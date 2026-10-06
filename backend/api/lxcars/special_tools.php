<?php
// backend/api/lxcars/special_tools.php
//
// Spezialwerkzeug: Werkzeuge einlagern und per KI den passenden Fahrzeugen
// zuordnen. Die Zuordnung steckt in Regeln (special_tool_rules_lxcars), die
// in SQL gegen das Fahrzeugprofil geprüft werden. Die Treffer liegen als
// Cache in special_tool_vehicle_matches_lxcars; jede Änderung an Regeln oder
// Zuordnungen frischt ihn in derselben Abfrage auf (special_tool_refresh_
// matches_lxcars), Fahrzeugänderungen erledigt ein Trigger — siehe
// backend/upstall/lxcars/company_schema.sql.
// Die KI (Anthropic) schlägt die Regeln vor, der Mensch darf sie jederzeit
// ändern, abschalten, löschen oder einzelne Fahrzeuge fest zuordnen bzw.
// ausschließen.

/**
 * Werkzeugliste mit Kennzahlen
 *
 * Eine Abfrage: alle Werkzeuge mit Anzahl passender Fahrzeuge, Regeln und
 * manuellen Zuordnungen plus Kennzahlen für die Kopfzeile.
 *
 * @param string $data['search'] Suchbegriff über Name, Nummer, Hersteller, Kategorie, Lagerort (optional)
 * @param string $data['status'] Nur dieser Status: available | lent | defective (optional)
 * @testdata {"search": "", "status": ""}
 */
function getSpecialTools($data) {
    $db = DbhCompany::begin();
    $search = trim($data['search'] ?? '');
    $status = trim($data['status'] ?? '');

    $query = <<<SQL
        WITH m AS (
            SELECT tool_id, COUNT(DISTINCT c_id) AS vehicles
            FROM special_tool_vehicle_matches_lxcars
            GROUP BY tool_id
        ),
        r AS (
            SELECT tool_id,
                   COUNT(*)                                   AS rules,
                   COUNT(*) FILTER (WHERE active)             AS active_rules,
                   COUNT(*) FILTER (WHERE source = 'manual')  AS manual_rules
            FROM special_tool_rules_lxcars
            GROUP BY tool_id
        ),
        o AS (
            SELECT tool_id,
                   COUNT(*) FILTER (WHERE mode = 'include') AS pinned,
                   COUNT(*) FILTER (WHERE mode = 'exclude') AS excluded
            FROM special_tool_vehicles_lxcars
            GROUP BY tool_id
        ),
        tools AS (
            SELECT t.id, t.name, t.tool_number, t.manufacturer, t.category, t.description,
                   t.location, t.bin_id, t.status, t.lent_to, t.ai_summary, t.ai_analyzed_at,
                   t.updated_at, t.shop_sell, t.shop_rent, t.purchase_price, t.sale_price, t.rental_price,
                   b.description AS bin, w.description AS warehouse,
                   COALESCE(m.vehicles, 0)      AS vehicles,
                   COALESCE(r.rules, 0)         AS rules,
                   COALESCE(r.active_rules, 0)  AS active_rules,
                   COALESCE(r.manual_rules, 0)  AS manual_rules,
                   COALESCE(o.pinned, 0)        AS pinned,
                   COALESCE(o.excluded, 0)      AS excluded
            FROM special_tools_lxcars t
            LEFT JOIN m ON m.tool_id = t.id
            LEFT JOIN r ON r.tool_id = t.id
            LEFT JOIN o ON o.tool_id = t.id
            LEFT JOIN bin b       ON b.id = t.bin_id
            LEFT JOIN warehouse w ON w.id = b.warehouse_id
            WHERE (:status = '' OR t.status = :status)
              AND (:search = ''
                   OR t.name         ILIKE '%' || :search || '%'
                   OR t.tool_number  ILIKE '%' || :search || '%'
                   OR t.manufacturer ILIKE '%' || :search || '%'
                   OR t.category     ILIKE '%' || :search || '%'
                   OR t.location     ILIKE '%' || :search || '%'
                   OR t.description  ILIKE '%' || :search || '%')
        )
        SELECT json_build_object(
            'items', COALESCE((SELECT json_agg(x ORDER BY x.name) FROM tools x), '[]'::json),
            'kpi', json_build_object(
                'total',      (SELECT COUNT(*) FROM special_tools_lxcars),
                'lent',       (SELECT COUNT(*) FROM special_tools_lxcars WHERE status = 'lent'),
                'unassigned', (SELECT COUNT(*) FROM special_tools_lxcars t
                               WHERE NOT EXISTS (SELECT 1 FROM m WHERE m.tool_id = t.id)),
                'vehicles_covered', (SELECT COUNT(DISTINCT c_id) FROM special_tool_vehicle_matches_lxcars),
                'vehicles_total',   (SELECT COUNT(*) FROM cars_lxcars),
                -- Profile fehlen (z. B. nach Datenimport ohne Trigger) → Neuberechnung anbieten
                'profiles_stale',   (SELECT COUNT(*) FROM cars_lxcars) <> (SELECT COUNT(*) FROM special_tool_vehicle_profiles_lxcars)
            )
        ) AS result
    SQL;

    $row = $db->getOne($query, [':search' => $search, ':status' => $status]);
    resultInfo(true, '', ['results' => json_decode($row['result'] ?? '{}', true)]);
}

/**
 * Auswahllisten für den Werkzeug- und Regel-Editor
 *
 * Kategorien und Lagerorte aus den vorhandenen Werkzeugen, Lagerplätze aus
 * dem Lagermodul, Hersteller/HSN, Motorcodes, Kraftstoffgruppen und
 * Fahrzeugarten aus der Flotte.
 *
 * @testdata {}
 */
function getSpecialToolOptions($data) {
    $db = DbhCompany::begin();

    $query = <<<SQL
        WITH p AS (SELECT * FROM special_tool_vehicle_profiles_lxcars)
        SELECT json_build_object(
            'categories', COALESCE((
                SELECT json_agg(x.category ORDER BY x.category)
                FROM (SELECT DISTINCT category FROM special_tools_lxcars WHERE COALESCE(category, '') <> '') x
            ), '[]'::json),
            'locations', COALESCE((
                SELECT json_agg(x.location ORDER BY x.location)
                FROM (SELECT DISTINCT location FROM special_tools_lxcars WHERE COALESCE(location, '') <> '') x
            ), '[]'::json),
            'bins', COALESCE((
                SELECT json_agg(json_build_object('id', b.id, 'label', w.description || ' / ' || b.description)
                                ORDER BY w.sortkey NULLS LAST, w.description, b.description)
                FROM bin b JOIN warehouse w ON w.id = b.warehouse_id
                WHERE COALESCE(w.invalid, false) = false
            ), '[]'::json),
            'makes', COALESCE((
                SELECT json_agg(json_build_object('make', x.make, 'hsn', x.hsn, 'n', x.n) ORDER BY x.n DESC)
                FROM (SELECT make, hsn, COUNT(*) AS n FROM p
                      WHERE make IS NOT NULL GROUP BY make, hsn ORDER BY n DESC LIMIT 150) x
            ), '[]'::json),
            'engine_codes', COALESCE((
                SELECT json_agg(json_build_object('code', x.engine_code, 'make', x.make, 'n', x.n) ORDER BY x.n DESC, x.engine_code)
                FROM (SELECT engine_code, MIN(make) AS make, COUNT(*) AS n FROM p
                      WHERE engine_code IS NOT NULL GROUP BY engine_code ORDER BY n DESC LIMIT 400) x
            ), '[]'::json),
            'fuels', COALESCE((
                SELECT json_agg(x.fuel ORDER BY x.n DESC)
                FROM (SELECT fuel, COUNT(*) AS n FROM p WHERE fuel IS NOT NULL GROUP BY fuel) x
            ), '[]'::json),
            'vehicle_types', COALESCE((
                SELECT json_agg(x.vehicle_type ORDER BY x.n DESC)
                FROM (SELECT vehicle_type, COUNT(*) AS n FROM p WHERE vehicle_type IS NOT NULL GROUP BY vehicle_type) x
            ), '[]'::json)
        ) AS result
    SQL;

    $row = $db->getOne($query);
    resultInfo(true, '', ['results' => json_decode($row['result'] ?? '{}', true)]);
}

