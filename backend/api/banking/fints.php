<?php
// backend/api/banking/fints.php
//
// FinTS/HBCI-Integration für Kontoabrufe und Überweisungen
//
// SICHERHEIT:
// - PIN wird NIEMALS gespeichert, nur für die aktuelle FinTS-Session verwendet
// - TAN wird NIEMALS gespeichert, nur für die aktuelle Aktion verwendet
// - FinTS-Session-Daten werden in PHP-Session gehalten (serverseitig, kurzlebig)
// - Alle Verbindungen laufen über HTTPS (TLS)

/**
 * FinTS Produkt-ID aus der Firmenkonfiguration lesen.
 *
 * Jeder Betreiber von OpensourceERP muss eine eigene 25-stellige FinTS-Produkt-ID
 * bei der Deutschen Kreditwirtschaft beantragen. Die ID identifiziert die
 * Banking-Software im HKVVB-Segment gegenueber den Banksystemen.
 *
 * Registrierung: https://www.fints.org/de/hersteller/produktregistrierung
 * Formular:      docs/features/FinTS-Produktregistrierung_V1.0.4.pdf
 *
 * Gespeichert wird die ID in defaults_oserp unter dem Key 'fints_product_id'.
 * Eingetragen wird sie ueber die Firmenkonfiguration (Tab SEPA/Bank).
 *
 * @return string|null 25-stellige Produkt-ID oder null falls nicht konfiguriert
 */
function getFintsProductId() {
    static $cached = false;
    static $value = null;
    if ($cached) {
        return $value;
    }
    $db = DbhCompany::begin();
    $row = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = 'fints_product_id'",
        []
    );
    $raw = $row ? trim((string)($row['value'] ?? '')) : '';
    $value = $raw !== '' ? $raw : null;
    $cached = true;
    return $value;
}

/**
 * Ist FinTS-Debug-Logging aktiv? Wird in defaults_oserp.fints_debug (bool-like)
 * geschaltet. Wenn 'true'/'1'/'on' → Logger zeichnet FinTS-Kommunikation in
 * OSERP_DEBUG_LOG_FILE auf.
 */
