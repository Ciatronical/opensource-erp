<?php
// backend/api/trading/lib/research.php

/**
 * Forschungslabor: Idee → Simulation → Auswertung → Verbesserung → erneuter Test
 *
 * Ein Zyklus (cycle):
 *
 *   1. Kandidaten erzeugen — zufällig aus dem Parameterraum, als Abwandlung
 *      und Kreuzung bewährter Varianten, optional als KI-Vorschlag. Welche
 *      Familien bevorzugt werden, lernt das System aus seiner eigenen
 *      Erfolgsbilanz (Thompson-Sampling über bestanden/geprüft je Familie).
 *   2. Jeden Kandidaten auf TRAINING und VALIDIERUNG simulieren. Rangfolge nach
 *      dem schlechteren der beiden Sharpe-Werte — wer nur im Training glänzt,
 *      fällt zurück.
 *   3. Die wenigen Besten müssen bestehen:
 *        Stabilität   die Parameter-Nachbarn funktionieren auch (Plateau)
 *        Testdaten    Sharpe und Verlust auf dem bis dahin unberührten Abschnitt
 *        Timing-Test  besser als zufällige Zeitverschiebungen der eigenen Positionen
 *        Deflated Sharpe  korrigiert um die Zahl ALLER je auf den Testdaten
 *                     geprüften Varianten — der Testabschnitt wird mit jedem
 *                     Zyklus ein Stück „verbraucht“, und das rechnet sich hier ein
 *   4. Wer besteht, bekommt ein virtuelles Testkonto (Zustand paper) und muss
 *      sich dort mit neuen Kursen bewähren, bevor Echtgeld in Frage kommt
 *      (lifecycle).
 *
 * Datenabschnitte (zeitlich, älteste zuerst): Training | Validierung | Test.
 * Jede geprüfte Variante wird gespeichert, auch die verworfenen — nur so stimmt
 * die Zahl der Versuche, und nichts wird zweimal getestet.
 */
class TradingResearch {

    /** Mindestzahl an Balken, ab der geforscht wird */
    const MIN_BARS = 400;

    /**
     * Grenzen der Datenabschnitte
     *
     * @param array $panel    Kurspanel
     * @param array $settings Einstellungen
     * @return array|null start, train_end, val_end, end (Stellen im Panel) oder null bei zu wenig Daten
     */
    public static function splits(array $panel, array $settings): ?array {
        $n = count($panel['ts']);
        // Start: erste Stelle, an der der Vergleichsmaßstab notiert
        $start = 1;
        $bench = $panel['close'][$panel['benchmark_id']] ?? [];
        while ($start < $n && ($bench[$start] ?? null) === null) $start++;
        $len = $n - $start;
        if ($len < self::MIN_BARS) return null;
        $train = (float)($settings['split_train_pct'] ?? 60) / 100;
        $val = (float)($settings['split_validation_pct'] ?? 20) / 100;
        return [
            'start'     => $start,
            'train_end' => $start + (int)floor($len * $train),
            'val_end'   => $start + (int)floor($len * ($train + $val)),
            'end'       => $n,
        ];
    }

