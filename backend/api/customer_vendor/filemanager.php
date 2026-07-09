<?php
// backend/api/customer_vendor/filemanager.php
//
// Dateimanager fuer Kunden-/Lieferanten-Dokumente.
// Alle Funktionen folgen dem Standard-API-Muster (action-basiertes Routing ueber api.call.php).
//
// Zwei Gruppen:
//   1. Basis-Funktionen (getFiles, uploadFile, etc.) — JSON via resultInfo()
//   2. Vuefinder-Funktionen (vfIndex, vfUpload, etc.) — Vuefinder-kompatible Antworten via resultInfo()
//      Ausnahme: vfPreview/vfDownload streamen binaer.

define('FM_DATA_BASE_DIR', realpath(__DIR__ . '/../../data') ?: __DIR__ . '/../../data');
define('FM_MAX_FILESIZE', 20 * 1024 * 1024); // 20 MB
define('FM_STORAGE_NAME', 'local');

// =========================================================================
// Hilfsfunktionen (keine API-Endpoints)
// =========================================================================

/**
 * Mandantenspezifisches Daten-Verzeichnis fuer einen expliziten dbname.
 *
 * Wird fuer Kontexte ohne Session gebraucht (z.B. WhatsApp-Webhook,
 * der den Mandanten anhand der phone_number_id ermittelt).
 */
function fmDataDirForDb($dbname) {
    if (!is_string($dbname) || $dbname === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $dbname)) {
        throw new ApiError('FM_INVALID_DATABASE', 'Datenbankname konnte nicht ermittelt werden');
    }
    $dir = FM_DATA_BASE_DIR . '/' . $dbname;
    if (!is_dir($dir)) {
        fmMkdir($dir);
    }
    return $dir;
}

/**
 * Mandantenspezifisches Daten-Verzeichnis fuer die aktuelle Session.
 *
 * Liefert FM_DATA_BASE_DIR/<dbname>/ und legt es bei Bedarf an.
 * dbname wird per current_database() aus der Company-DB-Verbindung ermittelt
 * und pro Request gecacht. So liegen Dateien verschiedener Mandanten getrennt.
 */
function fmDataDir() {
    static $dir = null;
    if ($dir !== null) return $dir;

    $db = DbhCompany::begin();
    $row = $db->getOne("SELECT current_database() AS dbname");
    $dir = fmDataDirForDb($row['dbname'] ?? '');
    return $dir;
}

/**
 * Verzeichnis-Konfiguration aus defaults_oserp (dir_group, dir_mode).
 * Wird einmal pro Request geladen und gecached.
 */
function fmGetDirConfig() {
    static $config = null;
    if ($config !== null) return $config;

    // Default 0775 (gruppen-schreibbar): Daten-Ordner muessen sowohl von
    // php-fpm (www-data) als auch vom lokalen Dev-Server (work, in Gruppe
    // www-data) beschreibbar sein. 0755 sperrt die Gruppe aus → Upload
    // schlaegt fehl, sobald PHP nicht als der erzeugende User laeuft.
    $config = ['mode' => 0775, 'group' => ''];
    try {
        $db = DbhCompany::begin();
        $rows = $db->getAll(
            "SELECT key, value FROM defaults_oserp WHERE key IN ('dir_mode', 'dir_group')",
            []
        );
        foreach ($rows as $r) {
            if ($r['key'] === 'dir_mode' && $r['value'] !== '') {
                $config['mode'] = intval($r['value'], 8);
            }
            if ($r['key'] === 'dir_group' && $r['value'] !== '') {
                $config['group'] = $r['value'];
            }
        }
    } catch (Throwable $e) {
        // Defaults verwenden wenn DB nicht erreichbar
    }
    return $config;
}

/**
 * Erstellt Verzeichnis mit konfigurierten Rechten und Gruppenbesitzer
 */
function fmMkdir($path) {
    if (is_dir($path)) return;
    $cfg = fmGetDirConfig();
    mkdir($path, $cfg['mode'], true);
    if ($cfg['group'] !== '') {
        @chgrp($path, $cfg['group']);
    }
    // Modus explizit setzen (mkdir wird durch umask beeinflusst)
    @chmod($path, $cfg['mode']);
}

/**
 * Basis-Pfad fuer Kunden oder Lieferanten
 */
function fmGetBasePath($src) {
    return ($src === 'V') ? 'vendors' : 'customers';
}

/**
 * Sonderzeichen entfernen fuer Dateisystem
 */
function fmSanitizeName($name) {
    return preg_replace('/[^a-zA-Z0-9äöüÄÖÜß\-]/u', '_', $name);
}

/**
 * Path-Traversal-Schutz
 */
function fmValidatePath($allowedBase, $requestedPath) {
    if (!is_dir($allowedBase)) {
        fmMkdir($allowedBase);
    }
    $resolvedBase = realpath($allowedBase);
    if ($resolvedBase === false) return false;

    $fullPath = $allowedBase . '/' . $requestedPath;
    $resolved = realpath($fullPath);

    if ($resolved === false) {
        $parentDir = dirname($fullPath);
        $resolvedParent = realpath($parentDir);
        if ($resolvedParent === false) return false;
        if (strpos($resolvedParent, $resolvedBase) !== 0) return false;
        return $fullPath;
    }

    if (strpos($resolved, $resolvedBase) !== 0) return false;
    return $resolved;
}

/**
 * MIME-Type anhand der Dateiendung ermitteln
 */
function fmGetMimeType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mimeTypes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'svg' => 'image/svg+xml', 'bmp' => 'image/bmp',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'json' => 'application/json',
        'xml' => 'application/xml', 'html' => 'text/html', 'htm' => 'text/html',
        'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed',
        'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg',
    ];
    return $mimeTypes[$ext] ?? 'application/octet-stream';
}

/**
 * Dateigroesse lesbar formatieren
 */
function fmFormatSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

/**
 * Root-Verzeichnis fuer einen Kunden/Lieferanten ermitteln und erstellen
 */
function vfGetRootDir($cvId, $src) {
    $basePath = fmGetBasePath($src);
    $rootDir = fmDataDir() . '/' . $basePath . '/' . $cvId;
    if (!is_dir($rootDir)) {
        fmMkdir($rootDir);
    }
    return $rootDir;
}

/**
 * Resolver fuer das Root-Verzeichnis aus den Request-Daten.
 *
 * Unterstuetzt zwei Modi:
 *   - c_id  → Fahrzeug-Modus: fmDataDir/fahrzeuge/{c_id}
 *   - cv_id + src → Kunden-/Lieferanten-Modus (Bestand)
 *
 * Liefert den absoluten Root-Pfad oder null, wenn kein gueltiger Identifier
 * uebergeben wurde. Erzeugt das Verzeichnis bei Bedarf.
 */
