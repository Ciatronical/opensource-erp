<?php
// backend/api/shop/lib/channel_images.php
//
// Bilder je Verkaufskanal (dev/shop-verkaufskanaele.md, V12, V17).
//
// Die Marktplätze (eBay, später Amazon) führen ihre Bilder in
// parts_channel_image_shop; die Dateien liegen unter data/<db>/parts/<id>/ und
// gehen über backend/webhook/part-image.php an den Marktplatz. Der HugoShop
// führt seine Bilder weiter als Dateinamen auf der Webseite
// (parts_ext.hugoshop_images, E6).
//
// Übernehmen zwischen den Kanälen:
//   HugoShop  → Marktplatz  über die Adresse aus images_link
//   Marktplatz → HugoShop   nicht: die Bilder der Webseite liegen bei HugoCMS
//                           auf dem Webserver, dorthin gibt es keinen Weg (V17)
//   Marktplatz → Marktplatz dieselben Dateien, nur neue Zeilen
//
// Braucht customer_vendor/filemanager.php (Datenverzeichnis, Größengrenze).

/** Erkannte Bildarten: Endung => MIME-Typ */
const SHOP_CHANNEL_IMAGE_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                                  'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif'];

/**
 * Bildformate, die ein Marktplatz annimmt; alles andere legt OSERP für den
 * Kanal als JPEG ab (shopChannelImageStore, shopChannelImagesFit). Dieselbe
 * Datei kann so in einem Kanal als WebP und im anderen als JPEG liegen.
 *
 * eBay holt die Bilder über part-image.php ab — Picture Services nimmt laut
 * Doku auch AVIF und HEIC, hier bewusst nur, was sicher durchgeht.
 * Kleinanzeigen nennt keine Formate: JPEG und PNG, wie die Webseite sie
 * zeigt. Amazon: JPEG, PNG, GIF.
 */
const SHOP_CHANNEL_IMAGE_FORMATS = ['ebay' => ['jpg', 'png', 'gif', 'webp'], 'kleinanzeigen' => ['jpg', 'png'],
                                    'amazon' => ['jpg', 'png', 'gif']];

/**
 * Übernimmt die Kanalbilder eines Artikels für einen anderen („Als neu verwenden“)
 *
 * Zeilen und Dateien; schon vorhandene Bilder des Ziels bleiben. Bilder sind
 * je Artikel abgelegt, deshalb werden die Dateien kopiert.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $von Vorlage
 * @param int $nach neuer Artikel
 * @return int Anzahl übernommener Bilder
 */
function shopChannelImagesCopyFromPart($db, int $von, int $nach): int {
    if ($von <= 0 || $nach <= 0 || $von === $nach) return 0;
    $zeilen = $db->getAll(
        "INSERT INTO parts_channel_image_shop (parts_id, channel_id, filename, sort)
         SELECT :nach, i.channel_id, i.filename, i.sort
           FROM parts_channel_image_shop i
          WHERE i.parts_id = :von
         ON CONFLICT (parts_id, channel_id, filename) DO NOTHING
         RETURNING filename",
        [':nach' => $nach, ':von' => $von]
    );
    if (!$zeilen) return 0;

    $quelle = shopChannelImageDir($db, $von);
    $ziel   = shopChannelImageDir($db, $nach, true);
    foreach (array_unique(array_column($zeilen, 'filename')) as $datei) {
        $datei = basename((string)$datei);
        if (is_file($quelle.'/'.$datei) && !is_file($ziel.'/'.$datei)) {
            copy($quelle.'/'.$datei, $ziel.'/'.$datei);
        }
    }
    return count($zeilen);
}

/**
 * Verzeichnis der Bilder eines Artikels
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
    } elseif (in_array($angabe, ['hugoshop', 'ebay', 'amazon', 'kleinanzeigen'], true)) {
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
 * Die Adresse ist relativ: die Oberfläche läuft auf demselben Server wie
 * backend/webhook/part-image.php.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param int $kanalId Kanal
 * @return array Liste aus id, filename, sort, url
 */
