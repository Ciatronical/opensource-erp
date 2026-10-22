<?php
// backend/api/portal/lib/context.php
//
// Die Sitzung eines Besuchers der Webseite. Vollständig getrennt von der
// Mitarbeiter-Sitzung in auth.session_oserp: eine UUID aus dem Cookie
// PORTALCLIENTID, ein Kunde, für den Shop ein Warenkorb — kein Benutzer,
// keine Rechte.
//
// Alle Funktionen bekommen die UUID übergeben und lesen sie nie selbst aus
// dem Cookie. Das ist keine Formsache: der Rückweg von PayPal kommt ohne
// Cookie an und reicht dieselbe UUID als reference_id durch, und das
// Admin-Panel arbeitet ganz ohne Kontext.
//
// Die UUID stammt vom Aufrufer und ist damit frei wählbar. Sie gehört
// deshalb ausnahmslos gebunden — nie in den SQL-Text.
//
// Eine Sitzung gehört einer Webseite (website_id). Dass die Kennung zur
// Webseite der Anfrage passt, prüft der Einstieg (portalPublicContextUuid).
//
// Der Name des Cookies stammt aus der Zeit, als nur der HugoShop Sitzungen
// hatte. Er bleibt, damit laufende Webseiten ihre Sitzungen behalten.

if (!defined('PORTAL_CONTEXT_COOKIE')) {
    define('PORTAL_CONTEXT_COOKIE', 'PORTALCLIENTID');
}

/**
 * Erzeugt eine neue Kennung — für Sitzungen, Warenkörbe, Rechnungslinks
 *
 * @return string 32 Hexzeichen
 */
function portalNewUuid(): string {
    return bin2hex(random_bytes(16));
}

/**
 * Liest den Sitzungskontext
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @return array|false Zeile mit uuid, cart_uuid, customer_id, website_id, guest, shipto_id
 */
function portalContext($db, string $uuid) {
    return $db->getOne(
        "SELECT con.uuid, con.cart_uuid, con.customer_id, con.website_id, con.active,
                COALESCE(cust.portal_guest, true) AS guest,
                cust.portal_shipto_id             AS shipto_id
           FROM portal_context_oserp con
           LEFT JOIN customer_ext cust ON cust.customer_id = con.customer_id
          WHERE con.uuid = :uuid",
        [':uuid' => $uuid]
    );
}

/**
 * Liest den Sitzungskontext und besteht darauf, dass es ihn gibt
 *
 * Der Fehlercode heißt weiter PORTAL_CONTEXT_ERROR: die Widgets der Webseite
 * holen bei diesem Code einmal den Kontext und wiederholen die Aktion.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @return array
 * @throws ApiError PORTAL_CONTEXT_ERROR wenn der Kontext nicht existiert
 */
function portalContextRequire($db, string $uuid): array {
    $context = portalContext($db, $uuid);
    if (!$context) {
        throw new ApiError("PORTAL_CONTEXT_ERROR", 'Keine Sitzung zu dieser Kennung');
    }
    return $context;
}

/**
 * Liest den Kontext und besteht auf einem angemeldeten Kunden
 *
 * Getrennt von portalContextRequire, damit sich „keine Sitzung“ von „nicht
 * angemeldet“ unterscheiden lässt — die Widgets wiederholen nur den ersten
 * Fall nach dem Anlegen des Kontextes.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @return array Kontextzeile, customer_id ist gesetzt
 * @throws ApiError PORTAL_CONTEXT_ERROR, CUSTOMER_NOT_FOUND
 */
function portalContextCustomer($db, string $uuid): array {
    $context = portalContextRequire($db, $uuid);
    if (empty($context['customer_id'])) {
        throw new ApiError("CUSTOMER_NOT_FOUND", 'Kein Kunde in dieser Sitzung');
    }
    return $context;
}

/**
 * Kunde der Sitzung — wirft, wenn niemand angemeldet ist
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @return int
 */
function portalCustomerId($db, string $uuid): int {
    return (int)portalContextCustomer($db, $uuid)['customer_id'];
}

/**
 * Status der Sitzung: Anmeldezustand, dazu was die Erweiterungen anhängen
 *
 * Legt den Kontext an, wenn es ihn noch nicht gibt, und stempelt sonst die
 * Benutzung nach. Das Nachstempeln hält die Sitzung am Leben und stößt
 * zugleich den Aufräum-Trigger an.
 *
 * Der Shop hängt über den Haken context_status den Warenkorbzähler an
 * (cart_pos_count) und stempelt dabei seinen Warenkorb.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param int $website Webseite der Anfrage — gilt nur für eine neue Sitzung
 * @return array{account: bool, context: string, customer: bool}
 */
