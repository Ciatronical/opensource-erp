<?php
// backend/api/sms/sms_lib.php
//
// SMS über seven.io (ehemals sms77) – gemeinsamer Kern für die SMS-API
// (backend/api/sms/sms.php), die WhatsApp-Ausweiche (backend/api/whatsapp,
// backend/webhook/whatsapp.php), Rundschreiben und den Eingangs-Webhook
// (backend/webhook/sms.php).
//
// Jede Funktion bekommt die Datenbank ($db, ApiDatabase) übergeben, weil die
// Webhooks ohne Mitarbeiter-Sitzung laufen und mehrere Mandanten bedienen.
//
// Einstellungen (defaults_oserp):
//   sms_api_key          Zugang bei seven.io; leer = kein SMS-Versand
//   sms_sender           Absenderkennung (11 Zeichen oder Rufnummer)
//   sms_cost             Kosten je SMS-Teil (nur Anzeige)
//   sms_whatsapp_fallback  '0' schaltet die automatische Ausweiche ab (Vorgabe an)
//   sms_link_days        Gültigkeit der Beleglinks in Tagen (Vorgabe 90)
//   sms_public_url       Öffentliche Adresse für Links und Webhook (sonst Host der Anfrage)
//   sms_webhook_secret   Geheimnis im Webhook-Pfad (beim Einrichten erzeugt)
//   sms_signing_key      optional: Signierschlüssel aus dem seven.io-Konto
//   sms_hook_ids         IDs der bei seven.io angemeldeten Webhooks (Komma-Liste)
//   sms_reply_hint       Antwort-Hinweis unter jeder SMS; {telegram} = t.me/<Bot>, leer = keiner
//   whatsapp_country_code  Landesvorwahl für Nummern ohne + (gemeinsam mit WhatsApp)

require_once __DIR__.'/../telegram/telegram_lib.php';

const SMS_GATEWAY = 'https://gateway.seven.io/api';

/**
 * SMS-Einstellungen in einer Abfrage
 *
 * @param object $db ApiDatabase des Mandanten
 * @return array api_key, sender, cost, fallback, link_days, public_url, country_code, dbname, …
 */
function smsSettings($db): array {
    static $cache = [];
    $row = $db->getOne(
        "SELECT current_database() AS dbname,
                json_object_agg(key, value) FILTER (WHERE key IS NOT NULL) AS cfg
           FROM (SELECT key, value FROM defaults_oserp
                  WHERE key LIKE 'sms\\_%' OR key = 'whatsapp_country_code') d"
    );
    $dbname = (string)($row['dbname'] ?? '');
    if (isset($cache[$dbname])) return $cache[$dbname];
    $cfg = json_decode((string)($row['cfg'] ?? ''), true) ?: [];
    $wert = fn($k, $vorgabe = '') => trim((string)($cfg[$k] ?? $vorgabe));
    return $cache[$dbname] = [
        'dbname'         => $dbname,
        'api_key'        => $wert('sms_api_key'),
        'sender'         => $wert('sms_sender'),
        'cost'           => (float)str_replace(',', '.', $wert('sms_cost', '0')),
        'fallback'       => !in_array(strtolower($wert('sms_whatsapp_fallback', 'true')), ['0', 'false', 'f', 'off', 'nein'], true),
        'link_days'      => max(1, (int)$wert('sms_link_days', '90')),
        'public_url'     => rtrim($wert('sms_public_url'), '/'),
        'webhook_secret' => $wert('sms_webhook_secret'),
        'signing_key'    => $wert('sms_signing_key'),
        'hook_ids'       => $wert('sms_hook_ids'),
        'reply_hint'     => $wert('sms_reply_hint'),
        'country_code'   => $wert('whatsapp_country_code', '49') ?: '49',
    ];
}

/**
 * Rufnummer ins Format +49… bringen (00… und 0… werden umgesetzt)
 *
 * @param string $phone Rufnummer in beliebiger Schreibweise
 * @param string $countryCode Landesvorwahl ohne +
 * @return string '' bei leerer/ungültiger Nummer
 */
function smsNormalizePhone(string $phone, string $countryCode = '49'): string {
    $plus = str_starts_with(trim($phone), '+');
    $digits = preg_replace('/\D/', '', $phone);
    if ($digits === '') return '';
    if (!$plus) {
        if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
        elseif ($digits[0] === '0') $digits = $countryCode.substr($digits, 1);
        else $digits = $countryCode.$digits;
    }
    return strlen($digits) >= 7 ? '+'.$digits : '';
}

