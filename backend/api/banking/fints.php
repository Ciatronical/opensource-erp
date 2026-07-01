<?php
// backend/api/banking/fints.php
//
// FinTS/HBCI-Integration für Kontoabrufe und Überweisungen
//
// SICHERHEIT:
// - PIN kann optional AES-256-GCM-verschlüsselt in bank_account_fints gespeichert werden
// - Schlüssel wird aus DB_AUTH_PASS abgeleitet — nie in der DB gespeichert
// - TAN wird NIEMALS gespeichert, nur für die aktuelle Aktion verwendet
// - FinTS-Session-Daten werden in PHP-Session gehalten (serverseitig, kurzlebig)
// - Alle Verbindungen laufen über HTTPS (TLS)

// ════════════════════════════════════════════════════════════
// PIN-Verschlüsselung (AES-256-GCM)
// ════════════════════════════════════════════════════════════

function fintsPinKey(): string {
    return hash('sha256', DB_AUTH_PASS . ':fints_pin_v1', true);
}

function fintsPinEncrypt(string $pin): string {
    $key = fintsPinKey();
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($pin, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return base64_encode($iv . $tag . $ct);
}

function fintsPinDecrypt(string $encrypted): string {
    $key = fintsPinKey();
    $raw = base64_decode($encrypted);
    if (strlen($raw) < 29) return '';
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $pin = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $pin === false ? '' : $pin;
}

/**
 * FinTS: Online-Banking-PIN verschlüsselt in defaults_oserp speichern.
 *
 * @param int    $data['bank_account_id']
 * @param string $data['pin']
 * @testdata {"bank_account_id": 1, "pin": "12345"}
 */
function fintsSavePin($data) {
    $db  = DbhCompany::begin();
    $id  = intval($data['bank_account_id'] ?? 0);
    $pin = $data['pin'] ?? '';
    if ($id <= 0 || $pin === '') {
        resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID und PIN sind Pflicht');
        return;
    }
    $key = 'fints_pin_' . $id;
    $db->execute(
        "INSERT INTO defaults_oserp (key, value, mtime) VALUES (:key, :val, now())
         ON CONFLICT (key) DO UPDATE SET value = :val, mtime = now()",
        ['key' => $key, 'val' => fintsPinEncrypt($pin)]
    );
    resultInfo(true, 'PIN gespeichert');
}

/**
 * FinTS: Gespeicherte PIN laden (entschlüsselt).
 *
 * @param int $data['bank_account_id']
 * @testdata {"bank_account_id": 1}
 */
function fintsLoadPin($data) {
    $db  = DbhCompany::begin();
    $id  = intval($data['bank_account_id'] ?? 0);
    if ($id <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID ist Pflicht'); return; }
    $row = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = :key",
        ['key' => 'fints_pin_' . $id]
    );
    if (!$row) {
        resultInfo(true, '', ['pin' => '', 'saved' => false]);
        return;
    }
    resultInfo(true, '', ['pin' => fintsPinDecrypt($row['value']), 'saved' => true]);
}

/**
 * FinTS: Gespeicherte PIN löschen.
 *
 * @param int $data['bank_account_id']
 * @testdata {"bank_account_id": 1}
 */
function fintsDeletePin($data) {
    $db = DbhCompany::begin();
    $id = intval($data['bank_account_id'] ?? 0);
    if ($id <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID ist Pflicht'); return; }
    $db->execute(
        "DELETE FROM defaults_oserp WHERE key = :key",
        ['key' => 'fints_pin_' . $id]
    );
    resultInfo(true, 'PIN gelöscht');
}

/**
 * FinTS: Trust-Anker proaktiv auffrischen ohne Benutzer-Interaktion.
 *
 * Verwendet die gespeicherte PIN und den gespeicherten Trust-Anker um
 * die FinTS-Sitzung zu verlängern, bevor sie abläuft.
 * Gibt 'REFRESHED' zurück wenn erfolgreich, 'EXPIRED' wenn der Trust-Anker
 * abgelaufen ist (dann muss der User einmalig per PushTAN neu authentifizieren),
 * 'NO_PIN_SAVED' / 'NO_STATE' wenn kein gespeicherter Zustand vorhanden ist.
 *
 * @param int $data['bank_account_id']
 * @testdata {"bank_account_id": 1}
 */
function fintsKeepAliveTrustAnchor($data) {
    $db            = DbhCompany::begin();
    $bankAccountId = intval($data['bank_account_id'] ?? 0);
    if ($bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID ist Pflicht');
        return;
    }

    $pinRow = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = :key",
        ['key' => 'fints_pin_' . $bankAccountId]
    );
    if (!$pinRow) { resultInfo(true, 'NO_PIN_SAVED'); return; }
    $pin = fintsPinDecrypt($pinRow['value']);
    if ($pin === '') { resultInfo(true, 'NO_PIN_SAVED'); return; }

    $config = $db->getOne(
        "SELECT baf.*, REGEXP_REPLACE(ba.bank_code, '\s+', '', 'g') AS fints_bank_code
         FROM bank_account_fints baf
         JOIN bank_accounts ba ON ba.id = baf.bank_account_id
         WHERE baf.bank_account_id = :id",
        ['id' => $bankAccountId]
    );
    if (!$config) { resultInfo(false, 'NO_FINTS_CONFIG', 'Keine FinTS-Konfiguration'); return; }

    $savedState = !empty($config['persisted_state']) ? $config['persisted_state'] : null;
    if (!$savedState) { resultInfo(true, 'NO_STATE'); return; }

    require_once __DIR__.'/../../vendor/autoload.php';

    try {
        $fints = fintsCreate($config, $pin, $savedState);
        $login = $fints->login();
        if ($login->needsTan()) {
            // Trust-Anker abgelaufen — stille Auffrischung nicht möglich
            resultInfo(true, 'EXPIRED');
            return;
        }
        fintsPersistTrustState($fints, $db, $bankAccountId);
        resultInfo(true, 'REFRESHED');
    } catch (\Exception $e) {
        fintsLogException('KeepAliveTrustAnchor', $e, ['bank_account_id' => $bankAccountId]);
        resultInfo(false, 'FINTS_ERROR', fintsToUtf8($e->getMessage()));
    }
}

/**
 * FinTS: Trust-Anker für alle Konten mit gespeicherter PIN auffrischen.
 *
 * Wird vom Frontend im Hintergrund aufgerufen (alle 45 Min, app-weit).
 * Gibt pro Konto 'REFRESHED' | 'EXPIRED' | 'NO_STATE' zurück.
 *
 * @testdata {}
 */
function fintsKeepAliveAll($data) {
    $db = DbhCompany::begin();

    // Alle Konten mit gespeicherter PIN und vorhandenem Trust-Anker
    $rows = $db->getAll(
        "SELECT baf.bank_account_id,
                do2.value                                            AS pin_encrypted,
                baf.persisted_state,
                baf.fints_url,
                baf.fints_username,
                baf.fints_tan_mode,
                REGEXP_REPLACE(ba.bank_code, '\s+', '', 'g')        AS fints_bank_code
         FROM bank_account_fints baf
         JOIN bank_accounts ba      ON ba.id  = baf.bank_account_id
         JOIN defaults_oserp do2   ON do2.key = 'fints_pin_' || baf.bank_account_id
         WHERE baf.persisted_state IS NOT NULL"
    );

    if (empty($rows)) { resultInfo(true, 'NOTHING_TO_REFRESH', []); return; }

    require_once __DIR__.'/../../vendor/autoload.php';

    $results = [];
    foreach ($rows as $config) {
        $id  = $config['bank_account_id'];
        $pin = fintsPinDecrypt($config['pin_encrypted']);
        if ($pin === '') { $results[$id] = 'NO_PIN'; continue; }
        try {
            $fints = fintsCreate($config, $pin, $config['persisted_state']);
            $login = $fints->login();
            if ($login->needsTan()) { $results[$id] = 'EXPIRED'; continue; }
            fintsPersistTrustState($fints, $db, $id);
            $results[$id] = 'REFRESHED';
        } catch (\Exception $e) {
            fintsLogException('KeepAliveAll', $e, ['bank_account_id' => $id]);
            $results[$id] = 'ERROR';
        }
    }
    resultInfo(true, 'DONE', $results);
}


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
 * Konvertiert Bank-Antworten (oft Latin-1) zu UTF-8, damit Postgres beim
 * Schreiben in error_message-Spalten nicht crashed (SQLSTATE 22021).
 */
function fintsToUtf8(?string $s): string {
    if ($s === null || $s === '') return '';
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    return mb_convert_encoding($s, 'UTF-8', ['UTF-8', 'Windows-1252', 'ISO-8859-1']);
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
/**
 * Erzeugt PAIN.001.001.03-XML fuer SEPA-Ueberweisungen.
 *
 * Unterstuetzt Einzel-, Sammel-, Sofort- und Termin-Ueberweisungen — phpFinTS
 * waehlt anhand von NbOfTxs und ReqdExctnDt automatisch das passende FinTS-
 * Segment (HKCCS / HKCSE / HKCCM / HKCME). Fuer Sofort-Ueberweisung wird
 * das Magic-Datum '1999-01-01' verwendet (Konvention von phpFinTS).
 *
 * @param string $senderName    Kontoinhaber des Absenders
 * @param string $senderIban
 * @param string $senderBic     optional
 * @param array  $transactions  Liste mit ['name','iban','bic','amount','purpose']
 * @param ?string $executionDate 'YYYY-MM-DD' fuer Termin-Ueberweisung,
 *                                null fuer Sofort
 * @return string PAIN-XML
 */
/**
 * Bereinigt Text für den eingeschränkten SEPA-Zeichensatz (EPC).
 * Erlaubt: a-z A-Z 0-9 / - ? : ( ) . , ' + und Leerzeichen. Deutsche Umlaute/ß
 * und gängige Akzente werden transliteriert, alle übrigen Zeichen (z. B. der
 * Unterstrich in "Hryshchenko_Inna", &, *) durch ein Leerzeichen ersetzt.
 * Verhindert Bank-Fehler 9010 ("Zeichen außerhalb SEPA-Range").
 */
function sepaSanitizeText($s): string {
    $s = (string)$s;
    $map = [
        'ä'=>'ae','ö'=>'oe','ü'=>'ue','Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ß'=>'ss',
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','å'=>'a','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ý'=>'y','ç'=>'c','ñ'=>'n','&'=>'+',
    ];
    $s = strtr($s, $map);
    $s = preg_replace('/[^A-Za-z0-9\/?:()., \'+-]/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

function buildSepaCreditTransferPainXml($senderName, $senderIban, $senderBic, array $transactions, ?string $executionDate = null) {
    if (empty($transactions)) {
        throw new \InvalidArgumentException('Mindestens eine Transaktion erforderlich');
    }

    $msgId    = 'MSG' . date('YmdHis') . substr(bin2hex(random_bytes(4)), 0, 6);
    $pmtInfId = 'PMT' . date('YmdHis') . substr(bin2hex(random_bytes(2)), 0, 4);
    $reqdDt   = $executionDate ?: '1999-01-01'; // Magic-Datum = sofort
    $nbOfTx   = count($transactions);
    $ctrlSum  = number_format(array_sum(array_map(fn($t) => (float)$t['amount'], $transactions)), 2, '.', '');

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
    $grpHdr->appendChild($el('NbOfTxs', (string)$nbOfTx));
    $grpHdr->appendChild($el('CtrlSum', $ctrlSum));
    $initgPty = $el('InitgPty');
    $initgPty->appendChild($el('Nm', sepaSanitizeText($senderName)));
    $grpHdr->appendChild($initgPty);

    // PmtInf — ein Block fuer alle Tx (Sammel = mehrere CdtTrfTxInf)
    $pmtInf = $el('PmtInf');
    $cstmr->appendChild($pmtInf);
    $pmtInf->appendChild($el('PmtInfId', $pmtInfId));
    $pmtInf->appendChild($el('PmtMtd', 'TRF'));
    $pmtInf->appendChild($el('NbOfTxs', (string)$nbOfTx));
    $pmtInf->appendChild($el('CtrlSum', $ctrlSum));

    $pmtTpInf = $el('PmtTpInf');
    $svcLvl = $el('SvcLvl');
    $svcLvl->appendChild($el('Cd', 'SEPA'));
    $pmtTpInf->appendChild($svcLvl);
    $pmtInf->appendChild($pmtTpInf);

    $pmtInf->appendChild($el('ReqdExctnDt', $reqdDt));

    $dbtr = $el('Dbtr');
    $dbtr->appendChild($el('Nm', sepaSanitizeText($senderName)));
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

    foreach ($transactions as $tx) {
        $amountStr = number_format((float)$tx['amount'], 2, '.', '');
        $e2eId = 'ETE' . substr(bin2hex(random_bytes(8)), 0, 14);

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

        if (!empty($tx['bic'])) {
            $cdtrAgt = $el('CdtrAgt');
            $finInstnId = $el('FinInstnId');
            $finInstnId->appendChild($el('BIC', $tx['bic']));
            $cdtrAgt->appendChild($finInstnId);
            $cdtTrfTxInf->appendChild($cdtrAgt);
        }

        $cdtr = $el('Cdtr');
        $cdtr->appendChild($el('Nm', sepaSanitizeText($tx['name'])));
        $cdtTrfTxInf->appendChild($cdtr);

        $cdtrAcct = $el('CdtrAcct');
        $cdtrAcctId = $el('Id');
        $cdtrAcctId->appendChild($el('IBAN', $tx['iban']));
        $cdtrAcct->appendChild($cdtrAcctId);
        $cdtTrfTxInf->appendChild($cdtrAcct);

        if (!empty($tx['purpose'])) {
            $rmtInf = $el('RmtInf');
            $rmtInf->appendChild($el('Ustrd', sepaSanitizeText($tx['purpose'])));
            $cdtTrfTxInf->appendChild($rmtInf);
        }
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
            resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $login));
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

            resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $getStatement));
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
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
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
                resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $getStatement));
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
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
    }
}

