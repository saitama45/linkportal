"""Tesseract OCR over a rendered page clip.

Pixel boxes from image_to_data are mapped back to normalized page coordinates
so downstream code never has to care which engine produced a word.
"""
from typing import List, Optional

import fitz
import pytesseract

from ..config import get_settings
from ..pdfio import Word, render_clip

# Alphabet for a retry pass over a region known to hold a purely numeric value,
# so stray marks cannot be read as letters. Only worth applying where the value
# has no text component — a whitelist costs the LSTM accuracy on real words.
NUMERIC_WHITELIST = "0123456789.,-"


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


def _config(psm: Optional[int], whitelist: Optional[str]) -> str:
    parts = []
    if psm is not None:
        parts.append(f"--psm {psm}")
    if whitelist:
        parts.append(f"-c tessedit_char_whitelist={whitelist}")
    return " ".join(parts)


def _normalize_contrast(img):
    """Flatten a photographed page's uneven lighting before a retry pass. On a
    scanned/generated PDF this is a no-op in practice; on a phone photo it is
    what makes the difference between reading a value and reading noise."""
    from PIL import ImageOps

    return ImageOps.autocontrast(ImageOps.grayscale(img))


def ocr_words(page: fitz.Page, bbox: List[float], lang: str, dpi: int,
              psm: Optional[int] = None, whitelist: Optional[str] = None,
              min_conf: float = 0, enhance: bool = False) -> List[Word]:
    """OCR a page region. The default page-segmentation mode expects
    paragraph-like structure and reliably reads multi-word regions (a
    description cell, a header field), but on a sparse crop it both finds
    nothing at all (a quantity column holding one digit) and — worse, because
    it looks like success — returns a fragment of the real value ("une" for
    "June 08, 2026"). When the default pass comes back empty, retry once as a
    single text block (psm 6), which handles sparse crops much better but is
    noisier, so its guesses are held to a confidence floor rather than trusted
    outright. Callers that know the region's value type can instead ask for an
    explicit pass (psm/whitelist/enhance) and judge the result themselves.
    """
    settings = get_settings()
    pytesseract.pytesseract.tesseract_cmd = settings.tesseract_cmd

    img, clip = render_clip(page, bbox, dpi)
    page_w, page_h = page.rect.width, page.rect.height
    scale = 72.0 / dpi  # image px -> PDF points

    if psm is not None or whitelist:
        if enhance:
            img = _normalize_contrast(img)
        return _words_from_image(img, clip, page_w, page_h, scale, lang,
                                 config=_config(psm, whitelist), min_conf=min_conf)

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
