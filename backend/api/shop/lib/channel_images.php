<?php
// backend/api/shop/lib/channel_images.php
//
// Bilder je Verkaufskanal (dev/shop-verkaufskanaele.md V12, V17;
// dev/shop-medienbibliothek.md Schritt 3).
//
// Die Marktplätze (eBay, Kleinanzeigen, später Amazon) führen ihre Bilder in
// parts_channel_image_shop. Jede Zeile verweist auf ein Bild der
// Medienbibliothek (media_id): die Datei liegt einmal unter
// data/<db>/shop/media/images/, welche Bilder ein Kanal zeigt und in welcher
// Reihenfolge, bestimmt die Zeile. Der HugoShop führt seine Bilder weiter als
// Dateinamen der Bibliothek in parts_ext.hugoshop_images.
//
// Format: Der Marktplatz bekommt jedes Bild in dem Format, das sein Kanal
// verlangt (Kanalkarte, image_format) und das die Kanalart annimmt
// (channel_image_format_shop) — entschieden in SQL (shop_channel_image_format).
// Passt das Format der Datei, geht das Original hinaus; sonst eine Fassung,
// die shopChannelImagesPrepare() neben der Bibliothek ablegt
// (shop/media/channel/<format>/<media_id>.<format>, media_variant_shop).
// Öffentlich über backend/webhook/part-image.php; Liste und Adressen aus
// shop_channel_images().
//
// Übernehmen zwischen den Kanälen:
//   HugoShop  → Marktplatz  die Bilder, die der Artikel nennt — aus der
//                           Bibliothek; fehlt eines dort noch, holt OSERP es
//                           einmal von der Webseite (images_link) hinein
//   Marktplatz → Marktplatz dieselben Bilder, nur neue Zeilen
//   Marktplatz → HugoShop   nicht hier: die Liste des HugoShops pflegt die
//                           Artikelkarte (Abschnitt Medien)
//
// Zeilen von früher (media_id NULL, Datei unter data/<db>/parts/<id>/)
// übernimmt shopChannelImagesAdopt() in die Bibliothek.
//
// Braucht customer_vendor/filemanager.php (Datenverzeichnis) und lib/media.php.

/**
 * Bildformate, die die Kanalarten annehmen (channel_image_format_shop)
 *
 * Für die Auswahl „Bildformat“ der Kanalkarte. Das Ersatzformat der Kanalart
 * steht vorn. Kanalarten ohne Eintrag nehmen alles.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array Kanalart => ['accepted' => Formate]
 */
function shopChannelImageFormats($db): array {
    $zeile = $db->getOne(
        "SELECT COALESCE(json_object_agg(t.channel_type, json_build_object('accepted', t.formate)), '{}'::json) AS kanaele
           FROM (SELECT channel_type, json_agg(format ORDER BY fallback DESC, format) AS formate
                   FROM channel_image_format_shop
                  GROUP BY channel_type) t"
    );
    return json_decode((string)($zeile['kanaele'] ?? '{}'), true) ?: [];
}

/**
 * Übernimmt die Kanalbilder eines Artikels für einen anderen („Als neu verwenden“)
 *
 * Nur Zeilen: beide Artikel zeigen dieselben Bilder der Bibliothek. Schon
 * vorhandene Bilder des Ziels bleiben.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $von Vorlage
 * @param int $nach neuer Artikel
 * @return int Anzahl übernommener Bilder
 */
function shopChannelImagesCopyFromPart($db, int $von, int $nach): int {
    if ($von <= 0 || $nach <= 0 || $von === $nach) return 0;
    shopChannelImagesAdopt($db, $von);
    $zeilen = $db->getAll(
        "INSERT INTO parts_channel_image_shop (parts_id, channel_id, media_id, sort)
         SELECT :nach, i.channel_id, i.media_id, i.sort
           FROM parts_channel_image_shop i
          WHERE i.parts_id = :von AND i.media_id IS NOT NULL
         ON CONFLICT (parts_id, channel_id, media_id) WHERE media_id IS NOT NULL DO NOTHING
         RETURNING id",
        [':nach' => $nach, ':von' => $von]
    );
    return count($zeilen ?: []);
}

/**
 * Artikelordner der Kanalbilder von früher (data/<db>/parts/<id>/)
 *
 * Nur noch für Zeilen ohne media_id, bis shopChannelImagesAdopt() sie
 * übernommen hat.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param bool $anlegen fehlendes Verzeichnis anlegen
 * @return string
 */
function shopChannelImageDir($db, int $partsId, bool $anlegen = false): string {
    $zeile = $db->getOne("SELECT current_database() AS name");
    $dir = fmDataDirForDb((string)($zeile['name'] ?? '')).'/parts/'.$partsId;
    if ($anlegen && !is_dir($dir)) {
        fmMkdir($dir);
    }
    return $dir;
}

