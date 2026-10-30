<?php
// backend/api/portal/public/actions.php
//
// Die Aktionen des öffentlichen Zugangs, die der Kern selbst anbietet:
// Sitzung, Konto, Rechnungen, Kontakt, Freischaltung. Jede ist dünn:
// Parameter lesen, Fachfunktion rufen, Antwort formatieren. Die Fachlogik
// steht unter ../lib/ und kennt weder Cookie noch Zugang.
//
// SIGNATUR
// Anders als im übrigen Backend bekommen diese Aktionen vier Parameter:
//   ($db, $uuid, $daten, $website)
// Die Verbindung, weil es hier keine Mitarbeiter-Sitzung gibt, über die sich
// DbhCompany füllen ließe; die Sitzungskennung des Besuchers, weil sie
// nirgends aus dem Cookie gelesen wird; die Webseite der Anfrage, die der
// Schlüssel bestimmt.
//
// Deshalb tragen sie kein @testdata: der API-Tester ruft Aktionen mit einem
// einzigen $data-Parameter und ohne Schlüssel, er kann sie nicht erreichen.
// Geprüft werden sie über die Fachschicht.
//
// NAMEN
// Die Namen stammen aus dem HugoShop (shopLogin, personalOverview, …) und
// bleiben, damit die Widgets der Webseite weiterlaufen. Die Kern-Widgets
// dürfen daneben portalLogin und portalLogout rufen.

/**
 * Wert aus den Eingabedaten
 */
function portalVar(array $daten, string $name, $default = null) {
    return $daten[$name] ?? $default;
}

/**
 * Ganzzahl aus den Eingabedaten
 */
function portalVarInt(array $daten, string $name, int $default = 0): int {
    $wert = filter_var($daten[$name] ?? null, FILTER_VALIDATE_INT);
    return false === $wert ? $default : $wert;
}

/**
 * Sprachkürzel der Anfrage
 */
function portalVarLang(array $daten): string {
    $lang = (string)($daten['lang'] ?? 'de');
    return preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'de';
}

/**
 * Schickt den Browser weiter
 *
 * Der Aufrufer ist hier der Browser des Kunden, nicht ein Programm: eine
 * JSON-Antwort brächte ihn nicht weiter.
 */
function portalRedirect(string $ziel): void {
    if ('' === trim($ziel)) { $ziel = '/'; }

    if (headers_sent()) {
        echo '<meta http-equiv="refresh" content="0; url='.htmlspecialchars($ziel, ENT_QUOTES).'">';
        return;
    }
    header('Location: '.$ziel);
}

/**
 * Liefert ein Rechnungs-PDF aus und räumt die Datei weg
 */
function portalSendPdf($db, int $arId): void {
    portalSendPdfFile(invoicePdf($db, $arId));
}

/**
 * Liefert eine erzeugte PDF-Datei aus und räumt sie weg
 *
 * @param array{path: string, filename: string} $pdf
 */
function portalSendPdfFile(array $pdf): void {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$pdf['filename'].'"');
    header('Content-Length: '.(string)filesize($pdf['path']));
    readfile($pdf['path']);
    @unlink($pdf['path']);
}

// ============================================================================
// VERBINDUNGSTEST
// ============================================================================

/**
 * Antwortet mit der Webseite, zu der der Schlüssel gehört
 *
 * Für den Verbindungstest der Webseitenkarte (testWebsiteBackendUrl): daran
 * erkennt OpensourceERP, dass eine Adresse wirklich zu seinem Portal-Zugang
 * führt und der Schlüssel zu dieser Webseite passt. Gibt nichts preis außer
 * Kennung und Namen, und legt keine Sitzung an.
 */
function portalPing($db, string $uuid, array $daten, int $website) {
    resultInfo(true, '', [
        'service'      => 'oserp-portal',
        'website_id'   => $website,
        'website_name' => websiteValue($db, $website, 'name'),
    ]);
}

// ============================================================================
// SITZUNG
// ============================================================================

/** Status der Sitzung; legt sie an, wenn es sie noch nicht gibt */
function getContext($db, string $uuid, array $daten, int $website) {
    resultInfo(true, '', portalContextStatus($db, $uuid, $website));
}

