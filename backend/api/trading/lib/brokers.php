<?php
// backend/api/trading/lib/brokers.php

/**
 * Broker-Architektur
 *
 * Jeder Broker ist ein Adapter hinter derselben Schnittstelle. Das
 * Ordermanagement (TradingOrders) kennt nur diese Schnittstelle; ein weiterer
 * Broker ist eine weitere Klasse plus ein Eintrag in TradingBrokers::ADAPTERS.
 *
 * Umgesetzt:
 *   paper   interne Simulation (Markt, Limit, Stop, Stop-Limit, Trailing-Stop)
 *   kraken  Kraken Spot REST API (/0/private/*)
 *   alpaca  Alpaca Trading API v2 (Papierkonto und Live)
 *
 * Geprüft, nicht umgesetzt (Begründung in dev/trading.md):
 *   Interactive Brokers — Client Portal API verlangt ein lokal laufendes Gateway
 *     mit täglicher interaktiver Anmeldung, das widerspricht dem unbeaufsichtigten Betrieb
 *   Coinbase Advanced Trade — machbar (JWT/ES256), aber ohne Testzugang nicht prüfbar
 *
 * Einheitlicher Orderzustand, den getOrder()/placeOrder() liefern:
 *   ['broker_order_id' => string, 'status' => submitted|partially_filled|filled|canceled|rejected|expired,
 *    'filled_qty' => float (kumuliert), 'avg_price' => float|null, 'fee' => float (kumuliert), 'message' => string]
 */
interface TradingBroker {
    /** Unterstützte Orderarten für ein Instrument: market, limit, stop, stop_limit, trailing_stop */
    public function capabilities(string $venueSymbol = ''): array;

    /** Verfügbares Guthaben je Währung/Asset: ['EUR' => 1234.5, 'BTC' => 0.1] */
    public function getBalances(): array;

    /** Bestand eines Instruments beim Broker (Stückzahl) */
    public function getPositionQty(string $venueSymbol): float;

    /** Aktueller Kurs: ['bid' => float, 'ask' => float, 'price' => float, 'ts' => int] */
    public function getQuote(string $venueSymbol): array;

    /**
     * Order aufgeben
     *
     * @param array $order id, client_order_id, venue_symbol, side (buy|sell), order_type, qty,
     *                     limit_price, stop_price, trail_percent
     * @return array Einheitlicher Orderzustand
     */
    public function placeOrder(array $order): array;

    /** Aktuellen Zustand einer aufgegebenen Order abfragen ($order = Zeile aus orders_trading + venue_symbol) */
    public function getOrder(array $order): array;

    /** Order anhand unserer Kennung suchen — null, wenn der Broker sie nicht kennt */
    public function findOrder(array $order): ?array;

    /** Order stornieren */
    public function cancelOrder(array $order): bool;

    /** Alle offenen Orders stornieren (Not-Aus); Rückgabe: Anzahl */
    public function cancelAll(): int;

    /** Offene Orders beim Broker (Rohdaten, für die Überwachung) */
    public function getOpenOrders(): array;

    /** Abgeschlossene Orders seit $since (Unix-Zeit) beim Broker (Rohdaten, für die Historie) */
    public function getOrderHistory(int $since): array;
}

/**
 * Fabrik und Verzeichnis der Adapter
 */
class TradingBrokers {
    const ADAPTERS = ['paper' => 'TradingPaperBroker', 'kraken' => 'TradingKrakenBroker', 'alpaca' => 'TradingAlpacaBroker'];

    /** @var callable|null Ersatzfabrik für Tests: fn(array $account) => TradingBroker */
    public static $factory = null;

