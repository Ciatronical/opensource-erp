<?php
// backend/api/shop/lib/payment.php
//
// Zahlung über PayPal. Portiert aus payment.paypal.php der Bridge; die
// Zugangsdaten kommen jetzt aus den Shop-Einstellungen statt aus passwd.php.
//
// ABLAUF
//   paymentBegin   legt bei PayPal eine Bestellung an und liefert die Adresse,
//                  zu der der Kunde geschickt wird
//   (der Kunde bezahlt bei PayPal)
//   paymentEnd     zieht den Betrag ein, legt die Rechnung an und schickt sie
//
// Der Rückweg von PayPal kommt ohne Kontext-Cookie an — PayPal reicht die
// Sitzungskennung als reference_id durch. Deshalb bekommt jede Funktion hier
// die Kennung übergeben, statt sie aus dem Cookie zu lesen.
//
// SCHWEBENDE ZAHLUNGEN
// Lässt der Betreiber verzögert abrechnende Zahlungsarten zu (Einstellung
// UNRESTRICTED), meldet PayPal die Buchung als PENDING. Die Rechnung entsteht
// trotzdem, der Kunde sieht auf der Rechnungsseite einen Hinweis statt der
// Bankverbindung — ihn zur Überweisung aufzufordern wäre die Bitte, ein
// zweites Mal zu zahlen. Entschieden wird der Vorgang später über
// paymentsReconcile() aus dem Admin-Panel.

define('SHOP_PAYPAL_LIVE',    'https://api-m.paypal.com');
define('SHOP_PAYPAL_SANDBOX', 'https://api-m.sandbox.paypal.com');

/**
 * Fehler im Zahlungsverkehr
 *
 * Trägt die Antwort von PayPal mit, damit im Log steht, woran es lag.
 */
class ShopPaymentError extends ApiError {
    protected $daten = null;

    public function __construct($id, $message, $daten = null) {
        parent::__construct($id, $message);
        $this->daten = $daten;
    }

    public function getDaten() {
        return $this->daten;
    }
}

/**
 * Adresse der PayPal-Schnittstelle
 *
 * @param object $db Company-Datenbankverbindung
 * @return string
 */
function paypalBaseUrl($db): string {
    return shopConfigBool($db, 'shop_paypal_sandbox', true) ? SHOP_PAYPAL_SANDBOX : SHOP_PAYPAL_LIVE;
}

/**
 * Kopfzeile, mit der PayPal einen Fehler erzwingt statt den Aufruf auszuführen
 *
 * Für Fehlertests: PayPal beantwortet einen Aufruf auf Wunsch mit einem
 * bestimmten Fehler. Gesteuert wird das über die Einstellung
 * shop_paypal_mock_response, zum Beispiel
 *
 *     capture:TRANSACTION_REFUSED
 *
 * JEDER CODE GEHÖRT ZU EINEM AUFRUF, und die Vorsilbe sagt zu welchem:
 *
 *   create:   Bestellung erstellen — PERMISSION_DENIED, INTERNAL_SERVER_ERROR,
 *             PAYEE_ACCOUNT_RESTRICTED, INVALID_PARAMETER_VALUE
 *   capture:  Zahlung erfassen — TRANSACTION_REFUSED, INSTRUMENT_DECLINED,
 *             ORDER_NOT_APPROVED, ORDER_ALREADY_CAPTURED
 *   read:     Bestellung lesen — RESOURCE_NOT_FOUND
 *
 * Ohne Vorsilbe gilt der Code für alle drei. Da das Erstellen zuerst kommt,
 * scheitert der Kauf dann schon dort — wer den Fehler beim Erfassen sehen
 * will, braucht capture:. Ein vollständiges JSON
 * ({"mock_application_codes":"…"}) geht auch und wird nie als Vorsilbe
 * missverstanden.
 *
 * Eine schwebende Buchung (PENDING) lässt sich damit nicht erzeugen: der
 * Katalog besteht aus Fehlern, PENDING ist eine erfolgreiche Antwort mit
 * zurückgehaltener Buchung.
 *
 * Im Echtbetrieb entsteht die Kopfzeile gar nicht erst — das ist die
 * eigentliche Absicherung, statt sie später irgendwo wieder herauszunehmen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $aufruf 'create', 'capture' oder 'read'
 * @return array Kopfzeilen, leer wenn kein Fehler erzwungen wird
 */
