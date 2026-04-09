<?php
// backend/api/lxcars/anpr.php

/**
 * ANPR (Automatic Number Plate Recognition) API fuer LxCars
 * Verwaltet Kameras, Aktoren und Kennzeichen-Erkennungen
 */

// ============================================================================
// KAMERAS
// ============================================================================

/**
 * Alle ANPR-Kameras laden
 *
 * @testdata {}
 */
function getAnprCameras() {
    $db = DbhCompany::begin();
    $rows = $db->getAll(
        "SELECT c.*, a.name AS actuator_name
         FROM anpr_cameras_lxcars c
         LEFT JOIN anpr_actuators_lxcars a ON c.actuator_id = a.id
         ORDER BY c.id"
    );
    resultInfo(true, '', ['cameras' => $rows ?: []]);
}

/**
 * ANPR-Kamera speichern (neu oder update)
 *
 * @param array $data['camera'] Kamera-Daten
 * @testdata {"camera": {"name": "Zufahrt", "rtsp_url": "rtsp://192.168.1.100:554/stream"}}
 */
function saveAnprCamera($data) {
    $cam = $data['camera'] ?? null;
    if (!$cam || empty($cam['name']) || empty($cam['rtsp_url'])) {
        resultInfo(false, 'MISSING_DATA', 'Name und RTSP-URL sind Pflichtfelder');
        return;
    }

    $db = DbhCompany::begin();
    $id = intval($cam['id'] ?? 0);

    $params = [
        ':name'             => $cam['name'],
        ':rtsp_url'         => $cam['rtsp_url'],
        ':enabled'          => ($cam['enabled'] ?? true) ? 't' : 'f',
        ':direction_mode'   => $cam['direction_mode'] ?? 'size',
        ':position'         => $cam['position'] ?? 'front',
        ':frame_interval'   => floatval($cam['frame_interval'] ?? 0.5),
        ':min_confidence'   => floatval($cam['min_confidence'] ?? 0.60),
        ':min_detections'   => intval($cam['min_detections'] ?? 3),
        ':cooldown_minutes' => intval($cam['cooldown_minutes'] ?? 5),
        ':action_type'      => $cam['action_type'] ?? 'infobar',
        ':actuator_id'      => !empty($cam['actuator_id']) ? intval($cam['actuator_id']) : null,
        ':gate_height_mode' => $cam['gate_height_mode'] ?? 'full',
        ':note'             => $cam['note'] ?? null,
        ':calibration_gate_height_cm' => !empty($cam['calibration_gate_height_cm']) ? intval($cam['calibration_gate_height_cm']) : 300,
        ':calibration_gate_top_y'     => !empty($cam['calibration_gate_top_y']) ? intval($cam['calibration_gate_top_y']) : null,
        ':calibration_gate_bottom_y'  => !empty($cam['calibration_gate_bottom_y']) ? intval($cam['calibration_gate_bottom_y']) : null,
    ];

    if ($id > 0) {
        $params[':id'] = $id;
        $db->execute(
            "UPDATE anpr_cameras_lxcars SET
                name = :name, rtsp_url = :rtsp_url, enabled = :enabled,
                direction_mode = :direction_mode, position = :position,
                frame_interval = :frame_interval, min_confidence = :min_confidence,
                min_detections = :min_detections, cooldown_minutes = :cooldown_minutes,
                action_type = :action_type, actuator_id = :actuator_id,
                gate_height_mode = :gate_height_mode, note = :note,
                calibration_gate_height_cm = :calibration_gate_height_cm,
                calibration_gate_top_y = :calibration_gate_top_y,
                calibration_gate_bottom_y = :calibration_gate_bottom_y,
                mtime = now()
             WHERE id = :id",
            $params
        );
    } else {
        $db->execute(
            "INSERT INTO anpr_cameras_lxcars
                (name, rtsp_url, enabled, direction_mode, position,
                 frame_interval, min_confidence, min_detections, cooldown_minutes,
                 action_type, actuator_id, gate_height_mode, note,
                 calibration_gate_height_cm, calibration_gate_top_y, calibration_gate_bottom_y)
             VALUES
                (:name, :rtsp_url, :enabled, :direction_mode, :position,
                 :frame_interval, :min_confidence, :min_detections, :cooldown_minutes,
                 :action_type, :actuator_id, :gate_height_mode, :note,
                 :calibration_gate_height_cm, :calibration_gate_top_y, :calibration_gate_bottom_y)",
            $params
        );
        $id = $db->getOne("SELECT currval(pg_get_serial_sequence('anpr_cameras_lxcars', 'id'))")['currval'];
    }

    resultInfo(true, '', ['id' => intval($id)]);
}

