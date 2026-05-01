<?php
// backend/api/banking/banking_ai.php

/**
 * KI-basierte 30-Tage-Liquiditätsprognose via Claude API (claude-opus-4-7).
 * Analysiert Monatsumsätze und wiederkehrende Zahlungsrhythmen aus der
 * Transaktionshistorie und liefert eine tagesweise Prognose zurück.
 *
 * API-Key: Umgebungsvariable ANTHROPIC_API_KEY oder defaults_oserp-Tabelle.
 *
 * @param int $data['bank_account_id']
 * @param int $data['days'] Prognosezeitraum in Tagen (default 30)
 * @testdata {"bank_account_id":1}
 */
function getAILiquidityForecast($data) {
    $db        = DbhCompany::begin();
    $accountId = intval($data['bank_account_id'] ?? 0);
    $days      = intval($data['days'] ?? 30);
    if (!$accountId) { resultInfo(false, 'VALIDATION_ERROR', 'Bankkonto-ID fehlt'); return; }

    $apiKey = getenv('ANTHROPIC_API_KEY')
        ?: ($db->getOne("SELECT value FROM defaults_oserp WHERE key = 'anthropic_api_key'")['value'] ?? '');
    if (!$apiKey) {
        resultInfo(false, 'CONFIG_ERROR', 'Anthropic API Key nicht konfiguriert (ANTHROPIC_API_KEY)');
        return;
    }

    // Aktueller Kontostand
    $balRow = $db->getOne(<<<SQL
        SELECT COALESCE(ba.reconciliation_starting_balance, 0)
               + COALESCE((SELECT SUM(bt.amount) FROM bank_transactions bt WHERE bt.local_bank_account_id = :id), 0)
               AS balance, ba.name
        FROM bank_accounts ba WHERE ba.id = :id
    SQL, ['id' => $accountId]);
    $balance = round((float)($balRow['balance'] ?? 0), 2);

    // Monatliche Umsätze letzte 12 Monate
    $monthly = $db->getAll(<<<SQL
        SELECT TO_CHAR(DATE_TRUNC('month', transdate), 'YYYY-MM') AS month,
               ROUND(COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0)::numeric, 2) AS income,
               ROUND(COALESCE(ABS(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END)), 0)::numeric, 2) AS expenses
        FROM bank_transactions
        WHERE local_bank_account_id = :id
          AND transdate >= CURRENT_DATE - INTERVAL '12 months'
        GROUP BY 1 ORDER BY 1
    SQL, ['id' => $accountId]);

    // Wiederkehrende Zahlungen aus Transaktionshistorie (beide Richtungen, eine Query)
    $recurringAll = $db->getAll(<<<SQL
        WITH with_lag AS (
            SELECT remote_name, transdate, amount,
                   CASE WHEN amount > 0 THEN 'in' ELSE 'out' END AS direction,
                   LAG(transdate) OVER (
                       PARTITION BY remote_name, CASE WHEN amount > 0 THEN 'in' ELSE 'out' END
                       ORDER BY transdate
                   ) AS prev_date
            FROM bank_transactions
            WHERE local_bank_account_id = :id
              AND remote_name IS NOT NULL AND remote_name <> ''
              AND transdate >= CURRENT_DATE - INTERVAL '12 months'
        )
        SELECT remote_name,
               direction,
               ROUND(AVG(amount)::numeric, 2)        AS avg_amount,
               MAX(transdate)                         AS last_date,
               ROUND(AVG(transdate - prev_date))::int AS interval_days
        FROM with_lag
        WHERE prev_date IS NOT NULL
        GROUP BY remote_name, direction
        HAVING ROUND(AVG(transdate - prev_date)) BETWEEN 5 AND 400
        ORDER BY direction, ABS(AVG(amount)) DESC
        LIMIT 30
    SQL, ['id' => $accountId]);

    $today   = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime("+{$days} days"));

    $monthlyText = empty($monthly)
        ? "  (keine Umsatzdaten)"
        : implode("\n", array_map(fn($m) =>
            "  {$m['month']}: Einnahmen +{$m['income']}€  Ausgaben -{$m['expenses']}€  Netto " .
            round($m['income'] - $m['expenses'], 2) . "€",
            $monthly));

    $inflows  = array_filter($recurringAll, fn($r) => $r['direction'] === 'in');
    $outflows = array_filter($recurringAll, fn($r) => $r['direction'] === 'out');

    $fmtRecurring = fn($rows) => empty($rows)
        ? "  (keine erkannt)"
        : implode("\n", array_map(fn($r) =>
            "  {$r['remote_name']}: Ø{$r['avg_amount']}€ alle {$r['interval_days']} Tage (zuletzt: {$r['last_date']})",
            $rows));

    $userPrompt =
        "Erstelle eine tagesweise Liquiditätsprognose für {$days} Tage " .
        "({$today} bis {$endDate}).\n\n" .
        "Konto: " . ($balRow['name'] ?? '–') . "\n" .
        "Aktueller Kontostand heute: {$balance}€\n\n" .
        "Monatliche Umsätze (letzte 12 Monate):\n{$monthlyText}\n\n" .
        "Wiederkehrende Eingänge:\n" . $fmtRecurring($inflows) . "\n\n" .
        "Wiederkehrende Abgänge:\n" . $fmtRecurring($outflows) . "\n\n" .
        "Extrapoliere beide Seiten in die Zukunft. " .
        "Die meisten Tage haben delta=0. Gib für jeden der {$days} Tage einen Eintrag zurück.";

    try {
        $forecast = _callClaudeForForecast($apiKey, $userPrompt, $days, $today);
    } catch (\RuntimeException $e) {
        resultInfo(false, 'AI_ERROR', $e->getMessage());
        return;
    }

    resultInfo(true, '', [
        'account_name' => $balRow['name'] ?? '',
        'forecast'     => $forecast,
    ]);
}

