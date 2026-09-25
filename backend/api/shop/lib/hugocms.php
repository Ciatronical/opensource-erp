<?php
// backend/api/shop/lib/hugocms.php
//
// Anbindung an HugoCMS (dev/shop-hugocms-trennung.md). In der Zielarchitektur
// baut HugoCMS die Webseite; OSERP liefert die Inhaltsdateien und stößt den Bau
// an: Zugang, Übertragung der Dateien, Vorschaubilder und Bau.
//
// Angemeldet wird mit dem Schlüssel, den HugoCMS in den Projekteinstellungen
// der Webseite erzeugt (Authorization: Bearer). Die Adresse ist der
// cms-api-Endpunkt genau dieser Webseite: HugoCMS erkennt die Webseite an Host
// und Endpunkt, ein Schlüssel gilt nur für seine.

/**
 * Prüft die eingestellte Adresse von HugoCMS
 *
 * Nur http und https. Über http geht der Schlüssel im Klartext übers Netz —
 * das ist nur zur Loopback-Adresse erlaubt, wie HugoCMS es auf seiner Seite
 * ebenfalls verlangt. Ein Verzeichnis-Endpunkt bekommt seinen abschließenden
 * Schrägstrich: ohne ihn antwortet der Webserver mit einer Umleitung, und die
 * würde hier nicht verfolgt.
 *
 * @param string $adresse Eingestellte Adresse
 * @return array adresse (bereinigt, leer bei Fehler), fehler (leer, wenn in Ordnung)
 */
function shopHugoCmsUrl(string $adresse): array {
    $adresse = trim($adresse);
    if ('' === $adresse) {
        return ['adresse' => '', 'fehler' => "Die Shop-Einstellung '".shopConfigLabel('shop_hugocms_url')."' ist nicht gesetzt"];
    }

    $teile = parse_url($adresse);
    $schema = strtolower((string)($teile['scheme'] ?? ''));
    $host = strtolower(trim((string)($teile['host'] ?? ''), '[]'));
    if (!in_array($schema, ['http', 'https'], true) || '' === $host) {
        return ['adresse' => '', 'fehler' => 'Keine gültige Adresse (http oder https): '.$adresse];
    }
    // Loopback: localhost, die Adressen selbst und alles unter .localhost
    // (RFC 6761 — solche Namen zeigen immer auf die eigene Maschine)
    $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost');
    if ('http' === $schema && !$loopback) {
        return ['adresse' => '', 'fehler' => 'HugoCMS nur über https ansprechen — über http ginge der Schlüssel unverschlüsselt übers Netz: '.$adresse];
    }

    $pfad = (string)($teile['path'] ?? '');
    if (!str_ends_with($pfad, '/') && !str_ends_with($pfad, '.php')) {
        $adresse = preg_replace('/(\?.*)?$/', '/$1', $adresse, 1);
    }

    return ['adresse' => $adresse, 'fehler' => ''];
}

/**
 * Übersetzt eine Fehlerantwort von HugoCMS
 *
 * HugoCMS schickt nur Codes und Schlüssel, keine Texte (sein Client übersetzt
 * selbst). Die für die Anbindung wichtigen stehen hier auf Deutsch, alles
 * andere bleibt als Code stehen.
 *
 * @param array $fehler error-Teil der Antwort: code, key, params
 * @return string
 */
