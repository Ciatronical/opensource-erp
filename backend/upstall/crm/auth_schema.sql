-- auth_schema.sql
-- Tabellen für das auth-Schema

-- ============================================================================
-- SESSION MANAGEMENT
-- ============================================================================

CREATE TABLE auth.session_oserp (
    id integer NOT NULL GENERATED ALWAYS AS IDENTITY,
    session_id text PRIMARY KEY,
    user_id integer NOT NULL,
    client_id integer NOT NULL,
    active timestamp without time zone DEFAULT now(),
    remember_me boolean NOT NULL DEFAULT false
);

COMMENT ON TABLE auth.session_oserp IS 'Sitzungsverwaltung für OpensourceERP';
COMMENT ON COLUMN auth.session_oserp.id IS 'Primärschlüssel (automatisch generiert)';
COMMENT ON COLUMN auth.session_oserp.session_id IS 'Eindeutige Session-ID';
COMMENT ON COLUMN auth.session_oserp.user_id IS 'Referenz zum Benutzer';
COMMENT ON COLUMN auth.session_oserp.client_id IS 'Referenz zum Mandanten';
COMMENT ON COLUMN auth.session_oserp.active IS 'Letzter Aktivitätszeitpunkt der Session';
COMMENT ON COLUMN auth.session_oserp.remember_me IS '"Angemeldet bleiben": Cookie überlebt Browser-Close, Session lebt bis 120h Inaktivität';

-- ============================================================================
-- SESSION CLEANUP FUNCTION & TRIGGER
-- ============================================================================

CREATE OR REPLACE FUNCTION auth.cleanup_session_oserp()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    DELETE FROM auth.session_oserp
    WHERE active < NOW() - INTERVAL '120 hours';
    RETURN NEW;
END;
$$;

COMMENT ON FUNCTION auth.cleanup_session_oserp() IS 'Löscht automatisch Sessions die länger als 120 Stunden inaktiv sind';

CREATE OR REPLACE TRIGGER trigger_cleanup_session_oserp
AFTER INSERT OR UPDATE
ON auth.session_oserp
FOR EACH STATEMENT
EXECUTE FUNCTION auth.cleanup_session_oserp();

COMMENT ON TRIGGER trigger_cleanup_session_oserp ON auth.session_oserp IS 'Trigger für automatische Session-Bereinigung';
