#!/usr/bin/env bash
#
# Hella Gutmann mega macs X in LxCars einbinden (docs/features/gutmann.md).
#
# Trägt die Weiterleitung /gutmann/ → Gerät in den HTTPS-vHost ein, schaltet
# die nötigen Apache-Module frei und lädt Apache neu. Einmal mit sudo
# ausführen, bei neuer Geräteadresse einfach erneut:
#
#   sudo ./install/gutmann-proxy.sh 192.168.178.217:8889
#
# Argument 1: Adresse:Port des mega macs (Pflicht)
#
# Idempotent: vorhandene Einträge werden ersetzt, nichts anderes im vHost
# angefasst.
set -euo pipefail

DEVICE="${1:-}"
if [[ ! "$DEVICE" =~ ^[0-9A-Za-z.-]+:[0-9]+$ ]]; then
    echo "Aufruf: sudo $0 <adresse:port>" >&2
    echo "   z. B. sudo $0 192.168.178.217:8889" >&2
    exit 1
fi
if [[ $EUID -ne 0 ]]; then
    echo "Bitte mit sudo ausführen." >&2
    exit 1
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF_SRC="$HERE/oserp-gutmann.conf"
CONF_DST=/etc/apache2/oserp-gutmann.conf
# Eigene Define-Datei: oserp-defines.conf schreibt der Installer bei jedem
# Lauf neu, die Geräteadresse soll das überleben.
DEFINES=/etc/apache2/conf-available/oserp-gutmann-defines.conf
MARK_BEGIN="# >>> oserp-gutmann"
MARK_END="# <<< oserp-gutmann"

# vHost finden: der mit dem Let's-Encrypt-Zertifikat (Installer oder Handarbeit)
VHOST="$(grep -lE 'SSLCertificateFile\s+/etc/letsencrypt' /etc/apache2/sites-enabled/*.conf 2>/dev/null | head -1 || true)"
if [[ -z "$VHOST" ]]; then
    echo "Kein HTTPS-vHost mit Let's-Encrypt-Zertifikat unter /etc/apache2/sites-enabled gefunden." >&2
    exit 1
fi
VHOST="$(readlink -f "$VHOST")"

echo "Gerät:      $DEVICE"
echo "vHost:      $VHOST"

# 1. Module
a2enmod -q proxy proxy_http proxy_wstunnel headers substitute filter rewrite >/dev/null

# 2. Konfiguration ablegen
install -m 644 "$CONF_SRC" "$CONF_DST"

# 3. Defines: Adresse und Zugangsgeheimnis. Das Geheimnis bleibt bei einem
#    erneuten Lauf erhalten (sonst flögen alle Benutzer aus dem Gerät), es wird
#    nur beim ersten Mal erzeugt. PHP liest die Datei (backend/api/lxcars/
#    gutmann.php), darum lesbar für alle — sie enthält nur dieses Geheimnis.
TOKEN="$(grep -oE '^Define OSERP_GUTMANN_TOKEN [0-9a-f]+' "$DEFINES" 2>/dev/null | awk '{print $3}' || true)"
if [[ -z "$TOKEN" ]]; then
    TOKEN="$(openssl rand -hex 24)"
fi
printf '# Von install/gutmann-proxy.sh geschrieben — bei neuer Adresse das Skript erneut ausführen.\n# OSERP_GUTMANN_TOKEN: Zugangsgeheimnis für /gutmann/ (Cookie oserp_gutmann, gesetzt nach OSERP-Anmeldung).\nDefine OSERP_GUTMANN_DEVICE %s\nDefine OSERP_GUTMANN_TOKEN %s\n' "$DEVICE" "$TOKEN" > "$DEFINES"
chmod 644 "$DEFINES"
a2enconf -q oserp-gutmann-defines >/dev/null

# 4. Include in den vHost hängen (vor dem ersten ErrorLog, einmalig)
sed -i "/^$MARK_BEGIN/,/^$MARK_END/d" "$VHOST"
if ! grep -q "^\s*ErrorLog" "$VHOST"; then
    echo "Im vHost fehlt eine ErrorLog-Zeile als Ankerpunkt — bitte Include $CONF_DST von Hand eintragen." >&2
    exit 1
fi
awk -v inc="$CONF_DST" -v b="$MARK_BEGIN" -v e="$MARK_END" '
    !done && /^[[:space:]]*ErrorLog/ { print b; print "    Include " inc; print e; done=1 }
    { print }
' "$VHOST" > "$VHOST.tmp" && mv "$VHOST.tmp" "$VHOST"

# 5. Prüfen und neu laden
if ! apache2ctl -t 2>&1; then
    echo "Apache-Konfiguration fehlerhaft — Änderungen zurücknehmen:" >&2
    sed -i "/^$MARK_BEGIN/,/^$MARK_END/d" "$VHOST"
    exit 1
fi
systemctl reload apache2
echo "Fertig. Oberfläche: https://<server>/gutmann/ — von überall, aber nur nach OSERP-Anmeldung; in LxCars unter Fahrzeuge → Gutmann (Schalter in Firmenkonfiguration → Features)."
