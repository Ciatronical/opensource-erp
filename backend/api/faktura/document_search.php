<?php
// backend/api/faktura/document_search.php
// Listenansicht fuer Belege (Rechnungen, Gutschriften, Auftraege, Angebote,
// Lieferscheine) und Artikel.
//
// Die Pfade /rechnung, /angebot, /lieferschein und /artikel standen zwar in der
// Routen-Tabelle, hatten aber keine Ansicht — der Aufruf landete auf "Seite
// nicht gefunden". Diese Funktion liefert die Daten dafuer.
//
// Ein Ajax-Call = eine DB-Abfrage: der Belegtyp waehlt eine feste Konfiguration
// (Tabelle, Nummernspalte, Abgrenzung). Die Konfiguration ist eine Whitelist im
// Code — es fliesst nie Benutzereingabe in den SQL-Text, Filterwerte gehen
// ausschliesslich als Prepared-Statement-Parameter hinein.

/**
 * Konfiguration je Belegtyp — Tabelle, Spalten und Abgrenzung untereinander.
 *
 * Besonderheiten des kivitendo-Schemas:
 * - Gutschriften stehen in `ar` und tragen type = 'credit_note'
 * - Angebote und Auftraege teilen sich `oe`; das Angebot hat eine quonumber,
 *   der Auftrag eine ordnumber
 */
function documentListConfig($documentType) {
    $configs = [
        'invoice' => [
            'table'      => 'ar',
            'number'     => 'invnumber',
            'filter'     => "COALESCE(d.type, '') <> 'credit_note'",
            'permission' => 'invoice_edit',
        ],
        'credit_note' => [
            'table'      => 'ar',
            'number'     => 'invnumber',
            'filter'     => "d.type = 'credit_note'",
            'permission' => 'invoice_edit',
        ],
        'order' => [
            'table'      => 'oe',
            'number'     => 'ordnumber',
            'filter'     => "COALESCE(d.ordnumber, '') <> ''",
            'permission' => 'sales_order_edit',
        ],
        'quotation' => [
            'table'      => 'oe',
            'number'     => 'quonumber',
            'filter'     => "COALESCE(d.quonumber, '') <> ''",
            'permission' => 'sales_quotation_edit',
        ],
        'delivery_order' => [
            'table'      => 'delivery_orders',
            'number'     => 'donumber',
            'filter'     => '1=1',
            'permission' => 'sales_delivery_order_edit',
        ],
    ];

    return $configs[$documentType] ?? null;
}

/**
 * Belegliste laden (neueste zuerst), optional gefiltert.
 *
 * @param string $data['documentType'] invoice | credit_note | order | quotation | delivery_order
 * @param string $data['q']            Volltext ueber Belegnummer, Kunde und Beschreibung
 * @param string $data['from']         Belegdatum ab (YYYY-MM-DD)
 * @param string $data['to']           Belegdatum bis (YYYY-MM-DD)
 * @param int    $data['limit']        Maximale Trefferzahl (Standard 200, max 1000)
 * @testdata {"action": "searchDocuments", "documentType": "invoice", "limit": 50}
 * @testdata {"action": "searchDocuments", "documentType": "quotation", "q": "Müller"}
 */
