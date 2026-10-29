<?php
// backend/api/portal/lib/account.php
//
// Das Kundenkonto. Ein Kunde des Portals ist ein gewöhnlicher
// kivitendo-Kunde (Tabelle customer) mit einem Kennwort in
// customer.user_password — kein Benutzer des ERP. Verwaltet werden diese
// Kunden im Admin-Panel über die normale Kundenverwaltung; hier steht nur,
// was der Kunde selbst auf der Webseite tun kann.
//
// Gastbestellungen des Shops legen ebenfalls eine Kundenzeile an,
// gekennzeichnet über customer_ext.portal_guest. Sie haben kein Kennwort und
// können sich deshalb nicht anmelden.
//
// Lieferadressen liegen in shipto; shipto.trans_id ist der Eigentümer. Jede
// Funktion, die eine shipto_id entgegennimmt, führt sie in der Bedingung mit
// — ohne das ließe sich durch Hochzählen der Kennung jede fremde
// Lieferadresse lesen, ändern oder löschen.
//
// Dazu gehört immer das Modul: Adressen des Kundenstamms haben module 'CT'.
// Seit dev/shop-adressen.md hat jede Rechnung ihre eigene Lieferadresse mit
// module 'AR' und trans_id = Rechnung — ohne den Filter sähe ein Kunde die
// Lieferadresse der Rechnung, deren Nummer zufällig seiner Kundennummer
// entspricht.

/**
 * Steuerzone und Währung für neue Kunden und Besucher ohne Konto
 *
 * Der Kern kennt keine eigene Einstellung dafür: die Erweiterungen tragen
 * ihre bei (Haken customer_defaults — der Shop seine shop_standard_*). Ohne
 * Beitrag gilt die erste Steuerzone und die Währung des Mandanten.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array{taxzone: string, currency: string}
 */
function portalCustomerDefaults($db): array {
    $werte = portalHookFilter('customer_defaults', ['taxzone' => '', 'currency' => ''], $db);
    return [
        'taxzone'  => (string)($werte['taxzone']  ?? ''),
        'currency' => (string)($werte['currency'] ?? ''),
    ];
}

/**
 * Stammdaten für Preisberechnung und Rechnungsstellung
 *
 * Mit Kunde dessen Steuerzone und Währung, ohne Kunde die Vorgaben des
 * Mandanten — ein Besucher ohne Konto sieht Preise, bevor er sich anmeldet.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int|null $customerId Kunde, oder null für die Vorgaben
 * @return array precision, taxzone_id, currency_id, currency (+ Kundenfelder)
 * @throws ApiError ACCOUNT_NOT_FOUND, INVOICING_DEFAULTS_NOT_FOUND
 */
function customerInvoicingData($db, ?int $customerId): array {
    if (null === $customerId) {
        $vorgaben = $db->getOne(
            // precision ist der Rundungsschritt und steht im Nenner. Ist er 0
            // oder nicht gesetzt — auf einer frisch aufgesetzten Datenbank
            // kommt das vor —, scheitert jede Preisabfrage an einer Division
            // durch Null.
            "SELECT COALESCE(NULLIF(d.precision, 0), 0.01) AS precision, d.currency_id,
                    (SELECT name FROM currencies WHERE id = d.currency_id) AS currency,
                    COALESCE(
                        (SELECT id FROM tax_zones
                          WHERE :taxzone <> '' AND description ILIKE :taxzone ORDER BY sortkey LIMIT 1),
                        (SELECT id FROM tax_zones ORDER BY sortkey LIMIT 1)
                    ) AS taxzone_id
               FROM defaults d",
            [':taxzone' => portalCustomerDefaults($db)['taxzone']]
        );
        if (!$vorgaben) {
            throw new ApiError("INVOICING_DEFAULTS_NOT_FOUND", 'Keine Mandanten-Vorgaben gefunden');
        }
        return $vorgaben;
    }

    $kunde = $db->getOne(
        "SELECT COALESCE(NULLIF((SELECT precision FROM defaults), 0), 0.01) AS precision,
                c.id AS customer_id, c.name, c.email, c.phone, c.street, c.zipcode, c.city,
                c.country, c.greeting, c.customernumber, c.natural_person, c.contact,
                c.taxzone_id, c.currency_id, c.payment_id,
                cur.name AS currency,
                COALESCE(ch.portal_guest, false) AS guest, ch.portal_shipto_id AS shipto_id
           FROM customer c
           LEFT JOIN customer_ext ch ON ch.customer_id = c.id
           JOIN currencies cur ON cur.id = c.currency_id
          WHERE c.id = :customer_id",
        [':customer_id' => $customerId]
    );

    if (!$kunde) {
        throw new ApiError("ACCOUNT_NOT_FOUND", 'Kein Konto zu dieser Kennung');
    }
    return $kunde;
}

