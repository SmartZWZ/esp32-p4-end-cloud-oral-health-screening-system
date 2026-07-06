"""
Quantize the edge-side YOLO11 model to ESP-DL .espdl with Espressif ESP-PPQ.

Default target:
    - model: ai/models/edge_risk_best.pt
    - input: 320x320
    - chip:  esp32p4

Install:
    pip uninstall -y ppq
    pip install git+https://github.com/espressif/esp-ppq.git
    pip install ultralytics pillow numpy torch

Example:
    python ai/quantization/quantize_espdl_yolo11.py ^
      --image-dir D:/Tooth-YoloV11/dataset_yolo_lesion_det/images/val
"""

from __future__ import annotations

import argparse
import os
import sys
from pathlib import Path
from typing import Iterable

import numpy as np
import torch
import torch.nn as nn
from PIL import Image
from torch.utils.data import DataLoader, Dataset


os.environ.setdefault("ULTRALYTICS_AUTOUPDATE", "0")

REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_WEIGHTS = REPO_ROOT / "ai" / "models" / "edge_risk_best.pt"
DEFAULT_OUTPUT = REPO_ROOT / "outputs" / "edge_risk_yolo11n_320_int8.espdl"
DEFAULT_DATA_YAML = Path("D:/Tooth-YoloV11/dataset_yolo_lesion_det/data.yaml")
DEFAULT_IMAGE_DIRS = [
    REPO_ROOT / "ai" / "quantization" / "calib_images",
    Path("D:/Tooth-YoloV11/dataset_yolo_lesion_det/images/val"),
    Path("D:/Tooth-YoloV11/dataset_yolo_lesion_det/images/train"),
]
IMAGE_SUFFIXES = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}


def patch_pathlib_for_checkpoint() -> None:
    """Allow checkpoints saved on another OS to load under torch/ultralytics."""
    import pathlib

    if os.name == "nt":
        pathlib.PosixPath = pathlib.WindowsPath
    else:
        pathlib.WindowsPath = pathlib.PosixPath


class CalibrationDataset(Dataset):
    def __init__(self, image_paths: list[Path], img_size: int, limit: int) -> None:
        self.image_paths = image_paths[:limit]
        self.img_size = img_size

    def __len__(self) -> int:
        return len(self.image_paths)

    def __getitem__(self, index: int) -> torch.Tensor:
        image = Image.open(self.image_paths[index]).convert("RGB")
        image = letterbox(image, self.img_size)
        array = np.asarray(image, dtype=np.float32) / 255.0
        array = array.transpose(2, 0, 1)
        return torch.from_numpy(array)


def letterbox(image: Image.Image, size: int) -> Image.Image:
    width, height = image.size
    scale = min(size / width, size / height)
    resized = (max(1, round(width * scale)), max(1, round(height * scale)))
    image = image.resize(resized, Image.BILINEAR)

    canvas = Image.new("RGB", (size, size), (114, 114, 114))
    left = (size - resized[0]) // 2
    top = (size - resized[1]) // 2
    canvas.paste(image, (left, top))
    return canvas


def collect_images(image_dir: Path | None, data_yaml: Path | None, limit: int) -> list[Path]:
    candidates: list[Path] = []

    if image_dir is not None:
        candidates.extend(find_images(image_dir))

    if not candidates and data_yaml is not None and data_yaml.exists():
        for split in ("val", "test", "train"):
            split_dir = read_yolo_data_yaml_path(data_yaml, split)
            if split_dir is None:
                continue
            candidates.extend(find_images(split_dir))
            if candidates:
                break

    if not candidates:
        for default_dir in DEFAULT_IMAGE_DIRS:
            candidates.extend(find_images(default_dir))
            if candidates:
                break

    unique: list[Path] = []
    seen: set[Path] = set()
    for path in candidates:
        resolved = path.resolve()
        if resolved in seen:
            continue
        seen.add(resolved)
        unique.append(path)
        if len(unique) >= limit:
            break

    return unique


def find_images(path: Path) -> list[Path]:
    if not path.exists():
        return []
    if path.is_file() and path.suffix.lower() in IMAGE_SUFFIXES:
        return [path]
    return sorted(p for p in path.rglob("*") if p.suffix.lower() in IMAGE_SUFFIXES)


def read_yolo_data_yaml_path(data_yaml: Path, key: str) -> Path | None:
    raw = data_yaml.read_text(encoding="utf-8", errors="ignore")
    dataset_root = data_yaml.parent

    try:
        import yaml  # type: ignore

        parsed = yaml.safe_load(raw)
        if isinstance(parsed, dict):
            root_value = parsed.get("path")
            if isinstance(root_value, str):
                dataset_root = resolve_path(data_yaml.parent, Path(root_value))
            split_value = parsed.get(key)
            if isinstance(split_value, str):
                return yolo_split_to_image_dir(dataset_root, split_value)
    except Exception:
        pass

    for line in raw.splitlines():
        stripped = line.strip()
        if not stripped.startswith(f"{key}:"):
            continue
        value = stripped.split(":", 1)[1].strip().strip("'\"")
        if value:
            return yolo_split_to_image_dir(dataset_root, value)
    return None


