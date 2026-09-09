-- ============================================================================
-- ERWEITERUNG SHOP — Company-Schema
-- ============================================================================
--
-- Herkunft: kivitendo_bridge/sql/install.sql. Die Abweichungen sind in
-- dev/shop-migration.md unter "Stufe 1" begruendet — im Kern: kein DROP TABLE
-- (die Datei laeuft bei jedem Update), customer_ext nur noch als ADD COLUMN,
-- und keine Stammdaten, die bestehende Werte ueberschreiben.
--
-- Die Namen *_hugoshop und die Spalten hugoshop_* bleiben, obwohl die
-- Erweiterung shop heisst: Beispielshop und Entwicklungs-OSERP arbeiten auf
-- derselben Datenbank, ein Umbenennen braeche den laufenden Shop.
--
-- Reihenfolge beachten: der Upstall-Parser verarbeitet erst alle
-- CREATE TABLE (...) getrennt, danach alles Uebrige in Dateireihenfolge.
-- Fremdschluessel zwischen den Tabellen brauchen deshalb die richtige
-- Reihenfolge der CREATE-TABLE-Bloecke.

-- ============================================================================
-- KUNDENERWEITERUNG
-- ============================================================================
--
-- customer_ext gehoert der CRM-Basis. Hier kommen nur die beiden Spalten des
-- Shops dazu — als ADD COLUMN mit Vorgabewert, damit das auch auf einer
-- Datenbank mit vorhandenen Kunden durchlaeuft. Dasselbe Muster wie
-- lxcars/company_schema.sql fuer hu_serienbrief_excluded.

ALTER TABLE customer_ext ADD COLUMN IF NOT EXISTS hugoshop_guest boolean NOT NULL DEFAULT false;
ALTER TABLE customer_ext ADD COLUMN IF NOT EXISTS hugoshop_shipto_id integer;

COMMENT ON COLUMN customer_ext.hugoshop_guest     IS 'Shop: Gastbestellung ohne Kundenkonto';
COMMENT ON COLUMN customer_ext.hugoshop_shipto_id IS 'Shop: gewaehlte Standard-Lieferadresse';

-- SET NULL und nicht CASCADE: hugoshop_shipto_id ist ein Zeiger auf eine
-- Einstellung, keine Besitzbeziehung. Mit CASCADE riss das Loeschen einer
-- Lieferadresse die ganze Kundenzeile mit.
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conrelid = 'customer_ext'::regclass
                      AND conname = 'customer_ext_hugoshop_shipto_id_fkey') THEN
        ALTER TABLE customer_ext
            ADD CONSTRAINT customer_ext_hugoshop_shipto_id_fkey
            FOREIGN KEY (hugoshop_shipto_id) REFERENCES shipto (shipto_id) ON DELETE SET NULL;
    END IF;
END $$;

-- ============================================================================
-- ARTIKELERWEITERUNG
-- ============================================================================
--
-- Die Shop-Angaben zu einem Artikel: Kategorie, Breadcrumbs, Bilder,
-- technische Daten, Zielseite im Shop. Gepflegt wird das im Admin-Panel und
-- von den Batchjobs des Shops.
--
-- Anders als in der Bridge mit Primaerschluessel und eindeutigem Index auf
-- parts_id (siehe DO-Block weiter unten).

CREATE TABLE IF NOT EXISTS parts_ext
(
    id                      integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    parts_id                integer NOT NULL,
    hugoshop_breadcrumbs    jsonb,
    hugoshop_technical_data jsonb,
    hugoshop_properties     jsonb,
    hugoshop_downloads      jsonb,
    hugoshop_images         jsonb,
    hugoshop_hyperlink      text,
    hugoshop_category       text,
    CONSTRAINT parts_ext_parts_id_key UNIQUE (parts_id),
    CONSTRAINT parts_ext_parts_id_fk FOREIGN KEY (parts_id)
        REFERENCES parts (id) ON UPDATE NO ACTION ON DELETE CASCADE
);

