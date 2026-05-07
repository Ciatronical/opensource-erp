<?php
// backend/api/lxcars/scan_images.php

/**
 * Speichert Fahrzeugschein-Bilder (Original + Feld-Ausschnitte)
 *
 * Erwartet: {
 *   action: 'saveScanImages',
 *   c_id: 42,
 *   c_ln: 'B-AB1234',
 *   original_image: '<base64>',   (optional)
 *   is_pdf: false,                 (optional)
 *   field_images: { hsnImg: '<base64>', vinImg: '<base64>', ... }
 * }
 *
 * Speichert in: {fmDataDir}/fahrzeuge/{c_id}/fahrzeugschein/
 * Erstellt Symlink: {fmDataDir}/fahrzeuge/0_by-plate/{c_ln} → ../{c_id}/fahrzeugschein
 */
function saveScanImages($data) {
    $cId = intval($data['c_id'] ?? 0);
    $cLn = trim($data['c_ln'] ?? '');
    $scanId = trim($data['scan_id'] ?? '');

    if ($cId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'c_id ist erforderlich');
        return;
    }

    $baseDir = fmDataDir() . '/fahrzeuge';
    $carDir = $baseDir . '/' . $cId . '/fahrzeugschein';
    $cropDir = $carDir . '/.crops';
    $plateDir = $baseDir . '/0_by-plate';

    // Verzeichnisse erstellen
    if (!is_dir($carDir)) {
        mkdir($carDir, 0755, true);
    }
    if (!is_dir($cropDir)) {
        mkdir($cropDir, 0755, true);
    }
    if (!is_dir($plateDir)) {
        mkdir($plateDir, 0755, true);
    }

    $savedFiles = [];

    // Original-Bild speichern: entweder via temp_image_id (vom Scan) oder via base64
    if (!empty($data['temp_image_id'])) {
        // Temp-Datei vom scanFahrzeugschein verschieben
        $tempId = preg_replace('/[^a-f0-9]/', '', $data['temp_image_id']);
        $tempDir = $baseDir . '/0_temp';
        foreach (['jpg', 'pdf'] as $tryExt) {
            $tempPath = $tempDir . '/' . $tempId . '.' . $tryExt;
            if (is_file($tempPath)) {
                $filename = 'original.' . $tryExt;
                rename($tempPath, $carDir . '/' . $filename);
                $savedFiles[] = $filename;
                break;
            }
        }
    } elseif (!empty($data['original_image'])) {
        $isPdf = !empty($data['is_pdf']);
        $ext = $isPdf ? 'pdf' : 'jpg';
        $filename = 'original.' . $ext;
        $decoded = base64_decode($data['original_image']);

        if ($decoded !== false) {
            file_put_contents($carDir . '/' . $filename, $decoded);
            $savedFiles[] = $filename;
        }
    } elseif (!empty($scanId)) {
        // Aus tmp-Cache kopieren
        $safeScanId = preg_replace('/[^a-zA-Z0-9\-]/', '', $scanId);
        $cacheDir = __DIR__ . '/../../tmp/' . $safeScanId;

        if (is_file($cacheDir . '/original.jpg')) {
            copy($cacheDir . '/original.jpg', $carDir . '/original.jpg');
            $savedFiles[] = 'original.jpg';
        }

        if (empty($data['field_images']) && is_dir($cacheDir . '/.crops')) {
            $cropEntries = scandir($cacheDir . '/.crops');
            foreach ($cropEntries as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if (!is_file($cacheDir . '/.crops/' . $entry)) continue;
                copy($cacheDir . '/.crops/' . $entry, $cropDir . '/' . $entry);
                $savedFiles[] = '.crops/' . $entry;
            }
        }

        // tmp aufräumen
        _removeTmpScanDir($safeScanId);
    }

    // Feld-Ausschnitte speichern
    if (!empty($data['field_images']) && is_array($data['field_images'])) {
        foreach ($data['field_images'] as $key => $base64) {
            if (empty($base64)) continue;

            // document_img = Original-Dokument (kompletter Fahrzeugschein, zugeschnitten + korrekt orientiert)
            // Hat Vorrang vor dem rohen Upload-Bild (temp_image_id), daher IMMER überschreiben
            if ($key === 'document_img' || $key === 'documentImg') {
                $decoded = base64_decode($base64);
                if ($decoded !== false) {
                    file_put_contents($carDir . '/original.jpg', $decoded);
                    if (!in_array('original.jpg', $savedFiles)) {
                        $savedFiles[] = 'original.jpg';
                    }
                }
                continue;
            }

            // Feldname extrahieren: 'hsn_img' → 'hsn', 'hsnImg' → 'hsn', 'registrationNumber_img' → 'registrationNumber'
            $fieldName = preg_replace('/[_]?[iI]mg$/', '', $key);
            if (empty($fieldName)) continue;

            // Sicherheit: nur alphanumerische Zeichen + Unterstrich
            $fieldName = preg_replace('/[^a-zA-Z0-9_]/', '', $fieldName);
            // Trailing underscores entfernen
            $fieldName = rtrim($fieldName, '_');
            if (empty($fieldName)) continue;

            $filename = 'crop_' . $fieldName . '.jpg';
            $decoded = base64_decode($base64);

            if ($decoded !== false) {
                file_put_contents($cropDir . '/' . $filename, $decoded);
                $savedFiles[] = '.crops/' . $filename;
            }
        }
    }

    // Symlink erstellen (Kennzeichen → Fahrzeug-ID)
    if (!empty($cLn)) {
        // Sonderzeichen entfernen für Dateisystem-Kompatibilität
        $safePlate = preg_replace('/[^a-zA-Z0-9\-]/', '_', $cLn);
        $symlinkPath = $plateDir . '/' . $safePlate;

        // Bestehenden Symlink entfernen falls vorhanden
        if (is_link($symlinkPath)) {
            unlink($symlinkPath);
        }

        // Relativen Symlink erstellen → Fahrzeugschein-Unterordner
        symlink('../' . $cId . '/fahrzeugschein', $symlinkPath);
    }

    // DB-Update: filename-Feld setzen (relativ zu fmDataDir)
    $db = DbhCompany::begin();
    $db->update(
        'cars_lxcars',
        ['filename'],
        ['fahrzeuge/' . $cId . '/fahrzeugschein'],
        "c_id = " . intval($cId)
    );

    resultInfo(true, 'OK', [
        'path' => 'fahrzeuge/' . $cId . '/fahrzeugschein',
        'files' => $savedFiles
    ]);
}

