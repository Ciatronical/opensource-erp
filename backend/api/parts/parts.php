<?php
// backend/api/parts/parts.php

/**
 * Artikel suchen
 *
 * @param string $data['term'] Suchbegriff für Artikelsuche
 * @testdata {"term": "a", "taxzone": 4}
 */
function findParts($data) {
    include_once __DIR__ . '/../features.php';

    $mandant = DbhCompany::begin();
    $term = $data['term'];
    $termLike   = "%{$term}%";
    $termPrefix = "{$term}%";
    $taxzoneId  = $data['taxzone'];

    $query = <<<SQL
        (
            SELECT
                'W' AS part_type,
                'Waren' AS category,
                description,
                partnumber,
                id,
                description AS value,
                part_type,
                unit,
                (
                    SELECT row_to_json(unit_type)
                    FROM (
                        SELECT base_unit, factor
                        FROM units
                        WHERE units.name = parts.unit
                    ) AS unit_type
                ) AS unit_type,
                partnumber || ' ' || description AS label,
                sellprice,
                (
                    SELECT qty
                    FROM (
                        SELECT qty FROM orderitems WHERE parts_id = parts.id AND qty IS NOT NULL
                        UNION ALL
                        SELECT qty FROM invoice WHERE parts_id = parts.id AND qty IS NOT NULL
                    ) AS all_qty
                    GROUP BY qty
                    ORDER BY count(qty) DESC, qty DESC
                    LIMIT 1
                ) AS qty,
                (
                    SELECT row_to_json(buchungsziel)
                    FROM (
                        SELECT
                            c2.id AS income_chart_id,
                            tk.tax_id,
                            tx.chart_id AS tax_chart_id,
                            tx.rate
                        FROM parts p
                        LEFT JOIN buchungsgruppen bg ON p.buchungsgruppen_id = bg.id
                        LEFT JOIN taxzone_charts tc ON bg.id = tc.buchungsgruppen_id
                        LEFT JOIN chart c1 ON bg.inventory_accno_id = c1.id
                        LEFT JOIN chart c2 ON tc.income_accno_id = c2.id
                        LEFT JOIN chart c3 ON tc.expense_accno_id = c3.id
                        LEFT JOIN taxkeys tk ON tk.chart_id = c2.id
                        LEFT JOIN tax tx ON tx.id = tk.tax_id
                        WHERE tc.taxzone_id = :taxzone_id
                        AND p.id IN (parts.id)
                        ORDER BY tk.startdate DESC
                        LIMIT 1
                    ) AS buchungsziel
                ) AS buchungsziel
            FROM parts
            WHERE (
                    description ILIKE :term_like
                OR partnumber ILIKE :term_prefix
            )
            AND obsolete = FALSE
            AND part_type = 'part'
            ORDER BY (
                SELECT (
                    SELECT count(qty)
                    FROM orderitems
                    WHERE parts_id = parts.id
                )
            ) DESC NULLS LAST
            LIMIT 5
        )
        UNION ALL
        (
            SELECT
                'D' AS part_type,
                'Dienstleistung' AS category,
                description,
                partnumber,
                id,
                description AS value,
                part_type,
                unit,
                (
                    SELECT row_to_json(unit_type)
                    FROM (
                        SELECT base_unit, factor
                        FROM units
                        WHERE units.name = parts.unit
                    ) AS unit_type
                ) AS unit_type,
                partnumber || ' ' || description AS label,
                sellprice,
                (
                    SELECT qty
                    FROM (
                        SELECT qty FROM orderitems WHERE parts_id = parts.id AND qty IS NOT NULL
                        UNION ALL
                        SELECT qty FROM invoice WHERE parts_id = parts.id AND qty IS NOT NULL
                    ) AS all_qty
                    GROUP BY qty
                    ORDER BY count(qty) DESC, qty DESC
                    LIMIT 1
                ) AS qty,
                (
                    SELECT row_to_json(buchungsziel)
                    FROM (
                        SELECT
                            c2.id AS income_chart_id,
                            tk.tax_id,
                            tx.chart_id AS tax_chart_id,
                            tx.rate
                        FROM parts p
                        LEFT JOIN buchungsgruppen bg ON p.buchungsgruppen_id = bg.id
                        LEFT JOIN taxzone_charts tc ON bg.id = tc.buchungsgruppen_id
                        LEFT JOIN chart c1 ON bg.inventory_accno_id = c1.id
                        LEFT JOIN chart c2 ON tc.income_accno_id = c2.id
                        LEFT JOIN chart c3 ON tc.expense_accno_id = c3.id
                        LEFT JOIN taxkeys tk ON tk.chart_id = c2.id
                        LEFT JOIN tax tx ON tx.id = tk.tax_id
                        WHERE tc.taxzone_id = :taxzone_id
                        AND p.id IN (parts.id)
                        ORDER BY tk.startdate DESC
                        LIMIT 1
                    ) AS buchungsziel
                ) AS buchungsziel
            FROM parts
            WHERE (
                    description ILIKE :term_like
                OR partnumber ILIKE :term_prefix
            )
            AND obsolete = FALSE
            AND part_type = 'service'
            ORDER BY (
                SELECT (
                    SELECT count(qty)
                    FROM orderitems
                    WHERE parts_id = parts.id
                )
            ) DESC NULLS LAST
            LIMIT 5
        )
    SQL;

    $params = [
        ':taxzone_id' => $taxzoneId,
        ':term_like' => $termLike,
        ':term_prefix' => $termPrefix
    ];

    $result = $mandant->getAll($query, $params);

    resultInfo(true, '', ['parts' => $result]);
}

