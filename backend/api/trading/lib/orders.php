<?php
// backend/api/trading/lib/orders.php

/**
 * Ordermanagement
 *
 * Lebenslauf einer Order:
 *
 *   new ──Risikoprüfung──► risk_rejected
 *    │
 *    ▼
 *   submitting ──Broker──► submitted ──► partially_filled ──► filled
 *    │                        │                 │
 *    │                        └────────► canceled / expired / rejected
 *    └─ Antwort unklar: bleibt 'submitting', sync() fragt beim Broker nach
 *
 * Schutz vor doppelten Orders auf zwei Ebenen:
 *   1. client_order_id ist aus (Konto, Instrument, Signalzeitpunkt, Richtung)
 *      gebildet und in der Datenbank eindeutig — dieselbe Entscheidung kann
 *      auch nach Absturz und Neustart nur eine Order erzeugen.
 *   2. Der Zustand 'submitting' wird VOR dem Broker-Aufruf gespeichert. Reisst
 *      die Verbindung danach ab, wird nicht blind wiederholt, sondern über
 *      unsere Kennung beim Broker nachgesehen (findOrder).
 *
 * Ausführungen werden als Differenz zum bisher verbuchten Stand gebucht; die
 * Kennung der Buchung enthält die kumulierte Menge und ist je Order eindeutig.
 * Meldet der Broker denselben Stand zweimal, wird er einmal gebucht.
 */
class TradingOrders {

    const OPEN = ['submitting', 'submitted', 'partially_filled'];

