# 牙齿实例分割与微龋暗线候选分析工具

## 1. 工具用途

本工具处理普通口内彩色照片，不使用 X 光图像。处理流程分为两部分：

1. 使用 `YOLO11s-seg` 实例分割模型找出图中的每颗牙齿，得到每颗牙齿的掩膜、轮廓坐标和外接框。
2. 在用户选择的单颗牙齿内部进行光照归一化、暗线增强、局部反差检测、黑色核心检测、真实线宽测量、连续域筛选和骨架化，输出线型、Y 型或网状暗线候选。

输出是图像处理得到的研究候选，不是临床诊断。天然窝沟、色素沉着、裂纹、修复体边缘、气泡及反光边缘仍可能形成候选，需要人工复核。

## 2. 发布包目录

```text
牙齿轮廓与微龋暗线分析器_v3/
├─ app.py                         图形界面
├─ darkline_core.py               牙齿分割和暗线分析核心算法
├─ models/
│  └─ tooth_instance_best.pt      牙齿实例分割权重
├─ deployment_assets/
│  └─ 单牙效果展示/                示例输入、各阶段图片和坐标 JSON
├─ 启动工具.bat                    当前电脑和已配置环境的无终端启动入口
├─ 启动工具_显示日志.bat           排错用启动入口
├─ 验证安装.py                     检查依赖、权重和默认参数
├─ 运行单牙示例.py                 重现本文档中的单牙示例
├─ requirements.txt               Python 依赖
├─ environment.yml                Conda 环境定义
├─ MODEL_INFO.json                模型来源和验证指标摘要
└─ SHA256SUMS.txt                  文件完整性校验
```

程序优先加载发布包内的 `models/tooth_instance_best.pt`，移动整个文件夹后仍能正常工作，不依赖原训练目录。

## 3. 当前推荐配置

发布版已把以下数值设为界面和核心算法的默认配置：

| 参数 | 默认值 | 内部值 | 作用 |
|---|---:|---:|---|
| 边缘内缩 | 6% | `6.0` | 将牙齿轮廓向内收缩，减少牙龈、邻牙和轮廓边缘误报 |
| 暗度阈值 | 60.2% | `0.602` | 暗线综合响应达到该值后才进入候选区域 |
| 黑色核心上限 | 35% | `35.0` | 原图亮度不高于该比例的像素视为绝对黑色证据 |
| 最小局部反差 | 5% | `5.0` | 暗线相对邻近牙面的最低亮度差 |
| 最小线宽 | 0.5 px | `0.5` | 黑色或局部反差证据骨架上的最低横向宽度 |
| 最短线长 | 18% 牙宽 | `18.0` | 骨架总长度低于牙齿宽度 18% 的候选被过滤 |
| 平滑尺度 | 5 px | `5` | 暗线响应的高斯平滑核尺寸，用于连接轻微断点 |
| 排除镜面反光 | 开启 | `True` | 排除亮度高、饱和度较低的牙面反光及其邻域 |

这组参数中，`暗度阈值 60.2%` 决定候选生成强度；`黑色核心上限 35%` 和 `最小局部反差 5%` 提供颜色证据；`最小线宽 0.5 px` 与 `最短线长 18%` 提供几何证据。只有同时满足连续性、颜色/反差和形状条件的区域才会保留。

## 4. 当前电脑直接启动

本机已验证的 Miniconda 环境为：

```text
D:\SoftwareLocation\EnvironmentManager\miniconda3\envs\dental-caries-yolo-gpu
```

直接双击：

```text
启动工具.bat
```

如果窗口未出现，双击 `启动工具_显示日志.bat`，终端会保留 Python 报错。界面异常也会写入同目录的 `app_error.log`。

也可以在 PowerShell 中启动：

```powershell
& 'D:\SoftwareLocation\EnvironmentManager\miniconda3\envs\dental-caries-yolo-gpu\python.exe' '.\app.py'
```

## 5. 在另一台 Windows 电脑部署

### 5.1 硬件和系统

