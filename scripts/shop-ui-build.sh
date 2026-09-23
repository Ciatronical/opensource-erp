#!/bin/bash
# scripts/shop-ui-build.sh
#
# Baut die Shop-UI — die Weboberfläche des Shops (Web Components mit Lit).
#
# Es gibt zwei Bündel aus derselben Quelle, und genau das ist der Grund für
# dieses Skript: Laufen sie auseinander, lädt eine Seite zwei verschiedene
# Stände derselben Widgets. Lit findet dann seine Platzhalter nicht wieder,
# und in der Seite stehen Bruchstücke wie lit$777210806$ statt der Knöpfe.
#
#   Paket   backend/templates-default/shop/standard/kit/assets/shop-ui/
#           Geht mit dem Webseiten-Paket an die Shops. Der Läufer
#           (tools/shop-publish.php) überträgt es bei jedem Lauf nach
#           <webseite>/oserp-shop/assets/shop-ui/.
#
#   Demo    shop-ui/dist/
#           Nur für die Entwicklung: die Testseite /shop-ui-demo/ bindet es
#           über einen Hugo-Mount ein. Gehört nicht auf einen echten Shop.
#
# Aufruf:
#   scripts/shop-ui-build.sh              beide Bündel bauen
#   scripts/shop-ui-build.sh --kit        nur das Paket-Bündel
#   scripts/shop-ui-build.sh --demo       nur das Demo-Bündel
#   scripts/shop-ui-build.sh --check      nichts bauen, nur den Stand melden
#   scripts/shop-ui-build.sh --watch      Demo-Bündel bei jeder Änderung neu
#   scripts/shop-ui-build.sh --help       diese Hilfe

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
UI_DIR="$PROJECT_DIR/shop-ui"
KIT_DATEI="$PROJECT_DIR/backend/templates-default/shop/standard/kit/assets/shop-ui/shop-widgets.js"
DEMO_DATEI="$UI_DIR/dist/shop-widgets.js"

hilfe() {
    sed -n '3,27p' "$SCRIPT_DIR/$(basename "${BASH_SOURCE[0]}")" | sed 's/^# \{0,1\}//'
}

# Jüngste Quelldatei in Sekunden seit 1970 — daran hängt der Altersvergleich
juengste_quelle() {
    find "$UI_DIR/src" -type f \( -name '*.js' -o -name '*.css' \) -printf '%T@\n' 2>/dev/null \
        | sort -rn | head -1 | cut -d. -f1
}

# Meldet Alter und Größe eines Bündels und ob es hinter der Quelle zurückliegt
stand() {
    local name="$1" datei="$2" quelle veraltet=0

    if [ ! -f "$datei" ]; then
        printf '  %-6s fehlt: %s\n' "$name" "${datei#$PROJECT_DIR/}"
        return 1
    fi

    quelle="$(juengste_quelle)"
    if [ -n "$quelle" ] && [ "$(stat -c %Y "$datei")" -lt "$quelle" ]; then
        veraltet=1
    fi

    printf '  %-6s %s  %7s Bytes  %s%s\n' \
        "$name" \
        "$(date -d "@$(stat -c %Y "$datei")" '+%d.%m.%Y %H:%M')" \
        "$(stat -c %s "$datei")" \
        "${datei#$PROJECT_DIR/}" \
        "$([ $veraltet -eq 1 ] && echo '   ← älter als der Quelltext')"

    return $veraltet
}

bauen() {
    local name="$1" ziel="$2"

    echo "Baue $name ..."
    if ! (cd "$UI_DIR" && npm run --silent "$ziel"); then
        echo "Fehlgeschlagen: npm run $ziel" >&2
        exit 1
    fi
}

case "${1:-}" in
    --help|-h)
        hilfe
        exit 0
        ;;
    ''|--kit|--demo|--check|--watch)
        ;;
    *)
        echo "Unbekannte Angabe: $1" >&2
        echo "" >&2
        hilfe >&2
        exit 1
        ;;
esac

if [ ! -d "$UI_DIR" ]; then
    echo "Verzeichnis shop-ui/ gibt es nicht: $UI_DIR" >&2
    exit 1
fi

echo "Shop-UI in $UI_DIR"
echo ""
echo "Stand vorher:"
stand "Paket" "$KIT_DATEI" || true
stand "Demo" "$DEMO_DATEI" || true
echo ""

if [ "${1:-}" = "--check" ]; then
    veraltet=0
    stand "Paket" "$KIT_DATEI" >/dev/null || veraltet=1
    stand "Demo" "$DEMO_DATEI" >/dev/null || veraltet=1
    if [ $veraltet -eq 1 ]; then
        echo "Mindestens ein Bündel ist älter als der Quelltext — bauen mit: scripts/shop-ui-build.sh"
        exit 1
    fi
    echo "Beide Bündel sind auf dem Stand des Quelltexts."
    exit 0
fi

# esbuild liegt in den Entwicklungsabhängigkeiten der Shop-UI
if [ ! -d "$UI_DIR/node_modules" ]; then
    echo "node_modules fehlt — installiere die Abhängigkeiten ..."
    (cd "$UI_DIR" && npm install) || exit 1
    echo ""
fi

case "${1:-}" in
    --watch)
        echo "Baue das Demo-Bündel bei jeder Änderung neu. Abbruch mit Strg+C."
        echo "Achtung: Das Paket-Bündel bleibt dabei stehen — vor dem Commit einmal"
        echo "ohne --watch bauen, sonst laufen die beiden Stände auseinander."
        echo ""
        (cd "$UI_DIR" && npm run watch)
        exit $?
        ;;
    --kit)
        bauen "das Paket-Bündel" build
        ;;
    --demo)
        bauen "das Demo-Bündel" build:dev
        ;;
    *)
        bauen "das Paket-Bündel" build
        bauen "das Demo-Bündel" build:dev
        ;;
esac

echo ""
echo "Stand nachher:"
stand "Paket" "$KIT_DATEI" || true
stand "Demo" "$DEMO_DATEI" || true
echo ""
echo "Das Paket-Bündel erreicht einen Shop erst mit dem nächsten Lauf der"
echo "Veröffentlichung: php tools/shop-publish.php --client=<id>"
echo "oder über „Jetzt ausführen\" in der Shop-Übersicht."
