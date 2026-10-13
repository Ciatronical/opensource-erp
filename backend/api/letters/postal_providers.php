<?php
// backend/api/letters/postal_providers.php
//
// Anbindung der Briefdienstleister für den Hybridbrief: Das ERP liefert ein
// fertiges PDF ein, der Dienstleister druckt, kuvertiert, frankiert und
// stellt zu.
//
// * PIN AG (eCollect / eBrief): Einlieferung per SFTP oder FTPS in einen vom
//   Dienstleister vorkonfigurierten Ordner. Druckoptionen (Farbe, Duplex)
//   sind beim Anbieter je Ordner hinterlegt; für Einwurf-Einschreiben kann
//   ein zweiter Ordner eingetragen werden.
// * LetterXpress: REST-API v3 (https://api.letterxpress.de/v3), Auftrag mit
//   base64-PDF, Preisabfrage, Status und Sendungsverfolgung, Storno
//   innerhalb von 15 Minuten.
//
// Beide Wege nutzen ausschließlich curl — keine zusätzliche PHP-Erweiterung.

define('POSTAL_LXP_BASE', 'https://api.letterxpress.de/v3');

/**
 * Konfiguration des Postversands aus defaults_oserp.
 *
 * Schlüssel (CRM-Tab „Briefversand"): eletter_provider (pinag | letterxpress | ''),
 * eletter_protocol (sftp | ftps), eletter_hostname, eletter_port, eletter_username,
 * eletter_passwd, eletter_folder, eletter_folder_registered, eletter_lxp_username,
 * eletter_lxp_apikey, eletter_lxp_mode (test | live), eletter_color, eletter_duplex.
 */
function postalConfig($db): array {
    $rows = $db->getAll("SELECT key, value FROM defaults_oserp WHERE key LIKE 'eletter_%'");
    $cfg = [];
    foreach ($rows as $r) {
        $cfg[$r['key']] = $r['value'];
    }
    $bool = fn($v) => in_array(strtolower(trim((string)$v)), ['1', 't', 'true', 'on'], true);
    $protocol = strtolower(trim($cfg['eletter_protocol'] ?? 'sftp')) === 'ftps' ? 'ftps' : 'sftp';
    // Port: leer = Standard des Protokolls. Der Standardport des jeweils anderen
    // Protokolls (21 bei SFTP, 22 bei FTPS) ist immer ein Eingabefehler und
    // würde nur eine abgelehnte Verbindung erzeugen — er wird wie leer behandelt.
    $port = intval($cfg['eletter_port'] ?? 0);
    if ($port === ($protocol === 'ftps' ? 22 : 21)) $port = 0;
    return [
        'provider'          => trim($cfg['eletter_provider'] ?? ''),
        'protocol'          => $protocol,
        'hostname'          => trim($cfg['eletter_hostname'] ?? ''),
        'port'              => $port,
        'username'          => trim($cfg['eletter_username'] ?? ''),
        'password'          => (string)($cfg['eletter_passwd'] ?? ''),
        'folder'            => trim($cfg['eletter_folder'] ?? ''),
        'folder_registered' => trim($cfg['eletter_folder_registered'] ?? ''),
        'lxp_username'      => trim($cfg['eletter_lxp_username'] ?? ''),
        'lxp_apikey'        => trim($cfg['eletter_lxp_apikey'] ?? ''),
        'lxp_mode'          => strtolower(trim($cfg['eletter_lxp_mode'] ?? 'test')) === 'live' ? 'live' : 'test',
        'color'             => $bool($cfg['eletter_color'] ?? ''),
        'duplex'            => $bool($cfg['eletter_duplex'] ?? ''),
    ];
}

/**
 * Ist der konfigurierte Anbieter vollständig eingerichtet?
 *
 * @return string|null Fehlende Angabe als Hinweis, null wenn alles da ist
 */
function postalConfigProblem(array $cfg): ?string {
    switch ($cfg['provider']) {
        case 'pinag':
            if ($cfg['hostname'] === '' || $cfg['username'] === '') return 'Server und Benutzername fehlen';
            if ($cfg['folder'] === '') return 'Zielordner fehlt';
            return null;
        case 'letterxpress':
            if ($cfg['lxp_username'] === '' || $cfg['lxp_apikey'] === '') return 'Benutzername und API-Schlüssel fehlen';
            return null;
        default:
            return 'Kein Anbieter gewählt';
    }
}

// ---------------------------------------------------------------------------
// LetterXpress
// ---------------------------------------------------------------------------

