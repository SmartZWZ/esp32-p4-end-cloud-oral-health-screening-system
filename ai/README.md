# AI 与单片机云端接口说明

本目录用于保存模型训练、云端推理、数据处理和部署说明。模型权重、原始数据集和训练输出不要提交到 Git，统一放在服务器或本地私有目录。

## 当前云端服务

由于家宽环境没有标准 `80/443` 入站端口，所有公网服务都需要带端口访问。

推荐单片机组使用 HTTPS 端口：

```text
HTTPS 端口: 2437
HTTP 端口: 1437
```

优先使用 HTTPS：

```text
https://video.chijing.xyz:2437
https://image.chijing.xyz:2437
https://yolo.chijing.xyz:2437
```

如果单片机 TLS 暂时调不通，可以先用 HTTP 调试：

```text
http://video.chijing.xyz:1437
http://image.chijing.xyz:1437
http://yolo.chijing.xyz:1437
```

## 1. 视频流上传

用途：ESP32-P4 将实时预览视频帧传到服务器，Web 端或调试人员可以查看画面。

推荐接口：

```text
WSS 连接: wss://video.chijing.xyz:2437/esp32
WS 连接:  ws://video.chijing.xyz:1437/esp32
```

协议约定：

- WebSocket 二进制消息。
- 每条消息发送一帧 JPEG 图片。
- 单帧建议控制在 `800 KB` 以下，服务器最大接受约 `8 MB`。
- 建议帧率先从 `5-10 FPS` 调试，确认稳定后再提高。

预览与状态：

```text
https://video.chijing.xyz:2437/
https://video.chijing.xyz:2437/health
```

健康检查返回示例：

```json
{
  "port": 8888,
  "upload": "/esp32",
  "preview": "/",
  "frames": 6814,
  "bytes": 55744328,
  "viewers": 0,
  "publishers": 12,
  "latestAt": "2026-07-06T01:24:41.012Z",
  "latestBytes": 5109
}
```

说明：

- `video.chijing.xyz` 只负责视频流接收和预览。
- 它不会自动跑 YOLO 推理。
- 如果需要对某一帧做检测，请把该帧作为 JPEG 图片发到 YOLO 推理接口。

## 2. 普通拍照上传

用途：ESP32-P4 拍一张照片上传到服务器，用于图片预览、调试和留存。

接口：

```text
POST https://image.chijing.xyz:2437/upload
POST http://image.chijing.xyz:1437/upload
```

支持两种请求体：

1. `Content-Type: image/jpeg`，body 直接放 JPEG 字节。
2. `multipart/form-data`，字段名建议使用 `file`。

状态与预览：

```text
https://image.chijing.xyz:2437/
https://image.chijing.xyz:2437/health
```

说明：

- `image.chijing.xyz` 用于原始拍照上传和预览。
- 它不会返回 YOLO 检测框。
- 需要检测结果时，请直接调用下面的 YOLO 推理接口。

## 3. 拍照后云端 YOLO 推理

用途：ESP32-P4 拍照后把 JPEG 上传给云端模型，服务器返回检测框、类别和置信度。

接口：

```text
POST https://yolo.chijing.xyz:2437/api/v1/yolo/image
POST http://yolo.chijing.xyz:1437/api/v1/yolo/image
```

查询参数：

```text
conf=0.25     置信度阈值，默认 0.25
imgsz=640     推理输入尺寸，默认 640
```

推荐请求：

```bash
curl -X POST \
  -F "file=@sample.jpg;type=image/jpeg" \
  "https://yolo.chijing.xyz:2437/api/v1/yolo/image?conf=0.25&imgsz=640"
```

如果单片机更方便发送裸 JPEG：

```bash
curl -X POST \
  -H "Content-Type: image/jpeg" \
  --data-binary "@sample.jpg" \
  "https://yolo.chijing.xyz:2437/api/v1/yolo/image?conf=0.25&imgsz=640"
```

返回示例：

```json
{
  "ok": true,
  "model_path": "/opt/tooth-yolo/models/cloud_fine_det_best.pt",
  "image_shape": [2528, 3419, 3],
  "elapsed_ms": 53.27,
  "detections": [
    {
      "class_id": 1,
      "class_name": "caries",
      "confidence": 0.8071,
      "box_xyxy": [2177.6, 452.2, 2550.7, 721.5]
    }
  ]
}
```

字段说明：

- `image_shape`: 原图尺寸，格式为 `[height, width, channels]`。
- `elapsed_ms`: 云端模型推理耗时，单位毫秒。
- `detections`: 检测结果数组。
- `class_name`: 模型类别名，例如 `caries`。
- `confidence`: 置信度，范围 `0-1`。
- `box_xyxy`: 检测框，格式为 `[x1, y1, x2, y2]`，单位是原图像素。

单片机侧处理建议：

1. 拍照得到 JPEG。
2. 先本地做基本质量检查，例如过暗、过曝、模糊。
3. 上传 JPEG 到 `/api/v1/yolo/image`。
4. 根据返回的 `box_xyxy` 在屏幕上叠加红框。
5. 只把结果作为健康筛查提示，不作为医学诊断。

