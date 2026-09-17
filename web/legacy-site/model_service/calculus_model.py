"""Dental-calculus semantic segmentation adapter for the Chijing worker."""

from __future__ import annotations

import math
import time
from pathlib import Path
from typing import Any

import cv2
import numpy as np
import segmentation_models_pytorch as smp
import torch


MEAN = np.array([0.485, 0.456, 0.406], dtype=np.float32)
STD = np.array([0.229, 0.224, 0.225], dtype=np.float32)


def _torch_device(configured: str) -> torch.device:
    value = configured.strip().lower()
    if value == "cpu" or not torch.cuda.is_available():
        return torch.device("cpu")
    if value.isdigit():
        return torch.device(f"cuda:{value}")
    return torch.device(value if value.startswith("cuda") else "cuda:0")


def _make_model(architecture: str, encoder: str, auxiliary: bool) -> torch.nn.Module:
    options: dict[str, Any] = {
        "encoder_name": encoder,
        "encoder_weights": None,
        "in_channels": 3,
        "classes": 1,
    }
    if auxiliary:
        options["aux_params"] = {
            "classes": 1,
            "pooling": "avg",
            "dropout": 0.20,
            "activation": None,
        }
    if architecture == "deeplabv3plus":
        return smp.DeepLabV3Plus(**options)
    if architecture == "segformer":
        return smp.Segformer(**options)
    raise ValueError(f"不支持的牙结石分割结构：{architecture}")


def _letterbox(image_bgr: np.ndarray, size: int) -> tuple[torch.Tensor, tuple[int, int, int, int]]:
    image = cv2.cvtColor(image_bgr, cv2.COLOR_BGR2RGB)
    height, width = image.shape[:2]
    scale = size / max(height, width)
    resized_width = max(1, int(round(width * scale)))
    resized_height = max(1, int(round(height * scale)))
    resized = cv2.resize(image, (resized_width, resized_height), interpolation=cv2.INTER_LINEAR)
    top = (size - resized_height) // 2
    left = (size - resized_width) // 2
    canvas = np.zeros((size, size, 3), dtype=np.uint8)
    canvas[top:top + resized_height, left:left + resized_width] = resized
    normalized = (canvas.astype(np.float32) / 255.0 - MEAN) / STD
    tensor = torch.from_numpy(normalized.transpose(2, 0, 1)).unsqueeze(0)
    return tensor, (top, left, resized_height, resized_width)


def _remove_small_components(binary: np.ndarray, minimum_area: int) -> np.ndarray:
    count, labels, stats, _ = cv2.connectedComponentsWithStats(binary.astype(np.uint8), 8)
    output = np.zeros_like(binary, dtype=np.uint8)
    for label in range(1, count):
        if int(stats[label, cv2.CC_STAT_AREA]) >= minimum_area:
            output[labels == label] = 1
    return output


def _polygon(contour: np.ndarray) -> list[list[float]]:
    points = contour.reshape(-1, 2)
    if len(points) > 96:
        points = points[::math.ceil(len(points) / 96)]
    return [[round(float(x), 1), round(float(y), 1)] for x, y in points]