function shopHugoCmsErrorText(array $fehler): string {
    $schluessel = (string)($fehler['key'] ?? '');
    $code = (string)($fehler['code'] ?? '');
    $bekannt = [
        'SHOP-KEY-NOT-SET'     => 'In HugoCMS ist für diese Webseite kein Schlüssel hinterlegt — in den Projekteinstellungen unter „Shop-Anbindung" erzeugen',
        'SHOP-KEY-INVALID'     => 'HugoCMS lehnt den Schlüssel ab — stimmt er mit dem in den Projekteinstellungen erzeugten überein?',
        'SHOP-HTTPS-REQUIRED'  => 'HugoCMS nimmt die Anbindung nur über https an',
        'METHOD-REQUIRED'      => 'Falsche Aufrufart',
        'HUGO-NOT-CONFIGURED'  => 'In HugoCMS ist für diese Webseite kein Hugo-Projekt eingerichtet',
        'HUGO-BIN-NOT-CONFIGURED' => 'In HugoCMS ist kein Hugo-Programm eingestellt',
        'HUGO-BIN-MISSING'     => 'HugoCMS findet das Hugo-Programm nicht',
        'HUGO-SOURCE-MISSING'  => 'HugoCMS findet das Verzeichnis der Webseite nicht',
        'UNKNOWN-COMMAND'      => 'Diese HugoCMS-Version kennt die Shop-Anbindung noch nicht',
        'SHOP-PATH-NOT-ALLOWED' => 'HugoCMS nimmt diese Datei nicht an, sie liegt außerhalb der freigegebenen Bereiche',
        'SHOP-FILETYPE-NOT-ALLOWED' => 'HugoCMS nimmt diese Dateiart nicht an',
        'SHOP-HASH-MISMATCH'   => 'Eine Datei kam anders an, als angekündigt',
        'SHOP-SYNC-INCOMPLETE' => 'Die Übertragung war unvollständig',
        'SHOP-SYNC-UNKNOWN'    => 'HugoCMS kennt diese Übertragung nicht (mehr)',
        'SHOP-THUMBNAILS-INVALID'  => 'HugoCMS lehnt die Liste der Bildnamen ab',
        'SHOP-THUMBNAILS-TOO-MANY' => 'Zu viele Bilder für einen Aufruf von HugoCMS',
        'SHOP-THUMBNAILS-NO-GD'    => 'Auf dem Webserver fehlt die PHP-Erweiterung GD — HugoCMS kann keine Vorschaubilder erzeugen',
    ];
    if (isset($bekannt[$schluessel])) {
        return $bekannt[$schluessel];
    }
    if ('ESITE' === $code) {
        return 'HugoCMS kennt unter dieser Adresse keine Webseite — ist es der cms-api-Endpunkt der Shop-Webseite?';
    }

    $params = array_filter(array_map('strval', (array)($fehler['params'] ?? [])));
    return 'HugoCMS meldet '.trim($code.' '.$schluessel).($params ? ' ('.implode(', ', $params).')' : '');
}

/**
 * Ruft einen Befehl von HugoCMS auf
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $befehl etwa shopbuildstatus oder shopbuild
 * @param string $methode GET oder POST
 * @param int $zeitgrenze Sekunden bis zur Antwort; ein Bau braucht mehr als eine Abfrage
 * @param array $zugang url und key statt der gespeicherten — für den Verbindungstest
 *                      mit noch nicht gespeicherten Eingaben; leere Werte fallen
 *                      auf die Einstellung zurück
 * @param array $daten weitere Felder im Rumpf eines POST, neben cmd
 * @return array ok, status (HTTP), data (Antwort bei Erfolg), fehler (Text, leer bei Erfolg)
 */
