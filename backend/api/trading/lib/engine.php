<?php
// backend/api/trading/lib/engine.php

/**
 * Hauptschleife des Handelssystems
 *
 * Ein Lauf (alle paar Minuten per Cron, backend/cli/trading-engine.php):
 *
 *   1. Marktdaten nachholen und prüfen
 *   2. je Konto: offene Orders mit dem Broker abgleichen
 *   3. je Konto: bei neuem Tagesbalken Zielgewichte bilden und umschichten
 *   4. Konten bewerten, Risiko überwachen
 *   5. fällige Arbeiten: Lebenszyklus der Strategien, Kapitalverteilung,
 *      Forschungszyklus, Tagesbericht
 *
 * Der Lauf ist wiederholbar: bricht er an beliebiger Stelle ab (Absturz,
 * Neustart), setzt der nächste Lauf dort auf — offene Orders werden
 * abgeglichen, bereits getroffene Entscheidungen erzeugen wegen der
 * eindeutigen client_order_id keine zweite Order.
 */
class TradingEngine {

    /** @var callable|null Wartefunktion (Sekunden) — in Tests ersetzt */
    public static $sleep = null;

    /**
     * Führt einen Lauf aus
     *
     * @param object $db   ApiDatabase
     * @param array  $opts force_research (bool), skip_research (bool), skip_report (bool)
     * @return array Zusammenfassung des Laufs
     */
    public static function run($db, array $opts = []): array {
        $settings = TradingConfig::settings($db);
        if (($settings['engine_enabled'] ?? '1') !== '1') {
            return ['skipped' => 'engine_disabled'];
        }
        // Nie zwei Läufe gleichzeitig auf derselben Firmen-Datenbank
        $lock = $db->getOne("SELECT pg_try_advisory_lock(hashtext('oserp_trading_engine')) AS ok", []);
        if (!$lock || !$lock['ok']) {
            return ['skipped' => 'already_running'];
        }

        $summary = ['bars' => 0, 'orders' => 0, 'errors' => []];
        try {
            TradingState::set($db, 'engine_heartbeat', gmdate('c'));

            // 1. Marktdaten
            $md = TradingMarketData::update($db);
            $summary['bars'] = $md['inserted'];
            foreach ($md['errors'] as $err) {
                $summary['errors'][] = 'Marktdaten: ' . $err;
                TradingAudit::log($db, 'marketdata', 'update_error', ['message' => $err], 'warning');
            }
            $panel = TradingMarketData::panel($db);
            if (count($panel['ts']) < 50) {
                $summary['skipped'] = 'no_market_data';
                return $summary;
            }
            $lastBar = end($panel['ts']);
            $dataFresh = (time() - $lastBar) < 4 * 86400;

            $quotes = [];
            try {
                $quotes = TradingMarketData::quotes($db);
            } catch (Throwable $e) {
                $summary['errors'][] = 'Kurse: ' . $e->getMessage();
                TradingAudit::log($db, 'marketdata', 'quotes_error', ['message' => $e->getMessage()], 'warning');
            }

            // 5a. Lebenszyklus und Kapitalverteilung einmal je Tag, VOR dem Handel:
            // eine heute ausgemusterte Strategie soll heute nicht mehr kaufen
            if (TradingState::get($db, 'lifecycle_day') !== gmdate('Y-m-d')) {
                $summary['lifecycle'] = TradingResearch::lifecycle($db);
                TradingState::set($db, 'lifecycle_day', gmdate('Y-m-d'));
            }
            TradingPortfolio::ensureAccounts($db);
            TradingPortfolio::allocate($db);

            // 2.–4. Konten
            foreach (TradingPortfolio::accounts($db) as $account) {
                try {
                    $summary['orders'] += self::processAccount($db, $account, $panel, $quotes, $lastBar, $dataFresh);
                } catch (Throwable $e) {
                    $summary['errors'][] = $account['name'] . ': ' . $e->getMessage();
                    if (in_array($account['kind'], TradingPortfolio::BROKER_KINDS, true)) {
                        TradingRisk::recordError($db, $account['name'] . ': ' . $e->getMessage());
                    } else {
                        TradingAudit::log($db, 'engine', 'account_error', ['message' => $e->getMessage()], 'warning', (int)$account['id']);
                    }
                }
            }
            TradingRisk::monitor($db);
            if (!$summary['errors']) TradingRisk::recordSuccess($db);

            // 5b. Forschung
            $researchDue = ($settings['research_enabled'] ?? '1') === '1'
                && (time() - (int)TradingState::get($db, 'research_last_ts', '0')) >= (float)($settings['research_interval_hours'] ?? 24) * 3600;
            if (empty($opts['skip_research']) && ($researchDue || !empty($opts['force_research']))) {
                TradingState::set($db, 'research_last_ts', (string)time());
                $summary['research'] = TradingResearch::cycle($db, $panel);
                TradingPortfolio::ensureAccounts($db);
            }

            // 5c. Tagesbericht
            if (empty($opts['skip_report']) && (int)gmdate('G') >= (int)($settings['report_hour'] ?? 6)
                && TradingState::get($db, 'report_day') !== gmdate('Y-m-d')) {
                TradingReport::generate($db, $panel);
                TradingState::set($db, 'report_day', gmdate('Y-m-d'));
                $summary['report'] = true;
            }
        } finally {
            $db->execute("SELECT pg_advisory_unlock(hashtext('oserp_trading_engine'))", []);
        }
        return $summary;
    }

