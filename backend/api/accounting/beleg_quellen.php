<?php
// backend/api/accounting/beleg_quellen.php
//
// Belegsuche ("Magisch Buchen"): Eingangsbelege aus Postfaechern (IMAP),
// dem WhatsApp-Eingang, Server-Ordnern und Lieferanten-Portalen holen, ueber
// die bestehende Belegpipeline (_iv_importDocument) ablegen + auslesen und als
// Buchungsvorschlag (accounting_bookings, status pending) bereitstellen.
// Gebucht wird erst per "Magisch Buchen" (magicBook), nichts automatisch.

require_once __DIR__.'/../lib/secrets.php';
require_once __DIR__.'/../email/imap.class.php';
require_once __DIR__.'/../email/mailer.php';

const BQ_MAX_FILE   = 20 * 1024 * 1024;   // wie storeAccountingDocument
const BQ_MIN_IMAGE  = 40 * 1024;          // WhatsApp: kleinere Bilder sind keine Belegfotos
const BQ_MIN_IMAGE_MAIL = 150 * 1024;     // Mail: Banner/Signaturen bleiben darunter, Belegfotos liegen darueber
const BQ_INITIAL_DAYS = 30;               // erster Lauf: so weit zurueck

/**
 * Quellen + Einstellungen der Belegsuche laden. Beim ersten Aufruf werden die
 * naheliegenden Quellen angelegt: das eingerichtete Firmenpostfach, der
 * WhatsApp-Eingang und der Ordner <projekt>/belege.
 *
 * @param int $data['vendor_id'] nur Quellen dieses Lieferanten (optional)
 * @testdata {}
 */
function getBelegQuellen($data) {
    $db = DbhCompany::begin();
    _bq_ensureDefaultSources($db, mitarbeiterId($data));

    // Optional nur die Quellen eines Lieferanten (Reiter "Belegabruf" am Lieferanten)
    $vendorFilter = intval($data['vendor_id'] ?? 0);
    $sources = $db->getAll(
        "SELECT q.id, q.type, q.name, q.active, q.config, (q.secret IS NOT NULL AND q.secret <> '') AS has_secret,
                q.vendor_id, v.name AS vendor_name, q.last_run_at, q.last_status, q.last_message, q.last_found,
                (SELECT count(*) FROM beleg_quellen_log l WHERE l.source_id = q.id AND l.result = 'imported') AS imported_total
         FROM beleg_quellen q LEFT JOIN vendor v ON v.id = q.vendor_id
         WHERE (:vid = 0 OR q.vendor_id = :vid2)
         ORDER BY q.id",
        [':vid' => $vendorFilter, ':vid2' => $vendorFilter]
    );
    foreach ($sources as &$s) {
        $s['config'] = json_decode($s['config'] ?? '{}', true) ?: [];
        $s['has_recording'] = !empty($s['config']['recording']);
        unset($s['config']['recording']);
        $s['active'] = in_array((string)$s['active'], ['1', 't', 'true'], true);
        $s['has_secret'] = in_array((string)$s['has_secret'], ['1', 't', 'true'], true);
    }
    unset($s);

    $cfg = $db->fetchKeyValue("SELECT key, value FROM defaults_oserp WHERE key IN ('belegsuche_enabled', 'belegsuche_time', 'belegsuche_last_run')");
    resultInfo(true, '', [
        'sources'  => $sources,
        'settings' => [
            'enabled'  => in_array((string)($cfg['belegsuche_enabled'] ?? ''), ['1', 't', 'true'], true),
            'time'     => $cfg['belegsuche_time'] ?? '06:00',
            'last_run' => $cfg['belegsuche_last_run'] ?? null,
            'default_folder' => _bq_defaultFolder(),
            'cron_line' => '0,10,20,30,40,50 * * * * cd ' . dirname(__DIR__, 3) . ' && php backend/cli/belegsuche.php >> log/belegsuche.log 2>&1',
        ],
    ]);
}

/**
 * Quelle anlegen/aendern. Ein leeres Passwort laesst das gespeicherte stehen.
 *
 * @param int    $data['id']        vorhandene Quelle (optional)
 * @param string $data['type']      imap | folder | whatsapp | portal
 * @param string $data['name']
 * @param bool   $data['active']
 * @param array  $data['config']    typabhaengig (s. Schema-Kommentar)
 * @param string $data['secret']    Passwort (Klartext, wird verschluesselt)
 * @param int    $data['vendor_id'] Portal: Lieferant
 * @testdata {"type": "folder", "name": "Belege-Ordner", "active": true, "config": {"path": "~/opensource-erp/belege", "move_processed": true}}
 */
