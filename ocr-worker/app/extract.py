"""Template-driven field and line-item extraction.

Strategy per region: if the page has a usable text layer, read embedded words
(confidence 0.99); otherwise render the clip and OCR it. The template's bboxes
and the returned word boxes are all normalized page-relative 0..1.
"""
from statistics import median
from typing import List, Optional

import fitz

from .config import get_settings
from .engines.tesseract_engine import ocr_words
from .normalize import normalize_value
from .pdfio import Word, embedded_words, page_meta

# Default value types per well-known column key; template may override with "type".
COLUMN_TYPE_DEFAULTS = {
    "description": "text",
    "quantity": "qty",
    "uom": "text",
    "unit_price": "amount",
    "line_total": "amount",
}

# New row when the y-center gap exceeds this multiple of the median word height.
ROW_GAP_FACTOR = 0.6


def _words_for(page: fitz.Page, bbox: List[float], has_text: bool, lang: str, dpi: int):
    if has_text:
        return embedded_words(page, bbox), "embedded"
    return ocr_words(page, bbox, lang=lang, dpi=dpi), "tesseract"


def _lines(words: List[Word]) -> List[List[Word]]:
    """Group words into visual lines by y-center proximity."""
    if not words:
        return []
    heights = [w.bbox[3] - w.bbox[1] for w in words]
    threshold = max(median(heights) * ROW_GAP_FACTOR, 0.002)
    ordered = sorted(words, key=lambda w: ((w.bbox[1] + w.bbox[3]) / 2, w.bbox[0]))
    lines: List[List[Word]] = [[ordered[0]]]
    for w in ordered[1:]:
        prev = lines[-1]
        prev_y = sum((p.bbox[1] + p.bbox[3]) / 2 for p in prev) / len(prev)
        y = (w.bbox[1] + w.bbox[3]) / 2
        if y - prev_y > threshold:
            lines.append([w])
        else:
            prev.append(w)
    for line in lines:
        line.sort(key=lambda w: w.bbox[0])
    return lines


def _joined_text(words: List[Word]) -> str:
    return "\n".join(" ".join(w.text for w in line) for line in _lines(words))


def _mean_confidence(words: List[Word]) -> float:
    if not words:
        return 0.0
    total_len = sum(len(w.text) for w in words) or 1
    return round(sum(w.confidence * len(w.text) for w in words) / total_len, 4)


def _union_bbox(words: List[Word]) -> Optional[List[float]]:
    if not words:
        return None
    return [
        round(min(w.bbox[0] for w in words), 5),
        round(min(w.bbox[1] for w in words), 5),
        round(max(w.bbox[2] for w in words), 5),
        round(max(w.bbox[3] for w in words), 5),
    ]


def _extract_field(doc: fitz.Document, field: dict, meta: list, lang: str, dpi: int,
                   engine_used: dict, dayfirst: bool) -> dict:
    page_no = int(field.get("page", 1))
    page = doc[page_no - 1]
    has_text = meta[page_no - 1]["has_text_layer"]
    words, engine = _words_for(page, field["bbox"], has_text, lang, dpi)
    engine_used.setdefault(str(page_no), engine)

    raw_text = _joined_text(words)
    flat = raw_text.replace("\n", " ").strip()
    value, ok = normalize_value(flat, field.get("type", "text"), dayfirst=dayfirst)
    confidence = _mean_confidence(words)
    if not words and field.get("required"):
        confidence = 0.0
    elif not ok and field.get("type") in ("amount", "qty", "date"):
        confidence = round(confidence * 0.5, 4)

    return {
        "key": field["key"],
        "value": value,
        "raw_text": raw_text,
        "confidence": confidence,
        "page": page_no,
        "bbox": _union_bbox(words) or field["bbox"],
    }


# Columns whose presence marks the start of a real line-item row. A wrapped
# continuation line (e.g. a description spilling onto a second line) has none of
# these, so it is merged back into the row above instead of becoming its own row.
ANCHOR_KEYS = ("quantity", "unit_price", "line_total", "amount")