function isFintsDebugEnabled() {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $db = DbhCompany::begin();
        $row = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'fints_debug'");
        $v = strtolower(trim((string)($row['value'] ?? '')));
        // 't' = PostgreSQL-Boolean-true (wird von PDO::PARAM_BOOL so gespeichert)
        $cached = in_array($v, ['1', 't', 'true', 'on', 'yes', 'ja'], true);
    } catch (\Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Schreibt einen FinTS-Log-Eintrag mit Kontext in OSERP_DEBUG_LOG_FILE.
 */
function fintsLog($message, array $context = [], $level = DLOG_INF) {
    $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    writeLog('[FinTS] ' . $message . $ctx, true, $level);
}

/**
 * PSR-3 Logger-Adapter: leitet die FinTS-Kommunikation an writeLog() weiter.
 * Wird als anonyme Klasse erst bei Bedarf instanziiert, weil Psr\Log\AbstractLogger
 * erst nach require_once vendor/autoload.php verfuegbar ist.
 */
function fintsMakeDebugLogger() {
    return new class extends \Psr\Log\AbstractLogger {
        public function log($level, \Stringable|string $message, array $context = []): void {
            fintsLog('[' . strtoupper((string)$level) . '] ' . $message, $context, DLOG_DBG);
        }
    };
}

/**
 * Erstellt eine FinTs-Instanz gemaess phpFinTS 3.x API.
 *
 * Ersetzt den frueheren Konstruktor-Aufruf (ging in 2.x, in 3.x nicht mehr
 * moeglich weil FinTs::__construct() protected ist). Intern:
 * - FinTsOptions mit productName (= unsere Produkt-ID) + productVersion
 * - Credentials::create()
 * - FinTs::new()
 *
 * @param array       $config            Zeile aus bank_account_fints (+ bank_code)
 * @param string      $pin               Online-Banking PIN
 * @param string|null $persistedInstance Optional: zuvor per $fints->persist() gesicherter Zustand
 * @return \Fhp\FinTs
 */
function fintsCreate($config, $pin, $persistedInstance = null) {
    $productId = getFintsProductId();
    if (!$productId) {
        throw new \RuntimeException('FinTS Produkt-ID ist nicht konfiguriert (defaults_oserp.fints_product_id).');
    }

    // BLZ immer ohne Whitespace — die UI speichert oft "1705 4040",
    // FinTS erwartet aber "17054040" (Bank lehnt sonst mit 9010/9050 ab).
    $bankCode = preg_replace('/\s+/', '', (string)$config['fints_bank_code']);

    $options = new \Fhp\Options\FinTsOptions();
    $options->productName = $productId;
    $options->productVersion = '1.0';
    $options->bankCode = $bankCode;
    $options->url = trim((string)$config['fints_url']);

    $credentials = \Fhp\Options\Credentials::create($config['fints_username'], $pin);

    $fints = \Fhp\FinTs::new($options, $credentials, $persistedInstance);

    if (isFintsDebugEnabled()) {
        $fints->setLogger(fintsMakeDebugLogger());
        fintsLog('FinTs-Instanz erzeugt', [
            'url'       => $options->url,
            'bankCode'  => $options->bankCode,
            'user'      => $config['fints_username'],
            'product'   => $options->productName,
            'persisted' => $persistedInstance !== null,
        ]);
    }

    return $fints;
}

/**
 * Baut ein PAIN.001.001.03 XML fuer eine einzelne SEPA-Ueberweisung.
 *
 * Wird an \Fhp\Action\SendSEPATransfer::create() uebergeben (phpFinTS 3.x).
 * DOMDocument escapt alle Textinhalte automatisch — keine XML-Injection moeglich.
 * Der Ausfuehrungstag '1999-01-01' ist das in der SEPA-Spec vorgesehene Flag
 * fuer "sofort ausfuehren".
 */
function buildSepaCreditTransferPainXml($senderName, $senderIban, $senderBic,
                                        $recipientName, $recipientIban, $recipientBic,
                                        $amount, $purpose) {
    $msgId    = 'MSG' . date('YmdHis') . substr(bin2hex(random_bytes(4)), 0, 6);
    $pmtInfId = 'PMT' . date('YmdHis');
    $e2eId    = 'ETE' . substr(bin2hex(random_bytes(6)), 0, 10);
    $amountStr = number_format((float)$amount, 2, '.', '');

    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = false;

    // Element mit Text-Inhalt (createTextNode escapt &, <, > korrekt)
    $el = function($name, $text = null) use ($doc) {
        $e = $doc->createElement($name);
        if ($text !== null && $text !== '') {
            $e->appendChild($doc->createTextNode((string)$text));
        }
        return $e;
    };

    $root = $doc->createElementNS('urn:iso:std:iso:20022:tech:xsd:pain.001.001.03', 'Document');
    $doc->appendChild($root);

    $cstmr = $el('CstmrCdtTrfInitn');
    $root->appendChild($cstmr);

    // GrpHdr
    $grpHdr = $el('GrpHdr');
    $cstmr->appendChild($grpHdr);
    $grpHdr->appendChild($el('MsgId', $msgId));
    $grpHdr->appendChild($el('CreDtTm', date('c')));
    $grpHdr->appendChild($el('NbOfTxs', '1'));
    $grpHdr->appendChild($el('CtrlSum', $amountStr));
    $initgPty = $el('InitgPty');
    $initgPty->appendChild($el('Nm', $senderName));
    $grpHdr->appendChild($initgPty);

    // PmtInf
    $pmtInf = $el('PmtInf');
    $cstmr->appendChild($pmtInf);
    $pmtInf->appendChild($el('PmtInfId', $pmtInfId));
    $pmtInf->appendChild($el('PmtMtd', 'TRF'));
    $pmtInf->appendChild($el('NbOfTxs', '1'));
    $pmtInf->appendChild($el('CtrlSum', $amountStr));

    $pmtTpInf = $el('PmtTpInf');
    $svcLvl = $el('SvcLvl');
    $svcLvl->appendChild($el('Cd', 'SEPA'));
    $pmtTpInf->appendChild($svcLvl);
    $pmtInf->appendChild($pmtTpInf);

    $pmtInf->appendChild($el('ReqdExctnDt', '1999-01-01'));

    $dbtr = $el('Dbtr');
    $dbtr->appendChild($el('Nm', $senderName));
    $pmtInf->appendChild($dbtr);

    $dbtrAcct = $el('DbtrAcct');
    $dbtrAcctId = $el('Id');
    $dbtrAcctId->appendChild($el('IBAN', $senderIban));
    $dbtrAcct->appendChild($dbtrAcctId);
    $pmtInf->appendChild($dbtrAcct);

    if (!empty($senderBic)) {
        $dbtrAgt = $el('DbtrAgt');
        $finInstnId = $el('FinInstnId');
        $finInstnId->appendChild($el('BIC', $senderBic));
        $dbtrAgt->appendChild($finInstnId);
        $pmtInf->appendChild($dbtrAgt);
    }

    $pmtInf->appendChild($el('ChrgBr', 'SLEV'));

    // Einzeltransaktion
    $cdtTrfTxInf = $el('CdtTrfTxInf');
    $pmtInf->appendChild($cdtTrfTxInf);

    $pmtId = $el('PmtId');
    $pmtId->appendChild($el('EndToEndId', $e2eId));
    $cdtTrfTxInf->appendChild($pmtId);

    $amt = $el('Amt');
    $instdAmt = $el('InstdAmt', $amountStr);
    $instdAmt->setAttribute('Ccy', 'EUR');
    $amt->appendChild($instdAmt);
    $cdtTrfTxInf->appendChild($amt);

    if (!empty($recipientBic)) {
        $cdtrAgt = $el('CdtrAgt');
        $finInstnId = $el('FinInstnId');
        $finInstnId->appendChild($el('BIC', $recipientBic));
        $cdtrAgt->appendChild($finInstnId);
        $cdtTrfTxInf->appendChild($cdtrAgt);
    }

    $cdtr = $el('Cdtr');
    $cdtr->appendChild($el('Nm', $recipientName));
    $cdtTrfTxInf->appendChild($cdtr);

    $cdtrAcct = $el('CdtrAcct');
    $cdtrAcctId = $el('Id');
    $cdtrAcctId->appendChild($el('IBAN', $recipientIban));
    $cdtrAcct->appendChild($cdtrAcctId);
    $cdtTrfTxInf->appendChild($cdtrAcct);

    if (!empty($purpose)) {
        $rmtInf = $el('RmtInf');
        $rmtInf->appendChild($el('Ustrd', $purpose));
        $cdtTrfTxInf->appendChild($rmtInf);
    }

    return $doc->saveXML();
}

/**
 * FinTS: Umsaetze von der Bank abrufen
 *
 * Startet eine FinTS-Session, ruft Umsaetze ab und importiert sie.
 * Falls TAN erforderlich: gibt TAN-Challenge zurueck.
 *
 * @param int    $data['bank_account_id'] Bankkonto-ID
 * @param string $data['pin']            Online-Banking PIN (wird nicht gespeichert)
 * @param string $data['from_date']      Ab-Datum (optional, default: letzte Sync oder -30 Tage)
 * @param string $data['to_date']        Bis-Datum (optional, default: heute)
 * @testdata {"bank_account_id": 1, "pin": "12345"}
 */
function fintsSyncTransactions($data) {
    $db = DbhCompany::begin();

    $bankAccountId = intval($data['bank_account_id'] ?? 0);
    $pin = $data['pin'] ?? '';

    if ($bankAccountId <= 0 || empty($pin)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID und PIN sind Pflicht');
        return;
    }

    // FinTS-Config laden
    $config = $db->getOne(<<<SQL
        SELECT baf.*, ba.iban, ba.account_number, ba.bank_code
        FROM bank_account_fints baf
        JOIN bank_accounts ba ON ba.id = baf.bank_account_id
        WHERE baf.bank_account_id = :bank_account_id
    SQL, ['bank_account_id' => $bankAccountId]);

    if (!$config) {
        resultInfo(false, 'NO_FINTS_CONFIG', 'Keine FinTS-Konfiguration fuer dieses Konto vorhanden');
        return;
    }

    // Pruefen ob phpFinTS via Composer installiert ist
    $autoloadPath = __DIR__.'/../../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        resultInfo(false, 'FINTS_NOT_INSTALLED', 'Composer-Abhaengigkeiten fehlen. Bitte "composer install" im backend/ Verzeichnis ausfuehren.');
        return;
    }

    require_once $autoloadPath;

    if (!class_exists('Fhp\FinTs')) {
        resultInfo(false, 'FINTS_NOT_INSTALLED', 'phpFinTS-Klasse nicht gefunden. Bitte "composer install" in backend/ ausfuehren.');
        return;
    }

    try {
        // Wenn ein persist-Token aus fruehrem erfolgreichen Sync vorhanden ist,
        // wiederverwenden — die Bank erkennt das Geraet und verzichtet auf
        // die Login-SCA (nur die Action selbst wird noch per TAN bestaetigt).
        $savedState = !empty($config['persisted_state']) ? $config['persisted_state'] : null;
        $fints = fintsCreate($config, $pin, $savedState);

        // PSD2: TAN-Verfahren auswaehlen + login (erforderlich ab phpFinTS 3.x).
        // Bei geladenem State ist der TAN-Mode bereits gespeichert.
        if (!$savedState) {
            $tanMode = !empty($config['fints_tan_mode']) ? (int)$config['fints_tan_mode'] : null;
            fintsSelectTanModeWithMedium($fints, $tanMode);
        }
        $login = $fints->login();
        if ($login->needsTan()) {
            session_start();
            $_SESSION['fints_action'] = serialize($login);
            $_SESSION['fints_persist'] = $fints->persist();
            $_SESSION['fints_bank_account_id'] = $bankAccountId;
            $_SESSION['fints_stage'] = 'login';
            $tanRequest = $login->getTanRequest();
            resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $login ?? $getStatement ?? $sepaTransfer ?? $action));
            return;
        }

        // Zeitraum bestimmen
        $fromDate = $data['from_date']
            ?? $config['sync_from_date']
            ?? ($config['last_sync'] ? date('Y-m-d', strtotime($config['last_sync'])) : date('Y-m-d', strtotime('-30 days')));
        $toDate = $data['to_date'] ?? date('Y-m-d');

        // Konten abrufen (HKSPA)
        $getSepa = \Fhp\Action\GetSEPAAccounts::create();
        $fints->execute($getSepa);
        $accounts = $getSepa->getAccounts();

        // Passendes Konto finden (IBAN oder Kontonummer)
        $targetAccount = null;
        foreach ($accounts as $account) {
            if ($config['iban'] && fintsNormalizeIban($account->getIban()) === fintsNormalizeIban($config['iban'])) {
                $targetAccount = $account;
                break;
            }
            if ($config['account_number'] && $account->getAccountNumber() === $config['account_number']) {
                $targetAccount = $account;
                break;
            }
        }

        if (!$targetAccount) {
            resultInfo(false, 'ACCOUNT_NOT_FOUND', 'Bankkonto nicht im FinTS-Zugang gefunden');
            return;
        }

        // Umsaetze abrufen
        $getStatement = \Fhp\Action\GetStatementOfAccount::create($targetAccount, new \DateTime($fromDate), new \DateTime($toDate));
        $fints->execute($getStatement);

        if ($getStatement->needsTan()) {
            // TAN-Challenge: Session-Daten speichern
            session_start();
            $_SESSION['fints_action'] = serialize($getStatement);
            $_SESSION['fints_persist'] = $fints->persist();
            $_SESSION['fints_bank_account_id'] = $bankAccountId;
            $_SESSION['fints_pin'] = ''; // PIN wird NICHT in Session gespeichert

            $tanRequest = $getStatement->getTanRequest();

            resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $login ?? $getStatement ?? $sepaTransfer ?? $action));
            return;
        }

        // Kein TAN noetig — Umsaetze direkt verarbeiten
        $statements = $getStatement->getStatement()->getStatements();
        $importedCount = importFintsStatements($db, $bankAccountId, $statements, $fromDate, $toDate);

        // Sync-Zeitpunkt + Device-Trust-State aktualisieren
        fintsPersistTrustState($fints, $db, $bankAccountId);

        resultInfo(true, 'Synchronisiert', [
            'imported_count' => $importedCount,
            'from_date' => $fromDate,
            'to_date' => $toDate
        ]);

    } catch (\Exception $e) {
        fintsLogException('Sync', $e, ['bank_account_id' => $bankAccountId, 'url' => $config['fints_url'] ?? null]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . $e->getMessage());
    }
}

