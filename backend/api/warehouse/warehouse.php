<?php
// backend/api/warehouse/warehouse.php
//
// Lager und Lagerplaetze. Arbeitet direkt auf den kivitendo-Tabellen
// warehouse / bin / inventory — es gibt keine Parallelhaltung. Der Bestand
// eines Artikels ergibt sich immer aus der Summe seiner inventory-Zeilen;
// parts.onhand haelt der kivitendo-Trigger `trig_update_onhand` synchron.

/**
 * Lager-Cockpit: Kennzahlen, Lager mit Plaetzen und die letzten Bewegungen.
 *
 * Ein Aufruf, eine Abfrage — alles wird in der Datenbank zusammengesetzt und
 * als fertiges JSON zurueckgegeben.
 *
 * @param int $data['dead_days'] Ab wie vielen Tagen ohne Bewegung ein Artikel als Ladenhueter gilt (Standard 180)
 * @testdata {"dead_days": 180}
 */
function getWarehouseOverview($data) {
    $db = DbhCompany::begin();
    $deadDays = max(1, intval($data['dead_days'] ?? 180));

    $query = <<<SQL
        WITH stock AS (
            SELECT i.parts_id, i.warehouse_id, i.bin_id, SUM(i.qty) AS qty, MAX(i.itime) AS last_move
            FROM inventory i
            GROUP BY i.parts_id, i.warehouse_id, i.bin_id
            HAVING SUM(i.qty) <> 0
        ),
        per_part AS (
            SELECT s.parts_id, SUM(s.qty) AS qty, MAX(s.last_move) AS last_move
            FROM stock s GROUP BY s.parts_id
        ),
        kpi AS (
            SELECT
                COUNT(*)                                                        AS parts_in_stock,
                COALESCE(SUM(pp.qty), 0)                                        AS total_qty,
                COALESCE(SUM(pp.qty * COALESCE(p.lastcost, 0)), 0)              AS stock_value,
                COUNT(*) FILTER (
                    WHERE COALESCE(p.rop, 0) > 0 AND pp.qty <= p.rop
                )                                                               AS below_rop,
                COUNT(*) FILTER (
                    WHERE pp.last_move < NOW() - (:dead_days || ' days')::interval
                )                                                               AS dead_stock
            FROM per_part pp
            JOIN parts p ON p.id = pp.parts_id
        ),
        -- Artikel, bei denen das kivitendo-Schnellfeld parts.onhand nicht zur
        -- Summe der Lagerbewegungen passt (typisch nach Datenimporten).
        drift AS (
            SELECT COUNT(*) AS n
            FROM parts p
            LEFT JOIN per_part pp ON pp.parts_id = p.id
            WHERE COALESCE(p.onhand, 0) IS DISTINCT FROM COALESCE(pp.qty, 0)
        ),
        warehouses AS (
            SELECT
                w.id, w.description, COALESCE(w.invalid, false) AS invalid, w.sortkey,
                COALESCE((
                    SELECT json_agg(b ORDER BY b.description)
                    FROM (
                        SELECT
                            bn.id, bn.description,
                            COALESCE((SELECT COUNT(DISTINCT s.parts_id) FROM stock s WHERE s.bin_id = bn.id), 0) AS parts_count,
                            COALESCE((SELECT SUM(s.qty) FROM stock s WHERE s.bin_id = bn.id), 0)                 AS qty
                        FROM bin bn WHERE bn.warehouse_id = w.id
                    ) b
                ), '[]'::json) AS bins,
                COALESCE((SELECT COUNT(DISTINCT s.parts_id) FROM stock s WHERE s.warehouse_id = w.id), 0) AS parts_count,
                COALESCE((SELECT SUM(s.qty) FROM stock s WHERE s.warehouse_id = w.id), 0)                 AS qty
            FROM warehouse w
        ),
        moves AS (
            SELECT
                i.id, i.qty, i.itime, i.comment, i.chargenumber,
                p.partnumber, p.description AS part_description, p.unit,
                w.description AS warehouse, b.description AS bin,
                tt.direction, tt.description AS transfer_type,
                e.name AS employee
            FROM inventory i
            JOIN parts p          ON p.id  = i.parts_id
            JOIN warehouse w      ON w.id  = i.warehouse_id
            JOIN bin b            ON b.id  = i.bin_id
            JOIN transfer_type tt ON tt.id = i.trans_type_id
            LEFT JOIN employee e  ON e.id  = i.employee_id
            ORDER BY i.itime DESC, i.id DESC
            LIMIT 15
        )
        SELECT json_build_object(
            'kpi',        COALESCE((SELECT row_to_json(k) FROM kpi k), '{}'::json),
            'drift',      COALESCE((SELECT n FROM drift), 0),
            'warehouses', COALESCE((SELECT json_agg(x ORDER BY x.sortkey NULLS LAST, x.description) FROM warehouses x), '[]'::json),
            'moves',      COALESCE((SELECT json_agg(m) FROM moves m), '[]'::json),
            'dead_days',  :dead_days::int
        ) AS result
    SQL;

    $row = $db->getOne($query, [':dead_days' => $deadDays]);
    resultInfo(true, '', ['results' => json_decode($row['result'] ?? '{}', true)]);
}