/** Meldet einen Kunden an */
function portalLogin($db, string $uuid, array $daten, int $website) {
    $email    = trim((string)portalVar($daten, 'email', ''));
    $password = (string)portalVar($daten, 'password', '');

    if ('' === $email || '' === $password) {
        throw new ApiError("MISSING_CREDENTIALS", 'Adresse und Kennwort werden gebraucht');
    }

    portalLoginCustomer($db, $uuid, $email, $password);
    resultInfo(true, '', portalContextStatus($db, $uuid, $website));
}

/** Meldet einen Kunden an — Name aus dem HugoShop */
function shopLogin($db, string $uuid, array $daten, int $website) {
    portalLogin($db, $uuid, $daten, $website);
}

/** Meldet den Kunden ab und vergibt eine neue Sitzungskennung */
function portalLogout($db, string $uuid, array $daten, int $website) {
    portalLogoutCustomer($db, $uuid);

    // Die alte Kennung ist verbraucht: der nächste Aufruf soll nicht auf die
    // gelöschte Sitzung zeigen.
    $neu = portalNewUuid();
    portalContextCookie($neu, '' !== (string)($_SERVER['HTTP_ORIGIN'] ?? '') && !empty($_SERVER['HTTPS']));

    resultInfo(true, '', ['context' => $neu]);
}

/** Meldet den Kunden ab — Name aus dem HugoShop */
function shopLogout($db, string $uuid, array $daten, int $website) {
    portalLogout($db, $uuid, $daten, $website);
}

// ============================================================================
// KONTO
// ============================================================================

/** Anreden zur Auswahl */
function getAccountSelections($db, string $uuid, array $daten) {
    resultInfo(true, '', ['salutations' => salutations($db, portalVarLang($daten))]);
}

/** Legt ein Kundenkonto oder eine Gastbestellung an */
function registerAccount($db, string $uuid, array $daten) {
    $adresse = [
        'name'         => portalVar($daten, 'name', ''),
        'company_name' => portalVar($daten, 'company-name', ''),
        'street'       => portalVar($daten, 'street', ''),
        'zipcode'      => portalVar($daten, 'postcode', ''),
        'city'         => portalVar($daten, 'city', ''),
        'country'      => portalVar($daten, 'country', ''),
        'phone'        => portalVar($daten, 'phone', ''),
        'email'        => portalVar($daten, 'email', ''),
        'password'     => portalVar($daten, 'password', ''),
        'salutation'   => portalVar($daten, 'salutation', ''),
        'account_type' => portalVar($daten, 'account-type', 'true'),
        'guest'        => filter_var(portalVar($daten, 'guest', false), FILTER_VALIDATE_BOOLEAN),
    ];

    if (filter_var(portalVar($daten, 'add-delivery-address', false), FILTER_VALIDATE_BOOLEAN)) {
        $adresse['shipping'] = [
            'name'    => portalVar($daten, 'shipping-name', ''),
            'street'  => portalVar($daten, 'shipping-street', ''),
            'zipcode' => portalVar($daten, 'shipping-postcode', ''),
            'city'    => portalVar($daten, 'shipping-city', ''),
            'country' => portalVar($daten, 'shipping-country', ''),
            'phone'   => portalVar($daten, 'shipping-phone', ''),
            'email'   => portalVar($daten, 'shipping-email', ''),
        ];
    }

    resultInfo(true, 'ACCOUNT_CREATED', customerRegister($db, $uuid, $adresse));
}

/** Rechnungsadresse und Lieferadressen */
function accountAddresses($db, string $uuid, array $daten) {
    resultInfo(true, '', customerAddresses($db, portalCustomerId($db, $uuid)));
}

/** Ändert die Rechnungsadresse */
function updateAddress($db, string $uuid, array $daten) {
    customerUpdateBillingAddress($db, portalCustomerId($db, $uuid), [
        'street'  => portalVar($daten, 'street', ''),
        'zipcode' => portalVar($daten, 'zipcode', ''),
        'city'    => portalVar($daten, 'city', ''),
        'country' => portalVar($daten, 'country', ''),
    ]);
    resultInfo(true, 'ACCOUNT_UPDATED');
}

