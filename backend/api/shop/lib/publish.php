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
    // Ein absoluter Pfad ist hier ein Missverständnis, kein Sonderfall: früher
    // fiel der Schrägstrich weg, und aus '/var/www/inhalt' wurde stillschweigend
    // '<webseite>/var/www/inhalt'. Die Meldung darüber nannte dann ein
    // Verzeichnis, das niemand eingetragen hat.
    if (str_starts_with($relativ, '/')) {
        throw new ApiError(
            'SHOP_PATH_ABSOLUTE',
            'Hier gehört ein Pfad relativ zum Webseiten-Verzeichnis hin, ohne führenden Schrägstrich: '.$relativ
        );
    }

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
 * Wurzel aller Webseiten-Verzeichnisse
 *
 * Je Mandant, denn jede Firma hat ihre eigene Webseite: Einstellung
 * shop_sites_dir, aenderbar in der Firmenkonfiguration unter Shop.
 *
 * Steht in der settings.ini ebenfalls ein shop_sites_dir, wirkt es als Riegel:
 * das eingestellte Verzeichnis muss dann darunter liegen. So kann ein
 * Administrator die Grenze festlegen, ohne dass die Erweiterung ohne
 * settings.ini unbrauchbar waere.
 *
 * @param object $db Company-Datenbankverbindung
 * @return string
 * @throws ApiError SHOP_SITES_DIR_MISSING, SHOP_SITES_DIR_OUTSIDE_LIMIT
 */
function shopSitesRoot($db): string {
    // Der leere Fall muss vor realpath() abgefangen werden: realpath('') gibt
    // das Arbeitsverzeichnis zurueck, und eine nicht gesetzte Einstellung
    // waere damit stillschweigend das Verzeichnis des Servers.
    $eingestellt = shopConfigValue($db, 'shop_sites_dir');
    $echt = '' === $eingestellt ? false : realpath($eingestellt);

    if (false === $echt) {
        throw new ApiError(
            'SHOP_SITES_DIR_MISSING',
            "Die Shop-Einstellung '".shopConfigLabel('shop_sites_dir')."' ist nicht gesetzt oder das Verzeichnis gibt es nicht"
        );
    }

    $riegel = defined('OSERP_SHOP_SITES_DIR') ? (string)OSERP_SHOP_SITES_DIR : '';
    if ('' !== $riegel) {
        $riegelEcht = realpath($riegel);
        if (false === $riegelEcht || ($echt !== $riegelEcht && !str_starts_with($echt.'/', $riegelEcht.'/'))) {
            throw new ApiError(
                'SHOP_SITES_DIR_OUTSIDE_LIMIT',
                'Das eingestellte Verzeichnis liegt nicht unterhalb von shop_sites_dir aus der settings.ini: '.$echt
            );
        }
    }

    return $echt;
}