/**
 * Anreden in der gewünschten Sprache
 *
 * Braucht weder Kontext noch Kunde und dient auch dem Admin-Panel.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $sprache Sprachkürzel, z.B. 'de'
 * @return array Liste aus id und translation
 */
function salutations($db, string $sprache = 'de'): array {
    return $db->getAll(
        "SELECT gt.id, gt.translation
           FROM generic_translations gt
           JOIN language lang ON lang.id = gt.language_id
          WHERE lang.template_code = :sprache
            AND gt.translation_type ILIKE 'greetings%'",
        [':sprache' => $sprache]
    );
}

/**
 * Gibt es schon ein Konto mit dieser Adresse?
 *
 * Gastzeilen zählen nicht: ihre Adresse darf ein Konto übernehmen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $email Adresse
 * @param int $ausser Kunde, der nicht zählt (der eigene)
 * @return bool
 */
function customerEmailTaken($db, string $email, int $ausser = 0): bool {
    $zeile = $db->getOne(
        "SELECT 1 AS da FROM customer c
           JOIN customer_ext ch ON ch.customer_id = c.id
          WHERE ch.portal_guest = false AND c.user_password IS NOT NULL
            AND lower(c.email) = lower(:email) AND c.id <> :ausser
          LIMIT 1",
        [':email' => trim($email), ':ausser' => $ausser]
    );
    return (bool)$zeile;
}

/**
 * Legt ein Kundenkonto oder eine Gastbestellung an
 *
 * Die Kundennummer kommt aus nextFreeNumber() und damit aus dem
 * Nummernkreis-Mechanismus von OpensourceERP.
 *
 * Das Konto bekommt das Kennwort, der Gast keines.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param array $daten name, company_name, street, zipcode, city, country, phone,
 *                     email, password, salutation, account_type, guest,
 *                     shipping (optionales Feld mit der Lieferadresse)
 * @return array{customer_id: int, shipto_id: int|null}
 * @throws ApiError PORTAL_CONTEXT_ERROR, ACCOUNT_EXISTS, PASSWORD_REQUIRED, PORTAL_DATABASE_ERROR
 */
