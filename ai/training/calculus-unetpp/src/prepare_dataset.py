"""Build a leakage-safe binary dental-calculus segmentation dataset."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import random
import shutil
from collections import defaultdict
from pathlib import Path

import numpy as np
from PIL import Image


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def split_items(items: list[dict], seed: int) -> dict[str, list[dict]]:
    ordered = list(items)
    random.Random(seed).shuffle(ordered)
    count = len(ordered)
    train_end = round(count * 0.70)
    val_end = train_end + round(count * 0.15)
    return {
        "train": ordered[:train_end],
        "val": ordered[train_end:val_end],
        "test": ordered[val_end:],
    }


def write_rows(path: Path, rows: list[dict], fields: list[str]) -> None:
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--raw", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--negative-ratio", type=float, default=2.0)
    parser.add_argument("--seed", type=int, default=42)
    args = parser.parse_args()

    image_dir = args.raw / "images"
    label_dir = args.raw / "lables"  # Original release uses this spelling.
    images = {path.stem: path for path in image_dir.glob("*.jpg")}
    labels = {path.stem: path for path in label_dir.glob("*.png")}
    if not images or not labels:
        raise RuntimeError("Expected images/*.jpg and lables/*.png under --raw.")

    output = args.output
    if output.exists():
        shutil.rmtree(output)
    output.mkdir(parents=True)

    grouped: dict[str, list[str]] = defaultdict(list)
    for stem, image_path in images.items():
        grouped[sha256(image_path)].append(stem)

    retained_positive: list[dict] = []
    retained_negative: list[dict] = []
    conflicts: list[dict] = []
    skipped_blank: list[dict] = []
    skipped_unlabeled: list[dict] = []

    for group_hash, stems in sorted(grouped.items()):
        stems = sorted(stems)
        paired = [stem for stem in stems if stem in labels]
        if not paired:
            skipped_unlabeled.append({"group_hash": group_hash, "stems": ";".join(stems)})
            continue

        class2_masks: list[np.ndarray] = []
        has_other_label = False
        image_size = None
        for stem in paired:
            with Image.open(images[stem]) as image:
                current_size = image.size
            with Image.open(labels[stem]) as label:
                label_array = np.asarray(label.convert("L"), dtype=np.uint8)
            if current_size != (label_array.shape[1], label_array.shape[0]):
                raise RuntimeError(f"Resolution mismatch for {stem}: {current_size} vs {label_array.shape[::-1]}")
            if image_size is None:
                image_size = current_size
            class2_masks.append(label_array == 2)
            has_other_label = has_other_label or bool(np.any(label_array != 0))

        statuses = [bool(mask.any()) for mask in class2_masks]
        if any(statuses) and not all(statuses):
            conflicts.append(
                {
                    "group_hash": group_hash,
                    "stems": ";".join(stems),
                    "paired_stems": ";".join(paired),
                    "class2_present_by_copy": ";".join(str(int(value)) for value in statuses),
                }
            )
            continue

        representative = min(paired)
        record = {
            "group_hash": group_hash,
            "source_stems": ";".join(stems),
            "representative": representative,
            "image_path": images[representative],
            "mask": np.logical_or.reduce(class2_masks),
        }
        if all(statuses):
            retained_positive.append(record)
        elif has_other_label:
            retained_negative.append(record)
        else:
            skipped_blank.append({"group_hash": group_hash, "stems": ";".join(stems)})

    random.Random(args.seed).shuffle(retained_negative)
    negative_limit = round(len(retained_positive) * args.negative_ratio)
    retained_negative = retained_negative[:negative_limit]

    positive_splits = split_items(retained_positive, args.seed)
    negative_splits = split_items(retained_negative, args.seed)
    manifest_rows: list[dict] = []
    for split in ("train", "val", "test"):
        image_out = output / "images" / split
        mask_out = output / "masks" / split
        image_out.mkdir(parents=True, exist_ok=True)
        mask_out.mkdir(parents=True, exist_ok=True)
        for target, records in ((1, positive_splits[split]), (0, negative_splits[split])):
            for record in records:
                sample_id = record["group_hash"][:16]
                destination_image = image_out / f"{sample_id}.jpg"
                destination_mask = mask_out / f"{sample_id}.png"
                shutil.copy2(record["image_path"], destination_image)
                Image.fromarray((record["mask"].astype(np.uint8) * 255), mode="L").save(destination_mask)
                manifest_rows.append(
                    {
                        "sample_id": sample_id,
                        "split": split,
                        "target": target,
                        "group_hash": record["group_hash"],
                        "source_stems": record["source_stems"],
                        "representative": record["representative"],
                        "image": str(destination_image),
                        "mask": str(destination_mask),
                    }
                )

    write_rows(
        output / "manifest.csv",
        manifest_rows,
        ["sample_id", "split", "target", "group_hash", "source_stems", "representative", "image", "mask"],
    )
    write_rows(output / "conflict_groups.csv", conflicts, ["group_hash", "stems", "paired_stems", "class2_present_by_copy"])
    write_rows(output / "blank_groups.csv", skipped_blank, ["group_hash", "stems"])
    write_rows(output / "unlabeled_groups.csv", skipped_unlabeled, ["group_hash", "stems"])

    split_summary = {
        split: {
            "positive": len(positive_splits[split]),
            "negative": len(negative_splits[split]),
            "total": len(positive_splits[split]) + len(negative_splits[split]),
        }
        for split in ("train", "val", "test")
    }
    audit = {
        "raw_images": len(images),
        "raw_masks": len(labels),
        "unique_exact_image_groups": len(grouped),
        "raw_image_without_mask": len(set(images) - set(labels)),
        "calculus_mask_value": 2,
        "positive_groups_retained": len(retained_positive),
        "negative_groups_retained": len(retained_negative),
        "conflicting_duplicate_groups_excluded": len(conflicts),
        "all_background_groups_excluded": len(skipped_blank),
        "unlabeled_groups_excluded": len(skipped_unlabeled),
        "negative_ratio": args.negative_ratio,
        "seed": args.seed,
        "splits": split_summary,
    }
    (output / "audit.json").write_text(json.dumps(audit, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(audit, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
