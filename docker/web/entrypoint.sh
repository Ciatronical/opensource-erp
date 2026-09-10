#!/bin/bash
set -e

echo "=== OpensourceERP: Container wird gestartet ==="

# ── 1. settings.ini generieren ──
SETTINGS_DIR="/var/www/html/backend/config"
SETTINGS_FILE="$SETTINGS_DIR/settings.ini"

# Passwort XOR-verschluesseln (identisch zu OserpConfig::enc() in config.php Zeile 52-55)
encrypt_password() {
    php -r '
        $key = "k";
        $s = $argv[1];
        echo base64_encode($s ^ str_repeat($key, strlen($s)));
    ' "$1"
}

ENCRYPTED_PASS=$(encrypt_password "${POSTGRES_PASSWORD}")

mkdir -p "$SETTINGS_DIR"
mkdir -p /var/www/html/backend/log
mkdir -p /var/www/html/backend/data
mkdir -p /var/www/html/backend/backups
mkdir -p /var/www/html/backend/tmp

cat > "$SETTINGS_FILE" <<EOF
; config/settings.ini
; Automatisch generiert durch Docker-Entrypoint.
; Generiert: $(date '+%Y-%m-%d %H:%M:%S')

[database]
host = "db"
port = "5432"
auth_db = "${DB_AUTH_NAME:-oserp_auth}"
auth_user = "${POSTGRES_USER:-postgres}"
auth_pass = "${ENCRYPTED_PASS}"

[session]
cookie_name = "${SESSION_COOKIE_NAME:-opensource_erp}"
cookie_same_site = "${SESSION_COOKIE_SAMESITE:-Strict}"

[logging]
max_log_size = 10485760
debug_log_file = "/var/www/html/backend/log/opensource_erp.api.debug.log"

[system]
timezone = "${APP_TIMEZONE:-Europe/Berlin}"
debug = ${APP_DEBUG:-false}
backup_dir = "/var/www/html/backend/backups/"

EOF

# Demo-Modus Konfiguration
if [ "${DEMO_MODE}" = "true" ]; then
    cat >> "$SETTINGS_FILE" <<DEMOEOF
[demo]
enabled = true
inactivity_minutes = ${DEMO_INACTIVITY_MINUTES:-20}
company_db = "${DB_COMPANY_NAME:-oserp_company}"

DEMOEOF
    echo "Demo-Modus aktiviert (Inaktivität: ${DEMO_INACTIVITY_MINUTES:-20} Min.)"
fi

echo "settings.ini generiert."

# ── 2. Berechtigungen setzen ──
chown -R www-data:www-data /var/www/html/backend/log
chown -R www-data:www-data /var/www/html/backend/data
chown -R www-data:www-data /var/www/html/backend/backups
chown -R www-data:www-data /var/www/html/backend/tmp
chown www-data:www-data "$SETTINGS_FILE"
chmod 640 "$SETTINGS_FILE"

# ── 3. Demo-Modus: DB-Snapshot erstellen ──
# Der Snapshot ist die Vorlage für den Demo-Reset. Er darf den Containerstart
# NICHT abbrechen: Ein harter Fehler an dieser Stelle führte zu einer endlosen
# Restart-Schleife und damit zu 502 am Reverse-Proxy. Deshalb gilt hier:
# auf die DB warten, pg_dump wiederholen, im Zweifel ohne frischen Snapshot
# weiterstarten.
if [ "${DEMO_MODE}" = "true" ]; then
    SNAPSHOT_FILE="/var/www/html/backend/data/demo_snapshot.sql"
    COMPANY_DB="${DB_COMPANY_NAME:-oserp_company}"
    DB_WAIT_SECONDS="${DEMO_DB_WAIT_SECONDS:-180}"
    DUMP_RETRIES="${DEMO_DUMP_RETRIES:-3}"

    export PGPASSWORD="${POSTGRES_PASSWORD}"

    # 3a. Auf die Company-DB warten.
    # pg_isready genügt nicht: Während des Auto-Seeds nimmt Postgres bereits
    # Verbindungen an, die Company-DB existiert aber noch nicht. Deshalb wird
    # eine echte Verbindung zur Ziel-Datenbank getestet.
    echo "Demo-Modus: Warte auf Datenbank '${COMPANY_DB}' (max. ${DB_WAIT_SECONDS}s)..."
    db_ready=false
    waited=0
    while [ "$waited" -lt "$DB_WAIT_SECONDS" ]; do
        if psql -h db -p 5432 -U "${POSTGRES_USER}" -d "$COMPANY_DB" \
                -tAc 'SELECT 1' >/dev/null 2>&1; then
            db_ready=true
            break
        fi
        sleep 2
        waited=$(( waited + 2 ))
    done

    if [ "$db_ready" != true ]; then
        echo "WARNUNG: '${COMPANY_DB}' war nach ${DB_WAIT_SECONDS}s nicht erreichbar."
        echo "         Container startet ohne frischen Snapshot weiter."
    else
        echo "Datenbank erreichbar nach ${waited}s. Erstelle Datenbank-Snapshot..."

        # 3b. pg_dump mit Wiederholung. Der Dump landet zuerst in einer
        # temporären Datei, damit ein Fehlversuch einen bereits vorhandenen
        # Snapshot nicht überschreibt.
        snapshot_ok=false
        attempt=1
        while [ "$attempt" -le "$DUMP_RETRIES" ]; do
            if pg_dump -h db -p 5432 -U "${POSTGRES_USER}" \
                    --clean --if-exists "$COMPANY_DB" \
                    > "${SNAPSHOT_FILE}.tmp" 2>/tmp/pg_dump.err; then
                mv "${SNAPSHOT_FILE}.tmp" "$SNAPSHOT_FILE"
                snapshot_ok=true
                break
            fi
            echo "pg_dump Versuch ${attempt}/${DUMP_RETRIES} fehlgeschlagen: $(tr '\n' ' ' < /tmp/pg_dump.err)"
            rm -f "${SNAPSHOT_FILE}.tmp"
            attempt=$(( attempt + 1 ))
            if [ "$attempt" -le "$DUMP_RETRIES" ]; then sleep 3; fi
        done

        if [ "$snapshot_ok" = true ]; then
            chown www-data:www-data "$SNAPSHOT_FILE"
            echo "Demo-Snapshot erstellt: $SNAPSHOT_FILE ($(du -h "$SNAPSHOT_FILE" | cut -f1))"
        else
            echo "WARNUNG: Snapshot nach ${DUMP_RETRIES} Versuchen fehlgeschlagen."
        fi
    fi

    if [ ! -f "$SNAPSHOT_FILE" ]; then
        echo "WARNUNG: Kein Demo-Snapshot vorhanden — der Demo-Reset bleibt bis zum"
        echo "         nächsten erfolgreichen Containerstart deaktiviert."
    fi

    unset PGPASSWORD
