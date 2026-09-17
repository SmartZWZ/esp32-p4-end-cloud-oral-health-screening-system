from __future__ import annotations

from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any

import cv2
import numpy as np
import torch


@dataclass
class AnalysisParams:
    edge_shrink_pct: float = 6.0
    darkness_threshold: float = 0.602
    black_level_pct: float = 35.0
    min_contrast_pct: float = 5.0
    min_width_px: float = 0.5
    min_length_pct: float = 18.0
    smooth_px: int = 5
    exclude_highlights: bool = True


@dataclass
class ToothInstance:
    index: int
    confidence: float
    mask: np.ndarray
    bbox: tuple[int, int, int, int]
    centroid: tuple[float, float]
    contour: np.ndarray


@dataclass
class CandidateLine:
    index: int
    kind: str
    structure_score: float
    area_px: int
    skeleton_length_px: int
    length_ratio: float
    mean_dark_response: float
    mean_width_px: float
    median_width_px: float
    width_pass_ratio: float
    black_core_ratio: float
    contrast_core_ratio: float
    evidence_core_ratio: float
    evidence_mode: str
    core_lightness_pct: float
    local_contrast_pct: float
    elongation: float
    solidity: float
    endpoints: list[tuple[int, int]] = field(default_factory=list)
    branchpoints: list[tuple[int, int]] = field(default_factory=list)
    skeleton_xy: list[tuple[int, int]] = field(default_factory=list)
    contour_xy: list[tuple[int, int]] = field(default_factory=list)


@dataclass
class ToothAnalysis:
    tooth_index: int
    crop_bounds: tuple[int, int, int, int]
    candidates: list[CandidateLine]
    views: dict[str, np.ndarray]
    response: np.ndarray
    binary: np.ndarray
    skeleton: np.ndarray
    inner_mask: np.ndarray


def read_image(path: str | Path) -> np.ndarray:
    data = np.fromfile(str(path), dtype=np.uint8)
    image = cv2.imdecode(data, cv2.IMREAD_COLOR)
    if image is None:
        raise ValueError(f"无法读取图片：{path}")
    return image


def write_image(path: str | Path, image: np.ndarray) -> None:
    path = Path(path)
    ext = path.suffix or ".png"
    ok, encoded = cv2.imencode(ext, image)
    if not ok:
        raise ValueError(f"无法编码图片：{path}")
    encoded.tofile(str(path))


def detect_teeth(model: Any, image_bgr: np.ndarray, conf: float = 0.25) -> list[ToothInstance]:
    results = model.predict(
        source=image_bgr,
        imgsz=1024,
        conf=conf,
        iou=0.65,
        max_det=64,
        retina_masks=True,
        device=0 if torch.cuda.is_available() else "cpu",
        verbose=False,
    )
    result = results[0]
    if result.masks is None or result.boxes is None:
        return []

    height, width = image_bgr.shape[:2]
    raw_masks = result.masks.data.detach().cpu().numpy()
    confidences = result.boxes.conf.detach().cpu().numpy()
    instances: list[ToothInstance] = []
    for raw_mask, confidence in zip(raw_masks, confidences):
        if raw_mask.shape != (height, width):
            raw_mask = cv2.resize(raw_mask, (width, height), interpolation=cv2.INTER_LINEAR)
        mask = (raw_mask >= 0.5).astype(np.uint8)
        contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        if not contours:
            continue
        contour = max(contours, key=cv2.contourArea)
        if cv2.contourArea(contour) < 30:
            continue
        x, y, w, h = cv2.boundingRect(contour)
        moments = cv2.moments(contour)
        if moments["m00"]:
            cx = moments["m10"] / moments["m00"]
            cy = moments["m01"] / moments["m00"]
        else:
            cx, cy = x + w / 2, y + h / 2
        instances.append(
            ToothInstance(
                index=0,
                confidence=float(confidence),
                mask=mask,
                bbox=(x, y, x + w, y + h),
                centroid=(cx, cy),
                contour=contour,
            )
        )

    # Read teeth in rows, then from left to right. This is navigation order, not FDI numbering.
    instances.sort(key=lambda item: (round(item.centroid[1] / max(height * 0.12, 1)), item.centroid[0]))
    for index, instance in enumerate(instances, start=1):
        instance.index = index
    return instances


