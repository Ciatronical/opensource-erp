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

    // Optional bis zu einem Stichtag saldieren (Default: alle Buchungen).
    // Saldo = Soll−Haben = -SUM(amount) (kivitendo-Vorzeichen). Subquery statt
    // JOIN, damit die taxkey-Zählung nicht durch acc_trans-Zeilen vervielfacht wird.
    $balCond = '';
    if (!empty($data['to_date'])) { $balCond = ' AND a.transdate <= :to_date'; $params[':to_date'] = $data['to_date']; }

    $accounts = $db->getAll(<<<SQL
        SELECT c.id, c.accno, c.description, c.charttype, c.category, c.invalid,
               c.taxkey_id,
               COUNT(tk.id) AS taxkey_count,
               (COUNT(tk.id) = 0 AND c.taxkey_id IS NOT NULL AND c.taxkey_id <> 0) AS taxkey_missing,
               (SELECT COALESCE(-SUM(a.amount), 0) FROM acc_trans a WHERE a.chart_id = c.id{$balCond}) AS balance,
               (SELECT COUNT(*) FROM acc_trans a WHERE a.chart_id = c.id) AS booking_count
        FROM chart c
        LEFT JOIN taxkeys tk ON tk.chart_id = c.id
        WHERE {$whereClause}
        GROUP BY c.id
        ORDER BY c.accno
    SQL, $params);

    resultInfo(true, '', ['accounts' => $accounts]);
}

/**
 * Einzelnes Konto mit Steuerschlüsseln und Folgekonto-Kandidaten laden
 *
 * Alles in einer Abfrage. Zusätzlich zu den Stammdaten:
 *  - orphaned:         Konto ist noch unbebucht, Kontentyp und Verknüpfung
 *                      dürfen geändert werden. Ein als Steuerkonto verlinktes
 *                      Konto, auf das ein Steuerschlüssel zeigt, gilt wie in
 *                      kivitendo auch ohne Buchung als gebunden.
 *  - new_chart_valid:  Das Folgekonto ist bereits aktiv (valid_from erreicht),
 *                      dann ist kein Wechsel mehr möglich.
 *  - new_accounts:     Mögliche Folgekonten — kivitendo bietet nur Konten mit
 *                      identischer Verknüpfung an, sonst würde die Umbuchung
 *                      in einer anderen Buchungsmaske landen.
 *
 * @param int $data['id'] Konto-ID (chart.id)
 * @testdata {"id": 170}
 */
function getChartAccount($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    $row = $db->getOne(<<<SQL
        SELECT json_build_object(
            'id',             c.id,
            'accno',          c.accno,
            'description',    c.description,
            'charttype',      c.charttype,
            'category',       c.category,
            'link',           c.link,
            'taxkey_id',      c.taxkey_id,
            'pos_bwa',        c.pos_bwa,
            'pos_eur',        c.pos_eur,
            'datevautomatik', c.datevautomatik,
            'invalid',        c.invalid,
            'new_chart_id',   c.new_chart_id,
            'valid_from',     TO_CHAR(c.valid_from, 'YYYY-MM-DD'),
            'new_chart_valid', (c.valid_from IS NOT NULL AND c.valid_from <= current_date),
            'orphaned', (
                    NOT EXISTS (SELECT 1 FROM acc_trans a WHERE a.chart_id = c.id)
                -- Ein Steuerkonto ist auch ohne Buchung gebunden, sobald ein
                -- Steuerschlüssel darauf zeigt (kivitendo, am.pl)
                AND NOT (c.link ~ '(AR_tax|AP_tax)'
                         AND EXISTS (SELECT 1 FROM tax t WHERE t.chart_id = c.id))
            ),
            'taxkeys', (
                SELECT COALESCE(json_agg(json_build_object(
                           'id',             tk.id,
                           'tax_id',         tk.tax_id,
                           'taxkey_id',      tk.taxkey_id,
                           'pos_ustva',      tk.pos_ustva,
                           'startdate',      TO_CHAR(tk.startdate, 'YYYY-MM-DD'),
                           'taxdescription', t.taxdescription,
                           'rate_percent',   t.rate * 100)
                        ORDER BY tk.startdate), '[]'::json)
                FROM taxkeys tk
                JOIN tax t ON t.id = tk.tax_id
                WHERE tk.chart_id = c.id
            ),
            'new_accounts', (
                SELECT COALESCE(json_agg(json_build_object(
                           'id', n.id, 'accno', n.accno, 'description', n.description)
                        ORDER BY n.accno), '[]'::json)
                FROM chart n
                WHERE n.link = c.link AND n.id <> c.id AND n.charttype = 'A'
            )
        ) AS chart
        FROM chart c
        WHERE c.id = :id
    SQL, [':id' => $id]);

    if (!$row) {
        throw new ApiError('DATA_NOT_FOUND', 'Konto nicht gefunden');
    }

    resultInfo(true, '', ['chart' => json_decode($row['chart'], true)]);
}

