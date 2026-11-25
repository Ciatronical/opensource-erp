<?php
// backend/api/trading/lib/strategies.php

/**
 * Strategiefamilien und ihr Parameterraum
 *
 * Eine Strategie ist ein Paar (Familie, Parameter). Die Familie bestimmt, wie
 * aus Kursen ein Rohsignal je Instrument entsteht (0 = draussen, 1 = investiert);
 * gemeinsame Aufsätze (Overlays) formen daraus Zielgewichte:
 *
 *   vol_target   Positionsgrösse so, dass jedes Instrument etwa dieselbe
 *                Schwankung beiträgt (Ziel in % p. a., 0 = aus)
 *   regime_sma   nur handeln, wenn der Vergleichsmaßstab über seinem SMA liegt
 *                (0 = aus) — nimmt Strategien in Abwärtsmärkten vom Tisch
 *   atr_stop     nachgezogener Stopp in ATR-Vielfachen (0 = aus); Wiedereinstieg
 *                erst nach einem frischen Signal
 *   max_weight   Obergrenze je Instrument in %
 *   concentrate  1 = Kapital auf die gerade aktiven Signale verteilen,
 *                0 = fester Anteil je Instrument des Universums
 *
 * Es wird nur gekauft und verkauft, nicht leerverkauft (Kassamarkt).
 *
 * Panel-Format (TradingMarketData::panel):
 *   ['ts' => [unix…], 'ids' => [instrument_id…], 'benchmark_id' => id,
 *    'open'|'high'|'low'|'close' => [instrument_id => [t => float|null]]]
 * null steht nur vor der ersten Notierung eines Instruments.
 */
class TradingStrategies {

    /**
     * Parameterraum je Familie: name => [min, max, schritt]
     *
     * @return array
     */
    public static function families(): array {
        return [
            // Trendfolge: schneller EMA über langsamem EMA
            'trend_ema'     => ['fast' => [5, 60, 5], 'slow' => [20, 250, 10]],
            // Ausbruch: neues n-Tage-Hoch kaufen, m-Tage-Tief verkaufen (Donchian)
            'donchian'      => ['entry' => [10, 120, 5], 'exit' => [5, 60, 5]],
            // Zeitreihen-Momentum: Rendite über den Rückblick über Schwelle
            'tsmom'         => ['lookback' => [10, 250, 10], 'threshold' => [0, 20, 2]],
            // Mehrere Horizonte stimmen ab: Gewicht = Anteil positiver Horizonte
            'multi_horizon' => ['short' => [5, 40, 5], 'mid' => [30, 120, 10], 'long' => [90, 300, 30]],
            // Relative Stärke: die besten k Instrumente des Universums halten
            'xsmom'         => ['lookback' => [10, 180, 10], 'top_k' => [1, 4, 1]],
            // Rücksetzer im Aufwärtstrend kaufen (RSI), Erholung verkaufen
            'rsi_revert'    => ['period' => [2, 21, 1], 'buy_below' => [10, 45, 5], 'sell_above' => [50, 80, 5], 'trend' => [0, 250, 50]],
            // Rückkehr zum Mittel: unter dem unteren Bollinger-Band kaufen
            'bollinger'     => ['period' => [10, 60, 5], 'width' => [10, 30, 2]],
            // Schwankungsausbruch: Schluss über EMA + k × ATR (Keltner)
            'keltner'       => ['period' => [10, 100, 10], 'width' => [10, 40, 5]],
        ];
    }

    /**
     * Parameterraum der Aufsätze: name => [min, max, schritt]
     *
     * @return array
     */
    public static function overlays(): array {
        return [
            'vol_target'  => [0, 100, 10],
            'regime_sma'  => [0, 250, 50],
            'atr_stop'    => [0, 6, 1],
            'max_weight'  => [20, 100, 10],
            'concentrate' => [0, 1, 1],
        ];
    }

    /**
     * Gesamter Parameterraum einer Familie samt Aufsätzen
     *
     * @param string $family Name der Familie
     * @return array
     */
    public static function space(string $family): array {
        $families = self::families();
        if (!isset($families[$family])) {
            throw new InvalidArgumentException('Unbekannte Strategiefamilie: ' . $family);
        }
        return $families[$family] + self::overlays();
    }

