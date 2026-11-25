<?php
// backend/api/trading/trading.php

/**
 * API der Erweiterung „KI-Trading“
 *
 * Nur die Funktionen in dieser Datei sind über `action` aufrufbar. Alles
 * Übrige des Handelssystems (lib/) besteht aus Klassen und ist von aussen
 * nicht erreichbar — insbesondere gibt es keine Aktion, die eine einzelne
 * Order aufgibt.
 *
 * Rechte:
 *   lesen, Not-Aus, Echtgeldhandel DEAKTIVIEREN   jeder angemeldete Mitarbeiter
 *   Echtgeldhandel aktivieren, Grenzen, Einstellungen,
 *   Broker-Zugänge, Sperren aufheben, Lauf anstossen   nur Systemadministratoren
 */

/**
 * Hilfsfunktionen der API (als Klasse, damit sie nicht selbst aufrufbar sind)
 */
class TradingApi {

    /** @var array|null Nur für automatisierte Tests auf der Kommandozeile: ['login' => string, 'admin' => bool] */
    public static $testUser = null;

    /**
     * Ersatzbenutzer der Tests — greift ausschliesslich im Testmodus auf der Kommandozeile
     *
     * @return array|null
     */
    private static function testUser(): ?array {
        return (PHP_SAPI === 'cli' && TradingGuard::isTestMode()) ? self::$testUser : null;
    }

    /**
     * Firmen-Datenbank; bricht ab, wenn die Erweiterung nicht aktiv ist
     *
     * @return ApiDatabase
     */
    public static function db() {
        $db = DbhCompany::begin();
        if (!isExtensionActive($db, 'trading')) {
            throw new ApiError('EXTENSION_INACTIVE', 'Die Erweiterung KI-Trading ist für diese Firma nicht aktiviert');
        }
        return $db;
    }

    /**
     * Login des angemeldeten Mitarbeiters
     *
     * @return string
     */
    public static function login(): string {
        if (self::testUser() !== null) return self::testUser()['login'];
        return (string)DbhAuth::begin()->getLogin();
    }

    /**
     * Ist der angemeldete Mitarbeiter Systemadministrator?
     *
     * @return bool
     */
    public static function isAdmin(): bool {
        if (self::testUser() !== null) return (bool)self::testUser()['admin'];
        $auth = DbhAuth::begin();
        return isSystemAdmin($auth->getUserId(), $auth->getLogin());
    }

    /**
     * Bricht ab, wenn der angemeldete Mitarbeiter kein Systemadministrator ist
     */
    public static function requireAdmin(): void {
        if (!self::isAdmin()) {
            throw new ApiError('NO_PERMISSION', 'Nur Systemadministratoren dürfen das Handelssystem konfigurieren');
        }
    }

    /**
     * Führt eine Abfrage aus, die genau eine JSON-Spalte "result" liefert
     *
     * @param object $db     ApiDatabase
     * @param string $sql    Abfrage
     * @param array  $params Platzhalter
     * @return mixed Dekodiertes JSON
     */
    public static function json($db, string $sql, array $params = []) {
        $row = $db->getOne($sql, $params);
        return json_decode($row['result'] ?? 'null', true);
    }
}

/**
 * Dashboard: Kennzahlen, Kapitalkurve, Strategien, Verteilung, Meldungen
 *
 * @param int $data['days'] Länge der Kapitalkurve in Tagen (optional, Standard 180)
 * @testdata {"days": 180}
 */
