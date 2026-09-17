# 口内照片牙科模型发布包

本包仅用于**口内可见光照片**的辅助筛查，不适用于 X 光片，也不能替代牙医诊断。

## 包含模型

| 模型 | 文件 | 输出 | 独立测试结果 |
|---|---|---|---|
| 单类龋齿检测 YOLOv8s | `weights/caries_yolov8s_best.pt` | 候选龋齿框和置信度 | Precision 0.845，Recall 0.914，mAP@0.5 0.935 |
| 单类牙结石分割 U-Net++/ResNet34 v3 | `weights/calculus_unetpp_resnet34_v3_inference.pt` | 青色半透明牙结石区域 | Dice 0.392，IoU 0.244，Precision 0.302，Recall 0.557 |

牙结石模型的指标来自同一公开数据源的固定测试划分，不是独立临床验证；在色素沉着、龋坏、牙龈炎、金属修复体和强反光场景可能误报。

## 环境

本机已创建两个 Conda 环境：

```powershell
conda activate dental-caries-yolo-gpu
conda activate dental-calculus-unetpp-gpu
```

如在另一台电脑使用，请在 Python 3.11 环境安装相应依赖：龋齿模型需要 `ultralytics`；牙结石模型需要 `torch`、`torchvision`、`segmentation-models-pytorch`、`Pillow`、`numpy`。

## 龋齿模型

双击 `tools/启动龋齿检测工具.ps1`，或在本发布包目录运行：

```powershell
powershell -ExecutionPolicy Bypass -File ".\tools\启动龋齿检测工具.ps1"
```

默认阈值为 0.25。模型的目标是初筛候选区域，建议由口腔医生复核。

## 牙结石模型

双击 `tools/启动牙结石分割.ps1`，选择口内照片后会在原图旁生成并打开 `*_calculus_overlay.png`。

或在 PowerShell 中运行：

```powershell
conda activate dental-calculus-unetpp-gpu
python tools/calculus_infer.py --image "C:\path\to\photo.jpg" --threshold 0.4
```

`0.4` 是在验证集上选出的 Dice 最优阈值。提高阈值会减少误报但可能漏检；建议在 0.35–0.55 之间按使用场景调整。

## 文件说明

- `weights/`：推理所需权重。
- `tools/calculus_infer.py`：牙结石单图推理脚本。
- `tools/启动牙结石分割.ps1`：Windows 文件选择启动器。
- `docs/`：训练、数据与局限说明。
