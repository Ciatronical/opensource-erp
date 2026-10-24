<?php
// backend/api/shop/kleinanzeigen.php
//
// Verwaltung des Verkaufskanals Kleinanzeigen (dev/shop-kleinanzeigen.md):
// das Board (Vorschläge, Entwürfe, Online, Verkauft, Anfragen), die fertige
// Anzeige zum Kopieren, Rückmeldungen (online, aufgefrischt, reserviert,
// gelöscht), der Verkauf mit Rechnung und Lagerbuchung, KI-Texte und das
// Bilderpaket. Das Kanalmodul steht in channels/kleinanzeigen.php.
//
// Rechte wie die übrige Shop-Verwaltung: lesen mit shop_order, shop_part_edit
// oder edit_shop_config; schreiben mit shop_part_edit oder edit_shop_config.

/** Zustände, die der Benutzer zurückmeldet, und was sie an der Kanalzeile ändern */
const SHOP_KLEINANZEIGEN_STATES = ['online', 'updated', 'refreshed', 'reserved', 'unreserved', 'ended', 'draft', 'removed'];

/**
 * Kleinanzeigen-Kanal einer Anfrage: channel_id, sonst der erste der Art
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $data Eingabedaten
 * @return int Kennung des Kanals
 * @throws ApiError SHOP_CHANNEL_UNKNOWN
 */
function shopKleinanzeigenChannel($db, array $data): int {
    return shopChannelOfRequest($db, $data, 'kleinanzeigen');
}

/**
 * Mitarbeiter für Kleinanzeigen-Rechnungen: employee_login des Kanals, sonst
 * der angemeldete, sonst der erste
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal Kleinanzeigen-Kanal
 * @return int|null
 */
function shopKleinanzeigenEmployeeId($db, int $kanal): ?int {
    $login = trim(shopChannelValue($db, $kanal, 'employee_login'));
    if ('' === $login) {
        try {
            $auth = DbhAuth::begin();
            $auth->fetchSessionData();
            $login = (string)$auth->getLogin();
        } catch (\Throwable $e) {
            // Ohne Sitzung (Läufer, Kommandozeile): der erste Mitarbeiter
            $login = '';
        }
    }
    $zeile = $db->getOne(
        "SELECT COALESCE(
                    (SELECT id FROM employee WHERE login = :login AND NOT deleted LIMIT 1),
                    (SELECT id FROM employee WHERE NOT deleted ORDER BY id LIMIT 1)) AS id",
        [':login' => $login]
    );
    return isset($zeile['id']) ? (int)$zeile['id'] : null;
}

/**
 * Die Anzeige eines Artikels, frisch gelesen (kleinanzeigen_ad)
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param int $kanal Kleinanzeigen-Kanal
 * @return array|null
 */
function shopKleinanzeigenAdRow($db, int $partsId, int $kanal): ?array {
    $zeile = $db->getOne(
        "SELECT kleinanzeigen_ad(CAST(:parts_id AS integer), CAST(:kanal AS integer)) AS ad",
        [':parts_id' => $partsId, ':kanal' => $kanal]
    );
    return json_decode((string)($zeile['ad'] ?? 'null'), true) ?: null;
}

/**
 * Anzeigennummer aus einer Adresse oder Eingabe
 *
 * Kleinanzeigen-Adressen: …/s-anzeige/<titel>/<nummer>-<kategorie>-<ort>.
 * Eine reine Zahl gilt als Nummer.
 *
 * @param string $eingabe Adresse oder Nummer
 * @return string Nummer, leer wenn nicht erkennbar
 */
function shopKleinanzeigenAdId(string $eingabe): string {
    $eingabe = trim($eingabe);
    if (preg_match('/^\d{6,}$/', $eingabe)) {
        return $eingabe;
    }
    if (preg_match('#/s-anzeige/[^/\s]+/(\d{6,})#', $eingabe, $t) || preg_match('/(\d{8,})/', $eingabe, $t)) {
        return $t[1];
    }
    return '';
}

/**
 * Board des Kleinanzeigen-Kanals: Kennzahlen, Vorschläge, Entwürfe, Online,
 * Verkauft, Anfragen
 *
 * Eine Abfrage (kleinanzeigen_board, kleinanzeigen_suggestions). Dazu die
 * Kleinanzeigen-Kanäle des Mandanten für die Auswahl und ob KI und Postfach
 * eingerichtet sind — die Knöpfe dafür erscheinen nur dann.
 *
 * @param int $data['channel_id'] Kanal; fehlt = der erste Kleinanzeigen-Kanal
 * @param int $data['suggestions'] Zahl der Vorschläge (Vorgabe 30)
 * @return void board, suggestions, channels, ai_ready, mail_ready
 * @testdata {"channel_id": 3, "suggestions": 30}
 */
function getKleinanzeigenBoard($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);

    $zeile = $db->getOne(
        "SELECT kleinanzeigen_board(CAST(:kanal AS integer)) AS board,
                (SELECT COALESCE(json_agg(row_to_json(v)), '[]'::json)
                   FROM kleinanzeigen_suggestions(CAST(:kanal_vorschlaege AS integer), CAST(:limit AS integer)) v) AS suggestions,
                (SELECT COALESCE(json_agg(json_build_object('channel_id', c.id, 'name', c.name, 'active', c.active)
                                          ORDER BY c.sortkey NULLS LAST, c.id), '[]'::json)
                   FROM sales_channel_shop c WHERE c.type = 'kleinanzeigen') AS channels,
                COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'anthropic_api_key'), false) AS ai_ready,
                COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'email_imap_host'), false)
                    AND COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'email_username'), false) AS mail_ready",
        [':kanal' => $kanal, ':kanal_vorschlaege' => $kanal, ':limit' => max(1, min(200, (int)($data['suggestions'] ?? 30)))]
    );

    // Betriebsart: automatisch über den Kleinanzeigen-Dienst (Headless-Browser)
    // oder von Hand — dazu, ob der Dienst gerade erreichbar ist
    $automatisch = shopKleinanzeigenAutomatic($db, $kanal);
    $dienst = $automatisch ? shopKleinanzeigenWorkerHealth($db) : ['enabled' => shopKleinanzeigenWorkerEnabled($db), 'reachable' => false];

    resultInfo(true, '', [
        'board'       => json_decode((string)($zeile['board'] ?? '{}'), true) ?: (object)[],
        'suggestions' => json_decode((string)($zeile['suggestions'] ?? '[]'), true) ?: [],
        'channels'    => json_decode((string)($zeile['channels'] ?? '[]'), true) ?: [],
        'ai_ready'    => in_array($zeile['ai_ready'] ?? false, [true, 't', 1, '1'], true),
        'mail_ready'  => in_array($zeile['mail_ready'] ?? false, [true, 't', 1, '1'], true),
        'automatic'   => $automatisch,
        'worker'      => $dienst,
    ]);
}

/**
 * Die fertige Anzeige eines Artikels, wie sie bei Kleinanzeigen einzugeben ist
 *
 * @param int $data['parts_id'] Artikel
 * @param int $data['channel_id'] Kanal; fehlt = der erste Kleinanzeigen-Kanal
 * @return void ad (kleinanzeigen_ad)
 * @testdata {"parts_id": 1, "channel_id": 3}
 */
function getKleinanzeigenAd($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);

    $anzeige = shopKleinanzeigenAdRow($db, (int)($data['parts_id'] ?? 0), $kanal);
    if (!$anzeige) {
        resultInfo(false, 'PART_NOT_FOUND', null, 'Artikel nicht gefunden');
        return;
    }
    resultInfo(true, '', ['ad' => $anzeige]);
}

/**
 * Speichert die Anzeige eines Artikels im Kanal
 *
 * Titel und Text in der Kanalzeile (leer = Stammdaten), Preis und Vorgaben
 * in settings (leer = Vorgabe des Kanals bzw. Kanalpreis). Der Preis ist
 * brutto in ganzen Euro — mehr kennt Kleinanzeigen nicht. Ein Vorgang; die
 * Antwort ist die neue Anzeige. Der Trigger auf der Kanalzeile legt den
 * Auftrag an, der Stand des Boards folgt von selbst (hash).
 *
 * @param int $data['parts_id'] Artikel
 * @param int $data['channel_id'] Kanal
 * @param string $data['title'] Titel, höchstens 65 Zeichen; leer = Bezeichnung des Artikels
 * @param string $data['text'] Text, höchstens 4000 Zeichen; leer = Langbeschreibung des Artikels
 * @param int|null $data['price'] Preis in ganzen Euro; leer = Kanalpreis
 * @param string $data['price_type'] fixed, negotiable oder giveaway; leer = Vorgabe des Kanals
 * @param string $data['shipping'] pickup, shipping oder both; leer = Vorgabe
 * @param string $data['condition'] Zustand; leer = Vorgabe
 * @param string $data['category'] Kategorie bei Kleinanzeigen; leer = Vorgabe
 * @return void ad
 * @testdata {"parts_id": 1, "channel_id": 3, "title": "Bremsscheiben VW Golf 7 vorne, neu", "text": "Originalverpackt …", "price": 89, "price_type": "negotiable", "shipping": "both", "condition": "new", "category": ""}
 */
