from __future__ import annotations

import json
import sys
import threading
import traceback
from pathlib import Path
import tkinter as tk
from tkinter import filedialog, messagebox, ttk

import cv2
from PIL import Image, ImageTk
from ultralytics import YOLO

from darkline_core import (
    AnalysisParams,
    ToothAnalysis,
    ToothInstance,
    analysis_to_dict,
    analyze_tooth,
    detect_teeth,
    read_image,
    render_overview,
    write_image,
)


WORKSPACE = Path(__file__).resolve().parent
PACKAGED_MODEL_PATH = WORKSPACE / "models" / "tooth_instance_best.pt"
LEGACY_MODEL_PATH = (
    WORKSPACE.parent
    / "tooth-instance-seg"
    / "runs"
    / "tooth_yolo11s_1024"
    / "weights"
    / "best.pt"
)
MODEL_PATH = PACKAGED_MODEL_PATH if PACKAGED_MODEL_PATH.exists() else LEGACY_MODEL_PATH


COLORS = {
    "ink": "#0B1419",
    "panel": "#101D24",
    "panel_2": "#17262E",
    "line": "#29404B",
    "text": "#E8F2F5",
    "muted": "#8FA7B2",
    "blue": "#37B8D8",
    "blue_dark": "#173B47",
    "amber": "#F4BE3E",
    "coral": "#FF625C",
    "white": "#FFFFFF",
}


