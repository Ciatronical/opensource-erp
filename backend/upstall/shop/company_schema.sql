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
-- Kanäle angeboten: HugoShop, eBay, später Amazon.
--
-- Ein Kanal ist eine Instanz seiner Art (dev/shop-mehrere-kanaele.md): je
-- Mandant beliebig viele HugoShops und eBay-Anbindungen. V3 („höchstens einer
-- je Art") ist aufgehoben, der eindeutige Index auf type entfällt. Die
-- Einstellungen einer Instanz stehen in settings, ihre Geheimnisse in
-- sales_channel_secret_shop.
--
-- Die kivitendo-Tabellen shops/shop_parts bleiben unberührt (V1): sie gehören
-- zu kivitendos Shopware- und WooCommerce-Anbindung.
--
-- Der Upstall-Parser liest einen Tabellenrumpf nur bis zum ersten
-- Klammer-Semikolon und legt die Tabellen in Dateireihenfolge an —
-- sales_channel_shop muss vor parts_channel_shop stehen.

CREATE TABLE IF NOT EXISTS sales_channel_shop
(
    id             integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    type           text NOT NULL,
    name           text,
    active         boolean NOT NULL DEFAULT true,
    sortkey        integer,
    markup_type    text NOT NULL DEFAULT 'none',
    markup_value   numeric(15,5) NOT NULL DEFAULT 0,
    round_99       boolean NOT NULL DEFAULT false,
    auto_add_parts boolean NOT NULL DEFAULT false,
    settings       jsonb,
    itime          timestamp without time zone DEFAULT now(),
    mtime          timestamp without time zone,
    CONSTRAINT sales_channel_shop_type_check CHECK (type IN ('hugoshop', 'ebay', 'amazon')),
    CONSTRAINT sales_channel_shop_markup_check CHECK (markup_type IN ('none', 'percent', 'amount'))
);

COMMENT ON TABLE  sales_channel_shop                IS 'Shop: Verkaufskanal, eine Instanz seiner Art';
COMMENT ON COLUMN sales_channel_shop.type           IS 'Art: hugoshop, ebay oder amazon';
COMMENT ON COLUMN sales_channel_shop.name           IS 'Name der Instanz, eindeutig je Mandant; Pflicht (nachgezogen im Abschnitt INSTANZEN)';
COMMENT ON COLUMN sales_channel_shop.auto_add_parts IS 'Neue Shop-Artikel (neue parts_ext-Zeile) automatisch in diesen Kanal aufnehmen (M3)';
COMMENT ON COLUMN sales_channel_shop.markup_type  IS 'Vorgabe für alle Artikel des Kanals: none, percent (Prozent) oder amount (fester Betrag)';
COMMENT ON COLUMN sales_channel_shop.markup_value IS 'Aufschlag in Prozent oder als Betrag — netto oder brutto wie parts.sellprice laut shop_tax_included';
COMMENT ON COLUMN sales_channel_shop.round_99     IS 'Bruttopreis auf die nächste ,99 aufrunden';
COMMENT ON COLUMN sales_channel_shop.settings     IS 'Kanaleigene Einstellungen, ohne Geheimnisse — geht an die Oberfläche';

-- Geheimnisse einer Instanz: Shop-Schlüssel, HugoCMS-Schlüssel,
-- PayPal-Geheimnisse, eBay-Zugang und Token. Getrennt von settings, weil
-- settings an die Oberfläche geht — diese Tabelle nie.
CREATE TABLE IF NOT EXISTS sales_channel_secret_shop
(
    channel_id integer NOT NULL REFERENCES sales_channel_shop (id) ON DELETE CASCADE,
    key        text NOT NULL,
    value      text NOT NULL DEFAULT '',
    mtime      timestamp without time zone DEFAULT now(),
    PRIMARY KEY (channel_id, key)
);

COMMENT ON TABLE  sales_channel_secret_shop     IS 'Shop: Geheimnisse eines Verkaufskanals — geht nie an die Oberfläche';
COMMENT ON COLUMN sales_channel_secret_shop.key IS 'Name ohne Präfix, etwa public_key, hugocms_key, client_secret';

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
    unavailable  boolean NOT NULL DEFAULT false,
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

-- Nachgetragen: in bestehenden Datenbanken gibt es die Tabelle schon.
ALTER TABLE parts_channel_shop ADD COLUMN IF NOT EXISTS unavailable boolean NOT NULL DEFAULT false;
COMMENT ON COLUMN parts_channel_shop.unavailable  IS 'Im Kanal vorübergehend nicht verfügbar: bleibt angeboten, ist aber nicht bestellbar (HugoShop: „Momentan nicht verfügbar“, eBay: Menge 0). Anders als parts.obsolete bleibt der Artikel im ERP nutzbar';

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

-- Mehrere Kanäle je Art: der eindeutige Index auf type entfällt
-- (dev/shop-mehrere-kanaele.md).
ALTER TABLE sales_channel_shop DROP CONSTRAINT IF EXISTS sales_channel_shop_type_key;

-- Vorgaben für die Einstellungen eines neuen Kanals (Grundausstattung und
-- „Kanal anlegen" in der Kanalkarte). Was hier fehlt, ist leer; die Leser
-- haben für fehlende Werte dieselben Vorgaben.
CREATE OR REPLACE FUNCTION shop_channel_default_settings(p_type text) RETURNS jsonb
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p_type
        WHEN 'hugoshop' THEN jsonb_build_object(
            'content_dir',                      'content/de/produkt',
            'downloads_link',                   '/downloads/%s',
            'template_set',                     'standard',
            'publish_mode',                     'local',
            'publish_clean_destination',        '1',
            'auto_publish',                     '1',
            'channel_off_pages',                'draft',
            'invoice_mail_subject',             'Ihre Rechnung (%s) vom %s',
            'paypal_sandbox',                   '1',
            'paypal_payment_method_preference', 'IMMEDIATE_PAYMENT_REQUIRED')
        WHEN 'ebay' THEN jsonb_build_object(
            'environment',                      'production',
            'marketplace_id',                   'EBAY_DE',
            'content_language',                 'de-DE',
            'currency',                         'EUR',
            'default_condition',                'NEW')
        ELSE '{}'::jsonb END
$$;

-- Grundausstattung, einmal je Mandant: ein HugoShop und ein eBay-Kanal. Der
-- Merker verhindert, dass ein später gelöschter Kanal (M5) beim nächsten
-- Schema-Update wiederkommt; eine bestehende Datenbank hat beide schon.
-- eBay ist eingeschaltet, wenn die bisherige eBay-Anbindung es war
-- (ebay_enabled); danach gilt der Schalter unter „Verkaufskanäle".
INSERT INTO sales_channel_shop (type, name, sortkey, auto_add_parts, settings)
SELECT 'hugoshop', 'HugoShop', 1, true, shop_channel_default_settings('hugoshop')
 WHERE NOT EXISTS (SELECT 1 FROM defaults_oserp WHERE key = 'shop_channels_seeded')
   AND NOT EXISTS (SELECT 1 FROM sales_channel_shop WHERE type = 'hugoshop');

INSERT INTO sales_channel_shop (type, name, sortkey, active, settings)
SELECT 'ebay', 'eBay', 2, COALESCE((SELECT lower(btrim(value)) IN ('1', 't', 'true', 'on')
                                     FROM defaults_oserp WHERE key = 'ebay_enabled'), false),
       shop_channel_default_settings('ebay')
 WHERE NOT EXISTS (SELECT 1 FROM defaults_oserp WHERE key = 'shop_channels_seeded')
   AND NOT EXISTS (SELECT 1 FROM sales_channel_shop WHERE type = 'ebay');

INSERT INTO defaults_oserp (key, value) VALUES ('shop_channels_seeded', '1') ON CONFLICT (key) DO NOTHING;

-- Name ist Pflicht und eindeutig. Bestehende Kanäle heißen nach ihrer Art;
-- gibt es eine Art mehrfach, bekommen die weiteren ihre Kennung angehängt.
-- Danach erst NOT NULL: der Upstall legt die Spalte auf einer bestehenden
-- Datenbank ohne Vorgabewert an, ein NOT NULL dort scheiterte an den Zeilen.
UPDATE sales_channel_shop c
   SET name = CASE c.type WHEN 'hugoshop' THEN 'HugoShop' WHEN 'ebay' THEN 'eBay' ELSE initcap(c.type) END
              || CASE WHEN EXISTS (SELECT 1 FROM sales_channel_shop v
                                    WHERE v.type = c.type AND v.id < c.id) THEN ' ' || c.id ELSE '' END
 WHERE NULLIF(btrim(c.name), '') IS NULL;

ALTER TABLE sales_channel_shop ALTER COLUMN name SET NOT NULL;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conrelid = 'sales_channel_shop'::regclass
                      AND conname = 'sales_channel_shop_name_check') THEN
        ALTER TABLE sales_channel_shop
            ADD CONSTRAINT sales_channel_shop_name_check CHECK (btrim(name) <> '');
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS sales_channel_shop_name_key
    ON sales_channel_shop (lower(btrim(name)));

-- Bisher nahm der Trigger auf parts_ext neue Artikel immer in den HugoShop
-- auf. Das gilt jetzt je Kanal (auto_add_parts); der bestehende HugoShop
-- behält es — einmal gesetzt, danach entscheidet der Benutzer.
UPDATE sales_channel_shop SET auto_add_parts = true
 WHERE type = 'hugoshop'
   AND NOT EXISTS (SELECT 1 FROM defaults_oserp WHERE key = 'shop_auto_add_migrated');
INSERT INTO defaults_oserp (key, value) VALUES ('shop_auto_add_migrated', '1') ON CONFLICT (key) DO NOTHING;

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

-- Automatische Aufnahme neuer Shop-Artikel (M3, dev/shop-mehrere-kanaele.md).
-- Entstanden als Übergang für Schreiber, die nur parts_ext kennen (V7); die
-- Bridge arbeitet nicht mit dieser Datenbank, geblieben ist die Regel für
-- jeden, der einen Artikel neu in den Shop aufnimmt (savePartShopData).
--
-- Neue parts_ext-Zeile: Kanalzeile in jedem Kanal mit auto_add_parts anlegen
-- (M3), falls es keine gibt. Eine vorhandene — auch eine abgeschaltete —
-- bleibt, wie sie ist: wer Kanalzeilen ausdrücklich schreibt, hat Vorrang.
-- Gelöschte parts_ext-Zeile: die Zeilen aller HugoShops abschalten, wie
-- früher das Löschen den Artikel aus dem Shop nahm. parts_ext sind die
-- Artikeltexte aller HugoShops (M6); Marktplätze bleiben unberührt.
CREATE OR REPLACE FUNCTION parts_ext_channel_insert() RETURNS trigger AS $$
BEGIN
    INSERT INTO parts_channel_shop (parts_id, channel_id, active)
    SELECT NEW.parts_id, c.id, true FROM sales_channel_shop c WHERE c.auto_add_parts
    ON CONFLICT (parts_id, channel_id) DO NOTHING;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION parts_ext_channel_delete() RETURNS trigger AS $$
BEGIN
    UPDATE parts_channel_shop SET active = false, mtime = now()
     WHERE parts_id = OLD.parts_id
       AND channel_id IN (SELECT id FROM sales_channel_shop WHERE type = 'hugoshop')
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

-- ── Kanal über die Kennung (dev/shop-mehrere-kanaele.md) ──
--
-- Ein Kanal ist eine Instanz seiner Art; alle Funktionen nehmen seine
-- Kennung. Die Fassungen mit der Art als Text (Übergang bis Schritt 6) sind
-- entfernt (Abschnitt INSTANZEN) — sie trugen denselben Namen, und ein
-- Platzhalter ohne Typ traf dort die falsche Fassung.

-- Der erste Kanal einer Art (kleinster sortkey, dann kleinste Kennung), NULL
-- wenn es keinen gibt. Nur für Stellen, die bewusst „irgendeinen" Kanal der
-- Art meinen: Übernahmen im Schema, Verwaltungsaufrufe ohne Kanalangabe, den
-- Zahlungsabgleich, der für alle HugoShops gilt. Eigener Name, keine
-- Überladung.
CREATE OR REPLACE FUNCTION shop_first_channel_id(p_type text) RETURNS integer
    LANGUAGE sql STABLE AS $$
    SELECT id FROM sales_channel_shop
     WHERE type = p_type
     ORDER BY sortkey NULLS LAST, id
     LIMIT 1
$$;

-- Kennung eines Kanals nur, wenn er eingeschaltet ist, sonst NULL. Für alle
-- Abfragen, die fragen, was ein Kanal anbietet: pc.channel_id = NULL trifft
-- nichts, ein abgeschalteter Kanal bietet also nichts an. Seine Artikelzeilen
-- bleiben stehen und gelten wieder, sobald er eingeschaltet wird.
CREATE OR REPLACE FUNCTION shop_active_channel_id(p_channel_id integer) RETURNS integer
    LANGUAGE sql STABLE AS $$
    SELECT id FROM sales_channel_shop WHERE id = p_channel_id AND active
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
CREATE OR REPLACE FUNCTION shop_channel_price(p_parts_id integer, p_channel_id integer) RETURNS numeric
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
          LEFT JOIN sales_channel_shop c ON c.id = p_channel_id
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

-- Ist der Artikel im Kanal bestellbar? Nicht bei parts.obsolete („Veraltet /
-- Nicht mehr verwenden“, gilt für alle Kanäle) und nicht, wenn er im Kanal als
-- nicht verfügbar markiert ist (parts_channel_shop.unavailable). Ob er dort
-- überhaupt angeboten wird, fragt diese Funktion nicht — das bleibt Sache der
-- Kanalzeile (active). Eine Regel für Produktseite, Warenkorb und Marktplätze.
CREATE OR REPLACE FUNCTION shop_part_available(p_parts_id integer, p_channel_id integer) RETURNS boolean
    LANGUAGE sql STABLE AS $$
    SELECT NOT COALESCE(p.obsolete, false) AND NOT COALESCE(pc.unavailable, false)
      FROM parts p
      LEFT JOIN parts_channel_shop pc ON pc.parts_id = p.id AND pc.channel_id = p_channel_id
     WHERE p.id = p_parts_id
$$;

-- Auftrag in die Warteschlange (batchjob_hugoshop), sofern nicht schon ein
-- gleicher offen ist. Gleiche Aufträge verschiedener Kanäle sind keine
-- Doppel. Liefert die Auftragsnummer, 0 wenn schon offen oder den Kanal nicht
-- gibt. Genutzt von shopQueueJob() und den Triggern unten — eine Stelle für
-- die Regel.
--
-- Ein offener Auftrag der Gegenrichtung für denselben Artikel und Kanal
-- (veröffentlichen gegen entfernen) wird vorher gelöscht: sonst hinterließe
-- an-aus-an das Angebot beendet, weil das zweite Veröffentlichen als Doppel
-- des ersten nicht angelegt würde.
CREATE OR REPLACE FUNCTION shop_queue_job(p_function text, p_partnumber text,
                                          p_param text, p_channel_id integer)
    RETURNS integer LANGUAGE sql AS $$
    WITH kanal AS (
        SELECT id FROM sales_channel_shop WHERE id = p_channel_id
    ), gegenrichtung AS (
        DELETE FROM batchjob_hugoshop b
         USING kanal
         WHERE b.result IS NULL
           AND b.partnumber = p_partnumber
           AND b.channel_id = kanal.id
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
                  AND b.channel_id = kanal.id)
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
-- Die Fassung ohne Kanal (shop_auto_publish aus defaults_oserp) gibt es
-- seit Schritt 5 nicht mehr: die Einstellung gehört dem HugoShop.
DROP FUNCTION IF EXISTS shop_auto_publish_enabled();

-- Automatisch neu veröffentlichen je HugoShop: settings.auto_publish des
-- Kanals, gepflegt in der Kanalkarte.
CREATE OR REPLACE FUNCTION shop_auto_publish_enabled(p_channel_id integer) RETURNS boolean
    LANGUAGE sql STABLE AS $$
    -- ohne Schlüssel NULL, dann gilt die Vorgabe (kein ?-Operator: PDO hielte
    -- ihn für einen Platzhalter)
    SELECT COALESCE((SELECT lower(btrim(settings ->> 'auto_publish')) IN ('1', 't', 'true', 'y', 'yes')
                       FROM sales_channel_shop WHERE id = p_channel_id),
                    true)
       AND shop_extension_active()
$$;

-- Änderung am Artikel (parts gehört kivitendo: hier kommt nur ein Trigger
-- dazu, die Tabelle selbst bleibt unverändert). Je eingeschaltetem Kanal, in
-- dem der Artikel angeboten wird:
--   HugoShop  Verkaufspreis oder Buchungsgruppe (Steuersatz) — die Seite
--             trägt den Preis —, dazu „Veraltet“ (obsolete): die Seite zeigt
--             die Verfügbarkeit, und das Gewicht: es entscheidet, ob eine
--             Versandart passt (shop_part_shipping_check), sonst wird die
--             Seite zum Entwurf; nur bei shop_auto_publish
--   Marktplatz zusätzlich Bestand, Beschreibung und Langbeschreibung
CREATE OR REPLACE FUNCTION parts_shop_auto_publish() RETURNS trigger AS $$
DECLARE
    preis   boolean := OLD.sellprice IS DISTINCT FROM NEW.sellprice
                       OR OLD.buchungsgruppen_id IS DISTINCT FROM NEW.buchungsgruppen_id
                       OR OLD.obsolete IS DISTINCT FROM NEW.obsolete
                       OR OLD.weight IS DISTINCT FROM NEW.weight;
    sonst   boolean := OLD.onhand IS DISTINCT FROM NEW.onhand
                       OR OLD.description IS DISTINCT FROM NEW.description
                       OR OLD.notes IS DISTINCT FROM NEW.notes;
BEGIN
    IF NOT shop_extension_active() THEN
        RETURN NULL;
    END IF;

    PERFORM shop_queue_job('publish_part', NEW.partnumber, NULL, c.id)
       FROM parts_channel_shop pc
       JOIN sales_channel_shop c ON c.id = pc.channel_id AND c.active
      WHERE pc.parts_id = NEW.id
        AND pc.active
        AND CASE WHEN c.type = 'hugoshop' THEN preis AND shop_auto_publish_enabled(c.id)
                 ELSE preis OR sonst END;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- Änderung an der Kanalzeile eines Artikels.
--   HugoShop   Aufschlag, Bezeichnung, Langbeschreibung oder Verfügbarkeit
--              (unavailable): Seite neu, nur
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
        OR OLD.settings IS DISTINCT FROM NEW.settings
        OR OLD.unavailable IS DISTINCT FROM NEW.unavailable;

    IF art = 'hugoshop' THEN
        IF TG_OP = 'UPDATE' AND NEW.active AND OLD.active AND geaendert
           AND shop_auto_publish_enabled(NEW.channel_id) THEN
            PERFORM shop_queue_job('publish_part', nummer, NULL, NEW.channel_id);
        END IF;
    ELSIF NEW.active AND (geaendert OR NOT OLD.active) THEN
        PERFORM shop_queue_job('publish_part', nummer, NULL, NEW.channel_id);
    ELSIF TG_OP = 'UPDATE' AND OLD.active AND NOT NEW.active THEN
        PERFORM shop_queue_job('remove_part', nummer, NULL, NEW.channel_id);
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
    PERFORM shop_queue_job('publish_part', p.partnumber, NULL, c.id)
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
    PERFORM shop_queue_job('publish_all', '', NULL, c.id)
       FROM sales_channel_shop c
      WHERE c.active
        AND (c.type <> 'hugoshop' OR shop_auto_publish_enabled(c.id));
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- Lagerbuchung bei Verkäufen (O14, V28). Rechnungen aus HugoShop und eBay
-- buchen je Warenposition eine Ausbuchung vom Lagerplatz shop_stock_bin_id —
-- sonst sänke der gemeinsame Bestand (V4) erst, wenn jemand von Hand
-- ausbucht, und eBay meldete bis dahin den alten Bestand.
--
-- Gebucht wird wie in der Lagerverwaltung (bookStock): Zeile in inventory,
-- parts.onhand fortgeschrieben vom kivitendo-Trigger trig_update_onhand.
-- invoice_id verweist auf die Rechnungsposition: die Buchung gehört zum
-- Beleg und lässt sich in der Lagerverwaltung nicht einzeln zurücknehmen.
--
-- Nur Waren (part_type part) mit positiver Menge; keine Versandartikel
-- (shop_is_shipping_part: der jeder Versandart) und nicht der eBay-Sammelartikel
-- (ebay_default_parts_id). shop_is_shipping_part steht weiter unten; PL/pgSQL
-- löst den Namen erst beim Aufruf auf. Der Bestand darf negativ werden — verkauft ist
-- verkauft; die Differenz zeigt die Inventur.
--
-- Ohne Lagerplatz keine Buchung (0), wie vor O14; die Einrichtungsprüfung
-- weist darauf hin. Eine Rechnung wird höchstens einmal gebucht.
CREATE OR REPLACE FUNCTION shop_book_stock(p_ar_id integer) RETURNS integer AS $$
DECLARE
    platz       integer;
    lager       integer;
    art         integer;
    mitarbeiter integer;
    vorgang     integer;
    anzahl      integer;
BEGIN
    platz := NULLIF(btrim((SELECT value FROM defaults_oserp WHERE key = 'shop_stock_bin_id')), '')::integer;
    SELECT warehouse_id INTO lager FROM bin WHERE id = platz;
    IF lager IS NULL THEN
        RETURN 0;
    END IF;

    IF EXISTS (SELECT 1 FROM inventory inv JOIN invoice i ON i.id = inv.invoice_id
                WHERE i.trans_id = p_ar_id) THEN
        RETURN 0;
    END IF;

    SELECT id INTO art FROM transfer_type
     WHERE direction = 'out'
     ORDER BY (description = 'shipped') DESC, (description = 'used') DESC, sortkey
     LIMIT 1;
    mitarbeiter := COALESCE(
        (SELECT employee_id FROM ar WHERE id = p_ar_id),
        (SELECT id FROM employee WHERE NOT COALESCE(deleted, false) ORDER BY id LIMIT 1));
    IF art IS NULL OR mitarbeiter IS NULL THEN
        RETURN 0;
    END IF;

    vorgang := nextval('id');
    INSERT INTO inventory (parts_id, warehouse_id, bin_id, qty, chargenumber, comment,
                           shippingdate, employee_id, trans_id, trans_type_id, invoice_id)
    SELECT i.parts_id, lager, platz, -i.qty, '', 'Verkauf, Rechnung ' || a.invnumber,
           COALESCE(a.transdate, current_date), mitarbeiter, vorgang, art, i.id
      FROM invoice i
      JOIN parts p ON p.id = i.parts_id
      JOIN ar a ON a.id = i.trans_id
     WHERE i.trans_id = p_ar_id
       AND p.part_type = 'part'
       AND i.qty > 0
       AND NOT shop_is_shipping_part(i.parts_id)
       -- Sammelartikel jedes eBay-Kanals; ebay_default_parts_id nur bis
       -- zum Umzug in settings (Schritt 4)
       AND p.id IS DISTINCT FROM NULLIF(btrim((SELECT value FROM defaults_oserp WHERE key = 'ebay_default_parts_id')), '')::integer
       AND NOT EXISTS (SELECT 1 FROM sales_channel_shop c
                        WHERE c.type = 'ebay'
                          AND btrim(c.settings ->> 'default_parts_id') = p.id::text);
    GET DIAGNOSTICS anzahl = ROW_COUNT;

    -- Wie bookStock: ein bebuchter Artikel ist lagerfähig, sonst blendet
    -- kivitendo ihn in den Lagermasken aus
    UPDATE parts SET stockable = true
     WHERE NOT COALESCE(stockable, false)
       AND id IN (SELECT parts_id FROM inventory WHERE trans_id = vorgang);

    RETURN anzahl;
END;
$$ LANGUAGE plpgsql;

-- Die Trigger werden bei jedem Lauf neu angelegt: ihre Bedingungen ändern
-- sich mit den Kanälen, und ein nur bei Fehlen angelegter Trigger behielte
-- die alte Fassung.
DO $$ BEGIN
    DROP TRIGGER IF EXISTS trigger_parts_shop_auto_publish ON parts;
    CREATE TRIGGER trigger_parts_shop_auto_publish
        AFTER UPDATE OF sellprice, buchungsgruppen_id, obsolete, weight, onhand, description, notes ON parts
        FOR EACH ROW
        WHEN (OLD.sellprice IS DISTINCT FROM NEW.sellprice
              OR OLD.buchungsgruppen_id IS DISTINCT FROM NEW.buchungsgruppen_id
              OR OLD.obsolete IS DISTINCT FROM NEW.obsolete
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
-- LÄNDER (dev/shop-versand.md, Schritt 3)
-- ============================================================================
--
-- Länder stehen in kivitendo als Freitext (customer.country,
-- shipto.shiptocountry …): „Deutschland", „DE", „Deutschland " mit
-- Leerzeichen. Versandkosten und Lieferländer brauchen ein eindeutiges Land.
-- Die Freitextspalten bleiben unverändert; abgebildet wird über eine
-- Zuordnungstabelle.
--
-- Gefüllt aus den ICU-Daten: die Länder unten per SQL, die Zuordnungen
-- (ISO-Codes und die Ländernamen in den 21 Sprachen der Anwendung) aus
-- company_data/country_alias_shop.csv. Was dort fehlt oder doppeldeutig ist, ordnet der Betreiber in
-- der Firmenkonfiguration zu (manual). Die Ländernamen der Oberfläche kommen
-- aus dem Code (Intl.DisplayNames), nicht aus der Datenbank.

CREATE TABLE IF NOT EXISTS country_shop
(
    iso_code text NOT NULL PRIMARY KEY CHECK (iso_code ~ '^[A-Z]{2}$'),
    eu       boolean NOT NULL DEFAULT false
);

COMMENT ON TABLE  country_shop          IS 'Shop: Länder nach ISO 3166-1 alpha-2';
COMMENT ON COLUMN country_shop.iso_code IS 'Ländercode, zwei Großbuchstaben';
COMMENT ON COLUMN country_shop.eu       IS 'Mitglied der Europäischen Union';

-- Die Länderliste (ISO 3166-1 alpha-2, aus den ICU-Daten). Per SQL statt als
-- CSV: der CSV-Import leert die Tabelle vorher mit TRUNCATE, und das geht bei
-- einer Tabelle nicht, auf die ein Fremdschlüssel zeigt. ON CONFLICT: neue
-- Länder kommen bei einem späteren Update hinzu, vorhandene bleiben.
INSERT INTO country_shop (iso_code, eu) VALUES
    ('AD', false), ('AE', false), ('AF', false), ('AG', false), ('AI', false), ('AL', false), ('AM', false), ('AO', false),
    ('AQ', false), ('AR', false), ('AS', false), ('AT', true), ('AU', false), ('AW', false), ('AX', false), ('AZ', false),
    ('BA', false), ('BB', false), ('BD', false), ('BE', true), ('BF', false), ('BG', true), ('BH', false), ('BI', false),
    ('BJ', false), ('BL', false), ('BM', false), ('BN', false), ('BO', false), ('BQ', false), ('BR', false), ('BS', false),
    ('BT', false), ('BV', false), ('BW', false), ('BY', false), ('BZ', false), ('CA', false), ('CC', false), ('CD', false),
    ('CF', false), ('CG', false), ('CH', false), ('CI', false), ('CK', false), ('CL', false), ('CM', false), ('CN', false),
    ('CO', false), ('CR', false), ('CU', false), ('CV', false), ('CW', false), ('CX', false), ('CY', true), ('CZ', true),
    ('DE', true), ('DJ', false), ('DK', true), ('DM', false), ('DO', false), ('DZ', false), ('EC', false), ('EE', true),
    ('EG', false), ('EH', false), ('ER', false), ('ES', true), ('ET', false), ('FI', true), ('FJ', false), ('FK', false),
    ('FM', false), ('FO', false), ('FR', true), ('GA', false), ('GB', false), ('GD', false), ('GE', false), ('GF', false),
    ('GG', false), ('GH', false), ('GI', false), ('GL', false), ('GM', false), ('GN', false), ('GP', false), ('GQ', false),
    ('GR', true), ('GS', false), ('GT', false), ('GU', false), ('GW', false), ('GY', false), ('HK', false), ('HM', false),
    ('HN', false), ('HR', true), ('HT', false), ('HU', true), ('ID', false), ('IE', true), ('IL', false), ('IM', false),
    ('IN', false), ('IO', false), ('IQ', false), ('IR', false), ('IS', false), ('IT', true), ('JE', false), ('JM', false),
    ('JO', false), ('JP', false), ('KE', false), ('KG', false), ('KH', false), ('KI', false), ('KM', false), ('KN', false),
    ('KP', false), ('KR', false), ('KW', false), ('KY', false), ('KZ', false), ('LA', false), ('LB', false), ('LC', false),
    ('LI', false), ('LK', false), ('LR', false), ('LS', false), ('LT', true), ('LU', true), ('LV', true), ('LY', false),
    ('MA', false), ('MC', false), ('MD', false), ('ME', false), ('MF', false), ('MG', false), ('MH', false), ('MK', false),
    ('ML', false), ('MM', false), ('MN', false), ('MO', false), ('MP', false), ('MQ', false), ('MR', false), ('MS', false),
    ('MT', true), ('MU', false), ('MV', false), ('MW', false), ('MX', false), ('MY', false), ('MZ', false), ('NA', false),
    ('NC', false), ('NE', false), ('NF', false), ('NG', false), ('NI', false), ('NL', true), ('NO', false), ('NP', false),
    ('NR', false), ('NU', false), ('NZ', false), ('OM', false), ('PA', false), ('PE', false), ('PF', false), ('PG', false),
    ('PH', false), ('PK', false), ('PL', true), ('PM', false), ('PN', false), ('PR', false), ('PS', false), ('PT', true),
    ('PW', false), ('PY', false), ('QA', false), ('RE', false), ('RO', true), ('RS', false), ('RU', false), ('RW', false),
    ('SA', false), ('SB', false), ('SC', false), ('SD', false), ('SE', true), ('SG', false), ('SH', false), ('SI', true),
    ('SJ', false), ('SK', true), ('SL', false), ('SM', false), ('SN', false), ('SO', false), ('SR', false), ('SS', false),
    ('ST', false), ('SV', false), ('SX', false), ('SY', false), ('SZ', false), ('TC', false), ('TD', false), ('TF', false),
    ('TG', false), ('TH', false), ('TJ', false), ('TK', false), ('TL', false), ('TM', false), ('TN', false), ('TO', false),
    ('TR', false), ('TT', false), ('TV', false), ('TW', false), ('TZ', false), ('UA', false), ('UG', false), ('UM', false),
    ('US', false), ('UY', false), ('UZ', false), ('VA', false), ('VC', false), ('VE', false), ('VG', false), ('VI', false),
    ('VN', false), ('VU', false), ('WF', false), ('WS', false), ('YE', false), ('YT', false), ('ZA', false), ('ZM', false),
    ('ZW', false)
ON CONFLICT (iso_code) DO NOTHING;

-- Die Zuordnungen lädt der Upstall einmalig aus company_data/ — nach den
-- SQL-Anweisungen, die Länder stehen dann schon.
CREATE TABLE IF NOT EXISTS country_alias_shop
(
    alias    text NOT NULL PRIMARY KEY,
    iso_code text NOT NULL,
    manual   boolean NOT NULL DEFAULT false,
    CONSTRAINT country_alias_shop_iso_code_fk FOREIGN KEY (iso_code)
        REFERENCES country_shop (iso_code) ON DELETE CASCADE
);

COMMENT ON TABLE  country_alias_shop          IS 'Shop: Freitext eines Landes -> ISO-Code';
COMMENT ON COLUMN country_alias_shop.alias    IS 'Freitext, bereinigt mit shop_country_key()';
COMMENT ON COLUMN country_alias_shop.iso_code IS 'Land (country_shop.iso_code)';
COMMENT ON COLUMN country_alias_shop.manual   IS 'vom Betreiber zugeordnet, nicht aus der Länderliste';

-- Bereinigt einen Freitext für den Vergleich: getrimmt, Leerräume
-- zusammengefasst, klein. „Deutschland " und „deutschland" sind dasselbe.
CREATE OR REPLACE FUNCTION shop_country_key(p_text text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT lower(regexp_replace(btrim(p_text), '\s+', ' ', 'g'))
$$;

-- Land zu einem Freitext, NULL wenn er keinem zugeordnet ist. Ein leerer
-- Freitext meint das Land des Mandanten (defaults.address_country), wie bei
-- Adressen ohne Land üblich.
CREATE OR REPLACE FUNCTION shop_country_code(p_text text) RETURNS text
    LANGUAGE sql STABLE AS $$
    SELECT a.iso_code
      FROM country_alias_shop a
     WHERE a.alias = shop_country_key(COALESCE(NULLIF(btrim(p_text), ''),
                                               (SELECT address_country FROM defaults LIMIT 1)))
$$;

-- ============================================================================
-- VERSAND (dev/shop-versand.md, Schritt 4)
-- ============================================================================
--
-- Versandarten mit Anbieter, Rang und Grenzen; Preise je Versandart, Kanal,
-- Länderzone, Gewichts- und Stückzahlstufe. Gewichte in der Einheit von
-- parts.weight (defaults.weightunit), Abmessungen in Zentimetern. Preise netto
-- oder brutto wie parts.sellprice laut shop_tax_included; gebucht wird über
-- den Versandartikel der Versandart (Erlöskonto, Steuer).
--
-- Gilt nur in den Verkaufskanälen, und dort nur, wo OSERP rechnet: eBay
-- rechnet den Versand selbst über die Versandrichtlinie (W4).

-- Freigrenze je Kanal (Entscheidung 4): ab diesem Warenwert (brutto, wie der
-- Kunde ihn sieht) ist der Versand frei — für Versandarten mit
-- free_shipping_applies. NULL = keine Freigrenze.
ALTER TABLE sales_channel_shop ADD COLUMN IF NOT EXISTS free_shipping_from numeric(15,5);
COMMENT ON COLUMN sales_channel_shop.free_shipping_from IS 'Versandfrei ab diesem Bruttowarenwert, NULL = keine Freigrenze';

-- Übernahme der bisherigen Einstellung in den HugoShop, einmalig: danach ist
-- der Schlüssel weg, ein zweiter Lauf findet nichts mehr.
UPDATE sales_channel_shop c
   SET free_shipping_from = replace(btrim(d.value), ',', '.')::numeric
  FROM defaults_oserp d
 WHERE d.key = 'shop_free_shipping_from'
   AND c.id = shop_first_channel_id('hugoshop')
   AND btrim(d.value) ~ '^[0-9]+([.,][0-9]+)?$';
DELETE FROM defaults_oserp WHERE key = 'shop_free_shipping_from';

CREATE TABLE IF NOT EXISTS shipping_method_shop
(
    id                         integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    description                text NOT NULL,
    vendor_id                  integer REFERENCES vendor (id) ON DELETE SET NULL,
    parts_id                   integer NOT NULL REFERENCES parts (id),
    rank                       integer NOT NULL DEFAULT 0,
    max_weight                 numeric(15,5),
    max_length                 numeric(15,5),
    max_girth                  numeric(15,5),
    free_shipping_applies      boolean NOT NULL DEFAULT true,
    ebay_fulfillment_policy_id text,
    active                     boolean NOT NULL DEFAULT true,
    itime                      timestamp without time zone DEFAULT now(),
    mtime                      timestamp without time zone
);

COMMENT ON TABLE  shipping_method_shop             IS 'Shop: Versandart';
COMMENT ON COLUMN shipping_method_shop.vendor_id   IS 'Anbieter (Lieferant), NULL = allgemeine Versandart';
COMMENT ON COLUMN shipping_method_shop.parts_id    IS 'Versandartikel (Dienstleistung, je Versandart ein eigener): Bezeichnung, Erlöskonto und Steuer der Versandposition';
COMMENT ON COLUMN shipping_method_shop.rank        IS 'Rang: bei verschiedenen zugeordneten Versandarten gilt die ranghöchste (W1)';
COMMENT ON COLUMN shipping_method_shop.max_weight  IS 'Höchstgewicht der Sendung in der Einheit von parts.weight, NULL = ohne Grenze';
COMMENT ON COLUMN shipping_method_shop.max_length  IS 'Längste Kante des größten Artikels in cm, NULL = ohne Grenze';
COMMENT ON COLUMN shipping_method_shop.max_girth   IS 'Gurtmaß des größten Artikels in cm (Länge + 2 × Breite + 2 × Höhe), NULL = ohne Grenze';
COMMENT ON COLUMN shipping_method_shop.free_shipping_applies IS 'Freigrenze des Kanals gilt für diese Versandart (W6)';
COMMENT ON COLUMN shipping_method_shop.ebay_fulfillment_policy_id IS 'eBay-Versandrichtlinie für Angebote mit dieser Versandart, NULL = die allgemeine';

-- Ein Versandartikel je Versandart (2026-10-02): die Maske der Versandart
-- verwaltet ihren Artikel — Nummer, Bezeichnung, Buchungsgruppe. Teilten sich
-- zwei Versandarten einen, änderte die eine die Rechnungsposition der anderen.
-- Teilen sich schon welche einen Artikel, scheitert der Index; dann bleibt er
-- weg, der Upstall meldet es, und die Zuordnung ist von Hand zu trennen.
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'shipping_method_shop_parts_id_key') THEN
        IF EXISTS (SELECT parts_id FROM shipping_method_shop GROUP BY parts_id HAVING count(*) > 1) THEN
            RAISE WARNING 'shipping_method_shop: mehrere Versandarten teilen sich einen Versandartikel — Index shipping_method_shop_parts_id_key nicht angelegt';
        ELSE
            CREATE UNIQUE INDEX shipping_method_shop_parts_id_key ON shipping_method_shop (parts_id);
        END IF;
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS shipping_zone_shop
(
    id          integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    description text NOT NULL,
    sortkey     integer NOT NULL DEFAULT 0
);

COMMENT ON TABLE shipping_zone_shop IS 'Shop: Länderzone für Versandpreise';

-- Ein Land gehört höchstens zu einer Zone (Schlüssel iso_code)
CREATE TABLE IF NOT EXISTS shipping_zone_country_shop
(
    iso_code text NOT NULL PRIMARY KEY REFERENCES country_shop (iso_code) ON DELETE CASCADE,
    zone_id  integer NOT NULL REFERENCES shipping_zone_shop (id) ON DELETE CASCADE
);

COMMENT ON TABLE shipping_zone_country_shop IS 'Shop: Land einer Versandzone, jedes Land höchstens einmal';

-- Preisstufen. Es gilt die Zeile mit dem genauesten Treffer: eigener Kanal vor
-- allen Kanälen, eigene Zone vor allen übrigen Ländern, dann die höchste
-- erreichte Gewichts- und Stückzahlstufe. Ohne passende Zeile liefert die
-- Versandart nicht dorthin.
CREATE TABLE IF NOT EXISTS shipping_rate_shop
(
    id                 integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    shipping_method_id integer NOT NULL REFERENCES shipping_method_shop (id) ON DELETE CASCADE,
    channel_id         integer REFERENCES sales_channel_shop (id) ON DELETE CASCADE,
    zone_id            integer REFERENCES shipping_zone_shop (id) ON DELETE CASCADE,
    weight_from        numeric(15,5) NOT NULL DEFAULT 0,
    qty_from           numeric(15,5) NOT NULL DEFAULT 0,
    price              numeric(15,5) NOT NULL
);

COMMENT ON TABLE  shipping_rate_shop             IS 'Shop: Versandpreis je Versandart, Kanal, Zone und Stufe';
COMMENT ON COLUMN shipping_rate_shop.channel_id  IS 'Verkaufskanal, NULL = alle';
COMMENT ON COLUMN shipping_rate_shop.zone_id     IS 'Länderzone, NULL = alle Länder ohne eigene Zeile';
COMMENT ON COLUMN shipping_rate_shop.weight_from IS 'gilt ab diesem Gesamtgewicht (Einheit von parts.weight)';
COMMENT ON COLUMN shipping_rate_shop.qty_from    IS 'gilt ab dieser Gesamtstückzahl';
COMMENT ON COLUMN shipping_rate_shop.price       IS 'Versandpreis, netto oder brutto wie parts.sellprice';

CREATE UNIQUE INDEX IF NOT EXISTS shipping_rate_shop_stufe_key
    ON shipping_rate_shop (shipping_method_id, COALESCE(channel_id, 0), COALESCE(zone_id, 0), weight_from, qty_from);

-- Lieferländer je Kanal (Entscheidung, Lieferländer begrenzen). Keine Zeile
-- für einen Kanal = keine Begrenzung. Bei eBay ohne Wirkung (W12).
CREATE TABLE IF NOT EXISTS sales_channel_country_shop
(
    channel_id integer NOT NULL REFERENCES sales_channel_shop (id) ON DELETE CASCADE,
    iso_code   text NOT NULL REFERENCES country_shop (iso_code) ON DELETE CASCADE,
    PRIMARY KEY (channel_id, iso_code)
);

COMMENT ON TABLE sales_channel_country_shop IS 'Shop: Lieferländer je Verkaufskanal, keine Zeile = alle';

-- Versandangaben je Artikel (Schritt 5). Das Gewicht bleibt in parts.weight.
CREATE TABLE IF NOT EXISTS parts_shipping_shop
(
    parts_id           integer NOT NULL PRIMARY KEY REFERENCES parts (id) ON DELETE CASCADE,
    shipping_method_id integer REFERENCES shipping_method_shop (id) ON DELETE SET NULL,
    length             numeric(15,5),
    width              numeric(15,5),
    height             numeric(15,5),
    min_qty            numeric(15,5),
    delivery_term_id   integer REFERENCES delivery_terms (id) ON DELETE SET NULL
);

COMMENT ON TABLE  parts_shipping_shop                    IS 'Shop: Versandangaben eines Artikels';
COMMENT ON COLUMN parts_shipping_shop.shipping_method_id IS 'Zugeordnete Versandart, NULL = die günstigste passende';
COMMENT ON COLUMN parts_shipping_shop.length             IS 'Länge in cm';
COMMENT ON COLUMN parts_shipping_shop.width              IS 'Breite in cm';
COMMENT ON COLUMN parts_shipping_shop.height             IS 'Höhe in cm';
COMMENT ON COLUMN parts_shipping_shop.min_qty            IS 'Mindestabnahme; bei eBay die Losgröße (W5, W10)';
COMMENT ON COLUMN parts_shipping_shop.delivery_term_id   IS 'Lieferbedingung (kivitendo delivery_terms), steht in Shop und Rechnung (Punkt 7)';

-- Lieferbedingung und Mindestabnahme stehen auf der Produktseite (HugoShop)
-- und bestimmen bei eBay Los und Angebot (W5, W11): ändern sie sich, wird
-- neu veröffentlicht — im HugoShop nur bei shop_auto_publish, wie bei Preis
-- und Texten (V22). Versandart und Abmessungen stehen auf keiner Seite, sie
-- entscheiden aber, ob eine Versandart passt (shop_part_shipping_check):
-- ohne passende wird die Seite zum Entwurf. Bei eBay bestimmt die Versandart
-- die Versandrichtlinie (W4).
CREATE OR REPLACE FUNCTION parts_shipping_shop_auto_publish() RETURNS trigger AS $$
BEGIN
    IF NOT shop_extension_active() THEN
        RETURN NULL;
    END IF;
    IF TG_OP = 'UPDATE'
       AND OLD.delivery_term_id IS NOT DISTINCT FROM NEW.delivery_term_id
       AND OLD.min_qty IS NOT DISTINCT FROM NEW.min_qty
       AND OLD.shipping_method_id IS NOT DISTINCT FROM NEW.shipping_method_id
       AND OLD.length IS NOT DISTINCT FROM NEW.length
       AND OLD.width IS NOT DISTINCT FROM NEW.width
       AND OLD.height IS NOT DISTINCT FROM NEW.height THEN
        RETURN NULL;
    END IF;

    PERFORM shop_queue_job('publish_part', p.partnumber, NULL, c.id)
       FROM parts p
       JOIN parts_channel_shop pc ON pc.parts_id = p.id AND pc.active
       JOIN sales_channel_shop c ON c.id = pc.channel_id AND c.active
      WHERE p.id = NEW.parts_id
        AND (c.type <> 'hugoshop' OR shop_auto_publish_enabled(c.id));
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    DROP TRIGGER IF EXISTS trigger_parts_shipping_shop_auto_publish ON parts_shipping_shop;
    CREATE TRIGGER trigger_parts_shipping_shop_auto_publish
        AFTER INSERT OR UPDATE OF delivery_term_id, min_qty, shipping_method_id, length, width, height
        ON parts_shipping_shop
        FOR EACH ROW
        EXECUTE FUNCTION parts_shipping_shop_auto_publish();
END $$;

-- Ist der Artikel ein Versandartikel — der einer Versandart? Versandartikel
-- sind keine Ware: sie zählen nicht im Warenkorb, nicht zum Warenwert und
-- nicht beim Versandgewicht.
CREATE OR REPLACE FUNCTION shop_is_shipping_part(p_parts_id integer) RETURNS boolean
    LANGUAGE sql STABLE AS $$
    SELECT EXISTS (SELECT 1 FROM shipping_method_shop m WHERE m.parts_id = p_parts_id)
$$;

-- Ist der Versand eingerichtet: eine aktive Versandart mit Preisstufe?
-- Ohne sie läuft jede Bestellung als „Standard" ohne Versandkosten, und die
-- Übersicht warnt (getShopStatus).
CREATE OR REPLACE FUNCTION shop_shipping_configured() RETURNS boolean
    LANGUAGE sql STABLE AS $$
    SELECT EXISTS (SELECT 1 FROM shipping_method_shop m
                    WHERE m.active
                      AND EXISTS (SELECT 1 FROM shipping_rate_shop r WHERE r.shipping_method_id = m.id))
$$;

-- ── Berechnung (dev/shop-versand.md, Schritt 6) ──
--
-- Versandart und Preis eines HugoShop-Warenkorbs. Eine Stelle für Warenkorb,
-- PayPal und Rechnung — alle sehen denselben Betrag. Kanal ist der HugoShop
-- des Warenkorbs: Preisstufen, Lieferländer und Freigrenze gelten je Kanal.
--
--   p_cart_uuid    Warenkorb
--   p_country      Land der Lieferadresse als Freitext oder Code; leer =
--                  Mandantenland (shop_country_code)
--   p_goods_value  Bruttowarenwert für die Freigrenze (wie der Kunde ihn sieht)
--
-- Regeln:
--   - Ware: alle Zeilen außer Versandartikeln (die der Versandarten).
--   - Keine aktive Versandart mit Preisstufe (shop_shipping_configured): die
--     Bestellung läuft als „Standard" ohne Versandkosten, ohne Versandartikel
--     — die Rechnung bekommt keine Versandposition. Lieferländer gelten
--     trotzdem.
--   - Haben Artikel eine aktive Versandart zugeordnet, gilt die ranghöchste
--     davon (W1) — passt sie nicht, ist keine Bestellung möglich (W2). Ohne
--     Zuordnung gilt die günstigste passende aktive (Entscheidung 2).
--   - Eine Versandart passt, wenn Gewicht, längste Kante und Gurtmaß des
--     größten Artikels in ihre Grenzen fallen und es eine Preisstufe für Kanal
--     und Land gibt. Fehlende Abmessungen gelten als passend; fehlt einer
--     Ware das Gewicht, passen nur Versandarten, die keins brauchen (keine
--     Gewichtsgrenze, keine Stufe ab einem Gewicht > 0) — sonst „Versand auf
--     Anfrage" (W8, W9).
--   - Preisstufe: eigener Kanal vor allen, eigene Zone vor allen übrigen
--     Ländern, dann die höchste erreichte Gewichts- und Stückzahlstufe.
--   - Freigrenze des Kanals: ab dem Wert ist der Versand frei, wenn die
--     Versandart sie gelten lässt (W6, W13).
--   - Lieferländer des Kanals: ist das Land nicht darunter, keine Lieferung.
--
-- status: ok, no_goods, country_unknown, country_not_delivered,
-- weight_missing (Versand auf Anfrage), assigned_unfit, no_method.
CREATE OR REPLACE FUNCTION shop_cart_shipping(p_cart_uuid text, p_country text, p_goods_value numeric)
RETURNS TABLE (status text, shipping_method_id integer, description text, parts_id integer,
               price numeric, free boolean)
LANGUAGE plpgsql STABLE AS $$
#variable_conflict use_column
DECLARE
    -- Der Warenkorb gehört einem HugoShop (carts_hugoshop.channel_id)
    kanal        integer := (SELECT k.channel_id FROM carts_hugoshop k WHERE k.uuid = p_cart_uuid);
    land         text    := shop_country_code(p_country);
    zone         integer;
    menge        numeric;
    gewicht      numeric;
    ohne_gewicht boolean;
    kante        numeric;
    gurt         numeric;
    zugeordnet   integer;
    gewaehlt     record;
BEGIN
    -- Ware und ihre Kennzahlen
    SELECT COALESCE(sum(c.amount), 0),
           COALESCE(sum(c.amount * COALESCE(p.weight, 0)), 0),
           bool_or(p.part_type <> 'service' AND COALESCE(p.weight, 0) <= 0),
           max(GREATEST(ps.length, ps.width, ps.height)),
           max(GREATEST(ps.length, ps.width, ps.height)
               + 2 * (COALESCE(ps.length, 0) + COALESCE(ps.width, 0) + COALESCE(ps.height, 0)
                      - GREATEST(ps.length, ps.width, ps.height))),
           (SELECT m.id
              FROM cart_parts_hugoshop c2
              JOIN parts_shipping_shop ps2 ON ps2.parts_id = c2.parts_id
              JOIN shipping_method_shop m ON m.id = ps2.shipping_method_id AND m.active
             WHERE c2.cart_uuid = p_cart_uuid
             ORDER BY m.rank DESC, m.id
             LIMIT 1)
      INTO menge, gewicht, ohne_gewicht, kante, gurt, zugeordnet
      FROM cart_parts_hugoshop c
      JOIN parts p ON p.id = c.parts_id
      LEFT JOIN parts_shipping_shop ps ON ps.parts_id = p.id
     WHERE c.cart_uuid = p_cart_uuid
       AND NOT shop_is_shipping_part(c.parts_id);

    IF menge <= 0 THEN
        RETURN QUERY SELECT 'no_goods'::text, NULL::integer, NULL::text, NULL::integer, 0::numeric, false;
        RETURN;
    END IF;
    IF land IS NULL THEN
        RETURN QUERY SELECT 'country_unknown'::text, NULL::integer, NULL::text, NULL::integer, 0::numeric, false;
        RETURN;
    END IF;
    IF EXISTS (SELECT 1 FROM sales_channel_country_shop cc WHERE cc.channel_id = kanal)
       AND NOT EXISTS (SELECT 1 FROM sales_channel_country_shop cc
                        WHERE cc.channel_id = kanal AND cc.iso_code = land) THEN
        RETURN QUERY SELECT 'country_not_delivered'::text, NULL::integer, NULL::text, NULL::integer, 0::numeric, false;
        RETURN;
    END IF;
    IF NOT shop_shipping_configured() THEN
        RETURN QUERY SELECT 'ok'::text, NULL::integer, 'Standard'::text, NULL::integer, 0::numeric, true;
        RETURN;
    END IF;
    SELECT zc.zone_id INTO zone FROM shipping_zone_country_shop zc WHERE zc.iso_code = land;

    -- Je Versandart (die zugeordnete oder alle aktiven): passt sie, und zu
    -- welchem Preis
    SELECT m.id, m.description, m.parts_id, m.rank, m.free_shipping_applies, stufe.price,
           (ohne_gewicht AND (m.max_weight IS NOT NULL
                              OR EXISTS (SELECT 1 FROM shipping_rate_shop r
                                          WHERE r.shipping_method_id = m.id AND r.weight_from > 0))) AS braucht_gewicht
      INTO gewaehlt
      FROM shipping_method_shop m
      LEFT JOIN LATERAL (
           SELECT r.price
             FROM shipping_rate_shop r
            WHERE r.shipping_method_id = m.id
              AND (r.channel_id IS NULL OR r.channel_id = kanal)
              AND (r.zone_id IS NULL OR r.zone_id = zone)
              AND r.weight_from <= gewicht
              AND r.qty_from <= menge
            ORDER BY (r.channel_id IS NOT NULL) DESC, (r.zone_id IS NOT NULL) DESC,
                     r.weight_from DESC, r.qty_from DESC
            LIMIT 1
      ) stufe ON true
     WHERE m.active
       AND (zugeordnet IS NULL OR m.id = zugeordnet)
       AND stufe.price IS NOT NULL
       AND (m.max_weight IS NULL OR gewicht <= m.max_weight)
       AND (m.max_length IS NULL OR kante IS NULL OR kante <= m.max_length)
       AND (m.max_girth IS NULL OR gurt IS NULL OR gurt <= m.max_girth)
       AND NOT (ohne_gewicht AND (m.max_weight IS NOT NULL
                                  OR EXISTS (SELECT 1 FROM shipping_rate_shop r
                                              WHERE r.shipping_method_id = m.id AND r.weight_from > 0)))
     ORDER BY stufe.price, m.rank DESC, m.id
     LIMIT 1;

    IF gewaehlt.id IS NULL THEN
        -- Warum keine passt: fehlendes Gewicht hat Vorrang (Versand auf
        -- Anfrage), dann die feste Zuordnung
        IF ohne_gewicht AND EXISTS (
               SELECT 1 FROM shipping_method_shop m
                WHERE m.active AND (zugeordnet IS NULL OR m.id = zugeordnet)
                  AND (m.max_weight IS NOT NULL
                       OR EXISTS (SELECT 1 FROM shipping_rate_shop r
                                   WHERE r.shipping_method_id = m.id AND r.weight_from > 0))) THEN
            RETURN QUERY SELECT 'weight_missing'::text, NULL::integer, NULL::text, NULL::integer, 0::numeric, false;
        ELSIF zugeordnet IS NOT NULL THEN
            RETURN QUERY SELECT 'assigned_unfit'::text, zugeordnet,
                                (SELECT m.description FROM shipping_method_shop m WHERE m.id = zugeordnet),
                                NULL::integer, 0::numeric, false;
        ELSE
            RETURN QUERY SELECT 'no_method'::text, NULL::integer, NULL::text, NULL::integer, 0::numeric, false;
        END IF;
        RETURN;
    END IF;

    RETURN QUERY
    SELECT 'ok'::text, gewaehlt.id, gewaehlt.description, gewaehlt.parts_id,
           CASE WHEN frei THEN 0::numeric ELSE gewaehlt.price END, frei
      FROM (SELECT gewaehlt.free_shipping_applies
                   AND (SELECT c.free_shipping_from FROM sales_channel_shop c WHERE c.id = kanal) IS NOT NULL
                   AND p_goods_value >= (SELECT c.free_shipping_from FROM sales_channel_shop c WHERE c.id = kanal)
                   AS frei) f;
END;
$$;

-- Lieferbedingung einer Rechnungsposition, wie sie verschickt wurde
-- (dev/shop-versand.md, Punkt 7, Weg a): Die Rechnung traegt sie im Langtext
-- der Position. Gezeigt wird der Text der Lieferbedingung des Artikels nur,
-- wenn er dort steht — sonst sagte die Rechnungsseite nach einer spaeteren
-- Aenderung etwas anderes als die Rechnung. NULL = keine.
CREATE OR REPLACE FUNCTION shop_invoice_delivery_term(p_invoice_id integer) RETURNS text
    LANGUAGE sql STABLE AS $$
    SELECT t.text
      FROM invoice i
      JOIN parts_shipping_shop ps ON ps.parts_id = i.parts_id
      JOIN delivery_terms d ON d.id = ps.delivery_term_id
      CROSS JOIN LATERAL (SELECT COALESCE(NULLIF(btrim(d.description_long), ''), d.description) AS text) t
     WHERE i.id = p_invoice_id
       AND COALESCE(t.text, '') <> ''
       AND strpos(COALESCE(i.longdescription, ''), t.text) > 0
$$;

-- Passt eine Versandart zum Artikel? Für die Veröffentlichung im HugoShop
-- (dev/shop-versand.md): ohne passende Versandart scheitert der Auftrag, und
-- eine schon veröffentlichte Seite wird zum Entwurf.
--
-- Die Regeln von shop_cart_shipping() für einen Warenkorb aus nur diesem
-- Artikel in seiner Mindestabnahme (sonst 1 Stück). Das Lieferland ist noch
-- unbekannt: es genügt eine Preisstufe für den Kanal in irgendeiner Zone.
-- Ein Warenkorb aus mehreren Artikeln kann trotzdem eine Grenze
-- überschreiten — das meldet erst der Warenkorb.
--
-- status: ok, weight_missing, assigned_unfit, no_method. Ohne eingerichtete
-- Versandart ok (Versand „Standard" ohne Kosten). method nennt die
-- zugeordnete Versandart; weight, length, girth und weightunit die Werte, an
-- denen gemessen wurde — für die Meldung.
CREATE OR REPLACE FUNCTION shop_part_shipping_check(p_parts_id integer, p_channel_id integer)
RETURNS TABLE (status text, method text, weight numeric, length numeric, girth numeric, weightunit text)
LANGUAGE plpgsql STABLE AS $$
#variable_conflict use_column
DECLARE
    menge        numeric;
    gewicht      numeric;
    ohne_gewicht boolean;
    kante        numeric;
    gurt         numeric;
    zugeordnet   integer;
    einheit      text := (SELECT d.weightunit FROM defaults d LIMIT 1);
BEGIN
    SELECT GREATEST(COALESCE(ps.min_qty, 1), 1),
           GREATEST(COALESCE(ps.min_qty, 1), 1) * COALESCE(p.weight, 0),
           p.part_type <> 'service' AND COALESCE(p.weight, 0) <= 0,
           GREATEST(ps.length, ps.width, ps.height),
           GREATEST(ps.length, ps.width, ps.height)
               + 2 * (COALESCE(ps.length, 0) + COALESCE(ps.width, 0) + COALESCE(ps.height, 0)
                      - GREATEST(ps.length, ps.width, ps.height)),
           (SELECT m.id FROM shipping_method_shop m WHERE m.id = ps.shipping_method_id AND m.active)
      INTO menge, gewicht, ohne_gewicht, kante, gurt, zugeordnet
      FROM parts p
      LEFT JOIN parts_shipping_shop ps ON ps.parts_id = p.id
     WHERE p.id = p_parts_id;

    -- Unbekannter Artikel, Versandartikel oder Versand nicht eingerichtet:
    -- nichts zu prüfen
    IF menge IS NULL OR shop_is_shipping_part(p_parts_id) OR NOT shop_shipping_configured() THEN
        RETURN QUERY SELECT 'ok'::text, NULL::text, gewicht, kante, gurt, einheit;
        RETURN;
    END IF;

    IF EXISTS (
        SELECT 1
          FROM shipping_method_shop m
         WHERE m.active
           AND (zugeordnet IS NULL OR m.id = zugeordnet)
           AND (m.max_weight IS NULL OR gewicht <= m.max_weight)
           AND (m.max_length IS NULL OR kante IS NULL OR kante <= m.max_length)
           AND (m.max_girth IS NULL OR gurt IS NULL OR gurt <= m.max_girth)
           AND EXISTS (SELECT 1 FROM shipping_rate_shop r
                        WHERE r.shipping_method_id = m.id
                          AND (r.channel_id IS NULL OR r.channel_id = p_channel_id)
                          AND r.weight_from <= gewicht
                          AND r.qty_from <= menge)
           AND NOT (ohne_gewicht AND (m.max_weight IS NOT NULL
                                      OR EXISTS (SELECT 1 FROM shipping_rate_shop r
                                                  WHERE r.shipping_method_id = m.id AND r.weight_from > 0)))
    ) THEN
        RETURN QUERY SELECT 'ok'::text, NULL::text, gewicht, kante, gurt, einheit;
        RETURN;
    END IF;

    -- Warum keine passt — in derselben Reihenfolge wie im Warenkorb
    IF ohne_gewicht AND EXISTS (
           SELECT 1 FROM shipping_method_shop m
            WHERE m.active AND (zugeordnet IS NULL OR m.id = zugeordnet)
              AND (m.max_weight IS NOT NULL
                   OR EXISTS (SELECT 1 FROM shipping_rate_shop r
                               WHERE r.shipping_method_id = m.id AND r.weight_from > 0))) THEN
        RETURN QUERY SELECT 'weight_missing'::text, NULL::text, gewicht, kante, gurt, einheit;
    ELSIF zugeordnet IS NOT NULL THEN
        RETURN QUERY SELECT 'assigned_unfit'::text,
                            (SELECT m.description FROM shipping_method_shop m WHERE m.id = zugeordnet),
                            gewicht, kante, gurt, einheit;
    ELSE
        RETURN QUERY SELECT 'no_method'::text, NULL::text, gewicht, kante, gurt, einheit;
    END IF;
END;
$$;

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

-- Ausgabe eines Laufs der Veröffentlichung (tools/shop-publish.php, „Jetzt
-- ausführen“). Angelegt am Ende eines Laufs, der Aufträge erledigt hat; die
-- Aufträge verweisen über run_id darauf. Das Admin-Panel zeigt die Ausgabe
-- beim Klick auf den Status eines Auftrags. Verweist kein Auftrag mehr auf
-- einen Lauf, entfernt ihn der Trigger unten.
CREATE TABLE IF NOT EXISTS batchjob_run_hugoshop
(
    id       integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    itime    timestamp without time zone DEFAULT now(),
    finished timestamp without time zone DEFAULT now(),
    output   text NOT NULL DEFAULT ''
);

COMMENT ON TABLE  batchjob_run_hugoshop          IS 'Shop: Ausgabe eines Laufs der Veröffentlichung';
COMMENT ON COLUMN batchjob_run_hugoshop.itime    IS 'Beginn des Laufs';
COMMENT ON COLUMN batchjob_run_hugoshop.finished IS 'Ende des Laufs';
COMMENT ON COLUMN batchjob_run_hugoshop.output   IS 'Meldungen des Laufs, eine je Zeile';

CREATE TABLE IF NOT EXISTS batchjob_hugoshop
(
    id         integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    itime      timestamp without time zone DEFAULT now(),
    function   text NOT NULL,
    partnumber text NOT NULL,
    param      text DEFAULT NULL,
    result     text DEFAULT NULL,
    channel_id integer DEFAULT NULL,
    run_id     integer DEFAULT NULL REFERENCES batchjob_run_hugoshop (id) ON DELETE SET NULL
);

-- Auf einer bestehenden Datenbank (wie parts_channel_shop.unavailable)
ALTER TABLE batchjob_hugoshop ADD COLUMN IF NOT EXISTS run_id integer DEFAULT NULL
    REFERENCES batchjob_run_hugoshop (id) ON DELETE SET NULL;

-- Wann ein Auftrag entstand. Name nach kivitendo-Brauch (itime). Auf einer
-- bestehenden Datenbank traegt der Upstall die Spalte mit Vorgabewert nach.
COMMENT ON COLUMN batchjob_hugoshop.itime IS 'Zeitpunkt, zu dem der Auftrag angelegt wurde';

COMMENT ON TABLE batchjob_hugoshop IS 'Shop: Warteschlange fuer Aufgaben, die auf dem Shop-Server laufen';

-- Verkaufskanal des Auftrags (dev/shop-verkaufskanaele.md, Schritt 4).
-- Früher hieß NULL „HugoShop"; mit mehreren HugoShops ist das nicht mehr
-- eindeutig. Der Abschnitt INSTANZEN am Ende trägt den Kanal nach, setzt die
-- Vorgabe für Schreiber ohne Kanal und legt Fremdschlüssel und NOT NULL an.
COMMENT ON COLUMN batchjob_hugoshop.run_id IS 'Lauf, in dem der Auftrag erledigt wurde (batchjob_run_hugoshop.id)';

CREATE INDEX IF NOT EXISTS batchjob_hugoshop_run_id_idx ON batchjob_hugoshop (run_id);

-- Ein Lauf lebt so lange wie seine Aufträge: sind alle gelöscht (Aufräumen,
-- Löschen im Admin-Panel, Aufbewahrungsfrist im Cron), geht seine Ausgabe
-- mit. Je Anweisung statt je Zeile — Aufräumen löscht viele Aufträge auf
-- einmal. Ein Lauf entsteht erst an seinem Ende, zusammen mit dem Verweis
-- der Aufträge; einen laufenden kann der Trigger also nicht treffen.
CREATE OR REPLACE FUNCTION cleanup_batchjob_run_hugoshop() RETURNS trigger AS $$
BEGIN
    DELETE FROM batchjob_run_hugoshop r
     WHERE NOT EXISTS (SELECT 1 FROM batchjob_hugoshop b WHERE b.run_id = r.id);
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trigger_cleanup_batchjob_run_hugoshop') THEN
        CREATE TRIGGER trigger_cleanup_batchjob_run_hugoshop
            AFTER DELETE ON batchjob_hugoshop
            FOR EACH STATEMENT EXECUTE FUNCTION cleanup_batchjob_run_hugoshop();
    END IF;
END $$;

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
-- Hier stehen nur Einstellungen fuer den ganzen Mandanten. Was einer Instanz
-- gehoert — Shop-Schluessel, Adressen und Verzeichnisse der Webseite,
-- Vorlagensatz, HugoCMS, PayPal, Mails, eBay-Zugang —, steht seit Schritt 5
-- (dev/shop-mehrere-kanaele.md) in den Einstellungen des Kanals und wird in
-- der Kanalkarte gepflegt; Vorgaben fuer neue Kanaele liefert
-- shop_channel_default_settings().

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
-- shop_shipping_partnumber gibt es nicht mehr: jede Versandart hat ihren
-- eigenen Versandartikel; ohne Versandart läuft der Versand als „Standard"
-- ohne Kosten (shop_cart_shipping)
DELETE FROM defaults_oserp WHERE key = 'shop_shipping_partnumber';
-- shop_free_shipping_from gibt es nicht mehr: die Freigrenze steht je Kanal in
-- sales_channel_shop.free_shipping_from (Abschnitt VERSAND)

-- Bankverbindung fuer die Zahlungsaufforderung auf der Rechnungsseite
INSERT INTO defaults_oserp (key, value) VALUES ('shop_payment_account_owner', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_payment_bank', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_payment_iban', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_payment_bic', '') ON CONFLICT (key) DO NOTHING;

-- PayPal, Uebergang: die erste Fassung dieser Erweiterung kannte nur ein
-- Zugangsdatenpaar. Wo es gefuellt ist, wandert es in die
-- Echtbetrieb-Schluessel — von dort uebernimmt es die Abschrift in den
-- HugoShop (Abschnitt INSTANZEN). Laeuft auch dann durch, wenn es die
-- Zeilen nie gab.
UPDATE defaults_oserp z SET value = a.value, mtime = now()
  FROM defaults_oserp a
 WHERE a.key = 'shop_paypal_client_id' AND COALESCE(a.value, '') <> ''
   AND z.key = 'shop_paypal_live_client_id' AND COALESCE(z.value, '') = '';
UPDATE defaults_oserp z SET value = a.value, mtime = now()
  FROM defaults_oserp a
 WHERE a.key = 'shop_paypal_secret' AND COALESCE(a.value, '') <> ''
   AND z.key = 'shop_paypal_live_secret' AND COALESCE(z.value, '') = '';
DELETE FROM defaults_oserp WHERE key IN ('shop_paypal_client_id', 'shop_paypal_secret');

-- Veroeffentlichung: Wurzel aller Webseiten. Die Webseiten-Verzeichnisse der
-- HugoShops gelten relativ dazu und duerfen nicht darueber hinausfuehren.
-- Steht in der settings.ini ein shop_sites_dir, muss die eingestellte Wurzel
-- darunter liegen — so kann ein Administrator die Grenze ziehen.
--
-- Gebaut wird mit dem Programm hugo aus dem Verzeichnis
-- shop_publish_command_path. Eingetragen wird nur ein Pfad, keine
-- Befehlszeile: die Argumente setzt OpensourceERP selbst, und der Pfad wird
-- vor jedem Bau geprueft (absolut, ohne Leerraum, ausfuehrbare Datei). Ist der
-- Wert hier leer, gilt ein gleichnamiger Eintrag aus der settings.ini als
-- Rueckfall.
INSERT INTO defaults_oserp (key, value) VALUES ('shop_sites_dir', '') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_publish_command_path', '') ON CONFLICT (key) DO NOTHING;
-- shop_job_retention_days: Der Laeufer loescht erfolgreich erledigte Auftraege
-- aus batchjob_hugoshop, sobald sie so viele Tage alt sind. 0 schaltet das ab;
-- fehlgeschlagene Auftraege bleiben immer stehen.
INSERT INTO defaults_oserp (key, value) VALUES ('shop_job_retention_days', '30') ON CONFLICT (key) DO NOTHING;
-- Groesse der Vorschaubilder (laengste Seite in Pixeln), fuer alle HugoShops
INSERT INTO defaults_oserp (key, value) VALUES ('shop_thumbnail_size', '200') ON CONFLICT (key) DO NOTHING;
-- eBay: Adresse von OpensourceERP, unter der eBay die Artikelbilder abholt
-- (backend/webhook/part-image.php, https) — fuer alle eBay-Kanaele dieselbe.
-- Der Laeufer arbeitet ohne Webanfrage und kennt die eigene Adresse sonst nicht.
INSERT INTO defaults_oserp (key, value) VALUES ('ebay_public_host', '') ON CONFLICT (key) DO NOTHING;
-- Lagerplatz, von dem Verkäufe aus HugoShop und eBay ausgebucht werden
-- (O14, V28). Leer = keine Lagerbuchung.
INSERT INTO defaults_oserp (key, value) VALUES ('shop_stock_bin_id', '') ON CONFLICT (key) DO NOTHING;

-- Suche: Gewichtung des Preises im Ranking
INSERT INTO defaults_oserp (key, value) VALUES ('shop_search_weighting', '0.5') ON CONFLICT (key) DO NOTHING;

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
-- und damit der Steuersatz sind seine Entscheidung. Er wird in der Ansicht
-- „Versandarten" je Versandart gewaehlt (dev/shop-versand.md). Ohne
-- Versandart braucht der Shop keinen: der Versand laeuft dann als „Standard"
-- ohne Kosten, und getShopStatus() warnt.

-- ============================================================================
-- INSTANZEN (dev/shop-mehrere-kanaele.md, Schritt 1)
-- ============================================================================
--
-- Tabellen, die einer Instanz gehören, tragen ihren Kanal. Am Ende dieser
-- Datei, weil der Nachtrag shop_first_channel_id() braucht und die
-- Einstellungen unten aus dem Abschnitt EINSTELLUNGEN stammen.
--
-- Löschen eines Kanals (M5): Warenkörbe, Sitzungen, Weiterleitungen und
-- Aufträge gehen mit (CASCADE); Rechnungslinks, Widerrufe und
-- eBay-Bestellungen verhindern es (NO ACTION) — ein Kanal mit Belegen wird
-- nur abgeschaltet.
--
-- Jeder Schreiber setzt den Kanal selbst; eine Vorgabe gibt es nicht (mehr —
-- bis Schritt 6 stand dort shop_channel_id('<art>') als Übergang). Zeilen
-- ohne Kanal aus der Zeit davor bekommen den ersten Kanal ihrer Art.
DO $$
DECLARE
    t     record;
    offen bigint;
BEGIN
    FOR t IN SELECT * FROM (VALUES
            ('carts_hugoshop',          'hugoshop', 'CASCADE',   'HugoShop des Warenkorbs'),
            ('context_hugoshop',        'hugoshop', 'CASCADE',   'HugoShop der Sitzung'),
            ('redirect_pages_hugoshop', 'hugoshop', 'CASCADE',   'Webseite (HugoShop) der Weiterleitung'),
            ('batchjob_hugoshop',       'hugoshop', 'CASCADE',   'Verkaufskanal des Auftrags'),
            ('ar_link_hugoshop',        'hugoshop', 'NO ACTION', 'HugoShop, aus dem die Rechnung stammt: Mail, Rechnungsseite, PayPal-Rücksprung'),
            ('withdrawals_hugoshop',    'hugoshop', 'NO ACTION', 'HugoShop, über den widerrufen wurde'),
            ('ebay_orders',             'ebay',     'NO ACTION', 'eBay-Kanal, von dem die Bestellung stammt')
         ) AS v(tabelle, art, loeschen, beschreibung)
    LOOP
        -- ebay_orders gehört der CRM-Basis und fehlt, wenn sie älter ist
        CONTINUE WHEN to_regclass(t.tabelle) IS NULL;

        EXECUTE format('ALTER TABLE %I ADD COLUMN IF NOT EXISTS channel_id integer', t.tabelle);
        EXECUTE format('ALTER TABLE %I ALTER COLUMN channel_id DROP DEFAULT', t.tabelle);
        EXECUTE format('UPDATE %I SET channel_id = shop_first_channel_id(%L) WHERE channel_id IS NULL', t.tabelle, t.art);
        EXECUTE format('COMMENT ON COLUMN %I.channel_id IS %L', t.tabelle,
                       t.beschreibung || ' (sales_channel_shop.id)');
        EXECUTE format('CREATE INDEX IF NOT EXISTS %I ON %I (channel_id)', t.tabelle || '_channel_id_idx', t.tabelle);

        -- Verweise auf Kanäle, die es nicht gibt, werden nur gemeldet: das
        -- Schema-Update soll daran nicht scheitern, aufräumen muss ein Mensch
        IF NOT EXISTS (SELECT 1 FROM pg_constraint
                        WHERE conrelid = t.tabelle::regclass
                          AND conname = t.tabelle || '_channel_id_fk') THEN
            EXECUTE format('SELECT count(*) FROM %I x
                             WHERE x.channel_id IS NOT NULL
                               AND NOT EXISTS (SELECT 1 FROM sales_channel_shop c WHERE c.id = x.channel_id)',
                           t.tabelle) INTO offen;
            IF offen > 0 THEN
                RAISE NOTICE '%: % Zeilen verweisen auf keinen Kanal — Fremdschlüssel nicht angelegt. Bitte bereinigen.',
                    t.tabelle, offen;
            ELSE
                EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I FOREIGN KEY (channel_id)
                                REFERENCES sales_channel_shop (id) ON DELETE %s',
                               t.tabelle, t.tabelle || '_channel_id_fk', t.loeschen);
            END IF;
        END IF;

        -- Ohne Kanal der Art (gelöscht) bleiben Zeilen leer — dann kein NOT NULL
        EXECUTE format('SELECT count(*) FROM %I WHERE channel_id IS NULL', t.tabelle) INTO offen;
        IF offen = 0 THEN
            EXECUTE format('ALTER TABLE %I ALTER COLUMN channel_id SET NOT NULL', t.tabelle);
        ELSE
            RAISE NOTICE '%: % Zeilen ohne Kanal — channel_id bleibt ohne NOT NULL.', t.tabelle, offen;
        END IF;
    END LOOP;
END $$;

-- Die Fassungen mit der Art als Text (Übergang bis Schritt 6) entfernen —
-- erst hier: bis zum Block oben hingen die Spaltenvorgaben an
-- shop_channel_id(text).
DROP FUNCTION IF EXISTS shop_queue_job(text, text, text, text);
DROP FUNCTION IF EXISTS shop_part_available(integer, text);
DROP FUNCTION IF EXISTS shop_channel_price(integer, text);
DROP FUNCTION IF EXISTS shop_active_channel_id(text);
DROP FUNCTION IF EXISTS shop_channel_id(text);
DROP FUNCTION IF EXISTS shop_channel_type_check(text);

-- Einstellungen einer Instanz: welcher Schlüssel aus defaults_oserp wohin
-- gehört. key ist der Name in settings bzw. sales_channel_secret_shop (ohne
-- Präfix), secret entscheidet zwischen beiden. Was hier fehlt, gilt für den
-- ganzen Mandanten und bleibt in defaults_oserp (Liste in
-- dev/shop-mehrere-kanaele.md).
CREATE OR REPLACE FUNCTION shop_channel_setting_keys()
RETURNS TABLE (type text, old_key text, key text, secret boolean)
    LANGUAGE sql IMMUTABLE AS $$
    VALUES
        -- HugoShop: Zugang und Webseite
        ('hugoshop', 'shop_public_key',                       'public_key',                       true),
        ('hugoshop', 'shop_allowed_origins',                  'allowed_origins',                  false),
        ('hugoshop', 'shop_base_url',                         'base_url',                         false),
        ('hugoshop', 'shop_backend_url',                      'backend_url',                      false),
        ('hugoshop', 'shop_products_link',                    'products_link',                    false),
        ('hugoshop', 'shop_category_link',                    'category_link',                    false),
        ('hugoshop', 'shop_images_link',                      'images_link',                      false),
        ('hugoshop', 'shop_thumbnails_link',                  'thumbnails_link',                  false),
        ('hugoshop', 'shop_downloads_link',                   'downloads_link',                   false),
        -- HugoShop: Veröffentlichung
        ('hugoshop', 'shop_site_dir',                         'site_dir',                         false),
        ('hugoshop', 'shop_content_dir',                      'content_dir',                      false),
        ('hugoshop', 'shop_images_dir',                       'images_dir',                       false),
        ('hugoshop', 'shop_thumbnails_dir',                   'thumbnails_dir',                   false),
        ('hugoshop', 'shop_template_set',                     'template_set',                     false),
        ('hugoshop', 'shop_publish_mode',                     'publish_mode',                     false),
        ('hugoshop', 'shop_publish_clean_destination',        'publish_clean_destination',        false),
        ('hugoshop', 'shop_hugocms_url',                      'hugocms_url',                      false),
        ('hugoshop', 'shop_hugocms_key',                      'hugocms_key',                      true),
        ('hugoshop', 'shop_auto_publish',                     'auto_publish',                     false),
        ('hugoshop', 'shop_channel_off_pages',                'channel_off_pages',                false),
        -- HugoShop: Mail und PayPal (M2)
        ('hugoshop', 'shop_invoice_mail_subject',             'invoice_mail_subject',             false),
        ('hugoshop', 'shop_withdrawal_mail_to',               'withdrawal_mail_to',               false),
        ('hugoshop', 'shop_paypal_sandbox',                   'paypal_sandbox',                   false),
        ('hugoshop', 'shop_paypal_live_client_id',            'paypal_live_client_id',            false),
        ('hugoshop', 'shop_paypal_live_secret',               'paypal_live_secret',               true),
        ('hugoshop', 'shop_paypal_sandbox_client_id',         'paypal_sandbox_client_id',         false),
        ('hugoshop', 'shop_paypal_sandbox_secret',            'paypal_sandbox_secret',            true),
        ('hugoshop', 'shop_paypal_payment_method_preference', 'paypal_payment_method_preference', false),
        ('hugoshop', 'shop_paypal_mock_response',             'paypal_mock_response',             false),
        -- eBay: Zugang. Token-Cache (access_token, access_token_exp) und
        -- letzter Abruf (order_last_check) schreibt der Kanal seit Schritt 4
        -- selbst — eine Abschrift aus defaults_oserp überschriebe sie mit
        -- alten Werten.
        ('ebay',     'ebay_client_id',                        'client_id',                        false),
        ('ebay',     'ebay_client_secret',                    'client_secret',                    true),
        ('ebay',     'ebay_refresh_token',                    'refresh_token',                    true),
        ('ebay',     'ebay_environment',                      'environment',                      false),
        -- eBay: Angebote und Bestellungen
        ('ebay',     'ebay_marketplace_id',                   'marketplace_id',                   false),
        ('ebay',     'ebay_content_language',                 'content_language',                 false),
        ('ebay',     'ebay_currency',                         'currency',                         false),
        ('ebay',     'ebay_default_category_id',              'default_category_id',              false),
        ('ebay',     'ebay_default_condition',                'default_condition',                false),
        ('ebay',     'ebay_fulfillment_policy_id',            'fulfillment_policy_id',            false),
        ('ebay',     'ebay_payment_policy_id',                'payment_policy_id',                false),
        ('ebay',     'ebay_return_policy_id',                 'return_policy_id',                 false),
        ('ebay',     'ebay_merchant_location_key',            'merchant_location_key',            false),
        ('ebay',     'ebay_default_parts_id',                 'default_parts_id',                 false),
        ('ebay',     'ebay_employee_login',                   'employee_login',                   false)
$$;

-- Abschrift in den Standardkanal der Art: übernimmt die Werte, die bis
-- Schritt 5 im Reiter „Shop" unter den alten Schlüsseln gepflegt wurden.
-- Danach löscht der Abschnitt unten die Schlüssel — jedes weitere
-- Schema-Update findet hier nichts mehr. Bleibt für Datenbanken, die von
-- einem älteren Stand direkt hierher aktualisiert werden.
UPDATE sales_channel_shop c
   SET settings = COALESCE(c.settings, '{}'::jsonb) || w.werte
  FROM (SELECT shop_first_channel_id(m.type) AS kanal,
               jsonb_object_agg(m.key, COALESCE(d.value, '')) AS werte
          FROM shop_channel_setting_keys() m
          JOIN defaults_oserp d ON d.key = m.old_key
         WHERE NOT m.secret
         GROUP BY 1) w
 WHERE c.id = w.kanal;

INSERT INTO sales_channel_secret_shop (channel_id, key, value)
SELECT shop_first_channel_id(m.type), m.key, d.value
  FROM shop_channel_setting_keys() m
  JOIN defaults_oserp d ON d.key = m.old_key
 WHERE m.secret
   AND COALESCE(d.value, '') <> ''
   AND shop_first_channel_id(m.type) IS NOT NULL
ON CONFLICT (channel_id, key) DO UPDATE
   SET value = EXCLUDED.value, mtime = now()
 WHERE sales_channel_secret_shop.value IS DISTINCT FROM EXCLUDED.value;

-- Ein in defaults_oserp geleertes Geheimnis ist auch hier keins mehr
DELETE FROM sales_channel_secret_shop s
 USING shop_channel_setting_keys() m, defaults_oserp d
 WHERE m.secret
   AND d.key = m.old_key
   AND COALESCE(d.value, '') = ''
   AND s.channel_id = shop_first_channel_id(m.type)
   AND s.key = m.key;

-- Schritt 5 (dev/shop-mehrere-kanaele.md): Die Instanz-Einstellungen werden
-- in der Kanalkarte gepflegt. Die Abschrift oben hat die letzten Werte aus
-- defaults_oserp übernommen; danach verschwinden die alten Schlüssel — auch
-- die Laufzeitwerte des eBay-Kanals, die seit Schritt 4 im Kanal stehen —
-- und mit ihnen der Übergangs-Trigger aus Schritt 2.
DELETE FROM defaults_oserp
 WHERE key IN (SELECT old_key FROM shop_channel_setting_keys())
    OR key IN ('ebay_access_token', 'ebay_access_token_exp', 'ebay_order_last_check');

DROP TRIGGER IF EXISTS trigger_defaults_oserp_shop_channel_sync ON defaults_oserp;
DROP FUNCTION IF EXISTS defaults_oserp_shop_channel_sync();

-- Der Shop-Schlüssel bestimmt Mandant und HugoShop (öffentlicher Zugang,
-- shopPublicFindCompany) — zwei Kanäle mit demselben Schlüssel wären nicht
-- zu unterscheiden.
CREATE UNIQUE INDEX IF NOT EXISTS sales_channel_secret_shop_public_key
    ON sales_channel_secret_shop (value)
 WHERE key = 'public_key';

-- Ein Kanal je eBay-Konto (M4, dev/shop-mehrere-kanaele.md, Schritt 4): der
-- Inventareintrag gehört bei eBay dem Konto, zwei Kanäle mit demselben Konto
-- überschrieben sich. account_id bestimmt der Kanal selbst nach jedem neuen
-- Token (shopEbayAccountCheck).
CREATE UNIQUE INDEX IF NOT EXISTS sales_channel_shop_ebay_account_key
    ON sales_channel_shop ((settings ->> 'account_id'))
 WHERE type = 'ebay' AND COALESCE(settings ->> 'account_id', '') <> '';
