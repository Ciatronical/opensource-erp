CREATE TABLE cars_lxcars (
    c_id      integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    c_ow      integer NOT NULL,
    c_ln      varchar(10) NOT NULL,
    c_2       varchar(4),
    c_3       varchar(10),
    c_em      varchar(6),
    c_mkb     varchar(20),
    c_t       varchar(5),
    c_d       date,
    c_hu      date,
    c_fin     varchar(30),
    c_st      varchar(30),
    c_wt      varchar(30),
    c_st_l    varchar(30),
    c_wt_l    varchar(30),
    c_it      timestamp DEFAULT now(),
    c_mt      varchar(30),
    c_e_id    varchar(30),
    c_text    text,
    c_m       varchar(5),
    c_color   varchar(30),
    c_gart    varchar(30),
    c_st_z    varchar(30),
    c_wt_z    varchar(30),
    c_km      integer,
    chk_c_ln  boolean DEFAULT true,
    chk_c_2   boolean DEFAULT true,
    chk_c_3   boolean DEFAULT true,
    chk_c_em  boolean DEFAULT true,
    chk_fin   boolean DEFAULT true,
    chk_c_hu  boolean DEFAULT true,
    chk_c_d   boolean DEFAULT true,
    c_sk      boolean DEFAULT false,
    c_zrk     integer,
    c_zrd     date,
    c_bf      date,
    c_wd      date,
    c_finchk  char(1),
    c_pb      boolean DEFAULT false,
    c_hu_notify boolean DEFAULT true,
    kba_id          integer,
    scan_detail_id  text,
    scan_id         text,
    filename        text,
    c_ktype         integer,
    c_ktype_desc    text,
    installed_engines text,
    CONSTRAINT cars_lxcars_c_ln_unique UNIQUE (c_ln),
    CONSTRAINT cars_lxcars_c_fin_unique UNIQUE (c_fin),
    CONSTRAINT cars_lxcars_scan_detail_unique UNIQUE (scan_detail_id),
    CONSTRAINT cars_lxcars_scan_unique UNIQUE (scan_id)
);

CREATE INDEX IF NOT EXISTS idx_cars_lxcars_c_ln ON public.cars_lxcars (c_ln);
CREATE INDEX IF NOT EXISTS idx_cars_lxcars_c_m  ON public.cars_lxcars (c_m);
CREATE INDEX IF NOT EXISTS idx_cars_lxcars_c_ow ON public.cars_lxcars (c_ow);
CREATE INDEX IF NOT EXISTS idx_cars_lxcars_c_t  ON public.cars_lxcars (c_t);

CREATE TABLE IF NOT EXISTS oe_ext (
    oe_id          integer NOT NULL REFERENCES oe(id) ON DELETE CASCADE,
    c_id           integer REFERENCES cars_lxcars(c_id) ON DELETE SET NULL,
    km_stand       integer,
    kfz_ort        text,
    gedruckt       boolean DEFAULT false,
    intern         boolean DEFAULT false,
    bringetermin   timestamp,
    fertigstellung timestamp,
    status         text,
    kennzeichen      text,
    no_whatsapp    boolean DEFAULT false,
    asanetwork_sent_at timestamp,
    CONSTRAINT oe_ext_pkey PRIMARY KEY (oe_id)
);

CREATE INDEX IF NOT EXISTS idx_oe_ext_c_id ON public.oe_ext (c_id);

CREATE TABLE IF NOT EXISTS ar_ext (
    ar_id          integer NOT NULL REFERENCES ar(id) ON DELETE CASCADE,
    c_id           integer REFERENCES cars_lxcars(c_id) ON DELETE SET NULL,
    km_stand       integer,
    fertigstellung date,
    CONSTRAINT ar_ext_pkey PRIMARY KEY (ar_id)
);

CREATE INDEX IF NOT EXISTS idx_ar_ext_c_id ON public.ar_ext (c_id);

