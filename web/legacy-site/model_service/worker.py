#!/usr/bin/env python3
"""Poll Chijing PHP model jobs and run the packaged oral-photo models."""

from __future__ import annotations

import argparse
import base64
import gc
import math
import os
import tempfile
import time
from collections import Counter
from pathlib import Path
from typing import Any

import requests
import cv2
import numpy as np
import torch
from ultralytics import YOLO


ROOT = Path(__file__).resolve().parent
WORKER_BUILD = "20260823-darkline-demo-v2"
WEIGHTS = ROOT / "weights"
CARIES_WEIGHT = WEIGHTS / "caries_yolov8s_best.pt"
RESTORATION_WEIGHT = WEIGHTS / "alphadent_4class_960.pt"
DENTAL_SEG_WEIGHT = WEIGHTS / "dental_seg_yolo11n_best.pt"
CALCULUS_SEG_WEIGHT = WEIGHTS / "calculus_seg_ensemble_v5.pt"
TOOTH_OUTLINE_WEIGHT = WEIGHTS / "tooth_instance_best.pt"
SINGLE_PIPELINES = {"caries", "both", "dental_seg", "calculus_seg", "tooth_outline"}
VALID_PIPELINES = {*SINGLE_PIPELINES, "all_models"}
ALL_MODEL_STAGES = [
    ("caries", "龋齿候选检测"),
    ("both", "综合检测"),
    ("dental_seg", "口腔四类分割"),
    ("calculus_seg", "牙结石分割"),
    ("tooth_outline", "牙齿实例轮廓与浅龋暗线"),
]


def model_identity(pipeline: str) -> tuple[str, str]:
    if pipeline == "all_models":
        return "all_models_joint_v1", "20260726"
    if pipeline == "dental_seg":
        return "dental_seg_yolo11n_v1", "20260623"
    if pipeline == "calculus_seg":
        return "calculus_seg_ensemble_v5", "v5-strict"
    if pipeline == "tooth_outline":
        return "tooth_outline_darkline_v3", "20260812"
    return "oral_photo_models_v1", "20260721"


def model_device() -> str:
    configured = os.getenv("CHIJING_MODEL_DEVICE", "").strip()
    if configured:
        return configured
    return "0" if torch.cuda.is_available() else "cpu"