function shopChannelImages($db, int $partsId, int $kanalId): array {
    return $db->getAll(
        "SELECT id, filename, sort,
                '/webhook/part-image.php?db=' || current_database() || '&id=' || parts_id || '&f=' || filename AS url
           FROM parts_channel_image_shop
          WHERE parts_id = :parts_id AND channel_id = :kanal
          ORDER BY sort, id",
        [':parts_id' => $partsId, ':kanal' => $kanalId]
    );
}

/**
 * Nimmt ein Kanal dieses Bildformat? Kanäle ohne Liste (HugoShop) nehmen alles
 *
 * @param string $typ Art des Kanals (ebay, kleinanzeigen, …)
 * @param string $endung Format (jpg, png, gif, webp, avif)
 * @return bool
 */
function shopChannelImageAccepted(string $typ, string $endung): bool {
    return !isset(SHOP_CHANNEL_IMAGE_FORMATS[$typ]) || in_array($endung, SHOP_CHANNEL_IMAGE_FORMATS[$typ], true);
}

/**
 * Wandelt Bilddaten in JPEG (Qualität 90, Transparenz auf Weiß)
 *
 * Liest über shopMediaLoad — GD, AVIF notfalls über ffmpeg.
 *
 * @param string $inhalt Bilddaten
 * @param string $format Format der Daten (png, gif, webp, avif)
 * @param string $name Dateiname für die Meldung
 * @return string JPEG-Bilddaten
 * @throws ApiError SHOP_IMAGE_TYPE
 */
function shopChannelImageToJpeg(string $inhalt, string $format, string $name = ''): string {
    $tmp = tempnam(sys_get_temp_dir(), 'oserp-channel');
    try {
        file_put_contents($tmp, $inhalt);
        $bild = shopMediaCanRead($format) ? shopMediaLoad($tmp, $format) : false;
        if (false === $bild) {
            throw new ApiError('SHOP_IMAGE_TYPE', strtoupper($format).' kann dieser Server nicht lesen'.('' !== $name ? ': '.$name : ''));
        }
        $flach = imagecreatetruecolor(imagesx($bild), imagesy($bild));
        imagefill($flach, 0, 0, imagecolorallocate($flach, 255, 255, 255));
        imagecopy($flach, $bild, 0, 0, 0, 0, imagesx($bild), imagesy($bild));
        ob_start();
        imagejpeg($flach, null, 90);
        return (string)ob_get_clean();
    } finally {
        @unlink($tmp);
    }
}

/**
 * Legt ein Bild in einem Marktplatz-Kanal ab
 *
 * Der Dateiname ist die Prüfsumme des Inhalts: dasselbe Bild liegt nur einmal
 * im Ordner, auch wenn es mehrere Kanäle nutzen. Ein schon vorhandenes Bild
 * kommt nicht doppelt in die Liste.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param int $kanalId Kanal
 * @param string $inhalt Bilddaten
 * @param string $name ursprünglicher Dateiname, für die Endung
 * @return string abgelegter Dateiname
 * @throws ApiError SHOP_IMAGE_INVALID, SHOP_IMAGE_TOO_LARGE, SHOP_IMAGE_TYPE, SHOP_IMAGE_WRITE
 */