/**
 * Kanal aus einer Angabe der Oberfläche: Kennung oder Art
 *
 * Eine Zahl ist die Kennung und wird geprüft. Die Art („hugoshop", „ebay")
 * meint den ersten Kanal der Art — für Verwaltungsaufrufe ohne Kanalangabe
 * (dev/shop-mehrere-kanaele.md).
 *
 * @param object $db Company-Datenbankverbindung
 * @param mixed $angabe Kennung oder Art
 * @return array{id: int, type: string}
 * @throws ApiError SHOP_CHANNEL_UNKNOWN
 */
function shopChannelParam($db, $angabe): array {
    $angabe = trim((string)$angabe);
    $zeile = null;
    if (ctype_digit($angabe)) {
        $zeile = $db->getOne("SELECT id, type FROM sales_channel_shop WHERE id = CAST(:id AS integer)", [':id' => $angabe]);
    } elseif ('' !== $angabe) {
        // Welche Arten es gibt, steht allein in der Datenbank: zu einer
        // unbekannten Art findet die Abfrage keinen Kanal
        $zeile = $db->getOne("SELECT id, type FROM sales_channel_shop WHERE id = shop_first_channel_id(:art)", [':art' => $angabe]);
    }
    if (!$zeile) {
        throw new ApiError('SHOP_CHANNEL_UNKNOWN', 'Unbekannter Verkaufskanal: '.$angabe);
    }
    return ['id' => (int)$zeile['id'], 'type' => (string)$zeile['type']];
}

/**
 * Bilder eines Artikels in einem Marktplatz-Kanal
 *
 * Aus shop_channel_images(): die Adresse ist relativ — die Oberfläche läuft
 * auf demselben Server wie backend/webhook/part-image.php —, path nennt die
 * Datei unter backend/data/<db>/.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param int $kanalId Kanal
 * @return array Liste aus id, media_id, filename, format, ready, path, url
 */
function shopChannelImages($db, int $partsId, int $kanalId): array {
    $zeile = $db->getOne(
        "SELECT shop_channel_images(CAST(:parts_id AS integer), CAST(:kanal AS integer)) AS bilder",
        [':parts_id' => $partsId, ':kanal' => $kanalId]
    );
    return json_decode((string)($zeile['bilder'] ?? '[]'), true) ?: [];
}

/**
 * Dateien der Bilder eines Kanals, in seiner Reihenfolge und seinem Format
 *
 * Für alles, was die Dateien selbst braucht (ZIP für Kleinanzeigen, der
 * Kleinanzeigen-Dienst auf demselben Rechner). Vorher
 * shopChannelImagesPrepare() aufrufen, damit die Fassungen da sind. Fehlende
 * Dateien bleiben aus.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param int $kanalId Kanal
 * @return array absolute Pfade
 */
function shopChannelImageFiles($db, int $partsId, int $kanalId): array {
    $basis = fmDataDir();
    $dateien = [];
    foreach (shopChannelImages($db, $partsId, $kanalId) as $bild) {
        $pfad = $basis.'/'.ltrim((string)$bild['path'], '/');
        if (!in_array('..', explode('/', (string)$bild['path']), true) && is_file($pfad)) {
            $dateien[] = $pfad;
        }
    }
    return $dateien;
}

/**
 * Wandelt Bilddaten in ein anderes Format (jpg, png, gif, webp, avif)
 *
 * Liest über shopMediaLoad — GD, AVIF notfalls über ffmpeg — und schreibt
 * über shopMediaSave. JPEG kennt keine Transparenz (Qualität 90, Transparenz
 * auf Weiß); die anderen Formate behalten sie.
 *
 * @param string $inhalt Bilddaten
 * @param string $format Format der Daten (jpg, png, gif, webp, avif)
 * @param string $ziel Zielformat (jpg, png, gif, webp, avif)
 * @param string $name Dateiname für die Meldung
 * @return string Bilddaten im Zielformat
 * @throws ApiError SHOP_IMAGE_TYPE
 */
function shopChannelImageConvert(string $inhalt, string $format, string $ziel, string $name = ''): string {
    $wer = '' !== $name ? ': '.$name : '';
    if (!shopMediaCanWrite($ziel)) {
        throw new ApiError('SHOP_IMAGE_TYPE', strtoupper($ziel).' kann dieser Server nicht schreiben'.$wer);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'oserp-channel');
    $aus = tempnam(sys_get_temp_dir(), 'oserp-channel');
    try {
        file_put_contents($tmp, $inhalt);
        $bild = shopMediaCanRead($format) ? shopMediaLoad($tmp, $format) : false;
        if (false === $bild) {
            throw new ApiError('SHOP_IMAGE_TYPE', strtoupper($format).' kann dieser Server nicht lesen'.$wer);
        }
        if ('jpg' === $ziel) {
            $flach = imagecreatetruecolor(imagesx($bild), imagesy($bild));
            imagefill($flach, 0, 0, imagecolorallocate($flach, 255, 255, 255));
            imagecopy($flach, $bild, 0, 0, 0, 0, imagesx($bild), imagesy($bild));
            imagedestroy($bild);
            $bild = $flach;
            ob_start();
            imagejpeg($bild, null, 90);
            imagedestroy($bild);
            return (string)ob_get_clean();
        }
        imagepalettetotruecolor($bild);
        imagealphablending($bild, false);
        imagesavealpha($bild, true);
        $ok = shopMediaSave($bild, $aus, $ziel, 'jpg' !== $format, $fehler);
        imagedestroy($bild);
        if (!$ok) {
            throw new ApiError('SHOP_IMAGE_TYPE', 'Bild konnte nicht nach '.strtoupper($ziel).' umgewandelt werden'.$wer.($fehler ? ' ('.$fehler.')' : ''));
        }
        return (string)file_get_contents($aus);
    } finally {
        @unlink($tmp);
        @unlink($aus);
    }
}

