<?php
// backend/api/shop/public/actions.php
//
// Die Aktionen des oeffentlichen Shop-Zugangs. Jede ist duenn: Parameter
// lesen, Fachfunktion rufen, Antwort formatieren. Die Fachlogik steht unter
// ../lib/ und kennt weder Cookie noch Zugang.
//
// SIGNATUR
// Anders als im uebrigen Backend bekommen diese Aktionen vier Parameter:
//   ($db, $uuid, $daten, $kanal)
// Die Verbindung, weil es hier keine Mitarbeiter-Sitzung gibt, ueber die sich
// DbhCompany fuellen liesse; die Sitzungskennung des Besuchers, weil sie
// nirgends aus dem Cookie gelesen wird; den HugoShop der Anfrage, den der
// Shop-Schluessel bestimmt (dev/shop-mehrere-kanaele.md). Aktionen, die ihn
// nicht brauchen, lassen den vierten Parameter weg — die Sitzung und ihr
// Warenkorb tragen den Kanal ohnehin.
//
// Deshalb tragen sie kein @testdata: der API-Tester ruft Aktionen mit einem
// einzigen $data-Parameter und ohne Shop-Schluessel, er kann sie nicht
// erreichen. Geprueft werden sie ueber die Fachschicht.
//
// ANTWORT
// resultInfo(true, '', $daten) — das Format des uebrigen Backends, mit den
// Nutzdaten unter "payload". Die Bridge lieferte die Nutzdaten roh; das
// shop-ui zieht in Stufe 7 nach.

/**
 * Wert aus den Eingabedaten
 */
function shopVar(array $daten, string $name, $default = null) {
    return $daten[$name] ?? $default;
}

/**
 * Ganzzahl aus den Eingabedaten
 */
function shopVarInt(array $daten, string $name, int $default = 0): int {
    $wert = filter_var($daten[$name] ?? null, FILTER_VALIDATE_INT);
    return false === $wert ? $default : $wert;
}

/**
 * Sprachkuerzel der Anfrage
 */
function shopVarLang(array $daten): string {
    $lang = (string)($daten['lang'] ?? 'de');
    return preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'de';
}

/**
 * Kunde der laufenden Sitzung — wirft, wenn niemand angemeldet ist
 */
function shopCustomerId($db, string $uuid): int {
    return (int)shopContextCustomer($db, $uuid)['customer_id'];
}

/**
 * Warenkorb und Kunde der laufenden Sitzung
 *
 * @return array{0: string, 1: int|null} Warenkorb-Kennung und Kunde
 */
function shopCartAndCustomer($db, string $uuid): array {
    $context = shopContextRequire($db, $uuid);
    if (empty($context['cart_uuid'])) {
        throw new ApiError("CART_NOT_FOUND", 'Zu dieser Sitzung gibt es keinen Warenkorb');
    }
    $customerId = empty($context['customer_id']) ? null : (int)$context['customer_id'];
    return [$context['cart_uuid'], $customerId];
}

// ============================================================================
// VERBINDUNGSTEST
// ============================================================================

/**
 * Antwortet mit dem Kanal, zu dem der Shop-Schlüssel gehört
 *
 * Für den Verbindungstest der Kanalkarte (testShopBackendUrl): Daran erkennt
 * OpensourceERP, dass eine Adresse wirklich zu seinem Shop-Zugang führt und
 * der Schlüssel zu diesem Kanal passt. Gibt nichts preis außer Kennung und
 * Namen des Kanals, und legt keine Sitzung an — shopPublicDispatch ruft es
 * vor shopPublicContextUuid auf.
 */
function shopPing($db, string $uuid, array $daten, int $kanal) {
    $zeile = $db->getOne(
        "SELECT name FROM sales_channel_shop WHERE id = CAST(:kanal AS integer)",
        [':kanal' => $kanal]
    );
    resultInfo(true, '', [
        'service'      => 'oserp-shop',
        'channel_id'   => $kanal,
        'channel_name' => (string)($zeile['name'] ?? ''),
    ]);
}

// ============================================================================
// SITZUNG
// ============================================================================