COMMENT ON TABLE  parts_ext                         IS 'Shop-Angaben zu einem Artikel';
COMMENT ON COLUMN parts_ext.hugoshop_breadcrumbs    IS 'JSON-Array: Pfad in der Shop-Navigation';
COMMENT ON COLUMN parts_ext.hugoshop_technical_data IS 'JSON-Objekt: technische Daten fuer die Artikelseite';
COMMENT ON COLUMN parts_ext.hugoshop_properties     IS 'JSON-Objekt: Eigenschaften fuer die Artikelseite';
COMMENT ON COLUMN parts_ext.hugoshop_downloads      IS 'JSON-Array: Datenblaetter und Anleitungen';
COMMENT ON COLUMN parts_ext.hugoshop_images         IS 'JSON-Array: Bilddateinamen, erstes Bild ist das Vorschaubild';
COMMENT ON COLUMN parts_ext.hugoshop_hyperlink      IS 'Zielseite im Shop (ohne Basis-URL)';
COMMENT ON COLUMN parts_ext.hugoshop_category       IS 'Kategorie fuer Suche und Auswertung';

-- Auf einer bestehenden Datenbank legt der Upstall nur fehlende SPALTEN an,
-- keine Constraints — der eindeutige Index muss deshalb hier nachgezogen
-- werden. Er fehlte in der Bridge; ohne ihn kann ein Artikel mehrere Zeilen
-- haben, und jeder JOIN parts_ext vervielfacht Warenkorb- und
-- Rechnungspositionen.
--
-- Gibt es bereits Doppeleintraege, wird nur gemeldet statt abgebrochen: das
-- Schema-Update soll an dieser Stelle nicht scheitern, aufraeumen muss ein
-- Mensch.
DO $$
DECLARE
    doppelte integer;
BEGIN
    -- Der Bridge-Fassung fehlte auch der Primaerschluessel.
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conrelid = 'parts_ext'::regclass AND contype = 'p') THEN
        ALTER TABLE parts_ext ADD CONSTRAINT parts_ext_pkey PRIMARY KEY (id);
    END IF;

    IF EXISTS (SELECT 1 FROM pg_constraint
                WHERE conrelid = 'parts_ext'::regclass AND conname = 'parts_ext_parts_id_key') THEN
        RETURN;
    END IF;

    SELECT count(*) INTO doppelte
      FROM (SELECT parts_id FROM parts_ext GROUP BY parts_id HAVING count(*) > 1) AS mehrfach;

    IF doppelte > 0 THEN
        RAISE NOTICE 'parts_ext: % Artikel mit mehreren Zeilen — eindeutiger Index nicht angelegt. Bitte bereinigen.', doppelte;
    ELSE
        ALTER TABLE parts_ext ADD CONSTRAINT parts_ext_parts_id_key UNIQUE (parts_id);
    END IF;
END $$;

-- ============================================================================
-- WARENKORB
-- ============================================================================
--
-- Der Warenkorb haengt an einer UUID, nicht am Kunden: er entsteht, bevor
-- jemand angemeldet ist. Beim Anmelden fuehrt shopLogin() den Gastkorb mit
-- einem vorhandenen Kundenkorb zusammen.

CREATE TABLE IF NOT EXISTS carts_hugoshop
(
    id          integer NOT NULL GENERATED ALWAYS AS IDENTITY,
    uuid        text NOT NULL,
    customer_id integer,
    active      timestamp without time zone DEFAULT now(),
    CONSTRAINT carts_hugoshop_pkey PRIMARY KEY (uuid),
    CONSTRAINT carts_hugoshop_customer_id_fk FOREIGN KEY (customer_id)
        REFERENCES customer (id) ON UPDATE NO ACTION ON DELETE CASCADE
);

COMMENT ON TABLE  carts_hugoshop        IS 'Shop: Warenkorb, auch ohne angemeldeten Kunden';
COMMENT ON COLUMN carts_hugoshop.active IS 'Letzte Benutzung — Grundlage fuer das Aufraeumen';

CREATE TABLE IF NOT EXISTS cart_parts_hugoshop
(
    id        integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    cart_uuid text NOT NULL,
    amount    integer DEFAULT 0,
    parts_id  integer,
    CONSTRAINT cart_parts_hugoshop_cart_parts_key UNIQUE (cart_uuid, parts_id),
    CONSTRAINT cart_parts_hugoshop_carts_uuid_fk FOREIGN KEY (cart_uuid)
        REFERENCES carts_hugoshop (uuid) ON UPDATE NO ACTION ON DELETE CASCADE
);

COMMENT ON TABLE cart_parts_hugoshop IS 'Shop: Positionen eines Warenkorbs';

CREATE INDEX IF NOT EXISTS cart_parts_hugoshop_cart_uuid_idx
    ON cart_parts_hugoshop (cart_uuid);