/** Legt eine Lieferadresse an */
function newDeliveryAddress($db, string $uuid, array $daten) {
    resultInfo(true, 'ADDRESS_CREATED', shiptoCreate($db, portalCustomerId($db, $uuid), [
        'name'    => portalVar($daten, 'name', ''),
        'street'  => portalVar($daten, 'street', ''),
        'zipcode' => portalVar($daten, 'zipcode', ''),
        'city'    => portalVar($daten, 'city', ''),
        'country' => portalVar($daten, 'country', ''),
        'phone'   => portalVar($daten, 'phone', ''),
        'email'   => portalVar($daten, 'email', ''),
    ]));
}

/** Liest eine Lieferadresse */
function getDeliveryAddress($db, string $uuid, array $daten) {
    resultInfo(true, '', shiptoRead(
        $db, portalCustomerId($db, $uuid), portalVarInt($daten, 'shipto_id')
    ));
}

/** Ändert eine Lieferadresse */
function updateDeliveryAddress($db, string $uuid, array $daten) {
    shiptoUpdate($db, portalCustomerId($db, $uuid), portalVarInt($daten, 'shipto_id'), [
        'name'    => portalVar($daten, 'name', ''),
        'street'  => portalVar($daten, 'street', ''),
        'zipcode' => portalVar($daten, 'zipcode', ''),
        'city'    => portalVar($daten, 'city', ''),
        'country' => portalVar($daten, 'country', ''),
        'phone'   => portalVar($daten, 'phone', ''),
        'email'   => portalVar($daten, 'email', ''),
    ]);
    resultInfo(true, 'ADDRESS_UPDATED');
}

/** Löscht eine Lieferadresse */
function removeDeliveryAddress($db, string $uuid, array $daten) {
    shiptoDelete($db, portalCustomerId($db, $uuid), portalVarInt($daten, 'shipto_id'));
    resultInfo(true, 'ADDRESS_REMOVED');
}

/** Wählt die Standard-Lieferadresse */
function standardDeliveryAddress($db, string $uuid, array $daten) {
    shiptoSetDefault($db, portalCustomerId($db, $uuid), portalVarInt($daten, 'shipto_id'));
    resultInfo(true, 'ADDRESS_UPDATED');
}

/** Liefert künftig an die Rechnungsadresse */
function takeBillAddress($db, string $uuid, array $daten) {
    shiptoClearDefault($db, portalCustomerId($db, $uuid));
    resultInfo(true, 'ADDRESS_UPDATED');
}

/** Übersicht des Kontos */
function personalOverview($db, string $uuid, array $daten) {
    resultInfo(true, '', customerOverview($db, portalCustomerId($db, $uuid), portalVarLang($daten)));
}

/** Persönliche Angaben */
function personalProfil($db, string $uuid, array $daten) {
    resultInfo(true, '', customerProfile($db, portalCustomerId($db, $uuid), portalVarLang($daten)));
}

/** Ändert die persönlichen Angaben */
function updatePersonalProfil($db, string $uuid, array $daten) {
    customerUpdateProfile($db, portalCustomerId($db, $uuid), [
        'name'           => portalVar($daten, 'name', ''),
        'company_name'   => portalVar($daten, 'company_name', ''),
        'phone'          => portalVar($daten, 'phone', ''),
        'salutation'     => portalVar($daten, 'salutation', ''),
        'natural_person' => portalVar($daten, 'natural_person', 'true'),
    ]);
    resultInfo(true, 'ACCOUNT_UPDATED');
}

/** Zahlungsarten zur Auswahl */
function personalPayment($db, string $uuid, array $daten) {
    resultInfo(true, '', customerPaymentTerms($db, portalCustomerId($db, $uuid), portalVarLang($daten)));
}

/** Wählt die Zahlungsart */
function changePaymentMethod($db, string $uuid, array $daten) {
    customerSetPaymentTerm($db, portalCustomerId($db, $uuid), portalVarInt($daten, 'payment_id'));
    resultInfo(true, 'ACCOUNT_UPDATED');
}

/** Ändert die E-Mail-Adresse */
function updateEmail($db, string $uuid, array $daten) {
    customerUpdateEmail(
        $db, portalCustomerId($db, $uuid),
        trim((string)portalVar($daten, 'email', '')),
        (string)portalVar($daten, 'password', '')
    );
    resultInfo(true, 'ACCOUNT_UPDATED');
}

