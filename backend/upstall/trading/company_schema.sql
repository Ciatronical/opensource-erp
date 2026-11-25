-- ============================================================================
-- ERWEITERUNG TRADING — Company-Schema
-- ============================================================================
--
-- Autonomes KI-Handelssystem (dev/trading.md). Aufbau:
--
--   Märkte        instruments_trading, instrument_symbols_trading, bars_trading
--   Strategien    strategies_trading, strategy_versions_trading
--   Forschung     experiments_trading, backtests_trading, regimes_trading
--   Konten        accounts_trading, positions_trading, equity_trading,
--                 allocations_trading
--   Orders        orders_trading, fills_trading
--   Sicherheit    risk_limits_trading, settings_trading, locks_trading,
--                 live_authorizations_trading, broker_connections_trading
--   Betrieb       state_trading, audit_trading, reports_trading
--
-- Sicherheitsgrundsatz: Die Engine (Cron, Forschung, KI) handelt selbstständig,
-- darf aber weder Limits noch Sperren noch die Echtgeldfreigabe verändern. Das
-- ist nicht nur Konvention im PHP-Code: die Tabellen der Gruppe „Sicherheit“
-- tragen einen Trigger, der Schreibzugriffe nur zulässt, wenn die Transaktion
-- oserp.trading_actor = 'user' gesetzt hat. Das tun ausschliesslich die
-- API-Aktionen eines angemeldeten Administrators (backend/api/trading/trading.php,
-- TradingGuard::asUser). Sperren darf die Engine setzen, aber nicht aufheben.
--
-- Reihenfolge beachten: der Upstall-Parser verarbeitet erst alle
-- CREATE TABLE (...) getrennt, danach alles Übrige in Dateireihenfolge.

-- ============================================================================
-- MÄRKTE
-- ============================================================================

CREATE TABLE IF NOT EXISTS instruments_trading
(
    id             integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    symbol         text NOT NULL,
    name           text NOT NULL,
    asset_class    text NOT NULL DEFAULT 'crypto',
    quote_currency text NOT NULL DEFAULT 'EUR',
    qty_decimals   integer NOT NULL DEFAULT 8,
    min_order_value numeric(20,8) NOT NULL DEFAULT 10,
    is_benchmark   boolean NOT NULL DEFAULT false,
    active         boolean NOT NULL DEFAULT true,
    itime          timestamp without time zone DEFAULT now(),
    mtime          timestamp without time zone
);

CREATE TABLE IF NOT EXISTS instrument_symbols_trading
(
    id            integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    instrument_id integer NOT NULL REFERENCES instruments_trading (id) ON DELETE CASCADE,
    venue         text NOT NULL,
    venue_symbol  text NOT NULL
);

CREATE TABLE IF NOT EXISTS bars_trading
(
    instrument_id integer NOT NULL REFERENCES instruments_trading (id) ON DELETE CASCADE,
    timeframe     text NOT NULL DEFAULT '1d',
    ts            timestamp without time zone NOT NULL,
    open          numeric(20,8) NOT NULL,
    high          numeric(20,8) NOT NULL,
    low           numeric(20,8) NOT NULL,
    close         numeric(20,8) NOT NULL,
    volume        numeric(28,8) NOT NULL DEFAULT 0,
    source        text NOT NULL,
    itime         timestamp without time zone DEFAULT now()
);

-- ============================================================================
-- STRATEGIEN
-- ============================================================================

CREATE TABLE IF NOT EXISTS strategies_trading
(
    id                 integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code               text NOT NULL,
    name               text NOT NULL,
    family             text NOT NULL,
    status             text NOT NULL DEFAULT 'candidate',
    origin             text NOT NULL DEFAULT 'seed',
    parent_id          integer,
    current_version_id integer,
    hypothesis         text,
    status_reason      text,
    paper_since        timestamp without time zone,
    itime              timestamp without time zone DEFAULT now(),
    mtime              timestamp without time zone
);

