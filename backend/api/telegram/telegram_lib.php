<?php
// backend/api/telegram/telegram_lib.php
//
// Telegram-Kundenchat – gemeinsamer Kern für die API (telegram.php), die
// Rundschreiben und den Antwort-Hinweis unter SMS (sms_lib.php). Eingehende
// Nachrichten nimmt backend/webhook/telegram.php entgegen.
//
// Einstellungen (defaults_oserp):
//   telegram_enabled       '1' = Bot aktiv
//   telegram_bot_token     Token von @BotFather
//   telegram_bot_username  Name des Bots (wird beim ersten Bedarf per getMe gelernt)

const TELEGRAM_API = 'https://api.telegram.org/bot';

/**
 * Bot-Zugang in einer Abfrage; lernt den Bot-Namen einmalig per getMe
 *
 * @param object $db ApiDatabase des Mandanten
 * @return array token, username, enabled ('' bzw. false, wenn nicht eingerichtet)
 */
function tgSettings($db): array {
    static $cache = [];
    $row = $db->getOne(
        "SELECT current_database() AS dbname,
                (SELECT btrim(value) FROM defaults_oserp WHERE key = 'telegram_bot_token') AS token,
                (SELECT btrim(value) FROM defaults_oserp WHERE key = 'telegram_bot_username') AS username,
                COALESCE((SELECT value = '1' FROM defaults_oserp WHERE key = 'telegram_enabled'), false) AS enabled"
    );
    $dbname = (string)($row['dbname'] ?? '');
    if (isset($cache[$dbname])) return $cache[$dbname];
    $cfg = [
        'token' => (string)($row['token'] ?? ''),
        'username' => ltrim((string)($row['username'] ?? ''), '@'),
        'enabled' => !empty($row['enabled']) && trim((string)($row['token'] ?? '')) !== '',
    ];
    if ($cfg['enabled'] && $cfg['username'] === '') {
        [$res] = tgRequest($cfg['token'], 'getMe');
        $cfg['username'] = trim((string)($res['result']['username'] ?? ''));
        if ($cfg['username'] !== '') {
            $db->execute(
                "INSERT INTO defaults_oserp (key, value) VALUES ('telegram_bot_username', :name)
                 ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value",
                [':name' => $cfg['username']]
            );
        }
    }
    return $cache[$dbname] = $cfg;
}

/**
 * Kurzer Link auf den Bot (t.me/<Bot>), '' ohne eingerichteten Bot
 */
function tgBotLink($db): string {
    $cfg = tgSettings($db);
    return $cfg['enabled'] && $cfg['username'] !== '' ? 't.me/'.$cfg['username'] : '';
}

/**
 * Anfrage an die Bot-API
 *
 * @param string $token Bot-Token
 * @param string $method z. B. sendMessage, sendDocument, getMe
 * @param array  $params Felder; ein CURLFile schickt die Anfrage als multipart
 * @return array [Antwort (decodiert), curlFehler]
 */
