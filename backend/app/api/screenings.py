from pathlib import Path
from uuid import uuid4
import json

from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile
from sqlmodel import Session, select

from app.api.deps import get_current_user
from app.core.config import settings
from app.db.session import get_session
from app.models import (
    Device,
    Member,
    Report,
    ScreeningImage,
    ScreeningSession,
    ScreeningStatus,
    User,
    utcnow,
)
from app.schemas import ImageRead, ScreeningCreate, ScreeningEdgeResultUpdate, ScreeningRead


router = APIRouter()


def parse_json_form(value: str | None) -> dict | None:
    if not value:
        return None
    try:
        parsed = json.loads(value)
    except json.JSONDecodeError:
        raise HTTPException(status_code=400, detail="Invalid JSON form field")
    if not isinstance(parsed, dict):
        raise HTTPException(status_code=400, detail="JSON form field must be an object")
    return parsed


def ensure_screening_owner(
    screening_id: int,
    current_user: User,
    session: Session,
) -> ScreeningSession:
    screening = session.get(ScreeningSession, screening_id)
    if not screening or screening.owner_id != current_user.id:
        raise HTTPException(status_code=404, detail="Screening not found")
    return screening


@router.post("", response_model=ScreeningRead)
def create_screening(
    payload: ScreeningCreate,
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> ScreeningSession:
    member = session.get(Member, payload.member_id)
    if not member or member.owner_id != current_user.id:
        raise HTTPException(status_code=404, detail="Member not found")

    if payload.device_id is not None:
        device = session.get(Device, payload.device_id)
        if not device or device.owner_id != current_user.id:
            raise HTTPException(status_code=404, detail="Device not found")

    screening = ScreeningSession(owner_id=current_user.id, **payload.model_dump())
    session.add(screening)
    session.commit()
    session.refresh(screening)
    return screening


@router.get("", response_model=list[ScreeningRead])
def list_screenings(
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> list[ScreeningSession]:
    return list(
        session.exec(
            select(ScreeningSession)
            .where(ScreeningSession.owner_id == current_user.id)
            .order_by(ScreeningSession.created_at.desc())
        ).all()
    )


@router.get("/{screening_id}", response_model=ScreeningRead)
def get_screening(
    screening_id: int,
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> ScreeningSession:
    return ensure_screening_owner(screening_id, current_user, session)


@router.patch("/{screening_id}/edge-result", response_model=ScreeningRead)
def update_edge_result(
    screening_id: int,
    payload: ScreeningEdgeResultUpdate,
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> ScreeningSession:
    screening = ensure_screening_owner(screening_id, current_user, session)
    screening.edge_result = payload.edge_result
    session.add(screening)
    session.commit()
    session.refresh(screening)
    return screening


@router.post("/{screening_id}/images", response_model=ImageRead)
def upload_image(
    screening_id: int,
    view_type: str = Form(...),
    quality: str | None = Form(default=None),
    edge_result: str | None = Form(default=None),
    file: UploadFile = File(...),
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> ScreeningImage:
    screening = ensure_screening_owner(screening_id, current_user, session)
    suffix = Path(file.filename or "").suffix.lower() or ".jpg"
    safe_name = f"{uuid4().hex}{suffix}"
    folder = Path(settings.upload_dir) / str(current_user.id) / str(screening_id)
    folder.mkdir(parents=True, exist_ok=True)
    file_path = folder / safe_name

    with file_path.open("wb") as target:
        target.write(file.file.read())

    relative_url = f"/uploads/{current_user.id}/{screening_id}/{safe_name}"
    file_url = f"{settings.public_base_url}{relative_url}" if settings.public_base_url else relative_url
    image = ScreeningImage(
        screening_id=screening_id,
        owner_id=current_user.id,
        view_type=view_type,
        file_path=str(file_path),
        file_url=file_url,
        quality=parse_json_form(quality),
        edge_result=parse_json_form(edge_result),
    )
    screening.status = ScreeningStatus.uploaded
    session.add(image)
    session.add(screening)
    session.commit()
    session.refresh(image)
    return image


@router.get("/{screening_id}/images", response_model=list[ImageRead])
def list_images(
    screening_id: int,
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> list[ScreeningImage]:
    ensure_screening_owner(screening_id, current_user, session)
    return list(
        session.exec(
            select(ScreeningImage)
            .where(ScreeningImage.screening_id == screening_id)
            .order_by(ScreeningImage.created_at)
        ).all()
    )


@router.post("/{screening_id}/complete", response_model=ScreeningRead)
def complete_screening(
    screening_id: int,
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> ScreeningSession:
    screening = ensure_screening_owner(screening_id, current_user, session)
    images = session.exec(select(ScreeningImage).where(ScreeningImage.screening_id == screening_id)).all()
    if not images:
        raise HTTPException(status_code=400, detail="Upload at least one image before completing screening")

    screening.status = ScreeningStatus.completed
    screening.completed_at = utcnow()

    existing_report = session.exec(select(Report).where(Report.screening_id == screening_id)).first()
    if not existing_report:
        report = Report(
            screening_id=screening_id,
            owner_id=current_user.id,
            risk_level="pending_review",
            summary="本次筛查已完成图像上传。当前报告由后端 MVP 自动生成，仅供健康筛查参考，不作为医学诊断。",
            suggestions=[
                "保持每天早晚刷牙，每次不少于两分钟。",
                "如发现持续疼痛、明显龋洞或牙龈出血，建议及时到正规口腔医疗机构复查。",
                "建议联网后接入云端视觉模型生成更精细的风险标注与护理建议。",
            ],
            structured_result={
                "image_count": len(images),
                "views": [image.view_type for image in images],
                "edge_result": screening.edge_result,
                "disclaimer": "仅供健康筛查参考，不作为医学诊断。",
            },
        )
        session.add(report)

    session.add(screening)
    session.commit()
    session.refresh(screening)
    return screening