    /**
     * Liefert den Adapter für ein Konto
     *
     * Papierkonten bekommen immer den internen Simulator. Ein echter Adapter
     * entsteht nur für Konten an einer Broker-Verbindung ('live' = echtes Geld,
     * 'broker_paper' = Testumgebung des Brokers) — und im Testmodus überhaupt nicht.
     *
     * @param object $db      ApiDatabase
     * @param array  $account Zeile aus accounts_trading
     * @param array  $quotes  Aktuelle Kurse [instrument_id => quote] für den Simulator
     * @return TradingBroker
     */
    public static function forAccount($db, array $account, array $quotes = []): TradingBroker {
        if (self::$factory !== null) {
            return (self::$factory)($account, $quotes);
        }
        if (!in_array($account['kind'], ['live', 'broker_paper'], true)) {
            return new TradingPaperBroker($db, $quotes);
        }
        if (TradingGuard::isTestMode()) {
            throw new RuntimeException('Testmodus: echte Broker-Adapter sind gesperrt');
        }
        $conn = $db->getOne(
            "SELECT * FROM broker_connections_trading WHERE id = :id AND active",
            [':id' => $account['broker_connection_id']]
        );
        if (!$conn) throw new RuntimeException('Keine aktive Broker-Verbindung für Konto ' . $account['id']);
        return self::fromConnection($conn);
    }

    /**
     * Baut einen Adapter aus einer Verbindungszeile
     *
     * @param array $conn Zeile aus broker_connections_trading
     * @return TradingBroker
     */
    public static function fromConnection(array $conn): TradingBroker {
        $class = self::ADAPTERS[$conn['adapter']] ?? null;
        if ($class === null || $conn['adapter'] === 'paper') {
            throw new RuntimeException('Unbekannter Broker-Adapter: ' . $conn['adapter']);
        }
        return new $class(secretDecrypt($conn['api_key_enc']), secretDecrypt($conn['api_secret_enc']), $conn['environment'] === 'live');
    }
}

/**
 * Interner Simulator für Papierkonten
 *
 * Marktorders werden sofort zum aktuellen Brief- (Kauf) bzw. Geldkurs (Verkauf)
 * zuzüglich Slippage ausgeführt. Limit-, Stop- und Trailing-Orders bleiben offen
 * und werden bei jedem Lauf gegen den aktuellen Kurs geprüft.
 */
class TradingPaperBroker implements TradingBroker {
    private $db;
    private $quotes;
    private $fee;
    private $slip;

    public function __construct($db, array $quotes) {
        $this->db = $db;
        $this->quotes = $quotes;
        $settings = TradingConfig::settings($db);
        $this->fee = (float)($settings['fee_bps'] ?? 40) / 10000;
        $this->slip = (float)($settings['slippage_bps'] ?? 10) / 10000;
    }

    public function capabilities(string $venueSymbol = ''): array {
        return ['market', 'limit', 'stop', 'stop_limit', 'trailing_stop'];
    }

    public function getBalances(): array {
        return [];
    }

    public function getPositionQty(string $venueSymbol): float {
        return INF;   // Der Simulator führt keinen eigenen Bestand; massgeblich ist positions_trading
    }

    public function getQuote(string $venueSymbol): array {
        throw new RuntimeException('Der Simulator bezieht Kurse über TradingMarketData::quotes');
    }

    public function placeOrder(array $order): array {
        return $this->evaluate($order, true);
    }

    public function getOrder(array $order): array {
        return $this->evaluate($order, false);
    }

    public function findOrder(array $order): ?array {
        return null;   // Es gibt keine Gegenseite, bei der eine Order unbemerkt angekommen sein könnte
    }

    public function cancelOrder(array $order): bool {
        return true;
    }

    public function cancelAll(): int {
        return 0;
    }

    public function getOpenOrders(): array {
        return [];
    }

    public function getOrderHistory(int $since): array {
        return [];
    }