def resolve_path(base: Path, value: Path) -> Path:
    return value if value.is_absolute() else (base / value)


def yolo_split_to_image_dir(dataset_root: Path, value: str) -> Path:
    path = resolve_path(dataset_root, Path(value))
    if path.is_file() and path.suffix.lower() == ".txt":
        return path.parent
    return path


class ExportableDFL(nn.Module):
    """DFL replacement that avoids fixed-weight Conv2d in ESP-DL export."""

    def __init__(self, c1: int = 16) -> None:
        super().__init__()
        self.c1 = c1
        self.register_buffer("bins", torch.arange(c1, dtype=torch.float32).view(1, 1, c1, 1))

    def forward(self, x: torch.Tensor) -> torch.Tensor:
        batch, _, anchors = x.shape
        x = x.view(batch, 4, self.c1, anchors)
        x_max = torch.amax(x, dim=2, keepdim=True)
        exp_x = torch.exp(x - x_max)
        probs = exp_x / exp_x.sum(dim=2, keepdim=True)
        return (probs * self.bins).sum(dim=2)


def load_yolo_model(weights: Path, img_size: int, patch_detect: bool) -> nn.Module:
    patch_pathlib_for_checkpoint()
    from ultralytics import YOLO

    yolo = YOLO(str(weights))
    model = yolo.model
    model.eval()
    model.cpu()
    if patch_detect:
        patch_detect_layer(model, img_size)
    return model


def patch_detect_layer(model: nn.Module, img_size: int) -> None:
    import types

    detect_layer = next((m for m in model.modules() if type(m).__name__ == "Detect"), None)
    if detect_layer is None:
        raise RuntimeError("No YOLO Detect layer found.")

    if hasattr(detect_layer, "dfl") and hasattr(detect_layer.dfl, "c1"):
        c1 = int(detect_layer.dfl.c1)
        detect_layer.dfl = ExportableDFL(c1=c1)
        print(f"Patched DFL -> ExportableDFL(c1={c1})")

    if not hasattr(detect_layer, "_get_decode_boxes"):
        print("Detect layer has no _get_decode_boxes; keeping original inference.")
        return

    strides = [float(s) for s in detect_layer.stride.tolist()]
    anchor_counts = [int((img_size / stride) ** 2) for stride in strides]
    stride_values: list[float] = []
    for stride, count in zip(strides, anchor_counts):
        stride_values.extend([stride] * count)
    strides_tensor = torch.tensor(stride_values, dtype=torch.float32).view(1, 1, -1)

    def patched_inference(self, x):  # noqa: ANN001
        decoded = self._get_decode_boxes(x)
        stride_scale = strides_tensor.to(decoded.device)
        decoded_stride_units = decoded / stride_scale
        raw_scores = x["scores"]
        return torch.cat([decoded_stride_units, raw_scores], dim=1)

    original_forward = detect_layer.forward

    def patched_forward(self, x, *args, **kwargs):  # noqa: ANN001
        result = original_forward(x, *args, **kwargs)
        if self.training:
            return result
        if isinstance(result, tuple):
            return result[0]
        return result

    detect_layer._inference = types.MethodType(patched_inference, detect_layer)
    detect_layer.forward = types.MethodType(patched_forward, detect_layer)
    print(f"Patched Detect: strides={strides}, anchors={anchor_counts}, total={sum(anchor_counts)}")


def collate_tensors(batch: Iterable[torch.Tensor]) -> torch.Tensor:
    return torch.stack(list(batch), dim=0)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Quantize YOLO11 edge model to ESP-DL.")
    parser.add_argument("--weights", type=Path, default=DEFAULT_WEIGHTS)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--img-size", type=int, default=320)
    parser.add_argument("--calib-images", type=int, default=200)
    parser.add_argument("--calib-steps", type=int, default=32)
    parser.add_argument("--image-dir", type=Path, default=None)
    parser.add_argument("--data-yaml", type=Path, default=DEFAULT_DATA_YAML)
    parser.add_argument("--target", choices=("esp32p4", "esp32s3", "c"), default="esp32p4")
    parser.add_argument("--num-bits", type=int, choices=(8, 16), default=8)
    parser.add_argument("--device", default="cpu")
    parser.add_argument("--opset-version", type=int, default=18)
    parser.add_argument("--no-error-report", action="store_true")
    parser.add_argument("--no-detect-patch", action="store_true")
    parser.add_argument("--dry-run", action="store_true", help="Load model and dataset, then stop before PPQ.")
    return parser.parse_args()