CREATE TABLE IF NOT EXISTS strategy_versions_trading
(
    id             integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    strategy_id    integer NOT NULL REFERENCES strategies_trading (id) ON DELETE CASCADE,
    version        integer NOT NULL DEFAULT 1,
    params         jsonb NOT NULL,
    instrument_ids integer[] NOT NULL,
    timeframe      text NOT NULL DEFAULT '1d',
    params_hash    text NOT NULL,
    created_by     text NOT NULL DEFAULT 'research',
    change_note    text,
    itime          timestamp without time zone DEFAULT now()
);

-- ============================================================================
-- FORSCHUNG
-- ============================================================================

CREATE TABLE IF NOT EXISTS experiments_trading
(
    id                integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind              text NOT NULL,
    hypothesis        text,
    status            text NOT NULL DEFAULT 'running',
    candidates_tested integer NOT NULL DEFAULT 0,
    candidates_passed integer NOT NULL DEFAULT 0,
    result            jsonb,
    summary           text,
    started_at        timestamp without time zone DEFAULT now(),
    finished_at       timestamp without time zone
);

CREATE TABLE IF NOT EXISTS backtests_trading
(
    id                  integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    strategy_version_id integer NOT NULL REFERENCES strategy_versions_trading (id) ON DELETE CASCADE,
    experiment_id       integer REFERENCES experiments_trading (id) ON DELETE SET NULL,
    segment             text NOT NULL,
    from_ts             timestamp without time zone NOT NULL,
    to_ts               timestamp without time zone NOT NULL,
    bars                integer NOT NULL DEFAULT 0,
    total_return        numeric(14,6),
    cagr                numeric(14,6),
    volatility          numeric(14,6),
    sharpe              numeric(14,6),
    sortino             numeric(14,6),
    max_drawdown        numeric(14,6),
    trades              integer,
    win_rate            numeric(14,6),
    profit_factor       numeric(14,6),
    fees_paid           numeric(20,8),
    turnover            numeric(14,6),
    exposure            numeric(14,6),
    benchmark_return    numeric(14,6),
    p_value             numeric(14,6),
    deflated_sharpe     numeric(14,6),
    stability           numeric(14,6),
    passed              boolean,
    gate_details        jsonb,
    equity_curve        jsonb,
    itime               timestamp without time zone DEFAULT now()
);

CREATE TABLE IF NOT EXISTS regimes_trading
(
    day           date NOT NULL PRIMARY KEY,
    instrument_id integer REFERENCES instruments_trading (id) ON DELETE SET NULL,
    trend         text NOT NULL,
    vol_state     text NOT NULL,
    label         text NOT NULL
);

-- ============================================================================
-- KONTEN
-- ============================================================================
--
-- Vor accounts_trading, das auf die Broker-Verbindung verweist.

CREATE TABLE IF NOT EXISTS broker_connections_trading
(
    id             integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name           text NOT NULL,
    adapter        text NOT NULL,
    environment    text NOT NULL DEFAULT 'sandbox',
    api_key_enc    text,
    api_secret_enc text,
    capital_limit  numeric(20,8) NOT NULL DEFAULT 0,
    active         boolean NOT NULL DEFAULT true,
    itime          timestamp without time zone DEFAULT now(),
    mtime          timestamp without time zone
);

CREATE TABLE IF NOT EXISTS accounts_trading
(
    id                   integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind                 text NOT NULL,
    name                 text NOT NULL,
    strategy_id          integer REFERENCES strategies_trading (id) ON DELETE SET NULL,
    broker_connection_id integer REFERENCES broker_connections_trading (id) ON DELETE SET NULL,
    currency             text NOT NULL DEFAULT 'EUR',
    start_capital        numeric(20,8) NOT NULL,
    cash                 numeric(20,8) NOT NULL,
    peak_equity          numeric(20,8),
    last_signal_ts       timestamp without time zone,
    active               boolean NOT NULL DEFAULT true,
    itime                timestamp without time zone DEFAULT now(),
    closed_at            timestamp without time zone
);