/**
 * ANPR-Kamera loeschen
 *
 * @param int $data['id'] Kamera-ID
 * @testdata {"id": 1}
 */
function deleteAnprCamera($data) {
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'MISSING_ID');
        return;
    }
    $db = DbhCompany::begin();
    $db->execute("DELETE FROM anpr_cameras_lxcars WHERE id = :id", [':id' => $id]);
    resultInfo(true);
}

// ============================================================================
// AKTOREN
// ============================================================================

/**
 * Alle ANPR-Aktoren laden
 *
 * @testdata {}
 */
function getAnprActuators() {
    $db = DbhCompany::begin();
    $rows = $db->getAll(
        "SELECT * FROM anpr_actuators_lxcars ORDER BY id"
    );
    resultInfo(true, '', ['actuators' => $rows ?: []]);
}

/**
 * ANPR-Aktor speichern (neu oder update)
 *
 * @param array $data['actuator'] Aktor-Daten
 * @testdata {"actuator": {"name": "Werkstatttor", "type": "gate", "host": "192.168.1.200", "port": 502}}
 */
function saveAnprActuator($data) {
    $act = $data['actuator'] ?? null;
    if (!$act || empty($act['name']) || empty($act['host'])) {
        resultInfo(false, 'MISSING_DATA', 'Name und Host sind Pflichtfelder');
        return;
    }

    $db = DbhCompany::begin();
    $id = intval($act['id'] ?? 0);

    $params = [
        ':name'             => $act['name'],
        ':type'             => $act['type'] ?? 'gate',
        ':protocol'         => $act['protocol'] ?? 'tcp',
        ':host'             => $act['host'],
        ':port'             => intval($act['port'] ?? 502),
        ':command_open'     => $act['command_open'] ?? null,
        ':command_close'    => $act['command_close'] ?? null,
        ':command_partial'  => $act['command_partial'] ?? null,
        ':max_height_cm'    => intval($act['max_height_cm'] ?? 300),
        ':height_buffer_cm' => intval($act['height_buffer_cm'] ?? 30),
        ':timeout_seconds'  => intval($act['timeout_seconds'] ?? 30),
        ':enabled'          => ($act['enabled'] ?? true) ? 't' : 'f',
        ':note'             => $act['note'] ?? null,
    ];

    if ($id > 0) {
        $params[':id'] = $id;
        $db->execute(
            "UPDATE anpr_actuators_lxcars SET
                name = :name, type = :type, protocol = :protocol,
                host = :host, port = :port,
                command_open = :command_open, command_close = :command_close,
                command_partial = :command_partial,
                max_height_cm = :max_height_cm, height_buffer_cm = :height_buffer_cm,
                timeout_seconds = :timeout_seconds, enabled = :enabled, note = :note,
                mtime = now()
             WHERE id = :id",
            $params
        );
    } else {
        $db->execute(
            "INSERT INTO anpr_actuators_lxcars
                (name, type, protocol, host, port,
                 command_open, command_close, command_partial,
                 max_height_cm, height_buffer_cm, timeout_seconds, enabled, note)
             VALUES
                (:name, :type, :protocol, :host, :port,
                 :command_open, :command_close, :command_partial,
                 :max_height_cm, :height_buffer_cm, :timeout_seconds, :enabled, :note)",
            $params
        );
        $id = $db->getOne("SELECT currval(pg_get_serial_sequence('anpr_actuators_lxcars', 'id'))")['currval'];
    }

    resultInfo(true, '', ['id' => intval($id)]);
}

/**
 * ANPR-Aktor loeschen
 *
 * @param int $data['id'] Aktor-ID
 * @testdata {"id": 1}
 */
