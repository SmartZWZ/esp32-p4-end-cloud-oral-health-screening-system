from pathlib import Path
import json

from ultralytics import YOLO

from darkline_core import (
    AnalysisParams,
    analysis_to_dict,
    analyze_tooth,
    detect_teeth,
    read_image,
    render_overview,
    write_image,
)


ROOT = Path(__file__).resolve().parent
MODEL_PATH = ROOT / "models" / "tooth_instance_best.pt"
IMAGE_PATH = ROOT / "deployment_assets" / "单牙效果展示" / "示例输入.jpg"
OUTPUT = ROOT / "示例运行输出"


def main() -> None:
    OUTPUT.mkdir(exist_ok=True)
    image = read_image(IMAGE_PATH)
    teeth = detect_teeth(YOLO(MODEL_PATH), image)
    if len(teeth) < 2:
        raise RuntimeError("示例图未检出预期的第二颗牙齿")
    tooth = next(item for item in teeth if item.index == 2)
    params = AnalysisParams(
        edge_shrink_pct=6.0,
        darkness_threshold=0.602,
        black_level_pct=35.0,
        min_contrast_pct=5.0,
        min_width_px=0.5,
        min_length_pct=18.0,
        smooth_px=5,
        exclude_highlights=True,
    )
    analysis = analyze_tooth(image, tooth, params)
    write_image(OUTPUT / "00_全景牙齿轮廓.png", render_overview(image, teeth, tooth.index, analysis))
    names = {
        "tooth": "01_单牙原图.png",
        "normalized": "02_光照归一化.png",
        "heatmap": "03_暗线热力图.png",
        "candidate": "04_候选暗区.png",
        "skeleton": "05_骨架与分叉.png",
    }
    for key, name in names.items():
        write_image(OUTPUT / name, analysis.views[key])
    payload = analysis_to_dict(str(IMAGE_PATH), str(MODEL_PATH), tooth, analysis, params)
    (OUTPUT / "参数与坐标.json").write_text(
        json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    print(f"处理完成：{OUTPUT}")
    print(f"牙齿数：{len(teeth)}；T02 候选数：{len(analysis.candidates)}")


if __name__ == "__main__":
    main()
