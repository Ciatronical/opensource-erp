<?php
// backend/api/portal/lib/sitecheck.php
//
// Konfigurationsprüfung der Webseite (2026-10-30, dev/kundenportal.md).
//
// Passt die Hugo-Konfiguration der Webseite zu dem, was das Paket und die
// Erweiterungen erwarten? HugoCMS gibt die Konfiguration, wie Hugo sie
// auflöst (Befehl shopsiteconfig: Mounts, disableKinds, taxonomies,
// permalinks, params.portalui, Hugos Warnungen beim Laden, Übersichtsseiten
// der Taxonomien); bewertet wird hier, weil nur OpensourceERP die
// Portaleinstellungen und die Kanalkarte kennt. Der Kern prüft die Mounts des
// Pakets und reicht Hugos Warnungen durch; Erweiterungen hängen ihre Prüfungen
// an den Haken site_checks (der Shop: Taxonomie und Adressen der Kategorien).
//
// Ein Befund: code (Schlüssel für die Übersetzung in der Oberfläche,
// crm_fields.shopSiteCheck_<code>), art (error, warning, info), params (für
// die Meldung) und fix — der Eintrag für die config.json der Webseite, den
// ein Administrator übernehmen kann. Geschrieben wird dort nichts: die
// Konfiguration gehört der Webseite.

/**
 * Holt die Hugo-Konfiguration der Webseite von HugoCMS (shopsiteconfig)
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param array $zugang url und key aus dem Formular (Verbindungstest), leer = gespeichert
 * @return array ok, data (config, warnings, files, grants, hugo), fehler,
 *               veraltet (true: HugoCMS kennt den Befehl noch nicht)
 */
function portalSiteConfigFetch($db, int $website, array $zugang = []): array {
    $antwort = portalHugoCmsCall($db, $website, 'shopsiteconfig', 'GET', 30, $zugang);
    $antwort['veraltet'] = !$antwort['ok'] && 'UNKNOWN-COMMAND' === ($antwort['key'] ?? '');
    if ($antwort['veraltet']) {
        $antwort['fehler'] = 'Diese HugoCMS-Version kennt die Konfigurationsprüfung noch nicht (Befehl shopsiteconfig, seit 2026-10-30) — bitte HugoCMS aktualisieren';
    }
    return $antwort;
}

/**
 * Ein Befund der Konfigurationsprüfung
 *
 * @param string $code Schlüssel, etwa MOUNT_MISSING
 * @param string $art error, warning oder info
 * @param array $params Werte für die Meldung
 * @param array|string|null $fix Eintrag für die config.json (Array wird als JSON ausgegeben) oder Text
 * @return array code, art, params, fix
 */
