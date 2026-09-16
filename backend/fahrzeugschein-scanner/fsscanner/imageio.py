"""Bild-Ein-/Ausgabe: Bytes/PDF -> OpenCV-Bild, EXIF-Orientierung, Base64."""
import base64
import os
import subprocess
import tempfile

import cv2
import numpy as np


def load_image(data: bytes, is_pdf: bool = False) -> np.ndarray:
    """Dekodiert Bild- oder PDF-Bytes zu einem BGR-Bild (EXIF-Orientierung wird beachtet)."""
    if is_pdf or data[:5] == b"%PDF-":
        return _pdf_first_page(data)
    arr = np.frombuffer(data, np.uint8)
    # IMREAD_COLOR ignoriert EXIF nicht (OpenCV >= 3.1 dreht automatisch) -> ok
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError("Bild konnte nicht dekodiert werden")
    return img


def _pdf_first_page(data: bytes, dpi: int = 300) -> np.ndarray:
    """Erste PDF-Seite per pdftoppm (poppler) rendern."""
    with tempfile.TemporaryDirectory() as tmp:
        pdf = os.path.join(tmp, "in.pdf")
        with open(pdf, "wb") as f:
            f.write(data)
        subprocess.run(
            ["pdftoppm", "-r", str(dpi), "-f", "1", "-l", "1", "-jpeg", pdf, os.path.join(tmp, "out")],
            check=True, capture_output=True,
        )
        outs = sorted(p for p in os.listdir(tmp) if p.startswith("out"))
        if not outs:
            raise ValueError("PDF konnte nicht gerendert werden")
        img = cv2.imread(os.path.join(tmp, outs[0]))
        if img is None:
            raise ValueError("PDF-Seite konnte nicht gelesen werden")
        return img


def load_file(path: str) -> np.ndarray:
    with open(path, "rb") as f:
        return load_image(f.read(), path.lower().endswith(".pdf"))


def to_jpeg_b64(img: np.ndarray, quality: int = 85) -> str:
    ok, buf = cv2.imencode(".jpg", img, [cv2.IMWRITE_JPEG_QUALITY, quality])
    if not ok:
        raise ValueError("JPEG-Kodierung fehlgeschlagen")
    return base64.b64encode(buf.tobytes()).decode("ascii")


def limit_size(img: np.ndarray, max_side: int) -> np.ndarray:
    """Verkleinert (nie vergroessert) so, dass die laengste Seite <= max_side ist."""
    h, w = img.shape[:2]
    s = max_side / max(h, w)
    if s >= 1.0:
        return img
    return cv2.resize(img, (int(round(w * s)), int(round(h * s))), interpolation=cv2.INTER_AREA)
