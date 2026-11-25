<?php
// backend/api/lib/background.php
//
// Einen PHP-Läufer als eigenen Prozess starten, der weiterläuft, wenn die
// Anfrage längst beantwortet ist. Dasselbe Verfahren wie bei der
// Shop-Veröffentlichung (shopPublishStartBackground), hier ohne Bezug zum Shop.

/**
 * Sucht das PHP für die Kommandozeile
 *
 * Unter PHP-FPM zeigt PHP_BINARY auf php-fpm, nicht auf das PHP für die
 * Kommandozeile. Gesucht wird deshalb neben PHP_BINDIR nach php<Version> und
 * php; im eingebauten Entwicklungsserver ist PHP_BINARY bereits das richtige.
 *
 * @return string Pfad, leer wenn keines gefunden wurde
 */
function backgroundPhpCli(): string {
    $kandidaten = [];
    if (in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        $kandidaten[] = PHP_BINARY;
    }
    $kandidaten[] = PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    $kandidaten[] = PHP_BINDIR.'/php';
    $kandidaten[] = '/usr/bin/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    $kandidaten[] = '/usr/bin/php';

    foreach (array_unique(array_filter($kandidaten)) as $pfad) {
        if (is_file($pfad) && is_executable($pfad)) {
            return $pfad;
        }
    }
    return '';
}

/**
 * Startet ein PHP-Skript als eigenen Prozess und kehrt sofort zurück
 *
 * Jedes Argument wird maskiert. setsid löst den Prozess von PHP-FPM, damit er
 * weiterläuft, wenn der Arbeitsprozess der Anfrage beendet oder neu gestartet
 * wird. Geerbte Deskriptoren werden geschlossen: ein Kindprozess hielte sonst
 * die Sockets des Webservers fest, solange er läuft.
 *
 * @param string $skript Absoluter Pfad des PHP-Skripts
 * @param array $argumente Argumente des Skripts
 * @param string $ausgabe Datei für Standard- und Fehlerausgabe des Prozesses
 * @return array pid (0, wenn nicht gestartet), fehler (leer, wenn gestartet)
 */
function backgroundStartPhp(string $skript, array $argumente, string $ausgabe): array {
    if (!function_exists('shell_exec')) {
        return ['pid' => 0, 'fehler' => 'shell_exec() ist abgeschaltet — der Läufer lässt sich nicht aus der Oberfläche starten.'];
    }
    $php = backgroundPhpCli();
    if ('' === $php) {
        return ['pid' => 0, 'fehler' => 'Kein Kommandozeilen-PHP gefunden.'];
    }
    if (!is_file($skript)) {
        return ['pid' => 0, 'fehler' => 'Läufer nicht gefunden: '.basename($skript)];
    }

    $befehl = escapeshellarg($php).' '.escapeshellarg($skript);
    foreach ($argumente as $argument) {
        $befehl .= ' '.escapeshellarg((string)$argument);
    }

    $setsid = trim((string)@shell_exec('command -v setsid 2>/dev/null'));
    $bash   = trim((string)@shell_exec('command -v bash 2>/dev/null'));
    $start  = ('' !== $setsid ? 'exec '.escapeshellarg($setsid).' ' : 'exec nohup ')
            .$befehl.' > '.escapeshellarg($ausgabe).' 2>&1 < /dev/null';

    // /bin/sh (dash) kann nur Deskriptoren bis 9 schließen, deshalb bash, wenn vorhanden
    if ('' !== $bash) {
        $start = escapeshellarg($bash).' -c '.escapeshellarg(
            'for d in /proc/$$/fd/*; do n=${d##*/}; '
            .'[ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-" 2>/dev/null; done; '
            .$start
        );
    }

    $pid = (int)trim((string)@shell_exec('cd '.escapeshellarg(dirname($skript)).' && '.$start.' & echo $!'));
    if ($pid <= 0) {
        return ['pid' => 0, 'fehler' => 'Der Läufer ließ sich nicht starten.'];
    }
    return ['pid' => $pid, 'fehler' => ''];
}
