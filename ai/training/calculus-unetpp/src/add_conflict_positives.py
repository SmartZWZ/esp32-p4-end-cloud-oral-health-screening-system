"""Create v3 data by adding valid calculus masks from formerly excluded duplicate groups.

The non-calculus copies in these groups are partial annotations for another disease,
not verified calculus negatives. Therefore only the positively annotated calculus masks
are merged and added to the training split; validation and test stay unchanged.
"""

from __future__ import annotations

import argparse
import csv
import json
import shutil
from pathlib import Path

import cv2
import numpy as np
from PIL import Image


def imread_unicode(path: Path, flag: int) -> np.ndarray:
    image = cv2.imdecode(np.fromfile(path, dtype=np.uint8), flag)
    if image is None:
        raise RuntimeError(f"Cannot read {path}")
    return image


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", type=Path, required=True)
    parser.add_argument("--raw", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if args.output.exists():
        shutil.rmtree(args.output)
    shutil.copytree(args.base / "images", args.output / "images")
    shutil.copytree(args.base / "masks", args.output / "masks")
    base_rows = list(csv.DictReader((args.base / "manifest.csv").open(encoding="utf-8")))
    conflicts = list(csv.DictReader((args.base / "conflict_groups.csv").open(encoding="utf-8")))
    added_rows = []
    for row in conflicts:
        paired_stems = row["paired_stems"].split(";")
        class2_masks = []
        for stem in paired_stems:
            mask = imread_unicode(args.raw / "lables" / f"{stem}.png", cv2.IMREAD_GRAYSCALE) == 2
            if mask.any():
                class2_masks.append(mask)
        if not class2_masks:
            continue
        merged = np.logical_or.reduce(class2_masks)
        sample_id = f"conflict_{row['group_hash'][:16]}"
        source_stem = paired_stems[0]
        source = args.raw / "images" / f"{source_stem}.jpg"
        image_out = args.output / "images" / "train" / f"{sample_id}.jpg"
        mask_out = args.output / "masks" / "train" / f"{sample_id}.png"
        shutil.copy2(source, image_out)
        Image.fromarray((merged.astype(np.uint8) * 255), mode="L").save(mask_out)
        added_rows.append({
            "sample_id": sample_id, "split": "train", "target": 1, "group_hash": row["group_hash"],
            "source_stems": row["stems"], "representative": source_stem, "image": str(image_out), "mask": str(mask_out),
        })
    all_rows = base_rows + added_rows
    fields = list(all_rows[0].keys())
    with (args.output / "manifest.csv").open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields); writer.writeheader(); writer.writerows(all_rows)
    original_audit = json.loads((args.base / "audit.json").read_text(encoding="utf-8"))
    audit = {
        **original_audit,
        "dataset_version": "v3_conflict_positive_merge",
        "conflict_positive_groups_added_to_train": len(added_rows),
        "train_total_after_merge": sum(1 for row in all_rows if row["split"] == "train"),
        "train_positive_after_merge": sum(1 for row in all_rows if row["split"] == "train" and int(row["target"]) == 1),
        "validation_and_test_unchanged": True,
    }
    (args.output / "audit.json").write_text(json.dumps(audit, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps(audit, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
