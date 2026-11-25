<?php
// backend/api/trading/lib/indicators.php

/**
 * Technische Indikatoren für die Strategie-Engine
 *
 * Alle Funktionen arbeiten auf lückenlosen Zahlenreihen (ältester Wert zuerst)
 * und liefern eine gleich lange Reihe. Stellen ohne genügend Vorlauf sind null —
 * eine Strategie darf dort kein Signal bilden. Kein Wert an Stelle t benutzt
 * Daten nach t (kein Blick in die Zukunft).
 *
 * Bewusst als Klasse: api.call.php ruft jede benutzerdefinierte *Funktion* auf,
 * deren Name in `action` steht. Interne Bausteine des Handelssystems dürfen so
 * nicht erreichbar sein, deshalb sind sie durchgehend statische Methoden.
 */
class TradingIndicators {

    /**
     * Einfacher gleitender Durchschnitt
     *
     * @param array $x Zahlenreihe
     * @param int   $n Fensterlänge
     * @return array
     */
    public static function sma(array $x, int $n): array {
        $out = array_fill(0, count($x), null);
        $sum = 0.0;
        foreach ($x as $i => $v) {
            $sum += $v;
            if ($i >= $n) $sum -= $x[$i - $n];
            if ($i >= $n - 1) $out[$i] = $sum / $n;
        }
        return $out;
    }

    /**
     * Exponentieller gleitender Durchschnitt (Start mit dem SMA der ersten n Werte)
     *
     * @param array $x Zahlenreihe
     * @param int   $n Periodenlänge
     * @return array
     */
    public static function ema(array $x, int $n): array {
        $cnt = count($x);
        $out = array_fill(0, $cnt, null);
        if ($cnt < $n) return $out;
        $k = 2.0 / ($n + 1);
        $ema = array_sum(array_slice($x, 0, $n)) / $n;
        $out[$n - 1] = $ema;
        for ($i = $n; $i < $cnt; $i++) {
            $ema += $k * ($x[$i] - $ema);
            $out[$i] = $ema;
        }
        return $out;
    }

    /**
     * Gleitende Standardabweichung (Stichprobe)
     *
     * @param array $x Zahlenreihe
     * @param int   $n Fensterlänge
     * @return array
     */
    public static function stddev(array $x, int $n): array {
        $cnt = count($x);
        $out = array_fill(0, $cnt, null);
        $sum = 0.0;
        $sq  = 0.0;
        for ($i = 0; $i < $cnt; $i++) {
            $sum += $x[$i];
            $sq  += $x[$i] * $x[$i];
            if ($i >= $n) {
                $sum -= $x[$i - $n];
                $sq  -= $x[$i - $n] * $x[$i - $n];
            }
            if ($i >= $n - 1 && $n > 1) {
                $var = ($sq - $sum * $sum / $n) / ($n - 1);
                $out[$i] = $var > 0 ? sqrt($var) : 0.0;
            }
        }
        return $out;
    }

    /**
     * Relative-Stärke-Index nach Wilder
     *
     * @param array $close Schlusskurse
     * @param int   $n     Periodenlänge
     * @return array Werte 0–100
     */
    public static function rsi(array $close, int $n): array {
        $cnt = count($close);
        $out = array_fill(0, $cnt, null);
        if ($cnt <= $n) return $out;
        $gain = 0.0;
        $loss = 0.0;
        for ($i = 1; $i <= $n; $i++) {
            $d = $close[$i] - $close[$i - 1];
            if ($d > 0) $gain += $d; else $loss -= $d;
        }
        $gain /= $n;
        $loss /= $n;
        $out[$n] = $loss == 0.0 ? 100.0 : 100.0 - 100.0 / (1 + $gain / $loss);
        for ($i = $n + 1; $i < $cnt; $i++) {
            $d = $close[$i] - $close[$i - 1];
            $gain = ($gain * ($n - 1) + max($d, 0)) / $n;
            $loss = ($loss * ($n - 1) + max(-$d, 0)) / $n;
            $out[$i] = $loss == 0.0 ? 100.0 : 100.0 - 100.0 / (1 + $gain / $loss);
        }
        return $out;
    }

    /**
     * Average True Range nach Wilder
     *
     * @param array $high  Höchstkurse
     * @param array $low   Tiefstkurse
     * @param array $close Schlusskurse
     * @param int   $n     Periodenlänge
     * @return array
     */
    public static function atr(array $high, array $low, array $close, int $n): array {
        $cnt = count($close);
        $out = array_fill(0, $cnt, null);
        if ($cnt <= $n) return $out;
        $tr = [0 => $high[0] - $low[0]];
        for ($i = 1; $i < $cnt; $i++) {
            $tr[$i] = max($high[$i] - $low[$i], abs($high[$i] - $close[$i - 1]), abs($low[$i] - $close[$i - 1]));
        }
        $atr = array_sum(array_slice($tr, 1, $n)) / $n;
        $out[$n] = $atr;
        for ($i = $n + 1; $i < $cnt; $i++) {
            $atr = ($atr * ($n - 1) + $tr[$i]) / $n;
            $out[$i] = $atr;
        }
        return $out;
    }

    /**
     * Höchster Wert der n Stellen VOR der jeweiligen Stelle (ohne die Stelle selbst)
     *
     * @param array $x Zahlenreihe
     * @param int   $n Fensterlänge
     * @return array
     */
    public static function priorMax(array $x, int $n): array {
        $cnt = count($x);
        $out = array_fill(0, $cnt, null);
        for ($i = $n; $i < $cnt; $i++) {
            $m = $x[$i - 1];
            for ($j = $i - $n; $j < $i - 1; $j++) {
                if ($x[$j] > $m) $m = $x[$j];
            }
            $out[$i] = $m;
        }
        return $out;
    }

    /**
     * Niedrigster Wert der n Stellen VOR der jeweiligen Stelle (ohne die Stelle selbst)
     *
     * @param array $x Zahlenreihe
     * @param int   $n Fensterlänge
     * @return array
     */
    public static function priorMin(array $x, int $n): array {
        $cnt = count($x);
        $out = array_fill(0, $cnt, null);
        for ($i = $n; $i < $cnt; $i++) {
            $m = $x[$i - 1];
            for ($j = $i - $n; $j < $i - 1; $j++) {
                if ($x[$j] < $m) $m = $x[$j];
            }
            $out[$i] = $m;
        }
        return $out;
    }

    /**
     * Einfache Renditen von Stelle zu Stelle (Stelle 0 = 0)
     *
     * @param array $x Zahlenreihe
     * @return array
     */
    public static function returns(array $x): array {
        $out = [0 => 0.0];
        for ($i = 1, $cnt = count($x); $i < $cnt; $i++) {
            $out[$i] = $x[$i - 1] > 0 ? $x[$i] / $x[$i - 1] - 1 : 0.0;
        }
        return $out;
    }

    /**
     * Rendite über n Stellen zurück
     *
     * @param array $x Zahlenreihe
     * @param int   $n Rückblick
     * @return array
     */
    public static function roc(array $x, int $n): array {
        $cnt = count($x);
        $out = array_fill(0, $cnt, null);
        for ($i = $n; $i < $cnt; $i++) {
            $out[$i] = $x[$i - $n] > 0 ? $x[$i] / $x[$i - $n] - 1 : null;
        }
        return $out;
    }
}