function saveKleinanzeigenAd($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    $partsId = (int)($data['parts_id'] ?? 0);

    $preisart = (string)($data['price_type'] ?? '');
    $versand = (string)($data['shipping'] ?? '');
    if ('' !== $preisart && !in_array($preisart, ['fixed', 'negotiable', 'giveaway'], true)) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Unbekannte Preisart: '.$preisart);
        return;
    }
    if ('' !== $versand && !in_array($versand, ['pickup', 'shipping', 'both'], true)) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Unbekannte Versandangabe: '.$versand);
        return;
    }
    $preis = $data['price'] ?? null;
    $preis = (null === $preis || '' === trim((string)$preis)) ? '' : (string)max(0, (int)round((float)$preis));

    // Erst schreiben, dann lesen: eine Abfrage mit schreibender CTE sähe
    // noch den alten Stand (PostgreSQL liest den Schnappschuss vor der CTE)
    $zeile = $db->getOne(
        "UPDATE parts_channel_shop pc
                SET title       = NULLIF(left(btrim(:titel), 65), ''),
                    description = NULLIF(left(btrim(:text), 4000), ''),
                    settings    = jsonb_strip_nulls(COALESCE(pc.settings, '{}'::jsonb) || jsonb_build_object(
                                      'price',      NULLIF(:preis, ''),
                                      'price_type', NULLIF(:preisart, ''),
                                      'shipping',   NULLIF(:versand, ''),
                                      'condition',  NULLIF(btrim(:zustand), ''),
                                      'category',   NULLIF(btrim(:kategorie), ''))),
                    mtime       = now()
              WHERE pc.parts_id = CAST(:parts_id AS integer) AND pc.channel_id = CAST(:kanal AS integer)
             RETURNING pc.parts_id",
        [
            ':titel'     => (string)($data['title'] ?? ''),
            ':text'      => (string)($data['text'] ?? ''),
            ':preis'     => $preis,
            ':preisart'  => $preisart,
            ':versand'   => $versand,
            ':zustand'   => (string)($data['condition'] ?? ''),
            ':kategorie' => (string)($data['category'] ?? ''),
            ':parts_id'  => $partsId,
            ':kanal'     => $kanal,
        ]
    );
    if (!$zeile) {
        resultInfo(false, 'KLEINANZEIGEN_NOT_OFFERED', null, 'Der Artikel wird in diesem Kanal nicht angeboten');
        return;
    }
    resultInfo(true, 'KLEINANZEIGEN_SAVED', ['ad' => shopKleinanzeigenAdRow($db, $partsId, $kanal)]);
}

/**
 * Nimmt Artikel in den Kleinanzeigen-Kanal auf (Vorschläge → Entwürfe)
 *
 * Je Artikel eine aktive Kanalzeile als Entwurf; eine vorhandene wird
 * wieder eingeschaltet. Hat der Artikel im Kanal noch keine Bilder, werden
 * die eines anderen Kanals übernommen (erst eBay, dann HugoShop) — eine
 * Anzeige ohne Bild bringt nichts, und die Bilder liegen meist schon da.
 *
 * @param array $data['parts_ids'] Artikel
 * @param int $data['channel_id'] Kanal
 * @return void added, images_copied
 * @testdata {"parts_ids": [1, 2], "channel_id": 3}
 */
function addKleinanzeigenParts($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($data['parts_ids'] ?? [])), fn($id) => $id > 0)));
    if (!$ids) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Keine Artikel angegeben');
        return;
    }

    $zeilen = $db->getAll(
        "WITH aufgenommen AS (
             INSERT INTO parts_channel_shop (parts_id, channel_id, active, sync_status)
             SELECT p.id, CAST(:kanal AS integer), true, 'draft'
               FROM parts p
              WHERE p.id = ANY(CAST(:ids AS integer[]))
                AND p.part_type <> 'service'
             ON CONFLICT (parts_id, channel_id) DO UPDATE
                SET active = true,
                    sync_status = CASE WHEN COALESCE(parts_channel_shop.sync_status, 'draft') IN ('active', 'reserved')
                                       THEN parts_channel_shop.sync_status ELSE 'draft' END,
                    mtime = now()
             RETURNING parts_id
         )
         SELECT a.parts_id,
                -- Bilder: fehlen sie im Kanal, der erste andere Kanal mit Bildern
                CASE WHEN NOT EXISTS (SELECT 1 FROM parts_channel_image_shop i
                                       WHERE i.parts_id = a.parts_id AND i.channel_id = CAST(:kanal_bilder AS integer))
                     THEN COALESCE(
                         (SELECT i.channel_id FROM parts_channel_image_shop i
                            JOIN sales_channel_shop c ON c.id = i.channel_id
                           WHERE i.parts_id = a.parts_id AND i.channel_id <> CAST(:kanal_fremd AS integer)
                           ORDER BY (c.type = 'ebay') DESC, i.channel_id LIMIT 1),
                         (SELECT c.id FROM sales_channel_shop c
                            JOIN parts_ext pe ON pe.parts_id = a.parts_id
                           WHERE c.type = 'hugoshop' AND c.active
                             AND jsonb_typeof(pe.hugoshop_images) = 'array' AND jsonb_array_length(pe.hugoshop_images) > 0
                           ORDER BY c.sortkey NULLS LAST, c.id LIMIT 1))
                END AS bildquelle
           FROM aufgenommen a",
        [':kanal' => $kanal, ':ids' => '{'.implode(',', $ids).'}', ':kanal_bilder' => $kanal, ':kanal_fremd' => $kanal]
    ) ?: [];

    $kopiert = 0;
    foreach ($zeilen as $zeile) {
        if (empty($zeile['bildquelle'])) {
            continue;
        }
        try {
            $kopiert += shopChannelImageCopy($db, (int)$zeile['parts_id'], (int)$zeile['bildquelle'], $kanal);
        } catch (\Throwable $e) {
            // Ohne Bilder bleibt der Entwurf — das Board nennt es als fehlend
            writeLog('addKleinanzeigenParts: Bilder für Artikel '.$zeile['parts_id'].' nicht übernommen: '.$e->getMessage(), true, DLOG_WRN);
        }
    }

    resultInfo(true, 'KLEINANZEIGEN_ADDED', ['added' => count($zeilen), 'images_copied' => $kopiert]);
}

/**
 * Rückmeldung zum Stand einer Anzeige
 *
 *   online      eingestellt — Adresse oder Nummer der Anzeige; merkt den Stand
 *               (posted_hash), ab jetzt wacht das Board
 *   updated     Anzeige bei Kleinanzeigen auf den aktuellen Stand gebracht
 *   refreshed   Anzeige aufgefrischt (gelöscht und neu eingestellt, oder
 *               „nach oben“) — neue Nummer, wenn angegeben
 *   reserved    reserviert / unreserved: wieder frei
 *   ended       Anzeige bei Kleinanzeigen gelöscht; ein abgewählter Artikel
 *               bleibt beendet, ein angebotener wird wieder Entwurf
 *   draft       zurück auf Entwurf
 *   removed     aus dem Kanal nehmen (Entwurf verwerfen): Kanalzeile aus,
 *               beendet — wie das Abwählen in der Artikelkarte
 *
 * @param int $data['parts_id'] Artikel
 * @param int $data['channel_id'] Kanal
 * @param string $data['state'] online, updated, refreshed, reserved, unreserved, ended, draft oder removed
 * @param string $data['url'] Adresse oder Nummer der Anzeige (online, refreshed)
 * @return void ad
 * @testdata {"parts_id": 1, "channel_id": 3, "state": "online", "url": "https://www.kleinanzeigen.de/s-anzeige/bremsscheiben/2345678901-223-1234"}
 */
