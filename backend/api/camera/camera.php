<?php
// backend/api/camera/camera.php

// ── Frigate-Konfiguration aus defaults_oserp laden ──

function _getCameraConfig(): array {
    $db = DbhCompany::begin();
    $rows = $db->getAll("SELECT key, value FROM defaults_oserp WHERE key LIKE 'camera_%'");
    $config = [];
    foreach ($rows as $row) {
        $config[$row['key']] = $row['value'];
    }
    return $config;
}

// ============================================================================
// KAMERAS (CRUD)
// ============================================================================

/**
 * Alle Kameras mit Zonen laden
 *
 * @param array $data (keine Parameter nötig)
 * @testdata {}
 */
function getCameras($data) {
    $db = DbhCompany::begin();
    $cameras = $db->getAll("
        SELECT c.*,
            COALESCE(
                (SELECT json_agg(json_build_object(
                    'id', z.id,
                    'name', z.name,
                    'frigate_zone', z.frigate_zone,
                    'color', z.color
                ) ORDER BY z.name)
                FROM camera_zone z WHERE z.camera_id = c.id),
                '[]'
            ) AS zones,
            COALESCE(
                (SELECT COUNT(*) FROM camera_event e
                 WHERE e.camera_id = c.id AND NOT e.acknowledged),
                0
            ) AS unread_events
        FROM camera c
        WHERE c.active
        ORDER BY c.sort_order, c.name
    ");
    foreach ($cameras as &$cam) {
        $cam['zones'] = json_decode($cam['zones'], true);
        $cam['unread_events'] = (int)$cam['unread_events'];
    }
    resultInfo(true, '', ['cameras' => $cameras]);
}

/**
 * Einzelne Kamera speichern (Upsert)
 *
 * @param array $data['id'] Kamera-ID (leer = neu anlegen)
 * @param array $data['name'] Anzeigename
 * @param array $data['frigate_name'] Frigate-Kameraname
 * @param array $data['stream_url'] WebRTC Stream-URL
 * @param array $data['location'] Standort
 * @param array $data['sort_order'] Sortierreihenfolge
 * @testdata {"name": "Lager Eingang", "frigate_name": "lager_eingang", "stream_url": "", "location": "Halle 1", "sort_order": 0}
 */
function saveCamera($data) {
    $db = DbhCompany::begin();

    if (!empty($data['id'])) {
        $db->execute("
            UPDATE camera SET
                name = :name,
                frigate_name = :frigate_name,
                stream_url = :stream_url,
                location = :location,
                sort_order = :sort_order,
                mtime = NOW()
            WHERE id = :id
        ", [
            ':id' => $data['id'],
            ':name' => $data['name'],
            ':frigate_name' => $data['frigate_name'],
            ':stream_url' => $data['stream_url'] ?? '',
            ':location' => $data['location'] ?? '',
            ':sort_order' => $data['sort_order'] ?? 0,
        ]);
        resultInfo(true, '', ['id' => $data['id']]);
    } else {
        $row = $db->getOne("
            INSERT INTO camera (name, frigate_name, stream_url, location, sort_order)
            VALUES (:name, :frigate_name, :stream_url, :location, :sort_order)
            RETURNING id
        ", [
            ':name' => $data['name'],
            ':frigate_name' => $data['frigate_name'],
            ':stream_url' => $data['stream_url'] ?? '',
            ':location' => $data['location'] ?? '',
            ':sort_order' => $data['sort_order'] ?? 0,
        ]);
        resultInfo(true, '', ['id' => $row['id']]);
    }
}

/**
 * Kamera deaktivieren (Soft-Delete)
 *
 * @param array $data['id'] Kamera-ID
 * @testdata {"id": 1}
 */
function deleteCamera($data) {
    $db = DbhCompany::begin();
    $db->execute("UPDATE camera SET active = false, mtime = NOW() WHERE id = :id", [':id' => $data['id']]);
    resultInfo(true);
}

// ============================================================================
// ZONEN (CRUD)
// ============================================================================

/**
 * Zone speichern (Upsert)
 *
 * @param array $data['id'] Zone-ID (leer = neu)
 * @param array $data['camera_id'] Kamera-ID
 * @param array $data['name'] Anzeigename
 * @param array $data['frigate_zone'] Frigate-Zonenname
 * @param array $data['color'] Farbe (Hex)
 * @testdata {"camera_id": 1, "name": "Wareneingang", "frigate_zone": "wareneingang", "color": "#FF5722"}
 */
function saveZone($data) {
    $db = DbhCompany::begin();

    if (!empty($data['id'])) {
        $db->execute("
            UPDATE camera_zone SET
                name = :name,
                frigate_zone = :frigate_zone,
                color = :color,
                mtime = NOW()
            WHERE id = :id
        ", [
            ':id' => $data['id'],
            ':name' => $data['name'],
            ':frigate_zone' => $data['frigate_zone'],
            ':color' => $data['color'] ?? '#FF5722',
        ]);
    } else {
        $db->execute("
            INSERT INTO camera_zone (camera_id, name, frigate_zone, color)
            VALUES (:camera_id, :name, :frigate_zone, :color)
        ", [
            ':camera_id' => $data['camera_id'],
            ':name' => $data['name'],
            ':frigate_zone' => $data['frigate_zone'],
            ':color' => $data['color'] ?? '#FF5722',
        ]);
    }
    resultInfo(true);
}

/**
 * Zone löschen
 *
 * @param array $data['id'] Zone-ID
 * @testdata {"id": 1}
 */
function deleteZone($data) {
    $db = DbhCompany::begin();
    $db->execute("DELETE FROM camera_zone WHERE id = :id", [':id' => $data['id']]);
    resultInfo(true);
}

// ============================================================================
// EVENTS
// ============================================================================

/**
 * Events laden (mit Filter)
 *
 * @param array $data['camera_id'] Filter nach Kamera (optional)
 * @param array $data['label'] Filter nach Objekttyp (optional)
 * @param array $data['zone'] Filter nach Zone (optional)
 * @param array $data['acknowledged'] Filter: true/false/all (optional, Standard: all)
 * @param array $data['date_from'] Datum von (optional)
 * @param array $data['date_to'] Datum bis (optional)
 * @param array $data['limit'] Anzahl (optional, Standard: 50)
 * @param array $data['offset'] Offset (optional, Standard: 0)
 * @testdata {"limit": 50}
 */