def _cells_for_line(line: List[Word], columns: list, dayfirst: bool) -> dict:
    cells = {}
    for col in columns:
        col_words = [
            w for w in line
            if col["x0"] <= (w.bbox[0] + w.bbox[2]) / 2 < col["x1"]
        ]
        text = " ".join(w.text for w in col_words).strip()
        col_type = col.get("type") or COLUMN_TYPE_DEFAULTS.get(col["key"], "text")
        value, _ok = normalize_value(text, col_type, dayfirst=dayfirst)
        cells[col["key"]] = {
            "value": value,
            "raw_text": text,
            "confidence": _mean_confidence(col_words),
            "_words": col_words,
        }
    return cells


def _has_anchor(cells: dict, anchor_keys: list) -> bool:
    return any(isinstance(cells.get(k, {}).get("value"), (int, float)) for k in anchor_keys)


def _fill_empty_cells(page: fitz.Page, cells: dict, columns: list, line: List[Word],
                       lang: str, dpi: int, dayfirst: bool, table_bbox: List[float]) -> None:
    """A single OCR pass over the whole row can miss an isolated short value
    (e.g. a lone quantity digit) even while correctly reading the row's
    richer cells — the default page-segmentation mode expects paragraph-like
    structure and under-detects sparse content surrounded by whitespace.
    Re-OCR just the still-empty column(s) as a fallback, narrowed to this
    row's own line height and clamped inside the table's annotated bounds so
    it can never bleed into a header band or an adjacent row.
    """
    if not line:
        return
    y0 = max(table_bbox[1], min(w.bbox[1] for w in line))
    y1 = min(table_bbox[3], max(w.bbox[3] for w in line))
    if y1 <= y0:
        y0, y1 = table_bbox[1], table_bbox[3]
    for col in columns:
        cell = cells.get(col["key"])
        if not cell or cell["raw_text"]:
            continue
        bbox = [col["x0"], y0, col["x1"], y1]
        # A narrow, mostly-blank crop is prone to a stray low-confidence
        # "blob" reading (e.g. a hairline row border) alongside the real
        # value — hold this fallback pass to a higher confidence floor than
        # the primary pass to avoid stitching noise onto a genuine read.
        words = [w for w in ocr_words(page, bbox, lang=lang, dpi=dpi) if w.confidence >= 0.6]
        text = _joined_text(words).replace("\n", " ").strip()
        if not text:
            continue
        col_type = col.get("type") or COLUMN_TYPE_DEFAULTS.get(col["key"], "text")
        value, _ok = normalize_value(text, col_type, dayfirst=dayfirst)
        cell["value"] = value
        cell["raw_text"] = text
        cell["confidence"] = _mean_confidence(words)
        cell["_words"] = words


def _merge_continuation(base: dict, extra: dict, columns: list, dayfirst: bool) -> None:
    """Append a wrapped line's text into the row it belongs to (text columns only)."""
    for col in columns:
        key = col["key"]
        add = extra[key]
        if not add["raw_text"]:
            continue
        cur = base[key]
        cur["_words"] = cur["_words"] + add["_words"]
        cur["raw_text"] = (cur["raw_text"] + " " + add["raw_text"]).strip()
        col_type = col.get("type") or COLUMN_TYPE_DEFAULTS.get(key, "text")
        if col_type == "text":  # numeric anchor values stay as first seen
            value, _ok = normalize_value(cur["raw_text"], col_type, dayfirst=dayfirst)
            cur["value"] = value
        cur["confidence"] = _mean_confidence(cur["_words"])


def _finalize_row(cells: dict, page_no: int, index: int) -> Optional[dict]:
    desc = (cells.get("description", {}).get("raw_text") or "").strip()
    numerics = [
        c for k, c in cells.items()
        if k != "description" and isinstance(c["value"], (int, float))
    ]
    # Standard templates anchor a row on its description or a numeric cell; a
    # fully custom (all-text) template has neither, so fall back to any text.
    any_text = any((c.get("raw_text") or "").strip() for c in cells.values())
    if not desc and not numerics and not any_text:
        return None  # noise row
    all_words = [w for c in cells.values() for w in c["_words"]]
    non_empty = [c["confidence"] for c in cells.values() if c["raw_text"]]
    clean = {k: {kk: vv for kk, vv in c.items() if kk != "_words"} for k, c in cells.items()}
    return {
        "row_index": index,
        "page": page_no,
        "bbox": _union_bbox(all_words),
        "cells": clean,
        "row_confidence": round(min(non_empty), 4) if non_empty else 0.0,
    }


