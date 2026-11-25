<?php
// backend/api/trading/lib/portfolio.php

/**
 * Konten, Bewertung und Kapitalverteilung
 *
 * Kontenarten:
 *   strategy_paper   virtuelles Testkonto je Strategie (gleiches Startkapital,
 *                    damit Strategien vergleichbar bleiben)
 *   portfolio_paper  virtuelles Gesamtportfolio: mischt die laufenden Strategien
 *                    nach der Kapitalverteilung — das zeigt das Dashboard
 *   broker_paper     Konto in der Testumgebung eines Brokers
 *   live             Brokerkonto mit echtem Geld
 *
 * Brokerkonten handeln die Mischung der Strategien, die den Vorwärtstest
 * bestanden haben (live_eligible, live). Das virtuelle Gesamtportfolio nimmt
 * auch Strategien im Vorwärtstest auf.
 */
class TradingPortfolio {

    const BROKER_KINDS = ['live', 'broker_paper'];

    /**
     * Aktive Konten samt Adapter und Strategie
     *
     * @param object $db ApiDatabase
     * @return array
     */
    public static function accounts($db): array {
        return $db->getAll(
            "SELECT a.*, COALESCE(c.adapter, '') AS venue, s.status AS strategy_status,
                    s.family, v.id AS version_id, v.params, v.instrument_ids
             FROM accounts_trading a
             LEFT JOIN broker_connections_trading c ON c.id = a.broker_connection_id
             LEFT JOIN strategies_trading s ON s.id = a.strategy_id
             LEFT JOIN strategy_versions_trading v ON v.id = s.current_version_id
             WHERE a.active AND a.closed_at IS NULL
               AND (a.broker_connection_id IS NULL OR c.active)
             ORDER BY a.id", []
        ) ?: [];
    }

    /**
     * Legt fehlende Konten an: Gesamtportfolio und je laufender Strategie ein Testkonto
     *
     * @param object $db ApiDatabase
     */
    public static function ensureAccounts($db): void {
        $settings = TradingConfig::settings($db);
        $capital = TradingOrders::dec($settings['lab_capital'] ?? 10000);
        $currency = $settings['account_currency'] ?? 'EUR';

        $db->execute(
            "INSERT INTO accounts_trading (kind, name, currency, start_capital, cash, peak_equity)
             SELECT 'portfolio_paper', 'KI-Portfolio (virtuell)', :cur, :cap, :cap2, :cap3
             WHERE NOT EXISTS (SELECT 1 FROM accounts_trading WHERE kind = 'portfolio_paper' AND closed_at IS NULL)",
            [':cur' => $currency, ':cap' => $capital, ':cap2' => $capital, ':cap3' => $capital]
        );
        $db->execute(
            "INSERT INTO accounts_trading (kind, name, strategy_id, currency, start_capital, cash, peak_equity)
             SELECT 'strategy_paper', 'Testkonto ' || s.code, s.id, :cur, :cap, :cap2, :cap3
             FROM strategies_trading s
             WHERE s.status IN ('paper', 'live_eligible', 'live')
               AND NOT EXISTS (SELECT 1 FROM accounts_trading a WHERE a.strategy_id = s.id AND a.kind = 'strategy_paper' AND a.closed_at IS NULL)",
            [':cur' => $currency, ':cap' => $capital, ':cap2' => $capital, ':cap3' => $capital]
        );
    }

    /**
     * Bestände eines Kontos
     *
     * @param object $db        ApiDatabase
     * @param int    $accountId Konto
     * @return array [instrument_id => ['qty' => float, 'avg_price' => float]]
     */
    public static function positions($db, int $accountId): array {
        $out = [];
        foreach ($db->getAll("SELECT instrument_id, qty, avg_price FROM positions_trading WHERE account_id = :id AND qty > 0", [':id' => $accountId]) ?: [] as $p) {
            $out[(int)$p['instrument_id']] = ['qty' => (float)$p['qty'], 'avg_price' => (float)$p['avg_price']];
        }
        return $out;
    }

