<?php
/**
 * PSR-4 Autoloader fuer phpFinTS und Abhaengigkeiten
 *
 * Laedt:
 * - Fhp\  → lib/Fhp/
 * - Psr\Log\ → psr/log/
 */

spl_autoload_register(static function ($class) {
    $map = [
        'Fhp\\'     => __DIR__ . '/lib/Fhp/',
        'Psr\\Log\\' => __DIR__ . '/psr/log/',
    ];

    foreach ($map as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, $len)) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }
});