## 4. 视频帧云端 YOLO 推理

用途：如果单片机想对视频中的某一帧做云端检测，可以把该帧 JPEG 发到视频帧推理接口。

接口：

```text
POST https://yolo.chijing.xyz:2437/api/v1/yolo/video-frame
POST http://yolo.chijing.xyz:1437/api/v1/yolo/video-frame
```

请求格式和返回格式与 `/api/v1/yolo/image` 完全一致。

建议：

- 不要每一帧都请求云端推理，带宽和延迟压力会比较大。
- 推荐每 `1-2` 秒抽一帧做检测，或者用户点击“拍照检测”时再推理。
- 实时预览走 `video.chijing.xyz`，检测走 `yolo.chijing.xyz`。

## 5. 当前模型效果提醒

当前部署模型：

```text
/opt/tooth-yolo/models/cloud_fine_det_best.pt
```

一次真实口腔图片测试结果：

- `conf=0.25`
- 返回 8 个 `caries` 检测框。
- 推理耗时约 `50-90 ms`。

注意：

- 模型还处在课程设计原型阶段。
- 对明显龋坏区域有响应，但不能保证覆盖所有病灶。
- 低阈值 `conf=0.1` 会返回更多候选框，但误检也会增加。
- 报告和界面必须标注“仅供健康筛查参考，不作为医学诊断”。

## 6. 接口分工

```text
video.chijing.xyz:2437
  - 单片机视频流上传
  - WebSocket JPEG 帧预览

image.chijing.xyz:2437
  - 单张图片上传和预览
  - 不做模型推理

yolo.chijing.xyz:2437
  - 单张图片 YOLO 检测
  - 视频抽帧 YOLO 检测
  - 返回检测框 JSON

api.chijing.xyz:2437
  - App/Web 后端
  - 用户、设备、筛查记录、报告管理
```

## 7. 单片机组最小联调流程

1. 打开视频 WebSocket：

```text
wss://video.chijing.xyz:2437/esp32
```

2. 连续发送 JPEG 二进制帧。

3. 拍照后上传图片推理：

```text
POST https://yolo.chijing.xyz:2437/api/v1/yolo/image?conf=0.25&imgsz=640
```

4. 读取返回的 `detections`。

5. 在屏幕上把 `box_xyxy` 映射到当前显示分辨率，叠加风险框。

## 8. Voice Control API

用途：ESP32-P4 麦克风录音后，把音频上传到云端。云端先调用 ASR 得到文字，再用 Qwen/规则解析成固定设备指令，最后返回给单片机执行。

接口：

```text
POST https://api.chijing.xyz:2437/api/v1/voice/commands
POST http://api.chijing.xyz:1437/api/v1/voice/commands
```

健康检查：

```text
GET https://api.chijing.xyz:2437/api/v1/voice/health
```

请求格式：`multipart/form-data`

字段：

- `file`: 必填，录音文件，建议先用 `wav`、`mp3`、`m4a`、`opus` 或 `pcm`。
- `device_sn`: 可选，设备序列号。
- `language`: 可选，默认 `zh`。
- `asr_text`: 可选，仅调试用；传了这个字段时服务器会跳过 ASR，直接解析文字。

请求示例：

```bash
curl -X POST \
  -F "device_sn=esp32-p4-001" \
  -F "language=zh" \
  -F "file=@command.wav;type=audio/wav" \
  "https://api.chijing.xyz:2437/api/v1/voice/commands"
```

调试示例：

```bash
curl -X POST \
  -F "asr_text=拍照" \
  -F "file=@command.wav;type=audio/wav" \
  "https://api.chijing.xyz:2437/api/v1/voice/commands"
```

返回示例：

```json
{
  "ok": true,
  "status": "ok",
  "command": "take_photo",
  "text": "拍照",
  "confidence": 0.9,
  "reason": "Matched photo capture keywords.",
  "source": "rule",
  "asr_configured": false,
  "llm_used": false,
  "audio_url": "/uploads/voice/xxx.wav",
  "device_sn": "esp32-p4-001",
  "raw": {
    "asr": null,
    "llm": null
  }
}
```

单片机只需要读取 `command` 字段：

```text
take_photo            拍照
start_edge_detection  开始端侧检测
stop_edge_detection   停止端侧检测
start_camera          打开摄像头
stop_camera           关闭摄像头
noop                  没有明确动作，不执行
```

说明：

- 服务器目前已经支持接口、音频保存、ASR 转写接入点、Qwen/OpenAI-compatible LLM 接入点和关键词兜底解析。
- Qwen3-3.5B 适合做“文字 -> 指令”的解析；语音转文字建议仍使用 ASR API 或本地 Whisper/faster-whisper。
- 如果服务器没有配置 ASR，真实音频会被保存，返回 `status=asr_unconfigured` 和 `command=noop`；联调时可以先传 `asr_text` 验证固件动作。
