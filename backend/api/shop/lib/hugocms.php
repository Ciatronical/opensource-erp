<?php
// backend/api/shop/lib/hugocms.php
//
// Anbindung des Shops an HugoCMS (dev/shop-hugocms-trennung.md). HugoCMS baut
// die Webseite; OSERP liefert die Inhaltsdateien und stößt den Bau an.
//
// Den Transport — Zugang, Aufruf, Abgleich, Übertragung in Portionen, Bau —
// stellt seit dem Kundenportal der Kern (backend/api/portal/lib/hugocms.php),
// je Webseite (website_oserp). Hier bleibt, was den Shop ausmacht: die
// Lieferung aus Produktseiten, Kategorieübersicht und Medien, die
// Vorschaubilder, die Prüfung der Webseite. Jeder HugoShop gehört genau einer
// Webseite (sales_channel_shop.website_id); die Funktionen bekommen weiter den
// Kanal und holen sich die Webseite über shopWebsiteOfChannel().

/**
 * Prüft die eingestellte Adresse von HugoCMS — seit dem Kundenportal im Kern
 *
 * @see portalHugoCmsUrl()
 */
function shopHugoCmsUrl(string $adresse): array {
    return portalHugoCmsUrl($adresse);
}

/**
 * Übersetzt eine Fehlerantwort von HugoCMS — Kern
 *
 * @see portalHugoCmsErrorText()
 */
function shopHugoCmsErrorText(array $fehler): string {
    return portalHugoCmsErrorText($fehler);
}

/**
 * Ruft einen Befehl von HugoCMS auf — über die Webseite des HugoShops
 *
 * Adresse und Schlüssel gehören seit dem Kundenportal der Webseite
 * (website_oserp); der Kanal nennt sie über sales_channel_shop.website_id.
 * Den Transport erledigt der Kern (portalHugoCmsCall).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @param string $befehl etwa shopbuildstatus oder shopbuild
 * @param string $methode GET oder POST
 * @param int $zeitgrenze Sekunden bis zur Antwort
 * @param array $zugang url und key statt der gespeicherten (Verbindungstest)
 * @param array $daten weitere Felder im Rumpf eines POST
 * @param array|null $stueck Binärdaten als Datei-Upload
 * @return array ok, status, data, fehler
 */
function shopHugoCmsCall($db, int $kanal, string $befehl, string $methode = 'GET', int $zeitgrenze = 20, array $zugang = [], array $daten = [], ?array $stueck = null): array {
    $website = shopWebsiteOfChannel($db, $kanal);
    if ($website <= 0) {
        return ['ok' => false, 'status' => 0, 'data' => null,
                'fehler' => 'Dem HugoShop ist keine Webseite zugeordnet (Verkaufskanäle → Webseite)'];
    }
    return portalHugoCmsCall($db, $website, $befehl, $methode, $zeitgrenze, $zugang, $daten, $stueck);
}

// ── Freigaben (dev/shop-hugocms-verzeichnisse.md) ──
//
// Wohin OSERP Produktseiten und Kategorieübersicht schreibt, legt ein
// Administrator in HugoCMS fest (Projekteinstellungen → Shop-Erweiterung).
// OSERP hält keine eigene Kopie: Der Lauf fragt die Freigaben bei HugoCMS ab
// (shopbuildstatus, grants) und legt die Dateien in der Bereitstellung genau
// dort ab; die Kanalkarte zeigt sie nur an.

/** Freigaben, die OSERP braucht, mit ihren Namen in HugoCMS */
const SHOP_HUGOCMS_GRANTS = [
    'contentDir'     => 'Produktseiten',
    'categoryGroups' => 'Kategorieübersicht',
    'images'         => 'Produktbilder',
    'thumbnails'     => 'Vorschaubilder',
];

/**
 * Liest die Freigaben aus einer Antwort von shopbuildstatus
 *
 * Der Kern liest Paket und Medien (portalHugoCmsGrantsFrom); der Shop
 * verlangt dazu Produktseiten, Kategorieübersicht, Produkt- und Vorschaubilder.
 *
 * @param array $antwort Ergebnis von shopHugoCmsCall
 * @return array freigaben (contentDir, categoryGroups, images, thumbnails, downloads, package,
 *               medien, medien_stueck), fehler (Text, leer wenn brauchbar)
 */
function shopHugoCmsGrantsFrom(array $antwort): array {
    return portalHugoCmsGrantsFrom($antwort, SHOP_HUGOCMS_GRANTS);
}

/**
 * Freigaben eines HugoShops, je Prozess gemerkt
 *
 * Seiten, Kategorieübersicht und Abgleich eines Laufs sollen mit denselben
 * Freigaben arbeiten. Der Lauf fragt deshalb zu Beginn je HugoShop neu ab
 * ($neu) — eine in HugoCMS verlegte Freigabe gilt so ab dem nächsten Lauf.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @param bool $neu bei HugoCMS neu abfragen
 * @return array freigaben, fehler — wie shopHugoCmsGrantsFrom()
 */
function shopHugoCmsGrants($db, int $kanal, bool $neu = false): array {
    static $gemerkt = [];
    $schluessel = spl_object_id($db).':'.$kanal;
    if ($neu || !isset($gemerkt[$schluessel])) {
        $gemerkt[$schluessel] = shopHugoCmsGrantsFrom(shopHugoCmsCall($db, $kanal, 'shopbuildstatus'));
    }
    return $gemerkt[$schluessel];
}

/**
 * Freigaben eines HugoShops oder ein Fehler
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return array contentDir, categoryGroups, images, thumbnails, package
 * @throws ApiError SHOP_HUGOCMS_GRANTS
 */
function shopHugoCmsGrantsRequired($db, int $kanal): array {
    $freigaben = shopHugoCmsGrants($db, $kanal);
    if ('' !== $freigaben['fehler']) {
        throw new ApiError('SHOP_HUGOCMS_GRANTS', $freigaben['fehler']);
    }
    return $freigaben['freigaben'];
}

// ── Übertragung und Bau ──
//
// Der Lauf schreibt alles in die Bereitstellung (shopStagingDir). Von dort geht
// es in drei Schritten an HugoCMS: Abgleich (Verzeichnis mit Prüfsummen),
// Übertragung (nur was fehlt, in Portionen), Übernahme. Danach baut HugoCMS.
// Das Protokoll steht in backend/core/Shop/ShopSync.php von HugoCMS.

/** Höchstgröße einer Portion in Bytes, vor Base64 — bleibt unter post_max_size */
const SHOP_HUGOCMS_PORTION_BYTES = 1_500_000;

/** Höchstzahl Dateien je Portion */
const SHOP_HUGOCMS_PORTION_FILES = 500;

/** Spätestens nach dieser Zeit wird abgeglichen, auch wenn sich hier nichts geändert hat */
const SHOP_HUGOCMS_RESYNC_SECONDS = 86400;

/** Größe eines Stücks bei der Übertragung von Medien — HugoCMS nennt seine Grenze, die kleinere gilt */
const SHOP_HUGOCMS_MEDIA_CHUNK_BYTES = 4_194_304;

/**
 * PHP-Einstiegspunkte des Pakets (Weiterleiter, 404-Seite). HugoCMS nimmt sie
 * nur signiert an (dev/shop-php-signatur.md); ohne Signaturschlüssel legt man
 * sie von Hand auf den Webserver (E9). Ihre Konfiguration kommt als
 * config.json über die Übertragung.
 */