function vfResolveRootDir($data) {
    if (!empty($data['c_id'])) {
        $cId = intval($data['c_id']);
        if ($cId <= 0) return null;
        $rootDir = fmDataDir() . '/fahrzeuge/' . $cId;
        if (!is_dir($rootDir)) {
            fmMkdir($rootDir);
        }
        return $rootDir;
    }
    $cvId = intval($data['cv_id'] ?? 0);
    if ($cvId <= 0) return null;
    $src = $data['src'] ?? 'C';
    return vfGetRootDir($cvId, $src);
}

/**
 * Vuefinder-Pfad bereinigen (Storage-Prefix entfernen, Traversal verhindern)
 */
function vfSanitizePath($path) {
    if (is_array($path)) {
        $path = $path['path'] ?? '';
    }
    $path = preg_replace('#^[a-zA-Z]+://#', '', $path);
    $path = str_replace(['..', "\0"], '', $path);
    return trim($path, '/');
}

/**
 * Absoluten Pfad ermitteln (mit Symlink-Unterstuetzung)
 */
function vfGetAbsPath($rootDir, $relativePath) {
    if (empty($relativePath)) return $rootDir;
    $full = $rootDir . '/' . $relativePath;
    if (is_link($full)) {
        $resolved = realpath($full);
        if ($resolved !== false) return $resolved;
    }
    return $full;
}

/**
 * Zugriff pruefen (innerhalb Root-Dir oder data-Dir fuer Symlinks)
 */
function vfValidateAccess($rootDir, $absPath) {
    $resolvedRoot = realpath($rootDir);
    $resolvedPath = realpath($absPath);
    if ($resolvedPath === false) return true; // existiert noch nicht
    if ($resolvedRoot === false) return false;
    $resolvedData = realpath(fmDataDir());
    return strpos($resolvedPath, $resolvedRoot) === 0 || strpos($resolvedPath, $resolvedData) === 0;
}

/**
 * Vuefinder-Eintrag fuer eine Datei/Ordner erstellen
 */
function vfBuildEntry($rootDir, $logicalDir, $name, $storageName) {
    $logicalPath = $logicalDir ? $logicalDir . '/' . $name : $name;
    $fullPath = $rootDir . '/' . $logicalPath;

    $entry = [
        'storage' => $storageName,
        'dir' => $storageName . '://' . $logicalDir,
        'basename' => $name,
        'path' => $storageName . '://' . $logicalPath,
        'last_modified' => @filemtime($fullPath) ?: time(),
        'visibility' => 'public',
    ];

    if (is_link($fullPath)) {
        $resolved = realpath($fullPath);
        if ($resolved && is_dir($resolved)) {
            $entry['type'] = 'dir';
            $entry['file_size'] = null;
            $entry['extension'] = '';
            $entry['mime_type'] = null;
        } elseif ($resolved && is_file($resolved)) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $entry['type'] = 'file';
            $entry['file_size'] = filesize($resolved);
            $entry['extension'] = $ext;
            $entry['mime_type'] = fmGetMimeType($name);
        } else {
            return null; // Broken link
        }
    } elseif (is_dir($fullPath)) {
        $entry['type'] = 'dir';
        $entry['file_size'] = null;
        $entry['extension'] = '';
        $entry['mime_type'] = null;
    } elseif (is_file($fullPath)) {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $entry['type'] = 'file';
        $entry['file_size'] = filesize($fullPath);
        $entry['extension'] = $ext;
        $entry['mime_type'] = fmGetMimeType($name);
    }

    return $entry;
}

/**
 * Verzeichnis scannen und Vuefinder-Eintraege erstellen
 */
function vfListDirectory($rootDir, $relativePath, $storageName) {
    $absDir = vfGetAbsPath($rootDir, $relativePath);
    if (!is_dir($absDir)) return [];

    $entries = [];
    foreach (scandir($absDir) as $item) {
        if ($item === '.' || $item === '..' || $item[0] === '.') continue;
        $entry = vfBuildEntry($rootDir, $relativePath, $item, $storageName);
        if ($entry) $entries[] = $entry;
    }

    usort($entries, function ($a, $b) {
        if ($a['type'] === 'dir' && $b['type'] !== 'dir') return -1;
        if ($a['type'] !== 'dir' && $b['type'] === 'dir') return 1;
        return strcasecmp($a['basename'], $b['basename']);
    });

    return $entries;
}

// =========================================================================
// Vuefinder API-Endpoints (action-basiert, via api.call.php)
// =========================================================================

/**
 * Vuefinder: Verzeichnis auflisten
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Optionaler Unterpfad
 * @testdata {"action": "vfIndex", "cv_id": 1121, "src": "C"}
 */
function vfIndex($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $rootDir = vfResolveRootDir($data);
    if ($rootDir === null) { resultInfo(false, 'cv_id or c_id required'); return; }

    // Ordner, Symlinks und Auto-Folders sicherstellen (idempotent), je nach Modus.
    if (!empty($data['c_id'])) {
        ensureVehicleFolders(intval($data['c_id']));
    } else {
        $cvId = intval($data['cv_id']);
        $src = $data['src'] ?? 'C';
        $db = DbhCompany::begin();
        $table = ($src === 'V') ? 'vendor' : 'customer';
        $row = $db->getOne("SELECT name FROM $table WHERE id = :id", [':id' => $cvId]);
        ensureCustomerFolder($cvId, $src, trim($row['name'] ?? ''));
    }

    $path = vfSanitizePath($data['path'] ?? '');
    $absDir = vfGetAbsPath($rootDir, $path);

    if (!vfValidateAccess($rootDir, $absDir)) {
        resultInfo(false, 'Access denied');
        return;
    }

    $files = vfListDirectory($rootDir, $path, FM_STORAGE_NAME);

    resultInfo(true, '', [
        'storages' => [FM_STORAGE_NAME],
        'dirname' => FM_STORAGE_NAME . '://' . $path,
        'files' => $files,
    ]);
}

/**
 * Vuefinder: Datei hochladen (multipart/form-data)
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Zielverzeichnis
 * @testdata {"action": "vfUpload", "cv_id": 1121, "src": "C"}
 */
