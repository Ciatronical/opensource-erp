<?php
// api/setup/index.php

/**
 * Setup-Endpunkt für OpensourceERP
 *
 * Behandelt Setup-Anfragen im einheitlichen Backend-Pattern.
 *
 * Besonderheit: Solange die Installation unvollständig ist (keine settings.ini, keine
 * Auth-Tabellen, kein Benutzer oder keine Firma), sind die Setup-Aktionen ohne Anmeldung
 * erreichbar — anders käme niemand jemals zum ersten Login. Sobald die Installation
 * steht, verschwinden sie hinter dem normalen Auth-Gate, damit niemand von aussen
 * Datenbank-Zugangsdaten durchprobieren kann.
 */

// error.php und config.php werden hier vorgezogen, weil die Entscheidung über die
// öffentlichen Aktionen den Datenbankzugang aus der settings.ini braucht. inc.php
// bindet beide später per require_once erneut ein — ohne Wirkung.
require_once __DIR__.'/../error.php';
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../password.php';
require_once __DIR__.'/../database.php';
require_once __DIR__.'/../lib/tenant.php';
require_once __DIR__.'/setup.php';

if (!defined('OSERP_PUBLIC_ACTIONS')) {
    $setupState = tenantInstallationState();
    $unfertig = in_array($setupState['stage'], ['fresh', 'no_database', 'no_schema', 'no_user', 'no_client'], true);
    if (!$unfertig) {
        // Fertige Installation (oder unerreichbare Datenbank): nichts ist offen.
        $setupActions = '';
    } elseif ($setupState['users'] > 0) {
        // Es gibt schon Benutzer (etwa: Benutzer ja, Firma nein). Dann bleibt nur die
        // Zustandsabfrage offen sowie install — das prüft selbst die Zugangsdaten eines
        // Administrators. Verbindungstests bleiben zu, damit der Server nicht zum
        // Durchprobieren von Datenbank-Passwörtern taugt.
        $setupActions = ',status,install';
    } else {
        // Noch kein einziger Benutzer: hier gibt es nichts zu schützen, und ohne die
        // Serverabfrage käme niemand durch die Einrichtung.
        $setupActions = ',status,getDefaults,test,probe,install';
    }
    define('OSERP_PUBLIC_ACTIONS', 'login,restoreSession,getClients,logout,resetDemo' . $setupActions);
}

require_once __DIR__.'/../inc.php'; // muss immer unten stehen