CREATE TABLE IF NOT EXISTS positions_trading
(
    id            integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    account_id    integer NOT NULL REFERENCES accounts_trading (id) ON DELETE CASCADE,
    instrument_id integer NOT NULL REFERENCES instruments_trading (id),
    qty           numeric(28,10) NOT NULL DEFAULT 0,
    avg_price     numeric(20,8) NOT NULL DEFAULT 0,
    realized_pnl  numeric(20,8) NOT NULL DEFAULT 0,
    mtime         timestamp without time zone DEFAULT now()
);

CREATE TABLE IF NOT EXISTS equity_trading
(
    account_id      integer NOT NULL REFERENCES accounts_trading (id) ON DELETE CASCADE,
    day             date NOT NULL,
    equity          numeric(20,8) NOT NULL,
    cash            numeric(20,8) NOT NULL,
    positions_value numeric(20,8) NOT NULL DEFAULT 0,
    mtime           timestamp without time zone DEFAULT now()
);

CREATE TABLE IF NOT EXISTS allocations_trading
(
    id          integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    day         date NOT NULL,
    strategy_id integer NOT NULL REFERENCES strategies_trading (id) ON DELETE CASCADE,
    weight      numeric(10,6) NOT NULL,
    reason      text,
    itime       timestamp without time zone DEFAULT now()
);

-- ============================================================================
-- ORDERS
-- ============================================================================

CREATE TABLE IF NOT EXISTS orders_trading
(
    id                  integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    account_id          integer NOT NULL REFERENCES accounts_trading (id) ON DELETE CASCADE,
    strategy_version_id integer REFERENCES strategy_versions_trading (id) ON DELETE SET NULL,
    instrument_id       integer NOT NULL REFERENCES instruments_trading (id),
    client_order_id     text NOT NULL,
    broker_order_id     text,
    side                text NOT NULL,
    order_type          text NOT NULL DEFAULT 'market',
    qty                 numeric(28,10) NOT NULL,
    limit_price         numeric(20,8),
    stop_price          numeric(20,8),
    trail_percent       numeric(10,4),
    trail_ref_price     numeric(20,8),
    reference_price     numeric(20,8),
    status              text NOT NULL DEFAULT 'new',
    filled_qty          numeric(28,10) NOT NULL DEFAULT 0,
    avg_fill_price      numeric(20,8),
    fees                numeric(20,8) NOT NULL DEFAULT 0,
    reason              text,
    risk_check          jsonb,
    error               text,
    itime               timestamp without time zone DEFAULT now(),
    submitted_at        timestamp without time zone,
    mtime               timestamp without time zone
);

CREATE TABLE IF NOT EXISTS fills_trading
(
    id             integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    order_id       integer NOT NULL REFERENCES orders_trading (id) ON DELETE CASCADE,
    broker_fill_id text NOT NULL,
    ts             timestamp without time zone NOT NULL DEFAULT now(),
    qty            numeric(28,10) NOT NULL,
    price          numeric(20,8) NOT NULL,
    fee            numeric(20,8) NOT NULL DEFAULT 0
);

-- ============================================================================
-- SICHERHEIT (schreibgeschützt für die Engine, siehe Trigger unten)
-- ============================================================================

CREATE TABLE IF NOT EXISTS risk_limits_trading
(
    key         text NOT NULL PRIMARY KEY,
    value       numeric(20,8) NOT NULL,
    unit        text NOT NULL DEFAULT '',
    sortkey     integer NOT NULL DEFAULT 0,
    mtime       timestamp without time zone DEFAULT now(),
    modified_by text
);

CREATE TABLE IF NOT EXISTS settings_trading
(
    key         text NOT NULL PRIMARY KEY,
    value       text NOT NULL,
    value_type  text NOT NULL DEFAULT 'number',
    section     text NOT NULL DEFAULT 'engine',
    sortkey     integer NOT NULL DEFAULT 0,
    mtime       timestamp without time zone DEFAULT now(),
    modified_by text
);

CREATE TABLE IF NOT EXISTS locks_trading
(
    id          integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope       text NOT NULL DEFAULT 'all',
    reason_code text NOT NULL,
    message     text,
    set_by      text NOT NULL,
    set_at      timestamp without time zone NOT NULL DEFAULT now(),
    cleared_by  text,
    cleared_at  timestamp without time zone
);

