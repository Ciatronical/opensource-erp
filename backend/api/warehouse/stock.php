<?php
// backend/api/warehouse/stock.php
//
// Bestandsabfragen. Der Bestand wird immer aus `inventory` aggregiert — nie
// aus parts.onhand gelesen, damit Lager/Platz/Charge korrekt aufgeschluesselt
// werden koennen. parts.onhand dient nur als Schnellfilter.

/**
 * Bestandsliste mit Sofortsuche.
 *
 * Liefert je Artikel den Gesamtbestand und — direkt mitgeliefert — die
 * Verteilung auf Lager, Lagerplatz und Charge. Dadurch kann die Oberflaeche
 * eine Zeile aufklappen, ohne nachzuladen.
 *
 * @param string $data['search']         Suchbegriff (Artikelnummer, Bezeichnung oder EAN)
 * @param int    $data['warehouse_id']   Nur dieses Lager (0 = alle)
 * @param string $data['filter']         all | below_rop | dead | zero
 * @param int    $data['dead_days']      Schwelle fuer Ladenhueter in Tagen (Standard 180)
 * @param int    $data['limit']          Maximale Trefferzahl (Standard 100)
 * @param int    $data['offset']         Versatz fuer das Nachladen
 * @testdata {"search": "", "warehouse_id": 0, "filter": "all", "limit": 50, "offset": 0}
 */
function getStock($data) {
    $db = DbhCompany::begin();

    $search      = trim($data['search'] ?? '');
    $warehouseId = intval($data['warehouse_id'] ?? 0);
    $filter      = $data['filter'] ?? 'all';
    $deadDays    = max(1, intval($data['dead_days'] ?? 180));
    $limit       = min(500, max(1, intval($data['limit'] ?? 100)));
    $offset      = max(0, intval($data['offset'] ?? 0));

    if (!in_array($filter, ['all', 'below_rop', 'dead', 'zero'], true)) $filter = 'all';

    $query = <<<SQL
        WITH stock AS (
            SELECT
                i.parts_id, i.warehouse_id, i.bin_id, i.chargenumber, i.bestbefore,
                SUM(i.qty) AS qty, MAX(i.itime) AS last_move
            FROM inventory i
            WHERE (:warehouse_id = 0 OR i.warehouse_id = :warehouse_id)
            GROUP BY i.parts_id, i.warehouse_id, i.bin_id, i.chargenumber, i.bestbefore
            HAVING SUM(i.qty) <> 0
        ),
        per_part AS (
            SELECT parts_id, SUM(qty) AS qty, MAX(last_move) AS last_move
            FROM stock GROUP BY parts_id
        ),
        candidates AS (
            SELECT
                p.id, p.partnumber, p.description, p.unit, p.ean,
                COALESCE(p.rop, 0)::numeric      AS rop,
                COALESCE(p.lastcost, 0)::numeric AS lastcost,
                COALESCE(p.sellprice, 0)::numeric AS sellprice,
                COALESCE(p.stockable, false)     AS stockable,
                COALESCE(pp.qty, 0)::numeric     AS qty,
                pp.last_move
            FROM parts p
            LEFT JOIN per_part pp ON pp.parts_id = p.id
            WHERE p.part_type <> 'service'
              AND (
                    :search = ''
                 OR p.partnumber  ILIKE :like
                 OR p.description ILIKE :like
                 OR p.ean         ILIKE :like
              )
              AND CASE :filter
                    WHEN 'below_rop' THEN COALESCE(p.rop, 0) > 0 AND COALESCE(pp.qty, 0) <= p.rop
                    WHEN 'dead'      THEN pp.qty IS NOT NULL AND pp.last_move < NOW() - (:dead_days || ' days')::interval
                    WHEN 'zero'      THEN pp.qty IS NULL
                    ELSE                  pp.qty IS NOT NULL OR :search <> ''
                  END
        ),
        page AS (
            SELECT * FROM candidates
            ORDER BY (qty = 0), partnumber
            LIMIT :limit OFFSET :offset
        )
        SELECT json_build_object(
            'total', (SELECT COUNT(*) FROM candidates),
            'sum_value', (SELECT COALESCE(SUM(qty * lastcost), 0) FROM candidates),
            'items', COALESCE((
                SELECT json_agg(json_build_object(
                    'id',          x.id,
                    'partnumber',  x.partnumber,
                    'description', x.description,
                    'unit',        x.unit,
                    'ean',         x.ean,
                    'rop',         x.rop,
                    'lastcost',    x.lastcost,
                    'sellprice',   x.sellprice,
                    'stockable',   x.stockable,
                    'qty',         x.qty,
                    'value',       x.qty * x.lastcost,
                    'last_move',   x.last_move,
                    'below_rop',   (x.rop > 0 AND x.qty <= x.rop),
                    'locations', COALESCE((
                        SELECT json_agg(json_build_object(
                            'warehouse_id', s.warehouse_id,
                            'warehouse',    w.description,
                            'bin_id',       s.bin_id,
                            'bin',          b.description,
                            'chargenumber', s.chargenumber,
                            'bestbefore',   s.bestbefore,
                            'qty',          s.qty
                        ) ORDER BY w.description, b.description, s.chargenumber)
                        FROM stock s
                        JOIN warehouse w ON w.id = s.warehouse_id
                        JOIN bin b       ON b.id = s.bin_id
                        WHERE s.parts_id = x.id
                    ), '[]'::json)
                ) ORDER BY (x.qty = 0), x.partnumber)
                FROM page x
            ), '[]'::json)
        ) AS result
    SQL;

    $row = $db->getOne($query, [
        ':search'       => $search,
        ':like'         => '%'.$search.'%',
        ':warehouse_id' => $warehouseId,
        ':filter'       => $filter,
        ':dead_days'    => $deadDays,
        ':limit'        => $limit,
        ':offset'       => $offset,
    ]);

    resultInfo(true, '', ['results' => json_decode($row['result'] ?? '{}', true)]);
}

