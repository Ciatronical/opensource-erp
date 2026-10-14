#!/usr/bin/env bash
# =============================================================================
#  OSERP Borg-Backup — eigenständige Einrichtung (auch ohne OSERP-Stack)
# =============================================================================
#  Richtet ein verschlüsseltes, dedupliziertes Offsite-Backup des ganzen
#  Rechners (plus PostgreSQL-Dumps) auf einer Hetzner Storage Box (oder einem
#  beliebigen SSH-Borg-Server) ein — so wie auf den bestehenden OSERP-Rechnern.
#
#  Legt an:
#    /etc/oserp/borg.env                      Zugang + Passphrase (0600, root)
#    /usr/local/sbin/oserp-backup             Backup-Skript (Dumps, borg create, prune)
#    /usr/local/sbin/oserp-backup-status      Status als JSON (für borg-uebersicht)
#    /etc/sudoers.d/oserp-backup-status       Betriebs-User darf den Status abfragen
#    /etc/cron.d/oserp-backup                 nächtlicher Lauf als root
#    /root/.ssh/id_ed25519                    SSH-Key, wird auf dem Borg-Server installiert
#
#  ALLE Zugangsdaten werden ABGEFRAGT (oder per Umgebung übergeben) — in diesem
#  Skript steht bewusst kein Konto, keine Adresse und keine Passphrase, denn es
#  liegt im öffentlichen Repository.
#
#  Aufruf:
#    sudo ./install/borg-standalone.sh
#    sudo BORG_BACKUP_USER=u123456 BORG_REPOSITORY_DIR=firma BORG_PASSPHRASE=... \
#         ./install/borg-standalone.sh
#    sudo ./install/borg-standalone.sh --refresh    # nur Skripte/Cron neu schreiben
#    sudo ./install/borg-standalone.sh --first      # Erstsicherung direkt starten
#
#  Idempotent: vorhandene borg.env bleibt erhalten, Skripte werden neu
#  geschrieben, das Repository wird nur angelegt, wenn es fehlt.
# =============================================================================
set -Eeuo pipefail

CONF=/etc/oserp/borg.env
BACKUP_BIN=/usr/local/sbin/oserp-backup
STATUS_BIN=/usr/local/sbin/oserp-backup-status
# Pfad, den das bestehende borg-uebersicht auf dem Zentralrechner abfragt
STATUS_COMPAT=/usr/local/sbin/storage_backup/borg-status-local.sh
SUDOERS=/etc/sudoers.d/oserp-backup-status
CRON=/etc/cron.d/oserp-backup
LOG_DIR=/var/log/oserp-backup

info() { echo "[INFO]  $*"; }
ok()   { echo "[OK]    $*"; }
warn() { echo "[WARN]  $*"; }
err()  { echo "[FEHLER] $*" >&2; }

REFRESH=0; FIRST=0
for a in "$@"; do
    case "$a" in
        --refresh) REFRESH=1 ;;
        --first)   FIRST=1 ;;
        -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
        *) err "Unbekannte Option: $a"; exit 1 ;;
    esac
done

# Betriebs-User (darf später den Status abfragen): Aufrufer vor sudo, sonst Repo-Eigentümer
OSERP_USER="${OSERP_USER:-${SUDO_USER:-}}"
if [[ -z $OSERP_USER || $OSERP_USER == root ]]; then
    OSERP_USER="$(stat -c '%U' "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)" 2>/dev/null || true)"
    [[ $OSERP_USER == root ]] && OSERP_USER=""
fi

# Selbst per sudo hochstufen, Umgebungsvariablen mitnehmen
if [[ "$(id -u)" -ne 0 ]]; then
    exec sudo OSERP_USER="$OSERP_USER" \
         BORG_BACKUP_USER="${BORG_BACKUP_USER:-}" BORG_BACKUP_HOST="${BORG_BACKUP_HOST:-}" \
         BORG_BACKUP_PORT="${BORG_BACKUP_PORT:-}" BORG_REPOSITORY_DIR="${BORG_REPOSITORY_DIR:-}" \
         BORG_PASSPHRASE="${BORG_PASSPHRASE:-}" BORG_ALERT_EMAIL="${BORG_ALERT_EMAIL:-}" \
         BORG_CRON_TIME="${BORG_CRON_TIME:-}" \
         bash "$0" "$@"
fi

if ! command -v borg >/dev/null; then
    info "borgbackup fehlt, wird installiert"
    apt-get update -y && DEBIAN_FRONTEND=noninteractive apt-get install -y borgbackup
fi

