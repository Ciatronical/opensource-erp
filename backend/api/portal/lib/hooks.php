<?php
// backend/api/portal/lib/hooks.php
//
// Haken des Kundenportals. Der Kern kennt die Erweiterungen nicht; an wenigen
// Stellen fragt er stattdessen, ob jemand etwas beizutragen hat:
//
//   login              ($db, $uuid, $customerId)        nach der Anmeldung — der Shop führt Warenkörbe zusammen
//   context_status     ($status, $db, $uuid, $website)  Status der Sitzung — der Shop hängt den Warenkorbzähler an
//   customer_defaults  ($werte, $db)                    Steuerzone und Währung neuer Kunden
//   invoice_list       ($orders, $db, $customerId)      Rechnungsliste — Zahlstand, Lieferstatus
//   invoice            ($rechnung, $db, $arId)          Kopf einer Rechnung
//   invoice_positions  ($positionen, $db, $arId, $website)  Positionen — Produktlinks, Vorschaubilder
//   publish_files      ($dateien, $db, $website, $satz) Dateien des Pakets einer Webseite
//   publish_media      ($medien, $db, $website)         Medien, die zur Webseite gehören
//
// Eine Erweiterung hängt sich mit portalHookAdd() an, beim Laden ihrer
// Bibliothek (backend/api/shop/lib/portal_hooks.php). Zwei Formen:
//
//   portalHookRun()     ruft alle Haken, sammelt die Rückgaben (login)
//   portalHookFilter()  reicht einen Wert durch alle Haken und gibt ihn
//                       verändert zurück (alle anderen)

/**
 * Das Register, je Request
 *
 * @return array<string, callable[]>
 */
function &portalHooks(): array {
    static $haken = [];
    return $haken;
}

/**
 * Hängt eine Funktion an einen Haken
 *
 * @param string $name Haken, siehe Kopf der Datei
 * @param callable $fn Signatur je nach Haken
 * @return void
 */
function portalHookAdd(string $name, callable $fn): void {
    $haken = &portalHooks();
    $haken[$name][] = $fn;
}

/**
 * Ruft alle Funktionen eines Hakens
 *
 * @param string $name Haken
 * @param mixed ...$args Argumente des Hakens
 * @return array Rückgaben, ohne null
 */
function portalHookRun(string $name, ...$args): array {
    $ergebnisse = [];
    foreach (portalHooks()[$name] ?? [] as $fn) {
        $r = $fn(...$args);
        if (null !== $r) {
            $ergebnisse[] = $r;
        }
    }
    return $ergebnisse;
}

/**
 * Reicht einen Wert durch alle Funktionen eines Hakens
 *
 * Jede Funktion bekommt den Wert als erstes Argument und gibt ihn zurück —
 * verändert oder unverändert. Gibt sie null zurück, bleibt der Wert, wie er
 * war.
 *
 * @param string $name Haken
 * @param mixed $wert Ausgangswert
 * @param mixed ...$args Weitere Argumente
 * @return mixed
 */
function portalHookFilter(string $name, $wert, ...$args) {
    foreach (portalHooks()[$name] ?? [] as $fn) {
        $neu = $fn($wert, ...$args);
        if (null !== $neu) {
            $wert = $neu;
        }
    }
    return $wert;
}