/**
 * Öffentliche Basisadresse (https://host) für Links und Webhook
 *
 * Eingestellte Adresse gewinnt; sonst der Host der laufenden Anfrage, der beim
 * ersten Mal gespeichert wird – Webhooks und Cron haben keinen eigenen Host.
 *
 * @param object $db ApiDatabase
 * @return string '' wenn unbekannt
 */
function smsPublicBase($db): string {
    $cfg = smsSettings($db);
    if ($cfg['public_url'] !== '') return $cfg['public_url'];
    $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''));
    $https = (!empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS']) || 'https' === ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    // Nur echte, von außen erreichbare Namen lernen – nicht den Aufruf im LAN
    // (IP-Adresse, localhost, .local/.lan/.fritz.box) oder ohne https
    $name = strtolower(preg_replace('/:\d+$/', '', $host));
    if (!$https || $name === '' || !str_contains($name, '.') || filter_var(trim($name, '[]'), FILTER_VALIDATE_IP)
        || preg_match('/(^localhost$|\.(local|lan|home|internal|fritz\.box)$)/', $name)) {
        return '';
    }
    $base = 'https://'.$host;
    $db->execute(
        "INSERT INTO defaults_oserp (key, value) VALUES ('sms_public_url', :v)
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value WHERE btrim(COALESCE(defaults_oserp.value, '')) = ''",
        [':v' => $base]
    );
    return $base;
}

/**
 * Link auf eine mitgeschickte Datei
 *
 * @param object $db ApiDatabase
 * @param string $token link_token der SMS
 * @return string '' wenn keine öffentliche Adresse bekannt ist
 */
function smsLinkUrl($db, string $token): string {
    $base = smsPublicBase($db);
    if ($base === '') return '';
    return $base.'/webhook/beleg.php?k='.rawurlencode(smsSettings($db)['dbname'].'.'.$token);
}

/**
 * Zufälliger, nicht erratbarer Link-Schlüssel (16 Zeichen, ~95 Bit)
 */
function smsNewToken(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < 16; $i++) $t .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $t;
}

/**
 * Anfrage an seven.io
 *
 * @param string $apiKey Zugang
 * @param string $method GET | POST | DELETE
 * @param string $path z. B. 'sms', 'balance', 'hooks'
 * @param array  $params Formularfelder bzw. Abfrageparameter
 * @return array [httpCode, body (decodiert oder roh), curlFehler]
 */
function smsSevenRequest(string $apiKey, string $method, string $path, array $params = []): array {
    $url = SMS_GATEWAY.'/'.$path;
    $ch = curl_init();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['X-Api-Key: '.$apiKey, 'Accept: application/json'],
    ];
    if ($method === 'GET') {
        $opts[CURLOPT_URL] = $url.($params ? '?'.http_build_query($params) : '');
    } else {
        $opts[CURLOPT_URL] = $url;
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return [$code, $json ?? $body, $err];
}

/**
 * Bekannte Rückgabecodes von seven.io in Klartext (für Protokoll und Chat)
 */
function smsSevenErrorText(string $code): string {
    $texte = [
        '101' => 'Versand an mindestens einen Empfänger fehlgeschlagen',
        '201' => 'Ungültiger Absender',
        '202' => 'Ungültige Empfängernummer',
        '301' => 'Empfänger fehlt',
        '305' => 'Ungültiger Text oder zu viele Teile',
        '401' => 'Text zu lang',
        '402' => 'Gleiche SMS innerhalb von 180 Sekunden schon gesendet',
        '403' => 'Tageslimit für diesen Empfänger erreicht',
        '407003' => 'Empfänger gesperrt',
        '407004' => 'Empfänger hat SMS abbestellt',
        '500' => 'Guthaben bei seven.io reicht nicht',
        '501' => 'Ausgabelimit bei seven.io erreicht',
        '600' => 'Versandfehler bei seven.io',
        '900' => 'Anmeldung bei seven.io fehlgeschlagen (API-Schlüssel prüfen)',
        '902' => 'API-Schlüssel hat keine Berechtigung',
        '903' => 'IP-Adresse bei seven.io nicht freigegeben',
    ];
    return $texte[$code] ?? ('seven.io-Code '.$code);
}