/**
 * Detail eines Werkzeugs: Stammdaten, Regeln mit Trefferzahl, passende
 * Fahrzeuge, feste Zuordnungen und Ausschlüsse
 *
 * @param int    $data['id']      Werkzeug-ID
 * @param string $data['search']  Filter über die Fahrzeugliste (Kennzeichen, Marke, Modell, Motorcode, Halter) (optional)
 * @param int    $data['limit']   Maximale Fahrzeuge in der Liste (Standard 300)
 * @testdata {"id": 1, "search": "", "limit": 300}
 */
function getSpecialTool($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) throw new ApiError('VALIDATION_ERROR', 'id erforderlich');

    $result = _stToolDetail($db, $id, trim($data['search'] ?? ''), intval($data['limit'] ?? 300));
    if (!$result) throw new ApiError('DATA_NOT_FOUND', 'Werkzeug nicht gefunden');

    resultInfo(true, '', ['results' => $result]);
}

/**
 * Baut die Detailansicht eines Werkzeugs in einer Abfrage zusammen
 *
 * @param ApiDatabase $db
 * @param int    $id     Werkzeug-ID
 * @param string $search Filter über die Fahrzeugliste
 * @param int    $limit  Maximale Fahrzeuge in der Liste
 * @return array|null
 */
function _stToolDetail($db, $id, $search = '', $limit = 300) {
    $limit = max(1, min(2000, $limit));

    $query = <<<SQL
        WITH t AS (
            SELECT t.*, b.description AS bin, w.description AS warehouse,
                   ps.partnumber AS sale_partnumber, pr.partnumber AS rental_partnumber
            FROM special_tools_lxcars t
            LEFT JOIN bin b       ON b.id = t.bin_id
            LEFT JOIN warehouse w ON w.id = b.warehouse_id
            LEFT JOIN parts ps    ON ps.id = t.parts_id
            LEFT JOIN parts pr    ON pr.id = t.rental_parts_id
            WHERE t.id = :id
        ),
        p AS (SELECT * FROM special_tool_vehicle_profiles_lxcars),
        m AS (SELECT * FROM special_tool_vehicle_matches_lxcars WHERE tool_id = :id),
        per_vehicle AS (
            SELECT c_id,
                   bool_or(source = 'manual')                                        AS pinned,
                   COALESCE(json_agg(DISTINCT rule_id) FILTER (WHERE rule_id IS NOT NULL), '[]'::json) AS rule_ids,
                   COALESCE(json_agg(DISTINCT note) FILTER (WHERE source = 'rule'), '[]'::json)        AS rule_labels
            FROM m GROUP BY c_id
        ),
        -- Einschlussregeln zählen aus dem Cache; Ausschlussregeln zeigen, wie
        -- viele Fahrzeuge sie wegnehmen würden (live, nur wenige je Werkzeug)
        rule_counts AS (
            SELECT rule_id, COUNT(DISTINCT c_id) AS n FROM m WHERE rule_id IS NOT NULL GROUP BY rule_id
            UNION ALL
            SELECT r.id, (SELECT COUNT(DISTINCT x.c_id) FROM special_tool_compute_matches_lxcars(NULL, NULL, r.criteria) x)
            FROM special_tool_rules_lxcars r WHERE r.tool_id = :id AND r.mode = 'exclude'
        ),
        vehicles AS (
            SELECT pv.c_id, p.c_ln, p.c_ow, p.make, p.model, p.engine_code, p.fuel, p.fuel_name,
                   p.vehicle_type, p.ccm, p.kw, p.year, p.hsn, p.tsn,
                   c.name AS owner, pv.pinned, pv.rule_ids, pv.rule_labels
            FROM per_vehicle pv
            JOIN p ON p.c_id = pv.c_id
            LEFT JOIN customer c ON c.id = p.c_ow
            WHERE :search = ''
               OR p.c_ln            ILIKE '%' || :search || '%'
               OR p.make_model_text ILIKE '%' || :search || '%'
               OR p.engine_code     ILIKE '%' || :search || '%'
               OR c.name            ILIKE '%' || :search || '%'
            ORDER BY p.make, p.model, p.c_ln
            LIMIT :limit
        ),
        excluded AS (
            SELECT v.c_id, p.c_ln, p.make, p.model, p.engine_code, v.note
            FROM special_tool_vehicles_lxcars v
            JOIN p ON p.c_id = v.c_id
            WHERE v.tool_id = :id AND v.mode = 'exclude'
            ORDER BY p.make, p.model, p.c_ln
        )
        SELECT json_build_object(
            'tool', (SELECT row_to_json(t) FROM t),
            'rules', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', r.id, 'label', r.label, 'criteria', r.criteria, 'reason', r.reason,
                    'mode', r.mode, 'source', r.source, 'confidence', r.confidence, 'active', r.active,
                    'sort_order', r.sort_order, 'matches', COALESCE(rc.n, 0)
                ) ORDER BY r.sort_order, r.id)
                FROM special_tool_rules_lxcars r
                LEFT JOIN rule_counts rc ON rc.rule_id = r.id
                WHERE r.tool_id = :id
            ), '[]'::json),
            'vehicles',       COALESCE((SELECT json_agg(v) FROM vehicles v), '[]'::json),
            'vehicles_total', (SELECT COUNT(*) FROM per_vehicle),
            'excluded',       COALESCE((SELECT json_agg(e) FROM excluded e), '[]'::json)
        ) AS result
    SQL;

    $row = $db->getOne($query, [':id' => $id, ':search' => $search, ':limit' => $limit]);
    $result = json_decode($row['result'] ?? '{}', true);
    return empty($result['tool']) ? null : $result;
}

/**
 * Werkzeug anlegen oder ändern (Upsert über die ID)
 *
 * @param int    $data['id']           Werkzeug-ID (0/leer = neu)
 * @param string $data['name']         Bezeichnung (Pflicht)
 * @param string $data['tool_number']  Inventar-/Werkzeugnummer
 * @param string $data['manufacturer'] Hersteller des Werkzeugs
 * @param string $data['category']     Kategorie (z. B. Zahnriemen)
 * @param string $data['description']  Beschreibung / Lieferumfang
 * @param string $data['location']     Lagerort als Freitext
 * @param int    $data['bin_id']       Lagerplatz aus dem Lagermodul (optional)
 * @param string $data['status']       available | lent | defective
 * @param string $data['lent_to']      An wen verliehen
 * @param string $data['ai_hint']      Hinweis an die KI für die Zuordnung
 * @param int    $data['employee_id']  Angemeldeter Mitarbeiter (aus dem Store)
 * @testdata {"name": "Zahnriemen-Arretierwerkzeug VW 1.9 TDI PD", "category": "Zahnriemen", "location": "Schrank 2, Schublade 3", "status": "available", "employee_id": 1}
 */
function saveSpecialTool($data) {
    $db = DbhCompany::begin();

    $id     = intval($data['id'] ?? 0);
    $name   = trim($data['name'] ?? '');
    $status = in_array($data['status'] ?? '', ['available', 'lent', 'defective'], true) ? $data['status'] : 'available';
    if ($name === '') throw new ApiError('VALIDATION_ERROR', 'Bezeichnung fehlt');

    $params = [
        ':name'         => $name,
        ':tool_number'  => _stNullIfEmpty($data['tool_number'] ?? null),
        ':manufacturer' => _stNullIfEmpty($data['manufacturer'] ?? null),
        ':category'     => _stNullIfEmpty($data['category'] ?? null),
        ':description'  => _stNullIfEmpty($data['description'] ?? null),
        ':location'     => _stNullIfEmpty($data['location'] ?? null),
        ':bin_id'       => intval($data['bin_id'] ?? 0) ?: null,
        ':status'       => $status,
        ':lent_to'      => $status === 'lent' ? _stNullIfEmpty($data['lent_to'] ?? null) : null,
        ':ai_hint'      => _stNullIfEmpty($data['ai_hint'] ?? null),
    ];

    if ($id > 0) {
        $params[':id'] = $id;
        $row = $db->getOne(
            "UPDATE special_tools_lxcars SET
                name = :name, tool_number = :tool_number, manufacturer = :manufacturer,
                category = :category, description = :description, location = :location,
                bin_id = :bin_id, status = :status, lent_to = :lent_to, ai_hint = :ai_hint,
                updated_at = now()
             WHERE id = :id RETURNING id",
            $params
        );
        if (!$row) throw new ApiError('DATA_NOT_FOUND', 'Werkzeug nicht gefunden');
    } else {
        $params[':created_by'] = intval($data['employee_id'] ?? 0) ?: null;
        $row = $db->getOne(
            "INSERT INTO special_tools_lxcars
                (name, tool_number, manufacturer, category, description, location, bin_id, status, lent_to, ai_hint, created_by)
             VALUES
                (:name, :tool_number, :manufacturer, :category, :description, :location, :bin_id, :status, :lent_to, :ai_hint, :created_by)
             RETURNING id",
            $params
        );
    }

    resultInfo(true, '', ['id' => intval($row['id'])]);
}