/** Verzeichnis des Hugo-Projekts dieses Mandanten */
function shopSiteDir($db, bool $anlegen = false): string {
    return shopPathUnder(shopSitesRoot($db), shopConfigValue($db, 'shop_site_dir'), $anlegen);
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

// Höchstzahl Fehlerzeilen, die ein einzelner Auftrag im Wortlaut meldet. Der
// Zähler läuft weiter, nur der Text wird nicht wiederholt: ein Grund, der alle
// Artikel trifft, füllte sonst die Meldungsliste mit Tausenden gleicher Zeilen.
const SHOP_MELDUNGEN_JE_AUFTRAG = 20;

// Name des Programms, das die Webseite baut. Fest kodiert: eingestellt wird
// nur das Verzeichnis, damit sich über die Firmenkonfiguration kein anderes
// Programm unterschieben lässt.
const SHOP_PUBLISH_BINARY = 'hugo';

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
 * @param array|null $nurIds nur diese Auftragsnummern, null = alle offenen
 * @return array
 */
function shopOpenJobs($db, int $limit = 500, ?array $nurIds = null): array {
    // Nur die eigenen Funktionen: die Tabelle teilt sich OSERP mit dem Laeufer
    // der Bridge, und jeder der beiden vermerkt fremde Auftraege sonst als
    // Fehler.
    // Leere Auswahl heißt "alle offenen". NULLIF haelt den leeren Fall aus
    // der Umwandlung heraus: string_to_array('', ',')::int[] scheiterte sonst.
    $ids = null === $nurIds ? '' : implode(',', array_map('intval', $nurIds));

    return $db->getAll(
        "SELECT id, function, partnumber, param
           FROM batchjob_hugoshop
          WHERE result IS NULL
            AND function = ANY(string_to_array(:funktionen, ','))
            AND ('' = :ids_alle OR id = ANY(string_to_array(NULLIF(:ids, ''), ',')::int[]))
          ORDER BY id
          LIMIT :limit",
        [
            ':limit'      => $limit,
            ':funktionen' => implode(',', shopJobFunctions()),
            ':ids_alle'   => $ids,
            ':ids'        => $ids,
        ]
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
 * Aufbewahrungsfrist erledigter Aufträge in Tagen
 *
 * Aus der Shop-Einstellung shop_job_retention_days des Mandanten. 0 heißt:
 * nichts automatisch löschen — dann bleibt nur der Knopf "Aufräumen" im
 * Admin-Panel.
 *
 * @param object $db Company-Datenbankverbindung
 * @return int Tage, 0 fuer "nie"
 */
function shopJobRetentionDays($db): int {
    $tage = shopConfigInt($db, 'shop_job_retention_days', 30);

    return $tage > 0 ? $tage : 0;
}

/**
 * Löscht einzelne Aufträge
 *
 * Für den Benutzer, der einen fehlgeschlagenen Auftrag gelesen hat und ihn aus
 * der Liste haben will — und für offene, die niemand mehr ausgeführt haben
 * will. Gelöscht wird, was ausgewählt war; ein offener Auftrag ist danach
 * schlicht nicht mehr vorgemerkt.
 *
 * Fremde Auftragsarten bleiben unangetastet: die Tabelle stammt aus der
 * Bridge, und was nicht aus dieser Erweiterung kommt, gehört ihr auch nicht.
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $ids Auftragsnummern
 * @return int Zahl der gelöschten Zeilen
 */
function shopDeleteJobs($db, array $ids): int {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
    if (!$ids) {
        return 0;
    }

    $zeile = $db->getOne(
        "WITH weg AS (
             DELETE FROM batchjob_hugoshop
              WHERE function = ANY(string_to_array(:funktionen, ','))
                AND id = ANY(string_to_array(:ids, ',')::int[])
             RETURNING id)
         SELECT count(*)::int AS anzahl FROM weg",
        [
            ':funktionen' => implode(',', shopJobFunctions()),
            ':ids'        => implode(',', $ids),
        ]
    );

    return $zeile ? (int)$zeile['anzahl'] : 0;
}

/**
 * Löscht erledigte Aufträge
 *
 * Nur erfolgreiche: ihr Ergebnis beginnt mit "ok" (siehe shopJobResult).
 * Fehlgeschlagene bleiben stehen, sonst wäre die Spur weg, bevor jemand sie
 * gesehen hat. Offene ebenfalls, und fremde Auftragsarten auch — die Tabelle
 * stammt aus der Bridge.
 *
 * Zeilen ohne itime stammen aus der Zeit vor der Spalte und gelten als alt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int|null $tage nur älter als so viele Tage; null = alle erfolgreichen
 * @return int Zahl der gelöschten Zeilen
 */
function shopCleanupJobs($db, ?int $tage = null): int {
    if (null !== $tage && $tage <= 0) {
        return 0;
    }

    $zeile = $db->getOne(
        "WITH weg AS (
             DELETE FROM batchjob_hugoshop
              WHERE result IS NOT NULL
                AND result LIKE 'ok%'
                AND function = ANY(string_to_array(:funktionen, ','))
                AND (1 = :alle
                     OR itime IS NULL
                     OR itime < now() - (:tage * interval '1 day'))
             RETURNING id)
         SELECT count(*)::int AS anzahl FROM weg",
        [
            ':funktionen' => implode(',', shopJobFunctions()),
            ':alle'       => null === $tage ? 1 : 0,
            ':tage'       => (int)($tage ?? 0),
        ]
    );

    return $zeile ? (int)$zeile['anzahl'] : 0;
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
 * @param array|null $nurIds nur diese Auftragsnummern, null = alle offenen
 * @return array jobs, seiten, entfernt, fehler, kit (Änderungen am Webseiten-Paket)
 */
function shopRunJobs($db, ?callable $melden = null, int $limit = 500, ?array $nurIds = null): array {
    $sagen = $melden ?? function (string $zeile) {};
    $bilanz = ['jobs' => 0, 'seiten' => 0, 'entfernt' => 0, 'fehler' => 0, 'kit' => 0, 'fehler_texte' => []];

    // Fehler werden nicht nur gezählt, sondern im Wortlaut gesammelt: das
    // Admin-Panel zeigt sie nach "Jetzt ausführen" an, sonst stünde dort nur
    // eine Zahl. Der Läufer schreibt sie ohnehin auf die Ausgabe.
    $fehler = function (string $zeile) use ($sagen, &$bilanz) {
        $bilanz['fehler']++;
        $bilanz['fehler_texte'][] = $zeile;
        $sagen($zeile);
    };

    foreach (shopOpenJobs($db, $limit, $nurIds) as $auftrag) {
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
                    $gescheitert = 0;
                    $ersterFehler = '';
                    foreach (shopListedParts($db) as $artikel) {
                        try {
                            shopWriteProductPage($db, (int)$artikel['id']);
                            $anzahl++;
                        } catch (ApiError $e) {
                            $gescheitert++;
                            if ('' === $ersterFehler) {
                                $ersterFehler = $e->getMessage();
                            }
                            // Nur die ersten Fehler im Wortlaut: trifft der
                            // Grund alle Artikel — ein fehlendes Verzeichnis
                            // etwa —, kämen sonst Tausende gleicher Zeilen.
                            if ($gescheitert <= SHOP_MELDUNGEN_JE_AUFTRAG) {
                                $fehler('Artikel '.$artikel['partnumber'].': '.$e->getMessage());
                            } else {
                                $bilanz['fehler']++;
                            }
                        }
                    }
                    $bilanz['seiten'] += $anzahl;
                    $stand = sprintf('%d Seiten geschrieben, %d fehlgeschlagen', $anzahl, $gescheitert);
                    if ($gescheitert > SHOP_MELDUNGEN_JE_AUFTRAG) {
                        $sagen(sprintf('… und %d weitere Artikel, nicht einzeln aufgeführt',
                            $gescheitert - SHOP_MELDUNGEN_JE_AUFTRAG));
                    }
                    $sagen($stand);
                    // Ein Auftrag, bei dem kein Artikel durchkam, ist kein
                    // erfolgreicher Auftrag — sonst stünde ein grüner Haken an
                    // einem Lauf, der nichts zustande gebracht hat.
                    shopJobResult($db, $id, $gescheitert > 0
                        ? 'Fehler: '.$stand.' — '.$ersterFehler
                        : 'ok: '.$anzahl.' Seiten');
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
                        $fehler('Zahlungsabgleich: '.$stand.' — '.implode(', ', $auffaellig));
                        shopJobResult($db, $id, 'Fehler: '.$stand.' — '.implode(', ', $auffaellig));
                    } else {
                        shopJobResult($db, $id, 'ok: '.$stand);
                    }
                    break;

                default:
                    $fehler('Auftrag '.$id.': unbekannte Funktion '.$auftrag['function']);
                    shopJobResult($db, $id, 'Fehler: unbekannte Funktion '.$auftrag['function']);
            }
        } catch (Throwable $e) {
            $fehler('Auftrag '.$id.' fehlgeschlagen: '.$e->getMessage());
            shopJobResult($db, $id, 'Fehler: '.$e->getMessage());
        }
    }

    return $bilanz;
}

