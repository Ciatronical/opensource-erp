#!/bin/bash
#
# Nerd Dictation - Installationsskript fuer Linux Mint
# ====================================================
# Installiert nerd-dictation mit deutschem VOSK-Sprachmodell.
# Nutzt suspend/resume statt start/stop fuer sofortiges Umschalten.
#
# Verwendung:
#   chmod +x install-nerd-dictation.sh
#   ./install-nerd-dictation.sh
#
# Installiert IMMER nach $HOME, egal von wo ausgefuehrt.

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

INSTALL_DIR="$HOME/nerd-dictation"

echo -e "${BLUE}"
echo "========================================================"
echo "   Nerd Dictation - Installer fuer Linux Mint"
echo "   Offline Sprache-zu-Text (Deutsch)"
echo "========================================================"
echo -e "${NC}"

# -----------------------------------------------------------
# 1. Sprachmodell auswaehlen
# -----------------------------------------------------------
echo -e "${YELLOW}[1/7] Sprachmodell auswaehlen${NC}"
echo ""
echo -e "  ${BOLD}1) vosk-model-small-de-0.15${NC}  (~45 MB, ~300 MB RAM)"
echo "     Leichtgewichtig, fuer aeltere Rechner und Raspberry Pi"
echo "     Erkennung OK, ideal fuer einfache Diktate"
echo ""
echo -e "  ${BOLD}2) vosk-model-de-0.21${NC}          (~1.9 GB, ~4 GB RAM)"
echo "     Grosses Modell, deutlich bessere Erkennung"
echo "     Braucht modernen Rechner mit genuegend RAM"
echo ""
echo -e "  ${BOLD}3) vosk-model-de-tuda-0.6-900k${NC} (~4.4 GB, ~8 GB RAM)"
echo "     Uni Hamburg TUDA-Modell, sehr gute Erkennung"
echo "     Braucht starken Rechner, 16 GB RAM empfohlen"
echo ""
echo -e "  ${BOLD}4) vosk-model-de-0.6${NC}           (~1.2 GB, ~3 GB RAM)"
echo "     Aelteres grosses Modell, gute Erkennung"
echo "     Weniger RAM als TUDA, guter Kompromiss"
echo ""
read -p "  Deine Wahl (1-4) [Standard: 1]: " MODEL_CHOICE
MODEL_CHOICE=${MODEL_CHOICE:-1}

case $MODEL_CHOICE in
    1)
        MODEL_NAME="vosk-model-small-de-0.15"
        MODEL_URL="https://alphacephei.com/vosk/models/vosk-model-small-de-0.15.zip"
        MODEL_ZIP="vosk-model-small-de-0.15.zip"
        ;;
    2)
        MODEL_NAME="vosk-model-de-0.21"
        MODEL_URL="https://alphacephei.com/vosk/models/vosk-model-de-0.21.zip"
        MODEL_ZIP="vosk-model-de-0.21.zip"
        ;;
    3)
        MODEL_NAME="vosk-model-de-tuda-0.6-900k"
        MODEL_URL="https://alphacephei.com/vosk/models/vosk-model-de-tuda-0.6-900k.zip"
        MODEL_ZIP="vosk-model-de-tuda-0.6-900k.zip"
        ;;
    4)
        MODEL_NAME="vosk-model-de-0.6"
        MODEL_URL="https://alphacephei.com/vosk/models/vosk-model-de-0.6.zip"
        MODEL_ZIP="vosk-model-de-0.6.zip"
        ;;
    *)
        echo -e "  ${RED}Ungueltige Auswahl. Abbruch.${NC}"
        exit 1
        ;;
esac

echo -e "  ${GREEN}Gewaehltes Modell: ${MODEL_NAME}${NC}"
echo ""

# -----------------------------------------------------------
# 2. Systemabhaengigkeiten installieren
# -----------------------------------------------------------
echo -e "${YELLOW}[2/7] Installiere Systemabhaengigkeiten...${NC}"

PACKAGES="git python3-venv xdotool pulseaudio-utils sox unzip wget libnotify-bin"
MISSING=""

for pkg in $PACKAGES; do
    if ! dpkg -s "$pkg" &>/dev/null; then
        MISSING="$MISSING $pkg"
    fi
done

