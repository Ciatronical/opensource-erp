#!/usr/bin/env php
<?php
// tools/shop-bridge-settings.php
//
// Übernimmt die Einstellungen einer Shop-Instanz aus der Bridge: liest deren
// bridge-config (config.php, passwd.php) und gibt die passenden Einstellungen
// für OpensourceERP als SQL aus.
//
//   php tools/shop-bridge-settings.php <webseite>/bridge-config ["<Name des HugoShops>"]
//   php tools/shop-bridge-settings.php <webseite>/bridge-config | psql -d <mandant>
//
// Seit es mehrere HugoShops gibt (dev/shop-mehrere-kanaele.md), gehen die
// Einstellungen der Instanz — Adressen, Verzeichnisse, PayPal, Mails,
// Freigrenze — in den Verkaufskanal: ohne Namen in den ersten HugoShop,
// mit Namen in den HugoShop dieses Namens. Was für den ganzen Mandanten gilt
// (Konten, Steuerzone, Bankverbindung, Wurzel der Webseiten …), bleibt in
// defaults_oserp.
//
// Gibt nur aus, schreibt nichts — das Ergebnis gehört gelesen, bevor es in
// eine Datenbank geht. Es enthält die PayPal-Zugangsdaten im Klartext. Werte,
// die die Instanz nicht setzt, bleiben heraus; dort gilt, was das Schema
// angelegt hat.
//
// Hervorgegangen aus web/oserp/einstellungen-uebernehmen.php der Bridge,
// ergänzt um die Einstellungen der Veröffentlichung: Wurzelverzeichnis der
// Webseiten, Verzeichnis dieser Webseite und Inhaltsordner. Alle drei ergeben
// sich aus dem Pfad der bridge-config.
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
// Konfiguration von OpensourceERP gar nicht.

if ('cli' !== PHP_SAPI) {
    fwrite(STDERR, "Nur auf der Kommandozeile.\n");
    exit(1);
}
if ($argc < 2) {
    fwrite(STDERR, "Aufruf: php ".basename(__FILE__)." <bridge-config-Verzeichnis>\n");
    exit(1);
}

$verzeichnis = rtrim($argv[1], '/');
$kanalName   = trim((string)($argv[2] ?? ''));
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

$zuordnung = [
    'shop_contact_login'                    => wert('KIVI_SHOP_CONTACT_LOGIN'),
    'shop_target_account'                   => wert('KIVI_TARGET_ACCOUNT'),
    'shop_incoming_account'                 => wert('KIVI_INCOMING_ACCOUNT'),
    'shop_standard_taxzone'                 => wert('KIVI_STANDARD_TAXZONE'),
    'shop_standard_currency'                => wert('KIVI_STANDARD_CURRENCY'),
    'shop_tax_included'                     => wahrheit('KIVI_TAX_INCLUDED'),
    'shop_active_price_source'              => wert('KIVI_ACTIVE_PRICE_SOURCE'),
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
    'shop_content_dir'                      => inhaltsordner(wert('KIVI_CONTENT_PATH'), $verzeichnis),
];

echo "-- Aus $konfig übernommen am ".date('Y-m-d H:i')."\n";
echo "-- Vor dem Einspielen lesen: die Werte überschreiben, was im Admin-Panel steht.\n";
echo "--\n";
echo "-- NOCH ZU SETZEN, hier nicht enthalten (Kanalkarte des HugoShops):\n";
echo "--   Shop-Schlüssel       — neu vergeben; der Läufer trägt ihn in oserp-shop/config.json ein\n";
echo "--   Adresse von OSERP    — für Proxy und 404-Seite, z.B. https://erp.example/shop/\n";
echo "--   Vorlagensatz         — eigener Vorlagensatz der Instanz, falls es einen gibt\n";
echo "--   Erlaubte Herkunft    — nur ohne Proxy nötig\n";
echo "--   HugoCMS          — Adresse und Schlüssel; veröffentlicht wird nur über HugoCMS\n";
echo "\n";

// Einstellungen der Instanz: Schlüssel im Kanal (ohne Präfix) und ob geheim —
// wie shop_channel_setting_keys() im Schema
const INSTANZ = [
    'shop_paypal_live_client_id'            => ['paypal_live_client_id', false],
    'shop_paypal_live_secret'               => ['paypal_live_secret', true],
    'shop_paypal_sandbox_client_id'         => ['paypal_sandbox_client_id', false],
    'shop_paypal_sandbox_secret'            => ['paypal_sandbox_secret', true],
    'shop_paypal_sandbox'                   => ['paypal_sandbox', false],
    'shop_paypal_payment_method_preference' => ['paypal_payment_method_preference', false],
    'shop_paypal_mock_response'             => ['paypal_mock_response', false],
    'shop_base_url'                         => ['base_url', false],
    'shop_products_link'                    => ['products_link', false],
    'shop_category_link'                    => ['category_link', false],
    'shop_thumbnails_link'                  => ['thumbnails_link', false],
    'shop_invoice_mail_subject'             => ['invoice_mail_subject', false],
    'shop_withdrawal_mail_to'               => ['withdrawal_mail_to', false],
    'shop_content_dir'                      => ['content_dir', false],
];

$text = fn($wert) => "'".str_replace("'", "''", (string)$wert)."'";
$kanal = '' === $kanalName
    ? "shop_first_channel_id('hugoshop')"
    : "(SELECT id FROM sales_channel_shop WHERE type = 'hugoshop' AND name = ".$text($kanalName).")";
echo "-- Einstellungen der Instanz gehen in den HugoShop ".('' === $kanalName ? '(erster HugoShop)' : '„'.$kanalName.'"')."\n\n";

foreach ($zuordnung as $schluessel => $w) {
    if (null === $w || '' === (string)$w) {
        echo "-- $schluessel: in der Instanz nicht gesetzt, Vorgabe bleibt\n";
        continue;
    }
    if ('shop_free_shipping_from' === $schluessel) {
        // Freigrenze je Kanal (dev/shop-versand.md, Entscheidung 4)
        printf("UPDATE sales_channel_shop SET free_shipping_from = %s WHERE id = %s;\n",
               $text(str_replace(',', '.', (string)$w)), $kanal);
    } elseif (isset(INSTANZ[$schluessel]) && INSTANZ[$schluessel][1]) {
        printf("INSERT INTO sales_channel_secret_shop (channel_id, key, value) VALUES (%s, %s, %s)\n"
               ."    ON CONFLICT (channel_id, key) DO UPDATE SET value = EXCLUDED.value, mtime = now();\n",
               $kanal, $text(INSTANZ[$schluessel][0]), $text($w));
    } elseif (isset(INSTANZ[$schluessel])) {
        printf("UPDATE sales_channel_shop SET settings = COALESCE(settings, '{}'::jsonb) || jsonb_build_object(%s, %s) WHERE id = %s;\n",
               $text(INSTANZ[$schluessel][0]), $text($w), $kanal);
    } else {
        printf("UPDATE defaults_oserp SET value = %s, mtime = now() WHERE key = %s;\n", $text($w), $text($schluessel));
    }
}

// KIVI_SIPPING_COST_PARTNUMBER wird nicht übernommen: jede Versandart hat
// ihren eigenen Versandartikel (Ansicht „Versandarten", dev/shop-versand.md)