/**
 * Werkzeug löschen (Regeln und Zuordnungen fallen per Kaskade mit)
 *
 * @param int $data['id'] Werkzeug-ID
 * @testdata {"id": 1}
 */
function deleteSpecialTool($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) throw new ApiError('VALIDATION_ERROR', 'id erforderlich');

    $row = $db->getOne("DELETE FROM special_tools_lxcars WHERE id = :id RETURNING id", [':id' => $id]);
    if (!$row) throw new ApiError('DATA_NOT_FOUND', 'Werkzeug nicht gefunden');

    resultInfo(true, '', ['id' => $id]);
}

/**
 * Regel anlegen oder ändern
 *
 * Kriterien (alle optional, gesetzte gelten zugleich):
 *   makes[], hsn[], models[], engine_codes[], fuel[], vehicle_types[],
 *   ccm_from, ccm_to, kw_from, kw_to, year_from, year_to
 *
 * @param int    $data['id']        Regel-ID (0/leer = neu)
 * @param int    $data['tool_id']   Werkzeug-ID (bei neu)
 * @param string $data['label']     Kurzbezeichnung der Regel
 * @param array  $data['criteria']  Kriterien als Objekt
 * @param string $data['reason']    Begründung (optional)
 * @param string $data['mode']      include (Standard) | exclude — Ausschlussregeln ziehen Treffer wieder ab
 * @param bool   $data['active']    Regel aktiv (Standard true)
 * @testdata {"tool_id": 1, "label": "VW-Konzern 1.9 TDI PD", "criteria": {"makes": ["VW", "Audi", "Skoda", "Seat"], "fuel": ["Diesel"], "ccm_from": 1850, "ccm_to": 2000, "year_from": 1999, "year_to": 2010}, "reason": "Pumpe-Düse-Motoren", "mode": "include", "active": true}
 */
function saveSpecialToolRule($data) {
    $db = DbhCompany::begin();

    $id       = intval($data['id'] ?? 0);
    $toolId   = intval($data['tool_id'] ?? 0);
    $label    = trim($data['label'] ?? '');
    $criteria = _stSanitizeCriteria(is_array($data['criteria'] ?? null) ? $data['criteria'] : []);
    $active   = !isset($data['active']) || filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
    $mode     = ($data['mode'] ?? 'include') === 'exclude' ? 'exclude' : 'include';

    if ($label === '') throw new ApiError('VALIDATION_ERROR', 'Bezeichnung der Regel fehlt');

    $params = [
        ':label'    => $label,
        ':criteria' => json_encode($criteria, JSON_UNESCAPED_UNICODE),
        ':reason'   => _stNullIfEmpty($data['reason'] ?? null),
        ':mode'     => $mode,
        ':active'   => $active,
    ];

    if ($id > 0) {
        $params[':id'] = $id;
        // Eine vom Menschen bearbeitete Regel gehört ab jetzt dem Menschen —
        // eine neue KI-Analyse ersetzt nur noch KI-Regeln.
        $row = $db->getOne(
            "WITH upd AS (
                UPDATE special_tool_rules_lxcars SET
                    label = :label, criteria = CAST(:criteria AS jsonb), reason = :reason,
                    mode = :mode, active = :active, source = 'manual', updated_at = now()
                WHERE id = :id RETURNING id, tool_id
             )
             SELECT upd.id, upd.tool_id, special_tool_refresh_matches_lxcars(upd.tool_id, NULL) AS matches FROM upd",
            $params
        );
        if (!$row) throw new ApiError('DATA_NOT_FOUND', 'Regel nicht gefunden');
        _stSyncShopArticles($db, intval($row['tool_id']));
    } else {
        if (!$toolId) throw new ApiError('VALIDATION_ERROR', 'tool_id erforderlich');
        $params[':tool_id'] = $toolId;
        $row = $db->getOne(
            "WITH ins AS (
                INSERT INTO special_tool_rules_lxcars (tool_id, label, criteria, reason, mode, source, active, sort_order)
                VALUES (:tool_id, :label, CAST(:criteria AS jsonb), :reason, :mode, 'manual', :active,
                        (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM special_tool_rules_lxcars WHERE tool_id = :tool_id))
                RETURNING id, tool_id
             )
             SELECT ins.id, ins.tool_id, special_tool_refresh_matches_lxcars(ins.tool_id, NULL) AS matches FROM ins",
            $params
        );
        _stSyncShopArticles($db, intval($row['tool_id']));
    }

    resultInfo(true, '', ['id' => intval($row['id']), 'tool_id' => intval($row['tool_id'])]);
}

/**
 * Regel ein- oder ausschalten
 *
 * @param int  $data['id']     Regel-ID
 * @param bool $data['active'] Neuer Zustand
 * @testdata {"id": 1, "active": false}
 */
function setSpecialToolRuleActive($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) throw new ApiError('VALIDATION_ERROR', 'id erforderlich');

    $row = $db->getOne(
        "WITH upd AS (
            UPDATE special_tool_rules_lxcars SET active = :active, updated_at = now()
            WHERE id = :id RETURNING id, tool_id
         )
         SELECT upd.id, special_tool_refresh_matches_lxcars(upd.tool_id, NULL) AS matches FROM upd",
        [':id' => $id, ':active' => filter_var($data['active'] ?? true, FILTER_VALIDATE_BOOLEAN)]
    );
    if (!$row) throw new ApiError('DATA_NOT_FOUND', 'Regel nicht gefunden');

    resultInfo(true, '', ['id' => $id]);
}

/**
 * Regel löschen
 *
 * @param int $data['id'] Regel-ID
 * @testdata {"id": 1}
 */
function deleteSpecialToolRule($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) throw new ApiError('VALIDATION_ERROR', 'id erforderlich');

    $row = $db->getOne(
        "WITH del AS (DELETE FROM special_tool_rules_lxcars WHERE id = :id RETURNING id, tool_id)
         SELECT del.id, del.tool_id, special_tool_refresh_matches_lxcars(del.tool_id, NULL) AS matches FROM del",
        [':id' => $id]
    );
    if (!$row) throw new ApiError('DATA_NOT_FOUND', 'Regel nicht gefunden');
    _stSyncShopArticles($db, intval($row['tool_id']));

    resultInfo(true, '', ['id' => $id]);
}

/**
 * Vorschau: Wie viele und welche Fahrzeuge treffen diese Kriterien?
 *
 * Für den Regel-Editor — zeigt beim Tippen, was die Regel bewirken würde.
 *
 * @param array $data['criteria'] Kriterien wie bei saveSpecialToolRule
 * @param int   $data['limit']    Beispielfahrzeuge (Standard 8)
 * @testdata {"criteria": {"engine_codes": ["CAY*"], "fuel": ["Diesel"]}, "limit": 8}
 */
function previewSpecialToolCriteria($data) {
    $db = DbhCompany::begin();
    $criteria = _stSanitizeCriteria(is_array($data['criteria'] ?? null) ? $data['criteria'] : []);
    $limit = max(1, min(50, intval($data['limit'] ?? 8)));

    $query = <<<SQL
        WITH m AS (
            SELECT DISTINCT c_id FROM special_tool_compute_matches_lxcars(NULL, NULL, CAST(:criteria AS jsonb))
        ),
        p AS (SELECT * FROM special_tool_vehicle_profiles_lxcars),
        sample AS (
            SELECT p.c_id, p.c_ln, p.make, p.model, p.engine_code, p.fuel, p.ccm, p.kw, p.year
            FROM m JOIN p ON p.c_id = m.c_id
            ORDER BY p.make, p.model, p.year
            LIMIT :limit
        )
        SELECT json_build_object(
            'count',  (SELECT COUNT(*) FROM m),
            'sample', COALESCE((SELECT json_agg(s) FROM sample s), '[]'::json),
            'makes',  COALESCE((
                SELECT json_agg(json_build_object('make', x.make, 'n', x.n) ORDER BY x.n DESC)
                FROM (SELECT p.make, COUNT(*) AS n FROM m JOIN p ON p.c_id = m.c_id
                      GROUP BY p.make ORDER BY n DESC LIMIT 8) x
            ), '[]'::json)
        ) AS result
    SQL;

    $row = $db->getOne($query, [':criteria' => json_encode($criteria, JSON_UNESCAPED_UNICODE), ':limit' => $limit]);
    resultInfo(true, '', ['results' => json_decode($row['result'] ?? '{}', true)]);
}

