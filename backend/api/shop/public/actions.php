<?php
// backend/api/shop/public/actions.php
//
// Die Aktionen des oeffentlichen Shop-Zugangs. Jede ist duenn: Parameter
// lesen, Fachfunktion rufen, Antwort formatieren. Die Fachlogik steht unter
// ../lib/ und kennt weder Cookie noch Zugang.
//
// SIGNATUR
// Anders als im uebrigen Backend bekommen diese Aktionen drei Parameter:
//   ($db, $uuid, $daten)
// Die Verbindung, weil es hier keine Mitarbeiter-Sitzung gibt, ueber die sich
// DbhCompany fuellen liesse; die Sitzungskennung des Besuchers, weil sie
// nirgends aus dem Cookie gelesen wird.
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
// SITZUNG
// ============================================================================

/** Status der Sitzung; legt sie an, wenn es sie noch nicht gibt */
function getContext($db, string $uuid, array $daten) {
    resultInfo(true, '', shopContextStatus($db, $uuid));
}

/** Meldet einen Shop-Kunden an */
function shopLogin($db, string $uuid, array $daten) {
    $email    = trim((string)shopVar($daten, 'email', ''));
    $password = (string)shopVar($daten, 'password', '');

    if ('' === $email || '' === $password) {
        throw new ApiError("MISSING_CREDENTIALS", 'Adresse und Kennwort werden gebraucht');
    }

    shopLoginCustomer($db, $uuid, $email, $password);
    resultInfo(true, '', shopContextStatus($db, $uuid));
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

/** Adresse der Artikelseite im Shop */
function getProductLink($db, string $uuid, array $daten) {
    $link = shopProductLink($db, shopVarInt($daten, 'product'));
    resultInfo(true, '', ['hyperlink' => $link.'#focus']);
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

    // Nach einem Abbruch bei der Bezahlung sollen die Versandkosten neu
    // bestimmt werden, statt aus dem vorigen Anlauf stehen zu bleiben.
    if (filter_var(shopVar($daten, 'invoicingCanceled', false), FILTER_VALIDATE_BOOLEAN)) {
        cartRemoveShipping($db, $context['cart_uuid']);
    }

    resultInfo(true, '', cartRead($db, $context['cart_uuid'], $customerId, true));
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
function fastSearch($db, string $uuid, array $daten) {
    resultInfo(true, '', shopSearch($db, (string)shopVar($daten, 'terms', ''), 5, 0));
}

/** Weitere Treffer zur laufenden Suche */
function moreSearchResults($db, string $uuid, array $daten) {
    resultInfo(true, '', shopSearch($db, (string)shopVar($daten, 'terms', ''), 11, 0));
}

/** Vollstaendige Trefferliste */
function fullSearch($db, string $uuid, array $daten) {
    resultInfo(true, '', shopSearch($db, (string)shopVar($daten, 'terms', ''), 30, 11));
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
        'getContext', 'shopLogin', 'shopLogout', 'getProductLink',
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
        'fastSearch', 'moreSearchResults', 'fullSearch',
    ];
}
