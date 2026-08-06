#!/usr/bin/env bash
# ==========================================================================
#  docker.sh — Docker-Stack-Helfer für OpensourceERP
# ==========================================================================
#  Zentrales Skript für alle Docker-Operationen. Abstrahiert den Pfad zur
#  docker-compose.yml und bietet komfortable Subcommands.
#
#  Aufruf:   ./scripts/docker.sh <command> [optionen]
#  Hilfe:    ./scripts/docker.sh help
#
#  Voraussetzungen:
#    - Docker Engine >= 20.10  (mit docker compose v2 Plugin)
#    - docker/.env muss existieren (Kopie von docker/.env.example)
# ==========================================================================

set -euo pipefail

# --------------------------------------------------------------------------
#  Pfade und Konstanten
# --------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPOSE_DIR="$PROJECT_ROOT/docker"
COMPOSE_FILE="$COMPOSE_DIR/docker-compose.yml"
DEMO_COMPOSE_FILE="$COMPOSE_DIR/docker-compose.demo.yml"
ENV_FILE="$COMPOSE_DIR/.env"

# Container-Namen aus STACK_NAME in .env ableiten
get_stack_name() {
    if [[ -f "$ENV_FILE" ]]; then
        local name
        name="$(grep -E '^STACK_NAME=' "$ENV_FILE" 2>/dev/null | cut -d= -f2)"
        echo "${name:-oserp}"
    else
        echo "oserp"
    fi
}
WEB_CONTAINER="$(get_stack_name)-web"
DB_CONTAINER="$(get_stack_name)-db"

# --------------------------------------------------------------------------
#  Farbausgabe
# --------------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