- Windows 10/11 64 位；
- Python 3.11；
- 内存建议 8 GB 以上；
- NVIDIA 显卡不是必需，但建议使用。当前验证设备为 RTX 5060 Laptop GPU；
- 无 NVIDIA 显卡时程序会自动切换至 CPU，牙齿分割速度会明显下降。

### 5.2 创建 Conda 环境

打开“Miniconda Prompt”，进入解压后的发布包目录：

```powershell
cd 'D:\你的目录\牙齿轮廓与微龋暗线分析器_v3'
conda create -n dental-darkline python=3.11 -y
conda activate dental-darkline
python -m pip install --upgrade pip
```

NVIDIA 显卡部署：

```powershell
pip install torch torchvision --index-url https://download.pytorch.org/whl/cu128
pip install -r requirements.txt
```

仅 CPU 部署：

```powershell
pip install torch torchvision --index-url https://download.pytorch.org/whl/cpu
pip install -r requirements.txt
```

如果目标显卡或驱动不适合 CUDA 12.8，应按目标电脑的驱动选择 PyTorch 官方提供的 CUDA 版本。`requirements.txt` 没有固定 PyTorch，就是为了避免自动安装与目标显卡不匹配的构建。

### 5.3 验证部署

```powershell
python '.\验证安装.py'
```

正常输出应包含“安装验证通过”、依赖版本、推理设备和模型路径。然后运行：

```powershell
python '.\运行单牙示例.py'
```

结果会写入 `示例运行输出`。确认能生成全景轮廓、五张单牙阶段图和 JSON 后，说明模型加载、GPU/CPU 推理、暗线算法及中文路径读写均正常。

### 5.4 新电脑启动界面

在已激活环境的 Miniconda Prompt 中执行：

```powershell
python '.\app.py'
```

`启动工具.bat` 会优先识别当前电脑原有环境；在其他电脑上，如果 Conda 已加入 PATH，它会尝试运行名为 `dental-darkline` 的环境。

## 6. 图形界面操作

1. 点击“打开口内照片”，选择 JPG、JPEG、PNG、BMP 或 TIFF。
2. 模型先分割每颗牙齿；左侧全景图会显示牙齿轮廓。
3. 从牙齿列表选择 T01、T02 等。这里是图片内导航编号，不是 FDI 牙位编号。
4. 切换“单牙原图、光照归一化、暗线热力图、候选暗区、骨架与分叉”检查证据链。
5. 默认参数已经设为第 3 节配置；滑动参数后当前牙齿会自动重新计算。
6. 点击“导出当前证据”，保存图片与 `暗线坐标与参数.json`。

鼠标滚轮用于缩放，按住鼠标右键拖动用于平移。

## 7. 单牙效果展示

以下示例使用发布包中的 `示例输入.jpg`。模型在整图中检出 3 颗牙齿，选择导航编号 T02，牙齿实例置信度为 `0.9362`。暗线阶段严格使用第 3 节推荐配置，最终保留 1 个连续网状候选：

- 结构分数：`0.972`，仅用于候选排序，不是患龋概率；
- 骨架长度：`69 px`；
- 黑色核心占比：`91.3%`；
- 中位证据线宽：`1.8 px`；
- 中位局部反差：`23.88%`。

![单牙五阶段总览](deployment_assets/单牙效果展示/单牙五阶段总览.png)

从左至右依次为：

1. 单牙掩膜和牙齿轮廓；
2. 光照归一化结果；
3. 多尺度暗线响应热力图；
4. 通过全部门控的连续暗线区域；
5. 一像素骨架、端点和分叉点。

完整牙列定位效果如下，青色轮廓来自牙齿实例分割模型，选中的 T02 会显示暗线叠加结果：

![全景牙齿轮廓](deployment_assets/单牙效果展示/00_全景牙齿轮廓.png)

各阶段原尺寸图片与原图坐标位于 `deployment_assets/单牙效果展示`。执行 `运行单牙示例.py` 可以重新生成相同证据链。

## 8. 输出 JSON 说明

坐标系以原始输入图片左上角为 `(0, 0)`：x 向右，y 向下。主要字段包括：