// ════════════════════════════════════════════════════════════
// HKVPP + HKVPA — VoP-konformer Überweisungsweg (engine=vop_check)
// ════════════════════════════════════════════════════════════

/**
 * Hilfsfunktion: Baut SEPAAccount + PAIN-XML aus einem geladenen Auftrag.
 * Wird von fintsSubmitTransferVopCheck und fintsApproveVopTransfer verwendet.
 */
function fintsBuildVopTransferData($db, array $order): array {
    $companyRow  = $db->getOne("SELECT company FROM defaults");
    $senderName  = trim($companyRow['company'] ?? '') ?: 'Absender';
    $senderAccount = (new \Fhp\Model\SEPAAccount())
        ->setIban($order['sender_iban'])
        ->setBic($order['sender_bic'] ?? '')
        ->setBlz($order['sender_blz'] ?? '')
        ->setAccountNumber($order['sender_account_number'] ?? '');
    $painXml = buildSepaCreditTransferPainXml(
        $senderName,
        $order['sender_iban'],
        $order['sender_bic'] ?? '',
        [[
            'name'    => $order['remote_name'],
            'iban'    => $order['remote_iban'],
            'bic'     => $order['remote_bic'] ?? '',
            'amount'  => floatval($order['amount']),
            'purpose' => $order['purpose'],
        ]],
        !empty($order['execution_date']) ? $order['execution_date'] : null
    );
    return [$senderAccount, $painXml];
}

/**
 * Hilfsfunktion: Mappt VoP-Response nach resultInfo — nutzt vorhandene PHP-Session.
 * Gibt true zurück wenn TAN_REQUIRED, false wenn VOP_APPROVAL_REQUIRED.
 */