function getTradingDashboard($data) {
    $db = TradingApi::db();
    $days = max(7, min(3650, (int)($data['days'] ?? 180)));
    $result = TradingApi::json($db,
        "WITH s AS (" . TradingReport::summarySql() . "),
         pf AS (
            SELECT a.id, a.start_capital, a.itime FROM accounts_trading a
            WHERE a.closed_at IS NULL AND a.active AND a.kind = COALESCE((SELECT summary->>'account_kind' FROM s), 'portfolio_paper')
            ORDER BY a.id LIMIT 1
         ),
         bench AS (SELECT id FROM instruments_trading WHERE is_benchmark ORDER BY id LIMIT 1)
         SELECT json_build_object(
            'summary', (SELECT summary FROM s),
            'is_admin', :is_admin::boolean,
            'equity_curve', (
                SELECT COALESCE(json_agg(json_build_object('day', e.day, 'equity', e.equity,
                           'benchmark', (SELECT b.close FROM bars_trading b WHERE b.instrument_id = (SELECT id FROM bench) AND b.ts::date <= e.day ORDER BY b.ts DESC LIMIT 1)) ORDER BY e.day), '[]'::json)
                FROM equity_trading e JOIN pf ON pf.id = e.account_id
                WHERE e.day >= CURRENT_DATE - :days::int),
            'strategies', (
                SELECT COALESCE(json_agg(json_build_object(
                           'id', st.id, 'code', st.code, 'name', st.name, 'family', st.family, 'status', st.status,
                           'paper_since', st.paper_since, 'start_capital', a.start_capital,
                           'equity', (SELECT e.equity FROM equity_trading e WHERE e.account_id = a.id ORDER BY e.day DESC LIMIT 1),
                           'weight', (SELECT al.weight FROM allocations_trading al WHERE al.strategy_id = st.id
                                        AND al.day = (SELECT max(day) FROM allocations_trading)),
                           'curve', (SELECT COALESCE(json_agg(json_build_object('day', e.day, 'equity', e.equity) ORDER BY e.day), '[]'::json)
                                     FROM equity_trading e WHERE e.account_id = a.id AND e.day >= CURRENT_DATE - :days2::int)
                       ) ORDER BY st.code), '[]'::json)
                FROM strategies_trading st
                JOIN accounts_trading a ON a.strategy_id = st.id AND a.kind = 'strategy_paper' AND a.closed_at IS NULL
                WHERE st.status IN ('paper', 'live_eligible', 'live')),
            'positions', (
                SELECT COALESCE(json_agg(json_build_object('symbol', i.symbol, 'name', i.name, 'qty', p.qty, 'avg_price', p.avg_price,
                           'last_close', (SELECT b.close FROM bars_trading b WHERE b.instrument_id = i.id ORDER BY b.ts DESC LIMIT 1)) ORDER BY i.symbol), '[]'::json)
                FROM positions_trading p JOIN pf ON pf.id = p.account_id JOIN instruments_trading i ON i.id = p.instrument_id
                WHERE p.qty > 0),
            'alerts', (
                SELECT COALESCE(json_agg(json_build_object('id', au.id, 'ts', au.ts, 'event', au.event, 'severity', au.severity, 'details', au.details) ORDER BY au.ts DESC), '[]'::json)
                FROM (SELECT * FROM audit_trading WHERE severity IN ('warning', 'critical') AND ts >= now() - interval '7 days'
                      ORDER BY ts DESC LIMIT 15) au),
            'report', (SELECT json_build_object('day', r.day, 'data', r.data) FROM reports_trading r ORDER BY r.day DESC LIMIT 1),
            'research', (SELECT json_build_object(
                            'experiments', (SELECT count(*) FROM experiments_trading),
                            'tested', (SELECT count(*) FROM strategy_versions_trading),
                            'last', (SELECT json_build_object('id', x.id, 'started_at', x.started_at, 'summary', x.summary)
                                     FROM experiments_trading x ORDER BY x.id DESC LIMIT 1))),
            'has_live_connection', EXISTS (SELECT 1 FROM broker_connections_trading WHERE environment = 'live' AND active)
         ) AS result",
        [':is_admin' => TradingApi::isAdmin(), ':days' => $days, ':days2' => $days]
    );
    resultInfo(true, '', ['results' => $result]);
}

/**
 * Kennzahlen für die Kachel auf der Startseite (klein und schnell)
 *
 * @testdata {}
 */
function getTradingSummary($data) {
    $db = TradingApi::db();
    $result = TradingApi::json($db, "WITH s AS (" . TradingReport::summarySql() . ") SELECT (SELECT summary FROM s) AS result");
    resultInfo(true, '', ['results' => $result]);
}

/**
 * Strategien mit Backtest-Kennzahlen je Datenabschnitt
 *
 * @param string $data['scope'] running (laufende), all (auch verworfene) — optional, Standard running
 * @param int    $data['limit'] Höchstzahl (optional, Standard 200)
 * @testdata {"scope": "all", "limit": 50}
 */
function getTradingStrategies($data) {
    $db = TradingApi::db();
    $result = TradingApi::json($db,
        "SELECT COALESCE(json_agg(row_to_json(t) ORDER BY t.sort_status, t.id DESC), '[]'::json) AS result
         FROM (
            SELECT s.id, s.code, s.name, s.family, s.status, s.origin, s.parent_id, s.hypothesis, s.status_reason, s.paper_since, s.itime,
                   v.version, v.params,
                   array_position(ARRAY['live', 'live_eligible', 'paper', 'queued', 'retired', 'rejected', 'candidate'], s.status) AS sort_status,
                   (SELECT json_object_agg(b.segment, json_build_object('sharpe', b.sharpe, 'total_return', b.total_return,
                               'max_drawdown', b.max_drawdown, 'trades', b.trades, 'benchmark_return', b.benchmark_return,
                               'p_value', b.p_value, 'deflated_sharpe', b.deflated_sharpe, 'stability', b.stability, 'exposure', b.exposure))
                    FROM backtests_trading b WHERE b.strategy_version_id = v.id) AS backtests,
                   (SELECT json_build_object('start_capital', a.start_capital,
                               'equity', (SELECT e.equity FROM equity_trading e WHERE e.account_id = a.id ORDER BY e.day DESC LIMIT 1),
                               'peak_equity', a.peak_equity,
                               'trades', (SELECT count(*) FROM orders_trading o WHERE o.account_id = a.id AND o.status = 'filled'))
                    FROM accounts_trading a WHERE a.strategy_id = s.id AND a.kind = 'strategy_paper' ORDER BY a.id DESC LIMIT 1) AS paper
            FROM strategies_trading s
            LEFT JOIN strategy_versions_trading v ON v.id = s.current_version_id
            WHERE (:scope = 'all' OR s.status IN ('paper', 'live_eligible', 'live', 'queued', 'retired'))
            ORDER BY array_position(ARRAY['live', 'live_eligible', 'paper', 'queued', 'retired', 'rejected', 'candidate'], s.status), s.id DESC
            LIMIT :limit
         ) t",
        [':scope' => ($data['scope'] ?? 'running') === 'all' ? 'all' : 'running', ':limit' => max(1, min(1000, (int)($data['limit'] ?? 200)))]
    );
    resultInfo(true, '', ['results' => $result]);
}

