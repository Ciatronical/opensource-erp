<?php
// backend/api/banking/kasse.php
// Kassenbuch – nutzt kivitendo-kompatible gl + acc_trans Buchungen.

// ── Hilfsfunktion: Kassakonto-Chart laden ───────────────────────────────────

function _kasse_loadRegister($db, $registerId) {
    return $db->getOne(
        "SELECT cr.id, cr.name, cr.chart_id, cr.opening_balance, cr.currency,
                ch.accno AS chart_accno, ch.description AS chart_description, ch.link AS chart_link
         FROM cash_registers cr
         JOIN chart ch ON ch.id = cr.chart_id
         WHERE cr.id = :id",
        ['id' => $registerId]
    );
}

// ── Kassenbücher ─────────────────────────────────────────────────────────────

/**
 * Kassakonten laden (ggf. Auto-Setup aus dem Kontenplan)
 *
 * Falls noch kein Eintrag in cash_registers existiert, wird das Kassakonto
 * automatisch aus dem Kontenplan erkannt (SKR03: 1000, SKR04: 1600) und
 * ein Standardeintrag angelegt. Der Benutzer muss nichts manuell einrichten.
 *
 * @testdata {}
 */
function getCashRegisters($data) {
    $db = DbhCompany::begin();

    // Auto-Setup: Kassakonto aus Kontenplan erkennen falls noch kein Eintrag vorhanden
    $existing = $db->getOne("SELECT COUNT(*) AS cnt FROM cash_registers");
    if (intval($existing['cnt'] ?? 0) === 0) {
        $kasseChart = $db->getOne("
            SELECT id, accno, description
            FROM chart
            WHERE link LIKE '%AR_paid%'
              AND category = 'A'
              AND (invalid IS NULL OR invalid = false)
            ORDER BY
                CASE WHEN accno = '1000' THEN 1
                     WHEN accno = '1600' THEN 2
                     ELSE 99 END
            LIMIT 1
        ");
        if ($kasseChart) {
            $db->execute(
                "INSERT INTO cash_registers (name, chart_id, opening_balance)
                 VALUES (:name, :chart_id, 0)",
                ['name' => $kasseChart['description'], 'chart_id' => $kasseChart['id']]
            );
        }
    }

    $result = $db->getAll("
        SELECT
            cr.id,
            cr.name,
            cr.chart_id,
            cr.opening_balance,
            cr.currency,
            ch.accno            AS chart_accno,
            ch.description      AS chart_description,
            cr.opening_balance + COALESCE(SUM(at.amount), 0) AS balance,
            COALESCE(SUM(
                CASE WHEN at.amount > 0
                    AND DATE_TRUNC('month', at.transdate) = DATE_TRUNC('month', CURRENT_DATE)
                THEN at.amount ELSE 0 END
            ), 0) AS income_this_month,
            ABS(COALESCE(SUM(
                CASE WHEN at.amount < 0
                    AND DATE_TRUNC('month', at.transdate) = DATE_TRUNC('month', CURRENT_DATE)
                THEN at.amount ELSE 0 END
            ), 0)) AS expenses_this_month,
            MAX(at.transdate) AS last_transaction_date
        FROM cash_registers cr
        JOIN chart ch ON ch.id = cr.chart_id
        LEFT JOIN acc_trans at ON at.chart_id = cr.chart_id
            AND (at.ob_transaction IS NULL OR at.ob_transaction = false)
        GROUP BY cr.id, cr.name, cr.chart_id, cr.opening_balance, cr.currency,
                 ch.accno, ch.description
        ORDER BY cr.name
    ");

    resultInfo(true, '', ['registers' => $result ?: []]);
}

/**
 * Verfügbare Kassakonten (chart mit AR_paid-Link) laden
 *
 * @testdata {}
 */
function getCashChartAccounts($data) {
    $db = DbhCompany::begin();

    $result = $db->getAll("
        SELECT id, accno, description
        FROM chart
        WHERE link LIKE '%AR_paid%'
          AND category = 'A'
          AND (invalid IS NULL OR invalid = false)
        ORDER BY accno
    ");

    resultInfo(true, '', ['charts' => $result ?: []]);
}

/**
 * Gegenkonten für Kassenbuchungen laden (Aufwands- und Ertragskonten)
 *
 * @param string $data['category'] E = Aufwand, I = Ertrag, all = alle (default: all)
 * @testdata {"category": "all"}
 */
function getCashCounterCharts($data) {
    $db = DbhCompany::begin();

    $category = $data['category'] ?? 'all';
    $where    = '';
    $params   = [];

    if ($category === 'E') {
        $where          = 'AND ch.category = :cat';
        $params['cat']  = 'E';
    } elseif ($category === 'I') {
        $where          = 'AND ch.category = :cat';
        $params['cat']  = 'I';
    } else {
        $where = "AND ch.category IN ('E', 'I')";
    }

    $result = $db->getAll("
        SELECT id, accno, description, category
        FROM chart ch
        WHERE (invalid IS NULL OR invalid = false)
            {$where}
        ORDER BY accno
    ", $params);

    resultInfo(true, '', ['charts' => $result ?: []]);
}

/**
 * Neues Kassenbuch anlegen
 *
 * @param string $data['name']            Name des Kassenbuchs
 * @param int    $data['chart_id']        Chart-ID des Kassakontos (aus getCashChartAccounts)
 * @param float  $data['opening_balance'] Anfangsbestand (optional, default 0)
 * @testdata {"name": "Hauptkasse", "chart_id": 1, "opening_balance": 500.00}
 */
function createCashRegister($data) {
    $db = DbhCompany::begin();

    $name    = trim($data['name'] ?? '');
    $chartId = intval($data['chart_id'] ?? 0);

    if (!$name) {
        resultInfo(false, 'VALIDATION_ERROR', 'Name fehlt');
        return;
    }
    if ($chartId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Kassakonto fehlt');
        return;
    }

    $openingBalance = floatval($data['opening_balance'] ?? 0);

    $db->execute(
        "INSERT INTO cash_registers (name, chart_id, opening_balance)
         VALUES (:name, :chart_id, :opening_balance)",
        ['name' => $name, 'chart_id' => $chartId, 'opening_balance' => $openingBalance]
    );

    $new = $db->getOne(
        "SELECT cr.id, cr.name, cr.chart_id, cr.opening_balance, ch.accno, ch.description AS chart_description
         FROM cash_registers cr JOIN chart ch ON ch.id = cr.chart_id
         ORDER BY cr.id DESC LIMIT 1"
    );

    resultInfo(true, '', ['register' => $new]);
}

// ── Kassenbuchungen laden ─────────────────────────────────────────────────────

/**
 * Kassenbuchungen eines Kassenbuchs laden (aus gl + acc_trans + AR + AP)
 *
 * @param int    $data['cash_register_id'] Kassenbuch-ID
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

    $chartId    = $register['chart_id'];
    $params     = ['chart_id' => $chartId, 'chart_id2' => $chartId, 'chart_id3' => $chartId];
    $whereExtra = '';

    $typeFilter = $data['type_filter'] ?? 'all';
    if ($typeFilter === 'income') {
        $whereExtra .= ' AND kasse.amount > 0';
    } elseif ($typeFilter === 'expense') {
        $whereExtra .= ' AND kasse.amount < 0';
    }

    if (!empty($data['from_date'])) {
        $whereExtra .= ' AND kasse.transdate >= :from_date';
        $params['from_date'] = $data['from_date'];
    }
    if (!empty($data['to_date'])) {
        $whereExtra .= ' AND kasse.transdate <= :to_date';
        $params['to_date'] = $data['to_date'];
    }

    $result = $db->getOne("
        SELECT json_agg(row_to_json(kasse) ORDER BY kasse.transdate DESC, kasse.acc_trans_id DESC)
               AS transactions
        FROM (
            -- ── 1. Manuelle GL-Buchungen (Barausgaben / Bareinnahmen) ──
            SELECT
                at.acc_trans_id,
                at.trans_id     AS gl_id,
                'gl'            AS source_type,
                at.transdate,
                at.amount,
                gl.reference,
                gl.description,
                NULL::text      AS partner_name,
                -- Gegenkonto (jeweils das andere Konto dieser GL-Buchung)
                gc.accno        AS gegenkonto_accno,
                gc.description  AS gegenkonto_description,
                -- Beleg
                cgd.document_id,
                ad.original_name AS document_name,
                ad.mime_type     AS document_mime_type
            FROM acc_trans at
            JOIN gl ON gl.id = at.trans_id
                AND (gl.storno IS NULL OR gl.storno = false)
                AND (gl.ob_transaction IS NULL OR gl.ob_transaction = false)
            LEFT JOIN LATERAL (
                SELECT c2.accno, c2.description
                FROM acc_trans at2
                JOIN chart c2 ON c2.id = at2.chart_id
                WHERE at2.trans_id = at.trans_id
                  AND at2.chart_id != at.chart_id
                ORDER BY at2.acc_trans_id ASC
                LIMIT 1
            ) gc ON true
            LEFT JOIN cash_gl_documents cgd ON cgd.gl_id = gl.id
            LEFT JOIN accounting_documents ad ON ad.id = cgd.document_id
            WHERE at.chart_id = :chart_id
              AND (at.ob_transaction IS NULL OR at.ob_transaction = false)

            UNION ALL

            -- ── 2. AR-Zahlungen (Kundenzahlungen bar) ──
            SELECT
                at.acc_trans_id,
                at.trans_id     AS gl_id,
                'ar'            AS source_type,
                at.transdate,
                at.amount,
                ar.invnumber    AS reference,
                ar.invnumber    AS description,
                c.name          AS partner_name,
                NULL::text      AS gegenkonto_accno,
                NULL::text      AS gegenkonto_description,
                NULL::integer   AS document_id,
                NULL::text      AS document_name,
                NULL::text      AS document_mime_type
            FROM acc_trans at
            JOIN ar ON ar.id = at.trans_id
                AND (ar.storno IS NULL OR ar.storno = false)
            JOIN customer c ON c.id = ar.customer_id
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
                at.amount,
                ap.invnumber    AS reference,
                ap.invnumber    AS description,
                v.name          AS partner_name,
                NULL::text      AS gegenkonto_accno,
                NULL::text      AS gegenkonto_description,
                NULL::integer   AS document_id,
                NULL::text      AS document_name,
                NULL::text      AS document_mime_type
            FROM acc_trans at
            JOIN ap ON ap.id = at.trans_id
                AND (ap.storno IS NULL OR ap.storno = false)
            JOIN vendor v ON v.id = ap.vendor_id
            WHERE at.chart_id = :chart_id3
              AND (at.ob_transaction IS NULL OR at.ob_transaction = false)
              AND NOT EXISTS (SELECT 1 FROM gl WHERE id = at.trans_id)
              AND NOT EXISTS (SELECT 1 FROM ar WHERE id = at.trans_id)
        ) kasse
        WHERE 1=1 {$whereExtra}
    ", $params);

    resultInfo(true, '', ['transactions' => $result['transactions'] ?? []]);
}

// ── Manuelle Kassenbuchung anlegen ────────────────────────────────────────────

/**
 * Manuelle Kassenbuchung anlegen (gl + acc_trans, kivitendo-kompatibel)
 *
 * @param int    $data['cash_register_id']   Kassenbuch-ID
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

    if ($registerId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Kassenbuch-ID fehlt'); return; }
    if ($counterChartId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Gegenkonto fehlt'); return; }
    if ($amount <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Betrag muss größer 0 sein'); return; }

    $register = _kasse_loadRegister($db, $registerId);
    if (!$register) { resultInfo(false, 'NOT_FOUND', 'Kassenbuch nicht gefunden'); return; }

    $counterChart = $db->getOne(
        "SELECT id, accno, description, link FROM chart WHERE id = :id",
        ['id' => $counterChartId]
    );
    if (!$counterChart) { resultInfo(false, 'NOT_FOUND', 'Gegenkonto nicht gefunden'); return; }

    $transdate   = $data['transdate'] ?? date('Y-m-d');
    $description = trim($data['description'] ?? '') ?: null;
    $reference   = trim($data['reference'] ?? '') ?: null;
    $documentId  = intval($data['document_id'] ?? 0) ?: null;
    $employeeId  = $_SESSION['employee_id'] ?? null;

    // Vorzeichen:
    // expense → Kasse nimmt ab (negativ auf Kassenkonto), Gegenkonto nimmt zu (positiv)
    // income  → Kasse nimmt zu (positiv auf Kassenkonto), Gegenkonto nimmt ab (negativ)
    $kasseAmount   = $type === 'expense' ? -$amount : $amount;
    $counterAmount = -$kasseAmount;

    // GL-Eintrag (Hauptbuch-Kopf)
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
        "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, chart_link)
         VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :chart_link)",
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
        "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, chart_link)
         VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :chart_link)",
        [
            'trans_id'   => $glId,
            'chart_id'   => $counterChartId,
            'amount'     => $counterAmount,
            'transdate'  => $transdate,
            'source'     => $reference ?? 'Kasse',
            'chart_link' => $counterChart['link'] ?? '',
        ]
    );

    // Beleg verknüpfen
    if ($documentId) {
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

    // Nur GL-Buchungen löschen (keine AR/AP-Zahlungen — die über den AR-Workflow)
    $gl = $db->getOne("SELECT id FROM gl WHERE id = :id AND (storno IS NULL OR storno = false)", ['id' => $glId]);
    if (!$gl) {
        resultInfo(false, 'NOT_FOUND', 'GL-Buchung nicht gefunden');
        return;
    }

    $db->execute("DELETE FROM acc_trans WHERE trans_id = :id", ['id' => $glId]);
    $db->execute("DELETE FROM cash_gl_documents WHERE gl_id = :id", ['id' => $glId]);
    $db->execute("DELETE FROM gl WHERE id = :id", ['id' => $glId]);

    resultInfo(true, '', []);
}

// ── AR-Rechnung als Barzahlung buchen ─────────────────────────────────────────

/**
 * Offene Ausgangsrechnungen für Barzahlung laden
 *
 * @param string $data['search'] Suchbegriff: Rechnungsnr. oder Kundenname (optional)
 * @testdata {}
 */
function getOpenArForCash($data) {
    $db = DbhCompany::begin();

    $params     = [];
    $whereExtra = '';

    if (!empty($data['search'])) {
        $whereExtra             .= " AND (ar.invnumber ILIKE :search OR c.name ILIKE :search)";
        $params['search']        = '%' . $data['search'] . '%';
    }

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
          {$whereExtra}
        ORDER BY ar.duedate ASC NULLS LAST, ar.transdate ASC
        LIMIT 100
    ", $params);

    resultInfo(true, '', ['invoices' => $result ?: []]);
}

/**
 * Ausgangsrechnung als Barzahlung buchen (acc_trans + ar.paid, kivitendo-kompatibel)
 *
 * @param int    $data['cash_register_id'] Kassenbuch-ID
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

    // Gegenkonto der AR-Erstbuchung ermitteln (Forderungskonto, z.B. 1200/1400)
    $counterChart = $db->getOne("
        SELECT chart_id
        FROM acc_trans
        WHERE trans_id = :tid
          AND chart_link LIKE '%AR%'
          AND chart_link NOT LIKE '%AR_paid%'
        ORDER BY acc_trans_id ASC
        LIMIT 1
    ", ['tid' => $arId]);

    if (!$counterChart) {
        resultInfo(false, 'DATA_ERROR', 'Forderungskonto der Rechnung nicht ermittelbar');
        return;
    }

    // Exakt wie matching.php: Kassenkonto erhält den positiven Betrag (Geld kommt rein)
    $db->execute(
        "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link)
         VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :memo, :chart_link)",
        [
            'trans_id'   => $arId,
            'chart_id'   => $register['chart_id'],
            'amount'     => $payAmount,
            'transdate'  => $transdate,
            'source'     => $ar['invnumber'],
            'memo'       => 'Barzahlung Kasse',
            'chart_link' => $register['chart_link'],
        ]
    );

    // Gegenbuchung gegen Forderungskonto (chart_link = 'AR_paid' → kivitendo erkennt Zahlung)
    $db->execute(
        "INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, chart_link)
         VALUES (:trans_id, :chart_id, :amount, :transdate, :transdate, :source, :memo, 'AR_paid')",
        [
            'trans_id' => $arId,
            'chart_id' => $counterChart['chart_id'],
            'amount'   => -$payAmount,
            'transdate' => $transdate,
            'source'   => $ar['invnumber'],
            'memo'     => 'Barzahlung Kasse',
        ]
    );

    // ar.paid erhöhen
    $db->execute(
        "UPDATE ar SET paid = COALESCE(paid, 0) + :inc WHERE id = :id",
        ['inc' => $payAmount, 'id' => $arId]
    );

    resultInfo(true, '', ['booked_amount' => $payAmount]);
}

// ── Beleg-Upload und -Vorschau ────────────────────────────────────────────────

/**
 * Beleg für Kassenbuchung hochladen (in accounting_documents, ohne KI-Analyse)
 *
 * @param string $data['filename']    Dateiname
 * @param string $data['mime_type']   MIME-Typ
 * @param string $data['file_base64'] Base64-kodierter Inhalt
 * @testdata {"filename": "beleg.pdf", "mime_type": "application/pdf", "file_base64": ""}
 */
function uploadCashDocument($data) {
    $db = DbhCompany::begin();

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
    $employeeId = $_SESSION['employee_id'] ?? null;

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

    $db->execute(
        "INSERT INTO accounting_documents (original_name, mime_type, file_size, file_hash, status, employee_id)
         VALUES (:name, :mime, :size, :hash, 'manual', :eid)",
        ['name' => $filename, 'mime' => $mimeType, 'size' => strlen($fileContent), 'hash' => $fileHash, 'eid' => $employeeId]
    );

    $doc      = $db->getOne("SELECT id FROM accounting_documents WHERE file_hash = :hash ORDER BY id DESC LIMIT 1", ['hash' => $fileHash]);
    $docId    = $doc['id'];
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

    $storedPath = "accounting/{$docId}_{$safeName}";
    file_put_contents(fmDataDir() . '/' . $storedPath, $fileContent);
    $db->execute("UPDATE accounting_documents SET stored_path = :path WHERE id = :id", ['path' => $storedPath, 'id' => $docId]);

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
    $db    = DbhCompany::begin();
    $glId  = intval($data['gl_id'] ?? 0);
    $docId = intval($data['document_id'] ?? 0);

    if ($glId <= 0 || $docId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'gl_id und document_id erforderlich');
        return;
    }

    // Idempotent: nur einfügen wenn noch nicht vorhanden
    $existing = $db->getOne(
        "SELECT id FROM cash_gl_documents WHERE gl_id = :gl_id AND document_id = :doc_id",
        ['gl_id' => $glId, 'doc_id' => $docId]
    );
    if (!$existing) {
        $db->execute(
            "INSERT INTO cash_gl_documents (gl_id, document_id) VALUES (:gl_id, :doc_id)",
            ['gl_id' => $glId, 'doc_id' => $docId]
        );
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
    $db    = DbhCompany::begin();
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

    resultInfo(true, '', [
        'content_base64' => base64_encode(file_get_contents($filePath)),
        'mime_type'      => $doc['mime_type'],
        'filename'       => $doc['original_name'],
    ]);
}
