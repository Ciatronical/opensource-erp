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
        ':ignore_right_pct'           => intval($cam['ignore_right_pct'] ?? 0),
        ':ignore_left_pct'            => intval($cam['ignore_left_pct'] ?? 0),
        ':direction_filter'           => in_array($cam['direction_filter'] ?? '', ['off', 'moving', 'approaching']) ? $cam['direction_filter'] : 'approaching',
        // direction_required aus direction_filter ableiten (Legacy-Feld für Rückwärtskompatibilität)
        ':direction_required'         => ($cam['direction_filter'] ?? 'approaching') === 'off' ? 'f' : 't',
        ':grid_size'                  => intval($cam['grid_size'] ?? 10) ?: 10,
        ':save_snapshots'             => isset($cam['save_snapshots']) ? ($cam['save_snapshots'] ? 't' : 'f') : 't',
        ':excluded_cells'             => json_encode($cam['excluded_cells'] ?? []),
        ':min_plate_height_px'        => max(0, intval($cam['min_plate_height_px'] ?? 0)),
        ':motion_size_pct'            => max(5, min(100, intval($cam['motion_size_pct'] ?? 20))),
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
                ignore_right_pct = :ignore_right_pct,
                ignore_left_pct = :ignore_left_pct,
                direction_required = :direction_required,
                direction_filter = :direction_filter,
                grid_size = :grid_size,
                save_snapshots = :save_snapshots,
                excluded_cells = :excluded_cells,
                min_plate_height_px = :min_plate_height_px,
                motion_size_pct = :motion_size_pct,
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
                 calibration_gate_height_cm, calibration_gate_top_y, calibration_gate_bottom_y,
                 ignore_right_pct, ignore_left_pct, direction_required, direction_filter, grid_size,
                 save_snapshots, excluded_cells, min_plate_height_px, motion_size_pct)
             VALUES
                (:name, :rtsp_url, :enabled, :direction_mode, :position,
                 :frame_interval, :min_confidence, :min_detections, :cooldown_minutes,
                 :action_type, :actuator_id, :gate_height_mode, :note,
                 :calibration_gate_height_cm, :calibration_gate_top_y, :calibration_gate_bottom_y,
                 :ignore_right_pct, :ignore_left_pct, :direction_required, :direction_filter, :grid_size,
                 :save_snapshots, :excluded_cells, :min_plate_height_px, :motion_size_pct)",
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

    // Normalisierung: alle Trennzeichen (Leerzeichen, Bindestrich, Geviertstrich) entfernen.
    // Kennzeichen in der DB sind inkonsistent gespeichert ("B-AA5952", "B-CO 687", "BCO687").
    // Beide Seiten werden für den Vergleich normalisiert — gespeicherter Wert bleibt unverändert.
    $kennzeichenNorm = preg_replace('/[\s\-\x{2013}]+/u', '', $kennzeichen);

    // Blacklist normalisiert vergleichen
    $blacklistRow = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = 'anpr_blacklist'"
    );
    if ($blacklistRow && !empty($blacklistRow['value'])) {
        $blacklist = array_map(
            fn($p) => preg_replace('/[\s\-\x{2013}]+/', '', strtoupper(trim($p))),
            explode(',', $blacklistRow['value'])
        );
        if (in_array($kennzeichenNorm, $blacklist)) {
            resultInfo(true, 'BLACKLISTED', 'Kennzeichen ist auf der Blacklist');
            return;
        }
    }

    // Cooldown pruefen
    $cooldown = 5;
    if ($camera_id > 0) {
        $cam = $db->getOne(
            "SELECT cooldown_minutes FROM anpr_cameras_lxcars WHERE id = :id",
            [':id' => $camera_id]
        );
        if ($cam) $cooldown = intval($cam['cooldown_minutes']);
    }

    $recent = $db->getOne(
        "SELECT id FROM anpr_detections_lxcars
         WHERE REGEXP_REPLACE(UPPER(c_ln), '[\s\-–]+', '', 'g') = :plate
           AND detected_at > now() - make_interval(mins => :cooldown)
         ORDER BY detected_at DESC LIMIT 1",
        [':plate' => $kennzeichenNorm, ':cooldown' => $cooldown]
    );

    if ($recent) {
        resultInfo(true, 'COOLDOWN', 'Kennzeichen kuerzlich bereits gemeldet');
        return;
    }

    // Fahrzeug in DB suchen — normalisierter Vergleich auf beiden Seiten
    $car = $db->getOne(
        "SELECT c.c_id, c.c_ow AS customer_id, c.c_ln,
                cv.name AS customer_name
         FROM cars_lxcars c
         LEFT JOIN customer cv ON c.c_ow = cv.id
         WHERE REGEXP_REPLACE(UPPER(c.c_ln), '[\s\-–]+', '', 'g') = :plate",
        [':plate' => $kennzeichenNorm]
    );

    $c_id = $car ? intval($car['c_id']) : null;
    $customer_id = $car ? intval($car['customer_id']) : null;

    // Offenen Auftrag prüfen (nur wenn anpr_open_order_skip aktiv)
    $has_open_order = false;
    $skip_row = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'anpr_open_order_skip'");
    $open_order_skip = $skip_row && in_array($skip_row['value'], ['1', 't', 'true']);

    if ($c_id && $open_order_skip) {
        $open = $db->getOne(
            "SELECT 1 FROM oe
             JOIN oe_ext ON oe.id = oe_ext.oe_id
             WHERE oe_ext.c_id = :c_id AND oe.closed IS NOT TRUE
             LIMIT 1",
            [':c_id' => $c_id]
        );
        $has_open_order = !!$open;
    }

    // Kamera-Aktion ermitteln
    $action_type = 'infobar';
    $cam = null;
    if ($camera_id > 0) {
        $cam = $db->getOne(
            "SELECT action_type, actuator_id, gate_height_mode FROM anpr_cameras_lxcars WHERE id = :id",
            [':id' => $camera_id]
        );
        if ($cam) $action_type = $cam['action_type'];
    }

    $action_taken = 'none';
    if (!$has_open_order) {
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

    $ttl_row = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'anpr_detection_ttl_hours'");
    $ttl = intval($ttl_row['value'] ?? 8) ?: 8;
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
 * @param int $data['limit'] Max. Anzahl (default 200, max 500)
 * @param int $data['camera_id'] Optional: nur fuer diese Kamera
 * @testdata {"limit": 200}
 */
function getAnprDetectionHistory($data) {
    $db = DbhCompany::begin();
    $limit = min(500, max(1, intval($data['limit'] ?? 200)));
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
                d.vehicle_height_px, d.frame_width, d.frame_height,
                d.camera_id,
                c.name AS customer_name,
                cam.name AS camera_name,
                cam.rtsp_url AS camera_rtsp
         FROM anpr_detections_lxcars d
         LEFT JOIN customer c ON d.customer_id = c.id
         LEFT JOIN anpr_cameras_lxcars cam ON d.camera_id = cam.id
         $where
         ORDER BY d.detected_at DESC
         LIMIT :limit",
        $params
    );

    if ($rows) {
        $dbnameRow = $db->getOne("SELECT current_database() AS dbname");
        $snapshotBase = __DIR__ . '/../../../backend/data/' . $dbnameRow['dbname'] . '/anpr-snapshots/';
        foreach ($rows as &$row) {
            $id = $row['id'];
            // Nummerierte Dateien zählen ({id}_1.jpg, {id}_2.jpg, ...)
            $count = 0;
            for ($n = 1; $n <= 10; $n++) {
                if (file_exists($snapshotBase . $id . '_' . $n . '.jpg')) $count++;
                else break;
            }
            // Fallback: altes Format ohne Nummer
            if ($count === 0 && file_exists($snapshotBase . $id . '.jpg')) $count = 1;
            $row['has_snapshot'] = $count > 0;
            $row['snapshot_count'] = $count;
        }
        unset($row);
    }

    resultInfo(true, '', ['detections' => $rows ?: []]);
}

