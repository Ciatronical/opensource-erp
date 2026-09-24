#!/usr/bin/env bash
# =============================================================================
#  OSERP DynDNS-Updater — eigenständige Installation ohne OSERP-Stack
# =============================================================================
#  Legt auf einem beliebigen Debian/Ubuntu-Rechner an:
#    /etc/oserp/dyndns.conf                 Zugang (0600, root)
#    /usr/local/sbin/oserp-dyndns-update    Updater (Stand lack, curl + Fallback-Quellen)
#    /etc/systemd/system/oserp-dyndns.service + .timer   alle 5 Minuten
#
#  Bewusst OHNE ddclient (Ubuntu-24.04-Version ermittelt keine Adresse, siehe
#  install/install.sh Schritt "dyndns"). Idempotent: mehrfach ausführbar.
#
#  Aufruf (fragt User/Passwort ab, wenn nicht per Umgebung gesetzt):
#    sudo ./dyndns-standalone.sh
#    sudo DYNDNS_HOST=anderer.spdns.de DYNDNS_USER=... DYNDNS_PASS=... ./dyndns-standalone.sh
# =============================================================================
set -Eeuo pipefail

DYNDNS_HOST="${DYNDNS_HOST:-isolierglas.spdns.de}"
DYNDNS_USER="${DYNDNS_USER:-}"
DYNDNS_PASS="${DYNDNS_PASS:-}"
DYNDNS_UPDATE_URL="${DYNDNS_UPDATE_URL:-https://update.spdyn.de/nic/update}"
DYNDNS_CHECKIP_URL="${DYNDNS_CHECKIP_URL:-https://checkip4.spdyn.de/}"
DYNDNS_CHECKIP_FALLBACK_URLS="${DYNDNS_CHECKIP_FALLBACK_URLS:-https://ipv4.icanhazip.com https://api.ipify.org}"

CONF=/etc/oserp/dyndns.conf
UPDATER=/usr/local/sbin/oserp-dyndns-update

info() { echo "[INFO]  $*"; }
ok()   { echo "[OK]    $*"; }
err()  { echo "[FEHLER] $*" >&2; }

# Selbst per sudo hochstufen, Umgebungsvariablen mitnehmen
if [[ "$(id -u)" -ne 0 ]]; then
    exec sudo DYNDNS_HOST="$DYNDNS_HOST" DYNDNS_USER="$DYNDNS_USER" DYNDNS_PASS="$DYNDNS_PASS" \
         DYNDNS_UPDATE_URL="$DYNDNS_UPDATE_URL" DYNDNS_CHECKIP_URL="$DYNDNS_CHECKIP_URL" \
         DYNDNS_CHECKIP_FALLBACK_URLS="$DYNDNS_CHECKIP_FALLBACK_URLS" \
         bash "$0" "$@"
fi

command -v systemctl >/dev/null || { err "systemd wird benötigt."; exit 1; }
if ! command -v curl >/dev/null; then
    info "curl fehlt, wird installiert"
    apt-get update -y && apt-get install -y curl
fi

# --------------------------------------------------------------------------
#  Zugangsdaten
# --------------------------------------------------------------------------
# Vorhandene Config nur übernehmen, wenn sie vollständig ist (keine Platzhalter
# aus install.sh) und zum gewünschten Host passt; sonst neu schreiben.
if [[ -f $CONF && -z $DYNDNS_USER && -z $DYNDNS_PASS ]] \
   && ! grep -q 'BITTE-EINTRAGEN' "$CONF" \
   && grep -qx "DYNDNS_HOST=$DYNDNS_HOST" "$CONF"; then
    info "Vorhandene Config $CONF wird beibehalten"
else
    if [[ -f $CONF ]]; then
        info "Vorhandene Config passt nicht (Platzhalter, anderer Host oder neue Zugangsdaten) und wird ersetzt"
    fi
    # spdyn kennt zwei Anmeldearten: Host-Token (Benutzer = Hostname selbst)
    # oder Account-Login (Benutzer = Mailadresse, Passwort = Portal-Passwort).
    # Mailadresse + Token ergibt immer "badauth".
    if [[ -z $DYNDNS_USER ]]; then
        read -r -p "spdyn-Benutzer [Enter = $DYNDNS_HOST für Host-Token]: " DYNDNS_USER
        DYNDNS_USER="${DYNDNS_USER:-$DYNDNS_HOST}"
    fi
    if [[ -z $DYNDNS_PASS ]]; then
        read -r -s -p "Host-Token bzw. Portal-Passwort: " DYNDNS_PASS; echo
    fi
    [[ -n $DYNDNS_USER && -n $DYNDNS_PASS ]] || { err "Benutzer und Passwort sind Pflicht."; exit 1; }

    install -d -m 755 /etc/oserp
    [[ -f $CONF ]] && cp -p "$CONF" "$CONF.bak-$(date +%Y%m%d%H%M%S)"
    umask 077
    cat > "$CONF" <<CONFEOF
# DynDNS-Zugang — wird von $UPDATER gelesen.
DYNDNS_HOST=$DYNDNS_HOST
DYNDNS_USER=$DYNDNS_USER
DYNDNS_PASS=$DYNDNS_PASS
DYNDNS_UPDATE_URL=$DYNDNS_UPDATE_URL
DYNDNS_CHECKIP_URL=$DYNDNS_CHECKIP_URL

