<?php
// backend/api/parts/parts.php

/**
 * Artikel für die Positionssuche in der Faktura suchen — klug sortiert
 *
 * Rangstufen (match_tier): 3000 Beschreibung beginnt mit dem Begriff,
 * 2000 Artikelnummer beginnt mit dem Begriff, 1000 ein Wort der Beschreibung
 * beginnt mit dem Begriff, 0 Begriff irgendwo enthalten. Darauf kommt eine
 * logarithmische Gewichtung der Verwendungshäufigkeit aus parts_usage_oserp
 * (gesamt und in den letzten recent_months Monaten). Bei den Standardgewichten
 * bleibt die Rangstufe maßgeblich; wer die Häufigkeit stärker will, dreht die
 * Gewichte in der Firmenkonfiguration hoch — das Frontend schickt sie mit.
 * Je Artikelart (Ware/Dienstleistung) sind mindestens drei Treffer dabei,
 * damit z. B. zu „Batte" auch „Batterie prüfen" erscheint.
 *
 * @param string $data['term'] Suchbegriff
 * @param int $data['taxzone'] Steuerzone des Belegs (für das Buchungsziel)
 * @param int $data['limit'] Anzahl Vorschläge (Standard 12)
 * @param number $data['usage_weight'] Gewicht Verwendungen gesamt (Standard 40)
 * @param number $data['recent_weight'] Gewicht Verwendungen zuletzt (Standard 60)
 * @param int $data['recent_months'] Zeitraum „zuletzt" in Monaten (Standard 12)
 * @testdata {"term": "batte", "taxzone": 4}
 */
