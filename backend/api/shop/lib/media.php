<?php
// backend/api/shop/lib/media.php
//
// Medienbibliothek der Shop-Erweiterung (dev/shop-medienbibliothek.md):
// Produktbilder und Downloads liegen in OpensourceERP, zentral für alle
// Verkaufskanäle, je Mandant unter backend/data/<db>/shop/media/
//
//   images/      Produktbilder (jpg, png, gif, webp)
//   thumbnails/  Vorschaubilder, von OpensourceERP erzeugt — nicht von Hand
//   downloads/   Datenblätter, Anleitungen (pdf, zip, txt, auch Bilder)
//
// Der Dateiname ist der Schlüssel (B2): die Artikel nennen ihn in parts_ext
// (hugoshop_images, hugoshop_downloads), die Webseite liefert die Datei unter
// diesem Namen aus. media_shop trägt die Prüfsummen, damit Dateimanager und
// Übertragung nicht bei jedem Lauf alles neu lesen.
//
// Der Dateimanager (VueFinder) spricht die Bereiche als „Speicher“ an:
// images://name, downloads://name, thumbnails://name.

/** Bereiche des Dateimanagers => Art in media_shop (thumbnails hat keine Zeile) */
const SHOP_MEDIA_STORAGES = ['images' => 'image', 'downloads' => 'download', 'thumbnails' => 'thumbnail'];

/** Endungen je Art; bei Bildern entscheidet der Inhalt, nicht der Name */
const SHOP_MEDIA_IMAGE_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
const SHOP_MEDIA_DOWNLOAD_TYPES = ['pdf' => 'application/pdf', 'zip' => 'application/zip', 'txt' => 'text/plain',
                                   'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                                   'gif' => 'image/gif', 'webp' => 'image/webp'];

/** Höchstgrößen (B5) — die PHP-Grenzen (upload_max_filesize) gelten zusätzlich */
const SHOP_MEDIA_MAX_IMAGE_BYTES = 20 * 1024 * 1024;
const SHOP_MEDIA_MAX_DOWNLOAD_BYTES = 100 * 1024 * 1024;

/** Längster Dateiname */
const SHOP_MEDIA_NAME_MAX = 180;

/**
 * Verzeichnis eines Bereichs der Bibliothek
 *
 * @param object $db Company-Datenbankverbindung (bestimmt den Mandanten)
 * @param string $bereich images, thumbnails oder downloads
 * @param bool $anlegen fehlendes Verzeichnis anlegen
 * @return string absoluter Pfad
 * @throws ApiError MEDIA_KIND_INVALID
 */
function shopMediaDir($db, string $bereich, bool $anlegen = false): string {
    if (!isset(SHOP_MEDIA_STORAGES[$bereich])) {
        throw new ApiError('MEDIA_KIND_INVALID', 'Unbekannter Bereich der Medienbibliothek: '.$bereich);
    }
    $verzeichnis = fmDataDir().'/shop/media/'.$bereich;
    if ($anlegen && !is_dir($verzeichnis)) {
        fmMkdir($verzeichnis);
    }
    return $verzeichnis;
}

/**
 * Bereich und Dateiname aus einem Pfad des Dateimanagers (images://name)
 *
 * Ein Pfad ohne Speicher meint die Produktbilder. Unterverzeichnisse gibt es
 * nicht: Die Webseite liefert die Dateien flach aus, und die Artikel nennen
 * nur Namen.
 *
 * @param mixed $pfad Pfad aus der Anfrage, auch als Array {path}
 * @return array bereich, name (leer = der Bereich selbst)
 * @throws ApiError MEDIA_KIND_INVALID, MEDIA_NAME_INVALID
 */
function shopMediaPath($pfad): array {
    if (is_array($pfad)) {
        $pfad = $pfad['path'] ?? '';
    }
    $pfad = trim((string)$pfad);
    $bereich = 'images';
    if (preg_match('#^([a-z]+)://(.*)$#', $pfad, $teile)) {
        $bereich = $teile[1];
        $pfad = $teile[2];
    }
    if (!isset(SHOP_MEDIA_STORAGES[$bereich])) {
        throw new ApiError('MEDIA_KIND_INVALID', 'Unbekannter Bereich der Medienbibliothek: '.$bereich);
    }
    $name = trim($pfad, '/');
    if ('' !== $name && (str_contains($name, '/') || str_contains($name, '\\'))) {
        throw new ApiError('MEDIA_NAME_INVALID', 'Die Medienbibliothek hat keine Unterverzeichnisse: '.$name);
    }
    return ['bereich' => $bereich, 'name' => '' === $name ? '' : shopMediaName($name)];
}

/**
 * Bereinigt einen Dateinamen für die Bibliothek
 *
 * Erlaubt sind Buchstaben, Ziffern, Punkt, Bindestrich und Unterstrich;
 * Leerzeichen und alles andere werden zu Unterstrichen. Die Groß- und
 * Kleinschreibung bleibt — die Namen vorhandener Webseiten tragen sie.
 *
 * @param string $name Dateiname, auch mit Verzeichnis (nur der Name zählt)
 * @return string
 * @throws ApiError MEDIA_NAME_INVALID
 */
function shopMediaName(string $name): string {
    $name = basename(str_replace('\\', '/', trim($name)));
    $name = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name);
    $name = trim((string)$name, '._');
    if ('' === $name || mb_strlen($name) > SHOP_MEDIA_NAME_MAX || '' === pathinfo($name, PATHINFO_EXTENSION)) {
        throw new ApiError('MEDIA_NAME_INVALID', 'Unbrauchbarer Dateiname: '.$name);
    }
    return $name;
}