function paypalMockHeader($db, string $aufruf): array {
    if (!shopConfigBool($db, 'shop_paypal_sandbox', true)) {
        return [];
    }

    $wert = trim(shopConfigValue($db, 'shop_paypal_mock_response'));
    if ('' === $wert) {
        return [];
    }

    // Ein vollständiges JSON fängt mit { an und enthält selbst Doppelpunkte —
    // das darf nicht als Vorsilbe durchgehen.
    if ('{' !== substr($wert, 0, 1) && false !== strpos($wert, ':')) {
        [$ziel, $rest] = explode(':', $wert, 2);
        if (in_array($ziel, ['create', 'capture', 'read'], true)) {
            if ($ziel !== $aufruf) {
                return [];
            }
            $wert = trim($rest);
        }
    }

    // Der blosse Code genügt; das JSON drumherum baut sich von selbst.
    if ('{' !== substr($wert, 0, 1)) {
        $wert = '{"mock_application_codes":"'.$wert.'"}';
    }

    // Muss ins Protokoll: eine erzwungene Ablehnung sieht dort sonst genauso
    // aus wie eine echte, und man sucht den Fehler an der falschen Stelle.
    writeLog('[SHOP] PayPal-Fehlertest aktiv beim Aufruf "'.$aufruf.'" ('.$wert
             .') — die folgende Antwort ist erzwungen, kein echter Fehler.', true, DLOG_WRN);

    return ['PayPal-Mock-Response: '.$wert];
}

/**
 * Ruft die PayPal-Schnittstelle auf
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $pfad Pfad ab /v1 bzw. /v2
 * @param string $methode GET oder POST
 * @param array|string|null $rumpf Anfragedaten
 * @param array $kopfzeilen Zusätzliche Kopfzeilen
 * @param string|null $aufruf 'create', 'capture' oder 'read' — schaltet den
 *                            Fehlertest für diesen Aufruf frei
 * @return array{status: int, daten: array, roh: string}
 * @throws ShopPaymentError PAYMENT_UNREACHABLE
 */
function paypalRequest($db, string $pfad, string $methode = 'GET', $rumpf = null,
                       array $kopfzeilen = [], ?string $aufruf = null): array {
    $mock = null === $aufruf ? [] : paypalMockHeader($db, $aufruf);

    $ch = curl_init(paypalBaseUrl($db).$pfad);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ('POST' === $methode) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($rumpf) ? json_encode($rumpf) : (string)$rumpf);
    }
    $alleKopfzeilen = array_merge($kopfzeilen, $mock);
    if (!empty($alleKopfzeilen)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $alleKopfzeilen);
    }

    $roh = curl_exec($ch);
    if (curl_errno($ch)) {
        $meldung = curl_error($ch);
        curl_close($ch);
        throw new ShopPaymentError('PAYMENT_UNREACHABLE', 'PayPal nicht erreichbar: '.$meldung);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // HTTP 403 mit leerem Rumpf heisst bei PayPal: dieser Code gehört nicht zu
    // diesem Aufruf. Ohne den Hinweis steht im Protokoll nur eine leere
    // Antwort, und die trifft ebenso einen vorgeschalteten Proxy, eine
    // Zeitüberschreitung oder eine Drosselung.
    if (403 === $status && '' === trim((string)$roh) && !empty($mock)) {
        writeLog('[SHOP] PayPal antwortet mit 403 und leerem Rumpf. Der eingestellte '
                 .'Fehlercode gehört nicht zum Aufruf "'.$aufruf.'" — siehe die '
                 .'Vorsilben create:, capture: und read:.', true, DLOG_WRN);
    }

    return [
        'status' => $status,
        'daten'  => json_decode((string)$roh, true) ?: [],
        'roh'    => (string)$roh,
    ];
}

/**
 * Holt ein Zugangsmerkmal bei PayPal
 *
 * @param object $db Company-Datenbankverbindung
 * @return string
 * @throws ShopPaymentError, ApiError SHOP_CONFIG_MISSING
 */