function getEvents($data) {
    $db = DbhCompany::begin();

    $where = ['1=1'];
    $params = [];

    if (!empty($data['camera_id'])) {
        $where[] = 'e.camera_id = :camera_id';
        $params[':camera_id'] = $data['camera_id'];
    }
    if (!empty($data['label'])) {
        $where[] = 'e.label = :label';
        $params[':label'] = $data['label'];
    }
    if (!empty($data['zone'])) {
        $where[] = ':zone = ANY(e.zones)';
        $params[':zone'] = $data['zone'];
    }
    if (isset($data['acknowledged']) && $data['acknowledged'] !== 'all') {
        $where[] = 'e.acknowledged = :ack';
        $params[':ack'] = $data['acknowledged'] === 'true' || $data['acknowledged'] === true ? 't' : 'f';
    }
    if (!empty($data['date_from'])) {
        $where[] = 'e.started_at >= :date_from';
        $params[':date_from'] = $data['date_from'];
    }
    if (!empty($data['date_to'])) {
        $where[] = 'e.started_at <= :date_to';
        $params[':date_to'] = $data['date_to'];
    }

    $limit = (int)($data['limit'] ?? 50);
    $offset = (int)($data['offset'] ?? 0);
    $whereClause = implode(' AND ', $where);

    $events = $db->getAll("
        SELECT e.*,
            c.name AS camera_display_name,
            c.location AS camera_location
        FROM camera_event e
        LEFT JOIN camera c ON c.id = e.camera_id
        WHERE $whereClause
        ORDER BY e.started_at DESC
        LIMIT $limit OFFSET $offset
    ", $params);

    $countRow = $db->getOne("
        SELECT COUNT(*) AS total FROM camera_event e WHERE $whereClause
    ", $params);

    resultInfo(true, '', [
        'events' => $events,
        'total' => (int)$countRow['total'],
    ]);
}

/**
 * Event bestätigen (acknowledged)
 *
 * @param array $data['id'] Event-ID
 * @param array $data['notes'] Optionale Notiz
 * @testdata {"id": 1}
 */
function acknowledgeEvent($data) {
    $db = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $employee = $db->getOne(
        "SELECT id FROM employee WHERE login = :login",
        [':login' => $auth->getLogin()]
    );

    $db->execute("
        UPDATE camera_event SET
            acknowledged = true,
            acknowledged_by = :emp_id,
            notes = COALESCE(:notes, notes)
        WHERE id = :id
    ", [
        ':id' => $data['id'],
        ':emp_id' => $employee['id'] ?? null,
        ':notes' => $data['notes'] ?? null,
    ]);
    resultInfo(true);
}

/**
 * Alle Events einer Kamera als gelesen markieren
 *
 * @param array $data['camera_id'] Kamera-ID
 * @testdata {"camera_id": 1}
 */
function acknowledgeAllEvents($data) {
    $db = DbhCompany::begin();
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();

    $employee = $db->getOne(
        "SELECT id FROM employee WHERE login = :login",
        [':login' => $auth->getLogin()]
    );

    $db->execute("
        UPDATE camera_event SET
            acknowledged = true,
            acknowledged_by = :emp_id
        WHERE camera_id = :camera_id AND NOT acknowledged
    ", [
        ':camera_id' => $data['camera_id'],
        ':emp_id' => $employee['id'] ?? null,
    ]);
    resultInfo(true);
}

// ============================================================================
// REGELN (CRUD)
// ============================================================================

/**
 * Alle Regeln laden
 *
 * @param array $data (keine Parameter nötig)
 * @testdata {}
 */