function shopHugoCmsCall($db, string $befehl, string $methode = 'GET', int $zeitgrenze = 20, array $zugang = [], array $daten = []): array {
    $ergebnis = ['ok' => false, 'status' => 0, 'data' => null, 'fehler' => ''];

    $eingabe = fn(string $feld, string $schluessel) => '' !== trim((string)($zugang[$feld] ?? ''))
        ? trim((string)$zugang[$feld])
        : shopConfigValue($db, $schluessel);

    $url = shopHugoCmsUrl($eingabe('url', 'shop_hugocms_url'));
    if ('' !== $url['fehler']) {
        $ergebnis['fehler'] = $url['fehler'];
        return $ergebnis;
    }
    $schluessel = $eingabe('key', 'shop_hugocms_key');
    if ('' === $schluessel) {
        $ergebnis['fehler'] = "Die Shop-Einstellung '".shopConfigLabel('shop_hugocms_key')."' ist nicht gesetzt";
        return $ergebnis;
    }

    $ziel = $url['adresse'];
    $optionen = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $zeitgrenze,
        // Keine Umleitungen: der Schlüssel ginge sonst an eine Adresse, die
        // niemand eingestellt hat
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer '.$schluessel,
            'Accept: application/json',
        ],
    ];
    if ('POST' === $methode) {
        $optionen[CURLOPT_POST] = true;
        $optionen[CURLOPT_POSTFIELDS] = json_encode(['cmd' => $befehl] + $daten, JSON_UNESCAPED_SLASHES);
        $optionen[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    } else {
        $ziel .= (str_contains($ziel, '?') ? '&' : '?').'cmd='.rawurlencode($befehl);
    }

    $ch = curl_init($ziel);
    curl_setopt_array($ch, $optionen);
    $antwort = curl_exec($ch);
    $ergebnis['status'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (false === $antwort) {
        $ergebnis['fehler'] = 'HugoCMS nicht erreichbar: '.curl_error($ch);
        return $ergebnis;
    }

    $daten = json_decode((string)$antwort, true);
    if (!is_array($daten) || !array_key_exists('ok', $daten)) {
        $ergebnis['fehler'] = in_array($ergebnis['status'], [301, 302, 307, 308], true)
            ? 'HugoCMS leitet um (HTTP '.$ergebnis['status'].') — stimmt die Adresse genau?'
            : 'Keine gültige Antwort von HugoCMS (HTTP '.$ergebnis['status'].')';
        return $ergebnis;
    }
    if (true !== $daten['ok']) {
        $ergebnis['fehler'] = shopHugoCmsErrorText((array)($daten['error'] ?? []));
        return $ergebnis;
    }

    $ergebnis['ok'] = true;
    $ergebnis['data'] = $daten['data'] ?? null;
    return $ergebnis;
}

// ── Übertragung und Bau (Betriebsart HugoCMS) ──
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

/**
 * PHP-Einstiegspunkte des Pakets: in der Betriebsart HugoCMS einmal von Hand
 * auf den Webserver (E9). Ihre Konfiguration kommt als config.json über die
 * Übertragung.
 */
const SHOP_HUGOCMS_MANUAL_FILES = ['oserp-shop/static/shop-api/index.php', 'oserp-shop/static/not_found.php'];

/**
 * Verzeichnis der Bereitstellung mit Prüfsummen
 *
 * Nur, was HugoCMS annimmt: innerhalb seiner Bereiche und mit erlaubter
 * Endung. Alles andere wird nicht verschickt, sondern gemeldet — vor allem die
 * PHP-Dateien des Pakets, die HugoCMS bewusst nicht schreibt.
 *
 * @param string $wurzel Bereitstellungsverzeichnis
 * @param array $bereiche von HugoCMS: Verzeichnisse mit / am Ende, sonst Dateien
 * @param array $endungen von HugoCMS angenommene Endungen
 * @return array dateien (Pfad => sha256), uebersprungen (Pfade)
 */
function shopHugoCmsManifest(string $wurzel, array $bereiche, array $endungen): array {
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
        $imBereich = false;
        foreach ($bereiche as $bereich) {
            $imBereich = $imBereich || (str_ends_with($bereich, '/') ? str_starts_with($pfad, $bereich) : $pfad === $bereich);
        }
        $endung = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));

        if ($versteckt || !$imBereich || !in_array($endung, $endungen, true)) {
            $ergebnis['uebersprungen'][] = $pfad;
            continue;
        }
        $ergebnis['dateien'][$pfad] = hash_file('sha256', $eintrag->getPathname());
    }
    ksort($ergebnis['dateien']);
    sort($ergebnis['uebersprungen']);

    return $ergebnis;
}

/** Stand der letzten Übertragung: Fingerabdruck und Zeitpunkt */
function shopHugoCmsStateFile($db): string {
    return substr(shopPublishStateFiles($db)['status'], 0, -strlen('.json')).'-hugocms.json';
}

/**
 * Überträgt die Bereitstellung an HugoCMS
 *
 * Hat sich seit der letzten erfolgreichen Übertragung nichts geändert, bleibt
 * es bei einer Abfrage des Baustands — höchstens einen Tag lang, dann wird
 * trotzdem abgeglichen, falls HugoCMS inzwischen etwas verloren hat.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array ok, uebertragen, written, deleted, unchanged, buildPending, uebersprungen, fehler
 */
