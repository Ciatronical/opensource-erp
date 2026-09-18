<?php
// api/inc.php

// resultInfo() und ApiError stehen in error.php — siehe dort, warum.
require_once __DIR__.'/error.php';

// Core-Komponenten laden. config.php definiert auch setupExists().
require_once __DIR__.'/config.php';
require_once __DIR__.'/logging.php';
require_once __DIR__.'/password.php';
require_once __DIR__.'/database.php';
require_once __DIR__.'/lib/ai_model.php';
require_once __DIR__.'/lib/directory_browser.php';
require_once __DIR__.'/lib/extensions.php';
require_once __DIR__.'/lib/upstall.php';
require_once __DIR__.'/lib/tenant.php';
require_once __DIR__.'/session.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/api.call.php';