CREATE TABLE IF NOT EXISTS live_authorizations_trading
(
    id           integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    action       text NOT NULL,
    login        text NOT NULL,
    confirmation text,
    itime        timestamp without time zone NOT NULL DEFAULT now()
);

-- ============================================================================
-- BETRIEB
-- ============================================================================

CREATE TABLE IF NOT EXISTS state_trading
(
    key   text NOT NULL PRIMARY KEY,
    value text,
    mtime timestamp without time zone DEFAULT now()
);

CREATE TABLE IF NOT EXISTS audit_trading
(
    id         bigint NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ts         timestamp without time zone NOT NULL DEFAULT now(),
    actor      text NOT NULL,
    event      text NOT NULL,
    severity   text NOT NULL DEFAULT 'info',
    account_id integer,
    order_id   integer,
    details    jsonb
);

CREATE TABLE IF NOT EXISTS reports_trading
(
    id        integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    day       date NOT NULL,
    data      jsonb NOT NULL,
    narrative text,
    itime     timestamp without time zone DEFAULT now()
);

-- ============================================================================
-- INDIZES
-- ============================================================================

CREATE UNIQUE INDEX idx_instruments_trading_symbol ON instruments_trading (symbol);
CREATE UNIQUE INDEX idx_instrument_symbols_trading_venue ON instrument_symbols_trading (instrument_id, venue);
CREATE UNIQUE INDEX idx_bars_trading_pk ON bars_trading (instrument_id, timeframe, ts);
CREATE UNIQUE INDEX idx_strategies_trading_code ON strategies_trading (code);
CREATE INDEX idx_strategies_trading_status ON strategies_trading (status);
CREATE UNIQUE INDEX idx_strategy_versions_trading_version ON strategy_versions_trading (strategy_id, version);
CREATE INDEX idx_strategy_versions_trading_hash ON strategy_versions_trading (params_hash);
CREATE INDEX idx_backtests_trading_version ON backtests_trading (strategy_version_id, segment);
CREATE UNIQUE INDEX idx_positions_trading_account ON positions_trading (account_id, instrument_id);
CREATE UNIQUE INDEX idx_equity_trading_pk ON equity_trading (account_id, day);
CREATE INDEX idx_allocations_trading_day ON allocations_trading (day);
-- Schutz vor doppelten Orders: dieselbe Entscheidung (Konto, Instrument,
-- Signalzeitpunkt, Richtung) ergibt immer dieselbe client_order_id
CREATE UNIQUE INDEX idx_orders_trading_client_order ON orders_trading (client_order_id);
CREATE INDEX idx_orders_trading_account ON orders_trading (account_id, itime);
CREATE INDEX idx_orders_trading_status ON orders_trading (status);
CREATE UNIQUE INDEX idx_fills_trading_broker_fill ON fills_trading (order_id, broker_fill_id);
CREATE INDEX idx_locks_trading_active ON locks_trading (cleared_at);
CREATE INDEX idx_audit_trading_ts ON audit_trading (ts);
CREATE UNIQUE INDEX idx_reports_trading_day ON reports_trading (day);
CREATE UNIQUE INDEX idx_accounts_trading_strategy ON accounts_trading (strategy_id) WHERE kind = 'strategy_paper' AND closed_at IS NULL;

-- ============================================================================
-- KOMMENTARE
-- ============================================================================