function vfUpload($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $rootDir = vfResolveRootDir($data);
    if ($rootDir === null) { resultInfo(false, 'cv_id or c_id required'); return; }
    $uploadPath = vfSanitizePath($data['path'] ?? '');
    $targetDir = vfGetAbsPath($rootDir, $uploadPath);

    if (!is_dir($targetDir)) {
        fmMkdir($targetDir);
    }

    if (!vfValidateAccess($rootDir, $targetDir)) {
        resultInfo(false, 'Access denied');
        return;
    }

    if (empty($_FILES['file'])) {
        resultInfo(false, 'Keine Datei empfangen');
        return;
    }

    $file = $_FILES['file'];

    // PHP-Upload-Fehler explizit melden (z.B. Datei zu gross)
    if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'Datei überschreitet die maximale Servergröße',
            UPLOAD_ERR_FORM_SIZE  => 'Datei überschreitet die maximale Größe',
            UPLOAD_ERR_PARTIAL    => 'Datei wurde nur teilweise hochgeladen',
            UPLOAD_ERR_NO_FILE    => 'Keine Datei empfangen',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporäres Verzeichnis fehlt',
            UPLOAD_ERR_CANT_WRITE => 'Schreiben auf die Festplatte fehlgeschlagen',
            UPLOAD_ERR_EXTENSION  => 'Upload durch eine PHP-Erweiterung gestoppt',
        ];
        resultInfo(false, $uploadErrors[$file['error']] ?? 'Upload fehlgeschlagen');
        return;
    }

    // Schreibrechte im Zielordner pruefen, bevor verschoben wird —
    // sonst schlaegt der Upload still fehl (Ordner gehoert oft www-data/root
    // ohne Gruppen-Schreibrecht, waehrend PHP als anderer User laeuft).
    if (!is_writable($targetDir)) {
        resultInfo(false, 'Keine Schreibrechte im Zielordner – bitte Dateirechte prüfen (Ordner: ' . basename($targetDir) . ')');
        return;
    }

    $filename = basename($file['name']);
    $filename = preg_replace('/[^\w.\-\s]/', '_', $filename);
    $destination = $targetDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        resultInfo(false, 'Datei konnte nicht gespeichert werden');
        return;
    }

    // Rechte/Gruppe der neuen Datei an die Ordner-Konfiguration angleichen,
    // damit auch andere Systembenutzer (www-data/work) sie ueberschreiben koennen.
    $cfg = fmGetDirConfig();
    if ($cfg['group'] !== '') {
        @chgrp($destination, $cfg['group']);
    }
    @chmod($destination, 0664);

    // Aktualisierte Verzeichnisliste zurueckgeben, damit das Frontend
    // die neue Datei sofort anzeigt (konsistent mit vfDelete/vfRename).
    $files = vfListDirectory($rootDir, $uploadPath, FM_STORAGE_NAME);
    resultInfo(true, '', [
        'storages' => [FM_STORAGE_NAME],
        'dirname' => FM_STORAGE_NAME . '://' . $uploadPath,
        'files' => $files,
        'filename' => $filename,
    ]);
}

/**
 * Vuefinder: Dateien loeschen
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Aktuelles Verzeichnis
 * @param array $data['items'] Zu loeschende Eintraege
 * @testdata {"action": "vfDelete", "cv_id": 1121, "src": "C", "path": "", "items": []}
 */
function vfDelete($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $rootDir = vfResolveRootDir($data);
    if ($rootDir === null) { resultInfo(false, 'cv_id or c_id required'); return; }
    $path = vfSanitizePath($data['path'] ?? '');
    $items = $data['items'] ?? [];

    foreach ($items as $item) {
        $itemPath = vfSanitizePath($item);
        $absPath = vfGetAbsPath($rootDir, $itemPath);
        if (!vfValidateAccess($rootDir, $absPath)) continue;

        if (is_link($absPath)) {
            continue; // Symlinks nicht loeschen
        } elseif (is_file($absPath)) {
            unlink($absPath);
        } elseif (is_dir($absPath)) {
            $contents = array_diff(scandir($absPath), ['.', '..']);
            if (empty($contents)) {
                rmdir($absPath);
            }
        }
    }

    $files = vfListDirectory($rootDir, $path, FM_STORAGE_NAME);
    resultInfo(true, '', [
        'storages' => [FM_STORAGE_NAME],
        'dirname' => FM_STORAGE_NAME . '://' . $path,
        'files' => $files,
    ]);
}

/**
 * Vuefinder: Datei/Ordner umbenennen
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Aktuelles Verzeichnis
 * @param string $data['item'] Alter Pfad
 * @param string $data['name'] Neuer Name
 * @testdata {"action": "vfRename", "cv_id": 1121, "src": "C", "path": "", "item": "test.txt", "name": "test2.txt"}
 */
function vfRename($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $rootDir = vfResolveRootDir($data);
    if ($rootDir === null) { resultInfo(false, 'cv_id or c_id required'); return; }
    $path = vfSanitizePath($data['path'] ?? '');
    $item = vfSanitizePath($data['item'] ?? '');
    $newName = basename($data['name'] ?? '');

    if (empty($item) || empty($newName)) {
        resultInfo(false, 'item and name required');
        return;
    }

    $oldPath = vfGetAbsPath($rootDir, $item);
    $parentDir = dirname($oldPath);
    $newPath = $parentDir . '/' . $newName;

    if (!vfValidateAccess($rootDir, $oldPath) || is_link($oldPath)) {
        resultInfo(false, 'Cannot rename this item');
        return;
    }

    if (file_exists($newPath)) {
        resultInfo(false, 'Name already exists');
        return;
    }

    rename($oldPath, $newPath);

    $files = vfListDirectory($rootDir, $path, FM_STORAGE_NAME);
    resultInfo(true, '', [
        'storages' => [FM_STORAGE_NAME],
        'dirname' => FM_STORAGE_NAME . '://' . $path,
        'files' => $files,
    ]);
}

/**
 * Vuefinder: Ordner erstellen
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Aktuelles Verzeichnis
 * @param string $data['name'] Ordnername
 * @testdata {"action": "vfCreateFolder", "cv_id": 1121, "src": "C", "path": "", "name": "Neuer Ordner"}
 */
function vfCreateFolder($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $rootDir = vfResolveRootDir($data);
    if ($rootDir === null) { resultInfo(false, 'cv_id or c_id required'); return; }
    $path = vfSanitizePath($data['path'] ?? '');
    $name = $data['name'] ?? '';
    // Nur Dateisystem-kritische Zeichen entfernen (/ \ : * ? " < > | und Null-Byte)
    $safeName = preg_replace('/[\/\\\\:*?"<>|\x00]/', '_', $name);
    $safeName = trim($safeName);

    if (empty($safeName)) {
        resultInfo(false, 'Folder name required');
        return;
    }

    $parentDir = vfGetAbsPath($rootDir, $path);
    $newDir = $parentDir . '/' . $safeName;

    if (is_dir($newDir)) {
        resultInfo(false, 'Folder already exists');
        return;
    }

    fmMkdir($newDir);

    $files = vfListDirectory($rootDir, $path, FM_STORAGE_NAME);
    resultInfo(true, '', [
        'storages' => [FM_STORAGE_NAME],
        'dirname' => FM_STORAGE_NAME . '://' . $path,
        'files' => $files,
    ]);
}

/**
 * Vuefinder: Datei erstellen
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Aktuelles Verzeichnis
 * @param string $data['name'] Dateiname
 * @testdata {"action": "vfCreateFile", "cv_id": 1121, "src": "C", "path": "", "name": "notiz.txt"}
 */