/**
 * Prüft eine Datei für die Bibliothek: Art, Größe, Inhalt
 *
 * Bilder nach dem Inhalt (getimagesize), nicht nach dem Namen — ein
 * umbenanntes PDF wäre sonst ein Bild. Downloads nach der Endung aus
 * SHOP_MEDIA_DOWNLOAD_TYPES.
 *
 * @param string $datei Pfad der Datei
 * @param string $art image oder download
 * @param string $name Dateiname für die Meldung und die Endung
 * @return array mime, width, height, size, extension (die zum Inhalt passende)
 * @throws ApiError MEDIA_TYPE, MEDIA_TOO_LARGE, MEDIA_NOT_FOUND
 */
function shopMediaInspect(string $datei, string $art, string $name): array {
    if (!is_file($datei)) {
        throw new ApiError('MEDIA_NOT_FOUND', 'Datei nicht gefunden: '.$name);
    }
    $groesse = (int)filesize($datei);

    if ('image' === $art) {
        if ($groesse > SHOP_MEDIA_MAX_IMAGE_BYTES) {
            throw new ApiError('MEDIA_TOO_LARGE', 'Bild ist zu groß (höchstens 20 MB): '.$name);
        }
        $info = @getimagesize($datei);
        $endung = array_search($info['mime'] ?? '', SHOP_MEDIA_IMAGE_TYPES, true);
        if (false === $endung) {
            throw new ApiError('MEDIA_TYPE', 'Nur JPG, PNG, GIF oder WEBP: '.$name);
        }
        return ['mime' => $info['mime'], 'width' => (int)$info[0], 'height' => (int)$info[1],
                'size' => $groesse, 'extension' => $endung];
    }

    if ($groesse > SHOP_MEDIA_MAX_DOWNLOAD_BYTES) {
        throw new ApiError('MEDIA_TOO_LARGE', 'Datei ist zu groß (höchstens 100 MB): '.$name);
    }
    $endung = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(SHOP_MEDIA_DOWNLOAD_TYPES[$endung])) {
        throw new ApiError('MEDIA_TYPE', 'Nur PDF, ZIP, TXT oder Bild: '.$name);
    }
    return ['mime' => SHOP_MEDIA_DOWNLOAD_TYPES[$endung], 'width' => null, 'height' => null,
            'size' => $groesse, 'extension' => $endung];
}

/**
 * Trägt eine Datei der Bibliothek in media_shop ein (neu oder geändert)
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $art image oder download
 * @param string $name Dateiname in images/ bzw. downloads/
 * @param array|null $vorschau sha256 und size des Vorschaubilds, nur bei Bildern
 * @return array Zeile aus media_shop
 */
function shopMediaRegister($db, string $art, string $name, ?array $vorschau = null): array {
    $datei = shopMediaDir($db, 'image' === $art ? 'images' : 'downloads').'/'.$name;
    $info = shopMediaInspect($datei, $art, $name);

    return $db->getOne(
        "INSERT INTO media_shop (kind, filename, sha256, size, mime, width, height, thumbnail_sha256, thumbnail_size)
         VALUES (:kind, :filename, :sha256, :size, :mime, :width, :height, :thumb_sha256, :thumb_size)
         ON CONFLICT (kind, filename) DO UPDATE
            SET sha256 = EXCLUDED.sha256, size = EXCLUDED.size, mime = EXCLUDED.mime,
                width = EXCLUDED.width, height = EXCLUDED.height,
                thumbnail_sha256 = EXCLUDED.thumbnail_sha256, thumbnail_size = EXCLUDED.thumbnail_size,
                mtime = now()
         RETURNING id, kind, filename, sha256, size, mime, width, height, thumbnail_sha256, thumbnail_size, itime, mtime",
        [
            ':kind'         => $art,
            ':filename'     => $name,
            ':sha256'       => hash_file('sha256', $datei),
            ':size'         => $info['size'],
            ':mime'         => $info['mime'],
            ':width'        => $info['width'],
            ':height'       => $info['height'],
            ':thumb_sha256' => $vorschau['sha256'] ?? null,
            ':thumb_size'   => $vorschau['size'] ?? null,
        ]
    );
}

/**
 * Nimmt eine Datei in die Bibliothek auf
 *
 * Eine Datei gleichen Namens wird ersetzt — die neue Fassung eines Bilds
 * behält ihren Namen, die Artikel und die Webseite folgen ihr. Bei Bildern
 * bekommt der Name die Endung, die zum Inhalt passt, und das Vorschaubild
 * entsteht gleich mit.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $art image oder download
 * @param string $quelle Pfad der Datei (Upload oder Übernahme)
 * @param string $name gewünschter Dateiname
 * @param bool $hochgeladen true = move_uploaded_file, sonst copy
 * @param bool $ersetzen false = eine Datei gleichen Namens ist ein Fehler (MEDIA_EXISTS)
 * @return array Zeile aus media_shop, dazu 'replaced' (es gab den Namen schon)
 * @throws ApiError MEDIA_NAME_INVALID, MEDIA_TYPE, MEDIA_TOO_LARGE, MEDIA_WRITE, MEDIA_EXISTS
 */