class CalculusSegmentationModel:
    """Loads the frozen v5 ensemble and returns the common Chijing result schema."""

    def __init__(self, weights: Path, configured_device: str) -> None:
        if not weights.is_file():
            raise FileNotFoundError(f"牙结石模型权重不存在：{weights}")
        self.device = _torch_device(configured_device)
        checkpoint = torch.load(weights, map_location="cpu", weights_only=False)
        if checkpoint.get("model_type") != "ensemble":
            raise ValueError("牙结石权重不是预期的 v5 融合模型。")
        self.inference = checkpoint["inference"]
        self.models: dict[str, torch.nn.Module] = {}
        self.settings: dict[str, dict[str, Any]] = {}
        for name, model_data in checkpoint["models"].items():
            settings = model_data["settings"]
            model = _make_model(
                str(settings["arch"]),
                str(settings["encoder"]),
                bool(model_data.get("uses_auxiliary_classification", True)),
            )
            model.load_state_dict(model_data["model_state"], strict=True)
            self.models[str(name)] = model.to(self.device).eval()
            self.settings[str(name)] = settings

    def _probability(self, model: torch.nn.Module, tensor: torch.Tensor) -> torch.Tensor:
        output = model(tensor)
        logits = output[0] if isinstance(output, (tuple, list)) else output
        probability = torch.sigmoid(logits)
        if bool(self.inference.get("tta", True)):
            flipped = torch.flip(tensor, dims=[3])
            flipped_output = model(flipped)
            flipped_logits = (
                flipped_output[0]
                if isinstance(flipped_output, (tuple, list))
                else flipped_output
            )
            probability = (
                probability + torch.flip(torch.sigmoid(flipped_logits), dims=[3])
            ) / 2
        return probability

    @torch.inference_mode()
    def infer(self, image_path: Path) -> dict[str, Any]:
        encoded = np.fromfile(image_path, dtype=np.uint8)
        image = cv2.imdecode(encoded, cv2.IMREAD_COLOR)
        if image is None:
            raise RuntimeError("牙结石模型无法读取待分析图片。")
        started = time.perf_counter()
        target_size = int(self.inference["resolution"])
        _, geometry = _letterbox(image, target_size)
        probability_square = np.zeros((target_size, target_size), dtype=np.float32)
        with torch.amp.autocast(
            device_type=self.device.type,
            enabled=self.device.type == "cuda",
        ):
            for name, model in self.models.items():
                size = int(self.settings[name]["image_size"])
                tensor, _ = _letterbox(image, size)
                probability = self._probability(model, tensor.to(self.device))
                current = probability[0, 0].float().cpu().numpy()
                if size != target_size:
                    current = cv2.resize(
                        current,
                        (target_size, target_size),
                        interpolation=cv2.INTER_LINEAR,
                    )
                probability_square += float(self.inference["weights"][name]) * current

        binary_square = _remove_small_components(
            probability_square >= float(self.inference["threshold"]),
            int(self.inference["min_area"]),
        )
        top, left, resized_height, resized_width = geometry
        probability_crop = probability_square[
            top:top + resized_height,
            left:left + resized_width,
        ]
        binary_crop = binary_square[
            top:top + resized_height,
            left:left + resized_width,
        ]
        image_height, image_width = image.shape[:2]
        probability = cv2.resize(
            probability_crop,
            (image_width, image_height),
            interpolation=cv2.INTER_LINEAR,
        )
        mask = cv2.resize(
            binary_crop,
            (image_width, image_height),
            interpolation=cv2.INTER_NEAREST,
        ).astype(np.uint8)

        count, labels, stats, _ = cv2.connectedComponentsWithStats(mask, 8)
        findings: list[dict[str, Any]] = []
        for component in range(1, count):
            component_mask = labels == component
            area = int(stats[component, cv2.CC_STAT_AREA])
            x = int(stats[component, cv2.CC_STAT_LEFT])
            y = int(stats[component, cv2.CC_STAT_TOP])
            width = int(stats[component, cv2.CC_STAT_WIDTH])
            height = int(stats[component, cv2.CC_STAT_HEIGHT])
            confidence = float(probability[component_mask].mean()) if area else 0.0
            contour_mask = component_mask.astype(np.uint8)
            contours, _ = cv2.findContours(
                contour_mask,
                cv2.RETR_EXTERNAL,
                cv2.CHAIN_APPROX_SIMPLE,
            )
            contour = max(contours, key=cv2.contourArea) if contours else np.empty((0, 1, 2))
            finding: dict[str, Any] = {
                "model": "calculus_seg_ensemble_v5",
                "label": "Calculus",
                "confidence": round(confidence, 4),
                "bbox_xyxy": [float(x), float(y), float(x + width), float(y + height)],
                "area_pixels": area,
                "area_ratio": round(area / max(1, image_width * image_height), 6),
            }
            polygon = _polygon(contour) if len(contour) >= 3 else []
            if len(polygon) >= 3:
                finding["polygon"] = polygon
            findings.append(finding)

        findings.sort(key=lambda item: int(item["area_pixels"]), reverse=True)
        detected_pixels = int(mask.sum())
        coverage = detected_pixels / max(1, image_width * image_height)
        elapsed_ms = (time.perf_counter() - started) * 1000
        device_name = (
            torch.cuda.get_device_name(self.device)
            if self.device.type == "cuda"
            else "CPU"
        )
        count_text = len(findings)
        summary = (
            f"牙结石候选区域 {count_text} 个，预测区域约占图片 {coverage * 100:.3f}%。"
            "结果仅用于口腔照片辅助筛查，需由口腔专业人员复核。"
        )
        average_confidence = (
            sum(float(item["confidence"]) for item in findings) / count_text
            if count_text
            else 0.0
        )
        maximum_confidence = (
            max(float(item["confidence"]) for item in findings)
            if findings
            else 0.0
        )
        return {
            "summary_text": summary,
            "risk_level": "unknown",
            "raw_result_json": {
                "pipeline": "calculus_seg_ensemble_v5",
                "models": [{
                    "name": "calculus_seg_ensemble_v5",
                    "weight": weights_name(self.inference),
                    "task": "semantic_segment",
                    "resolution": target_size,
                    "threshold": float(self.inference["threshold"]),
                    "minimum_component_area": int(self.inference["min_area"]),
                    "tta_horizontal_flip": bool(self.inference.get("tta", True)),
                    "classes": {"Calculus": "牙结石候选"},
                }],
                "counts": {"Calculus": count_text},
                "class_stats": {
                    "Calculus": {
                        "count": count_text,
                        "average_confidence": round(average_confidence, 4),
                        "maximum_confidence": round(maximum_confidence, 4),
                    },
                },
                "mask_statistics": {
                    "positive_pixels": detected_pixels,
                    "coverage_ratio": round(coverage, 6),
                },
                "runtime": {
                    "device": device_name,
                    "image_width": image_width,
                    "image_height": image_height,
                    "inference_ms": round(elapsed_ms, 2),
                },
                "findings": findings,
            },
        }


def weights_name(inference: dict[str, Any]) -> str:
    """Stable display name without serializing checkpoint internals."""
    return "calculus_seg_ensemble_v5.pt"