/**
 * Lager anlegen oder aendern (Upsert ueber die ID)
 *
 * @param int    $data['id']          Lager-ID (0/leer = neu anlegen)
 * @param string $data['description'] Bezeichnung des Lagers
 * @param int    $data['sortkey']     Sortierung (optional)
 * @param bool   $data['invalid']     true = ausser Betrieb
 * @testdata {"description": "Hauptlager", "sortkey": 1}
 */
function saveWarehouse($data) {
    $db = DbhCompany::begin();

    $id          = intval($data['id'] ?? 0);
    $description = trim($data['description'] ?? '');
    $invalid     = !empty($data['invalid']);
    $sortkey     = isset($data['sortkey']) && $data['sortkey'] !== '' ? intval($data['sortkey']) : null;

    if ($description === '') {
        resultInfo(false, 'VALIDATION_ERROR', 'Bezeichnung fehlt');
        return;
    }

    if ($id > 0) {
        $row = $db->getOne(
            "UPDATE warehouse SET description = :description, invalid = :invalid, sortkey = :sortkey
             WHERE id = :id RETURNING id",
            [':description' => $description, ':invalid' => $invalid, ':sortkey' => $sortkey, ':id' => $id]
        );
        if (!$row) { resultInfo(false, 'NOT_FOUND', 'Lager nicht gefunden'); return; }
    } else {
        $row = $db->getOne(
            "INSERT INTO warehouse (description, invalid, sortkey)
             VALUES (:description, :invalid, COALESCE(:sortkey, (SELECT COALESCE(MAX(sortkey), 0) + 1 FROM warehouse)))
             RETURNING id",
            [':description' => $description, ':invalid' => $invalid, ':sortkey' => $sortkey]
        );
    }

    resultInfo(true, '', ['id' => intval($row['id'])]);
}

/**
 * Lagerplatz anlegen oder aendern (Upsert ueber die ID)
 *
 * @param int    $data['id']           Lagerplatz-ID (0/leer = neu anlegen)
 * @param int    $data['warehouse_id'] Lager, zu dem der Platz gehoert
 * @param string $data['description']  Bezeichnung des Platzes
 * @testdata {"warehouse_id": 1, "description": "Regal A1"}
 */
function saveBin($data) {
    $db = DbhCompany::begin();

    $id          = intval($data['id'] ?? 0);
    $warehouseId = intval($data['warehouse_id'] ?? 0);
    $description = trim($data['description'] ?? '');

    if ($description === '') { resultInfo(false, 'VALIDATION_ERROR', 'Bezeichnung fehlt'); return; }

    if ($id > 0) {
        $row = $db->getOne(
            "UPDATE bin SET description = :description WHERE id = :id RETURNING id",
            [':description' => $description, ':id' => $id]
        );
        if (!$row) { resultInfo(false, 'NOT_FOUND', 'Lagerplatz nicht gefunden'); return; }
    } else {
        if ($warehouseId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Lager fehlt'); return; }
        $row = $db->getOne(
            "INSERT INTO bin (warehouse_id, description) VALUES (:warehouse_id, :description) RETURNING id",
            [':warehouse_id' => $warehouseId, ':description' => $description]
        );
    }

    resultInfo(true, '', ['id' => intval($row['id'])]);
}

/**
 * Lagerplatz loeschen — nur wenn dort nie etwas gelagert wurde.
 *
 * Ein Platz mit Bewegungshistorie bleibt bestehen, sonst waere die Historie
 * nicht mehr nachvollziehbar.
 *
 * @param int $data['id'] Lagerplatz-ID
 * @testdata {"id": 1}
 */
function deleteBin($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }

    $used = $db->getOne(
        "SELECT
            (SELECT COUNT(*) FROM inventory    WHERE bin_id = :id) AS moves,
            (SELECT COUNT(*) FROM parts        WHERE bin_id = :id) AS parts,
            (SELECT COUNT(*) FROM stocktakings WHERE bin_id = :id) AS counts",
        [':id' => $id]
    );

    if (intval($used['moves']) > 0 || intval($used['counts']) > 0) {
        resultInfo(false, 'BIN_IN_USE', 'Der Lagerplatz hat bereits Bewegungen und kann nicht gelöscht werden.');
        return;
    }
    if (intval($used['parts']) > 0) {
        resultInfo(false, 'BIN_IS_DEFAULT', 'Der Lagerplatz ist bei Artikeln als Standardplatz hinterlegt.');
        return;
    }

    $db->execute("DELETE FROM bin WHERE id = :id", [':id' => $id]);
    resultInfo(true, '', ['id' => $id]);
}

