<?php
// backend/api/parts/vendor_part_sync.php
//
// Artikelabgleich je Lieferant: Preise, Verfügbarkeit und Artikelangaben beim
// Lieferanten abholen und in parts / parts_ext übernehmen.
//
// Das Abholen selbst erledigt ein Programm ausserhalb dieses Repositorys
// (settings.ini, [vendor_part_sync] command). Hier wird nur der Lauf angelegt,
// der Läufer backend/cli/vendor-part-sync.php als eigener Prozess gestartet und
// der Stand für die Oberfläche geliefert (Lieferant › Reiter „Belegabruf").
// Die Übernahme der Daten steht in der Datenbank: vendor_part_sync_apply().

require_once __DIR__.'/../lib/mitarbeiter.php';
require_once __DIR__.'/../lib/background.php';

// Ohne Lebenszeichen des Läufers gilt ein Lauf nach so vielen Minuten als hängengeblieben
const VENDOR_PART_SYNC_STALL_MINUTES = 3;
// Preisdateien, die sich hochladen lassen
const VENDOR_PART_SYNC_UPLOAD_TYPES = ['xlsx', 'xls', 'ods', 'csv', 'txt'];

/**
 * Wahrheitswert aus dem Request lesen — per JSON kommt true/false, per
 * Formular (Aufruf mit Preisdatei) die Zeichenkette
 *
 * @param mixed $wert
 * @return bool
 */
function vendorPartSyncBool($wert): bool {
    return in_array($wert, [true, 1, '1', 'true', 't', 'on'], true);
}

/**
 * Prozentangabe aus dem Request: leer = keine Angabe, Komma erlaubt
 *
 * @param mixed $wert
 * @return string|null Zahl als Zeichenkette oder null
 */
function vendorPartSyncPercent($wert): ?string {
    $text = str_replace(',', '.', trim((string)($wert ?? '')));
    return ('' !== $text && is_numeric($text)) ? $text : null;
}

/**
 * Einstellungen des Abrufs aus dem Request als JSON-Text — per JSON kommt ein
 * Objekt, per Formular (Aufruf mit Preisdatei) dessen Text
 *
 * @param mixed $wert
 * @return string|null JSON-Objekt als Text oder null
 */
function vendorPartSyncSettings($wert): ?string {
    if (is_string($wert)) {
        $wert = json_decode($wert, true);
    }
    return (is_array($wert) && $wert) ? json_encode($wert, JSON_UNESCAPED_UNICODE) : null;
}

/**
 * Ist das Abrufprogramm eingerichtet und ausführbar?
 *
 * @return bool
 */
function vendorPartSyncConfigured(): bool {
    $befehl = defined('VENDOR_PART_SYNC_COMMAND') ? VENDOR_PART_SYNC_COMMAND : '';
    return '' !== $befehl && is_file($befehl) && is_executable($befehl);
}

/**
 * Arbeitsdateien eines Laufs in backend/tmp
 *
 * @param string $datenbank Name der Firmen-Datenbank
 * @param int $syncId Nummer des Laufs
 * @return array out (Ausgabe des Läufers), parts (Artikelliste für das Abrufprogramm),
 *               input (hochgeladene Preisdatei, ohne Endung)
 */
function vendorPartSyncFiles(string $datenbank, int $syncId): array {
    $basis = dirname(__DIR__, 2).'/tmp/vendor-part-sync-'.preg_replace('/[^A-Za-z0-9_-]/', '_', $datenbank).'-'.$syncId;
    return ['out' => $basis.'.out', 'parts' => $basis.'.jsonl', 'input' => $basis.'.input'];
}

/**
 * Stand des Artikelabgleichs eines Lieferanten: letzter Lauf mit Bilanz und
 * Protokoll, dessen geänderte/unbekannte/fehlerhafte Artikel und die
 * vorherigen Läufe
 *
 * @param int $data['vendor_id'] Lieferant
 * @testdata {"vendor_id": 1}
 */
