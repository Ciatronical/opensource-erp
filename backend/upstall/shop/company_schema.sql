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
COMMENT ON COLUMN parts_ext.hugoshop_downloads      IS 'JSON-Objekt: Anzeigename -> Dateiname (Datenblaetter, Anleitungen)';
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
-- VERKAUFSKANÄLE
-- ============================================================================
--
-- dev/shop-verkaufskanaele.md. Ein Artikel wird über einen oder mehrere
-- Kanäle angeboten: HugoShop, später eBay und Amazon. Je Mandant höchstens
-- ein Kanal je Art (V3), deshalb der eindeutige Index auf type.
--
-- Die kivitendo-Tabellen shops/shop_parts bleiben unberührt (V1): sie gehören
-- zu kivitendos Shopware- und WooCommerce-Anbindung.
--
-- Der Upstall-Parser liest einen Tabellenrumpf nur bis zum ersten
-- Klammer-Semikolon und legt die Tabellen in Dateireihenfolge an —
-- sales_channel_shop muss vor parts_channel_shop stehen.

CREATE TABLE IF NOT EXISTS sales_channel_shop
(
    id           integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    type         text NOT NULL,
    active       boolean NOT NULL DEFAULT true,
    sortkey      integer,
    markup_type  text NOT NULL DEFAULT 'none',
    markup_value numeric(15,5) NOT NULL DEFAULT 0,
    round_99     boolean NOT NULL DEFAULT false,
    settings     jsonb,
    itime        timestamp without time zone DEFAULT now(),
    mtime        timestamp without time zone,
    CONSTRAINT sales_channel_shop_type_key UNIQUE (type),
    CONSTRAINT sales_channel_shop_type_check CHECK (type IN ('hugoshop', 'ebay', 'amazon')),
    CONSTRAINT sales_channel_shop_markup_check CHECK (markup_type IN ('none', 'percent', 'amount'))
);

COMMENT ON TABLE  sales_channel_shop              IS 'Shop: Verkaufskanal, höchstens einer je Art';
COMMENT ON COLUMN sales_channel_shop.type         IS 'hugoshop, ebay oder amazon';
COMMENT ON COLUMN sales_channel_shop.markup_type  IS 'Vorgabe für alle Artikel des Kanals: none, percent (Prozent) oder amount (fester Betrag)';
COMMENT ON COLUMN sales_channel_shop.markup_value IS 'Aufschlag in Prozent oder als Betrag — netto oder brutto wie parts.sellprice laut shop_tax_included';
COMMENT ON COLUMN sales_channel_shop.round_99     IS 'Bruttopreis auf die nächste ,99 aufrunden';
COMMENT ON COLUMN sales_channel_shop.settings     IS 'Kanaleigene Einstellungen, ohne Geheimnisse — geht an die Oberfläche';

CREATE TABLE IF NOT EXISTS parts_channel_shop
(
    id           integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    parts_id     integer NOT NULL,
    channel_id   integer NOT NULL,
    active       boolean NOT NULL DEFAULT true,
    markup_type  text,
    markup_value numeric(15,5),
    title        text,
    description  text,
    settings     jsonb,
    external_id  text,
    sync_status  text,
    sync_mtime   timestamp without time zone,
    sync_error   text,
    sync_data    jsonb,
    itime        timestamp without time zone DEFAULT now(),
    mtime        timestamp without time zone,
    CONSTRAINT parts_channel_shop_key UNIQUE (parts_id, channel_id),
    CONSTRAINT parts_channel_shop_markup_check CHECK (markup_type IN ('none', 'percent', 'amount')),
    CONSTRAINT parts_channel_shop_parts_id_fk FOREIGN KEY (parts_id)
        REFERENCES parts (id) ON UPDATE NO ACTION ON DELETE CASCADE,
    CONSTRAINT parts_channel_shop_channel_id_fk FOREIGN KEY (channel_id)
        REFERENCES sales_channel_shop (id) ON UPDATE NO ACTION ON DELETE CASCADE
);

