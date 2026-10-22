<?php
// backend/api/shop/public/actions.php
//
// Die Aktionen, die der Shop dem öffentlichen Zugang des Kundenportals
// hinzufügt (backend/api/portal/public/bootstrap.php lädt diese Datei, wenn
// die Erweiterung aktiv ist). Jede ist dünn: Parameter lesen, Fachfunktion
// rufen, Antwort formatieren. Die Fachlogik steht unter ../lib/ und kennt
// weder Cookie noch Zugang.
//
// Sitzung, Konto, Rechnungen, Kontakt und Freischaltung liefert der Kern
// (backend/api/portal/public/actions.php); hier stehen Warenkorb, Kasse,
// Suche, Bestellung, Zahlung, Auswertung, Widerruf und Weiterleitung.
//
// SIGNATUR
//   ($db, $uuid, $daten, $website)
// Die Webseite der Anfrage bestimmt der Schlüssel; den HugoShop dazu holt
// sich jede Aktion mit shopChannelOfWebsite(). Aktionen ohne Kanal lassen
// den vierten Parameter weg.
//
// Kein @testdata: der API-Tester ruft Aktionen mit einem einzigen
// $data-Parameter und ohne Schlüssel, er kann sie nicht erreichen. Geprüft
// werden sie über die Fachschicht.

// Fachschicht und Haken (lib/shop.php über lib/portal_hooks.php)
require_once __DIR__.'/../lib/portal_hooks.php';

/**
 * Warenkorb und Kunde der laufenden Sitzung
 *
 * @return array{0: string, 1: int|null} Warenkorb-Kennung und Kunde
 */
