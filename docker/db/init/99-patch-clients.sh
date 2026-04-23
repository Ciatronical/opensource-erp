#!/bin/bash
# docker/db/init/99-patch-clients.sh
#
# Die pg_dumps haben die dbhost/dbuser-Felder fuer die lokale (Entwicklungs-)
# Umgebung gesetzt. In der Docker-Demo muss auth.clients auf die Container-
# Umgebung umgeschrieben werden - sonst findet die App die Company-DB nicht.
#
# Entspricht dem Patch-Schritt in scripts/docker.sh::cmd_reset().

set -e

echo "[demo-seed] Patche auth.clients fuer Docker-Umgebung..."

psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "${DB_AUTH_NAME:-oserp_auth}" <<EOSQL
UPDATE auth.clients SET
    dbhost   = 'db',
    dbport   = '5432',
    dbname   = '${DB_COMPANY_NAME:-oserp_company}',
    dbuser   = '${POSTGRES_USER}',
    dbpasswd = '${POSTGRES_PASSWORD}';
EOSQL