const SHOP_HUGOCMS_MANUAL_FILES = ['oserp-portal/static/portal-api/index.php', 'oserp-portal/static/not_found.php'];

// Signaturschlüssel der Installation: lib/signing.php (dev/shop-php-signatur.md)
require_once __DIR__.'/signing.php';

/**
 * Ruft eine Adresse auf, ohne Umleitungen zu folgen — Kern
 *
 * @see portalWebsiteProbe()
 */
function shopWebsiteProbe(string $adresse, ?array $json = null, array $kopfzeilen = []): array {
    return portalWebsiteProbe($adresse, $json, $kopfzeilen);
}

/**
 * Wer hat auf einen Aufruf von shopPing geantwortet? (testShopBackendUrl)
 *
 * Unterschieden an der Form der Antwort: OpensourceERP antwortet mit
 * success/text/payload, HugoCMS mit ok/error, der Weiterleiter selbst mit
 * success und den Codes PORTAL_PROXY_NOT_CONFIGURED bzw.
 * PORTAL_BACKEND_UNREACHABLE.
 *
 * @param array $antwort Ergebnis von shopWebsiteProbe
 * @param int $kanal erwarteter Kanal
 * @return array code (OK, OK_OLD, OTHER_CHANNEL, UNREACHABLE, HUGOCMS,
 *               KEY_REJECTED, PROXY_NOT_CONFIGURED, BACKEND_UNREACHABLE,
 *               NO_PHP, NOT_FOUND, FOREIGN), name (Kanal), status, fehler
 */
function shopShopApiClassify(array $antwort, int $kanal): array {
    $ergebnis = ['code' => 'FOREIGN', 'name' => '', 'status' => $antwort['status'], 'fehler' => $antwort['fehler']];
    $json = json_decode($antwort['rumpf'], true);
    $text = is_array($json) ? (string)($json['text'] ?? '') : '';

    if (0 === $antwort['status']) {
        $ergebnis['code'] = 'UNREACHABLE';
    } elseif (str_starts_with(ltrim($antwort['rumpf']), '<?php')) {
        $ergebnis['code'] = 'NO_PHP';
    } elseif (is_array($json) && array_key_exists('ok', $json) && is_array($json['error'] ?? null)) {
        $ergebnis['code'] = 'HUGOCMS';
    } elseif (is_array($json) && !empty($json['success']) && 'oserp-shop' === ($json['payload']['service'] ?? '')) {
        // Dienstkennung der Shop-Erweiterung (shopPing), nicht der Paketname
        $ergebnis['name'] = (string)($json['payload']['channel_name'] ?? '');
        $ergebnis['code'] = (int)($json['payload']['channel_id'] ?? 0) === $kanal ? 'OK' : 'OTHER_CHANNEL';
    } elseif ('PORTAL_NOT_AUTHORIZED' === $text) {
        $ergebnis['code'] = 'KEY_REJECTED';
    } elseif ('PORTAL_PROXY_NOT_CONFIGURED' === $text) {
        $ergebnis['code'] = 'PROXY_NOT_CONFIGURED';
    } elseif ('PORTAL_BACKEND_UNREACHABLE' === $text) {
        $ergebnis['code'] = 'BACKEND_UNREACHABLE';
        $ergebnis['fehler'] = (string)($json['debug'] ?? '');
    } elseif ('API_ACTION_NOT_ALLOWED' === $text) {
        // OpensourceERP ohne shopPing (älterer Stand): Schlüssel angenommen,
        // nur den Kanal nennt es nicht
        $ergebnis['code'] = 'OK_OLD';
    } elseif (404 === $antwort['status']) {
        $ergebnis['code'] = 'NOT_FOUND';
    }
    return $ergebnis;
}

/**
 * Prüft die von Hand abgelegten PHP-Dateien auf der Webseite (E9)
 *
 * HugoCMS nimmt kein PHP an; ob Weiterleiter und 404-Seite auf dem Webserver
 * liegen, weiß der Lauf deshalb nicht aus der Übertragung. Beide Dateien
 * senden ihre Prüfsumme (X-Oserp-Portal-File); verglichen wird mit der Datei im
 * Vorlagensatz des Kanals.
 *
 * - Weiterleiter: Aufruf von <Basisadresse>/portal-api/
 * - 404-Seite: Aufruf einer Adresse, die es nicht gibt — prüft zugleich, ob
 *   der Webserver die 404 an not_found.php gibt
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @param array $signiert Pfade, die dieser Lauf signiert an HugoCMS geliefert
 *                        hat — dann liegen sie in der Webseite, und „von Hand
 *                        ablegen“ wäre der falsche Rat
 * @return array je Datei: pfad, stand (ok, veraltet, fehlt, kein_php,
 *               nicht_eingerichtet, falsches_ziel, ungeprueft), text (leer bei ok)
 */
