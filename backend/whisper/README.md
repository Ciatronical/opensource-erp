# Whisper-Transkriptionsdienst (lokal, CPU)

Wandelt Audionachrichten (z.B. Telegram-Sprachnotizen) server-seitig in Text um —
**kostenlos und offline**, ohne Cloud-API. Genutzt für die Sprachnotiz →
Anschlagtafel-Funktion (siehe `dev/konzept-sprachnotiz-anschlagtafel.md`).

Technik: [`faster-whisper`](https://github.com/SYSTRAN/faster-whisper)
(CTranslate2, **kein PyTorch nötig** → schlank und CPU-schnell). Das Modell wird
**einmal** in den RAM geladen und bleibt als dauerhafter Dienst aktiv, damit pro
Anfrage nur die paar Sekunden Transkription anfallen (kein Modell-Neuladen).

---

## Hardware

Läuft auf normaler CPU, **keine GPU nötig**. Pro Sprachnotiz nur wenige Sekunden
Audio → unkritisch.

| | Minimum | Empfohlen |
|---|---|---|
| CPU | 4 Kerne | 6–8 Kerne |
| RAM | 8 GB | 16 GB |
| Disk | ~5 GB (Modell-Cache) | SSD |

Modell `large-v3-turbo` (int8) braucht ~2 GB RAM und liefert sehr gute deutsche
Erkennung inkl. Fachbegriffe. Bei schwacher Maschine `medium`/`small` setzen
(siehe `WHISPER_MODEL`).

---

## Installation

### 1. Systemvoraussetzungen

```bash
sudo apt install -y python3 python3-venv python3-full ffmpeg
```

`ffmpeg` ist Pflicht — es dekodiert das OGG/Opus der Telegram-Sprachnachrichten
(und MP3/M4A/WAV) für Whisper.

### 2. Dienst installieren

```bash
cd backend/whisper
./install.sh
```

Das Skript

1. legt ein Python-venv unter `backend/whisper/.venv` an,
2. installiert `faster-whisper` (siehe `requirements.txt`),
3. lädt das Modell `large-v3-turbo` einmalig vor (Download nach
   `~/.cache/huggingface`, ~1,6 GB).

Anderes Modell vorladen:

```bash
WHISPER_MODEL=medium ./install.sh
```

### 3. Test im Vordergrund

```bash
cd backend/whisper
./.venv/bin/python whisper-server.py
# -> "[whisper] Modell geladen, lausche auf 127.0.0.1:3002"
```

In zweitem Terminal prüfen:

```bash
# Healthcheck
curl http://127.0.0.1:3002/health
# -> {"ok": true, "model": "large-v3-turbo", "ready": true}

# Transkription einer Audiodatei
curl -s --data-binary @notiz.ogg http://127.0.0.1:3002/transcribe
# -> {"ok": true, "text": "...", "language": "de", "duration": 4.2}
```

### 4. Als Dauerdienst (systemd User-Service)

Gleiches Muster wie der SSE-Server (`backend/sse`): **User-Scope**, nicht
System-Scope.

```bash
mkdir -p ~/.config/systemd/user
cp install/oserp-whisper.service ~/.config/systemd/user/
# Pfade in der Unit ggf. anpassen (ExecStart -> .venv/bin/python)

export XDG_RUNTIME_DIR=/run/user/$(id -u)
systemctl --user daemon-reload
systemctl --user enable --now oserp-whisper
loginctl enable-linger "$USER"     # überlebt Logout/Boot

# Status / Logs
systemctl --user status oserp-whisper
journalctl --user -u oserp-whisper -f
```

> **Hinweis:** Beim allerersten Start lädt der Dienst das Modell herunter — das
> kann einige Minuten dauern (`TimeoutStartSec=600` ist dafür gesetzt). Im
> `journalctl` erscheint `Modell geladen`, sobald er bereit ist.

---

## HTTP-Schnittstelle

| | |
|---|---|
| `GET /health` | `{"ok": true, "model": "...", "ready": true}` |
| `POST /transcribe` | Body = rohe Audio-Bytes → `{"ok": true, "text": "...", "language": "de", "duration": 4.2}` |

Optionale Header bei `POST /transcribe`:

- `X-Whisper-Token: <geheim>` — nötig, wenn `WHISPER_TOKEN` gesetzt ist (sonst 401).
- `X-Whisper-Lang: de | auto` — überschreibt die Standardsprache pro Anfrage.

Bindet **nur an `127.0.0.1`** — nicht von außen erreichbar. Der Zugriff läuft
ausschließlich über das PHP-Backend auf demselben Host.

---

## Konfiguration (Umgebungsvariablen)

| Variable | Standard | Bedeutung |
|---|---|---|
| `WHISPER_PORT` | `3002` | Lausch-Port (3001 ist der SSE-Server) |
| `WHISPER_MODEL` | `large-v3-turbo` | Modellgröße: `tiny`/`small`/`medium`/`large-v3`/`large-v3-turbo` |
| `WHISPER_DEVICE` | `cpu` | `cuda` bei vorhandener NVIDIA-GPU |
| `WHISPER_COMPUTE` | `int8` | CPU: `int8`; GPU: `float16` |
| `WHISPER_LANG` | `de` | `auto` für automatische Spracherkennung |
| `WHISPER_TOKEN` | _(leer)_ | optionales Shared-Secret für `/transcribe` |

---

## Einordnung im Gesamtfluss

```
Telegram-Bot (Sprachnachricht, OGG/Opus)
  → backend/api/  (lädt Voice-Datei herunter)
    → POST 127.0.0.1:3002/transcribe   ◀── DIESER DIENST
      → Text in DB speichern
        → SSE-Event (Port 3001)
          → Anschlagtafel-Vue-View zeigt Text live
```

Dieser Baustein deckt **nur die Transkription** ab. Telegram-Anbindung,
DB-Speicherung, SSE-Event und Anschlagtafel-View sind eigene Bausteine
(siehe Konzept).
