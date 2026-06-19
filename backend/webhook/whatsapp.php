<?php
// backend/webhook/whatsapp.php
// Meta WhatsApp Business API Webhook
// KEIN Session-Check — Meta sendet keine Cookies!

header('Content-Type: application/json');

// Konfiguration laden
require_once __DIR__.'/../api/config.php';

if (OSERP_SETUP_MODE) {
    http_response_code(503);
    echo json_encode(['error' => 'Setup not complete']);
    exit;
}

OserpConfig::init();

// --- Webhook Verification (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';

    if ($mode === 'subscribe' && !empty($token) && !empty($challenge)) {
        // Verify-Token gegen alle Company-DBs prüfen
        $companyDb = _findCompanyDbByVerifyToken($token);
        if ($companyDb) {
            http_response_code(200);
            echo $challenge;
            exit;
        }
    }

    http_response_code(403);
    echo json_encode(['error' => 'Verification failed']);
    exit;
}

// --- Webhook Messages (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    // Meta erwartet immer 200 OK
    http_response_code(200);

    if (!$input || !isset($input['entry'])) {
        echo json_encode(['status' => 'ignored']);
        exit;
    }

    // Alle Company-DBs mit WhatsApp-Konfiguration finden
    $companyDbs = _getAllWhatsAppCompanyDbs();

    foreach ($input['entry'] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            if (($change['field'] ?? '') !== 'messages') continue;

            $value = $change['value'] ?? [];
            $phoneNumberId = $value['metadata']['phone_number_id'] ?? '';

            // Richtige Company-DB anhand phone_number_id finden
            $pdo = null;
            $dbname = '';
            foreach ($companyDbs as $dbInfo) {
                if ($dbInfo['phone_number_id'] === $phoneNumberId) {
                    $pdo = _connectCompanyDb($dbInfo);
                    $dbname = $dbInfo['dbname'];
                    $countryCode = $dbInfo['country_code'] ?? '49';
                    break;
                }
            }

            if (!$pdo) continue;

            // Eingehende Nachrichten verarbeiten
            foreach ($value['messages'] ?? [] as $msg) {
                _processIncomingMessage($pdo, $dbname, $msg, $value['contacts'] ?? [], $countryCode);
            }

            // Status-Updates verarbeiten
            foreach ($value['statuses'] ?? [] as $status) {
                _processStatusUpdate($pdo, $status);
            }

            $pdo = null;
        }
    }

    echo json_encode(['status' => 'ok']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
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
 * Company-DB anhand verify_token finden
 */
function _findCompanyDbByVerifyToken(string $verifyToken): ?array {
    try {
        $auth = _getAuthPdo();
        $clients = $auth->query("SELECT dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($clients as $client) {
            try {
                $companyPdo = _connectCompanyDb($client);
                $stmt = $companyPdo->prepare("SELECT value FROM defaults_oserp WHERE key = 'whatsapp_verify_token'");
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['value'] === $verifyToken) {
                    return $client;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
    } catch (\Exception $e) {
        error_log('[WHATSAPP WEBHOOK] Auth DB error: ' . $e->getMessage());
    }

    return null;
}

/**
 * Alle Company-DBs mit WhatsApp-Konfiguration laden
 */
function _getAllWhatsAppCompanyDbs(): array {
    $result = [];
    try {
        $auth = _getAuthPdo();
        $clients = $auth->query("SELECT dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($clients as $client) {
            try {
                $companyPdo = _connectCompanyDb($client);
                $stmt = $companyPdo->query("SELECT key, value FROM defaults_oserp WHERE key IN ('whatsapp_phone_number_id', 'whatsapp_country_code')");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $config = [];
                foreach ($rows as $row) {
                    $config[$row['key']] = $row['value'];
                }
                if (!empty($config['whatsapp_phone_number_id'])) {
                    $result[] = array_merge($client, [
                        'phone_number_id' => $config['whatsapp_phone_number_id'],
                        'country_code' => $config['whatsapp_country_code'] ?? '49'
                    ]);
                }
            } catch (\Exception $e) {
                continue;
            }
        }
    } catch (\Exception $e) {
        error_log('[WHATSAPP WEBHOOK] Error loading company DBs: ' . $e->getMessage());
    }

    return $result;
}

/**
 * Eingehende WhatsApp-Nachricht verarbeiten
 */
function _processIncomingMessage(PDO $pdo, string $dbname, array $msg, array $contacts, string $countryCode): void {
    $waMessageId = $msg['id'] ?? '';
    $from = $msg['from'] ?? '';
    $type = $msg['type'] ?? 'text';
    $timestamp = isset($msg['timestamp']) ? date('c', (int)$msg['timestamp']) : date('c');

    // Kontaktname ermitteln
    $contactName = '';
    foreach ($contacts as $contact) {
        if (($contact['wa_id'] ?? '') === $from) {
            $contactName = $contact['profile']['name'] ?? '';
            break;
        }
    }

    // Telefonnummer normalisieren
    $phone = '+' . ltrim($from, '+');

    // Nachrichtentext extrahieren
    $text = '';
    $mediaUrl = '';
    $mediaMime = '';
    $mediaCaption = '';

    switch ($type) {
        case 'text':
            $text = $msg['text']['body'] ?? '';
            break;
        case 'image':
            $mediaUrl = $msg['image']['id'] ?? '';
            $mediaMime = $msg['image']['mime_type'] ?? '';
            $mediaCaption = $msg['image']['caption'] ?? '';
            $text = $mediaCaption ?: '[Bild]';
            break;
        case 'document':
            $mediaUrl = $msg['document']['id'] ?? '';
            $mediaMime = $msg['document']['mime_type'] ?? '';
            $mediaCaption = $msg['document']['caption'] ?? $msg['document']['filename'] ?? '';
            $text = $mediaCaption ?: '[Dokument]';
            break;
        case 'audio':
            $mediaUrl = $msg['audio']['id'] ?? '';
            $mediaMime = $msg['audio']['mime_type'] ?? '';
            $text = '[Sprachnachricht]';
            break;
        case 'video':
            $mediaUrl = $msg['video']['id'] ?? '';
            $mediaMime = $msg['video']['mime_type'] ?? '';
            $mediaCaption = $msg['video']['caption'] ?? '';
            $text = $mediaCaption ?: '[Video]';
            break;
        case 'location':
            $lat = $msg['location']['latitude'] ?? '';
            $lon = $msg['location']['longitude'] ?? '';
            $text = "[Standort: {$lat}, {$lon}]";
            break;
        case 'contacts':
            $contactsList = $msg['contacts'] ?? [];
            $parts = [];
            foreach ($contactsList as $c) {
                $cName = trim(($c['name']['formatted_name'] ?? '') ?: (($c['name']['first_name'] ?? '') . ' ' . ($c['name']['last_name'] ?? '')));
                $cPhones = [];
                foreach ($c['phones'] ?? [] as $p) {
                    $cPhones[] = $p['phone'] ?? $p['wa_id'] ?? '';
                }
                $cEmails = [];
                foreach ($c['emails'] ?? [] as $e) {
                    $cEmails[] = $e['email'] ?? '';
                }
                $cOrg = $c['org']['company'] ?? '';
                $entry = ['name' => $cName];
                if ($cPhones) $entry['phones'] = array_filter($cPhones);
                if ($cEmails) $entry['emails'] = array_filter($cEmails);
                if ($cOrg) $entry['org'] = $cOrg;
                $parts[] = $entry;
            }
            $text = json_encode($parts, JSON_UNESCAPED_UNICODE);
            break;
        case 'sticker':
            $text = '[Sticker]';
            break;
        default:
            $text = "[{$type}]";
    }

    // Kunden- oder Lieferantenzuordnung über Telefonnummer
    $customerId = null;
    $cvSrc = null; // 'C' oder 'V'
    try {
        $digits = substr(preg_replace('/[^0-9]/', '', $phone), -8);
        // Kunde suchen: phone, phone3 und customer_ext.phone_numbers (JSONB)
        $stmt = $pdo->prepare(
            "SELECT c.id FROM customer c
             LEFT JOIN customer_ext ce ON ce.customer_id = c.id
             WHERE REGEXP_REPLACE(c.phone, '[^0-9]', '', 'g') LIKE '%' || :d1
                OR REGEXP_REPLACE(c.phone3, '[^0-9]', '', 'g') LIKE '%' || :d2
                OR EXISTS (
                    SELECT 1 FROM jsonb_array_elements(COALESCE(ce.phone_numbers, '[]'::jsonb)) pn
                    WHERE REGEXP_REPLACE(pn->>'number', '[^0-9]', '', 'g') LIKE '%' || :d3
                )
             LIMIT 1"
        );
        $stmt->execute([':d1' => $digits, ':d2' => $digits, ':d3' => $digits]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $customerId = (int)$row['id'];
            $cvSrc = 'C';
        } else {
            // Lieferant suchen: phone, phone3 und vendor_ext.phone_numbers (JSONB)
            $stmt = $pdo->prepare(
                "SELECT v.id FROM vendor v
                 LEFT JOIN vendor_ext ve ON ve.vendor_id = v.id
                 WHERE REGEXP_REPLACE(v.phone, '[^0-9]', '', 'g') LIKE '%' || :d1
                    OR REGEXP_REPLACE(v.phone3, '[^0-9]', '', 'g') LIKE '%' || :d2
                    OR EXISTS (
                        SELECT 1 FROM jsonb_array_elements(COALESCE(ve.phone_numbers, '[]'::jsonb)) pn
                        WHERE REGEXP_REPLACE(pn->>'number', '[^0-9]', '', 'g') LIKE '%' || :d3
                    )
                 LIMIT 1"
            );
            $stmt->execute([':d1' => $digits, ':d2' => $digits, ':d3' => $digits]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $customerId = (int)$row['id'];
                $cvSrc = 'V';
            }
        }
    } catch (\Exception $e) {
        // Zuordnung ist optional
    }

    // Mediendatei von Meta herunterladen und lokal speichern
    // Auch ohne customer_id speichern, damit keine Medien verloren gehen
    $localMediaPath = null;
    if (!empty($mediaUrl)) {
        $localMediaPath = _downloadAndSaveMedia($pdo, $dbname, $mediaUrl, $mediaMime, $type, $customerId, $cvSrc, $timestamp);
    }

    // Nachricht in DB speichern (ON CONFLICT für Idempotenz)
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO whatsapp_messages
                (wa_message_id, direction, phone_number, customer_id, contact_name,
                 message_type, message_text, media_url, media_mime_type, media_caption, status, itime)
             VALUES
                (:wa_id, 'I', :phone, :customer_id, :contact_name,
                 :type, :text, :media_url, :media_mime, :media_caption, 'received', :itime)
             ON CONFLICT (wa_message_id) DO NOTHING"
        );
        $stmt->execute([
            ':wa_id' => $waMessageId,
            ':phone' => $phone,
            ':customer_id' => $customerId,
            ':contact_name' => $contactName,
            ':type' => $type,
            ':text' => $text,
            ':media_url' => $localMediaPath ?: ($mediaUrl ?: null),
            ':media_mime' => $mediaMime ?: null,
            ':media_caption' => $mediaCaption ?: null,
            ':itime' => $timestamp
        ]);
    } catch (\Exception $e) {
        error_log('[WHATSAPP WEBHOOK] Insert error: ' . $e->getMessage());
    }
}

/**
 * Status-Update verarbeiten (sent, delivered, read)
 */
function _processStatusUpdate(PDO $pdo, array $status): void {
    $waMessageId = $status['id'] ?? '';
    $newStatus = $status['status'] ?? '';
    $timestamp = isset($status['timestamp']) ? date('c', (int)$status['timestamp']) : date('c');

    if (empty($waMessageId) || empty($newStatus)) return;

    // Fehler-Info bei failed
    $errorCode = null;
    $errorMessage = null;
    if ($newStatus === 'failed' && isset($status['errors'][0])) {
        $errorCode = (string)($status['errors'][0]['code'] ?? '');
        $errorMessage = $status['errors'][0]['title'] ?? '';
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE whatsapp_messages
             SET status = :status, status_timestamp = :ts, error_code = :err_code, error_message = :err_msg, mtime = NOW()
             WHERE wa_message_id = :wa_id"
        );
        $stmt->execute([
            ':status' => $newStatus,
            ':ts' => $timestamp,
            ':err_code' => $errorCode,
            ':err_msg' => $errorMessage,
            ':wa_id' => $waMessageId
        ]);
    } catch (\Exception $e) {
        error_log('[WHATSAPP WEBHOOK] Status update error: ' . $e->getMessage());
    }
}