function paypalAccessToken($db): string {
    $clientId = shopConfigRequire($db, 'shop_paypal_client_id');
    $secret   = shopConfigRequire($db, 'shop_paypal_secret');

    $antwort = paypalRequest($db, '/v1/oauth2/token', 'POST', 'grant_type=client_credentials', [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic '.base64_encode($clientId.':'.$secret),
    ]);

    if (empty($antwort['daten']['access_token'])) {
        throw new ShopPaymentError(
            'PAYMENT_NO_TOKEN',
            'PayPal hat kein Zugangsmerkmal geliefert (HTTP '.$antwort['status'].')',
            $antwort['daten']
        );
    }

    return (string)$antwort['daten']['access_token'];
}

/**
 * Legt bei PayPal eine Bestellung an und liefert die Adresse zur Freigabe
 *
 * Die Sitzungskennung geht als reference_id mit — sie ist der einzige Faden
 * zurück zum Warenkorb, wenn der Kunde von PayPal zurückkommt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param string $erfolgSeite Adresse der Rechnungsseite im Shop
 * @param string $abbruchSeite Adresse der Abbruchseite im Shop
 * @return array{approval_url: string, order_id: string}
 * @throws ShopPaymentError, ApiError CART_EMPTY
 */
function paymentBegin($db, string $uuid, string $erfolgSeite, string $abbruchSeite): array {
    $context    = shopContextCustomer($db, $uuid);
    $customerId = (int)$context['customer_id'];

    if (empty($context['cart_uuid'])) {
        throw new ApiError('CART_NOT_FOUND', 'Zu dieser Sitzung gibt es keinen Warenkorb');
    }

    cartApplyShipping($db, $context['cart_uuid'], $customerId);
    $korb = cartRead($db, $context['cart_uuid'], $customerId, true);

    // Ohne Betrag gibt es nichts zu bezahlen. Die Bridge schickte hier eine
    // Bestellung über 0,00 los und bekam von PayPal MISSING_REQUIRED_PARAMETER.
    if (empty($korb['positions']) || 0 >= (float)$korb['totalSum']) {
        throw new ApiError('CART_EMPTY', 'Der Warenkorb ist leer');
    }

    $rueckweg = rtrim(shopConfigRequire($db, 'shop_base_url'), '/').'/shop-api/';

    $antwort = paypalRequest($db, '/v2/checkout/orders', 'POST', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $uuid,
            'amount' => [
                'currency_code' => $korb['currency'],
                'value'         => number_format((float)$korb['totalSum'], 2, '.', ''),
            ],
        ]],
        'payment_source' => ['paypal' => ['experience_context' => [
            'payment_method_preference' => shopConfigValue($db, 'shop_paypal_payment_method_preference', 'IMMEDIATE_PAYMENT_REQUIRED'),
            'brand_name'   => shopConfigValue($db, 'shop_payment_account_owner'),
            'locale'       => 'de-DE',
            'landing_page' => 'LOGIN',
            'user_action'  => 'PAY_NOW',
            // failed: wohin, wenn die Zahlung zwar zurückkommt, aber scheitert.
            // Ohne dieses Ziel kannte der Rückweg nur die Erfolgsseite und
            // endete im Fehlerfall auf einer leeren Antwort.
            'return_url' => $rueckweg.'?action=endPayment&page='.rawurlencode($erfolgSeite)
                            .'&failed='.rawurlencode($abbruchSeite),
            'cancel_url' => $rueckweg.'?action=paymentCanceled&page='.rawurlencode($abbruchSeite),
        ]]],
    ], [
        'Content-Type: application/json',
        'Authorization: Bearer '.paypalAccessToken($db),
    ], 'create');

    if (($antwort['daten']['status'] ?? '') !== 'PAYER_ACTION_REQUIRED') {
        throw new ShopPaymentError(
            'PAYMENT_ORDER_FAILED',
            'PayPal hat die Bestellung nicht angenommen (HTTP '.$antwort['status'].')',
            $antwort['daten']
        );
    }

    foreach ($antwort['daten']['links'] ?? [] as $link) {
        if ('payer-action' === ($link['rel'] ?? '') || 'approve' === ($link['rel'] ?? '')) {
            return ['approval_url' => $link['href'], 'order_id' => $antwort['daten']['id'] ?? ''];
        }
    }

    throw new ShopPaymentError('PAYMENT_NO_APPROVAL_LINK',
                               'PayPal hat keine Adresse zur Freigabe geliefert', $antwort['daten']);
}