# Fallback-Quellen, falls checkip4.spdyn.de einen Node mit abgelaufenem
# Zertifikat erwischt (siehe Kommentar in oserp-dyndns-update).
DYNDNS_CHECKIP_FALLBACK_URLS="$DYNDNS_CHECKIP_FALLBACK_URLS"
CONFEOF
    umask 022
    chmod 600 "$CONF"
    ok "Config geschrieben: $CONF"
fi

install -d -m 755 /var/lib/oserp

# --------------------------------------------------------------------------
#  Updater
# --------------------------------------------------------------------------
cat > "$UPDATER" <<'SCRIPT'
#!/usr/bin/env bash
# Aktualisiert den DynDNS-A-Record auf die aktuelle öffentliche IPv4.
# Sendet nur bei tatsächlicher Änderung — unnötige Updates wertet der
# Anbieter als Missbrauch. Mit --force wird immer gesendet.
set -euo pipefail
CONF=/etc/oserp/dyndns.conf
STATE=/var/lib/oserp/dyndns.ip
[[ -r $CONF ]] || { logger -t oserp-dyndns "Config $CONF fehlt"; exit 1; }
# shellcheck disable=SC1090
source "$CONF"

# checkip4.spdyn.de ist ein DNS-Round-Robin über mehrere Nodes, von denen
# einzelne zeitweise abgelaufene TLS-Zertifikate ausliefern (beobachtet am
# 12.08.2026: 176.9.34.48, Zertifikat abgelaufen am 02.08.2026). Ein einzelner
# curl-Aufruf scheitert dort mit Exit 60, wodurch früher der ganze Timer-Lauf
# fehlschlug. Darum mehrere Versuche pro Quelle, danach die Fallback-Quellen
# aus der Config.
ermittle_ipv4() {
    local url versuch roh
    for url in "$DYNDNS_CHECKIP_URL" ${DYNDNS_CHECKIP_FALLBACK_URLS:-}; do
        for versuch in 1 2 3; do
            roh="$(curl -4 -fsS --max-time 10 "$url" 2>/dev/null | tr -dc '0-9.')" || roh=''
            if [[ $roh =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
                printf '%s' "$roh"
                return 0
            fi
            if [[ $versuch -lt 3 ]]; then
                sleep 2
            fi
        done
        logger -t oserp-dyndns "checkip-Quelle $url nach 3 Versuchen ohne gültige IPv4"
    done
    return 1
}

if ! ip="$(ermittle_ipv4)"; then
    logger -t oserp-dyndns "Keine gültige IPv4 ermittelt (alle Quellen erschöpft)"
    exit 1
fi

last="$(cat "$STATE" 2>/dev/null || true)"
if [[ "$ip" == "$last" && ${1:-} != --force ]]; then
    exit 0
fi

# --retry ohne --retry-all-errors wiederholt nur transiente Fehler (Timeout,
# 5xx) — ein "badauth"/"abuse" (4xx) wird bewusst nicht wiederholt.
resp="$(curl -fsS --max-time 20 --retry 2 --retry-delay 3 \
        -u "$DYNDNS_USER:$DYNDNS_PASS" \
        "$DYNDNS_UPDATE_URL?hostname=$DYNDNS_HOST&myip=$ip" || echo 'curl-fehler')"
case "$resp" in
    good*|nochg*)
        printf '%s' "$ip" > "$STATE"
        logger -t oserp-dyndns "$DYNDNS_HOST -> $ip ($(echo "$resp" | head -1))"
        ;;
    *)
        logger -t oserp-dyndns "FEHLER beim Update von $DYNDNS_HOST: $(echo "$resp" | head -1)"
        exit 1
        ;;
esac
SCRIPT
chmod 750 "$UPDATER"
ok "Updater geschrieben: $UPDATER"

# --------------------------------------------------------------------------
#  systemd
# --------------------------------------------------------------------------
cat > /etc/systemd/system/oserp-dyndns.service <<'UNIT'
[Unit]
Description=OSERP DynDNS-Update
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/oserp-dyndns-update
UNIT

cat > /etc/systemd/system/oserp-dyndns.timer <<'UNIT'
[Unit]
Description=OSERP DynDNS-Update alle 5 Minuten

[Timer]
OnBootSec=1min
OnUnitActiveSec=5min
Unit=oserp-dyndns.service

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now oserp-dyndns.timer
ok "Timer aktiviert"

# --------------------------------------------------------------------------
#  Erstes Update + Kontrolle
# --------------------------------------------------------------------------
if "$UPDATER" --force; then
    ok "DynDNS aktiv: $(grep -oP '(?<=^DYNDNS_HOST=).*' "$CONF") -> $(cat /var/lib/oserp/dyndns.ip)"
else
    err "Erstes Update fehlgeschlagen. Log: journalctl -t oserp-dyndns -n 5"
    err "401 = badauth: Benutzer/Passwort prüfen, Neustart mit DYNDNS_USER=... DYNDNS_PASS=... $0"
    exit 1
fi
journalctl -t oserp-dyndns -n 3 --no-pager || true
