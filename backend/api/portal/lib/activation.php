<?php
// backend/api/portal/lib/activation.php
//
// Freischaltung eines Bestandskunden (dev/shop-konto-freischaltung.md). Die
// Kunden der Werkstatt stehen in customer, meist mit Telefonnummer, selten
// mit E-Mail-Adresse, nie mit Kennwort. Sie sollen sich auf der Webseite
// anmelden können, ohne ein zweites Konto anzulegen:
//
//   1. Der Kunde nennt Telefonnummer und Postleitzahl (oder Kundennummer),
//      oder ein Mitarbeiter stößt es aus der Kundenakte an.
//   2. Genau ein passender Kunde ohne Konto: Kennung in
//      portal_activation_oserp, Link per WhatsApp (Vorlage mit Schaltfläche)
//      oder per Mail.
//   3. Auf der verlinkten Seite vergibt der Kunde E-Mail-Adresse und
//      Kennwort — danach ist er angemeldet wie jeder andere.
//
// Die Antwort auf die Anfrage ist immer dieselbe: sonst ließe sich über sie
// ablesen, welche Nummern Kunden sind. Was wirklich geschah, steht im
// Protokoll.
//
// Drossel (F6): je Nummer eine Nachricht in zehn Minuten und drei am Tag —
// jede Nachricht kostet bei Meta Geld —, je Sitzung fünf Anfragen am Tag.

const PORTAL_ACTIVATION_HOURS = 24;

/**
 * Landesvorwahl ohne Plus, für Nummern, die mit 0 beginnen
 *
 * Dieselbe Einstellung wie bei WhatsApp (whatsapp_country_code).
 *
 * @param object $db Company-Datenbankverbindung
 * @return string
 */
function portalCountryCode($db): string {
    $zeile = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'whatsapp_country_code'", []);
    $wert = preg_replace('/[^0-9]/', '', (string)($zeile['value'] ?? ''));
    return '' === $wert ? '49' : $wert;
}

/**
 * Ziffern einer Telefonnummer, international ohne Plus — wie portal_phone_digits() in SQL
 *
 * @param string $phone Nummer in beliebiger Schreibweise
 * @param string $land Landesvorwahl ohne Plus
 * @return string
 */
function portalPhoneDigits(string $phone, string $land): string {
    $d = preg_replace('/[^0-9]/', '', $phone);
    if ('' === $d) {
        return '';
    }
    if (str_starts_with($d, '00')) {
        return substr($d, 2);
    }
    if (str_starts_with($d, '0')) {
        return $land.substr($d, 1);
    }
    return $d;
}

/**
 * Sucht den Kunden zur Nummer — genau einen
 *
 * Nur Kunden ohne Konto: kein Kennwort oder Gast. Die Nummer muss ganz
 * passen (customer.phone oder customer_ext.phone_numbers), dazu die
 * Postleitzahl der Rechnungsadresse oder die Kundennummer. Mehr als ein
 * Treffer ist keiner — dann hilft nur der Mitarbeiter.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $phone Nummer, wie eingegeben
 * @param string $zipcode Postleitzahl
 * @param string $customernumber Kundennummer, alternativ
 * @return int|null Kunde
 */
function activationFindCustomer($db, string $phone, string $zipcode, string $customernumber): ?int {
    $zeile = $db->getOne(
        "WITH kandidaten AS (
             SELECT c.id
               FROM customer c
               LEFT JOIN customer_ext ce ON ce.customer_id = c.id
              WHERE NOT COALESCE(c.obsolete, false)
                AND (c.user_password IS NULL OR COALESCE(ce.portal_guest, false))
                AND portal_phone_digits(:phone, :land) <> ''
                AND (portal_phone_digits(c.phone, :land) = portal_phone_digits(:phone, :land)
                     OR EXISTS (SELECT 1 FROM jsonb_array_elements(COALESCE(ce.phone_numbers, '[]'::jsonb)) pn
                                 WHERE portal_phone_digits(pn ->> 'number', :land) = portal_phone_digits(:phone, :land)))
                AND ((:zipcode <> '' AND regexp_replace(COALESCE(c.zipcode, ''), '\s', '', 'g') = :zipcode)
                     OR (:customernumber <> '' AND lower(btrim(COALESCE(c.customernumber, ''))) = lower(:customernumber)))
         )
         SELECT (SELECT count(*) FROM kandidaten) AS n, (SELECT min(id) FROM kandidaten) AS id",
        [
            ':phone'          => $phone,
            ':land'           => portalCountryCode($db),
            ':zipcode'        => preg_replace('/\s/', '', $zipcode),
            ':customernumber' => trim($customernumber),
        ]
    );

    return 1 === (int)($zeile['n'] ?? 0) ? (int)$zeile['id'] : null;
}

