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
 * Pfade der Portalseiten einer Webseite, Schlüssel wie in portal-ui/src/core/paths.js
 *
 * Aus den Einstellungen (login_page, register_page, account_page,
 * invoice_page, activate_page, contact_page; Vorgaben unter /kunden/ aus
 * website_default_settings) und daraus abgeleitet: die Unterseiten des
 * Kontos hängen an account_page, die Dankeseite am Kontaktformular. Die
 * Seiten des Shops (Warenkorb, Kasse) haben feste Pfade an der Wurzel.
 * Erweiterungen passen die Liste über den Haken pages an.
 *
 * Zwei Abnehmer: die Seitenvorlagen im Paket (oserp-portal/content/,
 * Platzhalter [[login]] …) und assets/portal-ui/pages.json, aus dem der
 * Partial die Pfade an die Widgets gibt — so verlinken Seiten, Widgets und
 * Mails dieselben Adressen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @return array Schlüssel => Pfad ab der Wurzel, mit Schrägstrich am Ende
 */
function portalKitPages($db, int $website): array {
    // Relative Angaben hängen am Verzeichnis des Kundenportals (portal_path)
    $pfad = fn(string $key, string $vorgabe) => websitePagePath($db, $website, $key, $vorgabe);
    $konto = $pfad('account_page', 'konto/');
    $kontakt = $pfad('contact_page', 'kontakt/');
    $seiten = [
        'home'            => '/',
        'login'           => $pfad('login_page', 'anmelden/'),
        'logout'          => $konto.'abgemeldet/',
        'register'        => $pfad('register_page', 'registrieren/'),
        'registered'      => $konto,
        'account'         => $konto,
        'profile'         => $konto.'profil/',
        'addresses'       => $konto.'adressen/',
        // Belege: Rechnungen, Aufträge, Angebote, Lieferscheine, Reklamationen
        // nach den Schaltern documents_* der Webseite (lib/documents.php)
        'documents'       => $konto.'belege/',
        'payment'         => $konto.'zahlungsart/',
        'vehicles'        => $konto.'fahrzeuge/',
        // Ohne Freischaltung kein Pfad: die Shortcodes lassen activate-url
        // dann weg, der Link auf Anmelde- und Registrierseite entfällt
        'activate'        => websiteActivationEnabled($db, $website) ? $pfad('activate_page', 'freischalten/') : '',
        'invoice'         => $pfad('invoice_page', 'rechnung/'),
        'contact'         => $kontakt,
        'contactthanks'   => $kontakt.'danke/',
        'cart'            => '/warenkorb/',
        'checkout'        => '/kasse/',
        'paymentcanceled' => '/bezahlung-abgebrochen/',
    ];
    return portalHookFilter('pages', $seiten, $db, $website);
}

/**
 * Inhalt von assets/portal-ui/pages.json — liest der Partial der Webseite
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @return string
 */
