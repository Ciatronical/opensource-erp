-- company_schema.sql
-- Tabellen für das public-Schema (Company-Datenbank)

-- ============================================================================
-- FEATURES
-- ============================================================================

CREATE TABLE features_oserp (
    id integer NOT NULL GENERATED ALWAYS AS IDENTITY,
    feature TEXT,
    active BOOL,
    itime TIMESTAMP WITHOUT TIME ZONE DEFAULT now(),
    mtime TIMESTAMP WITHOUT TIME ZONE
);

COMMENT ON TABLE features_oserp IS 'Feature-Flags für OpensourceERP Funktionen';
COMMENT ON COLUMN features_oserp.id IS 'Primärschlüssel (automatisch generiert)';
COMMENT ON COLUMN features_oserp.feature IS 'Name des Features';
COMMENT ON COLUMN features_oserp.active IS 'Feature aktiviert (true) oder deaktiviert (false)';
COMMENT ON COLUMN features_oserp.itime IS 'Zeitstempel der Erstellung';
COMMENT ON COLUMN features_oserp.mtime IS 'Zeitstempel der letzten Änderung';

-- ============================================================================
-- EMPLOYEE CONFIGURATION
-- ============================================================================

CREATE TABLE employee_config_oserp (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    employee_id integer NOT NULL,
    key text NOT NULL, -- sollte besser config_key heißen!!
    value text,
    itime timestamp without time zone NOT NULL DEFAULT now(),
    mtime timestamp without time zone,
    CONSTRAINT employee_config_employee_key_unique UNIQUE (employee_id, key)
);

COMMENT ON TABLE employee_config_oserp IS 'Benutzer-spezifische Konfigurationen und Einstellungen';
COMMENT ON COLUMN employee_config_oserp.id IS 'Primärschlüssel (automatisch generiert)';
COMMENT ON COLUMN employee_config_oserp.employee_id IS 'Referenz zum Mitarbeiter';
COMMENT ON COLUMN employee_config_oserp.key IS 'Konfigurations-Schlüssel (z.B. saved_sql_queries)';
COMMENT ON COLUMN employee_config_oserp.value IS 'Konfigurations-Wert (oft JSON)';
COMMENT ON COLUMN employee_config_oserp.itime IS 'Zeitstempel der Erstellung';
COMMENT ON COLUMN employee_config_oserp.mtime IS 'Zeitstempel der letzten Änderung';

-- ============================================================================
-- DEFAULT SETTINGS
-- ============================================================================