def _extract_table(doc: fitz.Document, table: dict, meta: list, lang: str, dpi: int,
                   engine_used: dict, dayfirst: bool) -> List[dict]:
    first_page = int(table.get("page", 1))
    pages = [first_page]
    if table.get("repeat_on_following_pages"):
        pages += list(range(first_page + 1, len(doc) + 1))

    columns = table["columns"]
    anchor_keys = [k for k in ANCHOR_KEYS if k in {c["key"] for c in columns}]
    rows: List[dict] = []
    for page_no in pages:
        page = doc[page_no - 1]
        has_text = meta[page_no - 1]["has_text_layer"]
        words, engine = _words_for(page, table["bbox"], has_text, lang, dpi)
        engine_used.setdefault(str(page_no), engine)

        grouped: List[dict] = []
        open_row = None  # the last anchored row that continuation lines attach to
        for line in _lines(words):
            cells = _cells_for_line(line, columns, dayfirst)
            if not any(c["raw_text"] for c in cells.values()):
                continue
            if not has_text:
                _fill_empty_cells(page, cells, columns, line, lang, dpi, dayfirst, table["bbox"])
            if anchor_keys and _has_anchor(cells, anchor_keys):
                grouped.append(cells)
                open_row = cells
            elif open_row is not None:
                _merge_continuation(open_row, cells, columns, dayfirst)
            else:
                # No anchor columns, or nothing anchored yet: treat as its own row.
                grouped.append(cells)

        for cells in grouped:
            row = _finalize_row(cells, page_no, len(rows))
            if row:
                rows.append(row)
    return rows


def extract_document(pdf_path: str, template: dict, options: Optional[dict] = None) -> dict:
    import time
    started = time.monotonic()

    settings = get_settings()
    options = options or {}
    # options carries explicit None for unset fields (Pydantic), so `or` the
    # defaults rather than dict.get's fallback which only applies to missing keys.
    lang = options.get("lang") or settings.ocr_lang
    dpi = int(options.get("dpi") or settings.ocr_dpi)
    dayfirst = bool(options.get("dayfirst", False))

    doc = fitz.open(pdf_path)
    try:
        meta = page_meta(doc)
        engine_used: dict = {}

        fields = [
            _extract_field(doc, f, meta, lang, dpi, engine_used, dayfirst)
            for f in template.get("fields", [])
            if f.get("bbox") and 1 <= int(f.get("page", 1)) <= len(doc)
        ]

        line_items: List[dict] = []
        table = template.get("table")
        if table and 1 <= int(table.get("page", 1)) <= len(doc):
            line_items = _extract_table(doc, table, meta, lang, dpi, engine_used, dayfirst)

        line_sum = round(sum(
            r["cells"]["line_total"]["value"]
            for r in line_items
            if isinstance(r["cells"].get("line_total", {}).get("value"), (int, float))
        ), 2)
        total_field = next((f for f in fields if f["key"] == "total_amount"), None)
        extracted_total = total_field["value"] if total_field and isinstance(total_field["value"], (int, float)) else None
        subtotal_field = next((f for f in fields if f["key"] == "subtotal"), None)
        extracted_subtotal = subtotal_field["value"] if subtotal_field and isinstance(subtotal_field["value"], (int, float)) else None

        confidences = [f["confidence"] for f in fields] + [r["row_confidence"] for r in line_items]
        overall = round(sum(confidences) / len(confidences), 4) if confidences else 0.0

        return {
            "engine_used": engine_used,
            "fields": fields,
            "line_items": line_items,
            "totals_check": {
                "line_sum": line_sum,
                "extracted_total": extracted_total,
                "extracted_subtotal": extracted_subtotal,
                "delta": round(extracted_total - line_sum, 2) if extracted_total is not None else None,
            },
            "overall_confidence": overall,
            "page_meta": meta,
            "duration_ms": int((time.monotonic() - started) * 1000),
        }
    finally:
        doc.close()