function fintsHandleVopActionResult(
    \OserpBanking\SendSEPATransferWithVoP $action,
    \Fhp\FinTs $fints,
    int $transferId,
    int $bankAccountId,
    $db,
    string $painXml = ''
): void {
    if (!session_id()) session_start();

    if ($action->vopResponse !== null) {
        // VoP-Abweichung: User muss bestätigen
        // pain.xml in Session speichern — bei Approve identische XML verwenden (sonst 9010)
        $_SESSION['fints_vop_transfer_id'] = $transferId;
        $_SESSION['fints_vop_pain_xml']    = $painXml !== '' ? base64_encode($painXml) : '';
        $hivpp  = $action->vopResponse;
        $single = $hivpp->vopSingleResult;
        // VOP-ID als binary serialisiert aufbewahren (für HKVPA in Schritt 2)
        $_SESSION['fints_vop_id_raw'] = $hivpp->vopId ? base64_encode($hivpp->vopId->getData()) : '';
        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'pending_tan', mtime = now() WHERE id = :id",
            ['id' => $transferId]
        );
        resultInfo(true, 'VOP_APPROVAL_REQUIRED', [
            'vop' => [
                'result'          => $single?->result ?? null,
                'close_match_name'=> $single?->closeMatchName ?? null,
                'recipient_iban'  => $single?->recipientIban ?? null,
                'info_iban'       => $single?->infoIban ?? null,
                'na_reason'       => $single?->naReason ?? null,
            ],
            'notice' => $hivpp->manualAuthorizationNotice ?? null,
        ]);
        return;
    }

    if ($action->needsTan()) {
        // RCVC — exakter Treffer, TAN angefordert
        $_SESSION['fints_action']           = serialize($action);
        $_SESSION['fints_persist']          = $fints->persist();
        $_SESSION['fints_bank_account_id']  = $bankAccountId;
        $_SESSION['fints_transfer_id']      = $transferId;
        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'pending_tan', mtime = now() WHERE id = :id",
            ['id' => $transferId]
        );
        resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $action));
        return;
    }

    // Kein TAN, kein VoP-Einwand: sofort ausgeführt
    fintsPersistTrustState($fints, $db, $bankAccountId);
    $db->execute(<<<SQL
        UPDATE bank_transfer_orders
        SET status = 'submitted', submitted_at = now(), mtime = now()
        WHERE id = :id
    SQL, ['id' => $transferId]);
    resultInfo(true, 'Ueberweisung gesendet');
}

/**
 * FinTS: Überweisung mit VoP-Prüfung senden (HKVPP + HKCCS/HKCSE).
 *
 * Wenn der Namensabgleich positiv ist (RCVC), erhält der User eine TAN-Anfrage.
 * Bei Abweichung (RVMC/RVNM/RVNA) wird VOP_APPROVAL_REQUIRED zurückgegeben —
 * der User bestätigt und ruft dann fintsApproveVopTransfer auf.
 *
 * @param int    $data['transfer_order_id']
 * @param string $data['pin']
 * @testdata {"transfer_order_id": 1, "pin": "12345"}
 */
function fintsSubmitTransferVopCheck($data) {
    $db         = DbhCompany::begin();
    $transferId = intval($data['transfer_order_id'] ?? 0);
    $pin        = $data['pin'] ?? '';

    if ($transferId <= 0 || empty($pin)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Auftrags-ID und PIN sind Pflicht');
        return;
    }

    $order = $db->getOne(<<<SQL
        SELECT bto.*,
               REGEXP_REPLACE(ba.iban, '\s+', '', 'g')           as sender_iban,
               REGEXP_REPLACE(ba.bic, '\s+', '', 'g')            as sender_bic,
               REGEXP_REPLACE(ba.bank_code, '\s+', '', 'g')      as sender_blz,
               REGEXP_REPLACE(ba.account_number, '\s+', '', 'g') as sender_account_number
        FROM bank_transfer_orders bto
        JOIN bank_accounts ba ON ba.id = bto.bank_account_id
        WHERE bto.id = :id
    SQL, ['id' => $transferId]);

    if (!$order) { resultInfo(false, 'NOT_FOUND', 'Auftrag nicht gefunden'); return; }
    if (!in_array($order['status'], ['draft', 'rejected'], true)) {
        resultInfo(false, 'NOT_SUBMITTABLE', 'Nur Entwürfe können gesendet werden'); return;
    }

    $config = $db->getOne(
        "SELECT baf.* FROM bank_account_fints baf WHERE baf.bank_account_id = :id",
        ['id' => $order['bank_account_id']]
    );
    if (!$config) { resultInfo(false, 'NO_FINTS_CONFIG', 'Keine FinTS-Konfiguration'); return; }

    require_once __DIR__.'/../../vendor/autoload.php';
    require_once __DIR__.'/lib/SendSEPATransferWithVoP.php';

    try {
        $savedState = !empty($config['persisted_state']) ? $config['persisted_state'] : null;
        $fints = fintsCreate($config, $pin, $savedState);
        if (!$savedState) {
            $tanMode = !empty($config['fints_tan_mode']) ? (int)$config['fints_tan_mode'] : null;
            fintsSelectTanModeWithMedium($fints, $tanMode);
        }
        $login = $fints->login();
        if ($login->needsTan()) {
            session_start();
            $_SESSION['fints_action']          = serialize($login);
            $_SESSION['fints_persist']         = $fints->persist();
            $_SESSION['fints_bank_account_id'] = $order['bank_account_id'];
            $_SESSION['fints_transfer_id']     = $transferId;
            $_SESSION['fints_stage']           = 'login-vop';
            $db->execute("UPDATE bank_transfer_orders SET status = 'pending_tan', mtime = now() WHERE id = :id", ['id' => $transferId]);
            resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $login));
            return;
        }

        [$senderAccount, $painXml] = fintsBuildVopTransferData($db, $order);
        $action = \OserpBanking\SendSEPATransferWithVoP::createWithCheck($senderAccount, $painXml);
        $fints->execute($action);
        fintsHandleVopActionResult($action, $fints, $transferId, $order['bank_account_id'], $db, $painXml);

    } catch (\Exception $e) {
        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'rejected', error_message = :err, mtime = now() WHERE id = :id",
            ['id' => $transferId, 'err' => fintsToUtf8($e->getMessage())]
        );
        fintsLogException('SubmitTransferVopCheck', $e, ['transfer_id' => $transferId]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
    }
}

/**
 * FinTS: TAN für Überweisung mit VoP-Prüfung einreichen.
 * Handhabt auch: Login-TAN (login-vop) → danach VoP-Prüfung.
 *
 * @param string $data['tan']
 * @param string $data['pin']
 * @param int    $data['transfer_order_id']
 * @testdata {"tan": "123456", "pin": "12345", "transfer_order_id": 1}
 */
