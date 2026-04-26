<?php
// backend/api/banking/fints_py.php
//
// FinTS-Operationen, die VoP (Verification of Payee) benoetigen, werden ueber
// python-fints ausgefuehrt. phpFinTS 3.7 kennt das Verfahren nicht — die Bank
// lehnt SEPA-Auftraege seit 09.10.2025 mit Code 9076 ab.
//
// Architektur: PHP startet via proc_open ein Python-CLI-Skript
// (services/fints-py/fints_runner.py). Die FinTS-Dialog-Session wird zwischen
// User-TAN/VoP-Schritten in einer Pickle-Datei pro PHP-Session persistiert.

/**
 * Pfad zur Pickle-Datei fuer den aktuellen User. Stabil pro PHP-Session, damit
 * `fintsSubmitTransfer` (Initial) und spaetere `fintsSubmitTransferTan` /
 * `fintsApproveVopTransfer`-Calls dieselbe FinTS-Session weiterfuehren.
 */
function fintsPySessionPath(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $dir = realpath(__DIR__.'/../../') . '/run/fints';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/sess_' . hash('sha256', session_id() ?: 'anon') . '.pkl';
}

/**
 * Ruft das Python-Runner-Skript mit dem gegebenen Subcommand und Parametern auf.
 * Argumente werden ueber Argv uebergeben (PIN ist da OK, da Prozess nur lokal
 * laeuft und kein Cmdline-Logging passiert — siehe SECURITY-Hinweis unten).
 *
 * @return array  Geparstes JSON-Result vom Skript
 * @throws \RuntimeException  bei Crash, Timeout oder ungueltiger JSON-Antwort
 *
 * SECURITY: Wir nutzen proc_open mit array-args (kein Shell-Quoting noetig).
 *           PIN landet kurz im /proc/$pid/cmdline — wer den Server hat, hat
 *           ohnehin Zugriff. Eine Variante ueber stdin waere sauberer, aber
 *           argparse macht das umstaendlich; verschoben.
 */
function fintsPyExec(string $subcommand, array $args, int $timeoutSec = 90): array {
    $script = realpath(__DIR__.'/../../services/fints-py/fints_runner.py');
    if (!$script || !is_file($script)) {
        throw new \RuntimeException('fints_runner.py nicht gefunden');
    }
    // Bevorzugt den projekt-eigenen virtualenv — wichtig: NICHT realpath()
    // benutzen, sonst wird der venv-Symlink bis zum System-/usr/bin/python3
    // aufgeloest und sys.prefix zeigt aufs System statt aufs venv (=> kein
    // fints-Modul gefunden). Wir wollen den ungeparsten venv-Pfad als argv[0].
    $venvPython = __DIR__.'/../../services/fints-py/.venv/bin/python';
    $python = is_executable($venvPython) ? $venvPython : 'python3';
    // Debug-Flag durchschalten, wenn fints_debug in defaults_oserp aktiv ist —
    // python-fints loggt dann jede HBCI-Nachricht ins PHP-Debug-Log.
    if (isFintsDebugEnabled()) {
        $args[] = '--debug';
    }
    $cmd = array_merge([$python, $script, $subcommand], $args);

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, null, null);
    if (!is_resource($proc)) {
        throw new \RuntimeException('Python-Prozess konnte nicht gestartet werden');
    }
    fclose($pipes[0]);

    // Stdin direkt schliessen, dann Output sammeln. Naive Variante — fuer ein
    // ~30s-Bank-Round-Trip ausreichend, kein konkurrentes I/O noetig.
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $start = microtime(true);
    while (true) {
        $status = proc_get_status($proc);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        if (!$status['running']) break;
        if (microtime(true) - $start > $timeoutSec) {
            proc_terminate($proc, 9);
            throw new \RuntimeException('Python-FinTS-Aufruf Timeout nach '.$timeoutSec.'s');
        }
        usleep(100000); // 100 ms
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    if ($stderr !== '') {
        writeLog('[fints-py] stderr: '.trim($stderr), true, DLOG_DBG);
    }

    $stdout = trim($stdout);
    if ($stdout === '') {
        throw new \RuntimeException('Python-FinTS lieferte keine Antwort (exit='.$exitCode.', stderr='.trim($stderr).')');
    }
    $decoded = json_decode($stdout, true);
    if (!is_array($decoded)) {
        throw new \RuntimeException('Ungueltige JSON-Antwort vom Python-FinTS: '.substr($stdout, 0, 200));
    }
    return $decoded;
}

