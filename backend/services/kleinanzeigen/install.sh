#!/bin/bash
#
# Kleinanzeigen-Dienst — Installer
# ================================
# Legt ein Python-venv an und installiert nodriver (Chrome-Steuerung) und
# aiohttp (HTTP-Dienst). Chrome selbst muss installiert sein (google-chrome
# oder chromium).
#
# Verwendung:
#   cd backend/services/kleinanzeigen && ./install.sh
#
set -euo pipefail
cd "$(dirname "$0")"

GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
echo -e "${BLUE}== Kleinanzeigen-Dienst installieren ==${NC}"

command -v python3 >/dev/null || { echo "python3 fehlt (sudo apt install python3 python3-venv)"; exit 1; }
if ! command -v google-chrome >/dev/null && ! command -v chromium >/dev/null && ! command -v chromium-browser >/dev/null; then
    echo -e "${YELLOW}Kein Chrome gefunden. Google Chrome installieren oder KA_CHROME auf den Pfad setzen.${NC}"
fi

if [ ! -d ".venv" ]; then
    echo -e "${YELLOW}[1/2] Lege Python-venv an (.venv) ...${NC}"
    python3 -m venv .venv
fi
echo -e "${YELLOW}[2/2] Installiere nodriver und aiohttp ...${NC}"
./.venv/bin/pip install --upgrade pip >/dev/null
./.venv/bin/pip install -r requirements.txt

if [ -z "${KA_TOKEN:-}" ]; then
    echo -e "${YELLOW}Token-Vorschlag für KA_TOKEN (auch in der Firmenkonfiguration → Shop eintragen):${NC}"
    python3 -c "import secrets; print(secrets.token_hex(24))"
fi

echo -e "${GREEN}Fertig.${NC}"
echo "Start (Vordergrund-Test):  KA_TOKEN=<token> ./.venv/bin/python kleinanzeigen-worker.py"
echo "Als Dienst:                siehe install/oserp-kleinanzeigen.service und README.md"
