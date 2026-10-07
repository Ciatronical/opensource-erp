<?php
// backend/api/lib/directory_browser.php

/**
 * Verzeichnisauswahl für Pfadfelder
 *
 * Ein Browser kann kein Verzeichnis auf dem Server auswählen — die
 * Dateiauswahl des Browsers meint immer den Rechner des Benutzers und gibt
 * ohnehin keinen absoluten Pfad heraus. Deshalb listet der Server hier selbst
 * auf, und die Oberfläche navigiert darin.
 *
 * Für die Pfade der settings.ini. Nur für Systemadministratoren, weil die
 * Auflistung die Struktur des Servers preisgibt. Sichtbar ist nur, was
 * unterhalb einer Wurzel aus browseRootDirs() liegt. (Den Bereich für die
 * Webseiten-Verzeichnisse der Shop-Erweiterung gibt es seit 2026-10-07 nicht
 * mehr — die Webseiten liegen bei HugoCMS.)
 *
 * Versteckte Verzeichnisse (Punkt am Anfang) blendet die Auflistung aus. Wer
 * eines braucht, tippt den Pfad — die Felder bleiben frei beschreibbar.
 */

/** Mehr Einträge zeigt ein Verzeichnis nicht; darüber meldet die Antwort `truncated` */
if (!defined('BROWSE_MAX_ENTRIES')) define('BROWSE_MAX_ENTRIES', 500);

/**
 * Die Einstiegspunkte des Auswahldialogs
 *
 * Steht in der settings.ini `browse_roots`, gilt allein diese Liste. Sonst
 * werden die Wurzeln abgeleitet: die Installation und ihr Elternverzeichnis,
 * die Verzeichnisse der bereits eingetragenen Pfade und die üblichen Orte.
 * Eine Wurzel, die unter einer anderen liegt, fällt weg.
 *
 * @return array Liste absoluter, vorhandener Verzeichnisse
 */
function browseRootDirs(): array {
    $kandidaten = [];

    $eingestellt = defined('OSERP_BROWSE_ROOTS') ? trim((string)OSERP_BROWSE_ROOTS) : '';
    if ('' !== $eingestellt) {
        $kandidaten = array_map('trim', explode(',', $eingestellt));
    } else {
        // Installation und ihr Elternverzeichnis: damit ist auch ein
        // Schwesterverzeichnis erreichbar, etwa das Hugo-Programm neben dem ERP
        $installation = realpath(__DIR__.'/../../..');
        if ($installation) {
            $kandidaten[] = $installation;
            $kandidaten[] = dirname($installation);
        }

        // Verzeichnisse der eingetragenen Pfade
        foreach ([
            'OSERP_DEBUG_LOG_FILE', 'OSERP_API_LOG_FILE', 'BACKUP_BASE_DIR',
            'TELEPHONY_MONITOR_DIR',
        ] as $konstante) {
            if (!defined($konstante)) {
                continue;
            }
            $wert = trim((string)constant($konstante));
            if ('' !== $wert) {
                $kandidaten[] = dirname($wert);
            }
        }

        foreach (['/srv', '/var/www', '/mnt', '/media'] as $ort) {
            $kandidaten[] = $ort;
        }
    }

    // Auflösen, Vorhandene behalten, doppelte entfernen
    $wurzeln = [];
    foreach ($kandidaten as $kandidat) {
        $echt = ('' === $kandidat) ? false : realpath($kandidat);
        if ($echt && is_dir($echt) && is_readable($echt) && !in_array($echt, $wurzeln, true)) {
            $wurzeln[] = $echt;
        }
    }

    // Verschachtelte entfernen: liegt eine Wurzel unter einer anderen, ist sie
    // über diese ohnehin erreichbar
    $ergebnis = [];
    foreach ($wurzeln as $wurzel) {
        $enthalten = false;
        foreach ($wurzeln as $andere) {
            if ($andere !== $wurzel && str_starts_with($wurzel.'/', $andere.'/')) {
                $enthalten = true;
                break;
            }
        }
        if (!$enthalten) {
            $ergebnis[] = $wurzel;
        }
    }

    sort($ergebnis);
    return $ergebnis;
}

/**
 * Löst einen angefragten Pfad auf und prüft ihn gegen die Wurzeln
 *
 * realpath() folgt Symlinks; ein Symlink aus dem erlaubten Bereich heraus
 * scheitert deshalb an der Wurzelprüfung.
 *
 * @param string $pfad Angefragter Pfad, leer für die erste Wurzel
 * @param array $wurzeln Erlaubte Wurzeln
 * @return string Aufgelöster Pfad
 * @throws ApiError BROWSE_NO_ROOTS, BROWSE_PATH_NOT_FOUND, BROWSE_PATH_DENIED
 */
