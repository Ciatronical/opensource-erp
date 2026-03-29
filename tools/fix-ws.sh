#!/bin/bash
# tools/fix-ws.sh
# Script zum Bereinigen von Whitespace-Fehlern in Dateien
# sudo cp fix-ws.sh /usr/local/sbin/


git pull # Holen der neuesten Änderungen

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

    echo "$var"
    sed -i  -E 's/[[:space:]]*$//' "$var"  ##Leerzeichen rechts
    sed -i 's/\t/    /g' "$var"            ##Tabs to Space
    sed -i -e :a -e '/^\n*$/{$d;N;ba' -e '}' "$var" ##Letzte Leerzeile löschen
done

git add -p "$@"
git commit
git push