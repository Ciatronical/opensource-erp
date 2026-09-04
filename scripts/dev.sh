#!/bin/bash
# dev.sh - Startet die Entwicklungsumgebung mit automatischem git pull
#
# Aufruf:
#   ./scripts/dev.sh              normaler Start (Installation nur bei Bedarf)
#   ./scripts/dev.sh --force      Installation und Vite-Cache erzwingen
#   ./scripts/dev.sh --skip-pull  ohne git pull starten

# === KONFIGURATION ===
TERMINAL_WIDTH=250
TERMINAL_HEIGHT=40

# Verwende System-PHP
PHP_BIN="php"

# === ARGUMENTE ===
RUN_SERVERS_ONLY=0
FORCE_INSTALL=0
SKIP_PULL=0

for arg in "$@"; do
    case "$arg" in
        --run-servers) RUN_SERVERS_ONLY=1 ;;
        --force|--force-install|-f) FORCE_INSTALL=1 ;;
        --skip-pull) SKIP_PULL=1 ;;
        *) echo "Unbekannte Option: $arg"; exit 1 ;;
    esac
done

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

# === INSTALLATIONS-HELFER ===
# Berechnet eine Pruefsumme ueber die Manifest-Dateien eines Verzeichnisses
deps_hash() {
    cat "$@" 2>/dev/null | sha256sum | awk '{print $1}'
}

# npm install nur, wenn sich package.json/package-lock.json geaendert haben
npm_install_if_needed() {
    local dir="$1"
    local label="$2"
    local stamp="$dir/node_modules/.dev-deps-stamp"
    local current
    current=$(deps_hash "$dir/package.json" "$dir/package-lock.json")

    if [ "$FORCE_INSTALL" -eq 0 ] \
        && [ -d "$dir/node_modules" ] \
        && [ -f "$stamp" ] \
        && [ "$(cat "$stamp")" = "$current" ]; then
        echo "$label: Abhängigkeiten unverändert — Installation übersprungen."
        return 0
    fi

    echo "$label: Installiere Abhängigkeiten..."
    if (cd "$dir" && npm install --no-audit --no-fund); then
        echo "$current" > "$stamp"
    else
        echo "WARNUNG: npm install in $dir fehlgeschlagen."
        return 1
    fi
}

# composer install nur, wenn sich composer.json/composer.lock geaendert haben
composer_install_if_needed() {
    local dir="$1"
    local stamp="$dir/vendor/.dev-deps-stamp"
    local current
    current=$(deps_hash "$dir/composer.json" "$dir/composer.lock")

    if [ "$FORCE_INSTALL" -eq 0 ] \
        && [ -d "$dir/vendor" ] \
        && [ -f "$stamp" ] \
        && [ "$(cat "$stamp")" = "$current" ]; then
        echo "Composer: Abhängigkeiten unverändert — Installation übersprungen."
        return 0
    fi

    echo "Installing PHP dependencies (composer)..."
    local ok=0
    if command -v composer &>/dev/null; then
        (cd "$dir" && composer install --no-interaction) && ok=1
    elif [ -f "$dir/composer.phar" ]; then
        (cd "$dir" && php composer.phar install --no-interaction) && ok=1
    else
        echo "WARNUNG: composer nicht gefunden und $dir/composer.phar fehlt."
        echo "  Installation: sudo apt install composer"
        echo "  oder: cd $dir && php -r \"copy('https://getcomposer.org/installer', 'composer-setup.php');\" && php composer-setup.php && rm composer-setup.php"
        return 1
    fi

    if [ "$ok" -eq 1 ]; then
        echo "$current" > "$stamp"
    else
        echo "WARNUNG: composer install fehlgeschlagen."
        return 1
    fi
}

# === VORBEREITUNG (git pull, Logs, Abhaengigkeiten) ===
prepare_env() {
    if [ "$SKIP_PULL" -eq 0 ]; then
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
    fi

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

    # Abhaengigkeiten nur bei Bedarf installieren
    npm_install_if_needed "." "Frontend"
    npm_install_if_needed "backend/sse" "SSE-Server"
    if [ -f backend/composer.json ]; then
        composer_install_if_needed "backend"
    fi
    echo ""
}

# === Server-Start-Funktion ===
run_servers() {
    # Stoppe eventuell laufende Server
    echo "Stopping old servers..."
    pkill -f "php -S localhost:8000" 2>/dev/null
    pkill -f "vite" 2>/dev/null
    pkill -f "sse-server.js" 2>/dev/null
    sleep 1

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

    # Starte npm dev server — Vite-Cache nur bei --force neu aufbauen
    echo ""
    echo "Starting NPM Dev Server..."
    if [ "$FORCE_INSTALL" -eq 1 ]; then
        VITE_ARGS="--force"
    else
        VITE_ARGS=""
    fi
    npm run dev -- $VITE_ARGS 2>&1 | while read line; do
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
if [ "$RUN_SERVERS_ONLY" -eq 1 ]; then
    # Zweiter Durchlauf im neuen Terminal: Vorbereitung lief bereits
    run_servers
else
    prepare_env
    if command -v gnome-terminal &>/dev/null && [ -n "$DISPLAY" ]; then
        CHILD_ARGS="--run-servers"
        [ "$FORCE_INSTALL" -eq 1 ] && CHILD_ARGS="$CHILD_ARGS --force"
        gnome-terminal --title="OpensourceERP" --geometry=${TERMINAL_WIDTH}x${TERMINAL_HEIGHT} -- bash -c "cd $(pwd) && bash scripts/dev.sh $CHILD_ARGS; exec bash"
    else
        run_servers
    fi
fi
