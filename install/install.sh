#!/usr/bin/env bash
# =============================================================================
#  OpensourceERP — Vollständiger Installer für eine frische Maschine
# =============================================================================
#  Richtet den kompletten Stack idempotent ein:
#    Systempakete · PHP-FPM · Node-Build · PostgreSQL · Apache · SSE · Whisper
#    · Ollama (lokaler LLM) · ANPR · Kameras (go2rtc + Monitor) · Cronjobs
#    · Asterisk-Telefonie (Gerüst) · Borg-Backup (Gerüst) · kivitendo
#
#  GRUNDSÄTZE (siehe CLAUDE.md / feedback):
#    - KEINE hardcodierten Versionen. PHP-Version/-Socket werden zur Laufzeit
#      erkannt, Pfad/User aus der Umgebung abgeleitet.
#    - Idempotent: mehrfach ausführbar, prüft vor jedem Schritt.
#    - Modular: einzelne Schritte per --only / --skip steuerbar.
#
#  Aufruf:
#    ./install/install.sh                 # alle Schritte
#    ./install/install.sh --list          # Schritte auflisten
#    ./install/install.sh --only apache,sse
#    ./install/install.sh --skip cameras,anpr,asterisk,borg
#    OSERP_USER=work OLLAMA_MODEL=qwen2.5:7b ./install/install.sh
#
#  Das Script wird als der Benutzer ausgeführt, der OSERP betreibt (NICHT root)
#  und nutzt für Systemteile `sudo`. In der Test-VM siehe install/test-vm.sh.
# =============================================================================
set -Eeuo pipefail

# --------------------------------------------------------------------------
#  Pfade / Basisvariablen
# --------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OSERP_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Betriebs-User: Eigentümer des Repos, sonst der aufrufende User
OSERP_USER="${OSERP_USER:-$(stat -c '%U' "$OSERP_ROOT")}"
OSERP_HOME="$(getent passwd "$OSERP_USER" | cut -d: -f6)"
OSERP_HOME="${OSERP_HOME:-/home/$OSERP_USER}"

# Node-Major (nodesource). Vite 7 braucht >= 20.19; Projekt läuft auf 25.
NODE_MAJOR="${NODE_MAJOR:-25}"
# Modell, das Ollama nach der Installation vorlädt (überschreibbar).
OLLAMA_MODEL="${OLLAMA_MODEL:-qwen2.5:7b}"
# Externer kivitendo-Installer.
KIVI_INSTALLER_REPO="${KIVI_INSTALLER_REPO:-https://github.com/Ciatronical/install-kivitendo}"

# --------------------------------------------------------------------------
#  Ausgabe
# --------------------------------------------------------------------------
if [[ -t 1 ]]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'
    BLUE=$'\033[0;34m'; BOLD=$'\033[1m'; NC=$'\033[0m'
else
    RED=; GREEN=; YELLOW=; BLUE=; BOLD=; NC=
fi
info()    { echo "${BLUE}[INFO]${NC}  $*"; }
ok()      { echo "${GREEN}[OK]${NC}    $*"; }
warn()    { echo "${YELLOW}[WARN]${NC}  $*"; }
err()     { echo "${RED}[FEHLER]${NC} $*" >&2; }
step()    { echo; echo "${BOLD}=== $* ===${NC}"; }
todo()    { echo "${YELLOW}[TODO]${NC}  $*"; }

trap 'err "Abbruch in Zeile $LINENO (letztes Kommando: $BASH_COMMAND)"' ERR

as_user() { sudo -u "$OSERP_USER" -H bash -lc "$*"; }

# --------------------------------------------------------------------------
#  Schrittsteuerung
# --------------------------------------------------------------------------
ALL_STEPS=(packages php_fpm node_build config permissions apache \
           sse whisper ollama anpr go2rtc camera_monitor camera_sudoers \
           cron asterisk borg kivitendo healthcheck)