    /**
     * Führt einen Forschungszyklus aus
     *
     * @param object $db    ApiDatabase
     * @param array  $panel Kurspanel aller aktiven Instrumente
     * @return array experiment_id, tested, passed, summary
     */
    public static function cycle($db, array $panel): array {
        $settings = TradingConfig::settings($db);
        $costs = TradingConfig::costs($settings);
        $cap = TradingConfig::limits($db)['max_position_pct'] ?? 100.0;
        $split = self::splits($panel, $settings);
        if ($split === null) {
            return ['skipped' => 'Zu wenig Kursdaten (mindestens ' . self::MIN_BARS . ' Tage)'];
        }
        self::updateRegimes($db, $panel);

        $candidates = self::generate($db, (int)($settings['research_population'] ?? 24), $settings, $panel);
        $experiment = $db->getOne(
            "INSERT INTO experiments_trading (kind, hypothesis) VALUES ('evolution', :h) RETURNING id",
            [':h' => self::describeGeneration($candidates)]
        );
        $expId = (int)$experiment['id'];
        $minSharpe = (float)($settings['gate_min_test_sharpe'] ?? 0.5);
        $minTrades = (int)($settings['gate_min_trades'] ?? 10);

        // 2. Training und Validierung
        $evaluated = [];
        $signatures = [];
        foreach ($candidates as $c) {
            $hash = TradingStrategies::hash($c['family'], $c['params'], $panel['ids']);
            if ($db->getOne("SELECT 1 AS x FROM strategy_versions_trading WHERE params_hash = :h LIMIT 1", [':h' => $hash])) continue;

            $weights = TradingStrategies::weights($c['family'], $c['params'], $panel, $cap);
            $train = TradingBacktest::run($panel, $weights, $split['start'], $split['train_end'], $costs);
            $val = TradingBacktest::run($panel, $weights, $split['train_end'], $split['val_end'], $costs);

            // Gleiches Verhalten unter anderen Parametern (etwa ein Aufsatz, der nie greift) ist
            // kein neuer Versuch: nicht speichern, nicht zählen
            $signature = sprintf('%.6f|%.6f|%d|%d', $train['total_return'], $val['total_return'], $train['trades'], $val['trades']);
            if (isset($signatures[$signature]) || $db->getOne(
                    "SELECT 1 AS x FROM backtests_trading t JOIN backtests_trading v ON v.strategy_version_id = t.strategy_version_id AND v.segment = 'validation'
                     WHERE t.segment = 'train' AND t.total_return = :tr::numeric AND v.total_return = :vr::numeric AND t.trades = :tt AND v.trades = :vt LIMIT 1",
                    [':tr' => self::r($train['total_return']), ':vr' => self::r($val['total_return']), ':tt' => (int)$train['trades'], ':vt' => (int)$val['trades']])) {
                continue;
            }
            $signatures[$signature] = true;

            $c['hash'] = $hash;
            $c['weights'] = $weights;
            $c['train'] = $train;
            $c['val'] = $val;
            $c['score'] = min($train['sharpe'], $val['sharpe']);
            [$c['strategy_id'], $c['version_id']] = self::storeCandidate($db, $c, $panel['ids']);
            self::storeBacktest($db, $c['version_id'], $expId, 'train', $panel, $split['start'], $split['train_end'], $train);
            self::storeBacktest($db, $c['version_id'], $expId, 'validation', $panel, $split['train_end'], $split['val_end'], $val);

            $reason = null;
            if ($train['sharpe'] <= 0) $reason = 'Training ohne Ertrag';
            elseif ($val['sharpe'] < $minSharpe) $reason = sprintf('Validierung: Sharpe %.2f unter %.2f', $val['sharpe'], $minSharpe);
            elseif ($train['trades'] + $val['trades'] < $minTrades) $reason = 'Zu wenige Trades für eine Aussage';
            $c['reason'] = $reason;
            if ($reason !== null) self::setStatus($db, $c['strategy_id'], 'rejected', $reason);
            $evaluated[] = $c;
        }

        // 3. Die Besten gegen Stabilität und Testdaten
        $finalists = array_values(array_filter($evaluated, fn($c) => $c['reason'] === null));
        usort($finalists, fn($a, $b) => $b['score'] <=> $a['score']);
        $passed = [];
        foreach ($finalists as $rank => $c) {
            if ($rank >= 3) {
                self::setStatus($db, $c['strategy_id'], 'rejected', 'Validierung bestanden, aber nicht unter den besten drei des Zyklus');
                continue;
            }
            $verdict = self::finalExam($db, $c, $panel, $split, $costs, $settings, $cap, $expId);
            if ($verdict['passed']) {
                $passed[] = $c + ['verdict' => $verdict];
                self::promote($db, $c, $settings);
            } else {
                self::setStatus($db, $c['strategy_id'], 'rejected', implode('; ', $verdict['failed']));
            }
        }

        $best = $evaluated ? max(array_map(fn($c) => $c['score'], $evaluated)) : null;
        $summary = sprintf('%d Varianten geprüft, %d in der Endprüfung, %d bestanden', count($evaluated), min(count($finalists), 3), count($passed));
        $db->execute(
            "UPDATE experiments_trading SET status = 'finished', finished_at = now(), candidates_tested = :t, candidates_passed = :p,
                    summary = :s, result = :r::jsonb WHERE id = :id",
            [':t' => count($evaluated), ':p' => count($passed), ':s' => $summary, ':id' => $expId,
             ':r' => json_encode([
                 'best_score' => $best,
                 'families' => array_count_values(array_column($evaluated, 'family')),
                 'origins' => array_count_values(array_column($evaluated, 'origin')),
                 'passed' => array_map(fn($c) => $c['strategy_id'], $passed),
                 'split' => ['train_until' => gmdate('Y-m-d', $panel['ts'][$split['train_end'] - 1]),
                             'validation_until' => gmdate('Y-m-d', $panel['ts'][$split['val_end'] - 1]),
                             'test_until' => gmdate('Y-m-d', $panel['ts'][$split['end'] - 1])],
             ])]
        );
        TradingAudit::log($db, 'research', 'cycle_finished', ['experiment_id' => $expId, 'summary' => $summary]);
        return ['experiment_id' => $expId, 'tested' => count($evaluated), 'passed' => count($passed), 'summary' => $summary];
    }

