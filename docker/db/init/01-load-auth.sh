#!/bin/bash
# docker/db/init/01-load-auth.sh
#
# Laedt den Auth-Dump. Erkennt automatisch, ob die Quelldatei
# gzip-komprimiert ist (Magic-Bytes 1f 8b).

set -e

SRC="/dumps/auth.sql"
TARGET_DB="${DB_AUTH_NAME:-oserp_auth}"

if [ ! -f "$SRC" ]; then
    echo "[demo-seed] $SRC fehlt - ueberspringe auth-Seed"
    exit 0
fi

echo "[demo-seed] Lade $SRC -> $TARGET_DB..."

# Magic-Bytes pruefen: 1f 8b = gzip
if [ "$(od -An -N2 -tx1 "$SRC" 2>/dev/null | tr -d ' \n')" = "1f8b" ]; then
    gunzip -c "$SRC" | psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$TARGET_DB"
else
    psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$TARGET_DB" < "$SRC"
fi
