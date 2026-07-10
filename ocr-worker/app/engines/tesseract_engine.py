"""Tesseract OCR over a rendered page clip.

Pixel boxes from image_to_data are mapped back to normalized page coordinates
so downstream code never has to care which engine produced a word.
"""
from typing import List

import fitz
import pytesseract

from ..config import get_settings
from ..pdfio import Word, render_clip


def ocr_words(page: fitz.Page, bbox: List[float], lang: str, dpi: int) -> List[Word]:
    settings = get_settings()
    pytesseract.pytesseract.tesseract_cmd = settings.tesseract_cmd

    img, clip = render_clip(page, bbox, dpi)
    data = pytesseract.image_to_data(img, lang=lang, output_type=pytesseract.Output.DICT)

    page_w, page_h = page.rect.width, page.rect.height
    scale = 72.0 / dpi  # image px -> PDF points
    words: List[Word] = []
    for i, text in enumerate(data["text"]):
        text = text.strip()
        conf = float(data["conf"][i])
        if not text or conf < 0:
            continue
        x0 = clip.x0 + data["left"][i] * scale
        y0 = clip.y0 + data["top"][i] * scale
        x1 = x0 + data["width"][i] * scale
        y1 = y0 + data["height"][i] * scale
        words.append(Word(
            text=text,
            confidence=round(conf / 100.0, 4),
            bbox=[round(x0 / page_w, 5), round(y0 / page_h, 5),
                  round(x1 / page_w, 5), round(y1 / page_h, 5)],
        ))
    return words


def tesseract_version() -> str:
    try:
        pytesseract.pytesseract.tesseract_cmd = get_settings().tesseract_cmd
        return str(pytesseract.get_tesseract_version())
    except Exception:
        return "unavailable"