/**
 * Einzelne Strategie: Versionen, Backtests samt Kapitalkurve, Vorwärtstest, Orders
 *
 * @param int $data['id'] ID der Strategie
 * @testdata {"id": 1}
 */
function getTradingStrategy($data) {
    $db = TradingApi::db();
    $result = TradingApi::json($db,
        "SELECT json_build_object(
            'strategy', (SELECT row_to_json(s) FROM strategies_trading s WHERE s.id = :id),
            'versions', (
                SELECT COALESCE(json_agg(json_build_object('id', v.id, 'version', v.version, 'params', v.params, 'created_by', v.created_by,
                           'change_note', v.change_note, 'itime', v.itime,
                           'backtests', (SELECT COALESCE(json_agg(json_build_object(
                                   'segment', b.segment, 'from_ts', b.from_ts, 'to_ts', b.to_ts, 'bars', b.bars, 'total_return', b.total_return,
                                   'cagr', b.cagr, 'volatility', b.volatility, 'sharpe', b.sharpe, 'sortino', b.sortino,
                                   'max_drawdown', b.max_drawdown, 'trades', b.trades, 'win_rate', b.win_rate, 'profit_factor', b.profit_factor,
                                   'fees_paid', b.fees_paid, 'turnover', b.turnover, 'exposure', b.exposure, 'benchmark_return', b.benchmark_return,
                                   'p_value', b.p_value, 'deflated_sharpe', b.deflated_sharpe, 'stability', b.stability, 'passed', b.passed,
                                   'gate_details', b.gate_details, 'equity_curve', b.equity_curve) ORDER BY b.id), '[]'::json)
                               FROM backtests_trading b WHERE b.strategy_version_id = v.id)
                       ) ORDER BY v.version DESC), '[]'::json)
                FROM strategy_versions_trading v WHERE v.strategy_id = :id2),
            'paper_curve', (
                SELECT COALESCE(json_agg(json_build_object('day', e.day, 'equity', e.equity) ORDER BY e.day), '[]'::json)
                FROM equity_trading e JOIN accounts_trading a ON a.id = e.account_id
                WHERE a.strategy_id = :id3 AND a.kind = 'strategy_paper'),
            'orders', (
                SELECT COALESCE(json_agg(row_to_json(o) ORDER BY o.id DESC), '[]'::json)
                FROM (SELECT o.id, o.itime, i.symbol, o.side, o.order_type, o.qty, o.status, o.filled_qty, o.avg_fill_price, o.fees, o.reason, o.error
                      FROM orders_trading o JOIN accounts_trading a ON a.id = o.account_id JOIN instruments_trading i ON i.id = o.instrument_id
                      WHERE a.strategy_id = :id4 ORDER BY o.id DESC LIMIT 100) o),
            'children', (
                SELECT COALESCE(json_agg(json_build_object('id', c.id, 'code', c.code, 'status', c.status, 'origin', c.origin) ORDER BY c.id DESC), '[]'::json)
                FROM (SELECT * FROM strategies_trading WHERE parent_id = :id5 ORDER BY id DESC LIMIT 30) c)
         ) AS result",
        [':id' => (int)($data['id'] ?? 0), ':id2' => (int)($data['id'] ?? 0), ':id3' => (int)($data['id'] ?? 0),
         ':id4' => (int)($data['id'] ?? 0), ':id5' => (int)($data['id'] ?? 0)]
    );
    resultInfo(true, '', ['results' => $result]);
}

/**
 * Forschungsprotokoll: Experimente der KI
 *
 * @param int $data['limit'] Höchstzahl (optional, Standard 50)
 * @testdata {"limit": 20}
 */
