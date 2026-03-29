<?php
// tools/encrypt_password.php

/**
 * Hilfsskript zum Verschlüsseln von Passwörtern für settings.ini
 * 
 * Verwendung:
 *   php encrypt_password.php "mein_passwort"
 */

function enc(string $s): string {
    $key = 'k';
    return base64_encode($s ^ str_repeat($key, strlen($s)));
}

function dec(string $s): string {
    $key = 'k';
    return base64_decode($s) ^ str_repeat($key, strlen(base64_decode($s)));
}

// Passwort aus Kommandozeile oder interaktiv
if (isset($argv[1])) {
    $password = $argv[1];
} else {
    echo "Passwort eingeben: ";
    $password = trim(fgets(STDIN));
}

$encrypted = enc($password);

echo "\n";
echo "Klartext:      $password\n";
echo "Verschlüsselt: $encrypted\n";
echo "\n";
echo "Test Entschlüsselung: ".dec($encrypted)."\n";
echo "\n";
echo "Trage diesen Wert in settings.ini ein:\n";
echo "auth_pass = \"$encrypted\"\n";
echo "\n";