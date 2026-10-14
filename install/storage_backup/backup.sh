#!/usr/bin/env bash

########### Initialisierung ##############
#### https://docs.hetzner.com/de/robot/storage-box/backup-space-ssh-keys/
### auf dem Clienten:
# git clone https://Ciatronical@bitbucket.org/Ciatronical/storage_backup.git
# ssh-keygen
# cat ~/.ssh/id_rsa.pub | ssh -p23 uXXXXXX@uXXXXXX.your-storagebox.de install-ssh-key

set -e

# Muss als root laufen: Paketliste nach /var, su - postgres fuer die Dumps,
# SSH-Key von root fuer die Storage Box. Ohne root brach das Skript frueher
# erst mitten im Lauf (nach "Backup gestartet") ohne klare Meldung ab.
if [ "$(id -u)" -ne 0 ]; then
    echo "Fehler: bitte als root ausfuehren (sudo $0 $*)"
    exit 1
fi

# Pfad VOR dem cd ermitteln, sonst scheitert realpath bei relativem Aufruf
# (z. B. "bash install/storage_backup/backup.sh INIT" aus einem anderen Ordner).
full_path=$(realpath "$0")
cd "$(dirname "$full_path")" || exit 1
ENV_FILE="./backup.env"
if [ ! -r "$ENV_FILE" ]; then
    echo "Fehler: $ENV_FILE fehlt oder ist nicht lesbar!"
    exit 1
fi
# shellcheck disable=SC1090
source "$ENV_FILE"

# -------- Hilfeausgabe als Funktion (früh & ohne Seiteneffekte) ----------
print_help() {
  cat <<EOF

###################################### Verwendung des Backup-Skripts ######################################

Ohne Parameter:
  Führt ein reguläres Backup mittels Borg aus und hängt an den Archivnamen
  das aktuelle Datum und die Uhrzeit (z.B. 2025-10-24_14:03).
  Das Repository wird automatisch angelegt falls es noch nicht existiert.

Optionen:
  -h | --help | ?   Zeigt diese Hilfe an und beendet das Skript sofort.
  -v                Verbose-Modus für Borg (fügt --list --filter=AME hinzu).

Spezielle Aktionen (als Positions-Argument):
  INIT              Legt das Repository neu an, nur wenn es noch nicht existiert.
                    (Sicher: bricht ab, falls bereits vorhanden.)
  NEW_INIT          Löscht das gesamte Repository und legt es anschließend neu an.
                    (Gefährlich: Alle Backups in diesem Repository gehen verloren!)

Freier Suffix:
  Jeder weitere einzelne Parameter (außer den obigen) wird als Suffix
  an den Archivnamen angehängt, z.B. ::2025-10-24_14:03_SUFFIX

Beispiele:
  ./backup.sh
  ./backup.sh -v
  ./backup.sh INIT
  ./backup.sh NEW_INIT
  ./backup.sh -v nightly
  ./backup.sh ?       # identisch zu -h

Repository / Listing:
  borg list ssh://\${BACKUP_USER}@\${BACKUP_USER}.your-storagebox.de:23/./backups/\${REPOSITORY_DIR}

Rücksichern einzelner Verzeichnisse (Beispiel):
  cd /mytmp
  borg extract ssh://\${BACKUP_USER}@\${BACKUP_USER}.your-storagebox.de:23/./backups/\${REPOSITORY_DIR}::DATUM_UHRZEIT etc var lib lib64 sbin usr bin

Komplette Rücksicherung (Beispiel):
  cd /
  borg extract ssh://\${BACKUP_USER}@\${BACKUP_USER}.your-storagebox.de:23/./backups/\${REPOSITORY_DIR}::DATUM_UHRZEIT

Auf den Backup-Server verbinden:
  ssh -p23 \${BACKUP_USER}@\${BACKUP_USER}.your-storagebox.de
Backup-Verzeichnis anzeigen:
  ls backups/\${REPOSITORY_DIR}
Backup-Verzeichnis löschen (WARNUNG!):
  rm -rf backups/\${REPOSITORY_DIR}

######################################

EOF
}