function portalContextStatus($db, string $uuid, int $website): array {
    $zeile = $db->getOne(
        "WITH gestempelt AS (
             INSERT INTO portal_context_oserp (uuid, active, website_id)
             VALUES (:uuid, NOW(), CAST(:website AS integer))
             ON CONFLICT (uuid) DO UPDATE SET active = NOW()
             RETURNING uuid, cart_uuid, customer_id
         )
         SELECT g.uuid AS context, g.customer_id IS NOT NULL AS customer,
                (g.customer_id IS NOT NULL AND COALESCE(cust.portal_guest, true) = false) AS account
           FROM gestempelt g
           LEFT JOIN customer_ext cust ON cust.customer_id = g.customer_id",
        [':uuid' => $uuid, ':website' => $website]
    );

    $status = [
        'context'  => $uuid,
        'account'  => in_array($zeile['account']  ?? 'f', ['t', true, 1, '1'], true),
        // Kunde in der Sitzung — Konto oder Gast
        'customer' => in_array($zeile['customer'] ?? 'f', ['t', true, 1, '1'], true),
    ];

    return portalHookFilter('context_status', $status, $db, $uuid, $website);
}

/**
 * Setzt den Kunden der Sitzung
 *
 * Nach Anmeldung, Registrierung und Freischaltung. Die Erweiterungen hören
 * über den Haken login zu — der Shop führt dabei den mitgebrachten Warenkorb
 * mit dem des Kunden zusammen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param int $customerId Kunde
 * @return void
 */
function portalContextSetCustomer($db, string $uuid, int $customerId): void {
    $db->execute(
        "UPDATE portal_context_oserp SET customer_id = :customer_id, active = NOW() WHERE uuid = :uuid",
        [':customer_id' => $customerId, ':uuid' => $uuid]
    );
    portalHookRun('login', $db, $uuid, $customerId);
}

/**
 * Meldet einen Kunden an
 *
 * Kundenkonten liegen in customer.user_password (bcrypt) und haben mit
 * auth.user nichts zu tun: ein Kunde ist kein Benutzer des ERP und bekommt
 * weder Rechte noch Zugang zum Admin-Panel.
 *
 * Gastzeilen (portal_guest) sind ausgenommen — sie entstehen bei
 * Bestellungen ohne Konto und haben kein Kennwort.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param string $email E-Mail-Adresse des Kunden
 * @param string $password Kennwort im Klartext
 * @return array{customer_id: int}
 * @throws ApiError PORTAL_CONTEXT_ERROR, ACCOUNT_NOT_FOUND, WRONG_PASSWORD
 */
function portalLoginCustomer($db, string $uuid, string $email, string $password): array {
    portalContextRequire($db, $uuid);

    $konto = $db->getOne(
        "SELECT c.id, c.user_password
           FROM customer c
           JOIN customer_ext ch ON ch.customer_id = c.id
          WHERE lower(c.email) = lower(:email) AND c.obsolete = FALSE AND ch.portal_guest = false
            AND c.user_password IS NOT NULL
          ORDER BY c.id
          LIMIT 1",
        [':email' => trim($email)]
    );

    // Auch ohne Treffer wird geprüft: sonst verrät die Antwortzeit, ob es
    // die Adresse gibt. password_verify läuft dafür gegen einen festen Hash.
    $hash = $konto['user_password'] ?? '$2y$10$usuallyinvalidhashusuallyinvalidhashusuallyinvalidhashuxxx';
    if (!password_verify($password, (string)$hash) || !$konto) {
        throw new ApiError(
            $konto ? "WRONG_PASSWORD" : "ACCOUNT_NOT_FOUND",
            $konto ? 'Kennwort stimmt nicht' : 'Kein Konto zu dieser Adresse'
        );
    }

    $customerId = (int)$konto['id'];
    portalContextSetCustomer($db, $uuid, $customerId);

    return ['customer_id' => $customerId];
}

/**
 * Meldet den Kunden ab
 *
 * Löscht die Sitzung. Was am Kunden hängt (Warenkorb des Shops), bleibt: es
 * steht beim nächsten Anmelden wieder da. Der Aufrufer vergibt anschließend
 * eine neue Kontext-Kennung — die alte ist verbraucht.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @return void
 */
function portalLogoutCustomer($db, string $uuid): void {
    $db->execute("DELETE FROM portal_context_oserp WHERE uuid = :uuid", [':uuid' => $uuid]);
}

/**
 * Setzt das Kontext-Cookie
 *
 * @param string $uuid Kennung
 * @param bool $fremdeHerkunft true bei Zugriff von einer anderen Adresse (CORS)
 * @return void
 */
function portalContextCookie(string $uuid, bool $fremdeHerkunft = false): void {
    setcookie(PORTAL_CONTEXT_COOKIE, $uuid, [
        'expires'  => 0,
        'path'     => '/',
        'secure'   => $fremdeHerkunft || !empty($_SERVER['HTTPS']),
        'httponly' => true,
        // Bei Zugriff von einer anderen Adresse schickt der Browser das
        // Cookie nur mit None; im Proxy-Betrieb bleibt Strict.
        'samesite' => $fremdeHerkunft ? 'None' : 'Strict',
    ]);
    $_COOKIE[PORTAL_CONTEXT_COOKIE] = $uuid;
}