function saveBelegQuelle($data) {
    $db   = DbhCompany::begin();
    $id   = intval($data['id'] ?? 0);
    $type = $data['type'] ?? '';
    $name = trim((string)($data['name'] ?? ''));
    if (!in_array($type, ['imap', 'folder', 'whatsapp', 'portal'], true)) { resultInfo(false, 'VALIDATION_ERROR', 'Ungültiger Quellentyp'); return; }
    if ($name === '') { resultInfo(false, 'VALIDATION_ERROR', 'Name fehlt'); return; }

    $config = is_array($data['config'] ?? null) ? $data['config'] : [];
    // Nur bekannte Schluessel, keine Passwoerter in der Config
    $allowed = ['imap' => ['use_company_mailbox', 'host', 'port', 'encryption', 'username', 'folder'],
                'folder' => ['path', 'move_processed', 'recursive'],
                'whatsapp' => [],
                'portal' => ['url', 'username', 'download_all', 'recording_steps', 'recording_title', 'recording_at']];
    $config = array_intersect_key($config, array_flip($allowed[$type]));
    if ($type === 'folder') {
        $config['path'] = trim((string)($config['path'] ?? ''));
        if ($config['path'] === '') { resultInfo(false, 'VALIDATION_ERROR', 'Ordnerpfad fehlt'); return; }
    }
    if ($type === 'imap' && empty($config['use_company_mailbox']) && (empty($config['host']) || empty($config['username']))) {
        resultInfo(false, 'VALIDATION_ERROR', 'IMAP-Server und Benutzername fehlen'); return;
    }

    $active   = in_array((string)($data['active'] ?? '1'), ['1', 't', 'true'], true);
    $vendorId = intval($data['vendor_id'] ?? 0) ?: null;
    $secret   = (string)($data['secret'] ?? '');
    $eid      = mitarbeiterId($data);

    if ($id > 0) {
        if ($type === 'portal') {
            // Aufnahme + Kennzahlen bleiben erhalten (werden nur ueber savePortalRecording gesetzt)
            $old = _bq_loadSource($db, $id);
            foreach (['recording', 'recording_steps', 'recording_title', 'recording_at'] as $k) if (isset($old['config'][$k])) $config[$k] = $old['config'][$k];
        }
        $db->execute(
            "UPDATE beleg_quellen SET type = :t, name = :n, active = :a, config = :c::jsonb, vendor_id = :v,
                    secret = CASE WHEN :s <> '' THEN :s ELSE secret END, mtime = NOW()
             WHERE id = :id",
            [':t' => $type, ':n' => $name, ':a' => $active, ':c' => json_encode($config), ':v' => $vendorId,
             ':s' => $secret !== '' ? secretEncrypt($secret) : '', ':id' => $id]
        );
    } else {
        $row = $db->getOne(
            "INSERT INTO beleg_quellen (type, name, active, config, secret, vendor_id, employee_id)
             VALUES (:t, :n, :a, :c::jsonb, NULLIF(:s, ''), :v, :e) RETURNING id",
            [':t' => $type, ':n' => $name, ':a' => $active, ':c' => json_encode($config),
             ':s' => $secret !== '' ? secretEncrypt($secret) : '', ':v' => $vendorId, ':e' => $eid]
        );
        $id = intval($row['id']);
    }
    resultInfo(true, 'Gespeichert', ['id' => $id]);
}

/**
 * Quelle loeschen (Protokoll faellt per Cascade, bereits importierte Belege bleiben).
 * @param int $data['id']
 * @testdata {"id": 1}
 */
function deleteBelegQuelle($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }
    $db->execute("UPDATE accounting_documents SET source_id = NULL WHERE source_id = :id", [':id' => $id]);
    $db->execute("DELETE FROM beleg_quellen WHERE id = :id", [':id' => $id]);
    resultInfo(true, 'Gelöscht');
}

/**
 * Einstellungen der Belegsuche (Zeitplan) speichern.
 * @param bool   $data['enabled']
 * @param string $data['time'] HH:MM
 * @testdata {"enabled": true, "time": "06:30"}
 */
function saveBelegsucheSettings($data) {
    $db = DbhCompany::begin();
    $time = preg_match('/^\d{1,2}:\d{2}$/', (string)($data['time'] ?? '')) ? $data['time'] : '06:00';
    $enabled = in_array((string)($data['enabled'] ?? ''), ['1', 't', 'true'], true) ? '1' : '0';
    foreach (['belegsuche_enabled' => $enabled, 'belegsuche_time' => $time] as $k => $v) {
        $db->execute(
            "INSERT INTO defaults_oserp (key, value, mtime) VALUES (:k, :v, now()) ON CONFLICT (key) DO UPDATE SET value = :v, mtime = now()",
            [':k' => $k, ':v' => $v]
        );
    }
    resultInfo(true, 'Gespeichert');
}

/**
 * Verbindung/Zugriff einer Quelle pruefen, ohne etwas zu importieren.
 * @param int $data['id']  oder inline: type, config, secret
 * @testdata {"id": 1}
 */
function testBelegQuelle($data) {
    $db = DbhCompany::begin();
    $src = intval($data['id'] ?? 0) > 0 ? _bq_loadSource($db, intval($data['id'])) : [
        'id' => 0, 'type' => $data['type'] ?? '', 'name' => $data['name'] ?? '', 'config' => $data['config'] ?? [],
        'secret' => isset($data['secret']) && $data['secret'] !== '' ? secretEncrypt($data['secret']) : '', 'vendor_id' => null,
    ];
    if (!$src) { resultInfo(false, 'NOT_FOUND', 'Quelle nicht gefunden'); return; }

    try {
        switch ($src['type']) {
            case 'imap':
                $imap = _bq_imapConnect($src);
                $folder = $src['config']['folder'] ?? 'INBOX';
                $count = $imap->selectFolder($folder);
                $imap->disconnect();
                resultInfo(true, '', ['ok' => true, 'message' => "Verbunden, Ordner {$folder}: {$count} Nachrichten"]);
                return;
            case 'folder':
                $path = _bq_resolvePath($src['config']['path'] ?? '');
                if (!is_dir($path)) { resultInfo(true, '', ['ok' => false, 'message' => "Ordner nicht gefunden: {$path}"]); return; }
                if (!is_readable($path)) { resultInfo(true, '', ['ok' => false, 'message' => "Ordner nicht lesbar: {$path}"]); return; }
                $files = _bq_listFolder($path, !empty($src['config']['recursive']));
                resultInfo(true, '', ['ok' => true, 'message' => "Ordner lesbar, " . count($files) . " Belegdateien warten", 'path' => $path, 'writable' => is_writable($path)]);
                return;
            case 'whatsapp':
                $n = $db->getOne("SELECT count(*) AS n FROM whatsapp_messages WHERE direction = 'I' AND message_type IN ('document', 'image')");
                resultInfo(true, '', ['ok' => true, 'message' => "WhatsApp-Eingang: {$n['n']} Dokumente/Bilder insgesamt"]);
                return;
            case 'portal':
                if (empty($src['config']['recording'])) { resultInfo(true, '', ['ok' => false, 'message' => _bq_portalMessage($src)]); return; }
                $r = _bq_portalReplay($db, $src, 1, false);
                if (!empty($r['dir'])) _bq_rmdir($r['dir']);
                if (!$r['ok']) { resultInfo(true, '', ['ok' => false, 'message' => $r['error']]); return; }
                resultInfo(true, '', ['ok' => true, 'message' => "Ablauf abgespielt: {$r['steps']} Schritte, {$r['downloads']} Datei(en) geladen" . ($r['downloads'] === 0 ? ' — kein Download erkannt, Aufnahme prüfen' : '')]);
                return;
        }
        resultInfo(false, 'VALIDATION_ERROR', 'Unbekannter Typ');
    } catch (\Throwable $e) {
        resultInfo(true, '', ['ok' => false, 'message' => $e->getMessage()]);
    }
}

