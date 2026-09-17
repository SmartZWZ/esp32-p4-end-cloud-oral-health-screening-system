"""口腔净框 — interactive desktop interface."""

from __future__ import annotations

import queue
import threading
from pathlib import Path
from tkinter import filedialog, messagebox
import tkinter as tk
from tkinter import ttk

import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageTk

from processor import ProcessResult, Settings, process_file, write_image


APP_DIR = Path(__file__).resolve().parent
SAMPLE_DIR = APP_DIR / "图片集"
OUTPUT_DIR = APP_DIR / "处理结果"

COLORS = {
    "bg": "#07141d",
    "panel": "#0d202b",
    "panel2": "#102a36",
    "line": "#24424e",
    "text": "#e9f6f6",
    "muted": "#8ca8b0",
    "cyan": "#42e8df",
    "mint": "#76f0b2",
    "warning": "#ffd166",
}


class ImageStage(tk.Frame):
    def __init__(self, master: tk.Misc, number: str, title: str, subtitle: str):
        super().__init__(master, bg=COLORS["panel"], highlightbackground=COLORS["line"], highlightthickness=1)
        self._photo: ImageTk.PhotoImage | None = None
        head = tk.Frame(self, bg=COLORS["panel"])
        head.pack(fill="x", padx=14, pady=(11, 8))
        tk.Label(head, text=number, bg=COLORS["cyan"], fg=COLORS["bg"], font=("Consolas", 9, "bold"), padx=7, pady=2).pack(side="left")
        tk.Label(head, text=title, bg=COLORS["panel"], fg=COLORS["text"], font=("Microsoft YaHei UI", 11, "bold")).pack(side="left", padx=(9, 7))
        tk.Label(head, text=subtitle, bg=COLORS["panel"], fg=COLORS["muted"], font=("Microsoft YaHei UI", 9)).pack(side="left")
        self.canvas = tk.Canvas(self, bg="#030a0f", highlightthickness=0, height=260)
        self.canvas.pack(fill="both", expand=True, padx=1, pady=(0, 1))
        self.canvas.bind("<Configure>", lambda _e: self._redraw())
        self._image: Image.Image | None = None

    def set_image(self, image: Image.Image | None) -> None:
        self._image = image
        self._redraw()

    def _redraw(self) -> None:
        self.canvas.delete("all")
        width, height = self.canvas.winfo_width(), self.canvas.winfo_height()
        if width <= 4 or height <= 4:
            return
        if self._image is None:
            self.canvas.create_text(width / 2, height / 2, text="等待载入图片", fill=COLORS["muted"], font=("Microsoft YaHei UI", 10))
            return
        image = self._image.copy()
        image.thumbnail((width - 14, height - 14), Image.Resampling.LANCZOS)
        if image.mode == "RGBA":
            checker = Image.new("RGB", image.size, "#0a1820")
            draw = ImageDraw.Draw(checker)
            tile = 14
            for y in range(0, image.height, tile):
                for x in range(0, image.width, tile):
                    if (x // tile + y // tile) % 2:
                        draw.rectangle((x, y, x + tile, y + tile), fill="#17313d")
            checker.paste(image, mask=image.getchannel("A"))
            image = checker
        self._photo = ImageTk.PhotoImage(image)
        self.canvas.create_image(width / 2, height / 2, image=self._photo, anchor="center")


class DentalFrameApp(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("口腔净框 · 白色反光材料去除")
        self.geometry("1450x900")
        self.minsize(1120, 720)
        self.configure(bg=COLORS["bg"])
        self.current_path: Path | None = None
        self.result: ProcessResult | None = None
        self._processing = False
        self._worker_results: queue.Queue[tuple[str, object]] = queue.Queue()
        self._build_styles()
        self._build_ui()
        self._load_samples()

    def _build_styles(self) -> None:
        style = ttk.Style(self)
        style.theme_use("clam")
        style.configure("TScale", background=COLORS["panel2"], troughcolor="#193844")
        style.configure("TCombobox", fieldbackground="#17313d", background="#17313d", foreground=COLORS["text"], arrowcolor=COLORS["cyan"])

    def _build_ui(self) -> None:
        header = tk.Frame(self, bg=COLORS["bg"], height=82)
        header.pack(fill="x", padx=24, pady=(18, 10))
        title_block = tk.Frame(header, bg=COLORS["bg"])
        title_block.pack(side="left")
        tk.Label(title_block, text="口腔净框", bg=COLORS["bg"], fg=COLORS["text"], font=("Microsoft YaHei UI", 24, "bold")).pack(anchor="w")
        tk.Label(title_block, text="DENTAL FRAME CLEANER  /  边缘连通检测", bg=COLORS["bg"], fg=COLORS["cyan"], font=("Consolas", 9, "bold")).pack(anchor="w", pady=(3, 0))
        self.file_label = tk.Label(header, text="选择一张图片开始", bg=COLORS["bg"], fg=COLORS["muted"], font=("Microsoft YaHei UI", 10))
        self.file_label.pack(side="right", anchor="s", pady=8)

        body = tk.Frame(self, bg=COLORS["bg"])
        body.pack(fill="both", expand=True, padx=24, pady=(0, 20))
        sidebar = tk.Frame(body, bg=COLORS["panel2"], width=286, highlightbackground=COLORS["line"], highlightthickness=1)
        sidebar.pack(side="left", fill="y")
        sidebar.pack_propagate(False)
        workspace = tk.Frame(body, bg=COLORS["bg"])
        workspace.pack(side="left", fill="both", expand=True, padx=(16, 0))

        self._build_sidebar(sidebar)
        stages = tk.Frame(workspace, bg=COLORS["bg"])
        stages.pack(fill="both", expand=True)
        stages.grid_rowconfigure(0, weight=1)
        stages.grid_rowconfigure(1, weight=1)
        stages.grid_columnconfigure(0, weight=1)
        stages.grid_columnconfigure(1, weight=1)
        self.stage_original = ImageStage(stages, "01", "原始画面", "输入")
        self.stage_candidate = ImageStage(stages, "02", "白色候选", "亮度 + 色彩")
        self.stage_overlay = ImageStage(stages, "03", "边缘确认", "青色=材料  绿色=保留框")
        self.stage_output = ImageStage(stages, "04", "最终结果", "框内材料已置黑")
        for widget, row, col in [
            (self.stage_original, 0, 0), (self.stage_candidate, 0, 1),
            (self.stage_overlay, 1, 0), (self.stage_output, 1, 1),
        ]:
            widget.grid(row=row, column=col, sticky="nsew", padx=(0 if col == 0 else 6, 6 if col == 0 else 0), pady=(0 if row == 0 else 6, 6 if row == 0 else 0))

        self.status = tk.Label(workspace, text="就绪", anchor="w", bg=COLORS["bg"], fg=COLORS["muted"], font=("Microsoft YaHei UI", 9))
        self.status.pack(fill="x", pady=(9, 0))

    def _build_sidebar(self, parent: tk.Frame) -> None:
        tk.Label(parent, text="图片", bg=COLORS["panel2"], fg=COLORS["text"], font=("Microsoft YaHei UI", 11, "bold")).pack(anchor="w", padx=18, pady=(18, 8))
        self.file_list = tk.Listbox(parent, height=4, bg="#0a1a23", fg=COLORS["text"], selectbackground="#176d72", selectforeground="white", borderwidth=0, highlightthickness=0, font=("Microsoft YaHei UI", 10), activestyle="none")
        self.file_list.pack(fill="x", padx=18)
        self.file_list.bind("<<ListboxSelect>>", self._on_sample_selected)
        self.sample_paths: list[Path] = []
        self.source_dir = SAMPLE_DIR
        self._button(parent, "打开其他图片", self.open_image, secondary=True).pack(fill="x", padx=18, pady=(9, 0))
        self._button(parent, "选择图片文件夹", self.open_folder, secondary=True).pack(fill="x", padx=18, pady=(5, 0))

        tk.Frame(parent, bg=COLORS["line"], height=1).pack(fill="x", padx=18, pady=17)
        tk.Label(parent, text="检测参数", bg=COLORS["panel2"], fg=COLORS["text"], font=("Microsoft YaHei UI", 11, "bold")).pack(anchor="w", padx=18)
        tk.Label(parent, text="改动后点击“重新处理”", bg=COLORS["panel2"], fg=COLORS["muted"], font=("Microsoft YaHei UI", 8)).pack(anchor="w", padx=18, pady=(2, 8))

        self.brightness = self._slider(parent, "最低亮度", 80, 230, 145)
        self.saturation = self._slider(parent, "最大饱和度", 40, 240, 155)
        self.blue_bias = self._slider(parent, "青白偏色", -60, 45, -12)
        self.close_size = self._slider(parent, "断点连接", 3, 41, 19)
        self.padding = self._slider(parent, "黑色覆盖扩张（自动缩放）", 0, 50, 28)
        self.retention = self._slider(parent, "内容保留率", 0, 100, 55)

        tk.Label(parent, text="输出方式", bg=COLORS["panel2"], fg=COLORS["muted"], font=("Microsoft YaHei UI", 9)).pack(anchor="w", padx=18, pady=(8, 4))
        self.output_mode = ttk.Combobox(parent, state="readonly", values=["保留牙齿（材料置黑，推荐）", "严格裁剪（无黑色区域）", "透明去除（保留尺寸）"])
        self.output_mode.current(0)
        self.output_mode.pack(fill="x", padx=18)
        self.output_mode.bind("<<ComboboxSelected>>", lambda _e: self._update_output())

        self._button(parent, "重新处理", self.process_current).pack(fill="x", padx=18, pady=(17, 7))
        self._button(parent, "导出当前结果", self.export_current, secondary=True).pack(fill="x", padx=18, pady=3)
        self._button(parent, "批量处理图片集", self.batch_process, secondary=True).pack(fill="x", padx=18, pady=3)

    def _button(self, parent: tk.Misc, text: str, command, secondary: bool = False) -> tk.Button:
        return tk.Button(parent, text=text, command=command, bg=COLORS["panel"] if secondary else COLORS["cyan"], fg=COLORS["text"] if secondary else COLORS["bg"], activebackground=COLORS["mint"], activeforeground=COLORS["bg"], relief="flat", borderwidth=0, cursor="hand2", font=("Microsoft YaHei UI", 10, "bold"), pady=8)

    def _slider(self, parent: tk.Frame, title: str, start: int, end: int, value: int) -> tk.IntVar:
        row = tk.Frame(parent, bg=COLORS["panel2"])
        row.pack(fill="x", padx=18, pady=3)
        var = tk.IntVar(value=value)
        label = tk.Label(row, text=f"{title}  {value}", bg=COLORS["panel2"], fg=COLORS["muted"], font=("Microsoft YaHei UI", 9))
        label.pack(anchor="w")
        scale = ttk.Scale(row, from_=start, to=end, value=value, command=lambda v, l=label, t=title, x=var: (x.set(round(float(v))), l.config(text=f"{t}  {round(float(v))}")))
        scale.pack(fill="x")
        return var

    def _load_samples(self, folder: Path | None = None) -> None:
        self.source_dir = folder or SAMPLE_DIR
        self.sample_paths = sorted(
            [
                *self.source_dir.glob("*.jpg"),
                *self.source_dir.glob("*.jpeg"),
                *self.source_dir.glob("*.png"),
                *self.source_dir.glob("*.bmp"),
            ]
        ) if self.source_dir.exists() else []
        self.file_list.delete(0, "end")
        for path in self.sample_paths:
            self.file_list.insert("end", path.name)
        if self.sample_paths:
            self.file_list.selection_set(0)
            self._select_path(self.sample_paths[0])

    def _on_sample_selected(self, _event=None) -> None:
        selected = self.file_list.curselection()
        if selected:
            self._select_path(self.sample_paths[selected[0]])

    def open_image(self) -> None:
        path = filedialog.askopenfilename(title="选择口腔图片", filetypes=[("图片", "*.jpg *.jpeg *.png *.bmp"), ("所有文件", "*.*")])
        if path:
            self._select_path(Path(path))

    def open_folder(self) -> None:
        path = filedialog.askdirectory(title="选择待处理图片文件夹", initialdir=str(self.source_dir))
        if path:
            self._load_samples(Path(path))

    def _select_path(self, path: Path) -> None:
        self.current_path = path
        self.file_label.config(text=str(path.name))
        self.process_current()

    def _settings(self) -> Settings:
        return Settings(
            brightness=self.brightness.get(), max_saturation=self.saturation.get(),
            blue_bias=self.blue_bias.get(), close_size=self.close_size.get(),
            safety_padding=self.padding.get(), retention_percent=self.retention.get(),
        )

    def process_current(self) -> None:
        if not self.current_path or self._processing:
            return
        self._processing = True
        self.status.config(text="正在分析边缘反光材料…", fg=COLORS["cyan"])
        settings, path = self._settings(), self.current_path

        def work():
            try:
                result = process_file(path, settings)
                self._worker_results.put(("ok", result))
            except Exception as exc:
                self._worker_results.put(("error", exc))
        threading.Thread(target=work, daemon=True).start()
        self.after(40, self._poll_worker)

    def _poll_worker(self) -> None:
        try:
            status, payload = self._worker_results.get_nowait()
        except queue.Empty:
            if self._processing:
                self.after(40, self._poll_worker)
            return
        if status == "ok":
            self._show_result(payload)  # type: ignore[arg-type]
        else:
            self._show_error(payload)  # type: ignore[arg-type]

    def _show_error(self, exc: Exception) -> None:
        self._processing = False
        self.status.config(text="处理失败", fg=COLORS["warning"])
        messagebox.showerror("处理失败", str(exc))

    @staticmethod
    def _pil(image: np.ndarray) -> Image.Image:
        if image.ndim == 2:
            return Image.fromarray(image, mode="L").convert("RGB")
        if image.shape[2] == 4:
            return Image.fromarray(cv2.cvtColor(image, cv2.COLOR_BGRA2RGBA))
        return Image.fromarray(cv2.cvtColor(image, cv2.COLOR_BGR2RGB))

    def _show_result(self, result: ProcessResult) -> None:
        self._processing = False
        self.result = result
        self.stage_original.set_image(self._pil(result.original))
        self.stage_candidate.set_image(self._pil(result.candidate))
        self.stage_overlay.set_image(self._pil(result.overlay))
        self._update_output()
        h, w = result.original.shape[:2]
        x1, y1, x2, y2 = result.crop_rect
        profile_name = "大面积材料" if result.profile == "large" else "细小光圈"
        self.status.config(text=f"自动模式：{profile_name}  ·  识别材料 {result.coverage * 100:.1f}%  ·  原图 {w}×{h}  ·  保留输出 {x2-x1}×{y2-y1}  ·  掩膜区域将变成黑色", fg=COLORS["muted"])

    def _update_output(self) -> None:
        if not self.result:
            return
        mode = self.output_mode.current()
        image = self.result.cropped if mode == 0 else self.result.safe_cropped if mode == 1 else self.result.transparent
        self.stage_output.set_image(self._pil(image))

    def export_current(self) -> None:
        if not self.result or not self.current_path:
            messagebox.showinfo("没有结果", "请先选择并处理一张图片。")
            return
        mode = self.output_mode.current()
        transparent = mode == 2
        lossless = mode in (0, 2)
        default_ext = ".png" if lossless else ".jpg"
        suffix = "透明去除" if transparent else "严格裁剪" if mode == 1 else "保留牙齿_材料置黑"
        default_name = f"{self.current_path.stem}_{suffix}{default_ext}"
        path = filedialog.asksaveasfilename(initialdir=str(OUTPUT_DIR), initialfile=default_name, defaultextension=default_ext, filetypes=[("PNG（无损）", "*.png")] if lossless else [("JPEG", "*.jpg"), ("PNG", "*.png")])
        if path:
            output = self.result.transparent if transparent else self.result.safe_cropped if mode == 1 else self.result.cropped
            write_image(path, output)
            self.status.config(text=f"已导出：{path}", fg=COLORS["mint"])

    def batch_process(self) -> None:
        if not self.sample_paths:
            messagebox.showinfo("图片集为空", "“图片集”文件夹中没有可处理的图片。")
            return
        OUTPUT_DIR.mkdir(exist_ok=True)
        settings = self._settings()
        try:
            for path in self.sample_paths:
                result = process_file(path, settings)
                write_image(OUTPUT_DIR / f"{path.stem}_无反光材料.png", result.cropped)
                write_image(OUTPUT_DIR / f"{path.stem}_检测过程.jpg", result.overlay)
                write_image(OUTPUT_DIR / f"{path.stem}_透明去除.png", result.transparent)
            self.status.config(text=f"批量完成：{len(self.sample_paths)} 张图片，结果位于“处理结果”文件夹", fg=COLORS["mint"])
            messagebox.showinfo("批量处理完成", f"已处理 {len(self.sample_paths)} 张图片。\n\n输出目录：\n{OUTPUT_DIR}")
        except Exception as exc:
            self._show_error(exc)


if __name__ == "__main__":
    DentalFrameApp().mainloop()