function getRules($data) {
    $db = DbhCompany::begin();
    $rules = $db->getAll("
        SELECT r.*,
            c.name AS camera_name,
            z.name AS zone_name
        FROM camera_rule r
        LEFT JOIN camera c ON c.id = r.camera_id
        LEFT JOIN camera_zone z ON z.id = r.zone_id
        ORDER BY r.name
    ");
    foreach ($rules as &$rule) {
        $rule['action_config'] = json_decode($rule['action_config'], true) ?: [];
        // PostgreSQL-Array zu PHP-Array
        $rule['labels'] = _pgArrayToPhp($rule['labels']);
        $rule['days_of_week'] = _pgArrayToPhp($rule['days_of_week']);
    }
    resultInfo(true, '', ['rules' => $rules]);
}

/**
 * Regel speichern (Upsert)
 *
 * @param array $data['id'] Regel-ID (leer = neu)
 * @param array $data['name'] Regelname
 * @param array $data['camera_id'] Kamera-ID (optional)
 * @param array $data['zone_id'] Zone-ID (optional)
 * @param array $data['labels'] Objekttypen als Array
 * @param array $data['time_from'] Zeitfenster von (optional)
 * @param array $data['time_to'] Zeitfenster bis (optional)
 * @param array $data['days_of_week'] Wochentage als Array
 * @param array $data['min_score'] Mindest-Score
 * @param array $data['action'] Aktionstyp (notify, whatsapp, email, log)
 * @param array $data['action_config'] JSON-Konfiguration
 * @param array $data['active'] Aktiv
 * @param array $data['cooldown_seconds'] Cooldown in Sekunden
 * @testdata {"name": "Lager nachts", "labels": ["person"], "time_from": "22:00", "time_to": "06:00", "action": "notify", "active": true, "cooldown_seconds": 300}
 */
function saveRule($data) {
    $db = DbhCompany::begin();

    $labels = _phpArrayToPg($data['labels'] ?? ['person']);
    $daysOfWeek = _phpArrayToPg($data['days_of_week'] ?? [0,1,2,3,4,5,6]);
    $actionConfig = json_encode($data['action_config'] ?? []);

    $params = [
        ':name' => $data['name'],
        ':camera_id' => !empty($data['camera_id']) ? $data['camera_id'] : null,
        ':zone_id' => !empty($data['zone_id']) ? $data['zone_id'] : null,
        ':labels' => $labels,
        ':time_from' => !empty($data['time_from']) ? $data['time_from'] : null,
        ':time_to' => !empty($data['time_to']) ? $data['time_to'] : null,
        ':days_of_week' => $daysOfWeek,
        ':min_score' => $data['min_score'] ?? 0.7,
        ':action' => $data['action'] ?? 'notify',
        ':action_config' => $actionConfig,
        ':active' => ($data['active'] ?? true) ? 't' : 'f',
        ':cooldown_seconds' => $data['cooldown_seconds'] ?? 300,
    ];

    if (!empty($data['id'])) {
        $params[':id'] = $data['id'];
        $db->execute("
            UPDATE camera_rule SET
                name = :name, camera_id = :camera_id, zone_id = :zone_id,
                labels = :labels, time_from = :time_from, time_to = :time_to,
                days_of_week = :days_of_week, min_score = :min_score,
                action = :action, action_config = :action_config,
                active = :active, cooldown_seconds = :cooldown_seconds,
                mtime = NOW()
            WHERE id = :id
        ", $params);
    } else {
        $db->execute("
            INSERT INTO camera_rule (name, camera_id, zone_id, labels,
                time_from, time_to, days_of_week, min_score,
                action, action_config, active, cooldown_seconds)
            VALUES (:name, :camera_id, :zone_id, :labels,
                :time_from, :time_to, :days_of_week, :min_score,
                :action, :action_config, :active, :cooldown_seconds)
        ", $params);
    }
    resultInfo(true);
}

/**
 * Regel löschen
 *
 * @param array $data['id'] Regel-ID
 * @testdata {"id": 1}
 */
function deleteRule($data) {
    $db = DbhCompany::begin();
    $db->execute("DELETE FROM camera_rule WHERE id = :id", [':id' => $data['id']]);
    resultInfo(true);
}

// ============================================================================
// FRIGATE WEBHOOK
// ============================================================================

/**
 * Webhook-Empfänger für Frigate-Events
 *
 * Frigate sendet bei jedem Event (new, update, end) einen HTTP-POST.
 * Konfiguration in Frigate:
 *   mqtt:
 *     enabled: true
 *   # ODER per HTTP-Webhook:
 *   # notifications:
 *   #   webhook: http://erp-server/api/camera/?action=frigateWebhook
 *
 * @param array $data Frigate Event-Payload
 * @testdata {"type": "new", "after": {"id": "abc123", "camera": "lager", "label": "person", "zones": ["eingang"], "score": 0.85, "start_time": 1713000000, "has_snapshot": true, "has_clip": false}}
 */
function frigateWebhook($data) {
    $db = DbhCompany::begin();
    $config = _getCameraConfig();
    $frigateUrl = $config['camera_frigate_url'] ?? '';

    $type = $data['type'] ?? '';
    $eventData = $data['after'] ?? $data['before'] ?? [];

    if (empty($eventData) || empty($eventData['id'])) {
        resultInfo(false, 'INVALID_EVENT', 'Kein gültiges Frigate-Event');
        return;
    }

    $frigateEventId = $eventData['id'];
    $cameraName = $eventData['camera'] ?? '';
    $label = $eventData['label'] ?? 'unknown';
    $zones = $eventData['zones'] ?? [];
    $score = $eventData['score'] ?? 0;
    $startTime = isset($eventData['start_time'])
        ? date('Y-m-d H:i:s', (int)$eventData['start_time'])
        : date('Y-m-d H:i:s');
    $endTime = isset($eventData['end_time']) && $eventData['end_time']
        ? date('Y-m-d H:i:s', (int)$eventData['end_time'])
        : null;

    $snapshotUrl = '';
    $clipUrl = '';
    if ($frigateUrl) {
        if (!empty($eventData['has_snapshot'])) {
            $snapshotUrl = rtrim($frigateUrl, '/') . '/api/events/' . $frigateEventId . '/snapshot.jpg';
        }
        if (!empty($eventData['has_clip'])) {
            $clipUrl = rtrim($frigateUrl, '/') . '/api/events/' . $frigateEventId . '/clip.mp4';
        }
    }

    // Kamera-ID aus Stammdaten ermitteln
    $camera = $db->getOne(
        "SELECT id FROM camera WHERE frigate_name = :fname AND active",
        [':fname' => $cameraName]
    );
    $cameraId = $camera ? $camera['id'] : null;

    $pgZones = _phpArrayToPg($zones);

    if ($type === 'new') {
        $db->execute("
            INSERT INTO camera_event (frigate_event_id, camera_id, camera_name, label, zones, score, snapshot_url, clip_url, started_at)
            VALUES (:fid, :cam_id, :cam_name, :label, :zones, :score, :snap, :clip, :started)
            ON CONFLICT (frigate_event_id) DO NOTHING
        ", [
            ':fid' => $frigateEventId,
            ':cam_id' => $cameraId,
            ':cam_name' => $cameraName,
            ':label' => $label,
            ':zones' => $pgZones,
            ':score' => $score,
            ':snap' => $snapshotUrl,
            ':clip' => $clipUrl,
            ':started' => $startTime,
        ]);

        // pg_notify für Echtzeit-Push an Frontend
        $notifyPayload = json_encode([
            'type' => 'camera_event',
            'event' => 'new',
            'camera' => $cameraName,
            'label' => $label,
            'zones' => $zones,
            'score' => $score,
            'frigate_event_id' => $frigateEventId,
        ]);
        $db->execute("SELECT pg_notify('camera_event', :payload)", [':payload' => $notifyPayload]);

        // Regeln prüfen und ggf. Aktionen auslösen
        _checkRules($db, $cameraId, $cameraName, $label, $zones, $score, $frigateEventId, $snapshotUrl);

    } elseif ($type === 'end') {
        $db->execute("
            UPDATE camera_event SET
                ended_at = :ended,
                score = GREATEST(score, :score),
                clip_url = CASE WHEN :clip != '' THEN :clip ELSE clip_url END
            WHERE frigate_event_id = :fid
        ", [
            ':fid' => $frigateEventId,
            ':ended' => $endTime,
            ':score' => $score,
            ':clip' => $clipUrl,
        ]);

    } elseif ($type === 'update') {
        $db->execute("
            UPDATE camera_event SET
                score = GREATEST(score, :score),
                zones = :zones,
                snapshot_url = CASE WHEN :snap != '' THEN :snap ELSE snapshot_url END
            WHERE frigate_event_id = :fid
        ", [
            ':fid' => $frigateEventId,
            ':score' => $score,
            ':zones' => $pgZones,
            ':snap' => $snapshotUrl,
        ]);
    }

    resultInfo(true);
}

/**
 * Kamera-Konfiguration laden (Frigate-URL etc.)
 *
 * @param array $data (keine Parameter nötig)
 * @testdata {}
 */
function getCameraConfig($data) {
    $config = _getCameraConfig();
    resultInfo(true, '', ['config' => $config]);
}

/**
 * Dashboard-Statistiken
 *
 * @param array $data (keine Parameter nötig)
 * @testdata {}
 */
