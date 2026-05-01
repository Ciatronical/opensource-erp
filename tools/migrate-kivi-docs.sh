#!/bin/bash
# Migriert Dokumente von Kivitendo-CRM nach OSERP (lokal, kein SSH).
# Legt danach alle fehlenden Verzeichnisse und 0_by-name-Symlinks an.
#
# Kivitendo-Verzeichnis-Schema:
#   C{customernumber}/  -> Kunde  (neueres Format mit C-Prefix)
#   {number}/           -> Kunde (altes Format) oder Lieferant (Fallback)
#   Sonstiges           -> wird übersprungen
#
# OSERP-Ziel:
#   customers/{id}/  bzw.  vendors/{id}/
set -euo pipefail

# ============================================================
# Konfiguration
# ============================================================
KIVI_DOCS="/var/www/kivitendo-crm/dokumente/autoprofis_gmbh"
OSERP_DATA="/home/work/opensource-erp/backend/data/autoprofis_gmbh"
DB_NAME="autoprofis_gmbh"
BACKUP_BASE="/home/work/backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_BASE/oserp_data_$TIMESTAMP.tar.gz"
LOG_FILE="$BACKUP_BASE/migrate_$TIMESTAMP.log"

# Verzeichnisse die in Kivitendo vorkommen aber keine Kunden/Lieferanten sind
SKIP_PATTERN="^(A_WISSEN|C)$"

# ============================================================
# Hilfsfunktionen
# ============================================================
log()  { echo "$*" | tee -a "$LOG_FILE"; }
warn() { echo "WARNUNG: $*" | tee -a "$LOG_FILE"; }

# Entspricht fmSanitizeName() in filemanager.php:
# Alles außer a-z, A-Z, 0-9, deutschen Umlauten und - wird zu _
sanitize_name() {
    local name="$1"
    echo "$name" | sed 's/[^a-zA-Z0-9äöüÄÖÜß-]/_/g'
}

copy_docs() {
    local src="$1" dest="$2"
    mkdir -p "$dest"
    rsync -av --ignore-existing \
        --exclude='.quarantine' \
        --exclude='.tmb' \
        --exclude='.*' \
        "$src/" "$dest/" >> "$LOG_FILE" 2>&1
}

ensure_symlink() {
    local by_name_dir="$1" id="$2" name="$3"
    local safe_name link_path
    safe_name=$(sanitize_name "$name")
    [ -z "$safe_name" ] && return
    link_path="$by_name_dir/${safe_name}_${id}"

    # Alte Symlinks für diese ID entfernen (z.B. nach Namensänderung)
    find "$by_name_dir" -maxdepth 1 -name "*_${id}" -type l -delete 2>/dev/null || true

    ln -sf "../${id}" "$link_path"
}

# ============================================================
# 1. Backup
# ============================================================
mkdir -p "$BACKUP_BASE"
log "=== $(date) Backup wird erstellt ==="
tar -czf "$BACKUP_FILE" -C "$(dirname "$OSERP_DATA")" "$(basename "$OSERP_DATA")"
log "Backup: $BACKUP_FILE ($(du -sh "$BACKUP_FILE" | cut -f1))"

# ============================================================
# 2. Mapping aus Datenbank in temporäre Lookup-Dateien laden
# ============================================================
log "=== Datenbank-Mapping laden ==="

sudo -u postgres psql -d "$DB_NAME" -t -A -F'|' \
    -c "SELECT customernumber, id::text FROM customer
        WHERE customernumber IS NOT NULL AND customernumber != ''
        ORDER BY customernumber" > /tmp/kivi_cust.txt

sudo -u postgres psql -d "$DB_NAME" -t -A -F'|' \
    -c "SELECT vendornumber, id::text FROM vendor
        WHERE vendornumber IS NOT NULL AND vendornumber != ''
        ORDER BY vendornumber" > /tmp/kivi_vend.txt

log "Kunden-Einträge: $(wc -l < /tmp/kivi_cust.txt)"
log "Lieferanten-Einträge: $(wc -l < /tmp/kivi_vend.txt)"

# ============================================================
# 3. Kivitendo-Dokumente kopieren
# ============================================================
log "=== Kivitendo-Dokumente kopieren ==="
COPIED=0
NOT_FOUND=0
SKIPPED=0
CONFLICTS=0

