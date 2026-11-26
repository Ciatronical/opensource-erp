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
    echo "                        Init-Skripte aus docker/db/init/ spielen beim DB-Start die"
    echo "                        Dumps aus DEMO_AUTH_DUMP / DEMO_COMPANY_DUMP (docker/.env) ein"
    echo "    demo-up             Stack mit Demo-Overlay starten (DB laeuft im tmpfs)"
    echo "    demo-restart-db     DB-Container neu starten (spielt Seed neu ein)"
    echo "    demo-idle-watch     DB nur zuruecksetzen, wenn seit DEMO_INACTIVITY_MINUTES"
    echo "                        keine HTTP-Anfrage mehr kam (fuer Host-Cron alle 2-5 min)"
    echo "    demo-reseed         Seed-Dumps auf den Schema-Stand des Programms bringen"
    echo "                        (frischer Seed -> Schema-Update -> neue Dumps -> Probelauf)"
    echo "    demo-update [--force]  git pull, Web-Image neu bauen, Seeds erneuern, falls sich"
    echo "                        backend/upstall/ geändert hat (--force: in jedem Fall)"
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
# Prueft die Voraussetzungen des Demo-Overlays, bevor der Stack startet:
#   1. Init-Skripte in docker/db/init/  (*.sh, *.sql, *.sql.gz)
#   2. Dump-Dateien aus docker/.env     (werden nach /dumps/ eingehaengt)
#   3. pg_data-Volume liegt im tmpfs    (sonst greift der Auto-Seed nicht)
cmd_demo_up() {
    check_env
    if [[ ! -f "$DEMO_COMPOSE_FILE" ]]; then
        error "Demo-Overlay fehlt: $DEMO_COMPOSE_FILE"
        exit 1
    fi

    # --yes / -y: keine Rueckfragen (fuer Cron und Deploy-Skripte)
    local assume_yes=false
    [[ "${1:-}" == "--yes" || "${1:-}" == "-y" ]] && assume_yes=true
    # Ohne Terminal kann ohnehin niemand antworten
    [[ -t 0 ]] || assume_yes=true

    load_db_vars

    # ── 1. Init-Skripte ──
    # Postgres verarbeitet *.sql, *.sql.gz und *.sh alphabetisch. Die Dumps
    # selbst liegen NICHT hier, sondern werden vom Overlay nach /dumps/
    # eingehaengt und von den .sh-Skripten geladen.
    if ! compgen -G "$COMPOSE_DIR/db/init/*.sql" >/dev/null \
       && ! compgen -G "$COMPOSE_DIR/db/init/*.sql.gz" >/dev/null \
       && ! compgen -G "$COMPOSE_DIR/db/init/*.sh" >/dev/null; then
        error "Keine Init-Skripte in docker/db/init/ gefunden (*.sh, *.sql, *.sql.gz)."
        echo "  Ohne Init-Skripte startet die DB leer und der Web-Container findet"
        echo "  die Company-DB nicht."
        exit 1
    fi

    # ── 2. Dump-Dateien aus docker/.env ──
    # Fehlt eine Quelldatei, legt Docker an dieser Stelle stillschweigend ein
    # leeres Verzeichnis an — die DB bliebe leer, ohne dass es auffaellt.
    local missing=0 var path
    for var in DEMO_AUTH_DUMP DEMO_COMPANY_DUMP; do
        path="${!var:-}"
        if [[ -z "$path" ]]; then
            error "$var fehlt in docker/.env"
            missing=1
        elif [[ ! -f "$path" ]]; then
            error "$var zeigt auf eine nicht vorhandene Datei: $path"
            missing=1
        fi
    done
    # DEMO_SCAN_DATA ist optional: Das Verzeichnis wird nur von der lxcars-
    # Erweiterung gelesen. Ist es nicht gesetzt, mountet das Overlay das leere
    # docker/demo-scan-data-empty/. Nur ein gesetzter, aber falscher Pfad ist
    # ein Fehler — Docker wuerde dort sonst ein leeres Verzeichnis anlegen.
    if [[ -n "${DEMO_SCAN_DATA:-}" && ! -d "$DEMO_SCAN_DATA" ]]; then
        error "DEMO_SCAN_DATA zeigt auf ein nicht vorhandenes Verzeichnis: $DEMO_SCAN_DATA"
        missing=1
    fi
    if (( missing )); then
        echo ""
        echo "  Pfade in docker/.env korrigieren und erneut starten."
        exit 1
    fi

    # ── 3. tmpfs-Volume ──
    # Wurde der Stack einmal ohne Overlay gestartet, liegt ein normales
    # local-Volume vor. Der Auto-Seed laeuft dann nie wieder an, weil das
    # Datenverzeichnis einen Restart ueberlebt.
    local vol
    vol="$(get_stack_name)-pg-data"
    if docker volume inspect "$vol" >/dev/null 2>&1 \
       && [[ "$(docker volume inspect "$vol" --format '{{index .Options "type"}}' 2>/dev/null)" != "tmpfs" ]]; then
        warn "Volume '$vol' ist kein tmpfs-Volume."
        echo "  Der Stack lief vermutlich einmal ohne Demo-Overlay. Solange dieses"
        echo "  Volume existiert, wird die Demo-DB beim Restart NICHT zurueckgesetzt."
        echo ""
        if [[ "$assume_yes" == true ]]; then
            error "Nicht-interaktiv — bitte manuell entfernen:"
            echo "    docker volume rm $vol"
            exit 1
        fi
        read -rp "Volume jetzt entfernen und neu aufbauen? (ja/nein): " confirm
        [[ "$confirm" == "ja" ]] || { info "Abgebrochen."; exit 0; }
        dc_demo down
        docker volume rm "$vol"
        success "Volume entfernt."
    fi

    info "Starte Stack im Demo-Modus (tmpfs + Auto-Seed)..."
    dc_demo up -d --build
    success "Demo-Stack laeuft. DB wird bei jedem Restart neu geseeded."
}

