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
    voice_audio_dir: str = "storage/uploads/voice"
    voice_max_audio_bytes: int = 10 * 1024 * 1024
    voice_asr_url: str = ""
    voice_asr_api_key: str = ""
    voice_asr_model: str = ""
    voice_llm_base_url: str = ""
    voice_llm_api_key: str = ""
    voice_llm_model: str = "Qwen3-3.5B"
    voice_llm_timeout_seconds: float = 15.0
    public_base_url: str = ""
    allow_origins: str = "*"

    @cached_property
    def allow_origins_list(self) -> list[str]:
        if self.allow_origins.strip() == "*":
            return ["*"]
        return [item.strip() for item in self.allow_origins.split(",") if item.strip()]


settings = Settings()