/**
 * Fahrzeug fest zuordnen, ausschließen oder die manuelle Entscheidung aufheben
 *
 * @param int    $data['tool_id']     Werkzeug-ID
 * @param int    $data['c_id']        Fahrzeug-ID
 * @param string $data['mode']        include | exclude | leer (= Eintrag entfernen, Regeln gelten wieder)
 * @param string $data['note']        Bemerkung (optional)
 * @param int    $data['employee_id'] Angemeldeter Mitarbeiter (aus dem Store)
 * @testdata {"tool_id": 1, "c_id": 1584, "mode": "exclude", "note": "Motor getauscht", "employee_id": 1}
 */
function setSpecialToolVehicle($data) {
    $db = DbhCompany::begin();
    $toolId = intval($data['tool_id'] ?? 0);
    $cId    = intval($data['c_id'] ?? 0);
    $mode   = trim($data['mode'] ?? '');
    if (!$toolId || !$cId) throw new ApiError('VALIDATION_ERROR', 'tool_id und c_id erforderlich');

    // Die Hauptabfrage liest das CTE-Ergebnis (COUNT), damit die Änderung
    // abgeschlossen ist, bevor der Cache neu berechnet wird — ein
    // unreferenziertes schreibendes CTE liefe sonst erst danach.
    if ($mode === '') {
        $db->execute(
            "WITH del AS (
                DELETE FROM special_tool_vehicles_lxcars WHERE tool_id = :tool_id AND c_id = :c_id RETURNING 1
             )
             SELECT special_tool_refresh_matches_lxcars(:tool_id, :c_id) FROM (SELECT COUNT(*) FROM del) d",
            [':tool_id' => $toolId, ':c_id' => $cId]
        );
    } elseif (in_array($mode, ['include', 'exclude'], true)) {
        $db->execute(
            "WITH ins AS (
                INSERT INTO special_tool_vehicles_lxcars (tool_id, c_id, mode, note, created_by)
                VALUES (:tool_id, :c_id, :mode, :note, :created_by)
                ON CONFLICT (tool_id, c_id) DO UPDATE
                    SET mode = EXCLUDED.mode, note = EXCLUDED.note, created_by = EXCLUDED.created_by, created_at = now()
                RETURNING 1
             )
             SELECT special_tool_refresh_matches_lxcars(:tool_id, :c_id) FROM (SELECT COUNT(*) FROM ins) i",
            [
                ':tool_id'    => $toolId,
                ':c_id'       => $cId,
                ':mode'       => $mode,
                ':note'       => _stNullIfEmpty($data['note'] ?? null),
                ':created_by' => intval($data['employee_id'] ?? 0) ?: null,
            ]
        );
    } else {
        throw new ApiError('VALIDATION_ERROR', 'mode muss include, exclude oder leer sein');
    }

    resultInfo(true, '', ['tool_id' => $toolId, 'c_id' => $cId, 'mode' => $mode]);
}

/**
 * Fahrzeugsuche für die manuelle Zuordnung
 *
 * Liefert zu jedem Treffer, ob das Werkzeug dort bereits passt.
 *
 * @param int    $data['tool_id'] Werkzeug-ID
 * @param string $data['term']    Suchbegriff (Kennzeichen, Marke, Modell, Motorcode, Halter)
 * @param int    $data['limit']   Maximale Treffer (Standard 20)
 * @testdata {"tool_id": 1, "term": "Golf", "limit": 20}
 */
function searchSpecialToolVehicles($data) {
    $db = DbhCompany::begin();
    $toolId = intval($data['tool_id'] ?? 0);
    $term   = trim($data['term'] ?? '');
    $limit  = max(1, min(100, intval($data['limit'] ?? 20)));
    if ($term === '') { resultInfo(true, '', ['results' => []]); return; }

    $query = <<<SQL
        WITH p AS (SELECT * FROM special_tool_vehicle_profiles_lxcars),
        m AS (SELECT DISTINCT c_id FROM special_tool_vehicle_matches_lxcars WHERE tool_id = :tool_id)
        SELECT COALESCE(json_agg(x), '[]'::json) AS result
        FROM (
            SELECT p.c_id, p.c_ln, p.make, p.model, p.engine_code, p.fuel, p.ccm, p.kw, p.year,
                   c.name AS owner,
                   EXISTS (SELECT 1 FROM m WHERE m.c_id = p.c_id) AS matched,
                   (SELECT v.mode FROM special_tool_vehicles_lxcars v
                     WHERE v.tool_id = :tool_id AND v.c_id = p.c_id) AS mode
            FROM p
            LEFT JOIN customer c ON c.id = p.c_ow
            WHERE p.c_ln            ILIKE '%' || :term || '%'
               OR p.make_model_text ILIKE '%' || :term || '%'
               OR p.engine_code     ILIKE '%' || :term || '%'
               OR c.name            ILIKE '%' || :term || '%'
            ORDER BY (p.c_ln ILIKE :term || '%') DESC,
                     (p.make_model_text ILIKE '%' || :term || '%') DESC,
                     p.make, p.model, p.c_ln
            LIMIT :limit
        ) x
    SQL;

    $row = $db->getOne($query, [':tool_id' => $toolId, ':term' => $term, ':limit' => $limit]);
    resultInfo(true, '', ['results' => json_decode($row['result'] ?? '[]', true)]);
}

/**
 * Spezialwerkzeuge, die zu einem Fahrzeug passen
 *
 * Für die Karte in der Fahrzeugansicht und im Werkstattauftrag: Name,
 * Lagerort, Status und warum es passt.
 *
 * @param int $data['c_id'] Fahrzeug-ID
 * @testdata {"c_id": 1584}
 */
function getSpecialToolsForCar($data) {
    $db = DbhCompany::begin();
    $cId = intval($data['c_id'] ?? 0);
    if (!$cId) throw new ApiError('VALIDATION_ERROR', 'c_id erforderlich');

    $query = <<<SQL
        SELECT COALESCE(json_agg(x ORDER BY x.name), '[]'::json) AS result
        FROM (
            SELECT t.id, t.name, t.tool_number, t.category, t.location, t.status, t.lent_to,
                   b.description AS bin, w.description AS warehouse,
                   bool_or(m.source = 'manual') AS pinned,
                   COALESCE(json_agg(DISTINCT m.note) FILTER (WHERE m.source = 'rule'), '[]'::json) AS rule_labels
            FROM special_tool_vehicle_matches_lxcars m
            JOIN special_tools_lxcars t ON t.id = m.tool_id
            LEFT JOIN bin b       ON b.id = t.bin_id
            LEFT JOIN warehouse w ON w.id = b.warehouse_id
            WHERE m.c_id = :c_id
            GROUP BY t.id, b.description, w.description
        ) x
    SQL;

    $row = $db->getOne($query, [':c_id' => $cId]);
    resultInfo(true, '', ['results' => json_decode($row['result'] ?? '[]', true)]);
}

/**
 * Fahrzeugprofile und Treffer-Cache komplett neu berechnen
 *
 * Nötig nach Datenimporten, die den Trigger umgehen (z. B. KBA-Import).
 * Eine Abfrage; bei 10.000 Fahrzeugen rund 0,3 s.
 *
 * @testdata {}
 */
function rebuildSpecialToolMatches($data) {
    $db = DbhCompany::begin();
    // CTE zuerst: die Profile müssen stehen, bevor die Treffer berechnet werden
    $row = $db->getOne(
        "WITH p AS (SELECT special_tool_build_profiles_lxcars(NULL) AS profiles)
         SELECT p.profiles, special_tool_refresh_matches_lxcars(NULL, NULL) AS matches FROM p"
    );
    resultInfo(true, '', ['profiles' => intval($row['profiles']), 'matches' => intval($row['matches'])]);
}

// ── Shop: Verleih und Verkauf ────────────────────────────────────────────────

