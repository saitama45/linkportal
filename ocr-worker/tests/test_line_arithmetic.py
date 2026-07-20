"""quantity x unit_price = line_total reconciliation on extracted rows."""
from app.extract import _reconcile_row_arithmetic


def _row(qty, price, total):
    """A minimal cell dict shaped like the one _cells_for_line produces."""
    def cell(value):
        return {"value": value, "raw_text": "" if value is None else str(value),
                "confidence": 0.8, "_words": []}
    return {"quantity": cell(qty), "unit_price": cell(price), "line_total": cell(total)}


def test_corrects_cents_level_misread():
    """The case from the PC Worx invoice: the table rule fused to the last digit
    turned 4,700.00 into 4,700.04 / .09."""
    row = _row(1, 4700.0, 4700.09)
    _reconcile_row_arithmetic(row)
    assert row["line_total"]["value"] == 4700.00
    assert row["line_total"]["derived_from"] == "quantity x unit_price"


def test_correction_keeps_the_original_text_auditable():
    row = _row(1, 4700.0, 4700.04)
    _reconcile_row_arithmetic(row)
    assert row["line_total"]["value"] == 4700.00
    assert row["line_total"]["raw_text"] == "4700.04"


def test_leaves_a_real_discrepancy_alone():
    """A genuine difference (discount, wrong price, over-billing) must survive to
    a human — it is the signal approval depends on."""
    row = _row(2, 4700.0, 8000.0)
    _reconcile_row_arithmetic(row)
    assert row["line_total"]["value"] == 8000.0
    assert "derived_from" not in row["line_total"]


def test_leaves_a_correct_row_untouched():
    row = _row(3, 250.0, 750.0)
    _reconcile_row_arithmetic(row)
    assert row["line_total"]["value"] == 750.0
    assert "derived_from" not in row["line_total"]


def test_fills_a_missing_line_total():
    row = _row(4, 125.5, None)
    _reconcile_row_arithmetic(row)
    assert row["line_total"]["value"] == 502.0
    assert row["line_total"]["derived_from"] == "quantity x unit_price"


def test_fills_a_missing_unit_price():
    row = _row(4, None, 500.0)
    _reconcile_row_arithmetic(row)
    assert row["unit_price"]["value"] == 125.0


def test_fills_a_missing_quantity():
    row = _row(None, 125.0, 500.0)
    _reconcile_row_arithmetic(row)
    assert row["quantity"]["value"] == 4.0


def test_survives_a_row_with_nothing_numeric():
    row = _row(None, None, None)
    _reconcile_row_arithmetic(row)
    assert row["line_total"]["value"] is None


def test_never_divides_by_zero():
    _reconcile_row_arithmetic(_row(0, None, 500.0))
    _reconcile_row_arithmetic(_row(None, 0, 500.0))


def test_ignores_a_template_without_the_amount_columns():
    """A fully custom, all-text template has no arithmetic to apply."""
    row = {"description": {"value": "widget", "raw_text": "widget", "confidence": 0.9, "_words": []}}
    _reconcile_row_arithmetic(row)
    assert row["description"]["value"] == "widget"
