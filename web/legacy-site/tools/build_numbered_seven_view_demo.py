from __future__ import annotations

import importlib.util
import json
import re
import shutil
import sys
from dataclasses import asdict
from pathlib import Path

import cv2
import numpy as np


PROJECT_ROOT = Path(__file__).resolve().parents[1]
CHI_JING_ROOT = PROJECT_ROOT.parents[1]
SOURCE_ROOT = CHI_JING_ROOT / "7张图-nd"
ALGORITHM_ROOT = CHI_JING_ROOT / "发布包" / "牙齿轮廓与微龋暗线分析器_v3_20260812"
OUTPUT_ROOT = PROJECT_ROOT / "assets" / "demo" / "seven-view-numbered-v1"

VIEW_CONFIG = [
    ("front_bite", "正面咬合", "1正面咬合.jpg", "1正面咬合.txt"),
    ("left_bite", "左侧咬合", "2左侧咬合.jpg", "2左侧咬合.txt"),
    ("right_bite", "右侧咬合", "3右侧咬合.jpg", "3右侧咬合.txt"),
    ("upper_left_open", "左上牙列", "4左上张嘴.jpg", "4左上张嘴.txt"),
    ("upper_right_open", "右上牙列", "5右上张嘴.jpg", "5右上张嘴.txt"),
    ("lower_left_open", "左下牙列", "6左下张嘴.jpg", "6左下张嘴.txt"),
    ("lower_right_open", "右下牙列", "7右下张嘴.jpg", "7右下张嘴.txt"),
]


def load_algorithm():
    spec = importlib.util.spec_from_file_location("chijing_darkline_core", ALGORITHM_ROOT / "darkline_core.py")
    if spec is None or spec.loader is None:
        raise RuntimeError("无法加载暗线分析算法。")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def read_image(path: Path) -> np.ndarray:
    image = cv2.imdecode(np.fromfile(path, dtype=np.uint8), cv2.IMREAD_COLOR)
    if image is None:
        raise ValueError(f"无法读取图片：{path}")
    return image


def write_png(path: Path, image: np.ndarray) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    ok, encoded = cv2.imencode(".png", image, [cv2.IMWRITE_PNG_COMPRESSION, 7])
    if not ok:
        raise ValueError(f"无法写入图片：{path}")
    encoded.tofile(path)


def parse_coordinate_file(path: Path) -> dict:
    text = path.read_text(encoding="utf-8")
    width = int(re.search(r"^image_width:\s*(\d+)", text, re.M).group(1))
    height = int(re.search(r"^image_height:\s*(\d+)", text, re.M).group(1))
    teeth = []
    for block in re.split(r"(?=^tooth_id:\s*\d+)", text, flags=re.M)[1:]:
        tooth_match = re.search(r"^tooth_id:\s*(\d+)", block, re.M)
        polygon_match = re.search(r"^polygon_xy:\s*(.+)$", block, re.M)
        if not tooth_match or not polygon_match:
            continue
        polygon = [(int(x), int(y)) for x, y in re.findall(r"\((\d+),(\d+)\)", polygon_match.group(1))]
        if len(polygon) < 3:
            continue
        teeth.append({"fdi": int(tooth_match.group(1)), "polygon": polygon})
    return {"width": width, "height": height, "teeth": teeth}


def alpha_masked(image: np.ndarray, mask: np.ndarray) -> np.ndarray:
    bgra = cv2.cvtColor(image, cv2.COLOR_BGR2BGRA)
    bgra[:, :, 3] = np.where(mask > 0, 255, 0).astype(np.uint8)
    return bgra


def exact_stage(view: np.ndarray, crop_bounds: tuple[int, int, int, int], bbox: tuple[int, int, int, int], mask: np.ndarray) -> np.ndarray:
    cx0, cy0, _, _ = crop_bounds
    x0, y0, x1, y1 = bbox
    result = view[y0 - cy0 : y1 - cy0, x0 - cx0 : x1 - cx0].copy()
    return alpha_masked(result, mask[y0:y1, x0:x1])