COMMENT ON TABLE  parts_channel_shop              IS 'Shop: Artikel je Verkaufskanal';
COMMENT ON COLUMN parts_channel_shop.active       IS 'Artikel wird im Kanal angeboten. Abwählen setzt false statt zu löschen (V5)';
COMMENT ON COLUMN parts_channel_shop.markup_type  IS 'NULL = Vorgabe des Kanals, none = ausdrücklich ohne Aufschlag, percent, amount';
COMMENT ON COLUMN parts_channel_shop.title        IS 'Bezeichnung im Kanal, NULL = parts.description';
COMMENT ON COLUMN parts_channel_shop.description  IS 'Beschreibung im Kanal, NULL = parts.notes';
COMMENT ON COLUMN parts_channel_shop.external_id  IS 'Kennung beim Kanal (eBay-Angebot, Amazon-SKU/ASIN)';
COMMENT ON COLUMN parts_channel_shop.settings     IS 'Kanaleigene Angaben des Benutzers (eBay: category_id, condition)';
COMMENT ON COLUMN parts_channel_shop.sync_data    IS 'Angaben, die der Kanal beim Abgleich selbst schreibt (eBay: offer_id) — getrennt von settings, damit das Speichern der Artikelkarte sie nicht überschreibt';

-- Bilder je Kanal (V12). Der HugoShop führt seine Bilder weiter in
-- parts_ext.hugoshop_images (Dateinamen auf der Webseite, E6); diese Tabelle
-- gilt für die Marktplätze. Die Dateien liegen unter data/<db>/parts/<id>/,
-- öffentlich über backend/webhook/part-image.php — wie bei der bisherigen
-- eBay-Anbindung, deren Bilder hierher übernommen werden (V21).
CREATE TABLE IF NOT EXISTS parts_channel_image_shop
(
    id         integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    parts_id   integer NOT NULL,
    channel_id integer NOT NULL,
    filename   text NOT NULL,
    sort       integer NOT NULL DEFAULT 0,
    itime      timestamp without time zone DEFAULT now(),
    CONSTRAINT parts_channel_image_shop_key UNIQUE (parts_id, channel_id, filename),
    CONSTRAINT parts_channel_image_shop_parts_id_fk FOREIGN KEY (parts_id)
        REFERENCES parts (id) ON UPDATE NO ACTION ON DELETE CASCADE,
    CONSTRAINT parts_channel_image_shop_channel_id_fk FOREIGN KEY (channel_id)
        REFERENCES sales_channel_shop (id) ON UPDATE NO ACTION ON DELETE CASCADE
);

COMMENT ON TABLE  parts_channel_image_shop          IS 'Shop: Bilder eines Artikels je Marktplatz-Kanal; Dateien unter data/<db>/parts/<parts_id>/';
COMMENT ON COLUMN parts_channel_image_shop.filename IS 'Dateiname im Artikelordner (SHA-1 des Inhalts plus Endung)';
COMMENT ON COLUMN parts_channel_image_shop.sort     IS 'Reihenfolge, 0 = Hauptbild';

-- Den HugoShop gibt es immer. eBay und Amazon kommen mit ihrer Umsetzung.
INSERT INTO sales_channel_shop (type, sortkey) VALUES ('hugoshop', 1) ON CONFLICT (type) DO NOTHING;

-- eBay (Schritt 5). Eingeschaltet, wenn die bisherige eBay-Anbindung es war
-- (ebay_enabled); danach gilt der Schalter unter „Verkaufskanäle".
INSERT INTO sales_channel_shop (type, sortkey, active)
SELECT 'ebay', 2, COALESCE((SELECT lower(btrim(value)) IN ('1', 't', 'true', 'on')
                              FROM defaults_oserp WHERE key = 'ebay_enabled'), false)
ON CONFLICT (type) DO NOTHING;

-- Übernahme (V6): Bisher stand ein Artikel im Shop, wenn es seine
-- parts_ext-Zeile gab. Jede erhält einmalig eine aktive HugoShop-Zeile ohne
-- eigenen Aufschlag — Sortiment und Preise bleiben, wie sie sind.
--
-- Nur einmal: diese Datei läuft bei jedem Schema-Update, und ein zweiter Lauf
-- brächte abgewählte Artikel zurück. Der Merker steht danach in
-- defaults_oserp; beide Anweisungen laufen in derselben Transaktion.
INSERT INTO parts_channel_shop (parts_id, channel_id, active)
SELECT DISTINCT pe.parts_id, c.id, true
  FROM parts_ext pe
  JOIN sales_channel_shop c ON c.type = 'hugoshop'
 WHERE NOT EXISTS (SELECT 1 FROM defaults_oserp WHERE key = 'shop_channels_migrated')
ON CONFLICT (parts_id, channel_id) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_channels_migrated', '1') ON CONFLICT (key) DO NOTHING;

