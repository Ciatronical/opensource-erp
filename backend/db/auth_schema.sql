--
-- PostgreSQL database dump
--

\restrict jAmsMEAKr6S6TgKpBldL106PqUyjHaa97q9DEjVZE3YscWy88bnrvejgT2RDGiW

-- Dumped from database version 14.22 (Ubuntu 14.22-0ubuntu0.22.04.1)
-- Dumped by pg_dump version 14.22 (Ubuntu 14.22-0ubuntu0.22.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: auth; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA auth;


ALTER SCHEMA auth OWNER TO postgres;

--
-- Name: cleanup_session_oserp(); Type: FUNCTION; Schema: auth; Owner: postgres
--

CREATE FUNCTION auth.cleanup_session_oserp() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    DELETE FROM auth.session_oserp
    WHERE active < NOW() - INTERVAL '24 hours';
    RETURN NEW;
END;
$$;


ALTER FUNCTION auth.cleanup_session_oserp() OWNER TO postgres;

--
-- Name: FUNCTION cleanup_session_oserp(); Type: COMMENT; Schema: auth; Owner: postgres
--

COMMENT ON FUNCTION auth.cleanup_session_oserp() IS 'Löscht automatisch Sessions die älter als 24 Stunden sind';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: clients; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.clients (
    id integer NOT NULL,
    name text NOT NULL,
    dbhost text NOT NULL,
    dbport integer DEFAULT 5432 NOT NULL,
    dbname text NOT NULL,
    dbuser text NOT NULL,
    dbpasswd text NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    task_server_user_id integer
);


ALTER TABLE auth.clients OWNER TO postgres;

--
-- Name: clients_groups; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.clients_groups (
    client_id integer NOT NULL,
    group_id integer NOT NULL
);


ALTER TABLE auth.clients_groups OWNER TO postgres;

--
-- Name: clients_id_seq; Type: SEQUENCE; Schema: auth; Owner: postgres
--

CREATE SEQUENCE auth.clients_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE auth.clients_id_seq OWNER TO postgres;

--
-- Name: clients_id_seq; Type: SEQUENCE OWNED BY; Schema: auth; Owner: postgres
--

ALTER SEQUENCE auth.clients_id_seq OWNED BY auth.clients.id;


--
-- Name: clients_users; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.clients_users (
    client_id integer NOT NULL,
    user_id integer NOT NULL
);


ALTER TABLE auth.clients_users OWNER TO postgres;

--
-- Name: group_id_seq; Type: SEQUENCE; Schema: auth; Owner: postgres
--

CREATE SEQUENCE auth.group_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE auth.group_id_seq OWNER TO postgres;

--
-- Name: group; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth."group" (
    id integer DEFAULT nextval('auth.group_id_seq'::regclass) NOT NULL,
    name text NOT NULL,
    description text
);


ALTER TABLE auth."group" OWNER TO postgres;

--
-- Name: group_rights; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.group_rights (
    group_id integer NOT NULL,
    "right" text NOT NULL,
    granted boolean NOT NULL
);


ALTER TABLE auth.group_rights OWNER TO postgres;

--
-- Name: master_rights; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.master_rights (
    id integer NOT NULL,
    "position" integer NOT NULL,
    name text NOT NULL,
    description text NOT NULL,
    category boolean DEFAULT false NOT NULL
);


ALTER TABLE auth.master_rights OWNER TO postgres;

--
-- Name: master_rights_id_seq; Type: SEQUENCE; Schema: auth; Owner: postgres
--

CREATE SEQUENCE auth.master_rights_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE auth.master_rights_id_seq OWNER TO postgres;

--
-- Name: master_rights_id_seq; Type: SEQUENCE OWNED BY; Schema: auth; Owner: postgres
--

ALTER SEQUENCE auth.master_rights_id_seq OWNED BY auth.master_rights.id;


--
-- Name: schema_info; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.schema_info (
    tag text NOT NULL,
    login text,
    itime timestamp without time zone DEFAULT now()
);


ALTER TABLE auth.schema_info OWNER TO postgres;

--
-- Name: session; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.session (
    id text NOT NULL,
    ip_address inet,
    mtime timestamp without time zone,
    api_token text
);


ALTER TABLE auth.session OWNER TO postgres;

--
-- Name: session_content; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.session_content (
    session_id text NOT NULL,
    sess_key text NOT NULL,
    sess_value text,
    auto_restore boolean
);


ALTER TABLE auth.session_content OWNER TO postgres;

--
-- Name: session_oserp; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.session_oserp (
    id integer NOT NULL,
    session_id text NOT NULL,
    user_id integer NOT NULL,
    client_id integer NOT NULL,
    active timestamp without time zone DEFAULT now()
);


ALTER TABLE auth.session_oserp OWNER TO postgres;

--
-- Name: TABLE session_oserp; Type: COMMENT; Schema: auth; Owner: postgres
--

COMMENT ON TABLE auth.session_oserp IS 'Sitzungsverwaltung für OpensourceERP';


--
-- Name: COLUMN session_oserp.id; Type: COMMENT; Schema: auth; Owner: postgres
--

COMMENT ON COLUMN auth.session_oserp.id IS 'Primärschlüssel (automatisch generiert)';


--
-- Name: COLUMN session_oserp.session_id; Type: COMMENT; Schema: auth; Owner: postgres
--

COMMENT ON COLUMN auth.session_oserp.session_id IS 'Eindeutige Session-ID';


--
-- Name: COLUMN session_oserp.user_id; Type: COMMENT; Schema: auth; Owner: postgres
--

COMMENT ON COLUMN auth.session_oserp.user_id IS 'Referenz zum Benutzer';


--
-- Name: COLUMN session_oserp.client_id; Type: COMMENT; Schema: auth; Owner: postgres
--

COMMENT ON COLUMN auth.session_oserp.client_id IS 'Referenz zum Mandanten';


--
-- Name: COLUMN session_oserp.active; Type: COMMENT; Schema: auth; Owner: postgres
--

COMMENT ON COLUMN auth.session_oserp.active IS 'Letzter Aktivitätszeitpunkt der Session';


--
-- Name: session_oserp_id_seq; Type: SEQUENCE; Schema: auth; Owner: postgres
--

ALTER TABLE auth.session_oserp ALTER COLUMN id ADD GENERATED ALWAYS AS IDENTITY (
    SEQUENCE NAME auth.session_oserp_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1
);


--
-- Name: user_id_seq; Type: SEQUENCE; Schema: auth; Owner: postgres
--

CREATE SEQUENCE auth.user_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER TABLE auth.user_id_seq OWNER TO postgres;

--
-- Name: user; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth."user" (
    id integer DEFAULT nextval('auth.user_id_seq'::regclass) NOT NULL,
    login text NOT NULL,
    password text
);


ALTER TABLE auth."user" OWNER TO postgres;

--
-- Name: user_config; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.user_config (
    user_id integer NOT NULL,
    cfg_key text NOT NULL,
    cfg_value text
);


ALTER TABLE auth.user_config OWNER TO postgres;

--
-- Name: user_group; Type: TABLE; Schema: auth; Owner: postgres
--

CREATE TABLE auth.user_group (
    user_id integer NOT NULL,
    group_id integer NOT NULL
);


ALTER TABLE auth.user_group OWNER TO postgres;

--
-- Name: clients id; Type: DEFAULT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.clients ALTER COLUMN id SET DEFAULT nextval('auth.clients_id_seq'::regclass);


--
-- Name: master_rights id; Type: DEFAULT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.master_rights ALTER COLUMN id SET DEFAULT nextval('auth.master_rights_id_seq'::regclass);


--
-- Name: clients clients_dbhost_dbport_dbname_key; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.clients
    ADD CONSTRAINT clients_dbhost_dbport_dbname_key UNIQUE (dbhost, dbport, dbname);


--
-- Name: clients_groups clients_groups_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.clients_groups
    ADD CONSTRAINT clients_groups_pkey PRIMARY KEY (client_id, group_id);


--
-- Name: clients clients_name_key; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.clients
    ADD CONSTRAINT clients_name_key UNIQUE (name);


--
-- Name: clients clients_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.clients
    ADD CONSTRAINT clients_pkey PRIMARY KEY (id);


--
-- Name: clients_users clients_users_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.clients_users
    ADD CONSTRAINT clients_users_pkey PRIMARY KEY (client_id, user_id);


--
-- Name: group group_name_key; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth."group"
    ADD CONSTRAINT group_name_key UNIQUE (name);


--
-- Name: group group_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth."group"
    ADD CONSTRAINT group_pkey PRIMARY KEY (id);


--
-- Name: group_rights group_rights_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.group_rights
    ADD CONSTRAINT group_rights_pkey PRIMARY KEY (group_id, "right");


--
-- Name: master_rights master_rights_name_key; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.master_rights
    ADD CONSTRAINT master_rights_name_key UNIQUE (name);


--
-- Name: master_rights master_rights_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.master_rights
    ADD CONSTRAINT master_rights_pkey PRIMARY KEY (id);


--
-- Name: schema_info schema_info_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.schema_info
    ADD CONSTRAINT schema_info_pkey PRIMARY KEY (tag);


--
-- Name: session_content session_content_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.session_content
    ADD CONSTRAINT session_content_pkey PRIMARY KEY (session_id, sess_key);


--
-- Name: session_oserp session_oserp_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.session_oserp
    ADD CONSTRAINT session_oserp_pkey PRIMARY KEY (session_id);


--
-- Name: session session_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.session
    ADD CONSTRAINT session_pkey PRIMARY KEY (id);


--
-- Name: user_config user_config_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_config
    ADD CONSTRAINT user_config_pkey PRIMARY KEY (user_id, cfg_key);


--
-- Name: user_group user_group_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_group
    ADD CONSTRAINT user_group_pkey PRIMARY KEY (user_id, group_id);


--
-- Name: user user_login_key; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth."user"
    ADD CONSTRAINT user_login_key UNIQUE (login);


--
-- Name: user user_pkey; Type: CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth."user"
    ADD CONSTRAINT user_pkey PRIMARY KEY (id);


--
-- Name: session_oserp trigger_cleanup_session_oserp; Type: TRIGGER; Schema: auth; Owner: postgres
--

CREATE TRIGGER trigger_cleanup_session_oserp AFTER INSERT OR UPDATE ON auth.session_oserp FOR EACH STATEMENT EXECUTE FUNCTION auth.cleanup_session_oserp();


--
-- Name: TRIGGER trigger_cleanup_session_oserp ON session_oserp; Type: COMMENT; Schema: auth; Owner: postgres
--

COMMENT ON TRIGGER trigger_cleanup_session_oserp ON auth.session_oserp IS 'Trigger für automatische Session-Bereinigung';


--
-- Name: clients_groups clients_groups_client_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.clients_groups
    ADD CONSTRAINT clients_groups_client_id_fkey FOREIGN KEY (client_id) REFERENCES auth.clients(id) ON DELETE CASCADE;


--
-- Name: clients_groups clients_groups_group_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.clients_groups
    ADD CONSTRAINT clients_groups_group_id_fkey FOREIGN KEY (group_id) REFERENCES auth."group"(id) ON DELETE CASCADE;


--
-- Name: clients clients_task_server_user_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.clients
    ADD CONSTRAINT clients_task_server_user_id_fkey FOREIGN KEY (task_server_user_id) REFERENCES auth."user"(id);


--
-- Name: clients_users clients_users_client_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.clients_users
    ADD CONSTRAINT clients_users_client_id_fkey FOREIGN KEY (client_id) REFERENCES auth.clients(id) ON DELETE CASCADE;


--
-- Name: clients_users clients_users_user_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.clients_users
    ADD CONSTRAINT clients_users_user_id_fkey FOREIGN KEY (user_id) REFERENCES auth."user"(id) ON DELETE CASCADE;


--
-- Name: group_rights group_rights_group_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.group_rights
    ADD CONSTRAINT group_rights_group_id_fkey FOREIGN KEY (group_id) REFERENCES auth."group"(id) ON DELETE CASCADE;


--
-- Name: session_content session_content_session_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.session_content
    ADD CONSTRAINT session_content_session_id_fkey FOREIGN KEY (session_id) REFERENCES auth.session(id) ON DELETE CASCADE;


--
-- Name: user_config user_config_user_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_config
    ADD CONSTRAINT user_config_user_id_fkey FOREIGN KEY (user_id) REFERENCES auth."user"(id) ON DELETE CASCADE;


--
-- Name: user_group user_group_group_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_group
    ADD CONSTRAINT user_group_group_id_fkey FOREIGN KEY (group_id) REFERENCES auth."group"(id) ON DELETE CASCADE;


--
-- Name: user_group user_group_user_id_fkey; Type: FK CONSTRAINT; Schema: auth; Owner: postgres
--

ALTER TABLE ONLY auth.user_group
    ADD CONSTRAINT user_group_user_id_fkey FOREIGN KEY (user_id) REFERENCES auth."user"(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict jAmsMEAKr6S6TgKpBldL106PqUyjHaa97q9DEjVZE3YscWy88bnrvejgT2RDGiW