function setKleinanzeigenAdState($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    $stand = (string)($data['state'] ?? '');
    if (!in_array($stand, SHOP_KLEINANZEIGEN_STATES, true)) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Unbekannter Stand: '.$stand);
        return;
    }
    $url = trim((string)($data['url'] ?? ''));
    $nummer = shopKleinanzeigenAdId($url);
    if ('online' === $stand && '' === $nummer) {
        resultInfo(false, 'KLEINANZEIGEN_NO_AD_ID', null, 'Adresse oder Nummer der Anzeige fehlt');
        return;
    }
    if ('' !== $nummer && !preg_match('#^https?://#i', $url)) {
        $url = 'https://www.kleinanzeigen.de/s-anzeige/'.$nummer;
    }

    // Erst schreiben, dann lesen (siehe saveKleinanzeigenAd)
    $zeile = $db->getOne(
        "UPDATE parts_channel_shop pc
                SET active      = CASE WHEN :stand_aktiv = 'removed' THEN false ELSE pc.active END,
                    sync_status = CASE :stand
                                      WHEN 'removed'    THEN 'ended'
                                      WHEN 'online'     THEN 'active'
                                      WHEN 'reserved'   THEN 'reserved'
                                      WHEN 'unreserved' THEN 'active'
                                      WHEN 'ended'      THEN CASE WHEN pc.active THEN 'draft' ELSE 'ended' END
                                      WHEN 'draft'      THEN 'draft'
                                      ELSE pc.sync_status END,
                    external_id = CASE WHEN :stand_kennung IN ('online', 'refreshed') AND :nummer <> '' THEN :nummer_wert
                                       WHEN :stand_kennung_weg IN ('ended', 'draft', 'removed') THEN NULL
                                       ELSE pc.external_id END,
                    sync_data   = jsonb_strip_nulls(COALESCE(pc.sync_data, '{}'::jsonb) || CASE :stand_daten
                                      WHEN 'online'    THEN jsonb_build_object(
                                                                'url', CAST(:url_online AS text),
                                                                'online_since', to_char(now(), 'YYYY-MM-DD HH24:MI:SS'),
                                                                'refreshed_at', to_char(now(), 'YYYY-MM-DD HH24:MI:SS'),
                                                                'posted_hash', kleinanzeigen_ad(pc.parts_id, pc.channel_id) ->> 'hash',
                                                                'sold_out', NULL)
                                      WHEN 'updated'   THEN jsonb_build_object(
                                                                'posted_hash', kleinanzeigen_ad(pc.parts_id, pc.channel_id) ->> 'hash',
                                                                'refreshed_at', to_char(now(), 'YYYY-MM-DD HH24:MI:SS'))
                                      WHEN 'refreshed' THEN jsonb_build_object(
                                                                'refreshed_at', to_char(now(), 'YYYY-MM-DD HH24:MI:SS'),
                                                                'posted_hash', kleinanzeigen_ad(pc.parts_id, pc.channel_id) ->> 'hash',
                                                                'url', NULLIF(:url_refreshed, ''))
                                      WHEN 'ended'     THEN jsonb_build_object('url', NULL, 'online_since', NULL,
                                                                'refreshed_at', NULL, 'posted_hash', NULL, 'sold_out', NULL,
                                                                'ended_at', to_char(now(), 'YYYY-MM-DD HH24:MI:SS'))
                                      WHEN 'draft'     THEN jsonb_build_object('url', NULL, 'online_since', NULL,
                                                                'refreshed_at', NULL, 'posted_hash', NULL, 'sold_out', NULL)
                                      WHEN 'removed'   THEN jsonb_build_object('url', NULL, 'online_since', NULL,
                                                                'refreshed_at', NULL, 'posted_hash', NULL, 'sold_out', NULL)
                                      ELSE '{}'::jsonb END),
                    sync_error  = NULL,
                    sync_mtime  = now()
              WHERE pc.parts_id = CAST(:parts_id AS integer) AND pc.channel_id = CAST(:kanal AS integer)
             RETURNING pc.parts_id",
        [
            ':stand'             => $stand,
            ':stand_aktiv'       => $stand,
            ':stand_kennung'     => $stand,
            ':stand_kennung_weg' => $stand,
            ':stand_daten'       => $stand,
            ':nummer'            => $nummer,
            ':nummer_wert'       => $nummer,
            ':url_online'        => $url,
            ':url_refreshed'     => $url,
            ':parts_id'          => (int)($data['parts_id'] ?? 0),
            ':kanal'             => $kanal,
        ]
    );
    if (!$zeile) {
        resultInfo(false, 'KLEINANZEIGEN_NOT_OFFERED', null, 'Der Artikel wird in diesem Kanal nicht angeboten');
        return;
    }
    resultInfo(true, 'KLEINANZEIGEN_STATE_SAVED', ['ad' => shopKleinanzeigenAdRow($db, (int)($data['parts_id'] ?? 0), $kanal)]);
}

/**
 * Verkauf über Kleinanzeigen: Rechnung, Lagerbuchung, Käufer, Vermerk
 *
 * Käufer: ein bestehender Kunde (customer_id), ein neuer aus Name und
 * Kontakt (wie beim eBay-Import, ohne Dubletten über die E-Mail), sonst der
 * Barverkaufskunde des Kanals (default_customer_id). Die Rechnung ist brutto
 * zum erzielten Preis; gebucht wird ins Hauptbuch und, mit Lagerplatz
 * (shop_stock_bin_id), vom Lager. Die Zahlung bucht Kasse oder Bank wie bei
 * jeder anderen Rechnung — das Board merkt sich nur die Zahlart.
 *
 * Ohne Rechnung (with_invoice false) wird nur der Verkauf festgehalten.
 *
 * Danach: Anzeige bleibt online, solange Bestand da ist; sonst zeigt das
 * Board „Anzeige löschen“ (sold_out). Eine Anfrage (lead_id) gilt als
 * gewonnen.
 *
 * @param int $data['parts_id'] Artikel
 * @param int $data['channel_id'] Kanal
 * @param float $data['qty'] Menge (Vorgabe 1)
 * @param float $data['price'] erzielter Bruttopreis je Einheit
 * @param string $data['payment'] cash, transfer, paypal oder secure
 * @param bool $data['with_invoice'] Rechnung anlegen (Vorgabe true)
 * @param array $data['buyer'] customer_id oder name, email, phone, street, zipcode, city
 * @param int $data['lead_id'] Anfrage, aus der der Verkauf entstand
 * @param string $data['note'] Vermerk
 * @return void sale_id, ar_id, invnumber, customer_id, posted, ad
 * @testdata {"parts_id": 1, "channel_id": 3, "qty": 1, "price": 85, "payment": "cash", "with_invoice": true, "buyer": {"name": "Max Mustermann", "email": "max@example.de"}}
 */