function shopMediaStore($db, string $art, string $quelle, string $name, bool $hochgeladen = false, bool $ersetzen = true): array {
    $name = shopMediaName($name);
    $info = shopMediaInspect($quelle, $art, $name);
    if ('image' === $art) {
        $name = pathinfo($name, PATHINFO_FILENAME).'.'.$info['extension'];
    }

    $ziel = shopMediaDir($db, 'image' === $art ? 'images' : 'downloads', true).'/'.$name;
    $ersetzt = is_file($ziel);
    if ($ersetzt && !$ersetzen) {
        throw new ApiError('MEDIA_EXISTS', 'Den Namen gibt es schon: '.$name);
    }
    $ok = $hochgeladen ? move_uploaded_file($quelle, $ziel) : copy($quelle, $ziel);
    if (!$ok) {
        throw new ApiError('MEDIA_WRITE', 'Datei konnte nicht in der Bibliothek abgelegt werden: '.$name);
    }
    $cfg = fmGetDirConfig();
    if ('' !== $cfg['group']) {
        @chgrp($ziel, $cfg['group']);
    }
    @chmod($ziel, 0664);

    $vorschau = null;
    $grund = null;
    if ('image' === $art) {
        $vorschau = shopMediaThumbnail($db, $name, 0, $grund);
        if (null === $vorschau) {
            writeLog('[SHOP] Kein Vorschaubild zu '.$name.': '.$grund, true, DLOG_WRN);
        }
    }
    // thumbnail_error: warum kein Vorschaubild entstand (leer, wenn eines da ist)
    return shopMediaRegister($db, $art, $name, $vorschau) + ['replaced' => $ersetzt, 'thumbnail_error' => (string)$grund];
}

/**
 * Erzeugt das Vorschaubild eines Produktbilds
 *
 * Wie früher in OpensourceERP und zuletzt in HugoCMS (ShopThumbnails): in die
 * Größe einpassen, Seitenverhältnis behalten, nie vergrößern, Transparenz
 * erhalten, Format der Quelle. Die Größe ist shop_thumbnail_size.
 *
 * Scheitert es, nennt $grund die Ursache — der Aufrufer meldet sie (Lauf,
 * Abgleich, Hochladen) und schreibt sie ins Protokoll. Ein stilles null
 * ließe auf einem Server ohne GD oder ohne WebP-Unterstützung alle
 * Vorschaubilder fehlen, ohne dass es jemand sieht.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $name Dateiname in images/
 * @param int $groesse größte Seite, 0 = Einstellung
 * @param string|null $grund Ursache, wenn kein Vorschaubild entstand
 * @return array|null sha256, size (die Größe) — null, wenn kein Vorschaubild
 *                    entstehen kann (GD fehlt, Bildart nicht lesbar, Ziel nicht schreibbar)
 */
function shopMediaThumbnail($db, string $name, int $groesse = 0, ?string &$grund = null): ?array {
    $grund = null;
    if (!function_exists('imagecreatetruecolor')) {
        $grund = 'Die PHP-Erweiterung GD fehlt';
        return null;
    }
    $groesse = $groesse > 0 ? $groesse : max(16, min(2000, shopConfigInt($db, 'shop_thumbnail_size', 200)));
    $quelle = shopMediaDir($db, 'images').'/'.$name;
    if (!is_file($quelle)) {
        $grund = 'Produktbild fehlt in der Bibliothek';
        return null;
    }
    $info = @getimagesize($quelle);
    if (false === $info) {
        $grund = 'Bild nicht lesbar';
        return null;
    }
    [$breite, $hoehe, $typ] = $info;
    $faktor = min(1, $groesse / max($breite, $hoehe, 1));
    $neuBreite = max(1, (int)round($breite * $faktor));
    $neuHoehe = max(1, (int)round($hoehe * $faktor));

    $laden = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng',
              IMAGETYPE_GIF => 'imagecreatefromgif', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
    if (!isset($laden[$typ])) {
        $grund = 'Bildart wird nicht unterstützt';
        return null;
    }
    if (!function_exists($laden[$typ]) || (IMAGETYPE_WEBP === $typ && !function_exists('imagewebp'))) {
        $grund = 'GD dieses Servers kann kein '.(IMAGETYPE_WEBP === $typ ? 'WebP' : image_type_to_extension($typ, false))
            .' (PHP mit entsprechender GD-Unterstützung nötig)';
        return null;
    }
    $bild = @$laden[$typ]($quelle);
    if (false === $bild) {
        $grund = 'Bild nicht lesbar (beschädigt oder zu groß für den Speicher)';
        return null;
    }
    $klein = imagecreatetruecolor($neuBreite, $neuHoehe);
    imagealphablending($klein, false);
    imagesavealpha($klein, true);
    imagefill($klein, 0, 0, imagecolorallocatealpha($klein, 0, 0, 0, 127));
    imagecopyresampled($klein, $bild, 0, 0, 0, 0, $neuBreite, $neuHoehe, $breite, $hoehe);

    $verzeichnis = shopMediaDir($db, 'thumbnails', true);
    $ziel = $verzeichnis.'/'.$name;
    if (!is_writable($verzeichnis) || (is_file($ziel) && !is_writable($ziel))) {
        imagedestroy($klein);
        imagedestroy($bild);
        $grund = 'Verzeichnis der Vorschaubilder nicht schreibbar: '.$verzeichnis;
        return null;
    }
    $ok = match ($typ) {
        IMAGETYPE_JPEG => @imagejpeg($klein, $ziel, 85),
        IMAGETYPE_PNG  => @imagepng($klein, $ziel),
        IMAGETYPE_GIF  => @imagegif($klein, $ziel),
        default        => @imagewebp($klein, $ziel, 85),
    };
    imagedestroy($klein);
    imagedestroy($bild);
    if (!$ok) {
        $grund = 'Vorschaubild konnte nicht geschrieben werden: '.$ziel;
        return null;
    }
    @chmod($ziel, 0664);
    return ['sha256' => hash_file('sha256', $ziel), 'size' => $groesse];
}

