"""Visualize AlphaDent YOLO-seg predictions on intraoral photographs.

Examples
--------
Use the downloaded AlphaDent validation photos and show both labels and predictions:

python visualize_alphadent.py --ground-truth --max-images 12

Run a custom folder of photos (prediction only):

python visualize_alphadent.py --source D:\\my_photos --output D:\\alphadent_vis
"""

from __future__ import annotations

import argparse
import random
from pathlib import Path

import cv2
import numpy as np
from ultralytics import YOLO


DEFAULT_DOWNLOADS = Path(r"D:\Downloads\EdgeDownload")
DEFAULT_SOURCE = DEFAULT_DOWNLOADS / "AlphaDent" / "images" / "valid"
DEFAULT_WEIGHTS = DEFAULT_DOWNLOADS / "yolov8x_AlphaDent_4_classes_960px.pt"
DEFAULT_OUTPUT = Path(__file__).resolve().parent / "artifacts" / "alphadent_visualizations"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Render AlphaDent instance-segmentation predictions.")
    parser.add_argument("--source", type=Path, default=DEFAULT_SOURCE, help="One image or a folder of JPG/PNG images.")
    parser.add_argument("--weights", type=Path, default=DEFAULT_WEIGHTS, help="AlphaDent .pt model weight.")
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT, help="Directory for rendered images.")
    parser.add_argument("--conf", type=float, default=0.25, help="Minimum prediction confidence.")
    parser.add_argument("--imgsz", type=int, default=960, help="Inference image size. Use 640 for the 9-class 640px weight.")
    parser.add_argument("--max-images", type=int, default=12, help="Number of source images to render.")
    parser.add_argument("--seed", type=int, default=42, help="Seed used when selecting images.")
    parser.add_argument("--ground-truth", action="store_true", help="Render a label-vs-prediction panel when YOLO labels are available.")
    return parser.parse_args()


def list_images(source: Path) -> list[Path]:
    if source.is_file():
        return [source]
    images = [path for extension in ("*.jpg", "*.jpeg", "*.png") for path in source.rglob(extension)]
    if not images:
        raise FileNotFoundError(f"No JPG/JPEG/PNG images found in: {source}")
    return sorted(images)


def imread_unicode(path: Path) -> np.ndarray:
    image = cv2.imdecode(np.fromfile(path, dtype=np.uint8), cv2.IMREAD_COLOR)
    if image is None:
        raise RuntimeError(f"Cannot read image: {path}")
    return image


def imwrite_unicode(path: Path, image: np.ndarray) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    success, encoded = cv2.imencode(path.suffix or ".jpg", image)
    if not success:
        raise RuntimeError(f"Cannot encode image: {path}")
    encoded.tofile(str(path))


def infer_label_path(image_path: Path) -> Path | None:
    """Map .../images/<split>/x.jpg to .../labels/<split>/x.txt if possible."""
    parts = list(image_path.parts)
    try:
        position = parts.index("images")
    except ValueError:
        return None
    parts[position] = "labels"
    return Path(*parts).with_suffix(".txt")


def color(class_id: int) -> tuple[int, int, int]:
    palette = [(46, 204, 113), (52, 152, 219), (241, 196, 15), (231, 76, 60), (155, 89, 182), (26, 188, 156)]
    return palette[class_id % len(palette)]