-- Übergang für Schreiber, die nur parts_ext kennen (V7): der Lieferantenimport
-- der Bridge (run.php, bis Stufe F in dev/shop-bridge-abloesung.md) legt
-- Shop-Artikel an, indem er eine parts_ext-Zeile schreibt. Ohne diese Trigger
-- stünden solche Artikel nach der Umstellung nicht mehr im Shop.
--
-- Neue parts_ext-Zeile: HugoShop-Zeile anlegen, falls es keine gibt. Eine
-- vorhandene — auch eine abgeschaltete — bleibt, wie sie ist: wer
-- Kanalzeilen ausdrücklich schreibt, hat Vorrang.
-- Gelöschte parts_ext-Zeile: HugoShop-Zeile abschalten, wie früher das
-- Löschen den Artikel aus dem Shop nahm.
CREATE OR REPLACE FUNCTION parts_ext_channel_insert() RETURNS trigger AS $$
BEGIN
    INSERT INTO parts_channel_shop (parts_id, channel_id, active)
    SELECT NEW.parts_id, c.id, true FROM sales_channel_shop c WHERE c.type = 'hugoshop'
    ON CONFLICT (parts_id, channel_id) DO NOTHING;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION parts_ext_channel_delete() RETURNS trigger AS $$
BEGIN
    UPDATE parts_channel_shop SET active = false, mtime = now()
     WHERE parts_id = OLD.parts_id
       AND channel_id = shop_channel_id('hugoshop')
       AND active;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trigger_parts_ext_channel_insert') THEN
        CREATE TRIGGER trigger_parts_ext_channel_insert
            AFTER INSERT ON parts_ext
            FOR EACH ROW EXECUTE FUNCTION parts_ext_channel_insert();
    END IF;
END $$;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trigger_parts_ext_channel_delete') THEN
        CREATE TRIGGER trigger_parts_ext_channel_delete
            AFTER DELETE ON parts_ext
            FOR EACH ROW EXECUTE FUNCTION parts_ext_channel_delete();
    END IF;
END $$;

-- Kennung eines Kanals über seine Art. Kurzform für die Verknüpfungen in den
-- Abfragen: pc.channel_id = shop_channel_id('hugoshop').
CREATE OR REPLACE FUNCTION shop_channel_id(p_type text) RETURNS integer
    LANGUAGE sql STABLE AS $$
    SELECT id FROM sales_channel_shop WHERE type = p_type
$$;

-- Kennung eines Kanals nur, wenn er eingeschaltet ist, sonst NULL. Für alle
-- Abfragen, die fragen, was ein Kanal anbietet: pc.channel_id = NULL trifft
-- nichts, ein abgeschalteter Kanal bietet also nichts an. Seine Artikelzeilen
-- bleiben stehen und gelten wieder, sobald er eingeschaltet wird.
CREATE OR REPLACE FUNCTION shop_active_channel_id(p_type text) RETURNS integer
    LANGUAGE sql STABLE AS $$
    SELECT id FROM sales_channel_shop WHERE type = p_type AND active
$$;

-- Steuersatz eines Artikels in einer Steuerzone, wie auf der Produktseite:
-- Buchungsgruppe, Steuerzone, Erlöskonto, Steuerschlüssel — nur Schlüssel,
-- die schon gelten. Ohne Schlüssel 0.
CREATE OR REPLACE FUNCTION shop_tax_rate(p_buchungsgruppen_id integer, p_taxzone text) RETURNS numeric
    LANGUAGE sql STABLE AS $$
    SELECT COALESCE((
        SELECT tx.rate
          FROM taxzone_charts tc
          JOIN tax_zones tz ON tz.id = tc.taxzone_id
          JOIN taxkeys tk ON tk.chart_id = tc.income_accno_id
          JOIN tax tx ON tx.id = tk.tax_id
         WHERE tc.buchungsgruppen_id = p_buchungsgruppen_id
           AND tz.description = p_taxzone
           AND tk.startdate <= current_date
         ORDER BY tk.startdate DESC
         LIMIT 1), 0)
$$;