CREATE TABLE defaults_oserp (
    "key" text PRIMARY KEY,
    value text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

COMMENT ON TABLE defaults_oserp IS 'Globale System-Einstellungen und Standardwerte';
COMMENT ON COLUMN defaults_oserp.key IS 'Einstellungs-Schlüssel';
COMMENT ON COLUMN defaults_oserp.value IS 'Einstellungs-Wert';
COMMENT ON COLUMN defaults_oserp.itime IS 'Zeitstempel der Erstellung';
COMMENT ON COLUMN defaults_oserp.mtime IS 'Zeitstempel der letzten Änderung';

-- ============================================================================
-- GERMAN BANK CODES (BLZ)
-- ============================================================================

CREATE TABLE blz_de (
    blz             char(8) PRIMARY KEY,
    is_main         char(1)    NOT NULL,
    name            text,
    plz             text,
    ort             text,
    kurzname        text,
    pan             text,
    bic             text,
    pz_verfahren    text,
    datensatz_nr    text,
    aenderung       char(1),
    loeschung_flag  char(1),
    nachfolge_blz   char(8)
);

COMMENT ON TABLE blz_de IS 'Deutsche Bankleitzahlen (nur hauptführende BLZ)';
COMMENT ON COLUMN blz_de.blz IS 'Bankleitzahl (8-stellig)';
COMMENT ON COLUMN blz_de.is_main IS '1=bankleitzahlführend, 2=sonst';
COMMENT ON COLUMN blz_de.name IS 'Name der Bank';
COMMENT ON COLUMN blz_de.plz IS 'Postleitzahl';
COMMENT ON COLUMN blz_de.ort IS 'Ort';
COMMENT ON COLUMN blz_de.kurzname IS 'Kurzname der Bank';
COMMENT ON COLUMN blz_de.pan IS 'PAN (Primary Account Number)';
COMMENT ON COLUMN blz_de.bic IS 'BIC (Bank Identifier Code)';
COMMENT ON COLUMN blz_de.pz_verfahren IS 'Prüfziffer-Verfahren';
COMMENT ON COLUMN blz_de.datensatz_nr IS 'Datensatznummer';
COMMENT ON COLUMN blz_de.aenderung IS 'Änderungskennzeichen';
COMMENT ON COLUMN blz_de.loeschung_flag IS 'Löschungskennzeichen';
COMMENT ON COLUMN blz_de.nachfolge_blz IS 'Nachfolge-Bankleitzahl';

-- ============================================================================
-- SQL QUERY HISTORY
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.sql_query_history (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    query TEXT NOT NULL,
    execution_time NUMERIC(10,2),
    row_count INTEGER,
    itime TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    database_type VARCHAR(20) DEFAULT 'company' NOT NULL
);

-- Indizes für Performance
CREATE INDEX IF NOT EXISTS idx_sql_query_history_user_id
    ON public.sql_query_history(user_id);

CREATE INDEX IF NOT EXISTS idx_sql_query_history_itime
    ON public.sql_query_history(itime DESC);

CREATE INDEX IF NOT EXISTS idx_sql_query_history_database_type
    ON public.sql_query_history(database_type);

-- Kommentare
COMMENT ON TABLE public.sql_query_history IS 'Speichert erfolgreiche SQL-Queries aus dem SQL-Tool';
COMMENT ON COLUMN public.sql_query_history.id IS 'Primärschlüssel';
COMMENT ON COLUMN public.sql_query_history.user_id IS 'Referenz zum User der den Query ausgeführt hat';
COMMENT ON COLUMN public.sql_query_history.query IS 'Der ausgeführte SQL-Query';
COMMENT ON COLUMN public.sql_query_history.execution_time IS 'Ausführungszeit in Millisekunden';
COMMENT ON COLUMN public.sql_query_history.row_count IS 'Anzahl betroffener/zurückgegebener Zeilen';
COMMENT ON COLUMN public.sql_query_history.itime IS 'Zeitstempel der Query-Ausführung';
COMMENT ON COLUMN public.sql_query_history.database_type IS 'Datenbank-Typ: company oder auth';

-- ============================================================================
-- FIRSTNAME TO GENDER
-- ============================================================================

CREATE TABLE firstnametogender (
    gender character(1) COLLATE pg_catalog."default" NOT NULL,
    firstname text COLLATE pg_catalog."default" NOT NULL,
    CONSTRAINT firstnametogender_pkey PRIMARY KEY (firstname)
);

-- ============================================================================
-- CUSTOMER EXTENSION
-- ============================================================================

CREATE TABLE customer_ext (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id INTEGER NOT NULL,
    phone_numbers JSONB,
    phone_labels JSONB,
    emails JSONB,
    keywords TEXT,
    itime TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    mtime TIMESTAMP WITHOUT TIME ZONE,
    CONSTRAINT customer_ext_customer_id_unique UNIQUE (customer_id)
);

COMMENT ON TABLE customer_ext IS 'Erweiterte Kontaktdaten für Kunden (Telefonnummern, Bezeichnungen, E-Mails)';
COMMENT ON COLUMN customer_ext.id IS 'Primärschlüssel (automatisch generiert)';
COMMENT ON COLUMN customer_ext.customer_id IS 'Referenz zum Kunden';
COMMENT ON COLUMN customer_ext.phone_numbers IS 'JSON-Array mit Telefonnummern';
COMMENT ON COLUMN customer_ext.phone_labels IS 'JSON-Array mit Bezeichnungen für Telefonnummern';
COMMENT ON COLUMN customer_ext.emails IS 'JSON-Array mit E-Mail-Adressen';
COMMENT ON COLUMN customer_ext.itime IS 'Zeitstempel der Erstellung';
COMMENT ON COLUMN customer_ext.mtime IS 'Zeitstempel der letzten Änderung';

-- Index für schnelleren Zugriff
CREATE INDEX idx_customer_ext_customer_id ON customer_ext(customer_id);

-- ============================================================================
-- VENDOR EXTENSION
-- ============================================================================

CREATE TABLE vendor_ext (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    vendor_id INTEGER NOT NULL,
    phone_numbers JSONB,
    phone_labels JSONB,
    emails JSONB,
    itime TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    mtime TIMESTAMP WITHOUT TIME ZONE,
    CONSTRAINT vendor_ext_vendor_id_unique UNIQUE (vendor_id)
);

COMMENT ON TABLE vendor_ext IS 'Erweiterte Kontaktdaten für Lieferanten (Telefonnummern, Bezeichnungen, E-Mails)';
COMMENT ON COLUMN vendor_ext.id IS 'Primärschlüssel (automatisch generiert)';
COMMENT ON COLUMN vendor_ext.vendor_id IS 'Referenz zum Lieferanten';
COMMENT ON COLUMN vendor_ext.phone_numbers IS 'JSON-Array mit Telefonnummern';
COMMENT ON COLUMN vendor_ext.phone_labels IS 'JSON-Array mit Bezeichnungen für Telefonnummern';
COMMENT ON COLUMN vendor_ext.emails IS 'JSON-Array mit E-Mail-Adressen';
COMMENT ON COLUMN vendor_ext.itime IS 'Zeitstempel der Erstellung';
COMMENT ON COLUMN vendor_ext.mtime IS 'Zeitstempel der letzten Änderung';

-- Index für schnelleren Zugriff
CREATE INDEX idx_vendor_ext_vendor_id ON vendor_ext(vendor_id);

--- ============================================================================
--- CRM TELEFONIE HISTORIE
--- ============================================================================

CREATE TABLE crmti (
    crmti_id        integer NOT NULL GENERATED ALWAYS AS IDENTITY,
    crmti_init_time timestamptz DEFAULT now(),
    crmti_end_time  timestamptz DEFAULT now(),
    crmti_src       text,
    crmti_dst       text,
    crmti_caller_id integer,
    crmti_caller_typ char(1),
    crmti_direction text,
    crmti_status    text,
    crmti_number    text,
    unique_call_id  text,

    CONSTRAINT crmti_pkey PRIMARY KEY (crmti_id)
);

DROP INDEX IF EXISTS crmti_unique_call_id_idx;

CREATE OR REPLACE FUNCTION callin(
    text,
    text,
    text)
    RETURNS text
    LANGUAGE 'plpgsql'
    COST 100
    VOLATILE PARALLEL UNSAFE
AS $BODY$
DECLARE
    src ALIAS FOR $1;
    dst ALIAS FOR $2;
    unique_call_id ALIAS FOR $3;
    result record;
    new_row crmti%rowtype;

BEGIN
    result := SucheNummer( src );
    INSERT INTO crmti ( crmti_src, crmti_caller_id, crmti_caller_typ, crmti_dst, crmti_direction, crmti_number, unique_call_id  ) VALUES ( result.name, result.id, result.typ, dst, 'E', src, unique_call_id  ) RETURNING * INTO new_row;

    PERFORM pg_notify('crmti_change', to_json(new_row)::TEXT);

    IF result.typ != 'X' THEN
        insert into telcall ( calldate, bezug, cause, caller_id, kontakt, inout ) values ( CURRENT_TIMESTAMP, 0, 'Eingehender Anruf zu[r|m] '||dst, result.id, 'T','i' );
    END IF;
    IF result.id = 0 THEN
        return NULL;
    ELSE
        return result.name;
    END IF;
END;
$BODY$;

CREATE OR REPLACE FUNCTION callout(
    text,
    text,
    text)
    RETURNS text
    LANGUAGE 'plpgsql'
    COST 100
    VOLATILE PARALLEL UNSAFE
AS $BODY$
    -- Für ausgehende Anrufe
    DECLARE
        src ALIAS FOR $1;
        dst ALIAS FOR $2;
        unique_call_id ALIAS FOR $3;
        result record;
        new_row crmti%rowtype;
    BEGIN
        result := SucheNummer( dst );
        INSERT INTO crmti ( crmti_src, crmti_dst, crmti_caller_typ, crmti_caller_id, crmti_direction, crmti_number, unique_call_id ) VALUES ( src, result.name, result.typ, result.id, 'A', dst, unique_call_id ) RETURNING * INTO new_row;

        PERFORM pg_notify('crmti_change', to_json(new_row)::TEXT);

        DELETE FROM crmti WHERE crmti_id  < new_row.crmti_id - 512;
        IF result.typ != 'X' THEN
            INSERT INTO telcall ( calldate, bezug, cause, caller_id, kontakt, inout ) values ( CURRENT_TIMESTAMP, 0, 'Ausgehender Anruf vo[n|m] '||src, result.id, 'T', 'o' );
        END IF;
        return '1';
    END;
$BODY$;

-- ============================================================================
-- CALENDAR EVENT CATEGORIES
-- ============================================================================

CREATE TABLE IF NOT EXISTS event_category (
    id        INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    label     TEXT NOT NULL,
    color     CHARACTER(7) NOT NULL DEFAULT '#1976D2',
    cat_order INTEGER NOT NULL DEFAULT 1,
    itime     TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    mtime     TIMESTAMP WITHOUT TIME ZONE
);

COMMENT ON TABLE event_category IS 'Kategorien für Kalendertermine';
COMMENT ON COLUMN event_category.id IS 'Primärschlüssel (automatisch generiert)';
COMMENT ON COLUMN event_category.label IS 'Bezeichnung der Kategorie';
COMMENT ON COLUMN event_category.color IS 'Farbe der Kategorie (#RRGGBB)';
COMMENT ON COLUMN event_category.cat_order IS 'Sortierungsreihenfolge';

-- Default-Kategorien nur einfügen wenn Tabelle leer ist
INSERT INTO event_category (label, color, cat_order)
SELECT v.label, v.color, v.cat_order
FROM (VALUES
    ('Allgemein', '#1976D2', 1),
    ('Meeting', '#4CAF50', 2),
    ('Kundentermin', '#FF9800', 3),
    ('Intern', '#9C27B0', 4),
    ('Deadline', '#E53935', 5)
) AS v(label, color, cat_order)
WHERE NOT EXISTS (SELECT 1 FROM event_category LIMIT 1);

-- ============================================================================
-- CALENDAR EVENTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS calendar_events (
    id          INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    title       TEXT NOT NULL,
    description TEXT,
    dtstart     TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    dtend       TIMESTAMP WITHOUT TIME ZONE,
    "allDay"    BOOLEAN NOT NULL DEFAULT FALSE,
    location    TEXT,
    color       CHARACTER(7),
    prio        INTEGER NOT NULL DEFAULT 1,
    category_id INTEGER,
    visibility  INTEGER NOT NULL DEFAULT -1,
    uid         INTEGER NOT NULL,
    cvp_id      INTEGER,
    cvp_name    TEXT,
    cvp_type    CHARACTER(1),
    order_id    INTEGER,
    itime       TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    mtime       TIMESTAMP WITHOUT TIME ZONE
);

COMMENT ON TABLE calendar_events IS 'Kalendertermine (CRM Kalender)';
COMMENT ON COLUMN calendar_events.id IS 'Primärschlüssel (automatisch generiert)';
COMMENT ON COLUMN calendar_events.title IS 'Titel des Termins';
COMMENT ON COLUMN calendar_events.description IS 'Beschreibung / Notizen';
COMMENT ON COLUMN calendar_events.dtstart IS 'Startdatum/-zeit';
COMMENT ON COLUMN calendar_events.dtend IS 'Enddatum/-zeit';
COMMENT ON COLUMN calendar_events."allDay" IS 'Ganztägiger Termin';
COMMENT ON COLUMN calendar_events.location IS 'Ort';
COMMENT ON COLUMN calendar_events.color IS 'Individuelle Farbe (#RRGGBB)';
COMMENT ON COLUMN calendar_events.prio IS 'Priorität: 0=Niedrig, 1=Normal, 2=Hoch';
COMMENT ON COLUMN calendar_events.category_id IS 'Referenz zur Kategorie (event_category.id)';
COMMENT ON COLUMN calendar_events.visibility IS 'Sichtbarkeit: -1=Alle, 0=Privat';
COMMENT ON COLUMN calendar_events.uid IS 'Eigentümer (employee.id)';
COMMENT ON COLUMN calendar_events.cvp_id IS 'Verknüpfter Kunde/Lieferant (optional)';
COMMENT ON COLUMN calendar_events.cvp_name IS 'Denormalisierter Name des Kunden/Lieferanten';
COMMENT ON COLUMN calendar_events.cvp_type IS 'Typ: C=Kunde, V=Lieferant';
COMMENT ON COLUMN calendar_events.order_id IS 'Verknüpfter Auftrag (oe.id, optional)';
COMMENT ON COLUMN calendar_events.itime IS 'Zeitstempel der Erstellung';
COMMENT ON COLUMN calendar_events.mtime IS 'Zeitstempel der letzten Änderung';

CREATE INDEX IF NOT EXISTS idx_calendar_events_uid ON calendar_events(uid);
CREATE INDEX IF NOT EXISTS idx_calendar_events_dtstart ON calendar_events(dtstart);
CREATE INDEX IF NOT EXISTS idx_calendar_events_category ON calendar_events(category_id);

--- ============================================================================
--- ZIPCODE TO LOCATION
--- ============================================================================

CREATE TABLE zipcode_location_oserp
(
    id integer NOT NULL GENERATED ALWAYS AS IDENTITY,
    ort text NOT NULL,
    plz character(5) NOT NULL,
    landkreis text,
    bundesland text NOT NULL,
    CONSTRAINT zipcode_location_oserp_pkey PRIMARY KEY (id)
);

-- ============================================================================
-- WIKI / WISSENSDATENBANK
-- ============================================================================

CREATE TABLE wiki_categories (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name TEXT NOT NULL,
    sortkey INTEGER DEFAULT 0,
    itime TIMESTAMP WITHOUT TIME ZONE DEFAULT now(),
    mtime TIMESTAMP WITHOUT TIME ZONE
);

COMMENT ON TABLE wiki_categories IS 'Kategorien fuer Wiki-Artikel';
COMMENT ON COLUMN wiki_categories.id IS 'Primaerschluessel (automatisch generiert)';
COMMENT ON COLUMN wiki_categories.name IS 'Name der Kategorie';
COMMENT ON COLUMN wiki_categories.sortkey IS 'Sortierreihenfolge';
COMMENT ON COLUMN wiki_categories.itime IS 'Zeitstempel der Erstellung';
COMMENT ON COLUMN wiki_categories.mtime IS 'Zeitstempel der letzten Aenderung';

CREATE TABLE wiki_pages (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    title TEXT NOT NULL,
    slug TEXT NOT NULL,
    content TEXT DEFAULT '',
    category_id INTEGER REFERENCES wiki_categories(id) ON DELETE SET NULL,
    kba_hsn TEXT,
    kba_tsn TEXT,
    created_by INTEGER,
    updated_by INTEGER,
    itime TIMESTAMP WITHOUT TIME ZONE DEFAULT now(),
    mtime TIMESTAMP WITHOUT TIME ZONE
);

COMMENT ON TABLE wiki_pages IS 'Wiki-Artikel / Wissensartikel';
COMMENT ON COLUMN wiki_pages.id IS 'Primaerschluessel (automatisch generiert)';
COMMENT ON COLUMN wiki_pages.title IS 'Titel des Artikels';
COMMENT ON COLUMN wiki_pages.slug IS 'URL-freundlicher Bezeichner';
COMMENT ON COLUMN wiki_pages.content IS 'HTML-Inhalt des Artikels';
COMMENT ON COLUMN wiki_pages.category_id IS 'Referenz zur Kategorie';
COMMENT ON COLUMN wiki_pages.kba_hsn IS 'KBA Herstellerschluessel (4-stellig, optional)';
COMMENT ON COLUMN wiki_pages.kba_tsn IS 'KBA Typschluessel erste 3 Zeichen (optional)';
COMMENT ON COLUMN wiki_pages.created_by IS 'Ersteller (employee.id)';
COMMENT ON COLUMN wiki_pages.updated_by IS 'Letzter Bearbeiter (employee.id)';
COMMENT ON COLUMN wiki_pages.itime IS 'Zeitstempel der Erstellung';
COMMENT ON COLUMN wiki_pages.mtime IS 'Zeitstempel der letzten Aenderung';

CREATE INDEX idx_wiki_pages_slug ON wiki_pages(slug);
CREATE INDEX idx_wiki_pages_category ON wiki_pages(category_id);
CREATE INDEX idx_wiki_pages_kba ON wiki_pages(kba_hsn, kba_tsn);

CREATE TABLE wiki_revisions (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    page_id INTEGER NOT NULL REFERENCES wiki_pages(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    content TEXT DEFAULT '',
    edited_by INTEGER,
    itime TIMESTAMP WITHOUT TIME ZONE DEFAULT now()
);

COMMENT ON TABLE wiki_revisions IS 'Versionierung der Wiki-Artikel';
COMMENT ON COLUMN wiki_revisions.id IS 'Primaerschluessel (automatisch generiert)';
COMMENT ON COLUMN wiki_revisions.page_id IS 'Referenz zum Wiki-Artikel';
COMMENT ON COLUMN wiki_revisions.title IS 'Titel zum Zeitpunkt der Revision';
COMMENT ON COLUMN wiki_revisions.content IS 'Inhalt zum Zeitpunkt der Revision';
COMMENT ON COLUMN wiki_revisions.edited_by IS 'Bearbeiter (employee.id)';
COMMENT ON COLUMN wiki_revisions.itime IS 'Zeitstempel der Revision';

CREATE INDEX idx_wiki_revisions_page ON wiki_revisions(page_id);

-- ============================================================================
-- WHATSAPP MESSAGES
-- ============================================================================

CREATE TABLE IF NOT EXISTS whatsapp_messages (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    wa_message_id TEXT,
    direction CHAR(1) NOT NULL DEFAULT 'I',
    phone_number TEXT NOT NULL,
    customer_id INTEGER,
    contact_name TEXT,
    message_type TEXT NOT NULL DEFAULT 'text',
    message_text TEXT,
    media_url TEXT,
    media_mime_type TEXT,
    media_caption TEXT,
    status TEXT DEFAULT 'received',
    status_timestamp TIMESTAMPTZ,
    error_code TEXT,
    error_message TEXT,
    itime TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    mtime TIMESTAMPTZ,
    CONSTRAINT whatsapp_messages_wa_id_unique UNIQUE (wa_message_id)
);

COMMENT ON TABLE whatsapp_messages IS 'WhatsApp Business API Nachrichten-Historie';
COMMENT ON COLUMN whatsapp_messages.direction IS 'I=Eingehend, O=Ausgehend';
COMMENT ON COLUMN whatsapp_messages.phone_number IS 'Telefonnummer (internationales Format)';
COMMENT ON COLUMN whatsapp_messages.customer_id IS 'Zugeordneter Kunde (optional)';
COMMENT ON COLUMN whatsapp_messages.contact_name IS 'WhatsApp-Profilname';
COMMENT ON COLUMN whatsapp_messages.message_type IS 'text, image, document, audio, video, location, sticker';
COMMENT ON COLUMN whatsapp_messages.status IS 'received, sent, delivered, read, failed';

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_whatsapp_messages_phone') THEN
        CREATE INDEX idx_whatsapp_messages_phone ON whatsapp_messages(phone_number);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_whatsapp_messages_customer') THEN
        CREATE INDEX idx_whatsapp_messages_customer ON whatsapp_messages(customer_id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_whatsapp_messages_itime') THEN
        CREATE INDEX idx_whatsapp_messages_itime ON whatsapp_messages(itime DESC);
    END IF;
END $$;

-- Trigger: pg_notify bei neuer eingehender WhatsApp-Nachricht
CREATE OR REPLACE FUNCTION notify_whatsapp_message() RETURNS trigger AS $$
BEGIN
    IF NEW.direction = 'I' THEN
        PERFORM pg_notify('whatsapp_message', json_build_object(
            'id', NEW.id,
            'phone_number', NEW.phone_number,
            'contact_name', NEW.contact_name,
            'customer_id', NEW.customer_id,
            'message_text', LEFT(NEW.message_text, 200),
            'message_type', NEW.message_type,
            'itime', NEW.itime
        )::TEXT);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'whatsapp_message_notify') THEN
        CREATE TRIGGER whatsapp_message_notify
            AFTER INSERT ON whatsapp_messages
            FOR EACH ROW
            EXECUTE FUNCTION notify_whatsapp_message();
    END IF;
END $$;

-- Trigger: pg_notify bei Kalender-Aenderungen (SSE fuer Echtzeit-Updates)
CREATE OR REPLACE FUNCTION notify_calendar_change() RETURNS trigger AS $$
BEGIN
    PERFORM pg_notify('calendar_change', json_build_object(
        'action', TG_OP,
        'id', COALESCE(NEW.id, OLD.id),
        'uid', COALESCE(NEW.uid, OLD.uid),
        'visibility', COALESCE(NEW.visibility, OLD.visibility)
    )::TEXT);
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'calendar_events_notify') THEN
        CREATE TRIGGER calendar_events_notify
            AFTER INSERT OR UPDATE OR DELETE ON calendar_events
            FOR EACH ROW
            EXECUTE FUNCTION notify_calendar_change();
    END IF;
END $$;

-- Trigger: pg_notify bei Faktura-Aenderungen (SSE fuer Echtzeit-Updates)
-- Feuert auf Haupttabellen (oe, ar) und Positionstabellen (orderitems, invoice)

-- Haupttabellen (oe, ar): id direkt verfuegbar
CREATE OR REPLACE FUNCTION notify_faktura_change() RETURNS trigger AS $$
BEGIN
    PERFORM pg_notify('faktura_change', json_build_object(
        'action', TG_OP,
        'table', TG_TABLE_NAME,
        'id', COALESCE(NEW.id, OLD.id)
    )::TEXT);
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

-- Positionstabellen (orderitems, invoice): trans_id ist die Faktura-ID
CREATE OR REPLACE FUNCTION notify_faktura_item_change() RETURNS trigger AS $$
BEGIN
    PERFORM pg_notify('faktura_change', json_build_object(
        'action', TG_OP,
        'table', TG_TABLE_NAME,
        'id', COALESCE(NEW.trans_id, OLD.trans_id)
    )::TEXT);
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'oe_faktura_notify') THEN
        CREATE TRIGGER oe_faktura_notify
            AFTER UPDATE ON oe
            FOR EACH ROW
            EXECUTE FUNCTION notify_faktura_change();
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'ar_faktura_notify') THEN
        CREATE TRIGGER ar_faktura_notify
            AFTER UPDATE ON ar
            FOR EACH ROW
            EXECUTE FUNCTION notify_faktura_change();
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'orderitems_faktura_notify') THEN
        CREATE TRIGGER orderitems_faktura_notify
            AFTER INSERT OR UPDATE OR DELETE ON orderitems
            FOR EACH ROW
            EXECUTE FUNCTION notify_faktura_item_change();
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'invoice_faktura_notify') THEN
        CREATE TRIGGER invoice_faktura_notify
            AFTER INSERT OR UPDATE OR DELETE ON invoice
            FOR EACH ROW
            EXECUTE FUNCTION notify_faktura_item_change();
    END IF;
