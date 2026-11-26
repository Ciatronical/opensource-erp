<?php
// backend/api/trading/lib/marketdata.php

/**
 * Marktdaten: Abruf, Prüfung, Ablage und Aufbereitung
 *
 * Quellen (alle über dokumentierte, öffentliche Endpunkte):
 *   binance  GET /api/v3/klines, /api/v3/ticker/bookTicker   — lange Historie, ohne Schlüssel
 *   kraken   GET /0/public/OHLC, /0/public/Ticker           — höchstens 720 Balken je Abruf
 *   alpaca   GET data.alpaca.markets …/bars, …/latest/trades — braucht API-Schlüssel
 *
 * Gespeichert werden nur ABGESCHLOSSENE Balken. Fehlerhafte Balken (Preis ≤ 0,
 * Hoch < Tief, Sprung über 80 % zum Vortag) werden verworfen und protokolliert.
 */
class TradingMarketData {

    /** Sekunden je Balken */
    const TIMEFRAMES = ['1d' => 86400];

    /**
     * Holt fehlende Balken aller aktiven Instrumente nach
     *
     * @param object $db ApiDatabase
     * @return array ['inserted' => int, 'rejected' => int, 'errors' => [text]]
     */
    public static function update($db): array {
        $settings = TradingConfig::settings($db);
        $source = $settings['market_data_source'] ?? 'binance';
        $result = ['inserted' => 0, 'rejected' => 0, 'errors' => []];

        $instruments = $db->getAll(
            "SELECT i.id, i.symbol, s.venue_symbol,
                    (SELECT EXTRACT(EPOCH FROM max(b.ts))::bigint FROM bars_trading b
                      WHERE b.instrument_id = i.id AND b.timeframe = '1d') AS last_ts,
                    (SELECT b.close FROM bars_trading b
                      WHERE b.instrument_id = i.id AND b.timeframe = '1d' ORDER BY b.ts DESC LIMIT 1) AS last_close
             FROM instruments_trading i
             JOIN instrument_symbols_trading s ON s.instrument_id = i.id AND s.venue = :venue
             WHERE i.active ORDER BY i.id",
            [':venue' => $source]
        ) ?: [];

        foreach ($instruments as $ins) {
            try {
                $since = $ins['last_ts'] ? (int)$ins['last_ts'] + 86400 : 0;
                // Der jüngste abgeschlossene Tagesbalken beginnt gestern 00:00 UTC
                if ($since > 0 && $since > intdiv(time(), 86400) * 86400 - 86400) continue;

                $bars = self::fetchBars($db, $source, $ins['venue_symbol'], '1d', $since);
                $prevClose = $ins['last_close'] !== null ? (float)$ins['last_close'] : null;
                foreach ($bars as $bar) {
                    $problem = self::validateBar($bar, $prevClose);
                    if ($problem !== null) {
                        $result['rejected']++;
                        TradingAudit::log($db, 'marketdata', 'bar_rejected',
                            ['symbol' => $ins['symbol'], 'ts' => gmdate('Y-m-d', $bar['ts']), 'problem' => $problem, 'bar' => $bar], 'warning');
                        continue;
                    }
                    $db->execute(
                        "INSERT INTO bars_trading (instrument_id, timeframe, ts, open, high, low, close, volume, source)
                         VALUES (:iid, '1d', to_timestamp(:ts) AT TIME ZONE 'UTC', :o, :h, :l, :c, :v, :src)
                         ON CONFLICT (instrument_id, timeframe, ts) DO NOTHING",
                        [':iid' => $ins['id'], ':ts' => $bar['ts'], ':o' => $bar['open'], ':h' => $bar['high'],
                         ':l' => $bar['low'], ':c' => $bar['close'], ':v' => $bar['volume'], ':src' => $source]
                    );
                    $result['inserted']++;
                    $prevClose = $bar['close'];
                }
            } catch (Throwable $e) {
                $result['errors'][] = $ins['symbol'] . ': ' . $e->getMessage();
            }
        }
        return $result;
    }