function getCameraStats($data) {
    $db = DbhCompany::begin();

    $stats = $db->getOne("
        SELECT
            (SELECT COUNT(*) FROM camera WHERE active) AS camera_count,
            (SELECT COUNT(*) FROM camera_event WHERE NOT acknowledged) AS unread_events,
            (SELECT COUNT(*) FROM camera_event WHERE started_at >= CURRENT_DATE) AS events_today,
            (SELECT COUNT(*) FROM camera_rule WHERE active) AS active_rules
    ");

    $recentByCamera = $db->getAll("
        SELECT c.name, c.id AS camera_id, COUNT(e.id) AS event_count
        FROM camera c
        LEFT JOIN camera_event e ON e.camera_id = c.id AND e.started_at >= CURRENT_DATE
        WHERE c.active
        GROUP BY c.id, c.name
        ORDER BY event_count DESC
    ");

    $labelStats = $db->getAll("
        SELECT label, COUNT(*) AS count
        FROM camera_event
        WHERE started_at >= CURRENT_DATE
        GROUP BY label
        ORDER BY count DESC
    ");

    resultInfo(true, '', [
        'stats' => $stats,
        'by_camera' => $recentByCamera,
        'by_label' => $labelStats,
    ]);
}

// ============================================================================
// HARDWARE-ERKENNUNG (CPU, GPU, Coral, OpenVINO, Python-Pakete)
// ============================================================================

/**
 * Erkennt die verfuegbare KI-Hardware und installierte Python-Pakete.
 * Wird sowohl vom NVR (Kamera-Monitor) als auch vom ANPR-Service genutzt.
 *
 * @param array $data (keine Parameter noetig)
 * @testdata {}
 */
function getHardwareInfo($data) {
    $info = [
        'cpu' => _detectCpu(),
        'coral' => _detectCoral(),
        'openvino' => _detectOpenVino(),
        'python_packages' => _detectPythonPackages(),
        'ram' => _detectRam(),
        'gpu' => _detectGpu(),
    ];
    resultInfo(true, '', ['hardware' => $info]);
}

/**
 * Python-Paket installieren (openvino, pycoral, tflite-runtime, ultralytics)
 *
 * Nur vordefinierte Pakete erlaubt (kein beliebiges exec).
 *
 * @param array $data['package'] Paketname
 * @param array $data['service'] Fuer welchen Service: 'camera' oder 'anpr'
 * @testdata {"package": "openvino", "service": "camera"}
 */
function installPythonPackage($data) {
    // Whitelist: nur diese Pakete duerfen installiert werden
    $allowed = [
        'openvino',
        'pycoral',
        'tflite-runtime',
        'ultralytics',
    ];

    $package = $data['package'] ?? '';
    $service = $data['service'] ?? 'camera';

    if (!in_array($package, $allowed, true)) {
        resultInfo(false, 'PACKAGE_NOT_ALLOWED', "Paket '$package' ist nicht erlaubt.");
        return;
    }

    // Service-Verzeichnis und venv-Pfade
    $serviceDir = ($service === 'anpr')
        ? realpath(__DIR__ . '/../../services/plate-recognition')
        : realpath(__DIR__ . '/../../services/camera-monitor');
    $venvPip    = "$serviceDir/venv/bin/pip";
    $venvPython = "$serviceDir/venv/bin/python";

    // Venv anlegen falls nicht vorhanden (klappt wenn Verzeichnis beschreibbar ist)
    if (!file_exists($venvPip)) {
        exec("python3 -m venv " . escapeshellarg("$serviceDir/venv") . " 2>&1", $venvOut, $venvExit);
        if ($venvExit === 0 && file_exists($venvPip)) {
            // Venv frisch angelegt — www-data muss schreiben koennen
            exec("chmod -R a+w " . escapeshellarg("$serviceDir/venv/lib") . " "
                . escapeshellarg("$serviceDir/venv/bin") . " 2>/dev/null");
        } else {
            // Kein Schreibrecht auf Verzeichnis — Setup-Befehl anzeigen
            resultInfo(false, 'VENV_MISSING', '', [
                'output' => "Kann Python-Umgebung nicht anlegen:\n$serviceDir/venv\n\nEinmalig als Benutzer ausführen, dann erneut versuchen:",
                'setup_cmd' => "chmod 777 $serviceDir",
            ]);
            return;
        }
    }

    // --- Coral/pycoral: Sonderbehandlung ---
    // PyPI "pycoral 0.2.0" ist ein völlig anderes Paket (kein Google Coral).
    // Echtes pycoral 2.0 kommt von google-coral.github.io/py-repo und
    // benötigt libedgetpu als Systembibliothek — beides lässt sich nicht
    // automatisch per pip als www-data installieren.
    // Stattdessen: Setup-Anleitung zurückgeben.
    if ($package === 'pycoral') {
        $currentUser = trim(shell_exec('whoami 2>/dev/null') ?? 'www-data');
        $venvOwnerUid = fileowner("$serviceDir/venv");
        $venvOwnerInfo = $venvOwnerUid !== false ? posix_getpwuid($venvOwnerUid) : null;
        $venvOwner = $venvOwnerInfo['name'] ?? 'work';
        $libOk = (trim(shell_exec('dpkg -s libedgetpu1-std 2>/dev/null | grep -c "Status: install ok"') ?? '0') > 0);

        // Falsche PyPI-Version deinstallieren falls vorhanden
        exec(escapeshellarg($venvPip) . ' show pycoral 2>/dev/null | grep -c "^Name:"', $showOut);
        $fakeInstalled = (intval($showOut[0] ?? 0) > 0);

        resultInfo(false, 'CORAL_SETUP_REQUIRED', '', [
            'lib_installed'  => $libOk,
            'fake_installed' => $fakeInstalled,
            'venv_owner'     => $venvOwner,
            'venv_pip'       => $venvPip,
            'web_user'       => $currentUser,
        ]);
        return;
    }

    // pip install kann mehrere Minuten dauern (openvino ~500 MB)
    set_time_limit(0);

    // Venv-Besitzer ermitteln: falls www-data kein Schreibrecht hat, per sudo als Besitzer installieren
    $venvOwnerUid = fileowner("$serviceDir/venv");
    $venvOwnerInfo = $venvOwnerUid !== false ? posix_getpwuid($venvOwnerUid) : null;
    $venvOwner = $venvOwnerInfo['name'] ?? null;
    $currentUid = posix_getuid();
    $sudoPassword = $data['sudo_password'] ?? '';

    if ($venvOwner && $venvOwnerUid !== $currentUid) {
        $homeDir = $venvOwnerInfo['dir'] ?? '/tmp';

        // Prüfen ob sudo ohne Passwort möglich (NOPASSWD-Eintrag vorhanden)
        exec('sudo -n -H -u ' . escapeshellarg($venvOwner) . ' true 2>/dev/null', $_, $nopassExit);
        $needsPassword = ($nopassExit !== 0);

        if ($needsPassword && $sudoPassword === '') {
            resultInfo(false, 'SUDO_PASSWORD_REQUIRED', '', ['owner' => $venvOwner]);
            return;
        }

        if ($needsPassword && $sudoPassword !== '') {
            $cmd = 'sudo -S -H -u ' . escapeshellarg($venvOwner)
                . ' HOME=' . escapeshellarg($homeDir)
                . ' ' . escapeshellarg($venvPip)
                . ' install ' . escapeshellarg($package);
            $proc = proc_open($cmd,
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes);
            if (!is_resource($proc)) {
                resultInfo(false, 'INSTALL_FAILED', '', ['output' => 'proc_open fehlgeschlagen']);
                return;
            }
            fwrite($pipes[0], $sudoPassword . "\n");
            fclose($pipes[0]);
            $output   = stream_get_contents($pipes[1]);
            $output  .= stream_get_contents($pipes[2]);
            $exitCode = proc_close($proc);
            unset($sudoPassword);

            if (str_contains($output, 'incorrect password') || str_contains($output, '1 incorrect password')) {
                resultInfo(false, 'SUDO_WRONG_PASSWORD', '');
                return;
            }
            // www-data darf sudo -u {owner} nicht → Setup-Hinweis
            if (str_contains($output, 'is not allowed to execute') || str_contains($output, 'not in the sudoers')) {
                resultInfo(false, 'SUDO_NOT_ALLOWED', '', [
                    'owner' => $venvOwner,
                    'pip'   => $venvPip,
                ]);
                return;
            }
        } else {
            $pipCmd = 'sudo -n -H -u ' . escapeshellarg($venvOwner)
                . ' HOME=' . escapeshellarg($homeDir)
                . ' ' . escapeshellarg($venvPip)
                . ' install ' . escapeshellarg($package) . ' 2>&1';
            exec($pipCmd, $outputLines, $exitCode);
            $output = implode("\n", $outputLines);
        }
    } else {
        $pipCmd = escapeshellarg($venvPip) . ' install ' . escapeshellarg($package) . ' 2>&1';
        exec($pipCmd, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);
    }

    // Import pruefen
    $importName = $package === 'tflite-runtime' ? 'tflite_runtime' : str_replace('-', '_', $package);
    exec(escapeshellarg($venvPython) . ' -c "import ' . $importName . '; print(\'ok\')" 2>&1', $checkLines, $checkExit);
    $success = ($checkExit === 0 && trim(implode('', $checkLines)) === 'ok');

    resultInfo($success, $success ? '' : 'INSTALL_FAILED', [
        'package' => $package,
        'output'  => trim($output),
    ]);
}

/**
 * Frigate- oder go2rtc-Installationsbefehle generieren.
 * Erstellt/liest Webhook-Token, gibt fertige Copy-Paste-Befehle zurueck.
 *
 * @param array $data['client'] Mandant-Code (fuer Webhook-URL)
 * @param array $data['type']   'docker' (Standard) oder 'native' (go2rtc)
 * @testdata {"client": "demo", "type": "native"}
 */
function getFrigateSetupCmds($data) {
    $db = DbhCompany::begin();

    // Token: bestehenden nehmen oder neuen erzeugen und speichern
    $row = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'camera_webhook_token'");
    $token = $row ? $row['value'] : bin2hex(random_bytes(16));
    if (!$row) {
        $db->execute(
            "INSERT INTO defaults_oserp (key, value) VALUES ('camera_webhook_token', :token)",
            [':token' => $token]
        );
    }

    $proto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $client = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['client'] ?? 'MANDANT');
    $webhookUrl = "{$proto}://{$host}/api/camera/webhook.php?token={$token}&client={$client}";

    $type = $data['type'] ?? 'docker';

    if ($type === 'native') {
        // go2rtc: einzelne Binary, kein Docker
        // Webhook-Benachrichtigungen übernimmt der eingebaute camera-monitor (YOLO)
        $serviceDir = realpath(__DIR__ . '/../../services/camera-monitor');
        $cmd = <<<CMD
# go2rtc herunterladen (einmalige Binary, kein Docker)
wget https://github.com/AlexxIT/go2rtc/releases/latest/download/go2rtc_linux_amd64 \\
  -O /usr/local/bin/go2rtc
chmod +x /usr/local/bin/go2rtc

# go2rtc Konfiguration anlegen
mkdir -p /opt/go2rtc
cat > /opt/go2rtc/go2rtc.yaml << 'EOF'
api:
  listen: ":1984"

streams: {}
  # Kameras werden automatisch beim ERP-Scan hinzugefügt
  # lager_eingang: rtsp://admin:admin@192.168.1.100/av0_0
EOF

# Als systemd-Dienst einrichten
cat > /etc/systemd/system/go2rtc.service << 'EOF'
[Unit]
Description=go2rtc stream server
After=network.target

[Service]
ExecStart=/usr/local/bin/go2rtc -config /opt/go2rtc/go2rtc.yaml
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now go2rtc

# ERP camera-monitor-Service aktivieren (übernimmt YOLO-Erkennung + Webhook)
chmod 777 $serviceDir
# → Dann im ERP unter Einstellungen > KI-Hardware auf "Installieren" klicken
CMD;
        $info = "go2rtc läuft nach der Installation unter http://localhost:1984\n"
            . "Stream-URLs für Kameras: http://localhost:1984/api/ws?src=KAMERANAME\n"
            . "Webhook-URL für camera-monitor: $webhookUrl";

    } else {
        // Docker-Variante (Frigate)
        $configYaml = <<<YAML
mqtt:
  enabled: false

detectors:
  default:
    type: cpu

cameras: {}

notifications:
  webhook:
    enabled: true
    url: "$webhookUrl"
YAML;
        $cmd = <<<CMD
mkdir -p /opt/frigate/config /opt/frigate/storage

cat > /opt/frigate/config/config.yml << 'EOF'
$configYaml
EOF

docker run -d \\
  --name frigate \\
  --restart=unless-stopped \\
  -p 5000:5000 -p 8554:8554 -p 8555:8555 \\
  --shm-size=128m \\
  -v /opt/frigate/config:/config \\
  -v /opt/frigate/storage:/media/frigate \\
  ghcr.io/blakeblackshear/frigate:stable

docker logs -f frigate
CMD;
        $info = "Frigate läuft nach der Installation unter http://localhost:5000\nWebhook: $webhookUrl";
    }

    resultInfo(true, '', [
        'cmd'         => $cmd,
        'info'        => $info,
        'token'       => $token,
        'webhook_url' => $webhookUrl,
        'type'        => $type,
    ]);
}

/**
 * Laufende Frigate-Instanz erkennen und URL + Token in der DB speichern.
 * Prueft gaengige Ports (5000, 5001, 8971).
 *
 * @param array $data (keine Parameter noetig)
 * @testdata {}
 */
function detectFrigate($data) {
    $candidates = [
        'http://localhost:5000',
        'http://localhost:5001',
        'http://localhost:8971',
    ];

    $found    = null;
    $version  = '';
    $output   = [];

    foreach ($candidates as $base) {
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $body = @file_get_contents("$base/api/version", false, $ctx);
        if ($body !== false) {
            $json = json_decode($body, true);
            if (isset($json['version'])) {
                $found   = $base;
                $version = $json['version'];
                $output[] = "Frigate v{$version} gefunden unter $base";
                break;
            }
        }
        $output[] = "$base — nicht erreichbar";
    }

    if (!$found) {
        resultInfo(false, 'FRIGATE_NOT_FOUND', '', [
            'output' => implode("\n", $output) . "\n\nFrigate läuft nicht oder ist noch nicht gestartet.",
        ]);
        return;
    }

    // URL in DB speichern
    $db = DbhCompany::begin();
    $db->execute(
        "INSERT INTO defaults_oserp (key, value) VALUES ('camera_frigate_url', :url)
         ON CONFLICT (key) DO UPDATE SET value = :url",
        [':url' => $found]
    );

    resultInfo(true, '', [
        'frigate_url' => $found,
        'version'     => $version,
        'output'      => implode("\n", $output),
    ]);
}

/**
 * CPU-Kernauslastung in Echtzeit ermitteln
 *
 * Liest /proc/stat zweimal im Abstand von 250ms und berechnet
 * daraus die Auslastung pro logischem Kern sowie gesamt.
 *
 * @testdata {}
 */
function getAnprCpuLoad() {
    $s1 = _readProcStat();
    usleep(250000);
    $s2 = _readProcStat();

    $result = [];
    foreach ($s1 as $name => $v1) {
        if (!isset($s2[$name])) continue;
        $v2       = $s2[$name];
        $idle1    = ($v1[3] ?? 0) + ($v1[4] ?? 0); // idle + iowait
        $idle2    = ($v2[3] ?? 0) + ($v2[4] ?? 0);
        $total1   = array_sum($v1);
        $total2   = array_sum($v2);
        $dtotal   = $total2 - $total1;
        $didle    = $idle2  - $idle1;
        $result[$name] = $dtotal > 0 ? max(0, min(100, (int)round(100 * (1 - $didle / $dtotal)))) : 0;
    }

    // Reihenfolge: erst Gesamtwert "cpu", dann cpu0..cpuN aufsteigend
    uksort($result, fn($a, $b) => ($a === 'cpu' ? -1 : ($b === 'cpu' ? 1 : strnatcmp($a, $b))));

    resultInfo(true, '', ['load' => $result]);
}

function _readProcStat(): array {
    $stats = [];
    $lines = @file('/proc/stat');
    if (!$lines) return $stats;
    foreach ($lines as $line) {
        if (!preg_match('/^(cpu\d*)\s+(.+)/', $line, $m)) continue;
        $stats[$m[1]] = array_map('intval', preg_split('/\s+/', trim($m[2])));
    }
    return $stats;
}

// --- Hardware-Erkennung Hilfsfunktionen ---

function _detectCpu(): array {
    $info = ['model' => 'Unbekannt', 'cores' => 0, 'threads' => 0, 'has_intel_gpu' => false];

    // /proc/cpuinfo lesen
    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    if ($cpuinfo) {
        // Modellname
        if (preg_match('/model name\s*:\s*(.+)/i', $cpuinfo, $m)) {
            $info['model'] = trim($m[1]);
        }
        // Kerne zaehlen (physische)
        preg_match_all('/^processor\s*:/m', $cpuinfo, $m);
        $info['threads'] = count($m[0]);
        // Physische Kerne
        if (preg_match('/cpu cores\s*:\s*(\d+)/i', $cpuinfo, $m)) {
            $info['cores'] = (int)$m[1];
        }
        // Intel-Check (fuer iGPU/OpenVINO-Empfehlung)
        $info['has_intel_gpu'] = (bool)preg_match('/Intel/i', $info['model']);
    }

    return $info;
}

function _detectCoral(): array {
    $info = ['connected' => false, 'driver_installed' => false, 'python_package' => false, 'device_name' => ''];

    // lsusb pruefen (Coral USB: 1a6e:089a oder 18d1:9302)
    $lsusb = shell_exec('lsusb 2>/dev/null') ?? '';
    if (preg_match('/1a6e:089a|18d1:9302|Global Unichip|Google.*Coral/i', $lsusb, $m)) {
        $info['connected'] = true;
        // Geraetename extrahieren
        if (preg_match('/.*(?:1a6e:089a|18d1:9302)\s+(.*)/i', $lsusb, $dm)) {
            $info['device_name'] = trim($dm[1]);
        }
    }

    // pycoral Python-Paket pruefen (in beiden venvs)
    foreach (['camera-monitor', 'plate-recognition'] as $service) {
        $python = __DIR__ . "/../../services/$service/venv/bin/python";
        if (file_exists($python)) {
            $check = shell_exec(escapeshellarg($python) . ' -c "from pycoral.utils.edgetpu import list_edge_tpus; print(len(list_edge_tpus()))" 2>/dev/null');
            if ($check !== null && trim($check) !== '' && trim($check) !== '0') {
                $info['python_package'] = true;
                $info['driver_installed'] = true;
                break;
            }
            // Paket vorhanden aber kein Geraet?
            $check2 = shell_exec(escapeshellarg($python) . ' -c "import pycoral; print(1)" 2>/dev/null');
            if (trim($check2 ?? '') === '1') {
                $info['python_package'] = true;
            }
        }
    }

    return $info;
}

function _detectOpenVino(): array {
    $info = ['installed' => false, 'version' => '', 'in_camera_venv' => false, 'in_anpr_venv' => false];

    $venvs = [
        'camera' => __DIR__ . '/../../services/camera-monitor/venv/bin/python',
        'anpr' => __DIR__ . '/../../services/plate-recognition/venv/bin/python',
    ];

    foreach ($venvs as $name => $python) {
        if (!file_exists($python)) continue;
        $version = trim(shell_exec(escapeshellarg($python) . ' -c "import openvino; print(openvino.__version__)" 2>/dev/null') ?? '');
        if ($version && strpos($version, 'Error') === false) {
            $info['installed'] = true;
            $info['version'] = $version;
            $info["in_{$name}_venv"] = true;
        }
    }

    // Fallback: System-Python
    if (!$info['installed']) {
        $version = trim(shell_exec('python3 -c "import openvino; print(openvino.__version__)" 2>/dev/null') ?? '');
        if ($version && strpos($version, 'Error') === false) {
            $info['installed'] = true;
            $info['version'] = $version;
        }
    }

    return $info;
}

function _detectPythonPackages(): array {
    $packages = ['ultralytics', 'pycoral', 'openvino', 'paddleocr', 'cv2'];
    $result = [];

    $venvs = [
        'camera' => __DIR__ . '/../../services/camera-monitor/venv/bin/python',
        'anpr' => __DIR__ . '/../../services/plate-recognition/venv/bin/python',
    ];

    foreach ($venvs as $service => $python) {
        $result[$service] = [];
        if (!file_exists($python)) {
            $result[$service]['_venv_exists'] = false;
            continue;
        }
        $result[$service]['_venv_exists'] = true;

        foreach ($packages as $pkg) {
            $importName = $pkg === 'cv2' ? 'cv2' : str_replace('-', '_', $pkg);
            $check = trim(shell_exec(
                escapeshellarg($python) . " -c \"import $importName; print(getattr($importName, '__version__', 'ok'))\" 2>/dev/null"
            ) ?? '');
            $result[$service][$pkg] = ($check && strpos($check, 'Error') === false) ? $check : false;
        }
    }

    return $result;
}

function _detectRam(): array {
    $info = ['total_gb' => 0, 'available_gb' => 0];
    $meminfo = @file_get_contents('/proc/meminfo');
    if ($meminfo) {
        if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $m)) {
            $info['total_gb'] = round((int)$m[1] / 1048576, 1);
        }
        if (preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $meminfo, $m)) {
            $info['available_gb'] = round((int)$m[1] / 1048576, 1);
        }
    }
    return $info;
}