END $$;

-- ============================================================================
-- WHATSAPP TEMPLATES
-- ============================================================================

CREATE TABLE IF NOT EXISTS whatsapp_templates (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name TEXT NOT NULL,
    display_name TEXT,
    category TEXT NOT NULL DEFAULT 'UTILITY',
    language TEXT NOT NULL DEFAULT 'de',
    header_type TEXT DEFAULT NULL,
    header_text TEXT,
    body_text TEXT NOT NULL,
    footer_text TEXT,
    status TEXT DEFAULT 'draft',
    meta_template_id TEXT,
    rejection_reason TEXT,
    is_default BOOLEAN DEFAULT FALSE,
    template_type TEXT DEFAULT 'general',
    example_values JSONB DEFAULT '{}',
    itime TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    mtime TIMESTAMPTZ,
    CONSTRAINT whatsapp_templates_name_lang_unique UNIQUE (name, language)
);

COMMENT ON TABLE whatsapp_templates IS 'WhatsApp Message Templates (Meta-genehmigte Nachrichtenvorlagen)';
COMMENT ON COLUMN whatsapp_templates.name IS 'Template-Name (Meta-Kennung, lowercase, underscores)';
COMMENT ON COLUMN whatsapp_templates.display_name IS 'Anzeigename im ERP';
COMMENT ON COLUMN whatsapp_templates.category IS 'UTILITY, MARKETING, AUTHENTICATION';
COMMENT ON COLUMN whatsapp_templates.body_text IS 'Nachrichtentext mit {{1}}, {{2}} Platzhaltern';
COMMENT ON COLUMN whatsapp_templates.status IS 'draft, pending, approved, rejected';
COMMENT ON COLUMN whatsapp_templates.meta_template_id IS 'Meta Template-ID nach Einreichung';
COMMENT ON COLUMN whatsapp_templates.header_type IS 'Header-Typ: NULL, TEXT, DOCUMENT, IMAGE, VIDEO';
COMMENT ON COLUMN whatsapp_templates.template_type IS 'general, chat, document, hu, reminder, appointment_confirm';

