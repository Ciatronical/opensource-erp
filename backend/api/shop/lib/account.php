<?php
// backend/api/shop/lib/account.php
//
// Das Kundenkonto im Shop. Ein Shop-Kunde ist ein gewoehnlicher
// kivitendo-Kunde (Tabelle customer) mit einem Kennwort in
// customer.user_password — kein Benutzer des ERP. Verwaltet werden diese
// Kunden im Admin-Panel ueber die normale Kundenverwaltung; hier steht nur,
// was der Kunde selbst im Shop tun kann.
//
// Gastbestellungen legen ebenfalls eine Kundenzeile an, gekennzeichnet ueber
// customer_ext.hugoshop_guest. Sie haben kein Kennwort und koennen sich
// deshalb nicht anmelden.
//
// Lieferadressen liegen in shipto; shipto.trans_id ist der Eigentuemer. Jede
// Funktion, die eine shipto_id entgegennimmt, fuehrt sie in der Bedingung mit
// — ohne das liesse sich durch Hochzaehlen der Kennung jede fremde
// Lieferadresse lesen, aendern oder loeschen.

/**
 * Stammdaten fuer Preisberechnung und Rechnungsstellung
 *
 * Mit Kunde dessen Steuerzone und Waehrung, ohne Kunde die Vorgaben des
 * Mandanten — ein Besucher ohne Konto sieht Preise, bevor er sich anmeldet.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int|null $customerId Kunde, oder null fuer die Mandanten-Vorgaben
 * @return array precision, taxzone_id, currency_id, currency (+ Kundenfelder)
 * @throws ApiError ACCOUNT_NOT_FOUND, INVOICING_DEFAULTS_NOT_FOUND
 */
