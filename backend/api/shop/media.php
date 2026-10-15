<?php
// backend/api/shop/media.php
//
// Medienbibliothek für das Admin-Panel (dev/shop-medienbibliothek.md): die
// Ansicht „Medien“ (VueFinder) und die Artikelkarte (Hochladen, aus der
// Bibliothek wählen). Logik in lib/media.php, Daten in media_shop.
//
// Pfade wie VueFinder sie führt: images://name, downloads://name,
// thumbnails://name. Lesen und Hochladen dürfen alle, die Artikel pflegen
// (shop_part_edit); Umbenennen und Löschen betreffen alle Artikel und
// verlangen edit_shop_config.

/** Antwort des Dateimanagers für einen Bereich */
function shopMediaListing($db, string $bereich): array {
    return [
        'storages' => array_keys(SHOP_MEDIA_STORAGES),
        'dirname'  => $bereich.'://',
        'files'    => shopMediaEntries($db, $bereich),
    ];
}

/**
 * Dateimanager: Inhalt eines Bereichs der Medienbibliothek
 *
 * @param string $data['path'] Bereich, etwa images:// (leer = Produktbilder)
 * @return void storages, dirname, files (VueFinder)
 * @testdata {"path": "images://"}
 */
function shopMediaIndex($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $pfad = shopMediaPath($data['path'] ?? '');
    resultInfo(true, '', shopMediaListing($db, $pfad['bereich']));
}

/**
 * Dateimanager: Suche in einem Bereich
 *
 * @param string $data['path'] Bereich, etwa images://
 * @param string $data['filter'] Namensteil
 * @return void files
 * @testdata {"path": "images://", "filter": "hammer"}
 */
function shopMediaSearch($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $pfad = shopMediaPath($data['path'] ?? '');
    $filter = trim((string)($data['filter'] ?? ''));
    resultInfo(true, '', ['files' => '' === $filter ? [] : shopMediaEntries($db, $pfad['bereich'], $filter, 200)]);
}

/**
 * Auswahl für die Artikelkarte: Dateien eines Bereichs, mit Namensfilter
 *
 * @param string $data['kind'] image oder download
 * @param string $data['filter'] Namensteil, leer = alle
 * @param int $data['limit'] Höchstzahl, Vorgabe 60
 * @return void files (basename, path, file_size, mime_type, last_modified), total
 * @testdata {"kind": "image", "filter": "", "limit": 60}
 */
function shopMediaPick($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $bereich = 'download' === ($data['kind'] ?? '') ? 'downloads' : 'images';
    $alle = shopMediaEntries($db, $bereich, (string)($data['filter'] ?? ''));
    $limit = max(1, min(500, (int)($data['limit'] ?? 60)));
    resultInfo(true, '', ['files' => array_slice($alle, 0, $limit), 'total' => count($alle)]);
}

/**
 * Nimmt eine Datei in die Bibliothek auf (multipart/form-data, Feld file)
 *
 * Jeden Namen gibt es nur einmal: Nennt ein Artikel die vorhandene Datei,
 * antwortet MEDIA_EXISTS, nichts wird ersetzt; eine Datei, die kein Artikel
 * nennt, wird ersetzt. Die Antwort nennt den Namen, unter dem
 * die Datei liegt — bei Bildern mit der Endung, die zum Inhalt oder zum
 * Zielformat passt —, und den Inhalt des Bereichs.
 *
 * @param string $data['path'] Bereich, etwa images:// oder downloads://
 * @param string $data['name'] gewünschter Name; leer = Name der hochgeladenen Datei
 * @param string $data['format'] Zielformat für Bilder (webp, jpg, png); leer = Format beibehalten
 * @return void filename, replaced, media (Zeile aus media_shop), storages, dirname, files
 * @testdata {"path": "images://", "name": "", "format": ""}
 */