function _detectGpu(): array {
    $info = ['intel_igpu' => false, 'nvidia' => false, 'device' => ''];

    // Intel iGPU: entweder am Modellnamen erkennbar oder als generische Intel VGA-Karte
    // (lspci zeigt manchmal nur "Device XXXX" wenn die PCI-ID-Datenbank nicht aktuell ist)
    $lspci = shell_exec('lspci 2>/dev/null') ?? '';
    if (preg_match('/VGA[^:]*:.*Intel Corporation[^\n]*/i', $lspci, $m)) {
        $info['intel_igpu'] = true;
        $info['device'] = trim($m[0]);
        // Klartext-Name nachschlagen falls nur Device-ID angezeigt wird
        if (preg_match('/Device\s+[0-9a-f]{4}/i', $info['device'])) {
            $resolved = trim(shell_exec('lspci -nn 2>/dev/null | grep "00:02\.0"') ?? '');
            if ($resolved) $info['device'] = $resolved;
        }
    }

    // NVIDIA
    if (preg_match('/VGA.*NVIDIA[^\n]*/i', $lspci, $m)) {
        $info['nvidia'] = true;
        $info['device'] = trim($m[0]);
    }

    return $info;
}

// ============================================================================
// KAMERA-ERKENNUNG
// ============================================================================

