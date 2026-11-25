<?php
// backend/api/trading/lib/stats.php

/**
 * Kennzahlen und Signifikanztests für Backtests
 *
 * Die Tests hier sind der Schutz davor, Zufallsgewinne mit einer belastbaren
 * Strategie zu verwechseln:
 *
 *  - timingPValue():   schlägt die Strategie zufällige Zeitverschiebungen ihrer
 *                      eigenen Positionen? (Trennt Timing-Können von bloßem
 *                      Investiertsein in einem steigenden Markt.)
 *  - deflatedSharpe(): Sharpe nach Korrektur um die Zahl aller Versuche — wer
 *                      hundert Varianten testet, findet immer eine gute.
 */
class TradingStats {

    /**
     * Mittelwert
     *
     * @param array $x Zahlenreihe
     * @return float
     */
    public static function mean(array $x): float {
        return $x ? array_sum($x) / count($x) : 0.0;
    }

    /**
     * Standardabweichung (Stichprobe)
     *
     * @param array $x Zahlenreihe
     * @return float
     */
    public static function sd(array $x): float {
        $n = count($x);
        if ($n < 2) return 0.0;
        $m = self::mean($x);
        $s = 0.0;
        foreach ($x as $v) $s += ($v - $m) * ($v - $m);
        return sqrt($s / ($n - 1));
    }

    /**
     * Sharpe Ratio je Periode (nicht annualisiert, risikofreier Zins 0)
     *
     * @param array $returns Periodenrenditen
     * @return float
     */
    public static function sharpeRaw(array $returns): float {
        $sd = self::sd($returns);
        return $sd > 1e-12 ? self::mean($returns) / $sd : 0.0;
    }