    /**
     * Prüft einen Balken auf Plausibilität
     *
     * @param array      $bar       ts, open, high, low, close, volume
     * @param float|null $prevClose Schlusskurs des vorherigen Balkens
     * @return string|null Beschreibung des Fehlers oder null, wenn in Ordnung
     */
    public static function validateBar(array $bar, ?float $prevClose): ?string {
        foreach (['open', 'high', 'low', 'close'] as $f) {
            if (!is_finite((float)$bar[$f]) || $bar[$f] <= 0) return "$f nicht positiv";
        }
        if ($bar['high'] < $bar['low']) return 'Hoch unter Tief';
        if ($bar['open'] > $bar['high'] * 1.0001 || $bar['open'] < $bar['low'] * 0.9999
            || $bar['close'] > $bar['high'] * 1.0001 || $bar['close'] < $bar['low'] * 0.9999) {
            return 'Eröffnung/Schluss ausserhalb der Spanne';
        }
        if ($bar['volume'] < 0) return 'Volumen negativ';
        if ($prevClose !== null && $prevClose > 0) {
            $change = $bar['close'] / $prevClose - 1;
            if ($change > 4.0 || $change < -0.8) return 'Kurssprung ' . round($change * 100) . ' % zum Vortag';
        }
        return null;
    }

    /**
     * Ruft abgeschlossene Balken bei der Datenquelle ab
     *
     * @param object $db          ApiDatabase (für Alpaca-Schlüssel)
     * @param string $source      binance, kraken, alpaca
     * @param string $venueSymbol Schreibweise der Quelle
     * @param string $timeframe   1d
     * @param int    $since       Unix-Zeit des ersten gewünschten Balkens (0 = alles)
     * @return array Liste [ts, open, high, low, close, volume], aufsteigend
     */
    public static function fetchBars($db, string $source, string $venueSymbol, string $timeframe, int $since): array {
        $now = time();
        $step = self::TIMEFRAMES[$timeframe];
        $bars = [];

        if ($source === 'binance') {
            $start = $since > 0 ? $since * 1000 : 0;
            for ($page = 0; $page < 20; $page++) {
                $rows = TradingHttp::getJson('https://api.binance.com/api/v3/klines?symbol=' . rawurlencode($venueSymbol)
                    . '&interval=1d&limit=1000&startTime=' . $start);
                foreach ($rows as $r) {
                    // r[6] = Schlusszeit in ms; laufende Balken auslassen
                    if ((int)($r[6] / 1000) >= $now) continue;
                    $bars[] = ['ts' => (int)($r[0] / 1000), 'open' => (float)$r[1], 'high' => (float)$r[2],
                               'low' => (float)$r[3], 'close' => (float)$r[4], 'volume' => (float)$r[5]];
                }
                if (count($rows) < 1000) break;
                $start = (int)end($rows)[0] + 1;
            }
        } elseif ($source === 'kraken') {
            $data = TradingHttp::getJson('https://api.kraken.com/0/public/OHLC?pair=' . rawurlencode($venueSymbol)
                . '&interval=1440' . ($since > 0 ? '&since=' . ($since - 1) : ''));
            if (!empty($data['error'])) throw new RuntimeException('Kraken: ' . implode(', ', $data['error']));
            foreach ($data['result'] ?? [] as $key => $rows) {
                if ($key === 'last') continue;
                foreach ($rows as $r) {
                    // Der letzte Eintrag ist der laufende Balken
                    if ((int)$r[0] + $step > $now) continue;
                    $bars[] = ['ts' => (int)$r[0], 'open' => (float)$r[1], 'high' => (float)$r[2],
                               'low' => (float)$r[3], 'close' => (float)$r[4], 'volume' => (float)$r[6]];
                }
            }
        } elseif ($source === 'alpaca') {
            $headers = self::alpacaHeaders($db);
            $crypto = strpos($venueSymbol, '/') !== false;
            $base = $crypto ? 'https://data.alpaca.markets/v1beta3/crypto/us/bars' : 'https://data.alpaca.markets/v2/stocks/bars';
            $token = '';
            for ($page = 0; $page < 20; $page++) {
                $url = $base . '?symbols=' . rawurlencode($venueSymbol) . '&timeframe=1Day&limit=10000'
                     . '&start=' . rawurlencode(gmdate('Y-m-d\TH:i:s\Z', $since > 0 ? $since : strtotime('2016-01-01')))
                     . ($crypto ? '' : '&adjustment=all')
                     . ($token !== '' ? '&page_token=' . rawurlencode($token) : '');
                $data = TradingHttp::getJson($url, $headers);
                foreach ($data['bars'][$venueSymbol] ?? [] as $r) {
                    $ts = intdiv(strtotime($r['t']), 86400) * 86400;
                    if ($ts + $step > $now) continue;
                    $bars[] = ['ts' => $ts, 'open' => (float)$r['o'], 'high' => (float)$r['h'],
                               'low' => (float)$r['l'], 'close' => (float)$r['c'], 'volume' => (float)$r['v']];
                }
                $token = (string)($data['next_page_token'] ?? '');
                if ($token === '') break;
            }
        } else {
            throw new RuntimeException('Unbekannte Datenquelle: ' . $source);
        }

        usort($bars, fn($a, $b) => $a['ts'] <=> $b['ts']);
        return array_values(array_filter($bars, fn($b) => $b['ts'] >= $since));
    }