function searchDocuments($data) {
    $documentType = (string)($data['documentType'] ?? '');
    $config = documentListConfig($documentType);
    if ($config === null) {
        resultInfo(false, 'INVALID_INPUT', 'Unbekannter Belegtyp');
        return;
    }

    permit($config['permission']);

    $db    = DbhCompany::begin();
    $limit = (int)($data['limit'] ?? 200);
    if ($limit < 1)    $limit = 1;
    if ($limit > 1000) $limit = 1000;

    $q    = trim((string)($data['q'] ?? ''));
    $from = trim((string)($data['from'] ?? ''));
    $to   = trim((string)($data['to'] ?? ''));

    $table   = $config['table'];
    $number  = $config['number'];
    $filter  = $config['filter'];

    // Lieferscheine fuehren keine Betraege — dann bleibt die Spalte leer.
    $amount  = $table === 'delivery_orders' ? 'NULL::numeric' : 'd.amount';
    // "Erledigt": Rechnung vollstaendig bezahlt bzw. Beleg geschlossen
    $closed  = $table === 'ar'
        ? 'ABS(d.amount) > 0 AND ABS(d.paid) >= ABS(d.amount)'
        : 'COALESCE(d.closed, FALSE)';

    $params = [':limit' => $limit];
    $where  = [$filter];

    if ($q !== '') {
        $params[':q'] = '%' . $q . '%';
        $where[] = "(d.$number ILIKE :q
                     OR c.name ILIKE :q
                     OR COALESCE(d.transaction_description, '') ILIKE :q)";
    }
    if ($from !== '') {
        $params[':from'] = $from;
        $where[] = 'd.transdate >= :from::date';
    }
    if ($to !== '') {
        $params[':to'] = $to;
        $where[] = 'd.transdate <= :to::date';
    }

    $whereSql = implode(' AND ', $where);

    $documents = $db->getAll(
        "SELECT d.id,
                d.$number                        AS number,
                d.transdate,
                c.name                           AS customer_name,
                c.customernumber,
                $amount                          AS amount,
                ($closed)                        AS closed,
                COALESCE(d.transaction_description, '') AS description,
                (SELECT string_agg(DISTINCT rl.to_table, ',')
                   FROM record_links rl
                  WHERE rl.from_table = '$table' AND rl.from_id = d.id
                    AND rl.to_table IN ('email_journal', 'whatsapp_messages')) AS sent_channels
           FROM $table d
           LEFT JOIN customer c ON c.id = d.customer_id
          WHERE $whereSql
          ORDER BY d.transdate DESC NULLS LAST, d.id DESC
          LIMIT :limit",
        $params
    );

    resultInfo(true, '', ['documents' => $documents]);
}

/**
 * Artikelliste fuer /artikel (Klick fuehrt in die Artikelbearbeitung).
 *
 * Welche Artikel, bestimmt scope: nur aktive (Vorgabe), nur ausgemusterte
 * (parts.obsolete) oder alle. Die Volltextsuche laeuft ueber genau diese.
 *
 * shop schraenkt zusaetzlich nach den Verkaufskanaelen ein (Shop-Erweiterung):
 * offered = in einem eingeschalteten Kanal aktiv angeboten, not_offered = in
 * keinem. Mit scope all zusammen nicht moeglich — die Oberflaeche schaltet das
 * eine beim anderen ab. channel_id verfeinert offered auf einen Kanal.
 *
 * @param string $data['q']          Volltext ueber Artikelnummer und Bezeichnung
 * @param string $data['scope']      active (Vorgabe), obsolete oder all
 * @param string $data['shop']       leer, offered oder not_offered
 * @param int    $data['channel_id'] mit shop offered: nur in diesem Kanal (0 = alle Kanaele)
 * @param bool   $data['weight_missing'] true = nur Waren ohne Gewicht (zum Nachpflegen fuer den Versand)
 * @param bool   $data['shipping_unfit'] true = nur Artikel, die in einem HugoShop angeboten werden und
 *                                   fuer die dort keine Versandart passt (shop_part_shipping_check) —
 *                                   sie werden nicht veroeffentlicht; mit channel_id nur in diesem Kanal
 * @param int    $data['limit']      Maximale Trefferzahl (Standard 200, max 1000)
 * @testdata {"action": "searchParts", "q": "Bremse", "scope": "active", "shop": "", "channel_id": 0, "weight_missing": false, "shipping_unfit": false, "limit": 50}
 */
