<?php
// backend/api/warehouse/transfer.php
//
// Lagerbuchungen. Bewusst EIN Endpunkt fuer Einlagern, Auslagern und Umlagern:
// fachlich sind das drei Auspraegungen derselben Bewegung, und der Anwender
// soll nicht erst entscheiden muessen, welche Maske er oeffnet.
//
// Geschrieben wird ausschliesslich in die kivitendo-Tabelle `inventory`.
// parts.onhand aktualisiert der Trigger `trig_update_onhand` selbst.

/**
 * Mitarbeiter-ID ermitteln.
 *
 * Kommt normalerweise aus dem Frontend-Store; fehlt sie (z. B. beim
 * API-Tester), wird sie ueber den Login der Sitzung nachgeschlagen.
 * inventory.employee_id ist NOT NULL — ohne Mitarbeiter keine Buchung.
 */
function _wh_employeeId($db, $data) {
    $id = intval($data['employee_id'] ?? 0);
    if ($id > 0) return $id;

    $login = $_SESSION['login'] ?? ($data['login'] ?? null);
    if ($login) {
        $row = $db->getOne("SELECT id FROM employee WHERE login = :login", [':login' => $login]);
        if ($row) return intval($row['id']);
    }

    $row = $db->getOne("SELECT id FROM employee WHERE NOT COALESCE(deleted, false) ORDER BY id LIMIT 1");
    return $row ? intval($row['id']) : 0;
}

/**
 * Verfuegbare Menge an einem Lagerplatz (inkl. Charge).
 */
function _wh_available($db, $partsId, $warehouseId, $binId, $chargenumber) {
    $row = $db->getOne(
        "SELECT COALESCE(SUM(qty), 0) AS qty
         FROM inventory
         WHERE parts_id = :p AND warehouse_id = :w AND bin_id = :b AND chargenumber = :c",
        [':p' => $partsId, ':w' => $warehouseId, ':b' => $binId, ':c' => $chargenumber]
    );
    return floatval($row['qty']);
}

/**
 * Bewegungsart fuer eine Richtung ermitteln (Vorgabe, wenn der Anwender keine waehlt).
 */
function _wh_defaultTransferType($db, $direction) {
    $wanted = ['in' => 'stock', 'out' => 'used', 'transfer' => 'transfer'];
    $row = $db->getOne(
        "SELECT id FROM transfer_type
         WHERE direction = :dir
         ORDER BY (description = :pref) DESC, sortkey
         LIMIT 1",
        [':dir' => $direction, ':pref' => $wanted[$direction] ?? '']
    );
    return $row ? intval($row['id']) : 0;
}

/**
 * Lagerbuchung ausfuehren — Einlagern, Auslagern oder Umlagern.
 *
 * Beim Umlagern entstehen zwei inventory-Zeilen mit derselben trans_id
 * (Abgang am Quellplatz, Zugang am Zielplatz), damit die Bewegung als ein
 * Vorgang erkennbar bleibt und sich am Stueck zuruecknehmen laesst.
 *
 * @param string $data['direction']           in | out | transfer
 * @param int    $data['parts_id']            Artikel
 * @param float  $data['qty']                 Menge (immer positiv)
 * @param int    $data['warehouse_id']        Quelllager (bei "in" das Ziellager)
 * @param int    $data['bin_id']              Quellplatz (bei "in" der Zielplatz)
 * @param int    $data['target_warehouse_id'] Ziellager (nur bei "transfer")
 * @param int    $data['target_bin_id']       Zielplatz (nur bei "transfer")
 * @param int    $data['transfer_type_id']    Bewegungsart (optional, sonst Vorgabe der Richtung)
 * @param string $data['chargenumber']        Charge (optional)
 * @param string $data['bestbefore']          Mindesthaltbarkeitsdatum (optional)
 * @param string $data['comment']             Bemerkung (optional)
 * @param string $data['shippingdate']        Buchungsdatum (Standard heute)
 * @param bool   $data['allow_negative']      Buchung trotz fehlenden Bestands zulassen
 * @param int    $data['employee_id']         Mitarbeiter aus dem Frontend-Store
 * @testdata {"direction": "in", "parts_id": 1, "qty": 5, "warehouse_id": 1, "bin_id": 1, "comment": "Wareneingang"}
 */
