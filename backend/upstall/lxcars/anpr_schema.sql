-- ============================================================================
-- ANPR: Automatische Kennzeichenerkennung (LxCars)
-- ============================================================================

-- Kameras
CREATE TABLE IF NOT EXISTS anpr_cameras_lxcars (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            TEXT NOT NULL,
    rtsp_url        TEXT NOT NULL,
    enabled         BOOLEAN DEFAULT true,
    direction_mode  TEXT DEFAULT 'size',
    position        TEXT DEFAULT 'front',
    frame_interval  NUMERIC(4,2) DEFAULT 0.5,
    min_confidence  NUMERIC(4,2) DEFAULT 0.60,
    min_detections  INTEGER DEFAULT 3,
    cooldown_minutes INTEGER DEFAULT 5,
    action_type     TEXT DEFAULT 'infobar',
    actuator_id     INTEGER,
    gate_height_mode TEXT DEFAULT 'full',
    ignore_right_pct SMALLINT DEFAULT 0,
    ignore_left_pct  SMALLINT DEFAULT 0,
    direction_required BOOLEAN DEFAULT TRUE,
    grid_size        SMALLINT DEFAULT 10,
    save_snapshots   BOOLEAN DEFAULT TRUE,
    excluded_cells   TEXT DEFAULT '[]',
    min_plate_height_px SMALLINT DEFAULT 0,
    note            TEXT,
    itime           TIMESTAMP DEFAULT now(),
    mtime           TIMESTAMP
);

COMMENT ON TABLE anpr_cameras_lxcars IS 'ANPR-Kameras fuer Werkstattzufahrt';
COMMENT ON COLUMN anpr_cameras_lxcars.direction_mode IS 'Richtungserkennung: size (Bounding-Box-Groesse) oder position (y-Position)';
COMMENT ON COLUMN anpr_cameras_lxcars.position IS 'Kamera-Position: front (frontal), side_left, side_right (seitlich am Tor)';
COMMENT ON COLUMN anpr_cameras_lxcars.frame_interval IS 'Sekunden zwischen Frame-Verarbeitungen';
COMMENT ON COLUMN anpr_cameras_lxcars.min_confidence IS 'Mindest-Confidence fuer gueltige Erkennung (0-1)';
COMMENT ON COLUMN anpr_cameras_lxcars.min_detections IS 'Mindestanzahl Erkennungen bevor gemeldet wird';
COMMENT ON COLUMN anpr_cameras_lxcars.cooldown_minutes IS 'Minuten Cooldown nach letzter Meldung desselben Kennzeichens';
COMMENT ON COLUMN anpr_cameras_lxcars.action_type IS 'Aktion bei Erkennung: infobar, actuator, both';
COMMENT ON COLUMN anpr_cameras_lxcars.actuator_id IS 'Verknuepfter Aktor (z.B. Torantrieb)';
COMMENT ON COLUMN anpr_cameras_lxcars.gate_height_mode IS 'Toroeffnung: full (komplett), vehicle_height (Fahrzeughoehe + Puffer)';

-- Aktoren (Tore, Schranken, etc.)
CREATE TABLE IF NOT EXISTS anpr_actuators_lxcars (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            TEXT NOT NULL,
    type            TEXT NOT NULL DEFAULT 'gate',
    protocol        TEXT NOT NULL DEFAULT 'tcp',
    host            TEXT NOT NULL,
    port            INTEGER NOT NULL DEFAULT 502,
    command_open    TEXT,
    command_close   TEXT,
    command_partial TEXT,
    max_height_cm   INTEGER DEFAULT 300,
    height_buffer_cm INTEGER DEFAULT 30,
    timeout_seconds INTEGER DEFAULT 30,
    enabled         BOOLEAN DEFAULT true,
    note            TEXT,
    itime           TIMESTAMP DEFAULT now(),
    mtime           TIMESTAMP
);

COMMENT ON TABLE anpr_actuators_lxcars IS 'ANPR-Aktoren (Tore, Schranken, Lichter etc.)';
COMMENT ON COLUMN anpr_actuators_lxcars.type IS 'Aktortyp: gate (Tor), barrier (Schranke), light (Ampel/Licht)';
COMMENT ON COLUMN anpr_actuators_lxcars.protocol IS 'Kommunikationsprotokoll: tcp, http, modbus';
COMMENT ON COLUMN anpr_actuators_lxcars.command_open IS 'Befehl zum Oeffnen (Hex/ASCII je nach Protokoll)';
COMMENT ON COLUMN anpr_actuators_lxcars.command_close IS 'Befehl zum Schliessen';
COMMENT ON COLUMN anpr_actuators_lxcars.command_partial IS 'Befehl fuer teilweises Oeffnen (mit Hoehe als Platzhalter {height})';
COMMENT ON COLUMN anpr_actuators_lxcars.max_height_cm IS 'Maximale Oeffnungshoehe in cm';
COMMENT ON COLUMN anpr_actuators_lxcars.height_buffer_cm IS 'Sicherheitspuffer ueber Fahrzeughoehe in cm';
COMMENT ON COLUMN anpr_actuators_lxcars.timeout_seconds IS 'Sekunden bis automatisches Schliessen';