declare -A STEP_DESC=(
  [packages]="Systempakete via apt (Apache, PostgreSQL, PHP, Node, LaTeX, ffmpeg, borg, asterisk ...)"
  [php_fpm]="PHP-FPM-Socket erkennen, FPM aktivieren, Apache-Defines schreiben"
  [node_build]="npm install + Vue-Build + SSE-Deps + Composer"
  [config]="backend/config/settings.ini anlegen (falls fehlt)"
  [permissions]="Verzeichnisrechte für Webserver setzen"
  [apache]="Apache-Module, vHost, Reload"
  [sse]="SSE-Echtzeitserver als systemd-Dienst"
  [whisper]="Whisper-Transkription (venv) als systemd-Dienst"
  [ollama]="Lokaler LLM (Ollama) + Modell vorladen"
  [anpr]="Kennzeichenerkennung (Python-venv) als systemd-Dienst"
  [go2rtc]="go2rtc Stream-Server als systemd-Dienst"
  [camera_monitor]="Kamera-Objekterkennung als systemd-Dienst"
  [camera_sudoers]="sudoers-Regel damit die UI die Kameradienste steuern darf"
  [cron]="Cronjobs (WhatsApp-Reminder, eBay, Nachbuchen, asanetwork ...)"
  [asterisk]="Asterisk/CRMTI-Telefonie (Paket + Konfig-Gerüst)"
  [borg]="Borg-Backup (Paket + Gerüst, Repo-Secrets manuell)"
  [kivitendo]="kivitendo via externem Installer"
  [healthcheck]="Abschluss-Checks aller Dienste"
)

SELECTED=("${ALL_STEPS[@]}")

usage() {
    echo "OpensourceERP Installer"
    echo "  --list            Schritte auflisten und beenden"
    echo "  --only a,b,c      nur diese Schritte"
    echo "  --skip a,b,c      diese Schritte auslassen"
    echo "  -h|--help         diese Hilfe"
}
list_steps() {
    echo "${BOLD}Verfügbare Schritte:${NC}"
    for s in "${ALL_STEPS[@]}"; do printf "  %-16s %s\n" "$s" "${STEP_DESC[$s]:-}"; done
}
contains() { local n="$1"; shift; for x in "$@"; do [[ "$x" == "$n" ]] && return 0; done; return 1; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --list) list_steps; exit 0 ;;
        --only) IFS=',' read -r -a SELECTED <<< "$2"; shift 2 ;;
        --skip) IFS=',' read -r -a _sk <<< "$2"; shift 2
                _new=(); for s in "${ALL_STEPS[@]}"; do contains "$s" "${_sk[@]}" || _new+=("$s"); done
                SELECTED=("${_new[@]}") ;;
        -h|--help) usage; exit 0 ;;
        *) err "Unbekannte Option: $1"; usage; exit 1 ;;
    esac
done

want() { contains "$1" "${SELECTED[@]}"; }

# --------------------------------------------------------------------------
#  Preflight
# --------------------------------------------------------------------------
step "Preflight"
if [[ "$(id -u)" -eq 0 ]]; then
    err "Bitte NICHT als root ausführen. Als Betriebs-User starten (Script nutzt sudo)."
    exit 1
fi
if ! sudo -v; then err "sudo wird benötigt."; exit 1; fi
if [[ ! -f /etc/os-release ]]; then err "Unbekanntes OS (kein /etc/os-release)."; exit 1; fi
. /etc/os-release
info "OS:          ${PRETTY_NAME:-$ID}"
info "OSERP_ROOT:  $OSERP_ROOT"
info "OSERP_USER:  $OSERP_USER  (HOME=$OSERP_HOME)"
info "Schritte:    ${SELECTED[*]}"