def draw_ground_truth(image_path: Path, names: dict[int, str], collapse_to_four: bool) -> np.ndarray:
    canvas = imread_unicode(image_path)
    label_path = infer_label_path(image_path)
    if label_path is None or not label_path.exists():
        cv2.putText(canvas, "Ground truth unavailable", (18, 38), cv2.FONT_HERSHEY_SIMPLEX, 1.0, (0, 0, 255), 2, cv2.LINE_AA)
        return canvas

    height, width = canvas.shape[:2]
    overlay = canvas.copy()
    for row in label_path.read_text(encoding="utf-8").splitlines():
        values = row.split()
        if len(values) < 7:
            continue
        class_id = int(float(values[0]))
        if collapse_to_four:
            class_id = min(class_id, 3)
        points = np.asarray(values[1:], dtype=np.float32).reshape(-1, 2)
        points[:, 0] *= width
        points[:, 1] *= height
        polygon = points.astype(np.int32).reshape(-1, 1, 2)
        shade = color(class_id)
        cv2.fillPoly(overlay, [polygon], shade)
        cv2.polylines(canvas, [polygon], True, shade, 2, cv2.LINE_AA)
        x, y = polygon[0, 0]
        cv2.putText(canvas, names.get(class_id, str(class_id)), (int(x), max(22, int(y) - 4)), cv2.FONT_HERSHEY_SIMPLEX, 0.55, shade, 2, cv2.LINE_AA)
    canvas = cv2.addWeighted(overlay, 0.28, canvas, 0.72, 0)
    cv2.putText(canvas, "Ground truth", (18, 38), cv2.FONT_HERSHEY_SIMPLEX, 1.0, (255, 255, 255), 3, cv2.LINE_AA)
    cv2.putText(canvas, "Ground truth", (18, 38), cv2.FONT_HERSHEY_SIMPLEX, 1.0, (30, 30, 30), 1, cv2.LINE_AA)
    return canvas


def add_title(image: np.ndarray, title: str) -> np.ndarray:
    bordered = cv2.copyMakeBorder(image, 42, 0, 0, 0, cv2.BORDER_CONSTANT, value=(28, 28, 28))
    cv2.putText(bordered, title, (14, 29), cv2.FONT_HERSHEY_SIMPLEX, 0.85, (255, 255, 255), 2, cv2.LINE_AA)
    return bordered


def make_contact_sheet(images: list[np.ndarray], output: Path, columns: int = 2, width: int = 1100) -> None:
    if not images:
        return
    cell_width = width // columns
    cells: list[np.ndarray] = []
    for image in images:
        scale = cell_width / image.shape[1]
        cells.append(cv2.resize(image, (cell_width, round(image.shape[0] * scale)), interpolation=cv2.INTER_AREA))
    rows: list[np.ndarray] = []
    for start in range(0, len(cells), columns):
        row = cells[start:start + columns]
        max_height = max(item.shape[0] for item in row)
        row = [cv2.copyMakeBorder(item, 0, max_height - item.shape[0], 0, 0, cv2.BORDER_CONSTANT, value=(22, 22, 22)) for item in row]
        if len(row) < columns:
            row.append(np.zeros_like(row[0]))
        rows.append(np.hstack(row))
    imwrite_unicode(output, np.vstack(rows))


def main() -> None:
    args = parse_args()
    if not args.weights.exists():
        raise FileNotFoundError(f"Weight file not found: {args.weights}")
    images = list_images(args.source)
    random.Random(args.seed).shuffle(images)
    images = images[:args.max_images]

    model = YOLO(str(args.weights))
    names = {int(key): str(value) for key, value in model.names.items()}
    collapse_to_four = len(names) == 4
    output = args.output
    output.mkdir(parents=True, exist_ok=True)
    panels: list[np.ndarray] = []

    for index, image_path in enumerate(images, start=1):
        result = model.predict(str(image_path), imgsz=args.imgsz, conf=args.conf, iou=0.7, device=0, verbose=False, retina_masks=False)[0]
        prediction = result.plot(conf=True, boxes=True, masks=True)
        prediction = add_title(prediction, f"Prediction  |  {image_path.name}  |  conf >= {args.conf:.2f}")
        if args.ground_truth:
            truth = add_title(draw_ground_truth(image_path, names, collapse_to_four), f"Ground truth  |  {image_path.name}")
            panel = np.hstack((truth, prediction))
        else:
            panel = prediction
        imwrite_unicode(output / f"{index:02d}_{image_path.stem}.jpg", panel)
        panels.append(panel)
        detected = 0 if result.boxes is None else len(result.boxes)
        print(f"{index:02d}/{len(images)} {image_path.name}: {detected} predictions")

    make_contact_sheet(panels, output / "contact_sheet.jpg")
    print(f"Saved {len(images)} visualizations to: {output}")


if __name__ == "__main__":
    main()