function vfCreateFile($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $rootDir = vfResolveRootDir($data);
    if ($rootDir === null) { resultInfo(false, 'cv_id or c_id required'); return; }
    $path = vfSanitizePath($data['path'] ?? '');
    $name = $data['name'] ?? '';
    $safeName = preg_replace('/[^\w.\-\s]/', '_', $name);

    if (empty($safeName)) {
        resultInfo(false, 'File name required');
        return;
    }

    $parentDir = vfGetAbsPath($rootDir, $path);
    $newFile = $parentDir . '/' . $safeName;

    if (file_exists($newFile)) {
        resultInfo(false, 'File already exists');
        return;
    }

    file_put_contents($newFile, '');

    $files = vfListDirectory($rootDir, $path, FM_STORAGE_NAME);
    resultInfo(true, '', [
        'storages' => [FM_STORAGE_NAME],
        'dirname' => FM_STORAGE_NAME . '://' . $path,
        'files' => $files,
    ]);
}

/**
 * Vuefinder: Datei-Vorschau (binaerer Stream)
 *
 * Ueberschreibt den Content-Type-Header und streamt die Datei direkt.
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Dateipfad
 * @testdata {"action": "vfPreview", "cv_id": 1121, "src": "C", "path": "test.txt"}
 */
function vfPreview($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $rootDir = vfResolveRootDir($data);
    if ($rootDir === null) {
        http_response_code(400);
        echo 'cv_id or c_id required';
        exit;
    }
    $path = vfSanitizePath($data['path'] ?? '');
    $absPath = vfGetAbsPath($rootDir, $path);

    if (is_link($absPath)) {
        $absPath = realpath($absPath);
    }

    if (!$absPath || !is_file($absPath) || !vfValidateAccess($rootDir, $absPath)) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    $mime = fmGetMimeType(basename($absPath));
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($absPath));
    readfile($absPath);
    exit;
}

/**
 * Vuefinder: Datei herunterladen (binaerer Stream)
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Dateipfad
 * @testdata {"action": "vfDownload", "cv_id": 1121, "src": "C", "path": "test.txt"}
 */
function vfDownload($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $rootDir = vfResolveRootDir($data);
    if ($rootDir === null) {
        http_response_code(400);
        echo 'cv_id or c_id required';
        exit;
    }
    $path = vfSanitizePath($data['path'] ?? '');
    $absPath = vfGetAbsPath($rootDir, $path);

    if (is_link($absPath)) {
        $absPath = realpath($absPath);
    }

    if (!$absPath || !is_file($absPath) || !vfValidateAccess($rootDir, $absPath)) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    $filename = basename($absPath);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($absPath));
    readfile($absPath);
    exit;
}

/**
 * Vuefinder: Dateien suchen
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Suchpfad
 * @param string $data['filter'] Suchbegriff
 * @testdata {"action": "vfSearch", "cv_id": 1121, "src": "C", "filter": "test"}
 */
function vfSearch($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $rootDir = vfResolveRootDir($data);
    if ($rootDir === null) { resultInfo(false, 'cv_id or c_id required'); return; }
    $path = vfSanitizePath($data['path'] ?? '');
    $filter = $data['filter'] ?? '';

    if (empty($filter)) {
        resultInfo(true, '', ['files' => []]);
        return;
    }

    $absDir = vfGetAbsPath($rootDir, $path);
    $results = [];
    $filterLower = strtolower($filter);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $count = 0;
    foreach ($iterator as $file) {
        if ($count >= 50) break;
        $name = $file->getFilename();
        if ($name[0] === '.') continue;
        if (strpos(strtolower($name), $filterLower) !== false) {
            $relFromAbs = ltrim(str_replace($absDir, '', dirname($file->getPathname())), '/');
            $logicalDir = $path;
            if ($relFromAbs) {
                $logicalDir = $path ? $path . '/' . $relFromAbs : $relFromAbs;
            }
            $entry = vfBuildEntry($rootDir, $logicalDir, $name, FM_STORAGE_NAME);
            if ($entry) {
                $results[] = $entry;
                $count++;
            }
        }
    }

    resultInfo(true, '', ['files' => $results]);
}

/**
 * Vuefinder: Dateien verschieben
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Aktuelles Verzeichnis
 * @param array $data['sources'] Quellpfade
 * @param string $data['destination'] Zielpfad
 * @testdata {"action": "vfMove", "cv_id": 1121, "src": "C", "path": "", "sources": [], "destination": ""}
 */
function vfMove($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $rootDir = vfResolveRootDir($data);
    if ($rootDir === null) { resultInfo(false, 'cv_id or c_id required'); return; }
    $sources = $data['sources'] ?? [];
    $destination = vfSanitizePath($data['destination'] ?? '');
    $path = vfSanitizePath($data['path'] ?? '');
    $destDir = vfGetAbsPath($rootDir, $destination);

    if (!is_dir($destDir)) {
        fmMkdir($destDir);
    }

    foreach ($sources as $source) {
        $srcPath = vfSanitizePath($source);
        $absSrc = vfGetAbsPath($rootDir, $srcPath);
        if (is_link($absSrc)) continue;
        if (!vfValidateAccess($rootDir, $absSrc)) continue;

        $destPath = $destDir . '/' . basename($absSrc);
        if (file_exists($absSrc) && !file_exists($destPath)) {
            rename($absSrc, $destPath);
        }
    }

    $files = vfListDirectory($rootDir, $path, FM_STORAGE_NAME);
    resultInfo(true, '', [
        'storages' => [FM_STORAGE_NAME],
        'dirname' => FM_STORAGE_NAME . '://' . $path,
        'files' => $files,
    ]);
}

/**
 * Vuefinder: Dateien kopieren
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Aktuelles Verzeichnis
 * @param array $data['sources'] Quellpfade
 * @param string $data['destination'] Zielpfad
 * @testdata {"action": "vfCopy", "cv_id": 1121, "src": "C", "path": "", "sources": [], "destination": ""}
 */
function vfCopy($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $rootDir = vfResolveRootDir($data);
    if ($rootDir === null) { resultInfo(false, 'cv_id or c_id required'); return; }
    $sources = $data['sources'] ?? [];
    $destination = vfSanitizePath($data['destination'] ?? '');
    $path = vfSanitizePath($data['path'] ?? '');
    $destDir = vfGetAbsPath($rootDir, $destination);

    if (!is_dir($destDir)) {
        fmMkdir($destDir);
    }

    foreach ($sources as $source) {
        $srcPath = vfSanitizePath($source);
        $absSrc = vfGetAbsPath($rootDir, $srcPath);
        if (!vfValidateAccess($rootDir, $absSrc)) continue;

        $destPath = $destDir . '/' . basename($absSrc);
        if (is_file($absSrc) && !file_exists($destPath)) {
            copy($absSrc, $destPath);
        }
    }

    $files = vfListDirectory($rootDir, $path, FM_STORAGE_NAME);
    resultInfo(true, '', [
        'storages' => [FM_STORAGE_NAME],
        'dirname' => FM_STORAGE_NAME . '://' . $path,
        'files' => $files,
    ]);
}