/**
 * Legt Bilddaten in der Medienbibliothek ab — dasselbe Bild nur einmal
 *
 * Liegt ein Bild gleichen Inhalts (Prüfsumme) schon in der Bibliothek, gilt
 * dieses. Sonst bekommt das Bild einen freien Namen: der gewünschte, bei
 * Belegung mit -2, -3 … Die Endung folgt dem Inhalt; umgewandelt wird nichts,
 * das Original bleibt maßgeblich.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $inhalt Bilddaten
 * @param string $name gewünschter Dateiname, mit oder ohne Endung
 * @param string $ersatz Name, wenn der gewünschte unbrauchbar ist (etwa die Artikelnummer)
 * @return int Bild (media_shop.id)
 * @throws ApiError SHOP_IMAGE_INVALID, SHOP_IMAGE_TOO_LARGE, SHOP_IMAGE_TYPE und die Fehler von shopMediaStore()
 */
function shopChannelImageMediaStore($db, string $inhalt, string $name, string $ersatz = 'bild'): int {
    if ('' === $inhalt) {
        throw new ApiError('SHOP_IMAGE_INVALID', 'Keine Bilddaten');
    }
    if (strlen($inhalt) > SHOP_MEDIA_MAX_IMAGE_BYTES) {
        throw new ApiError('SHOP_IMAGE_TOO_LARGE', 'Bild ist zu groß (höchstens 20 MB)');
    }
    // Die Endung aus dem Inhalt, nicht aus dem Namen: ein umbenanntes PDF
    // wäre sonst ein „Bild"
    $info = @getimagesizefromstring($inhalt);
    $endung = array_search($info['mime'] ?? '', SHOP_MEDIA_IMAGE_TYPES, true);
    if (false === $endung) {
        throw new ApiError('SHOP_IMAGE_TYPE', 'Nur JPG, PNG, GIF, WEBP oder AVIF'.('' !== $name ? ': '.$name : ''));
    }

    $verzeichnis = shopMediaDir($db, 'images', true);
    $vorhanden = $db->getOne(
        "SELECT id, filename FROM media_shop WHERE kind = 'image' AND sha256 = :sha256 ORDER BY id LIMIT 1",
        [':sha256' => hash('sha256', $inhalt)]
    );
    if ($vorhanden && is_file($verzeichnis.'/'.$vorhanden['filename'])) {
        return (int)$vorhanden['id'];
    }

    // Freier Name in der Bibliothek
    $basis = '';
    foreach ([pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME), $ersatz, 'bild'] as $vorschlag) {
        try {
            $basis = pathinfo(shopMediaName(trim((string)$vorschlag).'.'.$endung), PATHINFO_FILENAME);
            break;
        } catch (ApiError $e) {
            // unbrauchbarer Name (leer, zu lang): der nächste Vorschlag
        }
    }
    $datei = $basis.'.'.$endung;
    for ($nummer = 2; file_exists($verzeichnis.'/'.$datei); $nummer++) {
        $datei = $basis.'-'.$nummer.'.'.$endung;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'oserp-channel');
    try {
        if (false === file_put_contents($tmp, $inhalt)) {
            throw new ApiError('SHOP_IMAGE_WRITE', 'Bild konnte nicht gespeichert werden');
        }
        $zeile = shopMediaStore($db, 'image', $tmp, $datei, false, false);
    } finally {
        @unlink($tmp);
    }
    return (int)$zeile['id'];
}

/**
 * Bild der Bibliothek zu einem Dateinamen — notfalls von der Webseite geholt
 *
 * Für Bilder, die ein Artikel nennt (parts_ext.hugoshop_images). Liegt die
 * Datei in der Bibliothek, gilt sie (ein fehlender Eintrag in media_shop wird
 * nachgetragen). Fehlt sie dort — Webseiten, deren Medien noch nicht
 * übernommen sind —, holt OSERP sie über images_link des HugoShops (relative
 * Muster gelten zur base_url) und legt sie unter ihrem Namen in die Bibliothek.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $name Dateiname, Verzeichnisanteile werden abgestreift
 * @param int $hugoKanal HugoShop, von dessen Webseite ein fehlendes Bild kommt; 0 = nicht holen
 * @return int Bild (media_shop.id)
 * @throws ApiError SHOP_IMAGE_SOURCE und die Fehler von shopMediaStore()
 */
