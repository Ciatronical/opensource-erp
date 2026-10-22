<?php
// backend/api/portal/lib/kit.php
//
// Das Webseiten-Paket des Kundenportals (dev/kundenportal.md, Stufe 4): die
// Dateien, die eine Webseite in HugoCMS braucht, damit die Widgets laufen —
// Partials, Shortcodes, die Bündel der Widgets, Weiterleiter und
// Konfiguration. Grundlage ist der mitgelieferte Satz unter
// backend/templates-default/portal/standard/kit/; der gewählte Satz der
// Webseite (template_set) legt sich dateiweise darüber, danach die Pakete
// der aktiven Erweiterungen (Haken publish_files — der Shop bringt Shortcodes
// und Partials seiner Widgets mit).
//
// Das Paket liegt in der Bereitstellung der Webseite unter dem Namen, den
// HugoCMS freigibt (Freigabe „Paket“, bisher oserp-portal/), und geht von dort
// an HugoCMS (lib/hugocms.php). Welche Bündel der Partial lädt, sagt ihm
// features.json im Paket.

/** Mitgelieferte Vorlagensätze des Kundenportals */
function portalTemplateMasterDir(): string {
    return dirname(__DIR__, 3).'/templates-default/portal';
}

/** Vorlagensätze des Kunden (eigenes Repository, Pfad aus der settings.ini) */
function portalTemplateUserDir(): string {
    return (defined('OSERP_TEMPLATES_DIR') ? OSERP_TEMPLATES_DIR : dirname(__DIR__, 3).'/templates').'/portal';
}

/**
 * Beschreibung eines Satzes aus theme.json
 *
 * @param string $verzeichnis Verzeichnis des Satzes
 * @return array leer, wenn es keine oder eine unbrauchbare theme.json gibt
 */
function portalTemplateInfo(string $verzeichnis): array {
    $datei = $verzeichnis.'/theme.json';
    if (!is_file($datei)) {
        return [];
    }
    $roh = json_decode((string)file_get_contents($datei), true);
    return is_array($roh) ? $roh : [];
}

/**
 * Alle wählbaren Vorlagensätze
 *
 * Die Sätze des Kerns und die der Erweiterungen (Haken template_sets — der
 * Shop seine Sätze mit den Produktseiten) unter einem Namen: ein Satz
 * „sonic24“ gilt für Paket und Produktseiten zugleich.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array Liste aus name, title, source
 */
function portalTemplateSets($db): array {
    $saetze = [];
    foreach ([['default', portalTemplateMasterDir()], ['custom', portalTemplateUserDir()]] as $herkunft) {
        foreach (glob($herkunft[1].'/*', GLOB_ONLYDIR) ?: [] as $verzeichnis) {
            $name = basename($verzeichnis);
            $info = portalTemplateInfo($verzeichnis);
            $saetze[$name] = ['name' => $name, 'title' => $info['title'] ?? $name, 'source' => $herkunft[0]];
        }
    }
    foreach (portalHookFilter('template_sets', [], $db) as $satz) {
        if (!isset($saetze[$satz['name']])) {
            $saetze[$satz['name']] = $satz;
        }
    }
    ksort($saetze);
    return array_values($saetze);
}

/**
 * Prüft den Namen eines Vorlagensatzes
 *
 * Nur ein Name, kein Pfad: die Einstellung ist für Mitarbeiter änderbar, und
 * mit einem Pfad ließe sich jede lesbare Datei des Servers einbinden.
 *
 * @param string $name
 * @return string
 * @throws ApiError PORTAL_TEMPLATE_SET_INVALID
 */
function portalTemplateSetName(string $name): string {
    $name = trim($name);
    if ('' === $name) {
        return 'standard';
    }
    if ('.' === $name[0] || preg_match('#[/\\\\]#', $name)) {
        throw new ApiError('PORTAL_TEMPLATE_SET_INVALID', 'Unbrauchbarer Vorlagensatz: '.$name);
    }
    return $name;
}

/**
 * Legt die Dateien eines Verzeichnisses über die Sammlung
 *
 * @param string $quelle Verzeichnis
 * @param array $dateien relativer Pfad => Quelldatei
 * @return void
 */
