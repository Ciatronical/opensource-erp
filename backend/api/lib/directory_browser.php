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
 * Der Dialog öffnet dort, wo das Feld steht — das ist nicht immer ein
 * vorhandenes Verzeichnis. Statt dann abzubrechen, zeigt er das Nächstliegende
 * und sagt, warum (notice):
 *
 *   Datei            ihr Verzeichnis (Logdatei, Programm) — ohne Hinweis
 *   gibt es nicht    das nächste vorhandene übergeordnete Verzeichnis
 *                    (missing), etwa für eine Logdatei, die erst entsteht
 *   außerhalb        die erste Wurzel (outside)
 *
 * realpath() folgt Symlinks; ein Symlink aus dem erlaubten Bereich heraus
 * landet deshalb ebenfalls bei outside.
 *
 * @param string $pfad Angefragter Pfad, leer für die erste Wurzel
 * @param array $wurzeln Erlaubte Wurzeln
 * @return array path (aufgelöst), notice (null oder ['type' => …, 'path' => …])
 * @throws ApiError BROWSE_NO_ROOTS, BROWSE_PATH_RELATIVE
 */
function browseResolvePath(string $pfad, array $wurzeln): array {
    if (empty($wurzeln)) {
        throw new ApiError('BROWSE_NO_ROOTS',
            'Kein Verzeichnis zur Auswahl freigegeben — die Einstiegspunkte (browse_roots in der settings.ini oder die abgeleiteten) gibt es auf diesem Server nicht');
    }

    $pfad = trim($pfad);
    if ('' === $pfad) {
        return ['path' => $wurzeln[0], 'notice' => null];
    }
    if (!str_starts_with($pfad, '/')) {
        throw new ApiError('BROWSE_PATH_RELATIVE', 'Kein absoluter Pfad: '.$pfad);
    }

    // Datei: ihr Verzeichnis. Gibt es den Pfad nicht, das nächste vorhandene
    // darüber — nur bis zur Wurzel des Dateisystems
    $hinweis = null;
    $kandidat = is_file($pfad) ? dirname($pfad) : $pfad;
    while (!is_dir($kandidat) && '/' !== $kandidat && '' !== $kandidat) {
        $kandidat = dirname($kandidat);
        $hinweis = ['type' => 'missing', 'path' => $pfad];
    }

    $echt = realpath($kandidat);
    if (false !== $echt) {
        foreach ($wurzeln as $wurzel) {
            if ($echt === $wurzel || str_starts_with($echt.'/', $wurzel.'/')) {
                return ['path' => $echt, 'notice' => $hinweis];
            }
        }
    }

    return ['path' => $wurzeln[0], 'notice' => ['type' => 'outside', 'path' => $pfad]];
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

    $aufgeloest = browseResolvePath((string)($data['path'] ?? ''), $wurzeln);
    $aktuell = $aufgeloest['path'];

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
        // Meist fehlen dem Benutzer des Webservers die Rechte
        $benutzer = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
        throw new ApiError('BROWSE_NOT_READABLE', 'Verzeichnis nicht lesbar'
            .('' !== $benutzer ? ' für den Benutzer '.$benutzer : '').': '.$aktuell);
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
        // Warum ein anderes Verzeichnis gezeigt wird als angefragt
        'notice'     => $aufgeloest['notice'],
    ]);
}
