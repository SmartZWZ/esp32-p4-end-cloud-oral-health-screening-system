import argparse
import json
import shutil
from pathlib import Path


DATASET_ROOT = Path(
    r"D:\Downloads\EdgeDownload\MODEL\MODEL\database\dentalai-DatasetNinja"
)
OUTPUT_ROOT = Path(r"C:\Users\16024\Desktop\齿镜\.audit_dental_seg")
CLASS_MAP = {"Caries": 0, "Cavity": 1, "Crack": 2, "Tooth": 3}


def convert_split(source_split: str, output_split: str) -> dict:
    source = DATASET_ROOT / source_split
    images_out = OUTPUT_ROOT / "images" / output_split
    labels_out = OUTPUT_ROOT / "labels" / output_split
    images_out.mkdir(parents=True, exist_ok=True)
    labels_out.mkdir(parents=True, exist_ok=True)

    converted = 0
    objects = {name: 0 for name in CLASS_MAP}
    for image_path in sorted((source / "img").iterdir()):
        if not image_path.is_file():
            continue
        annotation_path = source / "ann" / f"{image_path.name}.json"
        if not annotation_path.exists():
            continue
        data = json.loads(annotation_path.read_text(encoding="utf-8"))
        width = data["size"]["width"]
        height = data["size"]["height"]
        lines = []
        for obj in data.get("objects", []):
            class_name = obj.get("classTitle")
            if class_name not in CLASS_MAP:
                continue
            points = obj.get("points", {}).get("exterior", [])
            if len(points) < 3:
                continue
            coords = " ".join(
                f"{x / width:.6f} {y / height:.6f}" for x, y in points
            )
            lines.append(f"{CLASS_MAP[class_name]} {coords}")
            objects[class_name] += 1
        shutil.copy2(image_path, images_out / image_path.name)
        (labels_out / f"{image_path.stem}.txt").write_text(
            "\n".join(lines) + ("\n" if lines else ""), encoding="utf-8"
        )
        converted += 1
    return {"images": converted, "objects": objects}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-split", default="test")
    parser.add_argument("--output-split", default="val")
    args = parser.parse_args()

    result = convert_split(args.source_split, args.output_split)
    yaml_path = OUTPUT_ROOT / f"dental_audit_{args.output_split}.yaml"
    yaml_text = (
        f"path: {OUTPUT_ROOT.as_posix()}\n"
        f"train: images/{args.output_split}\n"
        f"val: images/{args.output_split}\n\n"
        "names:\n"
        "  0: Caries\n"
        "  1: Cavity\n"
        "  2: Crack\n"
        "  3: Tooth\n"
    )
    yaml_path.write_text(yaml_text, encoding="utf-8")
    print({**result, "yaml": str(yaml_path)})


if __name__ == "__main__":
    main()