function portalKitLayer(string $quelle, array &$dateien): void {
    if (!is_dir($quelle)) {
        return;
    }
    $eintraege = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($quelle, FilesystemIterator::SKIP_DOTS));
    foreach ($eintraege as $datei) {
        if ($datei->isFile()) {
            $dateien[str_replace('\\', '/', substr($datei->getPathname(), strlen($quelle) + 1))] = $datei->getPathname();
        }
    }
}

/**
 * Dateien des Pakets einer Webseite, relativer Pfad => Quelle
 *
 * Schichten in dieser Reihenfolge, jede ersetzt dateiweise: Kern standard,
 * Kern <satz> (mitgeliefert, dann Kundenkopie), danach die Erweiterungen
 * (Haken publish_files). Entfernen kann eine Schicht eine Datei der
 * Grundlage nicht, nur ersetzen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param string $satz Vorlagensatz, leer = der der Webseite (template_set)
 * @return array
 * @throws ApiError PORTAL_TEMPLATE_SET_INVALID
 */
function portalKitFiles($db, int $website, string $satz = ''): array {
    $satz = portalTemplateSetName('' !== $satz ? $satz : websiteValue($db, $website, 'template_set', 'standard'));

    $dateien = [];
    portalKitLayer(portalTemplateMasterDir().'/standard/kit', $dateien);
    if ('standard' !== $satz) {
        portalKitLayer(portalTemplateMasterDir().'/'.$satz.'/kit', $dateien);
    }
    portalKitLayer(portalTemplateUserDir().'/'.$satz.'/kit', $dateien);

    $dateien = portalHookFilter('publish_files', $dateien, $db, $website, $satz);
    ksort($dateien);
    return $dateien;
}

/**
 * Was auf dieser Webseite läuft — für features.json im Paket
 *
 * Der Kern immer; jede aktive Erweiterung trägt sich über den Haken features
 * ein, wenn sie auf dieser Webseite etwas anbietet (der Shop, wenn ein
 * HugoShop der Webseite zugeordnet ist). Der Partial lädt danach die Bündel.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @return array Name => true
 */
function portalKitFeatures($db, int $website): array {
    $features = portalHookFilter('features', ['portal' => true], $db, $website);
    return array_map(fn($v) => (bool)$v, $features);
}

/**
 * Inhalt von config.json im Paket
 *
 * Adresse und Schlüssel für Weiterleiter und 404-Seite, dazu die Merkmale der
 * Webseite. Die Datei liegt außerhalb des Docroots, und Hugo hängt sie nicht
 * ein. JSON statt PHP: HugoCMS schreibt bewusst kein PHP.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @return string
 */
function portalKitConfig($db, int $website): string {
    $werte = [
        '_hinweis'  => 'Von OpensourceERP geschrieben — nicht von Hand ändern.',
        'url'       => websiteValue($db, $website, 'backend_url'),
        'key'       => websiteValue($db, $website, 'public_key'),
        'website'   => $website,
        'features'  => portalKitFeatures($db, $website),
    ];
    return json_encode($werte, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
}

/**
 * Inhalt von assets/portal-ui/features.json — liest der Partial der Webseite
 *
 * Im Verzeichnis der Bündel, weil Hugo es dort schon einhängt (Mount
 * assets/portal-ui); ein zweiter Mount wäre in jeder Webseite nachzutragen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @return string
 */
function portalKitFeaturesJson($db, int $website): string {
    return json_encode(portalKitFeatures($db, $website), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
}

// ── Bereitstellung ──

/**
 * Grundname der Dateien unter backend/tmp/, je Mandant
 *
 * Derselbe wie beim Lauf der Shop-Erweiterung (shop-publish-<db>): Sperre,
 * Stand und Bereitstellungen liegen beieinander.
 *
 * @param object $db Company-Datenbankverbindung
 * @return string absoluter Pfad ohne Endung
 */
function portalStateBase($db): string {
    $verzeichnis = dirname(__DIR__, 3).'/tmp';
    if (!is_dir($verzeichnis)) {
        @mkdir($verzeichnis, 0775, true);
    }
    $verzeichnis = realpath($verzeichnis) ?: $verzeichnis;
    $zeile = $db->getOne("SELECT current_database() AS name");
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)($zeile['name'] ?? 'oserp'));
    return $verzeichnis.'/shop-publish-'.$name;
}