/** Status der Sitzung; legt sie an, wenn es sie noch nicht gibt */
function getContext($db, string $uuid, array $daten, int $kanal) {
    resultInfo(true, '', shopContextStatus($db, $uuid, $kanal));
}

/** Meldet einen Shop-Kunden an */
function shopLogin($db, string $uuid, array $daten, int $kanal) {
    $email    = trim((string)shopVar($daten, 'email', ''));
    $password = (string)shopVar($daten, 'password', '');

    if ('' === $email || '' === $password) {
        throw new ApiError("MISSING_CREDENTIALS", 'Adresse und Kennwort werden gebraucht');
    }

    shopLoginCustomer($db, $uuid, $email, $password);
    resultInfo(true, '', shopContextStatus($db, $uuid, $kanal));
}

/** Meldet den Shop-Kunden ab und vergibt eine neue Sitzungskennung */
function shopLogout($db, string $uuid, array $daten) {
    shopLogoutCustomer($db, $uuid);

    // Die alte Kennung ist verbraucht: der naechste Aufruf soll nicht auf die
    // geloeschte Sitzung zeigen.
    $neu = shopNewContextUuid();
    setcookie(SHOP_CONTEXT_COOKIE, $neu, [
        'expires'  => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    resultInfo(true, '', ['context' => $neu]);
}

// ============================================================================
// WARENKORB
// ============================================================================

/** Inhalt des Warenkorbs */
function getCart($db, string $uuid, array $daten) {
    $context = shopContextRequire($db, $uuid);
    $customerId = empty($context['customer_id']) ? null : (int)$context['customer_id'];

    // Ohne Warenkorb ist die Antwort der leere Warenkorb, kein Fehler: die
    // Seite /warenkorb/ wird auch von Besuchern geoeffnet, die nichts
    // hineingelegt haben.
    if (empty($context['cart_uuid'])) {
        resultInfo(true, '', ['positions' => [], 'totalSum' => 0.0, 'nettoTotalSum' => 0.0]);
        return;
    }

    // Versand nach der Lieferadresse, die die Kasse gerade gewaehlt hat
    // (adresses.shipping wie bei invoicing); ohne Angabe die
    // Standard-Lieferadresse des Kontos (dev/shop-versand.md, Schritt 6).
    // invoicingCanceled kommt von der Abbruchseite und hat keine Wirkung mehr:
    // der Versand liegt nicht mehr als Zeile im Warenkorb.
    $adressen = shopVar($daten, 'adresses', null);
    $land = is_array($adressen) && isset($adressen['shipping'])
        ? shopShippingCountry($db, $customerId, shopDeliveryAddress($adressen['shipping']))
        : null;

    resultInfo(true, '', cartRead($db, $context['cart_uuid'], $customerId, true, $land));
}

/** Legt einen Artikel in den Warenkorb */
function inCart($db, string $uuid, array $daten) {
    resultInfo(true, '', cartAdd(
        $db, $uuid, shopVarInt($daten, 'product'), shopVarInt($daten, 'quantity', 1)
    ));
}

/** Entfernt eine Position */
function deleteCartPos($db, string $uuid, array $daten) {
    [$cartUuid, $customerId] = shopCartAndCustomer($db, $uuid);
    resultInfo(true, '', cartRemovePos($db, $cartUuid, $customerId, shopVarInt($daten, 'pos')));
}

/** Setzt die Menge einer Position */
function changeQuantity($db, string $uuid, array $daten) {
    [$cartUuid, $customerId] = shopCartAndCustomer($db, $uuid);
    resultInfo(true, '', cartSetQuantity(
        $db, $cartUuid, $customerId, shopVarInt($daten, 'pos'), shopVarInt($daten, 'quantity')
    ));
}

// ============================================================================
// KONTO
// ============================================================================

/** Anreden zur Auswahl */
function getAccountSelections($db, string $uuid, array $daten) {
    resultInfo(true, '', ['salutations' => salutations($db, shopVarLang($daten))]);
}

/** Legt ein Kundenkonto oder eine Gastbestellung an */
function registerAccount($db, string $uuid, array $daten) {
    $adresse = [
        'name'         => shopVar($daten, 'name', ''),
        'company_name' => shopVar($daten, 'company-name', ''),
        'street'       => shopVar($daten, 'street', ''),
        'zipcode'      => shopVar($daten, 'postcode', ''),
        'city'         => shopVar($daten, 'city', ''),
        'country'      => shopVar($daten, 'country', ''),
        'phone'        => shopVar($daten, 'phone', ''),
        'email'        => shopVar($daten, 'email', ''),
        'password'     => shopVar($daten, 'password', ''),
        'salutation'   => shopVar($daten, 'salutation', ''),
        'account_type' => shopVar($daten, 'account-type', 'true'),
        'guest'        => filter_var(shopVar($daten, 'guest', false), FILTER_VALIDATE_BOOLEAN),
    ];

    if (filter_var(shopVar($daten, 'add-delivery-address', false), FILTER_VALIDATE_BOOLEAN)) {
        $adresse['shipping'] = [
            'name'    => shopVar($daten, 'shipping-name', ''),
            'street'  => shopVar($daten, 'shipping-street', ''),
            'zipcode' => shopVar($daten, 'shipping-postcode', ''),
            'city'    => shopVar($daten, 'shipping-city', ''),
            'country' => shopVar($daten, 'shipping-country', ''),
            'phone'   => shopVar($daten, 'shipping-phone', ''),
            'email'   => shopVar($daten, 'shipping-email', ''),
        ];
    }

    resultInfo(true, 'ACCOUNT_CREATED', customerRegister($db, $uuid, $adresse));
}

/** Rechnungsadresse und Lieferadressen */
function accountAddresses($db, string $uuid, array $daten) {
    resultInfo(true, '', customerAddresses($db, shopCustomerId($db, $uuid)));
}

/** Aendert die Rechnungsadresse */
function updateAddress($db, string $uuid, array $daten) {
    customerUpdateBillingAddress($db, shopCustomerId($db, $uuid), [
        'street'  => shopVar($daten, 'street', ''),
        'zipcode' => shopVar($daten, 'zipcode', ''),
        'city'    => shopVar($daten, 'city', ''),
        'country' => shopVar($daten, 'country', ''),
    ]);
    resultInfo(true, 'ACCOUNT_UPDATED');
}

/** Legt eine Lieferadresse an */
function newDeliveryAddress($db, string $uuid, array $daten) {
    resultInfo(true, 'ADDRESS_CREATED', shiptoCreate($db, shopCustomerId($db, $uuid), [
        'name'    => shopVar($daten, 'name', ''),
        'street'  => shopVar($daten, 'street', ''),
        'zipcode' => shopVar($daten, 'zipcode', ''),
        'city'    => shopVar($daten, 'city', ''),
        'country' => shopVar($daten, 'country', ''),
        'phone'   => shopVar($daten, 'phone', ''),
        'email'   => shopVar($daten, 'email', ''),
    ]));
}

/** Liest eine Lieferadresse */
function getDeliveryAddress($db, string $uuid, array $daten) {
    resultInfo(true, '', shiptoRead(
        $db, shopCustomerId($db, $uuid), shopVarInt($daten, 'shipto_id')
    ));
}

/** Aendert eine Lieferadresse */
function updateDeliveryAddress($db, string $uuid, array $daten) {
    shiptoUpdate($db, shopCustomerId($db, $uuid), shopVarInt($daten, 'shipto_id'), [
        'name'    => shopVar($daten, 'name', ''),
        'street'  => shopVar($daten, 'street', ''),
        'zipcode' => shopVar($daten, 'zipcode', ''),
        'city'    => shopVar($daten, 'city', ''),
        'country' => shopVar($daten, 'country', ''),
        'phone'   => shopVar($daten, 'phone', ''),
        'email'   => shopVar($daten, 'email', ''),
    ]);
    resultInfo(true, 'ADDRESS_UPDATED');
}

/** Loescht eine Lieferadresse */
function removeDeliveryAddress($db, string $uuid, array $daten) {
    shiptoDelete($db, shopCustomerId($db, $uuid), shopVarInt($daten, 'shipto_id'));
    resultInfo(true, 'ADDRESS_REMOVED');
}

/** Waehlt die Standard-Lieferadresse */
function standardDeliveryAddress($db, string $uuid, array $daten) {
    shiptoSetDefault($db, shopCustomerId($db, $uuid), shopVarInt($daten, 'shipto_id'));
    resultInfo(true, 'ADDRESS_UPDATED');
}

/** Liefert kuenftig an die Rechnungsadresse */
function takeBillAddress($db, string $uuid, array $daten) {
    shiptoClearDefault($db, shopCustomerId($db, $uuid));
    resultInfo(true, 'ADDRESS_UPDATED');
}

/** Uebersicht des Kontos */
function personalOverview($db, string $uuid, array $daten) {
    resultInfo(true, '', customerOverview($db, shopCustomerId($db, $uuid), shopVarLang($daten)));
}

/** Persoenliche Angaben */
function personalProfil($db, string $uuid, array $daten) {
    resultInfo(true, '', customerProfile($db, shopCustomerId($db, $uuid), shopVarLang($daten)));
}

/** Aendert die persoenlichen Angaben */
function updatePersonalProfil($db, string $uuid, array $daten) {
    customerUpdateProfile($db, shopCustomerId($db, $uuid), [
        'name'           => shopVar($daten, 'name', ''),
        'company_name'   => shopVar($daten, 'company_name', ''),
        'phone'          => shopVar($daten, 'phone', ''),
        'salutation'     => shopVar($daten, 'salutation', ''),
        'natural_person' => shopVar($daten, 'natural_person', 'true'),
    ]);
    resultInfo(true, 'ACCOUNT_UPDATED');
}

/** Zahlungsarten zur Auswahl */
function personalPayment($db, string $uuid, array $daten) {
    resultInfo(true, '', customerPaymentTerms($db, shopCustomerId($db, $uuid), shopVarLang($daten)));
}

/** Waehlt die Zahlungsart */
function changePaymentMethod($db, string $uuid, array $daten) {
    customerSetPaymentTerm($db, shopCustomerId($db, $uuid), shopVarInt($daten, 'payment_id'));
    resultInfo(true, 'ACCOUNT_UPDATED');
}

/** Aendert die E-Mail-Adresse */
function updateEmail($db, string $uuid, array $daten) {
    customerUpdateEmail(
        $db, shopCustomerId($db, $uuid),
        trim((string)shopVar($daten, 'email', '')),
        (string)shopVar($daten, 'password', '')
    );
    resultInfo(true, 'ACCOUNT_UPDATED');
}

/** Aendert das Kennwort */
function updatePassword($db, string $uuid, array $daten) {
    customerUpdatePassword(
        $db, shopCustomerId($db, $uuid),
        (string)shopVar($daten, 'old_password', ''),
        (string)shopVar($daten, 'new_password', '')
    );
    resultInfo(true, 'ACCOUNT_UPDATED');
}

/** Anschriften fuer die Kasse */
function billingAndShipping($db, string $uuid, array $daten) {
    resultInfo(true, '', checkoutAddresses($db, $uuid, shopVarLang($daten)));
}

/** Vorbelegung des Kontaktformulars */
function contactInit($db, string $uuid, array $daten) {
    $context = shopContextRequire($db, $uuid);
    $customerId = empty($context['customer_id']) ? null : (int)$context['customer_id'];
    resultInfo(true, '', contactFormDefaults($db, $customerId, shopVarLang($daten)));
}

// ============================================================================
// SUCHE
// ============================================================================

/** Sofortsuche waehrend der Eingabe */
function fastSearch($db, string $uuid, array $daten, int $kanal) {
    resultInfo(true, '', shopSearch($db, $kanal, (string)shopVar($daten, 'terms', ''), 5, 0));
}

/** Weitere Treffer zur laufenden Suche */
function moreSearchResults($db, string $uuid, array $daten, int $kanal) {
    resultInfo(true, '', shopSearch($db, $kanal, (string)shopVar($daten, 'terms', ''), 11, 0));
}

/** Vollstaendige Trefferliste */
function fullSearch($db, string $uuid, array $daten, int $kanal) {
    resultInfo(true, '', shopSearch($db, $kanal, (string)shopVar($daten, 'terms', ''), 30, 11));
}

/**
 * Werkzeugsuche: Spezialwerkzeuge zum Fahrzeug des Besuchers (HSN/TSN oder FIN)
 *
 * Verleih und Verkauf — lib/special_tools.php
 */
function findSpecialTools($db, string $uuid, array $daten, int $kanal) {
    resultInfo(true, '', shopFindSpecialTools($db, $kanal, [
        'hsn'         => (string)shopVar($daten, 'hsn', ''),
        'tsn'         => (string)shopVar($daten, 'tsn', ''),
        'fin'         => (string)shopVar($daten, 'fin', ''),
        'engine_code' => (string)shopVar($daten, 'engine_code', ''),
        'year'        => shopVarInt($daten, 'year', 0),
    ]));
}

/**
 * Die Aktionen, die dieser Zugang zulaesst
 *
 * Diese Liste ist die Grenze des oeffentlichen Zugangs. Was hier nicht steht,
 * ist nicht erreichbar — auch dann nicht, wenn die Funktion geladen ist.
 *
 * Rechnung, Zahlung, Auswertung und Widerruf kommen mit Stufe 5 dazu.
 *
 * @return string[]
 */
function shopPublicActions(): array {
    return [
        // Sitzung
        'getContext', 'shopLogin', 'shopLogout',
        // Warenkorb
        'getCart', 'inCart', 'deleteCartPos', 'changeQuantity',
        // Konto
        'getAccountSelections', 'registerAccount', 'accountAddresses', 'updateAddress',
        'newDeliveryAddress', 'getDeliveryAddress', 'updateDeliveryAddress',
        'removeDeliveryAddress', 'standardDeliveryAddress', 'takeBillAddress',
        'personalOverview', 'personalProfil', 'updatePersonalProfil',
        'personalPayment', 'changePaymentMethod', 'updateEmail', 'updatePassword',
        'billingAndShipping', 'contactInit',
        // Suche
        'fastSearch', 'moreSearchResults', 'fullSearch', 'findSpecialTools',
        // Bestellung und Rechnung
        'checkout', 'invoicing', 'personalOrders', 'personalOrder',
        'getInvoiceSummary', 'downloadInvoice', 'downloadInvoiceLink',
        // Zahlung
        'beginPayment', 'endPayment', 'paymentCanceled',
        // Auswertung, Kontakt, Widerruf
        'gtmGetProductInfo', 'gtmGetPurchased', 'gtmGetPurchasedProducts',
        'sendContactMail', 'submitWiderruf',
        // Webseite
        'resolveRedirect',
        // Verbindungstest aus der Kanalkarte (testShopBackendUrl) — ohne Sitzung
        'shopPing',
    ];
}

/**
 * Aktionen, die ein abgeschalteter HugoShop ablehnt (V16)
 *
 * Nur, was einen neuen Kauf beginnt. endPayment bleibt offen: eine bei PayPal
 * schon begonnene Zahlung muss abgeschlossen werden können, sonst wäre Geld
 * unterwegs ohne Rechnung. Konto, Rechnungen und Widerruf ebenso — den
 * Widerruf schuldet der Betreiber auch nach dem Schließen.
 *
 * @return array
 */
function shopClosedActions(): array {
    return ['inCart', 'changeQuantity', 'checkout', 'invoicing', 'beginPayment'];
}

// ============================================================================
// BESTELLUNG UND RECHNUNG
// ============================================================================

/** Kasse: Warenkorb mit Versandkosten */
function checkout($db, string $uuid, array $daten) {
    getCart($db, $uuid, $daten);
}

/**
 * Wandelt den Warenkorb in eine Rechnung — Zahlung auf Rechnung
 *
 * Der Weg über PayPal läuft über beginPayment/endPayment.
 */
function invoicing($db, string $uuid, array $daten) {
    $adressen = shopVar($daten, 'adresses', []);
    // Gespeicherte Adresse (id), neue Felder oder die Rechnungsadresse —
    // vereinheitlicht in createShopInvoice (shopDeliveryAddress). Frueher hiess
    // default hier immer „an die Rechnungsadresse", auch mit gewaehlter id.
    $lieferadresse = is_array($adressen) ? ($adressen['shipping'] ?? []) : [];

    $rechnung = createShopInvoice($db, $uuid, is_array($lieferadresse) ? $lieferadresse : []);
    $versendet = shopSendInvoiceMail($db, (int)$rechnung['ar_id']);

    resultInfo(true, '', [
        'ar_link'      => $rechnung['ar_link'],
        'invnumber'    => $rechnung['invnumber'],
        'email_status' => $versendet ? 'success' : 'error',
    ]);
}

/** Bestellungen des Kunden */
function personalOrders($db, string $uuid, array $daten) {
    resultInfo(true, '', customerInvoices($db, shopCustomerId($db, $uuid)));
}

/** Eine Bestellung des Kunden */
function personalOrder($db, string $uuid, array $daten, int $kanal) {
    resultInfo(true, '', customerInvoice($db, shopCustomerId($db, $uuid), shopVarInt($daten, 'id'), $kanal));
}

/** Zusammenfassung zum Rechnungslink — auch für Gäste ohne Anmeldung */
function getInvoiceSummary($db, string $uuid, array $daten, int $kanal) {
    resultInfo(true, '', invoiceSummaryByLink($db, (string)shopVar($daten, 'ar_link', ''), $kanal));
}

/** Rechnungs-PDF einer eigenen Bestellung */
function downloadInvoice($db, string $uuid, array $daten) {
    shopSendPdf($db, shopInvoiceForCustomer($db, $uuid, shopVarInt($daten, 'payment-id')));
}

/** Rechnungs-PDF über den Rechnungslink */
function downloadInvoiceLink($db, string $uuid, array $daten, int $kanal) {
    shopSendPdf($db, shopInvoiceIdByLink($db, (string)shopVar($daten, 'ar-link', ''), $kanal));
}

/**
 * Prüft, ob die Rechnung dem Kunden der Sitzung gehört
 *
 * Ohne diese Prüfung liesse sich durch Hochzählen der Kennung jede fremde
 * Rechnung herunterladen.
 */
function shopInvoiceForCustomer($db, string $uuid, int $arId): int {
    customerInvoice($db, shopCustomerId($db, $uuid), $arId);
    return $arId;
}

/** Liefert ein Rechnungs-PDF aus und räumt die Datei weg */
function shopSendPdf($db, int $arId) {
    $pdf = shopInvoicePdf($db, $arId);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$pdf['filename'].'"');
    header('Content-Length: '.(string)filesize($pdf['path']));
    readfile($pdf['path']);
    @unlink($pdf['path']);
}

// ============================================================================
// ZAHLUNG
// ============================================================================

/** Beginnt die Bezahlung und schickt den Kunden zu PayPal */
function beginPayment($db, string $uuid, array $daten) {
    // Lieferadresse (dev/shop-versand.md, Schritt 6). Aus der Kasse: shipto =
    // gespeicherte Adresse, 0 = Rechnungsadresse; eine neue Adresse legt die
    // Kasse vorher an (newDeliveryAddress) und schickt deren Id. Vom Knopf im
    // Warenkorb ohne shipto: die Standard-Lieferadresse des Kontos — mit ihr
    // rechnet der Warenkorb auch.
    $shipto = shopVar($daten, 'shipto', null);
    if (null === $shipto || '' === $shipto) {
        $standard = $db->getOne(
            "SELECT ce.hugoshop_shipto_id AS id
               FROM context_hugoshop k
               JOIN customer_ext ce ON ce.customer_id = k.customer_id
              WHERE k.uuid = :uuid",
            [':uuid' => $uuid]
        );
        $shipto = (int)($standard['id'] ?? 0);
    }
    $bezahlt = paymentBegin(
        $db, $uuid,
        (string)shopVar($daten, 'bill', ''),
        (string)shopVar($daten, 'canceled', ''),
        shopDeliveryAddress(['shipto_id' => (int)$shipto])
    );
    shopRedirect($bezahlt['approval_url']);
}

/** Rückweg von PayPal: einziehen, Rechnung anlegen, zur Rechnungsseite */
function endPayment($db, string $uuid, array $daten, int $kanal) {
    $ergebnis = paymentEnd($db, $kanal, (string)shopVar($daten, 'token', ''));
    shopRedirect((string)shopVar($daten, 'page', '/').'?link='.$ergebnis['ar_link'].'#focus');
}

/** Der Kunde hat bei PayPal abgebrochen */
function paymentCanceled($db, string $uuid, array $daten) {
    shopRedirect((string)shopVar($daten, 'page', '/'));
}

/**
 * Schickt den Browser weiter
 *
 * Der Aufrufer ist hier der Browser des Kunden, nicht ein Programm: eine
 * JSON-Antwort brächte ihn nicht weiter. Die Bridge endete im Fehlerfall
 * stumm, und der Kunde wusste nicht, ob er bezahlt hat.
 */
function shopRedirect(string $ziel) {
    if ('' === trim($ziel)) { $ziel = '/'; }

    if (headers_sent()) {
        echo '<meta http-equiv="refresh" content="0; url='.htmlspecialchars($ziel, ENT_QUOTES).'">';
        return;
    }
    header('Location: '.$ziel);
}

// ============================================================================
// AUSWERTUNG, KONTAKT, WIDERRUF
// ============================================================================

/** Angaben zu einem Artikel für die Reichweitenmessung */
function gtmGetProductInfo($db, string $uuid, array $daten, int $kanal) {
    resultInfo(true, '', ['product' => analyticsProduct($db, $kanal, shopVarInt($daten, 'product_id'))]);
}

/** Angaben zu einem Kauf */
function gtmGetPurchased($db, string $uuid, array $daten, int $kanal) {
    resultInfo(true, '', ['purchased' => analyticsPurchase($db, $kanal, (string)shopVar($daten, 'ar_link', ''))]);
}

/** Gekaufte Artikel und Kaufangaben */
function gtmGetPurchasedProducts($db, string $uuid, array $daten, int $kanal) {
    resultInfo(true, '', analyticsPurchaseItems($db, $kanal, (string)shopVar($daten, 'ar_link', '')));
}

/** Anfrage aus dem Kontaktformular */
function sendContactMail($db, string $uuid, array $daten, int $kanal) {
    $versendet = shopSendContactMail($db, $kanal, [
        'name'  => shopVar($daten, 'name', ''),
        'email' => shopVar($daten, 'email', ''),
        'phone' => shopVar($daten, 'phone', ''),
        'term'  => shopVar($daten, 'term', ''),
    ]);

    $versendet
        ? resultInfo(true, 'CONTACT_SENT')
        : resultInfo(false, 'CONTACT_NOT_SENT', null, 'Die Nachricht konnte nicht versendet werden');
}

/** Widerruf entgegennehmen */
function submitWiderruf($db, string $uuid, array $daten, int $kanal) {
    // Für Menschen unsichtbares Feld: ist es ausgefüllt, war ein Programm am
    // Werk. Wir tun so, als sei alles in Ordnung, halten aber nichts fest.
    if ('' !== trim((string)shopVar($daten, 'website', ''))) {
        resultInfo(true, 'WITHDRAWAL_RECEIVED');
        return;
    }

    $context = shopContext($db, $uuid);
    $customerId = empty($context['customer_id']) ? null : (int)$context['customer_id'];

    $widerruf = submitWithdrawal($db, $kanal, $customerId, [
        'name'        => shopVar($daten, 'name', ''),
        'ordernumber' => shopVar($daten, 'ordernumber', ''),
        'email'       => shopVar($daten, 'email', ''),
        'reason'      => shopVar($daten, 'reason', ''),
        'remote_addr' => $_SERVER['REMOTE_ADDR']     ?? '',
        'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ]);

    resultInfo(true, 'WITHDRAWAL_RECEIVED', ['id' => $widerruf['id']]);
}

// ============================================================================
// WEBSEITE
// ============================================================================

/**
 * Umleitung für eine entfallene Seite — gefragt von der 404-Seite der Webseite
 *
 * Braucht keine Besuchersitzung: die 404-Seite ruft serverseitig auf, und
 * angelegt wird eine Sitzung ohnehin erst von getContext.
 *
 * @param string $daten['url'] Host und Pfad der angefragten Adresse
 * @testdata {"url": "shop.example.de/alte-seite"}
 */
function resolveRedirect($db, string $uuid, array $daten, int $kanal) {
    resultInfo(true, '', shopResolveRedirect($db, $kanal, (string)shopVar($daten, 'url', '')));
}
