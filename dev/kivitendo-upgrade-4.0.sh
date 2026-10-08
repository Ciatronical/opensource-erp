#!/usr/bin/env bash
# =============================================================================
#  kivitendo-Upgrade auf 4.0.0 — für eine Firmendatenbank, die OSERP mitnutzt
# =============================================================================
#  WARUM: OSERP ist auf dem Datenbankschema von kivitendo 4.0.0 gebaut (die
#  Basisdumps backend/upstall/skr03|skr04 tragen release_4_0_0). Eine ältere
#  kivitendo-Firmendatenbank (hier: 3.7.0) hat z. B. invoice.tax_id nicht, und
#  die OSERP-Anmeldung endet mit "column tax_id does not exist". Das OSERP-
#  Schema-Update fasst kivitendo-Tabellen bewusst nicht an — das ist Sache von
#  kivitendos eigenem Datenbank-Upgrade. Also: kivitendo auf 4.0.0 heben,
#  dann laufen kivitendo und OSERP auf derselben, aktuellen Datenbank.
#
#  ABLAUF (idempotent, jeder Schritt prüft den Ist-Zustand):
#    1. Preflight      Repos, Zugangsdaten aus config/kivitendo.conf, Ist-Stand
#    2. Backup         pg_dump (Auth-DB + jede Firmen-DB aus auth.clients),
#                      lokale Änderungen/untracked Dateien des kivitendo-Baums
#    3. Perl-Module    installation_check.pl der ZIEL-Version in einem
#                      git-worktree — fehlende Debian-Pakete werden installiert,
#                      BEVOR der produktive Baum umgeschaltet wird
#    4. Code           git checkout <Tag> (www-data), CRM-Plugin-Zeile in
#                      js/kivi.js wieder anhängen, kivitendo-crm auf master
#                      (dort: "An Version 4.0 angepasst", Jan. 2026)
#    5. Datenbank      scripts/dbupgrade2_tool.pl --auth-db --apply=ALL und
#                      je Mandant --user/--client --apply=ALL
#    6. Apache         Neustart (FastCGI lädt den neuen Perl-Code)
#    7. Kontrolle      release_4_0_0 in schema_info jeder Firmen-DB
#
#  Aufruf (als Betriebs-User, nutzt sudo — NICHT als root):
#    ./dev/kivitendo-upgrade-4.0.sh --dry-run     # nur zeigen, nichts ändern
#    ./dev/kivitendo-upgrade-4.0.sh               # fragt einmal nach
#    ./dev/kivitendo-upgrade-4.0.sh --yes         # ohne Rückfrage
#    KIVI_TAG=release-4.1.0 ./dev/kivitendo-upgrade-4.0.sh   # anderer Stand
#    ./dev/kivitendo-upgrade-4.0.sh --no-crm      # kivitendo-crm nicht anfassen
#    ./dev/kivitendo-upgrade-4.0.sh --no-backup   # Wiederholung: Backup liegt schon vor
#
#  WÄHREND DES UPGRADES DARF NIEMAND IN KIVITENDO ARBEITEN.
#
#  Rückweg: Backup-Verzeichnis (wird am Ende ausgegeben) enthält die Dumps
#  (pg_restore -Fc) und den alten git-Stand (HEAD.txt); Code zurück per
#  "sudo -u www-data git -C /var/www/kivitendo-erp checkout <alter HEAD>".
# =============================================================================
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OSERP_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

KIVI_DIR="${KIVI_DIR:-/var/www/kivitendo-erp}"
CRM_DIR="${CRM_DIR:-/var/www/kivitendo-crm}"
KIVI_TAG="${KIVI_TAG:-release-4.0.0}"
KIVI_CONF="$KIVI_DIR/config/kivitendo.conf"
# Ziel-Tag im Schema (release-4.0.0 -> release_4_0_0)
SCHEMA_TAG="$(echo "$KIVI_TAG" | tr '.-' '__')"
# CRM-Plugin-Einbindung, die das CRM in js/kivi.js erwartet (lokale Änderung
# im kivitendo-Baum, geht beim checkout verloren und wird wieder angehängt)
CRM_JS_LINE='document.write("<script type=text/javascript src=crm/js/ERPplugins.js></script>")'