    /**
     * Endprüfung eines Finalisten: Stabilität, Testdaten, Timing, Deflated Sharpe
     *
     * @param object $db       ApiDatabase
     * @param array  $c        Kandidat mit weights, train, val, version_id
     * @param array  $panel    Kurspanel
     * @param array  $split    Abschnittsgrenzen
     * @param array  $costs    Handelskosten
     * @param array  $settings Einstellungen (Grenzwerte gate_*)
     * @param float  $cap      Positionsobergrenze in %
     * @param int    $expId    Experiment
     * @return array passed (bool), failed (Liste der Gründe), Messwerte
     */
    public static function finalExam($db, array $c, array $panel, array $split, array $costs, array $settings, float $cap, int $expId): array {
        $failed = [];
        $ppy = (float)$panel['periods_per_year'];

        // Stabilität: Nachbarn im Parameterraum auf der Validierung
        $neighbors = TradingStrategies::neighbors($c['family'], $c['params']);
        $good = 0;
        foreach ($neighbors as $np) {
            $nw = TradingStrategies::weights($c['family'], $np, $panel, $cap);
            $nv = TradingBacktest::run($panel, $nw, $split['train_end'], $split['val_end'], $costs);
            if ($nv['sharpe'] >= max(0.0, 0.5 * $c['val']['sharpe'])) $good++;
        }
        $stability = $neighbors ? $good / count($neighbors) : 0.0;
        if ($stability < (float)($settings['gate_min_stability'] ?? 0.6)) {
            // Schon hier verworfen: die Testdaten bleiben für diese Variante unberührt
            $db->execute("UPDATE backtests_trading SET stability = :s WHERE strategy_version_id = :v AND segment = 'validation'",
                [':s' => round($stability, 4), ':v' => $c['version_id']]);
            return ['passed' => false, 'failed' => [sprintf('Instabil: nur %d %% der Parameter-Nachbarn tragen', $stability * 100)], 'stability' => $stability];
        }

        // Testdaten — einmal je Variante
        $test = TradingBacktest::run($panel, $c['weights'], $split['val_end'], $split['end'], $costs);

        // Timing-Test über alles ausserhalb des Trainings
        $oosWeights = array_slice($c['weights'], $split['train_end'] - 1, $split['end'] - $split['train_end'] + 1);
        $oosReturns = TradingBacktest::assetReturns($panel, $split['train_end'] - 1, $split['end']);
        $pValue = TradingStats::timingPValue($oosWeights, $oosReturns);

        // Deflated Sharpe über alle bisherigen Prüfungen auf den Testdaten
        $trialRows = $db->getAll("SELECT sharpe FROM backtests_trading WHERE segment = 'test' AND sharpe IS NOT NULL", []) ?: [];
        $trialSharpes = array_map(fn($r) => (float)$r['sharpe'] / sqrt($ppy), $trialRows);
        $trialSharpes[] = TradingStats::sharpeRaw($test['returns']);
        $sd = TradingStats::sd($trialSharpes);
        // Streuung der Versuche: gemessen, sobald es genug sind — verwandte Varianten streuen
        // weniger als unabhängige, und genau das soll einfliessen. Bei wenigen Versuchen gilt
        // mindestens die Stichprobenunschärfe eines einzelnen Sharpe (1/T), also der Fall
        // voneinander unabhängiger Strategien ohne Können.
        $variance = count($trialSharpes) >= 10 ? $sd * $sd : max($sd * $sd, 1.0 / max(count($test['returns']), 1));
        $dsr = TradingStats::deflatedSharpe($test['returns'], count($trialSharpes), $variance);

        if ($test['sharpe'] < (float)($settings['gate_min_test_sharpe'] ?? 0.5)) {
            $failed[] = sprintf('Testdaten: Sharpe %.2f unter %.2f', $test['sharpe'], $settings['gate_min_test_sharpe'] ?? 0.5);
        }
        if ($test['max_drawdown'] * 100 > (float)($settings['gate_max_test_drawdown_pct'] ?? 35)) {
            $failed[] = sprintf('Testdaten: Verlust vom Höchststand %.1f %% über %.0f %%', $test['max_drawdown'] * 100, $settings['gate_max_test_drawdown_pct'] ?? 35);
        }
        if ($pValue > (float)($settings['gate_max_p_value'] ?? 0.10)) {
            $failed[] = sprintf('Timing nicht belegt (p = %.2f)', $pValue);
        }
        if ($dsr < (float)($settings['gate_min_deflated_sharpe'] ?? 0.6)) {
            $failed[] = sprintf('Deflated Sharpe %.2f unter %.2f bei %d Versuchen', $dsr, $settings['gate_min_deflated_sharpe'] ?? 0.6, count($trialSharpes));
        }

        $extra = ['p_value' => $pValue, 'deflated_sharpe' => $dsr, 'stability' => $stability, 'passed' => !$failed,
                  'gate_details' => ['failed' => $failed, 'trials' => count($trialSharpes), 'neighbors' => count($neighbors)]];
        self::storeBacktest($db, $c['version_id'], $expId, 'test', $panel, $split['val_end'], $split['end'], $test, $extra);

        if (!$failed) {
            // Gesamtlauf mit Kapitalkurve: Grundlage für Kapitalverteilung und Diagramme
            $full = TradingBacktest::run($panel, $c['weights'], $split['start'], $split['end'], $costs);
            $regimes = self::regimeBreakdown($db, $panel, $split['start'], $full['returns'], $ppy);
            self::storeBacktest($db, $c['version_id'], $expId, 'full', $panel, $split['start'], $split['end'], $full,
                ['gate_details' => ['regimes' => $regimes]], true);
        }
        return ['passed' => !$failed, 'failed' => $failed, 'stability' => $stability, 'p_value' => $pValue, 'deflated_sharpe' => $dsr, 'test' => $test];
    }