function shopWebsiteManualFilesCheck($db, int $kanal, array $signiert = []): array {
    $basis = rtrim(trim(shopSiteValue($db, $kanal, 'base_url')), '/');
    // Ohne lesbaren Vorlagensatz fehlt der Vergleich — dann zählt nur, ob
    // die Datei antwortet; den Satz selbst meldet die Übertragung
    try {
        $paket = shopKitFiles($db, $kanal);
    } catch (ApiError $e) {
        $paket = [];
    }
    $ergebnis = [];
    foreach (SHOP_HUGOCMS_MANUAL_FILES as $pfad) {
        $quelle = $paket[substr($pfad, strlen('oserp-portal/'))] ?? '';
        $soll = '' !== $quelle ? (string)sha1_file($quelle) : '';
        $weiterleiter = str_ends_with($pfad, 'portal-api/index.php');
        // Signiert geliefert: die Datei liegt in der Webseite. Antwortet sie
        // trotzdem nicht, liefert der Webserver unter der Basisadresse nicht
        // dieses public/ aus — oder die Basisadresse stimmt nicht
        $handablage = in_array($pfad, $signiert, true)
            ? 'Die Datei ist signiert an HugoCMS geliefert und liegt in der Webseite ('.$pfad
              .'), wird unter der Basisadresse '.$basis.' aber nicht ausgeliefert — Adresse der Webseite unter '
              .'Firmenkonfiguration → Kundenportal prüfen und ob der Webserver dafür das Verzeichnis public/ dieser Webseite ausliefert. Datei: '
            : 'Von Hand auf den Webserver legen (HugoCMS nimmt kein PHP an): ';

        if ('' === $basis) {
            $ergebnis[] = ['pfad' => $pfad, 'stand' => 'ungeprueft',
                           'text' => 'Nicht geprüft, die Webseite hat keine Adresse (Firmenkonfiguration → Kundenportal). '.$handablage.$pfad];
            continue;
        }

        $adresse = $weiterleiter
            ? $basis.'/portal-api/'
            : $basis.'/oserp-portal-pruefung-'.bin2hex(random_bytes(4));
        // Den Weiterleiter mit shopPing: so zeigt die Antwort auch, wohin er
        // weiterleitet (shopShopApiClassify)
        $antwort = $weiterleiter ? shopWebsiteProbe($adresse, ['action' => 'shopPing']) : shopWebsiteProbe($adresse);
        $ist = (string)($antwort['kopf']['x-oserp-portal-file'] ?? '');
        $json = json_decode($antwort['rumpf'], true);

        if (0 === $antwort['status']) {
            $stand = 'ungeprueft';
            $text = 'Nicht geprüft, '.$adresse.' ist nicht erreichbar ('.$antwort['fehler'].'). '.$handablage.$pfad;
        } elseif ('' !== $ist && ($ist === $soll || '' === $soll)) {
            $stand = 'ok';
            $text = '';
            // Weiterleiter liegt richtig — aber erreicht er OpensourceERP?
            $ziel = $weiterleiter ? shopShopApiClassify($antwort, $kanal)['code'] : 'OK';
            if ('PROXY_NOT_CONFIGURED' === $ziel) {
                $stand = 'nicht_eingerichtet';
                $text = 'Warnung: Der Weiterleiter '.$adresse.' läuft, findet aber oserp-portal/config.json nicht '
                      .'oder unvollständig — Adresse des Portal-Zugangs und Schlüssel der Webseite unter Firmenkonfiguration → Kundenportal prüfen.';
            } elseif ('HUGOCMS' === $ziel) {
                $stand = 'falsches_ziel';
                $text = 'Warnung: Der Weiterleiter '.$adresse.' erreicht HugoCMS statt OpensourceERP — '
                      .'„Adresse des Portal-Zugangs von OpensourceERP“ unter Firmenkonfiguration → Kundenportal prüfen (Verbindung prüfen).';
            } elseif (in_array($ziel, ['BACKEND_UNREACHABLE', 'KEY_REJECTED', 'OTHER_CHANNEL', 'OTHER_WEBSITE', 'FOREIGN', 'NOT_FOUND'], true)) {
                $stand = 'falsches_ziel';
                $text = 'Warnung: Der Weiterleiter '.$adresse.' erreicht den Portal-Zugang von OpensourceERP nicht ('
                      .$ziel.') — unter Firmenkonfiguration → Kundenportal „Verbindung prüfen“ bei „Adresse des Portal-Zugangs von OpensourceERP“.';
            }
        } elseif ('' !== $ist) {
            $stand = 'veraltet';
            $text = 'Veraltet auf dem Webserver, bitte neu ablegen: '.$pfad.' (aus dem Vorlagensatz, kit/'
                  .substr($pfad, strlen('oserp-portal/')).')';
        } elseif (str_starts_with(ltrim($antwort['rumpf']), '<?php')) {
            $stand = 'kein_php';
            $text = 'Warnung: '.$adresse.' liefert den Quelltext statt ihn auszuführen — '
                  .'PHP ist für die Webseite nicht eingerichtet.';
        } elseif ($weiterleiter && is_array($json) && array_key_exists('success', $json)) {
            // Antwortet wie der Weiterleiter, aber ohne Prüfsumme: Fassung von
            // vor dem 2026-10-08
            $stand = 'veraltet';
            $text = 'Veraltet auf dem Webserver, bitte neu ablegen: '.$pfad.' (aus dem Vorlagensatz, kit/'
                  .substr($pfad, strlen('oserp-portal/')).')';
        } elseif ($weiterleiter) {
            $stand = 'fehlt';
            $text = 'Warnung: Der Weiterleiter '.$adresse.' antwortet nicht (HTTP '.$antwort['status'].') — '
                  .'ohne ihn funktionieren Warenkorb, Kasse und Kundenkonto nicht. '.$handablage.$pfad;
        } else {
            $stand = 'fehlt';
            $text = 'Die 404-Seite mit den Umleitungen antwortet nicht (fehlt, ist veraltet oder der Webserver '
                  .'gibt die 404 nicht an sie weiter — nginx: error_page 404 /not_found.php; '
                  .'Apache: ErrorDocument 404 /not_found.php). '.$handablage.$pfad;
        }

        $ergebnis[] = ['pfad' => $pfad, 'stand' => $stand, 'text' => $text];
    }
    return $ergebnis;
}

/**
 * Verzeichnis der Bereitstellung mit Prüfsummen
 *
 * Nur, was HugoCMS annimmt, so wie ShopSync::allowedPath() dort prüft:
 * Markdown im Verzeichnis der Produktseiten, genau die Datei der
 * Kategorieübersicht, im Paket die angenommenen Endungen. Alles andere wird
 * nicht verschickt, sondern gemeldet — vor allem die PHP-Dateien des Pakets,
 * die HugoCMS nur signiert annimmt.
 *
 * @param string $wurzel Bereitstellungsverzeichnis
 * @param array $freigaben von HugoCMS: contentDir, categoryGroups, package
 * @param array $endungen von HugoCMS im Paket angenommene Endungen
 * @return array dateien (Pfad => sha256), uebersprungen (Pfade)
 */
function shopHugoCmsManifest(string $wurzel, array $freigaben, array $endungen): array {
    $ergebnis = ['dateien' => [], 'uebersprungen' => []];
    if (!is_dir($wurzel)) {
        return $ergebnis;
    }

    $eintraege = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS));
    foreach ($eintraege as $eintrag) {
        if (!$eintrag->isFile()) {
            continue;
        }
        $pfad = str_replace('\\', '/', substr($eintrag->getPathname(), strlen($wurzel) + 1));

        $versteckt = false;
        foreach (explode('/', $pfad) as $teil) {
            $versteckt = $versteckt || str_starts_with($teil, '.');
        }
        $endung = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
        $angenommen = $pfad === ($freigaben['categoryGroups'] ?? null)
            || (str_starts_with($pfad, ($freigaben['package'] ?? 'oserp-portal').'/') && in_array($endung, $endungen, true))
            || (str_starts_with($pfad, ($freigaben['contentDir'] ?? '').'/') && 'md' === $endung);

        if ($versteckt || !$angenommen) {
            $ergebnis['uebersprungen'][] = $pfad;
            continue;
        }
        $ergebnis['dateien'][$pfad] = hash_file('sha256', $eintrag->getPathname());
    }
    ksort($ergebnis['dateien']);
    sort($ergebnis['uebersprungen']);

    return $ergebnis;
}

/** Stand der letzten Übertragung eines HugoShops: Fingerabdruck und Zeitpunkt */
function shopHugoCmsStateFile($db, int $kanal): string {
    return shopSiteStateFile($db, $kanal, '-hugocms.json');
}

/**
 * Überträgt die Bereitstellung an HugoCMS
 *
 * Hat sich seit der letzten erfolgreichen Übertragung nichts geändert, bleibt
 * es bei einer Abfrage des Baustands — höchstens einen Tag lang, dann wird
 * trotzdem abgeglichen, falls HugoCMS inzwischen etwas verloren hat.
 *
 * $vollstaendig überspringt diese Abkürzung („Alle Produkte“): HugoCMS
 * vergleicht dann jede Datei mit dem, was auf seiner Seite liegt, und fordert
 * alles an, was dort fehlt oder abweicht — auch von Hand gelöschte oder
 * geänderte Produktseiten. Was dort gleich ist, nimmt HugoCMS nicht noch
 * einmal an (nur angeforderte Dateien, ShopSync::upload).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @param bool $vollstaendig jede Datei abgleichen, auch ohne Änderung seit dem letzten Mal
 * Die PHP-Einstiegspunkte des Pakets gehen signiert mit, sobald HugoCMS sie
 * annimmt (signedPhp.ready) und diese Installation einen Signaturschlüssel
 * hat (dev/shop-php-signatur.md). Sonst stehen sie unter uebersprungen.
 *
 * @return array ok, uebertragen, written, deleted, unchanged, buildPending, uebersprungen, fehler,
 *               php (hugocms_bereit, schluessel, gesendet)
 */