DRY_RUN=0; YES=0; WITH_CRM=1; WITH_BACKUP=1
for a in "$@"; do
    case "$a" in
        --dry-run)   DRY_RUN=1 ;;
        --yes|-y)    YES=1 ;;
        --no-crm)    WITH_CRM=0 ;;
        --no-backup) WITH_BACKUP=0 ;;
        -h|--help) sed -n '2,40p' "$0"; exit 0 ;;
        *) echo "Unbekannte Option: $a" >&2; exit 1 ;;
    esac
done

if [[ -t 1 ]]; then
    RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; BLUE=$'\033[0;34m'; BOLD=$'\033[1m'; NC=$'\033[0m'
else
    RED=; GREEN=; YELLOW=; BLUE=; BOLD=; NC=
fi
info() { echo "${BLUE}[INFO]${NC}  $*"; }
ok()   { echo "${GREEN}[OK]${NC}    $*"; }
warn() { echo "${YELLOW}[WARN]${NC}  $*"; }
err()  { echo "${RED}[FEHLER]${NC} $*" >&2; }
step() { echo; echo "${BOLD}=== $* ===${NC}"; }
todo() { echo "${YELLOW}[TODO]${NC}  $*"; }
ERRTRAP='err "Abbruch in Zeile $LINENO (letztes Kommando: $BASH_COMMAND)"'
trap "$ERRTRAP" ERR

# Alles im kivitendo-Baum läuft als dessen Eigentümer (www-data): git weigert
# sich sonst wegen "dubious ownership", und neue Dateien gehörten dem Falschen.
KIVI_OWNER=""
as_owner() { sudo -u "$KIVI_OWNER" -H "$@"; }
run() {   # führt aus, außer im Dry-Run
    if [[ $DRY_RUN -eq 1 ]]; then echo "        (dry-run) $*"; else "$@"; fi
}

# --------------------------------------------------------------------------
#  1. Preflight
# --------------------------------------------------------------------------
step "Preflight"
[[ "$(id -u)" -ne 0 ]] || { err "Bitte nicht als root, sondern als Betriebs-User (nutzt sudo)."; exit 1; }
[[ -d "$KIVI_DIR/.git" ]] || { err "$KIVI_DIR ist kein git-Repository — Upgrade per git nicht möglich."; exit 1; }
[[ -r "$KIVI_CONF" ]]     || { err "$KIVI_CONF nicht lesbar."; exit 1; }
for b in psql pg_dump perl curl; do command -v "$b" >/dev/null || { err "$b fehlt."; exit 1; }; done
KIVI_OWNER="$(stat -c '%U' "$KIVI_DIR")"
if [[ $DRY_RUN -eq 0 ]]; then
    sudo -n true 2>/dev/null || sudo -v || { err "sudo wird benötigt."; exit 1; }
fi
# Im Dry-Run ohne sudo lesen: git als aktueller User mit safe.directory
if [[ $DRY_RUN -eq 1 ]]; then
    kgit() { git -c "safe.directory=$KIVI_DIR" -c "safe.directory=$CRM_DIR" "$@"; }
else
    kgit() { as_owner git -c "safe.directory=$KIVI_DIR" -c "safe.directory=$CRM_DIR" -c user.name=kivitendo-upgrade -c user.email=upgrade@localhost "$@"; }
fi