/**
 * Drossel je Sitzung: höchstens fünf Anfragen am Tag
 *
 * Zählt in der Sitzung selbst; der Tag beginnt mit der ersten Anfrage.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @return bool true wenn die Anfrage noch erlaubt ist
 */
function activationThrottleSession($db, string $uuid): bool {
    $zeile = $db->getOne(
        "UPDATE portal_context_oserp
            SET activation_count = CASE WHEN activation_reset IS NULL OR activation_reset < NOW() - interval '24 hours'
                                        THEN 1 ELSE activation_count + 1 END,
                activation_reset = CASE WHEN activation_reset IS NULL OR activation_reset < NOW() - interval '24 hours'
                                        THEN NOW() ELSE activation_reset END
          WHERE uuid = :uuid
         RETURNING activation_count",
        [':uuid' => $uuid]
    );
    return null !== $zeile && (int)$zeile['activation_count'] <= 5;
}

/**
 * Drossel je Nummer: eine Nachricht in zehn Minuten, drei am Tag
 *
 * Gezählt werden die angelegten Kennungen zur Nummer — auch die ersetzten.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $phone Nummer, normalisiert
 * @return bool true wenn eine Nachricht noch erlaubt ist
 */
function activationThrottlePhone($db, string $phone): bool {
    $zeile = $db->getOne(
        "SELECT count(*) FILTER (WHERE created > NOW() - interval '10 minutes') AS kurz,
                count(*) FILTER (WHERE created > NOW() - interval '24 hours')   AS tag
           FROM portal_activation_oserp
          WHERE phone = :phone",
        [':phone' => $phone]
    );
    return (int)($zeile['kurz'] ?? 0) < 1 && (int)($zeile['tag'] ?? 0) < 3;
}

/**
 * Legt die Kennung an; eine offene Kennung des Kunden wird ersetzt
 *
 * Die alte Kennung gilt danach als verbraucht — der alte Link meldet
 * „ungültig“ und verweist auf eine neue Anfrage.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param int|null $website Webseite, deren Seite der Link öffnet
 * @param string $phone Nummer, normalisiert (leer bei Versand per Mail)
 * @param string $source portal (vom Kunden) oder staff (aus der Kundenakte)
 * @return string Kennung
 */
