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
    // Auch Kommando-Nachrichten hinterlassen eine (ausgeblendete) Zeile, damit
    // ein erneutes Zustellen nicht ein zweites Mal loescht.
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
    if ($botToken !== '') {
        $audioBytes = _downloadTelegramFile($botToken, $fileId);
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

    // ── Sprachbefehle erkennen (nur bei erfolgreicher Transkription) ──
    $command = $status === 'transcribed'
        ? _detectCommand($transcript)
        : ['cmd' => null, 'text' => $transcript];

    if ($command['cmd'] === 'help') {
        _recordCommand($pdo, $messageId, $chatId, $senderName, $transcript, $duration, $language);
        _confirm($botToken, $chatId, _helpText());
        return;
    }

    if ($command['cmd'] === 'clear_all') {
        _recordCommand($pdo, $messageId, $chatId, $senderName, $transcript, $duration, $language);
        _clearAll($pdo);
        _confirm($botToken, $chatId, "\u{1F9F9} Alle Eintraege geloescht.");
        return;
    }

    if ($command['cmd'] === 'delete') {
        _recordCommand($pdo, $messageId, $chatId, $senderName, $transcript, $duration, $language);
        $removed = _deleteAt($pdo, $command['from'], $command['n']);
        $label = _positionLabel($command['from'], $command['n']);
        _confirm($botToken, $chatId, $removed !== null
            ? "\u{1F5D1}\u{FE0F} {$label} geloescht."
            : "\u{2139}\u{FE0F} Kein Eintrag an dieser Position vorhanden.");
        return;
    }

    if ($command['cmd'] === 'correction') {
        _recordCommand($pdo, $messageId, $chatId, $senderName, $transcript, $duration, $language);
        $label = _positionLabel($command['from'], $command['n']);
        if ($command['text'] === '') {
            // Ohne neuen Text: Eintrag an der Position entfernen (Rueckgaengig-Machen).
            $removed = _deleteAt($pdo, $command['from'], $command['n']);
            _confirm($botToken, $chatId, $removed !== null
                ? "\u{270F}\u{FE0F} {$label} geloescht (Korrektur)."
                : "\u{2139}\u{FE0F} Kein Eintrag an dieser Position vorhanden.");
            return;
        }
        // Mit Text: Eintrag an Ort und Stelle ueberschreiben, Position bleibt erhalten.
        $updated = _updateAt($pdo, $command['from'], $command['n'], $command['text']);
        _confirm($botToken, $chatId, $updated !== null
            ? "\u{270F}\u{FE0F} {$label} korrigiert:\n" . mb_substr($command['text'], 0, 350)
            : "\u{2139}\u{FE0F} Kein Eintrag an dieser Position vorhanden.");
        return;
    }

    // ── Normale Notiz speichern ──
    // Audio erst jetzt ablegen, damit reine Kommandos keine Dateileichen erzeugen.
    $localPath = null;
    if ($audioBytes !== '') {
        $localPath = _saveAudio($dbInfo['dbname'], $audioBytes, $mime);
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
            ? "\u{2705} Eintrag auf der Tafel sichtbar:\n" . mb_substr($transcript, 0, 350)
            : "\u{26A0}\u{FE0F} Audio gespeichert, aber Transkription fehlgeschlagen.";
        _sendTelegramMessage($botToken, $chatId, $reply);
    }
}

/**
 * Ordnungs- und Grundzahlwoerter (1..10) fuer die Positionserkennung.
 * Bewusst als Funktion, damit sowohl _detectCommand als auch _parsePosition
 * dieselbe Quelle nutzen.
 */
function _numberWords(): array {
    return [
        'ordinals' => [
            'erste' => 1, 'ersten' => 1, 'erster' => 1, 'erstes' => 1,
            'zweite' => 2, 'zweiten' => 2, 'zweiter' => 2, 'zweites' => 2,
            'dritte' => 3, 'dritten' => 3, 'dritter' => 3, 'drittes' => 3,
            'vierte' => 4, 'vierten' => 4, 'vierter' => 4, 'viertes' => 4,
            'fünfte' => 5, 'fünften' => 5, 'fünfter' => 5, 'fünftes' => 5,
            'sechste' => 6, 'sechsten' => 6, 'sechster' => 6, 'sechstes' => 6,
            'siebte' => 7, 'siebten' => 7, 'siebter' => 7, 'siebente' => 7, 'siebenten' => 7,
            'achte' => 8, 'achten' => 8, 'achter' => 8, 'achtes' => 8,
            'neunte' => 9, 'neunten' => 9, 'neunter' => 9,
            'zehnte' => 10, 'zehnten' => 10, 'zehnter' => 10,
        ],
        'cardinals' => [
            'eins' => 1, 'ein' => 1, 'eine' => 1, 'einen' => 1,
            'zwei' => 2, 'drei' => 3, 'vier' => 4, 'fünf' => 5,
            'sechs' => 6, 'sieben' => 7, 'acht' => 8, 'neun' => 9, 'zehn' => 10,
        ],
    ];
}