function findParts($data) {
    $mandant = DbhCompany::begin();
    $term = trim((string)($data['term'] ?? ''));
    if ($term === '') {
        resultInfo(true, '', ['parts' => []]);
        return;
    }

    // Wortanfang: Begriff direkt nach Zeichen, das kein Buchstabe/Ziffer ist
    // (Leerzeichen, Komma, Klammer, Bindestrich …). Regex-Sonderzeichen entschärfen.
    $termWordRe = '(^|[^[:alnum:]])' . preg_quote($term);

    $params = [
        ':taxzone_id'    => (int)($data['taxzone'] ?? 0),
        ':term_like'     => "%{$term}%",
        ':term_prefix'   => "{$term}%",
        ':term_word_re'  => $termWordRe,
        ':limit'         => max(1, min(50, (int)($data['limit'] ?? 12))),
        ':usage_weight'  => max(0, (float)($data['usage_weight'] ?? 40)),
        ':recent_weight' => max(0, (float)($data['recent_weight'] ?? 60)),
        ':recent_months' => max(1, min(120, (int)($data['recent_months'] ?? 12)))
    ];

    $query = <<<SQL
        WITH cfg AS (
            SELECT :limit::int AS lim,
                   3 AS min_per_type,
                   :usage_weight::numeric AS w_usage,
                   :recent_weight::numeric AS w_recent,
                   (date_trunc('month', current_date) - make_interval(months => :recent_months::int))::date AS recent_from
        ),
        cand AS (
            SELECT p.id, p.part_type, p.description, p.partnumber, p.unit, p.sellprice, p.buchungsgruppen_id,
                   CASE
                       WHEN p.description ILIKE :term_prefix THEN 3000
                       WHEN p.partnumber  ILIKE :term_prefix THEN 2000
                       WHEN p.description ~* :term_word_re   THEN 1000
                       ELSE 0
                   END AS match_tier
              FROM parts p
             WHERE (p.description ILIKE :term_like OR p.partnumber ILIKE :term_prefix)
               AND p.obsolete = FALSE
               AND p.part_type IN ('part', 'service')
        ),
        scored AS (
            SELECT c.*,
                   COALESCE(u.total, 0)::int  AS total_uses,
                   COALESCE(u.recent, 0)::int AS recent_uses,
                   c.match_tier
                   + cfg.w_usage  * ln(1 + COALESCE(u.total, 0))
                   + cfg.w_recent * ln(1 + COALESCE(u.recent, 0)) AS score
              FROM cand c
              CROSS JOIN cfg
              LEFT JOIN LATERAL (
                  SELECT sum(uses) AS total,
                         sum(uses) FILTER (WHERE month >= cfg.recent_from) AS recent
                    FROM parts_usage_oserp pu
                   WHERE pu.parts_id = c.id
              ) u ON TRUE
        ),
        ranked AS (
            SELECT s.*,
                   row_number() OVER (PARTITION BY part_type ORDER BY score DESC, description) AS rn_type,
                   row_number() OVER (ORDER BY score DESC, description)                         AS rn_all
              FROM scored s
        )
        SELECT r.id,
               r.part_type,
               r.description,
               r.partnumber,
               r.description AS value,
               r.partnumber || ' ' || r.description AS label,
               r.unit,
               r.sellprice,
               r.match_tier,
               r.total_uses,
               r.recent_uses,
               round(r.score) AS score,
               (
                   SELECT row_to_json(unit_type)
                     FROM (SELECT base_unit, factor FROM units WHERE units.name = r.unit) AS unit_type
               ) AS unit_type,
               (
                   -- häufigste Menge dieses Artikels als Vorbelegung
                   SELECT qty
                     FROM (
                         SELECT qty FROM orderitems WHERE parts_id = r.id AND qty IS NOT NULL
                         UNION ALL
                         SELECT qty FROM invoice WHERE parts_id = r.id AND qty IS NOT NULL
                     ) AS all_qty
                    GROUP BY qty
                    ORDER BY count(qty) DESC, qty DESC
                    LIMIT 1
               ) AS qty,
               (
                   SELECT row_to_json(buchungsziel)
                     FROM (
                         SELECT c2.id AS income_chart_id,
                                tk.tax_id,
                                tx.chart_id AS tax_chart_id,
                                tx.rate
                           FROM buchungsgruppen bg
                           JOIN taxzone_charts tc ON bg.id = tc.buchungsgruppen_id
                           LEFT JOIN chart c2 ON tc.income_accno_id = c2.id
                           LEFT JOIN taxkeys tk ON tk.chart_id = c2.id
                           LEFT JOIN tax tx ON tx.id = tk.tax_id
                          WHERE bg.id = r.buchungsgruppen_id
                            AND tc.taxzone_id = :taxzone_id
                          ORDER BY tk.startdate DESC
                          LIMIT 1
                     ) AS buchungsziel
               ) AS buchungsziel
          FROM ranked r, cfg
         WHERE r.rn_all <= cfg.lim OR r.rn_type <= cfg.min_per_type
         ORDER BY r.score DESC, r.description
    SQL;

    $result = $mandant->getAll($query, $params);

    resultInfo(true, '', ['parts' => $result]);
}

/**
 * Verwendungsstatistik der Artikel neu aufbauen (parts_usage_oserp)
 *
 * Zählt alle Positionen aus orderitems und invoice je Artikel und Belegmonat
 * neu. Normalerweise pflegen Trigger die Tabelle; der Neuaufbau ist für
 * Importe oder Zweifel an den Zählern gedacht.
 *
 * @testdata {}
 */
