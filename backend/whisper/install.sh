#!/bin/bash
#
# Whisper-Transkriptionsdienst - Installer (lokal, CPU)
# =====================================================
# Legt ein Python-venv an und installiert faster-whisper.
# Das Sprachmodell selbst wird beim ERSTEN Start automatisch heruntergeladen
# (nach ~/.cache/huggingface) - hier laden wir es optional vor.
#
# Verwendung:
#   cd backend/whisper && ./install.sh
#
set -euo pipefail

cd "$(dirname "$0")"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'

echo -e "${BLUE}== Whisper-Dienst installieren ==${NC}"

# 1. Systemvoraussetzungen pruefen
command -v python3 >/dev/null || { echo "python3 fehlt"; exit 1; }
command -v ffmpeg  >/dev/null || { echo -e "${YELLOW}ffmpeg fehlt -> 'sudo apt install ffmpeg'${NC}"; exit 1; }

# 2. venv anlegen
if [ ! -d ".venv" ]; then
    echo -e "${YELLOW}[1/3] Lege Python-venv an (.venv) ...${NC}"
    python3 -m venv .venv
fi

# 3. Abhaengigkeiten installieren
echo -e "${YELLOW}[2/3] Installiere faster-whisper ...${NC}"
./.venv/bin/pip install --upgrade pip >/dev/null
./.venv/bin/pip install -r requirements.txt

# 4. Modell vorladen (optional, sonst beim ersten Start)
MODEL="${WHISPER_MODEL:-large-v3-turbo}"
echo -e "${YELLOW}[3/3] Lade Modell '${MODEL}' vor (einmalig, kann dauern) ...${NC}"
./.venv/bin/python -c "from faster_whisper import WhisperModel; WhisperModel('${MODEL}', device='cpu', compute_type='int8'); print('Modell bereit')"

echo -e "${GREEN}Fertig.${NC}"
echo "Start (Vordergrund-Test):  ./.venv/bin/python whisper-server.py"
echo "Als Dienst:                siehe install/oserp-whisper.service und README.md"