# --------------------------------------------------------------------------
#  Zugangsdaten
# --------------------------------------------------------------------------
# Vorhandene Config (ohne Platzhalter) bleibt, solange keine neuen Werte per
# Umgebung kommen. Sonst wird abgefragt — interaktiv, nie aus dem Skript heraus.
have_conf() { [[ -f $CONF ]] && ! grep -q 'BITTE-EINTRAGEN' "$CONF"; }

if have_conf && [[ -z ${BORG_BACKUP_USER:-} && -z ${BORG_PASSPHRASE:-} ]]; then
    info "Vorhandene Config $CONF wird beibehalten"
else
    if [[ $REFRESH -eq 1 ]]; then
        err "--refresh braucht eine fertige Config ($CONF fehlt oder enthält Platzhalter)."
        exit 1
    fi
    [[ -f $CONF ]] && info "Vorhandene Config passt nicht (Platzhalter oder neue Zugangsdaten) und wird ersetzt"
    if [[ ! -t 0 && -z ${BORG_BACKUP_USER:-} ]]; then
        err "Kein Terminal und keine Zugangsdaten in der Umgebung — Abbruch."
        err "Interaktiv: sudo $0   oder   BORG_BACKUP_USER=... BORG_PASSPHRASE=... $0"
        exit 1
    fi
    # ask VAR "Frage" Vorgabe [secret]: nur fragen, wenn Wert fehlt; ohne Terminal gilt die Vorgabe
    ask() {
        local var=$1 prompt=$2 def=$3 secret=${4:-}
        [[ -z ${!var:-} ]] || return 0
        if [[ -t 0 ]]; then
            if [[ -n $secret ]]; then read -r -s -p "$prompt" "$var" || true; echo
            else read -r -p "$prompt" "$var" || true; fi
        fi
        [[ -n ${!var:-} ]] || printf -v "$var" '%s' "$def"
    }
    if [[ -t 0 ]]; then
        echo
        echo "Daten des Borg-Servers (Hetzner Storage Box oder anderer SSH-Host)."
        echo "Bei der Storage Box ist der Benutzer das Konto (u123456 bzw. Subaccount"
        echo "u123456-sub1), der Host <konto>.your-storagebox.de und der Port 23."
        echo
    fi
    ask BORG_BACKUP_USER "Benutzer / Storage-Box-Konto: " ""
    [[ -n $BORG_BACKUP_USER ]] || { err "Benutzer ist Pflicht."; exit 1; }
    ask BORG_BACKUP_HOST "Host [Enter = $BORG_BACKUP_USER.your-storagebox.de]: " "$BORG_BACKUP_USER.your-storagebox.de"
    ask BORG_BACKUP_PORT "SSH-Port [Enter = 23]: " 23
    ask BORG_REPOSITORY_DIR "Repository-Verzeichnis auf dem Server (unter backups/) [Enter = $(hostname)]: " "$(hostname)"
    if [[ -z ${BORG_PASSPHRASE:-} ]]; then
        ask BORG_PASSPHRASE "Borg-Passphrase [Enter = sicher erzeugen]: " "" secret
        if [[ -z $BORG_PASSPHRASE ]]; then
            BORG_PASSPHRASE="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)"
            warn "Passphrase erzeugt — sie steht in $CONF. OHNE sie ist kein Restore möglich, sicher ablegen!"
        else
            p2=""; ask p2 "Passphrase wiederholen: " "" secret
            [[ $BORG_PASSPHRASE == "$p2" ]] || { err "Passphrasen stimmen nicht überein."; exit 1; }
        fi
    fi
    ask BORG_ALERT_EMAIL "E-Mail für Fehlermeldungen [Enter = keine]: " ""
    ask BORG_CRON_TIME "Uhrzeit des nächtlichen Laufs (HH:MM, nach der DSL-Zwangstrennung) [Enter = 02:30]: " "02:30"
    [[ $BORG_CRON_TIME =~ ^([01]?[0-9]|2[0-3]):[0-5][0-9]$ ]] || { err "Ungültige Uhrzeit: $BORG_CRON_TIME"; exit 1; }

    OSERP_HOME="$( [[ -n $OSERP_USER ]] && getent passwd "$OSERP_USER" | cut -d: -f6 || true)"
    install -d -m 755 /etc/oserp
    [[ -f $CONF ]] && cp -p "$CONF" "$CONF.bak-$(date +%Y%m%d%H%M%S)"
    umask 077
    cat > "$CONF" <<CONFEOF
# Borg-Backup-Zugang — wird von $BACKUP_BIN gelesen. NICHT ins Repository!
# Angelegt von install/borg-standalone.sh am $(date +%F)

