import argparse
import json
import os
import shutil
from collections import Counter, defaultdict
from pathlib import Path


LESION_CLASSES = {"Caries", "Cavity", "Crack"}
SPLIT_MAP = {"train": "train", "valid": "val", "test": "test"}


def link_or_copy(src: Path, dst: Path) -> str:
    dst.parent.mkdir(parents=True, exist_ok=True)
    if dst.exists():
        return "exists"
    try:
        os.link(src, dst)
        return "linked"
    except OSError:
        shutil.copy2(src, dst)
        return "copied"


def polygon_to_yolo_box(points, width: int, height: int):
    xs = [min(max(float(p[0]), 0.0), float(width)) for p in points]
    ys = [min(max(float(p[1]), 0.0), float(height)) for p in points]
    x1, x2 = min(xs), max(xs)
    y1, y2 = min(ys), max(ys)
    bw = x2 - x1
    bh = y2 - y1
    if bw <= 1 or bh <= 1:
        return None
    return [
        ((x1 + x2) / 2) / width,
        ((y1 + y2) / 2) / height,
        bw / width,
        bh / height,
    ]


def convert_annotation(ann_path: Path, label_path: Path):
    data = json.loads(ann_path.read_text(encoding="utf-8"))
    width = int(data["size"]["width"])
    height = int(data["size"]["height"])
    lines = []
    stats = Counter()
    skipped = Counter()

    for obj in data.get("objects", []):
        class_title = obj.get("classTitle")
        if class_title not in LESION_CLASSES:
            skipped[class_title or "unknown"] += 1
            continue
        points = ((obj.get("points") or {}).get("exterior") or [])
        if len(points) < 3:
            skipped["short_polygon"] += 1
            continue
        box = polygon_to_yolo_box(points, width, height)
        if box is None:
            skipped["tiny_box"] += 1
            continue
        lines.append("0 " + " ".join(f"{v:.6f}" for v in box))
        stats[class_title] += 1

    label_path.parent.mkdir(parents=True, exist_ok=True)
    label_path.write_text("\n".join(lines) + ("\n" if lines else ""), encoding="utf-8")
    return stats, skipped, len(lines)


def convert_dataset(src: Path, out: Path):
    out.mkdir(parents=True, exist_ok=True)
    global_stats = Counter()
    skipped_stats = Counter()
    split_stats = defaultdict(Counter)
    image_actions = Counter()
    empty_labels = Counter()

    for src_split, dst_split in SPLIT_MAP.items():
        img_dir = src / src_split / "img"
        ann_dir = src / src_split / "ann"
        out_img_dir = out / "images" / dst_split
        out_label_dir = out / "labels" / dst_split

        for ann_path in sorted(ann_dir.glob("*.json")):
            image_name = ann_path.name[:-5]
            src_img = img_dir / image_name
            if not src_img.exists():
                skipped_stats["missing_image"] += 1
                continue
            image_actions[link_or_copy(src_img, out_img_dir / image_name)] += 1
            label_name = Path(image_name).with_suffix(".txt").name
            stats, skipped, line_count = convert_annotation(ann_path, out_label_dir / label_name)
            global_stats.update(stats)
            split_stats[dst_split].update(stats)
            skipped_stats.update(skipped)
            if line_count == 0:
                empty_labels[dst_split] += 1

    (out / "data.yaml").write_text(
        "\n".join(
            [
                f"path: {out.as_posix()}",
                "train: images/train",
                "val: images/val",
                "test: images/test",
                "nc: 1",
                "names:",
                "  0: lesion",
            ]
        )
        + "\n",
        encoding="utf-8",
    )

    report = {
        "class_map": {"Caries/Cavity/Crack": "lesion"},
        "objects_total": dict(global_stats),
        "objects_by_split": {k: dict(v) for k, v in split_stats.items()},
        "empty_label_files": dict(empty_labels),
        "skipped": dict(skipped_stats),
        "image_actions": dict(image_actions),
    }
    (out / "conversion_report.json").write_text(
        json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8"
    )
    return report


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--src", required=True, type=Path, help="DatasetNinja root directory")
    parser.add_argument("--out", required=True, type=Path, help="Output YOLO dataset directory")
    args = parser.parse_args()
    print(json.dumps(convert_dataset(args.src, args.out), indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