function shopMediaUpload($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $pfad = shopMediaPath($data['path'] ?? '');
    $art = SHOP_MEDIA_STORAGES[$pfad['bereich']];
    if ('thumbnail' === $art) {
        resultInfo(false, 'MEDIA_KIND_INVALID', null, 'Vorschaubilder erzeugt OpensourceERP selbst');
        return;
    }

    $datei = $_FILES['file'] ?? null;
    if (!$datei || !isset($datei['tmp_name'])) {
        resultInfo(false, 'MEDIA_UPLOAD', null, 'Keine Datei empfangen');
        return;
    }
    if (($datei['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $gruende = [
            UPLOAD_ERR_INI_SIZE   => 'Die Datei ist größer, als der Server annimmt (upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE  => 'Die Datei ist zu groß',
            UPLOAD_ERR_PARTIAL    => 'Die Datei kam nur teilweise an',
            UPLOAD_ERR_NO_FILE    => 'Keine Datei empfangen',
            UPLOAD_ERR_NO_TMP_DIR => 'Dem Server fehlt das Verzeichnis für Uploads',
            UPLOAD_ERR_CANT_WRITE => 'Der Server konnte die Datei nicht schreiben',
        ];
        resultInfo(false, 'MEDIA_UPLOAD', null, $gruende[$datei['error']] ?? 'Upload fehlgeschlagen');
        return;
    }

    $zeile = shopMediaImport($db, $art, (string)$datei['tmp_name'], (string)($data['name'] ?? ''),
        (string)($datei['name'] ?? ''), (string)($data['format'] ?? ''), true);
    resultInfo(true, '', [
        'filename' => $zeile['filename'],
        'replaced' => $zeile['replaced'],
        'media'    => $zeile,
    ] + shopMediaListing($db, $pfad['bereich']));
}

/**
 * Dateimanager: Dateien löschen — nur, wenn kein Artikel sie nennt
 *
 * @param string $data['path'] Bereich
 * @param array $data['items'] Pfade (images://name) oder Einträge mit path
 * @param int $data['parts_id'] Artikel, dessen Verweis vorher mitgeht (Artikelkarte), 0 = keiner
 * @return void storages, dirname, files
 * @testdata {"path": "images://", "items": ["images://alt.jpg"]}
 */
function shopMediaDelete($data) {
    // Auch wer Artikel pflegt: Löschen aus der Artikelkarte nimmt die Datei
    // aus der Bibliothek — nennt ein anderer Artikel sie, bleibt sie
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $pfad = shopMediaPath($data['path'] ?? '');
    $partsId = (int)($data['parts_id'] ?? 0);
    foreach ((array)($data['items'] ?? []) as $eintrag) {
        $datei = shopMediaPath($eintrag);
        $art = SHOP_MEDIA_STORAGES[$datei['bereich']];
        if ('thumbnail' === $art || '' === $datei['name']) {
            resultInfo(false, 'MEDIA_KIND_INVALID', null, 'Vorschaubilder löscht OpensourceERP mit ihrem Produktbild');
            return;
        }
        if ($partsId > 0) {
            // Aus der Artikelkarte: der eigene Verweis geht mit — nennt ein
            // anderer Artikel die Datei, bleibt sie (MEDIA_IN_USE)
            shopMediaUnlinkFromPart($db, $art, $datei['name'], $partsId);
        }
        shopMediaFileDelete($db, $art, $datei['name']);
    }
    resultInfo(true, '', shopMediaListing($db, $pfad['bereich']));
}

/**
 * Artikelkarte: Dateien eines Bereichs zu den Namen, die der Artikel nennt
 *
 * @param string $data['kind'] image oder download
 * @param array $data['names'] Dateinamen (Verzeichnisanteile werden abgestreift)
 * @return void files (wie shopMediaIndex, dazu missing)
 * @testdata {"kind": "image", "names": ["foto.jpg"]}
 */
function shopMediaItems($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $bereich = 'download' === ($data['kind'] ?? '') ? 'downloads' : 'images';
    $namen = array_slice(array_map('strval', (array)($data['names'] ?? [])), 0, 500);
    resultInfo(true, '', ['files' => shopMediaEntriesFor($db, $bereich, $namen)]);
}

/**
 * Dateimanager: Datei umbenennen — die Verweise der Artikel folgen
 *
 * @param string $data['path'] Bereich
 * @param string $data['item'] bisheriger Pfad (images://alt.jpg)
 * @param string $data['name'] neuer Name
 * @return void parts (geänderte Artikel), name, storages, dirname, files
 * @testdata {"path": "images://", "item": "images://alt.jpg", "name": "neu.jpg"}
 */
function shopMediaRename($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();
    $pfad = shopMediaPath($data['path'] ?? '');
    $datei = shopMediaPath($data['item'] ?? '');
    $art = SHOP_MEDIA_STORAGES[$datei['bereich']];
    if ('thumbnail' === $art || '' === $datei['name']) {
        resultInfo(false, 'MEDIA_KIND_INVALID', null, 'Vorschaubilder heißen wie ihr Produktbild');
        return;
    }
    $ergebnis = shopMediaFileRename($db, $art, $datei['name'], (string)($data['name'] ?? ''));
    resultInfo(true, '', $ergebnis + shopMediaListing($db, $pfad['bereich']));
}

/**
 * Dateimanager: Produktbild in ein anderes Format umwandeln (WebP, JPEG, PNG)
 *
 * Die neue Datei ersetzt die alte, die Verweise der Artikel folgen.
 *
 * @param string $data['path'] Pfad des Bilds (images://foto.jpg)
 * @param string $data['format'] webp, jpg oder png
 * @return void name (neuer Name), parts (geänderte Artikel), size_before, size_after, storages, dirname, files
 * @testdata {"path": "images://foto.jpg", "format": "webp"}
 */
function shopMediaConvert($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();
    $datei = shopMediaPath($data['path'] ?? '');
    if ('images' !== $datei['bereich'] || '' === $datei['name']) {
        resultInfo(false, 'MEDIA_KIND_INVALID', null, 'Umgewandelt werden nur Produktbilder');
        return;
    }
    $ergebnis = shopMediaConvertFile($db, $datei['name'], (string)($data['format'] ?? ''));
    resultInfo(true, '', $ergebnis + shopMediaListing($db, 'images'));
}

/**
 * Welche Artikel eine Datei nennen
 *
 * @param string $data['path'] Pfad der Datei (images://name)
 * @return void parts (parts_id, partnumber, description)
 * @testdata {"path": "images://foto.jpg"}
 */
function shopMediaUsage($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $datei = shopMediaPath($data['path'] ?? '');
    $art = SHOP_MEDIA_STORAGES[$datei['bereich']];
    resultInfo(true, '', ['parts' => '' === $datei['name'] ? [] : shopMediaUsedBy($db, 'thumbnail' === $art ? 'image' : $art, $datei['name'])]);
}

/**
 * Gleicht media_shop mit den Verzeichnissen ab (von Hand abgelegte Dateien,
 * fehlende Vorschaubilder, geänderte Größe der Vorschaubilder)
 *
 * @return void neu, geaendert, entfernt, vorschau, unbrauchbar
 * @testdata {}
 */
function shopMediaScan($data) {
    permit(['edit_shop_config'], false);
    $db = DbhCompany::begin();
    resultInfo(true, '', shopMediaSync($db));
}

/**
 * Streamt eine Datei der Bibliothek (Vorschau im Browser)
 *
 * Für Bilder in der Artikelkarte und die Vorschau des Dateimanagers. Ein
 * fehlendes Vorschaubild fällt auf das Produktbild zurück.
 *
 * @param string $data['path'] Pfad der Datei (thumbnails://name, images://name, downloads://name)
 * @return void Dateiinhalt mit Content-Type
 * @testdata {"path": "thumbnails://foto.jpg"}
 */
function shopMediaPreview($data) {
    shopMediaStream($data, false);
}

/**
 * Liefert eine Datei der Bibliothek zum Herunterladen
 *
 * @param string $data['path'] Pfad der Datei
 * @return void Dateiinhalt als Anhang
 * @testdata {"path": "downloads://datenblatt.pdf"}
 */
function shopMediaDownload($data) {
    shopMediaStream($data, true);
}

/** Gemeinsamer Teil von shopMediaPreview und shopMediaDownload */
function shopMediaStream($data, bool $anhang): void {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();
    $pfad = shopMediaPath($data['path'] ?? '');
    $datei = '' === $pfad['name'] ? '' : shopMediaDir($db, $pfad['bereich']).'/'.$pfad['name'];
    if ('thumbnails' === $pfad['bereich'] && '' !== $pfad['name'] && !is_file($datei)) {
        $datei = shopMediaDir($db, 'images').'/'.$pfad['name'];
    }
    if ('' === $datei || !is_file($datei)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Datei nicht gefunden';
        exit;
    }
    header('Content-Type: '.fmGetMimeType($pfad['name']));
    header('Content-Length: '.filesize($datei));
    header('Cache-Control: private, max-age=300');
    if ($anhang) {
        header('Content-Disposition: attachment; filename="'.str_replace('"', '', $pfad['name']).'"');
    }
    readfile($datei);
    exit;
}