function shopChannelImageMedia($db, string $name, int $hugoKanal = 0): int {
    $name = basename(str_replace('\\', '/', trim($name)));
    if ('' === $name) {
        throw new ApiError('SHOP_IMAGE_SOURCE', 'Bild ohne Dateinamen');
    }
    $datei = shopMediaDir($db, 'images').'/'.$name;
    if (is_file($datei)) {
        $zeile = $db->getOne("SELECT id FROM media_shop WHERE kind = 'image' AND filename = :name", [':name' => $name]);
        if (!$zeile) {
            // von Hand abgelegt: eintragen, das Vorschaubild gleich mit
            $zeile = shopMediaRegister($db, 'image', $name, shopMediaThumbnail($db, $name));
        }
        return (int)$zeile['id'];
    }
    if ($hugoKanal <= 0) {
        throw new ApiError('SHOP_IMAGE_SOURCE', 'Bild '.$name.' fehlt in der Medienbibliothek');
    }

    $adresse = shopLink(shopChannelValue($db, $hugoKanal, 'images_link'), $name);
    if (!preg_match('#^https?://#i', $adresse)) {
        $basis = rtrim(shopChannelValue($db, $hugoKanal, 'base_url'), '/');
        if ('' === $basis) {
            throw new ApiError('SHOP_IMAGE_SOURCE', 'Bild '.$name.' fehlt in der Medienbibliothek und ist nicht abrufbar: keine vollständige Adresse (images_link, base_url)');
        }
        $adresse = $basis.'/'.ltrim($adresse, '/');
    }

    $curl = curl_init($adresse);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $inhalt = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (false === $inhalt || $status >= 400 || '' === $inhalt) {
        throw new ApiError('SHOP_IMAGE_SOURCE', 'Bild '.$name.' fehlt in der Medienbibliothek und ist nicht abrufbar ('.$adresse.', HTTP '.$status.')');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'oserp-channel');
    try {
        file_put_contents($tmp, $inhalt);
        $zeile = shopMediaStore($db, 'image', $tmp, $name);
    } finally {
        @unlink($tmp);
    }
    return (int)$zeile['id'];
}

/**
 * Hängt Bilder der Bibliothek an einen Marktplatz-Kanal, hinten an
 *
 * Schon gezeigte Bilder bleiben, wo sie sind. Danach liegen die Fassungen im
 * Format des Kanals bereit.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param int $kanalId Marktplatz-Kanal
 * @param array $mediaIds Bilder (media_shop.id) in der gewünschten Reihenfolge
 * @return int Zahl der neu zugeordneten Bilder
 */
function shopChannelImagesAttach($db, int $partsId, int $kanalId, array $mediaIds): int {
    $mediaIds = array_values(array_unique(array_filter(array_map('intval', $mediaIds))));
    if ($partsId <= 0 || $kanalId <= 0 || !$mediaIds) {
        return 0;
    }
    $zeilen = $db->getAll(
        "INSERT INTO parts_channel_image_shop (parts_id, channel_id, media_id, sort)
         SELECT CAST(:parts_id AS integer), c.id, m.id,
                COALESCE((SELECT MAX(sort) + 1 FROM parts_channel_image_shop
                           WHERE parts_id = CAST(:parts_id_sort AS integer) AND channel_id = CAST(:kanal_sort AS integer)), 0)
                + neu.pos - 1
           FROM unnest(string_to_array(:ids, ',')::int[]) WITH ORDINALITY AS neu(id, pos)
           JOIN media_shop m ON m.id = neu.id AND m.kind = 'image'
           JOIN sales_channel_shop c ON c.id = CAST(:kanal AS integer) AND c.type <> 'hugoshop'
          ORDER BY neu.pos
         ON CONFLICT (parts_id, channel_id, media_id) WHERE media_id IS NOT NULL DO NOTHING
         RETURNING id",
        [':parts_id' => $partsId, ':parts_id_sort' => $partsId, ':kanal_sort' => $kanalId,
         ':ids' => implode(',', $mediaIds), ':kanal' => $kanalId]
    );
    shopChannelImagesPrepare($db, $partsId, $kanalId);
    return count($zeilen ?: []);
}

/**
 * Ordnet einem Marktplatz-Kanal Bilder der Bibliothek nach ihren Namen zu
 *
 * Für die Artikelkarte („aus den Bildern des Artikels“) und die Übernahme aus
 * dem HugoShop. Ein Bild, das der Artikel nennt, das der Bibliothek aber
 * fehlt, kommt von der Webseite des ersten HugoShops hinein
 * (shopChannelImageMedia).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param int $kanalId Marktplatz-Kanal
 * @param array $namen Dateinamen der Bibliothek
 * @param int $hugoKanal HugoShop für fehlende Bilder; 0 = der erste HugoShop
 * @return int Zahl der neu zugeordneten Bilder
 * @throws ApiError SHOP_IMAGE_SOURCE und die Fehler von shopMediaStore()
 */