// ── Sperre und Lauf ──
//
// Aufträge, Paket und Webseite fasst immer nur einer an: der Läufer im Cron
// und "Jetzt ausführen" im Admin-Panel. Zwei gleichzeitige Läufe würden
// denselben Auftrag doppelt erledigen, sich im Paket die Dateien wegräumen
// und — am teuersten — zweimal bauen: der Bau löscht das ausgelieferte
// Verzeichnis.

/**
 * Nimmt die Sperre für die Veröffentlichung
 *
 * Eine Beratungssperre (advisory lock) in der Datenbank des Mandanten. Sie
 * gilt über Prozesse, Benutzer und Rechner hinweg — anders als die Datei in
 * backend/tmp, die nur zwei Läufer auf demselben Rechner auseinanderhält.
 * Gesperrt wird dabei weder Tabelle noch Zeile: wer diesen Schlüssel nicht
 * anfragt, merkt nichts davon.
 *
 * Der Schlüssel ist zweiteilig, damit er niemandem sonst in die Quere kommt.
 * Jeder Mandant hat seine eigene Datenbank, und Beratungssperren gelten je
 * Datenbank — Mandanten berühren sich also nicht.
 *
 * PHP-FPM hält die Verbindung über die Anfrage hinaus offen
 * (PDO::ATTR_PERSISTENT), und die Sperre hängt an der Verbindung, nicht an
 * der Anfrage. Deshalb gibt sie ein Aufräumer beim Beenden frei, falls der
 * reguläre Weg ausfällt.
 *
 * @param object $db Company-Datenbankverbindung
 * @return bool false, wenn schon jemand arbeitet
 */
function shopPublishLock($db): bool {
    static $aufraeumerAngemeldet = false;

    $zeile = $db->getOne("SELECT pg_try_advisory_lock(hashtext('oserp'), hashtext('shop_publish')) AS genommen");
    $genommen = in_array($zeile['genommen'] ?? null, [true, 't', '1', 1], true);

    if ($genommen && !$aufraeumerAngemeldet) {
        $aufraeumerAngemeldet = true;
        register_shutdown_function(static function () use ($db) { shopPublishUnlock($db); });
    }

    return $genommen;
}