function getTradingExperiments($data) {
    $db = TradingApi::db();
    $result = TradingApi::json($db,
        "SELECT json_build_object(
            'experiments', (SELECT COALESCE(json_agg(row_to_json(x) ORDER BY x.id DESC), '[]'::json)
                            FROM (SELECT * FROM experiments_trading ORDER BY id DESC LIMIT :limit) x),
            'families', (
                SELECT COALESCE(json_agg(row_to_json(f) ORDER BY f.family), '[]'::json)
                FROM (SELECT s.family, count(*) AS tested,
                             count(*) FILTER (WHERE s.status IN ('paper', 'live_eligible', 'live', 'queued', 'retired')) AS passed,
                             round(avg(b.sharpe)::numeric, 2) AS avg_validation_sharpe,
                             round(max(b.sharpe)::numeric, 2) AS best_validation_sharpe
                      FROM strategies_trading s
                      LEFT JOIN backtests_trading b ON b.strategy_version_id = s.current_version_id AND b.segment = 'validation'
                      GROUP BY s.family) f),
            'totals', json_build_object(
                'versions_tested', (SELECT count(*) FROM strategy_versions_trading),
                'test_set_evaluations', (SELECT count(*) FROM backtests_trading WHERE segment = 'test'),
                'passed', (SELECT count(*) FROM backtests_trading WHERE segment = 'test' AND passed))
         ) AS result",
        [':limit' => max(1, min(500, (int)($data['limit'] ?? 50)))]
    );
    resultInfo(true, '', ['results' => $result]);
}

/**
 * Orders aller Konten mit Ergebnis der Risikoprüfung
 *
 * @param int    $data['limit']  Höchstzahl (optional, Standard 100)
 * @param string $data['status'] nur dieser Status (optional)
 * @testdata {"limit": 50}
 */
function getTradingOrders($data) {
    $db = TradingApi::db();
    $result = TradingApi::json($db,
        "SELECT COALESCE(json_agg(row_to_json(o) ORDER BY o.id DESC), '[]'::json) AS result
         FROM (SELECT o.id, o.itime, o.submitted_at, a.name AS account, a.kind AS account_kind, i.symbol, o.side, o.order_type, o.qty,
                      o.limit_price, o.stop_price, o.reference_price, o.status, o.filled_qty, o.avg_fill_price, o.fees, o.reason, o.error,
                      o.risk_check, o.client_order_id, o.broker_order_id
               FROM orders_trading o
               JOIN accounts_trading a ON a.id = o.account_id
               JOIN instruments_trading i ON i.id = o.instrument_id
               WHERE (:status = '' OR o.status = :status2)
               ORDER BY o.id DESC LIMIT :limit) o",
        [':status' => (string)($data['status'] ?? ''), ':status2' => (string)($data['status'] ?? ''), ':limit' => max(1, min(1000, (int)($data['limit'] ?? 100)))]
    );
    resultInfo(true, '', ['results' => $result]);
}

/**
 * Sicherheit und Einstellungen: Grenzen, Sperren, Freigabeprotokoll, Broker, Konten, Instrumente
 *
 * @testdata {}
 */
function getTradingControl($data) {
    $db = TradingApi::db();
    $result = TradingApi::json($db,
        "SELECT json_build_object(
            'is_admin', :is_admin::boolean,
            'limits', (SELECT COALESCE(json_agg(json_build_object('key', key, 'value', value, 'unit', unit, 'mtime', mtime, 'modified_by', modified_by) ORDER BY sortkey), '[]'::json) FROM risk_limits_trading),
            'settings', (SELECT COALESCE(json_agg(json_build_object('key', key, 'value', value, 'value_type', value_type, 'section', section) ORDER BY section, sortkey), '[]'::json) FROM settings_trading),
            'locks', (SELECT COALESCE(json_agg(row_to_json(l) ORDER BY l.id DESC), '[]'::json) FROM (SELECT * FROM locks_trading ORDER BY id DESC LIMIT 50) l),
            'authorizations', (SELECT COALESCE(json_agg(json_build_object('action', action, 'login', login, 'itime', itime) ORDER BY id DESC), '[]'::json)
                               FROM (SELECT * FROM live_authorizations_trading ORDER BY id DESC LIMIT 20) la),
            'live_authorized', COALESCE((SELECT action = 'enable' FROM live_authorizations_trading ORDER BY id DESC LIMIT 1), false),
            'connections', (SELECT COALESCE(json_agg(json_build_object('id', c.id, 'name', c.name, 'adapter', c.adapter, 'environment', c.environment,
                                   'capital_limit', c.capital_limit, 'active', c.active, 'has_key', c.api_key_enc IS NOT NULL AND c.api_key_enc <> '',
                                   'status', (SELECT value FROM state_trading WHERE key = 'broker_status_' || c.id)) ORDER BY c.id), '[]'::json)
                            FROM broker_connections_trading c),
            'accounts', (SELECT COALESCE(json_agg(json_build_object('id', a.id, 'kind', a.kind, 'name', a.name, 'currency', a.currency,
                                   'start_capital', a.start_capital, 'cash', a.cash, 'peak_equity', a.peak_equity, 'last_signal_ts', a.last_signal_ts,
                                   'equity', (SELECT e.equity FROM equity_trading e WHERE e.account_id = a.id ORDER BY e.day DESC LIMIT 1)) ORDER BY a.id), '[]'::json)
                         FROM accounts_trading a WHERE a.closed_at IS NULL),
            'instruments', (SELECT COALESCE(json_agg(json_build_object('id', i.id, 'symbol', i.symbol, 'name', i.name, 'asset_class', i.asset_class,
                                   'active', i.active, 'is_benchmark', i.is_benchmark,
                                   'bars', (SELECT count(*) FROM bars_trading b WHERE b.instrument_id = i.id),
                                   'first_bar', (SELECT min(ts) FROM bars_trading b WHERE b.instrument_id = i.id),
                                   'last_bar', (SELECT max(ts) FROM bars_trading b WHERE b.instrument_id = i.id),
                                   'venues', (SELECT COALESCE(json_agg(s.venue ORDER BY s.venue), '[]'::json) FROM instrument_symbols_trading s WHERE s.instrument_id = i.id)) ORDER BY i.id), '[]'::json)
                            FROM instruments_trading i),
            'adapters', :adapters::json
         ) AS result",
        [':is_admin' => TradingApi::isAdmin(), ':adapters' => json_encode(array_values(array_diff(array_keys(TradingBrokers::ADAPTERS), ['paper'])))]
    );
    resultInfo(true, '', ['results' => $result]);
}

