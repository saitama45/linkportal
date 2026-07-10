"""Golden fixtures are generated, not stored: a digital invoice PDF drawn at
known coordinates, and a "scanned" variant (same page re-embedded as an image
so it has no text layer). The template fixture's bboxes match the drawing.
"""
import io

import fitz
import pytest

PAGE_W, PAGE_H = 612, 792


def _norm(x0, y0, x1, y1):
    return [x0 / PAGE_W, y0 / PAGE_H, x1 / PAGE_W, y1 / PAGE_H]


def _build_digital_invoice() -> bytes:
    doc = fitz.open()
    page = doc.new_page(width=PAGE_W, height=PAGE_H)
    put = lambda x, y, text, size=10: page.insert_text((x, y), text, fontsize=size)

    put(50, 60, "ACME SUPPLIES INC.", 14)
    put(50, 80, "123 Vendor Street, Makati City")

    put(380, 60, "Invoice No:")
    put(460, 60, "INV-1001")
    put(380, 78, "Invoice Date:")
    put(460, 78, "06/15/2026")
    put(380, 96, "PO Number:")
    put(460, 96, "PO-555")

    # line-item table header (outside the template's table bbox)
    put(50, 280, "Description")
    put(310, 280, "Qty")
    put(370, 280, "UOM")
    put(430, 280, "Unit Price")
    put(510, 280, "Line Total")

    rows = [
        ("Widget A", "10", "PCS", "100.00", "1,000.00"),
        ("Gadget B", "2", "BOX", "150.00", "300.00"),
    ]
    y = 305
    for desc, qty, uom, price, total in rows:
        put(50, y, desc)
        put(310, y, qty)
        put(370, y, uom)
        put(430, y, price)
        put(510, y, total)
        y += 22

    put(430, 420, "Subtotal:")
    put(510, 420, "1,300.00")
    put(430, 438, "Tax (12%):")
    put(510, 438, "156.00")
    put(430, 456, "TOTAL:")
    put(510, 456, "1,456.00")

    data = doc.tobytes()
    doc.close()
    return data


@pytest.fixture(scope="session")
def digital_invoice(tmp_path_factory):
    path = tmp_path_factory.mktemp("fixtures") / "digital_invoice.pdf"
    path.write_bytes(_build_digital_invoice())
    return str(path)


@pytest.fixture(scope="session")
def scanned_invoice(tmp_path_factory, digital_invoice):
    """Image-only variant: render the digital page @200dpi, embed as picture."""
    src = fitz.open(digital_invoice)
    pix = src[0].get_pixmap(matrix=fitz.Matrix(200 / 72, 200 / 72))
    src.close()

    doc = fitz.open()
    page = doc.new_page(width=PAGE_W, height=PAGE_H)
    page.insert_image(fitz.Rect(0, 0, PAGE_W, PAGE_H), stream=io.BytesIO(pix.tobytes("png")).getvalue())
    path = tmp_path_factory.mktemp("fixtures") / "scanned_invoice.pdf"
    doc.save(str(path))
    doc.close()
    return str(path)


@pytest.fixture(scope="session")
def invoice_template():
    return {
        "fields": [
            {"key": "invoice_no", "type": "text", "required": True, "page": 1,
             "bbox": _norm(455, 46, 590, 66)},
            {"key": "document_date", "type": "date", "required": True, "page": 1,
             "bbox": _norm(455, 64, 590, 84)},
            {"key": "po_number", "type": "text", "required": False, "page": 1,
             "bbox": _norm(455, 82, 590, 102)},
            {"key": "vendor_address", "type": "text", "required": False, "page": 1,
             "bbox": _norm(45, 66, 350, 88)},
            {"key": "subtotal", "type": "amount", "required": False, "page": 1,
             "bbox": _norm(505, 406, 600, 426)},
            {"key": "tax_amount", "type": "amount", "required": False, "page": 1,
             "bbox": _norm(505, 424, 600, 444)},
            {"key": "total_amount", "type": "amount", "required": True, "page": 1,
             "bbox": _norm(505, 442, 600, 462)},
        ],
        "table": {
            "page": 1,
            "repeat_on_following_pages": False,
            "bbox": _norm(45, 288, 600, 360),
            "columns": [
                {"key": "description", "x0": 45 / PAGE_W, "x1": 300 / PAGE_W},
                {"key": "quantity", "x0": 300 / PAGE_W, "x1": 360 / PAGE_W},
                {"key": "uom", "x0": 360 / PAGE_W, "x1": 420 / PAGE_W},
                {"key": "unit_price", "x0": 420 / PAGE_W, "x1": 500 / PAGE_W},
                {"key": "line_total", "x0": 500 / PAGE_W, "x1": 600 / PAGE_W},
            ],
        },
    }


def _build_multiline_invoice() -> bytes:
    """Invoice where the first line item's description wraps onto two lines."""
    doc = fitz.open()
    page = doc.new_page(width=PAGE_W, height=PAGE_H)
    put = lambda x, y, text, size=10: page.insert_text((x, y), text, fontsize=size)

    put(50, 280, "Description")
    put(360, 280, "Qty")
    put(430, 280, "Unit Price")
    put(510, 280, "Line Total")

    # Row 1 — description spills onto a second line; numbers only on the first line
    put(50, 305, "Professional consulting services for")
    put(50, 320, "Q1 privacy assessment program")
    put(360, 305, "1")
    put(430, 305, "5,000.00")
    put(510, 305, "5,000.00")

    # Row 2 — single line
    put(50, 350, "Training workshop")
    put(360, 350, "2")
    put(430, 350, "1,000.00")
    put(510, 350, "2,000.00")

    data = doc.tobytes()
    doc.close()
    return data


@pytest.fixture(scope="session")
def multiline_invoice(tmp_path_factory):
    path = tmp_path_factory.mktemp("fixtures") / "multiline_invoice.pdf"
    path.write_bytes(_build_multiline_invoice())
    return str(path)


@pytest.fixture(scope="session")
def multiline_template():
    return {
        "fields": [],
        "table": {
            "page": 1,
            "repeat_on_following_pages": False,
            "bbox": _norm(45, 295, 600, 365),
            "columns": [
                {"key": "description", "x0": 45 / PAGE_W, "x1": 350 / PAGE_W},
                {"key": "quantity", "x0": 350 / PAGE_W, "x1": 420 / PAGE_W},
                {"key": "unit_price", "x0": 420 / PAGE_W, "x1": 500 / PAGE_W},
                {"key": "line_total", "x0": 500 / PAGE_W, "x1": 600 / PAGE_W},
            ],
        },
    }


def tesseract_available() -> bool:
    from app.engines.tesseract_engine import tesseract_version
    return tesseract_version() != "unavailable"


def soffice_available() -> bool:
    from app.convert import soffice_version
    return soffice_version() != "unavailable"