/**
 * Ein Artikel im Detail: Bestand je Lagerplatz plus die letzten Bewegungen.
 *
 * @param int $data['parts_id'] Artikel-ID
 * @param int $data['limit']    Anzahl der Bewegungen (Standard 30)
 * @testdata {"parts_id": 1, "limit": 30}
 */
function getPartStock($data) {
    $db = DbhCompany::begin();
    $partsId = intval($data['parts_id'] ?? 0);
    $limit   = min(200, max(1, intval($data['limit'] ?? 30)));

    if ($partsId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Artikel fehlt'); return; }

    $query = <<<SQL
        SELECT json_build_object(
            'part', (
                SELECT row_to_json(pd) FROM (
                    SELECT p.id, p.partnumber, p.description, p.unit, p.ean,
                           COALESCE(p.rop, 0)::numeric      AS rop,
                           COALESCE(p.lastcost, 0)::numeric AS lastcost,
                           COALESCE(p.stockable, false)     AS stockable,
                           COALESCE(p.onhand, 0)::numeric   AS onhand,
                           -- Wahrheit ist immer die Summe der Lagerbewegungen;
                           -- parts.onhand ist nur ein Schnellfeld und kann aus
                           -- Alt-/Importdaten davon abweichen.
                           COALESCE((SELECT SUM(i.qty) FROM inventory i WHERE i.parts_id = p.id), 0)::numeric AS stock_qty
                    FROM parts p WHERE p.id = :parts_id
                ) pd
            ),
            'locations', COALESCE((
                SELECT json_agg(l ORDER BY l.warehouse, l.bin, l.chargenumber)
                FROM (
                    SELECT
                        i.warehouse_id, w.description AS warehouse,
                        i.bin_id,       b.description AS bin,
                        i.chargenumber, i.bestbefore,
                        SUM(i.qty) AS qty
                    FROM inventory i
                    JOIN warehouse w ON w.id = i.warehouse_id
                    JOIN bin b       ON b.id = i.bin_id
                    WHERE i.parts_id = :parts_id
                    GROUP BY i.warehouse_id, w.description, i.bin_id, b.description, i.chargenumber, i.bestbefore
                    HAVING SUM(i.qty) <> 0
                ) l
            ), '[]'::json),
            'moves', COALESCE((
                SELECT json_agg(m ORDER BY m.itime DESC, m.id DESC)
                FROM (
                    SELECT
                        i.id, i.qty, i.itime, i.shippingdate, i.comment, i.chargenumber, i.trans_id,
                        w.description AS warehouse, b.description AS bin,
                        tt.direction, tt.description AS transfer_type,
                        e.name AS employee
                    FROM inventory i
                    JOIN warehouse w      ON w.id  = i.warehouse_id
                    JOIN bin b            ON b.id  = i.bin_id
                    JOIN transfer_type tt ON tt.id = i.trans_type_id
                    LEFT JOIN employee e  ON e.id  = i.employee_id
                    WHERE i.parts_id = :parts_id
                    ORDER BY i.itime DESC, i.id DESC
                    LIMIT :limit
                ) m
            ), '[]'::json)
        ) AS result
    SQL;

    $row = $db->getOne($query, [':parts_id' => $partsId, ':limit' => $limit]);
    $result = json_decode($row['result'] ?? '{}', true);

    if (empty($result['part'])) { resultInfo(false, 'NOT_FOUND', 'Artikel nicht gefunden'); return; }

    resultInfo(true, '', ['results' => $result]);
}

/**
 * Artikel fuer den Scanner-Modus aufloesen.
 *
 * Trifft der Code genau einen Artikel (EAN oder Artikelnummer), wird dieser
 * samt Bestand direkt zurueckgegeben — der Scanner kann dann ohne weiteren
 * Klick zur Mengeneingabe springen. Sonst kommt eine Trefferliste.
 *
 * @param string $data['code'] Gescannter oder getippter Code
 * @testdata {"code": "4001"}
 */
function lookupPartByCode($data) {
    $db = DbhCompany::begin();
    $code = trim($data['code'] ?? '');

    if ($code === '') { resultInfo(false, 'VALIDATION_ERROR', 'Code fehlt'); return; }

    $query = <<<SQL
        WITH matched AS (
            SELECT
                p.id, p.partnumber, p.description, p.unit, p.ean,
                COALESCE(p.lastcost, 0)::numeric AS lastcost,
                COALESCE(p.stockable, false)     AS stockable,
                COALESCE((SELECT SUM(i.qty) FROM inventory i WHERE i.parts_id = p.id), 0)::numeric AS qty,
                -- exakte Treffer zuerst: EAN, dann Artikelnummer, dann Teiltreffer
                CASE
                    WHEN LOWER(p.ean)        = LOWER(:code) THEN 0
                    WHEN LOWER(p.partnumber) = LOWER(:code) THEN 1
                    ELSE 2
                END AS match_rank
            FROM parts p
            WHERE p.part_type <> 'service'
              AND (
                    LOWER(p.ean)        = LOWER(:code)
                 OR LOWER(p.partnumber) = LOWER(:code)
                 OR p.partnumber  ILIKE :like
                 OR p.description ILIKE :like
              )
        )
        SELECT json_build_object(
            'exact', (SELECT COUNT(*) FROM matched WHERE match_rank < 2) = 1,
            'items', COALESCE((
                SELECT json_agg(x ORDER BY x.match_rank, x.partnumber)
                FROM (SELECT * FROM matched ORDER BY match_rank, partnumber LIMIT 25) x
            ), '[]'::json)
        ) AS result
    SQL;

    $row = $db->getOne($query, [':code' => $code, ':like' => '%'.$code.'%']);
    resultInfo(true, '', ['results' => json_decode($row['result'] ?? '{}', true)]);
}

/**
 * Artikel als lagerfaehig markieren bzw. die Markierung entfernen.
 *
 * kivitendo fuehrt Bestand nur fuer Artikel mit gesetztem `stockable`. Damit
 * niemand deswegen im Lager haengen bleibt, laesst sich das Kennzeichen direkt
 * aus der Bestandsansicht setzen.
 *
 * @param int  $data['parts_id']  Artikel-ID
 * @param bool $data['stockable'] true = lagerfaehig
 * @testdata {"parts_id": 1, "stockable": true}
 */
function setPartStockable($data) {
    $db = DbhCompany::begin();
    $partsId   = intval($data['parts_id'] ?? 0);
    $stockable = !empty($data['stockable']);

    if ($partsId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Artikel fehlt'); return; }

    $row = $db->getOne(
        "UPDATE parts SET stockable = :stockable WHERE id = :id RETURNING id, stockable",
        [':stockable' => $stockable, ':id' => $partsId]
    );
    if (!$row) { resultInfo(false, 'NOT_FOUND', 'Artikel nicht gefunden'); return; }

    resultInfo(true, '', ['parts_id' => intval($row['id']), 'stockable' => $row['stockable']]);
}

/**
 * Meldebestand (Mindestbestand) eines Artikels setzen.
 *
 * @param int   $data['parts_id'] Artikel-ID
 * @param float $data['rop']      Meldebestand (0 = keine Ueberwachung)
 * @testdata {"parts_id": 1, "rop": 5}
 */
function setPartRop($data) {
    $db = DbhCompany::begin();
    $partsId = intval($data['parts_id'] ?? 0);
    $rop     = max(0, floatval($data['rop'] ?? 0));

    if ($partsId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Artikel fehlt'); return; }

    $row = $db->getOne(
        "UPDATE parts SET rop = :rop WHERE id = :id RETURNING id, rop",
        [':rop' => $rop, ':id' => $partsId]
    );
    if (!$row) { resultInfo(false, 'NOT_FOUND', 'Artikel nicht gefunden'); return; }

    resultInfo(true, '', ['parts_id' => intval($row['id']), 'rop' => $row['rop']]);
}

/**
 * Schnellfeld parts.onhand aus den Lagerbewegungen neu berechnen.
 *
 * kivitendo fuehrt parts.onhand ueber einen Trigger mit. Nach Datenimporten
 * oder direkten Eingriffen in die Datenbank kann das Feld von der Summe der
 * inventory-Zeilen abweichen — dann zeigen kivitendo-Masken einen anderen
 * Bestand als das Lagermodul. Diese Funktion gleicht beides wieder an.
 *
 * @param int $data['parts_id'] Nur diesen Artikel abgleichen (0 = alle abweichenden)
 * @testdata {"parts_id": 0}
 */
function recalcPartsOnhand($data) {
    $db = DbhCompany::begin();
    $partsId = intval($data['parts_id'] ?? 0);

    $rows = $db->getAll(
        "WITH real AS (
            SELECT p.id, COALESCE(p.onhand, 0)::numeric AS onhand,
                   COALESCE((SELECT SUM(i.qty) FROM inventory i WHERE i.parts_id = p.id), 0)::numeric AS stock_qty
            FROM parts p
            WHERE (:parts_id = 0 OR p.id = :parts_id)
         )
         UPDATE parts p SET onhand = r.stock_qty
         FROM real r
         WHERE p.id = r.id AND r.onhand IS DISTINCT FROM r.stock_qty
         RETURNING p.id, r.onhand AS was, r.stock_qty AS corrected",
        [':parts_id' => $partsId]
    );

    resultInfo(true, '', ['fixed' => count($rows), 'items' => $rows]);
}