function shopCartAndCustomer($db, string $uuid): array {
    $context = portalContextRequire($db, $uuid);
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
 * Antwortet mit dem Kanal, der zur Webseite des Schlüssels gehört
 *
 * Für den Verbindungstest der Kanalkarte (testShopBackendUrl): daran erkennt
 * OpensourceERP, dass die Adresse der Webseite zu seinem Zugang führt und die
 * Webseite einen HugoShop hat. Legt keine Sitzung an.
 */
function shopPing($db, string $uuid, array $daten, int $website) {
    $kanal = shopChannelOfWebsite($db, $website);
    resultInfo(true, '', [
        'service'      => 'oserp-shop',
        'website_id'   => $website,
        'channel_id'   => $kanal,
        'channel_name' => shopChannelValue($db, $kanal, 'name'),
    ]);
}

// ============================================================================
// WARENKORB
// ============================================================================

/** Inhalt des Warenkorbs */
function getCart($db, string $uuid, array $daten) {
    $context = portalContextRequire($db, $uuid);
    $customerId = empty($context['customer_id']) ? null : (int)$context['customer_id'];

    // Ohne Warenkorb ist die Antwort der leere Warenkorb, kein Fehler: die
    // Seite /warenkorb/ wird auch von Besuchern geöffnet, die nichts
    // hineingelegt haben.
    if (empty($context['cart_uuid'])) {
        resultInfo(true, '', ['positions' => [], 'totalSum' => 0.0, 'nettoTotalSum' => 0.0, 'customer' => null !== $customerId]);
        return;
    }

    // Versand nach der Lieferadresse, die die Kasse gerade gewählt hat
    // (adresses.shipping wie bei invoicing); ohne Angabe die
    // Standard-Lieferadresse des Kontos (dev/shop-versand.md, Schritt 6).
    $adressen = portalVar($daten, 'adresses', null);
    $land = is_array($adressen) && isset($adressen['shipping'])
        ? shopShippingCountry($db, $customerId, shopDeliveryAddress($adressen['shipping']))
        : null;

    // customer: Kunde (Konto oder Gast) in der Sitzung — nur dann zeigt der
    // Warenkorb den PayPal-Knopf; ohne Kunden führt der Weg über die Kasse
    resultInfo(true, '', ['customer' => null !== $customerId] + cartRead($db, $context['cart_uuid'], $customerId, true, $land));
}

/** Legt einen Artikel in den Warenkorb */
function inCart($db, string $uuid, array $daten) {
    resultInfo(true, '', cartAdd(
        $db, $uuid, portalVarInt($daten, 'product'), portalVarInt($daten, 'quantity', 1)
    ));
}

/** Entfernt eine Position */
function deleteCartPos($db, string $uuid, array $daten) {
    [$cartUuid, $customerId] = shopCartAndCustomer($db, $uuid);
    resultInfo(true, '', cartRemovePos($db, $cartUuid, $customerId, portalVarInt($daten, 'pos')));
}

/** Setzt die Menge einer Position */
function changeQuantity($db, string $uuid, array $daten) {
    [$cartUuid, $customerId] = shopCartAndCustomer($db, $uuid);
    resultInfo(true, '', cartSetQuantity(
        $db, $cartUuid, $customerId, portalVarInt($daten, 'pos'), portalVarInt($daten, 'quantity')
    ));
}

/** Anschriften für die Kasse */
function billingAndShipping($db, string $uuid, array $daten) {
    resultInfo(true, '', checkoutAddresses($db, $uuid, portalVarLang($daten)));
}

// ============================================================================
// SUCHE
// ============================================================================

/** Sofortsuche während der Eingabe */
function fastSearch($db, string $uuid, array $daten, int $website) {
    resultInfo(true, '', shopSearch($db, shopChannelOfWebsite($db, $website), (string)portalVar($daten, 'terms', ''), 5, 0));
}

/** Weitere Treffer zur laufenden Suche */
function moreSearchResults($db, string $uuid, array $daten, int $website) {
    resultInfo(true, '', shopSearch($db, shopChannelOfWebsite($db, $website), (string)portalVar($daten, 'terms', ''), 11, 0));
}

/** Vollständige Trefferliste */
function fullSearch($db, string $uuid, array $daten, int $website) {
    resultInfo(true, '', shopSearch($db, shopChannelOfWebsite($db, $website), (string)portalVar($daten, 'terms', ''), 30, 11));
}

/**
 * Werkzeugsuche: Spezialwerkzeuge zum Fahrzeug des Besuchers (HSN/TSN oder FIN)
 *
 * Verleih und Verkauf — lib/special_tools.php
 */
function findSpecialTools($db, string $uuid, array $daten, int $website) {
    resultInfo(true, '', shopFindSpecialTools($db, shopChannelOfWebsite($db, $website), [
        'hsn'         => (string)portalVar($daten, 'hsn', ''),
        'tsn'         => (string)portalVar($daten, 'tsn', ''),
        'fin'         => (string)portalVar($daten, 'fin', ''),
        'engine_code' => (string)portalVar($daten, 'engine_code', ''),
        'year'        => portalVarInt($daten, 'year', 0),
    ]));
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
    $adressen = portalVar($daten, 'adresses', []);
    // Gespeicherte Adresse (id), neue Felder oder die Rechnungsadresse —
    // vereinheitlicht in createShopInvoice (shopDeliveryAddress).
    $lieferadresse = is_array($adressen) ? ($adressen['shipping'] ?? []) : [];

    $rechnung = createShopInvoice($db, $uuid, is_array($lieferadresse) ? $lieferadresse : []);
    $versendet = shopSendInvoiceMail($db, (int)$rechnung['ar_id']);

    resultInfo(true, '', [
        'ar_link'      => $rechnung['ar_link'],
        'invnumber'    => $rechnung['invnumber'],
        'email_status' => $versendet ? 'success' : 'error',
    ]);
}

/** Zusammenfassung zum Rechnungslink — auch für Gäste ohne Anmeldung */
function getInvoiceSummary($db, string $uuid, array $daten, int $website) {
    resultInfo(true, '', invoiceSummaryByLink($db, (string)portalVar($daten, 'ar_link', ''), $website));
}

// ============================================================================
// ZAHLUNG
// ============================================================================

/**
 * Beginnt die Bezahlung und schickt den Kunden zu PayPal
 *
 * Der Aufrufer ist der Browser des Kunden (Link im Warenkorb oder in der
 * Kasse). Ohne Kunden in der Sitzung — Besucher ohne Konto und ohne
 * Gastangaben — geht es zur Kasse (checkout), wo Anmeldung und Gastformular
 * stehen; eine JSON-Fehlermeldung brächte den Kunden nicht weiter.
 */
function beginPayment($db, string $uuid, array $daten) {
    $context = portalContextRequire($db, $uuid);
    if (empty($context['customer_id'])) {
        portalRedirect((string)portalVar($daten, 'checkout', '') ?: (string)portalVar($daten, 'canceled', ''));
        return;
    }

    // Lieferadresse (dev/shop-versand.md, Schritt 6). Aus der Kasse: shipto =
    // gespeicherte Adresse, 0 = Rechnungsadresse; eine neue Adresse legt die
    // Kasse vorher an (newDeliveryAddress) und schickt deren Id. Vom Knopf im
    // Warenkorb ohne shipto: die Standard-Lieferadresse des Kontos — mit ihr
    // rechnet der Warenkorb auch.
    $shipto = portalVar($daten, 'shipto', null);
    if (null === $shipto || '' === $shipto) {
        $shipto = (int)($context['shipto_id'] ?? 0);
    }
    $bezahlt = paymentBegin(
        $db, $uuid,
        (string)portalVar($daten, 'bill', ''),
        (string)portalVar($daten, 'canceled', ''),
        shopDeliveryAddress(['shipto_id' => (int)$shipto])
    );
    portalRedirect($bezahlt['approval_url']);
}

/** Rückweg von PayPal: einziehen, Rechnung anlegen, zur Rechnungsseite */
function endPayment($db, string $uuid, array $daten, int $website) {
    $ergebnis = paymentEnd($db, shopChannelOfWebsite($db, $website), (string)portalVar($daten, 'token', ''));
    portalRedirect((string)portalVar($daten, 'page', '/').'?link='.$ergebnis['ar_link'].'#focus');
}

/** Der Kunde hat bei PayPal abgebrochen */
function paymentCanceled($db, string $uuid, array $daten) {
    portalRedirect((string)portalVar($daten, 'page', '/'));
}

// ============================================================================
// AUSWERTUNG UND WIDERRUF
// ============================================================================

/** Angaben zu einem Artikel für die Reichweitenmessung */
function gtmGetProductInfo($db, string $uuid, array $daten, int $website) {
    resultInfo(true, '', ['product' => analyticsProduct($db, shopChannelOfWebsite($db, $website), portalVarInt($daten, 'product_id'))]);
}

/** Angaben zu einem Kauf */
function gtmGetPurchased($db, string $uuid, array $daten, int $website) {
    resultInfo(true, '', ['purchased' => analyticsPurchase($db, shopChannelOfWebsite($db, $website), (string)portalVar($daten, 'ar_link', ''))]);
}

/** Gekaufte Artikel und Kaufangaben */
function gtmGetPurchasedProducts($db, string $uuid, array $daten, int $website) {
    resultInfo(true, '', analyticsPurchaseItems($db, shopChannelOfWebsite($db, $website), (string)portalVar($daten, 'ar_link', '')));
}

/** Widerruf entgegennehmen */
function submitWiderruf($db, string $uuid, array $daten, int $website) {
    // Für Menschen unsichtbares Feld: ist es ausgefüllt, war ein Programm am
    // Werk. Wir tun so, als sei alles in Ordnung, halten aber nichts fest.
    if ('' !== trim((string)portalVar($daten, 'website', ''))) {
        resultInfo(true, 'WITHDRAWAL_RECEIVED');
        return;
    }

    $context = portalContext($db, $uuid);
    $customerId = empty($context['customer_id']) ? null : (int)$context['customer_id'];

    $widerruf = submitWithdrawal($db, shopChannelOfWebsite($db, $website), $customerId, [
        'name'        => portalVar($daten, 'name', ''),
        'ordernumber' => portalVar($daten, 'ordernumber', ''),
        'email'       => portalVar($daten, 'email', ''),
        'reason'      => portalVar($daten, 'reason', ''),
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
 * Braucht keine Besuchersitzung: die 404-Seite ruft serverseitig auf.
 */
function resolveRedirect($db, string $uuid, array $daten, int $website) {
    resultInfo(true, '', shopResolveRedirect($db, shopChannelOfWebsite($db, $website), (string)portalVar($daten, 'url', '')));
}

// ============================================================================
// REGISTER
// ============================================================================

/**
 * Die Aktionen, die der Shop dem öffentlichen Zugang hinzufügt
 *
 * Diese Liste ist die Grenze. Was hier nicht steht, ist nicht erreichbar —
 * auch dann nicht, wenn die Funktion geladen ist.
 *
 * @return string[]
 */
function shopPublicActions(): array {
    return [
        // Warenkorb und Kasse
        'getCart', 'inCart', 'deleteCartPos', 'changeQuantity', 'billingAndShipping',
        // Suche
        'fastSearch', 'moreSearchResults', 'fullSearch', 'findSpecialTools',
        // Bestellung und Rechnung
        'checkout', 'invoicing', 'getInvoiceSummary',
        // Zahlung
        'beginPayment', 'endPayment', 'paymentCanceled',
        // Auswertung, Widerruf
        'gtmGetProductInfo', 'gtmGetPurchased', 'gtmGetPurchasedProducts', 'submitWiderruf',
        // Webseite
        'resolveRedirect',
        // Verbindungstest aus der Kanalkarte (testShopBackendUrl) — ohne Sitzung
        'shopPing',
    ];
}

/**
 * Aktionen ohne Besuchersitzung
 *
 * @return string[]
 */
function shopSessionlessActions(): array {
    return ['shopPing', 'resolveRedirect'];
}

/**
 * Aktionen, die ein abgeschalteter HugoShop ablehnt (V16)
 *
 * Nur, was einen neuen Kauf beginnt. endPayment bleibt offen: eine bei PayPal
 * schon begonnene Zahlung muss abgeschlossen werden können, sonst wäre Geld
 * unterwegs ohne Rechnung. Konto, Rechnungen und Widerruf ebenso — den
 * Widerruf schuldet der Betreiber auch nach dem Schließen.
 *
 * @return string[]
 */
function shopClosedActions(): array {
    return ['inCart', 'changeQuantity', 'checkout', 'invoicing', 'beginPayment'];
}

/**
 * Lehnt der Shop die Aktion gerade ab? — gefragt vom Unterbau des Portals
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite der Anfrage
 * @param string $aktion Name der Aktion
 * @return bool
 */
function shopPublicClosed($db, int $website, string $aktion): bool {
    if (!in_array($aktion, shopClosedActions(), true)) {
        return false;
    }
    try {
        return !shopIsOpen($db, shopChannelOfWebsite($db, $website));
    } catch (ApiError $e) {
        // Webseite ohne HugoShop: die Aktion meldet selbst SHOP_NOT_CONFIGURED
        return false;
    }
}