/**
 * ONVIF-Kameras im Netzwerk suchen (ruft Python-Skript auf)
 *
 * @param array $data['test_streams'] Ob RTSP-URLs getestet werden sollen (langsamer)
 * @testdata {}
 */
function discoverCameras($data) {
    $scriptPath = __DIR__ . '/../../services/camera-monitor/discover_cameras.py';
    $pythonBin = __DIR__ . '/../../services/camera-monitor/venv/bin/python';

    // Fallback auf System-Python
    if (!file_exists($pythonBin)) {
        $pythonBin = 'python3';
    }

    if (!file_exists($scriptPath)) {
        resultInfo(false, 'SCRIPT_NOT_FOUND', 'discover_cameras.py nicht gefunden');
        return;
    }

    $args = escapeshellarg($scriptPath);
    if (!empty($data['test_streams'])) {
        $args .= ' --test';
    }

    // Python-Skript ausfuehren und JSON-Output parsen
    $cmd = "$pythonBin $args --json 2>&1";
    $output = shell_exec($cmd);

    // Da das Skript normalerweise Text ausgibt, parsen wir als JSON
    // Fuer die API-Variante: eigenes JSON-Output-Format
    // Wir nutzen stattdessen den eingebauten Discovery direkt
    resultInfo(true, '', ['note' => 'Bitte discover_cameras.py direkt ausfuehren oder die native Discovery-API verwenden']);
}