/**
 * Belegsuche ausfuehren — alle aktiven Quellen oder eine. Wird vom Button
 * "Jetzt suchen", von "Magisch Buchen" und vom Cron (cli/belegsuche.php) gerufen.
 *
 * @param int $data['source_id'] nur diese Quelle (optional)
 * @param int $data['max_files'] Obergrenze je Quelle (optional, Tests)
 * @testdata {}
 */
function runBelegSuche($data) {
    set_time_limit(600);
    $db  = DbhCompany::begin();
    $eid = mitarbeiterId($data);
    _bq_ensureDefaultSources($db, $eid);

    $only = intval($data['source_id'] ?? 0);
    $max  = intval($data['max_files'] ?? 0);
    $sources = $db->getAll(
        "SELECT * FROM beleg_quellen WHERE (:only = 0 AND active) OR id = :only2 ORDER BY id",
        [':only' => $only, ':only2' => $only]
    );

    $out = [];
    foreach ($sources as $src) {
        $src['config'] = json_decode($src['config'] ?? '{}', true) ?: [];
        $stats = ['imported' => 0, 'duplicate' => 0, 'not_invoice' => 0, 'skipped' => 0, 'error' => 0, 'seen' => 0];
        $status = 'ok'; $message = '';
        try {
            switch ($src['type']) {
                case 'imap':     _bq_runImap($db, $src, $stats, $eid, $max); break;
                case 'folder':   _bq_runFolder($db, $src, $stats, $eid, $max); break;
                case 'whatsapp': _bq_runWhatsapp($db, $src, $stats, $eid, $max); break;
                case 'portal':
                    if (empty($src['config']['recording'])) { $status = 'unsupported'; $message = _bq_portalMessage($src); break; }
                    _bq_runPortal($db, $src, $stats, $eid, $max); break;
            }
        } catch (\Throwable $e) {
            $status = 'error'; $message = $e->getMessage();
            writeLog("runBelegSuche: Quelle #{$src['id']} ({$src['type']}) fehlgeschlagen: {$message}", true, DLOG_ERR);
        }
        if ($stats['error'] > 0 && $status === 'ok') { $status = 'error'; $message = $message ?: $stats['error'] . ' Fehler beim Import'; }
        $db->execute(
            "UPDATE beleg_quellen SET last_run_at = NOW(), last_status = :s, last_message = :m, last_found = :f, mtime = NOW() WHERE id = :id",
            [':s' => $status, ':m' => $message !== '' ? $message : null, ':f' => $stats['imported'], ':id' => $src['id']]
        );
        $out[] = array_merge(['id' => intval($src['id']), 'type' => $src['type'], 'name' => $src['name'], 'status' => $status, 'message' => $message], $stats);
    }

    $db->execute(
        "INSERT INTO defaults_oserp (key, value, mtime) VALUES ('belegsuche_last_run', :v, now()) ON CONFLICT (key) DO UPDATE SET value = :v, mtime = now()",
        [':v' => date('Y-m-d H:i:s')]
    );
    resultInfo(true, '', ['sources' => $out, 'imported' => array_sum(array_column($out, 'imported'))]);
}

/**
 * Vorschlaege fuer "Magisch Buchen": alle offenen Eingangsbelege (pending) mit
 * Herkunft, Kreditor, Konto und Sicherheit. `auto` = kann ohne Nacharbeit
 * gebucht werden (vorbelegt); sonst `reason`.
 *
 * @testdata {}
 */
