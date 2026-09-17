"""Local GUI inference tool for the trained single-class caries detector.

Run with the dedicated Conda environment:
    conda activate dental-caries-yolo-gpu
    python caries_gui.py
"""

from __future__ import annotations

import argparse
import time
import tkinter as tk
from pathlib import Path
from tkinter import filedialog, messagebox, ttk

import numpy as np
from PIL import Image, ImageTk
from ultralytics import YOLO


ROOT = Path(__file__).resolve().parent
DEFAULT_MODEL = ROOT.parent / "weights" / "caries_yolov8s_best.pt"
IMAGE_TYPES = [
    ("Image files", "*.jpg *.jpeg *.png *.bmp *.webp"),
    ("JPEG files", "*.jpg *.jpeg"),
    ("PNG files", "*.png"),
    ("All files", "*.*"),
]


def predict_image(model: YOLO, image_path: Path, confidence: float):
    """Return an annotated RGB image, count, and elapsed time for one image."""
    started = time.perf_counter()
    result = model.predict(
        source=str(image_path),
        conf=confidence,
        iou=0.7,
        imgsz=640,
        device=0,
        verbose=False,
    )[0]
    elapsed = time.perf_counter() - started
    # Ultralytics plot() returns an OpenCV BGR ndarray.
    annotated_rgb = result.plot(labels=True, conf=True, boxes=True)[:, :, ::-1]
    count = 0 if result.boxes is None else len(result.boxes)
    return Image.fromarray(np.ascontiguousarray(annotated_rgb)), count, elapsed


class CariesApp(tk.Tk):
    def __init__(self, model_path: Path) -> None:
        super().__init__()
        self.title("龋齿检测 · YOLOv8s")
        self.minsize(960, 720)
        self.model_path = model_path
        self.model: YOLO | None = None
        self.source_path: Path | None = None
        self.annotated_image: Image.Image | None = None
        self.preview_ref: ImageTk.PhotoImage | None = None
        self.confidence = tk.DoubleVar(value=0.25)
        self.status = tk.StringVar(value="正在加载模型…")
        self._build_ui()
        self.after(100, self._load_model)

    def _build_ui(self) -> None:
        toolbar = ttk.Frame(self, padding=12)
        toolbar.pack(fill=tk.X)

        self.select_button = ttk.Button(toolbar, text="选择口内照片并检测", command=self.select_and_predict)
        self.select_button.pack(side=tk.LEFT)
        self.save_button = ttk.Button(toolbar, text="保存叠加结果", command=self.save_result, state=tk.DISABLED)
        self.save_button.pack(side=tk.LEFT, padx=(8, 0))

        ttk.Label(toolbar, text="置信度阈值").pack(side=tk.LEFT, padx=(24, 6))
        slider = ttk.Scale(toolbar, from_=0.05, to=0.95, variable=self.confidence, orient=tk.HORIZONTAL, length=180)
        slider.pack(side=tk.LEFT)
        self.threshold_label = ttk.Label(toolbar, width=4)
        self.threshold_label.pack(side=tk.LEFT, padx=(6, 0))
        slider.configure(command=lambda value: self.threshold_label.configure(text=f"{float(value):.2f}"))
        self.threshold_label.configure(text="0.25")

        ttk.Label(self, textvariable=self.status, padding=(12, 0, 12, 8), foreground="#1f4e79").pack(fill=tk.X)

        self.canvas = ttk.Label(self, anchor=tk.CENTER, text="选择一张 JPG、PNG、BMP 或 WEBP 格式的口内照片。")
        self.canvas.pack(fill=tk.BOTH, expand=True, padx=12, pady=(0, 12))

    def _load_model(self) -> None:
        if not self.model_path.is_file():
            messagebox.showerror("模型不存在", f"未找到模型文件：\n{self.model_path}")
            self.status.set("模型加载失败")
            self.select_button.configure(state=tk.DISABLED)
            return
        try:
            self.model = YOLO(str(self.model_path))
            self.status.set(f"模型已就绪：{self.model_path.name}。选择图片后将立即检测。")
        except Exception as error:  # Show an actionable message rather than a traceback in the GUI.
            messagebox.showerror("模型加载失败", str(error))
            self.status.set("模型加载失败")
            self.select_button.configure(state=tk.DISABLED)

    def select_and_predict(self) -> None:
        selected = filedialog.askopenfilename(title="选择口内照片", filetypes=IMAGE_TYPES)
        if not selected:
            return
        self.source_path = Path(selected)
        self.run_prediction()

    def run_prediction(self) -> None:
        if self.model is None or self.source_path is None:
            return
        self.select_button.configure(state=tk.DISABLED)
        self.save_button.configure(state=tk.DISABLED)
        self.status.set(f"正在检测：{self.source_path.name}")
        self.update_idletasks()
        try:
            image, count, elapsed = predict_image(self.model, self.source_path, self.confidence.get())
            self.annotated_image = image
            self._show_image(image)
            self.status.set(
                f"完成：检测到 {count} 个龋齿候选区域｜阈值 {self.confidence.get():.2f}｜耗时 {elapsed * 1000:.0f} ms"
            )
            self.save_button.configure(state=tk.NORMAL)
        except Exception as error:
            messagebox.showerror("检测失败", str(error))
            self.status.set("检测失败，请选择有效图片后重试。")
        finally:
            self.select_button.configure(state=tk.NORMAL)

    def _show_image(self, image: Image.Image) -> None:
        preview = image.copy()
        preview.thumbnail((1180, 760), Image.Resampling.LANCZOS)
        self.preview_ref = ImageTk.PhotoImage(preview)
        self.canvas.configure(image=self.preview_ref, text="")

    def save_result(self) -> None:
        if self.annotated_image is None or self.source_path is None:
            return
        default_name = f"{self.source_path.stem}_caries_detected.jpg"
        destination = filedialog.asksaveasfilename(
            title="保存检测结果",
            initialfile=default_name,
            defaultextension=".jpg",
            filetypes=[("JPEG image", "*.jpg"), ("PNG image", "*.png")],
        )
        if not destination:
            return
        self.annotated_image.save(destination)
        self.status.set(f"已保存：{destination}")


def main() -> None:
    parser = argparse.ArgumentParser(description="YOLOv8s caries detector")
    parser.add_argument("--model", type=Path, default=DEFAULT_MODEL)
    parser.add_argument("--image", type=Path, help="Optional headless single-image inference for verification")
    parser.add_argument("--output", type=Path, help="Output path when --image is used")
    parser.add_argument("--conf", type=float, default=0.25)
    args = parser.parse_args()

    if args.image:
        model = YOLO(str(args.model))
        image, count, elapsed = predict_image(model, args.image, args.conf)
        output = args.output or args.image.with_name(f"{args.image.stem}_caries_detected.jpg")
        image.save(output)
        print(f"Detected {count} caries candidates in {elapsed * 1000:.0f} ms; saved {output}")
        return

    CariesApp(args.model).mainloop()


if __name__ == "__main__":
    main()