    /**
     * Übernimmt eine bestandene Variante in den Vorwärtstest
     *
     * Abwandlungen einer laufenden Strategie, die auf den Testdaten besser sind
     * als ihr Ausgangspunkt, werden deren nächste VERSION — das Testkonto läuft
     * weiter, die Bewährungsfrist beginnt neu. Alles andere wird eine eigene
     * Strategie; ist kein Testkonto frei, wartet sie (queued).
     *
     * @param object $db       ApiDatabase
     * @param array  $c        Kandidat
     * @param array  $settings Einstellungen
     */
    private static function promote($db, array $c, array $settings): void {
        if (!empty($c['parent_id']) && $c['origin'] === 'mutation') {
            $parent = $db->getOne(
                "SELECT s.id, s.code, s.status,
                        (SELECT b.sharpe FROM backtests_trading b WHERE b.strategy_version_id = s.current_version_id AND b.segment = 'test' ORDER BY b.id DESC LIMIT 1) AS test_sharpe,
                        (SELECT max(version) FROM strategy_versions_trading WHERE strategy_id = s.id) AS max_version
                 FROM strategies_trading s WHERE s.id = :id",
                [':id' => $c['parent_id']]
            );
            $newSharpe = (float)$db->getOne("SELECT sharpe FROM backtests_trading WHERE strategy_version_id = :v AND segment = 'test' ORDER BY id DESC LIMIT 1", [':v' => $c['version_id']])['sharpe'];
            if ($parent && in_array($parent['status'], ['paper', 'live_eligible', 'live'], true) && $newSharpe > (float)$parent['test_sharpe'] + 0.1) {
                $version = (int)$parent['max_version'] + 1;
                $db->execute("UPDATE strategy_versions_trading SET strategy_id = :sid, version = :ver, change_note = :note WHERE id = :vid",
                    [':sid' => $parent['id'], ':ver' => $version, ':vid' => $c['version_id'],
                     ':note' => sprintf('Weiterentwicklung: Test-Sharpe %.2f statt %.2f', $newSharpe, $parent['test_sharpe'])]);
                $db->execute("UPDATE strategies_trading SET current_version_id = :vid, status = 'paper', paper_since = now(), mtime = now(),
                                     status_reason = :reason WHERE id = :sid",
                    [':vid' => $c['version_id'], ':sid' => $parent['id'], ':reason' => 'Version ' . $version . ' übernommen, Bewährungsfrist beginnt neu']);
                $db->execute("DELETE FROM strategies_trading WHERE id = :id", [':id' => $c['strategy_id']]);
                TradingAudit::log($db, 'research', 'strategy_improved', ['strategy' => $parent['code'], 'version' => $version, 'test_sharpe' => $newSharpe]);
                return;
            }
        }

        $running = (int)$db->getOne("SELECT count(*) AS n FROM strategies_trading WHERE status IN ('paper', 'live_eligible', 'live')", [])['n'];
        $slot = $running < (int)($settings['max_paper_strategies'] ?? 8);
        self::setStatus($db, $c['strategy_id'], $slot ? 'paper' : 'queued', $slot ? 'Alle Prüfungen bestanden — Vorwärtstest auf virtuellem Konto' : 'Alle Prüfungen bestanden — wartet auf ein freies Testkonto');
        if ($slot) $db->execute("UPDATE strategies_trading SET paper_since = now() WHERE id = :id", [':id' => $c['strategy_id']]);
        TradingAudit::log($db, 'research', 'strategy_promoted', ['strategy_id' => $c['strategy_id'], 'family' => $c['family'], 'status' => $slot ? 'paper' : 'queued']);
    }

    /**
     * Erzeugt die Kandidaten eines Zyklus
     *
     * @param object $db         ApiDatabase
     * @param int    $population Zahl der Kandidaten
     * @param array  $settings   Einstellungen
     * @param array  $panel      Kurspanel
     * @return array Liste [family, params, origin, parent_id, hypothesis]
     */
    public static function generate($db, int $population, array $settings, array $panel): array {
        $families = array_keys(TradingStrategies::families());

        // Erfolgsbilanz je Familie: geprüft / Validierung bestanden
        $stats = [];
        foreach ($db->getAll(
            "SELECT s.family, count(*) AS tested, count(*) FILTER (WHERE b.sharpe >= :min) AS good
             FROM strategies_trading s
             JOIN strategy_versions_trading v ON v.strategy_id = s.id
             JOIN backtests_trading b ON b.strategy_version_id = v.id AND b.segment = 'validation'
             GROUP BY s.family",
            [':min' => (string)(float)($settings['gate_min_test_sharpe'] ?? 0.5)]
        ) ?: [] as $r) {
            $stats[$r['family']] = [(int)$r['tested'], (int)$r['good']];
        }
        $pickFamily = function () use ($families, $stats) {
            // Thompson-Sampling: je Familie eine Erfolgsquote aus Beta(gut+1, schlecht+1) ziehen, die höchste gewinnt
            $best = null;
            $bestDraw = -1.0;
            foreach ($families as $f) {
                [$tested, $good] = $stats[$f] ?? [0, 0];
                $draw = self::betaSample($good + 1, max($tested - $good, 0) + 1);
                if ($draw > $bestDraw) {
                    $bestDraw = $draw;
                    $best = $f;
                }
            }
            return $best;
        };

        // Ausgangspunkte für Abwandlung und Kreuzung: laufende Strategien und die besten Verworfenen
        $parents = $db->getAll(
            "SELECT s.id, s.family, v.params, s.status
             FROM strategies_trading s
             JOIN strategy_versions_trading v ON v.id = s.current_version_id
             WHERE s.status IN ('paper', 'live_eligible', 'live', 'queued')
             UNION ALL
             (SELECT s.id, s.family, v.params, s.status
              FROM strategies_trading s
              JOIN strategy_versions_trading v ON v.id = s.current_version_id
              JOIN backtests_trading b ON b.strategy_version_id = v.id AND b.segment = 'validation'
              WHERE s.status = 'rejected' AND b.sharpe > 0
              ORDER BY b.sharpe DESC LIMIT 10)", []
        ) ?: [];

        $out = [];
        $seen = [];
        $add = function (string $family, array $params, string $origin, ?int $parentId, string $hypothesis) use (&$out, &$seen, $panel) {
            $params = TradingStrategies::normalize($family, $params);
            $hash = TradingStrategies::hash($family, $params, $panel['ids']);
            if (isset($seen[$hash])) return;
            $seen[$hash] = true;
            $out[] = ['family' => $family, 'params' => $params, 'origin' => $origin, 'parent_id' => $parentId, 'hypothesis' => $hypothesis];
        };

        // KI-Vorschläge (optional)
        if (($settings['research_llm_enabled'] ?? '0') === '1') {
            foreach (self::llmIdeas($db, $stats) as $idea) {
                $add($idea['family'], $idea['params'], 'llm', null, $idea['hypothesis']);
            }
        }

        $guard = 0;
        while (count($out) < $population && $guard++ < $population * 20) {
            $roll = mt_rand(0, 99);
            if ($parents && $roll < 40) {
                $p = $parents[mt_rand(0, count($parents) - 1)];
                $add($p['family'], TradingStrategies::mutate($p['family'], json_decode($p['params'], true) ?: []), 'mutation', (int)$p['id'],
                     'Abwandlung von Strategie ' . $p['id'] . ': liegt in der Nachbarschaft etwas Besseres?');
            } elseif (count($parents) > 1 && $roll < 55) {
                $a = $parents[mt_rand(0, count($parents) - 1)];
                $same = array_values(array_filter($parents, fn($x) => $x['family'] === $a['family'] && $x['id'] !== $a['id']));
                if (!$same) continue;
                $b = $same[mt_rand(0, count($same) - 1)];
                $add($a['family'], TradingStrategies::crossover($a['family'], json_decode($a['params'], true) ?: [], json_decode($b['params'], true) ?: []),
                     'crossover', (int)$a['id'], 'Kreuzung der Strategien ' . $a['id'] . ' und ' . $b['id']);
            } else {
                $family = $pickFamily();
                $add($family, TradingStrategies::randomParams($family), 'seed', null, 'Erkundung des Parameterraums der Familie ' . $family);
            }
        }
        return $out;
    }

