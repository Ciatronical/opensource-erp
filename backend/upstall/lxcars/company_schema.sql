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
    kba_id          integer,
    scan_detail_id  text,
    scan_id         text,
    filename        text,
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
    hsn                TEXT NOT NULL,
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
