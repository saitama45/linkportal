from app.extract import extract_document


def _field(result, key):
    return next(f for f in result["fields"] if f["key"] == key)


def test_page_meta_reports_text_layer(digital_invoice, invoice_template):
    result = extract_document(digital_invoice, invoice_template)
    assert result["page_meta"][0]["has_text_layer"] is True
    assert result["engine_used"]["1"] == "embedded"


def test_header_fields_exact(digital_invoice, invoice_template):
    result = extract_document(digital_invoice, invoice_template)
    assert _field(result, "invoice_no")["value"] == "INV-1001"
    assert _field(result, "document_date")["value"] == "2026-06-15"
    assert _field(result, "po_number")["value"] == "PO-555"
    assert _field(result, "subtotal")["value"] == 1300.00
    assert _field(result, "tax_amount")["value"] == 156.00
    assert _field(result, "total_amount")["value"] == 1456.00
    for f in result["fields"]:
        assert f["confidence"] > 0.9, f"{f['key']} confidence too low"


def test_line_items(digital_invoice, invoice_template):
    result = extract_document(digital_invoice, invoice_template)
    items = result["line_items"]
    assert len(items) == 2

    first = items[0]["cells"]
    assert first["description"]["value"] == "Widget A"
    assert first["quantity"]["value"] == 10.0
    assert first["uom"]["value"] == "PCS"
    assert first["unit_price"]["value"] == 100.00
    assert first["line_total"]["value"] == 1000.00

    second = items[1]["cells"]
    assert second["description"]["value"] == "Gadget B"
    assert second["line_total"]["value"] == 300.00

    assert all(r["row_confidence"] > 0.9 for r in items)


def test_totals_check(digital_invoice, invoice_template):
    result = extract_document(digital_invoice, invoice_template)
    check = result["totals_check"]
    assert check["line_sum"] == 1300.00
    assert check["extracted_total"] == 1456.00
    assert check["extracted_subtotal"] == 1300.00
    # delta vs total includes tax; the Laravel rule reconciles against subtotal+tax
    assert check["delta"] == 156.00


def test_options_with_none_values(digital_invoice, invoice_template):
    # The API sends explicit None for unset lang/dpi (Pydantic model_dump).
    result = extract_document(digital_invoice, invoice_template,
                              {"engine": "auto", "lang": None, "dpi": None, "dayfirst": False})
    assert _field(result, "invoice_no")["value"] == "INV-1001"


def test_null_bbox_fields_are_skipped(digital_invoice, invoice_template):
    # Un-annotated fields arrive with bbox=None; they must be ignored, not error.
    template = {
        "fields": [
            {"key": "invoice_no", "type": "text", "required": True, "page": 1,
             "bbox": invoice_template["fields"][0]["bbox"]},
            {"key": "po_number", "type": "text", "required": False, "page": 1, "bbox": None},
            {"key": "total_amount", "type": "amount", "required": True, "page": 1, "bbox": None},
        ],
    }
    result = extract_document(digital_invoice, template)
    keys = [f["key"] for f in result["fields"]]
    assert keys == ["invoice_no"]
    assert _field(result, "invoice_no")["value"] == "INV-1001"


def test_missing_required_field_gets_zero_confidence(digital_invoice, invoice_template):
    template = {
        "fields": [
            # bbox over empty whitespace
            {"key": "invoice_no", "type": "text", "required": True, "page": 1,
             "bbox": [0.02, 0.90, 0.20, 0.95]},
        ],
    }
    result = extract_document(digital_invoice, template)
    field = result["fields"][0]
    assert field["raw_text"] == ""
    assert field["confidence"] == 0.0


def test_multiline_description_merges_into_one_row(multiline_invoice, multiline_template):
    # A wrapped description must NOT split into extra rows; it merges into the
    # anchored row (the line carrying qty/price/total).
    result = extract_document(multiline_invoice, multiline_template)
    items = result["line_items"]
    assert len(items) == 2

    first = items[0]["cells"]
    assert "Professional consulting services for" in first["description"]["value"]
    assert "Q1 privacy assessment program" in first["description"]["value"]
    assert first["quantity"]["value"] == 1.0
    assert first["line_total"]["value"] == 5000.00

    second = items[1]["cells"]
    assert second["description"]["value"] == "Training workshop"
    assert second["line_total"]["value"] == 2000.00