/**
 * Vuefinder: Dateiinhalt speichern (Editor)
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Dateipfad
 * @param string $data['content'] Neuer Inhalt
 * @testdata {"action": "vfSave", "cv_id": 1121, "src": "C", "path": "test.txt", "content": "Hello"}
 */
function vfSave($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $rootDir = vfResolveRootDir($data);
    if ($rootDir === null) { resultInfo(false, 'cv_id or c_id required'); return; }
    $path = vfSanitizePath($data['path'] ?? '');
    $content = $data['content'] ?? '';
    $absPath = vfGetAbsPath($rootDir, $path);

    if (!is_file($absPath) || is_link($absPath) || !vfValidateAccess($rootDir, $absPath)) {
        resultInfo(false, 'Cannot save this file');
        return;
    }

    file_put_contents($absPath, $content);
    resultInfo(true, 'Saved');
}

/**
 * Vuefinder: Datei duplizieren
 *
 * Erstellt eine Kopie mit "(Kopie)"-Suffix im gleichen Verzeichnis.
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Dateipfad
 * @testdata {"action": "vfDuplicate", "cv_id": 1121, "src": "C", "path": "test.txt"}
 */
function vfDuplicate($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $rootDir = vfResolveRootDir($data);
    if ($rootDir === null) { resultInfo(false, 'cv_id or c_id required'); return; }
    $path = vfSanitizePath($data['path'] ?? '');

    if (empty($path)) {
        resultInfo(false, 'path required');
        return;
    }

    $absPath = vfGetAbsPath($rootDir, $path);

    if (!is_file($absPath) || is_link($absPath) || !vfValidateAccess($rootDir, $absPath)) {
        resultInfo(false, 'Cannot duplicate this file');
        return;
    }

    $dir = dirname($absPath);
    $basename = pathinfo($absPath, PATHINFO_FILENAME);
    $ext = pathinfo($absPath, PATHINFO_EXTENSION);
    $extSuffix = $ext ? '.' . $ext : '';

    if (preg_match('/^(.+) \(Kopie(?: (\d+))?\)$/', $basename, $m)) {
        $base = $m[1];
        $num = isset($m[2]) ? (int)$m[2] + 1 : 2;
    } else {
        $base = $basename;
        $num = 0;
    }

    $newName = $num === 0
        ? $base . ' (Kopie)' . $extSuffix
        : $base . ' (Kopie ' . $num . ')' . $extSuffix;
    $newPath = $dir . '/' . $newName;

    while (file_exists($newPath)) {
        $num++;
        $newName = $base . ' (Kopie ' . $num . ')' . $extSuffix;
        $newPath = $dir . '/' . $newName;
    }

    copy($absPath, $newPath);

    $parentRelPath = vfSanitizePath(dirname($path));
    $files = vfListDirectory($rootDir, $parentRelPath, FM_STORAGE_NAME);
    resultInfo(true, '', [
        'storages' => [FM_STORAGE_NAME],
        'dirname' => FM_STORAGE_NAME . '://' . $parentRelPath,
        'files' => $files,
    ]);
}

// =========================================================================
// Basis API-Endpoints (nicht-Vuefinder, fuer andere Frontends)
// =========================================================================

/**
 * Listet Dateien und Ordner eines Kunden-/Lieferanten-Verzeichnisses
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Optionaler Unterpfad (z.B. 'fahrzeuge')
 * @testdata {"action": "getFiles", "cv_id": 1121, "src": "C"}
 */
function getFiles($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    $subPath = trim($data['path'] ?? '');

    if (!$cvId) {
        resultInfo(false, 'VALIDATION_ERROR: cv_id required');
        return;
    }

    $basePath = fmGetBasePath($src);
    $cvDir = fmDataDir() . '/' . $basePath . '/' . $cvId;

    if (!is_dir($cvDir)) {
        fmMkdir($cvDir);
    }

    $targetDir = $cvDir;
    if ($subPath !== '') {
        $validated = fmValidatePath($cvDir, $subPath);
        if ($validated === false) {
            resultInfo(false, 'VALIDATION_ERROR: Invalid path');
            return;
        }
        $targetDir = $validated;
    }

    if (!is_dir($targetDir)) {
        resultInfo(true, 'OK', ['files' => [], 'path' => $subPath]);
        return;
    }

    $entries = [];
    $scan = scandir($targetDir);
    foreach ($scan as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if ($entry[0] === '.') continue;

        $fullPath = $targetDir . '/' . $entry;
        $item = [
            'name' => $entry,
            'mtime' => date('Y-m-d H:i', filemtime($fullPath)),
        ];

        if (is_link($fullPath)) {
            $item['type'] = 'link';
            $target = readlink($fullPath);
            $item['link_target'] = basename($target);
            $resolved = realpath($fullPath);
            $item['link_valid'] = ($resolved !== false && is_dir($resolved));
        } elseif (is_dir($fullPath)) {
            $item['type'] = 'dir';
        } else {
            $item['type'] = 'file';
            $item['size'] = filesize($fullPath);
            $item['size_formatted'] = fmFormatSize($item['size']);
            $item['mime_type'] = fmGetMimeType($entry);
        }

        $entries[] = $item;
    }

    usort($entries, function ($a, $b) {
        $typeOrder = ['dir' => 0, 'link' => 1, 'file' => 2];
        $aOrder = $typeOrder[$a['type']] ?? 2;
        $bOrder = $typeOrder[$b['type']] ?? 2;
        if ($aOrder !== $bOrder) return $aOrder - $bOrder;
        return strcasecmp($a['name'], $b['name']);
    });

    $vehicles = [];
    if ($subPath === '' && $src === 'C') {
        $db = DbhCompany::begin();
        $features = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'features'");
        $lxCars = $features && str_contains($features['value'] ?? '', 'lxcars');

        if ($lxCars) {
            $vehicles = $db->getAll(
                "SELECT c.c_id, c.c_ln, c.c_m, c.c_mt
                 FROM cars_lxcars c
                 WHERE c.c_ow = :cv_id
                 ORDER BY c.c_ln",
                [':cv_id' => $cvId]
            );
        }
    }

    resultInfo(true, 'OK', [
        'files' => $entries,
        'path' => $subPath,
        'vehicles' => $vehicles,
    ]);
}

/**
 * Laedt eine Datei hoch (base64)
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Optionaler Unterpfad
 * @param string $data['filename'] Dateiname
 * @param string $data['content'] Base64-kodierter Inhalt
 * @testdata {"action": "uploadFile", "cv_id": 1121, "src": "C", "filename": "test.txt", "content": "SGVsbG8gV29ybGQ="}
 */
