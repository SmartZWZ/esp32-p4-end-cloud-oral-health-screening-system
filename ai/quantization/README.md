# ESP-DL Quantization

This directory contains the edge-side YOLO11 quantization script for ESP32-P4.

The default model is:

```text
ai/models/edge_risk_best.pt
```

The default output is:

```text
outputs/edge_risk_yolo11n_320_int8.espdl
```

## Environment

Use a Python environment that can run Ultralytics YOLO and Espressif ESP-PPQ:

```powershell
pip uninstall -y ppq
pip install git+https://github.com/espressif/esp-ppq.git
pip install ultralytics pillow numpy torch
```

## Run

Use representative oral images for calibration. The validation split is usually
better than random internet images because quantization ranges should match the
camera/domain used by the device.

```powershell
python ai\quantization\quantize_espdl_yolo11.py `
  --weights ai\models\edge_risk_best.pt `
  --image-dir D:\Tooth-YoloV11\dataset_yolo_lesion_det\images\val `
  --img-size 320 `
  --target esp32p4 `
  --output outputs\edge_risk_yolo11n_320_int8.espdl
```

If `data.yaml` exists, the script can discover the validation images:

```powershell
python ai\quantization\quantize_espdl_yolo11.py `
  --data-yaml D:\Tooth-YoloV11\dataset_yolo_lesion_det\data.yaml
```

Before running the expensive quantization step, do a dry run:

```powershell
python ai\quantization\quantize_espdl_yolo11.py --dry-run
```

## Output Contract

The script patches the YOLO Detect head for ESP-DL export:

- DFL is rewritten without the fixed-weight Conv2d.
- Box coordinates are exported in stride units to reduce INT8 range pressure.
- Class scores are raw logits; the firmware should apply sigmoid.

For the current single-class lesion model at `320x320`, the expected tensor is:

```text
[1, 5, 2100]
```

Channel meaning:

```text
0..3  box xywh in stride units
4     lesion logit
```

Firmware post-process:

1. Map each anchor to its stride: 40x40 anchors use stride 8, 20x20 use 16,
   10x10 use 32.
2. Convert boxes back to pixels by multiplying xywh by the corresponding stride.
3. Convert logits to confidence with sigmoid.
4. Run NMS on the device, or send candidates to the cloud for debugging.

## Notes

- Do not commit generated `.espdl` files unless the team explicitly decides to
  version release artifacts.
- If ESP-PPQ fails on a YOLO Detect operator, first try `--dry-run` and confirm
  the Torch output shape. Then retry with `--no-detect-patch` to isolate whether
  the patch or ESP-PPQ exporter is failing.