-- Preis eines Artikels im Kanal (V2) — in derselben Art wie parts.sellprice,
-- also netto oder brutto laut shop_tax_included. Warenkorb, Rechnung,
-- Produktseite, Suche und Auswertung lesen den Preis nur hier.
--
--   1. Grundpreis parts.sellprice
--   2. Aufschlag des Artikels im Kanal, sonst Vorgabe des Kanals (V2a).
--      Ohne Kanalzeile kein Aufschlag — der Versandartikel etwa steht in
--      keinem Kanal und behält seinen Preis.
--   3. Mit Aufschlag auf Cent gerundet.
--   4. Bei round_99 den Bruttopreis der Standard-Steuerzone auf die nächste
--      ,99 aufrunden (V2b); ein Preis auf ,99 bleibt. Bei Nettopreisen wird
--      der Nettopreis daraus mit fünf Stellen zurückgerechnet (V2d), damit
--      netto mal Steuersatz wieder genau den Bruttopreis ergibt.
CREATE OR REPLACE FUNCTION shop_channel_price(p_parts_id integer, p_type text DEFAULT 'hugoshop') RETURNS numeric
    LANGUAGE sql STABLE AS $$
    WITH einstellung AS (
        SELECT COALESCE((SELECT lower(btrim(value)) FROM defaults_oserp WHERE key = 'shop_tax_included'), '')
                   IN ('t', 'true', '1', 'y', 'yes') AS brutto,
               COALESCE((SELECT value FROM defaults_oserp WHERE key = 'shop_standard_taxzone'), 'Inland') AS zone
    ), basis AS (
        SELECT p.sellprice, p.buchungsgruppen_id,
               (pc.id IS NOT NULL) AS im_kanal,
               COALESCE(pc.markup_type, c.markup_type, 'none') AS art,
               CASE WHEN pc.markup_type IS NOT NULL THEN COALESCE(pc.markup_value, 0)
                    ELSE COALESCE(c.markup_value, 0) END AS wert,
               COALESCE(c.round_99, false) AS runden
          FROM parts p
          LEFT JOIN sales_channel_shop c ON c.type = p_type
          LEFT JOIN parts_channel_shop pc ON pc.parts_id = p.id AND pc.channel_id = c.id
         WHERE p.id = p_parts_id
    ), aufschlag AS (
        SELECT b.im_kanal, b.runden, e.brutto,
               CASE WHEN NOT b.im_kanal OR b.art = 'none' THEN b.sellprice
                    WHEN b.art = 'percent' THEN ROUND(b.sellprice * (1 + b.wert / 100), 2)
                    WHEN b.art = 'amount'  THEN ROUND(b.sellprice + b.wert, 2)
                    ELSE b.sellprice END AS preis,
               shop_tax_rate(b.buchungsgruppen_id, e.zone) AS satz
          FROM basis b CROSS JOIN einstellung e
    ), rundung AS (
        SELECT a.*,
               CEIL(ROUND(CASE WHEN a.brutto THEN a.preis ELSE a.preis * (1 + a.satz) END, 2) + 0.01) - 0.01
                   AS preis_99
          FROM aufschlag a
    )
    SELECT CASE WHEN NOT (im_kanal AND runden AND preis > 0) THEN preis
                WHEN brutto THEN preis_99
                ELSE ROUND(preis_99 / (1 + satz), 5) END
      FROM rundung
$$;

-- Auftrag in die Warteschlange (batchjob_hugoshop), sofern nicht schon ein
-- gleicher offen ist. Gleiche Aufträge verschiedener Kanäle sind keine
-- Doppel; channel_id NULL steht für den HugoShop. Liefert die Auftragsnummer,
-- 0 wenn schon offen. Genutzt von shopQueueJob() und den Triggern unten —
-- eine Stelle für die Regel.
--
-- Ein offener Auftrag der Gegenrichtung für denselben Artikel und Kanal
-- (veröffentlichen gegen entfernen) wird vorher gelöscht: sonst hinterließe
-- an-aus-an das Angebot beendet, weil das zweite Veröffentlichen als Doppel
-- des ersten nicht angelegt würde.
CREATE OR REPLACE FUNCTION shop_queue_job(p_function text, p_partnumber text DEFAULT '',
                                          p_param text DEFAULT NULL, p_type text DEFAULT 'hugoshop')
    RETURNS integer LANGUAGE sql AS $$
    WITH kanal AS (
        SELECT id FROM sales_channel_shop WHERE type = p_type
    ), gegenrichtung AS (
        DELETE FROM batchjob_hugoshop b
         USING kanal
         WHERE b.result IS NULL
           AND b.partnumber = p_partnumber
           AND COALESCE(b.channel_id, shop_channel_id('hugoshop')) = kanal.id
           AND b.function = CASE p_function WHEN 'publish_part' THEN 'remove_part'
                                            WHEN 'remove_part'  THEN 'publish_part' END
    ), neu AS (
        INSERT INTO batchjob_hugoshop (function, partnumber, param, channel_id)
        SELECT p_function, p_partnumber, p_param, kanal.id
          FROM kanal
         WHERE NOT EXISTS (
               SELECT 1 FROM batchjob_hugoshop b
                WHERE b.function = p_function
                  AND b.partnumber = p_partnumber
                  AND b.result IS NULL
                  AND COALESCE(b.channel_id, shop_channel_id('hugoshop')) = kanal.id)
        RETURNING id
    )
    SELECT COALESCE((SELECT id FROM neu), 0)