function customerRegister($db, string $uuid, array $daten): array {
    portalContextRequire($db, $uuid);

    $gast = !empty($daten['guest']);
    $email = trim((string)($daten['email'] ?? ''));

    if (!$gast && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiError("INVALID_EMAIL", 'Die E-Mail-Adresse ist unbrauchbar');
    }
    if (customerEmailTaken($db, $email)) {
        throw new ApiError("ACCOUNT_EXISTS", 'Zu dieser Adresse gibt es schon ein Konto');
    }
    if (!$gast && portalAccountLockedByEmail($db, $email)) {
        throw new ApiError("ACCOUNT_LOCKED", 'Der Zugang zu dieser Adresse ist gesperrt');
    }

    // Firmenkonto: der Firmenname steht im Namen, die Person im Ansprechpartner
    $firmenkonto = isset($daten['account_type']) && 'false' === (string)$daten['account_type'];
    $name    = $firmenkonto ? ($daten['company_name'] ?? '') : ($daten['name'] ?? '');
    $contact = $firmenkonto ? ($daten['name'] ?? '') : '';

    $kennwort = null;
    if (!$gast) {
        $klartext = (string)($daten['password'] ?? '');
        if ('' === $klartext) {
            throw new ApiError("PASSWORD_REQUIRED", 'Ein Konto braucht ein Kennwort');
        }
        $kennwort = password_hash($klartext, PASSWORD_DEFAULT);
    }

    $vorgaben = portalCustomerDefaults($db);

    $db->beginTransaction();
    try {
        $kundennummer = nextFreeNumber($db, 'customernumber', 'customer', 'customernumber');

        $kunde = $db->getOne(
            "INSERT INTO customer
                    (name, street, zipcode, city, country, phone, email, user_password,
                     greeting, customernumber, taxzone_id, currency_id, natural_person, contact)
             SELECT :name, :street, :zipcode, :city, :country, :phone, :email, :user_password,
                    :greeting, :customernumber,
                    COALESCE((SELECT id FROM tax_zones WHERE :taxzone <> '' AND description ILIKE :taxzone ORDER BY sortkey LIMIT 1),
                             (SELECT id FROM tax_zones ORDER BY sortkey LIMIT 1)),
                    COALESCE((SELECT id FROM currencies WHERE :currency <> '' AND name ILIKE :currency LIMIT 1),
                             (SELECT currency_id FROM defaults)),
                    :natural_person, :contact
             RETURNING id",
            [
                ':name'           => $name,
                ':street'         => $daten['street']   ?? '',
                ':zipcode'        => $daten['zipcode']  ?? '',
                ':city'           => $daten['city']     ?? '',
                ':country'        => $daten['country']  ?? '',
                ':phone'          => $daten['phone']    ?? '',
                ':email'          => $email,
                ':user_password'  => $kennwort,
                ':greeting'       => $daten['salutation'] ?? '',
                ':customernumber' => $kundennummer,
                ':taxzone'        => $vorgaben['taxzone'],
                ':currency'       => $vorgaben['currency'],
                ':natural_person' => !$firmenkonto,
                ':contact'        => $contact,
            ]
        );

        $customerId = (int)$kunde['id'];

        $db->execute(
            "INSERT INTO customer_ext (customer_id, portal_guest) VALUES (:customer_id, :guest)
             ON CONFLICT (customer_id) DO UPDATE SET portal_guest = EXCLUDED.portal_guest",
            [':customer_id' => $customerId, ':guest' => $gast]
        );

        // Die Kennung der eben angelegten Lieferadresse geht an den Aufrufer
        // zurück: ohne sie legte die Rechnungsstellung bei einer
        // Gastbestellung eine zweite, gleichlautende shipto-Zeile an.
        $shiptoId = null;
        if (!empty($daten['shipping'])) {
            $adresse = shiptoCreate($db, $customerId, $daten['shipping']);
            $shiptoId = $adresse['shipto_id'];
            $db->execute(
                "UPDATE customer_ext SET portal_shipto_id = :shipto_id WHERE customer_id = :customer_id",
                [':shipto_id' => $shiptoId, ':customer_id' => $customerId]
            );
        }

        $db->commit();
    } catch (ApiError $e) {
        $db->rollBack();
        throw $e;
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError("PORTAL_DATABASE_ERROR", 'Konto konnte nicht angelegt werden: '.$e->getMessage());
    }

    // In die Sitzung setzen — die Erweiterungen hören zu (Warenkorb des Shops)
    portalContextSetCustomer($db, $uuid, $customerId);

    return ['customer_id' => $customerId, 'shipto_id' => $shiptoId];
}

/**
 * Rechnungsadresse und alle Lieferadressen des Kunden
 *
 * "used" sagt, ob die Lieferadresse an einer Rechnung hängt. Eine benutzte
 * Adresse zu löschen würde die Rechnung ihrer Anschrift berauben.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @return array
 * @throws ApiError ACCOUNT_NOT_FOUND
 */
function customerAddresses($db, int $customerId): array {
    $kunde = customerInvoicingData($db, $customerId);

    $lieferadressen = $db->getAll(
        "SELECT s.shipto_id, s.shiptoname, s.shiptostreet, s.shiptozipcode, s.shiptocity,
                s.shiptocountry, s.shiptophone, s.shiptoemail,
                EXISTS (SELECT 1 FROM ar WHERE ar.customer_id = :customer_id
                                           AND ar.shipto_id = s.shipto_id) AS used
           FROM shipto s
          WHERE s.trans_id = :customer_id AND COALESCE(s.module, 'CT') = 'CT'
          ORDER BY s.shipto_id",
        [':customer_id' => $customerId]
    );

    return [
        'name'               => $kunde['name'],
        'phone'              => $kunde['phone'],
        'email'              => $kunde['email'],
        'salutation'         => $kunde['greeting'],
        'street'             => $kunde['street'],
        'zipcode'            => $kunde['zipcode'],
        'city'               => $kunde['city'],
        'country'            => $kunde['country'],
        'shipto_default'     => $kunde['shipto_id'],
        'shipping_addresses' => $lieferadressen,
    ];
}