/**
 * Gibt die Sperre frei
 *
 * Mehrfach aufrufbar: hält die Verbindung sie nicht mehr, meldet PostgreSQL
 * das nur zurück. Ist die Verbindung schon zu, ist die Sperre ohnehin weg.
 *
 * @param object $db Company-Datenbankverbindung
 * @return void
 */
function shopPublishUnlock($db): void {
    try {
        $db->getOne("SELECT pg_advisory_unlock(hashtext('oserp'), hashtext('shop_publish')) AS frei");
    } catch (Throwable $e) {
        // Verbindung fort, Sperre fort — nichts zu tun
    }
}

/**
 * Das Programm, das die Webseite baut
 *
 * Eingestellt wird nur das Verzeichnis (shop_publish_command_path); der Name
 * der Datei steht fest (SHOP_PUBLISH_BINARY) und wird angehängt. So lässt sich
 * über die Einstellung kein anderes Programm unterschieben. Ist die Einstellung
 * des Mandanten leer, springt der gleichnamige Eintrag aus der settings.ini
 * ein — als Rückfall, nicht als Vorrang.
 *
 * Geprüft wird vor jedem Bau: absoluter Pfad, kein Leerraum (der deutete auf
 * angehängte Argumente hin, die gehören nicht hierher), vorhandenes
 * Verzeichnis, darin eine vorhandene und ausführbare Datei.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array pfad (leer, wenn nichts eingestellt oder ungültig), quelle, fehler (leer, wenn in Ordnung)
 */
function shopPublishProgram($db): array {
    $ausEinstellung = trim(shopConfigValue($db, 'shop_publish_command_path'));
    $ausIni = defined('OSERP_SHOP_PUBLISH_COMMAND_PATH') ? trim((string)OSERP_SHOP_PUBLISH_COMMAND_PATH) : '';
    $verzeichnis = '' !== $ausEinstellung ? $ausEinstellung : $ausIni;
    $quelle = '' !== $ausEinstellung ? shopConfigLabel('shop_publish_command_path') : 'settings.ini';
    $ergebnis = ['pfad' => '', 'quelle' => $quelle, 'fehler' => ''];

    if ('' === $verzeichnis) {
        return $ergebnis;
    }

    $pfad = rtrim($verzeichnis, '/').'/'.SHOP_PUBLISH_BINARY;

    if ('/' !== $verzeichnis[0]) {
        $ergebnis['fehler'] = "Kein absoluter Pfad ($quelle): $verzeichnis";
    } elseif (1 === preg_match('/\s/', $verzeichnis)) {
        $ergebnis['fehler'] = "Nur das Verzeichnis, ohne Argumente ($quelle): $verzeichnis";
    } elseif (!is_dir($verzeichnis)) {
        $ergebnis['fehler'] = is_file($verzeichnis)
            ? "Nur das Verzeichnis eintragen, nicht das Programm selbst ($quelle): $verzeichnis"
            : "Verzeichnis nicht gefunden ($quelle): $verzeichnis";
    } elseif (!is_file($pfad)) {
        $ergebnis['fehler'] = "Im Verzeichnis liegt kein ".SHOP_PUBLISH_BINARY." ($quelle): $verzeichnis";
    } elseif (!is_executable($pfad)) {
        $ergebnis['fehler'] = "Programm nicht ausführbar ($quelle): $pfad";
    } else {
        $ergebnis['pfad'] = $pfad;
    }

    return $ergebnis;
}

/**
 * Die Befehlszeile, die die Webseite baut
 *
 * Zusammengesetzt aus dem geprüften Programm und festen Argumenten — nichts
 * davon ist frei eingegebener Text, und der Pfad geht maskiert hinein.
 * Ausgeführt wird sie im Verzeichnis der Webseite; Hugo schreibt dann nach
 * public/ darunter.
 *
 * Beide Wege — der Läufer im Cron und "Jetzt ausführen" im Admin-Panel —
 * bauen über shopPublishRun() und damit über diese Funktion.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array befehl (leer, wenn kein Programm eingestellt), fehler (leer, wenn in Ordnung)
 */
function shopPublishCommand($db): array {
    $programm = shopPublishProgram($db);
    if ('' === $programm['pfad']) {
        return ['befehl' => '', 'fehler' => $programm['fehler']];
    }

    $befehl = escapeshellarg($programm['pfad']);
    if (shopConfigBool($db, 'shop_publish_clean_destination', true)) {
        $befehl .= ' --cleanDestinationDir';
    }

    return ['befehl' => $befehl, 'fehler' => ''];
}