    /**
     * Zieht aus einer Beta-Verteilung (über zwei Gamma-Ziehungen, ganzzahlige Formparameter)
     *
     * @param int $a Erfolge + 1
     * @param int $b Misserfolge + 1
     * @return float 0–1
     */
    private static function betaSample(int $a, int $b): float {
        $gamma = function (int $k) {
            $s = 0.0;
            for ($i = 0; $i < min($k, 200); $i++) $s -= log(max(mt_rand() / mt_getrandmax(), 1e-12));
            return $s * ($k > 200 ? $k / 200 : 1);
        };
        $x = $gamma($a);
        $y = $gamma($b);
        return $x / max($x + $y, 1e-12);
    }

    /**
     * Holt Strategievorschläge von einem Sprachmodell
     *
     * Das Modell bekommt die Familien, ihren Parameterraum und die bisherige
     * Erfolgsbilanz und schlägt Parameterkombinationen samt Begründung vor. Es
     * liefert KEINEN Code und keine Handelsanweisung: jeder Vorschlag wird auf
     * den Parameterraum gestutzt und durchläuft dieselben Prüfungen wie jeder
     * andere Kandidat. Fehlt Schlüssel oder Freigabe, gibt es keine Vorschläge.
     *
     * @param object $db    ApiDatabase
     * @param array  $stats [family => [geprüft, gut]]
     * @return array Liste [family, params, hypothesis]
     */
    public static function llmIdeas($db, array $stats): array {
        try {
            $config = $db->fetchKeyValue(
                "SELECT key, value FROM defaults_oserp WHERE key IN ('anthropic_api_key', '" . aiExternalConfigKey() . "', '" . aiModelConfigKey('trading_research') . "')"
            );
            $apiKey = trim($config['anthropic_api_key'] ?? '');
            if ($apiKey === '' || !aiExternalEnabled($config)) return [];
            $model = resolveAiModel($config, 'trading_research');

            $best = $db->getAll(
                "SELECT s.family, v.params, b.sharpe AS validation_sharpe, s.status, s.status_reason
                 FROM strategies_trading s
                 JOIN strategy_versions_trading v ON v.id = s.current_version_id
                 JOIN backtests_trading b ON b.strategy_version_id = v.id AND b.segment = 'validation'
                 ORDER BY b.sharpe DESC LIMIT 15", []
            ) ?: [];

            $prompt = "Du hilfst einem Forschungssystem für Handelsstrategien (Tageskurse, nur Kauf/Verkauf, kein Leerverkauf).\n"
                . "Schlage bis zu 6 neue Parameterkombinationen vor, die sich von den bisher geprüften unterscheiden.\n\n"
                . "FAMILIEN UND PARAMETER (name: [min, max, schritt]):\n" . json_encode(TradingStrategies::families()) . "\n\n"
                . "AUFSÄTZE (für jede Familie, 0 = aus): " . json_encode(TradingStrategies::overlays()) . "\n\n"
                . "BISHERIGE BILANZ je Familie [geprüft, Validierung bestanden]: " . json_encode($stats) . "\n\n"
                . "BESTE BISHERIGE VARIANTEN (Validierung):\n" . json_encode($best) . "\n\n"
                . "Antworte ausschliesslich mit JSON: {\"ideas\": [{\"family\": \"…\", \"params\": {…}, \"hypothesis\": \"ein Satz, warum das funktionieren könnte\"}]}";

            [$code, $body] = TradingHttp::request('POST', 'https://api.anthropic.com/v1/messages', [
                'Content-Type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01',
            ], json_encode(['model' => $model, 'max_tokens' => 2000, 'messages' => [['role' => 'user', 'content' => $prompt]]], JSON_UNESCAPED_UNICODE), 90);
            if ($code !== 200) throw new RuntimeException('HTTP ' . $code);

            $text = aiResponseText(json_decode($body, true));
            if (!preg_match('/\{.*\}/s', $text, $m)) return [];
            $ideas = json_decode($m[0], true)['ideas'] ?? [];
            $families = TradingStrategies::families();
            $out = [];
            foreach (array_slice($ideas, 0, 6) as $idea) {
                if (!isset($families[$idea['family'] ?? '']) || !is_array($idea['params'] ?? null)) continue;
                $out[] = ['family' => $idea['family'], 'params' => $idea['params'],
                          'hypothesis' => mb_substr((string)($idea['hypothesis'] ?? 'KI-Vorschlag'), 0, 500)];
            }
            return $out;
        } catch (Throwable $e) {
            TradingAudit::log($db, 'research', 'llm_ideas_failed', ['message' => $e->getMessage()], 'warning');
            return [];
        }
    }