function sellKleinanzeigenAd($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    $partsId = (int)($data['parts_id'] ?? 0);
    $menge = (float)($data['qty'] ?? 1);
    $preis = round((float)($data['price'] ?? 0), 2);
    $zahlart = (string)($data['payment'] ?? 'cash');
    $mitRechnung = !isset($data['with_invoice']) || !empty($data['with_invoice']);
    $kaeufer = is_array($data['buyer'] ?? null) ? $data['buyer'] : [];
    $leadId = (int)($data['lead_id'] ?? 0);

    if ($menge <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Die Menge muss größer als 0 sein');
        return;
    }
    if ($preis < 0) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Der Preis kann nicht negativ sein');
        return;
    }
    if (!in_array($zahlart, ['cash', 'transfer', 'paypal', 'secure'], true)) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Unbekannte Zahlart: '.$zahlart);
        return;
    }

    $artikel = $db->getOne(
        "SELECT p.id, p.partnumber, p.description, p.unit, p.onhand, pc.id AS pc_id
           FROM parts p
           LEFT JOIN parts_channel_shop pc ON pc.parts_id = p.id AND pc.channel_id = CAST(:kanal AS integer)
          WHERE p.id = :parts_id",
        [':parts_id' => $partsId, ':kanal' => $kanal]
    );
    if (!$artikel) {
        resultInfo(false, 'PART_NOT_FOUND', null, 'Artikel nicht gefunden');
        return;
    }

    $kundeId = (int)($kaeufer['customer_id'] ?? 0);
    $name = trim((string)($kaeufer['name'] ?? ''));
    $email = trim((string)($kaeufer['email'] ?? ''));
    $arId = null;
    $invnumber = null;

    $db->beginTransaction();
    try {
        if (0 === $kundeId && ('' !== $name || '' !== $email)) {
            // Bekannt über die E-Mail? Sonst neu — Steuerzone und Währung wie
            // bei der Kontoanlage im Shop
            $zeile = $db->getOne(
                "WITH bekannt AS (
                     SELECT id FROM customer
                      WHERE :email <> '' AND lower(email) = lower(:email_vergleich)
                      ORDER BY id LIMIT 1
                 ), neu AS (
                     INSERT INTO customer (customernumber, name, street, zipcode, city, email, phone, notes,
                                           taxzone_id, currency_id)
                     SELECT :nummer, :name, :strasse, :plz, :ort, :email_neu, :telefon, 'Kleinanzeigen-Käufer',
                            COALESCE((SELECT id FROM tax_zones WHERE description ILIKE :taxzone ORDER BY sortkey LIMIT 1),
                                     (SELECT id FROM tax_zones ORDER BY sortkey LIMIT 1)),
                            COALESCE((SELECT id FROM currencies WHERE name ILIKE :currency LIMIT 1),
                                     (SELECT currency_id FROM defaults))
                      WHERE NOT EXISTS (SELECT 1 FROM bekannt)
                     RETURNING id
                 )
                 SELECT COALESCE((SELECT id FROM bekannt), (SELECT id FROM neu)) AS id",
                [
                    ':email'           => $email,
                    ':email_vergleich' => $email,
                    ':nummer'          => nextFreeNumber($db, 'customernumber', 'customer', 'customernumber'),
                    ':name'            => '' !== $name ? $name : $email,
                    ':strasse'         => trim((string)($kaeufer['street'] ?? '')),
                    ':plz'             => trim((string)($kaeufer['zipcode'] ?? '')),
                    ':ort'             => trim((string)($kaeufer['city'] ?? '')),
                    ':email_neu'       => $email,
                    ':telefon'         => trim((string)($kaeufer['phone'] ?? '')),
                    ':taxzone'         => shopConfigValue($db, 'shop_standard_taxzone', 'Inland'),
                    ':currency'        => shopConfigValue($db, 'shop_standard_currency', 'EUR'),
                ]
            );
            $kundeId = (int)($zeile['id'] ?? 0);
        }
        if (0 === $kundeId) {
            $kundeId = (int)shopChannelValue($db, $kanal, 'default_customer_id', '0');
        }
        if ($mitRechnung) {
            if (0 === $kundeId || !$db->getOne("SELECT 1 FROM customer WHERE id = :id", [':id' => $kundeId])) {
                throw new ApiError('KLEINANZEIGEN_NO_CUSTOMER',
                    'Für die Rechnung fehlt der Käufer: Kunde wählen, Name eingeben oder einen Barverkaufskunden im Kanal hinterlegen');
            }

            // Kleinanzeigen-Preise sind brutto
            $rechnung = createFakturaCore($db, 'invoice', $kundeId, 'C', shopKleinanzeigenEmployeeId($db, $kanal), ['taxincluded' => true]);
            $arId = (int)$rechnung['id'];
            createFakturaItemCore($db, 'invoice', $arId, [
                'parts_id'        => $partsId,
                'description'     => (string)$artikel['description'],
                'longdescription' => '',
                'qty'             => $menge,
                'sellprice'       => $preis,
                'discount'        => 0,
                'unit'            => trim((string)$artikel['unit']) ?: 'Stck',
            ]);
            // Brutto = Menge mal Preis, netto über den Steuersatz der
            // Buchungsgruppe in der Shop-Steuerzone (wie der Kanalpreis)
            $betraege = $db->getOne(
                "UPDATE ar
                    SET amount    = CAST(:betrag AS numeric),
                        netamount = ROUND(CAST(:betrag_netto AS numeric)
                                          / (1 + shop_tax_rate((SELECT buchungsgruppen_id FROM parts WHERE id = CAST(:parts_id AS integer)), :zone)), 2)
                  WHERE id = :id
                 RETURNING invnumber",
                [':betrag' => round($menge * $preis, 2), ':betrag_netto' => round($menge * $preis, 2),
                 ':parts_id' => $partsId, ':zone' => shopConfigValue($db, 'shop_standard_taxzone', 'Inland'), ':id' => $arId]
            );
            $invnumber = (string)($betraege['invnumber'] ?? ($rechnung['invnumber'] ?? ''));
        }

        $verkauf = $db->getOne(
            "WITH verkauf AS (
                 INSERT INTO kleinanzeigen_sale_shop (channel_id, parts_id, ar_id, customer_id, lead_id, qty, price, payment, buyer_name, note)
                 VALUES (CAST(:kanal AS integer), CAST(:parts_id AS integer), CAST(:ar_id AS integer),
                         NULLIF(CAST(:kunde AS integer), 0), NULLIF(CAST(:lead AS integer), 0),
                         CAST(:menge AS numeric), CAST(:preis AS numeric), :zahlart, NULLIF(:kaeufer, ''), NULLIF(:vermerk, ''))
                 RETURNING id
             ), anzeige AS (
                 -- Ausverkauft, wenn die Menge den Bestand aufbraucht: das Board
                 -- zeigt dann „Anzeige löschen“, auch ohne Lagerbuchung
                 UPDATE parts_channel_shop pc
                    SET sync_data = jsonb_strip_nulls(COALESCE(pc.sync_data, '{}'::jsonb)
                                    || jsonb_build_object('sold_out', CASE WHEN CAST(:menge_bestand AS numeric) >= COALESCE(:bestand, 0) THEN true END,
                                                          'last_sale', to_char(now(), 'YYYY-MM-DD HH24:MI:SS'))),
                        sync_status = CASE WHEN pc.sync_status = 'reserved' THEN 'active' ELSE pc.sync_status END,
                        sync_mtime = now()
                  WHERE pc.parts_id = CAST(:parts_id_anzeige AS integer) AND pc.channel_id = CAST(:kanal_anzeige AS integer)
             ), anfrage AS (
                 UPDATE kleinanzeigen_lead_shop
                    SET status = 'won', customer_id = COALESCE(customer_id, NULLIF(CAST(:kunde_anfrage AS integer), 0)), mtime = now()
                  WHERE id = CAST(:lead_anfrage AS integer) AND CAST(:lead_anfrage_gesetzt AS integer) > 0
             )
             SELECT id FROM verkauf",
            [
                ':kanal'    => $kanal,
                ':parts_id' => $partsId,
                ':ar_id'    => $arId,
                ':kunde'    => $kundeId,
                ':lead'     => $leadId,
                ':menge'    => $menge,
                ':preis'    => $preis,
                ':zahlart'  => $zahlart,
                ':kaeufer'  => '' !== $name ? $name : (string)($kaeufer['name'] ?? ''),
                ':vermerk'  => (string)($data['note'] ?? ''),
                ':menge_bestand' => $menge,
                ':bestand'  => (float)($artikel['onhand'] ?? 0),
                ':parts_id_anzeige' => $partsId,
                ':kanal_anzeige'    => $kanal,
                ':kunde_anfrage'    => $kundeId,
                ':lead_anfrage'     => $leadId,
                ':lead_anfrage_gesetzt' => $leadId,
            ]
        );
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // Buchen und Lager — nach der Transaktion, wie beim eBay-Import: Rechnung
    // und Verkauf stehen, ein Fehler hier wird gemeldet, nicht verschwiegen
    $gebucht = false;
    $grund = '';
    if (null !== $arId) {
        try {
            // Trockenlauf: der Bruttobetrag, wie das Hauptbuch ihn rechnet —
            // so scheitert die Prüfung nicht an einem Rundungscent
            $probe = postArInvoiceToLedger($db, $arId, true);
            if (isset($probe['gross'])) {
                $db->execute("UPDATE ar SET amount = :brutto WHERE id = :ar_id",
                             [':brutto' => $probe['gross'], ':ar_id' => $arId]);
            }
            $buchung = postArInvoiceToLedger($db, $arId);
            $gebucht = !empty($buchung['posted']);
            $grund = $gebucht ? '' : (string)($buchung['reason'] ?? 'UNKNOWN');
        } catch (\Throwable $e) {
            $grund = $e->getMessage();
        }
        shopBookStock($db, $arId);
    }

    resultInfo(true, 'KLEINANZEIGEN_SOLD', [
        'sale_id'     => (int)($verkauf['id'] ?? 0),
        'ar_id'       => $arId,
        'invnumber'   => $invnumber,
        'customer_id' => $kundeId ?: null,
        'posted'      => $gebucht,
        'post_reason' => $grund,
        'ad'          => shopKleinanzeigenAdRow($db, $partsId, $kanal),
    ]);
}

