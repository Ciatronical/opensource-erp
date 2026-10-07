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

// ── Webseite eines HugoShops (dev/shop-mehrere-kanaele.md, Schritt 3) ──
//
// Jeder HugoShop ist eine eigene Webseite: Verzeichnis, Betriebsart,
// Vorlagensatz, Adressen und HugoCMS-Zugang stehen in den Einstellungen seines
// Kanals. Alle Funktionen, die eine Webseite anfassen, bekommen deshalb die
// Kennung des Kanals ($kanal). Für den ganzen Mandanten gelten nur das
// Wurzelverzeichnis aller Webseiten (shop_sites_dir), das Bau-Programm und
// die Größe der Vorschaubilder.

/**
 * Verzeichnis des Hugo-Projekts eines HugoShops
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @param bool $anlegen fehlendes Verzeichnis anlegen
 * @return string
 */
function shopSiteDir($db, int $kanal, bool $anlegen = false): string {
    // Betriebsart HugoCMS: Die Webseite liegt auf einem anderen Server. Alles,
    // was OSERP erzeugt, landet zuerst in der Bereitstellung und geht von dort
    // an HugoCMS — Seiten, Paket und Kategorieübersicht merken davon nichts.
    if ('hugocms' === shopPublishMode($db, $kanal)) {
        return shopStagingDir($db, $kanal);
    }
    // Leer heißt wie bisher: die Wurzel aller Webseiten selbst. Bei mehreren
    // HugoShops braucht jeder sein eigenes Verzeichnis darunter.
    return shopPathUnder(shopSitesRoot($db), shopChannelValue($db, $kanal, 'site_dir'), $anlegen);
}

/**
 * Betriebsart der Veröffentlichung (dev/shop-hugocms-trennung.md, E8)
 *
 * local: OSERP schreibt in die Webseite und baut selbst — die Webseite liegt
 * auf demselben Server. hugocms: OSERP schreibt in die Bereitstellung,
 * überträgt an HugoCMS und lässt dort bauen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return string local oder hugocms
 */
function shopPublishMode($db, int $kanal): string {
    return 'hugocms' === shopChannelValue($db, $kanal, 'publish_mode', 'local') ? 'hugocms' : 'local';
}

/**
 * Grundname der Dateien, die eine Webseite unter backend/tmp/ hat
 *
 * Bereitstellung und Stand der HugoCMS-Übertragung gehören zur Webseite, nicht
 * zum Lauf — je HugoShop eigene. Vor Schritt 3 gab es sie einmal je Mandant
 * (ohne Kanal im Namen). Der älteste HugoShop übernimmt sie beim ersten Zugriff:
 * die Übertragung an HugoCMS gleicht die Bereitstellung als Abbild ab und
 * entfernt dort, was fehlt — eine neue, leere Bereitstellung räumte sonst die
 * Webseite leer.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @param string $endung Namensteil nach dem Kanal, etwa '-staging'
 * @return string absoluter Pfad
 */
function shopSiteStateFile($db, int $kanal, string $endung): string {
    $basis = substr(shopPublishStateFiles($db)['status'], 0, -strlen('.json'));
    $pfad = $basis.'-'.$kanal.$endung;

    if (!file_exists($pfad) && file_exists($basis.$endung)) {
        $aeltester = $db->getOne("SELECT min(id) AS id FROM sales_channel_shop WHERE type = 'hugoshop'");
        if ((int)($aeltester['id'] ?? 0) === $kanal) {
            @rename($basis.$endung, $pfad);
        }
    }
    return $pfad;
}

/**
 * Bereitstellungsverzeichnis der Betriebsart HugoCMS
 *
 * Unter backend/tmp/, je Mandant und HugoShop — neben Sperre und Stand der
 * Veröffentlichung. Es bleibt zwischen den Läufen erhalten: Es ist das Abbild
 * dessen, was die Webseite von OSERP haben soll, und die Übertragung
 * vergleicht es jedes Mal mit HugoCMS.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return string absoluter Pfad
 */
function shopStagingDir($db, int $kanal): string {
    $verzeichnis = shopSiteStateFile($db, $kanal, '-staging');
    if (!is_dir($verzeichnis) && !@mkdir($verzeichnis, 0775, true) && !is_dir($verzeichnis)) {
        throw new ApiError('SHOP_STAGING_UNAVAILABLE', 'Bereitstellungsverzeichnis lässt sich nicht anlegen: '.$verzeichnis);
    }
    return realpath($verzeichnis) ?: $verzeichnis;
}

/** Zielverzeichnis der Inhaltsdateien eines HugoShops */
function shopContentDir($db, int $kanal, bool $anlegen = false): string {
    // Vorgabe wie im Schema — fehlt die Zeile noch, landeten die Seiten sonst
    // direkt im Verzeichnis der Webseite
    return shopPathUnder(shopSiteDir($db, $kanal, $anlegen),
                         shopChannelValue($db, $kanal, 'content_dir', 'content/de/produkt'), $anlegen);
}

// ── Werte fuer die Vorlage ──

/**
 * Alles, was eine Produktseite braucht
 *
 * Eine Abfrage; die Vorlage bekommt ein einziges Array statt eines Schwarms
 * von Einzelvariablen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop — Preis, Texte, Angebot und Adressen des Kanals
 * @param int $partsId Artikel
 * @return array artikel, shop, betrieb, versand (shop_part_shipping_check:
 *               passt eine Versandart in diesem HugoShop)
 * @throws ApiError PART_NOT_FOUND
 */