function shopInvoicingData($db, ?int $customerId): array {
    if (null === $customerId) {
        $vorgaben = $db->getOne(
            // precision ist der Rundungsschritt und steht im Nenner. Ist er 0
            // oder nicht gesetzt — auf einer frisch aufgesetzten Datenbank
            // kommt das vor —, scheitert jede Preisabfrage an einer Division
            // durch Null, und der Shop meldet nur "division by zero".
            "SELECT COALESCE(NULLIF(d.precision, 0), 0.01) AS precision, d.currency_id,
                    (SELECT name FROM currencies WHERE id = d.currency_id) AS currency,
                    COALESCE(
                        (SELECT id FROM tax_zones
                          WHERE description ILIKE :taxzone ORDER BY sortkey LIMIT 1),
                        (SELECT id FROM tax_zones ORDER BY sortkey LIMIT 1)
                    ) AS taxzone_id
               FROM defaults d",
            [':taxzone' => shopConfigValue($db, 'shop_standard_taxzone', 'Inland')]
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
                ch.hugoshop_guest AS guest, ch.hugoshop_shipto_id AS shipto_id
           FROM customer c
           JOIN customer_ext ch ON ch.customer_id = c.id
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
 * Anreden in der gewuenschten Sprache
 *
 * Braucht weder Kontext noch Kunde und dient auch dem Admin-Panel.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $sprache Sprachkuerzel, z.B. 'de'
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
 * Legt ein Kundenkonto oder eine Gastbestellung an
 *
 * Die Kundennummer kommt aus nextFreeNumber() und damit aus dem
 * Nummernkreis-Mechanismus von OpensourceERP. Die Bridge sperrte dafuer die
 * ganze Kundentabelle und zaehlte in einer Schleife hoch.
 *
 * ACHTUNG BEIM VERGLEICH MIT DER BRIDGE: dort stand
 *   $hashedPassword = ('false' == $guest) ? null : password_hash(...)
 * — also genau andersherum. Ein angelegtes Konto bekam kein Kennwort und kam
 * durch die Anmeldung nie hindurch, eine Gastbestellung einen Hash ueber ein
 * Feld, das dort gar nicht gesetzt wird. Hier bekommt das Konto das Kennwort
 * und der Gast keines.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param array $daten name, company_name, street, zipcode, city, country, phone,
 *                     email, password, salutation, account_type, guest,
 *                     shipping (optionales Feld mit der Lieferadresse)
 * @return array{customer_id: int, shipto_id: int|null}
 * @throws ApiError SHOP_CONTEXT_ERROR, ACCOUNT_EXISTS, SHOP_DATABASE_ERROR
 */
function customerRegister($db, string $uuid, array $daten): array {
    shopContextRequire($db, $uuid);

    $gast = !empty($daten['guest']);
    $email = trim((string)($daten['email'] ?? ''));

    $vorhanden = $db->getOne(
        "SELECT 1 FROM customer c
           JOIN customer_ext ch ON ch.customer_id = c.id
          WHERE ch.hugoshop_guest = false AND c.email = :email",
        [':email' => $email]
    );
    if ($vorhanden) {
        throw new ApiError("ACCOUNT_EXISTS", 'Zu dieser Adresse gibt es schon ein Konto');
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

    $db->beginTransaction();
    try {
        $kundennummer = nextFreeNumber($db, 'customernumber', 'customer', 'customernumber');

        $kunde = $db->getOne(
            "INSERT INTO customer
                    (name, street, zipcode, city, country, phone, email, user_password,
                     greeting, customernumber, taxzone_id, currency_id, natural_person, contact)
             SELECT :name, :street, :zipcode, :city, :country, :phone, :email, :user_password,
                    :greeting, :customernumber,
                    COALESCE((SELECT id FROM tax_zones WHERE description ILIKE :taxzone ORDER BY sortkey LIMIT 1),
                             (SELECT id FROM tax_zones ORDER BY sortkey LIMIT 1)),
                    COALESCE((SELECT id FROM currencies WHERE name ILIKE :currency LIMIT 1),
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
                ':taxzone'        => shopConfigValue($db, 'shop_standard_taxzone', 'Inland'),
                ':currency'       => shopConfigValue($db, 'shop_standard_currency', 'EUR'),
                ':natural_person' => !$firmenkonto,
                ':contact'        => $contact,
            ]
        );

        $customerId = (int)$kunde['id'];

        $db->execute(
            "INSERT INTO customer_ext (customer_id, hugoshop_guest) VALUES (:customer_id, :guest)
             ON CONFLICT (customer_id) DO UPDATE SET hugoshop_guest = EXCLUDED.hugoshop_guest",
            [':customer_id' => $customerId, ':guest' => $gast]
        );

        // Die Kennung der eben angelegten Lieferadresse geht an den Aufrufer
        // zurueck: ohne sie legte die Rechnungsstellung bei einer
        // Gastbestellung eine zweite, gleichlautende shipto-Zeile an.
        $shiptoId = null;
        if (!empty($daten['shipping'])) {
            $adresse = shiptoCreate($db, $customerId, $daten['shipping']);
            $shiptoId = $adresse['shipto_id'];
            $db->execute(
                "UPDATE customer_ext SET hugoshop_shipto_id = :shipto_id WHERE customer_id = :customer_id",
                [':shipto_id' => $shiptoId, ':customer_id' => $customerId]
            );
        }

        $db->execute(
            "UPDATE context_hugoshop SET customer_id = :customer_id, active = NOW() WHERE uuid = :uuid",
            [':customer_id' => $customerId, ':uuid' => $uuid]
        );

        $db->commit();
    } catch (ApiError $e) {
        $db->rollBack();
        throw $e;
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError("SHOP_DATABASE_ERROR", 'Konto konnte nicht angelegt werden: '.$e->getMessage());
    }

    // Ein neu angelegter Kunde bringt seinen Warenkorb mit
    cartMergeIntoCustomerCart($db, $uuid, $customerId);

    return ['customer_id' => $customerId, 'shipto_id' => $shiptoId];
}

/**
 * Rechnungsadresse und alle Lieferadressen des Kunden
 *
 * "used" sagt, ob die Lieferadresse an einer Rechnung haengt. Eine benutzte
 * Adresse zu loeschen wuerde die Rechnung ihrer Anschrift berauben.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @return array
 * @throws ApiError ACCOUNT_NOT_FOUND
 */
function customerAddresses($db, int $customerId): array {
    $kunde = shopInvoicingData($db, $customerId);

    $lieferadressen = $db->getAll(
        "SELECT s.shipto_id, s.shiptoname, s.shiptostreet, s.shiptozipcode, s.shiptocity,
                s.shiptocountry, s.shiptophone, s.shiptoemail,
                EXISTS (SELECT 1 FROM ar WHERE ar.customer_id = :customer_id
                                           AND ar.shipto_id = s.shipto_id) AS used
           FROM shipto s
          WHERE s.trans_id = :customer_id
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
 * Aendert die Rechnungsadresse des Kunden
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
 * PostgreSQL lastval() und haengt damit an der Reihenfolge aller Sequenzen
 * dieser Sitzung.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Eigentuemer der Adresse
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
 * @param int $customerId Eigentuemer
 * @param int $shiptoId Lieferadresse
 * @return array
 * @throws ApiError ADDRESS_NOT_FOUND wenn es sie nicht gibt oder sie einem anderen gehoert
 */
function shiptoRead($db, int $customerId, int $shiptoId): array {
    $adresse = $db->getOne(
        "SELECT shipto_id, shiptoname, shiptostreet, shiptozipcode, shiptocity,
                shiptocountry, shiptophone, shiptoemail
           FROM shipto WHERE shipto_id = :shipto_id AND trans_id = :customer_id",
        [':shipto_id' => $shiptoId, ':customer_id' => $customerId]
    );

    if (!$adresse) {
        throw new ApiError("ADDRESS_NOT_FOUND", 'Diese Lieferadresse gibt es nicht');
    }
    return $adresse;
}

/**
 * Aendert eine Lieferadresse des Kunden
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Eigentuemer
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

    // Ohne diese Pruefung meldete die Bridge auch dann Erfolg, wenn gar keine
    // Zeile getroffen wurde — die Oberflaeche zeigte eine Aenderung an, die es
    // nicht gab.
    if (!$zeile) {
        throw new ApiError("ADDRESS_NOT_FOUND", 'Diese Lieferadresse gibt es nicht');
    }
}

/**
 * Loescht eine Lieferadresse des Kunden
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Eigentuemer
 * @param int $shiptoId Lieferadresse
 * @return void
 * @throws ApiError ADDRESS_NOT_FOUND
 */
function shiptoDelete($db, int $customerId, int $shiptoId): void {
    $zeile = $db->getOne(
        "DELETE FROM shipto WHERE shipto_id = :shipto_id AND trans_id = :customer_id
         RETURNING shipto_id",
        [':shipto_id' => $shiptoId, ':customer_id' => $customerId]
    );

    if (!$zeile) {
        throw new ApiError("ADDRESS_NOT_FOUND", 'Diese Lieferadresse gibt es nicht');
    }
}

/**
 * Waehlt die Standard-Lieferadresse
 *
 * Die Zugehoerigkeit wird geprueft, bevor gesetzt wird: sonst liesse sich
 * eine fremde Lieferadresse zur eigenen Standardadresse machen, und die
 * naechste Bestellung ginge dorthin.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param int $shiptoId Lieferadresse
 * @return void
 * @throws ApiError ADDRESS_NOT_FOUND
 */
function shiptoSetDefault($db, int $customerId, int $shiptoId): void {
    $zeile = $db->getOne(
        "UPDATE customer_ext SET hugoshop_shipto_id = :shipto_id
          WHERE customer_id = :customer_id
            AND EXISTS (SELECT 1 FROM shipto
                         WHERE shipto_id = :shipto_id AND trans_id = :customer_id)
         RETURNING hugoshop_shipto_id",
        [':shipto_id' => $shiptoId, ':customer_id' => $customerId]
    );

    if (!$zeile) {
        throw new ApiError("ADDRESS_NOT_FOUND", 'Diese Lieferadresse gibt es nicht');
    }
}

/**
 * Nimmt die Standard-Lieferadresse zurueck — geliefert wird an die Rechnungsadresse
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @return void
 */
function shiptoClearDefault($db, int $customerId): void {
    $db->execute(
        "UPDATE customer_ext SET hugoshop_shipto_id = NULL WHERE customer_id = :customer_id",
        [':customer_id' => $customerId]
    );
}

/**
 * Uebersicht des Kundenkontos: Anschriften und Zahlungsart
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $sprache Sprachkuerzel fuer die Bezeichnung der Zahlungsart
 * @return array
 */
function customerOverview($db, int $customerId, string $sprache = 'de'): array {
    $daten = customerAddresses($db, $customerId);
    $kunde = shopInvoicingData($db, $customerId);

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
 * Persoenliche Angaben des Kunden
 *
 * Bei einem Firmenkonto steht der Firmenname im Namen und die Person im
 * Ansprechpartner; die Oberflaeche zeigt beides getrennt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $sprache Sprachkuerzel fuer die Anreden
 * @return array
 */
function customerProfile($db, int $customerId, string $sprache = 'de'): array {
    $kunde = shopInvoicingData($db, $customerId);
    $natuerlichePerson = in_array($kunde['natural_person'], ['t', true, 1, '1'], true);

    return [
        'company_name'   => $natuerlichePerson ? '' : $kunde['name'],
        'name'           => $natuerlichePerson ? $kunde['name'] : $kunde['contact'],
        'phone'          => $kunde['phone'],
        'email'          => $kunde['email'],
        'natural_person' => $natuerlichePerson,
        'salutation'     => $kunde['greeting'],
        'salutations'    => salutations($db, $sprache),
    ];
}

/**
 * Aendert die persoenlichen Angaben
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
 * Zahlungsarten zur Auswahl, samt der aktuell gewaehlten
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $sprache Sprachkuerzel
 * @return array
 */
function customerPaymentTerms($db, int $customerId, string $sprache = 'de'): array {
    $kunde = shopInvoicingData($db, $customerId);

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
 * Waehlt die Zahlungsart des Kunden
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
 * Aendert die E-Mail-Adresse
 *
 * Die Adresse ist zugleich der Anmeldename, deshalb das Kennwort zur
 * Bestaetigung und die Pruefung auf Eindeutigkeit.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param string $email Neue Adresse
 * @param string $password Kennwort zur Bestaetigung
 * @return void
 * @throws ApiError ACCOUNT_NOT_FOUND, WRONG_PASSWORD, EMAIL_EXISTS
 */
function customerUpdateEmail($db, int $customerId, string $email, string $password): void {
    customerVerifyPassword($db, $customerId, $password);

    $vergeben = $db->getOne(
        "SELECT 1 FROM customer WHERE email = :email AND id <> :customer_id",
        [':email' => $email, ':customer_id' => $customerId]
    );
    if ($vergeben) {
        throw new ApiError("EMAIL_EXISTS", 'Diese Adresse wird bereits verwendet');
    }

    $db->execute(
        "UPDATE customer SET email = :email WHERE id = :customer_id",
        [':email' => $email, ':customer_id' => $customerId]
    );
}

/**
 * Aendert das Kennwort
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

    if ('' === trim($neu)) {
        throw new ApiError("PASSWORD_REQUIRED", 'Das neue Kennwort darf nicht leer sein');
    }

    $db->execute(
        "UPDATE customer SET user_password = :user_password WHERE id = :customer_id",
        [':user_password' => password_hash($neu, PASSWORD_DEFAULT), ':customer_id' => $customerId]
    );
}

/**
 * Prueft das Kennwort des Kunden
 *
 * Eine Gastzeile hat kein Kennwort; dort schlaegt die Pruefung immer fehl,
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
 * Anschriften fuer die Kasse
 *
 * Ein angemeldeter Kunde bekommt seine Anschriften mitgeliefert, ein Gast
 * nur die Anreden fuer das Formular.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param string $sprache Sprachkuerzel
 * @return array
 */
function checkoutAddresses($db, string $uuid, string $sprache = 'de'): array {
    $context = shopContextRequire($db, $uuid);

    $daten = [
        'registered'  => false,
        'salutations' => salutations($db, $sprache),
    ];

    $gast = in_array($context['guest'], ['t', true, 1, '1'], true);
    if (empty($context['customer_id']) || $gast) {
        return $daten;
    }

    $kunde = shopInvoicingData($db, (int)$context['customer_id']);
    $natuerlichePerson = in_array($kunde['natural_person'], ['t', true, 1, '1'], true);

    $daten['registered']     = true;
    $daten['natural_person'] = $natuerlichePerson;
    $daten['address'] = [
        'name'    => $natuerlichePerson ? $kunde['name'] : $kunde['contact'],
        'street'  => $kunde['street'],
        'zipcode' => $kunde['zipcode'],
        'city'    => $kunde['city'],
        'country' => $kunde['country'],
    ];

    if (!empty($kunde['shipto_id'])) {
        $daten['shipping'] = shiptoRead($db, (int)$context['customer_id'], (int)$kunde['shipto_id']);
    }

    $daten['shipping_addresses'] = $db->getAll(
        "SELECT shipto_id, shiptoname, shiptostreet, shiptozipcode, shiptocity,
                shiptocountry, shiptophone, shiptoemail
           FROM shipto WHERE trans_id = :customer_id ORDER BY shipto_id",
        [':customer_id' => $context['customer_id']]
    );

    return $daten;
}

/**
 * Vorbelegung des Kontaktformulars
 *
 * Ein angemeldeter Kunde muss seine Angaben nicht erneut eintippen. Ohne
 * Konto bleiben die Felder leer.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int|null $customerId Kunde, oder null
 * @param string $sprache Sprachkuerzel
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

    $kunde = shopInvoicingData($db, $customerId);
    $natuerlichePerson = in_array($kunde['natural_person'], ['t', true, 1, '1'], true);

    $daten['company_name'] = $natuerlichePerson ? '' : $kunde['name'];
    $daten['name']         = $natuerlichePerson ? $kunde['name'] : $kunde['contact'];
    $daten['email']        = $kunde['email'];
    $daten['phone']        = $kunde['phone'];
    $daten['salutation']   = $kunde['greeting'];

    return $daten;
}
