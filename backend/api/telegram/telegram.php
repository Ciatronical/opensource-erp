<?php
// backend/api/telegram/telegram.php
//
// Telegram-Kundenchat: Gespräche (Kontakt → Telegram), Reiter Telegram in der
// Kundenmaske, Antworten mit Datei, Zuordnung unbekannter Chats und Info-Leiste.
// Kern und Bot-Anbindung: telegram_lib.php, Eingang: backend/webhook/telegram.php.

/**
 * Einstellungen für die Oberfläche (ohne Token)
 *
 * @testdata {}
 */
function getTelegramConfig($data) {
    $cfg = tgSettings(DbhCompany::begin());
    resultInfo(true, '', [
        'configured' => $cfg['enabled'],
        'bot_username' => $cfg['username'],
        'link' => $cfg['enabled'] && $cfg['username'] !== '' ? 'https://t.me/'.$cfg['username'] : '',
    ]);
}

/**
 * Gespräche: je Chat die letzte Nachricht, Anzahl ungelesener, Kunde
 *
 * @param string $data['search'] Suche in Name und Text (optional)
 * @param int    $data['limit'] Höchstzahl (Vorgabe 100)
 * @testdata {"search": "", "limit": 50}
 */
function getTelegramConversations($data) {
    $search = trim((string)($data['search'] ?? ''));
    $rows = DbhCompany::begin()->getAll(
        "WITH m AS (
            SELECT * FROM telegram_messages WHERE NOT hidden
         ), last AS (
            SELECT DISTINCT ON (chat_id) chat_id, COALESCE(message_text, media_name, message_type) AS last_message,
                   direction AS last_direction, status AS last_status, itime AS last_message_time
              FROM m ORDER BY chat_id, itime DESC, id DESC
         ), agg AS (
            SELECT chat_id,
                   (array_agg(customer_id ORDER BY itime DESC) FILTER (WHERE customer_id IS NOT NULL))[1] AS customer_id,
                   (array_agg(contact_name ORDER BY itime DESC) FILTER (WHERE contact_name IS NOT NULL))[1] AS contact_name,
                   count(*) FILTER (WHERE direction = 'I' AND status = 'received') AS unread_count
              FROM m GROUP BY chat_id
         )
         SELECT l.*, a.contact_name, a.unread_count,
                COALESCE(c.id, v.id, xc.customer_id, xv.vendor_id) AS customer_id,
                COALESCE(c.name, v.name, xcc.name, xvv.name) AS customer_name,
                CASE WHEN COALESCE(c.id, xc.customer_id) IS NOT NULL THEN 'C'
                     WHEN COALESCE(v.id, xv.vendor_id) IS NOT NULL THEN 'V' END AS src
           FROM last l
           JOIN agg a USING (chat_id)
           LEFT JOIN customer c ON c.id = a.customer_id
           LEFT JOIN vendor v ON v.id = a.customer_id AND c.id IS NULL
           LEFT JOIN customer_ext xc ON a.customer_id IS NULL AND xc.telegram_chat_id = l.chat_id
           LEFT JOIN customer xcc ON xcc.id = xc.customer_id
           LEFT JOIN vendor_ext xv ON a.customer_id IS NULL AND xc.customer_id IS NULL AND xv.telegram_chat_id = l.chat_id
           LEFT JOIN vendor xvv ON xvv.id = xv.vendor_id
          WHERE CAST(:search AS text) IS NULL
             OR COALESCE(c.name, v.name, xcc.name, xvv.name, a.contact_name, '') ILIKE '%' || CAST(:search AS text) || '%'
             OR EXISTS (SELECT 1 FROM m WHERE m.chat_id = l.chat_id AND m.message_text ILIKE '%' || CAST(:search AS text) || '%')
          ORDER BY l.last_message_time DESC
          LIMIT CAST(:limit AS int)",
        [':search' => $search === '' ? null : $search, ':limit' => min(300, max(1, (int)($data['limit'] ?? 100)))]
    );
    resultInfo(true, '', ['conversations' => $rows]);
}

/**
 * Verlauf eines Chats bzw. eines Kunden; eingehende werden dabei gelesen
 *
 * @param string $data['chat_id'] Telegram-Chat-ID, oder
 * @param int    $data['customer_id'] Kunde/Lieferant – dann sein verknüpfter Chat und alle ihm zugeordneten Nachrichten
 * @testdata {"customer_id": 1126}
 */