function shopChannelImageStore($db, int $partsId, int $kanalId, string $inhalt, string $name = ''): string {
    if ('' === $inhalt) {
        throw new ApiError('SHOP_IMAGE_INVALID', 'Keine Bilddaten');
    }
    if (strlen($inhalt) > FM_MAX_FILESIZE) {
        throw new ApiError('SHOP_IMAGE_TOO_LARGE', 'Bild ist zu groß (höchstens 20 MB)');
    }

    // Die Endung aus dem Inhalt, nicht aus dem Namen: ein umbenanntes PDF
    // wäre sonst ein „Bild"
    $info = @getimagesizefromstring($inhalt);
    $endung = array_search($info['mime'] ?? '', SHOP_CHANNEL_IMAGE_TYPES, true);
    if (false === $endung) {
        throw new ApiError('SHOP_IMAGE_TYPE', 'Nur JPG, PNG, GIF, WEBP oder AVIF'.('' !== $name ? ': '.$name : ''));
    }
    $endung = 'jpeg' === $endung ? 'jpg' : $endung;

    // Nimmt der Marktplatz das Format nicht (AVIF aus dem HugoShop, WebP bei
    // Kleinanzeigen), liegt das Bild für diesen Kanal als JPEG
    $kanal = $db->getOne("SELECT type FROM sales_channel_shop WHERE id = :id", [':id' => $kanalId]);
    if (!shopChannelImageAccepted((string)($kanal['type'] ?? ''), $endung)) {
        $inhalt = shopChannelImageToJpeg($inhalt, $endung, $name);
        $endung = 'jpg';
    }

    $datei = sha1($inhalt).'.'.$endung;
    $pfad = shopChannelImageDir($db, $partsId, true).'/'.$datei;
    if (!is_file($pfad) && false === file_put_contents($pfad, $inhalt)) {
        throw new ApiError('SHOP_IMAGE_WRITE', 'Bild konnte nicht gespeichert werden');
    }
    @chmod($pfad, 0664);

    $db->execute(
        "INSERT INTO parts_channel_image_shop (parts_id, channel_id, filename, sort)
         SELECT :parts_id, :kanal, :datei,
                COALESCE((SELECT MAX(sort) + 1 FROM parts_channel_image_shop
                           WHERE parts_id = :parts_id_sort AND channel_id = :kanal_sort), 0)
         ON CONFLICT (parts_id, channel_id, filename) DO NOTHING",
        [':parts_id' => $partsId, ':kanal' => $kanalId, ':datei' => $datei,
         ':parts_id_sort' => $partsId, ':kanal_sort' => $kanalId]
    );
    return $datei;
}

/**
 * Zieht Kanalbilder in Formaten nach, die der Marktplatz nicht nimmt
 *
 * Für Bestände von früher und Dateien, die von einem anderen Marktplatz
 * übernommen wurden (dieselbe Datei, neue Zeile): Die Zeile bekommt eine
 * JPEG-Fassung, die alte Datei bleibt, solange ein anderer Kanal sie nennt.
 * Ohne Treffer nur die eine Abfrage — läuft deshalb vor dem Einstellen und
 * im Läufer.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel, 0 = alle
 * @param int $kanalId Kanal, 0 = alle
 * @return int Zahl der umgewandelten Bilder
 */
