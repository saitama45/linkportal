from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    tesseract_cmd: str = r"C:\Program Files\Tesseract-OCR\tesseract.exe"
    soffice_path: str = r"C:\Program Files\LibreOffice\program\soffice.exe"
    ocr_engine: str = "tesseract"
    ocr_lang: str = "eng"
    ocr_dpi: int = 300
    text_layer_min_chars: int = 20
    port: int = 8077


@lru_cache
def get_settings() -> Settings:
    return Settings()
