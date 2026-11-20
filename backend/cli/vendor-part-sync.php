#!/usr/bin/env php
<?php
/**
 * CLI-Script: Läufer des Artikelabgleichs je Lieferant
 *
 * Wird von startVendorPartSync (Lieferant › Reiter „Belegabruf") als eigener
 * Prozess gestartet. Er ruft das Abrufprogramm aus der settings.ini auf
 * ([vendor_part_sync] command), liest dessen Ausgabe zeilenweise und reicht
 * die Artikel stapelweise an vendor_part_sync_apply() weiter. Den Stand
 * schreibt er laufend nach vendor_part_syncs; die Oberfläche liest ihn dort.
 *
 * Aufruf:
 *   php backend/cli/vendor-part-sync.php --db=<firmendatenbank> --sync=<id>
 *
 * Vertrag mit dem Abrufprogramm
 * -----------------------------
 * Aufruf ohne Argumente, die Angaben stehen in der Umgebung:
 *   OSERP_VENDOR_ID, OSERP_VENDOR_NUMBER, OSERP_VENDOR_NAME   der Lieferant
 *   OSERP_PARTS_FILE   Datei mit den Artikeln dieses Lieferanten, je Zeile ein
 *                      JSON-Objekt: parts_id, partnumber, model (Artikelnummer
 *                      beim Lieferanten), description, sellprice, listprice,
 *                      lastcost, ean, obsolete
 *   OSERP_DRY_RUN      "1" im Probelauf (nichts wird geschrieben)
 *   OSERP_DEBUG        "1": ausführlich protokollieren (Schalter „Debug")
 *   OSERP_LIMIT        Begrenzung der Artikelzahl, "0" = alle; die Artikeldatei
 *                      ist bereits entsprechend gekürzt
 *   OSERP_INPUT_FILE   hochgeladene Preisdatei (Excel, CSV), leer wenn keine;
 *                      OSERP_INPUT_NAME ist ihr Name beim Benutzer
 *   OSERP_SYNC_ID      Nummer des Laufs
 *
 * Standardausgabe: je Zeile ein JSON-Objekt
 *   {"type": "part", "partnumber": "…", "model": "…", "sellprice": 12.5,
 *    "listprice": …, "lastcost": …, "description": "…", "ean": "…",
 *    "weight": …, "available": true,
 *    "ext": {"technical_data": {…}, "properties": {…}, "images": […],
 *            "downloads": {…}, "breadcrumbs": […], "extra": {…}}}
 *       Ein Artikel. partnumber oder model genügt, alles andere ist freiwillig;
 *       was fehlt, bleibt im ERP unverändert.
 *   {"type": "progress", "total": 1200, "message": "Seite 3 von 40"}
 *       Erwartete Gesamtzahl und/oder Meldung für die Oberfläche.
 *   {"type": "log", "level": "warn", "message": "…"}
 *       Zeile für das Protokoll.
 * Alles andere, auch die Fehlerausgabe, landet im Protokoll. Rückgabewert 0
 * heisst Erfolg, jeder andere Wert beendet den Lauf als Fehler. Das Programm
 * braucht keinen Zugang zur Datenbank.
 *
 * Aufschlag, Streuung und psychologische Preise rechnet nicht das
 * Abrufprogramm, sondern vendor_part_sync_apply() aus dem gelieferten
 * lastcost — das Programm liefert Einkaufspreise.
 */

if ('cli' !== PHP_SAPI) {
    fwrite(STDERR, "Nur auf der Kommandozeile.\n");
    exit(1);
}

require_once __DIR__.'/../api/config.php';
OserpConfig::init();
require_once __DIR__.'/../api/error.php';
require_once __DIR__.'/../api/logging.php';
require_once __DIR__.'/../api/database.php';

set_time_limit(0);

const VPS_BATCH_SIZE   = 100;    // Artikel je Aufruf von vendor_part_sync_apply()
const VPS_FLUSH_SECS   = 3;      // spätestens so oft Stand schreiben und Abbruchwunsch lesen
const VPS_LOG_LINE_MAX = 500;    // längere Protokollzeilen werden gekürzt
const VPS_LOG_CHARS       = 20000;   // so viel vom Ende des Protokolls bleibt am Lauf stehen
const VPS_LOG_CHARS_DEBUG = 200000;  // im Debug-Lauf entsprechend mehr

