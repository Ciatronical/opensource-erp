<?php
// backend/api/banking/direct_debit.php
//
// SEPA-Lastschriften (Einzuege) via FinTS.

/**
 * SEPA-Lastschrift-Auftraege laden
 * @testdata {}
 */
function getDirectDebitOrders($data) {
    $db  = DbhCompany::begin();
    $aid = $data['bank_account_id'] ? intval($data['bank_account_id']) : null;
    $w   = $aid ? 'WHERE sdd.bank_account_id = :aid' : '';
    $p   = $aid ? ['aid' => $aid] : [];
    $r   = $db->getOne(<<<SQL
        SELECT json_agg(row_to_json(t) ORDER BY t.collection_date DESC, t.id DESC) AS orders
        FROM (
            SELECT sdd.*, ba.name AS account_name
            FROM sepa_direct_debit_orders sdd
            LEFT JOIN bank_accounts ba ON ba.id = sdd.bank_account_id
            {$w}
            ORDER BY sdd.collection_date DESC, sdd.id DESC
        ) t
    SQL, $p);
    resultInfo(true, '', ['orders' => json_decode($r['orders'] ?? '[]', true) ?: []]);
}

/**
 * Lastschrift-Auftrag anlegen
 *
 * @param int    $data['bank_account_id']    Gläubigerkonto
 * @param string $data['debtor_name']        Schuldner-Name
 * @param string $data['debtor_iban']        Schuldner-IBAN
 * @param string $data['mandate_reference']  Mandatsreferenz
 * @param float  $data['amount']
 * @param string $data['purpose']
 * @param string $data['collection_date']    YYYY-MM-DD
 * @testdata {"bank_account_id":1,"debtor_name":"Max Mustermann","debtor_iban":"DE89370400440532013000","mandate_reference":"M001","amount":150,"purpose":"Rechnung 2026-001","collection_date":"2026-05-01"}
 */
function createDirectDebitOrder($data) {
    $db   = DbhCompany::begin();
    $aid  = intval($data['bank_account_id'] ?? 0);
    $name = trim($data['debtor_name']       ?? '');
    $iban = strtoupper(str_replace(' ', '', $data['debtor_iban'] ?? ''));
    $mref = trim($data['mandate_reference'] ?? '');
    $amt  = floatval($data['amount']        ?? 0);
    $pur  = trim($data['purpose']           ?? '');
    $date = trim($data['collection_date']   ?? '');

    if (!$aid || !$name || !$iban || !$mref || $amt <= 0 || !$pur || !$date) {
        resultInfo(false, 'VALIDATION_ERROR', 'Pflichtfelder fehlen');
        return;
    }

    // Gläubiger-ID aus FinTS-Konfiguration lesen (falls vorhanden)
    $config = $db->getOne(
        "SELECT fints_url FROM bank_account_fints WHERE bank_account_id = :id",
        ['id' => $aid]
    );

    $row = $db->getOne(<<<SQL
        INSERT INTO sepa_direct_debit_orders
            (bank_account_id, mandate_id, debtor_name, debtor_iban, debtor_bic,
             mandate_reference, creditor_id, collection_date, amount, purpose,
             sequence_type, employee_id)
        VALUES
            (:aid, :mid, :name, :iban, :bic,
             :mref, :cid, :date, :amt, :pur,
             :seq, :emp)
        RETURNING id
    SQL, [
        'aid'  => $aid,
        'mid'  => $data['mandate_id'] ? intval($data['mandate_id']) : null,
        'name' => $name,
        'iban' => $iban,
        'bic'  => trim($data['debtor_bic']   ?? '') ?: null,
        'mref' => $mref,
        'cid'  => trim($data['creditor_id']  ?? '') ?: null,
        'date' => $date,
        'amt'  => $amt,
        'pur'  => $pur,
        'seq'  => in_array($data['sequence_type'] ?? '', ['FRST','RCUR','FNAL','OOFF']) ? $data['sequence_type'] : 'RCUR',
        'emp'  => $data['employee_id'] ? intval($data['employee_id']) : null,
    ]);
    resultInfo(true, '', ['id' => $row['id']]);
}

/**
 * Lastschrift-Auftrag löschen (nur Entwürfe)
 * @param int $data['id']
 * @testdata {"id":1}
 */