function uploadFile($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    $subPath = trim($data['path'] ?? '');
    $filename = trim($data['filename'] ?? '');
    $content = $data['content'] ?? '';

    if (!$cvId || empty($filename) || empty($content)) {
        resultInfo(false, 'VALIDATION_ERROR: cv_id, filename and content required');
        return;
    }

    $filename = basename($filename);
    $filename = preg_replace('/[^\w.\-]/', '_', $filename);
    if (empty($filename) || $filename === '.' || $filename === '..') {
        resultInfo(false, 'VALIDATION_ERROR: Invalid filename');
        return;
    }

    $decoded = base64_decode($content, true);
    if ($decoded === false) {
        resultInfo(false, 'VALIDATION_ERROR: Invalid base64 content');
        return;
    }

    if (strlen($decoded) > FM_MAX_FILESIZE) {
        resultInfo(false, 'VALIDATION_ERROR: File too large (max 20 MB)');
        return;
    }

    $basePath = fmGetBasePath($src);
    $cvDir = fmDataDir() . '/' . $basePath . '/' . $cvId;

    if (!is_dir($cvDir)) {
        fmMkdir($cvDir);
    }

    $targetDir = $cvDir;
    if ($subPath !== '') {
        $validated = fmValidatePath($cvDir, $subPath);
        if ($validated === false) {
            resultInfo(false, 'VALIDATION_ERROR: Invalid path');
            return;
        }
        $targetDir = $validated;
        if (!is_dir($targetDir)) {
            fmMkdir($targetDir);
        }
    }

    $filePath = $targetDir . '/' . $filename;
    file_put_contents($filePath, $decoded);

    resultInfo(true, 'UPLOADED', [
        'filename' => $filename,
        'size' => strlen($decoded),
        'size_formatted' => fmFormatSize(strlen($decoded)),
    ]);
}

/**
 * Loescht eine Datei
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Optionaler Unterpfad
 * @param string $data['filename'] Dateiname
 * @testdata {"action": "deleteFile", "cv_id": 1121, "src": "C", "filename": "test.txt"}
 */
function deleteFile($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    $subPath = trim($data['path'] ?? '');
    $filename = trim($data['filename'] ?? '');

    if (!$cvId || empty($filename)) {
        resultInfo(false, 'VALIDATION_ERROR: cv_id and filename required');
        return;
    }

    $basePath = fmGetBasePath($src);
    $cvDir = fmDataDir() . '/' . $basePath . '/' . $cvId;

    $targetDir = $cvDir;
    if ($subPath !== '') {
        $validated = fmValidatePath($cvDir, $subPath);
        if ($validated === false) {
            resultInfo(false, 'VALIDATION_ERROR: Invalid path');
            return;
        }
        $targetDir = $validated;
    }

    $filePath = $targetDir . '/' . basename($filename);

    if (!is_file($filePath) || is_link($filePath)) {
        resultInfo(false, 'NOT_FOUND: File not found or not deletable');
        return;
    }

    $validated = fmValidatePath($cvDir, ($subPath ? $subPath . '/' : '') . basename($filename));
    if ($validated === false) {
        resultInfo(false, 'VALIDATION_ERROR: Invalid path');
        return;
    }

    unlink($filePath);
    resultInfo(true, 'DELETED');
}

/**
 * Erstellt einen neuen Unterordner
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Aktueller Pfad
 * @param string $data['foldername'] Name des neuen Ordners
 * @testdata {"action": "createFolder", "cv_id": 1121, "src": "C", "foldername": "Rechnungen"}
 */
function createFolder($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    $subPath = trim($data['path'] ?? '');
    $foldername = trim($data['foldername'] ?? '');

    if (!$cvId || empty($foldername)) {
        resultInfo(false, 'VALIDATION_ERROR: cv_id and foldername required');
        return;
    }

    $foldername = fmSanitizeName($foldername);
    if (empty($foldername)) {
        resultInfo(false, 'VALIDATION_ERROR: Invalid folder name');
        return;
    }

    $basePath = fmGetBasePath($src);
    $cvDir = fmDataDir() . '/' . $basePath . '/' . $cvId;

    if (!is_dir($cvDir)) {
        fmMkdir($cvDir);
    }

    $parentDir = $cvDir;
    if ($subPath !== '') {
        $validated = fmValidatePath($cvDir, $subPath);
        if ($validated === false) {
            resultInfo(false, 'VALIDATION_ERROR: Invalid path');
            return;
        }
        $parentDir = $validated;
    }

    $newDir = $parentDir . '/' . $foldername;
    if (is_dir($newDir)) {
        resultInfo(false, 'ALREADY_EXISTS: Folder already exists');
        return;
    }

    fmMkdir($newDir);
    resultInfo(true, 'CREATED', ['foldername' => $foldername]);
}

/**
 * Laedt eine Datei herunter (als base64)
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Optionaler Unterpfad
 * @param string $data['filename'] Dateiname
 * @testdata {"action": "downloadFile", "cv_id": 1121, "src": "C", "filename": "test.txt"}
 */
function downloadFile($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    $subPath = trim($data['path'] ?? '');
    $filename = trim($data['filename'] ?? '');

    if (!$cvId || empty($filename)) {
        resultInfo(false, 'VALIDATION_ERROR: cv_id and filename required');
        return;
    }

    $basePath = fmGetBasePath($src);
    $cvDir = fmDataDir() . '/' . $basePath . '/' . $cvId;

    $targetDir = $cvDir;
    if ($subPath !== '') {
        $validated = fmValidatePath($cvDir, $subPath);
        if ($validated === false) {
            resultInfo(false, 'VALIDATION_ERROR: Invalid path');
            return;
        }
        $targetDir = $validated;
    }

    $filePath = $targetDir . '/' . basename($filename);

    if (is_link($filePath)) {
        $filePath = realpath($filePath);
    }

    if (!$filePath || !is_file($filePath)) {
        resultInfo(false, 'NOT_FOUND: File not found');
        return;
    }

    $resolvedData = realpath(fmDataDir());
    if (strpos(realpath($filePath), $resolvedData) !== 0) {
        resultInfo(false, 'VALIDATION_ERROR: Access denied');
        return;
    }

    $content = file_get_contents($filePath);
    resultInfo(true, 'OK', [
        'filename' => basename($filename),
        'content' => base64_encode($content),
        'mime_type' => fmGetMimeType($filename),
        'size' => strlen($content),
    ]);
}

/**
 * Loescht einen Ordner (nur leere Ordner, keine Symlinks)
 *
 * @param int $data['cv_id'] Kunden-/Lieferanten-ID
 * @param string $data['src'] 'C' oder 'V'
 * @param string $data['path'] Pfad zum Ordner
 * @param string $data['foldername'] Name des zu loeschenden Ordners
 * @testdata {"action": "deleteFolder", "cv_id": 1121, "src": "C", "path": "", "foldername": "test"}
 */