function fintsSubmitTransferVopTan($data) {
    $db         = DbhCompany::begin();
    $tan        = trim($data['tan'] ?? '');
    $pin        = $data['pin'] ?? '';
    $transferId = intval($data['transfer_order_id'] ?? 0);

    if (empty($pin) || $transferId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'PIN und Auftrags-ID sind Pflicht');
        return;
    }

    if (!session_id()) session_start();
    if (empty($_SESSION['fints_action']) || empty($_SESSION['fints_persist'])) {
        resultInfo(false, 'NO_PENDING_ACTION', 'Keine ausstehende FinTS-Aktion');
        return;
    }
    if (($_SESSION['fints_transfer_id'] ?? 0) !== $transferId) {
        resultInfo(false, 'SESSION_MISMATCH', 'Auftrags-ID stimmt nicht überein');
        return;
    }

    $bankAccountId = $_SESSION['fints_bank_account_id'];
    $config = $db->getOne(
        "SELECT baf.* FROM bank_account_fints baf WHERE baf.bank_account_id = :id",
        ['id' => $bankAccountId]
    );
    require_once __DIR__.'/../../vendor/autoload.php';
    require_once __DIR__.'/lib/SendSEPATransferWithVoP.php';

    try {
        $fints  = fintsCreate($config, $pin, $_SESSION['fints_persist']);
        $action = unserialize($_SESSION['fints_action']);
        $stage  = $_SESSION['fints_stage'] ?? 'vop';

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
                $payload['message'] = 'Freigabe noch nicht eingegangen — bitte in der App bestätigen und erneut auf "Fortfahren" klicken.';
                resultInfo(true, 'TAN_REQUIRED', $payload);
                return;
            }
        }

        // Nach Login-TAN für VoP-Approve: HKVPA + Transfer absenden
        if ($stage === 'login-vop-approve') {
            unset($_SESSION['fints_action'], $_SESSION['fints_persist'], $_SESSION['fints_stage']);
            $vopIdRaw = $_SESSION['fints_vop_id_raw'] ?? '';
            $vopId    = $vopIdRaw !== '' ? base64_decode($vopIdRaw) : '';
            $order = $db->getOne(<<<SQL
                SELECT bto.*,
                       REGEXP_REPLACE(ba.iban, '\s+', '', 'g')           as sender_iban,
                       REGEXP_REPLACE(ba.bic, '\s+', '', 'g')            as sender_bic,
                       REGEXP_REPLACE(ba.bank_code, '\s+', '', 'g')      as sender_blz,
                       REGEXP_REPLACE(ba.account_number, '\s+', '', 'g') as sender_account_number
                FROM bank_transfer_orders bto
                JOIN bank_accounts ba ON ba.id = bto.bank_account_id
                WHERE bto.id = :id
            SQL, ['id' => $transferId]);
            [$senderAccount, $painXml] = fintsBuildVopTransferData($db, $order);
            // Gespeicherte pain.xml aus VoP-Check wiederverwenden (sonst 9010)
            if (!empty($_SESSION['fints_vop_pain_xml'])) {
                $painXml = base64_decode($_SESSION['fints_vop_pain_xml']);
            }
            $vopAction = \OserpBanking\SendSEPATransferWithVoP::createWithApproval($senderAccount, $painXml, $vopId);
            $fints->execute($vopAction);
            unset($_SESSION['fints_vop_transfer_id'], $_SESSION['fints_vop_id_raw'], $_SESSION['fints_vop_pain_xml']);
            fintsHandleVopActionResult($vopAction, $fints, $transferId, $bankAccountId, $db);
            return;
        }

        // Nach Login-TAN: VoP-Prüfung nachholen
        if ($stage === 'login-vop') {
            unset($_SESSION['fints_action'], $_SESSION['fints_persist'], $_SESSION['fints_stage']);
            $order = $db->getOne(<<<SQL
                SELECT bto.*,
                       REGEXP_REPLACE(ba.iban, '\s+', '', 'g')           as sender_iban,
                       REGEXP_REPLACE(ba.bic, '\s+', '', 'g')            as sender_bic,
                       REGEXP_REPLACE(ba.bank_code, '\s+', '', 'g')      as sender_blz,
                       REGEXP_REPLACE(ba.account_number, '\s+', '', 'g') as sender_account_number
                FROM bank_transfer_orders bto
                JOIN bank_accounts ba ON ba.id = bto.bank_account_id
                WHERE bto.id = :id
            SQL, ['id' => $transferId]);
            [$senderAccount, $painXml] = fintsBuildVopTransferData($db, $order);
            $vopAction = \OserpBanking\SendSEPATransferWithVoP::createWithCheck($senderAccount, $painXml);
            $fints->execute($vopAction);
            fintsHandleVopActionResult($vopAction, $fints, $transferId, $bankAccountId, $db, $painXml);
            return;
        }

        // Normaler TAN-Schritt: Transfer abschließen
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'],
              $_SESSION['fints_bank_account_id'], $_SESSION['fints_transfer_id'], $_SESSION['fints_stage']);

        fintsPersistTrustState($fints, $db, $bankAccountId);
        $db->execute(<<<SQL
            UPDATE bank_transfer_orders
            SET status = 'submitted', submitted_at = now(), mtime = now()
            WHERE id = :id
        SQL, ['id' => $transferId]);
        resultInfo(true, 'Ueberweisung gesendet');

    } catch (\Exception $e) {
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'],
              $_SESSION['fints_bank_account_id'], $_SESSION['fints_transfer_id'], $_SESSION['fints_stage']);
        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'rejected', error_message = :err, mtime = now() WHERE id = :id",
            ['id' => $transferId, 'err' => fintsToUtf8($e->getMessage())]
        );
        fintsLogException('SubmitTransferVopTan', $e, ['transfer_id' => $transferId]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
    }
}

/**
 * FinTS: VoP-Abweichung bestätigen und Überweisung absenden (HKVPA + HKCCS/HKCSE).
 *
 * Wird aufgerufen nachdem der User eine RVMC/RVNM/RVNA-Abweichung im VoP-Dialog
 * bestätigt hat. Sendet HKVPA mit der VOP-ID aus dem vorherigen HIVPP.
 *
 * @param string $data['pin']
 * @param int    $data['transfer_order_id']
 * @testdata {"pin": "12345", "transfer_order_id": 1}
 */
function fintsApproveVopTransfer($data) {
    $db         = DbhCompany::begin();
    $pin        = $data['pin'] ?? '';
    $transferId = intval($data['transfer_order_id'] ?? 0);

    if (empty($pin) || $transferId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'PIN und Auftrags-ID sind Pflicht');
        return;
    }

    if (!session_id()) session_start();
    if (($_SESSION['fints_vop_transfer_id'] ?? 0) !== $transferId) {
        resultInfo(false, 'SESSION_MISMATCH', 'Auftrags-ID stimmt nicht mit VoP-Session überein');
        return;
    }

    $vopIdRaw = $_SESSION['fints_vop_id_raw'] ?? '';
    $vopId    = $vopIdRaw !== '' ? base64_decode($vopIdRaw) : '';

    $order = $db->getOne(<<<SQL
        SELECT bto.*,
               REGEXP_REPLACE(ba.iban, '\s+', '', 'g')           as sender_iban,
               REGEXP_REPLACE(ba.bic, '\s+', '', 'g')            as sender_bic,
               REGEXP_REPLACE(ba.bank_code, '\s+', '', 'g')      as sender_blz,
               REGEXP_REPLACE(ba.account_number, '\s+', '', 'g') as sender_account_number
        FROM bank_transfer_orders bto
        JOIN bank_accounts ba ON ba.id = bto.bank_account_id
        WHERE bto.id = :id
    SQL, ['id' => $transferId]);

    if (!$order) { resultInfo(false, 'NOT_FOUND', 'Auftrag nicht gefunden'); return; }

    $config = $db->getOne(
        "SELECT baf.* FROM bank_account_fints baf WHERE baf.bank_account_id = :id",
        ['id' => $order['bank_account_id']]
    );
    if (!$config) { resultInfo(false, 'NO_FINTS_CONFIG', 'Keine FinTS-Konfiguration'); return; }

    require_once __DIR__.'/../../vendor/autoload.php';
    require_once __DIR__.'/lib/SendSEPATransferWithVoP.php';

    try {
        $savedState = !empty($config['persisted_state']) ? $config['persisted_state'] : null;
        $fints = fintsCreate($config, $pin, $savedState);
        if (!$savedState) {
            $tanMode = !empty($config['fints_tan_mode']) ? (int)$config['fints_tan_mode'] : null;
            fintsSelectTanModeWithMedium($fints, $tanMode);
        }
        $login = $fints->login();
        if ($login->needsTan()) {
            // Login braucht TAN — nach Login dann HKVPA + HKCSS absenden
            session_start();
            $_SESSION['fints_action']          = serialize($login);
            $_SESSION['fints_persist']         = $fints->persist();
            $_SESSION['fints_bank_account_id'] = $order['bank_account_id'];
            $_SESSION['fints_transfer_id']     = $transferId;
            $_SESSION['fints_stage']           = 'login-vop-approve';
            resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $login));
            return;
        }

        [$senderAccount, $painXml] = fintsBuildVopTransferData($db, $order);
        // Gespeicherte pain.xml aus VoP-Check wiederverwenden (sonst 9010)
        if (!empty($_SESSION['fints_vop_pain_xml'])) {
            $painXml = base64_decode($_SESSION['fints_vop_pain_xml']);
        }
        $action = \OserpBanking\SendSEPATransferWithVoP::createWithApproval($senderAccount, $painXml, $vopId);
        $fints->execute($action);

        unset($_SESSION['fints_vop_transfer_id'], $_SESSION['fints_vop_id_raw'], $_SESSION['fints_vop_pain_xml']);
        fintsHandleVopActionResult($action, $fints, $transferId, $order['bank_account_id'], $db);

    } catch (\Exception $e) {
        unset($_SESSION['fints_vop_transfer_id'], $_SESSION['fints_vop_id_raw'], $_SESSION['fints_vop_pain_xml']);
        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'rejected', error_message = :err, mtime = now() WHERE id = :id",
            ['id' => $transferId, 'err' => fintsToUtf8($e->getMessage())]
        );
        fintsLogException('VopApproveAndSend', $e, ['transfer_id' => $transferId]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
    }
}