# Zugang zur Auth-DB aus kivitendo.conf [authentication/database]
conf_val() { sed -n '/^\[authentication\/database\]/,/^\[/p' "$KIVI_CONF" | awk -F'= *' -v k="$1" '$1 ~ "^"k"[ \t]*$" {print $2}' | tr -d ' \r'; }
AUTH_HOST="$(conf_val host)"; AUTH_PORT="$(conf_val port)"; AUTH_DB="$(conf_val db)"
AUTH_USER="$(conf_val user)"; AUTH_PASS="$(conf_val password)"
[[ -n "$AUTH_DB" && -n "$AUTH_USER" ]] || { err "Auth-Datenbank nicht aus $KIVI_CONF lesbar."; exit 1; }
psql_auth() { PGPASSWORD="$AUTH_PASS" psql -h "${AUTH_HOST:-localhost}" -p "${AUTH_PORT:-5432}" -U "$AUTH_USER" -d "$AUTH_DB" -tAX "$@"; }
psql_auth -c 'select 1' >/dev/null || { err "Anmeldung an $AUTH_DB als $AUTH_USER fehlgeschlagen (Passwort in $KIVI_CONF prüfen)."; exit 1; }

# kivitendo-Login für dbupgrade2_tool (--user ist Pflicht bei --apply)
KIVI_USER="${KIVI_USER:-$(psql_auth -c 'select login from auth."user" order by id limit 1')}"
[[ -n "$KIVI_USER" ]] || { err "Kein Benutzer in $AUTH_DB.auth.user — KIVI_USER setzen."; exit 1; }

CUR_DESC="$(kgit -C "$KIVI_DIR" describe --tags --always 2>/dev/null || echo '?')"
CUR_HEAD="$(kgit -C "$KIVI_DIR" rev-parse HEAD)"
info "kivitendo:   $KIVI_DIR  (Stand $CUR_DESC, Eigentümer $KIVI_OWNER)"
info "Ziel:        $KIVI_TAG  (Schema-Tag $SCHEMA_TAG)"
info "Auth-DB:     $AUTH_DB@${AUTH_HOST:-localhost} als $AUTH_USER — Upgrade-Login '$KIVI_USER'"
if [[ -d "$CRM_DIR/.git" ]]; then
    info "CRM:         $CRM_DIR (Stand $(kgit -C "$CRM_DIR" log -1 --format='%h vom %ad' --date=short)) — $([[ $WITH_CRM -eq 1 ]] && echo 'wird auf origin/master gehoben' || echo 'bleibt unverändert (--no-crm)')"
else
    WITH_CRM=0; info "CRM:         nicht vorhanden"
fi