/**
 * Erstes Lager samt Standard-Lagerplatz in einem Schritt anlegen.
 *
 * Einstiegshilfe fuer Betriebe ohne eingerichtetes Lager: statt zwei Masken
 * genuegt ein Klick. Setzt das neue Lager gleich als Vorgabe in defaults,
 * damit Lieferscheine und Inventur sofort funktionieren.
 *
 * @param string $data['warehouse'] Name des Lagers (Standard "Hauptlager")
 * @param string $data['bin']       Name des Lagerplatzes (Standard "Standard")
 * @testdata {"warehouse": "Hauptlager", "bin": "Standard"}
 */
function createDefaultWarehouse($data) {
    $db = DbhCompany::begin();

    $warehouseName = trim($data['warehouse'] ?? '') ?: 'Hauptlager';
    $binName       = trim($data['bin'] ?? '') ?: 'Standard';

    $existing = $db->getOne("SELECT COUNT(*) AS n FROM warehouse");
    if (intval($existing['n']) > 0) {
        resultInfo(false, 'WAREHOUSE_EXISTS', 'Es ist bereits ein Lager vorhanden.');
        return;
    }

    $db->beginTransaction();
    try {
        $wh = $db->getOne(
            "INSERT INTO warehouse (description, sortkey, invalid) VALUES (:d, 1, false) RETURNING id",
            [':d' => $warehouseName]
        );
        $bin = $db->getOne(
            "INSERT INTO bin (warehouse_id, description) VALUES (:w, :d) RETURNING id",
            [':w' => $wh['id'], ':d' => $binName]
        );
        // Vorgaben setzen, damit Lieferscheine und Inventur ohne weitere
        // Konfiguration buchen koennen.
        $db->execute(
            "UPDATE defaults SET
                warehouse_id             = COALESCE(warehouse_id, :w),
                bin_id                   = COALESCE(bin_id, :b),
                stocktaking_warehouse_id = COALESCE(stocktaking_warehouse_id, :w),
                stocktaking_bin_id       = COALESCE(stocktaking_bin_id, :b)",
            [':w' => $wh['id'], ':b' => $bin['id']]
        );
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    resultInfo(true, '', ['warehouse_id' => intval($wh['id']), 'bin_id' => intval($bin['id'])]);
}

/**
 * Auswahllisten fuer die Buchungsdialoge: Lager mit Plaetzen und Bewegungsarten.
 *
 * @testdata {}
 */
function getWarehouseOptions($data) {
    $db = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_build_object(
            'warehouses', COALESCE((
                SELECT json_agg(x ORDER BY x.sortkey NULLS LAST, x.description)
                FROM (
                    SELECT w.id, w.description, w.sortkey,
                           COALESCE((
                               SELECT json_agg(json_build_object('id', b.id, 'description', b.description)
                                               ORDER BY b.description)
                               FROM bin b WHERE b.warehouse_id = w.id
                           ), '[]'::json) AS bins
                    FROM warehouse w
                    WHERE COALESCE(w.invalid, false) = false
                ) x
            ), '[]'::json),
            'transfer_types', COALESCE((
                SELECT json_agg(json_build_object('id', t.id, 'direction', t.direction, 'description', t.description)
                                ORDER BY t.sortkey, t.id)
                FROM transfer_type t
            ), '[]'::json),
            'defaults', COALESCE((
                SELECT row_to_json(d) FROM (
                    SELECT warehouse_id, bin_id,
                           COALESCE(undo_transfer_interval, 0) AS undo_transfer_interval,
                           COALESCE(show_bestbefore, false)    AS show_bestbefore
                    FROM defaults LIMIT 1
                ) d
            ), '{}'::json)
        ) AS result
    SQL;

    $row = $db->getOne($query);
    resultInfo(true, '', ['results' => json_decode($row['result'] ?? '{}', true)]);
}
