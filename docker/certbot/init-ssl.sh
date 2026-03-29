#!/bin/bash
set -e

# ============================================
# HINWEIS: Dieses Script ist nicht mehr noetig!
# SSL wird automatisch eingerichtet wenn DOMAIN und CERTBOT_EMAIL
# in .env konfiguriert sind (nicht auf example.com).
# Einfach: docker compose up -d
# Dieses Script bleibt als Fallback fuer manuelle Einrichtung.
# ============================================

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
    echo "FEHLER: .env nicht gefunden. Bitte erst 'cp .env.example .env' ausfuehren."
    exit 1
fi
source .env

if [ -z "$DOMAIN" ] || [ "$DOMAIN" = "erp.example.com" ]; then
    echo "FEHLER: DOMAIN in .env nicht gesetzt oder noch auf Standardwert."
    exit 1
fi

if [ -z "$CERTBOT_EMAIL" ] || [ "$CERTBOT_EMAIL" = "admin@example.com" ]; then
    echo "FEHLER: CERTBOT_EMAIL in .env nicht gesetzt oder noch auf Standardwert."
    exit 1
fi

echo "=== SSL-Zertifikat Setup ==="
echo "Domain: $DOMAIN"
echo "Email:  $CERTBOT_EMAIL"
echo ""

docker compose run --rm certbot certonly \
    --webroot \
    -w /var/www/certbot \
    -d "$DOMAIN" \
    --email "$CERTBOT_EMAIL" \
    --agree-tos \
    --no-eff-email \
    --non-interactive

echo ""
echo "=== SSL-Zertifikat erstellt ==="
echo "Web-Container neu starten:  docker compose restart web"
echo "Certbot-Daemon starten:     docker compose --profile ssl up -d certbot"