def _odd(value: int, minimum: int = 3, maximum: int | None = None) -> int:
    value = max(minimum, value)
    if maximum is not None:
        value = min(maximum, value)
    return value if value % 2 else value + 1


def _robust_normalize(image: np.ndarray, mask: np.ndarray) -> np.ndarray:
    values = image[mask > 0]
    if values.size == 0:
        return np.zeros_like(image, dtype=np.float32)
    low, high = np.percentile(values, [5, 99])
    if high <= low + 1e-6:
        return np.zeros_like(image, dtype=np.float32)
    return np.clip((image.astype(np.float32) - low) / (high - low), 0.0, 1.0)


def _hessian_ridge(dark_signal: np.ndarray, mask: np.ndarray, min_dim: int) -> np.ndarray:
    best = np.zeros_like(dark_signal, dtype=np.float32)
    sigmas = sorted({max(0.8, min(5.0, min_dim * ratio)) for ratio in (0.006, 0.012, 0.022)})
    for sigma in sigmas:
        blurred = cv2.GaussianBlur(dark_signal.astype(np.float32), (0, 0), sigma)
        scale = float(sigma * sigma)
        dxx = cv2.Sobel(blurred, cv2.CV_32F, 2, 0, ksize=3) * scale
        dyy = cv2.Sobel(blurred, cv2.CV_32F, 0, 2, ksize=3) * scale
        dxy = cv2.Sobel(blurred, cv2.CV_32F, 1, 1, ksize=3) * scale
        trace = dxx + dyy
        delta = np.sqrt(np.maximum((dxx - dyy) ** 2 + 4.0 * dxy**2, 0.0))
        eig_a = 0.5 * (trace + delta)
        eig_b = 0.5 * (trace - delta)
        swap = np.abs(eig_a) > np.abs(eig_b)
        lambda1 = np.where(swap, eig_b, eig_a)
        lambda2 = np.where(swap, eig_a, eig_b)
        rb = np.abs(lambda1) / (np.abs(lambda2) + 1e-6)
        energy = np.sqrt(lambda1**2 + lambda2**2)
        valid_energy = energy[mask > 0]
        c = float(np.percentile(valid_energy, 90)) if valid_energy.size else 1.0
        c = max(c, 1e-4)
        vesselness = np.exp(-(rb**2) / (2 * 0.55**2)) * (1 - np.exp(-(energy**2) / (2 * c**2)))
        # The inverted darkness signal is bright on a line, so the transverse curvature is negative.
        vesselness[lambda2 >= 0] = 0
        best = np.maximum(best, vesselness.astype(np.float32))
    return _robust_normalize(best, mask)


def _morphological_skeleton(binary: np.ndarray) -> np.ndarray:
    """Return a true one-pixel Zhang-Suen skeleton without OpenCV-contrib."""
    image = (binary > 0).astype(np.uint8)
    if image.size == 0:
        return image
    max_iterations = max(image.shape) * 2
    for _ in range(max_iterations):
        changed = False
        for phase in (0, 1):
            padded = np.pad(image, 1, mode="constant")
            p2 = padded[:-2, 1:-1]
            p3 = padded[:-2, 2:]
            p4 = padded[1:-1, 2:]
            p5 = padded[2:, 2:]
            p6 = padded[2:, 1:-1]
            p7 = padded[2:, :-2]
            p8 = padded[1:-1, :-2]
            p9 = padded[:-2, :-2]
            neighbours = (p2 + p3 + p4 + p5 + p6 + p7 + p8 + p9)
            transitions = (
                ((p2 == 0) & (p3 == 1)).astype(np.uint8)
                + ((p3 == 0) & (p4 == 1)).astype(np.uint8)
                + ((p4 == 0) & (p5 == 1)).astype(np.uint8)
                + ((p5 == 0) & (p6 == 1)).astype(np.uint8)
                + ((p6 == 0) & (p7 == 1)).astype(np.uint8)
                + ((p7 == 0) & (p8 == 1)).astype(np.uint8)
                + ((p8 == 0) & (p9 == 1)).astype(np.uint8)
                + ((p9 == 0) & (p2 == 1)).astype(np.uint8)
            )
            if phase == 0:
                geometry = ((p2 * p4 * p6) == 0) & ((p4 * p6 * p8) == 0)
            else:
                geometry = ((p2 * p4 * p8) == 0) & ((p2 * p6 * p8) == 0)
            remove = (image == 1) & (neighbours >= 2) & (neighbours <= 6) & (transitions == 1) & geometry
            if np.any(remove):
                image[remove] = 0
                changed = True
        if not changed:
            break
    return image


