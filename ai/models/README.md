# Models

This directory stores the small YOLO weights needed by the course-design demo.

Model weights are tracked with Git LFS:

- `cloud_fine_det_best.pt`: cloud-side YOLO model currently deployed on the server.
- `edge_risk_best.pt`: lightweight edge-side risk model candidate for ESP32-P4 experiments.
- `edge_risk_yolo11n_320_int8.espdl`: quantized ESP-DL model for ESP32-P4 firmware.

Do not commit large training packages, raw datasets, or generated experiment
outputs here. Keep those files on the server, local disk, or object storage.

After cloning the repository, install Git LFS before pulling model files:

```bash
git lfs install
git lfs pull
```

Current cloud inference endpoint:

```text
POST https://yolo.chijing.xyz:2437/api/v1/yolo/image
POST https://yolo.chijing.xyz:2437/api/v1/yolo/video-frame
```

Current ESP32-P4 edge model artifact:

```text
ai/models/edge_risk_yolo11n_320_int8.espdl
```

Expected output tensor:

```text
[1, 5, 2100]
```

Channel meaning:

```text
0..3  box xywh in stride units
4     lesion logit
```