/**
 * FinTS: Ueberweisung an Bank senden (phpFinTS-Variante, ohne VoP-Support).
 * Bei Banken die VoP erzwingen kommt 9076 — kein Auth-Fehler, keine PIN-Zaehler.
 *
 * Wird fuer Auftraege mit engine='php' oder 'vop_optout' aufgerufen.
 */
function fintsSubmitTransferPhp($data) {
    $db = DbhCompany::begin();

    $transferId = intval($data['transfer_order_id'] ?? 0);
    $pin = $data['pin'] ?? '';

    if ($transferId <= 0 || empty($pin)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Auftrags-ID und PIN sind Pflicht');
        return;
    }

    // Auftrag laden
    $order = $db->getOne(<<<SQL
        SELECT bto.*,
                       REGEXP_REPLACE(ba.iban, '\s+', '', 'g')           as sender_iban,
                       REGEXP_REPLACE(ba.bic, '\s+', '', 'g')            as sender_bic,
                       REGEXP_REPLACE(ba.bank_code, '\s+', '', 'g')      as sender_blz,
                       REGEXP_REPLACE(ba.account_number, '\s+', '', 'g') as sender_account_number
        FROM bank_transfer_orders bto
        JOIN bank_accounts ba ON ba.id = bto.bank_account_id
        WHERE bto.id = :id
    SQL, ['id' => $transferId]);

    if (!$order) {
        resultInfo(false, 'NOT_FOUND', 'Auftrag nicht gefunden');
        return;
    }

    if (!in_array($order['status'], ['draft', 'rejected'], true)) {
        resultInfo(false, 'NOT_SUBMITTABLE', 'Nur Entwürfe können gesendet werden');
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
        $savedState = !empty($config['persisted_state']) ? $config['persisted_state'] : null;
        $fints = fintsCreate($config, $pin, $savedState);
        if (!$savedState) {
            $tanMode = !empty($config['fints_tan_mode']) ? (int)$config['fints_tan_mode'] : null;
            fintsSelectTanModeWithMedium($fints, $tanMode);
        }
        $login = $fints->login();
        if ($login->needsTan()) {
            session_start();
            $_SESSION['fints_action'] = serialize($login);
            $_SESSION['fints_persist'] = $fints->persist();
            $_SESSION['fints_bank_account_id'] = $order['bank_account_id'];
            $_SESSION['fints_transfer_id'] = $transferId;
            $_SESSION['fints_stage'] = 'login-transfer';
            $db->execute("UPDATE bank_transfer_orders SET status = 'pending_tan', mtime = now() WHERE id = :id", ['id' => $transferId]);
            resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $login));
            return;
        }

        // Absender-Name aus Firmenkonfiguration (Kontoinhaber fuer PAIN)
        $companyRow = $db->getOne("SELECT company FROM defaults");
        $senderName = trim($companyRow['company'] ?? '') ?: 'Absender';

        // SEPAAccount des Absenders. BLZ + Kontonummer sind fuer phpFinTS Pflicht
        // (Kti::fromAccount baut daraus die Kreditinstitutskennung) — IBAN/BIC
        // alleine reichen nicht, sonst Crash in Kik::create().
        $senderAccount = (new \Fhp\Model\SEPAAccount())
            ->setIban($order['sender_iban'])
            ->setBic($order['sender_bic'] ?? '')
            ->setBlz($order['sender_blz'] ?? '')
            ->setAccountNumber($order['sender_account_number'] ?? '');

        // PAIN.001.001.03 XML fuer Einzelueberweisung — bei vorhandenem
        // execution_date wird das Datum durchgereicht und phpFinTS waehlt
        // automatisch HKCSE (terminiert) statt HKCCS (sofort).
        $painXml = buildSepaCreditTransferPainXml(
            $senderName,
            $order['sender_iban'],
            $order['sender_bic'] ?? '',
            [[
                'name'    => $order['remote_name'],
                'iban'    => $order['remote_iban'],
                'bic'     => $order['remote_bic'] ?? '',
                'amount'  => floatval($order['amount']),
                'purpose' => $order['purpose'],
            ]],
            !empty($order['execution_date']) ? $order['execution_date'] : null
        );

        // Instant-SEPA: separater FinTS-Geschaeftsvorfall HKIPZ. Faellt bei
        // Nicht-Unterstuetzung der Bank automatisch auf normale Ueberweisung
        // zurueck (allowConversionToSEPATransfer=true).
        // vop_optout: HKVOO + HKCCS in einer Nachricht — überspringt VoP-Prüfung
        // (9076) sofern das Konto für HKVOO freigeschaltet ist.
        if (!empty($order['instant'])) {
            $sepaTransfer = \Fhp\Action\SendSEPARealtimeTransfer::create($senderAccount, $painXml, true);
        } elseif (($order['engine'] ?? '') === 'vop_optout') {
            require_once __DIR__.'/lib/SendSEPATransferWithVopOptOut.php';
            $sepaTransfer = \OserpBanking\SendSEPATransferWithVopOptOut::create($senderAccount, $painXml);
        } else {
            $sepaTransfer = \Fhp\Action\SendSEPATransfer::create($senderAccount, $painXml);
        }
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

            resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $sepaTransfer));
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
        SQL, ['id' => $transferId, 'error' => fintsToUtf8($e->getMessage())]);

        fintsLogException('SubmitTransfer', $e, ['transfer_id' => $transferId, 'url' => $config['fints_url'] ?? null]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
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
function fintsSubmitTransferTanPhp($data) {
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

    $bankAccountId = (int)$_SESSION['fints_bank_account_id'];
    $config = $db->getOne(<<<SQL
        SELECT baf.*
        FROM bank_account_fints baf
        WHERE baf.bank_account_id = :bank_account_id
    SQL, ['bank_account_id' => $bankAccountId]);

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
                SELECT bto.*,
                       REGEXP_REPLACE(ba.iban, '\s+', '', 'g')           as sender_iban,
                       REGEXP_REPLACE(ba.bic, '\s+', '', 'g')            as sender_bic,
                       REGEXP_REPLACE(ba.bank_code, '\s+', '', 'g')      as sender_blz,
                       REGEXP_REPLACE(ba.account_number, '\s+', '', 'g') as sender_account_number
                FROM bank_transfer_orders bto
                JOIN bank_accounts ba ON ba.id = bto.bank_account_id
                WHERE bto.id = :id
            SQL, ['id' => $transferId]);

            $companyRow = $db->getOne("SELECT company FROM defaults");
            $senderName = trim($companyRow['company'] ?? '') ?: 'Absender';
            $senderAccount = (new \Fhp\Model\SEPAAccount())
                ->setIban($order['sender_iban'])
                ->setBic($order['sender_bic'] ?? '')
                ->setBlz($order['sender_blz'] ?? '')
                ->setAccountNumber($order['sender_account_number'] ?? '');
            $painXml = buildSepaCreditTransferPainXml(
                $senderName, $order['sender_iban'], $order['sender_bic'] ?? '',
                [[
                    'name'    => $order['remote_name'],
                    'iban'    => $order['remote_iban'],
                    'bic'     => $order['remote_bic'] ?? '',
                    'amount'  => floatval($order['amount']),
                    'purpose' => $order['purpose'],
                ]],
                !empty($order['execution_date']) ? $order['execution_date'] : null
            );
            if (!empty($order['instant'])) {
                $sepaTransfer = \Fhp\Action\SendSEPARealtimeTransfer::create($senderAccount, $painXml, true);
            } elseif (($order['engine'] ?? '') === 'vop_optout') {
                require_once __DIR__.'/lib/SendSEPATransferWithVopOptOut.php';
                $sepaTransfer = \OserpBanking\SendSEPATransferWithVopOptOut::create($senderAccount, $painXml);
            } else {
                $sepaTransfer = \Fhp\Action\SendSEPATransfer::create($senderAccount, $painXml);
            }
            $fints->execute($sepaTransfer);

            if ($sepaTransfer->needsTan()) {
                session_start();
                $_SESSION['fints_action'] = serialize($sepaTransfer);
                $_SESSION['fints_persist'] = $fints->persist();
                $_SESSION['fints_bank_account_id'] = $order['bank_account_id'];
                $_SESSION['fints_transfer_id'] = $transferId;
                $_SESSION['fints_stage'] = 'transfer';
                resultInfo(true, 'TAN_REQUIRED', fintsBuildTanResponse($fints, $sepaTransfer));
                return;
            }
            fintsPersistTrustState($fints, $db, $order['bank_account_id']);
            $db->execute("UPDATE bank_transfer_orders SET status = 'submitted', submitted_at = now(), mtime = now() WHERE id = :id", ['id' => $transferId]);
            resultInfo(true, 'Ueberweisung gesendet');
            return;
        }

        // Session aufraeumen
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'],
              $_SESSION['fints_bank_account_id'], $_SESSION['fints_transfer_id'], $_SESSION['fints_stage']);

        // Auftrag als gesendet markieren
        fintsPersistTrustState($fints, $db, $bankAccountId);
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
        SQL, ['id' => $transferId, 'error' => fintsToUtf8($e->getMessage())]);

        fintsLogException('SubmitTransferTan', $e, ['transfer_id' => $transferId]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
    }
}

