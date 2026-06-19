<?php
// backend/webhook/telegram.php
// Telegram-Bot Webhook fuer Sprachnotizen -> Whisper -> Anschlagtafel
// KEIN Session-Check — Telegram sendet keine Cookies!
//
// Ablauf:
//   Telegram-Sprachnachricht (Voice/Audio)
//     -> Company-DB anhand X-Telegram-Bot-Api-Secret-Token finden
//        -> Audiodatei von Telegram herunterladen (getFile + Download)
//           -> lokal speichern (data/<dbname>/voicenotes/)
//              -> Whisper-Dienst (127.0.0.1:3002) transkribiert
//                 -> INSERT in voice_notes  (Trigger feuert pg_notify -> SSE)
//                    -> optionale Bestaetigung an den Absender zuruecksenden
//
// Webhook einrichten (einmalig, pro Firmen-Bot):
//   curl "https://api.telegram.org/bot<TOKEN>/setWebhook" \
//        -d url="https://melissa.spdns.de/webhook/telegram.php" \
//        -d secret_token="<GEHEIM>"
//   Den gleichen <GEHEIM> als defaults_oserp.telegram_webhook_secret speichern.

header('Content-Type: application/json');

require_once __DIR__.'/../api/config.php';

if (OSERP_SETUP_MODE) {
    http_response_code(503);
    echo json_encode(['error' => 'Setup not complete']);
    exit;
}

OserpConfig::init();

// Telegram sendet ausschliesslich POST. GET nur als simple Lebendmeldung.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(200);
    echo json_encode(['status' => 'telegram webhook ready']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Telegram erwartet schnell 200 OK, sonst wird wiederholt zugestellt.
http_response_code(200);

$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['message'])) {
    echo json_encode(['status' => 'ignored']);
    exit;
}

// Leeres Secret nie akzeptieren (sonst wuerde ein nicht konfigurierter
// Mandant auf jede Anfrage matchen).
if ($secret === '') {
    echo json_encode(['status' => 'no secret']);
    exit;
}

$dbInfo = _findCompanyDbBySecret($secret);
if (!$dbInfo) {
    echo json_encode(['status' => 'unknown bot']);
    exit;
}

try {
    $pdo = _connectCompanyDb($dbInfo);
    _processVoiceMessage($pdo, $dbInfo, $input['message']);
} catch (\Throwable $e) {
    error_log('[TELEGRAM WEBHOOK] ' . $e->getMessage());
}

echo json_encode(['status' => 'ok']);
exit;

// ============================================================================
// Hilfsfunktionen
// ============================================================================

/**
 * Auth-DB Verbindung herstellen
 */
function _getAuthPdo(): PDO {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_AUTH_NAME),
        DB_AUTH_USER, DB_AUTH_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/**
 * Verbindung zu einer Company-DB herstellen
 */
function _connectCompanyDb(array $dbInfo): PDO {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbInfo['dbhost'], $dbInfo['dbport'], $dbInfo['dbname']),
        $dbInfo['dbuser'], $dbInfo['dbpasswd']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/**
 * Company-DB anhand des Telegram-Webhook-Secrets finden.
 * Durchsucht alle Mandanten nach defaults_oserp.telegram_webhook_secret.
 */
function _findCompanyDbBySecret(string $secret): ?array {
    try {
        $auth = _getAuthPdo();
        $clients = $auth->query("SELECT dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($clients as $client) {
            try {
                $companyPdo = _connectCompanyDb($client);
                $stmt = $companyPdo->prepare("SELECT value FROM defaults_oserp WHERE key = 'telegram_webhook_secret'");
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['value'] !== '' && hash_equals((string)$row['value'], $secret)) {
                    return $client;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
    } catch (\Exception $e) {
        error_log('[TELEGRAM WEBHOOK] Auth DB error: ' . $e->getMessage());
    }
    return null;
}

/**
 * Einen Konfigurationswert aus defaults_oserp lesen.
 */
function _cfg(PDO $pdo, string $key, string $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT value FROM defaults_oserp WHERE key = :k");
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && $row['value'] !== null) ? (string)$row['value'] : $default;
    } catch (\Exception $e) {
        return $default;
    }
}