function shopPageData($db, int $kanal, int $partsId): array {
    $inklusive = shopConfigBool($db, 'shop_tax_included', false) ? 1 : 0;

    // Steuersatz wie in der Faktura: Buchungsgruppe -> Steuerzone ->
    // Erloeskonto -> Steuerschluessel. Anders als dort nur Schluessel, die
    // schon gelten — ein kuenftiger Satz gehoert noch nicht auf die Seite.
    // Ohne Schluessel ist der Satz 0 und brutto gleich netto.
    //
    // Preis, Bezeichnung und Beschreibung sind die des HugoShops
    // (dev/shop-verkaufskanaele.md); ob der Artikel angeboten wird, sagt
    // seine aktive Kanalzeile.
    $zeile = $db->getOne(
        "SELECT p.id, p.partnumber,
                COALESCE(NULLIF(pc.title, ''), p.description) AS description,
                COALESCE(NULLIF(pc.description, ''), p.notes) AS notes,
                p.unit, p.ean,
                TRUNC(p.onhand) AS onhand, p.obsolete,
                shop_part_available(p.id, CAST(:kanal_verfuegbar AS integer)) AS available,
                COALESCE(p.mtime, p.itime) AS mtime,
                COALESCE(st.rate, 0) AS taxrate,
                CASE WHEN :inklusive_netto = 1
                     THEN ROUND(k.preis / (1 + COALESCE(st.rate, 0)), 2)
                     ELSE ROUND(k.preis, 2) END AS price_net,
                CASE WHEN :inklusive_brutto = 1
                     THEN ROUND(k.preis, 2)
                     ELSE ROUND(k.preis * (1 + COALESCE(st.rate, 0)), 2) END AS price_gross,
                (COALESCE(pc.active, false) AND shop_active_channel_id(CAST(:kanal_aktiv AS integer)) IS NOT NULL) AS listed,
                pe.hugoshop_category, pe.hugoshop_hyperlink, pe.hugoshop_breadcrumbs,
                pe.hugoshop_images, pe.hugoshop_technical_data,
                pe.hugoshop_properties, pe.hugoshop_downloads,
                (SELECT company FROM defaults LIMIT 1) AS firma,
                (SELECT free_shipping_from FROM sales_channel_shop WHERE id = CAST(:kanal_frei AS integer)) AS free_shipping_from,
                -- Versandangaben (dev/shop-versand.md, Schritt 5): Lieferbedingung
                -- als Text für Seite und Kunden, Mindestabnahme
                COALESCE(NULLIF(btrim(dt.description_long), ''), dt.description) AS delivery_term,
                ps.min_qty,
                -- Passt eine Versandart? Ohne passende wird nicht veröffentlicht
                (SELECT row_to_json(v) FROM shop_part_shipping_check(p.id, CAST(:kanal_versand AS integer)) v) AS versand
           FROM parts p
           LEFT JOIN parts_shipping_shop ps ON ps.parts_id = p.id
           LEFT JOIN delivery_terms dt ON dt.id = ps.delivery_term_id
           CROSS JOIN LATERAL (SELECT shop_channel_price(p.id, CAST(:kanal_preis AS integer)) AS preis) k
           LEFT JOIN parts_channel_shop pc ON pc.parts_id = p.id
                                          AND pc.channel_id = CAST(:kanal_zeile AS integer)
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
            ':kanal_verfuegbar' => $kanal,
            ':kanal_aktiv'      => $kanal,
            ':kanal_frei'       => $kanal,
            ':kanal_preis'      => $kanal,
            ':kanal_zeile'      => $kanal,
            ':kanal_versand'    => $kanal,
        ]
    );

    if (!$zeile) {
        throw new ApiError('PART_NOT_FOUND', 'Artikel nicht gefunden: '.$partsId);
    }

    $liste  = fn($wert) => is_array($w = json_decode((string)$wert, true)) ? $w : [];
    $wahr   = fn($wert) => true === $wert || 't' === $wert || '1' === $wert;

    $bilder         = $liste($zeile['hugoshop_images']);
    $downloads      = $liste($zeile['hugoshop_downloads']);
    $bildMuster     = shopChannelValue($db, $kanal, 'images_link');
    $vorschauMuster = shopChannelValue($db, $kanal, 'thumbnails_link');
    $downloadMuster = shopChannelValue($db, $kanal, 'downloads_link', '/downloads/%s');

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
            // bestellbar: nicht veraltet und im HugoShop nicht als nicht
            // verfügbar markiert (shop_part_available)
            'available'   => $wahr($zeile['available']),
            // Lieferbedingung („Versandfertig in 4–8 Wochen"), leer = keine
            'delivery_term' => trim((string)($zeile['delivery_term'] ?? '')),
            // Mindestabnahme, null = keine
            'min_qty'     => null === $zeile['min_qty'] ? null : (float)$zeile['min_qty'],
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
            'base_url'         => shopChannelValue($db, $kanal, 'base_url'),
            'products_link'    => shopChannelValue($db, $kanal, 'products_link'),
            'thumbnails_link'  => shopChannelValue($db, $kanal, 'thumbnails_link'),
            'free_shipping_from' => (float)($zeile['free_shipping_from'] ?? 0),
            'tax_included'     => shopConfigBool($db, 'shop_tax_included', false),
        ],
        'versand' => json_decode((string)($zeile['versand'] ?? ''), true) ?: ['status' => 'ok'],
    ];
}

/**
 * Meldung zur Prüfung der Versandart eines Artikels
 *
 * Für Lauf, Auftragsliste und Artikelkarte: sagt, woran es liegt, mit den
 * Werten, an denen gemessen wurde.
 *
 * @param array $pruefung Zeile aus shop_part_shipping_check(): status, method,
 *              weight, length, width, height, size, girth, weightunit
 * @return string leer bei status ok
 */