/**
 * Erkennt Sprachbefehle im transkribierten Text.
 *
 * Unterstuetzt beliebige Positionen, gezaehlt von oben (neueste zuerst) oder von
 * unten (aelteste zuerst):
 *   Loeschen:   "loesche den letzten/ersten/vorletzten Eintrag",
 *               "loesche den dritten Eintrag von oben", "vorletzten loeschen",
 *               "alle loeschen"
 *   Korrektur:  "Korrektur <Text>"                     -> ersetzt den letzten (obersten)
 *               "korrigiere den dritten von oben <Text>" -> ersetzt genau diesen Eintrag
 *               "Korrektur" ohne Text                  -> loescht den obersten Eintrag
 *
 * @return array{cmd:?string,from?:string,n?:int,text?:string} cmd = clear_all|delete|correction|null.
 *         Bei delete/correction gibt from (top|bottom) + n (1-basiert) die Zielposition an,
 *         text ist bei correction der neue Inhalt (leer = nur loeschen).
 */
function _detectCommand(string $transcript): array {
    $raw = trim($transcript);

    // Normalisierte Vergleichsform: klein, Satzzeichen an den Raendern entfernt.
    $norm = function_exists('mb_strtolower') ? mb_strtolower($raw) : strtolower($raw);
    $norm = trim($norm, " \t\n\r\0\x0B.,!?;:\"'„“”‚‘’");
    $norm = preg_replace('/\s+/u', ' ', $norm);

    // ── Hilfe: ganze Nachricht ist eine Hilfe-Anfrage (schuetzt normale Notizen) ──
    if (in_array($norm, [
        'hilfe', 'hilfe bitte', 'hilfe anzeigen', 'zeig hilfe', 'zeige hilfe', 'zeig mir hilfe',
        'help', 'befehle', 'kommandos', 'sprachbefehle', 'welche befehle', 'welche befehle gibt es',
        'was kann ich sagen', 'was kann ich sprechen', 'was geht',
    ], true)) {
        return ['cmd' => 'help'];
    }

    // ── Korrektur: muss mit dem Schluesselwort beginnen (sonst nur normale Notiz) ──
    if (preg_match('/^(?:korrektur|korrigiere?|korrigier|korrigieren|ändere?|ändern)\b[\s.,:!?…-]*(.*)$/isu', $raw, $m)) {
        $rest = trim($m[1]);
        $pos  = _parsePosition($rest);
        if ($pos !== null) {
            // Positionsangabe abgeschnitten -> Rest ist der neue Text.
            return ['cmd' => 'correction', 'from' => $pos['from'], 'n' => $pos['n'], 'text' => trim($pos['rest'])];
        }
        // Ohne Positionsangabe: oberster (letzter) Eintrag.
        return ['cmd' => 'correction', 'from' => 'top', 'n' => 1, 'text' => $rest];
    }

    // ── Loeschen: nur wenn der ganze Satz ausschliesslich aus Befehls-/Positions-
    //    Woertern besteht (schuetzt normale Notizen, die "loeschen" enthalten). ──
    if (preg_match('/\b(lösch\w*|entfern\w*)\b/u', $norm) && _onlyCommandTokens($norm)) {
        // "alle/alles/saemtliche loeschen" -> gesamte Tafel leeren.
        if (preg_match('/\b(alle|alles|sämtliche|saemtliche)\b/u', $norm)) {
            return ['cmd' => 'clear_all'];
        }
        // Verben/Fueller entfernen, Rest als Position deuten.
        $body = preg_replace('/\b(lösch\w*|entfern\w*|bitte|mal|doch|jetzt)\b/u', ' ', $norm);
        $body = trim(preg_replace('/\s+/u', ' ', $body));
        $pos  = _parsePosition($body);
        if ($pos !== null) {
            return ['cmd' => 'delete', 'from' => $pos['from'], 'n' => $pos['n']];
        }
        // Blosses "loeschen" ohne Position -> oberster (letzter) Eintrag.
        return ['cmd' => 'delete', 'from' => 'top', 'n' => 1];
    }

    return ['cmd' => null, 'text' => $raw];
}