// ── Lauf im Hintergrund ──
//
// "Jetzt ausführen" im Admin-Panel wartet nicht mehr auf den Lauf. Es startet
// den Läufer (tools/shop-publish.php) als eigenen Prozess und antwortet sofort;
// die Oberfläche fragt danach den Stand ab. So hängt keine Anfrage minutenlang,
// und kein Proxy bricht sie nach 60 Sekunden ab.
//
// Der Stand liegt in drei Dateien je Mandant unter backend/tmp/ — dort, wo der
// Läufer auch seine Sperrdatei hat:
//
//   shop-publish-<db>.json   angefordert, begonnen, beendet, Bilanz
//   shop-publish-<db>.log    Meldungen des letzten Laufs
//   shop-publish-<db>.out    Ausgabe des zuletzt vom Panel gestarteten Prozesses
//                            (nur Fehlerausgabe; hilft, wenn er abstürzt)
//
// Ob gerade ein Lauf arbeitet, sagt allein die Beratungssperre in der
// Datenbank. Die Dateien erzählen nur, was war.

/** Sekunden, die ein gestarteter Prozess hat, um die Sperre zu nehmen */
const SHOP_PUBLISH_START_FRIST = 30;

/** Zeilen des Protokolls, die die Oberfläche höchstens bekommt */
const SHOP_PUBLISH_PROTOKOLL_ZEILEN = 300;

/**
 * Dateien, in denen der Stand der Veröffentlichung liegt
 *
 * @param object $db Company-Datenbankverbindung
 * @return array status, log, out, lock — absolute Pfade
 */
function shopPublishStateFiles($db): array {
    $verzeichnis = __DIR__.'/../../../tmp';
    if (!is_dir($verzeichnis)) {
        @mkdir($verzeichnis, 0775, true);
    }
    $verzeichnis = realpath($verzeichnis) ?: $verzeichnis;

    // Der Name der Datenbank unterscheidet die Mandanten — wie bei der
    // Sperrdatei des Läufers
    $zeile = $db->getOne("SELECT current_database() AS name");
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)($zeile['name'] ?? 'oserp'));
    $basis = $verzeichnis.'/shop-publish-'.$name;

    return [
        'status' => $basis.'.json',
        'log'    => $basis.'.log',
        'out'    => $basis.'.out',
        'lock'   => $basis.'.lock',
    ];
}

/**
 * Liest den gespeicherten Stand
 *
 * @param string $datei Pfad der Statusdatei
 * @return array leer, wenn es sie nicht gibt oder sie unlesbar ist
 */
function shopPublishReadState(string $datei): array {
    $inhalt = is_file($datei) ? @file_get_contents($datei) : false;
    $werte = false === $inhalt ? null : json_decode($inhalt, true);
    return is_array($werte) ? $werte : [];
}

/**
 * Schreibt den Stand, indem die neuen Werte über die alten gelegt werden
 *
 * Über eine Zwischendatei und rename(): wer gerade liest, sieht entweder den
 * alten oder den neuen Stand, nie eine halbe Datei.
 *
 * @param string $datei Pfad der Statusdatei
 * @param array $werte zu setzende Felder; null entfernt ein Feld
 * @param bool $neu alten Stand verwerfen statt zu ergänzen
 * @return void
 */
