<?php
// backend/api/trading/lib/risk.php

/**
 * Unabhängige Risk-Engine
 *
 * Jede Order — Papier wie Echtgeld — läuft vor der Übermittlung durch
 * checkOrder(). Die Strategie- und Forschungsseite hat keinen Weg an dieser
 * Prüfung vorbei: TradingOrders::submit() ist die einzige Stelle, die einen
 * Broker-Adapter anspricht, und sie ruft die Prüfung selbst.
 *
 * Die Risk-Engine kann Sperren SETZEN (lock), aber nicht aufheben — das
 * verhindert der Trigger auf locks_trading. Grenzen liest sie nur.
 *
 * Zwei Arten von Stopps:
 *   - vorübergehend (Tagesverlust): wird bei jeder Prüfung neu berechnet und
 *     endet von selbst mit dem nächsten Handelstag
 *   - dauerhaft (Portfolioverlust, Fehlerserie, Bestandsabweichung, Not-Aus):
 *     Zeile in locks_trading, bleibt bis ein Administrator sie aufhebt
 */
class TradingRisk {

    /**
     * Aktive Sperren
     *
     * @param object $db ApiDatabase
     * @return array Zeilen aus locks_trading
     */
    public static function activeLocks($db): array {
        return $db->getAll("SELECT * FROM locks_trading WHERE cleared_at IS NULL ORDER BY set_at", []) ?: [];
    }

    /**
     * Setzt eine Sperre (höchstens eine aktive je Grund und Geltungsbereich)
     *
     * @param object $db      ApiDatabase
     * @param string $scope   all | live (live = Konten mit echtem Geld)
     * @param string $code    Grund: kill_switch, drawdown, critical_errors, position_mismatch …
     * @param string $message Erläuterung
     * @param string $setBy   risk, engine, user:<login>
     */
    public static function lock($db, string $scope, string $code, string $message, string $setBy = 'risk'): void {
        $exists = $db->getOne(
            "SELECT id FROM locks_trading WHERE cleared_at IS NULL AND scope = :scope AND reason_code = :code",
            [':scope' => $scope, ':code' => $code]
        );
        if ($exists) return;
        $db->execute(
            "INSERT INTO locks_trading (scope, reason_code, message, set_by) VALUES (:scope, :code, :message, :set_by)",
            [':scope' => $scope, ':code' => $code, ':message' => $message, ':set_by' => $setBy]
        );
        TradingAudit::log($db, $setBy, 'lock_set', ['scope' => $scope, 'code' => $code, 'message' => $message], 'critical');
    }

    /**
     * Ist der Echtgeldhandel vom Benutzer freigegeben? (letzter Protokolleintrag zählt)
     *
     * @param object $db ApiDatabase
     * @return bool
     */
    public static function liveAuthorized($db): bool {
        $row = $db->getOne("SELECT action FROM live_authorizations_trading ORDER BY id DESC LIMIT 1", []);
        return $row && $row['action'] === 'enable';
    }

    /**
     * Darf dieses Konto gerade handeln?
     *
     * @param object $db      ApiDatabase
     * @param array  $account Zeile aus accounts_trading
     * @return string|null Hinderungsgrund oder null, wenn der Handel erlaubt ist
     */
    public static function tradingBlocked($db, array $account): ?string {
        foreach (self::activeLocks($db) as $lock) {
            if ($lock['scope'] === 'all' || ($lock['scope'] === 'live' && $account['kind'] === 'live')) {
                return 'Handelssperre: ' . $lock['reason_code'];
            }
        }
        if ($account['kind'] === 'live' && !self::liveAuthorized($db)) {
            return 'Echtgeldhandel ist nicht freigegeben';
        }
        return null;
    }

