"""PyMuPDF helpers.

All bounding boxes crossing the API are normalized page-relative [x0, y0, x1, y1]
in 0..1 against the page's point dimensions, so they are independent of render
scale and match what the pdf.js annotator produces.
"""
from dataclasses import dataclass
from typing import List

import fitz  # PyMuPDF

from .config import get_settings


@dataclass
class Word:
    text: str
    confidence: float  # 0..1
    bbox: List[float]  # normalized [x0, y0, x1, y1]


def open_pdf(path: str) -> fitz.Document:
    return fitz.open(path)


def page_meta(doc: fitz.Document) -> list[dict]:
    min_chars = get_settings().text_layer_min_chars
    meta = []
    for i, page in enumerate(doc):
        text = page.get_text().strip()
        meta.append({
            "page": i + 1,
            "width_pt": round(page.rect.width, 2),
            "height_pt": round(page.rect.height, 2),
            "has_text_layer": len(text) >= min_chars,
        })
    return meta


def denormalize(bbox: List[float], page: fitz.Page) -> fitz.Rect:
    w, h = page.rect.width, page.rect.height
    return fitz.Rect(bbox[0] * w, bbox[1] * h, bbox[2] * w, bbox[3] * h)


def normalize_rect(rect: fitz.Rect, page: fitz.Page) -> List[float]:
    w, h = page.rect.width, page.rect.height
    return [round(rect.x0 / w, 5), round(rect.y0 / h, 5), round(rect.x1 / w, 5), round(rect.y1 / h, 5)]


def embedded_words(page: fitz.Page, bbox: List[float]) -> List[Word]:
    """Words from the PDF text layer inside a normalized clip, reading order."""
    clip = denormalize(bbox, page)
    raw = page.get_text("words", clip=clip)
    # sort by block, line, word_no (already the tuple tail) then y/x for stability
    raw.sort(key=lambda w: (w[5], w[6], w[7]))
    return [
        Word(text=w[4], confidence=0.99, bbox=normalize_rect(fitz.Rect(w[:4]), page))
        for w in raw if w[4].strip()
    ]


def render_clip(page: fitz.Page, bbox: List[float], dpi: int):
    """Render a normalized clip to a PIL image; returns (image, clip_rect)."""
    from io import BytesIO

    from PIL import Image

    clip = denormalize(bbox, page)
    zoom = dpi / 72.0
    pix = page.get_pixmap(matrix=fitz.Matrix(zoom, zoom), clip=clip)
    img = Image.open(BytesIO(pix.tobytes("png")))
    return img, clip