-- FK: Kamera -> Aktor
ALTER TABLE anpr_cameras_lxcars
    ADD CONSTRAINT anpr_cameras_actuator_fk
    FOREIGN KEY (actuator_id) REFERENCES anpr_actuators_lxcars(id)
    ON DELETE SET NULL;

-- Erkennungen
CREATE TABLE IF NOT EXISTS anpr_detections_lxcars (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    camera_id       INTEGER REFERENCES anpr_cameras_lxcars(id) ON DELETE SET NULL,
    c_ln            VARCHAR(15) NOT NULL,
    c_id            INTEGER REFERENCES cars_lxcars(c_id) ON DELETE SET NULL,
    customer_id     INTEGER,
    direction       VARCHAR(3) CHECK (direction IN ('in', 'out')),
    confidence      NUMERIC(4,2),
    vehicle_height_px INTEGER,
    frame_width     INTEGER,
    frame_height    INTEGER,
    action_taken    TEXT,
    dismissed       BOOLEAN DEFAULT false,
    dismissed_by    INTEGER,
    detected_at     TIMESTAMP DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_anpr_detections_pending
    ON anpr_detections_lxcars(dismissed, detected_at)
    WHERE dismissed IS NOT TRUE;
CREATE INDEX IF NOT EXISTS idx_anpr_detections_c_ln
    ON anpr_detections_lxcars(c_ln);
CREATE INDEX IF NOT EXISTS idx_anpr_detections_detected_at
    ON anpr_detections_lxcars(detected_at);

COMMENT ON TABLE anpr_detections_lxcars IS 'Erkannte Kennzeichen an der Werkstattzufahrt';
COMMENT ON COLUMN anpr_detections_lxcars.vehicle_height_px IS 'Gemessene Fahrzeughoehe in Pixeln (fuer Tor-Teiloeffnung)';
COMMENT ON COLUMN anpr_detections_lxcars.frame_width IS 'Breite des Frames bei Erkennung (fuer Hoehen-Berechnung)';
COMMENT ON COLUMN anpr_detections_lxcars.frame_height IS 'Hoehe des Frames bei Erkennung (fuer Hoehen-Berechnung)';
COMMENT ON COLUMN anpr_detections_lxcars.action_taken IS 'Ausgefuehrte Aktion: infobar, gate_open, gate_partial, none';

-- Trigger: SSE-Benachrichtigung bei neuen Erkennungen
CREATE OR REPLACE FUNCTION notify_anpr_detection() RETURNS trigger AS $$
BEGIN
    PERFORM pg_notify('faktura_change', json_build_object(
        'action', TG_OP,
        'table', 'anpr_detections_lxcars',
        'id', NEW.id,
        'c_ln', NEW.c_ln
    )::TEXT);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'anpr_detection_notify') THEN
        CREATE TRIGGER anpr_detection_notify
            AFTER INSERT ON anpr_detections_lxcars
            FOR EACH ROW
            EXECUTE FUNCTION notify_anpr_detection();
    END IF;
END $$;

-- Service-Gesundheitsprotokoll (Heartbeats, Fehler, Reconnects)
CREATE TABLE IF NOT EXISTS anpr_health_lxcars (
    id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    camera_id   INTEGER,
    ts          TIMESTAMP DEFAULT now(),
    event       TEXT NOT NULL,
    message     TEXT,
    frames      INTEGER,
    detections  INTEGER,
    skipped     INTEGER
);
CREATE INDEX IF NOT EXISTS idx_anpr_health_ts ON anpr_health_lxcars(ts DESC);

COMMENT ON TABLE anpr_health_lxcars IS 'ANPR-Service-Gesundheitsprotokoll: Heartbeats, Reconnects, Fehler';
COMMENT ON COLUMN anpr_health_lxcars.event IS 'Ereignistyp: start, heartbeat, reconnect, error';
COMMENT ON COLUMN anpr_health_lxcars.frames IS 'Verarbeitete Frames seit letztem Heartbeat';
COMMENT ON COLUMN anpr_health_lxcars.detections IS 'Gemeldete Erkennungen seit letztem Heartbeat';
COMMENT ON COLUMN anpr_health_lxcars.skipped IS 'Uebersprungene Erkennungen (Cooldown) seit letztem Heartbeat';

-- Defaults
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_enabled', '0') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_service_port', '8765') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_service_host', '127.0.0.1') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_show_unknown_vehicles', '1') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_detection_ttl_hours', '8') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_infobar_max', '3') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_open_order_skip', '0') ON CONFLICT (key) DO NOTHING;