/**
 * Anzeigenamen des Absenders bestimmen.
 * Bevorzugt das Mapping telegram_chat_map ({"<chat_id>": "Name"}),
 * sonst der Telegram-Profilname (Vor-/Nachname oder @username).
 */
function _resolveSenderName(PDO $pdo, string $chatId, array $from): string {
    $mapRaw = _cfg($pdo, 'telegram_chat_map', '');
    if ($mapRaw !== '') {
        $map = json_decode($mapRaw, true);
        if (is_array($map) && !empty($map[$chatId])) {
            return (string)$map[$chatId];
        }
    }
    $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
    if ($name === '' && !empty($from['username'])) {
        $name = '@' . $from['username'];
    }
    return $name !== '' ? $name : 'Unbekannt';
}

/**
 * Eingehende Telegram-Nachricht verarbeiten (nur Voice/Audio).
 */
function _processVoiceMessage(PDO $pdo, array $dbInfo, array $message): void {
    // Nur Sprach-/Audionachrichten interessieren uns.
    $media = $message['voice'] ?? $message['audio'] ?? null;
    if (!$media || empty($media['file_id'])) {
        return;
    }

    $messageId = (string)($message['message_id'] ?? '');
    $chatId    = (string)($message['chat']['id'] ?? ($message['from']['id'] ?? ''));
    $from      = $message['from'] ?? [];
    $duration  = isset($media['duration']) ? (float)$media['duration'] : null;
    $mime      = $media['mime_type'] ?? 'audio/ogg';
    $fileId    = $media['file_id'];

    // Doppelte Zustellung frueh abfangen (Telegram wiederholt bei Timeout).
    try {
        $chk = $pdo->prepare("SELECT 1 FROM voice_notes WHERE telegram_message_id = :m LIMIT 1");
        $chk->execute([':m' => $messageId]);
        if ($chk->fetch()) return;
    } catch (\Exception $e) {
        // weiter — ON CONFLICT faengt es ohnehin ab
    }

    $senderName = _resolveSenderName($pdo, $chatId, $from);
    $botToken   = _cfg($pdo, 'telegram_bot_token', '');

    // Audiodatei von Telegram herunterladen
    $audioBytes = '';
    $localPath  = null;
    if ($botToken !== '') {
        $audioBytes = _downloadTelegramFile($botToken, $fileId);
        if ($audioBytes !== '') {
            $localPath = _saveAudio($dbInfo['dbname'], $audioBytes, $mime);
        }
    }

    // Transkribieren (Whisper-Dienst). Schlaegt das fehl, wird die Notiz
    // trotzdem gespeichert (status 'failed'), damit nichts verloren geht.
    $transcript = '';
    $language   = null;
    $status     = 'failed';
    if ($audioBytes !== '') {
        $result = _transcribe($pdo, $audioBytes);
        if ($result !== null) {
            $transcript = $result['text'];
            $language   = $result['language'] ?? null;
            $status     = 'transcribed';
        }
    }

    // Speichern — Trigger voice_notes_notify feuert pg_notify('voicenote_change')
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO voice_notes
                (telegram_message_id, telegram_chat_id, sender_name, audio_file,
                 duration, transcript, language, status)
             VALUES
                (:msg, :chat, :sender, :audio, :duration, :transcript, :lang, :status)
             ON CONFLICT (telegram_message_id) DO NOTHING"
        );
        $stmt->execute([
            ':msg'        => $messageId,
            ':chat'       => $chatId,
            ':sender'     => $senderName,
            ':audio'      => $localPath,
            ':duration'   => $duration,
            ':transcript' => $transcript !== '' ? $transcript : null,
            ':lang'       => $language,
            ':status'     => $status,
        ]);
    } catch (\Exception $e) {
        error_log('[TELEGRAM WEBHOOK] Insert error: ' . $e->getMessage());
        return;
    }

    // Optionale Bestaetigung an den Absender (UX: "kein Browser").
    if ($botToken !== '' && _cfg($pdo, 'telegram_confirm_reply', '1') === '1') {
        $reply = $status === 'transcribed'
            ? "\u{2705} Notiz aufgenommen:\n" . mb_substr($transcript, 0, 350)
            : "\u{26A0}\u{FE0F} Audio gespeichert, aber Transkription fehlgeschlagen.";
        _sendTelegramMessage($botToken, $chatId, $reply);
    }
}