function portalKitPagesJson($db, int $website): string {
    return json_encode(portalKitPages($db, $website), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
}

/**
 * Gehört eine Seitenvorlage ins Paket dieser Webseite?
 *
 * Eine Vorlage kann im Vorspann eine Voraussetzung nennen:
 *   oserp_requires: activation   — Konto-Freischaltung eingeschaltet
 *   oserp_requires: <merkmal>    — Merkmal der Webseite (shop, lxcars …)
 * Ohne Angabe gehört sie immer dazu.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param string $quelle Vorlagendatei
 * @return bool
 */
function portalKitPageWanted($db, int $website, string $quelle): bool {
    $kopf = (string)file_get_contents($quelle, false, null, 0, 2048);
    if (!preg_match('/^oserp_requires:\s*([a-z_]+)\s*$/m', $kopf, $m)) {
        return true;
    }
    if ('activation' === $m[1]) {
        return websiteActivationEnabled($db, $website);
    }
    return !empty(portalKitFeatures($db, $website)[$m[1]]);
}

/**
 * Eine Seitenvorlage des Pakets (oserp-portal/content/…) für diese Webseite
 *
 * Platzhalter: [[login]], [[account]], [[profile]] … die Pfade aus
 * portalKitPages; [[feature:shop]], [[feature:lxcars]] als true/false für
 * Shortcode-Schalter wie show-payment. Unbekannte Platzhalter werden leer.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param string $quelle Vorlagendatei im Vorlagensatz
 * @return string
 */
function portalKitPageRender($db, int $website, string $quelle): string {
    $seiten = portalKitPages($db, $website);
    $features = portalKitFeatures($db, $website);
    $inhalt = (string)file_get_contents($quelle);
    return preg_replace_callback('/\[\[(feature:)?([a-z]+)\]\]/', function (array $m) use ($seiten, $features) {
        if ('' !== $m[1]) {
            return !empty($features[$m[2]]) ? 'true' : 'false';
        }
        return $seiten[$m[2]] ?? '';
    }, $inhalt);
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
    // Seitenvorlagen mit Voraussetzung (oserp_requires: activation) nur, wenn
    // die Webseite die Funktion anbietet — sonst fällt die Seite aus dem Paket
    // und damit beim nächsten Abgleich aus der Webseite
    $imPaket = array_filter($imPaket, fn($quelle, $relativ) => !str_starts_with($relativ, 'content/')
        || portalKitPageWanted($db, $website, $quelle), ARRAY_FILTER_USE_BOTH);
    $ziel = portalPackageDir($db, $website, $paket, true);

    foreach ($imPaket as $relativ => $quelle) {
        $zielDatei = $ziel.'/'.$relativ;
        // Seitenvorlagen (content/) werden je Webseite ausgefüllt, alles
        // andere unverändert kopiert
        $gerendert = str_starts_with($relativ, 'content/') ? portalKitPageRender($db, $website, $quelle) : null;
        if (is_file($zielDatei) && (null === $gerendert
                ? md5_file($zielDatei) === md5_file($quelle)
                : file_get_contents($zielDatei) === $gerendert)) {
            continue;
        }
        if (!is_dir(dirname($zielDatei))) {
            mkdir(dirname($zielDatei), 0775, true);
        }
        $ok = null === $gerendert ? copy($quelle, $zielDatei) : false !== file_put_contents($zielDatei, $gerendert, LOCK_EX);
        if (!$ok) {
            throw new ApiError('PORTAL_WRITE_FAILED', 'Datei nicht kopierbar: '.$zielDatei);
        }
        $bilanz['kopiert']++;
    }

    // Die beiden Konfigurationsdateien entstehen unten; alles andere, was
    // nicht im Paket steht, fällt weg. Kinder vor Eltern, damit leer
    // gewordene Verzeichnisse mit verschwinden; Verknüpfungen werden als
    // solche gelöscht, nie ihr Ziel.
    $eigene = ['config.json', 'assets/portal-ui/features.json', 'assets/portal-ui/pages.json'];
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

    foreach ([
        'config.json'                   => portalKitConfig($db, $website),
        'assets/portal-ui/features.json' => portalKitFeaturesJson($db, $website),
        'assets/portal-ui/pages.json'    => portalKitPagesJson($db, $website),
    ] as $relativ => $inhalt) {
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
 * Sperre der Veröffentlichung — je Mandant, über alle Wege
 *
 * „Paket installieren“ (Kern) und der Lauf der Shop-Erweiterung schreiben in
 * dieselbe Bereitstellung und liefern an dieselbe Webseite. Eine Sperre in
 * PostgreSQL (advisory lock) lässt nur einen zugleich: ein zweiter bekommt
 * false und meldet „läuft gerade“. PHP-FPM hält die Verbindung über die
 * Anfrage hinaus offen (PDO::ATTR_PERSISTENT), und die Sperre hängt an der
 * Verbindung — deshalb gibt sie ein Aufräumer beim Beenden frei, falls der
 * reguläre Weg ausfällt.
 *
 * @param object $db Company-Datenbankverbindung
 * @return bool false, wenn schon jemand arbeitet
 */
function portalPublishLock($db): bool {
    static $aufraeumerAngemeldet = false;

    $zeile = $db->getOne("SELECT pg_try_advisory_lock(hashtext('oserp'), hashtext('shop_publish')) AS genommen");
    $genommen = in_array($zeile['genommen'] ?? null, [true, 't', '1', 1], true);

    if ($genommen && !$aufraeumerAngemeldet) {
        $aufraeumerAngemeldet = true;
        register_shutdown_function(static function () use ($db) { portalPublishUnlock($db); });
    }

    return $genommen;
}

/**
 * Gibt die Sperre der Veröffentlichung frei
 *
 * Mehrfach aufrufbar: hält die Verbindung sie nicht mehr, meldet PostgreSQL
 * das nur zurück. Ist die Verbindung schon zu, ist die Sperre ohnehin weg.
 *
 * @param object $db Company-Datenbankverbindung
 * @return void
 */
function portalPublishUnlock($db): void {
    try {
        $db->getOne("SELECT pg_advisory_unlock(hashtext('oserp'), hashtext('shop_publish')) AS frei");
    } catch (Throwable $e) {
        // Verbindung fort, Sperre fort — nichts zu tun
    }
}

/**
 * Veröffentlicht das Paket einer Webseite: abgleichen, an HugoCMS übertragen, bauen
 *
 * Gleich hier, ohne Auftrag und Läufer — unabhängig davon, ob die
 * Shop-Erweiterung aktiv ist. HugoCMS löscht bei der Übernahme, was die
 * vorige Lieferung enthielt und diese nicht mehr; deshalb nennt die
 * Lieferung alles, was die Webseite von OpensourceERP haben soll: das Paket
 * aus der Bereitstellung, dazu was die Erweiterungen über den Haken
 * publish_entries beitragen (der Shop seine Produktseiten,
 * Kategorieübersicht und Medien). Der Haken läuft vor dem Paketabgleich,
 * damit eine ältere Bereitstellung je Kanal vorher umziehen kann.
 *
 * Der Aufrufer hält die Sperre (portalPublishLock).
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

    // Beitrag der Erweiterungen zur Lieferung: Einträge außerhalb des Pakets,
    // eigene Übertragung großer Dateien (extern), Meldungen
    $beitrag = portalHookFilter('publish_entries', ['eintraege' => [], 'extern' => null, 'meldungen' => []], $db, $website, $stand);
    foreach ((array)($beitrag['meldungen'] ?? []) as $text) {
        $sagen((string)$text);
    }

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
    // Paket vor dem Beitrag: bei gleichem Pfad gilt die Datei des Pakets
    $eintraege = $eintraege + (array)($beitrag['eintraege'] ?? []);
    ksort($eintraege);

    $ergebnis = portalHugoCmsTransfer($db, $website, $eintraege, fn(string $pfad) => (string)@file_get_contents($wurzel.'/'.$pfad),
        is_callable($beitrag['extern'] ?? null) ? $beitrag['extern'] : null);
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