-- Ein Artikel steht im Warenkorb genau einmal, mit einer Menge. Die Bridge
-- setzte das voraus (inCart sucht die Position und zaehlt sie hoch), ohne es
-- zu sichern — zwei gleichzeitige Anfragen legten zwei Zeilen an.
--
-- Mit dem eindeutigen Index wird aus Suchen-und-Entscheiden ein einziges
-- INSERT ... ON CONFLICT DO UPDATE, und das Zusammenfuehren zweier Warenkoerbe
-- beim Anmelden ebenso.
--
-- Vorhandene Doppeleintraege werden vorher zusammengefasst statt gemeldet:
-- anders als bei parts_ext gibt es hier eine eindeutig richtige Auflösung —
-- die Mengen gehoeren addiert.
DO $$
DECLARE
    zusammengefasst integer;
BEGIN
    IF EXISTS (SELECT 1 FROM pg_constraint
                WHERE conrelid = 'cart_parts_hugoshop'::regclass
                  AND conname = 'cart_parts_hugoshop_cart_parts_key') THEN
        RETURN;
    END IF;

    WITH summen AS (
        SELECT cart_uuid, parts_id, SUM(amount) AS menge, MIN(id) AS behalten
          FROM cart_parts_hugoshop
         GROUP BY cart_uuid, parts_id
        HAVING count(*) > 1
    ), aktualisiert AS (
        UPDATE cart_parts_hugoshop c SET amount = s.menge
          FROM summen s WHERE c.id = s.behalten
        RETURNING c.id
    ), geloescht AS (
        DELETE FROM cart_parts_hugoshop c
         USING summen s
         WHERE c.cart_uuid = s.cart_uuid AND c.parts_id = s.parts_id AND c.id <> s.behalten
        RETURNING c.id
    )
    SELECT count(*) INTO zusammengefasst FROM geloescht;

    IF zusammengefasst > 0 THEN
        RAISE NOTICE 'cart_parts_hugoshop: % doppelte Positionen zusammengefasst.', zusammengefasst;
    END IF;

    ALTER TABLE cart_parts_hugoshop
        ADD CONSTRAINT cart_parts_hugoshop_cart_parts_key UNIQUE (cart_uuid, parts_id);
END $$;

-- ============================================================================
-- SITZUNGSKONTEXT
-- ============================================================================
--
-- Die Sitzung des Shop-Kunden. Voellig getrennt von auth.session_oserp: eine
-- UUID aus dem Cookie HUGOSHOPCLIENTID, ein Warenkorb, ein Kunde — kein
-- Benutzer, keine Rechte. Wer hier steht, ist Kunde des Betreibers und hat
-- mit dem Admin-Panel nichts zu tun.

CREATE TABLE IF NOT EXISTS context_hugoshop
(
    id          integer NOT NULL GENERATED ALWAYS AS IDENTITY,
    uuid        text NOT NULL,
    cart_uuid   text,
    customer_id integer,
    active      timestamp without time zone DEFAULT now(),
    CONSTRAINT context_hugoshop_pkey PRIMARY KEY (uuid),
    CONSTRAINT context_hugoshop_carts_uuid_fk FOREIGN KEY (cart_uuid)
        REFERENCES carts_hugoshop (uuid) ON UPDATE NO ACTION ON DELETE CASCADE,
    CONSTRAINT context_hugoshop_customer_id_fk FOREIGN KEY (customer_id)
        REFERENCES customer (id) ON UPDATE NO ACTION ON DELETE CASCADE
);

COMMENT ON TABLE  context_hugoshop      IS 'Shop: Sitzung eines Shop-Besuchers (Cookie HUGOSHOPCLIENTID)';
COMMENT ON COLUMN context_hugoshop.uuid IS 'Wert des Cookies — vom Aufrufer frei waehlbar, gehoert immer gebunden';

-- ============================================================================
-- RECHNUNGSVERKNUEPFUNG UND ZAHLUNGSSTAND
-- ============================================================================
--
-- Der Kunde bekommt nach dem Kauf einen Link mit dieser UUID; darueber sieht
-- er seine Rechnung, ohne angemeldet zu sein. Die UUID ist damit das
-- Geheimnis und ersetzt die Rechteprüfung.
--
-- paypal ist die Payer-Id und steht, sobald eine Buchung vorliegt — auch eine
-- schwebende. Ob bezahlt wurde, sagt payment_status: COMPLETED (Geld da),
-- PENDING (unterwegs, z.B. Lastschrift), DECLINED/FAILED (gescheitert).

