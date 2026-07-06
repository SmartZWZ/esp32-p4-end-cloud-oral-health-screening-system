from functools import cached_property

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8")

    app_name: str = "Tooth Screening Backend"
    api_prefix: str = "/api/v1"
    secret_key: str = "change-me-before-deploy"
    access_token_expire_minutes: int = 60 * 24 * 7
    database_url: str = "sqlite:///./tooth_backend.db"
    upload_dir: str = "storage/uploads"
    public_base_url: str = ""
    allow_origins: str = "*"

    @cached_property
    def allow_origins_list(self) -> list[str]:
        if self.allow_origins.strip() == "*":
            return ["*"]
        return [item.strip() for item in self.allow_origins.split(",") if item.strip()]


settings = Settings()
