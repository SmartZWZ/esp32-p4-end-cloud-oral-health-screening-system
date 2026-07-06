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

## Existing ESP32 Preview Test Server

The legacy preview server is still available under:

- `backend/tests/esp32-preview/`

It is useful for low-level ESP32 photo/video upload tests and is separate from
the FastAPI production backend.
