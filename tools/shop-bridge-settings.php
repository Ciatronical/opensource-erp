#!/usr/bin/env php
<?php
// tools/shop-bridge-settings.php
//
// Übernimmt die Einstellungen einer Shop-Instanz aus der Bridge: liest deren
// bridge-config (config.php, passwd.php) und gibt die passenden
// shop_*-Einstellungen für OpensourceERP als SQL aus.
//
//   php tools/shop-bridge-settings.php <webseite>/bridge-config
//   php tools/shop-bridge-settings.php <webseite>/bridge-config | psql -d <mandant>
//
// Gibt nur aus, schreibt nichts — das Ergebnis gehört gelesen, bevor es in
// eine Datenbank geht. Es enthält die PayPal-Zugangsdaten im Klartext. Werte,
// die die Instanz nicht setzt, bleiben heraus; dort gilt, was das Schema
// angelegt hat.
//
// Hervorgegangen aus web/oserp/einstellungen-uebernehmen.php der Bridge,
// ergänzt um die Einstellungen der Veröffentlichung: Webseiten-Verzeichnis
// (relativ zu shop_sites_dir aus der settings.ini) und Inhaltsordner.
//
// Die PayPal-Zugangsdaten kommen aus der passwd.php, und zwar BEIDE Paare:
// dort steht eine Weiche auf $PAYPAL_SANDBOX, in OpensourceERP stehen Test-
// und Echtbetrieb nebeneinander.
//
// NICHT übernommen werden der Shop-Schlüssel (neu zu vergeben), die Adresse
// von OpensourceERP für Proxy und 404-Seite (gab es in der Bridge nicht) und
// alles, was mit der Migration entfallen ist: KIVI_ERP_PATH,
// KIVI_INVOICE_TEX_FILE, die PHPMAILER_*-Angaben (Mailversand über die
// E-Mail-Einstellungen des ERP) und KIVI_DATE_FORMAT (formatiert wird in der
// Oberfläche).
//
// Die config.php der Instanz definiert DB_HOST, DB_NAME und weitere
// Konstanten, die auch OpensourceERP verwendet. Das Skript lädt deshalb die
// Konfiguration von OpensourceERP nicht, sondern liest aus der settings.ini
// nur shop_sites_dir.

if ('cli' !== PHP_SAPI) {
    fwrite(STDERR, "Nur auf der Kommandozeile.\n");
    exit(1);
}
if ($argc < 2) {
    fwrite(STDERR, "Aufruf: php ".basename(__FILE__)." <bridge-config-Verzeichnis>\n");
    exit(1);
}

$verzeichnis = rtrim($argv[1], '/');
$konfig      = $verzeichnis.'/config.php';

if (!is_file($konfig)) {
    fwrite(STDERR, "Nicht gefunden: $konfig\n");
    exit(1);
}

define('KIVI_CONFIG_DIR', $verzeichnis);
@include $konfig;

/**
 * Die beiden PayPal-Zugangsdatenpaare aus der passwd.php
 *
 * Dort steht eine Weiche: je nach $PAYPAL_SANDBOX werden CLIENT_ID und
 * CLIENT_SECRET auf das eine oder das andere Paar gesetzt. Ein Include liefert
 * deshalb immer nur eines — und Konstanten lassen sich nicht neu belegen.
 * Beide Zweige werden darum aus dem Quelltext gelesen.
 *
 * Welcher Zweig welcher ist, entscheidet nicht die Reihenfolge, sondern die
 * Bedingung: hinter if(!$PAYPAL_SANDBOX) steht der Echtbetrieb, hinter dem
 * zugehörigen else die Testumgebung. Vertauschte Zugangsdaten wären ein
 * teurer Fehler — deshalb wird die Grenze gesucht statt angenommen. Lässt sie
 * sich nicht finden, kommt gar nichts zurück und eine Warnung nach stderr;
 * die Zugangsdaten gehören dann von Hand eingetragen.
 */
