-- company_schema.sql
-- Tabellen für das public-Schema (Company-Datenbank)

-- ============================================================================
-- ERWEITERUNGEN (EXTENSIONS)
-- ============================================================================
--
-- Erweiterungen sind eigenständige Module mit eigenem Schema unter
-- backend/upstall/<name>/, eigenem Backend unter backend/api/<name>/ und
-- eigenem Frontend unter src/features/<name>/. Mehrere Erweiterungen können
-- gleichzeitig aktiv sein. Der Name entspricht dem Verzeichnisnamen.
--
-- Nicht zu verwechseln mit den Features (feature_anpr, feature_nvr,
-- feature_datev, ...): das sind einfache Ein-/Aus-Schalter innerhalb
-- bestehender Funktionen und liegen weiterhin in defaults_oserp bzw. defaults.

CREATE TABLE extensions_oserp (
    id integer NOT NULL GENERATED ALWAYS AS IDENTITY,
    extension TEXT NOT NULL,
    active BOOL NOT NULL DEFAULT true,
    itime TIMESTAMP WITHOUT TIME ZONE DEFAULT now(),
    mtime TIMESTAMP WITHOUT TIME ZONE
);

COMMENT ON TABLE extensions_oserp IS 'Aktivierte Erweiterungen (Module wie LxCars)';
COMMENT ON COLUMN extensions_oserp.id IS 'Primärschlüssel (automatisch generiert)';
COMMENT ON COLUMN extensions_oserp.extension IS 'Name der Erweiterung, entspricht dem Verzeichnis unter backend/upstall/';
COMMENT ON COLUMN extensions_oserp.active IS 'Erweiterung aktiviert (true) oder deaktiviert (false)';
COMMENT ON COLUMN extensions_oserp.itime IS 'Zeitstempel der Erstellung';
COMMENT ON COLUMN extensions_oserp.mtime IS 'Zeitstempel der letzten Änderung';

CREATE UNIQUE INDEX idx_extensions_oserp_extension ON extensions_oserp(extension);

-- Migration: früher lag genau eine Erweiterung als Textwert in
-- defaults_oserp.key = 'features'. Der Wert wird einmalig übernommen,
-- danach sind diese Statements wirkungslos (idempotent).
INSERT INTO extensions_oserp (extension, active)
SELECT trim(value), true
FROM defaults_oserp
WHERE key = 'features' AND trim(COALESCE(value, '')) <> ''
ON CONFLICT (extension) DO NOTHING;

DELETE FROM defaults_oserp WHERE key = 'features';

