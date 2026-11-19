<?php
// backend/api/shop/lib/payment.php
//
// Zahlung über PayPal. Portiert aus payment.paypal.php der Bridge; die
// Zugangsdaten kommen jetzt aus den Shop-Einstellungen statt aus passwd.php.
//
// Je HugoShop ein eigener PayPal-Zugang (dev/shop-mehrere-kanaele.md, M2):
// Zugangsdaten, Test- oder Echtbetrieb und Rücksprungadresse stehen in den
// Einstellungen des Kanals (paypal_*, base_url). Jede Funktion bekommt den
// Kanal deshalb übergeben.
//
// ABLAUF (PayPal Express, 2026-11-19)
//   paymentBegin   legt bei PayPal eine Bestellung an und liefert die Adresse,
//                  zu der der Kunde geschickt wird. Die Lieferadresse wählt
//                  der Kunde bei PayPal (GET_FROM_FILE); kennt der Shop schon
//                  eine, geht sie als Vorschlag mit.
//   (der Kunde gibt die Zahlung bei PayPal frei)
//   paymentEnd     liest die Bestellung, übernimmt Kunde und Lieferadresse
//                  aus PayPal (ein Besucher ohne Konto wird als Gast
//                  angelegt), rechnet den Warenkorb für dieses Land und
//                  zieht ein, wenn der Betrag dem freigegebenen entspricht.
//                  Weicht er ab (anderes Lieferland), bestätigt der Kunde den
//                  neuen Betrag in der Kasse — erst dann wird die Bestellung
//                  angepasst und eingezogen (paymentEnd mit $bestaetigt).
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
 * @param int $kanal HugoShop
 * @return string
 */
function paypalBaseUrl($db, int $kanal): string {
    return shopChannelBool($db, $kanal, 'paypal_sandbox', true) ? SHOP_PAYPAL_SANDBOX : SHOP_PAYPAL_LIVE;
}

/**
 * Kopfzeile, mit der PayPal einen Fehler erzwingt statt den Aufruf auszuführen
 *
 * Für Fehlertests: PayPal beantwortet einen Aufruf auf Wunsch mit einem
 * bestimmten Fehler. Gesteuert wird das über die Einstellung
 * paypal_mock_response des HugoShops, zum Beispiel
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
 * @param int $kanal HugoShop
 * @param string $aufruf 'create', 'capture' oder 'read'
 * @return array Kopfzeilen, leer wenn kein Fehler erzwungen wird
 */
function paypalMockHeader($db, int $kanal, string $aufruf): array {
    if (!shopChannelBool($db, $kanal, 'paypal_sandbox', true)) {
        return [];
    }

    $wert = trim(shopChannelValue($db, $kanal, 'paypal_mock_response'));
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
 * @param int $kanal HugoShop, dessen PayPal-Zugang gilt
 * @param string $pfad Pfad ab /v1 bzw. /v2
 * @param string $methode GET, POST oder PATCH
 * @param array|string|null $rumpf Anfragedaten
 * @param array $kopfzeilen Zusätzliche Kopfzeilen
 * @param string|null $aufruf 'create', 'capture' oder 'read' — schaltet den
 *                            Fehlertest für diesen Aufruf frei
 * @return array{status: int, daten: array, roh: string}
 * @throws ShopPaymentError PAYMENT_UNREACHABLE
 */
function paypalRequest($db, int $kanal, string $pfad, string $methode = 'GET', $rumpf = null,
                       array $kopfzeilen = [], ?string $aufruf = null): array {
    $mock = null === $aufruf ? [] : paypalMockHeader($db, $kanal, $aufruf);

    $ch = curl_init(paypalBaseUrl($db, $kanal).$pfad);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if ('POST' === $methode) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($rumpf) ? json_encode($rumpf) : (string)$rumpf);
    } elseif ('PATCH' === $methode) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
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
 * @param int $kanal HugoShop
 * @return string
 * @throws ShopPaymentError, ApiError SHOP_CONFIG_MISSING
 */