    /**
     * Bewertet ein Konto: Guthaben + Bestände zum aktuellen Kurs
     *
     * Fehlt ein aktueller Kurs, zählt der letzte Schlusskurs.
     *
     * @param object $db      ApiDatabase
     * @param array  $account Zeile aus accounts_trading
     * @param array  $quotes  [instrument_id => quote]
     * @param array  $panel   Kurspanel (für den letzten Schlusskurs)
     * @return array equity, cash, positions_value, by_instrument [id => Wert]
     */
    public static function value($db, array $account, array $quotes, array $panel): array {
        $cash = (float)$db->getOne("SELECT cash FROM accounts_trading WHERE id = :id", [':id' => $account['id']])['cash'];
        $by = [];
        $last = count($panel['ts']) - 1;
        foreach (self::positions($db, (int)$account['id']) as $iid => $p) {
            $price = $quotes[$iid]['price'] ?? ($panel['close'][$iid][$last] ?? $p['avg_price']);
            $by[$iid] = $p['qty'] * $price;
        }
        $pos = array_sum($by);
        return ['equity' => $cash + $pos, 'cash' => $cash, 'positions_value' => $pos, 'by_instrument' => $by];
    }

    /**
     * Schreibt den Kontowert des laufenden Tages und zieht den Höchststand nach
     *
     * @param object $db        ApiDatabase
     * @param int    $accountId Konto
     * @param array  $value     Ergebnis von value()
     */
    public static function snapshot($db, int $accountId, array $value): void {
        $db->execute(
            "INSERT INTO equity_trading (account_id, day, equity, cash, positions_value, mtime)
             VALUES (:id, CURRENT_DATE, :eq, :cash, :pos, now())
             ON CONFLICT (account_id, day) DO UPDATE SET equity = EXCLUDED.equity, cash = EXCLUDED.cash,
                    positions_value = EXCLUDED.positions_value, mtime = now()",
            [':id' => $accountId, ':eq' => TradingOrders::dec($value['equity']), ':cash' => TradingOrders::dec($value['cash']), ':pos' => TradingOrders::dec($value['positions_value'])]
        );
        $db->execute("UPDATE accounts_trading SET peak_equity = GREATEST(COALESCE(peak_equity, 0), :eq) WHERE id = :id",
            [':eq' => TradingOrders::dec($value['equity']), ':id' => $accountId]);
    }

    /**
     * Kontowert zu Beginn des Tages (letzter Stand vor heute, sonst Startkapital)
     *
     * @param object $db      ApiDatabase
     * @param array  $account Zeile aus accounts_trading
     * @return float
     */
    public static function dayStartEquity($db, array $account): float {
        $row = $db->getOne("SELECT equity FROM equity_trading WHERE account_id = :id AND day < CURRENT_DATE ORDER BY day DESC LIMIT 1", [':id' => $account['id']]);
        return $row ? (float)$row['equity'] : (float)$account['start_capital'];
    }

    /**
     * Strategien, die gerade Kapital bekommen können, mit aktueller Version
     *
     * @param object $db       ApiDatabase
     * @param array  $statuses zulässige Zustände
     * @return array
     */
    public static function members($db, array $statuses): array {
        return $db->getAll(
            "SELECT s.id, s.code, s.family, s.status, v.id AS version_id, v.params, v.instrument_ids,
                    (SELECT b.equity_curve FROM backtests_trading b WHERE b.strategy_version_id = v.id AND b.segment = 'full' ORDER BY b.id DESC LIMIT 1) AS equity_curve,
                    (SELECT b.sharpe FROM backtests_trading b WHERE b.strategy_version_id = v.id AND b.segment = 'test' ORDER BY b.id DESC LIMIT 1) AS test_sharpe
             FROM strategies_trading s
             JOIN strategy_versions_trading v ON v.id = s.current_version_id
             WHERE s.status = ANY (string_to_array(:st, ','))
             ORDER BY s.id",
            [':st' => implode(',', $statuses)]
        ) ?: [];
    }