/**
 * Einzelnen Artikel laden
 *
 * @param int $data['parts_id'] ID des Artikels
 * @return void
 * @testdata {"parts_id": 1}
 */
function getPart($data) {
    $company = DbhCompany::begin();

    $partsId = intval($data['parts_id'] ?? 0);

    if ($partsId <= 0) {
        resultInfo(false, 'INVALID_PARTS_ID', ['message' => 'Ungültige Artikel-ID']);
        return;
    }

    $row = $company->getOne(
        "SELECT p.id, p.partnumber, p.description, p.part_type, p.unit,
                p.sellprice, p.notes, p.buchungsgruppen_id, p.obsolete,
                bg.description AS buchungsgruppen_name
         FROM parts p
         LEFT JOIN buchungsgruppen bg ON bg.id = p.buchungsgruppen_id
         WHERE p.id = :id",
        [':id' => $partsId]
    );

    if (!$row) {
        resultInfo(false, 'NOT_FOUND', ['message' => 'Artikel nicht gefunden']);
        return;
    }

    resultInfo(true, 'OK', $row);
}

/**
 * Artikel (Stammdaten) aktualisieren
 *
 * Aktualisiert die Stammdaten eines Artikels in der parts Tabelle.
 *
 * @param array $data Artikel-Daten
 * @param int $data['parts_id'] ID des Artikels
 * @param string $data['description'] Beschreibung
 * @param string $data['notes'] Langbeschreibung
 * @param float $data['sellprice'] Verkaufspreis
 * @param string $data['unit'] Einheit
 * @param string $data['part_type'] Artikeltyp (part/service)
 * @param int $data['buchungsgruppen_id'] Buchungsgruppen-ID
 * @param bool $data['obsolete'] Veraltet-Flag
 * @return void
 * @testdata {"parts_id": 1, "description": "Test", "notes": "Langtext", "sellprice": 100.00, "unit": "Stk"}
 */