CREATE INDEX IF NOT EXISTS idx_whatsapp_templates_status ON whatsapp_templates(status);

-- Migration: header_type Spalte hinzufuegen falls nicht vorhanden
ALTER TABLE whatsapp_templates ADD COLUMN IF NOT EXISTS header_type TEXT DEFAULT NULL;

-- Migration: dokument_senden Template mit DOCUMENT-Header versehen
UPDATE whatsapp_templates SET header_type = 'DOCUMENT' WHERE name = 'dokument_senden' AND header_type IS NULL;
CREATE INDEX IF NOT EXISTS idx_whatsapp_templates_type ON whatsapp_templates(template_type);

-- ============================================================================
-- WHATSAPP REMINDERS LOG
-- ============================================================================

CREATE TABLE IF NOT EXISTS whatsapp_reminder_log (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    reminder_type TEXT NOT NULL DEFAULT 'appointment',
    event_id INTEGER,
    car_id INTEGER,
    customer_id INTEGER,
    phone_number TEXT NOT NULL,
    template_id INTEGER,
    wa_message_id TEXT,
    status TEXT DEFAULT 'sent',
    itime TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE whatsapp_reminder_log IS 'Log der automatisch versendeten WhatsApp-Erinnerungen (Termine + HU)';
COMMENT ON COLUMN whatsapp_reminder_log.reminder_type IS 'appointment = Termin, hu = Hauptuntersuchung';
COMMENT ON COLUMN whatsapp_reminder_log.car_id IS 'Fahrzeug-ID (nur bei HU-Erinnerungen)';

CREATE INDEX IF NOT EXISTS idx_whatsapp_reminder_log_event ON whatsapp_reminder_log(event_id);
CREATE INDEX IF NOT EXISTS idx_whatsapp_reminder_log_car ON whatsapp_reminder_log(car_id, reminder_type);

-- ============================================================================
-- TICKETS / PROJEKTMANAGEMENT
-- ============================================================================

CREATE TABLE ticket_labels (
    id integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL,
    color text NOT NULL DEFAULT '#1976D2',
    position integer NOT NULL DEFAULT 0,
    itime timestamp without time zone DEFAULT now()
);

COMMENT ON TABLE ticket_labels IS 'Labels/Tags für Tickets (z.B. Frontend, Backend, Dringend)';

CREATE TABLE tickets (
    id integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    project_id integer,
    requirement_spec_id integer,
    requirement_spec_item_id integer,
    ticket_number text NOT NULL,
    title text NOT NULL,
    description text,
    ticket_type text NOT NULL DEFAULT 'task',
    priority text NOT NULL DEFAULT 'medium',
    status text NOT NULL DEFAULT 'open',
    assigned_to integer,
    reported_by integer,
    estimated_hours numeric(8,2),
    actual_hours numeric(8,2) DEFAULT 0,
    due_date date,
    closed_at timestamp without time zone,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

COMMENT ON TABLE tickets IS 'Tickets für Projektmanagement und Aufgabenverfolgung';
COMMENT ON COLUMN tickets.ticket_type IS 'Art: bug, feature, task, improvement';
COMMENT ON COLUMN tickets.priority IS 'Priorität: low, medium, high, critical';
COMMENT ON COLUMN tickets.status IS 'Status: open, in_progress, review, done, closed';

CREATE TABLE ticket_comments (
    id integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ticket_id integer NOT NULL,
    employee_id integer,
    comment text NOT NULL,
    itime timestamp without time zone DEFAULT now()
);

COMMENT ON TABLE ticket_comments IS 'Kommentare/Diskussion zu Tickets';

CREATE TABLE ticket_label_assignments (
    ticket_id integer NOT NULL,
    label_id integer NOT NULL,
    PRIMARY KEY (ticket_id, label_id)
);

COMMENT ON TABLE ticket_label_assignments IS 'Zuordnung von Labels zu Tickets (n:m)';

-- ============================================================================
-- PFLICHTENHEFT STAMMDATEN (falls leer)
-- ============================================================================

INSERT INTO requirement_spec_types (description, position, section_number_format, function_block_number_format)
SELECT 'Pflichtenheft', 1, 'A00', 'FB000'
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_types LIMIT 1);

INSERT INTO requirement_spec_types (description, position, section_number_format, function_block_number_format)
SELECT 'Lastenheft', 2, 'L00', 'LB000'
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_types WHERE description = 'Lastenheft');

