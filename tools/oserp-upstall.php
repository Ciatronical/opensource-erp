#!/usr/bin/env php
<?php
// tools/oserp-upstall.php

/**
 * Schema-Update der Datenbanken von der Kommandozeile — ohne Anmeldung
 *
 * Spielt die Dateien aus backend/upstall/ ein: in die Auth-Datenbank und in
 * jede Firmen-Datenbank aus auth.clients, oder nur in die eine, die --client
 * nennt. Von jeder betroffenen Datenbank entsteht vorher ein Backup.
 *
 * Nutzt dieselben Funktionen wie die Update-Ansicht im Browser
 * (backend/api/update/update.php), braucht aber weder Anmeldung noch Sitzung:
 * die Auth-Zugangsdaten stehen in backend/config/settings.ini, die der
 * Mandanten in auth.clients. Damit ist dies der Weg für alles, was über den
 * Browser nicht erreichbar ist — ein Auth-Schema, an dem schon die Anmeldung
 * scheitert, und Mandanten, bei denen sich nie ein Mitarbeiter anmeldet, etwa
 * eine Firma, die nur ihren Shop betreibt.
 *
 * Beispiele:
 *   php tools/oserp-upstall.php --dry-run
 *   php tools/oserp-upstall.php
 *   php tools/oserp-upstall.php --client 3
 *
 * Unter dem Benutzer aufrufen, unter dem auch der Webserver läuft — sonst
 * gehören die neuen Backups und Logzeilen hinterher root:
 *   sudo -u www-data php tools/oserp-upstall.php
 *
 * Optionen:
 *   --dry-run            nur anzeigen, was geschähe
 *   --client ID          nur diesen Mandanten (die Auth-Datenbank läuft mit)
 *   --wait SEKUNDEN      wie lange auf einen laufenden Lauf gewartet wird (Standard 120)
 *   --verbose            jede Einzelmeldung statt der Zusammenfassung
 *   --json               Rohergebnis als JSON, für weiterverarbeitende Skripte
 *   --settings-file NAME abweichende Datei in backend/config/ (Tests)
 *
 * Exit 0 = erfolgreich, 1 = Fehler.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Nur auf der Kommandozeile ausführbar\n");
    exit(1);
}

$opt = getopt('', [
    'dry-run', 'client:', 'wait:', 'verbose', 'json', 'settings-file:', 'help',
]);

if (false === $opt || isset($opt['help'])) {
    fwrite(STDOUT, upstallCliHilfe());
    exit(isset($opt['help']) ? 0 : 1);
}

// Muss vor config.php stehen: dort wird der Dateiname zur Konstante
if (!empty($opt['settings-file'])) {
    putenv('OSERP_SETTINGS_INI_FILE=' . $opt['settings-file']);
}

require_once __DIR__ . '/../backend/api/error.php';
require_once __DIR__ . '/../backend/api/config.php';
require_once __DIR__ . '/../backend/api/logging.php';
require_once __DIR__ . '/../backend/api/database.php';
require_once __DIR__ . '/../backend/api/session.php';
require_once __DIR__ . '/../backend/api/lib/extensions.php';
require_once __DIR__ . '/../backend/api/lib/upstall.php';
require_once __DIR__ . '/../backend/api/update/update.php';

if (OSERP_SETUP_MODE) {
    fwrite(STDERR, 'backend/config/' . SETUP_SETTINGS_INI_FILE . " fehlt — zuerst tools/oserp-setup.php ausführen\n");
    exit(1);
}

$daten = ['dry_run' => isset($opt['dry-run'])];

if (isset($opt['wait'])) {
    $daten['lock_wait'] = (int)$opt['wait'];
}

try {
    if (isset($opt['client'])) {
        $daten['client'] = (int)$opt['client'];
        $ergebnis = _updateOneDatabase($daten);
    } else {
        $ergebnis = _updateAllDatabases($daten);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Abbruch: ' . $e->getMessage() . "\n");
    exit(1);
}

if (isset($opt['json'])) {
    fwrite(STDOUT, json_encode($ergebnis, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit($ergebnis['success'] ? 0 : 1);
}

fwrite(STDOUT, upstallCliBericht($ergebnis, isset($opt['dry-run']), isset($opt['verbose'])));
exit($ergebnis['success'] ? 0 : 1);

/**
 * Der Hilfetext — der Kopfkommentar dieser Datei, ohne Rahmen
 *
 * @return string
 */
function upstallCliHilfe(): string {
    $quelltext = file_get_contents(__FILE__);
    if (!preg_match('#/\*\*(.*?)\*/#s', $quelltext, $treffer)) {
        return "Siehe Kopf von tools/oserp-upstall.php\n";
    }
    return preg_replace('/^ ?\* ?/m', '', trim($treffer[1])) . "\n";
}

/**
 * Formt das Ergebnis eines Laufs in einen lesbaren Bericht
 *
 * Unterscheidet die beiden Ergebnisformen: der Lauf über alle Datenbanken
 * bringt 'auth' und 'clients' mit, das Update eines einzelnen Mandanten die
 * Meldungen unmittelbar.
 *
 * @param array $ergebnis Rückgabe von _updateAllDatabases() oder _updateOneDatabase()
 * @param bool $dryRun Vorschaulauf
 * @param bool $ausfuehrlich Jede Einzelmeldung statt der Zusammenfassung
 * @return string
 */