function getVendorPartSync($data) {
    $db = DbhCompany::begin();
    $vendorId = intval($data['vendor_id'] ?? 0);

    $query = <<<SQL
        WITH runs AS (
            SELECT s.*, e.name AS employee_name,
                   (s.status IN ('queued', 'running')
                    AND COALESCE(s.heartbeat_at, s.itime) < now() - make_interval(mins => CAST(:stall_minutes AS integer))) AS stalled
              FROM vendor_part_syncs s
              LEFT JOIN employee e ON e.id = s.employee_id
             WHERE s.vendor_id = CAST(:vendor_id AS integer)
             ORDER BY s.id DESC
             LIMIT 6
        ),
        cur AS (
            SELECT * FROM runs ORDER BY id DESC LIMIT 1
        )
        SELECT json_build_object(
            'database', current_database(),
            'parts_count', (SELECT count(DISTINCT m.parts_id) FROM makemodel m WHERE m.make = CAST(:vendor_id_parts AS integer)),
            'sync', (
                SELECT json_build_object(
                           'id', c.id,
                           'status', CASE WHEN c.stalled THEN 'stalled' ELSE c.status END,
                           'dry_run', c.dry_run,
                           'debug', c.debug,
                           'part_limit', c.part_limit,
                           'markup_percent', c.markup_percent,
                           'markup_random_percent', c.markup_random_percent,
                           'psychological_prices', c.psychological_prices,
                           'input_name', c.input_name,
                           'fetch_settings', c.fetch_settings,
                           'cancel_requested', c.cancel_requested,
                           'parts_total', c.parts_total,
                           'parts_done', c.parts_done,
                           'parts_updated', c.parts_updated,
                           'parts_unchanged', c.parts_unchanged,
                           'parts_unknown', c.parts_unknown,
                           'parts_error', c.parts_error,
                           'message', c.message,
                           'log', c.log,
                           'employee_name', c.employee_name,
                           'itime', c.itime,
                           'started_at', c.started_at,
                           'finished_at', c.finished_at
                       )
                  FROM cur c
            ),
            'items', (
                SELECT COALESCE(json_agg(json_build_object(
                           'id', i.id, 'parts_id', i.parts_id, 'partnumber', i.partnumber,
                           'description', i.description, 'result', i.result,
                           'changes', i.changes, 'message', i.message
                       ) ORDER BY i.rang, i.id DESC), '[]'::json)
                  FROM (
                      SELECT it.id, it.parts_id, it.partnumber, p.description, it.result, it.changes, it.message,
                             CASE it.result WHEN 'error' THEN 0 WHEN 'unknown' THEN 1 ELSE 2 END AS rang
                        FROM vendor_part_sync_items it
                        LEFT JOIN parts p ON p.id = it.parts_id
                       WHERE it.sync_id = (SELECT id FROM cur)
                       ORDER BY rang, it.id DESC
                       LIMIT 200
                  ) i
            ),
            'history', (
                SELECT COALESCE(json_agg(json_build_object(
                           'id', r.id,
                           'status', CASE WHEN r.stalled THEN 'stalled' ELSE r.status END,
                           'dry_run', r.dry_run,
                           'debug', r.debug,
                           'part_limit', r.part_limit,
                           'markup_percent', r.markup_percent,
                           'input_name', r.input_name,
                           'parts_done', r.parts_done,
                           'parts_updated', r.parts_updated,
                           'parts_unknown', r.parts_unknown,
                           'parts_error', r.parts_error,
                           'employee_name', r.employee_name,
                           'started_at', COALESCE(r.started_at, r.itime),
                           'finished_at', r.finished_at
                       ) ORDER BY r.id DESC), '[]'::json)
                  FROM runs r
            )
        ) AS result
        SQL;

    $zeile = $db->getOne($query, [
        ':stall_minutes'   => VENDOR_PART_SYNC_STALL_MINUTES,
        ':vendor_id'       => $vendorId,
        ':vendor_id_parts' => $vendorId,
    ]);
    $stand = json_decode($zeile['result'] ?? '{}', true) ?: [];

    // Hängengeblieben: was der Läufer zuletzt ausgegeben hat, erklärt meist warum
    $stand['output'] = [];
    if ('stalled' === ($stand['sync']['status'] ?? '')) {
        $dateien = vendorPartSyncFiles((string)($stand['database'] ?? ''), intval($stand['sync']['id']));
        if (is_file($dateien['out'])) {
            $stand['output'] = array_slice(@file($dateien['out'], FILE_IGNORE_NEW_LINES) ?: [], -20);
        }
    }
    unset($stand['database']);

    // Nur ob eingerichtet — der Pfad des Abrufprogramms bleibt auf dem Server
    $stand['configured'] = vendorPartSyncConfigured();

    resultInfo(true, '', $stand);
}

