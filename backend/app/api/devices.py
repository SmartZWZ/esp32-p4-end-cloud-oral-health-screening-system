from fastapi import APIRouter, Depends, HTTPException
from sqlmodel import Session, select

from app.api.deps import get_current_user
from app.db.session import get_session
from app.models import Device, User, utcnow
from app.schemas import DeviceBind, DeviceRead, DeviceStatusUpdate


router = APIRouter()


@router.post("/bind", response_model=DeviceRead)
def bind_device(
    payload: DeviceBind,
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> Device:
    device = session.exec(select(Device).where(Device.device_sn == payload.device_sn)).first()
    if device and device.owner_id != current_user.id:
        raise HTTPException(status_code=409, detail="Device already bound by another user")
    if device:
        device.name = payload.name or device.name
        device.firmware_version = payload.firmware_version or device.firmware_version
        device.model_version = payload.model_version or device.model_version
    else:
        device = Device(owner_id=current_user.id, **payload.model_dump())
        session.add(device)
    session.commit()
    session.refresh(device)
    return device


@router.get("", response_model=list[DeviceRead])
def list_devices(
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> list[Device]:
    return list(session.exec(select(Device).where(Device.owner_id == current_user.id)).all())


@router.post("/{device_id}/status", response_model=DeviceRead)
def update_device_status(
    device_id: int,
    payload: DeviceStatusUpdate,
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> Device:
    device = session.get(Device, device_id)
    if not device or device.owner_id != current_user.id:
        raise HTTPException(status_code=404, detail="Device not found")
    device.firmware_version = payload.firmware_version or device.firmware_version
    device.model_version = payload.model_version or device.model_version
    device.last_status = payload.status
    device.last_seen_at = utcnow()
    session.add(device)
    session.commit()
    session.refresh(device)
    return device
