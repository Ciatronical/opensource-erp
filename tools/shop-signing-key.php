#!/usr/bin/env php
<?php
// tools/shop-signing-key.php
//
// Signaturschlüssel der Shop-Erweiterung (dev/shop-php-signatur.md).
//
// HugoCMS nimmt die PHP-Einstiegspunkte des Webseiten-Pakets (Weiterleiter
// shop-api/index.php, 404-Seite not_found.php) nur an, wenn OpensourceERP sie
// signiert hat. Dafür braucht diese Installation ein Ed25519-Schlüsselpaar:
// Der private Schlüssel liegt in backend/config/shop-signing.key (nicht im
// Repository, Rechte 0600), den öffentlichen trägt ein Administrator in HugoCMS
// ein (Projekteinstellungen → Shop-Anbindung → Signaturschlüssel). Ein
// Schlüsselpaar gilt für alle Mandanten und alle HugoShops dieser Installation.
//
// Üblicher Weg sind die Systemeinstellungen (Abschnitt Shop-Erweiterung). Das
// Werkzeug ist für Server, auf denen der Webserver nicht in backend/config/
// schreiben darf; beide nutzen backend/api/shop/lib/signing.php.
//
// Aufruf:
//   php tools/shop-signing-key.php            öffentlichen Schlüssel anzeigen
//   php tools/shop-signing-key.php --create   Schlüsselpaar erzeugen, falls keines da ist
//   php tools/shop-signing-key.php --create --force
//                                             neues Schlüsselpaar, ersetzt das alte —
//                                             danach in jedem HugoCMS neu eintragen
//
// Als der Benutzer aufrufen, unter dem auch der Läufer (Cron) und der
// Webserver laufen (etwa www-data): beide müssen die Datei lesen können.

if ('cli' !== PHP_SAPI) {
    fwrite(STDERR, "Nur auf der Kommandozeile.\n");
    exit(1);
}

require_once __DIR__.'/../backend/api/shop/lib/signing.php';

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "PHP hat keine Sodium-Erweiterung — ohne sie gibt es keine Signatur.\n");
    exit(1);
}

$argumente = getopt('', ['create', 'force', 'help']);
if (isset($argumente['help'])) {
    echo "php tools/shop-signing-key.php [--create [--force]]\n";
    exit(0);
}

$datei = shopSigningKeyFile();

if (isset($argumente['create'])) {
    $ergebnis = shopSigningCreate(isset($argumente['force']));
    if ('SIGNING_KEY_EXISTS' === $ergebnis['fehler']) {
        fwrite(STDERR, "Es gibt schon einen Schlüssel ($datei). Ersetzen nur mit --force — "
            ."danach muss der neue öffentliche Schlüssel in jedem HugoCMS eingetragen werden.\n");
        exit(1);
    }
    if (!$ergebnis['ok']) {
        fwrite(STDERR, "Konnte $datei nicht schreiben.\n");
        exit(1);
    }
    echo "Schlüsselpaar erzeugt: $datei\n";
}

$oeffentlich = shopSigningPublicKey();
if ('' === $oeffentlich) {
    fwrite(STDERR, is_file($datei)
        ? "$datei ist nicht lesbar oder enthält keinen gültigen Schlüssel.\n"
        : "Noch kein Schlüssel — mit --create erzeugen.\n");
    exit(1);
}

echo "Öffentlicher Schlüssel (in HugoCMS unter Projekteinstellungen → Shop-Anbindung eintragen):\n";
echo $oeffentlich, "\n";