/**
 * Stand einer Anfrage: beantwortet, gewonnen, verloren, offen — und die
 * Zuordnung zu einem Artikel, wenn die Mail sie nicht hergab
 *
 * @param int $data['lead_id'] Anfrage
 * @param string $data['status'] open, answered, won oder lost; fehlt = unverändert
 * @param int|null $data['parts_id'] Artikel zuordnen; fehlt = unverändert
 * @param string $data['note'] Vermerk; fehlt = unverändert
 * @return void
 * @testdata {"lead_id": 1, "status": "answered"}
 */
function setKleinanzeigenLeadStatus($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $stand = (string)($data['status'] ?? '');
    if ('' !== $stand && !in_array($stand, ['open', 'answered', 'won', 'lost'], true)) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Unbekannter Stand: '.$stand);
        return;
    }

    $zeile = $db->getOne(
        "UPDATE kleinanzeigen_lead_shop
            SET status   = CASE WHEN :stand <> '' THEN :stand_wert ELSE status END,
                parts_id = CASE WHEN :artikel_setzen = 1 THEN NULLIF(CAST(:parts_id AS integer), 0) ELSE parts_id END,
                note     = CASE WHEN :vermerk_setzen = 1 THEN NULLIF(:vermerk, '') ELSE note END,
                mtime    = now()
          WHERE id = CAST(:id AS integer)
         RETURNING id",
        [
            ':stand'          => $stand,
            ':stand_wert'     => $stand,
            ':artikel_setzen' => array_key_exists('parts_id', $data) ? 1 : 0,
            ':parts_id'       => (int)($data['parts_id'] ?? 0),
            ':vermerk_setzen' => array_key_exists('note', $data) ? 1 : 0,
            ':vermerk'        => (string)($data['note'] ?? ''),
            ':id'             => (int)($data['lead_id'] ?? 0),
        ]
    );
    if (!$zeile) {
        resultInfo(false, 'KLEINANZEIGEN_LEAD_NOT_FOUND', null, 'Diese Anfrage gibt es nicht');
        return;
    }
    resultInfo(true, 'KLEINANZEIGEN_LEAD_SAVED');
}

/**
 * Holt die Anfragen aus dem Postfach — jetzt, auf Knopfdruck
 *
 * Der regelmäßige Abruf läuft über den Läufer (tools/shop-publish.php
 * --reconcile-payments legt den Auftrag import_leads an).
 *
 * @param int $data['channel_id'] Kanal
 * @return void fetched, imported, matched
 * @testdata {"channel_id": 3}
 */
function importKleinanzeigenLeads($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    resultInfo(true, 'KLEINANZEIGEN_LEADS_IMPORTED', shopKleinanzeigenImportLeads($db, shopKleinanzeigenChannel($db, $data)));
}

/**
 * Ruft Claude auf und liefert den Antworttext
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $prompt Aufgabe
 * @param string|null $modell Modellwahl aus dem Request
 * @param int $maxTokens Antwortlänge
 * @return string
 * @throws ApiError MISSING_API_KEYS, CLAUDE_API_ERROR
 */
function shopKleinanzeigenAsk($db, string $prompt, ?string $modell, int $maxTokens = 1200): string {
    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('anthropic_api_key', '".aiModelConfigKey('kleinanzeigen')."')"
    );
    $schluessel = trim($config['anthropic_api_key'] ?? '');
    if ('' === $schluessel) {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key ist nicht konfiguriert (Firmenkonfiguration → KI)');
    }

    $curl = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'      => resolveAiModel($config, 'kleinanzeigen', $modell),
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: '.$schluessel,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $antwort = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $fehler = curl_error($curl);
    curl_close($curl);

    if ($fehler) {
        throw new ApiError('CLAUDE_API_ERROR', 'cURL-Fehler: '.$fehler);
    }
    if (200 !== $status) {
        throw new ApiError('CLAUDE_API_ERROR', 'Claude API Fehler (HTTP '.$status.'): '.$antwort);
    }
    $text = aiResponseText(json_decode((string)$antwort, true));
    if ('' === $text) {
        throw new ApiError('CLAUDE_API_ERROR', 'Leere Antwort von Claude');
    }
    return $text;
}

/**
 * Lässt Claude Titel und Text einer Anzeige schreiben
 *
 * Aus Artikelstamm, Shop-Angaben (technische Daten, Eigenschaften), Preis
 * und den Vorgaben des Kanals (Tonfall). Nichts wird gespeichert — der
 * Benutzer übernimmt den Vorschlag in der Anzeige oder nicht.
 *
 * @param int $data['parts_id'] Artikel
 * @param int $data['channel_id'] Kanal
 * @param string $data['hint'] Hinweis an die KI (Zustand, Besonderheiten, Zielgruppe)
 * @param string $data['ai_model'] Modellwahl für diese Sitzung
 * @return void title, text
 * @testdata {"parts_id": 1, "channel_id": 3, "hint": "Neuteil, Originalverpackung"}
 */
function generateKleinanzeigenText($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    set_time_limit(90);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    $partsId = (int)($data['parts_id'] ?? 0);

    $zeile = $db->getOne(
        "SELECT kleinanzeigen_ad(CAST(:parts_id AS integer), CAST(:kanal AS integer)) AS ad,
                (SELECT row_to_json(a) FROM (
                    SELECT p.partnumber, p.description, kleinanzeigen_plain_text(p.notes) AS notes, p.ean, p.unit, p.onhand,
                           pe.hugoshop_technical_data AS technical_data, pe.hugoshop_properties AS properties,
                           (SELECT pg.partsgroup FROM partsgroup pg WHERE pg.id = p.partsgroup_id) AS partsgroup
                      FROM parts p
                      LEFT JOIN parts_ext pe ON pe.parts_id = p.id
                     WHERE p.id = CAST(:parts_id_stamm AS integer)) a) AS part,
                (SELECT c.settings ->> 'text_style' FROM sales_channel_shop c WHERE c.id = CAST(:kanal_stil AS integer)) AS stil,
                (SELECT company FROM defaults LIMIT 1) AS firma",
        [':parts_id' => $partsId, ':kanal' => $kanal, ':parts_id_stamm' => $partsId, ':kanal_stil' => $kanal]
    );
    $anzeige = json_decode((string)($zeile['ad'] ?? 'null'), true);
    $artikel = json_decode((string)($zeile['part'] ?? 'null'), true);
    if (!$anzeige || !$artikel) {
        resultInfo(false, 'PART_NOT_FOUND', null, 'Artikel nicht gefunden');
        return;
    }

    $stil = 'werblich' === (string)($zeile['stil'] ?? '') ? 'werblich und lebendig, aber ehrlich' : 'sachlich und vertrauenswürdig';
    $hinweis = trim((string)($data['hint'] ?? ''));
    $daten = [
        'Artikelnummer'  => $artikel['partnumber'],
        'Bezeichnung'    => $artikel['description'],
        'Beschreibung'   => $artikel['notes'],
        'EAN'            => $artikel['ean'],
        'Warengruppe'    => $artikel['partsgroup'],
        'Technische Daten' => $artikel['technical_data'],
        'Eigenschaften'  => $artikel['properties'],
        'Preis (EUR)'    => $anzeige['price'],
        'Preisart'       => $anzeige['price_type'],
        'Zustand'        => $anzeige['condition'],
        'Versand'        => $anzeige['shipping'],
        'Verkäufer'      => $zeile['firma'],
    ];
    $block = '';
    foreach ($daten as $name => $wert) {
        if (null === $wert || '' === $wert || [] === $wert) {
            continue;
        }
        $block .= $name.': '.(is_array($wert) ? json_encode($wert, JSON_UNESCAPED_UNICODE) : $wert)."\n";
    }

    $prompt = <<<PROMPT
Schreibe eine Anzeige für kleinanzeigen.de (gewerblicher Verkäufer), Tonfall {$stil}.

Regeln:
- Titel: höchstens 65 Zeichen, die wichtigsten Suchbegriffe zuerst (Marke, Modell, Teilebezeichnung, Passform), keine Füllwörter, keine Großschreibung ganzer Wörter.
- Text: höchstens 1500 Zeichen, reiner Text ohne Markdown. Kurze Absätze. Erster Absatz: was es ist und für wen. Dann Zustand, Lieferumfang, Passform/Kompatibilität, Abholung oder Versand. Keine erfundenen Angaben — was nicht in den Daten steht, nicht behaupten.
- Nenne keine Preise im Text (der Preis steht im Preisfeld).
- Keine Telefonnummern, keine E-Mail-Adressen, keine Links.
- Sprache: Deutsch, Du-Form wie auf Kleinanzeigen üblich.

Antworte ausschließlich als JSON mit den Schlüsseln "title" und "text".

DATEN:
{$block}
PROMPT;
    if ('' !== $hinweis) {
        $prompt .= "\nHINWEIS DES VERKÄUFERS:\n".$hinweis."\n";
    }

    $antwort = shopKleinanzeigenAsk($db, $prompt, $data['ai_model'] ?? null, 1500);
    $antwort = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($antwort));
    $ergebnis = json_decode($antwort, true);
    if (!is_array($ergebnis) || empty($ergebnis['title'])) {
        // Kein JSON: die erste Zeile als Titel, der Rest als Text
        $zeilen = preg_split('/\R/', trim($antwort), 2);
        $ergebnis = ['title' => trim($zeilen[0] ?? ''), 'text' => trim($zeilen[1] ?? '')];
    }

    resultInfo(true, '', [
        'title' => mb_substr(trim((string)$ergebnis['title']), 0, 65),
        'text'  => mb_substr(trim((string)($ergebnis['text'] ?? '')), 0, 4000),
    ]);
}

