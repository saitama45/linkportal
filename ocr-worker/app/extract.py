"""Template-driven field and line-item extraction.

Strategy per region: if the page has a usable text layer, read embedded words
(confidence 0.99); otherwise render the clip and OCR it. The template's bboxes
and the returned word boxes are all normalized page-relative 0..1.
"""
from statistics import median
from typing import List, Optional

import fitz

from .config import get_settings
from .engines.tesseract_engine import NUMERIC_WHITELIST, ocr_words
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


# A character whitelist is applied only to the purely numeric types: constraining
# the alphabet measurably degrades Tesseract's LSTM on text-bearing values (it
# drops the month name off a date entirely).
TYPED = ("date", "amount", "qty")
TYPE_WHITELISTS = {"amount": NUMERIC_WHITELIST, "qty": NUMERIC_WHITELIST}

# Below this confidence a field is re-read from a larger render. Digit damage on a
# photographed page is often just a shortage of pixels: at the base resolution a
# "0" fused to a table rule reads as "9", and the same region rendered several
# times larger resolves it. Matches the threshold the validation screen calls low.
ESCALATE_BELOW = 0.75
ESCALATION_FACTOR = 4
ESCALATION_DPI_CAP = 1600


def _candidate(words: List[Word], field_type: str, dayfirst: bool) -> dict:
    raw_text = _joined_text(words)
    flat = raw_text.replace("\n", " ").strip()
    value, ok = normalize_value(flat, field_type, dayfirst=dayfirst)
    return {
        "words": words, "raw_text": raw_text, "flat": flat,
        "value": value, "ok": ok, "confidence": _mean_confidence(words),
    }


def _better(new: dict, current: dict, typed: bool) -> dict:
    """For a typed field a value that actually parses beats one that does not,
    however sure the engine was of its guess; otherwise the more confident read
    wins. Tesseract's own confidence tracks correctness well within one region,
    so this reliably picks the good read out of a set of passes."""
    if typed and new["ok"] != current["ok"]:
        return new if new["ok"] else current
    return new if new["confidence"] > current["confidence"] else current


def _extract_field(doc: fitz.Document, field: dict, meta: list, lang: str, dpi: int,
                   engine_used: dict, dayfirst: bool) -> dict:
    page_no = int(field.get("page", 1))
    page = doc[page_no - 1]
    has_text = meta[page_no - 1]["has_text_layer"]
    bbox = field["bbox"]
    words, engine = _words_for(page, bbox, has_text, lang, dpi)
    engine_used.setdefault(str(page_no), engine)

    field_type = field.get("type", "text")
    typed = field_type in TYPED
    best = _candidate(words, field_type, dayfirst)

    # A page with a text layer is read exactly, so extra passes would add nothing.
    # A photographed one is worth reading more than one way: the default
    # segmentation expects paragraph structure and on a short isolated value it
    # both fragments the text ("une" for an invoice date) and mis-shapes digits
    # ("11642" for 11612), while reporting healthy confidence either way. Reading
    # the region several ways and keeping the best-scoring result costs a few
    # extra crops on a handful of header fields and corrects both failures.
    if engine == "tesseract":
        whitelist = TYPE_WHITELISTS.get(field_type)
        best = _better(_candidate(
            ocr_words(page, bbox, lang=lang, dpi=dpi, psm=7,
                      whitelist=whitelist, min_conf=30, enhance=True),
            field_type, dayfirst), best, typed)

        if not best["ok"] or best["confidence"] < ESCALATE_BELOW:
            hi_dpi = min(dpi * ESCALATION_FACTOR, ESCALATION_DPI_CAP)
            if hi_dpi > dpi:
                best = _better(_candidate(
                    ocr_words(page, bbox, lang=lang, dpi=hi_dpi, psm=7,
                              whitelist=whitelist, min_conf=30, enhance=True),
                    field_type, dayfirst), best, typed)

    words, raw_text, value, ok = best["words"], best["raw_text"], best["value"], best["ok"]
    confidence = best["confidence"]
    if not words and field.get("required"):
        confidence = 0.0
    elif not ok and typed:
        confidence = round(confidence * 0.5, 4)

    return {
        "key": field["key"],
        "value": value,
        "raw_text": raw_text,
        "confidence": confidence,
        "page": page_no,
        "bbox": _union_bbox(words) or bbox,
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


# How far a read line total may sit from quantity x unit_price and still be
# treated as a damaged read of that product rather than a real difference. OCR
# damage concentrates in the trailing digits — a table rule fused to the last
# glyph turns "4,700.00" into "4,700.04" — so this is an absolute cents-level
# allowance, deliberately not a percentage: a genuine discount or a real pricing
# discrepancy is always larger than this and must survive to a human.
ARITHMETIC_TOLERANCE = 1.0


def _cell_number(cells: dict, key: str) -> Optional[float]:
    value = cells.get(key, {}).get("value")
    return value if isinstance(value, (int, float)) else None


def _apply_derived(cells: dict, key: str, value: float, source: str) -> None:
    """Write a value the arithmetic produced, leaving raw_text as whatever OCR
    actually read so the original is still auditable on the validation screen."""
    cell = cells.get(key)
    if cell is None:
        return
    cell["value"] = round(value, 2)
    cell["derived_from"] = source


def _reconcile_row_arithmetic(cells: dict) -> None:
    """A standard line item satisfies quantity x unit_price = line_total.

    That invariant is stronger evidence than any single read: the quantity and
    unit price are short, well-separated values that OCR gets right far more
    often than the line total, which sits hard against the table's right-hand
    rule and picks up its stroke. So when two of the three are known the third
    is filled in, and a line total that disagrees with the product only at the
    cents level is replaced by it. A larger disagreement is left exactly as read
    — it may be a real discrepancy, and silently "fixing" it would destroy the
    signal an approver needs.
    """
    if not all(k in cells for k in ("quantity", "unit_price", "line_total")):
        return

    qty = _cell_number(cells, "quantity")
    price = _cell_number(cells, "unit_price")
    total = _cell_number(cells, "line_total")

    if qty is not None and price is not None:
        expected = qty * price
        if total is None:
            _apply_derived(cells, "line_total", expected, "quantity x unit_price")
        elif abs(total - expected) <= ARITHMETIC_TOLERANCE and round(total, 2) != round(expected, 2):
            _apply_derived(cells, "line_total", expected, "quantity x unit_price")
    elif total is not None and qty is not None and price is None and qty:
        _apply_derived(cells, "unit_price", total / qty, "line_total / quantity")
    elif total is not None and price is not None and qty is None and price:
        _apply_derived(cells, "quantity", total / price, "line_total / unit_price")


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
            # Only once the row is whole — continuation lines merged, empty cells
            # retried — is the quantity/price/total triple final enough to trust.
            _reconcile_row_arithmetic(cells)
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
