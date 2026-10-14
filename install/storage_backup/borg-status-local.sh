#!/usr/bin/env bash
# Liefert den Status des LOKALEN borg-Repos als eine JSON-Zeile.
# Als root ausfuehren (borg braucht den SSH-Key aus /root/.ssh).
#   borg list  -> Archive (Anzahl, letztes Archiv + Zeit)
#   borg info  -> Repo-Groesse (dedupliziert) + last_modified
DIR=/usr/local/sbin/storage_backup
cd "$DIR" 2>/dev/null || { echo '{"error":"no storage_backup dir"}'; exit 0; }
[ -r ./backup.env ] || { echo '{"error":"no backup.env"}'; exit 0; }
set -a; . ./backup.env; set +a
export BORG_RSH="ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=6 -o ConnectTimeout=20"
REPO="ssh://${BACKUP_USER}@${BACKUP_USER}.your-storagebox.de:23/./backups/${REPOSITORY_DIR}"
HOST="$(hostname)"
LOG=/var/log/storage_backup/backup.log

RUNNING=0; pgrep -x borg >/dev/null 2>&1 && RUNNING=1

# Live-Fortschritt (nur waehrend eines Laufs, best effort): gelesene Bytes / Gesamtgroesse
PCT=""
if [ "$RUNNING" = 1 ]; then
  BP="$(pgrep -x borg | head -1)"
  TOTAL="$(cat "$DIR/.src_total" 2>/dev/null || echo 0)"
  RCH="$(awk '/^rchar/{print $2}' "/proc/$BP/io" 2>/dev/null)"
  if [ -n "$RCH" ] && [ "${TOTAL:-0}" -gt 0 ]; then
    PCT="$(awk -v d="$RCH" -v t="$TOTAL" 'BEGIN{printf "%.0f", d*100/t}')"
  fi
fi

LAST_RESULT=UNKNOWN; LAST_LOG_TIME=""
if [ -r "$LOG" ]; then
  grep -q "Backup beendet" "$LOG" 2>/dev/null && LAST_RESULT=OK
  grep -q "FEHLGESCHLAGEN" "$LOG" 2>/dev/null && LAST_RESULT=FAIL
  LAST_LOG_TIME="$(grep -aoE 'Backup gestartet: [0-9-]+ [0-9:]+' "$LOG" 2>/dev/null | tail -1 | sed 's/Backup gestartet: //')"
fi

LIST="$(borg list --json "$REPO" 2>/dev/null)"
INFO="$(borg info --json "$REPO" 2>/dev/null)"

if [ -z "$LIST" ] && [ -z "$INFO" ]; then
  printf '{"host":"%s","repo":"%s","reachable":false,"running":%s,"progress_pct":"%s","last_result":"%s","last_log_time":"%s"}\n' \
    "$HOST" "$REPOSITORY_DIR" "$RUNNING" "$PCT" "$LAST_RESULT" "$LAST_LOG_TIME"
  exit 0
fi

LIST="$LIST" INFO="$INFO" HOST="$HOST" REPO_D="$REPOSITORY_DIR" RUNNING="$RUNNING" PCT="$PCT" \
LAST_RESULT="$LAST_RESULT" LAST_LOG_TIME="$LAST_LOG_TIME" python3 - <<'PY'
import os, json
def loado(s):
    try: return json.loads(s) if s else {}
    except Exception: return {}
li=loado(os.environ.get("LIST",""))
info=loado(os.environ.get("INFO",""))
arch=li.get("archives",[])
last=arch[-1] if arch else {}
st=info.get("cache",{}).get("stats",{})
out={
  "host":os.environ["HOST"],
  "repo":os.environ["REPO_D"],
  "reachable":True,
  "running":os.environ["RUNNING"]=="1",
  "progress_pct":os.environ.get("PCT",""),
  "n_archives":len(arch),
  "last_name":last.get("name",""),
  "last_start":last.get("start","") or last.get("time",""),
  "repo_last_modified":info.get("repository",{}).get("last_modified",""),
  "total_size":st.get("total_size",0),
  "dedup_size":st.get("unique_csize",0),
  "last_result":os.environ["LAST_RESULT"],
  "last_log_time":os.environ["LAST_LOG_TIME"],
}
print(json.dumps(out))
PY