/**
 * Werkzeug im HugoShop anbieten: zum Verleih, zum Kauf oder beides
 *
 * Legt je Angebot einen Artikel an (Verkauf: Ware, Miete: Dienstleistung),
 * setzt die Preise, nimmt die Artikel in alle HugoShop-Kanäle auf und
 * stellt die Veröffentlichung ein. Vorgaben: Verkaufspreis = Einkaufspreis,
 * Miete = ein Drittel des Einkaufspreises. Auf die Produktseite kommt die
 * Fahrzeugliste aus den Zuordnungsregeln (Hersteller, Motorcodes, Baujahre).
 * Abschalten nimmt die Artikel aus den Kanälen; sie bleiben für ein
 * Wiedereinschalten erhalten.
 *
 * @param int    $data['id']             Werkzeug-ID
 * @param bool   $data['shop_sell']      Zum Kauf anbieten
 * @param bool   $data['shop_rent']      Zum Verleih anbieten
 * @param number $data['purchase_price'] Einkaufspreis (netto, wie parts.lastcost)
 * @param number $data['sale_price']     Verkaufspreis (leer = Einkaufspreis)
 * @param number $data['rental_price']   Miete je Mietvorgang (leer = Einkaufspreis / 3)
 * @param int    $data['rental_days']    Mietdauer in Tagen (Standard 7)
 * @testdata {"id": 1, "shop_sell": true, "shop_rent": true, "purchase_price": 149.9, "sale_price": "", "rental_price": "", "rental_days": 7}
 */
