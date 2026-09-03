<?php
// backend/api/banking/kasse.php
// Kassenbuch – arbeitet direkt auf den kivitendo-Standardtabellen
// (chart, gl, acc_trans, ar, ap). Es werden KEINE Zusatztabellen benötigt:
// das Kassenkonto wird aus dem Kontenplan erkannt (SKR03 = 1000, SKR04 = 1600
// oder ein beliebiges Aktivkonto mit AR_paid-Link, dessen Bezeichnung „Kasse"
// enthält). Beleg-Funktionen nutzen optionale Tabellen und schalten sich sauber
// ab, falls diese in der jeweiligen Firmen-DB nicht vorhanden sind.

// ── Hilfsfunktionen ──────────────────────────────────────────────────────────

/**
 * Ein Kassenkonto (chart-Zeile) anhand seiner chart.id laden.
 * Die chart.id dient gleichzeitig als „Kassenbuch-ID" nach aussen.
 */
function _kasse_loadRegister($db, $chartId) {
    return $db->getOne(
        "SELECT ch.id,
                ch.id          AS chart_id,
                ch.description AS name,
                ch.accno       AS chart_accno,
                ch.description AS chart_description,
                ch.link        AS chart_link
         FROM chart ch
         WHERE ch.id = :id",
        ['id' => $chartId]
    );
}

/**
 * Prüft, ob die optionalen Beleg-Tabellen in der aktuellen DB vorhanden sind.
 * Nur dann werden Beleg-Upload, -Verknüpfung und -Vorschau angeboten.
 */
function _kasse_documentsEnabled($db) {
    $r = $db->getOne(
        "SELECT to_regclass('public.cash_gl_documents')   AS link_tbl,
                to_regclass('public.accounting_documents') AS doc_tbl"
    );
    return !empty($r['link_tbl']) && !empty($r['doc_tbl']);
}

/**
 * Liefert eine WHERE-Bedingung, die ungültige Konten ausschliesst – aber nur,
 * wenn die Spalte chart.invalid in dieser DB existiert (ältere kivitendo-Schemas
 * kennen sie nicht). $alias ist das Tabellen-Alias von chart in der Query.
 */
function _kasse_invalidClause($db, $alias) {
    $r = $db->getOne(
        "SELECT 1 AS ok FROM information_schema.columns
         WHERE table_name = 'chart' AND column_name = 'invalid' LIMIT 1"
    );
    return $r ? "AND ({$alias}.invalid IS NULL OR {$alias}.invalid = false)" : '';
}

/**
 * Verfügbarer Kassenbestand für eine Ausgabe am Datum $date.
 *
 * Liefert den niedrigsten laufenden Tagesend-Saldo ab diesem Datum. Eine Ausgabe darf diesen Wert nicht
 * überschreiten, sonst würde die Kasse an diesem oder einem späteren Tag negativ.
 * Liegt das Datum nach allen Buchungen, ist es der aktuelle Gesamtbestand.
 */
/**
 * SQL-Ausdruck: Trägt der Link-Ausdruck $linkExpr den Token $token ('AR'/'AP')?
 *
 * chart.link ist eine ':'-getrennte Liste. Das Kontroll-Konto (Forderungen /
 * Verbindlichkeiten) trägt EXAKT den Token 'AR' bzw. 'AP'. Ein blosses
 * LIKE '%AR%' trifft auch 'AR_amount' (Erlös) und 'AR_tax' (Umsatzsteuer) —
 * genau daran sind Barzahlungen schon auf dem Erlöskonto gelandet statt die
 * Forderung auszugleichen. Gleiche Absicht wie der Token-Regex in matching.php,
 * hier ohne '$' geschrieben, damit der Ausdruck in PHP-Strings gefahrlos steht.
 *
 * @param string $linkExpr Spalte/Ausdruck mit dem Link (z. B. 'c2.link')
 * @param string $token    'AR' oder 'AP'
 * @return string SQL-Boolean-Ausdruck
 */
function _kasse_hasLinkToken($linkExpr, $token) {
    return "POSITION(':{$token}:' IN ':' || {$linkExpr} || ':') > 0";
}

