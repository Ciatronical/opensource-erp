<?php
// backend/api/email/email.php

/**
 * RuntimeException in ApiError umwandeln (für saubere JSON-Responses)
 */
function _emailApiCall(callable $fn, array $data): void {
    try {
        $fn($data);
    } catch (ApiError $e) {
        throw $e; // ApiError wird von api.call.php abgefangen
    } catch (\RuntimeException $e) {
        error_log('[EMAIL] Connection error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        resultInfo(false, 'EMAIL_CONNECTION_ERROR', $e->getMessage());
    } catch (\Exception $e) {
        error_log('[EMAIL] Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        resultInfo(false, 'EMAIL_ERROR', $e->getMessage());
    }
}

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

// ==================== API-Funktionen ====================

/**
 * Kunden/Lieferanten mit Emailadressen suchen (für Compose-Dialog)
 *
 * Parameter:
 *   query: Suchbegriff (min 2 Zeichen)
 */
function searchCvEmails($data) {
    $query = trim($data['query'] ?? '');
    if (strlen($query) < 2) {
        resultInfo(true, '', []);
        return;
    }

    $db = DbhCompany::begin();
    $containsQ = '%' . $query . '%';
    $results = [];

    // Kunden suchen
    $customers = $db->getAll(
        "SELECT c.id, c.name, c.customernumber, c.email,
                (SELECT string_agg(ct.cp_email, ', ') FROM contacts ct WHERE ct.cp_cv_id = c.id AND ct.cp_email IS NOT NULL AND ct.cp_email != '') AS contact_emails
         FROM customer c
         WHERE NOT COALESCE(c.obsolete, false)
           AND (LOWER(c.name) LIKE LOWER(:q1) OR LOWER(c.email) LIKE LOWER(:q2) OR LOWER(c.customernumber) LIKE LOWER(:q3))
         ORDER BY c.name LIMIT 10",
        [':q1' => $containsQ, ':q2' => $containsQ, ':q3' => $containsQ]
    );

    foreach ($customers ?: [] as $row) {
        $emails = [];
        if (!empty($row['email'])) $emails[] = $row['email'];
        if (!empty($row['contact_emails'])) {
            foreach (explode(', ', $row['contact_emails']) as $e) {
                $e = trim($e);
                if ($e && !in_array($e, $emails)) $emails[] = $e;
            }
        }
        if (empty($emails)) continue;

        $results[] = [
            'id' => (int)$row['id'],
            'type' => 'customer',
            'name' => $row['name'],
            'number' => $row['customernumber'],
            'emails' => $emails,
            'route' => '/kunde/' . $row['id'],
        ];
    }

    // Lieferanten suchen
    $vendors = $db->getAll(
        "SELECT v.id, v.name, v.vendornumber, v.email,
                (SELECT string_agg(ct.cp_email, ', ') FROM contacts ct WHERE ct.cp_cv_id = v.id AND ct.cp_email IS NOT NULL AND ct.cp_email != '') AS contact_emails
         FROM vendor v
         WHERE NOT COALESCE(v.obsolete, false)
           AND (LOWER(v.name) LIKE LOWER(:q1) OR LOWER(v.email) LIKE LOWER(:q2) OR LOWER(v.vendornumber) LIKE LOWER(:q3))
         ORDER BY v.name LIMIT 10",
        [':q1' => $containsQ, ':q2' => $containsQ, ':q3' => $containsQ]
    );

    foreach ($vendors ?: [] as $row) {
        $emails = [];
        if (!empty($row['email'])) $emails[] = $row['email'];
        if (!empty($row['contact_emails'])) {
            foreach (explode(', ', $row['contact_emails']) as $e) {
                $e = trim($e);
                if ($e && !in_array($e, $emails)) $emails[] = $e;
            }
        }
        if (empty($emails)) continue;

        $results[] = [
            'id' => (int)$row['id'],
            'type' => 'vendor',
            'name' => $row['name'],
            'number' => $row['vendornumber'],
            'emails' => $emails,
            'route' => '/lieferant/' . $row['id'],
        ];
    }

    resultInfo(true, '', $results);
}

/**
 * E-Mails abrufen (gefiltert nach Adressen eines Kunden/Lieferanten)
 *
 * Parameter:
 *   email_addresses: Array von E-Mail-Adressen
 *   folder: IMAP-Ordner (Standard: INBOX)
 *   page: Seitennummer (Standard: 1)
 *   limit: Anzahl pro Seite (Standard: 50)
 */
function getEmails($data) {
    _emailApiCall(function($data) {
        $addresses = $data['email_addresses'] ?? [];
        $page = max(1, (int)($data['page'] ?? 1));
        $limit = min(100, max(10, (int)($data['limit'] ?? 50)));

        if (empty($addresses)) {
            resultInfo(true, '', ['emails' => [], 'total' => 0, 'page' => $page, 'pages' => 0]);
            return;
        }

        $imap = _createImapClient();

        try {
            // Ordner durchsuchen: INBOX + Sent
            $folders = ['INBOX'];
            $sentFolder = $imap->findSentFolder();
            if ($sentFolder && $sentFolder !== 'INBOX') {
                $folders[] = $sentFolder;
            }

            $allEmails = [];

            foreach ($folders as $folder) {
                try {
                    $imap->selectFolder($folder);
                } catch (\Exception $e) {
                    continue;
                }

                $folderUids = [];
                foreach ($addresses as $addr) {
                    $addr = trim($addr);
                    if (empty($addr)) continue;

                    $fromUids = $imap->search('FROM "' . $addr . '"');
                    $toUids = $imap->search('TO "' . $addr . '"');
                    $folderUids = array_merge($folderUids, $fromUids, $toUids);
                }

                $folderUids = array_unique($folderUids);
                if (!empty($folderUids)) {
                    $headers = $imap->fetchHeaders($folderUids);
                    foreach ($headers as $header) {
                        $header['folder'] = $folder;
                        $allEmails[] = $header;
                    }
                }
            }

            // Nach Datum sortieren (neueste zuerst)
            usort($allEmails, function($a, $b) {
                return strtotime($b['date'] ?? '0') - strtotime($a['date'] ?? '0');
            });

            $total = count($allEmails);
            $pages = (int)ceil($total / $limit);

            // Pagination
            $offset = ($page - 1) * $limit;
            $emails = array_slice($allEmails, $offset, $limit);

            resultInfo(true, '', [
                'emails' => $emails,
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
            ]);
        } finally {
            $imap->disconnect();
        }
    }, $data);
}

/**
 * Alle E-Mails eines Ordners abrufen (für Hauptansicht)
 *
 * Parameter:
 *   folder: IMAP-Ordner (Standard: INBOX)
 *   page: Seitennummer
 *   limit: Anzahl pro Seite
 *   search: Optional — Suchtext (in FROM, TO, SUBJECT)
 */
function getAllEmails($data) {
    _emailApiCall(function($data) {
        $folder = $data['folder'] ?? 'INBOX';
        $page = max(1, (int)($data['page'] ?? 1));
        $limit = min(100, max(10, (int)($data['limit'] ?? 50)));
        $search = trim($data['search'] ?? '');

        $imap = _createImapClient();

        try {
            $imap->selectFolder($folder);

            if ($search) {
                // OR-Suche in From, Subject, To
                // IMAP OR kann nur 2 Operanden → verschachteln
                $uids = $imap->search('OR OR FROM "' . $search . '" TO "' . $search . '" SUBJECT "' . $search . '"');
            } else {
                $uids = $imap->search('ALL');
            }

            rsort($uids, SORT_NUMERIC);

            $total = count($uids);
            $pages = ceil($total / $limit);
            $offset = ($page - 1) * $limit;
            $pageUids = array_slice($uids, $offset, $limit);

            $emails = [];
            if (!empty($pageUids)) {
                $headers = $imap->fetchHeaders($pageUids);
                foreach ($pageUids as $uid) {
                    if (isset($headers[$uid])) {
                        $emails[] = $headers[$uid];
                    }
                }
            }

            resultInfo(true, '', [
                'emails' => $emails,
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
            ]);
        } finally {
            $imap->disconnect();
        }
    }, $data);
}

/**
 * Einzelne E-Mail lesen (Body + Attachments)
 *
 * Parameter:
 *   uid: UID der Nachricht
 *   folder: IMAP-Ordner
 */
function getEmail($data) {
    _emailApiCall(function($data) {
        $uid = (int)($data['uid'] ?? 0);
        $folder = $data['folder'] ?? 'INBOX';

        if (!$uid) {
            throw new ApiError('EMAIL_MISSING_UID', 'Keine Nachrichten-UID angegeben');
        }

        $imap = _createImapClient();

        try {
            $imap->selectFolder($folder);
            $message = $imap->fetchMessage($uid);
            $imap->markSeen($uid);

            resultInfo(true, '', $message);
        } finally {
            $imap->disconnect();
        }
    }, $data);
}

/**
 * E-Mail senden
 *
 * Parameter:
 *   to: Array [{email, name}] oder String
 *   cc: Array (optional)
 *   bcc: Array (optional)
 *   subject: Betreff
 *   body_html: HTML-Body
 *   body_text: Text-Body (optional, wird aus HTML generiert falls leer)
 *   attachments: Array [{filename, content_base64, content_type}] (optional)
 */
function sendEmail($data) {
    _emailApiCall(function($data) {
        $config = _getEmailConfig();
        $smtp = _createSmtpClient($config);

        $fromEmail = $config['email_address'] ?? $config['email_username'] ?? '';
        $fromName = $data['from_name'] ?? '';

        $to = $data['to'] ?? [];
        if (is_string($to)) $to = [['email' => $to, 'name' => '']];

        $cc = $data['cc'] ?? [];
        $bcc = $data['bcc'] ?? [];
        $subject = $data['subject'] ?? '';
        $bodyHtml = $data['body_html'] ?? '';
        $bodyText = $data['body_text'] ?? '';
        $attachments = $data['attachments'] ?? [];

        if (empty($to)) {
            throw new ApiError('EMAIL_NO_RECIPIENTS', 'Keine Empfänger angegeben');
        }
        if (empty($subject) && empty($bodyHtml) && empty($bodyText)) {
            throw new ApiError('EMAIL_EMPTY', 'Betreff und Nachricht sind leer');
        }

        // Fallback: Text aus HTML generieren
        if (empty($bodyText) && !empty($bodyHtml)) {
            $bodyText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $bodyHtml));
        }

        // 1. E-Mail senden (gibt die rohe MIME-Nachricht zurueck)
        $rawMessage = $smtp->send($fromEmail, $fromName, $to, $subject, $bodyHtml, $bodyText, $cc, $bcc, $attachments);

        // 2. Kopie im IMAP Sent-Ordner speichern
        try {
            $imap = _createImapClient($config);
            $sentFolder = $imap->findSentFolder();
            if ($sentFolder) {
                $imap->appendToFolder($sentFolder, $rawMessage);
            }
            $imap->disconnect();
        } catch (\Exception $e) {
            // Sent-Ordner-Fehler ist nicht kritisch — Email wurde trotzdem gesendet
            error_log('[EMAIL] Kopie im Sent-Ordner speichern fehlgeschlagen: ' . $e->getMessage());
        }

        // 3. Email-Journal-Eintrag erstellen
        try {
            _logToEmailJournal($fromEmail, $to, $cc, $subject, $bodyText ?: $bodyHtml, $attachments, $data['record_type'] ?? null);
        } catch (\Exception $e) {
            error_log('[EMAIL] Journal-Eintrag fehlgeschlagen: ' . $e->getMessage());
        }

        resultInfo(true, 'E-Mail erfolgreich gesendet');
    }, $data);
}

