<?php
// backend/api/shop/lib/publish.php
//
// Erzeugt die Inhaltsdateien des Shops aus parts und parts_ext.
//
// Vorlagensaetze arbeiten wie die Druckvorlagen: gespeichert wird ein Name,
// gesucht wird erst in der Kundenkopie unter <templates_dir>/shop/, dann im
// mitgelieferten Satz unter backend/templates-default/shop/.
//
// Gerendert wird wie die Mailvorlagen des Shops — PHP mit Ausgabepuffer. Die
// LaTeX-Engine der Druckaufbereitung passt hier nicht: ihre Maskierung ist auf
// LaTeX festgelegt, und die vorhandene Vorlage des Beispielshops ist PHP und
// laesst sich so fast unveraendert uebernehmen. Eine Vorlage ist damit Code —
// sie stammt wie die Druckvorlagen vom Betreiber, nicht von Benutzern.

/** Mitgelieferte Vorlagensaetze */
function shopTemplateMasterDir(): string {
    return dirname(__DIR__, 3).'/templates-default/shop';
}

/** Vorlagensaetze des Kunden (eigenes Repository, Pfad aus der settings.ini) */
function shopTemplateUserDir(): string {
    return OSERP_TEMPLATES_DIR.'/shop';
}

/**
 * Beschreibung eines Satzes aus theme.json
 *
 * @param string $verzeichnis Verzeichnis des Satzes
 * @return array leer, wenn es keine oder eine unbrauchbare theme.json gibt
 */
function shopTemplateInfo(string $verzeichnis): array {
    $datei = $verzeichnis.'/theme.json';
    if (!is_file($datei)) {
        return [];
    }
    $roh = json_decode((string)file_get_contents($datei), true);
    return is_array($roh) ? $roh : [];
}

/**
 * Alle verfuegbaren Vorlagensaetze
 *
 * Die Kundenkopie hat Vorrang und verdeckt einen gleichnamigen mitgelieferten
 * Satz — dieselbe Reihenfolge, in der shopTemplateDir() aufloest.
 *
 * @return array Liste aus name, title, source, hugo_theme
 */
function shopTemplateSets(): array {
    $saetze = [];

    foreach ([['default', shopTemplateMasterDir()], ['custom', shopTemplateUserDir()]] as $herkunft) {
        foreach (glob($herkunft[1].'/*', GLOB_ONLYDIR) ?: [] as $verzeichnis) {
            $name = basename($verzeichnis);
            $info = shopTemplateInfo($verzeichnis);
            $saetze[$name] = [
                'name'       => $name,
                'title'      => $info['title'] ?? $name,
                'source'     => $herkunft[0],
                'hugo_theme' => $info['hugo_theme'] ?? '',
            ];
        }
    }

    ksort($saetze);
    return array_values($saetze);
}

/**
 * Verzeichnis eines Vorlagensatzes
 *
 * Nur ein Name, kein Pfad: die Einstellung ist fuer Mitarbeiter aenderbar, und
 * mit einem Pfad liesse sich jede lesbare Datei des Servers einbinden.
 *
 * @param string $name Name des Satzes
 * @return string
 * @throws ApiError SHOP_TEMPLATE_SET_INVALID, SHOP_TEMPLATE_SET_MISSING
 */
function shopTemplateDir(string $name): string {
    if ('' === $name || '.' === $name[0] || preg_match('#[/\\\\]#', $name)) {
        throw new ApiError('SHOP_TEMPLATE_SET_INVALID', 'Unbrauchbarer Vorlagensatz: '.$name);
    }

    foreach ([shopTemplateUserDir(), shopTemplateMasterDir()] as $basis) {
        if (is_dir($basis.'/'.$name)) {
            return $basis.'/'.$name;
        }
    }

    throw new ApiError('SHOP_TEMPLATE_SET_MISSING', 'Vorlagensatz nicht gefunden: '.$name);
}

/**
 * Ein Verzeichnis unterhalb einer Wurzel, aus einem eingestellten Pfad
 *
 * Zwei Pruefungen, weil eine allein nicht reicht: '..' wird vor dem Anlegen
 * abgewiesen, und nach dem Aufloesen muss der Pfad immer noch unterhalb der
 * Wurzel liegen — sonst fuehrte eine Verknuepfung daran vorbei.
 *
 * @param string $wurzel Aufgeloeste Wurzel
 * @param string $relativ Eingestellter Pfad, relativ
 * @param bool $anlegen Fehlendes Verzeichnis anlegen
 * @return string
 * @throws ApiError SHOP_PATH_INVALID, SHOP_PATH_MISSING, SHOP_PATH_OUTSIDE_ROOT
 */