/**
 * Lässt Claude eine Antwort auf eine Anfrage vorschlagen
 *
 * Die Antwort wird bei Kleinanzeigen selbst geschickt; hier nur der
 * Vorschlag zum Kopieren — mit Anzeige, Preis, Verfügbarkeit und der
 * Nachricht des Interessenten. Ziel: schnell, freundlich, zum Abschluss.
 *
 * @param int $data['lead_id'] Anfrage
 * @param string $data['ai_model'] Modellwahl für diese Sitzung
 * @return void reply
 * @testdata {"lead_id": 1}
 */
function suggestKleinanzeigenReply($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    set_time_limit(90);
    $db = DbhCompany::begin();

    $zeile = $db->getOne(
        "SELECT l.sender_name, l.message, l.ad_title, l.channel_id,
                CASE WHEN l.parts_id IS NOT NULL THEN kleinanzeigen_ad(l.parts_id, l.channel_id) END AS ad,
                (SELECT company FROM defaults LIMIT 1) AS firma,
                (SELECT c.settings ->> 'text_style' FROM sales_channel_shop c WHERE c.id = l.channel_id) AS stil
           FROM kleinanzeigen_lead_shop l
          WHERE l.id = CAST(:id AS integer)",
        [':id' => (int)($data['lead_id'] ?? 0)]
    );
    if (!$zeile) {
        resultInfo(false, 'KLEINANZEIGEN_LEAD_NOT_FOUND', null, 'Diese Anfrage gibt es nicht');
        return;
    }
    $anzeige = json_decode((string)($zeile['ad'] ?? 'null'), true) ?: [];
    $anzeigeBlock = $anzeige
        ? "Titel: ".$anzeige['title']."\nPreis: ".$anzeige['price']." EUR (".$anzeige['price_type'].")\nVersand: ".$anzeige['shipping']
          ."\nVerfügbar: ".((float)($anzeige['onhand'] ?? 0) > 0 ? 'ja, Bestand '.$anzeige['onhand'] : 'nein')."\nText: ".$anzeige['text']
        : "Titel: ".(string)$zeile['ad_title'];

    $prompt = <<<PROMPT
Du antwortest für den gewerblichen Verkäufer „{$zeile['firma']}“ auf eine Anfrage bei kleinanzeigen.de. Ziel: freundlich, kurz, konkret, zum Abschluss führen (Abholtermin oder Versand anbieten, bei Preisfragen höflich beim Preis bleiben oder einen kleinen Spielraum lassen, wenn die Preisart Verhandlungsbasis ist). Du-Form, 2 bis 5 Sätze, reiner Text ohne Markdown, keine Platzhalter in eckigen Klammern. Antworte nur mit dem Nachrichtentext.

ANZEIGE:
{$anzeigeBlock}

ANFRAGE VON {$zeile['sender_name']}:
{$zeile['message']}
PROMPT;

    resultInfo(true, '', ['reply' => shopKleinanzeigenAsk($db, $prompt, $data['ai_model'] ?? null, 600)]);
}

/**
 * Alle Bilder einer Anzeige als ZIP — zum Hochladen bei Kleinanzeigen in einem Zug
 *
 * Reihenfolge wie im Kanal, Dateinamen nummeriert (01-, 02-, …), damit die
 * Auswahl im Datei-Dialog die Reihenfolge behält.
 *
 * @param int $data['parts_id'] Artikel
 * @param int $data['channel_id'] Kanal
 * @return void filename, mime, data (base64)
 * @testdata {"parts_id": 1, "channel_id": 3}
 */
function getKleinanzeigenImagesZip($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    $partsId = (int)($data['parts_id'] ?? 0);

    $zeile = $db->getOne(
        "SELECT p.partnumber,
                (SELECT COALESCE(json_agg(i.filename ORDER BY i.sort, i.id), '[]'::json)
                   FROM parts_channel_image_shop i
                  WHERE i.parts_id = p.id AND i.channel_id = CAST(:kanal AS integer)) AS dateien
           FROM parts p WHERE p.id = :parts_id",
        [':parts_id' => $partsId, ':kanal' => $kanal]
    );
    $dateien = $zeile ? (json_decode((string)$zeile['dateien'], true) ?: []) : [];
    if (!$dateien) {
        resultInfo(false, 'KLEINANZEIGEN_NO_IMAGES', null, 'Der Artikel hat in diesem Kanal keine Bilder');
        return;
    }

    $verzeichnis = shopChannelImageDir($db, $partsId);
    $zipPfad = tempnam(sys_get_temp_dir(), 'ka-');
    $zip = new ZipArchive();
    if (true !== $zip->open($zipPfad, ZipArchive::OVERWRITE)) {
        throw new ApiError('ZIP_ERROR', 'ZIP-Datei konnte nicht angelegt werden');
    }
    $nummer = 0;
    foreach ($dateien as $datei) {
        $pfad = $verzeichnis.'/'.basename((string)$datei);
        if (is_file($pfad)) {
            $nummer++;
            $zip->addFile($pfad, sprintf('%02d-%s.%s', $nummer, preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$zeile['partnumber']),
                                                 strtolower(pathinfo($pfad, PATHINFO_EXTENSION))));
        }
    }
    $zip->close();
    $inhalt = (string)file_get_contents($zipPfad);
    unlink($zipPfad);

    resultInfo(true, '', [
        'filename' => preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$zeile['partnumber']).'-kleinanzeigen.zip',
        'mime'     => 'application/zip',
        'data'     => base64_encode($inhalt),
        'count'    => $nummer,
    ]);
}

/**
 * Adresse des Anzeigen-Feeds dieses Kanals — für Sync-Werkzeuge
 *
 * Der Feed (backend/webhook/kleinanzeigen-feed.php) liefert alle Entwürfe und
 * Online-Anzeigen des Kanals als CSV oder JSON, geschützt durch den
 * Feed-Schlüssel des Kanals. Nur für Benutzer, die die Kanaleinstellungen
 * pflegen dürfen — der Schlüssel steht in der Adresse.
 *
 * @param int $data['channel_id'] Kanal
 * @return void url_csv, url_json — leer, wenn kein Schlüssel hinterlegt ist
 * @testdata {"channel_id": 3}
 */
function getKleinanzeigenFeedUrl($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    $schluessel = trim(shopChannelValue($db, $kanal, 'feed_key'));
    if ('' === $schluessel) {
        resultInfo(true, '', ['url_csv' => '', 'url_json' => '']);
        return;
    }
    $zeile = $db->getOne("SELECT current_database() AS name");
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $schema = (!empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS']) || 'https' === ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') ? 'https' : 'http';
    $basis = $schema.'://'.$host.'/webhook/kleinanzeigen-feed.php?db='.rawurlencode((string)$zeile['name'])
           .'&channel='.$kanal.'&key='.rawurlencode($schluessel);
    resultInfo(true, '', ['url_csv' => $basis.'&format=csv', 'url_json' => $basis.'&format=json']);
}

// ── Kleinanzeigen-Dienst: Anmeldung, Einstellen, Abgleich, Antworten ──
//
// Der Dienst (backend/services/kleinanzeigen) ist ein Headless-Chrome mit dem
// Konto des Kanals. Die Funktionen hier rufen ihn direkt (für die Knöpfe im
// Board und in der Kanalkarte); die Aufträge der Warteschlange nutzen
// dieselben Bausteine (lib/kleinanzeigen_worker.php).

/**
 * Antwort mit Fehler des Dienstes, samt Bildschirmfoto für die Fehlersuche
 *
 * @param ApiError $e Fehler aus shopKleinanzeigenWorkerCall()
 * @return void
 */