    /**
     * Bringt Parameter auf das Raster und in die erlaubten Grenzen
     *
     * Jede Parameterquelle — Zufall, Mutation, KI-Vorschlag — läuft hier durch.
     * Ein Vorschlag ausserhalb des Raums kann so nichts Unvorhergesehenes auslösen.
     *
     * @param string $family Name der Familie
     * @param array  $params Rohparameter
     * @return array Bereinigte Parameter, vollständig
     */
    public static function normalize(string $family, array $params): array {
        $out = [];
        foreach (self::space($family) as $name => [$min, $max, $step]) {
            $v = isset($params[$name]) && is_numeric($params[$name]) ? (float)$params[$name] : $min;
            $v = $min + round(($v - $min) / $step) * $step;
            $out[$name] = (int)min(max($v, $min), $max);
        }
        // Abhängigkeiten zwischen Parametern
        if ($family === 'trend_ema' && $out['slow'] <= $out['fast']) {
            $out['slow'] = min(250, max(20, (int)(ceil($out['fast'] * 2 / 10) * 10)));
            if ($out['slow'] <= $out['fast']) $out['fast'] = max(5, $out['slow'] - 15);
        }
        if ($family === 'donchian' && $out['exit'] > $out['entry']) $out['exit'] = $out['entry'];
        if ($family === 'multi_horizon') {
            if ($out['mid'] <= $out['short']) $out['mid'] = min(120, $out['short'] + 10);
            if ($out['long'] <= $out['mid'])  $out['long'] = min(300, max(90, (int)(ceil(($out['mid'] + 30) / 30) * 30)));
        }
        if ($family === 'rsi_revert' && $out['sell_above'] <= $out['buy_below']) $out['sell_above'] = min(80, $out['buy_below'] + 20);
        ksort($out);
        return $out;
    }

    /**
     * Eindeutiger Fingerabdruck einer Variante (Familie, Parameter, Universum)
     *
     * @param string $family        Name der Familie
     * @param array  $params        Parameter
     * @param array  $instrumentIds Universum
     * @return string
     */
    public static function hash(string $family, array $params, array $instrumentIds): string {
        $ids = array_map('intval', $instrumentIds);
        sort($ids);
        ksort($params);
        return sha1($family . '|' . json_encode($params) . '|' . implode(',', $ids));
    }

    /**
     * Zufällige Parameter aus dem Raum einer Familie
     *
     * @param string $family Name der Familie
     * @return array
     */
    public static function randomParams(string $family): array {
        $p = [];
        foreach (self::space($family) as $name => [$min, $max, $step]) {
            $p[$name] = $min + mt_rand(0, (int)(($max - $min) / $step)) * $step;
        }
        // Aufsätze starten überwiegend ausgeschaltet — einfache Varianten zuerst
        foreach (['vol_target', 'regime_sma', 'atr_stop'] as $overlay) {
            if (mt_rand(0, 99) < 50) $p[$overlay] = 0;
        }
        return self::normalize($family, $p);
    }

    /**
     * Abwandlung: ein bis zwei Parameter um wenige Rasterschritte verschieben
     *
     * @param string $family Name der Familie
     * @param array  $params Ausgangsparameter
     * @return array
     */
    public static function mutate(string $family, array $params): array {
        $space = self::space($family);
        $names = array_keys($space);
        $count = mt_rand(1, 2);
        for ($i = 0; $i < $count; $i++) {
            $name = $names[mt_rand(0, count($names) - 1)];
            [$min, $max, $step] = $space[$name];
            $delta = (mt_rand(0, 1) ? 1 : -1) * mt_rand(1, 2) * $step;
            $params[$name] = ($params[$name] ?? $min) + $delta;
        }
        return self::normalize($family, $params);
    }

    /**
     * Kreuzung zweier Varianten derselben Familie: je Parameter von A oder B
     *
     * @param string $family Name der Familie
     * @param array  $a      Parameter A
     * @param array  $b      Parameter B
     * @return array
     */
    public static function crossover(string $family, array $a, array $b): array {
        $p = [];
        foreach (array_keys(self::space($family)) as $name) {
            $p[$name] = mt_rand(0, 1) ? ($a[$name] ?? null) : ($b[$name] ?? null);
        }
        return self::normalize($family, $p);
    }

