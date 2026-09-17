"""Render every exact-duplicate group with contradictory calculus labels for review."""

from __future__ import annotations

import argparse
import csv
from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageDraw


def imread_unicode(path: Path, flag: int) -> np.ndarray:
    image = cv2.imdecode(np.fromfile(path, dtype=np.uint8), flag)
    if image is None:
        raise RuntimeError(f"Cannot read {path}")
    return image


def preview(image_rgb: np.ndarray, mask: np.ndarray, label: str) -> Image.Image:
    canvas = image_rgb.astype(np.float32).copy()
    canvas[mask] = canvas[mask] * 0.45 + np.asarray((255, 220, 20), dtype=np.float32) * 0.55
    picture = Image.fromarray(canvas.astype(np.uint8))
    picture.thumbnail((360, 260))
    tile = Image.new("RGB", (360, 290), "white")
    tile.paste(picture, ((360 - picture.width) // 2, 0))
    ImageDraw.Draw(tile).text((5, 265), label, fill="black")
    return tile


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--raw", type=Path, required=True)
    parser.add_argument("--conflicts", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    output = args.output
    output.mkdir(parents=True, exist_ok=True)
    rows = list(csv.DictReader(args.conflicts.open(encoding="utf-8")))
    index_rows = []
    for number, row in enumerate(rows, start=1):
        stems = row["paired_stems"].split(";")
        statuses = [int(value) for value in row["class2_present_by_copy"].split(";")]
        source = imread_unicode(args.raw / "images" / f"{stems[0]}.jpg", cv2.IMREAD_COLOR)
        source_rgb = cv2.cvtColor(source, cv2.COLOR_BGR2RGB)
        tiles = []
        for stem, status in zip(stems, statuses):
            mask = imread_unicode(args.raw / "lables" / f"{stem}.png", cv2.IMREAD_GRAYSCALE) == 2
            tiles.append(preview(source_rgb, mask, f"{stem} | calculus={status}"))
        columns = min(3, len(tiles))
        sheet = Image.new("RGB", (columns * 360, 28 + ((len(tiles) + columns - 1) // columns) * 290), "white")
        ImageDraw.Draw(sheet).text((8, 6), f"Conflict {number:03d} | exact-image group {row['group_hash'][:12]}", fill="black")
        for index, tile in enumerate(tiles):
            sheet.paste(tile, ((index % columns) * 360, 28 + (index // columns) * 290))
        destination = output / f"conflict_{number:03d}_{row['group_hash'][:12]}.jpg"
        sheet.save(destination, quality=92)
        index_rows.append({"review_id": number, "group_hash": row["group_hash"], "source_stems": row["stems"], "copy_statuses": row["class2_present_by_copy"], "review_image": str(destination), "review_decision": ""})
    with (output / "review_index.csv").open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=index_rows[0].keys()); writer.writeheader(); writer.writerows(index_rows)
    print(f"Created {len(index_rows)} conflict review images in {output}")


if __name__ == "__main__":
    main()