/**
 * Audit-Protokoll
 *
 * @param int    $data['limit']    Höchstzahl (optional, Standard 200)
 * @param string $data['severity'] info, warning, critical (optional)
 * @testdata {"limit": 50, "severity": "warning"}
 */
function getTradingAudit($data) {
    $db = TradingApi::db();
    $result = TradingApi::json($db,
        "SELECT COALESCE(json_agg(row_to_json(a) ORDER BY a.id DESC), '[]'::json) AS result
         FROM (SELECT * FROM audit_trading WHERE (:sev = '' OR severity = :sev2) ORDER BY id DESC LIMIT :limit) a",
        [':sev' => (string)($data['severity'] ?? ''), ':sev2' => (string)($data['severity'] ?? ''), ':limit' => max(1, min(2000, (int)($data['limit'] ?? 200)))]
    );
    resultInfo(true, '', ['results' => $result]);
}

/**
 * Tägliche Zusammenfassungen
 *
 * @param int $data['limit'] Höchstzahl (optional, Standard 30)
 * @testdata {"limit": 14}
 */
function getTradingReports($data) {
    $db = TradingApi::db();
    $result = TradingApi::json($db,
        "SELECT COALESCE(json_agg(json_build_object('day', r.day, 'data', r.data) ORDER BY r.day DESC), '[]'::json) AS result
         FROM (SELECT * FROM reports_trading ORDER BY day DESC LIMIT :limit) r",
        [':limit' => max(1, min(365, (int)($data['limit'] ?? 30)))]
    );
    resultInfo(true, '', ['results' => $result]);
}

/**
 * Not-Aus: setzt eine Handelssperre und storniert alle offenen Orders
 *
 * Darf jeder angemeldete Mitarbeiter. Bestände bleiben unangetastet. Die
 * Sperre hebt nur ein Administrator wieder auf (clearTradingLock).
 *
 * @testdata {}
 */
function triggerTradingKillSwitch($data) {
    $db = TradingApi::db();
    $canceled = TradingEngine::killSwitch($db, 'user:' . TradingApi::login());
    resultInfo(true, '', ['results' => ['canceled_orders' => $canceled]]);
}

/**
 * Hebt eine Handelssperre auf (nur Administratoren)
 *
 * @param int $data['id'] ID der Sperre
 * @testdata {"id": 1}
 */
function clearTradingLock($data) {
    $db = TradingApi::db();
    TradingApi::requireAdmin();
    $login = TradingApi::login();
    TradingGuard::asUser($db, function ($db) use ($data, $login) {
        $db->execute("UPDATE locks_trading SET cleared_at = now(), cleared_by = :login WHERE id = :id AND cleared_at IS NULL",
            [':login' => $login, ':id' => (int)($data['id'] ?? 0)]);
    });
    // Eine Fehlerserie gilt mit dem Aufheben als gesichtet
    TradingState::set($db, 'consecutive_errors', '0');
    TradingAudit::log($db, 'user:' . $login, 'lock_cleared', ['lock_id' => (int)($data['id'] ?? 0)], 'warning');
    resultInfo(true);
}

/**
 * Aktiviert den Echtgeldhandel dauerhaft (nur Administratoren)
 *
 * Die Freigabe bleibt über Neustarts und Sitzungen hinweg bestehen, bis sie
 * widerrufen wird. Voraussetzungen: ausdrückliche Bestätigung durch Eintippen
 * des Bestätigungsworts, eine aktive Live-Verbindung zu einem Broker und keine
 * aktive Handelssperre.
 *
 * @param string $data['confirmation'] Bestätigungswort, genau "ECHTGELD"
 * @testdata {"confirmation": "ECHTGELD"}
 */