/**
 * Auswahllisten für den Konten-Dialog
 *
 * Liefert Steuerschlüssel, EÜR-Positionen, BWA-Positionen und UStVA-Kennzahlen
 * in einer einzigen Abfrage. Wird einmalig beim Öffnen der Konten-Seite geladen
 * und für alle Konten wiederverwendet. Die Bezeichnungen kommen aus der
 * Datenbank (eur_categories, bwa_categories, tax.report_variables) — im
 * Frontend wird nichts fest verdrahtet.
 *
 * @testdata {}
 */
function getChartAccountOptions($data) {
    $db = DbhCompany::begin();

    $row = $db->getOne(<<<SQL
        SELECT json_build_object(
            'taxes', (
                SELECT COALESCE(json_agg(json_build_object(
                           'id',             t.id,
                           'taxkey',         t.taxkey,
                           'rate_percent',   t.rate * 100,
                           'taxdescription', t.taxdescription,
                           'chart_accno',    tc.accno)
                        ORDER BY t.taxkey, t.rate), '[]'::json)
                FROM tax t
                LEFT JOIN chart tc ON tc.id = t.chart_id
            ),
            'eur', (
                SELECT COALESCE(json_agg(json_build_object(
                           'id', e.id, 'description', e.description)
                        ORDER BY e.id), '[]'::json)
                FROM eur_categories e
            ),
            'bwa', (
                SELECT COALESCE(json_agg(json_build_object(
                           'id', b.id, 'description', b.description)
                        ORDER BY b.id), '[]'::json)
                FROM bwa_categories b
            ),
            'ustva', (
                -- taxkeys.pos_ustva ist numerisch, die Pseudo-Position 'keine'
                -- entfaellt daher. Je Kennzahl die aussagekraeftigste Erlaeuterung.
                SELECT COALESCE(json_agg(json_build_object(
                           'id', u.position, 'description', u.description)
                        ORDER BY u.position), '[]'::json)
                FROM (
                    SELECT DISTINCT ON (v.position::int)
                           v.position::int AS position, v.description
                    FROM tax.report_variables v
                    WHERE v.position ~ '^[0-9]+$'
                    ORDER BY v.position::int, length(v.description) DESC
                ) u
            )
        ) AS options
    SQL);

    resultInfo(true, '', ['options' => json_decode($row['options'], true)]);
}

/**
 * Konto speichern (Grunddaten + zeitabhängige Steuerschlüssel)
 *
 * Legt fehlende taxkeys-Zeilen an, aktualisiert bestehende und löscht markierte.
 * chart.taxkey_id wird mit dem Steuerschlüssel der jüngsten taxkeys-Zeile
 * synchronisiert, damit der Standard-Steuerschlüssel des Kontos stimmt.
 *
 * Übernimmt die Schutzregeln aus kivitendo (SL/AM.pm::_save_account):
 *  - Kontentyp und Verknüpfung sind bei bebuchten Konten gesperrt
 *  - ein Sammelkonto (AR/AP/IC) darf in keiner Buchungsmaske auftauchen
 *  - Überschriften (charttype H) haben weder Verknüpfung noch Auswertungs-
 *    positionen noch Folgekonto
 *  - Kontonummer muss eindeutig sein
 *  - ein aktives Folgekonto (valid_from erreicht) ist nicht mehr änderbar
 *
 * @param int    $data['id']          Konto-ID
 * @param string $data['accno']       Kontonummer
 * @param string $data['description'] Beschreibung
 * @param string $data['charttype']   A = Konto, H = Überschrift
 * @param string $data['category']    Kontoart (C/A/L/Q/E/I)
 * @param bool   $data['invalid']     Konto ungültig
 * @param array  $data['link']        Kontenverknüpfung als Marker-Liste (AR_amount, IC_income, …)
 * @param bool   $data['datevautomatik'] DATEV-Automatikkonto
 * @param int    $data['pos_eur']     Position EÜR (eur_categories.id)
 * @param int    $data['pos_bwa']     Position BWA (bwa_categories.id)
 * @param int    $data['new_chart_id'] Folgekonto (chart.id), leer = keines
 * @param string $data['valid_from']  Folgekonto gültig ab (YYYY-MM-DD)
 * @param array  $data['taxkeys']     Steuerschlüssel-Zeilen [{id, tax_id, startdate, pos_ustva, delete}]
 * @testdata {"id": 170, "accno": "5425", "description": "Innergem.Erwerb 19% VorSt u. Ust", "charttype": "A", "category": "E", "link": ["AP_amount", "IC_cogs"], "datevautomatik": true, "new_chart_id": null, "valid_from": "", "taxkeys": [{"id": "NEW", "tax_id": 1174, "startdate": "1970-01-01", "pos_ustva": null}]}
 */