/**
 * Ändert die Rechnungsadresse des Kunden
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param array $adresse street, zipcode, city, country
 * @return void
 */
function customerUpdateBillingAddress($db, int $customerId, array $adresse): void {
    $db->execute(
        "UPDATE customer SET street = :street, zipcode = :zipcode, city = :city, country = :country
          WHERE id = :customer_id",
        [
            ':street'      => $adresse['street']  ?? '',
            ':zipcode'     => $adresse['zipcode'] ?? '',
            ':city'        => $adresse['city']    ?? '',
            ':country'     => $adresse['country'] ?? '',
            ':customer_id' => $customerId,
        ]
    );
}

/**
 * Legt eine Lieferadresse an
 *
 * RETURNING statt lastInsertId(): ohne Sequenznamen liefert das bei
 * PostgreSQL lastval() und hängt damit an der Reihenfolge aller Sequenzen
 * dieser Sitzung.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Eigentümer der Adresse
 * @param array $adresse name, street, zipcode, city, country, phone, email
 * @return array{shipto_id: int}
 */
function shiptoCreate($db, int $customerId, array $adresse): array {
    $zeile = $db->getOne(
        "INSERT INTO shipto (trans_id, shiptoname, shiptostreet, shiptozipcode, shiptocity,
                             shiptocountry, shiptophone, shiptoemail, module)
         VALUES (:customer_id, :name, :street, :zipcode, :city, :country, :phone, :email, 'CT')
         RETURNING shipto_id",
        [
            ':customer_id' => $customerId,
            ':name'        => $adresse['name']    ?? '',
            ':street'      => $adresse['street']  ?? '',
            ':zipcode'     => $adresse['zipcode'] ?? '',
            ':city'        => $adresse['city']    ?? '',
            ':country'     => $adresse['country'] ?? '',
            ':phone'       => $adresse['phone']   ?? '',
            ':email'       => $adresse['email']   ?? '',
        ]
    );

    return ['shipto_id' => (int)$zeile['shipto_id']];
}

/**
 * Liest eine Lieferadresse des Kunden
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Eigentümer
 * @param int $shiptoId Lieferadresse
 * @return array
 * @throws ApiError ADDRESS_NOT_FOUND wenn es sie nicht gibt oder sie einem anderen gehört
 */
function shiptoRead($db, int $customerId, int $shiptoId): array {
    $adresse = $db->getOne(
        "SELECT shipto_id, shiptoname, shiptostreet, shiptozipcode, shiptocity,
                shiptocountry, shiptophone, shiptoemail
           FROM shipto WHERE shipto_id = :shipto_id AND trans_id = :customer_id
                         AND COALESCE(module, 'CT') = 'CT'",
        [':shipto_id' => $shiptoId, ':customer_id' => $customerId]
    );

    if (!$adresse) {
        throw new ApiError("ADDRESS_NOT_FOUND", 'Diese Lieferadresse gibt es nicht');
    }
    return $adresse;
}

/**
 * Ändert eine Lieferadresse des Kunden
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Eigentümer
 * @param int $shiptoId Lieferadresse
 * @param array $adresse name, street, zipcode, city, country, phone, email
 * @return void
 * @throws ApiError ADDRESS_NOT_FOUND
 */