function shopHugoCmsSync($db, int $kanal, bool $vollstaendig = false): array {
    $ergebnis = ['ok' => false, 'uebertragen' => false, 'written' => 0, 'deleted' => 0, 'unchanged' => 0,
                 'buildPending' => false, 'uebersprungen' => [], 'freigaben' => [], 'fehler' => '',
                 'php' => ['hugocms_bereit' => false, 'schluessel' => '' !== shopSigningSecretKey(), 'gesendet' => []],
                 'medien' => ['angenommen' => false, 'anzahl' => ['images' => 0, 'thumbnails' => 0, 'downloads' => 0],
                              'fehlend' => [], 'uebertragen' => 0, 'vorschau_neu' => 0, 'vorschau_fehler' => []]];

    $stand = shopHugoCmsCall($db, $kanal, 'shopbuildstatus');
    if (!$stand['ok']) {
        $ergebnis['fehler'] = $stand['fehler'];
        return $ergebnis;
    }
    $ergebnis['buildPending'] = !empty($stand['data']['buildPending']);

    // Dieselbe Antwort nennt die Freigaben — abgeglichen wird nach dem, was
    // HugoCMS jetzt annimmt
    $freigaben = shopHugoCmsGrantsFrom($stand);
    if ('' !== $freigaben['fehler']) {
        $ergebnis['fehler'] = $freigaben['fehler'];
        return $ergebnis;
    }
    $ergebnis['freigaben'] = $freigaben['freigaben'];

    $wurzel = shopStagingDir($db, $kanal);
    $liste = shopHugoCmsManifest($wurzel, $ergebnis['freigaben'], (array)($stand['data']['accept'] ?? []));

    // Signierte PHP-Dateien: nur die Pfade, die HugoCMS selbst nennt
    $signiert = (array)($stand['data']['signedPhp'] ?? []);
    $ergebnis['php']['hugocms_bereit'] = !empty($signiert['ready']);
    if ($ergebnis['php']['hugocms_bereit'] && $ergebnis['php']['schluessel']) {
        foreach ((array)($signiert['paths'] ?? []) as $pfad) {
            if (in_array($pfad, $liste['uebersprungen'], true) && is_file($wurzel.'/'.$pfad)) {
                $liste['dateien'][$pfad] = hash_file('sha256', $wurzel.'/'.$pfad);
                $liste['uebersprungen'] = array_values(array_diff($liste['uebersprungen'], [$pfad]));
                $ergebnis['php']['gesendet'][] = $pfad;
            }
        }
        ksort($liste['dateien']);
    }
    $ergebnis['uebersprungen'] = $liste['uebersprungen'];

    // Medien aus der Bibliothek: in die Freigaben, mit den Prüfsummen aus
    // media_shop — keine Kopie in der Bereitstellung
    $medien = shopHugoCmsMediaList($db, $ergebnis['freigaben']);
    $ergebnis['medien'] = [
        'angenommen'   => !empty($ergebnis['freigaben']['medien']),
        'anzahl'       => $medien['anzahl'],
        'fehlend'      => $medien['fehlend'],
        'uebertragen'  => 0,
        'vorschau_neu' => $medien['vorschau_neu'],
        'vorschau_fehler' => $medien['vorschau_fehler'],
    ];
    foreach ($medien['dateien'] as $pfad => $datei) {
        $liste['dateien'][$pfad] = $datei['sha256'];
    }

    $fingerabdruck = hash('sha256', json_encode($liste['dateien']).'|'.shopSiteValue($db, $kanal, 'hugocms_url'));
    $zustand = shopPublishReadState(shopHugoCmsStateFile($db, $kanal));
    $zuletzt = strtotime((string)($zustand['syncedAt'] ?? '')) ?: 0;
    if (!$vollstaendig
        && ($zustand['fingerprint'] ?? '') === $fingerabdruck && time() - $zuletzt < SHOP_HUGOCMS_RESYNC_SECONDS) {
        $ergebnis['ok'] = true;
        $ergebnis['unchanged'] = count($liste['dateien']);
        return $ergebnis;
    }

    // Abgleich, Übertragung in Portionen und Übernahme erledigt der Kern
    // (portalHugoCmsTransfer). Medien gehen in Stücken je Datei, nicht Base64
    // in der Portion — das übernimmt der Shop selbst ($extern).
    $eintraege = [];
    foreach ($liste['dateien'] as $pfad => $pruefsumme) {
        if (isset($medien['dateien'][$pfad])) {
            // Medien tragen ihre Größe: HugoCMS misst die Stücke daran
            $eintraege[$pfad] = ['sha256' => $pruefsumme, 'size' => $medien['dateien'][$pfad]['size']];
            continue;
        }
        $eintraege[$pfad] = in_array($pfad, $ergebnis['php']['gesendet'], true)
            ? ['sha256' => $pruefsumme, 'signature' => shopSigningSign($pfad, $pruefsumme)]
            : ['sha256' => $pruefsumme];
    }
    $stueckGroesse = (int)$ergebnis['freigaben']['medien_stueck'] > 0
        ? max(65536, min(SHOP_HUGOCMS_MEDIA_CHUNK_BYTES, (int)$ergebnis['freigaben']['medien_stueck']))
        : SHOP_HUGOCMS_MEDIA_CHUNK_BYTES;

    $uebertragung = portalHugoCmsTransfer(
        $db, shopWebsiteOfChannel($db, $kanal), $eintraege,
        fn(string $pfad) => (string)@file_get_contents($wurzel.'/'.$pfad),
        function (string $syncId, string $pfad) use ($db, $kanal, $medien, $stueckGroesse): ?string {
            if (!isset($medien['dateien'][$pfad])) {
                return null;
            }
            return shopHugoCmsMediaUpload($db, $kanal, $syncId, $pfad, $medien['dateien'][$pfad], $stueckGroesse);
        }
    );
    $ergebnis['medien']['uebertragen'] = $uebertragung['extern'];
    if (!$uebertragung['ok']) {
        $ergebnis['fehler'] = $uebertragung['fehler'];
        return $ergebnis;
    }

    shopPublishWriteState(shopHugoCmsStateFile($db, $kanal), [
        'fingerprint' => $fingerabdruck,
        'syncedAt'    => date(DATE_ATOM),
    ], true);

    return array_merge($ergebnis, [
        'ok'           => true,
        'uebertragen'  => true,
        'written'      => $uebertragung['written'],
        'deleted'      => $uebertragung['deleted'],
        'unchanged'    => $uebertragung['unchanged'],
        'buildPending' => $uebertragung['buildPending'] || $ergebnis['buildPending'],
    ]);
}