$$;

-- Ist die Shop-Erweiterung aktiv? Ohne sie arbeitet niemand Aufträge ab —
-- die Trigger unten legen dann keine an. Eine Übernahme im Upstall setzt
-- shop.ohne_auftraege, damit übernommene Angebote nicht alle neu eingestellt
-- werden.
CREATE OR REPLACE FUNCTION shop_extension_active() RETURNS boolean
    LANGUAGE sql STABLE AS $$
    SELECT COALESCE((SELECT active FROM extensions_oserp WHERE extension = 'shop'), false)
       AND COALESCE(current_setting('shop.ohne_auftraege', true), '') <> 'on'
$$;

-- Automatisch neu veröffentlichen (O1, V22): gilt für die Produktseiten des
-- HugoShops und ist über shop_auto_publish abschaltbar. Die Marktplätze
-- gleichen Preis, Texte und Bestand immer ab — das ist ihre Aufgabe, und ein
-- veralteter Bestand dort hieße Überverkauf (V4).
CREATE OR REPLACE FUNCTION shop_auto_publish_enabled() RETURNS boolean
    LANGUAGE sql STABLE AS $$
    SELECT COALESCE((SELECT lower(btrim(value)) IN ('1', 't', 'true', 'y', 'yes')
                       FROM defaults_oserp WHERE key = 'shop_auto_publish'), true)
       AND shop_extension_active()
$$;

-- Änderung am Artikel (parts gehört kivitendo: hier kommt nur ein Trigger
-- dazu, die Tabelle selbst bleibt unverändert). Je eingeschaltetem Kanal, in
-- dem der Artikel angeboten wird:
--   HugoShop  Verkaufspreis oder Buchungsgruppe (Steuersatz) — die Seite
--             trägt den Preis; nur bei shop_auto_publish
--   Marktplatz zusätzlich Bestand, Beschreibung und Langbeschreibung
CREATE OR REPLACE FUNCTION parts_shop_auto_publish() RETURNS trigger AS $$
DECLARE
    preis   boolean := OLD.sellprice IS DISTINCT FROM NEW.sellprice
                       OR OLD.buchungsgruppen_id IS DISTINCT FROM NEW.buchungsgruppen_id;
    sonst   boolean := OLD.onhand IS DISTINCT FROM NEW.onhand
                       OR OLD.description IS DISTINCT FROM NEW.description
                       OR OLD.notes IS DISTINCT FROM NEW.notes;
    seiten  boolean;
BEGIN
    IF NOT shop_extension_active() THEN
        RETURN NULL;
    END IF;
    seiten := preis AND shop_auto_publish_enabled();

    PERFORM shop_queue_job('publish_part', NEW.partnumber, NULL, c.type)
       FROM parts_channel_shop pc
       JOIN sales_channel_shop c ON c.id = pc.channel_id AND c.active
      WHERE pc.parts_id = NEW.id
        AND pc.active
        AND CASE WHEN c.type = 'hugoshop' THEN seiten ELSE preis OR sonst END;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- Änderung an der Kanalzeile eines Artikels.
--   HugoShop   Aufschlag, Bezeichnung oder Langbeschreibung: Seite neu, nur
--              bei shop_auto_publish. Ein- und Abwählen regelt die
--              Artikelkarte (Seite entfernen) bzw. der Benutzer
--              (Veröffentlichen).
--   Marktplatz neu angeboten oder geändert (auch settings): einstellen bzw.
--              aktualisieren; abgewählt: Angebot beenden (V26).
-- Was der Abgleich selbst schreibt (sync_*, external_id), löst nichts aus —
-- sonst schriebe jeder Abgleich den nächsten Auftrag.
CREATE OR REPLACE FUNCTION parts_channel_shop_auto_publish() RETURNS trigger AS $$
DECLARE
    art      text;
    kanal_an boolean;
    nummer   text;
    geaendert boolean;
