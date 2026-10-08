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
//
// Jeder HugoShop hat seine eigene Webseite und damit eigene Adresse, eigenen
// Schlüssel, eigene Bereitstellung und eigenen Übertragungsstand
// (dev/shop-mehrere-kanaele.md) — die Funktionen bekommen den Kanal.

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
 * @param int $kanal HugoShop, dessen Adresse und Schlüssel gelten
 * @param string $befehl etwa shopbuildstatus oder shopbuild
 * @param string $methode GET oder POST
 * @param int $zeitgrenze Sekunden bis zur Antwort; ein Bau braucht mehr als eine Abfrage
 * @param array $zugang url und key statt der gespeicherten — für den Verbindungstest
 *                      mit noch nicht gespeicherten Eingaben; leere Werte fallen
 *                      auf die Einstellung zurück
 * @param array $daten weitere Felder im Rumpf eines POST, neben cmd
 * @return array ok, status (HTTP), data (Antwort bei Erfolg), fehler (Text, leer bei Erfolg)
 */
function shopHugoCmsCall($db, int $kanal, string $befehl, string $methode = 'GET', int $zeitgrenze = 20, array $zugang = [], array $daten = []): array {
    $ergebnis = ['ok' => false, 'status' => 0, 'data' => null, 'fehler' => ''];

    $eingabe = fn(string $feld, string $schluessel) => '' !== trim((string)($zugang[$feld] ?? ''))
        ? trim((string)$zugang[$feld])
        : shopChannelValue($db, $kanal, $schluessel);

    $url = shopHugoCmsUrl($eingabe('url', 'hugocms_url'));
    if ('' !== $url['fehler']) {
        $ergebnis['fehler'] = $url['fehler'];
        return $ergebnis;
    }
    $schluessel = $eingabe('key', 'hugocms_key');
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

// Signaturschlüssel der Installation: lib/signing.php (dev/shop-php-signatur.md)
require_once __DIR__.'/signing.php';

/**
 * Ruft eine Adresse auf, ohne Umleitungen zu folgen
 *
 * @param string $adresse vollständige Adresse
 * @param array|null $json Rumpf als JSON (dann POST), null = GET
 * @param array $kopfzeilen zusätzliche Kopfzeilen, etwa X-Shop-Key
 * @return array status (0 = nicht erreichbar), kopf (Name klein => Wert), rumpf, fehler
 */
function shopWebsiteProbe(string $adresse, ?array $json = null, array $kopfzeilen = []): array {
    $kopf = [];
    $ch = curl_init($adresse);
    $optionen = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $kopfzeilen,
        CURLOPT_HEADERFUNCTION => function ($ch, string $zeile) use (&$kopf) {
            $doppelpunkt = strpos($zeile, ':');
            if (false !== $doppelpunkt) {
                $kopf[strtolower(trim(substr($zeile, 0, $doppelpunkt)))] = trim(substr($zeile, $doppelpunkt + 1));
            }
            return strlen($zeile);
        },
    ];
    if (null !== $json) {
        $optionen[CURLOPT_POST] = true;
        $optionen[CURLOPT_POSTFIELDS] = json_encode($json);
        $optionen[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $optionen);
    $rumpf = curl_exec($ch);
    return [
        'status' => false === $rumpf ? 0 : (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'kopf'   => $kopf,
        'rumpf'  => false === $rumpf ? '' : (string)$rumpf,
        'fehler' => false === $rumpf ? curl_error($ch) : '',
    ];
}

/**
 * Wer hat auf einen Aufruf von shopPing geantwortet? (testShopBackendUrl)
 *
 * Unterschieden an der Form der Antwort: OpensourceERP antwortet mit
 * success/text/payload, HugoCMS mit ok/error, der Weiterleiter selbst mit
 * success und den Codes SHOP_PROXY_NOT_CONFIGURED bzw.
 * SHOP_BACKEND_UNREACHABLE.
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
        $ergebnis['name'] = (string)($json['payload']['channel_name'] ?? '');
        $ergebnis['code'] = (int)($json['payload']['channel_id'] ?? 0) === $kanal ? 'OK' : 'OTHER_CHANNEL';
    } elseif ('SHOP_NOT_AUTHORIZED' === $text) {
        $ergebnis['code'] = 'KEY_REJECTED';
    } elseif ('SHOP_PROXY_NOT_CONFIGURED' === $text) {
        $ergebnis['code'] = 'PROXY_NOT_CONFIGURED';
    } elseif ('SHOP_BACKEND_UNREACHABLE' === $text) {
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
 * senden ihre Prüfsumme (X-Oserp-Shop-File); verglichen wird mit der Datei im
 * Vorlagensatz des Kanals.
 *
 * - Weiterleiter: Aufruf von <Basisadresse>/shop-api/
 * - 404-Seite: Aufruf einer Adresse, die es nicht gibt — prüft zugleich, ob
 *   der Webserver die 404 an not_found.php gibt
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return array je Datei: pfad, stand (ok, veraltet, fehlt, kein_php,
 *               nicht_eingerichtet, falsches_ziel, ungeprueft), text (leer bei ok)
 */
function shopWebsiteManualFilesCheck($db, int $kanal): array {
    $basis = rtrim(trim(shopChannelValue($db, $kanal, 'base_url')), '/');
    // Ohne lesbaren Vorlagensatz fehlt der Vergleich — dann zählt nur, ob
    // die Datei antwortet; den Satz selbst meldet die Übertragung
    try {
        $paket = shopKitFiles(shopChannelValue($db, $kanal, 'template_set', 'standard'));
    } catch (ApiError $e) {
        $paket = [];
    }
    $handablage = 'Von Hand auf den Webserver legen (HugoCMS nimmt kein PHP an): ';

    $ergebnis = [];
    foreach (SHOP_HUGOCMS_MANUAL_FILES as $pfad) {
        $quelle = $paket[substr($pfad, strlen('oserp-shop/'))] ?? '';
        $soll = '' !== $quelle ? (string)sha1_file($quelle) : '';
        $weiterleiter = str_ends_with($pfad, 'shop-api/index.php');

        if ('' === $basis) {
            $ergebnis[] = ['pfad' => $pfad, 'stand' => 'ungeprueft',
                           'text' => 'Nicht geprüft, der Kanal hat keine Basisadresse. '.$handablage.$pfad];
            continue;
        }

        $adresse = $weiterleiter
            ? $basis.'/shop-api/'
            : $basis.'/oserp-shop-pruefung-'.bin2hex(random_bytes(4));
        // Den Weiterleiter mit shopPing: so zeigt die Antwort auch, wohin er
        // weiterleitet (shopShopApiClassify)
        $antwort = $weiterleiter ? shopWebsiteProbe($adresse, ['action' => 'shopPing']) : shopWebsiteProbe($adresse);
        $ist = (string)($antwort['kopf']['x-oserp-shop-file'] ?? '');
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
                $text = 'Warnung: Der Weiterleiter '.$adresse.' läuft, findet aber oserp-shop/config.json nicht '
                      .'oder unvollständig — Adresse von OpensourceERP und Shop-Schlüssel im Kanal prüfen.';
            } elseif ('HUGOCMS' === $ziel) {
                $stand = 'falsches_ziel';
                $text = 'Warnung: Der Weiterleiter '.$adresse.' erreicht HugoCMS statt OpensourceERP — '
                      .'„Adresse von OpensourceERP für die Webseite“ in der Kanalkarte prüfen (Verbindung prüfen).';
            } elseif (in_array($ziel, ['BACKEND_UNREACHABLE', 'KEY_REJECTED', 'OTHER_CHANNEL', 'FOREIGN', 'NOT_FOUND'], true)) {
                $stand = 'falsches_ziel';
                $text = 'Warnung: Der Weiterleiter '.$adresse.' erreicht den Shop-Zugang von OpensourceERP nicht ('
                      .$ziel.') — in der Kanalkarte „Verbindung prüfen“ unter „Adresse von OpensourceERP für die Webseite“.';
            }
        } elseif ('' !== $ist) {
            $stand = 'veraltet';
            $text = 'Veraltet auf dem Webserver, bitte neu ablegen: '.$pfad.' (aus dem Vorlagensatz, kit/'
                  .substr($pfad, strlen('oserp-shop/')).')';
        } elseif (str_starts_with(ltrim($antwort['rumpf']), '<?php')) {
            $stand = 'kein_php';
            $text = 'Warnung: '.$adresse.' liefert den Quelltext statt ihn auszuführen — '
                  .'PHP ist für die Webseite nicht eingerichtet.';
        } elseif ($weiterleiter && is_array($json) && array_key_exists('success', $json)) {
            // Antwortet wie der Weiterleiter, aber ohne Prüfsumme: Fassung von
            // vor dem 2026-10-08
            $stand = 'veraltet';
            $text = 'Veraltet auf dem Webserver, bitte neu ablegen: '.$pfad.' (aus dem Vorlagensatz, kit/'
                  .substr($pfad, strlen('oserp-shop/')).')';
        } elseif ($weiterleiter) {
            $stand = 'fehlt';
            $text = 'Warnung: Der Weiterleiter '.$adresse.' antwortet nicht (HTTP '.$antwort['status'].') — '
                  .'ohne ihn funktionieren Warenkorb, Kasse und Kundenkonto nicht. '.$handablage.$pfad;
        } else {
            $stand = 'fehlt';
            $text = 'Die 404-Seite mit den Umleitungen antwortet nicht (fehlt, ist veraltet oder der Webserver '
                  .'gibt die 404 nicht an sie weiter, nginx: error_page 404 /not_found.php). '.$handablage.$pfad;
        }

        $ergebnis[] = ['pfad' => $pfad, 'stand' => $stand, 'text' => $text];
    }
    return $ergebnis;
}

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
                 'buildPending' => false, 'uebersprungen' => [], 'fehler' => '',
                 'php' => ['hugocms_bereit' => false, 'schluessel' => '' !== shopSigningSecretKey(), 'gesendet' => []]];

    $stand = shopHugoCmsCall($db, $kanal, 'shopbuildstatus');
    if (!$stand['ok']) {
        $ergebnis['fehler'] = $stand['fehler'];
        return $ergebnis;
    }
    $ergebnis['buildPending'] = !empty($stand['data']['buildPending']);

    $wurzel = shopStagingDir($db, $kanal);
    $liste = shopHugoCmsManifest($wurzel, (array)($stand['data']['areas'] ?? []), (array)($stand['data']['accept'] ?? []));

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

    $fingerabdruck = hash('sha256', json_encode($liste['dateien']).'|'.shopChannelValue($db, $kanal, 'hugocms_url'));
    $zustand = shopPublishReadState(shopHugoCmsStateFile($db, $kanal));
    $zuletzt = strtotime((string)($zustand['syncedAt'] ?? '')) ?: 0;
    if (!$vollstaendig
        && ($zustand['fingerprint'] ?? '') === $fingerabdruck && time() - $zuletzt < SHOP_HUGOCMS_RESYNC_SECONDS) {
        $ergebnis['ok'] = true;
        $ergebnis['unchanged'] = count($liste['dateien']);
        return $ergebnis;
    }

    // 1. Abgleich
    $eintraege = [];
    foreach ($liste['dateien'] as $pfad => $pruefsumme) {
        $eintraege[] = in_array($pfad, $ergebnis['php']['gesendet'], true)
            ? ['path' => $pfad, 'sha256' => $pruefsumme, 'signature' => shopSigningSign($pfad, $pruefsumme)]
            : ['path' => $pfad, 'sha256' => $pruefsumme];
    }
    $abgleich = shopHugoCmsCall($db, $kanal, 'shopmanifest', 'POST', 120, [], ['files' => $eintraege]);
    if (!$abgleich['ok']) {
        $ergebnis['fehler'] = 'Abgleich: '.$abgleich['fehler'];
        return $ergebnis;
    }
    $syncId = (string)($abgleich['data']['syncId'] ?? '');

    // 2. Übertragung in Portionen
    $portion = [];
    $groesse = 0;
    $senden = function () use ($db, $kanal, $syncId, &$portion, &$groesse): string {
        if (!$portion) {
            return '';
        }
        $antwort = shopHugoCmsCall($db, $kanal, 'shopupload', 'POST', 120, [], ['syncId' => $syncId, 'files' => $portion]);
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
    $uebernahme = shopHugoCmsCall($db, $kanal, 'shopcommit', 'POST', 300, [], ['syncId' => $syncId]);
    if (!$uebernahme['ok']) {
        $ergebnis['fehler'] = 'Übernahme: '.$uebernahme['fehler'];
        return $ergebnis;
    }

    shopPublishWriteState(shopHugoCmsStateFile($db, $kanal), [
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
    $abschluss = function (bool $gebaut) use ($db, $kanal, $sagen, $pruefen, $vonHand, $abgleich): bool {
        if (!$pruefen) {
            return $gebaut;
        }
        $mangel = false;
        foreach (shopWebsiteManualFilesCheck($db, $kanal) as $pruefung) {
            if ('' !== $pruefung['text']) {
                $sagen($pruefung['text']);
                $mangel = true;
            }
        }
        // Hinweis, wie der Lauf die Dateien künftig selbst überträgt
        if ($mangel && $vonHand) {
            $sagen($abgleich['php']['schluessel']
                ? 'Diese Dateien überträgt der Lauf selbst, sobald in HugoCMS (Projekteinstellungen → Shop-Anbindung) '
                  .'der Signaturschlüssel von OpensourceERP hinterlegt ist: '.shopSigningPublicKey()
                : 'Diese Dateien kann der Lauf selbst übertragen: in OpensourceERP unter Systemeinstellungen '
                  .'(Shop-Erweiterung: Signaturschlüssel) ein Schlüsselpaar erzeugen und den öffentlichen Schlüssel '
                  .'in HugoCMS (Projekteinstellungen → Shop-Anbindung) eintragen.');
        }
        return $gebaut;
    };
    if ($abgleich['php']['gesendet'] && $abgleich['uebertragen']) {
        $sagen('Signiert an HugoCMS übertragen: '.implode(', ', $abgleich['php']['gesendet']));
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
        $vorschau = shopHugoCmsThumbnails($db, $kanal);
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