/**
 * Hilfetext mit allen Sprachbefehlen, den der Bot auf "Hilfe" zurueckschickt.
 */
function _helpText(): string {
    return "\u{1F399}\u{FE0F} Sprachbefehle für die Anschlagtafel\n"
        . "\n"
        . "\u{1F5D1}\u{FE0F} Löschen:\n"
        . "• „Letzten Eintrag löschen\" – neueste (oben)\n"
        . "• „Ersten Eintrag löschen\" – älteste (unten)\n"
        . "• „Vorletzten löschen\" oder „Dritten von oben löschen\" – beliebige Position\n"
        . "• „Alle löschen\" – ganze Tafel leeren\n"
        . "\n"
        . "\u{270F}\u{FE0F} Korrigieren:\n"
        . "• „Korrektur: neuer Text\" – ersetzt den obersten Eintrag\n"
        . "• „Zweiten von oben korrigieren: Text\" – ersetzt genau diese Position\n"
        . "\n"
        . "\u{2139}\u{FE0F} „von oben\" zählt ab der neuesten, „von unten\" ab der ältesten Notiz.\n"
        . "Alles andere wird als normale Notiz auf der Tafel angezeigt.";
}

/**
 * Prueft, ob ein Satz ausschliesslich aus erlaubten Befehls-, Positions- und
 * Fuellwoertern besteht. Nur dann ist ein "loeschen" wirklich ein Befehl und
 * nicht Teil einer normalen Notiz ("Kunde will seinen Account loeschen").
 */
function _onlyCommandTokens(string $norm): bool {
    $tokens = preg_split('/\s+/u', trim($norm), -1, PREG_SPLIT_NO_EMPTY);
    if (!$tokens) {
        return false;
    }
    $words   = _numberWords();
    $fillers = array_fill_keys(array_merge([
        'bitte', 'mal', 'doch', 'jetzt', 'den', 'die', 'das', 'der', 'dem', 'und',
        'von', 'oben', 'unten', 'weg', 'raus', 'alle', 'alles', 'sämtliche', 'saemtliche',
        'eintrag', 'eintrags', 'eintrages', 'einträge', 'eintraege', 'eintraege',
        'notiz', 'notizen', 'position', 'positionen', 'mir',
    ], array_keys($words['ordinals']), array_keys($words['cardinals'])), true);

    foreach ($tokens as $tk) {
        if (isset($fillers[$tk])) {
            continue;
        }
        // Verben + Endpunkt-Woerter (letzter, vorletzter, oberster, ...) + Ziffern.
        if (preg_match('/^(lösch\w*|entfern\w*|vorvorletzt\w+|vorletzt\w+|letzt\w+|erst\w+|oberst\w+|unterst\w+|neuest\w+|ältest\w+|aeltest\w+|\d{1,2}\.?)$/u', $tk)) {
            continue;
        }
        return false;
    }
    return true;
}

/**
 * Liest eine Positionsangabe am Anfang eines Textes und liefert Zielseite + Rang.
 *
 * from = 'top' zaehlt von der neuesten (obersten) Notiz, 'bottom' von der aeltesten
 * (untersten). n ist 1-basiert. "rest" ist der Text nach der Positionsangabe (fuer
 * die Korrektur der neue Inhalt).
 *
 * Beispiele: "letzten" -> top/1, "vorletzten" -> top/2, "ersten" -> bottom/1,
 *            "dritten eintrag von oben Text" -> top/3 + rest "Text".
 *
 * @return array{from:string,n:int,rest:string}|null null, wenn keine Position erkannt wurde.
 */