DROP TABLE IF EXISTS features_oserp;

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
    urls JSONB,
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
COMMENT ON COLUMN customer_ext.emails IS 'JSON-Array mit E-Mail-Adressen [{label, email}]';
COMMENT ON COLUMN customer_ext.urls IS 'JSON-Array mit Web-Adressen [{label, url}]';
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
    urls JSONB,
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
COMMENT ON COLUMN vendor_ext.emails IS 'JSON-Array mit E-Mail-Adressen [{label, email}]';
COMMENT ON COLUMN vendor_ext.urls IS 'JSON-Array mit Web-Adressen [{label, url}]';
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
        IF result.name != '' THEN return result; END IF;        SELECT INTO result id, name::text, 'C'::char AS typ FROM (SELECT id, name, to_number(fax, format)::char(16) AS p, to_number(fax, format)::char(16) AS f, char_length(to_number(fax, format)::char(16)) AS l, char_length(to_number(fax, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM customer WHERE fax !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        -- Zusätzliche Telefonnummern aus customer_ext (JSON-Array [{"label":..,"number":..}])
        SELECT INTO result id, name::text, 'C'::char AS typ FROM (SELECT c.id AS id, c.name AS name, to_number(e.elem->>'number', format)::char(16) AS p, char_length(to_number(e.elem->>'number', format)::char(16)) AS l, char_length(to_number(telnum, format)::char(16)) AS lt FROM customer_ext ce JOIN customer c ON c.id = ce.customer_id CROSS JOIN LATERAL jsonb_array_elements(ce.phone_numbers) AS e(elem) WHERE jsonb_typeof(ce.phone_numbers) = 'array' AND (e.elem->>'number') ~ '[0-9]') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;
        SELECT INTO result id, name AS name, 'V'::char AS typ FROM (SELECT id, name, to_number(phone, format)::char(16) AS p, to_number(phone, format)::char(16) AS f, char_length(to_number(phone, format)::char(16)) AS l, char_length(to_number(phone, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM vendor WHERE phone !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
        IF result.name != '' THEN return result; END IF;        SELECT INTO result id, name, 'V'::char AS typ FROM (SELECT id, name, to_number(fax, format)::char(16) AS p, to_number(fax, format)::char(16) AS f, char_length(to_number(fax, format)::char(16)) AS l, char_length(to_number(fax, format)::char(16)) AS l1, char_length(to_number(telnum, format)::char(16)) AS lt FROM vendor WHERE fax !='') AS xyz WHERE kuerze(lt,xyz.p) LIKE kuerze(l,to_number(telnum, format)::char(16))||'%';
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

        -- Hinweis: Früher wurde hier die Anrufhistorie auf die letzten 512
        -- Zeilen gekürzt (DELETE FROM crmti WHERE crmti_id < new_row.crmti_id - 512).
        -- Das löschte bei jedem ausgehenden Anruf ältere Anrufe aller Kunden —
        -- die Historie bleibt jetzt vollständig erhalten.
        IF result.typ != 'X' THEN
            INSERT INTO telcall ( calldate, bezug, cause, caller_id, kontakt, inout ) values ( CURRENT_TIMESTAMP, 0, 'Ausgehender Anruf vo[n|m] '||src, result.id, 'T', 'o' );
        END IF;
        return '1';
    END;
$BODY$;

-- Schreibt den Anruf-Status (Asterisk DIALSTATUS) in die crmti-Zeile.
-- Wird aus extensions.ael NACH dem Dial() via ODBC_CALLSTATUS aufgerufen, da der
-- DIALSTATUS erst nach dem Wählversuch feststeht (CallIn/CallOut laufen davor).
-- Status ANSWER (DIALSTATUS) bedeutet angenommen, alles andere: nicht angenommen (eingehend verpasst bzw.
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
-- NUMMERNKREISE — DATEV/Lexware-konforme Startwerte für Kunden/Lieferanten
-- ============================================================================
-- Auf frischen kivitendo-Firmen-DBs sind defaults.customernumber/vendornumber leer;
-- nextFreeNumber() (backend/api/database.php) würde dann bei 1 beginnen. Hier idempotent
-- die DATEV/Lexware-üblichen Bereiche vorbelegen — NUR wenn noch leer, bestehende Nummern
-- (Produktiv-DBs) werden nie überschrieben. Da nextFreeNumber erst hochzählt, ist der
-- Startzähler = erster gewünschter Wert minus 1:
--   Debitoren/Kunden        10000–69999  -> Zähler 9999  (nächste vergebene Nummer = 10000)
--   Kreditoren/Lieferanten  70000–99999  -> Zähler 69999 (nächste vergebene Nummer = 70000)
UPDATE defaults SET customernumber = '9999'  WHERE customernumber IS NULL OR TRIM(customernumber) = '';
UPDATE defaults SET vendornumber   = '69999' WHERE vendornumber   IS NULL OR TRIM(vendornumber)   = '';

-- Artikel/Dienstleistungen (keine DATEV-Vorgabe): getrennte, gut unterscheidbare Bereiche.
-- Startzähler = erste gewünschte Nummer minus 1 (nextFreeNumber zählt erst hoch):
--   Artikel/Waren        ab 100000 -> Zähler 99999
--   Dienstleistungen     ab 900000 -> Zähler 899999
--   Erzeugnisse/Sortimente ab 800000 -> Zähler 799999
UPDATE defaults SET articlenumber    = '99999'  WHERE articlenumber    IS NULL OR TRIM(articlenumber)    = '';
UPDATE defaults SET servicenumber    = '899999' WHERE servicenumber    IS NULL OR TRIM(servicenumber)    = '';
UPDATE defaults SET assemblynumber   = '799999' WHERE assemblynumber   IS NULL OR TRIM(assemblynumber)   = '';
UPDATE defaults SET assortmentnumber = '799999' WHERE assortmentnumber IS NULL OR TRIM(assortmentnumber) = '';

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
CREATE INDEX IF NOT EXISTS idx_calendar_events_order_id ON calendar_events(order_id) WHERE order_id IS NOT NULL;

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

-- PLZ-Zentroide (Koordinaten) fuer Entfernungsberechnung. Datenquelle: GeoNames
-- (CC BY 4.0), aggregiert pro PLZ. Wird aus company_data/zipcode_geo_oserp.csv
-- automatisch befuellt (leere Tabelle -> Seed durch Update-Mechanismus).
CREATE TABLE zipcode_geo_oserp
(
    plz character(5) NOT NULL,
    lat numeric(9,6) NOT NULL,
    lon numeric(9,6) NOT NULL,
    CONSTRAINT zipcode_geo_oserp_pkey PRIMARY KEY (plz)
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
    sort_order DOUBLE PRECISION,
    -- message_id ist nur je Chat eindeutig, nicht global — deshalb zusammengesetzt.
    -- Der Webhook nutzt genau diese Spaltenkombination in ON CONFLICT.
    CONSTRAINT voice_notes_telegram_chat_msg_unique UNIQUE (telegram_chat_id, telegram_message_id)
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
COMMENT ON COLUMN voice_notes.sort_order IS 'Manuelle Sortierung der Anschlagtafel (hoeher = weiter oben); NULL faellt auf itime zurueck';

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

-- Kunde/Lieferant: offene Belege laden die Stammdaten (E-Mail, CC, BCC, Telefon,
-- Notizen) neu, wenn sie in einem anderen Fenster geaendert werden. Gleiche
-- Nachricht wie bei oe/ar (table + id); die Faktura-Ansicht filtert auf die
-- Kunden-ID des offenen Belegs.
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'customer_faktura_notify') THEN
        CREATE TRIGGER customer_faktura_notify
            AFTER UPDATE ON customer
            FOR EACH ROW
            EXECUTE FUNCTION notify_faktura_change();
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'vendor_faktura_notify') THEN
        CREATE TRIGGER vendor_faktura_notify
            AFTER UPDATE ON vendor
            FOR EACH ROW
            EXECUTE FUNCTION notify_faktura_change();
    END IF;
END $$;

-- ============================================================================
-- WHATSAPP TEMPLATES
-- ============================================================================

-- ═══════════════════════════════════════════════════════════════
-- Änderungsverlauf Kunde/Lieferant ("Zeitreise")
--
-- Ein Trigger auf den kivitendo-Tabellen customer, vendor, contacts, shipto,
-- additional_billing_addresses und den Erweiterungen customer_ext/vendor_ext
-- schreibt je Speichern die geänderten Spalten als Diff ({spalte: {old, new}})
-- mit Mitarbeiter und Zeit. Die kivitendo-Tabellen bleiben unverändert.
--
-- Wer geändert hat, setzt die API vor dem Schreiben per
--   set_config('oserp.employee_id', …) und set_config('oserp.cv_src', 'C'|'V')
-- (customer_vendor.php, cvHistoryContext). Ohne Kontext bleibt employee_id
-- leer und bei contacts/shipto gilt Kunde — beide Tabellen tragen die Seite
-- nicht selbst.
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS cv_history_oserp (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    cv_id INTEGER NOT NULL,
    src CHAR(1) NOT NULL,              -- C Kunde, V Lieferant
    table_name TEXT NOT NULL,          -- customer, vendor, customer_ext, vendor_ext, contacts, shipto, additional_billing_addresses
    row_id INTEGER,                    -- cp_id, shipto_id, id der Nebentabelle
    row_label TEXT,                    -- Name des Ansprechpartners / der Lieferadresse zur Anzeige
    action TEXT NOT NULL,              -- INSERT, UPDATE, DELETE
    changes JSONB,                     -- {spalte: {"old": …, "new": …}}
    employee_id INTEGER,
    itime TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS cv_history_oserp_cv_idx ON cv_history_oserp (src, cv_id, itime DESC);

CREATE OR REPLACE FUNCTION cv_history_log() RETURNS trigger AS $$
DECLARE
    v_old      JSONB := CASE WHEN TG_OP = 'INSERT' THEN '{}'::jsonb ELSE to_jsonb(OLD) END;
    v_new      JSONB := CASE WHEN TG_OP = 'DELETE' THEN '{}'::jsonb ELSE to_jsonb(NEW) END;
    v_changes  JSONB;
    v_cv_id    INTEGER;
    v_src      CHAR(1);
    v_row_id   INTEGER;
    v_label    TEXT;
    v_employee INTEGER := NULLIF(current_setting('oserp.employee_id', true), '')::INTEGER;
    v_ctx_src  TEXT    := NULLIF(current_setting('oserp.cv_src', true), '');
BEGIN
    -- Diff der sichtbaren Spalten; leere Zeichenkette und NULL gelten als gleich,
    -- beim Anlegen zählen nur gefüllte Werte (sonst stünde jede leere Spalte drin)
    SELECT jsonb_object_agg(d.key, jsonb_build_object('old', d.o_val, 'new', d.n_val))
      INTO v_changes
      FROM (
          SELECT COALESCE(o.key, n.key) AS key,
                 CASE WHEN o.value IN ('""'::jsonb, 'null'::jsonb) THEN NULL ELSE o.value END AS o_val,
                 CASE WHEN n.value IN ('""'::jsonb, 'null'::jsonb) THEN NULL ELSE n.value END AS n_val
            FROM jsonb_each(v_old) o
            FULL JOIN jsonb_each(v_new) n ON o.key = n.key
      ) d
     WHERE d.o_val IS DISTINCT FROM d.n_val
       AND d.key NOT IN ('id', 'itime', 'mtime', 'cp_id', 'shipto_id', 'cp_cv_id', 'trans_id', 'module', 'customer_id', 'vendor_id')
       AND (TG_OP <> 'INSERT' OR (d.n_val IS NOT NULL AND d.n_val <> 'false'::jsonb AND d.n_val <> '0'::jsonb));

    IF TG_OP <> 'DELETE' AND (v_changes IS NULL OR v_changes = '{}'::jsonb) THEN
        RETURN NULL;
    END IF;

    CASE TG_TABLE_NAME
        WHEN 'customer' THEN
            v_cv_id := COALESCE(v_new->>'id', v_old->>'id')::INTEGER; v_src := 'C';
        WHEN 'vendor' THEN
            v_cv_id := COALESCE(v_new->>'id', v_old->>'id')::INTEGER; v_src := 'V';
        WHEN 'customer_ext' THEN
            v_cv_id := COALESCE(v_new->>'customer_id', v_old->>'customer_id')::INTEGER; v_src := 'C';
        WHEN 'vendor_ext' THEN
            v_cv_id := COALESCE(v_new->>'vendor_id', v_old->>'vendor_id')::INTEGER; v_src := 'V';
        WHEN 'contacts' THEN
            v_cv_id  := COALESCE(v_new->>'cp_cv_id', v_old->>'cp_cv_id')::INTEGER;
            v_src    := COALESCE(v_ctx_src, 'C');
            v_row_id := COALESCE(v_new->>'cp_id', v_old->>'cp_id')::INTEGER;
            v_label  := NULLIF(trim(concat_ws(' ', COALESCE(v_new->>'cp_givenname', v_old->>'cp_givenname'),
                                                   COALESCE(v_new->>'cp_name', v_old->>'cp_name'))), '');
        WHEN 'shipto' THEN
            IF COALESCE(v_new->>'module', v_old->>'module') IS DISTINCT FROM 'CT' THEN
                RETURN NULL;
            END IF;
            v_cv_id  := COALESCE(v_new->>'trans_id', v_old->>'trans_id')::INTEGER;
            v_src    := COALESCE(v_ctx_src, 'C');
            v_row_id := COALESCE(v_new->>'shipto_id', v_old->>'shipto_id')::INTEGER;
            v_label  := NULLIF(concat_ws(', ', COALESCE(v_new->>'shiptoname', v_old->>'shiptoname'),
                                               COALESCE(v_new->>'shiptocity', v_old->>'shiptocity')), '');
        WHEN 'additional_billing_addresses' THEN
            v_cv_id  := COALESCE(v_new->>'customer_id', v_old->>'customer_id')::INTEGER; v_src := 'C';
            v_row_id := COALESCE(v_new->>'id', v_old->>'id')::INTEGER;
            v_label  := COALESCE(v_new->>'name', v_old->>'name');
        ELSE
            RETURN NULL;
    END CASE;

    IF v_cv_id IS NULL THEN
        RETURN NULL;
    END IF;

    INSERT INTO cv_history_oserp (cv_id, src, table_name, row_id, row_label, action, changes, employee_id)
    VALUES (v_cv_id, v_src, TG_TABLE_NAME, v_row_id, v_label, TG_OP, v_changes, v_employee);
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DO $$ DECLARE t TEXT; BEGIN
    FOREACH t IN ARRAY ARRAY['customer', 'vendor', 'customer_ext', 'vendor_ext', 'contacts', 'shipto', 'additional_billing_addresses'] LOOP
        IF to_regclass(t) IS NOT NULL AND NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = t || '_cv_history') THEN
            EXECUTE format('CREATE TRIGGER %I AFTER INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION cv_history_log()', t || '_cv_history', t);
        END IF;
    END LOOP;
END $$;

-- ═══════════════════════════════════════════════════════════════
-- Belegchronik: wer hat wann was an einem Faktura-Beleg geändert
--
-- Dasselbe Muster wie die Zeitreise der Kundenmaske: ein Trigger auf den
-- kivitendo-Beleg- und Positionstabellen schreibt je Änderung die geänderten
-- Spalten als Diff ({spalte: {old, new}}) mit Mitarbeiter und Zeit. Die
-- kivitendo-Tabellen bleiben unverändert. Die LxCars-Erweiterung hängt den
-- Trigger zusätzlich an oe_ext/ar_ext (Fahrzeugdaten je Auftrag/Rechnung).
--
-- Mitarbeiter und Quelle setzt jede API-Anfrage zentral aus dem Login
-- (DbhCompany::begin → set_config('oserp.employee_id' / 'oserp.source')),
-- Cron-Skripte über DbhCompany::setActor (Quelle 'cron:…'). Ohne Quelle kam
-- die Änderung nicht über OSERP — kivitendo, psql oder ein Reparatur-Skript.
--
-- Bewusst nicht protokolliert: technische und abgeleitete Spalten (Zeiten,
-- Margen, Basismengen, Kopien von Belegnummer/-datum in den Positionen,
-- Konten der Buchung). Unveränderte Speicherungen erzeugen keine Zeile.
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS record_history_oserp (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_table TEXT NOT NULL,        -- oe, ar, ap, delivery_orders
    record_id INTEGER NOT NULL,        -- ID des Belegs
    table_name TEXT NOT NULL,          -- Quelle: Belegkopf, orderitems, invoice, delivery_order_items, oe_ext, ar_ext
    row_id INTEGER,                    -- ID der Position
    row_label TEXT,                    -- Positionstext zur Anzeige (auch nach dem Löschen)
    action TEXT NOT NULL,              -- INSERT, UPDATE, DELETE
    changes JSONB,                     -- {spalte: {"old": …, "new": …}}
    employee_id INTEGER,
    source TEXT,                       -- api, portal, cron:recurring, cron:ebay …; leer = außerhalb von OSERP
    itime TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS record_history_oserp_record_idx ON record_history_oserp (record_table, record_id, itime DESC);

CREATE OR REPLACE FUNCTION record_history_log() RETURNS trigger AS $$
DECLARE
    v_old       JSONB := CASE WHEN TG_OP = 'INSERT' THEN '{}'::jsonb ELSE to_jsonb(OLD) END;
    v_new       JSONB := CASE WHEN TG_OP = 'DELETE' THEN '{}'::jsonb ELSE to_jsonb(NEW) END;
    v_row       JSONB := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
    v_changes   JSONB;
    v_table     TEXT;
    v_record_id INTEGER;
    v_row_id    INTEGER;
    v_label     TEXT;
    v_exists    BOOLEAN;
    v_employee  INTEGER := NULLIF(current_setting('oserp.employee_id', true), '')::INTEGER;
    v_source    TEXT    := NULLIF(current_setting('oserp.source', true), '');
    -- Technisch/abgeleitet in allen Tabellen: Schlüssel, Zeiten, von kivitendo berechnete Margen
    v_skip      TEXT[] := ARRAY['id', 'itime', 'mtime', 'trans_id', 'delivery_order_id', 'oe_id', 'ar_id',
                                'marge_total', 'marge_percent', 'marge_price_factor', 'exchangerate'];
BEGIN
    IF TG_TABLE_NAME IN ('orderitems', 'invoice', 'delivery_order_items') THEN
        -- Positionen: Kopien des Kopfs, Basismengen, Fremdwährungsduplikat, Lager/Buchungsziele,
        -- Preisquellen, gelieferte Menge, LxCars-Altspalten status/u_id; die Positionsnummer
        -- nummeriert OSERP beim Löschen und Sortieren für alle Positionen neu (Rauschen)
        v_skip := v_skip || ARRAY['position', 'ordnumber', 'transdate', 'cusordnumber', 'donumber', 'base_qty', 'fxsellprice',
                                  'lastcost', 'price_factor', 'allocated', 'assemblyitem', 'inventory_chart_id',
                                  'expense_chart_id', 'tax_id', 'tax_chart_type', 'active_price_source',
                                  'active_discount_source', 'ship', 'status', 'u_id', 'recurring_billing_mode',
                                  'recurring_billing_invoice_id'];
    ELSIF TG_TABLE_NAME IN ('oe_ext', 'ar_ext') THEN
        v_skip := v_skip || ARRAY['asanetwork_sent_at', 'c_sk', 'c_zrd', 'c_zrk', 'c_bf', 'c_wd', 'other_car_ar_id'];
    ELSE
        -- Belegkopf: bei Anlage gesetzte Konstanten, Folgefelder der Zahlung, Schweizer QR-Rechnung,
        -- kivitendo-Altlasten und die LxCars-Altspalten in oe (ersetzt durch oe_ext)
        v_skip := v_skip || ARRAY['gldate', 'orddate', 'quodate', 'invoice', 'type', 'datepaid',
                                  'delivery_customer_id', 'delivery_vendor_id', 'qr_reference',
                                  'qr_unstructured_message', 'qrbill_without_amount', 'qrbill_data',
                                  'c_id', 'car_manuf', 'car_status', 'car_type', 'delivery_time', 'finish_time',
                                  'internalorder', 'km_stnd', 'printed', 'status'];
    END IF;

    -- Diff der sichtbaren Spalten; leere Zeichenkette und NULL gelten als gleich,
    -- beim Anlegen zählen nur gefüllte Werte (sonst stünde jede leere Spalte drin)
    SELECT jsonb_object_agg(d.key, jsonb_build_object('old', d.o_val, 'new', d.n_val))
      INTO v_changes
      FROM (
          SELECT COALESCE(o.key, n.key) AS key,
                 CASE WHEN o.value IN ('""'::jsonb, 'null'::jsonb) THEN NULL ELSE o.value END AS o_val,
                 CASE WHEN n.value IN ('""'::jsonb, 'null'::jsonb) THEN NULL ELSE n.value END AS n_val
            FROM jsonb_each(v_old) o
            FULL JOIN jsonb_each(v_new) n ON o.key = n.key
      ) d
     WHERE d.o_val IS DISTINCT FROM d.n_val
       AND d.key <> ALL (v_skip)
       AND (TG_OP <> 'INSERT' OR (d.n_val IS NOT NULL AND d.n_val <> 'false'::jsonb AND d.n_val <> '0'::jsonb));

    IF TG_OP = 'UPDATE' AND (v_changes IS NULL OR v_changes = '{}'::jsonb) THEN
        RETURN NULL;
    END IF;

    CASE TG_TABLE_NAME
        WHEN 'oe', 'ar', 'ap', 'delivery_orders' THEN
            v_table := TG_TABLE_NAME;
            v_record_id := (v_row->>'id')::INTEGER;
            -- Belegnummer; Angebote/Anfragen: neues Schema record_type, altes quotation
            v_label := COALESCE(NULLIF(v_row->>'invnumber', ''), NULLIF(v_row->>'donumber', ''),
                                CASE WHEN COALESCE(v_row->>'record_type', '') LIKE '%quotation%'
                                       OR v_row->>'quotation' = 'true' THEN NULLIF(v_row->>'quonumber', '') END,
                                NULLIF(v_row->>'ordnumber', ''), NULLIF(v_row->>'quonumber', ''));
        WHEN 'orderitems' THEN
            v_table := 'oe';
            v_record_id := (v_row->>'trans_id')::INTEGER;
        WHEN 'delivery_order_items' THEN
            v_table := 'delivery_orders';
            v_record_id := (v_row->>'delivery_order_id')::INTEGER;
        WHEN 'invoice' THEN
            -- Positionen von Verkaufs- und Einkaufsrechnungen teilen sich die Tabelle;
            -- ar und ap ziehen ihre IDs aus derselben Sequenz (glid)
            v_record_id := (v_row->>'trans_id')::INTEGER;
            IF EXISTS (SELECT 1 FROM ar WHERE id = v_record_id) THEN
                v_table := 'ar';
            ELSIF EXISTS (SELECT 1 FROM ap WHERE id = v_record_id) THEN
                v_table := 'ap';
            ELSE
                RETURN NULL;   -- Kopf schon gelöscht: das Löschen des Belegs steht dort
            END IF;
        WHEN 'oe_ext' THEN
            v_table := 'oe';
            v_record_id := (v_row->>'oe_id')::INTEGER;
        WHEN 'ar_ext' THEN
            v_table := 'ar';
            v_record_id := (v_row->>'ar_id')::INTEGER;
        ELSE
            RETURN NULL;
    END CASE;

    IF v_record_id IS NULL THEN
        RETURN NULL;
    END IF;

    -- Löschen einer Position/Erweiterung, weil der ganze Beleg gelöscht wird
    -- (Kaskade): das steht schon als Löschen des Belegs in der Chronik
    IF TG_OP = 'DELETE' AND TG_TABLE_NAME NOT IN ('oe', 'ar', 'ap', 'delivery_orders') THEN
        EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I WHERE id = $1)', v_table) INTO v_exists USING v_record_id;
        IF NOT v_exists THEN
            RETURN NULL;
        END IF;
    END IF;

    IF TG_TABLE_NAME IN ('orderitems', 'invoice', 'delivery_order_items') THEN
        v_row_id := (v_row->>'id')::INTEGER;
        v_label := left(NULLIF(trim(v_row->>'description'), ''), 120);
    END IF;

    INSERT INTO record_history_oserp (record_table, record_id, table_name, row_id, row_label, action, changes, employee_id, source)
    VALUES (v_table, v_record_id, TG_TABLE_NAME, v_row_id, v_label, TG_OP, v_changes, v_employee, v_source);
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DO $$ DECLARE t TEXT; BEGIN
    FOREACH t IN ARRAY ARRAY['oe', 'ar', 'ap', 'delivery_orders', 'orderitems', 'invoice', 'delivery_order_items'] LOOP
        IF to_regclass(t) IS NOT NULL AND NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = t || '_record_history') THEN
            EXECUTE format('CREATE TRIGGER %I AFTER INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION record_history_log()', t || '_record_history', t);
        END IF;
    END LOOP;
END $$;

-- Notizen von Wiedervorlagen zeigen wie bei kivitendo auf ihre Wiedervorlage
-- (notes.trans_id) — Altbestand aus OSERP ohne diesen Verweis nachziehen
UPDATE notes n SET trans_id = fu.id
  FROM follow_ups fu
 WHERE fu.note_id = n.id AND n.trans_module = 'fu' AND n.trans_id IS NULL;

CREATE TABLE IF NOT EXISTS whatsapp_templates (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name TEXT NOT NULL,
    display_name TEXT,
    category TEXT NOT NULL DEFAULT 'UTILITY',
    submitted_category TEXT,
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
COMMENT ON COLUMN whatsapp_templates.submitted_category IS 'Kategorie, mit der die Vorlage bei Meta eingereicht wurde — weicht category davon ab, hat Meta die Vorlage umgestuft';
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

CREATE TABLE ticket_attachments (
    id integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ticket_id integer NOT NULL,
    comment_id integer,
    filename text NOT NULL,
    mime_type text NOT NULL DEFAULT 'application/octet-stream',
    file_size integer NOT NULL DEFAULT 0,
    stored_path text NOT NULL,
    employee_id integer,
    itime timestamp without time zone DEFAULT now()
);

CREATE INDEX idx_ticket_attachments_ticket_id ON ticket_attachments(ticket_id);

COMMENT ON TABLE ticket_attachments IS 'Dateien und Screenshots zu Tickets, optional an einem Kommentar';
COMMENT ON COLUMN ticket_attachments.stored_path IS 'Pfad relativ zum Datenverzeichnis des Mandanten (tickets/<ticket_id>/…)';

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
-- lief. Dieser Trigger ordnet die noch unaufgeloesten Zeilen, deren Nummer zu
-- phone/fax der gerade geschriebenen Zeile passt, diesem Kunden/Lieferanten zu
-- und aktualisiert Kennung + Anzeigename. Danach zeigt die Anrufliste den
-- neuen Kunden/Lieferanten korrekt an.
--
-- Der Abgleich laeuft als EIN UPDATE ueber die unaufgeloesten Anrufe und
-- vergleicht nur mit den Nummern der NEW-Zeile (gleiche Suffix-Logik wie in
-- SucheNummer). Die fruehere Fassung rief SucheNummer fuer JEDEN offenen Anruf
-- auf (12 Volltabellen-Scans je Aufruf) — bei ~180 offenen Anrufen rund 5 s
-- pro Kundenspeicherung. Zusaetzlich feuern die Trigger per WHEN-Klausel nur,
-- wenn sich phone/fax wirklich aendern: saveCV schreibt per UPSERT immer alle
-- Spalten, und "UPDATE OF phone, fax" allein wuerde damit bei jedem Speichern
-- ausloesen.
-- pg_notify('crmti_change', ...) aktualisiert offene Anruflisten live.
CREATE OR REPLACE FUNCTION backfill_crmti_caller()
    RETURNS trigger
    LANGUAGE plpgsql
AS $$
DECLARE
    fmt   CONSTANT text := '99999999999999999';
    typ   char(1) := CASE WHEN TG_TABLE_NAME = 'vendor' THEN 'V' ELSE 'C' END;
    nums  text[]  := ARRAY[]::text[];
    r     record;
BEGIN
    IF NEW.phone ~ '[0-9]' THEN nums := nums || to_number(NEW.phone, fmt)::char(16)::text; END IF;
    IF NEW.fax   ~ '[0-9]' THEN nums := nums || to_number(NEW.fax,   fmt)::char(16)::text; END IF;
    IF cardinality(nums) = 0 THEN RETURN NEW; END IF;

    FOR r IN
        UPDATE crmti c
           SET crmti_caller_id  = NEW.id,
               crmti_caller_typ = typ,
               crmti_src = CASE WHEN c.crmti_direction = 'E' THEN NEW.name ELSE c.crmti_src END,
               crmti_dst = CASE WHEN c.crmti_direction = 'A' THEN NEW.name ELSE c.crmti_dst END
          FROM unnest(nums) AS n(p)
         WHERE (c.crmti_caller_typ IN ('X', 'Y') OR c.crmti_caller_id = 0 OR c.crmti_caller_id IS NULL)
           AND c.crmti_number ~ '[0-9]'
           AND kuerze(char_length(to_number(c.crmti_number, fmt)::char(16)), n.p)
               LIKE kuerze(char_length(n.p), to_number(c.crmti_number, fmt)::char(16)) || '%'
        RETURNING c.crmti_id
    LOOP
        PERFORM pg_notify('crmti_change', json_build_object(
            'action', 'backfill',
            'crmti_id', r.crmti_id
        )::text);
    END LOOP;
    RETURN NEW;
EXCEPTION WHEN OTHERS THEN
    -- Der Abgleich ist Komfort — er darf das Speichern des Stammsatzes nie blockieren.
    RAISE WARNING 'backfill_crmti_caller(% %): %', TG_TABLE_NAME, NEW.id, SQLERRM;
    RETURN NEW;
END;
$$;

-- Trigger auf den kivitendo-Standardspalten phone + fax (customer/vendor bleiben
-- unveraendert). Zusatznummern liegen in customer_ext/vendor_ext.phone_numbers (JSON)
-- und werden von SucheNummer separat ausgewertet. INSERT und UPDATE sind getrennte
-- Trigger, weil eine WHEN-Klausel mit OLD bei INSERT nicht erlaubt ist.
DROP TRIGGER IF EXISTS trg_customer_backfill_crmti ON customer;
DROP TRIGGER IF EXISTS trg_customer_backfill_crmti_ins ON customer;
CREATE TRIGGER trg_customer_backfill_crmti_ins
    AFTER INSERT ON customer
    FOR EACH ROW
    WHEN (NEW.phone ~ '[0-9]' OR NEW.fax ~ '[0-9]')
    EXECUTE FUNCTION backfill_crmti_caller();
DROP TRIGGER IF EXISTS trg_customer_backfill_crmti_upd ON customer;
CREATE TRIGGER trg_customer_backfill_crmti_upd
    AFTER UPDATE OF phone, fax ON customer
    FOR EACH ROW
    WHEN (NEW.phone IS DISTINCT FROM OLD.phone OR NEW.fax IS DISTINCT FROM OLD.fax)
    EXECUTE FUNCTION backfill_crmti_caller();

DROP TRIGGER IF EXISTS trg_vendor_backfill_crmti ON vendor;
DROP TRIGGER IF EXISTS trg_vendor_backfill_crmti_ins ON vendor;
CREATE TRIGGER trg_vendor_backfill_crmti_ins
    AFTER INSERT ON vendor
    FOR EACH ROW
    WHEN (NEW.phone ~ '[0-9]' OR NEW.fax ~ '[0-9]')
    EXECUTE FUNCTION backfill_crmti_caller();
DROP TRIGGER IF EXISTS trg_vendor_backfill_crmti_upd ON vendor;
CREATE TRIGGER trg_vendor_backfill_crmti_upd
    AFTER UPDATE OF phone, fax ON vendor
    FOR EACH ROW
    WHEN (NEW.phone IS DISTINCT FROM OLD.phone OR NEW.fax IS DISTINCT FROM OLD.fax)
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

-- Abstimmungsstatus je Bankumsatz. Die kivitendo-Tabelle bank_transactions
-- bleibt unveraendert (nur 'cleared'); alles Weitere liegt hier.
-- match_status: unmatched | matched | booked | ignored
-- Die Gegen-IBAN steht in der kivitendo-Spalte bank_transactions.remote_account_number.
CREATE TABLE bank_transactions_ext (
    bank_transaction_id integer NOT NULL REFERENCES bank_transactions(id) ON DELETE CASCADE,
    match_status        text NOT NULL DEFAULT 'unmatched',
    CONSTRAINT bank_transactions_ext_pkey PRIMARY KEY (bank_transaction_id)
);

COMMENT ON TABLE bank_transactions_ext IS 'opensource-erp-Erweiterung zu bank_transactions (Abstimmungsstatus)';
COMMENT ON COLUMN bank_transactions_ext.match_status IS 'unmatched | matched | booked | ignored';

-- Altlast: match_status und remote_iban lagen frueher direkt in bank_transactions.
-- Daten umziehen und die Spalten entfernen, damit die kivitendo-Tabelle wieder original ist.
DO $bt_ext_legacy$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema = 'public' AND table_name = 'bank_transactions'
                 AND column_name = 'match_status') THEN
        INSERT INTO bank_transactions_ext (bank_transaction_id, match_status)
        SELECT id, COALESCE(match_status, 'unmatched') FROM bank_transactions
        ON CONFLICT (bank_transaction_id) DO NOTHING;
        ALTER TABLE bank_transactions DROP COLUMN match_status;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema = 'public' AND table_name = 'bank_transactions'
                 AND column_name = 'remote_iban') THEN
        UPDATE bank_transactions
           SET remote_account_number = remote_iban
         WHERE remote_iban IS NOT NULL AND remote_iban <> ''
           AND (remote_account_number IS NULL OR remote_account_number = '');
        ALTER TABLE bank_transactions DROP COLUMN remote_iban;
    END IF;
END
$bt_ext_legacy$;

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
    -- ZWEI HARTE PLAUSIBILITÄTS-REGELN gelten für JEDE Rechnungs-Strategie:
    --   (a) bt.transdate >= rechnung.transdate — eine Zahlung kann nicht vor
    --       der Rechnung liegen, die sie ausgleicht. Ohne diese Regel wurden
    --       Zahlungseingänge auf Rechnungen gebucht, die es zum
    --       Zahlungszeitpunkt noch gar nicht gab.
    --   (b) ABS(bt.amount) <= offener Betrag + 0,01 — nie mehr zuordnen als
    --       offen ist. Sammelzahlungen über mehrere Rechnungen gehören in
    --       die Sammelzahlungs-Erkennung, nicht komplett auf die erstbeste
    --       Einzelrechnung.
    --
    -- Konfidenz-Stufen (explizite Rechnungsreferenz schlägt Betrags-Zufall —
    -- bei wiederkehrenden Standardpreisen trifft ein reiner Betragsvergleich
    -- sonst systematisch die falsche Rechnung):
    --   0.99  Rechnungsnummer in End-to-End-ID (strukturierter SEPA-Wert)
    --   0.98  Rechnungsnummer im Purpose + Empfaenger-IBAN bestaetigt
    --   0.94  Rechnungsnummer im Purpose (vom Zahler selbst angegeben)
    --   0.92  IBAN passt + exakter offener Betrag (Toleranz 0,01 EUR)
    --   0.85  Zahlenwert aus Purpose endet auf ap.invnumber (z. B. RE9016 -> 9016)
    --   0.80  IBAN passt + Kontakt hat genau eine offene Rechnung
    --   0.78  Name passt + exakter offener Betrag (Fallback ohne IBAN)
    --   0.70  Benutzer-Regel
    RETURN QUERY
    WITH kandidaten AS (
        SELECT bt.id, bt.transdate, bt.amount, bt.purpose, bt.remote_account_number AS remote_iban, bt.remote_name, bt.end_to_end_id
        FROM bank_transactions bt
        WHERE bt.local_bank_account_id = p_bank_account_id
          AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
    ),
    -- Umsätze, in deren Verwendungszweck eine EIGENE Rechnungsnummer steht —
    -- egal ob die Rechnung noch offen oder längst bezahlt ist. Der Zahler hat
    -- damit gesagt, was er begleicht. Greift dann keine der
    -- Rechnungsnummer-Strategien (weil die Rechnung schon bezahlt ist), ist das
    -- ein Fall für den Menschen — und NICHT für einen Betragsvergleich, der
    -- bei wiederkehrenden Standardpreisen die nächstbeste fremde Rechnung
    -- greift. Genau so entstanden Zahlungen auf noch nicht existierende
    -- Rechnungen.
    -- Umsetzung über Tokens statt Regex-je-Rechnung: aus Zweck und
    -- End-to-End-ID werden die zusammenhängenden alphanumerischen Blöcke
    -- gezogen und per Gleichheit gegen invnumber gejoint (nutzt
    -- ar_invnumber_key / ap_invnumber_key). Das ist exakt äquivalent zum
    -- Regex '(^|[^[:alnum:]])invnumber($|[^[:alnum:]])' — der verlangt
    -- ebenfalls, dass die Nummer einen vollständigen alnum-Block bildet —
    -- aber es vergleicht nicht mehr jeden Umsatz gegen jede Rechnung.
    mit_rechnungsbezug AS MATERIALIZED (
        SELECT DISTINCT k.id
        FROM kandidaten k
        CROSS JOIN LATERAL regexp_matches(
            coalesce(k.purpose, '') || ' ' || coalesce(k.end_to_end_id, ''),
            '[[:alnum:]]{4,}', 'g') AS t(token)
        WHERE EXISTS (SELECT 1 FROM ar x WHERE x.invnumber = t.token[1])
           OR EXISTS (SELECT 1 FROM ap y WHERE y.invnumber = t.token[1])
    )
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
                    AND bt.transdate >= ar.transdate
                    AND bt.amount <= (ar.amount - ar.paid) + 0.01
                    AND length(ar.invnumber) >= 4
                    AND bt.end_to_end_id IS NOT NULL
                    AND bt.end_to_end_id ~ ('(^|[^[:alnum:]])' || ar.invnumber || '($|[^[:alnum:]])')
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
              AND bt.amount > 0

            UNION ALL

            -- 1a. AR-Nummer im Purpose UND Kunden-IBAN passt (zwei Signale)
            SELECT bt.id AS bank_transaction_id,
                   'invnumber_and_iban'::TEXT AS match_type,
                   'ar'::TEXT AS target_type,
                   ar.id AS target_id,
                   0.98::NUMERIC AS confidence
            FROM bank_transactions bt
            JOIN customer c ON c.iban = bt.remote_account_number AND bt.remote_account_number IS NOT NULL
            JOIN ar ON ar.customer_id = c.id
                    AND (ar.amount - ar.paid) > 0.01
                    AND bt.transdate >= ar.transdate
                    AND bt.amount <= (ar.amount - ar.paid) + 0.01
                    AND length(ar.invnumber) >= 4
                    AND bt.purpose ~ ('(^|[^[:alnum:]])' || ar.invnumber || '($|[^[:alnum:]])')
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
              AND bt.amount > 0

            UNION ALL

            -- 1b. AR-Nummer im Purpose ohne IBAN. Der Zahler hat die Nummer
            --     selbst angegeben — das ist ein stärkeres Signal als ein
            --     zufällig passender Betrag und rangiert deshalb über 2.
            SELECT bt.id, 'invnumber_in_purpose', 'ar'::TEXT, ar.id, 0.94
            FROM bank_transactions bt
            JOIN ar ON (ar.amount - ar.paid) > 0.01
                    AND bt.transdate >= ar.transdate
                    AND bt.amount <= (ar.amount - ar.paid) + 0.01
                    AND length(ar.invnumber) >= 4
                    AND bt.purpose ~ ('(^|[^[:alnum:]])' || ar.invnumber || '($|[^[:alnum:]])')
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
              AND bt.amount > 0

            UNION ALL

            -- 2. Kunden-IBAN + exakter offener Betrag (Toleranz 0,01)
            SELECT bt.id, 'iban_amount_match', 'ar'::TEXT, ar.id, 0.92
            FROM bank_transactions bt
            JOIN customer c ON c.iban = bt.remote_account_number AND bt.remote_account_number IS NOT NULL
            JOIN ar ON ar.customer_id = c.id
                    AND ABS((ar.amount - ar.paid) - bt.amount) < 0.01
                    AND bt.transdate >= ar.transdate
                    AND ar.paid < ar.amount
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
              AND bt.amount > 0
              AND bt.id NOT IN (SELECT id FROM mit_rechnungsbezug)

            UNION ALL

            -- 3. Kunden-IBAN + Kunde hat genau eine offene Rechnung
            SELECT bt.id, 'iban_single_open', 'ar'::TEXT, ar.id, 0.80
            FROM bank_transactions bt
            JOIN customer c ON c.iban = bt.remote_account_number AND bt.remote_account_number IS NOT NULL
            JOIN LATERAL (
                SELECT id, amount, paid
                FROM ar
                WHERE customer_id = c.id AND (amount - paid) > 0.01
                  AND bt.transdate >= ar.transdate
                LIMIT 2
            ) ar_count ON true
            JOIN ar ON ar.id = ar_count.id
                    AND bt.amount <= (ar.amount - ar.paid) + 0.01
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
              AND bt.amount > 0
              AND bt.id NOT IN (SELECT id FROM mit_rechnungsbezug)
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
                    AND bt.transdate >= ar.transdate
                    AND ar.paid < ar.amount
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
              AND bt.amount > 0
              AND bt.remote_account_number IS NULL
              AND length(bt.remote_name) >= 4
              AND bt.id NOT IN (SELECT id FROM mit_rechnungsbezug)

            UNION ALL

            -- ── AUSGAENGE (eigene Zahlungen an Lieferanten) ──────────────────

            -- 0b. End-to-End-ID enthaelt AP-Nummer
            SELECT bt.id, 'end_to_end_id', 'ap'::TEXT, ap.id, 0.99
            FROM bank_transactions bt
            JOIN ap ON (ap.amount - ap.paid) > 0.01
                    AND bt.transdate >= ap.transdate
                    AND ABS(bt.amount) <= (ap.amount - ap.paid) + 0.01
                    AND length(ap.invnumber) >= 4
                    AND bt.end_to_end_id IS NOT NULL
                    AND bt.end_to_end_id ~ ('(^|[^[:alnum:]])' || ap.invnumber || '($|[^[:alnum:]])')
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
              AND bt.amount < 0

            UNION ALL

            -- 4a. AP-Nummer im Purpose UND Lieferanten-IBAN
            SELECT bt.id, 'invnumber_and_iban', 'ap'::TEXT, ap.id, 0.98
            FROM bank_transactions bt
            JOIN vendor v ON v.iban = bt.remote_account_number AND bt.remote_account_number IS NOT NULL
            JOIN ap ON ap.vendor_id = v.id
                    AND (ap.amount - ap.paid) > 0.01
                    AND bt.transdate >= ap.transdate
                    AND ABS(bt.amount) <= (ap.amount - ap.paid) + 0.01
                    AND length(ap.invnumber) >= 4
                    AND bt.purpose ~ ('(^|[^[:alnum:]])' || ap.invnumber || '($|[^[:alnum:]])')
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
              AND bt.amount < 0

            UNION ALL

            -- 4b. AP-Nummer im Purpose ohne IBAN
            SELECT bt.id, 'invnumber_in_purpose', 'ap'::TEXT, ap.id, 0.94
            FROM bank_transactions bt
            JOIN ap ON (ap.amount - ap.paid) > 0.01
                    AND bt.transdate >= ap.transdate
                    AND ABS(bt.amount) <= (ap.amount - ap.paid) + 0.01
                    AND length(ap.invnumber) >= 4
                    AND bt.purpose ~ ('(^|[^[:alnum:]])' || ap.invnumber || '($|[^[:alnum:]])')
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
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
                    AND bt.transdate >= ap.transdate
                    AND ABS(bt.amount) <= (ap.amount - ap.paid) + 0.01
                    AND length(ap.invnumber) >= 4
                    AND m.token[1] LIKE '%' || ap.invnumber
                    AND length(m.token[1]) - length(ap.invnumber) <= 3  -- max 3 Praefix-Zeichen
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
              AND bt.amount < 0

            UNION ALL

            -- 5. Lieferanten-IBAN + exakter offener Betrag (Toleranz 0,01)
            SELECT bt.id, 'iban_amount_match', 'ap'::TEXT, ap.id, 0.92
            FROM bank_transactions bt
            JOIN vendor v ON v.iban = bt.remote_account_number AND bt.remote_account_number IS NOT NULL
            JOIN ap ON ap.vendor_id = v.id
                    AND ABS((ap.amount - ap.paid) - ABS(bt.amount)) < 0.01
                    AND bt.transdate >= ap.transdate
                    AND ap.paid < ap.amount
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
              AND bt.amount < 0
              AND bt.id NOT IN (SELECT id FROM mit_rechnungsbezug)

            UNION ALL

            -- 6. Lieferanten-IBAN + Lieferant hat genau eine offene Rechnung
            SELECT bt.id, 'iban_single_open', 'ap'::TEXT, ap.id, 0.80
            FROM bank_transactions bt
            JOIN vendor v ON v.iban = bt.remote_account_number AND bt.remote_account_number IS NOT NULL
            JOIN LATERAL (
                SELECT id
                FROM ap
                WHERE vendor_id = v.id AND (amount - paid) > 0.01
                  AND bt.transdate >= ap.transdate
                LIMIT 2
            ) ap_count ON true
            JOIN ap ON ap.id = ap_count.id
                    AND ABS(bt.amount) <= (ap.amount - ap.paid) + 0.01
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
              AND bt.amount < 0
              AND bt.id NOT IN (SELECT id FROM mit_rechnungsbezug)
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
                AND (bmr.match_remote_iban IS NULL OR bt.remote_account_number = bmr.match_remote_iban)
                AND (bmr.match_remote_name IS NULL OR bt.remote_name ILIKE '%' || bmr.match_remote_name || '%')
                AND (bmr.match_purpose IS NULL OR bt.purpose ILIKE '%' || bmr.match_purpose || '%')
                AND (bmr.match_amount_min IS NULL OR ABS(bt.amount) >= bmr.match_amount_min)
                AND (bmr.match_amount_max IS NULL OR ABS(bt.amount) <= bmr.match_amount_max)
                AND (bmr.match_booking_key IS NULL OR bt.transaction_code = bmr.match_booking_key)
            WHERE bt.local_bank_account_id = p_bank_account_id
              AND COALESCE((SELECT e.match_status FROM bank_transactions_ext e WHERE e.bank_transaction_id = bt.id), 'unmatched') = 'unmatched'
        ) AS all_matches
        ORDER BY bank_transaction_id, confidence DESC
    ) AS best_per_tx
    ORDER BY confidence DESC, bank_transaction_id;
END;
$$ LANGUAGE plpgsql;

-- Drucker: opensource-erp-Zusatzfelder zur kivitendo-Tabelle printers
CREATE TABLE printers_ext (
    printer_id   integer NOT NULL REFERENCES printers(id) ON DELETE CASCADE,
    hide_factura boolean NOT NULL DEFAULT false,
    CONSTRAINT printers_ext_pkey PRIMARY KEY (printer_id)
);

COMMENT ON TABLE printers_ext IS 'opensource-erp-Erweiterung zu printers';
COMMENT ON COLUMN printers_ext.hide_factura IS 'Drucker in der Faktura-Druckerauswahl ausblenden';

-- Altlast: hide_factura lag frueher direkt in printers.
DO $printers_ext_legacy$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema = 'public' AND table_name = 'printers'
                 AND column_name = 'hide_factura') THEN
        INSERT INTO printers_ext (printer_id, hide_factura)
        SELECT id, COALESCE(hide_factura, false) FROM printers
        ON CONFLICT (printer_id) DO NOTHING;
        ALTER TABLE printers DROP COLUMN hide_factura;
    END IF;
END
$printers_ext_legacy$;

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

-- Altinstallationen: Frigate wurde durch den eigenen Kamera-Monitor ersetzt.
-- frigate_name/frigate_zone/frigate_event_id heissen jetzt cam_key/zone_key/event_id.
-- Das Update-System legt nur neue Spalten an, die alten bleiben stehen und
-- blockieren mit ihrem NOT NULL jedes INSERT — deshalb hier umziehen und entfernen.
DO $camera_legacy$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema = 'public' AND table_name = 'camera'
                 AND column_name = 'frigate_name') THEN
        UPDATE camera SET cam_key = frigate_name WHERE cam_key IS NULL OR cam_key = '';
        ALTER TABLE camera DROP COLUMN frigate_name;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema = 'public' AND table_name = 'camera_zone'
                 AND column_name = 'frigate_zone') THEN
        UPDATE camera_zone SET zone_key = frigate_zone WHERE zone_key IS NULL OR zone_key = '';
        ALTER TABLE camera_zone DROP COLUMN frigate_zone;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema = 'public' AND table_name = 'camera_event'
                 AND column_name = 'frigate_event_id') THEN
        UPDATE camera_event SET event_id = frigate_event_id WHERE event_id IS NULL;
        ALTER TABLE camera_event DROP COLUMN frigate_event_id;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'camera'::regclass AND conname = 'camera_cam_key_unique')
       AND NOT EXISTS (SELECT 1 FROM camera WHERE cam_key IS NOT NULL
                       GROUP BY cam_key HAVING count(*) > 1) THEN
        ALTER TABLE camera ADD CONSTRAINT camera_cam_key_unique UNIQUE (cam_key);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'camera_zone'::regclass AND conname = 'camera_zone_unique')
       AND NOT EXISTS (SELECT 1 FROM camera_zone
                       GROUP BY camera_id, zone_key HAVING count(*) > 1) THEN
        ALTER TABLE camera_zone ADD CONSTRAINT camera_zone_unique UNIQUE (camera_id, zone_key);
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conrelid = 'camera_event'::regclass AND conname = 'camera_event_id_unique')
       AND NOT EXISTS (SELECT 1 FROM camera_event WHERE event_id IS NOT NULL
                       GROUP BY event_id HAVING count(*) > 1) THEN
        ALTER TABLE camera_event ADD CONSTRAINT camera_event_id_unique UNIQUE (event_id);
    END IF;