function enableLiveTrading($data) {
    $db = TradingApi::db();
    TradingApi::requireAdmin();
    if (($data['confirmation'] ?? '') !== 'ECHTGELD') {
        throw new ApiError('CONFIRMATION_REQUIRED', 'Die Sicherheitsbestätigung fehlt oder ist falsch');
    }
    if (!$db->getOne("SELECT 1 AS x FROM broker_connections_trading WHERE environment = 'live' AND active LIMIT 1", [])) {
        throw new ApiError('NO_LIVE_CONNECTION', 'Es ist keine aktive Live-Verbindung zu einem Broker eingerichtet');
    }
    if (TradingRisk::activeLocks($db)) {
        throw new ApiError('TRADING_LOCKED', 'Es besteht eine Handelssperre — erst prüfen und aufheben');
    }
    $login = TradingApi::login();
    TradingGuard::asUser($db, function ($db) use ($login) {
        $db->execute("INSERT INTO live_authorizations_trading (action, login, confirmation) VALUES ('enable', :login, 'ECHTGELD')", [':login' => $login]);
    });
    TradingAudit::log($db, 'user:' . $login, 'live_trading_enabled', [], 'critical');
    resultInfo(true);
}

/**
 * Deaktiviert den Echtgeldhandel
 *
 * Stoppt die Übermittlung neuer Echtgeldorders. Bestände werden nicht
 * geschlossen; Schutzstopps beim Broker bleiben bestehen, alle übrigen offenen
 * Echtgeldorders werden storniert. Darf jeder angemeldete Mitarbeiter.
 *
 * @testdata {}
 */