for entry in "$KIVI_DOCS"/*/; do
    dir=$(basename "$entry")

    [ -d "$entry" ] || continue

    if [[ "$dir" =~ $SKIP_PATTERN ]]; then
        log "Überspringe: $dir"
        SKIPPED=$((SKIPPED + 1))
        continue
    fi

    if [[ "$dir" =~ ^C([0-9]+)$ ]]; then
        number="${BASH_REMATCH[1]}"
        oserp_id=$(grep "^${number}|" /tmp/kivi_cust.txt | cut -d'|' -f2 | head -1 || true)
        if [ -n "$oserp_id" ]; then
            log "Kunde  C${number} -> $oserp_id"
            copy_docs "$entry" "$OSERP_DATA/customers/$oserp_id"
            COPIED=$((COPIED + 1))
        else
            warn "C${number}: Kundennummer nicht in DB (gelöscht?)"
            NOT_FOUND=$((NOT_FOUND + 1))
        fi
        continue
    fi

    if [[ "$dir" =~ ^V([0-9]+)$ ]]; then
        number="${BASH_REMATCH[1]}"
        vend_id=$(grep "^${number}|" /tmp/kivi_vend.txt | cut -d'|' -f2 | head -1 || true)
        if [ -n "$vend_id" ]; then
            log "Lieferant  V${number} -> $vend_id"
            copy_docs "$entry" "$OSERP_DATA/vendors/$vend_id"
            COPIED=$((COPIED + 1))
        else
            warn "V${number}: Lieferantennummer nicht in DB (gelöscht?)"
            NOT_FOUND=$((NOT_FOUND + 1))
        fi
        continue
    fi

    if [[ "$dir" =~ ^[0-9]+$ ]]; then
        cust_id=$(grep "^${dir}|" /tmp/kivi_cust.txt | cut -d'|' -f2 | head -1 || true)
        vend_id=$(grep "^${dir}|" /tmp/kivi_vend.txt  | cut -d'|' -f2 | head -1 || true)

        if [ -n "$cust_id" ] && [ -n "$vend_id" ]; then
            warn "Konflikt: $dir ist sowohl Kunde ($cust_id) als auch Lieferant ($vend_id) — bitte manuell prüfen"
            CONFLICTS=$((CONFLICTS + 1))
            continue
        fi

        if [ -n "$cust_id" ]; then
            log "Kunde  ${dir} -> $cust_id"
            copy_docs "$entry" "$OSERP_DATA/customers/$cust_id"
            COPIED=$((COPIED + 1))
            continue
        fi

        if [ -n "$vend_id" ]; then
            log "Lieferant ${dir} -> $vend_id"
            copy_docs "$entry" "$OSERP_DATA/vendors/$vend_id"
            COPIED=$((COPIED + 1))
            continue
        fi

        warn "${dir}: Weder Kunden- noch Lieferantennummer gefunden (gelöscht?)"
        NOT_FOUND=$((NOT_FOUND + 1))
        continue
    fi

    log "Überspringe unbekanntes Verzeichnis: $dir"
    SKIPPED=$((SKIPPED + 1))
done

# ============================================================
# 4. Fehlende Verzeichnisse + 0_by-name-Symlinks anlegen
# ============================================================
log "=== Verzeichnisse und Symlinks sicherstellen ==="

CUST_BY_NAME="$OSERP_DATA/customers/0_by-name"
VEND_BY_NAME="$OSERP_DATA/vendors/0_by-name"
mkdir -p "$CUST_BY_NAME" "$VEND_BY_NAME"

DIR_CREATED=0
LINK_CREATED=0

# Kunden
sudo -u postgres psql -d "$DB_NAME" -t -A -F'|' \
    -c "SELECT id::text, name FROM customer
        WHERE name IS NOT NULL AND name != ''
        ORDER BY id" > /tmp/kivi_cust_names.txt

while IFS='|' read -r id name; do
    cv_dir="$OSERP_DATA/customers/$id"
    if [ ! -d "$cv_dir" ]; then
        mkdir -p "$cv_dir"
        DIR_CREATED=$((DIR_CREATED + 1))
    fi
    ensure_symlink "$CUST_BY_NAME" "$id" "$name"
    LINK_CREATED=$((LINK_CREATED + 1))
done < /tmp/kivi_cust_names.txt

# Lieferanten
sudo -u postgres psql -d "$DB_NAME" -t -A -F'|' \
    -c "SELECT id::text, name FROM vendor
        WHERE name IS NOT NULL AND name != ''
        ORDER BY id" > /tmp/kivi_vend_names.txt

while IFS='|' read -r id name; do
    cv_dir="$OSERP_DATA/vendors/$id"
    if [ ! -d "$cv_dir" ]; then
        mkdir -p "$cv_dir"
        DIR_CREATED=$((DIR_CREATED + 1))
    fi
    ensure_symlink "$VEND_BY_NAME" "$id" "$name"
    LINK_CREATED=$((LINK_CREATED + 1))
done < /tmp/kivi_vend_names.txt

# ============================================================
# 5. Zusammenfassung
# ============================================================
log ""
log "=== $(date) Fertig ==="
log "  Dokumente kopiert:      $COPIED"
log "  Nicht gefunden (Kivi):  $NOT_FOUND"
log "  Konflikte:              $CONFLICTS"
log "  Übersprungen:           $SKIPPED"
log "  Verzeichnisse angelegt: $DIR_CREATED"
log "  Symlinks angelegt:      $LINK_CREATED"
log "  Log:                    $LOG_FILE"
log "  Backup:                 $BACKUP_FILE"

rm -f /tmp/kivi_cust.txt /tmp/kivi_vend.txt /tmp/kivi_cust_names.txt /tmp/kivi_vend_names.txt