    /**
     * Prüft eine Order gegen alle Grenzen
     *
     * @param object $db      ApiDatabase
     * @param array  $account Zeile aus accounts_trading
     * @param array  $order   instrument_id, side, qty, order_type
     * @param array  $ctx     quote [price, ts], equity, position_value (dieses Instruments),
     *                        positions_value (alle), position_qty, last_close, day_start_equity
     * @return array ['ok' => bool, 'reason' => string, 'checks' => [name => ['ok' => bool, 'value' => …, 'limit' => …]]]
     */
    public static function checkOrder($db, array $account, array $order, array $ctx): array {
        $limits = TradingConfig::limits($db);
        $checks = [];
        $add = function (string $name, bool $ok, $value = null, $limit = null) use (&$checks) {
            $checks[$name] = ['ok' => $ok, 'value' => $value, 'limit' => $limit];
        };

        $blocked = self::tradingBlocked($db, $account);
        $add('trading_allowed', $blocked === null, $blocked);

        $qty   = (float)$order['qty'];
        $price = (float)($ctx['quote']['price'] ?? 0);
        $buy   = $order['side'] === 'buy';
        $value = $qty * $price;
        $equity = (float)($ctx['equity'] ?? 0);

        $add('quantity_valid', $qty > 0 && is_finite($qty), $qty);
        $add('price_valid', $price > 0 && is_finite($price), $price);

        // Kursalter und Abweichung vom letzten Schlusskurs: Schutz vor fehlerhaften Marktdaten
        $age = (time() - (int)($ctx['quote']['ts'] ?? 0)) / 60;
        $add('price_fresh', $age <= ($limits['max_price_age_minutes'] ?? 30), round($age, 1), $limits['max_price_age_minutes'] ?? 30);
        if (!empty($ctx['last_close']) && $price > 0) {
            $dev = abs($price / $ctx['last_close'] - 1) * 100;
            $add('price_plausible', $dev <= ($limits['max_price_deviation_pct'] ?? 15), round($dev, 2), $limits['max_price_deviation_pct'] ?? 15);
        }

        // Offene Orders und Ordertakt
        $counts = $db->getOne(
            "SELECT count(*) FILTER (WHERE status IN ('submitting', 'submitted', 'partially_filled')) AS open,
                    count(*) FILTER (WHERE itime >= date_trunc('day', now()) AND status <> 'risk_rejected') AS today
             FROM orders_trading WHERE account_id = :aid AND id <> :oid",
            [':aid' => $account['id'], ':oid' => (int)($order['id'] ?? 0)]
        );
        $add('open_orders', (int)$counts['open'] < ($limits['max_open_orders'] ?? 20), (int)$counts['open'], $limits['max_open_orders'] ?? 20);
        $add('orders_per_day', (int)$counts['today'] < ($limits['max_orders_per_day'] ?? 40), (int)$counts['today'], $limits['max_orders_per_day'] ?? 40);

        if ($buy) {
            // Ordervolumen in Geld: nur Echtgeld — Papierkonten skalieren mit ihrem virtuellen Kapital
            if ($account['kind'] === 'live') {
                $add('order_value', $value <= ($limits['max_order_value'] ?? 0) * 1.001, round($value, 2), $limits['max_order_value'] ?? 0);
            }
            if ($equity > 0) {
                $posPct = ((float)($ctx['position_value'] ?? 0) + $value) / $equity * 100;
                $add('position_size', $posPct <= ($limits['max_position_pct'] ?? 35) + 1.0, round($posPct, 2), $limits['max_position_pct'] ?? 35);
                $grossPct = ((float)($ctx['positions_value'] ?? 0) + $value) / $equity * 100;
                $add('gross_exposure', $grossPct <= ($limits['max_gross_exposure_pct'] ?? 100) + 1.0, round($grossPct, 2), $limits['max_gross_exposure_pct'] ?? 100);
            } else {
                $add('equity_positive', false, $equity);
            }
            $add('cash_available', $value <= (float)$account['cash'] + 0.01, round($value, 2), round((float)$account['cash'], 2));

            // Tagesverlust: keine neuen Käufe mehr, Verkäufe bleiben möglich
            $dayStart = (float)($ctx['day_start_equity'] ?? 0);
            if ($dayStart > 0) {
                $dayLoss = (1 - $equity / $dayStart) * 100;
                $add('daily_loss', $dayLoss < ($limits['max_daily_loss_pct'] ?? 5), round($dayLoss, 2), $limits['max_daily_loss_pct'] ?? 5);
            }
        } else {
            $add('position_available', $qty <= (float)($ctx['position_qty'] ?? 0) * 1.0000001, $qty, (float)($ctx['position_qty'] ?? 0));
        }

        $failed = array_keys(array_filter($checks, fn($c) => !$c['ok']));
        return ['ok' => !$failed, 'reason' => implode(', ', $failed), 'checks' => $checks];
    }