function disableLiveTrading($data) {
    $db = TradingApi::db();
    $login = TradingApi::login();
    TradingGuard::asUser($db, function ($db) use ($login) {
        $db->execute("INSERT INTO live_authorizations_trading (action, login) VALUES ('disable', :login)", [':login' => $login]);
    });
    TradingAudit::log($db, 'user:' . $login, 'live_trading_disabled', [], 'critical');

    // Offene Echtgeldorders stornieren — ausser Schutzstopps, die sichern bestehende Positionen
    $canceled = 0;
    foreach (TradingPortfolio::accounts($db) as $account) {
        if ($account['kind'] !== 'live') continue;
        try {
            $broker = TradingBrokers::forAccount($db, $account, []);
            foreach ($db->getAll(
                "SELECT * FROM orders_trading WHERE account_id = :aid AND status IN ('submitting', 'submitted', 'partially_filled')
                   AND COALESCE(reason, '') <> 'protective_stop'", [':aid' => $account['id']]
            ) ?: [] as $order) {
                if (!empty($order['broker_order_id'])) {
                    $broker->cancelOrder($order);
                    TradingOrders::applyState($db, $order, $broker->getOrder($order));
                }
                $db->execute("UPDATE orders_trading SET status = 'canceled', error = 'Echtgeldhandel deaktiviert', mtime = now()
                              WHERE id = :id AND status IN ('submitting', 'submitted', 'partially_filled')", [':id' => $order['id']]);
                $canceled++;
            }
        } catch (Throwable $e) {
            TradingAudit::log($db, 'user:' . $login, 'cancel_error', ['message' => $e->getMessage()], 'critical', (int)$account['id']);
        }
    }
    resultInfo(true, '', ['results' => ['canceled_orders' => $canceled]]);
}

/**
 * Speichert Risikogrenzen (nur Administratoren)
 *
 * @param array $data['limits'] Schlüssel => Wert, nur vorhandene Schlüssel
 * @testdata {"limits": {"max_order_value": 500, "max_daily_loss_pct": 3}}
 */
function saveTradingRiskLimits($data) {
    $db = TradingApi::db();
    TradingApi::requireAdmin();
    $login = TradingApi::login();
    $limits = is_array($data['limits'] ?? null) ? $data['limits'] : [];
    foreach ($limits as $key => $value) {
        if (!is_numeric($value) || $value < 0) {
            throw new ApiError('VALIDATION_ERROR', 'Ungültiger Wert für ' . $key);
        }
    }
    TradingGuard::asUser($db, function ($db) use ($limits, $login) {
        foreach ($limits as $key => $value) {
            $db->execute("UPDATE risk_limits_trading SET value = :v, mtime = now(), modified_by = :login WHERE key = :k AND value <> :v2::numeric",
                [':v' => (string)(float)$value, ':v2' => (string)(float)$value, ':login' => $login, ':k' => (string)$key]);
        }
    });
    TradingAudit::log($db, 'user:' . $login, 'risk_limits_changed', ['limits' => $limits], 'warning');
    resultInfo(true);
}

/**
 * Speichert Einstellungen (nur Administratoren)
 *
 * @param array $data['settings'] Schlüssel => Wert, nur vorhandene Schlüssel
 * @testdata {"settings": {"research_population": 30, "research_llm_enabled": "1"}}
 */
function saveTradingSettings($data) {
    $db = TradingApi::db();
    TradingApi::requireAdmin();
    $login = TradingApi::login();
    $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
    TradingGuard::asUser($db, function ($db) use ($settings, $login) {
        foreach ($settings as $key => $value) {
            if (is_bool($value)) $value = $value ? '1' : '0';
            $db->execute(
                "UPDATE settings_trading SET value = :v, mtime = now(), modified_by = :login
                 WHERE key = :k AND value <> :v2 AND (value_type <> 'number' OR :v3 ~ '^-?[0-9]+(\\.[0-9]+)?$')",
                [':v' => (string)$value, ':v2' => (string)$value, ':v3' => (string)$value, ':login' => $login, ':k' => (string)$key]);
        }
    });
    TradingAudit::log($db, 'user:' . $login, 'settings_changed', ['settings' => $settings], 'warning');
    resultInfo(true);
}

/**
 * Legt eine Broker-Verbindung an oder ändert sie (nur Administratoren)
 *
 * Schlüssel werden verschlüsselt gespeichert und nie zurückgegeben. Leere
 * Schlüsselfelder lassen die gespeicherten Werte unverändert. Zu jeder
 * Verbindung gehört ein Konto: 'live' bei echtem Geld, sonst 'broker_paper'.
 *
 * @param int    $data['id']            ID (leer = neu)
 * @param string $data['name']          Bezeichnung
 * @param string $data['adapter']       kraken | alpaca
 * @param string $data['environment']   sandbox | live
 * @param string $data['api_key']       API-Schlüssel (optional bei Änderung)
 * @param string $data['api_secret']    API-Secret (optional bei Änderung)
 * @param float  $data['capital_limit'] Höchstbetrag, den die KI einsetzen darf
 * @param bool   $data['active']        Verbindung aktiv
 * @testdata {"name": "Alpaca Papierkonto", "adapter": "alpaca", "environment": "sandbox", "api_key": "PK...", "api_secret": "...", "capital_limit": 1000, "active": true}
 */
function saveTradingBrokerConnection($data) {
    $db = TradingApi::db();
    TradingApi::requireAdmin();
    require_once __DIR__.'/../lib/secrets.php';
    $login = TradingApi::login();

    $adapter = (string)($data['adapter'] ?? '');
    $environment = ($data['environment'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
    if (!isset(TradingBrokers::ADAPTERS[$adapter]) || $adapter === 'paper') {
        throw new ApiError('VALIDATION_ERROR', 'Unbekannter Broker');
    }
    $capital = max(0.0, (float)($data['capital_limit'] ?? 0));
    $id = (int)($data['id'] ?? 0);
    $key = trim((string)($data['api_key'] ?? ''));
    $secret = trim((string)($data['api_secret'] ?? ''));
    if ($id === 0 && ($key === '' || $secret === '')) {
        throw new ApiError('VALIDATION_ERROR', 'API-Schlüssel und Secret sind erforderlich');
    }
    $active = filter_var($data['active'] ?? true, FILTER_VALIDATE_BOOLEAN);

    $id = TradingGuard::asUser($db, function ($db) use ($id, $data, $adapter, $environment, $capital, $key, $secret, $active) {
        if ($id === 0) {
            $row = $db->getOne(
                "INSERT INTO broker_connections_trading (name, adapter, environment, api_key_enc, api_secret_enc, capital_limit, active)
                 VALUES (:name, :adapter, :env, :key, :secret, :cap, :active) RETURNING id",
                [':name' => trim((string)($data['name'] ?? $adapter)), ':adapter' => $adapter, ':env' => $environment,
                 ':key' => secretEncrypt($key), ':secret' => secretEncrypt($secret), ':cap' => TradingOrders::dec($capital), ':active' => $active]
            );
            return (int)$row['id'];
        }
        // Umgebung und Broker einer bestehenden Verbindung bleiben fest: ein Papierkonto
        // darf nicht durch eine Änderung zum Echtgeldkonto werden
        $db->execute(
            "UPDATE broker_connections_trading SET name = :name, capital_limit = :cap, active = :active, mtime = now(),
                    api_key_enc = CASE WHEN :key = '' THEN api_key_enc ELSE :key2 END,
                    api_secret_enc = CASE WHEN :secret = '' THEN api_secret_enc ELSE :secret2 END
             WHERE id = :id",
            [':name' => trim((string)($data['name'] ?? $adapter)), ':cap' => TradingOrders::dec($capital), ':active' => $active,
             ':key' => $key, ':key2' => secretEncrypt($key), ':secret' => $secret, ':secret2' => secretEncrypt($secret), ':id' => $id]
        );
        return $id;
    });

    // Konto zur Verbindung: Kapital = Höchstbetrag. Ein höheres Limit erhöht das verfügbare Guthaben um die Differenz.
    $conn = $db->getOne("SELECT * FROM broker_connections_trading WHERE id = :id", [':id' => $id]);
    $account = $db->getOne("SELECT * FROM accounts_trading WHERE broker_connection_id = :id AND closed_at IS NULL", [':id' => $id]);
    if (!$account) {
        $currency = $adapter === 'alpaca' ? 'USD' : (TradingConfig::settings($db)['account_currency'] ?? 'EUR');
        $db->execute(
            "INSERT INTO accounts_trading (kind, name, broker_connection_id, currency, start_capital, cash, peak_equity, active)
             VALUES (:kind, :name, :cid, :cur, :cap, :cap2, :cap3, :active)",
            [':kind' => $conn['environment'] === 'live' ? 'live' : 'broker_paper', ':name' => $conn['name'], ':cid' => $id, ':cur' => $currency,
             ':cap' => TradingOrders::dec($capital), ':cap2' => TradingOrders::dec($capital), ':cap3' => TradingOrders::dec($capital), ':active' => $active]
        );
    } else {
        $db->execute(
            "UPDATE accounts_trading SET name = :name, active = :active, cash = cash + (:cap::numeric - start_capital), start_capital = :cap2 WHERE id = :id",
            [':name' => $conn['name'], ':active' => $active, ':cap' => TradingOrders::dec($capital), ':cap2' => TradingOrders::dec($capital), ':id' => $account['id']]
        );
    }
    TradingAudit::log($db, 'user:' . $login, 'broker_connection_saved',
        ['id' => $id, 'adapter' => $conn['adapter'], 'environment' => $conn['environment'], 'capital_limit' => $capital, 'active' => $active], 'warning');
    resultInfo(true, '', ['results' => ['id' => $id]]);
}

/**
 * Prüft eine Broker-Verbindung: Anmeldung und Guthaben (nur Administratoren)
 *
 * Reiner Lesezugriff beim Broker, es wird keine Order aufgegeben.
 *
 * @param int $data['id'] ID der Verbindung
 * @testdata {"id": 1}
 */
function testTradingBrokerConnection($data) {
    $db = TradingApi::db();
    TradingApi::requireAdmin();
    require_once __DIR__.'/../lib/secrets.php';
    $conn = $db->getOne("SELECT * FROM broker_connections_trading WHERE id = :id", [':id' => (int)($data['id'] ?? 0)]);
    if (!$conn) throw new ApiError('DATA_NOT_FOUND', 'Verbindung nicht gefunden');
    try {
        $balances = TradingBrokers::fromConnection($conn)->getBalances();
        TradingState::set($db, 'broker_status_' . $conn['id'], 'ok ' . gmdate('c'));
        resultInfo(true, '', ['results' => ['balances' => $balances]]);
    } catch (Throwable $e) {
        TradingState::set($db, 'broker_status_' . $conn['id'], 'error ' . gmdate('c'));
        resultInfo(false, 'BROKER_CONNECTION_FAILED', $e->getMessage());
    }
}

/**
 * Schaltet ein Instrument für Forschung und Handel ein oder aus (nur Administratoren)
 *
 * @param int  $data['id']     ID des Instruments
 * @param bool $data['active'] aktiv
 * @testdata {"id": 1, "active": true}
 */
function saveTradingInstrument($data) {
    $db = TradingApi::db();
    TradingApi::requireAdmin();
    $db->execute("UPDATE instruments_trading SET active = :active, mtime = now() WHERE id = :id",
        [':active' => filter_var($data['active'] ?? true, FILTER_VALIDATE_BOOLEAN), ':id' => (int)($data['id'] ?? 0)]);
    TradingAudit::log($db, 'user:' . TradingApi::login(), 'instrument_changed', ['id' => (int)($data['id'] ?? 0), 'active' => $data['active'] ?? true]);
    resultInfo(true);
}

/**
 * Stösst einen Lauf der Engine sofort an (nur Administratoren)
 *
 * Derselbe Lauf wie per Cron. Mit research = true wird zusätzlich ein
 * Forschungszyklus erzwungen (kann einige Minuten dauern).
 *
 * @param bool $data['research'] Forschungszyklus erzwingen (optional)
 * @testdata {"research": false}
 */
function runTradingEngine($data) {
    $db = TradingApi::db();
    TradingApi::requireAdmin();
    require_once __DIR__.'/../lib/secrets.php';
    set_time_limit(600);
    $summary = TradingEngine::run($db, ['force_research' => filter_var($data['research'] ?? false, FILTER_VALIDATE_BOOLEAN)]);
    TradingAudit::log($db, 'user:' . TradingApi::login(), 'engine_run_manual', ['summary' => $summary]);
    resultInfo(true, '', ['results' => $summary]);
}