    /**
     * Prüft, ob eine Order beim aktuellen Kurs ausgeführt wird
     *
     * @param array $order Orderzeile
     * @param bool  $isNew true beim Aufgeben
     * @return array Einheitlicher Orderzustand
     */
    private function evaluate(array $order, bool $isNew): array {
        $state = ['broker_order_id' => 'paper-' . $order['id'], 'status' => 'submitted',
                  'filled_qty' => (float)($order['filled_qty'] ?? 0), 'avg_price' => null, 'fee' => (float)($order['fees'] ?? 0), 'message' => ''];
        $q = $this->quotes[(int)$order['instrument_id']] ?? null;
        if ($q === null) {
            if ($isNew && $order['order_type'] === 'market') {
                $state['status'] = 'rejected';
                $state['message'] = 'Kein aktueller Kurs';
            }
            return $state;
        }

        $buy = $order['side'] === 'buy';
        $touch = $buy ? $q['ask'] : $q['bid'];
        $fillPrice = null;

        switch ($order['order_type']) {
            case 'market':
                $fillPrice = $touch * ($buy ? 1 + $this->slip : 1 - $this->slip);
                break;
            case 'limit':
                if ($buy ? $touch <= $order['limit_price'] : $touch >= $order['limit_price']) {
                    $fillPrice = (float)$order['limit_price'];
                }
                break;
            case 'stop':
                if ($buy ? $touch >= $order['stop_price'] : $touch <= $order['stop_price']) {
                    $fillPrice = $touch * ($buy ? 1 + $this->slip : 1 - $this->slip);
                }
                break;
            case 'stop_limit':
                $triggered = $buy ? $touch >= $order['stop_price'] : $touch <= $order['stop_price'];
                if ($triggered && ($buy ? $touch <= $order['limit_price'] : $touch >= $order['limit_price'])) {
                    $fillPrice = (float)$order['limit_price'];
                }
                break;
            case 'trailing_stop':
                // Bezugskurs nachziehen (Verkauf: Höchstkurs seit Aufgabe), Stopp im Abstand trail_percent
                $ref = (float)($order['trail_ref_price'] ?? 0);
                $ref = $buy ? ($ref > 0 ? min($ref, $touch) : $touch) : max($ref, $touch);
                $this->db->execute("UPDATE orders_trading SET trail_ref_price = :ref WHERE id = :id", [':ref' => $ref, ':id' => $order['id']]);
                $stop = $ref * ($buy ? 1 + $order['trail_percent'] / 100 : 1 - $order['trail_percent'] / 100);
                if ($buy ? $touch >= $stop : $touch <= $stop) {
                    $fillPrice = $touch * ($buy ? 1 + $this->slip : 1 - $this->slip);
                }
                break;
            default:
                $state['status'] = 'rejected';
                $state['message'] = 'Orderart nicht unterstützt: ' . $order['order_type'];
                return $state;
        }

        if ($fillPrice !== null) {
            $qty = (float)$order['qty'];
            $state['status'] = 'filled';
            $state['filled_qty'] = $qty;
            $state['avg_price'] = $fillPrice;
            $state['fee'] = $qty * $fillPrice * $this->fee;
        }
        return $state;
    }
}

/**
 * Kraken Spot REST API
 *
 * Dokumentation: https://docs.kraken.com/api/ — verwendet werden ausschliesslich
 * /0/private/Balance, AddOrder, QueryOrders, OpenOrders, ClosedOrders,
 * CancelOrder, CancelAll sowie /0/public/Ticker und AssetPairs.
 *
 * Kraken hat für den Kassamarkt keine Testumgebung. In der Umgebung „sandbox“
 * gehen Orders deshalb mit validate=true hinaus: Kraken prüft sie vollständig,
 * führt sie aber nicht aus. Unsere Kennung reist als userref (int32) mit, darüber
 * findet findOrder() eine Order wieder, deren Bestätigung verloren ging.
 */
class TradingKrakenBroker implements TradingBroker {
    private $key;
    private $secret;
    private $live;

    public function __construct(string $key, string $secret, bool $live) {
        $this->key = $key;
        $this->secret = $secret;
        $this->live = $live;
    }

    public function capabilities(string $venueSymbol = ''): array {
        return ['market', 'limit', 'stop', 'stop_limit', 'trailing_stop'];
    }

    /**
     * Signatur eines privaten Aufrufs: HMAC-SHA512(Pfad + SHA256(nonce + POST-Daten)) mit dem Base64-dekodierten Secret
     *
     * @param string $path     z. B. /0/private/AddOrder
     * @param string $nonce    Nonce
     * @param string $postData URL-kodierte POST-Daten (enthalten die Nonce)
     * @param string $secret   API-Secret (Base64)
     * @return string Base64-Signatur
     */
    public static function sign(string $path, string $nonce, string $postData, string $secret): string {
        return base64_encode(hash_hmac('sha512', $path . hash('sha256', $nonce . $postData, true), base64_decode($secret), true));
    }