/**
 * Kleiner Client für die LetterXpress-API v3.
 *
 * Jede Anfrage trägt das auth-Objekt (Benutzername, API-Schlüssel, Modus) im
 * JSON-Körper — auch bei GET und DELETE, so sieht es die Dokumentation vor.
 * Im Modus „test" landen Aufträge in der Postbox des Kontos und werden nicht
 * gedruckt; so lässt sich die Anbindung gefahrlos ausprobieren.
 */
class LetterXpressClient {
    private string $username;
    private string $apikey;
    private string $mode;

    public function __construct(string $username, string $apikey, string $mode = 'test') {
        $this->username = $username;
        $this->apikey   = $apikey;
        $this->mode     = $mode === 'live' ? 'live' : 'test';
    }

    public function mode(): string {
        return $this->mode;
    }

    private function request(string $method, string $path, array $body = []): array {
        $body['auth'] = ['username' => $this->username, 'apikey' => $this->apikey, 'mode' => $this->mode];
        $ch = curl_init(POSTAL_LXP_BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new ApiError('POSTAL_PROVIDER_UNREACHABLE', 'LetterXpress nicht erreichbar: ' . $err);
        }
        $json = json_decode($raw, true);
        if ($code === 401) {
            throw new ApiError('POSTAL_AUTH_FAILED', 'LetterXpress: Benutzername oder API-Schlüssel falsch');
        }
        if ($code < 200 || $code >= 300 || !is_array($json)) {
            $msg = is_array($json) ? ($json['message'] ?? '') : '';
            if (is_array($json) && !empty($json['errors'])) {
                $msg .= ' ' . json_encode($json['errors'], JSON_UNESCAPED_UNICODE);
            }
            throw new ApiError('POSTAL_PROVIDER_ERROR', 'LetterXpress (HTTP ' . $code . '): ' . trim($msg ?: substr($raw, 0, 300)));
        }
        return $json;
    }

    /** Guthaben des Kontos */
    public function balance(): array {
        $r = $this->request('GET', '/balance');
        return ['balance' => floatval($r['data']['balance'] ?? 0), 'currency' => $r['data']['currency'] ?? 'EUR'];
    }

    /** Preis für einen Brief mit dieser Seitenzahl und diesen Optionen */
    public function price(int $pages, bool $color, bool $duplex, string $shipping, ?string $registered): ?float {
        $letter = ['specification' => [
            'pages'    => $pages,
            'color'    => $color ? '4' : '1',
            'mode'     => $duplex ? 'duplex' : 'simplex',
            'shipping' => $shipping === 'international' ? 'international' : 'national',
        ]];
        if ($registered && $shipping !== 'international') $letter['registered'] = $registered;
        $r = $this->request('GET', '/price', ['letter' => $letter]);
        return isset($r['data']['price']) ? floatval($r['data']['price']) : null;
    }

    /** Auftrag einliefern — liefert die Antwortdaten (id, status, items …) */
    public function submit(string $pdf, bool $color, bool $duplex, string $shipping, ?string $registered, string $filename, string $notice = ''): array {
        $b64 = base64_encode($pdf);
        $letter = [
            'base64_file'          => $b64,
            'base64_file_checksum' => md5($b64),
            'specification'        => [
                'color'    => $color ? '4' : '1',
                'mode'     => $duplex ? 'duplex' : 'simplex',
                'shipping' => $shipping === 'international' ? 'international' : 'national',
            ],
            'filename_original'    => $filename,
        ];
        if ($registered && $shipping !== 'international') $letter['registered'] = $registered;
        if ($notice !== '') $letter['notice'] = mb_substr($notice, 0, 255);
        $r = $this->request('POST', '/printjobs', ['letter' => $letter]);
        return $r['data'] ?? [];
    }

    /** Auftrag abrufen (Status, Sendungsverfolgung) */
    public function job(string $id): array {
        $r = $this->request('GET', '/printjobs/' . rawurlencode($id));
        return $r['data'] ?? [];
    }

    /** Auftrag löschen — nur innerhalb von 15 Minuten nach Einlieferung möglich */
    public function delete(string $id): void {
        $this->request('DELETE', '/printjobs/' . rawurlencode($id));
    }
}

/**
 * Antwort eines LetterXpress-Auftrags auf unsere Spalten abbilden.
 *
 * Auftragsstatus: queue (wartet), hold (angehalten), done (gedruckt und
 * versendet), canceled. Zustellinfos stehen je Brief in items[].
 */
