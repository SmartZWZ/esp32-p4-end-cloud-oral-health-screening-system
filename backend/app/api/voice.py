from pathlib import Path
from uuid import uuid4

import httpx
from fastapi import APIRouter, File, Form, HTTPException, UploadFile

from app.core.config import settings
from app.schemas import VoiceCommandRead
from app.services.voice_control import infer_command_with_llm, parse_command_by_rules, transcribe_audio


router = APIRouter()


@router.get("/health")
def voice_health() -> dict[str, object]:
    return {
        "status": "ok",
        "asr_configured": bool(settings.voice_asr_url),
        "llm_configured": bool(settings.voice_llm_base_url),
        "llm_model": settings.voice_llm_model,
        "max_audio_bytes": settings.voice_max_audio_bytes,
    }


@router.post("/commands", response_model=VoiceCommandRead)
async def create_voice_command(
    file: UploadFile = File(...),
    device_sn: str | None = Form(default=None),
    language: str | None = Form(default="zh"),
    asr_text: str | None = Form(default=None),
) -> VoiceCommandRead:
    content = await file.read()
    if not content:
        raise HTTPException(status_code=400, detail="Audio file is empty")
    if len(content) > settings.voice_max_audio_bytes:
        raise HTTPException(status_code=413, detail="Audio file is too large")

    saved_path, audio_url = save_audio_file(file.filename, content)

    asr_result = {
        "configured": False,
        "text": "",
        "raw": None,
        "error": "ASR skipped because asr_text was provided.",
    }
    status = "ok"
    if asr_text and asr_text.strip():
        text = asr_text.strip()
    else:
        try:
            asr_result = await transcribe_audio(
                filename=saved_path.name,
                content=content,
                content_type=file.content_type,
                language=language,
            )
        except httpx.HTTPError as exc:
            raise HTTPException(status_code=502, detail=f"ASR request failed: {exc}") from exc

        text = asr_result["text"]
        if not asr_result["configured"]:
            status = "asr_unconfigured"
        elif not text:
            status = "asr_empty"

    llm_result = None
    if text:
        try:
            llm_result = await infer_command_with_llm(text)
        except httpx.HTTPError:
            llm_result = None

    command_result = llm_result or parse_command_by_rules(text)
    ok = status == "ok" or bool(text)

    return VoiceCommandRead(
        ok=ok,
        status=status,
        command=command_result["command"],
        text=text,
        confidence=command_result["confidence"],
        reason=command_result["reason"],
        source=command_result["source"],
        asr_configured=bool(asr_result["configured"]),
        llm_used=bool(llm_result),
        audio_url=audio_url,
        device_sn=device_sn,
        raw={
            "asr": asr_result["raw"],
            "llm": llm_result.get("raw") if llm_result else None,
        },
    )


def save_audio_file(filename: str | None, content: bytes) -> tuple[Path, str]:
    suffix = Path(filename or "").suffix.lower() or ".wav"
    if suffix not in {".wav", ".mp3", ".m4a", ".aac", ".opus", ".ogg", ".webm", ".pcm"}:
        suffix = ".bin"

    folder = Path(settings.voice_audio_dir)
    folder.mkdir(parents=True, exist_ok=True)
    saved_path = folder / f"{uuid4().hex}{suffix}"
    saved_path.write_bytes(content)

    relative_url = f"/uploads/voice/{saved_path.name}"
    return saved_path, relative_url