COMMENT ON TABLE instruments_trading IS 'Trading: handelbare Instrumente (Universum der Strategien)';
COMMENT ON COLUMN instruments_trading.symbol IS 'Einheitliches Symbol, z. B. BTC/EUR — die Schreibweise je Börse steht in instrument_symbols_trading';
COMMENT ON COLUMN instruments_trading.min_order_value IS 'Kleinster Orderwert in Kontowährung; kleinere Umschichtungen werden nicht gehandelt';
COMMENT ON COLUMN instruments_trading.is_benchmark IS 'Vergleichsmaßstab für Berichte und Marktphasen-Erkennung (genau eines)';
COMMENT ON TABLE instrument_symbols_trading IS 'Trading: Schreibweise eines Instruments je Datenquelle/Broker (venue: binance, kraken, alpaca)';
COMMENT ON TABLE bars_trading IS 'Trading: Kursbalken (OHLCV); ts = Beginn des Balkens in UTC, nur abgeschlossene Balken';
COMMENT ON TABLE strategies_trading IS 'Trading: Strategie mit Lebenszyklus candidate → paper → live_eligible → live; rejected/retired sind Endzustände';
COMMENT ON COLUMN strategies_trading.code IS 'Kennung, z. B. S-0007';
COMMENT ON COLUMN strategies_trading.origin IS 'Herkunft: seed, mutation, crossover, llm, ensemble';
COMMENT ON COLUMN strategies_trading.paper_since IS 'Beginn des Vorwärtstests auf dem virtuellen Konto';
COMMENT ON TABLE strategy_versions_trading IS 'Trading: Versionshistorie der Strategieparameter; params_hash verhindert doppelte Tests derselben Variante';
COMMENT ON TABLE experiments_trading IS 'Trading: Forschungsprotokoll — jeder Zyklus Idee → Simulation → Auswertung ist ein Experiment';
COMMENT ON TABLE backtests_trading IS 'Trading: Backtest-Ergebnis je Version und Datenabschnitt (train, validation, test, full)';
COMMENT ON COLUMN backtests_trading.p_value IS 'Anteil zufälliger Zeitverschiebungen der Positionen, die mindestens so gut abschneiden (Timing-Test)';
COMMENT ON COLUMN backtests_trading.deflated_sharpe IS 'Deflated Sharpe Ratio: Wahrscheinlichkeit, dass der Sharpe nach Korrektur um die Zahl aller Versuche über null liegt';
COMMENT ON COLUMN backtests_trading.stability IS 'Anteil der Parameter-Nachbarn mit positivem Ergebnis (Plateau statt Einzelspitze)';
COMMENT ON TABLE regimes_trading IS 'Trading: Marktphase je Tag am Vergleichsmaßstab (Trend × Schwankung)';
COMMENT ON TABLE broker_connections_trading IS 'Trading: Broker-Zugänge; Schlüssel AES-256-GCM-verschlüsselt (lib/secrets.php)';
COMMENT ON COLUMN broker_connections_trading.environment IS 'sandbox (Papierkonto/Validierung beim Broker) oder live (echtes Geld)';
COMMENT ON COLUMN broker_connections_trading.capital_limit IS 'Höchstbetrag, den die KI über diesen Zugang einsetzen darf';
COMMENT ON TABLE accounts_trading IS 'Trading: Konten — strategy_paper (Testkonto je Strategie), portfolio_paper (virtuelles Gesamtportfolio), live (Brokerkonto)';
COMMENT ON COLUMN accounts_trading.last_signal_ts IS 'Zeitstempel des letzten Balkens, für den Signale verarbeitet wurden';
COMMENT ON TABLE positions_trading IS 'Trading: Bestände je Konto — nur was die Engine selbst gekauft hat';
COMMENT ON TABLE equity_trading IS 'Trading: Kontowert je Tag (laufender Tag wird bei jedem Lauf überschrieben)';
COMMENT ON TABLE allocations_trading IS 'Trading: Gewicht je Strategie im Gesamtportfolio, je Tag der Neuverteilung';
COMMENT ON TABLE orders_trading IS 'Trading: Orders aller Konten samt Ergebnis der Risikoprüfung';
COMMENT ON COLUMN orders_trading.status IS 'new, risk_rejected, submitted, partially_filled, filled, canceled, rejected, expired, error';
COMMENT ON TABLE fills_trading IS 'Trading: Ausführungen; (order_id, broker_fill_id) eindeutig — doppelt gemeldete Teilausführungen zählen einmal';
COMMENT ON TABLE risk_limits_trading IS 'Trading: Risikogrenzen der Risk-Engine — nur durch Administratoren änderbar (Trigger)';
COMMENT ON TABLE settings_trading IS 'Trading: Einstellungen (Gebühren, Forschungstakt, Übernahmekriterien) — nur durch Administratoren änderbar (Trigger)';
COMMENT ON TABLE locks_trading IS 'Trading: Handelssperren; aktiv solange cleared_at leer. Setzen darf jeder, aufheben nur ein Administrator (Trigger)';
COMMENT ON COLUMN locks_trading.scope IS 'all = gesamter Handel, live = nur Echtgeld';
COMMENT ON TABLE live_authorizations_trading IS 'Trading: Protokoll der Echtgeldfreigabe (enable/disable); der letzte Eintrag gilt, dauerhaft über Neustarts';
COMMENT ON TABLE state_trading IS 'Trading: Laufzeitzustand der Engine (letzter Lauf, letzter Forschungszyklus …)';
COMMENT ON TABLE audit_trading IS 'Trading: Audit-Protokoll aller Entscheidungen, Orders, Sperren und Freigaben';
COMMENT ON TABLE reports_trading IS 'Trading: tägliche Zusammenfassung, berechnet aus den gespeicherten Handels- und Marktdaten';