# ---- Optionen/Argumente parsen (ohne Seiteneffekte) ---------------------
BORG_VERBOSE=0
ACTION=""        # "", "INIT" oder "NEW_INIT"
USER_SUFFIX=""

for arg in "$@"; do
  case "$arg" in
    -h|--help|?) print_help; exit 0 ;;
    -v)          BORG_VERBOSE=1 ;;
    INIT|NEW_INIT) ACTION="$arg" ;;
    *)
      if [ -z "$USER_SUFFIX" ]; then
        USER_SUFFIX="$arg"
      fi
      ;;
  esac
done

# ---- Ab hier erst "richtige" Initialisierung / Logging -------------------
if [ ! -d "$LOG_DIR" ]; then
    mkdir -p "$LOG_DIR"
fi

dir_path=$(dirname "$full_path")
echo "$dir_path"

# ==========================================================================
# SSH-Keepalive fuer die Storage-Box-Verbindung.
#   ServerAliveInterval=30   -> alle 30s Keepalive-Paket senden
#   ServerAliveCountMax=6    -> 6 * 30s = 3 Minuten, dann gibt SSH auf
#   ConnectTimeout=30        -> Verbindungsaufbau max. 30s
# Kurzer Deadline ist ABSICHT: Bei der naechtlichen DSL-Zwangstrennung (neue IP)
# ist die Verbindung tot; borg soll das in Minuten erkennen, abbrechen und den
# Lock freigeben, damit sofort ein Resume moeglich ist – statt 4h zu haengen.
# ==========================================================================
export BORG_RSH="ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=6 -o ConnectTimeout=30"

REPOSITORY="ssh://${BACKUP_USER}@${BACKUP_USER}.your-storagebox.de:23/./backups/${REPOSITORY_DIR}"
TIMESTAMP=$(date +'%Y-%m-%d_%H:%M')

# Ausgabe in Logdatei schreiben
exec > >(tee -i "${LOG}")
exec 2>&1

start_time=$(date +'%Y-%m-%d %H:%M:%S')
echo "###### Backup gestartet: $start_time ######"

## Liste installierter Pakete
dpkg --get-selections > /var/software.list

#############################################
# >>> INTEGRIERT: PostgreSQL + MySQL Dumps
#############################################

backup_postgresql() {
  echo "Creating PostgreSQL dumps ..."
  if ! command -v psql >/dev/null 2>&1; then
    echo "PostgreSQL nicht installiert – übersprungen."
    return 0
  fi

  local TMPDIR="${PG_TMPDIR:-/tmp/backup-postgresql-db}"
  local BASEDIR="$dir_path"

  echo "Nutze TMPDIR: $TMPDIR"
  if [ -d "$TMPDIR" ]; then
    echo "Verzeichnis \"$TMPDIR\" prüfen....OK"
  else
    echo "Verzeichnis \"$TMPDIR\" existiert nicht. Lege es an ..."
    mkdir -p "$TMPDIR"
    echo "Verzeichnis \"$TMPDIR\" angelegt"
  fi
  chmod 777 "$TMPDIR"

  DB_LIST=$(su - postgres -c "psql -l -t | cut -d '|' -f1 | cut -d ':' -f1 | sed '/^ *$/d' | sed '/temp/d' | sed '/lxcars/d' | tr '\n' '\t'")

  for i in $DB_LIST; do
    echo "Datenbank \"$i\" wird gesichert."
    su - postgres -c "pg_dump \"$i\" > \"$TMPDIR/$i.sql\""
  done

  mkdir -p "$BASEDIR/data/postgresql-dbs/"
  echo "Kopiere nach $BASEDIR/data/postgresql-dbs/"
  cp -R "$TMPDIR/"* "$BASEDIR/data/postgresql-dbs/" 2>/dev/null || true

}

