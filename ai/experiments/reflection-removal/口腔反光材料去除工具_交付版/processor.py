"""Reflective dental-frame detection and safe removal.

The detector deliberately uses classical image processing only.  It finds
bright, low-to-medium-saturation cyan/white pixels and keeps components that
touch the image boundary.  This prevents bright teeth in the centre from
being classified as the surrounding reflector.
"""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

import cv2
import numpy as np


@dataclass(frozen=True)
class Settings:
    brightness: int = 145
    max_saturation: int = 155
    blue_bias: int = -12
    close_size: int = 19
    safety_padding: int = 28
    retention_percent: int = 55
    minimum_area_ratio: float = 0.001


@dataclass
class ProcessResult:
    original: np.ndarray
    candidate: np.ndarray
    mask: np.ndarray
    overlay: np.ndarray
    cropped: np.ndarray
    safe_cropped: np.ndarray
    transparent: np.ndarray
    crop_rect: tuple[int, int, int, int]
    safe_crop_rect: tuple[int, int, int, int]
    coverage: float
    profile: str


def read_image(path: str | Path) -> np.ndarray:
    """Read paths containing Chinese characters on Windows."""
    data = np.fromfile(str(path), dtype=np.uint8)
    image = cv2.imdecode(data, cv2.IMREAD_COLOR)
    if image is None:
        raise ValueError(f"无法读取图片：{path}")
    return image


def write_image(path: str | Path, image: np.ndarray, quality: int = 95) -> None:
    """Write paths containing Chinese characters on Windows."""
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    suffix = path.suffix.lower() or ".png"
    params = [cv2.IMWRITE_JPEG_QUALITY, quality] if suffix in {".jpg", ".jpeg"} else []
    ok, encoded = cv2.imencode(suffix, image, params)
    if not ok:
        raise ValueError(f"无法编码图片：{path}")
    encoded.tofile(str(path))


def _odd(value: int) -> int:
    value = max(3, int(value))
    return value if value % 2 else value + 1


def _border_components(
    binary: np.ndarray, min_area: int, *, fill_shapes: bool = True
) -> np.ndarray:
    """Keep meaningful components connected to the outer image boundary."""
    count, labels, stats, _ = cv2.connectedComponentsWithStats(binary, 8)
    h, w = binary.shape
    selected = np.zeros_like(binary)
    border = max(3, int(round(min(h, w) * 0.012)))

    for label in range(1, count):
        x, y, cw, ch, area = stats[label]
        touches = x <= border or y <= border or x + cw >= w - border or y + ch >= h - border
        if touches and area >= min_area:
            selected[labels == label] = 255

    if not fill_shapes:
        return selected

    # Large reflectors are separate wedges, so filling their outer contours
    # repairs texture holes. Thin rings can nearly enclose the whole image and
    # must skip this step or their central aperture would also be filled.
    contours, _ = cv2.findContours(selected, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    filled = np.zeros_like(selected)
    if contours:
        cv2.drawContours(filled, contours, -1, 255, thickness=cv2.FILLED)
    return filled


def _absorb_large_reflector_fringe(
    mask: np.ndarray,
    blue: np.ndarray,
    red: np.ndarray,
    value: np.ndarray,
    padding: int,
) -> np.ndarray:
    """Absorb broad dim-cyan transitions used by the large-reflector profile."""
    h, w = mask.shape
    fringe_radius = min(120, max(24, padding * 3))
    fringe_kernel = cv2.getStructuringElement(
        cv2.MORPH_ELLIPSE, (fringe_radius * 2 + 1, fringe_radius * 2 + 1)
    )
    near_reflector = cv2.dilate(mask, fringe_kernel) > 0
    blue_delta = blue.astype(np.int16) - red.astype(np.int16)
    yy, xx = np.ogrid[:h, :w]
    outer_band = (
        (xx < int(w * 0.22))
        | (xx >= int(w * 0.78))
        | (yy < int(h * 0.22))
        | (yy >= int(h * 0.78))
    )
    dim_cyan_fringe = (value >= 72) & (blue_delta >= 10) & near_reflector & outer_band
    fringe = np.where(dim_cyan_fringe, 255, 0).astype(np.uint8)
    fringe = cv2.morphologyEx(
        fringe,
        cv2.MORPH_CLOSE,
        cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (11, 11)),
    )

    component_count, fringe_labels, fringe_stats, _ = cv2.connectedComponentsWithStats(
        fringe, 8
    )
    filtered_fringe = np.zeros_like(fringe)
    minimum_fringe_area = max(80, int(h * w * 0.0001))
    for label in range(1, component_count):
        if fringe_stats[label, cv2.CC_STAT_AREA] >= minimum_fringe_area:
            filtered_fringe[fringe_labels == label] = 255

    combined = cv2.bitwise_or(mask, filtered_fringe)
    return cv2.dilate(
        combined, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (7, 7))
    )


