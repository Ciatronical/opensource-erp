<?php
// backend/api/warehouse/journal.php
//
// Bewegungsjournal. Eine Zeile je inventory-Eintrag; zusammengehoerende
// Umlagerungen tragen dieselbe Vorgangsnummer (trans_id) und werden in der
// Oberflaeche darueber gruppiert.

/**
 * Lagerbewegungen mit Filtern laden.
 *
 * @param string $data['date_from']    Von-Datum (YYYY-MM-DD, optional)
 * @param string $data['date_to']      Bis-Datum (YYYY-MM-DD, optional)
 * @param int    $data['warehouse_id'] Nur dieses Lager (0 = alle)
 * @param int    $data['parts_id']     Nur dieser Artikel (0 = alle)
 * @param string $data['direction']    in | out | transfer (leer = alle)
 * @param string $data['search']       Suche in Artikelnummer, Bezeichnung und Bemerkung
 * @param int    $data['limit']        Maximale Trefferzahl (Standard 200)
 * @param int    $data['offset']       Versatz
 * @testdata {"date_from": "2026-01-01", "date_to": "2026-12-31", "limit": 100, "offset": 0}
 */
function getStockJournal($data) {
    $db = DbhCompany::begin();

    $dateFrom    = trim($data['date_from'] ?? '') ?: null;
    $dateTo      = trim($data['date_to'] ?? '') ?: null;
    $warehouseId = intval($data['warehouse_id'] ?? 0);
    $partsId     = intval($data['parts_id'] ?? 0);
    $direction   = $data['direction'] ?? '';
    $search      = trim($data['search'] ?? '');
    $limit       = min(1000, max(1, intval($data['limit'] ?? 200)));
    $offset      = max(0, intval($data['offset'] ?? 0));

    if (!in_array($direction, ['in', 'out', 'transfer'], true)) $direction = '';

    $query = <<<SQL
        WITH filtered AS (
            SELECT
                i.id, i.trans_id, i.qty, i.itime, i.shippingdate, i.comment, i.chargenumber,
                i.parts_id, p.partnumber, p.description AS part_description, p.unit,
                i.warehouse_id, w.description AS warehouse,
                i.bin_id, b.description AS bin,
                tt.direction, tt.description AS transfer_type,
                e.name AS employee,
                (i.oe_id IS NOT NULL OR i.invoice_id IS NOT NULL
                 OR i.delivery_order_items_stock_id IS NOT NULL) AS from_record
            FROM inventory i
            JOIN parts p          ON p.id  = i.parts_id
            JOIN warehouse w      ON w.id  = i.warehouse_id
            JOIN bin b            ON b.id  = i.bin_id
            JOIN transfer_type tt ON tt.id = i.trans_type_id
            LEFT JOIN employee e  ON e.id  = i.employee_id
            WHERE (:date_from::date IS NULL OR i.shippingdate >= :date_from::date)
              AND (:date_to::date   IS NULL OR i.shippingdate <= :date_to::date)
              AND (:warehouse_id = 0 OR i.warehouse_id = :warehouse_id)
              AND (:parts_id = 0     OR i.parts_id = :parts_id)
              AND (:direction = ''   OR tt.direction = :direction)
              AND (
                    :search = ''
                 OR p.partnumber  ILIKE :like
                 OR p.description ILIKE :like
                 OR i.comment     ILIKE :like
              )
        )
        SELECT json_build_object(
            'total',    (SELECT COUNT(*) FROM filtered),
            'qty_in',   (SELECT COALESCE(SUM(qty), 0) FROM filtered WHERE qty > 0),
            'qty_out',  (SELECT COALESCE(SUM(qty), 0) FROM filtered WHERE qty < 0),
            'items', COALESCE((
                SELECT json_agg(x ORDER BY x.itime DESC, x.id DESC)
                FROM (
                    SELECT * FROM filtered
                    ORDER BY itime DESC, id DESC
                    LIMIT :limit OFFSET :offset
                ) x
            ), '[]'::json)
        ) AS result
    SQL;

    $row = $db->getOne($query, [
        ':date_from'    => $dateFrom,
        ':date_to'      => $dateTo,
        ':warehouse_id' => $warehouseId,
        ':parts_id'     => $partsId,
        ':direction'    => $direction,
        ':search'       => $search,
        ':like'         => '%'.$search.'%',
        ':limit'        => $limit,
        ':offset'       => $offset,
    ]);

    resultInfo(true, '', ['results' => json_decode($row['result'] ?? '{}', true)]);
}