/**
 * FinTS: Sammelüberweisung — mehrere Auftraege in einem PAIN-Batch absenden.
 * Alle Auftraege muessen zum gleichen Bankkonto gehoeren, im 'draft' sein, und
 * entweder alle ein execution_date haben (HKCME) oder keiner (HKCCM). Instant
 * ist im Batch nicht erlaubt — Sofort-Ueberweisungen sind per Definition
 * Einzeltransaktionen.
 *
 * @param array  $data['transfer_order_ids']  Liste von Auftrags-IDs
 * @param string $data['pin']                 Online-Banking PIN
 * @testdata {"transfer_order_ids": [1, 2, 3], "pin": "12345"}
 */
/**
 * Baut eine benannte IN-Klausel für eine Liste von IDs.
 *
 * Der DB-Wrapper (bindTypedParams) bindet ausschließlich über Array-Keys —
 * positionsbasierte '?'-Platzhalter mit 0-indizierten Arrays scheitern an
 * PDO::bindValue (Argument #1 muss >= 1 sein). Deshalb erzeugen wir benannte
 * Parameter :bid0, :bid1, … die sich mit weiteren named params mischen lassen.
 *
 * @param array $ids Liste von IDs
 * @return array [string $placeholders, array $params]
 */
function fintsBuildIdInClause(array $ids): array {
    $names = [];
    $params = [];
    foreach (array_values($ids) as $i => $id) {
        $key = 'bid' . $i;
        $names[] = ':' . $key;
        $params[$key] = (int)$id;
    }
    return [implode(',', $names), $params];
}