function getMagicProposals($data) {
    $db = DbhCompany::begin();
    $originCols = _iv_hasOriginColumns($db);
    $rows = $db->getAll(
        "SELECT b.id, b.invoice_number, b.invoice_date, b.due_date, ROUND(b.amount, 2) AS amount,
                ROUND(b.net_amount, 2) AS net_amount, ROUND(b.tax_amount, 2) AS tax_amount, b.tax_rate,
                b.debit_account, cd.description AS debit_name, b.credit_account, b.description,
                b.ai_confidence, b.vendor_id, v.name AS vendor_name,
                b.document_id, d.original_name, d.mime_type, d.itime AS found_at,
                " . ($originCols ? "d.source_id, q.type AS source_type, q.name AS source_name, d.origin_info," : "NULL AS source_id, NULL AS source_type, NULL AS source_name, NULL AS origin_info,") . "
                d.extracted_data->'vendor_resolution'->>'status' AS vendor_status,
                (cd.id IS NOT NULL) AS debit_ok,
                EXISTS (SELECT 1 FROM ap WHERE ap.vendor_id = b.vendor_id AND ap.invnumber = b.invoice_number AND b.invoice_number IS NOT NULL AND b.invoice_number <> '') AS already_posted
         FROM accounting_bookings b
         JOIN accounting_documents d ON d.id = b.document_id
         LEFT JOIN vendor v ON v.id = b.vendor_id
         LEFT JOIN chart cd ON cd.accno = b.debit_account AND NOT cd.invalid AND cd.charttype = 'A' AND cd.category IN ('E', 'A')
         " . ($originCols ? "LEFT JOIN beleg_quellen q ON q.id = d.source_id" : "") . "
         WHERE b.status = 'pending' AND b.type = 'incoming' AND b.ap_id IS NULL
         ORDER BY d.itime DESC, b.id DESC"
    );
    $items = [];
    foreach ($rows as $r) {
        $reasons = [];
        if (intval($r['vendor_id']) <= 0)                 $reasons[] = 'vendor_missing';
        elseif ($r['vendor_status'] === 'ambiguous')       $reasons[] = 'vendor_ambiguous';
        if (!in_array((string)$r['debit_ok'], ['1', 't', 'true'], true)) $reasons[] = 'account_missing';
        if (floatval($r['amount']) <= 0)                   $reasons[] = 'amount_missing';
        if (trim((string)$r['invoice_number']) === '')     $reasons[] = 'invnumber_missing';
        if (in_array((string)$r['already_posted'], ['1', 't', 'true'], true)) $reasons[] = 'already_posted';
        $r['origin_info'] = $r['origin_info'] ? (json_decode($r['origin_info'], true) ?: null) : null;
        $r['auto']    = count($reasons) === 0;
        $r['reasons'] = $reasons;
        $items[] = $r;
    }

    $skipped = [];
    $hasLog = $db->getOne("SELECT to_regclass('public.beleg_quellen_log') AS t");
    if (!empty($hasLog['t'])) {
        $skipped = $db->getAll(
            "SELECT l.id, l.result, l.filename, l.message, l.info, l.itime, l.document_id, q.type AS source_type, q.name AS source_name
             FROM beleg_quellen_log l JOIN beleg_quellen q ON q.id = l.source_id
             WHERE l.result IN ('not_invoice', 'duplicate', 'error') AND l.itime > NOW() - INTERVAL '14 days'
             ORDER BY l.itime DESC LIMIT 50"
        );
        foreach ($skipped as &$s) $s['info'] = $s['info'] ? (json_decode($s['info'], true) ?: null) : null;
        unset($s);
    }
    $cfg = $db->fetchKeyValue("SELECT key, value FROM defaults_oserp WHERE key IN ('belegsuche_enabled', 'belegsuche_last_run')");
    resultInfo(true, '', [
        'items'    => $items,
        'skipped'  => $skipped,
        'last_run' => $cfg['belegsuche_last_run'] ?? null,
        'enabled'  => in_array((string)($cfg['belegsuche_enabled'] ?? ''), ['1', 't', 'true'], true),
    ]);
}

/**
 * "Magisch Buchen": die gewaehlten Vorschlaege ins Hauptbuch buchen (echte ap).
 * Je Beleg ein Ergebnis — was scheitert, bleibt als Vorschlag liegen.
 *
 * @param array $data['booking_ids']
 * @testdata {"booking_ids": [1, 2]}
 */
function magicBook($data) {
    $db  = DbhCompany::begin();
    $ids = array_values(array_unique(array_map('intval', (array)($data['booking_ids'] ?? []))));
    if (count($ids) === 0) { resultInfo(false, 'VALIDATION_ERROR', 'Keine Belege ausgewählt'); return; }
    $eid = mitarbeiterId($data);

    $results = []; $booked = 0;
    foreach ($ids as $id) {
        if ($id <= 0) continue;
        $b = $db->getOne(
            "SELECT b.id, b.invoice_number, b.amount, v.name AS vendor_name FROM accounting_bookings b LEFT JOIN vendor v ON v.id = b.vendor_id
             WHERE b.id = :id AND b.status = 'pending' AND b.type = 'incoming' AND b.ap_id IS NULL",
            [':id' => $id]
        );
        if (!$b) { $results[] = ['booking_id' => $id, 'ok' => false, 'error' => 'nicht mehr offen']; continue; }
        try {
            $apId = _iv_postBooking($db, $id);
            $db->execute(
                "UPDATE accounting_bookings SET status = 'approved', approved_by = :eid, approved_at = NOW(), mtime = NOW() WHERE id = :id",
                [':eid' => $eid, ':id' => $id]
            );
            $booked++;
            $results[] = ['booking_id' => $id, 'ok' => true, 'ap_id' => $apId, 'invoice_number' => $b['invoice_number'], 'vendor_name' => $b['vendor_name'], 'amount' => $b['amount']];
        } catch (\Throwable $e) {
            $results[] = ['booking_id' => $id, 'ok' => false, 'error' => $e->getMessage(), 'invoice_number' => $b['invoice_number'], 'vendor_name' => $b['vendor_name'], 'amount' => $b['amount']];
        }
    }
    resultInfo(true, "{$booked} Belege gebucht", ['booked' => $booked, 'results' => $results]);
}

// ═══════════════════════════ interne Helfer ═══════════════════════════════

function _bq_loadSource($db, int $id) {
    $src = $db->getOne("SELECT * FROM beleg_quellen WHERE id = :id", [':id' => $id]);
    if ($src) $src['config'] = json_decode($src['config'] ?? '{}', true) ?: [];
    return $src ?: null;
}

/** <projekt>/belege — Standard-Ablageordner */
function _bq_defaultFolder(): string {
    return dirname(__DIR__, 3) . '/belege';
}

/**
 * "~" und relative Pfade aufloesen. "~" meint das Home des Nutzers, dem das
 * Projekt gehoert — nicht das des Webserver-Nutzers.
 */
