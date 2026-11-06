<?php
// backend/api/lxcars/order_images.php
//
// Bilder am Auftrag (docs/features/lxcars.md → Bilder am Auftrag)
//
// Fotos, die in der Auftragsmaske mit der Kamera des Geräts aufgenommen oder
// vom Gerät gewählt werden. Die Datei liegt im Dokumentenordner des Kunden
// unter customers/{customer_id}/auftraege/{ordnumber}/ und erscheint damit
// auch im Reiter „Dateien“ der Kundenakte. Die Zeile in oe_images_lxcars
// trägt Bildunterschrift, Freigabe für das Kundenportal und den Uploader.
//
// Der Ordner wird in SQL aus dem Auftrag abgeleitet (OI_REL_DIR_SQL), nicht
// vom Frontend übergeben: das Kundenportal kann denselben Ausdruck später mit
// seiner Kundenprüfung nutzen.

define('OI_MAX_FILESIZE', 20 * 1024 * 1024); // 20 MB
define('OI_THUMB_WIDTH', 480);

/** Erlaubte Bildformate: MIME → Dateiendung */
const OI_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

/**
 * SQL-Ausdruck für den Auftragsordner relativ zum Datenverzeichnis
 * (Alias o = oe). Auftragsnummer ohne Sonderzeichen, ohne Nummer die ID.
 * fmGetBasePath liefert eine feste Konstante ('customers'), keine Eingabe –
 * deshalb darf sie in den Ausdruck eingesetzt werden.
 *
 * @return string
 */
function _oiRelDirSql(): string {
    return "'" . fmGetBasePath('C') . "/' || o.customer_id || '/auftraege/' || "
         . "CASE WHEN COALESCE(o.ordnumber, '') = '' THEN 'auftrag-' || o.id "
         . "ELSE regexp_replace(o.ordnumber, '[^[:alnum:]_.-]', '_', 'g') END";
}

/**
 * Bildzeilen mit Uploader und Ordner (Alias i = oe_images_lxcars)
 *
 * @param string $where Bedingung mit Platzhaltern
 * @param string $source Quelle der Bildzeilen: Tabelle oder eine CTE mit RETURNING
 * @return string
 */
function _oiSelectSql(string $where, string $source = 'oe_images_lxcars'): string {
    return "SELECT i.id, i.oe_id, i.filename, i.caption, i.portal_visible, i.employee_id, i.created_at,
                   e.name AS employee_name,
                   " . _oiRelDirSql() . " AS rel_dir
              FROM $source i
              JOIN oe o ON o.id = i.oe_id
              LEFT JOIN employee e ON e.id = i.employee_id
             WHERE $where
             ORDER BY i.created_at, i.id";
}

/**
 * Auftrag mit Kunde und absolutem Zielordner; legt den Ordner bei Bedarf an
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $oeId Auftrag
 * @param bool $create Ordner anlegen
 * @return array{order: array, rel: string, abs: string}
 */
function _oiOrderDir($db, int $oeId, bool $create = false): array {
    $order = $db->getOne(
        "SELECT o.id, o.ordnumber, o.customer_id, " . _oiRelDirSql() . " AS rel_dir
           FROM oe o WHERE o.id = :id",
        [':id' => $oeId]
    );
    if (!$order) {
        throw new ApiError('ORDER_NOT_FOUND', 'Auftrag nicht gefunden');
    }
    if (intval($order['customer_id']) <= 0) {
        throw new ApiError('ORDER_WITHOUT_CUSTOMER', 'Der Auftrag hat keinen Kunden – Bilder werden im Dokumentenordner des Kunden abgelegt');
    }
    $abs = fmDataDir() . '/' . $order['rel_dir'];
    if ($create && !is_dir($abs)) {
        fmMkdir($abs);
    }
    return ['order' => $order, 'rel' => $order['rel_dir'], 'abs' => $abs];
}

/**
 * Vorschaubild (JPEG, OI_THUMB_WIDTH breit) unter .thumbs/ neben dem Original;
 * wird einmal erzeugt und danach wiederverwendet. Ohne GD oder bei kleinen
 * Bildern null → der Aufrufer streamt das Original.
 *
 * @param string $abs Absoluter Pfad des Originals
 * @return string|null Absoluter Pfad des Vorschaubilds
 */
