<?php
// backend/api/warehouse/stocktaking.php
//
// Inventur als gefuehrter Zaehlvorgang.
//
// Die Zaehlergebnisse liegen in der kivitendo-Tabelle `stocktakings`
// (inventory_id NULL = noch nicht gebucht). `stocktaking_sessions` klammert
// eine Zaehlung nur organisatorisch und wird ueber (warehouse_id, cutoff_date)
// zugeordnet — dadurch bleibt `stocktakings` unveraendert kompatibel.

/**
 * Buchbestand eines Lagers zum Stichtag, aufgeschluesselt nach Platz und Charge.
 *
 * Als SQL-Fragment ausgelagert, damit Zaehlliste, Zusammenfassung und Buchung
 * garantiert denselben Bestand verwenden.
 */
function _st_bookStockSql() {
    return "SELECT i.parts_id, i.bin_id, i.chargenumber, SUM(i.qty) AS qty
            FROM inventory i
            WHERE i.warehouse_id = :warehouse_id
              AND i.shippingdate <= :cutoff_date
            GROUP BY i.parts_id, i.bin_id, i.chargenumber
            HAVING SUM(i.qty) <> 0";
}

/**
 * Alle Inventuren mit Fortschritt laden.
 *
 * @testdata {}
 */
function getStocktakingSessions($data) {
    $db = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_agg(x ORDER BY x.cutoff_date DESC, x.id DESC) AS result
        FROM (
            SELECT
                s.id, s.name, s.warehouse_id, s.cutoff_date, s.status, s.posted_at, s.itime,
                w.description AS warehouse,
                e.name        AS employee,
                COALESCE((
                    SELECT COUNT(*) FROM stocktakings st
                    WHERE st.warehouse_id = s.warehouse_id AND st.cutoff_date = s.cutoff_date
                ), 0) AS counted,
                COALESCE((
                    SELECT COUNT(*) FROM stocktakings st
                    WHERE st.warehouse_id = s.warehouse_id AND st.cutoff_date = s.cutoff_date
                      AND st.inventory_id IS NOT NULL
                ), 0) AS posted_rows,
                COALESCE((
                    SELECT COUNT(*) FROM (
                        SELECT i.parts_id, i.bin_id, i.chargenumber
                        FROM inventory i
                        WHERE i.warehouse_id = s.warehouse_id AND i.shippingdate <= s.cutoff_date
                        GROUP BY i.parts_id, i.bin_id, i.chargenumber
                        HAVING SUM(i.qty) <> 0
                    ) bs
                ), 0) AS expected
            FROM stocktaking_sessions s
            JOIN warehouse w     ON w.id = s.warehouse_id
            LEFT JOIN employee e ON e.id = s.employee_id
        ) x
    SQL;

    $row = $db->getOne($query);
    resultInfo(true, '', ['results' => json_decode($row['result'] ?? 'null', true) ?: []]);
}

/**
 * Neue Inventur anlegen.
 *
 * Je Lager und Stichtag gibt es genau eine Inventur — ein zweiter Aufruf mit
 * denselben Werten liefert die bestehende zurueck, statt eine Dublette zu
 * erzeugen.
 *
 * @param string $data['name']         Bezeichnung, z. B. "Jahresinventur 2026"
 * @param int    $data['warehouse_id'] Zu zaehlendes Lager
 * @param string $data['cutoff_date']  Stichtag (YYYY-MM-DD)
 * @param int    $data['employee_id']  Mitarbeiter aus dem Frontend-Store
 * @testdata {"name": "Jahresinventur", "warehouse_id": 1, "cutoff_date": "2026-12-31"}
 */