function deleteAnprActuator($data) {
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'MISSING_ID');
        return;
    }
    $db = DbhCompany::begin();
    // Pruefen ob noch Kameras verknuepft sind
    $linked = $db->getOne(
        "SELECT COUNT(*) AS cnt FROM anpr_cameras_lxcars WHERE actuator_id = :id",
        [':id' => $id]
    );
    if ($linked && intval($linked['cnt']) > 0) {
        resultInfo(false, 'ACTUATOR_IN_USE', 'Aktor ist noch mit Kameras verknuepft');
        return;
    }
    $db->execute("DELETE FROM anpr_actuators_lxcars WHERE id = :id", [':id' => $id]);
    resultInfo(true);
}

// ============================================================================
// ERKENNUNGEN
// ============================================================================

/**
 * Neue Erkennung melden (vom Python-Dienst aufgerufen)
 *
 * Prueft ob das Fahrzeug bekannt ist und ob ein offener Auftrag existiert.
 * Speichert die Erkennung und fuehrt ggf. Aktionen aus.
 *
 * @param string $data['kennzeichen'] Erkanntes Kennzeichen
 * @param float  $data['confidence'] Erkennungssicherheit (0-1)
 * @param string $data['direction'] Fahrtrichtung: 'in' oder 'out'
 * @param int    $data['camera_id'] Kamera-ID
 * @param int    $data['vehicle_height_px'] Fahrzeughoehe in Pixeln (optional)
 * @param int    $data['frame_width'] Frame-Breite (optional)
 * @param int    $data['frame_height'] Frame-Hoehe (optional)
 * @testdata {"kennzeichen": "B-AB 1234", "confidence": 0.95, "direction": "in", "camera_id": 1}
 */