# ==========================================================================
# Test-Datenbank aus der Produktiv-Datenbank neu aufbauen (Klon).
#
# Steuerung über backup.env:
#   PG_CLONE=true|false      (Default: false — nichts tun)
#   PG_CLONE_SRC=ap_rebuild  Quelle (Produktiv-DB, wird nur GELESEN)
#   PG_CLONE_DST=ap_dev      Ziel   (Test-DB, wird gelöscht und neu angelegt)
#
# Es wird KEIN zweiter pg_dump gefahren: der Dump aus backup_postgresql()
# liegt bereits in $TMPDIR/<SRC>.sql und wird hier wiederverwendet.
#
# Zusätzlich werden die mandantenspezifischen Belege/Dateien unter
# backend/data/<dbname>/ abgeglichen (inkrementell per rsync --delete), da
# diese pro DB-Name getrennt liegen und in der Kopie sonst fehlen würden.
# Das Firmenlogo (defaults_oserp.company_logo) der Ziel-DB bleibt erhalten,
# damit die Testfirma optisch von der Produktivfirma unterscheidbar bleibt.
# ==========================================================================
clone_test_database() {
  local SRC="${PG_CLONE_SRC:-ap_rebuild}"
  local DST="${PG_CLONE_DST:-ap_dev}"
  local TMPDIR="${PG_TMPDIR:-/tmp/backup-postgresql-db}"
  local DUMP="$TMPDIR/$SRC.sql"

  echo "###### Test-Datenbank neu aufbauen: '$SRC' -> '$DST' ######"

  if [ ! -s "$DUMP" ]; then
    echo "WARN: Dump \"$DUMP\" fehlt oder ist leer – Klonen übersprungen."
    return 0
  fi

  if [ "$SRC" = "$DST" ]; then
    echo "FEHLER: PG_CLONE_SRC und PG_CLONE_DST sind identisch ('$SRC') – abgebrochen."
    return 0
  fi

  # Encoding/Locale der Quelle übernehmen, damit der Import 1:1 passt.
  local ENC COLLATE CTYPE
  ENC=$(su - postgres -c "psql -tAc \"SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname='$SRC'\"" || true)
  COLLATE=$(su - postgres -c "psql -tAc \"SELECT datcollate FROM pg_database WHERE datname='$SRC'\"" || true)
  CTYPE=$(su - postgres -c "psql -tAc \"SELECT datctype FROM pg_database WHERE datname='$SRC'\"" || true)
  if [ -z "$ENC" ]; then
    echo "WARN: Quell-Datenbank '$SRC' existiert nicht – Klonen übersprungen."
    return 0
  fi

  # Firmenlogo der bestehenden Ziel-DB sichern (falls vorhanden).
  local LOGO_FILE="$TMPDIR/.company_logo_$DST"
  rm -f "$LOGO_FILE"
  if su - postgres -c "psql -tAc \"SELECT 1 FROM pg_database WHERE datname='$DST'\"" | grep -q 1; then
    su - postgres -c "psql -d '$DST' -c \"\\copy (SELECT value FROM defaults_oserp WHERE key='company_logo') TO '$LOGO_FILE'\"" >/dev/null 2>&1 || true
    if [ -s "$LOGO_FILE" ]; then
      echo "Logo aus '$DST' gesichert."
    else
      rm -f "$LOGO_FILE"
    fi

    echo "Ziel-Datenbank '$DST' wird gelöscht ..."
    su - postgres -c "psql -d postgres -c \"SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='$DST' AND pid <> pg_backend_pid();\"" >/dev/null
    su - postgres -c "dropdb --if-exists '$DST'"
  fi

  echo "Erstelle leere Datenbank '$DST' (ENCODING $ENC, COLLATE $COLLATE) ..."
  su - postgres -c "psql -d postgres -c \"CREATE DATABASE \\\"$DST\\\" TEMPLATE template0 ENCODING '$ENC' LC_COLLATE '$COLLATE' LC_CTYPE '$CTYPE';\"" >/dev/null

  echo "Spiele Dump ein ($(du -h "$DUMP" | cut -f1)) ..."
  if ! su - postgres -c "psql -q -d '$DST' --set ON_ERROR_STOP=on -f '$DUMP'" >/dev/null; then
    echo "FEHLER: Import von '$DUMP' nach '$DST' fehlgeschlagen."
    return 0
  fi

  # Telegram-Zugangsdaten aus dem Klon entfernen — so frueh wie moeglich, denn ab
  # jetzt traegt '$DST' dieselben Bot-Zugangsdaten wie die Produktiv-DB.
  # _findCompanyDbBySecret() in backend/webhook/telegram.php durchlaeuft auth.clients
  # ohne feste Reihenfolge und nimmt den ersten Treffer mit passendem Secret — echte
  # Sprachnotizen koennten sonst in der Test-DB landen statt auf der Tafel.
  # Das Skript lehnt jede DB ab, die in auth.clients als is_default markiert ist.
  local OSERP_DIR="${PG_CLONE_APP_DIR:-/home/work/opensource-erp}"
  if su - work -c "cd '$OSERP_DIR' && php backend/cli/sanitize-testdb.php '$DST'" >/dev/null 2>&1; then
    echo "Telegram-Zugangsdaten aus '$DST' entfernt."
  else
    # Fehlgeschlagen heisst: '$DST' ist mit gueltigem Bot-Secret erreichbar.
    # Lieber keine Test-Datenbank als eine, die echte Sprachnotizen abfaengt.
    echo "FEHLER: '$DST' konnte nicht entschaerft werden – Test-Datenbank wird verworfen."
    su - postgres -c "dropdb --if-exists '$DST'" >/dev/null 2>&1
    return 0
  fi

  # Gesichertes Logo zurückschreiben – überschreibt das aus der Quelle kopierte.
  if [ -s "$LOGO_FILE" ]; then
    echo "Stelle ursprüngliches Logo von '$DST' wieder her ..."
    su - postgres -c "psql -q -d '$DST' --single-transaction --set ON_ERROR_STOP=on" >/dev/null <<SQL || echo "WARN: Logo konnte nicht zurückgeschrieben werden."
CREATE TEMP TABLE _logo_restore(value text);
\\copy _logo_restore FROM '$LOGO_FILE'
UPDATE defaults_oserp
   SET value = (SELECT value FROM _logo_restore LIMIT 1),
       mtime = now()
 WHERE key = 'company_logo';
SQL
    rm -f "$LOGO_FILE"
  fi

  # Belege/Dateien abgleichen (liegen pro DB-Name getrennt).
  local DATA_DIR="${PG_CLONE_DATA_DIR:-/home/work/opensource-erp/backend/data}"
  if [ -d "$DATA_DIR/$SRC" ]; then
    echo "Gleiche Belege ab: $DATA_DIR/$SRC/ -> $DATA_DIR/$DST/ ..."
    mkdir -p "$DATA_DIR/$DST"
    # Inkrementell: es werden nur geänderte/neue Dateien übertragen.
    rsync -a --delete "$DATA_DIR/$SRC/" "$DATA_DIR/$DST/" || echo "WARN: rsync der Belege fehlgeschlagen."
    chown -R work:www-data "$DATA_DIR/$DST" 2>/dev/null || true
    find "$DATA_DIR/$DST" -type d -exec chmod 2775 {} + 2>/dev/null || true
  else
    echo "Hinweis: '$DATA_DIR/$SRC' existiert nicht – Belege werden übersprungen."
  fi

  echo "###### Test-Datenbank '$DST' ist jetzt ein Klon von '$SRC'. ######"
}