    /**
     * Privater API-Aufruf
     *
     * @param string $method Endpunkt, z. B. AddOrder
     * @param array  $params Parameter
     * @return array Inhalt von result
     */
    private function call(string $method, array $params = []): array {
        $path = '/0/private/' . $method;
        $nonce = (string)(int)(microtime(true) * 1000);
        $postData = http_build_query(['nonce' => $nonce] + $params, '', '&');
        [$code, $body] = TradingHttp::request('POST', 'https://api.kraken.com' . $path, [
            'API-Key: ' . $this->key,
            'API-Sign: ' . self::sign($path, $nonce, $postData, $this->secret),
            'Content-Type: application/x-www-form-urlencoded',
        ], $postData);
        $data = json_decode($body, true);
        if (!is_array($data)) throw new RuntimeException('Kraken: unlesbare Antwort (HTTP ' . $code . ')');
        if (!empty($data['error'])) throw new RuntimeException('Kraken: ' . implode(', ', $data['error']));
        return $data['result'] ?? [];
    }

    public function getBalances(): array {
        $out = [];
        foreach ($this->call('Balance') as $asset => $amount) {
            $out[self::assetName($asset)] = (float)$amount;
        }
        return $out;
    }

    /**
     * Kraken-Assetcode in die übliche Schreibweise (ZEUR → EUR, XXBT → BTC)
     *
     * @param string $asset Kraken-Code
     * @return string
     */
    public static function assetName(string $asset): string {
        $asset = preg_replace('/\.[A-Z]$/', '', $asset);   // Varianten wie EUR.F, XBT.M
        if (strlen($asset) === 4 && ($asset[0] === 'X' || $asset[0] === 'Z')) $asset = substr($asset, 1);
        return ['XBT' => 'BTC', 'XDG' => 'DOGE'][$asset] ?? $asset;
    }

    public function getPositionQty(string $venueSymbol): float {
        $pair = TradingHttp::getJson('https://api.kraken.com/0/public/AssetPairs?pair=' . rawurlencode($venueSymbol));
        $info = current($pair['result'] ?? []);
        if (!$info) throw new RuntimeException('Kraken: unbekanntes Paar ' . $venueSymbol);
        return (float)($this->getBalances()[self::assetName($info['base'])] ?? 0);
    }

    public function getQuote(string $venueSymbol): array {
        $data = TradingHttp::getJson('https://api.kraken.com/0/public/Ticker?pair=' . rawurlencode($venueSymbol));
        if (!empty($data['error'])) throw new RuntimeException('Kraken: ' . implode(', ', $data['error']));
        $t = current($data['result'] ?? []);
        if (!$t) throw new RuntimeException('Kraken: kein Kurs für ' . $venueSymbol);
        return ['bid' => (float)$t['b'][0], 'ask' => (float)$t['a'][0], 'price' => ((float)$t['b'][0] + (float)$t['a'][0]) / 2, 'ts' => time()];
    }

