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
 * FinTS Produkt-ID (Registrierung auf fints.org)
 * Identifiziert OpensourceERP als zugelassene Banking-Software.
 * HIER die ID eintragen sobald sie von der Deutschen Kreditwirtschaft zugeteilt wurde.
 */
const FINTS_PRODUCT_ID = '';  // z.B. 'aaaaaaaaaXXXXXXXXXXX'

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

    // Pruefen ob phpFinTS installiert ist
    $libPath = __DIR__.'/../../lib/php-fints/autoload.php';
    if (!file_exists($libPath)) {
        resultInfo(false, 'FINTS_NOT_INSTALLED', 'phpFinTS nicht gefunden. Bitte php-fints nach backend/lib/php-fints/ installieren (siehe dev/README).');
        return;
    }

    require_once $libPath;

    if (!class_exists('Fhp\FinTs')) {
        resultInfo(false, 'FINTS_NOT_INSTALLED', 'phpFinTS-Klasse nicht gefunden in backend/lib/php-fints/.');
        return;
    }

    try {
        $fints = new \Fhp\FinTs(
            $config['fints_url'],
            $config['fints_bank_code'],
            $config['fints_username'],
            $pin,
            FINTS_PRODUCT_ID ?: null
        );

        // Zeitraum bestimmen
        $fromDate = $data['from_date']
            ?? $config['sync_from_date']
            ?? ($config['last_sync'] ? date('Y-m-d', strtotime($config['last_sync'])) : date('Y-m-d', strtotime('-30 days')));
        $toDate = $data['to_date'] ?? date('Y-m-d');

        // Konten abrufen
        $accounts = $fints->getAccounts();

        // Passendes Konto finden (IBAN oder Kontonummer)
        $targetAccount = null;
        foreach ($accounts as $account) {
            if ($config['iban'] && $account->getIban() === $config['iban']) {
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

            resultInfo(true, 'TAN_REQUIRED', [
                'tan_required' => true,
                'tan_medium' => $tanRequest->getTanMediumName() ?? 'Unbekannt',
                'challenge' => $tanRequest->getChallenge(),
                'challenge_hhduc' => $tanRequest->getChallengeHHD_UC() ?? null
            ]);
            return;
        }

        // Kein TAN noetig — Umsaetze direkt verarbeiten
        $statements = $getStatement->getStatements();
        $importedCount = importFintsStatements($db, $bankAccountId, $statements, $fromDate, $toDate);

        // Sync-Zeitpunkt aktualisieren
        $db->execute(
            "UPDATE bank_account_fints SET last_sync = now() WHERE bank_account_id = :id",
            ['id' => $bankAccountId]
        );

        resultInfo(true, 'Synchronisiert', [
            'imported_count' => $importedCount,
            'from_date' => $fromDate,
            'to_date' => $toDate
        ]);

    } catch (\Exception $e) {
        writeLog('FinTS Error: ' . $e->getMessage());
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

    $tan = trim($data['tan'] ?? '');
    $pin = $data['pin'] ?? '';
    $bankAccountId = intval($data['bank_account_id'] ?? 0);

    if (empty($tan) || empty($pin) || $bankAccountId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'TAN, PIN und Bankkonto-ID sind Pflicht');
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
        $fints = new \Fhp\FinTs(
            $config['fints_url'],
            $config['fints_bank_code'],
            $config['fints_username'],
            $pin,
            FINTS_PRODUCT_ID ?: null
        );

        $fints->loadPersistedInstance($_SESSION['fints_persist']);

        $action = unserialize($_SESSION['fints_action']);
        $fints->submitTan($action, $tan);

        // Session aufraeumen
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'], $_SESSION['fints_bank_account_id']);

        if ($action instanceof \Fhp\Action\GetStatementOfAccount) {
            $statements = $action->getStatements();
            $importedCount = importFintsStatements($db, $bankAccountId, $statements, null, null);

            $db->execute(
                "UPDATE bank_account_fints SET last_sync = now() WHERE bank_account_id = :id",
                ['id' => $bankAccountId]
            );

            resultInfo(true, 'Synchronisiert', ['imported_count' => $importedCount]);
        } else {
            resultInfo(true, 'TAN akzeptiert');
        }

    } catch (\Exception $e) {
        // Session bei Fehler aufraeumen
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'], $_SESSION['fints_bank_account_id']);
        writeLog('FinTS TAN Error: ' . $e->getMessage());
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
        $fints = new \Fhp\FinTs(
            $config['fints_url'],
            $config['fints_bank_code'],
            $config['fints_username'],
            $pin,
            FINTS_PRODUCT_ID ?: null
        );

        // SEPA-Ueberweisung zusammenstellen
        $sepaCreditTransfer = new \Fhp\Action\SendSEPACreditTransfer();
        $sepaCreditTransfer->setAccountNumber($order['sender_iban']);
        $sepaCreditTransfer->setRecipientName($order['remote_name']);
        $sepaCreditTransfer->setRecipientIban($order['remote_iban']);
        $sepaCreditTransfer->setRecipientBic($order['remote_bic'] ?? '');
        $sepaCreditTransfer->setAmount(floatval($order['amount']));
        $sepaCreditTransfer->setPurpose($order['purpose']);

        $fints->execute($sepaCreditTransfer);

        if ($sepaCreditTransfer->needsTan()) {
            // TAN-Challenge
            session_start();
            $_SESSION['fints_action'] = serialize($sepaCreditTransfer);
            $_SESSION['fints_persist'] = $fints->persist();
            $_SESSION['fints_bank_account_id'] = $order['bank_account_id'];
            $_SESSION['fints_transfer_id'] = $transferId;

            // Status auf pending_tan setzen
            $db->execute(
                "UPDATE bank_transfer_orders SET status = 'pending_tan', mtime = now() WHERE id = :id",
                ['id' => $transferId]
            );

            $tanRequest = $sepaCreditTransfer->getTanRequest();

            resultInfo(true, 'TAN_REQUIRED', [
                'tan_required' => true,
                'tan_medium' => $tanRequest->getTanMediumName() ?? 'Unbekannt',
                'challenge' => $tanRequest->getChallenge(),
                'challenge_hhduc' => $tanRequest->getChallengeHHD_UC() ?? null
            ]);
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

        writeLog('FinTS Transfer Error: ' . $e->getMessage());
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

    if (empty($tan) || empty($pin) || $transferId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'TAN, PIN und Auftrags-ID sind Pflicht');
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
        $fints = new \Fhp\FinTs(
            $config['fints_url'],
            $config['fints_bank_code'],
            $config['fints_username'],
            $pin,
            FINTS_PRODUCT_ID ?: null
        );

        $fints->loadPersistedInstance($_SESSION['fints_persist']);
        $action = unserialize($_SESSION['fints_action']);
        $fints->submitTan($action, $tan);

        // Session aufraeumen
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'],
              $_SESSION['fints_bank_account_id'], $_SESSION['fints_transfer_id']);

        // Auftrag als gesendet markieren
        $db->execute(<<<SQL
            UPDATE bank_transfer_orders
            SET status = 'submitted', submitted_at = now(), mtime = now()
            WHERE id = :id
        SQL, ['id' => $transferId]);

        resultInfo(true, 'Ueberweisung gesendet');

    } catch (\Exception $e) {
        unset($_SESSION['fints_action'], $_SESSION['fints_persist'],
              $_SESSION['fints_bank_account_id'], $_SESSION['fints_transfer_id']);

        $db->execute(<<<SQL
            UPDATE bank_transfer_orders
            SET status = 'rejected', error_message = :error, mtime = now()
            WHERE id = :id
        SQL, ['id' => $transferId, 'error' => $e->getMessage()]);

        writeLog('FinTS Transfer TAN Error: ' . $e->getMessage());
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
        $fints = new \Fhp\FinTs(
            $config['fints_url'],
            $config['fints_bank_code'],
            $config['fints_username'],
            $pin,
            FINTS_PRODUCT_ID ?: null
        );

        $accounts = $fints->getAccounts();
        $targetAccount = null;

        foreach ($accounts as $account) {
            if ($config['iban'] && $account->getIban() === $config['iban']) {
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
        writeLog('FinTS Balance Error: ' . $e->getMessage());
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . $e->getMessage());
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
            $amount = $transaction->getAmount();
            $transdate = $transaction->getBookingDate() ? $transaction->getBookingDate()->format('Y-m-d') : date('Y-m-d');
            $valutadate = $transaction->getValutaDate() ? $transaction->getValutaDate()->format('Y-m-d') : $transdate;
            $purpose = $transaction->getMainDescription() ?? '';
            $remoteName = $transaction->getName() ?? '';
            $remoteIban = $transaction->getAccountNumber() ?? '';
            $remoteBic = $transaction->getBankCode() ?? '';
            $endToEndId = $transaction->getEndToEndID() ?? '';
            $primanota = $transaction->getPrimanota() ?? '';
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
                    remote_name, remote_iban, remote_bic,
                    remote_bank_code, remote_account_number,
                    purpose, primanota, booking_key,
                    end_to_end_id, currency_id,
                    fints_import_id, match_status
                ) VALUES (
                    :account_id, :transdate, :valutadate, :amount,
                    :remote_name, :remote_iban, :remote_bic,
                    :remote_bank_code, :remote_account_number,
                    :purpose, :primanota, :booking_key,
                    :end_to_end_id, :currency_id,
                    :import_id, 'unmatched'
                )
            SQL, [
                'account_id' => $bankAccountId,
                'transdate' => $transdate,
                'valutadate' => $valutadate,
                'amount' => $amount,
                'remote_name' => $remoteName,
                'remote_iban' => $remoteIban,
                'remote_bic' => $remoteBic,
                'remote_bank_code' => $remoteBic,
                'remote_account_number' => $remoteIban,
                'purpose' => $purpose,
                'primanota' => $primanota,
                'booking_key' => $bookingKey,
                'end_to_end_id' => $endToEndId,
                'currency_id' => $currencyId,
                'import_id' => $importId
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