function browseResolvePath(string $pfad, array $wurzeln): string {
    if (empty($wurzeln)) {
        throw new ApiError('BROWSE_NO_ROOTS', 'Es ist kein Verzeichnis zur Auswahl freigegeben');
    }

    if ('' === trim($pfad)) {
        return $wurzeln[0];
    }

    $echt = realpath($pfad);
    if (false === $echt || !is_dir($echt)) {
        throw new ApiError('BROWSE_PATH_NOT_FOUND', 'Verzeichnis nicht gefunden: '.$pfad);
    }

    foreach ($wurzeln as $wurzel) {
        if ($echt === $wurzel || str_starts_with($echt.'/', $wurzel.'/')) {
            return $echt;
        }
    }

    throw new ApiError('BROWSE_PATH_DENIED', 'Dieses Verzeichnis steht nicht zur Auswahl: '.$echt);
}

/**
 * Der Pfad relativ zu seiner Wurzel, oder null wenn er die Wurzel selbst ist
 *
 * @param string $pfad Absoluter Pfad
 * @param string $wurzel Absolute Wurzel
 * @return string
 */
function browseRelativePath(string $pfad, string $wurzel): string {
    if ($pfad === $wurzel) {
        return '';
    }
    return str_starts_with($pfad.'/', $wurzel.'/') ? substr($pfad, strlen($wurzel) + 1) : '';
}

/**
 * Listet die Verzeichnisse (und auf Wunsch Dateien) eines Verzeichnisses
 *
 * @param string $path Verzeichnis, das gezeigt werden soll; leer für die erste Wurzel
 * @param bool $files true listet auch Dateien auf (für Felder, die eine Datei meinen)
 * @testdata {"path": "", "files": false}
 */
function browseDirectories($data) {
    $mitDateien = !empty($data['files']);

    requireSystemAdmin();
    $wurzeln = browseRootDirs();

    $aktuell = browseResolvePath((string)($data['path'] ?? ''), $wurzeln);

    // Wurzel, unter der der aktuelle Pfad liegt — für den relativen Wert und
    // um den Weg nach oben an der Wurzel enden zu lassen
    $wurzel = $wurzeln[0];
    foreach ($wurzeln as $kandidat) {
        if ($aktuell === $kandidat || str_starts_with($aktuell.'/', $kandidat.'/')) {
            $wurzel = $kandidat;
            break;
        }
    }

    $eintraege = [];
    $dateien = 0;
    $abgeschnitten = false;
    $namen = @scandir($aktuell);

    if (false === $namen) {
        throw new ApiError('BROWSE_NOT_READABLE', 'Verzeichnis nicht lesbar: '.$aktuell);
    }

    natcasesort($namen);
    foreach ($namen as $name) {
        if ('.' === $name || '..' === $name || str_starts_with($name, '.')) {
            continue;
        }

        $voll = $aktuell.'/'.$name;
        $istVerzeichnis = is_dir($voll);

        // Dateien zählen, auch wenn sie nicht aufgelistet werden: sonst hiesse
        // ein Verzeichnis ohne Unterverzeichnisse in der Oberfläche "leer",
        // obwohl Dateien darin liegen
        if (!$istVerzeichnis) {
            $dateien++;
            if (!$mitDateien) {
                continue;
            }
        }

        if (count($eintraege) >= BROWSE_MAX_ENTRIES) {
            $abgeschnitten = true;
            break;
        }

        $eintraege[] = [
            'name'     => $name,
            'path'     => $voll,
            'relative' => browseRelativePath($voll, $wurzel),
            'type'     => $istVerzeichnis ? 'dir' : 'file',
            'readable' => is_readable($voll),
            'writable' => is_writable($voll),
        ];
    }

    // Verzeichnisse zuerst, dann Dateien
    usort($eintraege, function($a, $b) {
        if ($a['type'] !== $b['type']) {
            return 'dir' === $a['type'] ? -1 : 1;
        }
        return strnatcasecmp($a['name'], $b['name']);
    });

    resultInfo(true, '', [
        'roots'     => $wurzeln,
        'root'      => $wurzel,
        'path'      => $aktuell,
        'relative'  => browseRelativePath($aktuell, $wurzel),
        'parent'    => $aktuell === $wurzel ? null : dirname($aktuell),
        'writable'  => is_writable($aktuell),
        'entries'    => $eintraege,
        'file_count' => $dateien,
        'truncated'  => $abgeschnitten,
    ]);
}