function paypalAccessToken($db, int $kanal): string {
    // PayPal vergibt fuer Test- und Echtbetrieb getrennte Zugangsdaten. Beide
    // stehen nebeneinander in den Einstellungen; welches Paar gilt, entscheidet
    // derselbe Schalter, der auch die Adresse bestimmt — so koennen die beiden
    // nicht auseinanderlaufen.
    $vorsilbe = shopChannelBool($db, $kanal, 'paypal_sandbox', true)
        ? 'paypal_sandbox_' : 'paypal_live_';

    $clientId = shopChannelRequire($db, $kanal, $vorsilbe.'client_id');
    $secret   = shopChannelRequire($db, $kanal, $vorsilbe.'secret');

    $antwort = paypalRequest($db, $kanal, '/v1/oauth2/token', 'POST', 'grant_type=client_credentials', [
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
 * Der Name, den PayPal dem Kunden als Empfänger zeigt
 *
 * Steht im Kopf der PayPal-Seite und in „Abbrechen und zurück zu …“.
 * Einstellung „Name bei PayPal“ (shop_paypal_brand_name); leer: der Firmenname
 * aus den Firmendaten. Bis 30.10.2026 ging hier der Kontoinhaber der
 * Bankverbindung mit — bei einer Person als Inhaber stand dann deren Name
 * statt des Shops (autoprofis24.de). PayPal nimmt höchstens 127 Zeichen.
 *
 * @param object $db Company-Datenbankverbindung
 * @return string leer, wenn weder Einstellung noch Firmenname gesetzt sind
 */
function paymentBrandName($db): string {
    $name = shopConfigValue($db, 'shop_paypal_brand_name');
    if ('' === $name) {
        $zeile = $db->getOne("SELECT company FROM defaults LIMIT 1", []);
        $name  = trim((string)($zeile['company'] ?? ''));
    }
    return mb_substr($name, 0, 127);
}

/**
 * Legt bei PayPal eine Bestellung an und liefert die Adresse zur Freigabe
 *
 * Die Sitzungskennung geht als reference_id mit — sie ist der einzige Faden
 * zurück zum Warenkorb, wenn der Kunde von PayPal zurückkommt.
 *
 * PayPal Express: Die Lieferadresse legt der Kunde bei PayPal fest
 * (GET_FROM_FILE). Ein Kunde in der Sitzung ist nicht nötig — ein Besucher
 * ohne Konto wird auf dem Rückweg aus den Angaben von PayPal als Gast
 * angelegt (paymentEnd). Gibt es einen Kunden, geht die in der Kasse gewählte
 * Adresse als Vorschlag mit. Gerechnet wird mit dem Land dieser Adresse, ohne
 * Kunden mit dem im Warenkorb gewählten Lieferland; nennt PayPal ein anderes
 * Land, bestätigt der Kunde den neuen Betrag vor dem Einzug in der Kasse.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param string $erfolgSeite Adresse der Rechnungsseite im Shop
 * @param string $abbruchSeite Adresse der Abbruchseite im Shop
 * @param array $lieferadresse aus der Kasse (shopDeliveryAddress): ['shipto_id' => …] oder [] = Rechnungsadresse
 * @param string $kasseSeite Adresse der Kasse: dorthin bei geändertem Betrag oder Fehler
 * @return array{approval_url: string, order_id: string}
 * @throws ShopPaymentError, ApiError CART_EMPTY, CART_NOT_OFFERED, SHIPPING_*
 */
function paymentBegin($db, string $uuid, string $erfolgSeite, string $abbruchSeite, array $lieferadresse = [], string $kasseSeite = ''): array {
    $context    = portalContextRequire($db, $uuid);
    $customerId = empty($context['customer_id']) ? null : (int)$context['customer_id'];
    if (null !== $customerId) {
        // prüft die Sperre des Zugangs
        portalContextCustomer($db, $uuid);
    }
    // HugoShop der Webseite: sein PayPal-Zugang; die Rücksprungadresse ist
    // die der Webseite
    $website    = (int)$context['website_id'];
    $kanal      = shopChannelOfWebsite($db, $website);

    if (empty($context['cart_uuid'])) {
        throw new ApiError('CART_NOT_FOUND', 'Zu dieser Sitzung gibt es keinen Warenkorb');
    }

    // Der Versand wird hier nur gerechnet, nicht in den Korb gelegt: als
    // Position entsteht er erst mit der Rechnung (createShopInvoice). Mit
    // Kunden gilt die Lieferadresse aus der Kasse, ohne Kunden das im
    // Warenkorb gewählte Lieferland (cartShippingCountry).
    $adresse = null === $customerId ? null : paymentDeliveryAddress($db, $customerId, $lieferadresse);
    $korb = cartRead($db, $context['cart_uuid'], $customerId, true, null === $adresse ? null : $adresse['land']);
    $betrag = paymentCartAmount($korb);

    // Ohne Betrag gibt es nichts zu bezahlen. Die Bridge schickte hier eine
    // Bestellung über 0,00 los und bekam von PayPal MISSING_REQUIRED_PARAMETER.
    // positions enthält nur Ware — ein Korb mit nichts als Versand ist leer.
    if (empty($korb['positions']) || 0 >= $betrag) {
        throw new ApiError('CART_EMPTY', 'Der Warenkorb ist leer');
    }
    // Vor der Zahlung, nicht danach (O2): nach der Zahlung muss die Rechnung
    // entstehen, gleich was im Warenkorb liegt
    cartRequireOffered($korb);
    cartRequireShipping($korb);

    $rueckweg = rtrim(websiteRequire($db, $website, 'base_url'), '/').'/portal-api/';

    // Was PayPal dem Kunden zeigt. Ohne Namen bleibt brand_name weg — PayPal
    // zeigt dann den Namen des PayPal-Kontos.
    $erlebnis = [
        'payment_method_preference' => shopChannelValue($db, $kanal, 'paypal_payment_method_preference', 'IMMEDIATE_PAYMENT_REQUIRED'),
        'locale'       => 'de-DE',
        'landing_page' => 'LOGIN',
        'user_action'  => 'PAY_NOW',
        // Express: der Kunde wählt die Lieferadresse bei PayPal. Ob der
        // Betrag danach noch stimmt, prüft der Rückweg (paymentEnd).
        'shipping_preference' => 'GET_FROM_FILE',
        // failed/checkout: wohin, wenn die Zahlung zwar zurückkommt, aber
        // scheitert oder der Betrag neu bestätigt werden muss.
        'return_url' => $rueckweg.'?action=endPayment&page='.rawurlencode($erfolgSeite)
                        .'&failed='.rawurlencode($abbruchSeite)
                        .'&checkout='.rawurlencode($kasseSeite),
        'cancel_url' => $rueckweg.'?action=paymentCanceled&page='.rawurlencode($abbruchSeite),
    ];
    $marke = paymentBrandName($db);
    if ('' !== $marke) {
        $erlebnis['brand_name'] = $marke;
    }

    $einheit = [
        'reference_id' => $uuid,
        // Die vorgeschlagene Lieferadresse; 0 = Rechnungsadresse oder keine
        'custom_id'    => (string)(int)($adresse['shipto_id'] ?? 0),
        'amount' => [
            'currency_code' => $korb['currency'],
            'value'         => number_format($betrag, 2, '.', ''),
        ],
    ];
    // Vorschlag nur mit vollständiger Anschrift — eine halbe lehnt PayPal ab
    if (null !== $adresse && paymentAddressComplete($adresse['paypal'])) {
        $einheit['shipping'] = $adresse['paypal'];
    }

    $antwort = paypalRequest($db, $kanal, '/v2/checkout/orders', 'POST', [
        'intent' => 'CAPTURE',
        'purchase_units' => [$einheit],
        'payment_source' => ['paypal' => ['experience_context' => $erlebnis]],
    ], [
        'Content-Type: application/json',
        'Authorization: Bearer '.paypalAccessToken($db, $kanal),
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
 * Der Betrag, den der Kunde für diesen Warenkorb zahlt
 *
 * @param array $korb Ergebnis von cartRead()
 * @return float
 */
function paymentCartAmount(array $korb): float {
    return (float)(!empty($korb['incShippingCosts']) ? $korb['totalSumIncShipping'] : $korb['totalSum']);
}

/**
 * Ist die Anschrift vollständig genug, um sie PayPal vorzuschlagen?
 *
 * @param array $paypal Versandadresse in der Form von PayPal (paymentDeliveryAddress)
 * @return bool
 */
function paymentAddressComplete(array $paypal): bool {
    $adresse = $paypal['address'] ?? [];
    return '' !== trim((string)($paypal['name']['full_name'] ?? ''))
        && '' !== trim((string)($adresse['address_line_1'] ?? ''))
        && '' !== trim((string)($adresse['admin_area_2'] ?? ''))
        && '' !== trim((string)($adresse['country_code'] ?? ''));
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
 * Liest eine Bestellung bei PayPal
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop, dessen PayPal-Zugang gilt
 * @param string $token Bestellkennung von PayPal
 * @return array Bestellung, wie PayPal sie liefert
 * @throws ShopPaymentError PAYMENT_ORDER_NOT_FOUND
 */
function paypalOrderRead($db, int $kanal, string $token): array {
    $antwort = paypalRequest($db, $kanal, '/v2/checkout/orders/'.rawurlencode($token), 'GET', null, [
        'Content-Type: application/json',
        'Authorization: Bearer '.paypalAccessToken($db, $kanal),
    ], 'read');

    if (200 !== $antwort['status'] || empty($antwort['daten']['id'])) {
        throw new ShopPaymentError('PAYMENT_ORDER_NOT_FOUND',
                                   'PayPal kennt diese Bestellung nicht (HTTP '.$antwort['status'].')', $antwort['daten']);
    }
    return $antwort['daten'];
}

/**
 * Kunde und Lieferadresse, wie PayPal sie zur Bestellung nennt
 *
 * @param array $bestellung Antwort von PayPal (paypalOrderRead)
 * @return array{name: string, street: string, zipcode: string, city: string, country_code: string, email: string}
 */
function paymentExpressAddress(array $bestellung): array {
    $versand = $bestellung['purchase_units'][0]['shipping'] ?? [];
    $adresse = $versand['address'] ?? [];
    $zahler  = $bestellung['payer'] ?? [];
    $konto   = $bestellung['payment_source']['paypal'] ?? [];

    $name = trim((string)($versand['name']['full_name'] ?? ''));
    if ('' === $name) {
        $person = $zahler['name'] ?? $konto['name'] ?? [];
        $name = trim(($person['given_name'] ?? '').' '.($person['surname'] ?? ''));
    }

    return [
        'name'         => $name,
        'street'       => trim(trim((string)($adresse['address_line_1'] ?? '')).' '.trim((string)($adresse['address_line_2'] ?? ''))),
        'zipcode'      => trim((string)($adresse['postal_code'] ?? '')),
        'city'         => trim((string)($adresse['admin_area_2'] ?? '')),
        'country_code' => strtoupper(trim((string)($adresse['country_code'] ?? ''))),
        'email'        => trim((string)($zahler['email_address'] ?? $konto['email_address'] ?? '')),
    ];
}

/**
 * Name eines Landes für die Anschrift
 *
 * PayPal nennt nur den Code, in customer.country und shipto.shiptocountry
 * steht Freitext. Der Name kommt aus den ICU-Daten (wie die Länderliste des
 * Shops); verwendet wird er nur, wenn der Shop ihn demselben Land zuordnet
 * (shop_country_code) — sonst bleibt der Code stehen, den der Shop kennt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $code ISO-Code
 * @return string
 */
function paymentCountryName($db, string $code): string {
    $name = class_exists('Locale') ? (string)Locale::getDisplayRegion('-'.$code, 'de') : '';
    if ('' === $name || $name === $code) {
        return $code;
    }
    $zeile = $db->getOne(
        "SELECT shop_country_code(:name) = :code AS passt",
        [':name' => $name, ':code' => $code]
    );
    return in_array($zeile['passt'] ?? false, [true, 't', 1, '1'], true) ? $name : $code;
}

/**
 * Übernimmt Kunde und Lieferadresse aus der PayPal-Bestellung
 *
 * Ohne Kunden in der Sitzung entsteht ein Gast mit der Anschrift von PayPal
 * als Rechnungsadresse. Mit Kunden wird die Anschrift seine Lieferadresse:
 * die Rechnungsadresse, wenn sie ihr gleicht, eine gespeicherte gleiche
 * Lieferadresse, sonst eine neue. Ein zweiter Aufruf mit derselben Bestellung
 * findet dieselbe Adresse wieder und legt nichts doppelt an.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Sitzungskennung (reference_id der Bestellung)
 * @param array $bestellung Antwort von PayPal (paypalOrderRead)
 * @return array Lieferadresse für createShopInvoice: ['shipto_id' => int] oder [] = Rechnungsadresse
 * @throws ApiError PAYMENT_ADDRESS_MISSING, ACCOUNT_EXISTS, PORTAL_CONTEXT_ERROR
 */
function paymentExpressCustomer($db, string $uuid, array $bestellung): array {
    $context = portalContextRequire($db, $uuid);
    $adresse = paymentExpressAddress($bestellung);

    if ('' === $adresse['name'] || '' === $adresse['street'] || '' === $adresse['country_code']) {
        throw new ShopPaymentError('PAYMENT_ADDRESS_MISSING',
                                   'PayPal hat keine vollständige Lieferadresse genannt', $bestellung);
    }
    $adresse['country'] = paymentCountryName($db, $adresse['country_code']);

    if (empty($context['customer_id'])) {
        customerRegister($db, $uuid, [
            'name'         => $adresse['name'],
            'street'       => $adresse['street'],
            'zipcode'      => $adresse['zipcode'],
            'city'         => $adresse['city'],
            'country'      => $adresse['country'],
            'email'        => $adresse['email'],
            'account_type' => 'true',
            'guest'        => true,
        ]);
        return [];
    }

    $customerId = (int)portalContextCustomer($db, $uuid)['customer_id'];
    $zeile = $db->getOne(
        "SELECT CASE
                    WHEN lower(btrim(c.name)) = lower(:name_a)
                     AND lower(btrim(COALESCE(c.street, ''))) = lower(:street_a)
                     AND btrim(COALESCE(c.zipcode, '')) = :zipcode_a
                     AND lower(btrim(COALESCE(c.city, ''))) = lower(:city_a)
                     AND shop_country_code(c.country) = :code_a
                    THEN 0
                    ELSE (SELECT s.shipto_id FROM shipto s
                           WHERE s.trans_id = c.id AND COALESCE(s.module, 'CT') = 'CT'
                             AND lower(btrim(COALESCE(s.shiptoname, ''))) = lower(:name_b)
                             AND lower(btrim(COALESCE(s.shiptostreet, ''))) = lower(:street_b)
                             AND btrim(COALESCE(s.shiptozipcode, '')) = :zipcode_b
                             AND lower(btrim(COALESCE(s.shiptocity, ''))) = lower(:city_b)
                             AND shop_country_code(s.shiptocountry) = :code_b
                           ORDER BY s.shipto_id LIMIT 1)
                END AS shipto_id
           FROM customer c WHERE c.id = :customer_id",
        [
            ':name_a'    => $adresse['name'],         ':name_b'    => $adresse['name'],
            ':street_a'  => $adresse['street'],       ':street_b'  => $adresse['street'],
            ':zipcode_a' => $adresse['zipcode'],      ':zipcode_b' => $adresse['zipcode'],
            ':city_a'    => $adresse['city'],         ':city_b'    => $adresse['city'],
            ':code_a'    => $adresse['country_code'], ':code_b'    => $adresse['country_code'],
            ':customer_id' => $customerId,
        ]
    );

    $shiptoId = $zeile['shipto_id'] ?? null;
    if (null === $shiptoId) {
        return ['shipto_id' => (int)shiptoCreate($db, $customerId, $adresse)['shipto_id']];
    }
    return (int)$shiptoId > 0 ? ['shipto_id' => (int)$shiptoId] : [];
}

/**
 * Rechnet den Warenkorb für die Lieferadresse der PayPal-Bestellung
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Sitzungskennung
 * @param array $bestellung Antwort von PayPal (paypalOrderRead)
 * @param array $lieferadresse Ergebnis von paymentExpressCustomer()
 * @return array{amount: float, approved: float, currency: string, equal: bool}
 *               amount = was der Warenkorb für diese Adresse kostet,
 *               approved = was der Kunde bei PayPal freigegeben hat
 * @throws ApiError CART_NOT_FOUND, CART_EMPTY, CART_NOT_OFFERED, SHIPPING_*
 */
function paymentExpressTotals($db, string $uuid, array $bestellung, array $lieferadresse): array {
    // Neu lesen: mit dem eben angelegten Gast hat die Sitzung einen Kunden
    $context    = portalContextCustomer($db, $uuid);
    $customerId = (int)$context['customer_id'];
    if (empty($context['cart_uuid'])) {
        throw new ApiError('CART_NOT_FOUND', 'Zu dieser Sitzung gibt es keinen Warenkorb');
    }

    $korb = cartRead($db, $context['cart_uuid'], $customerId, true,
                     shopShippingCountry($db, $customerId, $lieferadresse));
    $betrag = paymentCartAmount($korb);
    if (empty($korb['positions']) || 0 >= $betrag) {
        throw new ApiError('CART_EMPTY', 'Der Warenkorb ist leer');
    }
    cartRequireOffered($korb);
    cartRequireShipping($korb);

    $freigegeben = (float)($bestellung['purchase_units'][0]['amount']['value'] ?? 0);

    return [
        'amount'   => round($betrag, 2),
        'approved' => round($freigegeben, 2),
        'currency' => (string)$korb['currency'],
        'equal'    => number_format($betrag, 2, '.', '') === number_format($freigegeben, 2, '.', ''),
    ];
}

/**
 * Prüft, dass die Bestellung zur Sitzung gehört und freigegeben ist
 *
 * @param array $bestellung Antwort von PayPal (paypalOrderRead)
 * @param string|null $sitzung Sitzungskennung des Aufrufers; null = nicht prüfen
 *                             (Rückweg von PayPal, das Cookie kommt nicht sicher mit)
 * @return string Sitzungskennung aus der Bestellung
 * @throws ShopPaymentError PAYMENT_NO_REFERENCE, PAYMENT_ORDER_FOREIGN, PAYMENT_NOT_APPROVED
 */
function paymentExpressSession(array $bestellung, ?string $sitzung = null): string {
    $uuid = (string)($bestellung['purchase_units'][0]['reference_id'] ?? '');
    if ('' === $uuid) {
        throw new ShopPaymentError('PAYMENT_NO_REFERENCE',
                                   'PayPal hat keine Sitzungskennung zurückgegeben', $bestellung);
    }
    if (null !== $sitzung && $sitzung !== $uuid) {
        throw new ShopPaymentError('PAYMENT_ORDER_FOREIGN', 'Diese Zahlung gehört nicht zu dieser Sitzung');
    }
    if ('APPROVED' !== ($bestellung['status'] ?? '')) {
        throw new ShopPaymentError('PAYMENT_NOT_APPROVED',
                                   'Die Zahlung ist bei PayPal nicht (mehr) freigegeben', $bestellung);
    }
    return $uuid;
}

/**
 * Was die Kasse braucht, um einen geänderten Betrag bestätigen zu lassen
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop der Anfrage
 * @param string $uuid Sitzungskennung des Aufrufers
 * @param string $token Bestellkennung von PayPal
 * @return array{address: array, adresses: array, amount: float, approved: float, currency: string, equal: bool}
 * @throws ShopPaymentError, ApiError
 */
function paymentExpressInfo($db, int $kanal, string $uuid, string $token): array {
    $bestellung = paypalOrderRead($db, $kanal, $token);
    paymentExpressSession($bestellung, $uuid);

    $lieferadresse = paymentExpressCustomer($db, $uuid, $bestellung);
    $adresse = paymentExpressAddress($bestellung);
    $adresse['country'] = paymentCountryName($db, $adresse['country_code']);

    return [
        'address'  => $adresse,
        // in der Form der Kasse (adresses.shipping), für den Warenkorb daneben
        'adresses' => ['shipping' => ['default' => true, 'id' => isset($lieferadresse['shipto_id']) ? (string)$lieferadresse['shipto_id'] : null]],
    ] + paymentExpressTotals($db, $uuid, $bestellung, $lieferadresse);
}

/**
 * Zieht die Zahlung ein und legt die Rechnung an
 *
 * Läuft ohne Kontext-Cookie: die Sitzungskennung kommt als reference_id von
 * PayPal zurück. Der HugoShop kommt vom Einstieg (Shop-Schlüssel der
 * Webseite, über die PayPal zurückschickt) — sein Zugang zieht ein, bevor die
 * Sitzung bekannt ist.
 *
 * Express: Kunde und Lieferadresse kommen aus der Bestellung
 * (paymentExpressCustomer) — vor dem Einzug, damit kein Geld ohne Kunden
 * unterwegs ist. Kostet der Warenkorb für diese Adresse etwas anderes, als der
 * Kunde freigegeben hat, wird nicht eingezogen: die Antwort trägt
 * confirm = true, und die Kasse lässt den neuen Betrag bestätigen. Der zweite
 * Aufruf mit $bestaetigt passt die Bestellung bei PayPal an und zieht ein.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop der Anfrage
 * @param string $token Bestellkennung von PayPal (Parameter "token")
 * @param bool $bestaetigt true = der Kunde hat einen geänderten Betrag bestätigt
 * @param string|null $sitzung Sitzungskennung des Aufrufers, wenn er eine hat (Bestätigung aus der Kasse)
 * @return array{confirm: bool, order_id: string, ar_link?: string, invnumber?: string, email_status?: string, payment?: array}
 * @throws ShopPaymentError, ApiError
 */
function paymentEnd($db, int $kanal, string $token, bool $bestaetigt = false, ?string $sitzung = null): array {
    $bestellung = paypalOrderRead($db, $kanal, $token);
    $uuid = paymentExpressSession($bestellung, $sitzung);

    $lieferadresse = paymentExpressCustomer($db, $uuid, $bestellung);
    $summen = paymentExpressTotals($db, $uuid, $bestellung, $lieferadresse);

    if (!$summen['equal']) {
        if (!$bestaetigt) {
            return ['confirm' => true, 'order_id' => (string)$bestellung['id']];
        }
        $angepasst = paypalRequest($db, $kanal, '/v2/checkout/orders/'.rawurlencode($token), 'PATCH', [[
            'op'    => 'replace',
            'path'  => "/purchase_units/@reference_id=='".$uuid."'/amount",
            'value' => [
                'currency_code' => $summen['currency'],
                'value'         => number_format($summen['amount'], 2, '.', ''),
            ],
        ]], [
            'Content-Type: application/json',
            'Authorization: Bearer '.paypalAccessToken($db, $kanal),
        ]);
        if (204 !== $angepasst['status'] && 200 !== $angepasst['status']) {
            throw new ShopPaymentError('PAYMENT_AMOUNT_CHANGE_FAILED',
                                       'PayPal hat den geänderten Betrag nicht angenommen (HTTP '.$angepasst['status'].')',
                                       $angepasst['daten']);
        }
    }

    $antwort = paypalRequest($db, $kanal, '/v2/checkout/orders/'.rawurlencode($token).'/capture', 'POST', '{}', [
        'Content-Type: application/json',
        'Authorization: Bearer '.paypalAccessToken($db, $kanal),
    ], 'capture');

    $gebucht = $antwort['daten'];
    $zahlung = paypalPaymentState($gebucht);

    // Weder gebucht noch unterwegs: der Warenkorb bleibt stehen. Die Bridge
    // sah hier auf $result['status'] — der meint aber die Bestellung und kann
    // auch bei abgelehnter Buchung COMPLETED sein.
    if ($zahlung['failed']) {
        throw new ShopPaymentError('PAYMENT_NOT_COMPLETED',
                                   'Die Zahlung wurde nicht abgeschlossen', $gebucht);
    }

    if ($zahlung['open']) {
        // Ab hier muss ein Mensch etwas nachhalten.
        writeLog('[SHOP] Zahlung schwebt: Bestellung '.$zahlung['order_id']
                 .', Buchung '.$zahlung['capture_id']
                 .', Grund '.($zahlung['reason'] ?: 'ohne Angabe')
                 .' — Rechnung wird ohne Zahlungseingang angelegt.', true, DLOG_WRN);
    }

    // Dieselbe Lieferadresse, mit der eben gerechnet wurde
    $rechnung  = createShopInvoice($db, $uuid, $lieferadresse, $zahlung);
    $versendet = shopSendInvoiceMail($db, (int)$rechnung['ar_id']);

    return [
        'confirm'      => false,
        'order_id'     => (string)$bestellung['id'],
        'ar_link'      => $rechnung['ar_link'],
        'invnumber'    => $rechnung['invnumber'],
        'email_status' => $versendet ? 'success' : 'error',
        'payment'      => $zahlung,
    ];
}

/**
 * Schreibt einen Fehler im Zahlungsverkehr ins Protokoll
 *
 * Ein ApiError landet sonst nur in der Antwort an den Browser — und der
 * Kunde, der ihn sieht, meldet ihn selten (solar-spar.de, 19.11.2026: im
 * Protokoll stand zu PayPal nichts). Mit der Antwort von PayPal, wenn der
 * Fehler eine trägt.
 *
 * @param string $wo Aktion, in der der Fehler auftrat
 * @param ApiError $fehler
 * @return void
 */
function paymentLogError(string $wo, ApiError $fehler): void {
    $daten = $fehler instanceof ShopPaymentError ? $fehler->getDaten() : null;
    writeLog('[SHOP] PayPal '.$wo.': '.$fehler->getId().' — '.$fehler->getMessage()
             .(null === $daten ? '' : ' — '.json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
             true, DLOG_ERR);
}

/**
 * Lieferadresse fuer PayPal und den Versand
 *
 * Die in der Kasse gewaehlte gespeicherte Adresse oder die Rechnungsadresse
 * des Kunden, aufbereitet als PayPal-Versandadresse (der Vorschlag, den der
 * Kunde bei PayPal aendern kann) und mit dem Land fuer den Versand.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param array $lieferadresse ['shipto_id' => …] oder []
 * @return array{shipto_id: ?int, land: string, paypal: array}
 * @throws ApiError ADDRESS_NOT_FOUND
 */
function paymentDeliveryAddress($db, int $customerId, array $lieferadresse): array {
    $shiptoId = (int)($lieferadresse['shipto_id'] ?? 0);
    if ($shiptoId > 0) {
        $zeile = shiptoRead($db, $customerId, $shiptoId);   // prueft die Zugehoerigkeit
        $adresse = [
            'name'    => $zeile['name'] ?? $zeile['shiptoname'] ?? '',
            'street'  => $zeile['street'] ?? $zeile['shiptostreet'] ?? '',
            'zipcode' => $zeile['zipcode'] ?? $zeile['shiptozipcode'] ?? '',
            'city'    => $zeile['city'] ?? $zeile['shiptocity'] ?? '',
            'country' => $zeile['country'] ?? $zeile['shiptocountry'] ?? '',
        ];
    } else {
        $adresse = $db->getOne(
            "SELECT name, street, zipcode, city, country FROM customer WHERE id = :id",
            [':id' => $customerId]
        ) ?: [];
    }

    $land = trim((string)($adresse['country'] ?? ''));
    $code = $db->getOne("SELECT shop_country_code(:land) AS code", [':land' => $land]);

    return [
        'shipto_id' => $shiptoId ?: null,
        'land'      => $land,
        'paypal'    => [
            'name'    => ['full_name' => mb_substr((string)($adresse['name'] ?? ''), 0, 300)],
            'address' => [
                'address_line_1' => mb_substr((string)($adresse['street'] ?? ''), 0, 300),
                'postal_code'    => mb_substr((string)($adresse['zipcode'] ?? ''), 0, 60),
                'admin_area_2'   => mb_substr((string)($adresse['city'] ?? ''), 0, 120),
                // Unbekanntes Land: der Versand sperrt vorher (cartRequireShipping)
                'country_code'   => (string)($code['code'] ?? ''),
            ],
        ],
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
        "SELECT al.uuid, al.ar_id, al.channel_id, al.paypal_order_id, al.payment_reason, al.payment_mtime,
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
 * — geraten wird nichts. Jeder Vorgang mit dem PayPal-Zugang seines HugoShops.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $limit Höchstzahl der Vorgänge
 * @return array Je Vorgang Rechnungsnummer und Ergebnis
 */
function paymentsReconcile($db, int $limit = 100): array {
    $ergebnisse = [];

    foreach (paymentsPending($db, $limit) as $vorgang) {
        try {
            $kanal = (int)$vorgang['channel_id'];
            $antwort = paypalRequest($db, $kanal, '/v2/checkout/orders/'.rawurlencode($vorgang['paypal_order_id']), 'GET', null, [
                'Content-Type: application/json',
                'Authorization: Bearer '.paypalAccessToken($db, $kanal),
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