function shopChannelImagesAssign($db, int $partsId, int $kanalId, array $namen, int $hugoKanal = 0): int {
    if ($hugoKanal <= 0) {
        $zeile = $db->getOne("SELECT shop_first_channel_id('hugoshop') AS id");
        $hugoKanal = (int)($zeile['id'] ?? 0);
    }
    $ids = [];
    foreach ($namen as $name) {
        if ('' !== trim((string)$name)) {
            $ids[] = shopChannelImageMedia($db, (string)$name, $hugoKanal);
        }
    }
    return shopChannelImagesAttach($db, $partsId, $kanalId, $ids);
}

/**
 * Legt ein hochgeladenes Bild in die Bibliothek und zeigt es in einem Marktplatz-Kanal
 *
 * Das Bild liegt im Format der Datei in der Bibliothek — einmal, auch wenn
 * mehrere Kanäle oder Artikel es zeigen. Das Format des Kanals entsteht als
 * Fassung daneben. Ein schon gezeigtes Bild kommt nicht doppelt in die Liste.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param int $kanalId Kanal
 * @param string $inhalt Bilddaten
 * @param string $name ursprünglicher Dateiname; leer = nach der Artikelnummer
 * @return string Dateiname in der Bibliothek
 * @throws ApiError SHOP_IMAGE_INVALID, SHOP_IMAGE_TOO_LARGE, SHOP_IMAGE_TYPE, SHOP_IMAGE_WRITE
 */
function shopChannelImageStore($db, int $partsId, int $kanalId, string $inhalt, string $name = ''): string {
    $artikel = $db->getOne("SELECT partnumber FROM parts WHERE id = :id", [':id' => $partsId]);
    if (!$artikel) {
        throw new ApiError('SHOP_IMAGE_INVALID', 'Artikel nicht gefunden');
    }
    $mediaId = shopChannelImageMediaStore($db, $inhalt, $name, (string)$artikel['partnumber']);
    shopChannelImagesAttach($db, $partsId, $kanalId, [$mediaId]);
    $zeile = $db->getOne("SELECT filename FROM media_shop WHERE id = :id", [':id' => $mediaId]);
    return (string)($zeile['filename'] ?? '');
}

/**
 * Übernimmt Kanalbilder von früher in die Medienbibliothek
 *
 * Zeilen ohne media_id nennen eine Datei im Artikelordner
 * data/<db>/parts/<id>/ (Prüfsumme als Name). Die Datei kommt unter einem
 * lesbaren Namen (<Artikelnummer>-<Kanalart>) in die Bibliothek — gibt es das
 * Bild dort schon, gilt das vorhandene —, die Zeile verweist danach darauf.
 * Die Kopie im Artikelordner entfällt, sobald keine Zeile sie mehr nennt. Die
 * Trigger stellen die Angebote neu ein: die öffentliche Adresse ändert sich.
 * Ohne Zeilen von früher nur die eine Abfrage — läuft deshalb vor jeder
 * Verwendung und im Läufer.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel, 0 = alle
 * @param callable|null $melden Meldung je Bild, das sich nicht übernehmen ließ
 * @return int Zahl der übernommenen Zeilen
 */