    /**
     * Lebenszyklus der laufenden Strategien: bewähren, freigeben, ausmustern
     *
     * Einmal täglich. Grundlage ist ausschliesslich der tatsächliche Verlauf
     * des virtuellen Testkontos seit Beginn des Vorwärtstests.
     *
     * @param object $db ApiDatabase
     * @return array promoted, retired, activated (Kennungen)
     */
    public static function lifecycle($db): array {
        $settings = TradingConfig::settings($db);
        $result = ['promoted' => [], 'retired' => [], 'activated' => []];

        $rows = $db->getAll(
            "SELECT s.id, s.code, s.status, a.id AS account_id, a.start_capital, a.peak_equity,
                    EXTRACT(EPOCH FROM (now() - s.paper_since)) / 86400 AS paper_days,
                    (SELECT e.equity FROM equity_trading e WHERE e.account_id = a.id ORDER BY e.day DESC LIMIT 1) AS equity,
                    (SELECT count(*) FROM orders_trading o WHERE o.account_id = a.id AND o.status = 'filled' AND o.itime >= s.paper_since) AS trades,
                    (SELECT b.max_drawdown FROM backtests_trading b WHERE b.strategy_version_id = s.current_version_id AND b.segment = 'full' ORDER BY b.id DESC LIMIT 1) AS backtest_drawdown
             FROM strategies_trading s
             JOIN accounts_trading a ON a.strategy_id = s.id AND a.kind = 'strategy_paper' AND a.closed_at IS NULL
             WHERE s.status IN ('paper', 'live_eligible', 'live')", []
        ) ?: [];

        $liveActive = TradingRisk::liveAuthorized($db)
            && $db->getOne("SELECT 1 AS x FROM accounts_trading WHERE kind = 'live' AND active AND closed_at IS NULL LIMIT 1", []);

        foreach ($rows as $r) {
            if ($r['equity'] === null) continue;
            $dd = $r['peak_equity'] > 0 ? 1 - $r['equity'] / $r['peak_equity'] : 0.0;
            $ret = $r['equity'] / $r['start_capital'] - 1;
            $ddLimit = (float)($settings['gate_live_max_paper_drawdown_pct'] ?? 20) / 100;
            $ddExpected = (float)$r['backtest_drawdown'] * (float)($settings['retire_drawdown_factor'] ?? 1.5);

            // Ausmustern: Verlust über der festen Grenze oder deutlich über dem, was der Backtest erwarten liess
            if ($dd > $ddLimit || ($ddExpected > 0 && $dd > max($ddExpected, 0.05))) {
                self::setStatus($db, (int)$r['id'], 'retired', sprintf(
                    'Vorwärtstest: Verlust vom Höchststand %.1f %% (Grenze %.0f %%, im Backtest %.1f %%)', $dd * 100, $ddLimit * 100, $r['backtest_drawdown'] * 100));
                TradingAudit::log($db, 'research', 'strategy_retired', ['strategy' => $r['code'], 'drawdown_pct' => round($dd * 100, 2)], 'warning');
                $result['retired'][] = $r['code'];
                continue;
            }
            if ($r['status'] === 'paper'
                && $r['paper_days'] >= (float)($settings['gate_live_min_paper_days'] ?? 30)
                && $r['trades'] >= (int)($settings['gate_live_min_paper_trades'] ?? 3)
                && $ret * 100 >= (float)($settings['gate_live_min_paper_return_pct'] ?? 0)) {
                self::setStatus($db, (int)$r['id'], 'live_eligible', sprintf(
                    'Vorwärtstest bestanden: %d Tage, %d Trades, %+.1f %%', $r['paper_days'], $r['trades'], $ret * 100));
                TradingAudit::log($db, 'research', 'strategy_live_eligible', ['strategy' => $r['code'], 'return_pct' => round($ret * 100, 2)]);
                $result['promoted'][] = $r['code'];
                $r['status'] = 'live_eligible';
            }
            // Kennzeichnung, ob die Strategie gerade mit echtem Geld läuft
            if ($r['status'] === 'live_eligible' && $liveActive) {
                self::setStatus($db, (int)$r['id'], 'live', 'Handelt im Echtgeldkonto');
                $result['activated'][] = $r['code'];
            } elseif ($r['status'] === 'live' && !$liveActive) {
                self::setStatus($db, (int)$r['id'], 'live_eligible', 'Echtgeldhandel nicht aktiv');
            }
        }

        // Geleerte Testkonten ausgemusterter Strategien schliessen
        $db->execute(
            "UPDATE accounts_trading a SET closed_at = now(), active = false
             FROM strategies_trading s
             WHERE a.strategy_id = s.id AND a.kind = 'strategy_paper' AND a.closed_at IS NULL AND s.status IN ('retired', 'rejected')
               AND NOT EXISTS (SELECT 1 FROM positions_trading p WHERE p.account_id = a.id AND p.qty > 0)
               AND NOT EXISTS (SELECT 1 FROM orders_trading o WHERE o.account_id = a.id AND o.status IN ('submitting', 'submitted', 'partially_filled'))", []
        );

        // Wartende Strategien nachrücken lassen
        $running = (int)$db->getOne("SELECT count(*) AS n FROM strategies_trading WHERE status IN ('paper', 'live_eligible', 'live')", [])['n'];
        $free = (int)($settings['max_paper_strategies'] ?? 8) - $running;
        if ($free > 0) {
            foreach ($db->getAll(
                "SELECT s.id FROM strategies_trading s
                 LEFT JOIN backtests_trading b ON b.strategy_version_id = s.current_version_id AND b.segment = 'test'
                 WHERE s.status = 'queued' ORDER BY b.sharpe DESC NULLS LAST LIMIT " . (int)$free, []
            ) ?: [] as $q) {
                self::setStatus($db, (int)$q['id'], 'paper', 'Nachgerückt — Vorwärtstest auf virtuellem Konto');
                $db->execute("UPDATE strategies_trading SET paper_since = now() WHERE id = :id", [':id' => $q['id']]);
            }
        }
        return $result;
    }