    /**
     * Verteilt das Kapital des Gesamtportfolios auf die laufenden Strategien
     *
     * Gewicht ∝ Güte / (Schwankung × (1 + mittlere Korrelation zu den anderen)).
     * Ruhige, voneinander unabhängige Strategien bekommen mehr; zwei Strategien,
     * die dasselbe tun, teilen sich ihren Anteil. Keine Strategie über 40 %,
     * sobald es mindestens drei gibt. Grundlage sind die Tagesrenditen des
     * vollständigen Backtests der jeweiligen Version.
     *
     * @param object $db ApiDatabase
     * @return array [strategy_id => Gewicht], Summe 1 (leer, wenn es keine Strategien gibt)
     */
    public static function allocate($db): array {
        $members = self::members($db, ['paper', 'live_eligible', 'live']);
        if (!$members) return [];

        $returns = [];
        foreach ($members as $m) {
            $curve = json_decode($m['equity_curve'] ?? '[]', true) ?: [];
            $curve = array_slice($curve, -365);
            $r = [];
            for ($i = 1, $n = count($curve); $i < $n; $i++) {
                $r[] = $curve[$i - 1] > 0 ? $curve[$i] / $curve[$i - 1] - 1 : 0.0;
            }
            $returns[$m['id']] = $r;
        }

        $raw = [];
        foreach ($members as $m) {
            $vol = max(TradingStats::sd($returns[$m['id']]), 0.002);
            $corr = [];
            foreach ($members as $o) {
                if ($o['id'] !== $m['id']) $corr[] = TradingStats::correlation($returns[$m['id']], $returns[$o['id']]);
            }
            $avgCorr = $corr ? max(TradingStats::mean($corr), 0.0) : 0.0;
            $quality = min(max((float)$m['test_sharpe'], 0.25), 2.0);
            $raw[$m['id']] = $quality / ($vol * (1 + $avgCorr));
        }
        $weights = self::capWeights($raw, count($raw) >= 3 ? 0.40 : 1.0);

        // Nur bei Änderung neu schreiben
        $current = self::allocation($db);
        $changed = count($current) !== count($weights);
        foreach ($weights as $sid => $w) {
            if (abs(($current[$sid] ?? -1) - $w) > 0.02) $changed = true;
        }
        if ($changed) {
            $db->execute("DELETE FROM allocations_trading WHERE day = CURRENT_DATE", []);
            foreach ($weights as $sid => $w) {
                $db->execute("INSERT INTO allocations_trading (day, strategy_id, weight, reason) VALUES (CURRENT_DATE, :sid, :w, 'risk_parity_corr')",
                    [':sid' => $sid, ':w' => round($w, 6)]);
            }
            TradingAudit::log($db, 'engine', 'allocation_changed', ['weights' => $weights]);
        }
        return $weights;
    }

    /**
     * Normiert Rohgewichte auf Summe 1 mit Obergrenze je Eintrag
     *
     * @param array $raw [id => Rohgewicht]
     * @param float $cap Obergrenze 0–1
     * @return array
     */
    public static function capWeights(array $raw, float $cap): array {
        $sum = array_sum($raw);
        if ($sum <= 0) return [];
        $w = array_map(fn($v) => $v / $sum, $raw);
        if ($cap * count($w) < 1.0) return $w;   // Obergrenze unerfüllbar
        for ($round = 0; $round < 10; $round++) {
            $over = array_filter($w, fn($v) => $v > $cap + 1e-9);
            if (!$over) break;
            $excess = 0.0;
            foreach ($over as $id => $v) {
                $excess += $v - $cap;
                $w[$id] = $cap;
            }
            $free = array_filter($w, fn($v) => $v < $cap - 1e-9);
            $freeSum = array_sum($free);
            if ($freeSum <= 0) break;
            foreach ($free as $id => $v) $w[$id] = $v + $excess * $v / $freeSum;
        }
        return $w;
    }