/**
 * Automatisch alle Kameras im Netzwerk finden und in die DB eintragen.
 * Nutzt ONVIF WS-Discovery; Credentials werden automatisch ermittelt
 * (Hersteller-Datenbank, kein manueller Input noetig).
 *
 * @param array $data (keine Parameter noetig)
 * @testdata {}
 */
function autoDiscoverCameras($data) {
    $scriptPath = __DIR__ . '/../../services/camera-monitor/discover_cameras.py';
    $pythonBin = __DIR__ . '/../../services/camera-monitor/venv/bin/python';

    if (!file_exists($pythonBin)) {
        $pythonBin = 'python3';
    }

    // Pfad zum Service-Verzeichnis (fuer sys.path.insert)
    $serviceDir = realpath(__DIR__ . '/../../services/camera-monitor');

    $pythonCode = <<<PYEOF
import sys, json
sys.path.insert(0, '$serviceDir')
try:
    from discover_cameras import discover_cameras
    cams = discover_cameras(timeout=5, test_streams=False)
    print(json.dumps(cams))
except Exception as e:
    print(json.dumps({'error': str(e)}))
PYEOF;

    $cmd = "$pythonBin -c " . escapeshellarg($pythonCode) . " 2>/dev/null";
    $output = shell_exec($cmd);

    if (!$output) {
        resultInfo(false, 'DISCOVERY_FAILED', 'Kamera-Erkennung fehlgeschlagen. Python-Umgebung prüfen.');
        return;
    }

    $discovered = json_decode(trim($output), true);
    if (isset($discovered['error'])) {
        resultInfo(false, 'DISCOVERY_ERROR', $discovered['error']);
        return;
    }

    if (!is_array($discovered) || empty($discovered)) {
        resultInfo(true, '', ['cameras' => [], 'added' => 0]);
        return;
    }

    // Bestehende Kameras laden (inkl. inaktiver, um Reaktivierung zu ermoeglichen)
    $db = DbhCompany::begin();
    $existing = $db->getAll("SELECT id, frigate_name, stream_url, active FROM camera");
    $existingByName = [];
    foreach ($existing as $row) {
        $existingByName[$row['frigate_name']] = $row;
    }

    $added = 0;
    $updated = 0;
    foreach ($discovered as $cam) {
        // Frigate-Name aus IP generieren (z.B. "cam_192_168_1_100")
        $frigateName = 'cam_' . str_replace('.', '_', $cam['ip']);
        $streamUrl = $cam['rtsp_url'] ?? '';
        $name = $cam['name'] ?: ('Kamera ' . $cam['ip']);
        $location = $cam['location'] ?? '';
        $hardware = $cam['hardware'] ?? '';
        if ($hardware) {
            $location = $location ? "$location ($hardware)" : $hardware;
        }

        if (array_key_exists($frigateName, $existingByName)) {
            $existingCam = $existingByName[$frigateName];
            // Inaktive Kamera wiedergefunden: reaktivieren
            if (!$existingCam['active']) {
                $db->execute(
                    "UPDATE camera SET active = true, name = :name, location = :location, stream_url = COALESCE(NULLIF(:stream_url, ''), stream_url), mtime = NOW() WHERE id = :id",
                    [':id' => $existingCam['id'], ':name' => $name, ':location' => $location, ':stream_url' => $streamUrl]
                );
                $updated++;
            } elseif (empty($existingCam['stream_url']) && $streamUrl !== '') {
                // Aktive Kamera ohne stream_url: URL nachtragen
                $db->execute(
                    "UPDATE camera SET stream_url = :stream_url WHERE id = :id",
                    [':stream_url' => $streamUrl, ':id' => $existingCam['id']]
                );
                $updated++;
            }
            continue;
        }

        $db->execute("
            INSERT INTO camera (name, frigate_name, stream_url, location, sort_order)
            VALUES (:name, :frigate_name, :stream_url, :location, :sort)
        ", [
            ':name' => $name,
            ':frigate_name' => $frigateName,
            ':stream_url' => $streamUrl,
            ':location' => $location,
            ':sort' => $added,
        ]);
        $added++;
    }

    resultInfo(true, '', [
        'cameras' => $discovered,
        'added' => $added,
        'updated' => $updated,
    ]);
}