function fintsSubmitTransferBatch($data) {
    $db = DbhCompany::begin();

    $ids = $data['transfer_order_ids'] ?? [];
    $pin = $data['pin'] ?? '';

    if (!is_array($ids) || count($ids) < 2 || empty($pin)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Mindestens 2 Auftrags-IDs und PIN sind Pflicht');
        return;
    }

    $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    if (count($ids) < 2) {
        resultInfo(false, 'VALIDATION_ERROR', 'Mindestens 2 gültige Auftrags-IDs erforderlich');
        return;
    }

    // Aufträge laden + auf gleiche bank_account_id und Sendbarkeit prüfen.
    [$placeholders, $idParams] = fintsBuildIdInClause($ids);
    $orders = $db->getAll(<<<SQL
        SELECT bto.*,
                       REGEXP_REPLACE(ba.iban, '\s+', '', 'g')           as sender_iban,
                       REGEXP_REPLACE(ba.bic, '\s+', '', 'g')            as sender_bic,
                       REGEXP_REPLACE(ba.bank_code, '\s+', '', 'g')      as sender_blz,
                       REGEXP_REPLACE(ba.account_number, '\s+', '', 'g') as sender_account_number
        FROM bank_transfer_orders bto
        JOIN bank_accounts ba ON ba.id = bto.bank_account_id
        WHERE bto.id IN ($placeholders)
        ORDER BY bto.id ASC
    SQL, $idParams);

    if (count($orders) !== count($ids)) {
        resultInfo(false, 'NOT_FOUND', 'Ein oder mehrere Aufträge nicht gefunden');
        return;
    }

    $accountId = $orders[0]['bank_account_id'];
    foreach ($orders as $o) {
        // Entwürfe und zuvor fehlgeschlagene (rejected) Aufträge dürfen gesendet
        // werden — ein abgelehnter Auftrag wurde nicht ausgeführt, der Wiederholversuch
        // ist also sicher.
        if (!in_array($o['status'], ['draft', 'rejected'], true)) {
            resultInfo(false, 'NOT_SUBMITTABLE', 'Nur Entwürfe können gesendet werden');
            return;
        }
        if ($o['bank_account_id'] != $accountId) {
            resultInfo(false, 'VALIDATION_ERROR', 'Alle Aufträge müssen vom selben Konto sein');
            return;
        }
        if (!empty($o['instant'])) {
            resultInfo(false, 'VALIDATION_ERROR', 'Sofort-Überweisungen können nicht im Batch gesendet werden');
            return;
        }
    }

    // Termin/Sofort einheitlich pro Batch — entweder alle terminiert (gleiches
    // Datum) oder alle sofort. Mischbatches lehnt phpFinTS ab.
    $execDates = array_unique(array_filter(array_map(fn($o) => $o['execution_date'], $orders)));
    if (count($execDates) > 1) {
        resultInfo(false, 'VALIDATION_ERROR', 'Aufträge im Batch müssen das gleiche Ausführungsdatum haben');
        return;
    }
    $batchExecDate = $execDates ? reset($execDates) : null;
    // Wenn manche Aufträge ein Datum haben und andere nicht, ist das
    // ebenfalls ein Mix — nicht erlaubt.
    if ($batchExecDate !== null) {
        foreach ($orders as $o) {
            if (empty($o['execution_date'])) {
                resultInfo(false, 'VALIDATION_ERROR', 'Aufträge im Batch müssen entweder alle terminiert oder alle sofort sein');
                return;
            }
        }
    }

    // Terminierte Sammelüberweisung: ein optionales Batch-Ausführungsdatum (aus
    // dem "Alle senden"-Dialog) macht aus dem SOFORT-Sammelauftrag (HKCCM) eine
    // terminierte Sammelüberweisung (HKCME). Hintergrund: die Sparkasse blockiert
    // den Sofort-Sammelauftrag, weil der VoP-Namensabgleich für mehrere Empfänger
    // asynchron läuft (3093 "noch in Bearbeitung" -> 3945 "Freigabe kann nicht
    // erteilt werden"). Mit Termin nimmt die Bank den Auftrag sofort an und
    // erledigt den Abgleich bis zum Datum — genau eine pushTAN für alle.
    $reqExecDate = trim((string)($data['execution_date'] ?? ''));
    if ($reqExecDate !== '') {
        $d = \DateTime::createFromFormat('Y-m-d', $reqExecDate);
        if (!$d || $d->format('Y-m-d') !== $reqExecDate) {
            resultInfo(false, 'VALIDATION_ERROR', 'Ungültiges Ausführungsdatum');
            return;
        }
        if ($reqExecDate < date('Y-m-d')) {
            resultInfo(false, 'VALIDATION_ERROR', 'Ausführungsdatum darf nicht in der Vergangenheit liegen');
            return;
        }
        $batchExecDate = $reqExecDate;
        // Datum auf allen Aufträgen persistieren — konsistent für PAIN-Neuaufbau
        // im Folge-TAN-Schritt (login-batch) und für die Anzeige.
        $db->execute(
            "UPDATE bank_transfer_orders SET execution_date = :d, mtime = now() WHERE id IN ($placeholders)",
            array_merge(['d' => $reqExecDate], $idParams)
        );
    }

    $config = $db->getOne(
        "SELECT baf.* FROM bank_account_fints baf WHERE baf.bank_account_id = :id",
        ['id' => $accountId]
    );
    if (!$config) {
        resultInfo(false, 'NO_FINTS_CONFIG', 'Keine FinTS-Konfiguration vorhanden');
        return;
    }

    require_once __DIR__.'/../../vendor/autoload.php';

    $batchId = 'B' . date('YmdHis') . substr(bin2hex(random_bytes(4)), 0, 8);

    try {
        $savedState = !empty($config['persisted_state']) ? $config['persisted_state'] : null;
        $fints = fintsCreate($config, $pin, $savedState);
        if (!$savedState) {
            $tanMode = !empty($config['fints_tan_mode']) ? (int)$config['fints_tan_mode'] : null;
            fintsSelectTanModeWithMedium($fints, $tanMode);
        }
        $login = $fints->login();
        if ($login->needsTan()) {
            session_start();
            $_SESSION['fints_action'] = serialize($login);
            $_SESSION['fints_persist'] = $fints->persist();
            $_SESSION['fints_bank_account_id'] = $accountId;
            $_SESSION['fints_batch_id'] = $batchId;
            $_SESSION['fints_batch_ids'] = $ids;
            $_SESSION['fints_stage'] = 'login-batch';
            $db->execute(
                "UPDATE bank_transfer_orders SET status = 'pending_tan', batch_id = :batch, mtime = now() WHERE id IN ($placeholders)",
                array_merge(['batch' => $batchId], $idParams)
            );
            $payload = fintsBuildTanResponse($fints, $login);
            $payload['batch_id'] = $batchId;
            resultInfo(true, 'TAN_REQUIRED', $payload);
            return;
        }

        $companyRow = $db->getOne("SELECT company FROM defaults");
        $senderName = trim($companyRow['company'] ?? '') ?: 'Absender';

        $senderAccount = (new \Fhp\Model\SEPAAccount())
            ->setIban($orders[0]['sender_iban'])
            ->setBic($orders[0]['sender_bic'] ?? '')
            ->setBlz($orders[0]['sender_blz'] ?? '')
            ->setAccountNumber($orders[0]['sender_account_number'] ?? '');

        $txList = array_map(fn($o) => [
            'name'    => $o['remote_name'],
            'iban'    => $o['remote_iban'],
            'bic'     => $o['remote_bic'] ?? '',
            'amount'  => floatval($o['amount']),
            'purpose' => $o['purpose'],
        ], $orders);

        $painXml = buildSepaCreditTransferPainXml(
            $senderName,
            $orders[0]['sender_iban'],
            $orders[0]['sender_bic'] ?? '',
            $txList,
            $batchExecDate
        );

        // VoP-konform (HKVPP + HKCCM): die Sparkasse verlangt für Sammelaufträge
        // einen Empfänger-Namensabgleich (sonst Code 9076). Diese Lib führt ihn
        // durch — stimmen alle Namen, kommt direkt EINE TAN für den ganzen Batch.
        require_once __DIR__.'/lib/SendSEPATransferWithVoP.php';
        $sepaTransfer = \OserpBanking\SendSEPATransferWithVoP::createWithCheck($senderAccount, $painXml);
        $fints->execute($sepaTransfer);

        // Namensabgleich für mindestens einen Empfänger nicht eindeutig
        // (RVMC/RVNM/RVNA bzw. Sperrcode 3945). Eine Pro-Empfänger-Bestätigung
        // gibt es im Sammelauftrag nicht → zurück auf Entwurf, Einzelversand-Hinweis.
        if ($sepaTransfer->vopResponse !== null) {
            $db->execute(
                "UPDATE bank_transfer_orders SET status = 'draft', batch_id = NULL, mtime = now() WHERE id IN ($placeholders)",
                $idParams
            );
            resultInfo(false, 'VOP_MISMATCH_BATCH',
                'Namensabgleich für mindestens einen Empfänger nicht eindeutig — eine Sammelüberweisung ist dafür nicht möglich. Bitte die betroffenen Überweisungen einzeln senden (Pfeil in der Zeile) und dort den Namensabgleich bestätigen.');
            return;
        }

        if ($sepaTransfer->needsTan()) {
            session_start();
            $_SESSION['fints_action'] = serialize($sepaTransfer);
            $_SESSION['fints_persist'] = $fints->persist();
            $_SESSION['fints_bank_account_id'] = $accountId;
            $_SESSION['fints_batch_id'] = $batchId;
            $_SESSION['fints_batch_ids'] = $ids;
            $_SESSION['fints_stage'] = 'batch';

            $db->execute(
                "UPDATE bank_transfer_orders SET status = 'pending_tan', batch_id = :batch, mtime = now() WHERE id IN ($placeholders)",
                array_merge(['batch' => $batchId], $idParams)
            );
            $payload = fintsBuildTanResponse($fints, $sepaTransfer);
            $payload['batch_id'] = $batchId;
            resultInfo(true, 'TAN_REQUIRED', $payload);
            return;
        }

        // Kein TAN noetig — alle als submitted markieren
        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'submitted', submitted_at = now(), batch_id = :batch, mtime = now() WHERE id IN ($placeholders)",
            array_merge(['batch' => $batchId], $idParams)
        );
        resultInfo(true, 'Sammelüberweisung gesendet', ['batch_id' => $batchId, 'count' => count($ids)]);

    } catch (\Exception $e) {
        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'rejected', error_message = :error, mtime = now() WHERE id IN ($placeholders)",
            array_merge(['error' => fintsToUtf8($e->getMessage())], $idParams)
        );
        fintsLogException('SubmitTransferBatch', $e, ['batch_id' => $batchId, 'ids' => $ids]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
    }
}

/**
 * FinTS: TAN für Sammelüberweisung einreichen. Spiegelt fintsSubmitTransferTan
 * für Batch-Sessions — die TAN gilt für alle Aufträge gemeinsam.
 *
 * @param string $data['tan']      TAN-Eingabe (leer bei Decoupled)
 * @param string $data['pin']      Online-Banking PIN
 * @param string $data['batch_id'] Batch-ID aus der TAN_REQUIRED-Antwort
 * @testdata {"tan": "123456", "pin": "12345", "batch_id": "B20260101120000abcd"}
 */