class DarklineLabApp:
    def __init__(self, root: tk.Tk) -> None:
        self.root = root
        self.root.title("牙面暗线实验台 · 微龋候选分析")
        self.root.geometry("1480x900")
        self.root.minsize(1160, 720)
        self.root.configure(bg=COLORS["ink"])

        self.model: YOLO | None = None
        self.image_path: Path | None = None
        self.image_bgr = None
        self.teeth: list[ToothInstance] = []
        self.selected_tooth: ToothInstance | None = None
        self.analysis: ToothAnalysis | None = None
        self.current_view = "overview"
        self.display_bgr = None
        self.photo: ImageTk.PhotoImage | None = None
        self.zoom = 1.0
        self.is_busy = False
        self.recompute_job: str | None = None

        self.edge_var = tk.DoubleVar(value=6.0)
        self.dark_var = tk.DoubleVar(value=60.2)
        self.black_var = tk.DoubleVar(value=35.0)
        self.contrast_var = tk.DoubleVar(value=5.0)
        self.width_var = tk.DoubleVar(value=0.5)
        self.length_var = tk.DoubleVar(value=18.0)
        self.smooth_var = tk.IntVar(value=5)
        self.highlight_var = tk.BooleanVar(value=True)
        self.status_var = tk.StringVar(value="打开一张口内照片开始分析")
        self.model_status_var = tk.StringVar(value="模型待加载")
        self.image_name_var = tk.StringVar(value="尚未选择图片")
        self.tooth_info_var = tk.StringVar(value="—")
        self.candidate_count_var = tk.StringVar(value="0")
        self.total_length_var = tk.StringVar(value="0 px")
        self.branch_count_var = tk.StringVar(value="0")

        self._configure_styles()
        self._build_ui()
        self.root.after(150, self._render_display)

    def _configure_styles(self) -> None:
        style = ttk.Style()
        try:
            style.theme_use("clam")
        except tk.TclError:
            pass
        style.configure(
            "Dark.Horizontal.TScale",
            background=COLORS["panel"],
            troughcolor=COLORS["line"],
            bordercolor=COLORS["line"],
            lightcolor=COLORS["blue"],
            darkcolor=COLORS["blue"],
        )
        style.configure(
            "Dark.Treeview",
            background=COLORS["panel_2"],
            fieldbackground=COLORS["panel_2"],
            foreground=COLORS["text"],
            rowheight=28,
            borderwidth=0,
            font=("Microsoft YaHei UI", 9),
        )
        style.configure(
            "Dark.Treeview.Heading",
            background=COLORS["line"],
            foreground=COLORS["text"],
            relief="flat",
            font=("Microsoft YaHei UI", 9, "bold"),
        )
        style.map("Dark.Treeview", background=[("selected", COLORS["blue_dark"])])

    def _build_ui(self) -> None:
        header = tk.Frame(self.root, bg=COLORS["panel"], height=82)
        header.pack(fill="x")
        header.pack_propagate(False)

        title_block = tk.Frame(header, bg=COLORS["panel"])
        title_block.pack(side="left", padx=24, pady=15)
        tk.Label(
            title_block,
            text="牙面暗线实验台",
            bg=COLORS["panel"],
            fg=COLORS["text"],
            font=("Microsoft YaHei UI", 20, "bold"),
        ).pack(anchor="w")
        tk.Label(
            title_block,
            text="逐颗牙齿 · 暗线增强 · 骨架与分叉证据",
            bg=COLORS["panel"],
            fg=COLORS["muted"],
            font=("Microsoft YaHei UI", 9),
        ).pack(anchor="w", pady=(2, 0))

        status_pill = tk.Label(
            header,
            textvariable=self.model_status_var,
            bg=COLORS["blue_dark"],
            fg=COLORS["blue"],
            font=("Microsoft YaHei UI", 9, "bold"),
            padx=14,
            pady=7,
        )
        status_pill.pack(side="right", padx=(10, 24))

        self.export_button = self._button(header, "导出当前证据", self.export_current, secondary=True)
        self.export_button.pack(side="right", padx=5)
        self.open_button = self._button(header, "打开口内照片", self.open_image)
        self.open_button.pack(side="right", padx=5)

        body = tk.Frame(self.root, bg=COLORS["ink"])
        body.pack(fill="both", expand=True)

        self.left_panel = tk.Frame(body, bg=COLORS["panel"], width=190)
        self.left_panel.pack(side="left", fill="y")
        self.left_panel.pack_propagate(False)
        self._build_left_panel()

        center = tk.Frame(body, bg=COLORS["ink"])
        self.right_panel = tk.Frame(body, bg=COLORS["panel"], width=330)
        self.right_panel.pack(side="right", fill="y")
        self.right_panel.pack_propagate(False)
        self._build_right_panel()

        # Pack both fixed sidebars before the elastic canvas. This keeps the
        # parameter panel reachable on narrow/high-DPI Windows displays.
        center.pack(side="left", fill="both", expand=True)
        self._build_canvas(center)

        footer = tk.Frame(self.root, bg="#091015", height=34)
        footer.pack(fill="x")
        footer.pack_propagate(False)
        tk.Label(
            footer,
            textvariable=self.status_var,
            bg="#091015",
            fg=COLORS["muted"],
            font=("Microsoft YaHei UI", 9),
            anchor="w",
        ).pack(side="left", fill="x", expand=True, padx=16)
        tk.Label(
            footer,
            text="研究候选，不是临床诊断",
            bg="#091015",
            fg=COLORS["amber"],
            font=("Microsoft YaHei UI", 9, "bold"),
        ).pack(side="right", padx=16)

    def _build_left_panel(self) -> None:
        tk.Label(
            self.left_panel,
            text="证据层",
            bg=COLORS["panel"],
            fg=COLORS["muted"],
            font=("Microsoft YaHei UI", 9, "bold"),
        ).pack(anchor="w", padx=18, pady=(22, 10))

        self.view_buttons: dict[str, tk.Button] = {}
        views = [
            ("overview", "全景牙齿轮廓", "所有牙齿与当前候选"),
            ("tooth", "单牙原图", "查看原始牙面像素"),
            ("normalized", "光照归一化", "压低阴影与亮度漂移"),
            ("heatmap", "暗线热力图", "局部暗度与线结构响应"),
            ("candidate", "候选暗区", "通过阈值的连续区域"),
            ("skeleton", "骨架与分叉", "线中心、端点与分叉点"),
        ]
        for key, title, subtitle in views:
            frame = tk.Frame(self.left_panel, bg=COLORS["panel"])
            frame.pack(fill="x", padx=10, pady=3)
            button = tk.Button(
                frame,
                text=title,
                command=lambda selected=key: self.set_view(selected),
                bg=COLORS["panel"],
                fg=COLORS["text"],
                activebackground=COLORS["blue_dark"],
                activeforeground=COLORS["white"],
                relief="flat",
                bd=0,
                anchor="w",
                padx=10,
                pady=7,
                cursor="hand2",
                font=("Microsoft YaHei UI", 10, "bold"),
            )
            button.pack(fill="x")
            tk.Label(
                frame,
                text=subtitle,
                bg=COLORS["panel"],
                fg=COLORS["muted"],
                anchor="w",
                padx=10,
                font=("Microsoft YaHei UI", 8),
            ).pack(fill="x", pady=(0, 4))
            self.view_buttons[key] = button

        divider = tk.Frame(self.left_panel, bg=COLORS["line"], height=1)
        divider.pack(fill="x", padx=18, pady=18)
        tk.Label(
            self.left_panel,
            text="图例",
            bg=COLORS["panel"],
            fg=COLORS["muted"],
            font=("Microsoft YaHei UI", 9, "bold"),
        ).pack(anchor="w", padx=18, pady=(0, 8))
        for color, label in [
            (COLORS["blue"], "牙齿轮廓"),
            (COLORS["amber"], "暗线骨架 / 端点"),
            (COLORS["coral"], "分叉点"),
        ]:
            row = tk.Frame(self.left_panel, bg=COLORS["panel"])
            row.pack(fill="x", padx=18, pady=4)
            tk.Label(row, text="●", bg=COLORS["panel"], fg=color, font=("Arial", 12)).pack(side="left")
            tk.Label(
                row,
                text=label,
                bg=COLORS["panel"],
                fg=COLORS["text"],
                font=("Microsoft YaHei UI", 9),
            ).pack(side="left", padx=7)

    def _build_canvas(self, parent: tk.Frame) -> None:
        top = tk.Frame(parent, bg=COLORS["ink"], height=42)
        top.pack(fill="x")
        top.pack_propagate(False)
        tk.Label(
            top,
            textvariable=self.image_name_var,
            bg=COLORS["ink"],
            fg=COLORS["text"],
            font=("Microsoft YaHei UI", 10, "bold"),
        ).pack(side="left", padx=16)
        self._button(top, "适应窗口", self.fit_view, secondary=True, compact=True).pack(side="right", padx=5, pady=7)
        self._button(top, "−", lambda: self.change_zoom(0.82), secondary=True, compact=True).pack(
            side="right", padx=3, pady=7
        )
        self._button(top, "+", lambda: self.change_zoom(1.22), secondary=True, compact=True).pack(
            side="right", padx=3, pady=7
        )

        viewport = tk.Frame(parent, bg=COLORS["ink"])
        viewport.pack(fill="both", expand=True, padx=12, pady=(0, 12))
        self.canvas = tk.Canvas(
            viewport,
            bg="#081116",
            highlightthickness=1,
            highlightbackground=COLORS["line"],
            xscrollincrement=10,
            yscrollincrement=10,
        )
        hbar = ttk.Scrollbar(viewport, orient="horizontal", command=self.canvas.xview)
        vbar = ttk.Scrollbar(viewport, orient="vertical", command=self.canvas.yview)
        self.canvas.configure(xscrollcommand=hbar.set, yscrollcommand=vbar.set)
        self.canvas.grid(row=0, column=0, sticky="nsew")
        vbar.grid(row=0, column=1, sticky="ns")
        hbar.grid(row=1, column=0, sticky="ew")
        viewport.rowconfigure(0, weight=1)
        viewport.columnconfigure(0, weight=1)

        self.canvas.bind("<Configure>", lambda _event: self._render_display())
        self.canvas.bind("<MouseWheel>", self._on_mousewheel)
        self.canvas.bind("<ButtonPress-3>", lambda event: self.canvas.scan_mark(event.x, event.y))
        self.canvas.bind("<B3-Motion>", lambda event: self.canvas.scan_dragto(event.x, event.y, gain=1))

        self.empty_text = self.canvas.create_text(
            420,
            300,
            text="打开一张口内照片\n模型会先划分每颗牙齿，再提取连续暗线",
            fill=COLORS["muted"],
            font=("Microsoft YaHei UI", 14),
            justify="center",
        )

    def _build_right_panel(self) -> None:
        container = tk.Frame(self.right_panel, bg=COLORS["panel"])
        container.pack(fill="both", expand=True, padx=16, pady=16)

        self._section_label(container, "当前牙齿")
        nav = tk.Frame(container, bg=COLORS["panel"])
        nav.pack(fill="x", pady=(6, 8))
        self._button(nav, "上一颗", lambda: self.step_tooth(-1), secondary=True, compact=True).pack(side="left")
        self._button(nav, "下一颗", lambda: self.step_tooth(1), secondary=True, compact=True).pack(side="right")
        self.tooth_list = tk.Listbox(
            container,
            height=3,
            bg=COLORS["panel_2"],
            fg=COLORS["text"],
            selectbackground=COLORS["blue_dark"],
            selectforeground=COLORS["white"],
            highlightthickness=1,
            highlightbackground=COLORS["line"],
            borderwidth=0,
            activestyle="none",
            font=("Consolas", 10),
        )
        self.tooth_list.pack(fill="x")
        self.tooth_list.bind("<<ListboxSelect>>", self._on_tooth_selected)

        self._divider(container)
        self._section_label(container, "暗线参数")
        self._slider(container, "边缘内缩", self.edge_var, 0, 15, "%")
        self._slider(container, "暗度阈值", self.dark_var, 20, 80, "%")
        self._slider(container, "黑色核心上限", self.black_var, 20, 65, "%亮度")
        self._slider(container, "最小局部反差", self.contrast_var, 2, 20, "%亮度")
        self._slider(container, "最小线宽", self.width_var, 0.5, 6, "px", resolution=0.5)
        self._slider(container, "最短线长", self.length_var, 2, 25, "%牙宽")
        self._slider(container, "平滑尺度", self.smooth_var, 1, 9, "px", resolution=2)
        check = tk.Checkbutton(
            container,
            text="排除牙面镜面反光",
            variable=self.highlight_var,
            command=self.schedule_recompute,
            bg=COLORS["panel"],
            fg=COLORS["text"],
            activebackground=COLORS["panel"],
            activeforeground=COLORS["text"],
            selectcolor=COLORS["panel_2"],
            font=("Microsoft YaHei UI", 9),
        )
        check.pack(anchor="w", pady=(8, 3))

        self._divider(container)
        self._section_label(container, "结构统计")
        cards = tk.Frame(container, bg=COLORS["panel"])
        cards.pack(fill="x", pady=(7, 8))
        self._stat_card(cards, "候选", self.candidate_count_var, 0, 0)
        self._stat_card(cards, "骨架总长", self.total_length_var, 0, 1)
        self._stat_card(cards, "分叉点", self.branch_count_var, 1, 0)
        self._stat_card(cards, "牙齿置信度", self.tooth_info_var, 1, 1)

        self.candidate_table = ttk.Treeview(
            container,
            columns=("type", "score", "width", "evidence"),
            show="headings",
            height=4,
            style="Dark.Treeview",
        )
        for column, title, width in [
            ("type", "形态", 92),
            ("score", "结构分", 58),
            ("width", "中位宽", 55),
            ("evidence", "证据", 75),
        ]:
            self.candidate_table.heading(column, text=title)
            self.candidate_table.column(column, width=width, minwidth=38, stretch=True, anchor="center")
        self.candidate_table.pack(fill="x", pady=(2, 6))

        note = tk.Label(
            container,
            text="研究候选 · 结构分数不是患龋概率",
            bg=COLORS["blue_dark"],
            fg="#B9DDE7",
            justify="left",
            anchor="w",
            padx=11,
            pady=6,
            font=("Microsoft YaHei UI", 8),
        )
        note.pack(fill="x", side="bottom")

    def _section_label(self, parent: tk.Widget, text: str) -> None:
        tk.Label(
            parent,
            text=text,
            bg=COLORS["panel"],
            fg=COLORS["muted"],
            font=("Microsoft YaHei UI", 9, "bold"),
        ).pack(anchor="w")

    def _divider(self, parent: tk.Widget) -> None:
        tk.Frame(parent, bg=COLORS["line"], height=1).pack(fill="x", pady=8)

    def _button(
        self,
        parent: tk.Widget,
        text: str,
        command,
        secondary: bool = False,
        compact: bool = False,
    ) -> tk.Button:
        return tk.Button(
            parent,
            text=text,
            command=command,
            bg=COLORS["panel_2"] if secondary else COLORS["blue"],
            fg=COLORS["text"] if secondary else COLORS["ink"],
            activebackground=COLORS["line"] if secondary else "#74D5EA",
            activeforeground=COLORS["white"] if secondary else COLORS["ink"],
            relief="flat",
            bd=0,
            padx=10 if compact else 16,
            pady=3 if compact else 8,
            cursor="hand2",
            font=("Microsoft YaHei UI", 9, "bold"),
        )

    def _slider(
        self,
        parent: tk.Widget,
        label: str,
        variable: tk.Variable,
        start: int,
        end: int,
        suffix: str,
        resolution: int = 1,
    ) -> None:
        row = tk.Frame(parent, bg=COLORS["panel"])
        row.pack(fill="x", pady=(5, 0))
        tk.Label(row, text=label, bg=COLORS["panel"], fg=COLORS["text"], font=("Microsoft YaHei UI", 9)).pack(
            side="left"
        )
        value_label = tk.Label(
            row,
            text="",
            bg=COLORS["panel"],
            fg=COLORS["amber"],
            font=("Consolas", 9, "bold"),
        )
        value_label.pack(side="right")

        def update_label(*_args) -> None:
            value = variable.get()
            formatted = f"{int(value)}" if float(value).is_integer() else f"{value:.1f}"
            value_label.configure(text=f"{formatted} {suffix}")
            self.schedule_recompute()

        variable.trace_add("write", update_label)
        update_label()
        scale = ttk.Scale(
            parent,
            from_=start,
            to=end,
            variable=variable,
            command=lambda _value: None,
            style="Dark.Horizontal.TScale",
        )
        scale.pack(fill="x", pady=(2, 0))
        if resolution != 1:
            scale.bind("<ButtonRelease-1>", lambda _event: variable.set(round(variable.get() / resolution) * resolution))

    def _stat_card(self, parent: tk.Frame, title: str, variable: tk.StringVar, row: int, column: int) -> None:
        card = tk.Frame(parent, bg=COLORS["panel_2"], padx=10, pady=5)
        card.grid(row=row, column=column, sticky="nsew", padx=3, pady=3)
        parent.columnconfigure(column, weight=1)
        tk.Label(
            card,
            text=title,
            bg=COLORS["panel_2"],
            fg=COLORS["muted"],
            font=("Microsoft YaHei UI", 8),
        ).pack(anchor="w")
        tk.Label(
            card,
            textvariable=variable,
            bg=COLORS["panel_2"],
            fg=COLORS["text"],
            font=("Consolas", 11, "bold"),
        ).pack(anchor="w", pady=(2, 0))

    def open_image(self) -> None:
        if self.is_busy:
            return
        path = filedialog.askopenfilename(
            title="选择口内照片",
            filetypes=[("图片", "*.jpg *.jpeg *.png *.bmp *.tif *.tiff"), ("所有文件", "*.*")],
        )
        if not path:
            return
        try:
            self.image_bgr = read_image(path)
        except Exception as exc:
            messagebox.showerror("无法打开图片", str(exc))
            return
        self.image_path = Path(path)
        self.image_name_var.set(f"{self.image_path.name}  ·  {self.image_bgr.shape[1]}×{self.image_bgr.shape[0]}")
        self.teeth = []
        self.selected_tooth = None
        self.analysis = None
        self.tooth_list.delete(0, tk.END)
        self.current_view = "overview"
        self.display_bgr = self.image_bgr.copy()
        self.zoom = 1.0
        self._render_display()
        self._run_inference()

    def _run_inference(self) -> None:
        if self.image_bgr is None:
            return
        if not MODEL_PATH.exists():
            messagebox.showerror("模型不存在", f"未找到牙齿轮廓权重：\n{MODEL_PATH}")
            return
        self._set_busy(True, "正在划分每颗牙齿…")
        image_copy = self.image_bgr.copy()

        def worker() -> None:
            try:
                if self.model is None:
                    self.root.after(0, lambda: self.model_status_var.set("正在加载 YOLO11s-seg"))
                    self.model = YOLO(str(MODEL_PATH))
                teeth = detect_teeth(self.model, image_copy, conf=0.25)
                self.root.after(0, lambda: self._inference_done(teeth))
            except Exception as exc:
                detail = traceback.format_exc()
                self.root.after(0, lambda error=exc, log=detail: self._worker_failed(error, log))

        threading.Thread(target=worker, daemon=True).start()

    def _inference_done(self, teeth: list[ToothInstance]) -> None:
        self.teeth = teeth
        self.tooth_list.delete(0, tk.END)
        for tooth in teeth:
            x0, y0, x1, y1 = tooth.bbox
            self.tooth_list.insert(
                tk.END,
                f"T{tooth.index:02d}   conf {tooth.confidence:.2f}   {x1 - x0}×{y1 - y0}",
            )
        self.model_status_var.set(f"模型就绪 · 找到 {len(teeth)} 颗牙")
        self._set_busy(False, f"牙齿分割完成：共 {len(teeth)} 颗。请选择一颗查看暗线证据。")
        if not teeth:
            self.display_bgr = self.image_bgr.copy()
            self._render_display()
            messagebox.showinfo("没有检测到牙齿", "请换用清晰、牙齿可见面积更大的口内照片。")
            return
        self.tooth_list.selection_set(0)
        self.tooth_list.activate(0)
        self.select_tooth(0)

    def _worker_failed(self, exc: Exception, detail: str) -> None:
        (WORKSPACE / "app_error.log").write_text(detail, encoding="utf-8")
        self.model_status_var.set("模型运行失败")
        self._set_busy(False, "处理失败，详细信息已写入 app_error.log")
        messagebox.showerror("处理失败", f"{exc}\n\n详细日志：{WORKSPACE / 'app_error.log'}")

    def _set_busy(self, busy: bool, status: str) -> None:
        self.is_busy = busy
        self.status_var.set(status)
        state = tk.DISABLED if busy else tk.NORMAL
        self.open_button.configure(state=state)
        self.export_button.configure(state=state)
        self.root.configure(cursor="watch" if busy else "")

    def _on_tooth_selected(self, _event=None) -> None:
        selection = self.tooth_list.curselection()
        if selection:
            self.select_tooth(selection[0])

    def select_tooth(self, list_index: int) -> None:
        if not (0 <= list_index < len(self.teeth)):
            return
        self.selected_tooth = self.teeth[list_index]
        self.tooth_list.selection_clear(0, tk.END)
        self.tooth_list.selection_set(list_index)
        self.tooth_list.see(list_index)
        self._compute_analysis(reset_zoom=True)

    def step_tooth(self, direction: int) -> None:
        if not self.teeth:
            return
        current = self.selected_tooth.index - 1 if self.selected_tooth else 0
        self.select_tooth((current + direction) % len(self.teeth))

    def _params(self) -> AnalysisParams:
        smooth = int(round(self.smooth_var.get()))
        if smooth % 2 == 0:
            smooth += 1
        return AnalysisParams(
            edge_shrink_pct=float(self.edge_var.get()),
            darkness_threshold=float(self.dark_var.get()) / 100.0,
            black_level_pct=float(self.black_var.get()),
            min_contrast_pct=float(self.contrast_var.get()),
            min_width_px=float(self.width_var.get()),
            min_length_pct=float(self.length_var.get()),
            smooth_px=smooth,
            exclude_highlights=bool(self.highlight_var.get()),
        )

    def schedule_recompute(self) -> None:
        if self.recompute_job:
            self.root.after_cancel(self.recompute_job)
        self.recompute_job = self.root.after(220, self._compute_analysis)

    def _compute_analysis(self, reset_zoom: bool = False) -> None:
        self.recompute_job = None
        if self.image_bgr is None or self.selected_tooth is None:
            return
        try:
            self.analysis = analyze_tooth(self.image_bgr, self.selected_tooth, self._params())
        except Exception as exc:
            detail = traceback.format_exc()
            (WORKSPACE / "app_error.log").write_text(detail, encoding="utf-8")
            self.status_var.set(f"暗线分析失败：{exc}")
            return
        self._update_statistics()
        self._refresh_view(reset_zoom=reset_zoom)
        self.status_var.set(
            f"T{self.selected_tooth.index:02d}：找到 {len(self.analysis.candidates)} 条连续暗线候选。"
        )

    def _update_statistics(self) -> None:
        if self.analysis is None or self.selected_tooth is None:
            return
        candidates = self.analysis.candidates
        self.candidate_count_var.set(str(len(candidates)))
        self.total_length_var.set(f"{sum(item.skeleton_length_px for item in candidates)} px")
        self.branch_count_var.set(str(sum(len(item.branchpoints) for item in candidates)))
        self.tooth_info_var.set(f"{self.selected_tooth.confidence:.2f}")
        for row in self.candidate_table.get_children():
            self.candidate_table.delete(row)
        for candidate in candidates:
            self.candidate_table.insert(
                "",
                tk.END,
                values=(
                    candidate.kind,
                    f"{candidate.structure_score:.2f}",
                    f"{candidate.median_width_px:.1f}",
                    candidate.evidence_mode,
                ),
            )

    def set_view(self, view: str) -> None:
        if view != "overview" and self.analysis is None:
            return
        self.current_view = view
        self._refresh_view(reset_zoom=True)

    def _refresh_view(self, reset_zoom: bool = False) -> None:
        for key, button in self.view_buttons.items():
            active = key == self.current_view
            button.configure(bg=COLORS["blue_dark"] if active else COLORS["panel"])
        if self.image_bgr is None:
            return
        if self.current_view == "overview":
            self.display_bgr = render_overview(
                self.image_bgr,
                self.teeth,
                self.selected_tooth.index if self.selected_tooth else None,
                self.analysis,
            )
        elif self.analysis is not None:
            self.display_bgr = self.analysis.views[self.current_view]
        if reset_zoom:
            self.zoom = 1.0
        self._render_display()

    def fit_view(self) -> None:
        self.zoom = 1.0
        self._render_display()

    def change_zoom(self, factor: float) -> None:
        self.zoom = max(0.25, min(6.0, self.zoom * factor))
        self._render_display()

    def _on_mousewheel(self, event) -> None:
        self.change_zoom(1.16 if event.delta > 0 else 0.86)

    def _render_display(self) -> None:
        if self.display_bgr is None:
            width = max(100, self.canvas.winfo_width())
            height = max(100, self.canvas.winfo_height())
            self.canvas.coords(self.empty_text, width / 2, height / 2)
            return
        self.canvas.delete("all")
        rgb = cv2.cvtColor(self.display_bgr, cv2.COLOR_BGR2RGB)
        image = Image.fromarray(rgb)
        canvas_w = max(100, self.canvas.winfo_width() - 4)
        canvas_h = max(100, self.canvas.winfo_height() - 4)
        fit = min(canvas_w / image.width, canvas_h / image.height)
        scale = max(0.03, fit * self.zoom)
        display_w = max(1, int(image.width * scale))
        display_h = max(1, int(image.height * scale))
        image = image.resize((display_w, display_h), Image.Resampling.LANCZOS)
        self.photo = ImageTk.PhotoImage(image)
        self.canvas.create_image(0, 0, image=self.photo, anchor="nw")
        self.canvas.configure(scrollregion=(0, 0, display_w, display_h))

    def export_current(self) -> None:
        if self.image_path is None or self.selected_tooth is None or self.analysis is None:
            messagebox.showinfo("没有可导出的结果", "请先打开照片并选择一颗牙齿。")
            return
        parent = filedialog.askdirectory(title="选择导出目录")
        if not parent:
            return
        folder = Path(parent) / f"{self.image_path.stem}_T{self.selected_tooth.index:02d}_暗线证据"
        folder.mkdir(parents=True, exist_ok=True)
        overview = render_overview(
            self.image_bgr,
            self.teeth,
            self.selected_tooth.index,
            self.analysis,
        )
        write_image(folder / "00_全景轮廓与暗线.png", overview)
        order = ["tooth", "normalized", "heatmap", "candidate", "skeleton"]
        names = {
            "tooth": "01_单牙原图.png",
            "normalized": "02_光照归一化.png",
            "heatmap": "03_暗线热力图.png",
            "candidate": "04_候选暗区.png",
            "skeleton": "05_骨架与分叉.png",
        }
        for key in order:
            write_image(folder / names[key], self.analysis.views[key])
        payload = analysis_to_dict(
            str(self.image_path),
            str(MODEL_PATH),
            self.selected_tooth,
            self.analysis,
            self._params(),
        )
        (folder / "暗线坐标与参数.json").write_text(
            json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8"
        )
        self.status_var.set(f"已导出：{folder}")
        messagebox.showinfo("导出完成", f"已保存可视化图片、骨架坐标和处理参数：\n{folder}")


def install_exception_hook(root: tk.Tk) -> None:
    def report_exception(exc_type, exc_value, exc_traceback) -> None:
        detail = "".join(traceback.format_exception(exc_type, exc_value, exc_traceback))
        (WORKSPACE / "app_error.log").write_text(detail, encoding="utf-8")
        messagebox.showerror("程序错误", f"{exc_value}\n\n日志：{WORKSPACE / 'app_error.log'}")

    root.report_callback_exception = report_exception
    sys.excepthook = report_exception


def main() -> None:
    if sys.platform == "win32":
        try:
            import ctypes

            ctypes.windll.shcore.SetProcessDpiAwareness(1)
        except Exception:
            pass
    root = tk.Tk()
    install_exception_hook(root)
    DarklineLabApp(root)
    root.mainloop()


if __name__ == "__main__":
    main()