END
$camera_legacy$;

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
    ar_id           INTEGER REFERENCES ar(id),   -- Verknuepfung zur Ausgangsrechnung (archiviertes Versandexemplar)

    -- Meta
    employee_id     INTEGER,                     -- wer den Beleg abgelegt hat (GoBD: Nachvollziehbarkeit)
    notes           TEXT,
    retention_until DATE,                        -- Ende der Aufbewahrungsfrist (AO §147)
    source_id       INTEGER,                     -- Belegsuche: Quelle (beleg_quellen.id), NULL = manueller Upload
    origin          TEXT,                        -- Belegsuche: Kennung in der Quelle (Mail-UID, Dateipfad, WhatsApp-Nachricht)
    origin_info     JSONB,                       -- Belegsuche: Absender, Betreff, Empfangszeit ...
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

-- -----------------------------------------------------------------------------
-- MAHNWESEN
--
-- Die kivitendo-Tabellen bleiben unverändert und werden wie in kivitendo
-- genutzt: dunning_config (Mahnstufen), dunning (eine Zeile je gemahnter
-- Rechnung, dunning_id = Nummer des Mahnbriefs), ar.dunning_config_id
-- (aktuelle Mahnstufe der Rechnung), customer.dunning_lock / dunning_mail
-- und defaults.dunning_ar_amount_fee / dunning_ar_amount_interest / dunning_ar
-- (Konten der Gebührenrechnung). Was OS-ERP darüber hinaus braucht, liegt in
-- den beiden _ext-Tabellen daneben.
-- -----------------------------------------------------------------------------

-- Je Mahnstufe der Brieftext, den der Benutzer in der Oberfläche pflegt.
-- kivitendo kennt nur E-Mail-Betreff und -Text; der Brieftext steckte dort in
-- der LaTeX-Vorlage und war ohne Vorlagenkenntnisse nicht änderbar.
CREATE TABLE IF NOT EXISTS dunning_config_ext (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dunning_config_id integer NOT NULL,
    letter_text text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    CONSTRAINT dunning_config_ext_dunning_config_id_unique UNIQUE (dunning_config_id)
);

COMMENT ON TABLE dunning_config_ext IS 'Zusatzfelder je Mahnstufe (dunning_config)';
COMMENT ON COLUMN dunning_config_ext.dunning_config_id IS 'Referenz auf dunning_config.id';
COMMENT ON COLUMN dunning_config_ext.letter_text IS 'Einleitungstext des Mahnbriefs dieser Stufe';

-- Ein Mahnbrief (alle Rechnungen eines Kunden auf derselben Stufe in einem
-- Lauf). dunning_id ist die Briefnummer aus dunning.dunning_id.
CREATE TABLE IF NOT EXISTS dunning_ext (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dunning_id integer NOT NULL,
    customer_id integer,
    employee_id integer,
    channel text,
    email_to text,
    sent_at timestamp without time zone,
    document_id integer,
    open_total numeric(15,2),
    fee_total numeric(15,2),
    interest_total numeric(15,2),
    error text,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    CONSTRAINT dunning_ext_dunning_id_unique UNIQUE (dunning_id)
);

COMMENT ON TABLE dunning_ext IS 'Mahnbrief: Versand, Ablage und Summen je dunning.dunning_id';
COMMENT ON COLUMN dunning_ext.dunning_id IS 'Briefnummer (dunning.dunning_id)';
COMMENT ON COLUMN dunning_ext.customer_id IS 'Gemahnter Kunde';
COMMENT ON COLUMN dunning_ext.employee_id IS 'Mitarbeiter, der den Mahnlauf gestartet hat';
COMMENT ON COLUMN dunning_ext.channel IS 'Versandweg: email, print oder none';
COMMENT ON COLUMN dunning_ext.email_to IS 'Empfängeradresse beim Versand per E-Mail';
COMMENT ON COLUMN dunning_ext.sent_at IS 'Zeitpunkt des Versands (E-Mail) bzw. der PDF-Erzeugung (Druck)';
COMMENT ON COLUMN dunning_ext.document_id IS 'Archiviertes Versandexemplar in accounting_documents';
COMMENT ON COLUMN dunning_ext.open_total IS 'Summe der offenen Beträge der gemahnten Rechnungen zum Mahnzeitpunkt';
COMMENT ON COLUMN dunning_ext.fee_total IS 'Mahngebühr des Briefs';
COMMENT ON COLUMN dunning_ext.interest_total IS 'Verzugszinsen des Briefs';
COMMENT ON COLUMN dunning_ext.error IS 'Letzter Fehler bei PDF-Erzeugung oder Versand';

CREATE INDEX IF NOT EXISTS idx_dunning_ext_customer_id ON dunning_ext(customer_id);

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

-- Zugriffsprotokoll fuer Belege (GoBD: Nachvollziehbarkeit und Unveraenderbarkeit).
-- Haelt fest, wer einen Beleg abgelegt, angesehen oder geprueft hat. Wird nur
-- angehaengt, nie geaendert oder geloescht.
CREATE TABLE IF NOT EXISTS accounting_document_log (
    id          INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    document_id INTEGER NOT NULL REFERENCES accounting_documents(id) ON DELETE CASCADE,
    employee_id INTEGER,                          -- wer; NULL nur bei Hintergrundlaeufen
    aktion      VARCHAR(20) NOT NULL
                CHECK (aktion IN ('ablage', 'ansicht', 'pruefung', 'verknuepfung')),
    ergebnis    VARCHAR(20),                      -- bei 'pruefung': ok, geaendert, fehlt
    hinweis     TEXT,
    itime       TIMESTAMP DEFAULT NOW()
);

COMMENT ON TABLE accounting_document_log IS 'Protokoll aller Zugriffe auf Buchhaltungsbelege (GoBD)';

CREATE INDEX IF NOT EXISTS idx_cash_gl_documents_gl ON cash_gl_documents(gl_id);

-- Belege direkt an einem Bankumsatz. Belege zu Eingangs-/Ausgangsrechnungen
-- haengen ueber accounting_documents.ap_id/ar_id an der Rechnung; hier liegen
-- die Belege, die es ohne Rechnung gibt (Gebuehrenabrechnung, Kontoauszug-
-- seite, Vertrag, Lieferschein, Mahnung) — beliebig viele je Umsatz, auch
-- nachtraeglich zu einem bereits gebuchten Umsatz.
CREATE TABLE IF NOT EXISTS bank_transaction_documents (
    id                  INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    bank_transaction_id INTEGER NOT NULL REFERENCES bank_transactions(id) ON DELETE CASCADE,
    document_id         INTEGER NOT NULL REFERENCES accounting_documents(id) ON DELETE CASCADE,
    itime               TIMESTAMP DEFAULT NOW(),
    CONSTRAINT bank_transaction_documents_uniq UNIQUE (bank_transaction_id, document_id)
);

COMMENT ON TABLE bank_transaction_documents IS 'Verbindet hochgeladene Belege (accounting_documents) direkt mit Bankumsaetzen; mehrere Belege je Umsatz';

CREATE INDEX IF NOT EXISTS idx_bank_transaction_documents_bt ON bank_transaction_documents(bank_transaction_id);

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

COMMENT ON TABLE payment_settlements IS 'Hochgeladene Sammelabrechnungen von Kartendienstleistern (Flatpay/Rapyd, SumUp). Datei liegt als accounting_documents beim Kreditor.';