function _bq_resolvePath(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if ($path[0] === '~') {
        $home = '';
        if (function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(@fileowner(dirname(__DIR__, 3)));
            $home = $pw['dir'] ?? '';
        }
        if ($home === '') $home = dirname(dirname(__DIR__, 3));
        $path = $home . substr($path, 1);
    } elseif ($path[0] !== '/') {
        $path = dirname(__DIR__, 3) . '/' . $path;
    }
    return rtrim($path, '/');
}

/** Beim ersten Aufruf: Firmenpostfach, WhatsApp und Standardordner als Quellen anlegen. */
function _bq_ensureDefaultSources($db, $eid) {
    $n = $db->getOne("SELECT count(*) AS n FROM beleg_quellen");
    if (intval($n['n']) > 0) return;

    $email = $db->fetchKeyValue("SELECT key, value FROM defaults_oserp WHERE key IN ('email_imap_host', 'email_username', 'email_password', 'email_address', 'whatsapp_access_token')");
    if (!empty($email['email_imap_host']) && !empty($email['email_username'])) {
        $db->execute(
            "INSERT INTO beleg_quellen (type, name, active, config, employee_id) VALUES ('imap', :n, TRUE, :c::jsonb, :e)",
            [':n' => 'Firmenpostfach ' . ($email['email_address'] ?: $email['email_username']), ':c' => json_encode(['use_company_mailbox' => true, 'folder' => 'INBOX']), ':e' => $eid]
        );
    }
    if (!empty($email['whatsapp_access_token'])) {
        $db->execute("INSERT INTO beleg_quellen (type, name, active, config, employee_id) VALUES ('whatsapp', 'WhatsApp-Eingang', TRUE, '{}'::jsonb, :e)", [':e' => $eid]);
    }
    $folder = _bq_defaultFolder();
    if (!is_dir($folder)) @mkdir($folder, 0775, true);
    $db->execute(
        "INSERT INTO beleg_quellen (type, name, active, config, employee_id) VALUES ('folder', :n, TRUE, :c::jsonb, :e)",
        [':n' => 'Ordner belege/', ':c' => json_encode(['path' => $folder, 'move_processed' => true, 'recursive' => false]), ':e' => $eid]
    );
}

function _bq_portalMessage(array $src): string {
    return 'Für dieses Portal ist noch keine Aufnahme hinterlegt. Ablauf einmal mit dem Chrome-Recorder aufzeichnen (Login → Rechnung herunterladen) und hier hochladen.';
}

/**
 * Aufnahme (Chrome-Recorder-JSON) zu einer Portal-Quelle speichern.
 *
 * Die Aufnahme enthaelt die beim Vormachen eingetippten Zugangsdaten im
 * Klartext. Sie werden durch {{username}} / {{password}} ersetzt: Werte, die
 * dem gespeicherten Benutzernamen/Passwort gleichen, und ersatzweise jede
 * Eingabe in ein Passwortfeld. So landet das Passwort nie in der Config.
 *
 * @param int    $data['id']        Portal-Quelle
 * @param string $data['recording'] JSON-Export des Chrome-Recorders
 * @param string $data['secret']    Passwort (optional; wird gespeichert und zur Erkennung genutzt)
 * @testdata {"id": 1, "recording": "{\"title\":\"x\",\"steps\":[]}"}
 */
function savePortalRecording($data) {
    $db  = DbhCompany::begin();
    $src = _bq_loadSource($db, intval($data['id'] ?? 0));
    if (!$src || $src['type'] !== 'portal') { resultInfo(false, 'NOT_FOUND', 'Portal-Quelle nicht gefunden'); return; }
    $flow = json_decode((string)($data['recording'] ?? ''), true);
    if (!is_array($flow) || empty($flow['steps']) || !is_array($flow['steps'])) { resultInfo(false, 'VALIDATION_ERROR', 'Keine gültige Chrome-Recorder-Aufnahme (JSON mit "steps")'); return; }

    $secret = (string)($data['secret'] ?? '');
    if ($secret !== '') {
        $db->execute("UPDATE beleg_quellen SET secret = :s WHERE id = :id", [':s' => secretEncrypt($secret), ':id' => $src['id']]);
    } else {
        $secret = secretDecrypt($src['secret'] ?? '');
    }
    $username = (string)($src['config']['username'] ?? '');

    $replacedPass = 0; $replacedUser = 0;
    foreach ($flow['steps'] as &$step) {
        if (($step['type'] ?? '') !== 'change') continue;
        $val = (string)($step['value'] ?? '');
        $sel = json_encode($step['selectors'] ?? []);
        if ($secret !== '' && $val === $secret) { $step['value'] = '{{password}}'; $replacedPass++; }
        elseif ($username !== '' && $val === $username) { $step['value'] = '{{username}}'; $replacedUser++; }
        elseif (preg_match('/password|passwort|pwd|kennwort/i', $sel)) { $step['value'] = '{{password}}'; $replacedPass++; }
        elseif ($replacedUser === 0 && preg_match('/user|login|email|benutzer|kunde/i', $sel)) { $step['value'] = '{{username}}'; $replacedUser++; }
    }
    unset($step);
    if ($replacedPass === 0) { resultInfo(false, 'RECORDING_NO_PASSWORD', 'In der Aufnahme wurde keine Passworteingabe erkannt. Bitte das Passwort der Quelle eintragen (gleich dem beim Aufnehmen getippten) und erneut hochladen.'); return; }

    $cfg = $src['config'];
    $cfg['recording']       = $flow;
    $cfg['recording_steps'] = count($flow['steps']);
    $cfg['recording_title'] = (string)($flow['title'] ?? '');
    $cfg['recording_at']    = date('Y-m-d H:i:s');
    foreach ($flow['steps'] as $st) { if (($st['type'] ?? '') === 'navigate' && empty($cfg['url'])) { $cfg['url'] = $st['url'] ?? ''; break; } }
    $db->execute("UPDATE beleg_quellen SET config = :c::jsonb, mtime = NOW() WHERE id = :id", [':c' => json_encode($cfg, JSON_UNESCAPED_UNICODE), ':id' => $src['id']]);
    resultInfo(true, 'Aufnahme gespeichert', ['steps' => count($flow['steps']), 'password_steps' => $replacedPass, 'username_steps' => $replacedUser]);
}

