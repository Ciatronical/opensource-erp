<?php
// backend/api/voice/index.php

/**
 * Spracheingabe-API für OpensourceERP
 *
 * Proxy Browser -> lokaler Whisper-Dienst (der lauscht nur auf 127.0.0.1 und ist
 * daher nicht direkt vom Browser erreichbar) plus Pflege des Fachbegriffe-Glossars.
 */

require_once __DIR__.'/voice.php';

require_once __DIR__.'/../inc.php'; // muss immer unten stehen