// ── Medien aus der Bibliothek (dev/shop-medienbibliothek.md) ──
//
// Produktbilder, Vorschaubilder und Downloads liegen in der Medienbibliothek
// (backend/data/<db>/shop/media/) und gehen mit der Lieferung an HugoCMS: in
// die Freigaben „Produktbilder“, „Vorschaubilder“ und „Downloads“, als
// Binärdaten in Stücken (shopmediaupload), nicht über die Bereitstellung —
// dort läge sonst alles doppelt. Die Prüfsummen stammen aus media_shop; was
// HugoCMS schon hat, fordert es nicht an. Jede Shop-Webseite bekommt alle
// Medien (B7). Nimmt HugoCMS noch keine Medien an (Version vor 2026-10-15),
// bleibt es beim alten Verfahren: Vorschaubilder dort, Downloads nur geprüft.

require_once __DIR__.'/../../customer_vendor/filemanager.php';
require_once __DIR__.'/media.php';

/**
 * Medien der Bibliothek als Einträge der Lieferung
 *
 * Vorher werden Vorschaubilder erneuert, die fehlen oder nicht die
 * eingestellte Größe haben (shopMediaThumbnailsRefresh).
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $freigaben aus shopHugoCmsGrantsFrom (images, thumbnails, downloads, medien)
 * @return array dateien (Pfad in der Webseite => sha256, size, quelle),
 *               fehlend (Einträge ohne Datei, „bereich/name“),
 *               anzahl (images, thumbnails, downloads), vorschau_neu,
 *               vorschau_fehler (Dateiname => Grund)
 */
function shopHugoCmsMediaList($db, array $freigaben): array {
    $ergebnis = ['dateien' => [], 'fehlend' => [], 'anzahl' => ['images' => 0, 'thumbnails' => 0, 'downloads' => 0],
                 'vorschau_neu' => 0, 'vorschau_fehler' => []];
    if (empty($freigaben['medien'])) {
        return $ergebnis;
    }
    $vorschau = shopMediaThumbnailsRefresh($db);
    $ergebnis['vorschau_neu'] = $vorschau['erzeugt'];
    $ergebnis['vorschau_fehler'] = $vorschau['fehlgeschlagen'];

    $verzeichnisse = [];
    foreach (array_keys($ergebnis['anzahl']) as $bereich) {
        $verzeichnisse[$bereich] = shopMediaDir($db, $bereich);
    }
    $zeilen = $db->getAll("SELECT kind, filename, sha256, size, thumbnail_sha256 FROM media_shop ORDER BY kind, filename") ?: [];
    foreach ($zeilen as $zeile) {
        $ziele = 'image' === $zeile['kind']
            ? [['images', $zeile['sha256'], (int)$zeile['size']], ['thumbnails', $zeile['thumbnail_sha256'], null]]
            : [['downloads', $zeile['sha256'], (int)$zeile['size']]];
        foreach ($ziele as [$bereich, $pruefsumme, $groesse]) {
            $freigabe = (string)($freigaben[$bereich] ?? '');
            if ('' === $freigabe || '' === (string)$pruefsumme) {
                // Downloads ohne Freigabe in HugoCMS; Bild ohne Vorschaubild
                continue;
            }
            $quelle = $verzeichnisse[$bereich].'/'.$zeile['filename'];
            if (!is_file($quelle)) {
                $ergebnis['fehlend'][] = $bereich.'/'.$zeile['filename'];
                continue;
            }
            $groesse ??= (int)filesize($quelle);
            if ($groesse <= 0) {
                continue;
            }
            $ergebnis['dateien'][$freigabe.'/'.$zeile['filename']] = ['sha256' => $pruefsumme, 'size' => $groesse, 'quelle' => $quelle];
            $ergebnis['anzahl'][$bereich]++;
        }
    }
    ksort($ergebnis['dateien']);
    return $ergebnis;
}

/**
 * Überträgt eine Mediendatei in Stücken an HugoCMS
 *
 * Jedes Stück nennt seinen Versatz; HugoCMS sammelt und legt die Datei mit
 * dem letzten Stück nach Prüfung der Prüfsumme in ihre Freigabe. Passt der
 * Versatz dort nicht (Rest einer abgebrochenen Übertragung), beginnt die
 * Datei einmal von vorn.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @param string $syncId Lieferung aus dem Abgleich
 * @param string $pfad Pfad in der Webseite
 * @param array $datei sha256, size, quelle (aus shopHugoCmsMediaList)
 * @param int $stueckGroesse Bytes je Stück
 * @return string Fehler, leer bei Erfolg
 */
function shopHugoCmsMediaUpload($db, int $kanal, string $syncId, string $pfad, array $datei, int $stueckGroesse): string {
    $handle = @fopen($datei['quelle'], 'rb');
    if (false === $handle) {
        return 'Datei der Bibliothek nicht lesbar: '.$datei['quelle'];
    }
    $groesse = (int)$datei['size'];
    $versatz = 0;
    $neustart = false;
    try {
        while ($versatz < $groesse) {
            $inhalt = stream_get_contents($handle, min($stueckGroesse, $groesse - $versatz), $versatz);
            if (false === $inhalt || '' === $inhalt) {
                return 'Datei kürzer als in der Bibliothek verzeichnet — in der Ansicht „Medien“ abgleichen: '.$pfad;
            }
            $antwort = shopHugoCmsCall($db, $kanal, 'shopmediaupload', 'POST', 300, [],
                ['syncId' => $syncId, 'path' => $pfad, 'offset' => $versatz], ['feld' => 'chunk', 'inhalt' => $inhalt]);
            if (!$antwort['ok']) {
                if (!$neustart && 409 === $antwort['status'] && $versatz > 0) {
                    $neustart = true;
                    $versatz = 0;
                    continue;
                }
                return $antwort['fehler'].' ('.$pfad.')';
            }
            $versatz += strlen($inhalt);
            if (!empty($antwort['data']['complete'])) {
                return $versatz >= $groesse ? '' : 'HugoCMS meldet die Datei vor ihrem Ende als vollständig: '.$pfad;
            }
            if ((int)($antwort['data']['received'] ?? -1) !== $versatz) {
                return sprintf('HugoCMS meldet einen anderen Stand (%d statt %d Bytes): %s',
                    (int)($antwort['data']['received'] ?? -1), $versatz, $pfad);
            }
        }
        return 'HugoCMS hat die Datei nicht als vollständig gemeldet: '.$pfad;
    } finally {
        fclose($handle);
    }
}

/**
 * Dateien, die Artikel im Shop nennen, aber in der Bibliothek fehlen
 *
 * Sie erscheinen nicht auf der Webseite — ein Hinweis im Lauf, wie früher
 * „fehlen auf dem Webserver“. Alle Bilder eines Artikels, nicht nur das erste.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return array image (Namen), download (Namen)
 */