function deleteFolder($data) {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    $subPath = trim($data['path'] ?? '');
    $foldername = trim($data['foldername'] ?? '');

    if (!$cvId || empty($foldername)) {
        resultInfo(false, 'VALIDATION_ERROR: cv_id and foldername required');
        return;
    }

    if ($foldername === 'fahrzeuge') {
        resultInfo(false, 'VALIDATION_ERROR: Protected folder');
        return;
    }

    $basePath = fmGetBasePath($src);
    $cvDir = fmDataDir() . '/' . $basePath . '/' . $cvId;
    $fullSubPath = $subPath ? $subPath . '/' . $foldername : $foldername;

    $validated = fmValidatePath($cvDir, $fullSubPath);
    if ($validated === false) {
        resultInfo(false, 'VALIDATION_ERROR: Invalid path');
        return;
    }

    if (!is_dir($validated) || is_link($validated)) {
        resultInfo(false, 'NOT_FOUND: Folder not found');
        return;
    }

    $contents = array_diff(scandir($validated), ['.', '..']);
    if (!empty($contents)) {
        resultInfo(false, 'VALIDATION_ERROR: Folder is not empty');
        return;
    }

    rmdir($validated);
    resultInfo(true, 'DELETED');
}

/**
 * Erstellt automatisch konfigurierte Unterordner im Fahrzeug-Verzeichnis.
 *
 * Liest die Konfiguration 'lxcars_auto_folders' aus defaults_oserp (kommagetrennte Ordnernamen).
 * Erstellt die Ordner unter fmDataDir()/fahrzeuge/{c_id}/.
 * Legt zusaetzlich den fahrzeugschein/-Unterordner an, damit der By-plate-Symlink
 * (zeigt auf {c_id}/fahrzeugschein) immer auf ein existierendes Ziel laeuft.
 * Bereits vorhandene Ordner werden nicht ueberschrieben.
 *
 * @param int $carId Fahrzeug-ID (c_id)
 */
function ensureVehicleFolders($carId) {
    $carDir = fmDataDir() . '/fahrzeuge/' . intval($carId);
    if (!is_dir($carDir)) {
        fmMkdir($carDir);
    }

    // fahrzeugschein/ als Standard-Unterordner sicherstellen
    $scheinDir = $carDir . '/fahrzeugschein';
    if (!is_dir($scheinDir)) {
        fmMkdir($scheinDir);
    }

    $db = DbhCompany::begin();
    $row = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = 'lxcars_auto_folders'",
        []
    );

    $folderString = trim($row['value'] ?? '');
    if ($folderString === '') return;

    $folders = array_filter(array_map('trim', explode(',', $folderString)));
    foreach ($folders as $folder) {
        // Nur Dateisystem-kritische Zeichen entfernen (/ \ : * ? " < > | und Null-Byte)
        $safeName = preg_replace('/[\/\\\\:*?"<>|\x00]/', '_', $folder);
        if ($safeName === '') continue;
        $folderPath = $carDir . '/' . $safeName;
        if (!is_dir($folderPath)) {
            fmMkdir($folderPath);
        }
    }
}

/**
 * Stellt alle Fahrzeug-Pfade sicher (idempotent).
 *
 * Wird beim Oeffnen eines Fahrzeugs zum Bearbeiten (getCar) aufgerufen,
 * damit fehlende Pfade nachtraeglich angelegt werden.
 *
 * Erzeugt, falls fehlend:
 *   1. fmDataDir/fahrzeuge/{c_id}/ inkl. Auto-Folder + fahrzeugschein/
 *   2. fmDataDir/fahrzeuge/0_by-plate/{plate} → ../{c_id}/fahrzeugschein
 *   3. fmDataDir/customers/{c_ow}/fahrzeuge/{plate} → ../../../fahrzeuge/{c_id}
 *
 * @param array $car Datensatz mit mind. c_id, optional c_ln, c_ow
 */
function ensureVehiclePaths($car) {
    $cId = intval($car['c_id'] ?? 0);
    if ($cId <= 0) return;

    // 1. Fahrzeug-Verzeichnis (inkl. fahrzeugschein/ + Auto-Folder)
    ensureVehicleFolders($cId);

    $cLn = trim($car['c_ln'] ?? '');
    if ($cLn === '') return;

    $safePlate = preg_replace('/[^a-zA-Z0-9\-]/', '_', $cLn);
    if ($safePlate === '') return;

    // 2. By-plate-Symlink
    $baseDir = fmDataDir() . '/fahrzeuge';
    $plateDir = $baseDir . '/0_by-plate';
    if (!is_dir($plateDir)) {
        fmMkdir($plateDir);
    }
    $plateLink = $plateDir . '/' . $safePlate;
    if (!is_link($plateLink) && !file_exists($plateLink)) {
        @symlink('../' . $cId . '/fahrzeugschein', $plateLink);
    }

    // 3. Reverse-Symlink im Kunden-Ordner
    $cOw = intval($car['c_ow'] ?? 0);
    if ($cOw <= 0) return;

    $vehicleDir = fmDataDir() . '/customers/' . $cOw . '/fahrzeuge';
    if (!is_dir($vehicleDir)) {
        fmMkdir($vehicleDir);
    }
    $customerLink = $vehicleDir . '/' . $safePlate;
    if (!is_link($customerLink) && !file_exists($customerLink)) {
        @symlink('../../../fahrzeuge/' . $cId, $customerLink);
    }
}

/**
 * Stellt sicher, dass der Kunden-/Lieferanten-Ordner existiert und Symlinks aktuell sind
 *
 * Wird nach dem Speichern eines Kunden/Lieferanten aufgerufen.
 * Erstellt den ID-Ordner, den Namens-Symlink und ggf. Fahrzeug-Symlinks.
 *
 * @param int $cvId Kunden-/Lieferanten-ID
 * @param string $src 'C' oder 'V'
 * @param string $name Kunden-/Lieferantenname
 */
function ensureCustomerFolder($cvId, $src, $name) {
    $basePath = fmGetBasePath($src);
    $baseDir = fmDataDir() . '/' . $basePath;
    $cvDir = $baseDir . '/' . $cvId;

    if (!is_dir($cvDir)) {
        fmMkdir($cvDir);
    }

    $nameDir = $baseDir . '/0_by-name';
    if (!is_dir($nameDir)) {
        fmMkdir($nameDir);
    }

    $safeName = fmSanitizeName($name);
    $symlinkName = $safeName . '_' . $cvId;
    $symlinkPath = $nameDir . '/' . $symlinkName;

    $existing = glob($nameDir . '/*_' . $cvId);
    foreach ($existing as $old) {
        if (is_link($old)) {
            unlink($old);
        }
    }

    if (!empty($safeName)) {
        symlink('../' . $cvId, $symlinkPath);
    }

    if ($src === 'C') {
        $db = DbhCompany::begin();
        $features = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'features'");
        $lxCars = $features && str_contains($features['value'] ?? '', 'lxcars');

        if ($lxCars) {
            $vehicles = $db->getAll(
                "SELECT c_id, c_ln FROM cars_lxcars WHERE c_ow = :cv_id AND c_ln IS NOT NULL AND c_ln != ''",
                [':cv_id' => $cvId]
            );

            if (!empty($vehicles)) {
                $vehicleDir = $cvDir . '/fahrzeuge';
                if (!is_dir($vehicleDir)) {
                    fmMkdir($vehicleDir);
                }

                $existingLinks = glob($vehicleDir . '/*');
                foreach ($existingLinks as $link) {
                    if (is_link($link)) {
                        unlink($link);
                    }
                }

                foreach ($vehicles as $v) {
                    // Fahrzeugschein-Ordner + Auto-Ordner sicherstellen
                    ensureVehicleFolders($v['c_id']);

                    $safePlate = preg_replace('/[^a-zA-Z0-9\-]/', '_', $v['c_ln']);
                    $linkPath = $vehicleDir . '/' . $safePlate;
                    $targetPath = '../../../fahrzeuge/' . $v['c_id'];
                    symlink($targetPath, $linkPath);
                }
            }
        }
    }
}