/**
 * Laedt einen Auftrag inkl. Sender-Konto-Daten. IBAN/BIC/BLZ/Kontonummer werden
 * von Whitespace befreit, damit der nachfolgende SEPA-Aufruf nicht an
 * formatierten DB-Werten scheitert.
 */
function fintsPyLoadOrder($db, int $transferId): ?array {
    return $db->getOne(<<<SQL
        SELECT bto.*,
               REGEXP_REPLACE(ba.iban, '\s+', '', 'g')           as sender_iban,
               REGEXP_REPLACE(ba.bic, '\s+', '', 'g')            as sender_bic,
               REGEXP_REPLACE(ba.bank_code, '\s+', '', 'g')      as sender_blz,
               REGEXP_REPLACE(ba.account_number, '\s+', '', 'g') as sender_account_number,
               ba.name as sender_account_name
        FROM bank_transfer_orders bto
        JOIN bank_accounts ba ON ba.id = bto.bank_account_id
        WHERE bto.id = :id
    SQL, ['id' => $transferId]);
}

/**
 * Mappt eine Python-Runner-Antwort auf eine resultInfo()-Antwort + DB-Status-Update.
 * Setzt darüber hinaus $_SESSION-Felder fuer Folge-TAN/VoP-Steps.
 */
function fintsPyHandleResult(array $result, int $transferId, $db): void {
    if (!session_id()) session_start();

    if (($result['status'] ?? '') === 'success') {
        $db->execute(<<<SQL
            UPDATE bank_transfer_orders
            SET status = 'submitted', submitted_at = now(), mtime = now(), error_message = NULL
            WHERE id = :id
        SQL, ['id' => $transferId]);
        unset($_SESSION['fints_py_transfer_id']);
        resultInfo(true, 'Ueberweisung gesendet');
        return;
    }

    if (($result['status'] ?? '') === 'need_tan') {
        $_SESSION['fints_py_transfer_id'] = $transferId;
        $_SESSION['fints_py_stage'] = 'tan';
        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'pending_tan', mtime = now() WHERE id = :id",
            ['id' => $transferId]
        );
        resultInfo(true, 'TAN_REQUIRED', [
            'tan_required' => true,
            'decoupled'    => !empty($result['decoupled']),
            'tan_medium'   => '',
            'challenge'    => $result['challenge'] ?? '',
            'challenge_html'  => $result['challenge_html'] ?? '',
            'challenge_hhduc' => $result['challenge_hhduc'] ?? null,
            'vop_match_status' => $result['vop_match_status'] ?? null,
        ]);
        return;
    }

    if (($result['status'] ?? '') === 'need_vop_approval') {
        $_SESSION['fints_py_transfer_id'] = $transferId;
        $_SESSION['fints_py_stage'] = 'vop';
        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'pending_tan', mtime = now() WHERE id = :id",
            ['id' => $transferId]
        );
        resultInfo(true, 'VOP_APPROVAL_REQUIRED', [
            'vop'    => $result['vop'] ?? null,
            'notice' => $result['manual_authorization_notice'] ?? null,
        ]);
        return;
    }

    // status === 'error' oder Unbekannt
    $msg = $result['message'] ?? 'Unbekannter Python-FinTS-Fehler';
    if (!empty($result['trace'])) {
        // Python-Traceback ins PHP-Debug-Log — entscheidend zur Diagnose von
        // Bank-Init-/Login-Fehlern, sonst sieht man im UI nur die Top-Message.
        writeLog('[fints-py] traceback: ' . $result['trace'], true, DLOG_ERR);
    }
    $db->execute(<<<SQL
        UPDATE bank_transfer_orders
        SET status = 'rejected', error_message = :err, mtime = now()
        WHERE id = :id
    SQL, ['id' => $transferId, 'err' => fintsToUtf8($msg)]);
    unset($_SESSION['fints_py_transfer_id']);
    resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($msg));
}