    /**
     * Aktuelle Kurse (Geld/Brief) aller aktiven Instrumente von der Datenquelle
     *
     * @param object $db ApiDatabase
     * @return array [instrument_id => ['bid' => float, 'ask' => float, 'price' => float, 'ts' => int]]
     */
    public static function quotes($db): array {
        $settings = TradingConfig::settings($db);
        $source = $settings['market_data_source'] ?? 'binance';
        $rows = $db->getAll(
            "SELECT i.id, s.venue_symbol FROM instruments_trading i
             JOIN instrument_symbols_trading s ON s.instrument_id = i.id AND s.venue = :venue
             WHERE i.active",
            [':venue' => $source]
        ) ?: [];
        $out = [];
        $now = time();

        if ($source === 'binance') {
            $all = [];
            foreach (TradingHttp::getJson('https://api.binance.com/api/v3/ticker/bookTicker') as $t) {
                $all[$t['symbol']] = $t;
            }
            foreach ($rows as $r) {
                $t = $all[$r['venue_symbol']] ?? null;
                if (!$t || $t['bidPrice'] <= 0 || $t['askPrice'] <= 0) continue;
                $out[(int)$r['id']] = ['bid' => (float)$t['bidPrice'], 'ask' => (float)$t['askPrice'],
                                       'price' => ((float)$t['bidPrice'] + (float)$t['askPrice']) / 2, 'ts' => $now];
            }
        } elseif ($source === 'kraken') {
            $pairs = implode(',', array_column($rows, 'venue_symbol'));
            $data = TradingHttp::getJson('https://api.kraken.com/0/public/Ticker?pair=' . rawurlencode($pairs));
            if (!empty($data['error'])) throw new RuntimeException('Kraken: ' . implode(', ', $data['error']));
            // Kraken antwortet mit eigenen Paarnamen (XXBTZEUR); Zuordnung über altname
            $names = self::krakenPairNames(array_column($rows, 'venue_symbol'));
            foreach ($rows as $r) {
                $t = $data['result'][$names[$r['venue_symbol']] ?? $r['venue_symbol']] ?? null;
                if (!$t) continue;
                $out[(int)$r['id']] = ['bid' => (float)$t['b'][0], 'ask' => (float)$t['a'][0],
                                       'price' => ((float)$t['b'][0] + (float)$t['a'][0]) / 2, 'ts' => $now];
            }
        } elseif ($source === 'alpaca') {
            $headers = self::alpacaHeaders($db);
            foreach ($rows as $r) {
                $crypto = strpos($r['venue_symbol'], '/') !== false;
                $url = ($crypto ? 'https://data.alpaca.markets/v1beta3/crypto/us/latest/quotes' : 'https://data.alpaca.markets/v2/stocks/quotes/latest')
                     . '?symbols=' . rawurlencode($r['venue_symbol']);
                $q = TradingHttp::getJson($url, $headers)['quotes'][$r['venue_symbol']] ?? null;
                if (!$q || $q['bp'] <= 0 || $q['ap'] <= 0) continue;
                $out[(int)$r['id']] = ['bid' => (float)$q['bp'], 'ask' => (float)$q['ap'],
                                       'price' => ((float)$q['bp'] + (float)$q['ap']) / 2, 'ts' => strtotime($q['t']) ?: $now];
            }
        }
        return $out;
    }

    /**
     * Kraken-Antwortschlüssel zu den angefragten Paarnamen (XBTEUR → XXBTZEUR)
     *
     * @param array $altnames Angefragte Paarnamen
     * @return array [altname => Antwortschlüssel]
     */
    public static function krakenPairNames(array $altnames): array {
        static $cache = [];
        $key = implode(',', $altnames);
        if (!isset($cache[$key])) {
            $data = TradingHttp::getJson('https://api.kraken.com/0/public/AssetPairs?pair=' . rawurlencode($key));
            $map = [];
            foreach ($data['result'] ?? [] as $name => $info) {
                $map[$info['altname'] ?? $name] = $name;
            }
            $cache[$key] = $map;
        }
        return $cache[$key];
    }