function paypalPaare(string $verzeichnis): array {
    $datei = $verzeichnis.'/passwd.php';
    if (!is_file($datei)) {
        return [];
    }

    $quelle = file_get_contents($datei);

    $gefunden = preg_match_all(
        '/define\(\s*\'(CLIENT_ID|CLIENT_SECRET)\'\s*,\s*\'([^\']*)\'\s*\)/',
        $quelle, $treffer, PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );
    if (!$gefunden) {
        return [];
    }

    if (!preg_match('/if\s*\(\s*!\s*\$PAYPAL_SANDBOX\s*\)/', $quelle, $m, PREG_OFFSET_CAPTURE)) {
        fwrite(STDERR, "Warnung: if(!\$PAYPAL_SANDBOX) nicht gefunden — PayPal-Zugangsdaten übersprungen.\n");
        return [];
    }
    $abEchtbetrieb = $m[0][1];

    if (!preg_match('/\belse\b/', substr($quelle, $abEchtbetrieb), $m2, PREG_OFFSET_CAPTURE)) {
        fwrite(STDERR, "Warnung: else-Zweig nicht gefunden — PayPal-Zugangsdaten übersprungen.\n");
        return [];
    }
    $abTestumgebung = $abEchtbetrieb + $m2[0][1];

    $paare = [];
    foreach ($treffer as $t) {
        $feld   = 'CLIENT_ID' === $t[1][0] ? 'client_id' : 'secret';
        $wert   = $t[2][0];
        $stelle = $t[0][1];

        // Vor der Weiche definierte Werte gelten für beide Betriebsarten;
        // sie kommen als Echtbetrieb an und lassen sich im Admin-Panel
        // nachziehen.
        $art = $stelle >= $abTestumgebung ? 'sandbox' : 'live';
        $paare[$art][$feld] = $wert;
    }

    return $paare;
}

/** Konstante, oder null wenn sie die Instanz nicht setzt */
function wert(string $name) {
    return defined($name) ? constant($name) : null;
}

/** Wahrheitswert als '1'/'0' */
function wahrheit(string $name) {
    $w = wert($name);
    return null === $w ? null : ($w ? '1' : '0');
}

/**
 * Webseiten-Verzeichnis relativ zu shop_sites_dir
 *
 * Die bridge-config liegt im Verzeichnis der Webseite.
 *
 * @return array Pfad oder null, dazu der Grund, wenn er fehlt
 */
function webseitenVerzeichnis(string $bridgeConfig): array {
    $webseite = realpath(dirname(realpath($bridgeConfig)));
    $ini = @parse_ini_file(__DIR__.'/../backend/config/settings.ini', true) ?: [];
    $wurzel = (string)($ini['system']['shop_sites_dir'] ?? '');
    $wurzelEcht = '' === $wurzel ? false : realpath($wurzel);

    if (false === $wurzelEcht) {
        return [null, 'shop_sites_dir fehlt in der settings.ini oder das Verzeichnis gibt es nicht'];
    }
    if (!str_starts_with($webseite.'/', $wurzelEcht.'/') || $webseite === $wurzelEcht) {
        return [null, "die Webseite $webseite liegt nicht unter shop_sites_dir ($wurzelEcht)"];
    }
    return [substr($webseite, strlen($wurzelEcht) + 1), ''];
}

/**
 * Inhaltsordner relativ zur Webseite
 *
 * KIVI_CONTENT_PATH ist ein absoluter Pfad auf dem Server der Bridge, etwa
 * /var/www/hugoshops/sonic24.de/content/de/produkt/. Übernommen wird, was
 * hinter dem Namen des Webseiten-Verzeichnisses steht.
 */
function inhaltsordner($pfad, string $bridgeConfig): ?string {
    if (null === $pfad || '' === (string)$pfad) {
        return null;
    }
    $name = '/'.basename(dirname(realpath($bridgeConfig))).'/';
    $stelle = strrpos((string)$pfad, $name);
    if (false === $stelle) {
        return null;
    }
    $relativ = trim(substr((string)$pfad, $stelle + strlen($name)), '/');
    return '' === $relativ ? null : $relativ;
}

$paypal = paypalPaare($verzeichnis);
[$siteDir, $siteGrund] = webseitenVerzeichnis($verzeichnis);

