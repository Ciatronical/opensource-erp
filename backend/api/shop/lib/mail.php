<?php
// backend/api/shop/lib/mail.php
//
// Mailversand des Shops über die SMTP-Klasse von OpensourceERP. Die Bridge
// brachte dafür PHPMailer mit (sechs Dateien); davon bleibt nichts.
//
// Die Zugangsdaten stehen in denselben email_*-Einstellungen wie im übrigen
// ERP — der Shop bekommt keine zweite SMTP-Konfiguration. Absender ist die
// Firmenadresse; nur der Empfänger und der Text sind shop-eigen.
//
// Die Vorlagen liegen unter templates/ als PHP-Dateien, damit sie sich ohne
// Codeänderung anpassen lassen.

/**
 * Baut den SMTP-Client aus den Firmeneinstellungen
 *
 * Bewusst nicht über _createSmtpClient() aus email/email.php: diese Datei
 * bringt die Mail-Aktionen des ERP mit, die im öffentlichen Zugang nichts zu
 * suchen haben. Gelesen werden dieselben Einstellungen.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array{client: SmtpClient, from: string, from_name: string}
 * @throws ApiError EMAIL_NOT_CONFIGURED
 */
function shopMailer($db): array {
    $zeilen = $db->getAll("SELECT key, value FROM defaults_oserp WHERE key LIKE 'email\\_%' ESCAPE '\\'", []);
    $cfg = [];
    foreach ($zeilen as $zeile) {
        $cfg[$zeile['key']] = $zeile['value'];
    }

    $host     = $cfg['email_smtp_host'] ?? '';
    $benutzer = $cfg['email_username']  ?? '';
    $kennwort = $cfg['email_password']  ?? '';

    if ('' === $host || '' === $benutzer || '' === $kennwort) {
        throw new ApiError(
            'EMAIL_NOT_CONFIGURED',
            'Der Mailversand ist nicht eingerichtet (Einstellungen -> E-Mail)'
        );
    }

    return [
        'client' => new SmtpClient(
            $host,
            (int)($cfg['email_smtp_port'] ?? 465),
            $cfg['email_smtp_encryption'] ?? 'ssl',
            $benutzer,
            $kennwort
        ),
        'from'      => $cfg['email_from']      ?? $benutzer,
        'from_name' => $cfg['email_from_name'] ?? '',
    ];
}

/**
 * Füllt eine Mailvorlage
 *
 * @param string $vorlage Dateiname unter templates/, ohne Endung
 * @param array $werte Variablen der Vorlage
 * @return string HTML
 * @throws ApiError MAIL_TEMPLATE_NOT_FOUND
 */
function shopMailTemplate(string $vorlage, array $werte): string {
    $datei = __DIR__.'/../templates/'.basename($vorlage).'.php';
    if (!file_exists($datei)) {
        throw new ApiError('MAIL_TEMPLATE_NOT_FOUND', 'Mailvorlage '.$vorlage.' fehlt');
    }

    extract($werte, EXTR_SKIP);
    ob_start();
    include $datei;
    return (string)ob_get_clean();
}

/**
 * Verschickt die Rechnung an den Kunden
 *
 * Der Anhang entsteht über die Druckaufbereitung von OpensourceERP und wird
 * nach dem Versand wieder entfernt — die Belegablage ist Sache des ERP, nicht
 * des Mailversands.
 *
 * Wirft nicht: die Rechnung steht bereits, wenn diese Funktion gerufen wird.
 * Ein Fehlschlag beim Versand darf den Kauf nicht scheitern lassen — sonst
 * sieht der Kunde einen Fehler, obwohl seine Bestellung angekommen ist, und
 * bestellt womöglich ein zweites Mal. Was schiefging, steht im Protokoll.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @return bool true wenn versendet
 */
function shopSendInvoiceMail($db, int $arId): bool {
    try {
        return shopSendInvoiceMailOrFail($db, $arId);
    } catch (Exception $e) {
        writeLog('[SHOP] Rechnungsmail zu '.$arId.' nicht versendet: '.$e->getMessage(), true, DLOG_ERR);
        return false;
    }
}

/**
 * Der Versand selbst — meldet Fehler, damit der Aufrufer sie protokollieren kann
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung
 * @return bool true wenn versendet
 * @throws ApiError EMAIL_NOT_CONFIGURED, INVOICE_NOT_FOUND, INVOICE_PDF_ERROR
 */
function shopSendInvoiceMailOrFail($db, int $arId): bool {
    $rechnung = $db->getOne(
        "SELECT ar.invnumber, ar.transdate, TRUNC(ar.amount, 2) AS amount,
                c.name, c.email, c.greeting,
                (SELECT name FROM currencies WHERE id = ar.currency_id) AS currency
           FROM ar JOIN customer c ON c.id = ar.customer_id
          WHERE ar.id = :ar_id",
        [':ar_id' => $arId]
    );

    if (!$rechnung) {
        throw new ApiError('INVOICE_NOT_FOUND', 'Diese Rechnung gibt es nicht');
    }
    if (empty($rechnung['email'])) {
        // Ohne Adresse ist nichts zu versenden. Kein Fehler: eine Bestellung
        // ohne E-Mail ist zulässig, sie bekommt eben keine Mail.
        return false;
    }

    $pdf = shopInvoicePdf($db, $arId);
    $mailer = shopMailer($db);

    $html = shopMailTemplate('invoice.de', [
        'name'      => $rechnung['name'],
        'greeting'  => $rechnung['greeting'],
        'invnumber' => $rechnung['invnumber'],
        'amount'    => $rechnung['amount'],
        'currency'  => $rechnung['currency'],
        'signatur'  => shopConfigValue($db, 'shop_base_url'),
    ]);

    $betreff = sprintf(
        shopConfigValue($db, 'shop_invoice_mail_subject', 'Ihre Rechnung (%s) vom %s'),
        $rechnung['invnumber'],
        date('d.m.Y')
    );

    try {
        $mailer['client']->send(
            $mailer['from'], $mailer['from_name'],
            [$rechnung['email']], $betreff, $html, '', [], [],
            [[
                'filename'     => 'Rechnung_'.$rechnung['invnumber'].'.pdf',
                'content_type' => 'application/pdf',
                'content'      => (string)file_get_contents($pdf['path']),
            ]]
        );
        return true;
    } catch (Exception $e) {
        writeLog('[SHOP] Rechnungsmail fehlgeschlagen: '.$e->getMessage(), true, DLOG_ERR);
        return false;
    } finally {
        @unlink($pdf['path']);
    }
}