class OralPhotoModels:
    def __init__(self, device: str, pipeline: str) -> None:
        if pipeline not in SINGLE_PIPELINES:
            raise ValueError("CHIJING_MODEL_PIPELINE 不是受支持的模型流水线。")
        if pipeline == "caries":
            required = [CARIES_WEIGHT]
        elif pipeline == "both":
            required = [CARIES_WEIGHT, RESTORATION_WEIGHT]
        elif pipeline == "dental_seg":
            required = [DENTAL_SEG_WEIGHT]
        elif pipeline == "calculus_seg":
            required = [CALCULUS_SEG_WEIGHT]
        else:
            required = [TOOTH_OUTLINE_WEIGHT]
        missing = [str(path) for path in required if not path.is_file()]
        if missing:
            raise FileNotFoundError("模型权重不存在：" + ", ".join(missing))
        self.device = device
        self.pipeline = pipeline
        threads = max(1, int(os.getenv("CHIJING_TORCH_THREADS", "1")))
        torch.set_num_threads(threads)
        self.caries = YOLO(str(CARIES_WEIGHT)) if pipeline in {"caries", "both"} else None
        self.restoration = YOLO(str(RESTORATION_WEIGHT)) if pipeline == "both" else None
        self.dental_seg = YOLO(str(DENTAL_SEG_WEIGHT)) if pipeline == "dental_seg" else None
        self.calculus_seg = None
        self.tooth_outline = None
        if pipeline == "calculus_seg":
            from calculus_model import CalculusSegmentationModel

            self.calculus_seg = CalculusSegmentationModel(CALCULUS_SEG_WEIGHT, device)
        if pipeline == "tooth_outline":
            from tooth_outline_model import ToothOutlineModel

            self.tooth_outline = ToothOutlineModel(TOOTH_OUTLINE_WEIGHT, device)

    @staticmethod
    def items(result: Any, model_key: str) -> list[dict[str, Any]]:
        if result.boxes is None:
            return []
        boxes = result.boxes.xyxy.detach().cpu().tolist()
        scores = result.boxes.conf.detach().cpu().tolist()
        classes = result.boxes.cls.detach().cpu().tolist()
        names = result.names
        mask_polygons = result.masks.xy if result.masks is not None else []
        output: list[dict[str, Any]] = []
        for index, (box, score, class_id) in enumerate(zip(boxes, scores, classes)):
            class_index = int(class_id)
            label = names.get(class_index, str(class_index)) if isinstance(names, dict) else str(names[class_index])
            item = {
                "model": model_key,
                "label": str(label),
                "confidence": round(float(score), 4),
                "bbox_xyxy": [round(float(value), 1) for value in box],
            }
            if index < len(mask_polygons):
                points = mask_polygons[index]
                if len(points) > 96:
                    points = points[::math.ceil(len(points) / 96)]
                if len(points) >= 3:
                    item["polygon"] = [
                        [round(float(point[0]), 1), round(float(point[1]), 1)]
                        for point in points
                    ]
            output.append(item)
        return output

    @staticmethod
    def class_stats(findings: list[dict[str, Any]]) -> dict[str, dict[str, Any]]:
        grouped: dict[str, list[float]] = {}
        for item in findings:
            grouped.setdefault(str(item["label"]), []).append(float(item["confidence"]))
        return {
            label: {
                "count": len(scores),
                "average_confidence": round(sum(scores) / len(scores), 4),
                "maximum_confidence": round(max(scores), 4),
            }
            for label, scores in grouped.items()
        }

    def runtime_info(self, result: Any) -> dict[str, Any]:
        height, width = result.orig_shape
        if str(self.device) != "cpu" and torch.cuda.is_available():
            try:
                device_name = torch.cuda.get_device_name(int(str(self.device).split(":")[-1]))
            except (ValueError, RuntimeError):
                device_name = torch.cuda.get_device_name(0)
        else:
            device_name = "CPU"
        speed = result.speed if isinstance(result.speed, dict) else {}
        return {
            "device": device_name,
            "image_width": int(width),
            "image_height": int(height),
            "preprocess_ms": round(float(speed.get("preprocess", 0.0)), 2),
            "inference_ms": round(float(speed.get("inference", 0.0)), 2),
            "postprocess_ms": round(float(speed.get("postprocess", 0.0)), 2),
        }

    def infer(self, image_path: Path) -> dict[str, Any]:
        if self.tooth_outline is not None:
            return self.tooth_outline.infer(image_path)
        if self.calculus_seg is not None:
            return self.calculus_seg.infer(image_path)

        if self.dental_seg is not None:
            segmentation_result = self.dental_seg.predict(
                source=str(image_path),
                imgsz=640,
                conf=0.25,
                iou=0.7,
                device=self.device,
                verbose=False,
            )[0]
            findings = self.items(segmentation_result, "dental_seg_yolo11n")
            counts = Counter(item["label"] for item in findings)
            label_map = {
                "Caries": "龋齿候选",
                "Cavity": "窝洞候选",
                "Crack": "裂纹候选",
                "Tooth": "牙齿区域",
            }
            parts = [
                f"{chinese_name} {counts.get(label, 0)} 个"
                for label, chinese_name in label_map.items()
            ]
            summary = "；".join(parts) + "。分割结果仅用于口腔照片辅助筛查，需由口腔专业人员复核。"
            return {
                "summary_text": summary,
                "risk_level": "unknown",
                "raw_result_json": {
                    "pipeline": "dental_seg_yolo11n_v1",
                    "models": [{
                        "name": "dental_seg_yolo11n",
                        "weight": DENTAL_SEG_WEIGHT.name,
                        "task": "segment",
                        "imgsz": 640,
                        "confidence": 0.25,
                        "classes": label_map,
                    }],
                    "counts": dict(counts),
                    "class_stats": self.class_stats(findings),
                    "runtime": self.runtime_info(segmentation_result),
                    "findings": findings,
                },
            }

        if self.caries is None:
            raise RuntimeError("龋齿检测模型尚未加载。")
        caries_result = self.caries.predict(source=str(image_path), imgsz=640, conf=0.25, iou=0.7, device=self.device, verbose=False)[0]
        findings = self.items(caries_result, "caries_yolov8s")
        model_info = [{"name": "caries_yolov8s", "weight": CARIES_WEIGHT.name, "imgsz": 640, "confidence": 0.25}]
        if self.restoration is not None:
            restoration_result = self.restoration.predict(source=str(image_path), imgsz=960, conf=0.25, iou=0.7, device=self.device, verbose=False)[0]
            findings += self.items(restoration_result, "alphadent_4class")
            model_info.append({"name": "alphadent_4class", "weight": RESTORATION_WEIGHT.name, "imgsz": 960, "confidence": 0.25})
        counts = Counter(item["label"] for item in findings)
        caries_count = sum(1 for item in findings if item["model"] == "caries_yolov8s" and item["label"].lower() == "caries")
        parts = [f"龋齿候选区域 {caries_count} 个"]
        label_map = {"Abrasion": "磨耗", "Filling": "充填体", "Crown": "牙冠", "Caries": "龋齿候选"}
        for label in ("Abrasion", "Filling", "Crown", "Caries"):
            if counts[label]: parts.append(f"{label_map[label]} {counts[label]} 个")
        summary = "；".join(parts) + "。结果仅用于口腔照片辅助筛查，需由口腔专业人员复核。"
        return {
            "summary_text": summary,
            "risk_level": "unknown",
            "raw_result_json": {
                "pipeline": "oral_photo_models_v1",
                "models": model_info,
                "counts": dict(counts),
                "class_stats": self.class_stats(findings),
                "runtime": self.runtime_info(caries_result),
                "findings": findings,
            },
        }