function getTelegramChat($data) {
    $chatId = trim((string)($data['chat_id'] ?? ''));
    $cvId = (int)($data['customer_id'] ?? 0);
    $row = DbhCompany::begin()->getOne(
        "WITH ziel AS (
            SELECT COALESCE(NULLIF(CAST(:chat AS text), ''),
                            (SELECT telegram_chat_id FROM customer_ext WHERE customer_id = CAST(:cv AS int)),
                            (SELECT telegram_chat_id FROM vendor_ext WHERE vendor_id = CAST(:cv AS int))) AS chat_id
         ), m AS (
            SELECT t.* FROM telegram_messages t, ziel
             WHERE NOT t.hidden AND (t.chat_id = ziel.chat_id OR t.customer_id = CAST(:cv AS int))
             ORDER BY t.itime DESC LIMIT 300
         ), gelesen AS (
            UPDATE telegram_messages SET status = 'read', mtime = now()
             WHERE id IN (SELECT id FROM m WHERE direction = 'I' AND status = 'received')
         )
         SELECT (SELECT chat_id FROM ziel) AS chat_id,
                COALESCE((SELECT json_agg(json_build_object(
                    'id', m.id, 'direction', m.direction, 'chat_id', m.chat_id, 'customer_id', m.customer_id,
                    'contact_name', m.contact_name, 'message_type', m.message_type, 'message_text', m.message_text,
                    'media_name', m.media_name, 'has_media', m.media_url IS NOT NULL,
                    'status', CASE WHEN m.direction = 'I' AND m.status = 'received' THEN 'read' ELSE m.status END,
                    'error_message', m.error_message, 'source', m.source, 'itime', m.itime, 'employee_name', e.name
                ) ORDER BY m.itime, m.id) FROM m LEFT JOIN employee e ON e.id = m.employee_id), '[]') AS messages",
        [':chat' => $chatId, ':cv' => $cvId > 0 ? $cvId : null]
    );
    resultInfo(true, '', ['chat_id' => $row['chat_id'] ?? null, 'messages' => json_decode((string)($row['messages'] ?? '[]'), true)]);
}

/**
 * Nachricht ausblenden (bleibt in der Datenbank)
 *
 * @param int $data['message_id'] telegram_messages.id
 * @testdata {"message_id": 1}
 */
function deleteTelegramMessage($data) {
    DbhCompany::begin()->execute(
        "UPDATE telegram_messages SET hidden = TRUE, mtime = now() WHERE id = :id",
        [':id' => (int)($data['message_id'] ?? 0)]
    );
    resultInfo(true, '');
}

/**
 * Antwort senden – Text, optional mit Datei (Bilder als Foto, sonst als Dokument)
 *
 * @param string $data['chat_id'] Telegram-Chat-ID (oder customer_id mit verknüpftem Chat)
 * @param int    $data['customer_id'] Kunde/Lieferant (optional)
 * @param string $data['message'] Text
 * @param string $data['document_base64'] Datei (optional)
 * @param string $data['filename'] Dateiname der Datei
 * @param int    $data['employee_id'] Absender (kommt aus dem Store)
 * @testdata {"chat_id": "5088903108", "message": "Ihr Fahrzeug ist fertig."}
 */
function sendTelegram($data) {
    $db = DbhCompany::begin();
    $cvId = (int)($data['customer_id'] ?? 0);
    $chatId = trim((string)($data['chat_id'] ?? ''));
    if ($chatId === '' && $cvId > 0) {
        $chatId = (string)($db->getOne(
            "SELECT COALESCE((SELECT telegram_chat_id FROM customer_ext WHERE customer_id = :cv),
                             (SELECT telegram_chat_id FROM vendor_ext WHERE vendor_id = :cv)) AS chat_id",
            [':cv' => $cvId]
        )['chat_id'] ?? '');
    }
    if ($chatId === '') {
        resultInfo(false, 'TELEGRAM_NOT_LINKED', 'Kunde ist nicht mit Telegram verknüpft');
        return;
    }
    $media = null;
    if (!empty($data['document_base64'])) {
        $media = smsStoreFile($db, $cvId, (string)base64_decode((string)$data['document_base64'], true),
                              (string)($data['filename'] ?? 'Dokument.pdf'), 'telegram');
    }
    $res = tgDeliver($db, $chatId, (string)($data['message'] ?? ''), [
        'customer_id' => $cvId ?: null,
        'employee_id' => $data['employee_id'] ?? null,
        'media_url' => $media,
        'media_name' => $media ? basename($media) : null,
    ]);
    if (!$res['success']) {
        resultInfo(false, $res['code'], ['error' => $res['error'], 'id' => $res['id'] ?? null]);
        return;
    }
    resultInfo(true, '', ['id' => $res['id'], 'customer_id' => $res['customer_id']]);
}

