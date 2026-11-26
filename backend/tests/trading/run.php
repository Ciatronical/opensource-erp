#!/usr/bin/env php
<?php
// backend/tests/trading/run.php

/**
 * Automatisierte Tests des Handelssystems
 *
 * Aufruf:  php backend/tests/trading/run.php [filter]
 *
 * Sicherheit: Die erste Anweisung setzt OSERP_TRADING_TEST=1. Damit verweigert
 * TradingHttp jeden Netzzugriff ohne Ersatztransport und TradingBrokers baut
 * keinen echten Adapter — diese Tests können keine Echtgeldorder auslösen,
 * auch dann nicht, wenn in der Entwicklungsumgebung echte Zugänge liegen. Sie
 * laufen in einer eigenen Wegwerf-Datenbank (oserp_trading_test), nie in einer
 * Firmen-Datenbank.
 *
 * Exit 0 = alle Tests bestanden, 1 = Fehler.
 */

putenv('OSERP_TRADING_TEST=1');
error_reporting(E_ALL & ~E_WARNING);   // logging.php meldet sonst fehlende Schreibrechte auf das API-Log
require_once __DIR__.'/bootstrap.php';
require_once dirname(__DIR__, 2).'/api/trading/trading.php';
error_reporting(E_ALL);

/**
 * Test-Broker: verhält sich wie ein echter Adapter, wird aber vom Test gesteuert
 */
class TradingMockBroker implements TradingBroker {
    public $placed = [];
    public $canceled = [];
    public $cancelAllCalls = 0;
    /** @var array [client_order_id => Zustand], den getOrder/findOrder liefern */
    public $states = [];
    /** @var callable|null fn($order) => Zustand oder Ausnahme beim Aufgeben */
    public $onPlace = null;
    public $positionQty = INF;
    public $caps = ['market', 'limit', 'stop', 'stop_limit', 'trailing_stop'];

    public function capabilities(string $venueSymbol = ''): array { return $this->caps; }
    public function getBalances(): array { return ['EUR' => 100000.0]; }
    public function getPositionQty(string $venueSymbol): float { return $this->positionQty; }
    public function getQuote(string $venueSymbol): array { return ['bid' => 100, 'ask' => 100, 'price' => 100, 'ts' => time()]; }
    public function placeOrder(array $order): array {
        $this->placed[] = $order;
        if ($this->onPlace) return ($this->onPlace)($order);
        $state = ['broker_order_id' => 'B' . $order['id'], 'status' => 'submitted', 'filled_qty' => 0.0, 'avg_price' => null, 'fee' => 0.0, 'message' => ''];
        $this->states[$order['client_order_id']] = $state;
        return $state;
    }
    public function getOrder(array $order): array { return $this->states[$order['client_order_id']]; }
    public function findOrder(array $order): ?array { return $this->states[$order['client_order_id']] ?? null; }
    public function cancelOrder(array $order): bool {
        $this->canceled[] = $order['id'];
        if (isset($this->states[$order['client_order_id']])) $this->states[$order['client_order_id']]['status'] = 'canceled';
        return true;
    }
    public function cancelAll(): int { $this->cancelAllCalls++; return 0; }
    public function getOpenOrders(): array { return []; }
    public function getOrderHistory(int $since): array { return []; }
}

/**
 * Kleiner Testrahmen und gemeinsame Hilfen
 */
class T {
    public static $db;
    public static $passed = 0;
    public static $failed = [];
    public static $current = '';
    /** @var array [venue_symbol => Kurs] für den Ersatztransport */
    public static $prices = [];
    /** @var array [venue_symbol => zusätzliche Balken] für den Ersatztransport */
    public static $newBars = [];

    public static function ok($cond, string $what): void {
        if ($cond) { self::$passed++; return; }
        self::$failed[] = self::$current . ': ' . $what;
        echo "    ✗ $what\n";
    }

    public static function eq($actual, $expected, string $what, float $tol = 1e-6): void {
        $same = (is_numeric($actual) && is_numeric($expected)) ? abs($actual - $expected) <= $tol : $actual === $expected;
        self::ok($same, $what . ' (erwartet ' . json_encode($expected) . ', erhalten ' . json_encode($actual) . ')');
    }

    public static function throws(callable $fn, string $needle, string $what): void {
        try { $fn(); self::ok(false, $what . ' (keine Ausnahme)'); }
        catch (Throwable $e) { self::ok(stripos($e->getMessage(), $needle) !== false, $what . ' (Ausnahme: ' . substr($e->getMessage(), 0, 120) . ')'); }
    }

    public static function val(string $sql, array $p = []) {
        $row = self::$db->getOne($sql, $p);
        return $row ? reset($row) : null;
    }

    /** Als Benutzer schreiben (wie die API nach bestandener Rechteprüfung) */
    public static function asUser(string $sql, array $p = []): void {
        TradingGuard::asUser(self::$db, fn($db) => $db->execute($sql, $p));
    }

    /** Wechselt auf das Handelssystem eines Mitarbeiters (1 = chef, 2 = mitarbeiter) */
    public static function user(int $employeeId): void {
        TradingScope::set(self::$db, $employeeId);
    }

