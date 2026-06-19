#!/bin/bash
# log.sh - Zeigt die Log-Dateien live an (ohne die Dev-Umgebung zu starten)

cd "$(dirname "$0")/.." || exit 1

# Erstelle Log-Verzeichnis und Dateien falls nicht vorhanden
mkdir -p backend/log
touch backend/log/php_error.log 2>/dev/null || true
touch backend/log/opensource_erp.api.debug.log 2>/dev/null || true

echo "=== OpensourceERP Logs ==="
echo "PHP error log: backend/log/php_error.log"
echo "API debug log: backend/log/opensource_erp.api.debug.log"
echo ""
echo "=== Drücke Ctrl+C zum Beenden ==="
echo ""

# Funktion zum Anzeigen von PHP-Fehlern
tail_php_errors() {
    tail -f backend/log/php_error.log 2>/dev/null | while read line; do
        echo "[PHP ERROR] $line"
    done
}

# Funktion zum Anzeigen von API Debug Logs
tail_api_debug() {
    tail -f backend/log/opensource_erp.api.debug.log 2>/dev/null | while read line; do
        # Prüfe ob Zeile mit Timestamp beginnt (neuer Log-Eintrag)
        if [[ "$line" =~ ^\[2[0-9]{3}-[0-9]{2}-[0-9]{2} ]]; then
            echo "[API DEBUG] $line"
        else
            # Folgezeile ohne Präfix
            echo "            $line"
        fi
    done
}

# Cleanup-Funktion
cleanup() {
    echo ""
    echo "=== Beende Log-Anzeige ==="
    kill $TAIL_PHP_PID $TAIL_API_PID 2>/dev/null
    exit 0
}

trap cleanup SIGINT SIGTERM

# Starte beide Log-Monitore im Hintergrund
tail_php_errors &
TAIL_PHP_PID=$!

tail_api_debug &
TAIL_API_PID=$!

# Warte auf Beendigung
wait