/**
 * Bewertet den Zahlungsstand einer PayPal-Bestellung
 *
 * Drei Zustände, und ein unbekannter zählt als gescheitert: was sich nicht
 * einordnen lässt, wird nicht als bezahlt behandelt.
 *
 * @param array $bestellung Antwort von PayPal
 * @return array{order_id, capture_id, payer_id, status, reason, settled, open, failed}
 */
function paypalPaymentState(array $bestellung): array {
    $buchung = $bestellung['purchase_units'][0]['payments']['captures'][0] ?? null;
    $status  = $buchung['status'] ?? null;

    return [
        'order_id'   => $bestellung['id'] ?? null,
        'capture_id' => $buchung['id'] ?? null,
        'payer_id'   => $bestellung['payer']['payer_id'] ?? null,
        'status'     => $status,
        'reason'     => $buchung['status_details']['reason'] ?? null,
        // Geld ist da
        'settled'    => 'COMPLETED' === $status,
        // Noch offen: Rechnung ja, Zahlungseingang nein, später abgleichen
        'open'       => 'PENDING' === $status,
        'failed'     => 'COMPLETED' !== $status && 'PENDING' !== $status,
    ];
}

/**
 * Zieht die Zahlung ein und legt die Rechnung an
 *
 * Läuft ohne Kontext-Cookie: die Sitzungskennung kommt als reference_id von
 * PayPal zurück.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $token Bestellkennung von PayPal (Parameter "token")
 * @return array{ar_link: string, invnumber: string, payment: array}
 * @throws ShopPaymentError, ApiError
 */
function paymentEnd($db, string $token): array {
    $antwort = paypalRequest($db, '/v2/checkout/orders/'.rawurlencode($token).'/capture', 'POST', '{}', [
        'Content-Type: application/json',
        'Authorization: Bearer '.paypalAccessToken($db),
    ], 'capture');

    $bestellung = $antwort['daten'];
    $zahlung    = paypalPaymentState($bestellung);

    // Weder gebucht noch unterwegs: der Warenkorb bleibt stehen. Die Bridge
    // sah hier auf $result['status'] — der meint aber die Bestellung und kann
    // auch bei abgelehnter Buchung COMPLETED sein.
    if ($zahlung['failed']) {
        throw new ShopPaymentError('PAYMENT_NOT_COMPLETED',
                                   'Die Zahlung wurde nicht abgeschlossen', $bestellung);
    }

    if ($zahlung['open']) {
        // Ab hier muss ein Mensch etwas nachhalten.
        writeLog('[SHOP] Zahlung schwebt: Bestellung '.$zahlung['order_id']
                 .', Buchung '.$zahlung['capture_id']
                 .', Grund '.($zahlung['reason'] ?: 'ohne Angabe')
                 .' — Rechnung wird ohne Zahlungseingang angelegt.', true, DLOG_WRN);
    }

    $uuid = (string)($bestellung['purchase_units'][0]['reference_id'] ?? '');
    if ('' === $uuid) {
        throw new ShopPaymentError('PAYMENT_NO_REFERENCE',
                                   'PayPal hat keine Sitzungskennung zurückgegeben', $bestellung);
    }

    $rechnung = createShopInvoice($db, $uuid, paymentShippingAddress($bestellung), $zahlung);
    shopSendInvoiceMail($db, (int)$rechnung['ar_id']);

    return [
        'ar_link'   => $rechnung['ar_link'],
        'invnumber' => $rechnung['invnumber'],
        'payment'   => $zahlung,
    ];
}

/**
 * Liest die Lieferanschrift aus der PayPal-Antwort
 *
 * PayPal liefert die Anschrift, die der Kunde dort hinterlegt hat. Fehlt sie,
 * geht die Lieferung an die Rechnungsadresse.
 *
 * @param array $bestellung Antwort von PayPal
 * @return array Adressfelder oder leeres Array
 */
function paymentShippingAddress(array $bestellung): array {
    $versand = $bestellung['purchase_units'][0]['shipping'] ?? null;
    if (empty($versand['address']['address_line_1'])) {
        return [];
    }

    return [
        'name'    => $versand['name']['full_name']            ?? '',
        'street'  => $versand['address']['address_line_1']    ?? '',
        'zipcode' => $versand['address']['postal_code']       ?? '',
        'city'    => $versand['address']['admin_area_2']      ?? '',
        'country' => $versand['address']['country_code']      ?? '',
        'email'   => $bestellung['payer']['email_address']    ?? '',
    ];
}

