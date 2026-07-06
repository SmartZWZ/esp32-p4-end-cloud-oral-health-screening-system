import json
import re
from typing import Any

import httpx

from app.core.config import settings


COMMANDS: dict[str, str] = {
    "take_photo": "拍照并上传最新图片",
    "start_edge_detection": "开始端侧检测",
    "stop_edge_detection": "停止端侧检测",
    "start_camera": "打开摄像头",
    "stop_camera": "关闭摄像头",
    "noop": "没有明确设备动作",
}


def command_catalog() -> list[dict[str, str]]:
    return [{"command": key, "description": value} for key, value in COMMANDS.items()]


def parse_command_by_rules(text: str) -> dict[str, Any]:
    normalized = re.sub(r"\s+", "", text.lower())
    if not normalized:
        return {
            "command": "noop",
            "confidence": 0.0,
            "reason": "No ASR text was available.",
            "source": "rule",
        }

    rules: list[tuple[str, tuple[str, ...], float, str]] = [
        (
            "stop_camera",
            ("关闭摄像头", "关摄像头", "关闭相机", "关相机", "closecamera", "stopcamera"),
            0.9,
            "Matched camera shutdown keywords.",
        ),
        (
            "start_camera",
            ("打开摄像头", "开摄像头", "打开相机", "开相机", "opencamera", "startcamera"),
            0.88,
            "Matched camera startup keywords.",
        ),
        (
            "start_edge_detection",
            ("开始端测", "开始端侧", "开始检测", "开始筛查", "端测开始", "startdetection", "startedge"),
            0.88,
            "Matched edge detection startup keywords.",
        ),
        (
            "stop_edge_detection",
            ("停止端测", "停止端侧", "停止检测", "结束检测", "stopdetection", "stopedge"),
            0.86,
            "Matched edge detection stop keywords.",
        ),
        (
            "take_photo",
            ("拍照", "照相", "拍一张", "拍个照", "capture", "takephoto", "snapshot"),
            0.9,
            "Matched photo capture keywords.",
        ),
    ]

    for command, keywords, confidence, reason in rules:
        if any(keyword in normalized for keyword in keywords):
            return {
                "command": command,
                "confidence": confidence,
                "reason": reason,
                "source": "rule",
            }

    return {
        "command": "noop",
        "confidence": 0.35,
        "reason": "No supported device command was detected.",
        "source": "rule",
    }


async def transcribe_audio(
    *,
    filename: str,
    content: bytes,
    content_type: str | None,
    language: str | None,
) -> dict[str, Any]:
    if not settings.voice_asr_url:
        return {
            "configured": False,
            "text": "",
            "raw": None,
            "error": "VOICE_ASR_URL is not configured.",
        }

    headers = {}
    if settings.voice_asr_api_key:
        headers["Authorization"] = f"Bearer {settings.voice_asr_api_key}"

    data: dict[str, str] = {}
    if settings.voice_asr_model:
        data["model"] = settings.voice_asr_model
    if language:
        data["language"] = language

    files = {
        "file": (
            filename,
            content,
            content_type or "application/octet-stream",
        )
    }

    async with httpx.AsyncClient(timeout=settings.voice_llm_timeout_seconds) as client:
        response = await client.post(settings.voice_asr_url, headers=headers, data=data, files=files)
        response.raise_for_status()

    raw = response.json()
    return {
        "configured": True,
        "text": extract_asr_text(raw),
        "raw": raw,
        "error": None,
    }


def extract_asr_text(raw: Any) -> str:
    if isinstance(raw, str):
        return raw
    if not isinstance(raw, dict):
        return ""

    for key in ("text", "transcript", "recognized_text"):
        value = raw.get(key)
        if isinstance(value, str):
            return value.strip()

    result = raw.get("result")
    if isinstance(result, str):
        return result.strip()
    if isinstance(result, dict):
        return extract_asr_text(result)

    return ""


async def infer_command_with_llm(text: str) -> dict[str, Any] | None:
    if not settings.voice_llm_base_url or not text.strip():
        return None

    url = llm_chat_completions_url(settings.voice_llm_base_url)
    headers = {"Content-Type": "application/json"}
    if settings.voice_llm_api_key:
        headers["Authorization"] = f"Bearer {settings.voice_llm_api_key}"

    payload = {
        "model": settings.voice_llm_model,
        "temperature": 0,
        "messages": [
            {
                "role": "system",
                "content": (
                    "你是 ESP32-P4 口腔筛查设备的语音指令解析器。"
                    "只能从命令表中选择一个 command，并输出 JSON。"
                    f"命令表: {json.dumps(command_catalog(), ensure_ascii=False)}。"
                    "输出格式: {\"command\":\"take_photo\",\"confidence\":0.0,\"reason\":\"...\"}。"
                    "如果语义不明确，command 必须是 noop。"
                ),
            },
            {"role": "user", "content": text},
        ],
    }

    async with httpx.AsyncClient(timeout=settings.voice_llm_timeout_seconds) as client:
        response = await client.post(url, headers=headers, json=payload)
        response.raise_for_status()

    raw = response.json()
    content = (
        raw.get("choices", [{}])[0]
        .get("message", {})
        .get("content", "")
    )
    parsed = extract_json_object(content)
    command = parsed.get("command")
    if command not in COMMANDS:
        return None

    confidence = parsed.get("confidence", 0.5)
    try:
        confidence = float(confidence)
    except (TypeError, ValueError):
        confidence = 0.5

    return {
        "command": command,
        "confidence": max(0.0, min(1.0, confidence)),
        "reason": str(parsed.get("reason") or "LLM selected command."),
        "source": "llm",
        "raw": raw,
    }


def llm_chat_completions_url(base_url: str) -> str:
    base = base_url.rstrip("/")
    if base.endswith("/chat/completions"):
        return base
    if base.endswith("/v1"):
        return f"{base}/chat/completions"
    return f"{base}/v1/chat/completions"


def extract_json_object(text: str) -> dict[str, Any]:
    cleaned = text.strip()
    try:
        parsed = json.loads(cleaned)
        return parsed if isinstance(parsed, dict) else {}
    except json.JSONDecodeError:
        pass

    match = re.search(r"\{.*\}", cleaned, flags=re.DOTALL)
    if not match:
        return {}
    try:
        parsed = json.loads(match.group(0))
    except json.JSONDecodeError:
        return {}
    return parsed if isinstance(parsed, dict) else {}
