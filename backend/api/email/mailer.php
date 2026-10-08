<?php
// backend/api/email/mailer.php
//
// Gemeinsame Mail-Bausteine ohne API-Funktionen: Konfiguration, SMTP- und
// IMAP-Client, Journal. Hier getrennt von email.php, damit Module, die nur
// versenden wollen (wiederkehrende Rechnungen, Mahnwesen), die Bausteine
// einbinden können, ohne die API-Funktionen von email.php mitzuladen — die
// kollidieren sonst mit gleichnamigen Funktionen anderer Module
// (searchCvEmails gibt es in email.php und faktura.php).

require_once __DIR__.'/imap.class.php';
require_once __DIR__.'/smtp.class.php';

/**
 * Email-Config laden (Kaskade: Employee → Company)
 */
function _getEmailConfig(): array {
    $db = DbhCompany::begin();

    // 1. Firmenweite Config aus defaults_oserp
    $rows = $db->getAll("SELECT key, value FROM defaults_oserp WHERE key LIKE 'email_%'");
    $config = [];
    foreach ($rows as $row) {
        $config[$row['key']] = $row['value'];
    }

    // 2. Benutzerspezifische Config (überschreibt Firmen-Config)
    if (isset($_SESSION['employee_id']) && $_SESSION['employee_id']) {
        $empRows = $db->getAll(
            "SELECT key, value FROM employee_config_oserp WHERE employee_id = :eid AND key LIKE 'email_%'",
            [':eid' => $_SESSION['employee_id']]
        );
        foreach ($empRows as $row) {
            if (!empty($row['value'])) {
                $config[$row['key']] = $row['value'];
            }
        }
    }

    return $config;
}

/**
 * IMAP-Client erstellen und verbinden
 */
function _createImapClient(array $config = null): ImapClient {
    if (!$config) $config = _getEmailConfig();

    $host = $config['email_imap_host'] ?? '';
    $port = (int)($config['email_imap_port'] ?? 993);
    $encryption = $config['email_imap_encryption'] ?? 'ssl';
    $username = $config['email_username'] ?? '';
    $password = $config['email_password'] ?? '';

    if (empty($host) || empty($username) || empty($password)) {
        throw new ApiError('EMAIL_NOT_CONFIGURED', 'E-Mail-Client ist nicht konfiguriert. Bitte IMAP-Einstellungen in der Firmenkonfiguration hinterlegen.');
    }

    $imap = new ImapClient($host, $port, $encryption, $username, $password);
    $imap->connect();
    return $imap;
}

/**
 * SMTP-Client erstellen
 */
function _createSmtpClient(array $config = null): SmtpClient {
    if (!$config) $config = _getEmailConfig();

    $host = $config['email_smtp_host'] ?? '';
    $port = (int)($config['email_smtp_port'] ?? 465);
    $encryption = $config['email_smtp_encryption'] ?? 'ssl';
    $username = $config['email_username'] ?? '';
    $password = $config['email_password'] ?? '';

    if (empty($host) || empty($username) || empty($password)) {
        throw new ApiError('EMAIL_NOT_CONFIGURED', 'E-Mail-Client ist nicht konfiguriert. Bitte SMTP-Einstellungen in der Firmenkonfiguration hinterlegen.');
    }

    return new SmtpClient($host, $port, $encryption, $username, $password);
}

/**
 * Email-Journal-Eintrag erstellen (interne Hilfsfunktion)
 *
 * Mit $recordTable/$recordId wird der Eintrag wie in kivitendo über record_links
 * an den Beleg gehängt (from = Beleg, to = email_journal) — daraus speist sich
 * die Versandanzeige in der Faktura. Liefert die Journal-ID.
 */
function _logToEmailJournal(string $from, array $to, array $cc, string $subject, string $body, array $attachments, ?string $recordType, ?string $recordTable = null, ?int $recordId = null, ?int $employeeId = null): int {
    $db = DbhCompany::begin();

    // Empfaenger als kommaseparierte Liste
    $recipientList = [];
    foreach ($to as $r) {
        $recipientList[] = is_array($r) ? ($r['email'] ?? '') : $r;
    }
    foreach ($cc as $r) {
        $recipientList[] = is_array($r) ? ($r['email'] ?? '') : $r;
    }
    $recipients = implode(', ', array_filter($recipientList));

    // Sender-ID: Mitarbeiter aus dem Aufruf (Store), sonst aus der Session
    $senderId = $employeeId ?: null;
    if (!$senderId && isset($_SESSION['employee_id']) && $_SESSION['employee_id']) {
        $senderId = (int)$_SESSION['employee_id'];
    }

    $journalRow = $db->getOne(
        "INSERT INTO email_journal (sender_id, \"from\", recipients, subject, body, headers, extended_status, status, record_type)
         VALUES (:sender_id, :from, :recipients, :subject, :body, '', '', 'sent', :record_type)
         RETURNING id",
        [
            ':sender_id' => $senderId,
            ':from' => $from,
            ':recipients' => $recipients,
            ':subject' => $subject,
            ':body' => $body,
            ':record_type' => $recordType
        ]
    );
    $jid = (int)($journalRow['id'] ?? 0);

    // Verknüpfung zum Beleg (kivitendo-Konvention: from = Beleg, to = email_journal)
    if ($jid && $recordId && in_array($recordTable, ['oe', 'ar', 'ap', 'delivery_orders'], true)) {
        $db->execute(
            "INSERT INTO record_links (from_table, from_id, to_table, to_id) VALUES (:ft, :fid, 'email_journal', :tid)",
            [':ft' => $recordTable, ':fid' => $recordId, ':tid' => $jid]
        );
    }

    // Anhaenge im Journal speichern
    if (!empty($attachments)) {
        if ($jid) {
            $pos = 0;
            foreach ($attachments as $att) {
                $content = base64_decode($att['content_base64'] ?? '');
                $db->execute(
                    "INSERT INTO email_journal_attachments (\"position\", email_journal_id, name, mime_type, content)
                     VALUES (:pos, :jid, :name, :mime_type, :content)",
                    [
                        ':pos' => $pos++,
                        ':jid' => $jid,
                        ':name' => $att['filename'] ?? 'attachment',
                        ':mime_type' => $att['content_type'] ?? 'application/octet-stream',
                        ':content' => $content
                    ]
                );
            }
        }
    }

    return $jid;
}