function deleteDirectDebitOrder($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }
    $db->execute("DELETE FROM sepa_direct_debit_orders WHERE id = :id AND status = 'draft'", ['id' => $id]);
    resultInfo(true, '');
}

/**
 * SEPA-Lastschrift an Bank senden.
 * Baut PAIN.008.003.02 XML und sendet via phpFinTS SendSEPADirectDebit.
 *
 * @param int    $data['direct_debit_order_id']
 * @param string $data['pin']
 * @param string $data['creditor_id']  Gläubiger-ID (AT-02), z.B. DE98ZZZ09999999999
 * @testdata {"direct_debit_order_id":1,"pin":"12345","creditor_id":"DE98ZZZ09999999999"}
 */
function fintsSubmitDirectDebit($data) {
    $db         = DbhCompany::begin();
    $orderId    = intval($data['direct_debit_order_id'] ?? 0);
    $pin        = $data['pin'] ?? '';
    $creditorId = trim($data['creditor_id'] ?? '');

    if (!$orderId || !$pin) {
        resultInfo(false, 'VALIDATION_ERROR', 'Auftrags-ID und PIN sind Pflicht');
        return;
    }

    $order = $db->getOne(<<<SQL
        SELECT sdd.*,
               REGEXP_REPLACE(ba.iban, '\s+', '', 'g') AS creditor_iban,
               REGEXP_REPLACE(ba.bic,  '\s+', '', 'g') AS creditor_bic,
               REGEXP_REPLACE(ba.bank_code, '\s+', '', 'g') AS creditor_blz,
               REGEXP_REPLACE(ba.account_number, '\s+', '', 'g') AS creditor_account_number,
               ba.name AS creditor_name
        FROM sepa_direct_debit_orders sdd
        JOIN bank_accounts ba ON ba.id = sdd.bank_account_id
        WHERE sdd.id = :id
    SQL, ['id' => $orderId]);

    if (!$order) { resultInfo(false, 'NOT_FOUND', 'Lastschrift-Auftrag nicht gefunden'); return; }
    if ($order['status'] !== 'draft') { resultInfo(false, 'NOT_SUBMITTABLE', 'Nur Entwürfe können gesendet werden'); return; }

    $config = $db->getOne(
        "SELECT baf.* FROM bank_account_fints baf WHERE baf.bank_account_id = :id",
        ['id' => $order['bank_account_id']]
    );
    if (!$config) { resultInfo(false, 'NO_FINTS_CONFIG', 'Keine FinTS-Konfiguration'); return; }

    require_once __DIR__.'/../../vendor/autoload.php';

    // Gläubiger-ID: aus Parameter oder gespeichert
    $credId = $creditorId ?: ($order['creditor_id'] ?? 'DE98ZZZ09999999999');
    $companyRow = $db->getOne("SELECT company FROM defaults");
    $creditorName = trim($companyRow['company'] ?? '') ?: ($order['creditor_name'] ?? 'Gläubiger');

    // PAIN.008.003.02 XML aufbauen
    $painXml = buildSepaCoreDirectDebitPainXml(
        $creditorName,
        $order['creditor_iban'],
        $order['creditor_bic'] ?? '',
        $credId,
        [[
            'debtor_name'        => $order['debtor_name'],
            'debtor_iban'        => $order['debtor_iban'],
            'debtor_bic'         => $order['debtor_bic'] ?? '',
            'mandate_reference'  => $order['mandate_reference'],
            'collection_date'    => $order['collection_date'],
            'amount'             => $order['amount'],
            'purpose'            => $order['purpose'],
            'sequence_type'      => $order['sequence_type'] ?? 'RCUR',
        ]]
    );

    try {
        $fints = fintsCreate($config, $pin);
        $tanMode = !empty($config['fints_tan_mode']) ? (int)$config['fints_tan_mode'] : null;
        fintsSelectTanModeWithMedium($fints, $tanMode);
        $login = $fints->login();
        if ($login->needsTan()) {
            session_start();
            $_SESSION['fints_action']          = serialize($login);
            $_SESSION['fints_persist']         = $fints->persist();
            $_SESSION['fints_bank_account_id'] = $order['bank_account_id'];
            $_SESSION['fints_dd_order_id']     = $orderId;
            $_SESSION['fints_stage']           = 'login-dd';
            $db->execute("UPDATE sepa_direct_debit_orders SET status='pending_tan', mtime=now() WHERE id=:id", ['id' => $orderId]);
            resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $login));
            return;
        }

        $creditorAccount = (new \Fhp\Model\SEPAAccount())
            ->setIban($order['creditor_iban'])
            ->setBic($order['creditor_bic'] ?? '')
            ->setBlz($order['creditor_blz'] ?? '')
            ->setAccountNumber($order['creditor_account_number'] ?? '');

        $ddAction = \Fhp\Action\SendSEPADirectDebit::create($creditorAccount, $painXml);
        $fints->execute($ddAction);

        if ($ddAction->needsTan()) {
            session_start();
            $_SESSION['fints_action']          = serialize($ddAction);
            $_SESSION['fints_persist']         = $fints->persist();
            $_SESSION['fints_bank_account_id'] = $order['bank_account_id'];
            $_SESSION['fints_dd_order_id']     = $orderId;
            $_SESSION['fints_stage']           = 'dd';
            $db->execute("UPDATE sepa_direct_debit_orders SET status='pending_tan', mtime=now() WHERE id=:id", ['id' => $orderId]);
            resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $ddAction));
            return;
        }

        $db->execute(
            "UPDATE sepa_direct_debit_orders SET status='submitted', submitted_at=now(), mtime=now() WHERE id=:id",
            ['id' => $orderId]
        );
        resultInfo(true, 'Lastschrift gesendet');

    } catch (\Exception $e) {
        $db->execute(
            "UPDATE sepa_direct_debit_orders SET status='rejected', error_message=:err, mtime=now() WHERE id=:id",
            ['id' => $orderId, 'err' => fintsToUtf8($e->getMessage())]
        );
        fintsLogException('DirectDebit', $e, ['order_id' => $orderId]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
    }
}

