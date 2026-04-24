#!/bin/bash
# start-dev.sh - Startet die Entwicklungsumgebung mit automatischem git pull

# === KONFIGURATION ===
TERMINAL_WIDTH=250
TERMINAL_HEIGHT=40

# Verwende System-PHP
PHP_BIN="php"

# Prüfe ob PHP installiert ist
if ! command -v php &> /dev/null; then
    echo "ERROR: PHP nicht gefunden. Bitte installieren:"
    echo "sudo apt install php-cli php-pgsql php-mbstring php-xml php-curl"
    exit 1
fi

# Prüfe PHP-Version (mindestens 8.0)
PHP_VERSION=$(php -r 'echo PHP_VERSION;')
PHP_MAJOR=$(echo $PHP_VERSION | cut -d. -f1)
if [ "$PHP_MAJOR" -lt 8 ]; then
    echo "ERROR: PHP 8.0 oder höher erforderlich, gefunden: $PHP_VERSION"
    exit 1
fi

# === GIT PULL ===
echo "=== Updating from Git ==="
if ! git pull; then
    echo "ERROR: Git pull fehlgeschlagen!"
    read -p "Trotzdem fortfahren? (j/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Jj]$ ]]; then
        exit 1
    fi
fi
echo ""

# Erstelle Log-Verzeichnis falls nicht vorhanden
mkdir -p backend/log
touch backend/log/php_error.log 2>/dev/null || true
touch backend/log/opensource_erp.api.debug.log 2>/dev/null || true
# Log-Dateien muessen dem aktuellen User gehoeren
if [ -f backend/log/opensource_erp.api.debug.log ] && [ ! -w backend/log/opensource_erp.api.debug.log ]; then
    echo "WARNUNG: backend/log/opensource_erp.api.debug.log ist nicht beschreibbar."
    echo "  Fix: sudo chown $(whoami):apache2 backend/log/opensource_erp.api.debug.log"
fi

echo "Using PHP: $($PHP_BIN -v | head -n 1)"
echo "PHP logs to: backend/log/php_error.log"
echo "API logs to: backend/log/opensource_erp.api.debug.log"

# === Projekt-Statistiken ===
echo ""
echo "=== Projekt-Statistiken ==="
FILE_COUNT=$(find src -name '*.vue' -o -name '*.js' | wc -l)
LINE_COUNT=$(find src -name '*.vue' -o -name '*.js' | xargs wc -l 2>/dev/null | tail -1 | awk '{print $1}')
SRC_SIZE=$(du -sh --exclude='.git' src/ | awk '{print $1}')
echo "  Dateien (Vue/JS): $FILE_COUNT"
echo "  Codezeilen:       $LINE_COUNT"
echo "  src/ Größe:       $SRC_SIZE"
echo ""

# Installiere npm-Abhängigkeiten
npm install

# Installiere SSE-Server-Abhängigkeiten
echo "Installing SSE server dependencies..."
(cd backend/sse && npm install)

# Installiere PHP-Abhängigkeiten (Composer) — für E-Rechnung, FinTS etc.
if [ -f backend/composer.json ]; then
    echo ""
    echo "Installing PHP dependencies (composer)..."
    if command -v composer &>/dev/null; then
        (cd backend && composer install --no-interaction)
    elif [ -f backend/composer.phar ]; then
        (cd backend && php composer.phar install --no-interaction)
    else
        echo "WARNUNG: composer nicht gefunden und backend/composer.phar fehlt."
        echo "  Installation: sudo apt install composer"
        echo "  oder: cd backend && php -r \"copy('https://getcomposer.org/installer', 'composer-setup.php');\" && php composer-setup.php && rm composer-setup.php"
    fi
fi

# Stoppe eventuell laufende Server
echo "Stopping old servers..."
pkill -f "php -S localhost:8000" 2>/dev/null
pkill -f "vite" 2>/dev/null
pkill -f "sse-server.js" 2>/dev/null
sleep 1

# === Server-Start-Funktion ===
run_servers() {
    echo "=== OpensourceERP Development Environment ==="
    echo ""

    # Funktion zum Anzeigen von PHP-Fehlern
    tail_php_errors() {
        if [ -f backend/log/php_error.log ]; then
            tail -f backend/log/php_error.log 2>/dev/null | while read line; do
                echo "[PHP ERROR] $line"
            done
        fi
    }

    # Funktion zum Anzeigen von API Debug Logs
    tail_api_debug() {
        if [ -f backend/log/opensource_erp.api.debug.log ]; then
            tail -f backend/log/opensource_erp.api.debug.log 2>/dev/null | while read line; do
                # Prüfe ob Zeile mit Timestamp beginnt (neuer Log-Eintrag)
                if [[ "$line" =~ ^\[2[0-9]{3}-[0-9]{2}-[0-9]{2} ]]; then
                    echo "[API DEBUG] $line"
                else
                    # Folgezeile ohne Präfix
                    echo "            $line"
                fi
            done
        fi
    }

    # Starte PHP-Server im Hintergrund
    echo "Starting PHP Server on localhost:8000..."
    php -S localhost:8000 -t ./backend/ -d post_max_size=32M -d upload_max_filesize=32M 2>&1 | while read line; do
        echo "[PHP] $line"
    done &
    PHP_PID=$!

    # Starte Error-Log-Monitor im Hintergrund
    tail_php_errors &
    TAIL_PHP_PID=$!

    # Starte API Debug-Log-Monitor im Hintergrund
    tail_api_debug &
    TAIL_API_PID=$!

    # Starte SSE-Server im Hintergrund
    echo "Starting SSE Server on localhost:3001..."
    (cd backend/sse && node sse-server.js) 2>&1 | while read line; do
        echo "[SSE] $line"
    done &
    SSE_PID=$!

    # Kurze Pause damit PHP und SSE starten können
    sleep 2

    # Starte npm dev server
    echo ""
    echo "Starting NPM Dev Server..."
    npm run dev -- --force 2>&1 | while read line; do
        echo "[NPM] $line"
    done &
    NPM_PID=$!

    # Flag für cleanup
    CLEANING_UP=0

    # Cleanup-Funktion
    cleanup() {
        if [ $CLEANING_UP -eq 1 ]; then
            return
        fi
        CLEANING_UP=1

        echo ""
        echo "=== Stopping Servers ==="
        kill $PHP_PID $NPM_PID $SSE_PID $TAIL_PHP_PID $TAIL_API_PID 2>/dev/null
        pkill -f "php -S localhost:8000" 2>/dev/null
        pkill -f "vite" 2>/dev/null
        pkill -f "sse-server.js" 2>/dev/null
        exit 0
    }

    # Trap für sauberes Beenden
    trap cleanup SIGINT SIGTERM

    echo ""
    echo "=== All servers running - Press Ctrl+C to stop ==="
    echo ""

    # Warte auf Beendigung
    wait
}

# Starte Server — in gnome-terminal falls GUI vorhanden, sonst direkt im Terminal
if [ "$1" = "--run-servers" ]; then
    run_servers
elif command -v gnome-terminal &>/dev/null && [ -n "$DISPLAY" ]; then
    gnome-terminal --title="OpensourceERP" --geometry=${TERMINAL_WIDTH}x${TERMINAL_HEIGHT} -- bash -c "cd $(pwd) && bash scripts/dev.sh --run-servers; exec bash"
else
    run_servers
fi