/**
 * FinTS: TAN einreichen fuer laufende Aktion
 *
 * @param string $data['tan']            TAN-Eingabe
 * @param string $data['pin']            Online-Banking PIN (nochmal, da nicht in Session)
 * @param int    $data['bank_account_id'] Bankkonto-ID (zur Verifizierung)
 * @testdata {"tan": "123456", "pin": "12345", "bank_account_id": 1}
 */
function fintsSubmitTan($data) {
    $db = DbhCompany::begin();

    // Leerer TAN ist erlaubt bei Decoupled-Verfahren (pushTAN 2.0) — der User
    // hat in der Banking-App bestaetigt, wir fragen mit checkDecoupledSubmission
    // ab ob die Freigabe schon bei der Bank angekommen ist.
    $tan = trim($data['tan'] ?? '');
    $pin = $data['pin'] ?? '';
    $bankAccountId = intval($data['bank_account_id'] ?? 0);

    if (empty($pin) || $bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'PIN und Bankkonto-ID sind Pflicht');
        return;
    }

    session_start();

    if (empty($_SESSION['fints_action']) || empty($_SESSION['fints_persist'])) {
        resultInfo(false, 'NO_PENDING_ACTION', 'Keine ausstehende FinTS-Aktion vorhanden');
        return;
    }

    if ($_SESSION['fints_bank_account_id'] !== $bankAccountId) {
        resultInfo(false, 'SESSION_MISMATCH', 'Bankkonto stimmt nicht mit laufender Aktion ueberein');
        return;
    }

    $config = $db->getOne(<<<SQL
        SELECT baf.*, ba.iban, ba.account_number
        FROM bank_account_fints baf
        JOIN bank_accounts ba ON ba.id = baf.bank_account_id
        WHERE baf.bank_account_id = :bank_account_id
    SQL, ['bank_account_id' => $bankAccountId]);

    if (!$config) {
        resultInfo(false, 'NO_FINTS_CONFIG', 'FinTS-Konfiguration nicht gefunden');
        return;
    }

    require_once __DIR__.'/../../vendor/autoload.php';

    try {
        $fints = fintsCreate($config, $pin, $_SESSION['fints_persist']);
        // TAN-Mode/Medium sind bereits in der persistedInstance — NICHT erneut
        // setzen. getTanModes() wuerde einen neuen personalized Dialog starten
        // und den laufenden zerstoeren (Bank-Fehler 9120 "Initialisierung fehlt").

        $action = unserialize($_SESSION['fints_action']);

        if ($tan !== '') {
            $fints->submitTan($action, $tan);
        } else {
            // Decoupled: in der App bestaetigt — pollen ob Bank die Freigabe hat
            $tanMode = $fints->getSelectedTanMode();
            if (!$tanMode || !$tanMode->isDecoupled()) {
                resultInfo(false, 'VALIDATION_ERROR', 'TAN ist erforderlich');
                return;
            }
            if (!$fints->checkDecoupledSubmission($action)) {
                // Noch nicht bestaetigt — Session halten, User soll nochmal klicken
                $_SESSION['fints_persist'] = $fints->persist();
                $_SESSION['fints_action']  = serialize($action);
                $payload = fintsBuildTanResponse($fints, $action);
                $payload['message'] = 'Freigabe in der Banking-App noch nicht eingegangen — bitte in der App bestaetigen und erneut "Fortfahren" klicken.';
                resultInfo(true, 'TAN_REQUIRED', $payload);
                return;
            }
        }

        $stage = $_SESSION['fints_stage'] ?? 'action';
        // Login-TAN: nach Erfolg die eigentliche Action nachholen (Umsaetze abrufen)
        if ($stage === 'login') {
            unset($_SESSION['fints_action'], $_SESSION['fints_persist'], $_SESSION['fints_stage']);
            $fromDate = $config['sync_from_date']
                ?? ($config['last_sync'] ? date('Y-m-d', strtotime($config['last_sync'])) : date('Y-m-d', strtotime('-30 days')));
            $toDate = date('Y-m-d');

            $getSepa = \Fhp\Action\GetSEPAAccounts::create();
            $fints->execute($getSepa);
            $targetAccount = null;
            foreach ($getSepa->getAccounts() as $acc) {
                if ($config['iban'] && fintsNormalizeIban($acc->getIban()) === fintsNormalizeIban($config['iban'])) { $targetAccount = $acc; break; }
                if ($config['account_number'] && $acc->getAccountNumber() === $config['account_number']) { $targetAccount = $acc; break; }
            }
            if (!$targetAccount) {
                resultInfo(false, 'ACCOUNT_NOT_FOUND', 'Bankkonto nicht im FinTS-Zugang gefunden');
                return;
            }
            $getStatement = \Fhp\Action\GetStatementOfAccount::create($targetAccount, new \DateTime($fromDate), new \DateTime($toDate));
            $fints->execute($getStatement);
            if ($getStatement->needsTan()) {
                // Action-TAN nach Login-TAN — zweite Runde
                session_start();
                $_SESSION['fints_action'] = serialize($getStatement);
                $_SESSION['fints_persist'] = $fints->persist();
                $_SESSION['fints_bank_account_id'] = $bankAccountId;
                $_SESSION['fints_stage'] = 'action';
                $tanRequest = $getStatement->getTanRequest();
                resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $login ?? $getStatement ?? $sepaTransfer ?? $action));
                return;
            }
            $statements = $getStatement->getStatement()->getStatements();
            $importedCount = importFintsStatements($db, $bankAccountId, $statements, $fromDate, $toDate);
            fintsPersistTrustState($fints, $db, $bankAccountId);
            resultInfo(true, 'Synchronisiert', ['imported_count' => $importedCount]);
            return;
        }

        // Session aufraeumen
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'], $_SESSION['fints_bank_account_id'], $_SESSION['fints_stage']);

        if ($action instanceof \Fhp\Action\GetStatementOfAccount) {
            $statements = $action->getStatement()->getStatements();
            $importedCount = importFintsStatements($db, $bankAccountId, $statements, null, null);

            fintsPersistTrustState($fints, $db, $bankAccountId);

            resultInfo(true, 'Synchronisiert', ['imported_count' => $importedCount]);
        } else {
            resultInfo(true, 'TAN akzeptiert');
        }

    } catch (\Exception $e) {
        // Session bei Fehler aufraeumen
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'], $_SESSION['fints_bank_account_id'], $_SESSION['fints_stage']);
        fintsLogException('SubmitTan', $e, ['bank_account_id' => $bankAccountId]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . $e->getMessage());
    }
}

