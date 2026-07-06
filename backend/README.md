# Backend

FastAPI backend for the ESP32-P4 end-cloud oral health screening system.

This service is shared by three clients:

- ESP32-P4 device: upload screening images, edge results, and device status.
- App: manage personal/family members and view reports.
- Web: manage records, devices, screening batches, and reports.

## Run Locally

```bash
cd backend
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
Copy-Item .env.example .env
uvicorn app.main:app --reload
```

Open:

- Swagger: `http://127.0.0.1:8000/docs`
- Health: `http://127.0.0.1:8000/api/v1/health`

## Run With Docker

```bash
cd backend
cp .env.example .env
docker compose up -d --build
```

Persistent runtime data is stored under `backend/storage/`, including the
SQLite database and uploaded images. Do not commit that directory.

## Implemented MVP

- Auth: register, login, JWT token, current user.
- Members: family member or student profile.
- Devices: bind device and update device status.
- Screenings: create a screening session, attach edge result, upload images,
  complete screening.
- Reports: generate and query a basic screening report.
- Voice control: accept MCU audio, transcribe it with ASR, and map text to a
  fixed device command.

## Main API

- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `GET /api/v1/auth/me`
- `POST /api/v1/members`
- `GET /api/v1/members`
- `POST /api/v1/devices/bind`
- `POST /api/v1/devices/{device_id}/status`
- `POST /api/v1/screenings`
- `POST /api/v1/screenings/{screening_id}/images`
- `POST /api/v1/screenings/{screening_id}/complete`
- `GET /api/v1/reports`
- `GET /api/v1/reports/screenings/{screening_id}`
- `GET /api/v1/voice/health`
- `POST /api/v1/voice/commands`

## Voice Control API

MCU audio command upload:

```bash
curl -X POST \
  -F "device_sn=esp32-p4-001" \
  -F "language=zh" \
  -F "file=@command.wav;type=audio/wav" \
  "https://api.chijing.xyz:2437/api/v1/voice/commands"
```

During development, `asr_text` can be sent to test command parsing without a
real ASR provider:

```bash
curl -X POST \
  -F "asr_text=拍照" \
  -F "file=@command.wav;type=audio/wav" \
  "http://127.0.0.1:8000/api/v1/voice/commands"
```

Response example:

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

Supported `command` values:

- `take_photo`: 拍照并上传最新图片。
- `start_edge_detection`: 开始端侧检测。
- `stop_edge_detection`: 停止端侧检测。
- `start_camera`: 打开摄像头。
- `stop_camera`: 关闭摄像头。
- `noop`: 没有明确动作。

Optional runtime configuration:

```env
VOICE_ASR_URL=
VOICE_ASR_API_KEY=
VOICE_ASR_MODEL=
VOICE_LLM_BASE_URL=http://host.docker.internal:8001/v1
VOICE_LLM_API_KEY=
VOICE_LLM_MODEL=Qwen3-3.5B
```

`VOICE_LLM_BASE_URL` expects an OpenAI-compatible chat completions service.
If Qwen3-3.5B is served by vLLM, LMDeploy, Ollama OpenAI API, or another
compatible runtime, the backend can use it for command selection. If it is not
configured, the backend falls back to deterministic keyword rules.

## Existing ESP32 Preview Test Server

The legacy preview server is still available under:

- `backend/tests/esp32-preview/`

It is useful for low-level ESP32 photo/video upload tests and is separate from
the FastAPI production backend.