function searchParts($data) {
    $db    = DbhCompany::begin();
    $limit = (int)($data['limit'] ?? 200);
    if ($limit < 1)    $limit = 1;
    if ($limit > 1000) $limit = 1000;

    $q   = trim((string)($data['q'] ?? ''));
    $scope = in_array($data['scope'] ?? '', ['obsolete', 'all'], true) ? $data['scope'] : 'active';

    $params = [':limit' => $limit, ':scope_all' => $scope, ':scope_obsolete' => $scope];
    $where  = ["(:scope_all = 'all' OR COALESCE(p.obsolete, FALSE) = (:scope_obsolete = 'obsolete'))"];

    // Die Kanaltabellen gibt es nur mit der Shop-Erweiterung — ohne sie waere
    // die Abfrage ungueltig, nicht nur leer
    $shop = in_array($data['shop'] ?? '', ['offered', 'not_offered'], true) ? $data['shop'] : '';
    if ('' !== $shop) {
        if ('all' === $scope || !isExtensionActive($db, 'shop')) {
            resultInfo(false, 'INVALID_FILTER', null, 'Der Filter nach Verkaufskanal ist hier nicht moeglich');
            return;
        }
        // Angeboten: in einem eingeschalteten Kanal aktiv; channel_id 0 = in
        // irgendeinem. Nicht angeboten: in keinem — ein Kanal gilt dann nicht.
        $params[':channel_id'] = 'offered' === $shop ? (int)($data['channel_id'] ?? 0) : 0;
        $where[] = ('offered' === $shop ? 'EXISTS' : 'NOT EXISTS').' (SELECT 1
                              FROM parts_channel_shop pc
                              JOIN sales_channel_shop c ON c.id = pc.channel_id AND c.active
                             WHERE pc.parts_id = p.id AND pc.active
                               AND (CAST(:channel_id AS integer) = 0 OR pc.channel_id = CAST(:channel_id AS integer)))';
    }

    // Waren ohne Gewicht (dev/shop-versand.md, W8): Dienstleistungen werden
    // nicht verschickt und zaehlen nicht
    if (!empty($data['weight_missing'])) {
        $where[] = "p.part_type <> 'service' AND COALESCE(p.weight, 0) <= 0";
    }

    // Ohne passende Versandart (dev/shop-versand.md, Nachtrag 2026-10-02):
    // angeboten in einem eingeschalteten HugoShop, dort passt keine
    // Versandart — die Seite wird nicht veroeffentlicht
    if (!empty($data['shipping_unfit'])) {
        if (!isExtensionActive($db, 'shop')) {
            resultInfo(false, 'INVALID_FILTER', null, 'Der Filter nach Versandart ist hier nicht moeglich');
            return;
        }
        $params[':versand_kanal'] = (int)($data['channel_id'] ?? 0);
        // shop_part_shipping_check liefert eine Tabelle — sie gehört in FROM,
        // in WHERE lehnt PostgreSQL sie ab
        $where[] = "EXISTS (SELECT 1
                              FROM parts_channel_shop vc
                              JOIN sales_channel_shop vk ON vk.id = vc.channel_id
                                                        AND vk.active AND vk.type = 'hugoshop'
                             CROSS JOIN LATERAL shop_part_shipping_check(p.id, vk.id) vp
                             WHERE vc.parts_id = p.id AND vc.active
                               AND (CAST(:versand_kanal AS integer) = 0 OR vc.channel_id = CAST(:versand_kanal AS integer))
                               AND vp.status <> 'ok')";
    }

    // Treffergüte: genaue Artikelnummer zuerst, dann Nummern, die mit dem
    // Suchtext beginnen, dann solche, die ihn enthalten, zuletzt Treffer nur
    // in der Bezeichnung. Sortiert wird vor dem LIMIT — „8" steht so oben,
    // auch wenn hunderte Nummern und Bezeichnungen eine 8 enthalten.
    $rang = '';
    if ($q !== '') {
        $params[':q'] = '%' . $q . '%';
        $where[] = '(p.partnumber ILIKE :q OR p.description ILIKE :q)';

        $params[':q_genau']  = $q;
        $params[':q_anfang'] = $q . '%';
        $params[':q_teil']   = '%' . $q . '%';
        $rang = "CASE WHEN lower(p.partnumber) = lower(:q_genau) THEN 0
                      WHEN p.partnumber ILIKE :q_anfang THEN 1
                      WHEN p.partnumber ILIKE :q_teil THEN 2
                      ELSE 3 END, ";
    }

    $whereSql = implode(' AND ', $where);

    $parts = $db->getAll(
        "SELECT p.id,
                p.partnumber,
                p.description,
                p.part_type,
                p.unit,
                p.sellprice,
                p.onhand,
                COALESCE(p.obsolete, FALSE) AS obsolete
           FROM parts p
          WHERE $whereSql
          ORDER BY {$rang}p.partnumber
          LIMIT :limit",
        $params
    );

    resultInfo(true, '', ['parts' => $parts]);
}

/**
 * Eingeschaltete Verkaufskanaele fuer den Kanalfilter der Artikelliste
 *
 * Nur mit Shop-Erweiterung; ohne sie eine leere Liste (die Kanaltabellen
 * fehlen dann). Die Oberflaeche uebersetzt den Namen aus type.
 *
 * @return void
 * @testdata {"action": "getPartsSalesChannels"}
 */
function getPartsSalesChannels($data) {
    $db = DbhCompany::begin();

    if (!isExtensionActive($db, 'shop')) {
        resultInfo(true, '', ['channels' => []]);
        return;
    }

    resultInfo(true, '', ['channels' => $db->getAll(
        "SELECT c.id AS channel_id, c.type, c.name
           FROM sales_channel_shop c
          WHERE c.active
          -- Reihenfolge wie in der Ansicht „Verkaufskanäle“: umgekehrt
          ORDER BY c.sortkey DESC NULLS FIRST, c.id DESC"
    )]);
}
