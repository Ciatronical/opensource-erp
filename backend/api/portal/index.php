<?php
// backend/api/portal/index.php

/**
 * Zugang des Admin-Panels zum Kundenportal
 *
 * Für die Mitarbeiter: normale OpensourceERP-Sitzung, Rechteprüfung über
 * permit(). Die Kunden erreichen diesen Einstiegspunkt nicht — ihr Zugang
 * liegt unter backend/portal/index.php.
 */

require_once __DIR__.'/../error.php';
require_once __DIR__.'/../print/print.php';
require_once __DIR__.'/../print/template_engine.php';
require_once __DIR__.'/../email/smtp.class.php';

require_once __DIR__.'/lib/portal.php';
require_once __DIR__.'/admin.php';

require_once __DIR__.'/../inc.php'; // muss immer unten stehen