function shopPublishWriteState(string $datei, array $werte, bool $neu = false): void {
    $stand = $neu ? [] : shopPublishReadState($datei);
    foreach ($werte as $schluessel => $wert) {
        if (null === $wert) {
            unset($stand[$schluessel]);
        } else {
            $stand[$schluessel] = $wert;
        }
    }

    $zwischen = $datei.'.'.getmypid().'.tmp';
    if (false !== @file_put_contents($zwischen, json_encode($stand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
        @chmod($zwischen, 0664);
        @rename($zwischen, $datei);
    }
}

/**
 * Arbeitet gerade ein Lauf — egal, wer ihn gestartet hat?
 *
 * Schaut in pg_locks nach, ob jemand die Beratungssperre hält, ohne sie selbst
 * anzufassen. pg_try_advisory_lock() mit sofortiger Freigabe wäre einfacher,
 * nähme einem gerade startenden Läufer aber für einen Augenblick die Sperre
 * weg — er gäbe dann auf, und die Aufträge blieben liegen.
 *
 * Die Schlüssel sind int4 und stehen in pg_locks als oid; der Umweg über
 * ::oid macht auch negative hashtext()-Werte vergleichbar.
 *
 * @param object $db Company-Datenbankverbindung
 * @return bool
 */
function shopPublishRunning($db): bool {
    $zeile = $db->getOne(
        "SELECT EXISTS (
             SELECT 1 FROM pg_locks
              WHERE locktype = 'advisory'
                AND database = (SELECT oid FROM pg_database WHERE datname = current_database())
                AND classid = hashtext('oserp')::oid
                AND objid = hashtext('shop_publish')::oid
                AND objsubid = 2
                AND granted
         ) AS laeuft"
    );

    return in_array($zeile['laeuft'] ?? null, [true, 't', '1', 1], true);
}

/**
 * Das Kommandozeilen-PHP für den Läufer
 *
 * Unter PHP-FPM zeigt PHP_BINARY auf php-fpm, nicht auf das PHP für die
 * Kommandozeile. Gesucht wird deshalb neben PHP_BINDIR nach php<Version> und
 * php; im eingebauten Entwicklungsserver ist PHP_BINARY bereits das richtige.
 *
 * @return array pfad (leer, wenn keines gefunden), fehler
 */
function shopPhpCli(): array {
    $kandidaten = [];
    if (in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        $kandidaten[] = PHP_BINARY;
    }
    $kandidaten[] = PHP_BINDIR.'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    $kandidaten[] = PHP_BINDIR.'/php';
    $kandidaten[] = '/usr/bin/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    $kandidaten[] = '/usr/bin/php';

    foreach (array_unique(array_filter($kandidaten)) as $pfad) {
        if (is_file($pfad) && is_executable($pfad)) {
            return ['pfad' => $pfad, 'fehler' => ''];
        }
    }

    return ['pfad' => '', 'fehler' => 'Kein Kommandozeilen-PHP gefunden (gesucht: '.implode(', ', array_unique(array_filter($kandidaten))).')'];
}

/**
 * Startet den Läufer als eigenen Prozess und kehrt sofort zurück
 *
 * Die Befehlszeile besteht nur aus festen Teilen und Zahlen: Pfad zum PHP,
 * Pfad zum Läufer, Name der Datenbank, Auftragsnummern — alles maskiert.
 * setsid löst den Prozess von PHP-FPM, damit er weiterläuft, wenn der
 * Arbeitsprozess der Anfrage beendet oder neu gestartet wird.
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $ids Auftragsnummern; leer bedeutet alle offenen
 * @return array pid (0, wenn unbekannt), fehler (leer, wenn gestartet)
 */
function shopPublishStartBackground($db, array $ids): array {
    if (!function_exists('shell_exec')) {
        return ['pid' => 0, 'fehler' => 'shell_exec() ist abgeschaltet — der Läufer lässt sich nicht aus dem Panel starten.'];
    }

    $php = shopPhpCli();
    if ('' !== $php['fehler']) {
        return ['pid' => 0, 'fehler' => $php['fehler']];
    }

    $laeufer = realpath(__DIR__.'/../../../../tools/shop-publish.php');
    if (false === $laeufer) {
        return ['pid' => 0, 'fehler' => 'Läufer nicht gefunden: tools/shop-publish.php'];
    }

    $dateien = shopPublishStateFiles($db);
    $datenbank = (string)($db->getOne("SELECT current_database() AS name")['name'] ?? '');
    $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));

    $befehl = escapeshellarg($php['pfad']).' '.escapeshellarg($laeufer)
            .' --db='.escapeshellarg($datenbank)
            .($ids ? ' --ids='.escapeshellarg(implode(',', $ids)) : '')
            .' --quiet';

    // Vor dem Start vermerken: bis der Läufer die Sperre hat, vergehen einige
    // hundert Millisekunden. Ohne diesen Vermerk sähe die erste Abfrage keinen
    // Lauf und meldete ihn als beendet.
    shopPublishWriteState($dateien['status'], ['requested' => date(DATE_ATOM), 'pid' => null]);

    $setsid = trim((string)@shell_exec('command -v setsid 2>/dev/null'));
    $bash   = trim((string)@shell_exec('command -v bash 2>/dev/null'));
    $start  = ('' !== $setsid ? 'exec '.escapeshellarg($setsid).' ' : 'exec nohup ')
            .$befehl.' > '.escapeshellarg($dateien['out']).' 2>&1 < /dev/null';

    // Geerbte Deskriptoren schließen. Ein Kindprozess erbt sonst die Sockets
    // des Webservers — den Horch-Socket des Entwicklungsservers oder den von
    // PHP-FPM — und hielte sie fest, solange der Lauf dauert: der Port bliebe
    // belegt, ein Neustart des Servers schlüge fehl. /bin/sh (dash) kann nur
    // Deskriptoren bis 9 schließen, deshalb bash, wenn vorhanden.
    if ('' !== $bash) {
        $start = escapeshellarg($bash).' -c '.escapeshellarg(
            'for d in /proc/$$/fd/*; do n=${d##*/}; '
            .'[ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-" 2>/dev/null; done; '
            .$start
        );
    }

    $zeile = 'cd '.escapeshellarg(dirname($laeufer, 2)).' && '.$start.' & echo $!';

    $pid = (int)trim((string)@shell_exec($zeile));
    if ($pid <= 0) {
        shopPublishWriteState($dateien['status'], ['requested' => null]);
        return ['pid' => 0, 'fehler' => 'Der Läufer ließ sich nicht starten.'];
    }

    shopPublishWriteState($dateien['status'], ['pid' => $pid]);
    return ['pid' => $pid, 'fehler' => ''];
}

