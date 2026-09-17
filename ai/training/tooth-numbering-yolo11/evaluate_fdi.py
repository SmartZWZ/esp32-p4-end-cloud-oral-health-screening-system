"""Evaluate a YOLO FDI detector with a tooth-number exact-match metric."""

from __future__ import annotations

import argparse
import json
from collections import Counter
from pathlib import Path

from ultralytics import YOLO


FDI_NAMES = ["11", "12", "13", "14", "15", "21", "22", "23", "24", "25", "31", "32", "33", "34", "35", "41", "42", "43", "44", "45"]


def iou(first: tuple[float, float, float, float], second: tuple[float, float, float, float]) -> float:
    left = max(first[0], second[0])
    top = max(first[1], second[1])
    right = min(first[2], second[2])
    bottom = min(first[3], second[3])
    intersection = max(0.0, right - left) * max(0.0, bottom - top)
    if not intersection:
        return 0.0
    first_area = (first[2] - first[0]) * (first[3] - first[1])
    second_area = (second[2] - second[0]) * (second[3] - second[1])
    return intersection / (first_area + second_area - intersection)


def read_targets(label_path: Path, width: int, height: int) -> list[tuple[int, tuple[float, float, float, float]]]:
    targets = []
    for line in label_path.read_text(encoding="utf-8").splitlines():
        class_id, x, y, box_width, box_height = line.split()
        class_id = int(class_id)
        x, y, box_width, box_height = map(float, (x, y, box_width, box_height))
        targets.append((class_id, ((x - box_width / 2) * width, (y - box_height / 2) * height, (x + box_width / 2) * width, (y + box_height / 2) * height)))
    return targets


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--weights", required=True, type=Path)
    parser.add_argument("--dataset", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--imgsz", type=int, default=1024)
    parser.add_argument("--conf", type=float, default=0.25)
    parser.add_argument("--iou", type=float, default=0.5, help="IoU threshold for a detection-to-target match")
    parser.add_argument("--device", default="0", help="Ultralytics inference device, e.g. 0 or cpu")
    args = parser.parse_args()

    image_root = args.dataset / "images" / "test"
    label_root = args.dataset / "labels" / "test"
    image_paths = sorted(path for path in image_root.iterdir() if path.suffix.lower() in {".jpg", ".jpeg", ".png"})
    model = YOLO(str(args.weights))
    overall = Counter()
    per_class = {name: Counter() for name in FDI_NAMES}
    confusion = Counter()

    for image_path in image_paths:
        result = model.predict(str(image_path), imgsz=args.imgsz, conf=args.conf, device=args.device, verbose=False)[0]
        height, width = result.orig_shape
        targets = read_targets(label_root / f"{image_path.stem}.txt", width, height)
        predictions = [
            (int(cls), float(conf), tuple(map(float, xyxy)))
            for cls, conf, xyxy in zip(result.boxes.cls.cpu().tolist(), result.boxes.conf.cpu().tolist(), result.boxes.xyxy.cpu().tolist())
        ]
        predictions.sort(key=lambda item: item[1], reverse=True)
        matched_targets: set[int] = set()
        for predicted_class, _, predicted_box in predictions:
            candidates = [
                (iou(predicted_box, target_box), index, target_class)
                for index, (target_class, target_box) in enumerate(targets)
                if index not in matched_targets
            ]
            best_iou, target_index, target_class = max(candidates, default=(0.0, -1, -1))
            if best_iou < args.iou:
                overall["false_positive"] += 1
                per_class[FDI_NAMES[predicted_class]]["false_positive"] += 1
                continue
            matched_targets.add(target_index)
            overall["localization_match"] += 1
            confusion[(FDI_NAMES[target_class], FDI_NAMES[predicted_class])] += 1
            if target_class == predicted_class:
                overall["exact_fdi_match"] += 1
                per_class[FDI_NAMES[target_class]]["true_positive"] += 1
            else:
                overall["wrong_fdi"] += 1
                per_class[FDI_NAMES[target_class]]["false_negative"] += 1
                per_class[FDI_NAMES[predicted_class]]["false_positive"] += 1
        for target_index, (target_class, _) in enumerate(targets):
            overall["ground_truth"] += 1
            if target_index not in matched_targets:
                overall["false_negative"] += 1
                per_class[FDI_NAMES[target_class]]["false_negative"] += 1

    exact_accuracy = overall["exact_fdi_match"] / overall["ground_truth"] if overall["ground_truth"] else 0.0
    label_accuracy_when_localized = overall["exact_fdi_match"] / overall["localization_match"] if overall["localization_match"] else 0.0
    report = {
        "weights": str(args.weights.resolve()),
        "dataset": str(args.dataset.resolve()),
        "test_images": len(image_paths),
        "iou_threshold": args.iou,
        "confidence_threshold": args.conf,
        "overall": dict(overall),
        "exact_fdi_accuracy": exact_accuracy,
        "fdi_accuracy_when_localized": label_accuracy_when_localized,
        "per_class": {name: dict(values) for name, values in per_class.items()},
        "confusion": {f"{target}->{prediction}": count for (target, prediction), count in confusion.items()},
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