function _oiThumb(string $abs): ?string {
    $thumbDir = dirname($abs) . '/.thumbs';
    $thumb = $thumbDir . '/' . basename($abs) . '.jpg';
    if (is_file($thumb) && filemtime($thumb) >= filemtime($abs)) {
        return $thumb;
    }
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $info = @getimagesize($abs);
    $mime = $info['mime'] ?? '';
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($abs),
        'image/png'  => @imagecreatefrompng($abs),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($abs) : false,
        default      => false,
    };
    if (!$src) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    if ($w <= OI_THUMB_WIDTH) {
        imagedestroy($src);
        return null;
    }
    $nh = max(1, intval(round($h * OI_THUMB_WIDTH / $w)));
    $dst = imagecreatetruecolor(OI_THUMB_WIDTH, $nh);
    // Transparente Flächen (PNG/WebP) werden im JPEG weiß
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, OI_THUMB_WIDTH, $nh, $w, $h);
    imagedestroy($src);

    if (!is_dir($thumbDir)) {
        fmMkdir($thumbDir);
    }
    $ok = @imagejpeg($dst, $thumb, 82);
    imagedestroy($dst);
    if (!$ok) {
        return null;
    }
    $cfg = fmGetDirConfig();
    if (!empty($cfg['group'])) @chgrp($thumb, $cfg['group']);
    @chmod($thumb, 0664);
    return $thumb;
}

/**
 * Mitarbeiter des Uploads: employee_id aus dem Aufruf (setzt der Store wie bei
 * jedem anderen Aufruf), sonst aus der Sitzung
 *
 * @param array $data Aufrufdaten mit optionalem employee_id
 * @return int|null
 */
function _oiEmployeeId(array $data): ?int {
    $id = intval($data['employee_id'] ?? 0);
    if ($id > 0) return $id;
    return _getMechanicEmployeeId();
}

/**
 * Alle Bilder eines Auftrags
 *
 * @param int $data['oe_id'] Auftrag
 * @testdata {"oe_id": 1}
 */
function getOrderImages($data) {
    $db = DbhCompany::begin();
    $oeId = intval($data['oe_id'] ?? 0);
    if ($oeId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'oe_id ist erforderlich');
        return;
    }
    $rows = $db->getAll(_oiSelectSql('i.oe_id = :oe_id'), [':oe_id' => $oeId]) ?: [];
    resultInfo(true, '', ['results' => $rows]);
}

/**
 * Lädt ein Bild zum Auftrag hoch (multipart/form-data, Feld „file“)
 *
 * Ablage unter customers/{customer_id}/auftraege/{ordnumber}/ mit neutralem
 * Dateinamen (Zeitstempel), weil der Kunde ihn später im Kundenportal sieht.
 * Erlaubt sind JPEG, PNG und WebP bis 20 MB; das Frontend verkleinert vorher.
 *
 * @param int $data['oe_id'] Auftrag
 * @param string $data['caption'] Bildunterschrift (optional)
 * @param int $data['employee_id'] Mitarbeiter (optional, sonst Sitzung)
 * @testdata {"oe_id": 1, "caption": "Bremsscheibe vorne links"}
 */
function uploadOrderImage($data) {
    $db = DbhCompany::begin();
    $oeId = intval($data['oe_id'] ?? 0);
    if ($oeId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'oe_id ist erforderlich');
        return;
    }

    $file = $_FILES['file'] ?? null;
    if (!$file) {
        resultInfo(false, 'NO_FILE', 'Keine Datei empfangen');
        return;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'Datei überschreitet die maximale Servergröße',
            UPLOAD_ERR_FORM_SIZE  => 'Datei überschreitet die maximale Größe',
            UPLOAD_ERR_PARTIAL    => 'Datei wurde nur teilweise hochgeladen',
            UPLOAD_ERR_NO_FILE    => 'Keine Datei empfangen',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporäres Verzeichnis fehlt',
            UPLOAD_ERR_CANT_WRITE => 'Schreiben auf die Festplatte fehlgeschlagen',
            UPLOAD_ERR_EXTENSION  => 'Upload durch eine PHP-Erweiterung gestoppt',
        ];
        resultInfo(false, 'UPLOAD_ERROR', $uploadErrors[$file['error']] ?? 'Upload fehlgeschlagen');
        return;
    }
    if (intval($file['size']) > OI_MAX_FILESIZE) {
        resultInfo(false, 'FILE_TOO_LARGE', 'Bild ist größer als 20 MB');
        return;
    }
    $info = @getimagesize($file['tmp_name']);
    $ext = OI_IMAGE_TYPES[$info['mime'] ?? ''] ?? null;
    if ($ext === null) {
        resultInfo(false, 'NOT_AN_IMAGE', 'Nur JPEG, PNG und WebP sind erlaubt');
        return;
    }

    $dir = _oiOrderDir($db, $oeId, true);
    if (!is_writable($dir['abs'])) {
        resultInfo(false, 'DIR_NOT_WRITABLE', 'Keine Schreibrechte im Auftragsordner – bitte Dateirechte prüfen (Ordner: ' . basename($dir['abs']) . ')');
        return;
    }

    $base = date('Ymd-His');
    $name = $base . '.' . $ext;
    for ($i = 2; file_exists($dir['abs'] . '/' . $name) && $i < 1000; $i++) {
        $name = $base . '_' . $i . '.' . $ext;
    }
    $abs = $dir['abs'] . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $abs)) {
        resultInfo(false, 'SAVE_FAILED', 'Bild konnte nicht gespeichert werden');
        return;
    }
    $cfg = fmGetDirConfig();
    if (!empty($cfg['group'])) @chgrp($abs, $cfg['group']);
    @chmod($abs, 0664);

    $caption = trim((string)($data['caption'] ?? ''));
    try {
        $row = $db->getOne(
            "WITH ins AS (
                INSERT INTO oe_images_lxcars (oe_id, filename, caption, employee_id)
                VALUES (:oe_id, :filename, :caption, :employee_id)
                RETURNING *
             ) " . _oiSelectSql('true', 'ins'),
            [
                ':oe_id' => $oeId,
                ':filename' => $name,
                ':caption' => $caption !== '' ? $caption : null,
                ':employee_id' => _oiEmployeeId($data),
            ]
        );
    } catch (Throwable $e) {
        // Keine Zeile, keine Datei: sonst bliebe ein Bild ohne Zuordnung im Kundenordner
        @unlink($abs);
        throw $e;
    }
    resultInfo(true, '', ['results' => $row]);
}