    /**
     * Zuletzt gespeicherte Kapitalverteilung
     *
     * @param object $db ApiDatabase
     * @return array [strategy_id => Gewicht]
     */
    public static function allocation($db): array {
        $out = [];
        foreach ($db->getAll(
            "SELECT strategy_id, weight FROM allocations_trading WHERE day = (SELECT max(day) FROM allocations_trading)", []
        ) ?: [] as $r) {
            $out[(int)$r['strategy_id']] = (float)$r['weight'];
        }
        return $out;
    }

    /**
     * Zielgewichte eines Kontos für den letzten abgeschlossenen Balken
     *
     * @param object $db      ApiDatabase
     * @param array  $account Zeile aus accounts() (mit family, params, instrument_ids)
     * @param array  $panel   Kurspanel aller aktiven Instrumente
     * @return array [instrument_id => Gewicht]
     */
    public static function targetWeights($db, array $account, array $panel): array {
        $cap = TradingConfig::limits($db)['max_position_pct'] ?? 100.0;
        $last = count($panel['ts']) - 1;

        if ($account['kind'] === 'strategy_paper') {
            if (empty($account['family']) || !in_array($account['strategy_status'], ['paper', 'live_eligible', 'live'], true)) return [];
            return self::strategyWeights($account['family'], $account['params'], $account['instrument_ids'], $panel, $cap)[$last] ?? [];
        }

        $statuses = in_array($account['kind'], self::BROKER_KINDS, true) ? ['live_eligible', 'live'] : ['paper', 'live_eligible', 'live'];
        $members = self::members($db, $statuses);
        $alloc = self::allocation($db);
        $sum = 0.0;
        foreach ($members as $m) $sum += $alloc[(int)$m['id']] ?? 0.0;
        if ($sum <= 0) return [];

        $blend = [];
        foreach ($members as $m) {
            $share = ($alloc[(int)$m['id']] ?? 0.0) / $sum;
            if ($share <= 0) continue;
            $w = self::strategyWeights($m['family'], $m['params'], $m['instrument_ids'], $panel, $cap)[$last] ?? [];
            foreach ($w as $iid => $wi) $blend[$iid] = ($blend[$iid] ?? 0.0) + $share * $wi;
        }
        foreach ($blend as $iid => $w) $blend[$iid] = min($w, $cap / 100);
        return $blend;
    }

    /**
     * Gewichtsreihe einer Strategieversion auf ihrem Universum
     *
     * @param string $family        Familie
     * @param string $paramsJson    Parameter (JSON aus der Datenbank)
     * @param string $instrumentIds Universum als PostgreSQL-Array-Text '{1,2}'
     * @param array  $panel         Kurspanel aller aktiven Instrumente
     * @param float  $cap           Positionsobergrenze in %
     * @return array [t => [instrument_id => Gewicht]]
     */
    public static function strategyWeights(string $family, string $paramsJson, string $instrumentIds, array $panel, float $cap): array {
        $ids = array_map('intval', array_filter(explode(',', trim($instrumentIds, '{}')), 'strlen'));
        return TradingStrategies::weights($family, json_decode($paramsJson, true) ?: [], self::subPanel($panel, $ids), $cap);
    }

    /**
     * Schneidet das Panel auf ein Universum zu
     *
     * @param array $panel Kurspanel
     * @param array $ids   Instrumente des Universums
     * @return array
     */
    public static function subPanel(array $panel, array $ids): array {
        $ids = array_values(array_intersect($panel['ids'], $ids));
        $sub = ['ts' => $panel['ts'], 'ids' => $ids, 'benchmark_id' => $panel['benchmark_id'], 'periods_per_year' => $panel['periods_per_year']];
        foreach (['open', 'high', 'low', 'close'] as $f) {
            $sub[$f] = array_intersect_key($panel[$f], array_flip($ids));
            // Der Vergleichsmaßstab bleibt für den Marktphasenfilter lesbar, auch wenn er nicht gehandelt wird
            if (!isset($sub[$f][$panel['benchmark_id']]) && isset($panel[$f][$panel['benchmark_id']])) {
                $sub[$f][$panel['benchmark_id']] = $panel[$f][$panel['benchmark_id']];
            }
        }
        return $sub;
    }
}