$argumente = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $treffer)) {
        $argumente[$treffer[1]] = $treffer[2] ?? true;
    }
}
$datenbank = is_string($argumente['db'] ?? null) ? $argumente['db'] : '';
$syncId    = intval($argumente['sync'] ?? 0);
if ('' === $datenbank || $syncId <= 0) {
    fwrite(STDERR, "Aufruf: php backend/cli/vendor-part-sync.php --db=<firmendatenbank> --sync=<id>\n");
    exit(1);
}

// ── Verbindung zum Mandanten ──

try {
    $authPdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_AUTH_NAME),
        DB_AUTH_USER, DB_AUTH_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $abfrage = $authPdo->prepare("SELECT dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients WHERE dbname = :dbname ORDER BY id LIMIT 1");
    $abfrage->execute([':dbname' => $datenbank]);
    $mandant = $abfrage->fetch(PDO::FETCH_ASSOC);
    if (!$mandant) {
        fwrite(STDERR, "Kein Mandant mit der Datenbank $datenbank.\n");
        exit(1);
    }
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $mandant['dbhost'], $mandant['dbport'], $mandant['dbname']),
        $mandant['dbuser'], $mandant['dbpasswd'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    DbhCompany::begin($pdo);
    $db = DbhCompany::begin();
} catch (Throwable $e) {
    fwrite(STDERR, "Die Datenbank ist nicht erreichbar: ".$e->getMessage()."\n");
    exit(1);
}

/**
 * Lauf abschließen
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $syncId Nummer des Laufs
 * @param string $status done | error | cancelled
 * @param string|null $message Meldung; null lässt die letzte Meldung des Abrufprogramms stehen
 */
function vpsFinish($db, int $syncId, string $status, ?string $message): void {
    $db->execute(
        "UPDATE vendor_part_syncs
            SET status = :status, message = COALESCE(CAST(:message AS text), message),
                finished_at = now(), heartbeat_at = now()
          WHERE id = :id",
        [':status' => $status, ':message' => $message, ':id' => $syncId]
    );
}

// ── Lauf übernehmen ──

try {
    $sync = $db->getOne(
        "UPDATE vendor_part_syncs s
            SET status = 'running', pid = :pid, started_at = now(), heartbeat_at = now()
           FROM vendor v
          WHERE s.id = :id AND s.status = 'queued' AND v.id = s.vendor_id
        RETURNING s.id, s.vendor_id, s.dry_run, s.debug, COALESCE(s.part_limit, 0) AS part_limit,
                  s.input_file, s.input_name, s.employee_id, v.vendornumber, v.name AS vendor_name",
        [':pid' => getmypid(), ':id' => $syncId]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "Der Lauf ließ sich nicht übernehmen: ".$e->getMessage()."\n");
    exit(1);
}
if (!$sync) {
    fwrite(STDERR, "Lauf $syncId wartet nicht (schon gelaufen oder unbekannt).\n");
    exit(1);
}

DbhCompany::setActor($db, $sync['employee_id'], 'cron:vendor-part-sync');

$wahr      = fn($wert) => in_array($wert, [true, 't', 'true', '1', 1], true);
$probelauf = $wahr($sync['dry_run']);
$debug     = $wahr($sync['debug']);
$grenze    = max(0, intval($sync['part_limit']));

// Hochgeladene Preisdatei: nur der eigene Dateiname in backend/tmp zählt
$eingabeDatei = '';
if ('' !== (string)($sync['input_file'] ?? '')) {
    $eingabeDatei = dirname(__DIR__).'/tmp/'.basename((string)$sync['input_file']);
}

$artikelDatei = dirname(__DIR__).'/tmp/vendor-part-sync-'.preg_replace('/[^A-Za-z0-9_-]/', '_', $datenbank).'-'.$syncId.'.jsonl';
$prozess = null;

