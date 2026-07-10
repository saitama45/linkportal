"""linkportal OCR sidecar.

Run: uvicorn app.main:app --port 8077
Laravel talks to this over localhost; paths are shared-filesystem paths.
"""
import time
from pathlib import Path
from typing import List, Optional

import fitz
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

from . import convert as convert_mod
from .config import get_settings
from .engines.tesseract_engine import tesseract_version
from .extract import extract_document
from .pdfio import page_meta

app = FastAPI(title="linkportal-ocr-worker", version="1.0.0")


class ConvertRequest(BaseModel):
    input_path: str
    output_dir: str


class AnalyzeRequest(BaseModel):
    pdf_path: str


class TemplateField(BaseModel):
    key: str
    label: Optional[str] = None
    type: str = "text"  # text | date | amount | qty
    required: bool = False
    page: int = 1
    # Un-annotated fields (no box drawn yet) arrive as null and are skipped.
    bbox: Optional[List[float]] = Field(default=None, min_length=4, max_length=4)


class TemplateColumn(BaseModel):
    key: str
    type: Optional[str] = None
    x0: float
    x1: float


class TemplateTable(BaseModel):
    page: int = 1
    repeat_on_following_pages: bool = False
    bbox: List[float] = Field(min_length=4, max_length=4)
    columns: List[TemplateColumn]


class ExtractTemplate(BaseModel):
    fields: List[TemplateField] = []
    table: Optional[TemplateTable] = None


class ExtractOptions(BaseModel):
    engine: str = "auto"
    lang: Optional[str] = None
    dpi: Optional[int] = None
    dayfirst: bool = False


class ExtractRequest(BaseModel):
    pdf_path: str
    template: ExtractTemplate = ExtractTemplate()
    options: ExtractOptions = ExtractOptions()


def _require_file(path: str) -> None:
    if not Path(path).is_file():
        raise HTTPException(status_code=422, detail=f"file not found: {path}")


@app.get("/health")
def health():
    return {
        "status": "ok",
        "versions": {
            "pymupdf": fitz.__doc__.split()[1] if fitz.__doc__ else "unknown",
            "tesseract": tesseract_version(),
            "soffice": convert_mod.soffice_version(),
        },
        "engines": ["tesseract"],
    }


@app.post("/convert")
def convert(req: ConvertRequest):
    started = time.monotonic()
    _require_file(req.input_path)
    try:
        result = convert_mod.convert_to_pdf(req.input_path, req.output_dir)
    except convert_mod.ConversionError as exc:
        raise HTTPException(status_code=422, detail=str(exc))
    with fitz.open(result["pdf_path"]) as doc:
        page_count = len(doc)
    return {
        "pdf_path": result["pdf_path"],
        "page_count": page_count,
        "duration_ms": int((time.monotonic() - started) * 1000),
    }


@app.post("/analyze")
def analyze(req: AnalyzeRequest):
    _require_file(req.pdf_path)
    try:
        with fitz.open(req.pdf_path) as doc:
            return {"page_count": len(doc), "pages": page_meta(doc)}
    except fitz.FileDataError as exc:
        raise HTTPException(status_code=422, detail=f"unreadable PDF: {exc}")


@app.post("/extract")
def extract(req: ExtractRequest):
    _require_file(req.pdf_path)
    try:
        return extract_document(
            req.pdf_path,
            req.template.model_dump(),
            req.options.model_dump(),
        )
    except fitz.FileDataError as exc:
        raise HTTPException(status_code=422, detail=f"unreadable PDF: {exc}")


if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="127.0.0.1", port=get_settings().port)