/**
 * Antwort-Hinweis für das Ende jeder SMS (Absender mit Firmennamen lassen keine
 * Antwort zu): {telegram} wird zu t.me/<Bot>. Ohne eingerichteten Bot entfällt
 * ein Hinweis, der auf Telegram verweist.
 *
 * @param object $db ApiDatabase
 * @return string '' = kein Hinweis
 */
function smsReplyHint($db): string {
    $hint = smsSettings($db)['reply_hint'];
    if ($hint === '' || !str_contains($hint, '{telegram}')) return $hint;
    $bot = tgBotLink($db);
    return $bot === '' ? '' : str_replace('{telegram}', $bot, $hint);
}

/**
 * Kunde/Lieferant zu einer Rufnummer (Datenbankfunktion cv_by_phone)
 *
 * @return array|null cv_id, cv_src, cv_name
 */
function smsFindCv($db, string $phone): ?array {
    $row = $db->getOne("SELECT cv_id, cv_src, cv_name FROM cv_by_phone(:p)", [':p' => $phone]);
    return $row ?: null;
}

/**
 * SMS senden und im Verlauf ablegen
 *
 * Die Zeile entsteht VOR dem Versand (status pending) – so steht die SMS auch
 * bei einem Abbruch im Verlauf, und eine Ausweich-SMS je WhatsApp kann über den
 * eindeutigen whatsapp_message_id nur einmal entstehen. Ein Platzhalter {link}
 * im Text wird durch den Link auf die mitgeschickte Datei ersetzt; fehlt er,
 * kommt der Link ans Ende. Darunter steht der Antwort-Hinweis (smsReplyHint).
 *
 * @param object $db    ApiDatabase des Mandanten
 * @param string $phone Rufnummer (beliebige Schreibweise)
 * @param string $text  Nachricht
 * @param array  $opts  customer_id, source, employee_id, whatsapp_message_id, whatsapp_error,
 *                      media_url (relativ zum Datenverzeichnis), media_name, record_table, record_id,
 *                      reply_hint (false = ohne Antwort-Hinweis)
 * @return array success, id, error, code, phone, text, link, parts, price, duplicate
 */