def main() -> None:
    core = load_algorithm()
    params = core.AnalysisParams()
    if OUTPUT_ROOT.exists():
        shutil.rmtree(OUTPUT_ROOT)
    (OUTPUT_ROOT / "original").mkdir(parents=True)
    (OUTPUT_ROOT / "teeth").mkdir(parents=True)

    manifest = {
        "ok": True,
        "archive_id": "seven-view-numbered-v1",
        "title": "七视图单牙档案 · 编号测试 01",
        "created_at": "2026-08-14 00:00:00",
        "notice": "暗线结构分数用于候选排序，不是患龋概率，也不构成诊断。",
        "coordinate_system": "FDI 恒牙编号；原图像素坐标，左上角为原点。",
        "parameters": asdict(params),
        "views": [],
        "teeth": {},
    }

    for view_index, (view_id, label, image_name, txt_name) in enumerate(VIEW_CONFIG, start=1):
        image_path = SOURCE_ROOT / "原图" / image_name
        coordinate_path = SOURCE_ROOT / "原图牙齿轮廓编号结果" / "轮廓坐标TXT" / txt_name
        parsed = parse_coordinate_file(coordinate_path)
        image = read_image(image_path)
        image_h, image_w = image.shape[:2]
        sx, sy = image_w / parsed["width"], image_h / parsed["height"]
        original_name = f"{view_index:02d}-{view_id}.jpg"
        shutil.copy2(image_path, OUTPUT_ROOT / "original" / original_name)

        view_entry = {
            "id": view_id,
            "index": view_index,
            "label": label,
            "original_url": f"assets/demo/seven-view-numbered-v1/original/{original_name}",
            "width": image_w,
            "height": image_h,
            "source_width": parsed["width"],
            "source_height": parsed["height"],
            "coordinate_scale": [round(sx, 8), round(sy, 8)],
            "teeth": [],
        }

        for source_tooth in parsed["teeth"]:
            fdi = source_tooth["fdi"]
            polygon = np.asarray(
                [[round(x * sx), round(y * sy)] for x, y in source_tooth["polygon"]], dtype=np.int32
            )
            polygon[:, 0] = np.clip(polygon[:, 0], 0, image_w - 1)
            polygon[:, 1] = np.clip(polygon[:, 1], 0, image_h - 1)
            contour = polygon.reshape(-1, 1, 2)
            mask = np.zeros((image_h, image_w), dtype=np.uint8)
            cv2.fillPoly(mask, [polygon], 1)
            x, y, w, h = cv2.boundingRect(contour)
            bbox = (x, y, x + w, y + h)
            moments = cv2.moments(contour)
            centroid = (
                moments["m10"] / moments["m00"] if moments["m00"] else x + w / 2,
                moments["m01"] / moments["m00"] if moments["m00"] else y + h / 2,
            )
            tooth = core.ToothInstance(
                index=fdi,
                confidence=1.0,
                mask=mask,
                bbox=bbox,
                centroid=centroid,
                contour=contour,
            )
            analysis = core.analyze_tooth(image, tooth, params)
            x0, y0, x1, y1 = bbox
            exact_mask = mask[y0:y1, x0:x1]
            tooth_original = alpha_masked(image[y0:y1, x0:x1], exact_mask)
            contour_image = image[y0:y1, x0:x1].copy()
            local_contour = contour - np.array([[[x0, y0]]])
            cv2.drawContours(contour_image, [local_contour], -1, (235, 207, 88), 2, cv2.LINE_AA)
            tooth_contour = alpha_masked(contour_image, exact_mask)

            tooth_dir = OUTPUT_ROOT / "teeth" / str(fdi) / view_id
            stage_images = {
                "original": tooth_original,
                "contour": tooth_contour,
                "normalized": exact_stage(analysis.views["normalized"], analysis.crop_bounds, bbox, mask),
                "heatmap": exact_stage(analysis.views["heatmap"], analysis.crop_bounds, bbox, mask),
                "candidate": exact_stage(analysis.views["candidate"], analysis.crop_bounds, bbox, mask),
                "skeleton": exact_stage(analysis.views["skeleton"], analysis.crop_bounds, bbox, mask),
            }
            stage_urls = {}
            for stage_index, (stage_id, stage_image) in enumerate(stage_images.items(), start=1):
                filename = f"{stage_index:02d}-{stage_id}.png"
                write_png(tooth_dir / filename, stage_image)
                stage_urls[stage_id] = f"assets/demo/seven-view-numbered-v1/teeth/{fdi}/{view_id}/{filename}"

            candidates = []
            for candidate in analysis.candidates:
                candidates.append({
                    "index": candidate.index,
                    "kind": candidate.kind,
                    "structure_score": round(candidate.structure_score, 5),
                    "skeleton_length_px": candidate.skeleton_length_px,
                    "mean_width_px": round(candidate.mean_width_px, 3),
                    "median_width_px": round(candidate.median_width_px, 3),
                    "black_core_ratio": round(candidate.black_core_ratio, 5),
                    "local_contrast_pct": round(candidate.local_contrast_pct, 3),
                    "endpoints": candidate.endpoints,
                    "branchpoints": candidate.branchpoints,
                    "skeleton_xy": candidate.skeleton_xy,
                })
            tooth_view = {
                "view_id": view_id,
                "view_label": label,
                "original_url": view_entry["original_url"],
                "image_size": [image_w, image_h],
                "bbox_xyxy": list(bbox),
                "polygon_xy": polygon.tolist(),
                "pixel_area": int(cv2.countNonZero(mask)),
                "stages": stage_urls,
                "metrics": {
                    "candidate_count": len(candidates),
                    "max_structure_score": max((item["structure_score"] for item in candidates), default=0),
                    "total_skeleton_length_px": sum(item["skeleton_length_px"] for item in candidates),
                    "mean_candidate_width_px": round(float(np.mean([item["mean_width_px"] for item in candidates])), 3) if candidates else 0,
                },
                "candidates": candidates,
            }
            view_entry["teeth"].append({"fdi": fdi, "bbox_xyxy": list(bbox), "polygon_xy": polygon.tolist()})
            manifest["teeth"].setdefault(str(fdi), {"fdi": fdi, "views": []})["views"].append(tooth_view)
        manifest["views"].append(view_entry)

    present = sorted(int(key) for key in manifest["teeth"])
    manifest["present_teeth"] = present
    manifest["missing_teeth"] = [number for number in [18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28,48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38] if number not in present]
    manifest["summary"] = {
        "view_count": len(manifest["views"]),
        "tooth_count": len(present),
        "tooth_view_count": sum(len(item["views"]) for item in manifest["teeth"].values()),
    }
    (OUTPUT_ROOT / "manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(json.dumps(manifest["summary"], ensure_ascii=False))
    print(f"output={OUTPUT_ROOT}")


if __name__ == "__main__":
    main()