class ChijingClient:
    def __init__(self, base_url: str, secret: str, host_header: str = "") -> None:
        self.base_url = base_url.rstrip("/")
        self.session = requests.Session()
        self.headers = {"X-Model-Secret": secret}
        if host_header:
            self.headers["Host"] = host_header

    def next_job(self) -> dict[str, Any] | None:
        response = self.session.post(f"{self.base_url}/api/model_jobs.php?action=next", headers=self.headers, timeout=25)
        response.raise_for_status()
        payload = response.json()
        if not payload.get("ok"):
            raise RuntimeError(payload.get("error", "领取模型任务失败。"))
        return payload.get("job")

    def next_darkline_job(self) -> dict[str, Any] | None:
        response = self.session.post(
            f"{self.base_url}/api/tooth_darkline_lab.php?action=next",
            headers=self.headers,
            timeout=25,
        )
        response.raise_for_status()
        payload = response.json()
        if not payload.get("ok"):
            raise RuntimeError(payload.get("error", "领取浅龋证据层任务失败。"))
        return payload.get("job")

    def complete_darkline_job(
        self,
        job_id: str,
        status: str,
        result: dict[str, Any] | None = None,
        evidence_layers: dict[str, str] | None = None,
        error_message: str = "",
    ) -> None:
        response = self.session.post(
            f"{self.base_url}/api/tooth_darkline_lab.php?action=complete",
            headers={**self.headers, "Content-Type": "application/json"},
            json={
                "job_id": job_id,
                "status": status,
                "result": result or {},
                "evidence_layers": evidence_layers or {},
                "error_message": error_message[:500],
            },
            timeout=60,
        )
        response.raise_for_status()
        payload = response.json()
        if not payload.get("ok"):
            raise RuntimeError(payload.get("error", "浅龋证据层回传失败。"))

    def download_image(self, detection_id: str) -> tuple[bytes, str]:
        response = self.session.get(f"{self.base_url}/api/model_image.php", params={"detection_id": detection_id}, headers=self.headers, timeout=60)
        response.raise_for_status()
        mime = response.headers.get("Content-Type", "").split(";", 1)[0].strip().lower()
        suffix = {"image/png": ".png", "image/webp": ".webp"}.get(mime, ".jpg")
        return response.content, suffix

    def download_darkline_demo_image(self, dataset: str, view_id: str) -> tuple[bytes, str]:
        response = self.session.get(
            f"{self.base_url}/api/tooth_darkline_lab.php",
            params={"action": "demo_image", "dataset": dataset, "view_id": view_id},
            headers=self.headers,
            timeout=60,
        )
        response.raise_for_status()
        mime = response.headers.get("Content-Type", "").split(";", 1)[0].strip().lower()
        suffix = {"image/png": ".png", "image/webp": ".webp"}.get(mime, ".jpg")
        return response.content, suffix

    def report(self, payload: dict[str, Any]) -> None:
        response = self.session.post(f"{self.base_url}/api/model_result.php", headers={**self.headers, "Content-Type": "application/json"}, json=payload, timeout=40)
        response.raise_for_status()
        result = response.json()
        if not result.get("ok"):
            raise RuntimeError(result.get("error", "模型结果回传失败。"))

    def progress(self, detection_id: str, step: int, total: int, label: str) -> None:
        response = self.session.post(
            f"{self.base_url}/api/model_jobs.php?action=progress",
            headers={**self.headers, "Content-Type": "application/json"},
            json={"detection_id": detection_id, "step": step, "total": total, "label": label},
            timeout=20,
        )
        response.raise_for_status()
        result = response.json()
        if not result.get("ok"):
            raise RuntimeError(result.get("error", "模型进度回传失败。"))


