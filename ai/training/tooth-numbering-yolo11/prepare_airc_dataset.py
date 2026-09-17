"""Convert AIRC-LABDEN region annotations into tooth-level YOLO FDI labels.

The source labels have six fields:
    plaque_flag x_center y_center width height tooth_position_id

Each visible tooth is represented by four peri-bracket regions.  This script
groups those four regions by tooth-position ID and takes their geometric union,
creating one detection box per tooth.
"""

from __future__ import annotations

import argparse
import csv
import json
import random
import re
import shutil
from collections import Counter, defaultdict
from pathlib import Path


SPLIT_MAP = {"train": "train", "validation": "val", "test": "test"}
DERIVED_NAME = re.compile(
    r"_(?:brightness-(?:down|up)|rotate-(?:left|right)-15|flip_horizontal|blur|dark|light)(?:_|$)",
    re.IGNORECASE,
)


def is_derived(filename: str) -> bool:
    """Return whether a filename denotes a source-provided derived image."""
    return bool(DERIVED_NAME.search(Path(filename).stem))


def load_fdi_mapping(path: Path) -> dict[int, str]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    mapping = {
        int(item["id"]): str(item["name"])
        for item in payload["categories"]
        if str(item["name"]).isdigit()
    }
    expected_ids = list(range(1, 21))
    if sorted(mapping) != expected_ids:
        raise ValueError(f"Expected tooth IDs 1..20, found {sorted(mapping)}")
    return mapping


def parse_regions(
    label_path: Path,
) -> tuple[dict[int, list[tuple[float, float, float, float]]], int, int]:
    """Read source regions, returning boxes grouped by source tooth-position ID."""
    grouped: dict[int, list[tuple[float, float, float, float]]] = defaultdict(list)
    valid_lines = 0
    skipped_degenerate = 0
    for line_number, line in enumerate(label_path.read_text(encoding="utf-8-sig").splitlines(), 1):
        if not line.strip():
            continue
        fields = line.split()
        if len(fields) != 6:
            raise ValueError(f"{label_path}:{line_number}: expected 6 fields, found {len(fields)}")
        _, raw_x, raw_y, raw_w, raw_h, raw_id = fields
        x, y, width, height = map(float, (raw_x, raw_y, raw_w, raw_h))
        tooth_id = int(raw_id)
        if tooth_id not in range(1, 21):
            raise ValueError(f"{label_path}:{line_number}: unsupported tooth ID {tooth_id}")
        # Some source files contain a zero-height/width sub-region.  It cannot
        # train a detector, but the other regions for that tooth remain useful.
        if width <= 0 or height <= 0:
            skipped_degenerate += 1
            continue
        grouped[tooth_id].append((x, y, width, height))
        valid_lines += 1
    return grouped, valid_lines, skipped_degenerate


def union_box(boxes: list[tuple[float, float, float, float]]) -> tuple[float, float, float, float]:
    """Return a clipped normalized xywh box enclosing all source region boxes."""
    left = min(x - width / 2 for x, _, width, _ in boxes)
    top = min(y - height / 2 for _, y, _, height in boxes)
    right = max(x + width / 2 for x, _, width, _ in boxes)
    bottom = max(y + height / 2 for _, y, _, height in boxes)
    left, top = max(0.0, left), max(0.0, top)
    right, bottom = min(1.0, right), min(1.0, bottom)
    if right <= left or bottom <= top:
        raise ValueError(f"Invalid union box after clipping: {(left, top, right, bottom)}")
    return ((left + right) / 2, (top + bottom) / 2, right - left, bottom - top)


def write_label(destination: Path, grouped: dict[int, list[tuple[float, float, float, float]]]) -> int:
    rows: list[str] = []
    for source_id in sorted(grouped):
        x, y, width, height = union_box(grouped[source_id])
        yolo_class = source_id - 1
        rows.append(f"{yolo_class} {x:.6f} {y:.6f} {width:.6f} {height:.6f}")
    destination.write_text("\n".join(rows) + ("\n" if rows else ""), encoding="utf-8")
    return len(rows)