// ============================================================================
// HILFSFUNKTIONEN
// ============================================================================

/**
 * PostgreSQL-Array-String zu PHP-Array
 */
function _pgArrayToPhp($pgArray): array {
    if (is_array($pgArray)) return $pgArray;
    if (empty($pgArray) || $pgArray === '{}') return [];
    $inner = trim($pgArray, '{}');
    return array_map('trim', explode(',', $inner));
}

/**
 * PHP-Array zu PostgreSQL-Array-String
 */
function _phpArrayToPg(array $arr): string {
    if (empty($arr)) return '{}';
    $escaped = array_map(function($v) {
        return '"' . str_replace('"', '\\"', $v) . '"';
    }, $arr);
    return '{' . implode(',', $escaped) . '}';
}

/**
 * Regeln prüfen und Aktionen auslösen
 */
function _checkRules($db, $cameraId, $cameraName, $label, $zones, $score, $frigateEventId, $snapshotUrl) {
    $now = new DateTime();
    $currentTime = $now->format('H:i:s');
    $currentDow = (int)$now->format('w'); // 0=So, 6=Sa

    // Alle aktiven Regeln laden die passen könnten
    $rules = $db->getAll("
        SELECT * FROM camera_rule
        WHERE active
        AND (camera_id IS NULL OR camera_id = :cam_id)
        AND min_score <= :score
        AND (last_triggered_at IS NULL
             OR last_triggered_at + (cooldown_seconds || ' seconds')::INTERVAL <= NOW())
    ", [
        ':cam_id' => $cameraId,
        ':score' => $score,
    ]);

    foreach ($rules as $rule) {
        // Label prüfen
        $ruleLabels = _pgArrayToPhp($rule['labels']);
        if (!empty($ruleLabels) && !in_array($label, $ruleLabels)) continue;

        // Zone prüfen (wenn Regel eine Zone hat)
        if (!empty($rule['zone_id'])) {
            $zone = $db->getOne(
                "SELECT frigate_zone FROM camera_zone WHERE id = :id",
                [':id' => $rule['zone_id']]
            );
            if ($zone && !in_array($zone['frigate_zone'], $zones)) continue;
        }

        // Zeitfenster prüfen
        if (!empty($rule['time_from']) && !empty($rule['time_to'])) {
            $from = $rule['time_from'];
            $to = $rule['time_to'];
            if ($from <= $to) {
                // Normales Fenster (z.B. 08:00-18:00)
                if ($currentTime < $from || $currentTime > $to) continue;
            } else {
                // Über Mitternacht (z.B. 22:00-06:00)
                if ($currentTime < $from && $currentTime > $to) continue;
            }
        }

        // Wochentag prüfen
        $ruleDays = _pgArrayToPhp($rule['days_of_week']);
        if (!empty($ruleDays) && !in_array((string)$currentDow, $ruleDays)) continue;

        // Regel matched! Aktion ausführen
        _executeRuleAction($db, $rule, $cameraName, $label, $zones, $frigateEventId, $snapshotUrl);

        // Cooldown aktualisieren
        $db->execute(
            "UPDATE camera_rule SET last_triggered_at = NOW() WHERE id = :id",
            [':id' => $rule['id']]
        );
    }
}

/**
 * Regelaktion ausführen (Benachrichtigung, WhatsApp, E-Mail, Log)
 */
function _executeRuleAction($db, $rule, $cameraName, $label, $zones, $frigateEventId, $snapshotUrl) {
    $action = $rule['action'];
    $config = json_decode($rule['action_config'], true) ?: [];
    $zonesStr = implode(', ', $zones);

    $message = $config['message'] ?? "Kamera $cameraName: $label erkannt in Zone $zonesStr";

    switch ($action) {
        case 'notify':
            // pg_notify für Frontend-Push
            $notifyPayload = json_encode([
                'type' => 'camera_alert',
                'rule' => $rule['name'],
                'camera' => $cameraName,
                'label' => $label,
                'zones' => $zones,
                'message' => $message,
                'snapshot_url' => $snapshotUrl,
            ]);
            $db->execute("SELECT pg_notify('camera_event', :payload)", [':payload' => $notifyPayload]);
            break;

        case 'whatsapp':
            // WhatsApp über bestehende API senden
            if (!empty($config['phone'])) {
                _sendWhatsAppAlert($config['phone'], $message, $snapshotUrl);
            }
            break;

        case 'email':
            // E-Mail-Benachrichtigung (placeholder für spätere Implementierung)
            writeLog("Camera Rule '{$rule['name']}': E-Mail an {$config['email']} — $message", true, DLOG_INF);
            break;

        case 'log':
            writeLog("Camera Rule '{$rule['name']}': $message", true, DLOG_INF);
            break;
    }
}

/**
 * WhatsApp-Nachricht senden (nutzt bestehende WhatsApp-Business-API)
 */
function _sendWhatsAppAlert($phone, $message, $snapshotUrl = '') {
    $db = DbhCompany::begin();
    $waConfig = $db->getAll("SELECT key, value FROM defaults_oserp WHERE key IN ('whatsapp_access_token', 'whatsapp_phone_number_id')");
    $wa = [];
    foreach ($waConfig as $row) $wa[$row['key']] = $row['value'];

    if (empty($wa['whatsapp_access_token']) || empty($wa['whatsapp_phone_number_id'])) {
        writeLog("Camera WhatsApp-Alert: Keine WhatsApp-Konfiguration vorhanden", true, DLOG_WRN);
        return;
    }

    $url = "https://graph.facebook.com/v21.0/{$wa['whatsapp_phone_number_id']}/messages";
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => preg_replace('/[^0-9]/', '', $phone),
        'type' => 'text',
        'text' => ['body' => $message],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $wa['whatsapp_access_token'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        writeLog("Camera WhatsApp-Alert Fehler ($httpCode): $result", true, DLOG_ERR);
    }
}