if [ -n "$MISSING" ]; then
    echo "  Fehlende Pakete:$MISSING"
    sudo apt update -qq
    sudo apt install -y $MISSING
    if [ $? -ne 0 ]; then
        echo -e "  ${RED}Fehler beim Installieren der Pakete!${NC}"
        exit 1
    fi
    echo -e "  ${GREEN}Pakete installiert${NC}"
else
    echo -e "  ${GREEN}Alle Pakete bereits vorhanden${NC}"
fi

# -----------------------------------------------------------
# 3. Nerd Dictation klonen
# -----------------------------------------------------------
echo -e "${YELLOW}[3/7] Lade nerd-dictation herunter...${NC}"

if [ -d "$INSTALL_DIR" ]; then
    echo -e "  ${YELLOW}Verzeichnis $INSTALL_DIR existiert bereits.${NC}"
    read -p "  Ueberschreiben? (j/n): " OVERWRITE
    if [ "$OVERWRITE" = "j" ] || [ "$OVERWRITE" = "J" ]; then
        rm -rf "$INSTALL_DIR"
    else
        echo -e "  ${RED}Abbruch.${NC}"
        exit 1
    fi
fi

git clone https://github.com/ideasman42/nerd-dictation.git "$INSTALL_DIR"
if [ $? -ne 0 ]; then
    echo -e "  ${RED}Fehler beim Klonen! Ist git installiert und Internet verfuegbar?${NC}"
    exit 1
fi
echo -e "  ${GREEN}nerd-dictation heruntergeladen${NC}"

# -----------------------------------------------------------
# 4. Python venv + vosk
# -----------------------------------------------------------
echo -e "${YELLOW}[4/7] Richte Python-Umgebung ein...${NC}"

cd "$INSTALL_DIR"
python3 -m venv .venv
if [ $? -ne 0 ]; then
    echo -e "  ${RED}Fehler beim Erstellen der Python-Umgebung!${NC}"
    exit 1
fi

source .venv/bin/activate
pip install --upgrade pip -q
pip install vosk -q
if [ $? -ne 0 ]; then
    echo -e "  ${RED}Fehler beim Installieren von VOSK!${NC}"
    deactivate
    exit 1
fi
deactivate
echo -e "  ${GREEN}Python-Umgebung und VOSK installiert${NC}"

# -----------------------------------------------------------
# 5. Sprachmodell herunterladen
# -----------------------------------------------------------
echo -e "${YELLOW}[5/7] Lade Sprachmodell herunter: ${MODEL_NAME}...${NC}"
echo "  (Das kann je nach Modellgroesse einige Minuten dauern)"

cd "$INSTALL_DIR"

if [ -d "model" ]; then
    echo -e "  ${GREEN}Modell bereits vorhanden${NC}"
else
    wget --progress=bar:force "$MODEL_URL" -O "$MODEL_ZIP"
    if [ $? -ne 0 ]; then
        echo -e "  ${RED}Fehler beim Download des Sprachmodells!${NC}"
        echo -e "  ${RED}URL: ${MODEL_URL}${NC}"
        exit 1
    fi

    echo "  Entpacke Modell..."
    unzip -q "$MODEL_ZIP"
    if [ $? -ne 0 ]; then
        echo -e "  ${RED}Fehler beim Entpacken!${NC}"
        exit 1
    fi

    mv "$MODEL_NAME" model
    rm "$MODEL_ZIP"
    echo -e "  ${GREEN}Sprachmodell ${MODEL_NAME} installiert${NC}"
fi

# -----------------------------------------------------------
# 6. Skripte erstellen (suspend/resume fuer sofortiges Umschalten)
# -----------------------------------------------------------
echo -e "${YELLOW}[6/7] Erstelle Skripte in $HOME ...${NC}"

# --- Toggle-Skript (suspend/resume - SOFORT nach erstem Laden) ---
cat > "$HOME/nerd-dictation-toggle.sh" << 'TOGGLEEOF'
#!/bin/bash
# Nerd Dictation Toggle mit suspend/resume
# Erster Aufruf: Startet nerd-dictation (einmalig langsam)
# Danach: Schaltet sofort zwischen aktiv/pausiert um
cd ~/nerd-dictation
ACTIVE_WINDOW=$(xdotool getactivewindow 2>/dev/null)
STATE_FILE="/tmp/nerd-dictation-active"