    /**
     * Nachbarn im Parameterraum: je Parameter ein Schritt nach oben und unten
     *
     * Grundlage der Stabilitätsprüfung — eine belastbare Variante liegt auf
     * einem Plateau, nicht auf einer Einzelspitze.
     *
     * @param string $family Name der Familie
     * @param array  $params Ausgangsparameter
     * @return array Liste von Parametersätzen (ohne den Ausgangspunkt)
     */
    public static function neighbors(string $family, array $params): array {
        $out = [];
        $seen = [json_encode(self::normalize($family, $params)) => true];
        foreach (self::families()[$family] as $name => [$min, $max, $step]) {
            foreach ([-1, 1] as $dir) {
                $q = $params;
                $q[$name] = ($params[$name] ?? $min) + $dir * $step;
                $q = self::normalize($family, $q);
                $key = json_encode($q);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = $q;
                }
            }
        }
        return $out;
    }

    /**
     * Berechnet die Zielgewichte je Balken und Instrument
     *
     * Das Gewicht an Stelle t benutzt ausschliesslich Daten bis einschliesslich
     * des Schlusskurses von t. Gehandelt wird es frühestens zur Eröffnung t+1.
     *
     * @param string $family Name der Familie
     * @param array  $params Parameter (normalisiert)
     * @param array  $panel  Kurspanel
     * @param float  $maxPositionPct Risikogrenze je Instrument in % — gilt zusätzlich zu max_weight,
     *                               damit Backtest und Handel dieselbe Obergrenze sehen
     * @return array [t => [instrument_id => Gewicht 0–1]], Summe je t höchstens 1
     */
    public static function weights(string $family, array $params, array $panel, float $maxPositionPct = 100.0): array {
        $params = self::normalize($family, $params);
        $n   = count($panel['ts']);
        $ids = $panel['ids'];

        // 1. Rohsignal je Instrument
        $signal = [];
        if ($family === 'xsmom') {
            $signal = self::signalCrossSection($params, $panel);
        } else {
            foreach ($ids as $iid) {
                $signal[$iid] = self::expand(self::signalSingle($family, $params, self::compact($panel, $iid)), $panel, $iid);
            }
        }

        // 2. Nachgezogener Stopp
        if ($params['atr_stop'] > 0) {
            foreach ($ids as $iid) {
                $signal[$iid] = self::applyAtrStop($signal[$iid], $panel, $iid, $params['atr_stop']);
            }
        }

        // 3. Schwankungsskalierung je Instrument
        $scale = [];
        foreach ($ids as $iid) {
            $scale[$iid] = $params['vol_target'] > 0 ? self::volScale($panel, $iid, $params['vol_target'] / 100) : null;
        }

        // 4. Marktphasenfilter am Vergleichsmaßstab
        $regimeOn = null;
        if ($params['regime_sma'] > 0 && isset($panel['close'][$panel['benchmark_id']])) {
            $series = self::compact($panel, $panel['benchmark_id']);
            $sma = TradingIndicators::sma($series['close'], $params['regime_sma']);
            $on = [];
            foreach ($sma as $i => $v) $on[$i] = $v !== null && $series['close'][$i] > $v ? 1.0 : 0.0;
            $regimeOn = self::expand($on, $panel, $panel['benchmark_id']);
        }

        // 5. Gewichte bilden
        $maxW = min($params['max_weight'], $maxPositionPct) / 100;
        $universe = count($ids);
        $weights = [];
        for ($t = 0; $t < $n; $t++) {
            $row = [];
            $active = 0;
            foreach ($ids as $iid) {
                if (($signal[$iid][$t] ?? 0) > 0) $active++;
            }
            $base = $params['concentrate'] ? ($active > 0 ? 1 / $active : 0) : 1 / $universe;
            $sum = 0.0;
            foreach ($ids as $iid) {
                $s = $signal[$iid][$t] ?? 0;
                $w = 0.0;
                if ($s > 0 && ($regimeOn === null || ($regimeOn[$t] ?? 0) > 0)) {
                    $w = $s * $base;
                    if ($scale[$iid] !== null) $w *= $scale[$iid][$t] ?? 0;
                    $w = min($w, $maxW);
                }
                $row[$iid] = $w;
                $sum += $w;
            }
            if ($sum > 1.0) {
                foreach ($row as $iid => $w) $row[$iid] = $w / $sum;
            }
            $weights[$t] = $row;
        }
        return $weights;
    }

    /**
     * Lückenlose Reihen eines Instruments ab seiner ersten Notierung
     *
     * @param array $panel Kurspanel
     * @param int   $iid   Instrument
     * @return array offset, open, high, low, close
     */
    private static function compact(array $panel, int $iid): array {
        $close = $panel['close'][$iid];
        $offset = 0;
        $n = count($close);
        while ($offset < $n && $close[$offset] === null) $offset++;
        return [
            'offset' => $offset,
            'open'   => array_slice($panel['open'][$iid], $offset),
            'high'   => array_slice($panel['high'][$iid], $offset),
            'low'    => array_slice($panel['low'][$iid], $offset),
            'close'  => array_slice($close, $offset),
        ];
    }

    /**
     * Legt eine lückenlose Reihe zurück auf die Zeitachse des Panels
     *
     * @param array $values Werte ab der ersten Notierung
     * @param array $panel  Kurspanel
     * @param int   $iid    Instrument
     * @return array
     */
    private static function expand(array $values, array $panel, int $iid): array {
        $offset = self::compact($panel, $iid)['offset'];
        return $offset > 0 ? array_merge(array_fill(0, $offset, 0.0), $values) : $values;
    }

    /**
     * Rohsignal einer Familie für ein einzelnes Instrument
     *
     * @param string $family Name der Familie
     * @param array  $p      Parameter
     * @param array  $s      Lückenlose Reihen (compact)
     * @return array [i => 0–1]
     */
    private static function signalSingle(string $family, array $p, array $s): array {
        $close = $s['close'];
        $n = count($close);
        $sig = array_fill(0, $n, 0.0);
        if ($n === 0) return $sig;

        switch ($family) {
            case 'trend_ema':
                $fast = TradingIndicators::ema($close, $p['fast']);
                $slow = TradingIndicators::ema($close, $p['slow']);
                for ($i = 0; $i < $n; $i++) {
                    $sig[$i] = ($slow[$i] !== null && $fast[$i] > $slow[$i]) ? 1.0 : 0.0;
                }
                break;

            case 'donchian':
                $hi = TradingIndicators::priorMax($s['high'], $p['entry']);
                $lo = TradingIndicators::priorMin($s['low'], $p['exit']);
                $in = false;
                for ($i = 0; $i < $n; $i++) {
                    if (!$in && $hi[$i] !== null && $close[$i] > $hi[$i]) $in = true;
                    elseif ($in && $lo[$i] !== null && $close[$i] < $lo[$i]) $in = false;
                    $sig[$i] = $in ? 1.0 : 0.0;
                }
                break;

            case 'tsmom':
                $roc = TradingIndicators::roc($close, $p['lookback']);
                for ($i = 0; $i < $n; $i++) {
                    $sig[$i] = ($roc[$i] !== null && $roc[$i] > $p['threshold'] / 100) ? 1.0 : 0.0;
                }
                break;

            case 'multi_horizon':
                $h = [TradingIndicators::roc($close, $p['short']), TradingIndicators::roc($close, $p['mid']), TradingIndicators::roc($close, $p['long'])];
                for ($i = 0; $i < $n; $i++) {
                    if ($h[2][$i] === null) continue;
                    $votes = 0;
                    foreach ($h as $roc) if ($roc[$i] > 0) $votes++;
                    $sig[$i] = $votes / 3;
                }
                break;

            case 'rsi_revert':
                $rsi = TradingIndicators::rsi($close, $p['period']);
                $trend = $p['trend'] > 0 ? TradingIndicators::sma($close, $p['trend']) : null;
                $in = false;
                for ($i = 0; $i < $n; $i++) {
                    if ($rsi[$i] === null) continue;
                    $up = $trend === null || ($trend[$i] !== null && $close[$i] > $trend[$i]);
                    if (!$in && $rsi[$i] < $p['buy_below'] && $up) $in = true;
                    elseif ($in && $rsi[$i] > $p['sell_above']) $in = false;
                    $sig[$i] = $in ? 1.0 : 0.0;
                }
                break;

            case 'bollinger':
                $mid = TradingIndicators::sma($close, $p['period']);
                $sd  = TradingIndicators::stddev($close, $p['period']);
                $in = false;
                for ($i = 0; $i < $n; $i++) {
                    if ($mid[$i] === null || $sd[$i] === null) continue;
                    if (!$in && $close[$i] < $mid[$i] - $p['width'] / 10 * $sd[$i]) $in = true;
                    elseif ($in && $close[$i] >= $mid[$i]) $in = false;
                    $sig[$i] = $in ? 1.0 : 0.0;
                }
                break;

            case 'keltner':
                $ema = TradingIndicators::ema($close, $p['period']);
                $atr = TradingIndicators::atr($s['high'], $s['low'], $close, $p['period']);
                $in = false;
                for ($i = 0; $i < $n; $i++) {
                    if ($ema[$i] === null || $atr[$i] === null) continue;
                    if (!$in && $close[$i] > $ema[$i] + $p['width'] / 10 * $atr[$i]) $in = true;
                    elseif ($in && $close[$i] < $ema[$i]) $in = false;
                    $sig[$i] = $in ? 1.0 : 0.0;
                }
                break;

            default:
                throw new InvalidArgumentException('Unbekannte Strategiefamilie: ' . $family);
        }
        return $sig;
    }

    /**
     * Rohsignal der relativen Stärke über das ganze Universum
     *
     * @param array $p     Parameter
     * @param array $panel Kurspanel
     * @return array [instrument_id => [t => 0|1]]
     */
    private static function signalCrossSection(array $p, array $panel): array {
        $n = count($panel['ts']);
        $roc = [];
        $sig = [];
        foreach ($panel['ids'] as $iid) {
            $series = self::compact($panel, $iid);
            $roc[$iid] = self::expandNull(TradingIndicators::roc($series['close'], $p['lookback']), $series['offset']);
            $sig[$iid] = array_fill(0, $n, 0.0);
        }
        for ($t = 0; $t < $n; $t++) {
            $rank = [];
            foreach ($panel['ids'] as $iid) {
                // Nur Instrumente mit positivem eigenem Momentum (absolutes Momentum)
                if ($roc[$iid][$t] !== null && $roc[$iid][$t] > 0) $rank[$iid] = $roc[$iid][$t];
            }
            arsort($rank);
            foreach (array_slice(array_keys($rank), 0, $p['top_k']) as $iid) {
                $sig[$iid][$t] = 1.0;
            }
        }
        return $sig;
    }

    /**
     * Wie expand(), füllt den Vorlauf aber mit null
     *
     * @param array $values Werte ab der ersten Notierung
     * @param int   $offset Stellen vor der ersten Notierung
     * @return array
     */
    private static function expandNull(array $values, int $offset): array {
        return $offset > 0 ? array_merge(array_fill(0, $offset, null), $values) : $values;
    }

    /**
     * Nachgezogener Stopp: Signal auf 0, sobald der Schluss k × ATR unter dem
     * höchsten Schluss seit Einstieg liegt; Wiedereinstieg erst nach frischem Signal
     *
     * @param array $signal Rohsignal auf der Panel-Zeitachse
     * @param array $panel  Kurspanel
     * @param int   $iid    Instrument
     * @param float $k      ATR-Vielfaches
     * @return array
     */
    private static function applyAtrStop(array $signal, array $panel, int $iid, float $k): array {
        $series = self::compact($panel, $iid);
        $atr = self::expandNull(TradingIndicators::atr($series['high'], $series['low'], $series['close'], 14), $series['offset']);
        $close = $panel['close'][$iid];
        $in = false;
        $stopped = false;
        $peak = 0.0;
        foreach ($signal as $t => $s) {
            if ($s <= 0) {
                $in = false;
                $stopped = false;
                continue;
            }
            if ($stopped) {
                $signal[$t] = 0.0;
                continue;
            }
            if (!$in) {
                $in = true;
                $peak = $close[$t];
            }
            $peak = max($peak, $close[$t]);
            if ($atr[$t] !== null && $close[$t] < $peak - $k * $atr[$t]) {
                $stopped = true;
                $in = false;
                $signal[$t] = 0.0;
            }
        }
        return $signal;
    }

    /**
     * Skalierung auf eine Zielschwankung: min(1, Ziel / realisierte Schwankung)
     *
     * @param array $panel  Kurspanel
     * @param int   $iid    Instrument
     * @param float $target Zielschwankung p. a. als Anteil (0,4 = 40 %)
     * @return array [t => 0–1]
     */
    private static function volScale(array $panel, int $iid, float $target): array {
        $series = self::compact($panel, $iid);
        $sd = TradingIndicators::stddev(TradingIndicators::returns($series['close']), 20);
        $ppy = $panel['periods_per_year'] ?? 365;
        $out = [];
        foreach ($sd as $i => $v) {
            $out[$i] = ($v === null || $v <= 0) ? 0.0 : min(1.0, $target / ($v * sqrt($ppy)));
        }
        return self::expand($out, $panel, $iid);
    }
}