    /**
     * Bearbeitet ein Konto: abgleichen, umschichten, absichern, bewerten
     *
     * @param object $db        ApiDatabase
     * @param array  $account   Zeile aus TradingPortfolio::accounts
     * @param array  $panel     Kurspanel
     * @param array  $quotes    Aktuelle Kurse
     * @param int    $lastBar   Unix-Zeit des letzten abgeschlossenen Balkens
     * @param bool   $dataFresh false, wenn die Marktdaten veraltet sind
     * @return int Zahl der neu aufgegebenen Orders
     */
    public static function processAccount($db, array $account, array $panel, array $quotes, int $lastBar, bool $dataFresh): int {
        $broker = TradingBrokers::forAccount($db, $account, $quotes);
        $usesBroker = in_array($account['kind'], TradingPortfolio::BROKER_KINDS, true);
        TradingOrders::sync($db, $account, $broker);

        $orders = 0;
        $lastSignal = $account['last_signal_ts'] ? strtotime($account['last_signal_ts'] . ' UTC') : 0;
        if ($lastBar > $lastSignal && $quotes && $dataFresh) {
            $blocked = TradingRisk::tradingBlocked($db, $account);
            if ($blocked === null) {
                $orders = self::rebalance($db, $account, $broker, $panel, $quotes, $lastBar);
                $db->execute("UPDATE accounts_trading SET last_signal_ts = to_timestamp(:ts) AT TIME ZONE 'UTC' WHERE id = :id",
                    [':ts' => $lastBar, ':id' => $account['id']]);
            }
        }
        if ($usesBroker && $quotes && TradingRisk::tradingBlocked($db, $account) === null) {
            self::protectiveStops($db, $account, $broker, $quotes);
        }
        TradingPortfolio::snapshot($db, (int)$account['id'], TradingPortfolio::value($db, $account, $quotes, $panel));
        return $orders;
    }