/**
 * FinTS: Ueberweisung via python-fints (mit VoP-Support).
 *
 * ACHTUNG: bei Banken mit Login-pushTAN (Sparkassen-Geschaeftskunden) liefert
 * python-fints' pause/resume-Mechanismus aktuell ungueltige Signaturen
 * zurueck (9340) — das zaehlt als PIN-Fehlversuch und kann den Bank-Zugang
 * sperren. Daher nur waehlbar pro Auftrag (engine='python'), nicht default.
 *
 * Wird ueber den Dispatcher fintsSubmitTransfer aufgerufen wenn der Auftrag
 * `engine='python'` hat.
 *
 * @testdata {"transfer_order_id": 1, "pin": "12345"}
 */
function fintsSubmitTransferPy($data) {
    $db = DbhCompany::begin();

    $transferId = intval($data['transfer_order_id'] ?? 0);
    $pin = $data['pin'] ?? '';

    if ($transferId <= 0 || empty($pin)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Auftrags-ID und PIN sind Pflicht');
        return;
    }

    $order = fintsPyLoadOrder($db, $transferId);
    if (!$order) {
        resultInfo(false, 'NOT_FOUND', 'Auftrag nicht gefunden');
        return;
    }
    if ($order['status'] !== 'draft' && $order['status'] !== 'rejected') {
        resultInfo(false, 'NOT_SUBMITTABLE', 'Nur Entwuerfe koennen gesendet werden');
        return;
    }

    $config = $db->getOne(
        "SELECT baf.* FROM bank_account_fints baf WHERE baf.bank_account_id = :id",
        ['id' => $order['bank_account_id']]
    );
    if (!$config) {
        resultInfo(false, 'NO_FINTS_CONFIG', 'Keine FinTS-Konfiguration vorhanden');
        return;
    }

    $companyRow = $db->getOne("SELECT company FROM defaults");
    $senderName = trim($companyRow['company'] ?? '') ?: ($order['sender_account_name'] ?? 'Absender');

    $configJson = json_encode([
        'fints_url'       => $config['fints_url'],
        'fints_bank_code' => preg_replace('/\s+/', '', $config['fints_bank_code'] ?? ''),
        'fints_username'  => $config['fints_username'],
        'fints_tan_mode'  => $config['fints_tan_mode'] ?? null,
        'pin'             => $pin,
        'product_id'      => getFintsProductId(),
    ], JSON_UNESCAPED_UNICODE);

    $transferJson = json_encode([
        'sender_iban'    => $order['sender_iban'],
        'sender_name'    => $senderName,
        'recipient_iban' => $order['remote_iban'],
        'recipient_bic'  => $order['remote_bic'] ?? '',
        'recipient_name' => $order['remote_name'],
        'amount'         => $order['amount'],
        'purpose'        => $order['purpose'],
    ], JSON_UNESCAPED_UNICODE);

    try {
        $result = fintsPyExec('transfer', [
            '--session', fintsPySessionPath(),
            '--config',  $configJson,
            '--transfer', $transferJson,
        ]);
    } catch (\Throwable $e) {
        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'rejected', error_message = :err, mtime = now() WHERE id = :id",
            ['id' => $transferId, 'err' => fintsToUtf8($e->getMessage())]
        );
        fintsLogException('fintsSubmitTransfer-py', $e, ['transfer_id' => $transferId]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
        return;
    }

    fintsPyHandleResult($result, $transferId, $db);
}

/**
 * FinTS: TAN fuer Ueberweisung einreichen (via python-fints)
 *
 * @param string $data['tan']              TAN-Eingabe (leer bei Decoupled/pushTAN)
 * @param string $data['pin']              Online-Banking PIN
 * @param int    $data['transfer_order_id'] Ueberweisungsauftrags-ID
 * @testdata {"tan": "123456", "pin": "12345", "transfer_order_id": 1}
 */