    public function placeOrder(array $order): array {
        $types = ['market' => 'market', 'limit' => 'limit', 'stop' => 'stop-loss', 'stop_limit' => 'stop-loss-limit', 'trailing_stop' => 'trailing-stop'];
        if (!isset($types[$order['order_type']])) throw new RuntimeException('Kraken: Orderart nicht unterstützt: ' . $order['order_type']);

        $params = [
            'pair'      => $order['venue_symbol'],
            'type'      => $order['side'],
            'ordertype' => $types[$order['order_type']],
            'volume'    => self::num($order['qty']),
            'userref'   => (int)$order['id'],
        ];
        switch ($order['order_type']) {
            case 'limit':         $params['price'] = self::num($order['limit_price']); break;
            case 'stop':          $params['price'] = self::num($order['stop_price']); break;
            case 'stop_limit':    $params['price'] = self::num($order['stop_price']); $params['price2'] = self::num($order['limit_price']); break;
            case 'trailing_stop': $params['price'] = '+' . self::num($order['trail_percent']) . '%'; break;
        }
        if (!$this->live) $params['validate'] = 'true';

        $result = $this->call('AddOrder', $params);
        if (!$this->live) {
            // Nur geprüft, nicht ausgeführt — es entsteht keine Order
            return ['broker_order_id' => '', 'status' => 'canceled', 'filled_qty' => 0.0, 'avg_price' => null, 'fee' => 0.0,
                    'message' => 'Von Kraken geprüft (validate), nicht ausgeführt: ' . ($result['descr']['order'] ?? '')];
        }
        $txid = $result['txid'][0] ?? '';
        if ($txid === '') throw new TradingHttpUncertain('Kraken: Antwort ohne Order-ID');
        return ['broker_order_id' => $txid, 'status' => 'submitted', 'filled_qty' => 0.0, 'avg_price' => null, 'fee' => 0.0, 'message' => ''];
    }

    public function getOrder(array $order): array {
        $result = $this->call('QueryOrders', ['txid' => $order['broker_order_id']]);
        $o = $result[$order['broker_order_id']] ?? null;
        if (!$o) throw new RuntimeException('Kraken: Order ' . $order['broker_order_id'] . ' nicht gefunden');
        return self::normalize($order['broker_order_id'], $o);
    }

    public function findOrder(array $order): ?array {
        foreach ($this->call('OpenOrders', ['userref' => (int)$order['id']])['open'] ?? [] as $txid => $o) {
            return self::normalize($txid, $o);
        }
        foreach ($this->call('ClosedOrders', ['userref' => (int)$order['id']])['closed'] ?? [] as $txid => $o) {
            return self::normalize($txid, $o);
        }
        return null;
    }

    public function cancelOrder(array $order): bool {
        return (int)($this->call('CancelOrder', ['txid' => $order['broker_order_id']])['count'] ?? 0) > 0;
    }

    public function cancelAll(): int {
        return (int)($this->call('CancelAll')['count'] ?? 0);
    }

    public function getOpenOrders(): array {
        return $this->call('OpenOrders')['open'] ?? [];
    }

    public function getOrderHistory(int $since): array {
        return $this->call('ClosedOrders', ['start' => $since])['closed'] ?? [];
    }

    /**
     * Kraken-Orderzustand in den einheitlichen Zustand übersetzen
     *
     * @param string $txid Order-ID
     * @param array  $o    Orderinfo von Kraken
     * @return array
     */
    public static function normalize(string $txid, array $o): array {
        $filled = (float)($o['vol_exec'] ?? 0);
        $vol = (float)($o['vol'] ?? 0);
        $map = ['pending' => 'submitted', 'open' => 'submitted', 'closed' => 'filled', 'canceled' => 'canceled', 'expired' => 'expired'];
        $status = $map[$o['status'] ?? ''] ?? 'submitted';
        if ($status === 'submitted' && $filled > 0) $status = 'partially_filled';
        // „closed“ mit weniger als dem vollen Volumen gibt es bei Teilausführung mit anschliessendem Storno nicht — das meldet Kraken als canceled
        return ['broker_order_id' => $txid, 'status' => $status, 'filled_qty' => $filled,
                'avg_price' => $filled > 0 ? (float)($o['price'] ?? 0) : null, 'fee' => (float)($o['fee'] ?? 0),
                'message' => (string)($o['reason'] ?? ''), 'qty' => $vol];
    }

    /**
     * Zahl ohne Exponentialschreibweise
     *
     * @param mixed $v Wert
     * @return string
     */
    private static function num($v): string {
        return rtrim(rtrim(number_format((float)$v, 10, '.', ''), '0'), '.');
    }
}

