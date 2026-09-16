<?php
// api.call.php
$data = json_decode(file_get_contents('php://input'), true);
if(null != $data) $_POST = array_merge($_POST, $data);
else $data = array_merge($_POST, $_GET);

if(isset($_POST['content-type']) && $_POST['content-type'] == 'application/pdf') {
    header('Content-Type: application/pdf');
}
else {
    header('Content-Type: application/json');
}

// ============================================================================
// ZENTRALER AUTH-GATE
// ============================================================================
// Der Dispatcher rief bisher jede Funktion auf, deren Name in `action` stand,
// solange function_exists() sie kannte — also auch eingebaute PHP-Funktionen
// und jede geladene Hilfsfunktion. Ausserdem gab es keine Pruefung, ob der
// Aufrufer ueberhaupt angemeldet ist; das entstand nur als Nebeneffekt daraus,
// dass die Firmen-DB eine Sitzung braucht.
//
// Jetzt gilt:
//   1. Nur benutzerdefinierte Funktionen (kein eingebautes PHP) sind aufrufbar.
//   2. Jede Aktion ausserhalb der Public-Liste verlangt eine gueltige Sitzung.
//
// Die oeffentlichen Zugaenge ohne Mitarbeiter-Sitzung (Shop-Webseite,
// Webhooks) binden inc.php bewusst NICHT ein und laufen deshalb nicht durch
// diesen Dispatcher — sie haben ihre eigene Aktions-Whitelist.

if (!defined('OSERP_PUBLIC_ACTIONS')) {
    // Aktionen, die vor der Anmeldung erreichbar sein muessen:
    //   login          - Anmeldung selbst
    //   restoreSession - prueft den eigenen Cookie, liefert ggf. INVALID_SESSION
    //   getClients     - Mandanten-Dropdown der Anmeldemaske
    //   logout         - darf auch ohne gueltige Sitzung aufgerufen werden
    //   resetDemo      - Selbstheilung der Demo (intern durch DEMO_MODE geschuetzt)
    define('OSERP_PUBLIC_ACTIONS', 'login,restoreSession,getClients,logout,resetDemo');
}

/**
 * Prueft, ob $name eine im Projektcode definierte API-Funktion ist.
 *
 * Bewusst NICHT function_exists(): das traefe auch eingebaute PHP-Funktionen
 * (system, phpinfo, exec, ...), die ueber `action` niemals aufrufbar sein
 * duerfen. Zugelassen sind ausschliesslich benutzerdefinierte Funktionen, und
 * die sind erst geladen, wenn der jeweilige Modul-Einstiegspunkt (index.php)
 * seine Dateien vor inc.php eingebunden hat.
 *
 * @param mixed $name Aktionsname aus dem Request
 * @return bool
 */
function isApiAction($name): bool {
    static $userFns = null;
    if ($userFns === null) {
        $defined = get_defined_functions();
        $userFns = array_flip(array_map('strtolower', $defined['user']));
    }
    return is_string($name) && $name !== '' && isset($userFns[strtolower($name)]);
}

if(isset($data['action'])) {
    $action = $data['action'];

    if (!isApiAction($action)) {
        resultInfo(false, 'API_ACTION_NOT_ALLOWED', 'Aktion nicht erlaubt');
        return;
    }

    try {
        // Auth-Gate: jede Aktion ausserhalb der Public-Liste verlangt eine
        // gueltige Sitzung. Im Setup-Modus (noch keine settings.ini) gibt es
        // keine Datenbank und keine Sessions — dann greift der Gate nicht.
        $publicActions = array_map('trim', explode(',', OSERP_PUBLIC_ACTIONS));
        $setupMode = defined('OSERP_SETUP_MODE') && OSERP_SETUP_MODE;
        if (!$setupMode && !in_array($action, $publicActions, true)) {
            DbhAuth::begin()->fetchSessionData();
        }

        $action($data);
    }
    catch (PDOException $e) {
        header('Content-Type: application/json');
        resultInfo(false, "API_DATABASE_ERROR", dbFriendlyError($e));
    }
    catch (ApiError $e) {
        header('Content-Type: application/json');
        resultInfo(false, $e->getId(), $e->getMessage());
        if(!is_null($e->getQuery())) writeLog($e->getQuery());
    }
    catch (\Throwable $e) {
        header('Content-Type: application/json');
        resultInfo(false, 'API_INTERNAL_ERROR: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
else {
    resultInfo(false, 'API_ACTION_NOT_SPECIFIED', 'No action specified');
}