function _kasse_availableBalance($db, $chartId, $date) {
    $row = $db->getOne("
        WITH daily AS (
            SELECT transdate, SUM(-amount) AS delta
            FROM acc_trans
            -- Ab dem letzten Saldenvortrag rechnen, ihn selbst eingeschlossen:
            -- er ist der Anfangsbestand des laufenden Buchungsjahres, alles
            -- davor steckt bereits darin.
            WHERE chart_id = :cid
              AND transdate >= (SELECT COALESCE(MAX(transdate), '-infinity'::date)
                                  FROM acc_trans
                                 WHERE chart_id = :cid2 AND ob_transaction IS TRUE)
            GROUP BY transdate
        ),
        cumulative AS (
            SELECT transdate, SUM(delta) OVER (ORDER BY transdate) AS bal FROM daily
        )
        SELECT COALESCE(
            (SELECT MIN(bal) FROM cumulative WHERE transdate >= :d),
            (SELECT bal FROM cumulative ORDER BY transdate DESC LIMIT 1),
            0
        ) AS available
    ", ['cid' => $chartId, 'cid2' => $chartId, 'd' => $date]);

    return floatval($row['available'] ?? 0);
}

// ── Kassenkonten ─────────────────────────────────────────────────────────────

/**
 * Kassakonten aus dem Kontenplan laden (Kontenrahmen-unabhängig)
 *
 * Ein Kassakonto ist ein Aktivkonto (category = 'A'), das Zahlungen aufnehmen
 * kann (link enthält AR_paid) und dessen Kontonummer eine Standard-Kassennummer
 * ist (1000 bei SKR03, 1600 bei SKR04) oder dessen Bezeichnung „Kasse" enthält.
 * So wird das richtige Konto unabhängig vom gewählten Kontenrahmen gefunden.
 *
 * @testdata {}
 */
function getCashRegisters($data) {
    $db = DbhCompany::begin();

    $invalidClause = _kasse_invalidClause($db, 'ch');
    $kassenkonto   = kassenkontoBedingung('ch');

    $registers = $db->getAll("
        SELECT
            ch.id,
            ch.description AS name,
            ch.id          AS chart_id,
            ch.accno       AS chart_accno,
            ch.description AS chart_description,
            -- kivitendo-Konvention: Soll = negativ, Haben = positiv.
            -- Geld in die Kasse (Soll) ist negativ → Bestand = -SUMME (immer positiv).
            -COALESCE(SUM(at.amount), 0) AS balance,
            -- Einnahmen = Geld rein = negative Buchungen (negiert positiv dargestellt)
            -COALESCE(SUM(
                CASE WHEN at.amount < 0
                    AND (at.ob_transaction IS NULL OR at.ob_transaction = false)
                    AND DATE_TRUNC('month', at.transdate) = DATE_TRUNC('month', CURRENT_DATE)
                THEN at.amount ELSE 0 END
            ), 0) AS income_this_month,
            -- Ausgaben = Geld raus = positive Buchungen
            COALESCE(SUM(
                CASE WHEN at.amount > 0
                    AND (at.ob_transaction IS NULL OR at.ob_transaction = false)
                    AND DATE_TRUNC('month', at.transdate) = DATE_TRUNC('month', CURRENT_DATE)
                THEN at.amount ELSE 0 END
            ), 0) AS expenses_this_month,
            MAX(at.transdate) AS last_transaction_date
        FROM chart ch
        -- Bezugspunkt jedes Kontos ist sein letzter Saldenvortrag. Alles davor
        -- ist abgeschlossen und steckt bereits im Vortrag; würde man es dazu
        -- addieren, zählte man dieselben Jahre zweimal. Genau so rechnet auch
        -- kivitendo (SL/CA.pm): Vortrag des Jahres plus Bewegungen des Jahres.
        LEFT JOIN LATERAL (
            SELECT COALESCE(MAX(o.transdate), '-infinity'::date) AS ab
            FROM acc_trans o
            WHERE o.chart_id = ch.id AND o.ob_transaction IS TRUE
        ) v ON true
        LEFT JOIN acc_trans at ON at.chart_id = ch.id AND at.transdate >= v.ab
        WHERE ch.link LIKE '%AR_paid%'
          AND ch.category  = 'A'
          AND ch.charttype = 'A'
          {$invalidClause}
          AND {$kassenkonto}
        GROUP BY ch.id, ch.description, ch.accno
        ORDER BY
            CASE WHEN ch.description ILIKE '%kasse%' THEN 0 ELSE 1 END,
            ch.accno
    ");

    resultInfo(true, '', ['registers' => $registers ?: []]);
}

/**
 * Gegenkonten für Kassenbuchungen laden
 *
 * Aufwand (E) und Ertrag (I) für normale Bareinnahmen/-ausgaben, zusätzlich
 * Transferkonten (category A): Geldtransit (Geld von/zur Bank bringen) sowie die
 * Bankkonten selbst. Kassenkonten werden als Transfer-Gegenkonto ausgeschlossen
 * (man bucht nicht gegen sich selbst) — welche das sind, sagt der Kontenrahmen.
 *
 * @param string $data['category'] E = Aufwand, I = Ertrag, all = alle (default: all)
 * @testdata {"category": "all"}
 */
function getCashCounterCharts($data) {
    $db = DbhCompany::begin();

    $category    = $data['category'] ?? 'all';
    $params      = [];
    $kassenkonto = kassenkontoBedingung('ch');

    if ($category === 'E' || $category === 'I') {
        $where         = 'AND ch.category = :cat';
        $params['cat'] = $category;
    } else {
        // Aufwand + Ertrag + Transferkonten (Geldtransit + Bankkonten, keine Kassen)
        $where = "AND (
            ch.category IN ('E', 'I')
            OR (
                ch.category = 'A' AND (
                    ch.description ILIKE '%geldtransit%'
                    OR (ch.link LIKE '%AR_paid%' AND NOT {$kassenkonto})
                )
            )
        )";
    }

    $invalidClause = _kasse_invalidClause($db, 'ch');

    $result = $db->getAll("
        SELECT id, accno, description, category
        FROM chart ch
        WHERE charttype = 'A'
          {$invalidClause}
          {$where}
        ORDER BY
            CASE ch.category WHEN 'I' THEN 0 WHEN 'E' THEN 1 ELSE 2 END,
            ch.accno
    ", $params);

    resultInfo(true, '', ['charts' => $result ?: []]);
}

/**
 * Nächste fortlaufende Belegnummer eines Kassenbuchs (Vorschlag für den Dialog)
 *
 * @param int    $data['cash_register_id'] Kassakonto-ID (= chart.id)
 * @param string $data['transdate']        Buchungsdatum (optional, bestimmt das Jahr)
 * @testdata {"cash_register_id": 1}
 */
function getNextCashBelegnummer($data) {
    $db = DbhCompany::begin();

    $registerId = intval($data['cash_register_id'] ?? 0);
    if ($registerId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Kassenbuch-ID fehlt');
        return;
    }

    $register = _kasse_loadRegister($db, $registerId);
    if (!$register) {
        resultInfo(false, 'NOT_FOUND', 'Kassenbuch nicht gefunden');
        return;
    }

    $transdate = !empty($data['transdate']) ? $data['transdate'] : date('Y-m-d');
    $beleg     = nextBelegnummer($db, $register['chart_id'], $transdate);

    resultInfo(true, '', ['belegnummer' => $beleg]);
}

/**
 * Buchungsvorschlag aus früheren Kassenbuchungen (ohne KI, reiner History-Lookup)
 *
 * Zwei Modi:
 *  - counter_chart_id gesetzt → letzte Beschreibung für dieses Gegenkonto.
 *  - amount gesetzt           → letzte (oder betragsähnlichste) frühere Buchung mit
 *                               diesem Betrag; liefert Typ, Gegenkonto und Buchungstext.
 *                               Exakter Betrag hat Vorrang; sonst die nächstliegende
 *                               Buchung innerhalb ±50 %.
 *
 * Beispiel: gestern 900 € zur Bank (Geldtransit) → heute 1200 € getippt erkennt
 * „wieder zur Bank"; 60 € für die Putzfrau letzte Woche → 60 € füllt alles vor.
 *
 * @param int    $data['cash_register_id'] Kassakonto-ID (= chart.id)
 * @param float  $data['amount']           Betrag (optional)
 * @param int    $data['counter_chart_id'] Gegenkonto-ID (optional)
 * @testdata {"cash_register_id": 1, "amount": 60}
 */
function getCashBookingSuggestion($data) {
    $db = DbhCompany::begin();

    $registerId = intval($data['cash_register_id'] ?? 0);
    if ($registerId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Kassenbuch-ID fehlt');
        return;
    }
    $register = _kasse_loadRegister($db, $registerId);
    if (!$register) {
        resultInfo(false, 'NOT_FOUND', 'Kassenbuch nicht gefunden');
        return;
    }
    $cashChart = intval($register['chart_id']);

    $counterChartId = intval($data['counter_chart_id'] ?? 0);
    $amount         = abs(floatval($data['amount'] ?? 0));

    // Basis: GL-Kassenbuchungen mit ihrem Gegenkonto (nur diese taugen als Vorlage —
    // sie haben einen freien Buchungstext und genau ein Gegenkonto).
    $base = "
        FROM acc_trans cash
        JOIN gl ON gl.id = cash.trans_id AND (gl.storno IS NULL OR gl.storno = false)
        JOIN LATERAL (
            SELECT at2.chart_id, c2.accno, c2.description
            FROM acc_trans at2
            JOIN chart c2 ON c2.id = at2.chart_id
            WHERE at2.trans_id = gl.id AND at2.chart_id <> cash.chart_id
            ORDER BY at2.acc_trans_id ASC
            LIMIT 1
        ) gegen ON true
        WHERE cash.chart_id = :cash
    ";

    $select = "
        SELECT gl.description,
               gegen.chart_id   AS counter_chart_id,
               gegen.accno      AS counter_accno,
               gegen.description AS counter_description,
               CASE WHEN cash.amount < 0 THEN 'income' ELSE 'expense' END AS type,
               ABS(cash.amount) AS amount
    ";

    // Modus 1: Beschreibung zum gewählten Gegenkonto
    if ($counterChartId > 0) {
        $row = $db->getOne("
            {$select}
            {$base} AND gegen.chart_id = :counter AND COALESCE(gl.description, '') <> ''
            ORDER BY gl.transdate DESC, gl.id DESC
            LIMIT 1
        ", ['cash' => $cashChart, 'counter' => $counterChartId]);
        resultInfo(true, '', ['suggestion' => $row ?: null]);
        return;
    }

    // Modus 2: nach Betrag — exakt, sonst betragsähnlichste Buchung (±50 %)
    if ($amount > 0) {
        $row = $db->getOne("
            {$select}
            {$base} AND ABS(cash.amount) = :amt
            ORDER BY gl.transdate DESC, gl.id DESC
            LIMIT 1
        ", ['cash' => $cashChart, 'amt' => $amount]);

        if (!$row) {
            $row = $db->getOne("
                {$select}
                {$base} AND ABS(ABS(cash.amount) - :amt) <= :tol
                ORDER BY ABS(ABS(cash.amount) - :amt2) ASC, gl.transdate DESC
                LIMIT 1
            ", ['cash' => $cashChart, 'amt' => $amount, 'amt2' => $amount, 'tol' => $amount * 0.5]);
        }
        resultInfo(true, '', ['suggestion' => $row ?: null]);
        return;
    }

    resultInfo(true, '', ['suggestion' => null]);
}

// ── Kassenbuch laden (Lexware-Stil mit laufendem Saldo) ───────────────────────

/**
 * Kassenbuch eines Kassakontos laden – Bewegungen aus gl + acc_trans + AR + AP,
 * mit Anfangsbestand, laufendem Saldo, Summen und Endbestand (wie bei Lexware).
 *
 * Die Liste ist chronologisch aufsteigend (älteste zuerst), jede Zeile trägt
 * den fortlaufenden Saldo. Der Anfangsbestand ist die Summe aller Bewegungen
 * vor dem Von-Datum (inkl. Eröffnungsbuchung); ohne Von-Datum ist er 0.
 *
 * @param int    $data['cash_register_id'] Kassakonto-ID (= chart.id)
 * @param string $data['from_date']        Von-Datum (optional, YYYY-MM-DD)
 * @param string $data['to_date']          Bis-Datum (optional, YYYY-MM-DD)
 * @param string $data['type_filter']      all|income|expense (default: all)
 * @testdata {"cash_register_id": 1}
 */
function getCashTransactions($data) {
    $db = DbhCompany::begin();

    $registerId = intval($data['cash_register_id'] ?? 0);
    if ($registerId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Kassenbuch-ID fehlt');
        return;
    }

    $register = _kasse_loadRegister($db, $registerId);
    if (!$register) {
        resultInfo(false, 'NOT_FOUND', 'Kassenbuch nicht gefunden');
        return;
    }

    $chartId     = intval($register['chart_id']);
    $docsEnabled = _kasse_documentsEnabled($db);

    $fromDate   = !empty($data['from_date']) ? $data['from_date'] : null;
    $toDate     = !empty($data['to_date'])   ? $data['to_date']   : null;
    $typeFilter = $data['type_filter'] ?? 'all';

    // ── Filter auf die Bewegungen (Zeitraum / Typ) ──
    $params     = [
        'chart_id' => $chartId, 'chart_id2' => $chartId, 'chart_id3' => $chartId,
        'ob_chart' => $chartId, 'ob_von' => $fromDate, 'ob_bis' => $toDate,
        'v_chart'  => $chartId, 'v_bis'  => $toDate,
    ];
    $whereExtra = '';
    if ($typeFilter === 'income') {
        $whereExtra .= ' AND m.amount > 0';
    } elseif ($typeFilter === 'expense') {
        $whereExtra .= ' AND m.amount < 0';
    }
    if ($fromDate) {
        $whereExtra .= ' AND m.transdate >= :from_date';
        $params['from_date'] = $fromDate;
    }
    if ($toDate) {
        $whereExtra .= ' AND m.transdate <= :to_date';
        $params['to_date'] = $toDate;
    }

    // ── Beleg-Spalten/Joins nur, wenn die Tabellen existieren ──
    //
    // Zwei Wege, wie ein Beleg an einer Kassenzeile hängt:
    //   manuelle Buchung  → Verknüpfungstabelle cash_gl_documents
    //   Barzahlung auf ER → accounting_documents.ap_id, also der Scan der
    //                       Eingangsrechnung selbst
    // Der zweite Weg fehlte bisher: die Spalten waren im AP-Zweig fest auf NULL
    // gesetzt, obwohl der Beleg längst in der Datenbank lag. Wer bar bezahlte,
    // sah in der Kasse keine Rechnung — für eine Prüfung wertlos.
    $leer       = "NULL::integer AS document_id, NULL::text AS document_name, NULL::text AS document_mime_type";
    $docCols    = $leer;
    $docJoin    = '';
    $docColsAp  = $leer;
    $docJoinAp  = '';
    $docColsAr  = $leer;
    $docJoinAr  = '';
    if ($docsEnabled) {
        $docCols = "cgd.document_id, ad.original_name AS document_name, ad.mime_type AS document_mime_type";
        $docJoin = "LEFT JOIN cash_gl_documents   cgd ON cgd.gl_id = gl.id
                    LEFT JOIN accounting_documents ad  ON ad.id   = cgd.document_id";

        // Zu einer Rechnung können mehrere Belege liegen (Rechnung, Lieferschein).
        // Der jüngste steht stellvertretend an der Zeile, deshalb LIMIT 1.
        $docColsAp = "apd.id AS document_id, apd.original_name AS document_name, apd.mime_type AS document_mime_type";
        $docJoinAp = "LEFT JOIN LATERAL (
                          SELECT d.id, d.original_name, d.mime_type
                          FROM accounting_documents d
                          WHERE d.ap_id = ap.id AND d.stored_path IS NOT NULL
                          ORDER BY d.id DESC LIMIT 1
                      ) apd ON true";

        // Ausgangsrechnung: das archivierte Versandexemplar. Die Spalte ar_id
        // kommt erst mit dem Schema-Update — ohne die Pruefung scheiterte das
        // Kassenbuch bis dahin an einer fehlenden Spalte.
        $hatArId = $db->getOne(
            "SELECT 1 AS ok FROM information_schema.columns
             WHERE table_name = 'accounting_documents' AND column_name = 'ar_id' LIMIT 1"
        );
        if ($hatArId) {
            $docColsAr = "ard.id AS document_id, ard.original_name AS document_name, ard.mime_type AS document_mime_type";
            $docJoinAr = "LEFT JOIN LATERAL (
                              SELECT d.id, d.original_name, d.mime_type
                              FROM accounting_documents d
                              WHERE d.ar_id = ar.id AND d.stored_path IS NOT NULL
                              ORDER BY d.id DESC LIMIT 1
                          ) ard ON true";
        }
    }

    // Rangfolge fürs Gegenkonto: Forderung/Verbindlichkeit schlägt Erlös/Aufwand.
    $arLinkToken = _kasse_hasLinkToken('c2.link', 'AR');
    $apLinkToken = _kasse_hasLinkToken('c2.link', 'AP');

    $result = $db->getOne("
        WITH vortrag AS (
            -- Bezugspunkt: der letzte Saldenvortrag bis zum Bis-Datum. Ab hier
            -- ist das Buch offen; alles davor ist abgeschlossen und im Vortrag
            -- enthalten. Ohne Vortrag reicht das Buch bis zur ersten Buchung.
            SELECT COALESCE(MAX(transdate), '-infinity'::date) AS ab
            FROM acc_trans
            WHERE chart_id = :v_chart AND ob_transaction IS TRUE
              AND (:v_bis::date IS NULL OR transdate <= :v_bis::date)
        ),
        eroeffnung AS (
            -- Anfangsbestand des Zeitraums.
            --
            -- Zwei Sorten Buchungen gehören hinein: alles vor dem Von-Datum, und
            -- die Saldenvorträge (ob_transaction) des Zeitraums selbst. Letztere
            -- sind der springende Punkt — ein Saldenvortrag vom 01.01. liegt
            -- INNERHALB des Jahres 2026 und wäre mit einer reinen Vorher-Abfrage
            -- nirgends erfasst: nicht im Anfangsbestand und nicht in den
            -- Bewegungen, aus denen er bewusst herausgehalten wird. Genau so ist
            -- er bisher verschwunden.
            --
            -- kivitendo-Konvention: Soll = negativ, Haben = positiv. Geld in der
            -- Kasse wird als negative Summe gespeichert → Bestand = -SUMME.
            SELECT COALESCE(-SUM(amount), 0) AS opening
            FROM acc_trans
            WHERE chart_id = :ob_chart
              AND transdate >= (SELECT ab FROM vortrag)
              AND ((:ob_von::date IS NOT NULL AND transdate < :ob_von::date)
                   OR ob_transaction IS TRUE)
              AND (:ob_bis::date IS NULL OR transdate <= :ob_bis::date)
        ),
        movements AS (
            -- ── 1. Manuelle GL-Buchungen (Bareinnahmen / Barausgaben) ──
            SELECT
                at.acc_trans_id,
                at.trans_id     AS gl_id,
                'gl'            AS source_type,
                at.transdate,
                -at.amount      AS amount,
                -- Belegnummer: bevorzugt acc_trans.source, sonst gl.reference (native kivitendo-Buchungen)
                COALESCE(NULLIF(at.source, ''), gl.reference) AS reference,
                gl.description,
                NULL::text      AS partner_name,
                gc.accno        AS gegenkonto_accno,
                gc.description  AS gegenkonto_description,
                {$docCols}
            FROM acc_trans at
            JOIN gl ON gl.id = at.trans_id
                AND (gl.storno IS NULL OR gl.storno = false)
            LEFT JOIN LATERAL (
                SELECT c2.accno, c2.description
                FROM acc_trans at2
                JOIN chart c2 ON c2.id = at2.chart_id
                WHERE at2.trans_id = at.trans_id
                  AND at2.chart_id != at.chart_id
                ORDER BY at2.acc_trans_id ASC
                LIMIT 1
            ) gc ON true
            {$docJoin}
            WHERE at.chart_id = :chart_id
              AND (at.ob_transaction IS NULL OR at.ob_transaction = false)

            UNION ALL

            -- ── 2. AR-Zahlungen (Kundenzahlungen bar) ──
            SELECT
                at.acc_trans_id,
                at.trans_id     AS gl_id,
                'ar'            AS source_type,
                at.transdate,
                -at.amount      AS amount,
                at.source       AS reference,
                ar.invnumber    AS description,
                c.name          AS partner_name,
                gc.accno        AS gegenkonto_accno,
                gc.description  AS gegenkonto_description,
                {$docColsAr}
            FROM acc_trans at
            -- Kein Storno-Filter auf ar/ap: das Kennzeichen storno gehört zur Rechnung,
            -- nicht zur Zahlung. Eine Barauszahlung, die auf einer Stornorechnung
            -- gebucht ist, ist echtes Geld, das die Lade verlassen hat — sie muss
            -- im Kassenbuch stehen. Bei gl ist es umgekehrt: dort bilden Buchung
            -- und Storno ein Paar, das sich aufhebt und beide Zeilen entfallen.
            JOIN ar ON ar.id = at.trans_id
            JOIN customer c ON c.id = ar.customer_id
            {$docJoinAr}
            -- Gegenkonto einer Barzahlung ist das Forderungskonto der Rechnung
            -- (Debitoren-Sammelkonto, z. B. 1200) — die Kasse gleicht die Forderung
            -- aus, sie bucht keinen Erlös. Ohne die Rangfolge gewänne je nach
            -- Buchungsroutine mal die Erlös-, mal die Forderungszeile.
            LEFT JOIN LATERAL (
                SELECT c2.accno, c2.description
                FROM acc_trans at2
                JOIN chart c2 ON c2.id = at2.chart_id
                WHERE at2.trans_id = at.trans_id
                  AND at2.chart_id != at.chart_id
                ORDER BY (CASE WHEN {$arLinkToken} THEN 0 ELSE 1 END), at2.acc_trans_id ASC
                LIMIT 1
            ) gc ON true
            WHERE at.chart_id = :chart_id2
              AND (at.ob_transaction IS NULL OR at.ob_transaction = false)
              AND NOT EXISTS (SELECT 1 FROM gl WHERE id = at.trans_id)

            UNION ALL

            -- ── 3. AP-Zahlungen (Lieferantenzahlungen bar) ──
            SELECT
                at.acc_trans_id,
                at.trans_id     AS gl_id,
                'ap'            AS source_type,
                at.transdate,
                -at.amount      AS amount,
                at.source       AS reference,
                ap.invnumber    AS description,
                v.name          AS partner_name,
                gc.accno        AS gegenkonto_accno,
                gc.description  AS gegenkonto_description,
                {$docColsAp}
            FROM acc_trans at
            JOIN ap ON ap.id = at.trans_id
            JOIN vendor v ON v.id = ap.vendor_id
            {$docJoinAp}
            -- Spiegelbild zu AR: das Verbindlichkeitskonto der Rechnung
            -- (Kreditoren-Sammelkonto, z. B. 1600/3300), nicht das Aufwandskonto.
            LEFT JOIN LATERAL (
                SELECT c2.accno, c2.description
                FROM acc_trans at2
                JOIN chart c2 ON c2.id = at2.chart_id
                WHERE at2.trans_id = at.trans_id
                  AND at2.chart_id != at.chart_id
                ORDER BY (CASE WHEN {$apLinkToken} THEN 0 ELSE 1 END), at2.acc_trans_id ASC
                LIMIT 1
            ) gc ON true
            WHERE at.chart_id = :chart_id3
              AND (at.ob_transaction IS NULL OR at.ob_transaction = false)
              AND NOT EXISTS (SELECT 1 FROM gl WHERE id = at.trans_id)
              AND NOT EXISTS (SELECT 1 FROM ar WHERE id = at.trans_id)
        ),
        filtered AS (
            SELECT m.* FROM movements m
            WHERE m.transdate >= (SELECT ab FROM vortrag) {$whereExtra}
        ),
        numbered AS (
            SELECT
                f.*,
                -- Reihenfolge = Belegnummer (= Buchungsreihenfolge), nicht acc_trans_id.
                -- Natürliche Sortierung: Zahlteil der Belegnummer (erlaubt Präfixe wie
                -- 'AR-291'); bei Gleichstand der volle Text, dann acc_trans_id als
                -- Fallback (nicht-numerische/fehlende Belege).
                (SELECT opening FROM eroeffnung) + SUM(f.amount) OVER (
                    ORDER BY f.transdate ASC,
                             COALESCE(NULLIF(regexp_replace(f.reference, '[^0-9]', '', 'g'), '')::numeric, 0) ASC,
                             f.reference ASC NULLS FIRST,
                             f.acc_trans_id ASC
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ) AS saldo
            FROM filtered f
        )
        SELECT
            json_agg(row_to_json(numbered) ORDER BY
                numbered.transdate DESC,
                COALESCE(NULLIF(regexp_replace(numbered.reference, '[^0-9]', '', 'g'), '')::numeric, 0) DESC,
                numbered.reference DESC NULLS LAST,
                numbered.acc_trans_id DESC
            ) AS transactions,
            COALESCE(SUM(CASE WHEN numbered.amount > 0 THEN numbered.amount ELSE 0 END), 0)      AS sum_income,
            ABS(COALESCE(SUM(CASE WHEN numbered.amount < 0 THEN numbered.amount ELSE 0 END), 0)) AS sum_expense,
            (SELECT opening FROM eroeffnung)                                                     AS opening_balance,
            (SELECT opening FROM eroeffnung) + COALESCE(SUM(numbered.amount), 0)                 AS closing_balance
        FROM numbered
    ", $params);

    resultInfo(true, '', [
        'transactions'    => json_decode($result['transactions'] ?? '[]', true) ?: [],
        'opening_balance' => floatval($result['opening_balance'] ?? 0),
        'closing_balance' => floatval($result['closing_balance'] ?? 0),
        'sum_income'      => floatval($result['sum_income'] ?? 0),
        'sum_expense'     => floatval($result['sum_expense'] ?? 0),
        'documents_enabled' => $docsEnabled,
        'register'        => [
            'chart_accno'       => $register['chart_accno'],
            'chart_description' => $register['chart_description'],
        ],
    ]);
}

/**
 * Kassenbuch als PDF drucken — Aufbau und Spalten wie in Lexware.
 *
 * Ein Kassenbuch ist ein steuerlich relevanter Ausdruck: es muss lückenlos und
 * nachvollziehbar sein. Deshalb aufsteigend nach Datum, mit laufender Nummer,
 * Übertrag (Anfangsbestand) als erster Zeile, Bestand in jeder Zeile und
 * Endbestand plus Unterschriftsfeld am Schluss.
 *
 * @param int    $data['cash_register_id'] Kassakonto-ID (= chart.id)
 * @param string $data['from_date']        Von-Datum (optional)
 * @param string $data['to_date']          Bis-Datum (optional)
 * @param string $data['period_label']     Beschriftung des Zeitraums (optional)
 * @testdata {"cash_register_id": 1, "from_date": "2026-01-01", "to_date": "2026-12-31"}
 */
function getCashbookPdf($data) {
    $db = DbhCompany::begin();

    $registerId = intval($data['cash_register_id'] ?? 0);
    if ($registerId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Kassenbuch-ID fehlt'); return; }

    $from = !empty($data['from_date']) ? $data['from_date'] : null;
    $to   = !empty($data['to_date'])   ? $data['to_date']   : null;

    // Eine Abfrage fuer alles: Kassenkopf, Anfangsbestand, alle Bewegungen mit
    // laufender Nummer und laufendem Bestand sowie die Summen. Der Umsatzsteuer-
    // satz kommt aus der Gegenbuchung, denn dort hängt der Steuerschlüssel.
    $arLinkToken = _kasse_hasLinkToken('c2.link', 'AR');
    $apLinkToken = _kasse_hasLinkToken('c2.link', 'AP');

    $report = $db->getOne(<<<SQL
        WITH p AS (
            SELECT :chart_id::int AS cid, :von::date AS von, :bis::date AS bis
        ),
        kasse AS (
            SELECT ch.id, ch.accno, ch.description
            FROM chart ch CROSS JOIN p WHERE ch.id = p.cid
        ),
        vortrag AS (
            -- Siehe getCashTransactions: ab dem letzten Saldenvortrag ist das
            -- Buch offen, alles davor steckt bereits in ihm.
            SELECT COALESCE(MAX(at.transdate), '-infinity'::date) AS ab
            FROM acc_trans at CROSS JOIN p
            WHERE at.chart_id = p.cid AND at.ob_transaction IS TRUE
              AND (p.bis IS NULL OR at.transdate <= p.bis)
        ),
        eroeffnung AS (
            -- Anfangsbestand: alles vor dem Von-Datum plus die Saldenvorträge des
            -- Zeitraums selbst. Ein Vortrag vom 01.01. liegt innerhalb des Jahres
            -- und käme sonst weder hier noch in den Bewegungen vor.
            -- kivitendo: Soll = negativ. Der Bestand ist daher -SUMME.
            SELECT COALESCE(-SUM(at.amount), 0) AS opening
            FROM acc_trans at CROSS JOIN p
            WHERE at.chart_id = p.cid
              AND at.transdate >= (SELECT ab FROM vortrag)
              AND ((p.von IS NOT NULL AND at.transdate < p.von)
                   OR at.ob_transaction IS TRUE)
              AND (p.bis IS NULL OR at.transdate <= p.bis)
        ),
        movements AS (
            SELECT at.acc_trans_id, at.transdate, -at.amount AS amount,
                   COALESCE(NULLIF(at.source, ''), gl.reference) AS reference,
                   gl.description                               AS description,
                   NULL::text                                   AS partner_name,
                   gk.accno                                     AS gegenkonto_accno,
                   gk.description                               AS gegenkonto_description,
                   gk.tax_rate                                  AS tax_rate
            FROM acc_trans at
            CROSS JOIN p
            JOIN gl ON gl.id = at.trans_id AND (gl.storno IS NULL OR gl.storno = false)
            LEFT JOIN LATERAL (
                SELECT c2.accno, c2.description, COALESCE(tx.rate, 0) AS tax_rate
                FROM acc_trans at2
                JOIN chart c2 ON c2.id = at2.chart_id
                LEFT JOIN tax tx ON tx.id = at2.tax_id
                WHERE at2.trans_id = at.trans_id AND at2.chart_id != at.chart_id
                ORDER BY at2.acc_trans_id ASC LIMIT 1
            ) gk ON true
            WHERE at.chart_id = p.cid
              AND (at.ob_transaction IS NULL OR at.ob_transaction = false)

            UNION ALL

            SELECT at.acc_trans_id, at.transdate, -at.amount AS amount,
                   at.source, ar.invnumber, c.name,
                   gk.accno, gk.description, 0::numeric
            FROM acc_trans at
            CROSS JOIN p
            -- Kein Storno-Filter auf ar/ap: das Kennzeichen storno gehört zur Rechnung,
            -- nicht zur Zahlung. Eine Barauszahlung, die auf einer Stornorechnung
            -- gebucht ist, ist echtes Geld, das die Lade verlassen hat — sie muss
            -- im Kassenbuch stehen. Bei gl ist es umgekehrt: dort bilden Buchung
            -- und Storno ein Paar, das sich aufhebt und beide Zeilen entfallen.
            JOIN ar ON ar.id = at.trans_id
            JOIN customer c ON c.id = ar.customer_id
            -- Gegenkonto einer Barzahlung ist das Forderungskonto der Rechnung
            -- (Debitoren-Sammelkonto, z. B. 1200) — die Kasse gleicht die Forderung
            -- aus, sie bucht keinen Erlös. Ohne die Rangfolge gewänne je nach
            -- Buchungsroutine mal die Erlös-, mal die Forderungszeile.
            LEFT JOIN LATERAL (
                SELECT c2.accno, c2.description
                FROM acc_trans at2
                JOIN chart c2 ON c2.id = at2.chart_id
                WHERE at2.trans_id = at.trans_id AND at2.chart_id != at.chart_id
                ORDER BY (CASE WHEN {$arLinkToken} THEN 0 ELSE 1 END), at2.acc_trans_id ASC LIMIT 1
            ) gk ON true
            WHERE at.chart_id = p.cid
              AND (at.ob_transaction IS NULL OR at.ob_transaction = false)
              AND NOT EXISTS (SELECT 1 FROM gl WHERE id = at.trans_id)

            UNION ALL

            SELECT at.acc_trans_id, at.transdate, -at.amount AS amount,
                   at.source, ap.invnumber, v.name,
                   gk.accno, gk.description, 0::numeric
            FROM acc_trans at
            CROSS JOIN p
            JOIN ap ON ap.id = at.trans_id
            JOIN vendor v ON v.id = ap.vendor_id
            -- Spiegelbild zu AR: das Verbindlichkeitskonto der Rechnung
            -- (Kreditoren-Sammelkonto, z. B. 1600/3300), nicht das Aufwandskonto.
            LEFT JOIN LATERAL (
                SELECT c2.accno, c2.description
                FROM acc_trans at2
                JOIN chart c2 ON c2.id = at2.chart_id
                WHERE at2.trans_id = at.trans_id AND at2.chart_id != at.chart_id
                ORDER BY (CASE WHEN {$apLinkToken} THEN 0 ELSE 1 END), at2.acc_trans_id ASC LIMIT 1
            ) gk ON true
            WHERE at.chart_id = p.cid
              AND (at.ob_transaction IS NULL OR at.ob_transaction = false)
              AND NOT EXISTS (SELECT 1 FROM gl WHERE id = at.trans_id)
              AND NOT EXISTS (SELECT 1 FROM ar WHERE id = at.trans_id)
        ),
        filtered AS (
            SELECT m.* FROM movements m CROSS JOIN p
            WHERE m.transdate >= (SELECT ab FROM vortrag)
              AND (p.von IS NULL OR m.transdate >= p.von)
              AND (p.bis IS NULL OR m.transdate <= p.bis)
        ),
        numbered AS (
            SELECT f.*,
                   ROW_NUMBER() OVER w                                       AS lfd,
                   (SELECT opening FROM eroeffnung)
                       + SUM(f.amount) OVER (w ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS saldo
            FROM filtered f
            WINDOW w AS (
                ORDER BY f.transdate ASC,
                         COALESCE(NULLIF(regexp_replace(f.reference, '[^0-9]', '', 'g'), '')::numeric, 0) ASC,
                         f.reference ASC NULLS FIRST,
                         f.acc_trans_id ASC
            )
        )
        SELECT
            (SELECT accno       FROM kasse)      AS chart_accno,
            (SELECT description FROM kasse)      AS chart_description,
            (SELECT company     FROM defaults)   AS company,
            (SELECT opening     FROM eroeffnung) AS opening,
            COALESCE((SELECT opening FROM eroeffnung), 0)
                + COALESCE(SUM(n.amount), 0)                                AS closing,
            COALESCE(SUM(n.amount) FILTER (WHERE n.amount > 0), 0)          AS sum_income,
            ABS(COALESCE(SUM(n.amount) FILTER (WHERE n.amount < 0), 0))     AS sum_expense,
            COUNT(n.*)::int                                                 AS row_count,
            COALESCE(json_agg(row_to_json(n) ORDER BY n.lfd ASC)
                     FILTER (WHERE n.lfd IS NOT NULL), '[]')                AS rows
        FROM numbered n
    SQL, ['chart_id' => $registerId, 'von' => $from, 'bis' => $to]);

    if (!$report || $report['chart_accno'] === null) {
        resultInfo(false, 'NOT_FOUND', 'Kassenbuch nicht gefunden');
        return;
    }

    $rows    = json_decode($report['rows'] ?? '[]', true) ?: [];
    $opening = (float)($report['opening'] ?? 0);

    require_once __DIR__ . '/../lib/report_pdf.php';

    $zeitraum = trim((string)($data['period_label'] ?? ''));
    if ($zeitraum === '') {
        $zeitraum = $from || $to
            ? ($from ? _bu_datum($from) : 'Beginn') . ' bis ' . ($to ? _bu_datum($to) : 'heute')
            : 'gesamter Buchungsbestand';
    }

    $pdf = new ReportPdf('P', 'mm', 'A4');
    $pdf->reportTitle = 'Kassenbuch';
    $pdf->reportLines = array_filter([
        $report['company'] ?? '',
        'Kasse: ' . ($report['chart_description'] ?? '') . ' (Konto ' . ($report['chart_accno'] ?? '') . ')',
        'Zeitraum: ' . $zeitraum,
    ]);
    $pdf->columns = [
        ['w' =>  8, 'label' => 'Nr.',           'align' => 'R'],
        ['w' => 17, 'label' => 'Datum'],
        ['w' => 18, 'label' => 'Beleg-Nr.'],
        ['w' => 44, 'label' => 'Buchungstext'],
        ['w' => 32, 'label' => 'Gegenkonto'],
        ['w' => 10, 'label' => 'USt %',    'align' => 'R'],
        ['w' => 17, 'label' => 'Einnahme', 'align' => 'R'],
        ['w' => 17, 'label' => 'Ausgabe',  'align' => 'R'],
        ['w' => 17, 'label' => 'Bestand',  'align' => 'R'],
    ];
    $pdf->footNote = 'Erstellt am ' . date('d.m.Y H:i') . ' · ' . ($report['company'] ?? '');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 26); // Platz für Fußzeile und Unterschriftsfeld
    $pdf->AliasNbPages();
    $pdf->AddPage();

    $pdf->row([
        '', ($from ? _bu_datum($from) : ''), '',
        ['text' => 'Übertrag / Anfangsbestand', 'bold' => true],
        '', '', '', '',
        ['text' => ReportPdf::money($opening), 'bold' => true],
    ], false);

    foreach ($rows as $row) {
        $amount = (float)$row['amount'];
        $text   = trim((string)($row['description'] ?? ''));
        if (!empty($row['partner_name'])) {
            $text = $text === '' ? $row['partner_name'] : $text . ' · ' . $row['partner_name'];
        }
        if ($text === '') $text = $amount >= 0 ? 'Bareinnahme' : 'Barausgabe';

        $taxRate = (float)($row['tax_rate'] ?? 0);

        $pdf->row([
            (string)$row['lfd'],
            _bu_datum($row['transdate']),
            $row['reference'] ?: '',
            ['text' => $text, 'wrap' => true, 'maxLines' => 2],
            trim(($row['gegenkonto_accno'] ?? '') . ' ' . ($row['gegenkonto_description'] ?? '')),
            $taxRate > 0 ? ReportPdf::money($taxRate * 100) : '',
            ReportPdf::money($amount > 0 ? $amount : 0, true),
            ReportPdf::money($amount < 0 ? -$amount : 0, true),
            ReportPdf::money($row['saldo']),
        ]);
    }

    if (!$rows) {
        $pdf->row(['', '', '', 'Keine Buchungen im gewählten Zeitraum.', '', '', '', '', '']);
    }

    $pdf->totalRow([
        '', '', '',
        'Summe (' . (int)($report['row_count'] ?? 0) . ' Buchungen)',
        '', '',
        ['text' => ReportPdf::money($report['sum_income']  ?? 0), 'align' => 'R'],
        ['text' => ReportPdf::money($report['sum_expense'] ?? 0), 'align' => 'R'],
        ['text' => ReportPdf::money($report['closing']     ?? 0), 'align' => 'R'],
    ]);

    // Unterschriftsfeld: ein Kassenbuch wird abgezeichnet, nicht nur abgelegt.
    $pdf->Ln(10);
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(85, 5, ReportPdf::de('Kassenbestand laut Zählung: ______________________'), 0, 0);
    $pdf->Cell(0,  5, ReportPdf::de('Datum, Unterschrift: ______________________________'), 0, 1);
    $pdf->SetTextColor(0, 0, 0);

    resultInfo(true, '', [
        'filename' => 'kassenbuch_' . ($report['chart_accno'] ?? '') . '_' . date('Y-m-d') . '.pdf',
        'mime'     => 'application/pdf',
        'data'     => base64_encode($pdf->Output('S')),
    ]);
}

// ── Manuelle Kassenbuchung anlegen ────────────────────────────────────────────

/**
 * Manuelle Kassenbuchung anlegen (gl + acc_trans, kivitendo-kompatibel)
 *
 * @param int    $data['cash_register_id']   Kassakonto-ID (= chart.id)
 * @param string $data['transdate']          Buchungsdatum (YYYY-MM-DD)
 * @param float  $data['amount']             Betrag (immer positiv – Typ bestimmt das Vorzeichen)
 * @param string $data['type']               income|expense
 * @param int    $data['counter_chart_id']   Gegenkonto-ID aus getCashCounterCharts
 * @param string $data['description']        Buchungstext (optional)
 * @param string $data['reference']          Belegnummer (optional)
 * @param int    $data['document_id']        Beleg-ID aus uploadCashDocument (optional)
 * @testdata {"cash_register_id": 1, "transdate": "2026-06-04", "amount": 50.00, "type": "expense", "counter_chart_id": 1, "description": "Büromaterial", "reference": "BE-2026-001"}
 */
function createCashTransaction($data) {
    $db = DbhCompany::begin();

    $registerId     = intval($data['cash_register_id'] ?? 0);
    $counterChartId = intval($data['counter_chart_id'] ?? 0);
    $amount         = abs(floatval($data['amount'] ?? 0));
    $type           = $data['type'] ?? 'expense';

    if ($registerId <= 0)     { resultInfo(false, 'VALIDATION_ERROR', 'Kassenbuch-ID fehlt'); return; }
    if ($counterChartId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Gegenkonto fehlt'); return; }
    if ($amount <= 0)         { resultInfo(false, 'VALIDATION_ERROR', 'Betrag muss größer 0 sein'); return; }

    $register = _kasse_loadRegister($db, $registerId);
    if (!$register) { resultInfo(false, 'NOT_FOUND', 'Kassenbuch nicht gefunden'); return; }

    $counterChart = $db->getOne(
        "SELECT id, accno, description, link FROM chart WHERE id = :id",
        ['id' => $counterChartId]
    );
    if (!$counterChart) { resultInfo(false, 'NOT_FOUND', 'Gegenkonto nicht gefunden'); return; }

    $transdate   = $data['transdate'] ?? date('Y-m-d');
    $description = trim($data['description'] ?? '') ?: null;
    $reference   = trim($data['reference'] ?? '');
    $documentId  = intval($data['document_id'] ?? 0) ?: null;
    $employeeId  = mitarbeiterId($data);

    // Fortlaufende Belegnummer automatisch vergeben, falls keine angegeben wurde
    // (Feld ist im Dialog gesperrt; nur bei manueller Bearbeitung kommt ein Wert).
    if ($reference === '') {
        $reference = nextBelegnummer($db, $register['chart_id'], $transdate);
    }

    // Kasse darf nie ins Minus: Ausgabe nur buchen, wenn sie gedeckt ist.
    if ($type === 'expense') {
        $available = _kasse_availableBalance($db, $register['chart_id'], $transdate);
        if ($amount > $available + 0.005) {
            resultInfo(false,
                'Diese Buchung würde die Kasse ins Minus bringen. Verfügbar am '
                . date('d.m.Y', strtotime($transdate)) . ': '
                . number_format($available, 2, ',', '.') . ' €, benötigt: '
                . number_format($amount, 2, ',', '.') . ' €.',
                ['code' => 'NEGATIVE_CASH', 'available' => $available]
            );
            return;
        }
    }

    // Vorzeichen (kivitendo-Konvention: Soll = negativ, Haben = positiv):
    // income  → Geld kommt in die Kasse (Soll)  → Kasse negativ, Gegenkonto (Ertrag) positiv
    // expense → Geld verlässt die Kasse (Haben) → Kasse positiv, Gegenkonto (Aufwand) negativ
    $kasseAmount   = $type === 'expense' ? $amount : -$amount;
    $counterAmount = -$kasseAmount;

    // GL-Kopf
    $glRow = $db->getOne(
        "INSERT INTO gl (reference, description, transdate, gldate, employee_id)
         VALUES (:reference, :description, :transdate, :transdate, :employee_id)
         RETURNING id",
        [
            'reference'   => $reference,
            'description' => $description ?? ($type === 'expense' ? 'Barausgabe' : 'Bareinnahme'),
            'transdate'   => $transdate,
            'employee_id' => $employeeId,
        ]
    );
    $glId = $glRow['id'];

    // acc_trans: Kassenkonto
    $db->execute(
        "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, chart_link, tax_id, taxkey)
         VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :chart_link, 0, 0)",
        [
            'trans_id'   => $glId,
            'chart_id'   => $register['chart_id'],
            'amount'     => $kasseAmount,
            'transdate'  => $transdate,
            'source'     => $reference ?? 'Kasse',
            'chart_link' => $register['chart_link'],
        ]
    );

    // acc_trans: Gegenkonto
    $db->execute(
        "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, chart_link, tax_id, taxkey)
         VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :chart_link, 0, 0)",
        [
            'trans_id'   => $glId,
            'chart_id'   => $counterChartId,
            'amount'     => $counterAmount,
            'transdate'  => $transdate,
            'source'     => $reference ?? 'Kasse',
            'chart_link' => $counterChart['link'] ?? '',
        ]
    );

    // Beleg verknüpfen (nur falls Beleg-Tabellen vorhanden)
    if ($documentId && _kasse_documentsEnabled($db)) {
        $db->execute(
            "INSERT INTO cash_gl_documents (gl_id, document_id) VALUES (:gl_id, :doc_id)",
            ['gl_id' => $glId, 'doc_id' => $documentId]
        );
    }

    resultInfo(true, '', ['gl_id' => $glId]);
}

/**
 * Manuelle GL-Kassenbuchung löschen
 *
 * @param int $data['gl_id'] GL-ID der Buchung
 * @testdata {"gl_id": 1}
 */
function deleteCashTransaction($data) {
    $db = DbhCompany::begin();

    $glId = intval($data['gl_id'] ?? 0);
    if ($glId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'gl_id fehlt');
        return;
    }

    // Nur GL-Buchungen löschen (AR/AP-Zahlungen laufen über den Rechnungs-Workflow)
    $gl = $db->getOne("SELECT id FROM gl WHERE id = :id AND (storno IS NULL OR storno = false)", ['id' => $glId]);
    if (!$gl) {
        resultInfo(false, 'NOT_FOUND', 'GL-Buchung nicht gefunden');
        return;
    }

    $db->execute("DELETE FROM acc_trans WHERE trans_id = :id", ['id' => $glId]);
    if (_kasse_documentsEnabled($db)) {
        $db->execute("DELETE FROM cash_gl_documents WHERE gl_id = :id", ['id' => $glId]);
    }
    $db->execute("DELETE FROM gl WHERE id = :id", ['id' => $glId]);

    resultInfo(true, '', []);
}

// ── AR-Rechnung als Barzahlung buchen ─────────────────────────────────────────

/**
 * Offene Ausgangsrechnungen für Barzahlung laden
 *
 * Neueste zuerst: bar kassiert wird in aller Regel die eben geschriebene
 * Rechnung, nicht die älteste offene.
 *
 * Eine Suche greift bewusst über alle Zeiträume hinweg. Wer eine Rechnungsnummer
 * eintippt, sucht genau diese Rechnung — und die ist oft gerade deshalb nicht
 * im eingestellten Monat, weil sie liegengeblieben ist.
 *
 * @param string $data['search']    Suchbegriff: Rechnungsnr. oder Kundenname (optional)
 * @param string $data['from_date'] Frühestes Rechnungsdatum (optional)
 * @param string $data['to_date']   Spätestes Rechnungsdatum (optional)
 * @testdata {"from_date": "2026-08-01", "to_date": "2026-08-31"}
 */
function getOpenArForCash($data) {
    $db = DbhCompany::begin();

    $search = trim($data['search'] ?? '');
    $from   = $search === '' && !empty($data['from_date']) ? $data['from_date'] : null;
    $to     = $search === '' && !empty($data['to_date'])   ? $data['to_date']   : null;

    $result = $db->getAll("
        SELECT
            ar.id,
            ar.invnumber,
            ar.transdate,
            ar.duedate,
            ar.amount,
            ar.paid,
            (ar.amount - ar.paid) AS open_amount,
            c.name AS customer_name
        FROM ar
        JOIN customer c ON c.id = ar.customer_id
        WHERE ar.amount > ar.paid
          AND ar.storno IS NOT TRUE
          AND (:search::text IS NULL OR ar.invnumber ILIKE :search OR c.name ILIKE :search)
          AND (:from::date   IS NULL OR ar.transdate >= :from::date)
          AND (:to::date     IS NULL OR ar.transdate <= :to::date)
        ORDER BY ar.transdate DESC NULLS LAST, ar.id DESC
        LIMIT 100
    ", [
        'search' => $search === '' ? null : '%' . $search . '%',
        'from'   => $from,
        'to'     => $to,
    ]);

    resultInfo(true, '', ['invoices' => $result ?: []]);
}

/**
 * Ausgangsrechnung als Barzahlung buchen (acc_trans + ar.paid, kivitendo-kompatibel)
 *
 * @param int    $data['cash_register_id'] Kassakonto-ID (= chart.id)
 * @param int    $data['ar_id']            Ausgangsrechnung-ID
 * @param float  $data['amount']           Betrag (optional; default = offener Betrag)
 * @param string $data['transdate']        Buchungsdatum (optional)
 * @param int    $data['document_id']      Beleg-ID (optional)
 * @testdata {"cash_register_id": 1, "ar_id": 1}
 */
function bookArAsCash($data) {
    $db = DbhCompany::begin();

    $registerId = intval($data['cash_register_id'] ?? 0);
    $arId       = intval($data['ar_id'] ?? 0);

    if ($registerId <= 0 || $arId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'cash_register_id und ar_id erforderlich');
        return;
    }

    $register = _kasse_loadRegister($db, $registerId);
    if (!$register) { resultInfo(false, 'NOT_FOUND', 'Kassenbuch nicht gefunden'); return; }

    $ar = $db->getOne(
        "SELECT id, invnumber, amount, paid FROM ar WHERE id = :id AND storno IS NOT TRUE",
        ['id' => $arId]
    );
    if (!$ar) { resultInfo(false, 'NOT_FOUND', 'Ausgangsrechnung nicht gefunden'); return; }

    $openAmount = floatval($ar['amount']) - floatval($ar['paid']);
    $payAmount  = isset($data['amount']) ? min(abs(floatval($data['amount'])), $openAmount) : $openAmount;

    if ($payAmount <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Rechnung bereits vollständig bezahlt');
        return;
    }

    $transdate = $data['transdate'] ?? date('Y-m-d');

    // Forderungskonto der AR-Erstbuchung ermitteln (z. B. 1200/1400)
    $tokenLink = _kasse_hasLinkToken('chart_link', 'AR');
    $counterChart = $db->getOne("
        SELECT chart_id, chart_link
        FROM acc_trans
        WHERE trans_id = :tid
          AND {$tokenLink}
        ORDER BY acc_trans_id ASC
        LIMIT 1
    ", ['tid' => $arId]);

    if (!$counterChart) {
        resultInfo(false, 'DATA_ERROR', 'Forderungskonto der Rechnung nicht ermittelbar');
        return;
    }

    // Fortlaufende Belegnummer für diese Kassenbuchung
    $beleg = nextBelegnummer($db, $register['chart_id'], $transdate);

    // Klammer um die drei Schreibvorgänge: zwei acc_trans-Zeilen und ar.paid
    // gehören zusammen. Bricht es dazwischen ab, stünde eine halbe Zahlung in
    // den Büchern — genau die Sorte Buchung, die später als Differenz zwischen
    // Soll und Haben in der Saldenliste auftaucht und niemand mehr zuordnen kann.
    $db->beginTransaction();
    try {

        // Vorzeichen exakt wie matching.php / kivitendo (Soll = negativ, Haben = positiv):
        //   Forderungskonto: positiver Betrag (Haben) → klärt die Forderung, zählt für ar.paid
        //   Kassenkonto:     negativer Betrag (Soll)  → Geld kommt rein; chart_link 'AR_paid'
        //                    wird von der Faktura als Zahlung erkannt; source = Belegnummer.
        $db->execute(
            "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, tax_id, taxkey)
             VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :memo, :chart_link, 0, 0)",
            [
                'trans_id'   => $arId,
                'chart_id'   => $counterChart['chart_id'],
                'amount'     => $payAmount,
                'transdate'  => $transdate,
                'source'     => $ar['invnumber'],
                'memo'       => 'Barzahlung Kasse',
                'chart_link' => $counterChart['chart_link'] ?? 'AR',
            ]
        );

        $db->execute(
            "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, tax_id, taxkey)
             VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :memo, :chart_link, 0, 0)",
            [
                'trans_id'   => $arId,
                'chart_id'   => $register['chart_id'],
                'amount'     => -$payAmount,
                'transdate'  => $transdate,
                'source'     => $beleg,
                'memo'       => 'Barzahlung Kasse',
                'chart_link' => $register['chart_link'],
            ]
        );

        // ar.paid erhöhen
        $db->execute(
            "UPDATE ar SET paid = COALESCE(paid, 0) + :inc WHERE id = :id",
            ['inc' => $payAmount, 'id' => $arId]
        );

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    resultInfo(true, '', [
        'booked_amount' => $payAmount,
        'beleg'         => $beleg,
        'invnumber'     => $ar['invnumber'],
        'open_after'    => round($openAmount - $payAmount, 2),
    ]);
}

// ── Eingangsrechnung (AP) als Barzahlung buchen ───────────────────────────────

/**
 * Offene Eingangsrechnungen (Lieferantenrechnungen) für Barzahlung laden
 *
 * Sortierung und Zeitraumfilter wie bei den Ausgangsrechnungen — zwei Listen
 * nebeneinander, die sich unterschiedlich verhalten, wären für sich genommen
 * schon ein Fehler.
 *
 * @param string $data['search']    Suchbegriff: Rechnungsnr. oder Lieferantenname (optional)
 * @param string $data['from_date'] Frühestes Rechnungsdatum (optional)
 * @param string $data['to_date']   Spätestes Rechnungsdatum (optional)
 * @testdata {"from_date": "2026-08-01", "to_date": "2026-08-31"}
 */
function getOpenApForCash($data) {
    $db = DbhCompany::begin();

    $search = trim($data['search'] ?? '');
    $from   = $search === '' && !empty($data['from_date']) ? $data['from_date'] : null;
    $to     = $search === '' && !empty($data['to_date'])   ? $data['to_date']   : null;

    $result = $db->getAll("
        SELECT
            ap.id,
            ap.invnumber,
            ap.transdate,
            ap.duedate,
            ap.amount,
            ap.paid,
            (ap.amount - ap.paid) AS open_amount,
            v.name AS vendor_name
        FROM ap
        JOIN vendor v ON v.id = ap.vendor_id
        WHERE ap.amount > ap.paid
          AND ap.storno IS NOT TRUE
          AND (:search::text IS NULL OR ap.invnumber ILIKE :search OR v.name ILIKE :search)
          AND (:from::date   IS NULL OR ap.transdate >= :from::date)
          AND (:to::date     IS NULL OR ap.transdate <= :to::date)
        ORDER BY ap.transdate DESC NULLS LAST, ap.id DESC
        LIMIT 100
    ", [
        'search' => $search === '' ? null : '%' . $search . '%',
        'from'   => $from,
        'to'     => $to,
    ]);

    resultInfo(true, '', ['invoices' => $result ?: []]);
}