/**
 * Alpaca Trading API v2
 *
 * Dokumentation: https://docs.alpaca.markets/ — verwendet werden /v2/account,
 * /v2/positions, /v2/orders (POST, GET, DELETE), /v2/orders:by_client_order_id
 * und die Daten-API für Kurse. Umgebung „sandbox“ = paper-api.alpaca.markets.
 * Unsere client_order_id reist mit und macht das Aufgeben wiederholbar.
 *
 * Einschränkung laut Alpaca: Krypto kennt nur market, limit und stop_limit.
 */
class TradingAlpacaBroker implements TradingBroker {
    private $key;
    private $secret;
    private $base;

    public function __construct(string $key, string $secret, bool $live) {
        $this->key = $key;
        $this->secret = $secret;
        $this->base = $live ? 'https://api.alpaca.markets' : 'https://paper-api.alpaca.markets';
    }

    public function capabilities(string $venueSymbol = ''): array {
        // Krypto (Symbol mit Schrägstrich) kennt bei Alpaca nur diese drei Orderarten
        return strpos($venueSymbol, '/') !== false
            ? ['market', 'limit', 'stop_limit']
            : ['market', 'limit', 'stop', 'stop_limit', 'trailing_stop'];
    }

    /**
     * API-Aufruf
     *
     * @param string     $method HTTP-Methode
     * @param string     $path   Pfad ab /v2
     * @param array|null $body   JSON-Rumpf
     * @param string     $host   Abweichender Host (Daten-API)
     * @return array [Status, dekodierte Antwort]
     */
    private function call(string $method, string $path, ?array $body = null, string $host = ''): array {
        [$code, $resp] = TradingHttp::request($method, ($host ?: $this->base) . $path, [
            'APCA-API-KEY-ID: ' . $this->key,
            'APCA-API-SECRET-KEY: ' . $this->secret,
            'Content-Type: application/json',
        ], $body !== null ? json_encode($body) : null);
        $data = json_decode($resp, true);
        return [$code, is_array($data) ? $data : []];
    }