function _parsePosition(string $phrase): ?array {
    $phrase = trim($phrase);
    if ($phrase === '') {
        return null;
    }
    $words = _numberWords();
    // Laengere Woerter zuerst, damit z. B. "siebenten" vor "sieben" greift.
    $ordKeys  = array_keys($words['ordinals']);
    $cardKeys = array_keys($words['cardinals']);
    usort($ordKeys,  fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    usort($cardKeys, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $ordAlt  = implode('|', array_map(fn($w) => preg_quote($w, '/'), $ordKeys));
    $cardAlt = implode('|', array_map(fn($w) => preg_quote($w, '/'), $cardKeys));

    $special = 'vorvorletzt\w+|vorletzt\w+|letzt\w+|erst\w+|oberst\w+|unterst\w+|neuest\w+|ältest\w+|aeltest\w+';
    $article = '(?:(?:der|die|das|den|dem)\\s+)?';
    $noun    = '(?:\\s+(?:eintrag\\w*|einträge|eintraege|notiz\\w*|position\\w*))';
    $vondir  = '(?:\\s+von\\s+(oben|unten))';
    $tail    = '[\\s.,:;!?…-]*';

    // 1) Eindeutige Kerne: Sonderwoerter (letzter, vorletzter, erster, …) oder
    //    Ordinalzahlen (dritter, …). Nomen und Richtung sind optional.
    $re1 = "/^{$article}((?:$special)|(?:$ordAlt)){$noun}?{$vondir}?{$tail}/iu";
    if (preg_match($re1, $phrase, $m)) {
        $rest = preg_replace($re1, '', $phrase, 1);
        return _posFromCore($m[1], $m[2] ?? '', $words, (string)$rest);
    }

    // 2) Ziffer/Grundzahl nur MIT Qualifizierer ("… Eintrag", "… von oben"), damit
    //    z. B. "Korrektur eine wichtige Sache" nicht als Position missdeutet wird.
    $re2 = "/^{$article}(\\d{1,2}\\.?|$cardAlt)(?:{$noun}{$vondir}?|{$vondir}){$tail}/iu";
    if (preg_match($re2, $phrase, $m)) {
        $dir  = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
        $rest = preg_replace($re2, '', $phrase, 1);
        return _posFromCore($m[1], $dir, $words, (string)$rest);
    }

    return null;
}

/**
 * Leitet aus dem erkannten Kernwort (+ optionaler Richtung) Zielseite und Rang ab.
 */
function _posFromCore(string $coreWord, string $dir, array $words, string $rest): array {
    $c   = function_exists('mb_strtolower') ? mb_strtolower($coreWord) : strtolower($coreWord);
    $dir = strtolower($dir);

    if (preg_match('/^vorvorletzt/u', $c))                 { $from = 'top';    $n = 3; }
    elseif (preg_match('/^vorletzt/u', $c))                { $from = 'top';    $n = 2; }
    elseif (preg_match('/^letzt/u', $c))                   { $from = 'top';    $n = 1; }
    elseif (preg_match('/^(oberst|neuest)/u', $c))         { $from = 'top';    $n = 1; }
    elseif (preg_match('/^(unterst|ältest|aeltest)/u', $c)) { $from = 'bottom'; $n = 1; }
    elseif (preg_match('/^erst/u', $c))                    { $from = 'bottom'; $n = 1; }
    elseif (preg_match('/^\d/u', $c))                      { $from = 'bottom'; $n = (int)$c; }
    elseif (isset($words['ordinals'][$c]))                 { $from = 'bottom'; $n = $words['ordinals'][$c]; }
    elseif (isset($words['cardinals'][$c]))                { $from = 'bottom'; $n = $words['cardinals'][$c]; }
    else                                                   { $from = 'top';    $n = 1; }

    // Explizite Richtung ("von oben"/"von unten") hat immer Vorrang.
    if ($dir === 'oben')      $from = 'top';
    elseif ($dir === 'unten') $from = 'bottom';
    if ($n < 1) $n = 1;

    return ['from' => $from, 'n' => $n, 'rest' => trim($rest)];
}

/**
 * Klartext-Bezeichnung einer Position fuer die Telegram-Bestaetigung.
 */
function _positionLabel(string $from, int $n): string {
    if ($from === 'top') {
        if ($n === 1) return 'Letzter Eintrag';
        if ($n === 2) return 'Vorletzter Eintrag';
        if ($n === 3) return 'Vorvorletzter Eintrag';
        return "{$n}. Eintrag von oben";
    }
    if ($n === 1) return 'Erster Eintrag';
    return "{$n}. Eintrag von unten";
}

/**
 * Ermittelt den sichtbaren Eintrag an Position n, gezaehlt von oben (neueste) oder
 * unten (aelteste). Liefert die DB-Zeile (id, transcript) oder null.
 */
function _resolveNoteAt(PDO $pdo, string $from, int $n): ?array {
    $order  = $from === 'top' ? 'itime DESC, id DESC' : 'itime ASC, id ASC';
    $offset = max(0, $n - 1);
    $stmt = $pdo->prepare(
        "SELECT id, transcript FROM voice_notes WHERE hidden = FALSE ORDER BY $order OFFSET :off LIMIT 1"
    );
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Blendet den Eintrag an der angegebenen Position aus (Soft-Delete) und meldet es live.
 * @return int|null Die ID des ausgeblendeten Eintrags oder null, wenn keiner da war.
 */
function _deleteAt(PDO $pdo, string $from, int $n): ?int {
    $row = _resolveNoteAt($pdo, $from, $n);
    if (!$row) {
        return null;
    }
    $id = (int)$row['id'];
    $pdo->prepare("UPDATE voice_notes SET hidden = TRUE, mtime = NOW() WHERE id = :id")
        ->execute([':id' => $id]);
    _notifyChange($pdo, ['action' => 'removed', 'id' => $id]);
    return $id;
}

/**
 * Ueberschreibt den Text des Eintrags an der angegebenen Position an Ort und Stelle
 * (Position/Reihenfolge bleiben erhalten) und meldet es live.
 * @return int|null Die ID des geaenderten Eintrags oder null, wenn keiner da war.
 */
function _updateAt(PDO $pdo, string $from, int $n, string $text): ?int {
    $row = _resolveNoteAt($pdo, $from, $n);
    if (!$row) {
        return null;
    }
    $id = (int)$row['id'];
    $pdo->prepare("UPDATE voice_notes SET transcript = :t, status = 'transcribed', mtime = NOW() WHERE id = :id")
        ->execute([':t' => $text, ':id' => $id]);
    _notifyChange($pdo, ['action' => 'updated', 'id' => $id, 'transcript' => $text, 'status' => 'transcribed']);
    return $id;
}

/**
 * Blendet alle sichtbaren Eintraege aus (Soft-Delete) und meldet es live.
 */
function _clearAll(PDO $pdo): void {
    $pdo->exec("UPDATE voice_notes SET hidden = TRUE, mtime = NOW() WHERE hidden = FALSE");
    _notifyChange($pdo, ['action' => 'cleared']);
}

/**
 * Sendet ein Live-Event an die Anschlagtafel (SSE-Kanal voicenote_change).
 */
function _notifyChange(PDO $pdo, array $payload): void {
    try {
        $pdo->prepare("SELECT pg_notify('voicenote_change', :p)")
            ->execute([':p' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    } catch (\Exception $e) {
        error_log('[TELEGRAM WEBHOOK] notify error: ' . $e->getMessage());
    }
}

/**
 * Legt eine ausgeblendete Kommando-Zeile ab (Dedup gegen doppelte Zustellung
 * + Protokoll). Loest wegen hidden=TRUE kein Anschlagtafel-Event aus.
 */
function _recordCommand(PDO $pdo, string $messageId, string $chatId, string $senderName, string $transcript, ?float $duration, ?string $language): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO voice_notes
                (telegram_message_id, telegram_chat_id, sender_name, duration,
                 transcript, language, status, hidden)
             VALUES (:msg, :chat, :sender, :duration, :transcript, :lang, 'command', TRUE)
             ON CONFLICT (telegram_message_id) DO NOTHING"
        );
        $stmt->execute([
            ':msg'        => $messageId,
            ':chat'       => $chatId,
            ':sender'     => $senderName,
            ':duration'   => $duration,
            ':transcript' => $transcript !== '' ? $transcript : null,
            ':lang'       => $language,
        ]);
    } catch (\Exception $e) {
        error_log('[TELEGRAM WEBHOOK] Command record error: ' . $e->getMessage());
    }
}

/**
 * Kurze Bestaetigung an den Absender senden (falls Bot-Token vorhanden).
 */
function _confirm(string $botToken, string $chatId, string $text): void {
    if ($botToken !== '') {
        _sendTelegramMessage($botToken, $chatId, $text);
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