/**
 * Eingangsrechnung als Barzahlung buchen (acc_trans + ap.paid, kivitendo-kompatibel)
 *
 * Spiegelbild von bookArAsCash: hier verlässt Geld die Kasse (Auszahlung an den
 * Lieferanten). Vorzeichen daher umgekehrt; die Kasse darf nicht ins Minus.
 *
 * @param int    $data['cash_register_id'] Kassakonto-ID (= chart.id)
 * @param int    $data['ap_id']            Eingangsrechnung-ID
 * @param float  $data['amount']           Betrag (optional; default = offener Betrag)
 * @param string $data['transdate']        Buchungsdatum (optional)
 * @testdata {"cash_register_id": 1, "ap_id": 1}
 */
function bookApAsCash($data) {
    $db = DbhCompany::begin();

    $registerId = intval($data['cash_register_id'] ?? 0);
    $apId       = intval($data['ap_id'] ?? 0);

    if ($registerId <= 0 || $apId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'cash_register_id und ap_id erforderlich');
        return;
    }

    $register = _kasse_loadRegister($db, $registerId);
    if (!$register) { resultInfo(false, 'NOT_FOUND', 'Kassenbuch nicht gefunden'); return; }

    $ap = $db->getOne(
        "SELECT id, invnumber, amount, paid FROM ap WHERE id = :id AND storno IS NOT TRUE",
        ['id' => $apId]
    );
    if (!$ap) { resultInfo(false, 'NOT_FOUND', 'Eingangsrechnung nicht gefunden'); return; }

    $openAmount = floatval($ap['amount']) - floatval($ap['paid']);
    $payAmount  = isset($data['amount']) ? min(abs(floatval($data['amount'])), $openAmount) : $openAmount;

    if ($payAmount <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Rechnung bereits vollständig bezahlt');
        return;
    }

    $transdate = $data['transdate'] ?? date('Y-m-d');

    // Kasse darf nie ins Minus: Auszahlung nur, wenn gedeckt.
    $available = _kasse_availableBalance($db, $register['chart_id'], $transdate);
    if ($payAmount > $available + 0.005) {
        resultInfo(false,
            'Diese Zahlung würde die Kasse ins Minus bringen. Verfügbar am '
            . date('d.m.Y', strtotime($transdate)) . ': '
            . number_format($available, 2, ',', '.') . ' €, benötigt: '
            . number_format($payAmount, 2, ',', '.') . ' €.',
            ['code' => 'NEGATIVE_CASH', 'available' => $available]
        );
        return;
    }

    // Verbindlichkeitskonto der AP-Erstbuchung ermitteln (z. B. 3300)
    $tokenLink = _kasse_hasLinkToken('chart_link', 'AP');
    $counterChart = $db->getOne("
        SELECT chart_id, chart_link
        FROM acc_trans
        WHERE trans_id = :tid
          AND {$tokenLink}
        ORDER BY acc_trans_id ASC
        LIMIT 1
    ", ['tid' => $apId]);

    // Fallback (wie matching.php): Rechnungen ohne GL-Erstbuchung → Standard-
    // Verbindlichkeitskonto aus dem Kontenrahmen (chart.link = 'AP', kleinste Nr.).
    if (!$counterChart) {
        $counterChart = $db->getOne(
            "SELECT id AS chart_id, link AS chart_link FROM chart WHERE link = 'AP' ORDER BY accno ASC LIMIT 1"
        );
    }

    if (!$counterChart) {
        resultInfo(false, 'DATA_ERROR', 'Verbindlichkeitskonto der Rechnung nicht ermittelbar');
        return;
    }

    $beleg = nextBelegnummer($db, $register['chart_id'], $transdate);

    // Klammer wie bei der Ausgangsrechnung — siehe bookArAsCash().
    $db->beginTransaction();
    try {

        // Vorzeichen (Soll = negativ, Haben = positiv):
        //   Verbindlichkeitskonto: negativer Betrag (Soll) → klärt die Verbindlichkeit, zählt für ap.paid
        //   Kassenkonto:           positiver Betrag (Haben) → Geld verlässt die Kasse; chart_link 'AP_paid'
        //                          wird von der Faktura als Zahlung erkannt; source = Belegnummer.
        $db->execute(
            "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, tax_id, taxkey)
             VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :memo, :chart_link, 0, 0)",
            [
                'trans_id'   => $apId,
                'chart_id'   => $counterChart['chart_id'],
                'amount'     => -$payAmount,
                'transdate'  => $transdate,
                'source'     => $ap['invnumber'],
                'memo'       => 'Barzahlung Kasse',
                'chart_link' => $counterChart['chart_link'] ?? 'AP',
            ]
        );

        $db->execute(
            "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link, tax_id, taxkey)
             VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :memo, :chart_link, 0, 0)",
            [
                'trans_id'   => $apId,
                'chart_id'   => $register['chart_id'],
                'amount'     => $payAmount,
                'transdate'  => $transdate,
                'source'     => $beleg,
                'memo'       => 'Barzahlung Kasse',
                'chart_link' => $register['chart_link'],
            ]
        );

        // ap.paid erhöhen
        $db->execute(
            "UPDATE ap SET paid = COALESCE(paid, 0) + :inc WHERE id = :id",
            ['inc' => $payAmount, 'id' => $apId]
        );

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    resultInfo(true, '', [
        'booked_amount' => $payAmount,
        'beleg'         => $beleg,
        'invnumber'     => $ap['invnumber'],
        'open_after'    => round($openAmount - $payAmount, 2),
    ]);
}

