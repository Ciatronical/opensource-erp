<?php
// backend/api/lib/kontenrahmen.php
//
// Erkennung von Kassenkonten anhand des Kontenrahmens.
//
// Welcher Kontenrahmen gilt, steht in defaults.coa („Germany-DATEV-SKR03EU"
// bzw. „…SKR04EU") — dieselbe Spalte, aus der auch der DATEV-Export seine
// Kontenlänge ableitet. Vorher stand hier eine feste Liste beider Nummern, was
// in jedem Kontenrahmen auch die Nummer des jeweils anderen mitprüfte: 1600 ist
// in SKR03 „Verbindlichkeiten aus Lief.u.Leist.", 1000 in SKR04 „Roh-, Hilfs-
// und Betriebsstoffe". Beide fielen bisher nur zufällig durch die Kategorie-
// und Link-Prüfung.

/**
 * SQL-Bedingung „dieses Konto ist ein Kassenkonto".
 *
 * Gedacht für den WHERE-Teil einer Abfrage über chart. Die Bedingung enthält
 * keine Platzhalter und keine Eingaben von aussen, sie kann also gefahrlos in
 * eine Abfrage eingesetzt werden.
 *
 * Zweiter Zweig: eine Bezeichnung, die „Kasse" enthält. Damit werden Nebenkassen
 * gefunden, die im Standardkontenrahmen keine feste Nummer haben. „Sparkasse"
 * und alles mit „Bank" sind ausgenommen — sonst würde jedes Sparkassenkonto zur
 * Kasse.
 *
 * @param string $alias Tabellen-Alias von chart in der aufrufenden Abfrage
 * @return string SQL-Bedingung, in Klammern gefasst
 */
function kassenkontoBedingung($alias) {
    return "(
        {$alias}.accno IN (
            SELECT unnest(nummern) FROM (
                SELECT CASE
                           WHEN d.coa LIKE '%SKR04%' THEN ARRAY['1600']
                           WHEN d.coa LIKE '%SKR03%' THEN ARRAY['1000']
                           -- Unbekannter oder leerer Kontenrahmen: beide prüfen.
                           -- Das ist das Verhalten von früher und damit der
                           -- Rückfall, der niemandem die Kasse wegnimmt.
                           ELSE ARRAY['1000', '1600']
                       END AS nummern
                FROM defaults d LIMIT 1
            ) r
        )
        OR ({$alias}.description ILIKE '%kasse%'
            AND {$alias}.description NOT ILIKE '%sparkasse%'
            AND {$alias}.description NOT ILIKE '%bank%')
    )";
}
