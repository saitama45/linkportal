"""Value normalization for extracted field text.

Amounts arrive with currency symbols and comma grouping (PHP-style: 1,234.56).
Dates are parsed loosely with dateutil; day-first is off by default because the
sample vendors use MM/DD/YYYY — flip via template option later if needed.
"""
import re
from typing import Optional

from dateutil import parser as dateparser

_AMOUNT_CLEAN = re.compile(r"[^\d.\-]")
_QTY_CLEAN = re.compile(r"[^\d.\-]")


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


def parse_date(raw: str, dayfirst: bool = False) -> Optional[str]:
    if not raw or not re.search(r"\d", raw):
        return None
    try:
        return dateparser.parse(raw, dayfirst=dayfirst, fuzzy=True).date().isoformat()
    except (ValueError, OverflowError):
        return None


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