/**
 * Stand der Veröffentlichung für die Oberfläche
 *
 * running: ein Lauf arbeitet (Sperre gehalten) oder ein eben gestarteter
 * Prozess ist noch dabei, sie zu nehmen. aborted: ein Lauf hat begonnen und
 * nicht zu Ende gefunden, oder ein gestarteter Prozess kam nie an — dann
 * steht die Ausgabe des Prozesses dabei.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array
 */
function shopPublishStatus($db): array {
    $dateien = shopPublishStateFiles($db);
    $stand = shopPublishReadState($dateien['status']);
    $jetzt = time();

    $zeit = fn($schluessel) => isset($stand[$schluessel]) ? (strtotime((string)$stand[$schluessel]) ?: 0) : 0;
    $angefordert = $zeit('requested');
    $begonnen    = $zeit('started');
    $beendet     = $zeit('finished');

    $sperre = shopPublishRunning($db);

    // Angefordert, aber noch nicht begonnen — innerhalb der Frist gilt das als
    // "startet", danach als gescheitert
    $wartetAufStart = $angefordert > 0 && $begonnen < $angefordert;
    $startet = !$sperre && $wartetAufStart && ($jetzt - $angefordert) < SHOP_PUBLISH_START_FRIST;

    $abgebrochen = !$sperre && !$startet && (
        ($begonnen > 0 && $beendet < $begonnen)
        || $wartetAufStart
    );

    // Solange ein angeforderter Lauf noch nicht begonnen hat, gehören Protokoll
    // und Bilanz dem vorigen — die Oberfläche soll sie dann nicht zeigen
    if ($startet) {
        return [
            'running' => true, 'starting' => true, 'aborted' => false,
            'requested' => $stand['requested'] ?? null, 'started' => null, 'finished' => null,
            'summary' => null, 'lines' => [], 'error_lines' => [], 'output' => [],
        ];
    }

    $zeilen = [];
    if (is_file($dateien['log'])) {
        $zeilen = @file($dateien['log'], FILE_IGNORE_NEW_LINES) ?: [];
        $zeilen = array_slice($zeilen, -SHOP_PUBLISH_PROTOKOLL_ZEILEN);
    }

    $ausgabe = [];
    if ($abgebrochen && is_file($dateien['out'])) {
        $ausgabe = array_slice(@file($dateien['out'], FILE_IGNORE_NEW_LINES) ?: [], -30);
    }

    return [
        'running'     => $sperre || $startet,
        'starting'    => $startet,
        'aborted'     => $abgebrochen,
        'requested'   => $stand['requested'] ?? null,
        'started'     => $stand['started'] ?? null,
        'finished'    => $stand['finished'] ?? null,
        'summary'     => $stand['summary'] ?? null,
        'lines'       => $zeilen,
        'error_lines' => $stand['summary']['error_lines'] ?? [],
        'output'      => $ausgabe,
    ];
}

/**
 * Ein vollständiger Lauf: Aufträge, Paket, Kategorieübersicht, Bau
 *
 * Dieselbe Reihenfolge für beide Wege, den Läufer und das Admin-Panel. Wer
 * die Sperre nicht bekommt, tut nichts und meldet das — der laufende Prozess
 * nimmt die offenen Aufträge ohnehin mit.
 *
 * @param object $db Company-Datenbankverbindung
 * @param callable|null $melden Fortschritt, bekommt je eine Zeile Text
 * @param int $limit Höchstzahl Aufträge
 * @param array|null $nurIds nur diese Auftragsnummern, null = alle offenen
 * @param bool $bauen Webseite bauen, wenn sich etwas geändert hat
 * @param callable|null $beginn wird gerufen, sobald die Sperre genommen ist —
 *                              erst dann gehört der Lauf wirklich diesem Prozess
 *                              (der Läufer legt dort sein Protokoll an)
 * @return array gesperrt, jobs, seiten, entfernt, fehler, fehler_texte, kit, kategorien, gebaut, bau_code, bau_ausgabe
 */