# ------ php-fpm nach einem DB-Reset neu laden ------------------------------
# database.php oeffnet die PDO-Verbindungen mit PDO::ATTR_PERSISTENT => true.
# Die PHP-FPM-Worker halten sie ueber einen DB-Neustart hinweg fest und liefern
# danach "SQLSTATE[HY000]: server closed the connection unexpectedly", bis sie
# von selbst durchrotiert sind. SIGUSR2 an den Master erzwingt einen Graceful
# Restart: alle Worker werden ersetzt, damit auch alle toten Verbindungen.
#
# Gezielt der Master, denn PID 1 im Web-Container ist Apache — ein Signal
# dorthin wuerde den Webserver treffen.
reload_php_fpm() {
    if ! is_running "$WEB_CONTAINER"; then
        return 0
    fi
    if docker exec "$WEB_CONTAINER" pkill -USR2 -f 'php-fpm: master process' 2>/dev/null; then
        info "PHP-FPM neu geladen (persistente DB-Verbindungen verworfen)."
    else
        warn "PHP-FPM konnte nicht neu geladen werden - erste Requests koennen fehlschlagen."
    fi
}

# ------ demo-restart-db ---------------------------------------------------
cmd_demo_restart_db() {
    check_env
    info "Restart DB-Container -> Seed wird neu eingespielt..."
    dc_demo restart db
    reload_php_fpm
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

    # Läuft gerade eine Seed-Erneuerung, würde ein Reset mitten hinein einen
    # halben Stand in die Seed-Dateien bringen.
    if ! demo_lock 0; then
        info "Seed-Erneuerung läuft - kein Reset."
        exit 0
    fi

    info "Idle ${idle}s >= ${idle_seconds}s - starte DB-Reset..."
    if dc_demo restart db; then
        reload_php_fpm
        touch "$marker"
        success "Demo-DB zurueckgesetzt."
    else
        error "DB-Reset fehlgeschlagen - Marker nicht gesetzt."
        exit 1
    fi
}

# ------ Hilfsfunktionen der Seed-Erneuerung --------------------------------
DEMO_LOCK_FILE="$COMPOSE_DIR/.demo-lock"
DEMO_SEED_STAND_FILE="$COMPOSE_DIR/.demo-seed-stand"

# Gemeinsame Sperre von demo-idle-watch und demo-reseed. Sie hängt am
# Dateideskriptor 9 und fällt mit dem Ende des Skripts von selbst.
#   $1 = Sekunden, die auf die Sperre gewartet wird (0 = nicht warten)
demo_lock() {
    exec 9>"$DEMO_LOCK_FILE"
    if [[ "$1" == 0 ]]; then
        flock -n 9
    else
        flock -w "$1" 9
    fi
}