/**
 * FinTS: Ueberweisung an Bank senden
 *
 * @param int    $data['transfer_order_id'] Ueberweisungsauftrags-ID
 * @param string $data['pin']               Online-Banking PIN
 * @testdata {"transfer_order_id": 1, "pin": "12345"}
 */
function fintsSubmitTransfer($data) {
    $db = DbhCompany::begin();

    $transferId = intval($data['transfer_order_id'] ?? 0);
    $pin = $data['pin'] ?? '';

    if ($transferId <= 0 || empty($pin)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Auftrags-ID und PIN sind Pflicht');
        return;
    }

    // Auftrag laden
    $order = $db->getOne(<<<SQL
        SELECT bto.*, ba.iban as sender_iban, ba.bic as sender_bic
        FROM bank_transfer_orders bto
        JOIN bank_accounts ba ON ba.id = bto.bank_account_id
        WHERE bto.id = :id
    SQL, ['id' => $transferId]);

    if (!$order) {
        resultInfo(false, 'NOT_FOUND', 'Auftrag nicht gefunden');
        return;
    }

    if ($order['status'] !== 'draft') {
        resultInfo(false, 'NOT_SUBMITTABLE', 'Nur Entwuerfe koennen gesendet werden');
        return;
    }

    // FinTS-Config laden
    $config = $db->getOne(<<<SQL
        SELECT baf.*
        FROM bank_account_fints baf
        WHERE baf.bank_account_id = :bank_account_id
    SQL, ['bank_account_id' => $order['bank_account_id']]);

    if (!$config) {
        resultInfo(false, 'NO_FINTS_CONFIG', 'Keine FinTS-Konfiguration vorhanden');
        return;
    }

    $autoloadPath = __DIR__.'/../../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        resultInfo(false, 'FINTS_NOT_INSTALLED', 'phpFinTS ist nicht installiert');
        return;
    }

    require_once $autoloadPath;

    try {
        $fints = fintsCreate($config, $pin);
        $tanMode = !empty($config['fints_tan_mode']) ? (int)$config['fints_tan_mode'] : null;
        fintsSelectTanModeWithMedium($fints, $tanMode);
        $login = $fints->login();
        if ($login->needsTan()) {
            session_start();
            $_SESSION['fints_action'] = serialize($login);
            $_SESSION['fints_persist'] = $fints->persist();
            $_SESSION['fints_bank_account_id'] = $order['bank_account_id'];
            $_SESSION['fints_transfer_id'] = $transferId;
            $_SESSION['fints_stage'] = 'login-transfer';
            $db->execute("UPDATE bank_transfer_orders SET status = 'pending_tan', mtime = now() WHERE id = :id", ['id' => $transferId]);
            $tanRequest = $login->getTanRequest();
            resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $login ?? $getStatement ?? $sepaTransfer ?? $action));
            return;
        }

        // Absender-Name aus Firmenkonfiguration (Kontoinhaber fuer PAIN)
        $companyRow = $db->getOne("SELECT company FROM defaults");
        $senderName = trim($companyRow['company'] ?? '') ?: 'Absender';

        // SEPAAccount des Absenders
        $senderAccount = (new \Fhp\Model\SEPAAccount())
            ->setIban($order['sender_iban'])
            ->setBic($order['sender_bic'] ?? '');

        // PAIN.001.001.03 XML fuer Einzelueberweisung
        $painXml = buildSepaCreditTransferPainXml(
            $senderName,
            $order['sender_iban'],
            $order['sender_bic'] ?? '',
            $order['remote_name'],
            $order['remote_iban'],
            $order['remote_bic'] ?? '',
            floatval($order['amount']),
            $order['purpose']
        );

        $sepaTransfer = \Fhp\Action\SendSEPATransfer::create($senderAccount, $painXml);
        $fints->execute($sepaTransfer);

        if ($sepaTransfer->needsTan()) {
            // TAN-Challenge
            session_start();
            $_SESSION['fints_action'] = serialize($sepaTransfer);
            $_SESSION['fints_persist'] = $fints->persist();
            $_SESSION['fints_bank_account_id'] = $order['bank_account_id'];
            $_SESSION['fints_transfer_id'] = $transferId;

            // Status auf pending_tan setzen
            $db->execute(
                "UPDATE bank_transfer_orders SET status = 'pending_tan', mtime = now() WHERE id = :id",
                ['id' => $transferId]
            );

            $tanRequest = $sepaTransfer->getTanRequest();

            resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $login ?? $getStatement ?? $sepaTransfer ?? $action));
            return;
        }

        // Kein TAN noetig — direkt ausgefuehrt
        $db->execute(<<<SQL
            UPDATE bank_transfer_orders
            SET status = 'submitted', submitted_at = now(), mtime = now()
            WHERE id = :id
        SQL, ['id' => $transferId]);

        resultInfo(true, 'Ueberweisung gesendet');

    } catch (\Exception $e) {
        $db->execute(<<<SQL
            UPDATE bank_transfer_orders
            SET status = 'rejected', error_message = :error, mtime = now()
            WHERE id = :id
        SQL, ['id' => $transferId, 'error' => $e->getMessage()]);

        fintsLogException('SubmitTransfer', $e, ['transfer_id' => $transferId, 'url' => $config['fints_url'] ?? null]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . $e->getMessage());
    }
}