function updatePart($data) {
    permit(['invoice_edit', 'sales_order_edit', 'sales_quotation_edit'], false);

    $company = DbhCompany::begin();

    $partsId = intval($data['parts_id'] ?? 0);

    if ($partsId <= 0) {
        resultInfo(false, 'INVALID_PARTS_ID', ['message' => 'Ungültige Artikel-ID']);
        return;
    }

    // Nur erlaubte Felder aktualisieren
    $updateData = [];

    if (isset($data['description'])) {
        $updateData['description'] = trim($data['description']);
    }

    if (isset($data['notes'])) {
        $updateData['notes'] = trim($data['notes']);
    }

    if (isset($data['sellprice'])) {
        $updateData['sellprice'] = floatval($data['sellprice']);
    }

    if (isset($data['unit'])) {
        $updateData['unit'] = trim($data['unit']);
    }

    if (isset($data['part_type']) && in_array($data['part_type'], ['part', 'service'])) {
        $updateData['part_type'] = $data['part_type'];
    }

    if (isset($data['buchungsgruppen_id'])) {
        $updateData['buchungsgruppen_id'] = intval($data['buchungsgruppen_id']);
    }

    if (isset($data['obsolete'])) {
        $updateData['obsolete'] = (bool) $data['obsolete'];
    }

    if (empty($updateData)) {
        resultInfo(true, 'NO_CHANGES', ['message' => 'Keine Änderungen']);
        return;
    }

    // Update mit mtime
    $company->updateRow(
        'parts',
        $updateData,
        [],
        'id = :id',
        [':id' => $partsId],
        'mtime = NOW()'
    );

    resultInfo(true, 'UPDATED', ['message' => 'Artikel aktualisiert', 'parts_id' => $partsId]);
}

/**
 * Intelligente Artikelvorschläge - lernt dynamisch aus der Datenbank
 *
 * Analysiert vorhandene Artikel und ermittelt:
 * 1. Artikeltyp (part/service) basierend auf ähnlichen Artikeln
 * 2. Einheit basierend auf ähnlichen Artikeln
 * 3. Preis (Median) ähnlicher Artikel
 *
 * KEINE hardgecodeten Keywords - alles wird aus den Daten gelernt!
 *
 * @param array $data
 * @param string $data['description'] Beschreibung des neuen Artikels
 * @param int $data['buchungsgruppen_id'] Optional: Buchungsgruppen-ID
 * @return void
 * @testdata {"description": "Montage Klimaanlage"}
 */