function shopPathUnder(string $wurzel, string $relativ, bool $anlegen = false): string {
    $relativ = trim($relativ, '/');

    if ('' !== $relativ && preg_match('#(^|/)\.\.(/|$)#', $relativ)) {
        throw new ApiError('SHOP_PATH_INVALID', 'Pfad darf nicht aus dem Webseiten-Verzeichnis herausfuehren: '.$relativ);
    }

    $ziel = '' === $relativ ? $wurzel : $wurzel.'/'.$relativ;

    if ($anlegen && !is_dir($ziel)) {
        @mkdir($ziel, 0775, true);
    }

    $echt = realpath($ziel);
    if (false === $echt) {
        throw new ApiError('SHOP_PATH_MISSING', 'Verzeichnis gibt es nicht: '.$ziel);
    }
    if ($echt !== $wurzel && !str_starts_with($echt.'/', $wurzel.'/')) {
        throw new ApiError('SHOP_PATH_OUTSIDE_ROOT', 'Verzeichnis liegt ausserhalb des Webseiten-Verzeichnisses: '.$echt);
    }

    return $echt;
}

/**
 * Wurzel aller Webseiten-Verzeichnisse (settings.ini, nicht aenderbar im ERP)
 *
 * @return string
 * @throws ApiError SHOP_SITES_DIR_MISSING
 */
function shopSitesRoot(): string {
    $wurzel = defined('OSERP_SHOP_SITES_DIR') ? (string)OSERP_SHOP_SITES_DIR : '';
    $echt = '' === $wurzel ? false : realpath($wurzel);

    if (false === $echt) {
        throw new ApiError(
            'SHOP_SITES_DIR_MISSING',
            'In der settings.ini fehlt shop_sites_dir oder das Verzeichnis gibt es nicht'
        );
    }
    return $echt;
}

/** Verzeichnis des Hugo-Projekts dieses Mandanten */
function shopSiteDir($db, bool $anlegen = false): string {
    return shopPathUnder(shopSitesRoot(), shopConfigValue($db, 'shop_site_dir'), $anlegen);
}

/** Zielverzeichnis der Inhaltsdateien */
function shopContentDir($db, bool $anlegen = false): string {
    // Vorgabe wie im Schema — fehlt die Zeile noch, landeten die Seiten sonst
    // direkt im Verzeichnis der Webseite
    return shopPathUnder(shopSiteDir($db, $anlegen), shopConfigValue($db, 'shop_content_dir', 'content/de/produkt'), $anlegen);
}

// ── Werte fuer die Vorlage ──

/**
 * Alles, was eine Produktseite braucht
 *
 * Eine Abfrage; die Vorlage bekommt ein einziges Array statt eines Schwarms
 * von Einzelvariablen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @return array artikel, shop, betrieb
 * @throws ApiError PART_NOT_FOUND
 */