function bookStock($data) {
    $db = DbhCompany::begin();

    $direction   = $data['direction'] ?? '';
    $partsId     = intval($data['parts_id'] ?? 0);
    $qty         = round(floatval($data['qty'] ?? 0), 5);
    $warehouseId = intval($data['warehouse_id'] ?? 0);
    $binId       = intval($data['bin_id'] ?? 0);
    $charge      = trim($data['chargenumber'] ?? '');
    $bestbefore  = trim($data['bestbefore'] ?? '') ?: null;
    $comment     = trim($data['comment'] ?? '') ?: null;
    $shipdate    = trim($data['shippingdate'] ?? '') ?: date('Y-m-d');

    if (!in_array($direction, ['in', 'out', 'transfer'], true)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Richtung muss in, out oder transfer sein'); return;
    }
    if ($partsId <= 0)     { resultInfo(false, 'VALIDATION_ERROR', 'Artikel fehlt'); return; }
    if ($qty <= 0)         { resultInfo(false, 'VALIDATION_ERROR', 'Menge muss größer 0 sein'); return; }
    if ($warehouseId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Lager fehlt'); return; }
    if ($binId <= 0)       { resultInfo(false, 'VALIDATION_ERROR', 'Lagerplatz fehlt'); return; }

    $employeeId = _wh_employeeId($db, $data);
    if ($employeeId <= 0) { resultInfo(false, 'NO_EMPLOYEE', 'Kein Mitarbeiter ermittelbar'); return; }

    $typeId = intval($data['transfer_type_id'] ?? 0) ?: _wh_defaultTransferType($db, $direction);
    if ($typeId <= 0) { resultInfo(false, 'NO_TRANSFER_TYPE', 'Keine Bewegungsart gefunden'); return; }

    // Ziel nur beim Umlagern; sonst ist die Quelle zugleich das Ziel.
    $targetWarehouseId = $direction === 'transfer' ? intval($data['target_warehouse_id'] ?? 0) : $warehouseId;
    $targetBinId       = $direction === 'transfer' ? intval($data['target_bin_id'] ?? 0)       : $binId;

    if ($direction === 'transfer') {
        if ($targetWarehouseId <= 0 || $targetBinId <= 0) {
            resultInfo(false, 'VALIDATION_ERROR', 'Zielplatz fehlt'); return;
        }
        if ($targetWarehouseId === $warehouseId && $targetBinId === $binId) {
            resultInfo(false, 'SAME_LOCATION', 'Quelle und Ziel sind identisch'); return;
        }
    }

    // Bestandspruefung: der Lagerbestand soll nicht unbemerkt negativ werden.
    if ($direction !== 'in' && empty($data['allow_negative'])) {
        $available = _wh_available($db, $partsId, $warehouseId, $binId, $charge);
        if ($qty > $available + 0.00001) {
            resultInfo(false, 'NOT_ENOUGH_STOCK',
                ['available' => $available, 'requested' => $qty]);
            return;
        }
    }

    $db->beginTransaction();
    try {
        // Gemeinsame Vorgangsnummer aus der kivitendo-Sequenz `id`.
        $transRow = $db->getOne("SELECT nextval('id') AS id");
        $transId  = intval($transRow['id']);

        $insert = "INSERT INTO inventory
                    (parts_id, warehouse_id, bin_id, qty, chargenumber, bestbefore,
                     comment, shippingdate, employee_id, trans_id, trans_type_id)
                   VALUES
                    (:parts_id, :warehouse_id, :bin_id, :qty, :chargenumber, :bestbefore,
                     :comment, :shippingdate, :employee_id, :trans_id, :trans_type_id)";

        $base = [
            ':parts_id'      => $partsId,
            ':chargenumber'  => $charge,
            ':bestbefore'    => $bestbefore,
            ':comment'       => $comment,
            ':shippingdate'  => $shipdate,
            ':employee_id'   => $employeeId,
            ':trans_id'      => $transId,
            ':trans_type_id' => $typeId,
        ];

        if ($direction !== 'in') {
            $db->execute($insert, $base + [
                ':warehouse_id' => $warehouseId,
                ':bin_id'       => $binId,
                ':qty'          => -$qty,
            ]);
        }
        if ($direction !== 'out') {
            $db->execute($insert, $base + [
                ':warehouse_id' => $targetWarehouseId,
                ':bin_id'       => $targetBinId,
                ':qty'          => $qty,
            ]);
        }

        // Ein bebuchter Artikel ist lagerfaehig — sonst blendet kivitendo ihn
        // in den Lagermasken aus und der Bestand waere unsichtbar.
        $db->execute(
            "UPDATE parts SET stockable = true WHERE id = :id AND COALESCE(stockable, false) = false",
            [':id' => $partsId]
        );

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $row = $db->getOne(
        "SELECT COALESCE(SUM(qty), 0) AS onhand FROM inventory WHERE parts_id = :p",
        [':p' => $partsId]
    );

    resultInfo(true, '', ['trans_id' => $transId, 'onhand' => floatval($row['onhand'])]);
}

/**
 * Eine manuelle Lagerbuchung zuruecknehmen.
 *
 * Nur moeglich, solange die in der Firmenkonfiguration eingestellte Frist
 * ("Rückgängig-Intervall") nicht abgelaufen ist und die Buchung nicht an einem
 * Beleg haengt — Belegbuchungen gehoeren zum Beleg und werden dort geaendert.
 *
 * @param int $data['trans_id'] Vorgangsnummer der Buchung
 * @testdata {"trans_id": 1}
 */
function undoStockTransfer($data) {
    $db = DbhCompany::begin();
    $transId = intval($data['trans_id'] ?? 0);

    if ($transId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Vorgangsnummer fehlt'); return; }

    $info = $db->getOne(
        "SELECT
            COUNT(*)                                                                 AS row_count,
            COUNT(*) FILTER (WHERE oe_id IS NOT NULL
                                OR invoice_id IS NOT NULL
                                OR delivery_order_items_stock_id IS NOT NULL)        AS linked,
            MIN(itime)                                                               AS booked_at,
            COALESCE((SELECT undo_transfer_interval FROM defaults LIMIT 1), 0)        AS undo_days
         FROM inventory WHERE trans_id = :t",
        [':t' => $transId]
    );

    if (intval($info['row_count']) === 0) { resultInfo(false, 'NOT_FOUND', 'Buchung nicht gefunden'); return; }
    if (intval($info['linked']) > 0)   { resultInfo(false, 'LINKED_TO_RECORD', 'Die Buchung gehört zu einem Beleg und kann hier nicht zurückgenommen werden.'); return; }

    $undoDays = intval($info['undo_days']);
    if ($undoDays > 0) {
        $age = (time() - strtotime($info['booked_at'])) / 86400;
        if ($age > $undoDays) {
            resultInfo(false, 'UNDO_EXPIRED', ['undo_days' => $undoDays]);
            return;
        }
    }

    $db->execute("DELETE FROM inventory WHERE trans_id = :t", [':t' => $transId]);
    resultInfo(true, '', ['trans_id' => $transId]);
}