/**
 * Gibt die Liste der gespeicherten Scan-Bilder zurück
 *
 * Erwartet: { action: 'getScanImages', c_id: 42 }
 *    ODER:  { action: 'getScanImages', c_ln: 'B-AB1234' }
 * @testdata {"c_id": 1}
 */
function getScanImages($data) {
    $cId = intval($data['c_id'] ?? 0);

    // Lookup per Kennzeichen wenn keine c_id
    if ($cId <= 0 && !empty($data['c_ln'])) {
        $db = DbhCompany::begin();
        $row = $db->getOne(
            "SELECT c_id FROM cars_lxcars WHERE c_ln = :c_ln",
            [':c_ln' => trim($data['c_ln'])]
        );
        if ($row) {
            $cId = intval($row['c_id']);
        }
    }

    if ($cId <= 0) {
        resultInfo(true, 'OK', ['c_id' => 0, 'files' => []]);
        return;
    }

    $carDir = fmDataDir() . '/fahrzeuge/' . $cId . '/fahrzeugschein';
    $cropDir = $carDir . '/.crops';

    if (!is_dir($carDir)) {
        resultInfo(true, 'OK', ['c_id' => $cId, 'files' => []]);
        return;
    }

    $files = [];

    // Original-Dateien im Hauptverzeichnis
    $entries = scandir($carDir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.crops') continue;
        if (!is_file($carDir . '/' . $entry)) continue;

        $info = ['name' => $entry];
        if (strpos($entry, 'original') === 0) {
            $info['type'] = 'original';
        } else {
            $info['type'] = 'other';
        }
        $files[] = $info;
    }

    // Crop-Dateien im .crops/ Unterordner
    if (is_dir($cropDir)) {
        $cropEntries = scandir($cropDir);
        foreach ($cropEntries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (!is_file($cropDir . '/' . $entry)) continue;
            if (strpos($entry, 'crop_') !== 0) continue;

            $files[] = [
                'name' => '.crops/' . $entry,
                'type' => 'crop',
                'field' => preg_replace('/^crop_(.+)\.\w+$/', '$1', $entry),
            ];
        }
    }

    resultInfo(true, 'OK', ['c_id' => $cId, 'files' => $files]);
}