# =============================================================================
#  SCHRITT: packages
# =============================================================================
install_packages() {
    step "Systempakete"
    sudo apt-get update -y

    # Node.js via NodeSource (versionsunabhängig über NODE_MAJOR)
    if ! command -v node >/dev/null || [[ "$(node -v | sed 's/v\([0-9]*\).*/\1/')" -lt 20 ]]; then
        info "Node ${NODE_MAJOR}.x via NodeSource einrichten"
        curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | sudo -E bash -
    fi

    # PHP GENERISCH (kein php8.x!) — Meta-Pakete zeigen auf Distro-Default
    local pkgs=(
        apache2
        postgresql postgresql-contrib
        php php-fpm php-cli php-pgsql php-mbstring php-xml php-curl php-intl
        php-zip php-gd php-bcmath
        composer
        nodejs
        git curl unzip
        python3 python3-venv python3-full
        ffmpeg
        # Druck (siehe reference_print_latex_dependency): LaTeX inkl. deutsch + CUPS
        texlive texlive-latex-extra texlive-lang-german cups
        # Backup / Telefonie
        borgbackup
        # kivitendo-Basis (der externe Installer ergänzt den Rest)
        perl
    )
    info "apt install: ${pkgs[*]}"
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y "${pkgs[@]}"
    ok "Systempakete installiert"
}

# =============================================================================
#  SCHRITT: php_fpm  — Socket erkennen, Defines für Apache schreiben
# =============================================================================
detect_php_fpm_socket() {
    # Bevorzugt einen real existierenden Socket, sonst aus der PHP-Version ableiten
    local sock
    sock="$(ls -1 /run/php/php*-fpm.sock 2>/dev/null | head -n1 || true)"
    if [[ -z "$sock" ]]; then
        local ver; ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
        sock="/run/php/php${ver}-fpm.sock"
    fi
    echo "$sock"
}
setup_php_fpm() {
    step "PHP-FPM"
    local ver sock svc
    ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    svc="php${ver}-fpm"
    sock="$(detect_php_fpm_socket)"
    info "PHP $ver — FPM-Dienst $svc — Socket $sock"
    sudo systemctl enable --now "$svc"

    # Apache-Defines (versions-/pfadunabhängig) — einzige Quelle des konkreten Sockets
    local defs=/etc/apache2/conf-available/oserp-defines.conf
    sudo tee "$defs" >/dev/null <<EOF
# Automatisch erzeugt von install/install.sh — NICHT von Hand editieren.
# Wird vom vHost install/apacheOpensourceErp.conf referenziert.
Define OSERP_ROOT ${OSERP_ROOT}
Define OSERP_PHP_FPM ${sock}
EOF
    ok "Apache-Defines geschrieben: $defs"
}

# =============================================================================
#  SCHRITT: node_build  — Frontend bauen (nutzt scripts/built.sh-Logik)
# =============================================================================
node_build() {
    step "Build (npm / vite / sse / composer)"
    as_user "cd '$OSERP_ROOT' && npm install"
    as_user "cd '$OSERP_ROOT' && npm run build"
    as_user "cd '$OSERP_ROOT/backend/sse' && npm install --omit=dev"
    if [[ -f "$OSERP_ROOT/backend/composer.json" ]]; then
        as_user "cd '$OSERP_ROOT/backend' && composer install --no-interaction --no-dev --optimize-autoloader"
    fi
    ok "Build fertig ($(cat "$OSERP_ROOT/dist/build-id.txt" 2>/dev/null || echo 'build-id unbekannt'))"
}

# =============================================================================
#  SCHRITT: config
# =============================================================================
setup_config() {
    step "Konfiguration (settings.ini)"
    local cfg="$OSERP_ROOT/backend/config/settings.ini"
    if [[ -f "$cfg" ]]; then
        ok "settings.ini existiert bereits — unverändert"
        return
    fi
    warn "settings.ini fehlt — lege Vorlage an. DB-Zugangsdaten anschließend eintragen!"
    as_user "cat > '$cfg'" <<EOF
; OpensourceERP Konfiguration — von install.sh angelegt
; Passwort verschlüsseln: php tools/encrypt_password.php
[database]
host = "localhost"
port = "5432"
auth_db = "kivitendo_auth"
auth_user = "postgres"
auth_pass = "BITTE_EINTRAGEN"

[session]
cookie_name = "opensource_erp"
cookie_same_site = "Strict"

[logging]
max_log_size = 10485760

[system]
timezone = "Europe/Berlin"
debug = false
EOF
    todo "backend/config/settings.ini: DB-Passwort eintragen (verschlüsselt via tools/encrypt_password.php)"
}