CREATE TABLE IF NOT EXISTS ar_defects (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ar_id           INTEGER NOT NULL REFERENCES ar(id) ON DELETE CASCADE,
    defect_code         text NOT NULL,
    defect_description text NOT NULL,
    defect_class       text NOT NULL,
    note                text,
    sort_order          INTEGER DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_ar_defects_ar_id ON public.ar_defects (ar_id);

CREATE TABLE fs_scans_lxcars (
    itime                 TIMESTAMP WITHOUT TIME ZONE DEFAULT ( NOW() AT TIME ZONE 'utc'),
    scan_detail_id         TEXT UNIQUE,
    scan_id             TEXT UNIQUE,
    ez                     TEXT,
    ez_string            TEXT,
    hsn                    TEXT,
    tsn                    TEXT,
    vsn                    TEXT,
    field_2_2            TEXT,
    vin                    TEXT,
    d3                    TEXT,
    registrationnumber    TEXT,
    name1                TEXT,
    name2                TEXT,
    firstname            TEXT,
    address1            TEXT,
    address2            TEXT,
    j                    TEXT,
    field_4                TEXT,
    field_3                TEXT,
    d1                    TEXT,
    d2_1                TEXT,
    d2_2                TEXT,
    d2_3                TEXT,
    d2_4                TEXT,
    field_2                TEXT,
    field_5_1            TEXT,
    field_5_2            TEXT,
    v9                    TEXT,
    field_14            TEXT,
    p3                    TEXT,
    field_10            TEXT,
    field_14_1            TEXT,
    p1                    TEXT,
    l                    TEXT,
    field_9                TEXT,
    p2_p4                TEXT,
    t                    TEXT,
    field_18            TEXT,
    field_19            TEXT,
    field_20            TEXT,
    g                    TEXT,
    field_12            TEXT,
    field_13            TEXT,
    q                    TEXT,
    v7                    TEXT,
    f1                    TEXT,
    f2                    TEXT,
    field_7_1            TEXT,
    field_7_2            TEXT,
    field_7_3            TEXT,
    field_8_1            TEXT,
    field_8_2            TEXT,
    field_8_3            TEXT,
    u1                    TEXT,
    u2                    TEXT,
    u3                    TEXT,
    o1                    TEXT,
    o2                    TEXT,
    s1                    TEXT,
    s2                    TEXT,
    field_15_1            TEXT,
    field_15_2            TEXT,
    field_15_3            TEXT,
    r                    TEXT,
    field_11            TEXT,
    k                    TEXT,
    field_6                TEXT,
    field_17            TEXT,
    field_16            TEXT,
    field_21            TEXT,
    field_22            TEXT,
    hu                    TEXT,
    creation_date        TEXT,
    creation_city        TEXT,
    document_id            TEXT,
    maker                TEXT,
    model                TEXT,
    powerkw                TEXT,
    powerhpkw            TEXT,
    ccm                    TEXT,
    fuel                TEXT,
    fuelcode            TEXT,
    filename            TEXT
);

CREATE TABLE kba_lxcars (
    id                INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    hsn                TEXT NOT NULL CHECK (hsn ~ '^\d{4}$'),
    tsn                TEXT NOT NULL,
    hersteller        TEXT NOT NULL,
    marke            TEXT NOT NULL,
    name            TEXT,
    datum            TEXT,
    klasse            TEXT,
    aufbau            TEXT,
    kraftstoff        TEXT,
    leistung        TEXT,
    hubraum            TEXT,
    achsen            TEXT,
    antrieb            TEXT,
    sitze            TEXT,
    masse            TEXT,
    fhzart            TEXT,
    d3                TEXT,
    j                TEXT,
    field_4            TEXT,
    d1                TEXT,
    d2                TEXT,
    field_2            TEXT,
    field_5            TEXT,
    v9                TEXT,
    field_14        TEXT,
    p3                TEXT,
    field_10        TEXT,
    field_14_1        TEXT,
    p1                TEXT,
    l                TEXT,
    field_9            TEXT,
    p2_p4            TEXT,
    t                TEXT,
    field_18        TEXT,
    field_19        TEXT,
    field_20        TEXT,
    g                TEXT,
    field_12        TEXT,
    field_13        TEXT,
    q                TEXT,
    v7                TEXT,
    f1                TEXT,
    f2                TEXT,
    field_7_1        TEXT,
    field_7_2        TEXT,
    field_7_3        TEXT,
    field_8_1        TEXT,
    field_8_2        TEXT,
    field_8_3        TEXT,
    u1                TEXT,
    u2                TEXT,
    u3                TEXT,
    o1                TEXT,
    o2                TEXT,
    s1                TEXT,
    s2                TEXT,
    field_15_1        TEXT,
    field_15_2        TEXT,
    field_15_3        TEXT,
    k                TEXT,
    field_6            TEXT,
    field_17        TEXT,
    field_21        TEXT,
    CONSTRAINT kba_lxcars_unique UNIQUE (hsn, tsn, d2)
);

CREATE TABLE IF NOT EXISTS special_kba_lxcars (
    id                INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    c_id              INTEGER UNIQUE REFERENCES cars_lxcars(c_id) ON DELETE CASCADE,
    hsn               TEXT NOT NULL,
    tsn               TEXT NOT NULL,
    hersteller        TEXT NOT NULL,
    marke             TEXT NOT NULL,
    name              TEXT,
    datum             TEXT,
    klasse            TEXT,
    aufbau            TEXT,
    kraftstoff        TEXT,
    leistung          TEXT,
    hubraum           TEXT,
    achsen            TEXT,
    antrieb           TEXT,
    sitze             TEXT,
    masse             TEXT,
    fhzart            TEXT,
    d3                TEXT,
    j                 TEXT,
    field_4           TEXT,
    d1                TEXT,
    d2                TEXT,
    field_2           TEXT,
    field_5           TEXT,
    v9                TEXT,
    field_14          TEXT,
    p3                TEXT,
    field_10          TEXT,
    field_14_1        TEXT,
    p1                TEXT,
    l                 TEXT,
    field_9           TEXT,
    p2_p4             TEXT,
    t                 TEXT,
    field_18          TEXT,
    field_19          TEXT,
    field_20          TEXT,
    g                 TEXT,
    field_12          TEXT,
    field_13          TEXT,
    q                 TEXT,
    v7                TEXT,
    f1                TEXT,
    f2                TEXT,
    field_7_1         TEXT,
    field_7_2         TEXT,
    field_7_3         TEXT,
    field_8_1         TEXT,
    field_8_2         TEXT,
    field_8_3         TEXT,
    u1                TEXT,
    u2                TEXT,
    u3                TEXT,
    o1                TEXT,
    o2                TEXT,
    s1                TEXT,
    s2                TEXT,
    field_15_1        TEXT,
    field_15_2        TEXT,
    field_15_3        TEXT,
    k                 TEXT,
    field_6           TEXT,
    field_17          TEXT,
    field_21          TEXT
);

CREATE INDEX IF NOT EXISTS idx_special_kba_lxcars_c_id ON special_kba_lxcars(c_id);
CREATE INDEX IF NOT EXISTS idx_special_kba_lxcars_hsn_tsn ON special_kba_lxcars(hsn, tsn);

-- kba_lxcars ist Stammdaten: neue HSN dürfen nicht durch Anwendungscode angelegt werden.
-- Erlaubt: neue D2-Varianten für bereits bekannte HSN (resolveKbaWithD2 Phase 3).
-- Verboten: HSN-Werte die noch nicht in der Tabelle existieren.
-- Ausnahme: initialer CSV-Import setzt kba.allow_new_hsn = 'on' (SET LOCAL in importCsvToTable).
CREATE OR REPLACE FUNCTION kba_lxcars_protect_hsn()
RETURNS TRIGGER AS $$
BEGIN
    -- HSN-Format: genau 4 Ziffern (DB-Ebene, ergänzt CHECK-Constraint)
    IF NEW.hsn !~ '^\d{4}$' THEN
        RAISE EXCEPTION 'kba_lxcars: Ungültige HSN "%" — nur genau 4 Ziffern erlaubt.', NEW.hsn;
    END IF;
    -- Neue HSN nur während initialem CSV-Import erlaubt (SET LOCAL kba.allow_new_hsn = ''on'' in importCsvToTable)
    IF current_setting('kba.allow_new_hsn', true) IS DISTINCT FROM 'on' THEN
        IF NOT EXISTS (SELECT 1 FROM kba_lxcars WHERE hsn = NEW.hsn) THEN
            RAISE EXCEPTION 'kba_lxcars ist Stammdaten: Neue HSN "%" nicht erlaubt. Bitte HSN und TSN prüfen.', NEW.hsn;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS kba_lxcars_hsn_guard ON kba_lxcars;
CREATE TRIGGER kba_lxcars_hsn_guard
    BEFORE INSERT ON kba_lxcars
    FOR EACH ROW EXECUTE FUNCTION kba_lxcars_protect_hsn();

CREATE TABLE IF NOT EXISTS instructions_lxcars (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    description     TEXT NOT NULL,
    usage_count     INTEGER DEFAULT 1,
    instruction_number TEXT,
    avg_minutes     INTEGER DEFAULT 0,
    completed_count INTEGER DEFAULT 0,
    CONSTRAINT instructions_lxcars_desc_unique UNIQUE (description)
);

CREATE INDEX IF NOT EXISTS idx_instructions_lxcars_desc ON public.instructions_lxcars (description);

CREATE TABLE IF NOT EXISTS oe_instructions_lxcars (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    oe_id           INTEGER NOT NULL REFERENCES oe(id) ON DELETE CASCADE,
    description     TEXT NOT NULL,
    done            BOOLEAN DEFAULT false,
    sort_order      INTEGER DEFAULT 0,
    instruction_number TEXT,
    planned_minutes INTEGER DEFAULT 0,
    actual_minutes  INTEGER DEFAULT 0,
    employee_id     INTEGER
);

CREATE INDEX IF NOT EXISTS idx_oe_instructions_lxcars_oe_id ON public.oe_instructions_lxcars (oe_id);

-- Zeiterfassung: Timer-Spalten
ALTER TABLE oe_instructions_lxcars ADD COLUMN IF NOT EXISTS timer_started_at TIMESTAMP;
ALTER TABLE oe_instructions_lxcars ADD COLUMN IF NOT EXISTS timer_employee_id INTEGER;
ALTER TABLE oe_instructions_lxcars ADD COLUMN IF NOT EXISTS done_at TIMESTAMP;

CREATE INDEX IF NOT EXISTS idx_oe_instructions_lxcars_done_at ON oe_instructions_lxcars (done_at);

-- Migration: done_at fuer bestehende erledigte Anweisungen nachtraeglich setzen
UPDATE oe_instructions_lxcars SET done_at = NOW() WHERE done = true AND done_at IS NULL;

-- Nummernkreis in defaults_oserp initialisieren
INSERT INTO defaults_oserp (key, value) VALUES ('instructionnumber', '100') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('instructionprefix', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('lxcars_order_statuses', 'Angenommen, In Arbeit, Warte auf Teile, Fertig, Abgeholt') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('lxcars_kfz_ort_options', 'Fahrzeug hier, nicht hier, Bestellung, Sonstiges zur Rep gebracht') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('lxcars_hu_vorlauf_monate', '2') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('lxcars_hu_trigger_descriptions', 'Hauptuntersuchung, Nachkontrolle') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('lxcars_default_abgabezeit', '08:00') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('lxcars_default_fertigstellungszeit', '17:00') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('lxcars_time_range', '07:00-18:00') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('lxcars_hu_brief_text', E'Sehr geehrte/r {anrede} {name},\n\nfür folgende Fahrzeuge steht die Hauptuntersuchung (HU) an:\n\n{fahrzeugliste}\n\nWir möchten Sie daran erinnern, rechtzeitig einen Termin für die Hauptuntersuchung zu vereinbaren. Gerne können Sie die HU bei uns in der Werkstatt durchführen lassen.\n\nVereinbaren Sie jetzt Ihren Termin unter der bekannten Telefonnummer oder antworten Sie einfach auf dieses Schreiben.\n\nMit freundlichen Grüßen\n\n{mitarbeiter}') ON CONFLICT (key) DO NOTHING;

-- Trigger: pg_notify bei Anweisungs-Aenderungen (SSE fuer Echtzeit-Updates in Faktura)
-- Nutzt den gleichen Channel wie Faktura, damit der bestehende SSE-Listener greift
CREATE OR REPLACE FUNCTION notify_instruction_change() RETURNS trigger AS $$
BEGIN
    PERFORM pg_notify('faktura_change', json_build_object(
        'action', TG_OP,
        'table', 'oe_instructions_lxcars',
        'id', COALESCE(NEW.oe_id, OLD.oe_id)
    )::TEXT);
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'instructions_faktura_notify') THEN
        CREATE TRIGGER instructions_faktura_notify
            AFTER INSERT OR UPDATE OR DELETE ON oe_instructions_lxcars
            FOR EACH ROW
            EXECUTE FUNCTION notify_instruction_change();
    END IF;
END $$;

-- Trigger: pg_notify bei Maengel-Aenderungen (SSE fuer Echtzeit-Updates in Faktura)
CREATE OR REPLACE FUNCTION notify_defect_change() RETURNS trigger AS $$
DECLARE
    doc_id integer;
BEGIN
    IF TG_TABLE_NAME = 'oe_defects' THEN
        doc_id := COALESCE(NEW.oe_id, OLD.oe_id);
    ELSE
        doc_id := COALESCE(NEW.ar_id, OLD.ar_id);
    END IF;
    PERFORM pg_notify('faktura_change', json_build_object(
        'action', TG_OP,
        'table', TG_TABLE_NAME,
        'id', doc_id
    )::TEXT);
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'oe_defects_faktura_notify') THEN
        CREATE TRIGGER oe_defects_faktura_notify
            AFTER INSERT OR UPDATE OR DELETE ON oe_defects
            FOR EACH ROW
            EXECUTE FUNCTION notify_defect_change();
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'ar_defects_faktura_notify') THEN
        CREATE TRIGGER ar_defects_faktura_notify
            AFTER INSERT OR UPDATE OR DELETE ON ar_defects
            FOR EACH ROW
            EXECUTE FUNCTION notify_defect_change();
    END IF;