# Ziel: ssh://BORG_BACKUP_USER@BORG_BACKUP_HOST:BORG_BACKUP_PORT/./backups/BORG_REPOSITORY_DIR
BORG_BACKUP_USER="$BORG_BACKUP_USER"
BORG_BACKUP_HOST="$BORG_BACKUP_HOST"
BORG_BACKUP_PORT="$BORG_BACKUP_PORT"
BORG_REPOSITORY_DIR="$BORG_REPOSITORY_DIR"
# Optional: komplette Repo-URL statt der vier Werte oben (z. B. lokaler Pfad zum Testen)
BORG_REPO_URL=""

# Verschlüsselung repokey: Schlüssel liegt im Repo, geschützt durch diese Passphrase.
# Ohne Passphrase KEIN Restore — sicher aufbewahren.
BORG_PASSPHRASE="$BORG_PASSPHRASE"

# Mail bei echtem Fehler (Exit >= 2); leer = keine Mail
BORG_ALERT_EMAIL="$BORG_ALERT_EMAIL"

# Nächtlicher Lauf (HH:MM) — Cron wird daraus erzeugt (borg-standalone.sh --refresh)
BORG_CRON_TIME="$BORG_CRON_TIME"

# Aufbewahrung
BORG_KEEP_DAILY=7
BORG_KEEP_WEEKLY=4
BORG_KEEP_MONTHLY=6

# Was gesichert wird (Bash-Arrays). Die Datenbanken kommen als Dumps unter
# /var/backups/oserp dazu, /var/lib/postgresql selbst ist darum ausgeschlossen.
BORG_SOURCES=( "/" )
BORG_EXCLUDES=(
  "/dev" "/proc" "/sys" "/run" "/tmp" "/var/tmp" "/var/run" "/var/cache" "/var/log"
  "/lost+found" "/mnt" "/media" "/snap" "/swapfile" "/swap.img"
  "/var/lib/postgresql" "/var/lib/lxcfs" "/var/lib/snapd" "/var/lib/flatpak"
  "/root/.cache" "/home/*/.cache" "/home/*/.thunderbird" "/home/*/.mozilla"
  "/home/*/snap" "/home/*/.local/share/Trash" "/home/*/.npm" "/home/*/.ollama"
  "/usr/share/ollama" "/home/*/opensource-erp/node_modules" "/home/*/opensource-erp/dist"
)
# Datenbanken, die NICHT gedumpt werden (Muster für grep -E)
BORG_PG_SKIP_REGEX='^(postgres|.*_new)\$'

# Optional: Test-Datenbank jede Nacht aus der Produktiv-DB neu aufbauen
# (atomarer Tausch, Telegram-Zugänge werden entschärft). Leer = aus.
BORG_PG_CLONE_SRC=""
BORG_PG_CLONE_DST=""
BORG_OSERP_USER="$OSERP_USER"
BORG_OSERP_DIR="${OSERP_HOME:+$OSERP_HOME/opensource-erp}"
CONFEOF
    umask 022
    chmod 600 "$CONF"
    ok "Config geschrieben: $CONF"
fi

# shellcheck disable=SC1090
source "$CONF"
REPO="${BORG_REPO_URL:-ssh://${BORG_BACKUP_USER}@${BORG_BACKUP_HOST}:${BORG_BACKUP_PORT}/./backups/${BORG_REPOSITORY_DIR}}"
BORG_CRON_TIME="${BORG_CRON_TIME:-02:30}"
install -d -m 750 "$LOG_DIR"

# --------------------------------------------------------------------------
#  Backup-Skript
# --------------------------------------------------------------------------
cat > "$BACKUP_BIN" <<'SCRIPT'
#!/usr/bin/env bash
# OSERP-Backup: PostgreSQL-Dumps + borg create (verschlüsselt, dedupliziert)
# auf den in /etc/oserp/borg.env hinterlegten Borg-Server, danach prune/compact.
#
#   oserp-backup            normaler Lauf (Repo wird angelegt, falls es fehlt)
#   oserp-backup -v         borg mit --list --filter=AME
#   oserp-backup --first    Erstsicherung mit Auto-Resume: bricht die Verbindung
#                           ab (DSL-Zwangstrennung), wird gewartet und ab dem
#                           letzten Checkpoint fortgesetzt, bis das Archiv steht
#   oserp-backup <suffix>   Suffix an den Archivnamen hängen
#   oserp-backup --list     Archive auflisten
#   oserp-backup --restore ARCHIV [pfad ...]   ins aktuelle Verzeichnis entpacken
#
# Borg-Exit-Codes: 0 = OK, 1 = Warnung (Archiv gültig), >= 2 = Fehler (Mail).
set -uo pipefail
CONF="${OSERP_BORG_CONF:-/etc/oserp/borg.env}"
LOG_DIR="${OSERP_BORG_LOG_DIR:-/var/log/oserp-backup}"
LOG=$LOG_DIR/backup.log
DUMP_DIR="${OSERP_BORG_DUMP_DIR:-/var/backups/oserp/postgresql}"
[[ -r $CONF ]] || { echo "Config $CONF fehlt" >&2; exit 2; }
# shellcheck disable=SC1090
source "$CONF"
export BORG_PASSPHRASE
# Kurze Keepalive-Deadline mit Absicht: Nach der DSL-Zwangstrennung ist die
# Verbindung tot; borg soll das in Minuten merken, den Lock freigeben und ein
# Resume zulassen — statt stundenlang zu hängen.
export BORG_RSH="ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=6 -o ConnectTimeout=30 -o BatchMode=yes"
REPO="${BORG_REPO_URL:-ssh://${BORG_BACKUP_USER}@${BORG_BACKUP_HOST}:${BORG_BACKUP_PORT}/./backups/${BORG_REPOSITORY_DIR}}"