/**
 * Artikel, die eine Datei nennen
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $art image oder download
 * @param string $name Dateiname
 * @return array Liste aus parts_id, partnumber, description
 */
function shopMediaUsedBy($db, string $art, string $name): array {
    return $db->getAll(
        "SELECT parts_id, partnumber, description FROM shop_media_usage(:kind, :filename)",
        [':kind' => $art, ':filename' => $name]
    ) ?: [];
}

/**
 * Benennt eine Datei um — Datei, Vorschaubild, Zeile und die Verweise der Artikel
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $art image oder download
 * @param string $alt bisheriger Name
 * @param string $neu neuer Name
 * @return array name (bereinigt), parts (Zahl der geänderten Artikel)
 * @throws ApiError MEDIA_NOT_FOUND, MEDIA_EXISTS, MEDIA_NAME_INVALID, MEDIA_WRITE
 */
function shopMediaFileRename($db, string $art, string $alt, string $neu): array {
    $neu = shopMediaName($neu);
    $bereich = 'image' === $art ? 'images' : 'downloads';
    $von = shopMediaDir($db, $bereich).'/'.$alt;
    $nach = shopMediaDir($db, $bereich).'/'.$neu;
    if (!is_file($von)) {
        throw new ApiError('MEDIA_NOT_FOUND', 'Datei nicht gefunden: '.$alt);
    }
    if ($alt === $neu) {
        return ['name' => $neu, 'parts' => 0];
    }
    if (file_exists($nach)) {
        throw new ApiError('MEDIA_EXISTS', 'Den Namen gibt es schon: '.$neu);
    }
    if (strtolower(pathinfo($alt, PATHINFO_EXTENSION)) !== strtolower(pathinfo($neu, PATHINFO_EXTENSION))) {
        throw new ApiError('MEDIA_NAME_INVALID', 'Die Endung bleibt beim Umbenennen gleich: '.$alt);
    }
    if (!rename($von, $nach)) {
        throw new ApiError('MEDIA_WRITE', 'Datei konnte nicht umbenannt werden: '.$alt);
    }
    if ('image' === $art) {
        $vorschau = shopMediaDir($db, 'thumbnails').'/'.$alt;
        if (is_file($vorschau)) {
            @rename($vorschau, shopMediaDir($db, 'thumbnails').'/'.$neu);
        }
    }
    // Die Zeile kann fehlen (Datei von Hand abgelegt): dann anlegen
    $zeile = $db->getOne("SELECT shop_media_rename(:kind, :alt, :neu) AS parts", [':kind' => $art, ':alt' => $alt, ':neu' => $neu]);
    if (!$db->getOne("SELECT id FROM media_shop WHERE kind = :kind AND filename = :name", [':kind' => $art, ':name' => $neu])) {
        shopMediaRegister($db, $art, $neu, 'image' === $art ? shopMediaThumbnail($db, $neu) : null);
    }
    return ['name' => $neu, 'parts' => (int)($zeile['parts'] ?? 0)];
}

/** Zielformate beim Umwandeln eines Produktbilds: Endung => GD-Funktion */
const SHOP_MEDIA_CONVERT_FORMATS = ['webp' => 'imagewebp', 'jpg' => 'imagejpeg', 'png' => 'imagepng'];

/**
 * Wandelt ein Bild mit GD in ein anderes Format um — in eine Zwischendatei
 *
 * Qualität 85 für WebP und JPEG; JPEG kennt keine Transparenz, sie wird auf
 * Weiß gelegt. Der Aufrufer legt die Zwischendatei ab und löscht sie.
 *
 * @param string $quelle Pfad des Bilds
 * @param array $info aus shopMediaInspect (mime, width, height)
 * @param string $format webp, jpg oder png
 * @param string $name Dateiname für Meldungen
 * @return string Pfad der Zwischendatei
 * @throws ApiError MEDIA_FORMAT, MEDIA_CONVERT
 */