function suggestPartType($data) {
    $company = DbhCompany::begin();

    $description = trim($data['description'] ?? '');

    if (empty($description)) {
        resultInfo(true, '', [
            'suggested_type' => 'service',
            'suggested_unit' => 'Std',
            'suggested_price' => 0,
            'confidence' => 0,
            'reason' => 'empty_description',
            'similar_count' => 0
        ]);
        return;
    }

    // Komplettes JSON aus der Datenbank - Präfix-Suche mit mehreren Längen
    $query = <<<SQL
        WITH
        input_data AS (
            SELECT lower(:search) as search_lower
        ),

        -- Ähnliche Artikel finden: Präfix-Match mit verschiedenen Längen
        similar_articles AS (
            SELECT
                p.id,
                p.description,
                p.part_type,
                p.unit,
                p.sellprice,
                CASE
                    -- Exakter Match am Anfang
                    WHEN lower(p.description) LIKE (SELECT search_lower FROM input_data) || '%' THEN 100
                    -- Erste 6 Zeichen
                    WHEN length((SELECT search_lower FROM input_data)) >= 6
                         AND lower(p.description) LIKE left((SELECT search_lower FROM input_data), 6) || '%' THEN 80
                    -- Erste 5 Zeichen
                    WHEN length((SELECT search_lower FROM input_data)) >= 5
                         AND lower(p.description) LIKE left((SELECT search_lower FROM input_data), 5) || '%' THEN 70
                    -- Erste 4 Zeichen
                    WHEN length((SELECT search_lower FROM input_data)) >= 4
                         AND lower(p.description) LIKE left((SELECT search_lower FROM input_data), 4) || '%' THEN 60
                    -- Erste 3 Zeichen
                    WHEN length((SELECT search_lower FROM input_data)) >= 3
                         AND lower(p.description) LIKE left((SELECT search_lower FROM input_data), 3) || '%' THEN 50
                    -- Erstes Wort enthalten
                    WHEN lower(p.description) LIKE '%' || split_part((SELECT search_lower FROM input_data), ' ', 1) || '%' THEN 40
                    ELSE 0
                END as relevance
            FROM parts p
            WHERE
                p.obsolete = FALSE
                AND (
                    -- Präfix-Match (erste 3+ Zeichen)
                    (length((SELECT search_lower FROM input_data)) >= 3
                     AND lower(p.description) LIKE left((SELECT search_lower FROM input_data), 3) || '%')
                    -- Oder erstes Wort enthalten
                    OR lower(p.description) LIKE '%' || split_part((SELECT search_lower FROM input_data), ' ', 1) || '%'
                )
        ),

        -- Nur relevante Treffer
        relevant_articles AS (
            SELECT * FROM similar_articles
            WHERE relevance > 0
            ORDER BY relevance DESC
            LIMIT 100
        ),

        -- Häufigster Typ (gewichtet)
        type_counts AS (
            SELECT part_type, SUM(relevance) as score
            FROM relevant_articles
            WHERE part_type IS NOT NULL
            GROUP BY part_type
            ORDER BY score DESC
            LIMIT 1
        ),

        -- Häufigste Einheit (gewichtet)
        unit_counts AS (
            SELECT unit, SUM(relevance) as score
            FROM relevant_articles
            WHERE unit IS NOT NULL AND unit != ''
            GROUP BY unit
            ORDER BY score DESC
            LIMIT 1
        ),

        -- Preis-Statistiken
        price_stats AS (
            SELECT
                percentile_cont(0.5) WITHIN GROUP (ORDER BY sellprice) as median_price,
                MIN(sellprice) as min_price,
                MAX(sellprice) as max_price,
                COUNT(*) as cnt
            FROM relevant_articles
            WHERE sellprice > 0
        ),

        -- Gesamtzahlen
        totals AS (
            SELECT
                COUNT(*) as total,
                COUNT(*) FILTER (WHERE part_type = 'service') as services,
                COUNT(*) FILTER (WHERE part_type = 'part') as parts
            FROM relevant_articles
        )

        SELECT row_to_json(result) as suggestion
        FROM (
            SELECT
                COALESCE((SELECT part_type FROM type_counts), 'service') as suggested_type,
                COALESCE((SELECT unit FROM unit_counts), 'Std') as suggested_unit,
                COALESCE((SELECT ROUND(median_price::numeric, 2) FROM price_stats), 0) as suggested_price,
                COALESCE((SELECT total FROM totals), 0) as similar_count,
                CASE
                    WHEN COALESCE((SELECT total FROM totals), 0) = 0 THEN 0
                    WHEN COALESCE((SELECT services FROM totals), 0) = 0
                         OR COALESCE((SELECT parts FROM totals), 0) = 0 THEN 90
                    ELSE LEAST(
                        ROUND(ABS((SELECT services FROM totals) - (SELECT parts FROM totals))::numeric
                              / (SELECT total FROM totals)::numeric * 100),
                        100
                    )::int
                END as confidence,
                CASE
                    WHEN COALESCE((SELECT total FROM totals), 0) > 0 THEN 'similar_articles'
                    ELSE 'default'
                END as reason,
                ARRAY[]::text[] as reason_details,
                json_build_object(
                    'service', COALESCE((SELECT services FROM totals), 0),
                    'part', COALESCE((SELECT parts FROM totals), 0)
                ) as scores,
                CASE
                    WHEN COALESCE((SELECT cnt FROM price_stats), 0) > 0 THEN
                        json_build_object(
                            'min', COALESCE((SELECT min_price FROM price_stats), 0),
                            'max', COALESCE((SELECT max_price FROM price_stats), 0),
                            'median', COALESCE((SELECT ROUND(median_price::numeric, 2) FROM price_stats), 0),
                            'sample_size', COALESCE((SELECT cnt FROM price_stats), 0)
                        )
                    ELSE NULL
                END as price_info
        ) result
    SQL;

    $row = $company->getOne($query, [':search' => $description]);
    $suggestion = json_decode($row['suggestion'], true);

    resultInfo(true, '', $suggestion);
}

