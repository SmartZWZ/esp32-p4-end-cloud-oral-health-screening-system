from fastapi import APIRouter, Depends, HTTPException
from sqlmodel import Session, select

from app.api.deps import get_current_user
from app.db.session import get_session
from app.models import Report, ScreeningSession, User
from app.schemas import ReportRead


router = APIRouter()


@router.get("", response_model=list[ReportRead])
def list_reports(
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> list[Report]:
    return list(
        session.exec(
            select(Report).where(Report.owner_id == current_user.id).order_by(Report.created_at.desc())
        ).all()
    )


@router.get("/screenings/{screening_id}", response_model=ReportRead)
def get_report_by_screening(
    screening_id: int,
    current_user: User = Depends(get_current_user),
    session: Session = Depends(get_session),
) -> Report:
    screening = session.get(ScreeningSession, screening_id)
    if not screening or screening.owner_id != current_user.id:
        raise HTTPException(status_code=404, detail="Screening not found")
    report = session.exec(select(Report).where(Report.screening_id == screening_id)).first()
    if not report:
        raise HTTPException(status_code=404, detail="Report not found")
    return report