BEGIN
    SELECT type, active INTO art, kanal_an FROM sales_channel_shop WHERE id = NEW.channel_id;
    IF NOT kanal_an OR NOT shop_extension_active() THEN
        RETURN NULL;
    END IF;
    SELECT partnumber INTO nummer FROM parts WHERE id = NEW.parts_id;

    geaendert := TG_OP = 'INSERT'
        OR OLD.markup_type IS DISTINCT FROM NEW.markup_type
        OR OLD.markup_value IS DISTINCT FROM NEW.markup_value
        OR OLD.title IS DISTINCT FROM NEW.title
        OR OLD.description IS DISTINCT FROM NEW.description
        OR OLD.settings IS DISTINCT FROM NEW.settings;

    IF art = 'hugoshop' THEN
        IF TG_OP = 'UPDATE' AND NEW.active AND OLD.active AND geaendert AND shop_auto_publish_enabled() THEN
            PERFORM shop_queue_job('publish_part', nummer);
        END IF;
    ELSIF NEW.active AND (geaendert OR NOT OLD.active) THEN
        PERFORM shop_queue_job('publish_part', nummer, NULL, art);
    ELSIF TG_OP = 'UPDATE' AND OLD.active AND NOT NEW.active THEN
        PERFORM shop_queue_job('remove_part', nummer, NULL, art);
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- Bilder eines Marktplatz-Kanals (hochgeladen, entfernt, umsortiert): das
-- Angebot neu einstellen, sofern der Artikel dort angeboten wird.
CREATE OR REPLACE FUNCTION parts_channel_image_shop_auto_publish() RETURNS trigger AS $$
DECLARE
    bild parts_channel_image_shop;
BEGIN
    IF NOT shop_extension_active() THEN
        RETURN NULL;
    END IF;
    bild := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    PERFORM shop_queue_job('publish_part', p.partnumber, NULL, c.type)
       FROM parts_channel_shop pc
       JOIN sales_channel_shop c ON c.id = pc.channel_id AND c.active AND c.type <> 'hugoshop'
       JOIN parts p ON p.id = pc.parts_id
      WHERE pc.parts_id = bild.parts_id AND pc.channel_id = bild.channel_id AND pc.active;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- Brutto- oder Nettopreise in den Stammdaten: betrifft jeden Preis. HugoShop
-- nur bei shop_auto_publish, Marktplätze immer.
CREATE OR REPLACE FUNCTION defaults_oserp_shop_auto_publish() RETURNS trigger AS $$
BEGIN
    IF NOT shop_extension_active() THEN
        RETURN NULL;
    END IF;
    PERFORM shop_queue_job('publish_all', '', NULL, c.type)
       FROM sales_channel_shop c
      WHERE c.active
        AND (c.type <> 'hugoshop' OR shop_auto_publish_enabled());
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- Die Trigger werden bei jedem Lauf neu angelegt: ihre Bedingungen ändern
-- sich mit den Kanälen, und ein nur bei Fehlen angelegter Trigger behielte
-- die alte Fassung.
DO $$ BEGIN
    DROP TRIGGER IF EXISTS trigger_parts_shop_auto_publish ON parts;
    CREATE TRIGGER trigger_parts_shop_auto_publish
        AFTER UPDATE OF sellprice, buchungsgruppen_id, onhand, description, notes ON parts
        FOR EACH ROW
        WHEN (OLD.sellprice IS DISTINCT FROM NEW.sellprice
              OR OLD.buchungsgruppen_id IS DISTINCT FROM NEW.buchungsgruppen_id
              OR OLD.onhand IS DISTINCT FROM NEW.onhand
              OR OLD.description IS DISTINCT FROM NEW.description
              OR OLD.notes IS DISTINCT FROM NEW.notes)
        EXECUTE FUNCTION parts_shop_auto_publish();
END $$;

DO $$ BEGIN
    DROP TRIGGER IF EXISTS trigger_parts_channel_shop_auto_publish ON parts_channel_shop;
    CREATE TRIGGER trigger_parts_channel_shop_auto_publish
        AFTER INSERT OR UPDATE ON parts_channel_shop
        FOR EACH ROW
        EXECUTE FUNCTION parts_channel_shop_auto_publish();
END $$;

