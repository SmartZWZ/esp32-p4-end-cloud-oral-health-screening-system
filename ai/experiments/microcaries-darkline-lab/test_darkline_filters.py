"""Small deterministic regression test for the two v2 false-positive gates."""

import cv2
import numpy as np

from darkline_core import AnalysisParams, ToothInstance, analyze_tooth


def make_tooth(line_color: int, line_width: int, broad_patch: bool = False) -> tuple[np.ndarray, ToothInstance]:
    image = np.full((180, 180, 3), 205, np.uint8)
    mask = np.zeros((180, 180), np.uint8)
    cv2.ellipse(mask, (90, 90), (65, 72), 0, 0, 360, 1, -1)
    image[mask > 0] = (190, 205, 215)
    if broad_patch:
        cv2.ellipse(image, (90, 88), (35, 22), -15, 0, 360, (line_color,) * 3, -1)
    else:
        cv2.line(image, (45, 105), (135, 70), (line_color,) * 3, line_width, cv2.LINE_8)
    contour = max(cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)[0], key=cv2.contourArea)
    tooth = ToothInstance(1, 1.0, mask, (25, 18, 156, 163), (90.0, 90.0), contour)
    return image, tooth


def candidate_count(line_color: int, line_width: int, broad_patch: bool = False) -> int:
    image, tooth = make_tooth(line_color, line_width, broad_patch)
    params = AnalysisParams(
        darkness_threshold=0.35,
        black_level_pct=35.0,
        min_contrast_pct=5.0,
        min_width_px=1.5,
        min_length_pct=10.0,
        smooth_px=5,
    )
    return len(analyze_tooth(image, tooth, params).candidates)


def main() -> None:
    results = {
        "black_width_3_should_pass": candidate_count(30, 3),
        "shallow_width_3_should_pass": candidate_count(135, 3),
        "black_width_1_should_fail": candidate_count(30, 1),
        "broad_dark_patch_should_fail": candidate_count(55, 3, broad_patch=True),
    }
    print(results)
    assert results["black_width_3_should_pass"] >= 1
    assert results["shallow_width_3_should_pass"] >= 1
    assert results["black_width_1_should_fail"] == 0
    assert results["broad_dark_patch_should_fail"] == 0


if __name__ == "__main__":
    main()
