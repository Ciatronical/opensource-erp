#!/bin/bash
# run-build.sh

# Absoluten Pfad zum Script ermitteln (egal von wo es aufgerufen wird)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Projektverzeichnis = eine Ebene über /scripts
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

echo "========================================="
echo "OpensourceERP - Build & Deploy Script"
echo "========================================="
echo ""
echo "Projektverzeichnis: $PROJECT_DIR"
echo ""

cd "$PROJECT_DIR" || exit 1

echo "0. Aktuelles Repository aktualisieren..."
git pull

# === Projekt-Statistiken ===
echo "=== Projekt-Statistiken ==="
FILE_COUNT=$(find src -name '*.vue' -o -name '*.js' | wc -l)
LINE_COUNT=$(find src -name '*.vue' -o -name '*.js' | xargs wc -l 2>/dev/null | tail -1 | awk '{print $1}')
SRC_SIZE=$(du -sh --exclude='.git' src/ | awk '{print $1}')
echo "  Dateien (Vue/JS): $FILE_COUNT"
echo "  Codezeilen:       $LINE_COUNT"
echo "  src/ Größe:       $SRC_SIZE"
echo ""

echo "1. Dependencies installieren..."
npm install

echo ""
echo "2. Vue.js bauen..."
npm run build

echo ""
echo "2b. SSE-Server Dependencies..."
(cd backend/sse && npm install --omit=dev)

echo ""
echo "2c. PHP-Dependencies (Composer) für E-Rechnung/FinTS..."
if [ -f backend/composer.json ]; then
    if command -v composer &>/dev/null; then
        (cd backend && composer install --no-interaction --no-dev --optimize-autoloader)
    elif [ -f backend/composer.phar ]; then
        (cd backend && php composer.phar install --no-interaction --no-dev --optimize-autoloader)
    else
        echo "  WARNUNG: composer nicht gefunden und backend/composer.phar fehlt."
        echo "  Bitte einmalig: sudo apt install composer"
    fi
fi

if [ $? -ne 0 ]; then
    echo ""
    echo "❌ Build fehlgeschlagen!"
    exit 1
fi

echo ""
echo "3. Build-ID: $(cat dist/build-id.txt 2>/dev/null || echo '(von Vite geschrieben)')"

echo ""
echo "4. Berechtigungen setzen..."
find dist -type d -exec chmod 775 {} \; 2>/dev/null
find dist -type f -exec chmod 664 {} \; 2>/dev/null
chmod 777 backup 2>/dev/null

# echo ""
# echo "3. Apache neuladen..."
# sudo systemctl reload apache2

echo ""
echo "========================================="
echo "Build & Deploy erfolgreich!"
echo "========================================="
echo "URL: http://localhost"
echo "API-Test: curl -X POST http://localhost/api/ -H 'Content-Type: application/json' -d '{\"action\":\"getClients\"}'"
echo "Logs: tail -f backend/log/php_error.log"
echo "========================================="
