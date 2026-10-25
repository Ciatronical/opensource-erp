<?php
// backend/api/email/imap.class.php

/**
 * Einfacher IMAP-Client über stream_socket_client
 * Keine PHP-IMAP-Extension erforderlich
 */
class ImapClient {
    private $socket = null;
    private int $tagCounter = 0;
    private string $host;
    private int $port;
    private string $encryption;
    private string $username;
    private string $password;

    public function __construct(string $host, int $port, string $encryption, string $username, string $password) {
        $this->host = $host;
        $this->port = $port;
        $this->encryption = $encryption;
        $this->username = $username;
        $this->password = $password;
    }

    public function connect(): void {
        $address = $this->host . ':' . $this->port;
        if ($this->encryption === 'ssl') {
            $address = 'ssl://' . $address;
        }

        $context = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);

        $this->socket = @stream_socket_client($address, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket) {
            throw new \RuntimeException("IMAP-Verbindung fehlgeschlagen: $errstr ($errno)");
        }

        stream_set_timeout($this->socket, 15);
        $this->readLine(); // Server greeting

        if ($this->encryption === 'tls' || $this->encryption === 'starttls') {
            $this->command('STARTTLS');
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new \RuntimeException('STARTTLS fehlgeschlagen');
            }
        }

        $resp = $this->command('LOGIN "' . $this->escapeString($this->username) . '" "' . $this->escapeString($this->password) . '"');
        if (!$this->isOk($resp)) {
            // Server-Antwort für Diagnose loggen (ohne Passwort)
            $lines = array_values(array_filter(explode("\n", $resp), function($l) { return trim($l) !== ''; }));
            $lastLine = trim(end($lines) ?: '');
            $serverMsg = trim(preg_replace('/^A\d+\s*(NO|BAD)?\s*/i', '', $lastLine));
            error_log('[EMAIL] IMAP login failed for user "' . $this->username . '" on ' . $this->host . ':' . $this->port . ' — Response: ' . trim($resp));
            throw new \RuntimeException('IMAP-Login fehlgeschlagen' . ($serverMsg ? ': ' . $serverMsg : ''));
        }
    }

    public function disconnect(): void {
        if ($this->socket) {
            try { $this->idleDone(); } catch (\Exception $e) {}
            try { $this->command('LOGOUT'); } catch (\Exception $e) {}
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct() {
        $this->disconnect();
    }

    /**
     * Ordnerliste abrufen
     */
    public function listFolders(): array {
        $resp = $this->command('LIST "" "*"');
        $folders = [];
        foreach (explode("\n", $resp) as $line) {
            if (preg_match('/\* LIST \(([^)]*)\) "([^"]*)" "?([^"\r\n]+)"?/', $line, $m)) {
                $flags = $m[1];
                $name = $this->decodeUtf7($m[3]);
                $folders[] = [
                    'name' => $name,
                    'flags' => $flags,
                    'hasChildren' => str_contains($flags, '\\HasChildren'),
                    'noSelect' => str_contains($flags, '\\Noselect'),
                ];
            }
        }
        return $folders;
    }

    /**
     * Ordner auswählen, gibt Anzahl Nachrichten zurück
     */
    public function selectFolder(string $folder): int {
        $resp = $this->command('SELECT "' . $this->escapeString($folder) . '"');
        if (preg_match('/\* (\d+) EXISTS/', $resp, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    /**
     * Emails suchen
     * @param string $criteria IMAP-Suchkriterien (z.B. "ALL", "FROM foo@bar.com")
     * @return array UIDs
     */
    public function search(string $criteria = 'ALL'): array {
        $resp = $this->command('UID SEARCH ' . $criteria);
        if (preg_match('/\* SEARCH (.+)/', $resp, $m)) {
            return array_map('intval', preg_split('/\s+/', trim($m[1])));
        }
        return [];
    }

    /**
     * Email-Header + Flags für mehrere UIDs abrufen (ENVELOPE + FLAGS)
     */
    public function fetchHeaders(array $uids): array {
        if (empty($uids)) return [];

        $uidList = implode(',', $uids);
        $resp = $this->command('UID FETCH ' . $uidList . ' (FLAGS ENVELOPE BODYSTRUCTURE RFC822.SIZE)');

        return $this->parseEnvelopeResponse($resp);
    }

    /**
     * Einzelne Email komplett abrufen (Header + Body)
     */
    /**
     * @param bool $peek true = BODY.PEEK[] — die Mail bleibt im Postfach ungelesen
     *                   (Belegsuche im Hintergrund soll den Lesestatus nicht anfassen)
     */
    public function fetchMessage(int $uid, bool $peek = false): array {
        // Header + Struktur
        $headers = $this->fetchHeaders([$uid]);
        $header = $headers[$uid] ?? [];

        // Body abrufen
        $resp = $this->command('UID FETCH ' . $uid . ' (' . ($peek ? 'BODY.PEEK[]' : 'BODY[]') . ')');
        $raw = $this->extractBodyContent($resp);

        $parsed = $this->parseRawMessage($raw);
        return array_merge($header, $parsed);
    }

    /**
     * Email als gelesen markieren
     */
    public function markSeen(int $uid): void {
        $this->command('UID STORE ' . $uid . ' +FLAGS (\\Seen)');
    }

    /**
     * Nachricht in einen Ordner speichern (IMAP APPEND)
     *
     * @param string $folder Zielordner (z.B. "Sent", "INBOX.Sent")
     * @param string $rawMessage Rohe MIME-Nachricht
     * @param string $flags Flags (z.B. "\\Seen")
     */
    public function appendToFolder(string $folder, string $rawMessage, string $flags = '\\Seen'): void {
        $tag = 'A' . (++$this->tagCounter);
        $size = strlen($rawMessage);
        $cmd = $tag . ' APPEND "' . $this->escapeString($folder) . '" (' . $flags . ') {' . $size . '}' . "\r\n";
        fwrite($this->socket, $cmd);

        // Server antwortet mit "+" wenn bereit fuer Literal-Daten
        $cont = $this->readLine();
        if (!str_starts_with($cont, '+')) {
            throw new \RuntimeException('IMAP APPEND fehlgeschlagen: Server akzeptiert keine Daten — ' . $cont);
        }

        // Nachricht senden
        fwrite($this->socket, $rawMessage);
        fwrite($this->socket, "\r\n");

        // Antwort lesen
        $response = '';
        while (true) {
            $buf = $this->readLine();
            $response .= $buf . "\n";
            if (str_starts_with($buf, $tag . ' ')) break;
        }

        if (!$this->isOk($response)) {
            throw new \RuntimeException('IMAP APPEND fehlgeschlagen: ' . trim($response));
        }
    }

    /**
     * Sent-Ordner ermitteln (verschiedene IMAP-Server nutzen unterschiedliche Namen)
     *
     * @return string|null Ordnername oder null wenn nicht gefunden
     */
    public function findSentFolder(): ?string {
        $folders = $this->listFolders();
        $sentNames = ['Sent', 'INBOX.Sent', 'Sent Messages', 'Sent Items', 'Gesendete Objekte', 'Gesendete Elemente', 'Gesendet'];

        // Zuerst per \Sent Flag suchen (zuverlaessigste Methode)
        foreach ($folders as $f) {
            if (str_contains($f['flags'], '\\Sent')) {
                return $f['name'];
            }
        }

        // Fallback: nach bekannten Namen suchen (case-insensitive)
        foreach ($folders as $f) {
            foreach ($sentNames as $name) {
                if (strcasecmp($f['name'], $name) === 0) {
                    return $f['name'];
                }
            }
        }

        return null;
    }

    // ==================== Überwachung (IDLE) ====================
    // Für den Dienst oserp-email-eingang (backend/cli/email-eingang.php): Der Server
    // meldet neue Nachrichten im ausgewählten Ordner von sich aus (RFC 2177), der
    // Dienst wartet per stream_select() auf dem Socket. Ohne IDLE-Fähigkeit fragt
    // der Dienst mit noop() regelmäßig nach.

    private ?string $idleTag = null;
    private string $idleBuffer = '';

    /** Socket für stream_select() */
    public function getSocket() {
        return $this->socket;
    }

    /** Beherrscht der Server die Fähigkeit (z. B. IDLE)? */
    public function hasCapability(string $capability): bool {
        $resp = $this->command('CAPABILITY');
        return (bool)preg_match('/^\* CAPABILITY .*\b' . preg_quote($capability, '/') . '\b/mi', $resp);
    }

    /** Nächste zu vergebende UID eines Ordners (alles darunter ist bekannt) */
    public function uidNext(string $folder): int {
        $resp = $this->command('STATUS "' . $this->escapeString($folder) . '" (UIDNEXT)');
        return preg_match('/UIDNEXT (\d+)/', $resp, $m) ? (int)$m[1] : 0;
    }

    /**
     * Alle Adressen aus dem Umschlag (Von, Absender, Antwort an, An, Cc, Bcc)
     * der angegebenen Nachrichten, klein geschrieben und ohne Dubletten
     */
    /**
     * Absenderadresse je Nachricht aus dem From-Kopf (UID → adresse, klein geschrieben)
     *
     * Verlässlicher als der From-Teil von ENVELOPE in parseEnvelopeResponse(): ein
     * Anzeigename mit Klammern oder Anführungszeichen bringt den dortigen Regex aus dem Tritt.
     */
    public function fetchFromAddresses(array $uids): array {
        if (empty($uids)) return [];
        $resp = $this->command('UID FETCH ' . implode(',', $uids) . ' (UID BODY.PEEK[HEADER.FIELDS (FROM)])');
        $result = [];
        foreach (preg_split('/\n(?=\* \d+ FETCH)/', $resp) as $part) {
            if (!preg_match('/UID (\d+)/', $part, $u)) continue;
            if (!preg_match('/^From:[ \t]*((?:.*)(?:\r?\n[ \t].*)*)/mi', $part, $f)) continue;
            $from = $f[1];
            // <adresse> bevorzugen, sonst die erste Zeichenfolge mit @
            if (preg_match('/<\s*([^<>\s]+@[^<>\s]+)\s*>/', $from, $a) || preg_match('/([^\s<>"(),;:]+@[^\s<>"(),;:]+)/', $from, $a)) {
                $result[(int)$u[1]] = strtolower($a[1]);
            }
        }
        return $result;
    }

    public function fetchEnvelopeAddresses(array $uids): array {
        if (empty($uids)) return [];
        $resp = $this->command('UID FETCH ' . implode(',', $uids) . ' (ENVELOPE)');
        // Adressstruktur: (name adl mailbox host) — adl ist praktisch immer NIL
        preg_match_all('/NIL "([^"\s]+)" "([^"\s]+)"\)/', $resp, $m, PREG_SET_ORDER);
        $addresses = [];
        foreach ($m as $a) $addresses[strtolower($a[1] . '@' . $a[2])] = true;
        return array_keys($addresses);
    }

    /** IDLE beginnen; der Server meldet Änderungen danach unaufgefordert */
    public function idleStart(): void {
        $this->idleTag = 'A' . (++$this->tagCounter);
        $this->idleBuffer = '';
        fwrite($this->socket, $this->idleTag . " IDLE\r\n");
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, '+')) return;
            if (str_starts_with($line, $this->idleTag . ' ')) {
                $this->idleTag = null;
                throw new \RuntimeException('IDLE abgelehnt: ' . $line);
            }
        }
    }

    /**
     * Gemeldete Zeilen ohne Warten abholen (nach stream_select())
     * @return bool true, wenn neue Nachrichten gemeldet wurden (EXISTS)
     */
    public function idleRead(): bool {
        stream_set_blocking($this->socket, false);
        try {
            while (($chunk = fread($this->socket, 65536)) !== false && $chunk !== '') {
                $this->idleBuffer .= $chunk;
            }
            $eof = feof($this->socket);
        } finally {
            stream_set_blocking($this->socket, true);
        }
        $exists = false;
        while (($pos = strpos($this->idleBuffer, "\n")) !== false) {
            $line = rtrim(substr($this->idleBuffer, 0, $pos), "\r");
            $this->idleBuffer = substr($this->idleBuffer, $pos + 1);
            if (preg_match('/^\* \d+ EXISTS/i', $line)) $exists = true;
            if (preg_match('/^\* BYE/i', $line)) throw new \RuntimeException('IMAP-Server hat die Verbindung beendet');
        }
        if ($eof) throw new \RuntimeException('IMAP-Verbindung unterbrochen');
        return $exists;
    }

    /**
     * IDLE beenden (vor jedem weiteren Befehl nötig)
     * @return bool true, wenn noch neue Nachrichten gemeldet wurden
     */
    public function idleDone(): bool {
        if ($this->idleTag === null) return false;
        fwrite($this->socket, "DONE\r\n");
        $exists = (bool)preg_match('/^\* \d+ EXISTS/mi', $this->idleBuffer);
        $this->idleBuffer = '';
        while (true) {
            $line = $this->readLine();
            if (preg_match('/^\* \d+ EXISTS/i', $line)) $exists = true;
            if (str_starts_with($line, $this->idleTag . ' ')) break;
        }
        $this->idleTag = null;
        return $exists;
    }

    /** Nachfragen ohne IDLE: true, wenn der Server neue Nachrichten meldet */
    public function noop(): bool {
        return (bool)preg_match('/^\* \d+ EXISTS/mi', $this->command('NOOP'));
    }

    // ==================== Interne Methoden ====================

    private function command(string $cmd): string {
        $tag = 'A' . (++$this->tagCounter);
        $line = $tag . ' ' . $cmd . "\r\n";
        fwrite($this->socket, $line);

        $response = '';
        while (true) {
            $buf = $this->readLine();
            $response .= $buf . "\n";
            // Literale Daten einlesen (z.B. {12345}\r\n)
            if (preg_match('/\{(\d+)\}\s*$/', trim($buf), $m)) {
                $literalLen = (int)$m[1];
                $data = '';
                while (strlen($data) < $literalLen) {
                    $chunk = fread($this->socket, $literalLen - strlen($data));
                    // Verbindung mitten im Literal weg: fread liefert dann '' statt false —
                    // ohne diese Prüfung liefe die Schleife endlos
                    if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                        throw new \RuntimeException('IMAP-Verbindung unterbrochen');
                    }
                    $data .= $chunk;
                }
                $response .= $data;
                continue;
            }
            if (str_starts_with($buf, $tag . ' ')) break;
        }
        return $response;
    }

    private function readLine(): string {
        $line = fgets($this->socket, 65536);
        if ($line === false) {
            throw new \RuntimeException('IMAP-Verbindung unterbrochen');
        }
        return rtrim($line, "\r\n");
    }

    private function isOk(string $resp): bool {
        $lines = array_filter(explode("\n", $resp), function($l) { return trim($l) !== ''; });
        $last = trim(end($lines) ?: '');
        return (bool)preg_match('/^A\d+ OK/i', $last);
    }

    private function escapeString(string $s): string {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
    }

    private function decodeUtf7(string $s): string {
        // IMAP Modified UTF-7 dekodieren
        $s = trim($s, '"');
        return preg_replace_callback('/&([^-]*)-/', function($m) {
            if ($m[1] === '') return '&';
            $decoded = base64_decode(str_replace(',', '/', $m[1]));
            return mb_convert_encoding($decoded, 'UTF-8', 'UTF-16BE');
        }, $s);
    }

    /**
     * ENVELOPE-Response parsen
     */
    private function parseEnvelopeResponse(string $resp): array {
        $results = [];
        // Jede FETCH-Response einzeln verarbeiten
        $lines = explode("\n", $resp);
        $current = '';

        foreach ($lines as $line) {
            if (preg_match('/^\* \d+ FETCH/', $line)) {
                if ($current) $this->parseSingleFetch($current, $results);
                $current = $line;
            } else {
                $current .= "\n" . $line;
            }
        }
        if ($current) $this->parseSingleFetch($current, $results);

        return $results;
    }

    private function parseSingleFetch(string $data, array &$results): void {
        // UID extrahieren
        if (!preg_match('/UID (\d+)/', $data, $m)) return;
        $uid = (int)$m[1];

        $entry = [
            'uid' => $uid,
            'seen' => str_contains($data, '\\Seen'),
            'flagged' => str_contains($data, '\\Flagged'),
            'answered' => str_contains($data, '\\Answered'),
            'subject' => '',
            'from' => '',
            'from_name' => '',
            'to' => '',
            'date' => '',
            'size' => 0,
            'has_attachments' => false,
        ];

        // Size
        if (preg_match('/RFC822\.SIZE (\d+)/', $data, $m)) {
            $entry['size'] = (int)$m[1];
        }

        // ENVELOPE parsen — vereinfacht über Regex
        if (preg_match('/ENVELOPE \((.+)\)/s', $data, $m)) {
            $env = $m[1];
            // Datum (erstes quoted string)
            if (preg_match('/"([^"]*)"/', $env, $dm)) {
                $entry['date'] = $dm[1];
            }
            // Subject (zweites quoted string oder literal)
            $parts = $this->extractQuotedStrings($env);
            if (isset($parts[1])) $entry['subject'] = $this->decodeMimeHeader($parts[1]);
            // From-Adresse aus dem ENVELOPE extrahieren
            if (preg_match('/\(\("?([^"(]*)"?\s+NIL\s+"([^"]*)"\s+"([^"]*)"\)\)/', $env, $fm)) {
                $entry['from_name'] = $this->decodeMimeHeader($fm[1]);
                $entry['from'] = $fm[2] . '@' . $fm[3];
            }
        }

        // Attachments (vereinfacht: BODYSTRUCTURE mit "attachment" oder mehrere parts)
        if (preg_match('/BODYSTRUCTURE \((.+)\)$/s', $data, $m)) {
            $entry['has_attachments'] = str_contains(strtolower($m[1]), '"attachment"')
                || str_contains(strtolower($m[1]), '"mixed"');
        }

        $results[$uid] = $entry;
    }

    private function extractQuotedStrings(string $s): array {
        $strings = [];
        $inQuote = false;
        $current = '';
        $escaped = false;
        for ($i = 0; $i < strlen($s); $i++) {
            $c = $s[$i];
            if ($escaped) { $current .= $c; $escaped = false; continue; }
            if ($c === '\\') { $escaped = true; continue; }
            if ($c === '"') {
                if ($inQuote) {
                    $strings[] = $current;
                    $current = '';
                    $inQuote = false;
                } else {
                    $inQuote = true;
                }
            } elseif ($inQuote) {
                $current .= $c;
            }
        }
        return $strings;
    }

    private function decodeMimeHeader(string $s): string {
        // =?UTF-8?B?...?= oder =?UTF-8?Q?...?= dekodieren
        return preg_replace_callback('/=\?([^?]+)\?([BQ])\?([^?]+)\?=/i', function($m) {
            $charset = $m[1];
            $encoding = strtoupper($m[2]);
            $text = $m[3];
            if ($encoding === 'B') $decoded = base64_decode($text);
            else $decoded = quoted_printable_decode(str_replace('_', ' ', $text));
            return mb_convert_encoding($decoded, 'UTF-8', $charset);
        }, $s);
    }

    private function extractBodyContent(string $resp): string {
        // BODY[] Inhalt aus der FETCH-Response extrahieren
        // Format: * N FETCH (... BODY[] {SIZE}\r\nCONTENT\r\n...)
        $pos = strpos($resp, "BODY[] {");
        if ($pos === false) $pos = strpos($resp, "BODY[] \"");
        if ($pos === false) return '';

        // Literal: {SIZE}
        $bracePos = strpos($resp, '{', $pos);
        if ($bracePos !== false) {
            $endBrace = strpos($resp, '}', $bracePos);
            $size = (int)substr($resp, $bracePos + 1, $endBrace - $bracePos - 1);
            $dataStart = $endBrace + 1;
            // Skip \r\n nach }
            while ($dataStart < strlen($resp) && ($resp[$dataStart] === "\r" || $resp[$dataStart] === "\n")) $dataStart++;
            return substr($resp, $dataStart, $size);
        }

        return '';
    }

    /**
     * Rohe Email-Nachricht (RFC 2822) parsen
     */
    private function parseRawMessage(string $raw): array {
        $result = ['body_text' => '', 'body_html' => '', 'attachments' => []];
        if (empty($raw)) return $result;

        // Header und Body trennen
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);
        $headerStr = $parts[0] ?? '';
        $bodyStr = $parts[1] ?? '';

        $headers = $this->parseHeaders($headerStr);
        $contentType = $headers['content-type'] ?? 'text/plain';

        // Multipart?
        if (preg_match('/boundary="?([^";]+)"?/i', $contentType, $m)) {
            $boundary = $m[1];
            $this->parseMultipart($bodyStr, $boundary, $result);
        } else {
            // Einfache Nachricht
            $body = $this->decodeBody($bodyStr, $headers['content-transfer-encoding'] ?? '7bit');
            $charset = 'UTF-8';
            if (preg_match('/charset="?([^";]+)"?/i', $contentType, $m)) $charset = $m[1];
            $body = mb_convert_encoding($body, 'UTF-8', $charset);

            if (str_contains($contentType, 'text/html')) {
                $result['body_html'] = $body;
            } else {
                $result['body_text'] = $body;
            }
        }

        // To, CC aus Headers
        $result['to'] = $headers['to'] ?? '';
        $result['cc'] = $headers['cc'] ?? '';

        return $result;
    }

    private function parseHeaders(string $headerStr): array {
        $headers = [];
        $lines = preg_split('/\r?\n/', $headerStr);
        $currentKey = '';
        foreach ($lines as $line) {
            if (preg_match('/^(\S+):\s*(.*)$/', $line, $m)) {
                $currentKey = strtolower($m[1]);
                $headers[$currentKey] = $m[2];
            } elseif ($currentKey && preg_match('/^\s+(.*)$/', $line, $m)) {
                $headers[$currentKey] .= ' ' . $m[1];
            }
        }
        return $headers;
    }

    private function parseMultipart(string $body, string $boundary, array &$result): void {
        $parts = explode('--' . $boundary, $body);
        array_shift($parts); // Preamble

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '--' || $part === '') continue; // Ende-Marker

            $sections = preg_split('/\r?\n\r?\n/', $part, 2);
            $partHeaderStr = $sections[0] ?? '';
            $partBody = $sections[1] ?? '';
            $partHeaders = $this->parseHeaders($partHeaderStr);
            $partContentType = $partHeaders['content-type'] ?? 'text/plain';
            $partEncoding = $partHeaders['content-transfer-encoding'] ?? '7bit';
            $disposition = $partHeaders['content-disposition'] ?? '';

            // Verschachteltes Multipart
            if (preg_match('/boundary="?([^";]+)"?/i', $partContentType, $m)) {
                $this->parseMultipart($partBody, $m[1], $result);
                continue;
            }

            $decoded = $this->decodeBody($partBody, $partEncoding);

            // Attachment?
            if (str_contains(strtolower($disposition), 'attachment') || str_contains(strtolower($disposition), 'inline')) {
                $filename = 'attachment';
                if (preg_match('/filename="?([^";]+)"?/i', $disposition . $partContentType, $m)) {
                    $filename = $this->decodeMimeHeader(trim($m[1]));
                }
                $result['attachments'][] = [
                    'filename' => $filename,
                    'content_type' => preg_replace('/;.*/', '', $partContentType),
                    'size' => strlen($decoded),
                    'content_base64' => base64_encode($decoded),
                    // inline = im HTML eingebettet (Signaturbilder, Logos) — die
                    // Belegsuche ueberspringt solche Teile
                    'inline' => str_contains(strtolower($disposition), 'inline'),
                ];
                continue;
            }

            // Text-Content
            $charset = 'UTF-8';
            if (preg_match('/charset="?([^";]+)"?/i', $partContentType, $m)) $charset = $m[1];
            $text = mb_convert_encoding($decoded, 'UTF-8', $charset);

            if (str_contains($partContentType, 'text/html')) {
                $result['body_html'] = $text;
            } elseif (str_contains($partContentType, 'text/plain')) {
                $result['body_text'] = $text;
            }
        }
    }

    private function decodeBody(string $body, string $encoding): string {
        $encoding = strtolower(trim($encoding));
        return match($encoding) {
            'base64' => base64_decode($body),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }
}
