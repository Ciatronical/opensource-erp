<?php
// backend/api/accounting/chart_accounts.php

/**
 * Kontenrahmen laden (Liste aller Konten mit Steuerschlüssel-Status)
 *
 * Liefert pro Konto, ob ein zeitabhängiger Steuerschlüssel in der taxkeys-Tabelle
 * hinterlegt ist (has_taxkeys). Konten mit gesetztem chart.taxkey_id aber ohne
 * taxkeys-Zeile sind in Buchungsmasken nicht korrekt nutzbar.
 *
 * @param string $data['search']   Suchbegriff (Kontonummer oder Beschreibung)
 * @param string $data['category'] Filter auf Kontoart (C/A/L/Q/E/I), leer = alle
 * @testdata {"search": "5425"}
 */
function getChartAccounts($data) {
    $db = DbhCompany::begin();

    $search   = trim($data['search'] ?? '');
    $category = trim($data['category'] ?? '');

    $where  = ['1=1'];
    $params = [];

    if ($search !== '') {
        $where[] = '(c.accno ILIKE :search OR c.description ILIKE :search)';
        $params[':search'] = '%'.$search.'%';
    }
    if ($category !== '') {
        $where[] = 'c.category = :category';
        $params[':category'] = $category;
    }
    $whereClause = implode(' AND ', $where);

    $accounts = $db->getAll(<<<SQL
        SELECT c.id, c.accno, c.description, c.charttype, c.category, c.invalid,
               c.taxkey_id,
               COUNT(tk.id) AS taxkey_count,
               (COUNT(tk.id) = 0 AND c.taxkey_id IS NOT NULL AND c.taxkey_id <> 0) AS taxkey_missing
        FROM chart c
        LEFT JOIN taxkeys tk ON tk.chart_id = c.id
        WHERE {$whereClause}
        GROUP BY c.id
        ORDER BY c.accno
    SQL, $params);

    resultInfo(true, '', ['accounts' => $accounts]);
}

/**
 * Einzelnes Konto mit allen zeitabhängigen Steuerschlüsseln laden
 *
 * @param int $data['id'] Konto-ID (chart.id)
 * @testdata {"id": 170}
 */
function getChartAccount($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    $chart = $db->getOne(<<<SQL
        SELECT c.id, c.accno, c.description, c.charttype, c.category, c.link,
               c.taxkey_id, c.pos_bwa, c.pos_bilanz, c.pos_eur, c.pos_er,
               c.datevautomatik, c.invalid,
               NOT EXISTS (SELECT 1 FROM acc_trans a WHERE a.chart_id = c.id) AS orphaned
        FROM chart c
        WHERE c.id = :id
    SQL, [':id' => $id]);

    if (!$chart) {
        throw new ApiError('DATA_NOT_FOUND', 'Konto nicht gefunden');
    }

    $taxkeys = $db->getAll(<<<SQL
        SELECT tk.id, tk.tax_id, tk.taxkey_id, tk.pos_ustva,
               TO_CHAR(tk.startdate, 'YYYY-MM-DD') AS startdate,
               t.taxkey, t.rate, (t.rate * 100) AS rate_percent,
               t.taxdescription, tc.accno AS tax_chart_accno
        FROM taxkeys tk
        JOIN tax t ON t.id = tk.tax_id
        LEFT JOIN chart tc ON tc.id = t.chart_id
        WHERE tk.chart_id = :id
        ORDER BY tk.startdate
    SQL, [':id' => $id]);

    $chart['taxkeys'] = $taxkeys;

    resultInfo(true, '', ['chart' => $chart]);
}

/**
 * Verfügbare Steuerschlüssel (tax-Tabelle) für die Steuerschlüssel-Auswahl
 *
 * Wird einmalig beim Öffnen der Konten-Seite geladen und für alle Konten
 * wiederverwendet.
 *
 * @testdata {}
 */
function getChartAccountTaxOptions($data) {
    $db = DbhCompany::begin();

    $taxes = $db->getAll(<<<SQL
        SELECT t.id, t.taxkey, t.rate, (t.rate * 100) AS rate_percent,
               t.taxdescription, t.chart_categories,
               tc.accno AS chart_accno
        FROM tax t
        LEFT JOIN chart tc ON tc.id = t.chart_id
        ORDER BY t.taxkey, t.rate
    SQL);

    resultInfo(true, '', ['taxes' => $taxes]);
}