# Prüfsumme über backend/upstall/ — der Schema-Stand, den das Programm erwartet
demo_upstall_stand() {
    (cd "$PROJECT_ROOT/backend/upstall" \
        && find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1)
}

# Eine Abfrage im DB-Container; Ausgabe ohne Rahmen
#   $1 = Datenbank, $2 = SQL
demo_psql() {
    docker exec "$DB_CONTAINER" psql -v ON_ERROR_STOP=1 -h 127.0.0.1 \
        -U "$POSTGRES_USER" -d "$1" -Atc "$2"
}

# Wartet, bis die Company-Datenbank über TCP antwortet. Während des Seeds
# lauscht Postgres nur auf dem Unix-Socket — antwortet 127.0.0.1, sind die
# Init-Skripte durchgelaufen.
wait_demo_db() {
    local timeout="${1:-180}" waited=0
    while (( waited < timeout )); do
        if demo_psql "$DB_COMPANY_NAME" 'SELECT 1' >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
        waited=$(( waited + 2 ))
    done
    return 1
}

# DB-Container neu starten und prüfen, ob der Seed vollständig eingespielt ist
demo_fresh_seed() {
    local since log zeilen
    since=$(date +%s)
    dc_demo restart db || return 1
    if ! wait_demo_db 180; then
        error "Datenbank nach dem Neustart nicht erreichbar."
        docker logs --tail 20 "$DB_CONTAINER" 2>&1 | sed 's/^/    /' >&2 || true
        return 1
    fi
    log=$(docker logs --since "$since" "$DB_CONTAINER" 2>&1 || true)
    if grep -q 'ueberspringe' <<<"$log"; then
        error "Der Seed hat eine Dump-Datei nicht gefunden."
        return 1
    fi
    zeilen=$(grep -c '\[demo-seed\]' <<<"$log" || true)
    if (( zeilen < 4 )); then
        error "Seed unvollständig: $zeilen von 4 [demo-seed]-Zeilen im Protokoll."
        echo "  Ist das Datenvolume ein tmpfs? Start nur mit ./scripts/docker.sh demo-up" >&2
        return 1
    fi
    return 0
}

demo_is_gzip() {
    [[ "$(od -An -N2 -tx1 "$1" 2>/dev/null | tr -d ' \n')" == "1f8b" ]]
}

# Sichert eine Datenbank als SQL-Datei und prüft, ob der Dump vollständig ist
#   $1 = Datenbank, $2 = Zieldatei
demo_dump_to() {
    docker exec "$DB_CONTAINER" pg_dump -h 127.0.0.1 -U "$POSTGRES_USER" -d "$1" > "$2" || return 1
    tail -n 5 "$2" | grep -q 'PostgreSQL database dump complete'
}

# Überschreibt eine Seed-Datei an Ort und Stelle. Kein mv: Die Dumps sind als
# einzelne Dateien eingehängt, der Container sähe sonst weiter die alte Datei.
#   $1 = neue SQL-Datei, $2 = Seed-Datei
demo_write_seed() {
    if demo_is_gzip "$2"; then
        gzip -c "$1" > "$2.gz-neu" && cat "$2.gz-neu" > "$2" && rm -f "$2.gz-neu"
    else
        cat "$1" > "$2"
    fi
}

# Aufräumen beim Verlassen von demo-reseed, auch nach einem Abbruch
DEMO_RESEED_TMP=()
DEMO_RESEED_DB_DIRTY=false
demo_reseed_cleanup() {
    rm -f "${DEMO_RESEED_TMP[@]}" 2>/dev/null || true
    if [[ "$DEMO_RESEED_DB_DIRTY" == true ]]; then
        warn "Abbruch mitten im Lauf - stelle die Datenbank aus dem Seed wieder her..."
        dc_demo restart db || true
        reload_php_fpm || true
    fi
}