INSERT INTO requirement_spec_types (description, position, section_number_format, function_block_number_format)
SELECT 'Konzept', 3, 'K00', 'KB000'
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_types WHERE description = 'Konzept');

INSERT INTO requirement_spec_statuses (name, description, position)
SELECT 'Neu', 'Neu erstellt, noch nicht begonnen', 1
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_statuses WHERE name = 'Neu');

INSERT INTO requirement_spec_statuses (name, description, position)
SELECT 'In Arbeit', 'Wird aktuell bearbeitet', 2
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_statuses WHERE name = 'In Arbeit');

INSERT INTO requirement_spec_statuses (name, description, position)
SELECT 'Review', 'Zur Prüfung bereit', 3
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_statuses WHERE name = 'Review');

INSERT INTO requirement_spec_statuses (name, description, position)
SELECT 'Abgenommen', 'Vom Kunden abgenommen', 4
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_statuses WHERE name = 'Abgenommen');

INSERT INTO requirement_spec_statuses (name, description, position)
SELECT 'Abgeschlossen', 'Fertig und archiviert', 5
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_statuses WHERE name = 'Abgeschlossen');

INSERT INTO requirement_spec_complexities (description, position)
SELECT 'Einfach', 1
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_complexities LIMIT 1);

INSERT INTO requirement_spec_complexities (description, position)
SELECT 'Mittel', 2
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_complexities WHERE description = 'Mittel');

