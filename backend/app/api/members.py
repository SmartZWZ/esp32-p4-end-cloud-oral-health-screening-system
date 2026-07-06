from fastapi import APIRouter, Depends, HTTPException
from sqlmodel import Session, select

from app.api.deps import get_current_user
from app.db.session import get_session
from app.models import Member, User
from app.schemas import MemberCreate, MemberRead


router = APIRouter()


@router.post("", response_model=MemberRead)
def create_member(
    payload: MemberCreate,
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> Member:
    member = Member(owner_id=current_user.id, **payload.model_dump())
    session.add(member)
    session.commit()
    session.refresh(member)
    return member


@router.get("", response_model=list[MemberRead])
def list_members(
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> list[Member]:
    return list(session.exec(select(Member).where(Member.owner_id == current_user.id)).all())


@router.get("/{member_id}", response_model=MemberRead)
def get_member(
    member_id: int,
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> Member:
    member = session.get(Member, member_id)
    if not member or member.owner_id != current_user.id:
        raise HTTPException(status_code=404, detail="Member not found")
    return member
