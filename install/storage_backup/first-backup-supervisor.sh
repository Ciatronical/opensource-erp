#!/usr/bin/env bash
# Auto-Resume-Supervisor fuer den ERSTEN borg-Vollbackup.
# Erkennt Stall/Abbruch (auch den 4h-Keepalive-Hang) in Minuten, raeumt auf,
# wartet auf Erreichbarkeit der Storage Box (nach Zwangstrennung) und startet
# sofort den Resume. Laeuft bis "Backup beendet" im Log steht.
# Stellt am Ende die nächtliche Cron-Zeile wieder her (war während der
# Erstsicherung deaktiviert, damit der Nacht-Cron nicht kollidiert).

cd /usr/local/sbin/storage_backup || exit 1
set -a; source ./backup.env; set +a

REPO="ssh://${BACKUP_USER}@${BACKUP_USER}.your-storagebox.de:23/./backups/${REPOSITORY_DIR}"
HOST="${BACKUP_USER}.your-storagebox.de"
BLOG=/var/log/storage_backup/backup.log
SLOG=/var/log/storage_backup/supervisor.log
INTERVAL=120          # Sekunden pro Zyklus
FLAT_LIMIT=3          # so viele Zyklen ohne CPU-Fortschritt = Stall (~6 min)

# borg-RSH mit KURZEM Keepalive nur fuer die Erreichbarkeits-/break-lock-Aufrufe
export BORG_RSH="ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=6 -o ConnectTimeout=30"

say(){ echo "$(date '+%F %T') $*" >> "$SLOG"; }
borg_pid(){ pgrep -x borg | head -1; }
cpu_ticks(){ local p="$1"; [ -n "$p" ] && awk '{print $14+$15}' "/proc/$p/stat" 2>/dev/null || echo 0; }
reachable(){ timeout 20 bash -c "exec 3<>/dev/tcp/${HOST}/23" 2>/dev/null; }

restore_cron(){
  local TMP; TMP="$(mktemp)"
  crontab -l > "$TMP" 2>/dev/null || true
  sed -i 's/^#SUPERVISED //' "$TMP"
  crontab "$TMP"; rm -f "$TMP"
  say "Nacht-Cron wiederhergestellt"
}

relaunch(){
  say "Aufraeumen + Resume"
  pkill -f "bash ./backup.sh" 2>/dev/null
  pkill -x borg 2>/dev/null; sleep 5
  pkill -9 -x borg 2>/dev/null; sleep 3
  local n=0
  until reachable; do
    n=$((n+1)); say "Storage Box nicht erreichbar (Zwangstrennung?) – warte 60s (#$n)"; sleep 60
  done
  borg break-lock "$REPO" 2>/dev/null && say "break-lock ok" || say "break-lock: nichts zu tun"
  setsid nohup ./backup.sh >/var/log/storage_backup/run.out 2>&1 </dev/null &
  say "RESUME gestartet (PID $!)"
}

say "==== Supervisor gestartet (REPO=$REPO) ===="
last=0; flat=0
while true; do
  if grep -q "Backup beendet" "$BLOG" 2>/dev/null && [ -z "$(borg_pid)" ]; then
    say "FERTIG – erster Vollbackup abgeschlossen"
    restore_cron
    break
  fi
  bp="$(borg_pid)"
  if [ -n "$bp" ]; then
    t="$(cpu_ticks "$bp")"
    if [ "$t" -le "$last" ]; then flat=$((flat+1)); else flat=0; fi
    last="$t"
    if [ "$flat" -ge "$FLAT_LIMIT" ]; then
      say "STALL erkannt (borg $bp ohne CPU-Fortschritt ~$((FLAT_LIMIT*INTERVAL))s) – Resume"
      relaunch; last=0; flat=0
    fi
  else
    if ! pgrep -f "bash ./backup.sh" >/dev/null 2>&1; then
      say "Kein Lauf aktiv und kein Erfolg – Resume"
      relaunch; last=0; flat=0
    fi
    # sonst: backup.sh in Nicht-borg-Phase (DB-Dumps) – abwarten
  fi
  sleep "$INTERVAL"
done