DO $$ BEGIN
    DROP TRIGGER IF EXISTS trigger_parts_channel_image_shop_auto_publish ON parts_channel_image_shop;
    CREATE TRIGGER trigger_parts_channel_image_shop_auto_publish
        AFTER INSERT OR DELETE OR UPDATE OF sort ON parts_channel_image_shop
        FOR EACH ROW
        EXECUTE FUNCTION parts_channel_image_shop_auto_publish();
END $$;

DO $$ BEGIN
    DROP TRIGGER IF EXISTS trigger_defaults_oserp_shop_auto_publish ON defaults_oserp;
    CREATE TRIGGER trigger_defaults_oserp_shop_auto_publish
        AFTER UPDATE ON defaults_oserp
        FOR EACH ROW
        WHEN (NEW.key = 'shop_tax_included' AND OLD.value IS DISTINCT FROM NEW.value)
        EXECUTE FUNCTION defaults_oserp_shop_auto_publish();
END $$;

-- Übernahme der bisherigen eBay-Anbindung (V21), einmal. Angebote aus
-- ebay_listings werden eBay-Kanalzeilen (aktiv = eingestellt; Angebots- und
-- Listing-Kennung bleiben erhalten), Bilder aus ebay_part_images werden
-- eBay-Bilder. Die alten Tabellen bleiben stehen, bis alle Mandanten
-- übernommen sind. Während der Übernahme legen die Trigger keine Aufträge an
-- (shop.ohne_auftraege): die Angebote sind schon bei eBay.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM defaults_oserp WHERE key = 'shop_ebay_migrated') THEN
        RETURN;
    END IF;
    PERFORM set_config('shop.ohne_auftraege', 'on', true);

    IF to_regclass('ebay_listings') IS NOT NULL THEN
        INSERT INTO parts_channel_shop (parts_id, channel_id, active, external_id,
                                        sync_status, sync_error, sync_data, sync_mtime)
        SELECT l.parts_id, c.id, l.status = 'active', l.listing_id,
               l.status, NULLIF(l.message, ''),
               jsonb_build_object('offer_id', l.offer_id), l.mtime
          FROM ebay_listings l
          JOIN parts p ON p.id = l.parts_id
          JOIN sales_channel_shop c ON c.type = 'ebay'
        ON CONFLICT (parts_id, channel_id) DO NOTHING;
    END IF;

    IF to_regclass('ebay_part_images') IS NOT NULL THEN
        INSERT INTO parts_channel_image_shop (parts_id, channel_id, filename, sort)
        SELECT i.parts_id, c.id, i.filename, COALESCE(i.sort, 0)
          FROM ebay_part_images i
          JOIN parts p ON p.id = i.parts_id
          JOIN sales_channel_shop c ON c.type = 'ebay'
        ON CONFLICT (parts_id, channel_id, filename) DO NOTHING;
    END IF;

    PERFORM set_config('shop.ohne_auftraege', '', true);
    INSERT INTO defaults_oserp (key, value) VALUES ('shop_ebay_migrated', '1') ON CONFLICT (key) DO NOTHING;
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
    itime      timestamp without time zone DEFAULT now(),
    function   text NOT NULL,
    partnumber text NOT NULL,
    param      text DEFAULT NULL,
    result     text DEFAULT NULL,
    channel_id integer DEFAULT NULL
);

-- Wann ein Auftrag entstand. Name nach kivitendo-Brauch (itime). Auf einer
-- bestehenden Datenbank traegt der Upstall die Spalte mit Vorgabewert nach;
-- die Bridge schreibt mit Spaltenliste und bemerkt sie nicht.
COMMENT ON COLUMN batchjob_hugoshop.itime IS 'Zeitpunkt, zu dem der Auftrag angelegt wurde';

COMMENT ON TABLE batchjob_hugoshop IS 'Shop: Warteschlange fuer Aufgaben, die auf dem Shop-Server laufen';

-- Verkaufskanal des Auftrags (dev/shop-verkaufskanaele.md, Schritt 4). NULL
-- heißt HugoShop: so bleiben Aufträge von vor dieser Spalte und Schreiber, die
-- sie nicht kennen, gültig. Kein Fremdschlüssel — der Upstall legt auf einer
-- bestehenden Datenbank nur die Spalte an, und Kanalzeilen werden nie
-- gelöscht.
COMMENT ON COLUMN batchjob_hugoshop.channel_id IS 'Verkaufskanal (sales_channel_shop.id), NULL = HugoShop';

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

