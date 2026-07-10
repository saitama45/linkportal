import pytest

from app.convert import ConversionError, convert_to_pdf
from .conftest import soffice_available


def test_missing_input_raises(tmp_path):
    with pytest.raises(ConversionError, match="not found"):
        convert_to_pdf(str(tmp_path / "nope.docx"), str(tmp_path))


@pytest.mark.skipif(not soffice_available(), reason="LibreOffice not available")
def test_converts_document_to_pdf(tmp_path):
    src = tmp_path / "sample.txt"
    src.write_text("Hello vendor document\nLine two")
    result = convert_to_pdf(str(src), str(tmp_path / "out"))
    assert result["pdf_path"].endswith("sample.pdf")

    import fitz
    with fitz.open(result["pdf_path"]) as doc:
        assert len(doc) >= 1
        assert "Hello vendor document" in doc[0].get_text()
