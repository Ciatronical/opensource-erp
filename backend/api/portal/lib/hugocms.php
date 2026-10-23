<?php
// backend/api/portal/lib/hugocms.php
//
// Anbindung einer Webseite des Kundenportals an HugoCMS
// (dev/shop-hugocms-trennung.md, dev/kundenportal.md). HugoCMS baut die
// Webseite; OpensourceERP liefert die Dateien und stößt den Bau an. Hier
// steht der Transport: Zugang, Aufruf, Freigaben, Übertragung in Portionen,
// Bau. Was übertragen wird — das Paket mit den Widgets (lib/kit.php), die
// Produktseiten und Medien des Shops —, bestimmen Kern und Erweiterungen.
//
// Angemeldet wird mit dem Schlüssel, den HugoCMS in den Projekteinstellungen
// der Webseite erzeugt (Authorization: Bearer). Die Adresse ist der
// cms-api-Endpunkt genau dieser Webseite: HugoCMS erkennt die Webseite an Host
// und Endpunkt, ein Schlüssel gilt nur für seine. Beides steht an der
// Webseite (website_oserp: hugocms_url, hugocms_key).

/** Höchstgröße einer Portion in Bytes, vor Base64 — bleibt unter post_max_size */
const PORTAL_HUGOCMS_PORTION_BYTES = 1_500_000;

/** Höchstzahl Dateien je Portion */
const PORTAL_HUGOCMS_PORTION_FILES = 500;

