#!/bin/bash
# tools/refresh-fints-banks.sh
#
# Aktualisiert backend/api/banking/fints-banks.json aus der offenen
# hbci4j-Datenbank (Java HBCI-Library, pflegt die BLZ/FinTS-URL-Liste).
#
# Wird nur bei Bedarf ausgefuehrt — die DB aendert sich ~1-2x pro Jahr.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

SRC="https://raw.githubusercontent.com/hbci4j/hbci4java/master/src/main/resources/blz.properties"
TMP="$(mktemp --suffix=.properties)"
OUT="$PROJECT_DIR/backend/api/banking/fints-banks.json"

echo "Downloading $SRC ..."
curl -fsSL "$SRC" -o "$TMP"
TOTAL=$(wc -l < "$TMP")
echo "  $TOTAL Zeilen geladen."

php <<PHP
<?php
\$banks = [];
foreach (file('$TMP', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as \$line) {
    if (\$line[0] === '#' || strpos(\$line, '=') === false) continue;
    [\$blz, \$rest] = explode('=', \$line, 2);
    \$fields = explode('|', \$rest);
    \$name     = trim(\$fields[0] ?? '');
    \$bic      = trim(\$fields[2] ?? '');
    \$fintsUrl = trim(\$fields[5] ?? '');
    \$blz = trim(\$blz);
    if (\$blz === '' || \$fintsUrl === '') continue;
    \$banks[\$blz] = ['name' => \$name, 'url' => \$fintsUrl];
    if (\$bic !== '') \$banks[\$blz]['bic'] = \$bic;
}
\$data = [
    '_comment' => 'FinTS/HBCI-URL-Zuordnung fuer deutsche Banken. Aus hbci4j/blz.properties importiert (https://github.com/hbci4j/hbci4java). Zum Aktualisieren: tools/refresh-fints-banks.sh ausfuehren.',
    'exact'    => \$banks,
];
file_put_contents('$OUT', json_encode(\$data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
echo "  " . count(\$banks) . " BLZ mit FinTS-URL exportiert.\n";
PHP

rm -f "$TMP"
echo "Fertig: $OUT"