function smsDeliver($db, string $phone, string $text, array $opts = []): array {
    $cfg = smsSettings($db);
    $fail = fn(string $code, string $error, array $extra = []) => array_merge(['success' => false, 'code' => $code, 'error' => $error], $extra);

    if ($cfg['api_key'] === '') return $fail('SMS_NOT_CONFIGURED', 'SMS-Versand ist nicht eingerichtet');
    $to = smsNormalizePhone($phone, $cfg['country_code']);
    if ($to === '') return $fail('SMS_INVALID_NUMBER', 'Ungültige Rufnummer');
    $text = trim(str_replace("\r\n", "\n", $text));

    // Datei als Link
    $token = null;
    $link = '';
    if (!empty($opts['media_url'])) {
        $token = smsNewToken();
        $link = smsLinkUrl($db, $token);
        if ($link === '') return $fail('SMS_NO_PUBLIC_URL', 'Öffentliche Adresse für den Link fehlt (Firmenkonfiguration → SMS)');
        $text = str_contains($text, '{link}') ? str_replace('{link}', $link, $text) : rtrim($text."\n".$link);
    } else {
        $text = trim(str_replace('{link}', '', $text));
    }
    if ($text === '') return $fail('SMS_EMPTY', 'Kein Text');
    $hint = ($opts['reply_hint'] ?? true) ? smsReplyHint($db) : '';
    if ($hint !== '' && !str_contains($text, $hint)) $text .= "\n".$hint;

    // Zeile anlegen – Kunde über die Rufnummer, wenn keiner mitkommt; bei
    // Ausweich-SMS bricht ein zweiter Versuch für dieselbe WhatsApp hier ab.
    $row = $db->getOne(
        "INSERT INTO sms_messages (direction, phone_number, customer_id, message_text, status, source, employee_id,
                                   whatsapp_message_id, whatsapp_error, media_url, media_name, link_token, link_expires)
         SELECT 'O', CAST(:phone AS text), COALESCE(CAST(:cid AS int), (SELECT cv_id FROM cv_by_phone(CAST(:phone AS text)))),
                CAST(:text AS text), 'pending', CAST(:source AS text), CAST(:emp AS int),
                CAST(:wa_id AS int), CAST(:wa_err AS text), CAST(:media_url AS text), CAST(:media_name AS text), CAST(:token AS text),
                CASE WHEN CAST(:token AS text) IS NOT NULL THEN now() + make_interval(days => CAST(:days AS int)) END
         ON CONFLICT (whatsapp_message_id) DO NOTHING
         RETURNING id, customer_id",
        [
            ':phone' => $to,
            ':cid' => !empty($opts['customer_id']) ? (int)$opts['customer_id'] : null,
            ':text' => $text,
            ':source' => $opts['source'] ?? 'chat',
            ':emp' => !empty($opts['employee_id']) ? (int)$opts['employee_id'] : null,
            ':wa_id' => !empty($opts['whatsapp_message_id']) ? (int)$opts['whatsapp_message_id'] : null,
            ':wa_err' => $opts['whatsapp_error'] ?? null,
            ':media_url' => $opts['media_url'] ?? null,
            ':media_name' => $opts['media_name'] ?? null,
            ':token' => $token,
            ':days' => $cfg['link_days'],
        ]
    );
    if (!$row) return $fail('SMS_DUPLICATE', 'Ausweich-SMS wurde bereits gesendet', ['duplicate' => true]);
    $id = (int)$row['id'];

    [$http, $res, $curlErr] = smsSevenRequest($cfg['api_key'], 'POST', 'sms', array_filter([
        'to' => ltrim($to, '+'),
        'text' => $text,
        'from' => $cfg['sender'],
        'foreign_id' => 'oserp'.$id,
    ], fn($v) => $v !== '' && $v !== null));

    $code = is_array($res) ? (string)($res['success'] ?? '') : trim((string)$res);
    $msg = is_array($res) ? ($res['messages'][0] ?? []) : [];
    $ok = $curlErr === '' && $code === '100' && ($msg === [] || !empty($msg['success']));
    $errorCode = $ok ? null : ($curlErr !== '' ? 'CURL' : ($code !== '' && $code !== '100' ? $code : (string)($msg['error'] ?? 'HTTP'.$http)));
    $errorText = $ok ? null : ($curlErr !== '' ? $curlErr : (!empty($msg['error_text']) ? $msg['error_text'] : smsSevenErrorText((string)$errorCode)));

    // Ergebnis eintragen; der Beleg bekommt die Verknüpfung für die Versandanzeige
    $db->execute(
        "WITH upd AS (
            UPDATE sms_messages SET status = CAST(:status AS text), sms_id = CAST(:sms_id AS text), parts = CAST(:parts AS int),
                   price = CAST(:price AS numeric), error_code = CAST(:ecode AS text), error_message = CAST(:etext AS text),
                   status_timestamp = now(), mtime = now()
             WHERE id = CAST(:id AS int)
         )
         INSERT INTO record_links (from_table, from_id, to_table, to_id)
         SELECT CAST(:rt AS text), CAST(:rid AS int), 'sms_messages', CAST(:id AS int)
          WHERE CAST(:ok AS boolean) AND CAST(:rt AS text) IS NOT NULL AND CAST(:rid AS int) > 0",
        [
            ':status' => $ok ? 'sent' : 'failed',
            ':sms_id' => isset($msg['id']) ? (string)$msg['id'] : null,
            ':parts' => isset($msg['parts']) ? (int)$msg['parts'] : null,
            ':price' => isset($msg['price']) ? (string)$msg['price'] : (is_array($res) && isset($res['total_price']) ? (string)$res['total_price'] : null),
            ':ecode' => $errorCode,
            ':etext' => $errorText,
            ':id' => $id,
            ':ok' => $ok ? 'true' : 'false',
            ':rt' => in_array($opts['record_table'] ?? '', ['oe', 'ar', 'ap', 'delivery_orders'], true) ? $opts['record_table'] : null,
            ':rid' => (int)($opts['record_id'] ?? 0),
        ]
    );

    $base = ['id' => $id, 'phone' => $to, 'text' => $text, 'link' => $link, 'customer_id' => $row['customer_id'] ? (int)$row['customer_id'] : null];
    if (!$ok) return $fail('SMS_SEND_FAILED', (string)$errorText, $base + ['seven_code' => $errorCode]);
    return ['success' => true, 'parts' => (int)($msg['parts'] ?? 1), 'price' => (float)($msg['price'] ?? 0)] + $base;
}

/**
 * Datei für den Versand ablegen: im Ordner des Kunden/Lieferanten unter sms/
 * (bzw. telegram/), ohne Zuordnung unter sms/<Jahr-Monat>/. Liefert den Pfad
 * relativ zum Datenverzeichnis des Mandanten.
 *
 * @param object $db ApiDatabase
 * @param int    $cvId Kunde/Lieferant (0 = keiner)
 * @param string $binary Dateiinhalt
 * @param string $filename gewünschter Dateiname
 * @param string $sub Unterordner: sms | telegram
 * @return string relativer Pfad
 * @throws ApiError wenn die Datei nicht gespeichert werden kann
 */
