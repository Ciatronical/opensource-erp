<?php
// backend/api/trading/lib/core.php

/**
 * Grundbausteine des Handelssystems: Schutz, Einstellungen, Zustand, Audit, HTTP
 */

/**
 * Schutzfunktionen: Testmodus und Benutzerfreigabe für geschützte Tabellen
 */
class TradingGuard {

    /**
     * Läuft der Prozess im Testmodus?
     *
     * Im Testmodus (Umgebungsvariable OSERP_TRADING_TEST=1) verweigern alle
     * echten Broker-Adapter den Dienst und TradingHttp schickt nichts ins Netz.
     * Automatisierte Tests können so unter keinen Umständen Echtgeldorders auslösen.
     *
     * @return bool
     */
    public static function isTestMode(): bool {
        return getenv('OSERP_TRADING_TEST') === '1';
    }

    /**
     * Führt Schreibzugriffe auf geschützte Tabellen im Namen des Benutzers aus
     *
     * Setzt oserp.trading_actor = 'user' nur für die Dauer der Transaktion
     * (die Verbindungen sind persistent — ein sitzungsweiter Wert bliebe für
     * spätere Requests stehen). Darf ausschliesslich aus API-Aktionen des
     * angemeldeten Mitarbeiters gerufen werden. Der Trigger lässt nur Zeilen
     * dieses Mitarbeiters zu (und die Vorgabetabellen, deren Änderung die API
     * Administratoren vorbehält). Die Engine ruft das nie; ihre Schreibversuche
     * scheitern am Trigger.
     *
     * @param object   $db ApiDatabase
     * @param callable $fn Schreibzugriffe
     * @return mixed Rückgabewert von $fn
     */
    public static function asUser($db, callable $fn) {
        $db->beginTransaction();
        try {
            $db->execute("SELECT set_config('oserp.trading_actor', 'user', true)", []);
            $result = $fn($db);
            $db->commit();
            return $result;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
}

/**
 * Wessen Handelssystem gerade bearbeitet wird
 *
 * Jeder Mitarbeiter hat sein eigenes Handelssystem. Die Trennung macht die
 * Datenbank: alle Abfragen laufen über die Sichten my_*_trading, die nur die
 * Zeilen des Mitarbeiters der DB-Sitzung zeigen (oserp.employee_id). In der
 * API setzt DbhCompany diesen Wert je Request aus dem Login der Sitzung; die
 * Engine setzt ihn hier, bevor sie einen Benutzer bearbeitet.
 */
class TradingScope {

    /**
     * Mitarbeiter der DB-Sitzung (0 = keiner)
     *
     * @param object $db ApiDatabase
     * @return int employee.id
     */
    public static function id($db): int {
        $row = $db->getOne("SELECT NULLIF(current_setting('oserp.employee_id', true), '') AS id", []);
        return (int)($row['id'] ?? 0);
    }

    /**
     * Mitarbeiter der DB-Sitzung; bricht ab, wenn keiner gesetzt ist
     *
     * @param object $db ApiDatabase
     * @return int employee.id
     */
    public static function requireId($db): int {
        $id = self::id($db);
        if ($id <= 0) {
            throw new RuntimeException('Handelssystem: kein Mitarbeiter in der Sitzung');
        }
        return $id;
    }

    /**
     * Wechselt auf das Handelssystem eines Mitarbeiters (nur Engine und Tests)
     *
     * @param object   $db         ApiDatabase
     * @param int|null $employeeId employee.id, null = kein Mitarbeiter (Sichten leer)
     */
    public static function set($db, ?int $employeeId): void {
        $db->execute("SELECT set_config('oserp.employee_id', :id, false)", [':id' => $employeeId ? (string)$employeeId : '']);
    }

    /**
     * Mitarbeiter mit eigenem Handelssystem, in fester Reihenfolge
     *
     * Gelöschte Mitarbeiter fallen heraus: ihr System wird nicht mehr
     * bearbeitet, die Daten bleiben erhalten.
     *
     * @param object $db ApiDatabase
     * @return int[] employee.id
     */
    public static function users($db): array {
        $rows = $db->getAll(
            "SELECT u.employee_id FROM users_trading u
             JOIN employee e ON e.id = u.employee_id AND NOT COALESCE(e.deleted, false)
             ORDER BY u.employee_id", []
        ) ?: [];
        return array_map(fn($r) => (int)$r['employee_id'], $rows);
    }
}

/**
 * Einstellungen und Risikogrenzen (nur lesend — geändert wird über die API)
 *
 * Wirksam ist der eigene Wert des Mitarbeiters, sonst die Vorgabe der Firma.
 */
class TradingConfig {

    /**
     * Alle Einstellungen als key => value
     *
     * @param object $db ApiDatabase
     * @return array
     */
    public static function settings($db): array {
        $out = [];
        foreach ($db->getAll("SELECT key, value FROM my_settings_trading", []) ?: [] as $row) {
            $out[$row['key']] = $row['value'];
        }
        return $out;
    }

    /**
     * Alle Risikogrenzen als key => float
     *
     * @param object $db ApiDatabase
     * @return array
     */
    public static function limits($db): array {
        $out = [];
        foreach ($db->getAll("SELECT key, value FROM my_risk_limits_trading", []) ?: [] as $row) {
            $out[$row['key']] = (float)$row['value'];
        }
        return $out;
    }

    /**
     * Handelskosten für Simulation und Papierhandel
     *
     * @param array $settings Einstellungen
     * @return array fee_bps, slippage_bps, rebalance_threshold_pct, capital
     */
    public static function costs(array $settings): array {
        return [
            'fee_bps'                 => (float)($settings['fee_bps'] ?? 40),
            'slippage_bps'            => (float)($settings['slippage_bps'] ?? 10),
            'rebalance_threshold_pct' => (float)($settings['rebalance_threshold_pct'] ?? 5),
            'capital'                 => (float)($settings['lab_capital'] ?? 10000),
        ];
    }
}

/**
 * Laufzeitzustand der Engine (state_trading)
 *
 * get/set gelten für den Mitarbeiter der Sitzung (Schlüssel u<id>:…),
 * getGlobal/setGlobal für die ganze Firma (z. B. der Herzschlag der Engine).
 */
class TradingState {

    /**
     * Liest einen Zustandswert des Mitarbeiters der Sitzung
     *
     * @param object      $db      ApiDatabase
     * @param string      $key     Schlüssel
     * @param string|null $default Vorgabe
     * @return string|null
     */
    public static function get($db, string $key, ?string $default = null): ?string {
        return self::getGlobal($db, 'u' . TradingScope::requireId($db) . ':' . $key, $default);
    }

    /**
     * Schreibt einen Zustandswert des Mitarbeiters der Sitzung
     *
     * @param object $db    ApiDatabase
     * @param string $key   Schlüssel
     * @param string $value Wert
     */
    public static function set($db, string $key, string $value): void {
        self::setGlobal($db, 'u' . TradingScope::requireId($db) . ':' . $key, $value);
    }

    /**
     * Liest einen firmenweiten Zustandswert
     *
     * @param object      $db      ApiDatabase
     * @param string      $key     Schlüssel
     * @param string|null $default Vorgabe
     * @return string|null
     */
    public static function getGlobal($db, string $key, ?string $default = null): ?string {
        $row = $db->getOne("SELECT value FROM state_trading WHERE key = :k", [':k' => $key]);
        return $row ? $row['value'] : $default;
    }

    /**
     * Schreibt einen firmenweiten Zustandswert
     *
     * @param object $db    ApiDatabase
     * @param string $key   Schlüssel
     * @param string $value Wert
     */
    public static function setGlobal($db, string $key, string $value): void {
        $db->execute(
            "INSERT INTO state_trading (key, value, mtime) VALUES (:k, :v, now())
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now()",
            [':k' => $key, ':v' => $value]
        );
    }
}

/**
 * Audit-Protokoll
 */
class TradingAudit {

    /**
     * Schreibt einen Eintrag ins Audit-Protokoll
     *
     * @param object   $db        ApiDatabase
     * @param string   $actor     engine, research, risk, broker, user:<login>
     * @param string   $event     Ereigniskennung, z. B. order_submitted
     * @param array    $details   Zusatzdaten
     * @param string   $severity  info, warning, critical
     * @param int|null $accountId betroffenes Konto
     * @param int|null $orderId   betroffene Order
     */
    public static function log($db, string $actor, string $event, array $details = [], string $severity = 'info', ?int $accountId = null, ?int $orderId = null): void {
        $db->execute(
            "INSERT INTO my_audit_trading (actor, event, severity, account_id, order_id, details)
             VALUES (:actor, :event, :severity, :account_id, :order_id, :details::jsonb)",
            [
                ':actor' => $actor, ':event' => $event, ':severity' => $severity,
                ':account_id' => $accountId, ':order_id' => $orderId,
                ':details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            ]
        );
    }
}

/**
 * Fehler eines HTTP-Aufrufs, bei dem unklar ist, ob die Gegenseite ihn verarbeitet hat
 *
 * Zeitüberschreitung oder abgerissene Verbindung NACH dem Absenden: eine Order
 * kann angekommen sein. Der Aufrufer darf nicht blind wiederholen, sondern muss
 * erst beim Broker nachsehen (TradingOrders::reconcile).
 */
class TradingHttpUncertain extends RuntimeException {}

/**
 * HTTP-Zugriff für Marktdaten und Broker
 */
class TradingHttp {
    /** @var callable|null Ersatztransport für Tests: fn(method, url, headers, body) => [status, body] */
    public static $transport = null;

    /**
     * Führt einen HTTP-Aufruf aus
     *
     * @param string       $method  GET, POST, DELETE
     * @param string       $url     Adresse
     * @param array        $headers Kopfzeilen ['Name: Wert']
     * @param string|null  $body    Rumpf
     * @param int          $timeout Sekunden
     * @return array [int Status, string Rumpf]
     * @throws TradingHttpUncertain bei Zeitüberschreitung/Abbruch
     * @throws RuntimeException im Testmodus ohne Ersatztransport
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array {
        if (self::$transport !== null) {
            return (self::$transport)($method, $url, $headers, $body);
        }
        if (TradingGuard::isTestMode()) {
            throw new RuntimeException('Testmodus: kein Netzzugriff (' . $method . ' ' . parse_url($url, PHP_URL_HOST) . ')');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => array_merge(['User-Agent: oserp-trading'], $headers),
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new TradingHttpUncertain('Netzfehler (' . $errno . '): ' . $error);
        }
        return [$code, (string)$resp];
    }

    /**
     * GET mit JSON-Antwort; alles ausser Status 200 ist ein Fehler
     *
     * @param string $url     Adresse
     * @param array  $headers Kopfzeilen
     * @return array Dekodierte Antwort
     */
    public static function getJson(string $url, array $headers = []): array {
        [$code, $body] = self::request('GET', $url, $headers);
        $data = json_decode($body, true);
        if ($code !== 200 || !is_array($data)) {
            throw new RuntimeException('HTTP ' . $code . ' von ' . parse_url($url, PHP_URL_HOST) . ': ' . substr($body, 0, 200));
        }
        return $data;
    }
}