/**
 * Bereitstellung einer Webseite
 *
 * Alles, was OpensourceERP für die Webseite erzeugt — Paket, dazu
 * Produktseiten und Kategorieübersicht des Shops —, landet hier, in derselben
 * Ordnung wie im Hugo-Projekt, und geht von dort an HugoCMS. Es bleibt
 * zwischen den Läufen erhalten: es ist das Abbild dessen, was die Webseite
 * von OpensourceERP haben soll.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param string|null $uebernehmen Verzeichnis einer früheren Bereitstellung
 *                    (je Kanal, vor dem Kundenportal), das beim ersten Zugriff
 *                    umzieht — sonst räumte eine leere Bereitstellung die
 *                    Webseite leer
 * @return string absoluter Pfad
 * @throws ApiError PORTAL_STAGING_UNAVAILABLE
 */
function portalStagingDir($db, int $website, ?string $uebernehmen = null): string {
    $verzeichnis = portalStateBase($db).'-w'.$website.'-staging';
    if (!is_dir($verzeichnis) && null !== $uebernehmen && is_dir($uebernehmen)) {
        @rename($uebernehmen, $verzeichnis);
    }
    if (!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0775, true) && !is_dir($verzeichnis)) {
        throw new ApiError('PORTAL_STAGING_UNAVAILABLE', 'Bereitstellungsverzeichnis lässt sich nicht anlegen: '.$verzeichnis);
    }
    return realpath($verzeichnis) ?: $verzeichnis;
}

/**
 * Verzeichnis des Pakets in der Bereitstellung
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param string $paket Name aus der Freigabe von HugoCMS (oserp-portal, oserp-portal)
 * @param bool $anlegen fehlendes Verzeichnis anlegen
 * @return string absoluter Pfad
 */
function portalPackageDir($db, int $website, string $paket = 'oserp-portal', bool $anlegen = false): string {
    $paket = trim($paket, '/');
    if ('' === $paket || preg_match('#(^|/)\.\.?(/|$)#', $paket)) {
        throw new ApiError('PORTAL_PACKAGE_INVALID', 'Unbrauchbarer Paketname: '.$paket);
    }
    $ziel = portalStagingDir($db, $website).'/'.$paket;
    if ($anlegen && !is_dir($ziel)) {
        @mkdir($ziel, 0775, true);
    }
    return $ziel;
}

/**
 * Spiegelt das Paket der Webseite in die Bereitstellung
 *
 * Kopiert nur, was sich geändert hat, und entfernt, was das Paket nicht mehr
 * enthält — beides nur innerhalb des Paketverzeichnisses. config.json und
 * features.json schreibt der Abgleich selbst, nur wenn sich ihr Inhalt
 * ändert.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param string $paket Name des Pakets in der Freigabe von HugoCMS
 * @return array kopiert, entfernt, config (true wenn neu geschrieben)
 * @throws ApiError PORTAL_WRITE_FAILED
 */
