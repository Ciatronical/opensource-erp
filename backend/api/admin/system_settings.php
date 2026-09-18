<?php
// backend/api/admin/system_settings.php
//
// Systemeinstellungen: die settings.ini über die Oberfläche bearbeiten — nur
// für Systemadministratoren.
//
// Heikel, weil die Datei den Zugang zur Datenbank enthält. Ein falscher Wert
// sperrt alle aus, und über dieselbe Oberfläche ließe er sich nicht mehr
// zurücknehmen. Deshalb:
//
//   - Ein geänderter Datenbankzugang wird vor dem Speichern ausprobiert.
//   - Wer sich über admin_users selbst die Administration entziehen würde,
//     wird abgewiesen.
//   - Werte mit Steuerzeichen, Anführungszeichen, Backslash oder "${" werden
//     abgewiesen. Ein Zeilenumbruch schriebe neue Abschnitte in die Datei, und
//     "${...}" ersetzt PHP beim Lesen durch Umgebungsvariablen.
//   - Das Passwort geht nie an den Client; ein leeres Feld behält es.
//   - Vor jedem Schreiben legt OserpConfig::saveSettings() eine Sicherungskopie
//     an.
//
// Fehler tragen einen Code und als Meldung nur die Einzelheiten (Pfad,
// Fehlertext der Datenbank, betroffene Felder); den Satz davor übersetzt die
// Oberfläche.
//
// Einträge, die das Formular nicht kennt, bleiben erhalten. Kommentare in der
// Datei gehen beim Schreiben verloren; die Sicherungskopie enthält sie.

/**
 * Die bearbeitbaren Einträge
 *
 * Abschnitt -> Schlüssel -> Art, dazu die Konstante aus config.php, deren Wert
 * gerade gilt. Die Oberfläche baut ihr Formular daraus; eine eigene Liste
 * führt sie nicht.
 *
 * Der Datenbankzugang ist vollständig Pflicht: ohne Eintrag fiele er auf
 * Vorgaben zurück, die auf keinem echten Server stimmen.
 *
 * @return array
 */
function systemSettingsSchema(): array {
    $bezeichner = '/^[A-Za-z0-9._-]+$/';

    return [
        'database' => [
            'host'      => ['type' => 'text', 'const' => 'DB_HOST', 'required' => true, 'pattern' => $bezeichner],
            'port'      => ['type' => 'int', 'const' => 'DB_PORT', 'required' => true, 'min' => 1, 'max' => 65535],
            'auth_db'   => ['type' => 'text', 'const' => 'DB_AUTH_NAME', 'required' => true, 'pattern' => $bezeichner],
            'auth_user' => ['type' => 'text', 'const' => 'DB_AUTH_USER', 'required' => true, 'pattern' => $bezeichner],
            'auth_pass' => ['type' => 'secret'],
        ],
        'session' => [
            'cookie_name'      => ['type' => 'text', 'const' => 'SESSION_COOKIE', 'pattern' => '/^[A-Za-z0-9_]+$/'],
            'cookie_same_site' => ['type' => 'select', 'const' => 'COOKIE_SAME_SITE', 'options' => ['Strict', 'Lax', 'None']],
        ],
        'logging' => [
            'debug_log_file' => ['type' => 'path', 'const' => 'OSERP_DEBUG_LOG_FILE', 'select' => 'file'],
            'api_log_file'   => ['type' => 'path', 'const' => 'OSERP_API_LOG_FILE', 'select' => 'file'],
            'max_log_size'   => ['type' => 'int', 'const' => 'OSERP_DEBUG_LOG_MAX_SIZE', 'min' => 0],
        ],
        'system' => [
            'timezone'                  => ['type' => 'timezone', 'const' => 'OSERP_DEFAULT_TIMEZONE'],
            'debug'                     => ['type' => 'bool'],
            'templates_dir'             => ['type' => 'text', 'const' => 'OSERP_TEMPLATES_DIR_NAME'],
            'backup_dir'                => ['type' => 'path', 'const' => 'BACKUP_BASE_DIR'],
            'shop_sites_dir'            => ['type' => 'path', 'const' => 'OSERP_SHOP_SITES_DIR'],
            'shop_publish_command_path' => ['type' => 'path', 'const' => 'OSERP_SHOP_PUBLISH_COMMAND_PATH', 'select' => 'file'],
            'browse_roots'              => ['type' => 'text', 'const' => 'OSERP_BROWSE_ROOTS'],
        ],
        'telephony' => [
            'monitor_dir' => ['type' => 'path', 'const' => 'TELEPHONY_MONITOR_DIR'],
        ],
        'demo' => [
            'enabled'            => ['type' => 'bool', 'const' => 'DEMO_MODE'],
            'company_db'         => ['type' => 'text', 'const' => 'DEMO_COMPANY_DB'],
            'inactivity_minutes' => ['type' => 'int', 'const' => 'DEMO_INACTIVITY_MINUTES', 'min' => 1],
        ],
        'company' => [
            'admin_users' => ['type' => 'text', 'const' => 'COMPANY_ADMIN_USERS'],
        ],
    ];
}