/**
 * Artikelabgleich für einen Lieferanten starten. Der Läufer arbeitet als
 * eigener Prozess weiter; den Stand liefert getVendorPartSync.
 *
 * @param int $data['vendor_id'] Lieferant
 * @param bool $data['dry_run'] Probelauf: Änderungen nur ermitteln, nichts schreiben (optional)
 * @param bool $data['debug'] ausführliches Protokoll (optional)
 * @param int $data['limit'] nur so viele Artikel abrufen, 0 oder leer = alle (optional)
 * @param number $data['markup_percent'] Aufschlag in Prozent auf den Einkaufspreis; leer = Verkaufspreis nicht daraus bilden (optional)
 * @param number $data['markup_random_percent'] Streuung des Aufschlags in Prozentpunkten (±), leer = keine (optional)
 * @param bool $data['psychological_prices'] Verkaufspreis auf eine Endung mit 8 legen (optional)
 * @param object $data['fetch_settings'] Einstellungen für das Abrufprogramm: active_hours, active_days (1 = Montag), active_from, active_to, delay_min_s, delay_max_s, price_field, available_when, update_description; was fehlt, bestimmt dessen Konfiguration (optional)
 * @param file $_FILES['file'] Preisdatei des Lieferanten (Excel, CSV); der Aufruf kommt dann als Formular (optional)
 * @testdata {"vendor_id": 1, "dry_run": true, "debug": true, "limit": 10, "markup_percent": 20, "markup_random_percent": 1, "psychological_prices": true, "fetch_settings": {"active_hours": true, "active_days": [1, 2, 3, 4, 5], "active_from": "07:00", "active_to": "18:00", "delay_min_s": 5, "delay_max_s": 15}}
 */
