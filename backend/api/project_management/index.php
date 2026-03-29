<?php
// backend/api/project_management/index.php

/**
 * Projektmanagement-Endpunkt für OpensourceERP
 *
 * Pflichtenheft (Requirement Specs) und Ticket-System
 */

// Modul-Funktionen zuerst laden
require_once __DIR__.'/requirement_specs.php';
require_once __DIR__.'/tickets.php';

// inc.php lädt config, session, database UND api.call.php (Routing) - muss am Ende stehen
require_once __DIR__.'/../inc.php';
