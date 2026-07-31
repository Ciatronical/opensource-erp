#!/usr/bin/env python3
"""
Whisper-Transkriptionsdienst (lokal, CPU)
=========================================

Dauerhaft laufender HTTP-Dienst, der das Whisper-Modell EINMAL in den RAM laedt
und Audiodateien per POST entgegennimmt. So entfaellt das teure Neuladen des
Modells pro Anfrage (sonst ~10-20 s Overhead).

Anwendungsfall: Telegram-Bot laedt eine Sprachnachricht (OGG/Opus) herunter,
das PHP-Backend schickt die Bytes hierher und bekommt den fertigen Text zurueck.

Aufruf:
    POST http://127.0.0.1:3002/transcribe
    Body: rohe Audio-Bytes (OGG/Opus, MP3, WAV, M4A ... ffmpeg dekodiert alles)
    Header (optional): X-Whisper-Token:  <geheim>   (wenn WHISPER_TOKEN gesetzt)
    Header (optional): X-Whisper-Lang:   de | auto   (ueberschreibt WHISPER_LANG)
    Header (optional): X-Whisper-Prompt: <base64(utf-8)>  Fachbegriffe-Glossar als
                       initial_prompt. Verbessert die Erkennung von Domaenen-
                       Vokabular (Kfz-Teile, Namen). Base64-kodiert, damit Umlaute/
                       Kommas den HTTP-Header nicht sprengen. Whisper nutzt nur die
                       letzten ~224 Tokens des Prompts -> Glossar kurz halten.

Antwort (JSON):
    {"ok": true, "text": "...", "language": "de", "duration": 4.2}

Healthcheck:
    GET http://127.0.0.1:3002/health  ->  {"ok": true, "model": "...", "ready": true}

Konfiguration ueber Umgebungsvariablen:
    WHISPER_PORT     Port (Standard 3002)
    WHISPER_MODEL    Modellgroesse (Standard "large-v3-turbo"; Alternativen:
                     "medium", "small", "large-v3" ...)
    WHISPER_DEVICE   "cpu" (Standard) oder "cuda" (falls NVIDIA-GPU vorhanden)
    WHISPER_COMPUTE  "int8" (Standard, CPU-schnell) / "float16" (GPU) ...
    WHISPER_LANG     "de" (Standard) oder "auto" fuer Spracherkennung
    WHISPER_TOKEN    optionales Shared-Secret; wenn gesetzt, muss der Header
                     X-Whisper-Token passen, sonst 401
"""

import base64
import json
import os
import sys
import tempfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from faster_whisper import WhisperModel

PORT = int(os.environ.get("WHISPER_PORT", "3002"))
MODEL_NAME = os.environ.get("WHISPER_MODEL", "large-v3-turbo")
DEVICE = os.environ.get("WHISPER_DEVICE", "cpu")
COMPUTE = os.environ.get("WHISPER_COMPUTE", "int8")
DEFAULT_LANG = os.environ.get("WHISPER_LANG", "de")
TOKEN = os.environ.get("WHISPER_TOKEN", "")

# Hoechstgroesse einer Audionachricht (Schutz vor Speicher-DoS): 25 MB.
# Eine Telegram-Sprachnachricht ist typ. < 1 MB, das reicht ueppig.
MAX_BYTES = 25 * 1024 * 1024

print(f"[whisper] Lade Modell '{MODEL_NAME}' ({DEVICE}/{COMPUTE}) ...", flush=True)
model = WhisperModel(MODEL_NAME, device=DEVICE, compute_type=COMPUTE)
print(f"[whisper] Modell geladen, lausche auf 127.0.0.1:{PORT}", flush=True)


class Handler(BaseHTTPRequestHandler):
    # Stiller Logger -> systemd-journal nicht zumuellen.
    def log_message(self, fmt, *args):
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
            self._json(200, {"ok": True, "model": MODEL_NAME, "ready": True})
        else:
            self._json(404, {"ok": False, "error": "NOT_FOUND"})

    def do_POST(self):
        if self.path != "/transcribe":
            self._json(404, {"ok": False, "error": "NOT_FOUND"})
            return

        if TOKEN and self.headers.get("X-Whisper-Token", "") != TOKEN:
            self._json(401, {"ok": False, "error": "UNAUTHORIZED"})
            return

        length = int(self.headers.get("Content-Length", "0") or "0")
        if length <= 0:
            self._json(400, {"ok": False, "error": "EMPTY_BODY"})
            return
        if length > MAX_BYTES:
            self._json(413, {"ok": False, "error": "TOO_LARGE"})
            return

        audio = self.rfile.read(length)
        lang = self.headers.get("X-Whisper-Lang", DEFAULT_LANG)
        lang = None if lang == "auto" else lang

        # Fachbegriffe-Glossar (initial_prompt) — base64-kodiert im Header, damit
        # Umlaute/Kommas den HTTP-Header nicht sprengen. Leerer/ungueltiger Header
        # -> kein Prompt (unveraendertes Verhalten wie bisher).
        prompt = None
        raw_prompt = self.headers.get("X-Whisper-Prompt", "")
        if raw_prompt:
            try:
                prompt = base64.b64decode(raw_prompt).decode("utf-8").strip() or None
            except Exception:  # noqa: BLE001 - kaputter Header darf nicht crashen
                prompt = None

        tmp_path = None
        try:
            with tempfile.NamedTemporaryFile(suffix=".audio", delete=False) as tmp:
                tmp.write(audio)
                tmp_path = tmp.name

            segments, info = model.transcribe(
                tmp_path,
                language=lang,
                initial_prompt=prompt,  # Domaenen-Vokabular vorgeben
                vad_filter=True,  # Stille/Pausen herausfiltern -> sauberer Text
            )
            text = "".join(seg.text for seg in segments).strip()
            self._json(200, {
                "ok": True,
                "text": text,
                "language": info.language,
                "duration": round(info.duration, 2),
            })
        except Exception as exc:  # noqa: BLE001 - Fehler als JSON zurueckmelden
            self._json(500, {"ok": False, "error": "TRANSCRIBE_FAILED",
                             "detail": str(exc)})
        finally:
            if tmp_path and os.path.exists(tmp_path):
                os.unlink(tmp_path)


def main():
    server = ThreadingHTTPServer(("127.0.0.1", PORT), Handler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n[whisper] beendet", flush=True)
        server.shutdown()


if __name__ == "__main__":
    sys.exit(main())
