<?php
// backend/api/trading/lib/report.php

/**
 * Auswertungen: Kennzahlen des Dashboards und tägliche Zusammenfassung
 *
 * Alle Zahlen entstehen in einer SQL-Abfrage aus den gespeicherten Kontoständen,
 * Orders und Kursen — nichts wird geschätzt oder aus dem Backtest übernommen.
 * Dashboard und Tagesbericht benutzen dieselbe Abfrage, damit sie nie
 * voneinander abweichen.
 */
class TradingReport {

    /**
     * SQL-Ausdruck (ein SELECT, eine Spalte "summary" vom Typ json) mit den Kennzahlen
     *
     * Massgeblich ist das Echtgeldkonto, sobald der Echtgeldhandel freigegeben
     * ist und ein solches Konto besteht — sonst das virtuelle Gesamtportfolio.
     *
     * @return string
     */
    public static function summarySql(): string {
        return "
        WITH live_auth AS (
            SELECT COALESCE((SELECT action = 'enable' FROM live_authorizations_trading ORDER BY id DESC LIMIT 1), false) AS enabled
        ),
        locks AS (
            SELECT COALESCE(json_agg(json_build_object('id', id, 'scope', scope, 'reason_code', reason_code, 'message', message,
                       'set_by', set_by, 'set_at', set_at) ORDER BY set_at), '[]'::json) AS active,
                   COALESCE(bool_or(scope = 'all'), false) AS halted,
                   COALESCE(bool_or(scope = 'live'), false) AS live_locked
            FROM locks_trading WHERE cleared_at IS NULL
        ),
        pf AS (
            SELECT a.* FROM accounts_trading a, live_auth
            WHERE a.closed_at IS NULL AND a.active
              AND (a.kind = 'portfolio_paper' OR (a.kind = 'live' AND live_auth.enabled))
            ORDER BY (a.kind = 'live') DESC, a.id LIMIT 1
        ),
        cur AS (SELECT e.* FROM equity_trading e JOIN pf ON pf.id = e.account_id ORDER BY e.day DESC LIMIT 1),
        prev AS (SELECT e.* FROM equity_trading e JOIN pf ON pf.id = e.account_id
                 WHERE e.day < (SELECT day FROM cur) ORDER BY e.day DESC LIMIT 1),
        strat AS (
            SELECT s.id, s.code, s.name, s.family, s.status, a.start_capital,
                   (SELECT e.equity FROM equity_trading e WHERE e.account_id = a.id ORDER BY e.day DESC LIMIT 1) AS equity,
                   (SELECT e.equity FROM equity_trading e WHERE e.account_id = a.id AND e.day < CURRENT_DATE ORDER BY e.day DESC LIMIT 1) AS prev_equity
            FROM strategies_trading s
            JOIN accounts_trading a ON a.strategy_id = s.id AND a.kind = 'strategy_paper' AND a.closed_at IS NULL
            WHERE s.status IN ('paper', 'live_eligible', 'live')
        ),
        ranked AS (
            SELECT code, name, status, round(((equity / start_capital - 1) * 100)::numeric, 2) AS return_pct,
                   round(((equity / NULLIF(COALESCE(prev_equity, start_capital), 0) - 1) * 100)::numeric, 2) AS day_pct
            FROM strat WHERE equity IS NOT NULL
        ),
        bench AS (
            SELECT i.symbol,
                   (SELECT b.close FROM bars_trading b WHERE b.instrument_id = i.id AND b.ts < (SELECT itime FROM pf) ORDER BY b.ts DESC LIMIT 1) AS start_close,
                   (SELECT b.close FROM bars_trading b WHERE b.instrument_id = i.id ORDER BY b.ts DESC LIMIT 1) AS last_close
            FROM instruments_trading i WHERE i.is_benchmark ORDER BY i.id LIMIT 1
        )
        SELECT json_build_object(
            'has_data', (SELECT count(*) FROM cur) > 0,
            'account_kind', (SELECT kind FROM pf),
            'currency', (SELECT currency FROM pf),
            'start_capital', (SELECT start_capital FROM pf),
            'portfolio_value', (SELECT equity FROM cur),
            'cash', (SELECT cash FROM cur),
            'positions_value', (SELECT positions_value FROM cur),
            'as_of', (SELECT mtime FROM cur),
            'day_pnl', (SELECT cur.equity - COALESCE((SELECT equity FROM prev), pf.start_capital) FROM cur, pf),
            'total_pnl', (SELECT cur.equity - pf.start_capital FROM cur, pf),
            'total_return_pct', (SELECT round(((cur.equity / NULLIF(pf.start_capital, 0) - 1) * 100)::numeric, 2) FROM cur, pf),
            'drawdown_pct', (SELECT round(((1 - cur.equity / NULLIF(pf.peak_equity, 0)) * 100)::numeric, 2) FROM cur, pf),
            'active_strategies', (SELECT count(*) FROM strat),
            'mode', CASE WHEN (SELECT halted FROM locks) THEN 'halted'
                         WHEN (SELECT enabled FROM live_auth) AND NOT (SELECT live_locked FROM locks)
                              AND EXISTS (SELECT 1 FROM accounts_trading WHERE kind = 'live' AND active AND closed_at IS NULL) THEN 'live'
                         ELSE 'simulation' END,
            'live_authorized', (SELECT enabled FROM live_auth),
            'engine_enabled', COALESCE((SELECT value = '1' FROM settings_trading WHERE key = 'engine_enabled'), false),
            'engine_heartbeat', (SELECT value FROM state_trading WHERE key = 'engine_heartbeat'),
            'locks', (SELECT active FROM locks),
            'best_strategy', (SELECT row_to_json(r) FROM ranked r ORDER BY return_pct DESC LIMIT 1),
            'worst_strategy', (SELECT row_to_json(r) FROM ranked r ORDER BY return_pct ASC LIMIT 1),
            'benchmark', (SELECT json_build_object('symbol', symbol,
                              'return_pct', round(((last_close / NULLIF(start_close, 0) - 1) * 100)::numeric, 2)) FROM bench),
            'last_bar', (SELECT max(ts) FROM bars_trading)
        ) AS summary";
    }

