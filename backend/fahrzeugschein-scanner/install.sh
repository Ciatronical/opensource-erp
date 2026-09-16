#!/bin/bash
#
# Fahrzeugscheinscanner - Installer (lokal, CPU)
# ==============================================
# Legt ein Python-venv an, installiert RapidOCR/OpenCV und laedt das
# Latin-Erkennungsmodell (PP-OCRv5, ~8 MB) vor. Referenzvorlage und Layout liegen
# fertig im Repository (reference/), es ist kein Training noetig.
#
# Verwendung:
#   cd backend/fahrzeugschein-scanner && ./install.sh
#
set -euo pipefail

cd "$(dirname "$0")"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'

echo -e "${BLUE}== Fahrzeugscheinscanner installieren ==${NC}"

# 1. Systemvoraussetzungen pruefen
command -v python3  >/dev/null || { echo "python3 fehlt"; exit 1; }
command -v pdftoppm >/dev/null || { echo -e "${YELLOW}pdftoppm fehlt -> 'sudo apt install poppler-utils' (nur fuer PDF-Uploads noetig)${NC}"; }
[ -f reference/zb1_reference.jpg ] || { echo "reference/zb1_reference.jpg fehlt (Repository unvollstaendig)"; exit 1; }
[ -f reference/layout.json ]       || { echo "reference/layout.json fehlt (Repository unvollstaendig)"; exit 1; }

# 2. venv anlegen
if [ ! -d ".venv" ]; then
    echo -e "${YELLOW}[1/3] Lege Python-venv an (.venv) ...${NC}"
    python3 -m venv .venv
fi

# 3. Abhaengigkeiten installieren
echo -e "${YELLOW}[2/3] Installiere RapidOCR, OpenCV, ONNX Runtime ...${NC}"
./.venv/bin/pip install --upgrade pip >/dev/null
./.venv/bin/pip install -r requirements.txt

# 4. OCR-Modell vorladen und Pipeline einmal initialisieren
echo -e "${YELLOW}[3/3] Lade OCR-Modell vor und pruefe die Pipeline ...${NC}"
./.venv/bin/python -c "
import logging; logging.disable(logging.CRITICAL)
from fsscanner.pipeline import Scanner
s = Scanner(); print('Pipeline bereit,', len(s.layout.fields), 'Felder')"

echo -e "${GREEN}Fertig.${NC}"
echo "Start (Vordergrund-Test):  ./.venv/bin/python fs-scanner-server.py"
echo "Als Dienst:                siehe install/oserp-fahrzeugschein-scanner.service und README.md"
echo "Aktivieren in OSERP:       Firmenkonfiguration -> LxCars -> 'Eigenen Fahrzeugscheinscanner benutzen'"