function shopChannelImagesAdopt($db, int $partsId = 0, ?callable $melden = null): int {
    $zeilen = $db->getAll(
        "SELECT i.id, i.parts_id, i.filename, p.partnumber, c.type
           FROM parts_channel_image_shop i
           JOIN parts p ON p.id = i.parts_id
           JOIN sales_channel_shop c ON c.id = i.channel_id
          WHERE i.media_id IS NULL
            AND (CAST(:parts_id AS integer) = 0 OR i.parts_id = CAST(:parts_id_gleich AS integer))
          ORDER BY i.parts_id, i.sort, i.id",
        [':parts_id' => $partsId, ':parts_id_gleich' => $partsId]
    ) ?: [];
    if (!$zeilen) {
        return 0;
    }

    $uebernommen = 0;
    $bilder = [];   // Pfad der Kopie => Bild der Bibliothek (dieselbe Datei in mehreren Kanälen)
    $kopien = [];   // Pfad der Kopie => [Artikel, Dateiname]
    foreach ($zeilen as $zeile) {
        $datei = basename((string)$zeile['filename']);
        $pfad = shopChannelImageDir($db, (int)$zeile['parts_id']).'/'.$datei;
        try {
            if (!isset($bilder[$pfad])) {
                $inhalt = is_file($pfad) ? (string)file_get_contents($pfad) : '';
                if ('' === $inhalt) {
                    throw new ApiError('SHOP_IMAGE_SOURCE', 'Datei fehlt oder ist leer');
                }
                $bilder[$pfad] = shopChannelImageMediaStore($db, $inhalt, $zeile['partnumber'].'-'.$zeile['type'].'.bild', (string)$zeile['partnumber']);
            }
        } catch (ApiError $e) {
            $grund = 'Kanalbild '.$zeile['parts_id'].'/'.$datei.' nicht in die Medienbibliothek übernommen: '.$e->getMessage();
            writeLog('[SHOP] '.$grund, true, DLOG_WRN);
            if (null !== $melden) {
                $melden($grund);
            }
            continue;
        }
        // Die Zeile verweist auf die Bibliothek; zeigt der Kanal das Bild
        // schon, fällt die Zeile weg
        $db->execute(
            "WITH umgehaengt AS (
                 UPDATE parts_channel_image_shop i
                    SET media_id = :media, filename = NULL
                  WHERE i.id = :id
                    AND i.media_id IS NULL
                    AND NOT EXISTS (SELECT 1 FROM parts_channel_image_shop d
                                     WHERE d.parts_id = i.parts_id AND d.channel_id = i.channel_id AND d.media_id = :media_gleich)
                 RETURNING i.id
             )
             -- media_id IS NULL: hat ein gleichzeitiger Aufruf die Zeile schon
             -- umgehängt, bleibt sie
             DELETE FROM parts_channel_image_shop
              WHERE id = :id_doppelt AND media_id IS NULL AND NOT EXISTS (SELECT 1 FROM umgehaengt)",
            [':media' => $bilder[$pfad], ':id' => (int)$zeile['id'], ':media_gleich' => $bilder[$pfad], ':id_doppelt' => (int)$zeile['id']]
        );
        $kopien[$pfad] = [(int)$zeile['parts_id'], $datei];
        $uebernommen++;
    }

    // Die Kopien im Artikelordner entfallen, sobald keine Zeile sie mehr nennt
    foreach ($kopien as $pfad => [$artikel, $datei]) {
        $genutzt = $db->getOne(
            "SELECT 1 AS da FROM parts_channel_image_shop WHERE parts_id = :parts_id AND filename = :datei LIMIT 1",
            [':parts_id' => $artikel, ':datei' => $datei]
        );
        if (!$genutzt && is_file($pfad)) {
            @unlink($pfad);
            @rmdir(dirname($pfad));   // nur, wenn der Artikelordner damit leer ist
        }
    }
    return $uebernommen;
}

/**
 * Stellt die Bilder der Marktplatz-Kanäle im Format ihres Kanals bereit
 *
 * Erst die Übernahme von früher (shopChannelImagesAdopt), dann je Bild, dessen
 * Format der Kanal nicht so bekommt, wie es in der Bibliothek liegt
 * (shop_channel_image_state), die Fassung im Format des Kanals: neu, wenn sie
 * fehlt, das Original sich geändert hat oder der Kanal ein anderes Format
 * verlangt. Ohne Artikel und Kanal (Läufer) gehen außerdem Fassungen, die
 * kein Kanal mehr braucht. Ohne Arbeit nur zwei Abfragen — läuft deshalb vor
 * dem Einstellen und im Läufer; der Feed für Kleinanzeigen liest die
 * Fassungen direkt.
 *
 * Scheitert eine Umwandlung (der Server kann das Format nicht schreiben),
 * steht es im Protokoll, und shop_channel_images() liefert bis dahin das
 * Original.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel, 0 = alle
 * @param int $kanalId Kanal, 0 = alle
 * @return int Zahl der erzeugten Fassungen
 */
