"""Tesseract OCR over a rendered page clip.

Pixel boxes from image_to_data are mapped back to normalized page coordinates
so downstream code never has to care which engine produced a word.
"""
from typing import List

import fitz
import pytesseract

from ..config import get_settings
from ..pdfio import Word, render_clip


def _words_from_image(img, clip: fitz.Rect, page_w: float, page_h: float, scale: float,
                       lang: str, config: str = "", min_conf: float = 0) -> List[Word]:
    data = pytesseract.image_to_data(img, lang=lang, config=config, output_type=pytesseract.Output.DICT)
    words: List[Word] = []
    for i, text in enumerate(data["text"]):
        text = text.strip()
        conf = float(data["conf"][i])
        if not text or conf < 0 or conf < min_conf:
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


def ocr_words(page: fitz.Page, bbox: List[float], lang: str, dpi: int) -> List[Word]:
    """OCR a page region. The default page-segmentation mode expects
    paragraph-like structure and reliably reads multi-word regions (a
    description cell, a header field), but it frequently finds nothing in a
    crop containing just one short value — e.g. a quantity column with a
    single digit and lots of surrounding whitespace. When the default pass
    comes back empty, retry once as a single text block (psm 6), which
    handles sparse crops much better but is noisier, so its guesses are held
    to a confidence floor rather than trusted outright.
    """
    settings = get_settings()
    pytesseract.pytesseract.tesseract_cmd = settings.tesseract_cmd

    img, clip = render_clip(page, bbox, dpi)
    page_w, page_h = page.rect.width, page.rect.height
    scale = 72.0 / dpi  # image px -> PDF points

    words = _words_from_image(img, clip, page_w, page_h, scale, lang)
    if words:
        return words
    return _words_from_image(img, clip, page_w, page_h, scale, lang, config="--psm 6", min_conf=50)


def tesseract_version() -> str:
    try:
        pytesseract.pytesseract.tesseract_cmd = get_settings().tesseract_cmd
        return str(pytesseract.get_tesseract_version())
    except Exception:
        return "unavailable"
