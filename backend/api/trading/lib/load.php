<?php
// backend/api/trading/lib/load.php
//
// Lädt alle Bausteine des Handelssystems. Gemeinsamer Einstieg für die API
// (index.php), die Cron-Engine (backend/cli/trading-engine.php) und die Tests.

require_once __DIR__.'/../../lib/secrets.php';
require_once __DIR__.'/core.php';
require_once __DIR__.'/indicators.php';
require_once __DIR__.'/stats.php';
require_once __DIR__.'/strategies.php';
require_once __DIR__.'/backtest.php';
require_once __DIR__.'/marketdata.php';
require_once __DIR__.'/brokers.php';
require_once __DIR__.'/risk.php';
require_once __DIR__.'/orders.php';
require_once __DIR__.'/portfolio.php';
require_once __DIR__.'/research.php';
require_once __DIR__.'/report.php';
require_once __DIR__.'/engine.php';