    /**
     * Kopfzeilen für die Alpaca-Daten-API aus der ältesten aktiven Alpaca-Verbindung der Firma
     *
     * Die Kurse sind für alle Mitarbeiter gemeinsam, der Abruf läuft ausserhalb
     * eines Benutzers — deshalb hier bewusst die Tabelle statt der Sicht. Der
     * Zugang wird nur lesend für Kursdaten benutzt, nie für Orders.
     *
     * @param object $db ApiDatabase
     * @return array
     */
    private static function alpacaHeaders($db): array {
        $row = $db->getOne(
            "SELECT api_key_enc, api_secret_enc FROM broker_connections_trading
             WHERE adapter = 'alpaca' AND active ORDER BY id LIMIT 1", []
        );
        if (!$row) throw new RuntimeException('Alpaca-Daten brauchen eine eingerichtete Alpaca-Verbindung');
        return ['APCA-API-KEY-ID: ' . secretDecrypt($row['api_key_enc']), 'APCA-API-SECRET-KEY: ' . secretDecrypt($row['api_secret_enc'])];
    }

    /**
     * Baut das Kurspanel für Strategien und Backtests
     *
     * Alle Instrumente liegen auf einer gemeinsamen Zeitachse. Vor der ersten
     * Notierung eines Instruments steht null; Lücken danach werden mit dem
     * letzten Schlusskurs gefüllt (kein Handel an diesem Tag).
     *
     * @param object     $db            ApiDatabase
     * @param array|null $instrumentIds Auswahl; null = alle aktiven Instrumente
     * @return array ts, ids, benchmark_id, periods_per_year, open, high, low, close
     */
    public static function panel($db, ?array $instrumentIds = null): array {
        $rows = $db->getAll(
            "SELECT b.instrument_id, EXTRACT(EPOCH FROM b.ts)::bigint AS ts, b.open, b.high, b.low, b.close,
                    i.is_benchmark, i.asset_class
             FROM bars_trading b
             JOIN instruments_trading i ON i.id = b.instrument_id
             WHERE b.timeframe = '1d'
               AND (CASE WHEN :ids::text = '' THEN i.active ELSE b.instrument_id = ANY (string_to_array(:ids2, ',')::int[]) END)
             ORDER BY b.ts, b.instrument_id",
            [':ids' => $instrumentIds === null ? '' : implode(',', array_map('intval', $instrumentIds)),
             ':ids2' => $instrumentIds === null ? '' : implode(',', array_map('intval', $instrumentIds))]
        ) ?: [];

        $tsIndex = [];
        $ids = [];
        $benchmark = null;
        $crypto = true;
        foreach ($rows as $r) {
            $tsIndex[(int)$r['ts']] = true;
            $ids[(int)$r['instrument_id']] = true;
            if ($r['is_benchmark']) $benchmark = (int)$r['instrument_id'];
            if ($r['asset_class'] !== 'crypto') $crypto = false;
        }
        $ts = array_keys($tsIndex);
        sort($ts);
        $pos = array_flip($ts);
        $ids = array_keys($ids);
        sort($ids);
        $n = count($ts);

        $panel = ['ts' => $ts, 'ids' => $ids, 'benchmark_id' => $benchmark ?? ($ids[0] ?? 0),
                  'periods_per_year' => $crypto ? 365 : 252, 'open' => [], 'high' => [], 'low' => [], 'close' => []];
        foreach ($ids as $iid) {
            foreach (['open', 'high', 'low', 'close'] as $f) $panel[$f][$iid] = array_fill(0, $n, null);
        }
        foreach ($rows as $r) {
            $t = $pos[(int)$r['ts']];
            $iid = (int)$r['instrument_id'];
            foreach (['open', 'high', 'low', 'close'] as $f) $panel[$f][$iid][$t] = (float)$r[$f];
        }
        // Lücken nach der ersten Notierung füllen
        foreach ($ids as $iid) {
            $last = null;
            for ($t = 0; $t < $n; $t++) {
                if ($panel['close'][$iid][$t] === null) {
                    if ($last !== null) {
                        foreach (['open', 'high', 'low', 'close'] as $f) $panel[$f][$iid][$t] = $last;
                    }
                } else {
                    $last = $panel['close'][$iid][$t];
                }
            }
        }
        return $panel;
    }
}