function createStocktakingSession($data) {
    $db = DbhCompany::begin();

    $name        = trim($data['name'] ?? '');
    $warehouseId = intval($data['warehouse_id'] ?? 0);
    $cutoff      = trim($data['cutoff_date'] ?? '') ?: date('Y-m-d');
    $employeeId  = _wh_employeeId($db, $data) ?: null;

    if ($warehouseId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Lager fehlt'); return; }
    if ($name === '')      { $name = 'Inventur '.date('d.m.Y', strtotime($cutoff)); }

    $row = $db->getOne(
        "INSERT INTO stocktaking_sessions (name, warehouse_id, cutoff_date, employee_id)
         VALUES (:name, :warehouse_id, :cutoff_date, :employee_id)
         ON CONFLICT (warehouse_id, cutoff_date) DO UPDATE SET mtime = NOW()
         RETURNING id, status",
        [':name' => $name, ':warehouse_id' => $warehouseId, ':cutoff_date' => $cutoff, ':employee_id' => $employeeId]
    );

    resultInfo(true, '', ['id' => intval($row['id']), 'status' => $row['status']]);
}

/**
 * Zaehlliste einer Inventur.
 *
 * Enthaelt jeden Lagerplatz mit Buchbestand plus alle bereits erfassten
 * Zaehlungen. Bei `blind` = true wird der Buchbestand bewusst NICHT
 * mitgeschickt: Wer die Sollmenge sieht, zaehlt sie erfahrungsgemaess ab.
 * Nach dem Erfassen zeigt die Zusammenfassung die Differenzen.
 *
 * @param int    $data['session_id'] Inventur-ID
 * @param bool   $data['blind']      true = Buchbestand nicht mitliefern (Standard true)
 * @param string $data['search']     Suche in Artikelnummer und Bezeichnung
 * @testdata {"session_id": 1, "blind": true, "search": ""}
 */
function getStocktakingList($data) {
    $db = DbhCompany::begin();

    $sessionId = intval($data['session_id'] ?? 0);
    $blind     = !isset($data['blind']) || !empty($data['blind']);
    $search    = trim($data['search'] ?? '');

    if ($sessionId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Inventur fehlt'); return; }

    $session = $db->getOne(
        "SELECT s.*, w.description AS warehouse
         FROM stocktaking_sessions s JOIN warehouse w ON w.id = s.warehouse_id
         WHERE s.id = :id",
        [':id' => $sessionId]
    );
    if (!$session) { resultInfo(false, 'NOT_FOUND', 'Inventur nicht gefunden'); return; }

    $query = <<<SQL
        WITH book AS (
            SELECT i.parts_id, i.bin_id, i.chargenumber, SUM(i.qty) AS qty
            FROM inventory i
            WHERE i.warehouse_id = :warehouse_id AND i.shippingdate <= :cutoff_date
            GROUP BY i.parts_id, i.bin_id, i.chargenumber
            HAVING SUM(i.qty) <> 0
        ),
        counted AS (
            SELECT st.parts_id, st.bin_id, st.chargenumber, st.qty, st.id, st.comment,
                   st.inventory_id IS NOT NULL AS posted
            FROM stocktakings st
            WHERE st.warehouse_id = :warehouse_id AND st.cutoff_date = :cutoff_date
        ),
        lines AS (
            SELECT
                COALESCE(bk.parts_id, ct.parts_id)         AS parts_id,
                COALESCE(bk.bin_id,   ct.bin_id)           AS bin_id,
                COALESCE(bk.chargenumber, ct.chargenumber) AS chargenumber,
                bk.qty                                      AS book_qty,
                ct.qty                                      AS counted_qty,
                ct.id                                       AS count_id,
                ct.comment                                  AS count_comment,
                COALESCE(ct.posted, false)                  AS posted
            FROM book bk
            FULL OUTER JOIN counted ct
              ON ct.parts_id = bk.parts_id
             AND ct.bin_id = bk.bin_id
             AND ct.chargenumber = bk.chargenumber
        )
        SELECT json_build_object(
            'total',   (SELECT COUNT(*) FROM lines),
            'counted', (SELECT COUNT(*) FROM lines WHERE counted_qty IS NOT NULL),
            'items', COALESCE((
                SELECT json_agg(json_build_object(
                    'parts_id',      l.parts_id,
                    'partnumber',    p.partnumber,
                    'description',   p.description,
                    'unit',          p.unit,
                    'ean',           p.ean,
                    'bin_id',        l.bin_id,
                    'bin',           b.description,
                    'chargenumber',  l.chargenumber,
                    'book_qty',      CASE WHEN :blind AND l.counted_qty IS NULL THEN NULL ELSE l.book_qty END,
                    'counted_qty',   l.counted_qty,
                    'count_id',      l.count_id,
                    'count_comment', l.count_comment,
                    'posted',        l.posted,
                    'diff',          CASE WHEN l.counted_qty IS NULL THEN NULL
                                          ELSE l.counted_qty - COALESCE(l.book_qty, 0) END
                ) ORDER BY p.partnumber, b.description, l.chargenumber)
                FROM lines l
                JOIN parts p ON p.id = l.parts_id
                JOIN bin b   ON b.id = l.bin_id
                WHERE :search = ''
                   OR p.partnumber  ILIKE :like
                   OR p.description ILIKE :like
                   OR p.ean         ILIKE :like
            ), '[]'::json)
        ) AS result
    SQL;

    $row = $db->getOne($query, [
        ':warehouse_id' => $session['warehouse_id'],
        ':cutoff_date'  => $session['cutoff_date'],
        ':blind'        => $blind,
        ':search'       => $search,
        ':like'         => '%'.$search.'%',
    ]);

    $result = json_decode($row['result'] ?? '{}', true);
    $result['session'] = $session;
    $result['blind']   = $blind;

    resultInfo(true, '', ['results' => $result]);
}

/**
 * Eine gezaehlte Menge erfassen oder korrigieren.
 *
 * Bereits gebuchte Zaehlungen sind gesperrt — eine Korrektur danach ist eine
 * normale Lagerbuchung, keine Inventurzeile.
 *
 * @param int    $data['session_id']   Inventur-ID
 * @param int    $data['parts_id']     Artikel
 * @param int    $data['bin_id']       Lagerplatz
 * @param string $data['chargenumber'] Charge (optional)
 * @param float  $data['qty']          Gezaehlte Menge
 * @param string $data['comment']      Bemerkung (optional)
 * @param int    $data['employee_id']  Mitarbeiter aus dem Frontend-Store
 * @testdata {"session_id": 1, "parts_id": 1, "bin_id": 1, "chargenumber": "", "qty": 12}
 */
function saveStocktakingCount($data) {
    $db = DbhCompany::begin();

    $sessionId = intval($data['session_id'] ?? 0);
    $partsId   = intval($data['parts_id'] ?? 0);
    $binId     = intval($data['bin_id'] ?? 0);
    $charge    = trim($data['chargenumber'] ?? '');
    $comment   = trim($data['comment'] ?? '') ?: null;

    if ($sessionId <= 0 || $partsId <= 0 || $binId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Inventur, Artikel und Lagerplatz sind Pflicht'); return;
    }
    if (!isset($data['qty']) || $data['qty'] === '') {
        resultInfo(false, 'VALIDATION_ERROR', 'Menge fehlt'); return;
    }
    $qty = round(floatval($data['qty']), 5);
    if ($qty < 0) { resultInfo(false, 'VALIDATION_ERROR', 'Menge darf nicht negativ sein'); return; }

    $session = $db->getOne("SELECT * FROM stocktaking_sessions WHERE id = :id", [':id' => $sessionId]);
    if (!$session)                     { resultInfo(false, 'NOT_FOUND', 'Inventur nicht gefunden'); return; }
    if ($session['status'] !== 'open') { resultInfo(false, 'SESSION_CLOSED', 'Die Inventur ist abgeschlossen'); return; }

    $employeeId = _wh_employeeId($db, $data);
    if ($employeeId <= 0) { resultInfo(false, 'NO_EMPLOYEE', 'Kein Mitarbeiter ermittelbar'); return; }

    $existing = $db->getOne(
        "SELECT id, inventory_id FROM stocktakings
         WHERE warehouse_id = :w AND cutoff_date = :c AND parts_id = :p AND bin_id = :b AND chargenumber = :ch",
        [':w' => $session['warehouse_id'], ':c' => $session['cutoff_date'],
         ':p' => $partsId, ':b' => $binId, ':ch' => $charge]
    );

    if ($existing && $existing['inventory_id'] !== null) {
        resultInfo(false, 'ALREADY_POSTED', 'Diese Zählung wurde bereits gebucht'); return;
    }

    if ($existing) {
        $row = $db->getOne(
            "UPDATE stocktakings SET qty = :qty, comment = :comment, employee_id = :e
             WHERE id = :id RETURNING id",
            [':qty' => $qty, ':comment' => $comment, ':e' => $employeeId, ':id' => $existing['id']]
        );
    } else {
        $row = $db->getOne(
            "INSERT INTO stocktakings
                (warehouse_id, bin_id, parts_id, employee_id, qty, comment, chargenumber, cutoff_date)
             VALUES (:w, :b, :p, :e, :qty, :comment, :ch, :c)
             RETURNING id",
            [':w' => $session['warehouse_id'], ':b' => $binId, ':p' => $partsId, ':e' => $employeeId,
             ':qty' => $qty, ':comment' => $comment, ':ch' => $charge, ':c' => $session['cutoff_date']]
        );
    }

    // Buchbestand zurueckgeben, damit die Oberflaeche die Differenz sofort
    // anzeigen kann — ohne dass sie vor der Eingabe sichtbar war.
    $book = $db->getOne(
        "SELECT COALESCE(SUM(qty), 0) AS qty FROM inventory
         WHERE warehouse_id = :w AND parts_id = :p AND bin_id = :b AND chargenumber = :ch
           AND shippingdate <= :c",
        [':w' => $session['warehouse_id'], ':p' => $partsId, ':b' => $binId,
         ':ch' => $charge, ':c' => $session['cutoff_date']]
    );

    resultInfo(true, '', [
        'id'       => intval($row['id']),
        'book_qty' => floatval($book['qty']),
        'diff'     => round($qty - floatval($book['qty']), 5),
    ]);
}

/**
 * Eine erfasste Zaehlung wieder entfernen (nur solange nicht gebucht).
 *
 * @param int $data['count_id'] ID der Zaehlzeile
 * @testdata {"count_id": 1}
 */
function deleteStocktakingCount($data) {
    $db = DbhCompany::begin();
    $countId = intval($data['count_id'] ?? 0);

    if ($countId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }

    $row = $db->getOne("SELECT inventory_id FROM stocktakings WHERE id = :id", [':id' => $countId]);
    if (!$row)                          { resultInfo(false, 'NOT_FOUND', 'Zählung nicht gefunden'); return; }
    if ($row['inventory_id'] !== null)  { resultInfo(false, 'ALREADY_POSTED', 'Bereits gebucht'); return; }

    $db->execute("DELETE FROM stocktakings WHERE id = :id", [':id' => $countId]);
    resultInfo(true, '', ['id' => $countId]);
}

/**
 * Zusammenfassung einer Inventur: alle Differenzen mit Mengen- und Wertwirkung.
 *
 * @param int $data['session_id'] Inventur-ID
 * @testdata {"session_id": 1}
 */
function getStocktakingSummary($data) {
    $db = DbhCompany::begin();
    $sessionId = intval($data['session_id'] ?? 0);

    if ($sessionId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Inventur fehlt'); return; }

    $session = $db->getOne(
        "SELECT s.*, w.description AS warehouse
         FROM stocktaking_sessions s JOIN warehouse w ON w.id = s.warehouse_id
         WHERE s.id = :id",
        [':id' => $sessionId]
    );
    if (!$session) { resultInfo(false, 'NOT_FOUND', 'Inventur nicht gefunden'); return; }

    $query = <<<SQL
        WITH diffs AS (
            SELECT
                st.id, st.parts_id, st.bin_id, st.chargenumber, st.qty AS counted_qty,
                st.inventory_id IS NOT NULL AS posted,
                COALESCE((
                    SELECT SUM(i.qty) FROM inventory i
                    WHERE i.warehouse_id = st.warehouse_id AND i.parts_id = st.parts_id
                      AND i.bin_id = st.bin_id AND i.chargenumber = st.chargenumber
                      AND i.shippingdate <= st.cutoff_date
                ), 0) AS book_qty
            FROM stocktakings st
            WHERE st.warehouse_id = :warehouse_id AND st.cutoff_date = :cutoff_date
        ),
        enriched AS (
            SELECT d.*, (d.counted_qty - d.book_qty) AS diff,
                   (d.counted_qty - d.book_qty) * COALESCE(p.lastcost, 0) AS diff_value,
                   p.partnumber, p.description, p.unit, COALESCE(p.lastcost, 0) AS lastcost,
                   b.description AS bin
            FROM diffs d
            JOIN parts p ON p.id = d.parts_id
            JOIN bin b   ON b.id = d.bin_id
        )
        SELECT json_build_object(
            'counted',      (SELECT COUNT(*) FROM enriched),
            'with_diff',    (SELECT COUNT(*) FROM enriched WHERE diff <> 0),
            'open',         (SELECT COUNT(*) FROM enriched WHERE diff <> 0 AND NOT posted),
            'diff_value',   (SELECT COALESCE(SUM(diff_value), 0) FROM enriched WHERE NOT posted),
            'surplus_qty',  (SELECT COALESCE(SUM(diff), 0) FROM enriched WHERE diff > 0),
            'shortage_qty', (SELECT COALESCE(SUM(diff), 0) FROM enriched WHERE diff < 0),
            'items', COALESCE((
                SELECT json_agg(x ORDER BY (x.diff = 0), ABS(x.diff_value) DESC, x.partnumber)
                FROM enriched x
            ), '[]'::json)
        ) AS result
    SQL;

    $row = $db->getOne($query, [
        ':warehouse_id' => $session['warehouse_id'],
        ':cutoff_date'  => $session['cutoff_date'],
    ]);

    $result = json_decode($row['result'] ?? '{}', true);
    $result['session'] = $session;

    resultInfo(true, '', ['results' => $result]);
}

/**
 * Inventurdifferenzen buchen und die Inventur abschliessen.
 *
 * Fuer jede Abweichung entsteht eine Lagerbuchung mit der Bewegungsart
 * "stocktaking"; die Zaehlzeile wird ueber inventory_id mit ihr verknuepft und
 * ist danach gesperrt. Zeilen ohne Abweichung erzeugen keine Buchung.
 *
 * @param int $data['session_id']  Inventur-ID
 * @param int $data['employee_id'] Mitarbeiter aus dem Frontend-Store
 * @testdata {"session_id": 1}
 */
function postStocktaking($data) {
    $db = DbhCompany::begin();
    $sessionId = intval($data['session_id'] ?? 0);

    if ($sessionId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Inventur fehlt'); return; }

    $session = $db->getOne("SELECT * FROM stocktaking_sessions WHERE id = :id", [':id' => $sessionId]);
    if (!$session)                     { resultInfo(false, 'NOT_FOUND', 'Inventur nicht gefunden'); return; }
    if ($session['status'] !== 'open') { resultInfo(false, 'SESSION_CLOSED', 'Die Inventur ist bereits abgeschlossen'); return; }

    $employeeId = _wh_employeeId($db, $data);
    if ($employeeId <= 0) { resultInfo(false, 'NO_EMPLOYEE', 'Kein Mitarbeiter ermittelbar'); return; }

    $typeIn  = $db->getOne("SELECT id FROM transfer_type WHERE direction = 'in'  AND description = 'stocktaking' LIMIT 1");
    $typeOut = $db->getOne("SELECT id FROM transfer_type WHERE direction = 'out' AND description = 'stocktaking' LIMIT 1");
    if (!$typeIn || !$typeOut) {
        resultInfo(false, 'NO_TRANSFER_TYPE', 'Bewegungsart "stocktaking" fehlt in der Datenbank'); return;
    }

    // Offene Zaehlzeilen samt Buchbestand — eine Abfrage, danach nur noch schreiben.
    $rows = $db->getAll(
        "SELECT st.id, st.parts_id, st.bin_id, st.chargenumber, st.qty AS counted_qty, st.bestbefore,
                COALESCE((
                    SELECT SUM(i.qty) FROM inventory i
                    WHERE i.warehouse_id = st.warehouse_id AND i.parts_id = st.parts_id
                      AND i.bin_id = st.bin_id AND i.chargenumber = st.chargenumber
                      AND i.shippingdate <= st.cutoff_date
                ), 0) AS book_qty
         FROM stocktakings st
         WHERE st.warehouse_id = :w AND st.cutoff_date = :c AND st.inventory_id IS NULL",
        [':w' => $session['warehouse_id'], ':c' => $session['cutoff_date']]
    );

    $booked = 0;
    $unchanged = 0;

    $db->beginTransaction();
    try {
        foreach ($rows as $r) {
            $diff = round(floatval($r['counted_qty']) - floatval($r['book_qty']), 5);
            if (abs($diff) < 0.00001) { $unchanged++; continue; }

            $transRow = $db->getOne("SELECT nextval('id') AS id");
            $inv = $db->getOne(
                "INSERT INTO inventory
                    (parts_id, warehouse_id, bin_id, qty, chargenumber, bestbefore,
                     comment, shippingdate, employee_id, trans_id, trans_type_id)
                 VALUES
                    (:parts_id, :warehouse_id, :bin_id, :qty, :chargenumber, :bestbefore,
                     :comment, :shippingdate, :employee_id, :trans_id, :trans_type_id)
                 RETURNING id",
                [
                    ':parts_id'      => $r['parts_id'],
                    ':warehouse_id'  => $session['warehouse_id'],
                    ':bin_id'        => $r['bin_id'],
                    ':qty'           => $diff,
                    ':chargenumber'  => $r['chargenumber'],
                    ':bestbefore'    => $r['bestbefore'],
                    ':comment'       => 'Inventur '.$session['name'],
                    ':shippingdate'  => $session['cutoff_date'],
                    ':employee_id'   => $employeeId,
                    ':trans_id'      => intval($transRow['id']),
                    ':trans_type_id' => $diff > 0 ? $typeIn['id'] : $typeOut['id'],
                ]
            );

            $db->execute(
                "UPDATE stocktakings SET inventory_id = :inv WHERE id = :id",
                [':inv' => $inv['id'], ':id' => $r['id']]
            );
            $booked++;
        }

        $db->execute(
            "UPDATE stocktaking_sessions SET status = 'posted', posted_at = NOW(), mtime = NOW()
             WHERE id = :id",
            [':id' => $sessionId]
        );

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    resultInfo(true, '', ['booked' => $booked, 'unchanged' => $unchanged]);
}

/**
 * Eine Inventur verwerfen: Zaehlzeilen loeschen, Sitzung als abgebrochen markieren.
 *
 * Bereits gebuchte Differenzen bleiben bestehen — sie sind echte
 * Lagerbewegungen und werden nicht stillschweigend entfernt.
 *
 * @param int $data['session_id'] Inventur-ID
 * @testdata {"session_id": 1}
 */
function cancelStocktakingSession($data) {
    $db = DbhCompany::begin();
    $sessionId = intval($data['session_id'] ?? 0);

    if ($sessionId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Inventur fehlt'); return; }

    $session = $db->getOne("SELECT * FROM stocktaking_sessions WHERE id = :id", [':id' => $sessionId]);
    if (!$session) { resultInfo(false, 'NOT_FOUND', 'Inventur nicht gefunden'); return; }

    $db->beginTransaction();
    try {
        $db->execute(
            "DELETE FROM stocktakings
             WHERE warehouse_id = :w AND cutoff_date = :c AND inventory_id IS NULL",
            [':w' => $session['warehouse_id'], ':c' => $session['cutoff_date']]
        );
        $db->execute(
            "UPDATE stocktaking_sessions SET status = 'cancelled', mtime = NOW() WHERE id = :id",
            [':id' => $sessionId]
        );
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    resultInfo(true, '', ['id' => $sessionId]);
}