/**
 * Der Wert, der für einen Eintrag gerade gilt
 *
 * @param string $abschnitt Abschnitt
 * @param string $schluessel Schlüssel
 * @param array $art Eintrag aus systemSettingsSchema()
 * @return mixed
 */
function systemSettingsEffective(string $abschnitt, string $schluessel, array $art) {
    if ('system' === $abschnitt && 'debug' === $schluessel) {
        return (bool)($GLOBALS['DEBUG'] ?? false);
    }
    return isset($art['const']) && defined($art['const']) ? constant($art['const']) : null;
}

/**
 * Prüft einen eingegebenen Wert und wandelt ihn in den Typ der Datei
 *
 * @param string $name "abschnitt.schluessel" für die Meldung
 * @param array $art Eintrag aus systemSettingsSchema()
 * @param mixed $wert Eingabe
 * @return array [Wert oder null für "Eintrag entfernen", Fehlertext oder '']
 */
function systemSettingsValue(string $name, array $art, $wert): array {
    if ('bool' === $art['type']) {
        return [in_array($wert, [true, 1, '1', 'true', 't'], true), ''];
    }

    $text = trim((string)($wert ?? ''));
    if ('' === $text) {
        return !empty($art['required']) ? [null, "$name ist ein Pflichtfeld"] : [null, ''];
    }
    if (1 === preg_match('/[\x00-\x1F\x7F"\\\\]/', $text) || str_contains($text, '${')) {
        return [null, "$name: Steuerzeichen, Anführungszeichen, Backslash und \${ sind nicht erlaubt"];
    }

    switch ($art['type']) {
        case 'int':
            if (1 !== preg_match('/^\d+$/', $text)) {
                return [null, "$name: nur eine ganze Zahl"];
            }
            $zahl = (int)$text;
            if ((isset($art['min']) && $zahl < $art['min']) || (isset($art['max']) && $zahl > $art['max'])) {
                return [null, "$name: außerhalb des erlaubten Bereichs"];
            }
            return [$zahl, ''];

        case 'path':
            return '/' === $text[0] ? [$text, ''] : [null, "$name: nur ein absoluter Pfad"];

        case 'select':
            return in_array($text, $art['options'], true) ? [$text, ''] : [null, "$name: unbekannter Wert"];

        case 'timezone':
            return in_array($text, DateTimeZone::listIdentifiers(), true) ? [$text, ''] : [null, "$name: unbekannte Zeitzone"];

        default:
            if (isset($art['pattern']) && 1 !== preg_match($art['pattern'], $text)) {
                return [null, "$name: ungültige Zeichen"];
            }
            return [$text, ''];
    }
}

/**
 * Systemeinstellungen zum Bearbeiten
 *
 * Je Eintrag der Wert aus der Datei (null, wenn er dort fehlt) und der Wert,
 * der gerade gilt. Vom Passwort kommt nur, ob eines hinterlegt ist. Einträge,
 * die das Formular nicht kennt, kommen nur mit Namen — sie könnten Geheimnisse
 * anderer Teile enthalten.
 *
 * @return void
 * @testdata {}
 */
function getSystemSettings($data) {
    requireSystemAdmin();

    $datei = SETUP_SETTINGS_DIR.SETUP_SETTINGS_INI_FILE;
    $aktuell = OserpConfig::getCurrentSettings();
    $schema = systemSettingsSchema();

    $abschnitte = [];
    foreach ($schema as $abschnitt => $felder) {
        $eintraege = [];
        foreach ($felder as $schluessel => $art) {
            $vorhanden = is_array($aktuell[$abschnitt] ?? null) && array_key_exists($schluessel, $aktuell[$abschnitt]);
            $eintrag = ['key' => $schluessel, 'type' => $art['type'], 'present' => $vorhanden];
            foreach (['min', 'max', 'options', 'required', 'select'] as $zusatz) {
                if (isset($art[$zusatz])) {
                    $eintrag[$zusatz] = $art[$zusatz];
                }
            }
            if ('secret' === $art['type']) {
                $eintrag['set'] = $vorhanden && '' !== (string)$aktuell[$abschnitt][$schluessel];
            } else {
                $eintrag['value'] = $vorhanden ? $aktuell[$abschnitt][$schluessel] : null;
                $eintrag['effective'] = systemSettingsEffective($abschnitt, $schluessel, $art);
            }
            $eintraege[] = $eintrag;
        }
        $abschnitte[] = ['name' => $abschnitt, 'fields' => $eintraege];
    }

    $weitere = [];
    foreach ($aktuell as $abschnitt => $werte) {
        foreach ((array)$werte as $schluessel => $wert) {
            if (!isset($schema[$abschnitt][$schluessel])) {
                $weitere[] = ['section' => (string)$abschnitt, 'key' => (string)$schluessel];
            }
        }
    }

    resultInfo(true, '', [
        'file'      => realpath($datei) ?: $datei,
        'writable'  => is_writable($datei),
        'sections'  => $abschnitte,
        'extra'     => $weitere,
        'timezones' => DateTimeZone::listIdentifiers(),
    ]);
}

