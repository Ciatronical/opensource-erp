<?php
// backend/api/trading/lib/backtest.php

/**
 * Backtesting-Engine
 *
 * Simuliert Zielgewichte auf historischen Balken unter realistischen Bedingungen:
 *
 *  - Das Gewicht vom Schluss des Balkens t wird erst zur ERÖFFNUNG von t+1
 *    gehandelt — die Strategie kann den Kurs, auf den sie reagiert, nicht mehr
 *    bekommen.
 *  - Jeder Handel kostet Gebühr und Slippage (Kauf teurer, Verkauf billiger).
 *  - Kleine Abweichungen vom Ziel werden nicht gehandelt (Schwelle), sonst
 *    frässen die Kosten jeden Vorteil auf.
 *  - Käufe sind auf das vorhandene Guthaben begrenzt, kein Kredit.
 */
class TradingBacktest {

    /**
     * Führt einen Backtest über einen Abschnitt des Panels aus
     *
     * @param array $panel   Kurspanel (TradingMarketData::panel)
     * @param array $weights Zielgewichte [t => [instrument_id => Gewicht]] über das GANZE Panel
     * @param int   $from    Erste Stelle des Abschnitts (einschliesslich)
     * @param int   $to      Letzte Stelle des Abschnitts (ausschliesslich)
     * @param array $costs   fee_bps, slippage_bps, rebalance_threshold_pct, capital
     * @return array Kennzahlen, Kapitalkurve ('equity'), Tagesrenditen ('returns')
     */
    public static function run(array $panel, array $weights, int $from, int $to, array $costs): array {
        $fee    = ($costs['fee_bps'] ?? 0) / 10000;
        $slip   = ($costs['slippage_bps'] ?? 0) / 10000;
        $thresh = ($costs['rebalance_threshold_pct'] ?? 0) / 100;
        $capital = (float)($costs['capital'] ?? 10000);
        $ppy    = (float)($panel['periods_per_year'] ?? 365);
        $ids    = $panel['ids'];
        $from   = max($from, 1);
        $to     = min($to, count($panel['ts']));

        $cash = $capital;
        $qty  = array_fill_keys($ids, 0.0);
        $cost = array_fill_keys($ids, 0.0);   // Einstandswert des Bestands
        $equity = [$capital];
        $feesPaid = 0.0;
        $traded = 0.0;
        $trades = 0;
        $wins = 0;
        $closed = 0;
        $grossProfit = 0.0;
        $grossLoss = 0.0;
        $exposureSum = 0.0;

        for ($t = $from; $t < $to; $t++) {
            $target = $weights[$t - 1] ?? [];

            // Kontowert zur Eröffnung
            $eqOpen = $cash;
            foreach ($ids as $iid) {
                $o = $panel['open'][$iid][$t];
                if ($o !== null) $eqOpen += $qty[$iid] * $o;
            }

            // Verkäufe vor Käufen, damit das Guthaben für die Käufe da ist
            $buys = [];
            foreach ($ids as $iid) {
                $o = $panel['open'][$iid][$t];
                if ($o === null || $o <= 0) continue;
                $cur  = $qty[$iid] * $o;
                $want = ($target[$iid] ?? 0.0) * $eqOpen;
                $diff = $want - $cur;
                // Ganz aussteigen immer; sonst nur oberhalb der Schwelle
                if (!($want <= 0 && $cur > 0) && abs($diff) < $thresh * $eqOpen) continue;
                if (abs($diff) < 1e-9) continue;

                if ($diff < 0) {
                    $sellQty = $want <= 0 ? $qty[$iid] : min($qty[$iid], -$diff / $o);
                    $price = $o * (1 - $slip);
                    $value = $sellQty * $price;
                    $f = $value * $fee;
                    $basis = $qty[$iid] > 0 ? $cost[$iid] * $sellQty / $qty[$iid] : 0.0;
                    $pnl = $value - $f - $basis;
                    if ($pnl >= 0) { $wins++; $grossProfit += $pnl; } else { $grossLoss -= $pnl; }
                    $closed++;
                    $cash += $value - $f;
                    $cost[$iid] -= $basis;
                    $qty[$iid] -= $sellQty;
                    if ($qty[$iid] < 1e-12) { $qty[$iid] = 0.0; $cost[$iid] = 0.0; }
                    $feesPaid += $f;
                    $traded += $value;
                    $trades++;
                } else {
                    $buys[$iid] = $diff;
                }
            }
            foreach ($buys as $iid => $diff) {
                $price = $panel['open'][$iid][$t] * (1 + $slip);
                $value = min($diff, $cash / (1 + $fee));
                if ($value <= 1e-9) continue;
                $f = $value * $fee;
                $qty[$iid] += $value / $price;
                $cost[$iid] += $value + $f;
                $cash -= $value + $f;
                $feesPaid += $f;
                $traded += $value;
                $trades++;
            }

            // Kontowert zum Schluss
            $eqClose = $cash;
            $posValue = 0.0;
            foreach ($ids as $iid) {
                $c = $panel['close'][$iid][$t];
                if ($c !== null) $posValue += $qty[$iid] * $c;
            }
            $eqClose += $posValue;
            $equity[] = $eqClose;
            $exposureSum += $eqClose > 0 ? $posValue / $eqClose : 0.0;
        }

        $bars = count($equity) - 1;
        $m = TradingStats::equityMetrics($equity, $ppy);
        $avgEquity = TradingStats::mean($equity);
        $years = max($bars / $ppy, 1e-9);

        return [
            'bars'          => $bars,
            'total_return'  => $m['total_return'],
            'cagr'          => $m['cagr'],
            'volatility'    => $m['volatility'],
            'sharpe'        => $m['sharpe'],
            'sortino'       => $m['sortino'],
            'max_drawdown'  => $m['max_drawdown'],
            'trades'        => $trades,
            'win_rate'      => $closed > 0 ? $wins / $closed : 0.0,
            'profit_factor' => $grossLoss > 0 ? $grossProfit / $grossLoss : ($grossProfit > 0 ? 99.0 : 0.0),
            'fees_paid'     => $feesPaid,
            'turnover'      => $avgEquity > 0 ? $traded / $avgEquity / $years : 0.0,
            'exposure'      => $bars > 0 ? $exposureSum / $bars : 0.0,
            'equity'        => $equity,
            'returns'       => $m['returns'],
        ];
    }

