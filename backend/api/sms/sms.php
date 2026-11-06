<?php
// backend/api/sms/sms.php
//
// SMS-Modul: Gespräche (Kontakt → SMS), Reiter SMS in der Kundenmaske,
// Versand aus der Faktura (Beleg als Link), Info-Leiste und Einrichtung
// (Guthaben, Webhook für eingehende SMS und Zustellberichte).
// Kern und seven.io-Anbindung: sms_lib.php.

/**
 * Antwort nach smsDeliver: Erfolg mit der neuen Nachricht, sonst Fehlercode
 */
function _smsRespond(array $res): void {
    if (!$res['success']) {
        resultInfo(false, $res['code'], ['error' => $res['error'], 'id' => $res['id'] ?? null, 'seven_code' => $res['seven_code'] ?? null]);
        return;
    }
    resultInfo(true, '', ['id' => $res['id'], 'phone' => $res['phone'], 'text' => $res['text'], 'link' => $res['link'],
                          'parts' => $res['parts'], 'price' => $res['price'], 'customer_id' => $res['customer_id']]);
}

/**
 * Einstellungen für die Oberfläche (ohne Schlüssel)
 *
 * @testdata {}
 */
function getSmsConfig($data) {
    $db = DbhCompany::begin();
    $cfg = smsSettings($db);
    resultInfo(true, '', [
        'configured'   => $cfg['api_key'] !== '',
        'reply_hint'   => smsReplyHint($db),
        'sender'       => $cfg['sender'],
        'cost'         => $cfg['cost'],
        'fallback'     => $cfg['fallback'],
        'country_code' => $cfg['country_code'],
        'link_days'    => $cfg['link_days'],
        'public_url'   => $cfg['public_url'],
        'webhook'      => $cfg['hook_ids'] !== '' && $cfg['webhook_secret'] !== '',
    ]);
}

/**
 * Gespräche: je Rufnummer die letzte Nachricht, Anzahl ungelesener, Kunde
 *
 * @param string $data['search'] Suche in Name, Nummer und Text (optional)
 * @param int    $data['limit'] Höchstzahl (Vorgabe 100)
 * @testdata {"search": "", "limit": 50}
 */
function getSmsConversations($data) {
    $db = DbhCompany::begin();
    $search = trim((string)($data['search'] ?? ''));
    $rows = $db->getAll(
        "WITH s AS (
            SELECT * FROM sms_messages WHERE NOT hidden
         ), last AS (
            SELECT DISTINCT ON (phone_number) phone_number, message_text AS last_message, direction AS last_direction,
                   status AS last_status, itime AS last_message_time
              FROM s ORDER BY phone_number, itime DESC, id DESC
         ), agg AS (
            SELECT phone_number,
                   (array_agg(customer_id ORDER BY itime DESC) FILTER (WHERE customer_id IS NOT NULL))[1] AS customer_id,
                   max(contact_name) AS contact_name,
                   count(*) AS message_count,
                   count(*) FILTER (WHERE direction = 'I' AND status = 'received') AS unread_count
              FROM s GROUP BY phone_number
         )
         SELECT l.*, a.customer_id, a.contact_name, a.message_count, a.unread_count,
                COALESCE(c.name, v.name) AS customer_name,
                CASE WHEN c.id IS NOT NULL THEN 'C' WHEN v.id IS NOT NULL THEN 'V' END AS src
           FROM last l
           JOIN agg a USING (phone_number)
           LEFT JOIN customer c ON c.id = a.customer_id
           LEFT JOIN vendor v ON v.id = a.customer_id AND c.id IS NULL
          WHERE CAST(:search AS text) IS NULL
             OR l.phone_number ILIKE '%' || CAST(:search AS text) || '%'
             OR COALESCE(c.name, v.name, a.contact_name, '') ILIKE '%' || CAST(:search AS text) || '%'
             OR EXISTS (SELECT 1 FROM s WHERE s.phone_number = l.phone_number AND s.message_text ILIKE '%' || CAST(:search AS text) || '%')
          ORDER BY l.last_message_time DESC
          LIMIT CAST(:limit AS int)",
        [':search' => $search === '' ? null : $search, ':limit' => min(300, max(1, (int)($data['limit'] ?? 100)))]
    );
    resultInfo(true, '', ['conversations' => $rows]);
}