function saveSpecialToolShopOffer($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) throw new ApiError('VALIDATION_ERROR', 'id erforderlich');

    $money = function ($v) {
        if ($v === null || $v === '') return null;
        $v = str_replace(',', '.', (string)$v);
        return is_numeric($v) && floatval($v) >= 0 ? round(floatval($v), 2) : null;
    };
    $purchase = $money($data['purchase_price'] ?? null);
    $sale     = $money($data['sale_price'] ?? null) ?? $purchase;
    $rental   = $money($data['rental_price'] ?? null) ?? ($purchase !== null ? round($purchase / 3, 2) : null);
    $sell     = filter_var($data['shop_sell'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $rent     = filter_var($data['shop_rent'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $days     = max(1, intval($data['rental_days'] ?? 7));

    if ($sell && !$sale)   throw new ApiError('VALIDATION_ERROR', 'Verkaufspreis fehlt');
    if ($rent && !$rental) throw new ApiError('VALIDATION_ERROR', 'Mietpreis fehlt');
    if (($sell || $rent) && !existingTables($db, ['sales_channel_shop'])) {
        throw new ApiError('SHOP_NOT_ACTIVE', 'Die Shop-Erweiterung ist für diese Firma nicht aktiv');
    }

    $row = $db->getOne(
        "UPDATE special_tools_lxcars SET
            purchase_price = :purchase, sale_price = :sale, rental_price = :rental,
            rental_days = :days, shop_sell = :sell, shop_rent = :rent, updated_at = now()
         WHERE id = :id RETURNING id",
        [':purchase' => $purchase, ':sale' => $sale, ':rental' => $rental, ':days' => $days,
         ':sell' => $sell, ':rent' => $rent, ':id' => $id]
    );
    if (!$row) throw new ApiError('DATA_NOT_FOUND', 'Werkzeug nicht gefunden');

    $sync = _stSyncShopArticles($db, $id);
    $detail = _stToolDetail($db, $id);
    $detail['shop_sync'] = $sync;
    resultInfo(true, '', ['results' => $detail]);
}

/**
 * Führt die Shop-Artikel eines Werkzeugs nach
 *
 * Ohne Shop-Erweiterung oder ohne Angebot und Artikel passiert nichts.
 * Sonst: Artikel anlegen (einmalig) und aktualisieren, Shop-Angaben
 * (parts_ext) mit der Fahrzeugliste aus den Regeln schreiben, Kanalzeilen
 * aller HugoShops ein- oder ausschalten und Aufträge zum Veröffentlichen
 * bzw. Entfernen der Seiten einstellen.
 *
 * @param ApiDatabase $db
 * @param int $toolId
 * @return array ['synced' => bool, 'jobs' => int, 'notes' => string[]]
 */
function _stSyncShopArticles($db, int $toolId): array {
    $result = ['synced' => false, 'jobs' => 0, 'notes' => []];
    if (count(existingTables($db, ['sales_channel_shop', 'parts_ext', 'parts_channel_shop'])) < 3) {
        return $result;
    }

    // Werkzeug samt Fahrzeugliste aus den aktiven Einschlussregeln
    $tool = $db->getOne(<<<SQL
        WITH r AS (
            SELECT criteria FROM special_tool_rules_lxcars
            WHERE tool_id = :id AND active AND mode = 'include'
        ),
        lists AS (
            SELECT e.key, string_agg(DISTINCT x, ', ' ORDER BY x) AS vals
            FROM r, jsonb_each(r.criteria) e, jsonb_array_elements_text(e.value) x
            WHERE jsonb_typeof(e.value) = 'array'
            GROUP BY e.key
        )
        SELECT t.*,
               ps.partnumber AS sale_partnumber, pr.partnumber AS rental_partnumber,
               (SELECT vals FROM lists WHERE key = 'makes')        AS fit_makes,
               (SELECT vals FROM lists WHERE key = 'models')       AS fit_models,
               (SELECT vals FROM lists WHERE key = 'engine_codes') AS fit_engines,
               (SELECT vals FROM lists WHERE key = 'fuel')         AS fit_fuel,
               (SELECT MIN((criteria->>'year_from')::int) FROM r WHERE criteria->>'year_from' ~ '^\d+$') AS fit_year_from,
               (SELECT MAX((criteria->>'year_to')::int)   FROM r WHERE criteria->>'year_to'   ~ '^\d+$') AS fit_year_to,
               (SELECT COUNT(DISTINCT c_id) FROM special_tool_vehicle_matches_lxcars WHERE tool_id = t.id) AS fit_count,
               COALESCE((SELECT value::int FROM defaults_oserp WHERE key = 'special_tools_buchungsgruppen_id' AND value ~ '^\d+$'),
                        (SELECT buchungsgruppen_id FROM parts WHERE buchungsgruppen_id IS NOT NULL
                          GROUP BY 1 ORDER BY COUNT(*) DESC LIMIT 1)) AS buchungsgruppen_id,
               COALESCE((SELECT unit FROM parts WHERE part_type = 'part' GROUP BY 1 ORDER BY COUNT(*) DESC LIMIT 1), 'Stck') AS unit_part,
               COALESCE((SELECT unit FROM parts WHERE part_type = 'service' GROUP BY 1 ORDER BY COUNT(*) DESC LIMIT 1), 'Stck') AS unit_service
        FROM special_tools_lxcars t
        LEFT JOIN parts ps ON ps.id = t.parts_id
        LEFT JOIN parts pr ON pr.id = t.rental_parts_id
        WHERE t.id = :id
    SQL, [':id' => $toolId]);
    if (!$tool) return $result;

    $sell = filter_var($tool['shop_sell'], FILTER_VALIDATE_BOOLEAN);
    $rent = filter_var($tool['shop_rent'], FILTER_VALIDATE_BOOLEAN);
    if (!$sell && !$rent && !$tool['parts_id'] && !$tool['rental_parts_id']) return $result;
    if (($sell || $rent) && empty($tool['buchungsgruppen_id'])) {
        throw new ApiError('MISSING_BUCHUNGSGRUPPE', 'Keine Buchungsgruppe für Shop-Artikel gefunden (defaults_oserp: special_tools_buchungsgruppen_id)');
    }

    // Fahrzeugliste als technische Daten der Produktseite
    $tech = [];
    if ($tool['category'])      $tech['Kategorie']            = $tool['category'];
    if ($tool['fit_makes'])     $tech['Passend für Hersteller'] = $tool['fit_makes'];
    if ($tool['fit_models'])    $tech['Modelle']              = $tool['fit_models'];
    if ($tool['fit_engines']) {
        // Lange Listen am Komma kürzen, damit die Seite lesbar bleibt
        $codes = $tool['fit_engines'];
        if (mb_strlen($codes) > 400) $codes = mb_substr($codes, 0, mb_strrpos(mb_substr($codes, 0, 400), ',')) . ' …';
        $tech['Motorcodes'] = $codes;
    }
    if ($tool['fit_fuel'])      $tech['Kraftstoff']           = $tool['fit_fuel'];
    if ($tool['fit_year_from'] || $tool['fit_year_to']) {
        $tech['Baujahre'] = trim(($tool['fit_year_from'] ?: '') . ' – ' . ($tool['fit_year_to'] ?: ''), ' –');
    }
    if ($tool['manufacturer'])  $tech['Werkzeughersteller']   = $tool['manufacturer'];
    if ($tool['tool_number'])   $tech['Werkzeugnummer']       = $tool['tool_number'];

    $summary = trim((string)($tool['ai_summary'] ?? ''));
    $text    = trim((string)($tool['description'] ?? ''));
    $notes   = trim($text . ($summary !== '' ? "\n\n" . $summary : ''));
    $notes  .= "\n\nOb das Werkzeug zu Ihrem Fahrzeug passt, prüfen Sie mit der Werkzeugsuche nach HSN/TSN oder Fahrgestellnummer.";

    $offers = [
        'sell' => [
            'wanted'   => $sell,
            'column'   => 'parts_id',
            'parts_id' => intval($tool['parts_id'] ?? 0),
            'type'     => 'part',
            'counter'  => 'articlenumber',
            'unit'     => $tool['unit_part'],
            'name'     => $tool['name'],
            'price'    => floatval($tool['sale_price'] ?? 0),
            'lastcost' => $tool['purchase_price'],
            'notes'    => $notes,
            'category' => 'Spezialwerkzeug',
            'slug'     => 'spezialwerkzeug-' . _stSlug($tool['name']),
            'props'    => [],
        ],
        'rent' => [
            'wanted'   => $rent,
            'column'   => 'rental_parts_id',
            'parts_id' => intval($tool['rental_parts_id'] ?? 0),
            'type'     => 'service',
            'counter'  => 'servicenumber',
            'unit'     => $tool['unit_service'],
            'name'     => 'Miete: ' . $tool['name'],
            'price'    => floatval($tool['rental_price'] ?? 0),
            'lastcost' => null,
            'notes'    => "Mietangebot: Sie leihen das Werkzeug für {$tool['rental_days']} Tage.\n\n" . $notes,
            'category' => 'Werkzeugverleih',
            'slug'     => 'miete-' . _stSlug($tool['name']),
            'props'    => ['Mietdauer' => $tool['rental_days'] . ' Tage', 'Angebot' => 'Verleih'],
        ],
    ];

    $db->beginTransaction();
    try {
        foreach ($offers as $offer) {
            $partsId = $offer['parts_id'];
            if (!$partsId && !$offer['wanted']) continue;

            if (!$partsId) {
                // Artikel einmalig anlegen; Nummer aus dem Nummernkreis
                $number = nextFreeNumber($db, $offer['counter'], 'parts', 'partnumber');
                $row = $db->getOne(
                    "INSERT INTO parts (partnumber, description, part_type, buchungsgruppen_id, sellprice, lastcost, unit, notes, obsolete)
                     VALUES (:partnumber, :description, CAST(:part_type AS part_type_enum), :bg, :sellprice, :lastcost, :unit, :notes, FALSE)
                     RETURNING id",
                    [':partnumber' => $number, ':description' => $offer['name'], ':part_type' => $offer['type'],
                     ':bg' => intval($tool['buchungsgruppen_id']), ':sellprice' => $offer['price'],
                     ':lastcost' => $offer['lastcost'], ':unit' => $offer['unit'], ':notes' => $offer['notes']]
                );
                $partsId = intval($row['id']);
                $db->execute("UPDATE special_tools_lxcars SET {$offer['column']} = :pid WHERE id = :id",
                             [':pid' => $partsId, ':id' => $toolId]);
                $result['notes'][] = 'Artikel ' . $number . ' angelegt';
            } else {
                $db->execute(
                    "UPDATE parts SET description = :description, sellprice = :sellprice, lastcost = COALESCE(:lastcost, lastcost),
                            notes = :notes, obsolete = FALSE, mtime = now()
                     WHERE id = :id",
                    [':description' => $offer['name'], ':sellprice' => $offer['price'], ':lastcost' => $offer['lastcost'],
                     ':notes' => $offer['notes'], ':id' => $partsId]
                );
            }

            // Shop-Angaben, Kanalzeilen aller HugoShops, Aufträge — eine Anweisung.
            // Abgewählte Kanäle liefert sie zurück, damit die Seite entfernt wird.
            $rows = $db->getAll(<<<SQL
                WITH ext AS (
                    INSERT INTO parts_ext (parts_id, hugoshop_category, hugoshop_hyperlink, hugoshop_breadcrumbs,
                                           hugoshop_technical_data, hugoshop_properties, hugoshop_images, hugoshop_downloads)
                    VALUES (:parts_id, :category, :slug, CAST(:breadcrumbs AS jsonb),
                            CAST(:technical AS jsonb), CAST(:properties AS jsonb), '[]'::jsonb, '{}'::jsonb)
                    ON CONFLICT (parts_id) DO UPDATE SET
                        hugoshop_category       = EXCLUDED.hugoshop_category,
                        hugoshop_breadcrumbs    = EXCLUDED.hugoshop_breadcrumbs,
                        hugoshop_technical_data = EXCLUDED.hugoshop_technical_data,
                        hugoshop_properties     = parts_ext.hugoshop_properties || EXCLUDED.hugoshop_properties,
                        hugoshop_hyperlink      = COALESCE(NULLIF(parts_ext.hugoshop_hyperlink, ''), EXCLUDED.hugoshop_hyperlink)
                    RETURNING parts_id, hugoshop_hyperlink
                ),
                vorher AS (
                    SELECT channel_id, active FROM parts_channel_shop WHERE parts_id = :parts_id_vorher
                ),
                ch AS (
                    INSERT INTO parts_channel_shop (parts_id, channel_id, active)
                    SELECT ext.parts_id, c.id, :wanted
                    FROM ext, sales_channel_shop c
                    WHERE c.type = 'hugoshop'
                    ON CONFLICT (parts_id, channel_id) DO UPDATE SET active = EXCLUDED.active, mtime = now()
                    RETURNING channel_id, active
                )
                SELECT ch.channel_id, ch.active, c.active AS channel_active, ext.hugoshop_hyperlink,
                       COALESCE(v.active, false) AS was_active
                FROM ch
                JOIN sales_channel_shop c ON c.id = ch.channel_id
                CROSS JOIN ext
                LEFT JOIN vorher v ON v.channel_id = ch.channel_id
            SQL, [
                ':parts_id'        => $partsId,
                ':parts_id_vorher' => $partsId,
                ':category'        => $offer['category'],
                ':slug'            => $offer['slug'],
                ':breadcrumbs'     => json_encode([$offer['category']]),
                ':technical'       => json_encode($tech, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE),
                ':properties'      => json_encode($offer['props'], JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE),
                ':wanted'          => $offer['wanted'],
            ]);

            $partnumber = $db->getOne("SELECT partnumber FROM parts WHERE id = :id", [':id' => $partsId])['partnumber'];
            foreach ($rows ?: [] as $r) {
                if (!filter_var($r['channel_active'], FILTER_VALIDATE_BOOLEAN)) continue;
                if (filter_var($r['active'], FILTER_VALIDATE_BOOLEAN)) {
                    if (shopQueueJob($db, 'publish_part', $partnumber, null, intval($r['channel_id']))) $result['jobs']++;
                } elseif (filter_var($r['was_active'], FILTER_VALIDATE_BOOLEAN)) {
                    // Seite entfernen: Dateiname wie beim Schreiben (shopPageFileName)
                    $datei = shopPageFileName(['shop' => ['hyperlink' => (string)$r['hugoshop_hyperlink']],
                                               'artikel' => ['partnumber' => $partnumber]]);
                    if (shopQueueJob($db, 'remove_part', $partnumber, $datei, intval($r['channel_id']))) $result['jobs']++;
                }
            }
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError('API_DATABASE_ERROR', $e->getMessage());
    }

    $result['synced'] = true;
    return $result;
}

/**
 * Adresse der Produktseite aus der Bezeichnung: Kleinbuchstaben, Umlaute
 * aufgelöst, alles andere zu Bindestrichen
 */
function _stSlug(string $text): string {
    $text = mb_strtolower(trim($text));
    $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim(mb_substr($text, 0, 80), '-') ?: 'werkzeug';
}

// ── KI-Zuordnung ─────────────────────────────────────────────────────────────

/**
 * KI-Analyse: Für welche Fahrzeuge passt dieses Werkzeug?
 *
 * Die KI bekommt Werkzeugdaten und ein Profil der eigenen Flotte (Hersteller
 * mit HSN, Motorcodes mit Hubraum/Leistung/Kraftstoff/Baujahren, Modell-
 * familien) und antwortet mit Zuordnungsregeln. Bestehende KI-Regeln werden
 * ersetzt, manuelle Regeln und feste Zuordnungen bleiben unberührt.
 *
 * @param int    $data['id']       Werkzeug-ID
 * @param string $data['ai_model'] Modellwahl des Benutzers, gilt nur für diesen Aufruf (optional)
 * @testdata {"id": 1, "ai_model": "claude-opus-5"}
 */
function analyzeSpecialTool($data) {
    set_time_limit(180);
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) throw new ApiError('VALIDATION_ERROR', 'id erforderlich');

    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key IN ('anthropic_api_key', '" . aiModelConfigKey('special_tools') . "')"
    );
    $apiKey = trim($config['anthropic_api_key'] ?? '');
    if ($apiKey === '') {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key ist nicht konfiguriert (Firmeneinstellungen → KI)');
    }
    $model = resolveAiModel($config, 'special_tools', $data['ai_model'] ?? null);

    // Werkzeug, bestehende manuelle Regeln und Flottenprofil in einer Abfrage
    $query = <<<SQL
        WITH p AS (SELECT * FROM special_tool_vehicle_profiles_lxcars)
        SELECT json_build_object(
            'tool', (SELECT row_to_json(t) FROM (
                        SELECT id, name, tool_number, manufacturer, category, description, ai_hint
                        FROM special_tools_lxcars WHERE id = :id) t),
            'manual_rules', COALESCE((
                SELECT json_agg(json_build_object('label', r.label, 'mode', r.mode, 'criteria', r.criteria))
                FROM special_tool_rules_lxcars r WHERE r.tool_id = :id AND r.source = 'manual'
            ), '[]'::json),
            'makes', COALESCE((
                SELECT json_agg(json_build_object('hsn', x.hsn, 'make', x.make, 'n', x.n) ORDER BY x.n DESC)
                FROM (SELECT hsn, make, COUNT(*) AS n FROM p
                      WHERE make IS NOT NULL AND vehicle_type IN ('car', 'truck')
                      GROUP BY hsn, make ORDER BY n DESC LIMIT 80) x
            ), '[]'::json),
            'engines', COALESCE((
                SELECT json_agg(json_build_object(
                    'code', x.engine_code, 'make', x.make, 'model', x.model, 'fuel', x.fuel,
                    'ccm', x.ccm, 'kw', x.kw, 'years', x.y_from || '-' || x.y_to, 'n', x.n
                ) ORDER BY x.n DESC)
                FROM (SELECT engine_code, MIN(make) AS make, MIN(model) AS model, MIN(fuel) AS fuel,
                             MIN(ccm) AS ccm, MIN(kw) AS kw, MIN(year) AS y_from, MAX(year) AS y_to, COUNT(*) AS n
                      FROM p WHERE engine_code IS NOT NULL
                      GROUP BY engine_code ORDER BY n DESC LIMIT 250) x
            ), '[]'::json),
            'families', COALESCE((
                SELECT json_agg(json_build_object(
                    'make', x.make, 'model', x.model, 'fuel', x.fuel, 'ccm', x.ccm, 'kw', x.kw,
                    'years', x.y_from || '-' || x.y_to, 'n', x.n
                ) ORDER BY x.n DESC)
                FROM (SELECT make, model, fuel, ccm, kw, MIN(year) AS y_from, MAX(year) AS y_to, COUNT(*) AS n
                      FROM p WHERE make IS NOT NULL AND vehicle_type IN ('car', 'truck')
                      GROUP BY make, model, fuel, ccm, kw ORDER BY n DESC LIMIT 300) x
            ), '[]'::json),
            'fuels', COALESCE((
                SELECT json_agg(json_build_object('fuel', x.fuel, 'n', x.n) ORDER BY x.n DESC)
                FROM (SELECT fuel, COUNT(*) AS n FROM p WHERE fuel IS NOT NULL GROUP BY fuel) x
            ), '[]'::json),
            'vehicle_types', COALESCE((
                SELECT json_agg(json_build_object('type', x.vehicle_type, 'n', x.n) ORDER BY x.n DESC)
                FROM (SELECT vehicle_type, COUNT(*) AS n FROM p WHERE vehicle_type IS NOT NULL GROUP BY vehicle_type) x
            ), '[]'::json)
        ) AS result
    SQL;

    $row  = $db->getOne($query, [':id' => $id]);
    $ctx  = json_decode($row['result'] ?? '{}', true);
    if (empty($ctx['tool'])) throw new ApiError('DATA_NOT_FOUND', 'Werkzeug nicht gefunden');

    $answer = _stAskClaude($apiKey, $model, $ctx);

    // Regeln bereinigen
    $rules = [];
    foreach ($answer['rules'] ?? [] as $i => $r) {
        $label = trim((string)($r['label'] ?? ''));
        if ($label === '') continue;
        $rules[] = [
            'label'      => mb_substr($label, 0, 120),
            'criteria'   => _stSanitizeCriteria(is_array($r['criteria'] ?? null) ? $r['criteria'] : []),
            'reason'     => _stNullIfEmpty($r['reason'] ?? null),
            'mode'       => ($r['mode'] ?? 'include') === 'exclude' ? 'exclude' : 'include',
            'confidence' => isset($r['confidence']) ? max(0, min(1, round(floatval($r['confidence']), 2))) : null,
            'ord'        => $i,
        ];
    }

    $summary  = _stNullIfEmpty($answer['summary'] ?? null);
    $category = _stNullIfEmpty($answer['category'] ?? null);

    // Alles in einer Transaktion: KI-Regeln ersetzen, Einschätzung speichern
    $db->beginTransaction();
    try {
        $db->execute(
            "DELETE FROM special_tool_rules_lxcars WHERE tool_id = :id AND source = 'ai'",
            [':id' => $id]
        );
        if ($rules) {
            $db->execute(
                "INSERT INTO special_tool_rules_lxcars (tool_id, label, criteria, reason, mode, source, confidence, sort_order)
                 SELECT :id, x.label, COALESCE(x.criteria, '{}'::jsonb), x.reason, x.mode, 'ai', x.confidence, x.ord
                 FROM jsonb_to_recordset(CAST(:rules AS jsonb))
                      AS x(label text, criteria jsonb, reason text, mode text, confidence numeric, ord integer)",
                [':id' => $id, ':rules' => json_encode($rules, JSON_UNESCAPED_UNICODE)]
            );
        }
        $db->execute(
            "UPDATE special_tools_lxcars SET
                ai_summary = :summary,
                category   = COALESCE(category, :category),
                ai_model   = :model,
                ai_analyzed_at = now(),
                updated_at = now()
             WHERE id = :id",
            [':summary' => $summary, ':category' => $category, ':model' => $model, ':id' => $id]
        );
        $db->execute("SELECT special_tool_refresh_matches_lxcars(:id, NULL)", [':id' => $id]);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError('API_DATABASE_ERROR', $e->getMessage());
    }
    // Shop-Artikel bekommen die neue Fahrzeugliste auf ihre Seite
    _stSyncShopArticles($db, $id);

    $detail = _stToolDetail($db, $id);
    $detail['warnings'] = array_values(array_filter(array_map('strval', $answer['warnings'] ?? [])));
    resultInfo(true, '', ['results' => $detail]);
}

/**
 * Stellt der KI die Zuordnungsfrage und liefert die geparste JSON-Antwort
 *
 * @param string $apiKey Anthropic API-Key
 * @param string $model  Modell-ID
 * @param array  $ctx    Werkzeug, manuelle Regeln und Flottenprofil
 * @return array         summary, category, rules[], warnings[]
 */
function _stAskClaude($apiKey, $model, $ctx) {
    $tool = $ctx['tool'];

    $systemPrompt = <<<PROMPT
Du bist ein erfahrener Kfz-Werkstattmeister mit sehr gutem Wissen über Motorbaureihen, Motorkennbuchstaben und Konzernverbünde (VW-Konzern, PSA/Stellantis, Renault-Nissan, BMW/Mini, Daimler, Ford, GM/Opel, Fiat, Toyota, Hyundai/Kia usw.).

Aufgabe: Für ein Spezialwerkzeug festlegen, zu welchen Fahrzeugen es passt. Du antwortest mit Zuordnungsregeln, die eine Werkstattsoftware automatisch gegen den Fahrzeugbestand prüft.

REGELAUFBAU
- Jede Regel hat "label" (kurz, prägnant), "reason" (1–2 Sätze, warum), "confidence" (0–1), "mode" und "criteria".
- "mode": "include" (Standard) nimmt Fahrzeuge auf, "exclude" zieht Fahrzeuge wieder ab, die eine include-Regel getroffen hat. Nutze exclude-Regeln, um breite include-Regeln präzise zu machen — z. B. "alle Diesel ab 2003" minus "VW-Konzern Pumpe-Düse 1999–2010".
- Innerhalb einer Regel gelten alle gesetzten Kriterien ZUGLEICH (UND). Listen sind Alternativen (ODER). Mehrere include-Regeln sind Alternativen.
- Erlaubte Kriterien:
  "makes": Herstellernamen, wie sie im Bestand vorkommen (siehe Liste HERSTELLER) — Wortgrenzen-Suche im Fahrzeugtext. Nenne alle Schreibweisen, die im Bestand vorkommen (z. B. "VOLKSWAGEN", "VW").
  "hsn": KBA-Herstellerschlüsselnummern (4-stellig). Hersteller gelten als getroffen, wenn makes ODER hsn passt.
  "models": Modellnamen (Wortgrenzen-Suche, z. B. "Golf", "A3", "Octavia"). Nur setzen, wenn es wirklich auf das Modell ankommt.
  "engine_codes": Motorkennbuchstaben/Motorcodes. Teilstring-Suche ohne Leer-/Sonderzeichen, "*" als Platzhalter (z. B. "CAY*"). Nenne ALLE Codes der Motorfamilie, auch solche, die im Bestand nicht vorkommen — Fahrzeuge kommen später hinzu.
  "fuel": Kraftstoffgruppen aus KRAFTSTOFFE (z. B. "Diesel", "Benzin"). Hybride zählen zur Gruppe ihres Verbrennungsmotors.
  "vehicle_types": aus FAHRZEUGARTEN (car, truck, bike, trailer, tractor). Werkzeuge für Pkw-Motoren: ["car","truck"] (Transporter laufen oft als truck).
  "ccm_from"/"ccm_to": Hubraum in ccm. "kw_from"/"kw_to": Leistung in kW. "year_from"/"year_to": Erstzulassungsjahr.
- Im Bestand fehlt bei vielen Fahrzeugen der Motorkennbuchstabe. Verlasse dich deshalb NICHT allein auf engine_codes: Lege zusätzlich eine Regel über Hersteller/HSN + Kraftstoff + Hubraumbereich + Baujahrbereich (+ ggf. kW) an, die dieselbe Motorfamilie ohne Motorcode trifft. Halte diese Regel so eng, dass sie keine fremden Motoren einschließt.
- Gleiche oder baugleiche Motoren anderer Marken einbeziehen (Konzernmotoren, Kooperationen wie PSA/Ford-Diesel, Renault/Nissan/Mercedes, Fiat/Opel/Suzuki, Toyota/PSA 1.0, BMW/PSA Prince, VW/Audi/Seat/Skoda).
- Universelle Werkzeuge (z. B. Injektor-Auszieher für Common Rail, Federspanner, Glühkerzen-Ausbohrsatz) über Kraftstoff, Baujahr und Fahrzeugart beschreiben — nicht über Hersteller. Common Rail: Diesel-Pkw ab ca. 1998–2003 je nach Hersteller, Pumpe-Düse (VW 1999–2010) ist KEIN Common Rail.
- Passt das Werkzeug zu keinem Fahrzeug im Bestand, trotzdem korrekte Regeln liefern.
- Zu wenig Information im Werkzeugnamen? Dann die bestmögliche Einschätzung treffen und in "warnings" sagen, was unklar ist.

ANTWORT
Ausschließlich valides JSON, kein Markdown:
{"summary": "2–4 Sätze: wofür das Werkzeug ist und für welche Motoren/Fahrzeuge es passt", "category": "kurze Kategorie, z. B. Zahnriemen, Steuerkette, Injektoren, Glühkerzen, Fahrwerk, Getriebe, Klima, Elektrik, Karosserie", "rules": [{"label": "...", "reason": "...", "confidence": 0.9, "mode": "include", "criteria": {...}}], "warnings": []}
PROMPT;

    $toolLines = ["Bezeichnung: {$tool['name']}"];
    if (!empty($tool['tool_number']))  $toolLines[] = "Werkzeugnummer: {$tool['tool_number']}";
    if (!empty($tool['manufacturer'])) $toolLines[] = "Werkzeughersteller: {$tool['manufacturer']}";
    if (!empty($tool['category']))     $toolLines[] = "Kategorie: {$tool['category']}";
    if (!empty($tool['description']))  $toolLines[] = "Beschreibung: {$tool['description']}";
    if (!empty($tool['ai_hint']))      $toolLines[] = "Hinweis des Mitarbeiters: {$tool['ai_hint']}";

    $manual = '';
    if (!empty($ctx['manual_rules'])) {
        $manual = "\nBEREITS MANUELL FESTGELEGTE REGELN (bleiben bestehen, nicht wiederholen):\n"
                . json_encode($ctx['manual_rules'], JSON_UNESCAPED_UNICODE);
    }

    $compact = fn($rows) => implode("\n", array_map(fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE), $rows ?: []));

    $userMessage = "WERKZEUG:\n" . implode("\n", $toolLines) . "\n" . $manual
        . "\n\nFAHRZEUGARTEN im Bestand:\n" . $compact($ctx['vehicle_types'])
        . "\n\nKRAFTSTOFFE im Bestand:\n" . $compact($ctx['fuels'])
        . "\n\nHERSTELLER im Bestand (hsn, Schreibweise, Anzahl):\n" . $compact($ctx['makes'])
        . "\n\nMOTORCODES im Bestand (code, Beispielfahrzeug, Kraftstoff, ccm, kW, Baujahre, Anzahl):\n" . $compact($ctx['engines'])
        . "\n\nMODELLFAMILIEN im Bestand (Marke, Modell, Kraftstoff, ccm, kW, Baujahre, Anzahl):\n" . $compact($ctx['families'])
        . "\n\nErstelle jetzt die Zuordnungsregeln für dieses Werkzeug.";

    $requestBody = json_encode([
        'model'      => $model,
        'max_tokens' => 6000,
        'system'     => $systemPrompt,
        'messages'   => [['role' => 'user', 'content' => $userMessage]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 150,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) throw new ApiError('CLAUDE_API_ERROR', 'cURL-Fehler: ' . $curlError);
    if ($httpCode !== 200) throw new ApiError('CLAUDE_API_ERROR', 'Claude API Fehler (HTTP ' . $httpCode . '): ' . $response);

    $text = '';
    foreach ((json_decode($response, true)['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    }
    $parsed = _stParseJson($text);
    if (!is_array($parsed) || !isset($parsed['rules'])) {
        writeLog('Spezialwerkzeug: unbrauchbare KI-Antwort: ' . mb_substr($text, 0, 500), true, DLOG_WRN);
        throw new ApiError('CLAUDE_API_ERROR', 'Die KI hat keine verwertbaren Regeln geliefert');
    }
    return $parsed;
}

/**
 * Holt das JSON-Objekt aus einer KI-Antwort, auch wenn Text oder Code-Zäune drumherum stehen
 *
 * @param string $text
 * @return array|null
 */
function _stParseJson($text) {
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $parsed = json_decode($text, true);
    if (is_array($parsed)) return $parsed;

    $start = strpos($text, '{');
    $end   = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    return json_decode(substr($text, $start, $end - $start + 1), true);
}

/**
 * Bereinigt Kriterien: nur bekannte Schlüssel, Listen aus Strings, Zahlen als int
 *
 * @param array $c
 * @return array
 */
function _stSanitizeCriteria(array $c) {
    $out = [];
    foreach (['makes', 'hsn', 'models', 'engine_codes', 'fuel', 'vehicle_types'] as $key) {
        $list = $c[$key] ?? null;
        if (is_string($list)) $list = preg_split('/[,;\n]+/', $list);
        if (!is_array($list)) continue;
        $vals = [];
        foreach ($list as $v) {
            if (is_array($v)) continue;
            $v = trim((string)$v);
            if ($v === '') continue;
            if ($key === 'hsn') $v = str_pad(preg_replace('/\D/', '', $v), 4, '0', STR_PAD_LEFT);
            if ($key === 'vehicle_types') $v = strtolower($v);
            $vals[] = mb_substr($v, 0, 60);
        }
        $vals = array_values(array_unique($vals));
        if ($vals) $out[$key] = $vals;
    }
    foreach (['ccm_from', 'ccm_to', 'kw_from', 'kw_to', 'year_from', 'year_to'] as $key) {
        if (!isset($c[$key]) || $c[$key] === '' || $c[$key] === null) continue;
        if (!is_numeric($c[$key])) continue;
        $n = intval($c[$key]);
        if ($n <= 0) continue;
        $out[$key] = $n;
    }
    return $out;
}

/**
 * Leerer String → NULL, sonst getrimmter Text
 */
function _stNullIfEmpty($v) {
    if ($v === null) return null;
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}