function postalLxpJobToRow(array $job): array {
    $jobStatus = strtolower((string)($job['status'] ?? ''));
    $status = match ($jobStatus) {
        'done'     => 'done',
        'canceled' => 'canceled',
        default    => 'sent',
    };
    $item  = $job['items'][0] ?? [];
    $price = null;
    if (!empty($job['items'])) {
        $price = 0.0;
        foreach ($job['items'] as $it) $price += floatval($it['amount'] ?? 0);
    }
    return [
        'status'          => $status,
        'provider_status' => $jobStatus ?: null,
        'tracking_code'   => $item['tracking_code'] ?? null,
        'tracking_status' => $item['tracking_status'] ?? null,
        'pages'           => isset($item['pages']) ? intval($item['pages']) : null,
        'price'           => $price,
    ];
}

// ---------------------------------------------------------------------------
// PIN AG / eBrief: SFTP oder FTPS
//
// SFTP läuft über die PHP-Erweiterung ssh2 (so wie der HU-Serienbrief, der
// damit seit Jahren einliefert). PHPs curl ist hier gegen libssh gebaut und
// lehnt den Server mangels known_hosts-Eintrag ab (CURLE_PEER_FAILED_
// VERIFICATION) — curl bleibt Rückfall ohne ssh2 und der Weg für FTPS.
// ---------------------------------------------------------------------------

/**
 * Effektiver Port: konfiguriert oder Standard des Protokolls.
 */
function postalSftpPort(array $cfg): int {
    return $cfg['port'] ?: ($cfg['protocol'] === 'ftps' ? 21 : 22);
}

/**
 * SFTP-Verbindung über ssh2 aufbauen und anmelden.
 *
 * @return array [connection, sftp-Ressource]
 */
function postalSsh2Connect(array $cfg): array {
    $port = postalSftpPort($cfg);
    $conn = @ssh2_connect($cfg['hostname'], $port);
    if (!$conn) {
        throw new ApiError('POSTAL_PROVIDER_UNREACHABLE', 'Verbindung zu ' . $cfg['hostname'] . ':' . $port . ' fehlgeschlagen');
    }
    if (!@ssh2_auth_password($conn, $cfg['username'], $cfg['password'])) {
        throw new ApiError('POSTAL_AUTH_FAILED', 'SFTP-Anmeldung abgelehnt (Benutzername oder Passwort)');
    }
    $sftp = @ssh2_sftp($conn);
    if (!$sftp) {
        throw new ApiError('POSTAL_PROVIDER_ERROR', 'SFTP-Subsystem konnte nicht gestartet werden');
    }
    return [$conn, $sftp];
}

/**
 * Pfad eines Ordners auf dem Server (absolut, ohne Dateinamen).
 */
function postalSftpFolderPath(string $folder): string {
    $path = '/' . trim($folder, '/');
    return $path === '/' ? '' : $path;
}

/**
 * Ziel-URL für curl aus der Konfiguration bauen.
 */
function postalSftpUrl(array $cfg, string $folder, string $filename = ''): string {
    $url = $cfg['protocol'] . '://' . $cfg['hostname'] . ':' . postalSftpPort($cfg) . postalSftpFolderPath($folder) . '/';
    return $filename === '' ? $url : $url . rawurlencode($filename);
}

/**
 * Gemeinsame curl-Optionen für SFTP/FTPS.
 */
function postalSftpCurl(array $cfg, string $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERPWD        => $cfg['username'] . ':' . $cfg['password'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);
    if ($cfg['protocol'] === 'ftps') {
        // Explizites FTPS (Port 21, AUTH TLS) — die übliche Variante bei Briefdiensten
        curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
        curl_setopt($ch, CURLOPT_FTP_SSL_CCC, CURLFTPSSL_CCC_NONE);
    } else {
        // Hostschlüssel: Briefdienste wechseln ihn gelegentlich; die
        // Anmeldung ist durch das Passwort gesichert, nicht durch known_hosts.
        curl_setopt($ch, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD);
    }
    return $ch;
}

/**
 * PDF in den Einlieferungsordner hochladen.
 *
 * Die Datei wird zuerst unter einem temporären Namen (.part) übertragen und
 * erst nach vollständigem Upload umbenannt: Der Dienstleister holt Dateien
 * automatisch ab — eine halb geschriebene PDF darf er nie erwischen.
 */