-- ============================================================
-- SumUp-Terminal: Protokoll der aus der Faktura gestarteten Karten-Checkouts
-- (Cloud API). Ein Eintrag je an das Terminal gesendetem Betrag; das
-- Ergebnis (Transaktionscode, Kartentyp) traegt sumupCheckoutStatus() nach.
-- Der Transaktionscode dient dem spaeteren Abgleich der Sammelauszahlung.
-- ============================================================
CREATE TABLE IF NOT EXISTS sumup_checkouts (
    id                      SERIAL PRIMARY KEY,
    trans_id                INTEGER,                                -- ar.id der Rechnung (optional)
    amount                  NUMERIC(15,2) NOT NULL,
    currency                VARCHAR(3) DEFAULT 'EUR',
    description             TEXT,
    client_transaction_id   TEXT NOT NULL,                          -- Kennung aus der Checkout-Antwort
    checkout_id             TEXT,
    status                  VARCHAR(20) NOT NULL DEFAULT 'pending'  -- pending | successful | failed | cancelled
                            CHECK (status IN ('pending', 'successful', 'failed', 'cancelled')),
    transaction_code        TEXT,                                   -- SumUp-Transaktionscode (z. B. TEENSK4W2K)
    card_type               TEXT,
    receipt                 JSONB,                                  -- Kundenbeleg (SumUp Receipts API), wird als Belegseite mitgedruckt
    employee_id             INTEGER,
    itime                   TIMESTAMP DEFAULT NOW(),
    mtime                   TIMESTAMP DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_sumup_checkouts_ctid ON sumup_checkouts(client_transaction_id);
CREATE INDEX IF NOT EXISTS idx_sumup_checkouts_trans ON sumup_checkouts(trans_id);

COMMENT ON TABLE sumup_checkouts IS 'Aus der Faktura an das SumUp-Terminal gesendete Karten-Checkouts mit Ergebnis.';

CREATE TABLE IF NOT EXISTS payment_settlement_lines (
    id                          SERIAL PRIMARY KEY,
    settlement_id               INTEGER NOT NULL REFERENCES payment_settlements(id) ON DELETE CASCADE,
    payout_date                 DATE NOT NULL,                  -- Auszahlungsdatum (= Bank-Valuta)
    period_from                 DATE,                           -- "Deckt den Zeitraum ab" (von)
    period_to                   DATE,                           -- "Deckt den Zeitraum ab" (bis)
    gross                       NUMERIC(15,2) NOT NULL DEFAULT 0,  -- Gesamteinnahmen (brutto)
    fee                         NUMERIC(15,2) NOT NULL DEFAULT 0,  -- Transaktionskosten (positiv)
    net                         NUMERIC(15,2) NOT NULL DEFAULT 0,  -- Nettoauszahlung (= erwarteter Bankbetrag)
    reference                   TEXT,                           -- Auszahlungs-Kennung des Dienstleisters (SumUp "PID1283966"), steht im Bank-Verwendungszweck
    transactions                JSONB,                          -- einzelne Kartenzahlungen der Auszahlung [{code, timestamp, gross, fee, net, description}] fuer die Rechnungszuordnung je Zahlung
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

-- Belegsuche ("Magisch Buchen"): Quellen, aus denen Eingangsbelege automatisch
-- geholt werden (Postfach per IMAP, WhatsApp-Eingang, Server-Ordner, Lieferanten-
-- Portal mit Login). Passwoerter liegen verschluesselt in secret (lib/secrets.php).
CREATE TABLE IF NOT EXISTS beleg_quellen (
    id              SERIAL PRIMARY KEY,
    type            VARCHAR(20) NOT NULL CHECK (type IN ('imap', 'folder', 'whatsapp', 'portal')),
    name            TEXT NOT NULL,
    active          BOOLEAN DEFAULT TRUE,
    config          JSONB DEFAULT '{}'::jsonb,    -- imap: host, port, encryption, username, folder, use_company_mailbox; folder: path, move_processed; portal: url, adapter, username
    secret          TEXT,                         -- verschluesseltes Passwort (AES-256-GCM, lib/secrets.php)
    vendor_id       INTEGER REFERENCES vendor(id), -- Portal: zugehoeriger Lieferant
    last_run_at     TIMESTAMP,
    last_status     VARCHAR(20),                  -- ok | error | unsupported
    last_message    TEXT,
    last_found      INTEGER DEFAULT 0,            -- beim letzten Lauf importierte Belege
    employee_id     INTEGER,
    itime           TIMESTAMP DEFAULT NOW(),
    mtime           TIMESTAMP DEFAULT NOW()
);

COMMENT ON TABLE beleg_quellen IS 'Belegsuche: Quellen fuer den automatischen Belegimport (Magisch Buchen).';

-- Je gefundenem Element (Mail-Anhang, Datei, WhatsApp-Medium) ein Eintrag, damit
-- nichts zweimal verarbeitet wird und Uebersprungenes nachvollziehbar bleibt.
CREATE TABLE IF NOT EXISTS beleg_quellen_log (
    id              SERIAL PRIMARY KEY,
    source_id       INTEGER NOT NULL REFERENCES beleg_quellen(id) ON DELETE CASCADE,
    origin          TEXT NOT NULL,                -- Kennung in der Quelle (z. B. "INBOX#4711#2:rechnung.pdf")
    result          VARCHAR(20) NOT NULL          -- imported | duplicate | not_invoice | skipped | error
                    CHECK (result IN ('imported', 'duplicate', 'not_invoice', 'skipped', 'error')),
    filename        TEXT,
    document_id     INTEGER REFERENCES accounting_documents(id) ON DELETE SET NULL,
    booking_id      INTEGER,
    message         TEXT,
    info            JSONB,                        -- Absender, Betreff, Datum ...
    itime           TIMESTAMP DEFAULT NOW(),
    UNIQUE (source_id, origin)
);

CREATE INDEX IF NOT EXISTS idx_beleg_quellen_log_source ON beleg_quellen_log(source_id, itime DESC);

COMMENT ON TABLE beleg_quellen_log IS 'Belegsuche: verarbeitete Elemente je Quelle (Idempotenz + Nachvollziehbarkeit).';

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
    mtime           TIMESTAMP DEFAULT NOW(),
    -- Verkäufe mit Prüfung (dev/shop-ebay.md): erst Käufer klären, dann Rechnung
    state           VARCHAR(20) DEFAULT 'invoiced',             -- invoiced, review, waiting_payment, cancelled, history, error
    match           JSONB,                                      -- Kundenabgleich: Stufe, Vorschlag, Kandidaten mit Gründen
    payment_status  VARCHAR(30),                                -- eBay orderPaymentStatus (PAID, PENDING, FULLY_REFUNDED …)
    cancel_state    VARCHAR(30),                                -- eBay cancelStatus.cancelState (NONE_REQUESTED, CANCELED …)
    ordered_at      TIMESTAMP,                                  -- creationDate der Bestellung bei eBay
    modified_at     TIMESTAMP,                                  -- lastModifiedDate bei eBay
    error           TEXT,                                       -- letzter Fehler beim Abrechnen
    decided_by      TEXT,                                       -- wer den Käufer geklärt hat (Login, auto)
    decided_at      TIMESTAMP,
    tracking_number TEXT,                                       -- Sendungsnummer, an eBay gemeldet
    carrier         TEXT,                                       -- Versanddienst für eBay (DHL, DPD, HERMES …)
    shipped_at      TIMESTAMP,                                  -- an eBay als versendet gemeldet
    ship_error      TEXT                                        -- letzte Ablehnung der Versandmeldung
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

-- ═══════════════════════════════════════════════════════════════════════════
-- LAGER — Inventur-Sitzungen
-- ═══════════════════════════════════════════════════════════════════════════
--
-- Die Zaehlergebnisse selbst liegen in der kivitendo-Tabelle `stocktakings`
-- (dort ist inventory_id NULL, solange die Differenz noch nicht gebucht ist).
-- Diese Tabelle klammert eine Zaehlung nur organisatorisch: Name, Lager,
-- Stichtag, Status. Verknuepft wird ueber (warehouse_id, cutoff_date) — dadurch
-- bleibt `stocktakings` unveraendert kivitendo-kompatibel.
CREATE TABLE IF NOT EXISTS stocktaking_sessions (
    id              SERIAL PRIMARY KEY,
    name            TEXT NOT NULL,                              -- z. B. "Jahresinventur 2026"
    warehouse_id    INTEGER NOT NULL REFERENCES warehouse(id),  -- gezaehltes Lager
    cutoff_date     DATE NOT NULL,                              -- Stichtag (= stocktakings.cutoff_date)
    status          VARCHAR(20) NOT NULL DEFAULT 'open'
                    CHECK (status IN ('open', 'posted', 'cancelled')),
    employee_id     INTEGER,                                    -- wer die Zaehlung angelegt hat
    posted_at       TIMESTAMP,                                  -- wann die Differenzen gebucht wurden
    itime           TIMESTAMP DEFAULT NOW(),
    mtime           TIMESTAMP DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_stocktaking_sessions_key
    ON stocktaking_sessions(warehouse_id, cutoff_date);

COMMENT ON TABLE stocktaking_sessions IS 'Klammer um eine Inventur; die Zaehlzeilen stehen in der kivitendo-Tabelle stocktakings und werden ueber (warehouse_id, cutoff_date) zugeordnet.';

-- ═══════════════════════════════════════════════════════════════════════════
-- UMSATZSTEUER-VORANMELDUNG (UStVA)
-- ═══════════════════════════════════════════════════════════════════════════

-- Kennzahlen des amtlichen UStVA-Formulars.
-- Steht bewusst in der Datenbank statt im Code: das Formular aendert sich
-- gelegentlich und laesst sich so ohne Deployment nachziehen.
--   section : umsatz | umsatz_frei | erwerb | reverse | steuer | vorsteuer | ergebnis
--   kind    : base = Bemessungsgrundlage (volle Euro), tax = Steuerbetrag (mit Cent)
--   rate    : Steuersatz zur Berechnung der Steuer aus der Bemessungsgrundlage
--             (NULL, wenn die Steuer separat als eigene Kennzahl gemeldet wird)
CREATE TABLE IF NOT EXISTS ustva_kennzahlen (
    kz          INTEGER PRIMARY KEY,
    label       TEXT NOT NULL,
    section     VARCHAR(20) NOT NULL,
    kind        VARCHAR(10) NOT NULL CHECK (kind IN ('base', 'tax')),
    rate        NUMERIC(15,5),
    sortkey     INTEGER NOT NULL DEFAULT 0
);

COMMENT ON TABLE ustva_kennzahlen IS 'Kennzahlen des amtlichen UStVA-Formulars inkl. Abschnitt, Art (Bemessungsgrundlage/Steuer) und Steuersatz.';

-- Zuordnung Buchung -> UStVA-Kennzahl.
-- Eine Buchungszeile wird ueber Steuerschluessel + Steuersatz zugeordnet; ein
-- Eintrag mit gesetzter chart_id gewinnt gegenueber dem allgemeinen Eintrag
-- (noetig z. B. bei § 13b, wo Vorsteuer und Umsatzsteuer denselben
-- Steuerschluessel tragen und nur das Konto sie unterscheidet).
--   role      : base = Bemessungsgrundlage (AR_amount/AP_amount), tax = Steuerkonto (AR_tax/AP_tax)
--   direction : revenue = Ausgangsseite, expense = Eingangsseite
CREATE TABLE IF NOT EXISTS ustva_mapping (
    id          SERIAL PRIMARY KEY,
    taxkey      INTEGER,                                    -- NULL = beliebig (nur mit chart_id sinnvoll)
    rate        NUMERIC(15,5),                              -- NULL = beliebiger Steuersatz
    chart_id    INTEGER,                                    -- NULL = alle Konten; sonst Vorrang fuer dieses Konto
    role        VARCHAR(10) NOT NULL CHECK (role IN ('base', 'tax')),
    direction   VARCHAR(10) NOT NULL CHECK (direction IN ('revenue', 'expense')),
    kz          INTEGER NOT NULL,
    description TEXT,
    itime       TIMESTAMP DEFAULT NOW(),
    mtime       TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ustva_mapping_lookup ON ustva_mapping(role, direction, taxkey);

COMMENT ON TABLE ustva_mapping IS 'Zuordnung von Steuerschluessel/Steuersatz (optional Konto) zu einer UStVA-Kennzahl; in der Oberflaeche editierbar.';

-- Abgegebene Voranmeldungen. Der Kennzahlen-Stand wird beim Abgeben als
-- Momentaufnahme gesichert, damit spaetere Buchungen die abgegebenen Zahlen
-- nicht rueckwirkend veraendern (und eine Berichtigung erkennbar wird).
CREATE TABLE IF NOT EXISTS ustva_filings (
    id              SERIAL PRIMARY KEY,
    period_start    DATE NOT NULL,
    period_end      DATE NOT NULL,
    period_type     VARCHAR(10) NOT NULL CHECK (period_type IN ('month', 'quarter', 'year')),
    method          VARCHAR(10) NOT NULL,                   -- accrual (Soll) | cash (Ist)
    payload         JSONB,                                  -- Momentaufnahme aller Kennzahlen
    vat_payable     NUMERIC(15,2),                          -- Kennzahl 83 zum Zeitpunkt der Abgabe
    filed_at        TIMESTAMP,
    filed_by        INTEGER,                                -- employee.id
    note            TEXT,
    itime           TIMESTAMP DEFAULT NOW(),
    mtime           TIMESTAMP DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_ustva_filings_period
    ON ustva_filings(period_start, period_end);

COMMENT ON TABLE ustva_filings IS 'Als abgegeben markierte Voranmeldungen inkl. Momentaufnahme der Kennzahlen zum Abgabezeitpunkt.';

-- ── Stammdaten: Kennzahlen des amtlichen Formulars ─────────────────────────
INSERT INTO ustva_kennzahlen (kz, label, section, kind, rate, sortkey) VALUES
    -- Kennzahl 0 ist keine Formularzeile, sondern die bewusste Entscheidung
    -- "gehoert nicht in die Voranmeldung" — etwa durchlaufende Posten nach
    -- § 10 Abs. 1 Satz 6 UStG. Ohne diese Moeglichkeit muessten solche Konten
    -- dauerhaft als "nicht zugeordnet" gemeldet bleiben.
    ( 0, 'Nicht melden (durchlaufender Posten / nicht steuerbar)',         'ignoriert',   'base', NULL, 900),
    (81, 'Steuerpflichtige Umsätze zum Steuersatz von 19 %',                     'umsatz',      'base', 0.19, 10),
    (86, 'Steuerpflichtige Umsätze zum Steuersatz von 7 %',                      'umsatz',      'base', 0.07, 20),
    (35, 'Umsätze zu anderen Steuersätzen',                                      'umsatz',      'base', NULL, 30),
    (36, 'Steuer auf Umsätze zu anderen Steuersätzen',                           'umsatz',      'tax',  NULL, 31),
    (77, 'Umsätze land- und forstwirtschaftlicher Betriebe (§ 24 UStG)',         'umsatz',      'base', NULL, 40),
    (41, 'Innergemeinschaftliche Lieferungen an Abnehmer mit USt-IdNr.',         'umsatz_frei', 'base', 0,    50),
    (44, 'Weitere steuerfreie Umsätze mit Vorsteuerabzug',                       'umsatz_frei', 'base', 0,    60),
    (48, 'Umsätze nach § 4 Nr. 8 ff. UStG (ohne Vorsteuerabzug)',                'umsatz_frei', 'base', 0,    70),
    (21, 'Nicht steuerbare sonstige Leistungen (§ 18b Satz 1 Nr. 2 UStG)',       'umsatz_frei', 'base', 0,    80),
    (60, 'Steuerpflichtige Umsätze, für die der Leistungsempfänger die Steuer nach § 13b UStG schuldet', 'umsatz_frei', 'base', 0, 85),
    (45, 'Übrige nicht steuerbare Umsätze (Leistungsort nicht im Inland)',       'umsatz_frei', 'base', 0,    90),
    (89, 'Innergemeinschaftliche Erwerbe zum Steuersatz von 19 %',               'erwerb',      'base', 0.19, 100),
    (93, 'Innergemeinschaftliche Erwerbe zum Steuersatz von 7 %',                'erwerb',      'base', 0.07, 110),
    (95, 'Innergemeinschaftliche Erwerbe zu anderen Steuersätzen',               'erwerb',      'base', NULL, 120),
    (98, 'Steuer auf innergemeinschaftliche Erwerbe zu anderen Steuersätzen',    'erwerb',      'tax',  NULL, 121),
    (91, 'Steuerfreie innergemeinschaftliche Erwerbe',                           'erwerb',      'base', 0,    130),
    (46, 'Leistungen eines im Ausland ansässigen Unternehmers (§ 13b UStG)',     'reverse',     'base', NULL, 140),
    (47, 'Steuer auf Leistungen nach § 13b UStG',                                'reverse',     'tax',  NULL, 141),
    (69, 'Steuerbeträge, die vom letzten Abnehmer geschuldet werden',            'steuer',      'tax',  NULL, 150),
    (66, 'Vorsteuerbeträge aus Rechnungen von anderen Unternehmern',             'vorsteuer',   'tax',  NULL, 200),
    (61, 'Vorsteuerbeträge aus dem innergemeinschaftlichen Erwerb',              'vorsteuer',   'tax',  NULL, 210),
    (62, 'Entstandene Einfuhrumsatzsteuer',                                      'vorsteuer',   'tax',  NULL, 220),
    (67, 'Vorsteuerbeträge aus Leistungen nach § 13b UStG',                      'vorsteuer',   'tax',  NULL, 230),
    (63, 'Vorsteuerbeträge, berechnet nach Durchschnittssätzen',                 'vorsteuer',   'tax',  NULL, 240),
    (64, 'Berichtigung des Vorsteuerabzugs (§ 15a UStG)',                        'vorsteuer',   'tax',  NULL, 250),
    (59, 'Vorsteuerabzug für innergem. Lieferungen neuer Fahrzeuge',             'vorsteuer',   'tax',  NULL, 260),
    (39, 'Anrechnung der Sondervorauszahlung',                                   'ergebnis',    'tax',  NULL, 300),
    (83, 'Verbleibende Umsatzsteuer-Vorauszahlung / verbleibender Überschuss',   'ergebnis',    'tax',  NULL, 310)
ON CONFLICT (kz) DO NOTHING;

-- ── Stammdaten: Standardzuordnung der kivitendo-Steuerschlüssel ────────────
-- Je Kombination aus Rolle, Richtung, Steuerschlüssel und Steuersatz wird nur
-- dann ein Eintrag angelegt, wenn es ihn noch nicht gibt. Dadurch kommen neue
-- Vorgaben bei einem Update dazu, ohne eigene Anpassungen zu überschreiben.
INSERT INTO ustva_mapping (taxkey, rate, role, direction, kz, description)
SELECT seed.* FROM (VALUES
    -- Ausgangsseite: Bemessungsgrundlagen
    ( 1, 0.00000::numeric, 'base', 'revenue', 48, 'USt-frei'),
    ( 2, 0.07000::numeric, 'base', 'revenue', 86, 'Umsatzsteuer 7 %'),
    ( 2, 0.05000::numeric, 'base', 'revenue', 35, 'Umsatzsteuer 5 % (anderer Steuersatz)'),
    ( 3, 0.19000::numeric, 'base', 'revenue', 81, 'Umsatzsteuer 19 %'),
    ( 3, 0.16000::numeric, 'base', 'revenue', 35, 'Umsatzsteuer 16 % (anderer Steuersatz)'),
    ( 5, 0.16000::numeric, 'base', 'revenue', 35, 'Umsatzsteuer 16 % (anderer Steuersatz)'),
    (10, 0.00000::numeric, 'base', 'revenue', 21, 'Im anderen EU-Staat steuerpflichtige Lieferung'),
    (11, 0.00000::numeric, 'base', 'revenue', 41, 'Steuerfreie innergem. Lieferung an Abnehmer mit USt-IdNr.'),
    (12, 0.07000::numeric, 'base', 'revenue', 93, 'Innergemeinschaftlicher Erwerb 7 %'),
    (13, 0.19000::numeric, 'base', 'revenue', 89, 'Innergemeinschaftlicher Erwerb 19 %'),
    -- Ausgangsseite: Steuerkonten. Bei 81/86/89/93 rechnet das Finanzamt die
    -- Steuer selbst aus der Bemessungsgrundlage; die gebuchte Steuer dient hier
    -- als Kontrollwert. Bei "anderen Steuersätzen" wird sie separat gemeldet.
    ( 2, 0.07000::numeric, 'tax',  'revenue', 86, 'Umsatzsteuer 7 % (Kontrollwert)'),
    ( 3, 0.19000::numeric, 'tax',  'revenue', 81, 'Umsatzsteuer 19 % (Kontrollwert)'),
    (12, 0.07000::numeric, 'tax',  'revenue', 93, 'Umsatzsteuer innergem. Erwerb 7 % (Kontrollwert)'),
    (13, 0.19000::numeric, 'tax',  'revenue', 89, 'Umsatzsteuer innergem. Erwerb 19 % (Kontrollwert)'),
    ( 2, 0.05000::numeric, 'tax',  'revenue', 36, 'Steuer 5 %'),
    ( 3, 0.16000::numeric, 'tax',  'revenue', 36, 'Steuer 16 %'),
    ( 5, 0.16000::numeric, 'tax',  'revenue', 36, 'Steuer 16 %'),
    -- Eingangsseite: Vorsteuer
    ( 7, 0.16000::numeric, 'tax',  'expense', 66, 'Vorsteuer 16 %'),
    ( 8, 0.05000::numeric, 'tax',  'expense', 66, 'Vorsteuer 5 %'),
    ( 8, 0.07000::numeric, 'tax',  'expense', 66, 'Vorsteuer 7 %'),
    ( 9, 0.16000::numeric, 'tax',  'expense', 66, 'Vorsteuer 16 %'),
    ( 9, 0.19000::numeric, 'tax',  'expense', 66, 'Vorsteuer 19 %'),
    (18, 0.07000::numeric, 'tax',  'expense', 61, 'Vorsteuer aus innergem. Erwerb 7 %'),
    (19, 0.19000::numeric, 'tax',  'expense', 61, 'Vorsteuer aus innergem. Erwerb 19 %'),
    -- Eingangsseite: § 13b — Bemessungsgrundlage und Vorsteuer
    (94, 0.19000::numeric, 'base', 'expense', 46, 'Leistung nach § 13b UStG 19 %'),
    (94, 0.19000::numeric, 'tax',  'expense', 67, 'Vorsteuer aus Leistungen nach § 13b UStG')
) AS seed(taxkey, rate, role, direction, kz, description)
WHERE NOT EXISTS (
    SELECT 1 FROM ustva_mapping m
    WHERE m.role      = seed.role
      AND m.direction = seed.direction
      AND m.taxkey IS NOT DISTINCT FROM seed.taxkey
      AND m.rate   IS NOT DISTINCT FROM seed.rate
      AND m.chart_id IS NULL
);

-- ============================================================================
-- REVERSE CHARGE (§ 13b UStG) IN DER FAKTURA
-- ============================================================================
-- Umsätze, bei denen der Leistungsempfänger die Umsatzsteuer schuldet (Bau-
-- leistungen, Gebäudereinigung, Schrott, Mobilfunkgeräte …), laufen wie in
-- kivitendo über eine eigene Steuerzone: Buchungsgruppe × Zone → Erlöskonto
-- „Erlöse aus Leistungen, für die der Leistungsempfänger die Umsatzsteuer nach
-- § 13b UStG schuldet" (SKR04 4337, SKR03 8337) → Steuerschlüssel 0 → 0 %.
-- Damit stimmen Positionen, Buchungssätze, Druck, E-Rechnung und UStVA (Kz 60)
-- ohne Sonderfälle im Code.
--
-- Der Schalter feature_reverse_charge (Firmenkonfiguration → Features) legt die
-- Zone beim Einschalten an bzw. aktiviert sie wieder, beim Ausschalten wird
-- sie nur ausgeblendet (obsolete) — bestehende Belege behalten ihre Zone und
-- drucken den Hinweissatz weiterhin.
INSERT INTO defaults_oserp (key, value) VALUES
    ('feature_reverse_charge', '0')
ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES
    ('reverse_charge_text', 'Leistungen unterliegen gemäß § 13b UStG dem Reverse-Charge-Verfahren (Steuerschuldnerschaft des Leistungsempfängers).')
ON CONFLICT (key) DO NOTHING;

-- Schaltet Reverse Charge firmenweit ein oder aus und hält dabei alle
-- Stammdaten konsistent. Idempotent — darf bei jedem Speichern der
-- Firmenkonfiguration laufen (neue Buchungsgruppen bekommen so ihre Zuordnung).
--
-- Beim Einschalten:
--   1. Erlöskonto nach Kontenrahmen bestimmen (defaults.coa), bei Bedarf anlegen, Verknüpfung
--      AR_amount:IC_sale:IC_income sicherstellen (nötig für Buchungsgruppen,
--      Buchung und die UStVA-Einordnung) und Steuerschlüssel 0 / Kz 60 am Konto
--   2. Steuerzone anlegen oder reaktivieren (Merker reverse_charge_taxzone_id)
--   3. taxzone_charts für jede Buchungsgruppe ohne Eintrag: Erlös = § 13b-Konto,
--      Aufwand wie in der Zone mit dem kleinsten sortkey (Inland)
--   4. UStVA-Zuordnung des Erlöskontos auf Kennzahl 60
-- Beim Ausschalten: Zone obsolete = true, Schalter 0 — sonst bleibt alles.
CREATE OR REPLACE FUNCTION reverse_charge_set(p_enabled boolean)
    RETURNS json
    LANGUAGE plpgsql
AS $BODY$
DECLARE
    v_zone_id   integer;
    v_chart_id  integer;
    v_accno     text;
    v_coa       text;
    v_desc      text := 'Reverse Charge § 13b UStG';
BEGIN
    SELECT NULLIF(btrim(value), '')::integer INTO v_zone_id
      FROM defaults_oserp WHERE key = 'reverse_charge_taxzone_id';
    IF v_zone_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM tax_zones WHERE id = v_zone_id) THEN
        v_zone_id := NULL;
    END IF;
    IF v_zone_id IS NULL THEN
        SELECT id INTO v_zone_id FROM tax_zones WHERE description = v_desc ORDER BY id LIMIT 1;
    END IF;

    IF NOT p_enabled THEN
        IF v_zone_id IS NOT NULL THEN
            UPDATE tax_zones SET obsolete = true WHERE id = v_zone_id;
        END IF;
        INSERT INTO defaults_oserp (key, value) VALUES ('feature_reverse_charge', '0')
        ON CONFLICT (key) DO UPDATE SET value = '0', mtime = now();
        RETURN json_build_object('enabled', false, 'taxzone_id', v_zone_id);
    END IF;

    -- 1. Erlöskonto je Kontenrahmen
    SELECT coa INTO v_coa FROM defaults LIMIT 1;
    v_accno := CASE WHEN v_coa ILIKE '%SKR03%' THEN '8337' ELSE '4337' END;
    SELECT id INTO v_chart_id FROM chart WHERE accno = v_accno LIMIT 1;
    -- Die mitgelieferten SKR03/SKR04-Kontenrahmen enthalten das Konto nicht —
    -- dann hier anlegen (Erlöskonto, steuerfrei, BWA/EÜR wie die übrigen Erlöse).
    IF v_chart_id IS NULL THEN
        INSERT INTO chart (accno, description, charttype, category, link, taxkey_id,
                           pos_bwa, pos_eur, pos_er, datevautomatik)
        VALUES (v_accno, 'Erlöse aus Leistungen nach § 13b UStG', 'A', 'I',
                'AR_amount:IC_sale:IC_income', 0, 1, 1, 1, false)
        RETURNING id INTO v_chart_id;
    END IF;
    UPDATE chart
       SET link = CASE
                    WHEN COALESCE(link, '') = '' THEN 'AR_amount:IC_sale:IC_income'
                    ELSE link || ':AR_amount'
                  END
     WHERE id = v_chart_id
       AND COALESCE(link, '') !~ '(^|:)AR_amount(:|$)';
    IF NOT EXISTS (SELECT 1 FROM taxkeys WHERE chart_id = v_chart_id) THEN
        INSERT INTO taxkeys (chart_id, tax_id, taxkey_id, pos_ustva, startdate)
        VALUES (v_chart_id, 0, 0, 60, '1970-01-01');
    END IF;

    -- 2. Steuerzone
    IF v_zone_id IS NULL THEN
        INSERT INTO tax_zones (description, sortkey, obsolete)
        VALUES (v_desc, (SELECT COALESCE(MAX(sortkey), 0) + 1 FROM tax_zones), false)
        RETURNING id INTO v_zone_id;
    ELSE
        UPDATE tax_zones SET obsolete = false WHERE id = v_zone_id;
    END IF;

    -- 3. Kontenzuordnung je Buchungsgruppe. Vorlage ist die Zone mit dem
    --    kleinsten sortkey (Inland): steuerpflichtige Gruppen wechseln auf das
    --    § 13b-Erlöskonto, ohnehin steuerfreie (Auslagen, durchlaufende Posten,
    --    Gebühren) behalten ihr Erlöskonto — das sind keine § 13b-Leistungen.
    INSERT INTO taxzone_charts (taxzone_id, buchungsgruppen_id, income_accno_id, expense_accno_id)
    SELECT v_zone_id, bg.id,
           CASE WHEN COALESCE(src.rate, 0) > 0 THEN v_chart_id ELSE src.income_accno_id END,
           src.expense_accno_id
      FROM buchungsgruppen bg
      JOIN LATERAL (
            SELECT tc.income_accno_id, tc.expense_accno_id,
                   (SELECT t.rate
                      FROM taxkeys k
                      JOIN tax t ON t.id = k.tax_id
                     WHERE k.chart_id = tc.income_accno_id
                       AND k.startdate <= CURRENT_DATE
                     ORDER BY k.startdate DESC
                     LIMIT 1) AS rate
              FROM taxzone_charts tc
              JOIN tax_zones z ON z.id = tc.taxzone_id
             WHERE tc.buchungsgruppen_id = bg.id
               AND tc.taxzone_id <> v_zone_id
             ORDER BY z.sortkey, z.id
             LIMIT 1
           ) src ON true
     WHERE NOT EXISTS (
            SELECT 1 FROM taxzone_charts x
             WHERE x.taxzone_id = v_zone_id AND x.buchungsgruppen_id = bg.id);

    -- 4. UStVA: Bemessungsgrundlage auf dem § 13b-Erlöskonto → Kennzahl 60
    INSERT INTO ustva_mapping (taxkey, rate, chart_id, role, direction, kz, description)
    SELECT NULL, NULL, v_chart_id, 'base', 'revenue', 60, 'Reverse Charge § 13b UStG (Ausgangsseite)'
     WHERE NOT EXISTS (
            SELECT 1 FROM ustva_mapping m
             WHERE m.role = 'base' AND m.direction = 'revenue' AND m.chart_id = v_chart_id);

    INSERT INTO defaults_oserp (key, value) VALUES ('feature_reverse_charge', '1')
    ON CONFLICT (key) DO UPDATE SET value = '1', mtime = now();
    INSERT INTO defaults_oserp (key, value) VALUES ('reverse_charge_taxzone_id', v_zone_id::text)
    ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now();

    RETURN json_build_object('enabled', true, 'taxzone_id', v_zone_id,
                             'description', v_desc, 'income_accno', v_accno);
END;
$BODY$;

COMMENT ON FUNCTION reverse_charge_set(boolean) IS 'Reverse Charge (§ 13b UStG) ein-/ausschalten: Steuerzone, Kontenzuordnung, UStVA-Kennzahl 60 — idempotent.';

-- ============================================================================
-- MITARBEITER-CHAT (interner Chat zwischen Benutzern, Echtzeit via SSE)
-- ============================================================================

CREATE TABLE IF NOT EXISTS chat_conversations (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    title TEXT,
    is_group BOOLEAN NOT NULL DEFAULT FALSE,
    created_by INTEGER,
    itime TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    mtime TIMESTAMPTZ
);

COMMENT ON TABLE chat_conversations IS 'Chat-Unterhaltung zwischen Mitarbeitern (1:1 oder Gruppe)';
COMMENT ON COLUMN chat_conversations.title IS 'Nur fuer Gruppen-Chats; 1:1-Chats zeigen den Namen des Gegenuebers';
COMMENT ON COLUMN chat_conversations.is_group IS 'FALSE = genau zwei Teilnehmer (1:1), TRUE = Gruppe';
COMMENT ON COLUMN chat_conversations.created_by IS 'employee.id des Erstellers';

CREATE TABLE IF NOT EXISTS chat_participants (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    conversation_id INTEGER NOT NULL,
    employee_id INTEGER NOT NULL,
    last_read_id INTEGER NOT NULL DEFAULT 0,
    muted BOOLEAN NOT NULL DEFAULT FALSE,
    itime TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chat_participants_unique UNIQUE (conversation_id, employee_id)
);

COMMENT ON TABLE chat_participants IS 'Teilnehmer einer Chat-Unterhaltung inkl. Lesestand';
COMMENT ON COLUMN chat_participants.last_read_id IS 'Hoechste gelesene chat_messages.id — daraus ergibt sich die Ungelesen-Zahl';
COMMENT ON COLUMN chat_participants.muted IS 'Keine Popup-Benachrichtigung fuer diese Unterhaltung';

CREATE TABLE IF NOT EXISTS chat_messages (
    id INTEGER NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    conversation_id INTEGER NOT NULL,
    employee_id INTEGER NOT NULL,
    message TEXT NOT NULL,
    itime TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted BOOLEAN NOT NULL DEFAULT FALSE
);

COMMENT ON TABLE chat_messages IS 'Einzelne Chat-Nachricht innerhalb einer Unterhaltung';
COMMENT ON COLUMN chat_messages.employee_id IS 'Absender (employee.id)';
COMMENT ON COLUMN chat_messages.deleted IS 'Soft-Delete — geloeschte Nachrichten bleiben fuer die Historie erhalten';

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_chat_messages_conversation') THEN
        CREATE INDEX idx_chat_messages_conversation ON chat_messages(conversation_id, id DESC);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_chat_participants_employee') THEN
        CREATE INDEX idx_chat_participants_employee ON chat_participants(employee_id);
    END IF;
END $$;

-- Trigger: pg_notify bei neuer Nachricht (SSE -> Einblendung beim Empfaenger).
-- Die Empfaengerliste steckt im Payload, damit der Client ohne Rueckfrage
-- entscheiden kann, ob ihn die Nachricht betrifft; der Nachrichtentext ist
-- ebenfalls dabei, damit die Einblendung ihn ohne zweiten Call zeigen kann.
CREATE OR REPLACE FUNCTION notify_chat_message() RETURNS trigger AS $$
DECLARE
    v_sender     TEXT;
    v_recipients INTEGER[];
BEGIN
    -- Fallback auf den Login: nicht jeder Mitarbeiterdatensatz hat einen Namen
    SELECT COALESCE(NULLIF(TRIM(e.name), ''), e.login) INTO v_sender
      FROM employee e WHERE e.id = NEW.employee_id;
    SELECT array_agg(p.employee_id) INTO v_recipients
      FROM chat_participants p WHERE p.conversation_id = NEW.conversation_id;

    PERFORM pg_notify('chat_message', json_build_object(
        'action',          'new',
        'id',              NEW.id,
        'conversation_id', NEW.conversation_id,
        'employee_id',     NEW.employee_id,
        'sender_name',     COALESCE(v_sender, ''),
        -- pg_notify ist auf 8000 Byte begrenzt; laengere Texte holt der Client nach
        'message',         left(NEW.message, 2000),
        'truncated',       length(NEW.message) > 2000,
        'itime',           NEW.itime,
        'recipients',      COALESCE(v_recipients, ARRAY[]::INTEGER[])
    )::TEXT);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'chat_messages_notify') THEN
        CREATE TRIGGER chat_messages_notify
            AFTER INSERT ON chat_messages
            FOR EACH ROW
            EXECUTE FUNCTION notify_chat_message();
    END IF;
END $$;

-- Trigger: Lesebestaetigung. Setzt ein Teilnehmer seinen Lesestand hoch, erfaehrt
-- der Absender das live (Haken-Anzeige) — ohne zusaetzlichen Ajax-Call.
CREATE OR REPLACE FUNCTION notify_chat_read() RETURNS trigger AS $$
BEGIN
    IF NEW.last_read_id IS DISTINCT FROM OLD.last_read_id THEN
        PERFORM pg_notify('chat_message', json_build_object(
            'action',          'read',
            'conversation_id', NEW.conversation_id,
            'employee_id',     NEW.employee_id,
            'last_read_id',    NEW.last_read_id
        )::TEXT);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'chat_participants_read_notify') THEN
        CREATE TRIGGER chat_participants_read_notify
            AFTER UPDATE ON chat_participants
            FOR EACH ROW
            EXECUTE FUNCTION notify_chat_read();
    END IF;
END $$;

-- -----------------------------------------------------------------------------
-- WIEDERKEHRENDE RECHNUNGEN (Abos, Mieten, Wartungsverträge)
--
-- Die kivitendo-Tabellen bleiben unverändert und bedeuten dasselbe wie dort:
-- periodic_invoices_configs (eine Zeile je Auftrag: Rhythmus, Laufzeit,
-- Versand) und periodic_invoices (eine Zeile je erzeugter Rechnung mit dem
-- Periodenbeginn als Merkposten, was schon abgerechnet ist). Was OS-ERP darüber
-- hinaus kann — freie Intervalle, Kalender-Ausrichtung, anteilige Perioden,
-- nachschüssige Abrechnung, Preisanpassung, Kündigungsfristen, Mahnsperre —
-- liegt in den _ext-Tabellen daneben. kivitendo-Spalten werden dabei so
-- gefüllt, dass kivitendo die Konfiguration weiterhin versteht.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS periodic_invoices_configs_ext (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    config_id integer NOT NULL REFERENCES periodic_invoices_configs(id) ON DELETE CASCADE,
    interval_unit text NOT NULL DEFAULT 'month',
    interval_count integer NOT NULL DEFAULT 1,
    align_to_calendar boolean NOT NULL DEFAULT false,
    prorate_partial boolean NOT NULL DEFAULT true,
    billing_timing text NOT NULL DEFAULT 'advance',
    billing_offset_days integer NOT NULL DEFAULT 0,
    price_mode text NOT NULL DEFAULT 'fixed',
    price_increase_percent numeric(6,3),
    price_increase_month integer,
    hold_on_overdue_days integer,
    notice_period_months integer,
    min_term_months integer,
    paused_until date,
    post_to_ledger boolean NOT NULL DEFAULT true,
    notes text,
    send_whatsapp boolean NOT NULL DEFAULT false,
    whatsapp_phone text,
    whatsapp_template_id integer,
    created_by integer,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    CONSTRAINT periodic_invoices_configs_ext_config_id_unique UNIQUE (config_id)
);

COMMENT ON TABLE periodic_invoices_configs_ext IS 'Zusatzfelder je wiederkehrender Abrechnung (periodic_invoices_configs)';
COMMENT ON COLUMN periodic_invoices_configs_ext.config_id IS 'Referenz auf periodic_invoices_configs.id';
COMMENT ON COLUMN periodic_invoices_configs_ext.interval_unit IS 'Einheit des Abrechnungsintervalls: day, week, month, year oder once';
COMMENT ON COLUMN periodic_invoices_configs_ext.interval_count IS 'Anzahl Einheiten je Intervall (alle 2 Wochen = week/2)';
COMMENT ON COLUMN periodic_invoices_configs_ext.align_to_calendar IS 'Perioden an Kalendergrenzen ausrichten (Monatserster, Montag, 1. Januar)';
COMMENT ON COLUMN periodic_invoices_configs_ext.prorate_partial IS 'Angebrochene erste/letzte Periode anteilig nach Tagen berechnen';
COMMENT ON COLUMN periodic_invoices_configs_ext.billing_timing IS 'advance = vorschüssig (Rechnung zu Periodenbeginn), arrears = nachschüssig (zum Periodenende)';
COMMENT ON COLUMN periodic_invoices_configs_ext.billing_offset_days IS 'Rechnungsdatum relativ zum Periodenbeginn bzw. -ende in Tagen (negativ = früher)';
COMMENT ON COLUMN periodic_invoices_configs_ext.price_mode IS 'fixed = Preise aus dem Auftrag, current = aktuelle Listen-/Preisgruppenpreise bei Erzeugung';
COMMENT ON COLUMN periodic_invoices_configs_ext.price_increase_percent IS 'Jährliche Preisanpassung in Prozent (Indexierung), NULL = keine';
COMMENT ON COLUMN periodic_invoices_configs_ext.price_increase_month IS 'Monat (1-12), ab dem die jährliche Preisanpassung greift';
COMMENT ON COLUMN periodic_invoices_configs_ext.hold_on_overdue_days IS 'Erzeugung anhalten, wenn der Kunde Rechnungen hat, die länger als n Tage überfällig sind; NULL = aus';
COMMENT ON COLUMN periodic_invoices_configs_ext.notice_period_months IS 'Kündigungsfrist in Monaten vor Laufzeitende';
COMMENT ON COLUMN periodic_invoices_configs_ext.min_term_months IS 'Mindestlaufzeit in Monaten ab Startdatum';
COMMENT ON COLUMN periodic_invoices_configs_ext.paused_until IS 'Pausiert bis zu diesem Datum; danach wird die Abrechnung automatisch wieder aktiv';
COMMENT ON COLUMN periodic_invoices_configs_ext.post_to_ledger IS 'Erzeugte Rechnung sofort ins Hauptbuch buchen (acc_trans)';
COMMENT ON COLUMN periodic_invoices_configs_ext.notes IS 'Interne Bemerkung zur Abrechnung (Vertragsnummer, Absprachen)';
COMMENT ON COLUMN periodic_invoices_configs_ext.send_whatsapp IS 'Rechnung automatisch per WhatsApp (Dokument-Template) senden';
COMMENT ON COLUMN periodic_invoices_configs_ext.whatsapp_phone IS 'Empfängernummer; NULL = Mobilnummer des Kunden';
COMMENT ON COLUMN periodic_invoices_configs_ext.whatsapp_template_id IS 'WhatsApp-Dokument-Template (whatsapp_templates.id); NULL = Vorgabe whatsapp_tpl_faktura';
COMMENT ON COLUMN periodic_invoices_configs_ext.created_by IS 'Mitarbeiter, der die Abrechnung eingerichtet hat';

-- Je erzeugter Rechnung: abgerechnete Periode, Faktoren und Zustellung.
CREATE TABLE IF NOT EXISTS periodic_invoices_ext (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    periodic_invoice_id integer NOT NULL REFERENCES periodic_invoices(id) ON DELETE CASCADE,
    period_end_date date,
    billing_date date,
    factor numeric(12,6) NOT NULL DEFAULT 1,
    posted boolean NOT NULL DEFAULT false,
    post_error text,
    email_to text,
    email_sent_at timestamp without time zone,
    email_error text,
    printed_at timestamp without time zone,
    print_error text,
    whatsapp_to text,
    whatsapp_sent_at timestamp without time zone,
    whatsapp_error text,
    created_by integer,
    source text NOT NULL DEFAULT 'manual',
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone,
    CONSTRAINT periodic_invoices_ext_periodic_invoice_id_unique UNIQUE (periodic_invoice_id)
);

COMMENT ON TABLE periodic_invoices_ext IS 'Zusatzfelder je erzeugter wiederkehrender Rechnung (periodic_invoices)';
COMMENT ON COLUMN periodic_invoices_ext.periodic_invoice_id IS 'Referenz auf periodic_invoices.id';
COMMENT ON COLUMN periodic_invoices_ext.period_end_date IS 'Ende der abgerechneten Periode';
COMMENT ON COLUMN periodic_invoices_ext.billing_date IS 'Planmäßiges Rechnungsdatum laut Rhythmus';
COMMENT ON COLUMN periodic_invoices_ext.factor IS 'Preisfaktor der Periode (anteilige Tage × Auftragswert-Umrechnung × Preisanpassung)';
COMMENT ON COLUMN periodic_invoices_ext.posted IS 'Rechnung wurde ins Hauptbuch gebucht';
COMMENT ON COLUMN periodic_invoices_ext.post_error IS 'Grund, warum die Buchung nicht möglich war';
COMMENT ON COLUMN periodic_invoices_ext.email_to IS 'Empfänger des E-Mail-Versands';
COMMENT ON COLUMN periodic_invoices_ext.email_sent_at IS 'Zeitpunkt des E-Mail-Versands';
COMMENT ON COLUMN periodic_invoices_ext.email_error IS 'Letzter Fehler beim E-Mail-Versand';
COMMENT ON COLUMN periodic_invoices_ext.printed_at IS 'Zeitpunkt des automatischen Drucks';
COMMENT ON COLUMN periodic_invoices_ext.print_error IS 'Letzter Fehler beim Druck';
COMMENT ON COLUMN periodic_invoices_ext.created_by IS 'Mitarbeiter, der die Rechnung erzeugt hat (NULL = Zeitsteuerung)';
COMMENT ON COLUMN periodic_invoices_ext.source IS 'manual = aus der Oberfläche, cron = Zeitsteuerung';

-- Bewusst ausgelassene Perioden (z. B. Kulanzmonat). Eine übersprungene
-- Periode gilt als erledigt und wird nicht nachgeholt.
CREATE TABLE IF NOT EXISTS periodic_invoices_skips (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    config_id integer NOT NULL REFERENCES periodic_invoices_configs(id) ON DELETE CASCADE,
    period_start_date date NOT NULL,
    reason text,
    employee_id integer,
    itime timestamp without time zone DEFAULT now(),
    CONSTRAINT periodic_invoices_skips_config_period_unique UNIQUE (config_id, period_start_date)
);

COMMENT ON TABLE periodic_invoices_skips IS 'Bewusst übersprungene Perioden einer wiederkehrenden Abrechnung';
COMMENT ON COLUMN periodic_invoices_skips.config_id IS 'Referenz auf periodic_invoices_configs.id';
COMMENT ON COLUMN periodic_invoices_skips.period_start_date IS 'Beginn der übersprungenen Periode';
COMMENT ON COLUMN periodic_invoices_skips.reason IS 'Grund (frei)';
COMMENT ON COLUMN periodic_invoices_skips.employee_id IS 'Mitarbeiter, der die Periode übersprungen hat';

CREATE INDEX IF NOT EXISTS idx_periodic_invoices_configs_oe_id ON periodic_invoices_configs(oe_id);
CREATE INDEX IF NOT EXISTS idx_periodic_invoices_config_period ON periodic_invoices(config_id, period_start_date);

-- Vorbelegung für E-Mail-Betreff und -Text neuer Abrechnungen. Platzhalter
-- wie in den Positionstexten, zusätzlich alle Spalten der Rechnung
-- (<%invnumber%>, <%amount%>, <%duedate%>).
INSERT INTO defaults_oserp (key, value) VALUES
    ('recurring_email_subject', 'Ihre Rechnung <%invnumber%> für <%period_month%>')
ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES
    ('recurring_email_body', '<p>Sehr geehrte Damen und Herren,</p><p>anbei erhalten Sie Ihre Rechnung <%invnumber%> für den Zeitraum <%period_start_date%> bis <%period_end_date%>.</p><p>Mit freundlichen Grüßen</p>')
ON CONFLICT (key) DO NOTHING;

-- Perioden eines Abrechnungsrhythmus — rein rechnerisch, ohne Tabellenzugriff.
-- Alle Perioden werden vom Anker aus bestimmt (anchor + i × Intervall), nicht
-- iterativ: sonst wandert ein 31. nach dem Februar dauerhaft auf den 28.
-- Mit Kalender-Ausrichtung ist der Anker die Kalendergrenze vor dem Start, die
-- erste (und bei Enddatum die letzte) Periode ist dann angebrochen und wird
-- auf Wunsch anteilig nach Tagen berechnet (factor < 1).
CREATE OR REPLACE FUNCTION recurring_invoice_periods(
    p_start_date          date,
    p_end_date            date,
    p_interval_unit       text,
    p_interval_count      integer,
    p_align_to_calendar   boolean,
    p_prorate_partial     boolean,
    p_billing_timing      text,
    p_billing_offset_days integer,
    p_until               date,
    p_max                 integer DEFAULT 1000
) RETURNS TABLE(n integer, period_start date, period_end date, billing_date date, factor numeric, is_partial boolean)
-- ROWS: realistische Schätzung (zwei Jahre monatlich ≈ 24). Mit der Vorgabe
-- von 1000 Zeilen je Aufruf schätzt der Planer die Übersicht so teuer, dass
-- er JIT-Kompilierung anwirft — die kostete mehr als die ganze Abfrage.
LANGUAGE plpgsql IMMUTABLE ROWS 40 AS $$
DECLARE
    v_step       interval;
    v_anchor     date;
    v_full_start date;
    v_full_end   date;
    v_i          integer := 0;
BEGIN
    IF p_start_date IS NULL THEN RETURN; END IF;
    p_interval_count      := GREATEST(COALESCE(p_interval_count, 1), 1);
    p_billing_offset_days := COALESCE(p_billing_offset_days, 0);
    p_until               := COALESCE(p_until, DATE '2999-12-31');
    p_max                 := COALESCE(p_max, 1000);

    -- Einmalige Abrechnung: genau eine Periode von Start bis Ende (oder Start)
    IF p_interval_unit = 'once' THEN
        n := 1; period_start := p_start_date; period_end := COALESCE(p_end_date, p_start_date);
        billing_date := (CASE WHEN p_billing_timing = 'arrears' THEN period_end ELSE period_start END) + p_billing_offset_days;
        factor := 1; is_partial := false;
        IF billing_date <= p_until THEN RETURN NEXT; END IF;
        RETURN;
    END IF;

    v_step := CASE p_interval_unit
        WHEN 'day'  THEN make_interval(days  => p_interval_count)
        WHEN 'week' THEN make_interval(weeks => p_interval_count)
        WHEN 'year' THEN make_interval(years => p_interval_count)
        ELSE             make_interval(months => p_interval_count)
    END;

    v_anchor := CASE
        WHEN p_align_to_calendar AND p_interval_unit = 'month' THEN date_trunc('month', p_start_date)::date
        WHEN p_align_to_calendar AND p_interval_unit = 'year'  THEN date_trunc('year',  p_start_date)::date
        WHEN p_align_to_calendar AND p_interval_unit = 'week'  THEN date_trunc('week',  p_start_date)::date
        ELSE p_start_date
    END;

    LOOP
        EXIT WHEN v_i >= p_max;
        v_full_start := (v_anchor + v_step * v_i)::date;
        v_full_end   := (v_anchor + v_step * (v_i + 1))::date - 1;
        EXIT WHEN p_end_date IS NOT NULL AND v_full_start > p_end_date;

        n            := v_i + 1;
        period_start := GREATEST(v_full_start, p_start_date);
        period_end   := LEAST(v_full_end, COALESCE(p_end_date, v_full_end));
        is_partial   := (period_start <> v_full_start) OR (period_end <> v_full_end);
        factor       := CASE WHEN is_partial AND p_prorate_partial
                             THEN ROUND((period_end - period_start + 1)::numeric / (v_full_end - v_full_start + 1), 6)
                             ELSE 1 END;
        billing_date := (CASE WHEN p_billing_timing = 'arrears' THEN period_end ELSE period_start END) + p_billing_offset_days;

        EXIT WHEN billing_date > p_until;
        RETURN NEXT;
        v_i := v_i + 1;
    END LOOP;
END;
$$;

-- Platzhalter in Texten einer wiederkehrenden Rechnung ersetzen.
--
-- Syntax wie kivitendo: <%period_start_date%>, <%current_month_long%>,
-- <%previous_year%>, optional mit strftime-Format <%period_start_date FORMAT=%m/%Y%>.
-- In HTML-Texten (Editor) stehen die Klammern maskiert als &lt;% … %&gt; — auch
-- die werden erkannt. Zusätzlich zu kivitendo: <%period%> (von–bis),
-- <%period_month%> (Monat Jahr) und <%period_days%>.
--
-- p_lang ist der template_code der Belegsprache ('de', 'en', …); er bestimmt
-- Datumsformat und Monatsnamen.
CREATE OR REPLACE FUNCTION recurring_fill_placeholders(
    p_text         text,
    p_period_start date,
    p_period_end   date,
    p_lang         text DEFAULT 'de'
) RETURNS text
LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
    v_result   text := p_text;
    v_match    text[];
    v_key      text;
    v_fmt      text;
    v_date     date;
    v_value    text;
    v_lang     text := lower(COALESCE(NULLIF(p_lang, ''), 'de'));
    v_datefmt  text;
    v_tok      text;
    v_pattern  text;
    v_months_de text[] := ARRAY['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];
    v_months_en text[] := ARRAY['January','February','March','April','May','June','July','August','September','October','November','December'];
BEGIN
    IF p_text IS NULL OR p_text = '' OR p_period_start IS NULL THEN RETURN p_text; END IF;
    p_period_end := COALESCE(p_period_end, p_period_start);
    v_datefmt := CASE WHEN v_lang LIKE 'en%' THEN 'MM/DD/YYYY' ELSE 'DD.MM.YYYY' END;

    FOR v_match IN
        SELECT m FROM regexp_matches(p_text,
            '((?:<%|&lt;%)\s*([a-z_]+)(?:\s+format\s*=\s*((?:[^%>&]|%[^>&]|&(?!gt;))*))?\s*(?:%>|%&gt;))', 'gi') AS m
    LOOP
        v_key := lower(v_match[2]);
        v_fmt := v_match[3];

        -- Bezugsdatum des Platzhalters
        v_date := CASE
            WHEN v_key IN ('period_end_date') THEN p_period_end
            WHEN v_key LIKE 'previous_quarter%' THEN (p_period_start - INTERVAL '3 months')::date
            WHEN v_key LIKE 'next_quarter%'     THEN (p_period_start + INTERVAL '3 months')::date
            WHEN v_key LIKE 'previous_month%'   THEN (p_period_start - INTERVAL '1 month')::date
            WHEN v_key LIKE 'next_month%'       THEN (p_period_start + INTERVAL '1 month')::date
            WHEN v_key LIKE 'previous_year%'    THEN (p_period_start - INTERVAL '1 year')::date
            WHEN v_key LIKE 'next_year%'        THEN (p_period_start + INTERVAL '1 year')::date
            ELSE p_period_start
        END;

        IF v_key NOT IN ('period_start_date','period_end_date','period','period_month','period_days',
                         'current_month','previous_month','next_month',
                         'current_month_long','previous_month_long','next_month_long',
                         'current_year','previous_year','next_year',
                         'current_quarter','previous_quarter','next_quarter') THEN
            CONTINUE;
        END IF;

        IF v_fmt IS NOT NULL AND v_fmt <> '' THEN
            -- strftime → to_char: Literale in Anführungszeichen, damit to_char sie
            -- nicht als Muster liest ("KW %V" → "KW" IW)
            v_pattern := '';
            FOR v_tok IN SELECT t[1] FROM regexp_matches(v_fmt, '(%.|[^%]+)', 'g') AS t LOOP
                v_pattern := v_pattern || CASE v_tok
                    WHEN '%Y' THEN 'YYYY'  WHEN '%y' THEN 'YY'    WHEN '%C' THEN 'CC'
                    WHEN '%m' THEN 'MM'    WHEN '%d' THEN 'DD'    WHEN '%e' THEN 'FMDD'
                    WHEN '%j' THEN 'DDD'   WHEN '%F' THEN 'YYYY-MM-DD' WHEN '%D' THEN 'MM/DD/YY'
                    WHEN '%u' THEN 'ID'    WHEN '%w' THEN 'D'     WHEN '%U' THEN 'WW'
                    WHEN '%W' THEN 'WW'    WHEN '%V' THEN 'IW'    WHEN '%A' THEN 'TMDay'
                    WHEN '%a' THEN 'TMDy'  WHEN '%%' THEN '"%"'
                    WHEN '%B' THEN '"' || (CASE WHEN v_lang LIKE 'en%' THEN v_months_en ELSE v_months_de END)[EXTRACT(MONTH FROM v_date)::int] || '"'
                    WHEN '%b' THEN '"' || left((CASE WHEN v_lang LIKE 'en%' THEN v_months_en ELSE v_months_de END)[EXTRACT(MONTH FROM v_date)::int], 3) || '"'
                    ELSE '"' || replace(v_tok, '"', '') || '"'
                END;
            END LOOP;
            v_value := to_char(v_date, v_pattern);
        ELSE
            v_value := CASE
                WHEN v_key IN ('period_start_date','period_end_date') THEN to_char(v_date, v_datefmt)
                WHEN v_key = 'period' THEN to_char(p_period_start, v_datefmt) || ' – ' || to_char(p_period_end, v_datefmt)
                WHEN v_key = 'period_days' THEN (p_period_end - p_period_start + 1)::text
                WHEN v_key = 'period_month' THEN
                    (CASE WHEN v_lang LIKE 'en%' THEN v_months_en ELSE v_months_de END)[EXTRACT(MONTH FROM v_date)::int]
                    || ' ' || to_char(v_date, 'YYYY')
                WHEN v_key LIKE '%_month_long' THEN
                    (CASE WHEN v_lang LIKE 'en%' THEN v_months_en ELSE v_months_de END)[EXTRACT(MONTH FROM v_date)::int]
                WHEN v_key LIKE '%_month'   THEN to_char(v_date, 'FMMM')
                WHEN v_key LIKE '%_year'    THEN to_char(v_date, 'YYYY')
                WHEN v_key LIKE '%_quarter' THEN to_char(v_date, 'Q')
                ELSE ''
            END;
        END IF;

        v_result := replace(v_result, v_match[1], v_value);
    END LOOP;

    RETURN v_result;
END;
$$;

-- Perioden einer gespeicherten Abrechnung mit ihrem Zustand:
--   created  Rechnung liegt vor (periodic_invoices)
--   skipped  bewusst übersprungen (periodic_invoices_skips)
--   due      Rechnungsdatum erreicht, noch nicht erzeugt
--   planned  liegt in der Zukunft
-- Eine Rechnung zählt zur Periode, wenn ihr gemerkter Periodenbeginn in die
-- Periode fällt — so bleibt nach einer Änderung des Rhythmus nichts doppelt.
-- Ohne _ext-Zeile (von kivitendo angelegt) gelten kivitendos Regeln: Anker ist
-- first_billing_date bzw. start_date, Intervall aus periodicity, keine Anteile.
-- Mit automatischer Verlängerung ist das Enddatum offen, solange nicht gekündigt.
CREATE OR REPLACE FUNCTION recurring_config_periods(
    p_config_id integer,
    p_until     date DEFAULT NULL,
    p_max       integer DEFAULT 1000
) RETURNS TABLE(
    n integer, period_start date, period_end date, billing_date date, factor numeric, is_partial boolean,
    periodic_invoice_id integer, ar_id integer, invnumber text, ar_transdate date, ar_amount numeric, ar_paid numeric,
    skipped boolean, skip_reason text, state text
)
-- Bewusst PL/pgSQL statt SQL: eine SQL-Funktion würde der Planer in die
-- aufrufende Abfrage einbetten, und dort kostete der Aufruf je Abrechnung
-- Hunderte Millisekunden. So bleibt es ein eigener, kleiner Plan je Aufruf.
LANGUAGE plpgsql STABLE ROWS 40 AS $$
BEGIN
    RETURN QUERY
    SELECT p.n, p.period_start, p.period_end, p.billing_date, p.factor, p.is_partial,
           pi.id, pi.ar_id, ar.invnumber, ar.transdate, ar.amount, ar.paid,
           (sk.id IS NOT NULL), sk.reason,
           CASE WHEN pi.id IS NOT NULL THEN 'created'
                WHEN sk.id IS NOT NULL THEN 'skipped'
                WHEN p.billing_date <= CURRENT_DATE THEN 'due'
                ELSE 'planned' END
    FROM periodic_invoices_configs c
    LEFT JOIN periodic_invoices_configs_ext e ON e.config_id = c.id
    CROSS JOIN LATERAL recurring_invoice_periods(
        CASE WHEN e.id IS NULL THEN COALESCE(c.first_billing_date, c.start_date) ELSE c.start_date END,
        CASE WHEN NOT COALESCE(c.terminated, false) AND COALESCE(c.extend_automatically_by, 0) > 0 THEN NULL ELSE c.end_date END,
        COALESCE(e.interval_unit, CASE c.periodicity WHEN 'o' THEN 'once' ELSE 'month' END),
        COALESCE(e.interval_count, CASE c.periodicity WHEN 'q' THEN 3 WHEN 'b' THEN 6 WHEN 'y' THEN 12 ELSE 1 END),
        COALESCE(e.align_to_calendar, false),
        COALESCE(e.prorate_partial, false),
        COALESCE(e.billing_timing, 'advance'),
        COALESCE(e.billing_offset_days, 0),
        COALESCE(p_until, DATE '2999-12-31'),
        COALESCE(p_max, 1000)
    ) p
    LEFT JOIN LATERAL (
        SELECT pi.id, pi.ar_id FROM periodic_invoices pi
        WHERE pi.config_id = c.id AND pi.period_start_date BETWEEN p.period_start AND p.period_end
        ORDER BY pi.period_start_date LIMIT 1
    ) pi ON true
    LEFT JOIN ar ON ar.id = pi.ar_id
    LEFT JOIN LATERAL (
        SELECT sk.id, sk.reason FROM periodic_invoices_skips sk
        WHERE sk.config_id = c.id AND sk.period_start_date BETWEEN p.period_start AND p.period_end
        LIMIT 1
    ) sk ON true
    WHERE c.id = p_config_id
    ORDER BY p.n;
END;
$$;

-- Faktor der jährlichen Preisanpassung für eine Periode. Eine Anpassung greift
-- zu jedem 1. des Anpassungsmonats, frühestens zwölf Monate nach dem Start,
-- jede weitere ein Jahr später (Zinseszins). Vorschau, Fälligkeit und
-- Erzeugung rechnen damit identisch.
CREATE OR REPLACE FUNCTION recurring_index_factor(
    p_percent      numeric,
    p_month        integer,
    p_start        date,
    p_period_start date
) RETURNS numeric
LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN p_percent IS NULL OR p_percent = 0 OR p_month IS NULL OR p_start IS NULL OR p_period_start IS NULL THEN 1
           ELSE POWER(1 + p_percent / 100, (
                SELECT COUNT(*) FROM generate_series(EXTRACT(YEAR FROM p_start)::int, EXTRACT(YEAR FROM p_period_start)::int) y
                WHERE make_date(y, p_month, 1) >= (p_start + INTERVAL '1 year')::date
                  AND make_date(y, p_month, 1) <= p_period_start
           ))::numeric END
$$;

-- ============================================================================
-- DRUCKVORLAGEN-DESIGNS (Vorlageneditor)
-- ============================================================================
-- Der Vorlageneditor beschreibt eine Druckvorlage als JSON (Seite, frei
-- platzierte Bausteine, Fließbereich mit Positionstabelle). Beim Speichern
-- übersetzt das Backend das Design in eine kivitendo-kompatible .tex-Datei im
-- Vorlagensatz. Jede Speicherung ist eine neue Version; nur die aktuelle Zeile
-- trägt is_current, die älteren bleiben zum Zurückholen stehen.

CREATE TABLE IF NOT EXISTS print_template_designs (
    id integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    template_set text NOT NULL,
    document_type text NOT NULL,
    design jsonb NOT NULL,
    version integer NOT NULL DEFAULT 1,
    is_current boolean NOT NULL DEFAULT true,
    employee_id integer,
    itime timestamp without time zone NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_print_template_designs_set_type
    ON print_template_designs (template_set, document_type, is_current);

COMMENT ON TABLE print_template_designs IS 'Vorlageneditor: Designs der Druckvorlagen als JSON, versioniert je Vorlagensatz und Belegart';
COMMENT ON COLUMN print_template_designs.template_set IS 'Vorlagensatz wie in defaults.templates, z. B. templates/firma';
COMMENT ON COLUMN print_template_designs.document_type IS 'Belegart (fakturaType), z. B. invoice, quotation, order';
COMMENT ON COLUMN print_template_designs.design IS 'Design-JSON (page, blocks, body) — Quelle der erzeugten .tex-Datei';
COMMENT ON COLUMN print_template_designs.version IS 'Laufende Versionsnummer je Vorlagensatz und Belegart';
COMMENT ON COLUMN print_template_designs.is_current IS 'Genau eine aktuelle Version je Vorlagensatz und Belegart';
COMMENT ON COLUMN print_template_designs.employee_id IS 'Mitarbeiter, der diese Version gespeichert hat';

-- ============================================================================
-- BRIEFE UND POSTVERSAND (Hybridbrief)
-- ============================================================================
-- Briefe liegen unverändert in kivitendos Tabelle `letter` (Nummernkreis
-- defaults.letternumber, Ansprechperson cp_id, Kunde oder Lieferant). Was
-- OS-ERP darüber hinaus braucht, steht daneben:
--
-- * letter_templates — Textbausteine (Betreff + Text), die der Benutzer im
--   Briefeditor wählt und dort auch anlegt.
-- * postal_dispatches — jede elektronische Einlieferung eines Dokuments bei
--   einem Briefdienstleister (PIN AG per SFTP/FTPS, LetterXpress per REST)
--   oder der händische Postversand. Gilt für Briefe UND Belege (Rechnung,
--   Angebot, Auftrag, Lieferschein): record_table/record_id zeigen auf die
--   kivitendo-Tabelle, record_type ist die Belegart der Druckvorlage. Das
--   versendete PDF bleibt als Versandexemplar in der Zeile — ein Kunde
--   bestreitet gern, was er bekommen hat.
--
-- Versand per E-Mail läuft wie bei Belegen über email_journal + record_links
-- (from_table = 'letter'); kivitendos Trigger räumt die Verknüpfungen beim
-- Löschen eines Briefs auf.

CREATE TABLE IF NOT EXISTS letter_templates (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL,
    subject text,
    body text,
    employee_id integer,
    itime timestamp without time zone DEFAULT now(),
    mtime timestamp without time zone
);

COMMENT ON TABLE letter_templates IS 'Textbausteine für Briefe (Betreff und Text mit Platzhaltern)';
COMMENT ON COLUMN letter_templates.name IS 'Anzeigename in der Vorlagenauswahl des Briefeditors';
COMMENT ON COLUMN letter_templates.body IS 'Brieftext als HTML (tiptap), Platzhalter <%name%>, <%customernumber%> …';

CREATE TABLE IF NOT EXISTS postal_dispatches (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type text NOT NULL,
    record_table text NOT NULL,
    record_id integer NOT NULL,
    provider text NOT NULL,
    status text NOT NULL DEFAULT 'queued',
    provider_job_id text,
    provider_status text,
    tracking_code text,
    tracking_status text,
    color boolean NOT NULL DEFAULT false,
    duplex boolean NOT NULL DEFAULT false,
    registered text,
    shipping text,
    pages integer,
    price numeric(10,2),
    filename text,
    pdf bytea,
    recipient_name text,
    recipient_address text,
    error text,
    employee_id integer,
    itime timestamp without time zone DEFAULT now(),
    sent_at timestamp without time zone,
    mtime timestamp without time zone
);

COMMENT ON TABLE postal_dispatches IS 'Postversand (Hybridbrief): Einlieferungen von Briefen und Belegen beim Briefdienstleister';
COMMENT ON COLUMN postal_dispatches.record_type IS 'Belegart der Druckvorlage: letter, invoice, order, quotation, delivery_order …';
COMMENT ON COLUMN postal_dispatches.record_table IS 'kivitendo-Tabelle des Dokuments: letter, ar, ap, oe, delivery_orders';
COMMENT ON COLUMN postal_dispatches.provider IS 'pinag (SFTP/FTPS-Einlieferung), letterxpress (REST-API) oder manual (selbst gedruckt und frankiert)';
COMMENT ON COLUMN postal_dispatches.status IS 'queued | sent (beim Anbieter) | done (zugestellt/abgeschlossen) | canceled | error | manual';
COMMENT ON COLUMN postal_dispatches.provider_job_id IS 'Auftragsnummer beim Anbieter (LetterXpress printjob id) bzw. Dateiname auf dem SFTP-Server';
COMMENT ON COLUMN postal_dispatches.provider_status IS 'Status des Anbieters im Original (z. B. queue, hold, done)';
COMMENT ON COLUMN postal_dispatches.registered IS 'Einschreiben: NULL, r1 (Einwurf) oder r2 (Übergabe)';
COMMENT ON COLUMN postal_dispatches.shipping IS 'national oder international (nach Land des Empfängers)';
COMMENT ON COLUMN postal_dispatches.price IS 'Vom Anbieter gemeldeter Preis in EUR (netto), falls bekannt';
COMMENT ON COLUMN postal_dispatches.pdf IS 'Versandexemplar: genau das PDF, das eingeliefert wurde';

CREATE INDEX IF NOT EXISTS idx_postal_dispatches_record ON postal_dispatches (record_table, record_id);
CREATE INDEX IF NOT EXISTS idx_postal_dispatches_status ON postal_dispatches (status);

-- ============================================================================
-- BANK: BUCHUNGSVORLAGEN FÜR UMSÄTZE OHNE BELEG (Dialogbuchung aus dem Bankumsatz)
-- ============================================================================
-- Umbuchung zwischen eigenen Konten, Privatentnahme/-einlage, Lohn, Sozial-
-- versicherung, Finanzamt, Bankgebühren, Darlehen … — Geschäftsvorfälle ohne
-- Eingangs-/Ausgangsrechnung. Je Vorlage das Gegenkonto für SKR03 und SKR04
-- (zur Laufzeit über defaults.coa aufgelöst) und ein Erkennungsmuster für
-- Verwendungszweck/Empfänger. Gebucht wird wie kivitendos Dialogbuchung (gl +
-- acc_trans), verknüpft über bank_transaction_acc_trans.gl_id.
CREATE TABLE IF NOT EXISTS bank_booking_templates (
    id              SERIAL PRIMARY KEY,
    key             TEXT NOT NULL UNIQUE,
    label           TEXT NOT NULL,
    icon            TEXT,
    direction       TEXT NOT NULL DEFAULT 'any' CHECK (direction IN ('in', 'out', 'any')),
    accno_skr03     TEXT,
    accno_skr04     TEXT,
    keyword_regex   TEXT,
    sort            INTEGER NOT NULL DEFAULT 100,
    hit_count       INTEGER NOT NULL DEFAULT 0,
    active          BOOLEAN NOT NULL DEFAULT TRUE,
    itime           TIMESTAMP DEFAULT now(),
    mtime           TIMESTAMP
);

COMMENT ON TABLE bank_booking_templates IS 'Buchungsvorlagen für Bankumsätze ohne Beleg (Umbuchung, Privat, Lohn, Finanzamt, Gebühren …)';
COMMENT ON COLUMN bank_booking_templates.key IS 'transfer_own (eigenes Bankkonto) und cash (Kassenkonto) lösen das Konto dynamisch auf, alle anderen über accno_skr03/accno_skr04';
COMMENT ON COLUMN bank_booking_templates.keyword_regex IS 'Erkennung (case-insensitive, POSIX-Regex) in Verwendungszweck oder Empfängername';

INSERT INTO bank_booking_templates (key, label, icon, direction, accno_skr03, accno_skr04, keyword_regex, sort) VALUES
    ('transfer_own',       'Umbuchung auf eigenes Bankkonto',          'mdi-bank-transfer',          'any', NULL,   NULL,   NULL, 10),
    ('cash',               'Bareinzahlung / Barabhebung (Kasse)',      'mdi-cash-register',          'any', NULL,   NULL,   'bareinzahlung|barauszahlung|abhebung|geldautomat|bargeld', 20),
    ('transfer_transit',   'Geldtransit (Konto außerhalb des Systems)','mdi-swap-horizontal',        'any', '1360', '1460', 'umbuchung|übertrag|uebertrag', 30),
    ('private_withdrawal', 'Privatentnahme',                           'mdi-account-arrow-right',    'out', '1800', '2100', 'privat', 40),
    ('private_deposit',    'Privateinlage',                            'mdi-account-arrow-left',     'in',  '1890', '2180', 'privat|einlage', 41),
    ('wages',              'Lohn / Gehalt',                            'mdi-account-cash',           'out', '1740', '3720', 'lohn|gehalt|verdienst|vergütung|verguetung', 50),
    ('wage_tax',           'Lohnsteuer (Finanzamt)',                   'mdi-office-building',        'out', '1741', '3730', 'lohnsteuer|lst[ /]', 51),
    ('social_security',    'Sozialversicherung (Krankenkasse)',        'mdi-hospital-box-outline',   'out', '1742', '3740', 'krankenkasse|sozialvers|beitragsnachweis|gesamtsozial|\mAOK\M|\mTK\M|\mDAK\M|\mIKK\M|\mBKK\M|barmer|knappschaft|minijob', 52),
    ('vat_prepayment',     'Umsatzsteuer-Vorauszahlung (Finanzamt)',   'mdi-percent',                'out', '1780', '3820', 'umsatzsteuer|\must\M|finanzamt|finanzkasse', 60),
    ('vat_refund',         'Umsatzsteuer-Erstattung (Finanzamt)',      'mdi-percent',                'in',  '1780', '3820', 'umsatzsteuer|\must\M|finanzamt|finanzkasse|erstattung', 61),
    ('bank_fees',          'Bankgebühren',                             'mdi-bank',                   'out', '4970', '6855', 'entgelt|gebühr|gebuehr|kontoführung|kontofuehrung|rechnungsabschluss|kontoabschluss', 70),
    ('bank_interest',      'Zinsen (Aufwand)',                         'mdi-percent-outline',        'out', '2110', '7310', 'zins', 71),
    ('loan',               'Darlehen (Tilgung)',                       'mdi-home-city-outline',      'any', '0630', '3150', 'tilgung|darlehen|kredit', 80),
    ('trade_tax',          'Gewerbesteuer',                            'mdi-city',                   'out', '4320', '7610', 'gewerbesteuer', 90),
    ('vehicle_tax',        'Kfz-Steuer',                               'mdi-car',                    'out', '4510', '7685', 'kfz-?steuer|kraftfahrzeugsteuer', 91),
    ('other',              'Anderes Konto',                            'mdi-dots-horizontal',        'any', NULL,   NULL,   NULL, 999)
ON CONFLICT (key) DO NOTHING;

-- ============================================================================
-- KARTENABRECHNUNG: doppelte Auszahlungszeilen bereinigen
-- ============================================================================
-- Derselbe SumUp-Bericht wurde mehrfach hochgeladen (einmal ohne, einmal mit
-- Kreditor) — die Dublettenprüfung verglich den Kreditor mit. Je Kennung bleibt
-- die gebuchte Zeile, sonst die älteste offene; leere Köpfe verschwinden.
DELETE FROM payment_settlement_lines l
USING payment_settlement_lines k
WHERE l.reference IS NOT NULL
  AND upper(k.reference) = upper(l.reference)
  AND k.id <> l.id
  AND l.status = 'open'
  AND (k.status = 'booked' OR (k.status = 'open' AND k.id < l.id));

DELETE FROM payment_settlements s
WHERE NOT EXISTS (SELECT 1 FROM payment_settlement_lines l WHERE l.settlement_id = s.id);

-- ═══════════════════════════════════════════════════════════════
-- ARTIKELSUCHE: VERWENDUNGSHÄUFIGKEIT JE ARTIKEL UND MONAT
--
-- Die Positionssuche in der Faktura (parts.php findParts) sortiert Treffer
-- nach Rangstufe (Beschreibung beginnt mit dem Begriff > Artikelnummer >
-- Wortanfang > irgendwo enthalten) und darin nach Verwendungshäufigkeit.
-- Die Verwendungen bei jeder Eingabe aus orderitems/invoice zu zählen wäre
-- bei großen Artikelstämmen zu teuer — deshalb hält diese Tabelle je Artikel
-- und Belegmonat einen Zähler, den Trigger auf orderitems und invoice in
-- Echtzeit pflegen. Die Monatsauflösung erlaubt eine Gewichtung „zuletzt
-- verwendet" (parts_search_recent_months) ohne Verfall der Zähler.
-- parts_usage_rebuild() baut die Tabelle aus den Bestandsdaten neu auf
-- (Erstbefüllung beim Upstall, Knopf in der Firmenkonfiguration).
-- Gewichte: defaults_oserp parts_search_limit / parts_search_usage_weight /
-- parts_search_recent_weight / parts_search_recent_months.
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS parts_usage_oserp (
    parts_id INTEGER NOT NULL,
    month DATE NOT NULL,               -- erster Tag des Belegmonats (oe/ar/ap.transdate)
    uses INTEGER NOT NULL DEFAULT 0,   -- Anzahl Positionen in diesem Monat
    PRIMARY KEY (parts_id, month)
);

CREATE OR REPLACE FUNCTION parts_usage_track() RETURNS trigger AS $$
DECLARE
    v_trans_id INTEGER := CASE WHEN TG_OP = 'DELETE' THEN OLD.trans_id ELSE NEW.trans_id END;
    v_old_part INTEGER := CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE OLD.parts_id END;
    v_new_part INTEGER := CASE WHEN TG_OP = 'DELETE' THEN NULL ELSE NEW.parts_id END;
    v_month    DATE;
BEGIN
    -- Nur ein Artikelwechsel oder Anlegen/Löschen ändert die Zähler
    IF v_old_part IS NOT DISTINCT FROM v_new_part THEN
        RETURN NULL;
    END IF;

    IF TG_TABLE_NAME = 'orderitems' THEN
        SELECT date_trunc('month', transdate)::date INTO v_month FROM oe WHERE id = v_trans_id;
    ELSE
        SELECT date_trunc('month', transdate)::date INTO v_month FROM ar WHERE id = v_trans_id;
        IF v_month IS NULL THEN
            SELECT date_trunc('month', transdate)::date INTO v_month FROM ap WHERE id = v_trans_id;
        END IF;
    END IF;
    v_month := COALESCE(v_month, date_trunc('month', current_date)::date);

    IF v_old_part IS NOT NULL THEN
        UPDATE parts_usage_oserp SET uses = GREATEST(uses - 1, 0)
         WHERE parts_id = v_old_part AND month = v_month;
    END IF;
    IF v_new_part IS NOT NULL THEN
        INSERT INTO parts_usage_oserp (parts_id, month, uses) VALUES (v_new_part, v_month, 1)
        ON CONFLICT (parts_id, month) DO UPDATE SET uses = parts_usage_oserp.uses + 1;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DO $$ DECLARE t TEXT; BEGIN
    FOREACH t IN ARRAY ARRAY['orderitems', 'invoice'] LOOP
        IF to_regclass(t) IS NOT NULL AND NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = t || '_parts_usage') THEN
            EXECUTE format('CREATE TRIGGER %I AFTER INSERT OR DELETE OR UPDATE OF parts_id ON %I FOR EACH ROW EXECUTE FUNCTION parts_usage_track()', t || '_parts_usage', t);
        END IF;
    END LOOP;
END $$;

-- Zähler komplett aus orderitems (Angebote, Aufträge, Lieferscheine) und
-- invoice (Verkaufs- und Einkaufsrechnungen) neu aufbauen. Liefert die
-- Kennzahlen danach — als Funktion, damit sie den Neuaufbau schon sehen.
DROP FUNCTION IF EXISTS parts_usage_rebuild();
CREATE OR REPLACE FUNCTION parts_usage_rebuild()
RETURNS TABLE (rows_built INTEGER, parts_with_usage INTEGER, total_uses BIGINT) AS $$
BEGIN
    DELETE FROM parts_usage_oserp;
    INSERT INTO parts_usage_oserp (parts_id, month, uses)
    SELECT u.parts_id, u.month, count(*)
      FROM (
          SELECT oi.parts_id, date_trunc('month', COALESCE(oe.transdate, current_date))::date AS month
            FROM orderitems oi
            LEFT JOIN oe ON oe.id = oi.trans_id
           WHERE oi.parts_id IS NOT NULL
          UNION ALL
          SELECT i.parts_id, date_trunc('month', COALESCE(ar.transdate, ap.transdate, current_date))::date
            FROM invoice i
            LEFT JOIN ar ON ar.id = i.trans_id
            LEFT JOIN ap ON ap.id = i.trans_id
           WHERE i.parts_id IS NOT NULL
      ) u
     GROUP BY u.parts_id, u.month;
    GET DIAGNOSTICS rows_built = ROW_COUNT;
    SELECT count(DISTINCT pu.parts_id), COALESCE(sum(pu.uses), 0)
      INTO parts_with_usage, total_uses
      FROM parts_usage_oserp pu;
    RETURN NEXT;
END;
$$ LANGUAGE plpgsql;

-- Erstbefüllung: nur wenn die Tabelle noch leer ist (danach pflegen die Trigger)
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM parts_usage_oserp) THEN
        PERFORM parts_usage_rebuild();
    END IF;
END $$;

-- ============================================================================
-- KUNDENPORTAL (dev/kundenportal.md, 2026-10-21)
-- ============================================================================
--
-- Kunden erreichen über eine Webseite (HugoCMS) ihr Konto: Anmeldung,
-- Stammdaten, Adressen, Rechnungen — und, je nach aktiver Erweiterung, den
-- Shop (Warenkorb, Kasse) oder die Fahrzeuge (LxCars). Der Kern hält die
-- Webseite, die Sitzung, den Rechnungslink und die Freischaltung; die
-- Erweiterungen hängen sich daran (shop: sales_channel_shop.website_id,
-- portal_context_oserp.cart_uuid; ar_link_hugoshop trägt die PayPal-Felder
-- zur selben uuid wie invoice_link_oserp).
--
-- Reihenfolge: dieses Schema läuft vor den Erweiterungen, deren Schemata
-- dürfen die Tabellen hier voraussetzen.

-- Webseite: Name, Einstellungen (jsonb, Schlüssel aus website_setting_keys())
CREATE TABLE IF NOT EXISTS website_oserp
(
    id       integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name     text NOT NULL,
    active   boolean NOT NULL DEFAULT true,
    sortkey  integer,
    settings jsonb,
    itime    timestamp without time zone DEFAULT now(),
    mtime    timestamp without time zone,
    CONSTRAINT website_oserp_name_key UNIQUE (name)
);

COMMENT ON TABLE  website_oserp          IS 'Kundenportal: Webseite (HugoCMS), über die Kunden ihr Konto erreichen';
COMMENT ON COLUMN website_oserp.settings IS 'Einstellungen ohne Geheimnisse, Schlüssel aus website_setting_keys()';

-- Geheimnisse der Webseite: Schlüssel des Zugangs (public_key) und der
-- HugoCMS-Zugang (hugocms_key) — nur für edit_shop_config (getWebsiteSecrets),
-- nie an den öffentlichen Zugang
CREATE TABLE IF NOT EXISTS website_secret_oserp
(
    website_id integer NOT NULL REFERENCES website_oserp (id) ON DELETE CASCADE,
    key        text NOT NULL,
    value      text NOT NULL DEFAULT '',
    mtime      timestamp without time zone DEFAULT now(),
    PRIMARY KEY (website_id, key)
);

COMMENT ON TABLE website_secret_oserp IS 'Kundenportal: Geheimnisse einer Webseite — nur für edit_shop_config, nie an den öffentlichen Zugang';

-- Der Schlüssel bestimmt im öffentlichen Zugang Mandant und Webseite
-- (portalPublicFindCompany): zwei Webseiten mit demselben Schlüssel wären
-- nicht zu unterscheiden.
CREATE UNIQUE INDEX IF NOT EXISTS website_secret_oserp_public_key
    ON website_secret_oserp (value)
 WHERE key = 'public_key' AND value <> '';

-- Sitzung eines Besuchers (Cookie HUGOSHOPCLIENTID — der Name bleibt, damit
-- laufende Webseiten ihre Sitzungen behalten). uuid kommt aus dem Cookie und
-- gehört immer gebunden. cart_uuid gehört der Shop-Erweiterung (Warenkorb,
-- Fremdschlüssel dort).
CREATE TABLE IF NOT EXISTS portal_context_oserp
(
    uuid        text NOT NULL PRIMARY KEY,
    website_id  integer NOT NULL REFERENCES website_oserp (id) ON DELETE CASCADE,
    customer_id integer REFERENCES customer (id) ON DELETE CASCADE,
    cart_uuid   text,
    active      timestamp without time zone DEFAULT now()
);

COMMENT ON TABLE  portal_context_oserp           IS 'Kundenportal: Sitzung eines Besuchers einer Webseite';
COMMENT ON COLUMN portal_context_oserp.cart_uuid IS 'Warenkorb der Shop-Erweiterung (carts_hugoshop.uuid), sonst NULL';

CREATE INDEX IF NOT EXISTS portal_context_oserp_customer_idx ON portal_context_oserp (customer_id);

-- Drossel der Freischaltung je Sitzung (dev/shop-konto-freischaltung.md, F6):
-- Zähler der Anfragen und Beginn des laufenden Tages
ALTER TABLE portal_context_oserp ADD COLUMN IF NOT EXISTS activation_count integer NOT NULL DEFAULT 0;
ALTER TABLE portal_context_oserp ADD COLUMN IF NOT EXISTS activation_reset timestamp without time zone;

-- Rechnungslink: der Kunde sieht und lädt seine Rechnung über diese uuid, auch
-- ohne Anmeldung (Mail, Rechnungsseite). Die uuid ist das Geheimnis.
CREATE TABLE IF NOT EXISTS invoice_link_oserp
(
    uuid       text NOT NULL PRIMARY KEY,
    ar_id      integer NOT NULL REFERENCES ar (id) ON UPDATE CASCADE ON DELETE CASCADE,
    website_id integer REFERENCES website_oserp (id) ON DELETE SET NULL,
    active     timestamp without time zone DEFAULT now(),
    CONSTRAINT invoice_link_oserp_ar_id_key UNIQUE (ar_id)
);

COMMENT ON TABLE invoice_link_oserp IS 'Kundenportal: Geheimnis im Rechnungslink — ersetzt die Anmeldung';

-- Freischaltung eines Bestandskunden (dev/shop-konto-freischaltung.md): die
-- Kennung geht per WhatsApp an den Kunden, der damit E-Mail-Adresse und
-- Kennwort setzt. Je Kunde höchstens eine offene Kennung.
CREATE TABLE IF NOT EXISTS portal_activation_oserp
(
    uuid        text NOT NULL PRIMARY KEY,
    customer_id integer NOT NULL REFERENCES customer (id) ON DELETE CASCADE,
    website_id  integer REFERENCES website_oserp (id) ON DELETE CASCADE,
    phone       text NOT NULL DEFAULT '',
    source      text NOT NULL DEFAULT 'portal',
    created     timestamp without time zone NOT NULL DEFAULT now(),
    expires     timestamp without time zone NOT NULL,
    used_at     timestamp without time zone,
    CONSTRAINT portal_activation_oserp_source_check CHECK (source IN ('portal', 'staff'))
);

COMMENT ON TABLE  portal_activation_oserp        IS 'Kundenportal: Kennung zur Freischaltung eines Bestandskunden (WhatsApp-Link)';
COMMENT ON COLUMN portal_activation_oserp.source IS 'portal = vom Kunden angefragt, staff = aus der Kundenakte gesendet';

CREATE UNIQUE INDEX IF NOT EXISTS portal_activation_oserp_offen
    ON portal_activation_oserp (customer_id)
 WHERE used_at IS NULL;

-- Kunde: Gastkonto und gewählte Standard-Lieferadresse. Umbenannt aus den
-- Spalten der Shop-Erweiterung (hugoshop_*), wo sie vorhanden sind.
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_name = 'customer_ext' AND column_name = 'hugoshop_guest')
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns
                        WHERE table_name = 'customer_ext' AND column_name = 'portal_guest') THEN
        ALTER TABLE customer_ext RENAME COLUMN hugoshop_guest TO portal_guest;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_name = 'customer_ext' AND column_name = 'hugoshop_shipto_id')
       AND NOT EXISTS (SELECT 1 FROM information_schema.columns
                        WHERE table_name = 'customer_ext' AND column_name = 'portal_shipto_id') THEN
        ALTER TABLE customer_ext RENAME COLUMN hugoshop_shipto_id TO portal_shipto_id;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_constraint
                WHERE conrelid = 'customer_ext'::regclass AND conname = 'customer_ext_hugoshop_shipto_id_fkey') THEN
        ALTER TABLE customer_ext RENAME CONSTRAINT customer_ext_hugoshop_shipto_id_fkey TO customer_ext_portal_shipto_id_fkey;
    END IF;
