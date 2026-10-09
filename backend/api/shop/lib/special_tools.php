<?php
// backend/api/shop/lib/special_tools.php
//
// Werkzeugsuche im Shop: Welche Spezialwerkzeuge der Werkstatt passen zum
// Fahrzeug des Besuchers? Gesucht wird mit HSN/TSN (Fahrzeugschein, Felder
// 2.1/2.2) oder mit der Fahrgestellnummer. Die Zuordnungsregeln und ihre
// Prüfung gehören der LxCars-Erweiterung (backend/upstall/lxcars/
// company_schema.sql); hier wird nur gefragt, was zum Angebot im HugoShop
// (Verleih, Verkauf) gehört.
//
// Fahrgestellnummer: Ohne externen Decoder lässt sich aus der FIN kein Motor
// ableiten. Deshalb wird sie gegen die Fahrzeuge der Werkstatt geprüft —
// Kunden, deren Fahrzeug schon in der Werkstatt war, bekommen damit die
// exakt zugeordneten Werkzeuge. Fremde FIN: Hinweis, HSN/TSN einzugeben
// (dev/spezialwerkzeug-shop-todo.md).

/**
 * Spezialwerkzeuge zum Fahrzeug des Besuchers
 *
 * @param object $db     Company-Datenbankverbindung
 * @param int    $kanal  HugoShop der Anfrage
 * @param array  $daten  hsn, tsn, fin, engine_code, year
 * @return array supported, vehicle, source (fin|kba|null), fin_unknown, tools[]
 */
