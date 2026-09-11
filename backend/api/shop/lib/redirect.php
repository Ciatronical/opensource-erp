<?php
// backend/api/shop/lib/redirect.php
//
// Umleitungen für entfallene Seiten der Shop-Webseite (redirect_pages_hugoshop).
// Die 404-Seite der Webseite fragt sie über die öffentliche Aktion
// resolveRedirect ab.

/**
 * Sucht die Umleitung zu einer angefragten Adresse
 *
 * Wie in der Bridge: die gespeicherte alte Adresse beginnt mit der angefragten
 * (ILIKE 'angefragt%'), die kürzeste gewinnt. Anders als dort ohne Schema —
 * hinter einem Proxy weiß die Webseite oft nicht, ob sie per https angesprochen
 * wurde — und gebunden statt in den SQL-Text gesetzt: die Bridge baute die
 * angefragte Adresse ungeprüft in die Abfrage ein.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $adresse Host und Pfad, mit oder ohne Schema
 * @return array|null code, current_link, link_text
 */
function shopResolveRedirect($db, string $adresse): ?array {
    $adresse = rtrim((string)preg_replace('#^https?://#i', '', trim($adresse)), '/');
    if ('' === $adresse) {
        return null;
    }

    // Platzhalter von LIKE in der Adresse wörtlich nehmen
    $muster = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $adresse).'%';

    $treffer = $db->getOne(
        "SELECT code, current_link, link_text
           FROM redirect_pages_hugoshop
          WHERE regexp_replace(previous_link, '^https?://', '', 'i') ILIKE :muster
          ORDER BY length(previous_link)
          LIMIT 1",
        [':muster' => $muster]
    );

    return $treffer ?: null;
}
