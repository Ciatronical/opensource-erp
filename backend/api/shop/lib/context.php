<?php
// backend/api/shop/lib/context.php
//
// Die Sitzung eines Shop-Besuchers. Vollstaendig getrennt von der
// Mitarbeiter-Sitzung in auth.session_oserp: eine UUID aus dem Cookie
// HUGOSHOPCLIENTID, ein Warenkorb, ein Kunde — kein Benutzer, keine Rechte.
//
// Alle Funktionen bekommen die UUID uebergeben und lesen sie nie selbst aus
// dem Cookie. Das ist keine Formsache: der Rueckweg von PayPal kommt ohne
// Cookie an und reicht dieselbe UUID als reference_id durch, und das
// Admin-Panel arbeitet ganz ohne Kontext.
//
// Die UUID stammt vom Aufrufer und ist damit frei waehlbar. Sie gehoert
// deshalb ausnahmslos gebunden — nie in den SQL-Text.

/**
 * Erzeugt eine neue Kontext-UUID
 *
 * @return string 32 Hexzeichen
 */
function shopNewContextUuid(): string {
    return bin2hex(random_bytes(16));
}

/**
 * Liest den Sitzungskontext
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @return array|false Zeile mit uuid, cart_uuid, customer_id, guest, shipto_id
 */
function shopContext($db, string $uuid) {
    return $db->getOne(
        "SELECT con.uuid, con.cart_uuid, con.customer_id, con.active,
                COALESCE(cust.hugoshop_guest, true)  AS guest,
                cust.hugoshop_shipto_id              AS shipto_id
           FROM context_hugoshop con
           LEFT JOIN customer_ext cust ON cust.customer_id = con.customer_id
          WHERE con.uuid = :uuid",
        [':uuid' => $uuid]
    );
}

/**
 * Liest den Sitzungskontext und besteht darauf, dass es ihn gibt
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @return array
 * @throws ApiError SHOP_CONTEXT_ERROR wenn der Kontext nicht existiert
 */
function shopContextRequire($db, string $uuid): array {
    $context = shopContext($db, $uuid);
    if (!$context) {
        throw new ApiError("SHOP_CONTEXT_ERROR", 'Keine Sitzung zu dieser Kennung');
    }
    return $context;
}

/**
 * Liest den Kontext und besteht auf einem angemeldeten Kunden
 *
 * Jede Kontofunktion braucht beides. Getrennt von shopContextRequire, damit
 * sich "keine Sitzung" von "nicht angemeldet" unterscheiden laesst — der
 * Shop-Client wiederholt nur den ersten Fall nach dem Anlegen des Kontextes.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @return array Kontextzeile, customer_id ist gesetzt
 * @throws ApiError SHOP_CONTEXT_ERROR, CUSTOMER_NOT_FOUND
 */
function shopContextCustomer($db, string $uuid): array {
    $context = shopContextRequire($db, $uuid);
    if (empty($context['customer_id'])) {
        throw new ApiError("CUSTOMER_NOT_FOUND", 'Kein Kunde in dieser Sitzung');
    }
    return $context;
}

/**
 * Status der Sitzung: Anzahl der Warenkorbpositionen und Anmeldezustand
 *
 * Legt den Kontext an, wenn es ihn noch nicht gibt, und stempelt sonst die
 * Benutzung nach. Das Nachstempeln haelt die Sitzung am Leben und stoesst
 * zugleich die beiden Aufraeum-Trigger an; deshalb geschieht es auch dann,
 * wenn der Kontext gerade erst entstanden ist.
 *
 * Ein Vorgang: das INSERT ... ON CONFLICT legt an oder stempelt nach, die
 * Auskunft kommt aus demselben Statement.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @return array{cart_pos_count: int, account: bool, context: string}
 */