/**
 * Gesamte ANPR-Erkennungs-Historie löschen
 *
 * @testdata {}
 */
function clearAnprDetectionHistory() {
    $db = DbhCompany::begin();
    $db->execute("DELETE FROM anpr_detections_lxcars");
    resultInfo(true);
}

/**
 * ANPR-Dienst-Gesundheitsprotokoll laden
 *
 * Liefert Heartbeats, Reconnects und Fehler der letzten 24h sowie
 * eine Zusammenfassung pro Kamera.
 *
 * @testdata {}
 */
function getAnprHealth() {
    $db = DbhCompany::begin();

    // Letzte 100 Ereignisse der letzten 24h
    $events = $db->getAll(
        "SELECT h.id, h.camera_id, h.ts, h.event, h.message,
                h.frames, h.detections, h.skipped,
                cam.name AS camera_name
         FROM anpr_health_lxcars h
         LEFT JOIN anpr_cameras_lxcars cam ON h.camera_id = cam.id
         WHERE h.ts > now() - interval '24 hours'
         ORDER BY h.ts DESC
         LIMIT 100"
    );

    // Letzter Heartbeat pro Kamera
    $last_heartbeats = $db->getAll(
        "SELECT DISTINCT ON (camera_id)
                camera_id, ts, frames, detections, skipped
         FROM anpr_health_lxcars
         WHERE event = 'heartbeat'
         ORDER BY camera_id, ts DESC"
    );

    // Reconnects und Fehler der letzten 24h pro Kamera
    $problems = $db->getAll(
        "SELECT camera_id,
                COUNT(*) FILTER (WHERE event = 'reconnect') AS reconnects,
                COUNT(*) FILTER (WHERE event = 'error')     AS errors
         FROM anpr_health_lxcars
         WHERE ts > now() - interval '24 hours'
           AND event IN ('reconnect', 'error')
         GROUP BY camera_id"
    );

    // Erkennungen heute gesamt
    $today = $db->getOne(
        "SELECT COUNT(*) AS cnt FROM anpr_detections_lxcars
         WHERE detected_at > date_trunc('day', now())"
    );

    resultInfo(true, '', [
        'events'          => $events ?: [],
        'last_heartbeats' => $last_heartbeats ?: [],
        'problems'        => $problems ?: [],
        'today_count'     => intval($today['cnt'] ?? 0),
    ]);
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

    $details = null;
    if ($status === 'active') {
        // PID, Startzeit, User aus systemctl
        $raw = shell_exec('systemctl show anpr --property=MainPID,ActiveEnterTimestamp,User 2>&1');
        if ($raw) {
            foreach (explode("\n", $raw) as $line) {
                if (str_starts_with($line, 'MainPID='))
                    $details['pid'] = intval(substr($line, 8));
                if (str_starts_with($line, 'ActiveEnterTimestamp='))
                    $details['started_at'] = substr($line, 21);
                if (str_starts_with($line, 'User='))
                    $details['service_user'] = trim(substr($line, 5));
            }
        }

        // Startzeit als Unix-Timestamp (für Frontend einfach verarbeitbar)
        if (!empty($details['started_at'])) {
            $ts = strtotime($details['started_at']);
            if ($ts) $details['started_ts'] = $ts;
        }

        // Laufender User (kann vom konfigurierten User abweichen wenn Service veraltet)
        $pid = $details['pid'] ?? 0;
        if ($pid > 0) {
            $details['run_as_user'] = trim(shell_exec("ps -p $pid -o user= 2>/dev/null") ?? '');
            // LC_ALL=C: Dezimaltrenner ist Punkt, nicht Komma (deutsches System)
            $psOut = trim(shell_exec("LC_ALL=C ps -p $pid -o %cpu=,%mem=,rss= 2>/dev/null") ?? '');
            if ($psOut) {
                $parts = preg_split('/\s+/', trim($psOut));
                $details['cpu_pct']  = floatval(str_replace(',', '.', $parts[0] ?? '0'));
                $details['mem_pct']  = floatval(str_replace(',', '.', $parts[1] ?? '0'));
                $details['rss_kb']   = intval($parts[2] ?? 0);
            }

            // RTSP-Verbindungen dieses Prozesses
            $ssOut = shell_exec("ss -tnp 2>/dev/null | grep 'pid=$pid,' | grep ':554'") ?? '';
            $rtsp = [];
            foreach (array_filter(explode("\n", trim($ssOut))) as $line) {
                if (preg_match('/(\S+)\s+(\S+)\s+(\d+)\s+(\d+)\s+(\S+)\s+(\S+)/', $line, $m)) {
                    $rtsp[] = ['state' => $m[1], 'recv_q' => intval($m[3]),
                               'send_q' => intval($m[4]),
                               'local' => $m[5], 'remote' => $m[6]];
                }
            }
            $details['rtsp_connections'] = $rtsp;
        }

        // Letzte 50 Logzeilen (kompakt) fuer Service-Status-Card
        $log = shell_exec('journalctl -u anpr -n 50 --no-pager --output=short 2>/dev/null') ?? '';
        $details['log_tail'] = array_values(array_filter(explode("\n", $log)));

        // Erkennungen heute
        try {
            $db = DbhCompany::begin();
            $row = $db->getOne(
                "SELECT COUNT(*) AS cnt FROM anpr_detections_lxcars WHERE detected_at >= CURRENT_DATE"
            );
            $details['detections_today'] = intval($row['cnt'] ?? 0);
        } catch (\Throwable) {
            $details['detections_today'] = null;
        }
    }

    resultInfo(true, '', [
        'status'  => $status,
        'details' => $details,
    ]);
}