/**
 * Speichert die Systemeinstellungen
 *
 * Nur mitgeschickte, bekannte Einträge zählen; ein leerer Wert entfernt den
 * Eintrag, dann gilt die Vorgabe aus config.php. Beim Passwort heißt leer:
 * behalten.
 *
 * @param array $data['values'] Abschnitt -> Schlüssel -> Wert
 * @return void
 * @testdata {"values": {"system": {"debug": false}}}
 */
function saveSystemSettings($data) {
    $auth = requireSystemAdmin();

    $datei = SETUP_SETTINGS_DIR.SETUP_SETTINGS_INI_FILE;
    if (!is_writable($datei)) {
        throw new ApiError('SETTINGS_NOT_WRITABLE', $datei);
    }

    $aktuell = OserpConfig::getCurrentSettings();
    $neu = $aktuell;
    $eingaben = is_array($data['values'] ?? null) ? $data['values'] : [];
    $alsText = fn($wert) => is_bool($wert) ? ($wert ? '1' : '0') : (string)$wert;

    $fehler = [];
    $geaendert = [];
    foreach (systemSettingsSchema() as $abschnitt => $felder) {
        foreach ($felder as $schluessel => $art) {
            if (!is_array($eingaben[$abschnitt] ?? null) || !array_key_exists($schluessel, $eingaben[$abschnitt])) {
                continue;
            }
            $name = "$abschnitt.$schluessel";
            $roh = $eingaben[$abschnitt][$schluessel];
            $alt = is_array($aktuell[$abschnitt] ?? null) ? ($aktuell[$abschnitt][$schluessel] ?? null) : null;

            if ('secret' === $art['type']) {
                if ('' === (string)($roh ?? '')) {
                    continue;
                }
                $wert = (string)$roh;
                if (1 === preg_match('/[\x00-\x1F\x7F]/', $wert)) {
                    $fehler[] = "$name: Steuerzeichen sind nicht erlaubt";
                    continue;
                }
            } else {
                [$wert, $meldung] = systemSettingsValue($name, $art, $roh);
                if ('' !== $meldung) {
                    $fehler[] = $meldung;
                    continue;
                }
            }

            $gleich = (null === $wert && null === $alt)
                || (null !== $wert && null !== $alt && $alsText($wert) === $alsText($alt));
            if ($gleich) {
                continue;
            }

            if (null === $wert) {
                unset($neu[$abschnitt][$schluessel]);
            } else {
                $neu[$abschnitt][$schluessel] = $wert;
            }
            $geaendert[] = $name;
        }
    }

    if ($fehler) {
        throw new ApiError('INVALID_VALUE', implode('; ', $fehler));
    }
    if (!$geaendert) {
        resultInfo(true, '', ['changed' => [], 'reload_fpm' => false]);
        return;
    }

    // Leer gewordene Abschnitte nicht als [abschnitt] ohne Inhalt schreiben
    $neu = array_filter($neu, fn($werte) => !is_array($werte) || [] !== $werte);

    // Ein neuer Datenbankzugang muss funktionieren, bevor er in der Datei steht
    $datenbankGeaendert = [] !== array_filter($geaendert, fn($n) => str_starts_with($n, 'database.'));
    if ($datenbankGeaendert) {
        $zugang = $neu['database'] ?? [];
        try {
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s', $zugang['host'] ?? '', $zugang['port'] ?? '', $zugang['auth_db'] ?? ''),
                (string)($zugang['auth_user'] ?? ''),
                (string)($zugang['auth_pass'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            // Eine Auth-Datenbank erkennt man an ihrer Mandantenliste
            $pdo->query('SELECT 1 FROM auth.clients LIMIT 1');
        } catch (PDOException $e) {
            throw new ApiError('DB_CONNECTION_FAILED', $e->getMessage());
        }
    }

    // Niemand soll sich über admin_users selbst aussperren
    if (in_array('company.admin_users', $geaendert, true)) {
        $liste = array_values(array_filter(array_map('trim', explode(',', (string)($neu['company']['admin_users'] ?? '')))));
        if (!tenantAdminStatus($auth, (int)$auth->getUserId(), $auth->getLogin(), $liste)['is_admin']) {
            throw new ApiError('WOULD_LOCK_OUT', 'Mit dieser Liste wären Sie kein Systemadministrator mehr — nichts gespeichert');
        }
    }

    try {
        OserpConfig::saveSettings($neu);
    } catch (Exception $e) {
        throw new ApiError('SETTINGS_NOT_WRITABLE', $e->getMessage());
    }
    writeLog('[SYSTEM] settings.ini geändert von '.$auth->getLogin().': '.implode(', ', $geaendert), true, DLOG_WRN);

    resultInfo(true, '', ['changed' => $geaendert, 'reload_fpm' => $datenbankGeaendert]);
}