function shiptoUpdate($db, int $customerId, int $shiptoId, array $adresse): void {
    $zeile = $db->getOne(
        "UPDATE shipto SET shiptoname = :name, shiptostreet = :street, shiptozipcode = :zipcode,
                           shiptocity = :city, shiptocountry = :country, shiptophone = :phone,
                           shiptoemail = :email
          WHERE shipto_id = :shipto_id AND trans_id = :customer_id
            AND COALESCE(module, 'CT') = 'CT'
         RETURNING shipto_id",
        [
            ':name'        => $adresse['name']    ?? '',
            ':street'      => $adresse['street']  ?? '',
            ':zipcode'     => $adresse['zipcode'] ?? '',
            ':city'        => $adresse['city']    ?? '',
            ':country'     => $adresse['country'] ?? '',
            ':phone'       => $adresse['phone']   ?? '',
            ':email'       => $adresse['email']   ?? '',
            ':shipto_id'   => $shiptoId,
            ':customer_id' => $customerId,
        ]
    );

    // Ohne diese Prüfung meldete die Oberfläche eine Änderung, die es nicht gab
    if (!$zeile) {
        throw new ApiError("ADDRESS_NOT_FOUND", 'Diese Lieferadresse gibt es nicht');
    }
}

/**
 * Löscht eine Lieferadresse des Kunden
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Eigentümer
 * @param int $shiptoId Lieferadresse
 * @return void
 * @throws ApiError ADDRESS_NOT_FOUND
 */
function shiptoDelete($db, int $customerId, int $shiptoId): void {
    $zeile = $db->getOne(
        "DELETE FROM shipto WHERE shipto_id = :shipto_id AND trans_id = :customer_id
            AND COALESCE(module, 'CT') = 'CT'
         RETURNING shipto_id",
        [':shipto_id' => $shiptoId, ':customer_id' => $customerId]
    );

    if (!$zeile) {
        throw new ApiError("ADDRESS_NOT_FOUND", 'Diese Lieferadresse gibt es nicht');
    }
}

/**
 * Wählt die Standard-Lieferadresse
 *
 * Die Zugehörigkeit wird geprüft, bevor gesetzt wird: sonst ließe sich eine
 * fremde Lieferadresse zur eigenen Standardadresse machen, und die nächste
 * Bestellung ginge dorthin.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param int $shiptoId Lieferadresse
 * @return void
 * @throws ApiError ADDRESS_NOT_FOUND
 */
function shiptoSetDefault($db, int $customerId, int $shiptoId): void {
    $zeile = $db->getOne(
        "INSERT INTO customer_ext (customer_id, portal_shipto_id)
         SELECT :customer_id, :shipto_id
          WHERE EXISTS (SELECT 1 FROM shipto
                         WHERE shipto_id = :shipto_id AND trans_id = :customer_id
                           AND COALESCE(module, 'CT') = 'CT')
         ON CONFLICT (customer_id) DO UPDATE SET portal_shipto_id = EXCLUDED.portal_shipto_id
         RETURNING portal_shipto_id",
        [':shipto_id' => $shiptoId, ':customer_id' => $customerId]
    );

    if (!$zeile) {
        throw new ApiError("ADDRESS_NOT_FOUND", 'Diese Lieferadresse gibt es nicht');
    }
}

/**
 * Nimmt die Standard-Lieferadresse zurück — geliefert wird an die Rechnungsadresse
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @return void
 */
function shiptoClearDefault($db, int $customerId): void {
    $db->execute(
        "UPDATE customer_ext SET portal_shipto_id = NULL WHERE customer_id = :customer_id",
        [':customer_id' => $customerId]
    );
}

/**
 * Übersicht des Kundenkontos: Anschriften und Zahlungsart
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $sprache Sprachkürzel für die Bezeichnung der Zahlungsart
 * @return array
 */
function customerOverview($db, int $customerId, string $sprache = 'de'): array {
    $daten = customerAddresses($db, $customerId);
    $kunde = customerInvoicingData($db, $customerId);

    $daten['payment_method'] = $db->getOne(
        "SELECT pt.id, pt.description, COALESCE(gt.translation, pt.description_long) AS description_long
           FROM payment_terms pt
           LEFT JOIN generic_translations gt
                  ON gt.translation_id = pt.id
                 AND gt.translation_type = 'SL::DB::PaymentTerm/description_long'
                 AND gt.language_id = (SELECT id FROM language WHERE template_code = :sprache)
          WHERE pt.id = :payment_id AND pt.obsolete = FALSE",
        [':payment_id' => $kunde['payment_id'], ':sprache' => $sprache]
    ) ?: null;

    return $daten;
}

/**
 * Persönliche Angaben des Kunden
 *
 * Bei einem Firmenkonto steht der Firmenname im Namen und die Person im
 * Ansprechpartner; die Oberfläche zeigt beides getrennt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $sprache Sprachkürzel für die Anreden
 * @return array
 */