    /**
     * Leert alle Bewegungsdaten; Stammdaten und Vorgaben bleiben
     *
     * Danach hat nur Mitarbeiter 1 ein Handelssystem und die Sitzung steht auf ihm —
     * die Tests arbeiten direkt auf den Tabellen, der Besitzer-Trigger trägt ihn ein.
     */
    public static function reset(): void {
        self::$db->execute("TRUNCATE orders_trading, fills_trading, positions_trading, equity_trading, allocations_trading, accounts_trading,
            backtests_trading, experiments_trading, strategy_versions_trading, strategies_trading, audit_trading, state_trading,
            reports_trading, regimes_trading, locks_trading, live_authorizations_trading, broker_connections_trading,
            users_trading, user_limits_trading, user_settings_trading RESTART IDENTITY CASCADE", []);
        self::user(1);
        self::asUser("INSERT INTO users_trading (employee_id) VALUES (1)");
        TradingBrokers::$factory = null;
        TradingHttp::$transport = null;
    }

    /**
     * Synthetische Kurse für die ersten $count Instrumente: Zufallspfad mit wechselnden Trendphasen
     *
     * @param int $days  Zahl der Tage (endet gestern)
     * @param int $count Zahl der Instrumente
     * @param int $seed  Startwert
     */
    public static function seedBars(int $days = 900, int $count = 3, int $seed = 42): void {
        self::$db->execute("TRUNCATE bars_trading", []);
        mt_srand($seed);
        $end = intdiv(time(), 86400) * 86400 - 86400;
        $ids = array_column(self::$db->getAll("SELECT id FROM instruments_trading ORDER BY id LIMIT " . $count, []), 'id');
        foreach ($ids as $n => $iid) {
            $price = 100.0 * ($n + 1);
            $drift = 0.002;
            $rows = [];
            for ($d = $days - 1; $d >= 0; $d--) {
                if (mt_rand(0, 99) < 2) $drift = (mt_rand(0, 1) ? 1 : -1) * mt_rand(5, 30) / 10000;   // Phasenwechsel
                $open = $price;
                $ret = $drift + (mt_rand(-1000, 1000) / 1000) * 0.025;
                $close = max($open * (1 + $ret), 0.5);
                $high = max($open, $close) * (1 + mt_rand(0, 100) / 10000);
                $low = min($open, $close) * (1 - mt_rand(0, 100) / 10000);
                $rows[] = sprintf("(%d, '1d', to_timestamp(%d) AT TIME ZONE 'UTC', %F, %F, %F, %F, 1000, 'test')", $iid, $end - $d * 86400, $open, $high, $low, $close);
                $price = $close;
            }
            self::$db->execute("INSERT INTO bars_trading (instrument_id, timeframe, ts, open, high, low, close, volume, source) VALUES " . implode(',', $rows), []);
        }
        mt_srand();
    }

    /** Ersatztransport für die Datenquelle: liefert T::$prices als Kurse und T::$newBars als neue Balken */
    public static function mockMarket(): void {
        $symbols = [];
        foreach (self::$db->getAll("SELECT s.venue_symbol, (SELECT close FROM bars_trading b WHERE b.instrument_id = s.instrument_id ORDER BY ts DESC LIMIT 1) AS close
                                    FROM instrument_symbols_trading s WHERE s.venue = 'binance'", []) as $r) {
            if ($r['close'] !== null && !isset(self::$prices[$r['venue_symbol']])) self::$prices[$r['venue_symbol']] = (float)$r['close'];
        }
        TradingHttp::$transport = function ($method, $url) {
            if (strpos($url, 'bookTicker') !== false) {
                $out = [];
                foreach (T::$prices as $sym => $p) $out[] = ['symbol' => $sym, 'bidPrice' => (string)($p * 0.9995), 'askPrice' => (string)($p * 1.0005)];
                return [200, json_encode($out)];
            }
            if (strpos($url, 'klines') !== false) {
                parse_str(parse_url($url, PHP_URL_QUERY), $q);
                return [200, json_encode(T::$newBars[$q['symbol']] ?? [])];
            }
            return [404, '{}'];
        };
    }

    /** Legt eine laufende Strategie samt Version an und liefert ihre ID */
    public static function strategy(string $family, array $params, string $status = 'paper'): int {
        $ids = array_column(self::$db->getAll("SELECT DISTINCT instrument_id FROM bars_trading ORDER BY 1", []), 'instrument_id');
        $s = self::$db->getOne("INSERT INTO strategies_trading (code, name, family, status, paper_since) VALUES (:c, 'Test', :f, :s, now()) RETURNING id",
            [':c' => 'T-' . mt_rand(), ':f' => $family, ':s' => $status]);
        $params = TradingStrategies::normalize($family, $params);
        $v = self::$db->getOne("INSERT INTO strategy_versions_trading (strategy_id, params, instrument_ids, params_hash) VALUES (:s, :p::jsonb, :i::int[], :h) RETURNING id",
            [':s' => $s['id'], ':p' => json_encode($params), ':i' => '{' . implode(',', $ids) . '}', ':h' => TradingStrategies::hash($family, $params, $ids)]);
        self::$db->execute("UPDATE strategies_trading SET current_version_id = :v WHERE id = :s", [':v' => $v['id'], ':s' => $s['id']]);
        return (int)$s['id'];
    }

    /** Legt ein Konto an und liefert seine Zeile (wie TradingPortfolio::accounts) */
    public static function account(string $kind, float $capital = 10000, ?int $connectionId = null): array {
        $a = self::$db->getOne("INSERT INTO accounts_trading (kind, name, broker_connection_id, start_capital, cash, peak_equity) VALUES (:k, :n, :c, :cap, :cap2, :cap3) RETURNING id",
            [':k' => $kind, ':n' => 'Test ' . $kind, ':c' => $connectionId, ':cap' => $capital, ':cap2' => $capital, ':cap3' => $capital]);
        foreach (TradingPortfolio::accounts(self::$db) as $row) if ((int)$row['id'] === (int)$a['id']) return $row;
        return self::$db->getOne("SELECT *, '' AS venue FROM accounts_trading WHERE id = :id", [':id' => $a['id']]);
    }

    /** Legt eine Broker-Verbindung an (als Benutzer) und liefert ihre ID */
    public static function connection(string $environment): int {
        return TradingGuard::asUser(self::$db, fn($db) => (int)$db->getOne(
            "INSERT INTO broker_connections_trading (name, adapter, environment, api_key_enc, api_secret_enc, capital_limit) VALUES ('Test', 'kraken', :e, 'x', 'x', 5000) RETURNING id",
            [':e' => $environment])['id']);
    }

    /** Kontext der Risikoprüfung für eine Order über $qty Stück zum Kurs $price */
    public static function ctx(array $account, float $price, array $extra = []): array {
        return $extra + ['quote' => ['price' => $price, 'bid' => $price, 'ask' => $price, 'ts' => time()], 'equity' => (float)$account['cash'],
                         'position_value' => 0.0, 'positions_value' => 0.0, 'position_qty' => 0.0, 'last_close' => $price,
                         'day_start_equity' => (float)$account['cash'], 'venue_symbol' => 'XBTEUR'];
    }

    public static function iid(): int {
        return (int)self::val("SELECT id FROM instruments_trading ORDER BY id LIMIT 1");
    }
}

$tests = [];

// ---------------------------------------------------------------------------
$tests['Indikatoren'] = function () {
    T::eq(TradingIndicators::sma([1, 2, 3, 4, 5], 3)[4], 4.0, 'SMA');
    T::ok(TradingIndicators::sma([1, 2, 3], 3)[1] === null, 'SMA ohne Vorlauf ist null');
    T::eq(TradingIndicators::ema([2, 2, 2, 2, 2], 3)[4], 2.0, 'EMA einer Konstanten');
    T::eq(TradingIndicators::rsi(range(1, 30), 14)[29], 100.0, 'RSI bei stetigem Anstieg');
    T::eq(TradingIndicators::rsi(range(30, 1), 14)[29], 0.0, 'RSI bei stetigem Fall');
    T::eq(TradingIndicators::priorMax([1, 5, 3, 2, 9], 3)[4], 5.0, 'priorMax schliesst die eigene Stelle aus');
    T::eq(TradingIndicators::stddev([2, 4, 4, 4, 5, 5, 7, 9], 8)[7], 2.13809, 'Standardabweichung', 1e-4);
};

$tests['Statistik'] = function () {
    T::eq(TradingStats::normCdf(0), 0.5, 'normCdf(0)', 1e-6);
    T::eq(TradingStats::normCdf(1.96), 0.975, 'normCdf(1.96)', 1e-3);
    T::eq(TradingStats::normInv(0.975), 1.96, 'normInv(0.975)', 1e-3);
    mt_srand(5);
    $r = [];
    for ($i = 0; $i < 500; $i++) $r[] = 0.0005 + (mt_rand(-1000, 1000) / 1000) * 0.01;
    $one = TradingStats::deflatedSharpe($r, 1, 0.0);
    $many = TradingStats::deflatedSharpe($r, 200, 1 / 500);
    T::ok($one > 0.9, 'Deflated Sharpe: ein einzelner Versuch mit Ertrag ist signifikant (' . round($one, 3) . ')');
    T::ok($many < 0.5, 'Deflated Sharpe: derselbe Ertrag als bester von 200 Versuchen ist es nicht mehr (' . round($many, 3) . ')');

    // Timing-Test: Positionen mit Vorwissen sind signifikant, zufällige nicht
    $rets = [];
    $perfect = [];
    $random = [];
    for ($t = 0; $t < 400; $t++) $rets[$t] = [1 => (mt_rand(-1000, 1000) / 1000) * 0.03];
    for ($t = 0; $t < 400; $t++) {
        $perfect[$t] = [1 => ($rets[$t + 1][1] ?? 0) > 0 ? 1.0 : 0.0];
        $random[$t] = [1 => mt_rand(0, 1) ? 1.0 : 0.0];
    }
    T::ok(TradingStats::timingPValue($perfect, $rets) < 0.02, 'Timing-Test erkennt echtes Timing');
    T::ok(TradingStats::timingPValue($random, $rets) > 0.05, 'Timing-Test verwirft Zufallspositionen');
    mt_srand();
};

$tests['Strategien: kein Blick in die Zukunft'] = function () {
    T::seedBars(500, 3);
    $panel = TradingMarketData::panel(T::$db);
    $cut = 400;
    $short = $panel;
    $short['ts'] = array_slice($panel['ts'], 0, $cut);
    foreach (['open', 'high', 'low', 'close'] as $f) foreach ($panel[$f] as $iid => $series) $short[$f][$iid] = array_slice($series, 0, $cut);
    mt_srand(3);
    foreach (array_keys(TradingStrategies::families()) as $family) {
        for ($k = 0; $k < 4; $k++) {
            $params = TradingStrategies::randomParams($family);
            $full = TradingStrategies::weights($family, $params, $panel);
            $part = TradingStrategies::weights($family, $params, $short);
            $same = true;
            for ($t = 0; $t < $cut; $t++) {
                foreach ($part[$t] as $iid => $w) if (abs($w - $full[$t][$iid]) > 1e-12) $same = false;
            }
            T::ok($same, "$family: Gewichte bis t hängen nicht von Daten nach t ab");
            $sumOk = true;
            foreach ($full as $row) if (array_sum($row) > 1.0 + 1e-9 || min($row) < 0) $sumOk = false;
            T::ok($sumOk, "$family: Gewichte liegen in 0–1 und summieren sich höchstens zu 1");
        }
    }
    $p = TradingStrategies::normalize('trend_ema', ['fast' => 999, 'slow' => -5, 'max_weight' => 'DROP TABLE']);
    T::ok($p['fast'] <= 60 && $p['slow'] > $p['fast'] && $p['max_weight'] >= 20, 'Parameter ausserhalb des Raums werden gestutzt');
    $capped = TradingStrategies::weights('trend_ema', ['fast' => 5, 'slow' => 20, 'concentrate' => 1, 'max_weight' => 100], $panel, 35.0);
    $maxW = 0;
    foreach ($capped as $row) $maxW = max($maxW, max($row));
    T::ok($maxW <= 0.35 + 1e-9, 'Positionsobergrenze der Risk-Engine gilt schon in den Zielgewichten');
    mt_srand();
};

$tests['Backtest: Kosten und Buchführung'] = function () {
    T::seedBars(300, 1);
    $panel = TradingMarketData::panel(T::$db);
    $iid = $panel['ids'][0];
    $n = count($panel['ts']);
    $hold = array_fill(0, $n, [$iid => 1.0]);
    $free = TradingBacktest::run($panel, $hold, 1, $n, ['fee_bps' => 0, 'slippage_bps' => 0, 'rebalance_threshold_pct' => 5, 'capital' => 10000]);
    $bh = TradingBacktest::buyAndHold($panel, $iid, 1, $n);
    T::eq($free['total_return'], $bh, 'Dauerhaft investiert ohne Kosten = Kaufen und Halten', 1e-9);
    T::eq($free['trades'], 1, 'Dauerhaft investiert handelt genau einmal');
    $cost = TradingBacktest::run($panel, $hold, 1, $n, ['fee_bps' => 40, 'slippage_bps' => 10, 'rebalance_threshold_pct' => 5, 'capital' => 10000]);
    T::ok($cost['total_return'] < $free['total_return'] && $cost['fees_paid'] > 39 && $cost['fees_paid'] < 41, 'Gebühr und Slippage schmälern das Ergebnis (Gebühr ' . round($cost['fees_paid'], 2) . ')');
    // Täglicher Wechsel: Kosten fressen das Konto, Guthaben wird nie negativ
    $flip = [];
    for ($t = 0; $t < $n; $t++) $flip[$t] = [$iid => $t % 2 ? 1.0 : 0.0];
    $churn = TradingBacktest::run($panel, $flip, 1, $n, ['fee_bps' => 40, 'slippage_bps' => 10, 'rebalance_threshold_pct' => 0, 'capital' => 10000]);
    T::ok($churn['trades'] > 250 && $churn['fees_paid'] > 1000, 'Häufiges Handeln kostet (' . $churn['trades'] . ' Trades, ' . round($churn['fees_paid']) . ' Gebühr)');
    T::ok(min($churn['equity']) > 0, 'Kontowert bleibt positiv');
    $flat = TradingBacktest::run($panel, array_fill(0, $n, [$iid => 0.0]), 1, $n, ['fee_bps' => 40, 'slippage_bps' => 10, 'capital' => 10000]);
    T::eq($flat['total_return'], 0.0, 'Ohne Position kein Ergebnis');
};

$tests['Schema: Wiederholbar und geschützt'] = function () {
    T::reset();
    TradingTestDb::applySchema(T::$db);
    T::eq((int)T::val("SELECT count(*) FROM risk_limits_trading"), 11, 'Grenzen nach wiederholtem Update unverändert vorhanden');
    T::asUser("UPDATE risk_limits_trading SET value = 777 WHERE key = 'max_order_value'");
    TradingTestDb::applySchema(T::$db);
    T::eq((float)T::val("SELECT value FROM risk_limits_trading WHERE key = 'max_order_value'"), 777.0, 'Ein Update überschreibt geänderte Grenzen nicht');
    T::asUser("UPDATE risk_limits_trading SET value = 1000 WHERE key = 'max_order_value'");

    // Die Engine (ohne Benutzerfreigabe) kann die Sicherheitstabellen nicht verändern
    T::throws(fn() => T::$db->execute("UPDATE risk_limits_trading SET value = 9999999 WHERE key = 'max_order_value'", []), 'TRADING_GUARD', 'Engine kann Grenzen nicht ändern');
    T::throws(fn() => T::$db->execute("DELETE FROM risk_limits_trading", []), 'TRADING_GUARD', 'Engine kann Grenzen nicht löschen');
    T::throws(fn() => T::$db->execute("UPDATE settings_trading SET value = '0' WHERE key = 'gate_min_test_sharpe'", []), 'TRADING_GUARD', 'Engine kann Übernahmekriterien nicht ändern');
    T::throws(fn() => T::$db->execute("INSERT INTO live_authorizations_trading (action, login) VALUES ('enable', 'engine')", []), 'TRADING_GUARD', 'Engine kann Echtgeld nicht freigeben');
    T::throws(fn() => T::$db->execute("INSERT INTO broker_connections_trading (name, adapter, environment) VALUES ('x', 'kraken', 'live')", []), 'TRADING_GUARD', 'Engine kann keine Broker-Verbindung anlegen');
    TradingRisk::lock(T::$db, 'all', 'test', 'Test');
    T::throws(fn() => T::$db->execute("UPDATE locks_trading SET cleared_at = now()", []), 'TRADING_GUARD', 'Engine kann Sperren nicht aufheben');
    T::throws(fn() => T::$db->execute("DELETE FROM locks_trading", []), 'TRADING_GUARD', 'Engine kann Sperren nicht löschen');
    T::eq(count(TradingRisk::activeLocks(T::$db)), 1, 'Sperre besteht weiter');
    // Die Freigabe gilt nur innerhalb der Transaktion
    T::asUser("UPDATE locks_trading SET cleared_at = now(), cleared_by = 'admin'");
    T::throws(fn() => T::$db->execute("UPDATE risk_limits_trading SET value = 1 WHERE key = 'max_order_value'", []), 'TRADING_GUARD', 'Benutzerfreigabe wirkt nicht über die Transaktion hinaus');
};

$tests['Testmodus: kein Netz, kein echter Broker'] = function () {
    T::reset();
    T::throws(fn() => TradingHttp::request('GET', 'https://api.kraken.com/0/public/Time'), 'Testmodus', 'HTTP ohne Ersatztransport ist gesperrt');
    $cid = T::connection('live');
    $live = T::account('live', 5000, $cid);
    T::throws(fn() => TradingBrokers::forAccount(T::$db, $live), 'Testmodus', 'Echter Adapter wird im Testmodus nicht gebaut');
    T::ok(TradingBrokers::forAccount(T::$db, T::account('portfolio_paper')) instanceof TradingPaperBroker, 'Papierkonto bekommt den Simulator');
};

$tests['Marktdaten: fehlerhafte Balken'] = function () {
    T::reset();
    T::seedBars(60, 1);
    $good = ['ts' => 1, 'open' => 100, 'high' => 110, 'low' => 95, 'close' => 105, 'volume' => 5];
    T::ok(TradingMarketData::validateBar($good, 100.0) === null, 'Plausibler Balken wird angenommen');
    T::ok(TradingMarketData::validateBar(['close' => 0] + $good, null) !== null, 'Kurs 0 wird verworfen');
    T::ok(TradingMarketData::validateBar(['high' => 90] + $good, null) !== null, 'Hoch unter Tief wird verworfen');
    T::ok(TradingMarketData::validateBar(['close' => 200, 'high' => 110] + $good, null) !== null, 'Schluss ausserhalb der Spanne wird verworfen');
    T::ok(TradingMarketData::validateBar($good, 1000.0) !== null, 'Kurssturz um 90 % zum Vortag wird verworfen');
    T::ok(TradingMarketData::validateBar(['close' => NAN] + $good, null) !== null, 'NaN wird verworfen');

    // Über den Abruf: ein kaputter und ein guter neuer Balken
    T::$db->execute("DELETE FROM bars_trading WHERE ts >= (SELECT max(ts) - interval '1 day' FROM bars_trading)", []);
    $last = (int)T::val("SELECT EXTRACT(EPOCH FROM max(ts))::bigint FROM bars_trading");
    $close = (float)T::val("SELECT close FROM bars_trading ORDER BY ts DESC LIMIT 1");
    $bar = fn($ts, $c) => [$ts * 1000, (string)$c, (string)($c * 1.01), (string)($c * 0.99), (string)$c, '10', ($ts + 86400) * 1000 - 1];
    T::$newBars = ['BTCEUR' => [$bar($last + 86400, $close * 0.05), $bar($last + 2 * 86400, $close * 1.01)]];
    T::$prices = [];
    T::mockMarket();
    $before = (int)T::val("SELECT count(*) FROM bars_trading");
    $r = TradingMarketData::update(T::$db);
    T::eq($r['rejected'], 1, 'Abruf verwirft den fehlerhaften Balken');
    T::eq((int)T::val("SELECT count(*) FROM bars_trading") - $before, 1, 'Abruf speichert nur den plausiblen Balken');
    T::eq((int)T::val("SELECT count(*) FROM audit_trading WHERE event = 'bar_rejected'"), 1, 'Verworfener Balken steht im Audit-Protokoll');
    T::$newBars = [];
};

$tests['Orders: Risikoprüfung'] = function () {
    T::reset();
    $acc = T::account('portfolio_paper', 10000);
    $iid = T::iid();
    $broker = new TradingMockBroker();
    $try = function (string $key, float $qty, array $ctx, string $side = 'buy') use (&$acc, $iid, $broker) {
        $o = TradingOrders::create(T::$db, $acc, $iid, $side, 'market', $qty, $key);
        return TradingOrders::submit(T::$db, $acc, $o, $broker, $ctx);
    };
    $o = $try('ok', 10, T::ctx($acc, 100));
    T::eq($o['status'], 'submitted', 'Zulässige Order wird übermittelt');
    T::ok(strpos($try('big', 50, T::ctx($acc, 100))['error'], 'position_size') !== false, 'Zu grosse Position wird abgelehnt (max_position_pct)');
    T::ok(strpos($try('stale', 10, ['quote' => ['price' => 100, 'ts' => time() - 7200]] + T::ctx($acc, 100))['error'], 'price_fresh') !== false, 'Veralteter Kurs wird abgelehnt');
    T::ok(strpos($try('dev', 10, ['last_close' => 60] + T::ctx($acc, 100))['error'], 'price_plausible') !== false, 'Kurs weit weg vom letzten Schluss wird abgelehnt');
    T::ok(strpos($try('zero', 10, ['quote' => ['price' => 0, 'ts' => time()]] + T::ctx($acc, 100))['error'], 'price_valid') !== false, 'Kurs 0 wird abgelehnt');
    T::ok(strpos($try('loss', 10, ['equity' => 9000, 'day_start_equity' => 10000] + T::ctx($acc, 100))['error'], 'daily_loss') !== false, 'Nach Tagesverlust keine Käufe mehr');
    T::ok(strpos($try('nopos', 5, T::ctx($acc, 100), 'sell')['error'], 'position_available') !== false, 'Verkauf ohne Bestand wird abgelehnt');
    T::ok(strpos($try('cash', 30, ['equity' => 100000] + T::ctx($acc, 400))['error'], 'cash_available') !== false, 'Kauf über dem Guthaben wird abgelehnt');
    T::eq(count($broker->placed), 1, 'Abgelehnte Orders erreichen den Broker nicht');

    T::asUser("UPDATE risk_limits_trading SET value = 2 WHERE key = 'max_open_orders'");
    $try('open2', 5, T::ctx($acc, 100));
    T::ok(strpos($try('open3', 5, T::ctx($acc, 100))['error'], 'open_orders') !== false, 'Zu viele offene Orders werden abgelehnt');
    T::asUser("UPDATE risk_limits_trading SET value = 20 WHERE key = 'max_open_orders'");

    TradingRisk::lock(T::$db, 'all', 'test', 'Test');
    T::ok(strpos($try('locked', 10, T::ctx($acc, 100))['error'], 'trading_allowed') !== false, 'Bei Handelssperre geht keine Order hinaus');
    $broker->caps = ['market'];
    T::asUser("UPDATE locks_trading SET cleared_at = now()");
    $o = TradingOrders::create(T::$db, $acc, $iid, 'buy', 'trailing_stop', 1, 'caps', ['trail_percent' => 5]);
    T::eq(TradingOrders::submit(T::$db, $acc, $o, $broker, T::ctx($acc, 100))['error'], 'order_type_unsupported', 'Nicht unterstützte Orderart wird abgelehnt');
};

$tests['Orders: doppelte Orders, Teilausführung, Verbindungsabbruch, Neustart'] = function () {
    T::reset();
    $cid = T::connection('sandbox');
    $acc = T::account('broker_paper', 10000, $cid);
    $iid = T::iid();
    $broker = new TradingMockBroker();

    // Dieselbe Entscheidung zweimal → eine Order
    $o1 = TradingOrders::create(T::$db, $acc, $iid, 'buy', 'market', 10, '20260101');
    $o2 = TradingOrders::create(T::$db, $acc, $iid, 'buy', 'market', 10, '20260101');
    T::ok($o1 !== null && $o2 === null, 'Zweite Order zur selben Entscheidung wird verhindert');
    T::eq((int)T::val("SELECT count(*) FROM audit_trading WHERE event = 'order_duplicate_blocked'"), 1, 'Verhindertes Duplikat steht im Audit-Protokoll');

    // Teilausführung in zwei Schritten, zweite Meldung doppelt
    $o1 = TradingOrders::submit(T::$db, $acc, $o1, $broker, T::ctx($acc, 100));
    $cidKey = $o1['client_order_id'];
    $broker->states[$cidKey] = ['broker_order_id' => 'B1', 'status' => 'partially_filled', 'filled_qty' => 4.0, 'avg_price' => 100.0, 'fee' => 1.6, 'message' => ''];
    TradingOrders::sync(T::$db, $acc, $broker);
    T::eq((float)T::val("SELECT qty FROM positions_trading WHERE account_id = :a", [':a' => $acc['id']]), 4.0, 'Teilausführung: Bestand 4');
    T::eq((float)T::val("SELECT cash FROM accounts_trading WHERE id = :a", [':a' => $acc['id']]), 10000 - 400 - 1.6, 'Teilausführung: Guthaben um Wert und Gebühr vermindert');
    T::eq(T::val("SELECT status FROM orders_trading WHERE id = :o", [':o' => $o1['id']]), 'partially_filled', 'Status teilweise ausgeführt');
    TradingOrders::sync(T::$db, $acc, $broker);   // dieselbe Meldung noch einmal
    T::eq((float)T::val("SELECT qty FROM positions_trading WHERE account_id = :a", [':a' => $acc['id']]), 4.0, 'Doppelt gemeldete Teilausführung zählt einmal');
    $broker->states[$cidKey] = ['broker_order_id' => 'B1', 'status' => 'filled', 'filled_qty' => 10.0, 'avg_price' => 101.2, 'fee' => 4.05, 'message' => ''];
    TradingOrders::sync(T::$db, $acc, $broker);
    TradingOrders::sync(T::$db, $acc, $broker);
    T::eq((float)T::val("SELECT qty FROM positions_trading WHERE account_id = :a", [':a' => $acc['id']]), 10.0, 'Vollständig ausgeführt: Bestand 10');
    T::eq((float)T::val("SELECT cash FROM accounts_trading WHERE id = :a", [':a' => $acc['id']]), 10000 - 1012 - 4.05, 'Guthaben entspricht kumuliertem Wert und Gebühr', 1e-4);
    T::eq((int)T::val("SELECT count(*) FROM fills_trading WHERE order_id = :o", [':o' => $o1['id']]), 2, 'Zwei Ausführungen verbucht');
    T::eq((float)T::val("SELECT price FROM fills_trading WHERE order_id = :o ORDER BY id DESC LIMIT 1", [':o' => $o1['id']]), 102.0, 'Kurs der zweiten Teilausführung aus der Differenz', 1e-6);
    // Mehr gemeldet als bestellt → gedeckelt
    $broker->states[$cidKey]['filled_qty'] = 15.0;
    TradingOrders::applyState(T::$db, $o1, $broker->states[$cidKey]);
    T::eq((float)T::val("SELECT qty FROM positions_trading WHERE account_id = :a", [':a' => $acc['id']]), 10.0, 'Übermeldung des Brokers wird nicht verbucht');

    // Verbindungsabbruch beim Aufgeben: Order ist beim Broker angekommen
    $broker->onPlace = function ($order) use ($broker) {
        $broker->states[$order['client_order_id']] = ['broker_order_id' => 'B2', 'status' => 'submitted', 'filled_qty' => 0.0, 'avg_price' => null, 'fee' => 0.0, 'message' => ''];
        throw new TradingHttpUncertain('Zeitüberschreitung');
    };
    $o3 = TradingOrders::submit(T::$db, $acc, TradingOrders::create(T::$db, $acc, $iid, 'buy', 'market', 5, '20260102'), $broker, T::ctx($acc, 100));
    T::eq($o3['status'], 'submitting', 'Unklare Antwort: Order bleibt in Übermittlung');
    $placedBefore = count($broker->placed);
    TradingOrders::sync(T::$db, $acc, $broker);
    T::eq(T::val("SELECT broker_order_id FROM orders_trading WHERE id = :o", [':o' => $o3['id']]), 'B2', 'Abgleich findet die angekommene Order über unsere Kennung');
    T::eq(count($broker->placed), $placedBefore, 'Kein zweites Aufgeben nach Verbindungsabbruch');

    // Verbindungsabbruch, Order ist NICHT angekommen
    $broker->onPlace = function () { throw new TradingHttpUncertain('Verbindung abgerissen'); };
    $o4 = TradingOrders::submit(T::$db, $acc, TradingOrders::create(T::$db, $acc, $iid, 'buy', 'market', 5, '20260103'), $broker, T::ctx($acc, 100));
    TradingOrders::sync(T::$db, $acc, $broker);
    T::eq(T::val("SELECT status FROM orders_trading WHERE id = :o", [':o' => $o4['id']]), 'submitting', 'Kurz nach dem Absenden wird noch gewartet');
    T::$db->execute("UPDATE orders_trading SET submitted_at = now() - interval '10 minutes' WHERE id = :o", [':o' => $o4['id']]);
    TradingOrders::sync(T::$db, $acc, $broker);
    T::eq(T::val("SELECT status FROM orders_trading WHERE id = :o", [':o' => $o4['id']]), 'rejected', 'Nicht angekommene Order wird als abgelehnt geführt, nicht wiederholt');

    // Serverneustart: eine Order blieb als 'submitting' liegen, der Prozess ist neu
    $broker->onPlace = null;
    $o5 = TradingOrders::create(T::$db, $acc, $iid, 'buy', 'market', 2, '20260104');
    T::$db->execute("UPDATE orders_trading SET status = 'submitting', submitted_at = now() - interval '1 hour' WHERE id = :o", [':o' => $o5['id']]);
    $fresh = new TradingMockBroker();   // „neuer Prozess“
    $fresh->states = $broker->states;   // der Broker selbst hat nichts vergessen
    $fresh->states[$o5['client_order_id']] = ['broker_order_id' => 'B5', 'status' => 'filled', 'filled_qty' => 2.0, 'avg_price' => 100.0, 'fee' => 0.8, 'message' => ''];
    TradingOrders::sync(T::$db, $acc, $fresh);
    T::eq(T::val("SELECT status FROM orders_trading WHERE id = :o", [':o' => $o5['id']]), 'filled', 'Nach Neustart wird die liegengebliebene Order abgeglichen');
    T::eq(count($fresh->placed), 0, 'Nach Neustart wird nichts erneut aufgegeben');
    T::eq(TradingOrders::create(T::$db, $acc, $iid, 'buy', 'market', 2, '20260104'), null, 'Dieselbe Entscheidung erzeugt auch nach Neustart keine zweite Order');

    // Fehler des Brokers: Order im Fehlerzustand, Zähler läuft, nach drei Fehlern Sperre
    $broker->onPlace = function () { throw new RuntimeException('EGeneral:Internal error'); };
    foreach (['e1', 'e2', 'e3'] as $k) {
        TradingOrders::submit(T::$db, $acc, TradingOrders::create(T::$db, $acc, $iid, 'buy', 'market', 1, $k), $broker, T::ctx($acc, 100));
    }
    T::eq((int)T::val("SELECT count(*) FROM orders_trading WHERE status = 'error'"), 3, 'Broker-Fehler führen in den Fehlerzustand');
    T::eq((int)T::val("SELECT count(*) FROM locks_trading WHERE cleared_at IS NULL AND reason_code = 'critical_errors'"), 1, 'Drei kritische Fehler in Folge lösen die automatische Handelssperre aus');
};

$tests['Simulator: Orderarten'] = function () {
    T::reset();
    $acc = T::account('portfolio_paper', 10000);
    $iid = T::iid();
    T::asUser("UPDATE risk_limits_trading SET value = 100 WHERE key = 'max_position_pct'");
    $q = fn($p) => [$iid => ['bid' => $p, 'ask' => $p, 'price' => $p, 'ts' => time()]];
    $place = function (string $side, string $type, float $qty, float $price, array $opts, string $key) use ($acc, $iid, $q) {
        $o = TradingOrders::create(T::$db, $acc, $iid, $side, $type, $qty, $key, $opts);
        $pos = (float)(T::val("SELECT qty FROM positions_trading WHERE account_id = :a", [':a' => $acc['id']]) ?? 0);
        return TradingOrders::submit(T::$db, $acc, $o, new TradingPaperBroker(T::$db, $q($price)), T::ctx($acc, $price, ['position_qty' => $pos]));
    };
    $sync = fn($price) => TradingOrders::sync(T::$db, $acc, new TradingPaperBroker(T::$db, $q($price)));
    $status = fn($o) => T::val("SELECT status FROM orders_trading WHERE id = :o", [':o' => $o['id']]);

    $m = $place('buy', 'market', 10, 100, [], 'm');
    T::eq($m['status'], 'filled', 'Marktorder wird sofort ausgeführt');
    T::eq((float)$m['avg_fill_price'], 100.1, 'Marktorder mit Slippage (10 Basispunkte)', 1e-6);
    T::eq((float)$m['fees'], 10 * 100.1 * 0.004, 'Gebühr 40 Basispunkte', 1e-6);

    $l = $place('buy', 'limit', 5, 100, ['limit_price' => 95], 'l');
    T::eq($l['status'], 'submitted', 'Limitorder über dem Markt bleibt offen');
    $sync(97);
    T::eq($status($l), 'submitted', 'Limit nicht erreicht');
    $sync(94);
    T::eq($status($l), 'filled', 'Limit erreicht → ausgeführt');
    T::eq((float)T::val("SELECT avg_fill_price FROM orders_trading WHERE id = :o", [':o' => $l['id']]), 95.0, 'Limitorder zum Limitkurs');

    $s = $place('sell', 'stop', 5, 100, ['stop_price' => 90], 's');
    $sync(92);
    T::eq($status($s), 'submitted', 'Stop nicht ausgelöst');
    $sync(89);
    T::eq($status($s), 'filled', 'Stop ausgelöst');

    $sl = $place('sell', 'stop_limit', 2, 100, ['stop_price' => 90, 'limit_price' => 88], 'sl');
    $sync(85);
    T::eq($status($sl), 'submitted', 'Stop-Limit: unter dem Limit keine Ausführung');
    $sync(89);
    T::eq($status($sl), 'filled', 'Stop-Limit: im Bereich ausgeführt');

    $tr = $place('sell', 'trailing_stop', 3, 100, ['trail_percent' => 10], 'tr');
    $sync(120);
    T::eq($status($tr), 'submitted', 'Trailing-Stop zieht mit dem Kurs nach oben');
    $sync(111);
    T::eq($status($tr), 'submitted', 'Trailing-Stop: Rücksetzer unter 10 % löst nicht aus');
    $sync(107);
    T::eq($status($tr), 'filled', 'Trailing-Stop: 10 % unter dem Höchstkurs ausgelöst');
    T::eq((float)T::val("SELECT qty FROM positions_trading WHERE account_id = :a", [':a' => $acc['id']]), 5.0, 'Bestand nach allen Ausführungen');
    T::asUser("UPDATE risk_limits_trading SET value = 35 WHERE key = 'max_position_pct'");
};

$tests['Echtgeld: Freigabe, Sperren, Not-Aus'] = function () {
    T::reset();
    T::seedBars(120, 2);
    $cid = T::connection('live');
    $live = T::account('live', 5000, $cid);
    $iid = T::iid();
    $broker = new TradingMockBroker();
    TradingBrokers::$factory = fn($account) => $broker;

    $o = TradingOrders::submit(T::$db, $live, TradingOrders::create(T::$db, $live, $iid, 'buy', 'market', 1, 'a'), $broker, T::ctx($live, 100));
    T::eq($o['status'], 'risk_rejected', 'Ohne Freigabe geht keine Echtgeldorder hinaus');
    T::eq(count($broker->placed), 0, 'Broker wurde nicht angesprochen');
    T::eq(T::val("WITH s AS (" . TradingReport::summarySql() . ") SELECT summary->>'mode' FROM s"), 'simulation', 'Handelsmodus: Simulation');

    T::asUser("INSERT INTO live_authorizations_trading (action, login, confirmation) VALUES ('enable', 'admin', 'ECHTGELD')");
    T::ok(TradingRisk::liveAuthorized(T::$db), 'Freigabe ist gespeichert (dauerhaft, über Neustarts)');
    T::eq(T::val("WITH s AS (" . TradingReport::summarySql() . ") SELECT summary->>'mode' FROM s"), 'live', 'Handelsmodus: Echtgeld');
    $o = TradingOrders::submit(T::$db, $live, TradingOrders::create(T::$db, $live, $iid, 'buy', 'market', 1, 'b'), $broker, T::ctx($live, 100));
    T::eq($o['status'], 'submitted', 'Mit Freigabe wird übermittelt');
    $o = TradingOrders::submit(T::$db, $live, TradingOrders::create(T::$db, $live, $iid, 'buy', 'market', 12, 'c'), $broker, T::ctx($live, 100));
    T::ok(strpos($o['error'], 'order_value') !== false, 'Echtgeld: maximales Ordervolumen greift');

    // Not-Aus
    $n = TradingEngine::killSwitch(T::$db, 'user:test');
    T::eq($n, 1, 'Not-Aus storniert die offene Order');
    T::eq($broker->cancelAllCalls, 1, 'Not-Aus storniert zusätzlich alles Offene beim Broker');
    T::eq(T::val("WITH s AS (" . TradingReport::summarySql() . ") SELECT summary->>'mode' FROM s"), 'halted', 'Handelsmodus: angehalten');
    $o = TradingOrders::submit(T::$db, $live, TradingOrders::create(T::$db, $live, $iid, 'buy', 'market', 1, 'd'), $broker, T::ctx($live, 100));
    T::eq($o['status'], 'risk_rejected', 'Nach Not-Aus geht keine Order hinaus');
    // Die Engine läuft weiter, hebt die Sperre aber nicht auf
    T::mockMarket();
    TradingEngine::run(T::$db, ['skip_research' => true, 'skip_report' => true]);
    TradingEngine::run(T::$db, ['skip_research' => true, 'skip_report' => true]);
    T::eq(count(TradingRisk::activeLocks(T::$db)), 1, 'Engine-Läufe heben die Sperre nicht auf');
    T::eq(count($broker->placed), 1, 'Während der Sperre gibt die Engine nichts auf');

    // Widerruf der Freigabe
    T::asUser("UPDATE locks_trading SET cleared_at = now()");
    T::asUser("INSERT INTO live_authorizations_trading (action, login) VALUES ('disable', 'admin')");
    $o = TradingOrders::submit(T::$db, $live, TradingOrders::create(T::$db, $live, $iid, 'buy', 'market', 1, 'e'), $broker, T::ctx($live, 100));
    T::eq($o['status'], 'risk_rejected', 'Nach Deaktivierung geht keine Echtgeldorder hinaus');

    // Portfolioverlust → Sperre nur für Echtgeld
    T::asUser("INSERT INTO live_authorizations_trading (action, login) VALUES ('enable', 'admin')");
    T::$db->execute("UPDATE accounts_trading SET peak_equity = 5000 WHERE id = :a", [':a' => $live['id']]);
    T::$db->execute("INSERT INTO equity_trading (account_id, day, equity, cash) VALUES (:a, CURRENT_DATE, 3900, 3900)
                     ON CONFLICT (account_id, day) DO UPDATE SET equity = 3900", [':a' => $live['id']]);
    TradingRisk::monitor(T::$db);
    T::eq(T::val("SELECT scope || '/' || reason_code FROM locks_trading WHERE cleared_at IS NULL"), 'live/drawdown', 'Portfolioverlust über der Grenze sperrt den Echtgeldhandel');
    T::ok(TradingRisk::tradingBlocked(T::$db, T::account('portfolio_paper')) === null, 'Papierhandel läuft bei Echtgeldsperre weiter');
};

$tests['Engine: Papierhandel von Ende zu Ende'] = function () {
    T::reset();
    T::seedBars(400, 3);
    T::$prices = [];
    T::mockMarket();
    // Zeitreihen-Momentum mit Schwelle 0 und kurzem Rückblick: ist fast immer irgendwo investiert
    $sid = T::strategy('tsmom', ['lookback' => 10, 'threshold' => 0, 'max_weight' => 100, 'concentrate' => 1]);
    T::$db->execute("INSERT INTO backtests_trading (strategy_version_id, segment, from_ts, to_ts, sharpe, max_drawdown, equity_curve)
                     SELECT current_version_id, s.seg, now(), now(), 1.0, 0.2, '[100,101,102,101,103,104]'::jsonb
                     FROM strategies_trading, (VALUES ('test'), ('full')) AS s (seg) WHERE id = :s", [':s' => $sid]);

    $r1 = TradingEngine::run(T::$db, ['skip_research' => true, 'skip_report' => true]);
    T::eq($r1['errors'], [], 'Lauf ohne Fehler');
    $accounts = (int)T::val("SELECT count(*) FROM accounts_trading");
    T::eq($accounts, 2, 'Gesamtportfolio und Testkonto der Strategie wurden angelegt');
    $orders1 = (int)T::val("SELECT count(*) FROM orders_trading WHERE status = 'filled'");
    T::ok($orders1 > 0, "Erster Lauf handelt ($orders1 Orders ausgeführt)");
    T::eq((int)T::val("SELECT count(*) FROM orders_trading WHERE status NOT IN ('filled')"), 0, 'Alle Orders ausgeführt, keine abgelehnt');
    T::eq((float)T::val("SELECT weight FROM allocations_trading"), 1.0, 'Eine Strategie bekommt das ganze Kapital des Gesamtportfolios');
    $check = T::$db->getAll("SELECT a.id, a.cash, a.start_capital, COALESCE((SELECT sum(o.fees) FROM orders_trading o WHERE o.account_id = a.id), 0) AS fees,
                                    (SELECT e.equity FROM equity_trading e WHERE e.account_id = a.id) AS equity,
                                    (SELECT max(p.qty * 1) FROM positions_trading p WHERE p.account_id = a.id) AS anypos
                             FROM accounts_trading a", []);
    foreach ($check as $a) {
        T::ok($a['cash'] >= 0, 'Guthaben nicht negativ (Konto ' . $a['id'] . ')');
        T::ok($a['equity'] !== null && abs($a['equity'] - $a['start_capital']) < $a['start_capital'] * 0.02, 'Kontowert nach Einstieg nahe am Startkapital (Kosten) — Konto ' . $a['id']);
        T::ok($a['equity'] < $a['start_capital'], 'Gebühren und Slippage sind im Kontowert enthalten — Konto ' . $a['id']);
    }
    $maxPos = (float)T::val("SELECT max(p.qty * b.close / e.equity) FROM positions_trading p
                             JOIN equity_trading e ON e.account_id = p.account_id
                             JOIN LATERAL (SELECT close FROM bars_trading WHERE instrument_id = p.instrument_id ORDER BY ts DESC LIMIT 1) b ON true");
    T::ok($maxPos <= 0.36, 'Keine Position über der Risikogrenze von 35 % (' . round($maxPos * 100, 1) . ' %)');

    // Zweiter Lauf am selben Tag: nichts Neues
    $r2 = TradingEngine::run(T::$db, ['skip_research' => true, 'skip_report' => true]);
    T::eq($r2['orders'], 0, 'Zweiter Lauf zum selben Balken handelt nicht erneut');
    T::eq((int)T::val("SELECT count(*) FROM orders_trading"), $orders1, 'Orderzahl unverändert');

    // Absturz simulieren: Signalstand zurückdrehen → dieselben Entscheidungen, aber keine doppelten Orders
    T::$db->execute("UPDATE accounts_trading SET last_signal_ts = NULL", []);
    TradingEngine::run(T::$db, ['skip_research' => true, 'skip_report' => true]);
    $dupes = (int)T::val("SELECT count(*) FROM (SELECT account_id, instrument_id, side FROM orders_trading GROUP BY 1, 2, 3 HAVING count(*) > 1) x");
    T::eq($dupes, 0, 'Wiederholung nach Absturz erzeugt keine doppelten Orders');

    // Bericht und Kennzahlen
    $report = TradingReport::generate(T::$db);
    T::ok($report['summary']['has_data'] && $report['summary']['mode'] === 'simulation', 'Tagesbericht: Modus Simulation, Daten vorhanden');
    T::eq((float)$report['summary']['start_capital'], 10000.0, 'Tagesbericht: virtuelles Startkapital');
    T::eq((float)$report['summary']['portfolio_value'], (float)T::val("SELECT e.equity FROM equity_trading e JOIN accounts_trading a ON a.id = e.account_id WHERE a.kind = 'portfolio_paper'"), 'Tagesbericht: Portfoliowert = gespeicherter Kontostand');
    T::eq((int)$report['summary']['active_strategies'], 1, 'Tagesbericht: aktive Strategien');
    T::ok(isset($report['summary']['benchmark']['symbol']), 'Tagesbericht: Vergleichsmaßstab vorhanden');

    // Ausmustern: Verlust im Vorwärtstest über der Grenze → Strategie raus, Konto wird geräumt
    T::$db->execute("UPDATE accounts_trading SET peak_equity = 20000 WHERE strategy_id = :s", [':s' => $sid]);
    TradingState::set(T::$db, 'lifecycle_day', '');
    T::$db->execute("UPDATE accounts_trading SET last_signal_ts = last_signal_ts - interval '1 day'", []);
    TradingEngine::run(T::$db, ['skip_research' => true, 'skip_report' => true]);
    T::eq(T::val("SELECT status FROM strategies_trading WHERE id = :s", [':s' => $sid]), 'retired', 'Strategie mit zu hohem Verlust wird ausgemustert');
    T::eq((int)T::val("SELECT count(*) FROM positions_trading WHERE qty > 0"), 0, 'Bestände der ausgemusterten Strategie werden verkauft (auch im Gesamtportfolio)');
    T::eq((int)T::val("SELECT count(*) FROM audit_trading WHERE event = 'strategy_retired'"), 1, 'Ausmusterung steht im Audit-Protokoll');
};

$tests['Lebenszyklus: Bewährung vor Echtgeld'] = function () {
    T::reset();
    T::seedBars(120, 2);
    $sid = T::strategy('trend_ema', ['fast' => 5, 'slow' => 20]);
    TradingPortfolio::ensureAccounts(T::$db);
    $aid = (int)T::val("SELECT id FROM accounts_trading WHERE strategy_id = :s", [':s' => $sid]);
    T::$db->execute("INSERT INTO equity_trading (account_id, day, equity, cash) VALUES (:a, CURRENT_DATE, 10400, 10400)", [':a' => $aid]);
    T::$db->execute("UPDATE accounts_trading SET peak_equity = 10500 WHERE id = :a", [':a' => $aid]);
    $status = fn() => T::val("SELECT status FROM strategies_trading WHERE id = :s", [':s' => $sid]);

    TradingResearch::lifecycle(T::$db);
    T::eq($status(), 'paper', 'Frisch im Vorwärtstest: noch nicht freigegeben');
    T::$db->execute("UPDATE strategies_trading SET paper_since = now() - interval '40 days' WHERE id = :s", [':s' => $sid]);
    TradingResearch::lifecycle(T::$db);
    T::eq($status(), 'paper', 'Genug Tage, aber zu wenige Trades: noch nicht freigegeben');
    $iid = T::iid();
    foreach (['1', '2', '3'] as $k) {
        T::$db->execute("INSERT INTO orders_trading (account_id, instrument_id, client_order_id, side, qty, status) VALUES (:a, :i, :c, 'buy', 1, 'filled')",
            [':a' => $aid, ':i' => $iid, ':c' => 'lc-' . $k]);
    }
    TradingResearch::lifecycle(T::$db);
    T::eq($status(), 'live_eligible', 'Tage, Trades und Ergebnis erfüllt: für Echtgeld zugelassen');

    // Echtgeld-Konten handeln nur zugelassene Strategien
    $panel = TradingMarketData::panel(T::$db);
    $other = T::strategy('tsmom', ['lookback' => 10, 'threshold' => 0]);
    T::$db->execute("INSERT INTO allocations_trading (day, strategy_id, weight) VALUES (CURRENT_DATE, :a, 0.5), (CURRENT_DATE, :b, 0.5)", [':a' => $sid, ':b' => $other]);
    $cid = T::connection('live');
    $live = T::account('live', 5000, $cid);
    $members = array_column(TradingPortfolio::members(T::$db, ['live_eligible', 'live']), 'id');
    T::eq(array_map('intval', $members), [$sid], 'Nur die bewährte Strategie zählt für Brokerkonten');
    T::$db->execute("UPDATE strategies_trading SET status = 'paper' WHERE id = :s", [':s' => $sid]);
    T::eq(TradingPortfolio::targetWeights(T::$db, $live, $panel), [], 'Ohne bewährte Strategie hat das Echtgeldkonto kein Ziel (bleibt in Geld)');

    $w = TradingPortfolio::capWeights(['a' => 10, 'b' => 1, 'c' => 1], 0.40);
    T::eq($w['a'], 0.40, 'Kapitalverteilung: Obergrenze je Strategie');
    T::eq(array_sum($w), 1.0, 'Kapitalverteilung: Summe 1');
};

$tests['Schutzstopp beim Broker'] = function () {
    T::reset();
    $cid = T::connection('sandbox');
    $acc = T::account('broker_paper', 10000, $cid);
    $iid = T::iid();
    $broker = new TradingMockBroker();
    T::$db->execute("INSERT INTO positions_trading (account_id, instrument_id, qty, avg_price) VALUES (:a, :i, 2, 100)", [':a' => $acc['id'], ':i' => $iid]);
    $quotes = [$iid => ['bid' => 105, 'ask' => 105, 'price' => 105, 'ts' => time()]];
    TradingEngine::protectiveStops(T::$db, $acc, $broker, $quotes);
    $stop = T::$db->getOne("SELECT * FROM orders_trading WHERE reason = 'protective_stop'", []);
    T::ok($stop && $stop['status'] === 'submitted' && $stop['side'] === 'sell', 'Für den Bestand liegt ein Stopp beim Broker');
    T::eq((float)$stop['stop_price'], 75.0, 'Stopp 25 % unter dem Einstand');
    T::eq((float)$stop['qty'], 2.0, 'Stopp über den ganzen Bestand');
    sleep(1);
    TradingEngine::protectiveStops(T::$db, $acc, $broker, $quotes);
    T::eq((int)T::val("SELECT count(*) FROM orders_trading"), 1, 'Passender Stopp wird nicht doppelt gelegt');
    T::$db->execute("UPDATE positions_trading SET qty = 3", []);
    TradingEngine::protectiveStops(T::$db, $acc, $broker, $quotes);
    T::eq(count($broker->canceled), 1, 'Geänderter Bestand: alter Stopp wird storniert');
    T::eq((float)T::val("SELECT qty FROM orders_trading WHERE status = 'submitted'"), 3.0, 'Geänderter Bestand: neuer Stopp über die neue Menge');
};

$tests['Broker-Adapter: Kraken und Alpaca (HTTP nachgestellt)'] = function () {
    T::reset();
    // Signatur gegen das Beispiel aus der Kraken-Dokumentation
    $sig = TradingKrakenBroker::sign('/0/private/AddOrder', '1616492376594',
        'nonce=1616492376594&ordertype=limit&pair=XBTUSD&price=37500&type=buy&volume=1.25',
        'kQH5HW/8p1uGOVjbgWA7FunAmGO8lsSUXNsu3eow76sz84Q18fWxnyRzBHCd3pd5nE9qa99HAZtuZuj6F1huXg==');
    T::eq($sig, '4/dpxb3iT4tp/ZCVEwSnEsLxx0bqyhLpdfOpc6fn7OR8+UClSV5n9E6aSS8MPtnRfp32bAb0nmbRn6H8ndwLUQ==', 'Kraken-Signatur entspricht dem dokumentierten Beispiel');
    T::eq(TradingKrakenBroker::assetName('XXBT'), 'BTC', 'Kraken-Assetcode XXBT → BTC');
    T::eq(TradingKrakenBroker::assetName('ZEUR'), 'EUR', 'Kraken-Assetcode ZEUR → EUR');
    T::eq(TradingKrakenBroker::assetName('SOL'), 'SOL', 'Kraken-Assetcode SOL bleibt');

    $calls = [];
    TradingHttp::$transport = function ($method, $url, $headers, $body) use (&$calls) {
        $calls[] = [$method, $url, $headers, $body];
        if (strpos($url, 'AddOrder') !== false) {
            parse_str($body, $p);
            return [200, json_encode(['error' => [], 'result' => ['descr' => ['order' => 'buy'], 'txid' => isset($p['validate']) ? [] : ['OABC-1']]])];
        }
        if (strpos($url, 'QueryOrders') !== false) return [200, json_encode(['error' => [], 'result' => ['OABC-1' => ['status' => 'open', 'vol' => '2', 'vol_exec' => '0.5', 'price' => '100', 'fee' => '0.2']]])];
        if (strpos($url, 'Balance') !== false) return [200, json_encode(['error' => ['EAPI:Invalid key'], 'result' => []])];
        if (strpos($url, '/v2/orders') !== false && $method === 'POST') return [200, json_encode(['id' => 'al-1', 'status' => 'accepted', 'filled_qty' => '0', 'qty' => '2'])];
        if (strpos($url, 'by_client_order_id') !== false) return [404, '{}'];
        return [500, '{}'];
    };
    $order = ['id' => 77, 'client_order_id' => 'oserp-1-1-x-b', 'venue_symbol' => 'XBTEUR', 'side' => 'buy', 'order_type' => 'stop_limit',
              'qty' => 0.5, 'limit_price' => 101, 'stop_price' => 100, 'trail_percent' => null];

    $sandbox = new TradingKrakenBroker('key', base64_encode('secret'), false);
    $state = $sandbox->placeOrder($order);
    parse_str(end($calls)[3], $sent);
    T::eq($sent['validate'] ?? '', 'true', 'Kraken-Testumgebung: Order geht nur zur Prüfung hinaus (validate)');
    T::eq($state['status'], 'canceled', 'Kraken-Testumgebung: es entsteht keine Order');
    T::eq([$sent['ordertype'], $sent['price'], $sent['price2'], $sent['userref']], ['stop-loss-limit', '100', '101', '77'], 'Kraken: Stop-Limit richtig übersetzt, unsere Kennung als userref');
    T::ok((bool)array_filter(end($calls)[2], fn($h) => strpos($h, 'API-Sign: ') === 0), 'Kraken: Aufruf ist signiert');

    $live = new TradingKrakenBroker('key', base64_encode('secret'), true);
    $state = $live->placeOrder($order);
    parse_str(end($calls)[3], $sent);
    T::ok(!isset($sent['validate']) && $state['broker_order_id'] === 'OABC-1', 'Kraken live: ohne validate, Order-ID übernommen');
    $state = $live->getOrder(['broker_order_id' => 'OABC-1']);
    T::eq([$state['status'], $state['filled_qty']], ['partially_filled', 0.5], 'Kraken: offene Order mit Teilmenge = teilweise ausgeführt');
    T::throws(fn() => $live->getBalances(), 'Invalid key', 'Kraken: Fehler der API werden gemeldet');

    $alpaca = new TradingAlpacaBroker('k', 's', false);
    $state = $alpaca->placeOrder(['venue_symbol' => 'SPY', 'order_type' => 'trailing_stop', 'trail_percent' => 5] + $order);
    $sent = json_decode(end($calls)[3], true);
    T::ok(strpos(end($calls)[1], 'https://paper-api.alpaca.markets/v2/orders') === 0, 'Alpaca-Testumgebung nutzt paper-api');
    T::eq([$sent['type'], $sent['trail_percent'], $sent['client_order_id']], ['trailing_stop', '5', 'oserp-1-1-x-b'], 'Alpaca: Trailing-Stop und client_order_id richtig übergeben');
    T::eq($state['broker_order_id'], 'al-1', 'Alpaca: Order-ID übernommen');
    T::throws(fn() => $alpaca->placeOrder(['venue_symbol' => 'BTC/USD', 'order_type' => 'trailing_stop'] + $order), 'Krypto', 'Alpaca: nicht angebotene Orderart für Krypto wird nicht erfunden');
    T::eq($alpaca->capabilities('BTC/USD'), ['market', 'limit', 'stop_limit'], 'Alpaca: Fähigkeiten je Anlageklasse');
    T::ok($alpaca->findOrder($order) === null, 'Alpaca: unbekannte client_order_id → nicht angekommen');
};

$tests['Forschungszyklus'] = function () {
    T::reset();
    T::seedBars(900, 3, 11);
    T::mockMarket();
    T::asUser("UPDATE settings_trading SET value = '12' WHERE key = 'research_population'");
    $panel = TradingMarketData::panel(T::$db);
    $split = TradingResearch::splits($panel, TradingConfig::settings(T::$db));
    T::ok($split['start'] < $split['train_end'] && $split['train_end'] < $split['val_end'] && $split['val_end'] < $split['end'], 'Abschnitte Training < Validierung < Test, zeitlich getrennt');

    mt_srand(21);
    $r = TradingResearch::cycle(T::$db, $panel);
    T::ok($r['tested'] > 0, 'Zyklus prüft Kandidaten (' . $r['tested'] . ')');
    T::eq((int)T::val("SELECT count(*) FROM experiments_trading WHERE status = 'finished'"), 1, 'Experiment ist protokolliert');
    T::eq((int)T::val("SELECT count(*) FROM strategies_trading WHERE status = 'candidate'"), 0, 'Jeder Kandidat hat ein Urteil mit Begründung');
    T::eq((int)T::val("SELECT count(*) FROM strategies_trading WHERE status_reason IS NULL OR status_reason = ''"), 0, 'Kein Urteil ohne Begründung');
    $tests = (int)T::val("SELECT count(*) FROM backtests_trading WHERE segment = 'test'");
    T::ok($tests <= 3, "Testdaten höchstens für die drei Besten angefasst ($tests)");
    T::eq((int)T::val("SELECT count(*) FROM backtests_trading b WHERE segment = 'test' AND NOT EXISTS
                         (SELECT 1 FROM backtests_trading v WHERE v.strategy_version_id = b.strategy_version_id AND v.segment = 'validation' AND v.sharpe >= 0.5)"), 0,
          'Testdaten nur nach bestandener Validierung');
    T::eq((int)T::val("SELECT count(*) FROM strategies_trading s WHERE s.status IN ('paper', 'queued') AND NOT EXISTS
                         (SELECT 1 FROM backtests_trading b WHERE b.strategy_version_id = s.current_version_id AND b.segment = 'test' AND b.passed)"), 0,
          'In den Vorwärtstest kommt nur, wer die Endprüfung bestanden hat');
    T::eq((int)T::val("SELECT count(*) FROM backtests_trading WHERE segment = 'train' AND to_ts > (SELECT min(from_ts) FROM backtests_trading WHERE segment = 'validation')"), 0, 'Training endet vor der Validierung');
    T::eq((int)T::val("SELECT count(*) FROM backtests_trading WHERE segment = 'validation' AND to_ts > (SELECT min(from_ts) FROM backtests_trading WHERE segment = 'test')"), 0, 'Validierung endet vor den Testdaten');

    // Derselbe Zufall noch einmal: nichts wird doppelt geprüft
    $versions = (int)T::val("SELECT count(*) FROM strategy_versions_trading");
    mt_srand(21);
    $r2 = TradingResearch::cycle(T::$db, $panel);
    T::eq((int)T::val("SELECT count(*) FROM strategy_versions_trading WHERE version = 1") - $versions, $r2['tested'], 'Gezählt wird nur, was neu geprüft wurde');
    T::eq((int)T::val("SELECT count(*) FROM (SELECT params_hash FROM strategy_versions_trading GROUP BY 1 HAVING count(*) > 1) x"), 0, 'Keine Variante wird zweimal getestet');

    // KI-Vorschläge: nur innerhalb des Parameterraums, und nur als Kandidat
    T::asUser("UPDATE settings_trading SET value = '1' WHERE key = 'research_llm_enabled'");
    T::$db->execute("INSERT INTO defaults_oserp (key, value) VALUES ('anthropic_api_key', 'test'), ('ai_external_enabled', '1') ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value", []);
    TradingHttp::$transport = function ($method, $url) {
        if (strpos($url, 'anthropic') === false) return [200, '[]'];
        return [200, json_encode(['content' => [['type' => 'text', 'text' => json_encode(['ideas' => [
            ['family' => 'trend_ema', 'params' => ['fast' => 100000, 'slow' => -1, 'max_weight' => 5000], 'hypothesis' => 'Test'],
            ['family' => 'gibt_es_nicht', 'params' => ['x' => 1], 'hypothesis' => 'Unbekannte Familie'],
            ['family' => 'donchian', 'params' => 'rm -rf', 'hypothesis' => 'Kein Parameterobjekt'],
        ]])]]])];
    };
    $ideas = TradingResearch::llmIdeas(T::$db, []);
    T::eq(count($ideas), 1, 'KI-Vorschläge mit unbekannter Familie oder kaputten Parametern werden verworfen');
    $norm = TradingStrategies::normalize($ideas[0]['family'], $ideas[0]['params']);
    T::ok($norm['fast'] <= 60 && $norm['max_weight'] <= 100, 'KI-Vorschlag wird auf den Parameterraum gestutzt');
    $gen = TradingResearch::generate(T::$db, 6, TradingConfig::settings(T::$db), $panel);
    T::ok((bool)array_filter($gen, fn($c) => $c['origin'] === 'llm'), 'KI-Vorschlag läuft als gewöhnlicher Kandidat durch dieselben Prüfungen');
    T::asUser("UPDATE settings_trading SET value = '0' WHERE key = 'research_llm_enabled'");
    T::asUser("UPDATE settings_trading SET value = '24' WHERE key = 'research_population'");
    mt_srand();
};

$tests['Benutzertrennung in der Datenbank'] = function () {
    T::reset();
    T::seedBars(400, 3);
    T::$prices = [];
    T::mockMarket();
    T::user(2);
    T::asUser("INSERT INTO users_trading (employee_id) VALUES (2)");
    T::user(1);
    $sid = T::strategy('tsmom', ['lookback' => 10, 'threshold' => 0]);
    T::eq((int)T::val("SELECT employee_id FROM strategies_trading WHERE id = :s", [':s' => $sid]), 1, 'Neue Zeilen bekommen den Mitarbeiter der Sitzung als Besitzer');
    T::eq((int)T::val("SELECT count(*) FROM my_strategies_trading"), 1, 'Besitzer sieht seine Strategie');

    T::user(2);
    T::eq((int)T::val("SELECT count(*) FROM my_strategies_trading"), 0, 'Anderer Mitarbeiter sieht sie nicht');
    T::eq((int)T::val("SELECT count(*) FROM my_strategy_versions_trading"), 0, 'Auch nicht ihre Versionen');
    T::$db->execute("UPDATE my_strategies_trading SET status = 'live' WHERE id = :s", [':s' => $sid]);
    T::$db->execute("DELETE FROM my_strategies_trading WHERE id = :s", [':s' => $sid]);
    T::eq(T::val("SELECT status FROM strategies_trading WHERE id = :s", [':s' => $sid]), 'paper', 'Fremde Zeilen lassen sich über die Sicht weder ändern noch löschen');
    T::throws(fn() => T::$db->execute("INSERT INTO my_locks_trading (employee_id, scope, reason_code, set_by) VALUES (1, 'all', 'x', 'test')", []),
        'check option', 'Über die Sicht lässt sich nichts für einen anderen Mitarbeiter anlegen');
    T::throws(fn() => T::$db->execute("UPDATE strategies_trading SET employee_id = 2 WHERE id = :s", [':s' => $sid]), 'TRADING_OWNER', 'Der Besitzer einer Zeile ist unveränderlich');

    // Geschützte Tabellen: auch mit Benutzerfreigabe nur die eigenen Zeilen
    T::user(1);
    TradingRisk::lock(T::$db, 'all', 'kill_switch', 'Test', 'user:chef');
    T::asUser("INSERT INTO user_limits_trading (employee_id, key, value) VALUES (1, 'max_order_value', 250)");
    T::user(2);
    T::throws(fn() => T::asUser("UPDATE locks_trading SET cleared_at = now()"), 'TRADING_GUARD', 'Fremde Sperre lässt sich nicht aufheben');
    T::throws(fn() => T::asUser("UPDATE user_limits_trading SET value = 999999"), 'TRADING_GUARD', 'Fremde Grenze lässt sich nicht ändern');
    T::throws(fn() => T::asUser("INSERT INTO live_authorizations_trading (employee_id, action, login) VALUES (1, 'enable', 'mitarbeiter')"), 'TRADING_GUARD', 'Echtgeldfreigabe für einen anderen lässt sich nicht eintragen');
    T::eq(TradingRisk::tradingBlocked(T::$db, ['kind' => 'portfolio_paper']), null, 'Die Sperre des einen hält den anderen nicht an');
    T::eq(TradingConfig::limits(T::$db)['max_order_value'], 1000.0, 'Eigene Grenze des einen ändert die des anderen nicht (Vorgabe)');
    T::user(1);
    T::eq(TradingConfig::limits(T::$db)['max_order_value'], 250.0, 'Wirksam ist die eigene Grenze');
    T::ok(TradingRisk::tradingBlocked(T::$db, ['kind' => 'portfolio_paper']) !== null, 'Sperre gilt für ihren Besitzer');
    T::asUser("UPDATE locks_trading SET cleared_at = now(), cleared_by = 'chef' WHERE employee_id = 1");

    // Ohne Mitarbeiter in der Sitzung: leer und kein Einfügen
    T::user(0);
    T::eq((int)T::val("SELECT count(*) FROM my_strategies_trading"), 0, 'Ohne Mitarbeiter in der Sitzung sind die Sichten leer');
    T::throws(fn() => T::$db->execute("INSERT INTO accounts_trading (kind, name, start_capital, cash) VALUES ('portfolio_paper', 'x', 1, 1)", []),
        'TRADING_OWNER', 'Ohne Mitarbeiter entsteht kein herrenloses Konto');
    T::throws(fn() => TradingState::set(T::$db, 'x', '1'), 'kein Mitarbeiter', 'Benutzerzustand verlangt einen Mitarbeiter');

    // Engine: ein Lauf bearbeitet beide Systeme getrennt
    T::user(1);
    $run = TradingEngine::run(T::$db, ['skip_research' => true]);
    T::eq(array_keys($run['users']), [1, 2], 'Engine bearbeitet jeden Mitarbeiter mit Handelssystem');
    T::eq((int)TradingScope::id(T::$db), 1, 'Nach dem Lauf steht die Sitzung wieder auf dem Aufrufer');
    T::eq((int)T::val("SELECT count(*) FROM accounts_trading WHERE kind = 'portfolio_paper' AND employee_id = 1"), 1, 'Virtuelles Portfolio für Mitarbeiter 1');
    T::eq((int)T::val("SELECT count(*) FROM accounts_trading WHERE kind = 'portfolio_paper' AND employee_id = 2"), 1, 'Eigenes virtuelles Portfolio für Mitarbeiter 2');
    T::eq((int)T::val("SELECT count(*) FROM accounts_trading WHERE kind = 'strategy_paper' AND employee_id = 2"), 0, 'Mitarbeiter 2 bekommt kein Testkonto für die fremde Strategie');
    T::eq((int)T::val("SELECT count(*) FROM orders_trading o JOIN accounts_trading a ON a.id = o.account_id WHERE o.employee_id <> a.employee_id"), 0, 'Jede Order gehört dem Besitzer ihres Kontos');
    T::ok((int)T::val("SELECT count(*) FROM orders_trading WHERE employee_id = 1") > 0 && (int)T::val("SELECT count(*) FROM orders_trading WHERE employee_id = 2") === 0,
        'Gehandelt wird nur im System mit Strategie');
    T::eq((int)T::val("SELECT count(*) FROM state_trading WHERE key LIKE 'u2:%'") > 0, true, 'Laufzeitzustand je Mitarbeiter');
    $only = TradingEngine::run(T::$db, ['skip_research' => true, 'only_user' => 2]);
    T::eq(array_keys($only['users']), [2], 'Lauf nur für einen Mitarbeiter');
    T::$db->execute("UPDATE employee SET deleted = true WHERE id = 2", []);
    T::eq(array_keys(TradingEngine::run(T::$db, ['skip_research' => true])['users']), [1], 'Gelöschte Mitarbeiter werden nicht mehr bearbeitet');
    T::$db->execute("UPDATE employee SET deleted = false WHERE id = 2", []);
};

$tests['API: Abfragen, Rechte, Freigabe'] = function () {
    T::reset();
    T::seedBars(400, 3);
    T::$prices = [];
    T::mockMarket();
    $sid = T::strategy('tsmom', ['lookback' => 10, 'threshold' => 0]);
    T::$db->execute("INSERT INTO backtests_trading (strategy_version_id, segment, from_ts, to_ts, sharpe, total_return, max_drawdown, equity_curve)
                     SELECT current_version_id, s.seg, now() - interval '100 days', now(), 1.0, 0.2, 0.2, '[100,101,102,101,103,104]'::jsonb
                     FROM strategies_trading, (VALUES ('train'), ('validation'), ('test'), ('full')) AS s (seg) WHERE id = :s", [':s' => $sid]);
    TradingEngine::run(T::$db, ['force_research' => true]);

    // Verbindung der Tests als Firmen-Datenbank der API
    $prop = (new ReflectionClass('DbhCompany'))->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, T::$db);
    $call = function (string $action, array $data = []) {
        ob_start();
        try {
            $action($data);
            $out = json_decode(ob_get_clean(), true);
        } catch (ApiError $e) {
            ob_end_clean();
            $out = ['success' => false, 'text' => $e->getId()];
        }
        return $out;
    };
    // Anmelden: Login, Administratorrecht und — wie DbhCompany je Request — der Mitarbeiter der DB-Sitzung
    $chef = function () { TradingApi::$testUser = ['login' => 'chef', 'admin' => true]; T::user(1); };
    $mitarbeiter = function () { TradingApi::$testUser = ['login' => 'mitarbeiter', 'admin' => false]; T::user(2); };

    $chef();
    $dash = $call('getTradingDashboard', ['days' => 90]);
    T::ok($dash['success'] && $dash['payload']['results']['summary']['has_data'], 'Dashboard liefert Kennzahlen');
    T::eq((float)$dash['payload']['results']['summary']['start_capital'], 10000.0, 'Dashboard: virtuelles Startkapital aus der Datenbank');
    T::ok(count($dash['payload']['results']['equity_curve']) >= 1 && count($dash['payload']['results']['strategies']) === 1, 'Dashboard: Kapitalkurve und Strategien');
    T::ok($dash['payload']['results']['report'] !== null, 'Dashboard: Tagesbericht');
    foreach (['getTradingSummary' => [], 'getTradingStrategies' => ['scope' => 'all'], 'getTradingStrategy' => ['id' => $sid], 'getTradingExperiments' => [],
              'getTradingOrders' => ['limit' => 10], 'getTradingControl' => [], 'getTradingAudit' => ['severity' => 'warning'], 'getTradingReports' => []] as $action => $data) {
        $r = $call($action, $data);
        T::ok($r['success'] && array_key_exists('results', $r['payload']), "$action antwortet");
    }
    $control = $call('getTradingControl')['payload']['results'];
    T::ok(strpos(json_encode($control), 'api_key_enc') === false && strpos(json_encode($control), 'api_secret') === false, 'Schlüssel verlassen den Server nicht');
    $detail = $call('getTradingStrategy', ['id' => $sid])['payload']['results'];
    T::eq(count($detail['versions'][0]['backtests']), 4, 'Strategie-Detail: Backtests je Abschnitt');
    $chefOrders = count($call('getTradingOrders', ['limit' => 100])['payload']['results']);
    T::ok($chefOrders > 0, 'Orders des eigenen Systems');

    // Zweiter Mitarbeiter: eigenes, leeres System — nichts vom ersten
    $mitarbeiter();
    T::eq((int)T::val("SELECT count(*) FROM users_trading WHERE employee_id = 2"), 0, 'Vor dem ersten Öffnen kein Handelssystem');
    T::ok($call('getTradingSummary')['success'], 'Kachel antwortet auch ohne eigenes System');
    T::eq((int)T::val("SELECT count(*) FROM users_trading WHERE employee_id = 2"), 0, 'Die Kachel richtet kein System ein');
    $own = $call('getTradingDashboard', ['days' => 90]);
    T::ok($own['success'], 'Erstes Öffnen der Seite');
    T::eq((int)T::val("SELECT count(*) FROM users_trading WHERE employee_id = 2"), 1, 'Erstes Öffnen richtet das eigene System ein');
    T::eq((float)T::val("SELECT start_capital FROM accounts_trading WHERE employee_id = 2 AND kind = 'portfolio_paper'"), 10000.0, 'Eigenes virtuelles Portfolio');
    T::eq(count($own['payload']['results']['strategies']), 0, 'Dashboard: keine fremden Strategien');
    T::eq($own['payload']['results']['report'], null, 'Dashboard: kein fremder Tagesbericht');
    T::eq($own['payload']['results']['research']['tested'], 0, 'Dashboard: keine fremde Forschung');
    T::eq($call('getTradingStrategy', ['id' => $sid])['payload']['results']['strategy'], null, 'Fremde Strategie ist nicht abrufbar');
    T::eq(count($call('getTradingStrategy', ['id' => $sid])['payload']['results']['versions']), 0, 'Fremde Versionen sind nicht abrufbar');
    T::eq(count($call('getTradingStrategies', ['scope' => 'all'])['payload']['results']), 0, 'Liste: keine fremden Strategien');
    T::eq(count($call('getTradingOrders', ['limit' => 100])['payload']['results']), 0, 'Keine fremden Orders');
    T::eq(count($call('getTradingExperiments')['payload']['results']['experiments']), 0, 'Keine fremden Experimente');
    T::eq(count($call('getTradingReports')['payload']['results']), 0, 'Keine fremden Berichte');
    T::eq(count(array_filter($call('getTradingAudit', ['limit' => 2000])['payload']['results'], fn($a) => (int)$a['employee_id'] !== 2)), 0, 'Protokoll: nur eigene Einträge');
    T::eq(count($call('getTradingControl')['payload']['results']['accounts']), 1, 'Konten: nur das eigene');

    // Firmenweites bleibt Administratoren vorbehalten
    T::eq($call('saveTradingInstrument', ['id' => 1, 'active' => false])['text'] ?? '', 'NO_PERMISSION', 'Instrumente nur für Administratoren');
    T::eq($call('saveTradingSettings', ['settings' => ['market_data_source' => 'kraken']])['text'] ?? '', 'NO_PERMISSION', 'Datenquelle der Kurse nur für Administratoren');
    T::eq(T::val("SELECT value FROM settings_trading WHERE key = 'market_data_source'"), 'binance', 'Datenquelle blieb unverändert');

    // Eigene Grenzen und Einstellungen wirken nur im eigenen System
    T::ok($call('saveTradingRiskLimits', ['limits' => ['max_order_value' => 500]])['success'], 'Mitarbeiter ändert seine eigene Grenze');
    T::eq(TradingConfig::limits(T::$db)['max_order_value'], 500.0, 'Eigene Grenze gespeichert');
    T::eq(T::val("SELECT modified_by FROM user_limits_trading WHERE employee_id = 2 AND key = 'max_order_value'"), 'mitarbeiter', 'Änderung ist dem Benutzer zugeordnet');
    T::eq((float)T::val("SELECT value FROM risk_limits_trading WHERE key = 'max_order_value'"), 1000.0, 'Vorgabe der Firma blieb unverändert');
    T::eq($call('saveTradingRiskLimits', ['limits' => ['max_order_value' => -5]])['text'], 'VALIDATION_ERROR', 'Negative Grenze wird abgelehnt');
    T::ok($call('saveTradingRiskLimits', ['limits' => ['gibt_es_nicht' => 5]])['success'] && (int)T::val("SELECT count(*) FROM user_limits_trading WHERE key = 'gibt_es_nicht'") === 0, 'Unbekannte Grenzen werden nicht angelegt');
    T::ok($call('saveTradingSettings', ['settings' => ['research_population' => 30, 'fee_bps' => 'abc']])['success'], 'Einstellungen speichern');
    $mine = TradingConfig::settings(T::$db);
    T::eq($mine['research_population'], '30', 'Eigene Einstellung gespeichert');
    T::eq($mine['fee_bps'], '40', 'Nichtnumerischer Wert für eine Zahl wird verworfen');
    $chef();
    T::eq(TradingConfig::limits(T::$db)['max_order_value'], 1000.0, 'Grenze des anderen Mitarbeiters blieb unverändert');
    T::eq(TradingConfig::settings(T::$db)['research_population'], '24', 'Einstellung des anderen Mitarbeiters blieb unverändert');

    // Not-Aus und Sperren: je Mitarbeiter
    $mitarbeiter();
    T::ok($call('triggerTradingKillSwitch')['success'], 'Not-Aus im eigenen System');
    T::eq($call('getTradingSummary')['payload']['results']['mode'], 'halted', 'Nach Not-Aus: Modus angehalten');
    $lockId = (int)T::val("SELECT id FROM locks_trading WHERE cleared_at IS NULL AND employee_id = 2");
    $chef();
    T::eq($call('getTradingSummary')['payload']['results']['mode'], 'simulation', 'Not-Aus des einen hält das System des anderen nicht an');
    T::ok($call('clearTradingLock', ['id' => $lockId])['success'] && T::val("SELECT cleared_at FROM locks_trading WHERE id = :id", [':id' => $lockId]) === null,
        'Fremde Sperre lässt sich nicht aufheben');

    // Echtgeldfreigabe: je Mitarbeiter, mit Bestätigung, Live-Verbindung und ohne Sperre
    $mitarbeiter();
    T::eq($call('enableLiveTrading', ['confirmation' => 'ja'])['text'], 'CONFIRMATION_REQUIRED', 'Freigabe ohne Bestätigungswort wird abgelehnt');
    T::eq($call('enableLiveTrading', ['confirmation' => 'ECHTGELD'])['text'], 'NO_LIVE_CONNECTION', 'Freigabe ohne Live-Verbindung wird abgelehnt');
    $conn = $call('saveTradingBrokerConnection', ['name' => 'Kraken', 'adapter' => 'kraken', 'environment' => 'live', 'api_key' => 'KEY123', 'api_secret' => 'SECRET456', 'capital_limit' => 2000]);
    T::ok($conn['success'], 'Broker-Verbindung anlegen');
    $row = T::$db->getOne("SELECT * FROM broker_connections_trading", []);
    T::eq((int)$row['employee_id'], 2, 'Verbindung gehört dem Mitarbeiter, der sie anlegt');
    T::ok($row['api_secret_enc'] !== 'SECRET456' && strpos($row['api_secret_enc'], 'SECRET') === false && secretDecrypt($row['api_secret_enc']) === 'SECRET456', 'Secret liegt verschlüsselt in der Datenbank');
    T::eq(T::val("SELECT kind || '/' || start_capital::int || '/' || employee_id FROM accounts_trading WHERE broker_connection_id = :c", [':c' => $row['id']]), 'live/2000/2', 'Zur Verbindung gehört ein eigenes Echtgeldkonto mit dem Höchstbetrag als Kapital');
    T::eq($call('enableLiveTrading', ['confirmation' => 'ECHTGELD'])['text'], 'TRADING_LOCKED', 'Freigabe bei aktiver Sperre wird abgelehnt');
    T::ok($call('clearTradingLock', ['id' => $lockId])['success'] && T::val("SELECT cleared_by FROM locks_trading WHERE id = :id", [':id' => $lockId]) === 'mitarbeiter', 'Besitzer hebt seine Sperre auf');
    $chef();
    T::eq($call('enableLiveTrading', ['confirmation' => 'ECHTGELD'])['text'], 'NO_LIVE_CONNECTION', 'Die Live-Verbindung eines anderen genügt nicht für die eigene Freigabe');
    T::eq($call('saveTradingBrokerConnection', ['id' => (int)$row['id'], 'name' => 'gekapert', 'adapter' => 'kraken', 'capital_limit' => 999999])['text'] ?? '', 'DATA_NOT_FOUND', 'Fremde Verbindung lässt sich nicht ändern');
    T::eq(T::val("SELECT name || '/' || capital_limit::int FROM broker_connections_trading WHERE id = :id", [':id' => $row['id']]), 'Kraken/2000', 'Fremde Verbindung blieb unverändert');
    T::eq($call('testTradingBrokerConnection', ['id' => (int)$row['id']])['text'] ?? '', 'DATA_NOT_FOUND', 'Fremde Verbindung lässt sich nicht benutzen');
    T::eq(count($call('getTradingControl')['payload']['results']['connections']), 0, 'Fremde Verbindungen sind nicht sichtbar');
    $mitarbeiter();
    T::ok($call('enableLiveTrading', ['confirmation' => 'ECHTGELD'])['success'], 'Freigabe mit Bestätigung, Verbindung und ohne Sperre');
    T::eq($call('getTradingSummary')['payload']['results']['mode'], 'live', 'Modus: Echtgeld');
    T::eq(T::val("SELECT login || '/' || employee_id FROM live_authorizations_trading ORDER BY id DESC LIMIT 1"), 'mitarbeiter/2', 'Freigabe ist mit Benutzer protokolliert');
    $chef();
    T::ok(!TradingRisk::liveAuthorized(T::$db), 'Die Freigabe des einen gilt nicht für den anderen');
    T::eq($call('getTradingSummary')['payload']['results']['mode'], 'simulation', 'Anderer Mitarbeiter bleibt in der Simulation');
    T::ok($call('disableLiveTrading')['success'], 'Deaktivieren im eigenen System');
    $mitarbeiter();
    T::ok(TradingRisk::liveAuthorized(T::$db), 'Deaktivieren des einen widerruft nicht die Freigabe des anderen');
    T::ok($call('disableLiveTrading')['success'], 'Besitzer deaktiviert den Echtgeldhandel');
    T::eq($call('getTradingSummary')['payload']['results']['mode'], 'simulation', 'Modus wieder Simulation');

    // Eigener Lauf: nur das eigene System
    $before = (int)T::val("SELECT count(*) FROM audit_trading WHERE employee_id = 1");
    $run = $call('runTradingEngine', ['research' => false]);
    T::ok($run['success'] && array_keys($run['payload']['results']['users']) === [2], 'Lauf anstossen bearbeitet nur das eigene System');
    T::eq((int)T::val("SELECT count(*) FROM audit_trading WHERE employee_id = 1"), $before, 'Der Lauf schreibt nichts in das System des anderen');

    // Administrator: firmenweite Einstellung
    $chef();
    T::ok($call('saveTradingSettings', ['settings' => ['market_data_source' => 'kraken']])['success'], 'Administrator ändert die Datenquelle der Kurse');
    T::eq(T::val("SELECT value FROM settings_trading WHERE key = 'market_data_source'"), 'kraken', 'Datenquelle gespeichert');
    $mitarbeiter();
    T::eq(TradingConfig::settings(T::$db)['market_data_source'], 'kraken', 'Datenquelle gilt für alle Mitarbeiter');
    T::asUser("UPDATE settings_trading SET value = 'binance' WHERE key = 'market_data_source'");

    // Anmeldung ohne Mitarbeiter in der Firma
    T::user(0);
    T::eq($call('getTradingSummary')['text'] ?? '', 'NO_EMPLOYEE', 'Ohne Mitarbeiter in der Firma antwortet die API nicht');
    $chef();
    T::$db->execute("UPDATE extensions_oserp SET active = false WHERE extension = 'trading'", []);
    T::eq($call('getTradingSummary')['text'] ?? '', 'EXTENSION_INACTIVE', 'Ohne aktive Erweiterung antwortet die API nicht');
    T::$db->execute("UPDATE extensions_oserp SET active = true WHERE extension = 'trading'", []);
    TradingApi::$testUser = null;
    $prop->setValue(null, null);
};

// ---------------------------------------------------------------------------
$filter = $argv[1] ?? '';
T::$db = TradingTestDb::create('oserp_trading_test', true);
$start = microtime(true);
foreach ($tests as $name => $fn) {
    if ($filter !== '' && stripos($name, $filter) === false) continue;
    T::$current = $name;
    $before = count(T::$failed);
    $t = microtime(true);
    try {
        $fn();
    } catch (Throwable $e) {
        T::$failed[] = $name . ': Ausnahme ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
        echo "    ✗ Ausnahme: " . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
    echo (count(T::$failed) === $before ? '  ✓ ' : '  ✗ ') . $name . ' (' . round(microtime(true) - $t, 1) . " s)\n";
}
T::reset();
echo "\n" . T::$passed . ' Prüfungen bestanden, ' . count(T::$failed) . ' fehlgeschlagen (' . round(microtime(true) - $start, 1) . " s)\n";
exit(T::$failed ? 1 : 0);