/**
 * Konto speichern (Grunddaten + zeitabhängige Steuerschlüssel)
 *
 * Legt fehlende taxkeys-Zeilen an, aktualisiert bestehende und löscht markierte.
 * chart.taxkey_id wird mit dem Steuerschlüssel der jüngsten taxkeys-Zeile
 * synchronisiert, damit der Standard-Steuerschlüssel des Kontos stimmt.
 *
 * @param int    $data['id']          Konto-ID
 * @param string $data['accno']       Kontonummer
 * @param string $data['description'] Beschreibung
 * @param string $data['charttype']   A = Konto, H = Überschrift
 * @param string $data['category']    Kontoart (C/A/L/Q/E/I)
 * @param bool   $data['invalid']     Konto ungültig
 * @param int    $data['pos_eur']     Position EÜR
 * @param int    $data['pos_bwa']     Position BWA
 * @param int    $data['pos_bilanz']  Position Bilanz
 * @param int    $data['pos_er']      Position E/R
 * @param array  $data['taxkeys']     Steuerschlüssel-Zeilen [{id, tax_id, startdate, pos_ustva, delete}]
 * @testdata {"id": 170, "accno": "5425", "description": "Innergem.Erwerb 19% VorSt u. Ust", "charttype": "A", "category": "E", "taxkeys": [{"id": "NEW", "tax_id": 1174, "startdate": "1970-01-01", "pos_ustva": null}]}
 */
function saveChartAccount($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        throw new ApiError('INVALID_PARAMETER', 'Konto-ID fehlt');
    }

    $taxkeys = is_array($data['taxkeys'] ?? null) ? $data['taxkeys'] : [];

    $db->beginTransaction();
    try {
        $db->execute(<<<SQL
            UPDATE chart SET
                accno       = :accno,
                description = :description,
                charttype   = :charttype,
                category    = :category,
                invalid     = :invalid,
                pos_eur     = :pos_eur,
                pos_bwa     = :pos_bwa,
                pos_bilanz  = :pos_bilanz,
                pos_er      = :pos_er,
                mtime       = now()
            WHERE id = :id
        SQL, [
            ':accno'       => trim($data['accno'] ?? ''),
            ':description' => trim($data['description'] ?? ''),
            ':charttype'   => ($data['charttype'] ?? 'A') === 'H' ? 'H' : 'A',
            ':category'    => ($data['category'] ?? '') !== '' ? $data['category'] : null,
            ':invalid'     => !empty($data['invalid']),
            ':pos_eur'     => _chartPosOrNull($data['pos_eur'] ?? null),
            ':pos_bwa'     => _chartPosOrNull($data['pos_bwa'] ?? null),
            ':pos_bilanz'  => _chartPosOrNull($data['pos_bilanz'] ?? null),
            ':pos_er'      => _chartPosOrNull($data['pos_er'] ?? null),
            ':id'          => $id,
        ]);

        foreach ($taxkeys as $row) {
            $rowId  = $row['id'] ?? null;
            $delete = !empty($row['delete']);

            // Löschen einer bestehenden Zeile
            if ($delete) {
                if ($rowId !== null && $rowId !== 'NEW') {
                    $db->execute('DELETE FROM taxkeys WHERE id = :id AND chart_id = :chart_id',
                        [':id' => intval($rowId), ':chart_id' => $id]);
                }
                continue;
            }

            $taxId     = intval($row['tax_id'] ?? 0);
            $startdate = trim($row['startdate'] ?? '');

            // Unvollständige neue Zeilen überspringen
            if ($taxId <= 0 || $startdate === '') {
                if ($rowId === null || $rowId === 'NEW') continue;
            }

            // BU-Schlüssel (taxkey_id) aus der gewählten tax-Zeile ableiten
            $taxRow = $db->getOne('SELECT taxkey FROM tax WHERE id = :id', [':id' => $taxId]);
            if (!$taxRow) {
                throw new ApiError('INVALID_PARAMETER', 'Ungültiger Steuerschlüssel');
            }

            $params = [
                ':chart_id'  => $id,
                ':tax_id'    => $taxId,
                ':taxkey_id' => intval($taxRow['taxkey']),
                ':pos_ustva' => _chartPosOrNull($row['pos_ustva'] ?? null),
                ':startdate' => $startdate,
            ];

            if ($rowId === null || $rowId === 'NEW') {
                $db->execute(<<<SQL
                    INSERT INTO taxkeys (chart_id, tax_id, taxkey_id, pos_ustva, startdate)
                    VALUES (:chart_id, :tax_id, :taxkey_id, :pos_ustva, :startdate)
                SQL, $params);
            } else {
                $params[':id'] = intval($rowId);
                $db->execute(<<<SQL
                    UPDATE taxkeys SET
                        tax_id    = :tax_id,
                        taxkey_id = :taxkey_id,
                        pos_ustva = :pos_ustva,
                        startdate = :startdate
                    WHERE id = :id AND chart_id = :chart_id
                SQL, $params);
            }
        }

        // chart.taxkey_id mit der jüngsten verbleibenden taxkeys-Zeile synchronisieren
        $db->execute(<<<SQL
            UPDATE chart SET taxkey_id = COALESCE(
                (SELECT tk.taxkey_id FROM taxkeys tk
                 WHERE tk.chart_id = :id ORDER BY tk.startdate DESC LIMIT 1), 0)
            WHERE id = :id
        SQL, [':id' => $id]);

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    resultInfo(true, '', ['id' => $id]);
}

/**
 * Hilfsfunktion: Positionsangabe in Integer oder NULL wandeln
 */
function _chartPosOrNull($value) {
    if ($value === null || $value === '' || $value === false) return null;
    return intval($value);
}