function shopMediaMissingInLibrary($db, int $kanal): array {
    $ergebnis = ['image' => [], 'download' => []];
    $zeilen = $db->getAll(
        "WITH kanal AS (SELECT shop_active_channel_id(CAST(:kanal AS integer)) AS id),
              angeboten AS (
                  SELECT pe.hugoshop_images, pe.hugoshop_downloads
                    FROM parts p
                    JOIN parts_channel_shop pc ON pc.parts_id = p.id AND pc.active
                    JOIN kanal k ON k.id = pc.channel_id
                    JOIN parts_ext pe ON pe.parts_id = p.id
              ),
              genannt AS (
                  SELECT DISTINCT 'image' AS kind, regexp_replace(e, '^.*/', '') AS name
                    FROM angeboten a CROSS JOIN LATERAL jsonb_array_elements_text(a.hugoshop_images) e
                   WHERE jsonb_typeof(a.hugoshop_images) = 'array' AND btrim(e) <> ''
                  UNION
                  SELECT DISTINCT 'download', regexp_replace(d.value, '^.*/', '')
                    FROM angeboten a CROSS JOIN LATERAL jsonb_each_text(a.hugoshop_downloads) d
                   WHERE jsonb_typeof(a.hugoshop_downloads) = 'object' AND btrim(d.value) <> ''
              )
         SELECT g.kind, g.name
           FROM genannt g
           LEFT JOIN media_shop m ON m.kind = g.kind AND m.filename = g.name
          WHERE m.id IS NULL
          ORDER BY 1, 2",
        [':kanal' => $kanal]
    ) ?: [];
    foreach ($zeilen as $zeile) {
        $ergebnis[$zeile['kind']][] = $zeile['name'];
    }
    return $ergebnis;
}

/**
 * Meldungen zu den Medien nach einer Übertragung
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @param array $abgleich Ergebnis von shopHugoCmsSync
 * @param callable $sagen Meldung
 * @param callable $liste kürzt eine Namensliste für die Ausgabe
 */
function shopHugoCmsMediaReport($db, int $kanal, array $abgleich, callable $sagen, callable $liste): void {
    $medien = $abgleich['medien'];
    $sagen(sprintf('Medien an HugoCMS: %d Produktbilder, %d Vorschaubilder, %d Downloads in der Lieferung, %d übertragen',
        $medien['anzahl']['images'], $medien['anzahl']['thumbnails'], $medien['anzahl']['downloads'], $medien['uebertragen']));
    if ($medien['vorschau_neu'] > 0) {
        $sagen(sprintf('%d Vorschaubilder neu erzeugt (Größe %d)', $medien['vorschau_neu'], shopConfigInt($db, 'shop_thumbnail_size', 200)));
    }
    if ($medien['vorschau_fehler']) {
        // Nach Grund gebündelt: auf einem Server ohne WebP-Unterstützung
        // wäre es sonst eine Zeile je Bild
        $nachGrund = [];
        foreach ($medien['vorschau_fehler'] as $name => $grund) {
            $nachGrund[$grund][] = $name;
        }
        foreach ($nachGrund as $grund => $namen) {
            $sagen(sprintf('Warnung: %d Vorschaubilder nicht erzeugt — %s: %s', count($namen), $grund, $liste($namen)));
        }
    }
    if ($medien['fehlend']) {
        $sagen(sprintf('Warnung: %d Einträge der Medienbibliothek ohne Datei — in der Ansicht „Medien“ abgleichen: %s',
            count($medien['fehlend']), $liste($medien['fehlend'])));
    }
    if ('' === (string)($abgleich['freigaben']['downloads'] ?? '')) {
        $sagen('Downloads nicht übertragen: HugoCMS gibt kein Verzeichnis „Downloads“ frei (Projekteinstellungen → Shop-Erweiterung).');
    }
    $fehlt = shopMediaMissingInLibrary($db, $kanal);
    if ($fehlt['image']) {
        $sagen(sprintf('%d Produktbilder fehlen in der Medienbibliothek: %s', count($fehlt['image']), $liste($fehlt['image'])));
    }
    if ($fehlt['download']) {
        $sagen(sprintf('%d Download-Dateien fehlen in der Medienbibliothek: %s', count($fehlt['download']), $liste($fehlt['download'])));
    }
}

// ── Download-Dateien (HugoCMS ohne Medienübernahme) ──
//
// Solange HugoCMS keine Medien aus der Bibliothek annimmt, liegen Datenblätter
// und Anleitungen wie die Bilder auf dem Webserver (E6); die Produktseiten
// verlinken sie über downloads_link. Ob die Datei da ist, sieht nur HugoCMS:
// shopdownloads nennt, was in der Freigabe „Downloads“ fehlt — sonst fände
// erst ein Kunde den toten Link.

/**
 * Dateinamen der Downloads aller im Shop angebotenen Artikel
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return array Dateinamen, ohne doppelte
 */
function shopDownloadSources($db, int $kanal): array {
    $zeilen = $db->getAll(
        "SELECT DISTINCT regexp_replace(d.value, '^.*/', '') AS name
           FROM parts p
           JOIN parts_channel_shop pc ON pc.parts_id = p.id
                                     AND pc.channel_id = shop_active_channel_id(CAST(:kanal AS integer))
                                     AND pc.active
           JOIN parts_ext pe ON pe.parts_id = p.id
           CROSS JOIN LATERAL jsonb_each_text(pe.hugoshop_downloads) d
          WHERE jsonb_typeof(pe.hugoshop_downloads) = 'object'
            AND btrim(d.value) <> ''
          ORDER BY 1",
        [':kanal' => $kanal]
    );
    return array_values(array_filter(array_column($zeilen ?: [], 'name'), fn($name) => '' !== (string)$name));
}

/**
 * Lässt HugoCMS prüfen, welche Download-Dateien auf der Webseite fehlen
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return array ok, total, missing, invalid, fehler
 */
function shopHugoCmsDownloadsCheck($db, int $kanal): array {
    $ergebnis = ['ok' => false, 'total' => 0, 'missing' => [], 'invalid' => [], 'fehler' => ''];
    $namen = shopDownloadSources($db, $kanal);
    if (!$namen) {
        $ergebnis['ok'] = true;
        return $ergebnis;
    }
    $antwort = shopHugoCmsCall($db, $kanal, 'shopdownloads', 'POST', 120, [], ['names' => $namen]);
    if (!$antwort['ok']) {
        $ergebnis['fehler'] = $antwort['fehler'];
        return $ergebnis;
    }
    return [
        'ok'      => true,
        'total'   => (int)($antwort['data']['total'] ?? count($namen)),
        'missing' => array_values((array)($antwort['data']['missing'] ?? [])),
        'invalid' => array_values((array)($antwort['data']['invalid'] ?? [])),
        'fehler'  => '',
    ];
}

// ── Vorschaubilder (HugoCMS ohne Medienübernahme) ──
//
// Nimmt HugoCMS keine Medien aus der Bibliothek an, liegen die Produktbilder
// auf dem Webserver (E6). OSERP nennt dann nur die Namen und die Größe;
// verkleinert wird in HugoCMS (backend/core/Shop/ShopThumbnails.php).

/** Höchstzahl Abschnitte je Lauf — Schutz gegen eine Antwort, die nie „fertig" meldet */
const SHOP_HUGOCMS_THUMBNAIL_ROUNDS = 500;

/**
 * Namen der Bilder, zu denen es ein Vorschaubild gibt
 *
 * Das erste Bild jedes Artikels im Shop, ohne Verzeichnis.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return array Dateinamen, ohne doppelte
 */