| 字段 | 含义 |
|---|---|
| `parameters` | 本次实际使用的全部参数 |
| `tooth.bbox_xyxy` | 牙齿外接框 `[x1, y1, x2, y2]` |
| `tooth.contour_xy` | 牙齿实例轮廓坐标 |
| `candidate_count` | 当前单牙保留的暗线候选数量 |
| `candidates[].skeleton_xy` | 暗线中心骨架的原图坐标 |
| `candidates[].contour_xy` | 暗线候选外轮廓坐标 |
| `endpoints` / `branchpoints` | 端点和分叉点原图坐标 |
| `median_width_px` | 在原始颜色/反差证据上的中位线宽 |
| `black_core_ratio` | 骨架点中满足黑色核心条件的比例 |
| `local_contrast_pct` | 候选相对局部牙面的中位亮度反差 |
| `evidence_mode` | 深色、局部反差或二者共同支持 |

后续数字图像处理应优先使用 `tooth.contour_xy` 限定牙齿范围，再使用 `skeleton_xy`、`branchpoints` 和宽度统计做结构分析，不建议从展示图片反向提取坐标。

## 9. Python 调用方式

```python
from ultralytics import YOLO
from darkline_core import AnalysisParams, analyze_tooth, detect_teeth, read_image

image = read_image("口内照片.jpg")
model = YOLO("models/tooth_instance_best.pt")
teeth = detect_teeth(model, image, conf=0.25)

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

analysis = analyze_tooth(image, teeth[0], params)
for candidate in analysis.candidates:
    print(candidate.kind, candidate.skeleton_xy, candidate.median_width_px)
```

`detect_teeth` 在 CUDA 可用时使用 GPU，否则自动使用 CPU。模型推理尺寸固定为 `1024`，置信度默认 `0.25`，NMS IoU 为 `0.65`。

## 10. 模型信息

- 任务：单类牙齿实例分割；
- 架构：YOLO11s-seg；
- 权重：`models/tooth_instance_best.pt`；
- 训练分辨率：1024；
- 训练轮数：200；
- 优化器：AdamW；
- 本地验证记录中的最高掩膜 `mAP@0.5 = 0.96395`，对应第 77 轮；
- 同轮掩膜 Precision `0.91336`、Recall `0.92111`、`mAP@0.5:0.95 = 0.81238`。

这些指标来自现有数据划分，只说明该验证集上的实例分割表现，不等于不同手机、光线、人群和牙位上的临床泛化性能。

## 11. 常见问题

### 启动时报 `No module named ultralytics`

当前 Python 不是部署环境。先执行 `conda activate dental-darkline`，再运行 `python app.py`。

### 找不到 `models/tooth_instance_best.pt`

不要只复制 `app.py`。必须保留整个发布包结构，并确认权重文件存在且 SHA256 与 `SHA256SUMS.txt` 一致。

### CUDA 不可用或显存不足

先运行 `验证安装.py` 查看推理设备。CUDA 不可用时程序会转 CPU；显存不足时关闭其他占用 GPU 的程序。模型默认只分析一张图片，正常情况下不需要调整 batch。

### 普通暗区仍被判为暗线

先提高“暗度阈值”或降低“黑色核心上限”，再提高“最小局部反差”。每次只改一个参数并保存导出 JSON，便于比较。

### 极细纹理仍被保留

将“最小线宽”从 `0.5 px` 提高到 `1.0–1.5 px`。当前 `0.5 px` 是偏向保留极轻微暗线的高灵敏度配置，误报控制主要依赖暗度、局部反差、长度和形状门控。

### 真暗线被截断或漏检

依次尝试降低暗度阈值、降低最短线长、降低最小局部反差。不要一次同时大幅修改多个参数，否则无法判断哪项变化有效。

## 12. 完整性与使用边界

可在 PowerShell 中校验发布包：

```powershell
Get-FileHash '.\models\tooth_instance_best.pt' -Algorithm SHA256
```

将结果与 `SHA256SUMS.txt` 对照。该工具适合算法研究、候选筛查和人工标注辅助，不应单独用于患者诊断、治疗决策或替代口腔医生检查。
