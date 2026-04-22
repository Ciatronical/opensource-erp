<?php
// backend/api/developer-tools/index.php

require_once __DIR__.'/database-backup.php';
require_once __DIR__.'/sql-tool.php'; // SQL-Tool zum Ausführen von SQL-Abfragen
require_once __DIR__.'/api-tester.php'; // API-Tester zum Testen von API-Funktionen
require_once __DIR__.'/test-parser.php'; // Schema-Parser Test-Script
require_once __DIR__.'/auto-test.php'; // Automatischer API-Test-Runner

require_once __DIR__.'/../inc.php'; // muss immer unten stehen