function upstallCliBericht(array $ergebnis, bool $dryRun, bool $ausfuehrlich): string {
    $nutzlast = $ergebnis['payload'];
    $zeilen = [$dryRun ? 'Upstall (Vorschau)' : 'Upstall', ''];

    // Der Lauf über alle Datenbanken
    if (is_array($nutzlast) && array_key_exists('clients', $nutzlast)) {
        $zeilen[] = 'Auth-Datenbank: ' . upstallCliLaufZeile($nutzlast['auth'] ?? null);
        foreach (upstallCliDetails($nutzlast['auth'] ?? null, $ausfuehrlich) as $zeile) {
            $zeilen[] = '  ' . $zeile;
        }
        if (!empty($nutzlast['auth_backup']['filename'])) {
            $zeilen[] = '  Backup: ' . $nutzlast['auth_backup']['filename'];
        }

        $zeilen[] = '';
        $zeilen[] = 'Mandanten: ' . count($nutzlast['clients']);

        foreach ($nutzlast['clients'] as $mandant) {
            $zeilen[] = sprintf(
                '  [%s] %s (%s): %s',
                $mandant['id'],
                $mandant['name'],
                $mandant['dbname'],
                upstallCliLaufZeile($mandant['update'] ?? null)
            );
            if (!empty($mandant['extensions'])) {
                $zeilen[] = '      Verzeichnisse: ' . implode(', ', $mandant['extensions']);
            }
            foreach (upstallCliDetails($mandant['update'] ?? null, $ausfuehrlich) as $zeile) {
                $zeilen[] = '      ' . $zeile;
            }
            if (!empty($mandant['backup']['filename'])) {
                $zeilen[] = '      Backup: ' . $mandant['backup']['filename'];
            }
        }
    } elseif (is_array($nutzlast)) {
        // Einzelner Mandant
        $zeilen[] = 'Ergebnis: ' . upstallCliLaufZeile($nutzlast);
        if (!empty($nutzlast['processed_extensions'])) {
            $zeilen[] = 'Verzeichnisse: ' . implode(', ', $nutzlast['processed_extensions']);
        }
        foreach (upstallCliDetails($nutzlast, $ausfuehrlich) as $zeile) {
            $zeilen[] = '  ' . $zeile;
        }
        foreach (($nutzlast['backups'] ?? []) as $welche => $backup) {
            if (!empty($backup['filename'])) {
                $zeilen[] = '  Backup ' . $welche . ': ' . $backup['filename'];
            }
        }
    } else {
        // Fehler ohne Ergebnisdaten: der Text sagt alles (z. B. UNKNOWN_CLIENT)
        $zeilen[] = is_string($nutzlast) && '' !== $nutzlast ? $nutzlast : '';
    }

    $zeilen[] = '';
    $zeilen[] = ($ergebnis['success'] ? '' : 'FEHLER: ') . $ergebnis['text'];

    return implode("\n", $zeilen) . "\n";
}

/**
 * Kurzstand eines einzelnen Laufs: ok oder Fehler mit Anzahl
 *
 * @param array|null $lauf Ergebnis von updateDatabaseSchema()
 * @return string
 */
function upstallCliLaufZeile($lauf): string {
    if (!is_array($lauf)) {
        return 'nicht ausgeführt';
    }
    if (!empty($lauf['success'])) {
        return 'ok';
    }
    return 'FEHLER (' . count($lauf['errors'] ?? []) . ')';
}

/**
 * Meldungen eines Laufs: Fehler immer, Meldungen gezählt oder einzeln
 *
 * Die Zählung gruppiert über das Feld 'type' und kommt damit auch mit
 * Meldungsarten zurecht, die es beim Schreiben dieses Skripts noch nicht gab.
 *
 * @param array|null $lauf Ergebnis von updateDatabaseSchema()
 * @param bool $ausfuehrlich Jede Meldung einzeln ausgeben
 * @return string[]
 */
function upstallCliDetails($lauf, bool $ausfuehrlich): array {
    if (!is_array($lauf)) {
        return [];
    }

    $zeilen = [];

    foreach ($lauf['errors'] ?? [] as $fehler) {
        $zeilen[] = '- ' . $fehler;
    }

    $meldungen = $lauf['messages'] ?? [];

    if ($ausfuehrlich) {
        foreach ($meldungen as $meldung) {
            $felder = [];
            foreach ($meldung as $name => $wert) {
                if ('type' === $name || is_array($wert)) {
                    continue;
                }
                $felder[] = $name . '=' . (is_bool($wert) ? ($wert ? 'ja' : 'nein') : $wert);
            }
            $zeilen[] = '· ' . ($meldung['type'] ?? 'unbekannt')
                . ($felder ? ' ' . implode(' ', $felder) : '');
        }
        return $zeilen;
    }

    $zaehler = [];
    foreach ($meldungen as $meldung) {
        $typ = $meldung['type'] ?? 'unbekannt';
        $zaehler[$typ] = ($zaehler[$typ] ?? 0) + 1;
    }

    if ($zaehler) {
        $teile = [];
        foreach ($zaehler as $typ => $anzahl) {
            $teile[] = $anzahl . '× ' . $typ;
        }
        $zeilen[] = implode(', ', $teile);
    }

    return $zeilen;
}