// ── Beleg-Upload und -Vorschau (optional) ─────────────────────────────────────

/**
 * Beleg für Kassenbuchung hochladen (in accounting_documents)
 *
 * @param string $data['filename']    Dateiname
 * @param string $data['mime_type']   MIME-Typ
 * @param string $data['file_base64'] Base64-kodierter Inhalt
 * @testdata {"filename": "beleg.pdf", "mime_type": "application/pdf", "file_base64": ""}
 */
function uploadCashDocument($data) {
    $db = DbhCompany::begin();

    if (!_kasse_documentsEnabled($db)) {
        resultInfo(false, 'DOCUMENTS_UNAVAILABLE', 'Beleg-Verwaltung in dieser Datenbank nicht verfügbar');
        return;
    }

    $filename   = trim($data['filename'] ?? '');
    $mimeType   = $data['mime_type'] ?? 'application/octet-stream';
    $fileBase64 = $data['file_base64'] ?? '';

    if (!$filename || !$fileBase64) {
        resultInfo(false, 'VALIDATION_ERROR', 'Dateiname und Inhalt erforderlich');
        return;
    }

    $fileContent = base64_decode($fileBase64, true);
    if ($fileContent === false) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungültiges Base64-Format');
        return;
    }

    $fileHash   = hash('sha256', $fileContent);
    $employeeId = mitarbeiterId($data);

    $existing = $db->getOne(
        "SELECT id FROM accounting_documents WHERE file_hash = :hash",
        ['hash' => $fileHash]
    );
    if ($existing) {
        resultInfo(true, '', ['document_id' => $existing['id'], 'duplicate' => true]);
        return;
    }

    $accountingDir = fmDataDir() . '/accounting';
    if (!is_dir($accountingDir)) mkdir($accountingDir, 0755, true);

    // status 'booked': der Beleg wird im selben Arbeitsgang an eine Kassenbuchung
    // gehaengt. 'manual' stand hier frueher — ein Wert, den
    // accounting_documents_status_check gar nicht zulaesst; der Upload lief
    // deshalb ausnahmslos in eine Constraint-Verletzung. 'pending' waere falsch,
    // das ist die Warteschlange der Belegerkennung.
    $db->execute(
        "INSERT INTO accounting_documents (original_name, mime_type, file_size, file_hash, status, employee_id)
         VALUES (:name, :mime, :size, :hash, 'booked', :eid)",
        ['name' => $filename, 'mime' => $mimeType, 'size' => strlen($fileContent), 'hash' => $fileHash, 'eid' => $employeeId]
    );

    $doc      = $db->getOne("SELECT id FROM accounting_documents WHERE file_hash = :hash ORDER BY id DESC LIMIT 1", ['hash' => $fileHash]);
    $docId    = $doc['id'];
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

    $storedPath = "accounting/{$docId}_{$safeName}";
    if (!belegSchreiben(fmDataDir() . '/' . $storedPath, $fileContent)) {
        resultInfo(false, 'STORAGE_ERROR', 'Beleg konnte nicht gespeichert werden');
        return;
    }
    belegAblageEintragen($db, $docId, $storedPath);
    belegProtokoll($db, $docId, $employeeId, 'ablage', null, $filename);

    resultInfo(true, '', ['document_id' => $docId]);
}