/**
 * Verlauf einer Rufnummer bzw. eines Kunden; eingehende werden dabei gelesen
 *
 * @param string $data['phone_number'] Rufnummer (+49…), oder
 * @param int    $data['customer_id'] Kunde/Lieferant – dann alle seine Nummern
 * @param array  $data['phone_numbers'] weitere Nummern des Kunden (Reiter in der Kundenmaske)
 * @testdata {"phone_number": "+4915202847411"}
 */
function getSmsChat($data) {
    $db = DbhCompany::begin();
    $cfg = smsSettings($db);
    $phones = array_values(array_filter(array_map(
        fn($p) => smsNormalizePhone((string)$p, $cfg['country_code']),
        array_merge([(string)($data['phone_number'] ?? '')], is_array($data['phone_numbers'] ?? null) ? $data['phone_numbers'] : [])
    )));
    $cvId = (int)($data['customer_id'] ?? 0);
    if (!$phones && $cvId <= 0) {
        resultInfo(true, '', ['messages' => []]);
        return;
    }
    $rows = $db->getAll(
        "WITH m AS (
            SELECT * FROM sms_messages
             WHERE NOT hidden AND (phone_number = ANY(CAST(:phones AS text[])) OR customer_id = CAST(:cv AS int))
             ORDER BY itime DESC LIMIT 300
         ), gelesen AS (
            UPDATE sms_messages SET status = 'read', mtime = now()
             WHERE id IN (SELECT id FROM m WHERE direction = 'I' AND status = 'received')
         )
         SELECT m.id, m.direction, m.phone_number, m.customer_id, m.contact_name, m.message_text, m.parts, m.price,
                CASE WHEN m.direction = 'I' AND m.status = 'received' THEN 'read' ELSE m.status END AS status,
                m.error_code, m.error_message, m.source, m.whatsapp_error, m.media_name,
                m.media_url IS NOT NULL AS has_media, m.link_opened_at, m.link_expires, m.itime,
                e.name AS employee_name
           FROM m LEFT JOIN employee e ON e.id = m.employee_id
          ORDER BY m.itime, m.id",
        [':phones' => '{'.implode(',', array_map(fn($p) => '"'.$p.'"', $phones)).'}', ':cv' => $cvId > 0 ? $cvId : null]
    );
    resultInfo(true, '', ['messages' => $rows]);
}

/**
 * SMS ausblenden (bleibt in der Datenbank)
 *
 * @param int $data['message_id'] sms_messages.id
 * @testdata {"message_id": 1}
 */
function deleteSmsMessage($data) {
    DbhCompany::begin()->execute(
        "UPDATE sms_messages SET hidden = TRUE, mtime = now() WHERE id = :id",
        [':id' => (int)($data['message_id'] ?? 0)]
    );
    resultInfo(true, '');
}

/**
 * SMS senden – Text frei, optional mit Datei (geht als Link mit)
 *
 * @param string $data['to'] Rufnummer
 * @param string $data['message'] Text; {link} markiert, wo der Link stehen soll
 * @param int    $data['customer_id'] Kunde/Lieferant (optional, sonst über die Nummer)
 * @param string $data['document_base64'] Datei (optional)
 * @param string $data['filename'] Dateiname der Datei
 * @param int    $data['employee_id'] Absender (kommt aus dem Store)
 * @testdata {"to": "+4915202847411", "message": "Ihr Fahrzeug ist fertig.", "customer_id": 0}
 */
