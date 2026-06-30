# Training

这里放 AI 训练和数据转换脚本。

## 端侧 lesion 检测数据集

转换脚本：

```powershell
python ai\training\convert_datasetninja_to_yolo_lesion_det.py `
  --src D:\Tooth-YoloV11\dentalai-DatasetNinja `
  --out D:\Tooth-YoloV11\dataset_yolo_lesion_det
```

转换逻辑：

- `Caries/Cavity/Crack` 合并为 `lesion`
- `Tooth` 忽略
- polygon 转 YOLO bbox
- 保留空标签图片作为负样本

## 训练命令

320 输入端侧基线：

```powershell
& 'E:\conda_envs\yolov11\Scripts\yolo.exe' detect train `
  model=yolo11n.pt `
  data=D:\Tooth-YoloV11\dataset_yolo_lesion_det\data.yaml `
  imgsz=320 `
  epochs=200 `
  batch=32 `
  device=0 `
  workers=0 `
  patience=50 `
  amp=True `
  seed=42 `
  project=D:\Tooth-YoloV11\runs_edge `
  name=lesion_yolo11n_320
```

640 诊断性实验：

```powershell
& 'E:\conda_envs\yolov11\Scripts\yolo.exe' detect train `
  model=yolo11n.pt `
  data=D:\Tooth-YoloV11\dataset_yolo_lesion_det\data.yaml `
  imgsz=640 `
  epochs=80 `
  batch=16 `
  device=0 `
  workers=0 `
  patience=25 `
  amp=True `
  seed=42 `
  mosaic=0 `
  mixup=0 `
  copy_paste=0 `
  erasing=0 `
  scale=0.2 `
  translate=0.05 `
  project=D:\Tooth-YoloV11\runs_edge `
  name=lesion_yolo11n_640_nomosaic
```