/**
 * Beleg einer GL-Kassenbuchung zuordnen
 *
 * @param int $data['gl_id']      GL-ID der Buchung
 * @param int $data['document_id'] Dokument-ID
 * @testdata {"gl_id": 1, "document_id": 1}
 */
function linkDocumentToCashTransaction($data) {
    $db = DbhCompany::begin();

    if (!_kasse_documentsEnabled($db)) {
        resultInfo(false, 'DOCUMENTS_UNAVAILABLE', 'Beleg-Verwaltung in dieser Datenbank nicht verfügbar');
        return;
    }

    $glId  = intval($data['gl_id'] ?? 0);
    $docId = intval($data['document_id'] ?? 0);

    if ($glId <= 0 || $docId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'gl_id und document_id erforderlich');
        return;
    }

    $existing = $db->getOne(
        "SELECT id FROM cash_gl_documents WHERE gl_id = :gl_id AND document_id = :doc_id",
        ['gl_id' => $glId, 'doc_id' => $docId]
    );
    if (!$existing) {
        $db->execute(
            "INSERT INTO cash_gl_documents (gl_id, document_id) VALUES (:gl_id, :doc_id)",
            ['gl_id' => $glId, 'doc_id' => $docId]
        );
        belegProtokoll($db, $docId, mitarbeiterId($data), 'verknuepfung', null, "Kassenbuchung {$glId}");
    }

    resultInfo(true, '', []);
}