/**
 * Mediendatei von Meta herunterladen und im Kunden-/Lieferantenordner speichern
 *
 * @return string|null Lokaler Dateipfad relativ zu data/ oder null bei Fehler
 */
function _downloadAndSaveMedia(PDO $pdo, string $dbname, string $metaMediaId, string $mimeType, string $msgType, ?int $cvId, ?string $cvSrc, string $timestamp): ?string {
    // Access-Token aus DB lesen
    try {
        $stmt = $pdo->prepare("SELECT value FROM defaults_oserp WHERE key = 'whatsapp_access_token'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $accessToken = $row['value'] ?? '';
    } catch (\Exception $e) {
        return null;
    }
    if (empty($accessToken)) return null;

    // Schritt 1: Download-URL von Meta holen
    $ch = curl_init("https://graph.facebook.com/v21.0/{$metaMediaId}");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("[WHATSAPP WEBHOOK] Media-URL Abruf fehlgeschlagen: HTTP {$httpCode}");
        return null;
    }

    $mediaInfo = json_decode($response, true);
    $downloadUrl = $mediaInfo['url'] ?? '';
    if (empty($downloadUrl)) return null;

    // Schritt 2: Datei herunterladen
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $fileData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($fileData)) {
        error_log("[WHATSAPP WEBHOOK] Media-Download fehlgeschlagen: HTTP {$httpCode}");
        return null;
    }

    // Dateiendung bestimmen
    $extMap = [
        'audio/ogg' => 'ogg', 'audio/ogg; codecs=opus' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'mp4',
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'video/mp4' => 'mp4', 'video/3gpp' => '3gp',
        'application/pdf' => 'pdf',
    ];
    // MIME kann Semikolon-Parameter enthalten (z.B. "audio/ogg; codecs=opus")
    $mimeBase = strtolower(trim(explode(';', $mimeType)[0]));
    $ext = $extMap[$mimeBase] ?? ($extMap[strtolower($mimeType)] ?? 'bin');

    // Zielverzeichnis: data/<dbname>/customers|vendors/{id}/whatsapp/ oder data/<dbname>/whatsapp_unmatched/
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbname)) {
        error_log("[WHATSAPP WEBHOOK] Ungueltiger dbname: {$dbname}");
        return null;
    }
    // Hinweis: Diese Datei liegt in backend/webhook (eine Ebene unter backend),
    // daher '/../data'. Das Idiom '/../../data' stammt aus filemanager.php, die
    // zwei Ebenen tiefer (api/customer_vendor) liegt — dort ergibt es backend/data.
    $baseDir = realpath(__DIR__ . '/../data') ?: (__DIR__ . '/../data');
    $dataDir = $baseDir . '/' . $dbname;
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    if ($cvId) {
        $basePath = ($cvSrc === 'V') ? 'vendors' : 'customers';
        $whatsappDir = $dataDir . '/' . $basePath . '/' . $cvId . '/whatsapp';
    } else {
        $basePath = 'whatsapp_unmatched';
        $whatsappDir = $dataDir . '/whatsapp_unmatched';
    }

    if (!is_dir($whatsappDir)) {
        mkdir($whatsappDir, 0755, true);
    }

    // Dateiname: YYYY-MM-DD_HH-MM-SS_typ.ext
    $dateStr = date('Y-m-d_H-i-s', strtotime($timestamp));
    $filename = "{$dateStr}_{$msgType}.{$ext}";

    // Bei Namenskollision Suffix anhaengen
    $fullPath = $whatsappDir . '/' . $filename;
    if (file_exists($fullPath)) {
        $filename = "{$dateStr}_{$msgType}_" . substr(uniqid(), -4) . ".{$ext}";
        $fullPath = $whatsappDir . '/' . $filename;
    }

    if (file_put_contents($fullPath, $fileData) === false) {
        error_log("[WHATSAPP WEBHOOK] Datei konnte nicht gespeichert werden: {$fullPath}");
        return null;
    }

    // Relativer Pfad zurueckgeben (ab data/)
    if ($cvId) {
        return $basePath . '/' . $cvId . '/whatsapp/' . $filename;
    }
    return 'whatsapp_unmatched/' . $filename;
}