def _cluster_points(point_mask: np.ndarray, merge_radius: int = 1) -> list[tuple[int, int]]:
    source = point_mask.astype(np.uint8)
    if not np.any(source):
        return []
    if merge_radius > 1:
        size = merge_radius * 2 + 1
        grouped = cv2.dilate(source, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (size, size)))
    else:
        grouped = source
    count, labels, stats, _ = cv2.connectedComponentsWithStats(grouped, 8)
    points: list[tuple[int, int]] = []
    for label in range(1, count):
        if stats[label, cv2.CC_STAT_AREA] <= 0:
            continue
        ys, xs = np.where((labels == label) & (source > 0))
        if xs.size:
            points.append((int(round(float(xs.mean()))), int(round(float(ys.mean())))))
    return points


def _candidate_kind(endpoints: int, branchpoints: int) -> str:
    if branchpoints >= 1 and endpoints >= 3:
        return "Y型/分叉型"
    if branchpoints >= 2:
        return "网状"
    if endpoints >= 2:
        return "线型"
    return "环形/斑块"


def _line_widths(component: np.ndarray, skeleton: np.ndarray) -> np.ndarray:
    """Estimate full transverse width at every skeleton pixel.

    OpenCV's distance is measured from a foreground pixel centre to the nearest
    background pixel centre. Subtracting one pixel from the diameter makes a
    one-pixel digital hairline measure approximately one pixel instead of two.
    """
    distance = cv2.distanceTransform(component.astype(np.uint8), cv2.DIST_L2, 5)
    widths = 2.0 * distance[skeleton > 0] - 1.0
    return np.maximum(widths.astype(np.float32), 0.0)


def _component_appearance(
    lightness: np.ndarray,
    component: np.ndarray,
    skeleton: np.ndarray,
    inner_mask: np.ndarray,
    min_dim: int,
    black_level_pct: float,
    min_contrast_pct: float,
) -> tuple[float, float, float, float, np.ndarray]:
    """Return black-core ratio, core lightness and local line contrast.

    Measurements are made on the skeleton rather than the whole thresholded
    component, so anti-aliased borders do not dilute a genuinely black centre.
    The local baseline removes broad illumination/shadow changes.
    """
    sample = (skeleton > 0) & (inner_mask > 0)
    values = lightness[sample].astype(np.float32)
    if values.size == 0:
        return 0.0, 100.0, 0.0, 0.0, np.zeros_like(lightness, dtype=np.float32)

    black_limit = float(np.clip(black_level_pct, 1.0, 99.0) * 2.55)
    black_core_ratio = float(np.mean(values <= black_limit))
    core_lightness_pct = float(np.percentile(values, 25) / 2.55)

    # A broad dark patch stays close to its blurred local baseline, while a
    # narrow black fissure remains markedly darker than both sides.
    raw_l = lightness.astype(np.float32)
    # Two baselines cover narrow and moderately broad fissures. A true line is
    # consistently darker than its immediate enamel neighbourhood; a uniformly
    # shaded patch has little response except at its boundary.
    local_contrast = np.zeros_like(raw_l)
    for sigma in (max(1.5, min_dim * 0.018), max(3.0, min_dim * 0.055)):
        baseline = cv2.GaussianBlur(raw_l, (0, 0), sigma)
        local_contrast = np.maximum(local_contrast, np.maximum(baseline - raw_l, 0.0))
    local_contrast_pct = float(np.median(local_contrast[sample]) / 2.55)
    contrast_limit = float(max(min_contrast_pct, 0.1) * 2.55)
    contrast_core_ratio = float(np.mean(local_contrast[sample] >= contrast_limit))
    return black_core_ratio, core_lightness_pct, local_contrast_pct, contrast_core_ratio, local_contrast