function shopShippingCheckText(array $pruefung): string {
    $zahl = fn($wert) => str_replace('.', ',', rtrim(rtrim(number_format((float)$wert, 3, '.', ''), '0'), '.'));

    $werte = [];
    if ((float)($pruefung['weight'] ?? 0) > 0) {
        $werte[] = 'Gewicht '.$zahl($pruefung['weight']).' '.trim((string)($pruefung['weightunit'] ?? ''));
    }
    if (null !== ($pruefung['length'] ?? null)) {
        $werte[] = 'längste Kante '.$zahl($pruefung['length']).' cm';
    }
    if (null !== ($pruefung['width'] ?? null)) {
        $werte[] = 'Breite '.$zahl($pruefung['width']).' cm';
    }
    if (null !== ($pruefung['height'] ?? null)) {
        $werte[] = 'Höhe '.$zahl($pruefung['height']).' cm';
    }
    if (null !== ($pruefung['size'] ?? null)) {
        $werte[] = 'Größe '.$zahl($pruefung['size']).' cm';
    }
    if (null !== ($pruefung['girth'] ?? null)) {
        $werte[] = 'Gurtmaß '.$zahl($pruefung['girth']).' cm';
    }
    $gemessen = $werte ? ' ('.implode(', ', array_map('trim', $werte)).')' : '';

    switch ($pruefung['status'] ?? 'ok') {
        case 'ok':
            return '';
        case 'weight_missing':
            return 'Keine passende Versandart: Der Artikel hat kein Gewicht, die Versandarten brauchen eins';
        case 'assigned_unfit':
            return 'Die zugeordnete Versandart „'.($pruefung['method'] ?? '').'“ passt nicht'.$gemessen
                .' oder hat für diesen HugoShop keine Preisstufe';
        default:
            return 'Keine passende Versandart'.$gemessen
                .': Grenzen und Preisstufen der Versandarten für diesen HugoShop prüfen';
    }
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

/** Menge für die Anzeige: deutsches Zahlenformat, ohne überflüssige Nullen (10, 2,5) */
function shopQuantity($wert): string {
    return rtrim(rtrim(number_format((float)$wert, 3, ',', '.'), '0'), ',');
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
 * @param int $kanal HugoShop
 * @param array $seite Werte aus shopPageData()
 * @return string erzeugt, aktuell, keine Bilder, nicht eingerichtet, Quelle fehlt, Fehler: …
 */
function shopThumbnailFor($db, int $kanal, array $seite): string {
    if (!$seite['shop']['images']) {
        return 'keine Bilder';
    }
    // Die Bilder liegen auf dem Webserver bei HugoCMS (E6), nicht hier
    if ('hugocms' === shopPublishMode($db, $kanal)) {
        return 'bei HugoCMS';
    }
    if ('' === shopChannelValue($db, $kanal, 'images_dir') || '' === shopChannelValue($db, $kanal, 'thumbnails_dir')) {
        return 'nicht eingerichtet';
    }

    try {
        $webseite = shopSiteDir($db, $kanal);
        $name = basename((string)$seite['shop']['images'][0]);

        $quelle = shopPathUnder($webseite, shopChannelValue($db, $kanal, 'images_dir')).'/'.$name;
        if (!is_file($quelle)) {
            return 'Quelle fehlt';
        }

        $ziel = shopPathUnder($webseite, shopChannelValue($db, $kanal, 'thumbnails_dir'), true).'/'.$name;
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
 * @param int $kanal HugoShop — sein Vorlagensatz
 * @param array $seite Werte aus shopPageData()
 * @param string $ausgabe Vorlage ohne Endung, z.B. 'product'
 * @return string
 * @throws ApiError SHOP_TEMPLATE_MISSING
 */
function shopRenderPage($db, int $kanal, array $seite, string $ausgabe = 'product'): string {
    $verzeichnis = shopTemplateDir(shopChannelValue($db, $kanal, 'template_set', 'standard'));
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
 * @param int $kanal HugoShop, in dessen Webseite die Seite kommt
 * @param int $partsId Artikel
 * Ohne passende Versandart (shop_part_shipping_check) wird nicht
 * veröffentlicht: Gibt es die Seite schon, wird sie als Entwurf neu
 * geschrieben — Hugo nimmt sie dann aus der Webseite, und kein Kunde legt den
 * Artikel mehr in einen Warenkorb, der an der Kasse scheitert. Danach scheitert
 * der Auftrag mit der Meldung: SHIPPING_UNFIT_DRAFT, wenn eine Seite zum
 * Entwurf wurde (der Lauf muss dann bauen), sonst SHIPPING_UNFIT.
 *
 * @param bool $entwurf als Entwurf schreiben (V16) — ohne Prüfung der Versandart
 * @return array file, bytes, thumbnail
 * @throws ApiError SHOP_WRITE_FAILED, SHIPPING_UNFIT, SHIPPING_UNFIT_DRAFT
 */
function shopWriteProductPage($db, int $kanal, int $partsId, bool $entwurf = false): array {
    $seite = shopPageData($db, $kanal, $partsId);
    $versandFehler = $entwurf ? '' : shopShippingCheckText($seite['versand']);
    $datei = shopContentDir($db, $kanal, true).'/'.shopPageFileName($seite);

    if ('' !== $versandFehler && !is_file($datei)) {
        throw new ApiError('SHIPPING_UNFIT', $versandFehler);
    }

    $inhalt = shopRenderPage($db, $kanal, $seite);
    if ($entwurf || '' !== $versandFehler) {
        $inhalt = shopPageAsDraft($inhalt);
    }

    $geschrieben = file_put_contents($datei, $inhalt, LOCK_EX);
    if (false === $geschrieben) {
        throw new ApiError('SHOP_WRITE_FAILED', 'Datei nicht schreibbar: '.$datei);
    }

    if ('' !== $versandFehler) {
        throw new ApiError('SHIPPING_UNFIT_DRAFT', $versandFehler.' — die bisherige Seite ist jetzt ein Entwurf');
    }

    return ['file' => $datei, 'bytes' => $geschrieben, 'thumbnail' => shopThumbnailFor($db, $kanal, $seite)];
}

/**
 * Macht aus einer gerenderten Seite einen Entwurf
 *
 * Setzt im Front Matter draft: true. Hugo veröffentlicht Entwürfe nicht —
 * weder der lokale Bau noch HugoCMS bauen mit --buildDrafts. Die Datei bleibt
 * stehen und wird beim Einschalten des HugoShops durch publish_all ersetzt
 * (V16).
 *
 * Geändert wird hier statt in der Vorlage: so gilt es für jeden Vorlagensatz,
 * auch für Kundenkopien, die draft gar nicht kennen.
 *
 * @param string $inhalt Seite mit YAML-Front-Matter zwischen zwei ---
 * @return string
 * @throws ApiError SHOP_PAGE_NO_FRONT_MATTER wenn die Seite keines hat
 */
function shopPageAsDraft(string $inhalt): string {
    if (!preg_match('/\A(\s*---\R)(.*?)(\R---)/s', $inhalt, $treffer, PREG_OFFSET_CAPTURE)) {
        throw new ApiError('SHOP_PAGE_NO_FRONT_MATTER', 'Die Seite hat kein Front Matter — als Entwurf nicht markierbar');
    }
    $kopf = $treffer[2][0];
    $neu = preg_match('/^draft\s*:.*$/m', $kopf)
        ? preg_replace('/^draft\s*:.*$/m', 'draft: true', $kopf)
        : "draft: true\n".$kopf;

    return substr($inhalt, 0, $treffer[2][1]).$neu.substr($inhalt, $treffer[2][1] + strlen($kopf));
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

/** Verzeichnis des Pakets in der Webseite eines HugoShops */
function shopKitDir($db, int $kanal, bool $anlegen = false): string {
    return shopPathUnder(shopSiteDir($db, $kanal, $anlegen), 'oserp-shop', $anlegen);
}

/**
 * Inhalt von oserp-shop/config.json
 *
 * Adresse und Schlüssel für Weiterleiter und 404-Seite. Die Datei liegt
 * außerhalb des Docroots, und Hugo hängt sie nicht ein.
 *
 * JSON statt PHP (dev/shop-hugocms-trennung.md, E9): In der Betriebsart
 * HugoCMS geht das Paket über die HugoCMS-API, und HugoCMS schreibt bewusst
 * kein PHP. Eine Konfiguration, die sich mit jedem neuen Shop-Schlüssel
 * ändert, muss aber übertragen werden können — anders als die beiden
 * PHP-Einstiegspunkte, die man einmal von Hand ablegt.
 *
 * Der Schlüssel ist der des HugoShops: über ihn erkennt der öffentliche
 * Zugang Mandant und Kanal (dev/shop-mehrere-kanaele.md).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return string
 */
function shopKitConfig($db, int $kanal): string {
    $werte = [
        '_hinweis' => 'Von OpensourceERP geschrieben (tools/shop-publish.php) — nicht von Hand ändern.',
        'url'      => shopChannelValue($db, $kanal, 'backend_url'),
        'key'      => shopChannelValue($db, $kanal, 'public_key'),
    ];
    return json_encode($werte, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
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
 * enthaelt — beides nur innerhalb von oserp-shop/. Die config.json bleibt dabei
 * stehen und wird nur neu geschrieben, wenn sich ihr Inhalt aendert. Woraus
 * das Paket besteht, sagt shopKitFiles().
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return array kopiert, entfernt, config (true wenn neu geschrieben)
 * @throws ApiError SHOP_WRITE_FAILED
 */
function shopSyncKit($db, int $kanal): array {
    $bilanz = ['kopiert' => 0, 'entfernt' => 0, 'config' => false];

    $imPaket = shopKitFiles(shopChannelValue($db, $kanal, 'template_set', 'standard'));
    if (!$imPaket) {
        return $bilanz;
    }
    $ziel = shopKitDir($db, $kanal, true);

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
        // Die Konfiguration schreibt der Abgleich unten selbst. Eine alte
        // config.php (vor E9) steht nicht im Paket und fällt hier weg.
        if ('config.json' === $relativ) {
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

    $inhalt = shopKitConfig($db, $kanal);
    $konfiguration = $ziel.'/config.json';
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

// Zusatzangabe (param) eines sync_kit-Auftrags aus „Shop-Benutzerschnittstelle
// installieren“: Paket abgleichen und die Webseite in jedem Fall bauen.
const SHOP_KIT_INSTALL = 'install';

// Mounts, ohne die das Paket in der Webseite nicht ankommt (shop-ui/README.md,
// „Einbindung“): Einstiegspunkte wie /shop-api/, Shortcodes und Partials,
// das Widget-Bündel.
const SHOP_KIT_MOUNTS = ['oserp-shop/static', 'oserp-shop/layouts', 'oserp-shop/assets/shop-ui'];

/**
 * Was an der Einrichtung der Webseite für die Shop-UI noch fehlt
 *
 * Das Paket liegt nach dem Abgleich in oserp-shop/, wirkt aber erst, wenn die
 * Site-Konfiguration es einhängt und params.shopui setzt — sonst baut Hugo
 * fehlerfrei eine Webseite ohne Widgets. Die Konfiguration gehört der
 * Webseite; hier wird nur nachgesehen, nichts geschrieben. Gesucht wird
 * textuell, das genügt für JSON, TOML und YAML gleichermaßen.
 *
 * In der Betriebsart HugoCMS liegt die Webseite nicht auf diesem Rechner —
 * dann gibt es nichts nachzusehen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return array Hinweise im Wortlaut, leer wenn alles da ist
 */
function shopKitSetupHints($db, int $kanal): array {
    if ('hugocms' === shopPublishMode($db, $kanal)) {
        return [];
    }

    $verzeichnis = shopSiteDir($db, $kanal);
    $konfiguration = null;
    foreach (['hugo.json', 'hugo.toml', 'hugo.yaml', 'hugo.yml', 'config.json', 'config.toml', 'config.yaml', 'config.yml'] as $name) {
        if (is_file($verzeichnis.'/'.$name)) {
            $konfiguration = $verzeichnis.'/'.$name;
            break;
        }
    }
    if (null === $konfiguration) {
        return ['Keine Site-Konfiguration (hugo.* oder config.*) in '.$verzeichnis.' gefunden.'];
    }

    $inhalt = (string)@file_get_contents($konfiguration);
    $hinweise = [];
    $fehlend = array_values(array_filter(SHOP_KIT_MOUNTS, fn($quelle) => !str_contains($inhalt, $quelle)));
    if ($fehlend) {
        $hinweise[] = 'In '.basename($konfiguration).' fehlen unter module.mounts: '.implode(', ', $fehlend)
                     .' — ohne sie kommt das Paket nicht in der Webseite an.';
    }
    if (!preg_match('/\bshopui\b/i', $inhalt)) {
        $hinweise[] = 'In '.basename($konfiguration).' fehlt params.shopui — der Seitenkopf zeigt dann die alten Knöpfe statt der Widgets.';
    }
    return $hinweise;
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
// diese zeigt die Auftragsliste.
function shopJobFunctions(): array {
    return ['publish_part', 'publish_all', 'remove_part', 'remove_all', 'draft_all', 'sync_kit', 'reconcile_payments'];
}
//
// Die Tabelle batchjob_hugoshop stammt aus der Bridge, ergaenzt um Kanal,
// Zeitpunkt und Lauf. Ein Auftrag ist offen, solange result NULL ist. Die
// Anwendung schreibt nur Auftraege; geschrieben und gebaut wird auf der
// Kommandozeile (tools/shop-publish.php).

/**
 * Nimmt einen Auftrag an, wenn er nicht schon offen ist
 *
 * Doppelte offene Auftraege waeren sinnlose Arbeit: der Laeufer erzeugt die
 * Seite ohnehin aus dem aktuellen Stand.
 *
 * Der Auftrag gehört einem Verkaufskanal (channels/channels.php); gleiche
 * Aufträge verschiedener Kanäle sind keine Doppel.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $funktion eine Auftragsart des Kanals
 * @param string $partnumber Artikelnummer, leer bei publish_all
 * @param string|null $param Zusatzangabe, etwa der zu loeschende Dateiname
 * @param int $kanal Kennung des Kanals (dev/shop-mehrere-kanaele.md)
 * @return int Auftragsnummer, 0 wenn schon offen oder den Kanal nicht gibt
 */
function shopQueueJob($db, string $funktion, string $partnumber, ?string $param, int $kanal): int {
    // Die Regel steht in der Datenbank (shop_queue_job), weil die Trigger für
    // das automatische Neuschreiben (V22) dieselbe brauchen.
    $zeile = $db->getOne(
        "SELECT shop_queue_job(:function, :partnumber, :param, CAST(:kanal AS integer)) AS id",
        [
            ':function'   => $funktion,
            ':partnumber' => $partnumber,
            ':param'      => $param,
            ':kanal'      => $kanal,
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
    // Nur Auftragsarten, die ein Kanalmodul abarbeitet — eine unbekannte
    // bliebe sonst bei jedem Lauf als Fehler stehen.
    // Leere Auswahl heißt "alle offenen". NULLIF haelt den leeren Fall aus
    // der Umwandlung heraus: string_to_array('', ',')::int[] scheiterte sonst.
    $ids = null === $nurIds ? '' : implode(',', array_map('intval', $nurIds));

    //
    // Ausgewählt wird nach Kanal und Auftragsart (shopChannelJobPairs); channel
    // nennt dem Läufer das zuständige Modul.
    return $db->getAll(
        "SELECT b.id, b.function, b.partnumber, b.param, c.type AS channel, b.channel_id,
                c.name AS channel_name
           FROM batchjob_hugoshop b
           JOIN sales_channel_shop c ON c.id = b.channel_id
          WHERE b.result IS NULL
            AND (c.type || ':' || b.function) = ANY(string_to_array(:paare, ','))
            AND ('' = :ids_alle OR b.id = ANY(string_to_array(NULLIF(:ids, ''), ',')::int[]))
          ORDER BY b.id
          LIMIT :limit",
        [
            ':limit'      => $limit,
            ':paare'      => shopChannelJobPairs(),
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
 * Nur Auftragsarten der Kanalmodule (shopChannelJobPairs).
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
             DELETE FROM batchjob_hugoshop b
              USING sales_channel_shop c
              WHERE c.id = b.channel_id
                AND (c.type || ':' || b.function) = ANY(string_to_array(:paare, ','))
                AND b.id = ANY(string_to_array(:ids, ',')::int[])
             RETURNING b.id)
         SELECT count(*)::int AS anzahl FROM weg",
        [
            ':paare'      => shopChannelJobPairs(),
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
 * gesehen hat. Offene ebenfalls; nur Auftragsarten der Kanalmodule.
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
             DELETE FROM batchjob_hugoshop b
              USING sales_channel_shop c
              WHERE c.id = b.channel_id
                AND b.result IS NOT NULL
                AND b.result LIKE 'ok%'
                AND (c.type || ':' || b.function) = ANY(string_to_array(:paare, ','))
                AND (1 = :alle
                     OR b.itime IS NULL
                     OR b.itime < now() - (:tage * interval '1 day'))
             RETURNING b.id)
         SELECT count(*)::int AS anzahl FROM weg",
        [
            ':paare'      => shopChannelJobPairs(),
            ':alle'       => null === $tage ? 1 : 0,
            ':tage'       => (int)($tage ?? 0),
        ]
    );

    return $zeile ? (int)$zeile['anzahl'] : 0;
}

/**
 * Artikel, die in einem HugoShop stehen
 *
 * Maßgeblich ist die aktive Zeile des Kanals, nicht mehr die
 * parts_ext-Zeile: die bleibt beim Abwählen erhalten (V5).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop; abgeschaltet = keine Artikel
 * @return array Liste aus id und partnumber
 */
function shopListedParts($db, int $kanal): array {
    return $db->getAll(
        "SELECT p.id, p.partnumber
           FROM parts p
           JOIN parts_channel_shop pc ON pc.parts_id = p.id
                                     AND pc.channel_id = shop_active_channel_id(CAST(:kanal AS integer))
                                     AND pc.active
          ORDER BY p.id",
        [':kanal' => $kanal]
    );
}

/**
 * Loescht die Seite eines Artikels
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @param string $dateiname Dateiname ohne Pfad
 * @return bool true, wenn es die Datei gab
 */
function shopRemovePage($db, int $kanal, string $dateiname): bool {
    $datei = shopContentDir($db, $kanal).'/'.basename($dateiname);
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
 * @param callable|null $gruppe vor jedem Auftrag mit seiner Zeile gerufen;
 *                              liefert die Vorsilbe für seine Fehlertexte
 *                              (shopPublishRun gruppiert damit die Ausgabe je Kanal)
 * @return array jobs, seiten, entfernt, fehler, kit (Änderungen am Webseiten-Paket),
 *               ids (bearbeitete Aufträge), webseiten (je HugoShop mit Aufträgen:
 *               jobs, seiten, entfernt, kit, bauen — siehe shopSiteTally)
 */
function shopRunJobs($db, ?callable $melden = null, int $limit = 500, ?array $nurIds = null, ?callable $gruppe = null): array {
    $sagen = $melden ?? function (string $zeile) {};
    $vorsilbe = '';
    $bilanz = ['jobs' => 0, 'seiten' => 0, 'entfernt' => 0, 'fehler' => 0, 'kit' => 0,
               'fehler_texte' => [], 'ids' => [], 'webseiten' => []];

    // Fehler werden nicht nur gezählt, sondern im Wortlaut gesammelt: das
    // Admin-Panel zeigt sie nach "Jetzt ausführen" an, sonst stünde dort nur
    // eine Zahl. Der Läufer schreibt sie ohnehin auf die Ausgabe.
    $fehler = function (string $zeile) use ($sagen, &$bilanz, &$vorsilbe) {
        $bilanz['fehler']++;
        $bilanz['fehler_texte'][] = $vorsilbe.$zeile;
        $sagen($zeile);
    };

    foreach (shopOpenJobs($db, $limit, $nurIds) as $auftrag) {
        $id = (int)$auftrag['id'];
        if (null !== $gruppe) {
            $vorsilbe = (string)$gruppe($auftrag);
        }
        $bilanz['jobs']++;
        $bilanz['ids'][] = $id;

        try {
            // Jeder Kanal arbeitet seine Aufträge selbst ab (channels/). Ein
            // Fehler trifft nur diesen Auftrag, die übrigen Kanäle laufen
            // weiter (V13).
            if ('hugoshop' === $auftrag['channel']) {
                shopSiteTally($bilanz, (int)$auftrag['channel_id'], 'jobs');
                // Für das Ergebnis der Webseite (shopSiteJobResults): welche
                // Aufträge dieses Laufs zu welcher Webseite gehören
                $bilanz['webseiten_ids'][(int)$auftrag['channel_id']][] = $id;
            }
            shopChannelRunJob($db, $auftrag, $sagen, $fehler, $bilanz);
        } catch (Throwable $e) {
            $fehler('Auftrag '.$id.' fehlgeschlagen: '.$e->getMessage());
            shopJobResult($db, $id, 'Fehler: '.$e->getMessage());
        }
    }

    return $bilanz;
}

/**
 * Zählt in der Bilanz eines Laufs — gesamt und je Webseite
 *
 * Nach den Aufträgen entscheidet der Lauf je HugoShop, ob Paket,
 * Kategorieübersicht und Bau nötig sind; dafür braucht er die Zahlen je
 * Webseite. 'bauen' ist ein Merker: der Bau ist verlangt, auch ohne Änderung.
 *
 * @param array $bilanz Bilanz des Laufs
 * @param int $kanal HugoShop
 * @param string $feld jobs, seiten, entfernt, kit oder bauen
 * @param int $anzahl Zuwachs
 * @return void
 */
function shopSiteTally(array &$bilanz, int $kanal, string $feld, int $anzahl = 1): void {
    if (!isset($bilanz['webseiten'][$kanal])) {
        $bilanz['webseiten'][$kanal] = ['jobs' => 0, 'seiten' => 0, 'entfernt' => 0, 'kit' => 0, 'bauen' => false];
    }
    if ('bauen' === $feld) {
        $bilanz['webseiten'][$kanal]['bauen'] = true;
        return;
    }
    $bilanz['webseiten'][$kanal][$feld] += $anzahl;
    if ('jobs' !== $feld) {
        $bilanz[$feld] = ($bilanz[$feld] ?? 0) + $anzahl;
    }
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
 * @param int $kanal HugoShop
 * @return array befehl (leer, wenn kein Programm eingestellt), fehler (leer, wenn in Ordnung)
 */
function shopPublishCommand($db, int $kanal): array {
    $programm = shopPublishProgram($db);
    if ('' === $programm['pfad']) {
        return ['befehl' => '', 'fehler' => $programm['fehler']];
    }

    $befehl = escapeshellarg($programm['pfad']);
    if (shopChannelBool($db, $kanal, 'publish_clean_destination', true)) {
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
            // Aus dem Panel: nur die Webseiten der ausgeführten Aufträge
            .' --only-jobs'
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
 * @param bool $nurBetroffene nur Webseiten mit Aufträgen in diesem Lauf bearbeiten
 *                            (Lauf aus dem Admin-Panel, --only-jobs)
 * @return array gesperrt, jobs, seiten, entfernt, fehler, fehler_texte, kit, kategorien, bauen, gebaut, bau_code, bau_ausgabe
 */
function shopPublishRun($db, ?callable $melden = null, int $limit = 500, ?array $nurIds = null, bool $bauen = true, ?callable $beginn = null, bool $nurBetroffene = false): array {
    // Jede Meldung geht an den Aufrufer und in die Ausgabe des Laufs, die am
    // Ende bei den erledigten Aufträgen landet (shopSaveRun). Gespeichert wird
    // sie je Kanal gruppiert, unter einer Überschrift — der Lauf arbeitet
    // Aufträge und Webseiten nacheinander ab, Aufträge verschiedener Kanäle
    // können sich aber abwechseln. Der Aufrufer (Protokoll während des Laufs)
    // bekommt die Zeilen in ihrer Reihenfolge, mit dem Kanal vorn.
    $gruppen = [];          // Schlüssel => ['titel' => …, 'zeilen' => […]], in Reihenfolge des Auftretens
    $aktuell = '';          // '' = ohne Kanal (Sperre, allgemeine Meldungen)
    $laufBeginn = date('Y-m-d H:i:s');
    $sagen = function (string $zeile) use ($melden, &$gruppen, &$aktuell) {
        $gruppen[$aktuell] ??= ['titel' => '', 'zeilen' => []];
        $gruppen[$aktuell]['zeilen'][] = $zeile;
        if (null !== $melden) {
            $titel = $gruppen[$aktuell]['titel'];
            $melden(('' !== $titel ? '['.$titel.'] ' : '').$zeile);
        }
    };
    /** Wechselt die Gruppe; Titel ist der Name des Kanals */
    $gruppeSetzen = function (int $kanal, string $titel) use (&$gruppen, &$aktuell): void {
        $aktuell = 'k'.$kanal;
        $gruppen[$aktuell] ??= ['titel' => $titel, 'zeilen' => []];
    };
    $bilanz = ['gesperrt' => false, 'jobs' => 0, 'seiten' => 0, 'entfernt' => 0, 'fehler' => 0,
               'kit' => 0, 'kategorien' => 0, 'bauen' => false, 'gebaut' => false, 'bau_code' => 0, 'bau_ausgabe' => [],
               'fehler_texte' => []];

    // Fehler werden nicht nur gezählt, sondern im Wortlaut gesammelt: das
    // Admin-Panel zeigt sie nach "Jetzt ausführen" an, sonst stünde dort nur
    // eine Zahl. Der Läufer schreibt sie ohnehin auf die Ausgabe.
    // $vorsilbe nennt den Kanal in den Fehlertexten (Meldung über der Liste);
    // in der Ausgabe steht die Zeile ohne, unter der Überschrift ihres Kanals
    $fehler = function (string $zeile, string $vorsilbe = '') use ($sagen, &$bilanz) {
        $bilanz['fehler']++;
        $bilanz['fehler_texte'][] = $vorsilbe.$zeile;
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
        $bilanz = array_merge($bilanz, shopRunJobs($db, $sagen, $limit, $nurIds,
            function (array $auftrag) use ($gruppeSetzen): string {
                $name = (string)($auftrag['channel_name'] ?? '') ?: (string)($auftrag['channel'] ?? '');
                $gruppeSetzen((int)$auftrag['channel_id'], $name);
                return '['.$name.'] ';
            }));

        // Je HugoShop seine Webseite: Paket, Kategorieübersicht, Bau
        // (dev/shop-mehrere-kanaele.md, Schritt 3). Betroffen ist jeder
        // eingeschaltete HugoShop und jeder, für den in diesem Lauf Aufträge
        // liefen — etwa remove_all gleich nach dem Abschalten (V8, V16). Ein
        // abgeschalteter ohne Aufträge bleibt unberührt; ein Mandant, der nur
        // eBay nutzt, hat womöglich gar keine Webseite. Ein Fehler bei einer
        // Webseite hält die übrigen nicht auf.
        $kanaele = $db->getAll(
            "SELECT id, name FROM sales_channel_shop WHERE type = 'hugoshop' ORDER BY sortkey NULLS LAST, id"
        );
        foreach ($kanaele as $eintrag) {
            $kanal = (int)$eintrag['id'];
            $zahlen = $bilanz['webseiten'][$kanal] ?? null;
            // Fehlertexte (Meldung über der Liste) nennen den Kanal; die
            // Ausgabe steht unter seiner Überschrift
            $vorsilbe = '['.$eintrag['name'].'] ';

            // Lauf aus dem Admin-Panel: nur die Webseiten, für die Aufträge
            // liefen. Die Pflege der übrigen (Paket, Übersicht, täglicher
            // Abgleich mit HugoCMS) bleibt dem Cron.
            if ($nurBetroffene && null === $zahlen) {
                continue;
            }
            $gruppeSetzen($kanal, (string)$eintrag['name']);

            if (null === $zahlen && !shopChannelHugoshopActive($db, $kanal)) {
                $sagen('HugoShop abgeschaltet — Webseite unverändert.');
                continue;
            }

            // Fehler und Bau dieser Webseite getrennt erfassen: sie gehören in
            // das Ergebnis ihrer Aufträge, nicht nur in die Ausgabe
            $webFehler = [];
            $gebautVorher = $bilanz['gebaut'];
            $bilanz['gebaut'] = false;
            try {
                shopPublishSite(
                    $db, $kanal,
                    $zahlen ?? ['jobs' => 0, 'seiten' => 0, 'entfernt' => 0, 'kit' => 0, 'bauen' => false],
                    $bauen,
                    $sagen,
                    function (string $zeile) use ($fehler, $vorsilbe, &$webFehler) {
                        $webFehler[] = $zeile;
                        $fehler($zeile, $vorsilbe);
                    },
                    $bilanz
                );
            } catch (Throwable $e) {
                $webFehler[] = 'Webseite nicht bearbeitet: '.$e->getMessage();
                $fehler('Webseite nicht bearbeitet: '.$e->getMessage(), $vorsilbe);
            }
            shopSiteJobResults($db, $bilanz['webseiten_ids'][$kanal] ?? [], $webFehler, $bilanz['gebaut']);
            $bilanz['gebaut'] = $gebautVorher || $bilanz['gebaut'];
        }
    } finally {
        // Vor dem Freigeben der Sperre: die Aufträge stehen erst mit ihrer
        // Ausgabe als erledigt da, wenn der nächste Lauf beginnen kann
        try {
            shopSaveRun($db, $laufBeginn, shopRunOutputLines($gruppen), $bilanz['ids'] ?? []);
        } catch (Throwable $e) {
            if (null !== $melden) {
                $melden('Ausgabe des Laufs nicht gespeichert: '.$e->getMessage());
            }
        }
        shopPublishUnlock($db);
    }

    return $bilanz;
}

/** Kennzeichen einer Überschrift in der gespeicherten Ausgabe — die Oberfläche hebt sie hervor */
const SHOP_RUN_HEADING = '━━ ';

/**
 * Setzt die nach Kanal gesammelte Ausgabe eines Laufs zusammen
 *
 * Erst, was keinem Kanal gehört (etwa die Sperre), dann je Kanal eine
 * Überschrift und seine Zeilen in der Reihenfolge, in der sie entstanden —
 * Aufträge und Webseite zusammen. Zwischen den Kanälen eine Leerzeile.
 *
 * @param array $gruppen Schlüssel => ['titel' => …, 'zeilen' => […]]
 * @return array Zeilen
 */
function shopRunOutputLines(array $gruppen): array {
    $zeilen = $gruppen['']['zeilen'] ?? [];
    unset($gruppen['']);
    foreach ($gruppen as $gruppe) {
        if (!$gruppe['zeilen']) {
            continue;
        }
        if ($zeilen) {
            $zeilen[] = '';
        }
        $zeilen[] = SHOP_RUN_HEADING.$gruppe['titel'].' ━━';
        array_push($zeilen, ...$gruppe['zeilen']);
    }
    return $zeilen;
}

/**
 * Ergänzt das Ergebnis der Aufträge einer Webseite um deren Veröffentlichung
 *
 * Ein Auftrag erledigt zwei Dinge: die Seiten schreiben (sein Ergebnis, etwa
 * „ok: 3711 Seiten“) und danach — für alle Aufträge der Webseite gemeinsam —
 * Übertragung und Bau. Scheitert der zweite Teil, stand bisher trotzdem ein
 * grüner Haken am Auftrag. Jetzt:
 *
 *   gescheitert  „Fehler bei der Webseite: <erster Grund> · Seiten: 3711“ —
 *                der Auftrag gilt als fehlgeschlagen
 *   gebaut       „ok: 3711 Seiten · Webseite gebaut“
 *
 * Aufträge, die schon selbst gescheitert sind, bleiben, wie sie sind.
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $ids Aufträge dieses Laufs für die Webseite
 * @param array $fehler Fehlermeldungen der Webseite (Übertragung, Bau)
 * @param bool $gebaut die Webseite wurde in diesem Lauf gebaut
 * @return void
 */
function shopSiteJobResults($db, array $ids, array $fehler, bool $gebaut): void {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
    if (!$ids || (!$fehler && !$gebaut)) {
        return;
    }
    $db->execute(
        "UPDATE batchjob_hugoshop
            SET result = left(CASE WHEN :gescheitert = 1
                                   THEN 'Fehler bei der Webseite: ' || :grund || ' · Seiten: '
                                        || regexp_replace(COALESCE(result, ''), '^ok: ', '')
                                   ELSE result || ' · Webseite gebaut' END, 500)
          WHERE id = ANY(string_to_array(:ids, ',')::int[])
            AND COALESCE(result, '') NOT LIKE 'Fehler%'",
        [
            ':gescheitert' => $fehler ? 1 : 0,
            ':grund'       => (string)($fehler[0] ?? ''),
            ':ids'         => implode(',', $ids),
        ]
    );
}

/**
 * Der Teil eines Laufs, der eine Webseite betrifft: Paket, Kategorieübersicht,
 * Bau bzw. Übertragung an HugoCMS
 *
 * Gerufen von shopPublishRun() nach den Aufträgen, je betroffenem HugoShop.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @param array $zahlen Zahlen dieser Webseite aus den Aufträgen (shopSiteTally)
 * @param bool $bauen Webseite bauen, wenn sich etwas geändert hat
 * @param callable $sagen Fortschritt
 * @param callable $fehler Fehlermeldung, wird gezählt
 * @param array $bilanz Bilanz des Laufs: kit, kategorien, gebaut, bau_code, bau_ausgabe
 * @return void
 */
function shopPublishSite($db, int $kanal, array $zahlen, bool $bauen, callable $sagen, callable $fehler, array &$bilanz): void {
    $kit = 0;
    $kategorien = 0;

    // Das Paket bei jedem Lauf abgleichen, nicht nur nach neuen Seiten:
    // beim ersten Lauf entsteht oserp-shop/ überhaupt erst, und nach einem
    // Update kommt ein neues Bundle an, ohne dass jemand veröffentlicht.
    try {
        $abgleich = shopSyncKit($db, $kanal);
        $kit = shopKitChanges($abgleich);
        $bilanz['kit'] += $kit;
        if ($kit > 0) {
            $sagen(sprintf('Paket abgeglichen: %d kopiert, %d entfernt%s',
                $abgleich['kopiert'], $abgleich['entfernt'], $abgleich['config'] ? ', Konfiguration neu' : ''));
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
        $ziel = shopCategoryGroupsFile($db, $kanal);
        $uebersichtFehlt = '' !== $ziel && !is_file($ziel);
    } catch (ApiError $e) {
        // Nur das Unterverzeichnis fehlt — dann fehlt auch die Datei.
        // Alles andere (kein Wurzelverzeichnis etwa) meldet schon ein
        // anderer Schritt; hier bliebe es bei einer zweiten Meldung.
        $uebersichtFehlt = 'SHOP_PATH_MISSING' === $e->getId();
    }

    if ($zahlen['seiten'] + $zahlen['entfernt'] > 0 || $uebersichtFehlt) {
        try {
            $übersicht = shopWriteCategoryGroups($db, $kanal);
            if ($übersicht['changed']) {
                $kategorien = 1;
                $bilanz['kategorien'] += 1;
                $sagen($übersicht['categories'] > 0
                    ? sprintf('Kategorieübersicht geschrieben: %d Kategorien in %d Gruppen', $übersicht['categories'], $übersicht['groups'])
                    : 'Kategorieübersicht entfernt: keine Kategorien');
            }
        } catch (Throwable $e) {
            $fehler('Kategorieübersicht fehlgeschlagen: '.$e->getMessage());
        }
    }

    // Betriebsart HugoCMS: übertragen und dort bauen lassen. Übertragen
    // wird auch ohne Änderung in diesem Lauf — HugoCMS könnte hinterher
    // sein (neue Webseite, gescheiterter Lauf); was unverändert ist,
    // erkennt die Übertragung selbst.
    if ('hugocms' === shopPublishMode($db, $kanal)) {
        if (shopHugoCmsPublish($db, $kanal, $sagen, $fehler, $bauen, $zahlen['bauen'])) {
            $bilanz['gebaut'] = true;
        }
        return;
    }

    // bauen: ein Auftrag verlangt den Bau auch ohne Änderung (SHOP_KIT_INSTALL)
    $geaendert = $zahlen['seiten'] + $zahlen['entfernt'] + $zahlen['kit'] + $kit + $kategorien
               + ($zahlen['bauen'] ? 1 : 0);
    if (!$bauen || 0 === $geaendert) {
        return;
    }

    // Die Befehlszeile setzt die Erweiterung selbst zusammen: geprüfter
    // Pfad zum Programm plus feste Argumente (shopPublishCommand).
    $bau = shopPublishCommand($db, $kanal);
    if ('' !== $bau['fehler']) {
        $fehler('Die Webseite wurde nicht gebaut: '.$bau['fehler']);
        return;
    }
    if ('' === $bau['befehl']) {
        $sagen('Kein Programm zum Bauen eingestellt — es wurden nur Dateien geschrieben.');
        return;
    }
    if (!function_exists('exec')) {
        $fehler('exec() ist abgeschaltet — die Webseite wurde nicht gebaut.');
        return;
    }

    $verzeichnis = shopSiteDir($db, $kanal);
    $sagen('Baue die Webseite in '.$verzeichnis);

    $ausgabe = [];
    $code = 0;
    exec('cd '.escapeshellarg($verzeichnis).' && '.$bau['befehl'].' 2>&1', $ausgabe, $code);
    foreach ($ausgabe as $zeile) {
        $sagen('  '.$zeile);
    }

    $bilanz['bau_ausgabe'] = array_merge($bilanz['bau_ausgabe'], $ausgabe);
    if (0 === $code) {
        $bilanz['gebaut'] = true;
        $sagen('Webseite gebaut.');
    } else {
        $bilanz['bau_code'] = $code;
        $fehler('Der Bau der Webseite ist fehlgeschlagen (Rückgabewert '.$code.').');
    }
}

// Höchstzahl gespeicherter Zeilen je Lauf. „Alle Produkte“ meldet eine Zeile
// je Seite; darüber hinaus bleiben Anfang und Ende stehen, dazwischen ein
// Hinweis — dort stehen der Bau und das Ergebnis.
const SHOP_RUN_OUTPUT_LINES = 20000;

/**
 * Speichert die Ausgabe eines Laufs bei seinen Aufträgen
 *
 * Nur, wenn der Lauf Aufträge erledigt hat, die es noch gibt — sonst hätte
 * niemand einen Status, über den er die Ausgabe öffnen könnte. Lauf und
 * Verweise entstehen in einer Anweisung.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $beginn Beginn des Laufs (Y-m-d H:i:s)
 * @param array $zeilen Meldungen des Laufs
 * @param array $ids bearbeitete Aufträge
 * @return void
 */
function shopSaveRun($db, string $beginn, array $zeilen, array $ids): void {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
    if (!$ids) {
        return;
    }
    if (count($zeilen) > SHOP_RUN_OUTPUT_LINES) {
        $haelfte = intdiv(SHOP_RUN_OUTPUT_LINES, 2);
        $zeilen = array_merge(
            array_slice($zeilen, 0, $haelfte),
            [sprintf('… %d Zeilen ausgelassen …', count($zeilen) - 2 * $haelfte)],
            array_slice($zeilen, -$haelfte)
        );
    }

    $db->execute(
        "WITH lauf AS (
             INSERT INTO batchjob_run_hugoshop (itime, finished, output)
             SELECT CAST(:beginn AS timestamp), localtimestamp(0), :output
              WHERE EXISTS (SELECT 1 FROM batchjob_hugoshop
                             WHERE id = ANY(string_to_array(:ids_pruefen, ',')::int[]))
             RETURNING id)
         UPDATE batchjob_hugoshop b
            SET run_id = lauf.id
           FROM lauf
          WHERE b.id = ANY(string_to_array(:ids, ',')::int[])",
        [
            ':beginn'      => $beginn,
            ':output'      => implode("\n", $zeilen),
            ':ids_pruefen' => implode(',', $ids),
            ':ids'         => implode(',', $ids),
        ]
    );
}

// Die Verkaufskanäle: Modulrahmen und Module, darunter der HugoShop mit der
// Abarbeitung der Aufträge dieser Datei (dev/shop-verkaufskanaele.md, Schritt 4)
require_once __DIR__.'/../channels/channels.php';