    /**
     * Korrelation zweier gleich langer Reihen
     *
     * @param array $a Reihe A
     * @param array $b Reihe B
     * @return float
     */
    public static function correlation(array $a, array $b): float {
        $n = min(count($a), count($b));
        if ($n < 3) return 0.0;
        $a = array_slice($a, -$n);
        $b = array_slice($b, -$n);
        $ma = self::mean($a);
        $mb = self::mean($b);
        $sab = $saa = $sbb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sab += ($a[$i] - $ma) * ($b[$i] - $mb);
            $saa += ($a[$i] - $ma) ** 2;
            $sbb += ($b[$i] - $mb) ** 2;
        }
        return ($saa > 0 && $sbb > 0) ? $sab / sqrt($saa * $sbb) : 0.0;
    }

    /**
     * Kennzahlen aus einer Kapitalkurve
     *
     * @param array $equity         Kontowert je Periode (erste Stelle = Startkapital)
     * @param float $periodsPerYear Perioden je Jahr (Krypto täglich: 365)
     * @return array total_return, cagr, volatility, sharpe, sortino, max_drawdown, returns
     */
    public static function equityMetrics(array $equity, float $periodsPerYear): array {
        $n = count($equity);
        $returns = [];
        $peak = $equity[0] ?? 0.0;
        $maxDd = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $returns[] = $equity[$i - 1] > 0 ? $equity[$i] / $equity[$i - 1] - 1 : 0.0;
            if ($equity[$i] > $peak) $peak = $equity[$i];
            if ($peak > 0) $maxDd = max($maxDd, 1 - $equity[$i] / $peak);
        }
        $total = ($n > 1 && $equity[0] > 0) ? $equity[$n - 1] / $equity[0] - 1 : 0.0;
        $years = max(($n - 1) / $periodsPerYear, 1e-9);
        $cagr  = ($total > -1) ? pow(1 + $total, 1 / $years) - 1 : -1.0;
        $sd    = self::sd($returns);
        $mean  = self::mean($returns);
        $down  = array_map(fn($r) => min($r, 0.0) ** 2, $returns);
        $dsd   = $returns ? sqrt(array_sum($down) / count($returns)) : 0.0;

        return [
            'total_return' => $total,
            'cagr'         => $cagr,
            'volatility'   => $sd * sqrt($periodsPerYear),
            'sharpe'       => $sd > 1e-12 ? $mean / $sd * sqrt($periodsPerYear) : 0.0,
            'sortino'      => $dsd > 1e-12 ? $mean / $dsd * sqrt($periodsPerYear) : 0.0,
            'max_drawdown' => $maxDd,
            'returns'      => $returns,
        ];
    }

    /**
     * Verteilungsfunktion der Standardnormalverteilung
     *
     * @param float $x Stelle
     * @return float
     */
    public static function normCdf(float $x): float {
        // Abramowitz/Stegun 26.2.17, Fehler < 7,5e-8
        $t = 1 / (1 + 0.2316419 * abs($x));
        $d = 0.3989422804014327 * exp(-$x * $x / 2);
        $p = $d * $t * (0.319381530 + $t * (-0.356563782 + $t * (1.781477937 + $t * (-1.821255978 + $t * 1.330274429))));
        return $x >= 0 ? 1 - $p : $p;
    }

    /**
     * Quantilfunktion der Standardnormalverteilung (Acklam)
     *
     * @param float $p Wahrscheinlichkeit 0 < p < 1
     * @return float
     */
    public static function normInv(float $p): float {
        $p = min(max($p, 1e-12), 1 - 1e-12);
        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];
        if ($p < 0.02425) {
            $q = sqrt(-2 * log($p));
            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                 / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }
        if ($p > 1 - 0.02425) {
            $q = sqrt(-2 * log(1 - $p));
            return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                  / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }
        $q = $p - 0.5;
        $r = $q * $q;
        return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
             / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
    }

    /**
     * Deflated Sharpe Ratio (Bailey / López de Prado 2014)
     *
     * Wahrscheinlichkeit, dass der wahre Sharpe über null liegt, nachdem man
     * berücksichtigt hat, dass der beobachtete Wert der beste aus $trials
     * Versuchen sein kann, und dass Renditen schief und schwerrandig sind.
     *
     * @param array $returns        Periodenrenditen der geprüften Strategie
     * @param int   $trials         Zahl aller bisher auf diesem Abschnitt geprüften Varianten
     * @param float $trialSharpeVar Varianz der (nicht annualisierten) Sharpe-Werte dieser Varianten
     * @return float 0–1
     */
    public static function deflatedSharpe(array $returns, int $trials, float $trialSharpeVar): float {
        $t = count($returns);
        if ($t < 10) return 0.0;
        $sr = self::sharpeRaw($returns);
        $m  = self::mean($returns);
        $sd = self::sd($returns);
        if ($sd < 1e-12) return 0.0;

        $skew = 0.0;
        $kurt = 0.0;
        foreach ($returns as $r) {
            $z = ($r - $m) / $sd;
            $skew += $z ** 3;
            $kurt += $z ** 4;
        }
        $skew /= $t;
        $kurt /= $t;

        // Erwarteter Höchstwert des Sharpe unter der Nullhypothese bei $trials Versuchen
        $sr0 = 0.0;
        if ($trials > 1 && $trialSharpeVar > 0) {
            $gamma = 0.5772156649015329;
            $sr0 = sqrt($trialSharpeVar) * ((1 - $gamma) * self::normInv(1 - 1 / $trials)
                                           + $gamma * self::normInv(1 - 1 / ($trials * M_E)));
        }

        $den = 1 - $skew * $sr + ($kurt - 1) / 4 * $sr * $sr;
        if ($den <= 0) return 0.0;
        return self::normCdf(($sr - $sr0) * sqrt($t - 1) / sqrt($den));
    }

    /**
     * Timing-Test: Anteil zufälliger Zeitverschiebungen, die mindestens so gut sind
     *
     * Die Gewichtsreihe der Strategie wird ringförmig gegen die Marktrenditen
     * verschoben. Dabei bleiben Investitionsgrad, Umschlag und Seriencharakter
     * der Positionen erhalten — nur der Bezug zum Markt geht verloren. Ist die
     * echte Zuordnung nicht deutlich besser als die verschobenen, steckt hinter
     * dem Ergebnis kein Timing, sondern Marktrichtung oder Zufall.
     *
     * @param array $weights      [t => [instrument_id => Gewicht]] — Gewicht am Ende von t
     * @param array $assetReturns [t => [instrument_id => Rendite von t-1 nach t]]
     * @param int   $shifts       Zahl der Verschiebungen
     * @param int   $seed         Startwert des Zufallsgenerators (reproduzierbar)
     * @return float p-Wert 0–1 (klein = Timing ist echt)
     */
    public static function timingPValue(array $weights, array $assetReturns, int $shifts = 300, int $seed = 7): float {
        $weights = array_values($weights);
        $assetReturns = array_values($assetReturns);
        $n = count($weights);
        if ($n < 60) return 1.0;

        $calc = function (int $offset) use ($weights, $assetReturns, $n) {
            $rets = [];
            for ($t = 1; $t < $n; $t++) {
                $w = $weights[($t - 1 + $offset) % $n];
                $r = 0.0;
                foreach ($w as $iid => $wi) {
                    if ($wi != 0.0) $r += $wi * ($assetReturns[$t][$iid] ?? 0.0);
                }
                $rets[] = $r;
            }
            return self::sharpeRaw($rets);
        };

        $actual = $calc(0);
        mt_srand($seed);
        $minShift = max(10, intdiv($n, 20));
        $better = 0;
        for ($k = 0; $k < $shifts; $k++) {
            $offset = mt_rand($minShift, $n - $minShift);
            if ($calc($offset) >= $actual) $better++;
        }
        mt_srand();
        return ($better + 1) / ($shifts + 1);
    }
}