    /**
     * Legt eine Order an (noch ohne Übermittlung)
     *
     * @param object $db           ApiDatabase
     * @param array  $account      Zeile aus accounts_trading
     * @param int    $instrumentId Instrument
     * @param string $side         buy | sell
     * @param string $type         market, limit, stop, stop_limit, trailing_stop
     * @param float  $qty          Stückzahl
     * @param string $signalKey    Kennung der Entscheidung, z. B. '20261124' oder 'stop-20261124'
     * @param array  $opts         limit_price, stop_price, trail_percent, strategy_version_id, reason, reference_price
     * @return array|null Orderzeile; null, wenn es diese Order schon gibt (Duplikat verhindert)
     */
    public static function create($db, array $account, int $instrumentId, string $side, string $type, float $qty, string $signalKey, array $opts = []): ?array {
        $clientId = sprintf('oserp-%d-%d-%s-%s', $account['id'], $instrumentId, $signalKey, $side[0]);
        $row = $db->getOne(
            "INSERT INTO orders_trading (account_id, strategy_version_id, instrument_id, client_order_id, side, order_type, qty,
                                         limit_price, stop_price, trail_percent, reference_price, reason)
             VALUES (:aid, :svid, :iid, :cid, :side, :type, :qty, :limit, :stop, :trail, :ref, :reason)
             ON CONFLICT (client_order_id) DO NOTHING
             RETURNING *",
            [
                ':aid' => (int)$account['id'], ':svid' => $opts['strategy_version_id'] ?? null, ':iid' => $instrumentId,
                ':cid' => $clientId, ':side' => $side, ':type' => $type, ':qty' => self::dec($qty),
                ':limit' => isset($opts['limit_price']) ? self::dec($opts['limit_price']) : null,
                ':stop' => isset($opts['stop_price']) ? self::dec($opts['stop_price']) : null,
                ':trail' => isset($opts['trail_percent']) ? self::dec($opts['trail_percent']) : null,
                ':ref' => isset($opts['reference_price']) ? self::dec($opts['reference_price']) : null,
                ':reason' => $opts['reason'] ?? null,
            ]
        );
        if (!$row) {
            TradingAudit::log($db, 'orders', 'order_duplicate_blocked', ['client_order_id' => $clientId], 'warning', (int)$account['id']);
            return null;
        }
        return $row;
    }

    /**
     * Prüft eine Order und übermittelt sie an den Broker
     *
     * @param object        $db      ApiDatabase
     * @param array         $account Zeile aus accounts_trading
     * @param array         $order   Zeile aus orders_trading (Status new)
     * @param TradingBroker $broker  Adapter des Kontos
     * @param array         $ctx     Kontext der Risikoprüfung (TradingRisk::checkOrder) + venue_symbol
     * @return array Aktuelle Orderzeile
     */
    public static function submit($db, array $account, array $order, TradingBroker $broker, array $ctx): array {
        if ($order['status'] !== 'new') return $order;

        // Kontostand frisch lesen: vorherige Orders desselben Laufs haben ihn verändert
        $account = $db->getOne("SELECT * FROM accounts_trading WHERE id = :id", [':id' => $account['id']]);
        $risk = TradingRisk::checkOrder($db, $account, $order, $ctx);
        if ($risk['ok'] && !in_array($order['order_type'], $broker->capabilities($ctx['venue_symbol'] ?? ''), true)) {
            $risk['ok'] = false;
            $risk['reason'] = 'order_type_unsupported';
        }
        if (!$risk['ok']) {
            $db->execute(
                "UPDATE orders_trading SET status = 'risk_rejected', error = :reason, risk_check = :risk::jsonb, mtime = now() WHERE id = :id",
                [':reason' => $risk['reason'], ':risk' => json_encode($risk['checks']), ':id' => $order['id']]
            );
            TradingAudit::log($db, 'risk', 'order_risk_rejected', ['reason' => $risk['reason'], 'checks' => $risk['checks']], 'warning', (int)$account['id'], (int)$order['id']);
            return self::load($db, (int)$order['id']);
        }

        // Zustand VOR dem Aufruf festhalten — siehe Klassenkommentar
        $db->execute(
            "UPDATE orders_trading SET status = 'submitting', submitted_at = now(), risk_check = :risk::jsonb, mtime = now() WHERE id = :id",
            [':risk' => json_encode($risk['checks']), ':id' => $order['id']]
        );
        $order = self::load($db, (int)$order['id']);

        try {
            $state = $broker->placeOrder($order + ['venue_symbol' => $ctx['venue_symbol'] ?? '']);
        } catch (TradingHttpUncertain $e) {
            TradingAudit::log($db, 'broker', 'order_uncertain', ['message' => $e->getMessage()], 'warning', (int)$account['id'], (int)$order['id']);
            return $order;
        } catch (Throwable $e) {
            $db->execute("UPDATE orders_trading SET status = 'error', error = :err, mtime = now() WHERE id = :id",
                [':err' => $e->getMessage(), ':id' => $order['id']]);
            TradingAudit::log($db, 'broker', 'order_error', ['message' => $e->getMessage()], 'critical', (int)$account['id'], (int)$order['id']);
            if ($account['kind'] !== 'strategy_paper' && $account['kind'] !== 'portfolio_paper') TradingRisk::recordError($db, 'Order ' . $order['id'] . ': ' . $e->getMessage());
            return self::load($db, (int)$order['id']);
        }

        TradingAudit::log($db, 'orders', 'order_submitted',
            ['side' => $order['side'], 'type' => $order['order_type'], 'qty' => (float)$order['qty'], 'broker_order_id' => $state['broker_order_id']],
            'info', (int)$account['id'], (int)$order['id']);
        return self::applyState($db, $order, $state);
    }

    /**
     * Verbucht einen vom Broker gemeldeten Orderzustand
     *
     * @param object $db    ApiDatabase
     * @param array  $order Zeile aus orders_trading
     * @param array  $state Einheitlicher Orderzustand des Adapters (kumuliert)
     * @return array Aktuelle Orderzeile
     */
    public static function applyState($db, array $order, array $state): array {
        $pdo = $db->getPDO();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $db->beginTransaction();
        try {
            $cur = $db->getOne("SELECT * FROM orders_trading WHERE id = :id FOR UPDATE", [':id' => $order['id']]);
            $prevQty = (float)$cur['filled_qty'];
            // Nie mehr als die Ordermenge verbuchen, was auch immer gemeldet wird
            $cumQty = min((float)$state['filled_qty'], (float)$cur['qty']);
            $delta = $cumQty - $prevQty;

            if ($delta > 1e-12 && !empty($state['avg_price'])) {
                $prevCost = $prevQty * (float)($cur['avg_fill_price'] ?? 0);
                $price = max(($cumQty * (float)$state['avg_price'] - $prevCost) / $delta, 0.0);
                $fee = max((float)$state['fee'] - (float)$cur['fees'], 0.0);
                $fill = $db->getOne(
                    "INSERT INTO fills_trading (order_id, broker_fill_id, qty, price, fee)
                     VALUES (:oid, :fid, :qty, :price, :fee)
                     ON CONFLICT (order_id, broker_fill_id) DO NOTHING RETURNING id",
                    [':oid' => $cur['id'], ':fid' => 'cum:' . self::dec($cumQty), ':qty' => self::dec($delta), ':price' => self::dec($price), ':fee' => self::dec($fee)]
                );
                if ($fill) {
                    self::book($db, $cur, $delta, $price, $fee);
                }
            }

            // Ein Endzustand wird nicht mehr von einem offenen überschrieben (verspätete Meldungen)
            $final = ['filled', 'canceled', 'rejected', 'expired'];
            $status = in_array($cur['status'], $final, true) ? $cur['status'] : $state['status'];
            if ($cumQty >= (float)$cur['qty'] * 0.9999999 && $cumQty > 0) $status = 'filled';
            $db->execute(
                "UPDATE orders_trading SET status = :status, broker_order_id = COALESCE(NULLIF(:boid, ''), broker_order_id),
                        filled_qty = :filled, avg_fill_price = COALESCE(:avg, avg_fill_price), fees = GREATEST(fees, :fees),
                        error = COALESCE(NULLIF(:msg, ''), error), mtime = now()
                 WHERE id = :id",
                [':status' => $status, ':boid' => (string)($state['broker_order_id'] ?? ''), ':filled' => self::dec(max($cumQty, $prevQty)),
                 ':avg' => !empty($state['avg_price']) ? self::dec($state['avg_price']) : null, ':fees' => self::dec($state['fee'] ?? 0),
                 ':msg' => (string)($state['message'] ?? ''), ':id' => $cur['id']]
            );
            if ($ownTx) $db->commit();

            if ($status !== $cur['status']) {
                TradingAudit::log($db, 'orders', 'order_' . $status,
                    ['filled_qty' => $cumQty, 'avg_price' => $state['avg_price'] ?? null, 'message' => $state['message'] ?? ''],
                    in_array($status, ['rejected', 'error'], true) ? 'warning' : 'info', (int)$cur['account_id'], (int)$cur['id']);
            }
        } catch (Throwable $e) {
            if ($ownTx) $db->rollBack();
            throw $e;
        }
        return self::load($db, (int)$order['id']);
    }

    /**
     * Bucht eine Ausführung auf Guthaben und Bestand des Kontos
     *
     * @param object $db    ApiDatabase
     * @param array  $order Orderzeile
     * @param float  $qty   ausgeführte Stückzahl
     * @param float  $price Ausführungskurs
     * @param float  $fee   Gebühr
     */
    private static function book($db, array $order, float $qty, float $price, float $fee): void {
        $value = $qty * $price;
        if ($order['side'] === 'buy') {
            $db->execute("UPDATE accounts_trading SET cash = cash - :v WHERE id = :id", [':v' => self::dec($value + $fee), ':id' => $order['account_id']]);
            $db->execute(
                "INSERT INTO positions_trading (account_id, instrument_id, qty, avg_price, mtime)
                 VALUES (:aid, :iid, :qty, :price, now())
                 ON CONFLICT (account_id, instrument_id) DO UPDATE SET
                    avg_price = CASE WHEN positions_trading.qty + EXCLUDED.qty > 0
                                     THEN (positions_trading.qty * positions_trading.avg_price + EXCLUDED.qty * EXCLUDED.avg_price)
                                          / (positions_trading.qty + EXCLUDED.qty) ELSE 0 END,
                    qty = positions_trading.qty + EXCLUDED.qty, mtime = now()",
                [':aid' => $order['account_id'], ':iid' => $order['instrument_id'], ':qty' => self::dec($qty), ':price' => self::dec(($value + $fee) / $qty)]
            );
        } else {
            $db->execute("UPDATE accounts_trading SET cash = cash + :v WHERE id = :id", [':v' => self::dec($value - $fee), ':id' => $order['account_id']]);
            $db->execute(
                "UPDATE positions_trading SET
                    realized_pnl = realized_pnl + (:value::numeric - avg_price * LEAST(qty, :qty::numeric)),
                    qty = GREATEST(qty - :qty2::numeric, 0), mtime = now()
                 WHERE account_id = :aid AND instrument_id = :iid",
                [':value' => self::dec($value - $fee), ':qty' => self::dec($qty), ':qty2' => self::dec($qty), ':aid' => $order['account_id'], ':iid' => $order['instrument_id']]
            );
        }
    }

    /**
     * Gleicht alle offenen Orders eines Kontos mit dem Broker ab
     *
     * Auch der Wiederanlauf nach einem Serverneustart: was als 'submitting'
     * stehen blieb, wird über unsere Kennung beim Broker gesucht.
     *
     * @param object        $db      ApiDatabase
     * @param array         $account Zeile aus accounts_trading
     * @param TradingBroker $broker  Adapter des Kontos
     * @return int Zahl der abgeglichenen Orders
     */
    public static function sync($db, array $account, TradingBroker $broker): int {
        $orders = $db->getAll(
            "SELECT o.*, s.venue_symbol, EXTRACT(EPOCH FROM (now() - o.submitted_at)) AS age
             FROM orders_trading o
             LEFT JOIN instrument_symbols_trading s ON s.instrument_id = o.instrument_id AND s.venue = :venue
             WHERE o.account_id = :aid AND o.status IN ('submitting', 'submitted', 'partially_filled')
             ORDER BY o.id",
            [':aid' => $account['id'], ':venue' => $account['venue'] ?? '']
        ) ?: [];
        $n = 0;
        foreach ($orders as $order) {
            try {
                if ($order['status'] === 'submitting' || empty($order['broker_order_id'])) {
                    $state = $broker->findOrder($order);
                    if ($state === null) {
                        // Beim Broker unbekannt. Kurz nach dem Absenden kann die Order noch
                        // unterwegs sein; danach gilt sie als nicht angekommen.
                        $usesBroker = in_array($account['kind'], ['live', 'broker_paper'], true);
                        if ((float)$order['age'] < 120 && $usesBroker) continue;
                        $state = ['broker_order_id' => '', 'status' => 'rejected', 'filled_qty' => (float)$order['filled_qty'],
                                  'avg_price' => null, 'fee' => (float)$order['fees'], 'message' => 'Beim Broker nicht angekommen'];
                        // Der Simulator kennt kein „unterwegs“: dort wird die Order einfach erneut bewertet
                        if (!$usesBroker) $state = $broker->getOrder($order);
                    }
                } else {
                    $state = $broker->getOrder($order);
                }
                self::applyState($db, $order, $state);
                $n++;
            } catch (Throwable $e) {
                TradingAudit::log($db, 'broker', 'sync_error', ['message' => $e->getMessage()], 'warning', (int)$account['id'], (int)$order['id']);
                if (in_array($account['kind'], ['live', 'broker_paper'], true)) TradingRisk::recordError($db, 'Abgleich Order ' . $order['id'] . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    /**
     * Storniert offene Orders eines Kontos
     *
     * @param object        $db           ApiDatabase
     * @param array         $account      Zeile aus accounts_trading
     * @param TradingBroker $broker       Adapter des Kontos
     * @param string        $reason       Grund fürs Protokoll
     * @param int|null      $instrumentId nur dieses Instrument
     * @param string|null   $side         nur diese Richtung
     * @return int Zahl der stornierten Orders
     */
    public static function cancelOpen($db, array $account, TradingBroker $broker, string $reason, ?int $instrumentId = null, ?string $side = null): int {
        $orders = $db->getAll(
            "SELECT * FROM orders_trading
             WHERE account_id = :aid AND status IN ('submitting', 'submitted', 'partially_filled')
               AND (:iid::int IS NULL OR instrument_id = :iid2::int)
               AND (:side::text IS NULL OR side = :side2::text)",
            [':aid' => $account['id'], ':iid' => $instrumentId, ':iid2' => $instrumentId, ':side' => $side, ':side2' => $side]
        ) ?: [];
        $n = 0;
        foreach ($orders as $order) {
            try {
                if (!empty($order['broker_order_id']) && in_array($account['kind'], ['live', 'broker_paper'], true)) {
                    $broker->cancelOrder($order);
                    // Bis zum Storno kann noch etwas ausgeführt worden sein — erst verbuchen
                    self::applyState($db, $order, $broker->getOrder($order));
                }
                $db->execute("UPDATE orders_trading SET status = 'canceled', error = :reason, mtime = now() WHERE id = :id AND status IN ('submitting', 'submitted', 'partially_filled')",
                    [':reason' => $reason, ':id' => $order['id']]);
                TradingAudit::log($db, 'orders', 'order_canceled', ['reason' => $reason], 'info', (int)$account['id'], (int)$order['id']);
                $n++;
            } catch (Throwable $e) {
                TradingAudit::log($db, 'broker', 'cancel_error', ['message' => $e->getMessage()], 'critical', (int)$account['id'], (int)$order['id']);
            }
        }
        return $n;
    }

    /**
     * Lädt eine Orderzeile
     *
     * @param object $db ApiDatabase
     * @param int    $id Order-ID
     * @return array
     */
    public static function load($db, int $id): array {
        return $db->getOne("SELECT * FROM orders_trading WHERE id = :id", [':id' => $id]);
    }

    /**
     * Dezimalzahl als Text ohne Exponentialschreibweise (für numeric-Spalten)
     *
     * @param mixed $v Wert
     * @return string
     */
    public static function dec($v): string {
        return number_format((float)$v, 10, '.', '');
    }
}