function shopMediaConvertImage(string $quelle, array $info, string $format, string $name): string {
    $format = strtolower(trim($format));
    if (!isset(SHOP_MEDIA_CONVERT_FORMATS[$format]) || !function_exists(SHOP_MEDIA_CONVERT_FORMATS[$format])) {
        throw new ApiError('MEDIA_FORMAT', 'Umwandeln nach '.strtoupper($format).' ist auf diesem Server nicht möglich');
    }
    $laden = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng',
              'image/gif' => 'imagecreatefromgif', 'image/webp' => 'imagecreatefromwebp'];
    $bild = isset($laden[$info['mime']]) && function_exists($laden[$info['mime']]) ? @$laden[$info['mime']]($quelle) : false;
    if (false === $bild) {
        throw new ApiError('MEDIA_CONVERT', 'Bild nicht lesbar: '.$name);
    }
    if ('jpg' === $format) {
        $flach = imagecreatetruecolor($info['width'], $info['height']);
        imagefill($flach, 0, 0, imagecolorallocate($flach, 255, 255, 255));
        imagecopy($flach, $bild, 0, 0, 0, 0, $info['width'], $info['height']);
        imagedestroy($bild);
        $bild = $flach;
    } else {
        imagepalettetotruecolor($bild);
        imagealphablending($bild, false);
        imagesavealpha($bild, true);
    }

    $tmp = tempnam(sys_get_temp_dir(), 'oserp-media');
    $ok = match ($format) {
        'webp'  => imagewebp($bild, $tmp, 85),
        'jpg'   => imagejpeg($bild, $tmp, 85),
        default => imagepng($bild, $tmp),
    };
    imagedestroy($bild);
    if (!$ok) {
        @unlink($tmp);
        throw new ApiError('MEDIA_CONVERT', 'Bild konnte nicht umgewandelt werden: '.$name);
    }
    return $tmp;
}

/**
 * Nimmt eine hochgeladene Datei unter einem gewünschten Namen in die
 * Bibliothek auf, bei Bildern auf Wunsch in ein anderes Format umgewandelt
 *
 * Jeden Namen gibt es nur einmal: Nennt ein Artikel die vorhandene Datei, ist
 * das ein Fehler (MEDIA_EXISTS), nichts wird ersetzt — der Dialog in der
 * Artikelkarte lässt dann einen anderen Namen wählen. Eine Datei, die kein
 * Artikel nennt, wird ersetzt. Die Endung folgt dem Inhalt bzw. dem
 * Zielformat; ein eingegebener Name wird ohne seine Endung verwendet.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $art image oder download
 * @param string $quelle hochgeladene Datei
 * @param string $name gewünschter Name (ohne oder mit Endung)
 * @param string $originalname Name der hochgeladenen Datei (Endung der Downloads)
 * @param string $format Zielformat für Bilder (webp, jpg, png) oder leer
 * @param bool $hochgeladen true = move_uploaded_file
 * @return array Zeile aus media_shop, dazu replaced (eine verwaiste Datei wurde ersetzt)
 * @throws ApiError MEDIA_EXISTS, MEDIA_FORMAT, MEDIA_CONVERT und die von shopMediaStore
 */