    /**
     * Schichtet ein Konto auf seine Zielgewichte um
     *
     * Verkäufe zuerst, dann Käufe mit dem tatsächlich vorhandenen Guthaben.
     * Jede Order geht einzeln durch die Risk-Engine.
     *
     * @param object        $db      ApiDatabase
     * @param array         $account Kontozeile
     * @param TradingBroker $broker  Adapter
     * @param array         $panel   Kurspanel
     * @param array         $quotes  Aktuelle Kurse
     * @param int           $lastBar Unix-Zeit des Signalbalkens
     * @return int Zahl der aufgegebenen Orders
     */
    public static function rebalance($db, array $account, TradingBroker $broker, array $panel, array $quotes, int $lastBar): int {
        $settings = TradingConfig::settings($db);
        $limits = TradingConfig::limits($db);
        $thresh = (float)($settings['rebalance_threshold_pct'] ?? 5) / 100;
        $feeRate = (float)($settings['fee_bps'] ?? 40) / 10000;
        $usesBroker = in_array($account['kind'], TradingPortfolio::BROKER_KINDS, true);
        $signalKey = gmdate('Ymd', $lastBar);
        $lastIdx = count($panel['ts']) - 1;

        $target = TradingPortfolio::targetWeights($db, $account, $panel);
        $value = TradingPortfolio::value($db, $account, $quotes, $panel);
        $positions = TradingPortfolio::positions($db, (int)$account['id']);
        $equity = $value['equity'];
        if ($equity <= 0) return 0;
        $dayStart = TradingPortfolio::dayStartEquity($db, $account);

        $instruments = [];
        foreach ($db->getAll(
            "SELECT i.id, i.symbol, i.qty_decimals, i.min_order_value, COALESCE(s.venue_symbol, '') AS venue_symbol
             FROM instruments_trading i
             LEFT JOIN instrument_symbols_trading s ON s.instrument_id = i.id AND s.venue = :venue", [':venue' => $account['venue'] ?? '']
        ) ?: [] as $i) {
            $instruments[(int)$i['id']] = $i;
        }

        $ctxFor = function (int $iid) use ($db, $account, $quotes, $panel, $lastIdx, $dayStart, $instruments) {
            // Bewertung frisch: vorherige Orders dieses Laufs haben Bestand und Guthaben verändert
            $v = TradingPortfolio::value($db, $account, $quotes, $panel);
            $pos = TradingPortfolio::positions($db, (int)$account['id']);
            return [
                'quote' => $quotes[$iid] ?? null, 'equity' => $v['equity'],
                'position_value' => $v['by_instrument'][$iid] ?? 0.0, 'positions_value' => $v['positions_value'],
                'position_qty' => $pos[$iid]['qty'] ?? 0.0, 'last_close' => $panel['close'][$iid][$lastIdx] ?? null,
                'day_start_equity' => $dayStart, 'venue_symbol' => $instruments[$iid]['venue_symbol'] ?? '',
            ];
        };

        $count = 0;
        $buys = [];
        $ids = array_unique(array_merge(array_keys($target), array_keys($positions)));
        foreach ($ids as $iid) {
            $q = $quotes[$iid] ?? null;
            $ins = $instruments[$iid] ?? null;
            if (!$q || !$ins) continue;
            if ($usesBroker && $ins['venue_symbol'] === '') continue;   // beim Broker nicht handelbar

            $cur = $value['by_instrument'][$iid] ?? 0.0;
            $want = ($target[$iid] ?? 0.0) * $equity;
            $diff = $want - $cur;
            $exit = $want <= 0 && $cur > 0;
            if (!$exit && abs($diff) < max($thresh * $equity, (float)$ins['min_order_value'])) continue;

            if ($diff > 0) {
                $buys[$iid] = $diff;
                continue;
            }

            // Verkauf
            $qty = $exit ? $positions[$iid]['qty'] : min($positions[$iid]['qty'], -$diff / $q['price']);
            if ($usesBroker) {
                // Schutzstopp gibt den Bestand erst nach dem Storno frei
                TradingOrders::cancelOpen($db, $account, $broker, 'Umschichtung', $iid, 'sell');
                $brokerQty = $broker->getPositionQty($ins['venue_symbol']);
                if ($brokerQty < $qty * 0.98) {
                    TradingRisk::lock($db, $account['kind'] === 'live' ? 'live' : 'all', 'position_mismatch',
                        sprintf('%s: Broker meldet %s Stück %s, verbucht sind %s', $account['name'], $brokerQty, $ins['symbol'], $positions[$iid]['qty']));
                    return $count;
                }
                $qty = min($qty, $brokerQty);
            }
            $qty = self::floorQty($qty, (int)$ins['qty_decimals'], $exit && !$usesBroker);
            if ($qty <= 0) continue;
            $order = TradingOrders::create($db, $account, $iid, 'sell', 'market', $qty, $signalKey,
                ['strategy_version_id' => $account['version_id'] ?? null, 'reference_price' => $q['price'], 'reason' => $exit ? 'exit' : 'reduce']);
            if ($order) {
                TradingOrders::submit($db, $account, $order, $broker, $ctxFor($iid));
                $count++;
            }
        }

        // Verkäufe beim Broker kurz abwarten, damit das Guthaben für die Käufe da ist
        if ($usesBroker && $count > 0) {
            for ($i = 0; $i < 3; $i++) {
                self::wait(2);
                TradingOrders::sync($db, $account, $broker);
            }
        }

        arsort($buys);
        foreach ($buys as $iid => $diff) {
            $q = $quotes[$iid];
            $ins = $instruments[$iid];
            $cash = (float)$db->getOne("SELECT cash FROM accounts_trading WHERE id = :id", [':id' => $account['id']])['cash'];
            // Puffer für Gebühr, Slippage und Kursbewegung bis zur Ausführung
            $spend = min($diff, $cash / (1 + $feeRate) * 0.995);
            if ($account['kind'] === 'live') $spend = min($spend, (float)($limits['max_order_value'] ?? 0));
            if ($spend < (float)$ins['min_order_value']) continue;
            $qty = self::floorQty($spend / $q['ask'], (int)$ins['qty_decimals'], false);
            if ($qty <= 0) continue;
            $order = TradingOrders::create($db, $account, $iid, 'buy', 'market', $qty, $signalKey,
                ['strategy_version_id' => $account['version_id'] ?? null, 'reference_price' => $q['price'], 'reason' => 'increase']);
            if ($order) {
                TradingOrders::submit($db, $account, $order, $broker, $ctxFor($iid));
                $count++;
            }
        }
        return $count;
    }

