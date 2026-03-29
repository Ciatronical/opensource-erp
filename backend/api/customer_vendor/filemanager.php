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

define('FM_DATA_DIR', realpath(__DIR__ . '/../../data') ?: __DIR__ . '/../../data');
define('FM_MAX_FILESIZE', 20 * 1024 * 1024); // 20 MB
define('FM_STORAGE_NAME', 'local');

// =========================================================================
// Hilfsfunktionen (keine API-Endpoints)
// =========================================================================

/**
 * Verzeichnis-Konfiguration aus defaults_oserp (dir_group, dir_mode).
 * Wird einmal pro Request geladen und gecached.
 */
function fmGetDirConfig() {
    static $config = null;
    if ($config !== null) return $config;

    $config = ['mode' => 0755, 'group' => ''];
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
    $replacements = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
                     'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue'];
    $name = strtr($name, $replacements);
    return preg_replace('/[^a-zA-Z0-9\-]/', '_', $name);
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
    $rootDir = FM_DATA_DIR . '/' . $basePath . '/' . $cvId;
    if (!is_dir($rootDir)) {
        fmMkdir($rootDir);
    }
    return $rootDir;
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
    $resolvedData = realpath(FM_DATA_DIR);
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

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    if (!$cvId) { resultInfo(false, 'cv_id required'); return; }

    // Ordner, Symlinks und ggf. Fahrzeuge sicherstellen (idempotent)
    $db = DbhCompany::begin();
    $table = ($src === 'V') ? 'vendor' : 'customer';
    $row = $db->getOne("SELECT name FROM $table WHERE id = :id", [':id' => $cvId]);
    ensureCustomerFolder($cvId, $src, trim($row['name'] ?? ''));

    $rootDir = vfGetRootDir($cvId, $src);
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

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    if (!$cvId) { resultInfo(false, 'cv_id required'); return; }

    $rootDir = vfGetRootDir($cvId, $src);
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
        resultInfo(false, 'No file uploaded');
        return;
    }

    $file = $_FILES['file'];
    $filename = basename($file['name']);
    $filename = preg_replace('/[^\w.\-\s]/', '_', $filename);
    $destination = $targetDir . '/' . $filename;

    move_uploaded_file($file['tmp_name'], $destination);
    resultInfo(true, 'File uploaded', ['filename' => $filename]);
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

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    if (!$cvId) { resultInfo(false, 'cv_id required'); return; }

    $rootDir = vfGetRootDir($cvId, $src);
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

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    if (!$cvId) { resultInfo(false, 'cv_id required'); return; }

    $rootDir = vfGetRootDir($cvId, $src);
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

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    if (!$cvId) { resultInfo(false, 'cv_id required'); return; }

    $rootDir = vfGetRootDir($cvId, $src);
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

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    if (!$cvId) { resultInfo(false, 'cv_id required'); return; }

    $rootDir = vfGetRootDir($cvId, $src);
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

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    if (!$cvId) {
        http_response_code(400);
        echo 'cv_id required';
        exit;
    }

    $rootDir = vfGetRootDir($cvId, $src);
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

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    if (!$cvId) {
        http_response_code(400);
        echo 'cv_id required';
        exit;
    }

    $rootDir = vfGetRootDir($cvId, $src);
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

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    if (!$cvId) { resultInfo(false, 'cv_id required'); return; }

    $rootDir = vfGetRootDir($cvId, $src);
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

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    if (!$cvId) { resultInfo(false, 'cv_id required'); return; }

    $rootDir = vfGetRootDir($cvId, $src);
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

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    if (!$cvId) { resultInfo(false, 'cv_id required'); return; }

    $rootDir = vfGetRootDir($cvId, $src);
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

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    if (!$cvId) { resultInfo(false, 'cv_id required'); return; }

    $rootDir = vfGetRootDir($cvId, $src);
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

    $cvId = intval($data['cv_id'] ?? 0);
    $src = $data['src'] ?? 'C';
    if (!$cvId) { resultInfo(false, 'cv_id required'); return; }

    $rootDir = vfGetRootDir($cvId, $src);
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
    $cvDir = FM_DATA_DIR . '/' . $basePath . '/' . $cvId;

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
    $cvDir = FM_DATA_DIR . '/' . $basePath . '/' . $cvId;

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
    $cvDir = FM_DATA_DIR . '/' . $basePath . '/' . $cvId;

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
    $cvDir = FM_DATA_DIR . '/' . $basePath . '/' . $cvId;

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
    $cvDir = FM_DATA_DIR . '/' . $basePath . '/' . $cvId;

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

    $resolvedData = realpath(FM_DATA_DIR);
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
    $cvDir = FM_DATA_DIR . '/' . $basePath . '/' . $cvId;
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
 * Erstellt die Ordner unter FM_DATA_DIR/fahrzeugschein/{c_id}/.
 * Bereits vorhandene Ordner werden nicht ueberschrieben.
 *
 * @param int $carId Fahrzeug-ID (c_id)
 */
function ensureVehicleFolders($carId) {
    $carDir = FM_DATA_DIR . '/fahrzeugschein/' . intval($carId);
    if (!is_dir($carDir)) {
        fmMkdir($carDir);
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
    $baseDir = FM_DATA_DIR . '/' . $basePath;
    $cvDir = $baseDir . '/' . $cvId;

    if (!is_dir($cvDir)) {
        fmMkdir($cvDir);
    }

    $nameDir = $baseDir . '/by-name';
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
                    $targetPath = '../../../fahrzeugschein/' . $v['c_id'];
                    symlink($targetPath, $linkPath);
                }
            }
        }
    }
}