/**
 * Unbekannten Chat einem Kunden/Lieferanten zuordnen (z. B. andere Rufnummer
 * als im Stamm): Chat-ID an den Kunden, bisherige Nachrichten mit
 *
 * @param string $data['chat_id'] Telegram-Chat-ID
 * @param int    $data['customer_id'] Kunde/Lieferant
 * @param string $data['src'] C | V
 * @testdata {"chat_id": "5088903108", "customer_id": 1126, "src": "C"}
 */
function linkTelegramChat($data) {
    $chatId = trim((string)($data['chat_id'] ?? ''));
    $cvId = (int)($data['customer_id'] ?? 0);
    if (!preg_match('/^-?\d+$/', $chatId) || $cvId <= 0) {
        resultInfo(false, 'INVALID_INPUT', 'Chat oder Kunde fehlt');
        return;
    }
    $vendor = ($data['src'] ?? 'C') === 'V';
    $table = $vendor ? 'vendor_ext' : 'customer_ext';
    $col = $vendor ? 'vendor_id' : 'customer_id';
    DbhCompany::begin()->execute(
        "WITH ext AS (
            INSERT INTO {$table} ({$col}, telegram_chat_id, itime) VALUES (:cv, :chat, now())
            ON CONFLICT ({$col}) DO UPDATE SET telegram_chat_id = EXCLUDED.telegram_chat_id, mtime = now()
         )
         UPDATE telegram_messages SET customer_id = :cv, mtime = now() WHERE chat_id = :chat",
        [':cv' => $cvId, ':chat' => $chatId]
    );
    resultInfo(true, '');
}

/**
 * Info-Leiste: ungelesene eingehende Nachrichten und gescheiterte Antworten der letzten 7 Tage
 *
 * @testdata {}
 */
function getTelegramNotices($data) {
    $rows = DbhCompany::begin()->getAll(
        "SELECT t.id, t.direction, t.status, t.chat_id, t.customer_id, t.itime, t.contact_name, t.message_type,
                LEFT(COALESCE(t.message_text, t.media_name, ''), 200) AS message_text, t.error_message,
                COALESCE(c.name, v.name) AS customer_name,
                CASE WHEN c.id IS NOT NULL THEN 'C' WHEN v.id IS NOT NULL THEN 'V' END AS src
           FROM telegram_messages t
           LEFT JOIN customer c ON c.id = t.customer_id
           LEFT JOIN vendor v ON v.id = t.customer_id AND c.id IS NULL
          WHERE NOT t.hidden AND t.itime > now() - interval '7 days'
            AND ((t.direction = 'I' AND t.status = 'received') OR (t.direction = 'O' AND t.status = 'failed'))
          ORDER BY t.itime DESC
          LIMIT 50"
    );
    resultInfo(true, '', ['results' => $rows]);
}

/**
 * Datei einer Nachricht ausliefern (Bild in der Vorschau, sonst Download)
 *
 * @param int $data['message_id'] telegram_messages.id
 * @testdata {"message_id": 1}
 */
function getTelegramMedia($data) {
    $row = DbhCompany::begin()->getOne(
        "SELECT media_url, media_name FROM telegram_messages WHERE id = :id AND media_url IS NOT NULL",
        [':id' => (int)($data['message_id'] ?? 0)]
    );
    $base = realpath(fmDataDir());
    $abs = $row ? realpath($base.'/'.$row['media_url']) : false;
    if (!$abs || !str_starts_with($abs, $base.'/') || !is_file($abs)) {
        http_response_code(404);
        resultInfo(false, 'NOT_FOUND', 'Datei nicht gefunden');
        exit;
    }
    $name = str_replace('"', '', (string)($row['media_name'] ?: basename($abs)));
    header('Content-Type: '.(mime_content_type($abs) ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="'.$name.'"');
    header('Content-Length: '.filesize($abs));
    readfile($abs);
    exit;
}