function shopContextStatus($db, string $uuid): array {
    $zeile = $db->getOne(
        "WITH gestempelt AS (
             INSERT INTO context_hugoshop (uuid, active) VALUES (:uuid, NOW())
             ON CONFLICT (uuid) DO UPDATE SET active = NOW()
             RETURNING uuid, cart_uuid, customer_id
         ), korb AS (
             UPDATE carts_hugoshop c SET active = NOW()
               FROM gestempelt g WHERE c.uuid = g.cart_uuid
             RETURNING c.uuid
         )
         SELECT g.uuid AS context,
                (SELECT COUNT(*) FROM cart_parts_hugoshop p WHERE p.cart_uuid = g.cart_uuid) AS cart_pos_count,
                (g.customer_id IS NOT NULL AND COALESCE(cust.hugoshop_guest, true) = false) AS account
           FROM gestempelt g
           LEFT JOIN customer_ext cust ON cust.customer_id = g.customer_id",
        [':uuid' => $uuid]
    );

    return [
        'cart_pos_count' => (int)($zeile['cart_pos_count'] ?? 0),
        'account'        => in_array($zeile['account'] ?? 'f', ['t', true, 1, '1'], true),
        'context'        => $uuid,
    ];
}

/**
 * Meldet einen Shop-Kunden an
 *
 * Kundenkonten liegen in customer.user_password (bcrypt) und haben mit
 * auth.user nichts zu tun: ein Shop-Kunde ist kein Benutzer des ERP und
 * bekommt weder Rechte noch Zugang zum Admin-Panel.
 *
 * Gastzeilen (hugoshop_guest) sind ausgenommen — sie entstehen bei
 * Bestellungen ohne Konto und haben kein Passwort.
 *
 * Nach der Anmeldung werden die Warenkoerbe zusammengefuehrt, siehe
 * cartMergeIntoCustomerCart().
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param string $email E-Mail-Adresse des Kunden
 * @param string $password Kennwort im Klartext
 * @return array{customer_id: int}
 * @throws ApiError SHOP_CONTEXT_ERROR, ACCOUNT_NOT_FOUND, WRONG_PASSWORD
 */
function shopLogin($db, string $uuid, string $email, string $password): array {
    shopContextRequire($db, $uuid);

    $konto = $db->getOne(
        "SELECT c.id, c.user_password
           FROM customer c
           JOIN customer_ext ch ON ch.customer_id = c.id
          WHERE c.email = :email AND c.obsolete = FALSE AND ch.hugoshop_guest = false",
        [':email' => $email]
    );

    // Auch ohne Treffer wird geprueft: sonst verraet die Antwortzeit, ob es
    // die Adresse gibt. password_verify laeuft dafuer gegen einen festen Hash.
    $hash = $konto['user_password'] ?? '$2y$10$usuallyinvalidhashusuallyinvalidhashusuallyinvalidhashuxxx';
    if (!password_verify($password, (string)$hash) || !$konto) {
        throw new ApiError(
            $konto ? "WRONG_PASSWORD" : "ACCOUNT_NOT_FOUND",
            $konto ? 'Kennwort stimmt nicht' : 'Kein Konto zu dieser Adresse'
        );
    }

    $customerId = (int)$konto['id'];

    $db->execute(
        "UPDATE context_hugoshop SET customer_id = :customer_id, active = NOW() WHERE uuid = :uuid",
        [':customer_id' => $customerId, ':uuid' => $uuid]
    );

    cartMergeIntoCustomerCart($db, $uuid, $customerId);

    return ['customer_id' => $customerId];
}

/**
 * Meldet den Shop-Kunden ab
 *
 * Loescht die Sitzung. Der Warenkorb bleibt: er haengt am Kunden und steht
 * beim naechsten Anmelden wieder da. Der Aufrufer vergibt anschliessend eine
 * neue Kontext-Kennung — die alte ist verbraucht.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @return void
 */
function shopLogout($db, string $uuid): void {
    $db->execute("DELETE FROM context_hugoshop WHERE uuid = :uuid", [':uuid' => $uuid]);
}

/**
 * Adresse der Artikelseite im Shop
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @return string Zielseite, klein geschrieben; leer wenn nicht gepflegt
 */
function shopProductLink($db, int $partsId): string {
    $zeile = $db->getOne(
        "SELECT hugoshop_hyperlink FROM parts_ext WHERE parts_id = :parts_id",
        [':parts_id' => $partsId]
    );
    return mb_strtolower((string)($zeile['hugoshop_hyperlink'] ?? ''));
}