END $$;

-- KI-Chat-Verlauf pro Fahrzeug
CREATE TABLE IF NOT EXISTS car_chat_lxcars (
    id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    c_id        INTEGER NOT NULL REFERENCES cars_lxcars(c_id) ON DELETE CASCADE,
    role        TEXT NOT NULL CHECK (role IN ('user', 'assistant')),
    content     TEXT NOT NULL,
    created_at  TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_car_chat_lxcars_c_id ON car_chat_lxcars (c_id);

INSERT INTO defaults_oserp (key, value) VALUES ('lxcars_chat_system_prompt', 'Du bist ein erfahrener KFZ-Werkstattmeister und technischer Berater. Du hilfst Mechanikern bei Diagnosen, Reparaturentscheidungen und technischen Fragen. Antworte praxisnah, konkret und auf Deutsch.') ON CONFLICT (key) DO NOTHING;

-- ============================================================================
-- HU-SERIENBRIEF: Opt-out pro Kunde
-- ============================================================================

ALTER TABLE customer_ext ADD COLUMN IF NOT EXISTS hu_serienbrief_excluded boolean DEFAULT false;

-- ============================================================================
-- TÜV MÄNGELKLASSEN
-- ============================================================================

CREATE TABLE tuev_defect_classes (
    code            text PRIMARY KEY,
    bezeichnung     text NOT NULL,
    plakette        text,
    nachpruefung    text,
    beschreibung    text
);

COMMENT ON TABLE tuev_defect_classes IS 'TÜV-Mängelklassen (OM, GM, EM, VM, VU, HW)';
COMMENT ON COLUMN tuev_defect_classes.code IS 'Mängelklassen-Code (z.B. OM, GM, EM)';
COMMENT ON COLUMN tuev_defect_classes.bezeichnung IS 'Bezeichnung der Mängelklasse';
COMMENT ON COLUMN tuev_defect_classes.plakette IS 'Plakette wird vergeben (True/False)';
COMMENT ON COLUMN tuev_defect_classes.nachpruefung IS 'Nachprüfung erforderlich (True/False)';
COMMENT ON COLUMN tuev_defect_classes.beschreibung IS 'Ausführliche Beschreibung der Mängelklasse';

-- ============================================================================
-- TÜV MÄNGELLISTE
-- ============================================================================

CREATE TABLE tuev_defect_catalog (
    id                  integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    pruefgruppe_nr      text,
    pruefgruppe         text,
    unterpunkt_nr       text,
    unterpunkt          text,
    defect_code         text NOT NULL,
    defect_description text,
    possible_classes   text,
    is_custom           boolean DEFAULT false
);

COMMENT ON TABLE tuev_defect_catalog IS 'TÜV-Mängelliste mit allen prüfbaren Mängelpunkten';
COMMENT ON COLUMN tuev_defect_catalog.pruefgruppe_nr IS 'Nummer der Prüfgruppe (z.B. 0, 1, 2)';
COMMENT ON COLUMN tuev_defect_catalog.pruefgruppe IS 'Name der Prüfgruppe (z.B. Bremsanlage, Lenkanlage)';
COMMENT ON COLUMN tuev_defect_catalog.unterpunkt_nr IS 'Nummer des Unterpunkts (z.B. 1.1, 1.2)';
COMMENT ON COLUMN tuev_defect_catalog.unterpunkt IS 'Name des Unterpunkts';
COMMENT ON COLUMN tuev_defect_catalog.defect_code IS 'Eindeutiger Mangel-Code (z.B. 1.1.1a)';
COMMENT ON COLUMN tuev_defect_catalog.defect_description IS 'Beschreibung des Mangels';
COMMENT ON COLUMN tuev_defect_catalog.possible_classes IS 'Mögliche Mängelklassen, pipe-getrennt (z.B. GM|EM)';
COMMENT ON COLUMN tuev_defect_catalog.is_custom IS 'True für benutzerdefinierte Mängel (nicht aus TÜV-Katalog)';

CREATE INDEX idx_tuev_defect_catalog_defect_code ON tuev_defect_catalog(defect_code);
CREATE INDEX idx_tuev_defect_catalog_pruefgruppe_nr ON tuev_defect_catalog(pruefgruppe_nr);

-- ============================================================================
-- MÄNGEL PRO AUFTRAG
-- ============================================================================

CREATE TABLE IF NOT EXISTS oe_defects (
    id                  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    oe_id               INTEGER NOT NULL REFERENCES oe(id) ON DELETE CASCADE,
    defect_code         text NOT NULL,
    defect_description text NOT NULL,
    defect_class       text NOT NULL,
    note                text,
    sort_order          INTEGER DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_oe_defects_oe_id ON public.oe_defects (oe_id);

COMMENT ON TABLE oe_defects IS 'Erfasste Mängel pro Auftrag (TÜV-Prüfung)';
COMMENT ON COLUMN oe_defects.oe_id IS 'Referenz zum Auftrag';
COMMENT ON COLUMN oe_defects.defect_code IS 'Mangel-Code aus tuev_defect_catalog (z.B. 1.1.1a)';
COMMENT ON COLUMN oe_defects.defect_description IS 'Beschreibung des Mangels (kopiert bei Erfassung)';
COMMENT ON COLUMN oe_defects.defect_class IS 'Zugewiesene Mängelklasse (z.B. GM, EM)';
COMMENT ON COLUMN oe_defects.note IS 'Optionale Notiz zum Mangel';
COMMENT ON COLUMN oe_defects.sort_order IS 'Sortierreihenfolge';

-- ============================================================================
-- MECHANIKER-MODUS: Ersatzteil-Anfragen pro Auftrag
-- ============================================================================

CREATE TABLE IF NOT EXISTS oe_parts_requests_lxcars (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    oe_id           INTEGER NOT NULL REFERENCES oe(id) ON DELETE CASCADE,
    orderitem_id    INTEGER REFERENCES orderitems(id) ON DELETE CASCADE,
    parts_id        INTEGER REFERENCES parts(id) ON DELETE SET NULL,
    partnumber      TEXT,
    description     TEXT NOT NULL,
    qty             NUMERIC(15,5) DEFAULT 1,
    unit            TEXT DEFAULT 'Stck',
    note            TEXT,
    photo           TEXT,
    status          TEXT DEFAULT 'pending',
    requested_by    INTEGER,
    ordered_by      INTEGER,
    vendor_id       INTEGER,
    requested_at    TIMESTAMP DEFAULT now(),
    ordered_at      TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_oe_parts_requests_oe_id ON oe_parts_requests_lxcars (oe_id);
CREATE INDEX IF NOT EXISTS idx_oe_parts_requests_status ON oe_parts_requests_lxcars (status);
CREATE INDEX IF NOT EXISTS idx_oe_parts_requests_orderitem_id ON oe_parts_requests_lxcars (orderitem_id);

-- Migration: orderitem_id hinzufuegen falls Tabelle bereits existiert
ALTER TABLE oe_parts_requests_lxcars ADD COLUMN IF NOT EXISTS orderitem_id INTEGER REFERENCES orderitems(id) ON DELETE CASCADE;

COMMENT ON TABLE oe_parts_requests_lxcars IS 'Bestellstatus-Erweiterung fuer Auftragspositionen (Ersatzteile)';
COMMENT ON COLUMN oe_parts_requests_lxcars.orderitem_id IS 'Verknuepfung zur Position in orderitems';
COMMENT ON COLUMN oe_parts_requests_lxcars.status IS 'pending = muss bestellt werden, ordered = bestellt, received = eingetroffen';
COMMENT ON COLUMN oe_parts_requests_lxcars.photo IS 'Dateiname im Verzeichnis data/parts_requests/{oe_id}/';

-- Trigger: SSE-Benachrichtigung bei Ersatzteil-Anfragen
CREATE OR REPLACE FUNCTION notify_parts_request_change() RETURNS trigger AS $$
BEGIN
    PERFORM pg_notify('faktura_change', json_build_object(
        'action', TG_OP,
        'table', 'oe_parts_requests_lxcars',
        'id', COALESCE(NEW.oe_id, OLD.oe_id)
    )::TEXT);
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'parts_requests_faktura_notify') THEN
        CREATE TRIGGER parts_requests_faktura_notify
            AFTER INSERT OR UPDATE OR DELETE ON oe_parts_requests_lxcars
            FOR EACH ROW
            EXECUTE FUNCTION notify_parts_request_change();
    END IF;
END $$;

-- Zeiterfassung
INSERT INTO defaults_oserp (key, value) VALUES ('lxcars_arbeitsbeginn', '08:00') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('lxcars_arbeitsende', '17:00') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('lxcars_pausen', '09:00-09:30, 12:00-12:30') ON CONFLICT (key) DO NOTHING;

-- Feature-Toggle
INSERT INTO defaults_oserp (key, value) VALUES ('lxcars_mechanic_mode', '0') ON CONFLICT (key) DO NOTHING;

-- ============================================================================
-- ANPR: Automatische Kennzeichenerkennung
-- ============================================================================
-- Inhalt von anpr_schema.sql (inline, da \i ein psql-Meta-Kommando ist)

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
    calibration_gate_height_cm INTEGER DEFAULT 300,
    calibration_gate_top_y     INTEGER,
    calibration_gate_bottom_y  INTEGER,
    ignore_right_pct SMALLINT DEFAULT 0,
    ignore_left_pct  SMALLINT DEFAULT 0,
    direction_required BOOLEAN DEFAULT TRUE,
    direction_filter   TEXT DEFAULT 'approaching',
    grid_size        SMALLINT DEFAULT 10,
    save_snapshots   BOOLEAN DEFAULT TRUE,
    excluded_cells   TEXT DEFAULT '[]',
    min_plate_height_px SMALLINT DEFAULT 0,
    motion_size_pct SMALLINT DEFAULT 20,
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
COMMENT ON COLUMN anpr_cameras_lxcars.gate_height_mode IS 'Toröffnung: full (komplett), vehicle_height (Fahrzeughöhe + Puffer)';
COMMENT ON COLUMN anpr_cameras_lxcars.motion_size_pct IS 'Mindest-Flächenwachstum des Kennzeichens in Prozent um Bewegung zu erkennen (Standard: 20)';
COMMENT ON COLUMN anpr_cameras_lxcars.calibration_gate_height_cm IS 'Reale Torhöhe in cm (Referenz für Fahrzeughöhen-Berechnung)';
COMMENT ON COLUMN anpr_cameras_lxcars.calibration_gate_top_y IS 'Y-Pixel der Toroberkante im Kamerabild';
COMMENT ON COLUMN anpr_cameras_lxcars.calibration_gate_bottom_y IS 'Y-Pixel der Torunterkante im Kamerabild';

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
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'anpr_cameras_actuator_fk') THEN
        ALTER TABLE anpr_cameras_lxcars
            ADD CONSTRAINT anpr_cameras_actuator_fk
            FOREIGN KEY (actuator_id) REFERENCES anpr_actuators_lxcars(id)
            ON DELETE SET NULL;
    END IF;
END $$;

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

-- Health-Protokoll (Heartbeats, Reconnects, Fehler des Python-Dienstes)
CREATE TABLE IF NOT EXISTS anpr_health_lxcars (
    id          bigserial PRIMARY KEY,
    camera_id   integer REFERENCES anpr_cameras_lxcars(id) ON DELETE CASCADE,
    ts          timestamptz NOT NULL DEFAULT now(),
    event       varchar(20) NOT NULL,   -- 'start', 'heartbeat', 'reconnect', 'error'
    message     text,
    frames      integer,
    detections  integer,
    skipped     integer
);
CREATE INDEX IF NOT EXISTS idx_anpr_health_ts ON anpr_health_lxcars (ts DESC);

-- Defaults
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_enabled', '0') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_service_port', '8765') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_service_host', '127.0.0.1') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_show_unknown_vehicles', '1') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_detection_ttl_hours', '8') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_infobar_max', '3') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_blacklist', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('anpr_debug_snapshots', '0') ON CONFLICT (key) DO NOTHING;

