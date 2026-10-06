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
    deleted_at            TIMESTAMP WITHOUT TIME ZONE,
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

-- Trigger: pg_notify bei neuem Fahrzeugschein-Scan (SSE -> Scanliste live)
-- Nutzt den gleichen Channel wie Faktura, damit der bestehende SSE-Listener greift
CREATE OR REPLACE FUNCTION notify_fs_scan() RETURNS trigger AS $$
BEGIN
    PERFORM pg_notify('faktura_change', json_build_object(
        'action', TG_OP,
        'table', 'fs_scans_lxcars',
        'id', NEW.scan_id
    )::TEXT);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'fs_scans_notify') THEN
        CREATE TRIGGER fs_scans_notify
            AFTER INSERT ON fs_scans_lxcars
            FOR EACH ROW
            EXECUTE FUNCTION notify_fs_scan();
    END IF;
END $$;

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

-- ============================================================================
-- SPEZIALWERKZEUG
-- ============================================================================
-- Spezialwerkzeuge (Absteckwerkzeug, Injektor-Auszieher, ...) werden eingelagert
-- und per KI den passenden Fahrzeugen zugeordnet. Die Zuordnung besteht aus
-- Regeln (special_tool_rules_lxcars): jede Regel beschreibt in `criteria` ein
-- Fahrzeugprofil als JSON — Hersteller/HSN, Modelle, Motorkennbuchstaben,
-- Kraftstoff, Hubraum-, Leistungs- und Baujahrbereich. Innerhalb einer Regel
-- gelten alle gesetzten Kriterien zugleich (UND), Listen sind Alternativen
-- (ODER). Mehrere Regeln je Werkzeug sind Alternativen.
--
-- Der Mensch behält das letzte Wort: Regeln lassen sich ändern, abschalten
-- oder löschen, und einzelne Fahrzeuge werden in special_tool_vehicles_lxcars
-- fest zugeordnet (include) oder ausgeschlossen (exclude).

-- KBA-Schlüssel der Kraftstoffart → lesbarer Name und Motorgruppe. Hybride und
-- Gasfahrzeuge zählen zur Gruppe ihres Verbrennungsmotors, denn darauf kommt
-- es beim Werkzeug an. Daten: company_data/kba_fuel_codes_lxcars.csv
CREATE TABLE IF NOT EXISTS kba_fuel_codes_lxcars (
    code        text PRIMARY KEY,
    name        text NOT NULL,
    fuel_group  text NOT NULL
);
COMMENT ON TABLE kba_fuel_codes_lxcars IS 'KBA-Kraftstoffschlüssel (Feld P.3) mit Motorgruppe für die Werkzeugzuordnung';

CREATE TABLE IF NOT EXISTS special_tools_lxcars (
    id              SERIAL PRIMARY KEY,
    name            text NOT NULL,
    tool_number     text,                       -- Inventar-/Werkzeugnummer
    manufacturer    text,
    category        text,                       -- z. B. Zahnriemen, Injektoren, Steuerkette
    description     text,
    location        text,                       -- Lagerort als Freitext (Schrank, Schublade ...)
    bin_id          integer REFERENCES bin(id) ON DELETE SET NULL,
    parts_id        integer REFERENCES parts(id) ON DELETE SET NULL,          -- Verkaufsartikel im Shop
    rental_parts_id integer REFERENCES parts(id) ON DELETE SET NULL,          -- Mietartikel (Dienstleistung) im Shop
    purchase_price  numeric(15,2),              -- Einkaufspreis des Werkzeugs
    sale_price      numeric(15,2),              -- Verkaufspreis im Shop (Vorgabe: Einkaufspreis)
    rental_price    numeric(15,2),              -- Miete je Mietvorgang (Vorgabe: ein Drittel des Einkaufspreises)
    rental_days     integer NOT NULL DEFAULT 7, -- Mietdauer in Tagen, die die Miete abdeckt
    shop_sell       boolean NOT NULL DEFAULT false,  -- im HugoShop zum Kauf anbieten
    shop_rent       boolean NOT NULL DEFAULT false,  -- im HugoShop zum Verleih anbieten
    status          text NOT NULL DEFAULT 'available',   -- available | lent | defective
    lent_to         text,
    ai_hint         text,                       -- Hinweis des Benutzers an die KI
    ai_summary      text,                       -- Einschätzung der KI zur Zuordnung
    ai_model        text,
    ai_analyzed_at  timestamp,
    created_by      integer,
    created_at      timestamp NOT NULL DEFAULT now(),
    updated_at      timestamp NOT NULL DEFAULT now()
);
COMMENT ON TABLE special_tools_lxcars IS 'Spezialwerkzeuge der Werkstatt mit Lagerort und KI-Einschätzung';