/**
 * TAN für SEPA-Lastschrift einreichen
 * @param string $data['tan']
 * @param string $data['pin']
 * @param int    $data['direct_debit_order_id']
 * @testdata {"tan":"","pin":"12345","direct_debit_order_id":1}
 */
function fintsSubmitDirectDebitTan($data) {
    $db      = DbhCompany::begin();
    $tan     = trim($data['tan'] ?? '');
    $pin     = $data['pin'] ?? '';
    $orderId = intval($data['direct_debit_order_id'] ?? 0);

    if (!$pin || !$orderId) { resultInfo(false, 'VALIDATION_ERROR', 'PIN und ID sind Pflicht'); return; }

    session_start();
    if (($_SESSION['fints_dd_order_id'] ?? 0) !== $orderId) {
        resultInfo(false, 'SESSION_MISMATCH', 'Session-Mismatch'); return;
    }

    $bankAccountId = $_SESSION['fints_bank_account_id'];
    $config = $db->getOne("SELECT baf.* FROM bank_account_fints baf WHERE baf.bank_account_id = :id", ['id' => $bankAccountId]);

    require_once __DIR__.'/../../vendor/autoload.php';
    try {
        $fints  = fintsCreate($config, $pin, $_SESSION['fints_persist']);
        $action = unserialize($_SESSION['fints_action']);
        $stage  = $_SESSION['fints_stage'] ?? 'dd';

        if ($tan !== '') {
            $fints->submitTan($action, $tan);
        } else {
            $tm = $fints->getSelectedTanMode();
            if (!$tm || !$tm->isDecoupled()) { resultInfo(false, 'VALIDATION_ERROR', 'TAN ist erforderlich'); return; }
            if (!$fints->checkDecoupledSubmission($action)) {
                $_SESSION['fints_persist'] = $fints->persist();
                $_SESSION['fints_action']  = serialize($action);
                $payload = fintsBuildTanResponse($fints, $action);
                $payload['message'] = 'Freigabe noch ausstehend — bitte in der App bestätigen.';
                resultInfo(true, 'TAN_REQUIRED', $payload);
                return;
            }
        }

        if ($stage === 'login-dd') {
            unset($_SESSION['fints_action'], $_SESSION['fints_persist'], $_SESSION['fints_stage']);
            // Jetzt Lastschrift ausführen
            $order = $db->getOne(<<<SQL
                SELECT sdd.*,
                       REGEXP_REPLACE(ba.iban, '\s+', '', 'g') AS creditor_iban,
                       REGEXP_REPLACE(ba.bic,  '\s+', '', 'g') AS creditor_bic,
                       REGEXP_REPLACE(ba.bank_code, '\s+', '', 'g') AS creditor_blz,
                       REGEXP_REPLACE(ba.account_number, '\s+', '', 'g') AS creditor_account_number
                FROM sepa_direct_debit_orders sdd
                JOIN bank_accounts ba ON ba.id = sdd.bank_account_id
                WHERE sdd.id = :id
            SQL, ['id' => $orderId]);
            $companyRow  = $db->getOne("SELECT company FROM defaults");
            $creditorName = trim($companyRow['company'] ?? '') ?: 'Gläubiger';
            $painXml = buildSepaCoreDirectDebitPainXml(
                $creditorName, $order['creditor_iban'], $order['creditor_bic'] ?? '',
                $order['creditor_id'] ?? 'DE98ZZZ09999999999',
                [[
                    'debtor_name' => $order['debtor_name'], 'debtor_iban' => $order['debtor_iban'],
                    'debtor_bic'  => $order['debtor_bic'] ?? '', 'mandate_reference' => $order['mandate_reference'],
                    'collection_date' => $order['collection_date'], 'amount' => $order['amount'],
                    'purpose'     => $order['purpose'], 'sequence_type' => $order['sequence_type'] ?? 'RCUR',
                ]]
            );
            $cAcct = (new \Fhp\Model\SEPAAccount())
                ->setIban($order['creditor_iban'])->setBic($order['creditor_bic'] ?? '')
                ->setBlz($order['creditor_blz'] ?? '')->setAccountNumber($order['creditor_account_number'] ?? '');
            $ddAction = \Fhp\Action\SendSEPADirectDebit::create($cAcct, $painXml);
            $fints->execute($ddAction);
            if ($ddAction->needsTan()) {
                session_start();
                $_SESSION['fints_action'] = serialize($ddAction); $_SESSION['fints_persist'] = $fints->persist();
                $_SESSION['fints_bank_account_id'] = $bankAccountId; $_SESSION['fints_dd_order_id'] = $orderId;
                $_SESSION['fints_stage'] = 'dd';
                resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $ddAction));
                return;
            }
        }

        unset($_SESSION['fints_action'], $_SESSION['fints_persist'], $_SESSION['fints_bank_account_id'], $_SESSION['fints_dd_order_id'], $_SESSION['fints_stage']);
        $db->execute("UPDATE sepa_direct_debit_orders SET status='submitted', submitted_at=now(), mtime=now() WHERE id=:id", ['id' => $orderId]);
        resultInfo(true, 'Lastschrift gesendet');

    } catch (\Exception $e) {
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'], $_SESSION['fints_bank_account_id'], $_SESSION['fints_dd_order_id'], $_SESSION['fints_stage']);
        $db->execute("UPDATE sepa_direct_debit_orders SET status='rejected', error_message=:err, mtime=now() WHERE id=:id", ['id' => $orderId, 'err' => fintsToUtf8($e->getMessage())]);
        fintsLogException('DirectDebitTan', $e, ['order_id' => $orderId]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
    }
}