function sendSms($data) {
    $db = DbhCompany::begin();
    $cvId = (int)($data['customer_id'] ?? 0);
    $media = null;
    if (!empty($data['document_base64'])) {
        $media = smsStoreFile($db, $cvId, (string)base64_decode((string)$data['document_base64'], true), (string)($data['filename'] ?? 'Dokument.pdf'));
    }
    _smsRespond(smsDeliver($db, (string)($data['to'] ?? ''), (string)($data['message'] ?? ''), [
        'customer_id' => $cvId ?: null,
        'source' => 'chat',
        'employee_id' => $data['employee_id'] ?? null,
        'media_url' => $media,
        'media_name' => $media ? basename($media) : null,
    ]));
}

/**
 * Beleg per SMS: PDF ablegen, Link in den Text, Verknüpfung zum Beleg
 *
 * @param string $data['to'] Rufnummer
 * @param string $data['message'] Text mit {link}
 * @param int    $data['customer_id'] Kunde/Lieferant
 * @param string $data['document_base64'] PDF
 * @param string $data['filename'] Dateiname
 * @param string $data['record_table'] oe | ar | ap | delivery_orders
 * @param int    $data['record_id'] Beleg
 * @testdata {"to": "+4915202847411", "message": "Ihr Angebot: {link}", "customer_id": 98499, "document_base64": "JVBERi0xLjQK", "filename": "Angebot_1.pdf", "record_table": "oe", "record_id": 1}
 */
function sendSmsDocument($data) {
    $db = DbhCompany::begin();
    $cvId = (int)($data['customer_id'] ?? 0);
    $binary = base64_decode((string)($data['document_base64'] ?? ''), true);
    if ($binary === false || $binary === '') {
        resultInfo(false, 'INVALID_INPUT', 'Kein Dokument angegeben');
        return;
    }
    $media = smsStoreFile($db, $cvId, $binary, (string)($data['filename'] ?? 'Dokument.pdf'));
    _smsRespond(smsDeliver($db, (string)($data['to'] ?? ''), (string)($data['message'] ?? ''), [
        'customer_id' => $cvId ?: null,
        'source' => 'faktura',
        'employee_id' => $data['employee_id'] ?? null,
        'media_url' => $media,
        'media_name' => basename($media),
        'record_table' => $data['record_table'] ?? null,
        'record_id' => $data['record_id'] ?? null,
    ]));
}

/**
 * Info-Leiste: ungelesene eingehende SMS und Ausweich-SMS (statt WhatsApp) der letzten 7 Tage
 *
 * @testdata {}
 */
function getSmsNotices($data) {
    $rows = DbhCompany::begin()->getAll(
        "SELECT s.id, s.direction, s.source, s.status, s.phone_number, s.customer_id, s.itime,
                LEFT(s.message_text, 200) AS message_text, s.whatsapp_error, s.error_message,
                COALESCE(c.name, v.name) AS customer_name,
                CASE WHEN c.id IS NOT NULL THEN 'C' WHEN v.id IS NOT NULL THEN 'V' END AS src
           FROM sms_messages s
           LEFT JOIN customer c ON c.id = s.customer_id
           LEFT JOIN vendor v ON v.id = s.customer_id AND c.id IS NULL
          WHERE NOT s.hidden AND s.itime > now() - interval '7 days'
            AND ((s.direction = 'I' AND s.status = 'received') OR s.source = 'fallback')
          ORDER BY s.itime DESC
          LIMIT 50"
    );
    resultInfo(true, '', ['results' => $rows]);
}

/**
 * Einrichtung: Guthaben bei seven.io, Webhook-Stand, öffentliche Adresse
 *
 * @testdata {}
 */