/**
 * Beleginhalt als Base64 zurückgeben (für Vorschau)
 *
 * @param int $data['document_id'] Dokument-ID
 * @testdata {"document_id": 1}
 */
function getCashDocumentContent($data) {
    $db = DbhCompany::begin();

    if (!_kasse_documentsEnabled($db)) {
        resultInfo(false, 'DOCUMENTS_UNAVAILABLE', 'Beleg-Verwaltung in dieser Datenbank nicht verfügbar');
        return;
    }

    $docId = intval($data['document_id'] ?? 0);
    if ($docId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Dokument-ID fehlt');
        return;
    }

    $doc = $db->getOne(
        "SELECT stored_path, original_name, mime_type FROM accounting_documents WHERE id = :id",
        ['id' => $docId]
    );
    if (!$doc || !$doc['stored_path']) {
        resultInfo(false, 'DATA_NOT_FOUND', 'Dokument nicht gefunden');
        return;
    }

    $filePath = fmDataDir() . '/' . $doc['stored_path'];
    if (!file_exists($filePath)) {
        resultInfo(false, 'DATA_NOT_FOUND', 'Datei nicht gefunden');
        return;
    }

    belegProtokoll($db, $docId, mitarbeiterId($data), 'ansicht');

    resultInfo(true, '', [
        'content_base64' => base64_encode(file_get_contents($filePath)),
        'mime_type'      => $doc['mime_type'],
        'filename'       => $doc['original_name'],
    ]);
}
