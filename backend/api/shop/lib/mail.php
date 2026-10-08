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
//
// Signatur, Betreff der Rechnungsmail und Empfänger von Kontakt- und
// Widerrufsmails gehören zum HugoShop (dev/shop-mehrere-kanaele.md): die
// Rechnungsmail nimmt den Kanal ihres Rechnungslinks, Kontakt und Widerruf den
// der Anfrage.

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
                (SELECT name FROM currencies WHERE id = ar.currency_id) AS currency,
                (SELECT al.channel_id FROM ar_link_hugoshop al WHERE al.ar_id = ar.id LIMIT 1) AS kanal
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

    // Ohne Rechnungslink (keine Shop-Rechnung) gibt es keinen Kanal: dann
    // ohne Signatur und mit dem vorgegebenen Betreff
    $kanal = (int)($rechnung['kanal'] ?? 0);

    $pdf = shopInvoicePdf($db, $arId);
    $mailer = shopMailer($db);

    $html = shopMailTemplate('invoice.de', [
        'name'      => $rechnung['name'],
        'greeting'  => $rechnung['greeting'],
        'invnumber' => $rechnung['invnumber'],
        'amount'    => $rechnung['amount'],
        'currency'  => $rechnung['currency'],
        'signatur'  => shopChannelValue($db, $kanal, 'base_url'),
        // Bestellte Artikel mit Lieferbedingung, wie in einer Bestellbestätigung
        // (dev/shop-versand.md, Punkt 7)
        'positionen' => shopInvoiceDeliveryTerms($db, $arId),
    ]);

    $betreff = sprintf(
        shopChannelValue($db, $kanal, 'invoice_mail_subject', 'Ihre Rechnung (%s) vom %s'),
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
 * Lieferstatus in der Sprache der Mail
 *
 * Die Mailvorlagen sind deutsch (*.de.php); die Oberfläche übersetzt selbst.
 *
 * @param string $status Schlüssel aus ar_status_shop / shop_order_state
 * @return string
 */
function shopDeliveryStatusText(string $status): string {
    return [
        'open'               => 'Offen',
        'partially_shipped'  => 'Teilweise versandt',
        'shipped'            => 'Versandt',
        'partially_returned' => 'Teilretour',
        'returned'           => 'Retour',
        'cancelled'          => 'Abgebrochen',
    ][$status] ?? $status;
}

/**
 * Adresse der Rechnungsseite einer Bestellung, für Links in Mails
 *
 * Die Seite mit <shop-invoice> liegt auf der Webseite des HugoShops; ihr Pfad
 * ist dort frei wählbar und steht deshalb in den Einstellungen des Kanals
 * (invoice_page, Vorgabe /rechnung/ wie billing-page in <shop-checkout>). Der
 * Rechnungslink (ar_link_hugoshop.uuid) ersetzt die Anmeldung — so kommen auch
 * Gäste ohne Kundenkonto wieder auf die Seite.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop der Bestellung
 * @param string $arLink Kennung aus ar_link_hugoshop
 * @return string leer, wenn der Kanal keine Basisadresse hat
 */
function shopInvoicePageUrl($db, int $kanal, string $arLink): string {
    $basis = rtrim(trim(shopChannelValue($db, $kanal, 'base_url')), '/');
    if ('' === $basis || '' === $arLink) {
        return '';
    }
    $pfad = trim(shopChannelValue($db, $kanal, 'invoice_page'));
    if ('' === $pfad) {
        $pfad = '/rechnung/';
    }
    // Eine vollständige Adresse gilt, wie sie ist; ein Pfad hängt an der Basis
    $adresse = preg_match('~^https?://~i', $pfad) ? $pfad : $basis.'/'.ltrim($pfad, '/');
    return $adresse.(str_contains($adresse, '?') ? '&' : '?').'link='.rawurlencode($arLink);
}

/**
 * Meldet dem Kunden einen geänderten Lieferstatus (dev/shop-bestellstatus.md)
 *
 * Nur bei eingeschaltetem shop_delivery_status_mail, nur für
 * HugoShop-Bestellungen (eBay benachrichtigt seine Käufer selbst), nur mit
 * E-Mail-Adresse und nie für „Offen“. Jeder Status geht nur einmal hinaus:
 * gemerkt in ar_status_shop.delivery_notified.
 *
 * Wirft nicht: der Status ist bereits gespeichert, wenn diese Funktion
 * gerufen wird. Was schiefging, steht im Protokoll; der Läufer versucht es
 * innerhalb von zwei Tagen erneut (shopDeliveryStatusMailsPending).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $arId Rechnung der Bestellung
 * @return string off (abgeschaltet), skipped (nichts zu melden), sent, failed
 */
function shopSendDeliveryStatusMail($db, int $arId): string {
    if (!shopConfigBool($db, 'shop_delivery_status_mail')) {
        return 'off';
    }

    $zeile = $db->getOne(
        "SELECT ar.invnumber, c.name, c.email, al.channel_id, al.uuid,
                st.delivery_status, s.delivery_notified
           FROM ar_link_hugoshop al
           JOIN ar ON ar.id = al.ar_id
           JOIN customer c ON c.id = ar.customer_id
           LEFT JOIN ar_status_shop s ON s.ar_id = ar.id
          CROSS JOIN LATERAL shop_order_state(ar.id) st
          WHERE al.ar_id = :ar_id
          ORDER BY al.id
          LIMIT 1",
        [':ar_id' => $arId]
    );

    $status = (string)($zeile['delivery_status'] ?? 'open');
    if (!$zeile || 'open' === $status || $status === (string)($zeile['delivery_notified'] ?? 'open')
        || empty($zeile['email'])) {
        return 'skipped';
    }

    try {
        $mailer = shopMailer($db);
        $mailer['client']->send(
            $mailer['from'], $mailer['from_name'], [$zeile['email']],
            sprintf('Ihre Bestellung %s: %s', $zeile['invnumber'], shopDeliveryStatusText($status)),
            shopMailTemplate('delivery-status.de', [
                'name'      => $zeile['name'],
                'invnumber' => $zeile['invnumber'],
                'status'    => $status,
                'text'      => shopDeliveryStatusText($status),
                'link'      => shopInvoicePageUrl($db, (int)$zeile['channel_id'], (string)$zeile['uuid']),
                'signatur'  => shopChannelValue($db, (int)$zeile['channel_id'], 'base_url'),
            ])
        );
    } catch (Exception $e) {
        writeLog('[SHOP] Mail zum Lieferstatus von '.$zeile['invnumber'].' nicht versendet: '.$e->getMessage(), true, DLOG_ERR);
        return 'failed';
    }

    $db->execute(
        "INSERT INTO ar_status_shop (ar_id, delivery_notified, delivery_notified_mtime)
         VALUES (:ar_id, :status, now())
         ON CONFLICT (ar_id) DO UPDATE SET delivery_notified = EXCLUDED.delivery_notified,
                                           delivery_notified_mtime = now()",
        [':ar_id' => $arId, ':status' => $status]
    );
    return 'sent';
}

/**
 * Verschickt die ausstehenden Mails zum Lieferstatus (Läufer)
 *
 * Für Änderungen, die nicht über setShopOrderStatus kommen — vor allem das
 * DHL-Etikett — und für Mails, die dort scheiterten. Betrachtet werden nur
 * Bestellungen, deren Etikett, Storno oder Statusänderung höchstens zwei Tage
 * alt ist: sonst bekämen beim Einschalten des Schalters alle alten
 * Bestellungen eine Mail.
 *
 * @param object $db Company-Datenbankverbindung
 * @return int Zahl der verschickten Mails
 */
function shopDeliveryStatusMailsPending($db): int {
    if (!shopConfigBool($db, 'shop_delivery_status_mail')) {
        return 0;
    }

    $etikett = null !== ($db->getOne("SELECT to_regclass('dhl_shipments') AS t")['t'] ?? null)
        ? "OR EXISTS (SELECT 1 FROM dhl_shipments d
                       WHERE d.record_type = 'invoice' AND d.record_id = al.ar_id
                         AND d.created_at > now() - interval '2 days')"
        : '';

    $verschickt = 0;
    foreach ($db->getAll(
        "SELECT DISTINCT al.ar_id
           FROM ar_link_hugoshop al
           JOIN ar ON ar.id = al.ar_id
           LEFT JOIN ar_status_shop s ON s.ar_id = al.ar_id
          WHERE s.delivery_mtime > now() - interval '2 days'
             OR (COALESCE(ar.storno, false) AND ar.mtime > now() - interval '2 days')
             $etikett",
        []
    ) ?: [] as $zeile) {
        if ('sent' === shopSendDeliveryStatusMail($db, (int)$zeile['ar_id'])) {
            $verschickt++;
        }
    }
    return $verschickt;
}