/**
 * FinTS: TAN fuer Ueberweisung einreichen
 *
 * @param string $data['tan']              TAN-Eingabe
 * @param string $data['pin']              Online-Banking PIN
 * @param int    $data['transfer_order_id'] Ueberweisungsauftrags-ID
 * @testdata {"tan": "123456", "pin": "12345", "transfer_order_id": 1}
 */
function fintsSubmitTransferTan($data) {
    $db = DbhCompany::begin();

    $tan = trim($data['tan'] ?? '');
    $pin = $data['pin'] ?? '';
    $transferId = intval($data['transfer_order_id'] ?? 0);

    if (empty($pin) || $transferId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'PIN und Auftrags-ID sind Pflicht');
        return;
    }

    session_start();

    if (empty($_SESSION['fints_action']) || empty($_SESSION['fints_persist'])) {
        resultInfo(false, 'NO_PENDING_ACTION', 'Keine ausstehende FinTS-Aktion');
        return;
    }

    if (($_SESSION['fints_transfer_id'] ?? 0) !== $transferId) {
        resultInfo(false, 'SESSION_MISMATCH', 'Auftrags-ID stimmt nicht');
        return;
    }

    $config = $db->getOne(<<<SQL
        SELECT baf.*
        FROM bank_account_fints baf
        WHERE baf.bank_account_id = :bank_account_id
    SQL, ['bank_account_id' => $_SESSION['fints_bank_account_id']]);

    require_once __DIR__.'/../../vendor/autoload.php';

    try {
        $fints = fintsCreate($config, $pin, $_SESSION['fints_persist']);
        // TAN-Mode/Medium sind in persistedInstance — NICHT erneut setzen (s.o. 9120)
        $action = unserialize($_SESSION['fints_action']);

        if ($tan !== '') {
            $fints->submitTan($action, $tan);
        } else {
            $tm = $fints->getSelectedTanMode();
            if (!$tm || !$tm->isDecoupled()) {
                resultInfo(false, 'VALIDATION_ERROR', 'TAN ist erforderlich');
                return;
            }
            if (!$fints->checkDecoupledSubmission($action)) {
                $_SESSION['fints_persist'] = $fints->persist();
                $_SESSION['fints_action']  = serialize($action);
                $payload = fintsBuildTanResponse($fints, $action);
                $payload['message'] = 'Freigabe in der Banking-App noch nicht eingegangen — bitte in der App bestaetigen und erneut "Fortfahren" klicken.';
                resultInfo(true, 'TAN_REQUIRED', $payload);
                return;
            }
        }

        $stage = $_SESSION['fints_stage'] ?? 'transfer';
        // Login-TAN beim Transfer: nach Erfolg den eigentlichen SEPA-Transfer ausfuehren
        if ($stage === 'login-transfer') {
            unset($_SESSION['fints_action'], $_SESSION['fints_persist'], $_SESSION['fints_stage']);

            $order = $db->getOne(<<<SQL
                SELECT bto.*, ba.iban as sender_iban, ba.bic as sender_bic
                FROM bank_transfer_orders bto
                JOIN bank_accounts ba ON ba.id = bto.bank_account_id
                WHERE bto.id = :id
            SQL, ['id' => $transferId]);

            $companyRow = $db->getOne("SELECT company FROM defaults");
            $senderName = trim($companyRow['company'] ?? '') ?: 'Absender';
            $senderAccount = (new \Fhp\Model\SEPAAccount())
                ->setIban($order['sender_iban'])
                ->setBic($order['sender_bic'] ?? '');
            $painXml = buildSepaCreditTransferPainXml(
                $senderName, $order['sender_iban'], $order['sender_bic'] ?? '',
                $order['remote_name'], $order['remote_iban'], $order['remote_bic'] ?? '',
                floatval($order['amount']), $order['purpose']
            );
            $sepaTransfer = \Fhp\Action\SendSEPATransfer::create($senderAccount, $painXml);
            $fints->execute($sepaTransfer);

            if ($sepaTransfer->needsTan()) {
                session_start();
                $_SESSION['fints_action'] = serialize($sepaTransfer);
                $_SESSION['fints_persist'] = $fints->persist();
                $_SESSION['fints_bank_account_id'] = $order['bank_account_id'];
                $_SESSION['fints_transfer_id'] = $transferId;
                $_SESSION['fints_stage'] = 'transfer';
                $tanRequest = $sepaTransfer->getTanRequest();
                resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $login ?? $getStatement ?? $sepaTransfer ?? $action));
                return;
            }
            $db->execute("UPDATE bank_transfer_orders SET status = 'submitted', submitted_at = now(), mtime = now() WHERE id = :id", ['id' => $transferId]);
            resultInfo(true, 'Ueberweisung gesendet');
            return;
        }

        // Session aufraeumen
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'],
              $_SESSION['fints_bank_account_id'], $_SESSION['fints_transfer_id'], $_SESSION['fints_stage']);

        // Auftrag als gesendet markieren
        $db->execute(<<<SQL
            UPDATE bank_transfer_orders
            SET status = 'submitted', submitted_at = now(), mtime = now()
            WHERE id = :id
        SQL, ['id' => $transferId]);

        resultInfo(true, 'Ueberweisung gesendet');

    } catch (\Exception $e) {
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'],
              $_SESSION['fints_bank_account_id'], $_SESSION['fints_transfer_id'], $_SESSION['fints_stage']);

        $db->execute(<<<SQL
            UPDATE bank_transfer_orders
            SET status = 'rejected', error_message = :error, mtime = now()
            WHERE id = :id
        SQL, ['id' => $transferId, 'error' => $e->getMessage()]);

        fintsLogException('SubmitTransferTan', $e, ['transfer_id' => $transferId]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . $e->getMessage());
    }
}