/** Ändert das Kennwort */
function updatePassword($db, string $uuid, array $daten) {
    customerUpdatePassword(
        $db, portalCustomerId($db, $uuid),
        (string)portalVar($daten, 'old_password', ''),
        (string)portalVar($daten, 'new_password', '')
    );
    resultInfo(true, 'ACCOUNT_UPDATED');
}

/** Vorbelegung des Kontaktformulars */
function contactInit($db, string $uuid, array $daten) {
    $context = portalContextRequire($db, $uuid);
    $customerId = empty($context['customer_id']) ? null : (int)$context['customer_id'];
    resultInfo(true, '', contactFormDefaults($db, $customerId, portalVarLang($daten)));
}

/** Anfrage aus dem Kontaktformular */
function sendContactMail($db, string $uuid, array $daten, int $website) {
    // Für Menschen unsichtbares Feld: ist es ausgefüllt, war ein Programm am
    // Werk. Wir tun so, als sei alles in Ordnung, verschicken aber nichts.
    if ('' !== trim((string)portalVar($daten, 'website', ''))) {
        resultInfo(true, 'CONTACT_SENT');
        return;
    }

    $versendet = portalSendContactMail($db, $website, [
        'name'  => portalVar($daten, 'name', ''),
        'email' => portalVar($daten, 'email', ''),
        'phone' => portalVar($daten, 'phone', ''),
        'term'  => portalVar($daten, 'term', ''),
    ]);

    $versendet
        ? resultInfo(true, 'CONTACT_SENT')
        : resultInfo(false, 'CONTACT_NOT_SENT', null, 'Die Nachricht konnte nicht versendet werden');
}

// ============================================================================
// BELEGE (lib/documents.php) — Rechnungen, Aufträge, Angebote, Lieferscheine,
// Reklamationen, je nach Einstellung der Webseite
// ============================================================================

/**
 * Belege einer Art — ohne type die erste eingeschaltete Belegart
 *
 * Antwort: name (Kunde), types (eingeschaltete Belegarten, Reihenfolge der
 * Reiter), type (die gelieferte), pdf (gibt es dazu ein PDF), documents (Zeilen)
 */
function personalDocuments($db, string $uuid, array $daten, int $website) {
    $kunde = portalCustomerId($db, $uuid);
    $typ = documentTypeRequire($db, $website, (string)portalVar($daten, 'type', ''));
    resultInfo(true, '', [
        'name'      => customerInvoicingData($db, $kunde)['name'],
        'types'     => websiteDocumentTypes($db, $website),
        'type'      => $typ,
        'pdf'       => documentTypeHasPdf($db, $website, $typ),
        'documents' => customerDocuments($db, $kunde, $typ, $website),
    ]);
}

/** Ein Beleg des Kunden mit Positionen */
function personalDocument($db, string $uuid, array $daten, int $website) {
    $typ = documentTypeRequire($db, $website, (string)portalVar($daten, 'type', ''));
    resultInfo(true, '', customerDocument($db, portalCustomerId($db, $uuid), $typ, portalVarInt($daten, 'id'), $website));
}

/** PDF eines eigenen Belegs */
function downloadDocument($db, string $uuid, array $daten, int $website) {
    $typ = documentTypeRequire($db, $website, (string)portalVar($daten, 'type', ''));
    $id = documentOfCustomer($db, portalCustomerId($db, $uuid), $typ, portalVarInt($daten, 'id'), $website);
    portalSendPdfFile(documentPdf($db, $typ, $id, $website));
}

// ── Rechnungen: die bisherigen Aktionen, gebunden an den Schalter „Rechnungen“ ──

/** Rechnungen des Kunden */
function personalOrders($db, string $uuid, array $daten, int $website) {
    documentTypeRequire($db, $website, 'invoices');
    resultInfo(true, '', customerInvoices($db, portalCustomerId($db, $uuid)));
}

/** Eine Rechnung des Kunden */
function personalOrder($db, string $uuid, array $daten, int $website) {
    documentTypeRequire($db, $website, 'invoices');
    resultInfo(true, '', customerInvoice($db, portalCustomerId($db, $uuid), portalVarInt($daten, 'id'), $website));
}