-- ============================================================================
-- SCHUTZ-TRIGGER
-- ============================================================================

CREATE OR REPLACE FUNCTION trading_guard_user_only() RETURNS trigger AS $$
BEGIN
    IF COALESCE(current_setting('oserp.trading_actor', true), '') <> 'user' THEN
        RAISE EXCEPTION 'TRADING_GUARD: % auf % ist nur durch einen angemeldeten Administrator erlaubt', TG_OP, TG_TABLE_NAME
            USING ERRCODE = '42501';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
DECLARE
    t text;
BEGIN
    -- Vollschutz: jede Änderung nur durch den Benutzer
    FOREACH t IN ARRAY ARRAY['risk_limits_trading', 'settings_trading', 'live_authorizations_trading', 'broker_connections_trading'] LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = t || '_guard') THEN
            EXECUTE format('CREATE TRIGGER %I BEFORE INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION trading_guard_user_only()', t || '_guard', t);
        END IF;
    END LOOP;
    -- Sperren: setzen darf die Engine, aufheben und löschen nur der Benutzer
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'locks_trading_guard') THEN
        CREATE TRIGGER locks_trading_guard BEFORE UPDATE OR DELETE ON locks_trading
            FOR EACH ROW EXECUTE FUNCTION trading_guard_user_only();
    END IF;
END $$;

-- ============================================================================
-- GRUNDAUSSTATTUNG
-- ============================================================================
--
-- Nur Erstbefüllung (ON CONFLICT DO NOTHING): spätere Änderungen des Benutzers
-- bleiben bei jedem weiteren Update erhalten. Die Freigabe für den Trigger gilt
-- nur in dieser Transaktion.

SELECT set_config('oserp.trading_actor', 'user', true);

INSERT INTO risk_limits_trading (key, value, unit, sortkey) VALUES
    ('max_order_value',          1000, 'currency', 10),
    ('max_position_pct',           35, 'percent',  20),
    ('max_gross_exposure_pct',    100, 'percent',  30),
    ('max_daily_loss_pct',          5, 'percent',  40),
    ('max_portfolio_drawdown_pct', 20, 'percent',  50),
    ('max_open_orders',            20, 'count',    60),
    ('max_orders_per_day',         40, 'count',    70),
    ('max_price_age_minutes',      30, 'minutes',  80),
    ('max_price_deviation_pct',    15, 'percent',  90),
    ('max_consecutive_errors',      3, 'count',   100),
    ('protective_stop_pct',        25, 'percent', 110)
ON CONFLICT (key) DO NOTHING;