backup_mysql() {
  echo "Creating MySQL/MariaDB dumps ..."
  if ! command -v mysqldump >/dev/null 2>&1; then
    echo "MySQL/MariaDB nicht installiert – übersprungen."
    return 0
  fi

  local USER="${MYSQL_USER:-root}"
  local PASSWD="${MYSQL_PASSWORD:-}"
  local BASEDIR="$dir_path"

  if [ -n "$PASSWD" ]; then
    DATABASES=$(mysql -u"$USER" -p"$PASSWD" -e "SHOW DATABASES;" | grep -Ev "(Database|information_schema|performance_schema)")
  else
    DATABASES=$(mysql -u"$USER" -e "SHOW DATABASES;" | grep -Ev "(Database|information_schema|performance_schema)")
  fi

  mkdir -p "$BASEDIR/data/mysql-dbs/"
  for db in $DATABASES; do
    echo "Dumping $db  in $BASEDIR/data/mysql-dbs/$db.sql"
    if [ -n "$PASSWD" ]; then
      mysqldump --force --opt -u"$USER" -p"$PASSWD" --databases "$db" > "$BASEDIR/data/mysql-dbs/$db.sql"
    else
      mysqldump --force --opt -u"$USER" --databases "$db" > "$BASEDIR/data/mysql-dbs/$db.sql"
    fi
  done
}