/**
 * ANPR-Service neu starten
 *
 * Benötigt sudo-Berechtigung — siehe backend/services/oserp-camera-sudoers
 *
 * @testdata {}
 */
function restartAnprService() {
    $output = shell_exec('sudo systemctl restart anpr.service 2>&1');
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

// ============================================================================
// SERVICE-LOG
// ============================================================================

/**
 * journalctl-Log des ANPR-Dienstes laden
 *
 * @param int    $data['lines']  Anzahl Zeilen (default 200, max 1000)
 * @param string $data['since']  Zeitfilter fuer journalctl (z.B. "1 hour ago"), optional
 * @testdata {"lines": 200}
 */
function getAnprLog($data) {
    $lines = min(1000, max(10, intval($data['lines'] ?? 200)));
    $since = trim($data['since'] ?? '');

    $cmd = 'journalctl -u anpr -n ' . $lines . ' --no-pager --output=short-iso 2>/dev/null';
    if ($since !== '') {
        $safe = preg_replace('/[^a-zA-Z0-9 \-:]/', '', $since);
        if ($safe !== '') {
            $cmd = 'journalctl -u anpr -n ' . $lines . ' --no-pager --output=short-iso'
                 . ' --since ' . escapeshellarg($safe) . ' 2>/dev/null';
        }
    }

    $raw = shell_exec($cmd) ?? '';
    $lines_out = array_values(array_filter(explode("\n", $raw)));

    resultInfo(true, '', ['lines' => $lines_out, 'count' => count($lines_out)]);
}

// ============================================================================
// DEBUG-SNAPSHOTS (Near-miss)
// ============================================================================

/**
 * Near-miss Debug-Snapshots auflisten
 *
 * Liefert maximal 200 Eintraege, neueste zuerst.
 *
 * @testdata {}
 */
function getAnprDebugSnapshots() {
    $db = DbhCompany::begin();
    $dbnameRow = $db->getOne("SELECT current_database() AS dbname");
    $dir = __DIR__ . '/../../../backend/data/' . $dbnameRow['dbname'] . '/anpr-debug-snapshots/';

    if (!is_dir($dir)) {
        resultInfo(true, '', ['snapshots' => [], 'total' => 0]);
        return;
    }

    $cameras = $db->getAll("SELECT id, name FROM anpr_cameras_lxcars ORDER BY id");
    $camNames = [];
    foreach ($cameras as $c) $camNames[intval($c['id'])] = $c['name'];

    $files = glob($dir . '*.jpg') ?: [];
    // Sortierung nach Zeitstempel (YYYYMMDD_HHMMSS_ffffff), cam_id-Präfix überspringen
    usort($files, function($a, $b) {
        $ta = substr(basename($a), strpos(basename($a), '_') + 1);
        $tb = substr(basename($b), strpos(basename($b), '_') + 1);
        return strcmp($tb, $ta);  // absteigend: neueste zuerst
    });
    $total = count($files);

    $result = [];
    foreach (array_slice($files, 0, 200) as $f) {
        // Dateiname: {cam_id}_{YYYYMMDD}_{HHMMSS}_{ffffff}_{reason}_{plate}.jpg
        $fname = basename($f, '.jpg');
        $parts = explode('_', $fname, 6);
        if (count($parts) < 5) continue;

        $cam_id = intval($parts[0]);
        $datestr = $parts[1];  // YYYYMMDD
        $timestr = $parts[2];  // HHMMSS
        $reason  = $parts[4] ?? '';
        $plate   = $parts[5] ?? '';

        $ts = null;
        if (strlen($datestr) === 8 && strlen($timestr) === 6) {
            $ts = mktime(
                intval(substr($timestr, 0, 2)), intval(substr($timestr, 2, 2)), intval(substr($timestr, 4, 2)),
                intval(substr($datestr, 4, 2)), intval(substr($datestr, 6, 2)), intval(substr($datestr, 0, 4))
            );
        }

        $result[] = [
            'filename' => basename($f),
            'cam_id'   => $cam_id,
            'cam_name' => $camNames[$cam_id] ?? "Kamera $cam_id",
            'reason'   => $reason,
            'plate'    => $plate,
            'ts'       => $ts,
        ];
    }

    resultInfo(true, '', ['snapshots' => $result, 'total' => $total]);
}

/**
 * Alle Near-miss Debug-Snapshots loeschen
 *
 * @testdata {}
 */
function clearAnprDebugSnapshots() {
    $db = DbhCompany::begin();
    $dbnameRow = $db->getOne("SELECT current_database() AS dbname");
    $dir = __DIR__ . '/../../../backend/data/' . $dbnameRow['dbname'] . '/anpr-debug-snapshots/';

    $deleted = 0;
    $failed = 0;
    if (is_dir($dir)) {
        foreach (glob($dir . '*.jpg') ?: [] as $f) {
            if (unlink($f)) $deleted++;
            else $failed++;
        }
    }

    if ($failed > 0 && $deleted === 0) {
        resultInfo(false, 'DELETE_FAILED', 'Keine Dateien konnten gelöscht werden (Berechtigung fehlt)');
        return;
    }
    resultInfo(true, '', ['deleted' => $deleted, 'failed' => $failed]);
}