/**
 * FinTS: Kontostand abrufen
 *
 * @param int    $data['bank_account_id'] Bankkonto-ID
 * @param string $data['pin']            Online-Banking PIN
 * @testdata {"bank_account_id": 1, "pin": "12345"}
 */
function fintsGetBalance($data) {
    $db = DbhCompany::begin();

    $bankAccountId = intval($data['bank_account_id'] ?? 0);
    $pin = $data['pin'] ?? '';

    if ($bankAccountId <= 0 || empty($pin)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID und PIN sind Pflicht');
        return;
    }

    $config = $db->getOne(<<<SQL
        SELECT baf.*, ba.iban, ba.account_number
        FROM bank_account_fints baf
        JOIN bank_accounts ba ON ba.id = baf.bank_account_id
        WHERE baf.bank_account_id = :bank_account_id
    SQL, ['bank_account_id' => $bankAccountId]);

    if (!$config) {
        resultInfo(false, 'NO_FINTS_CONFIG', 'Keine FinTS-Konfiguration vorhanden');
        return;
    }

    require_once __DIR__.'/../../vendor/autoload.php';

    try {
        $fints = fintsCreate($config, $pin);
        $tanMode = !empty($config['fints_tan_mode']) ? (int)$config['fints_tan_mode'] : null;
        fintsSelectTanModeWithMedium($fints, $tanMode);
        $login = $fints->login();
        if ($login->needsTan()) {
            // Fuer Balance-Abfrage kein TAN-Flow — einfach mit Fehler abbrechen
            resultInfo(false, 'LOGIN_TAN_REQUIRED', 'Login erfordert TAN — bitte erst ueber "Umsaetze synchronisieren" anmelden.');
            return;
        }

        $getSepa = \Fhp\Action\GetSEPAAccounts::create();
        $fints->execute($getSepa);
        $accounts = $getSepa->getAccounts();
        $targetAccount = null;

        foreach ($accounts as $account) {
            if ($config['iban'] && fintsNormalizeIban($account->getIban()) === fintsNormalizeIban($config['iban'])) {
                $targetAccount = $account;
                break;
            }
        }

        if (!$targetAccount) {
            resultInfo(false, 'ACCOUNT_NOT_FOUND', 'Konto nicht gefunden');
            return;
        }

        $getBalance = \Fhp\Action\GetBalance::create($targetAccount);
        $fints->execute($getBalance);

        if ($getBalance->needsTan()) {
            resultInfo(true, 'TAN_REQUIRED', ['tan_required' => true]);
            return;
        }

        $balance = $getBalance->getBalance();

        resultInfo(true, '', [
            'balance' => $balance->getAmount(),
            'currency' => $balance->getCurrency(),
            'date' => $balance->getValutaDate() ? $balance->getValutaDate()->format('Y-m-d') : date('Y-m-d')
        ]);

    } catch (\Exception $e) {
        fintsLogException('GetBalance', $e, ['bank_account_id' => $bankAccountId, 'url' => $config['fints_url'] ?? null]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . $e->getMessage());
    }
}