END $$;

ALTER TABLE customer_ext ADD COLUMN IF NOT EXISTS portal_guest boolean NOT NULL DEFAULT false;
ALTER TABLE customer_ext ADD COLUMN IF NOT EXISTS portal_shipto_id integer;

COMMENT ON COLUMN customer_ext.portal_guest     IS 'Kundenportal: Gast (ohne Kennwort, etwa Gastbestellung im Shop)';
COMMENT ON COLUMN customer_ext.portal_shipto_id IS 'Kundenportal: gewählte Standard-Lieferadresse';

-- SET NULL und nicht CASCADE: ein Zeiger auf eine Einstellung, keine
-- Besitzbeziehung. Mit CASCADE risse das Löschen einer Lieferadresse die
-- ganze Kundenzeile mit.
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conrelid = 'customer_ext'::regclass
                      AND conname = 'customer_ext_portal_shipto_id_fkey') THEN
        ALTER TABLE customer_ext
            ADD CONSTRAINT customer_ext_portal_shipto_id_fkey
            FOREIGN KEY (portal_shipto_id) REFERENCES shipto (shipto_id) ON DELETE SET NULL;
    END IF;
END $$;

-- Einstellungen einer Webseite: Schlüssel in settings bzw. — secret — in
-- website_secret_oserp. Was eine Erweiterung je Webseite braucht (Adressmuster
-- der Produktseiten, PayPal), steht bei ihr (Kanal), nicht hier.
CREATE OR REPLACE FUNCTION website_setting_keys()
RETURNS TABLE (key text, secret boolean)
    LANGUAGE sql IMMUTABLE AS $$
    VALUES
        -- Zugang der Webseite zu OpensourceERP
        ('public_key',      true),   -- Schlüssel im Kopf X-Shop-Key, bestimmt Mandant und Webseite
        ('allowed_origins', false),  -- erlaubte Herkünfte bei Zugriff ohne Weiterleiter
        ('base_url',        false),  -- Adresse der Webseite
        ('backend_url',     false),  -- Adresse des Portal-Zugangs von OpensourceERP, für den Weiterleiter
        -- Seiten der Webseite: Pfade, unter denen das Paket die Portalseiten
        -- anlegt (oserp-portal/content/) und auf die Widgets und Mails verlinken.
        -- Ohne führenden Schrägstrich relativ zum Verzeichnis des Kundenportals
        ('portal_path',     false),  -- Verzeichnis des Kundenportals, Vorgabe /kunden/
        ('login_page',      false),  -- Anmeldung
        ('register_page',   false),  -- Registrierung
        ('account_page',    false),  -- Kundenkonto; Unterseiten (Profil, Adressen, Rechnungen …) hängen daran
        ('invoice_page',    false),  -- Seite mit der Rechnungsansicht (Links in Mails)
        ('activation_enabled', false), -- Konto-Freischaltung für Bestandskunden (WhatsApp) angeboten; Vorgabe aus
        ('activate_page',   false),  -- Seite „Konto freischalten“ (Link in der WhatsApp-Nachricht)
        ('contact_page',    false),  -- Kontaktformular
        ('contact_mail_to', false),  -- Empfänger des Kontaktformulars; leer = Absenderadresse der Firma
        -- Veröffentlichung
        ('template_set',    false),  -- Vorlagensatz des Pakets (backend/templates-default/portal/<satz>/kit/)
        ('hugocms_url',     false),  -- cms-api-Adresse der Webseite in HugoCMS
        ('hugocms_key',     true)    -- Schlüssel der Shop-Anbindung von HugoCMS
