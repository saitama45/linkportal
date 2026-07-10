"""DOC/DOCX -> PDF via LibreOffice headless.

LibreOffice refuses to run two instances against one user profile (a real
problem on Windows), so every call gets a throwaway -env:UserInstallation
profile AND conversions are serialized with a lock.
"""
import subprocess
import tempfile
import threading
import uuid
from pathlib import Path

from .config import get_settings

_convert_lock = threading.Lock()


class ConversionError(RuntimeError):
    pass


def convert_to_pdf(input_path: str, output_dir: str, timeout: int = 180) -> dict:
    src = Path(input_path)
    if not src.is_file():
        raise ConversionError(f"input file not found: {input_path}")
    out_dir = Path(output_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    settings = get_settings()
    profile_dir = Path(tempfile.gettempdir()) / f"lo-profile-{uuid.uuid4().hex}"
    profile_uri = profile_dir.as_uri()

    cmd = [
        settings.soffice_path,
        f"-env:UserInstallation={profile_uri}",
        "--headless", "--norestore",
        "--convert-to", "pdf",
        "--outdir", str(out_dir),
        str(src),
    ]

    with _convert_lock:
        try:
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        except subprocess.TimeoutExpired as exc:
            raise ConversionError(f"soffice timed out after {timeout}s") from exc
        except FileNotFoundError as exc:
            raise ConversionError(f"soffice not found at {settings.soffice_path}") from exc

    pdf_path = out_dir / (src.stem + ".pdf")
    if result.returncode != 0 or not pdf_path.is_file():
        raise ConversionError(
            f"conversion failed (exit {result.returncode}): {result.stderr.strip() or result.stdout.strip()}"
        )
    return {"pdf_path": str(pdf_path)}


def soffice_version() -> str:
    try:
        result = subprocess.run(
            [get_settings().soffice_path, "--version"],
            capture_output=True, text=True, timeout=30,
        )
        return result.stdout.strip() or "unknown"
    except Exception:
        return "unavailable"