/**
 * Selektiert TAN-Verfahren und — falls das Verfahren ein TAN-Medium verlangt
 * (z.B. smsTAN, pushTAN) — das erste verfuegbare Medium. Fuer Verfahren ohne
 * Medium (chipTAN, iTAN) wird nur der Mode gesetzt.
 *
 * Ohne diesen Schritt wirft phpFinTS 3.x beim login() "tanMedium is mandatory".
 */
function fintsSelectTanModeWithMedium($fints, $tanMode) {
    if (!$tanMode) return;

    // Zuerst pruefen welche Modi fuer den Benutzer **tatsaechlich freigegeben**
    // sind (HIRMS 3920-Antwort). Die BPD enthaelt alle bankseitig unterstuetzten,
    // der Benutzer hat aber meist nur 1-2 davon freigegeben (z.B. nur pushTAN 2.0).
    $fints->selectTanMode($tanMode);
    $allowed = $fints->getTanModes();

    if (!isset($allowed[$tanMode])) {
        if (empty($allowed)) {
            fintsLog("Keine TAN-Verfahren fuer Benutzer freigegeben — Bank-Konfigurationsproblem",
                ['requestedMode' => $tanMode], DLOG_ERR);
            return;
        }
        $allowedIds = array_keys($allowed);
        $newMode = $allowedIds[0];
        fintsLog("TAN-Mode {$tanMode} nicht freigegeben, wechsle automatisch auf {$newMode}", [
            'requested' => $tanMode,
            'used'      => $newMode,
            'allowed'   => $allowedIds,
        ], DLOG_WRN);
        $tanMode = $newMode;
        $fints->selectTanMode($tanMode);
    }

    if (!$allowed[$tanMode]->needsTanMedium()) {
        return;
    }

    $media = $fints->getTanMedia($tanMode);
    if (empty($media)) {
        fintsLog("TAN-Mode verlangt Medium, aber Bank liefert keines", ['tanMode' => $tanMode], DLOG_ERR);
        return;
    }
    $fints->selectTanMode($tanMode, $media[0]);
    fintsLog("TAN-Medium automatisch gewaehlt", [
        'tanMode'   => $tanMode,
        'medium'    => $media[0]->getName(),
        'available' => array_map(fn($m) => $m->getName(), $media),
    ]);
}

/**
 * Normalisiert eine IBAN fuer Vergleiche: Leerzeichen entfernen, uppercase.
 * In DB sind IBANs oft mit Spaces gespeichert ("DE08 1705 ..."), die
 * FinTS-Antwort liefert sie aber kompakt ("DE08170540...").
 */
function fintsNormalizeIban($iban) {
    return strtoupper(preg_replace('/\s+/', '', (string)$iban));
}

/**
 * Speichert den Device-Trust-State (Kundensystem-ID) in bank_account_fints,
 * damit die Bank das Geraet beim naechsten Login ohne SCA wiedererkennt.
 *
 * Wichtig: VOR persist() wird forgetDialog() gerufen, sodass die aktuelle
 * (und bei der naechsten Session laengst abgelaufene) dialogId nicht mit
 * gespeichert wird — sonst bekommt man Fehler 9010/9120 "Dialog-ID nicht
 * gueltig" beim naechsten Sync.
 */
function fintsPersistTrustState($fints, $db, $bankAccountId) {
    $fints->forgetDialog();
    $db->execute(
        "UPDATE bank_account_fints SET last_sync = now(), persisted_state = :state WHERE bank_account_id = :id",
        ['id' => $bankAccountId, 'state' => $fints->persist(true)]
    );
}

/**
 * Baut die TAN_REQUIRED-Payload. Setzt das decoupled-Flag, wenn das aktive
 * TAN-Verfahren ein Decoupled-Verfahren ist (z.B. pushTAN 2.0) — in dem Fall
 * tippt der User keine TAN, sondern bestaetigt in der Banking-App und das
 * Frontend ruft spaeter `fintsConfirmDecoupled` auf.
 */
function fintsBuildTanResponse($fints, $action) {
    $tanRequest = $action->getTanRequest();
    $tanMode    = $fints->getSelectedTanMode();
    $decoupled  = $tanMode && $tanMode->isDecoupled();
    return [
        'tan_required'     => true,
        'decoupled'        => $decoupled,
        'tan_medium'       => $tanRequest->getTanMediumName() ?? 'Unbekannt',
        'challenge'        => $tanRequest->getChallenge(),
        'challenge_hhduc'  => $tanRequest->getChallengeHhdUc()?->getData() ?? null,
    ];
}