def _largest_clear_rectangle(blocked: np.ndarray) -> tuple[int, int, int, int]:
    """Largest all-clear axis-aligned rectangle using a histogram stack."""
    clear = blocked == 0
    h, w = clear.shape
    heights = np.zeros(w, dtype=np.int32)
    best_area = 0
    best = (0, 0, w, h)

    for row in range(h):
        heights = np.where(clear[row], heights + 1, 0)
        stack: list[tuple[int, int]] = []
        for col in range(w + 1):
            height = int(heights[col]) if col < w else 0
            start = col
            while stack and stack[-1][1] > height:
                index, old_height = stack.pop()
                area = old_height * (col - index)
                if area > best_area:
                    best_area = area
                    best = (index, row - old_height + 1, col, row + 1)
                start = index
            if not stack or stack[-1][1] < height:
                stack.append((start, height))

    x1, y1, x2, y2 = best
    # Avoid pathological one-pixel-wide results when parameters are extreme.
    if best_area < h * w * 0.04 or x2 - x1 < 80 or y2 - y1 < 80:
        margin_x, margin_y = int(w * 0.2), int(h * 0.2)
        return margin_x, margin_y, w - margin_x, h - margin_y
    return best


def process_image(image: np.ndarray, settings: Settings = Settings()) -> ProcessResult:
    b, g, r = cv2.split(image)
    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)
    saturation = hsv[:, :, 1]
    value = hsv[:, :, 2]
    h, w = value.shape

    # Large reflectors dominate most of the image border. A thin aperture only
    # occupies parts of that border, leaving a much lower median brightness.
    edge_width = max(8, int(round(min(h, w) * 0.04)))
    edge_pixels = np.concatenate(
        (
            value[:edge_width, :].ravel(),
            value[-edge_width:, :].ravel(),
            value[edge_width:-edge_width, :edge_width].ravel(),
            value[edge_width:-edge_width, -edge_width:].ravel(),
        )
    )
    profile = "large" if float(np.median(edge_pixels)) >= 220.0 else "thin"

    if profile == "thin":
        # Thin apertures are clipped/overexposed white. A strict threshold keeps
        # skin, lips and teeth out even if those regions touch the image edge.
        thin_brightness = int(np.clip(settings.brightness + 100, 238, 250))
        thin_saturation = min(120, int(settings.max_saturation))
        candidate_bool = (value >= thin_brightness) & (saturation <= thin_saturation)
        close_k = _odd(min(9, settings.close_size))
        open_k = 3
        minimum_area = int(h * w * max(0.002, settings.minimum_area_ratio))
        fill_shapes = False
    else:
        # Large reflectors are cyan-white and can contain broad dim transitions.
        cyan_white = (
            (value >= settings.brightness)
            & (saturation <= settings.max_saturation)
            & (b.astype(np.int16) - r.astype(np.int16) >= settings.blue_bias)
        )
        neutral_hot = (value >= min(245, settings.brightness + 70)) & (saturation <= 55)
        candidate_bool = cyan_white | neutral_hot
        close_k = _odd(settings.close_size)
        open_k = 5
        minimum_area = int(h * w * settings.minimum_area_ratio)
        fill_shapes = True

    candidate = np.where(candidate_bool, 255, 0).astype(np.uint8)
    close_kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (close_k, close_k))
    candidate_closed = cv2.morphologyEx(candidate, cv2.MORPH_CLOSE, close_kernel)
    candidate_closed = cv2.morphologyEx(
        candidate_closed,
        cv2.MORPH_OPEN,
        cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (open_k, open_k)),
    )

    mask = _border_components(candidate_closed, minimum_area, fill_shapes=fill_shapes)

    if profile == "thin":
        # A thin reflector can break into pieces smaller than the component
        # threshold. Keep clipped-white pixels in the outermost strip even
        # when they are short fragments; central lip/teeth highlights remain
        # outside this strip and therefore stay untouched.
        edge_strip = max(4, int(round(min(h, w) * 0.01)))
        side_span = int(round(w * 0.36))
        edge_cleanup = np.zeros_like(mask)
        edge_cleanup[:, :edge_strip] = candidate_closed[:, :edge_strip]
        edge_cleanup[:, -edge_strip:] = candidate_closed[:, -edge_strip:]
        edge_cleanup[:edge_strip, :side_span] = candidate_closed[:edge_strip, :side_span]
        edge_cleanup[:edge_strip, -side_span:] = candidate_closed[:edge_strip, -side_span:]
        edge_cleanup[-edge_strip:, :side_span] = candidate_closed[-edge_strip:, :side_span]
        edge_cleanup[-edge_strip:, -side_span:] = candidate_closed[-edge_strip:, -side_span:]
        mask = cv2.bitwise_or(mask, edge_cleanup)

    # The material edge contains dim, semi-transparent transition pixels that
    # do not pass the white threshold. Expand the actual removal mask so these
    # residual pale fringes are painted black as well.
    requested_padding = max(0, int(settings.safety_padding))
    if profile == "thin" and requested_padding:
        # A smooth 20-ish pixel expansion clears the last clipped-white rim at
        # 1920x1080, while the cap prevents a UI setting intended for a broad
        # reflector from eating into teeth in the thin-aperture profile.
        padding = min(22, max(4, int(round(requested_padding * 0.75))))
    else:
        padding = requested_padding
    if padding:
        kernel_size = padding * 2 + 1
        mask = cv2.dilate(
            mask,
            cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (kernel_size, kernel_size)),
        )

        if profile == "large":
            mask = _absorb_large_reflector_fringe(mask, b, r, value, padding)
    mask_for_crop = mask

    safe_rect = _largest_clear_rectangle(mask_for_crop)
    sx1, sy1, sx2, sy2 = safe_rect
    safe_cropped = image[sy1:sy2, sx1:sx2].copy()

    # Expand the strict no-reflector rectangle back towards the original image.
    # Any reflector reintroduced by this expansion is painted black below. This
    # preserves substantially more teeth without inventing clinical content.
    requested_retention = int(np.clip(settings.retention_percent, 0, 100))
    effective_retention = max(90, requested_retention) if profile == "thin" else requested_retention
    retention = float(effective_retention) / 100.0
    x1 = max(0, int(round(sx1 * (1.0 - retention))))
    y1 = max(0, int(round(sy1 * (1.0 - retention))))
    x2 = min(w, int(round(sx2 + (w - sx2) * retention)))
    y2 = min(h, int(round(sy2 + (h - sy2) * retention)))
    rect = (x1, y1, x2, y2)
    cropped = image[y1:y2, x1:x2].copy()
    cropped_mask = mask[y1:y2, x1:x2] > 0
    cropped[cropped_mask] = (0, 0, 0)

    # Same-size alternative with alpha=0 over the detected reflector.
    alpha = 255 - mask
    transparent = cv2.cvtColor(image, cv2.COLOR_BGR2BGRA)
    transparent[:, :, 3] = alpha

    overlay = image.copy()
    tint = np.zeros_like(image)
    tint[:] = (222, 214, 35)  # cyan in BGR
    selected_pixels = mask > 0
    if np.any(selected_pixels):
        overlay[selected_pixels] = cv2.addWeighted(
            image[selected_pixels], 0.32, tint[selected_pixels], 0.68, 0
        )
    contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    cv2.drawContours(overlay, contours, -1, (255, 244, 79), 3)
    # Thin yellow: strict safe crop. Thick green: content-retaining black-fill crop.
    cv2.rectangle(overlay, (sx1, sy1), (max(sx1, sx2 - 1), max(sy1, sy2 - 1)), (60, 205, 255), 2)
    cv2.rectangle(overlay, (x1, y1), (max(x1, x2 - 1), max(y1, y2 - 1)), (75, 247, 166), 4)

    return ProcessResult(
        original=image,
        candidate=candidate,
        mask=mask,
        overlay=overlay,
        cropped=cropped,
        safe_cropped=safe_cropped,
        transparent=transparent,
        crop_rect=rect,
        safe_crop_rect=safe_rect,
        coverage=float(np.count_nonzero(mask)) / float(h * w),
        profile=profile,
    )


def process_file(path: str | Path, settings: Settings = Settings()) -> ProcessResult:
    return process_image(read_image(path), settings)
