#!/usr/bin/env bash
# Zeigt den Fortschritt des laufenden borg-Backups (als root ausfuehren).
BP="$(pgrep -x borg | head -1)"
if [ -z "$BP" ]; then
  echo "Kein borg-Backup aktiv."
  tail -n 3 /var/log/storage_backup/supervisor.log 2>/dev/null
  exit 0
fi
TOTALF=/usr/local/sbin/storage_backup/.src_total
TOTAL="$(cat "$TOTALF" 2>/dev/null || cat /tmp/src_total.txt 2>/dev/null || echo 0)"
r1="$(awk '/^rchar/{print $2}' /proc/$BP/io 2>/dev/null)"
sleep 4
r2="$(awk '/^rchar/{print $2}' /proc/$BP/io 2>/dev/null)"
rate=$(( (r2 - r1) / 4 ))
cur="$(readlink -f /proc/$BP/cwd >/dev/null 2>&1; ls -l /proc/$BP/fd 2>/dev/null | grep -oE '/[^ ]+$' | grep -vE '^/(dev|proc|sys)' | head -1)"
if [ "${TOTAL:-0}" -gt 0 ]; then
  awk -v d="$r2" -v t="$TOTAL" -v r="$rate" 'BEGIN{
    pct=d*100/t; rem=(t-d)/(r>0?r:1);
    printf "Backup laeuft: %.1f%%  (%.1f / %.1f GiB gelesen)  Rate: %.1f MB/s  Rest ~%dh%02dm\n",
           pct, d/2^30, t/2^30, r/2^20, rem/3600, (rem%3600)/60 }'
else
  awk -v d="$r2" -v r="$rate" 'BEGIN{printf "Gelesen: %.1f GiB  Rate: %.1f MB/s (Gesamtgroesse unbekannt)\n", d/2^30, r/2^20}'
fi
[ -n "$cur" ] && echo "Aktuell bei: $cur"