try {
    $befehl = VENDOR_PART_SYNC_COMMAND;
    if ('' === $befehl || !is_file($befehl) || !is_executable($befehl)) {
        throw new RuntimeException('Das Abrufprogramm ist nicht eingerichtet oder nicht ausführbar (settings.ini, [vendor_part_sync] command).');
    }

    // ── Artikel dieses Lieferanten für das Abrufprogramm bereitstellen ──
    // Zuordnung über makemodel (Lieferant am Artikel); je Artikel die erste
    // Zeile dieses Lieferanten.
    $artikel = $db->getAll(
        "SELECT json_build_object(
                    'parts_id', p.id, 'partnumber', p.partnumber, 'model', m.model,
                    'description', p.description, 'sellprice', p.sellprice, 'listprice', p.listprice,
                    'lastcost', p.lastcost, 'ean', p.ean, 'obsolete', COALESCE(p.obsolete, FALSE)
                )::text AS zeile
           FROM parts p
           JOIN (SELECT DISTINCT ON (parts_id) parts_id, model
                   FROM makemodel
                  WHERE make = :vendor_id
                  ORDER BY parts_id, sortorder NULLS LAST, id) m ON m.parts_id = p.id
          ORDER BY p.partnumber
          LIMIT NULLIF(CAST(:part_limit AS integer), 0)",
        [':vendor_id' => intval($sync['vendor_id']), ':part_limit' => $grenze]
    );
    $inhalt = '';
    foreach ($artikel as $zeile) {
        $inhalt .= $zeile['zeile']."\n";
    }
    if (false === @file_put_contents($artikelDatei, $inhalt)) {
        throw new RuntimeException('Die Artikelliste ließ sich nicht schreiben: backend/tmp ist für den Webserver nicht beschreibbar.');
    }
    @chmod($artikelDatei, 0600);
    $db->execute(
        "UPDATE vendor_part_syncs SET parts_total = :total WHERE id = :id",
        [':total' => count($artikel), ':id' => $syncId]
    );
    unset($artikel, $inhalt);

    // ── Abrufprogramm starten (ohne Shell, Angaben über die Umgebung) ──
    $umgebung = array_merge(getenv(), [
        'OSERP_SYNC_ID'       => (string)$syncId,
        'OSERP_VENDOR_ID'     => (string)$sync['vendor_id'],
        'OSERP_VENDOR_NUMBER' => (string)$sync['vendornumber'],
        'OSERP_VENDOR_NAME'   => (string)$sync['vendor_name'],
        'OSERP_PARTS_FILE'    => $artikelDatei,
        'OSERP_DRY_RUN'       => $probelauf ? '1' : '0',
        'OSERP_DEBUG'         => $debug ? '1' : '0',
        'OSERP_LIMIT'         => (string)$grenze,
        'OSERP_INPUT_FILE'    => is_file($eingabeDatei) ? $eingabeDatei : '',
        'OSERP_INPUT_NAME'    => (string)($sync['input_name'] ?? ''),
    ]);
    $rohre = [];
    $prozess = proc_open([$befehl], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre, dirname($befehl), $umgebung);
    if (!is_resource($prozess)) {
        throw new RuntimeException('Das Abrufprogramm ließ sich nicht starten.');
    }
    stream_set_blocking($rohre[1], false);
    stream_set_blocking($rohre[2], false);

    $stapel     = [];      // Artikel, die noch nicht übernommen sind
    $protokoll  = '';      // neue Protokollzeilen seit dem letzten Schreiben
    $meldung    = null;    // letzte Meldung des Abrufprogramms
    $gesamt     = 0;       // vom Abrufprogramm gemeldete Gesamtzahl
    $abgebrochen = false;
    $zuletzt    = microtime(true);
    $puffer     = [1 => '', 2 => ''];

    $notieren = function (string $zeile) use (&$protokoll) {
        $protokoll .= date('H:i:s').'  '.mb_strimwidth($zeile, 0, VPS_LOG_LINE_MAX, '…')."\n";
    };

    // Eine Zeile der Standardausgabe einordnen
    $verarbeiten = function (string $zeile) use (&$stapel, &$meldung, &$gesamt, $notieren, $debug) {
        $zeile = trim($zeile);
        if ('' === $zeile) {
            return;
        }
        $daten = json_decode($zeile, true);
        // Debug: auch die Artikelzeilen wörtlich ins Protokoll
        if ($debug && is_array($daten) && 'part' === ($daten['type'] ?? '')) {
            $notieren('[part] '.$zeile);
        }
        if (!is_array($daten)) {
            $notieren($zeile);
            return;
        }
        $typ = $daten['type'] ?? (isset($daten['partnumber']) || isset($daten['model']) ? 'part' : '');
        if ('part' === $typ) {
            unset($daten['type']);
            $stapel[] = $daten;
        } elseif ('progress' === $typ) {
            if (isset($daten['total'])) {
                $gesamt = max($gesamt, intval($daten['total']));
            }
            if (isset($daten['message']) && '' !== trim((string)$daten['message'])) {
                $meldung = mb_strimwidth(trim((string)$daten['message']), 0, VPS_LOG_LINE_MAX, '…');
            }
        } elseif ('log' === $typ) {
            $notieren('['.($daten['level'] ?? 'info').'] '.(string)($daten['message'] ?? ''));
        } else {
            $notieren($zeile);
        }
    };

    // Stapel übernehmen, Stand schreiben, Abbruchwunsch lesen
    $protokollLaenge = $debug ? VPS_LOG_CHARS_DEBUG : VPS_LOG_CHARS;
    $schreiben = function () use ($db, $syncId, &$stapel, &$protokoll, &$meldung, &$gesamt, &$zuletzt, $protokollLaenge): bool {
        while ($stapel) {
            $teil = array_splice($stapel, 0, VPS_BATCH_SIZE);
            $db->getOne(
                "SELECT vendor_part_sync_apply(CAST(:id AS integer), CAST(:items AS jsonb)) AS bilanz",
                [':id' => $syncId, ':items' => json_encode($teil, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]
            );
        }
        $stand = $db->getOne(
            "UPDATE vendor_part_syncs
                SET heartbeat_at = now(),
                    parts_total  = GREATEST(parts_total, CAST(:total AS integer), parts_done),
                    message      = COALESCE(CAST(:message AS text), message),
                    log          = right(COALESCE(log, '') || CAST(:log AS text), CAST(:log_chars AS integer))
              WHERE id = :id
            RETURNING cancel_requested",
            [':total' => $gesamt, ':message' => $meldung, ':log' => $protokoll, ':log_chars' => $protokollLaenge, ':id' => $syncId]
        );
        $protokoll = '';
        $zuletzt = microtime(true);
        return in_array($stand['cancel_requested'] ?? false, [true, 't', 'true', '1', 1], true);
    };

    // ── Ausgabe lesen, bis das Abrufprogramm beide Kanäle schließt ──
    while (true) {
        $lesen = [];
        foreach ([1, 2] as $nr) {
            if (is_resource($rohre[$nr]) && !feof($rohre[$nr])) {
                $lesen[] = $rohre[$nr];
            }
        }
        if (!$lesen) {
            break;
        }
        $schreibbar = $ausnahme = null;
        if (false === @stream_select($lesen, $schreibbar, $ausnahme, 1)) {
            break;
        }
        foreach ($lesen as $rohr) {
            $nr = ($rohr === $rohre[1]) ? 1 : 2;
            $puffer[$nr] .= (string)fread($rohr, 65536);
            while (false !== ($ende = strpos($puffer[$nr], "\n"))) {
                $zeile = substr($puffer[$nr], 0, $ende);
                $puffer[$nr] = substr($puffer[$nr], $ende + 1);
                if (1 === $nr) {
                    $verarbeiten($zeile);
                } elseif ('' !== trim($zeile)) {
                    $notieren('[stderr] '.trim($zeile));
                }
            }
        }

        if (count($stapel) >= VPS_BATCH_SIZE || (microtime(true) - $zuletzt) >= VPS_FLUSH_SECS) {
            if ($schreiben()) {
                $abgebrochen = true;
                proc_terminate($prozess);
                break;
            }
        }
    }

    // Reste ohne Zeilenende
    $verarbeiten($puffer[1]);
    if ('' !== trim($puffer[2])) {
        $notieren('[stderr] '.trim($puffer[2]));
    }
    foreach ([1, 2] as $nr) {
        if (is_resource($rohre[$nr])) {
            fclose($rohre[$nr]);
        }
    }
    $rueckgabe = proc_close($prozess);
    $prozess = null;

    if ($abgebrochen) {
        // Was schon gelesen war, wird noch übernommen
        $schreiben();
        vpsFinish($db, $syncId, 'cancelled', null);
    } else {
        $schreiben();
        if (0 === $rueckgabe) {
            vpsFinish($db, $syncId, 'done', null);
        } else {
            vpsFinish($db, $syncId, 'error', 'Das Abrufprogramm endete mit Rückgabewert '.$rueckgabe.'.');
        }
    }
} catch (Throwable $e) {
    if (is_resource($prozess)) {
        proc_terminate($prozess);
    }
    try {
        vpsFinish($db, $syncId, 'error', $e->getMessage());
    } catch (Throwable $e2) {
        // Verbindung verloren — die Oberfläche meldet den Lauf nach der Frist als hängengeblieben
    }
    fwrite(STDERR, $e->getMessage()."\n");
    @unlink($artikelDatei);
    if ('' !== $eingabeDatei) {
        @unlink($eingabeDatei);
    }
    exit(1);
}

@unlink($artikelDatei);
if ('' !== $eingabeDatei) {
    @unlink($eingabeDatei);
}
exit(0);