/**
 * Ändert Bildunterschrift und/oder Freigabe für das Kundenportal
 *
 * @param int $data['id'] Bild
 * @param string $data['caption'] Bildunterschrift (optional)
 * @param bool $data['portal_visible'] Freigabe für den Kunden (optional)
 * @testdata {"id": 1, "caption": "Bremsscheibe vorne links", "portal_visible": true}
 */
function updateOrderImage($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'id ist erforderlich');
        return;
    }
    $caption = array_key_exists('caption', $data) ? trim((string)$data['caption']) : null;
    $visible = array_key_exists('portal_visible', $data)
        ? (filter_var($data['portal_visible'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false')
        : null;

    $row = $db->getOne(
        "WITH upd AS (
            UPDATE oe_images_lxcars
               SET caption = CASE WHEN CAST(:caption_set AS boolean) THEN NULLIF(CAST(:caption AS text), '') ELSE caption END,
                   portal_visible = COALESCE(CAST(:portal_visible AS boolean), portal_visible)
             WHERE id = :id
            RETURNING *
         ) " . _oiSelectSql('true', 'upd'),
        [
            ':caption_set' => $caption !== null ? 'true' : 'false',
            ':caption' => $caption,
            ':portal_visible' => $visible,
            ':id' => $id,
        ]
    );
    if (!$row) {
        resultInfo(false, 'NOT_FOUND', 'Bild nicht gefunden');
        return;
    }
    resultInfo(true, '', ['results' => $row]);
}

/**
 * Löscht ein Bild samt Datei und Vorschaubild
 *
 * @param int $data['id'] Bild
 * @testdata {"id": 1}
 */
function deleteOrderImage($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'id ist erforderlich');
        return;
    }
    $row = $db->getOne(_oiSelectSql('i.id = :id'), [':id' => $id]);
    if (!$row) {
        resultInfo(false, 'NOT_FOUND', 'Bild nicht gefunden');
        return;
    }
    $abs = fmDataDir() . '/' . $row['rel_dir'] . '/' . basename($row['filename']);
    if (is_file($abs)) @unlink($abs);
    $thumb = dirname($abs) . '/.thumbs/' . basename($abs) . '.jpg';
    if (is_file($thumb)) @unlink($thumb);

    $db->execute("DELETE FROM oe_images_lxcars WHERE id = :id", [':id' => $id]);
    resultInfo(true, '', ['results' => ['id' => $id]]);
}

/**
 * Streamt ein Bild (binär), per GET als Bildquelle nutzbar
 *
 * @param int $data['id'] Bild
 * @param int $data['thumb'] 1 = Vorschaubild (480 px breit)
 * @testdata {"id": 1, "thumb": 1}
 */
function getOrderImage($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    $row = $id > 0 ? $db->getOne(_oiSelectSql('i.id = :id'), [':id' => $id]) : null;
    $abs = $row ? fmDataDir() . '/' . $row['rel_dir'] . '/' . basename($row['filename']) : '';
    if (!$row || !is_file($abs)) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'Bild nicht gefunden';
        exit;
    }
    $path = $abs;
    if (!empty($data['thumb'])) {
        $path = _oiThumb($abs) ?? $abs;
    }
    header('Content-Type: ' . fmGetMimeType(basename($path)));
    header('Content-Length: ' . filesize($path));
    // Ein Bild ändert sich unter seiner ID nicht, nur die Unterschrift
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}
