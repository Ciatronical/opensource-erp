<?php
// backend/api/portal/lib/signing.php
//
// Signaturschlüssel des Kundenportals (dev/shop-php-signatur.md).
//
// HugoCMS nimmt die PHP-Einstiegspunkte des Webseiten-Pakets (Weiterleiter,
// 404-Seite) nur signiert an. Der private Ed25519-Schlüssel gehört dieser
// Installation (S1, S2) und liegt als Datei neben der settings.ini, nicht in
// der Datenbank und nicht im Repository. Erzeugt wird er in den
// Systemeinstellungen (nur Systemadministratoren) oder mit
// tools/shop-signing-key.php; den öffentlichen Schlüssel trägt ein
// Administrator in HugoCMS ein.
//
// Der Dateiname (shop-signing.key) und der Zweck der Signatur stammen aus der
// Zeit, als nur der Shop signierte — beide bleiben, damit vorhandene
// Schlüssel und HugoCMS-Einträge weiter gelten.

/** Zweck der Signatur — muss zu ShopSync::signatureMessage() in HugoCMS passen */
const PORTAL_SIGNING_CONTEXT = "hugocms-shop-php\n";

/** Datei mit dem privaten Signaturschlüssel (Base64, 64 Bytes) */
function portalSigningKeyFile(): string {
    return dirname(__DIR__, 3).'/config/shop-signing.key';
}

/**
 * Privater Signaturschlüssel dieser Installation
 *
 * @return string Rohbytes, leer ohne Datei, ohne Sodium oder bei ungültigem Inhalt
 */
function portalSigningSecretKey(): string {
    $datei = portalSigningKeyFile();
    if (!function_exists('sodium_crypto_sign_detached') || !is_file($datei) || !is_readable($datei)) {
        return '';
    }
    $schluessel = base64_decode(trim((string)file_get_contents($datei)), true);
    return false !== $schluessel && SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === strlen($schluessel) ? $schluessel : '';
}

/** Öffentlicher Schlüssel dieser Installation, Base64 — zum Eintragen in HugoCMS; leer ohne Schlüssel */
function portalSigningPublicKey(): string {
    $geheim = portalSigningSecretKey();
    return '' === $geheim ? '' : base64_encode(sodium_crypto_sign_publickey_from_secretkey($geheim));
}

/**
 * Signatur einer PHP-Datei des Pakets: über Zweck, Pfad und Prüfsumme
 *
 * @param string $pfad Pfad in der Lieferung, etwa oserp-portal/static/not_found.php
 * @param string $sha256 Prüfsumme der Datei (hex)
 * @return string Base64, leer ohne Schlüssel
 */
function portalSigningSign(string $pfad, string $sha256): string {
    $geheim = portalSigningSecretKey();
    if ('' === $geheim) {
        return '';
    }
    return base64_encode(sodium_crypto_sign_detached(PORTAL_SIGNING_CONTEXT.$pfad."\n".strtolower($sha256), $geheim));
}

/**
 * Stand des Signaturschlüssels — für die Systemeinstellungen
 *
 * @return array available (Sodium vorhanden), exists (Datei da), valid,
 *               public_key (Base64, leer ohne gültigen Schlüssel), writable
 *               (lässt sich anlegen oder ersetzen), file (Pfad)
 */
function portalSigningStatus(): array {
    $datei = portalSigningKeyFile();
    $oeffentlich = portalSigningPublicKey();
    return [
        'available'  => function_exists('sodium_crypto_sign_keypair'),
        'exists'     => is_file($datei),
        'valid'      => '' !== $oeffentlich,
        'public_key' => $oeffentlich,
        'writable'   => is_file($datei) ? is_writable($datei) && is_writable(dirname($datei)) : is_writable(dirname($datei)),
        'file'       => $datei,
    ];
}

/**
 * Erzeugt das Schlüsselpaar dieser Installation
 *
 * Geschrieben wird über eine temporäre Datei mit Rechten 0600 und rename(),
 * damit nie eine halbe oder lesbare Datei dasteht.
 *
 * @param bool $ersetzen einen vorhandenen Schlüssel ersetzen — danach muss der
 *                       neue öffentliche Schlüssel in jedem HugoCMS eingetragen werden
 * @return array ok, fehler (SIGNING_UNAVAILABLE, SIGNING_KEY_EXISTS,
 *               SIGNING_NOT_WRITABLE, leer bei Erfolg), public_key
 */
function portalSigningCreate(bool $ersetzen = false): array {
    $datei = portalSigningKeyFile();
    if (!function_exists('sodium_crypto_sign_keypair')) {
        return ['ok' => false, 'fehler' => 'SIGNING_UNAVAILABLE', 'public_key' => ''];
    }
    if (is_file($datei) && !$ersetzen) {
        return ['ok' => false, 'fehler' => 'SIGNING_KEY_EXISTS', 'public_key' => portalSigningPublicKey()];
    }

    $paar = sodium_crypto_sign_keypair();
    $alteMaske = umask(0077);
    $geschrieben = @file_put_contents($datei.'.tmp', base64_encode(sodium_crypto_sign_secretkey($paar))."\n");
    umask($alteMaske);
    sodium_memzero($paar);
    if (false === $geschrieben || !@chmod($datei.'.tmp', 0600) || !@rename($datei.'.tmp', $datei)) {
        @unlink($datei.'.tmp');
        return ['ok' => false, 'fehler' => 'SIGNING_NOT_WRITABLE', 'public_key' => ''];
    }

    return ['ok' => true, 'fehler' => '', 'public_key' => portalSigningPublicKey()];
}

/**
 * Warum dieser Prozess nicht signieren kann — für die Meldung im Lauf
 *
 * Nennt den Benutzer des Prozesses und den Eigentümer der Datei, soweit PHP
 * sie kennt (posix): der häufigste Grund ist ein Cron unter einem anderen
 * Benutzer als der Webserver, der den Schlüssel erzeugt hat.
 *
 * @return string
 */
function portalSigningUnreadableText(): string {
    $datei = portalSigningKeyFile();
    $name = fn(int $uid) => function_exists('posix_getpwuid') ? ((posix_getpwuid($uid) ?: [])['name'] ?? (string)$uid) : (string)$uid;
    $ich = function_exists('posix_geteuid') ? $name(posix_geteuid()) : '?';

    if (!function_exists('sodium_crypto_sign_detached')) {
        $grund = 'PHP dieses Laufs hat keine Sodium-Erweiterung';
    } elseif (!is_file($datei)) {
        $grund = 'es gibt keinen Signaturschlüssel ('.$datei.')';
    } elseif (!is_readable($datei)) {
        $eigner = false !== ($uid = @fileowner($datei)) ? $name($uid) : '?';
        $grund = 'der Lauf (Benutzer '.$ich.') darf '.$datei.' nicht lesen — die Datei gehört '.$eigner
               .'; Cron und Webserver unter demselben Benutzer laufen lassen oder die Datei für beide lesbar machen';
    } elseif ('' === portalSigningSecretKey()) {
        $grund = $datei.' enthält keinen gültigen Schlüssel';
    } else {
        // Hier und jetzt lesbar — dann lag es an einem anderen Lauf
        $grund = 'der Schlüssel war für diesen Lauf nicht lesbar; Benutzer '.$ich;
    }

    return 'Warnung: HugoCMS nimmt Weiterleiter und 404-Seite signiert an, dieser Lauf kann aber nicht signieren ('
         .$grund.'). Die beiden Dateien bleiben auf der Webseite, werden aber nicht aktualisiert.';
}