function shopChannelImagesFit($db, int $partsId = 0, int $kanalId = 0): int {
    $zeilen = $db->getAll(
        "SELECT i.id, i.parts_id, i.channel_id, i.filename
           FROM parts_channel_image_shop i
           JOIN sales_channel_shop c ON c.id = i.channel_id
          WHERE (CAST(:parts_id AS integer) = 0 OR i.parts_id = CAST(:parts_id_gleich AS integer))
            AND (CAST(:kanal AS integer) = 0 OR i.channel_id = CAST(:kanal_gleich AS integer))
            AND jsonb_exists(CAST(:formate AS jsonb), c.type)
            AND NOT jsonb_exists(CAST(:formate_typ AS jsonb) -> c.type,
                                 lower(substring(i.filename FROM '\.([^.]+)$')))
          ORDER BY i.parts_id, i.id",
        [':parts_id' => $partsId, ':parts_id_gleich' => $partsId, ':kanal' => $kanalId, ':kanal_gleich' => $kanalId,
         ':formate' => json_encode(SHOP_CHANNEL_IMAGE_FORMATS), ':formate_typ' => json_encode(SHOP_CHANNEL_IMAGE_FORMATS)]
    ) ?: [];

    $umgewandelt = 0;
    foreach ($zeilen as $zeile) {
        $verzeichnis = shopChannelImageDir($db, (int)$zeile['parts_id']);
        $alt = basename((string)$zeile['filename']);
        $pfad = $verzeichnis.'/'.$alt;
        try {
            $inhalt = is_file($pfad) ? (string)file_get_contents($pfad) : '';
            $endung = array_search(@getimagesizefromstring($inhalt)['mime'] ?? '', SHOP_CHANNEL_IMAGE_TYPES, true);
            if (false === $endung) {
                throw new ApiError('SHOP_IMAGE_TYPE', 'Bild fehlt oder ist unlesbar');
            }
            $jpeg = shopChannelImageToJpeg($inhalt, 'jpeg' === $endung ? 'jpg' : $endung, $alt);
        } catch (ApiError $e) {
            writeLog('[SHOP] Kanalbild '.$zeile['parts_id'].'/'.$alt.' nicht umgewandelt: '.$e->getMessage(), true, DLOG_WRN);
            continue;
        }
        $neu = sha1($jpeg).'.jpg';
        if (!is_file($verzeichnis.'/'.$neu) && false === file_put_contents($verzeichnis.'/'.$neu, $jpeg)) {
            continue;
        }
        @chmod($verzeichnis.'/'.$neu, 0664);
        // Die Zeile zeigt auf die JPEG-Fassung; hat der Kanal sie schon, fällt
        // die Zeile weg. Die alte Datei geht, wenn kein Kanal sie mehr nennt.
        $rest = $db->getOne(
            "WITH umbenannt AS (
                 UPDATE parts_channel_image_shop i SET filename = :neu
                  WHERE i.id = :id
                    AND NOT EXISTS (SELECT 1 FROM parts_channel_image_shop d
                                     WHERE d.parts_id = i.parts_id AND d.channel_id = i.channel_id AND d.filename = :neu_gleich)
                 RETURNING i.id
             ), doppelt AS (
                 DELETE FROM parts_channel_image_shop
                  WHERE id = :id_doppelt AND NOT EXISTS (SELECT 1 FROM umbenannt)
                 RETURNING id
             )
             SELECT EXISTS (SELECT 1 FROM parts_channel_image_shop
                             WHERE parts_id = :parts_id AND filename = :alt AND id <> :id_rest) AS noch_genutzt,
                    (SELECT count(*) FROM doppelt) AS doppelt",
            [':neu' => $neu, ':id' => (int)$zeile['id'], ':neu_gleich' => $neu, ':id_doppelt' => (int)$zeile['id'],
             ':parts_id' => (int)$zeile['parts_id'], ':alt' => $alt, ':id_rest' => (int)$zeile['id']]
        );
        if (!in_array($rest['noch_genutzt'] ?? false, [true, 't', 1, '1'], true)) {
            @unlink($pfad);
        }
        $umgewandelt++;
    }
    return $umgewandelt;
}

/**
 * Entfernt ein Bild aus einem Kanal
 *
 * Die Datei verschwindet erst, wenn kein Kanal sie mehr nutzt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $bildId Zeile in parts_channel_image_shop
 * @return array|null parts_id und channel_id des Bildes, null wenn es das nicht gab
 */