/**
 * Einheitlicher Exception-Logger — immer loggen (auch ohne fints_debug),
 * damit nachvollziehbar ist was schiefging. Bei ServerException werden die
 * Bank-Rueckmeldungscodes mitgelogged (HIRMG/HIRMS).
 */
function fintsLogException($op, \Throwable $e, array $context = []) {
    $ctx = array_merge($context, [
        'exception' => get_class($e),
        'message'   => $e->getMessage(),
        'file'      => basename($e->getFile()) . ':' . $e->getLine(),
    ]);
    if ($e instanceof \Fhp\Protocol\ServerException) {
        // Bank-seitige FinTS-Fehlercodes extrahieren (falls moeglich)
        $ctx['server_errors'] = method_exists($e, 'getErrors') ? $e->getErrors() : null;
    }
    fintsLog("{$op} fehlgeschlagen", $ctx, DLOG_ERR);
    if (isFintsDebugEnabled()) {
        fintsLog("{$op} Stacktrace", ['trace' => $e->getTraceAsString()], DLOG_DBG);
    }
}

// ════════════════════════════════════════════════════════════
// INTERNE HILFSFUNKTIONEN (nicht als API-Action erreichbar)
// ════════════════════════════════════════════════════════════

/**
 * Importiert FinTS-Kontoauszuege in bank_transactions
 * Duplikate werden anhand von Datum + Betrag + Verwendungszweck erkannt
 *
 * @param object $db               Datenbankverbindung
 * @param int    $bankAccountId    Bankkonto-ID
 * @param array  $statements       FinTS-Kontoauszuege
 * @param string $fromDate         Von-Datum
 * @param string $toDate           Bis-Datum
 * @return int   Anzahl importierter Umsaetze
 */
function importFintsStatements($db, $bankAccountId, $statements, $fromDate, $toDate) {
    // Import-Log erstellen
    $log = $db->getOne(<<<SQL
        INSERT INTO bank_import_log (
            bank_account_id, from_date, to_date, import_source
        ) VALUES (
            :bank_account_id, :from_date, :to_date, 'fints'
        )
        RETURNING id
    SQL, [
        'bank_account_id' => $bankAccountId,
        'from_date' => $fromDate,
        'to_date' => $toDate
    ]);

    $importId = $log['id'];
    $importedCount = 0;

    // Waehrungs-ID fuer EUR holen (Fallback)
    $eurCurrency = $db->getOne("SELECT id FROM currencies WHERE name = 'EUR' LIMIT 1");
    $currencyId = $eurCurrency ? $eurCurrency['id'] : 1;

    foreach ($statements as $statement) {
        foreach ($statement->getTransactions() as $transaction) {
            // getAmount() liefert in phpFinTS 3.x immer einen positiven Betrag.
            // Vorzeichen steht separat in getCreditDebit() ('credit'/'debit'
            // via Transaction::CD_CREDIT / CD_DEBIT — keine einzelnen Buchstaben).
            $rawAmount = abs((float)$transaction->getAmount());
            $isDebit = $transaction->getCreditDebit() === \Fhp\Model\StatementOfAccount\Transaction::CD_DEBIT;
            $amount = $isDebit ? -$rawAmount : $rawAmount;
            $transdate = $transaction->getBookingDate() ? $transaction->getBookingDate()->format('Y-m-d') : date('Y-m-d');
            $valutadate = $transaction->getValutaDate() ? $transaction->getValutaDate()->format('Y-m-d') : $transdate;
            $purpose = $transaction->getMainDescription() ?? '';
            $remoteName = $transaction->getName() ?? '';
            $remoteIban = $transaction->getAccountNumber() ?? '';
            $remoteBic = $transaction->getBankCode() ?? '';
            $endToEndId = $transaction->getEndToEndID() ?? '';
            // getPrimanota() aus 2.x heisst jetzt getPN() und liefert int
            $primanota = (string)($transaction->getPN() ?: '');
            $bookingKey = $transaction->getBookingCode() ?? '';

            // Duplikaterkennung: gleicher Tag + Betrag + Verwendungszweck + Konto
            $existing = $db->getOne(<<<SQL
                SELECT id FROM bank_transactions
                WHERE local_bank_account_id = :account_id
                  AND transdate = :transdate
                  AND amount = :amount
                  AND COALESCE(purpose, '') = :purpose
                  AND COALESCE(end_to_end_id, '') = :end_to_end_id
                LIMIT 1
            SQL, [
                'account_id' => $bankAccountId,
                'transdate' => $transdate,
                'amount' => $amount,
                'purpose' => $purpose,
                'end_to_end_id' => $endToEndId
            ]);

            if ($existing) {
                continue; // Duplikat ueberspringen
            }

            $db->execute(<<<SQL
                INSERT INTO bank_transactions (
                    local_bank_account_id, transdate, valutadate, amount,
                    remote_name, remote_iban, remote_bank_code, remote_account_number,
                    purpose, transaction_code,
                    end_to_end_id, currency_id,
                    match_status
                ) VALUES (
                    :account_id, :transdate, :valutadate, :amount,
                    :remote_name, :remote_iban, :remote_bank_code, :remote_account_number,
                    :purpose, :transaction_code,
                    :end_to_end_id, :currency_id,
                    'unmatched'
                )
            SQL, [
                'account_id' => $bankAccountId,
                'transdate' => $transdate,
                'valutadate' => $valutadate,
                'amount' => $amount,
                'remote_name' => $remoteName,
                'remote_iban' => $remoteIban,
                'remote_bank_code' => $remoteBic,
                'remote_account_number' => $remoteIban,
                'purpose' => $purpose,
                'transaction_code' => $bookingKey,
                'end_to_end_id' => $endToEndId,
                'currency_id' => $currencyId,
            ]);

            $importedCount++;
        }
    }

    // Import-Log aktualisieren
    $db->execute(
        "UPDATE bank_import_log SET transaction_count = :count WHERE id = :id",
        ['count' => $importedCount, 'id' => $importId]
    );

    return $importedCount;
}
