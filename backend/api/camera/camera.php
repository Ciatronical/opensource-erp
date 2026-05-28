<?php
// backend/api/camera/camera.php

// ── go2rtc-URL aus defaults_oserp laden ──

function _getCameraConfig(): array {
    $db = DbhCompany::begin();
    $rows = $db->getAll("SELECT key, value FROM defaults_oserp WHERE key LIKE 'camera_%'");
    $config = [];
    foreach ($rows as $row) {
        $config[$row['key']] = $row['value'];
    }
    return $config;
}

// ── go2rtc-Sync-Hilfsfunktionen ──

/**
 * Kamera-Stream in go2rtc registrieren (oder aktualisieren).
 * Wird bei saveCamera() aufgerufen.
 */
function _slugify(string $str): string {
    $str = mb_strtolower($str, 'UTF-8');
    $str = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $str);
    $str = preg_replace('/[^a-z0-9]+/', '_', $str);
    return trim($str, '_');
}

define('GO2RTC_YAML', __DIR__ . '/../../../backend/services/go2rtc/go2rtc.yaml');

function _go2rtcYamlSetStream(string $camKey, string $streamUrl): void {
    if (!file_exists(GO2RTC_YAML)) return;
    $yaml = file_get_contents(GO2RTC_YAML);

    $escaped = str_replace('"', '\\"', $streamUrl);
    $line = $camKey . ': "' . $escaped . '"';

    if (preg_match('/^(\s+)' . preg_quote($camKey, '/') . '\s*:/m', $yaml, $m)) {
        $yaml = preg_replace('/^(\s+)' . preg_quote($camKey, '/') . '\s*:.*$/m', $m[1] . $line, $yaml);
    } elseif (preg_match('/^streams\s*:/m', $yaml)) {
        $yaml = preg_replace('/(^streams\s*:)/m', "$1\n  $line", $yaml);
    } else {
        $yaml .= "\nstreams:\n  $line\n";
    }

    file_put_contents(GO2RTC_YAML, $yaml);
}

function _go2rtcYamlRemoveStream(string $camKey): void {
    if (!file_exists(GO2RTC_YAML)) return;
    $yaml = file_get_contents(GO2RTC_YAML);
    $yaml = preg_replace('/^\s+' . preg_quote($camKey, '/') . '\s*:.*\n?/m', '', $yaml);
    file_put_contents(GO2RTC_YAML, $yaml);
}

function _syncGo2rtc(?string $camKey, string $streamUrl): void {
    if (empty($camKey) || empty($streamUrl)) return;

    _go2rtcYamlSetStream($camKey, $streamUrl);

    $db = DbhCompany::begin();
    $row = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'camera_go2rtc_url'");
    $go2rtcUrl = $row ? rtrim($row['value'], '/') : '';
    if (empty($go2rtcUrl)) return;

    $url = $go2rtcUrl . '/api/streams?' . http_build_query(['name' => $camKey, 'src' => $streamUrl]);
    $ctx = stream_context_create(['http' => ['method' => 'PUT', 'timeout' => 5, 'ignore_errors' => true]]);
    @file_get_contents($url, false, $ctx);
}

/**
 * Kamera-Stream aus go2rtc entfernen.
 * Wird bei deleteCamera() aufgerufen.
 */