function portalSiteFinding(string $code, string $art, array $params = [], $fix = null): array {
    if (is_array($fix)) {
        $fix = json_encode($fix, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return ['code' => $code, 'art' => $art, 'params' => $params, 'fix' => (string)($fix ?? '')];
}

/**
 * Die Mounts der Webseite aus der Antwort von shopsiteconfig, bereinigt
 *
 * @param array $site Antwort von shopsiteconfig (data)
 * @return array Liste aus source, target — ohne Schrägstriche an den Enden
 */
function portalSiteMounts(array $site): array {
    $mounts = [];
    foreach ((array)($site['config']['mounts'] ?? []) as $mount) {
        if (!is_array($mount) || !isset($mount['source'], $mount['target'])) {
            continue;
        }
        $mounts[] = [
            'source' => trim((string)$mount['source'], '/'),
            'target' => trim((string)$mount['target'], '/'),
        ];
    }
    return $mounts;
}

/**
 * Liegt ein Verzeichnis oder eine Datei der Hugo-Quelle in einem Mount mit
 * diesem Ziel (oder einem Unterverzeichnis davon)?
 *
 * Hugo sieht eine Datei nur über einen Mount: content/de/produkt gehört zu
 * einem Mount content → content, aber nicht zu einem Mount data → data.
 *
 * @param array $mounts Liste aus portalSiteMounts
 * @param string $pfad relativ zur Hugo-Quelle, etwa content/de/produkt
 * @param string $ziel Ziel in Hugos Dateisystem, etwa content oder data
 * @return bool
 */
function portalSiteMounted(array $mounts, string $pfad, string $ziel): bool {
    $pfad = trim($pfad, '/');
    foreach ($mounts as $mount) {
        $passtZiel = $mount['target'] === $ziel || str_starts_with($mount['target'], $ziel.'/');
        $passtQuelle = $mount['source'] === $pfad || str_starts_with($pfad, $mount['source'].'/');
        if ($passtZiel && $passtQuelle) {
            return true;
        }
    }
    return false;
}

/**
 * Prüft die Hugo-Konfiguration einer Webseite
 *
 * Kern: die vier Mounts des Pakets (static, layouts, assets/portal-ui,
 * content) und Hugos Warnungen beim Laden der Konfiguration (abgekündigte
 * Schlüssel, die eine künftige Hugo-Version nicht mehr annimmt). Danach die
 * Erweiterungen über den Haken site_checks.
 *
 * Der Inhalts-Mount braucht nur ein Ziel unter content: die Paketseiten
 * tragen ihre Adresse im Vorspann (url), der Verzeichnisname bestimmt sie
 * nicht. Die übrigen drei müssen genau treffen, sonst findet Hugo
 * Weiterleiter, Shortcodes oder Bündel nicht.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param array $site Antwort von shopsiteconfig (data)
 * @param array $stand Antwort von shopbuildstatus (portalHugoCmsCall), falls vorhanden
 * @return array Liste von Befunden (portalSiteFinding), leer = nichts zu beanstanden
 */
function portalSiteChecks($db, int $website, array $site, array $stand = []): array {
    $befunde = [];
    $paket = trim((string)($site['grants']['package'] ?? $stand['data']['grants']['package'] ?? 'oserp-portal'), '/');
    $mounts = portalSiteMounts($site);

    $verzeichnis = trim(websiteValue($db, $website, 'portal_path'), " /\t");
    $erwartet = [
        ['source' => $paket.'/static',           'target' => 'static',           'genau' => true],
        ['source' => $paket.'/layouts',          'target' => 'layouts',          'genau' => true],
        ['source' => $paket.'/assets/portal-ui', 'target' => 'assets/portal-ui', 'genau' => true],
        ['source' => $paket.'/content',          'target' => 'content/'.('' === $verzeichnis ? 'kunden' : $verzeichnis), 'genau' => false],
    ];
    foreach ($erwartet as $soll) {
        $ist = null;
        foreach ($mounts as $mount) {
            if ($mount['source'] === $soll['source']) {
                $ist = $mount;
                break;
            }
        }
        $fix = ['source' => $soll['source'], 'target' => $soll['target']];
        if (null === $ist) {
            $befunde[] = portalSiteFinding('MOUNT_MISSING', 'error', ['source' => $soll['source'], 'target' => $soll['target']], $fix);
            continue;
        }
        $passt = $soll['genau']
            ? $ist['target'] === $soll['target']
            : ('content' === $ist['target'] || str_starts_with($ist['target'], 'content/'));
        if (!$passt) {
            $befunde[] = portalSiteFinding('MOUNT_TARGET', 'error',
                ['source' => $soll['source'], 'target' => $soll['target'], 'actual' => $ist['target']], $fix);
        }
    }

    // Hugos Warnungen beim Laden: abgekündigte Schlüssel — heute Warnung, in
    // einer künftigen Version Fehler. Wir bauen immer mit der neuesten.
    foreach ((array)($site['warnings'] ?? []) as $warnung) {
        $warnung = trim((string)$warnung);
        if ('' !== $warnung) {
            $befunde[] = portalSiteFinding('HUGO_WARNING', 'warning', ['text' => $warnung]);
        }
    }

    return portalHookFilter('site_checks', $befunde, $db, $website, $site, $stand);
}
