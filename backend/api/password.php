<?php
// api/password.php

// ============================================================================
// PASSWORD HASHING FUNCTIONS (kivitendo-kompatibel)
// ============================================================================
// Diese Funktionen replizieren das Password-Hashing aus kivitendo (Perl)
// WICHTIG: Der Original Perl-Code hat einen Bug (vertauschte PBKDF2-Parameter)
// Diese Implementierung repliziert diesen Bug exakt für Kompatibilität!
// ============================================================================

/**
 * Hash password using PBKDF2 - exact port from Perl PBKDF2::Tiny
 *
 * WICHTIG: Der Original Perl-Code hat einen Bug - die Parameter für PBKDF2::Tiny::derive()
 * sind vertauscht! Der Salt wird als Password übergeben und das Password als Salt.
 * Diese Funktion repliziert exakt dieses Verhalten für Kompatibilität.
 *
 * @param string $password Das zu hashende Passwort
 * @param string $login Der Benutzer-Login
 * @param string|null $stored_password Vorhandener Hash (für Verifikation)
 * @return string Gehashtes Passwort im Format {PBKDF2}salt-in-hex:hash-in-hex
 */
function create_pbkdf2_hash(string $password, string $login, ?string $stored_password = null): string {
    $salt = '';

    if ($stored_password !== null && preg_match('/^\{PBKDF2\}([0-9a-f]+):/i', $stored_password, $matches)) {
        // KRITISCH: In Perl ist $salt der HEX STRING (34 chars), nicht binary (17 bytes)!
        $salt = $matches[1];
    } else {
        // Salt aus Login + Random Bytes generieren
        $login_utf8 = mb_convert_encoding($login, 'UTF-8', 'UTF-8');
        $login_bytes = [];
        for ($i = 0; $i < strlen($login_utf8); $i++) {
            $login_bytes[] = ord($login_utf8[$i]);
        }

        $random_bytes = [];
        for ($i = 0; $i < 17; $i++) {
            $random_bytes[] = random_int(0, 255);
        }

        $all_bytes = array_merge($login_bytes, $random_bytes);
        $salt = '';
        foreach ($all_bytes as $byte) {
            $salt .= sprintf('%02x', $byte);
        }
    }

    // KRITISCHER BUG IM PERL CODE: Parameter sind VERTAUSCHT!
    // Perl Code macht: derive('SHA-256', $salt, $password)
    // Aber Dokumentation sagt: derive($type, $password, $salt, $iterations)
    // Wir müssen diesen Bug für Kompatibilität replizieren!

    $password_utf8 = mb_convert_encoding($password, 'UTF-8', 'UTF-8');

    // PBKDF2 mit VERTAUSCHTEN Parametern um Perl Bug zu matchen
    // PHP: hash_pbkdf2(algo, password, salt, iterations, length, binary)
    // Wir übergeben: salt_hex_string als "password", password als "salt"
    $iterations = 1000;
    $key_length = 32;

    $derived = hash_pbkdf2('sha256', $salt, $password_utf8, $iterations, $key_length, true);

    $hash_hex = bin2hex($derived);

    return "{PBKDF2}{$salt}:{$hash_hex}";
}

/**
 * Extrahiert Algorithmus und Hash-Teil aus einem gespeicherten Hash
 * Format: {ALGORITHMUS}hash-daten
 *
 * @param string $password Der gespeicherte Password-Hash
 * @param string $default_algorithm Standard-Algorithmus wenn keiner angegeben
 * @return array [algorithm, hash_part]
 */
function parse_password_hash(string $password, string $default_algorithm = 'PBKDF2'): array {
    if (preg_match('/^\{([^\}]+)\}(.+)$/', $password, $matches)) {
        return [$matches[1], $matches[2]];
    }
    return [$default_algorithm, $password];
}

/**
 * Hasht ein Passwort mit dem angegebenen Algorithmus
 * Unterstützte Algorithmen:
 * - PBKDF2: Sicherer PBKDF2-SHA256 Hash (Standard)
 * - SHA256: Einfacher SHA256 Hash
 * - SHA256S: SHA256 mit Login als Salt
 *
 * @param string $password Das zu hashende Passwort
 * @param string $login Der Benutzer-Login
 * @param string $algorithm Hash-Algorithmus
 * @param string|null $stored_password Vorhandener Hash (für Verifikation)
 * @return string Gehashtes Passwort
 * @throws Exception bei nicht unterstütztem Algorithmus
 */
function hash_password(string $password, string $login, string $algorithm = 'PBKDF2', ?string $stored_password = null): string {
    $salt = '';
    if (preg_match('/S$/', $algorithm)) {
        $salt = $login;
    }

    if (strpos($algorithm, 'SHA256') === 0) {
        return '{' . $algorithm . '}' . hash('sha256', $salt . $password);
    } elseif (strpos($algorithm, 'PBKDF2') === 0) {
        return create_pbkdf2_hash($password, $login, $stored_password);
    } else {
        throw new Exception('Unsupported hash algorithm: ' . $algorithm);
    }
}

/**
 * Verifiziert ein Klartext-Passwort gegen einen gespeicherten Hash
 * Unterstützt alle Algorithmen (PBKDF2, SHA256, SHA256S)
 *
 * @param string $login Benutzer-Login
 * @param string $password Zu verifizierendes Passwort (Klartext)
 * @param string $stored_hash Gespeicherter Password-Hash (aus Datenbank)
 * @return bool True wenn Passwort korrekt ist
 */
function verify_password(string $login, string $password, string $stored_hash): bool {
    list($algorithm, $hash_part) = parse_password_hash($stored_hash);
    $hashed_password = hash_password($password, $login, $algorithm, $stored_hash);
    return $hashed_password === $stored_hash;
}

/**
 * Generiert einen neuen Password-Hash für einen Benutzer
 *
 * Diese Funktion erstellt einen sicheren PBKDF2-Hash aus einem Klartext-Passwort.
 * Der Hash kann direkt in der Datenbank gespeichert werden (in auth.user.password).
 *
 * WICHTIG: Der generierte Hash ist kompatibel mit dem Original kivitendo Perl-Code,
 * inklusive des Bugs mit den vertauschten Parametern!
 *
 * Format des generierten Hashes:
 *   {PBKDF2}[salt-in-hex]:[hash-in-hex]
 *
 * Beispiel:
 *   {PBKDF2}6469726b313647ede14a2e18a255003319c56804c3:a1972bc0632a0d5e38a24954e4b5b3bd1883b526ad582138b3d2d4fd850d1d22
 *
 * Der Salt wird wie folgt generiert:
 *   1. Login wird in UTF-8 Bytes konvertiert
 *   2. 17 zufällige Bytes werden generiert
 *   3. Beide werden konkateniert und als Hex-String gespeichert
 *
 * PBKDF2-Parameter:
 *   - Algorithmus: SHA-256 (HMAC-SHA-256)
 *   - Iterationen: 1000
 *   - Output-Länge: 32 Bytes (256 Bits)
 *   - ACHTUNG: Parameter sind vertauscht! (Salt als Password, Password als Salt)
 *
 * @param string $login Benutzer-Login (wird Teil des Salts)
 * @param string $password Klartext-Passwort (wird NICHT gespeichert)
 * @param string $algorithm Hash-Algorithmus (Standard: 'PBKDF2')
 * @return string Der fertige Hash im Format {PBKDF2}salt:hash
 * @throws Exception wenn ein nicht unterstützter Algorithmus angegeben wird
 */
function generate_password_hash(string $login, string $password, string $algorithm = 'PBKDF2'): string {
    return hash_password($password, $login, $algorithm, null);
}
