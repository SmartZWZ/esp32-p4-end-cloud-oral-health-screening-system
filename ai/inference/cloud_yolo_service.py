from __future__ import annotations

import os
import time
from pathlib import Path
from typing import Annotated, Any

import cv2
import numpy as np
from fastapi import FastAPI, File, HTTPException, Query, Request, UploadFile
from pydantic import BaseModel
from ultralytics import YOLO


MODEL_PATH = Path(os.getenv("YOLO_MODEL_PATH", "/opt/tooth-yolo/models/cloud_fine_det_best.pt"))
DEFAULT_CONF = float(os.getenv("YOLO_CONF", "0.25"))
DEFAULT_IMGSZ = int(os.getenv("YOLO_IMGSZ", "640"))

app = FastAPI(title="Tooth YOLO Inference Service")
model: YOLO | None = None


class Detection(BaseModel):
    class_id: int
    class_name: str
    confidence: float
    box_xyxy: list[float]


class PredictResponse(BaseModel):
    ok: bool
    model_path: str
    image_shape: list[int]
    elapsed_ms: float
    detections: list[Detection]


def get_model() -> YOLO:
    global model
    if model is None:
        if not MODEL_PATH.exists():
            raise RuntimeError(f"model file not found: {MODEL_PATH}")
        model = YOLO(str(MODEL_PATH))
    return model


@app.on_event("startup")
def startup() -> None:
    get_model()


@app.get("/health")
def health() -> dict[str, Any]:
    return {
        "ok": True,
        "model_path": str(MODEL_PATH),
        "model_exists": MODEL_PATH.exists(),
    }


async def read_image_bytes(request: Request, file: UploadFile | None) -> bytes:
    if file is not None:
        return await file.read()

    body = await request.body()
    if body:
        return body

    raise HTTPException(status_code=400, detail="No image data provided")


def decode_image(data: bytes) -> np.ndarray:
    if not data:
        raise HTTPException(status_code=400, detail="Empty image payload")
    array = np.frombuffer(data, dtype=np.uint8)
    image = cv2.imdecode(array, cv2.IMREAD_COLOR)
    if image is None:
        raise HTTPException(status_code=400, detail="Payload is not a valid image")
    return image


def run_prediction(image: np.ndarray, conf: float, imgsz: int) -> PredictResponse:
    loaded_model = get_model()
    started = time.perf_counter()
    results = loaded_model.predict(source=image, conf=conf, imgsz=imgsz, verbose=False)
    elapsed_ms = (time.perf_counter() - started) * 1000

    detections: list[Detection] = []
    names = loaded_model.names
    result = results[0]
    if result.boxes is not None:
        for box in result.boxes:
            class_id = int(box.cls[0].item())
            confidence = float(box.conf[0].item())
            xyxy = [float(value) for value in box.xyxy[0].tolist()]
            detections.append(
                Detection(
                    class_id=class_id,
                    class_name=str(names.get(class_id, class_id)),
                    confidence=confidence,
                    box_xyxy=xyxy,
                )
            )

    return PredictResponse(
        ok=True,
        model_path=str(MODEL_PATH),
        image_shape=[int(image.shape[0]), int(image.shape[1]), int(image.shape[2])],
        elapsed_ms=round(elapsed_ms, 2),
        detections=detections,
    )


@app.post("/api/v1/yolo/image", response_model=PredictResponse)
async def predict_image(
    request: Request,
    file: Annotated[UploadFile | None, File()] = None,
    conf: Annotated[float, Query(ge=0.01, le=1.0)] = DEFAULT_CONF,
    imgsz: Annotated[int, Query(ge=160, le=1280)] = DEFAULT_IMGSZ,
) -> PredictResponse:
    data = await read_image_bytes(request, file)
    image = decode_image(data)
    return run_prediction(image, conf, imgsz)


@app.post("/api/v1/yolo/video-frame", response_model=PredictResponse)
async def predict_video_frame(
    request: Request,
    file: Annotated[UploadFile | None, File()] = None,
    conf: Annotated[float, Query(ge=0.01, le=1.0)] = DEFAULT_CONF,
    imgsz: Annotated[int, Query(ge=160, le=1280)] = DEFAULT_IMGSZ,
) -> PredictResponse:
    data = await read_image_bytes(request, file)
    image = decode_image(data)
    return run_prediction(image, conf, imgsz)