function customerProfile($db, int $customerId, string $sprache = 'de'): array {
    $kunde = customerInvoicingData($db, $customerId);
    $natuerlichePerson = in_array($kunde['natural_person'], ['t', true, 1, '1'], true);

    return [
        'company_name'   => $natuerlichePerson ? '' : $kunde['name'],
        'name'           => $natuerlichePerson ? $kunde['name'] : $kunde['contact'],
        'phone'          => $kunde['phone'],
        'email'          => $kunde['email'],
        'customernumber' => $kunde['customernumber'],
        'natural_person' => $natuerlichePerson,
        'salutation'     => $kunde['greeting'],
        'salutations'    => salutations($db, $sprache),
    ];
}

/**
 * Ändert die persönlichen Angaben
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param array $daten name, company_name, phone, salutation, natural_person
 * @return void
 */
function customerUpdateProfile($db, int $customerId, array $daten): void {
    $firmenkonto = isset($daten['natural_person']) && 'false' === (string)$daten['natural_person'];

    $db->execute(
        "UPDATE customer SET name = :name, phone = :phone, greeting = :greeting,
                             natural_person = :natural_person, contact = :contact
          WHERE id = :customer_id",
        [
            ':name'           => $firmenkonto ? ($daten['company_name'] ?? '') : ($daten['name'] ?? ''),
            ':phone'          => $daten['phone'] ?? '',
            ':greeting'       => $daten['salutation'] ?? '',
            ':natural_person' => !$firmenkonto,
            ':contact'        => $firmenkonto ? ($daten['name'] ?? '') : '',
            ':customer_id'    => $customerId,
        ]
    );
}

/**
 * Zahlungsarten zur Auswahl, samt der aktuell gewählten
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $sprache Sprachkürzel
 * @return array
 */
function customerPaymentTerms($db, int $customerId, string $sprache = 'de'): array {
    $kunde = customerInvoicingData($db, $customerId);

    return [
        'name'            => $kunde['name'],
        'default_payment' => $kunde['payment_id'],
        'payment_methods' => $db->getAll(
            "SELECT pt.id, pt.description,
                    COALESCE(gt.translation, pt.description_long) AS description_long
               FROM payment_terms pt
               LEFT JOIN generic_translations gt
                      ON gt.translation_id = pt.id
                     AND gt.translation_type = 'SL::DB::PaymentTerm/description_long'
                     AND gt.language_id = (SELECT id FROM language WHERE template_code = :sprache)
              WHERE pt.obsolete = FALSE
              ORDER BY pt.sortkey",
            [':sprache' => $sprache]
        ),
    ];
}

/**
 * Wählt die Zahlungsart des Kunden
 *
 * Nur Zahlungsarten, die es gibt und die nicht ausgemustert sind.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param int $paymentId Zahlungsart
 * @return void
 * @throws ApiError PAYMENT_TERM_NOT_FOUND
 */
function customerSetPaymentTerm($db, int $customerId, int $paymentId): void {
    $zeile = $db->getOne(
        "UPDATE customer SET payment_id = :payment_id
          WHERE id = :customer_id
            AND EXISTS (SELECT 1 FROM payment_terms WHERE id = :payment_id AND obsolete = FALSE)
         RETURNING payment_id",
        [':payment_id' => $paymentId, ':customer_id' => $customerId]
    );

    if (!$zeile) {
        throw new ApiError("PAYMENT_TERM_NOT_FOUND", 'Diese Zahlungsart gibt es nicht');
    }
}

/**
 * Ändert die E-Mail-Adresse
 *
 * Die Adresse ist zugleich der Anmeldename, deshalb das Kennwort zur
 * Bestätigung und die Prüfung auf Eindeutigkeit.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $email Neue Adresse
 * @param string $password Kennwort zur Bestätigung
 * @return void
 * @throws ApiError ACCOUNT_NOT_FOUND, WRONG_PASSWORD, INVALID_EMAIL, EMAIL_EXISTS
 */