function activationCreate($db, int $customerId, ?int $website, string $phone, string $source): string {
    // Zwei Anweisungen, keine CTE: Innerhalb einer Anweisung sieht PostgreSQL
    // die Änderung eines Teilschritts nicht, der eindeutige Index
    // portal_activation_oserp_offen (eine offene Kennung je Kunde) meldete
    // sonst die eben entwertete Kennung als Dublette
    $db->beginTransaction();
    try {
        $db->execute(
            "UPDATE portal_activation_oserp SET used_at = NOW()
              WHERE customer_id = :customer_id AND used_at IS NULL",
            [':customer_id' => $customerId]
        );
        $zeile = $db->getOne(
            "INSERT INTO portal_activation_oserp (uuid, customer_id, website_id, phone, source, expires)
             VALUES (:uuid, :customer_id, CAST(:website AS integer), :phone, :source,
                     NOW() + CAST(:stunden || ' hours' AS interval))
             RETURNING uuid",
            [
                ':uuid'        => portalNewUuid(),
                ':customer_id' => $customerId,
                ':website'     => $website,
                ':phone'       => $phone,
                ':source'      => 'staff' === $source ? 'staff' : 'portal',
                ':stunden'     => (string)PORTAL_ACTIVATION_HOURS,
            ]
        );
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    return (string)$zeile['uuid'];
}

/**
 * Anrede des Kunden für die Nachricht — Vorname reicht, mehr verrät die Nachricht nicht
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @return array{name: string, email: string, greeting: string}
 */
function activationCustomer($db, int $customerId): array {
    $zeile = $db->getOne(
        "SELECT c.name, COALESCE(c.email, '') AS email, COALESCE(c.greeting, '') AS greeting,
                CASE WHEN c.natural_person THEN c.name ELSE COALESCE(NULLIF(c.contact, ''), c.name) END AS person
           FROM customer c WHERE c.id = :id",
        [':id' => $customerId]
    );
    return [
        'name'     => (string)($zeile['person'] ?? ''),
        'email'    => (string)($zeile['email'] ?? ''),
        'greeting' => (string)($zeile['greeting'] ?? ''),
    ];
}

/**
 * Legt die Kennung an und schickt den Link
 *
 * WhatsApp: genehmigte Vorlage vom Typ portal_activation mit einer
 * Schaltfläche, deren Adresse die Kennung trägt. Mail: Vorlage
 * activation.de an die hinterlegte Adresse.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @param int $website Webseite, deren Seite der Link öffnet
 * @param string $via whatsapp oder email
 * @param string $phone Nummer für WhatsApp (leer = Nummer des Kundenstamms)
 * @param string $source portal oder staff
 * @return array{uuid: string, sent: bool, via: string, error: string, code: string} code: Fehlercode bei sent=false
 * @throws ApiError WEBSITE_CONFIG_MISSING (keine base_url), ACTIVATION_NO_PHONE, ACTIVATION_NO_EMAIL
 */
function activationSend($db, int $customerId, int $website, string $via, string $phone, string $source): array {
    $link = websiteActivatePageUrl($db, $website, 'PLATZHALTER');
    if ('' === $link) {
        throw new ApiError('WEBSITE_CONFIG_MISSING', 'Die Webseite hat keine Adresse (base_url)');
    }
    $kunde = activationCustomer($db, $customerId);

    if ('email' === $via) {
        if (!filter_var($kunde['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ApiError('ACTIVATION_NO_EMAIL', 'Der Kunde hat keine E-Mail-Adresse');
        }
        $token = activationCreate($db, $customerId, $website, '', $source);
        $ergebnis = activationSendMail($db, $website, $kunde, $token);
        return ['uuid' => $token, 'sent' => $ergebnis['sent'], 'via' => 'email', 'error' => $ergebnis['error'], 'code' => $ergebnis['code']];
    }

    $digits = portalPhoneDigits($phone, portalCountryCode($db));
    if ('' === $digits) {
        $zeile = $db->getOne("SELECT COALESCE(phone, '') AS phone FROM customer WHERE id = :id", [':id' => $customerId]);
        $digits = portalPhoneDigits((string)($zeile['phone'] ?? ''), portalCountryCode($db));
    }
    if ('' === $digits) {
        throw new ApiError('ACTIVATION_NO_PHONE', 'Der Kunde hat keine Telefonnummer');
    }

    $token = activationCreate($db, $customerId, $website, $digits, $source);
    $ergebnis = activationSendWhatsApp($db, $digits, $kunde, $token, $customerId);
    return ['uuid' => $token, 'sent' => $ergebnis['sent'], 'via' => 'whatsapp', 'error' => $ergebnis['error'], 'code' => $ergebnis['code']];
}

/**
 * Schickt den Link per WhatsApp
 *
 * Die Vorlage (template_type portal_activation) trägt die feste Adresse der
 * Seite „Konto freischalten“ in ihrer Schaltfläche; variabel ist nur die
 * Kennung. Im Text steht {{1}} für den Namen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $digits Nummer, normalisiert ohne Plus
 * @param array $kunde aus activationCustomer()
 * @param string $token Kennung
 * @param int $customerId Kunde, für das Nachrichtenprotokoll
 * @return array{sent: bool, error: string}
 */
function activationSendWhatsApp($db, string $digits, array $kunde, string $token, int $customerId): array {
    require_once __DIR__.'/../../customer_vendor/filemanager.php';
    require_once __DIR__.'/../../whatsapp/whatsapp.php';

    $ergebnis = whatsappSendTemplate([
        'to'                => '+'.$digits,
        'template_type'     => 'portal_activation',
        'parameters'        => [$kunde['name']],
        'button_parameters' => [$token],
        'customer_id'       => $customerId,
    ]);

    if (empty($ergebnis['success'])) {
        writeLog('[PORTAL] Freischaltung per WhatsApp nicht versendet: '.($ergebnis['error'] ?? ''), true, DLOG_WRN);
        return [
            'sent'  => false,
            'error' => (string)($ergebnis['error'] ?? ''),
            'code'  => (string)($ergebnis['code'] ?? '') ?: 'ACTIVATION_NOT_SENT',
        ];
    }
    return ['sent' => true, 'error' => '', 'code' => ''];
}

/**
 * Schickt den Link per Mail
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param array $kunde aus activationCustomer()
 * @param string $token Kennung
 * @return array{sent: bool, error: string, code: string} code: Fehlercode für die Oberfläche (EMAIL_NOT_CONFIGURED …)
 */
function activationSendMail($db, int $website, array $kunde, string $token): array {
    try {
        $mailer = portalMailer($db);
        $html = portalMailTemplate('activation.de', [
            'name'     => $kunde['name'],
            'link'     => websiteActivatePageUrl($db, $website, $token),
            'stunden'  => PORTAL_ACTIVATION_HOURS,
            'webseite' => websiteValue($db, $website, 'name'),
            'signatur' => websiteValue($db, $website, 'base_url'),
        ]);
        $mailer['client']->send(
            $mailer['from'], $mailer['from_name'],
            [$kunde['email']], 'Ihr Zugang zum Kundenkonto', $html
        );
        return ['sent' => true, 'error' => '', 'code' => ''];
    } catch (Exception $e) {
        writeLog('[PORTAL] Freischaltung per Mail nicht versendet: '.$e->getMessage(), true, DLOG_WRN);
        return [
            'sent'  => false,
            'error' => $e->getMessage(),
            'code'  => $e instanceof ApiError ? $e->getId() : 'ACTIVATION_NOT_SENT',
        ];
    }
}

/**
 * Anfrage eines Kunden von der Webseite
 *
 * Antwortet immer gleich. Gedrosselt, nicht gefunden, mehrdeutig, Versand
 * gescheitert — alles landet im Protokoll, nichts in der Antwort.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param int $website Webseite der Anfrage
 * @param array $daten phone, zipcode, customernumber
 * @return array{sent: bool}
 * @throws ApiError PORTAL_CONTEXT_ERROR, ACTIVATION_DISABLED, MISSING_PHONE, MISSING_ZIPCODE
 */
function activationRequest($db, string $uuid, int $website, array $daten): array {
    portalContextRequire($db, $uuid);
    activationRequireEnabled($db, $website);

    $phone         = trim((string)($daten['phone'] ?? ''));
    $zipcode        = trim((string)($daten['zipcode'] ?? ''));
    $customernumber = trim((string)($daten['customernumber'] ?? ''));

    if ('' === portalPhoneDigits($phone, portalCountryCode($db))) {
        throw new ApiError('MISSING_PHONE', 'Bitte eine Telefonnummer angeben');
    }
    if ('' === $zipcode && '' === $customernumber) {
        throw new ApiError('MISSING_ZIPCODE', 'Bitte die Postleitzahl oder die Kundennummer angeben');
    }

    $antwort = ['sent' => true];

    if (!activationThrottleSession($db, $uuid)) {
        writeLog('[PORTAL] Freischaltung: Sitzung gedrosselt', true, DLOG_WRN);
        return $antwort;
    }

    $customerId = activationFindCustomer($db, $phone, $zipcode, $customernumber);
    if (null === $customerId) {
        writeLog('[PORTAL] Freischaltung: kein eindeutiger Kunde zur Anfrage', true, DLOG_INFO);
        return $antwort;
    }

    $digits = portalPhoneDigits($phone, portalCountryCode($db));
    if (!activationThrottlePhone($db, $digits)) {
        writeLog('[PORTAL] Freischaltung: Nummer gedrosselt (Kunde '.$customerId.')', true, DLOG_WRN);
        return $antwort;
    }

    try {
        activationSend($db, $customerId, $website, 'whatsapp', $digits, 'portal');
    } catch (ApiError $e) {
        writeLog('[PORTAL] Freischaltung (Kunde '.$customerId.'): '.$e->getMessage(), true, DLOG_WRN);
    }
    return $antwort;
}

/**
 * Was der Kunde zur Kennung sieht, bevor er das Formular ausfüllt
 *
 * Name und Ort maskiert („M… Mustermann, 12345 B…“): der Kunde erkennt sein
 * Konto, ein fremder Empfänger des Links sieht wenig.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $token Kennung
 * @return array{name: string, zipcode: string, city: string, email: string}
 * @throws ApiError ACTIVATION_INVALID, ACTIVATION_EXPIRED
 */
function activationInfoByToken($db, string $token): array {
    $zeile = activationRow($db, $token);
    return [
        'name'    => activationMaskName((string)$zeile['name']),
        'zipcode' => (string)$zeile['zipcode'],
        'city'    => activationMaskWord((string)$zeile['city']),
        // Vorbelegung des Formulars: eine vorhandene Adresse darf der Kunde übernehmen
        'email'   => (string)$zeile['email'],
    ];
}

/**
 * Bricht ab, wenn die Webseite die Konto-Freischaltung nicht anbietet
 *
 * Einstellung activation_enabled der Webseite (Firmenkonfiguration →
 * Kundenportal), Vorgabe aus. Gilt für Anfrage, Kennung und Abschluss von
 * der Webseite wie für den Versand aus der Kundenakte.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @return void
 * @throws ApiError ACTIVATION_DISABLED
 */
function activationRequireEnabled($db, int $website): void {
    if (!websiteActivationEnabled($db, $website)) {
        throw new ApiError('ACTIVATION_DISABLED', 'Die Konto-Freischaltung ist für diese Webseite nicht eingeschaltet');
    }
}

/**
 * Die Kennung mit ihrem Kunden, geprüft
 *
 * Auch die Webseite der Kennung muss die Freischaltung noch anbieten: wird
 * sie abgeschaltet, verfallen ausgegebene Links.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $token Kennung
 * @return array
 * @throws ApiError ACTIVATION_INVALID, ACTIVATION_EXPIRED, ACTIVATION_DISABLED
 */
function activationRow($db, string $token): array {
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        throw new ApiError('ACTIVATION_INVALID', 'Dieser Link ist ungültig');
    }
    $zeile = $db->getOne(
        "SELECT a.uuid, a.customer_id, a.website_id, a.expires, a.used_at,
                c.name, COALESCE(c.zipcode, '') AS zipcode, COALESCE(c.city, '') AS city,
                COALESCE(c.email, '') AS email, COALESCE(c.obsolete, false) AS obsolete
           FROM portal_activation_oserp a
           JOIN customer c ON c.id = a.customer_id
          WHERE a.uuid = :uuid",
        [':uuid' => $token]
    );
    if (!$zeile || null !== $zeile['used_at'] || in_array($zeile['obsolete'], [true, 't', 1, '1'], true)) {
        throw new ApiError('ACTIVATION_INVALID', 'Dieser Link ist ungültig oder wurde schon benutzt');
    }
    if (strtotime((string)$zeile['expires']) < time()) {
        throw new ApiError('ACTIVATION_EXPIRED', 'Dieser Link ist abgelaufen');
    }
    activationRequireEnabled($db, (int)$zeile['website_id']);
    return $zeile;
}

/**
 * Richtet das Konto ein: E-Mail-Adresse und Kennwort, dann angemeldet
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $uuid Wert des Kontext-Cookies
 * @param string $token Kennung
 * @param string $email Adresse — der künftige Anmeldename
 * @param string $password Kennwort
 * @return array{customer_id: int}
 * @throws ApiError ACTIVATION_INVALID, ACTIVATION_EXPIRED, INVALID_EMAIL, ACCOUNT_EXISTS, PASSWORD_REQUIRED
 */
function activationComplete($db, string $uuid, string $token, string $email, string $password): array {
    portalContextRequire($db, $uuid);
    $zeile = activationRow($db, $token);
    $customerId = (int)$zeile['customer_id'];
    $email = trim($email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiError('INVALID_EMAIL', 'Die E-Mail-Adresse ist unbrauchbar');
    }
    if (customerEmailTaken($db, $email, $customerId)) {
        throw new ApiError('ACCOUNT_EXISTS', 'Zu dieser Adresse gibt es schon ein Konto');
    }
    if ('' === trim($password)) {
        throw new ApiError('PASSWORD_REQUIRED', 'Ein Konto braucht ein Kennwort');
    }

    $db->beginTransaction();
    try {
        $db->execute(
            "UPDATE customer SET email = :email, user_password = :user_password WHERE id = :id",
            [':email' => $email, ':user_password' => password_hash($password, PASSWORD_DEFAULT), ':id' => $customerId]
        );
        $db->execute(
            "INSERT INTO customer_ext (customer_id, portal_guest) VALUES (:id, false)
             ON CONFLICT (customer_id) DO UPDATE SET portal_guest = false",
            [':id' => $customerId]
        );
        $db->execute(
            "UPDATE portal_activation_oserp SET used_at = NOW() WHERE uuid = :uuid",
            [':uuid' => $token]
        );
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError('PORTAL_DATABASE_ERROR', 'Konto konnte nicht eingerichtet werden: '.$e->getMessage());
    }

    portalContextSetCustomer($db, $uuid, $customerId);
    return ['customer_id' => $customerId];
}

/**
 * Stand der Freischaltung eines Kunden — für die Kundenakte
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $customerId Kunde
 * @return array{account: bool, guest: bool, email: string, phone: string, open: array|null, last: array|null}
 */
function activationStatus($db, int $customerId): array {
    $zeile = $db->getOne(
        "SELECT c.user_password IS NOT NULL AND NOT COALESCE(ce.portal_guest, false) AS account,
                COALESCE(ce.portal_guest, false) AS guest,
                COALESCE(c.email, '') AS email, COALESCE(c.phone, '') AS phone,
                (SELECT row_to_json(o) FROM (
                    SELECT a.created, a.expires, a.source, a.phone, a.website_id
                      FROM portal_activation_oserp a
                     WHERE a.customer_id = c.id AND a.used_at IS NULL AND a.expires > NOW()
                     ORDER BY a.created DESC LIMIT 1) o) AS open,
                (SELECT row_to_json(l) FROM (
                    SELECT a.created, a.used_at, a.source
                      FROM portal_activation_oserp a
                     WHERE a.customer_id = c.id
                     ORDER BY a.created DESC LIMIT 1) l) AS last
           FROM customer c
           LEFT JOIN customer_ext ce ON ce.customer_id = c.id
          WHERE c.id = :id",
        [':id' => $customerId]
    );
    if (!$zeile) {
        throw new ApiError('ACCOUNT_NOT_FOUND', 'Kein Kunde zu dieser Kennung');
    }
    return [
        'account' => in_array($zeile['account'], [true, 't', 1, '1'], true),
        'guest'   => in_array($zeile['guest'],   [true, 't', 1, '1'], true),
        'email'   => (string)$zeile['email'],
        'phone'   => (string)$zeile['phone'],
        'open'    => is_string($zeile['open']) ? json_decode($zeile['open'], true) : $zeile['open'],
        'last'    => is_string($zeile['last']) ? json_decode($zeile['last'], true) : $zeile['last'],
    ];
}

/**
 * Maskiert einen Namen: alle Wörter bis auf das letzte auf den Anfangsbuchstaben
 *
 * @param string $name
 * @return string
 */
function activationMaskName(string $name): string {
    $woerter = preg_split('/\s+/', trim($name)) ?: [];
    $woerter = array_values(array_filter($woerter, fn($w) => '' !== $w));
    if (count($woerter) <= 1) {
        return activationMaskWord($name);
    }
    $letztes = array_pop($woerter);
    return implode(' ', array_map('activationMaskWord', $woerter)).' '.$letztes;
}

/**
 * Maskiert ein Wort auf den Anfangsbuchstaben
 *
 * @param string $wort
 * @return string
 */
function activationMaskWord(string $wort): string {
    $wort = trim($wort);
    if ('' === $wort) {
        return '';
    }
    return mb_substr($wort, 0, 1).'…';
}
