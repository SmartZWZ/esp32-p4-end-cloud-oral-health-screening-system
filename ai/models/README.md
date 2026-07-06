# Models

This directory stores the small YOLO weights needed by the course-design demo.

Model weights are tracked with Git LFS:

- `cloud_fine_det_best.pt`: cloud-side YOLO model currently deployed on the server.
- `edge_risk_best.pt`: lightweight edge-side risk model candidate for ESP32-P4 experiments.

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