# =============================================================================
#  SCHRITT: permissions
# =============================================================================
setup_permissions() {
    step "Berechtigungen"
    # Webserver (www-data) muss in Datenverzeichnisse schreiben; Repo gehört OSERP_USER.
    # www-data zur Gruppe des Users hinzufügen und Gruppenrechte geben.
    sudo usermod -aG "$OSERP_USER" www-data || true
    for d in backend/log backend/tmp backend/data backend/backups dist log; do
        as_user "mkdir -p '$OSERP_ROOT/$d'"
    done
    sudo chgrp -R "$OSERP_USER" "$OSERP_ROOT/backend/data" "$OSERP_ROOT/backend/log" \
        "$OSERP_ROOT/backend/tmp" 2>/dev/null || true
    sudo chmod -R g+rwX "$OSERP_ROOT/backend/data" "$OSERP_ROOT/backend/log" \
        "$OSERP_ROOT/backend/tmp" 2>/dev/null || true
    ok "Rechte gesetzt (www-data in Gruppe $OSERP_USER; siehe project_mmelissa_perms_divergence)"
}

# =============================================================================
#  SCHRITT: apache
# =============================================================================
setup_apache() {
    step "Apache"
    sudo a2enmod rewrite proxy proxy_fcgi proxy_http setenvif >/dev/null
    sudo a2enconf oserp-defines >/dev/null 2>&1 || {
        warn "oserp-defines noch nicht vorhanden — führe zuerst Schritt php_fpm aus"; }
    sudo cp "$OSERP_ROOT/install/apacheOpensourceErp.conf" \
        /etc/apache2/sites-available/apacheOpensourceErp.conf
    sudo a2ensite apacheOpensourceErp >/dev/null
    sudo a2dissite 000-default >/dev/null 2>&1 || true
    if sudo apache2ctl configtest; then
        sudo systemctl restart apache2
        ok "Apache läuft — http://localhost"
    else
        err "apache2ctl configtest fehlgeschlagen — vHost/Defines prüfen"
        return 1
    fi
}

# --------------------------------------------------------------------------
#  Hilfsfunktion: systemd-Unit aus committed Vorlage installieren
#  Ersetzt hardcodierte Pfade/User durch die echte Installation.
# --------------------------------------------------------------------------
install_unit() {
    local src="$1" name="$2"
    [[ -f "$src" ]] || { warn "Unit-Vorlage fehlt: $src"; return 1; }
    sudo sed \
        -e "s#/home/work/opensource-erp#${OSERP_ROOT}#g" \
        -e "s#^User=work#User=${OSERP_USER}#g" \
        -e "s#HOME=/home/work#HOME=${OSERP_HOME}#g" \
        "$src" | sudo tee "/etc/systemd/system/$name" >/dev/null
    sudo systemctl daemon-reload
}

# =============================================================================
#  SCHRITT: sse
# =============================================================================
setup_sse() {
    step "SSE-Server"
    install_unit "$OSERP_ROOT/install/oserp-sse.service" "oserp-sse.service"
    sudo systemctl enable --now oserp-sse.service
    sleep 2
    if curl -m3 -so /dev/null -w '%{http_code}' http://127.0.0.1:3001/events | grep -qE '401|200'; then
        ok "SSE aktiv (401/200 = gesund, siehe reference_sse_server)"
    else
        warn "SSE antwortet nicht wie erwartet — journalctl -u oserp-sse -e"
    fi
}