# ----------------- Flags / Suffix vorbereiten ----------------------------
BACKUP_SUFFIX=""
if [ -n "$USER_SUFFIX" ] && [ "$USER_SUFFIX" != "INIT" ] && [ "$USER_SUFFIX" != "NEW_INIT" ] && [ "$USER_SUFFIX" != "-v" ]; then
  BACKUP_SUFFIX="_$USER_SUFFIX"
fi

BORG_EXTRA_OPTS=()
if [ "$BORG_VERBOSE" = "1" ]; then
  echo "Verbose mode: Borg mit --list --filter=AME"
  BORG_EXTRA_OPTS+=( --list --filter=AME )
fi

# ----------- Auto-INIT: Repository anlegen falls noch nicht vorhanden -----
# Läuft nur beim normalen Backup-Lauf (nicht bei INIT/NEW_INIT).
# Ist das Repository bereits da, passiert nichts – kein Fehler, kein Abbruch.
auto_init_if_needed() {
  echo "Prüfe ob Borg-Repository vorhanden ist ..."
  if borg info "$REPOSITORY" > /dev/null 2>&1; then
    echo "Repository vorhanden – kein INIT nötig."
  else
    echo "Repository nicht gefunden – lege es automatisch an ..."
    ssh -p23 "${BACKUP_USER}@${BACKUP_USER}.your-storagebox.de" \
        "mkdir -p backups/${REPOSITORY_DIR}"
    borg init --encryption=repokey "$REPOSITORY"
    echo "Repository erfolgreich initialisiert."
  fi
}

# ----------- Gewünschte Aktion ausführen ---------------------------------
case "$ACTION" in
  NEW_INIT)
    echo "Überprüfe ob Verzeichnis backups/${REPOSITORY_DIR} existiert ..."
    ssh -p23 "${BACKUP_USER}@${BACKUP_USER}.your-storagebox.de" \
        "mkdir -p backups/${REPOSITORY_DIR}"
    echo "Das Repository wird komplett gelöscht und neu angelegt ..."
    borg delete --force --stats "$REPOSITORY" || true
    borg init --encryption=repokey "$REPOSITORY"
    BACKUP_SUFFIX="_NEW_INIT${BACKUP_SUFFIX}"
    ;;
  INIT)
    echo "Überprüfe ob Verzeichnis backups/${REPOSITORY_DIR} existiert ..."
    ssh -p23 "${BACKUP_USER}@${BACKUP_USER}.your-storagebox.de" \
        "mkdir -p backups/${REPOSITORY_DIR}"
    echo "Überprüfe ob Repository existiert ..."
    if borg info "$REPOSITORY" > /dev/null 2>&1; then
      echo "Das Repository existiert bereits. Vorgang wird abgebrochen."
      exit 1
    else
      echo "Das Repository wird neu angelegt ..."
      borg init --encryption=repokey "$REPOSITORY"
    fi
    BACKUP_SUFFIX="_INIT${BACKUP_SUFFIX}"
    ;;
  *)
    # Normaler Cron-Lauf: Repository automatisch anlegen falls nötig
    auto_init_if_needed
    ;;
