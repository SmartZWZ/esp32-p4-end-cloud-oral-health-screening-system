from datetime import datetime
from typing import Any

from pydantic import BaseModel, Field

from app.models import ScreeningStatus, UserRole


class TokenResponse(BaseModel):
    access_token: str
    token_type: str = "bearer"


class UserCreate(BaseModel):
    username: str = Field(min_length=3, max_length=50)
    password: str = Field(min_length=6, max_length=128)
    phone: str | None = None
    email: str | None = None


class UserLogin(BaseModel):
    username: str
    password: str


class UserRead(BaseModel):
    id: int
    username: str
    phone: str | None
    email: str | None
    role: UserRole


class MemberCreate(BaseModel):
    name: str
    age: int | None = None
    gender: str | None = None
    relation: str | None = None
    school_class: str | None = None
    student_no: str | None = None


class MemberRead(MemberCreate):
    id: int
    owner_id: int
    created_at: datetime


class DeviceBind(BaseModel):
    device_sn: str
    name: str | None = None
    firmware_version: str | None = None
    model_version: str | None = None


class DeviceStatusUpdate(BaseModel):
    firmware_version: str | None = None
    model_version: str | None = None
    status: dict[str, Any] = Field(default_factory=dict)


class DeviceRead(BaseModel):
    id: int
    owner_id: int
    device_sn: str
    name: str | None
    firmware_version: str | None
    model_version: str | None
    last_status: dict[str, Any] | None
    last_seen_at: datetime | None


class ScreeningCreate(BaseModel):
    member_id: int
    device_id: int | None = None
    note: str | None = None


class ScreeningEdgeResultUpdate(BaseModel):
    edge_result: dict[str, Any] = Field(default_factory=dict)


class ScreeningRead(BaseModel):
    id: int
    owner_id: int
    member_id: int
    device_id: int | None
    status: ScreeningStatus
    note: str | None
    edge_result: dict[str, Any] | None
    created_at: datetime
    completed_at: datetime | None


class ImageRead(BaseModel):
    id: int
    screening_id: int
    view_type: str
    file_url: str
    quality: dict[str, Any] | None
    edge_result: dict[str, Any] | None
    created_at: datetime


class ReportRead(BaseModel):
    id: int
    screening_id: int
    summary: str
    risk_level: str
    suggestions: list[str]
    structured_result: dict[str, Any] | None
    created_at: datetime


class VoiceCommandRead(BaseModel):
    ok: bool
    status: str
    command: str
    text: str
    confidence: float
    reason: str
    source: str
    asr_configured: bool
    llm_used: bool
    audio_url: str | None = None
    device_sn: str | None = None
    raw: dict[str, Any] | None = None
