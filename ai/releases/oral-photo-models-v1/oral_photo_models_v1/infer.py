"""Unified inference entry point for the packaged oral-photo models."""

from __future__ import annotations

import argparse
from pathlib import Path

from ultralytics import YOLO


PACKAGE_ROOT = Path(__file__).resolve().parent
MODELS = {
    "caries": {
        "weight": PACKAGE_ROOT / "weights" / "caries_yolov8s_best.pt",
        "imgsz": 640,
        "conf": 0.25,
        "description": "龋齿检测（单类别目标检测）",
    },
    "restoration": {
        "weight": PACKAGE_ROOT / "weights" / "alphadent_4class_960.pt",
        "imgsz": 960,
        "conf": 0.25,
        "description": "磨耗、充填体、牙冠、龋齿实例分割",
    },
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run an oral-photo model from this package.")
    parser.add_argument("--model", choices=MODELS, required=True, help="Model to run.")
    parser.add_argument("--source", type=Path, required=True, help="One image, a directory, a video, or a webcam index.")
    parser.add_argument("--output", type=Path, default=PACKAGE_ROOT / "outputs", help="Parent directory for rendered predictions.")
    parser.add_argument("--conf", type=float, help="Override the default confidence threshold.")
    parser.add_argument("--imgsz", type=int, help="Override the default inference image size.")
    parser.add_argument("--device", default="0", help="GPU index such as 0, or cpu.")
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    spec = MODELS[args.model]
    weight = spec["weight"]
    if not weight.exists():
        raise FileNotFoundError(f"Weight file is missing: {weight}")
    if args.source != Path("0") and not args.source.exists():
        raise FileNotFoundError(f"Source does not exist: {args.source}")

    model = YOLO(str(weight))
    results = model.predict(
        source=str(args.source),
        imgsz=args.imgsz or spec["imgsz"],
        conf=args.conf if args.conf is not None else spec["conf"],
        iou=0.7,
        device=args.device,
        save=True,
        save_txt=True,
        save_conf=True,
        retina_masks=False,
        project=str(args.output.resolve()),
        name=args.model,
        exist_ok=False,
        verbose=False,
    )
    print(f"Model: {spec['description']}")
    print(f"Classes: {model.names}")
    print(f"Saved predictions to: {results[0].save_dir}")


if __name__ == "__main__":
    main()