# ------ demo-reseed -------------------------------------------------------
# Bringt die Seed-Dumps auf den Schema-Stand des Programms:
#   1. DB-Container neu starten      -> Datenbank entspricht exakt dem alten Seed
#   2. Schema-Update im Web-Container (tools/oserp-upstall.php)
#   3. beide Datenbanken sichern, alte Seeds aufheben, Dateien überschreiben
#   4. DB-Container zur Probe neu starten; schlägt der neue Seed fehl, kommen
#      die alten Dateien zurück
#
# Meldet sich währenddessen ein Besucher an, wird der Lauf wiederholt — seine
# Daten sollen nicht im Seed landen.
cmd_demo_reseed() {
    check_env
    load_db_vars

    if ! dc_demo config >/dev/null 2>&1; then
        error "Demo-Overlay nicht ladbar (DEMO_AUTH_DUMP/DEMO_COMPANY_DUMP in docker/.env?)."
        exit 1
    fi
    local c
    for c in "$DB_CONTAINER" "$WEB_CONTAINER"; do
        if ! is_running "$c"; then
            error "Container '$c' läuft nicht. Starte mit: ./scripts/docker.sh demo-up"
            exit 1
        fi
    done

    local auth_seed="$DEMO_AUTH_DUMP" company_seed="$DEMO_COMPANY_DUMP" f
    for f in "$auth_seed" "$company_seed"; do
        if [[ ! -f "$f" || ! -w "$f" || ! -w "$(dirname "$f")" ]]; then
            error "Seed-Datei fehlt oder ist nicht beschreibbar: $f"
            exit 1
        fi
    done

    local tool="/var/www/html/tools/oserp-upstall.php"
    if ! docker exec "$WEB_CONTAINER" test -f "$tool"; then
        error "tools/oserp-upstall.php fehlt im Web-Image."
        echo "  Das Image ist älter als dieser Befehl. Neu bauen mit: ./scripts/docker.sh demo-up"
        exit 1
    fi

    if ! demo_lock 300; then
        error "Sperre nicht erhalten - läuft bereits ein Reset oder eine Seed-Erneuerung?"
        exit 1
    fi

    local stamp
    stamp="$(date +%Y%m%d-%H%M%S)"
    local auth_tmp="$auth_seed.neu-$stamp" company_tmp="$company_seed.neu-$stamp"
    DEMO_RESEED_TMP=("$auth_tmp" "$company_tmp")
    trap demo_reseed_cleanup EXIT

    local versuch fertig=false vorher nachher
    for versuch in 1 2 3; do
        info "Spiele den bisherigen Seed frisch ein (Versuch $versuch von 3)..."
        demo_fresh_seed || exit 1
        DEMO_RESEED_DB_DIRTY=true
        reload_php_fpm

        # Anmeldungen erkennt man an auth.session_oserp. Fehlt die Tabelle im
        # alten Seed noch, gab es dort auch keine Sitzungen.
        vorher=$(demo_psql "$DB_AUTH_NAME" 'SELECT count(*) FROM auth.session_oserp' 2>/dev/null || echo 0)

        info "Schema-Update..."
        if ! docker exec -u www-data "$WEB_CONTAINER" php "$tool"; then
            error "Schema-Update fehlgeschlagen - die Seed-Dateien bleiben unverändert."
            exit 1
        fi

        info "Sichere $DB_COMPANY_NAME..."
        if ! demo_dump_to "$DB_COMPANY_NAME" "$company_tmp"; then
            error "Dump von $DB_COMPANY_NAME unvollständig."
            exit 1
        fi

        nachher=$(demo_psql "$DB_AUTH_NAME" 'SELECT count(*) FROM auth.session_oserp')
        if [[ "$vorher" != "$nachher" ]]; then
            warn "Während des Laufs hat sich ein Besucher angemeldet - wiederhole."
            continue
        fi

        # Das Kennwort des Containers gehört nicht in die Seed-Datei;
        # 99-patch-clients.sh setzt es bei jedem Seed neu.
        demo_psql "$DB_AUTH_NAME" "UPDATE auth.clients SET dbpasswd = ''" >/dev/null
        info "Sichere $DB_AUTH_NAME..."
        if ! demo_dump_to "$DB_AUTH_NAME" "$auth_tmp"; then
            error "Dump von $DB_AUTH_NAME unvollständig."
            exit 1
        fi
        fertig=true
        break
    done

    if [[ "$fertig" != true ]]; then
        error "Drei Versuche durch Anmeldungen gestört - die Seed-Dateien bleiben unverändert."
        exit 1
    fi

    # Ein Dump, der auf weniger als die Hälfte schrumpft, ist kein Schema-Update
    local alt neu
    if ! demo_is_gzip "$company_seed"; then
        alt=$(stat -c %s "$company_seed")
        neu=$(stat -c %s "$company_tmp")
        if (( neu * 2 < alt )); then
            error "Neuer Company-Dump ist auffällig klein ($neu statt $alt Bytes) - Abbruch."
            exit 1
        fi
    fi

    # ── Alte Seeds aufheben, neue an Ort und Stelle schreiben ──
    cp -p "$auth_seed" "$auth_seed.reseed-$stamp"
    cp -p "$company_seed" "$company_seed.reseed-$stamp"
    demo_write_seed "$auth_tmp" "$auth_seed"
    demo_write_seed "$company_tmp" "$company_seed"

    info "Probelauf mit den neuen Seeds..."
    if ! demo_fresh_seed; then
        error "Die neuen Seeds lassen sich nicht einspielen - stelle die alten wieder her."
        cat "$auth_seed.reseed-$stamp" > "$auth_seed"
        cat "$company_seed.reseed-$stamp" > "$company_seed"
        exit 1
    fi
    DEMO_RESEED_DB_DIRTY=false
    reload_php_fpm

    # Die Datenbank ist frisch: Der Watcher muss nicht noch einmal zurücksetzen.
    touch "$COMPOSE_DIR/.demo-last-reset"
    demo_upstall_stand > "$DEMO_SEED_STAND_FILE"

    # Von den aufgehobenen Seeds bleiben die letzten fünf
    for f in "$auth_seed" "$company_seed"; do
        ls -1t "$f".reseed-* 2>/dev/null | tail -n +6 | xargs -r rm -f
    done

    success "Seeds erneuert:"
    echo "    $auth_seed"
    echo "    $company_seed"
    echo "  Vorheriger Stand: *.reseed-$stamp"
}

