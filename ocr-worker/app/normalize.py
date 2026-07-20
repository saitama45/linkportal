"""Value normalization for extracted field text.

Amounts arrive with currency symbols and comma grouping (PHP-style: 1,234.56).
Dates are parsed loosely with dateutil; day-first is off by default because the
sample vendors use MM/DD/YYYY — flip via template option later if needed.
"""
import re
from datetime import datetime
from typing import Optional

from dateutil import parser as dateparser

_AMOUNT_CLEAN = re.compile(r"[^\d.\-]")
_QTY_CLEAN = re.compile(r"[^\d.\-]")

# Letters an OCR pass commonly returns in place of a digit. Applied only to a
# token that already reads as mostly numeric, so a month name is never mangled.
_CONFUSABLE = str.maketrans({
    "B": "8", "O": "0", "o": "0", "D": "0",
    "l": "1", "I": "1", "|": "1", "S": "5", "s": "5", "Z": "2", "z": "2",
})

# Two unrelated defaults: dateutil fills components the text does not specify,
# so a value that is genuinely underspecified lands on two different dates.
_DEFAULT_A = datetime(2000, 1, 1)
_DEFAULT_B = datetime(2001, 2, 2)


def parse_amount(raw: str) -> Optional[float]:
    if not raw:
        return None
    cleaned = _AMOUNT_CLEAN.sub("", raw.replace(",", ""))
    if cleaned in ("", "-", ".", "-."):
        return None
    try:
        return round(float(cleaned), 2)
    except ValueError:
        return None


def parse_quantity(raw: str) -> Optional[float]:
    if not raw:
        return None
    cleaned = _QTY_CLEAN.sub("", raw.replace(",", ""))
    if cleaned in ("", "-", ".", "-."):
        return None
    try:
        return round(float(cleaned), 4)
    except ValueError:
        return None


def _repair_digits(raw: str) -> str:
    """Map digit-shaped letters back to digits inside numeric-looking tokens.
    A photographed "08," is often read as "0B," — repairing it recovers the day,
    while "June" (no digits) is left untouched."""
    out = []
    for token in raw.split():
        alnum = [c for c in token if c.isalnum()]
        digits = sum(c.isdigit() for c in alnum)
        out.append(token.translate(_CONFUSABLE) if digits and digits * 2 >= len(alnum) else token)
    return " ".join(out)


def _parse_complete(raw: str, dayfirst: bool) -> Optional[str]:
    """Parse only when the text actually specifies day, month and year.

    dateutil fills anything the text omits from a default date, so a fragment
    like "2026" would otherwise come back as a confident full date built from
    today — a fabricated value that reads as a successful extraction. Parsing
    against two different defaults and requiring an identical result rejects
    any input that did not pin down every component itself.
    """
    try:
        a = dateparser.parse(raw, dayfirst=dayfirst, fuzzy=True, default=_DEFAULT_A)
        b = dateparser.parse(raw, dayfirst=dayfirst, fuzzy=True, default=_DEFAULT_B)
    except (ValueError, OverflowError, TypeError):
        return None
    if a is None or b is None or a.date() != b.date():
        return None
    return a.date().isoformat()


def parse_date(raw: str, dayfirst: bool = False) -> Optional[str]:
    if not raw or not re.search(r"\d", raw):
        return None
    return _parse_complete(raw, dayfirst) or _parse_complete(_repair_digits(raw), dayfirst)


def normalize_value(raw: str, field_type: str, dayfirst: bool = False):
    """Returns (value, ok). value falls back to stripped raw text when parsing fails."""
    raw = (raw or "").strip()
    if field_type == "amount":
        parsed = parse_amount(raw)
        return (parsed, parsed is not None)
    if field_type == "qty":
        parsed = parse_quantity(raw)
        return (parsed, parsed is not None)
    if field_type == "date":
        parsed = parse_date(raw, dayfirst=dayfirst)
        return (parsed if parsed is not None else raw, parsed is not None)
    return (raw, bool(raw))