/**
 * PAIN.008.003.02 XML für SEPA Core-Lastschrift aufbauen.
 */
function buildSepaCoreDirectDebitPainXml(
    string $creditorName, string $creditorIban, string $creditorBic,
    string $creditorId, array $transactions
): string {
    $msgId    = 'DDC' . date('YmdHis') . substr(bin2hex(random_bytes(3)), 0, 6);
    $pmtInfId = 'PI'  . date('YmdHis') . substr(bin2hex(random_bytes(2)), 0, 4);
    $nbOfTx   = count($transactions);
    $ctrlSum  = number_format(array_sum(array_map(fn($t) => (float)$t['amount'], $transactions)), 2, '.', '');
    $collDate = $transactions[0]['collection_date'] ?? date('Y-m-d', strtotime('+2 days'));

    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = false;
    $el = function($name, $text = null) use ($doc) {
        $e = $doc->createElement($name);
        if ($text !== null && $text !== '') $e->appendChild($doc->createTextNode((string)$text));
        return $e;
    };

    $root = $doc->createElementNS('urn:iso:std:iso:20022:tech:xsd:pain.008.003.02', 'Document');
    $doc->appendChild($root);
    $init = $el('CstmrDrctDbtInitn'); $root->appendChild($init);

    $grp = $el('GrpHdr'); $init->appendChild($grp);
    $grp->appendChild($el('MsgId', $msgId));
    $grp->appendChild($el('CreDtTm', date('c')));
    $grp->appendChild($el('NbOfTxs', $nbOfTx));
    $grp->appendChild($el('CtrlSum', $ctrlSum));
    $ip = $el('InitgPty'); $ip->appendChild($el('Nm', $creditorName)); $grp->appendChild($ip);

    $pmt = $el('PmtInf'); $init->appendChild($pmt);
    $pmt->appendChild($el('PmtInfId', $pmtInfId));
    $pmt->appendChild($el('PmtMtd', 'DD'));
    $pmt->appendChild($el('NbOfTxs', $nbOfTx));
    $pmt->appendChild($el('CtrlSum', $ctrlSum));

    $ptInf = $el('PmtTpInf');
    $sl = $el('SvcLvl'); $sl->appendChild($el('Cd', 'SEPA')); $ptInf->appendChild($sl);
    $lt = $el('LclInstrm'); $lt->appendChild($el('Cd', 'CORE')); $ptInf->appendChild($lt);
    $ptInf->appendChild($el('SeqTp', $transactions[0]['sequence_type'] ?? 'RCUR'));
    $pmt->appendChild($ptInf);

    $pmt->appendChild($el('ReqdColltnDt', $collDate));

    $cr = $el('Cdtr'); $cr->appendChild($el('Nm', $creditorName)); $pmt->appendChild($cr);
    $crAcct = $el('CdtrAcct'); $crId = $el('Id'); $crId->appendChild($el('IBAN', $creditorIban)); $crAcct->appendChild($crId); $pmt->appendChild($crAcct);
    if ($creditorBic) {
        $crAgt = $el('CdtrAgt'); $fi = $el('FinInstnId'); $fi->appendChild($el('BIC', $creditorBic)); $crAgt->appendChild($fi); $pmt->appendChild($crAgt);
    }
    $crSch = $el('CdtrSchmeId');
    $crSchId = $el('Id');
    $priv = $el('PrvtId'); $othr = $el('Othr');
    $othr->appendChild($el('Id', $creditorId));
    $schNm = $el('SchmeNm'); $schNm->appendChild($el('Prtry', 'SEPA')); $othr->appendChild($schNm);
    $priv->appendChild($othr); $crSchId->appendChild($priv); $crSch->appendChild($crSchId); $pmt->appendChild($crSch);

    foreach ($transactions as $tx) {
        $dbt = $el('DrctDbtTxInf'); $pmt->appendChild($dbt);
        $pid = $el('PmtId'); $pid->appendChild($el('EndToEndId', 'E2E' . substr(bin2hex(random_bytes(7)), 0, 14))); $dbt->appendChild($pid);
        $amt = $el('InstdAmt', number_format((float)$tx['amount'], 2, '.', '')); $amt->setAttribute('Ccy', 'EUR'); $dbt->appendChild($amt);
        $tx2 = $el('DrctDbtTx');
        $mndt = $el('MndtRltdInf');
        $mndt->appendChild($el('MndtId', $tx['mandate_reference']));
        $mndt->appendChild($el('DtOfSgntr', date('Y-m-d')));
        $tx2->appendChild($mndt); $dbt->appendChild($tx2);
        if ($tx['debtor_bic'] ?? '') {
            $dbAgt = $el('DbtrAgt'); $dfi = $el('FinInstnId'); $dfi->appendChild($el('BIC', $tx['debtor_bic'])); $dbAgt->appendChild($dfi); $dbt->appendChild($dbAgt);
        }
        $dbtr = $el('Dbtr'); $dbtr->appendChild($el('Nm', $tx['debtor_name'])); $dbt->appendChild($dbtr);
        $dbAcct = $el('DbtrAcct'); $dbId = $el('Id'); $dbId->appendChild($el('IBAN', $tx['debtor_iban'])); $dbAcct->appendChild($dbId); $dbt->appendChild($dbAcct);
        if ($tx['purpose'] ?? '') {
            $rmt = $el('RmtInf'); $rmt->appendChild($el('Ustrd', $tx['purpose'])); $dbt->appendChild($rmt);
        }
    }

    return $doc->saveXML();
}