/**
 * Verschickt eine Anfrage aus dem Kontaktformular an den Betreiber
 *
 * Absender bleibt die Firmenadresse, der Kunde steht in Antwort-An: sonst
 * verwerfen die meisten Empfänger die Mail, weil der Absender nicht zur
 * versendenden Domain passt. Die Bridge setzte den Kunden als Absender.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop der Anfrage — sein Empfänger (withdrawal_mail_to)
 * @param array $daten name, email, phone, term (Nachricht)
 * @return bool true wenn versendet
 * @throws ApiError EMAIL_NOT_CONFIGURED, MISSING_MESSAGE, SHOP_CONFIG_MISSING
 */
function shopSendContactMail($db, int $kanal, array $daten): bool {
    $nachricht = trim((string)($daten['term'] ?? ''));
    $absender  = trim((string)($daten['email'] ?? ''));

    if ('' === $nachricht) {
        throw new ApiError('MISSING_MESSAGE', 'Die Nachricht ist leer');
    }
    if (!filter_var($absender, FILTER_VALIDATE_EMAIL)) {
        throw new ApiError('INVALID_EMAIL', 'Die Absenderadresse ist unbrauchbar');
    }

    $empfaenger = shopChannelRequire($db, $kanal, 'withdrawal_mail_to');

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
 * @param array $widerruf Zeile aus withdrawals_hugoshop, mit channel_id
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

    $betreiber = shopChannelValue($db, (int)($widerruf['channel_id'] ?? 0), 'withdrawal_mail_to');
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