    /**
     * Laufende Überwachung der Konten: Portfolioverlust und Fehlerserien
     *
     * Wird bei jedem Lauf der Engine gerufen, unabhängig davon, ob gehandelt wird.
     *
     * @param object $db ApiDatabase
     */
    public static function monitor($db): void {
        $limits = TradingConfig::limits($db);

        // Portfolioverlust vom Höchststand: Echtgeld und virtuelles Gesamtportfolio
        $accounts = $db->getAll(
            "SELECT a.id, a.kind, a.name, a.peak_equity,
                    (SELECT e.equity FROM equity_trading e WHERE e.account_id = a.id ORDER BY e.day DESC LIMIT 1) AS equity
             FROM accounts_trading a
             WHERE a.active AND a.closed_at IS NULL AND a.kind IN ('live', 'portfolio_paper')", []
        ) ?: [];
        foreach ($accounts as $a) {
            if (!$a['peak_equity'] || !$a['equity']) continue;
            $dd = (1 - $a['equity'] / $a['peak_equity']) * 100;
            if ($dd >= ($limits['max_portfolio_drawdown_pct'] ?? 20)) {
                // Echtgeld: Sperre nur für Echtgeld. Papierportfolio: alles anhalten wäre
                // überzogen — die Strategien dort laufen zu Forschungszwecken weiter.
                if ($a['kind'] === 'live') {
                    self::lock($db, 'live', 'drawdown',
                        sprintf('%s: Verlust vom Höchststand %.1f %% erreicht die Grenze von %.1f %%', $a['name'], $dd, $limits['max_portfolio_drawdown_pct']));
                } elseif (TradingState::get($db, 'portfolio_drawdown_alert') !== date('Y-m-d')) {
                    TradingState::set($db, 'portfolio_drawdown_alert', date('Y-m-d'));
                    TradingAudit::log($db, 'risk', 'portfolio_drawdown',
                        ['account' => $a['name'], 'drawdown_pct' => round($dd, 2), 'limit_pct' => $limits['max_portfolio_drawdown_pct']], 'warning', (int)$a['id']);
                }
            }
        }

        // Fehlerserie: automatische Handelssperre
        $errors = (int)TradingState::get($db, 'consecutive_errors', '0');
        if ($errors >= ($limits['max_consecutive_errors'] ?? 3)) {
            self::lock($db, 'all', 'critical_errors', $errors . ' kritische Fehler in Folge — Handel angehalten');
        }
    }

    /**
     * Zählt einen kritischen Fehler (Broker, Abgleich) für die automatische Sperre
     *
     * @param object $db      ApiDatabase
     * @param string $message Fehlertext
     * @param array  $details Zusatzdaten
     */
    public static function recordError($db, string $message, array $details = []): void {
        $n = (int)TradingState::get($db, 'consecutive_errors', '0') + 1;
        TradingState::set($db, 'consecutive_errors', (string)$n);
        TradingAudit::log($db, 'engine', 'critical_error', ['message' => $message, 'count' => $n] + $details, 'critical');
        self::monitor($db);
    }

    /**
     * Setzt den Fehlerzähler nach einem fehlerfreien Lauf zurück
     *
     * @param object $db ApiDatabase
     */
    public static function recordSuccess($db): void {
        if (TradingState::get($db, 'consecutive_errors', '0') !== '0') {
            TradingState::set($db, 'consecutive_errors', '0');
        }
    }
}
