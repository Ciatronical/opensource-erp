<?php
// backend/api/shop/lib/shop.php
//
// Lädt die Fachschicht der Shop-Erweiterung samt den Bausteinen des ERP, auf
// die sie aufsetzt. Eingebunden vom Admin-Zugang (../index.php), vom
// öffentlichen Zugang des Kundenportals (../public/actions.php) und von den
// Haken (portal_hooks.php), die der Kern auch ohne die Aktionen lädt.

require_once __DIR__.'/../../error.php';
require_once __DIR__.'/../../portal/lib/portal.php';   // Kundenportal: Webseite, Sitzung, Konto, Rechnungen, Mail, HugoCMS

require_once __DIR__.'/../../faktura/faktura.php';     // postArInvoiceToLedger
require_once __DIR__.'/../../print/print.php';         // renderDocumentPdfFile
require_once __DIR__.'/../../print/template_engine.php';
require_once __DIR__.'/../../email/smtp.class.php';
require_once __DIR__.'/../../customer_vendor/filemanager.php';   // Datenverzeichnis der Kanalbilder und der Medienbibliothek

require_once __DIR__.'/config.php';
require_once __DIR__.'/cart.php';
require_once __DIR__.'/account.php';
require_once __DIR__.'/search.php';
require_once __DIR__.'/invoice.php';
require_once __DIR__.'/mail.php';
require_once __DIR__.'/payment.php';
require_once __DIR__.'/analytics.php';
require_once __DIR__.'/withdrawal.php';
require_once __DIR__.'/redirect.php';
require_once __DIR__.'/special_tools.php';
require_once __DIR__.'/publish.php';
require_once __DIR__.'/categories.php';
require_once __DIR__.'/hugocms.php';
require_once __DIR__.'/sitecheck.php';     // Konfigurationsprüfung der Webseite (Haken site_checks)
require_once __DIR__.'/channel_images.php';
require_once __DIR__.'/media.php';
