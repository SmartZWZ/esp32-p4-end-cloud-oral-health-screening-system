"""Tooth instance contours and shallow-caries dark-line evidence for Chijing."""

from __future__ import annotations

import math
import time
from pathlib import Path
from typing import Any, Iterable

from ultralytics import YOLO

from darkline_core import AnalysisParams, analyze_tooth, detect_teeth, read_image


def _sample_points(points: Iterable[Iterable[float]], limit: int = 128) -> list[list[float]]:
    values = [[round(float(point[0]), 1), round(float(point[1]), 1)] for point in points]
    if len(values) > limit:
        step = math.ceil(len(values) / limit)
        values = values[::step]
    return values


class ToothOutlineModel:
    """Runs YOLO tooth instances, then analyses dark-line evidence inside each mask."""

    def __init__(self, weight_path: Path, device: str) -> None:
        self.weight_path = Path(weight_path)
        self.device = device
        self.model = YOLO(str(self.weight_path))
        self.params = AnalysisParams(
            edge_shrink_pct=6.0,
            darkness_threshold=0.602,
            black_level_pct=35.0,
            min_contrast_pct=5.0,
            min_width_px=0.5,
            min_length_pct=18.0,
            smooth_px=5,
            exclude_highlights=True,
        )

    def infer(self, image_path: Path) -> dict[str, Any]:
        started = time.perf_counter()
        image = read_image(image_path)
        height, width = image.shape[:2]
        inference_started = time.perf_counter()
        teeth = detect_teeth(self.model, image, conf=0.25)
        inference_ms = (time.perf_counter() - inference_started) * 1000

        findings: list[dict[str, Any]] = []
        tooth_payloads: list[dict[str, Any]] = []
        darkline_count = 0
        analysis_started = time.perf_counter()
        for tooth in teeth:
            contour = _sample_points((point[0] for point in tooth.contour), 144)
            tooth_finding = {
                "model": "tooth_instance_yolo11s_seg",
                "label": "Tooth",
                "instance_id": f"T{tooth.index:02d}",
                "navigation_id": tooth.index,
                "confidence": round(float(tooth.confidence), 4),
                "bbox_xyxy": [int(value) for value in tooth.bbox],
                "centroid_xy": [round(float(value), 1) for value in tooth.centroid],
                "polygon": contour,
            }
            findings.append(tooth_finding)

            analysis = analyze_tooth(image, tooth, self.params)
            candidates: list[dict[str, Any]] = []
            for candidate in analysis.candidates:
                darkline_count += 1
                candidate_payload = {
                    "candidate_id": f"T{tooth.index:02d}-D{candidate.index:02d}",
                    "kind": candidate.kind,
                    "structure_score": round(float(candidate.structure_score), 4),
                    "skeleton_length_px": int(candidate.skeleton_length_px),
                    "median_width_px": round(float(candidate.median_width_px), 3),
                    "black_core_ratio": round(float(candidate.black_core_ratio), 4),
                    "local_contrast_pct": round(float(candidate.local_contrast_pct), 3),
                    "evidence_mode": candidate.evidence_mode,
                    "polygon": _sample_points(candidate.contour_xy, 96),
                    "skeleton_xy": _sample_points(candidate.skeleton_xy, 96),
                    "branchpoints": _sample_points(candidate.branchpoints, 32),
                }
                candidates.append(candidate_payload)
                findings.append({
                    "model": "microcaries_darkline_v3",
                    "label": "MicrocariesDarkline",
                    "instance_id": candidate_payload["candidate_id"],
                    "tooth_navigation_id": tooth.index,
                    "confidence": candidate_payload["structure_score"],
                    "bbox_xyxy": [int(value) for value in tooth.bbox],
                    "polygon": candidate_payload["polygon"],
                    "skeleton_xy": candidate_payload["skeleton_xy"],
                    "metrics": {
                        "skeleton_length_px": candidate_payload["skeleton_length_px"],
                        "median_width_px": candidate_payload["median_width_px"],
                        "black_core_ratio": candidate_payload["black_core_ratio"],
                        "local_contrast_pct": candidate_payload["local_contrast_pct"],
                        "evidence_mode": candidate_payload["evidence_mode"],
                    },
                })

            tooth_payloads.append({
                **tooth_finding,
                "candidate_count": len(candidates),
                "darkline_candidates": candidates,
            })

        analysis_ms = (time.perf_counter() - analysis_started) * 1000
        elapsed_ms = (time.perf_counter() - started) * 1000
        summary = (
            f"检出牙齿实例 {len(teeth)} 颗，轮廓已建立；"
            f"轮廓内保留浅龋暗线研究候选 {darkline_count} 处。"
            "候选可能包含天然窝沟、色素、裂纹或修复体边缘，必须人工复核。"
        )
        return {
            "summary_text": summary,
            "risk_level": "unknown",
            "raw_result_json": {
                "pipeline": "tooth_outline_darkline_v3",
                "notice": "牙齿轮廓和暗线均为研究辅助结果，不是患者三维扫描或龋齿诊断。",
                "models": [{
                    "name": "tooth_instance_yolo11s_seg",
                    "weight": self.weight_path.name,
                    "task": "instance_segment",
                    "imgsz": 1024,
                    "confidence": 0.25,
                }, {
                    "name": "microcaries_darkline_v3",
                    "task": "classical_image_analysis",
                    "parameters": self.params.__dict__,
                }],
                "counts": {"Tooth": len(teeth), "MicrocariesDarkline": darkline_count},
                "runtime": {
                    "device": str(self.device),
                    "image_width": int(width),
                    "image_height": int(height),
                    "inference_ms": round(inference_ms, 2),
                    "darkline_analysis_ms": round(analysis_ms, 2),
                    "total_ms": round(elapsed_ms, 2),
                },
                "teeth": tooth_payloads,
                "findings": findings,
            },
        }
