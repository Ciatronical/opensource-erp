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

# =========================================================================
# 5. Sicherheits- & Aktualitäts-Check (immer als LETZTES, damit sichtbar)
# =========================================================================
echo ""
echo "5. Abhängigkeiten prüfen (Sicherheit & Aktualität)..."

# Farben nur, wenn ein echtes Terminal dranhängt
if [ -t 1 ]; then
    R=$'\e[1;37;41m'; Y=$'\e[1;33m'; G=$'\e[1;32m'; B=$'\e[0m'
else
    R=''; Y=''; G=''; B=''
fi

# Zählt die Vulnerabilities eines npm-Projekts. Gibt "crit high mod low" zurück.
count_npm_vulns() {
    npm audit --json 2>/dev/null | node -e '
        let s = "";
        process.stdin.on("data", d => s += d);
        process.stdin.on("end", () => {
            try {
                const v = JSON.parse(s).metadata.vulnerabilities;
                console.log(`${v.critical||0} ${v.high||0} ${v.moderate||0} ${v.low||0}`);
            } catch (e) { console.log("? ? ? ?"); }
        });
    ' 2>/dev/null || echo "? ? ? ?"
}

TOTAL_HIGH=0
TOTAL_ALL=0

check_npm_project() {
    local name="$1" dir="$2"
    ( cd "$dir" || exit 1
      read -r crit high mod low <<< "$(count_npm_vulns)"
      [ "$crit" = "?" ] && { echo "  [$name] Audit nicht möglich (übersprungen)"; exit 0; }
      local sum=$((crit + high + mod + low))
      if [ "$sum" -eq 0 ]; then
          echo "  ${G}✓${B} [$name] keine bekannten Lücken"
      else
          echo "  [$name] kritisch:$crit  hoch:$high  mittel:$mod  niedrig:$low"
      fi
      echo "$crit $high $sum" > /tmp/oserp_audit_$$_"${name//\//_}"
    )
    if [ -f "/tmp/oserp_audit_$$_${name//\//_}" ]; then
        read -r c h s < "/tmp/oserp_audit_$$_${name//\//_}"
        rm -f "/tmp/oserp_audit_$$_${name//\//_}"
        TOTAL_HIGH=$((TOTAL_HIGH + c + h))
        TOTAL_ALL=$((TOTAL_ALL + s))
    fi
}

check_npm_project "Frontend"   "$PROJECT_DIR"
check_npm_project "SSE-Server" "$PROJECT_DIR/backend/sse"

# PHP/Composer (falls verfügbar)
if [ -f "$PROJECT_DIR/backend/composer.json" ]; then
    COMPOSER_CMD=""
    command -v composer &>/dev/null && COMPOSER_CMD="composer"
    [ -z "$COMPOSER_CMD" ] && [ -f "$PROJECT_DIR/backend/composer.phar" ] && COMPOSER_CMD="php composer.phar"
    if [ -n "$COMPOSER_CMD" ]; then
        PHP_VULN=$(cd "$PROJECT_DIR/backend" && $COMPOSER_CMD audit --format=plain --no-dev 2>/dev/null | grep -ciE "advisor|CVE" || true)
        if [ "${PHP_VULN:-0}" -gt 0 ]; then
            echo "  [PHP/Composer] $PHP_VULN Sicherheitshinweis(e) — 'cd backend && $COMPOSER_CMD audit' ausführen"
            TOTAL_HIGH=$((TOTAL_HIGH + PHP_VULN))
            TOTAL_ALL=$((TOTAL_ALL + PHP_VULN))
        else
            echo "  ${G}✓${B} [PHP/Composer] keine bekannten Lücken"
        fi
    fi
fi

