<?php
// backend/api/faktura/index.php

require_once __DIR__.'/../lib/kontenrahmen.php';        // kassenkontoBedingung()
require_once __DIR__.'/../banking/banking_utils.php';  // nextBelegnummer() für Barzahlungen
require_once __DIR__.'/faktura.php';
require_once __DIR__.'/history.php';
require_once __DIR__.'/order_search.php';
require_once __DIR__.'/document_search.php';
require_once __DIR__.'/einvoice.php';
require_once __DIR__.'/recurring.php';
require_once __DIR__.'/gutachten_import.php';
require_once __DIR__.'/gutachten_review.php';
// Gutachten-Import legt die Datei im Fahrzeug-/Kundenordner ab (fmDataDir, ensureVehiclePaths)
require_once __DIR__.'/../customer_vendor/filemanager.php';
require_once __DIR__.'/../aag_online.php';

require_once __DIR__.'/../inc.php'; // muss immer unten stehen