function tgRequest(string $token, string $method, array $params = []): array {
    $multipart = (bool)array_filter($params, fn($v) => $v instanceof CURLFile);
    $ch = curl_init(TELEGRAM_API.$token.'/'.$method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $multipart ? $params : http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $multipart ? 60 : 20,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return [is_string($body) ? (json_decode($body, true) ?: []) : [], $err];
}

/**
 * Nachricht an einen Telegram-Chat senden und im Verlauf ablegen
 *
 * Die Zeile entsteht vor dem Versand (wie bei SMS), damit ein Abbruch sichtbar
 * bleibt. Eine Datei geht als Foto (Bilder) bzw. Dokument mit dem Text als
 * Bildunterschrift; längere Texte (über 1024 Zeichen) gehen vorab als eigene
 * Nachricht.
 *
 * @param object $db     ApiDatabase des Mandanten
 * @param string $chatId Telegram-Chat-ID
 * @param string $text   Nachricht
 * @param array  $opts   customer_id, source, employee_id, media_url (relativ zum Datenverzeichnis), media_name
 * @return array success, id, error, customer_id
 */
function tgDeliver($db, string $chatId, string $text, array $opts = []): array {
    $cfg = tgSettings($db);
    $text = trim(str_replace("\r\n", "\n", $text));
    $media = (string)($opts['media_url'] ?? '');
    if (!$cfg['enabled']) return ['success' => false, 'code' => 'TELEGRAM_NOT_CONFIGURED', 'error' => 'Telegram ist nicht eingerichtet'];
    if (!preg_match('/^-?\d+$/', $chatId)) return ['success' => false, 'code' => 'TELEGRAM_INVALID_CHAT', 'error' => 'Ungültige Chat-ID'];
    if ($text === '' && $media === '') return ['success' => false, 'code' => 'TELEGRAM_EMPTY', 'error' => 'Kein Text'];
    $mediaName = $media !== '' ? (string)($opts['media_name'] ?? basename($media)) : null;
    $isPhoto = $media !== '' && preg_match('/\.(jpe?g|png|webp)$/i', $media);

    // Kunde: mitgegeben oder über die verknüpfte Chat-ID
    $row = $db->getOne(
        "INSERT INTO telegram_messages (chat_id, direction, customer_id, message_type, message_text, media_url, media_name,
                                        status, source, employee_id)
         VALUES (CAST(:chat AS text), 'O',
                 COALESCE(CAST(:cid AS int),
                          (SELECT customer_id FROM customer_ext WHERE telegram_chat_id = CAST(:chat AS text) LIMIT 1),
                          (SELECT vendor_id FROM vendor_ext WHERE telegram_chat_id = CAST(:chat AS text) LIMIT 1)),
                 CAST(:type AS text), NULLIF(CAST(:text AS text), ''), CAST(:media AS text), CAST(:media_name AS text),
                 'pending', CAST(:source AS text), CAST(:emp AS int))
         RETURNING id, customer_id",
        [
            ':chat' => $chatId,
            ':cid' => !empty($opts['customer_id']) ? (int)$opts['customer_id'] : null,
            ':type' => $media === '' ? 'text' : ($isPhoto ? 'photo' : 'document'),
            ':text' => $text,
            ':media' => $media ?: null,
            ':media_name' => $mediaName,
            ':source' => $opts['source'] ?? 'chat',
            ':emp' => !empty($opts['employee_id']) ? (int)$opts['employee_id'] : null,
        ]
    );
    $id = (int)$row['id'];

    $res = [];
    $err = '';
    if ($media === '') {
        [$res, $err] = tgRequest($cfg['token'], 'sendMessage', ['chat_id' => $chatId, 'text' => $text]);
    } else {
        $abs = fmDataDirForDb(tgDbName($db)).'/'.$media;
        if (!is_file($abs)) {
            $err = 'Datei nicht gefunden';
        } else {
            $send = true;
            if (mb_strlen($text) > 1024) {
                [$res, $err] = tgRequest($cfg['token'], 'sendMessage', ['chat_id' => $chatId, 'text' => $text]);
                $send = $err === '' && !empty($res['ok']);
                $text = '';
            }
            if ($send) {
                [$res, $err] = tgRequest($cfg['token'], $isPhoto ? 'sendPhoto' : 'sendDocument', array_filter([
                    'chat_id' => $chatId,
                    $isPhoto ? 'photo' : 'document' => new CURLFile($abs, mime_content_type($abs) ?: 'application/octet-stream', $mediaName),
                    'caption' => $text,
                ], fn($v) => $v !== ''));
            }
        }
    }
    $ok = $err === '' && !empty($res['ok']);
    $error = $ok ? null : ($err !== '' ? $err : (string)($res['description'] ?? 'Telegram-Fehler'));

    $db->execute(
        "UPDATE telegram_messages SET status = CAST(:status AS text), telegram_message_id = CAST(:mid AS bigint),
                error_message = CAST(:error AS text), mtime = now()
          WHERE id = CAST(:id AS int)",
        [':status' => $ok ? 'sent' : 'failed', ':mid' => $ok ? ($res['result']['message_id'] ?? null) : null, ':error' => $error, ':id' => $id]
    );

    $out = ['id' => $id, 'customer_id' => $row['customer_id'] ? (int)$row['customer_id'] : null,
            'reference' => $ok ? (string)($res['result']['message_id'] ?? '') : null];
    if ($ok) return ['success' => true] + $out;
    $out += ['success' => false, 'code' => 'TELEGRAM_SEND_FAILED', 'error' => $error];
    if ((int)($res['error_code'] ?? 0) === 429) $out['retry_after'] = (int)($res['parameters']['retry_after'] ?? 30);
    return $out;
}

/**
 * Name der Mandanten-Datenbank (Datenverzeichnis)
 */
function tgDbName($db): string {
    static $name = null;
    return $name ??= (string)($db->getOne("SELECT current_database() AS dbname")['dbname'] ?? '');
}