// =========================================================================
// KI-Dokument-Chat
// =========================================================================

/**
 * Stellt eine KI-Frage zu einem Dokument aus dem CRM-Dateimanager.
 * Das Dokument wird vom Server gelesen und per Anthropic-API an Claude übergeben.
 *
 * @param string $data['path']    Relativer Dateipfad (fmValidatePath wird angewendet)
 * @param int    $data['cv_id']   Kunden-/Lieferanten-ID
 * @param string $data['src']     'C' = Kunde, 'V' = Lieferant
 * @param string $data['message'] Benutzerfrage
 * @testdata {"cv_id": 1, "src": "C", "path": "example.pdf", "message": "Worum geht es in diesem Dokument?"}
 */
function docChatMessage($data) {
    set_time_limit(60);

    $db = DbhCompany::begin();
    $cvId  = intval($data['cv_id'] ?? 0);
    $src   = ($data['src'] ?? 'C') === 'V' ? 'V' : 'C';
    $path  = trim($data['path'] ?? '');
    $message = trim($data['message'] ?? '');

    if (!$cvId || empty($path) || empty($message)) {
        throw new ApiError('VALIDATION_ERROR', 'cv_id, path und message sind erforderlich');
    }

    // Anthropic API-Key laden
    $config = $db->fetchKeyValue(
        "SELECT key, value FROM defaults_oserp WHERE key = 'anthropic_api_key'"
    );
    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if (empty($anthropicKey)) {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key ist nicht konfiguriert (CRM-Einstellungen)');
    }

    // Dateipfad validieren — gleiche Logik wie vfPreview/vfDownload
    $rootDir = vfGetRootDir($cvId, $src);
    $safePath = vfSanitizePath($path);
    $absPath  = vfGetAbsPath($rootDir, $safePath);

    if (is_link($absPath)) {
        $absPath = realpath($absPath);
    }

    if (!$absPath || !is_file($absPath) || !vfValidateAccess($rootDir, $absPath)) {
        throw new ApiError('FILE_NOT_FOUND', 'Datei nicht gefunden');
    }

    $ext      = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $pdfExts  = ['pdf'];
    $textExts = ['txt', 'md', 'csv', 'json', 'xml', 'html', 'htm'];
    $isPdf    = in_array($ext, $pdfExts, true);
    $isText   = in_array($ext, $textExts, true);

    if (!$isPdf && !$isText) {
        throw new ApiError('UNSUPPORTED_FILE_TYPE', 'Dieser Dateityp wird für die KI-Analyse nicht unterstützt (PDF, TXT, CSV, JSON, XML, HTML, MD)');
    }

    $fileName = basename($absPath);

    $systemPrompt = 'Du bist ein hilfreicher Assistent, der Dokumente analysiert und Fragen dazu beantwortet. Beziehe dich in deinen Antworten immer konkret auf den Inhalt des bereitgestellten Dokuments. Antworte auf Deutsch, präzise und klar.';

    if ($isText) {
        // Textdateien direkt einbetten
        $rawContent  = file_get_contents($absPath);
        $textContent = mb_convert_encoding($rawContent, 'UTF-8', 'UTF-8, ISO-8859-1');
        $userContent = [
            ['type' => 'text', 'text' => "Datei: {$fileName}\n\nInhalt:\n{$textContent}"],
            ['type' => 'text', 'text' => $message],
        ];
    } else {
        // PDF: Text per pdftotext extrahieren — funktioniert für beliebige Dateigrößen
        $escapedPath = escapeshellarg($absPath);
        $extracted   = shell_exec("pdftotext -enc UTF-8 {$escapedPath} - 2>/dev/null");

        if (!empty(trim($extracted ?? ''))) {
            // Textextraktion erfolgreich — auf ~400K Zeichen kappen (≈ 100K Tokens)
            $maxChars = 400000;
            $truncated = mb_strlen($extracted) > $maxChars;
            if ($truncated) {
                $extracted = mb_substr($extracted, 0, $maxChars);
            }
            $docText = "Datei: {$fileName}" . ($truncated ? " [nur erste ~400.000 Zeichen]" : "") . "\n\nInhalt:\n{$extracted}";
            $userContent = [
                ['type' => 'text', 'text' => $docText],
                ['type' => 'text', 'text' => $message],
            ];
        } else {
            // Kein extrahierbarer Text (gescanntes PDF) — Base64 mit Größenlimit als Fallback
            if (filesize($absPath) > FM_MAX_FILESIZE) {
                throw new ApiError('FILE_TOO_LARGE', 'Dieses PDF enthält keinen extrahierbaren Text (gescannt?) und ist zu groß für die Bildanalyse (max. 20 MB).');
            }
            $rawContent  = file_get_contents($absPath);
            $userContent = [
                [
                    'type'   => 'document',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => 'application/pdf',
                        'data'       => base64_encode($rawContent),
                    ],
                    'title'  => $fileName,
                ],
                ['type' => 'text', 'text' => $message],
            ];
        }
    }

    $requestBody = json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 2048,
        'system'     => $systemPrompt,
        'messages'   => [['role' => 'user', 'content' => $userContent]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT       => 60,
        CURLOPT_HTTPHEADER    => [
            'Content-Type: application/json',
            'x-api-key: ' . $anthropicKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new ApiError('CLAUDE_API_ERROR', 'cURL-Fehler: ' . $curlError);
    }
    if ($httpCode !== 200) {
        $apiErr = json_decode($response, true);
        $detail = $apiErr['error']['message'] ?? $response;
        throw new ApiError('CLAUDE_API_ERROR', 'Claude API Fehler (HTTP ' . $httpCode . '): ' . $detail);
    }

    $responseData = json_decode($response, true);
    $answer = $responseData['content'][0]['text'] ?? '';

    if (empty($answer)) {
        throw new ApiError('CLAUDE_API_ERROR', 'Leere Antwort von Claude');
    }

    resultInfo(true, 'OK', ['answer' => $answer]);
}