if pgrep -f "nerd-dictation begin" > /dev/null; then
    # Prozess laeuft - suspend oder resume?
    if [ -f "$STATE_FILE" ]; then
        # Aktiv -> pausieren
        ./nerd-dictation suspend
        rm "$STATE_FILE"
        notify-send -t 1500 "Diktat pausiert" "" 2>/dev/null
    else
        # Pausiert -> fortsetzen
        ./nerd-dictation resume
        touch "$STATE_FILE"
        notify-send -t 1500 "Diktat laeuft" "Sprich jetzt..." 2>/dev/null
    fi
else
    # Erster Start - Modell wird geladen (einmalig langsam)
    notify-send -t 3000 "Diktat wird geladen..." "Bitte kurz warten" 2>/dev/null
    PATH="$HOME/nerd-dictation/.venv/bin:$PATH" \
    ./nerd-dictation begin --vosk-model-dir=./model --continuous --timeout=0 &
    touch "$STATE_FILE"
fi

[ -n "$ACTIVE_WINDOW" ] && xdotool windowactivate "$ACTIVE_WINDOW" 2>/dev/null
TOGGLEEOF

# --- Kill-Skript (Prozess komplett beenden, RAM freigeben) ---
cat > "$HOME/nerd-dictation-kill.sh" << 'KILLEOF'
#!/bin/bash
# Nerd Dictation komplett beenden und RAM freigeben
cd ~/nerd-dictation
./nerd-dictation end 2>/dev/null
rm -f /tmp/nerd-dictation-active
notify-send -t 1500 "Diktat beendet" "RAM freigegeben" 2>/dev/null
KILLEOF

# --- Autostart-Skript (Modell beim Login vorladen) ---
cat > "$HOME/nerd-dictation-autostart.sh" << 'AUTOEOF'
#!/bin/bash
# Nerd Dictation beim Login starten und sofort pausieren
# Dadurch ist das Modell bereits im RAM wenn F7 gedrueckt wird
cd ~/nerd-dictation
if ! pgrep -f "nerd-dictation begin" > /dev/null; then
    PATH="$HOME/nerd-dictation/.venv/bin:$PATH" \
    ./nerd-dictation begin --vosk-model-dir=./model --continuous --timeout=0 &
    sleep 5
    ./nerd-dictation suspend
    rm -f /tmp/nerd-dictation-active
fi
AUTOEOF

chmod +x "$HOME/nerd-dictation-toggle.sh"
chmod +x "$HOME/nerd-dictation-kill.sh"
chmod +x "$HOME/nerd-dictation-autostart.sh"

echo -e "  ${GREEN}Skripte erstellt in $HOME:${NC}"
echo "    ~/nerd-dictation-toggle.sh     (F7: Ein/Aus umschalten)"
echo "    ~/nerd-dictation-kill.sh       (Prozess komplett beenden)"
echo "    ~/nerd-dictation-autostart.sh  (Beim Login vorladen)"

# -----------------------------------------------------------
# 7. Autostart einrichten (optional)
# -----------------------------------------------------------
echo ""
echo -e "${YELLOW}[7/7] Autostart einrichten?${NC}"
echo "  Damit wird das Sprachmodell beim Login geladen."
echo "  Der allererste F7-Druck ist dann sofort schnell."
echo "  (Braucht dauerhaft ~300 MB RAM bei kleinem Modell)"
echo ""
read -p "  Autostart einrichten? (j/n) [Standard: n]: " AUTOSTART
AUTOSTART=${AUTOSTART:-n}

if [ "$AUTOSTART" = "j" ] || [ "$AUTOSTART" = "J" ]; then
    AUTOSTART_DIR="$HOME/.config/autostart"
    mkdir -p "$AUTOSTART_DIR"
    cat > "$AUTOSTART_DIR/nerd-dictation.desktop" << DESKTOPEOF
[Desktop Entry]
Type=Application
Name=Nerd Dictation Preload
Comment=Laedt das Sprachmodell beim Login vor
Exec=$HOME/nerd-dictation-autostart.sh
Hidden=false
NoDisplay=true
X-GNOME-Autostart-enabled=true
DESKTOPEOF
    echo -e "  ${GREEN}Autostart eingerichtet!${NC}"
    echo "  (Kann unter Startprogramme wieder deaktiviert werden)"
