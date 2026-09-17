# 口腔照片模型交付包 v1

本包仅包含已完成验证、可直接推理的两个模型及其权重。**不包含训练数据集**。

| 模型参数 | 用途 | 输出类别 | 推荐输入尺寸 |
|---|---|---|---:|
| `weights/caries_yolov8s_best.pt` | 龋齿目标检测 | `caries` | 640 |
| `weights/alphadent_4class_960.pt` | 牙体修复相关实例分割 | `Abrasion`（磨耗）、`Filling`（充填体）、`Crown`（牙冠）、`Caries` | 960 |

## 已验证的性能范围

### 1. 龋齿检测模型

本项目独立测试集结果：Precision 0.845、Recall 0.914、mAP@0.5 0.935、mAP@0.5:0.95 0.758（319 张图、973 个目标）。

### 2. 磨耗 / 充填体 / 牙冠模型

模型来自 AlphaDent 的公开 YOLOv8x-seg 权重。本地以其公开验证集（83 张图、872 个实例）复测：Box mAP@0.5 0.701、Box mAP@0.5:0.95 0.511；Mask mAP@0.5 0.692、Mask mAP@0.5:0.95 0.469。

按掩码 mAP@0.5:0.95：磨耗 0.689、牙冠 0.703、充填体 0.372、龋齿 0.111。因此实际使用时，推荐将该模型用于**磨耗与牙冠**，充填体为辅助输出；龋齿仍应使用本包的专用龋齿模型。

## 环境安装

推荐 Windows + NVIDIA GPU。已安装合适的 PyTorch/CUDA 环境时，只需要：

```powershell
pip install -r requirements.txt
```

没有 GPU 也可使用 CPU，但 960px 分割模型会明显变慢。

## 快速推理

进入包目录后，对单张图片或一个图片文件夹执行：

```powershell
python .\infer.py --model caries --source D:\photos
python .\infer.py --model restoration --source D:\photos
```

也可以用 Windows 启动脚本：

```powershell
.\运行推理.ps1 -Model caries -Source D:\photos
.\运行推理.ps1 -Model restoration -Source D:\photos
```

默认结果保存到 `outputs\caries*` 或 `outputs\restoration*`：

- 标注后的图片/视频；
- `labels\` 内的 YOLO 格式预测结果；
- 预测置信度。

## 常用参数

```powershell
# 降低阈值以提高召回，需人工复核更多候选目标
python .\infer.py --model caries --source D:\photos --conf 0.15

# 使用 CPU 推理
python .\infer.py --model restoration --source D:\photos --device cpu

# 9 类模型不在本包内；本包的修复模型固定使用 4 类 960px 权重
```

## 使用边界

- 两个模型均用于口腔照片辅助筛查，不替代临床诊断、探诊或影像学检查。
- 龋齿模型的性能结论仅对应当前训练数据的拍摄条件与标注规范。
- AlphaDent 修复模型的公开验证集主要为标准化口内照片；跨设备、自拍照片、强反光或遮挡情况下应人工复核。
- 不将本包用于牙结石诊断、牙位编号或龋齿分型；这些任务在当前项目中未达到可交付标准。

## 来源与许可证提示

AlphaDent 权重、代码与数据来源：<https://github.com/ZFTurbo/AlphaDent>。使用前请遵守其仓库与数据集许可证。龋齿权重为本项目训练产物，使用时应遵守其原始数据集约束。