info()    { echo -e "${BLUE}[INFO]${NC}  $*"; }
success() { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[FEHLER]${NC} $*" >&2; }

# --------------------------------------------------------------------------
#  Hilfsfunktionen
# --------------------------------------------------------------------------

# docker compose mit korrektem Compose-File, Env-File und Projektname ausführen
dc() {
    docker compose -p "$(get_stack_name)" -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

# docker compose mit Demo-Overlay (tmpfs + Auto-Seed)
dc_demo() {
    docker compose -p "$(get_stack_name)" \
        -f "$COMPOSE_FILE" -f "$DEMO_COMPOSE_FILE" \
        --env-file "$ENV_FILE" "$@"
}

# Prüfen ob docker/.env existiert
check_env() {
    if [[ ! -f "$ENV_FILE" ]]; then
        error "docker/.env nicht gefunden!"
        echo ""
        echo "  Erstelle sie mit:"
        echo "    cp docker/.env.example docker/.env"
        echo "  Dann passe die Werte an (mindestens POSTGRES_PASSWORD)."
        echo ""
        exit 1
    fi
}

# Prüfen ob ein Container läuft
is_running() {
    docker ps --format '{{.Names}}' | grep -q "^${1}$"
}

# DB-Variablen aus .env lesen
load_db_vars() {
    # shellcheck source=/dev/null
    set -a
    source "$ENV_FILE"
    set +a
    : "${POSTGRES_USER:=postgres}"
    : "${DB_AUTH_NAME:=oserp_auth}"
    : "${DB_COMPANY_NAME:=oserp_company}"
}

# ==========================================================================
#  Subcommands
# ==========================================================================

# ------ help --------------------------------------------------------------
cmd_help() {
    echo -e "${BOLD}${CYAN}"
    echo "  OpensourceERP Docker-Helfer"
    echo -e "${NC}"
    echo -e "  ${BOLD}Aufruf:${NC} ./scripts/docker.sh <command> [optionen]"
    echo ""
    echo -e "  ${BOLD}Container starten:${NC}"
    echo "    up-db               DB-Container starten"
    echo "    up-web              Web-Container starten (baut Image wenn noetig)"
    echo "    up-all              Alle Container starten"
    echo ""
    echo -e "  ${BOLD}Container stoppen:${NC}"
    echo "    down-db             DB-Container stoppen"
    echo "    down-web            Web-Container stoppen"
    echo "    down-all            Alle Container stoppen"
    echo ""
    echo -e "  ${BOLD}Container entfernen:${NC}"
    echo "    destroy-db          DB-Container + Volume + Image loeschen"
    echo "    destroy-web         Web-Container + Image loeschen"
    echo "    destroy-all         Alles loeschen (Container, Volumes, Images)"
    echo ""
    echo -e "  ${BOLD}Komplett-Reset:${NC}"
    echo "    reset <auth> <company> [demo-daten]  Stack neu aufsetzen aus zwei Dump-Dateien (.sql oder .sql.gz)"
    echo "                                          Optional: Demo-Daten als Verzeichnis oder .tar.gz"
    echo ""
    echo -e "  ${BOLD}Datenbank:${NC}"
    echo "    dbdump <file> <db>  SQL-Datei in DB laden (z.B. dbdump schema.sql oserp_company)"
    echo "    upstall             Alle Erweiterungen aus backend/upstall/ installieren"
    echo "    psql <db>           PostgreSQL-Shell oeffnen (z.B. psql oserp_auth)"
    echo "    backup              Datenbank-Backup erstellen (beide DBs)"
    echo ""
    echo -e "  ${BOLD}Demo-Modus (tmpfs + Auto-Seed):${NC}"
    echo "                        Seed-Dateien in docker/db/init/ werden beim DB-Start eingespielt"
    echo "    demo-up             Stack mit Demo-Overlay starten (DB laeuft im tmpfs)"
    echo "    demo-restart-db     DB-Container neu starten (spielt Seed neu ein)"
    echo "    demo-idle-watch     DB nur zuruecksetzen, wenn seit DEMO_INACTIVITY_MINUTES"
    echo "                        keine HTTP-Anfrage mehr kam (fuer Host-Cron alle 2-5 min)"
    echo ""
    echo -e "  ${BOLD}Sonstiges:${NC}"
    echo "    status              Status aller Container anzeigen"
    echo "    logs [service]      Logs anzeigen (Standard: alle, z.B. 'logs web')"
    echo "    shell [service]     Shell im Container oeffnen (Standard: web)"
    echo "    help                Diese Hilfe anzeigen"
    echo ""
    echo -e "  ${BOLD}Beispiele:${NC}"
    echo "    ./scripts/docker.sh up-db"
    echo "    ./scripts/docker.sh dbdump dumps/auth.sql oserp_auth"
    echo "    ./scripts/docker.sh dbdump dumps/company.sql oserp_company"
    echo "    ./scripts/docker.sh psql oserp_company"
    echo "    ./scripts/docker.sh destroy-all"
    echo ""
}

# ------ up-db -------------------------------------------------------------
cmd_up_db() {
    check_env
    info "Starte DB-Container..."
    dc up -d db
    success "DB-Container gestartet."
}

# ------ up-web ------------------------------------------------------------
cmd_up_web() {
    check_env
    info "Starte Web-Container (baut Image wenn noetig)..."
    dc up -d --build web
    success "Web-Container gestartet."
}

# ------ up-all ------------------------------------------------------------
cmd_up_all() {
    check_env
    info "Starte alle Container..."
    dc up -d --build
    success "Alle Container gestartet."
}

# ------ down-db -----------------------------------------------------------
cmd_down_db() {
    check_env
    info "Stoppe DB-Container..."
    dc stop db
    dc rm -f db
    success "DB-Container gestoppt und entfernt."
}

# ------ down-web ----------------------------------------------------------
cmd_down_web() {
    check_env
    info "Stoppe Web-Container..."
    dc stop web
    dc rm -f web
    success "Web-Container gestoppt und entfernt."
}

# ------ down-all ----------------------------------------------------------
cmd_down_all() {
    check_env
    info "Stoppe alle Container..."
    dc down
    success "Alle Container gestoppt."
}

# ------ destroy-db --------------------------------------------------------
cmd_destroy_db() {
    check_env
    warn "ACHTUNG: DB-Container, Volume und Image werden geloescht!"
    echo ""
    read -rp "Wirklich loeschen? (ja/nein): " confirm
    if [[ "$confirm" != "ja" ]]; then
        info "Abgebrochen."
        exit 0
    fi
    echo ""
    info "Stoppe und entferne DB-Container..."
    dc stop db 2>/dev/null || true
    dc rm -f db 2>/dev/null || true
    info "Loesche DB-Volume..."
    docker volume rm "$(get_stack_name)-pg-data" 2>/dev/null || true
    info "Loesche DB-Image..."
    docker rmi "postgres:16-alpine" 2>/dev/null || true
    success "DB-Container, Volume und Image geloescht."
}

# ------ destroy-web -------------------------------------------------------
cmd_destroy_web() {
    check_env
    warn "ACHTUNG: Web-Container und Image werden geloescht!"
    echo ""
    read -rp "Wirklich loeschen? (ja/nein): " confirm
    if [[ "$confirm" != "ja" ]]; then
        info "Abgebrochen."
        exit 0
    fi
    echo ""
    info "Stoppe und entferne Web-Container..."
    dc stop web 2>/dev/null || true
    dc rm -f web 2>/dev/null || true
    info "Loesche Web-Image..."
    docker rmi "$(get_stack_name)-web:latest" 2>/dev/null || true
    success "Web-Container und Image geloescht."
}

# ------ destroy-all -------------------------------------------------------
cmd_destroy_all() {
    check_env
    warn "ACHTUNG: Alle Container, Volumes und Images werden geloescht!"
    echo ""
    read -rp "Wirklich ALLES loeschen? (ja/nein): " confirm
    if [[ "$confirm" != "ja" ]]; then
        info "Abgebrochen."
        exit 0
    fi
    echo ""
    info "Stoppe Stack und loesche Volumes und lokale Images..."
    dc down -v --rmi local
    info "Loesche postgres:16-alpine..."
    docker rmi "postgres:16-alpine" 2>/dev/null || true
    success "Stack komplett entfernt."
}

# ------ reset --------------------------------------------------------------
cmd_reset() {
    local auth_dump="${1:-}"
    local company_dump="${2:-}"
    local demo_data="${3:-}"

    if [[ -z "$auth_dump" ]] || [[ -z "$company_dump" ]]; then
        error "Zwei Dump-Dateien erforderlich!"
        echo ""
        echo "  Aufruf: ./scripts/docker.sh reset <auth-dump> <company-dump> [demo-daten]"
        echo ""
        echo "  Dumps von lokaler DB erzeugen:"
        echo "    pg_dump -U postgres --clean --if-exists oserp_auth    > auth.sql"
        echo "    pg_dump -U postgres --clean --if-exists oserp_company > company.sql"
        echo ""
        echo "  Dann: ./scripts/docker.sh reset auth.sql company.sql"
        echo ""
        echo "  Optional Demo-Daten (Verzeichnis oder .tar.gz):"
        echo "    ./scripts/docker.sh reset auth.sql company.sql /mytmp/demo-data/"
        echo "    ./scripts/docker.sh reset auth.sql company.sql /mytmp/demo-data.tar.gz"
        echo ""
        exit 1
    fi

    # Pfade aufloesen
    [[ "$auth_dump" != /* ]]    && auth_dump="$PROJECT_ROOT/$auth_dump"
    [[ "$company_dump" != /* ]] && company_dump="$PROJECT_ROOT/$company_dump"

    # .gz transparent entpacken
    if [[ "$auth_dump" == *.gz ]]; then
        info "Entpacke $(basename "$auth_dump") ..."
        gunzip -k "$auth_dump"
        auth_dump="${auth_dump%.gz}"
    fi
    if [[ "$company_dump" == *.gz ]]; then
        info "Entpacke $(basename "$company_dump") ..."
        gunzip -k "$company_dump"
        company_dump="${company_dump%.gz}"
    fi

    for f in "$auth_dump" "$company_dump"; do
        [[ -f "$f" ]] || { error "Datei nicht gefunden: $f"; exit 1; }
    done

    check_env
    load_db_vars

    # ── 1. Alles plattmachen ──
    warn "Stack wird komplett neu aufgesetzt!"
    echo ""
    read -rp "Wirklich alles loeschen und neu aufsetzen? (ja/nein): " confirm
    [[ "$confirm" == "ja" ]] || { info "Abgebrochen."; exit 0; }
    echo ""

    info "Stoppe Stack und loesche Volumes..."
    dc down -v --rmi local 2>/dev/null || true
    docker rmi "postgres:16-alpine" 2>/dev/null || true

    # ── 2. DB-Container starten und warten ──
    info "Starte DB-Container..."
    dc up -d db

    info "Warte auf PostgreSQL..."
    local retries=30
    while ! docker exec "$DB_CONTAINER" pg_isready -U "$POSTGRES_USER" -q 2>/dev/null; do
        retries=$((retries - 1))
        [[ $retries -le 0 ]] && { error "DB startet nicht!"; exit 1; }
        sleep 1
    done
    success "PostgreSQL bereit."

    # ── 3. Datenbanken anlegen ──
    info "Erstelle Datenbanken..."
    docker exec "$DB_CONTAINER" psql -U "$POSTGRES_USER" -c "CREATE DATABASE ${DB_AUTH_NAME};"
    docker exec "$DB_CONTAINER" psql -U "$POSTGRES_USER" -c "CREATE DATABASE ${DB_COMPANY_NAME};"

    # ── 4. Dumps laden ──
    info "Lade Auth-Dump..."
    docker exec -i "$DB_CONTAINER" psql -U "$POSTGRES_USER" -d "$DB_AUTH_NAME" < "$auth_dump"

    info "Lade Company-Dump..."
    docker exec -i "$DB_CONTAINER" psql -U "$POSTGRES_USER" -d "$DB_COMPANY_NAME" < "$company_dump"

    # ── 5. auth.clients fuer Docker patchen ──
    info "Passe auth.clients an Docker-Umgebung an..."
    docker exec "$DB_CONTAINER" psql -U "$POSTGRES_USER" -d "$DB_AUTH_NAME" -c \
        "UPDATE auth.clients SET dbhost = 'db', dbport = '5432', dbname = '${DB_COMPANY_NAME}', dbuser = '${POSTGRES_USER}', dbpasswd = '${POSTGRES_PASSWORD}';"

    success "Datenbanken geladen."

    # ── 6. Demo-Daten ins Projektverzeichnis kopieren (optional, vor dem Build) ──
    local dest_dir="$PROJECT_ROOT/backend/api/demo/data"
    if [[ -n "$demo_data" ]]; then
        if [[ -d "$demo_data" ]]; then
            info "Kopiere Demo-Daten ins Projektverzeichnis..."
            mkdir -p "$dest_dir"
            cp -r "$demo_data/." "$dest_dir/"
            success "Demo-Daten kopiert nach $dest_dir"

        elif [[ -f "$demo_data" ]] && [[ "$demo_data" == *.tar.gz ]]; then
            info "Entpacke Demo-Daten ins Projektverzeichnis..."
            mkdir -p "$dest_dir"
            tar -xzf "$demo_data" -C "$dest_dir"
            success "Demo-Daten entpackt nach $dest_dir"

        else
            warn "Demo-Daten nicht gefunden oder unbekanntes Format: $demo_data"
        fi
    fi

    # ── 7. Web-Container starten ──
    info "Starte Web-Container..."
    dc up -d --build web

    echo ""
    success "Stack komplett neu aufgesetzt!"
    echo ""
    echo -e "  ${CYAN}Status pruefen:${NC}  ./scripts/docker.sh status"
    echo ""
}

# ------ dbdump ------------------------------------------------------------
cmd_dbdump() {
    local file="${1:-}"
    local dbname="${2:-}"

    if [[ -z "$file" ]] || [[ -z "$dbname" ]]; then
        error "Beide Parameter erforderlich: <file> <dbname>"
        echo ""
        echo "  Aufruf: ./scripts/docker.sh dbdump <sql-datei> <datenbankname>"
        echo ""
        echo "  Beispiele:"
        echo "    ./scripts/docker.sh dbdump dumps/auth.sql oserp_auth"
        echo "    ./scripts/docker.sh dbdump dumps/company.sql oserp_company"
        echo ""
        echo "  Hinweis: Basis-Schemas (kivitendo) liegen nicht im Repository."
        echo "           OSERP-Erweiterungen danach mit 'upstall' einspielen."
        echo ""
        exit 1
    fi

    # Relativen Pfad gegen PROJECT_ROOT aufloesen
    if [[ "$file" != /* ]]; then
        file="$PROJECT_ROOT/$file"
    fi

    if [[ ! -f "$file" ]]; then
        error "Datei nicht gefunden: $file"
        exit 1
    fi

    check_env
    load_db_vars

    if ! is_running "$DB_CONTAINER"; then
        error "DB-Container laeuft nicht. Starte mit: ./scripts/docker.sh up-db"
        exit 1
    fi

    info "Lade '$file' in Datenbank '$dbname'..."
    docker exec -i "$DB_CONTAINER" psql -U "$POSTGRES_USER" -d "$dbname" < "$file"
    success "SQL-Datei erfolgreich geladen."

    # Auth-DB erkennen und Verbindungsdaten fuer Docker-Netzwerk anpassen
    local is_auth
    is_auth=$(docker exec "$DB_CONTAINER" psql -U "$POSTGRES_USER" -d "$dbname" -tAc \
        "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'auth' AND table_name = 'clients');" 2>/dev/null)

    if [[ "$is_auth" == "t" ]]; then
        info "Auth-DB erkannt — passe auth.clients an Docker-Umgebung an..."
        docker exec "$DB_CONTAINER" psql -U "$POSTGRES_USER" -d "$dbname" -c \
            "UPDATE auth.clients SET dbhost = 'db', dbport = '5432', dbname = '${DB_COMPANY_NAME}', dbuser = '${POSTGRES_USER}', dbpasswd = '${POSTGRES_PASSWORD}';"
        success "auth.clients: dbhost='db', dbname='${DB_COMPANY_NAME}', dbuser='${POSTGRES_USER}'"
    fi
}

# ------ psql --------------------------------------------------------------
cmd_psql() {
    local dbname="${1:-}"

    if [[ -z "$dbname" ]]; then
        error "Datenbankname erforderlich!"
        echo ""
        echo "  Aufruf: ./scripts/docker.sh psql <datenbankname>"
        echo ""
        echo "  Beispiele:"
        echo "    ./scripts/docker.sh psql oserp_auth"
        echo "    ./scripts/docker.sh psql oserp_company"
        echo ""
        exit 1
    fi

    check_env
    load_db_vars

    if ! is_running "$DB_CONTAINER"; then
        error "DB-Container laeuft nicht. Starte mit: ./scripts/docker.sh up-db"
        exit 1
    fi

    info "Oeffne psql-Shell fuer Datenbank '$dbname'..."
    docker exec -it "$DB_CONTAINER" psql -U "$POSTGRES_USER" -d "$dbname"
}

# ------ backup ------------------------------------------------------------
cmd_backup() {
    check_env
    load_db_vars

    if ! is_running "$DB_CONTAINER"; then
        error "DB-Container laeuft nicht. Starte mit: ./scripts/docker.sh up-db"
        exit 1
    fi

    local backup_dir="$PROJECT_ROOT/backups"
    mkdir -p "$backup_dir"
    local timestamp
    timestamp="$(date +%Y%m%d_%H%M%S)"

    info "Erstelle Backup der Auth-DB ($DB_AUTH_NAME)..."
    docker exec "$DB_CONTAINER" pg_dump -U "$POSTGRES_USER" --clean --if-exists -d "$DB_AUTH_NAME" \
        | gzip > "$backup_dir/${DB_AUTH_NAME}_${timestamp}.sql.gz"
    success "  -> backups/${DB_AUTH_NAME}_${timestamp}.sql.gz"

    info "Erstelle Backup der Company-DB ($DB_COMPANY_NAME)..."
    docker exec "$DB_CONTAINER" pg_dump -U "$POSTGRES_USER" --clean --if-exists -d "$DB_COMPANY_NAME" \
        | gzip > "$backup_dir/${DB_COMPANY_NAME}_${timestamp}.sql.gz"
    success "  -> backups/${DB_COMPANY_NAME}_${timestamp}.sql.gz"

    echo ""
    success "Backup abgeschlossen."
    echo ""
    info "Vorhandene Backups:"
    ls -lh "$backup_dir"/*.sql.gz 2>/dev/null || echo "  (keine)"
    echo ""
}

# ------ status ------------------------------------------------------------
cmd_status() {
    check_env
    echo -e "${BOLD}Container-Status:${NC}"
    echo ""
    dc ps -a
    echo ""

    # Ports anzeigen wenn Stack läuft
    if is_running "$WEB_CONTAINER"; then
        # HTTP-Port aus .env lesen
        load_db_vars
        local http_port="${WEB_HTTP_PORT:-80}"
        echo -e "${GREEN}Web erreichbar unter:${NC} http://localhost:${http_port}"
    fi

    if is_running "$DB_CONTAINER"; then
        load_db_vars
        local db_port="${DB_EXTERNAL_PORT:-5433}"
        echo -e "${GREEN}DB erreichbar unter:${NC}  localhost:${db_port}"
    fi
    echo ""
}

# ------ logs --------------------------------------------------------------
cmd_logs() {
    local service="${1:-}"
    check_env
    if [[ -n "$service" ]]; then
        dc logs -f --tail=100 "$service"
    else
        dc logs -f --tail=100
    fi
}

# ------ upstall ------------------------------------------------------------
cmd_upstall() {
    check_env
    load_db_vars

    if ! is_running "$DB_CONTAINER"; then
        error "DB-Container laeuft nicht. Starte mit: ./scripts/docker.sh up-db"
        exit 1
    fi

    local upstall_dir="$PROJECT_ROOT/backend/upstall"
    if [[ ! -d "$upstall_dir" ]]; then
        error "Verzeichnis nicht gefunden: backend/upstall/"
        exit 1
    fi

    local auth_files=()
    local company_files=()

    # SQL-Dateien nach Typ sortieren
    while IFS= read -r -d '' sqlfile; do
        local basename
        basename="$(basename "$sqlfile")"
        case "$basename" in
            auth_schema.sql)    auth_files+=("$sqlfile") ;;
            company_schema.sql) company_files+=("$sqlfile") ;;
        esac
    done < <(find "$upstall_dir" -type f -name "*.sql" -print0 | sort -z)

    if [[ ${#auth_files[@]} -eq 0 ]] && [[ ${#company_files[@]} -eq 0 ]]; then
        warn "Keine SQL-Dateien in backend/upstall/ gefunden."
        return
    fi

    info "Upstall: ${#auth_files[@]} Auth-Schema(s), ${#company_files[@]} Company-Schema(s)"
    echo ""

    for f in "${auth_files[@]}"; do
        local rel="${f#"$PROJECT_ROOT/"}"
        info "[$DB_AUTH_NAME] $rel"
        docker exec -i "$DB_CONTAINER" psql -U "$POSTGRES_USER" -d "$DB_AUTH_NAME" < "$f" 2>&1 | grep -i error || true
    done

    for f in "${company_files[@]}"; do
        local rel="${f#"$PROJECT_ROOT/"}"
        info "[$DB_COMPANY_NAME] $rel"
        docker exec -i "$DB_CONTAINER" psql -U "$POSTGRES_USER" -d "$DB_COMPANY_NAME" < "$f" 2>&1 | grep -i error || true
    done

    success "Upstall abgeschlossen."
}

# ------ demo-up -----------------------------------------------------------
cmd_demo_up() {
    check_env
    if [[ ! -f "$DEMO_COMPOSE_FILE" ]]; then
        error "Demo-Overlay fehlt: $DEMO_COMPOSE_FILE"
        exit 1
    fi
    if ! ls "$COMPOSE_DIR/db/init/"*.sql* >/dev/null 2>&1; then
        warn "Keine Seed-Dateien in docker/db/init/ gefunden."
        echo "  Zuerst ausfuehren: ./scripts/docker.sh generate-demo-seed"
        echo ""
        read -rp "Trotzdem starten (DB wird leer sein)? (ja/nein): " confirm
        [[ "$confirm" == "ja" ]] || { info "Abgebrochen."; exit 0; }
    fi
    info "Starte Stack im Demo-Modus (tmpfs + Auto-Seed)..."
    dc_demo up -d --build
    success "Demo-Stack laeuft. DB wird bei jedem Restart neu geseeded."
}

# ------ demo-restart-db ---------------------------------------------------
cmd_demo_restart_db() {
    check_env
    info "Restart DB-Container -> Seed wird neu eingespielt..."
    dc_demo restart db
    success "DB zurueckgesetzt."
}

# ------ demo-idle-watch ---------------------------------------------------
# Setzt die Demo-DB zurueck, wenn seit DEMO_INACTIVITY_MINUTES kein echter
# Benutzer-Request mehr bei Apache eingetroffen ist. One-Shot, gedacht fuer
# einen Host-Cron-Job alle 2-5 Minuten.
#
# Logik:
#   - Jüngste Zeile im Access-Log, die NICHT vom Healthcheck (curl/*) stammt,
#     liefert den Zeitpunkt der letzten echten Benutzeraktivitaet.
#   - Reset passiert genau einmal pro Idle-Phase: nach dem Reset wird eine
#     Marker-Datei angelegt; der naechste Reset setzt neue Aktivitaet
#     (last_user_ts > marker_mtime) voraus.
#
# Nutzung im Cron (Host, root oder User in docker-Gruppe):
#   */3 * * * * /pfad/zu/opensource-erp/scripts/docker.sh demo-idle-watch >>/var/log/oserp-demo-watch.log 2>&1
cmd_demo_idle_watch() {
    check_env
    # Schwellwert aus .env laden (Default: 20 Minuten)
    load_db_vars
    local idle_minutes="${DEMO_INACTIVITY_MINUTES:-20}"
    local idle_seconds=$(( idle_minutes * 60 ))

    if ! is_running "$WEB_CONTAINER"; then
        info "Web-Container laeuft nicht - kein Reset faellig."
        exit 0
    fi
    if ! is_running "$DB_CONTAINER"; then
        info "DB-Container laeuft nicht - kein Reset faellig."
        exit 0
    fi

    # Letzte Zeile aus HTTP- und HTTPS-Log holen, die kein curl-Healthcheck ist.
    # Existierende Logs werden per sh -c im Container ausgewertet; fehlende Dateien
    # werden via '|| true' toleriert.
    local last_line
    last_line=$(docker exec "$WEB_CONTAINER" sh -c '
        for f in /var/log/apache2/oserp_access.log /var/log/apache2/oserp_ssl_access.log; do
            [ -r "$f" ] && grep -v "\"curl/" "$f" 2>/dev/null | tail -1
        done | tail -1
    ' 2>/dev/null || true)

    if [[ -z "$last_line" ]]; then
        info "Noch keine echte Benutzeraktivitaet im Access-Log - kein Reset."
        exit 0
    fi

    # Apache-Timestamp aus Zeile extrahieren: [24/Apr/2026:13:59:27 +0200]
    local day mon year hh mm ss tz last_ts
    if [[ "$last_line" =~ \[([0-9]{2})/([A-Za-z]{3})/([0-9]{4}):([0-9]{2}):([0-9]{2}):([0-9]{2})[[:space:]]([+-][0-9]{4})\] ]]; then
        day="${BASH_REMATCH[1]}"
        mon="${BASH_REMATCH[2]}"
        year="${BASH_REMATCH[3]}"
        hh="${BASH_REMATCH[4]}"
        mm="${BASH_REMATCH[5]}"
        ss="${BASH_REMATCH[6]}"
        tz="${BASH_REMATCH[7]}"
        last_ts=$(date -d "$day $mon $year $hh:$mm:$ss $tz" +%s 2>/dev/null || echo 0)
    else
        warn "Konnte Timestamp aus Access-Log nicht parsen - kein Reset."
        exit 0
    fi

    if [[ "$last_ts" -eq 0 ]]; then
        warn "Timestamp-Umwandlung fehlgeschlagen - kein Reset."
        exit 0
    fi

    local now idle
    now=$(date +%s)
    idle=$(( now - last_ts ))

    # Marker-Datei verhindert wiederholte Resets waehrend durchgehender Idle-Phase
    local marker="$PROJECT_ROOT/docker/.demo-last-reset"
    local marker_mtime=0
    [[ -f "$marker" ]] && marker_mtime=$(stat -c %Y "$marker")

    if (( idle < idle_seconds )); then
        info "Aktiv (idle ${idle}s < ${idle_seconds}s) - kein Reset."
        exit 0
    fi

    if (( last_ts <= marker_mtime )); then
        info "Bereits zurueckgesetzt (keine neue Aktivitaet seit letztem Reset)."
        exit 0
    fi

    # Pruefen, ob das Demo-Overlay ueberhaupt geladen werden kann.
    # Sonst laeuft der Stack vermutlich im Normalmodus (ohne tmpfs) und
    # ein 'restart db' wuerde die Daten gar nicht zuruecksetzen.
    if ! dc_demo config >/dev/null 2>&1; then
        error "Demo-Overlay nicht geladen (DEMO_AUTH_DUMP/DEMO_COMPANY_DUMP fehlt?)."
        echo "  demo-idle-watch setzt den Demo-Stack voraus (./scripts/docker.sh demo-up)."
        exit 1
    fi

    info "Idle ${idle}s >= ${idle_seconds}s - starte DB-Reset..."
    if dc_demo restart db; then
        touch "$marker"
        success "Demo-DB zurueckgesetzt."
    else
        error "DB-Reset fehlgeschlagen - Marker nicht gesetzt."
        exit 1
    fi
}

# ------ shell -------------------------------------------------------------
cmd_shell() {
    local service="${1:-web}"
    check_env

    local container
    case "$service" in
        web) container="$WEB_CONTAINER" ;;
        db)  container="$DB_CONTAINER" ;;
        *)   container="$(get_stack_name)-$service" ;;
    esac

    if ! is_running "$container"; then
        error "Container '$container' laeuft nicht."
        exit 1
    fi

    info "Oeffne Shell in '$container'..."
    docker exec -it "$container" /bin/bash 2>/dev/null \
        || docker exec -it "$container" /bin/sh
}

# ==========================================================================
#  Hauptprogramm: Subcommand dispatchen
# ==========================================================================

command="${1:-help}"
shift 2>/dev/null || true

case "$command" in
    up-db)               cmd_up_db "$@" ;;
    up-web)              cmd_up_web "$@" ;;
    up-all)              cmd_up_all "$@" ;;
    down-db)             cmd_down_db "$@" ;;
    down-web)            cmd_down_web "$@" ;;
    down-all)            cmd_down_all "$@" ;;
    destroy-db)          cmd_destroy_db "$@" ;;
    destroy-web)         cmd_destroy_web "$@" ;;
    destroy-all)         cmd_destroy_all "$@" ;;
    reset)               cmd_reset "$@" ;;
    dbdump)              cmd_dbdump "$@" ;;
    upstall)             cmd_upstall "$@" ;;
    psql)                cmd_psql "$@" ;;
    backup)              cmd_backup "$@" ;;
    status)              cmd_status "$@" ;;
    logs)                cmd_logs "$@" ;;
    shell)               cmd_shell "$@" ;;
    demo-up)             cmd_demo_up "$@" ;;
    demo-restart-db)     cmd_demo_restart_db "$@" ;;
    demo-idle-watch)     cmd_demo_idle_watch "$@" ;;
    help|--help|-h) cmd_help ;;
    *)
        error "Unbekannter Befehl: $command"
        echo ""
        cmd_help
        exit 1
        ;;
esac