function shopHugoCmsSync($db): array {
    $ergebnis = ['ok' => false, 'uebertragen' => false, 'written' => 0, 'deleted' => 0, 'unchanged' => 0,
                 'buildPending' => false, 'uebersprungen' => [], 'fehler' => ''];

    $stand = shopHugoCmsCall($db, 'shopbuildstatus');
    if (!$stand['ok']) {
        $ergebnis['fehler'] = $stand['fehler'];
        return $ergebnis;
    }
    $ergebnis['buildPending'] = !empty($stand['data']['buildPending']);

    $wurzel = shopStagingDir($db);
    $liste = shopHugoCmsManifest($wurzel, (array)($stand['data']['areas'] ?? []), (array)($stand['data']['accept'] ?? []));
    $ergebnis['uebersprungen'] = $liste['uebersprungen'];

    $fingerabdruck = hash('sha256', json_encode($liste['dateien']).'|'.shopConfigValue($db, 'shop_hugocms_url'));
    $zustand = shopPublishReadState(shopHugoCmsStateFile($db));
    $zuletzt = strtotime((string)($zustand['syncedAt'] ?? '')) ?: 0;
    if (($zustand['fingerprint'] ?? '') === $fingerabdruck && time() - $zuletzt < SHOP_HUGOCMS_RESYNC_SECONDS) {
        $ergebnis['ok'] = true;
        $ergebnis['unchanged'] = count($liste['dateien']);
        return $ergebnis;
    }

    // 1. Abgleich
    $eintraege = [];
    foreach ($liste['dateien'] as $pfad => $pruefsumme) {
        $eintraege[] = ['path' => $pfad, 'sha256' => $pruefsumme];
    }
    $abgleich = shopHugoCmsCall($db, 'shopmanifest', 'POST', 120, [], ['files' => $eintraege]);
    if (!$abgleich['ok']) {
        $ergebnis['fehler'] = 'Abgleich: '.$abgleich['fehler'];
        return $ergebnis;
    }
    $syncId = (string)($abgleich['data']['syncId'] ?? '');

    // 2. Übertragung in Portionen
    $portion = [];
    $groesse = 0;
    $senden = function () use ($db, $syncId, &$portion, &$groesse): string {
        if (!$portion) {
            return '';
        }
        $antwort = shopHugoCmsCall($db, 'shopupload', 'POST', 120, [], ['syncId' => $syncId, 'files' => $portion]);
        $portion = [];
        $groesse = 0;
        return $antwort['ok'] ? '' : 'Übertragung: '.$antwort['fehler'];
    };
    foreach ((array)($abgleich['data']['needed'] ?? []) as $pfad) {
        $inhalt = (string)@file_get_contents($wurzel.'/'.$pfad);
        if ($portion && ($groesse + strlen($inhalt) > SHOP_HUGOCMS_PORTION_BYTES || count($portion) >= SHOP_HUGOCMS_PORTION_FILES)) {
            if ('' !== ($fehler = $senden())) {
                $ergebnis['fehler'] = $fehler;
                return $ergebnis;
            }
        }
        $portion[] = ['path' => $pfad, 'content' => base64_encode($inhalt)];
        $groesse += strlen($inhalt);
    }
    if ('' !== ($fehler = $senden())) {
        $ergebnis['fehler'] = $fehler;
        return $ergebnis;
    }

    // 3. Übernahme
    $uebernahme = shopHugoCmsCall($db, 'shopcommit', 'POST', 300, [], ['syncId' => $syncId]);
    if (!$uebernahme['ok']) {
        $ergebnis['fehler'] = 'Übernahme: '.$uebernahme['fehler'];
        return $ergebnis;
    }

    shopPublishWriteState(shopHugoCmsStateFile($db), [
        'fingerprint' => $fingerabdruck,
        'syncedAt'    => date(DATE_ATOM),
    ], true);

    return array_merge($ergebnis, [
        'ok'           => true,
        'uebertragen'  => true,
        'written'      => (int)($uebernahme['data']['written'] ?? 0),
        'deleted'      => (int)($uebernahme['data']['deleted'] ?? 0),
        'unchanged'    => (int)($uebernahme['data']['unchanged'] ?? 0),
        'buildPending' => !empty($uebernahme['data']['buildPending']) || $ergebnis['buildPending'],
    ]);
}

// ── Vorschaubilder (Betriebsart HugoCMS) ──
//
// Die Produktbilder liegen auf dem Webserver (E6). OSERP nennt nur die Namen
// und die Größe; verkleinert wird in HugoCMS (backend/core/Shop/ShopThumbnails.php).

/** Höchstzahl Abschnitte je Lauf — Schutz gegen eine Antwort, die nie „fertig" meldet */
const SHOP_HUGOCMS_THUMBNAIL_ROUNDS = 500;

/**
 * Namen der Bilder, zu denen es ein Vorschaubild gibt
 *
 * Wie im lokalen Bau: das erste Bild jedes Artikels im Shop, ohne Verzeichnis.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array Dateinamen, ohne doppelte
 */