# ------ demo-update -------------------------------------------------------
# Aktualisiert eine Demo-Instanz in einem Zug: git pull, Web-Image neu bauen,
# Seeds erneuern — Letzteres nur, wenn sich backend/upstall/ seit der letzten
# Erneuerung geändert hat (--force erzwingt es).
#
# Nach dem Pull startet sich das Skript neu: Der Pull kann diese Datei selbst
# ändern, und bash liest ein laufendes Skript stückweise von der Platte.
cmd_demo_update() {
    check_env
    info "Hole den aktuellen Stand..."
    git -C "$PROJECT_ROOT" pull --ff-only
    exec "$SCRIPT_DIR/docker.sh" demo-update-fortsetzen "$@"
}

cmd_demo_update_fortsetzen() {
    local force=false
    [[ "${1:-}" == "--force" ]] && force=true

    cmd_demo_up --yes

    info "Warte auf den Web-Container..."
    local waited=0 status=""
    while (( waited < 300 )); do
        status=$(docker inspect -f '{{.State.Health.Status}}' "$WEB_CONTAINER" 2>/dev/null || true)
        [[ "$status" == "healthy" ]] && break
        sleep 5
        waited=$(( waited + 5 ))
    done
    if [[ "$status" != "healthy" ]]; then
        error "Web-Container wird nicht gesund (Status: ${status:-unbekannt})."
        echo "  Protokoll: docker logs --tail 50 $WEB_CONTAINER"
        exit 1
    fi

    local stand_seed=""
    [[ -f "$DEMO_SEED_STAND_FILE" ]] && stand_seed="$(cat "$DEMO_SEED_STAND_FILE")"
    if [[ "$force" != true && "$stand_seed" == "$(demo_upstall_stand)" ]]; then
        success "Demo aktualisiert. Die Seeds sind auf dem Schema-Stand - keine Erneuerung nötig."
        return 0
    fi

    cmd_demo_reseed
    success "Demo aktualisiert."
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
    demo-reseed)         cmd_demo_reseed "$@" ;;
    demo-update)         cmd_demo_update "$@" ;;
    demo-update-fortsetzen) cmd_demo_update_fortsetzen "$@" ;;
    help|--help|-h) cmd_help ;;
    *)
        error "Unbekannter Befehl: $command"
        echo ""
        cmd_help
        exit 1
        ;;
esac