function reportAnprDetection($data) {
    $kennzeichen = strtoupper(trim($data['kennzeichen'] ?? ''));
    $confidence = floatval($data['confidence'] ?? 0);
    $direction = $data['direction'] ?? 'in';
    $camera_id = intval($data['camera_id'] ?? 0);

    if (empty($kennzeichen)) {
        resultInfo(false, 'MISSING_PLATE');
        return;
    }

    $db = DbhCompany::begin();

    // Kennzeichen ohne Leerzeichen (so wie in der DB)
    $kennzeichen = str_replace(' ', '', $kennzeichen);

    // Blacklist prüfen (ohne Leerzeichen vergleichen)
    $blacklistRow = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = 'anpr_blacklist'"
    );
    if ($blacklistRow && !empty($blacklistRow['value'])) {
        $blacklist = array_map(function($p) { return strtoupper(str_replace(' ', '', trim($p))); },
                               explode(',', $blacklistRow['value']));
        if (in_array($kennzeichen, $blacklist)) {
            resultInfo(true, 'BLACKLISTED', 'Kennzeichen ist auf der Blacklist');
            return;
        }
    }

    // Cooldown pruefen: Wurde dieses Kennzeichen kuerzlich schon gemeldet?
    $cooldown = 5; // Default
    if ($camera_id > 0) {
        $cam = $db->getOne(
            "SELECT cooldown_minutes FROM anpr_cameras_lxcars WHERE id = :id",
            [':id' => $camera_id]
        );
        if ($cam) $cooldown = intval($cam['cooldown_minutes']);
    }

    $recent = $db->getOne(
        "SELECT id FROM anpr_detections_lxcars
         WHERE c_ln = :plate AND detected_at > now() - interval ':cooldown minutes'
         ORDER BY detected_at DESC LIMIT 1",
        [':plate' => $kennzeichen, ':cooldown' => $cooldown]
    );
    // Achtung: Interval mit Parameter funktioniert nicht direkt — Workaround:
    $recent = $db->getOne(
        "SELECT id FROM anpr_detections_lxcars
         WHERE c_ln = :plate AND detected_at > now() - make_interval(mins => :cooldown)
         ORDER BY detected_at DESC LIMIT 1",
        [':plate' => $kennzeichen, ':cooldown' => $cooldown]
    );

    if ($recent) {
        resultInfo(true, 'COOLDOWN', 'Kennzeichen kuerzlich bereits gemeldet');
        return;
    }

    // Fahrzeug in DB suchen
    $car = $db->getOne(
        "SELECT c.c_id, c.c_ow AS customer_id, c.c_ln,
                cv.name AS customer_name
         FROM cars_lxcars c
         LEFT JOIN customer cv ON c.c_ow = cv.id
         WHERE c.c_ln = :plate",
        [':plate' => $kennzeichen]
    );

    $c_id = $car ? intval($car['c_id']) : null;
    $customer_id = $car ? intval($car['customer_id']) : null;

    // Offenen Auftrag pruefen
    $has_open_order = false;
    if ($c_id) {
        $open = $db->getOne(
            "SELECT 1 FROM oe
             JOIN oe_ext ON oe.id = oe_ext.oe_id
             WHERE oe_ext.c_id = :c_id AND oe.closed IS NOT TRUE
             LIMIT 1",
            [':c_id' => $c_id]
        );
        $has_open_order = !!$open;
    }

    // Aktion bestimmen
    $show_unknown = true; // Default: unbekannte Fahrzeuge anzeigen
    $action_taken = 'none';

    if ($has_open_order) {
        // Fahrzeug hat offenen Auftrag → nicht in Infoleiste (Probefahrt etc.)
        $action_taken = 'none';
    } else {
        // Kamera-Aktion ermitteln
        $action_type = 'infobar';
        if ($camera_id > 0) {
            $cam = $db->getOne(
                "SELECT action_type, actuator_id, gate_height_mode FROM anpr_cameras_lxcars WHERE id = :id",
                [':id' => $camera_id]
            );
            if ($cam) $action_type = $cam['action_type'];
        }

        if ($action_type === 'infobar' || $action_type === 'both') {
            $action_taken = 'infobar';
        }
        if (($action_type === 'actuator' || $action_type === 'both') && $cam && !empty($cam['actuator_id'])) {
            $action_taken = ($action_taken === 'infobar') ? 'both' : 'gate_open';
        }
    }

    // Erkennung speichern
    $db->execute(
        "INSERT INTO anpr_detections_lxcars
            (camera_id, c_ln, c_id, customer_id, direction, confidence,
             vehicle_height_px, frame_width, frame_height, action_taken,
             dismissed)
         VALUES
            (:camera_id, :c_ln, :c_id, :customer_id, :direction, :confidence,
             :vehicle_height_px, :frame_width, :frame_height, :action_taken,
             :dismissed)",
        [
            ':camera_id'         => $camera_id > 0 ? $camera_id : null,
            ':c_ln'              => $kennzeichen,
            ':c_id'              => $c_id,
            ':customer_id'       => $customer_id,
            ':direction'         => $direction,
            ':confidence'        => $confidence,
            ':vehicle_height_px' => !empty($data['vehicle_height_px']) ? intval($data['vehicle_height_px']) : null,
            ':frame_width'       => !empty($data['frame_width']) ? intval($data['frame_width']) : null,
            ':frame_height'      => !empty($data['frame_height']) ? intval($data['frame_height']) : null,
            ':action_taken'      => $action_taken,
            ':dismissed'         => ($action_taken === 'none') ? 't' : 'f',
        ]
    );

    resultInfo(true, '', [
        'action' => $action_taken,
        'known_vehicle' => !!$car,
        'has_open_order' => $has_open_order,
        'customer_name' => $car['customer_name'] ?? null,
        'c_id' => $c_id,
    ]);
}

/**
 * Ausstehende ANPR-Erkennungen fuer die Infoleiste laden
 *
 * Nur Einfahrten ohne offenen Auftrag, nicht-dismissed, innerhalb TTL
 *
 * @testdata {}
 */
function getPendingAnprDetections() {
    $db = DbhCompany::begin();

    $ttl = 8; // Default: 8 Stunden
    $rows = $db->getAll(
        "SELECT d.id, d.c_ln, d.c_id, d.customer_id, d.confidence,
                d.detected_at, d.action_taken, d.camera_id,
                c.name AS customer_name,
                car.c_id AS vehicle_id,
                cam.name AS camera_name
         FROM anpr_detections_lxcars d
         LEFT JOIN customer c ON d.customer_id = c.id
         LEFT JOIN cars_lxcars car ON d.c_id = car.c_id
         LEFT JOIN anpr_cameras_lxcars cam ON d.camera_id = cam.id
         WHERE d.dismissed IS NOT TRUE
           AND d.direction = 'in'
           AND d.action_taken IN ('infobar', 'both')
           AND d.detected_at > now() - make_interval(hours => :ttl)
         ORDER BY d.detected_at DESC",
        [':ttl' => $ttl]
    );

    resultInfo(true, '', ['detections' => $rows ?: []]);
}

