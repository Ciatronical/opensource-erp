<?php
// backend/api/shop/index.php

/**
 * Zugang des Admin-Panels zur Shop-Erweiterung
 *
 * Fuer die Mitarbeiter des Shop-Betreibers: normale OpensourceERP-Sitzung,
 * Rechtepruefung ueber permit(). Die Kunden des Betreibers erreichen diesen
 * Einstiegspunkt nicht — sie haben keine Zeile in auth.user; ihr Zugang liegt
 * im Kundenportal unter backend/portal/index.php (und weiterhin
 * backend/shop/index.php).
 *
 * Beide Zugaenge teilen sich die Fachschicht unter lib/; Sitzung, Konto und
 * Rechnungen kommen aus dem Kern (backend/api/portal/lib/).
 */

// ApiError muss VOR lib/payment.php stehen: dort erbt ShopPaymentError davon,
// und eine Basisklasse muss beim Laden der Datei bekannt sein — anders als
// Funktionen, die erst beim Aufruf aufgeloest werden. inc.php gehoert ans Ende
// (Projektkonvention) und kaeme dafuer zu spaet; error.php ist genau dafuer aus
// inc.php herausgeloest. Doppeltes Laden schadet nicht, inc.php holt es sich
// per require_once ohnehin.
require_once __DIR__.'/../error.php';

// Die Fachschicht des Shops samt Kundenportal (lib/shop.php) und die Haken,
// über die der Shop am Portal hängt
require_once __DIR__.'/lib/portal_hooks.php';
require_once __DIR__.'/admin.php';
require_once __DIR__.'/media.php';
require_once __DIR__.'/assortment.php';  // Ansicht Sortiment: Gruppen und Kategorien
require_once __DIR__.'/kleinanzeigen.php'; // Verkaufskanal Kleinanzeigen: Board, Anzeigen, Verkauf, Anfragen
require_once __DIR__.'/ebay.php';          // Verkaufskanal eBay: Board, Prüfung der Käufer, Angebote, Produktseiten

require_once __DIR__.'/../inc.php'; // muss immer unten stehen