# =============================================================================
#  SCHRITT: whisper
# =============================================================================
setup_whisper() {
    step "Whisper-Transkription"
    as_user "cd '$OSERP_ROOT/backend/whisper' && ./install.sh"
    install_unit "$OSERP_ROOT/install/oserp-whisper.service" "oserp-whisper.service"
    sudo systemctl enable --now oserp-whisper.service
    ok "Whisper-Dienst eingerichtet (Port 3002, Modell lädt beim 1. Start)"
}

# =============================================================================
#  SCHRITT: ollama  — stock ollama (offizieller Installer), Modell vorladen
# =============================================================================
setup_ollama() {
    step "Ollama (lokaler LLM)"
    if ! command -v ollama >/dev/null; then
        info "Offiziellen Ollama-Installer ausführen"
        curl -fsSL https://ollama.com/install.sh | sh
    fi
    sudo systemctl enable --now ollama 2>/dev/null || true
    info "Modell '$OLLAMA_MODEL' vorladen (kann dauern)"
    ollama pull "$OLLAMA_MODEL" || warn "ollama pull fehlgeschlagen — später manuell"
    ok "Ollama auf 127.0.0.1:11434 (Endpunkt in der ERP-UI konfigurierbar)"
    todo "iGPU-Beschleunigung (Vulkan) ist maschinenspezifisch — siehe dev/lokale-ki-ollama.md"
}

# =============================================================================
#  SCHRITT: anpr
# =============================================================================
setup_anpr() {
    step "ANPR-Kennzeichenerkennung"
    local d="$OSERP_ROOT/backend/services/plate-recognition"
    as_user "cd '$d' && python3 -m venv venv && ./venv/bin/pip install --upgrade pip && ./venv/bin/pip install -r requirements.txt"
    install_unit "$OSERP_ROOT/install/anpr.service" "anpr.service"
    sudo systemctl enable anpr.service
    ok "ANPR eingerichtet (Konfiguration im Browser: Einstellungen > ANPR)"
}

# =============================================================================
#  SCHRITT: go2rtc
# =============================================================================
setup_go2rtc() {
    step "go2rtc Stream-Server"
    local d="$OSERP_ROOT/backend/services/go2rtc"
    local bin="$d/go2rtc" cfg="$d/go2rtc.yaml"
    if [[ ! -x "$bin" ]]; then
        local arch; arch="$(uname -m)"; [[ "$arch" == "x86_64" ]] && arch="amd64"; [[ "$arch" == "aarch64" ]] && arch="arm64"
        info "go2rtc-Binary laden ($arch)"
        as_user "curl -fsSL -o '$bin' 'https://github.com/AlexxIT/go2rtc/releases/latest/download/go2rtc_linux_${arch}' && chmod +x '$bin'" \
            || { warn "go2rtc-Download fehlgeschlagen — Binary manuell nach $bin legen"; return 0; }
    fi
    [[ -f "$cfg" ]] || as_user "printf 'streams: {}\\n' > '$cfg'"
    sudo sed \
        -e "s#ExecStart=GOPATH -config CONFIGPATH#ExecStart=${bin} -config ${cfg}#" \
        "$d/go2rtc.service" | sudo tee /etc/systemd/system/go2rtc.service >/dev/null
    sudo systemctl daemon-reload
    sudo systemctl enable go2rtc.service
    ok "go2rtc eingerichtet (Kameras im Browser konfigurieren)"
}

# =============================================================================
#  SCHRITT: camera_monitor
# =============================================================================
setup_camera_monitor() {
    step "Kamera-Monitor (Objekterkennung)"
    local d="$OSERP_ROOT/backend/services/camera-monitor"
    if [[ -f "$d/requirements.txt" ]]; then
        as_user "cd '$d' && python3 -m venv venv && ./venv/bin/pip install --upgrade pip && ./venv/bin/pip install -r requirements.txt"
    fi
    install_unit "$d/camera-monitor.service" "camera-monitor.service"
    sudo systemctl enable camera-monitor.service
    ok "Kamera-Monitor eingerichtet"
}