CREATE TABLE IF NOT EXISTS special_tool_rules_lxcars (
    id              SERIAL PRIMARY KEY,
    tool_id         integer NOT NULL REFERENCES special_tools_lxcars(id) ON DELETE CASCADE,
    label           text NOT NULL,              -- z. B. "VW-Konzern 1.9/2.0 TDI Pumpe-Düse"
    criteria        jsonb NOT NULL DEFAULT '{}'::jsonb,
    reason          text,                       -- Begründung (KI oder Mensch)
    mode            text NOT NULL DEFAULT 'include', -- include | exclude (zieht Treffer wieder ab)
    source          text NOT NULL DEFAULT 'ai', -- ai | manual
    confidence      numeric(3,2),
    active          boolean NOT NULL DEFAULT true,
    sort_order      integer NOT NULL DEFAULT 0,
    created_at      timestamp NOT NULL DEFAULT now(),
    updated_at      timestamp NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_special_tool_rules_lxcars_tool_id ON special_tool_rules_lxcars(tool_id);
COMMENT ON TABLE special_tool_rules_lxcars IS 'Zuordnungsregeln eines Spezialwerkzeugs (Fahrzeugprofil als JSON)';
COMMENT ON COLUMN special_tool_rules_lxcars.criteria IS 'JSON: makes[], hsn[], models[], engine_codes[], fuel[], vehicle_types[], ccm_from, ccm_to, kw_from, kw_to, year_from, year_to';

CREATE TABLE IF NOT EXISTS special_tool_vehicles_lxcars (
    id          SERIAL PRIMARY KEY,
    tool_id     integer NOT NULL REFERENCES special_tools_lxcars(id) ON DELETE CASCADE,
    c_id        integer NOT NULL REFERENCES cars_lxcars(c_id) ON DELETE CASCADE,
    mode        text NOT NULL DEFAULT 'include',   -- include | exclude
    note        text,
    created_by  integer,
    created_at  timestamp NOT NULL DEFAULT now(),
    CONSTRAINT special_tool_vehicles_lxcars_tool_car_unique UNIQUE (tool_id, c_id)
);
CREATE INDEX IF NOT EXISTS idx_special_tool_vehicles_lxcars_c_id ON special_tool_vehicles_lxcars(c_id);
COMMENT ON TABLE special_tool_vehicles_lxcars IS 'Manuelle Fahrzeugzuordnung je Werkzeug: fest zugeordnet oder ausgeschlossen';

-- Fahrzeugprofil für die Regelprüfung: Fahrzeug- und KBA-Daten normalisiert
-- (Motorcode ohne Leer- und Sonderzeichen, Kraftstoff als Gruppe, Hubraum/kW/
-- Baujahr als Zahl). Eine echte Tabelle, die ein Trigger auf cars_lxcars
-- aktuell hält — so kostet die Regelprüfung keine Neuberechnung je Aufruf.
CREATE TABLE IF NOT EXISTS special_tool_vehicle_profiles_lxcars (
    c_id             integer PRIMARY KEY REFERENCES cars_lxcars(c_id) ON DELETE CASCADE,
    c_ln             text,
    c_ow             integer,
    hsn              text,
    tsn              text,
    make             text,
    model            text,
    make_model_text  text,
    engine_code      text,
    engine_code_norm text,
    fuel             text,
    fuel_name        text,
    vehicle_type     text,
    ccm              integer,
    kw               integer,
    year             integer,
    first_reg        date,
    updated_at       timestamp NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_special_tool_vehicle_profiles_lxcars_fuel ON special_tool_vehicle_profiles_lxcars(fuel);
CREATE INDEX IF NOT EXISTS idx_special_tool_vehicle_profiles_lxcars_year ON special_tool_vehicle_profiles_lxcars(year);
COMMENT ON TABLE special_tool_vehicle_profiles_lxcars IS 'Normalisiertes Fahrzeugprofil für die Spezialwerkzeug-Zuordnung (per Trigger aus cars_lxcars + kba_lxcars)';

-- Treffer Werkzeug ↔ Fahrzeug als Cache. Wird je Werkzeug neu berechnet, wenn
-- sich Regeln oder Zuordnungen ändern, und je Fahrzeug, wenn sich das
-- Fahrzeug ändert. Lesen (Werkzeugliste, Fahrzeugkarte) kostet damit nichts.
CREATE TABLE IF NOT EXISTS special_tool_vehicle_matches_lxcars (
    tool_id     integer NOT NULL REFERENCES special_tools_lxcars(id) ON DELETE CASCADE,
    c_id        integer NOT NULL REFERENCES cars_lxcars(c_id) ON DELETE CASCADE,
    rule_id     integer REFERENCES special_tool_rules_lxcars(id) ON DELETE CASCADE,
    source      text NOT NULL,              -- rule | manual
    note        text
);
CREATE INDEX IF NOT EXISTS idx_special_tool_vehicle_matches_lxcars_tool_id ON special_tool_vehicle_matches_lxcars(tool_id);
CREATE INDEX IF NOT EXISTS idx_special_tool_vehicle_matches_lxcars_c_id ON special_tool_vehicle_matches_lxcars(c_id);
COMMENT ON TABLE special_tool_vehicle_matches_lxcars IS 'Cache der Spezialwerkzeug-Treffer je Fahrzeug (siehe special_tool_refresh_matches_lxcars)';

-- Profile aufbauen bzw. auffrischen — für ein Fahrzeug oder (NULL) für alle
CREATE OR REPLACE FUNCTION special_tool_build_profiles_lxcars(p_c_id integer DEFAULT NULL)
RETURNS integer
LANGUAGE sql VOLATILE AS $$
    WITH src AS (
        SELECT
            c.c_id,
            c.c_ln::text                                                                     AS c_ln,
            c.c_ow,
            NULLIF(trim(c.c_2), '')::text                                                    AS hsn,
            NULLIF(trim(c.c_3), '')::text                                                    AS tsn,
            COALESCE(NULLIF(trim(k.marke), ''), NULLIF(trim(k.hersteller), ''), NULLIF(trim(c.c_m), ''))::text AS make,
            COALESCE(NULLIF(trim(k.d3), ''), NULLIF(trim(k.name), ''), NULLIF(trim(c.c_mt), ''))::text         AS model,
            concat_ws(' ', k.hersteller, k.marke, k.name, k.d3, c.c_m, c.c_mt)::text         AS make_model_text,
            NULLIF(trim(c.c_mkb), '')::text                                                  AS engine_code,
            upper(regexp_replace(COALESCE(c.c_mkb, ''), '[^A-Za-z0-9]', '', 'g'))::text      AS engine_code_norm,
            COALESCE(
                f.fuel_group,
                CASE
                    WHEN k.kraftstoff ILIKE '%diesel%'                                  THEN 'Diesel'
                    WHEN k.kraftstoff ILIKE '%benzin%' OR k.kraftstoff ILIKE '%otto%'  THEN 'Benzin'
                    WHEN k.kraftstoff ILIKE '%elektro%'                                 THEN 'Elektro'
                    WHEN NULLIF(trim(k.kraftstoff), '') IS NULL                         THEN NULL
                    ELSE 'Sonstige'
                END
            )::text                                                                          AS fuel,
            COALESCE(f.name, NULLIF(trim(k.kraftstoff), ''))::text                           AS fuel_name,
            NULLIF(trim(k.fhzart), '')::text                                                 AS vehicle_type,
            NULLIF(regexp_replace(COALESCE(k.hubraum, ''), '\D', '', 'g'), '')::integer      AS ccm,
            (SELECT (m[1])::integer
               FROM regexp_matches(COALESCE(k.leistung, ''), '(\d+)', 'g') m
              WHERE (m[1])::integer BETWEEN 1 AND 999
              LIMIT 1)                                                                       AS kw,
            EXTRACT(YEAR FROM c.c_d)::integer                                                AS year,
            c.c_d                                                                            AS first_reg
        FROM cars_lxcars c
        LEFT JOIN kba_lxcars k            ON k.id = c.kba_id
        LEFT JOIN kba_fuel_codes_lxcars f ON f.code = trim(k.kraftstoff)
        WHERE p_c_id IS NULL OR c.c_id = p_c_id
    ),
    up AS (
        INSERT INTO special_tool_vehicle_profiles_lxcars AS t
            (c_id, c_ln, c_ow, hsn, tsn, make, model, make_model_text, engine_code, engine_code_norm,
             fuel, fuel_name, vehicle_type, ccm, kw, year, first_reg, updated_at)
        SELECT c_id, c_ln, c_ow, hsn, tsn, make, model, make_model_text, engine_code, engine_code_norm,
               fuel, fuel_name, vehicle_type, ccm, kw, year, first_reg, now()
        FROM src
        ON CONFLICT (c_id) DO UPDATE SET
            c_ln = EXCLUDED.c_ln, c_ow = EXCLUDED.c_ow, hsn = EXCLUDED.hsn, tsn = EXCLUDED.tsn,
            make = EXCLUDED.make, model = EXCLUDED.model, make_model_text = EXCLUDED.make_model_text,
            engine_code = EXCLUDED.engine_code, engine_code_norm = EXCLUDED.engine_code_norm,
            fuel = EXCLUDED.fuel, fuel_name = EXCLUDED.fuel_name, vehicle_type = EXCLUDED.vehicle_type,
            ccm = EXCLUDED.ccm, kw = EXCLUDED.kw, year = EXCLUDED.year, first_reg = EXCLUDED.first_reg,
            updated_at = now()
        RETURNING 1
    )
    SELECT COUNT(*)::integer FROM up
$$;

-- Übersetzt die Kriterien einer Regel in vorbereitete Arrays und reguläre
-- Ausdrücke. So wird die Prüfung gegen die ganze Flotte zu einem einfachen
-- Join ohne Funktionsaufruf je Fahrzeug. Leere oder fehlende Kriterien
-- ergeben NULL und gelten als "egal".
CREATE OR REPLACE FUNCTION special_tool_compile_criteria_lxcars(crit jsonb)
RETURNS TABLE (
    fuel            text[],
    vehicle_types   text[],
    hsn             text[],
    make_regex      text,
    model_regex     text,
    engine_regex    text,
    ccm_from        integer,
    ccm_to          integer,
    kw_from         integer,
    kw_to           integer,
    year_from       integer,
    year_to         integer
)
LANGUAGE sql IMMUTABLE AS $$
    WITH c AS (SELECT COALESCE(crit, '{}'::jsonb) AS j),
    lists AS (
        SELECT key, array_agg(trim(x)) FILTER (WHERE trim(x) <> '') AS vals
        FROM c, jsonb_each(c.j) e(key, val), jsonb_array_elements_text(val) x
        WHERE jsonb_typeof(val) = 'array'
        GROUP BY key
    )
    SELECT
        (SELECT array_agg(lower(v)) FROM lists, unnest(vals) v WHERE key = 'fuel'),
        (SELECT array_agg(lower(v)) FROM lists, unnest(vals) v WHERE key = 'vehicle_types'),
        (SELECT array_agg(lpad(v, 4, '0')) FROM lists, unnest(vals) v WHERE key = 'hsn'),
        (SELECT '\m(' || string_agg(regexp_replace(v, '([.^$|()\[\]{}*+?\\])', '\\\1', 'g'), '|') || ')\M'
           FROM lists, unnest(vals) v WHERE key = 'makes'),
        (SELECT '\m(' || string_agg(regexp_replace(v, '([.^$|()\[\]{}*+?\\])', '\\\1', 'g'), '|') || ')\M'
           FROM lists, unnest(vals) v WHERE key = 'models'),
        -- Motorcodes normalisiert (nur A-Z/0-9), Teilstring-Suche, * als Platzhalter
        (SELECT '(' || string_agg(replace(n, '*', '.*'), '|') || ')'
           FROM (SELECT upper(regexp_replace(v, '[^A-Za-z0-9*]', '', 'g')) AS n
                   FROM lists, unnest(vals) v WHERE key = 'engine_codes') q
          WHERE n <> '' AND n <> '*'),
        (SELECT CASE WHEN j->>'ccm_from'  ~ '^\d+$' THEN (j->>'ccm_from')::integer  END FROM c),
        (SELECT CASE WHEN j->>'ccm_to'    ~ '^\d+$' THEN (j->>'ccm_to')::integer    END FROM c),
        (SELECT CASE WHEN j->>'kw_from'   ~ '^\d+$' THEN (j->>'kw_from')::integer   END FROM c),
        (SELECT CASE WHEN j->>'kw_to'     ~ '^\d+$' THEN (j->>'kw_to')::integer     END FROM c),
        (SELECT CASE WHEN j->>'year_from' ~ '^\d+$' THEN (j->>'year_from')::integer END FROM c),
        (SELECT CASE WHEN j->>'year_to'   ~ '^\d+$' THEN (j->>'year_to')::integer   END FROM c)
$$;

-- Berechnet die Treffer Werkzeug ↔ Fahrzeug: Treffer der Einschlussregeln
-- abzüglich der Ausschlussregeln, plus fest zugeordnete Fahrzeuge, abzüglich
-- der von Hand ausgeschlossenen. Entscheidungsreihenfolge: manueller
-- Ausschluss > feste Zuordnung > Ausschlussregel > Einschlussregel.
-- Alle Parameter optional:
--   p_tool_id   nur dieses Werkzeug
--   p_c_id      nur dieses Fahrzeug
--   p_criteria  statt der gespeicherten Regeln diese Kriterien prüfen
--               (Vorschau im Regel-Editor; tool_id/rule_id sind dann NULL)
--   p_profile   statt der Fahrzeuge im Bestand dieses eine Fahrzeugprofil
--               prüfen (Shop-Suche nach HSN/TSN; c_id ist dann 0, feste
--               Zuordnungen und Ausschlüsse je Fahrzeug greifen nicht)
-- Innerhalb einer Regel gelten alle gesetzten Kriterien zugleich; Hersteller
-- und HSN sind eine gemeinsame Alternative. Ist ein Zahlenbereich gesetzt,
-- muss der Fahrzeugwert bekannt sein (NULL trifft nie).
DROP FUNCTION IF EXISTS special_tool_compute_matches_lxcars(integer, integer, jsonb);
CREATE OR REPLACE FUNCTION special_tool_compute_matches_lxcars(
    p_tool_id   integer DEFAULT NULL,
    p_c_id      integer DEFAULT NULL,
    p_criteria  jsonb   DEFAULT NULL,
    p_profile   jsonb   DEFAULT NULL
)
RETURNS TABLE (
    tool_id     integer,
    c_id        integer,
    rule_id     integer,
    source      text,
    note        text
)
LANGUAGE sql STABLE AS $$
    WITH profiles AS (
        SELECT c_id, make_model_text, hsn, engine_code_norm, fuel, vehicle_type, ccm, kw, year
        FROM special_tool_vehicle_profiles_lxcars
        WHERE p_profile IS NULL AND (p_c_id IS NULL OR c_id = p_c_id)
        UNION ALL
        SELECT 0, x.make_model_text, x.hsn,
               upper(regexp_replace(COALESCE(x.engine_code, ''), '[^A-Za-z0-9]', '', 'g')),
               x.fuel, x.vehicle_type, x.ccm, x.kw, x.year
        FROM jsonb_to_record(p_profile)
             AS x(make_model_text text, hsn text, engine_code text, fuel text,
                  vehicle_type text, ccm integer, kw integer, year integer)
        WHERE p_profile IS NOT NULL
    ),
    rules AS (
        SELECT r.id, r.tool_id, r.label, r.mode, cc.*
        FROM special_tool_rules_lxcars r
        CROSS JOIN LATERAL special_tool_compile_criteria_lxcars(r.criteria) cc
        WHERE p_criteria IS NULL
          AND r.active
          AND (p_tool_id IS NULL OR r.tool_id = p_tool_id)
        UNION ALL
        SELECT NULL::integer, NULL::integer, NULL::text, 'include'::text, cc.*
        FROM special_tool_compile_criteria_lxcars(p_criteria) cc
        WHERE p_criteria IS NOT NULL
    ),
    -- Stufe 1 (billig): Kraftstoff, Fahrzeugart, Zahlenbereiche, Motorcode vorhanden.
    -- MATERIALIZED, damit die regulären Ausdrücke in Stufe 2 nur noch die
    -- verbliebenen Kandidaten sehen und nicht die ganze Flotte je Regel.
    cand AS MATERIALIZED (
        SELECT r.id AS rule_id, r.tool_id, r.label, r.mode,
               r.hsn, r.make_regex, r.model_regex, r.engine_regex,
               p.c_id, p.make_model_text, p.engine_code_norm, p.hsn AS p_hsn
        FROM rules r
        JOIN profiles p ON
                (r.fuel          IS NULL OR lower(COALESCE(p.fuel, ''))         = ANY(r.fuel))
            AND (r.vehicle_types IS NULL OR lower(COALESCE(p.vehicle_type, '')) = ANY(r.vehicle_types))
            AND (r.ccm_from  IS NULL OR p.ccm  >= r.ccm_from)
            AND (r.ccm_to    IS NULL OR p.ccm  <= r.ccm_to)
            AND (r.kw_from   IS NULL OR p.kw   >= r.kw_from)
            AND (r.kw_to     IS NULL OR p.kw   <= r.kw_to)
            AND (r.year_from IS NULL OR p.year >= r.year_from)
            AND (r.year_to   IS NULL OR p.year <= r.year_to)
            AND (r.engine_regex IS NULL OR p.engine_code_norm <> '')
    ),
    -- Stufe 2: Hersteller/HSN, Modell und Motorcode (reguläre Ausdrücke)
    rule_hits AS MATERIALIZED (
        SELECT c.tool_id, c.c_id, c.rule_id, c.mode, c.label
        FROM cand c
        WHERE (   (c.hsn IS NULL AND c.make_regex IS NULL)
               OR lpad(COALESCE(c.p_hsn, ''), 4, '0') = ANY(c.hsn)
               OR c.make_model_text ~* c.make_regex)
          AND (c.model_regex  IS NULL OR c.make_model_text ~* c.model_regex)
          AND (c.engine_regex IS NULL OR c.engine_code_norm ~ c.engine_regex)
    ),
    -- Ausschlussregeln ziehen Treffer desselben Werkzeugs ab (Hash-Join über
    -- COALESCE, weil tool_id in der Vorschau NULL ist)
    excl AS MATERIALIZED (
        SELECT DISTINCT COALESCE(x.tool_id, 0) AS tool_key, x.c_id
        FROM rule_hits x WHERE x.mode = 'exclude'
    ),
    hits AS (
        SELECT i.tool_id, i.c_id, i.rule_id, 'rule'::text AS source, i.label AS note
        FROM rule_hits i
        LEFT JOIN excl e ON e.tool_key = COALESCE(i.tool_id, 0) AND e.c_id = i.c_id
        WHERE i.mode = 'include' AND e.c_id IS NULL
        UNION ALL
        SELECT v.tool_id, v.c_id, NULL::integer, 'manual'::text, v.note
        FROM special_tool_vehicles_lxcars v
        WHERE p_criteria IS NULL AND p_profile IS NULL
          AND v.mode = 'include'
          AND (p_tool_id IS NULL OR v.tool_id = p_tool_id)
          AND (p_c_id IS NULL OR v.c_id = p_c_id)
    )
    SELECT h.tool_id, h.c_id, h.rule_id, h.source, h.note
    FROM hits h
    WHERE p_criteria IS NOT NULL OR p_profile IS NOT NULL OR NOT EXISTS (
        SELECT 1 FROM special_tool_vehicles_lxcars x
        WHERE x.tool_id = h.tool_id AND x.c_id = h.c_id AND x.mode = 'exclude'
    )
$$;

-- Fahrzeugprofil aus den KBA-Schlüsselnummern, wie es die Shop-Suche braucht:
-- HSN (4-stellig) und TSN (die ersten drei Stellen genügen) finden den
-- KBA-Datensatz; Motorcode und Erstzulassungsjahr kommen vom Besucher dazu.
-- Liefert NULL, wenn die Schlüsselnummern unbekannt sind. Die Felder
-- entsprechen den Spalten von special_tool_vehicle_profiles_lxcars, dazu
-- `label` als Anzeigetext.
CREATE OR REPLACE FUNCTION special_tool_profile_from_kba_lxcars(
    p_hsn text, p_tsn text, p_engine_code text DEFAULT NULL, p_year integer DEFAULT NULL
) RETURNS jsonb
LANGUAGE sql STABLE AS $$
    SELECT jsonb_build_object(
        'hsn',             k.hsn,
        'tsn',             upper(left(regexp_replace(COALESCE(p_tsn, ''), '\s', '', 'g'), 3)),
        'make',            COALESCE(NULLIF(trim(k.marke), ''), NULLIF(trim(k.hersteller), '')),
        'model',           COALESCE(NULLIF(trim(k.d3), ''), NULLIF(trim(k.name), '')),
        'make_model_text', concat_ws(' ', k.hersteller, k.marke, k.name, k.d3),
        'engine_code',     NULLIF(trim(COALESCE(p_engine_code, '')), ''),
        'fuel',            COALESCE(f.fuel_group,
                               CASE WHEN k.kraftstoff ILIKE '%diesel%' THEN 'Diesel'
                                    WHEN k.kraftstoff ILIKE '%benzin%' OR k.kraftstoff ILIKE '%otto%' THEN 'Benzin'
                                    WHEN k.kraftstoff ILIKE '%elektro%' THEN 'Elektro'
                                    WHEN NULLIF(trim(k.kraftstoff), '') IS NULL THEN NULL
                                    ELSE 'Sonstige' END),
        'fuel_name',       COALESCE(f.name, NULLIF(trim(k.kraftstoff), '')),
        'vehicle_type',    NULLIF(trim(k.fhzart), ''),
        'ccm',             NULLIF(regexp_replace(COALESCE(k.hubraum, ''), '\D', '', 'g'), '')::integer,
        'kw',              (SELECT (m[1])::integer FROM regexp_matches(COALESCE(k.leistung, ''), '(\d+)', 'g') m
                             WHERE (m[1])::integer BETWEEN 1 AND 999 LIMIT 1),
        'year',            p_year,
        'label',           concat_ws(' ',
                               COALESCE(NULLIF(trim(k.marke), ''), NULLIF(trim(k.hersteller), '')),
                               COALESCE(NULLIF(trim(k.d3), ''), NULLIF(trim(k.name), '')))
    )
    FROM kba_lxcars k
    LEFT JOIN kba_fuel_codes_lxcars f ON f.code = trim(k.kraftstoff)
    WHERE k.hsn = lpad(regexp_replace(COALESCE(p_hsn, ''), '\D', '', 'g'), 4, '0')
      AND upper(k.tsn) = upper(left(regexp_replace(COALESCE(p_tsn, ''), '\s', '', 'g'), 3))
    ORDER BY (k.d2 IS NULL OR k.d2 = '') DESC, k.id
    LIMIT 1
$$;

-- Cache neu berechnen: für ein Werkzeug, ein Fahrzeug oder (beides NULL) alles.
-- Liefert die Zahl der eingetragenen Treffer.
CREATE OR REPLACE FUNCTION special_tool_refresh_matches_lxcars(p_tool_id integer DEFAULT NULL, p_c_id integer DEFAULT NULL)
RETURNS integer
LANGUAGE sql VOLATILE AS $$
    WITH del AS (
        DELETE FROM special_tool_vehicle_matches_lxcars m
        WHERE (p_tool_id IS NULL OR m.tool_id = p_tool_id)
          AND (p_c_id IS NULL OR m.c_id = p_c_id)
    ),
    ins AS (
        INSERT INTO special_tool_vehicle_matches_lxcars (tool_id, c_id, rule_id, source, note)
        SELECT tool_id, c_id, rule_id, source, note
        FROM special_tool_compute_matches_lxcars(p_tool_id, p_c_id)
        RETURNING 1
    )
    SELECT COUNT(*)::integer FROM ins
$$;

-- Trigger: Fahrzeug angelegt oder geändert → Profil und Treffer dieses
-- Fahrzeugs auffrischen. Löschen räumt die Fremdschlüssel-Kaskade auf.
CREATE OR REPLACE FUNCTION special_tool_profile_sync_lxcars() RETURNS trigger AS $$
BEGIN
    PERFORM special_tool_build_profiles_lxcars(NEW.c_id);
    PERFORM special_tool_refresh_matches_lxcars(NULL, NEW.c_id);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'special_tool_profile_sync') THEN
        CREATE TRIGGER special_tool_profile_sync
            AFTER INSERT OR UPDATE OF kba_id, c_mkb, c_2, c_3, c_d, c_m, c_mt, c_ln, c_ow
            ON cars_lxcars
            FOR EACH ROW
            EXECUTE FUNCTION special_tool_profile_sync_lxcars();
    END IF;
END $$;

-- Beim Update alle Profile und Treffer auffrischen (idempotent, ~0,1 s je
-- 10.000 Fahrzeuge). Bringt nach KBA-Importen und Schemaänderungen alles auf Stand.
SELECT special_tool_build_profiles_lxcars(NULL);
SELECT special_tool_refresh_matches_lxcars(NULL, NULL);