/**
 * Audiodatei von Telegram herunterladen (getFile + Datei-Download).
 * @return string Rohe Bytes oder '' bei Fehler.
 */
function _downloadTelegramFile(string $botToken, string $fileId): string {
    // Schritt 1: file_path holen
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/getFile?file_id=" . urlencode($fileId));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        error_log("[TELEGRAM WEBHOOK] getFile fehlgeschlagen: HTTP {$code}");
        return '';
    }
    $info = json_decode($resp, true);
    $filePath = $info['result']['file_path'] ?? '';
    if ($filePath === '') return '';

    // Schritt 2: Datei laden
    $ch = curl_init("https://api.telegram.org/file/bot{$botToken}/" . $filePath);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || $data === false || $data === '') {
        error_log("[TELEGRAM WEBHOOK] Datei-Download fehlgeschlagen: HTTP {$code}");
        return '';
    }
    return $data;
}

/**
 * Audiodatei lokal ablegen unter data/<dbname>/voicenotes/.
 * @return string|null Relativer Pfad ab data/<dbname>/ oder null.
 */
function _saveAudio(string $dbname, string $bytes, string $mime): ?string {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbname)) {
        error_log("[TELEGRAM WEBHOOK] Ungueltiger dbname: {$dbname}");
        return null;
    }
    $extMap = ['audio/ogg' => 'ogg', 'audio/opus' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-wav' => 'wav', 'audio/wav' => 'wav'];
    $mimeBase = strtolower(trim(explode(';', $mime)[0]));
    $ext = $extMap[$mimeBase] ?? 'ogg';

    $baseDir = realpath(__DIR__ . '/../data') ?: (__DIR__ . '/../data');
    $dir = $baseDir . '/' . $dbname . '/voicenotes';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log("[TELEGRAM WEBHOOK] Verzeichnis nicht anlegbar: {$dir}");
        return null;
    }
    $filename = date('Y-m-d_H-i-s') . '_' . substr(uniqid(), -4) . '.' . $ext;
    $full = $dir . '/' . $filename;
    if (file_put_contents($full, $bytes) === false) {
        error_log("[TELEGRAM WEBHOOK] Audio nicht speicherbar: {$full}");
        return null;
    }
    return 'voicenotes/' . $filename;
}

/**
 * Audio per Whisper-Dienst transkribieren.
 * @return array{text:string,language:?string}|null
 */
function _transcribe(PDO $pdo, string $audioBytes): ?array {
    $url = rtrim(_cfg($pdo, 'whisper_url', 'http://127.0.0.1:3002'), '/') . '/transcribe';
    $token = _cfg($pdo, 'whisper_token', '');

    $headers = ['Content-Type: application/octet-stream'];
    if ($token !== '') $headers[] = 'X-Whisper-Token: ' . $token;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $audioBytes,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        error_log("[TELEGRAM WEBHOOK] Whisper HTTP {$code}: " . substr((string)$resp, 0, 200));
        return null;
    }
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['ok'])) {
        return null;
    }
    return ['text' => trim($data['text'] ?? ''), 'language' => $data['language'] ?? null];
}

/**
 * Kurze Textnachricht an einen Telegram-Chat senden (Bestaetigung).
 */
function _sendTelegramMessage(string $botToken, string $chatId, string $text): void {
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chatId, 'text' => $text]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
