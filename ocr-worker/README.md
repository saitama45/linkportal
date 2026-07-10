# linkportal OCR Worker

Self-hosted OCR sidecar for the vendor document intake pipeline. Laravel queued
jobs call it over localhost HTTP; files are exchanged as shared-filesystem paths.

## Stack

- **PyMuPDF** — digital PDF text-layer extraction + page rendering
- **Tesseract** — OCR for scanned pages (PaddleOCR is a future opt-in via `OCR_ENGINE`)
- **LibreOffice headless** — DOC/DOCX → PDF conversion
- **FastAPI/uvicorn** — HTTP surface

## Setup (Windows dev)

1. Install [Tesseract](https://github.com/UB-Mannheim/tesseract/wiki) and LibreOffice.
2. ```
   cd ocr-worker
   python -m venv .venv
   .venv\Scripts\activate
   pip install -r requirements.txt
   copy .env.example .env   # adjust TESSERACT_CMD / SOFFICE_PATH if needed
   ```
3. Run: `uvicorn app.main:app --port 8077`
4. Smoke test: `curl http://127.0.0.1:8077/health`

Laravel side: set `OCR_SERVICE_URL=http://127.0.0.1:8077` in linkportal `.env`.

## Endpoints

| Endpoint | Purpose |
|---|---|
| `GET /health` | status + binary versions |
| `POST /convert` | `{input_path, output_dir}` → `{pdf_path, page_count}` (LibreOffice, serialized) |
| `POST /analyze` | `{pdf_path}` → per-page dimensions + `has_text_layer` |
| `POST /extract` | `{pdf_path, template, options}` → fields, line items, confidences, totals check |

All bounding boxes are **normalized page-relative 0..1** against PDF point
dimensions — the same coordinate space the pdf.js annotator uses. Template shape
is exactly what `portal_document_template_versions.annotations` stores.

## Tests

```
pytest
```

Golden fixtures are generated in-test (a digital invoice drawn at known
coordinates + an image-only "scanned" variant). Tesseract/LibreOffice-dependent
tests skip automatically when the binaries are absent.

## Production notes

Run as a Windows service (NSSM or Task Scheduler at-startup):
`.venv\Scripts\uvicorn.exe app.main:app --host 127.0.0.1 --port 8077`
Keep it bound to localhost — it has no auth and can read arbitrary paths.
