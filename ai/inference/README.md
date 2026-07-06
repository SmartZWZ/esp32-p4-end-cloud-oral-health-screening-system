# Inference

Cloud and edge inference experiments live here.

## Server Environment

The current server YOLO environment is:

```text
Conda env: /root/miniconda3/envs/yolo-torch
PyTorch: xpu build
Ultralytics: available
OpenCV: available
Target GPU: Intel Arc A380
```

## Cloud YOLO HTTP Service

`cloud_yolo_service.py` exposes two inference endpoints for the deployed cloud
model:

- `POST /api/v1/yolo/image`
- `POST /api/v1/yolo/video-frame`

Both endpoints accept either multipart form data with a `file` field or raw
JPEG/PNG bytes in the request body. The response is JSON with class ids, class
names, confidence scores, and `xyxy` bounding boxes.

Runtime configuration:

- `YOLO_MODEL_PATH`, default `/opt/tooth-yolo/models/cloud_fine_det_best.pt`
- `YOLO_CONF`, default `0.25`
- `YOLO_IMGSZ`, default `640`

The model file is intentionally not committed.