# Veraltete Frontend-Pakete (nur Info, kein Alarm)
#
# 'npm outdated' liest stumpf den "latest"-Tag aus der Registry. Bei Paketen, deren
# Maintainer den Tag nie umgehängt hat, zeigt der rückwärts: vuedraggable meldet
# "latest 2.24.3", das ist aber die Vue-2-Linie — die Vue-3-Version 4.1.0 hängt am
# "next"-Tag. Solche Einträge werden hier aussortiert, sonst steht dauerhaft ein
# Fehlalarm im Report und echte Rückstände gehen darin unter.
OUTDATED_RAW=$( (npm outdated --json 2>/dev/null || true) | node -e '
    let s = "";
    process.stdin.on("data", d => s += d);
    process.stdin.on("end", () => {
        let o = {};
        try { o = JSON.parse(s || "{}"); } catch (e) { console.log("0"); console.log(""); return; }
        const num = v => String(v || "0").split("-")[0].split(".").map(n => parseInt(n, 10) || 0);
        const cmp = (a, b) => {
            const x = num(a), y = num(b);
            for (let i = 0; i < 3; i++) if ((x[i]||0) !== (y[i]||0)) return (x[i]||0) < (y[i]||0) ? -1 : 1;
            return 0;
        };
        const real = [], traps = [];
        for (const [name, raw] of Object.entries(o)) {
            const info = Array.isArray(raw) ? raw[0] : raw;
            if (!info || !info.current || !info.latest) continue;
            const d = cmp(info.latest, info.current);
            if (d < 0) traps.push(name + " (" + info.current + " > latest " + info.latest + ")");
            else if (d > 0) real.push(name);
        }
        console.log(real.length);
        console.log(traps.join(", "));
    });
' 2>/dev/null )
{ read -r OUTDATED; read -r TRAPS; } <<< "$OUTDATED_RAW"

[ "${OUTDATED:-0}" -gt 0 ] && echo "  ${Y}ℹ${B} $OUTDATED veraltete npm-Paket(e) — Details: 'npm outdated'"
[ -n "${TRAPS:-}" ] && echo "  ${Y}ℹ${B} ignoriert, \"latest\"-Tag zeigt rückwärts: $TRAPS"

# Gegenprobe: ist so ein Paket bereits versehentlich heruntergestuft worden?
# 'npm i <paket>@latest' schreibt die Range in package.json einfach um, das ^ schützt
# davor nicht. Hier fällt es beim nächsten Build auf, statt erst im kaputten Frontend.
# Format: paketname:erwarteter-mindest-major
DIST_TAG_GUARD="vuedraggable:4"

DOWNGRADE_ALERT=0
for entry in $DIST_TAG_GUARD; do
    pkg="${entry%:*}"
    min="${entry##*:}"
    pkg_json="$PROJECT_DIR/node_modules/$pkg/package.json"
    [ -f "$pkg_json" ] || continue
    installed=$(node -p "require('$pkg_json').version" 2>/dev/null) || continue
    major="${installed%%.*}"
    if [ "${major:-0}" -lt "$min" ]; then
        echo "  ${R} ✗ [$pkg] Version $installed installiert, erwartet >= $min.x ${B}"
        DOWNGRADE_ALERT=$((DOWNGRADE_ALERT + 1))
    fi
done

echo ""
if [ "$TOTAL_ALL" -eq 0 ]; then
    echo "${G}=========================================${B}"
    echo "${G} ✓  Keine bekannten Sicherheitslücken.${B}"
    echo "${G}=========================================${B}"
elif [ "$TOTAL_HIGH" -gt 0 ]; then
    echo "${R}                                                          ${B}"
    echo "${R}   ⚠  ACHTUNG – SICHERHEITSLÜCKE(N)!!!  ⚠                 ${B}"
    echo "${R}                                                          ${B}"
    echo "${R}   $TOTAL_ALL Lücke(n) gefunden, davon $TOTAL_HIGH kritisch/hoch.${B}"
    echo "${R}   Beheben:  npm audit fix    (bei Bedarf --force)        ${B}"
    echo "${R}                                                          ${B}"
else
    echo "${Y}=========================================${B}"
    echo "${Y} ⚠  $TOTAL_ALL Sicherheitshinweis(e) (mittel/niedrig).${B}"
    echo "${Y}    Beheben:  npm audit fix${B}"
    echo "${Y}=========================================${B}"
fi

# Falsche Paket-Version ist keine Sicherheitslücke, aber genauso build-relevant —
# deshalb ein eigener Block unterhalb des Sicherheits-Banners.
if [ "${DOWNGRADE_ALERT:-0}" -gt 0 ]; then
    echo ""
    echo "${R}                                                          ${B}"
    echo "${R}   ⚠  FALSCHE PAKET-VERSION INSTALLIERT!  ⚠               ${B}"
    echo "${R}                                                          ${B}"
    echo "${R}   $DOWNGRADE_ALERT Paket(e) auf einer inkompatiblen Linie.        ${B}"
    echo "${R}   Beheben:  npm ci    (stellt package-lock.json wieder her)${B}"
    echo "${R}                                                          ${B}"
fi