function shopPageData($db, int $partsId): array {
    $inklusive = shopConfigBool($db, 'shop_tax_included', false) ? 1 : 0;

    // Steuersatz wie in der Faktura: Buchungsgruppe -> Steuerzone ->
    // Erloeskonto -> Steuerschluessel. Anders als dort nur Schluessel, die
    // schon gelten — ein kuenftiger Satz gehoert noch nicht auf die Seite.
    // Ohne Schluessel ist der Satz 0 und brutto gleich netto.
    $zeile = $db->getOne(
        "SELECT p.id, p.partnumber, p.description, p.notes, p.unit, p.ean,
                TRUNC(p.onhand) AS onhand, p.obsolete,
                COALESCE(p.mtime, p.itime) AS mtime,
                COALESCE(st.rate, 0) AS taxrate,
                CASE WHEN :inklusive_netto = 1
                     THEN ROUND(p.sellprice / (1 + COALESCE(st.rate, 0)), 2)
                     ELSE ROUND(p.sellprice, 2) END AS price_net,
                CASE WHEN :inklusive_brutto = 1
                     THEN ROUND(p.sellprice, 2)
                     ELSE ROUND(p.sellprice * (1 + COALESCE(st.rate, 0)), 2) END AS price_gross,
                (pe.id IS NOT NULL) AS listed,
                pe.hugoshop_category, pe.hugoshop_hyperlink, pe.hugoshop_breadcrumbs,
                pe.hugoshop_images, pe.hugoshop_technical_data,
                pe.hugoshop_properties, pe.hugoshop_downloads,
                (SELECT company FROM defaults LIMIT 1) AS firma
           FROM parts p
           LEFT JOIN parts_ext pe ON pe.parts_id = p.id
           LEFT JOIN LATERAL (
                SELECT tx.rate
                  FROM taxzone_charts tc
                  JOIN tax_zones tz ON tz.id = tc.taxzone_id
                  JOIN taxkeys tk ON tk.chart_id = tc.income_accno_id
                  JOIN tax tx ON tx.id = tk.tax_id
                 WHERE tc.buchungsgruppen_id = p.buchungsgruppen_id
                   AND tz.description = :taxzone
                   AND tk.startdate <= current_date
                 ORDER BY tk.startdate DESC
                 LIMIT 1
           ) st ON true
          WHERE p.id = :parts_id",
        [
            ':parts_id'         => $partsId,
            ':taxzone'          => shopConfigValue($db, 'shop_standard_taxzone', 'Inland'),
            ':inklusive_netto'  => $inklusive,
            ':inklusive_brutto' => $inklusive,
        ]
    );

    if (!$zeile) {
        throw new ApiError('PART_NOT_FOUND', 'Artikel nicht gefunden: '.$partsId);
    }

    $liste  = fn($wert) => is_array($w = json_decode((string)$wert, true)) ? $w : [];
    $wahr   = fn($wert) => true === $wert || 't' === $wert || '1' === $wert;

    $bilder         = $liste($zeile['hugoshop_images']);
    $downloads      = $liste($zeile['hugoshop_downloads']);
    $bildMuster     = shopConfigValue($db, 'shop_images_link');
    $vorschauMuster = shopConfigValue($db, 'shop_thumbnails_link');
    $downloadMuster = shopConfigValue($db, 'shop_downloads_link', '/downloads/%s');

    return [
        'artikel' => [
            'id'          => (int)$zeile['id'],
            'partnumber'  => (string)$zeile['partnumber'],
            'description' => (string)$zeile['description'],
            'notes'       => (string)($zeile['notes'] ?? ''),
            'unit'        => (string)($zeile['unit'] ?? ''),
            'ean'         => (string)($zeile['ean'] ?? ''),
            'sellprice'   => (float)$zeile['price_net'],
            'price_net'   => (float)$zeile['price_net'],
            'price_gross' => (float)$zeile['price_gross'],
            'taxrate'     => (float)$zeile['taxrate'],
            'onhand'      => (float)($zeile['onhand'] ?? 0),
            'obsolete'    => $wahr($zeile['obsolete']),
            // Für lastmod im Front Matter — Hugo übernimmt es in die Sitemap
            'mtime'       => empty($zeile['mtime']) ? '' : (new DateTimeImmutable($zeile['mtime']))->format(DATE_ATOM),
        ],
        'shop' => [
            'listed'         => $wahr($zeile['listed']),
            'category'       => trim((string)($zeile['hugoshop_category'] ?? '')),
            'hyperlink'      => (string)($zeile['hugoshop_hyperlink'] ?? ''),
            'breadcrumbs'    => $liste($zeile['hugoshop_breadcrumbs']),
            'images'         => $bilder,
            'image_urls'     => array_map(fn($bild) => shopLink($bildMuster, (string)$bild), $bilder),
            'thumbnail_url'  => $bilder ? shopLink($vorschauMuster, (string)$bilder[0]) : '',
            'technical_data' => $liste($zeile['hugoshop_technical_data']),
            'properties'     => $liste($zeile['hugoshop_properties']),
            'downloads'      => $downloads,
            'download_urls'  => array_map(fn($datei) => shopLink($downloadMuster, (string)$datei), $downloads),
        ],
        'betrieb' => [
            'firma'            => (string)($zeile['firma'] ?? ''),
            'currency'         => shopConfigValue($db, 'shop_standard_currency', 'EUR'),
            'base_url'         => shopConfigValue($db, 'shop_base_url'),
            'products_link'    => shopConfigValue($db, 'shop_products_link'),
            'thumbnails_link'  => shopConfigValue($db, 'shop_thumbnails_link'),
            'free_shipping_from' => shopConfigFloat($db, 'shop_free_shipping_from', 0),
            'tax_included'     => shopConfigBool($db, 'shop_tax_included', false),
        ],
    ];
}

// ── Hilfen fuer die Vorlagen ──

/** Text als YAML-Zeichenkette, mit Anfuehrungszeichen */
function shopYaml($wert): string {
    $text = str_replace(["\r\n", "\r", "\n"], ' ', (string)$wert);
    return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $text).'"';
}

