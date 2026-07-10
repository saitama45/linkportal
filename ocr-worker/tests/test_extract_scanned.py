import pytest

from app.extract import extract_document
from .conftest import tesseract_available

pytestmark = pytest.mark.skipif(
    not tesseract_available(), reason="tesseract binary not available"
)


def _field(result, key):
    return next(f for f in result["fields"] if f["key"] == key)


def test_scanned_page_has_no_text_layer(scanned_invoice, invoice_template):
    result = extract_document(scanned_invoice, invoice_template)
    assert result["page_meta"][0]["has_text_layer"] is False
    assert result["engine_used"]["1"] == "tesseract"


def test_scanned_fields_meet_threshold(scanned_invoice, invoice_template):
    result = extract_document(scanned_invoice, invoice_template)

    invoice_no = _field(result, "invoice_no")
    assert "INV" in str(invoice_no["value"])
    assert invoice_no["confidence"] >= 0.5

    total = _field(result, "total_amount")
    assert total["value"] == pytest.approx(1456.00, abs=0.01)

    assert len(result["line_items"]) == 2
    assert result["overall_confidence"] >= 0.5