function shopFindSpecialTools($db, int $kanal, array $daten): array {
    $leer = ['supported' => true, 'vehicle' => null, 'source' => null, 'fin_unknown' => false, 'tools' => []];

    if (count(existingTables($db, ['special_tools_lxcars', 'kba_lxcars', 'cars_lxcars'])) < 3) {
        return ['supported' => false] + $leer;
    }

    $fin    = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($daten['fin'] ?? '')));
    $hsn    = preg_replace('/\D/', '', (string)($daten['hsn'] ?? ''));
    $tsn    = strtoupper(preg_replace('/\s/', '', (string)($daten['tsn'] ?? '')));
    $engine = trim((string)($daten['engine_code'] ?? ''));
    $jahr   = (int)($daten['year'] ?? 0);
    if ($jahr < 1950 || $jahr > (int)date('Y') + 1) $jahr = 0;

    if ($fin === '' && ($hsn === '' || $tsn === '')) {
        return $leer;
    }

    $zone        = shopConfigValue($db, 'shop_standard_taxzone', 'Inland');
    $brutto      = shopConfigBool($db, 'shop_tax_included', false) ? 1 : 0;

    // Eine Abfrage: Fahrzeug über FIN (eigener Bestand, Treffer-Cache) oder
    // über HSN/TSN (KBA-Profil, Regeln live), dann die angebotenen Werkzeuge
    // mit Preisen des Kanals.
    $zeile = $db->getOne(<<<SQL
        WITH own AS (
            SELECT p.c_id, p.make, p.model, p.fuel_name, p.ccm, p.kw, p.year, p.engine_code,
                   concat_ws(' ', p.make, p.model) AS label
            FROM cars_lxcars c
            JOIN special_tool_vehicle_profiles_lxcars p ON p.c_id = c.c_id
            WHERE :fin <> '' AND upper(regexp_replace(COALESCE(c.c_fin, ''), '[^A-Za-z0-9]', '', 'g')) = :fin
            ORDER BY c.c_id DESC
            LIMIT 1
        ),
        kba AS (
            SELECT special_tool_profile_from_kba_lxcars(:hsn, :tsn, NULLIF(:engine, ''), NULLIF(:jahr, 0)) AS profile
            WHERE :hsn_set <> '' AND :tsn_set <> '' AND NOT EXISTS (SELECT 1 FROM own)
        ),
        hits AS (
            SELECT m.tool_id, m.note FROM own, special_tool_vehicle_matches_lxcars m WHERE m.c_id = own.c_id
            UNION ALL
            SELECT m.tool_id, m.note
            FROM kba, special_tool_compute_matches_lxcars(NULL, NULL, NULL, kba.profile) m
            WHERE kba.profile IS NOT NULL
        ),
        tools AS (
            SELECT t.id, t.name, t.category, t.description, t.ai_summary, t.status, t.rental_days,
                   t.shop_sell, t.shop_rent, t.parts_id, t.rental_parts_id,
                   COALESCE(json_agg(DISTINCT h.note) FILTER (WHERE h.note IS NOT NULL), '[]'::json) AS reasons
            FROM hits h
            JOIN special_tools_lxcars t ON t.id = h.tool_id
            WHERE t.shop_sell OR t.shop_rent
            GROUP BY t.id
        ),
        offers AS (
            SELECT t.id, t.name, t.category, t.description, t.ai_summary, t.status, t.rental_days, t.reasons,
                   CASE WHEN t.shop_sell AND pcs.active THEN json_build_object(
                        'parts_id', ps.id, 'partnumber', ps.partnumber,
                        'price', shop_channel_price(ps.id, CAST(:kanal_s AS integer)),
                        'taxrate', shop_tax_rate(ps.buchungsgruppen_id, :zone_s),
                        'available', shop_part_available(ps.id, CAST(:kanal_sa AS integer)),
                        'hyperlink', pes.hugoshop_hyperlink, 'image', pes.hugoshop_images ->> 0)
                   END AS sale,
                   CASE WHEN t.shop_rent AND pcr.active THEN json_build_object(
                        'parts_id', pr.id, 'partnumber', pr.partnumber,
                        'price', shop_channel_price(pr.id, CAST(:kanal_r AS integer)),
                        'taxrate', shop_tax_rate(pr.buchungsgruppen_id, :zone_r),
                        'available', shop_part_available(pr.id, CAST(:kanal_ra AS integer)) AND t.status = 'available',
                        'hyperlink', per.hugoshop_hyperlink, 'image', per.hugoshop_images ->> 0)
                   END AS rent
            FROM tools t
            LEFT JOIN parts ps              ON ps.id = t.parts_id
            LEFT JOIN parts_channel_shop pcs ON pcs.parts_id = t.parts_id AND pcs.channel_id = CAST(:kanal_cs AS integer)
            LEFT JOIN parts_ext pes         ON pes.parts_id = t.parts_id
            LEFT JOIN parts pr              ON pr.id = t.rental_parts_id
            LEFT JOIN parts_channel_shop pcr ON pcr.parts_id = t.rental_parts_id AND pcr.channel_id = CAST(:kanal_cr AS integer)
            LEFT JOIN parts_ext per         ON per.parts_id = t.rental_parts_id
        )
        SELECT json_build_object(
            'vehicle', COALESCE((SELECT to_jsonb(o) FROM own o), (SELECT k.profile FROM kba k)),
            'source',  CASE WHEN EXISTS (SELECT 1 FROM own) THEN 'fin'
                            WHEN EXISTS (SELECT 1 FROM kba WHERE profile IS NOT NULL) THEN 'kba' END,
            'tools',   COALESCE((SELECT json_agg(o ORDER BY o.name) FROM offers o WHERE o.sale IS NOT NULL OR o.rent IS NOT NULL), '[]'::json)
        ) AS result
    SQL, [
        ':fin' => $fin, ':hsn' => $hsn, ':tsn' => $tsn, ':engine' => $engine, ':jahr' => $jahr,
        ':hsn_set' => $hsn, ':tsn_set' => $tsn,
        ':kanal_s' => $kanal, ':kanal_sa' => $kanal, ':kanal_cs' => $kanal,
        ':kanal_r' => $kanal, ':kanal_ra' => $kanal, ':kanal_cr' => $kanal,
        ':zone_s' => $zone, ':zone_r' => $zone,
    ]);

    $ergebnis = json_decode((string)($zeile['result'] ?? ''), true) ?: [];
    $preis = function (?array $angebot) use ($db, $kanal, $brutto) {
        if (!$angebot) return null;
        $netto = (float)$angebot['price'];
        $satz  = (float)($angebot['taxrate'] ?? 0);
        return [
            'parts_id'    => (int)$angebot['parts_id'],
            'partnumber'  => (string)$angebot['partnumber'],
            'price_gross' => round($brutto ? $netto : $netto * (1 + $satz), 2),
            'price_net'   => round($brutto ? $netto / (1 + $satz) : $netto, 2),
            'available'   => filter_var($angebot['available'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'hyperlink'   => shopChannelLink($db, $kanal, 'products_link',
                                             shopPageSlug((string)($angebot['hyperlink'] ?? ''), (string)$angebot['partnumber'])),
            'image'       => shopChannelLink($db, $kanal, 'thumbnails_link', (string)($angebot['image'] ?? '')),
        ];
    };

    $werkzeuge = array_map(fn($t) => [
        'id'          => (int)$t['id'],
        'name'        => (string)$t['name'],
        'category'    => (string)($t['category'] ?? ''),
        'description' => (string)($t['description'] ?? ''),
        'summary'     => (string)($t['ai_summary'] ?? ''),
        'status'      => (string)$t['status'],
        'rental_days' => (int)$t['rental_days'],
        'reasons'     => $t['reasons'] ?? [],
        'sale'        => $preis($t['sale'] ?? null),
        'rent'        => $preis($t['rent'] ?? null),
    ], $ergebnis['tools'] ?? []);

    return [
        'supported'   => true,
        'vehicle'     => $ergebnis['vehicle'] ?? null,
        'source'      => $ergebnis['source'] ?? null,
        // FIN angegeben, aber nicht im Bestand und kein HSN/TSN: Hinweis an den Besucher
        'fin_unknown' => $fin !== '' && ($ergebnis['source'] ?? null) !== 'fin',
        'tools'       => $werkzeuge,
    ];
}