    /**
     * Hält für jeden Bestand eines Brokerkontos einen Stopp beim Broker
     *
     * Sicherheitsnetz für den Fall, dass die Engine ausfällt: der Stopp liegt
     * beim Broker und greift auch ohne uns. Abstand = protective_stop_pct unter
     * dem Einstandskurs (0 = aus).
     *
     * @param object        $db      ApiDatabase
     * @param array         $account Kontozeile
     * @param TradingBroker $broker  Adapter
     * @param array         $quotes  Aktuelle Kurse
     */
    public static function protectiveStops($db, array $account, TradingBroker $broker, array $quotes): void {
        $pct = (float)(TradingConfig::limits($db)['protective_stop_pct'] ?? 0);
        if ($pct <= 0) return;

        $rows = $db->getAll(
            "SELECT p.instrument_id, p.qty, p.avg_price, i.qty_decimals, s.venue_symbol,
                    (SELECT COALESCE(sum(o.qty - o.filled_qty), 0) FROM orders_trading o
                      WHERE o.account_id = p.account_id AND o.instrument_id = p.instrument_id AND o.side = 'sell'
                        AND o.order_type IN ('stop', 'stop_limit') AND o.status IN ('submitting', 'submitted', 'partially_filled')) AS stop_qty
             FROM positions_trading p
             JOIN instruments_trading i ON i.id = p.instrument_id
             JOIN instrument_symbols_trading s ON s.instrument_id = p.instrument_id AND s.venue = :venue
             WHERE p.account_id = :aid AND p.qty > 0",
            [':venue' => $account['venue'], ':aid' => $account['id']]
        ) ?: [];

        foreach ($rows as $p) {
            $iid = (int)$p['instrument_id'];
            $qty = self::floorQty((float)$p['qty'], (int)$p['qty_decimals'], false);
            $q = $quotes[$iid] ?? null;
            if ($qty <= 0 || !$q) continue;
            // Stopp passt noch zum Bestand
            if (abs((float)$p['stop_qty'] - $qty) <= $qty * 0.01) continue;

            TradingOrders::cancelOpen($db, $account, $broker, 'Schutzstopp anpassen', $iid, 'sell');
            $stop = (float)$p['avg_price'] * (1 - $pct / 100);
            if ($stop >= $q['bid']) continue;   // Kurs liegt schon darunter: die Strategie entscheidet beim nächsten Signal
            $caps = $broker->capabilities($p['venue_symbol']);
            $type = in_array('stop', $caps, true) ? 'stop' : (in_array('stop_limit', $caps, true) ? 'stop_limit' : null);
            if ($type === null) continue;
            $opts = ['stop_price' => $stop, 'reason' => 'protective_stop', 'reference_price' => $q['price']];
            if ($type === 'stop_limit') $opts['limit_price'] = $stop * 0.98;
            $order = TradingOrders::create($db, $account, $iid, 'sell', $type, $qty, 'stop-' . time(), $opts);
            if ($order) {
                $value = TradingPortfolio::value($db, $account, $quotes, ['ts' => [0], 'close' => []]);
                TradingOrders::submit($db, $account, $order, $broker, [
                    'quote' => $q, 'equity' => $value['equity'], 'position_qty' => (float)$p['qty'],
                    'venue_symbol' => $p['venue_symbol'],
                ]);
            }
        }
    }