    /**
     * Erzeugt die Zusammenfassung des Tages und speichert sie
     *
     * @param object $db    ApiDatabase
     * @param array  $panel Kurspanel (ungenutzt, für künftige Auswertungen)
     * @return array Inhalt des Berichts
     */
    public static function generate($db, array $panel = []): array {
        $row = $db->getOne(
            "WITH s AS (" . self::summarySql() . ")
             SELECT json_build_object(
                'summary', (SELECT summary FROM s),
                'strategies', (
                    SELECT COALESCE(json_agg(json_build_object('code', st.code, 'name', st.name, 'status', st.status,
                               'equity', e.equity, 'return_pct', round(((e.equity / a.start_capital - 1) * 100)::numeric, 2)) ORDER BY st.code), '[]'::json)
                    FROM strategies_trading st
                    JOIN accounts_trading a ON a.strategy_id = st.id AND a.kind = 'strategy_paper' AND a.closed_at IS NULL
                    JOIN LATERAL (SELECT equity FROM equity_trading WHERE account_id = a.id ORDER BY day DESC LIMIT 1) e ON true
                    WHERE st.status IN ('paper', 'live_eligible', 'live')),
                'experiments', (
                    SELECT COALESCE(json_agg(json_build_object('id', x.id, 'kind', x.kind, 'hypothesis', x.hypothesis, 'summary', x.summary,
                               'tested', x.candidates_tested, 'passed', x.candidates_passed) ORDER BY x.id), '[]'::json)
                    FROM experiments_trading x WHERE x.started_at >= now() - interval '24 hours'),
                'status_changes', (
                    SELECT COALESCE(json_agg(json_build_object('code', st.code, 'status', st.status, 'reason', st.status_reason) ORDER BY st.mtime), '[]'::json)
                    FROM strategies_trading st
                    WHERE st.mtime >= now() - interval '24 hours' AND st.status IN ('paper', 'queued', 'live_eligible', 'live', 'retired')),
                'orders', (
                    SELECT json_build_object('filled', count(*) FILTER (WHERE status = 'filled'),
                                             'rejected', count(*) FILTER (WHERE status IN ('risk_rejected', 'rejected', 'error')),
                                             'fees', COALESCE(sum(fees), 0))
                    FROM orders_trading WHERE itime >= now() - interval '24 hours'),
                'risks', (
                    SELECT COALESCE(json_agg(json_build_object('ts', au.ts, 'event', au.event, 'severity', au.severity, 'details', au.details) ORDER BY au.ts DESC), '[]'::json)
                    FROM (SELECT * FROM audit_trading WHERE ts >= now() - interval '24 hours' AND severity IN ('warning', 'critical')
                          ORDER BY ts DESC LIMIT 20) au),
                'regime', (SELECT label FROM regimes_trading ORDER BY day DESC LIMIT 1)
             ) AS report",
            []
        );
        $report = $row['report'];
        $db->execute(
            "INSERT INTO reports_trading (day, data) VALUES (CURRENT_DATE, :data::jsonb)
             ON CONFLICT (day) DO UPDATE SET data = EXCLUDED.data, itime = now()",
            [':data' => $report]
        );
        TradingAudit::log($db, 'engine', 'report_created', ['day' => date('Y-m-d')]);
        return json_decode($report, true) ?: [];
    }
}