/** Node-Binary finden (Webserver hat kein nvm im PATH). */
function _bq_nodeBinary(): string {
    foreach (['/usr/local/bin/node', '/usr/bin/node'] as $c) if (is_executable($c)) return $c;
    $home = '';
    if (function_exists('posix_getpwuid')) { $pw = @posix_getpwuid(@fileowner(dirname(__DIR__, 3))); $home = $pw['dir'] ?? ''; }
    $cands = glob(($home ?: '/home/*') . '/.nvm/versions/node/*/bin/node') ?: [];
    rsort($cands, SORT_NATURAL);
    foreach ($cands as $c) if (is_executable($c)) return $c;
    $w = trim((string)@shell_exec('command -v node 2>/dev/null'));
    return $w !== '' ? $w : 'node';
}

/**
 * Aufnahme abspielen: Dateien landen in einem temporaeren Download-Ordner.
 * @return array {ok, files[], steps, downloads, error, dir}
 */
function _bq_portalReplay($db, array $src, int $maxFiles, bool $all): array {
    $runner = dirname(__DIR__, 2) . '/portal-runner/replay.mjs';
    if (!is_file($runner)) return ['ok' => false, 'error' => 'Portal-Runner fehlt (backend/portal-runner)'];
    $tmp = sys_get_temp_dir() . '/oserp-portal-' . intval($src['id']) . '-' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0770, true);
    $flowFile = $tmp . '/flow.json';
    file_put_contents($flowFile, json_encode($src['config']['recording'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $dl = $tmp . '/dl';
    @mkdir($dl, 0770, true);

    $env = [
        'PORTAL_USER' => (string)($src['config']['username'] ?? ''),
        'PORTAL_PASS' => secretDecrypt($src['secret'] ?? ''),
        'HOME'        => $tmp,                      // Chrome braucht ein beschreibbares Home
        'PATH'        => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        'LANG'        => 'de_DE.UTF-8',
    ];
    $cmd = [_bq_nodeBinary(), $runner, '--flow', $flowFile, '--out', $dl, '--max', (string)max(1, $maxFiles), '--timeout', '25000'];
    if ($all) $cmd[] = '--all';
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $spec, $pipes, dirname($runner), $env);
    if (!is_resource($proc)) return ['ok' => false, 'error' => 'Portal-Runner konnte nicht gestartet werden', 'dir' => $tmp];
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $stdout = ''; $stderr = ''; $t0 = time();
    while (true) {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) break;
        if (time() - $t0 > 240) { proc_terminate($proc, 9); $stderr .= "\nZeitüberschreitung (240 s)"; break; }
        usleep(200000);
    }
    $stdout .= (string)stream_get_contents($pipes[1]); $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    @unlink($flowFile);

    $line = null;
    foreach (array_reverse(explode("\n", trim($stdout))) as $l) { $j = json_decode($l, true); if (is_array($j) && array_key_exists('ok', $j)) { $line = $j; break; } }
    if (!$line) {
        writeLog("Portal-Runner #{$src['id']} ohne Ergebnis: " . mb_substr($stderr ?: $stdout, 0, 800), true, DLOG_ERR);
        return ['ok' => false, 'error' => 'Portal-Runner lieferte kein Ergebnis: ' . mb_substr(trim($stderr ?: $stdout), 0, 300), 'dir' => $tmp];
    }
    $line['dir'] = $tmp;
    if (empty($line['ok'])) {
        $fs = $line['failedStep'] ?? null;
        $line['error'] = 'Wiedergabe gescheitert' . ($fs ? ' bei Schritt ' . ($fs['index'] + 1) . " ({$fs['type']})" : '') . ': ' . ($line['error'] ?? 'unbekannt') . ' — Portal geändert? Aufnahme bitte erneuern.';
    }
    return $line;
}

function _bq_runPortal($db, array $src, array &$stats, $eid, int $max) {
    $all = !array_key_exists('download_all', $src['config']) || !empty($src['config']['download_all']);
    $r = _bq_portalReplay($db, $src, $max > 0 ? $max : 50, $all);
    try {
        if (!$r['ok']) throw new ApiError('PORTAL_REPLAY_FAILED', $r['error']);
        $known = _bq_knownOrigins($db, intval($src['id']));
        foreach ($r['files'] as $file) {
            if (!is_file($file)) continue;
            $content = file_get_contents($file);
            $name = basename($file);
            $origin = 'portal#' . hash('sha256', $content);
            if (isset($known[$origin])) { $stats['duplicate']++; continue; }
            if (!_bq_acceptFile($name, _bq_mimeFor($name), strlen($content))) { $stats['skipped']++; continue; }
            $stats['seen']++;
            _bq_import($db, $src, $origin, $content, $name, _bq_mimeFor($name), ['portal' => $src['config']['url'] ?? '', 'file' => $name], $eid, $stats);
        }
    } finally {
        if (!empty($r['dir'])) _bq_rmdir($r['dir']);
    }
}

function _bq_rmdir(string $dir) {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** Dateiname/MIME → als Beleg verarbeiten? */
function _bq_acceptFile(string $filename, string $mime, int $size, int $minImage = BQ_MIN_IMAGE): bool {
    if ($size <= 0 || $size > BQ_MAX_FILE) return false;
    $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = strtolower($mime);
    if ($ext === 'pdf' || str_contains($mime, 'pdf')) return true;
    if ($ext === 'xml' || str_contains($mime, 'xml')) return true;
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || str_starts_with($mime, 'image/')) return $size >= $minImage;
    return false;
}