/** Rechnungs-PDF einer eigenen Rechnung */
function downloadInvoice($db, string $uuid, array $daten, int $website) {
    documentTypeRequire($db, $website, 'invoices');
    portalSendPdf($db, invoiceOfCustomer($db, portalCustomerId($db, $uuid), portalVarInt($daten, 'payment-id')));
}

/** Rechnungs-PDF über den Rechnungslink — auch für Gäste ohne Anmeldung */
function downloadInvoiceLink($db, string $uuid, array $daten, int $website) {
    portalSendPdf($db, invoiceIdByLink($db, (string)portalVar($daten, 'ar-link', ''), $website));
}

// ============================================================================
// FREISCHALTUNG (dev/shop-konto-freischaltung.md)
// ============================================================================

/** Bestandskunde bittet um den Link — Antwort immer gleich */
function requestActivation($db, string $uuid, array $daten, int $website) {
    if ('' !== trim((string)portalVar($daten, 'website', ''))) {
        resultInfo(true, '', ['sent' => true]);
        return;
    }
    resultInfo(true, '', activationRequest($db, $uuid, $website, [
        'phone'          => portalVar($daten, 'phone', ''),
        'zipcode'        => portalVar($daten, 'zipcode', ''),
        'customernumber' => portalVar($daten, 'customernumber', ''),
    ]));
}

/** Was der Kunde zur Kennung sieht */
function activationInfo($db, string $uuid, array $daten) {
    resultInfo(true, '', activationInfoByToken($db, (string)portalVar($daten, 'token', '')));
}

/** Richtet das Konto ein und meldet an */
function completeActivation($db, string $uuid, array $daten, int $website) {
    activationComplete(
        $db, $uuid,
        (string)portalVar($daten, 'token', ''),
        (string)portalVar($daten, 'email', ''),
        (string)portalVar($daten, 'password', '')
    );
    resultInfo(true, 'ACCOUNT_ACTIVATED', portalContextStatus($db, $uuid, $website));
}

/**
 * Die Aktionen, die der Kern zulässt
 *
 * Diese Liste ist die Grenze des öffentlichen Zugangs. Was hier nicht steht
 * — und nicht in der Liste einer aktiven Erweiterung —, ist nicht erreichbar,
 * auch dann nicht, wenn die Funktion geladen ist.
 *
 * @return string[]
 */
function portalPublicActions(): array {
    return [
        // Sitzung
        'getContext', 'portalLogin', 'shopLogin', 'portalLogout', 'shopLogout',
        // Konto
        'getAccountSelections', 'registerAccount', 'accountAddresses', 'updateAddress',
        'newDeliveryAddress', 'getDeliveryAddress', 'updateDeliveryAddress',
        'removeDeliveryAddress', 'standardDeliveryAddress', 'takeBillAddress',
        'personalOverview', 'personalProfil', 'updatePersonalProfil',
        'personalPayment', 'changePaymentMethod', 'updateEmail', 'updatePassword',
        'contactInit', 'sendContactMail',
        // Belege (Rechnungen, Aufträge, Angebote, Lieferscheine, Reklamationen)
        'personalDocuments', 'personalDocument', 'downloadDocument',
        // Rechnungen
        'personalOrders', 'personalOrder', 'downloadInvoice', 'downloadInvoiceLink',
        // Freischaltung
        'requestActivation', 'activationInfo', 'completeActivation',
        // Verbindungstest aus der Webseitenkarte — ohne Sitzung
        'portalPing',
    ];
}

/**
 * Aktionen ohne Besuchersitzung
 *
 * @return string[]
 */
function portalSessionlessActions(): array {
    return ['portalPing'];
}

/**
 * Aktionen, die der Browser von einer fremden Seite aus ruft
 *
 * Für sie vergibt der Zugang keine neue Sitzung, wenn das Cookie fehlt
 * (portalPublicContextUuidVorhanden). Der Kern hat keine; die
 * Shop-Erweiterung meldet den Rücksprung von PayPal.
 *
 * @return string[]
 */
function portalExternalActions(): array {
    return [];
}