INSERT INTO requirement_spec_complexities (description, position)
SELECT 'Komplex', 3
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_complexities WHERE description = 'Komplex');

INSERT INTO requirement_spec_complexities (description, position)
SELECT 'Sehr komplex', 4
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_complexities WHERE description = 'Sehr komplex');

INSERT INTO requirement_spec_risks (description, position)
SELECT 'Niedrig', 1
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_risks LIMIT 1);

INSERT INTO requirement_spec_risks (description, position)
SELECT 'Mittel', 2
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_risks WHERE description = 'Mittel');

INSERT INTO requirement_spec_risks (description, position)
SELECT 'Hoch', 3
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_risks WHERE description = 'Hoch');

INSERT INTO requirement_spec_risks (description, position)
SELECT 'Kritisch', 4
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_risks WHERE description = 'Kritisch');

INSERT INTO requirement_spec_acceptance_statuses (name, description, position)
SELECT 'Offen', 'Noch nicht geprüft', 1
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_acceptance_statuses LIMIT 1);

INSERT INTO requirement_spec_acceptance_statuses (name, description, position)
SELECT 'Akzeptiert', 'Vom Kunden akzeptiert', 2
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_acceptance_statuses WHERE name = 'Akzeptiert');

INSERT INTO requirement_spec_acceptance_statuses (name, description, position)
SELECT 'Abgelehnt', 'Vom Kunden abgelehnt', 3
WHERE NOT EXISTS (SELECT 1 FROM requirement_spec_acceptance_statuses WHERE name = 'Abgelehnt');