function rebuildPartsUsage($data) {
    $mandant = DbhCompany::begin();
    $row = $mandant->getOne("SELECT rows_built, parts_with_usage, total_uses FROM parts_usage_rebuild()", []);
    resultInfo(true, '', ['results' => $row]);
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
                p.sellprice, p.notes, p.buchungsgruppen_id, p.obsolete, p.weight,
                bg.description AS buchungsgruppen_name,
                (SELECT weightunit FROM defaults LIMIT 1) AS weightunit
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
 * @param string $data['partnumber'] Artikelnummer, eindeutig; Fehler PARTNUMBER_EXISTS, wenn vergeben.
 *                                   Offene Aufträge der Shop-Erweiterung folgen der neuen Nummer
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
    permit(['part_service_assembly_edit', 'invoice_edit', 'sales_order_edit', 'sales_quotation_edit'], false);

    $company = DbhCompany::begin();

    $partsId = intval($data['parts_id'] ?? 0);

    if ($partsId <= 0) {
        resultInfo(false, 'INVALID_PARTS_ID', ['message' => 'Ungültige Artikel-ID']);
        return;
    }

    // Nur erlaubte Felder aktualisieren
    $updateData = [];

    // Artikelnummer: eindeutig (parts_partnumber_key1). Geprüft wird vorab,
    // damit die Oberfläche eine klare Meldung bekommt statt eines
    // Datenbankfehlers; die Eindeutigkeit selbst sichert die Datenbank.
    $alteNummer = null;
    if (isset($data['partnumber'])) {
        $neueNummer = trim((string)$data['partnumber']);
        if ('' === $neueNummer) {
            resultInfo(false, 'VALIDATION_ERROR', ['message' => 'Die Artikelnummer darf nicht leer sein']);
            return;
        }
        $bisher = $company->getOne("SELECT partnumber FROM parts WHERE id = :id", [':id' => $partsId]);
        if (!$bisher) {
            resultInfo(false, 'NOT_FOUND', ['message' => 'Artikel nicht gefunden']);
            return;
        }
        if ($neueNummer !== (string)$bisher['partnumber']) {
            $belegt = $company->getOne(
                "SELECT id FROM parts WHERE partnumber = :partnumber AND id <> :id",
                [':partnumber' => $neueNummer, ':id' => $partsId]
            );
            if ($belegt) {
                resultInfo(false, 'PARTNUMBER_EXISTS', ['message' => 'Artikelnummer ist bereits vergeben']);
                return;
            }
            $alteNummer = (string)$bisher['partnumber'];
            $updateData['partnumber'] = $neueNummer;
        }
    }

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

    // Gewicht in der Einheit von defaults.weightunit; leer = nicht gepflegt.
    // array_key_exists statt isset: null soll das Gewicht leeren können.
    if (array_key_exists('weight', $data)) {
        $gewicht = $data['weight'];
        if (null === $gewicht || '' === trim((string)$gewicht)) {
            $updateData['weight'] = null;
        } elseif (is_numeric($gewicht) && (float)$gewicht >= 0) {
            $updateData['weight'] = (float)$gewicht;
        } else {
            resultInfo(false, 'VALIDATION_ERROR', ['message' => 'Das Gewicht muss leer oder eine Zahl ab 0 sein']);
            return;
        }
    }

    if (empty($updateData)) {
        resultInfo(true, 'NO_CHANGES', ['message' => 'Keine Änderungen']);
        return;
    }

    // Update mit mtime
    try {
        $company->updateRow(
            'parts',
            $updateData,
            [],
            'id = :id',
            [':id' => $partsId],
            'mtime = NOW()'
        );
    } catch (PDOException $e) {
        // Zwei gleichzeitige Umbenennungen auf dieselbe Nummer: die Datenbank entscheidet
        if ('23505' === $e->getCode() && null !== $alteNummer) {
            resultInfo(false, 'PARTNUMBER_EXISTS', ['message' => 'Artikelnummer ist bereits vergeben']);
            return;
        }
        throw $e;
    }

    // Offene Aufträge der Shop-Erweiterung tragen die Artikelnummer: sie
    // folgen der neuen, sonst fände der Lauf den Artikel nicht mehr. Nur,
    // wenn die Erweiterung eingerichtet ist (Tabelle vorhanden).
    if (null !== $alteNummer) {
        $tabelle = $company->getOne("SELECT to_regclass('public.batchjob_hugoshop') AS t");
        if (!empty($tabelle['t'])) {
            $company->execute(
                "UPDATE batchjob_hugoshop SET partnumber = :neu WHERE partnumber = :alt AND result IS NULL",
                [':neu' => $updateData['partnumber'], ':alt' => $alteNummer]
            );
        }
    }

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
 * Mit template_parts_id („Als neu verwenden“ in der Artikelmaske) kommt alles
 * mit, was die Maske nicht zeigt: Listenpreis, Einkaufspreis, Warengruppe,
 * Preisfaktor, Lager und Lagerplatz, Zolltarifnummer, Übersetzungen,
 * benutzerdefinierte Variablen, Herstellerangaben und Preisgruppenpreise.
 * Nicht mit: Artikelnummer, Bestand, Veraltet und die EAN — ein Strichcode
 * gehört zu genau einem Artikel, eine Kopie fände der Scanner doppelt.
 * Die Shop-Angaben der Vorlage übernimmt die Shop-Karte (savePartShopData).
 *
 * @param array $data Artikel-Daten
 * @param string $data['description'] Beschreibung (Pflicht)
 * @param string $data['partnumber'] Artikelnummer (optional, wird generiert)
 * @param string $data['part_type'] 'part' oder 'service' (Pflicht)
 * @param int $data['buchungsgruppen_id'] Buchungsgruppen-ID (Pflicht)
 * @param float $data['sellprice'] Verkaufspreis
 * @param string $data['unit'] Einheit
 * @param string $data['notes'] Langbeschreibung
 * @param float $data['weight'] Gewicht in der Gewichtseinheit des Mandanten (optional)
 * @param int $data['template_parts_id'] Vorlage: Artikel, dessen übrige Angaben übernommen werden (optional)
 * @param int $data['taxzone_id'] Steuerzone des Belegs, aus dem heraus angelegt wird (optional, Standard 0)
 * @param string $data['transdate'] Belegdatum (optional, Standard heute) — bestimmt den gültigen Steuerschlüssel
 * @return void JSON {parts_id, partnumber, part_type, buchungsziel}; Fehler PARTNUMBER_EXISTS, wenn eine vorgegebene Artikelnummer vergeben ist
 * @testdata {"description": "Test Artikel", "part_type": "service", "buchungsgruppen_id": 1, "sellprice": 100, "unit": "Stck", "taxzone_id": 0}
 */
function createPart($data) {
    permit(['part_service_assembly_edit', 'invoice_edit', 'sales_order_edit', 'sales_quotation_edit'], false);

    $company = DbhCompany::begin();

    $description = trim($data['description'] ?? '');
    $partType = $data['part_type'] ?? 'service';
    $buchungsgruppenId = intval($data['buchungsgruppen_id'] ?? 0);
    $templateId = intval($data['template_parts_id'] ?? 0);

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

    // Gewicht: leer = nicht gepflegt, sonst eine Zahl ab 0
    $weight = $data['weight'] ?? null;
    $weight = ($weight === null || $weight === '' || !is_numeric($weight) || (float)$weight < 0) ? null : (float)$weight;

    $partnumber = trim($data['partnumber'] ?? '');
    $partParams = [
        ':description'        => $description,
        ':part_type'          => $partType,
        ':buchungsgruppen_id' => $buchungsgruppenId,
        ':sellprice'          => floatval($data['sellprice'] ?? 0),
        ':unit'               => trim($data['unit'] ?? 'Stck'),
        ':notes'              => trim($data['notes'] ?? ''),
        ':weight'             => $weight,
        // Buchungsziel (Erlöskonto, Steuerkonto, tax_id, Satz) gleich mitliefern: die
        // Faktura rechnet damit die Umsatzsteuer der Position. Ohne diese Angabe stünde
        // der Artikel in der Position mit Satz 0 und der Beleg wäre zu niedrig besteuert.
        ':taxzone_id'         => intval($data['taxzone_id'] ?? 0),
        ':transdate'          => trim($data['transdate'] ?? ''),
        ':bg_bz'              => $buchungsgruppenId
    ];

    // Gleiche Ableitung wie bei der Artikelsuche (fetchParts) und beim Laden der
    // Positionen (faktura.php): Buchungsgruppe → taxzone_charts → Erlöskonto → am
    // Belegdatum gültiger Steuerschlüssel.
    $buchungszielSql = <<<SQL
        (
            SELECT row_to_json(buchungsziel)
            FROM (
                SELECT
                    c2.id AS income_chart_id,
                    tk.tax_id,
                    tx.chart_id AS tax_chart_id,
                    tx.rate
                FROM buchungsgruppen bg
                JOIN taxzone_charts tc ON tc.buchungsgruppen_id = bg.id AND tc.taxzone_id = :taxzone_id
                LEFT JOIN chart c2 ON c2.id = tc.income_accno_id
                LEFT JOIN taxkeys tk ON tk.chart_id = c2.id
                    AND tk.startdate <= COALESCE(NULLIF(:transdate, '')::date, current_date)
                LEFT JOIN tax tx ON tx.id = tk.tax_id
                WHERE bg.id = :bg_bz
                ORDER BY tk.startdate DESC
                LIMIT 1
            ) AS buchungsziel
        ) AS buchungsziel
    SQL;

    // Die Anweisung besteht aus festen Bausteinen; Werte gehen nur als Platzhalter
    // hinein. Mit Vorlage kommen die Spalten der Vorlage und ihre Nebentabellen dazu.
    $vorlageSpalten = '';
    $vorlageWerte   = '';
    $vorlageQuelle  = '';
    $vorlageTabellen = '';
    if ($templateId > 0) {
        $partParams[':template_parts_id'] = $templateId;
        $vorlageSpalten = ', listprice, lastcost, makemodel, rop, shop, bom, image, drawing, microfiche,
                           partsgroup_id, ve, gv, formel, not_discountable, payment_id, price_factor_id,
                           stockable, has_sernumber, warehouse_id, bin_id, classification_id,
                           order_qty, order_locked, tariff_code';
        $vorlageWerte = ', v.listprice, v.lastcost, COALESCE(v.makemodel, FALSE), v.rop, COALESCE(v.shop, FALSE),
                          COALESCE(v.bom, FALSE), v.image, v.drawing, v.microfiche,
                          v.partsgroup_id, v.ve, v.gv, v.formel, COALESCE(v.not_discountable, FALSE), v.payment_id,
                          v.price_factor_id, COALESCE(v.stockable, FALSE), COALESCE(v.has_sernumber, FALSE),
                          v.warehouse_id, v.bin_id, COALESCE(v.classification_id, 0),
                          COALESCE(v.order_qty, 0), COALESCE(v.order_locked, FALSE), v.tariff_code';
        // Die Vorlage muss es geben — sonst entsteht kein Artikel (Ergebnis leer)
        $vorlageQuelle = 'FROM parts v WHERE v.id = :template_parts_id';
        $vorlageTabellen = <<<SQL
            , uebersetzung AS (
                INSERT INTO translation (parts_id, language_id, translation, longdescription)
                SELECT np.id, t.language_id, t.translation, t.longdescription
                  FROM np, translation t WHERE t.parts_id = :tpl_translation
            ), variablen AS (
                INSERT INTO custom_variables (config_id, trans_id, sub_module, bool_value, timestamp_value, text_value, number_value)
                SELECT cv.config_id, np.id, cv.sub_module, cv.bool_value, cv.timestamp_value, cv.text_value, cv.number_value
                  FROM np, custom_variables cv
                  JOIN custom_variable_configs cvc ON cvc.id = cv.config_id AND cvc.module = 'IC'
                 WHERE cv.trans_id = :tpl_variables
            ), hersteller AS (
                INSERT INTO makemodel (parts_id, make, model, lastcost, lastupdate, sortorder, part_description, part_longdescription)
                SELECT np.id, m.make, m.model, m.lastcost, m.lastupdate, m.sortorder, m.part_description, m.part_longdescription
                  FROM np, makemodel m WHERE m.parts_id = :tpl_makemodel
            ), preisgruppen AS (
                INSERT INTO prices (parts_id, pricegroup_id, price)
                SELECT np.id, pr.pricegroup_id, pr.price
                  FROM np, prices pr WHERE pr.parts_id = :tpl_prices
            )
        SQL;
        // PDO ohne Emulation erlaubt keinen Platzhalter zweimal
        foreach (['translation', 'variables', 'makemodel', 'prices'] as $teil) {
            $partParams[":tpl_{$teil}"] = $templateId;
        }
    }

    if (empty($partnumber)) {
        // Artikelnummer automatisch vergeben: nextFreeNumber zählt den defaults-Counter
        // hoch und überspringt bereits vergebene Nummern in parts.partnumber.
        $numberField = ($partType === 'service') ? 'servicenumber' : 'articlenumber';
        $partnumber = nextFreeNumber($company, $numberField, 'parts', 'partnumber');
        $nummerFrei = '';
    } else {
        // Manuelle Artikelnummer: nur anlegen, wenn sie noch frei ist. Die Prüfung
        // steckt in derselben Abfrage und hängt nicht an einem eindeutigen Index
        // auf partnumber. Eigener Platzhalter für die Prüfung, weil PDO ohne
        // Emulation keinen Namen zweimal erlaubt.
        $nummerFrei = ($vorlageQuelle ? 'AND' : 'WHERE').' NOT EXISTS (SELECT 1 FROM parts WHERE partnumber = :partnumber_check)';
        $partParams[':partnumber_check'] = $partnumber;
    }
    $partParams[':partnumber'] = $partnumber;

    $result = $company->getOne(<<<SQL
        WITH np AS (
            INSERT INTO parts (partnumber, description, part_type, buchungsgruppen_id, sellprice, unit, notes, obsolete, weight{$vorlageSpalten})
            SELECT :partnumber, :description, :part_type, :buchungsgruppen_id, :sellprice, :unit, :notes, FALSE, :weight{$vorlageWerte}
            {$vorlageQuelle} {$nummerFrei}
            RETURNING id, partnumber
        ){$vorlageTabellen}
        SELECT np.id, np.partnumber, {$buchungszielSql}
        FROM np
    SQL, $partParams);

    if (!$result) {
        if ($nummerFrei) {
            resultInfo(false, 'PARTNUMBER_EXISTS', ['message' => 'Artikelnummer ist bereits vergeben']);
        } else {
            resultInfo(false, 'TEMPLATE_NOT_FOUND', ['message' => 'Vorlage nicht gefunden']);
        }
        return;
    }

    resultInfo(true, 'CREATED', [
        'parts_id' => $result['id'],
        'partnumber' => $result['partnumber'],
        'part_type' => $partType,
        'buchungsziel' => !empty($result['buchungsziel']) ? json_decode($result['buchungsziel'], true) : null
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

/**
 * Liefert die nächste freie Artikelnummer aus dem Nummernkreis, OHNE den Zähler
 * hochzuzählen (Vorbelegung im Dialog). Bereits vergebene Nummern werden übersprungen,
 * genau wie in nextFreeNumber(). Verbraucht wird die Nummer erst beim Anlegen.
 *
 * @param string $data['part_type'] 'part' (articlenumber) oder 'service' (servicenumber)
 * @return void JSON {partnumber, weightunit}
 * @testdata {"part_type": "part"}
 */
function peekNextPartnumber($data) {
    permit(['part_service_assembly_edit', 'invoice_edit', 'sales_order_edit', 'sales_quotation_edit'], false);

    $company = DbhCompany::begin();
    $numberField = (($data['part_type'] ?? 'part') === 'service') ? 'servicenumber' : 'articlenumber';

    $row = $company->getOne(<<<SQL
        WITH RECURSIVE c(n) AS (
            SELECT COALESCE({$numberField}::BIGINT, 0) + 1 FROM defaults
            UNION ALL
            SELECT n + 1 FROM c WHERE EXISTS (SELECT 1 FROM parts WHERE partnumber = c.n::TEXT)
        )
        SELECT MAX(n)::TEXT AS partnumber, (SELECT weightunit FROM defaults LIMIT 1) AS weightunit FROM c
    SQL);

    // Die Gewichtseinheit gleich mit: die Neuanlage zeigt das Gewichtsfeld und hat sonst keine Quelle dafür
    resultInfo(true, '', ['partnumber' => $row['partnumber'] ?? '', 'weightunit' => $row['weightunit'] ?? '']);
}