function startVendorPartSync($data) {
    $db = DbhCompany::begin();
    $vendorId = intval($data['vendor_id'] ?? 0);

    if (!vendorPartSyncConfigured()) {
        resultInfo(false, 'VENDOR_PART_SYNC_NOT_CONFIGURED', 'Das Abrufprogramm ist nicht eingerichtet (settings.ini, [vendor_part_sync] command).');
        return;
    }

    // Preisdatei prüfen, bevor ein Lauf angelegt wird
    $datei = $_FILES['file'] ?? null;
    $endung = '';
    if (is_array($datei) && UPLOAD_ERR_NO_FILE !== ($datei['error'] ?? UPLOAD_ERR_NO_FILE)) {
        if (UPLOAD_ERR_OK !== $datei['error'] || !is_uploaded_file($datei['tmp_name'])) {
            resultInfo(false, 'VENDOR_PART_SYNC_UPLOAD_FAILED', 'Die Preisdatei ist nicht angekommen (zu groß für die PHP-Einstellungen upload_max_filesize / post_max_size?).');
            return;
        }
        $endung = strtolower(pathinfo((string)$datei['name'], PATHINFO_EXTENSION));
        if (!in_array($endung, VENDOR_PART_SYNC_UPLOAD_TYPES, true)) {
            resultInfo(false, 'VENDOR_PART_SYNC_UPLOAD_TYPE', 'Als Preisdatei sind erlaubt: '.implode(', ', VENDOR_PART_SYNC_UPLOAD_TYPES));
            return;
        }
    } else {
        $datei = null;
    }

    // Hängengebliebene Läufe abschließen, sonst sperrten sie den Lieferanten für immer
    $db->execute(
        "UPDATE vendor_part_syncs
            SET status = 'error', message = 'STALLED', finished_at = now()
          WHERE vendor_id = :vendor_id AND status IN ('queued', 'running')
            AND COALESCE(heartbeat_at, itime) < now() - make_interval(mins => CAST(:stall_minutes AS integer))",
        [':vendor_id' => $vendorId, ':stall_minutes' => VENDOR_PART_SYNC_STALL_MINUTES]
    );

    $neu = $db->getOne(
        "INSERT INTO vendor_part_syncs (vendor_id, dry_run, debug, part_limit, markup_percent, markup_random_percent,
                                        psychological_prices, input_name, employee_id, fetch_settings)
         SELECT v.id, CAST(:dry_run AS boolean), CAST(:debug AS boolean),
                NULLIF(GREATEST(CAST(:part_limit AS integer), 0), 0),
                CAST(:markup_percent AS numeric), NULLIF(abs(CAST(:markup_random_percent AS numeric)), 0),
                CAST(:psychological_prices AS boolean), CAST(:input_name AS text), CAST(:employee_id AS integer),
                -- Nur diese Schlüssel gehen an das Abrufprogramm: Zugangsdaten
                -- und Programmpfade lassen sich von hier nicht überschreiben
                (SELECT jsonb_object_agg(e.key, e.value)
                   FROM jsonb_each(COALESCE(CAST(:fetch_settings AS jsonb), '{}'::jsonb)) e
                  WHERE e.key IN ('active_hours', 'active_days', 'active_from', 'active_to',
                                  'delay_min_s', 'delay_max_s',
                                  'price_field', 'available_when', 'update_description')
                    AND jsonb_typeof(e.value) <> 'null')
           FROM vendor v
          WHERE v.id = :vendor_id
            AND NOT EXISTS (SELECT 1 FROM vendor_part_syncs s WHERE s.vendor_id = v.id AND s.status IN ('queued', 'running'))
         RETURNING id, current_database() AS datenbank",
        [
            ':dry_run'               => vendorPartSyncBool($data['dry_run'] ?? false) ? 'true' : 'false',
            ':debug'                 => vendorPartSyncBool($data['debug'] ?? false) ? 'true' : 'false',
            ':part_limit'            => intval($data['limit'] ?? 0),
            ':markup_percent'        => vendorPartSyncPercent($data['markup_percent'] ?? null),
            ':markup_random_percent' => vendorPartSyncPercent($data['markup_random_percent'] ?? null),
            ':psychological_prices'  => vendorPartSyncBool($data['psychological_prices'] ?? false) ? 'true' : 'false',
            ':input_name'            => $datei ? mb_substr(basename((string)$datei['name']), 0, 200) : null,
            ':employee_id'           => mitarbeiterId($data),
            ':fetch_settings'        => vendorPartSyncSettings($data['fetch_settings'] ?? null),
            ':vendor_id'             => $vendorId,
        ]
    );
    if (empty($neu['id'])) {
        resultInfo(false, 'VENDOR_PART_SYNC_RUNNING', 'Für diesen Lieferanten läuft bereits ein Artikelabgleich.');
        return;
    }

    $syncId = intval($neu['id']);
    $dateien = vendorPartSyncFiles((string)$neu['datenbank'], $syncId);

    // Preisdatei unter eigenem Namen ablegen; die Endung ist geprüft, der
    // Name des Benutzers geht nicht ins Dateisystem
    if ($datei) {
        $ziel = $dateien['input'].'.'.$endung;
        if (!@move_uploaded_file($datei['tmp_name'], $ziel)) {
            $db->execute(
                "UPDATE vendor_part_syncs SET status = 'error', message = :message, finished_at = now() WHERE id = :id",
                [':message' => 'Die Preisdatei ließ sich nicht ablegen: backend/tmp ist nicht beschreibbar.', ':id' => $syncId]
            );
            resultInfo(false, 'VENDOR_PART_SYNC_UPLOAD_FAILED', 'Die Preisdatei ließ sich nicht ablegen: backend/tmp ist nicht beschreibbar.');
            return;
        }
        @chmod($ziel, 0600);
        $db->execute(
            "UPDATE vendor_part_syncs SET input_file = :input_file WHERE id = :id",
            [':input_file' => basename($ziel), ':id' => $syncId]
        );
    }
    $start = backgroundStartPhp(
        dirname(__DIR__, 2).'/cli/vendor-part-sync.php',
        ['--db='.$neu['datenbank'], '--sync='.$syncId],
        $dateien['out']
    );

    if ('' !== $start['fehler']) {
        $db->execute(
            "UPDATE vendor_part_syncs SET status = 'error', message = :message, finished_at = now() WHERE id = :id",
            [':message' => $start['fehler'], ':id' => $syncId]
        );
        resultInfo(false, 'VENDOR_PART_SYNC_START_FAILED', $start['fehler']);
        return;
    }

    resultInfo(true, '', ['sync_id' => $syncId]);
}

/**
 * Laufenden Artikelabgleich abbrechen. Der Läufer bemerkt den Wunsch beim
 * nächsten Lebenszeichen, beendet das Abrufprogramm und schließt den Lauf ab;
 * bereits übernommene Artikel bleiben übernommen.
 *
 * @param int $data['sync_id'] Nummer des Laufs
 * @testdata {"sync_id": 1}
 */
function cancelVendorPartSync($data) {
    $db = DbhCompany::begin();
    $db->execute(
        "UPDATE vendor_part_syncs SET cancel_requested = TRUE WHERE id = :id AND status IN ('queued', 'running')",
        [':id' => intval($data['sync_id'] ?? 0)]
    );
    resultInfo(true, '', ['sync_id' => intval($data['sync_id'] ?? 0)]);
}