esac

# ----------------- DB-Dumps ausführen ------------------------------------
echo -n "Postgresql installed? "
if command -v psql >/dev/null 2>&1; then
  echo 'YES'
  backup_postgresql
  # Test-Datenbank aus dem frisch erstellten Produktiv-Dump neu aufbauen.
  # Bewusst NACH den Dumps und VOR borg create: schlägt es fehl, läuft das
  # eigentliche Backup trotzdem weiter (Funktion gibt immer 0 zurück).
  if [ "${PG_CLONE:-false}" = "true" ]; then
    clone_test_database
  else
    echo "PG_CLONE ist nicht aktiv – Test-Datenbank wird nicht neu aufgebaut."
  fi
else
  echo 'NO!!!'
fi

echo -n "MariaDB / MySQL installed?: "
if command -v mysqldump >/dev/null 2>&1 || command -v mysql >/dev/null 2>&1; then
  echo 'YES'
  backup_mysql
else
  echo 'NO!!!'
fi

# ==========================================================================
# LOCKS AUFRÄUMEN: Veraltete Locks von abgebrochenen Jobs entfernen.
# borg break-lock räumt BEIDE auf:
#   - Lokal:  ~/.cache/borg/<repoid>/lock.exclusive
#   - Remote: <repository>/lock.exclusive auf der Storage Box
# Sicherheitscheck: Nur ausführen wenn KEIN Borg-Prozess läuft.
# ==========================================================================
echo "Prüfe auf veraltete Borg-Locks ..."
if pgrep -x borg > /dev/null 2>&1; then
  echo "WARNUNG: Aktiver Borg-Prozess erkannt – überspringe Lock-Bereinigung."
  echo "         Bei Problemen manuell prüfen: pgrep -a borg"
else
  echo "Kein laufender Borg-Prozess – bereinige veraltete Locks ..."
  if borg break-lock "$REPOSITORY" 2>/dev/null; then
    echo "Locks bereinigt (lokal + remote)."
  else
    echo "Keine veralteten Locks gefunden."
  fi
fi

# ----------------- Borg Backup ausführen ---------------------------------
echo "Übertrage Dateien ..."
EXCLUDE_ARGS=( "${EXCLUDES[@]/#/--exclude=}" )

echo "DEBUG: Übergebe folgende Argumente an Borg:"
echo "-------------------------------------------"
echo "REPOSITORY: $REPOSITORY::${TIMESTAMP}${BACKUP_SUFFIX}"
echo "VERZEICHNISSE:"
printf "  '%s'\n" "${DIRS_TO_BACKUP[@]}"
echo "EXCLUDES:"
printf "  '%s'\n" "${EXCLUDE_ARGS[@]}"
echo "-------------------------------------------"

# Borg-Exit-Codes: 0 = OK, 1 = Warnung (Archiv gueltig, z.B. Datei aenderte sich
# waehrend der Sicherung / nicht lesbar), >=2 = echter Fehler. Nur >=2 alarmieren.
BORG_RC=0
borg create -v --stats \
    --lock-wait "${LOCK_WAIT:-300}" \
    --compression lz4 \
    "${BORG_EXTRA_OPTS[@]}" \
    "$REPOSITORY::${TIMESTAMP}${BACKUP_SUFFIX}" \
    "${DIRS_TO_BACKUP[@]}" \
    "${EXCLUDE_ARGS[@]}" || BORG_RC=$?