function shopPublishRun($db, ?callable $melden = null, int $limit = 500, ?array $nurIds = null, bool $bauen = true, ?callable $beginn = null): array {
    $sagen = $melden ?? function (string $zeile) {};
    $bilanz = ['gesperrt' => false, 'jobs' => 0, 'seiten' => 0, 'entfernt' => 0, 'fehler' => 0,
               'kit' => 0, 'kategorien' => 0, 'gebaut' => false, 'bau_code' => 0, 'bau_ausgabe' => [],
               'fehler_texte' => []];

    // Fehler werden nicht nur gezählt, sondern im Wortlaut gesammelt: das
    // Admin-Panel zeigt sie nach "Jetzt ausführen" an, sonst stünde dort nur
    // eine Zahl. Der Läufer schreibt sie ohnehin auf die Ausgabe.
    $fehler = function (string $zeile) use ($sagen, &$bilanz) {
        $bilanz['fehler']++;
        $bilanz['fehler_texte'][] = $zeile;
        $sagen($zeile);
    };

    if (!shopPublishLock($db)) {
        $bilanz['gesperrt'] = true;
        $sagen('Ein Lauf ist gerade unterwegs — die offenen Aufträge werden dabei miterledigt.');
        return $bilanz;
    }

    if (null !== $beginn) {
        $beginn();
    }

    try {
        $bilanz = array_merge($bilanz, shopRunJobs($db, $sagen, $limit, $nurIds));

        // Das Paket bei jedem Lauf abgleichen, nicht nur nach neuen Seiten:
        // beim ersten Lauf entsteht oserp-shop/ überhaupt erst, und nach einem
        // Update kommt ein neues Bundle an, ohne dass jemand veröffentlicht.
        try {
            $kit = shopSyncKit($db);
            $bilanz['kit'] += shopKitChanges($kit);
            if (shopKitChanges($kit) > 0) {
                $sagen(sprintf('Paket abgeglichen: %d kopiert, %d entfernt%s',
                    $kit['kopiert'], $kit['entfernt'], $kit['config'] ? ', Konfiguration neu' : ''));
            }
        } catch (Throwable $e) {
            $fehler('Paketabgleich fehlgeschlagen: '.$e->getMessage());
        }

        // Die Kategorieübersicht, wenn sich Seiten geändert haben — oder wenn
        // ihre Datei fehlt. Das zweite heilt einen Lauf, in dem der Schritt
        // ausgefallen ist: sonst bliebe die Übersicht weg, bis zufällig wieder
        // eine Seite geschrieben wird.
        $uebersichtFehlt = false;
        try {
            $ziel = shopCategoryGroupsFile($db);
            $uebersichtFehlt = '' !== $ziel && !is_file($ziel);
        } catch (ApiError $e) {
            // Nur das Unterverzeichnis fehlt — dann fehlt auch die Datei.
            // Alles andere (kein Wurzelverzeichnis etwa) meldet schon ein
            // anderer Schritt; hier bliebe es bei einer zweiten Meldung.
            $uebersichtFehlt = 'SHOP_PATH_MISSING' === $e->getId();
        }

        if ($bilanz['seiten'] + $bilanz['entfernt'] > 0 || $uebersichtFehlt) {
            try {
                $übersicht = shopWriteCategoryGroups($db);
                if ($übersicht['changed']) {
                    $bilanz['kategorien'] = 1;
                    $sagen($übersicht['categories'] > 0
                        ? sprintf('Kategorieübersicht geschrieben: %d Kategorien in %d Gruppen', $übersicht['categories'], $übersicht['groups'])
                        : 'Kategorieübersicht entfernt: keine Kategorien');
                }
            } catch (Throwable $e) {
                $fehler('Kategorieübersicht fehlgeschlagen: '.$e->getMessage());
            }
        }

        $geaendert = $bilanz['seiten'] + $bilanz['entfernt'] + $bilanz['kit'] + $bilanz['kategorien'];
        // Die Befehlszeile setzt die Erweiterung selbst zusammen: geprüfter
        // Pfad zum Programm plus feste Argumente (shopPublishCommand).
        $bau = shopPublishCommand($db);
        $befehl = $bau['befehl'];

        if ($bauen && $geaendert > 0 && '' !== $bau['fehler']) {
            $fehler('Die Webseite wurde nicht gebaut: '.$bau['fehler']);
        } elseif ($bauen && $geaendert > 0 && '' !== $befehl) {
            if (!function_exists('exec')) {
                $fehler('exec() ist abgeschaltet — die Webseite wurde nicht gebaut.');
            } else {
                $verzeichnis = shopSiteDir($db);
                $sagen('Baue die Webseite in '.$verzeichnis);

                $ausgabe = [];
                $code = 0;
                exec('cd '.escapeshellarg($verzeichnis).' && '.$befehl.' 2>&1', $ausgabe, $code);
                foreach ($ausgabe as $zeile) {
                    $sagen('  '.$zeile);
                }

                $bilanz['bau_ausgabe'] = $ausgabe;
                $bilanz['bau_code'] = $code;
                $bilanz['gebaut'] = 0 === $code;
                if (0 === $code) {
                    $sagen('Webseite gebaut.');
                } else {
                    $fehler('Der Bau der Webseite ist fehlgeschlagen (Rückgabewert '.$code.').');
                }
            }
        } elseif ($bauen && $geaendert > 0 && '' === $befehl) {
            $sagen('Kein Programm zum Bauen eingestellt — es wurden nur Dateien geschrieben.');
        }
    } finally {
        shopPublishUnlock($db);
    }

    return $bilanz;
}