CREATE TABLE IF NOT EXISTS ar_link_hugoshop
(
    id                integer NOT NULL GENERATED ALWAYS AS IDENTITY,
    uuid              text NOT NULL,
    ar_id             integer,
    active            timestamp without time zone DEFAULT now(),
    paypal            text,
    paypal_order_id   text,
    paypal_capture_id text,
    payment_status    text,
    payment_reason    text,
    payment_mtime     timestamp without time zone,
    CONSTRAINT ar_link_hugoshop_pkey PRIMARY KEY (uuid),
    CONSTRAINT ar_link_hugoshop_ar_id_fkey FOREIGN KEY (ar_id)
        REFERENCES ar (id) ON UPDATE CASCADE ON DELETE CASCADE
);

COMMENT ON TABLE  ar_link_hugoshop                IS 'Shop: Verknuepfung Rechnung <-> Shop-Bestellung samt Zahlungsstand';
COMMENT ON COLUMN ar_link_hugoshop.uuid           IS 'Geheimnis im Rechnungslink — ersetzt die Anmeldung';
COMMENT ON COLUMN ar_link_hugoshop.payment_status IS 'COMPLETED, PENDING, DECLINED, FAILED — von PayPal gemeldet, nicht gebucht';

-- Der Abgleich schwebender Zahlungen fragt genau diese Zeilen ab.
CREATE INDEX IF NOT EXISTS ar_link_hugoshop_payment_offen_idx
    ON ar_link_hugoshop (payment_mtime)
 WHERE payment_status = 'PENDING';

-- ============================================================================
-- BATCHJOBS UND WEITERLEITUNGEN
-- ============================================================================
--
-- Die Warteschlange wird im Admin-Panel gefuellt; abgearbeitet wird sie
-- weiterhin auf dem Shop-Server, weil dort Hugo laeuft.
--
-- In der Bridge stand vor beiden Tabellen ein DROP TABLE. Das ist entfallen:
-- diese Datei laeuft bei jedem Schema-Update, und ein DROP haette jedes Mal
-- die Weiterleitungen und die Warteschlange gekostet.

CREATE TABLE IF NOT EXISTS batchjob_hugoshop
(
    id         integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    function   text NOT NULL,
    partnumber text NOT NULL,
    param      text DEFAULT NULL,
    result     text DEFAULT NULL
);

COMMENT ON TABLE batchjob_hugoshop IS 'Shop: Warteschlange fuer Aufgaben, die auf dem Shop-Server laufen';

CREATE TABLE IF NOT EXISTS redirect_pages_hugoshop
(
    id            integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code          integer NOT NULL,
    previous_link text NOT NULL,
    current_link  text NOT NULL,
    link_text     text DEFAULT NULL
);

COMMENT ON TABLE  redirect_pages_hugoshop      IS 'Shop: Weiterleitungen fuer entfallene Seiten';
COMMENT ON COLUMN redirect_pages_hugoshop.code IS 'HTTP-Status der Weiterleitung (301, 302, ...)';

-- ============================================================================
-- WIDERRUF
-- ============================================================================
--
-- § 356a BGB. Bisher schrieb die Bridge eine JSON-Zeile in eine Logdatei
-- (shop.widerruf.php). Ein Vorgang, der nachweisbar sein muss, gehoert in die
-- Datenbank — sonst haengt der Nachweis daran, dass niemand die Datei anfasst.
--
-- ordernumber ist die Angabe des Kunden und wird nicht geprueft: der Widerruf
-- ist auch mit falscher oder fehlender Nummer wirksam.

CREATE TABLE IF NOT EXISTS withdrawals_hugoshop
(
    id          integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    itime       timestamp without time zone DEFAULT now(),
    name        text,
    ordernumber text,
    email       text,
    reason      text,
    customer_id integer,
    ar_id       integer,
    remote_addr text,
    user_agent  text,
    processed   timestamp without time zone,
    CONSTRAINT withdrawals_hugoshop_customer_id_fk FOREIGN KEY (customer_id)
        REFERENCES customer (id) ON UPDATE NO ACTION ON DELETE SET NULL,
    CONSTRAINT withdrawals_hugoshop_ar_id_fk FOREIGN KEY (ar_id)
        REFERENCES ar (id) ON UPDATE NO ACTION ON DELETE SET NULL
);