/**
 * Neuen Artikel anlegen
 *
 * Legt einen neuen Artikel in der parts Tabelle an.
 * Artikelnummer wird automatisch als nächste freie Nummer generiert.
 *
 * @param array $data Artikel-Daten
 * @param string $data['description'] Beschreibung (Pflicht)
 * @param string $data['partnumber'] Artikelnummer (optional, wird generiert)
 * @param string $data['part_type'] 'part' oder 'service' (Pflicht)
 * @param int $data['buchungsgruppen_id'] Buchungsgruppen-ID (Pflicht)
 * @param float $data['sellprice'] Verkaufspreis
 * @param string $data['unit'] Einheit
 * @param string $data['notes'] Langbeschreibung
 * @return void
 * @testdata {"description": "Test Artikel", "part_type": "service", "buchungsgruppen_id": 1, "sellprice": 100, "unit": "Stck"}
 */
function createPart($data) {
    permit(['invoice_edit', 'sales_order_edit', 'sales_quotation_edit'], false);

    $company = DbhCompany::begin();

    $description = trim($data['description'] ?? '');
    $partType = $data['part_type'] ?? 'service';
    $buchungsgruppenId = intval($data['buchungsgruppen_id'] ?? 0);

    // Validierung
    if (empty($description)) {
        resultInfo(false, 'MISSING_DESCRIPTION', ['message' => 'Beschreibung ist erforderlich']);
        return;
    }

    if (!in_array($partType, ['part', 'service'])) {
        resultInfo(false, 'INVALID_PART_TYPE', ['message' => 'Ungültiger Artikeltyp']);
        return;
    }

    if ($buchungsgruppenId <= 0) {
        resultInfo(false, 'MISSING_BUCHUNGSGRUPPE', ['message' => 'Buchungsgruppe ist erforderlich']);
        return;
    }

    $partnumber = trim($data['partnumber'] ?? '');
    $partParams = [
        ':description'        => $description,
        ':part_type'          => $partType,
        ':buchungsgruppen_id' => $buchungsgruppenId,
        ':sellprice'          => floatval($data['sellprice'] ?? 0),
        ':unit'               => trim($data['unit'] ?? 'Stck'),
        ':notes'              => trim($data['notes'] ?? '')
    ];

    if (empty($partnumber)) {
        // Artikelnummer automatisch vergeben: nextFreeNumber zählt den defaults-Counter
        // hoch und überspringt bereits vergebene Nummern in parts.partnumber.
        $numberField = ($partType === 'service') ? 'servicenumber' : 'articlenumber';
        $nextNumber = nextFreeNumber($company, $numberField, 'parts', 'partnumber');

        $result = $company->getOne(<<<SQL
            INSERT INTO parts (partnumber, description, part_type, buchungsgruppen_id, sellprice, unit, notes, obsolete)
            VALUES (:partnumber, :description, :part_type, :buchungsgruppen_id, :sellprice, :unit, :notes, FALSE)
            RETURNING id, partnumber
        SQL, array_merge($partParams, [':partnumber' => $nextNumber]));

        $partsId    = $result['id'];
        $partnumber = $result['partnumber'];
    } else {
        // Manuelle Artikelnummer
        $query = <<<SQL
            INSERT INTO parts (partnumber, description, part_type, buchungsgruppen_id, sellprice, unit, notes, obsolete)
            VALUES (:partnumber, :description, :part_type, :buchungsgruppen_id, :sellprice, :unit, :notes, FALSE)
            RETURNING id
        SQL;

        $result  = $company->getOne($query, array_merge($partParams, [':partnumber' => $partnumber]));
        $partsId = $result['id'];
    }

    resultInfo(true, 'CREATED', [
        'parts_id' => $partsId,
        'partnumber' => $partnumber,
        'part_type' => $partType
    ]);
}