function shopKleinanzeigenWorkerFail(ApiError $e): void {
    resultInfo(false, $e->getId(), shopKleinanzeigenWorkerDetail() ?: null, $e->getMessage());
}

/**
 * Stand des Kleinanzeigen-Dienstes für einen Kanal
 *
 * Ohne check nur, ob der Dienst eingeschaltet und erreichbar ist und ob
 * Konto und Anmelde-IDs hinterlegt sind. Mit check fragt der Browser die
 * Webseite: angemeldet, Captcha, gesperrt, abgemeldet — das dauert einige Sekunden.
 *
 * @param int $data['channel_id'] Kanal
 * @param bool $data['check'] Anmeldung in der Webseite prüfen (spielt bei Bedarf die hinterlegten Anmelde-IDs ein)
 * @return void enabled, reachable, version, has_login, has_cookies, automatic, session {state, logged_in, ids}
 * @testdata {"channel_id": 3, "check": false}
 */
function getKleinanzeigenWorkerStatus($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    $cfg = shopChannelConfig($db, $kanal);
    $stand = shopKleinanzeigenWorkerHealth($db);
    $stand['has_login'] = '' !== trim((string)($cfg['login_email'] ?? ''));
    $stand['has_cookies'] = '' !== trim((string)($cfg['login_cookies'] ?? ''));
    $stand['login_email'] = trim((string)($cfg['login_email'] ?? ''));
    $stand['automatic'] = shopKleinanzeigenAutomatic($db, $kanal);
    $stand['ads_last_sync'] = $cfg['ads_last_sync'] ?? null;
    $stand['ads_online_count'] = $cfg['ads_online_count'] ?? null;
    if (!empty($data['check']) && !empty($stand['reachable'])) {
        try {
            $stand['session'] = shopKleinanzeigenWorkerCall($db, $kanal, '/session/status', [], 90);
            if (empty($stand['session']['logged_in']) && $stand['has_cookies']) {
                // Profil verloren oder Browser neu: die hinterlegten IDs einspielen
                $stand['session'] = shopKleinanzeigenRestoreSession($db, $kanal);
            }
        } catch (ApiError $e) {
            $stand['session'] = ['state' => 'error', 'message' => $e->getMessage()] + shopKleinanzeigenWorkerDetail();
        }
    }
    resultInfo(true, '', $stand);
}

/**
 * Übernimmt die Anmelde-IDs aus dem Browser des Benutzers
 *
 * Der Dienst meldet sich nie selbst an. Der Benutzer meldet sich in seinem
 * eigenen Browser bei Kleinanzeigen an und kopiert die Cookies der Seite
 * (mindestens refresh_token; die Kanalkarte nimmt JSON-Export, cookies.txt,
 * „name=wert“-Zeilen oder die Tabelle der Entwicklerwerkzeuge). Die IDs
 * gehen an den Dienst, der sie in sein Browserprofil setzt und die Sitzung
 * prüft. Angemeldet, werden alle Cookies der Sitzung als Geheimnis
 * login_cookies im Kanal aufgehoben — so spielt OSERP sie nach einem
 * Profilverlust von selbst wieder ein (shopKleinanzeigenRestoreSession).
 *
 * @param int $data['channel_id'] Kanal
 * @param array $data['cookies'] Liste {name, value, domain?, path?, expires?, httpOnly?, secure?, sameSite?}
 * @return void state, logged_in, ids [{name, domain, expires}], set
 * @testdata {"channel_id": 3, "cookies": [{"name": "refresh_token", "value": "…", "domain": ".kleinanzeigen.de"}]}
 */
function saveKleinanzeigenSession($data) {
    permit(['edit_shop_config'], false);
    set_time_limit(180);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    $cookies = [];
    foreach ((array)($data['cookies'] ?? []) as $c) {
        if (!is_array($c) || '' === trim((string)($c['name'] ?? ''))) {
            continue;
        }
        $cookies[] = array_intersect_key($c, array_flip(['name', 'value', 'domain', 'path', 'expires', 'httpOnly', 'secure', 'sameSite', 'session']));
    }
    if (!$cookies) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Keine Anmelde-IDs erkannt');
        return;
    }
    try {
        $antwort = shopKleinanzeigenWorkerCall($db, $kanal, '/session/restore', ['cookies' => $cookies], 120);
    } catch (ApiError $e) {
        shopKleinanzeigenWorkerFail($e);
        return;
    }
    if (!empty($antwort['logged_in'])) {
        $db->execute(
            "INSERT INTO sales_channel_secret_shop (channel_id, key, value)
             VALUES (CAST(:kanal AS integer), 'login_cookies', CAST(:cookies AS text))
             ON CONFLICT (channel_id, key) DO UPDATE SET value = EXCLUDED.value, mtime = now()",
            [':kanal' => $kanal, ':cookies' => json_encode($antwort['cookies'] ?? $cookies, JSON_UNESCAPED_SLASHES)]
        );
        shopChannelConfig($db, $kanal, true);
    }
    unset($antwort['cookies']);
    resultInfo(true, '', $antwort);
}

/**
 * Kategoriebaum von Kleinanzeigen für die Auswahl
 *
 * Mandantenweit (kleinanzeigen_category_shop), vom Dienst gelesen
 * (syncKleinanzeigenCategories). Blätter sind einstellbar; Zwischenebenen
 * kommen mit, damit die Auswahl sie als Gruppen zeigen kann.
 *
 * @return void categories [{path, parent, name, level, category_id, is_leaf}], count, mtime
 * @testdata {}
 */
function getKleinanzeigenCategories($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $zeile = $db->getOne(
        "SELECT COALESCE(jsonb_agg(jsonb_build_object(
                    'path', c.path, 'parent', c.parent, 'name', c.name, 'level', c.level,
                    'category_id', c.category_id, 'is_leaf', c.is_leaf)
                ORDER BY c.level, c.parent, c.sort), '[]'::jsonb) AS categories,
                count(*) AS count,
                to_char(max(c.mtime), 'YYYY-MM-DD HH24:MI') AS mtime
           FROM kleinanzeigen_category_shop c"
    );
    resultInfo(true, '', [
        'categories' => json_decode($zeile['categories'] ?? '[]', true) ?: [],
        'count'      => (int)($zeile['count'] ?? 0),
        'mtime'      => $zeile['mtime'] ?? null,
    ]);
}

/**
 * Liest den Kategoriebaum über den Dienst neu ein
 *
 * Der Dienst läuft den Kategorie-Dialog des Anzeigenformulars ab (einige
 * Minuten) und ergänzt die Nummern der Übersichtsseite; der Baum ersetzt
 * den gespeicherten in einem Vorgang.
 *
 * @param int $data['channel_id'] Kanal mit angemeldetem Dienst
 * @return void count, leaves
 * @testdata {"channel_id": 3}
 */
function syncKleinanzeigenCategories($data) {
    permit(['edit_shop_config'], false);
    set_time_limit(900);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    try {
        $antwort = shopKleinanzeigenWorkerCallLoggedIn($db, $kanal, '/categories', [], 850);
    } catch (ApiError $e) {
        shopKleinanzeigenWorkerFail($e);
        return;
    }
    $baum = array_values(array_filter((array)($antwort['categories'] ?? []), fn($c) => is_array($c) && '' !== trim((string)($c['path'] ?? ''))));
    if (count($baum) < 10) {
        resultInfo(false, 'KLEINANZEIGEN_CATEGORY_TREE', null, 'Der Dienst hat keinen brauchbaren Kategoriebaum geliefert ('.count($baum).' Einträge)');
        return;
    }
    $zeile = $db->getOne(
        "WITH neu AS (
             SELECT e.path, NULLIF(e.parent, '') AS parent, e.name, e.level, e.category_id, COALESCE(e.is_leaf, false) AS is_leaf, COALESCE(e.sort, 0) AS sort
               FROM jsonb_to_recordset(CAST(:baum AS jsonb))
                    AS e(path text, parent text, name text, level integer, category_id integer, is_leaf boolean, sort integer)
         ), weg AS (
             DELETE FROM kleinanzeigen_category_shop WHERE path NOT IN (SELECT path FROM neu) RETURNING path
         ), rein AS (
             INSERT INTO kleinanzeigen_category_shop (path, parent, name, level, category_id, is_leaf, sort, mtime)
             SELECT path, parent, name, level, category_id, is_leaf, sort, now() FROM neu
             ON CONFLICT (path) DO UPDATE
                SET parent = EXCLUDED.parent, name = EXCLUDED.name, level = EXCLUDED.level, category_id = EXCLUDED.category_id,
                    is_leaf = EXCLUDED.is_leaf, sort = EXCLUDED.sort, mtime = now()
             RETURNING is_leaf
         )
         SELECT (SELECT count(*) FROM rein) AS count, (SELECT count(*) FROM rein WHERE is_leaf) AS leaves, (SELECT count(*) FROM weg) AS removed",
        [':baum' => json_encode($baum, JSON_UNESCAPED_UNICODE)]
    );
    resultInfo(true, '', ['count' => (int)$zeile['count'], 'leaves' => (int)$zeile['leaves'], 'removed' => (int)$zeile['removed']]);
}