COMMENT ON TABLE  withdrawals_hugoshop             IS 'Shop: eingegangene Widerrufe (§ 356a BGB)';
COMMENT ON COLUMN withdrawals_hugoshop.ordernumber IS 'Bestellnummer laut Kunde — ungeprueft, der Widerruf gilt auch ohne';
COMMENT ON COLUMN withdrawals_hugoshop.processed   IS 'Zeitpunkt der Bearbeitung im Admin-Panel, NULL = offen';

CREATE INDEX IF NOT EXISTS withdrawals_hugoshop_offen_idx
    ON withdrawals_hugoshop (itime)
 WHERE processed IS NULL;

-- ============================================================================
-- AUFRAEUMEN
-- ============================================================================
--
-- Warenkoerbe und Sitzungen verfallen. Beides haengt an Statement-Triggern auf
-- context_hugoshop: jeder Zugriff eines Besuchers raeumt ein wenig mit auf, es
-- braucht also keinen Cronjob.
--
-- Die Fristen kommen aus defaults_oserp statt fest aus dem Rumpf. Ein Korb mit
-- angemeldetem Kunden bleibt stehen — der Kunde findet ihn beim naechsten
-- Besuch wieder.

CREATE OR REPLACE FUNCTION cleanup_carts_hugoshop() RETURNS trigger AS $$
BEGIN
    DELETE FROM carts_hugoshop
     WHERE active < NOW() - (COALESCE(
               (SELECT value FROM defaults_oserp WHERE key = 'shop_cart_lifetime_hours'),
               '24') || ' hours')::interval
       AND NOT EXISTS (
           SELECT 1 FROM context_hugoshop
            WHERE cart_uuid = carts_hugoshop.uuid AND customer_id IS NOT NULL
       );
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION cleanup_context_hugoshop() RETURNS trigger AS $$
BEGIN
    DELETE FROM context_hugoshop
     WHERE active < NOW() - (COALESCE(
               (SELECT value FROM defaults_oserp WHERE key = 'shop_context_lifetime_hours'),
               '24') || ' hours')::interval;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- CREATE OR REPLACE TRIGGER gaebe es erst ab PostgreSQL 14. Das DO-Muster mit
-- Existenzpruefung ist dasselbe wie in lxcars und laeuft ueberall.
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trigger_cleanup_carts_hugoshop') THEN
        CREATE TRIGGER trigger_cleanup_carts_hugoshop
            AFTER INSERT OR UPDATE ON context_hugoshop
            FOR EACH STATEMENT EXECUTE FUNCTION cleanup_carts_hugoshop();
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trigger_cleanup_context_hugoshop') THEN
        CREATE TRIGGER trigger_cleanup_context_hugoshop
            AFTER INSERT OR UPDATE ON context_hugoshop
            FOR EACH STATEMENT EXECUTE FUNCTION cleanup_context_hugoshop();
    END IF;
END $$;

-- ============================================================================
-- EINSTELLUNGEN
-- ============================================================================
--
-- Ersetzt config.php und passwd.php der Bridge. Nur anlegen, nie ueberschreiben
-- — ON CONFLICT DO NOTHING, damit ein Schema-Update keine gepflegten Werte
-- zuruecksetzt.
--
-- shop_public_key und die PayPal-Zugangsdaten bleiben leer: sie gehoeren in das
-- Admin-Panel, nicht in eine Datei im Repository. Ohne shop_public_key nimmt
-- der oeffentliche Einstiegspunkt keine Anfrage an.

-- Zugang
INSERT INTO defaults_oserp (key, value) VALUES ('shop_public_key', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_allowed_origins', '') ON CONFLICT (key) DO NOTHING;

-- Sitzung
INSERT INTO defaults_oserp (key, value) VALUES ('shop_cart_lifetime_hours', '24') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_context_lifetime_hours', '24') ON CONFLICT (key) DO NOTHING;

-- Rechnungsstellung
INSERT INTO defaults_oserp (key, value) VALUES ('shop_contact_login', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_target_account', 'Lieferungen und Leistungen') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_incoming_account', 'Bank') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_standard_taxzone', 'Inland') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_standard_currency', 'EUR') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_tax_included', '0') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_active_price_source', 'master_data/sellprice') ON CONFLICT (key) DO NOTHING;

-- Versand
INSERT INTO defaults_oserp (key, value) VALUES ('shop_shipping_partnumber', '8') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_free_shipping_from', '150') ON CONFLICT (key) DO NOTHING;