if [ "$BORG_RC" -ge 2 ]; then
    echo "Borg create FEHLGESCHLAGEN (Exit $BORG_RC) – sende Mail an ${ALERT_EMAIL}"
    mail -s "[Backup-Fehler] $(hostname) – Borg Backup fehlgeschlagen" "$ALERT_EMAIL" <<MAILEOF
Das Borg-Backup ist fehlgeschlagen (Exit-Code $BORG_RC).

Host:        $(hostname)
Zeit:        $(date)
Repository:  ${REPOSITORY}
Lock-Wait:   ${LOCK_WAIT:-300}s

Letzte Logzeilen:
$(tail -n 50 "$LOG")

Mögliche Ursache: Lock vorhanden (z.B. laufender oder abgebrochener Job).
Manuelle Prüfung/Behebung (nur falls sicher kein anderer Job läuft):
  borg break-lock "$REPOSITORY"
MAILEOF
    exit 1
elif [ "$BORG_RC" -eq 1 ]; then
    echo "Borg-Warnung (Exit 1): Archiv wurde erstellt, aber mit Warnungen (z.B. Datei aenderte sich waehrend der Sicherung) – kein Fehler, Backup ist gueltig."
fi

# ----------------- Retention: alte Archive ausduennen --------------------
# Laeuft nur nach erfolgreichem borg create. Behaelt taeglich/woechentlich/
# monatlich gestaffelt; anschliessend compact, um Speicher auf der Storage Box
# tatsaechlich freizugeben (Borg 1.2 gibt Platz erst mit compact frei).
echo "Prune: alte Archive ausduennen ..."
borg prune -v --list \
    --keep-daily=7 --keep-weekly=4 --keep-monthly=6 \
    "$REPOSITORY" || echo "WARN: borg prune fehlgeschlagen"
echo "Compact: Speicher auf der Storage Box freigeben ..."
borg compact "$REPOSITORY" || echo "WARN: borg compact fehlgeschlagen"

# ----------------- Abschluss-Infos ---------------------------------------
end_time=$(date +'%Y-%m-%d %H:%M:%S')
duration=$(date -u -d @$(( $(date -d "$end_time" +%s) - $(date -d "$start_time" +%s) )) +%H:%M:%S)

echo "###### Backup beendet: $end_time ######"
echo "Time (start): $start_time"
echo "Time (end):   $end_time"
echo "Duration:     $duration"

echo -e "\n######################################"
echo -e "Backups auflisten mit:"
echo -e "borg list ssh://${BACKUP_USER}@${BACKUP_USER}.your-storagebox.de:23/./backups/${REPOSITORY_DIR}\n"

echo -e "Rücksichern einzelner Verzeichnisse mit:"
echo -e "cd /mytmp"
echo -e "borg extract ssh://${BACKUP_USER}@${BACKUP_USER}.your-storagebox.de:23/./backups/${REPOSITORY_DIR}::${TIMESTAMP}${BACKUP_SUFFIX} etc var lib lib64 sbin usr bin"
echo -e "######################################\n"

echo -e "Komplette Rücksicherung mit:"
echo -e "cd /"
echo -e "borg extract ssh://${BACKUP_USER}@${BACKUP_USER}.your-storagebox.de:23/./backups/${REPOSITORY_DIR}::${TIMESTAMP}${BACKUP_SUFFIX}"
echo -e "######################################\n"

echo -e "Auf den Backup-Server verbinden:"
echo -e "ssh -p23 ${BACKUP_USER}@${BACKUP_USER}.your-storagebox.de"
echo -e "Verzeichnisinhalt anzeigen:"
echo -e "ls backups/${REPOSITORY_DIR}"
echo -e "Verzeichnis der aktuellen Backups löschen:"
echo -e "rm -rf backups/${REPOSITORY_DIR}"
echo -e "######################################\n"