function _callClaudeForForecast(string $apiKey, string $userPrompt, int $days, string $today): array {
    $systemPrompt =
        "Du bist ein Finanzanalyst für ein deutsches Unternehmen. " .
        "Erstelle eine realistische tagesweise Liquiditätsprognose auf Basis historischer Bankumsätze. " .
        "Berücksichtige BEIDE Seiten symmetrisch: wiederkehrende Eingänge (z.B. Kundenzahlungen, " .
        "Mieterträge) genauso wie Ausgaben. Extrapoliere erkannte Zahlungsrhythmen in die Zukunft. " .
        "Orientiere dich am historischen Durchschnitt — weder zu optimistisch noch zu pessimistisch. " .
        "Berechne für jeden Tag den laufenden Kontostand ausgehend vom Startsaldo. " .
        "Die meisten Tage haben delta=0. Nur an Tagen mit erwarteten Zahlungen gibst du Deltas an. " .
        "Positive Deltas = Einnahmen, negative Deltas = Ausgaben.";

    $payload = [
        'model'      => 'claude-opus-4-7',
        'max_tokens' => 8192,
        'system'     => [[
            'type'          => 'text',
            'text'          => $systemPrompt,
            'cache_control' => ['type' => 'ephemeral'],
        ]],
        'tools' => [[
            'name'        => 'submit_forecast',
            'description' => 'Tagesweise Liquiditätsprognose für den gesamten Zeitraum übermitteln',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'forecast' => [
                        'type'        => 'array',
                        'description' => "Genau {$days} Einträge, einer pro Tag ab {$today}",
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'date'    => ['type' => 'string', 'description' => 'Datum YYYY-MM-DD'],
                                'balance' => ['type' => 'number', 'description' => 'Erwarteter Kontostand am Tagesende in EUR'],
                                'delta'   => ['type' => 'number', 'description' => 'Erwartete Veränderung an diesem Tag in EUR (0 wenn keine Zahlung erwartet)'],
                            ],
                            'required' => ['date', 'balance', 'delta'],
                        ],
                    ],
                ],
                'required' => ['forecast'],
            ],
        ]],
        'tool_choice' => ['type' => 'any'],
        'messages'    => [['role' => 'user', 'content' => $userPrompt]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 120,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new \RuntimeException("Netzwerkfehler: {$curlError}");
    }
    if ($httpCode !== 200) {
        $decoded = json_decode($response, true);
        $msg = $decoded['error']['message'] ?? "HTTP {$httpCode}";
        throw new \RuntimeException("Claude API: {$msg}");
    }

    $decoded = json_decode($response, true);
    foreach ($decoded['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'submit_forecast') {
            return $block['input']['forecast'] ?? [];
        }
    }

    throw new \RuntimeException('Keine Prognose von Claude erhalten');
}
