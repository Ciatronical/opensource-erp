<?php
// backend/api/sms/index.php
//
// SMS (Kontakt → SMS): Gespräche, Versand mit Beleg als Link, Einrichtung bei seven.io.
require_once __DIR__.'/../customer_vendor/filemanager.php';
require_once __DIR__.'/sms_lib.php';
require_once __DIR__.'/sms.php';

require_once __DIR__.'/../inc.php'; // muss immer unten stehen