function shopChannelImagesPrepare($db, int $partsId = 0, int $kanalId = 0): int {
    shopChannelImagesAdopt($db, $partsId);

    $zeilen = $db->getAll(
        "SELECT DISTINCT s.media_id, s.filename, s.sha256, s.source_format, s.format
           FROM shop_channel_image_state(CAST(:parts_id AS integer), CAST(:kanal AS integer)) s
          WHERE NOT s.legacy AND s.format <> s.source_format AND s.variant_sha256 IS NULL
          ORDER BY s.media_id, s.format",
        [':parts_id' => $partsId, ':kanal' => $kanalId]
    ) ?: [];

    $erzeugt = 0;
    $cfg = $zeilen ? fmGetDirConfig() : [];
    foreach ($zeilen as $zeile) {
        $mediaId = (int)$zeile['media_id'];
        try {
            $quelle = shopMediaDir($db, 'images').'/'.$zeile['filename'];
            if (!is_file($quelle)) {
                throw new ApiError('SHOP_IMAGE_SOURCE', 'Bild fehlt in der Medienbibliothek');
            }
            $inhalt = shopChannelImageConvert((string)file_get_contents($quelle), (string)$zeile['source_format'],
                                              (string)$zeile['format'], (string)$zeile['filename']);
            // Erst daneben schreiben, dann umbenennen: part-image.php liefert nie eine halbe Datei
            $ziel = shopMediaVariantPath($db, $mediaId, (string)$zeile['format'], true);
            $tmp = $ziel.'.'.getmypid().'.tmp';
            if (false === file_put_contents($tmp, $inhalt) || !rename($tmp, $ziel)) {
                @unlink($tmp);
                throw new ApiError('SHOP_IMAGE_WRITE', 'Fassung konnte nicht gespeichert werden: '.$ziel);
            }
            if ('' !== ($cfg['group'] ?? '')) {
                @chgrp($ziel, $cfg['group']);
            }
            @chmod($ziel, 0664);
        } catch (ApiError $e) {
            writeLog('[SHOP] Bild '.$zeile['filename'].' nicht nach '.strtoupper((string)$zeile['format']).' umgewandelt: '.$e->getMessage(), true, DLOG_WRN);
            continue;
        }
        $db->execute(
            "INSERT INTO media_variant_shop (media_id, format, source_sha256, sha256, size)
             VALUES (:media, :format, :quelle, :sha256, :size)
             ON CONFLICT (media_id, format) DO UPDATE
                SET source_sha256 = EXCLUDED.source_sha256, sha256 = EXCLUDED.sha256, size = EXCLUDED.size, mtime = now()",
            [':media' => $mediaId, ':format' => $zeile['format'], ':quelle' => $zeile['sha256'],
             ':sha256' => hash('sha256', $inhalt), ':size' => strlen($inhalt)]
        );
        $erzeugt++;
    }

    // Im Läufer: Fassungen, die kein Kanal mehr braucht (Bild entfernt, Format des Kanals geändert)
    if (0 === $partsId && 0 === $kanalId) {
        $weg = $db->getAll(
            "DELETE FROM media_variant_shop v
              WHERE NOT EXISTS (SELECT 1 FROM shop_channel_image_state(0, 0) s
                                 WHERE s.media_id = v.media_id AND s.format = v.format AND s.format <> s.source_format)
             RETURNING v.media_id, v.format"
        ) ?: [];
        foreach ($weg as $fassung) {
            try {
                @unlink(shopMediaVariantPath($db, (int)$fassung['media_id'], (string)$fassung['format']));
            } catch (ApiError $e) {
                // unbekanntes Format in der Tabelle: keine Datei dazu
            }
        }
    }
    return $erzeugt;
}

/**
 * Entfernt ein Bild aus einem Kanal
 *
 * Ein Bild, das für den Kanal in die Bibliothek kam (hochgeladen, von eBay
 * oder aus den Kopien von früher übernommen — sein Eintrag ist nicht älter
 * als die Zuordnung), verschwindet auch aus der Bibliothek, wenn es danach
 * niemand mehr verwendet: kein Artikel nennt es (HugoShop), kein Kanal zeigt
 * es, und die offene Artikelkarte führt es nicht in ihrer ungespeicherten
 * Liste ($behalten). So bleibt nichts liegen, was nur für den Marktplatz
 * hochgeladen wurde. Ein Bild, das schon vorher in der Bibliothek lag und nur
 * zugeordnet wurde, bleibt dort.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $bildId Zeile in parts_channel_image_shop
 * @param array $behalten Dateinamen, die in der Bibliothek bleiben sollen
 * @return array|null parts_id und channel_id des Bildes, null wenn es das nicht gab
 */
function shopChannelImageDelete($db, int $bildId, array $behalten = []): ?array {
    $zeile = $db->getOne(
        "WITH weg AS (
             DELETE FROM parts_channel_image_shop WHERE id = :id
             RETURNING parts_id, channel_id, filename, media_id, itime
         )
         SELECT weg.parts_id, weg.channel_id, weg.filename, weg.media_id, m.filename AS media_name,
                COALESCE(m.itime >= weg.itime - interval '1 minute', false) AS fuer_kanal,
                EXISTS (SELECT 1 FROM parts_channel_image_shop i
                         WHERE i.parts_id = weg.parts_id AND i.filename = weg.filename
                           AND i.id <> :id_rest) AS noch_genutzt
           FROM weg
           LEFT JOIN media_shop m ON m.id = weg.media_id",
        [':id' => $bildId, ':id_rest' => $bildId]
    );
    if (!$zeile) {
        return null;
    }

    if (null === $zeile['media_id']) {
        // Zeile von früher: die Kopie im Artikelordner geht, wenn kein Kanal sie mehr nennt
        if (!in_array($zeile['noch_genutzt'], [true, 't', 1, '1'], true)) {
            $pfad = shopChannelImageDir($db, (int)$zeile['parts_id']).'/'.basename((string)$zeile['filename']);
            if (is_file($pfad)) {
                @unlink($pfad);
            }
        }
    } elseif ('' !== (string)$zeile['media_name']) {
        $name = (string)$zeile['media_name'];
        $behalten = array_map(fn($n) => basename(str_replace('\\', '/', trim((string)$n))), $behalten);
        if (in_array($zeile['fuer_kanal'], [true, 't', 1, '1'], true) && !in_array($name, $behalten, true)) {
            try {
                // wirft MEDIA_IN_USE, solange ein Artikel das Bild verwendet
                shopMediaFileDelete($db, 'image', $name);
            } catch (ApiError $e) {
                // bleibt in der Bibliothek
            }
        }
    }
    return ['parts_id' => (int)$zeile['parts_id'], 'channel_id' => (int)$zeile['channel_id']];
}