/**
 * ANPR-Erkennung als erledigt markieren (dismiss)
 *
 * @param int $data['id'] Detection-ID
 * @testdata {"id": 1}
 */
function dismissAnprDetection($data) {
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'MISSING_ID');
        return;
    }

    $db = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();
    $login = $auth->getLogin();

    $emp = $db->getOne("SELECT id FROM employee WHERE login = :login", [':login' => $login]);
    $emp_id = $emp ? intval($emp['id']) : null;

    $db->execute(
        "UPDATE anpr_detections_lxcars SET dismissed = true, dismissed_by = :emp WHERE id = :id",
        [':id' => $id, ':emp' => $emp_id]
    );
    resultInfo(true);
}

/**
 * ANPR-Erkennungs-Historie laden (fuer Config-Ansicht)
 *
 * @param int $data['limit'] Max. Anzahl (default 50)
 * @param int $data['camera_id'] Optional: nur fuer diese Kamera
 * @testdata {"limit": 50}
 */
function getAnprDetectionHistory($data) {
    $db = DbhCompany::begin();
    $limit = intval($data['limit'] ?? 50);
    $camera_id = intval($data['camera_id'] ?? 0);

    $where = '';
    $params = [':limit' => $limit];

    if ($camera_id > 0) {
        $where = 'WHERE d.camera_id = :camera_id';
        $params[':camera_id'] = $camera_id;
    }

    $rows = $db->getAll(
        "SELECT d.id, d.c_ln, d.c_id, d.customer_id, d.confidence,
                d.direction, d.detected_at, d.action_taken, d.dismissed,
                c.name AS customer_name,
                cam.name AS camera_name
         FROM anpr_detections_lxcars d
         LEFT JOIN customer c ON d.customer_id = c.id
         LEFT JOIN anpr_cameras_lxcars cam ON d.camera_id = cam.id
         $where
         ORDER BY d.detected_at DESC
         LIMIT :limit",
        $params
    );

    resultInfo(true, '', ['detections' => $rows ?: []]);
}

/**
 * ANPR-Service-Konfiguration laden (fuer den Python-Dienst)
 *
 * Liefert alle aktiven Kameras mit ihren Einstellungen und Aktoren
 *
 * @testdata {}
 */
function getAnprServiceConfig() {
    $db = DbhCompany::begin();

    $cameras = $db->getAll(
        "SELECT c.*,
                a.name AS actuator_name, a.type AS actuator_type,
                a.protocol AS actuator_protocol, a.host AS actuator_host,
                a.port AS actuator_port, a.command_open AS actuator_command_open,
                a.command_close AS actuator_command_close,
                a.command_partial AS actuator_command_partial,
                a.max_height_cm AS actuator_max_height_cm,
                a.height_buffer_cm AS actuator_height_buffer_cm,
                a.timeout_seconds AS actuator_timeout_seconds
         FROM anpr_cameras_lxcars c
         LEFT JOIN anpr_actuators_lxcars a ON c.actuator_id = a.id
         WHERE c.enabled = true
         ORDER BY c.id"
    );

    resultInfo(true, '', ['cameras' => $cameras ?: []]);
}

// ============================================================================
// SERVICE-STATUS & STEUERUNG
// ============================================================================

/**
 * ANPR-Service-Status prüfen
 *
 * @testdata {}
 */
function getAnprServiceStatus() {
    $output = shell_exec('systemctl is-active anpr 2>&1');
    $status = trim($output ?? 'unknown');

    // Zusätzlich: PID und Uptime wenn aktiv
    $details = null;
    if ($status === 'active') {
        $raw = shell_exec('systemctl show anpr --property=MainPID,ActiveEnterTimestamp 2>&1');
        if ($raw) {
            foreach (explode("\n", $raw) as $line) {
                if (str_starts_with($line, 'MainPID=')) {
                    $details['pid'] = intval(substr($line, 8));
                }
                if (str_starts_with($line, 'ActiveEnterTimestamp=')) {
                    $details['started_at'] = substr($line, 21);
                }
            }
        }
    }

    resultInfo(true, '', [
        'status' => $status,
        'details' => $details,
    ]);
}

/**
 * ANPR-Service neu starten
 *
 * Benötigt: www-data darf 'sudo systemctl restart anpr' ohne Passwort ausführen.
 * Einrichten: sudo visudo -f /etc/sudoers.d/anpr
 *             www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart anpr
 *
 * @testdata {}
 */