function shopThumbnailSources($db, int $kanal): array {
    $zeilen = $db->getAll(
        "SELECT DISTINCT regexp_replace(pe.hugoshop_images ->> 0, '^.*/', '') AS name
           FROM parts p
           JOIN parts_channel_shop pc ON pc.parts_id = p.id
                                     AND pc.channel_id = shop_active_channel_id(CAST(:kanal AS integer))
                                     AND pc.active
           JOIN parts_ext pe ON pe.parts_id = p.id
          WHERE jsonb_typeof(pe.hugoshop_images) = 'array'
            AND COALESCE(pe.hugoshop_images ->> 0, '') <> ''
          ORDER BY 1",
        [':kanal' => $kanal]
    );
    return array_values(array_filter(array_column($zeilen ?: [], 'name'), fn($name) => '' !== (string)$name));
}

/**
 * Lässt HugoCMS die Vorschaubilder erzeugen
 *
 * HugoCMS arbeitet je Aufruf nur eine begrenzte Zeit und nennt, wo es
 * weitergeht; hier wird aufgerufen, bis alles bearbeitet ist.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return array ok, created, current, missing, failed, buildPending, fehler
 */
function shopHugoCmsThumbnails($db, int $kanal): array {
    $ergebnis = ['ok' => false, 'created' => 0, 'current' => 0, 'missing' => [], 'failed' => [],
                 'buildPending' => false, 'fehler' => ''];

    $namen = shopThumbnailSources($db, $kanal);
    if (!$namen) {
        $ergebnis['ok'] = true;
        return $ergebnis;
    }

    $groesse = shopConfigInt($db, 'shop_thumbnail_size', 200);
    $weiter = 0;
    for ($runde = 0; $runde < SHOP_HUGOCMS_THUMBNAIL_ROUNDS; $runde++) {
        $antwort = shopHugoCmsCall($db, $kanal, 'shopthumbnails', 'POST', 120, [],
            ['names' => $namen, 'size' => $groesse, 'offset' => $weiter]);
        if (!$antwort['ok']) {
            $ergebnis['fehler'] = $antwort['fehler'];
            return $ergebnis;
        }
        $teil = (array)$antwort['data'];
        $ergebnis['created'] += (int)($teil['created'] ?? 0);
        $ergebnis['current'] += (int)($teil['current'] ?? 0);
        $ergebnis['missing'] = array_merge($ergebnis['missing'], (array)($teil['missing'] ?? []));
        $ergebnis['failed'] = array_merge($ergebnis['failed'], (array)($teil['failed'] ?? []));
        $ergebnis['buildPending'] = !empty($teil['buildPending']);

        if (!empty($teil['done'])) {
            $ergebnis['ok'] = true;
            return $ergebnis;
        }
        $naechster = (int)($teil['next'] ?? 0);
        if ($naechster <= $weiter) {
            $ergebnis['fehler'] = 'HugoCMS kommt bei den Vorschaubildern nicht voran (bei '.$weiter.' von '.count($namen).')';
            return $ergebnis;
        }
        $weiter = $naechster;
    }

    $ergebnis['fehler'] = 'Zu viele Abschnitte bei den Vorschaubildern';
    return $ergebnis;
}

/**
 * Überträgt an HugoCMS und lässt dort bauen — der Teil des Laufs, der die
 * Webseite eines HugoShops veröffentlicht
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @param callable $sagen Meldung
 * @param callable $fehler Fehlermeldung (zählt mit)
 * @param bool $bauen nach der Übertragung bauen lassen
 * @param bool $erzwingen jede Datei abgleichen und bauen, auch wenn HugoCMS
 *                        nichts Neues hat („Alle Produkte“, „Shop-Benutzerschnittstelle
 *                        installieren“)
 * @return bool gebaut
 */