function shopThumbnailSources($db): array {
    $zeilen = $db->getAll(
        "SELECT DISTINCT regexp_replace(pe.hugoshop_images ->> 0, '^.*/', '') AS name
           FROM parts p
           JOIN parts_channel_shop pc ON pc.parts_id = p.id
                                     AND pc.channel_id = shop_active_channel_id('hugoshop')
                                     AND pc.active
           JOIN parts_ext pe ON pe.parts_id = p.id
          WHERE jsonb_typeof(pe.hugoshop_images) = 'array'
            AND COALESCE(pe.hugoshop_images ->> 0, '') <> ''
          ORDER BY 1"
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
 * @return array ok, created, current, missing, failed, buildPending, fehler
 */
function shopHugoCmsThumbnails($db): array {
    $ergebnis = ['ok' => false, 'created' => 0, 'current' => 0, 'missing' => [], 'failed' => [],
                 'buildPending' => false, 'fehler' => ''];

    $namen = shopThumbnailSources($db);
    if (!$namen) {
        $ergebnis['ok'] = true;
        return $ergebnis;
    }

    $groesse = shopConfigInt($db, 'shop_thumbnail_size', 200);
    $weiter = 0;
    for ($runde = 0; $runde < SHOP_HUGOCMS_THUMBNAIL_ROUNDS; $runde++) {
        $antwort = shopHugoCmsCall($db, 'shopthumbnails', 'POST', 120, [],
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
 * Überträgt an HugoCMS und lässt dort bauen — der Teil des Laufs, der in der
 * Betriebsart HugoCMS an die Stelle des lokalen Baus tritt
 *
 * @param object $db Company-Datenbankverbindung
 * @param callable $sagen Meldung
 * @param callable $fehler Fehlermeldung (zählt mit)
 * @param bool $bauen nach der Übertragung bauen lassen
 * @param bool $erzwingen auch bauen, wenn HugoCMS nichts Neues hat
 *                        („Shop-Benutzerschnittstelle installieren“)
 * @return bool gebaut
 */
function shopHugoCmsPublish($db, callable $sagen, callable $fehler, bool $bauen, bool $erzwingen = false): bool {
    $abgleich = shopHugoCmsSync($db);

    // Nur melden, wenn wirklich übertragen wurde — sonst stünde dieselbe
    // Zeile in jedem Lauf. Die PHP-Einstiegspunkte des Pakets sind der
    // erwartete Fall (E9): HugoCMS nimmt kein PHP an, man legt sie einmal von
    // Hand ab. Alles andere wäre ein Befund.
    if ($abgleich['uebertragen'] && $abgleich['uebersprungen']) {
        $vonHand = array_values(array_filter($abgleich['uebersprungen'],
            fn($pfad) => in_array($pfad, SHOP_HUGOCMS_MANUAL_FILES, true)));
        $sonst = array_values(array_diff($abgleich['uebersprungen'], $vonHand));
        if ($vonHand) {
            $sagen('Von Hand auf den Webserver legen, falls noch nicht geschehen (HugoCMS nimmt kein PHP an): '
                .implode(', ', $vonHand));
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

    // Vorschaubilder nur nach einer Übertragung — sonst fragte jeder Lauf alle
    // Bilder ab. Die Übertragung findet mindestens einmal am Tag statt
    // (SHOP_HUGOCMS_RESYNC_SECONDS); dann kommen auch Bilder zum Zug, die
    // inzwischen auf dem Webserver abgelegt wurden.
    $bauNoetig = $abgleich['buildPending'];
    if ($abgleich['uebertragen']) {
        $vorschau = shopHugoCmsThumbnails($db);
        $liste = fn(array $namen) => implode(', ', array_slice($namen, 0, 5)).(count($namen) > 5 ? ' …' : '');
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
    }

    if (!$bauen || (!$bauNoetig && !$erzwingen)) {
        return false;
    }

    $sagen('HugoCMS baut die Webseite …');
    $bau = shopHugoCmsCall($db, 'shopbuild', 'POST', 600);
    if (!$bau['ok']) {
        $fehler('Bau in HugoCMS fehlgeschlagen: '.$bau['fehler']);
        return false;
    }
    if (!empty($bau['data']['paused'])) {
        $sagen('Das Bauen ist in HugoCMS pausiert — der Cron von HugoCMS baut, sobald die Pause endet.');
        return false;
    }
    foreach (array_slice(explode("\n", trim((string)($bau['data']['output'] ?? ''))), -50) as $zeile) {
        if ('' !== trim($zeile)) {
            $sagen('  '.$zeile);
        }
    }
    if (!empty($bau['data']['success'])) {
        $sagen(sprintf('Webseite in HugoCMS gebaut (%s s).', $bau['data']['seconds'] ?? '?'));
        return true;
    }
    $fehler('Der Bau in HugoCMS ist fehlgeschlagen (Rückgabewert '.(int)($bau['data']['exitCode'] ?? -1).').');
    return false;
}
