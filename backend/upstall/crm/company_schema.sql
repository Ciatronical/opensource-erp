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
    keywords TEXT,
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

-- Kürzt eine Telefonnummer von rechts auf n Stellen
-- (löst die 0049/+49/0-Präfix-Problematik beim Vergleich)
CREATE OR REPLACE FUNCTION kuerze(integer, text)
    RETURNS text
    LANGUAGE plpgsql
AS $function$
    DECLARE
        laenge INT;
    BEGIN
        laenge = length( $2 );
        IF laenge <= $1 THEN
            RETURN $2;
        ELSE
            RETURN substring( $2, laenge  - $1 + 1 );
        END IF;
    END;
$function$;

-- Sucht zu einer eingehenden/ausgehenden Telefonnummer den passenden Namen.
-- Durchsucht Kunden, Lieferanten, Kontakte sowie die zusätzlichen Nummern in den
-- JSON-Spalten von customer_ext und vendor_ext.
-- Rueckgabe: record (id, name, typ); typ = C=Kunde, V=Lieferant, K=Kontakt,
-- Y=nicht numerisch, X=nicht gefunden. id=0 bei Y/X.
CREATE OR REPLACE FUNCTION suchenummer(text)
    RETURNS record
    LANGUAGE plpgsql
AS $function$
    DECLARE
        telnum ALIAS FOR $1;
        myname text;
        result record;
        format text;
    BEGIN
        format := '99999999999999999';
        IF telnum !~ '[0-9]' THEN
            SELECT INTO result 0 AS id, telnum AS name, 'Y'::char AS typ;
            return result;
        END IF;
        SELECT INTO result id, name::text, 'C'::char AS typ FROM (SELECT id, name, to_number(phone, format)::char(16) AS p, to_number(phone, format)::char(16) AS f, char_length(to_number(phone, format)::char(16)) AS l, char_length(to_number(phone, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM customer WHERE phone !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        SELECT INTO result id, name::text, 'C'::char AS typ FROM (SELECT id, name, to_number(phone3, format)::char(16) AS p, to_number(phone3, format)::char(16) AS f, char_length(to_number(phone3, format)::char(16)) AS l, char_length(to_number(phone3, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM customer WHERE phone3 !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        SELECT INTO result id, name::text, 'C'::char AS typ FROM (SELECT id, name, to_number(fax, format)::char(16) AS p, to_number(fax, format)::char(16) AS f, char_length(to_number(fax, format)::char(16)) AS l, char_length(to_number(fax, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM customer WHERE fax !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        -- Zusätzliche Telefonnummern aus customer_ext (JSON-Array [{"label":..,"number":..}])
        SELECT INTO result id, name::text, 'C'::char AS typ FROM (SELECT c.id AS id, c.name AS name, to_number(e.elem->>'number', format)::char(16) AS p, char_length(to_number(e.elem->>'number', format)::char(16)) AS l, char_length(to_number(telnum, format)::char(16)) AS lt FROM customer_ext ce JOIN customer c ON c.id = ce.customer_id CROSS JOIN LATERAL jsonb_array_elements(ce.phone_numbers) AS e(elem) WHERE jsonb_typeof(ce.phone_numbers) = 'array' AND (e.elem->>'number') ~ '[0-9]') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        SELECT INTO result id, name AS name, 'V'::char AS typ FROM (SELECT id, name, to_number(phone, format)::char(16) AS p, to_number(phone, format)::char(16) AS f, char_length(to_number(phone, format)::char(16)) AS l, char_length(to_number(phone, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM vendor WHERE phone !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        SELECT INTO result id, name AS name, 'V'::char AS typ FROM (SELECT id, name, to_number(phone3, format)::char(16) AS p, to_number(phone3, format)::char(16) AS f, char_length(to_number(phone3, format)::char(16)) AS l, char_length(to_number(phone3, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM vendor WHERE phone3 !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        SELECT INTO result id, name, 'V'::char AS typ FROM (SELECT id, name, to_number(fax, format)::char(16) AS p, to_number(fax, format)::char(16) AS f, char_length(to_number(fax, format)::char(16)) AS l, char_length(to_number(fax, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM vendor WHERE fax !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        -- Zusätzliche Telefonnummern aus vendor_ext (JSON-Array [{"label":..,"number":..}])
        SELECT INTO result id, name::text, 'V'::char AS typ FROM (SELECT v.id AS id, v.name AS name, to_number(e.elem->>'number', format)::char(16) AS p, char_length(to_number(e.elem->>'number', format)::char(16)) AS l, char_length(to_number(telnum, format)::char(16)) AS lt FROM vendor_ext ve JOIN vendor v ON v.id = ve.vendor_id CROSS JOIN LATERAL jsonb_array_elements(ve.phone_numbers) AS e(elem) WHERE jsonb_typeof(ve.phone_numbers) = 'array' AND (e.elem->>'number') ~ '[0-9]') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        SELECT INTO result id, name, 'K'::char AS typ FROM (SELECT cp_id AS id, (cp_givenname||' '||cp_name)::text AS name, to_number(cp_phone1, format)::char(16) AS p, to_number(cp_phone1, format)::char(16) AS f, char_length(to_number(cp_phone1, format)::char(16)) AS l, char_length(to_number(cp_phone1, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM contacts WHERE cp_phone1 !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        SELECT INTO result id, name, 'K'::char AS typ FROM (SELECT cp_id AS id, (cp_givenname||' '||cp_name)::text AS name, to_number(cp_phone2, format)::char(16) AS p, to_number(cp_phone2, format)::char(16) AS f, char_length(to_number(cp_phone2, format)::char(16)) AS l, char_length(to_number(cp_phone2, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM contacts WHERE cp_phone2 !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        SELECT INTO result id, name, 'K'::char AS typ FROM (SELECT cp_id AS id, (cp_givenname||' '||cp_name)::text AS name, to_number(cp_mobile1, format)::char(16) AS p, to_number(cp_mobile1, format)::char(16) AS f, char_length(to_number(cp_mobile1, format)::char(16)) AS l, char_length(to_number(cp_mobile1, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM contacts WHERE cp_mobile1 !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        SELECT INTO result id, name, 'K'::char AS typ FROM (SELECT cp_id AS id, (cp_givenname||' '||cp_name)::text AS name, to_number(cp_mobile2, format)::char(16) AS p, to_number(cp_mobile2, format)::char(16) AS f, char_length(to_number(cp_mobile2, format)::char(16)) AS l, char_length(to_number(cp_mobile2, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM contacts WHERE cp_mobile2 !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        SELECT INTO result id, name, 'K'::char AS typ FROM (SELECT cp_id AS id, (cp_givenname||' '||cp_name)::text AS name, to_number(cp_privatphone, format)::char(16) AS p, to_number(cp_privatphone, format)::char(16) AS f, char_length(to_number(cp_privatphone, format)::char(16)) AS l, char_length(to_number(cp_privatphone, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM contacts WHERE cp_privatphone !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        SELECT INTO result id, name, 'K'::char AS typ FROM (SELECT cp_id AS id, (cp_givenname||' '||cp_name)::text AS name, to_number(cp_fax, format)::char(16) AS p, to_number(cp_fax, format)::char(16) AS f, char_length(to_number(cp_fax, format)::char(16)) AS l, char_length(to_number(cp_fax, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM contacts WHERE cp_fax !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        SELECT INTO result 0 AS id, telnum AS name, 'X'::char AS typ;
        return result;
    END;
$function$;

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

-- Schreibt den Anruf-Status (Asterisk DIALSTATUS) in die crmti-Zeile.
-- Wird aus extensions.ael NACH dem Dial() via ODBC_CALLSTATUS aufgerufen, da der
-- DIALSTATUS erst nach dem Wählversuch feststeht (CallIn/CallOut laufen davor).
-- Status != 'ANSWERED' bedeutet: nicht angenommen (eingehend verpasst bzw.
-- ausgehend nicht erreicht) — das Frontend färbt solche Anrufe rot.
-- Das erneute pg_notify aktualisiert Anrufliste/Info-Bar in Echtzeit.
CREATE OR REPLACE FUNCTION callstatus(
    text,
    text)
    RETURNS text
    LANGUAGE 'plpgsql'
    COST 100
    VOLATILE PARALLEL UNSAFE
AS $BODY$
    DECLARE
        p_call_id ALIAS FOR $1;
        p_status  ALIAS FOR $2;
        new_row crmti%rowtype;
    BEGIN
        UPDATE crmti SET crmti_status = p_status
         WHERE crmti.unique_call_id = p_call_id
         RETURNING * INTO new_row;
        IF FOUND THEN
            PERFORM pg_notify('crmti_change', to_json(new_row)::TEXT);
        END IF;
        return p_status;
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
    freq        TEXT,
    interval    INTEGER DEFAULT 1,
    count       INTEGER,
    repeat_end  TIMESTAMP WITHOUT TIME ZONE,
    byweekday   TEXT,
    duration    TEXT,
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
COMMENT ON COLUMN calendar_events.freq IS 'Wiederholungsfrequenz: daily, weekly, monthly, yearly';
COMMENT ON COLUMN calendar_events.interval IS 'Intervall-Multiplikator (z.B. 58 für alle 58 Tage)';
COMMENT ON COLUMN calendar_events.count IS 'Gesamtanzahl Wiederholungen';
COMMENT ON COLUMN calendar_events.repeat_end IS 'Endzeitpunkt der Terminserie (dtstart + count * interval)';
COMMENT ON COLUMN calendar_events.byweekday IS 'Wochentage für wöchentliche Wiederholung (RRule-Format)';
COMMENT ON COLUMN calendar_events.duration IS 'Termindauer im Format HH:MM';
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
    hidden BOOLEAN NOT NULL DEFAULT FALSE,
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

-- ============================================================================
-- SPRACHNOTIZEN (Telegram-Sprachnachricht -> Whisper -> Anschlagtafel)
-- ============================================================================

CREATE TABLE IF NOT EXISTS voice_notes (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    telegram_message_id TEXT,
    telegram_chat_id TEXT,
    sender_name TEXT,
    employee_id INTEGER,
    audio_file TEXT,
    duration NUMERIC(8,2),
    transcript TEXT,
    language TEXT,
    status TEXT NOT NULL DEFAULT 'transcribed',
    itime TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    mtime TIMESTAMPTZ,
    hidden BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT voice_notes_telegram_msg_unique UNIQUE (telegram_message_id)
);

COMMENT ON TABLE voice_notes IS 'Per Telegram eingesprochene Sprachnotizen, server-seitig via Whisper transkribiert, Anzeige auf der Anschlagtafel';
COMMENT ON COLUMN voice_notes.telegram_message_id IS 'Telegram message_id zur Idempotenz (Webhook kann doppelt zustellen)';
COMMENT ON COLUMN voice_notes.telegram_chat_id IS 'Telegram-Chat-ID des Absenders (Zuordnung Mitarbeiter)';
COMMENT ON COLUMN voice_notes.sender_name IS 'Anzeigename des Absenders (aus Mapping oder Telegram-Profil)';
COMMENT ON COLUMN voice_notes.employee_id IS 'Zugeordneter Mitarbeiter (optional)';
COMMENT ON COLUMN voice_notes.audio_file IS 'Relativer Pfad zur Audiodatei ab data/<dbname>/';
COMMENT ON COLUMN voice_notes.duration IS 'Audiolaenge in Sekunden';
COMMENT ON COLUMN voice_notes.transcript IS 'Whisper-Transkript';
COMMENT ON COLUMN voice_notes.status IS 'transcribed, failed';

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_voice_notes_itime') THEN
        CREATE INDEX idx_voice_notes_itime ON voice_notes(itime DESC);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_voice_notes_chat') THEN
        CREATE INDEX idx_voice_notes_chat ON voice_notes(telegram_chat_id);
    END IF;
END $$;

-- Trigger: pg_notify bei neuer Sprachnotiz (SSE -> Anschlagtafel live)
CREATE OR REPLACE FUNCTION notify_voice_note() RETURNS trigger AS $$
BEGIN
    -- Nur sichtbare Notizen auf die Anschlagtafel schieben. Ausgeblendete
    -- Zeilen (z.B. Kommando-Nachrichten mit hidden=true) loesen kein Event aus.
    IF NEW.hidden = FALSE THEN
        PERFORM pg_notify('voicenote_change', json_build_object(
            'action', 'new',
            'id', NEW.id,
            'sender_name', NEW.sender_name,
            'transcript', NEW.transcript,
            'duration', NEW.duration,
            'status', NEW.status,
            'itime', NEW.itime
        )::TEXT);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'voice_notes_notify') THEN
        CREATE TRIGGER voice_notes_notify
            AFTER INSERT ON voice_notes
            FOR EACH ROW
            EXECUTE FUNCTION notify_voice_note();
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

-- Weroni Chat-Template (muss bei Meta eingereicht und genehmigt werden)
INSERT INTO whatsapp_templates (name, display_name, category, language, body_text, status, template_type, example_values)
VALUES (
    'weroni_nachricht',
    'Weroni — Allgemeine Nachricht',
    'UTILITY',
    'de',
    'Hallo! Hier ist eine Nachricht von uns: {{1}}',
    'draft',
    'chat',
    '{"1": "Ihr Termin am Montag um 10 Uhr ist bestätigt."}'
) ON CONFLICT (name, language) DO NOTHING;

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

-- ============================================================================
-- DHL VERSAND
-- ============================================================================

CREATE TABLE IF NOT EXISTS dhl_shipments (
    id integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type text NOT NULL,
    record_id integer NOT NULL,
    shipment_no text NOT NULL,
    product text,
    weight numeric(10,2),
    label_pdf bytea,
    created_at timestamp without time zone DEFAULT now(),
    created_by integer
);

COMMENT ON TABLE dhl_shipments IS 'DHL Versandetiketten und Sendungsnummern';
COMMENT ON COLUMN dhl_shipments.record_type IS 'Belegtyp (invoice, order, delivery_order)';
COMMENT ON COLUMN dhl_shipments.record_id IS 'ID des zugehörigen Belegs';
COMMENT ON COLUMN dhl_shipments.shipment_no IS 'DHL Sendungsnummer (Tracking)';
COMMENT ON COLUMN dhl_shipments.product IS 'DHL Produkt (V01PAK, V53WPAK, V62WP)';
COMMENT ON COLUMN dhl_shipments.weight IS 'Paketgewicht in kg';
COMMENT ON COLUMN dhl_shipments.label_pdf IS 'Versandetikett als PDF (binär)';
COMMENT ON COLUMN dhl_shipments.created_at IS 'Erstellungszeitpunkt';
COMMENT ON COLUMN dhl_shipments.created_by IS 'Erstellt von (employee_id)';

CREATE INDEX IF NOT EXISTS idx_dhl_shipments_record ON dhl_shipments (record_type, record_id);

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

-- ----------------------------------------------------------------------------
-- Backfill: Wird ein Kunde/Lieferant NACH einem Anruf angelegt (oder dessen
-- Rufnummer geaendert), waren die zurueckliegenden crmti-Zeilen bis dahin
-- "unbekannt" (crmti_caller_typ = 'X'/'Y', crmti_caller_id = 0) und blieben es
-- auch nach Reload, weil die Namensaufloesung nur einmalig zum Anrufzeitpunkt
-- lief. Dieser Trigger loest die noch unaufgeloesten Zeilen erneut ueber
-- SucheNummer auf und aktualisiert Kennung + Anzeigename. Danach zeigt die
-- Anrufliste den neuen Kunden/Lieferanten korrekt an.
--
-- Laeuft nur ueber die (per Pruning ~512 Zeilen kleine) Menge unaufgeloester
-- Anrufe und nur beim Anlegen bzw. bei Rufnummern-Aenderung — daher guenstig.
-- pg_notify('crmti_change', ...) aktualisiert offene Anruflisten live.
CREATE OR REPLACE FUNCTION backfill_crmti_caller()
    RETURNS trigger
    LANGUAGE plpgsql
AS $$
DECLARE
    r      record;
    treffer record;
BEGIN
    FOR r IN
        SELECT crmti_id, crmti_number, crmti_direction
        FROM crmti
        WHERE (crmti_caller_typ IN ('X', 'Y') OR crmti_caller_id = 0 OR crmti_caller_id IS NULL)
          AND crmti_number ~ '[0-9]'
    LOOP
        treffer := SucheNummer(r.crmti_number);
        IF treffer.typ IN ('C', 'V') AND treffer.id <> 0 THEN
            UPDATE crmti
               SET crmti_caller_id  = treffer.id,
                   crmti_caller_typ = treffer.typ,
                   crmti_src = CASE WHEN crmti_direction = 'E' THEN treffer.name ELSE crmti_src END,
                   crmti_dst = CASE WHEN crmti_direction = 'A' THEN treffer.name ELSE crmti_dst END
             WHERE crmti_id = r.crmti_id;
            PERFORM pg_notify('crmti_change', json_build_object(
                'action', 'backfill',
                'crmti_id', r.crmti_id
            )::text);
        END IF;
    END LOOP;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS trg_customer_backfill_crmti ON customer;
CREATE TRIGGER trg_customer_backfill_crmti
    AFTER INSERT OR UPDATE OF phone, phone3, fax ON customer
    FOR EACH ROW
    EXECUTE FUNCTION backfill_crmti_caller();

DROP TRIGGER IF EXISTS trg_vendor_backfill_crmti ON vendor;
CREATE TRIGGER trg_vendor_backfill_crmti
    AFTER INSERT OR UPDATE OF phone, phone3, fax ON vendor
    FOR EACH ROW
    EXECUTE FUNCTION backfill_crmti_caller();

-- =============================================================================
-- Weroni — KI-Bürokauffrau (Autonomer Agent)
-- =============================================================================

CREATE TABLE IF NOT EXISTS weroni_memory (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    category        TEXT NOT NULL CHECK (category IN ('person', 'preference', 'process', 'lesson', 'fact', 'contact')),
    subject         TEXT NOT NULL,
    content         TEXT NOT NULL,
    importance      INTEGER DEFAULT 5 CHECK (importance BETWEEN 1 AND 10),
    source          TEXT,
    related_id      INTEGER,
    related_type    TEXT,
    created_at      TIMESTAMP DEFAULT NOW(),
    updated_at      TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_weroni_memory_category ON weroni_memory (category);
CREATE INDEX IF NOT EXISTS idx_weroni_memory_subject ON weroni_memory USING gin (to_tsvector('german', subject || ' ' || content));

CREATE TABLE IF NOT EXISTS weroni_tasks (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    parent_id       INTEGER REFERENCES weroni_tasks(id) ON DELETE CASCADE,
    title           TEXT NOT NULL,
    description     TEXT,
    status          TEXT DEFAULT 'open' CHECK (status IN ('open', 'pending_confirm', 'in_progress', 'waiting', 'done', 'cancelled')),
    priority        INTEGER DEFAULT 5 CHECK (priority BETWEEN 1 AND 10),
    due_date        TIMESTAMP,
    due_reminder    TIMESTAMP,
    assigned_by     TEXT,
    assigned_to     TEXT,
    recurrence      TEXT,
    recurrence_time TIME,
    tags            TEXT[],
    source          TEXT,               -- 'email', 'whatsapp', 'call', 'manual', 'monitor'
    source_ref      TEXT,               -- Referenz (email_subject, phone_number, wa_message_id)
    auto_action     JSONB,              -- Geplante Aktion die bei Bestätigung ausgeführt wird
    created_at      TIMESTAMP DEFAULT NOW(),
    updated_at      TIMESTAMP DEFAULT NOW(),
    completed_at    TIMESTAMP
);


-- Weroni Verarbeitungsstatus: Was wurde schon gelesen/verarbeitet
CREATE TABLE IF NOT EXISTS weroni_monitor_state (
    key             TEXT PRIMARY KEY,
    value           TEXT NOT NULL,
    updated_at      TIMESTAMP DEFAULT NOW()
);

INSERT INTO defaults_oserp (key, value) VALUES ('weroni_monitor_interval', '5') ON CONFLICT (key) DO NOTHING;

CREATE INDEX IF NOT EXISTS idx_weroni_tasks_status ON weroni_tasks (status);
CREATE INDEX IF NOT EXISTS idx_weroni_tasks_parent ON weroni_tasks (parent_id);
CREATE INDEX IF NOT EXISTS idx_weroni_tasks_due ON weroni_tasks (due_date) WHERE status NOT IN ('done', 'cancelled');

CREATE TABLE IF NOT EXISTS weroni_actions (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    action_type     TEXT NOT NULL,
    description     TEXT NOT NULL,
    input_data      JSONB,
    output_data     JSONB,
    status          TEXT DEFAULT 'success' CHECK (status IN ('success', 'failed', 'pending', 'cancelled')),
    error_message   TEXT,
    lesson_learned  TEXT,
    employee_id     INTEGER,
    created_at      TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_weroni_actions_type ON weroni_actions (action_type);
CREATE INDEX IF NOT EXISTS idx_weroni_actions_created ON weroni_actions (created_at DESC);

CREATE TABLE IF NOT EXISTS weroni_conversations (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    session_id      TEXT NOT NULL,
    role            TEXT NOT NULL CHECK (role IN ('user', 'assistant', 'system', 'tool_result')),
    content         TEXT NOT NULL,
    tool_calls      JSONB,
    employee_id     INTEGER,
    created_at      TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_weroni_conv_session ON weroni_conversations (session_id, id);

CREATE TABLE IF NOT EXISTS weroni_questions (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    question        TEXT NOT NULL,
    context         TEXT,
    context_data    JSONB,
    urgency         INTEGER DEFAULT 5 CHECK (urgency BETWEEN 1 AND 10),
    status          TEXT DEFAULT 'pending' CHECK (status IN ('pending', 'answered', 'dismissed', 'auto_resolved')),
    answer          TEXT,
    answered_by     INTEGER,
    created_at      TIMESTAMP DEFAULT NOW(),
    answered_at     TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_weroni_questions_status ON weroni_questions (status) WHERE status = 'pending';

INSERT INTO defaults_oserp (key, value) VALUES ('weroni_enabled', 'false') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('weroni_mode', 'assistant') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('weroni_system_prompt', 'Du bist Weroni, die KI-Bürokauffrau. Du bist freundlich, effizient und denkst mit. Du kümmerst dich um Emails, WhatsApp-Nachrichten, Anrufe, Termine, Aufgaben und Bestellungen. Du lernst aus Fehlern und merkst dir wichtige Informationen. Du sprichst Deutsch und bist Teil des Teams.') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('weroni_phone_number', '') ON CONFLICT (key) DO NOTHING;

-- Weroni Dokumenten-Eingang (gescannte/fotografierte Dokumente)
CREATE TABLE IF NOT EXISTS weroni_documents (
    id              INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    original_name   TEXT NOT NULL,
    mime_type       TEXT NOT NULL,
    file_path       TEXT,                   -- Pfad im Kunden-/Lieferantenordner nach Zuordnung
    doc_type        TEXT,                   -- 'invoice', 'delivery_note', 'letter', 'receipt', 'contract', 'other'
    summary         TEXT,                   -- KI-Zusammenfassung des Inhalts
    extracted_data  JSONB,                  -- Extrahierte Felder (Betrag, Datum, Rechnungsnr, etc.)
    customer_id     INTEGER,
    vendor_id       INTEGER,
    order_id        INTEGER,
    status          TEXT DEFAULT 'pending' CHECK (status IN ('pending', 'analyzed', 'filed', 'rejected')),
    created_at      TIMESTAMP DEFAULT NOW(),
    filed_at        TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_weroni_documents_status ON weroni_documents (status);

CREATE OR REPLACE FUNCTION notify_weroni_question() RETURNS trigger AS $$
BEGIN
    PERFORM pg_notify('weroni_question', json_build_object(
        'action', TG_OP,
        'id', NEW.id,
        'urgency', NEW.urgency
    )::TEXT);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'weroni_question_notify') THEN
        CREATE TRIGGER weroni_question_notify
            AFTER INSERT ON weroni_questions
            FOR EACH ROW
            WHEN (NEW.status = 'pending')
            EXECUTE FUNCTION notify_weroni_question();
    END IF;
END $$;

-- ============================================================================
-- BANKING
-- ============================================================================

CREATE TABLE bank_account_fints (
    id              integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bank_account_id integer NOT NULL,
    fints_url       text NOT NULL,
    fints_bank_code varchar(20) NOT NULL,
    fints_username  text NOT NULL,
    fints_tan_mode  varchar(50),
    last_sync       timestamp,
    sync_from_date  date,
    persisted_state text,
    itime           timestamp DEFAULT now(),
    mtime           timestamp
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_bank_account_fints_account ON bank_account_fints(bank_account_id);

CREATE TABLE bank_import_log (
    id              integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bank_account_id integer NOT NULL,
    import_date     timestamp DEFAULT now(),
    from_date       date,
    to_date         date,
    transaction_count integer DEFAULT 0,
    employee_id     integer,
    import_source   varchar(20) DEFAULT 'fints'
);

CREATE TABLE bank_transfer_orders (
    id                     integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bank_account_id        integer NOT NULL,
    recipient_type         varchar(10),
    recipient_id           integer,
    remote_iban            varchar(40) NOT NULL,
    remote_bic             varchar(20),
    remote_name            text NOT NULL,
    amount                 numeric(15,5) NOT NULL,
    currency               varchar(5) DEFAULT 'EUR',
    purpose                text NOT NULL,
    execution_date         date,
    instant                boolean DEFAULT false,
    batch_id               varchar(40),
    engine                 varchar(10) DEFAULT 'php',
    status                 varchar(20) DEFAULT 'draft',
    source_type            varchar(20),
    source_id              integer,
    employee_id            integer,
    error_message          text,
    submitted_at           timestamp,
    executed_at            timestamp,
    matched_transaction_id integer,
    expired_at             timestamp,
    itime                  timestamp DEFAULT now(),
    mtime                  timestamp
);

CREATE INDEX IF NOT EXISTS idx_bank_transfer_orders_status ON bank_transfer_orders(status);
CREATE INDEX IF NOT EXISTS idx_bank_transfer_orders_account ON bank_transfer_orders(bank_account_id);

-- Ueberweisungsvorlagen-Gruppen (z.B. "Lohn Autoprofis", "Lieferanten April")
CREATE TABLE IF NOT EXISTS transfer_template_groups (
    id          serial PRIMARY KEY,
    name        varchar(100) NOT NULL,
    description text,
    color       varchar(30)  DEFAULT 'primary',
    sort_order  integer      DEFAULT 0,
    itime       timestamp    DEFAULT now()
);

-- Ueberweisungsvorlagen mit Platzhalter-Unterstuetzung im Verwendungszweck.
-- Platzhalter: {MM/JJJJ}, {MM}, {JJJJ}, {DATUM}, {MONAT}
-- Werden beim Anlegen der Ueberweisung client-seitig aufgeloest.
CREATE TABLE IF NOT EXISTS transfer_templates (
    id               serial PRIMARY KEY,
    group_id         integer      REFERENCES transfer_template_groups(id) ON DELETE SET NULL,
    bank_account_id  integer      REFERENCES bank_accounts(id),
    recipient_type   varchar(20),
    recipient_id     integer,
    remote_name      varchar(140) NOT NULL,
    remote_iban      varchar(34)  NOT NULL,
    remote_bic       varchar(11),
    amount           numeric(12,2) NOT NULL,
    purpose_template varchar(140) NOT NULL,
    name             varchar(100),
    sort_order       integer      DEFAULT 0,
    active           boolean      DEFAULT true,
    itime            timestamp    DEFAULT now(),
    mtime            timestamp
);
CREATE INDEX IF NOT EXISTS idx_transfer_templates_group   ON transfer_templates(group_id);
CREATE INDEX IF NOT EXISTS idx_transfer_templates_account ON transfer_templates(bank_account_id);

-- Dauerauftraege: wiederkehrende Zahlungsvorlagen. Beim Ausfuehren wird ein
-- normaler bank_transfer_order erzeugt und via FinTS gesendet.
CREATE TABLE IF NOT EXISTS standing_orders (
    id                  serial PRIMARY KEY,
    bank_account_id     integer NOT NULL REFERENCES bank_accounts(id),
    recipient_type      varchar(20),              -- customer | vendor | other
    recipient_id        integer,
    remote_name         varchar(140) NOT NULL,
    remote_iban         varchar(34)  NOT NULL,
    remote_bic          varchar(11),
    amount              numeric(12,2) NOT NULL,
    purpose             varchar(140) NOT NULL,
    frequency           varchar(20)  NOT NULL,    -- monthly | quarterly | yearly | weekly
    execution_day       integer,                  -- Tag im Monat (1-31) oder Wochentag (1-7)
    start_date          date         NOT NULL,
    end_date            date,
    status              varchar(20)  DEFAULT 'active',  -- active | paused | ended
    last_executed_date  date,
    next_execution_date date,
    employee_id         integer,
    note                text,
    itime               timestamp    DEFAULT now(),
    mtime               timestamp
);
CREATE INDEX IF NOT EXISTS idx_standing_orders_account ON standing_orders(bank_account_id);
CREATE INDEX IF NOT EXISTS idx_standing_orders_next    ON standing_orders(next_execution_date) WHERE status = 'active';

-- Einzugsermächtigungen: SEPA-Lastschrift-Mandate, die Dritte (Gläubiger)
-- berechtigen, vom eigenen Konto einzuziehen. Reines Register, kein FinTS.
CREATE TABLE IF NOT EXISTS sepa_mandates (
    id                  serial PRIMARY KEY,
    bank_account_id     integer REFERENCES bank_accounts(id),
    mandate_reference   varchar(35)  NOT NULL,    -- Mandatsreferenz des Gläubigers
    creditor_name       varchar(140) NOT NULL,
    creditor_id         varchar(35),              -- Gläubiger-ID (AT-02)
    creditor_iban       varchar(34),
    creditor_bic        varchar(11),
    max_amount          numeric(12,2),            -- optionaler Höchstbetrag
    purpose             varchar(140),
    mandate_type        varchar(10)  DEFAULT 'RCUR',  -- RCUR | FRST | FNAL | OOFF
    issued_date         date         NOT NULL,
    first_collection    date,
    last_collection     date,
    revoked_date        date,
    status              varchar(20)  DEFAULT 'active',  -- active | revoked
    note                text,
    employee_id         integer,
    itime               timestamp    DEFAULT now(),
    mtime               timestamp
);
CREATE INDEX IF NOT EXISTS idx_sepa_mandates_account ON sepa_mandates(bank_account_id);

-- SEPA-Lastschrift-Auftraege (Einzuege vom Kundenkonto)
CREATE TABLE IF NOT EXISTS sepa_direct_debit_orders (
    id                  serial PRIMARY KEY,
    bank_account_id     integer NOT NULL REFERENCES bank_accounts(id),
    mandate_id          integer REFERENCES sepa_mandates(id) ON DELETE SET NULL,
    debtor_name         varchar(140) NOT NULL,
    debtor_iban         varchar(34)  NOT NULL,
    debtor_bic          varchar(11),
    mandate_reference   varchar(35)  NOT NULL,
    creditor_id         varchar(35),
    collection_date     date         NOT NULL,
    amount              numeric(12,2) NOT NULL,
    purpose             varchar(140) NOT NULL,
    sequence_type       varchar(10)  DEFAULT 'RCUR',  -- FRST|RCUR|FNAL|OOFF
    status              varchar(20)  DEFAULT 'draft', -- draft|pending_tan|submitted|executed|rejected
    batch_id            varchar(50),
    error_message       text,
    submitted_at        timestamp,
    employee_id         integer,
    itime               timestamp    DEFAULT now(),
    mtime               timestamp
);
CREATE INDEX IF NOT EXISTS idx_sdd_account ON sepa_direct_debit_orders(bank_account_id);
CREATE INDEX IF NOT EXISTS idx_sdd_status  ON sepa_direct_debit_orders(status);

-- Pre-Booking-Mapping fuer Bankabstimmung. Die kivitendo-Tabelle
-- bank_transaction_acc_trans verlangt acc_trans_id NOT NULL — sie eignet sich
-- nur fuer bereits gebuchte Zuordnungen. Diese Tabelle haelt die Zuordnung
-- zwischen Match-Klick und Buchung-Klick. Beim Buchen wird der Eintrag
-- konsumiert und in bank_transaction_acc_trans uebersetzt.
CREATE TABLE bank_transaction_matches (
    bank_transaction_id integer PRIMARY KEY,
    target_type         varchar(10) NOT NULL CHECK (target_type IN ('ar', 'ap')),
    target_id           integer NOT NULL,
    matched_at          timestamp DEFAULT now(),
    matched_by          integer
);

CREATE TABLE bank_matching_rules (
    id                  integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bank_account_id     integer,
    rule_name           text NOT NULL,
    priority            integer DEFAULT 100,
    match_remote_iban   varchar(40),
    match_remote_name   text,
    match_purpose       text,
    match_amount_min    numeric(15,5),
    match_amount_max    numeric(15,5),
    match_booking_key   varchar(10),
    action_type         varchar(20) NOT NULL,
    action_customer_id  integer,
    action_vendor_id    integer,
    action_chart_id     integer,
    active              boolean DEFAULT true,
    itime               timestamp DEFAULT now()
);

ALTER TABLE bank_transactions ADD COLUMN IF NOT EXISTS match_status text DEFAULT 'unmatched';
ALTER TABLE bank_transactions ADD COLUMN IF NOT EXISTS remote_iban varchar(40);
CREATE INDEX IF NOT EXISTS idx_bank_transactions_match_status ON bank_transactions(match_status);
CREATE INDEX IF NOT EXISTS idx_bank_transactions_remote_iban ON bank_transactions(remote_iban);
CREATE INDEX IF NOT EXISTS idx_bank_transactions_transdate ON bank_transactions(transdate);

CREATE OR REPLACE FUNCTION bank_auto_match(p_bank_account_id INTEGER)
RETURNS TABLE(
    bank_transaction_id INTEGER,
    match_type TEXT,
    target_type TEXT,
    target_id INTEGER,
    confidence NUMERIC
) AS $$
#variable_conflict use_column
BEGIN
    -- Liefert pro Bankumsatz **maximal einen** Match — den mit hoechster
    -- Konfidenz aus mehreren Strategien. So entstehen keine doppelten
    -- Zuordnungen wenn z. B. Rechnungsnummer im Purpose UND IBAN+Betrag
    -- gleichzeitig passen.
    --
    -- Konfidenz-Stufen:
    --   0.99  Rechnungsnummer in End-to-End-ID (strukturierter SEPA-Wert)
    --   0.98  Rechnungsnummer im Purpose + Empfaenger-IBAN bestaetigt
    --   0.92  IBAN passt + exakter offener Betrag (Toleranz 0,01 EUR)
    --   0.88  Rechnungsnummer im Purpose ohne IBAN-Bestaetigung
    --   0.85  Zahlenwert aus Purpose endet auf ap.invnumber (z. B. RE9016 -> 9016)
    --   0.80  IBAN passt + Kontakt hat genau eine offene Rechnung
    --   0.78  Name passt + exakter offener Betrag (Fallback ohne IBAN)
    --   0.70  Benutzer-Regel
    RETURN QUERY
    SELECT bank_transaction_id, match_type, target_type, target_id, confidence
    FROM (
        SELECT DISTINCT ON (bank_transaction_id)
            bank_transaction_id, match_type, target_type, target_id, confidence
        FROM (
            -- ── EINGAENGE (Kunden zahlen Ausgangsrechnungen) ──────────────────

            -- 0a. End-to-End-ID enthaelt AR-Nummer (hoechste Konfidenz —
            --     strukturierter Wert, kommt direkt aus dem SEPA-Beleg)
            SELECT bt.id AS bank_transaction_id,
                   'end_to_end_id'::TEXT AS match_type,
                   'ar'::TEXT AS target_type,
                   ar.id AS target_id,
                   0.99::NUMERIC AS confidence
            FROM bank_transactions bt
            JOIN ar ON (ar.amount - ar.paid) > 0.01
                    AND length(ar.invnumber) >= 4
                    AND bt.end_to_end_id IS NOT NULL
                    AND bt.end_to_end_id ~ ('(^|[^[:alnum:]])' || ar.invnumber || '($|[^[:alnum:]])')
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND bt.match_status = 'unmatched'
              AND bt.amount > 0

            UNION ALL

            -- 1a. AR-Nummer im Purpose UND Kunden-IBAN passt (zwei Signale)
            SELECT bt.id AS bank_transaction_id,
                   'invnumber_and_iban'::TEXT AS match_type,
                   'ar'::TEXT AS target_type,
                   ar.id AS target_id,
                   0.98::NUMERIC AS confidence
            FROM bank_transactions bt
            JOIN customer c ON c.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
            JOIN ar ON ar.customer_id = c.id
                    AND (ar.amount - ar.paid) > 0.01
                    AND length(ar.invnumber) >= 4
                    AND bt.purpose ~ ('(^|[^[:alnum:]])' || ar.invnumber || '($|[^[:alnum:]])')
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND bt.match_status = 'unmatched'
              AND bt.amount > 0

            UNION ALL

            -- 1b. AR-Nummer im Purpose ohne IBAN — nur eindeutige Treffer.
            --     Wenn die gleiche Nummer bei mehreren offenen ARs vorkommt
            --     (sehr selten), lassen wir den Match weg.
            SELECT bt.id, 'invnumber_in_purpose', 'ar'::TEXT, ar.id, 0.88
            FROM bank_transactions bt
            JOIN ar ON (ar.amount - ar.paid) > 0.01
                    AND length(ar.invnumber) >= 4
                    AND bt.purpose ~ ('(^|[^[:alnum:]])' || ar.invnumber || '($|[^[:alnum:]])')
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND bt.match_status = 'unmatched'
              AND bt.amount > 0

            UNION ALL

            -- 2. Kunden-IBAN + exakter offener Betrag (Toleranz 0,01)
            SELECT bt.id, 'iban_amount_match', 'ar'::TEXT, ar.id, 0.92
            FROM bank_transactions bt
            JOIN customer c ON c.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
            JOIN ar ON ar.customer_id = c.id
                    AND ABS((ar.amount - ar.paid) - bt.amount) < 0.01
                    AND ar.paid < ar.amount
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND bt.match_status = 'unmatched'
              AND bt.amount > 0

            UNION ALL

            -- 3. Kunden-IBAN + Kunde hat genau eine offene Rechnung
            SELECT bt.id, 'iban_single_open', 'ar'::TEXT, ar.id, 0.80
            FROM bank_transactions bt
            JOIN customer c ON c.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
            JOIN LATERAL (
                SELECT id, amount, paid
                FROM ar
                WHERE customer_id = c.id AND (amount - paid) > 0.01
                LIMIT 2
            ) ar_count ON true
            JOIN ar ON ar.id = ar_count.id
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND bt.match_status = 'unmatched'
              AND bt.amount > 0
            GROUP BY bt.id, ar.id
            HAVING count(*) = 1

            UNION ALL

            -- 3b. Name aus remote_name passt zu customer.name + exakter Betrag
            --     Fallback wenn IBAN fehlt (z. B. Sammelauftrag der Bank).
            SELECT bt.id, 'name_amount_match', 'ar'::TEXT, ar.id, 0.78
            FROM bank_transactions bt
            JOIN customer c ON c.name ILIKE '%' || bt.remote_name || '%'
                            OR bt.remote_name ILIKE '%' || c.name || '%'
            JOIN ar ON ar.customer_id = c.id
                    AND ABS((ar.amount - ar.paid) - bt.amount) < 0.01
                    AND ar.paid < ar.amount
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND bt.match_status = 'unmatched'
              AND bt.amount > 0
              AND bt.remote_iban IS NULL
              AND length(bt.remote_name) >= 4

            UNION ALL

            -- ── AUSGAENGE (eigene Zahlungen an Lieferanten) ──────────────────

            -- 0b. End-to-End-ID enthaelt AP-Nummer
            SELECT bt.id, 'end_to_end_id', 'ap'::TEXT, ap.id, 0.99
            FROM bank_transactions bt
            JOIN ap ON (ap.amount - ap.paid) > 0.01
                    AND length(ap.invnumber) >= 4
                    AND bt.end_to_end_id IS NOT NULL
                    AND bt.end_to_end_id ~ ('(^|[^[:alnum:]])' || ap.invnumber || '($|[^[:alnum:]])')
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND bt.match_status = 'unmatched'
              AND bt.amount < 0

            UNION ALL

            -- 4a. AP-Nummer im Purpose UND Lieferanten-IBAN
            SELECT bt.id, 'invnumber_and_iban', 'ap'::TEXT, ap.id, 0.98
            FROM bank_transactions bt
            JOIN vendor v ON v.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
            JOIN ap ON ap.vendor_id = v.id
                    AND (ap.amount - ap.paid) > 0.01
                    AND length(ap.invnumber) >= 4
                    AND bt.purpose ~ ('(^|[^[:alnum:]])' || ap.invnumber || '($|[^[:alnum:]])')
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND bt.match_status = 'unmatched'
              AND bt.amount < 0

            UNION ALL

            -- 4c. Suffix-Match: AP-Nummer ist Suffix einer Zahl im Purpose.
            --     Beispiel: Purpose "Rechnung RE9016", ap.invnumber "9016".
            --     Wir extrahieren alle 4+stelligen Zahlen aus dem Purpose und
            --     pruefen ob eine davon mit der invnumber endet.
            SELECT bt.id, 'invnumber_suffix', 'ap'::TEXT, ap.id, 0.85
            FROM bank_transactions bt
            CROSS JOIN LATERAL regexp_matches(bt.purpose, '\d{4,}', 'g') AS m(token)
            JOIN ap ON (ap.amount - ap.paid) > 0.01
                    AND length(ap.invnumber) >= 4
                    AND m.token[1] LIKE '%' || ap.invnumber
                    AND length(m.token[1]) - length(ap.invnumber) <= 3  -- max 3 Praefix-Zeichen
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND bt.match_status = 'unmatched'
              AND bt.amount < 0

            UNION ALL

            -- 4b. AP-Nummer im Purpose ohne IBAN
            SELECT bt.id, 'invnumber_in_purpose', 'ap'::TEXT, ap.id, 0.88
            FROM bank_transactions bt
            JOIN ap ON (ap.amount - ap.paid) > 0.01
                    AND length(ap.invnumber) >= 4
                    AND bt.purpose ~ ('(^|[^[:alnum:]])' || ap.invnumber || '($|[^[:alnum:]])')
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND bt.match_status = 'unmatched'
              AND bt.amount < 0

            UNION ALL

            -- 5. Lieferanten-IBAN + exakter offener Betrag (Toleranz 0,01)
            SELECT bt.id, 'iban_amount_match', 'ap'::TEXT, ap.id, 0.92
            FROM bank_transactions bt
            JOIN vendor v ON v.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
            JOIN ap ON ap.vendor_id = v.id
                    AND ABS((ap.amount - ap.paid) - ABS(bt.amount)) < 0.01
                    AND ap.paid < ap.amount
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND bt.match_status = 'unmatched'
              AND bt.amount < 0

            UNION ALL

            -- 6. Lieferanten-IBAN + Lieferant hat genau eine offene Rechnung
            SELECT bt.id, 'iban_single_open', 'ap'::TEXT, ap.id, 0.80
            FROM bank_transactions bt
            JOIN vendor v ON v.iban = bt.remote_iban AND bt.remote_iban IS NOT NULL
            JOIN LATERAL (
                SELECT id
                FROM ap
                WHERE vendor_id = v.id AND (amount - paid) > 0.01
                LIMIT 2
            ) ap_count ON true
            JOIN ap ON ap.id = ap_count.id
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND bt.match_status = 'unmatched'
              AND bt.amount < 0
            GROUP BY bt.id, ap.id
            HAVING count(*) = 1

            UNION ALL

            -- 7. Benutzer-Regeln
            SELECT bt.id, 'user_rule',
                   CASE bmr.action_type
                       WHEN 'assign_customer' THEN 'customer'
                       WHEN 'assign_vendor'   THEN 'vendor'
                       WHEN 'assign_chart'    THEN 'chart'
                       WHEN 'ignore'          THEN 'ignore'
                   END::TEXT,
                   COALESCE(bmr.action_customer_id, bmr.action_vendor_id, bmr.action_chart_id, 0),
                   0.70
            FROM bank_transactions bt
            JOIN bank_matching_rules bmr ON bmr.active
                AND (bmr.bank_account_id IS NULL OR bmr.bank_account_id = bt.local_bank_account_id)
                AND (bmr.match_remote_iban IS NULL OR bt.remote_iban = bmr.match_remote_iban)
                AND (bmr.match_remote_name IS NULL OR bt.remote_name ILIKE '%' || bmr.match_remote_name || '%')
                AND (bmr.match_purpose IS NULL OR bt.purpose ILIKE '%' || bmr.match_purpose || '%')
                AND (bmr.match_amount_min IS NULL OR ABS(bt.amount) >= bmr.match_amount_min)
                AND (bmr.match_amount_max IS NULL OR ABS(bt.amount) <= bmr.match_amount_max)
                AND (bmr.match_booking_key IS NULL OR bt.transaction_code = bmr.match_booking_key)
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND bt.match_status = 'unmatched'
        ) AS all_matches
        ORDER BY bank_transaction_id, confidence DESC
    ) AS best_per_tx
    ORDER BY confidence DESC, bank_transaction_id;
END;
$$ LANGUAGE plpgsql;

-- Drucker: Spalte zum Ausblenden in Faktura
ALTER TABLE printers ADD COLUMN IF NOT EXISTS hide_factura boolean DEFAULT false;

-- ============================================================================
-- KAMERA / VIDEOÜBERWACHUNG (Frigate NVR Integration)
-- ============================================================================

CREATE TABLE camera (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name TEXT NOT NULL,
    cam_key TEXT,
    stream_url TEXT,
    location TEXT,
    active BOOLEAN NOT NULL DEFAULT true,
    sort_order INTEGER NOT NULL DEFAULT 0,
    motion_threshold NUMERIC(4,2) DEFAULT 1.5,
    min_score NUMERIC(3,2) DEFAULT 0.45,
    record_clips BOOLEAN DEFAULT TRUE,
    analysis_fps SMALLINT DEFAULT 2,
    pre_record_secs SMALLINT DEFAULT 3,
    post_record_secs SMALLINT DEFAULT 5,
    itime TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    mtime TIMESTAMP WITHOUT TIME ZONE,
    CONSTRAINT camera_cam_key_unique UNIQUE (cam_key)
);

COMMENT ON TABLE camera IS 'Kamera-Stammdaten';
COMMENT ON COLUMN camera.name IS 'Anzeigename der Kamera (z.B. Lager Eingang)';
COMMENT ON COLUMN camera.cam_key IS 'Eindeutiger Schlüssel der Kamera (Slug, z.B. lager_eingang) — wird als Stream-Name in go2rtc verwendet';
COMMENT ON COLUMN camera.stream_url IS 'RTSP Stream-URL der Kamera';
COMMENT ON COLUMN camera.location IS 'Standortbeschreibung';
COMMENT ON COLUMN camera.active IS 'Kamera aktiv/inaktiv';
COMMENT ON COLUMN camera.sort_order IS 'Sortierreihenfolge in der Übersicht';

CREATE TABLE camera_zone (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    camera_id INTEGER NOT NULL REFERENCES camera(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    zone_key TEXT NOT NULL,
    color TEXT DEFAULT '#FF5722',
    coordinates JSONB,
    itime TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    mtime TIMESTAMP WITHOUT TIME ZONE,
    CONSTRAINT camera_zone_unique UNIQUE (camera_id, zone_key)
);

COMMENT ON TABLE camera_zone IS 'Überwachungszonen pro Kamera';
COMMENT ON COLUMN camera_zone.name IS 'Anzeigename der Zone (z.B. Wareneingang)';
COMMENT ON COLUMN camera_zone.zone_key IS 'Eindeutiger Schlüssel der Zone (Slug)';
COMMENT ON COLUMN camera_zone.color IS 'Farbe für UI-Anzeige (Hex)';

CREATE INDEX idx_camera_zone_camera_id ON camera_zone(camera_id);

CREATE TABLE camera_event (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    event_id TEXT,
    camera_id INTEGER REFERENCES camera(id) ON DELETE SET NULL,
    camera_name TEXT NOT NULL,
    label TEXT NOT NULL,
    zones TEXT[],
    score NUMERIC(4,3),
    snapshot_url TEXT,
    clip_url TEXT,
    started_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    ended_at TIMESTAMP WITHOUT TIME ZONE,
    acknowledged BOOLEAN NOT NULL DEFAULT false,
    acknowledged_by INTEGER,
    notes TEXT,
    itime TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    CONSTRAINT camera_event_id_unique UNIQUE (event_id)
);

COMMENT ON TABLE camera_event IS 'Erkannte Ereignisse des Kamera-Monitors (Personen, Fahrzeuge, Tiere etc.)';
COMMENT ON COLUMN camera_event.event_id IS 'Eindeutige Event-ID (UUID-Prefix, generiert vom camera_monitor)';
COMMENT ON COLUMN camera_event.camera_name IS 'Kamera-Schlüssel (cam_key, auch ohne Stammdaten nutzbar)';
COMMENT ON COLUMN camera_event.label IS 'Erkanntes Objekt (person, car, dog, cat etc.)';
COMMENT ON COLUMN camera_event.zones IS 'Betroffene Zonen als Array';
COMMENT ON COLUMN camera_event.score IS 'Erkennungsgenauigkeit (0.000 - 1.000)';
COMMENT ON COLUMN camera_event.snapshot_url IS 'URL zum Snapshot-Bild';
COMMENT ON COLUMN camera_event.clip_url IS 'URL zum Video-Clip';
COMMENT ON COLUMN camera_event.started_at IS 'Zeitpunkt des Eventbeginns';
COMMENT ON COLUMN camera_event.ended_at IS 'Zeitpunkt des Eventendes';
COMMENT ON COLUMN camera_event.acknowledged IS 'Event wurde vom Benutzer gesehen/bestätigt';
COMMENT ON COLUMN camera_event.acknowledged_by IS 'Employee-ID der Person die bestätigt hat';
COMMENT ON COLUMN camera_event.notes IS 'Optionale Notizen zum Event';

CREATE INDEX idx_camera_event_camera_id ON camera_event(camera_id);
CREATE INDEX idx_camera_event_started_at ON camera_event(started_at DESC);
CREATE INDEX idx_camera_event_label ON camera_event(label);
CREATE INDEX idx_camera_event_acknowledged ON camera_event(acknowledged) WHERE NOT acknowledged;

CREATE TABLE camera_rule (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name TEXT NOT NULL,
    camera_id INTEGER REFERENCES camera(id) ON DELETE CASCADE,
    zone_id INTEGER REFERENCES camera_zone(id) ON DELETE CASCADE,
    labels TEXT[] NOT NULL DEFAULT '{person}',
    time_from TIME,
    time_to TIME,
    days_of_week INTEGER[] DEFAULT '{0,1,2,3,4,5,6}',
    min_score NUMERIC(4,3) DEFAULT 0.700,
    action TEXT NOT NULL DEFAULT 'notify',
    action_config JSONB DEFAULT '{}',
    active BOOLEAN NOT NULL DEFAULT true,
    cooldown_seconds INTEGER NOT NULL DEFAULT 300,
    last_triggered_at TIMESTAMP WITHOUT TIME ZONE,
    itime TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    mtime TIMESTAMP WITHOUT TIME ZONE
);

COMMENT ON TABLE camera_rule IS 'Alarmregeln: wann und wie bei Kamera-Events reagiert wird';
COMMENT ON COLUMN camera_rule.name IS 'Regelname (z.B. Lager nachts - Person)';
COMMENT ON COLUMN camera_rule.camera_id IS 'Kamera (NULL = alle Kameras)';
COMMENT ON COLUMN camera_rule.zone_id IS 'Zone (NULL = alle Zonen der Kamera)';
COMMENT ON COLUMN camera_rule.labels IS 'Erkannte Objekte die triggern (z.B. {person,car})';
COMMENT ON COLUMN camera_rule.time_from IS 'Zeitfenster Beginn (NULL = immer)';
COMMENT ON COLUMN camera_rule.time_to IS 'Zeitfenster Ende';
COMMENT ON COLUMN camera_rule.days_of_week IS 'Wochentage (0=So, 1=Mo, ..., 6=Sa)';
COMMENT ON COLUMN camera_rule.min_score IS 'Mindest-Erkennungsgenauigkeit';
COMMENT ON COLUMN camera_rule.action IS 'Aktion: notify, whatsapp, email, log';
COMMENT ON COLUMN camera_rule.action_config IS 'JSON-Konfiguration für die Aktion (z.B. {"phone": "+49...", "message": "..."})';
COMMENT ON COLUMN camera_rule.active IS 'Regel aktiv/inaktiv';
COMMENT ON COLUMN camera_rule.cooldown_seconds IS 'Mindestabstand zwischen zwei Auslösungen (Sekunden)';
COMMENT ON COLUMN camera_rule.last_triggered_at IS 'Letzter Auslösezeitpunkt (für Cooldown)';

-- =============================================================================
-- BUCHHALTUNG (Weroni Accounting)
-- DATEV-kompatible Buchungsverwaltung mit KI-basierter Belegerfassung
-- =============================================================================

-- pg_trgm fuer Fuzzy-Matching (falls noch nicht vorhanden)
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Hochgeladene Belege (Eingangsrechnungen als PDF)
CREATE TABLE IF NOT EXISTS accounting_documents (
    id              SERIAL PRIMARY KEY,
    original_name   TEXT NOT NULL,
    stored_path     TEXT,
    mime_type       VARCHAR(100) DEFAULT 'application/pdf',
    file_size       INTEGER,
    file_hash       VARCHAR(64),                -- SHA-256 zur Duplikaterkennung

    -- KI-Extraktion
    status          VARCHAR(20) DEFAULT 'pending'
                    CHECK (status IN ('pending', 'processing', 'extracted', 'booked', 'error', 'duplicate')),
    extracted_data  JSONB,                       -- Komplette KI-Extraktion
    extraction_confidence NUMERIC(3,2),          -- 0.00 - 1.00

    -- Verknuepfungen
    vendor_id       INTEGER REFERENCES vendor(id),
    booking_id      INTEGER,                     -- FK wird spaeter gesetzt
    ap_id           INTEGER REFERENCES ap(id),   -- Verknuepfung zur Eingangsrechnung

    -- Meta
    employee_id     INTEGER,
    notes           TEXT,
    itime           TIMESTAMP DEFAULT NOW(),
    mtime           TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_accounting_documents_status ON accounting_documents(status);
CREATE INDEX IF NOT EXISTS idx_accounting_documents_vendor ON accounting_documents(vendor_id);
CREATE INDEX IF NOT EXISTS idx_accounting_documents_hash ON accounting_documents(file_hash);

-- Buchungen (DATEV-kompatibel, mit Freigabe-Workflow)
CREATE TABLE IF NOT EXISTS accounting_bookings (
    id              SERIAL PRIMARY KEY,
    booking_date    DATE NOT NULL,               -- Buchungsdatum
    invoice_date    DATE,                        -- Belegdatum
    due_date        DATE,                        -- Faelligkeitsdatum

    -- DATEV-Pflichtfelder
    amount          NUMERIC(15,5) NOT NULL,      -- Bruttobetrag (immer positiv, Richtung durch type)
    net_amount      NUMERIC(15,5),               -- Nettobetrag
    tax_amount      NUMERIC(15,5),               -- Steuerbetrag
    tax_rate        NUMERIC(5,2),                -- Steuersatz (19, 7, 0)
    tax_key         INTEGER,                     -- DATEV-Steuerschluessel
    debit_account   VARCHAR(10) NOT NULL,        -- Sollkonto (SKR03/04 Kontonummer)
    credit_account  VARCHAR(10) NOT NULL,        -- Habenkonto (SKR03/04 Kontonummer)

    -- Belegdaten
    invoice_number  TEXT,                        -- Rechnungsnummer
    description     TEXT,                        -- Buchungstext
    reference       TEXT,                        -- Belegnummer (BU-Schluessel)

    -- Typ und Status
    type            VARCHAR(20) DEFAULT 'incoming'
                    CHECK (type IN ('incoming', 'outgoing', 'manual', 'bank')),
    status          VARCHAR(20) DEFAULT 'pending'
                    CHECK (status IN ('pending', 'approved', 'booked', 'rejected', 'storno')),
    approved_by     INTEGER,                     -- Employee-ID der Freigabe
    approved_at     TIMESTAMP,

    -- Verknuepfungen
    vendor_id       INTEGER REFERENCES vendor(id),
    customer_id     INTEGER REFERENCES customer(id),
    document_id     INTEGER,                     -- FK zu accounting_documents
    ap_id           INTEGER REFERENCES ap(id),
    ar_id           INTEGER REFERENCES ar(id),
    bank_transaction_id INTEGER,                 -- FK zu bank_transactions
    gl_id           INTEGER REFERENCES gl(id),   -- Verknuepfung zum Hauptbuch

    -- KI-Metadaten
    ai_generated    BOOLEAN DEFAULT FALSE,
    ai_confidence   NUMERIC(3,2),
    ai_notes        TEXT,                        -- KI-Erklaerung der Buchung

    -- Meta
    employee_id     INTEGER,
    cost_center     TEXT,                        -- Kostenstelle
    itime           TIMESTAMP DEFAULT NOW(),
    mtime           TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_accounting_bookings_status ON accounting_bookings(status);
CREATE INDEX IF NOT EXISTS idx_accounting_bookings_date ON accounting_bookings(booking_date);
CREATE INDEX IF NOT EXISTS idx_accounting_bookings_vendor ON accounting_bookings(vendor_id);
CREATE INDEX IF NOT EXISTS idx_accounting_bookings_type ON accounting_bookings(type);
CREATE INDEX IF NOT EXISTS idx_accounting_bookings_document ON accounting_bookings(document_id);


-- ALTER TABLE ist streng verboten in diesem Skript - wird automatisch erzeugt
-- FK von documents zu bookings
--ALTER TABLE accounting_documents
--    ADD CONSTRAINT fk_accounting_documents_booking
--    FOREIGN KEY (booking_id) REFERENCES accounting_bookings(id);

-- Buchungspositionen (bei Rechnungen mit mehreren Zeilen)
CREATE TABLE IF NOT EXISTS accounting_booking_lines (
    id              SERIAL PRIMARY KEY,
    booking_id      INTEGER NOT NULL REFERENCES accounting_bookings(id) ON DELETE CASCADE,
    position        INTEGER DEFAULT 1,
    description     TEXT,
    quantity        NUMERIC(15,5) DEFAULT 1,
    unit_price      NUMERIC(15,5),
    net_amount      NUMERIC(15,5),
    tax_rate        NUMERIC(5,2),
    tax_amount      NUMERIC(15,5),
    gross_amount    NUMERIC(15,5),
    account_number  VARCHAR(10),                 -- Sachkonto fuer diese Position
    cost_center     TEXT,
    itime           TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_accounting_booking_lines_booking ON accounting_booking_lines(booking_id);

-- Lieferanten-Alias (fuer KI-Erkennung bei abweichenden Schreibweisen)
CREATE TABLE IF NOT EXISTS vendor_aliases (
    id              SERIAL PRIMARY KEY,
    vendor_id       INTEGER NOT NULL REFERENCES vendor(id) ON DELETE CASCADE,
    alias_name      TEXT NOT NULL,               -- Alternative Schreibweise
    alias_iban      VARCHAR(40),                 -- Alternative IBAN
    alias_taxnumber TEXT,                        -- Alternative Steuernummer
    default_account VARCHAR(10),                 -- Standardkonto fuer diesen Lieferanten
    itime           TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_vendor_aliases_vendor ON vendor_aliases(vendor_id);
CREATE INDEX IF NOT EXISTS idx_vendor_aliases_name_trgm ON vendor_aliases USING gin (alias_name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_vendor_aliases_iban ON vendor_aliases(alias_iban);

-- Trigram-Index auf vendor.name fuer Fuzzy-Suche
CREATE INDEX IF NOT EXISTS idx_vendor_name_trgm ON vendor USING gin (name gin_trgm_ops);

-- Konten-Zuordnungsregeln (lernt aus bisherigen Buchungen)
CREATE TABLE IF NOT EXISTS accounting_account_rules (
    id              SERIAL PRIMARY KEY,
    vendor_id       INTEGER REFERENCES vendor(id),
    match_pattern   TEXT,                        -- Regex oder Keywords im Buchungstext
    debit_account   VARCHAR(10) NOT NULL,
    credit_account  VARCHAR(10) NOT NULL,
    tax_key         INTEGER,
    priority        INTEGER DEFAULT 100,
    active          BOOLEAN DEFAULT TRUE,
    hit_count       INTEGER DEFAULT 0,           -- Wie oft wurde die Regel angewendet
    itime           TIMESTAMP DEFAULT NOW(),
    mtime           TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_accounting_rules_vendor ON accounting_account_rules(vendor_id);

-- PostgreSQL-Funktion: Fuzzy-Lieferantensuche
CREATE OR REPLACE FUNCTION find_vendor_fuzzy(
    p_name TEXT,
    p_iban TEXT DEFAULT NULL,
    p_taxnumber TEXT DEFAULT NULL,
    p_threshold NUMERIC DEFAULT 0.3
)
RETURNS TABLE (
    vendor_id INTEGER,
    vendor_name TEXT,
    match_score NUMERIC,
    match_type TEXT
) AS $$
BEGIN
    -- 1. Exakter IBAN-Match (hoechste Prioritaet)
    IF p_iban IS NOT NULL AND p_iban != '' THEN
        RETURN QUERY
        SELECT v.id, v.name, 1.0::NUMERIC, 'iban_exact'::TEXT
        FROM vendor v
        WHERE REPLACE(v.iban, ' ', '') = REPLACE(p_iban, ' ', '')
        AND v.obsolete IS NOT TRUE
        LIMIT 1;
        IF FOUND THEN RETURN; END IF;

        -- IBAN in Aliases pruefen
        RETURN QUERY
        SELECT va.vendor_id, v.name, 0.99::NUMERIC, 'iban_alias'::TEXT
        FROM vendor_aliases va
        JOIN vendor v ON v.id = va.vendor_id
        WHERE REPLACE(va.alias_iban, ' ', '') = REPLACE(p_iban, ' ', '')
        AND v.obsolete IS NOT TRUE
        LIMIT 1;
        IF FOUND THEN RETURN; END IF;
    END IF;

    -- 2. Exakter Steuernummer-Match
    IF p_taxnumber IS NOT NULL AND p_taxnumber != '' THEN
        RETURN QUERY
        SELECT v.id, v.name, 0.98::NUMERIC, 'taxnumber'::TEXT
        FROM vendor v
        WHERE (v.taxnumber = p_taxnumber OR v.ustid = p_taxnumber)
        AND v.obsolete IS NOT TRUE
        LIMIT 1;
        IF FOUND THEN RETURN; END IF;
    END IF;

    -- 3. Fuzzy-Name-Match (mit pg_trgm)
    IF p_name IS NOT NULL AND p_name != '' THEN
        RETURN QUERY
        SELECT v.id, v.name,
               GREATEST(
                   similarity(LOWER(p_name), LOWER(v.name)),
                   COALESCE((
                       SELECT MAX(similarity(LOWER(p_name), LOWER(va.alias_name)))
                       FROM vendor_aliases va WHERE va.vendor_id = v.id
                   ), 0)
               )::NUMERIC AS score,
               'fuzzy_name'::TEXT
        FROM vendor v
        WHERE v.obsolete IS NOT TRUE
        AND (
            similarity(LOWER(p_name), LOWER(v.name)) > p_threshold
            OR EXISTS (
                SELECT 1 FROM vendor_aliases va
                WHERE va.vendor_id = v.id
                AND similarity(LOWER(p_name), LOWER(va.alias_name)) > p_threshold
            )
        )
        ORDER BY score DESC
        LIMIT 5;
    END IF;
END;
$$ LANGUAGE plpgsql;

-- PostgreSQL-Funktion: Naechste Buchungsnummer
CREATE OR REPLACE FUNCTION next_booking_number()
RETURNS TEXT AS $$
DECLARE
    v_year TEXT;
    v_next INTEGER;
BEGIN
    v_year := TO_CHAR(NOW(), 'YYYY');
    SELECT COALESCE(MAX(
        CAST(SUBSTRING(reference FROM '\d+$') AS INTEGER)
    ), 0) + 1
    INTO v_next
    FROM accounting_bookings
    WHERE reference LIKE 'BU-' || v_year || '-%';

    RETURN 'BU-' || v_year || '-' || LPAD(v_next::TEXT, 5, '0');
END;
$$ LANGUAGE plpgsql;

-- ============================================================================
-- HR-MODUL: Lohnabrechnung & Urlaubsplanung
-- ============================================================================

CREATE TABLE IF NOT EXISTS hr_salary_settings (
    id                  INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    employee_id         INTEGER NOT NULL REFERENCES employee(id) ON DELETE CASCADE,
    brutto              NUMERIC(12,2) NOT NULL DEFAULT 0,
    steuerklasse        SMALLINT NOT NULL DEFAULT 1 CHECK (steuerklasse BETWEEN 1 AND 6),
    kv_prozent          NUMERIC(5,3) NOT NULL DEFAULT 8.150,
    pv_prozent          NUMERIC(5,3) NOT NULL DEFAULT 1.700,
    rv_prozent          NUMERIC(5,3) NOT NULL DEFAULT 9.300,
    av_prozent          NUMERIC(5,3) NOT NULL DEFAULT 1.300,
    kv_prozent_ag       NUMERIC(5,3) NOT NULL DEFAULT 7.300,
    pv_prozent_ag       NUMERIC(5,3) NOT NULL DEFAULT 1.700,
    rv_prozent_ag       NUMERIC(5,3) NOT NULL DEFAULT 9.300,
    av_prozent_ag       NUMERIC(5,3) NOT NULL DEFAULT 1.300,
    notes               TEXT,
    itime               TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    mtime               TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    UNIQUE(employee_id)
);

COMMENT ON TABLE hr_salary_settings IS 'Gehaltseinstellungen pro Mitarbeiter (AN- und AG-Anteile)';
COMMENT ON COLUMN hr_salary_settings.kv_prozent IS 'Krankenversicherung Arbeitnehmer-Anteil in Prozent';
COMMENT ON COLUMN hr_salary_settings.pv_prozent IS 'Pflegeversicherung Arbeitnehmer-Anteil in Prozent';
COMMENT ON COLUMN hr_salary_settings.rv_prozent IS 'Rentenversicherung Arbeitnehmer-Anteil in Prozent';
COMMENT ON COLUMN hr_salary_settings.av_prozent IS 'Arbeitslosenversicherung Arbeitnehmer-Anteil in Prozent';

CREATE TABLE IF NOT EXISTS hr_payroll_runs (
    id                  INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    year                SMALLINT NOT NULL,
    month               SMALLINT NOT NULL CHECK (month BETWEEN 1 AND 12),
    status              TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'final')),
    notes               TEXT,
    created_by          INTEGER REFERENCES employee(id),
    finalized_by        INTEGER REFERENCES employee(id),
    finalized_at        TIMESTAMP WITHOUT TIME ZONE,
    itime               TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    mtime               TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    UNIQUE(year, month)
);

COMMENT ON TABLE hr_payroll_runs IS 'Lohnabrechnungslaeufe (pro Monat)';
COMMENT ON COLUMN hr_payroll_runs.status IS 'Status: draft=Entwurf, final=Abgeschlossen';

CREATE TABLE IF NOT EXISTS hr_payroll_items (
    id                      INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    run_id                  INTEGER NOT NULL REFERENCES hr_payroll_runs(id) ON DELETE CASCADE,
    employee_id             INTEGER NOT NULL REFERENCES employee(id),
    brutto                  NUMERIC(12,2) NOT NULL DEFAULT 0,
    kv_an                   NUMERIC(12,2) NOT NULL DEFAULT 0,
    pv_an                   NUMERIC(12,2) NOT NULL DEFAULT 0,
    rv_an                   NUMERIC(12,2) NOT NULL DEFAULT 0,
    av_an                   NUMERIC(12,2) NOT NULL DEFAULT 0,
    kv_ag                   NUMERIC(12,2) NOT NULL DEFAULT 0,
    pv_ag                   NUMERIC(12,2) NOT NULL DEFAULT 0,
    rv_ag                   NUMERIC(12,2) NOT NULL DEFAULT 0,
    av_ag                   NUMERIC(12,2) NOT NULL DEFAULT 0,
    lohnsteuer              NUMERIC(12,2) NOT NULL DEFAULT 0,
    kirchensteuer           NUMERIC(12,2) NOT NULL DEFAULT 0,
    solidaritaetszuschlag   NUMERIC(12,2) NOT NULL DEFAULT 0,
    sonstige_abzuege        NUMERIC(12,2) NOT NULL DEFAULT 0,
    sonstige_zulagen        NUMERIC(12,2) NOT NULL DEFAULT 0,
    netto                   NUMERIC(12,2) GENERATED ALWAYS AS (
        brutto
        - kv_an - pv_an - rv_an - av_an
        - lohnsteuer - kirchensteuer - solidaritaetszuschlag
        - sonstige_abzuege
        + sonstige_zulagen
    ) STORED,
    notes                   TEXT,
    itime                   TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    mtime                   TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    UNIQUE(run_id, employee_id)
);

COMMENT ON TABLE hr_payroll_items IS 'Lohnabrechnungspositionen pro Mitarbeiter und Laufdatum';
COMMENT ON COLUMN hr_payroll_items.netto IS 'Nettolohn (wird automatisch berechnet)';

CREATE INDEX IF NOT EXISTS idx_hr_payroll_items_run ON hr_payroll_items(run_id);
CREATE INDEX IF NOT EXISTS idx_hr_payroll_items_employee ON hr_payroll_items(employee_id);

CREATE TABLE IF NOT EXISTS hr_vacation_entitlements (
    id                  INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    employee_id         INTEGER NOT NULL REFERENCES employee(id) ON DELETE CASCADE,
    year                SMALLINT NOT NULL,
    days_total          NUMERIC(4,1) NOT NULL DEFAULT 30,
    itime               TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    mtime               TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    UNIQUE(employee_id, year)
);

COMMENT ON TABLE hr_vacation_entitlements IS 'Jahresurlaubsanspruch pro Mitarbeiter';

CREATE TABLE IF NOT EXISTS hr_vacation_requests (
    id                  INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    employee_id         INTEGER NOT NULL REFERENCES employee(id) ON DELETE CASCADE,
    date_from           DATE NOT NULL,
    date_to             DATE NOT NULL,
    days                NUMERIC(4,1) NOT NULL DEFAULT 1,
    status              TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    notes               TEXT,
    approved_by         INTEGER REFERENCES employee(id),
    approved_at         TIMESTAMP WITHOUT TIME ZONE,
    rejection_reason    TEXT,
    itime               TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    mtime               TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW()
);

COMMENT ON TABLE hr_vacation_requests IS 'Urlaubsantraege mit Genehmigungsworkflow';
COMMENT ON COLUMN hr_vacation_requests.days IS 'Anzahl Urlaubstage (halbe Tage moeglich)';
COMMENT ON COLUMN hr_vacation_requests.status IS 'Status: pending=Ausstehend, approved=Genehmigt, rejected=Abgelehnt';

CREATE INDEX IF NOT EXISTS idx_hr_vacation_requests_employee ON hr_vacation_requests(employee_id);
CREATE INDEX IF NOT EXISTS idx_hr_vacation_requests_dates ON hr_vacation_requests(date_from, date_to);

-- Vorberechnete Lohnsteuertabelle (befuellt via PAP-Algorithmus)
CREATE TABLE IF NOT EXISTS hr_lohnsteuer_table (
    id              INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tax_year        SMALLINT NOT NULL,
    steuerklasse    SMALLINT NOT NULL CHECK (steuerklasse BETWEEN 1 AND 6),
    brutto_monat    NUMERIC(10,2) NOT NULL,
    lohnsteuer      NUMERIC(10,2) NOT NULL DEFAULT 0,
    soli            NUMERIC(10,2) NOT NULL DEFAULT 0,
    kirchensteuer   NUMERIC(10,2) NOT NULL DEFAULT 0,
    zve_jahr        INTEGER NOT NULL DEFAULT 0,
    updated_at      TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    UNIQUE(tax_year, steuerklasse, brutto_monat)
);

COMMENT ON TABLE hr_lohnsteuer_table IS 'Vorberechnete monatliche Lohnsteuerwerte nach PAP (lokale Berechnung)';
COMMENT ON COLUMN hr_lohnsteuer_table.brutto_monat IS 'Monatliches Bruttogehalt in EUR';
COMMENT ON COLUMN hr_lohnsteuer_table.lohnsteuer IS 'Monatliche Lohnsteuer in EUR';
COMMENT ON COLUMN hr_lohnsteuer_table.soli IS 'Monatlicher Solidaritaetszuschlag in EUR';
COMMENT ON COLUMN hr_lohnsteuer_table.kirchensteuer IS 'Monatliche Kirchensteuer in EUR (9%)';

CREATE INDEX IF NOT EXISTS idx_hr_lohnsteuer_year_stkl ON hr_lohnsteuer_table(tax_year, steuerklasse);

-- Metadaten zur Steuertabelle
CREATE TABLE IF NOT EXISTS hr_lohnsteuer_meta (
    id          INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tax_year    SMALLINT NOT NULL UNIQUE,
    row_count   INTEGER NOT NULL DEFAULT 0,
    built_at    TIMESTAMP WITHOUT TIME ZONE DEFAULT NOW(),
    built_by    INTEGER REFERENCES employee(id),
    notes       TEXT
);

COMMENT ON TABLE hr_lohnsteuer_meta IS 'Metadaten der Lohnsteuertabelle (Erstellungszeitpunkt, Zeilenanzahl)';

-- ── Kassenbuch ──────────────────────────────────────────────────────────────
-- Nutzt kivitendo-kompatible Buchung über gl + acc_trans.
-- cash_registers verknüpft eine Kasse mit dem entsprechenden Kassakonto (z. B. 1000 SKR03 / 1600 SKR04).
-- Alle Buchungsbewegungen liegen in acc_trans (chart_id = cash_registers.chart_id).

CREATE TABLE IF NOT EXISTS cash_registers (
    id              INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    chart_id        INTEGER NOT NULL,           -- Referenz auf chart.id (Kassakonto, z. B. 1000/1600)
    opening_balance NUMERIC(15,2) NOT NULL DEFAULT 0,
    currency        CHAR(3) NOT NULL DEFAULT 'EUR',
    created_at      TIMESTAMP NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE cash_registers IS 'Kassenbücher – verknüpfen UI-Kassen mit dem Kassakonto im Kontenplan';
COMMENT ON COLUMN cash_registers.chart_id IS 'FK auf chart.id (Kassakonto: SKR03=1000, SKR04=1600)';
COMMENT ON COLUMN cash_registers.opening_balance IS 'Anfangsbestand; wird zum acc_trans-Saldo addiert';

-- Verknüpft Belege (accounting_documents) mit GL-Kassenbuchungen (gl.id)
CREATE TABLE IF NOT EXISTS cash_gl_documents (
    id          INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    gl_id       INTEGER NOT NULL,
    document_id INTEGER NOT NULL REFERENCES accounting_documents(id) ON DELETE CASCADE
);

COMMENT ON TABLE cash_gl_documents IS 'Verbindet hochgeladene Belege mit manuellen Kassenbuchungen (gl.id)';

CREATE INDEX IF NOT EXISTS idx_cash_gl_documents_gl ON cash_gl_documents(gl_id);

-- ============================================================
-- Zahlungsabrechnungen von Kartendienstleistern (Flatpay/Rapyd o.ae.)
--
-- Eine Sammelauszahlung auf dem Bankkonto entspricht der Summe vieler
-- Kartenzahlungen abzueglich der Dienstleistergebuehr. Die hochgeladene
-- Abrechnung (Excel/PDF/CSV) wird als accounting_documents beim Kreditor
-- (Kartendienstleister) abgelegt. Jede Auszahlungszeile wird ueber
-- net = Bankbetrag automatisch einem nicht zuordbaren Bankumsatz
-- zugeordnet und als Split-Buchung (Brutto-Erloes + Gebuehr) gebucht.
-- ============================================================
CREATE TABLE IF NOT EXISTS payment_settlements (
    id              SERIAL PRIMARY KEY,
    provider        TEXT NOT NULL,                              -- z.B. 'Flatpay', 'Rapyd'
    vendor_id       INTEGER REFERENCES vendor(id),              -- Kreditor (Kartendienstleister)
    document_id     INTEGER REFERENCES accounting_documents(id) ON DELETE SET NULL,
    period_from     DATE,                                       -- abgedeckter Gesamtzeitraum
    period_to       DATE,
    total_gross     NUMERIC(15,2) DEFAULT 0,                    -- Gesamteinnahmen (brutto)
    total_fee       NUMERIC(15,2) DEFAULT 0,                    -- Transaktionskosten gesamt
    total_net       NUMERIC(15,2) DEFAULT 0,                    -- Nettoauszahlung gesamt
    currency        VARCHAR(3) DEFAULT 'EUR',
    fee_chart_id    INTEGER REFERENCES chart(id),               -- gewaehltes Gebuehren-/Aufwandskonto (gemerkt je Kreditor)
    clearing_chart_id INTEGER REFERENCES chart(id),             -- gewaehltes Verrechnungskonto (Kartenzahlungen/Geldtransit)
    employee_id     INTEGER,
    itime           TIMESTAMP DEFAULT NOW(),
    mtime           TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_payment_settlements_vendor ON payment_settlements(vendor_id);

COMMENT ON TABLE payment_settlements IS 'Hochgeladene Sammelabrechnungen von Kartendienstleistern (Flatpay/Rapyd). Datei liegt als accounting_documents beim Kreditor.';

CREATE TABLE IF NOT EXISTS payment_settlement_lines (
    id                          SERIAL PRIMARY KEY,
    settlement_id               INTEGER NOT NULL REFERENCES payment_settlements(id) ON DELETE CASCADE,
    payout_date                 DATE NOT NULL,                  -- Auszahlungsdatum (= Bank-Valuta)
    period_from                 DATE,                           -- "Deckt den Zeitraum ab" (von)
    period_to                   DATE,                           -- "Deckt den Zeitraum ab" (bis)
    gross                       NUMERIC(15,2) NOT NULL DEFAULT 0,  -- Gesamteinnahmen (brutto)
    fee                         NUMERIC(15,2) NOT NULL DEFAULT 0,  -- Transaktionskosten (positiv)
    net                         NUMERIC(15,2) NOT NULL DEFAULT 0,  -- Nettoauszahlung (= erwarteter Bankbetrag)
    matched_bank_transaction_id INTEGER,                        -- FK zu bank_transactions (nach Match)
    gl_id                       INTEGER,                        -- gebuchter Vorgang (gl.id), nach Buchung
    settled_ar_ids              JSONB,                          -- ausgeglichene Ausgangsrechnungen (ar.id-Array), fuer Storno
    status                      VARCHAR(20) DEFAULT 'open'
                                CHECK (status IN ('open', 'matched', 'booked', 'ignored')),
    itime                       TIMESTAMP DEFAULT NOW(),
    mtime                       TIMESTAMP DEFAULT NOW(),
    UNIQUE (settlement_id, payout_date)
);

CREATE INDEX IF NOT EXISTS idx_payment_settlement_lines_settlement ON payment_settlement_lines(settlement_id);
CREATE INDEX IF NOT EXISTS idx_payment_settlement_lines_net ON payment_settlement_lines(net);
CREATE INDEX IF NOT EXISTS idx_payment_settlement_lines_matched ON payment_settlement_lines(matched_bank_transaction_id);

COMMENT ON TABLE payment_settlement_lines IS 'Einzelne Auszahlungszeilen einer Kartenabrechnung; net = erwarteter Bankbetrag fuer Auto-Match.';

-- eBay-Bestellimport: Idempotenz, Audit und Kaeufer->Kunde-Verknuepfung.
-- UNIQUE(ebay_order_id) ist die zentrale Sperre gegen doppelte Rechnungen.
-- buyer_username dient als schnellster Dubletten-Treffer fuer wiederkehrende Kaeufer.
CREATE TABLE IF NOT EXISTS ebay_orders (
    id              SERIAL PRIMARY KEY,
    ebay_order_id   TEXT NOT NULL UNIQUE,                       -- eBay orderId (Idempotenz-Schluessel)
    ar_id           INTEGER REFERENCES ar(id) ON DELETE SET NULL,  -- erzeugte Ausgangsrechnung
    customer_id     INTEGER REFERENCES customer(id) ON DELETE SET NULL,  -- zugeordneter Kunde
    buyer_username  TEXT,                                       -- eBay-Kaeufername (stabilster Dubletten-Schluessel)
    order_status    VARCHAR(30),                                -- eBay orderFulfillmentStatus
    total           NUMERIC(15,2) DEFAULT 0,                    -- Bruttogesamtbetrag der Bestellung
    posting_reason  VARCHAR(40),                                -- Ergebnis von postArInvoiceToLedger (posted/AMOUNT_MISMATCH/...)
    raw             JSONB,                                      -- Rohbestellung von eBay (Audit/Reklamation)
    itime           TIMESTAMP DEFAULT NOW(),
    mtime           TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ebay_orders_buyer ON ebay_orders(buyer_username);
CREATE INDEX IF NOT EXISTS idx_ebay_orders_customer ON ebay_orders(customer_id);

COMMENT ON TABLE ebay_orders IS 'Importierte eBay-Bestellungen; UNIQUE(ebay_order_id) verhindert doppelte Rechnungen, buyer_username verknuepft wiederkehrende Kaeufer mit dem Kunden.';

-- eBay-Artikelbilder (Outbound-Listing). Dateien liegen unter data/<db>/parts/<parts_id>/;
-- oeffentlich ausgeliefert ueber webhook/part-image.php, damit eBay sie laden kann.
CREATE TABLE IF NOT EXISTS ebay_part_images (
    id          SERIAL PRIMARY KEY,
    parts_id    INTEGER NOT NULL,                           -- Artikel (parts.id; kein FK, parts ist kivitendo-Kerntabelle)
    filename    TEXT NOT NULL,                              -- Dateiname im Artikelordner
    sort        INTEGER DEFAULT 0,                          -- Reihenfolge (0 = Hauptbild)
    itime       TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ebay_part_images_part ON ebay_part_images(parts_id);

COMMENT ON TABLE ebay_part_images IS 'Artikelbilder fuer eBay-Listings; Dateien unter data/<db>/parts/<parts_id>/, oeffentlich via webhook/part-image.php.';

-- eBay-Listing-Status je Artikel. Checkbox "eBay-Artikel" = status='active'.
CREATE TABLE IF NOT EXISTS ebay_listings (
    id          SERIAL PRIMARY KEY,
    parts_id    INTEGER NOT NULL UNIQUE,                    -- Artikel (parts.id)
    sku         TEXT,                                       -- = parts.partnumber (Bruecke zum Inbound-Abgleich)
    offer_id    TEXT,                                       -- eBay offerId
    listing_id  TEXT,                                       -- eBay listingId (nach publish)
    status      VARCHAR(20) DEFAULT 'active'
                CHECK (status IN ('active', 'ended', 'error')),
    message     TEXT,                                       -- letzte eBay-Meldung (z. B. Validierungsfehler)
    itime       TIMESTAMP DEFAULT NOW(),
    mtime       TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ebay_listings_status ON ebay_listings(status);

COMMENT ON TABLE ebay_listings IS 'eBay-Outbound-Listing je Artikel; status=active bedeutet bei eBay veroeffentlicht, ended=zurueckgezogen, error=Publish-Fehler (siehe message).';
