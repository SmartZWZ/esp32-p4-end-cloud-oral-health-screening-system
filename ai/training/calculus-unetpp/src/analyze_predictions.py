"""Create visual error analysis artifacts for a trained calculus segmentation model."""

from __future__ import annotations

import argparse
import csv
import json
from collections import Counter
from pathlib import Path

import cv2
import numpy as np
import segmentation_models_pytorch as smp
import torch
from PIL import Image, ImageDraw, ImageFont


def imread_unicode(path: Path, flag: int) -> np.ndarray:
    image = cv2.imdecode(np.fromfile(path, dtype=np.uint8), flag)
    if image is None:
        raise RuntimeError(f"Cannot read {path}")
    return image


def segmentation_metrics(prediction: np.ndarray, truth: np.ndarray) -> tuple[float, float, float]:
    prediction, truth = prediction.astype(bool), truth.astype(bool)
    tp = int((prediction & truth).sum())
    fp = int((prediction & ~truth).sum())
    fn = int((~prediction & truth).sum())
    dice = (2 * tp + 1.0) / (2 * tp + fp + fn + 1.0)
    precision = (tp + 1.0) / (tp + fp + 1.0)
    recall = (tp + 1.0) / (tp + fn + 1.0)
    return dice, precision, recall


def categorize(gt_pixels: int, pred_pixels: int, dice: float, precision: float, recall: float, total_pixels: int) -> str:
    if gt_pixels == 0:
        return "negative_clean" if pred_pixels < total_pixels * 0.001 else "false_positive"
    if pred_pixels == 0 or recall < 0.10:
        return "false_negative"
    if recall < 0.35:
        return "severe_undersegmentation"
    if precision < 0.25:
        return "oversegmentation"
    if dice < 0.40:
        return "partial_miss"
    return "acceptable"


def overlay(image_rgb: np.ndarray, truth: np.ndarray, prediction: np.ndarray, title: str) -> Image.Image:
    canvas = image_rgb.copy().astype(np.float32)
    gt_only = truth & ~prediction
    pred_only = prediction & ~truth
    overlap = truth & prediction
    # Red: missed ground truth, cyan: false prediction, yellow: overlap.
    for mask, color in ((gt_only, (255, 40, 40)), (pred_only, (30, 220, 255)), (overlap, (255, 220, 20))):
        canvas[mask] = canvas[mask] * 0.45 + np.asarray(color, dtype=np.float32) * 0.55
    picture = Image.fromarray(canvas.astype(np.uint8))
    draw = ImageDraw.Draw(picture)
    draw.rectangle((0, 0, picture.width, 26), fill=(0, 0, 0))
    draw.text((6, 5), title, fill=(255, 255, 255))
    return picture


def contact_sheet(items: list[tuple[Path, str]], destination: Path, title: str, columns: int = 3) -> None:
    if not items:
        return
    thumbs: list[Image.Image] = []
    for path, caption in items:
        image = Image.open(path).convert("RGB")
        image.thumbnail((360, 260))
        tile = Image.new("RGB", (360, 292), "white")
        tile.paste(image, ((360 - image.width) // 2, 0))
        draw = ImageDraw.Draw(tile)
        draw.text((5, 264), caption, fill="black")
        thumbs.append(tile)
    rows = (len(thumbs) + columns - 1) // columns
    sheet = Image.new("RGB", (columns * 360, 32 + rows * 292), "white")
    draw = ImageDraw.Draw(sheet)
    draw.text((8, 8), title, fill="black")
    for index, tile in enumerate(thumbs):
        sheet.paste(tile, ((index % columns) * 360, 32 + (index // columns) * 292))
    sheet.save(destination, quality=92)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--data", type=Path, required=True)
    parser.add_argument("--checkpoint", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--threshold", type=float, default=0.5)
    args = parser.parse_args()
    output = args.output
    overlays = output / "overlays"
    overlays.mkdir(parents=True, exist_ok=True)
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    checkpoint = torch.load(args.checkpoint, map_location=device, weights_only=False)
    model = smp.UnetPlusPlus(encoder_name="resnet34", encoder_weights=None, in_channels=3, classes=1, activation=None).to(device).eval()
    model.load_state_dict(checkpoint["model_state"])

    image_dir, mask_dir = args.data / "images" / "test", args.data / "masks" / "test"
    rows: list[dict[str, str | int | float]] = []
    for image_path in sorted(image_dir.glob("*.jpg")):
        bgr = imread_unicode(image_path, cv2.IMREAD_COLOR)
        rgb = cv2.cvtColor(bgr, cv2.COLOR_BGR2RGB)
        truth = imread_unicode(mask_dir / f"{image_path.stem}.png", cv2.IMREAD_GRAYSCALE) > 127
        resized = cv2.resize(rgb, (672, 448), interpolation=cv2.INTER_LINEAR).astype(np.float32) / 255.0
        normalized = (resized - np.asarray((0.485, 0.456, 0.406), dtype=np.float32)) / np.asarray((0.229, 0.224, 0.225), dtype=np.float32)
        tensor = torch.from_numpy(normalized.transpose(2, 0, 1)).unsqueeze(0).to(device)
        with torch.inference_mode(), torch.amp.autocast(device_type=device.type, enabled=device.type == "cuda"):
            probability = torch.sigmoid(model(tensor))[0, 0].float().cpu().numpy()
        probability = cv2.resize(probability, (rgb.shape[1], rgb.shape[0]), interpolation=cv2.INTER_LINEAR)
        prediction = probability >= args.threshold
        dice, precision, recall = segmentation_metrics(prediction, truth)
        category = categorize(int(truth.sum()), int(prediction.sum()), dice, precision, recall, truth.size)
        filename = f"{category}__{image_path.stem}.jpg"
        title = f"{category}  Dice={dice:.3f}  P={precision:.3f}  R={recall:.3f}"
        overlay(rgb, truth, prediction, title).save(overlays / filename, quality=92)
        rows.append({
            "sample_id": image_path.stem, "category": category, "dice": round(dice, 6), "precision": round(precision, 6),
            "recall": round(recall, 6), "gt_pixels": int(truth.sum()), "pred_pixels": int(prediction.sum()), "overlay": str(overlays / filename),
        })

    with (output / "per_image_metrics.csv").open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=rows[0].keys()); writer.writeheader(); writer.writerows(rows)
    grouped = Counter(str(row["category"]) for row in rows)
    summary = {"threshold": args.threshold, "image_count": len(rows), "categories": dict(grouped)}
    (output / "summary.json").write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")

    error_order = {"false_negative", "severe_undersegmentation", "partial_miss", "oversegmentation", "false_positive"}
    difficult = [row for row in rows if row["category"] in error_order]
    difficult.sort(key=lambda row: (float(row["dice"]), -int(row["pred_pixels"])))
    contact_sheet([(Path(str(row["overlay"])), f"{row['category']} | D={float(row['dice']):.3f}") for row in difficult[:30]], output / "worst_30.jpg", "Worst test-set cases: red=missed GT, cyan=false prediction, yellow=overlap")
    false_positive = [row for row in rows if row["category"] == "false_positive"]
    false_positive.sort(key=lambda row: -int(row["pred_pixels"]))
    contact_sheet([(Path(str(row["overlay"])), f"false positive | area={int(row['pred_pixels'])}") for row in false_positive[:30]], output / "false_positives.jpg", "Largest false-positive cases")
    print(json.dumps(summary, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
