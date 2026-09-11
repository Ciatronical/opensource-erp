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

// ApiError muss VOR lib/payment.php stehen: dort erbt ShopPaymentError davon,
// und eine Basisklasse muss beim Laden der Datei bekannt sein — anders als
// Funktionen, die erst beim Aufruf aufgeloest werden. inc.php gehoert ans Ende
// (Projektkonvention) und kaeme dafuer zu spaet; error.php ist genau dafuer aus
// inc.php herausgeloest. Doppeltes Laden schadet nicht, inc.php holt es sich
// per require_once ohnehin.
require_once __DIR__.'/../error.php';

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
require_once __DIR__.'/lib/redirect.php';
require_once __DIR__.'/lib/publish.php';
require_once __DIR__.'/admin.php';

require_once __DIR__.'/../inc.php'; // muss immer unten stehen