    /**
     * Schreibt die Marktphase je Tag am Vergleichsmaßstab fort
     *
     * Trend: Schluss über/unter dem 200-Tage-Durchschnitt. Schwankung: 30-Tage-
     * Schwankung über/unter ihrem Median der letzten 365 Tage.
     *
     * @param object $db    ApiDatabase
     * @param array  $panel Kurspanel
     */
    public static function updateRegimes($db, array $panel): void {
        $iid = $panel['benchmark_id'];
        if (!isset($panel['close'][$iid])) return;
        $offset = 0;
        $n = count($panel['ts']);
        while ($offset < $n && $panel['close'][$iid][$offset] === null) $offset++;
        $close = array_slice($panel['close'][$iid], $offset);
        $sma = TradingIndicators::sma($close, 200);
        $vol = TradingIndicators::stddev(TradingIndicators::returns($close), 30);

        $last = $db->getOne("SELECT EXTRACT(EPOCH FROM max(day))::bigint AS ts FROM regimes_trading", []);
        $lastTs = (int)($last['ts'] ?? 0);
        for ($i = 0, $m = count($close); $i < $m; $i++) {
            $ts = $panel['ts'][$offset + $i];
            if ($ts <= $lastTs || $sma[$i] === null || $vol[$i] === null) continue;
            $window = array_values(array_filter(array_slice($vol, max(0, $i - 364), min($i + 1, 365)), fn($v) => $v !== null));
            sort($window);
            $median = $window[intdiv(count($window), 2)];
            $trend = $close[$i] > $sma[$i] ? 'up' : 'down';
            $volState = $vol[$i] > $median ? 'high' : 'low';
            $db->execute(
                "INSERT INTO regimes_trading (day, instrument_id, trend, vol_state, label) VALUES ((to_timestamp(:ts) AT TIME ZONE 'UTC')::date, :iid, :trend, :vol, :label)
                 ON CONFLICT (day) DO NOTHING",
                [':ts' => $ts, ':iid' => $iid, ':trend' => $trend, ':vol' => $volState, ':label' => $trend . '_' . $volState]
            );
        }
    }

    /**
     * Ergebnis einer Renditereihe je Marktphase
     *
     * @param object $db      ApiDatabase
     * @param array  $panel   Kurspanel
     * @param int    $from    Stelle des ersten Tages der Reihe
     * @param array  $returns Tagesrenditen ab $from
     * @param float  $ppy     Perioden je Jahr
     * @return array [label => ['days' => int, 'return' => float, 'sharpe' => float]]
     */
    public static function regimeBreakdown($db, array $panel, int $from, array $returns, float $ppy): array {
        $labels = [];
        foreach ($db->getAll("SELECT EXTRACT(EPOCH FROM day)::bigint AS ts, label FROM regimes_trading", []) ?: [] as $r) {
            $labels[(int)$r['ts']] = $r['label'];
        }
        $groups = [];
        foreach ($returns as $k => $ret) {
            $label = $labels[$panel['ts'][max($from, 1) + $k] ?? 0] ?? null;
            if ($label !== null) $groups[$label][] = $ret;
        }
        $out = [];
        foreach ($groups as $label => $rets) {
            $total = 1.0;
            foreach ($rets as $r) $total *= 1 + $r;
            $out[$label] = ['days' => count($rets), 'return' => round($total - 1, 4), 'sharpe' => round(TradingStats::sharpeRaw($rets) * sqrt($ppy), 2)];
        }
        ksort($out);
        return $out;
    }

    /**
     * Speichert einen Kandidaten als Strategie mit erster Version
     *
     * @param object $db            ApiDatabase
     * @param array  $c             Kandidat
     * @param array  $instrumentIds Universum
     * @return array [strategy_id, version_id]
     */
    private static function storeCandidate($db, array $c, array $instrumentIds): array {
        $names = ['trend_ema' => 'Trendfolge EMA', 'donchian' => 'Ausbruch Donchian', 'tsmom' => 'Zeitreihen-Momentum',
                  'multi_horizon' => 'Mehrhorizont-Trend', 'xsmom' => 'Relative Stärke', 'rsi_revert' => 'RSI-Rücksetzer',
                  'bollinger' => 'Bollinger-Rückkehr', 'keltner' => 'Keltner-Ausbruch'];
        $s = $db->getOne(
            "INSERT INTO strategies_trading (code, name, family, status, origin, parent_id, hypothesis)
             VALUES ('tmp-' || md5(random()::text || clock_timestamp()::text), :name, :family, 'candidate', :origin, :parent, :hyp) RETURNING id",
            [':name' => $names[$c['family']] ?? $c['family'], ':family' => $c['family'], ':origin' => $c['origin'], ':parent' => $c['parent_id'], ':hyp' => $c['hypothesis']]
        );
        $sid = (int)$s['id'];
        $v = $db->getOne(
            "INSERT INTO strategy_versions_trading (strategy_id, version, params, instrument_ids, params_hash, created_by)
             VALUES (:sid, 1, :params::jsonb, :ids::int[], :hash, :by) RETURNING id",
            [':sid' => $sid, ':params' => json_encode($c['params']), ':ids' => '{' . implode(',', array_map('intval', $instrumentIds)) . '}',
             ':hash' => $c['hash'], ':by' => $c['origin'] === 'llm' ? 'llm' : 'research']
        );
        $db->execute("UPDATE strategies_trading SET code = 'S-' || lpad(id::text, 4, '0'), current_version_id = :vid WHERE id = :sid",
            [':vid' => $v['id'], ':sid' => $sid]);
        return [$sid, (int)$v['id']];
    }