def _encoded_evidence(image: np.ndarray, extension: str = ".png") -> str:
    parameters: list[int] = []
    if extension.lower() in {".jpg", ".jpeg"}:
        parameters = [cv2.IMWRITE_JPEG_QUALITY, 90]
    ok, encoded = cv2.imencode(extension, image, parameters)
    if not ok:
        raise RuntimeError("证据层图片编码失败。")
    return base64.b64encode(encoded.tobytes()).decode("ascii")


def _sample_coordinate_pairs(points: Any, limit: int = 192) -> list[list[float]]:
    output: list[list[float]] = []
    if not isinstance(points, (list, tuple)):
        return output
    step = max(1, math.ceil(len(points) / limit))
    for point in points[::step]:
        if isinstance(point, (list, tuple)) and len(point) >= 2:
            output.append([round(float(point[0]), 1), round(float(point[1]), 1)])
    return output


def process_darkline_job(client: ChijingClient, job: dict[str, Any]) -> None:
    """Re-run the exact desktop OpenCV pipeline for one cloud-selected tooth."""
    from darkline_core import AnalysisParams, ToothInstance, analyze_tooth, read_image

    job_id = str(job.get("public_id") or "")
    detection_id = str(job.get("detection_id") or "")
    source_kind = str(job.get("source_kind") or "").strip().lower()
    demo_dataset = str(job.get("demo_dataset") or "").strip()
    demo_view_id = str(job.get("demo_view_id") or "").strip()
    is_demo_source = source_kind == "demo" or (
        detection_id == "" and demo_dataset != "" and demo_view_id != ""
    )
    tooth_payload = job.get("tooth") if isinstance(job.get("tooth"), dict) else {}
    navigation_id = int(job.get("tooth_navigation_id") or tooth_payload.get("navigation_id") or 0)
    temp_path: Path | None = None
    try:
        if is_demo_source:
            image_bytes, image_suffix = client.download_darkline_demo_image(
                demo_dataset,
                demo_view_id,
            )
        else:
            if detection_id == "":
                raise ValueError(
                    "浅龋任务缺少影像来源：服务器未返回 detection_id，也未返回完整的测试数据来源。"
                )
            image_bytes, image_suffix = client.download_image(detection_id)
        with tempfile.NamedTemporaryFile(prefix="chijing_darkline_", suffix=image_suffix, delete=False) as handle:
            handle.write(image_bytes)
            temp_path = Path(handle.name)
        image = read_image(temp_path)
        height, width = image.shape[:2]
        polygon = tooth_payload.get("polygon") or tooth_payload.get("contour_xy") or []
        if not isinstance(polygon, list) or len(polygon) < 3:
            raise ValueError("所选牙齿缺少有效轮廓坐标。")
        contour = np.asarray(
            [[round(float(point[0])), round(float(point[1]))] for point in polygon if isinstance(point, (list, tuple)) and len(point) >= 2],
            dtype=np.int32,
        ).reshape((-1, 1, 2))
        if len(contour) < 3:
            raise ValueError("所选牙齿轮廓坐标不足。")
        mask = np.zeros((height, width), dtype=np.uint8)
        cv2.fillPoly(mask, [contour], 1)
        box = tooth_payload.get("bbox_xyxy") or []
        if not isinstance(box, list) or len(box) != 4:
            x, y, box_width, box_height = cv2.boundingRect(contour)
            box = [x, y, x + box_width, y + box_height]
        moments = cv2.moments(contour)
        centroid = tooth_payload.get("centroid_xy") or []
        if not isinstance(centroid, list) or len(centroid) != 2:
            centroid = [
                moments["m10"] / moments["m00"] if moments["m00"] else (float(box[0]) + float(box[2])) / 2,
                moments["m01"] / moments["m00"] if moments["m00"] else (float(box[1]) + float(box[3])) / 2,
            ]
        tooth = ToothInstance(
            index=navigation_id,
            confidence=float(tooth_payload.get("confidence") or 0),
            mask=mask,
            bbox=tuple(int(round(float(value))) for value in box),
            centroid=(float(centroid[0]), float(centroid[1])),
            contour=contour,
        )
        defaults = AnalysisParams()
        requested = job.get("parameters") if isinstance(job.get("parameters"), dict) else {}
        params = AnalysisParams(**{
            key: requested.get(key, getattr(defaults, key))
            for key in defaults.__dict__.keys()
        })
        started = time.perf_counter()
        analysis = analyze_tooth(image, tooth, params)
        elapsed_ms = round((time.perf_counter() - started) * 1000, 2)
        overview = image.copy()
        overlay = overview.copy()
        cv2.fillPoly(overlay, [contour], (48, 190, 255))
        overview = cv2.addWeighted(overview, 0.82, overlay, 0.18, 0)
        cv2.drawContours(overview, [contour], -1, (48, 190, 255), max(2, width // 640), cv2.LINE_AA)
        label_at = (max(4, int(box[0])), max(24, int(box[1]) - 8))
        cv2.putText(overview, f"T{navigation_id:02d}", label_at, cv2.FONT_HERSHEY_SIMPLEX, max(.55, width / 1900), (48, 190, 255), 2, cv2.LINE_AA)
        evidence = {"overview": _encoded_evidence(overview, ".jpg")}
        evidence.update({name: _encoded_evidence(view, ".png") for name, view in analysis.views.items()})
        candidates: list[dict[str, Any]] = []
        for candidate in analysis.candidates:
            candidates.append({
                "candidate_id": f"T{navigation_id:02d}-D{candidate.index:02d}",
                "kind": candidate.kind,
                "structure_score": round(float(candidate.structure_score), 4),
                "area_px": int(candidate.area_px),
                "skeleton_length_px": int(candidate.skeleton_length_px),
                "length_ratio": round(float(candidate.length_ratio), 4),
                "median_width_px": round(float(candidate.median_width_px), 3),
                "black_core_ratio": round(float(candidate.black_core_ratio), 4),
                "local_contrast_pct": round(float(candidate.local_contrast_pct), 3),
                "evidence_mode": candidate.evidence_mode,
                "branch_count": len(candidate.branchpoints),
                "endpoint_count": len(candidate.endpoints),
                "polygon": _sample_coordinate_pairs(candidate.contour_xy, 96),
                "skeleton_xy": _sample_coordinate_pairs(candidate.skeleton_xy, 128),
                "branchpoints": _sample_coordinate_pairs(candidate.branchpoints, 32),
            })
        result = {
            "tooth_navigation_id": navigation_id,
            "crop_bounds": list(analysis.crop_bounds),
            "parameters": params.__dict__,
            "candidate_count": len(candidates),
            "total_skeleton_length_px": sum(int(item["skeleton_length_px"]) for item in candidates),
            "branch_count": sum(int(item["branch_count"]) for item in candidates),
            "tooth_confidence": round(float(tooth.confidence), 4),
            "processing_ms": elapsed_ms,
            "candidates": candidates,
        }
        client.complete_darkline_job(job_id, "completed", result=result, evidence_layers=evidence)
        print(f"完成浅龋证据层：{job_id} / T{navigation_id:02d}", flush=True)
    except Exception as error:
        client.complete_darkline_job(job_id, "failed", error_message=str(error))
        print(f"浅龋证据层任务 {job_id} 失败：{error}", flush=True)
    finally:
        if temp_path is not None:
            temp_path.unlink(missing_ok=True)


def run_all_models(
    client: ChijingClient,
    models: dict[str, OralPhotoModels],
    device: str,
    detection_id: str,
    image_path: Path,
) -> dict[str, Any]:
    pipeline_results: list[dict[str, Any]] = []
    combined_findings: list[dict[str, Any]] = []
    combined_models: list[dict[str, Any]] = []
    summaries: list[str] = []
    failures: list[dict[str, str]] = []
    total = len(ALL_MODEL_STAGES)

    def release_stage_model(pipeline_key: str) -> None:
        models.pop(pipeline_key, None)
        gc.collect()
        if torch.cuda.is_available():
            torch.cuda.empty_cache()

    for step, (pipeline, title) in enumerate(ALL_MODEL_STAGES, start=1):
        stage_result: dict[str, Any] | None = None
        stage_error: Exception | None = None
        attempts = 0
        for attempt in range(1, 3):
            attempts = attempt
            retry_text = "（重试）" if attempt == 2 else ""
            client.progress(detection_id, step, total, f"正在运行 {step}/{total}：{title}{retry_text}")
            try:
                if pipeline not in models:
                    models[pipeline] = OralPhotoModels(device, pipeline)
                stage_result = models[pipeline].infer(image_path)
                stage_error = None
                break
            except Exception as error:
                stage_error = error
                models.pop(pipeline, None)

        if stage_result is None:
            release_stage_model(pipeline)
            message = str(stage_error or "未知错误")[:500]
            failures.append({"pipeline": pipeline, "title": title, "error": message})
            pipeline_results.append({
                "pipeline": pipeline,
                "title": title,
                "status": "failed",
                "attempts": attempts,
                "error": message,
            })
            continue

        raw = stage_result.get("raw_result_json") or {}
        summary = str(stage_result.get("summary_text") or f"{title}已完成。")
        summaries.append(f"{title}：{summary}")
        stage_findings: list[dict[str, Any]] = []
        for finding in raw.get("findings") or []:
            if not isinstance(finding, dict):
                continue
            item = dict(finding)
            item["pipeline_key"] = pipeline
            item["pipeline_title"] = title
            stage_findings.append(item)
            combined_findings.append(item)
        for model in raw.get("models") or []:
            if not isinstance(model, dict):
                continue
            item = dict(model)
            item["pipeline_key"] = pipeline
            item["pipeline_title"] = title
            combined_models.append(item)
        pipeline_results.append({
            "pipeline": pipeline,
            "title": title,
            "status": "completed",
            "attempts": attempts,
            "summary_text": summary,
            "risk_level": stage_result.get("risk_level", "unknown"),
            "counts": raw.get("counts") or {},
            "class_stats": raw.get("class_stats") or {},
            "runtime": raw.get("runtime") or {},
            "teeth": raw.get("teeth") or [],
            "findings": stage_findings,
            "models": [
                item for item in combined_models if item.get("pipeline_key") == pipeline
            ],
        })
        release_stage_model(pipeline)

    success_count = total - len(failures)
    if success_count == 0:
        raise RuntimeError("全部模型均运行失败：" + "；".join(item["error"] for item in failures))
    completion_status = "partial" if failures else "completed"
    suffix = (
        f"其中 {len(failures)} 个模型在自动重试后仍失败，已保留其余结果。"
        if failures else
        "五个分析流水线均已完成。"
    )
    return {
        "summary_text": "；".join(summaries) + " " + suffix,
        "risk_level": "unknown",
        "raw_result_json": {
            "pipeline": "all_models_joint_v1",
            "completion_status": completion_status,
            "stage_total": total,
            "stage_succeeded": success_count,
            "stage_failed": len(failures),
            "pipeline_results": pipeline_results,
            "failures": failures,
            "models": combined_models,
            "findings": combined_findings,
        },
    }


def process_one(client: ChijingClient, models: dict[str, OralPhotoModels], device: str, fallback_pipeline: str) -> bool:
    darkline_job = client.next_darkline_job()
    if darkline_job is not None:
        process_darkline_job(client, darkline_job)
        return True
    job = client.next_job()
    if job is None:
        return False
    detection_id = str(job["public_id"])
    pipeline = str(job.get("model_pipeline") or fallback_pipeline).strip().lower()
    if pipeline not in VALID_PIPELINES:
        pipeline = fallback_pipeline if fallback_pipeline in VALID_PIPELINES else "caries"
    temp_path: Path | None = None
    try:
        image_bytes, image_suffix = client.download_image(detection_id)
        with tempfile.NamedTemporaryFile(prefix="chijing_", suffix=image_suffix, delete=False) as handle:
            handle.write(image_bytes)
            temp_path = Path(handle.name)
        if pipeline == "all_models":
            result = run_all_models(client, models, device, detection_id, temp_path)
        else:
            if pipeline not in models:
                models[pipeline] = OralPhotoModels(device, pipeline)
            client.progress(detection_id, 1, 1, f"正在运行 1/1：{pipeline}")
            result = models[pipeline].infer(temp_path)
        model_name, model_version = model_identity(pipeline)
        client.report({
            "detection_id": detection_id,
            "model_name": model_name,
            "model_version": model_version,
            "model_type": "vision",
            "status": "completed",
            **result,
        })
        print(f"完成检测：{detection_id}", flush=True)
    except Exception as error:
        try:
            model_name, model_version = model_identity(pipeline)
            client.report({
                "detection_id": detection_id,
                "model_name": model_name,
                "model_version": model_version,
                "model_type": "vision",
                "status": "failed",
                "risk_level": "unknown",
                "error_message": str(error)[:500],
            })
        except Exception as report_error:
            print(f"任务 {detection_id} 失败且无法回传：{report_error}", flush=True)
        print(f"任务 {detection_id} 推理失败：{error}", flush=True)
    finally:
        if temp_path is not None:
            temp_path.unlink(missing_ok=True)
    return True


def main() -> None:
    parser = argparse.ArgumentParser(description="齿镜口腔照片模型常驻工作进程")
    parser.add_argument("--once", action="store_true", help="只领取并处理一个任务，适合宝塔计划任务。")
    parser.add_argument("--interval", type=float, default=float(os.getenv("CHIJING_MODEL_INTERVAL", "3")), help="无任务时的轮询秒数。")
    args = parser.parse_args()
    base_url = os.getenv("CHIJING_BASE_URL", "").strip()
    secret = os.getenv("CHIJING_MODEL_SECRET", "").strip()
    host_header = os.getenv("CHIJING_HTTP_HOST", "").strip()
    if not base_url or not secret:
        raise SystemExit("请设置 CHIJING_BASE_URL 和 CHIJING_MODEL_SECRET 环境变量。")
    pipeline = os.getenv("CHIJING_MODEL_PIPELINE", "caries").strip().lower()
    device = model_device()
    client = ChijingClient(base_url, secret, host_header)
    models: dict[str, OralPhotoModels] = {}
    print(f"模型工作进程已启动，版本={WORKER_BUILD}，设备={device}，默认流水线={pipeline}", flush=True)
    while True:
        try:
            worked = process_one(client, models, device, pipeline)
        except requests.RequestException as error:
            print(
                f"模型队列网络请求失败，将自动重试：{type(error).__name__}: {error}",
                flush=True,
            )
            if args.once:
                raise
            time.sleep(max(args.interval, 3.0))
            continue
        if args.once:
            return
        if not worked:
            time.sleep(max(args.interval, 1.0))


if __name__ == "__main__":
    main()