function _bq_mimeFor(string $filename, string $fallback = 'application/octet-stream'): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return ['pdf' => 'application/pdf', 'xml' => 'application/xml', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? $fallback;
}

/** Bereits verarbeitete Kennungen einer Quelle (Idempotenz). */
function _bq_knownOrigins($db, int $sourceId): array {
    $rows = $db->getAll("SELECT origin FROM beleg_quellen_log WHERE source_id = :id", [':id' => $sourceId]);
    $set = [];
    foreach ($rows as $r) $set[$r['origin']] = true;
    return $set;
}

function _bq_log($db, int $sourceId, string $origin, string $result, ?string $filename, ?int $docId, ?int $bookingId, ?string $message, ?array $info) {
    $db->execute(
        "INSERT INTO beleg_quellen_log (source_id, origin, result, filename, document_id, booking_id, message, info)
         VALUES (:s, :o, :r, :f, :d, :b, :m, :i::jsonb)
         ON CONFLICT (source_id, origin) DO UPDATE SET result = EXCLUDED.result, document_id = EXCLUDED.document_id,
             booking_id = EXCLUDED.booking_id, message = EXCLUDED.message, itime = NOW()",
        [':s' => $sourceId, ':o' => $origin, ':r' => $result, ':f' => $filename, ':d' => $docId, ':b' => $bookingId,
         ':m' => $message !== null ? mb_substr($message, 0, 500) : null, ':i' => $info ? json_encode($info, JSON_UNESCAPED_UNICODE) : null]
    );
}

/**
 * Eine Datei durch die Belegpipeline schicken und protokollieren.
 * @return string imported | duplicate | not_invoice | error
 */
function _bq_import($db, array $src, string $origin, string $content, string $filename, string $mime, array $info, $eid, array &$stats): string {
    try {
        $res = _iv_importDocument($db, $content, $filename, $mime, [
            'employee_id'     => $eid,
            'on_duplicate'    => 'return',
            'auto_book'       => false,
            'require_invoice' => true,
            'source_id'       => intval($src['id']),
            'origin'          => $origin,
            'origin_info'     => $info,
        ]);
        if (!empty($res['duplicate']))   { $result = 'duplicate';   $msg = 'Beleg bereits vorhanden (#' . $res['document_id'] . ')'; }
        elseif (!empty($res['not_invoice'])) { $result = 'not_invoice'; $msg = 'Kein Rechnungsbeleg erkannt (ohne Betrag und Rechnungsnummer)'; }
        else { $result = 'imported'; $msg = trim(($res['vendor']['vendor_name'] ?? '') . ' ' . ($res['invnumber'] ?? '') . ' ' . number_format(floatval($res['gross'] ?? 0), 2, ',', '.') . ' €'); }
        _bq_log($db, intval($src['id']), $origin, $result, $filename, intval($res['document_id'] ?? 0) ?: null, intval($res['booking_id'] ?? 0) ?: null, $msg, $info);
    } catch (\Throwable $e) {
        $result = 'error';
        _bq_log($db, intval($src['id']), $origin, 'error', $filename, null, null, $e->getMessage(), $info);
        writeLog("Belegsuche: Import {$origin} fehlgeschlagen: " . $e->getMessage(), true, DLOG_ERR);
    }
    $stats[$result]++;
    return $result;
}

// ── IMAP ────────────────────────────────────────────────────────────────────

function _bq_imapConnect(array $src): ImapClient {
    $c = $src['config'];
    if (!empty($c['use_company_mailbox'])) {
        return _createImapClient();   // Firmenpostfach aus defaults_oserp (mailer.php)
    }
    $pass = secretDecrypt($src['secret'] ?? '');
    if (empty($c['host']) || empty($c['username']) || $pass === '') {
        throw new ApiError('EMAIL_NOT_CONFIGURED', 'IMAP-Zugangsdaten unvollständig');
    }
    $imap = new ImapClient($c['host'], intval($c['port'] ?? 993), $c['encryption'] ?? 'ssl', $c['username'], $pass);
    $imap->connect();
    return $imap;
}

function _bq_runImap($db, array $src, array &$stats, $eid, int $max) {
    $imap   = _bq_imapConnect($src);
    $folder = $src['config']['folder'] ?? 'INBOX';
    $imap->selectFolder($folder);

    // Ab dem letzten Lauf (3 Tage Puffer), erster Lauf: BQ_INITIAL_DAYS zurueck
    $since = $src['last_run_at'] ? strtotime($src['last_run_at'] . ' -3 days') : strtotime('-' . BQ_INITIAL_DAYS . ' days');
    $uids  = $imap->search('SINCE "' . date('d-M-Y', $since) . '"');
    $known = _bq_knownOrigins($db, intval($src['id']));
    $processed = 0;

    foreach ($uids as $uid) {
        $mailKey = $folder . '#' . $uid;
        if (isset($known[$mailKey])) continue;
        if ($max > 0 && $processed >= $max) break;
        $msg = $imap->fetchMessage($uid, true);   // PEEK: Lesestatus bleibt
        $stats['seen']++;
        $info = ['from' => $msg['from'] ?? '', 'subject' => $msg['subject'] ?? '', 'date' => $msg['date'] ?? '', 'folder' => $folder, 'uid' => $uid];
        $attachments = $msg['attachments'] ?? [];
        $accepted = 0;
        foreach ($attachments as $i => $att) {
            $name = $att['filename'] ?: ('anhang_' . ($i + 1));
            $content = base64_decode($att['content_base64'] ?? '', true);
            if ($content === false) continue;
            $mime = $att['content_type'] ?: _bq_mimeFor($name);
            // Eingebettete Bilder (Signaturen, Banner) sind nie Belege
            if (!empty($att['inline']) && str_starts_with(strtolower($mime), 'image/')) continue;
            if (!_bq_acceptFile($name, $mime, strlen($content), BQ_MIN_IMAGE_MAIL)) continue;
            $accepted++;
            $origin = $mailKey . '#' . ($i + 1) . ':' . mb_substr($name, 0, 120);
            if (isset($known[$origin])) continue;
            _bq_import($db, $src, $origin, $content, $name, _bq_mimeFor($name, $mime), $info, $eid, $stats);
            $processed++;
        }
        // Mail als gesehen protokollieren (auch ohne Anhang), sonst wird sie jedes Mal neu geladen
        _bq_log($db, intval($src['id']), $mailKey, 'skipped', null, null, null, $accepted ? "{$accepted} Anhänge verarbeitet" : 'kein Belegdokument angehängt', $info);
        if ($accepted) $stats['skipped']--; // nur echte Uebersprungene zaehlen
        $stats['skipped']++;
    }
    $imap->disconnect();
}