-- ============================================================================
-- AUFTRAG FEHLT (Mechanikermodus)
-- ============================================================================
-- Meldungen aus dem Mechanikermodus: ein Fahrzeug wird bearbeitet, zu dem noch
-- kein Auftrag im System existiert. Erscheint als Item in der Info-Bar
-- ("<Kennzeichen> kein Auftrag" bzw. "<Freitext> kein Auftrag"), bis es dort
-- weggeklickt (dismissed) wird. c_id ist optional (Freitext-Meldung ohne Fahrzeug).
CREATE TABLE IF NOT EXISTS missing_orders_lxcars (
    id           INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    c_id         INTEGER REFERENCES cars_lxcars(c_id) ON DELETE SET NULL,
    label        TEXT NOT NULL,          -- Kennzeichen oder Freitext
    created_by   INTEGER,                -- employee.id des meldenden Mechanikers
    dismissed    BOOLEAN DEFAULT false,
    dismissed_by INTEGER,
    itime        TIMESTAMP DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_missing_orders_pending
    ON missing_orders_lxcars(dismissed, itime)
    WHERE dismissed IS NOT TRUE;

-- Trigger: SSE-Benachrichtigung bei neuen Meldungen (Info-Bar aktualisiert live)
CREATE OR REPLACE FUNCTION notify_missing_order() RETURNS trigger AS $$
BEGIN
    PERFORM pg_notify('faktura_change', json_build_object(
        'action', TG_OP,
        'table', 'missing_orders_lxcars',
        'id', NEW.id,
        'label', NEW.label
    )::TEXT);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'missing_order_notify') THEN
        CREATE TRIGGER missing_order_notify
            AFTER INSERT ON missing_orders_lxcars
            FOR EACH ROW
            EXECUTE FUNCTION notify_missing_order();
    END IF;
END $$;