$$;

-- Ziffern einer Telefonnummer, international ohne Plus: 0171… wird zu
-- 49171…, +49 171… und 0049… ebenso. Für den Vergleich bei der Freischaltung
-- (activationFindCustomer); land ist die Vorwahl ohne Plus.
CREATE OR REPLACE FUNCTION portal_phone_digits(nummer text, land text)
RETURNS text LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN d = ''        THEN ''
                WHEN d LIKE '00%'  THEN substr(d, 3)
                WHEN d LIKE '0%'   THEN land || substr(d, 2)
                ELSE d END
      FROM (SELECT regexp_replace(COALESCE(nummer, ''), '[^0-9]', '', 'g') AS d) x
$$;

-- Schaltflächen einer WhatsApp-Vorlage (Meta: BUTTONS-Komponente), etwa der
-- Link zur Freischaltung: [{"type": "URL", "text": "…", "url": "https://…/freischalten/?token={{1}}", "example": "abc"}]
ALTER TABLE whatsapp_templates ADD COLUMN IF NOT EXISTS buttons jsonb NOT NULL DEFAULT '[]'::jsonb;
COMMENT ON COLUMN whatsapp_templates.buttons IS 'Schaltflächen (Meta BUTTONS): type URL/QUICK_REPLY/PHONE_NUMBER, text, url mit {{1}} als Suffix, example';