/**
 * Artikelsuche mit Pagination, Sortierung und Filtern
 *
 * @param string $data['where']['partnumber'] Artikelnummer (ILIKE, optional)
 * @param string $data['where']['description'] Beschreibung (ILIKE, optional)
 * @param string $data['where']['part_type'] Artikeltyp: part oder service (optional)
 * @param string $data['where']['obsolete'] Obsolet-Status (optional)
 * @param int $data['limit'] Einträge pro Seite (optional, default 10, -1 = alle)
 * @param int $data['offset'] Offset (optional, default 0)
 * @param string $data['sortBy'] Sortierspalte (optional, default 'id')
 * @param string $data['sortOrder'] Sortierrichtung (optional, default 'desc')
 * @testdata {"where": {"description": "Test"}}
 */
function searchParts($data) {
    $db = DbhCompany::begin();

    $where = $data['where'] ?? [];
    $conditions = ["1=1"];
    $params = [];
    $i = 0;

    foreach ($where as $key => $value) {
        if ($value === null || $value === '') continue;

        switch ($key) {
            case 'partnumber':
            case 'description':
            case 'notes':
            case 'unit':
                $p = ":p$i";
                $conditions[] = "parts.$key ILIKE $p";
                $params[$p] = "%$value%";
                $i++;
                break;
            case 'part_type':
                if (in_array($value, ['part', 'service'])) {
                    $p = ":p$i";
                    $conditions[] = "parts.part_type = $p";
                    $params[$p] = $value;
                    $i++;
                }
                break;
            case 'obsolete':
                $conditions[] = "COALESCE(parts.obsolete, false) IS " . ($value ? "TRUE" : "FALSE");
                break;
            case 'sellprice_from':
                $p = ":p$i";
                $conditions[] = "parts.sellprice >= $p";
                $params[$p] = floatval($value);
                $i++;
                break;
            case 'sellprice_to':
                $p = ":p$i";
                $conditions[] = "parts.sellprice <= $p";
                $params[$p] = floatval($value);
                $i++;
                break;
        }
    }

    $search = implode(' AND ', $conditions);

    // Pagination
    $rawLimit = isset($data['limit']) ? intval($data['limit']) : 10;
    $limit = ($rawLimit === -1) ? 'ALL' : max(1, $rawLimit);
    $offset = isset($data['offset']) ? max(0, intval($data['offset'])) : 0;

    // Sortierung
    $sortKey = $data['sortBy'] ?? 'id';
    $sortOrder = (isset($data['sortOrder']) && strtolower($data['sortOrder']) === 'asc') ? 'ASC' : 'DESC';
    $allowedSortKeys = ['id', 'partnumber', 'description', 'part_type', 'unit', 'sellprice', 'itime', 'obsolete'];
    if (!in_array($sortKey, $allowedSortKeys)) $sortKey = 'id';
    $sortCol = "parts.$sortKey";

    $countQuery = <<<SQL
        SELECT COUNT(*) AS total
        FROM parts
        WHERE $search
    SQL;

    $query = <<<SQL
        SELECT
            parts.id,
            parts.partnumber,
            parts.description,
            parts.part_type,
            parts.unit,
            parts.sellprice,
            parts.notes,
            COALESCE(parts.obsolete, false) AS obsolete,
            parts.itime
        FROM parts
        WHERE $search
        ORDER BY $sortCol $sortOrder
        LIMIT $limit OFFSET $offset
    SQL;

    try {
        $countResult = $db->getOne($countQuery, $params);
        $total = intval($countResult['total']);
        $results = $db->getAll($query, $params);
        resultInfo(true, '', ['results' => $results, 'total' => $total]);
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        preg_match('/SQLSTATE\[(\w+)\]:\s*(.+)/', $errorMessage, $matches);
        $sqlState = $matches[1] ?? 'UNKNOWN';
        $sqlError = $matches[2] ?? $errorMessage;

        echo json_encode([
            'sql_error' => true,
            'sql_state' => $sqlState,
            'error_message' => $sqlError,
            'full_error' => $errorMessage
        ]);
    }
}