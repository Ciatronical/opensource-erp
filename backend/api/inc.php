<?php
// api/inc.php

// resultInfo() und ApiError stehen in error.php — siehe dort, warum.
require_once __DIR__.'/error.php';

/**
 * Prüft ob settings.ini bereits existiert
 *
 * @return bool True wenn Setup bereits durchgeführt wurde
 */
function setupExists(): bool {
    $settingsIniPath = SETUP_SETTINGS_DIR . SETUP_SETTINGS_INI_FILE;
    return file_exists($settingsIniPath);
}

// Core-Komponenten laden
require_once __DIR__.'/config.php';
require_once __DIR__.'/logging.php';
require_once __DIR__.'/password.php';
require_once __DIR__.'/database.php';
require_once __DIR__.'/lib/extensions.php';
require_once __DIR__.'/lib/upstall.php';
require_once __DIR__.'/session.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/api.call.php';