/**
 * Verschickt eine Anfrage aus dem Kontaktformular an den Betreiber
 *
 * Absender bleibt die Firmenadresse, der Kunde steht in Antwort-An: sonst
 * verwerfen die meisten Empfänger die Mail, weil der Absender nicht zur
 * versendenden Domain passt. Die Bridge setzte den Kunden als Absender.
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $daten name, email, phone, term (Nachricht)
 * @return bool true wenn versendet
 * @throws ApiError EMAIL_NOT_CONFIGURED, MISSING_MESSAGE
 */
function shopSendContactMail($db, array $daten): bool {
    $nachricht = trim((string)($daten['term'] ?? ''));
    $absender  = trim((string)($daten['email'] ?? ''));

    if ('' === $nachricht) {
        throw new ApiError('MISSING_MESSAGE', 'Die Nachricht ist leer');
    }
    if (!filter_var($absender, FILTER_VALIDATE_EMAIL)) {
        throw new ApiError('INVALID_EMAIL', 'Die Absenderadresse ist unbrauchbar');
    }

    $empfaenger = shopConfigValue($db, 'shop_withdrawal_mail_to');
    if ('' === $empfaenger) {
        throw new ApiError('SHOP_CONFIG_MISSING', "Die Shop-Einstellung '".shopConfigLabel('shop_withdrawal_mail_to')."' ist nicht gesetzt");
    }

    $mailer = shopMailer($db);
    $html = shopMailTemplate('contact.de', [
        'name'      => (string)($daten['name'] ?? ''),
        'email'     => $absender,
        'phone'     => (string)($daten['phone'] ?? ''),
        'nachricht' => $nachricht,
    ]);

    try {
        $mailer['client']->send(
            $mailer['from'], $mailer['from_name'],
            [$empfaenger], 'Anfrage über das Kontaktformular', $html
        );
        return true;
    } catch (Exception $e) {
        writeLog('[SHOP] Kontaktmail fehlgeschlagen: '.$e->getMessage(), true, DLOG_ERR);
        return false;
    }
}

/**
 * Verschickt die beiden Widerrufsmails
 *
 * An den Kunden eine Eingangsbestätigung — neutral, ohne Aussage über die
 * Wirksamkeit (§ 356a BGB), und an den Betreiber die Angaben zur Bearbeitung.
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $widerruf Zeile aus withdrawals_hugoshop
 * @return array{customer: bool, operator: bool}
 */
function shopSendWithdrawalMails($db, array $widerruf): array {
    $ergebnis = ['customer' => false, 'operator' => false];

    try {
        $mailer = shopMailer($db);
    } catch (ApiError $e) {
        writeLog('[SHOP] Widerrufsmails nicht versendet: '.$e->getMessage(), true, DLOG_WRN);
        return $ergebnis;
    }

    $werte = [
        'name'        => $widerruf['name'],
        'ordernumber' => $widerruf['ordernumber'],
        'email'       => $widerruf['email'],
        'reason'      => $widerruf['reason'],
        'eingegangen' => $widerruf['itime'],
        'remote_addr' => $widerruf['remote_addr'] ?? '',
        'user_agent'  => $widerruf['user_agent'] ?? '',
    ];

    if (!empty($widerruf['email'])) {
        try {
            $mailer['client']->send(
                $mailer['from'], $mailer['from_name'], [$widerruf['email']],
                sprintf('Eingangsbestätigung Ihres Widerrufs – Bestellung %s', $widerruf['ordernumber']),
                shopMailTemplate('withdrawal-customer.de', $werte)
            );
            $ergebnis['customer'] = true;
        } catch (Exception $e) {
            writeLog('[SHOP] Widerruf: Kundenmail fehlgeschlagen: '.$e->getMessage(), true, DLOG_ERR);
        }
    }

    $betreiber = shopConfigValue($db, 'shop_withdrawal_mail_to');
    if ('' !== $betreiber) {
        try {
            $mailer['client']->send(
                $mailer['from'], $mailer['from_name'], [$betreiber],
                sprintf('Widerruf eingegangen – Bestellung %s', $widerruf['ordernumber']),
                shopMailTemplate('withdrawal-operator.de', $werte)
            );
            $ergebnis['operator'] = true;
        } catch (Exception $e) {
            writeLog('[SHOP] Widerruf: Betreibermail fehlgeschlagen: '.$e->getMessage(), true, DLOG_ERR);
        }
    }

    return $ergebnis;
}
