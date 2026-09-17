# AIRC-LABDEN 牙齿 FDI 编号训练

本项目使用 AIRC-LABDEN 的口内 RGB 照片训练 20 类牙位检测模型。当前类别为：

`11–15, 21–25, 31–35, 41–45`

这不是完整牙列模型：磨牙、乳牙、种植牙和未覆盖的牙位不能输出 FDI 编号。

## 标注转换

原始标签的每行包含“菌斑标志 + 托槽周围区域框 + 牙位 ID”。同一张图片中，同一个牙位 ID 的四个区域框会被合并成一个单牙检测框；菌斑标志在本模型中不使用。

默认转换只使用未增强的原图，并沿用 AIRC 官方的患者级 train/validation/test 划分。这样验证和测试均不含同一患者或其派生增强图。源数据中的空标签图片默认排除，待人工核验后再决定是否作为负样本使用。

```powershell
python prepare_airc_dataset.py `
  --source "D:\Downloads\EdgeDownload\mendeley-dataset-materials_Part_1\mendeley-dataset-materials_Part_1" `
  --output "C:\tooth-numbering-data\airc_fdi20_original"
```

转换完成后检查：

- `data/airc_fdi20_original/audit_report.json`
- `data/airc_fdi20_original/previews/`

若首轮基线完成后需要比较源数据自带增强的收益，另建一个输出目录并添加 `--include-derived-train`。验证和测试仍只使用原图。

## 训练

```powershell
.\train_baseline.ps1
```

默认使用 `yolo11s.pt`、1024 图像尺寸、20 类 FDI、150 epochs。训练生成于 `runs/yolo11s_fdi20_original/`。

## 测试集 FDI 精确匹配评估

```powershell
python evaluate_fdi.py `
  --weights ".\runs\yolo11s_fdi20_original\weights\best.pt" `
  --dataset ".\data\airc_fdi20_original" `
  --output ".\runs\yolo11s_fdi20_original\fdi_test_report.json"
```

除 Ultralytics 的 mAP 外，该报告还计算“预测框与真实牙框 IoU ≥ 0.5 且 FDI 类别相同”的逐牙精确匹配率。
