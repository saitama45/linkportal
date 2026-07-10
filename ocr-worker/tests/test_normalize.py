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