else
    echo -e "  ${YELLOW}Kein Autostart. Erster F7-Druck laedt das Modell.${NC}"
fi

# -----------------------------------------------------------
# Schnelltest
# -----------------------------------------------------------
echo ""
echo -e "${YELLOW}Teste Installation...${NC}"

cd "$INSTALL_DIR"
source .venv/bin/activate

ERRORS=0

if python3 -c "import vosk" 2>/dev/null; then
    echo -e "  ${GREEN}[OK] VOSK funktioniert${NC}"
else
    echo -e "  ${RED}[FEHLER] VOSK konnte nicht geladen werden${NC}"
    ERRORS=$((ERRORS+1))
fi

if [ -d "$INSTALL_DIR/model" ]; then
    MODEL_SIZE=$(du -sh "$INSTALL_DIR/model" | cut -f1)
    echo -e "  ${GREEN}[OK] Sprachmodell vorhanden ($MODEL_SIZE)${NC}"
else
    echo -e "  ${RED}[FEHLER] Sprachmodell nicht gefunden${NC}"
    ERRORS=$((ERRORS+1))
fi

command -v xdotool &>/dev/null && echo -e "  ${GREEN}[OK] xdotool verfuegbar${NC}" || { echo -e "  ${RED}[FEHLER] xdotool fehlt${NC}"; ERRORS=$((ERRORS+1)); }
command -v parec &>/dev/null && echo -e "  ${GREEN}[OK] parec (Audio) verfuegbar${NC}" || { echo -e "  ${RED}[FEHLER] parec fehlt${NC}"; ERRORS=$((ERRORS+1)); }

if [ -f "$HOME/nerd-dictation-toggle.sh" ]; then
    echo -e "  ${GREEN}[OK] Toggle-Skript in ~ vorhanden${NC}"
else
    echo -e "  ${RED}[FEHLER] Toggle-Skript fehlt in ~${NC}"
    ERRORS=$((ERRORS+1))
fi

deactivate

# -----------------------------------------------------------
# Ergebnis
# -----------------------------------------------------------
echo ""
if [ $ERRORS -eq 0 ]; then
    echo -e "${GREEN}========================================================"
    echo "   Installation erfolgreich abgeschlossen!"
    echo "   Modell: ${MODEL_NAME}"
    echo "========================================================${NC}"
else
    echo -e "${RED}========================================================"
    echo "   Installation mit $ERRORS Fehler(n) abgeschlossen!"
    echo "   Bitte die obigen Fehlermeldungen pruefen."
    echo "========================================================${NC}"
fi

echo ""
echo -e "${BLUE}TASTENKUERZEL EINRICHTEN:${NC}"
echo ""
echo "  1. Systemeinstellungen > Tastatur > Tastenkombinationen"
echo "  2. Eigene Tastenkombinationen > Hinzufuegen"
echo ""
echo "     Name:   Diktat umschalten"
echo "     Befehl: $HOME/nerd-dictation-toggle.sh"
echo "     Taste:  F7"
echo ""
echo "  Optional (Prozess komplett beenden):"
echo "     Name:   Diktat beenden"
echo "     Befehl: $HOME/nerd-dictation-kill.sh"
echo "     Taste:  z.B. Shift+F7"
echo ""
echo -e "${BLUE}SO FUNKTIONIERT ES:${NC}"
echo "  1. VS Code oeffnen, ins Claude-Chatfeld klicken"
echo "  2. F7 kurz druecken -> erster Start laedt Modell (einmalig)"
echo "  3. Sprechen - Text erscheint im Chatfeld"
echo "  4. F7 nochmal -> Diktat pausiert (Modell bleibt im RAM)"
echo "  5. F7 wieder -> Diktat sofort wieder aktiv"
echo "  6. Enter -> Prompt an Claude senden"
echo ""
echo -e "${YELLOW}Der erste F7-Druck ist einmalig langsam (Modell laden)."
echo "Danach ist jedes Umschalten sofort, weil das Modell"
echo "im RAM bleibt. Mit Autostart entfaellt auch das.${NC}"
echo ""