# =============================================================================
#  SCHRITT: camera_sudoers
# =============================================================================
setup_camera_sudoers() {
    step "Kamera-sudoers (UI-Steuerung)"
    sudo cp "$OSERP_ROOT/backend/services/oserp-camera-sudoers" /etc/sudoers.d/oserp-camera
    sudo chmod 440 /etc/sudoers.d/oserp-camera
    if sudo visudo -c -f /etc/sudoers.d/oserp-camera; then
        ok "sudoers-Regel installiert"
    else
        err "sudoers-Syntaxfehler — /etc/sudoers.d/oserp-camera entfernen!"
        sudo rm -f /etc/sudoers.d/oserp-camera
        return 1
    fi
}

# =============================================================================
#  SCHRITT: cron
# =============================================================================
setup_cron() {
    step "Cronjobs"
    local frag="/etc/cron.d/oserp"
    # System-cron.d: läuft als OSERP_USER, Pfade absolut. Nur vorhandene CLIs eintragen.
    sudo tee "$frag" >/dev/null <<EOF
# OpensourceERP Cronjobs — erzeugt von install.sh
SHELL=/bin/bash
PATH=/usr/local/bin:/usr/bin:/bin
MAILTO=""

# WhatsApp-Erinnerungen (Termine + HU) alle 15 Minuten
*/15 * * * * $OSERP_USER cd $OSERP_ROOT && php backend/cli/whatsapp-reminders.php >> log/whatsapp-reminders.log 2>&1
# eBay-Bestellungen importieren (stündlich)
7 * * * * $OSERP_USER cd $OSERP_ROOT && php backend/cli/ebay-orders.php >> log/ebay-orders.log 2>&1
# Nicht gebuchte Rechnungen nachbuchen (nachts)
30 2 * * * $OSERP_USER cd $OSERP_ROOT && php backend/cli/post-unbooked-invoices.php >> log/post-unbooked.log 2>&1
EOF
    sudo chmod 644 "$frag"
    ok "Cron-Fragment: $frag"
    todo "asanetwork-Sweep (project_esitronic_asanetwork) und weitere Jobs bei Bedarf ergänzen"
}

# =============================================================================
#  SCHRITT: asterisk  (Telefonie — Paket + Gerüst; Config ist umgebungsspezifisch)
# =============================================================================
setup_asterisk() {
    step "Asterisk / CRMTI-Telefonie"
    if ! command -v asterisk >/dev/null; then
        sudo DEBIAN_FRONTEND=noninteractive apt-get install -y asterisk
    fi
    sudo systemctl enable asterisk 2>/dev/null || true
    ok "Asterisk installiert"
    todo "Live-Konfiguration liegt NICHT im Repo (reference_asterisk_crmti_config):"
    todo "  - /etc/asterisk aus kivitendo-crm/crmti-Templates befüllen"
    todo "  - crmti_status/CallStatus-Anbindung + AGI-Ansage (migration/asterisk-ansage-fix)"
    todo "  - Dies ist eine Neuinstallation: Config vom alten Server migrieren"
}

# =============================================================================
#  SCHRITT: borg  (Backup — Paket + Gerüst; Repo/Keys sind Secrets)
# =============================================================================
setup_borg() {
    step "Borg-Backup"
    command -v borg >/dev/null || sudo apt-get install -y borgbackup
    ok "borgbackup installiert ($(borg --version 2>/dev/null || echo '?'))"
    todo "Backup-Ziel ist ein Secret (project_borg_backup_setup): Hetzner-Repo u338100, SSH-Key, Passphrase"
    todo "  - SSH-Key + BORG_PASSPHRASE einrichten, dann Backup-Cron + Auto-Resume-Supervisor"
    todo "  - 'borg-uebersicht'-Helfer + prune/keepalive vom alten Setup übernehmen"
}

