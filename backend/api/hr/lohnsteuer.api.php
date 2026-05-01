<?php
// backend/api/hr/lohnsteuer.api.php
// API-Funktionen fuer Lohnsteuerberechnung (PAP-basiert)

// ============================================================================
// TABELLEN-VERWALTUNG
// ============================================================================

/**
 * Gibt Status und Vorschau der Lohnsteuertabelle zurueck
 *
 * @testdata {"action": "getLohnsteuerTableStatus"}
 */
function getLohnsteuerTableStatus($data) {
    $db = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_build_object(
            'available_years', COALESCE((
                SELECT json_agg(json_build_object(
                    'year', m.tax_year,
                    'row_count', m.row_count,
                    'built_at', m.built_at,
                    'built_by_name', e.name,
                    'notes', m.notes
                ) ORDER BY m.tax_year DESC)
                FROM hr_lohnsteuer_meta m
                LEFT JOIN employee e ON e.id = m.built_by
            ), '[]'::json),
            'current_year', EXTRACT(YEAR FROM NOW())::int,
            'preview', COALESCE((
                SELECT json_agg(json_build_object(
                    'steuerklasse', t.steuerklasse,
                    'brutto_monat', t.brutto_monat,
                    'lohnsteuer', t.lohnsteuer,
                    'soli', t.soli
                ) ORDER BY t.steuerklasse, t.brutto_monat)
                FROM hr_lohnsteuer_table t
                WHERE t.tax_year = EXTRACT(YEAR FROM NOW())::int
                AND t.brutto_monat IN (2000, 3000, 4000, 5000)
                AND t.steuerklasse IN (1, 3)
            ), '[]'::json)
        ) AS result
    SQL;

    echo $db->get($query);
}

/**
 * Baut die Lohnsteuertabelle fuer ein Jahr lokal (PAP-Algorithmus, kein Internet).
 * Berechnet SK 1-6 fuer Brutto 0 bis 20.000 EUR in 50-EUR-Stufen.
 *
 * @param int    $data['year']        Steuerjahr (default: aktuelles Jahr)
 * @param int    $data['employee_id'] Ausfuehrender Mitarbeiter
 * @param int    $data['step']        Schrittweite in EUR (default: 50)
 * @param int    $data['max_brutto']  Obergrenze in EUR (default: 20000)
 * @testdata {"action": "buildLohnsteuerTable", "year": 2025, "employee_id": 1}
 */
function buildLohnsteuerTable($data) {
    $db = DbhCompany::begin();

    $year       = intval($data['year']       ?? date('Y'));
    $employeeId = intval($data['employee_id'] ?? 0);
    $step       = max(10, intval($data['step']       ?? 50));
    $maxBrutto  = max(1000, intval($data['max_brutto'] ?? 20000));

    // Sicherstellen dass PAP fuer das Jahr implementiert ist
    $konstanten = LohnsteuerPAP::getKonstanten($year);
    if (empty($konstanten)) {
        resultInfo(false, 'NO_PAP_FOR_YEAR', "Kein PAP fuer Jahr $year implementiert");
        return;
    }

    // Alte Tabelle fuer dieses Jahr loeschen
    $db->execute('DELETE FROM hr_lohnsteuer_table WHERE tax_year = :y', [':y' => $year]);
    $db->execute('DELETE FROM hr_lohnsteuer_meta WHERE tax_year = :y', [':y' => $year]);

    $rowCount = 0;

    for ($stkl = 1; $stkl <= 6; $stkl++) {
        // Brutto 0 (Sonderfall) + alle Stufen
        $bruttoWerte = [0];
        for ($b = $step; $b <= $maxBrutto; $b += $step) {
            $bruttoWerte[] = $b;
        }

        foreach ($bruttoWerte as $brutto) {
            $result = LohnsteuerPAP::berechne(
                (float) $brutto,
                $stkl,
                0.0, 0.0, 0.0, // Standard-SV-Saetze (aus PAP-Konstanten)
                false,          // ohne Kirchensteuer (gesondert gespeichert)
                9.0,
                $year
            );

            // Kirchensteuer separat (9%)
            $kist = floor($result['lohnsteuer_monat'] * 0.09);

            $db->execute(
                <<<SQL
                INSERT INTO hr_lohnsteuer_table
                    (tax_year, steuerklasse, brutto_monat, lohnsteuer, soli, kirchensteuer, zve_jahr)
                VALUES (:y, :sk, :br, :lst, :soli, :kist, :zve)
                SQL,
                [
                    ':y'    => $year,
                    ':sk'   => $stkl,
                    ':br'   => $brutto,
                    ':lst'  => $result['lohnsteuer_monat'],
                    ':soli' => $result['soli_monat'],
                    ':kist' => $kist,
                    ':zve'  => $result['zve_jahr'],
                ]
            );
            $rowCount++;
        }
    }

    // Meta-Eintrag
    $db->execute(
        'INSERT INTO hr_lohnsteuer_meta (tax_year, row_count, built_by)
         VALUES (:y, :rc, :eid)',
        [':y' => $year, ':rc' => $rowCount, ':eid' => $employeeId ?: null]
    );

    resultInfo(true, '', [
        'year'      => $year,
        'row_count' => $rowCount,
        'step'      => $step,
        'max_brutto' => $maxBrutto,
    ]);
}