function saveChartAccount($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        throw new ApiError('INVALID_PARAMETER', 'Konto-ID fehlt');
    }

    $taxkeys = is_array($data['taxkeys'] ?? null) ? $data['taxkeys'] : [];

    // Kontonummer ohne Leerzeichen, Beschreibung ohne doppelte Leerzeichen —
    // wie in kivitendo, sonst laufen Kontensuche und DATEV-Export ins Leere
    $accno       = preg_replace('/\s+/u', '', (string)($data['accno'] ?? ''));
    $description = trim(preg_replace('/\h+/u', ' ', (string)($data['description'] ?? '')));
    $charttype   = ($data['charttype'] ?? 'A') === 'H' ? 'H' : 'A';
    $category    = ($data['category'] ?? '') !== '' ? $data['category'] : null;

    if ($accno === '') {
        throw new ApiError('INVALID_PARAMETER', 'Kontonummer fehlt');
    }
    if ($description === '') {
        throw new ApiError('INVALID_PARAMETER', 'Beschreibung fehlt');
    }

    // Bestand und Sperrgründe in einer Abfrage holen
    $state = $db->getOne(<<<SQL
        SELECT c.charttype, c.link, c.new_chart_id,
               TO_CHAR(c.valid_from, 'YYYY-MM-DD') AS valid_from,
               (c.valid_from IS NOT NULL AND c.valid_from <= current_date) AS new_chart_valid,
               (
                    NOT EXISTS (SELECT 1 FROM acc_trans a WHERE a.chart_id = c.id)
                AND NOT (c.link ~ '(AR_tax|AP_tax)'
                         AND EXISTS (SELECT 1 FROM tax t WHERE t.chart_id = c.id))
               ) AS orphaned,
               EXISTS (SELECT 1 FROM chart o WHERE o.accno = :accno AND o.id <> c.id) AS accno_taken
        FROM chart c
        WHERE c.id = :id
    SQL, [':id' => $id, ':accno' => $accno]);

    if (!$state) {
        throw new ApiError('DATA_NOT_FOUND', 'Konto nicht gefunden');
    }
    if (_chartBool($state['accno_taken'])) {
        throw new ApiError('INVALID_PARAMETER', 'Kontonummer ist bereits vergeben');
    }

    $orphaned = _chartBool($state['orphaned']);
    $link     = _chartLink($data['link'] ?? '');

    // Bebuchte Konten: Kontentyp und Verknüpfung bleiben, wie sie sind.
    // Sonst würden bereits gebuchte Belege in einer anderen Maske landen.
    if (!$orphaned) {
        if ($charttype !== $state['charttype']) {
            throw new ApiError('NOT_ALLOWED',
                'Der Kontentyp kann nicht geändert werden, weil auf das Konto bereits gebucht wurde');
        }
        if ($link !== _chartLink($state['link'])) {
            throw new ApiError('NOT_ALLOWED',
                'Die Kontenverknüpfung kann nicht geändert werden, weil auf das Konto bereits gebucht wurde');
        }
    }

    // Ein Sammelkonto sammelt die Gegenbuchungen und darf deshalb in keiner
    // Buchungsmaske einzeln auswählbar sein
    $summary  = array_intersect(['AR', 'AP', 'IC'], explode(':', $link));
    $dropdown = array_diff(explode(':', $link), ['AR', 'AP', 'IC', '']);
    if ($summary && $dropdown) {
        throw new ApiError('INVALID_PARAMETER',
            'Ein Sammelkonto darf nicht zusätzlich in Buchungsmasken angeboten werden');
    }

    $newChartId = _chartPosOrNull($data['new_chart_id'] ?? null);
    $validFrom  = trim((string)($data['valid_from'] ?? '')) ?: null;

    // Ein bereits aktives Folgekonto ist gesetzt — daran wird nicht mehr gerührt
    if (_chartBool($state['new_chart_valid'])) {
        $newChartId = _chartPosOrNull($state['new_chart_id']);
        $validFrom  = $state['valid_from'] ?: null;
    } else {
        if ($newChartId !== null && $newChartId === $id) {
            throw new ApiError('INVALID_PARAMETER', 'Das Konto kann nicht sein eigenes Folgekonto sein');
        }
        if ($newChartId !== null && $validFrom === null) {
            throw new ApiError('INVALID_PARAMETER', 'Ein Folgekonto braucht ein "Gültig ab"-Datum');
        }
        // Datum ohne Folgekonto hat keine Wirkung
        if ($newChartId === null) {
            $validFrom = null;
        }
    }

    // Überschriften tauchen in keiner Buchungsmaske und keiner Auswertung auf
    $isHeading = $charttype === 'H';
    $posEur    = $isHeading ? null : _chartPosOrNull($data['pos_eur'] ?? null);
    $posBwa    = $isHeading ? null : _chartPosOrNull($data['pos_bwa'] ?? null);
    if ($isHeading) {
        $link       = '';
        $newChartId = null;
        $validFrom  = null;
    }

    // Konten (keine Überschriften) brauchen eine Kontoart und mindestens einen
    // Steuerschlüssel mit Gültigkeitsdatum, sonst ist keine Steuer buchbar
    if (!$isHeading) {
        if ($category === null) {
            throw new ApiError('INVALID_PARAMETER', 'Kontoart fehlt');
        }
        $hasValidTaxkey = false;
        foreach ($taxkeys as $row) {
            if (empty($row['delete']) && trim((string)($row['startdate'] ?? '')) !== ''
                && intval($row['tax_id'] ?? 0) > 0) {
                $hasValidTaxkey = true;
                break;
            }
        }
        if (!$hasValidTaxkey) {
            throw new ApiError('INVALID_PARAMETER',
                'Das Konto braucht mindestens einen Steuerschlüssel mit "Gültig ab"-Datum');
        }
    }

    $db->beginTransaction();
    try {
        $db->execute(<<<SQL
            UPDATE chart SET
                accno       = :accno,
                description = :description,
                charttype   = :charttype,
                category    = :category,
                invalid     = :invalid,
                link           = :link,
                datevautomatik = :datevautomatik,
                pos_eur     = :pos_eur,
                pos_bwa     = :pos_bwa,
                new_chart_id = :new_chart_id,
                valid_from   = :valid_from,
                mtime       = now()
            WHERE id = :id
        SQL, [
            ':accno'       => $accno,
            ':description' => $description,
            ':charttype'   => $charttype,
            ':category'    => $category,
            ':invalid'     => !empty($data['invalid']),
            // chart.link ist NOT NULL — ohne Verknuepfung leerer String, nicht NULL
            ':link'           => $link,
            ':datevautomatik' => !empty($data['datevautomatik']),
            ':pos_eur'      => $posEur,
            ':pos_bwa'      => $posBwa,
            ':new_chart_id' => $newChartId,
            ':valid_from'   => $validFrom,
            ':id'           => $id,
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

/**
 * Hilfsfunktion: Kontenverknüpfung normalisieren.
 *
 * Akzeptiert ein Array (aus der Auswahl) oder den fertigen ':'-getrennten
 * String und lässt nur die in kivitendo gültigen Marker durch — ein Tippfehler
 * würde das Konto sonst aus allen Buchungsmasken entfernen.
 *
 * Die Reihenfolge ist die von kivitendo (SL/AM.pm, @link_order) und damit
 * unabhängig von der Eingabereihenfolge. Nur so lassen sich zwei
 * Verknüpfungen zuverlässig vergleichen (Schreibschutz, Folgekonto-Suche).
 */
function _chartLink($value) {
    static $order = [
        'AR', 'AR_amount', 'AR_tax', 'AR_paid',
        'AP', 'AP_amount', 'AP_tax', 'AP_paid',
        'IC', 'IC_sale', 'IC_cogs', 'IC_taxpart',
        'IC_income', 'IC_expense', 'IC_taxservice',
    ];

    $parts = is_array($value) ? $value : explode(':', (string)$value);
    $parts = array_filter(array_map('trim', $parts));

    return implode(':', array_values(array_intersect($order, $parts)));
}

/**
 * Hilfsfunktion: Boolean aus der Datenbank auswerten.
 *
 * PDO liefert je nach Treiber 't'/'f', '1'/'0' oder echte Booleans.
 */
function _chartBool($value) {
    return $value === true || $value === 1 || $value === '1' || $value === 't';
}