// ── Ordner ──────────────────────────────────────────────────────────────────

function _bq_listFolder(string $path, bool $recursive): array {
    $files = [];
    $it = $recursive
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))
        : new DirectoryIterator($path);
    foreach ($it as $f) {
        if ($f->isDir() || $f->isDot()) continue;
        $rel = substr($f->getPathname(), strlen($path) + 1);
        if (preg_match('#(^|/)(verarbeitet|fehler)/#', $rel . '/')) continue;
        if (!_bq_acceptFile($f->getFilename(), _bq_mimeFor($f->getFilename()), $f->getSize())) continue;
        $files[] = $f->getPathname();
    }
    sort($files);
    return $files;
}

function _bq_runFolder($db, array $src, array &$stats, $eid, int $max) {
    $path = _bq_resolvePath($src['config']['path'] ?? '');
    if ($path === '' || !is_dir($path)) throw new ApiError('FOLDER_NOT_FOUND', "Ordner nicht gefunden: {$path}");
    $move  = !array_key_exists('move_processed', $src['config']) || !empty($src['config']['move_processed']);
    $known = _bq_knownOrigins($db, intval($src['id']));
    $processed = 0;
    foreach (_bq_listFolder($path, !empty($src['config']['recursive'])) as $file) {
        if ($max > 0 && $processed >= $max) break;
        $origin = $file . '@' . filemtime($file) . ':' . filesize($file);
        if (isset($known[$origin])) continue;
        $content = @file_get_contents($file);
        if ($content === false) { $stats['error']++; _bq_log($db, intval($src['id']), $origin, 'error', basename($file), null, null, 'Datei nicht lesbar', null); continue; }
        $stats['seen']++;
        $info = ['path' => $file, 'mtime' => date('Y-m-d H:i:s', filemtime($file))];
        $result = _bq_import($db, $src, $origin, $content, basename($file), _bq_mimeFor($file), $info, $eid, $stats);
        $processed++;
        // Erledigtes aus dem Eingangsordner wegraeumen — die Ablage liegt ohnehin
        // revisionssicher im Belegarchiv; der Ordner bleibt uebersichtlich.
        if ($move && is_writable(dirname($file))) {
            $sub = $result === 'error' ? 'fehler' : 'verarbeitet/' . date('Y-m');
            $target = $path . '/' . $sub;
            if (!is_dir($target)) @mkdir($target, 0775, true);
            if (is_dir($target)) @rename($file, $target . '/' . basename($file));
        }
    }
}

// ── WhatsApp ────────────────────────────────────────────────────────────────

function _bq_runWhatsapp($db, array $src, array &$stats, $eid, int $max) {
    $has = $db->getOne("SELECT to_regclass('public.whatsapp_messages') AS t");
    if (empty($has['t'])) return;
    $rows = $db->getAll(
        "SELECT m.id, m.wa_message_id, m.phone_number, m.contact_name, m.message_type, m.media_url, m.media_mime_type, m.media_caption, m.itime
         FROM whatsapp_messages m
         WHERE m.direction = 'I' AND m.message_type IN ('document', 'image')
           AND m.media_url IS NOT NULL
           AND (m.media_url LIKE 'customers/%' OR m.media_url LIKE 'vendors/%' OR m.media_url LIKE 'whatsapp_unmatched/%')
           AND m.itime > NOW() - INTERVAL '60 days'
           AND NOT EXISTS (SELECT 1 FROM beleg_quellen_log l WHERE l.source_id = :sid AND l.origin = 'wa#' || m.id)
         ORDER BY m.id",
        [':sid' => intval($src['id'])]
    );
    $processed = 0;
    foreach ($rows as $m) {
        if ($max > 0 && $processed >= $max) break;
        $origin = 'wa#' . $m['id'];
        $file = fmDataDir() . '/' . $m['media_url'];
        if (!is_file($file)) { _bq_log($db, intval($src['id']), $origin, 'skipped', $m['media_caption'], null, null, 'Mediendatei nicht vorhanden', null); $stats['skipped']++; continue; }
        $name = $m['media_caption'] ?: basename($m['media_url']);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === '') $name .= '.' . pathinfo($m['media_url'], PATHINFO_EXTENSION);
        $size = filesize($file);
        $mime = $m['media_mime_type'] ?: _bq_mimeFor($name);
        if (!_bq_acceptFile($name, $mime, $size)) { _bq_log($db, intval($src['id']), $origin, 'skipped', $name, null, null, 'Dateityp/-größe nicht geeignet', null); $stats['skipped']++; continue; }
        $stats['seen']++;
        $info = ['from' => trim(($m['contact_name'] ?? '') . ' ' . ($m['phone_number'] ?? '')), 'date' => $m['itime'], 'whatsapp_message_id' => $m['wa_message_id']];
        _bq_import($db, $src, $origin, file_get_contents($file), $name, $mime, $info, $eid, $stats);
        $processed++;
    }
}