def main() -> None:
    args = parse_args()

    if not args.weights.exists():
        raise SystemExit(f"Weights not found: {args.weights}")

    image_paths = collect_images(args.image_dir, args.data_yaml, args.calib_images)
    if not image_paths:
        raise SystemExit(
            "No calibration images found. Pass --image-dir or --data-yaml. "
            "Use representative oral images, preferably from the validation set."
        )

    print(f"Weights: {args.weights}")
    print(f"Output:  {args.output}")
    print(f"Target:  {args.target}, int{args.num_bits}, input={args.img_size}")
    print(f"Calib:   {len(image_paths)} images, first={image_paths[0]}")

    model = load_yolo_model(args.weights, args.img_size, patch_detect=not args.no_detect_patch)
    dataset = CalibrationDataset(image_paths, img_size=args.img_size, limit=args.calib_images)
    loader = DataLoader(dataset, batch_size=1, shuffle=False, num_workers=0, collate_fn=collate_tensors)

    with torch.no_grad():
        sample = next(iter(loader))
        output = model(sample)
        if isinstance(output, (list, tuple)):
            output_shapes = [tuple(item.shape) for item in output if hasattr(item, "shape")]
        else:
            output_shapes = [tuple(output.shape)] if hasattr(output, "shape") else [type(output).__name__]
        print(f"Torch output shape: {output_shapes}")

    if args.dry_run:
        print("Dry run finished; quantization was not executed.")
        return

    onnx_path = args.output.with_suffix(".onnx")
    export_onnx_model(
        model=model,
        onnx_path=onnx_path,
        input_shape=[1, 3, args.img_size, args.img_size],
        device=args.device,
        opset_version=args.opset_version,
    )
    canonicalize_negative_onnx_axes(onnx_path)

    try:
        from esp_ppq import QuantizationSettingFactory
        from esp_ppq.api import espdl_quantize_onnx
    except ImportError as exc:
        raise SystemExit(
            "esp_ppq is not installed. Run:\n"
            "  pip uninstall -y ppq\n"
            "  pip install git+https://github.com/espressif/esp-ppq.git"
        ) from exc

    args.output.parent.mkdir(parents=True, exist_ok=True)
    setting = QuantizationSettingFactory.espdl_setting()
    setting.equalization = False

    espdl_quantize_onnx(
        onnx_import_file=str(onnx_path),
        espdl_export_file=str(args.output),
        calib_dataloader=loader,
        calib_steps=min(args.calib_steps, len(loader)),
        input_shape=[1, 3, args.img_size, args.img_size],
        target=args.target,
        num_of_bits=args.num_bits,
        collate_fn=lambda item: item,
        setting=setting,
        device=args.device,
        error_report=not args.no_error_report,
        skip_export=False,
        export_test_values=False,
        verbose=1,
    )

    if not args.output.exists():
        raise SystemExit(f"ESP-DL file was not generated: {args.output}")

    print(f"Done: {args.output} ({args.output.stat().st_size / 1024:.1f} KB)")
    for suffix in (".info", ".json"):
        companion = args.output.with_suffix(suffix)
        if companion.exists():
            print(f"Companion: {companion}")


def export_onnx_model(
    model: nn.Module,
    onnx_path: Path,
    input_shape: list[int],
    device: str,
    opset_version: int,
) -> None:
    onnx_path.parent.mkdir(parents=True, exist_ok=True)
    model = model.eval().to(device)
    dummy = torch.zeros(size=input_shape, device=device, dtype=torch.float32)
    export_kwargs = {
        "model": model,
        "args": (dummy,),
        "f": str(onnx_path),
        "opset_version": opset_version,
        "do_constant_folding": True,
    }
    if torch.__version__ >= "2.9.0":
        export_kwargs["dynamo"] = False
    torch.onnx.export(**export_kwargs)
    print(f"ONNX: {onnx_path}")


def canonicalize_negative_onnx_axes(onnx_path: Path) -> None:
    import onnx

    model = onnx.load(str(onnx_path))
    try:
        inferred = onnx.shape_inference.infer_shapes(model)
    except Exception:
        inferred = model

    ranks = tensor_ranks(inferred)
    changed = 0
    for node in model.graph.node:
        rank = first_known_rank(node.input, node.output, ranks)
        if rank is None:
            continue
        for attr in node.attribute:
            if attr.name == "axis" and attr.i < 0:
                attr.i += rank
                changed += 1
            elif attr.name == "axes" and attr.ints:
                axes = [axis + rank if axis < 0 else axis for axis in attr.ints]
                if axes != list(attr.ints):
                    attr.ClearField("ints")
                    attr.ints.extend(axes)
                    changed += 1

    if changed:
        onnx.save(model, str(onnx_path))
        print(f"ONNX axis fix: canonicalized {changed} negative axis attribute(s).")


def tensor_ranks(model) -> dict[str, int]:  # noqa: ANN001
    ranks: dict[str, int] = {}
    values = list(model.graph.input) + list(model.graph.output) + list(model.graph.value_info)
    for value in values:
        shape = value.type.tensor_type.shape
        if shape.dim:
            ranks[value.name] = len(shape.dim)
    return ranks


def first_known_rank(inputs, outputs, ranks: dict[str, int]) -> int | None:  # noqa: ANN001
    for name in list(inputs) + list(outputs):
        if name in ranks:
            return ranks[name]
    return None


if __name__ == "__main__":
    main()
