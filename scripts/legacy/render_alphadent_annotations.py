"""Render AlphaDent ground-truth annotations on the original photographs.

By default, only Abrasion, Filling and Crown are drawn. This makes the
annotation definition of the three practically useful AlphaDent classes easy to
inspect without caries polygons obscuring the teeth.
"""

from __future__ import annotations

import argparse
import random
from pathlib import Path

import cv2
import numpy as np


DOWNLOADS = Path(r"D:\Downloads\EdgeDownload")
DEFAULT_IMAGES = DOWNLOADS / "AlphaDent" / "images" / "valid"
DEFAULT_LABELS = DOWNLOADS / "AlphaDent" / "labels" / "valid"
DEFAULT_OUTPUT = Path(__file__).resolve().parent / "artifacts" / "alphadent_annotations"

NAMES = {
    0: "Abrasion / 磨耗",
    1: "Filling / 充填体",
    2: "Crown / 牙冠",
    3: "Caries 1",
    4: "Caries 2",
    5: "Caries 3",
    6: "Caries 4",
    7: "Caries 5",
    8: "Caries 6",
}
# BGR: magenta, cyan and yellow remain visible on enamel and gingiva.
COLORS = {0: (190, 60, 210), 1: (225, 205, 0), 2: (0, 220, 255)}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Overlay AlphaDent polygon annotations on original images.")
    parser.add_argument("--images", type=Path, default=DEFAULT_IMAGES)
    parser.add_argument("--labels", type=Path, default=DEFAULT_LABELS)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--max-images", type=int, default=20)
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument("--include-caries", action="store_true", help="Also draw the six caries classes.")
    return parser.parse_args()


def imread(path: Path) -> np.ndarray:
    image = cv2.imdecode(np.fromfile(path, dtype=np.uint8), cv2.IMREAD_COLOR)
    if image is None:
        raise RuntimeError(f"Cannot read {path}")
    return image


def imwrite(path: Path, image: np.ndarray) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    ok, encoded = cv2.imencode(".jpg", image)
    if not ok:
        raise RuntimeError(f"Cannot encode {path}")
    encoded.tofile(str(path))


def load_polygons(label_path: Path, width: int, height: int) -> list[tuple[int, np.ndarray]]:
    polygons: list[tuple[int, np.ndarray]] = []
    for row in label_path.read_text(encoding="utf-8").splitlines():
        fields = row.split()
        if len(fields) < 7:
            continue
        class_id = int(float(fields[0]))
        points = np.asarray(fields[1:], dtype=np.float32).reshape(-1, 2)
        points[:, 0] *= width
        points[:, 1] *= height
        polygons.append((class_id, points.astype(np.int32).reshape(-1, 1, 2)))
    return polygons


def with_caption(image: np.ndarray, caption: str) -> np.ndarray:
    canvas = cv2.copyMakeBorder(image, 48, 0, 0, 0, cv2.BORDER_CONSTANT, value=(24, 24, 24))
    cv2.putText(canvas, caption, (16, 32), cv2.FONT_HERSHEY_SIMPLEX, 0.85, (255, 255, 255), 2, cv2.LINE_AA)
    return canvas


def annotate(image: np.ndarray, polygons: list[tuple[int, np.ndarray]], show_ids: set[int]) -> np.ndarray:
    annotated = image.copy()
    filled = image.copy()
    counts: dict[int, int] = {class_id: 0 for class_id in show_ids}
    for class_id, polygon in polygons:
        if class_id not in show_ids:
            continue
        draw_color = COLORS.get(class_id, (80, 80, 255))
        cv2.fillPoly(filled, [polygon], draw_color)
        cv2.polylines(annotated, [polygon], True, draw_color, 3, cv2.LINE_AA)
        x, y = polygon[0, 0]
        cv2.putText(annotated, NAMES[class_id], (int(x), max(22, int(y) - 5)), cv2.FONT_HERSHEY_SIMPLEX, 0.58, draw_color, 2, cv2.LINE_AA)
        counts[class_id] = counts.get(class_id, 0) + 1
    annotated = cv2.addWeighted(filled, 0.31, annotated, 0.69, 0)

    legend_y = 30
    for class_id in sorted(show_ids):
        draw_color = COLORS.get(class_id, (80, 80, 255))
        text = f"{NAMES[class_id]}: {counts.get(class_id, 0)}"
        cv2.rectangle(annotated, (14, legend_y - 17), (34, legend_y + 3), draw_color, -1)
        cv2.putText(annotated, text, (43, legend_y), cv2.FONT_HERSHEY_SIMPLEX, 0.62, (30, 30, 30), 3, cv2.LINE_AA)
        cv2.putText(annotated, text, (43, legend_y), cv2.FONT_HERSHEY_SIMPLEX, 0.62, (255, 255, 255), 1, cv2.LINE_AA)
        legend_y += 27
    return annotated


def make_sheet(panels: list[np.ndarray], output: Path) -> None:
    cell_width, columns = 900, 2
    resized: list[np.ndarray] = []
    for panel in panels:
        scale = cell_width / panel.shape[1]
        resized.append(cv2.resize(panel, (cell_width, round(panel.shape[0] * scale)), interpolation=cv2.INTER_AREA))
    rows: list[np.ndarray] = []
    for index in range(0, len(resized), columns):
        row = resized[index:index + columns]
        target_height = max(item.shape[0] for item in row)
        row = [cv2.copyMakeBorder(item, 0, target_height - item.shape[0], 0, 0, cv2.BORDER_CONSTANT, value=(18, 18, 18)) for item in row]
        if len(row) == 1:
            row.append(np.zeros_like(row[0]))
        rows.append(np.hstack(row))
    imwrite(output, np.vstack(rows))


def main() -> None:
    args = parse_args()
    show_ids = set(range(9)) if args.include_caries else {0, 1, 2}
    pairs: list[tuple[Path, Path]] = []
    for image_path in sorted(args.images.glob("*.jpg")):
        label_path = args.labels / f"{image_path.stem}.txt"
        if not label_path.exists():
            continue
        classes = {int(float(row.split()[0])) for row in label_path.read_text(encoding="utf-8").splitlines() if row.strip()}
        if classes & show_ids:
            pairs.append((image_path, label_path))
    if not pairs:
        raise RuntimeError("No images with the requested annotation classes were found.")
    random.Random(args.seed).shuffle(pairs)
    pairs = pairs[:args.max_images]

    panels: list[np.ndarray] = []
    for index, (image_path, label_path) in enumerate(pairs, start=1):
        original = imread(image_path)
        polygons = load_polygons(label_path, original.shape[1], original.shape[0])
        rendered = annotate(original, polygons, show_ids)
        panel = np.hstack((with_caption(original, f"Original | {image_path.name}"), with_caption(rendered, "Annotation overlay")))
        imwrite(args.output / f"{index:02d}_{image_path.stem}.jpg", panel)
        panels.append(panel)
        visible = sum(class_id in show_ids for class_id, _ in polygons)
        print(f"{index:02d}/{len(pairs)} {image_path.name}: {visible} displayed annotations")
    make_sheet(panels, args.output / "contact_sheet.jpg")
    print(f"Saved to {args.output}")


if __name__ == "__main__":
    main()
