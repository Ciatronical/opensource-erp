#!/usr/bin/env bash

# nur bei Fehlern abbrechen (bewusst KEIN -u, damit fehlende optionale Arrays nicht knallen)
set -e

# --- ENV-Datei einlesen ---
ENV_FILE="./backup.env"
if [ ! -r "$ENV_FILE" ]; then
    echo "Fehler: $ENV_FILE fehlt oder ist nicht lesbar!"
    exit 1
fi
# shellcheck disable=SC1090
source "$ENV_FILE"

# --- Pflicht-Variablen prüfen ---
: "${BACKUP_USER:?BACKUP_USER fehlt in backup.env}"
: "${REPOSITORY_DIR:?REPOSITORY_DIR fehlt in backup.env}"

# --- Repository & Timestamp ---
REPOSITORY="ssh://${BACKUP_USER}@${BACKUP_USER}.your-storagebox.de:23/./backups/${REPOSITORY_DIR}"
TIMESTAMP=$(date +'%Y-%m-%d_%H:%M')

# --- Optionale Defaults (falls in env nicht gesetzt) ---
: "${LOCK_WAIT:=0}"
# Falls du in der env BORG_EXTRA_OPTS als Array haben willst, kannst du sie dort definieren.
# Wenn nicht vorhanden, setzen wir eine sinnvolle Anzeige-Voreinstellung:
if ! declare -p BORG_EXTRA_OPTS >/dev/null 2>&1; then
  BORG_EXTRA_OPTS=( --list --filter=AME )
fi

# --- Arrays aus env nutzen ---
# DIRS_TO_BACKUP muss in backup.env definiert sein (wie bei dir gezeigt).
if ! declare -p DIRS_TO_BACKUP >/dev/null 2>&1; then
  echo "Fehler: DIRS_TO_BACKUP ist nicht in backup.env definiert (Bash-Array)."
  exit 1
fi

# EXCLUDES ist optional; wenn vorhanden, in --exclude=… umwandeln.
EXCLUDE_ARGS=()
if declare -p EXCLUDES >/dev/null 2>&1; then
  # in --exclude=/pfad umschreiben
  EXCLUDE_ARGS=( "${EXCLUDES[@]/#/--exclude=}" )
fi

# --- Nur Ausgabe des Befehls (keine Ausführung!) ---
echo "##################################################"
echo "BORG TESTBEFEHL (nur Ausgabe, keine Ausführung)"
echo "##################################################"
echo

printf 'borg create -v --stats --lock-wait %q \\\n' "${LOCK_WAIT}"

# Extra-Optionen
for opt in "${BORG_EXTRA_OPTS[@]}"; do
  printf '    %q \\\n' "$opt"
done

# Repository::Archiv
printf '    %q \\\n' "${REPOSITORY}::${TIMESTAMP}_TEST"

echo 'Backup-Quellen:'
for dir in "${DIRS_TO_BACKUP[@]}"; do
  printf '    %q \\\n' "$dir"
done

echo 'Excludes (falls vorhanden):'
for excl in "${EXCLUDE_ARGS[@]}"; do
  printf '    %q \\\n' "$excl"
done

# Letzte Backslash-Zeile hübsch beenden (optional)
echo
echo "Hinweis: BORG_PASSPHRASE muss in der Umgebung gesetzt sein (backup.env)."
echo "##################################################"
# Ende des Skripts