def analyze_tooth(
    image_bgr: np.ndarray,
    tooth: ToothInstance,
    params: AnalysisParams,
) -> ToothAnalysis:
    image_h, image_w = image_bgr.shape[:2]
    bx0, by0, bx1, by1 = tooth.bbox
    tooth_w, tooth_h = bx1 - bx0, by1 - by0
    padding = max(8, int(max(tooth_w, tooth_h) * 0.10))
    x0, y0 = max(0, bx0 - padding), max(0, by0 - padding)
    x1, y1 = min(image_w, bx1 + padding), min(image_h, by1 + padding)
    crop = image_bgr[y0:y1, x0:x1].copy()
    mask = tooth.mask[y0:y1, x0:x1].astype(np.uint8)
    min_dim = max(12, min(tooth_w, tooth_h))

    erode_radius = max(1, int(min_dim * params.edge_shrink_pct / 100.0))
    erode_kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (_odd(erode_radius * 2 + 1),) * 2)
    inner_mask = cv2.erode(mask, erode_kernel)
    if cv2.countNonZero(inner_mask) < max(20, cv2.countNonZero(mask) * 0.2):
        inner_mask = mask.copy()

    lab = cv2.cvtColor(crop, cv2.COLOR_BGR2LAB)
    lightness = lab[:, :, 0]
    clahe = cv2.createCLAHE(clipLimit=1.8, tileGridSize=(8, 8))
    enhanced_l = clahe.apply(lightness)

    illumination_sigma = max(3.0, min_dim * 0.10)
    illumination = cv2.GaussianBlur(enhanced_l.astype(np.float32), (0, 0), illumination_sigma)
    local_dark = np.maximum(illumination - enhanced_l.astype(np.float32), 0.0)

    blackhat = np.zeros_like(enhanced_l, dtype=np.float32)
    for ratio in (0.035, 0.065, 0.11):
        kernel_size = _odd(int(min_dim * ratio), minimum=5, maximum=41)
        kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (kernel_size, kernel_size))
        closed = cv2.morphologyEx(enhanced_l, cv2.MORPH_CLOSE, kernel)
        blackhat = np.maximum(blackhat, closed.astype(np.float32) - enhanced_l.astype(np.float32))

    blackhat_n = _robust_normalize(blackhat + 0.35 * local_dark, inner_mask)
    ridge = _hessian_ridge(blackhat_n, inner_mask, min_dim)
    response = np.clip(0.72 * blackhat_n + 0.28 * ridge, 0.0, 1.0)

    hsv = cv2.cvtColor(crop, cv2.COLOR_BGR2HSV)
    # Wet enamel highlights are often slightly yellow/blue rather than perfectly white.
    # Mask a broader bright, weakly-saturated core and dilate across its dark halo;
    # otherwise the halo is easily mistaken for a fissure by a black-hat filter.
    highlight_mask = ((hsv[:, :, 2] > 232) & (hsv[:, :, 1] < 125)).astype(np.uint8)
    highlight_size = _odd(int(min_dim * 0.045), minimum=5, maximum=11)
    highlight_kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (highlight_size, highlight_size))
    highlight_mask = cv2.dilate(highlight_mask, highlight_kernel, iterations=1)
    response[inner_mask == 0] = 0
    if params.exclude_highlights:
        response[highlight_mask > 0] = 0

    smooth = _odd(int(params.smooth_px), minimum=1, maximum=9)
    if smooth > 1:
        response = cv2.GaussianBlur(response, (smooth, smooth), 0)
    response[inner_mask == 0] = 0

    binary = (response >= params.darkness_threshold).astype(np.uint8)
    binary = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, np.ones((3, 3), np.uint8), iterations=1)
    binary[inner_mask == 0] = 0
    if params.exclude_highlights:
        binary[highlight_mask > 0] = 0

    count, labels, stats, _ = cv2.connectedComponentsWithStats(binary, 8)
    filtered = np.zeros_like(binary)
    candidates: list[CandidateLine] = []
    min_length = max(5, int(tooth_w * params.min_length_pct / 100.0))
    inner_area = max(1, cv2.countNonZero(inner_mask))
    # V3 uses two alternative appearance channels: absolute blackness OR stable
    # local contrast. This keeps pale early-fissure candidates while retaining
    # geometric rejection of shadows, blobs and one-pixel texture.
    min_absolute_black_ratio = 0.12
    min_contrast_core_ratio = 0.30
    min_evidence_core_ratio = 0.30
    min_width_pass_ratio = 0.50

    for label in range(1, count):
        component = (labels == label).astype(np.uint8)
        area = int(stats[label, cv2.CC_STAT_AREA])
        if area < 5 or area > inner_area * 0.30:
            continue
        component_skeleton = _morphological_skeleton(component)
        length = int(cv2.countNonZero(component_skeleton))
        if length < min_length:
            continue
        mean_width = area / max(length, 1)

        black_core_ratio, core_lightness_pct, local_contrast_pct, contrast_core_ratio, contrast_map = (
            _component_appearance(
                lightness,
                component,
                component_skeleton,
                inner_mask,
                min_dim,
                params.black_level_pct,
                params.min_contrast_pct,
            )
        )

        # Measure width on unsmoothed evidence pixels. Unlike V2, support can be
        # either absolutely black or locally darker than the neighbouring enamel.
        # Gaussian response smoothing therefore cannot inflate a 1 px hairline.
        black_limit = float(np.clip(params.black_level_pct, 1.0, 99.0) * 2.55)
        contrast_limit = float(max(params.min_contrast_pct, 0.1) * 2.55)
        evidence_support = (
            (component > 0) & ((lightness <= black_limit) | (contrast_map >= contrast_limit))
        ).astype(np.uint8)
        evidence_core_ratio = float(np.mean(evidence_support[component_skeleton > 0] > 0))
        evidence_skeleton = _morphological_skeleton(evidence_support)
        widths = _line_widths(evidence_support, evidence_skeleton)
        median_width = float(np.median(widths)) if widths.size else 0.0
        width_pass_ratio = float(np.mean(widths >= params.min_width_px)) if widths.size else 0.0
        if median_width > min_dim * 0.12:
            continue
        if median_width < params.min_width_px or width_pass_ratio < min_width_pass_ratio:
            continue

        if evidence_core_ratio < min_evidence_core_ratio:
            continue
        if black_core_ratio < min_absolute_black_ratio and contrast_core_ratio < min_contrast_core_ratio:
            continue

        support_contours, _ = cv2.findContours(evidence_support, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        if not support_contours:
            continue
        support_contour = max(support_contours, key=cv2.contourArea)
        rect_w, rect_h = cv2.minAreaRect(support_contour)[1]
        elongation = float(max(rect_w, rect_h) / max(min(rect_w, rect_h), 1e-6))
        hull_area = float(cv2.contourArea(cv2.convexHull(support_contour)))
        solidity = float(cv2.contourArea(support_contour) / max(hull_area, 1.0))
        # A filled round/oval dark patch is neither a line nor a Y-shaped
        # fissure. Real Y structures can have low elongation, but their arms
        # leave a characteristically low-solidity convex hull.
        if elongation < 2.2 and solidity > 0.72:
            continue

        if black_core_ratio >= min_absolute_black_ratio and contrast_core_ratio >= min_contrast_core_ratio:
            evidence_mode = "深色+局部反差"
        elif black_core_ratio >= min_absolute_black_ratio:
            evidence_mode = "深色"
        else:
            evidence_mode = "浅色局部反差"

        neighbor_kernel = np.ones((3, 3), dtype=np.uint8)
        neighbor_kernel[1, 1] = 0
        neighbor_count = cv2.filter2D(component_skeleton, cv2.CV_8U, neighbor_kernel)
        endpoint_mask = ((component_skeleton > 0) & (neighbor_count == 1)).astype(np.uint8)
        branch_mask = ((component_skeleton > 0) & (neighbor_count >= 3)).astype(np.uint8)
        endpoints = _cluster_points(endpoint_mask, merge_radius=1)
        # A digital skeleton usually contains several adjacent junction pixels.
        # Merge them into one anatomical branch location for display/export.
        branchpoints = _cluster_points(branch_mask, merge_radius=max(2, int(min_dim * 0.025)))

        ys, xs = np.where(component_skeleton > 0)
        skeleton_xy = [(int(x + x0), int(y + y0)) for y, x in zip(ys, xs)]
        component_contours, _ = cv2.findContours(component, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        contour = max(component_contours, key=cv2.contourArea) if component_contours else np.empty((0, 1, 2))
        contour_xy = [(int(p[0][0] + x0), int(p[0][1] + y0)) for p in contour]
        mean_response = float(response[component > 0].mean())
        length_ratio = float(length / max(tooth_w, 1))
        branch_bonus = min(len(branchpoints), 2) * 0.06
        appearance_score = max(
            min(black_core_ratio / 0.45, 1.0),
            min(contrast_core_ratio / 0.65, 1.0),
        )
        width_score = min(width_pass_ratio / 0.85, 1.0)
        structure_score = float(
            np.clip(
                0.38 * mean_response
                + 0.24 * min(length_ratio / 0.45, 1.0)
                + 0.20 * appearance_score
                + 0.12 * width_score
                + branch_bonus,
                0,
                1,
            )
        )
        kind = _candidate_kind(len(endpoints), len(branchpoints))
        candidates.append(
            CandidateLine(
                index=0,
                kind=kind,
                structure_score=structure_score,
                area_px=area,
                skeleton_length_px=length,
                length_ratio=length_ratio,
                mean_dark_response=mean_response,
                mean_width_px=float(mean_width),
                median_width_px=median_width,
                width_pass_ratio=width_pass_ratio,
                black_core_ratio=black_core_ratio,
                contrast_core_ratio=contrast_core_ratio,
                evidence_core_ratio=evidence_core_ratio,
                evidence_mode=evidence_mode,
                core_lightness_pct=core_lightness_pct,
                local_contrast_pct=local_contrast_pct,
                elongation=elongation,
                solidity=solidity,
                endpoints=[(px + x0, py + y0) for px, py in endpoints],
                branchpoints=[(px + x0, py + y0) for px, py in branchpoints],
                skeleton_xy=skeleton_xy,
                contour_xy=contour_xy,
            )
        )
        filtered[component > 0] = 1

    candidates.sort(key=lambda item: item.structure_score, reverse=True)
    for index, candidate in enumerate(candidates, start=1):
        candidate.index = index

    skeleton = _morphological_skeleton(filtered)
    tooth_view = crop.copy()
    tooth_view[mask == 0] = (20, 28, 32)
    cv2.drawContours(tooth_view, [tooth.contour - np.array([[[x0, y0]]])], -1, (232, 197, 63), 2)

    corrected = np.clip(enhanced_l.astype(np.float32) - illumination + 135.0, 0, 255).astype(np.uint8)
    normalized_view = cv2.cvtColor(corrected, cv2.COLOR_GRAY2BGR)
    normalized_view[mask == 0] = (20, 28, 32)

    heat_color = cv2.applyColorMap((response * 255).astype(np.uint8), cv2.COLORMAP_TURBO)
    heat_view = cv2.addWeighted(crop, 0.48, heat_color, 0.52, 0)
    heat_view[mask == 0] = (20, 28, 32)

    candidate_view = crop.copy()
    overlay = candidate_view.copy()
    overlay[filtered > 0] = (30, 177, 244)
    candidate_view = cv2.addWeighted(candidate_view, 0.62, overlay, 0.38, 0)
    candidate_view[mask == 0] = (20, 28, 32)

    skeleton_view = crop.copy()
    skeleton_view[mask == 0] = (20, 28, 32)
    sy, sx = np.where(skeleton > 0)
    skeleton_view[sy, sx] = (48, 190, 255)
    for candidate in candidates:
        for px, py in candidate.endpoints:
            cv2.circle(skeleton_view, (px - x0, py - y0), 3, (232, 197, 63), -1, cv2.LINE_AA)
        for px, py in candidate.branchpoints:
            cv2.circle(skeleton_view, (px - x0, py - y0), 5, (98, 85, 255), -1, cv2.LINE_AA)
            cv2.circle(skeleton_view, (px - x0, py - y0), 7, (255, 255, 255), 1, cv2.LINE_AA)

    views = {
        "tooth": tooth_view,
        "normalized": normalized_view,
        "heatmap": heat_view,
        "candidate": candidate_view,
        "skeleton": skeleton_view,
    }
    return ToothAnalysis(
        tooth_index=tooth.index,
        crop_bounds=(x0, y0, x1, y1),
        candidates=candidates,
        views=views,
        response=response,
        binary=filtered,
        skeleton=skeleton,
        inner_mask=inner_mask,
    )


def render_overview(
    image_bgr: np.ndarray,
    teeth: list[ToothInstance],
    selected_index: int | None = None,
    analysis: ToothAnalysis | None = None,
) -> np.ndarray:
    output = image_bgr.copy()
    for tooth in teeth:
        selected = tooth.index == selected_index
        color = (232, 197, 63) if selected else (215, 148, 55)
        thickness = 3 if selected else 2
        cv2.drawContours(output, [tooth.contour], -1, color, thickness, cv2.LINE_AA)
        x0, y0, _, _ = tooth.bbox
        label = f"T{tooth.index:02d} {tooth.confidence:.2f}"
        cv2.putText(output, label, (x0, max(20, y0 - 7)), cv2.FONT_HERSHEY_SIMPLEX, 0.55, color, 2, cv2.LINE_AA)

    if analysis is not None:
        for candidate in analysis.candidates:
            points = np.asarray(candidate.skeleton_xy, dtype=np.int32)
            if points.size:
                output[points[:, 1], points[:, 0]] = (48, 190, 255)
            for x, y in candidate.branchpoints:
                cv2.circle(output, (x, y), 7, (98, 85, 255), -1, cv2.LINE_AA)
                cv2.circle(output, (x, y), 9, (255, 255, 255), 1, cv2.LINE_AA)
    return output


def analysis_to_dict(
    image_path: str,
    model_path: str,
    tooth: ToothInstance,
    analysis: ToothAnalysis,
    params: AnalysisParams,
) -> dict[str, Any]:
    return {
        "notice": "研究候选结果，不是龋齿诊断。结构分数不是患龋概率。",
        "image_path": image_path,
        "model_path": model_path,
        "image_size_wh": [int(tooth.mask.shape[1]), int(tooth.mask.shape[0])],
        "coordinate_system": "原图像素坐标，左上角为 (0, 0)，x 向右、y 向下",
        "parameters": asdict(params),
        "tooth": {
            "navigation_id": tooth.index,
            "confidence": tooth.confidence,
            "bbox_xyxy": list(tooth.bbox),
            "centroid_xy": list(tooth.centroid),
            "contour_xy": [[int(p[0][0]), int(p[0][1])] for p in tooth.contour],
        },
        "crop_bounds_xyxy": list(analysis.crop_bounds),
        "candidate_count": len(analysis.candidates),
        "candidates": [asdict(candidate) for candidate in analysis.candidates],
    }