function restartAnprService() {
    $output = shell_exec('sudo systemctl restart anpr 2>&1');
    // Warten bis Service wieder aktiv ist (max 5s)
    $status = 'unknown';
    for ($i = 0; $i < 10; $i++) {
        usleep(500000);
        $status = trim(shell_exec('systemctl is-active anpr 2>&1') ?? 'unknown');
        if ($status === 'active') break;
    }

    // Details (PID, Startzeit) gleich mitliefern
    $details = null;
    if ($status === 'active') {
        $raw = shell_exec('systemctl show anpr --property=MainPID,ActiveEnterTimestamp 2>&1');
        if ($raw) {
            foreach (explode("\n", $raw) as $line) {
                if (str_starts_with($line, 'MainPID=')) {
                    $details['pid'] = intval(substr($line, 8));
                }
                if (str_starts_with($line, 'ActiveEnterTimestamp=')) {
                    $details['started_at'] = substr($line, 21);
                }
            }
        }
    }

    resultInfo($status === 'active', '', [
        'status' => $status,
        'details' => $details,
        'output' => trim($output ?? ''),
    ]);
}

// ============================================================================
// TEST
// ============================================================================

/**
 * Bild oder Video mit dem Erkennungsskript testen
 *
 * Empfängt eine hochgeladene Datei per multipart/form-data,
 * führt detect_plate.py darauf aus und liefert die Ergebnisse.
 *
 * @testdata {}
 */
function testAnprFile() {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        resultInfo(false, 'NO_FILE', 'Keine Datei hochgeladen');
        return;
    }

    $tmpFile = $_FILES['file']['tmp_name'];
    $originalName = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    // Nur Bilder und Videos erlauben
    $allowedExt = ['jpg', 'jpeg', 'png', 'bmp', 'webp', 'mp4', 'avi', 'mov', 'mkv'];
    if (!in_array($ext, $allowedExt)) {
        resultInfo(false, 'INVALID_FORMAT', 'Nur Bild- und Videodateien erlaubt');
        return;
    }

    // Datei in tmp-Verzeichnis kopieren (mit Endung, damit OpenCV sie erkennt)
    $testFile = sys_get_temp_dir() . '/anpr_test_' . uniqid() . '.' . $ext;
    move_uploaded_file($tmpFile, $testFile);

    // Python-Skript aufrufen
    $scriptDir = __DIR__ . '/../../services/plate-recognition';
    $python = $scriptDir . '/venv/bin/python';
    $script = $scriptDir . '/detect_plate.py';

    if (!file_exists($python)) {
        @unlink($testFile);
        resultInfo(false, 'PYTHON_NOT_FOUND', 'Python venv nicht gefunden. Bitte erst installieren: cd backend/services/plate-recognition && python3 -m venv venv && ./venv/bin/pip install -r requirements.txt');
        return;
    }

    $isVideo = in_array($ext, ['mp4', 'avi', 'mov', 'mkv']);
    $flag = $isVideo ? '--video' : '--image';

    // HOME auf den Besitzer des Projektverzeichnisses setzen,
    // damit PaddleOCR die gecachten Modelle findet (~/.paddleocr/)
    $projectDir = realpath(__DIR__ . '/../../../');
    $ownerUid = fileowner($projectDir);
    $ownerInfo = posix_getpwuid($ownerUid);
    $homeDir = $ownerInfo['dir'] ?? '/home/work';
    $cmd = 'HOME=' . escapeshellarg($homeDir) . ' '
         . escapeshellcmd($python) . ' ' . escapeshellarg($script)
         . ' ' . $flag . ' ' . escapeshellarg($testFile)
         . ' --json 2>&1';

    $output = shell_exec($cmd);
    @unlink($testFile);

    // JSON aus der Ausgabe extrahieren
    $results = [];
    if ($output) {
        // Suche nach JSON-Zeile im Output
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '[') || str_starts_with($line, '{')) {
                $decoded = json_decode($line, true);
                if ($decoded !== null) {
                    $results = is_array($decoded) && isset($decoded[0]) ? $decoded : [$decoded];
                    break;
                }
            }
        }
    }

    resultInfo(true, '', ['results' => $results]);
}