function postalSftpUpload(array $cfg, string $folder, string $filename, string $pdf): void {
    if ($cfg['protocol'] === 'sftp' && function_exists('ssh2_connect')) {
        [$conn, $sftp] = postalSsh2Connect($cfg);
        $dir  = postalSftpFolderPath($folder);
        $part = $dir . '/' . $filename . '.part';
        $dest = $dir . '/' . $filename;
        $stream = @fopen('ssh2.sftp://' . intval($sftp) . $part, 'w');
        if (!$stream) {
            throw new ApiError('POSTAL_UPLOAD_FAILED', 'Einlieferungsordner nicht beschreibbar: ' . $dir);
        }
        $written = fwrite($stream, $pdf);
        fclose($stream);
        if ($written !== strlen($pdf)) {
            @ssh2_sftp_unlink($sftp, $part);
            throw new ApiError('POSTAL_UPLOAD_FAILED', 'Datei wurde nur unvollständig übertragen');
        }
        if (!@ssh2_sftp_rename($sftp, $part, $dest)) {
            @ssh2_sftp_unlink($sftp, $part);
            throw new ApiError('POSTAL_UPLOAD_FAILED', 'Datei konnte nach dem Upload nicht umbenannt werden');
        }
        return;
    }

    $tmp = tmpfile();
    fwrite($tmp, $pdf);
    fseek($tmp, 0);

    $partName = $filename . '.part';
    $ch = postalSftpCurl($cfg, postalSftpUrl($cfg, $folder, $partName));
    curl_setopt_array($ch, [
        CURLOPT_UPLOAD     => true,
        CURLOPT_INFILE     => $tmp,
        CURLOPT_INFILESIZE => strlen($pdf),
    ]);
    if ($cfg['protocol'] === 'sftp') {
        curl_setopt($ch, CURLOPT_POSTQUOTE, ['rename ' . postalSftpQuotePath($cfg, $folder, $partName) . ' ' . postalSftpQuotePath($cfg, $folder, $filename)]);
    } else {
        curl_setopt($ch, CURLOPT_POSTQUOTE, ['RNFR ' . $partName, 'RNTO ' . $filename]);
    }
    $ok   = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_errno($ch);
    curl_close($ch);
    fclose($tmp);

    if ($ok === false || $code !== 0) {
        throw new ApiError('POSTAL_UPLOAD_FAILED', 'Einlieferung fehlgeschlagen: ' . ($err ?: 'curl-Fehler ' . $code));
    }
}

/**
 * Absoluter Pfad für SFTP-Kommandos (rename) — ohne URL-Kodierung, mit Anführungszeichen.
 */
function postalSftpQuotePath(array $cfg, string $folder, string $filename): string {
    $path = '/' . trim($folder, '/');
    if ($path === '/') $path = '';
    return '"' . $path . '/' . str_replace('"', '', $filename) . '"';
}

/**
 * Anmeldung und Ordner prüfen (Verbindungstest in der Konfiguration).
 *
 * @return array ['ok' => bool, 'message' => string, 'files' => int]
 */
function postalSftpTest(array $cfg): array {
    $where = postalSftpUrl($cfg, $cfg['folder']);
    if ($cfg['protocol'] === 'sftp' && function_exists('ssh2_connect')) {
        try {
            [$conn, $sftp] = postalSsh2Connect($cfg);
        } catch (ApiError $e) {
            return ['ok' => false, 'message' => $e->getMessage() . ' — ' . $where, 'files' => 0];
        }
        $dir = @opendir('ssh2.sftp://' . intval($sftp) . (postalSftpFolderPath($cfg['folder']) ?: '/'));
        if (!$dir) {
            return ['ok' => false, 'message' => 'Anmeldung erfolgreich, aber der Einlieferungsordner ist nicht erreichbar: ' . $where, 'files' => 0];
        }
        $n = 0;
        while (($f = readdir($dir)) !== false) {
            if ($f !== '.' && $f !== '..') $n++;
        }
        closedir($dir);
        return ['ok' => true, 'message' => 'Anmeldung erfolgreich, Ordner erreichbar: ' . $where, 'files' => $n];
    }

    $ch = postalSftpCurl($cfg, $where);
    curl_setopt($ch, CURLOPT_DIRLISTONLY, true);
    $list = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($list === false) {
        return ['ok' => false, 'message' => $err ?: 'Verbindung fehlgeschlagen', 'files' => 0];
    }
    $files = array_values(array_filter(array_map('trim', explode("\n", $list)), fn($f) => $f !== '' && $f !== '.' && $f !== '..'));
    return ['ok' => true, 'message' => 'Anmeldung erfolgreich, Ordner erreichbar: ' . $where, 'files' => count($files)];
}
