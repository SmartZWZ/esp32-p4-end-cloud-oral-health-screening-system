from fastapi import APIRouter

from app.api import auth, devices, health, members, reports, screenings


api_router = APIRouter()
api_router.include_router(health.router, tags=["health"])
api_router.include_router(auth.router, prefix="/auth", tags=["auth"])
api_router.include_router(members.router, prefix="/members", tags=["members"])
api_router.include_router(devices.router, prefix="/devices", tags=["devices"])
api_router.include_router(screenings.router, prefix="/screenings", tags=["screenings"])
api_router.include_router(reports.router, prefix="/reports", tags=["reports"])