function fintsSubmitTransferTanPy($data) {
    $db = DbhCompany::begin();

    $tan = trim($data['tan'] ?? '');
    $pin = $data['pin'] ?? '';
    $transferId = intval($data['transfer_order_id'] ?? 0);

    if (empty($pin) || $transferId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'PIN und Auftrags-ID sind Pflicht');
        return;
    }

    if (!session_id()) session_start();
    if (($_SESSION['fints_py_transfer_id'] ?? 0) !== $transferId) {
        resultInfo(false, 'SESSION_MISMATCH', 'Auftrags-ID stimmt nicht mit der pausierten FinTS-Session ueberein');
        return;
    }

    $order = fintsPyLoadOrder($db, $transferId);
    if (!$order) {
        resultInfo(false, 'NOT_FOUND', 'Auftrag nicht gefunden');
        return;
    }
    $config = $db->getOne(
        "SELECT baf.* FROM bank_account_fints baf WHERE baf.bank_account_id = :id",
        ['id' => $order['bank_account_id']]
    );
    if (!$config) {
        resultInfo(false, 'NO_FINTS_CONFIG', 'Keine FinTS-Konfiguration vorhanden');
        return;
    }

    $configJson = json_encode([
        'fints_url'       => $config['fints_url'],
        'fints_bank_code' => preg_replace('/\s+/', '', $config['fints_bank_code'] ?? ''),
        'fints_username'  => $config['fints_username'],
        'fints_tan_mode'  => $config['fints_tan_mode'] ?? null,
        'pin'             => $pin,
        'product_id'      => getFintsProductId(),
    ], JSON_UNESCAPED_UNICODE);

    try {
        $result = fintsPyExec('tan', [
            '--session', fintsPySessionPath(),
            '--config',  $configJson,
            '--tan',     $tan,
        ]);
    } catch (\Throwable $e) {
        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'rejected', error_message = :err, mtime = now() WHERE id = :id",
            ['id' => $transferId, 'err' => fintsToUtf8($e->getMessage())]
        );
        unset($_SESSION['fints_py_transfer_id']);
        fintsLogException('fintsSubmitTransferTan-py', $e, ['transfer_id' => $transferId]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
        return;
    }

    fintsPyHandleResult($result, $transferId, $db);
}

/**
 * FinTS: VoP-Mismatch trotzdem freigeben und den Auftrag jetzt absenden.
 *
 * Wird aufgerufen, wenn die initialen Bank-Antwort einen "Namensabgleich
 * negativ"/"close match"-Status zurueckgegeben hat und der User explizit
 * bestaetigt hat dass er trotzdem ueberweisen will. Danach wird die Bank
 * regulaer eine TAN-Anforderung senden.
 *
 * @param string $data['pin']              Online-Banking PIN
 * @param int    $data['transfer_order_id'] Ueberweisungsauftrags-ID
 * @testdata {"pin": "12345", "transfer_order_id": 1}
 */