function _removeGo2rtc(string $camKey): void {
    _go2rtcYamlRemoveStream($camKey);

    $db = DbhCompany::begin();
    $row = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'camera_go2rtc_url'");
    $go2rtcUrl = $row ? rtrim($row['value'], '/') : '';
    if (empty($go2rtcUrl)) return;

    $url = $go2rtcUrl . '/api/streams?' . http_build_query(['name' => $camKey]);
    $ctx = stream_context_create(['http' => ['method' => 'DELETE', 'timeout' => 5, 'ignore_errors' => true]]);
    @file_get_contents($url, false, $ctx);
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
                    'zone_key', z.zone_key,
                    'color', z.color,
                    'coordinates', z.coordinates
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
    // Neue Felder mit Defaults absichern (falls Spalten in älterer DB noch fehlen)
    foreach ($cameras as &$cam) {
        $cam['motion_threshold'] = isset($cam['motion_threshold']) ? (float)$cam['motion_threshold'] : 1.5;
        $cam['min_score']        = isset($cam['min_score'])        ? (float)$cam['min_score']        : 0.45;
        $cam['record_clips']     = isset($cam['record_clips'])     ? (bool)$cam['record_clips']      : true;
        $cam['analysis_fps']     = isset($cam['analysis_fps'])     ? (int)$cam['analysis_fps']       : 2;
        $cam['pre_record_secs']  = isset($cam['pre_record_secs'])  ? (int)$cam['pre_record_secs']    : 3;
        $cam['post_record_secs'] = isset($cam['post_record_secs']) ? (int)$cam['post_record_secs']   : 5;
    }
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
 * @param array $data['cam_key'] Eindeutiger Kamera-Schlüssel (Slug, z.B. lager_eingang)
 * @param array $data['stream_url'] RTSP Stream-URL
 * @param array $data['location'] Standort
 * @param array $data['sort_order'] Sortierreihenfolge
 * @testdata {"name": "Lager Eingang", "cam_key": "lager_eingang", "stream_url": "rtsp://admin:admin@192.168.1.100/av0_0", "location": "Halle 1", "sort_order": 0}
 */
function saveCamera($data) {
    $db = DbhCompany::begin();

    if (empty($data['cam_key']) && !empty($data['name']))
        $data['cam_key'] = _slugify($data['name']);

    // Eindeutigkeit sicherstellen: bei Kollision ID anhängen
    if (!empty($data['cam_key'])) {
        $existingId = $db->getOne(
            "SELECT id FROM camera WHERE cam_key = :key AND id != :id",
            [':key' => $data['cam_key'], ':id' => $data['id'] ?? 0]
        );
        if ($existingId)
            $data['cam_key'] = $data['cam_key'] . '_' . ($data['id'] ?? uniqid());
    }

    $params = [
        ':name'             => $data['name'],
        ':cam_key'          => $data['cam_key'],
        ':stream_url'       => $data['stream_url'] ?? '',
        ':location'         => $data['location'] ?? '',
        ':sort_order'       => $data['sort_order'] ?? 0,
        ':motion_threshold' => $data['motion_threshold'] ?? 1.5,
        ':min_score'        => $data['min_score'] ?? 0.45,
        ':record_clips'     => ($data['record_clips'] ?? true) ? 't' : 'f',
        ':analysis_fps'     => max(1, min(5, (int)($data['analysis_fps'] ?? 2))),
        ':pre_record_secs'  => max(0, min(30, (int)($data['pre_record_secs'] ?? 3))),
        ':post_record_secs' => max(0, min(30, (int)($data['post_record_secs'] ?? 5))),
    ];

    if (!empty($data['id'])) {
        $params[':id'] = $data['id'];
        $db->execute("
            UPDATE camera SET
                name = :name,
                cam_key = :cam_key,
                stream_url = :stream_url,
                location = :location,
                sort_order = :sort_order,
                motion_threshold = :motion_threshold,
                min_score = :min_score,
                record_clips = :record_clips,
                analysis_fps = :analysis_fps,
                pre_record_secs = :pre_record_secs,
                post_record_secs = :post_record_secs,
                mtime = NOW()
            WHERE id = :id
        ", $params);
        _syncGo2rtc($data['cam_key'], $data['stream_url'] ?? '');
        resultInfo(true, '', ['id' => $data['id']]);
    } else {
        $row = $db->getOne("
            INSERT INTO camera (name, cam_key, stream_url, location, sort_order,
                motion_threshold, min_score, record_clips, analysis_fps,
                pre_record_secs, post_record_secs)
            VALUES (:name, :cam_key, :stream_url, :location, :sort_order,
                :motion_threshold, :min_score, :record_clips, :analysis_fps,
                :pre_record_secs, :post_record_secs)
            RETURNING id
        ", $params);
        _syncGo2rtc($data['cam_key'], $data['stream_url'] ?? '');
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
    $cam = $db->getOne("SELECT cam_key FROM camera WHERE id = :id", [':id' => $data['id']]);
    $db->execute("UPDATE camera SET active = false, mtime = NOW() WHERE id = :id", [':id' => $data['id']]);
    if ($cam) _removeGo2rtc($cam['cam_key']);
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
 * @param array $data['zone_key'] Eindeutiger Zonenname (Slug)
 * @param array $data['color'] Farbe (Hex)
 * @testdata {"camera_id": 1, "name": "Wareneingang", "zone_key": "wareneingang", "color": "#FF5722"}
 */
function saveZone($data) {
    $db = DbhCompany::begin();

    $coordinates = isset($data['coordinates']) ? json_encode($data['coordinates']) : null;

    if (!empty($data['id'])) {
        $db->execute("
            UPDATE camera_zone SET
                name = :name,
                zone_key = :zone_key,
                color = :color,
                coordinates = :coordinates::jsonb,
                mtime = NOW()
            WHERE id = :id
        ", [
            ':id'          => $data['id'],
            ':name'        => $data['name'],
            ':zone_key'    => $data['zone_key'],
            ':color'       => $data['color'] ?? '#FF5722',
            ':coordinates' => $coordinates,
        ]);
    } else {
        $db->execute("
            INSERT INTO camera_zone (camera_id, name, zone_key, color, coordinates)
            VALUES (:camera_id, :name, :zone_key, :color, :coordinates::jsonb)
        ", [
            ':camera_id'   => $data['camera_id'],
            ':name'        => $data['name'],
            ':zone_key'    => $data['zone_key'],
            ':color'       => $data['color'] ?? '#FF5722',
            ':coordinates' => $coordinates,
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
 * @param array $data['labels'] Objekttypen als Array (person, car, dog, cat, etc.)
 * @param array $data['time_from'] Zeitfenster von (optional)
 * @param array $data['time_to'] Zeitfenster bis (optional)
 * @param array $data['days_of_week'] Wochentage als Array (0=So, 1=Mo, ..., 6=Sa)
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
// SETUP & KONFIGURATION
// ============================================================================

/**
 * Kamera-Konfiguration laden (go2rtc-URL etc.)
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

/**
 * go2rtc-Installationsbefehle generieren (Copy-Paste-ready).
 *
 * @param array $data (keine Parameter nötig)
 * @testdata {}
 */
function getSetupCmds($data) {
    $serviceDir = realpath(__DIR__ . '/../../services/camera-monitor');

    $cmd = <<<CMD
# go2rtc herunterladen (einmalige Binary, kein Docker)
wget https://github.com/AlexxIT/go2rtc/releases/latest/download/go2rtc_linux_amd64 \
  -O /usr/local/bin/go2rtc
chmod +x /usr/local/bin/go2rtc

# Konfigurationsverzeichnis anlegen
mkdir -p /opt/go2rtc
cat > /opt/go2rtc/go2rtc.yaml << 'EOF'
api:
  listen: ":1984"

streams: {}
  # Kameras werden automatisch vom ERP hinzugefügt wenn du sie speicherst
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

# Camera-Monitor-Service einrichten (YOLO-Objekterkennung)
chmod 777 $serviceDir
# → Dann im ERP unter Einstellungen > KI-Hardware auf "Installieren" klicken
CMD;

    resultInfo(true, '', [
        'cmd' => $cmd,
        'info' => "go2rtc läuft nach der Installation unter http://localhost:1984\n"
                . "Trage diese URL im ERP unter Kamera-Einstellungen > go2rtc-URL ein.\n"
                . "Live-Stream im Browser: http://localhost:1984/stream.html?src=KAMERA_SCHLÜSSEL",
    ]);
}

/**
 * go2rtc-Binary herunterladen, Config anlegen und systemd-Dienst einrichten.
 *
 * Ablauf:
 *   1. Architektur erkennen (amd64 / arm64 / arm)
 *   2. Binary von GitHub herunterladen → backend/services/go2rtc/go2rtc
 *   3. Config anlegen falls noch nicht vorhanden
 *   4. systemd-Service installieren und starten (braucht sudo)
 *   5. go2rtc-URL in DB speichern
 *
 * @param array $data (keine Parameter nötig)
 * @testdata {}
 */
function installGo2rtc($data) {
    $serviceDir = realpath(__DIR__ . '/../../services/go2rtc');
    if (!$serviceDir) {
        mkdir(__DIR__ . '/../../services/go2rtc', 0755, true);
        $serviceDir = realpath(__DIR__ . '/../../services/go2rtc');
    }

    $binPath    = "$serviceDir/go2rtc";
    $configPath = "$serviceDir/go2rtc.yaml";
    $svcTpl     = "$serviceDir/go2rtc.service";
    $svcDest    = '/etc/systemd/system/go2rtc.service';
    $log        = [];

    // 1. Architektur
    $uname  = trim(shell_exec('uname -m 2>/dev/null') ?? 'x86_64');
    $goArch = match ($uname) {
        'aarch64', 'arm64' => 'arm64',
        'armv7l'           => 'arm',
        default            => 'amd64',
    };
    $downloadUrl = "https://github.com/AlexxIT/go2rtc/releases/latest/download/go2rtc_linux_{$goArch}";
    $log[] = "Architektur: $uname → $goArch";
    $log[] = "Download-URL: $downloadUrl";

    // 2. Download (curl → wget Fallback)
    $tmpPath = sys_get_temp_dir() . '/go2rtc_download_' . getmypid();
    exec('curl -fsSL ' . escapeshellarg($downloadUrl) . ' -o ' . escapeshellarg($tmpPath) . ' 2>&1', $out, $exit);
    if ($exit !== 0 || !file_exists($tmpPath) || filesize($tmpPath) < 100000) {
        @unlink($tmpPath);
        exec('wget -q ' . escapeshellarg($downloadUrl) . ' -O ' . escapeshellarg($tmpPath) . ' 2>&1', $out2, $exit2);
        if ($exit2 !== 0 || !file_exists($tmpPath) || filesize($tmpPath) < 100000) {
            @unlink($tmpPath);
            resultInfo(false, 'DOWNLOAD_FAILED', [
                'log'    => implode("\n", $log),
                'cmd'    => "curl -fsSL $downloadUrl\nwget -q $downloadUrl",
                'output' => implode("\n", array_merge($out, $out2 ?? [])),
            ]);
            return;
        }
    }
    $log[] = 'Download: OK (' . round(filesize($tmpPath) / 1048576, 1) . ' MB)';

    // 3. Binary installieren (serviceDir muss beschreibbar sein)
    if (is_writable($serviceDir)) {
        rename($tmpPath, $binPath);
        chmod($binPath, 0755);
        $log[] = "Binary installiert: $binPath";
    } else {
        exec('sudo mv ' . escapeshellarg($tmpPath) . ' ' . escapeshellarg($binPath) . ' && sudo chmod +x ' . escapeshellarg($binPath) . ' 2>&1', $mvOut, $mvExit);
        if ($mvExit !== 0 || !file_exists($binPath)) {
            @unlink($tmpPath);
            resultInfo(false, 'INSTALL_FAILED', [
                'log'       => implode("\n", $log),
                'cmd'       => "sudo mv $tmpPath $binPath && sudo chmod +x $binPath",
                'output'    => implode("\n", $mvOut),
                'setup_cmd' => "chmod 777 $serviceDir",
            ]);
            return;
        }
        $log[] = "Binary installiert (sudo): $binPath";
    }

    // 4. Config anlegen (bestehende nicht überschreiben)
    if (!file_exists($configPath)) {
        file_put_contents($configPath, "api:\n  listen: \":1984\"\n\nstreams: {}\n");
        $log[] = "Config angelegt: $configPath";
    } else {
        $log[] = "Config vorhanden: $configPath";
    }

    // 5. systemd-Service installieren
    $serviceContent = str_replace(
        ['GOPATH', 'CONFIGPATH'],
        [$binPath, $configPath],
        file_get_contents($svcTpl)
    );

    $systemdOk = false;
    $manualCmd = '';

    // Temporäre Service-Datei schreiben und per sudo verschieben
    $tmpSvc = sys_get_temp_dir() . '/go2rtc_' . getmypid() . '.service';
    file_put_contents($tmpSvc, $serviceContent);

    exec(
        'sudo cp ' . escapeshellarg($tmpSvc) . ' ' . escapeshellarg($svcDest)
        . ' && sudo systemctl daemon-reload'
        . ' && sudo systemctl enable --now go2rtc 2>&1',
        $sysOut, $sysExit
    );
    @unlink($tmpSvc);

    if ($sysExit === 0) {
        $systemdOk = true;
        $log[] = 'systemd-Service aktiviert und gestartet.';
    } else {
        $log[] = 'systemd-Setup fehlgeschlagen (sudo nicht verfügbar?). Manuelle Befehle:';
        // Service-Datei ins Service-Dir schreiben damit der User sie kopieren kann
        file_put_contents($svcTpl, $serviceContent);
        $manualCmd = implode("\n", [
            "sudo cp $svcTpl $svcDest",
            "sudo systemctl daemon-reload",
            "sudo systemctl enable --now go2rtc",
        ]);
    }

    // 6. go2rtc-URL in DB speichern und Verbindung prüfen
    $running = false;
    $go2rtcUrl = 'http://localhost:1984';
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
    if (@file_get_contents("$go2rtcUrl/api", false, $ctx) !== false) {
        $running = true;
        $log[] = "go2rtc erreichbar unter $go2rtcUrl";
        $db = DbhCompany::begin();
        $db->execute(
            "INSERT INTO defaults_oserp (key, value) VALUES ('camera_go2rtc_url', :url)
             ON CONFLICT (key) DO UPDATE SET value = :url",
            [':url' => $go2rtcUrl]
        );
    } else {
        $log[] = "go2rtc noch nicht erreichbar — ggf. systemd-Service manuell starten.";
    }

    resultInfo(true, '', [
        'running'      => $running,
        'systemd_ok'   => $systemdOk,
        'bin_path'     => $binPath,
        'config_path'  => $configPath,
        'go2rtc_url'   => $go2rtcUrl,
        'manual_cmd'   => $manualCmd,
        'log'          => implode("\n", $log),
    ]);
}

/**
 * go2rtc-Status abfragen (läuft/nicht erreichbar, Version, aktive Streams).
 *
 * @param array $data (keine Parameter nötig)
 * @testdata {}
 */
function getGo2rtcStatus($data) {
    $serviceDir = realpath(__DIR__ . '/../../services/go2rtc') ?: '';
    $binPath    = $serviceDir ? "$serviceDir/go2rtc" : '';

    $installed = $binPath && file_exists($binPath);
    $version   = '';
    if ($installed) {
        $version = trim(shell_exec(escapeshellarg($binPath) . ' -version 2>&1') ?? '');
    }

    $db = DbhCompany::begin();
    $row = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'camera_go2rtc_url'");
    $go2rtcUrl = $row ? rtrim($row['value'], '/') : 'http://localhost:1984';

    $running = false;
    $streams = [];
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);

    $apiBody = @file_get_contents("$go2rtcUrl/api", false, $ctx);
    if ($apiBody !== false) {
        $running = true;
        $apiData = json_decode($apiBody, true);
        $version = $version ?: ($apiData['version'] ?? '');
    }

    if ($running) {
        $streamsBody = @file_get_contents("$go2rtcUrl/api/streams", false, $ctx);
        if ($streamsBody !== false) {
            $streams = array_keys(json_decode($streamsBody, true) ?: []);
        }
    }

    // systemd-Status
    $systemdActive = '';
    exec('systemctl is-active go2rtc 2>/dev/null', $sOut);
    $systemdActive = trim($sOut[0] ?? '');

    resultInfo(true, '', [
        'installed'      => $installed,
        'running'        => $running,
        'version'        => $version,
        'go2rtc_url'     => $go2rtcUrl,
        'streams'        => $streams,
        'systemd_active' => $systemdActive,
        'bin_path'       => $binPath,
    ]);
}

/**
 * go2rtc-Verbindung prüfen und URL in DB speichern.
 *
 * @param array $data['url'] go2rtc-URL (z.B. http://localhost:1984)
 * @testdata {"url": "http://localhost:1984"}
 */
function detectGo2rtc($data) {
    $candidates = !empty($data['url'])
        ? [rtrim($data['url'], '/')]
        : ['http://localhost:1984', 'http://127.0.0.1:1984'];

    $found = null;
    $version = '';
    $output = [];

    foreach ($candidates as $base) {
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $body = @file_get_contents("$base/api", false, $ctx);
        if ($body !== false) {
            $json = json_decode($body, true);
            if (isset($json['version'])) {
                $found = $base;
                $version = $json['version'];
                $output[] = "go2rtc v{$version} gefunden unter $base";
                break;
            }
            // go2rtc antwortet auch ohne version-Feld auf /api
            if ($body !== '') {
                $found = $base;
                $output[] = "go2rtc gefunden unter $base";
                break;
            }
        }
        $output[] = "$base — nicht erreichbar";
    }

    if (!$found) {
        resultInfo(false, 'GO2RTC_NOT_FOUND', '', [
            'output' => implode("\n", $output) . "\n\ngo2rtc läuft nicht oder ist noch nicht installiert.",
        ]);
        return;
    }

    $db = DbhCompany::begin();
    $db->execute(
        "INSERT INTO defaults_oserp (key, value) VALUES ('camera_go2rtc_url', :url)
         ON CONFLICT (key) DO UPDATE SET value = :url",
        [':url' => $found]
    );

    // Alle aktiven Kameras in go2rtc registrieren
    $cameras = $db->getAll("SELECT cam_key, stream_url FROM camera WHERE active AND stream_url != ''");
    $synced = 0;
    foreach ($cameras as $cam) {
        $putUrl = $found . '/api/streams?' . http_build_query(['name' => $cam['cam_key'], 'src' => $cam['stream_url']]);
        $ctx = stream_context_create(['http' => ['method' => 'PUT', 'timeout' => 5, 'ignore_errors' => true]]);
        @file_get_contents($putUrl, false, $ctx);
        $synced++;
    }

    resultInfo(true, '', [
        'go2rtc_url' => $found,
        'version' => $version,
        'output' => implode("\n", $output),
        'cameras_synced' => $synced,
    ]);
}

// ============================================================================
// HARDWARE-ERKENNUNG
// ============================================================================

/**
 * Erkennt die verfügbare KI-Hardware und installierte Python-Pakete.
 *
 * @param array $data (keine Parameter nötig)
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
 * @param array $data['service'] Für welchen Service: 'camera' oder 'anpr'
 * @testdata {"package": "openvino", "service": "camera"}
 */
function installPythonPackage($data) {
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

    $serviceDir = ($service === 'anpr')
        ? realpath(__DIR__ . '/../../services/plate-recognition')
        : realpath(__DIR__ . '/../../services/camera-monitor');
    $venvPip    = "$serviceDir/venv/bin/pip";
    $venvPython = "$serviceDir/venv/bin/python";

    if (!file_exists($venvPip)) {
        exec("python3 -m venv " . escapeshellarg("$serviceDir/venv") . " 2>&1", $venvOut, $venvExit);
        if ($venvExit === 0 && file_exists($venvPip)) {
            exec("chmod -R a+w " . escapeshellarg("$serviceDir/venv/lib") . " "
                . escapeshellarg("$serviceDir/venv/bin") . " 2>/dev/null");
        } else {
            resultInfo(false, 'VENV_MISSING', [
                'cmd'       => "python3 -m venv $serviceDir/venv",
                'output'    => implode("\n", $venvOut) ?: '(kein Output)',
                'setup_cmd' => "chmod 777 $serviceDir",
            ]);
            return;
        }
    }

    if ($package === 'pycoral') {
        $currentUser = trim(shell_exec('whoami 2>/dev/null') ?? 'www-data');
        $venvOwnerUid = fileowner("$serviceDir/venv");
        $venvOwnerInfo = $venvOwnerUid !== false ? posix_getpwuid($venvOwnerUid) : null;
        $venvOwner = $venvOwnerInfo['name'] ?? 'work';
        $libOk = (trim(shell_exec('dpkg -s libedgetpu1-std 2>/dev/null | grep -c "Status: install ok"') ?? '0') > 0);

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

    set_time_limit(0);

    $venvOwnerUid = fileowner("$serviceDir/venv");
    $venvOwnerInfo = $venvOwnerUid !== false ? posix_getpwuid($venvOwnerUid) : null;
    $venvOwner = $venvOwnerInfo['name'] ?? null;
    $currentUid = posix_getuid();
    $sudoPassword = $data['sudo_password'] ?? '';

    if ($venvOwner && $venvOwnerUid !== $currentUid) {
        $homeDir = $venvOwnerInfo['dir'] ?? '/tmp';

        exec('sudo -n -H -u ' . escapeshellarg($venvOwner) . ' true 2>/dev/null', $_, $nopassExit);
        $needsPassword = ($nopassExit !== 0);

        if ($needsPassword && $sudoPassword === '') {
            resultInfo(false, 'SUDO_PASSWORD_REQUIRED', [
                'cmd'    => "sudo -H -u $venvOwner $venvPip install $package",
                'output' => "Die Python-Umgebung gehört Benutzer '$venvOwner'. Sudo-Passwort erforderlich.",
            ]);
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
                resultInfo(false, 'INSTALL_FAILED', [
                    'cmd'    => $cmd,
                    'output' => 'proc_open fehlgeschlagen',
                ]);
                return;
            }
            fwrite($pipes[0], $sudoPassword . "\n");
            fclose($pipes[0]);
            $output   = stream_get_contents($pipes[1]);
            $output  .= stream_get_contents($pipes[2]);
            $exitCode = proc_close($proc);
            unset($sudoPassword);

            if (str_contains($output, 'incorrect password') || str_contains($output, '1 incorrect password')) {
                resultInfo(false, 'SUDO_WRONG_PASSWORD', [
                    'output' => 'Falsches Passwort.',
                ]);
                return;
            }
            if (str_contains($output, 'is not allowed to execute') || str_contains($output, 'not in the sudoers')) {
                resultInfo(false, 'SUDO_NOT_ALLOWED', [
                    'cmd'    => "sudo -H -u $venvOwner $venvPip install $package",
                    'output' => "Benutzer darf nicht als '$venvOwner' ausführen (nicht in sudoers).",
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

    $importName = $package === 'tflite-runtime' ? 'tflite_runtime' : str_replace('-', '_', $package);
    exec(escapeshellarg($venvPython) . ' -c "import ' . $importName . '; print(\'ok\')" 2>&1', $checkLines, $checkExit);
    $success = ($checkExit === 0 && trim(implode('', $checkLines)) === 'ok');

    $fullOutput = trim($output);
    if (!$success && $checkExit !== 0) {
        $fullOutput .= "\n\n# Import-Test:\n$ python -c \"import $importName\"\n" . implode("\n", $checkLines);
    }

    resultInfo($success, $success ? '' : 'INSTALL_FAILED', [
        'package' => $package,
        'cmd'     => "$venvPip install $package",
        'output'  => $fullOutput,
    ]);
}

// ============================================================================
// KAMERA-ERKENNUNG (ONVIF)
// ============================================================================

/**
 * Automatisch alle Kameras im Netzwerk finden und in die DB eintragen.
 * Nutzt ONVIF WS-Discovery.
 *
 * @param array $data (keine Parameter nötig)
 * @testdata {}
 */
function autoDiscoverCameras($data) {
    $scriptPath = __DIR__ . '/../../services/camera-monitor/discover_cameras.py';
    $pythonBin = __DIR__ . '/../../services/camera-monitor/venv/bin/python';

    if (!file_exists($pythonBin)) {
        $pythonBin = 'python3';
    }

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

    $db = DbhCompany::begin();
    $existing = $db->getAll("SELECT id, cam_key, stream_url, active FROM camera");
    $existingByKey = [];
    foreach ($existing as $row) {
        $existingByKey[$row['cam_key']] = $row;
    }

    $added = 0;
    $updated = 0;
    foreach ($discovered as $cam) {
        $camKey = 'cam_' . str_replace('.', '_', $cam['ip']);
        $streamUrl = $cam['rtsp_url'] ?? '';
        $name = $cam['name'] ?: ('Kamera ' . $cam['ip']);
        $location = $cam['location'] ?? '';
        $hardware = $cam['hardware'] ?? '';
        if ($hardware) {
            $location = $location ? "$location ($hardware)" : $hardware;
        }

        if (array_key_exists($camKey, $existingByKey)) {
            $existingCam = $existingByKey[$camKey];
            if (!$existingCam['active']) {
                $db->execute(
                    "UPDATE camera SET active = true, name = :name, location = :location, stream_url = COALESCE(NULLIF(:stream_url, ''), stream_url), mtime = NOW() WHERE id = :id",
                    [':id' => $existingCam['id'], ':name' => $name, ':location' => $location, ':stream_url' => $streamUrl]
                );
                $updated++;
            } elseif (empty($existingCam['stream_url']) && $streamUrl !== '') {
                $db->execute(
                    "UPDATE camera SET stream_url = :stream_url WHERE id = :id",
                    [':stream_url' => $streamUrl, ':id' => $existingCam['id']]
                );
                $updated++;
            }
            if ($streamUrl) _syncGo2rtc($camKey, $streamUrl);
            continue;
        }

        $db->execute("
            INSERT INTO camera (name, cam_key, stream_url, location, sort_order)
            VALUES (:name, :cam_key, :stream_url, :location, :sort)
        ", [
            ':name' => $name,
            ':cam_key' => $camKey,
            ':stream_url' => $streamUrl,
            ':location' => $location,
            ':sort' => $added,
        ]);
        if ($streamUrl) _syncGo2rtc($camKey, $streamUrl);
        $added++;
    }

    resultInfo(true, '', [
        'cameras' => $discovered,
        'added' => $added,
        'updated' => $updated,
    ]);
}

/**
 * CPU-Kernauslastung in Echtzeit ermitteln
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
        $idle1    = ($v1[3] ?? 0) + ($v1[4] ?? 0);
        $idle2    = ($v2[3] ?? 0) + ($v2[4] ?? 0);
        $total1   = array_sum($v1);
        $total2   = array_sum($v2);
        $dtotal   = $total2 - $total1;
        $didle    = $idle2  - $idle1;
        $result[$name] = $dtotal > 0 ? max(0, min(100, (int)round(100 * (1 - $didle / $dtotal)))) : 0;
    }

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

    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    if ($cpuinfo) {
        if (preg_match('/model name\s*:\s*(.+)/i', $cpuinfo, $m)) {
            $info['model'] = trim($m[1]);
        }
        preg_match_all('/^processor\s*:/m', $cpuinfo, $m);
        $info['threads'] = count($m[0]);
        if (preg_match('/cpu cores\s*:\s*(\d+)/i', $cpuinfo, $m)) {
            $info['cores'] = (int)$m[1];
        }
        $info['has_intel_gpu'] = (bool)preg_match('/Intel/i', $info['model']);
    }

    return $info;
}

function _detectCoral(): array {
    $info = ['connected' => false, 'driver_installed' => false, 'python_package' => false, 'device_name' => ''];

    $lsusb = shell_exec('lsusb 2>/dev/null') ?? '';
    if (preg_match('/1a6e:089a|18d1:9302|Global Unichip|Google.*Coral/i', $lsusb, $m)) {
        $info['connected'] = true;
        if (preg_match('/.*(?:1a6e:089a|18d1:9302)\s+(.*)/i', $lsusb, $dm)) {
            $info['device_name'] = trim($dm[1]);
        }
    }

    // Treiber (libedgetpu) installiert?
    $info['driver_installed'] = (trim(shell_exec('dpkg -s libedgetpu1-std 2>/dev/null | grep -c "Status: install ok"') ?? '0') > 0);

    // pycoral nutzbar? Nur "bereit" wenn list_edge_tpus() tatsaechlich Geraete findet.
    foreach (['camera-monitor', 'plate-recognition'] as $service) {
        $python = __DIR__ . "/../../services/$service/venv/bin/python";
        if (!file_exists($python)) continue;
        $count = trim(shell_exec(escapeshellarg($python) . ' -c "from pycoral.utils.edgetpu import list_edge_tpus; print(len(list_edge_tpus()))" 2>/dev/null') ?? '');
        if ($count !== '' && $count !== '0') {
            $info['python_package'] = true;
            $info['driver_installed'] = true;
            break;
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

    $lspci = shell_exec('lspci 2>/dev/null') ?? '';
    if (preg_match('/VGA[^:]*:.*Intel Corporation[^\n]*/i', $lspci, $m)) {
        $info['intel_igpu'] = true;
        $info['device'] = trim($m[0]);
        if (preg_match('/Device\s+[0-9a-f]{4}/i', $info['device'])) {
            $resolved = trim(shell_exec('lspci -nn 2>/dev/null | grep "00:02\.0"') ?? '');
            if ($resolved) $info['device'] = $resolved;
        }
    }

    if (preg_match('/VGA.*NVIDIA[^\n]*/i', $lspci, $m)) {
        $info['nvidia'] = true;
        $info['device'] = trim($m[0]);
    }

    return $info;
}

// ============================================================================
// HILFSFUNKTIONEN
// ============================================================================

// ============================================================================
// KAMERA-MONITOR-DIENST
// ============================================================================

/**
 * Status des camera-monitor Daemon prüfen (läuft/gestoppt/nicht eingerichtet).
 *
 * @param array $data (keine Parameter)
 * @testdata {}
 */
function getCameraMonitorStatus($data) {
    $serviceDir = realpath(__DIR__ . '/../../services/camera-monitor') ?: '';
    $scriptPath = $serviceDir ? "$serviceDir/camera_monitor.py" : '';
    $venvPython = $serviceDir ? "$serviceDir/venv/bin/python" : '';
    $svcTemplate = $serviceDir ? "$serviceDir/camera-monitor.service" : '';

    $installed = $scriptPath && file_exists($scriptPath) && file_exists($venvPython);

    exec('systemctl is-active camera-monitor 2>/dev/null', $actOut);
    $systemdActive = trim($actOut[0] ?? '');
    $running = ($systemdActive === 'active');

    exec('journalctl -u camera-monitor -n 15 --no-pager --output=short 2>/dev/null', $logLines);
    $logs = implode("\n", $logLines);

    // Manuelle Befehle falls systemd nicht aktiv
    $manualCmd = '';
    $svcDest = '/etc/systemd/system/camera-monitor.service';
    if ($installed && !$running && file_exists($svcTemplate)) {
        $manualCmd = implode("\n", [
            "sudo cp $svcTemplate $svcDest",
            "sudo systemctl daemon-reload",
            "sudo systemctl enable --now camera-monitor",
        ]);
    }

    resultInfo(true, '', [
        'installed'      => $installed,
        'running'        => $running,
        'systemd_active' => $systemdActive,
        'service_dir'    => $serviceDir,
        'logs'           => $logs,
        'manual_cmd'     => $manualCmd,
    ]);
}

/**
 * camera-monitor als systemd-Dienst einrichten und starten.
 *
 * Erstellt die Service-Datei anhand des Vorlagen-Templates, installiert sie
 * per sudo und startet den Dienst. Gibt manuelle Befehle zurück wenn sudo
 * nicht verfügbar ist.
 *
 * @param array $data (keine Parameter)
 * @testdata {}
 */
function installCameraMonitor($data) {
    $serviceDir = realpath(__DIR__ . '/../../services/camera-monitor');
    if (!$serviceDir) {
        resultInfo(false, 'SERVICE_DIR_NOT_FOUND', 'camera-monitor Verzeichnis nicht gefunden.');
        return;
    }

    $scriptPath = "$serviceDir/camera_monitor.py";
    $venvPython = "$serviceDir/venv/bin/python";
    $svcTemplate = "$serviceDir/camera-monitor.service";
    $svcDest = '/etc/systemd/system/camera-monitor.service';

    if (!file_exists($scriptPath)) {
        resultInfo(false, 'SCRIPT_NOT_FOUND', "camera_monitor.py nicht gefunden: $scriptPath");
        return;
    }
    if (!file_exists($venvPython)) {
        resultInfo(false, 'VENV_NOT_FOUND', "Python-Umgebung nicht gefunden: $venvPython");
        return;
    }

    // Service-User = Eigentümer des venv
    $venvOwner = 'www-data';
    $venvStat = @stat("$serviceDir/venv");
    if ($venvStat) {
        $pwInfo = @posix_getpwuid($venvStat['uid']);
        if ($pwInfo) $venvOwner = $pwInfo['name'];
    }

    // Service-Datei generieren
    $serviceContent = "[Unit]\nDescription=OpensourceERP Kamera-Monitor (Objekterkennung)\nAfter=network.target go2rtc.service\n\n[Service]\nExecStart=$venvPython $scriptPath\nWorkingDirectory=$serviceDir\nRestart=always\nRestartSec=10\nUser=$venvOwner\nStandardOutput=journal\nStandardError=journal\n\n[Install]\nWantedBy=multi-user.target\n";
    file_put_contents($svcTemplate, $serviceContent);

    $log = [];
    $log[] = "Service-User: $venvOwner";
    $log[] = "Script: $scriptPath";
    $log[] = "Python: $venvPython";

    // Per sudo installieren
    $tmpSvc = sys_get_temp_dir() . '/camera_monitor_' . getmypid() . '.service';
    file_put_contents($tmpSvc, $serviceContent);

    exec(
        'sudo cp ' . escapeshellarg($tmpSvc) . ' ' . escapeshellarg($svcDest)
        . ' && sudo systemctl daemon-reload'
        . ' && sudo systemctl enable --now camera-monitor 2>&1',
        $sysOut, $sysExit
    );
    @unlink($tmpSvc);

    $systemdOk = false;
    $manualCmd = '';

    if ($sysExit === 0) {
        $systemdOk = true;
        $log[] = 'systemd-Service aktiviert und gestartet.';
    } else {
        $log[] = 'systemd-Setup fehlgeschlagen (sudo benötigt). Manuelle Befehle:';
        $manualCmd = implode("\n", [
            "sudo cp $svcTemplate $svcDest",
            "sudo systemctl daemon-reload",
            "sudo systemctl enable --now camera-monitor",
        ]);
    }

    exec('systemctl is-active camera-monitor 2>/dev/null', $actOut);
    $running = (trim($actOut[0] ?? '') === 'active');

    resultInfo(true, '', [
        'running'      => $running,
        'systemd_ok'   => $systemdOk,
        'service_user' => $venvOwner,
        'manual_cmd'   => $manualCmd,
        'log'          => implode("\n", $log),
    ]);
}

/**
 * camera-monitor Dienst neu starten.
 *
 * Benötigt sudo-Berechtigung — siehe backend/services/oserp-camera-sudoers
 *
 * @param array $data (keine Parameter)
 * @testdata {}
 */
function restartCameraMonitor($data) {
    exec('sudo systemctl restart camera-monitor 2>&1', $out, $exit);
    if ($exit === 0) {
        resultInfo(true, '', ['output' => 'camera-monitor neu gestartet.']);
    } else {
        resultInfo(false, 'RESTART_FAILED', implode("\n", $out));
    }
}

/**
 * Feature-Dienst stoppen oder starten.
 *
 * Wird aufgerufen wenn feature_anpr oder feature_nvr in der Config umgeschaltet wird.
 * Beim Deaktivieren werden die zugehörigen systemd-Dienste gestoppt.
 * Beim Aktivieren wird versucht, die Dienste zu starten.
 *
 * Benötigt sudo-Berechtigung — siehe backend/services/oserp-camera-sudoers
 *
 * @param string $data['feature']  'anpr' oder 'nvr'
 * @param bool   $data['enabled']  true = starten, false = stoppen
 * @testdata {"feature": "anpr", "enabled": false}
 */
function setFeatureService($data) {
    $feature = $data['feature'] ?? '';
    $enabled = filter_var($data['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);

    $serviceMap = [
        'anpr' => ['anpr.service'],
        'nvr'  => ['go2rtc.service', 'camera-monitor.service'],
    ];

    if (!isset($serviceMap[$feature])) {
        resultInfo(false, 'UNKNOWN_FEATURE', 'Unbekanntes Feature: ' . $feature);
        return;
    }

    $services = $serviceMap[$feature];
    $action   = $enabled ? 'start' : 'stop';
    $results  = [];
    $hasError = false;

    foreach ($services as $svc) {
        exec("sudo systemctl $action " . escapeshellarg($svc) . " 2>&1", $out, $exit);
        $results[$svc] = ['exit' => $exit, 'output' => implode("\n", $out)];
        if ($exit !== 0) $hasError = true;
        $out = [];
    }

    // Status der Dienste nach der Aktion ermitteln
    $statuses = [];
    foreach ($services as $svc) {
        $status = trim(shell_exec("systemctl is-active " . escapeshellarg($svc) . " 2>/dev/null") ?? 'unknown');
        $statuses[$svc] = $status;
    }

    if ($hasError && !$enabled) {
        // Beim Stoppen: Fehler nur melden wenn Dienst noch aktiv ist
        $stillActive = array_filter($statuses, fn($s) => $s === 'active');
        if (!empty($stillActive)) {
            $failedServices = array_keys($stillActive);
            $manualCmds = array_map(fn($s) => "sudo systemctl stop $s", $failedServices);

            // Fehlermeldung pro Dienst zusammenbauen
            $details = [];
            foreach ($failedServices as $svc) {
                $out = trim($results[$svc]['output'] ?? '');
                $details[] = ($out ?: "systemctl stop $svc fehlgeschlagen (Exit " . ($results[$svc]['exit'] ?? '?') . ")");
            }

            resultInfo(false, 'STOP_FAILED', [
                'message'         => implode("\n", $details),
                'failed_services' => $failedServices,
                'statuses'        => $statuses,
                'manual_commands' => $manualCmds,
                'results'         => $results,
            ]);
            return;
        }
    }

    resultInfo(true, '', [
        'feature'  => $feature,
        'enabled'  => $enabled,
        'results'  => $results,
        'statuses' => $statuses,
    ]);
}

function _pgArrayToPhp($pgArray): array {
    if (is_array($pgArray)) return $pgArray;
    if (empty($pgArray) || $pgArray === '{}') return [];
    $inner = trim($pgArray, '{}');
    return array_map('trim', explode(',', $inner));
}

function _phpArrayToPg(array $arr): string {
    if (empty($arr)) return '{}';
    $escaped = array_map(function($v) {
        return '"' . str_replace('"', '\\"', $v) . '"';
    }, $arr);
    return '{' . implode(',', $escaped) . '}';
}

function _checkRules($db, $cameraId, $cameraName, $label, $zones, $score, $eventId, $snapshotUrl) {
    $now = new DateTime();
    $currentTime = $now->format('H:i:s');
    $currentDow = (int)$now->format('w');

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
        $ruleLabels = _pgArrayToPhp($rule['labels']);
        if (!empty($ruleLabels) && !in_array($label, $ruleLabels)) continue;

        if (!empty($rule['zone_id'])) {
            $zone = $db->getOne(
                "SELECT zone_key FROM camera_zone WHERE id = :id",
                [':id' => $rule['zone_id']]
            );
            if ($zone && !in_array($zone['zone_key'], $zones)) continue;
        }

        if (!empty($rule['time_from']) && !empty($rule['time_to'])) {
            $from = $rule['time_from'];
            $to = $rule['time_to'];
            if ($from <= $to) {
                if ($currentTime < $from || $currentTime > $to) continue;
            } else {
                if ($currentTime < $from && $currentTime > $to) continue;
            }
        }

        $ruleDays = _pgArrayToPhp($rule['days_of_week']);
        if (!empty($ruleDays) && !in_array((string)$currentDow, $ruleDays)) continue;

        _executeRuleAction($db, $rule, $cameraName, $label, $zones, $eventId, $snapshotUrl);

        $db->execute(
            "UPDATE camera_rule SET last_triggered_at = NOW() WHERE id = :id",
            [':id' => $rule['id']]
        );
    }
}

function _executeRuleAction($db, $rule, $cameraName, $label, $zones, $eventId, $snapshotUrl) {
    $action = $rule['action'];
    $config = json_decode($rule['action_config'], true) ?: [];
    $zonesStr = implode(', ', $zones);

    $message = $config['message'] ?? "Kamera $cameraName: $label erkannt in Zone $zonesStr";

    switch ($action) {
        case 'notify':
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
            if (!empty($config['phone'])) {
                _sendWhatsAppAlert($config['phone'], $message, $snapshotUrl);
            }
            break;

        case 'email':
            writeLog("Camera Rule '{$rule['name']}': E-Mail an {$config['email']} — $message", true, DLOG_INF);
            break;

        case 'log':
            writeLog("Camera Rule '{$rule['name']}': $message", true, DLOG_INF);
            break;
    }
}

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