INSERT INTO settings_trading (key, value, value_type, section, sortkey) VALUES
    ('engine_enabled',            '1',       'bool',   'engine',   10),
    ('lab_capital',               '10000',   'number', 'engine',   20),
    ('account_currency',          'EUR',     'text',   'engine',   30),
    ('market_data_source',        'binance', 'text',   'engine',   40),
    ('fee_bps',                   '40',      'number', 'costs',    10),
    ('slippage_bps',              '10',      'number', 'costs',    20),
    ('rebalance_threshold_pct',   '5',       'number', 'costs',    30),
    ('research_enabled',          '1',       'bool',   'research', 10),
    ('research_interval_hours',   '24',      'number', 'research', 20),
    ('research_population',       '24',      'number', 'research', 30),
    ('research_llm_enabled',      '0',       'bool',   'research', 40),
    ('max_paper_strategies',      '8',       'number', 'research', 50),
    ('split_train_pct',           '60',      'number', 'research', 60),
    ('split_validation_pct',      '20',      'number', 'research', 70),
    ('gate_min_test_sharpe',      '0.5',     'number', 'gates',    10),
    ('gate_max_test_drawdown_pct','35',      'number', 'gates',    20),
    ('gate_max_p_value',          '0.10',    'number', 'gates',    30),
    ('gate_min_deflated_sharpe',  '0.60',    'number', 'gates',    40),
    ('gate_min_stability',        '0.60',    'number', 'gates',    50),
    ('gate_min_trades',           '10',      'number', 'gates',    60),
    ('gate_live_min_paper_days',  '30',      'number', 'gates',    70),
    ('gate_live_min_paper_trades','3',       'number', 'gates',    80),
    ('gate_live_max_paper_drawdown_pct', '20', 'number', 'gates',  90),
    ('gate_live_min_paper_return_pct', '0',  'number', 'gates',    95),
    ('retire_drawdown_factor',    '1.5',     'number', 'gates',   100),
    ('report_hour',               '6',       'number', 'engine',   50)
ON CONFLICT (key) DO NOTHING;

INSERT INTO instruments_trading (symbol, name, asset_class, quote_currency, qty_decimals, is_benchmark) VALUES
    ('BTC/EUR',  'Bitcoin',   'crypto', 'EUR', 6, true),
    ('ETH/EUR',  'Ethereum',  'crypto', 'EUR', 5, false),
    ('SOL/EUR',  'Solana',    'crypto', 'EUR', 3, false),
    ('XRP/EUR',  'XRP',       'crypto', 'EUR', 1, false),
    ('ADA/EUR',  'Cardano',   'crypto', 'EUR', 1, false),
    ('LTC/EUR',  'Litecoin',  'crypto', 'EUR', 3, false),
    ('LINK/EUR', 'Chainlink', 'crypto', 'EUR', 2, false),
    ('DOT/EUR',  'Polkadot',  'crypto', 'EUR', 2, false)
ON CONFLICT (symbol) DO NOTHING;

INSERT INTO instrument_symbols_trading (instrument_id, venue, venue_symbol)
SELECT i.id, v.venue, v.venue_symbol
FROM (VALUES
    ('BTC/EUR',  'binance', 'BTCEUR'),  ('BTC/EUR',  'kraken', 'XBTEUR'),
    ('ETH/EUR',  'binance', 'ETHEUR'),  ('ETH/EUR',  'kraken', 'ETHEUR'),
    ('SOL/EUR',  'binance', 'SOLEUR'),  ('SOL/EUR',  'kraken', 'SOLEUR'),
    ('XRP/EUR',  'binance', 'XRPEUR'),  ('XRP/EUR',  'kraken', 'XRPEUR'),
    ('ADA/EUR',  'binance', 'ADAEUR'),  ('ADA/EUR',  'kraken', 'ADAEUR'),
    ('LTC/EUR',  'binance', 'LTCEUR'),  ('LTC/EUR',  'kraken', 'LTCEUR'),
    ('LINK/EUR', 'binance', 'LINKEUR'), ('LINK/EUR', 'kraken', 'LINKEUR'),
    ('DOT/EUR',  'binance', 'DOTEUR'),  ('DOT/EUR',  'kraken', 'DOTEUR')
) AS v (symbol, venue, venue_symbol)
JOIN instruments_trading i ON i.symbol = v.symbol
ON CONFLICT (instrument_id, venue) DO NOTHING;