    /**
     * Not-Aus: Sperre setzen und alle offenen Orders aller Konten stornieren
     *
     * Bestände werden nicht verkauft — das entscheidet der Benutzer.
     *
     * @param object $db    ApiDatabase
     * @param string $actor Wer ausgelöst hat (user:<login>)
     * @return int Zahl der stornierten Orders
     */
    public static function killSwitch($db, string $actor): int {
        TradingRisk::lock($db, 'all', 'kill_switch', 'Not-Aus ausgelöst', $actor);
        $n = 0;
        foreach (TradingPortfolio::accounts($db) as $account) {
            try {
                $broker = TradingBrokers::forAccount($db, $account, []);
                $n += TradingOrders::cancelOpen($db, $account, $broker, 'Not-Aus');
                if (in_array($account['kind'], TradingPortfolio::BROKER_KINDS, true)) {
                    // Auch was wir nicht (mehr) kennen: alles Offene beim Broker stornieren
                    $broker->cancelAll();
                }
            } catch (Throwable $e) {
                TradingAudit::log($db, $actor, 'kill_switch_error', ['message' => $e->getMessage()], 'critical', (int)$account['id']);
            }
        }
        return $n;
    }

    /**
     * Rundet eine Stückzahl auf die handelbare Stellenzahl ab
     *
     * @param float $qty      Stückzahl
     * @param int   $decimals Nachkommastellen des Instruments
     * @param bool  $exact    true = unverändert (vollständiger Ausstieg im Simulator)
     * @return float
     */
    public static function floorQty(float $qty, int $decimals, bool $exact): float {
        if ($exact) return $qty;
        $f = pow(10, $decimals);
        return floor($qty * $f + 1e-9) / $f;
    }

    /**
     * Wartet (in Tests ersetzbar)
     *
     * @param int $seconds Sekunden
     */
    private static function wait(int $seconds): void {
        if (self::$sleep !== null) {
            (self::$sleep)($seconds);
        } elseif (!TradingGuard::isTestMode()) {
            sleep($seconds);
        }
    }
}