$zuordnung = [
    'shop_contact_login'                    => wert('KIVI_SHOP_CONTACT_LOGIN'),
    'shop_target_account'                   => wert('KIVI_TARGET_ACCOUNT'),
    'shop_incoming_account'                 => wert('KIVI_INCOMING_ACCOUNT'),
    'shop_standard_taxzone'                 => wert('KIVI_STANDARD_TAXZONE'),
    'shop_standard_currency'                => wert('KIVI_STANDARD_CURRENCY'),
    'shop_tax_included'                     => wahrheit('KIVI_TAX_INCLUDED'),
    'shop_active_price_source'              => wert('KIVI_ACTIVE_PRICE_SOURCE'),
    'shop_shipping_partnumber'              => wert('KIVI_SIPPING_COST_PARTNUMBER'),
    'shop_free_shipping_from'               => wert('KIVI_ZERO_SIPPING_COSTS_FROM'),
    'shop_payment_account_owner'            => wert('KIVI_PAYMENT_TERMS_ACCOUNT_OWNER'),
    'shop_payment_bank'                     => wert('KIVI_PAYMENT_TERMS_BANK'),
    'shop_payment_iban'                     => wert('KIVI_PAYMENT_TERMS_IBAN'),
    'shop_payment_bic'                      => wert('KIVI_PAYMENT_TERMS_BIC'),
    'shop_paypal_live_client_id'            => $paypal['live']['client_id']    ?? null,
    'shop_paypal_live_secret'               => $paypal['live']['secret']       ?? null,
    'shop_paypal_sandbox_client_id'         => $paypal['sandbox']['client_id'] ?? null,
    'shop_paypal_sandbox_secret'            => $paypal['sandbox']['secret']    ?? null,
    'shop_paypal_sandbox'                   => isset($PAYPAL_SANDBOX) ? ($PAYPAL_SANDBOX ? '1' : '0') : null,
    'shop_paypal_payment_method_preference' => wert('PAYPAL_PAYMENT_METHOD_PREFERENCE'),
    'shop_paypal_mock_response'             => wert('PAYPAL_MOCK_RESPONSE'),
    'shop_base_url'                         => wert('KIVI_BASE_LINK'),
    'shop_products_link'                    => wert('KIVI_PRODUCTS_LINK'),
    'shop_category_link'                    => wert('KIVI_CATEGORY_LINK'),
    'shop_thumbnails_link'                  => wert('KIVI_PRODUCTS_THUMBNAILS'),
    'shop_search_weighting'                 => wert('HUGOSHOP_SEARCH_WEIGHTING'),
    'shop_invoice_mail_subject'             => wert('KIVI_INVOICE_MAIL_SUBJECT'),
    'shop_withdrawal_mail_to'               => wert('KIVI_WIDERRUF_MAIL_TO'),
    'shop_site_dir'                         => $siteDir,
    'shop_content_dir'                      => inhaltsordner(wert('KIVI_CONTENT_PATH'), $verzeichnis),
];

echo "-- Aus $konfig übernommen am ".date('Y-m-d H:i')."\n";
echo "-- Vor dem Einspielen lesen: die Werte überschreiben, was im Admin-Panel steht.\n";
echo "--\n";
echo "-- NOCH ZU SETZEN, hier nicht enthalten:\n";
echo "--   shop_public_key      — neu vergeben; der Läufer trägt ihn in oserp-shop/config.php ein\n";
echo "--   shop_backend_url     — Adresse von OpensourceERP für Proxy und 404-Seite, z.B. https://erp.example/shop/\n";
echo "--   shop_template_set    — eigener Vorlagensatz der Instanz, falls es einen gibt\n";
echo "--   shop_allowed_origins — nur ohne Proxy nötig\n";
if (null === $siteDir) {
    echo "--   shop_site_dir        — $siteGrund\n";
}
echo "\n";

foreach ($zuordnung as $schluessel => $w) {
    if (null === $w || '' === (string)$w) {
        echo "-- $schluessel: in der Instanz nicht gesetzt, Vorgabe bleibt\n";
        continue;
    }
    printf(
        "UPDATE defaults_oserp SET value = %s, mtime = now() WHERE key = %s;\n",
        "'".str_replace("'", "''", (string)$w)."'",
        "'".$schluessel."'"
    );
}

// Der Versandartikel muss vorhanden sein — das Schema legt ihn bewusst nicht an
$versand = wert('KIVI_SIPPING_COST_PARTNUMBER');
if (null !== $versand) {
    echo "\n-- Prüfen, ob der Versandartikel existiert (das Schema legt ihn nicht an):\n";
    echo "-- SELECT id, partnumber, description, sellprice FROM parts WHERE partnumber = '".
         str_replace("'", "''", (string)$versand)."';\n";
}