VERBOSE=0; FIRST=0; SUFFIX=""
case "${1:-}" in
    --list)    exec borg list "$REPO" ;;
    --restore) shift; a="${1:?Archivname fehlt}"; shift; exec borg extract --list "$REPO::$a" "$@" ;;
    -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
esac
for a in "$@"; do
    case "$a" in
        -v) VERBOSE=1 ;;
        --first) FIRST=1 ;;
        *) SUFFIX="_$a" ;;
    esac
done

mkdir -p "$LOG_DIR" "$(dirname "$DUMP_DIR")"
exec > >(tee -a "$LOG") 2>&1
say() { echo "$(date '+%F %T') $*"; }

# Als root über den DB-Superuser arbeiten, sonst (Testlauf) als aktueller Benutzer
pg() { if [[ $(id -u) -eq 0 ]]; then sudo -u postgres "$@"; else "$@"; fi; }
reachable() {
    [[ $REPO == ssh://* ]] || return 0
    timeout 20 bash -c "exec 3<>/dev/tcp/${BORG_BACKUP_HOST}/${BORG_BACKUP_PORT}" 2>/dev/null
}

# ---- Datenbank-Dumps ------------------------------------------------------
dump_postgresql() {
    command -v psql >/dev/null || { say "PostgreSQL nicht installiert — keine Dumps"; return 0; }
    install -d -m 700 "$DUMP_DIR"
    say "PostgreSQL-Dumps nach $DUMP_DIR"
    pg pg_dumpall --globals-only > "$DUMP_DIR/_globals.sql" 2>>"$LOG" \
        || say "WARN: pg_dumpall --globals-only fehlgeschlagen"
    local db
    for db in $(pg psql -tAc "SELECT datname FROM pg_database WHERE NOT datistemplate" \
                | grep -Ev "${BORG_PG_SKIP_REGEX:-^$}"); do
        # Klartext (nicht gz): borg dedupliziert unkomprimierte Dumps deutlich besser.
        if pg pg_dump "$db" > "$DUMP_DIR/$db.sql.tmp" 2>>"$LOG"; then
            mv -f "$DUMP_DIR/$db.sql.tmp" "$DUMP_DIR/$db.sql"
            say "  $db ($(du -h "$DUMP_DIR/$db.sql" | cut -f1))"
        else
            rm -f "$DUMP_DIR/$db.sql.tmp"
            say "  WARN: Dump von $db fehlgeschlagen"
        fi
    done
    # Dumps verschwundener Datenbanken aufräumen
    local f
    for f in "$DUMP_DIR"/*.sql; do
        [[ -e $f ]] || continue
        db="$(basename "$f" .sql)"; [[ $db == _globals ]] && continue
        pg psql -tAc "SELECT 1 FROM pg_database WHERE datname='$db'" | grep -q 1 \
            || { rm -f "$f"; say "  alter Dump entfernt: $db"; }
    done
}

# ---- Optional: Test-DB aus Produktiv-DB (atomarer Tausch) -----------------
clone_test_database() {
    local SRC="${BORG_PG_CLONE_SRC:-}" DST="${BORG_PG_CLONE_DST:-}"
    [[ -n $SRC && -n $DST ]] || return 0
    [[ $SRC != "$DST" ]] || { say "FEHLER: Klon-Quelle und -Ziel identisch"; return 0; }
    local DUMP="$DUMP_DIR/$SRC.sql" NEW="${DST}_new"
    [[ -s $DUMP ]] || { say "WARN: Dump $DUMP fehlt — Klon übersprungen"; return 0; }
    say "Test-Datenbank '$DST' aus '$SRC' neu aufbauen"
    local ENC COLLATE CTYPE
    ENC=$(pg psql -tAc "SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname='$SRC'")
    COLLATE=$(pg psql -tAc "SELECT datcollate FROM pg_database WHERE datname='$SRC'")
    CTYPE=$(pg psql -tAc "SELECT datctype FROM pg_database WHERE datname='$SRC'")
    [[ -n $ENC ]] || { say "WARN: Quelle '$SRC' existiert nicht — Klon übersprungen"; return 0; }
    # Der Dump muss für postgres lesbar sein: Kopie in ein postgres-eigenes Verzeichnis
    local TMP=/var/tmp/oserp_clone
    install -d -m 700 -o postgres -g postgres "$TMP"
    install -m 600 -o postgres -g postgres "$DUMP" "$TMP/src.sql"
    pg dropdb --if-exists "$NEW" >/dev/null 2>&1
    pg psql -d postgres -qc "CREATE DATABASE \"$NEW\" TEMPLATE template0 ENCODING '$ENC' LC_COLLATE '$COLLATE' LC_CTYPE '$CTYPE'" \
        || { say "FEHLER: CREATE DATABASE $NEW"; return 0; }
    if ! pg psql -q -d "$NEW" --set ON_ERROR_STOP=on -f "$TMP/src.sql" >/dev/null 2>>"$LOG"; then
        say "FEHLER: Import nach $NEW fehlgeschlagen — '$DST' bleibt unverändert"
        pg dropdb --if-exists "$NEW" >/dev/null 2>&1; rm -f "$TMP/src.sql"; return 0
    fi
    rm -f "$TMP/src.sql"
    # Telegram-Zugänge entschärfen, solange die DB noch unter dem Zwischennamen läuft
    if [[ -n ${BORG_OSERP_USER:-} && -f ${BORG_OSERP_DIR:-/nonexistent}/backend/cli/sanitize-testdb.php ]]; then
        if ! sudo -u "$BORG_OSERP_USER" -H bash -c "cd '$BORG_OSERP_DIR' && php backend/cli/sanitize-testdb.php '$NEW'" >/dev/null 2>>"$LOG"; then
            say "FEHLER: $NEW konnte nicht entschärft werden — Klon verworfen"
            pg dropdb --if-exists "$NEW" >/dev/null 2>&1; return 0
        fi
    fi
    # Logo der bisherigen Test-DB übernehmen (TEST-Markierung bleibt erhalten)
    if pg psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DST'" | grep -q 1; then
        rm -f "$TMP/logo"
        pg psql -d "$DST" -qc "\\copy (SELECT value FROM defaults_oserp WHERE key='company_logo') TO '$TMP/logo'" >/dev/null 2>&1 || true
        if [[ -s $TMP/logo ]]; then
            pg psql -q -d "$NEW" -c "CREATE TEMP TABLE _l(v text)" -c "\\copy _l FROM '$TMP/logo'" \
                -c "UPDATE defaults_oserp SET value=(SELECT v FROM _l LIMIT 1), mtime=now() WHERE key='company_logo'" >/dev/null 2>&1 \
                || say "WARN: Logo nicht übernommen"
        fi
        rm -f "$TMP/logo"
        pg psql -d postgres -qc "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='$DST' AND pid<>pg_backend_pid()" >/dev/null
        pg dropdb --if-exists "$DST"
    fi
    pg psql -d postgres -qc "ALTER DATABASE \"$NEW\" RENAME TO \"$DST\"" \
        || { say "FEHLER: Umbenennen $NEW -> $DST"; return 0; }
    # Belegdateien liegen je DB-Name getrennt
    local DATA="${BORG_OSERP_DIR:-/nonexistent}/backend/data"
    if [[ -d $DATA/$SRC ]]; then
        mkdir -p "$DATA/$DST"
        rsync -a --delete "$DATA/$SRC/" "$DATA/$DST/" || say "WARN: rsync der Belege fehlgeschlagen"
        chown -R "${BORG_OSERP_USER:-root}:www-data" "$DATA/$DST" 2>/dev/null || true
    fi
    say "Test-Datenbank '$DST' ist jetzt ein Klon von '$SRC'"
}

# ---- Ein borg-create-Lauf --------------------------------------------------
run_borg() {
    local ts; ts="$(date +'%Y-%m-%d_%H%M%S')"
    local opts=(--compression lz4 --lock-wait "${BORG_LOCK_WAIT:-300}" --checkpoint-interval 600 --stats)
    [[ $VERBOSE -eq 1 ]] && opts+=(--list --filter=AME)
    local ex=("${BORG_EXCLUDES[@]/#/--exclude=}")
    # Dumps und Config immer mitnehmen, auch wenn nicht "/" gesichert wird
    local src=("${BORG_SOURCES[@]}")
    [[ " ${src[*]} " == *" / "* ]] || src+=("$DUMP_DIR" "$CONF")
    # Veraltete Locks abgebrochener Läufe entfernen — nur wenn kein borg läuft
    if ! pgrep -x borg >/dev/null; then
        borg break-lock "$REPO" >/dev/null 2>&1 || true
    fi
    say "borg create $REPO::$(hostname)-${ts}${SUFFIX}"
    borg create "${opts[@]}" "${ex[@]}" "$REPO::$(hostname)-${ts}${SUFFIX}" "${src[@]}"
}

# ---- Hauptablauf ------------------------------------------------------------
say "###### Backup gestartet ######"
dpkg --get-selections > "$(dirname "$DUMP_DIR")/software.list" 2>/dev/null || true

if ! borg info "$REPO" >/dev/null 2>&1; then
    say "Repository nicht gefunden — wird angelegt: $REPO"
    if [[ $REPO == ssh://* ]]; then
        ssh -p "$BORG_BACKUP_PORT" -o BatchMode=yes -o ConnectTimeout=30 "${BORG_BACKUP_USER}@${BORG_BACKUP_HOST}" \
            "mkdir -p backups/${BORG_REPOSITORY_DIR}" 2>>"$LOG" || true
    fi
    borg init --encryption=repokey "$REPO" || { say "###### Backup FEHLGESCHLAGEN (borg init) ######"; exit 2; }
fi

dump_postgresql
clone_test_database

RC=0
if [[ $FIRST -eq 1 ]]; then
    # Erstsicherung: so lange wiederholen, bis ein Archiv steht. Jeder Versuch
    # setzt am letzten Checkpoint (alle 10 min) an — Hochgeladenes geht nicht verloren.
    n=0
    while :; do
        n=$((n+1)); say "Erstsicherung, Versuch $n"
        run_borg; RC=$?
        [[ $RC -le 1 ]] && break
        [[ $n -ge 48 ]] && { say "FEHLER: Erstsicherung nach $n Versuchen aufgegeben"; break; }
        say "Abbruch (Exit $RC) — warte auf den Borg-Server"
        sleep 60
        until reachable; do say "Server nicht erreichbar (Zwangstrennung?) — warte 60 s"; sleep 60; done
    done
else
    run_borg; RC=$?
fi

if [[ $RC -ge 2 ]]; then
    say "###### Backup FEHLGESCHLAGEN (Exit $RC) ######"
    if [[ -n ${BORG_ALERT_EMAIL:-} ]] && command -v mail >/dev/null; then
        mail -s "[Backup-Fehler] $(hostname): Borg-Backup fehlgeschlagen" "$BORG_ALERT_EMAIL" <<MAIL
Host: $(hostname)   Zeit: $(date)   Exit: $RC
Repository: $REPO

$(tail -n 40 "$LOG")
MAIL
    fi
    exit "$RC"
fi
[[ $RC -eq 1 ]] && say "borg-Warnung (Exit 1): Archiv ist gültig, einzelne Dateien änderten sich während der Sicherung"

say "Prune (täglich ${BORG_KEEP_DAILY:-7} / wöchentlich ${BORG_KEEP_WEEKLY:-4} / monatlich ${BORG_KEEP_MONTHLY:-6})"
borg prune --list --glob-archives "$(hostname)-*" \
    --keep-daily="${BORG_KEEP_DAILY:-7}" --keep-weekly="${BORG_KEEP_WEEKLY:-4}" --keep-monthly="${BORG_KEEP_MONTHLY:-6}" \
    "$REPO" || say "WARN: borg prune fehlgeschlagen"
borg compact "$REPO" || say "WARN: borg compact fehlgeschlagen"
say "###### Backup beendet – Status: OK ######"
SCRIPT
chmod 700 "$BACKUP_BIN"
ok "Backup-Skript: $BACKUP_BIN"

# --------------------------------------------------------------------------
#  Status-Helfer (JSON, kompatibel zu borg-uebersicht)
# --------------------------------------------------------------------------
cat > "$STATUS_BIN" <<'SCRIPT'
#!/usr/bin/env bash
# Status des lokalen Borg-Repos als eine JSON-Zeile (für borg-uebersicht).
# Läuft als root (SSH-Key in /root/.ssh); der Betriebs-User darf es per sudo -n.
CONF="${OSERP_BORG_CONF:-/etc/oserp/borg.env}"
LOG="${OSERP_BORG_LOG_DIR:-/var/log/oserp-backup}/backup.log"
[[ -r $CONF ]] || { echo '{"error":"no borg.env"}'; exit 0; }
# shellcheck disable=SC1090
source "$CONF"
export BORG_PASSPHRASE
export BORG_RSH="ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=6 -o ConnectTimeout=20 -o BatchMode=yes"
REPO="${BORG_REPO_URL:-ssh://${BORG_BACKUP_USER}@${BORG_BACKUP_HOST}:${BORG_BACKUP_PORT}/./backups/${BORG_REPOSITORY_DIR}}"
RUNNING=false; pgrep -x borg >/dev/null && RUNNING=true
LAST_RESULT=UNKNOWN; LAST_LOG_TIME=""
if [[ -r $LOG ]]; then
    last="$(grep -aE '###### Backup (beendet|FEHLGESCHLAGEN)' "$LOG" | tail -1)"
    [[ $last == *beendet* ]] && LAST_RESULT=OK
    [[ $last == *FEHLGESCHLAGEN* ]] && LAST_RESULT=FAIL
    LAST_LOG_TIME="$(grep -a 'Backup gestartet' "$LOG" | tail -1 | cut -c1-19)"
fi
LIST="$(borg list --json "$REPO" 2>/dev/null)"
INFO="$(borg info --json "$REPO" 2>/dev/null)"
if [[ -z $LIST && -z $INFO ]]; then
    printf '{"host":"%s","repo":"%s","reachable":false,"running":%s,"last_result":"%s","last_log_time":"%s"}\n' \
        "$(hostname)" "$BORG_REPOSITORY_DIR" "$RUNNING" "$LAST_RESULT" "$LAST_LOG_TIME"
    exit 0
fi
LIST="$LIST" INFO="$INFO" HOST="$(hostname)" REPO_D="$BORG_REPOSITORY_DIR" RUNNING="$RUNNING" \
LAST_RESULT="$LAST_RESULT" LAST_LOG_TIME="$LAST_LOG_TIME" python3 - <<'PY'
import os, json
def j(s):
    try: return json.loads(s) if s else {}
    except Exception: return {}
li, info = j(os.environ["LIST"]), j(os.environ["INFO"])
arch = li.get("archives", []); last = arch[-1] if arch else {}
st = info.get("cache", {}).get("stats", {})
print(json.dumps({
    "host": os.environ["HOST"], "repo": os.environ["REPO_D"], "reachable": True,
    "running": os.environ["RUNNING"] == "true", "progress_pct": "",
    "n_archives": len(arch), "last_name": last.get("name", ""),
    "last_start": last.get("start", "") or last.get("time", ""),
    "repo_last_modified": info.get("repository", {}).get("last_modified", ""),
    "total_size": st.get("total_size", 0), "dedup_size": st.get("unique_csize", 0),
    "last_result": os.environ["LAST_RESULT"], "last_log_time": os.environ["LAST_LOG_TIME"],
}))
PY
SCRIPT
chmod 750 "$STATUS_BIN"
ok "Status-Helfer: $STATUS_BIN"
# Gleicher Helfer unter dem Pfad, den das bestehende borg-uebersicht abfragt
if [[ ! -e $STATUS_COMPAT || -L $STATUS_COMPAT ]]; then
    install -d -m 755 "$(dirname "$STATUS_COMPAT")"
    ln -sfn "$STATUS_BIN" "$STATUS_COMPAT"
fi

if [[ -n $OSERP_USER ]] && id "$OSERP_USER" >/dev/null 2>&1; then
    echo "$OSERP_USER ALL=(root) NOPASSWD: $STATUS_BIN, $STATUS_COMPAT" > "$SUDOERS"
    chmod 440 "$SUDOERS"
    if visudo -c -f "$SUDOERS" >/dev/null; then
        ok "sudoers: $OSERP_USER darf den Status ohne Passwort abfragen (für borg-uebersicht)"
    else
        rm -f "$SUDOERS"; warn "sudoers-Regel verworfen (Syntaxfehler)"
    fi
fi

# --------------------------------------------------------------------------
#  Cron
# --------------------------------------------------------------------------
H="${BORG_CRON_TIME%%:*}"; M="${BORG_CRON_TIME##*:}"
cat > "$CRON" <<CRONEOF
# OSERP Borg-Backup — erzeugt von install/borg-standalone.sh (Zeit: $BORG_CRON_TIME)
# Log: $LOG_DIR/backup.log   Status: sudo $STATUS_BIN
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=""
$((10#$M)) $((10#$H)) * * * root $BACKUP_BIN >/dev/null 2>&1
CRONEOF
chmod 644 "$CRON"
ok "Cron: $CRON (täglich $BORG_CRON_TIME als root)"

[[ $REFRESH -eq 1 ]] && { ok "Skripte und Cron neu geschrieben."; exit 0; }

# --------------------------------------------------------------------------
#  SSH-Key auf dem Borg-Server
# --------------------------------------------------------------------------
if [[ $REPO == ssh://* ]]; then
    KEY=/root/.ssh/id_ed25519
    if [[ ! -f $KEY ]]; then
        install -d -m 700 /root/.ssh
        ssh-keygen -t ed25519 -N "" -C "oserp-backup@$(hostname)" -f "$KEY" >/dev/null
        ok "SSH-Key erzeugt: $KEY"
    fi
    touch /root/.ssh/known_hosts
    ssh-keyscan -p "$BORG_BACKUP_PORT" "$BORG_BACKUP_HOST" 2>/dev/null >> /root/.ssh/known_hosts || true
    sort -u /root/.ssh/known_hosts -o /root/.ssh/known_hosts

    ssh_ok() { ssh -p "$BORG_BACKUP_PORT" -o BatchMode=yes -o ConnectTimeout=20 "$BORG_BACKUP_USER@$BORG_BACKUP_HOST" true 2>/dev/null; }
    if ssh_ok; then
        ok "SSH-Zugang zum Borg-Server funktioniert (Key)"
    elif [[ -t 0 ]]; then
        echo
        info "Der öffentliche Key wird jetzt auf dem Server installiert — es folgt die Passwortabfrage für $BORG_BACKUP_USER."
        # Hetzner Storage Box: 'install-ssh-key' nimmt den Key von stdin und hängt ihn ADDITIV an.
        # Anderer SSH-Server: authorized_keys ergänzen.
        if ssh -p "$BORG_BACKUP_PORT" -o ConnectTimeout=20 "$BORG_BACKUP_USER@$BORG_BACKUP_HOST" install-ssh-key < "$KEY.pub" 2>/dev/null \
           || ssh -p "$BORG_BACKUP_PORT" -o ConnectTimeout=20 "$BORG_BACKUP_USER@$BORG_BACKUP_HOST" \
                "mkdir -p .ssh && chmod 700 .ssh && cat >> .ssh/authorized_keys && chmod 600 .ssh/authorized_keys" < "$KEY.pub"; then
            ssh_ok && ok "Key installiert, Zugang funktioniert" || { err "Key installiert, aber Login ohne Passwort schlägt fehl."; exit 1; }
        else
            err "Key konnte nicht installiert werden. Manuell:"
            err "  ssh -p$BORG_BACKUP_PORT $BORG_BACKUP_USER@$BORG_BACKUP_HOST install-ssh-key < $KEY.pub"
            exit 1
        fi
    else
        err "Kein SSH-Zugang zum Borg-Server und kein Terminal für die Passwortabfrage."
        err "Diesen Public Key auf dem Server eintragen und erneut aufrufen:"
        cat "$KEY.pub"
        exit 1
    fi
fi

# --------------------------------------------------------------------------
#  Repository anlegen (nur wenn es fehlt) + Erstsicherung
# --------------------------------------------------------------------------
export BORG_PASSPHRASE BORG_RSH="ssh -o BatchMode=yes -o ConnectTimeout=30"
if borg info "$REPO" >/dev/null 2>&1; then
    ok "Repository vorhanden: $REPO ($(borg list --short "$REPO" 2>/dev/null | wc -l) Archive)"
else
    if [[ $REPO == ssh://* ]]; then
        ssh -p "$BORG_BACKUP_PORT" -o BatchMode=yes "$BORG_BACKUP_USER@$BORG_BACKUP_HOST" "mkdir -p backups/$BORG_REPOSITORY_DIR" || true
    fi
    borg init --encryption=repokey "$REPO"
    ok "Repository angelegt: $REPO"
    # Schlüssel-Export zusätzlich zur Passphrase aufbewahren (Repo-Schlüssel ist Teil des Restores)
    KEYFILE="/etc/oserp/borg-key.${BORG_REPOSITORY_DIR:-repo}.txt"
    if borg key export "$REPO" "$KEYFILE"; then
        chmod 600 "$KEYFILE"; ok "Repo-Schlüssel exportiert: $KEYFILE (mit der Passphrase extern sichern)"
    fi
fi

if [[ $FIRST -eq 0 && -t 0 ]]; then
    read -r -p "Erstsicherung jetzt im Hintergrund starten? [J/n] " a
    [[ ${a:-J} =~ ^[JjYy]$ ]] && FIRST=1
fi
if [[ $FIRST -eq 1 ]]; then
    setsid nohup "$BACKUP_BIN" --first >/dev/null 2>&1 < /dev/null &
    ok "Erstsicherung gestartet (PID $!). Verlauf: tail -f $LOG_DIR/backup.log"
else
    info "Erstsicherung später: sudo $BACKUP_BIN --first   (oder abwarten, der Cron läuft um $BORG_CRON_TIME)"
fi
echo
info "Archive:   sudo $BACKUP_BIN --list"
info "Restore:   mkdir -p /tmp/restore && cd /tmp/restore && sudo $BACKUP_BIN --restore <ARCHIV> etc var/backups/oserp"
info "Status:    sudo $STATUS_BIN   (borg-uebersicht auf dem Zentralrechner fragt genau das ab)"