/** Liste als einzeilige YAML-Folge */
function shopYamlList(array $werte): string {
    return '[ '.implode(', ', array_map('shopYaml', $werte)).' ]';
}

/**
 * Setzt einen Dateinamen in ein Adressmuster mit %s
 *
 * Ohne Muster bleibt der Name stehen — wie shopSearchFormatLink(), das hier
 * nicht geladen ist (der Laeufer braucht die Suche nicht).
 */
function shopLink(string $muster, string $wert): string {
    if ('' === $muster || false === strpos($muster, '%s')) {
        return $wert;
    }
    return str_replace('%s', rawurlencode($wert), $muster);
}

/** Geldbetrag fuer die Anzeige: deutsches Zahlenformat */
function shopMoney($wert): string {
    return number_format((float)$wert, 2, ',', '.');
}

/** Zahl mit Punkt als Dezimaltrenner — Front Matter ist keine Anzeige */
function shopNumber($wert, int $stellen = 2): string {
    return number_format((float)$wert, $stellen, '.', '');
}

// ── Vorschaubilder ──

/**
 * Verkleinert ein Bild auf hoechstens $groesse Pixel an der laengsten Seite
 *
 * Seitenverhaeltnis, Format und Transparenz bleiben; kleinere Bilder werden
 * nicht vergroessert.
 *
 * @param string $quelle Bilddatei
 * @param string $ziel Vorschaubild
 * @param int $groesse Laengste Seite in Pixeln
 * @return bool
 */
function shopThumbnail(string $quelle, string $ziel, int $groesse): bool {
    $info = @getimagesize($quelle);
    if (!$info) {
        return false;
    }
    [$breite, $hoehe, $typ] = $info;

    $laden = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG  => 'imagecreatefrompng',
        IMAGETYPE_GIF  => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
    if (!isset($laden[$typ])) {
        return false;
    }
    $bild = @$laden[$typ]($quelle);
    if (!$bild) {
        return false;
    }

    $faktor = min(1, max(1, $groesse) / max($breite, $hoehe));
    $neuBreite = max(1, (int)round($breite * $faktor));
    $neuHoehe  = max(1, (int)round($hoehe * $faktor));

    $vorschau = imagecreatetruecolor($neuBreite, $neuHoehe);
    imagealphablending($vorschau, false);
    imagesavealpha($vorschau, true);
    imagefill($vorschau, 0, 0, imagecolorallocatealpha($vorschau, 0, 0, 0, 127));
    imagecopyresampled($vorschau, $bild, 0, 0, 0, 0, $neuBreite, $neuHoehe, $breite, $hoehe);

    switch ($typ) {
        case IMAGETYPE_JPEG: return imagejpeg($vorschau, $ziel, 85);
        case IMAGETYPE_PNG:  return imagepng($vorschau, $ziel);
        case IMAGETYPE_GIF:  return imagegif($vorschau, $ziel);
        default:             return imagewebp($vorschau, $ziel, 85);
    }
}

/**
 * Vorschaubild zum ersten Bild eines Artikels
 *
 * Nur wenn beide Verzeichnisse eingestellt sind. Ein Vorschaubild, das neuer
 * ist als seine Quelle, bleibt stehen — ein Vollbau rechnete sonst tausende
 * Bilder neu. Scheitert etwas, bleibt die Seite trotzdem geschrieben.
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $seite Werte aus shopPageData()
 * @return string erzeugt, aktuell, keine Bilder, nicht eingerichtet, Quelle fehlt, Fehler: …
 */
function shopThumbnailFor($db, array $seite): string {
    if (!$seite['shop']['images']) {
        return 'keine Bilder';
    }
    if ('' === shopConfigValue($db, 'shop_images_dir') || '' === shopConfigValue($db, 'shop_thumbnails_dir')) {
        return 'nicht eingerichtet';
    }

    try {
        $webseite = shopSiteDir($db);
        $name = basename((string)$seite['shop']['images'][0]);

        $quelle = shopPathUnder($webseite, shopConfigValue($db, 'shop_images_dir')).'/'.$name;
        if (!is_file($quelle)) {
            return 'Quelle fehlt';
        }

        $ziel = shopPathUnder($webseite, shopConfigValue($db, 'shop_thumbnails_dir'), true).'/'.$name;
        if (is_file($ziel) && filemtime($ziel) >= filemtime($quelle)) {
            return 'aktuell';
        }

        return shopThumbnail($quelle, $ziel, shopConfigInt($db, 'shop_thumbnail_size', 200))
            ? 'erzeugt' : 'Fehler: Bild nicht lesbar';
    } catch (ApiError $e) {
        return 'Fehler: '.$e->getMessage();
    }
}