/**
 * Gibt alle Crop-Bilder für die Fahrzeugfelder als Base64 zurück (ein Call).
 *
 * Erwartet: { action: 'getScanCrops', c_id: 42 }
 * Gibt zurück: { success: true, payload: {
 *     original: { image: '<base64>', mime: 'image/jpeg' } | null,
 *     crops: { hsn: { image: '<base64>', mime: 'image/jpeg' }, vin: { ... }, ... }
 * }}
 * @testdata {"c_id": 1}
 */
function getScanCrops($data) {
    $cId = intval($data['c_id'] ?? 0);
    if ($cId <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'c_id ist erforderlich');
        return;
    }

    $carDir = fmDataDir() . '/fahrzeuge/' . $cId . '/fahrzeugschein';
    $cropDir = $carDir . '/.crops';
    if (!is_dir($carDir)) {
        resultInfo(true, 'OK', ['original' => null, 'crops' => new \stdClass()]);
        return;
    }

    $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
    $original = null;
    $crops = [];

    // Original aus Hauptverzeichnis
    $entries = scandir($carDir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.crops') continue;
        $filePath = $carDir . '/' . $entry;
        if (!is_file($filePath)) continue;

        if (strpos($entry, 'original') === 0) {
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';
            $original = ['image' => base64_encode(file_get_contents($filePath)), 'mime' => $mime];
        }
    }

    // Crops aus .crops/ Unterordner
    if (is_dir($cropDir)) {
        $cropEntries = scandir($cropDir);
        foreach ($cropEntries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $filePath = $cropDir . '/' . $entry;
            if (!is_file($filePath)) continue;
            if (strpos($entry, 'crop_') !== 0) continue;

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';
            $field = preg_replace('/^crop_(.+)\.\w+$/', '$1', $entry);
            $crops[$field] = ['image' => base64_encode(file_get_contents($filePath)), 'mime' => $mime];
        }
    }

    // stdClass damit leeres Array als {} statt [] serialisiert wird
    resultInfo(true, 'OK', [
        'original' => $original,
        'crops' => empty($crops) ? new \stdClass() : $crops
    ]);
}

/**
 * Gibt ein einzelnes Scan-Bild als Base64 zurück
 *
 * Erwartet: { action: 'getScanImage', c_id: 42, filename: 'crop_hsn.jpg' }
 * @testdata {"c_id": 1, "filename": "original.jpg"}
 */
function getScanImage($data) {
    $cId = intval($data['c_id'] ?? 0);
    $filename = $data['filename'] ?? '';

    if ($cId <= 0 || empty($filename)) {
        resultInfo(false, 'VALIDATION_ERROR', 'c_id und filename sind erforderlich');
        return;
    }

    // Path-Traversal-Schutz: erlaubt .crops/ Prefix + alphanumerische Zeichen + Punkt + Unterstrich + Bindestrich
    if (!preg_match('/^(\.crops\/)?[a-zA-Z0-9._\-]+$/', $filename)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungültiger Dateiname');
        return;
    }

    $filePath = fmDataDir() . '/fahrzeuge/' . $cId . '/fahrzeugschein/' . $filename;

    if (!is_file($filePath)) {
        resultInfo(false, 'FILE_NOT_FOUND', 'Datei nicht gefunden');
        return;
    }

    $content = file_get_contents($filePath);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $mimeMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'pdf' => 'application/pdf',
    ];

    $mime = $mimeMap[$ext] ?? 'application/octet-stream';

    resultInfo(true, 'OK', [
        'image' => base64_encode($content),
        'mime' => $mime,
        'filename' => $filename
    ]);
}

/**
 * Speichert Scan-Bilder in backend/tmp/{scan_id}/
 * Wird von getScanDetail() aufgerufen.
 *
 * @param string $scanId Scan-ID
 * @param array $imageFields { 'hsnImg' => '<base64>', 'document_img' => '<base64>', ... }
 */
