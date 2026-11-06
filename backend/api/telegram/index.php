<?php
// backend/api/telegram/index.php
//
// Telegram-Kundenchat (Kontakt → Telegram, Reiter Telegram): Gespräche, Antworten mit Datei, Zuordnung.
require_once __DIR__.'/../customer_vendor/filemanager.php';
require_once __DIR__.'/../sms/sms_lib.php';
require_once __DIR__.'/telegram_lib.php';
require_once __DIR__.'/telegram.php';

require_once __DIR__.'/../inc.php'; // muss immer unten stehen