function fintsApproveVopTransfer($data) {
    // Bei vop_check-Engine → phpFinTS VoP-Approve
    if (fintsLookupEngine(intval($data['transfer_order_id'] ?? 0)) === 'vop_check') {
        fintsVopApproveAndSend($data);
        return;
    }

    $db = DbhCompany::begin();

    $pin = $data['pin'] ?? '';
    $transferId = intval($data['transfer_order_id'] ?? 0);

    if (empty($pin) || $transferId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'PIN und Auftrags-ID sind Pflicht');
        return;
    }

    if (!session_id()) session_start();
    if (($_SESSION['fints_py_transfer_id'] ?? 0) !== $transferId) {
        resultInfo(false, 'SESSION_MISMATCH', 'Auftrags-ID stimmt nicht mit der pausierten FinTS-Session ueberein');
        return;
    }

    $order = fintsPyLoadOrder($db, $transferId);
    if (!$order) {
        resultInfo(false, 'NOT_FOUND', 'Auftrag nicht gefunden');
        return;
    }
    $config = $db->getOne(
        "SELECT baf.* FROM bank_account_fints baf WHERE baf.bank_account_id = :id",
        ['id' => $order['bank_account_id']]
    );
    if (!$config) {
        resultInfo(false, 'NO_FINTS_CONFIG', 'Keine FinTS-Konfiguration vorhanden');
        return;
    }

    $configJson = json_encode([
        'fints_url'       => $config['fints_url'],
        'fints_bank_code' => preg_replace('/\s+/', '', $config['fints_bank_code'] ?? ''),
        'fints_username'  => $config['fints_username'],
        'fints_tan_mode'  => $config['fints_tan_mode'] ?? null,
        'pin'             => $pin,
        'product_id'      => getFintsProductId(),
    ], JSON_UNESCAPED_UNICODE);

    try {
        $result = fintsPyExec('approve_vop', [
            '--session', fintsPySessionPath(),
            '--config',  $configJson,
        ]);
    } catch (\Throwable $e) {
        $db->execute(
            "UPDATE bank_transfer_orders SET status = 'rejected', error_message = :err, mtime = now() WHERE id = :id",
            ['id' => $transferId, 'err' => fintsToUtf8($e->getMessage())]
        );
        unset($_SESSION['fints_py_transfer_id']);
        fintsLogException('fintsApproveVopTransfer-py', $e, ['transfer_id' => $transferId]);
        resultInfo(false, 'FINTS_ERROR', 'FinTS-Fehler: ' . fintsToUtf8($e->getMessage()));
        return;
    }

    fintsPyHandleResult($result, $transferId, $db);
}


// ===========================================================================
// Engine-Dispatcher
// ===========================================================================
//
// Frontend ruft action="fintsSubmitTransfer" / "fintsSubmitTransferTan" auf.
// Welche Engine genutzt wird, steht pro Auftrag in bank_transfer_orders.engine
// ('php' oder 'python'). Default ist 'php' (sicher, kein Auth-Risiko, scheitert
// aber bei VoP-Pflicht); 'python' ist Test-Variante mit VoP-Support.

/**
 * Liest die Engine ('php'|'python') fuer einen Auftrag aus der DB.
 * Default 'php' falls Spalte fehlt oder Wert ungueltig ist.
 */
function fintsLookupEngine(int $transferId): string {
    if ($transferId <= 0) return 'php';
    $db = DbhCompany::begin();
    $row = $db->getOne(
        "SELECT COALESCE(engine, 'php') as engine FROM bank_transfer_orders WHERE id = :id",
        ['id' => $transferId]
    );
    $engine = strtolower(trim($row['engine'] ?? 'php'));
    return in_array($engine, ['php', 'python', 'vop_optout', 'vop_check'], true) ? $engine : 'php';
}

/**
 * Dispatcher: ruft je nach Engine-Spalte des Auftrags die passende Variante.
 *
 * @param int    $data['transfer_order_id'] Ueberweisungsauftrags-ID
 * @param string $data['pin']               Online-Banking PIN
 * @testdata {"transfer_order_id": 1, "pin": "12345"}
 */
function fintsSubmitTransfer($data) {
    $engine = fintsLookupEngine(intval($data['transfer_order_id'] ?? 0));
    if ($engine === 'python') {
        fintsSubmitTransferPy($data);
    } elseif ($engine === 'vop_check') {
        fintsSubmitTransferVopCheck($data);
    } else {
        // 'php' und 'vop_optout' → phpFinTS, fintsSubmitTransferPhp liest engine aus order
        fintsSubmitTransferPhp($data);
    }
}

/**
 * Dispatcher fuer TAN-Submission.
 *
 * @param string $data['tan']               TAN (leer bei pushTAN/decoupled)
 * @param string $data['pin']               Online-Banking PIN
 * @param int    $data['transfer_order_id'] Ueberweisungsauftrags-ID
 * @testdata {"tan": "123456", "pin": "12345", "transfer_order_id": 1}
 */
function fintsSubmitTransferTan($data) {
    $engine = fintsLookupEngine(intval($data['transfer_order_id'] ?? 0));
    if ($engine === 'python') {
        fintsSubmitTransferTanPy($data);
    } elseif ($engine === 'vop_check') {
        fintsSubmitTransferVopTan($data);
    } else {
        fintsSubmitTransferTanPhp($data);
    }
}