def write_previews(dataset_root: Path, destination: Path, class_names: list[str], count: int) -> list[str]:
    """Create deterministic annotated images for a quick human label audit."""
    try:
        import cv2
    except ImportError as exc:  # pragma: no cover - depends on local optional package
        raise RuntimeError("Preview generation requires opencv-python.") from exc

    candidates = sorted(dataset_root.glob("images/*/*"))
    chosen = random.Random(42).sample(candidates, min(count, len(candidates)))
    destination.mkdir(parents=True, exist_ok=True)
    written: list[str] = []
    for image_path in chosen:
        image = cv2.imread(str(image_path))
        if image is None:
            continue
        height, width = image.shape[:2]
        label_path = dataset_root / "labels" / image_path.parent.name / f"{image_path.stem}.txt"
        for raw in label_path.read_text(encoding="utf-8").splitlines():
            class_id, x, y, box_width, box_height = raw.split()
            class_index = int(class_id)
            x, y, box_width, box_height = map(float, (x, y, box_width, box_height))
            x1 = int((x - box_width / 2) * width)
            y1 = int((y - box_height / 2) * height)
            x2 = int((x + box_width / 2) * width)
            y2 = int((y + box_height / 2) * height)
            cv2.rectangle(image, (x1, y1), (x2, y2), (0, 220, 0), 3)
            cv2.putText(image, class_names[class_index], (x1, max(26, y1 - 8)), cv2.FONT_HERSHEY_SIMPLEX, 0.8, (0, 220, 0), 2)
        output = destination / f"{image_path.parent.name}__{image_path.name}"
        cv2.imwrite(str(output), image)
        written.append(str(output.relative_to(dataset_root)))
    return written


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", required=True, type=Path, help="AIRC-LABDEN Part 1 root")
    parser.add_argument("--output", required=True, type=Path, help="Destination YOLO dataset directory")
    parser.add_argument("--include-derived-train", action="store_true", help="Include source-provided derived images in train only")
    parser.add_argument("--include-empty", action="store_true", help="Keep source images whose label files are empty")
    parser.add_argument("--preview-count", type=int, default=24, help="Number of annotated audit previews to write")
    args = parser.parse_args()

    source = args.source.resolve()
    images_root = source / "data" / "images"
    labels_root = source / "data" / "labels"
    split_path = source / "data_splits.csv"
    mapping_path = source / "metadata" / "teeth_position_mapping.json"
    for required in (images_root, labels_root, split_path, mapping_path):
        if not required.exists():
            raise SystemExit(f"Missing required input: {required}")
    if args.output.exists():
        raise SystemExit(f"Refusing to overwrite existing output: {args.output}. Remove it first if intended.")

    id_to_fdi = load_fdi_mapping(mapping_path)
    class_names = [id_to_fdi[index] for index in range(1, 21)]
    output = args.output.resolve()
    for split in ("train", "val", "test"):
        (output / "images" / split).mkdir(parents=True, exist_ok=True)
        (output / "labels" / split).mkdir(parents=True, exist_ok=True)

    with split_path.open("r", encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    expected_columns = {"patient", "Image-Filename", "status"}
    if not rows or set(rows[0]) != expected_columns:
        raise SystemExit(f"Unexpected data_splits.csv columns: {list(rows[0]) if rows else 'empty file'}")

    audit: dict[str, object] = {
        "source": str(source),
        "output": str(output),
        "include_derived_train": args.include_derived_train,
        "include_empty": args.include_empty,
        "class_names": {str(index): name for index, name in enumerate(class_names)},
        "rows_in_data_splits": len(rows),
        "images_by_split": Counter(),
        "objects_by_split": Counter(),
        "patients_by_split": defaultdict(set),
        "source_region_count_by_fdi": Counter(),
        "skipped_degenerate_regions": 0,
        "tooth_box_count_by_fdi": Counter(),
        "group_size_counts": Counter(),
        "skipped_missing_image": [],
        "skipped_missing_label": [],
        "skipped_derived": 0,
        "skipped_empty_label": [],
    }

    for row in rows:
        patient = row["patient"]
        filename = row["Image-Filename"]
        source_status = row["status"]
        if source_status not in SPLIT_MAP:
            raise ValueError(f"Unexpected split status {source_status!r} for {filename}")
        split = SPLIT_MAP[source_status]
        derived = is_derived(filename)
        if derived and not (split == "train" and args.include_derived_train):
            audit["skipped_derived"] += 1
            continue

        image_path = images_root / patient / filename
        label_path = labels_root / patient / f"{Path(filename).stem}.txt"
        if not image_path.is_file():
            audit["skipped_missing_image"].append(str(image_path))
            continue
        if not label_path.is_file():
            audit["skipped_missing_label"].append(str(label_path))
            continue

        grouped, region_count, skipped_degenerate = parse_regions(label_path)
        audit["skipped_degenerate_regions"] += skipped_degenerate
        if not grouped and not args.include_empty:
            audit["skipped_empty_label"].append(str(image_path))
            continue
        target_image = output / "images" / split / filename
        target_label = output / "labels" / split / f"{Path(filename).stem}.txt"
        shutil.copy2(image_path, target_image)
        tooth_count = write_label(target_label, grouped)
        audit["images_by_split"][split] += 1
        audit["objects_by_split"][split] += tooth_count
        audit["patients_by_split"][split].add(patient)
        for source_id, boxes in grouped.items():
            fdi = id_to_fdi[source_id]
            audit["source_region_count_by_fdi"][fdi] += len(boxes)
            audit["tooth_box_count_by_fdi"][fdi] += 1
            audit["group_size_counts"][str(len(boxes))] += 1
        if region_count != sum(len(boxes) for boxes in grouped.values()):
            raise AssertionError("Region accounting mismatch")

    audit["images_by_split"] = dict(audit["images_by_split"])
    audit["objects_by_split"] = dict(audit["objects_by_split"])
    audit["patients_by_split"] = {key: sorted(value) for key, value in audit["patients_by_split"].items()}
    audit["source_region_count_by_fdi"] = dict(sorted(audit["source_region_count_by_fdi"].items()))
    audit["tooth_box_count_by_fdi"] = dict(sorted(audit["tooth_box_count_by_fdi"].items()))
    audit["group_size_counts"] = dict(sorted(audit["group_size_counts"].items()))
    audit["preview_files"] = write_previews(output, output / "previews", class_names, args.preview_count)
    (output / "audit_report.json").write_text(json.dumps(audit, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(audit, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