-- ============================================================================
-- UNIQUE CONSTRAINT: ar.invnumber (Ausgangsrechnungen)
-- Verhindert doppelte Rechnungsnummern auf Datenbankebene.
-- Ersetzt den bestehenden nicht-eindeutigen Index ar_invnumber_key.
-- ============================================================================

DROP INDEX IF EXISTS ar_invnumber_key;
CREATE UNIQUE INDEX ar_invnumber_key ON ar USING btree (lower(invnumber));

-- ============================================================================
-- UNIQUE CONSTRAINT: ap.invnumber (Eingangsrechnungen)
-- Ersetzt den bestehenden nicht-eindeutigen Index ap_invnumber_key.
-- ============================================================================

DROP INDEX IF EXISTS ap_invnumber_key;
CREATE UNIQUE INDEX ap_invnumber_key ON ap USING btree (lower(invnumber));

--- ============================================================================
--- SSE NOTIFICATIONS (pg_notify)
--- ============================================================================

CREATE OR REPLACE FUNCTION notify_crmti_insert()
    RETURNS trigger
    LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM pg_notify('crmti_change', json_build_object(
        'action', 'insert',
        'crmti_id', NEW.crmti_id
    )::text);
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_crmti_notify ON crmti;
CREATE TRIGGER trg_crmti_notify
    AFTER INSERT ON crmti
    FOR EACH ROW
    EXECUTE FUNCTION notify_crmti_insert();
