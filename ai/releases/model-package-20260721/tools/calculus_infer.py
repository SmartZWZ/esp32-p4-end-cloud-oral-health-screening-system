"""Single-image inference for the released dental-calculus U-Net++ model."""

from __future__ import annotations

import argparse
from pathlib import Path

import numpy as np
import segmentation_models_pytorch as smp
import torch
from PIL import Image


ROOT = Path(__file__).resolve().parents[1]
DEFAULT_MODEL = ROOT / "weights" / "calculus_unetpp_resnet34_v3_inference.pt"


def main() -> None:
    parser = argparse.ArgumentParser(description="Overlay dental-calculus segmentation on one intraoral image.")
    parser.add_argument("--image", type=Path, required=True)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--threshold", type=float, default=0.4)
    parser.add_argument("--model", type=Path, default=DEFAULT_MODEL)
    parser.add_argument("--cpu", action="store_true")
    args = parser.parse_args()
    if not args.image.is_file() or not args.model.is_file():
        raise FileNotFoundError("Image or model file was not found.")
    if not 0 < args.threshold < 1:
        raise ValueError("--threshold must be between 0 and 1.")

    device = torch.device("cpu" if args.cpu or not torch.cuda.is_available() else "cuda")
    checkpoint = torch.load(args.model, map_location=device, weights_only=False)
    model = smp.UnetPlusPlus(encoder_name="resnet34", encoder_weights=None, in_channels=3, classes=1, activation=None).to(device).eval()
    model.load_state_dict(checkpoint["model_state"])

    source = Image.open(args.image).convert("RGB")
    original = np.asarray(source)
    resized = np.asarray(source.resize((672, 448), Image.Resampling.BILINEAR), dtype=np.float32) / 255.0
    normalized = (resized - np.asarray((0.485, 0.456, 0.406), dtype=np.float32)) / np.asarray((0.229, 0.224, 0.225), dtype=np.float32)
    tensor = torch.from_numpy(normalized.transpose(2, 0, 1)).unsqueeze(0).to(device)
    with torch.inference_mode(), torch.amp.autocast(device_type=device.type, enabled=device.type == "cuda"):
        probability = torch.sigmoid(model(tensor))[0, 0].float().cpu().numpy()
    probability_image = Image.fromarray((probability * 255).astype(np.uint8)).resize(source.size, Image.Resampling.BILINEAR)
    mask = np.asarray(probability_image, dtype=np.float32) / 255.0 >= args.threshold
    overlay = original.astype(np.float32).copy()
    overlay[mask] = overlay[mask] * 0.45 + np.asarray((30, 220, 255), dtype=np.float32) * 0.55
    output = args.output or args.image.with_name(f"{args.image.stem}_calculus_overlay.png")
    Image.fromarray(overlay.astype(np.uint8)).save(output)
    print(f"Saved overlay: {output}")
    print(f"Threshold: {args.threshold:.2f}; predicted calculus area: {int(mask.sum())} pixels")


if __name__ == "__main__":
    main()