/**
 * Gibt die Lohnsteuertabelle fuer ein Jahr zurueck (alle SK, gefiltert)
 *
 * @param int $data['year']         Steuerjahr
 * @param int $data['steuerklasse'] Nur diese SK (optional)
 * @testdata {"action": "getLohnsteuerTable", "year": 2025, "steuerklasse": 1}
 */
function getLohnsteuerTable($data) {
    $db = DbhCompany::begin();
    $year = intval($data['year'] ?? date('Y'));
    $stkl = intval($data['steuerklasse'] ?? 0);

    $sklFilter = ($stkl > 0 && $stkl <= 6) ? "AND steuerklasse = $stkl" : '';

    $query = <<<SQL
        SELECT json_build_object(
            'year', $year,
            'rows', COALESCE((
                SELECT json_agg(json_build_object(
                    'steuerklasse', steuerklasse,
                    'brutto_monat', brutto_monat,
                    'lohnsteuer', lohnsteuer,
                    'soli', soli,
                    'kirchensteuer', kirchensteuer,
                    'zve_jahr', zve_jahr
                ) ORDER BY steuerklasse, brutto_monat)
                FROM hr_lohnsteuer_table
                WHERE tax_year = $year $sklFilter
            ), '[]'::json)
        ) AS result
    SQL;

    echo $db->get($query);
}

// ============================================================================
// LOHNLAUF-BERECHNUNG
// ============================================================================

/**
 * Berechnet Lohnsteuer + Soli fuer alle Positionen eines Lohnlaufs automatisch.
 * Nutzt den PAP-Algorithmus mit den gespeicherten Gehaltseinstellungen.
 *
 * @param int  $data['run_id']        Lohnlauf-ID
 * @param bool $data['kirchensteuer'] Kirchensteuer berechnen (default: false)
 * @param int  $data['year']          Steuerjahr (default: aktuelles Jahr)
 * @testdata {"action": "calculatePayrollTaxes", "run_id": 1}
 */
function calculatePayrollTaxes($data) {
    $db = DbhCompany::begin();

    $runId         = intval($data['run_id'] ?? 0);
    $withKist      = !empty($data['kirchensteuer']);
    $year          = intval($data['year'] ?? date('Y'));

    if ($runId <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungueltige Run-ID');
        return;
    }

    $run = $db->getOne(
        "SELECT status FROM hr_payroll_runs WHERE id = :id",
        [':id' => $runId]
    );
    if (!$run) {
        resultInfo(false, 'NOT_FOUND', 'Lohnlauf nicht gefunden');
        return;
    }
    if ($run['status'] === 'final') {
        resultInfo(false, 'ALREADY_FINAL', 'Abgeschlossene Lohnlaeufe koennen nicht veraendert werden');
        return;
    }

    // Alle Items mit Gehaltseinstellungen laden
    $items = $db->getAll(
        <<<SQL
        SELECT
            i.id,
            i.brutto,
            COALESCE(s.steuerklasse, 1)     AS steuerklasse,
            COALESCE(s.rv_prozent, 9.3)     AS rv_prozent,
            COALESCE(s.kv_prozent, 7.3)     AS kv_prozent,
            COALESCE(s.pv_prozent, 1.7)     AS pv_prozent
        FROM hr_payroll_items i
        JOIN employee e ON e.id = i.employee_id
        LEFT JOIN hr_salary_settings s ON s.employee_id = i.employee_id
        WHERE i.run_id = :rid
        SQL,
        [':rid' => $runId]
    );

    $updated = 0;
    foreach ($items as $item) {
        $result = LohnsteuerPAP::berechne(
            floatval($item['brutto']),
            intval($item['steuerklasse']),
            floatval($item['rv_prozent']),
            floatval($item['kv_prozent']),
            floatval($item['pv_prozent']),
            $withKist,
            9.0,
            $year
        );

        $db->execute(
            <<<SQL
            UPDATE hr_payroll_items SET
                lohnsteuer            = :lst,
                solidaritaetszuschlag = :soli,
                kirchensteuer         = :kist,
                mtime                 = NOW()
            WHERE id = :id
            SQL,
            [
                ':lst'  => $result['lohnsteuer_monat'],
                ':soli' => $result['soli_monat'],
                ':kist' => $result['kist_monat'],
                ':id'   => intval($item['id']),
            ]
        );
        $updated++;
    }

    resultInfo(true, '', ['updated' => $updated, 'year' => $year]);
}

/**
 * Berechnet eine Schnell-Vorschau (ohne DB) fuer gegebene Parameter
 *
 * @param float $data['brutto_monat']  Bruttogehalt
 * @param int   $data['steuerklasse']  Steuerklasse 1-6
 * @param float $data['rv_prozent']    RV AN-Anteil % (optional)
 * @param float $data['kv_prozent']    KV AN-Anteil % (optional)
 * @param float $data['pv_prozent']    PV AN-Anteil % (optional)
 * @param int   $data['year']          Steuerjahr (optional)
 * @testdata {"action": "previewLohnsteuer", "brutto_monat": 3500, "steuerklasse": 1}
 */
function previewLohnsteuer($data) {
    $result = LohnsteuerPAP::berechne(
        floatval($data['brutto_monat'] ?? 0),
        intval($data['steuerklasse']   ?? 1),
        floatval($data['rv_prozent']   ?? 0),
        floatval($data['kv_prozent']   ?? 0),
        floatval($data['pv_prozent']   ?? 0),
        !empty($data['kirchensteuer']),
        floatval($data['kist_satz']    ?? 9.0),
        intval($data['year']           ?? date('Y'))
    );

    resultInfo(true, '', $result);
}
