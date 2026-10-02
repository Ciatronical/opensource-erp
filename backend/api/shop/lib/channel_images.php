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
//   HugoShop  → Marktplatz  Datei aus dem Bildverzeichnis der Webseite
//                           (Betriebsart lokal) oder über shop_images_link
//   Marktplatz → HugoShop   nur in der Betriebsart lokal: Datei ins
//                           Bildverzeichnis der Webseite. In der Betriebsart
//                           HugoCMS gibt es keinen Weg dorthin (V17).
//   Marktplatz → Marktplatz dieselben Dateien, nur neue Zeilen
//
// Braucht customer_vendor/filemanager.php (Datenverzeichnis, Größengrenze).

/** Erlaubte Bildarten: Endung => MIME-Typ */
const SHOP_CHANNEL_IMAGE_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                                  'gif' => 'image/gif', 'webp' => 'image/webp'];

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
    } elseif (in_array($angabe, ['hugoshop', 'ebay', 'amazon'], true)) {
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
        throw new ApiError('SHOP_IMAGE_TYPE', 'Nur JPG, PNG, GIF oder WEBP'.('' !== $name ? ': '.$name : ''));
    }
    $endung = 'jpeg' === $endung ? 'jpg' : $endung;

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
 * In der Betriebsart lokal aus dem Bildverzeichnis, sonst über die Adresse
 * aus images_link (relative Muster gelten zur base_url des Kanals).
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop, von dessen Webseite das Bild kommt
 * @param string $name Dateiname aus parts_ext.hugoshop_images
 * @return string Bilddaten
 * @throws ApiError SHOP_IMAGE_SOURCE
 */
function shopHugoshopImageContent($db, int $kanal, string $name): string {
    $name = basename($name);

    if ('local' === shopPublishMode($db, $kanal) && '' !== shopChannelValue($db, $kanal, 'images_dir')) {
        $pfad = shopPathUnder(shopSiteDir($db, $kanal), shopChannelValue($db, $kanal, 'images_dir')).'/'.$name;
        if (is_file($pfad)) {
            return (string)file_get_contents($pfad);
        }
    }

    $adresse = shopLink(shopChannelValue($db, $kanal, 'images_link'), $name);
    if (!preg_match('#^https?://#i', $adresse)) {
        $basis = rtrim(shopChannelValue($db, $kanal, 'base_url'), '/');
        if ('' === $basis) {
            throw new ApiError('SHOP_IMAGE_SOURCE', 'Bild '.$name.': weder Datei noch vollständige Adresse (images_link, base_url)');
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
 * HugoShops (M6), die Dateien liegen aber auf der Webseite des jeweiligen
 * Kanals: gelesen wird von der Webseite der Quelle, geschrieben auf die des
 * Ziels. Zwischen zwei HugoShops wird nichts kopiert.
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

    // Marktplatz → HugoShop: nur, wenn OSERP die Webseite selbst beschreibt
    if ('hugoshop' === $nach['type']) {
        $kanal = $nach['id'];
        if ('local' !== shopPublishMode($db, $kanal)) {
            throw new ApiError('SHOP_IMAGE_COPY_UNAVAILABLE',
                'In der Betriebsart HugoCMS liegen die Bilder der Webseite bei HugoCMS — dorthin kann OSERP nichts übertragen.');
        }
        if ('' === shopChannelValue($db, $kanal, 'images_dir')) {
            throw new ApiError('SHOP_IMAGE_COPY_UNAVAILABLE', 'Das Bildverzeichnis der Webseite ist nicht eingestellt (images_dir).');
        }
        $zielDir = shopPathUnder(shopSiteDir($db, $kanal), shopChannelValue($db, $kanal, 'images_dir'), true);
        $quellDir = shopChannelImageDir($db, $partsId);
        $namen = [];
        foreach ($quelle as $bild) {
            $datei = basename((string)$bild['filename']);
            if (!is_file($zielDir.'/'.$datei) && !@copy($quellDir.'/'.$datei, $zielDir.'/'.$datei)) {
                throw new ApiError('SHOP_IMAGE_WRITE', 'Bild '.$datei.' konnte nicht auf die Webseite kopiert werden');
            }
            $namen[] = $datei;
        }
        // An die Liste anhängen, ohne Doppel, Reihenfolge der Quelle
        $db->execute(
            "INSERT INTO parts_ext (parts_id, hugoshop_images)
             SELECT p.id, to_jsonb(string_to_array(:namen, ',')) FROM parts p WHERE p.id = :parts_id
             ON CONFLICT (parts_id) DO UPDATE SET hugoshop_images = (
                 SELECT COALESCE(jsonb_agg(name ORDER BY pos), '[]'::jsonb)
                   FROM (SELECT DISTINCT ON (name) name, pos
                           FROM jsonb_array_elements_text(
                                    COALESCE(parts_ext.hugoshop_images, '[]'::jsonb) || EXCLUDED.hugoshop_images)
                                WITH ORDINALITY AS e(name, pos)
                          ORDER BY name, pos) liste)",
            [':namen' => implode(',', $namen), ':parts_id' => $partsId]
        );
        return count($namen);
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
    return count($quelle);
}