function portalSyncKit($db, int $website, string $paket = 'oserp-portal'): array {
    $bilanz = ['kopiert' => 0, 'entfernt' => 0, 'config' => false];

    $imPaket = portalKitFiles($db, $website);
    if (!$imPaket) {
        return $bilanz;
    }
    $ziel = portalPackageDir($db, $website, $paket, true);

    foreach ($imPaket as $relativ => $quelle) {
        $zielDatei = $ziel.'/'.$relativ;
        if (is_file($zielDatei) && md5_file($zielDatei) === md5_file($quelle)) {
            continue;
        }
        if (!is_dir(dirname($zielDatei))) {
            mkdir(dirname($zielDatei), 0775, true);
        }
        if (!copy($quelle, $zielDatei)) {
            throw new ApiError('PORTAL_WRITE_FAILED', 'Datei nicht kopierbar: '.$zielDatei);
        }
        $bilanz['kopiert']++;
    }

    // Die beiden Konfigurationsdateien entstehen unten; alles andere, was
    // nicht im Paket steht, fällt weg. Kinder vor Eltern, damit leer
    // gewordene Verzeichnisse mit verschwinden; Verknüpfungen werden als
    // solche gelöscht, nie ihr Ziel.
    $eigene = ['config.json', 'assets/portal-ui/features.json'];
    $eintraege = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($ziel, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($eintraege as $eintrag) {
        $relativ = str_replace('\\', '/', substr($eintrag->getPathname(), strlen($ziel) + 1));
        if (in_array($relativ, $eigene, true)) {
            continue;
        }
        if ($eintrag->isDir() && !$eintrag->isLink()) {
            if (!(new FilesystemIterator($eintrag->getPathname()))->valid()) {
                rmdir($eintrag->getPathname());
            }
            continue;
        }
        if (!isset($imPaket[$relativ])) {
            unlink($eintrag->getPathname());
            $bilanz['entfernt']++;
        }
    }

    foreach (['config.json' => portalKitConfig($db, $website), 'assets/portal-ui/features.json' => portalKitFeaturesJson($db, $website)] as $relativ => $inhalt) {
        $datei = $ziel.'/'.$relativ;
        if (is_file($datei) && file_get_contents($datei) === $inhalt) {
            continue;
        }
        if (!is_dir(dirname($datei))) {
            mkdir(dirname($datei), 0775, true);
        }
        if (false === file_put_contents($datei, $inhalt, LOCK_EX)) {
            throw new ApiError('PORTAL_WRITE_FAILED', 'Datei nicht schreibbar: '.$datei);
        }
        $bilanz['config'] = true;
    }

    $bilanz['entfernt'] += portalStalePackagesRemove(dirname($ziel), basename($ziel));

    return $bilanz;
}

/**
 * Räumt frühere Pakete aus der Bereitstellung
 *
 * Vor dem 2026-10-22 hieß das Paket oserp-shop/; eine übernommene
 * Bereitstellung trägt es noch neben dem aktuellen Paket. Die Übertragung
 * schickte es weiter mit, HugoCMS lehnte jede Datei ab („nicht angenommen“).
 * Hier fällt alles weg, was wie ein Paket heißt (oserp-*) und nicht das
 * aktuelle ist. Produktseiten und Daten des Shops bleiben unberührt.
 *
 * @param string $staging Bereitstellung der Webseite
 * @param string $aktuell Name des aktuellen Pakets, etwa oserp-portal
 * @return int entfernte Dateien
 */
function portalStalePackagesRemove(string $staging, string $aktuell): int {
    $entfernt = 0;
    foreach (glob($staging.'/oserp-*', GLOB_ONLYDIR) ?: [] as $alt) {
        if (basename($alt) === $aktuell || is_link($alt)) {
            continue;
        }
        $eintraege = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($alt, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($eintraege as $eintrag) {
            if ($eintrag->isDir() && !$eintrag->isLink()) {
                @rmdir($eintrag->getPathname());
            } elseif (@unlink($eintrag->getPathname())) {
                $entfernt++;
            }
        }
        @rmdir($alt);
    }
    return $entfernt;
}

/**
 * PHP-Einstiegspunkte des Pakets: Weiterleiter und 404-Seite
 *
 * HugoCMS nimmt sie nur signiert an (dev/shop-php-signatur.md); ohne
 * Signaturschlüssel legt man sie von Hand auf den Webserver.
 *
 * @param string $paket Paketname
 * @return string[] Pfade in der Lieferung
 */
function portalKitPhpFiles(string $paket = 'oserp-portal'): array {
    return [$paket.'/static/portal-api/index.php', $paket.'/static/not_found.php'];
}

/**
 * Veröffentlicht das Paket einer Webseite ohne Shop
 *
 * Für Webseiten, auf denen nur das Kundenportal läuft: Paket abgleichen, an
 * HugoCMS übertragen, bauen lassen — gleich hier, ohne Auftrag und Läufer.
 * Webseiten mit HugoShop veröffentlicht der Lauf der Shop-Erweiterung
 * (Haken install_package).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param callable $sagen Meldung
 * @param callable $fehler Fehlermeldung
 * @param bool $bauen nach der Übertragung bauen lassen
 * @return bool true wenn übertragen (und gebaut, falls verlangt)
 */
function portalPublishWebsite($db, int $website, callable $sagen, callable $fehler, bool $bauen = true): bool {
    $stand = portalHugoCmsCall($db, $website, 'shopbuildstatus');
    if (!$stand['ok']) {
        $fehler('HugoCMS: '.$stand['fehler']);
        return false;
    }
    $freigaben = portalHugoCmsGrantsFrom($stand);
    if ('' !== $freigaben['fehler']) {
        $fehler($freigaben['fehler']);
        return false;
    }
    $paket = $freigaben['freigaben']['package'];

    try {
        $abgleich = portalSyncKit($db, $website, $paket);
        $sagen(sprintf('Paket abgeglichen: %d kopiert, %d entfernt%s',
            $abgleich['kopiert'], $abgleich['entfernt'], $abgleich['config'] ? ', Konfiguration neu' : ''));
    } catch (Throwable $e) {
        $fehler('Paketabgleich fehlgeschlagen: '.$e->getMessage());
        return false;
    }

    // Lieferung: alle Dateien des Pakets mit den angenommenen Endungen,
    // PHP nur signiert
    $wurzel = portalStagingDir($db, $website);
    $endungen = (array)($stand['data']['accept'] ?? []);
    $signiert = (array)($stand['data']['signedPhp'] ?? []);
    $kannSignieren = !empty($signiert['ready']) && '' !== portalSigningSecretKey();
    $eintraege = [];
    $uebersprungen = [];
    $gesendetPhp = [];
    $verzeichnis = $wurzel.'/'.$paket;
    if (is_dir($verzeichnis)) {
        $alle = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($verzeichnis, FilesystemIterator::SKIP_DOTS));
        foreach ($alle as $eintrag) {
            if (!$eintrag->isFile()) {
                continue;
            }
            $pfad = $paket.'/'.str_replace('\\', '/', substr($eintrag->getPathname(), strlen($verzeichnis) + 1));
            $endung = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
            $pruefsumme = hash_file('sha256', $eintrag->getPathname());
            if (in_array($endung, $endungen, true)) {
                $eintraege[$pfad] = ['sha256' => $pruefsumme];
            } elseif ($kannSignieren && in_array($pfad, (array)($signiert['paths'] ?? []), true)) {
                $eintraege[$pfad] = ['sha256' => $pruefsumme, 'signature' => portalSigningSign($pfad, $pruefsumme)];
                $gesendetPhp[] = $pfad;
            } else {
                $uebersprungen[] = $pfad;
            }
        }
    }
    ksort($eintraege);

    $ergebnis = portalHugoCmsTransfer($db, $website, $eintraege, fn(string $pfad) => (string)@file_get_contents($wurzel.'/'.$pfad));
    if (!$ergebnis['ok']) {
        $fehler('Übertragung an HugoCMS fehlgeschlagen: '.$ergebnis['fehler']);
        return false;
    }
    $sagen(sprintf('An HugoCMS übertragen: %d geschrieben, %d entfernt, %d unverändert',
        $ergebnis['written'], $ergebnis['deleted'], $ergebnis['unchanged']));
    if ($gesendetPhp) {
        $sagen('Signiert an HugoCMS geliefert: '.implode(', ', $gesendetPhp));
    }
    $vonHand = array_values(array_filter($uebersprungen, fn($p) => in_array($p, portalKitPhpFiles($paket), true)));
    if ($vonHand) {
        $sagen('Von Hand auf den Webserver legen (HugoCMS nimmt PHP nur signiert an): '.implode(', ', $vonHand)
            .('' === portalSigningPublicKey()
                ? ' — oder in OpensourceERP ein Schlüsselpaar erzeugen (Systemeinstellungen) und den öffentlichen Schlüssel in HugoCMS eintragen.'
                : ' — oder den Signaturschlüssel von OpensourceERP in HugoCMS eintragen: '.portalSigningPublicKey()));
    }

    if (!$bauen) {
        return true;
    }
    $sagen('HugoCMS baut die Webseite …');
    $bau = portalHugoCmsBuild($db, $website);
    if (!$bau['ok']) {
        $fehler('Bau in HugoCMS fehlgeschlagen: '.$bau['fehler']);
        return false;
    }
    $daten = (array)$bau['data'];
    if (array_key_exists('success', $daten) && !$daten['success']) {
        $fehler('HugoCMS meldet einen fehlgeschlagenen Bau'.(isset($daten['exitCode']) ? ' (Rückgabewert '.$daten['exitCode'].')' : ''));
        return false;
    }
    $sagen('Webseite gebaut'.(isset($daten['seconds']) ? ' ('.$daten['seconds'].' s)' : '').'.');
    return true;
}
