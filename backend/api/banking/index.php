<?php
// backend/api/banking/index.php

require_once __DIR__.'/banking.php';
require_once __DIR__.'/transactions.php';
require_once __DIR__.'/matching.php';
require_once __DIR__.'/transfers.php';
require_once __DIR__.'/standing_orders.php';
require_once __DIR__.'/transfer_templates.php';
require_once __DIR__.'/banking_utils.php';
require_once __DIR__.'/banking_ai.php';
require_once __DIR__.'/direct_debit.php';
require_once __DIR__.'/fints.php';
require_once __DIR__.'/kasse.php';
require_once __DIR__.'/settlements.php';

// fmDataDir() (Mandanten-Datenverzeichnis) wird von kasse.php und settlements.php
// fuer die Dateiablage gebraucht, ist aber im customer_vendor-Modul definiert.
require_once __DIR__.'/../customer_vendor/filemanager.php';

require_once __DIR__.'/../inc.php'; // muss immer unten stehen