function shopHugoCmsPublish($db, int $kanal, callable $sagen, callable $fehler, bool $bauen, bool $erzwingen = false): bool {
    $abgleich = shopHugoCmsSync($db, $kanal, $erzwingen);

    // PHP-Einstiegspunkte des Pakets (Weiterleiter, 404-Seite): signiert
    // übertragen (dev/shop-php-signatur.md) oder von Hand abgelegt (E9). Ob sie
    // auf der Webseite liegen und aktuell sind, sagt die Prüfung über die
    // Webseite — erst am Ende, nach dem Bau, wenn eine neue Fassung in
    // public/ angekommen ist. Geprüft wird nur nach einer Übertragung, sonst
    // kostete jeder Lauf zwei Aufrufe der Webseite; gemeldet wird nur, was
    // nicht stimmt. Alles andere, was HugoCMS ablehnt, wäre ein Befund.
    $vonHand = array_values(array_filter($abgleich['uebersprungen'],
        fn($pfad) => in_array($pfad, SHOP_HUGOCMS_MANUAL_FILES, true)));
    $pruefen = $abgleich['uebertragen'] && ($vonHand || $abgleich['php']['gesendet']);
    $abschluss = function (bool $gebaut) use ($db, $kanal, $sagen, $fehler, $pruefen, $vonHand, $abgleich): bool {
        if (!$pruefen) {
            return $gebaut;
        }
        $mangel = false;
        foreach (shopWebsiteManualFilesCheck($db, $kanal, $abgleich['php']['gesendet']) as $pruefung) {
            if ('' === $pruefung['text']) {
                continue;
            }
            $mangel = true;
            // Ohne erreichbaren Weiterleiter gibt es keinen Warenkorb, keine
            // Kasse, kein Konto: ein Fehler, der Lauf gilt als gescheitert.
            // Die 404-Seite (Umleitungen) und eine veraltete Kopie bleiben Hinweise.
            $schwer = str_ends_with($pruefung['pfad'], 'portal-api/index.php')
                && in_array($pruefung['stand'], ['fehlt', 'kein_php', 'falsches_ziel', 'nicht_eingerichtet'], true);
            $schwer ? $fehler($pruefung['text']) : $sagen($pruefung['text']);
        }
        // Hinweis, wie der Lauf die Dateien künftig selbst überträgt
        if ($mangel && $vonHand) {
            $sagen($abgleich['php']['schluessel']
                ? 'Diese Dateien überträgt der Lauf selbst, sobald in HugoCMS (Projekteinstellungen → Shop-Erweiterung) '
                  .'der Signaturschlüssel von OpensourceERP hinterlegt ist: '.shopSigningPublicKey()
                : 'Diese Dateien kann der Lauf selbst übertragen: in OpensourceERP unter Systemeinstellungen '
                  .'(Shop-Erweiterung: Signaturschlüssel) ein Schlüsselpaar erzeugen und den öffentlichen Schlüssel '
                  .'in HugoCMS (Projekteinstellungen → Shop-Erweiterung) eintragen.');
        }
        return $gebaut;
    };
    if ($abgleich['php']['gesendet'] && $abgleich['uebertragen']) {
        $sagen('Signiert an HugoCMS geliefert (neu oder unverändert): '.implode(', ', $abgleich['php']['gesendet']));
    }

    // HugoCMS nimmt signiertes PHP an, dieser Lauf kann aber nicht signieren:
    // Weiterleiter und 404-Seite bleiben dann auf dem alten Stand (HugoCMS
    // löscht sie nicht, ersetzt sie aber auch nicht). Typisch, wenn Cron und
    // Webserver unter verschiedenen Benutzern laufen und die Schlüsseldatei
    // (Rechte 0600) dem anderen gehört. Nur nach einer Übertragung, wie die
    // übrigen Hinweise — der tägliche Abgleich bringt ihn mindestens einmal am Tag.
    if ($abgleich['uebertragen'] && $abgleich['php']['hugocms_bereit'] && !$abgleich['php']['schluessel']) {
        $sagen(shopSigningUnreadableText());
    }

    if ($abgleich['uebertragen'] && $abgleich['uebersprungen']) {
        $sonst = array_values(array_diff($abgleich['uebersprungen'], $vonHand));
        // Produktseiten und Kategorieübersicht, die HugoCMS nicht annimmt:
        // sie erscheinen nie auf der Webseite — ein Fehler, kein Hinweis. Die
        // Bereitstellung folgt den Freigaben zu Beginn des Laufs
        // (shopStagingFollowGrants); bleibt hier etwas übrig, hat sich eine
        // Freigabe in HugoCMS während des Laufs geändert.
        $inhalt = array_values(array_filter($sonst, fn($pfad) => !str_starts_with($pfad, 'oserp-portal/')));
        $sonst = array_values(array_diff($sonst, $inhalt));
        if ($inhalt) {
            $freigaben = $abgleich['freigaben'];
            $fehler(sprintf('%d Inhaltsdateien von HugoCMS nicht angenommen, sie erscheinen nicht auf der Webseite: %s. '
                .'HugoCMS gibt der Shop-Anbindung frei: Produktseiten %s, Kategorieübersicht %s '
                .'(Projekteinstellungen → Shop-Erweiterung). Der nächste Lauf legt die Dateien dorthin.',
                count($inhalt), implode(', ', array_slice($inhalt, 0, 5)).(count($inhalt) > 5 ? ' …' : ''),
                ($freigaben['contentDir'] ?? '—').'/', $freigaben['categoryGroups'] ?? '—'));
        }
        if ($sonst) {
            $sagen(sprintf('%d Dateien nicht an HugoCMS übertragen — dort nicht angenommen: %s',
                count($sonst), implode(', ', array_slice($sonst, 0, 5)).(count($sonst) > 5 ? ' …' : '')));
        }
    }
    if (!$abgleich['ok']) {
        $fehler('Übertragung an HugoCMS fehlgeschlagen: '.$abgleich['fehler']);
        return false;
    }
    $sagen($abgleich['uebertragen']
        ? sprintf('An HugoCMS übertragen: %d geschrieben, %d entfernt, %d unverändert',
            $abgleich['written'], $abgleich['deleted'], $abgleich['unchanged'])
        : 'HugoCMS ist auf dem aktuellen Stand — nichts zu übertragen.');

    // Medien nur nach einer Übertragung melden — sonst fragte jeder Lauf alle
    // Namen ab. Die Übertragung findet mindestens einmal am Tag statt
    // (SHOP_HUGOCMS_RESYNC_SECONDS).
    $bauNoetig = $abgleich['buildPending'];
    $liste = fn(array $namen) => implode(', ', array_slice($namen, 0, 5)).(count($namen) > 5 ? ' …' : '');
    if ($abgleich['uebertragen'] && $abgleich['medien']['angenommen']) {
        // Medien aus der Bibliothek sind mit der Lieferung gegangen
        // (dev/shop-medienbibliothek.md): Vorschaubilder kommen fertig von
        // hier, fehlende Dateien kennt die Bibliothek selbst
        shopHugoCmsMediaReport($db, $kanal, $abgleich, $sagen, $liste);
    } elseif ($abgleich['uebertragen']) {
        // HugoCMS ohne Medienübernahme: Vorschaubilder dort erzeugen, Downloads
        // nur prüfen — die Medien bleiben auf der Webseite maßgeblich
        $sagen('HugoCMS nimmt noch keine Medien aus der Medienbibliothek an (Version vor 2026-10-15) — '
            .'Produktbilder und Downloads bleiben auf der Webseite maßgeblich, bis HugoCMS aktualisiert ist.');
        $vorschau = shopHugoCmsThumbnails($db, $kanal);
        if (!$vorschau['ok']) {
            $fehler('Vorschaubilder in HugoCMS fehlgeschlagen: '.$vorschau['fehler']);
        } else {
            $bauNoetig = $bauNoetig || $vorschau['buildPending'];
            $sagen(sprintf('Vorschaubilder in HugoCMS: %d erzeugt, %d aktuell', $vorschau['created'], $vorschau['current']));
        }
        if ($vorschau['missing']) {
            $sagen(sprintf('%d Produktbilder fehlen auf dem Webserver: %s', count($vorschau['missing']), $liste($vorschau['missing'])));
        }
        if ($vorschau['failed']) {
            $fehler(sprintf('%d Vorschaubilder nicht erzeugt (Name ungültig oder Bild nicht lesbar): %s',
                count($vorschau['failed']), $liste($vorschau['failed'])));
        }

        // Download-Dateien: fehlt eine, steht ein toter Link auf der
        // Produktseite — ein Hinweis wie bei fehlenden Produktbildern. Nur,
        // wenn HugoCMS die Freigabe „Downloads“ kennt.
        if ('' !== (string)($abgleich['freigaben']['downloads'] ?? '')) {
            $downloads = shopHugoCmsDownloadsCheck($db, $kanal);
            if (!$downloads['ok']) {
                $sagen('Download-Dateien nicht geprüft: '.$downloads['fehler']);
            } elseif ($downloads['missing'] || $downloads['invalid']) {
                if ($downloads['missing']) {
                    $sagen(sprintf('%d Download-Dateien fehlen auf dem Webserver (HugoCMS, Freigabe „Downloads“: %s/): %s',
                        count($downloads['missing']), $abgleich['freigaben']['downloads'], $liste($downloads['missing'])));
                }
                if ($downloads['invalid']) {
                    $sagen(sprintf('%d Download-Namen unbrauchbar (nur Dateiname, kein Verzeichnis): %s',
                        count($downloads['invalid']), $liste($downloads['invalid'])));
                }
            } elseif ($downloads['total'] > 0) {
                $sagen(sprintf('Download-Dateien auf dem Webserver: alle %d vorhanden', $downloads['total']));
            }
        }
    }

    if (!$bauen || (!$bauNoetig && !$erzwingen)) {
        return $abschluss(false);
    }

    $sagen('HugoCMS baut die Webseite …');
    $bau = shopHugoCmsCall($db, $kanal, 'shopbuild', 'POST', 600);
    if (!$bau['ok']) {
        $fehler('Bau in HugoCMS fehlgeschlagen: '.$bau['fehler']);
        return $abschluss(false);
    }
    if (!empty($bau['data']['paused'])) {
        $sagen('Das Bauen ist in HugoCMS pausiert — der Cron von HugoCMS baut, sobald die Pause endet.');
        return $abschluss(false);
    }
    foreach (array_slice(explode("\n", trim((string)($bau['data']['output'] ?? ''))), -50) as $zeile) {
        if ('' !== trim($zeile)) {
            $sagen('  '.$zeile);
        }
    }
    if (!empty($bau['data']['success'])) {
        $sagen(sprintf('Webseite in HugoCMS gebaut (%s s).', $bau['data']['seconds'] ?? '?'));
        return $abschluss(true);
    }
    $fehler('Der Bau in HugoCMS ist fehlgeschlagen (Rückgabewert '.(int)($bau['data']['exitCode'] ?? -1).').');
    return $abschluss(false);
}