-- Übergang (2026-10-23): Vor dem Verzeichnis des Kundenportals wurden die
-- Seitenpfade absolut gespeichert (/kunden/anmelden/ …). Solange das
-- Verzeichnis noch die Vorgabe ist, werden sie relativ (anmelden/), damit ein
-- geändertes Verzeichnis sie mitnimmt. Idempotent: danach beginnt kein Wert
-- mehr mit /kunden/.
UPDATE website_oserp w
   SET settings = w.settings || (
           SELECT COALESCE(jsonb_object_agg(k, substr(w.settings ->> k, length('/kunden/') + 1)), '{}'::jsonb)
             FROM unnest(ARRAY['login_page', 'register_page', 'account_page', 'invoice_page', 'activate_page', 'contact_page']) AS k
            WHERE w.settings ->> k LIKE '/kunden/_%')
 WHERE COALESCE(w.settings ->> 'portal_path', '/kunden/') = '/kunden/'
   AND EXISTS (SELECT 1 FROM unnest(ARRAY['login_page', 'register_page', 'account_page', 'invoice_page', 'activate_page', 'contact_page']) AS k
                WHERE w.settings ->> k LIKE '/kunden/_%');

-- Vorgaben: Vorlagensatz, das Verzeichnis des Kundenportals und die Seiten
-- darin (relativ, websitePagePath hängt sie an portal_path) — so legt das
-- Paket die Seiten ohne weitere Einstellung an (portalKitPages)
CREATE OR REPLACE FUNCTION website_default_settings() RETURNS jsonb
    LANGUAGE sql IMMUTABLE AS $$
    SELECT jsonb_build_object(
        'template_set',  'standard',
        'activation_enabled', '0',
        'portal_path',   '/kunden/',
        'login_page',    'anmelden/',
        'register_page', 'registrieren/',
        'account_page',  'konto/',
        'invoice_page',  'rechnung/',
        'activate_page', 'freischalten/',
        'contact_page',  'kontakt/'
    )
$$;

-- Sitzungen verfallen: jeder Zugriff eines Besuchers räumt ein wenig mit auf,
-- es braucht keinen Cronjob. Frist aus defaults_oserp
-- (portal_context_lifetime_hours, übergangsweise shop_context_lifetime_hours).
CREATE OR REPLACE FUNCTION cleanup_portal_context_oserp() RETURNS trigger AS $$
BEGIN
    DELETE FROM portal_context_oserp
     WHERE active < NOW() - (COALESCE(
               (SELECT value FROM defaults_oserp WHERE key = 'portal_context_lifetime_hours'),
               (SELECT value FROM defaults_oserp WHERE key = 'shop_context_lifetime_hours'),
               '24') || ' hours')::interval;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trigger_cleanup_portal_context_oserp') THEN
        CREATE TRIGGER trigger_cleanup_portal_context_oserp
            AFTER INSERT OR UPDATE ON portal_context_oserp
            FOR EACH STATEMENT EXECUTE FUNCTION cleanup_portal_context_oserp();
    END IF;
END $$;

-- Abgelaufene Freischaltungen gehen mit — selten genug, um am selben
-- Trigger zu hängen
CREATE OR REPLACE FUNCTION cleanup_portal_activation_oserp() RETURNS trigger AS $$
BEGIN
    DELETE FROM portal_activation_oserp WHERE expires < NOW() - interval '7 days';
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trigger_cleanup_portal_activation_oserp') THEN
        CREATE TRIGGER trigger_cleanup_portal_activation_oserp
            AFTER INSERT ON portal_activation_oserp
            FOR EACH STATEMENT EXECUTE FUNCTION cleanup_portal_activation_oserp();
    END IF;
END $$;

-- ============================================================================
-- RECHNUNGSSTATUS EINES AUFTRAGS
-- ============================================================================
-- Welche Rechnung(en) sind aus einem Auftrag (oe) entstanden? Genutzt von der
-- Auftragsliste, der Belegkarte im CRM und der Auftragsmaske — eine Stelle,
-- ein Ergebnis.
--
-- Verknüpfung:
--   1. record_links oe → ar/ap, auch über einen Lieferschein (oe → delivery_orders → ar/ap)
--   2. Altbelege ohne record_links: die Auftragsnummer im Rechnungskopf (ar.ordnumber)
-- Gutschriften zählen nicht als Rechnung. Stornierte Rechnungen (storno = true,
-- Original und Stornobeleg) zählen nicht als gültige Rechnung — gibt es nur
-- solche, lautet der Zustand 'storno'.
--
-- Rückgabe (json):
--   state    'invoiced' | 'storno' | 'none'
--   count    Anzahl gültiger Rechnungen
--   invoices [{id, kind (invoice|purchase_invoice), number, transdate, amount, paid, storno}]
--            gültige zuerst, jeweils neueste zuerst
CREATE OR REPLACE FUNCTION oe_invoice_status(p_oe_id integer)
    RETURNS json
    LANGUAGE sql
    STABLE
AS $function$
    WITH o AS (
        SELECT id, ordnumber, (record_type = 'purchase_order') AS purchase FROM oe WHERE id = p_oe_id
    ),
    linked AS (
        SELECT rl.to_table, rl.to_id
          FROM o
          JOIN record_links rl ON rl.from_table = 'oe' AND rl.from_id = o.id AND rl.to_table IN ('ar', 'ap')
        UNION
        SELECT rl2.to_table, rl2.to_id
          FROM o
          JOIN record_links rl  ON rl.from_table = 'oe' AND rl.from_id = o.id AND rl.to_table = 'delivery_orders'
          JOIN record_links rl2 ON rl2.from_table = 'delivery_orders' AND rl2.from_id = rl.to_id AND rl2.to_table IN ('ar', 'ap')
    ),
    inv AS (
        SELECT ar.id, ar.invnumber, ar.transdate, ar.amount, ar.paid, COALESCE(ar.storno, FALSE) AS storno, 'invoice' AS kind
          FROM ar
         WHERE NOT (SELECT purchase FROM o)
           AND COALESCE(ar.type, '') <> 'credit_note'
           AND ar.id IN (SELECT to_id FROM linked WHERE to_table = 'ar')
        UNION
        SELECT ar.id, ar.invnumber, ar.transdate, ar.amount, ar.paid, COALESCE(ar.storno, FALSE), 'invoice'
          FROM o, ar
         WHERE NOT o.purchase
           AND COALESCE(ar.type, '') <> 'credit_note'
           AND COALESCE(o.ordnumber, '') <> ''
           AND lower(ar.ordnumber) = lower(o.ordnumber)
        UNION
        SELECT ap.id, ap.invnumber, ap.transdate, ap.amount, ap.paid, COALESCE(ap.storno, FALSE), 'purchase_invoice'
          FROM ap
         WHERE (SELECT purchase FROM o)
           AND ap.id IN (SELECT to_id FROM linked WHERE to_table = 'ap')
        UNION
        SELECT ap.id, ap.invnumber, ap.transdate, ap.amount, ap.paid, COALESCE(ap.storno, FALSE), 'purchase_invoice'
          FROM o, ap
         WHERE o.purchase
           AND COALESCE(o.ordnumber, '') <> ''
           AND lower(ap.ordnumber) = lower(o.ordnumber)
    )
    SELECT json_build_object(
        'state', CASE WHEN EXISTS (SELECT 1 FROM inv WHERE NOT storno) THEN 'invoiced'
                      WHEN EXISTS (SELECT 1 FROM inv) THEN 'storno'
                      ELSE 'none' END,
        'count', (SELECT count(*) FROM inv WHERE NOT storno),
        'invoices', (
            SELECT COALESCE(json_agg(json_build_object(
                       'id', i.id, 'kind', i.kind, 'number', i.invnumber, 'transdate', i.transdate,
                       'amount', i.amount, 'paid', i.paid, 'storno', i.storno
                   ) ORDER BY i.storno, i.transdate DESC, i.id DESC), '[]'::json)
              FROM inv i
        )
    )
$function$;

-- ============================================================================
-- KONTAKTHISTORIE LIVE (SSE-Kanal contact_history_change)
-- ============================================================================
-- Die Kontakthistorie im CRM aktualisiert sich ohne Knopf: Briefe, deren Versand
-- (E-Mail/Post) und „Kunde vor Ort"-Notizen melden sich per Trigger, neue E-Mails
-- meldet der Dienst oserp-email-eingang (backend/cli/email-eingang.php) über
-- contact_history_email_notify(). Nutzlast: {kind, cv_ids} — das Frontend lädt
-- nur, wenn der offene Kunde/Lieferant betroffen ist.

CREATE OR REPLACE FUNCTION contact_history_notify(p_kind TEXT, p_cv_ids INTEGER[]) RETURNS VOID AS $$
BEGIN
    p_cv_ids := array_remove(p_cv_ids, NULL);
    IF cardinality(p_cv_ids) > 0 THEN
        PERFORM pg_notify('contact_history_change', json_build_object('kind', p_kind, 'cv_ids', p_cv_ids)::TEXT);
    END IF;
END;
$$ LANGUAGE plpgsql;

-- Briefe: Anlegen, Ändern, Löschen
CREATE OR REPLACE FUNCTION notify_contact_history_letter() RETURNS trigger AS $$
BEGIN
    PERFORM contact_history_notify('letter', ARRAY[
        COALESCE(NEW.customer_id, OLD.customer_id), COALESCE(NEW.vendor_id, OLD.vendor_id)]);
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

-- Versand eines Briefs per E-Mail (record_links letter → email_journal)
CREATE OR REPLACE FUNCTION notify_contact_history_letter_link() RETURNS trigger AS $$
BEGIN
    IF NEW.from_table = 'letter' THEN
        PERFORM contact_history_notify('letter',
            (SELECT ARRAY[customer_id, vendor_id] FROM letter WHERE id = NEW.from_id));
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Versand eines Briefs per Post (postal_dispatches)
CREATE OR REPLACE FUNCTION notify_contact_history_letter_post() RETURNS trigger AS $$
BEGIN
    IF NEW.record_table = 'letter' THEN
        PERFORM contact_history_notify('letter',
            (SELECT ARRAY[customer_id, vendor_id] FROM letter WHERE id = NEW.record_id));
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Kunde vor Ort (kivitendo-Notiz am Kunden/Lieferanten, trans_module CT)
CREATE OR REPLACE FUNCTION notify_contact_history_note() RETURNS trigger AS $$
BEGIN
    IF COALESCE(NEW.trans_module, OLD.trans_module) = 'CT' THEN
        PERFORM contact_history_notify('visit', ARRAY[COALESCE(NEW.trans_id, OLD.trans_id)]);
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'letter_contact_history_notify') THEN
        CREATE TRIGGER letter_contact_history_notify
            AFTER INSERT OR UPDATE OR DELETE ON letter
            FOR EACH ROW EXECUTE FUNCTION notify_contact_history_letter();
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'record_links_contact_history_notify') THEN
        CREATE TRIGGER record_links_contact_history_notify
            AFTER INSERT ON record_links
            FOR EACH ROW EXECUTE FUNCTION notify_contact_history_letter_link();
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'postal_dispatches_contact_history_notify') THEN
        CREATE TRIGGER postal_dispatches_contact_history_notify
            AFTER INSERT OR UPDATE OF status ON postal_dispatches
            FOR EACH ROW EXECUTE FUNCTION notify_contact_history_letter_post();
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'notes_contact_history_notify') THEN
        CREATE TRIGGER notes_contact_history_notify
            AFTER INSERT OR UPDATE OR DELETE ON notes
            FOR EACH ROW EXECUTE FUNCTION notify_contact_history_note();
    END IF;
END $$;

-- E-Mail-Adressen den Kunden/Lieferanten zuordnen — Hauptadresse (auch mit ; oder ,
-- getrennt), weitere Adressen aus customer_ext/vendor_ext.emails und
-- Ansprechpersonen, wie collectEmailAddresses() im Frontend. Eine Zeile je
-- Treffer (Adresse klein geschrieben, Kunde/Lieferant); Kunden und Lieferanten
-- teilen sich in kivitendo die id-Folge, die id allein ist also eindeutig.
CREATE OR REPLACE FUNCTION cv_ids_by_email(p_addresses TEXT[])
    RETURNS TABLE (addr TEXT, cv_id INTEGER)
    LANGUAGE sql
    STABLE
AS $function$
    WITH a AS (SELECT DISTINCT lower(trim(x)) AS addr FROM unnest(p_addresses) x WHERE trim(x) <> '')
    SELECT DISTINCT t.addr, t.cv_id FROM (
        SELECT trim(m) AS addr, c.id AS cv_id FROM customer c CROSS JOIN LATERAL regexp_split_to_table(lower(c.email), '\s*[;,]\s*') m
         WHERE c.email IS NOT NULL AND trim(m) IN (SELECT addr FROM a)
        UNION ALL
        SELECT trim(m), v.id FROM vendor v CROSS JOIN LATERAL regexp_split_to_table(lower(v.email), '\s*[;,]\s*') m
         WHERE v.email IS NOT NULL AND trim(m) IN (SELECT addr FROM a)
        UNION ALL
        SELECT trim(m), k.cp_cv_id FROM contacts k CROSS JOIN LATERAL regexp_split_to_table(lower(k.cp_email), '\s*[;,]\s*') m
         WHERE k.cp_email IS NOT NULL AND trim(m) IN (SELECT addr FROM a)
        UNION ALL
        SELECT trim(m), x.customer_id FROM customer_ext x CROSS JOIN LATERAL jsonb_array_elements(x.emails) e
               CROSS JOIN LATERAL regexp_split_to_table(lower(e->>'email'), '\s*[;,]\s*') m
         WHERE jsonb_typeof(x.emails) = 'array' AND trim(m) IN (SELECT addr FROM a)
        UNION ALL
        SELECT trim(m), x.vendor_id FROM vendor_ext x CROSS JOIN LATERAL jsonb_array_elements(x.emails) e
               CROSS JOIN LATERAL regexp_split_to_table(lower(e->>'email'), '\s*[;,]\s*') m
         WHERE jsonb_typeof(x.emails) = 'array' AND trim(m) IN (SELECT addr FROM a)
    ) t
    WHERE t.cv_id IS NOT NULL
$function$;

-- Neue E-Mails (Posteingang und Gesendet): alle Adressen aus dem Umschlag
-- (Von, An, Cc …) den Kunden/Lieferanten zuordnen (cv_ids_by_email) und die
-- offenen Kontakthistorien benachrichtigen. Gibt die Anzahl betroffener
-- Kunden/Lieferanten zurück.
CREATE OR REPLACE FUNCTION contact_history_email_notify(p_addresses TEXT[]) RETURNS INTEGER AS $$
DECLARE
    v_ids INTEGER[];
BEGIN
    SELECT array_agg(DISTINCT cv_id) INTO v_ids FROM cv_ids_by_email(p_addresses);
    PERFORM contact_history_notify('email', v_ids);
    RETURN COALESCE(cardinality(v_ids), 0);
END;
$$ LANGUAGE plpgsql;

-- ============================================================================
-- AUFTRAGSBESTÄTIGUNG
-- ============================================================================
-- Ein Verkaufsauftrag ist Auftragseingang (oe.record_type sales_order_intake)
-- oder bestätigt (sales_order). Wie, wann und von wem bestätigt wurde, steht in
-- oe_confirmation_oserp — mit dem Betrag zum Zeitpunkt der Bestätigung, damit
-- die Auftragsmaske einen späteren Anstieg anzeigen kann. Dieselbe Tabelle hält
-- die Vorschläge der KI: Anrufe, WhatsApp und E-Mails von Kunden mit offenem
-- Auftragseingang landen in oe_confirmation_queue_oserp, der Dienst
-- oserp-auftragsbestaetigung (backend/cli/auftragsbestaetigung.php) liest bzw.
-- hört sie ab und legt bei einer erkannten Zusage oder Absage einen Vorschlag
-- an. Bestätigt wird erst per Klick — im Modus „auto“ nur eindeutige Zusagen
-- (ein offener Auftrag, keine Bedingung, hohe Sicherheit) selbst.
-- Schalter: defaults_oserp.order_confirm_ai (off | suggest | auto) und
-- order_confirm_ai_days (Auftragseingänge der letzten n Tage, Vorgabe 60).