function shopChannelImageDelete($db, int $bildId): ?array {
    $zeile = $db->getOne(
        "WITH weg AS (
             DELETE FROM parts_channel_image_shop WHERE id = :id
             RETURNING parts_id, channel_id, filename
         )
         SELECT weg.parts_id, weg.channel_id, weg.filename,
                EXISTS (SELECT 1 FROM parts_channel_image_shop i
                         WHERE i.parts_id = weg.parts_id AND i.filename = weg.filename
                           AND i.id <> :id_rest) AS noch_genutzt
           FROM weg",
        [':id' => $bildId, ':id_rest' => $bildId]
    );
    if (!$zeile) {
        return null;
    }

    if (!in_array($zeile['noch_genutzt'], [true, 't', 1, '1'], true)) {
        $pfad = shopChannelImageDir($db, (int)$zeile['parts_id']).'/'.basename((string)$zeile['filename']);
        if (is_file($pfad)) {
            @unlink($pfad);
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
 * Lädt ein Bild der Webseite eines HugoShops
 *
 * Über die Adresse aus images_link (relative Muster gelten zur base_url des
 * Kanals) — die Bilder liegen auf dem Webserver der Webseite.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop, von dessen Webseite das Bild kommt
 * @param string $name Dateiname aus parts_ext.hugoshop_images
 * @return string Bilddaten
 * @throws ApiError SHOP_IMAGE_SOURCE
 */
function shopHugoshopImageContent($db, int $kanal, string $name): string {
    $name = basename($name);

    $adresse = shopLink(shopChannelValue($db, $kanal, 'images_link'), $name);
    if (!preg_match('#^https?://#i', $adresse)) {
        $basis = rtrim(shopChannelValue($db, $kanal, 'base_url'), '/');
        if ('' === $basis) {
            throw new ApiError('SHOP_IMAGE_SOURCE', 'Bild '.$name.': keine vollständige Adresse (images_link, base_url)');
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
        throw new ApiError('SHOP_IMAGE_SOURCE', 'Bild '.$name.' nicht abrufbar ('.$adresse.', HTTP '.$status.')');
    }
    return $inhalt;
}

/**
 * Übernimmt die Bilder eines Artikels von einem Kanal in einen anderen
 *
 * Vorhandene Bilder des Ziels bleiben; hinzu kommen die fehlenden, hinten
 * angehängt.
 *
 * Die Bildnamen des HugoShops (parts_ext.hugoshop_images) gelten für alle
 * HugoShops (M6), die Dateien liegen auf der Webseite bei HugoCMS: gelesen
 * wird von dort (HugoShop → Marktplatz). In einen HugoShop überträgt OSERP
 * keine Bilder, zwischen zwei HugoShops wird nichts kopiert.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $partsId Artikel
 * @param mixed $von Quellkanal: Kennung oder Art (shopChannelParam)
 * @param mixed $nach Zielkanal: Kennung oder Art
 * @return int Zahl der übernommenen Bilder
 * @throws ApiError SHOP_CHANNEL_UNKNOWN, SHOP_IMAGE_COPY_UNAVAILABLE, SHOP_IMAGE_SOURCE und die Fehler von shopChannelImageStore()
 */
function shopChannelImageCopy($db, int $partsId, $von, $nach): int {
    $von = shopChannelParam($db, $von);
    $nach = shopChannelParam($db, $nach);
    if ($von['id'] === $nach['id'] || ('hugoshop' === $von['type'] && 'hugoshop' === $nach['type'])) {
        return 0;
    }

    // HugoShop → Marktplatz
    if ('hugoshop' === $von['type']) {
        $zeile = $db->getOne("SELECT hugoshop_images FROM parts_ext WHERE parts_id = :id", [':id' => $partsId]);
        $namen = json_decode((string)($zeile['hugoshop_images'] ?? '[]'), true) ?: [];
        foreach ($namen as $name) {
            shopChannelImageStore($db, $partsId, $nach['id'],
                                  shopHugoshopImageContent($db, $von['id'], (string)$name), (string)$name);
        }
        return count($namen);
    }

    $quelle = shopChannelImages($db, $partsId, $von['id']);

    // Marktplatz → HugoShop: die Bilder der Webseite liegen bei HugoCMS auf
    // dem Webserver — dorthin überträgt OSERP keine Bilder
    if ('hugoshop' === $nach['type']) {
        throw new ApiError('SHOP_IMAGE_COPY_UNAVAILABLE',
            'Die Bilder der Webseite liegen bei HugoCMS — dorthin kann OSERP nichts übertragen.');
    }

    // Marktplatz → Marktplatz: dieselben Dateien im selben Ordner
    $ziel = $nach['id'];
    $db->execute(
        "INSERT INTO parts_channel_image_shop (parts_id, channel_id, filename, sort)
         SELECT q.parts_id, :ziel, q.filename,
                COALESCE((SELECT MAX(sort) + 1 FROM parts_channel_image_shop
                           WHERE parts_id = :parts_id_sort AND channel_id = :ziel_sort), 0) + q.sort
           FROM parts_channel_image_shop q
          WHERE q.parts_id = :parts_id AND q.channel_id = :quelle
         ON CONFLICT (parts_id, channel_id, filename) DO NOTHING",
        [':ziel' => $ziel, ':parts_id' => $partsId, ':quelle' => $von['id'],
         ':parts_id_sort' => $partsId, ':ziel_sort' => $ziel]
    );
    // Nimmt das Ziel ein Format der Quelle nicht (WebP von eBay zu
    // Kleinanzeigen), bekommt es seine eigene JPEG-Fassung
    shopChannelImagesFit($db, $partsId, $ziel);
    return count($quelle);
}