function shopMediaImport($db, string $art, string $quelle, string $name, string $originalname, string $format = '', bool $hochgeladen = false): array {
    $format = strtolower(trim($format));
    $endungOriginal = strtolower(pathinfo($originalname, PATHINFO_EXTENSION));
    // Ein Name ohne Endung bekommt die der hochgeladenen Datei — die Prüfung
    // verlangt eine; die endgültige Endung entscheidet sich unten
    $roh = '' !== trim($name) ? trim($name) : $originalname;
    if ('' === pathinfo($roh, PATHINFO_EXTENSION)) {
        $roh .= '.'.('' !== $endungOriginal ? $endungOriginal : 'bin');
    }
    $name = shopMediaName($roh);
    $basis = pathinfo($name, PATHINFO_FILENAME);
    $info = shopMediaInspect($quelle, $art, $name);

    if ('image' === $art) {
        $endung = '' !== $format ? $format : $info['extension'];
        if ('' !== $format && !isset(SHOP_MEDIA_CONVERT_FORMATS[$format])) {
            throw new ApiError('MEDIA_FORMAT', 'Unbekanntes Zielformat: '.$format);
        }
    } else {
        // Downloads behalten die Endung der hochgeladenen Datei
        $endung = '' !== $endungOriginal ? $endungOriginal : strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
    $ziel = $basis.'.'.$endung;
    // Belegt ist ein Name erst, wenn ein Artikel die Datei nennt. Liegt sie
    // nur in der Bibliothek (Rest eines Fehlers, nie verknüpft), wird sie
    // ersetzt — die Artikel nennen nur Namen, eine verwaiste Datei hat keinen
    if (file_exists(shopMediaDir($db, 'image' === $art ? 'images' : 'downloads', true).'/'.$ziel)) {
        $nutzer = shopMediaUsedBy($db, $art, $ziel);
        if ($nutzer) {
            $nummern = array_column($nutzer, 'partnumber');
            throw new ApiError('MEDIA_EXISTS', 'Den Namen gibt es schon, verwendet von '.count($nutzer).' Artikeln: '
                .implode(', ', array_slice($nummern, 0, 5)).(count($nummern) > 5 ? ' …' : ''));
        }
    }

    if ('image' === $art && '' !== $format && $format !== $info['extension']) {
        $tmp = shopMediaConvertImage($quelle, $info, $format, $name);
        try {
            $zeile = shopMediaStore($db, 'image', $tmp, $ziel);
        } finally {
            @unlink($tmp);
            if ($hochgeladen) {
                @unlink($quelle);
            }
        }
        return $zeile;
    }
    return shopMediaStore($db, $art, $quelle, $ziel, $hochgeladen);
}

/**
 * Wandelt ein Produktbild in ein anderes Format um
 *
 * Die neue Datei trägt den alten Namen mit der neuen Endung und ersetzt die
 * alte: Verweise der Artikel werden umgeschrieben (die Trigger legen die
 * Aufträge „Produktseite schreiben“ an), Vorschaubild und Eintrag entstehen
 * neu, die alte Datei geht. JPEG kennt keine Transparenz — sie wird auf Weiß
 * gelegt; ein animiertes GIF verliert die Animation. Qualität 85 für WebP
 * und JPEG, wie bei den Vorschaubildern.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $name Dateiname in images/
 * @param string $format webp, jpg oder png
 * @return array name (neuer Name), parts (geänderte Artikel), size_before, size_after
 * @throws ApiError MEDIA_FORMAT, MEDIA_FORMAT_SAME, MEDIA_EXISTS, MEDIA_NOT_FOUND, MEDIA_CONVERT, MEDIA_WRITE
 */
function shopMediaConvertFile($db, string $name, string $format): array {
    $format = strtolower(trim($format));
    if (!isset(SHOP_MEDIA_CONVERT_FORMATS[$format]) || !function_exists(SHOP_MEDIA_CONVERT_FORMATS[$format])) {
        throw new ApiError('MEDIA_FORMAT', 'Umwandeln nach '.strtoupper($format).' ist auf diesem Server nicht möglich');
    }
    $quelle = shopMediaDir($db, 'images').'/'.$name;
    $info = shopMediaInspect($quelle, 'image', $name);
    if ($info['extension'] === $format) {
        throw new ApiError('MEDIA_FORMAT_SAME', 'Das Bild hat dieses Format schon: '.$name);
    }
    $neu = pathinfo($name, PATHINFO_FILENAME).'.'.$format;
    if (file_exists(shopMediaDir($db, 'images').'/'.$neu)) {
        throw new ApiError('MEDIA_EXISTS', 'Den Namen gibt es schon: '.$neu);
    }

    $tmp = shopMediaConvertImage($quelle, $info, $format, $name);
    try {
        $zeile = shopMediaStore($db, 'image', $tmp, $neu);
    } finally {
        @unlink($tmp);
    }
    // Erst den alten Eintrag weg, dann die Verweise umschreiben — die
    // SQL-Funktion benennt sonst den alten Eintrag auf den schon vergebenen
    // neuen Namen um
    $db->execute("DELETE FROM media_shop WHERE kind = 'image' AND filename = :name", [':name' => $name]);
    $umgeschrieben = $db->getOne("SELECT shop_media_rename('image', :alt, :neu) AS parts", [':alt' => $name, ':neu' => $neu]);
    @unlink($quelle);
    $vorschau = shopMediaDir($db, 'thumbnails').'/'.$name;
    if (is_file($vorschau)) {
        @unlink($vorschau);
    }

    return ['name' => $neu, 'parts' => (int)($umgeschrieben['parts'] ?? 0),
            'size_before' => (int)$info['size'], 'size_after' => (int)$zeile['size']];
}

/**
 * Löscht eine Datei aus der Bibliothek — nur, wenn kein Artikel sie nennt
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $art image oder download
 * @param string $name Dateiname
 * @return void
 * @throws ApiError MEDIA_IN_USE (Meldung nennt die Artikel), MEDIA_NOT_FOUND
 */
function shopMediaFileDelete($db, string $art, string $name): void {
    $verwendung = shopMediaUsedBy($db, $art, $name);
    if ($verwendung) {
        $nummern = array_column($verwendung, 'partnumber');
        throw new ApiError('MEDIA_IN_USE', $name.' wird verwendet von '.count($verwendung).' Artikeln: '
            .implode(', ', array_slice($nummern, 0, 5)).(count($nummern) > 5 ? ' …' : ''));
    }
    $datei = shopMediaDir($db, 'image' === $art ? 'images' : 'downloads').'/'.$name;
    if (is_file($datei) && !unlink($datei)) {
        throw new ApiError('MEDIA_WRITE', 'Datei konnte nicht gelöscht werden: '.$name);
    }
    if ('image' === $art) {
        $vorschau = shopMediaDir($db, 'thumbnails').'/'.$name;
        if (is_file($vorschau)) {
            @unlink($vorschau);
        }
    }
    $db->execute("DELETE FROM media_shop WHERE kind = :kind AND filename = :name", [':kind' => $art, ':name' => $name]);
}

/**
 * Gleicht media_shop mit den Verzeichnissen ab
 *
 * Für das Importwerkzeug und für Dateien, die jemand von Hand abgelegt hat:
 * neue und geänderte Dateien (Größe oder Zeit weichen ab) werden eingetragen,
 * Zeilen ohne Datei entfernt, fehlende Vorschaubilder erzeugt. Unbrauchbare
 * Dateien (falsche Art, zu groß) bleiben liegen und stehen im Bericht.
 *
 * @param object $db Company-Datenbankverbindung
 * @param callable|null $melden Fortschritt je Datei
 * @return array neu, geaendert, entfernt, vorschau, unbrauchbar (Liste)
 */
function shopMediaSync($db, ?callable $melden = null): array {
    $bilanz = ['neu' => 0, 'geaendert' => 0, 'entfernt' => 0, 'vorschau' => 0, 'unbrauchbar' => []];
    $groesse = max(16, min(2000, shopConfigInt($db, 'shop_thumbnail_size', 200)));

    foreach (['image' => 'images', 'download' => 'downloads'] as $art => $bereich) {
        $verzeichnis = shopMediaDir($db, $bereich, true);
        $bekannt = [];
        foreach ($db->getAll("SELECT filename, size, mtime, thumbnail_sha256, thumbnail_size FROM media_shop WHERE kind = :kind",
                             [':kind' => $art]) ?: [] as $zeile) {
            $bekannt[$zeile['filename']] = $zeile;
        }
        $vorhanden = [];
        foreach (scandir($verzeichnis) ?: [] as $name) {
            $datei = $verzeichnis.'/'.$name;
            if (str_starts_with($name, '.') || !is_file($datei)) {
                continue;
            }
            $vorhanden[$name] = true;
            $zeile = $bekannt[$name] ?? null;
            $vorschauFehlt = 'image' === $art
                && (!is_file(shopMediaDir($db, 'thumbnails').'/'.$name) || (int)($zeile['thumbnail_size'] ?? 0) !== $groesse);
            $unveraendert = $zeile && (int)$zeile['size'] === filesize($datei)
                && strtotime((string)$zeile['mtime']) >= filemtime($datei);
            if ($unveraendert && !$vorschauFehlt) {
                continue;
            }
            try {
                $vorschau = null;
                if ('image' === $art && ($vorschauFehlt || !$unveraendert)) {
                    $grund = null;
                    $vorschau = shopMediaThumbnail($db, $name, $groesse, $grund);
                    if ($vorschau) {
                        $bilanz['vorschau']++;
                    } else {
                        $bilanz['unbrauchbar'][] = $bereich.'/'.$name.': kein Vorschaubild — '.$grund;
                    }
                }
                shopMediaRegister($db, $art, $name, $vorschau);
                $bilanz[$zeile ? 'geaendert' : 'neu']++;
                if (null !== $melden) {
                    $melden(($zeile ? 'geändert' : 'neu').': '.$bereich.'/'.$name);
                }
            } catch (ApiError $e) {
                $bilanz['unbrauchbar'][] = $bereich.'/'.$name.': '.$e->getMessage();
            }
        }
        foreach (array_keys($bekannt) as $name) {
            if (!isset($vorhanden[$name])) {
                $db->execute("DELETE FROM media_shop WHERE kind = :kind AND filename = :name", [':kind' => $art, ':name' => $name]);
                $bilanz['entfernt']++;
            }
        }
    }
    return $bilanz;
}

/**
 * Erneuert Vorschaubilder, die fehlen oder nicht die eingestellte Größe haben
 *
 * Vor der Lieferung an die Webseiten: Eine geänderte shop_thumbnail_size
 * wirkt so mit dem nächsten Lauf, ohne dass jemand „Abgleichen“ drückt.
 * Nur die Einträge aus media_shop, kein Lesen der Verzeichnisse.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array erzeugt (Zahl), fehlgeschlagen (Dateiname => Grund)
 */
function shopMediaThumbnailsRefresh($db): array {
    $groesse = max(16, min(2000, shopConfigInt($db, 'shop_thumbnail_size', 200)));
    $verzeichnis = shopMediaDir($db, 'thumbnails');
    $bilanz = ['erzeugt' => 0, 'fehlgeschlagen' => []];
    foreach ($db->getAll("SELECT filename, thumbnail_size FROM media_shop WHERE kind = 'image' ORDER BY filename") ?: [] as $zeile) {
        if ((int)$zeile['thumbnail_size'] === $groesse && is_file($verzeichnis.'/'.$zeile['filename'])) {
            continue;
        }
        $grund = null;
        $vorschau = shopMediaThumbnail($db, $zeile['filename'], $groesse, $grund);
        if (null === $vorschau) {
            // Der Eintrag bleibt, die Lieferung lässt das Vorschaubild aus;
            // der Lauf nennt den Grund
            $bilanz['fehlgeschlagen'][$zeile['filename']] = (string)$grund;
            continue;
        }
        $db->execute(
            "UPDATE media_shop SET thumbnail_sha256 = :sha256, thumbnail_size = :groesse
              WHERE kind = 'image' AND filename = :name",
            [':sha256' => $vorschau['sha256'], ':groesse' => $vorschau['size'], ':name' => $zeile['filename']]
        );
        $bilanz['erzeugt']++;
    }
    if ($bilanz['fehlgeschlagen']) {
        writeLog(sprintf('[SHOP] %d Vorschaubilder nicht erzeugt, etwa %s: %s', count($bilanz['fehlgeschlagen']),
            array_key_first($bilanz['fehlgeschlagen']), reset($bilanz['fehlgeschlagen'])), true, DLOG_WRN);
    }
    return $bilanz;
}

/**
 * Einträge eines Bereichs zu gegebenen Dateinamen — für die Artikelkarte
 *
 * In der Reihenfolge der Namen; Verzeichnisanteile werden abgestreift.
 * Eine Datei, die es in der Bibliothek nicht gibt, kommt mit missing = true
 * zurück, damit die Karte sie als fehlend zeigt statt sie zu verschweigen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $bereich images oder downloads
 * @param array $namen Dateinamen
 * @return array Einträge wie shopMediaEntries(), dazu missing
 */
function shopMediaEntriesFor($db, string $bereich, array $namen): array {
    $verzeichnis = shopMediaDir($db, $bereich, true);
    $eintraege = [];
    $gesehen = [];
    foreach ($namen as $roh) {
        $name = preg_replace('#^.*/#', '', trim((string)$roh));
        if ('' === $name || isset($gesehen[$name])) {
            continue;
        }
        $gesehen[$name] = true;
        $datei = $verzeichnis.'/'.$name;
        $da = !str_starts_with($name, '.') && is_file($datei);
        $eintraege[] = [
            'type'          => 'file',
            'storage'       => $bereich,
            'dir'           => $bereich.'://',
            'basename'      => $name,
            'path'          => $bereich.'://'.$name,
            'file_size'     => $da ? filesize($datei) : 0,
            'extension'     => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            'mime_type'     => $da ? fmGetMimeType($name) : '',
            'last_modified' => $da ? (filemtime($datei) ?: time()) : 0,
            'visibility'    => 'public',
            'missing'       => !$da,
        ];
    }
    return $eintraege;
}

/**
 * Nimmt den Verweis auf eine Datei aus einem Artikel — vor dem Löschen aus
 * der Artikelkarte heraus
 *
 * Die Karte hat die Datei schon aus ihrer (ungespeicherten) Liste genommen;
 * hier geht sie auch aus parts_ext, sonst zählte die Verwendungsprüfung den
 * Artikel noch. Die Trigger legen den Auftrag „Produktseite schreiben“ an.
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $art image oder download
 * @param string $name Dateiname
 * @param int $partsId Artikel
 */
function shopMediaUnlinkFromPart($db, string $art, string $name, int $partsId): void {
    if ('image' === $art) {
        $db->execute(
            "UPDATE parts_ext pe
                SET hugoshop_images = COALESCE((SELECT jsonb_agg(x.e ORDER BY x.o)
                                                  FROM jsonb_array_elements(pe.hugoshop_images) WITH ORDINALITY AS x(e, o)
                                                 WHERE regexp_replace(x.e #>> '{}', '^.*/', '') <> :name), '[]'::jsonb)
              WHERE pe.parts_id = :id AND jsonb_typeof(pe.hugoshop_images) = 'array'",
            [':name' => $name, ':id' => $partsId]
        );
    } else {
        $db->execute(
            "UPDATE parts_ext pe
                SET hugoshop_downloads = COALESCE((SELECT jsonb_object_agg(d.key, d.value)
                                                     FROM jsonb_each(pe.hugoshop_downloads) d
                                                    WHERE regexp_replace(d.value #>> '{}', '^.*/', '') <> :name), '{}'::jsonb)
              WHERE pe.parts_id = :id AND jsonb_typeof(pe.hugoshop_downloads) = 'object'",
            [':name' => $name, ':id' => $partsId]
        );
    }
}

/**
 * Einträge eines Bereichs für den Dateimanager (VueFinder)
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $bereich images, thumbnails oder downloads
 * @param string $filter Namensteil, leer = alle
 * @param int $limit Höchstzahl, 0 = alle
 * @return array VueFinder-Einträge, nach Name sortiert
 */
function shopMediaEntries($db, string $bereich, string $filter = '', int $limit = 0): array {
    $verzeichnis = shopMediaDir($db, $bereich, true);
    $filter = mb_strtolower(trim($filter));
    $eintraege = [];
    foreach (scandir($verzeichnis) ?: [] as $name) {
        $datei = $verzeichnis.'/'.$name;
        if (str_starts_with($name, '.') || !is_file($datei)) {
            continue;
        }
        if ('' !== $filter && !str_contains(mb_strtolower($name), $filter)) {
            continue;
        }
        $eintraege[] = [
            'type'          => 'file',
            'storage'       => $bereich,
            'dir'           => $bereich.'://',
            'basename'      => $name,
            'path'          => $bereich.'://'.$name,
            'file_size'     => filesize($datei),
            'extension'     => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            'mime_type'     => fmGetMimeType($name),
            'last_modified' => filemtime($datei) ?: time(),
            'visibility'    => 'public',
        ];
        if ($limit > 0 && count($eintraege) >= $limit) {
            break;
        }
    }
    usort($eintraege, fn($a, $b) => strcasecmp($a['basename'], $b['basename']));
    return $eintraege;
}
