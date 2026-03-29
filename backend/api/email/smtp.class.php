<?php
// backend/api/email/smtp.class.php

/**
 * Einfacher SMTP-Client über stream_socket_client
 * Keine externe Bibliothek erforderlich
 */
class SmtpClient {
    private $socket = null;
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

    /**
     * Email senden
     */
    /**
     * Email senden und MIME-Nachricht zurueckgeben (fuer IMAP-Archivierung)
     *
     * @return string Die rohe MIME-Nachricht (ohne Dot-Stuffing)
     */
    public function send(
        string $fromEmail,
        string $fromName,
        array $to,
        string $subject,
        string $bodyHtml,
        string $bodyText = '',
        array $cc = [],
        array $bcc = [],
        array $attachments = []
    ): string {
        $this->connect();

        try {
            // Absender
            $this->sendCommand('MAIL FROM:<' . $fromEmail . '>');

            // Empfänger
            $allRecipients = array_merge($to, $cc, $bcc);
            foreach ($allRecipients as $recipient) {
                $email = is_array($recipient) ? $recipient['email'] : $recipient;
                $this->sendCommand('RCPT TO:<' . $email . '>');
            }

            // Daten
            $this->sendCommand('DATA', 354);

            $message = $this->buildMessage($fromEmail, $fromName, $to, $subject, $bodyHtml, $bodyText, $cc, $attachments);
            fwrite($this->socket, $message);
            fwrite($this->socket, "\r\n.\r\n");
            $this->readResponse(250);

            // Dot-Stuffing rueckgaengig machen fuer die Rueckgabe (Original-Nachricht)
            $rawMessage = str_replace("\r\n..", "\r\n.", $message);
            return $rawMessage;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Verbindung testen
     */
    public function testConnection(): bool {
        $this->connect();
        $this->disconnect();
        return true;
    }

    // ==================== Interne Methoden ====================

    private function connect(): void {
        $address = $this->host . ':' . $this->port;
        if ($this->encryption === 'ssl') {
            $address = 'ssl://' . $address;
        }

        $context = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);

        $this->socket = @stream_socket_client($address, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket) {
            throw new \RuntimeException("SMTP-Verbindung fehlgeschlagen: $errstr ($errno)");
        }

        stream_set_timeout($this->socket, 15);
        $this->readResponse(220);

        // EHLO
        $this->sendCommand('EHLO ' . gethostname());

        // STARTTLS
        if ($this->encryption === 'tls' || $this->encryption === 'starttls') {
            $this->sendCommand('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new \RuntimeException('STARTTLS fehlgeschlagen');
            }
            $this->sendCommand('EHLO ' . gethostname());
        }

        // AUTH LOGIN
        if ($this->username && $this->password) {
            $this->sendCommand('AUTH LOGIN', 334);
            $this->sendCommand(base64_encode($this->username), 334);
            $this->sendCommand(base64_encode($this->password), 235);
        }
    }

    private function disconnect(): void {
        if ($this->socket) {
            try {
                fwrite($this->socket, "QUIT\r\n");
            } catch (\Exception $e) {}
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function sendCommand(string $cmd, int $expectedCode = 250): string {
        fwrite($this->socket, $cmd . "\r\n");
        return $this->readResponse($expectedCode);
    }

    private function readResponse(int $expectedCode): string {
        $response = '';
        while (true) {
            $line = fgets($this->socket, 4096);
            if ($line === false) {
                throw new \RuntimeException('SMTP-Verbindung unterbrochen');
            }
            $response .= $line;
            // Mehrzeilige Antwort: Code + "-" bedeutet Fortsetzung
            if (isset($line[3]) && $line[3] !== '-') break;
        }

        $code = (int)substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException("SMTP-Fehler: erwartet $expectedCode, erhalten $code — " . trim($response));
        }
        return $response;
    }

    private function buildMessage(
        string $fromEmail,
        string $fromName,
        array $to,
        string $subject,
        string $bodyHtml,
        string $bodyText,
        array $cc,
        array $attachments
    ): string {
        $boundary = '----=_Part_' . bin2hex(random_bytes(16));
        $boundaryMixed = '----=_Mixed_' . bin2hex(random_bytes(16));

        // Header
        $headers = [];
        $headers[] = 'From: ' . $this->encodeAddress($fromEmail, $fromName);
        $headers[] = 'To: ' . $this->formatAddressList($to);
        if (!empty($cc)) {
            $headers[] = 'Cc: ' . $this->formatAddressList($cc);
        }
        $headers[] = 'Subject: ' . $this->encodeHeader($subject);
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . gethostname() . '>';
        $headers[] = 'MIME-Version: 1.0';

        $hasAttachments = !empty($attachments);

        if ($hasAttachments) {
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundaryMixed . '"';
        } else {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        }

        $msg = implode("\r\n", $headers) . "\r\n\r\n";

        // Body (alternative: text + html)
        $alternativeContent = '';
        if ($bodyText) {
            $alternativeContent .= '--' . $boundary . "\r\n";
            $alternativeContent .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $alternativeContent .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $alternativeContent .= chunk_split(base64_encode($bodyText)) . "\r\n";
        }
        if ($bodyHtml) {
            $alternativeContent .= '--' . $boundary . "\r\n";
            $alternativeContent .= "Content-Type: text/html; charset=UTF-8\r\n";
            $alternativeContent .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $alternativeContent .= chunk_split(base64_encode($bodyHtml)) . "\r\n";
        }
        $alternativeContent .= '--' . $boundary . "--\r\n";

        if ($hasAttachments) {
            $msg .= '--' . $boundaryMixed . "\r\n";
            $msg .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n\r\n";
            $msg .= $alternativeContent;

            foreach ($attachments as $att) {
                $msg .= '--' . $boundaryMixed . "\r\n";
                $msg .= 'Content-Type: ' . ($att['content_type'] ?? 'application/octet-stream') . '; name="' . $att['filename'] . '"' . "\r\n";
                $msg .= "Content-Transfer-Encoding: base64\r\n";
                $msg .= 'Content-Disposition: attachment; filename="' . $att['filename'] . '"' . "\r\n\r\n";
                $msg .= chunk_split($att['content_base64'] ?? base64_encode($att['content'] ?? '')) . "\r\n";
            }
            $msg .= '--' . $boundaryMixed . "--\r\n";
        } else {
            $msg .= $alternativeContent;
        }

        // Dot-Stuffing (RFC 5321)
        $msg = str_replace("\r\n.", "\r\n..", $msg);

        return $msg;
    }

    private function encodeAddress(string $email, string $name = ''): string {
        if ($name) {
            return $this->encodeHeader($name) . ' <' . $email . '>';
        }
        return '<' . $email . '>';
    }

    private function formatAddressList(array $addresses): string {
        $parts = [];
        foreach ($addresses as $addr) {
            if (is_array($addr)) {
                $parts[] = $this->encodeAddress($addr['email'], $addr['name'] ?? '');
            } else {
                $parts[] = '<' . $addr . '>';
            }
        }
        return implode(', ', $parts);
    }

    private function encodeHeader(string $s): string {
        if (preg_match('/[\x80-\xFF]/', $s)) {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        }
        return $s;
    }
}