require_once __DIR__.'/signing.php';

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
function portalHugoCmsUrl(string $adresse): array {
    $adresse = trim($adresse);
    if ('' === $adresse) {
        return ['adresse' => '', 'fehler' => 'Die Webseite hat keine Webadresse für den HugoCMS-Fernzugriff (Firmenkonfiguration → Kundenportal, Karte HugoCMS)'];
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
 * @param string $gesendet Schlüssel für HugoCMS, der im Aufruf stand — die Meldung
 *                         nennt sein Ende, damit man ihn mit der Anzeige in
 *                         HugoCMS („endet auf …“) vergleichen kann
 * @return string
 */
function portalHugoCmsErrorText(array $fehler, string $gesendet = ''): string {
    $schluessel = (string)($fehler['key'] ?? '');
    $code = (string)($fehler['code'] ?? '');
    $ende = '' === $gesendet ? '' : ' Gesendet wurde der Schlüssel, der auf „…'.substr($gesendet, -4).'“ endet;';
    $bekannt = [
        'SHOP-DISABLED'        => 'In HugoCMS ist die OpensourceERP-Anbindung für diese Webseite ausgeschaltet — in den Projekteinstellungen einschalten',
        'SHOP-KEY-NOT-SET'     => 'In HugoCMS ist für diese Webseite kein Schlüssel hinterlegt — in den Projekteinstellungen unter „OpensourceERP-Anbindung“ erzeugen und hier im Feld „Schlüssel für HugoCMS“ eintragen',
        'SHOP-KEY-INVALID'     => 'HugoCMS lehnt den „Schlüssel für HugoCMS“ dieser Webseite ab (Feld in der Karte HugoCMS).'.$ende
            .' HugoCMS zeigt in den Projekteinstellungen unter „OpensourceERP-Anbindung“ die letzten vier Zeichen des dort erzeugten Schlüssels — stimmen beide nicht überein, dort einen neuen Schlüssel erzeugen und hier eintragen',
        'SHOP-HTTPS-REQUIRED'  => 'HugoCMS nimmt die Anbindung nur über https an',
        'METHOD-REQUIRED'      => 'Falsche Aufrufart',
        'HUGO-NOT-CONFIGURED'  => 'In HugoCMS ist für diese Webseite kein Hugo-Projekt eingerichtet',
        'HUGO-BIN-NOT-CONFIGURED' => 'In HugoCMS ist kein Hugo-Programm eingestellt',
        'HUGO-BIN-MISSING'     => 'HugoCMS findet das Hugo-Programm nicht',
        'HUGO-SOURCE-MISSING'  => 'HugoCMS findet das Verzeichnis der Webseite nicht',
        'UNKNOWN-COMMAND'      => 'Diese HugoCMS-Version kennt die OpensourceERP-Anbindung noch nicht',
        'SHOP-PATH-NOT-ALLOWED' => 'HugoCMS nimmt diese Datei nicht an, sie liegt außerhalb der Freigaben',
        'SHOP-GRANT-UNUSABLE'  => 'In HugoCMS ist eine Freigabe unbrauchbar eingetragen — in den Projekteinstellungen unter „OpensourceERP“ prüfen und speichern',
        'SHOP-FILETYPE-NOT-ALLOWED' => 'HugoCMS nimmt diese Dateiart nicht an',
        'SHOP-HASH-MISMATCH'   => 'Eine Datei kam anders an, als angekündigt',
        'SHOP-MEDIA-INVALID'   => 'HugoCMS lehnt das Stück einer Mediendatei ab',
        'SHOP-MEDIA-OFFSET'    => 'HugoCMS erwartet das Stück einer Mediendatei an anderer Stelle',
        'SHOP-MEDIA-SIZE-MISMATCH' => 'Eine Mediendatei ist größer als im Abgleich angekündigt — in der Ansicht „Medien“ abgleichen',
        'SHOP-CHUNK-TOO-LARGE' => 'HugoCMS nimmt das Stück einer Mediendatei nicht an (zu groß für seine php.ini)',
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
        return 'HugoCMS kennt unter dieser Adresse keine Webseite — ist es der cms-api-Endpunkt der Webseite?';
    }

    $params = array_filter(array_map('strval', (array)($fehler['params'] ?? [])));
    return 'HugoCMS meldet '.trim($code.' '.$schluessel).($params ? ' ('.implode(', ', $params).')' : '');
}

/**
 * Ruft einen Befehl von HugoCMS auf
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite, deren Adresse und Schlüssel gelten
 * @param string $befehl etwa shopbuildstatus oder shopbuild
 * @param string $methode GET oder POST
 * @param int $zeitgrenze Sekunden bis zur Antwort; ein Bau braucht mehr als eine Abfrage
 * @param array $zugang url und key statt der gespeicherten — für den Verbindungstest
 *                      mit noch nicht gespeicherten Eingaben; leere Werte fallen
 *                      auf die Einstellung zurück
 * @param array $daten weitere Felder im Rumpf eines POST, neben cmd
 * @param array|null $stueck Binärdaten als Datei-Upload (multipart/form-data) statt JSON:
 *                           feld (Name des Datei-Felds), inhalt (Bytes); $daten gehen als Formularfelder mit
 * @return array ok, status (HTTP), data (Antwort bei Erfolg), fehler (Text, leer bei Erfolg)
 */
function portalHugoCmsCall($db, int $website, string $befehl, string $methode = 'GET', int $zeitgrenze = 20, array $zugang = [], array $daten = [], ?array $stueck = null): array {
    $ergebnis = ['ok' => false, 'status' => 0, 'data' => null, 'fehler' => ''];

    $eingabe = fn(string $feld, string $schluessel) => '' !== trim((string)($zugang[$feld] ?? ''))
        ? trim((string)$zugang[$feld])
        : websiteValue($db, $website, $schluessel);

    $url = portalHugoCmsUrl($eingabe('url', 'hugocms_url'));
    if ('' !== $url['fehler']) {
        $ergebnis['fehler'] = $url['fehler'];
        return $ergebnis;
    }
    $schluessel = $eingabe('key', 'hugocms_key');
    if ('' === $schluessel) {
        $ergebnis['fehler'] = 'Die Webseite hat keinen Schlüssel für den HugoCMS-Fernzugriff (Firmenkonfiguration → Kundenportal, Karte HugoCMS)';
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
    if ('POST' === $methode && null !== $stueck) {
        // Mehrteilig: die Felder als Formularfelder, die Bytes als Datei —
        // cURL setzt Content-Type multipart/form-data samt Grenze selbst
        $felder = ['cmd' => $befehl];
        foreach ($daten as $feld => $wert) {
            $felder[$feld] = (string)$wert;
        }
        $felder[(string)$stueck['feld']] = new CURLStringFile((string)$stueck['inhalt'], 'chunk', 'application/octet-stream');
        $optionen[CURLOPT_POST] = true;
        $optionen[CURLOPT_POSTFIELDS] = $felder;
    } elseif ('POST' === $methode) {
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
        $ergebnis['fehler'] = portalHugoCmsErrorText((array)($daten['error'] ?? []), $schluessel);
        return $ergebnis;
    }

    $ergebnis['ok'] = true;
    $ergebnis['data'] = $daten['data'] ?? null;
    return $ergebnis;
}

// ── Freigaben (dev/shop-hugocms-verzeichnisse.md) ──
//
// Wohin OpensourceERP schreibt, legt ein Administrator in HugoCMS fest
// (Projekteinstellungen). OpensourceERP hält keine eigene Kopie: der Lauf fragt
// die Freigaben bei HugoCMS ab (shopbuildstatus, grants) und legt die Dateien
// in der Bereitstellung genau dort ab. Der Kern braucht nur das Paket; welche
// weiteren Freigaben eine Erweiterung verlangt, sagt sie selbst ($pflicht).

/**
 * Liest die Freigaben aus einer Antwort von shopbuildstatus
 *
 * Ein unbrauchbarer Eintrag (HugoCMS meldet ihn als null) ist ein Fehler, kein
 * Anlass für eine Vorgabe: sonst schriebe OpensourceERP an einen Ort, den
 * niemand freigegeben hat.
 *
 * @param array $antwort Ergebnis von portalHugoCmsCall
 * @param array $pflicht Freigaben, die da sein müssen: Feld => Name in HugoCMS
 * @return array freigaben (package, downloads, medien, medien_stueck, dazu die
 *               Pflichtfelder), fehler (Text, leer wenn brauchbar)
 */
function portalHugoCmsGrantsFrom(array $antwort, array $pflicht = []): array {
    if (!$antwort['ok']) {
        return ['freigaben' => [], 'fehler' => 'HugoCMS: '.$antwort['fehler']];
    }
    $freigaben = $antwort['data']['grants'] ?? null;
    if (!is_array($freigaben)) {
        return ['freigaben' => [], 'fehler' => 'HugoCMS meldet keine Freigaben — diese Version kennt die '
            .'OpensourceERP-Anbindung noch nicht, bitte HugoCMS aktualisieren'];
    }

    $ergebnis = ['package' => trim((string)($freigaben['package'] ?? 'oserp-portal'), '/')];
    // Downloads: nur zur Prüfung, nicht zum Schreiben — ein älteres HugoCMS
    // ohne diese Freigabe bleibt brauchbar, die Prüfung entfällt dann
    $ergebnis['downloads'] = is_string($freigaben['downloads'] ?? null) ? trim($freigaben['downloads'], '/') : '';
    // Medien aus der Bibliothek (dev/shop-medienbibliothek.md): nimmt HugoCMS
    // sie an (seit 2026-10-15), kommen Produkt- und Vorschaubilder und
    // Downloads mit der Lieferung
    $medien = (array)($antwort['data']['media'] ?? []);
    $ergebnis['medien'] = !empty($medien['accepted']);
    $ergebnis['medien_stueck'] = (int)($medien['chunkBytes'] ?? 0);
    // Online-Shop in HugoCMS nicht freigegeben: dann fehlen die Freigaben des
    // Shops mit Absicht — ein anderer Rat als bei unbrauchbaren Einträgen
    if ($pflicht && array_key_exists('onlineShop', (array)$antwort['data']) && !$antwort['data']['onlineShop']) {
        return ['freigaben' => [], 'fehler' => 'In HugoCMS ist der Online-Shop für diese Webseite nicht freigegeben — in den '
            .'Projekteinstellungen unter „OpensourceERP-Anbindung“ den Schalter „Online-Shop auf dieser Webseite“ einschalten '
            .'und die Freigaben speichern'];
    }
    $unbrauchbar = [];
    foreach ($pflicht as $feld => $name) {
        $wert = is_string($freigaben[$feld] ?? null) ? trim($freigaben[$feld], '/') : '';
        if ('' === $wert || preg_match('#(^|/)\.#', $wert)) {
            $unbrauchbar[] = '„'.$name.'“';
        }
        $ergebnis[$feld] = $wert;
    }
    if ($unbrauchbar) {
        return ['freigaben' => [], 'fehler' => 'In HugoCMS unbrauchbar eingetragen: Freigabe '.implode(', ', $unbrauchbar)
            .' — in den Projekteinstellungen unter „OpensourceERP“ prüfen und speichern'];
    }
    return ['freigaben' => $ergebnis, 'fehler' => ''];
}

// ── Übertragung und Bau ──
//
// Drei Schritte: Abgleich (Verzeichnis mit Prüfsummen), Übertragung (nur was
// fehlt, in Portionen), Übernahme. Danach baut HugoCMS. Das Protokoll steht in
// backend/core/Shop/ShopSync.php von HugoCMS.

/**
 * Überträgt eine Lieferung an HugoCMS
 *
 * Der Aufrufer nennt jede Datei mit Prüfsumme (und Größe oder Signatur); was
 * HugoCMS anfordert, liefert er über $lesen als Inhalt oder — für große
 * Mediendateien in Stücken — über $extern selbst.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param array $eintraege Pfad => ['sha256' => …, 'size' => ?, 'signature' => ?]
 * @param callable $lesen (string $pfad): string — Inhalt einer Datei
 * @param callable|null $extern (string $syncId, string $pfad): ?string — null, wenn
 *                      der Aufrufer die Datei nicht selbst überträgt; sonst '' bei
 *                      Erfolg oder der Fehlertext
 * @return array ok, written, deleted, unchanged, buildPending, extern (Zahl), fehler
 */
function portalHugoCmsTransfer($db, int $website, array $eintraege, callable $lesen, ?callable $extern = null): array {
    $ergebnis = ['ok' => false, 'written' => 0, 'deleted' => 0, 'unchanged' => 0, 'buildPending' => false,
                 'extern' => 0, 'fehler' => ''];

    // 1. Abgleich
    $liste = [];
    foreach ($eintraege as $pfad => $eintrag) {
        $zeile = ['path' => (string)$pfad, 'sha256' => (string)$eintrag['sha256']];
        if (isset($eintrag['size'])) {
            $zeile['size'] = (int)$eintrag['size'];
        }
        if (!empty($eintrag['signature'])) {
            $zeile['signature'] = (string)$eintrag['signature'];
        }
        $liste[] = $zeile;
    }
    $abgleich = portalHugoCmsCall($db, $website, 'shopmanifest', 'POST', 120, [], ['files' => $liste]);
    if (!$abgleich['ok']) {
        $ergebnis['fehler'] = 'Abgleich: '.$abgleich['fehler'];
        return $ergebnis;
    }
    $syncId = (string)($abgleich['data']['syncId'] ?? '');

    // 2. Übertragung in Portionen
    $portion = [];
    $groesse = 0;
    $senden = function () use ($db, $website, $syncId, &$portion, &$groesse): string {
        if (!$portion) {
            return '';
        }
        $antwort = portalHugoCmsCall($db, $website, 'shopupload', 'POST', 120, [], ['syncId' => $syncId, 'files' => $portion]);
        $portion = [];
        $groesse = 0;
        return $antwort['ok'] ? '' : 'Übertragung: '.$antwort['fehler'];
    };
    foreach ((array)($abgleich['data']['needed'] ?? []) as $pfad) {
        $pfad = (string)$pfad;
        if (null !== $extern) {
            $selbst = $extern($syncId, $pfad);
            if (null !== $selbst) {
                if ('' !== $selbst) {
                    $ergebnis['fehler'] = 'Übertragung: '.$selbst;
                    return $ergebnis;
                }
                $ergebnis['extern']++;
                continue;
            }
        }
        $inhalt = (string)$lesen($pfad);
        if ($portion && ($groesse + strlen($inhalt) > PORTAL_HUGOCMS_PORTION_BYTES || count($portion) >= PORTAL_HUGOCMS_PORTION_FILES)) {
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
    $uebernahme = portalHugoCmsCall($db, $website, 'shopcommit', 'POST', 300, [], ['syncId' => $syncId]);
    if (!$uebernahme['ok']) {
        $ergebnis['fehler'] = 'Übernahme: '.$uebernahme['fehler'];
        return $ergebnis;
    }

    return array_merge($ergebnis, [
        'ok'           => true,
        'written'      => (int)($uebernahme['data']['written'] ?? 0),
        'deleted'      => (int)($uebernahme['data']['deleted'] ?? 0),
        'unchanged'    => (int)($uebernahme['data']['unchanged'] ?? 0),
        'buildPending' => !empty($uebernahme['data']['buildPending']),
    ]);
}

/**
 * Lässt HugoCMS die Webseite bauen
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @return array ok, data (Ergebnis des Baus), fehler
 */
function portalHugoCmsBuild($db, int $website): array {
    return portalHugoCmsCall($db, $website, 'shopbuild', 'POST', 600);
}

// ── Prüfung über die Webseite ──

/**
 * Ruft eine Adresse auf, ohne Umleitungen zu folgen
 *
 * @param string $adresse vollständige Adresse
 * @param array|null $json Rumpf als JSON (dann POST), null = GET
 * @param array $kopfzeilen zusätzliche Kopfzeilen, etwa X-Portal-Key
 * @return array status (0 = nicht erreichbar), kopf (Name klein => Wert), rumpf, fehler
 */
function portalWebsiteProbe(string $adresse, ?array $json = null, array $kopfzeilen = []): array {
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
 * Wer hat auf einen Aufruf von portalPing geantwortet? (testWebsiteBackendUrl)
 *
 * Unterschieden an der Form der Antwort: OpensourceERP antwortet mit
 * success/text/payload, HugoCMS mit ok/error, der Weiterleiter selbst mit
 * success und den Codes PORTAL_PROXY_NOT_CONFIGURED bzw.
 * PORTAL_BACKEND_UNREACHABLE.
 *
 * @param array $antwort Ergebnis von portalWebsiteProbe
 * @param int $website erwartete Webseite
 * @return array code (OK, OK_OLD, OTHER_WEBSITE, UNREACHABLE, HUGOCMS,
 *               KEY_REJECTED, PROXY_NOT_CONFIGURED, BACKEND_UNREACHABLE,
 *               NO_PHP, NOT_FOUND, FOREIGN), name (Webseite), status, fehler
 */
function portalApiClassify(array $antwort, int $website): array {
    $ergebnis = ['code' => 'FOREIGN', 'name' => '', 'status' => $antwort['status'], 'fehler' => $antwort['fehler']];
    $json = json_decode($antwort['rumpf'], true);
    $text = is_array($json) ? (string)($json['text'] ?? '') : '';
    $dienst = is_array($json) ? (string)($json['payload']['service'] ?? '') : '';

    if (0 === $antwort['status']) {
        $ergebnis['code'] = 'UNREACHABLE';
    } elseif (str_starts_with(ltrim($antwort['rumpf']), '<?php')) {
        $ergebnis['code'] = 'NO_PHP';
    } elseif (is_array($json) && array_key_exists('ok', $json) && is_array($json['error'] ?? null)) {
        $ergebnis['code'] = 'HUGOCMS';
    } elseif (is_array($json) && !empty($json['success']) && in_array($dienst, ['oserp-portal', 'oserp-shop'], true)) {
        // Dienstkennungen von portalPing und shopPing — nicht der Paketname
        $ergebnis['name'] = (string)($json['payload']['website_name'] ?? $json['payload']['channel_name'] ?? '');
        $ergebnis['code'] = (int)($json['payload']['website_id'] ?? 0) === $website ? 'OK' : 'OTHER_WEBSITE';
    } elseif ('PORTAL_NOT_AUTHORIZED' === $text) {
        $ergebnis['code'] = 'KEY_REJECTED';
    } elseif ('PORTAL_PROXY_NOT_CONFIGURED' === $text) {
        $ergebnis['code'] = 'PROXY_NOT_CONFIGURED';
    } elseif ('PORTAL_BACKEND_UNREACHABLE' === $text) {
        $ergebnis['code'] = 'BACKEND_UNREACHABLE';
        $ergebnis['fehler'] = (string)($json['debug'] ?? '');
    } elseif ('API_ACTION_NOT_ALLOWED' === $text) {
        // OpensourceERP ohne portalPing (älterer Stand): Schlüssel angenommen
        $ergebnis['code'] = 'OK_OLD';
    } elseif (404 === $antwort['status']) {
        $ergebnis['code'] = 'NOT_FOUND';
    }
    return $ergebnis;
}

/**
 * Prüft die Adresse des Portal-Zugangs einer Webseite (backend_url)
 *
 * Drei Schritte, jeder mit eigenem Ergebnis:
 *  1. plausibel — gesetzt, http(s), nicht die Adresse von HugoCMS
 *  2. direkt — Aufruf der Adresse von diesem Server mit dem Schlüssel der
 *     Webseite (portalPing): antwortet der Zugang von OpensourceERP, und
 *     gehört der Schlüssel zu dieser Webseite?
 *  3. über die Webseite — <Basisadresse>/portal-api/, also Weiterleiter,
 *     config.json und Zugang zusammen, wie der Browser
 *
 * Adressen und Schlüssel dürfen aus dem Formular kommen (noch nicht
 * gespeichert); leer heißt: der gespeicherte Wert gilt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param array $eingaben backend_url, base_url, hugocms_url, key — aus dem Formular, optional
 * @return array Liste aus schritt (plausibel, direkt, webseite), art (success, warning, error, info), code, params
 */
function portalBackendUrlCheck($db, int $website, array $eingaben = []): array {
    $wert = fn(string $feld) => '' !== trim((string)($eingaben[$feld] ?? ''))
        ? trim((string)$eingaben[$feld]) : trim(websiteValue($db, $website, $feld));
    $adresse = $wert('backend_url');
    $basis = rtrim($wert('base_url'), '/');
    $hugocms = rtrim($wert('hugocms_url'), '/');
    $schluessel = '' !== trim((string)($eingaben['key'] ?? '')) ? trim((string)$eingaben['key'])
        : websiteValue($db, $website, 'public_key');

    $schritte = [];
    $melden = function (string $schritt, string $art, string $code, array $params = []) use (&$schritte) {
        $schritte[] = ['schritt' => $schritt, 'art' => $art, 'code' => $code, 'params' => $params];
    };
    // url: die aufgerufene Adresse — die Meldung nennt sie vollständig, damit
    // man sie im Browser nachprüfen kann
    $bewerten = function (string $schritt, array $ziel, string $url = '') use ($melden) {
        $art = match ($ziel['code']) {
            'OK' => 'success',
            'OK_OLD' => 'warning',
            default => 'error',
        };
        $melden($schritt, $art, $ziel['code'], [
            'name' => $ziel['name'], 'status' => (string)$ziel['status'], 'error' => (string)$ziel['fehler'], 'url' => $url,
        ]);
    };

    // 1. plausibel
    $plausibel = true;
    if ('' === $adresse) {
        $melden('plausibel', 'error', 'EMPTY');
        $plausibel = false;
    } elseif (!preg_match('~^https?://[^/\s]+~i', $adresse)) {
        $melden('plausibel', 'error', 'INVALID', ['url' => $adresse]);
        $plausibel = false;
    } elseif (('' !== $hugocms && rtrim($adresse, '/') === $hugocms) || str_contains($adresse, '/cms-api')) {
        $melden('plausibel', 'error', 'IS_HUGOCMS', ['url' => $adresse]);
        $plausibel = false;
    } else {
        $melden('plausibel', 'success', 'PLAUSIBLE', ['url' => $adresse]);
    }

    // 2. direkt von diesem Server
    if (!$plausibel) {
        $melden('direkt', 'info', 'SKIPPED');
    } elseif ('' === $schluessel) {
        $melden('direkt', 'error', 'NO_KEY');
    } else {
        $direkt = rtrim($adresse, '/').'/';
        $bewerten('direkt', portalApiClassify(
            portalWebsiteProbe($direkt, ['action' => 'portalPing'], ['X-Portal-Key: '.$schluessel]),
            $website
        ), $direkt);
    }

    // 3. über die Webseite
    if ('' === $basis) {
        $melden('webseite', 'info', 'NO_BASE');
    } else {
        $weiterleiter = $basis.'/portal-api/';
        $bewerten('webseite', portalApiClassify(
            portalWebsiteProbe($weiterleiter, ['action' => 'portalPing']),
            $website
        ), $weiterleiter);
    }

    return $schritte;
}

/**
 * Vorschlag für die Adresse des Portal-Zugangs (backend_url einer Webseite)
 *
 * Die Adresse, unter der OpensourceERP gerade aufgerufen wird, mit dem Pfad
 * des Zugangs backend/portal/ (Webserver: Alias /portal). Hinter einem
 * vorgeschalteten Proxy zählen dessen Kopfzeilen; lokal mit dem PHP-Server
 * aus scripts/dev.sh ist es http://localhost:8000/portal/. Leer ohne
 * HTTP-Aufruf (Kommandozeile).
 *
 * @return string
 */
function portalBackendUrlSuggestion(): string {
    $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''));
    if ('' === $host || !preg_match('/^[A-Za-z0-9.:\[\]-]+$/', $host)) {
        return '';
    }
    $schema = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if (!in_array($schema, ['http', 'https'], true)) {
        $schema = !empty($_SERVER['HTTPS']) && 'off' !== strtolower((string)$_SERVER['HTTPS']) ? 'https' : 'http';
    }
    return $schema.'://'.$host.'/portal/';
}