function fintsSubmitTransferBatchTan($data) {
    $db = DbhCompany::begin();

    $tan = trim($data['tan'] ?? '');
    $pin = $data['pin'] ?? '';
    $batchId = trim($data['batch_id'] ?? '');

    if (empty($pin) || $batchId === '') {
        resultInfo(false, 'VALIDATION_ERROR', 'PIN und Batch-ID sind Pflicht');
        return;
    }

    session_start();

    if (empty($_SESSION['fints_action']) || empty($_SESSION['fints_persist'])) {
        resultInfo(false, 'NO_PENDING_ACTION', 'Keine ausstehende FinTS-Aktion');
        return;
    }
    if (($_SESSION['fints_batch_id'] ?? '') !== $batchId) {
        resultInfo(false, 'SESSION_MISMATCH', 'Batch-ID stimmt nicht');
        return;
    }

    $ids = $_SESSION['fints_batch_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        resultInfo(false, 'NO_PENDING_ACTION', 'Batch-Session ungültig');
        return;
    }
    [$placeholders, $idParams] = fintsBuildIdInClause($ids);

    $bankAccountId = (int)$_SESSION['fints_bank_account_id'];
    $config = $db->getOne(
        "SELECT baf.* FROM bank_account_fints baf WHERE baf.bank_account_id = :id",
        ['id' => $bankAccountId]
    );

    require_once __DIR__.'/../../vendor/autoload.php';

    try {
        $fints = fintsCreate($config, $pin, $_SESSION['fints_persist']);
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

        $stage = $_SESSION['fints_stage'] ?? 'batch';
        if ($stage === 'login-batch') {
            // Login-TAN war erfolgreich, jetzt die eigentliche Sammelüberweisung
            unset($_SESSION['fints_action'], $_SESSION['fints_persist'], $_SESSION['fints_stage']);

            $orders = $db->getAll(<<<SQL
                SELECT bto.*,
                       REGEXP_REPLACE(ba.iban, '\s+', '', 'g')           as sender_iban,
                       REGEXP_REPLACE(ba.bic, '\s+', '', 'g')            as sender_bic,
                       REGEXP_REPLACE(ba.bank_code, '\s+', '', 'g')      as sender_blz,
                       REGEXP_REPLACE(ba.account_number, '\s+', '', 'g') as sender_account_number
                FROM bank_transfer_orders bto
                JOIN bank_accounts ba ON ba.id = bto.bank_account_id
                WHERE bto.id IN ($placeholders)
                ORDER BY bto.id ASC
            SQL, $idParams);

            $companyRow = $db->getOne("SELECT company FROM defaults");
            $senderName = trim($companyRow['company'] ?? '') ?: 'Absender';
            $senderAccount = (new \Fhp\Model\SEPAAccount())
                ->setIban($orders[0]['sender_iban'])
                ->setBic($orders[0]['sender_bic'] ?? '')
                ->setBlz($orders[0]['sender_blz'] ?? '')
                ->setAccountNumber($orders[0]['sender_account_number'] ?? '');
            $txList = array_map(fn($o) => [
                'name'    => $o['remote_name'],
                'iban'    => $o['remote_iban'],
                'bic'     => $o['remote_bic'] ?? '',
                'amount'  => floatval($o['amount']),
                'purpose' => $o['purpose'],
            ], $orders);
            $execDates = array_unique(array_filter(array_map(fn($o) => $o['execution_date'], $orders)));
            $batchExecDate = $execDates ? reset($execDates) : null;
            $painXml = buildSepaCreditTransferPainXml(
                $senderName, $orders[0]['sender_iban'], $orders[0]['sender_bic'] ?? '',
                $txList, $batchExecDate
            );
            // VoP-konform wie in fintsSubmitTransferBatch (HKVPP + HKCCM).
            require_once __DIR__.'/lib/SendSEPATransferWithVoP.php';
            $sepaTransfer = \OserpBanking\SendSEPATransferWithVoP::createWithCheck($senderAccount, $painXml);
            $fints->execute($sepaTransfer);
            if ($sepaTransfer->vopResponse !== null) {
                unset($_SESSION['fints_action'], $_SESSION['fints_persist'],
                      $_SESSION['fints_bank_account_id'], $_SESSION['fints_batch_id'],
                      $_SESSION['fints_batch_ids'], $_SESSION['fints_stage']);
                $db->execute(
                    "UPDATE bank_transfer_orders SET status = 'draft', batch_id = NULL, mtime = now() WHERE id IN ($placeholders)",
                    $idParams
                );
                resultInfo(false, 'VOP_MISMATCH_BATCH',
                    'Namensabgleich für mindestens einen Empfänger nicht eindeutig — eine Sammelüberweisung ist dafür nicht möglich. Bitte die betroffenen Überweisungen einzeln senden und dort den Namensabgleich bestätigen.');
                return;
            }
            if ($sepaTransfer->needsTan()) {
                session_start();
                $_SESSION['fints_action'] = serialize($sepaTransfer);
                $_SESSION['fints_persist'] = $fints->persist();
                $_SESSION['fints_bank_account_id'] = $orders[0]['bank_account_id'];
                $_SESSION['fints_batch_id'] = $batchId;
                $_SESSION['fints_batch_ids'] = $ids;
                $_SESSION['fints_stage'] = 'batch';
                $payload = fintsBuildTanResponse($fints, $sepaTransfer);
                $payload['batch_id'] = $batchId;
                resultInfo(true, 'TAN_REQUIRED', $payload);
                return;
            }
            fintsPersistTrustState($fints, $db, $orders[0]['bank_account_id']);
            $db->execute(
                "UPDATE bank_transfer_orders SET status = 'submitted', submitted_at = now(), mtime = now() WHERE id IN ($placeholders)",
                $idParams
            );
            resultInfo(true, 'Sammelüberweisung gesendet', ['count' => count($ids)]);
            return;
        }

        // stage = batch — Action-TAN, Sammelüberweisung war direkt in der Action
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'],
              $_SESSION['fints_bank_account_id'], $_SESSION['fints_batch_id'],
              $_SESSION['fints_batch_ids'], $_SESSION['fints_stage']);

        fintsPersistTrustState($fints, $db, $bankAccountId);
        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'submitted', submitted_at = now(), mtime = now() WHERE id IN ($placeholders)",
            $idParams
        );
        resultInfo(true, 'Sammelüberweisung gesendet', ['count' => count($ids)]);

    } catch (\Exception $e) {
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'],
              $_SESSION['fints_bank_account_id'], $_SESSION['fints_batch_id'],
              $_SESSION['fints_batch_ids'], $_SESSION['fints_stage']);

        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'rejected', error_message = :error, mtime = now() WHERE id IN ($placeholders)",
            array_merge(['error' => fintsToUtf8($e->getMessage())], $idParams)
        );
        fintsLogException('SubmitTransferBatchTan', $e, ['batch_id' => $batchId, 'ids' => $ids]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
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
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
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
    // Beim Decoupled-Polling ist getTanRequest() nach dem ersten
    // checkDecoupledSubmission() oft null — daher durchgaengig null-safe.
    $tanRequest = $action->getTanRequest();
    $tanMode    = $fints->getSelectedTanMode();
    $decoupled  = $tanMode && $tanMode->isDecoupled();
    return [
        'tan_required'     => true,
        'decoupled'        => $decoupled,
        'tan_medium'       => $tanRequest?->getTanMediumName() ?? 'Unbekannt',
        'challenge'        => $tanRequest?->getChallenge() ?? '',
        'challenge_hhduc'  => $tanRequest?->getChallengeHhdUc()?->getData() ?? null,
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

    // Status-Polling fuer offene Ueberweisungen: gerade importierte Buchungen
    // gegen 'submitted'-Auftraege matchen, danach abgelaufene Auftraege markieren.
    matchTransfersToTransactions($db, $bankAccountId);
    expireOldSubmittedTransfers($db, $bankAccountId);

    return $importedCount;
}

// ════════════════════════════════════════════════════════════
// Engine-Dispatcher
// ════════════════════════════════════════════════════════════

/**
 * Dispatcher: leitet je nach engine-Spalte des Auftrags weiter.
 * Alle neuen Aufträge nutzen 'vop_check'; Altaufträge mit 'python' in der DB
 * werden ebenfalls auf vop_check umgeleitet.
 *
 * @param int    $data['transfer_order_id']
 * @param string $data['pin']
 * @testdata {"transfer_order_id": 1, "pin": "12345"}
 */
function fintsSubmitTransfer($data) {
    $db  = DbhCompany::begin();
    $id  = intval($data['transfer_order_id'] ?? 0);
    $row = $id > 0 ? $db->getOne("SELECT COALESCE(engine,'vop_check') AS engine FROM bank_transfer_orders WHERE id = :id", ['id' => $id]) : null;
    $engine = strtolower(trim($row['engine'] ?? 'vop_check'));
    if ($engine === 'vop_check' || $engine === 'python') {
        fintsSubmitTransferVopCheck($data);
    } else {
        fintsSubmitTransferPhp($data);
    }
}

/**
 * Dispatcher für TAN-Submission.
 *
 * @param string $data['tan']
 * @param string $data['pin']
 * @param int    $data['transfer_order_id']
 * @testdata {"tan": "123456", "pin": "12345", "transfer_order_id": 1}
 */
function fintsSubmitTransferTan($data) {
    $db  = DbhCompany::begin();
    $id  = intval($data['transfer_order_id'] ?? 0);
    $row = $id > 0 ? $db->getOne("SELECT COALESCE(engine,'vop_check') AS engine FROM bank_transfer_orders WHERE id = :id", ['id' => $id]) : null;
    $engine = strtolower(trim($row['engine'] ?? 'vop_check'));
    if ($engine === 'vop_check' || $engine === 'python') {
        fintsSubmitTransferVopTan($data);
    } else {
        fintsSubmitTransferTanPhp($data);
    }
}
