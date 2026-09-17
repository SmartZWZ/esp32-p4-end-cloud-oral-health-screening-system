# 齿镜本地模型工作进程

网站和数据库部署在云服务器，PyTorch/YOLO 推理实际运行在 Windows 本地电脑的：

```text
D:\chijing_model_agent
```

`model_service/worker.py` 是随网站代码保存的工作进程源文件，用于更新本地副本；不要在当前低配云服务器上恢复 PyTorch 模型环境。

## 支持的模型

| 流水线 | 权重 | 功能 |
| --- | --- | --- |
| `caries` | `caries_yolov8s_best.pt` | 龋齿候选框 |
| `both` | `caries_yolov8s_best.pt`、`alphadent_4class_960.pt` | 龋齿、磨耗、充填体和牙冠候选框 |
| `dental_seg` | `dental_seg_yolo11n_best.pt` | 龋齿、窝洞、裂纹和牙齿的实例分割轮廓 |
| `calculus_seg` | `calculus_seg_ensemble_v5.pt` | SegFormer-B2 与 DeepLabV3+ 融合的牙结石语义分割轮廓 |
| `all_models` | 上述全部权重 | 依次运行 caries、both、dental_seg、calculus_seg；单阶段失败自动重试一次 |

模型权重只保存在本地：

```text
D:\chijing_model_agent\weights
```

不要把 `.pt` 权重上传到网站目录。

## 更新本地工作端

把本目录中的 `worker.py` 和 `calculus_model.py` 复制到：

```text
D:\chijing_model_agent\worker.py
D:\chijing_model_agent\calculus_model.py
```

把牙结石发布包中的 `best.pt` 复制并重命名为：

```text
D:\chijing_model_agent\weights\calculus_seg_ensemble_v5.pt
```

在现有本地 Python 环境补充分割模型依赖；不要重装或替换当前 CUDA PyTorch：

```powershell
& "D:\SoftwareLocation\EnvironmentManager\miniconda3\python.exe" -m pip install `
  "click==8.1.8" "huggingface-hub==0.36.0" `
  "segmentation-models-pytorch==0.5.0"
```

然后重启计划任务：

```powershell
Stop-ScheduledTask -TaskName "Chijing Local Model Worker"
Start-ScheduledTask -TaskName "Chijing Local Model Worker"
```

查看状态与日志：

```powershell
Get-ScheduledTask -TaskName "Chijing Local Model Worker" |
  Select-Object TaskName, State

Get-Content D:\chijing_model_agent\logs\worker.log -Wait
```

网页任务中的 `model_pipeline` 会决定本次加载哪组权重；`config.ps1` 中的 `CHIJING_MODEL_PIPELINE` 只是在旧任务没有提供模型标识时使用的后备值。

## 云服务器要求

云服务器只保存任务、图片和结果。确认旧的服务器模型服务保持关闭：

```bash
systemctl disable --now chijing-model-worker.timer 2>/dev/null || true
systemctl stop chijing-model-worker.service 2>/dev/null || true
```

增加牙结石模型后，需要在现有数据库执行：

```text
database_calculus_seg_model_migration.sql

启用“全部模型联合分析”前，还需要在当前数据库执行：

```text
database_all_models_migration.sql
```

联合任务会实时回传 1/4～4/4 进度。某个阶段重试后仍失败时，其余成功阶段仍会保存为“部分完成”；四个阶段都失败时，整条任务才会标记为失败。
```
