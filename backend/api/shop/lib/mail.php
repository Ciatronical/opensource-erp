<?php
// backend/api/shop/lib/mail.php
//
// Mails des Shops: Rechnung, Lieferstatus, Widerruf. Versand und Vorlagen-
// mechanismus gehören dem Kundenportal (backend/api/portal/lib/mail.php:
// portalMailer, portalMailTemplate); die Vorlagen des Shops liegen unter
// ../templates/ und sind dort angemeldet (lib/portal_hooks.php). Das
// Kontaktformular ist Sache des Kerns.
//
// Signatur und Betreff der Rechnungsmail gehören zum HugoShop
// (dev/shop-mehrere-kanaele.md): die Rechnungsmail nimmt den Kanal ihres
// Rechnungslinks. Die Adresse der Rechnungsseite kommt von der Webseite des
// Kanals (websiteInvoicePageUrl).

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

    $pdf = invoicePdf($db, $arId);
    $mailer = portalMailer($db);

    $html = portalMailTemplate('invoice.de', [
        'name'      => $rechnung['name'],
        'greeting'  => $rechnung['greeting'],
        'invnumber' => $rechnung['invnumber'],
        'amount'    => $rechnung['amount'],
        'currency'  => $rechnung['currency'],
        'signatur'  => websiteValue($db, shopWebsiteOfChannel($db, $kanal), 'base_url'),
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
        $website = shopWebsiteOfChannel($db, (int)$zeile['channel_id']);
        $mailer = portalMailer($db);
        $mailer['client']->send(
            $mailer['from'], $mailer['from_name'], [$zeile['email']],
            sprintf('Ihre Bestellung %s: %s', $zeile['invnumber'], shopDeliveryStatusText($status)),
            portalMailTemplate('delivery-status.de', [
                'name'      => $zeile['name'],
                'invnumber' => $zeile['invnumber'],
                'status'    => $status,
                'text'      => shopDeliveryStatusText($status),
                'link'      => websiteInvoicePageUrl($db, $website, (string)$zeile['uuid']),
                'signatur'  => websiteValue($db, $website, 'base_url'),
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
        $mailer = portalMailer($db);
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
                portalMailTemplate('withdrawal-customer.de', $werte)
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
                portalMailTemplate('withdrawal-operator.de', $werte)
            );
            $ergebnis['operator'] = true;
        } catch (Exception $e) {
            writeLog('[SHOP] Widerruf: Betreibermail fehlgeschlagen: '.$e->getMessage(), true, DLOG_ERR);
        }
    }

    return $ergebnis;
}