-- Veroeffentlichung: Vorlagensatz und Verzeichnisse. Das Wurzelverzeichnis
-- steht hier, weil jeder Mandant seine eigene Webseite hat; die uebrigen
-- Verzeichnisse gelten relativ dazu und duerfen nicht darueber hinausfuehren.
-- Steht in der settings.ini ein shop_sites_dir, muss das eingestellte
-- Verzeichnis darunter liegen — so kann ein Administrator die Grenze ziehen.
--
-- Gebaut wird mit dem Programm hugo aus dem Verzeichnis
-- shop_publish_command_path. Eingetragen wird nur
-- ein Pfad, keine Befehlszeile: die Argumente setzt OpensourceERP selbst, und
-- der Pfad wird vor jedem Bau geprueft (absolut, ohne Leerraum, ausfuehrbare
-- Datei). Ist der Wert hier leer, gilt ein gleichnamiger Eintrag aus der
-- settings.ini als Rueckfall.
-- shop_publish_clean_destination: Hugo mit --cleanDestinationDir aufrufen.
INSERT INTO defaults_oserp (key, value) VALUES ('shop_sites_dir', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_publish_command_path', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_publish_clean_destination', '1') ON CONFLICT (key) DO NOTHING;
-- shop_job_retention_days: Der Laeufer loescht erfolgreich erledigte Auftraege
-- aus batchjob_hugoshop, sobald sie so viele Tage alt sind. 0 schaltet das ab;
-- fehlgeschlagene Auftraege bleiben immer stehen.
INSERT INTO defaults_oserp (key, value) VALUES ('shop_job_retention_days', '30') ON CONFLICT (key) DO NOTHING;
-- Verkaufskanäle (dev/shop-verkaufskanaele.md):
-- shop_auto_publish: Produktseiten bei Preisänderungen automatisch neu
-- schreiben (V22) — Verkaufspreis, Buchungsgruppe, Aufschlag und Texte im
-- HugoShop, Brutto-/Nettopreise, Kanalvorgaben.
-- shop_channel_off_pages: was beim Abschalten des HugoShops mit den Seiten
-- geschieht (V16) — draft: bleiben als Entwurf stehen und werden nicht mehr
-- veröffentlicht; remove: werden entfernt.
INSERT INTO defaults_oserp (key, value) VALUES ('shop_auto_publish', '1') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_channel_off_pages', 'draft') ON CONFLICT (key) DO NOTHING;
-- eBay-Kanal: Adresse, unter der eBay die Artikelbilder abholt
-- (backend/webhook/part-image.php, https). Der Läufer arbeitet ohne
-- Webanfrage und kennt die eigene Adresse sonst nicht.
INSERT INTO defaults_oserp (key, value) VALUES ('ebay_public_host', '') ON CONFLICT (key) DO NOTHING;
-- Anbindung an HugoCMS (dev/shop-hugocms-trennung.md): Adresse des
-- cms-api-Endpunkts der Webseite und der Schluessel, den HugoCMS dort in den
-- Projekteinstellungen erzeugt. Der Schluessel ist ein Geheimnis und geht nie an
-- den Browser (oserp_config/defaults.php).
-- shop_publish_mode: local = OSERP schreibt in die Webseite und baut selbst
-- (Webseite auf demselben Server); hugocms = Bereitstellung, Übertragung an
-- HugoCMS, Bau dort.
INSERT INTO defaults_oserp (key, value) VALUES ('shop_publish_mode', 'local') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_hugocms_url', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_hugocms_key', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_template_set', 'standard') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_site_dir', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_content_dir', 'content/de/produkt') ON CONFLICT (key) DO NOTHING;

-- Bilder und Downloads: Adressmuster mit %s fuer den Dateinamen, Verzeichnisse
-- relativ zum Verzeichnis der Shop-Webseite. Ohne Verzeichnisse entstehen
-- keine Vorschaubilder.
INSERT INTO defaults_oserp (key, value) VALUES ('shop_images_link', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_downloads_link', '/downloads/%s') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_images_dir', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_thumbnails_dir', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_thumbnail_size', '200') ON CONFLICT (key) DO NOTHING;

-- Adresse, unter der die Shop-Webseite den Shop-Zugang von OpensourceERP
-- erreicht. Der Laeufer schreibt sie mit dem Shop-Schluessel in
-- <webseite>/oserp-shop/config.php, wo der Proxy sie liest.
INSERT INTO defaults_oserp (key, value) VALUES ('shop_backend_url', '') ON CONFLICT (key) DO NOTHING;

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
