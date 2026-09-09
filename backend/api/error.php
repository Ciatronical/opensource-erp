<?php
// backend/api/error.php
//
// Antwortformat und Fehlerklasse der API. Herausgeloest aus inc.php, damit
// Einstiegspunkte ohne Mitarbeiter-Sitzung sie einbinden koennen, ohne
// zugleich auth.php zu bekommen: ueber den action-Mechanismus waeren dort
// login, logout, getClients, restoreSession und switchClient erreichbar.
//
// inc.php laedt diese Datei; fuer die uebrigen Module aendert sich nichts.

/**
 * Gibt eine JSON-kodierte Antwort zurück
 *
 * @param bool $success Erfolg der Operation
 * @param string $text Textnachricht (optional)
 * @param mixed $data Daten (optional) - wird als "payload" zurückgegeben
 * @param string $debug Debug-Informationen (optional)
 * @param bool $error_to_warning Fehler als Warnung behandeln (optional)
 */
function resultInfo($success, $text = '', $data = null, $debug = '', $error_to_warning = false) {
    $info = array("success" => $success);
    if ($error_to_warning && !$success) $info["warning"] = true;
    if (!empty($text)) $info["text"] = $text;
    if ($data !== null) $info["payload"] = $data;
    if (!empty($debug)) $info["debug"] = $debug;
    echo json_encode($info);
}

/**
 * Benutzerdefinierte Ausnahme für API-Fehler
 */
class ApiError extends Exception {
    protected $id = '';
    protected $query = '';

    /**
     * Konstruktor
     *
     * @param string $id Fehler-ID für API-Fehlercodes (z.B. DATA_NOT_FOUND, API_DATABASE_ERROR)
     * @param string $message Fehlermeldung
     * @param string|null $query Ausgeführte Abfrage bei Datenbankfehlern (optional)
     * @param int $code Fehlercode (optional)
     * @param Exception|null $previous Vorherige Ausnahme (optional)
     */
    public function __construct($id, $message, $query = null, $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->id = $id;
        $this->query = $query;
    }

    public function getId() {
        return $this->id;
    }

    public function getQuery() {
        return $this->query;
    }
}
