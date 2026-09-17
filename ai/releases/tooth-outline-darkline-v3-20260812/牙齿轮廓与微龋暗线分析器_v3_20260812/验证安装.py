from pathlib import Path

import cv2
import numpy
import PIL
import torch
import ultralytics
from ultralytics import YOLO

from darkline_core import AnalysisParams


ROOT = Path(__file__).resolve().parent
MODEL = ROOT / "models" / "tooth_instance_best.pt"


def main() -> None:
    params = AnalysisParams()
    expected = (6.0, 0.602, 35.0, 5.0, 0.5, 18.0, 5)
    actual = (
        params.edge_shrink_pct,
        params.darkness_threshold,
        params.black_level_pct,
        params.min_contrast_pct,
        params.min_width_px,
        params.min_length_pct,
        params.smooth_px,
    )
    if actual != expected:
        raise RuntimeError(f"默认参数不一致：{actual}")
    if not MODEL.is_file():
        raise FileNotFoundError(f"模型不存在：{MODEL}")
    YOLO(MODEL)
    print("安装验证通过")
    print(f"Python/PyTorch: {torch.__version__}")
    print(f"Ultralytics: {ultralytics.__version__}")
    print(f"OpenCV: {cv2.__version__}")
    print(f"NumPy/Pillow: {numpy.__version__} / {PIL.__version__}")
    print(f"推理设备: {torch.cuda.get_device_name(0) if torch.cuda.is_available() else 'CPU'}")
    print(f"模型: {MODEL}")


if __name__ == "__main__":
    main()