function customerUpdateEmail($db, int $customerId, string $email, string $password): void {
    customerVerifyPassword($db, $customerId, $password);

    if (!filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
        throw new ApiError("INVALID_EMAIL", 'Die E-Mail-Adresse ist unbrauchbar');
    }
    if (customerEmailTaken($db, $email, $customerId)) {
        throw new ApiError("EMAIL_EXISTS", 'Diese Adresse wird bereits verwendet');
    }

    $db->execute(
        "UPDATE customer SET email = :email WHERE id = :customer_id",
        [':email' => trim($email), ':customer_id' => $customerId]
    );
}

/**
 * Ändert das Kennwort
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $alt Bisheriges Kennwort
 * @param string $neu Neues Kennwort
 * @return void
 * @throws ApiError ACCOUNT_NOT_FOUND, WRONG_PASSWORD, PASSWORD_REQUIRED
 */
function customerUpdatePassword($db, int $customerId, string $alt, string $neu): void {
    customerVerifyPassword($db, $customerId, $alt);
    customerSetPassword($db, $customerId, $neu);
}

/**
 * Setzt das Kennwort ohne Prüfung des alten — Freischaltung, Kennwort vergessen
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $neu Neues Kennwort
 * @return void
 * @throws ApiError PASSWORD_REQUIRED
 */
function customerSetPassword($db, int $customerId, string $neu): void {
    if ('' === trim($neu)) {
        throw new ApiError("PASSWORD_REQUIRED", 'Das neue Kennwort darf nicht leer sein');
    }

    $db->execute(
        "UPDATE customer SET user_password = :user_password WHERE id = :customer_id",
        [':user_password' => password_hash($neu, PASSWORD_DEFAULT), ':customer_id' => $customerId]
    );
}

/**
 * Prüft das Kennwort des Kunden
 *
 * Eine Gastzeile hat kein Kennwort; dort schlägt die Prüfung immer fehl,
 * ohne dass password_verify mit null aufgerufen wird.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $password Kennwort im Klartext
 * @return void
 * @throws ApiError ACCOUNT_NOT_FOUND, WRONG_PASSWORD
 */
function customerVerifyPassword($db, int $customerId, string $password): void {
    $konto = $db->getOne(
        "SELECT user_password FROM customer WHERE id = :customer_id",
        [':customer_id' => $customerId]
    );

    if (!$konto) {
        throw new ApiError("ACCOUNT_NOT_FOUND", 'Kein Konto zu dieser Kennung');
    }
    if (null === $konto['user_password'] || !password_verify($password, (string)$konto['user_password'])) {
        throw new ApiError("WRONG_PASSWORD", 'Kennwort stimmt nicht');
    }
}

/**
 * Vorbelegung des Kontaktformulars
 *
 * Ein angemeldeter Kunde muss seine Angaben nicht erneut eintippen. Ohne
 * Konto bleiben die Felder leer.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int|null $customerId Kunde, oder null
 * @param string $sprache Sprachkürzel
 * @return array
 */
function contactFormDefaults($db, ?int $customerId, string $sprache = 'de'): array {
    $daten = [
        'name'         => '',
        'company_name' => '',
        'email'        => '',
        'phone'        => '',
        'salutation'   => '',
        'salutations'  => salutations($db, $sprache),
    ];

    if (null === $customerId) {
        return $daten;
    }

    $kunde = customerInvoicingData($db, $customerId);
    $natuerlichePerson = in_array($kunde['natural_person'], ['t', true, 1, '1'], true);

    $daten['company_name'] = $natuerlichePerson ? '' : $kunde['name'];
    $daten['name']         = $natuerlichePerson ? $kunde['name'] : $kunde['contact'];
    $daten['email']        = $kunde['email'];
    $daten['phone']        = $kunde['phone'];
    $daten['salutation']   = $kunde['greeting'];

    return $daten;
}

// ============================================================================
// SPERRE UND ZURÜCKSETZEN (Kundenakte, Reiter „Kundenkonto“)
// ============================================================================
//
// Die Sperre steht in customer_ext.portal_locked_at — getrennt von
// customer.obsolete, damit ein gesperrter Kunde ein gültiger Kunde bleibt
// (Belege, Fahrzeuge, Auswertungen). Gesperrt heißt: keine Anmeldung, keine
// Freischaltung, keine Registrierung mit seiner Adresse; laufende Sitzungen
// enden beim Sperren.
//
// Zurücksetzen löscht nur das Kennwort. Der Kunde ist danach wieder ein
// Kunde ohne Konto, den die Konto-Freischaltung findet
// (activationFindCustomer) — er richtet E-Mail-Adresse und Kennwort neu
// ein. Eine Sperre bleibt dabei bestehen und wird eigens aufgehoben.