    /**
     * Wie call(), wirft aber bei jedem Status ausserhalb 2xx
     *
     * @param string     $method HTTP-Methode
     * @param string     $path   Pfad
     * @param array|null $body   JSON-Rumpf
     * @param string     $host   Abweichender Host
     * @return array Dekodierte Antwort
     */
    private function ok(string $method, string $path, ?array $body = null, string $host = ''): array {
        [$code, $data] = $this->call($method, $path, $body, $host);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Alpaca: HTTP ' . $code . ' ' . ($data['message'] ?? ''));
        }
        return $data;
    }

    public function getBalances(): array {
        $acc = $this->ok('GET', '/v2/account');
        if (!empty($acc['trading_blocked']) || !empty($acc['account_blocked'])) {
            throw new RuntimeException('Alpaca: Konto ist für den Handel gesperrt');
        }
        return [(string)($acc['currency'] ?? 'USD') => (float)($acc['cash'] ?? 0)];
    }

    public function getPositionQty(string $venueSymbol): float {
        // Positionen führt Alpaca ohne Schrägstrich (BTC/USD → BTCUSD)
        [$code, $pos] = $this->call('GET', '/v2/positions/' . rawurlencode(str_replace('/', '', $venueSymbol)));
        if ($code === 404) return 0.0;
        if ($code !== 200) throw new RuntimeException('Alpaca: HTTP ' . $code . ' ' . ($pos['message'] ?? ''));
        return (float)($pos['qty_available'] ?? $pos['qty'] ?? 0);
    }

    public function getQuote(string $venueSymbol): array {
        $crypto = strpos($venueSymbol, '/') !== false;
        $path = ($crypto ? '/v1beta3/crypto/us/latest/quotes' : '/v2/stocks/quotes/latest') . '?symbols=' . rawurlencode($venueSymbol);
        $q = $this->ok('GET', $path, null, 'https://data.alpaca.markets')['quotes'][$venueSymbol] ?? null;
        if (!$q) throw new RuntimeException('Alpaca: kein Kurs für ' . $venueSymbol);
        return ['bid' => (float)$q['bp'], 'ask' => (float)$q['ap'], 'price' => ((float)$q['bp'] + (float)$q['ap']) / 2, 'ts' => strtotime($q['t']) ?: time()];
    }

    public function placeOrder(array $order): array {
        $crypto = strpos($order['venue_symbol'], '/') !== false;
        if ($crypto && !in_array($order['order_type'], ['market', 'limit', 'stop_limit'], true)) {
            throw new RuntimeException('Alpaca: Krypto unterstützt nur market, limit und stop_limit');
        }
        $body = [
            'symbol'          => $order['venue_symbol'],
            'qty'             => (string)(float)$order['qty'],
            'side'            => $order['side'],
            'type'            => $order['order_type'],
            'time_in_force'   => 'gtc',
            'client_order_id' => $order['client_order_id'],
        ];
        if (in_array($order['order_type'], ['limit', 'stop_limit'], true)) $body['limit_price'] = (string)(float)$order['limit_price'];
        if (in_array($order['order_type'], ['stop', 'stop_limit'], true))  $body['stop_price'] = (string)(float)$order['stop_price'];
        if ($order['order_type'] === 'trailing_stop') $body['trail_percent'] = (string)(float)$order['trail_percent'];

        [$code, $data] = $this->call('POST', '/v2/orders', $body);
        if ($code === 422 && stripos($data['message'] ?? '', 'client_order_id') !== false) {
            // Diese Kennung gibt es schon: die Order ist bei einem früheren Versuch angekommen
            $found = $this->findOrder($order);
            if ($found) return $found;
        }
        if ($code < 200 || $code >= 300) {
            return ['broker_order_id' => '', 'status' => 'rejected', 'filled_qty' => 0.0, 'avg_price' => null, 'fee' => 0.0,
                    'message' => 'HTTP ' . $code . ' ' . ($data['message'] ?? '')];
        }
        return self::normalize($data);
    }

    public function getOrder(array $order): array {
        return self::normalize($this->ok('GET', '/v2/orders/' . rawurlencode($order['broker_order_id'])));
    }

    public function findOrder(array $order): ?array {
        [$code, $data] = $this->call('GET', '/v2/orders:by_client_order_id?client_order_id=' . rawurlencode($order['client_order_id']));
        if ($code === 404) return null;
        if ($code !== 200) throw new RuntimeException('Alpaca: HTTP ' . $code . ' ' . ($data['message'] ?? ''));
        return self::normalize($data);
    }

    public function cancelOrder(array $order): bool {
        [$code] = $this->call('DELETE', '/v2/orders/' . rawurlencode($order['broker_order_id']));
        return $code === 204 || $code === 200;
    }

    public function cancelAll(): int {
        [$code, $data] = $this->call('DELETE', '/v2/orders');
        if ($code !== 207 && $code !== 200) throw new RuntimeException('Alpaca: Stornieren fehlgeschlagen (HTTP ' . $code . ')');
        return count($data);
    }

    public function getOpenOrders(): array {
        return $this->ok('GET', '/v2/orders?status=open&limit=500');
    }

    public function getOrderHistory(int $since): array {
        return $this->ok('GET', '/v2/orders?status=closed&limit=500&after=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $since)));
    }

    /**
     * Alpaca-Order in den einheitlichen Zustand übersetzen
     *
     * @param array $o Order von Alpaca
     * @return array
     */
    public static function normalize(array $o): array {
        $filled = (float)($o['filled_qty'] ?? 0);
        $map = [
            'filled' => 'filled', 'partially_filled' => 'partially_filled',
            'canceled' => 'canceled', 'expired' => 'expired', 'rejected' => 'rejected', 'done_for_day' => 'expired',
            'replaced' => 'canceled', 'stopped' => 'canceled', 'suspended' => 'canceled',
        ];
        return ['broker_order_id' => (string)($o['id'] ?? ''), 'status' => $map[$o['status'] ?? ''] ?? 'submitted',
                'filled_qty' => $filled, 'avg_price' => $filled > 0 ? (float)($o['filled_avg_price'] ?? 0) : null,
                'fee' => 0.0, 'message' => '', 'qty' => (float)($o['qty'] ?? 0)];
    }
}