-- status:  suggested (Vorschlag der KI) | confirmed | revoked (zurückgenommen) | dismissed (Vorschlag verworfen)
-- intent:  accept (Zusage) | decline (Absage) — Absagen gibt es nur als Vorschlag
-- source:  manual (von Hand erfasst) | ai (aus einem Vorschlag; employee_id NULL = automatisch bestätigt)
-- channel: phone | email | whatsapp | visit (vor Ort) | signature | portal | other
CREATE TABLE IF NOT EXISTS oe_confirmation_oserp
(
    id          integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    oe_id       integer NOT NULL REFERENCES oe (id) ON DELETE CASCADE,
    status      text NOT NULL DEFAULT 'confirmed',
    intent      text NOT NULL DEFAULT 'accept',
    source      text NOT NULL DEFAULT 'manual',
    channel     text NOT NULL DEFAULT 'other',
    source_ref  text,
    quote       text,
    note        text,
    conditions  text,
    confidence  numeric(4,3),
    amount      numeric(15,5),
    netamount   numeric(15,5),
    agreed_at   timestamptz NOT NULL DEFAULT now(),
    employee_id integer REFERENCES employee (id) ON DELETE SET NULL,
    created     timestamptz NOT NULL DEFAULT now(),
    decided_at  timestamptz,
    decided_by  integer REFERENCES employee (id) ON DELETE SET NULL,
    revoked_at  timestamptz,
    revoked_by  integer REFERENCES employee (id) ON DELETE SET NULL,
    -- (oe_id, id) ist ohnehin eindeutig — die Bedingung liefert den Index für die Suche je Auftrag
    CONSTRAINT oe_confirmation_oserp_oe_key UNIQUE (oe_id, id),
    CONSTRAINT oe_confirmation_oserp_status_check  CHECK (status IN ('suggested', 'confirmed', 'revoked', 'dismissed')),
    CONSTRAINT oe_confirmation_oserp_intent_check  CHECK (intent IN ('accept', 'decline')),
    CONSTRAINT oe_confirmation_oserp_source_check  CHECK (source IN ('manual', 'ai')),
    CONSTRAINT oe_confirmation_oserp_channel_check CHECK (channel IN ('phone', 'email', 'whatsapp', 'visit', 'signature', 'portal', 'other'))
);

COMMENT ON TABLE  oe_confirmation_oserp            IS 'Auftragsbestätigung: wie/wann/von wem ein Verkaufsauftrag bestätigt wurde, dazu KI-Vorschläge';
COMMENT ON COLUMN oe_confirmation_oserp.source_ref IS 'Quelle des Vorschlags: whatsapp_messages.id, unique_call_id bzw. Postfach|UID der E-Mail';
COMMENT ON COLUMN oe_confirmation_oserp.quote      IS 'Wörtliche Zusage/Absage des Kunden (bei Vorschlägen von der KI zitiert)';
COMMENT ON COLUMN oe_confirmation_oserp.conditions IS 'Bedingung des Kunden, z. B. „nicht über 800 €“ — verhindert die automatische Bestätigung';
COMMENT ON COLUMN oe_confirmation_oserp.amount     IS 'Bruttobetrag des Auftrags zum Zeitpunkt der Bestätigung';

-- Warteschlange der Erkennung. status: new | done | skipped | error.
-- Anrufe kommen ohne Inhalt — der Dienst wartet auf die Aufnahme (next_try).
CREATE TABLE IF NOT EXISTS oe_confirmation_queue_oserp
(
    id          integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel     text NOT NULL,
    source_ref  text NOT NULL,
    customer_id integer NOT NULL,
    received_at timestamptz NOT NULL DEFAULT now(),
    subject     text,
    content     text,
    media_path  text,
    status      text NOT NULL DEFAULT 'new',
    attempts    integer NOT NULL DEFAULT 0,
    next_try    timestamptz,
    result      jsonb,
    error       text,
    created     timestamptz NOT NULL DEFAULT now(),
    processed   timestamptz,
    CONSTRAINT oe_confirmation_queue_oserp_source_key UNIQUE (channel, source_ref),
    CONSTRAINT oe_confirmation_queue_oserp_status_key UNIQUE (status, id),
    CONSTRAINT oe_confirmation_queue_oserp_status_check  CHECK (status IN ('new', 'done', 'skipped', 'error')),
    CONSTRAINT oe_confirmation_queue_oserp_channel_check CHECK (channel IN ('phone', 'email', 'whatsapp'))
);

COMMENT ON TABLE  oe_confirmation_queue_oserp            IS 'Auftragsbestätigung: eingegangene Nachrichten/Anrufe, die die KI auf eine Zusage prüft';
COMMENT ON COLUMN oe_confirmation_queue_oserp.media_path IS 'WhatsApp-Sprachnachricht, relativ zu backend/data/<db>';

-- Modus der Erkennung: off | suggest | auto
CREATE OR REPLACE FUNCTION oe_confirmation_mode()
    RETURNS text
    LANGUAGE sql
    STABLE
AS $function$
    SELECT COALESCE((SELECT CASE WHEN value IN ('suggest', 'auto') THEN value END
                       FROM defaults_oserp WHERE key = 'order_confirm_ai'), 'off')
$function$;

-- Offene Auftragseingänge eines Kunden zum Zeitpunkt p_before: angelegt bis
-- dahin, nicht geschlossen, noch nicht berechnet (OSERP schließt einen Auftrag
-- beim Umwandeln in die Rechnung nicht — eine erledigte Arbeit ist kein offener
-- Auftrag) und jünger als order_confirm_ai_days (1–3650, Vorgabe 60) — nur auf
-- sie bezieht die KI eine Zusage.
-- Rückgabe: [{id, ordnumber, transdate, amount, netamount, description, items[]}], neueste zuerst
CREATE OR REPLACE FUNCTION oe_confirmation_candidates(p_customer_id integer, p_before timestamptz)
    RETURNS json
    LANGUAGE sql
    STABLE
AS $function$
    SELECT COALESCE(json_agg(json_build_object(
               'id', o.id, 'ordnumber', o.ordnumber, 'transdate', o.transdate,
               'amount', o.amount, 'netamount', o.netamount,
               'description', NULLIF(o.transaction_description, ''),
               'items', (SELECT COALESCE(json_agg(i.description ORDER BY i.position), '[]'::json)
                           FROM (SELECT description, position FROM orderitems
                                  WHERE trans_id = o.id ORDER BY position LIMIT 8) i)
           ) ORDER BY o.transdate DESC, o.id DESC), '[]'::json)
      FROM oe o
     WHERE o.customer_id = p_customer_id
       AND o.record_type = 'sales_order_intake'
       AND NOT COALESCE(o.closed, FALSE)
       AND o.itime::timestamptz <= COALESCE(p_before, now())
       AND o.transdate >= CURRENT_DATE - LEAST(GREATEST(COALESCE(
               (SELECT NULLIF(regexp_replace(value, '[^0-9]', '', 'g'), '')::numeric
                  FROM defaults_oserp WHERE key = 'order_confirm_ai_days'), 60), 1), 3650)::int
       AND (oe_invoice_status(o.id)->>'state') IS DISTINCT FROM 'invoiced'
$function$;

-- Offene Auftragseingänge jetzt (Einreihen, E-Mail-Vorauswahl)
CREATE OR REPLACE FUNCTION oe_confirmation_candidates(p_customer_id integer)
    RETURNS json
    LANGUAGE sql
    STABLE
AS $function$
    SELECT oe_confirmation_candidates(p_customer_id, now())
$function$;

-- Bestätigungsstand eines Verkaufsauftrags (NULL bei anderen Belegen):
--   confirmed     bestätigt (record_type sales_order)
--   amount        aktueller Bruttobetrag
--   confirmation  letzte gültige Bestätigung {id, channel, agreed_at, quote, note, conditions,
--                 amount, source, source_ref, auto, employee_name, decided_at} oder NULL
--                 (bestätigt, aber nicht erfasst — z. B. von früher)
--   suggestions   offene Vorschläge der KI [{id, intent, channel, agreed_at, quote,
--                 conditions, confidence, source_ref}], neueste zuerst
CREATE OR REPLACE FUNCTION oe_confirmation_status(p_oe_id integer)
    RETURNS json
    LANGUAGE sql
    STABLE
AS $function$
    SELECT json_build_object(
        'confirmed', o.record_type = 'sales_order',
        'amount', o.amount,
        'confirmation', (
            SELECT json_build_object(
                       'id', c.id, 'channel', c.channel, 'agreed_at', c.agreed_at,
                       'quote', c.quote, 'note', c.note, 'conditions', c.conditions,
                       'amount', c.amount, 'source', c.source, 'source_ref', c.source_ref,
                       'auto', c.source = 'ai' AND c.employee_id IS NULL,
                       'employee_name', e.name, 'decided_at', c.decided_at)
              FROM oe_confirmation_oserp c
              LEFT JOIN employee e ON e.id = c.employee_id
             WHERE c.oe_id = o.id AND c.status = 'confirmed' AND o.record_type = 'sales_order'
             ORDER BY c.decided_at DESC NULLS LAST, c.id DESC
             LIMIT 1),
        'suggestions', (
            SELECT COALESCE(json_agg(json_build_object(
                       'id', s.id, 'intent', s.intent, 'channel', s.channel, 'agreed_at', s.agreed_at,
                       'quote', s.quote, 'conditions', s.conditions, 'confidence', s.confidence,
                       'source_ref', s.source_ref
                   ) ORDER BY s.agreed_at DESC, s.id DESC), '[]'::json)
              FROM oe_confirmation_oserp s
             WHERE s.oe_id = o.id AND s.status = 'suggested')
    )
      FROM oe o
     WHERE o.id = p_oe_id
       AND o.record_type IN ('sales_order', 'sales_order_intake')
$function$;

-- Bestätigen (p_confirmed) oder zurücknehmen. Bestätigen legt einen Eintrag mit
-- Kanal, Zeitpunkt und aktuellem Betrag an — oder übernimmt den Vorschlag
-- p_suggestion_id samt Zitat und dem Betrag zum Zeitpunkt der Kundennachricht;
-- alle weiteren offenen Vorschläge (auch Absagen) sind damit entschieden. Ist der Auftrag schon bestätigt, wird neu bestätigt (etwa nach einer
-- Preiserhöhung), die alte Bestätigung bleibt als Verlauf stehen. Zurücknehmen
-- markiert alle Bestätigungen als revoked. Rückgabe: oe_confirmation_status,
-- bei Fehlern {error: OE_NOT_FOUND | NOT_SALES_ORDER | SUGGESTION_NOT_FOUND}.
CREATE OR REPLACE FUNCTION oe_confirm(
    p_oe_id         integer,
    p_confirmed     boolean,
    p_employee_id   integer,
    p_channel       text DEFAULT NULL,
    p_agreed_at     timestamptz DEFAULT NULL,
    p_note          text DEFAULT NULL,
    p_suggestion_id integer DEFAULT NULL
) RETURNS json AS $$
DECLARE
    v_type   text;
    v_amount numeric;
    v_net    numeric;
BEGIN
    SELECT record_type::text, COALESCE(amount, 0), COALESCE(netamount, 0) INTO v_type, v_amount, v_net
      FROM oe WHERE id = p_oe_id FOR UPDATE;
    IF NOT FOUND THEN
        RETURN json_build_object('error', 'OE_NOT_FOUND');
    END IF;
    IF v_type NOT IN ('sales_order', 'sales_order_intake') THEN
        RETURN json_build_object('error', 'NOT_SALES_ORDER');
    END IF;

    IF p_confirmed THEN
        IF p_suggestion_id IS NOT NULL THEN
            UPDATE oe_confirmation_oserp
               SET status = 'confirmed', employee_id = p_employee_id, decided_by = p_employee_id, decided_at = now(),
                   amount = COALESCE(amount, v_amount), netamount = COALESCE(netamount, v_net),
                   note = COALESCE(NULLIF(trim(p_note), ''), note)
             WHERE id = p_suggestion_id AND oe_id = p_oe_id AND status = 'suggested' AND intent = 'accept';
            IF NOT FOUND THEN
                RETURN json_build_object('error', 'SUGGESTION_NOT_FOUND');
            END IF;
        ELSE
            INSERT INTO oe_confirmation_oserp (oe_id, status, source, channel, agreed_at, note, amount, netamount, employee_id, decided_by, decided_at)
            VALUES (p_oe_id, 'confirmed', 'manual',
                    CASE WHEN p_channel IN ('phone', 'email', 'whatsapp', 'visit', 'signature', 'portal') THEN p_channel ELSE 'other' END,
                    COALESCE(p_agreed_at, now()), NULLIF(trim(p_note), ''), v_amount, v_net, p_employee_id, p_employee_id, now());
        END IF;
        UPDATE oe_confirmation_oserp
           SET status = 'dismissed', decided_by = p_employee_id, decided_at = now()
         WHERE oe_id = p_oe_id AND status = 'suggested';
        IF v_type <> 'sales_order' THEN
            UPDATE oe SET record_type = 'sales_order' WHERE id = p_oe_id;
        END IF;
    ELSE
        UPDATE oe_confirmation_oserp
           SET status = 'revoked', revoked_by = p_employee_id, revoked_at = now()
         WHERE oe_id = p_oe_id AND status = 'confirmed';
        IF v_type <> 'sales_order_intake' THEN
            UPDATE oe SET record_type = 'sales_order_intake' WHERE id = p_oe_id;
        END IF;
    END IF;

    RETURN oe_confirmation_status(p_oe_id);
END;
$$ LANGUAGE plpgsql;

-- Vorschlag verwerfen (Zusage war keine) bzw. Absage zur Kenntnis nehmen.
-- Rückgabe: oe_confirmation_status des Auftrags oder {error: SUGGESTION_NOT_FOUND}
CREATE OR REPLACE FUNCTION oe_confirmation_dismiss(p_id integer, p_employee_id integer) RETURNS json AS $$
DECLARE
    v_oe_id integer;
BEGIN
    UPDATE oe_confirmation_oserp
       SET status = 'dismissed', decided_by = p_employee_id, decided_at = now()
     WHERE id = p_id AND status = 'suggested'
    RETURNING oe_id INTO v_oe_id;
    IF NOT FOUND THEN
        RETURN json_build_object('error', 'SUGGESTION_NOT_FOUND');
    END IF;
    RETURN oe_confirmation_status(v_oe_id);
END;
$$ LANGUAGE plpgsql;

-- Ergebnis der KI für einen Eintrag der Warteschlange übernehmen: Vorschlag
-- anlegen oder — Modus auto, eindeutige Zusage ohne Bedingung, Sicherheit ab
-- 0,9, nur ein offener Auftrag und per WhatsApp oder Anruf — gleich bestätigen.
-- E-Mails bestätigen nie selbst: der Absender einer E-Mail ist fälschbar, und
-- eine gemeinsame Adresse kann zu mehreren Kunden gehören. Der Auftrag muss zu
-- den offenen Auftragseingängen des Kunden beim Eingang der Nachricht gehören.
-- Der Betrag wird schon hier festgehalten: zugesagt hat der Kunde zu diesem
-- Stand. Rückgabe: suggested | confirmed | ignored
CREATE OR REPLACE FUNCTION oe_confirmation_suggest(
    p_queue_id   integer,
    p_oe_id      integer,
    p_intent     text,
    p_quote      text,
    p_conditions text,
    p_confidence numeric
) RETURNS text AS $$
DECLARE
    q           oe_confirmation_queue_oserp%ROWTYPE;
    v_open      integer[];
    v_amount    numeric;
    v_net       numeric;
    v_auto      boolean;
BEGIN
    SELECT * INTO q FROM oe_confirmation_queue_oserp WHERE id = p_queue_id;
    IF NOT FOUND OR p_intent NOT IN ('accept', 'decline') THEN
        RETURN 'ignored';
    END IF;
    SELECT array_agg((c->>'id')::int) INTO v_open
      FROM json_array_elements(oe_confirmation_candidates(q.customer_id, q.received_at)) c;
    IF NOT (p_oe_id = ANY (COALESCE(v_open, '{}'))) THEN
        RETURN 'ignored';
    END IF;
    -- Dieselbe Nachricht schlägt für denselben Auftrag nur einmal vor
    IF EXISTS (SELECT 1 FROM oe_confirmation_oserp
                WHERE oe_id = p_oe_id AND source = 'ai' AND source_ref = q.source_ref AND channel = q.channel) THEN
        RETURN 'ignored';
    END IF;

    v_auto := oe_confirmation_mode() = 'auto'
              AND p_intent = 'accept'
              AND COALESCE(trim(p_conditions), '') = ''
              AND COALESCE(p_confidence, 0) >= 0.9
              AND cardinality(v_open) = 1
              AND q.channel IN ('whatsapp', 'phone');

    SELECT COALESCE(amount, 0), COALESCE(netamount, 0) INTO v_amount, v_net FROM oe WHERE id = p_oe_id FOR UPDATE;
    INSERT INTO oe_confirmation_oserp (oe_id, status, intent, source, channel, source_ref, quote, conditions,
                                       confidence, agreed_at, amount, netamount, decided_at)
    VALUES (p_oe_id, CASE WHEN v_auto THEN 'confirmed' ELSE 'suggested' END, p_intent, 'ai', q.channel, q.source_ref,
            NULLIF(trim(p_quote), ''), NULLIF(trim(p_conditions), ''), p_confidence, q.received_at,
            v_amount, v_net, CASE WHEN v_auto THEN now() END);
    IF v_auto THEN
        -- Wie beim Bestätigen von Hand: übrige offene Vorschläge sind entschieden
        UPDATE oe_confirmation_oserp SET status = 'dismissed', decided_at = now()
         WHERE oe_id = p_oe_id AND status = 'suggested';
        UPDATE oe SET record_type = 'sales_order' WHERE id = p_oe_id;
        RETURN 'confirmed';
    END IF;
    RETURN 'suggested';
END;
$$ LANGUAGE plpgsql;

-- Eingang in die Warteschlange stellen — nur wenn die Erkennung an ist und der
-- Kunde einen offenen Auftragseingang hat. Meldet dem Dienst per pg_notify.
CREATE OR REPLACE FUNCTION oe_confirmation_enqueue(
    p_channel     text,
    p_source_ref  text,
    p_customer_id integer,
    p_received_at timestamptz,
    p_subject     text,
    p_content     text,
    p_media_path  text
) RETURNS boolean AS $$
DECLARE
    v_id integer;
BEGIN
    IF p_customer_id IS NULL OR p_source_ref IS NULL OR oe_confirmation_mode() = 'off'
       OR json_array_length(oe_confirmation_candidates(p_customer_id)) = 0 THEN
        RETURN FALSE;
    END IF;
    INSERT INTO oe_confirmation_queue_oserp (channel, source_ref, customer_id, received_at, subject, content, media_path)
    VALUES (p_channel, p_source_ref, p_customer_id, COALESCE(p_received_at, now()), p_subject, p_content, p_media_path)
    ON CONFLICT (channel, source_ref) DO NOTHING
    RETURNING id INTO v_id;
    IF v_id IS NOT NULL THEN
        PERFORM pg_notify('order_confirm_queue', v_id::text);
    END IF;
    RETURN v_id IS NOT NULL;
END;
$$ LANGUAGE plpgsql;

-- E-Mail-Eingang (backend/cli/email-eingang.php): welche Absender gehören zu
-- Kunden mit offenem Auftragseingang? Nur deren Nachrichten lädt der Dienst
-- vollständig. Leer, wenn die Erkennung aus ist.
CREATE OR REPLACE FUNCTION oe_confirmation_email_wanted(p_addresses text[])
    RETURNS text[]
    LANGUAGE sql
    STABLE
AS $function$
    SELECT COALESCE(array_agg(DISTINCT m.addr), '{}')
      FROM cv_ids_by_email(p_addresses) m
     WHERE oe_confirmation_mode() <> 'off'
       AND json_array_length(oe_confirmation_candidates(m.cv_id)) > 0
$function$;

-- E-Mail in die Warteschlange — je zugeordnetem Kunden ein Eintrag.
-- p_ref: Postfach|UID. Rückgabe: Anzahl neuer Einträge
CREATE OR REPLACE FUNCTION oe_confirmation_email_enqueue(
    p_from        text,
    p_ref         text,
    p_received_at timestamptz,
    p_subject     text,
    p_body        text
) RETURNS integer AS $$
DECLARE
    v_cv    integer;
    v_count integer := 0;
BEGIN
    FOR v_cv IN SELECT DISTINCT cv_id FROM cv_ids_by_email(ARRAY[p_from]) LOOP
        IF oe_confirmation_enqueue('email', p_ref || '#' || v_cv, v_cv, p_received_at, p_subject, p_body, NULL) THEN
            v_count := v_count + 1;
        END IF;
    END LOOP;
    RETURN v_count;
END;
$$ LANGUAGE plpgsql;

-- WhatsApp: eingehende Texte, Antwortknöpfe und Sprachnachrichten. Ohne
-- customer_id über die Rufnummer zuordnen (suchenummer, Ansprechpersonen →
-- ihr Kunde). Ein Fehler hier darf den Eingang nie verhindern.
CREATE OR REPLACE FUNCTION oe_confirmation_whatsapp_enqueue() RETURNS trigger AS $$
DECLARE
    v_cv integer := NEW.customer_id;
    r    record;
BEGIN
    IF NEW.direction <> 'I' OR NEW.message_type NOT IN ('text', 'interactive', 'button', 'audio')
       OR oe_confirmation_mode() = 'off' THEN
        RETURN NEW;
    END IF;
    BEGIN
        IF v_cv IS NULL THEN
            r := suchenummer(NEW.phone_number);
            IF r.typ = 'C' THEN
                v_cv := r.id;
            ELSIF r.typ = 'K' THEN
                SELECT cp_cv_id INTO v_cv FROM contacts WHERE cp_id = r.id;
            END IF;
        END IF;
        -- Sprachnachricht: message_text ist nur der Platzhalter „[Sprachnachricht]“ —
        -- ohne Inhalt schreibt der Dienst die Aufnahme mit
        PERFORM oe_confirmation_enqueue('whatsapp', NEW.id::text, v_cv, NEW.itime, NULL,
            CASE WHEN NEW.message_type = 'audio' THEN NULL
                 ELSE COALESCE(NULLIF(NEW.message_text, ''), NULLIF(NEW.media_caption, '')) END,
            CASE WHEN NEW.message_type = 'audio' THEN NEW.media_url END);
    EXCEPTION WHEN OTHERS THEN
        RAISE WARNING 'oe_confirmation_whatsapp_enqueue: %', SQLERRM;
    END;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Anrufe (callin/callout): der Kunde selbst (typ C) oder eine seiner
-- Ansprechpersonen (typ K). Der Dienst wartet auf die Aufnahme und überspringt
-- nicht angenommene Anrufe.
CREATE OR REPLACE FUNCTION oe_confirmation_call_enqueue() RETURNS trigger AS $$
DECLARE
    v_cv integer;
BEGIN
    IF NEW.crmti_caller_typ NOT IN ('C', 'K') OR oe_confirmation_mode() = 'off' THEN
        RETURN NEW;
    END IF;
    BEGIN
        IF NEW.crmti_caller_typ = 'C' THEN
            v_cv := NEW.crmti_caller_id;
        ELSE
            SELECT cp_cv_id INTO v_cv FROM contacts WHERE cp_id = NEW.crmti_caller_id;
        END IF;
        PERFORM oe_confirmation_enqueue('phone', COALESCE(NULLIF(NEW.unique_call_id, ''), 'crmti:' || NEW.crmti_id),
            v_cv, NEW.crmti_init_time, NULL, NULL, NULL);
    EXCEPTION WHEN OTHERS THEN
        RAISE WARNING 'oe_confirmation_call_enqueue: %', SQLERRM;
    END;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Änderungen an Bestätigungen/Vorschlägen: Auftragsmaske (faktura_change wie
-- bei oe) und Kontakthistorie des Kunden live aktualisieren
CREATE OR REPLACE FUNCTION notify_oe_confirmation() RETURNS trigger AS $$
DECLARE
    v_oe_id integer := COALESCE(NEW.oe_id, OLD.oe_id);
BEGIN
    PERFORM pg_notify('faktura_change', json_build_object('action', TG_OP, 'table', 'oe', 'id', v_oe_id)::TEXT);
    PERFORM contact_history_notify('confirmation', (SELECT ARRAY[customer_id] FROM oe WHERE id = v_oe_id));
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'whatsapp_messages_order_confirm') THEN
        CREATE TRIGGER whatsapp_messages_order_confirm
            AFTER INSERT ON whatsapp_messages
            FOR EACH ROW EXECUTE FUNCTION oe_confirmation_whatsapp_enqueue();
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'crmti_order_confirm') THEN
        CREATE TRIGGER crmti_order_confirm
            AFTER INSERT ON crmti
            FOR EACH ROW EXECUTE FUNCTION oe_confirmation_call_enqueue();
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'oe_confirmation_oserp_notify') THEN
        CREATE TRIGGER oe_confirmation_oserp_notify
            AFTER INSERT OR UPDATE OR DELETE ON oe_confirmation_oserp
            FOR EACH ROW EXECUTE FUNCTION notify_oe_confirmation();
    END IF;
END $$;