    /**
     * Rendite von Kaufen-und-Halten eines Instruments über einen Abschnitt
     *
     * Gleiche Annahme wie im Backtest: Einstieg zur Eröffnung der ersten Stelle.
     *
     * @param array $panel Kurspanel
     * @param int   $iid   Instrument
     * @param int   $from  Erste Stelle (einschliesslich)
     * @param int   $to    Letzte Stelle (ausschliesslich)
     * @return float|null null, wenn das Instrument im Abschnitt nicht notiert
     */
    public static function buyAndHold(array $panel, int $iid, int $from, int $to): ?float {
        $from = max($from, 1);
        $to = min($to, count($panel['ts']));
        $start = $panel['open'][$iid][$from] ?? null;
        $end = $panel['close'][$iid][$to - 1] ?? null;
        return ($start && $end) ? $end / $start - 1 : null;
    }

    /**
     * Marktrenditen je Stelle und Instrument für den Timing-Test
     *
     * @param array $panel Kurspanel
     * @param int   $from  Erste Stelle (einschliesslich)
     * @param int   $to    Letzte Stelle (ausschliesslich)
     * @return array [k => [instrument_id => Rendite]]
     */
    public static function assetReturns(array $panel, int $from, int $to): array {
        $out = [];
        for ($t = max($from, 1); $t < $to; $t++) {
            $row = [];
            foreach ($panel['ids'] as $iid) {
                $a = $panel['close'][$iid][$t - 1];
                $b = $panel['close'][$iid][$t];
                $row[$iid] = ($a !== null && $b !== null && $a > 0) ? $b / $a - 1 : 0.0;
            }
            $out[] = $row;
        }
        return $out;
    }
}
