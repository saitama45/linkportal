from app.normalize import parse_amount, parse_date, parse_quantity


def test_amount_with_peso_and_commas():
    assert parse_amount("₱ 1,234.56") == 1234.56


def test_amount_plain():
    assert parse_amount("300.00") == 300.00


def test_amount_garbage():
    assert parse_amount("N/A") is None
    assert parse_amount("") is None


def test_quantity():
    assert parse_quantity("1,000") == 1000.0
    assert parse_quantity("2.5") == 2.5


def test_date_us_format():
    assert parse_date("06/15/2026") == "2026-06-15"


def test_date_dayfirst():
    assert parse_date("15/06/2026", dayfirst=True) == "2026-06-15"


def test_date_written():
    assert parse_date("June 15, 2026") == "2026-06-15"


def test_date_garbage():
    assert parse_date("pending") is None


def test_date_rejects_underspecified():
    """An incomplete read must not be completed from today's date — dateutil
    fills missing components from a default, which would turn a fragment into a
    confident, fabricated date."""
    assert parse_date("2026") is None
    assert parse_date("June 2026") is None
    assert parse_date("Dec 2026") is None
    assert parse_date("une") is None


def test_date_repairs_digit_shaped_letters():
    """A photographed "08," commonly OCRs as "0B,"; the day is still recoverable."""
    assert parse_date("June 0B, 2026") == "2026-06-08"
    assert parse_date(": June 0B, 2026 ; |") == "2026-06-08"
    assert parse_date("June O8, 2026") == "2026-06-08"


def test_date_repair_leaves_month_name_intact():
    """Digit repair must only touch numeric-looking tokens, never a month name
    (a naive substitution would rewrite the "S" in "Sep" to a 5)."""
    assert parse_date("Sep 05, 2026") == "2026-09-05"
    assert parse_date("August 15, 2026") == "2026-08-15"