/**
 * Email-Journal-Eintrag erstellen (interne Hilfsfunktion)
 */
function _logToEmailJournal(string $from, array $to, array $cc, string $subject, string $body, array $attachments, ?string $recordType): void {
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

    // Sender-ID (aktueller Mitarbeiter)
    $senderId = null;
    if (isset($_SESSION['employee_id']) && $_SESSION['employee_id']) {
        $senderId = (int)$_SESSION['employee_id'];
    }

    $db->execute(
        "INSERT INTO email_journal (sender_id, \"from\", recipients, subject, body, headers, extended_status, status, record_type)
         VALUES (:sender_id, :from, :recipients, :subject, :body, '', '', 'sent', :record_type)",
        [
            ':sender_id' => $senderId,
            ':from' => $from,
            ':recipients' => $recipients,
            ':subject' => $subject,
            ':body' => $body,
            ':record_type' => $recordType
        ]
    );

    // Anhaenge im Journal speichern
    if (!empty($attachments)) {
        $journalRow = $db->getOne("SELECT currval(pg_get_serial_sequence('email_journal', 'id')) AS id");
        if ($journalRow) {
            $jid = (int)$journalRow['id'];
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
}

/**
 * IMAP-Ordnerliste abrufen
 */
/**
 * Neue ungelesene E-Mails seit einem bestimmten Datum abrufen (leichtgewichtig fuer Info Bar)
 *
 * Parameter:
 *   since_date: Datum im Format "YYYY-MM-DD"
 *   folder: IMAP-Ordner (Standard: INBOX)
 *   limit: Max. Anzahl (Standard: 20)
 */
function getNewEmails($data) {
    _emailApiCall(function($data) {
        $sinceDate = $data['since_date'] ?? date('Y-m-d');
        $folder = $data['folder'] ?? 'INBOX';
        $limit = min(50, max(1, (int)($data['limit'] ?? 20)));

        $imap = _createImapClient();

        try {
            $imap->selectFolder($folder);

            $imapDate = date('d-M-Y', strtotime($sinceDate));
            $uids = $imap->search('UNSEEN SINCE "' . $imapDate . '"');

            rsort($uids, SORT_NUMERIC);
            $total = count($uids);
            $pageUids = array_slice($uids, 0, $limit);

            $emails = [];
            if (!empty($pageUids)) {
                $headers = $imap->fetchHeaders($pageUids);
                foreach ($pageUids as $uid) {
                    if (isset($headers[$uid])) {
                        $emails[] = $headers[$uid];
                    }
                }
            }

            // Kunden/Lieferanten per Email-Adresse matchen
            if (!empty($emails)) {
                $db = DbhCompany::begin();
                foreach ($emails as &$email) {
                    $fromAddr = strtolower(trim($email['from'] ?? ''));
                    if (empty($fromAddr)) continue;
                    $cv = $db->getOne(
                        "SELECT id, 'C' AS src FROM customer WHERE LOWER(email) = :email
                         UNION ALL
                         SELECT id, 'V' AS src FROM vendor WHERE LOWER(email) = :email
                         LIMIT 1",
                        [':email' => $fromAddr]
                    );
                    if ($cv) {
                        $email['customer_id'] = (int)$cv['id'];
                        $email['cv_src'] = $cv['src'];
                    }
                }
                unset($email);
            }

            resultInfo(true, '', [
                'emails' => $emails,
                'total' => $total,
            ]);
        } finally {
            $imap->disconnect();
        }
    }, $data);
}

function getEmailFolders($data) {
    _emailApiCall(function($data) {
        $imap = _createImapClient();

        try {
            $folders = $imap->listFolders();
            resultInfo(true, '', ['folders' => $folders]);
        } finally {
            $imap->disconnect();
        }
    }, $data);
}

/**
 * IMAP/SMTP-Verbindung testen
 *
 * Parameter:
 *   test_type: 'imap', 'smtp' oder 'both'
 */
function testEmailConnection($data) {
    _emailApiCall(function($data) {
        $testType = $data['test_type'] ?? 'both';
        $results = [];

        $config = _getEmailConfig();

        if ($testType === 'imap' || $testType === 'both') {
            try {
                $imap = _createImapClient($config);
                $folders = $imap->listFolders();
                $imap->disconnect();
                $results['imap'] = [
                    'success' => true,
                    'message' => 'IMAP-Verbindung erfolgreich (' . count($folders) . ' Ordner gefunden)',
                ];
            } catch (\Exception $e) {
                $results['imap'] = [
                    'success' => false,
                    'message' => 'IMAP-Fehler: ' . $e->getMessage(),
                ];
            }
        }

        if ($testType === 'smtp' || $testType === 'both') {
            try {
                $smtp = _createSmtpClient($config);
                $smtp->testConnection();
                $results['smtp'] = [
                    'success' => true,
                    'message' => 'SMTP-Verbindung erfolgreich',
                ];
            } catch (\Exception $e) {
                $results['smtp'] = [
                    'success' => false,
                    'message' => 'SMTP-Fehler: ' . $e->getMessage(),
                ];
            }
        }

        resultInfo(true, '', $results);
    }, $data);
}
