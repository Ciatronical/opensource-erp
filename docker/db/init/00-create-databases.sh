#!/bin/bash
# docker/db/init/00-create-databases.sh
#
# Legt die Ziel-Datenbanken an, bevor die Dumps geladen werden.
# Wird vom Postgres-Entrypoint alphabetisch VOR 01- und 02- ausgefuehrt.

set -e

echo "[demo-seed] Erstelle Datenbanken ${DB_AUTH_NAME:-oserp_auth} + ${DB_COMPANY_NAME:-oserp_company}..."

psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres <<EOSQL
CREATE DATABASE ${DB_AUTH_NAME:-oserp_auth};
CREATE DATABASE ${DB_COMPANY_NAME:-oserp_company};
EOSQL