fi

# ── 4. PHP-FPM starten (Daemon-Modus) ──
echo "Starte PHP-FPM..."
php-fpm -D

# ── 5. SSL-Zertifikate bereitstellen ──
export DOMAIN="${DOMAIN:-localhost}"
CERT_SRC="/etc/letsencrypt/live/${DOMAIN}"
CERT_DST="/etc/ssl/oserp"
mkdir -p "$CERT_DST"

# SSL-Automatik: Zertifikat holen wenn DOMAIN und CERTBOT_EMAIL konfiguriert sind
SSL_AUTO=false
if [ -n "$DOMAIN" ] && [ "$DOMAIN" != "erp.example.com" ] && [ "$DOMAIN" != "localhost" ] \
   && [ -n "$CERTBOT_EMAIL" ] && [ "$CERTBOT_EMAIL" != "admin@example.com" ]; then
    SSL_AUTO=true
fi

if [ "$SSL_AUTO" = true ] && [ ! -f "${CERT_SRC}/fullchain.pem" ]; then
    echo "SSL-Automatik: Hole Zertifikat fuer ${DOMAIN}..."

    # Apache kurz im Hintergrund starten fuer ACME-Challenge
    . /etc/apache2/envvars
    mkdir -p "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR" "$APACHE_LOG_DIR"
    apache2 -k start
    sleep 2

    # Certbot ausfuehren
    if certbot certonly \
        --webroot \
        -w /var/www/certbot \
        -d "$DOMAIN" \
        --email "$CERTBOT_EMAIL" \
        --agree-tos \
        --no-eff-email \
        --non-interactive; then
        echo "SSL-Zertifikat erfolgreich geholt fuer ${DOMAIN}"
    else
        echo "WARNUNG: Certbot fehlgeschlagen — App laeuft ueber HTTP."
        echo "Moeglicherweise ist die Domain nicht erreichbar oder Port 80 blockiert."
    fi

    # Apache stoppen (wird unten im Vordergrund neu gestartet)
    apache2 -k stop
    sleep 1
fi

if [ -f "${CERT_SRC}/fullchain.pem" ]; then
    echo "SSL-Zertifikat gefunden fuer ${DOMAIN}"
    cp "${CERT_SRC}/fullchain.pem" "${CERT_DST}/fullchain.pem"
    cp "${CERT_SRC}/privkey.pem" "${CERT_DST}/privkey.pem"
    a2ensite oserp-ssl 2>/dev/null || true
    echo "session.cookie_secure = 1" > /usr/local/etc/php/conf.d/ssl-cookie.ini

    # Renewal-Cronjob einrichten (2x taeglich, wie von Let's Encrypt empfohlen)
    if [ "$SSL_AUTO" = true ]; then
        echo "0 */12 * * * certbot renew --webroot -w /var/www/certbot --quiet --deploy-hook 'cp ${CERT_SRC}/fullchain.pem ${CERT_DST}/fullchain.pem && cp ${CERT_SRC}/privkey.pem ${CERT_DST}/privkey.pem && apache2 -k graceful'" \
            | crontab -
        cron
        echo "SSL-Renewal-Cronjob eingerichtet (alle 12h)."
    fi
else
    echo "Kein SSL-Zertifikat fuer ${DOMAIN} — App laeuft ueber HTTP."
    if [ "$SSL_AUTO" = false ] && [ "$DOMAIN" != "localhost" ]; then
        echo "SSL einrichten: DOMAIN und CERTBOT_EMAIL in .env konfigurieren und Container neu starten."
    fi
    a2dissite oserp-ssl 2>/dev/null || true
    echo "session.cookie_secure = 0" > /usr/local/etc/php/conf.d/ssl-cookie.ini
fi

# ── 6. SSE-Server starten (Hintergrund, mit Auto-Restart) ──
# Stirbt der Node-Prozess (z. B. DB-Abbruch), wuerde Apache sonst dauerhaft 503
# unter /sse/ liefern. Die Schleife startet ihn automatisch neu.
echo "Starte SSE-Server..."
(
    while true; do
        node /var/www/html/backend/sse/sse-server.js
        echo "SSE-Server beendet (Exit $?) — Neustart in 2s..."
        sleep 2
    done
) &

echo "=== OpensourceERP: Starte Apache ==="

# Apache im Vordergrund starten
# apache2-foreground existiert NICHT im php:fpm Image!
. /etc/apache2/envvars
mkdir -p "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR" "$APACHE_LOG_DIR"
exec apache2 -D FOREGROUND