function getSmsSetup($data) {
    $db = DbhCompany::begin();
    $cfg = smsSettings($db);
    $balance = null;
    $error = null;
    if ($cfg['api_key'] !== '') {
        [$http, $res, $err] = smsSevenRequest($cfg['api_key'], 'GET', 'balance');
        if (is_array($res) && isset($res['amount'])) $balance = ['amount' => (float)$res['amount'], 'currency' => (string)($res['currency'] ?? 'EUR')];
        elseif (is_numeric(trim((string)(is_string($res) ? $res : '')))) $balance = ['amount' => (float)$res, 'currency' => 'EUR'];
        else $error = $err !== '' ? $err : smsSevenErrorText(is_string($res) ? trim($res) : 'HTTP'.$http);
    }
    resultInfo(true, '', [
        'configured' => $cfg['api_key'] !== '',
        'balance' => $balance,
        'error' => $error,
        'webhook' => $cfg['hook_ids'] !== '' && $cfg['webhook_secret'] !== '',
        'public_url' => smsPublicBase($db),
        'stats' => $db->getOne(
            "SELECT count(*) FILTER (WHERE direction = 'O' AND itime > date_trunc('month', now())) AS sent_month,
                    COALESCE(sum(price) FILTER (WHERE direction = 'O' AND itime > date_trunc('month', now())), 0) AS cost_month,
                    count(*) FILTER (WHERE source = 'fallback' AND itime > date_trunc('month', now())) AS fallback_month,
                    count(*) FILTER (WHERE direction = 'I' AND itime > date_trunc('month', now())) AS received_month
               FROM sms_messages"
        ),
    ]);
}

/**
 * Webhook bei seven.io anmelden: eingehende SMS (sms_mo) und Zustellberichte (dlr)
 * gehen an backend/webhook/sms.php dieses Servers. Bereits angemeldete Hooks
 * dieses Mandanten werden vorher abgemeldet.
 *
 * @param string $data['public_url'] Öffentliche Adresse (optional, sonst die bekannte)
 * @testdata {"public_url": "https://melissa.spdns.de"}
 */
function registerSmsWebhook($data) {
    $db = DbhCompany::begin();
    $cfg = smsSettings($db);
    if ($cfg['api_key'] === '') {
        resultInfo(false, 'SMS_NOT_CONFIGURED', 'SMS-Versand ist nicht eingerichtet');
        return;
    }
    $base = rtrim(trim((string)($data['public_url'] ?? '')), '/') ?: smsPublicBase($db);
    if (!preg_match('#^https://[^/\s]+$#', $base)) {
        resultInfo(false, 'SMS_NO_PUBLIC_URL', 'Öffentliche https-Adresse fehlt, z. B. https://firma.example');
        return;
    }
    foreach (array_filter(explode(',', $cfg['hook_ids'])) as $alt) {
        smsSevenRequest($cfg['api_key'], 'DELETE', 'hooks', ['id' => (int)$alt]);
    }
    $secret = bin2hex(random_bytes(16));
    $target = $base.'/webhook/sms.php?db='.rawurlencode($cfg['dbname']).'&key='.$secret;
    $ids = [];
    foreach (['sms_mo', 'dlr'] as $event) {
        [$http, $res, $err] = smsSevenRequest($cfg['api_key'], 'POST', 'hooks', [
            'target_url' => $target, 'event_type' => $event, 'request_method' => 'JSON',
        ]);
        if (!is_array($res) || empty($res['success']) || empty($res['id'])) {
            foreach ($ids as $id) smsSevenRequest($cfg['api_key'], 'DELETE', 'hooks', ['id' => $id]);
            $grund = $err !== '' ? $err : (is_array($res) ? (string)($res['error_message'] ?? $res['code'] ?? 'HTTP '.$http) : mb_substr((string)$res, 0, 120));
            resultInfo(false, 'SMS_WEBHOOK_FAILED', 'Webhook-Anmeldung fehlgeschlagen: '.$grund);
            return;
        }
        $ids[] = (int)$res['id'];
    }
    $db->execute(
        "INSERT INTO defaults_oserp (key, value)
         VALUES ('sms_webhook_secret', :secret), ('sms_hook_ids', :ids), ('sms_public_url', :base)
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value",
        [':secret' => $secret, ':ids' => implode(',', $ids), ':base' => $base]
    );
    resultInfo(true, '', ['hook_ids' => $ids, 'public_url' => $base]);
}