/**
 * Stellt die Anzeige eines Artikels jetzt bei Kleinanzeigen ein oder aktualisiert sie
 *
 * Über den Dienst, sofort — der Knopf im Board. Die Aufträge der
 * Warteschlange tun dasselbe im Hintergrund.
 *
 * @param int $data['parts_id'] Artikel
 * @param int $data['channel_id'] Kanal
 * @return void id, url, updated, ad
 * @testdata {"parts_id": 1, "channel_id": 3}
 */
function publishKleinanzeigenAd($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    set_time_limit(600);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    $partsId = (int)($data['parts_id'] ?? 0);
    try {
        $ergebnis = shopKleinanzeigenPublishViaWorker($db, $kanal, $partsId);
    } catch (ApiError $e) {
        $db->execute(
            "UPDATE parts_channel_shop SET sync_error = :meldung, sync_mtime = now()
              WHERE parts_id = :parts_id AND channel_id = CAST(:kanal AS integer)",
            [':meldung' => mb_substr($e->getMessage(), 0, 500), ':parts_id' => $partsId, ':kanal' => $kanal]
        );
        shopKleinanzeigenWorkerFail($e);
        return;
    }
    resultInfo(true, 'KLEINANZEIGEN_PUBLISHED', $ergebnis + ['ad' => shopKleinanzeigenAdRow($db, $partsId, $kanal)]);
}

/**
 * Löscht die Anzeige eines Artikels bei Kleinanzeigen
 *
 * Der Artikel bleibt im Kanal (Entwurf), sofern er angeboten ist.
 *
 * @param int $data['parts_id'] Artikel
 * @param int $data['channel_id'] Kanal
 * @return void deleted, ad
 * @testdata {"parts_id": 1, "channel_id": 3}
 */
function deleteKleinanzeigenAd($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    set_time_limit(300);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    $partsId = (int)($data['parts_id'] ?? 0);
    try {
        $ergebnis = shopKleinanzeigenDeleteViaWorker($db, $kanal, $partsId);
    } catch (ApiError $e) {
        shopKleinanzeigenWorkerFail($e);
        return;
    }
    resultInfo(true, 'KLEINANZEIGEN_DELETED', $ergebnis + ['ad' => shopKleinanzeigenAdRow($db, $partsId, $kanal)]);
}

/**
 * Frischt die Anzeige eines Artikels auf
 *
 * Erst „Verlängern“ bei Kleinanzeigen (geht nur in den letzten Tagen vor
 * Ablauf); klappt das nicht, wird die Anzeige gelöscht und neu eingestellt —
 * so steht sie wieder oben.
 *
 * @param int $data['parts_id'] Artikel
 * @param int $data['channel_id'] Kanal
 * @return void method (extended oder republished), id, url, ad
 * @testdata {"parts_id": 1, "channel_id": 3}
 */
function extendKleinanzeigenAd($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    set_time_limit(900);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    $partsId = (int)($data['parts_id'] ?? 0);
    $zeile = $db->getOne(
        "SELECT external_id FROM parts_channel_shop WHERE parts_id = :parts_id AND channel_id = CAST(:kanal AS integer)",
        [':parts_id' => $partsId, ':kanal' => $kanal]
    );
    $nummer = trim((string)($zeile['external_id'] ?? ''));
    try {
        $methode = 'republished';
        if ('' !== $nummer) {
            try {
                shopKleinanzeigenWorkerCall($db, $kanal, '/ads/extend', ['id' => $nummer], 120);
                $methode = 'extended';
            } catch (ApiError $e) {
                if ('KLEINANZEIGEN_EXTEND_BUTTON_MISSING' !== $e->getId()) {
                    throw $e;
                }
                shopKleinanzeigenDeleteViaWorker($db, $kanal, $partsId);
            }
        }
        if ('extended' === $methode) {
            $db->execute(
                "UPDATE parts_channel_shop
                    SET sync_data = COALESCE(sync_data, '{}'::jsonb) || jsonb_build_object('refreshed_at', to_char(now(), 'YYYY-MM-DD HH24:MI:SS')),
                        sync_mtime = now()
                  WHERE parts_id = :parts_id AND channel_id = CAST(:kanal AS integer)",
                [':parts_id' => $partsId, ':kanal' => $kanal]
            );
            $ergebnis = ['id' => $nummer];
        } else {
            $ergebnis = shopKleinanzeigenPublishViaWorker($db, $kanal, $partsId);
        }
    } catch (ApiError $e) {
        shopKleinanzeigenWorkerFail($e);
        return;
    }
    resultInfo(true, 'KLEINANZEIGEN_REFRESHED', $ergebnis + ['method' => $methode, 'ad' => shopKleinanzeigenAdRow($db, $partsId, $kanal)]);
}

/**
 * Gleicht die Kanalzeilen jetzt mit „Meine Anzeigen“ bei Kleinanzeigen ab
 *
 * @param int $data['channel_id'] Kanal
 * @return void fetched, ended, reserved, active
 * @testdata {"channel_id": 3}
 */
function syncKleinanzeigenAds($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    set_time_limit(300);
    $db = DbhCompany::begin();
    $kanal = shopKleinanzeigenChannel($db, $data);
    try {
        resultInfo(true, 'KLEINANZEIGEN_SYNCED', shopKleinanzeigenSyncViaWorker($db, $kanal));
    } catch (ApiError $e) {
        shopKleinanzeigenWorkerFail($e);
    }
}

/**
 * Antwortet auf eine Anfrage direkt bei Kleinanzeigen
 *
 * Nur für Anfragen, die der Dienst aus den Unterhaltungen geholt hat
 * (mail_uid conv:<id>). Danach gilt die Anfrage als beantwortet.
 *
 * @param int $data['lead_id'] Anfrage
 * @param string $data['text'] Antworttext
 * @return void
 * @testdata {"lead_id": 1, "text": "Hallo, ja das Teil ist noch da."}
 */
function replyKleinanzeigenLead($data) {
    permit(['shop_order', 'shop_part_edit', 'edit_shop_config'], false);
    set_time_limit(300);
    $db = DbhCompany::begin();
    $text = trim((string)($data['text'] ?? ''));
    if ('' === $text) {
        resultInfo(false, 'VALIDATION_ERROR', null, 'Antworttext fehlt');
        return;
    }
    $lead = $db->getOne(
        "SELECT id, channel_id, mail_uid FROM kleinanzeigen_lead_shop WHERE id = CAST(:id AS integer)",
        [':id' => (int)($data['lead_id'] ?? 0)]
    );
    if (!$lead) {
        resultInfo(false, 'KLEINANZEIGEN_LEAD_NOT_FOUND', null, 'Diese Anfrage gibt es nicht');
        return;
    }
    if (0 !== strpos((string)$lead['mail_uid'], 'conv:')) {
        resultInfo(false, 'KLEINANZEIGEN_LEAD_NOT_CONVERSATION', null, 'Diese Anfrage kam per E-Mail — bitte bei Kleinanzeigen antworten');
        return;
    }
    try {
        shopKleinanzeigenWorkerCall($db, (int)$lead['channel_id'], '/messages/reply',
            ['id' => substr((string)$lead['mail_uid'], 5), 'text' => $text], 120);
    } catch (ApiError $e) {
        shopKleinanzeigenWorkerFail($e);
        return;
    }
    $db->execute(
        "UPDATE kleinanzeigen_lead_shop
            SET status = CASE WHEN status = 'open' THEN 'answered' ELSE status END,
                note = concat_ws(E'\n', NULLIF(note, ''), to_char(now(), 'DD.MM.YYYY HH24:MI') || ' geantwortet: ' || CAST(:text AS text)),
                mtime = now()
          WHERE id = CAST(:id AS integer)",
        [':text' => mb_substr($text, 0, 500), ':id' => (int)$lead['id']]
    );
    resultInfo(true, 'KLEINANZEIGEN_REPLIED');
}