# Mandanten: Name, DB, Zugang (kivitendo hält die Passwörter im Klartext in auth.clients)
mapfile -t CLIENTS < <(psql_auth -F'|' -c "select id, name, coalesce(dbhost,'localhost'), coalesce(dbport,5432), dbname, dbuser, dbpasswd from auth.clients order by id")
[[ ${#CLIENTS[@]} -gt 0 ]] || { err "Keine Mandanten in auth.clients."; exit 1; }
echo "Mandanten:"
for c in "${CLIENTS[@]}"; do
    IFS='|' read -r cid cname chost cport cdb cuser cpass <<<"$c"
    have="$(PGPASSWORD="$cpass" psql -h "$chost" -p "$cport" -U "$cuser" -d "$cdb" -tAXc "select tag from schema_info where tag like 'release_%'" 2>/dev/null | sort -V | tail -n1)"
    done_tag="$(PGPASSWORD="$cpass" psql -h "$chost" -p "$cport" -U "$cuser" -d "$cdb" -tAXc "select 1 from schema_info where tag='$SCHEMA_TAG'" 2>/dev/null || true)"
    printf '  [%s] %-20s DB %-16s Schema: %s%s\n' "$cid" "$cname" "$cdb" "${have:-?}" "${done_tag:+  (bereits $SCHEMA_TAG)}"
done

echo
if [[ $DRY_RUN -eq 1 ]]; then
    warn "Dry-Run: es wird nichts geändert. Ablauf wäre: Backup -> Perl-Check -> checkout $KIVI_TAG -> DB-Upgrade -> Apache-Neustart"
elif [[ $YES -ne 1 ]]; then
    warn "Jetzt darf niemand in kivitendo arbeiten. Vorher Backup, dann Code + Datenbank auf $KIVI_TAG."
    read -r -p "Weiter? [j/N] " antwort
    [[ "$antwort" =~ ^[jJyY]$ ]] || { info "Abgebrochen."; exit 0; }
fi

# --------------------------------------------------------------------------
#  2. Backup
# --------------------------------------------------------------------------
step "Backup"
BACKUP_DIR="$OSERP_ROOT/backups/kivitendo-upgrade-$(date +%Y%m%d-%H%M%S)"
if [[ $WITH_BACKUP -eq 0 ]]; then
    BACKUP_DIR="$(ls -d "$OSERP_ROOT"/backups/kivitendo-upgrade-* 2>/dev/null | tail -n1 || true)"
    [[ -n "$BACKUP_DIR" ]] || { err "--no-backup, aber kein früheres Backup unter $OSERP_ROOT/backups/ gefunden."; exit 1; }
    warn "Kein neues Backup (--no-backup) — es gilt das letzte: $BACKUP_DIR"
elif [[ $DRY_RUN -eq 0 ]]; then
    info "Backup-Verzeichnis: $BACKUP_DIR"
    mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
    PGPASSWORD="$AUTH_PASS" pg_dump -h "${AUTH_HOST:-localhost}" -p "${AUTH_PORT:-5432}" -U "$AUTH_USER" -Fc -f "$BACKUP_DIR/$AUTH_DB.dump" "$AUTH_DB"
    ok "Auth-DB gesichert: $AUTH_DB.dump"
    for c in "${CLIENTS[@]}"; do
        IFS='|' read -r cid cname chost cport cdb cuser cpass <<<"$c"
        PGPASSWORD="$cpass" pg_dump -h "$chost" -p "$cport" -U "$cuser" -Fc -f "$BACKUP_DIR/$cdb.dump" "$cdb"
        ok "Firmen-DB gesichert: $cdb.dump ($(du -h "$BACKUP_DIR/$cdb.dump" | cut -f1))"
    done
    # Alter Code-Stand + alles, was nicht aus git kommt (Config, eigene Vorlagen, CRM-Dateien)
    { echo "kivitendo-erp $CUR_HEAD ($CUR_DESC)"; [[ $WITH_CRM -eq 1 ]] && echo "kivitendo-crm $(kgit -C "$CRM_DIR" rev-parse HEAD)"; } > "$BACKUP_DIR/HEAD.txt"
    kgit -C "$KIVI_DIR" diff > "$BACKUP_DIR/kivitendo-erp-lokale-aenderungen.diff" || true
    kgit -C "$KIVI_DIR" status --porcelain | awk '{print $2}' > "$BACKUP_DIR/kivitendo-erp-eigene-dateien.txt"
    sudo tar -C "$KIVI_DIR" -czf "$BACKUP_DIR/kivitendo-erp-eigene-dateien.tgz" config/kivitendo.conf $(cat "$BACKUP_DIR/kivitendo-erp-eigene-dateien.txt") 2>/dev/null || warn "Nicht alle eigenen Dateien gepackt (siehe eigene-dateien.txt)"
    ok "Code-Stand und eigene Dateien gesichert"
elif [[ $DRY_RUN -eq 1 ]]; then
    echo "        (dry-run) pg_dump $AUTH_DB + $(printf '%s\n' "${CLIENTS[@]}" | cut -d'|' -f5 | tr '\n' ' ')"
fi

# --------------------------------------------------------------------------
#  3. Perl-Module der Zielversion prüfen — in einem worktree, der produktive
#     Baum bleibt bis hierhin unangetastet
# --------------------------------------------------------------------------
step "Perl-Module für $KIVI_TAG"
if [[ $DRY_RUN -eq 0 ]]; then
    kgit -C "$KIVI_DIR" fetch -q --tags origin
    kgit -C "$KIVI_DIR" rev-parse -q --verify "refs/tags/$KIVI_TAG" >/dev/null || { err "Tag $KIVI_TAG nicht in origin — Tag prüfen (git tag -l 'release-4*')."; exit 1; }
    # Prüf-Worktrees eines abgebrochenen Laufs wegräumen (git löscht das Verzeichnis mit)
    for old in $(kgit -C "$KIVI_DIR" worktree list --porcelain | awk '/^worktree \/tmp\/kivi-check\./{print $2}'); do
        kgit -C "$KIVI_DIR" worktree remove --force "$old" >/dev/null 2>&1 || true
    done
    kgit -C "$KIVI_DIR" worktree prune
    # Worktree gehört www-data; der Check läuft deshalb ebenfalls als www-data
    # über den absoluten Pfad (installation_check.pl findet SL/ per FindBin).
    # mktemp legt 0700 an — nach dem chown an www-data käme der Betriebs-User
    # sonst nicht mehr an die Modulliste heran.
    WT="$(mktemp -d /tmp/kivi-check.XXXXXX)"; sudo chown "$KIVI_OWNER" "$WT"; sudo chmod 755 "$WT"
    kgit -C "$KIVI_DIR" worktree add --detach "$WT" "$KIVI_TAG" >/dev/null
    # Fehlende Module -> Debian-Pakete: aus den "NOT ok"-Zeilen des Checks und der
    # Modulliste der ZIELVERSION (SL/InstallationCheck.pm nennt je Modul das
    # Debian-Paket). Den vom Check gedruckten apt-Befehl nicht parsen — er ist
    # per Text::Wrap umgebrochen und je nach Distribution-Erkennung gar nicht da.
    missing_packages() {   # liest Check-Ausgabe von stdin, gibt Paketnamen aus
        local mod pkg
        for mod in $(grep -E '^Looking for .*NOT ok' | awk '{print $3}'); do
            pkg="$(as_owner cat "$WT/SL/InstallationCheck.pm" | { grep -E "name *=> *\"$mod\"" || true; } | grep -oE "debian *=> *'[^']+'" | sed "s/.*'\([^']*\)'.*/\1/" | head -n1)"
            if [[ -n "$pkg" ]]; then echo "$pkg"; else warn "Kein Debian-Paket für Perl-Modul $mod bekannt — per cpanm nachinstallieren" >&2; fi
        done
    }
    trap - ERR; set +e
    check_out="$(as_owner perl "$WT/scripts/installation_check.pl" 2>&1)"; check_rc=$?
    set -e; trap "$ERRTRAP" ERR
    if [[ $check_rc -ne 0 ]]; then
        echo "$check_out" | grep -E "NOT ok" | grep -v '^All' || true
        pkgs="$(echo "$check_out" | missing_packages | sort -u | tr '\n' ' ')"
        if [[ -n "${pkgs// /}" ]]; then
            info "Installiere fehlende Debian-Pakete: $pkgs"
            sudo DEBIAN_FRONTEND=noninteractive apt-get install -y $pkgs
        fi
        trap - ERR; set +e
        check_out="$(as_owner perl "$WT/scripts/installation_check.pl" 2>&1)"; check_rc=$?
        set -e; trap "$ERRTRAP" ERR
        if [[ $check_rc -ne 0 ]]; then
            echo "$check_out" | grep -E "NOT ok" | grep -v '^All' || true
            kgit -C "$KIVI_DIR" worktree remove --force "$WT" >/dev/null 2>&1 || true
            err "Pflichtmodule fehlen weiterhin (ggf. per cpanm nachinstallieren) — Abbruch VOR dem Umschalten."
            exit 1
        fi
    fi
    kgit -C "$KIVI_DIR" worktree remove --force "$WT" >/dev/null 2>&1 || true
    ok "Alle Pflichtmodule für $KIVI_TAG vorhanden"
else
    echo "        (dry-run) git fetch --tags; worktree $KIVI_TAG; perl scripts/installation_check.pl -i; fehlende Pakete per apt"
fi

# --------------------------------------------------------------------------
#  4. Code umschalten
# --------------------------------------------------------------------------
step "Code: kivitendo $KIVI_TAG"
if [[ "$CUR_DESC" == "$KIVI_TAG" ]]; then
    ok "kivitendo steht bereits auf $KIVI_TAG"
else
    # Einzige getrackte lokale Änderung ist die CRM-Zeile in js/kivi.js (im Backup
    # als Diff). Sie wird verworfen und nach dem Checkout wieder angehängt.
    # Untracked Dateien (CRM-Menüs, eigene Druckvorlagen, Config) überleben den
    # Checkout, solange die Zielversion keine gleichnamigen Dateien bringt.
    run kgit -C "$KIVI_DIR" checkout -- js/kivi.js
    run kgit -C "$KIVI_DIR" checkout --detach "$KIVI_TAG"
    [[ $DRY_RUN -eq 1 ]] || ok "kivitendo auf $KIVI_TAG"
fi
if [[ -d "$CRM_DIR" ]] && ! grep -qF 'crm/js/ERPplugins.js' "$KIVI_DIR/js/kivi.js"; then
    if [[ $DRY_RUN -eq 1 ]]; then echo "        (dry-run) CRM-Zeile an js/kivi.js anhängen"; else
        printf '\n%s\n' "$CRM_JS_LINE" | as_owner tee -a "$KIVI_DIR/js/kivi.js" >/dev/null
    fi
    [[ $DRY_RUN -eq 1 ]] || ok "CRM-Plugin-Einbindung in js/kivi.js wiederhergestellt"
fi
if [[ $WITH_CRM -eq 1 ]]; then
    if [[ $DRY_RUN -eq 0 ]]; then
        kgit -C "$CRM_DIR" fetch -q origin
        if [[ "$(kgit -C "$CRM_DIR" rev-parse HEAD)" == "$(kgit -C "$CRM_DIR" rev-parse origin/master)" ]]; then
            ok "kivitendo-crm ist bereits auf origin/master"
        else
            kgit -C "$CRM_DIR" stash --include-untracked >/dev/null 2>&1 || true
            kgit -C "$CRM_DIR" checkout -B master origin/master >/dev/null
            ok "kivitendo-crm auf origin/master ($(kgit -C "$CRM_DIR" log -1 --format='%h %ad %s' --date=short))"
            todo "CRM-Datenbank-Update läuft im CRM selbst: als Admin anmelden -> CRM -> Verwaltung -> Updatecheck"
        fi
    else
        echo "        (dry-run) git -C $CRM_DIR checkout -B master origin/master"
    fi
fi

# --------------------------------------------------------------------------
#  5. Datenbank-Upgrade (kivitendos eigenes Werkzeug, als www-data)
# --------------------------------------------------------------------------
step "Datenbank-Upgrade"
dbup() { (cd "$KIVI_DIR" && as_owner perl scripts/dbupgrade2_tool.pl "$@"); }

# Wendet "--apply=ALL" an und fängt Kollisionen mit dem OSERP-Schema ab:
# OSERP hat Tabellen/Spalten der 4.0-Dumps womöglich schon ergänzt (z. B.
# auth.session_content.auto_restore). Scheitert ein kivitendo-Skript mit
# "... already exists", ist sein Ergebnis bereits da — der Tag wird in
# schema_info als eingespielt eingetragen und der Lauf fortgesetzt. Jeder
# Fall wird ausgegeben. Andere Fehler brechen ab.
#   $1 = Kommando (dbup|dbup_auth)   $2 = psql-Aufruf für INSERT   $3 = Tabelle schema_info
#   Rest = Argumente für das Werkzeug
apply_all() {
    local cmd="$1" psqlcmd="$2" sitab="$3"; shift 3
    local out rc file tag n=0
    while :; do
        trap - ERR; set +e
        out="$($cmd "$@" 2>&1)"; rc=$?
        set -e; trap "$ERRTRAP" ERR
        echo "$out" | grep -vE "rollback ineffective|uninitialized value in sprintf" || true
        [[ $rc -eq 0 ]] && return 0
        file="$(echo "$out" | grep -oE 'The file sql/Pg-upgrade2(-auth)?/[^ ]+ containing' | awk '{print $3}' | head -n1)"
        if [[ -n "$file" ]] && echo "$out" | grep -q 'already exists'; then
            tag="$(grep -m1 -oE '@tag: *[A-Za-z0-9_]+' "$KIVI_DIR/$file" | awk -F': *' '{print $2}')"
            [[ -n "$tag" ]] || { err "Kein @tag in $file"; return 1; }
            warn "$tag: Objekt existiert bereits (vom OSERP-Schema) — als eingespielt markiert"
            $psqlcmd -c "INSERT INTO $sitab (tag, login) VALUES ('$tag', '$KIVI_USER') ON CONFLICT (tag) DO NOTHING" >/dev/null
            n=$((n+1)); [[ $n -lt 200 ]] || { err "Zu viele Kollisionen — manuell prüfen"; return 1; }
            continue
        fi
        return $rc
    done
}
# Auth-DB: der generische "--apply"-Pfad von dbupgrade2_tool.pl (Stand 4.0.0)
# taugt für --auth-db nicht — er ruft die Skripte fest unter sql/Pg-upgrade2/
# auf und liest/schreibt "schema_info" ohne Schema (also public.schema_info,
# die er selbst leer anlegt) statt auth.schema_info; Folge: "No such file"
# bzw. bereits eingespielte Skripte laufen bei jedem Aufruf erneut. Die
# Admin-Anmeldung im Browser nutzt stattdessen SL::DBUpgrade2->
# apply_admin_dbupgrade_scripts, das beides richtig macht. Eine Kopie des
# Werkzeugs (gleicher Bootstrap: Config, Auth, Locale, Request) ruft genau
# diese Routine auf; sie liegt in scripts/ (FindBin), ist untracked und wird
# danach gelöscht.
dbup_auth() {
    local tool="$KIVI_DIR/scripts/dbupgrade2_tool-auth-admin.pl" rc=0
    sed 's|^  apply_upgrade($opt_apply);$|  if ($opt_auth_db) { print "Auth-DB: ", ($dbupgrader->apply_admin_dbupgrade_scripts(0) ? "Upgrades eingespielt" : "nichts zu tun"), "\\n"; } else { apply_upgrade($opt_apply); }|' \
        "$KIVI_DIR/scripts/dbupgrade2_tool.pl" | as_owner tee "$tool" >/dev/null
    grep -q 'apply_admin_dbupgrade_scripts(0)' "$tool" || { as_owner rm -f "$tool"; err "Werkzeug-Kopie konnte nicht angepasst werden (Zeile im Original nicht gefunden)"; return 1; }
    (cd "$KIVI_DIR" && as_owner perl "$tool" --auth-db "$@") || rc=$?
    as_owner rm -f "$tool"
    # Leere public.schema_info, die der fehlerhafte Werkzeug-Pfad in der Auth-DB
    # angelegt hat, wieder entfernen (nur wenn leer).
    psql_auth -c "DO \$\$ BEGIN IF to_regclass('public.schema_info') IS NOT NULL AND (SELECT count(*) FROM public.schema_info) = 0 THEN DROP TABLE public.schema_info; END IF; END \$\$;" >/dev/null || true
    return $rc
}
# Auch der Auth-DB-Lauf bekommt --user/--client: das Werkzeug legt die Tabelle
# schema_info über das Benutzerobjekt an und stürzt ohne --user ab
# ("Can't call method create_schema_info_table on an undefined value").
FIRST_CLIENT="$(printf '%s\n' "${CLIENTS[0]}" | cut -d'|' -f1)"
if [[ $DRY_RUN -eq 1 ]]; then
    echo "        (dry-run) dbupgrade2_tool.pl --auth-db --user=$KIVI_USER --client=$FIRST_CLIENT --apply=ALL"
    for c in "${CLIENTS[@]}"; do IFS='|' read -r cid cname _ <<<"$c"; echo "        (dry-run) dbupgrade2_tool.pl --user=$KIVI_USER --client=$cid --apply=ALL   # $cname"; done
else
    apply_all dbup_auth psql_auth auth.schema_info --user="$KIVI_USER" --client="$FIRST_CLIENT" --apply=ALL
    ok "Auth-DB aktualisiert"
    for c in "${CLIENTS[@]}"; do
        IFS='|' read -r cid cname chost cport cdb cuser cpass <<<"$c"
        info "Mandant [$cid] $cname"
        psql_client() { PGPASSWORD="$cpass" psql -h "$chost" -p "$cport" -U "$cuser" -d "$cdb" -tAX "$@"; }
        apply_all dbup psql_client schema_info --user="$KIVI_USER" --client="$cid" --apply=ALL
        ok "Mandant $cname aktualisiert"
    done
fi

# --------------------------------------------------------------------------
#  6. Apache
# --------------------------------------------------------------------------
step "Apache"
run sudo systemctl restart apache2
[[ $DRY_RUN -eq 1 ]] || ok "Apache neu gestartet (FastCGI lädt $KIVI_TAG)"

# --------------------------------------------------------------------------
#  7. Kontrolle
# --------------------------------------------------------------------------
step "Kontrolle"
fail=0
for c in "${CLIENTS[@]}"; do
    IFS='|' read -r cid cname chost cport cdb cuser cpass <<<"$c"
    if [[ -n "$(PGPASSWORD="$cpass" psql -h "$chost" -p "$cport" -U "$cuser" -d "$cdb" -tAXc "select 1 from schema_info where tag='$SCHEMA_TAG'" 2>/dev/null)" ]]; then
        ok "$cdb: $SCHEMA_TAG eingespielt"
    else
        [[ $DRY_RUN -eq 1 ]] && info "$cdb: $SCHEMA_TAG noch nicht eingespielt (Dry-Run)" || { warn "$cdb: $SCHEMA_TAG FEHLT"; fail=1; }
    fi
done
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1/kivitendo/login.pl 2>/dev/null || echo 000)"
[[ "$code" =~ ^(200|302)$ ]] && ok "kivitendo-Anmeldeseite antwortet (HTTP $code)" || warn "kivitendo-Anmeldeseite: HTTP $code — sudo tail -30 /var/log/apache2/error.log"
echo
if [[ $DRY_RUN -eq 1 ]]; then
    info "Dry-Run beendet — nichts geändert."
elif [[ $fail -eq 0 ]]; then
    ok "kivitendo läuft auf $KIVI_TAG, alle Firmendatenbanken tragen $SCHEMA_TAG."
    info "Backup: $BACKUP_DIR"
    todo "In OSERP anmelden — das Schema passt jetzt zu den Basisdumps."
    todo "kivitendo 4.0 hat die alten Stilvorlagen (kivitendo.css, lx-office-erp.css) entfernt:"
    todo "  bei Benutzern mit altem Design unter Programm > Benutzereinstellungen das Design neu wählen."
    [[ $WITH_CRM -eq 1 ]] && todo "CRM: Verwaltung > Updatecheck einmal ausführen (CRM-eigene Tabellen)."
else
    err "Schema-Kontrolle fehlgeschlagen — Ausgabe von dbupgrade2_tool.pl oben prüfen. Backup: $BACKUP_DIR"
    exit 1
fi
