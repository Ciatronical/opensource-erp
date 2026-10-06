<?php
// backend/api/lib/secrets.php
//
// Verschluesselte Ablage von Zugangsdaten (Postfach-Passwoerter, Portal-Logins
// der Belegsuche). Gleiches Verfahren wie die FinTS-PIN (banking/fints.php):
// AES-256-GCM, Schluessel aus dem Auth-DB-Passwort abgeleitet — ein Dump der
// Firmen-DB allein gibt die Passwoerter nicht preis.

function secretKey(): string {
    return hash('sha256', DB_AUTH_PASS . ':oserp_secret_v1', true);
}

function secretEncrypt(string $plain): string {
    if ($plain === '') return '';
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', secretKey(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return base64_encode($iv . $tag . $ct);
}

function secretDecrypt(?string $encrypted): string {
    if ($encrypted === null || $encrypted === '') return '';
    $raw = base64_decode($encrypted);
    if ($raw === false || strlen($raw) < 29) return '';
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', secretKey(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}