# =============================================================================
#  SCHRITT: kivitendo  (externer Installer)
# =============================================================================
setup_kivitendo() {
    step "kivitendo (externer Installer)"
    if [[ -d /var/www/kivitendo-erp || -d "$OSERP_HOME/kivitendo-erp" ]]; then
        ok "kivitendo scheint bereits vorhanden — überspringe"
        return
    fi
    local tmp="$OSERP_HOME/install-kivitendo"
    info "Klone $KIVI_INSTALLER_REPO nach $tmp"
    as_user "rm -rf '$tmp' && git clone '$KIVI_INSTALLER_REPO' '$tmp'" \
        || { warn "Klonen fehlgeschlagen — Installer manuell ausführen"; return 0; }
    local runner
    runner="$(as_user "ls '$tmp'/install*.sh '$tmp'/setup*.sh 2>/dev/null | head -n1" || true)"
    if [[ -n "$runner" ]]; then
        warn "kivitendo-Installer gefunden: $runner"
        todo "Bitte prüfen und ausführen: sudo bash '$runner'  (legt kivitendo_auth + Firmen-DBs an)"
    else
        todo "Kein install*.sh im Repo gefunden — README von $KIVI_INSTALLER_REPO befolgen"
    fi
    todo "Danach OSERP-Schema einspielen: in der UI angemeldet -> POST /api/update/ {action:updateSchema}"
    todo "  bzw. Firmen-DBs sind rohe kivitendo-Dumps (project_kivitendo_no_custom_tables)"
}

# =============================================================================
#  SCHRITT: healthcheck
# =============================================================================
healthcheck() {
    step "Abschluss-Checks"
    local ok_all=1
    _chk() { # name  cmd
        if eval "$2" >/dev/null 2>&1; then ok "$1"; else warn "$1 — prüfen"; ok_all=0; fi
    }
    _chk "Apache aktiv"        "systemctl is-active --quiet apache2"
    _chk "PostgreSQL aktiv"    "systemctl is-active --quiet postgresql"
    _chk "PHP-FPM aktiv"       "systemctl is-active --quiet php*-fpm || pgrep -f php-fpm"
    _chk "SSE :3001"           "curl -m3 -so /dev/null http://127.0.0.1:3001/events"
    _chk "HTTP :80"            "curl -m3 -so /dev/null http://127.0.0.1/"
    want ollama  && _chk "Ollama :11434" "curl -m3 -so /dev/null http://127.0.0.1:11434/"
    echo
    if [[ $ok_all -eq 1 ]]; then
        ok "Grund-Stack läuft. URL: http://localhost"
    else
        warn "Einzelne Checks offen — Details siehe oben / journalctl."
    fi
    echo
    info "Offene manuelle Schritte oben mit [TODO] markiert (Config, Asterisk, Borg, kivitendo)."
}

# =============================================================================
#  Ablauf
# =============================================================================
declare -A STEP_FN=(
  [packages]=install_packages [php_fpm]=setup_php_fpm [node_build]=node_build
  [config]=setup_config [permissions]=setup_permissions [apache]=setup_apache
  [sse]=setup_sse [whisper]=setup_whisper [ollama]=setup_ollama [anpr]=setup_anpr
  [go2rtc]=setup_go2rtc [camera_monitor]=setup_camera_monitor
  [camera_sudoers]=setup_camera_sudoers [cron]=setup_cron [asterisk]=setup_asterisk
  [borg]=setup_borg [kivitendo]=setup_kivitendo [healthcheck]=healthcheck
)

for s in "${ALL_STEPS[@]}"; do
    want "$s" || continue
    "${STEP_FN[$s]}"
done

echo
ok "Installer durchgelaufen. Bei Bedarf einzelne Schritte erneut: ./install/install.sh --only <schritt>"