/**
 * Rechnungen, deren Zahlung bei PayPal noch schwebt
 *
 * Zeilen ohne Bestellkennung tauchen nicht auf — nachfragen ließe sich zu
 * ihnen ohnehin nichts.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $limit Höchstzahl
 * @return array
 */
function paymentsPending($db, int $limit = 100): array {
    return $db->getAll(
        "SELECT al.uuid, al.ar_id, al.paypal_order_id, al.payment_reason, al.payment_mtime,
                ar.invnumber, TRUNC(ar.amount, 2) AS amount,
                c.name AS customer
           FROM ar_link_hugoshop al
           JOIN ar ON ar.id = al.ar_id
           JOIN customer c ON c.id = ar.customer_id
          WHERE al.payment_status = 'PENDING' AND al.paypal_order_id IS NOT NULL
          ORDER BY al.payment_mtime
          LIMIT :limit",
        [':limit' => $limit]
    );
}

/**
 * Trägt einen nachgeschlagenen Zahlungsstand nach
 *
 * Schreibt ausschließlich nach ar_link_hugoshop. Gebucht wird nichts:
 * Zahlungseingänge kommen im ERP von Hand in die Bücher, für PayPal wie für
 * Überweisungen. Diese Funktion sagt nur, woran man ist.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $arLink Kennung aus ar_link_hugoshop
 * @param array $zahlung Ergebnis aus paypalPaymentState()
 * @return string 'bezahlt', 'offen' oder 'gescheitert'
 * @throws ApiError INVOICE_LINK_NOT_FOUND
 */
function paymentApplyState($db, string $arLink, array $zahlung): string {
    $zeile = $db->getOne(
        "UPDATE ar_link_hugoshop
            SET payment_status = :status, payment_reason = :reason,
                paypal_capture_id = COALESCE(:capture_id, paypal_capture_id),
                payment_mtime = NOW()
          WHERE uuid = :uuid
         RETURNING uuid",
        [
            ':status'     => $zahlung['status'],
            ':reason'     => $zahlung['reason'],
            ':capture_id' => $zahlung['capture_id'],
            ':uuid'       => $arLink,
        ]
    );

    if (!$zeile) {
        throw new ApiError('INVOICE_LINK_NOT_FOUND', 'Zu diesem Link gibt es keine Bestellung');
    }

    if (!empty($zahlung['settled'])) { return 'bezahlt'; }
    if (!empty($zahlung['failed']))  { return 'gescheitert'; }
    return 'offen';
}

/**
 * Fragt alle schwebenden Zahlungen bei PayPal nach und trägt das Ergebnis ein
 *
 * Vorgänge, die sich nicht abfragen lassen, bleiben unangetastet auf PENDING
 * — geraten wird nichts.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $limit Höchstzahl der Vorgänge
 * @return array Je Vorgang Rechnungsnummer und Ergebnis
 */
function paymentsReconcile($db, int $limit = 100): array {
    $ergebnisse = [];

    foreach (paymentsPending($db, $limit) as $vorgang) {
        try {
            $antwort = paypalRequest($db, '/v2/checkout/orders/'.rawurlencode($vorgang['paypal_order_id']), 'GET', null, [
                'Content-Type: application/json',
                'Authorization: Bearer '.paypalAccessToken($db),
            ], 'read');

            if (200 !== $antwort['status']) {
                $ergebnisse[] = [
                    'invnumber' => $vorgang['invnumber'],
                    'result'    => 'nicht abfragbar',
                    'detail'    => 'HTTP '.$antwort['status'],
                ];
                continue;
            }

            $ergebnisse[] = [
                'invnumber' => $vorgang['invnumber'],
                'result'    => paymentApplyState($db, $vorgang['uuid'], paypalPaymentState($antwort['daten'])),
            ];
        } catch (Exception $e) {
            writeLog('[SHOP] Abgleich '.$vorgang['invnumber'].' fehlgeschlagen: '.$e->getMessage(), true, DLOG_WRN);
            $ergebnisse[] = [
                'invnumber' => $vorgang['invnumber'],
                'result'    => 'nicht abfragbar',
                'detail'    => $e->getMessage(),
            ];
        }
    }

    return $ergebnisse;
}
