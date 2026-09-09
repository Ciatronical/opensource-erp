<?php
// backend/api/shop/index.php

/**
 * Zugang des Admin-Panels zur Shop-Erweiterung
 *
 * Fuer die Mitarbeiter des Shop-Betreibers: normale OpensourceERP-Sitzung,
 * Rechtepruefung ueber permit(). Die Kunden des Betreibers erreichen diesen
 * Einstiegspunkt nicht — sie haben keine Zeile in auth.user; ihr Zugang liegt
 * unter backend/shop/index.php.
 *
 * Beide Zugaenge teilen sich die Fachschicht unter lib/.
 */

require_once __DIR__.'/../faktura/faktura.php';
require_once __DIR__.'/../print/print.php';
require_once __DIR__.'/../print/template_engine.php';
require_once __DIR__.'/../email/smtp.class.php';

require_once __DIR__.'/lib/config.php';
require_once __DIR__.'/lib/context.php';
require_once __DIR__.'/lib/cart.php';
require_once __DIR__.'/lib/account.php';
require_once __DIR__.'/lib/search.php';
require_once __DIR__.'/lib/invoice.php';
require_once __DIR__.'/lib/mail.php';
require_once __DIR__.'/lib/payment.php';
require_once __DIR__.'/lib/analytics.php';
require_once __DIR__.'/lib/withdrawal.php';
require_once __DIR__.'/admin.php';

require_once __DIR__.'/../inc.php'; // muss immer unten stehen
