<?php
// backend/api/lxcars/index.php

/**
 * Update-Endpunkt für OpensourceERP
 *
 * Behandelt Update-Anfragen im einheitlichen Backend-Pattern
 */

// Update-Funktionen laden
require_once __DIR__.'/cars.php';
require_once __DIR__.'/instructions.php';
require_once __DIR__.'/maengel.php';
require_once __DIR__.'/scan_images.php';
require_once __DIR__.'/carreg.php';
require_once __DIR__.'/hu_serienbrief.php';
require_once __DIR__.'/ai_positions.php';
require_once __DIR__.'/car_chat.php';
require_once __DIR__.'/labels.php';
require_once __DIR__.'/mechanic.php';
require_once __DIR__.'/reports.php';
require_once __DIR__.'/anpr.php';
require_once __DIR__.'/sales_text.php';
require_once __DIR__.'/../aag_online.php';
require_once __DIR__.'/../hgs_data.php';
require_once __DIR__.'/../customer_vendor/filemanager.php';

require_once __DIR__.'/../inc.php'; // muss mímmer unten stehen
