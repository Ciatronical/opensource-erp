<?php
// backend/api/shop/lib/account.php
//
// Was der Shop über das Kundenkonto hinaus braucht. Das Konto selbst —
// Anmeldung, Registrierung, Anschriften, Zahlungsart, Kennwort — gehört seit
// dem Kundenportal dem Kern (backend/api/portal/lib/account.php); hier steht
// nur die Kasse.

/**
 * Anschriften für die Kasse
 *
 * Ein angemeldeter Kunde bekommt seine Anschriften mitgeliefert, ein Gast
 * nur die Anreden für das Formular.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param string $sprache Sprachkürzel
 * @return array
 */
function checkoutAddresses($db, string $uuid, string $sprache = 'de'): array {
    $context = portalContextRequire($db, $uuid);

    $daten = [
        'registered'  => false,
        'salutations' => salutations($db, $sprache),
    ];

    $gast = in_array($context['guest'], ['t', true, 1, '1'], true);
    if (empty($context['customer_id']) || $gast) {
        return $daten;
    }

    $kunde = customerInvoicingData($db, (int)$context['customer_id']);
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
           FROM shipto WHERE trans_id = :customer_id AND COALESCE(module, 'CT') = 'CT'
          ORDER BY shipto_id",
        [':customer_id' => $context['customer_id']]
    );

    return $daten;
}
