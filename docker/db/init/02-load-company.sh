#!/bin/bash
# docker/db/init/02-load-company.sh
#
# Laedt den Company-Dump. Erkennt automatisch, ob die Quelldatei
# gzip-komprimiert ist (Magic-Bytes 1f 8b).

set -e

SRC="/dumps/company.sql"
TARGET_DB="${DB_COMPANY_NAME:-oserp_company}"

if [ ! -f "$SRC" ]; then
    echo "[demo-seed] $SRC fehlt - ueberspringe company-Seed"
    exit 0
fi

echo "[demo-seed] Lade $SRC -> $TARGET_DB..."

if [ "$(od -An -N2 -tx1 "$SRC" 2>/dev/null | tr -d ' \n')" = "1f8b" ]; then
    gunzip -c "$SRC" | psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$TARGET_DB"
else
    psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$TARGET_DB" < "$SRC"
fi