/**
 * Setzt die Reihenfolge der Bilder eines Kanals
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param int $kanalId Kanal
 * @param array $ids Bildkennungen in der neuen Reihenfolge, das erste ist das Hauptbild
 * @return void
 */
function shopChannelImageSort($db, int $partsId, int $kanalId, array $ids): void {
    $db->execute(
        "UPDATE parts_channel_image_shop i
            SET sort = neu.pos - 1
           FROM unnest(string_to_array(:ids, ',')::int[]) WITH ORDINALITY AS neu(id, pos)
          WHERE i.id = neu.id AND i.parts_id = :parts_id AND i.channel_id = :kanal",
        [':ids' => implode(',', array_map('intval', $ids)), ':parts_id' => $partsId, ':kanal' => $kanalId]
    );
}

/**
 * Gibt einem Marktplatz-Kanal ohne eigene Bilder die Bilder des Artikels
 *
 * Quelle aus shop_channel_image_source(): ein anderer Kanal mit Kanalbildern,
 * sonst der HugoShop (die Bilder, die der Artikel nennt). Für
 * Artikel, deren Kanal in der Artikelkarte nur angehakt wurde — beim
 * Aufnehmen aus den Vorschlägen geschieht dasselbe gleich. Hat der Kanal
 * schon Bilder, nur die eine Abfrage.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param int $kanalId Marktplatz-Kanal
 * @return int Zahl der übernommenen Bilder
 * @throws ApiError die Fehler von shopChannelImageCopy()
 */
function shopChannelImagesFill($db, int $partsId, int $kanalId): int {
    $zeile = $db->getOne(
        "SELECT shop_channel_image_source(CAST(:parts_id AS integer), CAST(:kanal AS integer)) AS quelle",
        [':parts_id' => $partsId, ':kanal' => $kanalId]
    );
    $quelle = (int)($zeile['quelle'] ?? 0);
    return $quelle > 0 ? shopChannelImageCopy($db, $partsId, $quelle, $kanalId) : 0;
}

/**
 * Übernimmt die Bilder eines Artikels von einem Kanal in einen anderen
 *
 * Vorhandene Bilder des Ziels bleiben; hinzu kommen die fehlenden, hinten
 * angehängt. Kopiert wird nichts: Quelle und Ziel zeigen dieselben Bilder der
 * Bibliothek.
 *
 * Die Bildnamen des HugoShops (parts_ext.hugoshop_images) gelten für alle
 * HugoShops (M6); zwischen zwei HugoShops gibt es deshalb nichts zu
 * übernehmen. In einen HugoShop übernimmt diese Funktion nichts — seine Liste
 * pflegt die Artikelkarte im Abschnitt Medien.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param mixed $von Quellkanal: Kennung oder Art (shopChannelParam)
 * @param mixed $nach Zielkanal: Kennung oder Art
 * @return int Zahl der Bilder der Quelle
 * @throws ApiError SHOP_CHANNEL_UNKNOWN, SHOP_IMAGE_COPY_UNAVAILABLE, SHOP_IMAGE_SOURCE und die Fehler von shopMediaStore()
 */
function shopChannelImageCopy($db, int $partsId, $von, $nach): int {
    $von = shopChannelParam($db, $von);
    $nach = shopChannelParam($db, $nach);
    if ($von['id'] === $nach['id'] || ('hugoshop' === $von['type'] && 'hugoshop' === $nach['type'])) {
        return 0;
    }
    if ('hugoshop' === $nach['type']) {
        throw new ApiError('SHOP_IMAGE_COPY_UNAVAILABLE',
            'Die Bilder des HugoShops pflegt die Artikelkarte im Abschnitt Medien.');
    }

    // HugoShop → Marktplatz: die Bilder, die der Artikel nennt
    if ('hugoshop' === $von['type']) {
        $zeile = $db->getOne("SELECT hugoshop_images FROM parts_ext WHERE parts_id = :id", [':id' => $partsId]);
        $namen = json_decode((string)($zeile['hugoshop_images'] ?? '[]'), true) ?: [];
        shopChannelImagesAssign($db, $partsId, $nach['id'], $namen, $von['id']);
        return count($namen);
    }

    // Marktplatz → Marktplatz: dieselben Bilder, neue Zeilen in der Reihenfolge der Quelle
    shopChannelImagesAdopt($db, $partsId);
    $quelle = $db->getAll(
        "SELECT media_id FROM parts_channel_image_shop
          WHERE parts_id = :parts_id AND channel_id = :quelle AND media_id IS NOT NULL
          ORDER BY sort, id",
        [':parts_id' => $partsId, ':quelle' => $von['id']]
    ) ?: [];
    shopChannelImagesAttach($db, $partsId, $nach['id'], array_column($quelle, 'media_id'));
    return count($quelle);
}
