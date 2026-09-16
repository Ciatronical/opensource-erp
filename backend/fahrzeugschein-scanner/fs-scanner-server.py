#!/usr/bin/env python3
"""
Fahrzeugscheinscanner-Dienst (lokal, CPU)
=========================================

Dauerhaft laufender HTTP-Dienst, der Referenzvorlage, Layout und OCR-Modell EINMAL
laedt und Fahrzeugschein-Fotos (JPEG/PNG/PDF) per POST entgegennimmt. Ersetzt die
externe API von fahrzeugschein-scanner.de — es verlaesst kein Bild den Rechner.

Aufruf:
    POST http://127.0.0.1:3003/scan
    Body (JSON):   {"image": "<base64>", "is_pdf": false, "with_images": true}
    Body (binaer): rohe Bild-/PDF-Bytes mit Content-Type image/* bzw. application/pdf
    Header (optional): X-Scanner-Token: <geheim>   (wenn FSSCANNER_TOKEN gesetzt)

Antwort (JSON, Format der bisherigen API):
    {"ok": true, "data": {"vin": "...", "vin_img": "<base64>", ..., "document_img": "<base64>"},
     "country_code": "de", "meta": {"inliers": 300, "elapsed": 3.2, ...}}

Healthcheck:
    GET http://127.0.0.1:3003/health -> {"ok": true, "ready": true, "fields": 62, "version": "..."}

Konfiguration ueber Umgebungsvariablen:
    FSSCANNER_PORT       Port (Standard 3003)
    FSSCANNER_HOST       Bind-Adresse (Standard 127.0.0.1)
    FSSCANNER_TOKEN      optionales Shared-Secret (Header X-Scanner-Token)
    FSSCANNER_REFERENCE  Pfad zur Referenzvorlage (Standard reference/zb1_reference.jpg)
    FSSCANNER_LAYOUT     Pfad zum Layout (Standard reference/layout.json)
"""
import base64
import json
import os
import sys
import threading
import traceback
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from fsscanner import __version__  # noqa: E402
from fsscanner.pipeline import Scanner  # noqa: E402
from fsscanner.register import RegistrationError  # noqa: E402

HOST = os.environ.get("FSSCANNER_HOST", "127.0.0.1")
PORT = int(os.environ.get("FSSCANNER_PORT", "3003"))
TOKEN = os.environ.get("FSSCANNER_TOKEN", "")
MAX_BYTES = 40 * 1024 * 1024  # Handyfotos sind < 15 MB, PDFs koennen groesser sein

print(f"[fsscanner] Lade Vorlage, Layout und OCR-Modell ...", flush=True)
scanner = Scanner(os.environ.get("FSSCANNER_REFERENCE") or None, os.environ.get("FSSCANNER_LAYOUT") or None)
# OCR ist nicht thread-sicher genug fuer parallele Batches -> serialisieren
scan_lock = threading.Lock()
print(f"[fsscanner] bereit, {len(scanner.layout.fields)} Felder, lausche auf {HOST}:{PORT}", flush=True)


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):  # journal nicht zumuellen
        pass

    def _json(self, code, payload):
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/health":
            self._json(200, {"ok": True, "ready": True, "version": __version__,
                             "fields": len(scanner.layout.fields), "engine": "local"})
            return
        self._json(404, {"ok": False, "error": "not found"})

    def do_POST(self):
        if self.path != "/scan":
            self._json(404, {"ok": False, "error": "not found"})
            return
        if TOKEN and self.headers.get("X-Scanner-Token", "") != TOKEN:
            self._json(401, {"ok": False, "error": "unauthorized"})
            return
        length = int(self.headers.get("Content-Length", "0") or 0)
        if length <= 0 or length > MAX_BYTES:
            self._json(413 if length > MAX_BYTES else 400, {"ok": False, "error": "bad content length"})
            return
        body = self.rfile.read(length)
        ctype = (self.headers.get("Content-Type") or "").lower()
        with_images = True
        is_pdf = False
        try:
            if ctype.startswith("application/json"):
                req = json.loads(body.decode("utf-8"))
                b64 = req.get("image", "")
                if "base64," in b64:
                    b64 = b64.split("base64,", 1)[1]
                data = base64.b64decode(b64)
                is_pdf = bool(req.get("is_pdf"))
                with_images = req.get("with_images", True)
            else:
                data = body
                is_pdf = ctype.startswith("application/pdf")
        except Exception as e:  # noqa: BLE001
            self._json(400, {"ok": False, "error": f"invalid request: {e}"})
            return
        if not data:
            self._json(400, {"ok": False, "error": "empty image"})
            return
        try:
            with scan_lock:
                result = scanner.scan_bytes(data, is_pdf=is_pdf, with_images=bool(with_images))
            result["ok"] = True
            self._json(200, result)
        except RegistrationError as e:
            self._json(422, {"ok": False, "error": "NO_DOCUMENT", "message": str(e)})
        except Exception as e:  # noqa: BLE001
            traceback.print_exc()
            self._json(500, {"ok": False, "error": "SCAN_FAILED", "message": str(e)})


if __name__ == "__main__":
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()