function smsStoreFile($db, int $cvId, string $binary, string $filename, string $sub = 'sms'): string {
    if ($binary === '' || strlen($binary) > FM_MAX_FILESIZE) {
        throw new ApiError('SMS_FILE_INVALID', 'Datei fehlt oder ist größer als 20 MB');
    }
    $name = preg_replace('/[^\p{L}\p{N}._ -]/u', '_', basename($filename)) ?: 'Dokument.pdf';
    $cv = $cvId > 0
        ? $db->getOne("SELECT CASE WHEN EXISTS (SELECT 1 FROM vendor WHERE id = :id) THEN 'V' ELSE 'C' END AS src", [':id' => $cvId])
        : null;
    $rel = $cv ? fmGetBasePath($cv['src']).'/'.$cvId.'/'.$sub : $sub.'/'.date('Y-m');
    $dir = fmDataDir().'/'.$rel;
    fmMkdir($dir);
    $pi = pathinfo($name);
    $ziel = $name;
    for ($n = 1; file_exists($dir.'/'.$ziel); $n++) {
        $ziel = $pi['filename'].'_'.$n.(isset($pi['extension']) ? '.'.$pi['extension'] : '');
    }
    if (file_put_contents($dir.'/'.$ziel, $binary) === false) {
        throw new ApiError('SMS_FILE_WRITE', 'Datei konnte nicht gespeichert werden');
    }
    return $rel.'/'.$ziel;
}

/**
 * Ausweich-SMS für eine WhatsApp, die nicht zugestellt werden kann
 *
 * Greift nur, wenn SMS eingerichtet und die Ausweiche nicht abgeschaltet ist.
 * Belege und Dateien, die als WhatsApp-Anhang gedacht waren, gehen als Link mit,
 * sofern eine lokale Kopie im Datenverzeichnis liegt.
 *
 * @param object $db  ApiDatabase
 * @param array  $wa  phone, text, reason, customer_id, whatsapp_message_id, media_url, media_name,
 *                    record_table, record_id, employee_id
 * @return array|null Ergebnis von smsDeliver, null wenn die Ausweiche nicht greift
 */
function smsWhatsAppFallback($db, array $wa): ?array {
    $cfg = smsSettings($db);
    if ($cfg['api_key'] === '' || !$cfg['fallback']) return null;
    $text = trim((string)($wa['text'] ?? ''));
    $media = (string)($wa['media_url'] ?? '');
    // Nur lokale Kopien taugen als Link (Meta-Media-IDs sind reine Ziffern).
    // smsStoreFile braucht den Dateimanager (customer_vendor/filemanager.php).
    if ($media !== '' && !preg_match('#^(customers/\d+|vendors/\d+|sms)/#', $media)) $media = '';
    // Ohne öffentliche Adresse lieber den Text ohne Link als gar keine SMS
    if ($media !== '' && smsPublicBase($db) === '') $media = '';
    if ($text === '' && $media === '') return null;
    $res = smsDeliver($db, (string)($wa['phone'] ?? ''), $text, [
        'customer_id' => $wa['customer_id'] ?? null,
        'source' => 'fallback',
        'employee_id' => $wa['employee_id'] ?? null,
        'whatsapp_message_id' => $wa['whatsapp_message_id'] ?? null,
        'whatsapp_error' => mb_substr((string)($wa['reason'] ?? ''), 0, 500),
        'media_url' => $media ?: null,
        'media_name' => $media ? ($wa['media_name'] ?? basename($media)) : null,
        'record_table' => $wa['record_table'] ?? null,
        'record_id' => $wa['record_id'] ?? null,
    ]);
    if (!empty($res['duplicate'])) return null;
    return $res;
}

/**
 * Antwort-Nutzlast für das Frontend nach einer Ausweich-SMS (globaler Hinweis in main.js)
 */
function smsFallbackPayload(array $sms, string $reason): array {
    return [
        'success' => (bool)$sms['success'],
        'id' => $sms['id'] ?? null,
        'phone' => $sms['phone'] ?? '',
        'reason' => $reason,
        'error' => $sms['success'] ? null : ($sms['error'] ?? ''),
    ];
}