    /**
     * Speichert ein Backtest-Ergebnis
     *
     * @param object $db        ApiDatabase
     * @param int    $versionId Strategieversion
     * @param int    $expId     Experiment
     * @param string $segment   train, validation, test, full
     * @param array  $panel     Kurspanel
     * @param int    $from      Erste Stelle
     * @param int    $to        Letzte Stelle (ausschliesslich)
     * @param array  $m         Ergebnis von TradingBacktest::run
     * @param array  $extra     p_value, deflated_sharpe, stability, passed, gate_details
     * @param bool   $curve     Kapitalkurve mitspeichern
     */
    private static function storeBacktest($db, int $versionId, int $expId, string $segment, array $panel, int $from, int $to, array $m, array $extra = [], bool $curve = false): void {
        $bench = TradingBacktest::buyAndHold($panel, $panel['benchmark_id'], $from, $to);
        $db->execute(
            "INSERT INTO backtests_trading (strategy_version_id, experiment_id, segment, from_ts, to_ts, bars, total_return, cagr, volatility,
                    sharpe, sortino, max_drawdown, trades, win_rate, profit_factor, fees_paid, turnover, exposure, benchmark_return,
                    p_value, deflated_sharpe, stability, passed, gate_details, equity_curve)
             VALUES (:vid, :eid, :seg, to_timestamp(:from) AT TIME ZONE 'UTC', to_timestamp(:to) AT TIME ZONE 'UTC', :bars, :tr, :cagr, :vol,
                    :sharpe, :sortino, :dd, :trades, :win, :pf, :fees, :turn, :exp, :bench,
                    :p, :dsr, :stab, :passed, :gate::jsonb, :curve::jsonb)",
            [
                ':vid' => $versionId, ':eid' => $expId, ':seg' => $segment,
                ':from' => $panel['ts'][max($from, 1)], ':to' => $panel['ts'][$to - 1], ':bars' => (int)$m['bars'],
                ':tr' => self::r($m['total_return']), ':cagr' => self::r(min($m['cagr'], 9999)), ':vol' => self::r($m['volatility']),
                ':sharpe' => self::r($m['sharpe']), ':sortino' => self::r(min($m['sortino'], 9999)), ':dd' => self::r($m['max_drawdown']),
                ':trades' => (int)$m['trades'], ':win' => self::r($m['win_rate']), ':pf' => self::r($m['profit_factor']),
                ':fees' => TradingOrders::dec($m['fees_paid']), ':turn' => self::r(min($m['turnover'], 9999)), ':exp' => self::r($m['exposure']),
                ':bench' => $bench !== null ? self::r($bench) : null,
                ':p' => isset($extra['p_value']) ? self::r($extra['p_value']) : null,
                ':dsr' => isset($extra['deflated_sharpe']) ? self::r($extra['deflated_sharpe']) : null,
                ':stab' => isset($extra['stability']) ? self::r($extra['stability']) : null,
                ':passed' => $extra['passed'] ?? null,
                ':gate' => isset($extra['gate_details']) ? json_encode($extra['gate_details'], JSON_UNESCAPED_UNICODE) : null,
                ':curve' => $curve ? json_encode(array_map(fn($v) => round($v, 2), $m['equity'])) : null,
            ]
        );
    }

    /**
     * Setzt den Zustand einer Strategie mit Begründung
     *
     * @param object $db     ApiDatabase
     * @param int    $id     Strategie
     * @param string $status Neuer Zustand
     * @param string $reason Begründung
     */
    public static function setStatus($db, int $id, string $status, string $reason): void {
        $db->execute("UPDATE strategies_trading SET status = :s, status_reason = :r, mtime = now() WHERE id = :id",
            [':s' => $status, ':r' => $reason, ':id' => $id]);
    }

    /**
     * Beschreibt in einem Satz, woraus die Kandidaten eines Zyklus bestehen
     *
     * @param array $candidates Kandidaten
     * @return string
     */
    private static function describeGeneration(array $candidates): string {
        $o = array_count_values(array_column($candidates, 'origin'));
        return sprintf('%d Kandidaten: %d Erkundung, %d Abwandlung, %d Kreuzung, %d KI-Vorschlag',
            count($candidates), $o['seed'] ?? 0, $o['mutation'] ?? 0, $o['crossover'] ?? 0, $o['llm'] ?? 0);
    }

    /**
     * Rundet auf sechs Stellen und liefert Text für numeric-Spalten
     *
     * @param float $v Wert
     * @return string
     */
    private static function r($v): string {
        return number_format(is_finite((float)$v) ? (float)$v : 0.0, 6, '.', '');
    }
}
