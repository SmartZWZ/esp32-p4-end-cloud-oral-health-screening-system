# Inference

这里放模型导出、OpenVINO 推理和 benchmark 脚本。

目标部署路线：

```text
PyTorch best.pt
  -> ONNX
  -> OpenVINO IR
  -> Intel Arc A380 GPU 推理
```

服务器环境已验证：

```text
Conda env: /root/miniconda3/envs/yolo-torch
PyTorch: 2.12.1+xpu
OpenVINO: 2024.6.0
GPU: Intel Arc A380
FFmpeg: VAAPI/QSV 可用
```

OpenVINO benchmark 示例：

```bash
/root/miniconda3/bin/conda run -n yolo-torch benchmark_app \
  -m path/to/model.xml \
  -d GPU
```