function cacheScanToTmp($scanId, $imageFields) {
    if (empty($scanId) || empty($imageFields)) return;

    $safeScanId = preg_replace('/[^a-zA-Z0-9\-]/', '', $scanId);
    if (empty($safeScanId)) return;

    $scanDir = __DIR__ . '/../../tmp/' . $safeScanId;
    $cropDir = $scanDir . '/.crops';

    if (!is_dir($scanDir)) mkdir($scanDir, 0775, true);
    if (!is_dir($cropDir)) mkdir($cropDir, 0775, true);

    foreach ($imageFields as $key => $base64) {
        if (empty($base64)) continue;

        $decoded = base64_decode($base64);
        if ($decoded === false) continue;

        // document_img → original.jpg
        if ($key === 'document_img' || $key === 'documentImg') {
            file_put_contents($scanDir . '/original.jpg', $decoded);
            continue;
        }

        // Feldname: 'hsn_img' → 'hsn', 'hsnImg' → 'hsn'
        $fieldName = preg_replace('/[_]?[iI]mg$/', '', $key);
        if (empty($fieldName)) continue;
        $fieldName = preg_replace('/[^a-zA-Z0-9_]/', '', $fieldName);
        $fieldName = rtrim($fieldName, '_');
        if (empty($fieldName)) continue;

        file_put_contents($cropDir . '/crop_' . $fieldName . '.jpg', $decoded);
    }
}

/**
 * Gibt ein einzelnes Crop-Bild aus backend/tmp/{scan_id}/ als Base64 zurück.
 * Wird beim Hover im Scan-Formular aufgerufen (lazy loading).
 *
 * @param string $data['scan_id'] Scan-ID
 * @param string $data['field'] Feldname (z.B. 'hsn', 'vin', 'registrationNumber')
 * @testdata {"scan_id": "12345", "field": "hsn"}
 */
function getScanTempCrop($data) {
    $scanId = trim($data['scan_id'] ?? '');
    $field = trim($data['field'] ?? '');

    if (empty($scanId) || empty($field)) {
        resultInfo(false, 'VALIDATION_ERROR', 'scan_id und field sind erforderlich');
        return;
    }

    $safeScanId = preg_replace('/[^a-zA-Z0-9\-]/', '', $scanId);
    $safeField = preg_replace('/[^a-zA-Z0-9_]/', '', $field);

    if (empty($safeScanId) || empty($safeField)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungültige Parameter');
        return;
    }

    // original-Bild
    if ($safeField === 'original') {
        $filePath = __DIR__ . '/../../tmp/' . $safeScanId . '/original.jpg';
    } else {
        $filePath = __DIR__ . '/../../tmp/' . $safeScanId . '/.crops/crop_' . $safeField . '.jpg';
    }

    if (!is_file($filePath)) {
        resultInfo(false, 'FILE_NOT_FOUND', 'Bild nicht gefunden');
        return;
    }

    resultInfo(true, 'OK', [
        'image' => base64_encode(file_get_contents($filePath)),
        'mime'  => 'image/jpeg'
    ]);
}

/**
 * Gibt die Liste der verfügbaren Crop-Felder aus backend/tmp/{scan_id}/ zurück.
 * Wird nach getScanDetail aufgerufen um zu wissen, welche Crops vorhanden sind.
 *
 * @param string $data['scan_id'] Scan-ID
 * @testdata {"scan_id": "12345"}
 */
function getScanTempCropList($data) {
    $scanId = trim($data['scan_id'] ?? '');
    if (empty($scanId)) {
        resultInfo(true, 'OK', ['fields' => []]);
        return;
    }

    $safeScanId = preg_replace('/[^a-zA-Z0-9\-]/', '', $scanId);
    $cropDir = __DIR__ . '/../../tmp/' . $safeScanId . '/.crops';

    $fields = [];
    if (is_dir($cropDir)) {
        $entries = scandir($cropDir);
        foreach ($entries as $entry) {
            if (strpos($entry, 'crop_') !== 0) continue;
            $field = preg_replace('/^crop_(.+)\.\w+$/', '$1', $entry);
            $fields[] = $field;
        }
    }

    $hasOriginal = is_file(__DIR__ . '/../../tmp/' . $safeScanId . '/original.jpg');

    resultInfo(true, 'OK', [
        'fields'       => $fields,
        'has_original' => $hasOriginal
    ]);
}

/**
 * Löscht den tmp-Cache für eine Scan-ID
 *
 * @param string $safeScanId Bereits bereinigte Scan-ID
 */
function _removeTmpScanDir($safeScanId) {
    $dir = __DIR__ . '/../../tmp/' . $safeScanId;
    if (!is_dir($dir)) return;

    // .crops löschen
    $cropDir = $dir . '/.crops';
    if (is_dir($cropDir)) {
        $entries = scandir($cropDir);
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            @unlink($cropDir . '/' . $e);
        }
        @rmdir($cropDir);
    }

    // Dateien im Hauptverzeichnis löschen
    $entries = scandir($dir);
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        @unlink($dir . '/' . $e);
    }
    @rmdir($dir);
}