// ── Rendern und Schreiben ──

/**
 * Dateiname der Seite
 *
 * Aus der eingetragenen Produktseite, ersatzweise der Artikelnummer. basename()
 * haelt den Namen im Zielverzeichnis: die Eingabe stammt aus der Artikelmaske.
 *
 * @param array $seite Werte aus shopPageData()
 * @return string
 */
function shopPageFileName(array $seite): string {
    $name = $seite['shop']['hyperlink'] !== '' ? $seite['shop']['hyperlink'] : $seite['artikel']['partnumber'];
    $name = mb_strtolower(basename(trim($name)));

    if ('' === $name) {
        throw new ApiError('SHOP_PAGE_NAME_MISSING', 'Weder Produktseite noch Artikelnummer gesetzt');
    }
    return str_ends_with($name, '.md') ? $name : $name.'.md';
}

/**
 * Rendert eine Seite mit dem eingestellten Vorlagensatz
 *
 * Bringt der Satz die Vorlage nicht mit, gilt die des mitgelieferten Satzes
 * standard — wie beim Webseiten-Paket und den Regeln der Kategorieübersicht.
 * Ein eigener Satz besteht so nur aus dem, was er ändert.
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $seite Werte aus shopPageData()
 * @param string $ausgabe Vorlage ohne Endung, z.B. 'product'
 * @return string
 * @throws ApiError SHOP_TEMPLATE_MISSING
 */
function shopRenderPage($db, array $seite, string $ausgabe = 'product'): string {
    $verzeichnis = shopTemplateDir(shopConfigValue($db, 'shop_template_set', 'standard'));
    $name = basename($ausgabe).'.md.php';

    $vorlage = $verzeichnis.'/'.$name;
    if (!is_file($vorlage)) {
        $vorlage = shopTemplateMasterDir().'/standard/'.$name;
    }
    if (!is_file($vorlage)) {
        throw new ApiError('SHOP_TEMPLATE_MISSING', 'Vorlage fehlt im Satz: '.$name);
    }

    // Eigene Hilfen des Satzes, falls vorhanden
    $hilfen = $verzeichnis.'/_helpers.php';
    if (is_file($hilfen)) {
        require_once $hilfen;
    }

    ob_start();
    include $vorlage;   // sieht $seite und $verzeichnis
    return (string)ob_get_clean();
}

/**
 * Schreibt die Produktseite eines Artikels
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @return array file, bytes, thumbnail
 * @throws ApiError SHOP_WRITE_FAILED
 */
function shopWriteProductPage($db, int $partsId): array {
    $seite = shopPageData($db, $partsId);
    $inhalt = shopRenderPage($db, $seite);
    $datei = shopContentDir($db, true).'/'.shopPageFileName($seite);

    $geschrieben = file_put_contents($datei, $inhalt, LOCK_EX);
    if (false === $geschrieben) {
        throw new ApiError('SHOP_WRITE_FAILED', 'Datei nicht schreibbar: '.$datei);
    }

    return ['file' => $datei, 'bytes' => $geschrieben, 'thumbnail' => shopThumbnailFor($db, $seite)];
}

// ── Webseiten-Paket ──
//
// Der Vorlagensatz bringt unter kit/ mit, was die Webseite ausser den
// Produktseiten braucht: Hugo-Layouts, Einstiegspunkte im Docroot (Proxy,
// 404-Seite) und das Widget-Bundle. Der Laeufer spiegelt es nach
// <webseite>/oserp-shop/. Die Webseite haengt die Unterordner NACH ihren
// eigenen ein — so behalten ihre Uebersteuerungen Vorrang. Direkt nach
// layouts/ kopiert, ueberschriebe das Paket die Dateien der Instanz.
//
// Der Name ist fest: der Proxy findet seine Konfiguration darueber.

/** Verzeichnis des Pakets in der Webseite */
function shopKitDir($db, bool $anlegen = false): string {
    return shopPathUnder(shopSiteDir($db, $anlegen), 'oserp-shop', $anlegen);
}

/**
 * Inhalt von oserp-shop/config.php
 *
 * Adresse und Schluessel fuer Proxy und 404-Seite. Die Datei liegt ausserhalb
 * des Docroots, und Hugo haengt sie nicht ein.
 */