-- Bankverbindung fuer die Zahlungsaufforderung auf der Rechnungsseite
INSERT INTO defaults_oserp (key, value) VALUES ('shop_payment_account_owner', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_payment_bank', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_payment_iban', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_payment_bic', '') ON CONFLICT (key) DO NOTHING;

-- PayPal. Sandbox bleibt an, bis jemand bewusst umschaltet.
--
-- Zwei Zugangsdatenpaare, wie es die Bridge in ihrer passwd.php ebenfalls
-- hielt: PayPal vergibt fuer Test- und Echtbetrieb getrennte Kennungen. Wer
-- nur eines vorhaelt, muesste sie beim Umschalten jedesmal austauschen — und
-- beim Zurueckschalten wieder. Welches Paar gilt, entscheidet
-- shop_paypal_sandbox.
INSERT INTO defaults_oserp (key, value) VALUES ('shop_paypal_sandbox', '1') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_paypal_live_client_id', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_paypal_live_secret', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_paypal_sandbox_client_id', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_paypal_sandbox_secret', '') ON CONFLICT (key) DO NOTHING;

-- Uebergang: die erste Fassung dieser Erweiterung kannte nur ein Paar. Wo es
-- gefuellt ist, wandert es in die Echtbetrieb-Schluessel und der Altbestand
-- verschwindet. Laeuft auch dann durch, wenn es die alten Zeilen nie gab.
UPDATE defaults_oserp z SET value = a.value, mtime = now()
  FROM defaults_oserp a
 WHERE a.key = 'shop_paypal_client_id' AND COALESCE(a.value, '') <> ''
   AND z.key = 'shop_paypal_live_client_id' AND COALESCE(z.value, '') = '';
UPDATE defaults_oserp z SET value = a.value, mtime = now()
  FROM defaults_oserp a
 WHERE a.key = 'shop_paypal_secret' AND COALESCE(a.value, '') <> ''
   AND z.key = 'shop_paypal_live_secret' AND COALESCE(z.value, '') = '';
DELETE FROM defaults_oserp WHERE key IN ('shop_paypal_client_id', 'shop_paypal_secret');
INSERT INTO defaults_oserp (key, value) VALUES ('shop_paypal_payment_method_preference', 'IMMEDIATE_PAYMENT_REQUIRED') ON CONFLICT (key) DO NOTHING;
-- Fehlertest: erzwingt eine bestimmte Fehlerantwort von PayPal, statt den
-- Aufruf auszufuehren. Wirkt nur in der Testumgebung; siehe paypalMockHeader().
INSERT INTO defaults_oserp (key, value) VALUES ('shop_paypal_mock_response', '') ON CONFLICT (key) DO NOTHING;

-- Adressen der Shop-Webseite (fuer Links in Suche, Mails und Auswertung)
INSERT INTO defaults_oserp (key, value) VALUES ('shop_base_url', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_products_link', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_category_link', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_thumbnails_link', '') ON CONFLICT (key) DO NOTHING;

-- Suche: Gewichtung des Preises im Ranking
INSERT INTO defaults_oserp (key, value) VALUES ('shop_search_weighting', '0.5') ON CONFLICT (key) DO NOTHING;

-- Mail
INSERT INTO defaults_oserp (key, value) VALUES ('shop_invoice_mail_subject', 'Ihre Rechnung (%s) vom %s') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_withdrawal_mail_to', '') ON CONFLICT (key) DO NOTHING;

-- ============================================================================
-- VERSANDARTIKEL
-- ============================================================================
--
-- Wird bewusst NICHT angelegt. Die Bridge tat das (mit ON CONFLICT DO UPDATE,
-- was bei jedem Schema-Update den gepflegten Preis zurueckgesetzt haette), und
-- auch ein blosses Anlegen ist heikel: parts traegt je nach kivitendo-Stand
-- unterschiedliche Pflichtfelder und Fremdschluessel — nachgemessen ist der
-- Lauf an part_classification_id_fkey gescheitert. Ein Schema-Update darf
-- daran nicht scheitern.
--
-- Sachlich gehoert der Artikel ohnehin dem Betreiber: Preis, Buchungsgruppe
-- und damit der Steuersatz sind seine Entscheidung. Fehlt er, meldet
-- getShopStatus() das als blockierenden Punkt, und das Admin-Panel zeigt es
-- vor der ersten Bestellung an.
--
-- Anzulegen ist ein gewoehnlicher Artikel mit der Nummer aus der Einstellung
-- shop_shipping_partnumber (Vorgabe: 8).
