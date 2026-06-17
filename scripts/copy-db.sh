#!/usr/bin/env bash
#
# Kopiert die Datenbank ap_rebuild in ap_dev (Quelle bleibt unverändert).
# Lokale PostgreSQL auf Port 5432.
#
# Variante mit pg_dump | psql: Die Quell-DB wird NICHT angetastet und es werden
# KEINE Verbindungen getrennt — kivitendo kann während des Kopierens weiterlaufen.
# Nur die Ziel-DB ap_dev wird gelöscht und neu aufgebaut.
#
# Kopiert ZUSÄTZLICH die mandantenspezifischen Belege/Dateien
# (backend/data/<db>/), da diese pro DB-Name getrennt liegen und sonst in der
# Kopie fehlen würden.
#
set -euo pipefail

SRC_DB="ap_rebuild"
DST_DB="ap_dev"

PGHOST="${PGHOST:-localhost}"
PGPORT="${PGPORT:-5432}"
PGUSER="${PGUSER:-postgres}"

PSQL="psql -h $PGHOST -p $PGPORT -U $PGUSER"

echo "Kopiere Datenbank '$SRC_DB' -> '$DST_DB' ($PGHOST:$PGPORT)"

# Prüfen, ob die Quell-DB existiert
if ! $PSQL -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$SRC_DB'" | grep -q 1; then
    echo "FEHLER: Quell-Datenbank '$SRC_DB' existiert nicht." >&2
    exit 1
fi

# Logo (company_logo in defaults_oserp) der bestehenden ap_dev sichern — es soll
# beim Kopieren NICHT durch das Logo der Quelle ersetzt werden.
LOGO_FILE=""
if $PSQL -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$DST_DB'" | grep -q 1; then
    LOGO_FILE="$(mktemp)"
    echo "Sichere bestehendes Logo (company_logo) aus '$DST_DB' ..."
    $PSQL -d "$DST_DB" -c \
        "\copy (SELECT value FROM defaults_oserp WHERE key = 'company_logo') TO '$LOGO_FILE'"
    # Wenn kein Logo vorhanden war, Sicherung verwerfen
    if [ ! -s "$LOGO_FILE" ]; then
        echo "  (kein Logo in '$DST_DB' gefunden — nichts zu sichern)"
        rm -f "$LOGO_FILE"
        LOGO_FILE=""
    fi
fi

# Ziel-DB löschen, falls vorhanden (nur ap_dev — Verbindungen hierher trennen)
if $PSQL -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$DST_DB'" | grep -q 1; then
    echo "Ziel-Datenbank '$DST_DB' existiert bereits — wird gelöscht ..."
    $PSQL -d postgres -c \
        "SELECT pg_terminate_backend(pid) FROM pg_stat_activity \
         WHERE datname = '$DST_DB' AND pid <> pg_backend_pid();" >/dev/null
    $PSQL -d postgres -c "DROP DATABASE \"$DST_DB\";"
fi

# Leere Ziel-DB anlegen (gleiche Encoding-/Locale-Einstellungen wie die Quelle)
echo "Erstelle leere Datenbank '$DST_DB' ..."
$PSQL -d postgres -c \
    "CREATE DATABASE \"$DST_DB\" OWNER \"$PGUSER\" TEMPLATE template0 \
     ENCODING 'UTF8' LC_COLLATE 'de_DE.UTF-8' LC_CTYPE 'de_DE.UTF-8';"

# Daten per Dump-Stream übertragen — Quelle wird nur gelesen
echo "Übertrage Schema und Daten ..."
pg_dump -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" --no-owner --no-privileges "$SRC_DB" \
    | $PSQL -d "$DST_DB" --single-transaction --set ON_ERROR_STOP=on >/dev/null

# Gesichertes Logo wieder einspielen — überschreibt das aus der Quelle kopierte Logo
if [ -n "$LOGO_FILE" ]; then
    echo "Stelle ursprüngliches Logo von '$DST_DB' wieder her ..."
    $PSQL -d "$DST_DB" --single-transaction --set ON_ERROR_STOP=on <<SQL >/dev/null
CREATE TEMP TABLE _logo_restore(value text);
\copy _logo_restore FROM '$LOGO_FILE'
UPDATE defaults_oserp
   SET value = (SELECT value FROM _logo_restore LIMIT 1),
       mtime = now()
 WHERE key = 'company_logo';
SQL
    rm -f "$LOGO_FILE"
fi

# Belege/Dateien mitkopieren — liegen pro DB-Name getrennt unter backend/data/<db>/
# (siehe fmDataDir() in backend/api/customer_vendor/filemanager.php). Ohne diesen
# Schritt fehlen in der Kopie alle Kunden-/Lieferanten-Belege.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DATA_DIR="$REPO_ROOT/backend/data"
SRC_DATA="$DATA_DIR/$SRC_DB"
DST_DATA="$DATA_DIR/$DST_DB"

if [ -d "$SRC_DATA" ]; then
    echo "Kopiere Belege/Dateien '$SRC_DATA/' -> '$DST_DATA/' ..."

    # Ein vorhandenes Ziel kann Unterordner enthalten, die php-fpm als www-data
    # und NICHT gruppenschreibbar angelegt hat — darin kann der ausführende
    # Benutzer keine neuen Ordner erstellen (rsync scheitert mit Permission
    # denied). Da uns der übergeordnete data/-Ordner gehört, schieben wir das
    # alte Ziel beiseite und kopieren in einen frischen, eigenen Ordner. So
    # funktioniert es ohne sudo, unabhängig von den Alt-Rechten.
    TRASH=""
    if [ -d "$DST_DATA" ]; then
        TRASH="$DATA_DIR/.trash-$DST_DB"
        rm -rf "$TRASH" 2>/dev/null || true
        # Reste, die uns nicht gehören (www-data), ließen sich evtl. nicht
        # löschen — dann eindeutigen Namen wählen statt das mv zu riskieren.
        [ -e "$TRASH" ] && TRASH="$TRASH-$(date +%s)"
        mv "$DST_DATA" "$TRASH"
    fi

    mkdir -p "$DST_DATA"
    rsync -a "$SRC_DATA/" "$DST_DATA/"
    find "$DST_DATA" -type d -exec chmod 2775 {} +
    echo "  $(find "$DST_DATA" -type f | wc -l) Dateien kopiert."

    # Altes Ziel best-effort entfernen; www-data-eigene Reste brauchen sudo.
    if [ -n "$TRASH" ]; then
        rm -rf "$TRASH" 2>/dev/null || true
        [ -e "$TRASH" ] && echo "Hinweis: Reste in '$TRASH' gehören www-data — bei Bedarf mit 'sudo rm -rf $TRASH' entfernen." >&2
    fi
else
    echo "Hinweis: Quell-Datenverzeichnis '$SRC_DATA' existiert nicht — Dateien werden übersprungen." >&2
fi

echo "Fertig. '$DST_DB' ist jetzt eine Kopie von '$SRC_DB' (DB + Belege, Logo beibehalten)."