function shopKitConfig($db): string {
    $werte = [
        'url' => shopConfigValue($db, 'shop_backend_url'),
        'key' => shopConfigValue($db, 'shop_public_key'),
    ];
    return "<?php\n"
         . "// Von OpensourceERP geschrieben (tools/shop-publish.php) — nicht von Hand aendern.\n"
         . "// Liegt ausserhalb des Docroots und wird von Hugo nicht eingehaengt.\n"
         . 'return '.var_export($werte, true).";\n";
}

/**
 * Dateien des Pakets, relativer Pfad => Quelle
 *
 * Grundlage ist das Paket des mitgelieferten Satzes standard; das Paket des
 * gewählten Satzes legt sich dateiweise darüber. Ein eigener Satz bringt so
 * nur mit, was er ändert, und bekommt das Widget-Bundle nach jedem Neubau
 * ohne eigene Kopie. Entfernen kann er eine Datei der Grundlage nicht, nur
 * ersetzen.
 *
 * @param string $satz Name des gewählten Satzes
 * @return array
 * @throws ApiError SHOP_TEMPLATE_SET_INVALID, SHOP_TEMPLATE_SET_MISSING
 */
function shopKitFiles(string $satz): array {
    $schichten = array_unique([shopTemplateMasterDir().'/standard/kit', shopTemplateDir($satz).'/kit']);

    $dateien = [];
    foreach ($schichten as $quelle) {
        if (!is_dir($quelle)) {
            continue;
        }
        $eintraege = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($quelle, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($eintraege as $datei) {
            if ($datei->isFile()) {
                $dateien[substr($datei->getPathname(), strlen($quelle) + 1)] = $datei->getPathname();
            }
        }
    }

    ksort($dateien);
    return $dateien;
}

/**
 * Spiegelt das Paket des Vorlagensatzes in die Webseite
 *
 * Kopiert nur, was sich geaendert hat, und entfernt, was das Paket nicht mehr
 * enthaelt — beides nur innerhalb von oserp-shop/. Die config.php bleibt dabei
 * stehen und wird nur neu geschrieben, wenn sich ihr Inhalt aendert. Woraus
 * das Paket besteht, sagt shopKitFiles().
 *
 * @param object $db Company-Datenbankverbindung
 * @return array kopiert, entfernt, config (true wenn neu geschrieben)
 * @throws ApiError SHOP_WRITE_FAILED
 */
function shopSyncKit($db): array {
    $bilanz = ['kopiert' => 0, 'entfernt' => 0, 'config' => false];

    $imPaket = shopKitFiles(shopConfigValue($db, 'shop_template_set', 'standard'));
    if (!$imPaket) {
        return $bilanz;
    }
    $ziel = shopKitDir($db, true);

    foreach ($imPaket as $relativ => $quelle) {
        $zielDatei = $ziel.'/'.$relativ;
        if (is_file($zielDatei) && md5_file($zielDatei) === md5_file($quelle)) {
            continue;
        }
        if (!is_dir(dirname($zielDatei))) {
            mkdir(dirname($zielDatei), 0775, true);
        }
        if (!copy($quelle, $zielDatei)) {
            throw new ApiError('SHOP_WRITE_FAILED', 'Datei nicht kopierbar: '.$zielDatei);
        }
        $bilanz['kopiert']++;
    }

    // Kinder vor Eltern, damit leer gewordene Verzeichnisse mit verschwinden.
    // Verknuepfungen werden als solche geloescht, nie ihr Ziel.
    $eintraege = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($ziel, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($eintraege as $eintrag) {
        $relativ = substr($eintrag->getPathname(), strlen($ziel) + 1);
        if ('config.php' === $relativ) {
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

    $inhalt = shopKitConfig($db);
    $konfiguration = $ziel.'/config.php';
    if (!is_file($konfiguration) || file_get_contents($konfiguration) !== $inhalt) {
        if (false === file_put_contents($konfiguration, $inhalt, LOCK_EX)) {
            throw new ApiError('SHOP_WRITE_FAILED', 'Datei nicht schreibbar: '.$konfiguration);
        }
        $bilanz['config'] = true;
    }

    return $bilanz;
}

/** Zahl der Aenderungen eines Abgleichs */
function shopKitChanges(array $kit): int {
    return $kit['kopiert'] + $kit['entfernt'] + ($kit['config'] ? 1 : 0);
}

// ── Auftraege ──
//
// Die Auftragsarten dieser Erweiterung. Nur diese nimmt der Laeufer, und nur
// diese zeigt die Auftragsliste — die Tabelle stammt aus der Bridge.
function shopJobFunctions(): array {
    return ['publish_part', 'publish_all', 'remove_part', 'sync_kit', 'reconcile_payments'];
}
//
// Die Tabelle batchjob_hugoshop stammt aus der Bridge und bleibt unveraendert:
// id, function, partnumber, param, result. Ein Auftrag ist offen, solange
// result NULL ist. Die Anwendung schreibt nur Auftraege; geschrieben und
// gebaut wird auf der Kommandozeile (tools/shop-publish.php).

/**
 * Nimmt einen Auftrag an, wenn er nicht schon offen ist
 *
 * Doppelte offene Auftraege waeren sinnlose Arbeit: der Laeufer erzeugt die
 * Seite ohnehin aus dem aktuellen Stand.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $funktion eine aus shopJobFunctions()
 * @param string $partnumber Artikelnummer, leer bei publish_all
 * @param string|null $param Zusatzangabe, etwa der zu loeschende Dateiname
 * @return int Auftragsnummer, 0 wenn schon offen
 */
function shopQueueJob($db, string $funktion, string $partnumber = '', ?string $param = null): int {
    $zeile = $db->getOne(
        "INSERT INTO batchjob_hugoshop (function, partnumber, param)
         SELECT :function, :partnumber, :param
          WHERE NOT EXISTS (
                SELECT 1 FROM batchjob_hugoshop
                 WHERE function = :function_offen
                   AND partnumber = :partnumber_offen
                   AND result IS NULL)
         RETURNING id",
        [
            ':function'          => $funktion,
            ':partnumber'        => $partnumber,
            ':param'             => $param,
            ':function_offen'    => $funktion,
            ':partnumber_offen'  => $partnumber,
        ]
    );

    return $zeile ? (int)$zeile['id'] : 0;
}

/**
 * Offene Auftraege, aelteste zuerst
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $limit Hoechstzahl
 * @return array
 */
function shopOpenJobs($db, int $limit = 500): array {
    // Nur die eigenen Funktionen: die Tabelle teilt sich OSERP mit dem Laeufer
    // der Bridge, und jeder der beiden vermerkt fremde Auftraege sonst als
    // Fehler.
    return $db->getAll(
        "SELECT id, function, partnumber, param
           FROM batchjob_hugoshop
          WHERE result IS NULL
            AND function = ANY(string_to_array(:funktionen, ','))
          ORDER BY id
          LIMIT :limit",
        [':limit' => $limit, ':funktionen' => implode(',', shopJobFunctions())]
    );
}

/**
 * Haelt das Ergebnis eines Auftrags fest
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $id Auftrag
 * @param string $ergebnis Kurzer Text, beginnt mit "ok" oder "Fehler"
 * @return void
 */
function shopJobResult($db, int $id, string $ergebnis): void {
    $db->execute(
        "UPDATE batchjob_hugoshop SET result = :result WHERE id = :id",
        [':result' => mb_substr($ergebnis, 0, 500), ':id' => $id]
    );
}

/**
 * Artikel, die im Shop stehen
 *
 * @param object $db Company-Datenbankverbindung
 * @return array Liste aus id und partnumber
 */
function shopListedParts($db): array {
    return $db->getAll(
        "SELECT p.id, p.partnumber
           FROM parts p
           JOIN parts_ext pe ON pe.parts_id = p.id
          ORDER BY p.id"
    );
}

/**
 * Loescht die Seite eines Artikels
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $dateiname Dateiname ohne Pfad
 * @return bool true, wenn es die Datei gab
 */
function shopRemovePage($db, string $dateiname): bool {
    $datei = shopContentDir($db).'/'.basename($dateiname);
    return is_file($datei) ? unlink($datei) : false;
}

/**
 * Arbeitet die offenen Auftraege ab
 *
 * Ein Fehler beendet nur den einen Auftrag: er bekommt sein Ergebnis und die
 * Schlange laeuft weiter, sonst blockierte ein einzelner Artikel alles.
 *
 * @param object $db Company-Datenbankverbindung
 * @param callable|null $melden Fortschritt, bekommt je eine Zeile Text
 * @param int $limit Hoechstzahl Auftraege
 * @return array jobs, seiten, entfernt, fehler, kit (Änderungen am Webseiten-Paket)
 */
function shopRunJobs($db, ?callable $melden = null, int $limit = 500): array {
    $sagen = $melden ?? function (string $zeile) {};
    $bilanz = ['jobs' => 0, 'seiten' => 0, 'entfernt' => 0, 'fehler' => 0, 'kit' => 0];

    foreach (shopOpenJobs($db, $limit) as $auftrag) {
        $id = (int)$auftrag['id'];
        $bilanz['jobs']++;

        try {
            switch ($auftrag['function']) {
                case 'publish_part':
                    $artikel = $db->getOne(
                        "SELECT id FROM parts WHERE partnumber = :partnumber",
                        [':partnumber' => $auftrag['partnumber']]
                    );
                    if (!$artikel) {
                        throw new ApiError('PART_NOT_FOUND', 'Artikel nicht gefunden: '.$auftrag['partnumber']);
                    }
                    $ergebnis = shopWriteProductPage($db, (int)$artikel['id']);
                    $bilanz['seiten']++;
                    $sagen('Seite geschrieben: '.basename($ergebnis['file']).' (Vorschaubild: '.$ergebnis['thumbnail'].')');
                    shopJobResult($db, $id, 'ok: '.basename($ergebnis['file']));
                    break;

                case 'publish_all':
                    $anzahl = 0;
                    foreach (shopListedParts($db) as $artikel) {
                        try {
                            shopWriteProductPage($db, (int)$artikel['id']);
                            $anzahl++;
                        } catch (ApiError $e) {
                            $bilanz['fehler']++;
                            $sagen('Artikel '.$artikel['partnumber'].': '.$e->getMessage());
                        }
                    }
                    $bilanz['seiten'] += $anzahl;
                    $sagen('Alle Seiten geschrieben: '.$anzahl);
                    shopJobResult($db, $id, 'ok: '.$anzahl.' Seiten');
                    break;

                case 'remove_part':
                    $weg = shopRemovePage($db, (string)($auftrag['param'] ?? ''));
                    if ($weg) {
                        $bilanz['entfernt']++;
                    }
                    $sagen('Seite entfernt: '.$auftrag['param'].($weg ? '' : ' (gab es nicht)'));
                    shopJobResult($db, $id, $weg ? 'ok: entfernt' : 'ok: gab es nicht');
                    break;

                case 'sync_kit':
                    $kit = shopSyncKit($db);
                    $bilanz['kit'] += shopKitChanges($kit);
                    $sagen(sprintf('Paket abgeglichen: %d kopiert, %d entfernt%s',
                        $kit['kopiert'], $kit['entfernt'], $kit['config'] ? ', Konfiguration neu' : ''));
                    shopJobResult($db, $id, sprintf('ok: %d kopiert, %d entfernt', $kit['kopiert'], $kit['entfernt']));
                    break;

                case 'reconcile_payments':
                    // Gebucht wird nichts (lib/payment.php). Eine gescheiterte
                    // Zahlung zählt als Fehler: die Rechnung ist unbezahlt, die
                    // Ware vielleicht schon unterwegs — das soll jemand sehen.
                    $zahlungen = paymentsReconcile($db);
                    $anzahl = array_count_values(array_column($zahlungen, 'result'));
                    $auffaellig = [];
                    foreach ($zahlungen as $zahlung) {
                        $zeile = 'Rechnung '.$zahlung['invnumber'].': '.$zahlung['result'];
                        if ('bezahlt' === $zahlung['result']) {
                            $zeile .= ' — Zahlungseingang von Hand buchen';
                        } elseif ('offen' !== $zahlung['result']) {
                            $auffaellig[] = 'Rechnung '.$zahlung['invnumber'].' '.$zahlung['result'];
                            $zeile .= isset($zahlung['detail']) ? ' ('.$zahlung['detail'].')' : '';
                        }
                        if ('gescheitert' === $zahlung['result']) {
                            writeLog('[SHOP] PayPal-Zahlung gescheitert: Rechnung '.$zahlung['invnumber'], true, DLOG_ERR);
                        }
                        $sagen($zeile);
                    }
                    $stand = sprintf('%d geprüft, %d bezahlt, %d offen',
                        count($zahlungen), $anzahl['bezahlt'] ?? 0, $anzahl['offen'] ?? 0);
                    if ($auffaellig) {
                        $bilanz['fehler']++;
                        shopJobResult($db, $id, 'Fehler: '.$stand.' — '.implode(', ', $auffaellig));
                    } else {
                        shopJobResult($db, $id, 'ok: '.$stand);
                    }
                    break;

                default:
                    shopJobResult($db, $id, 'Fehler: unbekannte Funktion '.$auftrag['function']);
                    $bilanz['fehler']++;
            }
        } catch (Throwable $e) {
            $bilanz['fehler']++;
            $sagen('Auftrag '.$id.' fehlgeschlagen: '.$e->getMessage());
            shopJobResult($db, $id, 'Fehler: '.$e->getMessage());
        }
    }

    return $bilanz;
}