/**
 * Ist der Zugang des Kunden gesperrt?
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @return bool
 */
function portalAccountLocked($db, int $customerId): bool {
    $zeile = $db->getOne(
        "SELECT 1 AS da FROM customer_ext WHERE customer_id = :id AND portal_locked_at IS NOT NULL",
        [':id' => $customerId]
    );
    return (bool)$zeile;
}

/**
 * Gehört die Adresse einem gesperrten Kunden?
 *
 * Für die Registrierung: sonst legte sich ein gesperrter Kunde mit derselben
 * Adresse ein zweites Konto an.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $email Adresse
 * @return bool
 */
function portalAccountLockedByEmail($db, string $email): bool {
    $zeile = $db->getOne(
        "SELECT 1 AS da FROM customer c
           JOIN customer_ext ce ON ce.customer_id = c.id
          WHERE ce.portal_locked_at IS NOT NULL AND lower(c.email) = lower(:email)
          LIMIT 1",
        [':email' => trim($email)]
    );
    return (bool)$zeile;
}

/**
 * Beendet alle Sitzungen des Kunden und entwertet offene Freischaltungs-Kennungen
 *
 * Wie beim Abmelden bleibt, was am Kunden hängt (Warenkorb des Shops).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @return void
 */
function portalAccountEndSessions($db, int $customerId): void {
    $db->execute("DELETE FROM portal_context_oserp WHERE customer_id = :id", [':id' => $customerId]);
    $db->execute(
        "UPDATE portal_activation_oserp SET used_at = NOW() WHERE customer_id = :id AND used_at IS NULL",
        [':id' => $customerId]
    );
}

/**
 * Sperrt den Zugang oder hebt die Sperre auf
 *
 * Sperren beendet die Sitzungen des Kunden. Kennwort und E-Mail-Adresse
 * bleiben: nach dem Aufheben meldet er sich an wie zuvor. Eine bestehende
 * Sperre behält ihren Zeitpunkt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param bool $sperren true sperrt, false hebt auf
 * @return void
 * @throws ApiError PORTAL_DATABASE_ERROR
 */
function portalAccountLock($db, int $customerId, bool $sperren): void {
    $db->beginTransaction();
    try {
        $db->execute(
            "INSERT INTO customer_ext (customer_id, portal_locked_at)
             VALUES (:id, CASE WHEN CAST(:sperren AS boolean) THEN NOW() END)
             ON CONFLICT (customer_id) DO UPDATE
                SET portal_locked_at = CASE WHEN CAST(:sperren AS boolean)
                                            THEN COALESCE(customer_ext.portal_locked_at, NOW()) END",
            [':id' => $customerId, ':sperren' => $sperren ? 't' : 'f']
        );
        if ($sperren) {
            portalAccountEndSessions($db, $customerId);
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError('PORTAL_DATABASE_ERROR', 'Die Sperre konnte nicht gesetzt werden: '.$e->getMessage());
    }
}

/**
 * Setzt den Zugang zurück: das Kennwort wird gelöscht
 *
 * Der Kunde ist danach ein Kunde ohne Konto — die Konto-Freischaltung
 * findet ihn wieder, aus der Kundenakte lässt sich der Link erneut senden.
 * Kundendaten und E-Mail-Adresse bleiben, Sitzungen enden, offene Kennungen
 * verfallen. Eine Sperre bleibt bestehen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @return void
 * @throws ApiError PORTAL_DATABASE_ERROR
 */
function portalAccountReset($db, int $customerId): void {
    $db->beginTransaction();
    try {
        $db->execute("UPDATE customer SET user_password = NULL WHERE id = :id", [':id' => $customerId]);
        portalAccountEndSessions($db, $customerId);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError('PORTAL_DATABASE_ERROR', 'Der Zugang konnte nicht zurückgesetzt werden: '.$e->getMessage());
    }
}
