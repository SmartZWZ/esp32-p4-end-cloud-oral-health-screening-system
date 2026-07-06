from datetime import datetime, timezone
from enum import Enum

from sqlalchemy import Column, JSON
from sqlmodel import Field, SQLModel


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


class UserRole(str, Enum):
    user = "user"
    operator = "operator"
    admin = "admin"


class ScreeningStatus(str, Enum):
    draft = "draft"
    uploaded = "uploaded"
    analyzing = "analyzing"
    completed = "completed"
    failed = "failed"


class User(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    phone: str | None = Field(default=None, index=True)
    email: str | None = Field(default=None, index=True)
    username: str = Field(index=True, unique=True)
    password_hash: str
    role: UserRole = Field(default=UserRole.user)
    is_active: bool = Field(default=True)
    created_at: datetime = Field(default_factory=utcnow)


class Member(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    owner_id: int = Field(foreign_key="user.id", index=True)
    name: str
    age: int | None = None
    gender: str | None = None
    relation: str | None = None
    school_class: str | None = None
    student_no: str | None = None
    created_at: datetime = Field(default_factory=utcnow)


class Device(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    owner_id: int = Field(foreign_key="user.id", index=True)
    device_sn: str = Field(index=True, unique=True)
    name: str | None = None
    firmware_version: str | None = None
    model_version: str | None = None
    last_status: dict | None = Field(default=None, sa_column=Column(JSON))
    last_seen_at: datetime | None = None
    created_at: datetime = Field(default_factory=utcnow)


class ScreeningSession(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    owner_id: int = Field(foreign_key="user.id", index=True)
    member_id: int = Field(foreign_key="member.id", index=True)
    device_id: int | None = Field(default=None, foreign_key="device.id", index=True)
    status: ScreeningStatus = Field(default=ScreeningStatus.draft, index=True)
    note: str | None = None
    edge_result: dict | None = Field(default=None, sa_column=Column(JSON))
    created_at: datetime = Field(default_factory=utcnow)
    completed_at: datetime | None = None


class ScreeningImage(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    screening_id: int = Field(foreign_key="screeningsession.id", index=True)
    owner_id: int = Field(foreign_key="user.id", index=True)
    view_type: str
    file_path: str
    file_url: str
    quality: dict | None = Field(default=None, sa_column=Column(JSON))
    edge_result: dict | None = Field(default=None, sa_column=Column(JSON))
    created_at: datetime = Field(default_factory=utcnow)


class Report(SQLModel, table=True):
    id: int | None = Field(default=None, primary_key=True)
    screening_id: int = Field(foreign_key="screeningsession.id", index=True, unique=True)
    owner_id: int = Field(foreign_key="user.id", index=True)
    summary: str
    risk_level: str = Field(default="unknown")
    suggestions: list[str] = Field(default_factory=list, sa_column=Column(JSON))
    structured_result: dict | None = Field(default=None, sa_column=Column(JSON))
    created_at: datetime = Field(default_factory=utcnow)
