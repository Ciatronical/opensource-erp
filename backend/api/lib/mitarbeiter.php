<?php
// backend/api/lib/mitarbeiter.php
//
// Wer war das? — Ermittlung des handelnden Mitarbeiters für Buchungen und
// Belege.

/**
 * Mitarbeiter-ID des Aufrufers aus den Anfragedaten.
 *
 * Bewusst NICHT aus $_SESSION: die Anmeldung liefert die ID an den
 * Frontend-Store, und der schickt sie bei jeder Anfrage mit (siehe
 * Axios-Interceptor in src/main.js). `$_SESSION['employee_id']` wird im
 * gesamten Projekt an keiner Stelle gesetzt — jede Auswertung darauf ergab
 * NULL, und deshalb stand an keinem abgelegten Beleg, wer ihn abgelegt hat.
 * Für die Nachvollziehbarkeit nach GoBD ist das die entscheidende Angabe.
 *
 * @param array $data Anfragedaten der API-Funktion
 * @return int|null Mitarbeiter-ID oder null, wenn keine mitgeschickt wurde
 */
function mitarbeiterId($data) {
    $id = intval($data['employee_id'] ?? 0);
    if ($id > 0) return $id;

    // Rückfall auf die Session, falls ein Aufruf sie doch einmal setzt.
    $alt = intval($_SESSION['employee_id'] ?? 0);
    return $alt > 0 ? $alt : null;
}
