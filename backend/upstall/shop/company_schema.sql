-- ============================================================================
-- ERWEITERUNG SHOP — Company-Schema
-- ============================================================================
--
-- Herkunft: kivitendo_bridge/sql/install.sql. Die Abweichungen sind in
-- dev/shop-migration.md unter "Stufe 1" begruendet — im Kern: kein DROP TABLE
-- (die Datei laeuft bei jedem Update), customer_ext nur noch als ADD COLUMN,
-- und keine Stammdaten, die bestehende Werte ueberschreiben.
--
-- Die Namen *_hugoshop bleiben, obwohl die Erweiterung shop heisst:
-- Beispielshop und Entwicklungs-OSERP arbeiten auf derselben Datenbank, ein
-- Umbenennen braeche den laufenden Shop.
--
-- Seit 2026-10-21 (dev/kundenportal.md) liegen Webseite, Sitzung, Kundenkonto
-- und Rechnungslink im Kern (upstall/crm: website_oserp, portal_context_oserp,
-- invoice_link_oserp, customer_ext.portal_*). Dieses Schema hängt sich daran:
-- sales_channel_shop.website_id, portal_context_oserp.cart_uuid,
-- ar_link_hugoshop mit den PayPal-Feldern zur uuid aus invoice_link_oserp.
--
-- Reihenfolge beachten: der Upstall-Parser verarbeitet erst alle
-- CREATE TABLE (...) getrennt, danach alles Uebrige in Dateireihenfolge.
-- Fremdschluessel zwischen den Tabellen brauchen deshalb die richtige
-- Reihenfolge der CREATE-TABLE-Bloecke.

-- ============================================================================
-- KUNDENERWEITERUNG
-- ============================================================================
--
-- customer_ext gehoert der CRM-Basis. Gastkennzeichen und Standard-
-- Lieferadresse (portal_guest, portal_shipto_id) legt seit 2026-10-21 der
-- Kern an (Kundenportal, upstall/crm); der Shop liest und schreibt sie nur.

-- ============================================================================
-- SORTIMENT
-- ============================================================================
--
-- Gruppen und Kategorien des Shops, gepflegt in der Ansicht „Sortiment“ der
-- Shop-Erweiterung (dev/shop-sortiment.md). Ein Artikel wählt seine Kategorie
-- in der Artikelkarte aus dieser Liste (parts_ext.hugoshop_category_id), die
-- Gruppe gehört der Kategorie — so gibt es je Kategorie genau eine Gruppe.
--
-- Die Kategorieübersicht der Webseite (category_groups.json) entsteht beim
-- Satz standard aus diesen Tabellen. Ein Vorlagensatz mit eigener
-- category_groups.php (sonic24) gruppiert weiter nach seinen Wortlisten und
-- nimmt von hier nur die Kategorien.
--
-- Je Mandant ein Sortiment: die Artikeltexte in parts_ext gelten für alle
-- HugoShops (dev/shop-mehrere-kanaele.md, M6).
--
-- Vor parts_ext, das auf category_shop verweist.

CREATE TABLE IF NOT EXISTS category_group_shop
(
    id      integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name    text NOT NULL,
    sortkey integer,
    itime   timestamp without time zone DEFAULT now(),
    mtime   timestamp without time zone
);

COMMENT ON TABLE  category_group_shop         IS 'Shop: Gruppe der Kategorieübersicht (Ansicht Sortiment)';
COMMENT ON COLUMN category_group_shop.name    IS 'Name der Gruppe, wie er in der Kategorieübersicht der Webseite steht';
COMMENT ON COLUMN category_group_shop.sortkey IS 'Reihenfolge in der Übersicht, aufsteigend; ohne Wert nach dem Namen';

CREATE TABLE IF NOT EXISTS category_shop
(
    id        integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name      text NOT NULL,
    group_id  integer REFERENCES category_group_shop (id) ON DELETE SET NULL,
    topseller boolean NOT NULL DEFAULT false,
    itime     timestamp without time zone DEFAULT now(),
    mtime     timestamp without time zone
);

COMMENT ON TABLE  category_shop           IS 'Shop: Kategorie des Sortiments; Artikel verweisen über parts_ext.hugoshop_category_id';
COMMENT ON COLUMN category_shop.name      IS 'Name der Kategorie — Begriff der Taxonomie auf der Webseite, aus ihm bildet Hugo die Adresse';
COMMENT ON COLUMN category_shop.group_id  IS 'Gruppe in der Kategorieübersicht; leer = Restgruppe „Sonstiges“';
COMMENT ON COLUMN category_shop.topseller IS 'Hervorgehoben oben in der Kategorieübersicht';

-- Namen eindeutig ohne Rücksicht auf Groß- und Kleinschreibung: Hugo legt
-- „Zubehör“ und „zubehör“ auf dieselbe Adresse
CREATE UNIQUE INDEX IF NOT EXISTS category_group_shop_name_key ON category_group_shop (lower(btrim(name)));
CREATE UNIQUE INDEX IF NOT EXISTS category_shop_name_key ON category_shop (lower(btrim(name)));

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
    -- RESTRICT: eine Kategorie mit Artikeln lässt sich nicht löschen, die
    -- Ansicht Sortiment sagt, wie viele noch daran hängen
    hugoshop_category_id    integer REFERENCES category_shop (id) ON DELETE RESTRICT,
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
COMMENT ON COLUMN parts_ext.hugoshop_category       IS 'Abgelöst durch hugoshop_category_id (Sortiment); wird beim Speichern geleert und nur noch einmalig übernommen';
COMMENT ON COLUMN parts_ext.hugoshop_category_id    IS 'Kategorie des Artikels im Sortiment (category_shop)';

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

-- Freitext-Kategorien ins Sortiment übernehmen (dev/shop-sortiment.md): jede
-- noch nicht übernommene wird eine Kategorie ohne Gruppe, der Artikel
-- verweist auf sie. Beim Speichern leert die Artikelkarte den Freitext —
-- danach findet dieser Schritt nichts mehr, und eine bewusst entfernte
-- Kategorie kommt nicht zurück. Ohne Gruppe: die Gruppen legt der Satz
-- standard in der Ansicht Sortiment fest, sonic24 ermittelt sie selbst.
INSERT INTO category_shop (name)
SELECT DISTINCT ON (lower(btrim(pe.hugoshop_category))) btrim(pe.hugoshop_category)
  FROM parts_ext pe
 WHERE pe.hugoshop_category_id IS NULL
   AND btrim(COALESCE(pe.hugoshop_category, '')) <> ''
   AND NOT EXISTS (SELECT 1 FROM category_shop c
                    WHERE lower(btrim(c.name)) = lower(btrim(pe.hugoshop_category)))
 ORDER BY lower(btrim(pe.hugoshop_category)), btrim(pe.hugoshop_category);

UPDATE parts_ext pe
   SET hugoshop_category_id = c.id
  FROM category_shop c
 WHERE pe.hugoshop_category_id IS NULL
   AND btrim(COALESCE(pe.hugoshop_category, '')) <> ''
   AND lower(btrim(c.name)) = lower(btrim(pe.hugoshop_category));

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
    CONSTRAINT sales_channel_shop_type_check CHECK (type IN ('hugoshop', 'ebay', 'amazon', 'kleinanzeigen')),
    CONSTRAINT sales_channel_shop_markup_check CHECK (markup_type IN ('none', 'percent', 'amount'))
);

-- Ein HugoShop gehört zu einer Webseite des Kerns (dev/kundenportal.md):
-- dort stehen Adresse, Zugang und HugoCMS; hier bleibt der Handel
ALTER TABLE sales_channel_shop ADD COLUMN IF NOT EXISTS website_id integer;
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conrelid = 'sales_channel_shop'::regclass
                      AND conname = 'sales_channel_shop_website_id_fk') THEN
        ALTER TABLE sales_channel_shop
            ADD CONSTRAINT sales_channel_shop_website_id_fk
            FOREIGN KEY (website_id) REFERENCES website_oserp (id) ON DELETE SET NULL;
    END IF;
END $$;
COMMENT ON COLUMN sales_channel_shop.website_id IS 'Webseite des Kerns (website_oserp), auf der der HugoShop läuft';

-- Eine Webseite trägt höchstens einen HugoShop: Bereitstellung, Paket und
-- Sitzungen gehören der Webseite, zwei Shops darauf überschrieben sich
CREATE UNIQUE INDEX IF NOT EXISTS sales_channel_shop_website_hugoshop
    ON sales_channel_shop (website_id)
 WHERE type = 'hugoshop' AND website_id IS NOT NULL;

COMMENT ON TABLE  sales_channel_shop                IS 'Shop: Verkaufskanal, eine Instanz seiner Art';
COMMENT ON COLUMN sales_channel_shop.type           IS 'Art: hugoshop, ebay, amazon oder kleinanzeigen';
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

-- Neue Kanalart kleinanzeigen (dev/shop-kleinanzeigen.md): die Prüfung der Art
-- auf bestehenden Datenbanken nachziehen — CREATE TABLE oben greift dort nicht.
DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM pg_constraint
                WHERE conrelid = 'sales_channel_shop'::regclass
                  AND conname = 'sales_channel_shop_type_check'
                  AND pg_get_constraintdef(oid) NOT LIKE '%kleinanzeigen%') THEN
        ALTER TABLE sales_channel_shop DROP CONSTRAINT sales_channel_shop_type_check;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conrelid = 'sales_channel_shop'::regclass
                      AND conname = 'sales_channel_shop_type_check') THEN
        ALTER TABLE sales_channel_shop
            ADD CONSTRAINT sales_channel_shop_type_check
            CHECK (type IN ('hugoshop', 'ebay', 'amazon', 'kleinanzeigen'));
    END IF;
END $$;

-- Vorgaben für die Einstellungen eines neuen Kanals (Grundausstattung und
-- „Kanal anlegen" in der Kanalkarte). Was hier fehlt, ist leer; die Leser
-- haben für fehlende Werte dieselben Vorgaben.
CREATE OR REPLACE FUNCTION shop_channel_default_settings(p_type text) RETURNS jsonb
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p_type
        WHEN 'hugoshop' THEN jsonb_build_object(
            'downloads_link',                   '/downloads/%s',
            'template_set',                     'standard',
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
        -- Kleinanzeigen (dev/shop-kleinanzeigen.md): Preisart Verhandlungsbasis,
        -- Abholung und Versand, Anzeigen nach 30 Tagen auffrischen
        WHEN 'kleinanzeigen' THEN jsonb_build_object(
            'price_type',                       'negotiable',
            'shipping',                         'both',
            'default_condition',                'good',
            'refresh_days',                     '30',
            'lead_mail_folder',                 'INBOX',
            'text_style',                       'sachlich')
        ELSE '{}'::jsonb END
$$;

-- Grundausstattung, einmal je Mandant: ein HugoShop. Der Merker
-- (shop_channels_seeded) verhindert, dass ein später gelöschter Kanal (M5) beim
-- nächsten Schema-Update wiederkommt; eine bestehende Datenbank hat ihn schon.
-- Weitere Kanäle legt der Betreiber unter „Verkaufskanäle" an — ein neuer
-- Mandant bekommt nicht mehr von selbst eBay und Kleinanzeigen dazu (bis
-- 2026-10-29 kamen beide mit, eBay eingeschaltet nach ebay_enabled,
-- Kleinanzeigen abgeschaltet mit eigenem Merker shop_kleinanzeigen_seeded).
INSERT INTO sales_channel_shop (type, name, sortkey, auto_add_parts, settings)
SELECT 'hugoshop', 'HugoShop', 1, true, shop_channel_default_settings('hugoshop')
 WHERE NOT EXISTS (SELECT 1 FROM defaults_oserp WHERE key = 'shop_channels_seeded')
   AND NOT EXISTS (SELECT 1 FROM sales_channel_shop WHERE type = 'hugoshop');

-- Ein eBay-Kanal nur, wenn die alte eBay-Anbindung des Kerns in Gebrauch war
-- (ebay_enabled gesetzt oder Angebote in ebay_listings): er nimmt deren
-- Angebote auf (Übernahme unten, shop_ebay_migrated) und ist eingeschaltet,
-- wenn die alte Anbindung es war; danach gilt der Schalter unter
-- „Verkaufskanäle". ebay_listings kommt aus dem CRM-Schema und wird nur
-- angefasst, wenn es die Tabelle gibt.
DO $$
DECLARE
    alt_an  boolean;
    alt_da  boolean;
BEGIN
    IF EXISTS (SELECT 1 FROM defaults_oserp WHERE key = 'shop_channels_seeded')
       OR EXISTS (SELECT 1 FROM sales_channel_shop WHERE type = 'ebay') THEN
        RETURN;
    END IF;
    alt_an := COALESCE((SELECT lower(btrim(value)) IN ('1', 't', 'true', 'on')
                          FROM defaults_oserp WHERE key = 'ebay_enabled'), false);
    alt_da := alt_an;
    IF NOT alt_da AND to_regclass('ebay_listings') IS NOT NULL THEN
        alt_da := EXISTS (SELECT 1 FROM ebay_listings);
    END IF;
    IF alt_da THEN
        INSERT INTO sales_channel_shop (type, name, sortkey, active, settings)
        VALUES ('ebay', 'eBay', 2, alt_an, shop_channel_default_settings('ebay'));
    END IF;
END $$;

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

-- Dateiname der Produktseite: die Produktseiten-Kennung, sonst die
-- Artikelnummer, klein, ohne Verzeichnis, mit .md — wie shopPageFileName()
-- und shopPageSlug() in PHP. NULL, wenn beides fehlt.
CREATE OR REPLACE FUNCTION shop_page_file_name(p_hyperlink text, p_partnumber text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN n = '' THEN NULL
                WHEN n LIKE '%.md' THEN n
                ELSE n || '.md' END
      FROM (SELECT lower(regexp_replace(btrim(COALESCE(NULLIF(btrim(p_hyperlink), ''), p_partnumber, '')),
                                        '^.*/', '')) AS n) x
$$;

-- Produktseite eines Artikels neu schreiben lassen: in jedem eingeschalteten
-- HugoShop, in dem er angeboten wird und der automatisch neu veröffentlicht
-- (shop_auto_publish). Ändert sich dabei der Dateiname (neue
-- Produktseiten-Kennung, neue Artikelnummer ohne Kennung), steht der alte im
-- param des Auftrags: der Lauf entfernt die Seite unter dem alten Namen,
-- sonst bliebe sie verwaist mit altem Preis online. Ein eigener remove_part
-- ginge nicht — shop_queue_job hebt Aufträge der Gegenrichtung für dieselbe
-- Artikelnummer auf. Gilt nur der erste alte Name: Zwischennamen vor dem
-- nächsten Lauf gab es auf der Webseite nie.
CREATE OR REPLACE FUNCTION shop_queue_page_rewrite(p_parts_id integer, p_partnumber text, p_old_file text)
    RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    kanal integer;
BEGIN
    FOR kanal IN
        SELECT c.id
          FROM parts_channel_shop pc
          JOIN sales_channel_shop c ON c.id = pc.channel_id AND c.active AND c.type = 'hugoshop'
         WHERE pc.parts_id = p_parts_id
           AND pc.active
           AND shop_auto_publish_enabled(c.id)
    LOOP
        PERFORM shop_queue_job('publish_part', p_partnumber, NULL, kanal);
        IF p_old_file IS NOT NULL THEN
            UPDATE batchjob_hugoshop
               SET param = p_old_file
             WHERE result IS NULL
               AND function = 'publish_part'
               AND partnumber = p_partnumber
               AND channel_id = kanal
               AND param IS NULL;
        END IF;
    END LOOP;
END;
$$;

-- Änderung am Artikel (parts gehört kivitendo: hier kommt nur ein Trigger
-- dazu, die Tabelle selbst bleibt unverändert). Je eingeschaltetem Kanal, in
-- dem der Artikel angeboten wird:
--   HugoShop  alles, was auf der Seite steht oder über sie entscheidet:
--             Verkaufspreis und Buchungsgruppe (Steuersatz), „Veraltet“,
--             Gewicht (passt eine Versandart, sonst Entwurf), Bezeichnung
--             und Langbeschreibung (gelten, solange die Kanalzeile keine
--             eigenen hat), Einheit, EAN, Artikelnummer, und der Bestand nur
--             beim Wechsel zwischen vorrätig und nicht vorrätig — die Seite
--             zeigt nur das. Nur bei shop_auto_publish
--             (shop_queue_page_rewrite).
--   Marktplatz Preis, Buchungsgruppe, „Veraltet“, Gewicht, Bestand,
--              Beschreibung und Langbeschreibung
CREATE OR REPLACE FUNCTION parts_shop_auto_publish() RETURNS trigger AS $$
DECLARE
    preis   boolean := OLD.sellprice IS DISTINCT FROM NEW.sellprice
                       OR OLD.buchungsgruppen_id IS DISTINCT FROM NEW.buchungsgruppen_id
                       OR OLD.obsolete IS DISTINCT FROM NEW.obsolete
                       OR OLD.weight IS DISTINCT FROM NEW.weight;
    sonst   boolean := OLD.onhand IS DISTINCT FROM NEW.onhand
                       OR OLD.description IS DISTINCT FROM NEW.description
                       OR OLD.notes IS DISTINCT FROM NEW.notes;
    seite   boolean := preis
                       OR OLD.description IS DISTINCT FROM NEW.description
                       OR OLD.notes IS DISTINCT FROM NEW.notes
                       OR OLD.unit IS DISTINCT FROM NEW.unit
                       OR OLD.ean IS DISTINCT FROM NEW.ean
                       OR OLD.partnumber IS DISTINCT FROM NEW.partnumber
                       OR (COALESCE(OLD.onhand, 0) > 0) IS DISTINCT FROM (COALESCE(NEW.onhand, 0) > 0);
    kennung text;
    alt     text;
    neu     text;
BEGIN
    IF NOT shop_extension_active() THEN
        RETURN NULL;
    END IF;

    -- Marktplätze gleichen immer ab
    PERFORM shop_queue_job('publish_part', NEW.partnumber, NULL, c.id)
       FROM parts_channel_shop pc
       JOIN sales_channel_shop c ON c.id = pc.channel_id AND c.active AND c.type <> 'hugoshop'
      WHERE pc.parts_id = NEW.id
        AND pc.active
        AND (preis OR sonst);

    IF seite THEN
        SELECT hugoshop_hyperlink INTO kennung FROM parts_ext WHERE parts_id = NEW.id;
        alt := shop_page_file_name(kennung, OLD.partnumber);
        neu := shop_page_file_name(kennung, NEW.partnumber);
        PERFORM shop_queue_page_rewrite(NEW.id, NEW.partnumber,
                                        CASE WHEN alt IS DISTINCT FROM neu THEN alt END);
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- Änderung an den Shop-Angaben eines Artikels (parts_ext: Bilder, Kategorie,
-- Navigationspfad, technische Daten, Eigenschaften, Downloads, Kennung der
-- Produktseite): sie stehen nur auf den Seiten der HugoShops. Seite neu, nur
-- bei shop_auto_publish; eine neue Kennung entfernt die Seite unter dem
-- alten Namen (shop_queue_page_rewrite). Eine neue Zeile löst nichts aus —
-- veröffentlicht wird ein Artikel zum ersten Mal vom Benutzer.
CREATE OR REPLACE FUNCTION parts_ext_shop_auto_publish() RETURNS trigger AS $$
DECLARE
    nummer text;
    alt    text;
    neu    text;
BEGIN
    IF NOT shop_extension_active() THEN
        RETURN NULL;
    END IF;
    SELECT partnumber INTO nummer FROM parts WHERE id = NEW.parts_id;
    IF nummer IS NULL THEN
        RETURN NULL;
    END IF;
    alt := shop_page_file_name(OLD.hugoshop_hyperlink, nummer);
    neu := shop_page_file_name(NEW.hugoshop_hyperlink, nummer);
    PERFORM shop_queue_page_rewrite(NEW.parts_id, nummer, CASE WHEN alt IS DISTINCT FROM neu THEN alt END);
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
    -- weight stand bis 2026-10-09 nur in der Funktion, nicht hier — eine
    -- Gewichtsänderung löste deshalb nie etwas aus
    CREATE TRIGGER trigger_parts_shop_auto_publish
        AFTER UPDATE OF sellprice, buchungsgruppen_id, obsolete, weight, onhand, description, notes,
                        unit, ean, partnumber ON parts
        FOR EACH ROW
        WHEN (OLD.sellprice IS DISTINCT FROM NEW.sellprice
              OR OLD.buchungsgruppen_id IS DISTINCT FROM NEW.buchungsgruppen_id
              OR OLD.obsolete IS DISTINCT FROM NEW.obsolete
              OR OLD.weight IS DISTINCT FROM NEW.weight
              OR OLD.onhand IS DISTINCT FROM NEW.onhand
              OR OLD.description IS DISTINCT FROM NEW.description
              OR OLD.notes IS DISTINCT FROM NEW.notes
              OR OLD.unit IS DISTINCT FROM NEW.unit
              OR OLD.ean IS DISTINCT FROM NEW.ean
              OR OLD.partnumber IS DISTINCT FROM NEW.partnumber)
        EXECUTE FUNCTION parts_shop_auto_publish();
END $$;

DO $$ BEGIN
    DROP TRIGGER IF EXISTS trigger_parts_ext_shop_auto_publish ON parts_ext;
    CREATE TRIGGER trigger_parts_ext_shop_auto_publish
        AFTER UPDATE ON parts_ext
        FOR EACH ROW
        WHEN (OLD.hugoshop_hyperlink IS DISTINCT FROM NEW.hugoshop_hyperlink
              OR OLD.hugoshop_category_id IS DISTINCT FROM NEW.hugoshop_category_id
              OR OLD.hugoshop_breadcrumbs IS DISTINCT FROM NEW.hugoshop_breadcrumbs
              OR OLD.hugoshop_images IS DISTINCT FROM NEW.hugoshop_images
              OR OLD.hugoshop_technical_data IS DISTINCT FROM NEW.hugoshop_technical_data
              OR OLD.hugoshop_properties IS DISTINCT FROM NEW.hugoshop_properties
              OR OLD.hugoshop_downloads IS DISTINCT FROM NEW.hugoshop_downloads)
        EXECUTE FUNCTION parts_ext_shop_auto_publish();
END $$;

-- Sortiment (dev/shop-sortiment.md). Eine umbenannte Kategorie hat eine neue
-- Adresse: die Produktseiten ihrer Artikel tragen Name und Adresse im Front
-- Matter (Taxonomie, catalogname, cataloglink) und werden neu geschrieben —
-- dabei schreibt der Lauf auch die Übersicht neu. Alles andere (neue,
-- gelöschte Kategorie, andere Gruppe, Topseller, Gruppen selbst) steht nur in
-- der Kategorieübersicht: ein Auftrag write_categories je HugoShop genügt.
-- Beides nur bei shop_auto_publish, wie jedes automatische Neuschreiben.
CREATE OR REPLACE FUNCTION category_shop_rename_publish() RETURNS trigger AS $$
DECLARE
    artikel record;
BEGIN
    IF NOT shop_extension_active() THEN
        RETURN NULL;
    END IF;
    FOR artikel IN
        SELECT p.id, p.partnumber
          FROM parts_ext pe
          JOIN parts p ON p.id = pe.parts_id
         WHERE pe.hugoshop_category_id = NEW.id
    LOOP
        PERFORM shop_queue_page_rewrite(artikel.id, artikel.partnumber, NULL);
    END LOOP;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION shop_assortment_changed() RETURNS trigger AS $$
BEGIN
    IF NOT shop_extension_active() THEN
        RETURN NULL;
    END IF;
    PERFORM shop_queue_job('write_categories', '', NULL, c.id)
       FROM sales_channel_shop c
      WHERE c.active
        AND c.type = 'hugoshop'
        AND shop_auto_publish_enabled(c.id);
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    DROP TRIGGER IF EXISTS trigger_category_shop_rename_publish ON category_shop;
    CREATE TRIGGER trigger_category_shop_rename_publish
        AFTER UPDATE OF name ON category_shop
        FOR EACH ROW
        WHEN (OLD.name IS DISTINCT FROM NEW.name)
        EXECUTE FUNCTION category_shop_rename_publish();

    -- Je Zeile, nicht je Anweisung: eine Anweisung ohne betroffene Zeile
    -- (die Übernahme oben bei jedem Upstall) soll keinen Auftrag anlegen.
    -- Mehrere Zeilen ergeben trotzdem nur einen — shop_queue_job legt keinen
    -- zweiten offenen an.
    DROP TRIGGER IF EXISTS trigger_category_shop_assortment_changed ON category_shop;
    CREATE TRIGGER trigger_category_shop_assortment_changed
        AFTER INSERT OR UPDATE OR DELETE ON category_shop
        FOR EACH ROW
        EXECUTE FUNCTION shop_assortment_changed();

    DROP TRIGGER IF EXISTS trigger_category_group_shop_assortment_changed ON category_group_shop;
    CREATE TRIGGER trigger_category_group_shop_assortment_changed
        AFTER INSERT OR UPDATE OR DELETE ON category_group_shop
        FOR EACH ROW
        EXECUTE FUNCTION shop_assortment_changed();
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
    max_width                  numeric(15,5),
    max_height                 numeric(15,5),
    max_size                   numeric(15,5),
    free_shipping_applies      boolean NOT NULL DEFAULT true,
    ebay_fulfillment_policy_id text,
    active                     boolean NOT NULL DEFAULT true,
    itime                      timestamp without time zone DEFAULT now(),
    mtime                      timestamp without time zone
);

COMMENT ON TABLE  shipping_method_shop             IS 'Shop: Versandart';
COMMENT ON COLUMN shipping_method_shop.vendor_id   IS 'Anbieter (Lieferant), NULL = allgemeine Versandart';
COMMENT ON COLUMN shipping_method_shop.parts_id    IS 'Versandartikel (Dienstleistung, je Versandart ein eigener): Bezeichnung, Erlöskonto und Steuer der Versandposition';
COMMENT ON COLUMN shipping_method_shop.rank        IS 'Rang: entscheidet bei gleichem Preis über die Vorauswahl; bei eBay die Richtlinie der ranghöchsten erlaubten (W1, W4)';
COMMENT ON COLUMN shipping_method_shop.max_weight  IS 'Höchstgewicht der Sendung in der Einheit von parts.weight, NULL = ohne Grenze';
COMMENT ON COLUMN shipping_method_shop.max_length  IS 'Längste Kante des größten Artikels in cm, NULL = ohne Grenze';
COMMENT ON COLUMN shipping_method_shop.max_girth   IS 'Gurtmaß des größten Artikels in cm (Länge + 2 × Breite + 2 × Höhe), NULL = ohne Grenze';

-- Einzelgrenzen und Größe nach Hermes (2026-10-02). Die Maße eines Artikels
-- werden sortiert verglichen, wie die Versanddienstleister messen: längste
-- Seite = Länge, mittlere = Breite, kürzeste = Höhe — gleich, wie der Artikel
-- im Paket liegt. Breite, Höhe und Größe brauchen alle drei Maße; fehlt
-- eines, gilt der Artikel dafür als passend, wie bei fehlenden Abmessungen
-- überhaupt.
ALTER TABLE shipping_method_shop ADD COLUMN IF NOT EXISTS max_width  numeric(15,5);
ALTER TABLE shipping_method_shop ADD COLUMN IF NOT EXISTS max_height numeric(15,5);
ALTER TABLE shipping_method_shop ADD COLUMN IF NOT EXISTS max_size   numeric(15,5);
COMMENT ON COLUMN shipping_method_shop.max_width  IS 'Breite (mittlere Seite) des größten Artikels in cm, NULL = ohne Grenze';
COMMENT ON COLUMN shipping_method_shop.max_height IS 'Höhe (kürzeste Seite) des größten Artikels in cm, NULL = ohne Grenze — etwa Warensendung, Brief';
COMMENT ON COLUMN shipping_method_shop.max_size   IS 'Größe nach Hermes: längste + kürzeste Seite in cm, NULL = ohne Grenze';
COMMENT ON COLUMN shipping_method_shop.free_shipping_applies IS 'Freigrenze des Kanals gilt für diese Versandart (W6)';
COMMENT ON COLUMN shipping_method_shop.ebay_fulfillment_policy_id IS 'eBay-Versandrichtlinie für Angebote mit dieser Versandart, NULL = die allgemeine';

-- Versandwahl in der Kasse (dev/shop-versand.md, 2026-10-30): ein Hinweis
-- für den Kunden an der Versandart (Lieferzeit, Abholort, Bedingungen) und
-- die Freischaltung: nicht freigeschaltete Versandarten sind für Kunden nicht
-- frei wählbar, kommen aber zum Zug, wenn eine Artikelzuordnung sie vorgibt
ALTER TABLE shipping_method_shop ADD COLUMN IF NOT EXISTS customer_note text;
ALTER TABLE shipping_method_shop ADD COLUMN IF NOT EXISTS selectable boolean NOT NULL DEFAULT true;
COMMENT ON COLUMN shipping_method_shop.customer_note IS 'Hinweis für den Kunden in der Kasse (Lieferzeit, Abholort …), NULL = keiner';
COMMENT ON COLUMN shipping_method_shop.selectable    IS 'Für Kunden wählbar; false = nur über die Zuordnung eines Artikels';

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
    price              numeric(15,5) NOT NULL,
    cost               numeric(15,5)
);

COMMENT ON TABLE  shipping_rate_shop             IS 'Shop: Versandpreis je Versandart, Kanal, Zone und Stufe';
COMMENT ON COLUMN shipping_rate_shop.channel_id  IS 'Verkaufskanal, NULL = alle';
COMMENT ON COLUMN shipping_rate_shop.zone_id     IS 'Länderzone, NULL = alle Länder ohne eigene Zeile';
COMMENT ON COLUMN shipping_rate_shop.weight_from IS 'gilt ab diesem Gesamtgewicht (Einheit von parts.weight)';
COMMENT ON COLUMN shipping_rate_shop.qty_from    IS 'gilt ab dieser Gesamtstückzahl';
COMMENT ON COLUMN shipping_rate_shop.price       IS 'Versandpreis, netto oder brutto wie parts.sellprice';

-- Kosten des Versanddienstleisters je Stufe (2026-10-02): was der Versand den
-- Betreiber kostet, netto. Nur zur Anzeige unter „Versand je Zone" in der
-- Artikelkarte (Preis, Kosten, Differenz); die Rechnung übernimmt sie nicht.
ALTER TABLE shipping_rate_shop ADD COLUMN IF NOT EXISTS cost numeric(15,5);
COMMENT ON COLUMN shipping_rate_shop.cost        IS 'Kosten des Versanddienstleisters, netto, NULL = nicht gepflegt';

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

-- Versandangaben je Artikel (Schritt 5). Das Gewicht bleibt in parts.weight;
-- die erlaubten Versandarten stehen seit 2026-10-30 in parts_shipping_method_shop.
CREATE TABLE IF NOT EXISTS parts_shipping_shop
(
    parts_id           integer NOT NULL PRIMARY KEY REFERENCES parts (id) ON DELETE CASCADE,
    length             numeric(15,5),
    width              numeric(15,5),
    height             numeric(15,5),
    min_qty            numeric(15,5),
    delivery_term_id   integer REFERENCES delivery_terms (id) ON DELETE SET NULL
);

COMMENT ON TABLE  parts_shipping_shop                    IS 'Shop: Versandangaben eines Artikels';
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
        AFTER INSERT OR UPDATE OF delivery_term_id, min_qty, length, width, height
        ON parts_shipping_shop
        FOR EACH ROW
        EXECUTE FUNCTION parts_shipping_shop_auto_publish();
END $$;

-- Mehrfachzuordnung (dev/shop-versand.md, „Versandwahl in der Kasse“,
-- 2026-10-30): ein Artikel kann mehrere Versandarten erlauben. Keine Zeile =
-- alle Versandarten; Zeilen = nur diese. Im Warenkorb gilt die Schnittmenge
-- der eingeschränkten Artikel (shop_cart_shipping_metrics). Ersetzt die
-- Einzelzuordnung parts_shipping_shop.shipping_method_id; vorhandene
-- Zuordnungen werden einmalig übernommen, die Spalte danach entfernt.
CREATE TABLE IF NOT EXISTS parts_shipping_method_shop
(
    parts_id           integer NOT NULL REFERENCES parts (id) ON DELETE CASCADE,
    shipping_method_id integer NOT NULL REFERENCES shipping_method_shop (id) ON DELETE CASCADE,
    PRIMARY KEY (parts_id, shipping_method_id)
);

COMMENT ON TABLE parts_shipping_method_shop IS 'Shop: erlaubte Versandarten eines Artikels, keine Zeile = alle';

CREATE INDEX IF NOT EXISTS parts_shipping_method_shop_method_idx
    ON parts_shipping_method_shop (shipping_method_id);

DO $$ BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns
                WHERE table_name = 'parts_shipping_shop' AND column_name = 'shipping_method_id') THEN
        INSERT INTO parts_shipping_method_shop (parts_id, shipping_method_id)
        SELECT ps.parts_id, ps.shipping_method_id
          FROM parts_shipping_shop ps
         WHERE ps.shipping_method_id IS NOT NULL
        ON CONFLICT DO NOTHING;
        -- Der Trigger auf parts_shipping_shop wurde oben schon ohne die Spalte
        -- neu angelegt, sonst hinge er an ihr
        ALTER TABLE parts_shipping_shop DROP COLUMN shipping_method_id;
    END IF;
END $$;

-- Erlaubte aktive Versandarten eines Artikels; NULL = keine Einschränkung
-- (keine Zuordnung, oder nur inaktive — wie bisher bei inaktiver Zuordnung).
CREATE OR REPLACE FUNCTION shop_part_allowed_methods(p_parts_id integer) RETURNS integer[]
    LANGUAGE sql STABLE AS $$
    SELECT array_agg(pm.shipping_method_id ORDER BY pm.shipping_method_id)
      FROM parts_shipping_method_shop pm
      JOIN shipping_method_shop m ON m.id = pm.shipping_method_id AND m.active
     WHERE pm.parts_id = p_parts_id
$$;

-- Zuordnung geändert: wie bei den übrigen Versandangaben neu veröffentlichen
-- (shop_part_shipping_check entscheidet, ob die Seite bleibt)
CREATE OR REPLACE FUNCTION parts_shipping_method_shop_auto_publish() RETURNS trigger AS $$
BEGIN
    IF NOT shop_extension_active() THEN
        RETURN NULL;
    END IF;
    PERFORM shop_queue_job('publish_part', p.partnumber, NULL, c.id)
       FROM parts p
       JOIN parts_channel_shop pc ON pc.parts_id = p.id AND pc.active
       JOIN sales_channel_shop c ON c.id = pc.channel_id AND c.active
      WHERE p.id = COALESCE(NEW.parts_id, OLD.parts_id)
        AND (c.type <> 'hugoshop' OR shop_auto_publish_enabled(c.id));
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DO $$ BEGIN
    DROP TRIGGER IF EXISTS trigger_parts_shipping_method_shop_auto_publish ON parts_shipping_method_shop;
    CREATE TRIGGER trigger_parts_shipping_method_shop_auto_publish
        AFTER INSERT OR DELETE
        ON parts_shipping_method_shop
        FOR EACH ROW
        EXECUTE FUNCTION parts_shipping_method_shop_auto_publish();
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

-- Versandart und Preis aus den Kennzahlen einer Sendung — die eine Regel für
-- den Warenkorb (shop_cart_shipping), die Versandwahl in der Kasse
-- (shop_cart_shipping_options) und die Preisanzeige je Artikel
-- (shop_part_shipping_prices). Regeln siehe shop_cart_shipping; Lieferland
-- und Lieferländer prüft der Aufrufer, hier kommt nur die Zone an (NULL =
-- alle übrigen Länder).
--
--   p_kanal        HugoShop
--   p_zone         Zone des Lieferlands, NULL = alle übrigen Länder
--   p_menge        Stückzahl der Ware
--   p_gewicht      Gesamtgewicht
--   p_ohne_gewicht mindestens eine Ware ohne Gewicht
--   p_kante, p_breite, p_hoehe, p_groesse, p_gurt
--                  größte Werte der Artikel (längste, mittlere, kürzeste
--                  Seite, längste + kürzeste, Gurtmaß); NULL = nicht gepflegt
--   p_erlaubt      erlaubte Versandarten aus den Zuordnungen der Artikel
--                  (Schnittmenge), NULL = keine Einschränkung, leer = keine
--                  Versandart erfüllt alle Zuordnungen
--   p_warenwert    Bruttowarenwert für die Freigrenze
--   p_gewaehlt     in der Kasse gewählte Versandart, NULL = keine Wahl
--
-- shop_shipping_candidates (Versandwahl in der Kasse, 2026-10-30): jede
-- aktive Versandart, die den Kanal bedient (eine Preisstufe für ihn oder für
-- alle Kanäle), mit Preis und Freiheit und dem Grund, wenn sie für diese
-- Sendung nicht in Frage kommt. Die Kasse zeigt die wählbaren, nicht
-- passende ausgegraut.
--   rate_price  Preis der Stufe vor der Freigrenze — kostenpflichtig heißt rate_price > 0
--   selectable  für Kunden freigeschaltet (shipping_method_shop.selectable)
--   forced      durch eine Artikelzuordnung vorgegeben (in p_erlaubt) — gilt
--               auch ohne Freischaltung (Bedeutung 1: nicht frei wählbar,
--               aber durch Zuordnung erzwingbar)
--   available   kommt in Frage
--   reason      assigned_other  ein Artikel lässt diese Versandart nicht zu
--               not_selectable  nicht freigeschaltet und nicht vorgegeben
--               weight_missing  Ware ohne Gewicht, die Versandart braucht eins (W8)
--               no_rate         keine Preisstufe für Kanal und Zone
--               too_heavy       über dem Höchstgewicht
--               too_large       über einer Abmessungsgrenze
--               NULL            kommt in Frage
-- Fehlende Abmessungen gelten als passend (Vergleich mit NULL ist nicht wahr).
-- Frühere Fassungen (anderer Parameter- oder Ergebnistyp) weg, sonst entstünde eine zweite
DROP FUNCTION IF EXISTS shop_shipping_candidates(integer, integer, numeric, numeric, boolean, numeric, numeric,
                                                 numeric, numeric, numeric, integer, numeric);
DROP FUNCTION IF EXISTS shop_shipping_candidates(integer, integer, numeric, numeric, boolean, numeric, numeric,
                                                 numeric, numeric, numeric, integer[], numeric);
CREATE OR REPLACE FUNCTION shop_shipping_candidates(p_kanal integer, p_zone integer, p_menge numeric,
                                                    p_gewicht numeric, p_ohne_gewicht boolean,
                                                    p_kante numeric, p_breite numeric, p_hoehe numeric,
                                                    p_groesse numeric, p_gurt numeric,
                                                    p_erlaubt integer[], p_warenwert numeric)
RETURNS TABLE (shipping_method_id integer, description text, customer_note text, parts_id integer,
               rank integer, price numeric, free boolean, cost numeric, rate_price numeric,
               selectable boolean, forced boolean, available boolean, reason text)
LANGUAGE sql STABLE AS $$
    WITH grenze AS (
        SELECT c.free_shipping_from FROM sales_channel_shop c WHERE c.id = p_kanal
    )
    SELECT m.id, m.description, m.customer_note, m.parts_id, m.rank,
           CASE WHEN f.frei THEN 0::numeric ELSE stufe.price END,
           f.frei, stufe.cost, stufe.price,
           m.selectable, v.vorgegeben,
           g.grund IS NULL, g.grund
      FROM shipping_method_shop m
      LEFT JOIN LATERAL (
           SELECT r.price, r.cost
             FROM shipping_rate_shop r
            WHERE r.shipping_method_id = m.id
              AND (r.channel_id IS NULL OR r.channel_id = p_kanal)
              AND (r.zone_id IS NULL OR r.zone_id = p_zone)
              AND r.weight_from <= p_gewicht
              AND r.qty_from <= p_menge
            ORDER BY (r.channel_id IS NOT NULL) DESC, (r.zone_id IS NOT NULL) DESC,
                     r.weight_from DESC, r.qty_from DESC
            LIMIT 1
      ) stufe ON true
      CROSS JOIN LATERAL (
           SELECT p_erlaubt IS NOT NULL AND m.id = ANY (p_erlaubt) AS vorgegeben
      ) v
      CROSS JOIN LATERAL (
           SELECT CASE
                    WHEN p_erlaubt IS NOT NULL AND NOT v.vorgegeben THEN 'assigned_other'
                    WHEN NOT m.selectable AND NOT v.vorgegeben THEN 'not_selectable'
                    WHEN p_ohne_gewicht AND (m.max_weight IS NOT NULL
                                             OR EXISTS (SELECT 1 FROM shipping_rate_shop r
                                                         WHERE r.shipping_method_id = m.id AND r.weight_from > 0))
                         THEN 'weight_missing'
                    WHEN stufe.price IS NULL THEN 'no_rate'
                    WHEN m.max_weight IS NOT NULL AND p_gewicht > m.max_weight THEN 'too_heavy'
                    WHEN (m.max_length IS NOT NULL AND p_kante   > m.max_length)
                      OR (m.max_width  IS NOT NULL AND p_breite  > m.max_width)
                      OR (m.max_height IS NOT NULL AND p_hoehe   > m.max_height)
                      OR (m.max_size   IS NOT NULL AND p_groesse > m.max_size)
                      OR (m.max_girth  IS NOT NULL AND p_gurt    > m.max_girth) THEN 'too_large'
                  END AS grund
      ) g
      CROSS JOIN LATERAL (
           SELECT m.free_shipping_applies
                  AND (SELECT free_shipping_from FROM grenze) IS NOT NULL
                  AND p_warenwert >= (SELECT free_shipping_from FROM grenze) AS frei
      ) f
     WHERE m.active
       AND EXISTS (SELECT 1 FROM shipping_rate_shop r
                    WHERE r.shipping_method_id = m.id
                      AND (r.channel_id IS NULL OR r.channel_id = p_kanal))
$$;

-- Die Versandart, mit der gerechnet wird: die in der Kasse gewählte, wenn sie
-- in Frage kommt, sonst die Vorauswahl: die günstigste kostenpflichtige
-- Versandart (Stufenpreis > 0, auch wenn die Freigrenze sie gerade frei
-- macht), bei gleichem Preis die ranghöchste; kostenlose Versandarten (Preis
-- 0, etwa Selbstabholung) nur, wenn keine kostenpflichtige in Frage kommt —
-- Abholung ist für den Kunden meist die ungünstigste Wahl (Entscheidung 2,
-- Fassung 2026-10-30).
-- Kommt keine in Frage, nennt status den Grund: weight_missing (Versand auf
-- Anfrage) vor assigned_unfit (description nennt die erlaubten) vor no_method.
-- Die Parameterliste hat sich geändert (p_erlaubt statt p_zugeordnet,
-- p_gewaehlt) — CREATE OR REPLACE lässt das nicht zu, alte Fassungen weg.
DROP FUNCTION IF EXISTS shop_shipping_select(integer, integer, numeric, numeric, boolean, numeric, numeric,
                                             numeric, numeric, numeric, integer, numeric);
DROP FUNCTION IF EXISTS shop_shipping_select(integer, integer, numeric, numeric, boolean, numeric, numeric,
                                             numeric, numeric, numeric, integer, numeric, integer);
CREATE OR REPLACE FUNCTION shop_shipping_select(p_kanal integer, p_zone integer, p_menge numeric,
                                                p_gewicht numeric, p_ohne_gewicht boolean,
                                                p_kante numeric, p_breite numeric, p_hoehe numeric,
                                                p_groesse numeric, p_gurt numeric,
                                                p_erlaubt integer[], p_warenwert numeric,
                                                p_gewaehlt integer DEFAULT NULL)
RETURNS TABLE (status text, shipping_method_id integer, description text, parts_id integer,
               price numeric, free boolean, cost numeric)
LANGUAGE plpgsql STABLE AS $$
#variable_conflict use_column
DECLARE
    gewaehlt record;
BEGIN
    IF NOT shop_shipping_configured() THEN
        RETURN QUERY SELECT 'ok'::text, NULL::integer, 'Standard'::text, NULL::integer, 0::numeric, true, NULL::numeric;
        RETURN;
    END IF;

    SELECT k.* INTO gewaehlt
      FROM shop_shipping_candidates(p_kanal, p_zone, p_menge, p_gewicht, p_ohne_gewicht,
                                    p_kante, p_breite, p_hoehe, p_groesse, p_gurt, p_erlaubt, p_warenwert) k
     WHERE k.available
     ORDER BY (k.shipping_method_id = p_gewaehlt) DESC NULLS LAST,
              (COALESCE(k.rate_price, 0) > 0) DESC, k.price, k.rank DESC, k.shipping_method_id
     LIMIT 1;

    IF gewaehlt.shipping_method_id IS NULL THEN
        IF p_ohne_gewicht AND EXISTS (
               SELECT 1
                 FROM shop_shipping_candidates(p_kanal, p_zone, p_menge, p_gewicht, p_ohne_gewicht,
                                               p_kante, p_breite, p_hoehe, p_groesse, p_gurt, p_erlaubt, p_warenwert) k
                WHERE k.reason = 'weight_missing') THEN
            RETURN QUERY SELECT 'weight_missing'::text, NULL::integer, NULL::text, NULL::integer, 0::numeric, false, NULL::numeric;
        ELSIF p_erlaubt IS NOT NULL THEN
            RETURN QUERY SELECT 'assigned_unfit'::text, NULL::integer,
                                (SELECT string_agg(m.description, ', ' ORDER BY m.rank DESC, m.description)
                                   FROM shipping_method_shop m WHERE m.id = ANY (p_erlaubt)),
                                NULL::integer, 0::numeric, false, NULL::numeric;
        ELSE
            RETURN QUERY SELECT 'no_method'::text, NULL::integer, NULL::text, NULL::integer, 0::numeric, false, NULL::numeric;
        END IF;
        RETURN;
    END IF;

    RETURN QUERY
    SELECT 'ok'::text, gewaehlt.shipping_method_id, gewaehlt.description, gewaehlt.parts_id,
           gewaehlt.price, gewaehlt.free, gewaehlt.cost;
END;
$$;

-- ── Berechnung (dev/shop-versand.md, Schritt 6) ──
--
-- Kennzahlen der Ware eines Warenkorbs für die Versandregeln, eine Zeile —
-- gemeinsam für shop_cart_shipping und shop_cart_shipping_options.
--   kanal        HugoShop des Warenkorbs (carts_hugoshop.channel_id)
--   menge        Stückzahl der Ware (ohne Versandartikel)
--   gewicht      Gesamtgewicht
--   ohne_gewicht mindestens eine Ware ohne Gewicht
--   kante, breite, hoehe, groesse, gurt
--                größte Werte der Artikel: sortiert verglichen, wie die
--                Versanddienstleister messen — längste Seite = Länge,
--                mittlere = Breite, kürzeste = Höhe; Breite, Höhe und Größe
--                nur mit allen drei Maßen (die Summe ist NULL, sobald eines fehlt)
--   erlaubt      Schnittmenge der erlaubten Versandarten aller Artikel mit
--                Zuordnung (shop_part_allowed_methods); NULL = kein Artikel
--                eingeschränkt; leer = die Zuordnungen schließen sich aus
--   restricted_by Bezeichnungen der eingeschränkten Artikel, für die Kasse
--   gewaehlt     in der Kasse gewählte Versandart (carts_hugoshop.shipping_method_id)
CREATE OR REPLACE FUNCTION shop_cart_shipping_metrics(p_cart_uuid text)
RETURNS TABLE (kanal integer, menge numeric, gewicht numeric, ohne_gewicht boolean,
               kante numeric, breite numeric, hoehe numeric, groesse numeric, gurt numeric,
               erlaubt integer[], restricted_by text, gewaehlt integer)
-- plpgsql, nicht sql: carts_hugoshop entsteht weiter unten in dieser Datei, und
-- eine SQL-Funktion prüfte ihre Spalten schon beim Anlegen
LANGUAGE plpgsql STABLE AS $$
BEGIN
    RETURN QUERY
    WITH nur AS (
        -- Artikel mit Zuordnung und ihre erlaubten Versandarten
        SELECT c.parts_id, shop_part_allowed_methods(c.parts_id) AS erlaubt
          FROM cart_parts_hugoshop c
         WHERE c.cart_uuid = p_cart_uuid
           AND NOT shop_is_shipping_part(c.parts_id)
           AND shop_part_allowed_methods(c.parts_id) IS NOT NULL
    )
    SELECT (SELECT k.channel_id FROM carts_hugoshop k WHERE k.uuid = p_cart_uuid),
           COALESCE(sum(c.amount), 0)::numeric,
           COALESCE(sum(c.amount * COALESCE(p.weight, 0)), 0)::numeric,
           COALESCE(bool_or(p.part_type <> 'service' AND COALESCE(p.weight, 0) <= 0), false),
           max(GREATEST(ps.length, ps.width, ps.height)),
           max(ps.length + ps.width + ps.height
               - GREATEST(ps.length, ps.width, ps.height) - LEAST(ps.length, ps.width, ps.height)),
           max(CASE WHEN ps.length + ps.width + ps.height IS NOT NULL
                    THEN LEAST(ps.length, ps.width, ps.height) END),
           max(CASE WHEN ps.length + ps.width + ps.height IS NOT NULL
                    THEN GREATEST(ps.length, ps.width, ps.height) + LEAST(ps.length, ps.width, ps.height) END),
           max(GREATEST(ps.length, ps.width, ps.height)
               + 2 * (COALESCE(ps.length, 0) + COALESCE(ps.width, 0) + COALESCE(ps.height, 0)
                      - GREATEST(ps.length, ps.width, ps.height))),
           -- Schnittmenge: nur Versandarten, die jeder eingeschränkte Artikel erlaubt
           (SELECT CASE WHEN count(*) = 0 THEN NULL
                        ELSE COALESCE((SELECT array_agg(x.m ORDER BY x.m)
                                         FROM (SELECT u.m
                                                 FROM nur, unnest(nur.erlaubt) AS u(m)
                                                GROUP BY u.m
                                               HAVING count(*) = (SELECT count(*) FROM nur)) x),
                                      '{}'::integer[]) END
              FROM nur),
           (SELECT string_agg(pn.description, ', ' ORDER BY pn.description)
              FROM nur JOIN parts pn ON pn.id = nur.parts_id),
           (SELECT k.shipping_method_id FROM carts_hugoshop k WHERE k.uuid = p_cart_uuid)
      FROM cart_parts_hugoshop c
      JOIN parts p ON p.id = c.parts_id
      LEFT JOIN parts_shipping_shop ps ON ps.parts_id = p.id
     WHERE c.cart_uuid = p_cart_uuid
       AND NOT shop_is_shipping_part(c.parts_id);
END;
$$;

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
--   - Artikel können Versandarten zugeordnet haben (erlaubte Menge,
--     parts_shipping_method_shop). Für die Sendung gilt die Schnittmenge
--     aller eingeschränkten Artikel; ist sie leer oder passt keine daraus,
--     ist keine Bestellung möglich (W1, W2 in der Fassung vom 2026-10-30).
--   - Nicht freigeschaltete Versandarten (selectable = false) kommen nur in
--     Frage, wenn eine Zuordnung sie vorgibt.
--   - Aus den in Frage kommenden gilt die in der Kasse gewählte, sonst die
--     günstigste kostenpflichtige, bei gleichem Preis die ranghöchste,
--     kostenlose zuletzt (Entscheidung 2).
--   - Eine Versandart passt, wenn Gewicht, längste Kante, Breite (mittlere
--     Seite), Höhe (kürzeste Seite), Größe (längste + kürzeste Seite) und
--     Gurtmaß des größten Artikels in ihre Grenzen fallen und es eine
--     Preisstufe für Kanal und Land gibt. Fehlende Abmessungen gelten als
--     passend; fehlt einer Ware das Gewicht, passen nur Versandarten, die
--     keins brauchen (keine Gewichtsgrenze, keine Stufe ab einem Gewicht > 0)
--     — sonst „Versand auf Anfrage" (W8, W9).
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
    k    record;
    land text := shop_country_code(p_country);
    zone integer;
BEGIN
    SELECT * INTO k FROM shop_cart_shipping_metrics(p_cart_uuid);

    IF k.menge <= 0 THEN
        RETURN QUERY SELECT 'no_goods'::text, NULL::integer, NULL::text, NULL::integer, 0::numeric, false;
        RETURN;
    END IF;
    IF land IS NULL THEN
        RETURN QUERY SELECT 'country_unknown'::text, NULL::integer, NULL::text, NULL::integer, 0::numeric, false;
        RETURN;
    END IF;
    IF EXISTS (SELECT 1 FROM sales_channel_country_shop cc WHERE cc.channel_id = k.kanal)
       AND NOT EXISTS (SELECT 1 FROM sales_channel_country_shop cc
                        WHERE cc.channel_id = k.kanal AND cc.iso_code = land) THEN
        RETURN QUERY SELECT 'country_not_delivered'::text, NULL::integer, NULL::text, NULL::integer, 0::numeric, false;
        RETURN;
    END IF;
    SELECT zc.zone_id INTO zone FROM shipping_zone_country_shop zc WHERE zc.iso_code = land;

    -- Versandart und Preis: dieselbe Regel wie für die Anzeige je Artikel
    RETURN QUERY
    SELECT v.status, v.shipping_method_id, v.description, v.parts_id, v.price, v.free
      FROM shop_shipping_select(k.kanal, zone, k.menge, k.gewicht, k.ohne_gewicht,
                                k.kante, k.breite, k.hoehe, k.groesse, k.gurt, k.erlaubt, p_goods_value,
                                k.gewaehlt) v;
END;
$$;

-- Versandwahl in der Kasse (dev/shop-versand.md, 2026-10-30): die
-- Versandarten, die der Kunde zu diesem Warenkorb und Lieferland sieht —
-- freigeschaltete und durch Zuordnung vorgegebene —, mit Preis,
-- Verfügbarkeit und Grund. selected ist die, mit der shop_cart_shipping
-- rechnet (die gewählte, wenn sie in Frage kommt, sonst die günstigste
-- kostenpflichtige, bei gleichem Preis die ranghöchste, kostenlose zuletzt);
-- forced und required_by sagen, welche
-- Artikel eine Versandart vorgeben. Ist das Land unbekannt oder kein
-- Lieferland, ist keine verfügbar, der Grund steht an jeder, der Preis bleibt
-- leer. Ohne eingerichtete Versandart gibt es nichts zu wählen: leer.
-- Reihenfolge: verfügbare zuerst, kostenpflichtige vor kostenlosen, dann
-- günstigste oben, bei gleichem Preis die ranghöchste.
CREATE OR REPLACE FUNCTION shop_cart_shipping_options(p_cart_uuid text, p_country text, p_goods_value numeric)
RETURNS TABLE (shipping_method_id integer, description text, customer_note text,
               price numeric, free boolean, available boolean, reason text, selected boolean,
               forced boolean, required_by text)
LANGUAGE plpgsql STABLE AS $$
#variable_conflict use_column
DECLARE
    k        record;
    land     text := shop_country_code(p_country);
    zone     integer;
    sperre   text;
    gewaehlt integer;
BEGIN
    IF NOT shop_shipping_configured() THEN
        RETURN;
    END IF;
    SELECT * INTO k FROM shop_cart_shipping_metrics(p_cart_uuid);
    IF k.menge <= 0 THEN
        RETURN;
    END IF;

    IF land IS NULL THEN
        sperre := 'country_unknown';
    ELSIF EXISTS (SELECT 1 FROM sales_channel_country_shop cc WHERE cc.channel_id = k.kanal)
          AND NOT EXISTS (SELECT 1 FROM sales_channel_country_shop cc
                           WHERE cc.channel_id = k.kanal AND cc.iso_code = land) THEN
        sperre := 'country_not_delivered';
    END IF;
    SELECT zc.zone_id INTO zone FROM shipping_zone_country_shop zc WHERE zc.iso_code = land;

    IF sperre IS NULL THEN
        SELECT v.shipping_method_id INTO gewaehlt
          FROM shop_shipping_select(k.kanal, zone, k.menge, k.gewicht, k.ohne_gewicht,
                                    k.kante, k.breite, k.hoehe, k.groesse, k.gurt, k.erlaubt, p_goods_value,
                                    k.gewaehlt) v;
    END IF;

    RETURN QUERY
    SELECT c.shipping_method_id, c.description, c.customer_note,
           CASE WHEN sperre IS NULL THEN c.price END,
           c.free,
           sperre IS NULL AND c.available,
           COALESCE(sperre, c.reason),
           COALESCE(c.shipping_method_id = gewaehlt, false),
           c.forced,
           CASE WHEN c.forced THEN k.restricted_by END
      FROM shop_shipping_candidates(k.kanal, zone, k.menge, k.gewicht, k.ohne_gewicht,
                                    k.kante, k.breite, k.hoehe, k.groesse, k.gurt, k.erlaubt, p_goods_value) c
     WHERE c.selectable OR c.forced
     ORDER BY (sperre IS NULL AND c.available) DESC,
              (COALESCE(c.rate_price, 0) > 0) DESC, c.price, c.rank DESC, c.shipping_method_id;
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
-- zugeordnete Versandart; weight, length (längste Seite), width (mittlere),
-- height (kürzeste), size (längste + kürzeste), girth und weightunit die
-- Werte, an denen gemessen wurde — für die Meldung.
--
-- Die Ergebnisspalten haben sich geändert (width, height, size) — das lässt
-- CREATE OR REPLACE nicht zu, deshalb erst entfernen.
DROP FUNCTION IF EXISTS shop_part_shipping_check(integer, integer);
CREATE FUNCTION shop_part_shipping_check(p_parts_id integer, p_channel_id integer)
RETURNS TABLE (status text, method text, weight numeric, length numeric, width numeric, height numeric,
               size numeric, girth numeric, weightunit text)
LANGUAGE plpgsql STABLE AS $$
#variable_conflict use_column
DECLARE
    menge        numeric;
    gewicht      numeric;
    ohne_gewicht boolean;
    kante        numeric;
    breite       numeric;
    hoehe        numeric;
    groesse      numeric;
    gurt         numeric;
    erlaubt      integer[];
    einheit      text := (SELECT d.weightunit FROM defaults d LIMIT 1);
BEGIN
    SELECT GREATEST(COALESCE(ps.min_qty, 1), 1),
           GREATEST(COALESCE(ps.min_qty, 1), 1) * COALESCE(p.weight, 0),
           p.part_type <> 'service' AND COALESCE(p.weight, 0) <= 0,
           GREATEST(ps.length, ps.width, ps.height),
           -- Sortiert wie in shop_cart_shipping, nur mit allen drei Maßen
           ps.length + ps.width + ps.height
               - GREATEST(ps.length, ps.width, ps.height) - LEAST(ps.length, ps.width, ps.height),
           CASE WHEN ps.length + ps.width + ps.height IS NOT NULL
                THEN LEAST(ps.length, ps.width, ps.height) END,
           CASE WHEN ps.length + ps.width + ps.height IS NOT NULL
                THEN GREATEST(ps.length, ps.width, ps.height) + LEAST(ps.length, ps.width, ps.height) END,
           GREATEST(ps.length, ps.width, ps.height)
               + 2 * (COALESCE(ps.length, 0) + COALESCE(ps.width, 0) + COALESCE(ps.height, 0)
                      - GREATEST(ps.length, ps.width, ps.height)),
           shop_part_allowed_methods(p.id)
      INTO menge, gewicht, ohne_gewicht, kante, breite, hoehe, groesse, gurt, erlaubt
      FROM parts p
      LEFT JOIN parts_shipping_shop ps ON ps.parts_id = p.id
     WHERE p.id = p_parts_id;

    -- Unbekannter Artikel, Versandartikel oder Versand nicht eingerichtet:
    -- nichts zu prüfen
    IF menge IS NULL OR shop_is_shipping_part(p_parts_id) OR NOT shop_shipping_configured() THEN
        RETURN QUERY SELECT 'ok'::text, NULL::text, gewicht, kante, breite, hoehe, groesse, gurt, einheit;
        RETURN;
    END IF;

    IF EXISTS (
        SELECT 1
          FROM shipping_method_shop m
         WHERE m.active
           AND (erlaubt IS NULL OR m.id = ANY (erlaubt))
           -- nicht freigeschaltete nur, wenn eine Zuordnung sie vorgibt
           AND (m.selectable OR erlaubt IS NOT NULL)
           AND (m.max_weight IS NULL OR gewicht <= m.max_weight)
           AND (m.max_length IS NULL OR kante IS NULL OR kante <= m.max_length)
           AND (m.max_width IS NULL OR breite IS NULL OR breite <= m.max_width)
           AND (m.max_height IS NULL OR hoehe IS NULL OR hoehe <= m.max_height)
           AND (m.max_size IS NULL OR groesse IS NULL OR groesse <= m.max_size)
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
        RETURN QUERY SELECT 'ok'::text, NULL::text, gewicht, kante, breite, hoehe, groesse, gurt, einheit;
        RETURN;
    END IF;

    -- Warum keine passt — in derselben Reihenfolge wie im Warenkorb
    IF ohne_gewicht AND EXISTS (
           SELECT 1 FROM shipping_method_shop m
            WHERE m.active AND (erlaubt IS NULL OR m.id = ANY (erlaubt))
              AND (m.selectable OR erlaubt IS NOT NULL)
              AND (m.max_weight IS NOT NULL
                   OR EXISTS (SELECT 1 FROM shipping_rate_shop r
                               WHERE r.shipping_method_id = m.id AND r.weight_from > 0))) THEN
        RETURN QUERY SELECT 'weight_missing'::text, NULL::text, gewicht, kante, breite, hoehe, groesse, gurt, einheit;
    ELSIF erlaubt IS NOT NULL THEN
        RETURN QUERY SELECT 'assigned_unfit'::text,
                            (SELECT string_agg(m.description, ', ' ORDER BY m.rank DESC, m.description)
                               FROM shipping_method_shop m WHERE m.id = ANY (erlaubt)),
                            gewicht, kante, breite, hoehe, groesse, gurt, einheit;
    ELSE
        RETURN QUERY SELECT 'no_method'::text, NULL::text, gewicht, kante, breite, hoehe, groesse, gurt, einheit;
    END IF;
END;
$$;

-- Versandpreise eines Artikels für die Shop-Karte (Artikel bearbeiten): je
-- Zone und für alle übrigen Länder die Versandart, die der Warenkorb wählen
-- würde, und ihr Preis — für einen Warenkorb aus nur diesem Artikel in seiner
-- Mindestabnahme (sonst 1 Stück), dieselbe Regel (shop_shipping_select).
-- Preise netto oder brutto wie parts.sellprice (shop_tax_included); die
-- Freigrenze misst wie im Warenkorb den Bruttowarenwert.
--
-- zone_id NULL = alle übrigen Länder. status wie shop_cart_shipping, dazu
-- country_not_delivered, wenn die Lieferländer des Kanals kein Land der Zone
-- enthalten. free_from ist die Freigrenze des Kanals (NULL = keine),
-- free_applies, ob sie für die gewählte Versandart gilt. cost sind die
-- Kosten der gewählten Preisstufe (netto, NULL = nicht gepflegt), price_net
-- der Preis netto — bei Bruttopreisen mit dem Steuersatz des Versandartikels
-- zurückgerechnet —, damit beide vergleichbar sind. Für
-- Versandartikel und unbekannte Artikel keine Zeilen.
-- Ergebnisspalten price_net und cost kamen hinzu (2026-10-02) — erst entfernen.
DROP FUNCTION IF EXISTS shop_part_shipping_prices(integer, integer);
CREATE FUNCTION shop_part_shipping_prices(p_parts_id integer, p_channel_id integer)
RETURNS TABLE (zone_id integer, zone text, status text, method text, price numeric, free boolean,
               free_from numeric, free_applies boolean, price_net numeric, cost numeric)
LANGUAGE plpgsql STABLE AS $$
#variable_conflict use_column
DECLARE
    menge        numeric;
    gewicht      numeric;
    ohne_gewicht boolean;
    kante        numeric;
    breite       numeric;
    hoehe        numeric;
    groesse      numeric;
    gurt         numeric;
    erlaubt      integer[];
    warenwert    numeric;
    grenze       numeric := (SELECT c.free_shipping_from FROM sales_channel_shop c WHERE c.id = p_channel_id);
    brutto       boolean := COALESCE((SELECT lower(btrim(d.value)) FROM defaults_oserp d
                                       WHERE d.key = 'shop_tax_included'), '') IN ('t', 'true', '1', 'y', 'yes');
    steuerzone   text    := COALESCE((SELECT d.value FROM defaults_oserp d
                                       WHERE d.key = 'shop_standard_taxzone'), 'Inland');
    begrenzt     boolean := EXISTS (SELECT 1 FROM sales_channel_country_shop cc WHERE cc.channel_id = p_channel_id);
    z            record;
BEGIN
    SELECT GREATEST(COALESCE(ps.min_qty, 1), 1),
           GREATEST(COALESCE(ps.min_qty, 1), 1) * COALESCE(p.weight, 0),
           p.part_type <> 'service' AND COALESCE(p.weight, 0) <= 0,
           GREATEST(ps.length, ps.width, ps.height),
           ps.length + ps.width + ps.height
               - GREATEST(ps.length, ps.width, ps.height) - LEAST(ps.length, ps.width, ps.height),
           CASE WHEN ps.length + ps.width + ps.height IS NOT NULL
                THEN LEAST(ps.length, ps.width, ps.height) END,
           CASE WHEN ps.length + ps.width + ps.height IS NOT NULL
                THEN GREATEST(ps.length, ps.width, ps.height) + LEAST(ps.length, ps.width, ps.height) END,
           GREATEST(ps.length, ps.width, ps.height)
               + 2 * (COALESCE(ps.length, 0) + COALESCE(ps.width, 0) + COALESCE(ps.height, 0)
                      - GREATEST(ps.length, ps.width, ps.height)),
           shop_part_allowed_methods(p.id),
           -- Bruttowarenwert wie im Warenkorb: Kanalpreis, bei Nettopreisen mit
           -- dem Steuersatz der Standard-Steuerzone
           GREATEST(COALESCE(ps.min_qty, 1), 1) * COALESCE(shop_channel_price(p.id, p_channel_id), 0)
               * CASE WHEN COALESCE((SELECT lower(btrim(d.value)) FROM defaults_oserp d
                                      WHERE d.key = 'shop_tax_included'), '') IN ('t', 'true', '1', 'y', 'yes')
                      THEN 1
                      ELSE 1 + shop_tax_rate(p.buchungsgruppen_id,
                                             COALESCE((SELECT d.value FROM defaults_oserp d
                                                        WHERE d.key = 'shop_standard_taxzone'), 'Inland')) END
      INTO menge, gewicht, ohne_gewicht, kante, breite, hoehe, groesse, gurt, erlaubt, warenwert
      FROM parts p
      LEFT JOIN parts_shipping_shop ps ON ps.parts_id = p.id
     WHERE p.id = p_parts_id;

    IF menge IS NULL OR shop_is_shipping_part(p_parts_id) THEN
        RETURN;
    END IF;

    FOR z IN SELECT x.id, x.description
               FROM (SELECT zs.id, zs.description, zs.sortkey, 0 AS rest FROM shipping_zone_shop zs
                     UNION ALL
                     SELECT NULL, NULL, NULL, 1) x
              ORDER BY x.rest, x.sortkey, x.description
    LOOP
        -- Lieferländer des Kanals: liefert er in kein Land dieser Zone (bzw.
        -- in kein Land ohne Zone), entfällt sie
        IF begrenzt AND NOT EXISTS (
               SELECT 1 FROM sales_channel_country_shop cc
                WHERE cc.channel_id = p_channel_id
                  AND CASE WHEN z.id IS NULL
                           THEN NOT EXISTS (SELECT 1 FROM shipping_zone_country_shop zc WHERE zc.iso_code = cc.iso_code)
                           ELSE EXISTS (SELECT 1 FROM shipping_zone_country_shop zc
                                         WHERE zc.iso_code = cc.iso_code AND zc.zone_id = z.id) END) THEN
            RETURN QUERY SELECT z.id, z.description::text, 'country_not_delivered'::text, NULL::text,
                                NULL::numeric, false, grenze, false, NULL::numeric, NULL::numeric;
            CONTINUE;
        END IF;

        RETURN QUERY
        SELECT z.id, z.description::text, v.status, v.description, v.price, v.free, grenze,
               COALESCE((SELECT m.free_shipping_applies FROM shipping_method_shop m
                          WHERE m.id = v.shipping_method_id), false),
               CASE WHEN v.status <> 'ok' THEN NULL
                    WHEN brutto THEN v.price / (1 + shop_tax_rate((SELECT vp.buchungsgruppen_id FROM parts vp
                                                                     WHERE vp.id = v.parts_id), steuerzone))
                    ELSE v.price END,
               v.cost
          FROM shop_shipping_select(p_channel_id, z.id, menge, gewicht, ohne_gewicht,
                                    kante, breite, hoehe, groesse, gurt, erlaubt, warenwert, NULL) v;
    END LOOP;
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

-- Versandwahl in der Kasse (dev/shop-versand.md, 2026-10-30): die Versandart,
-- die der Kunde gewählt hat. NULL = keine Wahl, es gilt die Vorauswahl
-- (günstigste kostenpflichtige, siehe shop_shipping_select); passt die
-- gewählte nicht mehr, ebenso. Die Rechnung setzt die Wahl zurück (cartClear).
ALTER TABLE carts_hugoshop ADD COLUMN IF NOT EXISTS shipping_method_id integer
    REFERENCES shipping_method_shop (id) ON DELETE SET NULL;
COMMENT ON COLUMN carts_hugoshop.shipping_method_id IS 'In der Kasse gewählte Versandart, NULL = die Vorauswahl (günstigste kostenpflichtige)';

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
-- Die Sitzung des Besuchers (Cookie HUGOSHOPCLIENTID) hält der Kern in
-- portal_context_oserp (dev/kundenportal.md). Der Shop hängt seinen Warenkorb
-- daran: portal_context_oserp.cart_uuid zeigt auf carts_hugoshop.
DO $$ BEGIN
    IF to_regclass('portal_context_oserp') IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM pg_constraint
                        WHERE conrelid = 'portal_context_oserp'::regclass
                          AND conname = 'portal_context_oserp_cart_uuid_fk') THEN
        ALTER TABLE portal_context_oserp
            ADD CONSTRAINT portal_context_oserp_cart_uuid_fk
            FOREIGN KEY (cart_uuid) REFERENCES carts_hugoshop (uuid) ON DELETE CASCADE;
    END IF;
END $$;

-- ============================================================================
-- RECHNUNGSVERKNUEPFUNG UND ZAHLUNGSSTAND
-- ============================================================================
--
-- Der Rechnungslink selbst (uuid, ar_id, Webseite) liegt seit 2026-10-21 im
-- Kern (invoice_link_oserp, dev/kundenportal.md). Diese Tabelle trägt zur
-- selben uuid, was der Shop dazu weiß: PayPal-Buchung und Zahlungsstand.
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
-- BESTELLSTATUS UND LIEFERSTATUS (dev/shop-bestellstatus.md)
-- ============================================================================

-- Je Bestellung (Rechnung aus HugoShop oder eBay) der von Hand gesetzte
-- Bestell- und Lieferstatus. NULL heißt automatisch: dann gilt, was
-- shop_order_state() aus Storno, DHL-Etikett und Zahlung ableitet. Eine Zeile
-- entsteht erst, wenn jemand einen Status setzt oder eine Mail verschickt wird.
CREATE TABLE IF NOT EXISTS ar_status_shop
(
    ar_id                   integer NOT NULL,
    order_status            text,
    order_mtime             timestamp without time zone,
    delivery_status         text,
    delivery_mtime          timestamp without time zone,
    delivery_notified       text,
    delivery_notified_mtime timestamp without time zone,
    CONSTRAINT ar_status_shop_pkey PRIMARY KEY (ar_id),
    CONSTRAINT ar_status_shop_ar_id_fkey FOREIGN KEY (ar_id)
        REFERENCES ar (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ar_status_shop_order_status_check
        CHECK (order_status IN ('open', 'processing', 'completed', 'cancelled')),
    CONSTRAINT ar_status_shop_delivery_status_check
        CHECK (delivery_status IN ('open', 'partially_shipped', 'shipped',
                                   'partially_returned', 'returned', 'cancelled')),
    CONSTRAINT ar_status_shop_delivery_notified_check
        CHECK (delivery_notified IN ('open', 'partially_shipped', 'shipped',
                                     'partially_returned', 'returned', 'cancelled'))
);

COMMENT ON TABLE  ar_status_shop                   IS 'Shop: Bestell- und Lieferstatus je Rechnung aus einem Verkaufskanal';
COMMENT ON COLUMN ar_status_shop.order_status      IS 'Von Hand gesetzter Bestellstatus — NULL = automatisch (shop_order_state)';
COMMENT ON COLUMN ar_status_shop.delivery_status   IS 'Von Hand gesetzter Lieferstatus — NULL = automatisch (shop_order_state)';
COMMENT ON COLUMN ar_status_shop.delivery_notified IS 'Zuletzt per Mail an den Kunden gemeldeter Lieferstatus';

-- Wirksamer und abgeleiteter Status einer Bestellung
--
-- Lieferstatus abgeleitet: storniert → cancelled, DHL-Etikett zur Rechnung
-- → shipped, sonst open. Bestellstatus abgeleitet aus dem wirksamen
-- Lieferstatus: storniert oder Lieferung abgebrochen → cancelled, bezahlt
-- und versandt → completed, Lieferung nicht mehr offen → processing, sonst
-- open. Von Hand gesetzt hat immer Vorrang.
--
-- dhl_shipments und ebay_orders gehören der CRM-Basis und fehlen, wenn sie
-- älter ist — deshalb über EXECUTE und nur, wenn es sie gibt.
DROP FUNCTION IF EXISTS shop_order_state(integer);
CREATE FUNCTION shop_order_state(p_ar_id integer)
RETURNS TABLE (order_status text, order_auto text, order_manual boolean,
               delivery_status text, delivery_auto text, delivery_manual boolean,
               paid boolean)
LANGUAGE plpgsql STABLE AS $$
DECLARE
    v_betrag        numeric;
    v_gezahlt       numeric;
    v_storno        boolean;
    v_bestell_hand  text;
    v_liefer_hand   text;
    v_etikett       boolean := false;
    v_ebay          boolean := false;
BEGIN
    SELECT a.amount, COALESCE(a.paid, 0), COALESCE(a.storno, false)
      INTO v_betrag, v_gezahlt, v_storno
      FROM ar a WHERE a.id = p_ar_id;
    IF NOT FOUND THEN
        RETURN;
    END IF;

    SELECT s.order_status, s.delivery_status
      INTO v_bestell_hand, v_liefer_hand
      FROM ar_status_shop s WHERE s.ar_id = p_ar_id;

    IF to_regclass('dhl_shipments') IS NOT NULL THEN
        EXECUTE 'SELECT EXISTS (SELECT 1 FROM dhl_shipments
                                 WHERE record_type = ''invoice'' AND record_id = $1)'
           INTO v_etikett USING p_ar_id;
    END IF;
    IF to_regclass('ebay_orders') IS NOT NULL THEN
        EXECUTE 'SELECT EXISTS (SELECT 1 FROM ebay_orders WHERE ar_id = $1)'
           INTO v_ebay USING p_ar_id;
    END IF;

    -- Bezahlt: eBay kassiert vor dem Import; PayPal meldet COMPLETED (Zeilen
    -- aus der Zeit vor payment_status: die Payer-Id); sonst die Buchung
    paid := v_ebay
         OR (v_betrag > 0 AND v_gezahlt >= v_betrag - 0.005)
         OR EXISTS (SELECT 1 FROM ar_link_hugoshop l
                     WHERE l.ar_id = p_ar_id
                       AND (l.payment_status = 'COMPLETED'
                            OR (l.payment_status IS NULL AND l.paypal IS NOT NULL)));

    delivery_auto := CASE WHEN v_storno THEN 'cancelled'
                          WHEN v_etikett THEN 'shipped'
                          ELSE 'open' END;
    delivery_manual := v_liefer_hand IS NOT NULL;
    delivery_status := COALESCE(v_liefer_hand, delivery_auto);

    order_auto := CASE WHEN v_storno OR delivery_status = 'cancelled' THEN 'cancelled'
                       WHEN paid AND delivery_status = 'shipped' THEN 'completed'
                       WHEN delivery_status <> 'open' THEN 'processing'
                       ELSE 'open' END;
    order_manual := v_bestell_hand IS NOT NULL;
    order_status := COALESCE(v_bestell_hand, order_auto);

    RETURN NEXT;
END;
$$;

COMMENT ON FUNCTION shop_order_state(integer) IS 'Shop: wirksamer und abgeleiteter Bestell- und Lieferstatus einer Rechnung (dev/shop-bestellstatus.md)';

-- ============================================================================
-- LIEFERADRESSE JE RECHNUNG (dev/shop-adressen.md)
-- ============================================================================

-- Lieferadressen aus der Zeit der Bridge haben kein Modul (NULL). Faktura und
-- Kundenverwaltung lesen nur module = 'CT' und zeigen sie deshalb nicht an.
-- Nachgetragen wird nur, wo trans_id ein Kunde oder Lieferant ist — eine
-- Zeile ohne solchen Eigentümer bleibt, wie sie ist. Läuft bei jedem
-- Schema-Update und findet nach dem ersten Mal nichts mehr.
UPDATE shipto s
   SET module = 'CT'
 WHERE s.module IS NULL
   AND (EXISTS (SELECT 1 FROM customer c WHERE c.id = s.trans_id)
        OR EXISTS (SELECT 1 FROM vendor v WHERE v.id = s.trans_id));

-- Jede Shop-Rechnung bekommt eine eigene Lieferadresse wie in kivitendo:
-- eine shipto-Zeile mit module = 'AR' und trans_id = Rechnung. Ändert oder
-- löscht der Kunde danach eine Adresse in seinem Konto (module 'CT', bei
-- alten Zeilen aus der Bridge NULL), bleibt die Anschrift der Rechnung stehen.
--
-- Quelle in dieser Reihenfolge: p_adresse (eBay), p_quelle (gewählte oder in
-- der Kasse angelegte Lieferadresse), sonst der Kundenstamm (E1: auch ohne
-- abweichende Lieferadresse wird die Anschrift festgehalten). Danach zeigt
-- ar.shipto_id auf die Kopie.
--
-- Gäste (E2): die Quelle war nur der Träger der Adresse durch die Kasse und
-- über PayPal — wiederverwenden kann ein Gast sie nicht. Mit
-- p_gast_aufraeumen wird sie nach dem Kopieren entfernt, sofern kein anderer
-- Beleg darauf zeigt.
DROP FUNCTION IF EXISTS shop_invoice_shipto(integer, integer, jsonb, boolean);
CREATE FUNCTION shop_invoice_shipto(p_ar_id integer, p_quelle integer DEFAULT NULL,
                                    p_adresse jsonb DEFAULT NULL,
                                    p_gast_aufraeumen boolean DEFAULT true)
RETURNS integer
LANGUAGE plpgsql AS $$
DECLARE
    v_kunde integer;
    v_neu   integer;
BEGIN
    SELECT a.customer_id INTO v_kunde FROM ar a WHERE a.id = p_ar_id;
    IF NOT FOUND THEN
        RETURN NULL;
    END IF;

    IF p_adresse IS NOT NULL THEN
        INSERT INTO shipto (trans_id, module, shiptoname, shiptostreet, shiptozipcode,
                            shiptocity, shiptocountry, shiptophone, shiptoemail)
        VALUES (p_ar_id, 'AR', p_adresse ->> 'name', p_adresse ->> 'street', p_adresse ->> 'zipcode',
                p_adresse ->> 'city', p_adresse ->> 'country', p_adresse ->> 'phone', p_adresse ->> 'email')
        RETURNING shipto_id INTO v_neu;
    ELSIF p_quelle IS NOT NULL THEN
        INSERT INTO shipto (trans_id, module, shiptoname, shiptodepartment_1, shiptodepartment_2,
                            shiptocontact, shiptostreet, shiptozipcode, shiptocity, shiptocountry,
                            shiptophone, shiptofax, shiptoemail)
        SELECT p_ar_id, 'AR', s.shiptoname, s.shiptodepartment_1, s.shiptodepartment_2,
               s.shiptocontact, s.shiptostreet, s.shiptozipcode, s.shiptocity, s.shiptocountry,
               s.shiptophone, s.shiptofax, s.shiptoemail
          FROM shipto s
         WHERE s.shipto_id = p_quelle
        RETURNING shipto_id INTO v_neu;
    END IF;

    IF v_neu IS NULL THEN
        INSERT INTO shipto (trans_id, module, shiptoname, shiptodepartment_1, shiptodepartment_2,
                            shiptocontact, shiptostreet, shiptozipcode, shiptocity, shiptocountry,
                            shiptophone, shiptofax, shiptoemail)
        SELECT p_ar_id, 'AR', c.name, c.department_1, c.department_2,
               c.contact, c.street, c.zipcode, c.city, c.country,
               c.phone, c.fax, c.email
          FROM customer c
         WHERE c.id = v_kunde
        RETURNING shipto_id INTO v_neu;
    END IF;

    UPDATE ar SET shipto_id = v_neu WHERE id = p_ar_id;

    IF p_gast_aufraeumen AND p_quelle IS NOT NULL
       AND EXISTS (SELECT 1 FROM customer_ext ce WHERE ce.customer_id = v_kunde AND ce.portal_guest) THEN
        UPDATE customer_ext SET portal_shipto_id = NULL
         WHERE customer_id = v_kunde AND portal_shipto_id = p_quelle;
        DELETE FROM shipto s
         WHERE s.shipto_id = p_quelle
           AND s.trans_id = v_kunde
           AND COALESCE(s.module, 'CT') = 'CT'
           AND NOT EXISTS (SELECT 1 FROM ar WHERE ar.shipto_id = p_quelle)
           AND NOT EXISTS (SELECT 1 FROM oe WHERE oe.shipto_id = p_quelle)
           AND NOT EXISTS (SELECT 1 FROM delivery_orders d WHERE d.shipto_id = p_quelle);
    END IF;

    RETURN v_neu;
END;
$$;

COMMENT ON FUNCTION shop_invoice_shipto(integer, integer, jsonb, boolean) IS 'Shop: eigene Lieferadresse (shipto, module AR) für eine Rechnung (dev/shop-adressen.md)';

-- Lieferadresse einer eBay-Bestellung aus ihrem Rohdatensatz, in der Form
-- von p_adresse oben; NULL, wenn die Bestellung keine enthält
DROP FUNCTION IF EXISTS shop_ebay_ship_to(jsonb);
CREATE FUNCTION shop_ebay_ship_to(p_roh jsonb)
RETURNS jsonb
LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN z.ziel IS NULL OR z.ziel = 'null'::jsonb THEN NULL ELSE jsonb_build_object(
        'name',    z.ziel ->> 'fullName',
        'street',  btrim(COALESCE(z.ziel -> 'contactAddress' ->> 'addressLine1', '') || ' '
                      || COALESCE(z.ziel -> 'contactAddress' ->> 'addressLine2', '')),
        'zipcode', z.ziel -> 'contactAddress' ->> 'postalCode',
        'city',    z.ziel -> 'contactAddress' ->> 'city',
        'country', z.ziel -> 'contactAddress' ->> 'countryCode',
        'phone',   z.ziel -> 'primaryPhone' ->> 'phoneNumber',
        'email',   z.ziel ->> 'email'
    ) END
      FROM (SELECT p_roh -> 'fulfillmentStartInstructions' -> 0 -> 'shippingStep' -> 'shipTo' AS ziel) z
$$;

-- Bestand einmalig nachziehen (E3): Shop-Rechnungen ohne eigene Lieferadresse
-- bekommen eine Kopie dessen, worauf sie heute zeigen — sonst des
-- Kundenstamms, bei eBay der Adresse aus dem Rohdatensatz. Was der Kunde
-- vorher schon geändert hat, lässt sich nicht zurückholen. Läuft bei jedem
-- Schema-Update, findet nach dem ersten Mal nichts mehr. Ohne Aufräumen der
-- Gast-Adressen: der Bestand bleibt, wie er ist.
SELECT shop_invoice_shipto(a.id, a.shipto_id, NULL, false)
  FROM ar a
 WHERE a.id IN (SELECT l.ar_id FROM ar_link_hugoshop l WHERE l.ar_id IS NOT NULL)
   AND NOT EXISTS (SELECT 1 FROM shipto s
                    WHERE s.shipto_id = a.shipto_id AND s.module = 'AR' AND s.trans_id = a.id);

DO $$
BEGIN
    -- ebay_orders gehört der CRM-Basis und fehlt, wenn sie älter ist
    IF to_regclass('ebay_orders') IS NOT NULL THEN
        PERFORM shop_invoice_shipto(a.id, a.shipto_id,
                                    CASE WHEN a.shipto_id IS NULL THEN shop_ebay_ship_to(e.raw) END,
                                    false)
           FROM ebay_orders e
           JOIN ar a ON a.id = e.ar_id
          WHERE NOT EXISTS (SELECT 1 FROM shipto s
                             WHERE s.shipto_id = a.shipto_id AND s.module = 'AR' AND s.trans_id = a.id);
    END IF;
END;
$$;

-- ============================================================================
-- AUFRAEUMEN
-- ============================================================================
--
-- Warenkoerbe verfallen. Der Trigger haengt am Statement-Trigger der
-- Sitzungen des Kerns (portal_context_oserp): jeder Zugriff eines Besuchers
-- raeumt ein wenig mit auf, es braucht also keinen Cronjob. Die Sitzungen
-- selbst raeumt der Kern (cleanup_portal_context_oserp).
--
-- Die Frist kommt aus defaults_oserp statt fest aus dem Rumpf. Ein Korb mit
-- angemeldetem Kunden bleibt stehen — der Kunde findet ihn beim naechsten
-- Besuch wieder.

CREATE OR REPLACE FUNCTION cleanup_carts_hugoshop() RETURNS trigger AS $$
BEGIN
    DELETE FROM carts_hugoshop
     WHERE active < NOW() - (COALESCE(
               (SELECT value FROM defaults_oserp WHERE key = 'shop_cart_lifetime_hours'),
               '24') || ' hours')::interval
       AND NOT EXISTS (
           SELECT 1 FROM portal_context_oserp
            WHERE cart_uuid = carts_hugoshop.uuid AND customer_id IS NOT NULL
       );
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

-- Alte Sitzungstabelle der Erweiterung: Sitzungen sind flüchtig (Frist
-- shop_context_lifetime_hours), Besucher bekommen beim nächsten Aufruf eine
-- neue — Trigger und Tabelle gehen ohne Übernahme
DO $$ BEGIN
    IF to_regclass('context_hugoshop') IS NOT NULL THEN
        DROP TRIGGER IF EXISTS trigger_cleanup_carts_hugoshop ON context_hugoshop;
        DROP TRIGGER IF EXISTS trigger_cleanup_context_hugoshop ON context_hugoshop;
        DROP TABLE context_hugoshop;
    END IF;
END $$;
DROP FUNCTION IF EXISTS cleanup_context_hugoshop();

-- CREATE OR REPLACE TRIGGER gaebe es erst ab PostgreSQL 14. Das DO-Muster mit
-- Existenzpruefung ist dasselbe wie in lxcars und laeuft ueberall.
DO $$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trigger_cleanup_carts_hugoshop') THEN
        CREATE TRIGGER trigger_cleanup_carts_hugoshop
            AFTER INSERT OR UPDATE ON portal_context_oserp
            FOR EACH STATEMENT EXECUTE FUNCTION cleanup_carts_hugoshop();
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

-- Name, den PayPal dem Kunden als Empfänger zeigt (brand_name). Leer: der
-- Firmenname aus defaults.company. Bis 30.10.2026 ging der Kontoinhaber mit.
INSERT INTO defaults_oserp (key, value) VALUES ('shop_paypal_brand_name', '') ON CONFLICT (key) DO NOTHING;

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

-- Veroeffentlichung (2026-10-07): Jeder HugoShop veröffentlicht über HugoCMS —
-- OSERP schreibt in seine Bereitstellung unter backend/tmp/, überträgt an
-- HugoCMS und lässt dort bauen. Die Betriebsart „lokal“ (OSERP schreibt in ein
-- Verzeichnis auf diesem Server und baut selbst mit hugo) gibt es nicht mehr;
-- mit ihr entfallen die Wurzel aller Webseiten und das Verzeichnis des
-- Bau-Programms.
DELETE FROM defaults_oserp WHERE key IN ('shop_sites_dir', 'shop_publish_command_path');
-- shop_job_retention_days: Der Laeufer loescht erfolgreich erledigte Auftraege
-- aus batchjob_hugoshop, sobald sie so viele Tage alt sind. 0 schaltet das ab;
-- fehlgeschlagene Auftraege bleiben immer stehen.
INSERT INTO defaults_oserp (key, value) VALUES ('shop_job_retention_days', '30') ON CONFLICT (key) DO NOTHING;
-- Groesse der Vorschaubilder (laengste Seite in Pixeln), fuer alle HugoShops
INSERT INTO defaults_oserp (key, value) VALUES ('shop_thumbnail_size', '200') ON CONFLICT (key) DO NOTHING;
-- Lieferstatus für den Kunden (dev/shop-bestellstatus.md): Anzeige auf der
-- Rechnungsseite im Shop und Mail bei Änderung, beides per Vorgabe aus
INSERT INTO defaults_oserp (key, value) VALUES ('shop_delivery_status_show', '0') ON CONFLICT (key) DO NOTHING;
INSERT INTO defaults_oserp (key, value) VALUES ('shop_delivery_status_mail', '0') ON CONFLICT (key) DO NOTHING;
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
        -- HugoShop: Handel. Zugang, Adresse und HugoCMS der Webseite stehen
        -- seit 2026-10-21 beim Kern (website_setting_keys(), dev/kundenportal.md)
        ('hugoshop', 'shop_products_link',                    'products_link',                    false),
        ('hugoshop', 'shop_category_link',                    'category_link',                    false),
        -- Schlüssel im Front Matter der Produktseiten, aus dem Hugo die
        -- Kategorieseiten bildet (Vorgabe kategorien); zusammen mit
        -- category_link legt er den Shop einer Webseite mit eigener
        -- Themen-Taxonomie unter /shop/kategorien/. Den alten Schlüssel gab
        -- es nie, die Abschrift findet nichts.
        ('hugoshop', 'shop_category_taxonomy',                'category_taxonomy',                false),
        -- Bezeichnung der Kategorienübersicht in den Brotkrumen der
        -- Produktseiten (Vorgabe Kategorien), etwa „Sortiment“ — passend zum
        -- Titel der Übersichtsseite auf der Webseite. Den alten Schlüssel gab
        -- es nie.
        ('hugoshop', 'shop_category_title',                   'category_title',                   false),
        ('hugoshop', 'shop_images_link',                      'images_link',                      false),
        ('hugoshop', 'shop_thumbnails_link',                  'thumbnails_link',                  false),
        ('hugoshop', 'shop_downloads_link',                   'downloads_link',                   false),
        -- Pfad der Rechnungsseite (<shop-invoice>), für den Link in der Mail
        -- zum Lieferstatus (dev/shop-bestellstatus.md); leer = /rechnung/.
        -- Den alten Schlüssel gab es nie, die Abschrift findet nichts.
        -- HugoShop: Veröffentlichung. Das Verzeichnis der Produktseiten legt
        -- HugoCMS fest (Freigaben, dev/shop-hugocms-verzeichnisse.md)
        ('hugoshop', 'shop_template_set',                     'template_set',                     false),
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
        ('ebay',     'ebay_employee_login',                   'employee_login',                   false),
        -- eBay (dev/shop-ebay.md): „Mit eBay verbinden“ (RuName der
        -- Rücksprungadresse), Verkäufe mit Prüfung, übernommene Angebote,
        -- Produktseiten. Alte Schlüssel gab es nie.
        ('ebay',     'ebay_ru_name',                          'ru_name',                          false),
        ('ebay',     'ebay_auto_invoice',                     'auto_invoice',                     false),
        ('ebay',     'ebay_import_since',                     'import_since',                     false),
        ('ebay',     'ebay_legacy_sync',                      'legacy_sync',                      false),
        ('ebay',     'ebay_default_buchungsgruppen_id',       'default_buchungsgruppen_id',       false),
        ('ebay',     'ebay_page_template',                    'page_template',                    false),
        ('ebay',     'ebay_page_color',                       'page_color',                       false),
        ('ebay',     'ebay_page_shipping',                    'page_shipping',                    false),
        ('ebay',     'ebay_page_returns',                     'page_returns',                     false),
        ('ebay',     'ebay_page_about',                       'page_about',                       false),
        -- Kleinanzeigen (dev/shop-kleinanzeigen.md): keine Schnittstelle, die
        -- Schlüssel gab es nie in defaults_oserp — die Abschrift findet nichts.
        -- Konto und Standort der Anzeigen
        ('kleinanzeigen', 'kleinanzeigen_account_email',       'account_email',       false),
        ('kleinanzeigen', 'kleinanzeigen_location_zip',        'location_zip',        false),
        ('kleinanzeigen', 'kleinanzeigen_location_city',       'location_city',       false),
        -- Vorgaben für neue Anzeigen
        ('kleinanzeigen', 'kleinanzeigen_price_type',          'price_type',          false),
        ('kleinanzeigen', 'kleinanzeigen_shipping',            'shipping',            false),
        ('kleinanzeigen', 'kleinanzeigen_buy_now',             'buy_now',             false),
        ('kleinanzeigen', 'kleinanzeigen_default_condition',   'default_condition',   false),
        ('kleinanzeigen', 'kleinanzeigen_default_category',    'default_category',    false),
        ('kleinanzeigen', 'kleinanzeigen_text_style',          'text_style',          false),
        ('kleinanzeigen', 'kleinanzeigen_text_footer',         'text_footer',         false),
        ('kleinanzeigen', 'kleinanzeigen_refresh_days',        'refresh_days',        false),
        -- Anfragen aus dem Postfach und Verkaufsabschluss
        ('kleinanzeigen', 'kleinanzeigen_lead_mail_folder',    'lead_mail_folder',    false),
        ('kleinanzeigen', 'kleinanzeigen_default_customer_id', 'default_customer_id', false),
        ('kleinanzeigen', 'kleinanzeigen_employee_login',      'employee_login',      false),
        -- Feed für Sync-Werkzeuge: der Schlüssel ist ein Geheimnis
        ('kleinanzeigen', 'kleinanzeigen_feed_key',            'feed_key',            true),
        -- Kleinanzeigen-Dienst (Headless-Browser): Konto, Anmelde-IDs (Cookies,
        -- die der Benutzer nach seiner Anmeldung im eigenen Browser übergibt),
        -- Name im Anzeigenformular
        ('kleinanzeigen', 'kleinanzeigen_login_email',         'login_email',         false),
        ('kleinanzeigen', 'kleinanzeigen_login_cookies',       'login_cookies',       true),
        ('kleinanzeigen', 'kleinanzeigen_contact_name',        'contact_name',        false)
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

-- Kleinanzeigen: Der Dienst meldet sich nicht mehr mit Passwort an (der
-- Benutzer übergibt die Anmelde-IDs aus seinem Browser) — ein früher
-- hinterlegtes Passwort wird gelöscht
DELETE FROM sales_channel_secret_shop s
 USING sales_channel_shop c
 WHERE s.channel_id = c.id AND c.type = 'kleinanzeigen' AND s.key = 'login_password';

-- Schritt 5 (dev/shop-mehrere-kanaele.md): Die Instanz-Einstellungen werden
-- in der Kanalkarte gepflegt. Die Abschrift oben hat die letzten Werte aus
-- defaults_oserp übernommen; danach verschwinden die alten Schlüssel — auch
-- die Laufzeitwerte des eBay-Kanals, die seit Schritt 4 im Kanal stehen —
-- und mit ihnen der Übergangs-Trigger aus Schritt 2.
DELETE FROM defaults_oserp
 WHERE key IN (SELECT old_key FROM shop_channel_setting_keys())
    OR key IN ('ebay_access_token', 'ebay_access_token_exp', 'ebay_order_last_check');

-- Betriebsart „lokal“ entfallen (2026-10-07): ihre Einstellungen — im Kanal
-- und, falls noch vorhanden, unter den alten Schlüsseln
UPDATE sales_channel_shop
   SET settings = settings - 'site_dir' - 'images_dir' - 'thumbnails_dir'
                           - 'publish_mode' - 'publish_clean_destination'
 WHERE type = 'hugoshop'
   -- ohne den jsonb-Operator ?|: PDO hielte das Fragezeichen für einen Platzhalter
   AND (settings ->> 'site_dir' IS NOT NULL OR settings ->> 'images_dir' IS NOT NULL
        OR settings ->> 'thumbnails_dir' IS NOT NULL OR settings ->> 'publish_mode' IS NOT NULL
        OR settings ->> 'publish_clean_destination' IS NOT NULL);
DELETE FROM defaults_oserp
 WHERE key IN ('shop_site_dir', 'shop_images_dir', 'shop_thumbnails_dir',
               'shop_publish_mode', 'shop_publish_clean_destination');

-- Verzeichnis der Produktseiten entfallen (2026-10-09, E3 in
-- dev/shop-hugocms-verzeichnisse.md): Es legt HugoCMS fest (Freigabe
-- „Produktseiten“), der Lauf fragt es dort ab — OSERP hält keine eigene Kopie
UPDATE sales_channel_shop
   SET settings = settings - 'content_dir'
 WHERE type = 'hugoshop'
   AND settings -> 'content_dir' IS NOT NULL;
DELETE FROM defaults_oserp WHERE key = 'shop_content_dir';

DROP TRIGGER IF EXISTS trigger_defaults_oserp_shop_channel_sync ON defaults_oserp;
DROP FUNCTION IF EXISTS defaults_oserp_shop_channel_sync();

-- Der Schlüssel des Zugangs gehört seit 2026-10-21 der Webseite des Kerns
-- (website_secret_oserp); der alte Index am Kanal entfällt
DROP INDEX IF EXISTS sales_channel_secret_shop_public_key;

-- Übernahme (dev/kundenportal.md): Ein HugoShop ohne Webseite, der vor dem
-- Kundenportal eingerichtet war (Adresse, Zugang oder HugoCMS in seinen
-- Einstellungen), bekommt eine Webseite aus diesen Einstellungen — Name und
-- Schaltzustand wie der Kanal; die Schlüssel verlassen den Kanal. Danach
-- zeigen Sitzungen und Rechnungslinks auf die Webseite (unten). Läuft nur
-- einmal je Kanal. Der leere HugoShop-Kanal einer frischen Aktivierung
-- bekommt keine Webseite: die legt man unter Firmenkonfiguration →
-- Kundenportal an und ordnet sie dem Kanal im Feld „Webseite“ zu.
DO $$
DECLARE
    k        record;
    neu      integer;
    schluessel text[] := ARRAY['allowed_origins', 'base_url', 'backend_url', 'invoice_page', 'hugocms_url'];
BEGIN
    IF to_regclass('website_oserp') IS NULL THEN
        RETURN;
    END IF;
    FOR k IN SELECT c.id, c.name, c.active, c.settings FROM sales_channel_shop c
              WHERE c.type = 'hugoshop' AND c.website_id IS NULL
                AND (COALESCE(c.settings ->> 'base_url', '') <> ''
                     OR COALESCE(c.settings ->> 'backend_url', '') <> ''
                     OR COALESCE(c.settings ->> 'hugocms_url', '') <> ''
                     OR EXISTS (SELECT 1 FROM sales_channel_secret_shop s
                                 WHERE s.channel_id = c.id AND s.key IN ('public_key', 'hugocms_key') AND s.value <> ''))
              ORDER BY c.id
    LOOP
        INSERT INTO website_oserp (name, active, sortkey, settings)
        VALUES (COALESCE(NULLIF(k.name, ''), 'Webseite ' || k.id), COALESCE(k.active, false), k.id,
                website_default_settings()
                || COALESCE((SELECT jsonb_object_agg(e.key, e.value)
                               FROM jsonb_each(COALESCE(k.settings, '{}'::jsonb)) e
                              WHERE e.key = ANY (schluessel)), '{}'::jsonb)
                || jsonb_strip_nulls(jsonb_build_object(
                       'template_set',    k.settings ->> 'template_set',
                       -- Empfänger des Kontaktformulars war der des Widerrufs
                       'contact_mail_to', k.settings ->> 'withdrawal_mail_to')))
        ON CONFLICT (name) DO UPDATE SET mtime = now()
        RETURNING id INTO neu;

        INSERT INTO website_secret_oserp (website_id, key, value)
        SELECT neu, s.key, s.value
          FROM sales_channel_secret_shop s
         WHERE s.channel_id = k.id AND s.key IN ('public_key', 'hugocms_key') AND s.value <> ''
        ON CONFLICT (website_id, key) DO UPDATE SET value = EXCLUDED.value, mtime = now();
        DELETE FROM sales_channel_secret_shop s
         WHERE s.channel_id = k.id AND s.key IN ('public_key', 'hugocms_key');

        UPDATE sales_channel_shop
           SET website_id = neu,
               settings = COALESCE(settings, '{}'::jsonb) - 'allowed_origins' - 'base_url' - 'backend_url'
                          - 'invoice_page' - 'hugocms_url' - 'public_key' - 'hugocms_key'
         WHERE id = k.id;
    END LOOP;
END $$;

-- Rechnungslinks in den Kern: dieselbe uuid, dazu die Webseite des Kanals
INSERT INTO invoice_link_oserp (uuid, ar_id, website_id, active)
SELECT l.uuid, l.ar_id, c.website_id, l.active
  FROM ar_link_hugoshop l
  LEFT JOIN sales_channel_shop c ON c.id = l.channel_id
 WHERE l.ar_id IS NOT NULL
ON CONFLICT (uuid) DO NOTHING;

-- Ein Kanal je eBay-Konto (M4, dev/shop-mehrere-kanaele.md, Schritt 4): der
-- Inventareintrag gehört bei eBay dem Konto, zwei Kanäle mit demselben Konto
-- überschrieben sich. account_id bestimmt der Kanal selbst nach jedem neuen
-- Token (shopEbayAccountCheck).
CREATE UNIQUE INDEX IF NOT EXISTS sales_channel_shop_ebay_account_key
    ON sales_channel_shop ((settings ->> 'account_id'))
 WHERE type = 'ebay' AND COALESCE(settings ->> 'account_id', '') <> '';

-- ============================================================================
-- MEDIENBIBLIOTHEK (dev/shop-medienbibliothek.md, 2026-10-15)
-- ============================================================================
--
-- Produktbilder und Downloads liegen in OpensourceERP, zentral für alle
-- Verkaufskanäle, unter backend/data/<db>/shop/media/{images,downloads};
-- die Vorschaubilder erzeugt OpensourceERP daneben (thumbnails/). Die Tabelle
-- trägt, was Dateimanager und Übertragung brauchen, ohne die Dateien bei
-- jedem Lauf neu zu lesen. Der Dateiname bleibt der Schlüssel (B2): die
-- Artikel nennen ihn in parts_ext (hugoshop_images, hugoshop_downloads), die
-- Webseite liefert die Datei unter diesem Namen aus.
CREATE TABLE IF NOT EXISTS media_shop
(
    id               integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind             text NOT NULL CHECK (kind IN ('image', 'download')),
    filename         text NOT NULL,
    sha256           text NOT NULL,
    size             bigint NOT NULL,
    mime             text NOT NULL,
    width            integer,
    height           integer,
    thumbnail_sha256 text,
    thumbnail_size   integer,
    itime            timestamp NOT NULL DEFAULT now(),
    mtime            timestamp NOT NULL DEFAULT now(),
    CONSTRAINT media_shop_kind_filename_key UNIQUE (kind, filename)
);

COMMENT ON TABLE  media_shop                  IS 'Medienbibliothek der Shop-Erweiterung: Produktbilder und Downloads (Datei unter backend/data/<db>/shop/media/)';
COMMENT ON COLUMN media_shop.kind             IS 'image (images/) oder download (downloads/)';
COMMENT ON COLUMN media_shop.filename         IS 'Dateiname ohne Verzeichnis — der Schlüssel, den die Artikel nennen';
COMMENT ON COLUMN media_shop.sha256           IS 'Prüfsumme der Datei, für den Abgleich mit der Webseite';
COMMENT ON COLUMN media_shop.thumbnail_sha256 IS 'Prüfsumme des Vorschaubilds (thumbnails/<filename>), nur bei Bildern';
COMMENT ON COLUMN media_shop.thumbnail_size   IS 'Größte Seite, mit der das Vorschaubild erzeugt wurde (shop_thumbnail_size)';

-- Welche Artikel eine Datei nennen. Die Namen in parts_ext können aus der
-- Bridge noch ein Verzeichnis tragen — verglichen wird der Dateiname.
CREATE OR REPLACE FUNCTION shop_media_usage(p_kind text, p_filename text)
RETURNS TABLE (parts_id integer, partnumber text, description text)
    LANGUAGE sql STABLE AS $$
    SELECT p.id, p.partnumber, p.description
      FROM parts_ext pe
      JOIN parts p ON p.id = pe.parts_id
     WHERE CASE p_kind
           WHEN 'image' THEN jsonb_typeof(pe.hugoshop_images) = 'array'
                AND EXISTS (SELECT 1 FROM jsonb_array_elements_text(pe.hugoshop_images) e
                             WHERE regexp_replace(e, '^.*/', '') = p_filename)
           WHEN 'download' THEN jsonb_typeof(pe.hugoshop_downloads) = 'object'
                AND EXISTS (SELECT 1 FROM jsonb_each_text(pe.hugoshop_downloads) d
                             WHERE regexp_replace(d.value, '^.*/', '') = p_filename)
           ELSE false END
     ORDER BY p.partnumber
$$;

-- Umbenennen: die Verweise der Artikel folgen dem neuen Namen. Die Trigger
-- auf parts_ext legen dabei die Aufträge an, die Seiten neu zu schreiben.
-- Liefert die Zahl der geänderten Artikel.
CREATE OR REPLACE FUNCTION shop_media_rename(p_kind text, p_old text, p_new text)
RETURNS integer LANGUAGE plpgsql AS $$
DECLARE
    anzahl integer := 0;
BEGIN
    IF p_kind = 'image' THEN
        UPDATE parts_ext pe
           SET hugoshop_images = (SELECT jsonb_agg(CASE WHEN regexp_replace(x.e, '^.*/', '') = p_old
                                                        THEN to_jsonb(p_new) ELSE to_jsonb(x.e) END ORDER BY x.o)
                                    FROM jsonb_array_elements_text(pe.hugoshop_images) WITH ORDINALITY AS x(e, o))
         WHERE jsonb_typeof(pe.hugoshop_images) = 'array'
           AND EXISTS (SELECT 1 FROM jsonb_array_elements_text(pe.hugoshop_images) e
                        WHERE regexp_replace(e, '^.*/', '') = p_old);
    ELSIF p_kind = 'download' THEN
        UPDATE parts_ext pe
           SET hugoshop_downloads = (SELECT jsonb_object_agg(d.key, CASE WHEN regexp_replace(d.value, '^.*/', '') = p_old
                                                                         THEN p_new ELSE d.value END)
                                       FROM jsonb_each_text(pe.hugoshop_downloads) d)
         WHERE jsonb_typeof(pe.hugoshop_downloads) = 'object'
           AND EXISTS (SELECT 1 FROM jsonb_each_text(pe.hugoshop_downloads) d
                        WHERE regexp_replace(d.value, '^.*/', '') = p_old);
    END IF;
    GET DIAGNOSTICS anzahl = ROW_COUNT;

    UPDATE media_shop SET filename = p_new, mtime = now() WHERE kind = p_kind AND filename = p_old;
    RETURN anzahl;
END;
$$;

-- ============================================================================
-- Verkaufskanal Kleinanzeigen (dev/shop-kleinanzeigen.md)
-- ============================================================================
--
-- Kleinanzeigen.de hat keine Schnittstelle zum Einstellen von Anzeigen. Der
-- Kanal ist deshalb ein „assistierter" Kanal: OpensourceERP bereitet jede
-- Anzeige vollständig vor (Titel, Text, Preis, Bilder, Vorgaben), der
-- Benutzer stellt sie mit wenigen Klicks ein und meldet zurück, dass sie
-- online ist. Danach wacht OpensourceERP: Preis- oder Textänderungen, kein
-- Bestand mehr, Anzeige auffrischen, Anfragen aus dem Postfach, Verkauf mit
-- Rechnung und Lagerbuchung.
--
-- Stand je Artikel in parts_channel_shop:
--   sync_status  NULL/draft = Entwurf (bereit zum Einstellen), active = online,
--                reserved = reserviert, ended = Anzeige bei Kleinanzeigen weg.
--                Verkäufe stehen in kleinanzeigen_sale_shop; ist danach kein
--                Bestand mehr da, bleibt die Anzeige online, bis der Benutzer
--                sie gelöscht meldet (todo delete)
--   external_id  Anzeigennummer bei Kleinanzeigen
--   sync_data    url, online_since, refreshed_at, posted_hash (Stand der
--                Anzeige beim Einstellen — weicht der aktuelle ab, ist die
--                Anzeige zu aktualisieren)
--   settings     price (Festpreis in ganzen Euro, leer = Kanalpreis),
--                price_type, shipping, condition, category — leer = Vorgabe
--                des Kanals
--
-- Was zu tun ist (aktualisieren, löschen, auffrischen) leitet
-- kleinanzeigen_board() live ab — kein Auftrag, kein Läufer nötig.

-- Verkäufe über Kleinanzeigen: je Verkauf eine Zeile, verhindert das Löschen
-- des Kanals (M5) und liefert die Umsatzzahlen des Boards. ar_id kann fehlen,
-- wenn ohne Rechnung verkauft wurde (Privatverkauf aus dem Bestand).
CREATE TABLE IF NOT EXISTS kleinanzeigen_sale_shop
(
    id          integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel_id  integer NOT NULL REFERENCES sales_channel_shop (id),
    parts_id    integer REFERENCES parts (id) ON DELETE SET NULL,
    ar_id       integer REFERENCES ar (id) ON DELETE SET NULL,
    customer_id integer REFERENCES customer (id) ON DELETE SET NULL,
    lead_id     integer,
    qty         numeric(25,5) NOT NULL DEFAULT 1,
    price       numeric(15,2) NOT NULL DEFAULT 0,
    payment     text NOT NULL DEFAULT 'cash',
    buyer_name  text,
    note        text,
    sold_at     timestamp without time zone NOT NULL DEFAULT now(),
    itime       timestamp without time zone DEFAULT now(),
    CONSTRAINT kleinanzeigen_sale_shop_payment_check CHECK (payment IN ('cash', 'transfer', 'paypal', 'secure'))
);

COMMENT ON TABLE  kleinanzeigen_sale_shop         IS 'Shop: Verkäufe über Kleinanzeigen — Umsatz, Rechnung, Käufer';
COMMENT ON COLUMN kleinanzeigen_sale_shop.price   IS 'Erzielter Bruttopreis je Einheit';
COMMENT ON COLUMN kleinanzeigen_sale_shop.payment IS 'cash (bar), transfer (Überweisung), paypal, secure („Sicher bezahlen“ von Kleinanzeigen)';

CREATE INDEX IF NOT EXISTS kleinanzeigen_sale_shop_channel_idx ON kleinanzeigen_sale_shop (channel_id, sold_at DESC);

-- Anfragen zu Anzeigen, aus den Benachrichtigungs-Mails von Kleinanzeigen
-- (Postfach des Mandanten, lead_mail_folder des Kanals). mail_uid hält jede
-- Mail nur einmal fest. parts_id fehlt, wenn sich die Anzeige nicht zuordnen
-- ließ — die Anfrage bleibt trotzdem sichtbar.
CREATE TABLE IF NOT EXISTS kleinanzeigen_lead_shop
(
    id          integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel_id  integer NOT NULL REFERENCES sales_channel_shop (id) ON DELETE CASCADE,
    parts_id    integer REFERENCES parts (id) ON DELETE SET NULL,
    ad_id       text,
    ad_title    text,
    sender_name text,
    message     text,
    mail_uid    text NOT NULL,
    received_at timestamp without time zone NOT NULL DEFAULT now(),
    status      text NOT NULL DEFAULT 'open',
    customer_id integer REFERENCES customer (id) ON DELETE SET NULL,
    note        text,
    itime       timestamp without time zone DEFAULT now(),
    mtime       timestamp without time zone,
    CONSTRAINT kleinanzeigen_lead_shop_key UNIQUE (channel_id, mail_uid),
    CONSTRAINT kleinanzeigen_lead_shop_status_check CHECK (status IN ('open', 'answered', 'won', 'lost'))
);

COMMENT ON TABLE  kleinanzeigen_lead_shop         IS 'Shop: Anfragen zu Kleinanzeigen aus dem Postfach';
COMMENT ON COLUMN kleinanzeigen_lead_shop.status  IS 'open (unbeantwortet), answered, won (verkauft), lost';
COMMENT ON COLUMN kleinanzeigen_lead_shop.mail_uid IS 'Ordner:UID der Mail im Postfach — jede Mail nur einmal';

CREATE INDEX IF NOT EXISTS kleinanzeigen_lead_shop_open_idx ON kleinanzeigen_lead_shop (channel_id, status, received_at DESC);

-- Kategoriebaum von Kleinanzeigen (dev/shop-kleinanzeigen.md K22): vom Dienst
-- aus dem Kategorie-Dialog des Anzeigenformulars gelesen, mandantenweit.
-- Der Pfad („Auto, Rad & Boot > Autoteile & Reifen > Weitere Autoteile“) ist
-- der Schlüssel — er steht in parts_channel_shop.settings.category und in
-- der Kanalvorgabe default_category. Einstellbar sind nur Blätter; die
-- dritte Ebene ist auf der Webseite ein Merkmal („Art“) der Kategorie.
CREATE TABLE IF NOT EXISTS kleinanzeigen_category_shop
(
    path        text PRIMARY KEY,
    parent      text,
    name        text NOT NULL,
    level       integer NOT NULL,
    category_id integer,
    is_leaf     boolean NOT NULL DEFAULT false,
    sort        integer NOT NULL DEFAULT 0,
    mtime       timestamp without time zone DEFAULT now()
);

COMMENT ON TABLE  kleinanzeigen_category_shop             IS 'Shop: Kategoriebaum von Kleinanzeigen, vom Dienst gelesen';
COMMENT ON COLUMN kleinanzeigen_category_shop.path        IS 'Beschriftungen mit „ > “, wie im Dialog der Webseite';
COMMENT ON COLUMN kleinanzeigen_category_shop.category_id IS 'Nummer der Webseite (Ebenen 1–2, /s-…/c<nr>), NULL bei Merkmalsausprägungen';
COMMENT ON COLUMN kleinanzeigen_category_shop.is_leaf     IS 'Nur Blätter lassen sich im Formular übernehmen';

CREATE INDEX IF NOT EXISTS kleinanzeigen_category_shop_parent_idx ON kleinanzeigen_category_shop (parent, sort);

-- Reiner Text aus den Artikelnotizen: kivitendo speichert sie als HTML,
-- Kleinanzeigen nimmt nur Text. Absätze und Zeilenumbrüche bleiben erhalten.
CREATE OR REPLACE FUNCTION kleinanzeigen_plain_text(p_html text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT btrim(regexp_replace(regexp_replace(regexp_replace(regexp_replace(regexp_replace(
               replace(replace(replace(replace(replace(replace(
                   regexp_replace(regexp_replace(COALESCE(p_html, ''),
                       '<\s*(br|/p|/div|/li|/h[1-6]|/tr)\s*/?>', E'\n', 'gi'),
                       '<\s*li[^>]*>', E'\n- ', 'gi'),
                   '&nbsp;', ' '), '&amp;', '&'), '&lt;', '<'), '&gt;', '>'), '&quot;', '"'), '&#39;', ''''),
               '<[^>]+>', '', 'g'),
               E'\r', '', 'g'),
               E'[ \t]+\n', E'\n', 'g'),
               E'\n{3,}', E'\n\n', 'g'),
               E'[ \t]{2,}', ' ', 'g'), E' \n')
$$;

-- Die Anzeige eines Artikels, so wie sie bei Kleinanzeigen einzugeben ist.
-- Eine Stelle für Titel (65 Zeichen), Text (4000 Zeichen, mit dem Fußtext des
-- Kanals), Preis in ganzen Euro brutto (Kleinanzeigen kennt keine Cent),
-- Preisart, Versand, Zustand, Kategorie, Standort und Bilder. hash fasst
-- zusammen, was der Käufer sieht — gemerkt beim Einstellen (posted_hash),
-- verglichen danach.
CREATE OR REPLACE FUNCTION kleinanzeigen_ad(p_parts_id integer, p_channel_id integer) RETURNS jsonb
    LANGUAGE sql STABLE AS $$
    WITH einstellung AS (
        SELECT COALESCE((SELECT lower(btrim(value)) FROM defaults_oserp WHERE key = 'shop_tax_included'), '')
                   IN ('t', 'true', '1', 'y', 'yes') AS brutto,
               COALESCE((SELECT value FROM defaults_oserp WHERE key = 'shop_standard_taxzone'), 'Inland') AS zone
    ), kanal AS (
        SELECT c.id, c.active AS kanal_an, COALESCE(c.settings, '{}'::jsonb) AS s
          FROM sales_channel_shop c WHERE c.id = p_channel_id
    ), artikel AS (
        SELECT p.id, p.partnumber, p.description, p.notes, p.onhand, p.unit, p.part_type, p.obsolete,
               pc.id AS pc_id, COALESCE(pc.active, false) AS aktiv, COALESCE(pc.unavailable, false) AS unavailable,
               pc.sync_status, COALESCE(pc.sync_data, '{}'::jsonb) AS sync_data, pc.external_id, pc.sync_mtime,
               pc.title AS kanal_titel, pc.description AS kanal_text,
               COALESCE(pc.settings, '{}'::jsonb) AS ps, k.s, k.kanal_an,
               shop_channel_price(p.id, k.id) AS preis_basis,
               shop_tax_rate(p.buchungsgruppen_id, e.zone) AS satz, e.brutto
          FROM parts p
          CROSS JOIN kanal k
          CROSS JOIN einstellung e
          LEFT JOIN parts_channel_shop pc ON pc.parts_id = p.id AND pc.channel_id = k.id
         WHERE p.id = p_parts_id
    ), anzeige AS (
        SELECT a.*,
               left(btrim(COALESCE(NULLIF(btrim(a.kanal_titel), ''), a.description, a.partnumber)), 65) AS titel,
               left(concat_ws(E'\n\n',
                        NULLIF(kleinanzeigen_plain_text(COALESCE(NULLIF(a.kanal_text, ''), a.notes)), ''),
                        NULLIF(btrim(a.s ->> 'text_footer'), '')), 4000) AS text,
               COALESCE(NULLIF(btrim(a.ps ->> 'price'), '')::numeric,
                        GREATEST(ROUND(CASE WHEN a.brutto THEN a.preis_basis ELSE a.preis_basis * (1 + a.satz) END), 0))
                   AS preis,
               COALESCE(NULLIF(a.ps ->> 'price_type', ''), NULLIF(a.s ->> 'price_type', ''), 'fixed') AS preisart,
               COALESCE(NULLIF(a.ps ->> 'shipping', ''), NULLIF(a.s ->> 'shipping', ''), 'both') AS versand,
               COALESCE(NULLIF(a.ps ->> 'condition', ''), NULLIF(a.s ->> 'default_condition', ''), '') AS zustand,
               COALESCE(NULLIF(a.ps ->> 'category', ''), NULLIF(a.s ->> 'default_category', ''), '') AS kategorie
          FROM artikel a
    )
    SELECT jsonb_build_object(
               'parts_id',    z.id,
               'partnumber',  z.partnumber,
               'title',       z.titel,
               'text',        z.text,
               'price',       z.preis,
               -- eigener Preis des Artikels im Kanal (settings.price), sonst leer
               'own_price',   NULLIF(btrim(z.ps ->> 'price'), '')::numeric,
               'price_type',  z.preisart,
               'shipping',    z.versand,
               'condition',   z.zustand,
               'category',    z.kategorie,
               'location',    btrim(concat_ws(' ', z.s ->> 'location_zip', z.s ->> 'location_city')),
               'onhand',      z.onhand,
               'unit',        z.unit,
               'images',      (SELECT COALESCE(jsonb_agg(jsonb_build_object(
                                          'id', i.id, 'filename', i.filename,
                                          'url', '/webhook/part-image.php?db=' || current_database()
                                                 || '&id=' || i.parts_id || '&f=' || i.filename)
                                       ORDER BY i.sort, i.id), '[]'::jsonb)
                                 FROM parts_channel_image_shop i
                                WHERE i.parts_id = z.id AND i.channel_id = p_channel_id),
               'active',      z.aktiv,
               'sellable',    z.aktiv AND z.kanal_an AND NOT z.obsolete AND NOT z.unavailable AND COALESCE(z.onhand, 0) > 0,
               'sync_status', COALESCE(z.sync_status, 'draft'),
               'external_id', z.external_id,
               'sync_data',   z.sync_data,
               'sync_mtime',  z.sync_mtime,
               'hash',        md5(concat_ws('|', z.titel, z.text, z.preis::text, z.preisart, z.versand, z.zustand)),
               -- Pflichtangaben für eine Anzeige: Titel, Text, mindestens ein Bild
               'missing',     (SELECT COALESCE(jsonb_agg(f), '[]'::jsonb) FROM (
                                   SELECT 'title' AS f WHERE btrim(z.titel) = ''
                                   UNION ALL SELECT 'text' WHERE btrim(z.text) = ''
                                   UNION ALL SELECT 'image' WHERE NOT EXISTS (SELECT 1 FROM parts_channel_image_shop i
                                                                                WHERE i.parts_id = z.id AND i.channel_id = p_channel_id)
                                   UNION ALL SELECT 'location' WHERE btrim(COALESCE(z.s ->> 'location_zip', '')) = ''
                               ) m)
           )
      FROM anzeige z
$$;

-- Vorschläge: Ware, die Geld bindet und sich nicht bewegt. Bestand mal
-- Einkaufspreis, gewichtet mit den Monaten ohne Verwendung (parts_usage_oserp,
-- Positionen in Belegen). Nur Waren mit Bestand, nicht veraltet, keine
-- Versandartikel, nicht schon in diesem Kanal angeboten. Mit Bild zuerst —
-- eine Anzeige ohne Bild bringt nichts.
CREATE OR REPLACE FUNCTION kleinanzeigen_suggestions(p_channel_id integer, p_limit integer)
RETURNS TABLE (parts_id integer, partnumber text, description text, onhand numeric, unit text,
               lastcost numeric, sellprice numeric, price numeric, capital numeric,
               last_use date, idle_months integer, images integer, score numeric)
    LANGUAGE sql STABLE AS $$
    WITH einstellung AS (
        SELECT COALESCE((SELECT lower(btrim(value)) FROM defaults_oserp WHERE key = 'shop_tax_included'), '')
                   IN ('t', 'true', '1', 'y', 'yes') AS brutto,
               COALESCE((SELECT value FROM defaults_oserp WHERE key = 'shop_standard_taxzone'), 'Inland') AS zone
    ), kandidat AS (
        SELECT p.id, p.partnumber, p.description, p.onhand, p.unit,
               COALESCE(p.lastcost, 0) AS lastcost, COALESCE(p.sellprice, 0) AS sellprice,
               ROUND(COALESCE(p.onhand, 0) * COALESCE(p.lastcost, 0), 2) AS kapital,
               (SELECT max(u.month) FROM parts_usage_oserp u WHERE u.parts_id = p.id AND u.uses > 0) AS letzte,
               (SELECT count(*)::int FROM parts_channel_image_shop i WHERE i.parts_id = p.id) AS bilder,
               shop_channel_price(p.id, p_channel_id) AS preis_basis,
               shop_tax_rate(p.buchungsgruppen_id, e.zone) AS satz, e.brutto
          FROM parts p
          CROSS JOIN einstellung e
         WHERE p.part_type = 'part'
           AND NOT COALESCE(p.obsolete, false)
           AND COALESCE(p.onhand, 0) > 0
           AND NOT shop_is_shipping_part(p.id)
           AND NOT EXISTS (SELECT 1 FROM parts_channel_shop pc
                            WHERE pc.parts_id = p.id AND pc.channel_id = p_channel_id AND pc.active)
    ), bewertet AS (
        SELECT k.*,
               GREATEST(0, (EXTRACT(YEAR FROM age(current_date, COALESCE(k.letzte, date_trunc('month', current_date) - interval '12 months'))) * 12
                          + EXTRACT(MONTH FROM age(current_date, COALESCE(k.letzte, date_trunc('month', current_date) - interval '12 months'))))::int)
                   AS monate
          FROM kandidat k
    )
    SELECT b.id, b.partnumber, b.description, b.onhand, b.unit, b.lastcost, b.sellprice,
           GREATEST(ROUND(CASE WHEN b.brutto THEN b.preis_basis ELSE b.preis_basis * (1 + b.satz) END), 0) AS price,
           b.kapital, b.letzte, b.monate, b.bilder,
           -- Kapitalbindung gewichtet mit der Liegezeit; ohne Bild hinten an
           ROUND(GREATEST(b.kapital, 1) * (1 + b.monate / 6.0) * CASE WHEN b.bilder > 0 THEN 1.5 ELSE 1 END, 2) AS score
      FROM bewertet b
     ORDER BY score DESC, b.id
     LIMIT p_limit
$$;

-- Das Board des Kanals: Kennzahlen und die Listen Entwürfe, Online (mit dem,
-- was zu tun ist), Verkauft und Anfragen — eine Abfrage für die Ansicht.
--   todo  update  Preis, Titel oder Text haben sich seit dem Einstellen geändert
--         delete  nichts mehr da (Bestand 0, abgewählt, veraltet, Kanal aus)
--         refresh älter als refresh_days — bei Kleinanzeigen zählt Aktualität
CREATE OR REPLACE FUNCTION kleinanzeigen_board(p_channel_id integer) RETURNS jsonb
    LANGUAGE sql STABLE AS $$
    WITH kanal AS (
        SELECT c.id, c.name, c.active, COALESCE(c.settings, '{}'::jsonb) AS s,
               GREATEST(COALESCE(NULLIF(btrim(c.settings ->> 'refresh_days'), '')::int, 30), 1) AS refresh_days
          FROM sales_channel_shop c WHERE c.id = p_channel_id
    ), zeile AS (
        SELECT pc.parts_id, pc.active, COALESCE(pc.sync_status, 'draft') AS stand, pc.external_id, pc.sync_mtime,
               pc.sync_error,
               COALESCE(pc.sync_data, '{}'::jsonb) AS d,
               kleinanzeigen_ad(pc.parts_id, k.id) AS ad, k.refresh_days
          FROM parts_channel_shop pc
          CROSS JOIN kanal k
         WHERE pc.channel_id = k.id
           AND (pc.active OR COALESCE(pc.sync_status, 'draft') IN ('active', 'reserved'))
    ), bewertet AS (
        SELECT z.*,
               COALESCE((z.d ->> 'online_since')::timestamp, z.sync_mtime) AS online_seit,
               COALESCE((z.d ->> 'refreshed_at')::timestamp, (z.d ->> 'online_since')::timestamp, z.sync_mtime) AS aufgefrischt,
               (SELECT count(*) FROM kleinanzeigen_lead_shop l
                 WHERE l.channel_id = p_channel_id AND l.parts_id = z.parts_id AND l.status = 'open') AS anfragen,
               CASE WHEN z.stand IN ('active', 'reserved') THEN
                        CASE WHEN NOT (z.ad ->> 'sellable')::boolean
                               OR COALESCE((z.d ->> 'sold_out')::boolean, false) THEN 'delete'
                             WHEN COALESCE(z.d ->> 'posted_hash', '') <> '' AND z.d ->> 'posted_hash' <> z.ad ->> 'hash' THEN 'update'
                             WHEN COALESCE((z.d ->> 'refreshed_at')::timestamp, (z.d ->> 'online_since')::timestamp, z.sync_mtime)
                                  < now() - make_interval(days => z.refresh_days) THEN 'refresh'
                             END
                    END AS todo
          FROM zeile z
    ), karte AS (
        SELECT b.parts_id,
               b.ad || jsonb_build_object(
                   'state', b.stand, 'todo', b.todo, 'leads', b.anfragen,
                   'online_since', b.online_seit, 'refreshed_at', b.aufgefrischt,
                   'days_online', CASE WHEN b.online_seit IS NOT NULL THEN EXTRACT(DAY FROM now() - b.online_seit)::int END,
                   'url', b.d ->> 'url',
                   -- letzter Fehler des Dienstes oder Läufers an dieser Anzeige (Karte zeigt ihn, bis es klappt)
                   'sync_error', NULLIF(btrim(b.sync_error), ''),
                   'sync_mtime', to_char(b.sync_mtime, 'DD.MM.YYYY HH24:MI')) AS karte,
               b.stand, b.todo, b.online_seit
          FROM bewertet b
    ), verkauf AS (
        SELECT v.id, v.parts_id, v.ar_id, v.customer_id, v.qty, v.price, v.payment, v.buyer_name, v.sold_at, v.note,
               p.partnumber, p.description, ar.invnumber, c.name AS customer_name
          FROM kleinanzeigen_sale_shop v
          LEFT JOIN parts p ON p.id = v.parts_id
          LEFT JOIN ar ON ar.id = v.ar_id
          LEFT JOIN customer c ON c.id = v.customer_id
         WHERE v.channel_id = p_channel_id
    )
    SELECT jsonb_build_object(
               'channel', (SELECT jsonb_build_object('channel_id', k.id, 'name', k.name, 'active', k.active,
                                                     'refresh_days', k.refresh_days,
                                                     'location', btrim(concat_ws(' ', k.s ->> 'location_zip', k.s ->> 'location_city')),
                                                     'account_email', COALESCE(k.s ->> 'account_email', ''),
                                                     'lead_last_check', k.s ->> 'lead_last_check')
                             FROM kanal k),
               'counts', jsonb_build_object(
                   'draft',    (SELECT count(*) FROM karte WHERE stand = 'draft'),
                   'active',   (SELECT count(*) FROM karte WHERE stand IN ('active', 'reserved')),
                   'todo',     (SELECT count(*) FROM karte WHERE todo IS NOT NULL),
                   'leads',    (SELECT count(*) FROM kleinanzeigen_lead_shop l WHERE l.channel_id = p_channel_id AND l.status = 'open'),
                   'sold_30d', (SELECT count(*) FROM verkauf WHERE sold_at >= now() - interval '30 days'),
                   'revenue_30d', (SELECT COALESCE(sum(qty * price), 0) FROM verkauf WHERE sold_at >= now() - interval '30 days'),
                   'revenue_total', (SELECT COALESCE(sum(qty * price), 0) FROM verkauf),
                   'online_value', (SELECT COALESCE(sum((karte ->> 'price')::numeric), 0) FROM karte WHERE stand IN ('active', 'reserved'))),
               'drafts', (SELECT COALESCE(jsonb_agg(karte ORDER BY parts_id DESC), '[]'::jsonb) FROM karte WHERE stand = 'draft'),
               'online', (SELECT COALESCE(jsonb_agg(karte ORDER BY (todo IS NULL), online_seit NULLS FIRST), '[]'::jsonb)
                            FROM karte WHERE stand IN ('active', 'reserved')),
               'sold', (SELECT COALESCE(jsonb_agg(to_jsonb(v) ORDER BY v.sold_at DESC), '[]'::jsonb)
                          FROM (SELECT * FROM verkauf ORDER BY sold_at DESC LIMIT 50) v),
               'leads', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
                                     'id', l.id, 'parts_id', l.parts_id, 'partnumber', p.partnumber,
                                     'ad_id', l.ad_id, 'ad_title', COALESCE(NULLIF(l.ad_title, ''), p.description),
                                     'sender_name', l.sender_name, 'message', l.message,
                                     'received_at', l.received_at, 'status', l.status, 'note', l.note,
                                     'customer_id', l.customer_id,
                                     -- aus den Unterhaltungen des Dienstes: Antwort direkt möglich
                                     'conversation', l.mail_uid LIKE 'conv:%')
                                 ORDER BY (l.status = 'open') DESC, l.received_at DESC), '[]'::jsonb)
                           FROM (SELECT * FROM kleinanzeigen_lead_shop l
                                  WHERE l.channel_id = p_channel_id
                                  ORDER BY (l.status = 'open') DESC, l.received_at DESC LIMIT 100) l
                           LEFT JOIN parts p ON p.id = l.parts_id)
           )
$$;

-- ============================================================================
-- EBAY: ÜBERNOMMENE ANGEBOTE, VERKÄUFE MIT PRÜFUNG, PRODUKTSEITEN
-- ============================================================================
--
-- dev/shop-ebay.md. Drei Teile:
--
--   Angebote   Was schon bei eBay steht (über Seller Hub eingestellt, nicht
--              über OSERP), liest der Abgleich in ebay_listing_shop und ordnet
--              es den Artikeln zu: Artikelnummer = SKU, EAN, Herstellernummer,
--              sonst als Vorschlag über den Titel. Eine Zuordnung ist eine
--              Kanalzeile (parts_channel_shop) mit sync_data.legacy_item_id —
--              derselbe Weg wie beim Einstellen: Bestand und Preis lösen über
--              die Trigger einen Auftrag aus, der das bestehende Angebot ändert
--              statt ein zweites anzulegen. Ob OSERP Menge und Preis schreibt,
--              sagt die Kanaleinstellung legacy_sync (off, stock, stock_price).
--   Verkäufe   Bestellungen kommen nach ebay_orders (CRM-Basis) und werden erst
--              abgerechnet, wenn der Käufer klar ist: ebay_customer_match()
--              bewertet die Kunden des Stamms; eindeutige rechnet der Abruf
--              sofort ab, unklare warten in der Prüfung (state review).
--   Seiten     Die Produktseite (Beschreibung bei eBay) entsteht aus einem
--              Aufbau in parts_channel_shop.settings.page — Abschnitte,
--              Merkmale, Vorlage — und steht gerendert in description.

CREATE TABLE IF NOT EXISTS ebay_listing_shop
(
    id                  integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel_id          integer NOT NULL REFERENCES sales_channel_shop (id) ON DELETE CASCADE,
    item_id             text NOT NULL,
    variation_sku       text NOT NULL DEFAULT '',
    sku                 text,
    title               text,
    variation_text      text,
    price               numeric(15,2),
    currency            text,
    quantity            integer,
    quantity_sold       integer,
    lot_size            integer NOT NULL DEFAULT 1,
    ean                 text,
    mpn                 text,
    brand               text,
    image_url           text,
    view_url            text,
    category_id         text,
    category_name       text,
    condition_name      text,
    listing_type        text,
    watch_count         integer,
    start_time          timestamp without time zone,
    end_time            timestamp without time zone,
    status              text NOT NULL DEFAULT 'active',
    parts_id            integer REFERENCES parts (id) ON DELETE SET NULL,
    link_source         text,
    suggestion_parts_id integer REFERENCES parts (id) ON DELETE SET NULL,
    suggestion_score    numeric(5,1),
    suggestion_source   text,
    dismissed_parts_id  integer,
    raw                 jsonb,
    seen_at             timestamp without time zone,
    itime               timestamp without time zone DEFAULT now(),
    mtime               timestamp without time zone,
    CONSTRAINT ebay_listing_shop_key UNIQUE (channel_id, item_id, variation_sku),
    CONSTRAINT ebay_listing_shop_status_check CHECK (status IN ('active', 'ended'))
);

COMMENT ON TABLE  ebay_listing_shop                     IS 'Shop: Angebote eines eBay-Kontos, wie der Abgleich sie zuletzt sah — auch die außerhalb von OSERP eingestellten';
COMMENT ON COLUMN ebay_listing_shop.item_id             IS 'eBay-Artikelnummer (ItemID)';
COMMENT ON COLUMN ebay_listing_shop.variation_sku       IS 'SKU der Variante, leer bei Angeboten ohne Varianten — eine Zeile je Variante';
COMMENT ON COLUMN ebay_listing_shop.quantity            IS 'Bei eBay verfügbare Menge (in Losen)';
COMMENT ON COLUMN ebay_listing_shop.lot_size            IS 'Stück je Los; die Menge bei eBay zählt Lose';
COMMENT ON COLUMN ebay_listing_shop.status              IS 'active (im letzten Abgleich gesehen) oder ended';
COMMENT ON COLUMN ebay_listing_shop.parts_id            IS 'Zugeordneter Artikel; dazu gehört eine Kanalzeile mit sync_data.legacy_item_id';
COMMENT ON COLUMN ebay_listing_shop.link_source         IS 'Wie zugeordnet: sku, ean, mpn, manual, created (aus dem Angebot angelegt), inventory (von OSERP eingestellt)';
COMMENT ON COLUMN ebay_listing_shop.suggestion_parts_id IS 'Vorschlag des Abgleichs, wartet auf Bestätigung';
COMMENT ON COLUMN ebay_listing_shop.dismissed_parts_id  IS 'Abgelehnter Vorschlag — wird nicht wieder vorgeschlagen';

-- Neue eBay-Kanäle: Rechnungen eindeutiger Käufer gleich anlegen, an
-- bestehenden Angeboten nichts schreiben, bis der Benutzer es einschaltet
CREATE OR REPLACE FUNCTION shop_channel_default_settings(p_type text) RETURNS jsonb
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p_type
        WHEN 'hugoshop' THEN jsonb_build_object(
            'downloads_link',                   '/downloads/%s',
            'template_set',                     'standard',
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
            'default_condition',                'NEW',
            'auto_invoice',                     '1',
            'legacy_sync',                      'off',
            'page_template',                    'klar',
            'page_color',                       '#1f6feb')
        -- Kleinanzeigen (dev/shop-kleinanzeigen.md): Preisart Verhandlungsbasis,
        -- Abholung und Versand, Anzeigen nach 30 Tagen auffrischen
        WHEN 'kleinanzeigen' THEN jsonb_build_object(
            'price_type',                       'negotiable',
            'shipping',                         'both',
            'default_condition',                'good',
            'refresh_days',                     '30',
            'lead_mail_folder',                 'INBOX',
            'text_style',                       'sachlich')
        ELSE '{}'::jsonb END
$$;

-- Bestehende eBay-Kanäle bekommen die neuen Vorgaben, ohne Eingetragenes zu ändern
UPDATE sales_channel_shop
   SET settings = jsonb_build_object('auto_invoice', '1', 'legacy_sync', 'off',
                                     'page_template', 'klar', 'page_color', '#1f6feb')
                  || COALESCE(settings, '{}'::jsonb)
 WHERE type = 'ebay'
   AND NOT jsonb_exists(COALESCE(settings, '{}'::jsonb), 'legacy_sync');

-- ── Normalformen für den Abgleich ──
-- Kleinbuchstaben, Umlaute ausgeschrieben, Akzente weg, alles außer Buchstaben
-- und Ziffern als ein Leerzeichen: „Müller-Lüdenscheidt“ = „mueller luedenscheidt“
CREATE OR REPLACE FUNCTION ebay_norm(p text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT btrim(regexp_replace(
               replace(replace(replace(replace(
                   translate(lower(COALESCE(p, '')), 'áàâãåéèêëíìîïóòôõøúùûýÿçñ', 'aaaaaeeeeiiiiooooouuuyycn'),
                   'ä', 'ae'), 'ö', 'oe'), 'ü', 'ue'), 'ß', 'ss'),
               '[^a-z0-9]+', ' ', 'g'))
$$;

-- Name mit sortierten Wörtern: „Müller, Hans“ = „Hans Müller“
CREATE OR REPLACE FUNCTION ebay_norm_name(p text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(string_agg(w, ' ' ORDER BY w), '')
      FROM regexp_split_to_table(ebay_norm(p), ' ') w
     WHERE w <> ''
$$;

-- Straße ohne Leerzeichen, Straße/Str. gleich: „Karl-Frank-Str. 30“ = „Karl Frank Straße 30“
CREATE OR REPLACE FUNCTION ebay_norm_street(p text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT replace(regexp_replace(ebay_norm(p), '(strasse|str)( |$)', 'str\2', 'g'), ' ', '')
$$;

-- Telefon: die letzten neun Ziffern, Ländervorwahl 49 wie die Null; leer unter sechs Ziffern
CREATE OR REPLACE FUNCTION ebay_norm_phone(p text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN length(z) >= 6 THEN right(z, 9) ELSE '' END
      FROM (SELECT regexp_replace(regexp_replace(COALESCE(p, ''), '[^0-9+]', '', 'g'), '^(\+|00)49', '0') AS roh) r
      CROSS JOIN LATERAL (SELECT regexp_replace(r.roh, '[^0-9]', '', 'g') AS z) d
$$;

-- Bruttopreis eines Artikels im Kanal — eBay rechnet brutto
CREATE OR REPLACE FUNCTION ebay_gross_price(p_parts_id integer, p_channel_id integer) RETURNS numeric
    LANGUAGE sql STABLE AS $$
    SELECT ROUND(CASE WHEN COALESCE((SELECT lower(btrim(value)) FROM defaults_oserp WHERE key = 'shop_tax_included'), '')
                           IN ('t', 'true', '1', 'y', 'yes')
                      THEN shop_channel_price(p.id, p_channel_id)
                      ELSE shop_channel_price(p.id, p_channel_id)
                           * (1 + shop_tax_rate(p.buchungsgruppen_id,
                                  COALESCE((SELECT value FROM defaults_oserp WHERE key = 'shop_standard_taxzone'), 'Inland')))
                 END, 2)
      FROM parts p WHERE p.id = p_parts_id
$$;

-- ── Käufer ──
-- Der Käufer einer Bestellung in einer Form: Rechnungsanschrift ist die
-- Registrierungsadresse (buyer.buyerRegistrationAddress), sonst die
-- Lieferanschrift; ship ist immer die Lieferanschrift.
CREATE OR REPLACE FUNCTION ebay_buyer(p_order jsonb) RETURNS jsonb
    LANGUAGE sql IMMUTABLE AS $$
    WITH r AS (
        SELECT NULLIF(p_order -> 'buyer' -> 'buyerRegistrationAddress', 'null'::jsonb) AS reg,
               NULLIF(p_order -> 'fulfillmentStartInstructions' -> 0 -> 'shippingStep' -> 'shipTo', 'null'::jsonb) AS ship
    ), a AS (
        SELECT r.reg, r.ship, COALESCE(r.reg, r.ship, '{}'::jsonb) AS bill FROM r
    )
    SELECT jsonb_build_object(
        'username',   btrim(COALESCE(p_order -> 'buyer' ->> 'username', '')),
        'name',       btrim(COALESCE(a.bill ->> 'fullName', '')),
        'company',    btrim(COALESCE(a.bill ->> 'companyName', '')),
        'street',     btrim(concat_ws(' ', a.bill -> 'contactAddress' ->> 'addressLine1', a.bill -> 'contactAddress' ->> 'addressLine2')),
        'zipcode',    btrim(COALESCE(a.bill -> 'contactAddress' ->> 'postalCode', '')),
        'city',       btrim(COALESCE(a.bill -> 'contactAddress' ->> 'city', '')),
        'country',    btrim(COALESCE(a.bill -> 'contactAddress' ->> 'countryCode', '')),
        'email',      lower(btrim(COALESCE(NULLIF(a.bill ->> 'email', ''), a.ship ->> 'email', ''))),
        'phone',      btrim(COALESCE(NULLIF(a.bill -> 'primaryPhone' ->> 'phoneNumber', ''), a.ship -> 'primaryPhone' ->> 'phoneNumber', '')),
        'registered', a.reg IS NOT NULL,
        'ship', jsonb_build_object(
            'name',    btrim(COALESCE(a.ship ->> 'fullName', '')),
            'street',  btrim(concat_ws(' ', a.ship -> 'contactAddress' ->> 'addressLine1', a.ship -> 'contactAddress' ->> 'addressLine2')),
            'zipcode', btrim(COALESCE(a.ship -> 'contactAddress' ->> 'postalCode', '')),
            'city',    btrim(COALESCE(a.ship -> 'contactAddress' ->> 'city', '')),
            'country', btrim(COALESCE(a.ship -> 'contactAddress' ->> 'countryCode', ''))))
      FROM a
$$;

-- Wer ist der Käufer im Kundenstamm? Bewertet jeden denkbaren Kunden mit
-- Punkten (höchstens 100) und sagt, wie sicher das ist:
--
--   bekannter eBay-Käufer (frühere Bestellung)   60
--   gleiche E-Mail                               50
--   gleiche Telefonnummer                        40
--   Name gleich / ähnlich (≥ 0,75) / entfernt    40 / 25 / 10
--   Straße gleich / ähnlich (≥ 0,8)              30 / 15
--   PLZ gleich                                   15
--
--   sure    bester ≥ 85 und kein zweiter in Reichweite (< 60 oder 25 dahinter)
--   new     keiner ≥ 40 — neuer Kunde, ebenso eindeutig
--   review  alles dazwischen; dazu ein sicherer Treffer, dessen
--           Registrierungsadresse nicht mehr zum Stamm passt (umgezogen?)
--
-- Lieferanschriften zählen mit (Straße, PLZ), lösen aber kein „umgezogen“
-- aus: Lieferungen an Werkstatt oder Nachbarn sind normal.
CREATE OR REPLACE FUNCTION ebay_customer_match(p_order jsonb) RETURNS jsonb
    LANGUAGE sql STABLE AS $$
    WITH k AS (
        SELECT b AS kaeufer,
               b ->> 'username' AS username,
               ebay_norm_name(b ->> 'name') AS name_n,
               ebay_norm_name(b ->> 'company') AS firma_n,
               ebay_norm_street(b ->> 'street') AS strasse_n,
               ebay_norm_street(b -> 'ship' ->> 'street') AS liefer_strasse_n,
               lower(b ->> 'zipcode') AS plz,
               lower(b -> 'ship' ->> 'zipcode') AS liefer_plz,
               NULLIF(b ->> 'email', '') AS email,
               ebay_norm_phone(b ->> 'phone') AS telefon,
               (b ->> 'registered')::boolean AS registriert
          FROM (SELECT ebay_buyer(p_order) AS b) x
    ), bekannt AS (
        SELECT e.customer_id, count(*) AS bestellungen
          FROM ebay_orders e, k
         WHERE k.username <> '' AND e.buyer_username = k.username AND e.customer_id IS NOT NULL
         GROUP BY e.customer_id
    ), kandidat AS (
        SELECT c.*, x.emails AS weitere_emails, x.phone_numbers AS weitere_telefone, bk.bestellungen
          FROM customer c
          CROSS JOIN k
          LEFT JOIN customer_ext x ON x.customer_id = c.id
          LEFT JOIN bekannt bk ON bk.customer_id = c.id
         WHERE NOT COALESCE(c.obsolete, false)
           AND (bk.customer_id IS NOT NULL
                OR (k.email IS NOT NULL AND lower(c.email) = k.email)
                OR lower(btrim(c.zipcode)) IN (k.plz, k.liefer_plz)
                OR (k.name_n <> '' AND similarity(ebay_norm_name(c.name), k.name_n) >= 0.5)
                OR (k.telefon <> '' AND k.telefon IN (ebay_norm_phone(c.phone), ebay_norm_phone(c.fax))))
    ), treffer AS (
        SELECT c.id, c.customernumber, c.name, c.contact, c.street, c.zipcode, c.city, c.country, c.email, c.phone,
               COALESCE(c.bestellungen, 0) AS ebay_bestellungen,
               c.bestellungen IS NOT NULL AS bekannt,
               k.email IS NOT NULL AND (lower(c.email) = k.email
                   OR EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(c.weitere_emails) = 'array' THEN c.weitere_emails ELSE '[]'::jsonb END) m
                               WHERE lower(btrim(m ->> 'email')) = k.email)) AS email_gleich,
               k.telefon <> '' AND (k.telefon IN (ebay_norm_phone(c.phone), ebay_norm_phone(c.fax))
                   OR EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(c.weitere_telefone) = 'array' THEN c.weitere_telefone ELSE '[]'::jsonb END) t
                               WHERE ebay_norm_phone(t ->> 'number') = k.telefon)) AS telefon_gleich,
               GREATEST(CASE WHEN k.name_n <> '' THEN similarity(ebay_norm_name(c.name), k.name_n) ELSE 0 END,
                        CASE WHEN k.name_n <> '' THEN similarity(ebay_norm_name(c.contact), k.name_n) ELSE 0 END,
                        CASE WHEN k.firma_n <> '' THEN similarity(ebay_norm_name(c.name), k.firma_n) ELSE 0 END) AS name_aehnlich,
               (k.name_n <> '' AND ebay_norm_name(c.name) IN (k.name_n, k.firma_n))
                   OR (k.name_n <> '' AND ebay_norm_name(c.contact) = k.name_n) AS name_gleich,
               lower(btrim(c.zipcode)) = k.plz AS plz_rechnung,
               lower(btrim(c.zipcode)) IN (k.plz, k.liefer_plz) AS plz_gleich,
               ebay_norm_street(c.street) = k.strasse_n AND k.strasse_n <> '' AS strasse_rechnung,
               ebay_norm_street(c.street) IN (k.strasse_n, k.liefer_strasse_n) AND ebay_norm_street(c.street) <> '' AS strasse_gleich,
               -- ähnlich nur bei gleicher Hausnummer: Nachbarn sind keine Kandidaten
               GREATEST(CASE WHEN regexp_replace(c.street, '\D', '', 'g') = regexp_replace(k.kaeufer ->> 'street', '\D', '', 'g')
                             THEN similarity(ebay_norm_street(c.street), k.strasse_n) ELSE 0 END,
                        CASE WHEN regexp_replace(c.street, '\D', '', 'g') = regexp_replace(k.kaeufer -> 'ship' ->> 'street', '\D', '', 'g')
                             THEN similarity(ebay_norm_street(c.street), k.liefer_strasse_n) ELSE 0 END) AS strasse_aehnlich,
               (SELECT count(*) FROM ar WHERE ar.customer_id = c.id) AS rechnungen,
               k.registriert
          FROM kandidat c CROSS JOIN k
    ), bewertet AS (
        SELECT t.*,
               LEAST(100,
                     CASE WHEN t.bekannt THEN 60 ELSE 0 END
                   + CASE WHEN t.email_gleich THEN 50 ELSE 0 END
                   + CASE WHEN t.telefon_gleich THEN 40 ELSE 0 END
                   + CASE WHEN t.name_gleich THEN 40 WHEN t.name_aehnlich >= 0.75 THEN 25 WHEN t.name_aehnlich >= 0.5 THEN 10 ELSE 0 END
                   + CASE WHEN t.strasse_gleich THEN 30 WHEN t.strasse_aehnlich >= 0.8 THEN 15 ELSE 0 END
                   + CASE WHEN t.plz_gleich THEN 15 ELSE 0 END) AS punkte,
               t.registriert AND NOT (t.plz_rechnung AND t.strasse_rechnung) AS anschrift_anders
          FROM treffer t
    ), rang AS (
        SELECT b.*, row_number() OVER (ORDER BY b.punkte DESC, b.bekannt DESC, b.rechnungen DESC, b.id) AS nr
          FROM bewertet b
         WHERE b.punkte >= 40
    ), spitze AS (
        SELECT e.id AS erster_id, e.punkte AS erster_punkte, COALESCE(e.anschrift_anders, false) AS erster_anders,
               (SELECT punkte FROM rang WHERE nr = 2) AS zweiter
          FROM (SELECT 1) x LEFT JOIN rang e ON e.nr = 1
    ), urteil AS (
        SELECT s.*,
               CASE WHEN s.erster_id IS NULL OR s.erster_punkte < 40 THEN 'new'
                    WHEN s.erster_punkte >= 85 AND (s.zweiter IS NULL OR s.zweiter < 60 OR s.erster_punkte - s.zweiter >= 25)
                         THEN CASE WHEN s.erster_anders THEN 'review' ELSE 'sure' END
                    ELSE 'review' END AS stufe,
               CASE WHEN s.erster_id IS NULL OR s.erster_punkte < 40 THEN NULL
                    WHEN s.erster_punkte >= 85 AND s.zweiter >= 60 AND s.erster_punkte - s.zweiter < 25 THEN 'several'
                    WHEN s.erster_punkte >= 85 AND s.erster_anders THEN 'address_changed'
                    WHEN s.erster_punkte >= 85 THEN NULL
                    ELSE 'uncertain' END AS grund
          FROM spitze s
    )
    SELECT jsonb_build_object(
               'state', u.stufe,
               'reason', u.grund,
               'customer_id', CASE WHEN u.stufe <> 'new' THEN u.erster_id END,
               'buyer', (SELECT kaeufer FROM k),
               'candidates', COALESCE((SELECT jsonb_agg(jsonb_build_object(
                       'customer_id', r.id, 'customernumber', r.customernumber, 'name', r.name, 'contact', r.contact,
                       'street', r.street, 'zipcode', r.zipcode, 'city', r.city, 'country', r.country,
                       'email', r.email, 'phone', r.phone, 'score', r.punkte,
                       'invoices', r.rechnungen, 'ebay_orders', r.ebay_bestellungen,
                       'address_changed', r.anschrift_anders,
                       'hits', jsonb_build_object(
                           'known', r.bekannt, 'email', r.email_gleich, 'phone', r.telefon_gleich,
                           'name', CASE WHEN r.name_gleich THEN 'same' WHEN r.name_aehnlich >= 0.75 THEN 'similar'
                                        WHEN r.name_aehnlich >= 0.5 THEN 'weak' END,
                           'street', CASE WHEN r.strasse_gleich THEN 'same' WHEN r.strasse_aehnlich >= 0.8 THEN 'similar' END,
                           'zipcode', r.plz_gleich))
                   ORDER BY r.nr) FROM rang r WHERE r.nr <= 5), '[]'::jsonb))
      FROM urteil u
$$;

-- ── Positionen ──
-- Artikel jeder Position einer Bestellung, eine Regel für Prüfung und
-- Rechnung: SKU = Artikelnummer, sonst das zugeordnete Angebot (ItemID,
-- Variante), sonst der Sammelartikel des Kanals. Stück = Menge × Los: bei
-- übernommenen Angeboten das Los des Angebots, bei eingestellten die
-- Mindestabnahme des Artikels (W10).
CREATE OR REPLACE FUNCTION ebay_order_items(p_channel_id integer, p_order jsonb) RETURNS jsonb
    LANGUAGE sql STABLE AS $$
    WITH kanal AS (
        SELECT NULLIF(btrim(c.settings ->> 'default_parts_id'), '')::integer AS sammel
          FROM sales_channel_shop c WHERE c.id = p_channel_id
    ), pos AS (
        SELECT l.ord, l.e,
               btrim(COALESCE(l.e ->> 'sku', '')) AS sku,
               btrim(COALESCE(l.e ->> 'legacyItemId', '')) AS item_id,
               btrim(COALESCE(l.e ->> 'legacyVariationId', '')) AS variante
          FROM jsonb_array_elements(COALESCE(p_order -> 'lineItems', '[]'::jsonb)) WITH ORDINALITY l(e, ord)
    ), zuordnung AS (
        SELECT p.*,
               (SELECT x.id FROM parts x WHERE p.sku <> '' AND x.partnumber = p.sku AND NOT COALESCE(x.obsolete, false) LIMIT 1) AS per_sku,
               (SELECT li FROM ebay_listing_shop li
                 WHERE li.channel_id = p_channel_id AND li.item_id = p.item_id AND li.parts_id IS NOT NULL
                 ORDER BY (li.variation_sku = p.sku) DESC, li.variation_sku = '' DESC LIMIT 1) AS angebot
          FROM pos p
    ), ergebnis AS (
        SELECT z.*,
               COALESCE(z.per_sku, (z.angebot).parts_id, (SELECT sammel FROM kanal)) AS parts_id,
               CASE WHEN z.per_sku IS NOT NULL THEN 'sku'
                    WHEN (z.angebot).parts_id IS NOT NULL THEN 'listing'
                    WHEN (SELECT sammel FROM kanal) IS NOT NULL THEN 'collective' END AS quelle,
               CASE WHEN z.per_sku IS NULL AND (z.angebot).parts_id IS NOT NULL THEN GREATEST((z.angebot).lot_size, 1)
                    WHEN z.per_sku IS NOT NULL THEN COALESCE((SELECT CASE WHEN ps.min_qty > 1 THEN CEIL(ps.min_qty)::int END
                                                                 FROM parts_shipping_shop ps WHERE ps.parts_id = z.per_sku), 1)
                    ELSE 1 END AS los
          FROM zuordnung z
    )
    SELECT COALESCE(jsonb_agg(jsonb_build_object(
               'line_item_id', e.e ->> 'lineItemId',
               'title',        e.e ->> 'title',
               'sku',          e.sku,
               'item_id',      e.item_id,
               'quantity',     GREATEST(COALESCE((e.e ->> 'quantity')::int, 1), 1),
               'pieces',       GREATEST(COALESCE((e.e ->> 'quantity')::int, 1), 1) * e.los,
               'total',        COALESCE((e.e -> 'lineItemCost' ->> 'value')::numeric, 0),
               'parts_id',     e.parts_id,
               'source',       e.quelle,
               'partnumber',   p.partnumber,
               'description',  p.description,
               'unit',         p.unit,
               'onhand',       p.onhand,
               'image_url',    (SELECT li.image_url FROM ebay_listing_shop li
                                 WHERE li.channel_id = p_channel_id AND li.item_id = e.item_id LIMIT 1))
               ORDER BY e.ord), '[]'::jsonb)
      FROM ergebnis e
      LEFT JOIN parts p ON p.id = e.parts_id
$$;

-- ── Bestellung einordnen ──
-- Schreibt eine Bestellung von eBay fest (neu oder geändert) und sagt, ob
-- jetzt abzurechnen ist. Eine Regel für Abruf und Prüfung:
--
--   abgerechnet       bleibt abgerechnet; Zahlung und Storno werden
--                     nachgetragen (das Board zeigt „Gutschrift nötig“)
--   vor import_since  history — alte Verkäufe, schon anders abgerechnet
--   ignoriert         bleibt ignoriert
--   storniert/erstattet  cancelled
--   nicht bezahlt     waiting_payment — kommt wieder, sobald bezahlt
--   geklärt           (decided_at gesetzt, Fehler beim letzten Versuch) → abrechnen
--   sonst             ebay_customer_match(): sure/new → abrechnen, wenn
--                     auto_invoice; sonst und bei review → review
--
-- action = invoice: der Aufrufer legt die Rechnung an (customer_id leer =
-- neuer Kunde) — Rechnungen schreibt PHP (createFakturaCore).
CREATE OR REPLACE FUNCTION ebay_order_upsert(p_channel_id integer, p_order jsonb) RETURNS jsonb
    LANGUAGE plpgsql AS $$
DECLARE
    v_kanal     sales_channel_shop;
    v_zeile     ebay_orders;
    v_bezahlt   text := upper(COALESCE(p_order ->> 'orderPaymentStatus', ''));
    v_storno    text := upper(COALESCE(p_order -> 'cancelStatus' ->> 'cancelState', ''));
    v_bestellt  timestamp := (p_order ->> 'creationDate')::timestamptz AT TIME ZONE 'UTC';
    v_ab        timestamp;
    v_auto      boolean;
    v_abgleich  jsonb;
    v_stufe     text;
    v_aktion    text := 'none';
    v_kunde     integer;
    v_neu       boolean;
BEGIN
    v_neu := NOT EXISTS (SELECT 1 FROM ebay_orders WHERE ebay_order_id = p_order ->> 'orderId');
    SELECT * INTO v_kanal FROM sales_channel_shop WHERE id = p_channel_id;
    v_ab := NULLIF(btrim(v_kanal.settings ->> 'import_since'), '')::timestamp;
    v_auto := COALESCE(lower(btrim(v_kanal.settings ->> 'auto_invoice')), '1') IN ('1', 't', 'true', 'on');

    INSERT INTO ebay_orders (ebay_order_id, buyer_username, order_status, total, raw, channel_id, state,
                             payment_status, cancel_state, ordered_at, modified_at, posting_reason)
    VALUES (p_order ->> 'orderId', btrim(COALESCE(p_order -> 'buyer' ->> 'username', '')),
            p_order ->> 'orderFulfillmentStatus',
            COALESCE((p_order -> 'pricingSummary' -> 'total' ->> 'value')::numeric, 0),
            p_order, p_channel_id, 'review', v_bezahlt, v_storno, v_bestellt,
            (p_order ->> 'lastModifiedDate')::timestamptz AT TIME ZONE 'UTC', NULL)
    ON CONFLICT (ebay_order_id) DO UPDATE
       SET order_status   = EXCLUDED.order_status,
           total          = CASE WHEN ebay_orders.ar_id IS NULL THEN EXCLUDED.total ELSE ebay_orders.total END,
           raw            = EXCLUDED.raw,
           payment_status = EXCLUDED.payment_status,
           cancel_state   = EXCLUDED.cancel_state,
           ordered_at     = EXCLUDED.ordered_at,
           modified_at    = EXCLUDED.modified_at,
           channel_id     = COALESCE(ebay_orders.channel_id, EXCLUDED.channel_id),
           mtime          = now()
    RETURNING * INTO v_zeile;

    IF v_zeile.ar_id IS NOT NULL THEN
        v_stufe := 'invoiced';
    ELSIF v_zeile.state = 'ignored' THEN
        v_stufe := 'ignored';
    ELSIF v_ab IS NOT NULL AND v_bestellt < v_ab THEN
        v_stufe := 'history';
    ELSIF v_storno IN ('CANCELED', 'CANCELLED') OR v_bezahlt = 'FULLY_REFUNDED' THEN
        v_stufe := 'cancelled';
    ELSIF v_bezahlt NOT IN ('PAID', 'PARTIALLY_REFUNDED') THEN
        v_stufe := 'waiting_payment';
    ELSIF v_zeile.decided_at IS NOT NULL THEN
        -- schon geklärt, letzter Versuch scheiterte: noch einmal
        v_stufe := 'review';
        v_aktion := 'invoice';
        v_kunde := v_zeile.customer_id;
    ELSE
        v_abgleich := ebay_customer_match(p_order);
        v_stufe := 'review';
        IF v_auto AND v_abgleich ->> 'state' IN ('sure', 'new') THEN
            v_aktion := 'invoice';
            v_kunde := (v_abgleich ->> 'customer_id')::integer;
        END IF;
    END IF;

    UPDATE ebay_orders
       SET state       = v_stufe,
           match       = COALESCE(v_abgleich, match),
           customer_id = CASE WHEN ar_id IS NULL AND decided_at IS NULL THEN v_kunde ELSE customer_id END,
           decided_by  = CASE WHEN v_aktion = 'invoice' AND decided_at IS NULL THEN 'auto' ELSE decided_by END
     WHERE id = v_zeile.id;

    RETURN jsonb_build_object('id', v_zeile.id, 'state', v_stufe, 'action', v_aktion, 'customer_id', v_kunde,
                              'new', v_neu);
END;
$$;

-- ── Angebote zuordnen ──
-- Ordnet ein Angebot einem Artikel zu: Kanalzeile an (legacy_item_id), der
-- Trigger stellt einen Auftrag ein, der Menge und Preis abgleicht (sofern
-- legacy_sync es erlaubt). Ein Artikel hat je Kanal nur ein Angebot — eine
-- frühere Zuordnung desselben Artikels in diesem Kanal wird gelöst.
CREATE OR REPLACE FUNCTION ebay_listing_link(p_listing_id integer, p_parts_id integer, p_source text) RETURNS jsonb
    LANGUAGE plpgsql AS $$
DECLARE
    v_angebot ebay_listing_shop;
    v_angebot_von_oserp text;
BEGIN
    SELECT * INTO v_angebot FROM ebay_listing_shop WHERE id = p_listing_id;
    IF v_angebot.id IS NULL THEN
        RETURN jsonb_build_object('linked', false, 'reason', 'LISTING_NOT_FOUND');
    END IF;

    -- Von OSERP eingestellt (Inventory-API): nichts umhängen, das Angebot gehört dem Artikel
    SELECT pc.external_id INTO v_angebot_von_oserp
      FROM parts_channel_shop pc
     WHERE pc.parts_id = p_parts_id AND pc.channel_id = v_angebot.channel_id
       AND COALESCE(pc.sync_data ->> 'offer_id', '') <> '' AND COALESCE(pc.sync_status, '') <> 'ended';
    IF v_angebot_von_oserp IS NOT NULL AND v_angebot_von_oserp <> v_angebot.item_id THEN
        RETURN jsonb_build_object('linked', false, 'reason', 'EBAY_PART_HAS_OFFER', 'item_id', v_angebot_von_oserp);
    END IF;

    UPDATE ebay_listing_shop
       SET parts_id = NULL, link_source = NULL, mtime = now()
     WHERE channel_id = v_angebot.channel_id AND parts_id = p_parts_id AND id <> p_listing_id;

    UPDATE ebay_listing_shop
       SET parts_id = p_parts_id, link_source = p_source,
           suggestion_parts_id = NULL, suggestion_score = NULL, suggestion_source = NULL, mtime = now()
     WHERE id = p_listing_id;

    IF v_angebot_von_oserp IS NULL THEN
        INSERT INTO parts_channel_shop (parts_id, channel_id, active, external_id, sync_status, sync_mtime, sync_data)
        VALUES (p_parts_id, v_angebot.channel_id, true, v_angebot.item_id, 'active', now(),
                jsonb_build_object('legacy_item_id', v_angebot.item_id, 'legacy_variation_sku', v_angebot.variation_sku))
        ON CONFLICT (parts_id, channel_id) DO UPDATE
           SET active = true,
               external_id = EXCLUDED.external_id,
               sync_status = 'active',
               sync_error = NULL,
               sync_mtime = now(),
               sync_data = (COALESCE(parts_channel_shop.sync_data, '{}'::jsonb) - 'offer_id') || EXCLUDED.sync_data;
    END IF;
    RETURN jsonb_build_object('linked', true);
END;
$$;

-- Zuordnung lösen: die Kanalzeile verliert das Angebot und wird abgewählt —
-- bei eBay ändert sich nichts (shopEbayEndPartNow lässt übernommene
-- Angebote stehen)
CREATE OR REPLACE FUNCTION ebay_listing_unlink(p_listing_id integer, p_dismiss boolean) RETURNS void
    LANGUAGE sql AS $$
    WITH angebot AS (
        SELECT * FROM ebay_listing_shop WHERE id = p_listing_id
    ), kanalzeile AS (
        UPDATE parts_channel_shop pc
           SET sync_data = COALESCE(pc.sync_data, '{}'::jsonb) - 'legacy_item_id' - 'legacy_variation_sku',
               sync_status = 'ended', external_id = NULL, active = false
          FROM angebot a
         WHERE pc.parts_id = a.parts_id AND pc.channel_id = a.channel_id
           AND pc.sync_data ->> 'legacy_item_id' = a.item_id
    )
    UPDATE ebay_listing_shop l
       SET dismissed_parts_id = CASE WHEN p_dismiss THEN COALESCE(l.parts_id, l.suggestion_parts_id) ELSE l.dismissed_parts_id END,
           parts_id = NULL, link_source = NULL,
           suggestion_parts_id = NULL, suggestion_score = NULL, suggestion_source = NULL, mtime = now()
      FROM angebot a
     WHERE l.id = a.id
$$;

-- Ordnet alle offenen Angebote eines Kanals zu, so gut es geht:
--   inventory  von OSERP eingestellt (external_id der Kanalzeile)
--   sku        SKU = Artikelnummer
--   ean        EAN gleich (nur Ziffern verglichen)
--   mpn        Herstellernummer = Artikelnummer ohne Trennzeichen
-- eindeutig → zugeordnet; Titel ähnlich (≥ 0,45) → Vorschlag. Ein Artikel,
-- der schon ein Angebot hat, wird nicht automatisch ein zweites Mal vergeben.
CREATE OR REPLACE FUNCTION ebay_listing_autolink(p_channel_id integer) RETURNS jsonb
    LANGUAGE plpgsql AS $$
DECLARE
    z         record;
    v_linked  integer := 0;
    v_inv     integer := 0;
BEGIN
    -- Von OSERP eingestellte Angebote: kein Zuordnen nötig, nur kennzeichnen
    WITH eigen AS (
        UPDATE ebay_listing_shop l
           SET parts_id = pc.parts_id, link_source = 'inventory', suggestion_parts_id = NULL, mtime = now()
          FROM parts_channel_shop pc
         WHERE l.channel_id = p_channel_id AND l.parts_id IS NULL
           AND pc.channel_id = p_channel_id AND pc.external_id = l.item_id
           AND COALESCE(pc.sync_data ->> 'offer_id', '') <> ''
        RETURNING 1
    ) SELECT count(*) INTO v_inv FROM eigen;

    FOR z IN
        WITH offen AS (
            SELECT l.* FROM ebay_listing_shop l
             WHERE l.channel_id = p_channel_id AND l.status = 'active' AND l.parts_id IS NULL
        ), kandidat AS (
            SELECT o.id AS listing_id, p.id AS parts_id, 'sku' AS quelle, 1 AS rang
              FROM offen o JOIN parts p ON p.partnumber = btrim(COALESCE(NULLIF(o.variation_sku, ''), o.sku))
             WHERE btrim(COALESCE(NULLIF(o.variation_sku, ''), o.sku, '')) <> '' AND NOT COALESCE(p.obsolete, false)
            UNION
            SELECT o.id, p.id, 'ean', 2
              FROM offen o JOIN parts p ON regexp_replace(p.ean, '\D', '', 'g') = regexp_replace(o.ean, '\D', '', 'g')
             WHERE length(regexp_replace(COALESCE(o.ean, ''), '\D', '', 'g')) >= 8 AND NOT COALESCE(p.obsolete, false)
            UNION
            SELECT o.id, p.id, 'mpn', 3
              FROM offen o JOIN parts p ON lower(regexp_replace(p.partnumber, '[^A-Za-z0-9]', '', 'g'))
                                         = lower(regexp_replace(o.mpn, '[^A-Za-z0-9]', '', 'g'))
             WHERE length(regexp_replace(COALESCE(o.mpn, ''), '[^A-Za-z0-9]', '', 'g')) >= 4 AND NOT COALESCE(p.obsolete, false)
        ), bester AS (
            SELECT DISTINCT ON (k.listing_id) k.*,
                   count(*) OVER (PARTITION BY k.listing_id, k.rang) AS mehrdeutig
              FROM kandidat k
             ORDER BY k.listing_id, k.rang
        )
        SELECT b.* FROM bester b
         WHERE b.mehrdeutig = 1
           AND NOT EXISTS (SELECT 1 FROM ebay_listing_shop x
                            WHERE x.channel_id = p_channel_id AND x.parts_id = b.parts_id)
           AND b.parts_id IS DISTINCT FROM (SELECT dismissed_parts_id FROM ebay_listing_shop WHERE id = b.listing_id)
    LOOP
        -- Zwei Angebote mit demselben Artikel: nur das erste bekommt ihn
        CONTINUE WHEN EXISTS (SELECT 1 FROM ebay_listing_shop x WHERE x.channel_id = p_channel_id AND x.parts_id = z.parts_id);
        PERFORM ebay_listing_link(z.listing_id, z.parts_id, z.quelle);
        v_linked := v_linked + 1;
    END LOOP;

    -- Vorschläge über den Titel für den Rest
    UPDATE ebay_listing_shop l
       SET suggestion_parts_id = v.parts_id, suggestion_score = v.score, suggestion_source = 'title'
      FROM (
          SELECT l2.id,
                 (SELECT p.id FROM parts p
                   WHERE p.description % l2.title AND NOT COALESCE(p.obsolete, false) AND p.part_type = 'part'
                     AND p.id IS DISTINCT FROM l2.dismissed_parts_id
                   ORDER BY similarity(p.description, l2.title) DESC LIMIT 1) AS parts_id,
                 (SELECT ROUND(max(similarity(p.description, l2.title))::numeric * 100, 1) FROM parts p
                   WHERE p.description % l2.title AND NOT COALESCE(p.obsolete, false) AND p.part_type = 'part'
                     AND p.id IS DISTINCT FROM l2.dismissed_parts_id) AS score
            FROM ebay_listing_shop l2
           WHERE l2.channel_id = p_channel_id AND l2.status = 'active' AND l2.parts_id IS NULL
      ) v
     WHERE l.id = v.id;
    UPDATE ebay_listing_shop SET suggestion_parts_id = NULL, suggestion_score = NULL, suggestion_source = NULL
     WHERE channel_id = p_channel_id AND parts_id IS NULL AND suggestion_score < 45;

    RETURN jsonb_build_object('linked', v_linked, 'inventory', v_inv,
                              'suggested', (SELECT count(*) FROM ebay_listing_shop
                                             WHERE channel_id = p_channel_id AND parts_id IS NULL
                                               AND suggestion_parts_id IS NOT NULL AND status = 'active'));
END;
$$;

-- ── Ansicht ──
-- Angebote eines Kanals mit dem, was OSERP dazu weiß: Lager, Kanalpreis,
-- Abweichungen, letzter Fehler des Abgleichs
CREATE OR REPLACE FUNCTION ebay_listings(p_channel_id integer) RETURNS jsonb
    LANGUAGE sql STABLE AS $$
    SELECT COALESCE(jsonb_agg(z ORDER BY z.status, (z.parts_id IS NULL) DESC, z.title), '[]'::jsonb)
      FROM (
        SELECT l.id, l.item_id, l.variation_sku, l.sku, l.title, l.variation_text, l.price, l.quantity,
               l.quantity_sold, l.lot_size, l.ean, l.mpn, l.image_url, l.view_url, l.watch_count, l.status,
               l.condition_name, l.category_name, l.end_time, l.link_source,
               l.parts_id, p.partnumber, p.description, p.onhand, p.unit,
               CASE WHEN p.id IS NOT NULL THEN ebay_gross_price(p.id, p_channel_id) END AS oserp_price,
               CASE WHEN p.id IS NOT NULL THEN GREATEST(FLOOR(COALESCE(p.onhand, 0) / GREATEST(l.lot_size, 1)), 0)::int END AS oserp_quantity,
               l.suggestion_parts_id, l.suggestion_score, l.suggestion_source,
               s.partnumber AS suggestion_partnumber, s.description AS suggestion_description, s.onhand AS suggestion_onhand,
               pc.sync_error, pc.sync_mtime,
               -- mehr bei eBay als im Lager: Gefahr, zu verkaufen, was fehlt
               p.id IS NOT NULL AND l.status = 'active' AND COALESCE(l.quantity, 0) * GREATEST(l.lot_size, 1) > COALESCE(p.onhand, 0) AS oversell,
               p.id IS NOT NULL AND l.status = 'active'
                   AND COALESCE(l.quantity, 0) <> GREATEST(FLOOR(COALESCE(p.onhand, 0) / GREATEST(l.lot_size, 1)), 0) AS stock_diff,
               p.id IS NOT NULL AND l.status = 'active' AND l.link_source <> 'inventory'
                   AND abs(COALESCE(l.price, 0) - COALESCE(ebay_gross_price(p.id, p_channel_id), 0)) >= 0.01 AS price_diff
          FROM ebay_listing_shop l
          LEFT JOIN parts p ON p.id = l.parts_id
          LEFT JOIN parts s ON s.id = l.suggestion_parts_id
          LEFT JOIN parts_channel_shop pc ON pc.parts_id = l.parts_id AND pc.channel_id = l.channel_id
         WHERE l.channel_id = p_channel_id
           AND (l.status = 'active' OR l.mtime >= now() - interval '30 days' OR l.parts_id IS NOT NULL)
      ) z
$$;

-- Das Board des eBay-Kanals: Kanal, Kennzahlen, Prüfung, Verkäufe und die
-- von OSERP eingestellten Artikel — eine Abfrage für die Ansicht
CREATE OR REPLACE FUNCTION ebay_board(p_channel_id integer) RETURNS jsonb
    LANGUAGE sql STABLE AS $$
    WITH kanal AS (
        SELECT c.id, c.name, c.active, COALESCE(c.settings, '{}'::jsonb) AS s,
               EXISTS (SELECT 1 FROM sales_channel_secret_shop x
                        WHERE x.channel_id = c.id AND x.key = 'refresh_token' AND btrim(x.value) <> '') AS verbunden
          FROM sales_channel_shop c WHERE c.id = p_channel_id
    ), bestellung AS (
        SELECT e.*, a.invnumber, a.transdate, cu.name AS customer_name, cu.customernumber,
               ebay_buyer(e.raw) AS kaeufer,
               (SELECT d.shipment_no FROM dhl_shipments d
                 WHERE d.record_type = 'invoice' AND d.record_id = e.ar_id ORDER BY d.id DESC LIMIT 1) AS dhl_nummer
          FROM ebay_orders e
          LEFT JOIN ar a ON a.id = e.ar_id
          LEFT JOIN customer cu ON cu.id = e.customer_id
         WHERE e.channel_id = p_channel_id
    ), karte AS (
        SELECT b.*,
               b.ar_id IS NOT NULL AND (b.cancel_state IN ('CANCELED', 'CANCELLED') OR b.payment_status = 'FULLY_REFUNDED') AS gutschrift,
               b.ar_id IS NOT NULL AND b.shipped_at IS NULL AND COALESCE(b.order_status, '') <> 'FULFILLED'
                   AND COALESCE(b.cancel_state, '') NOT IN ('CANCELED', 'CANCELLED')
                   AND COALESCE(b.payment_status, '') <> 'FULLY_REFUNDED' AS zu_versenden
          FROM bestellung b
    )
    SELECT jsonb_build_object(
        'channel', (SELECT jsonb_build_object(
                        'channel_id', k.id, 'name', k.name, 'active', k.active, 'connected', k.verbunden,
                        'environment', COALESCE(k.s ->> 'environment', 'production'),
                        'account', k.s ->> 'account_id',
                        'last_check', k.s ->> 'order_last_check',
                        'listings_last_sync', k.s ->> 'listings_last_sync',
                        'import_since', k.s ->> 'import_since',
                        'auto_invoice', COALESCE(lower(btrim(k.s ->> 'auto_invoice')), '1') IN ('1', 't', 'true', 'on'),
                        'legacy_sync', COALESCE(NULLIF(k.s ->> 'legacy_sync', ''), 'off'),
                        'out_of_stock_control', k.s ->> 'out_of_stock_control',
                        'collective_part', NULLIF(btrim(k.s ->> 'default_parts_id'), '') IS NOT NULL)
                      FROM kanal k),
        'counts', jsonb_build_object(
            'review',          (SELECT count(*) FROM karte WHERE state = 'review'),
            'errors',          (SELECT count(*) FROM karte WHERE state = 'review' AND error IS NOT NULL),
            'waiting_payment', (SELECT count(*) FROM karte WHERE state = 'waiting_payment'),
            'to_ship',         (SELECT count(*) FROM karte WHERE zu_versenden),
            'credit_needed',   (SELECT count(*) FROM karte WHERE gutschrift),
            'sold_30d',        (SELECT count(*) FROM karte WHERE ar_id IS NOT NULL AND ordered_at >= now() - interval '30 days'),
            'revenue_30d',     (SELECT COALESCE(sum(total), 0) FROM karte WHERE ar_id IS NOT NULL AND ordered_at >= now() - interval '30 days'),
            'history',         (SELECT count(*) FROM karte WHERE state = 'history'),
            'listings',        (SELECT count(*) FROM ebay_listing_shop WHERE channel_id = p_channel_id AND status = 'active'),
            'linked',          (SELECT count(*) FROM ebay_listing_shop WHERE channel_id = p_channel_id AND status = 'active' AND parts_id IS NOT NULL),
            'unlinked',        (SELECT count(*) FROM ebay_listing_shop WHERE channel_id = p_channel_id AND status = 'active' AND parts_id IS NULL),
            'suggested',       (SELECT count(*) FROM ebay_listing_shop WHERE channel_id = p_channel_id AND status = 'active'
                                                                         AND parts_id IS NULL AND suggestion_parts_id IS NOT NULL),
            'oversell',        (SELECT count(*) FROM ebay_listing_shop l JOIN parts p ON p.id = l.parts_id
                                 WHERE l.channel_id = p_channel_id AND l.status = 'active'
                                   AND COALESCE(l.quantity, 0) * GREATEST(l.lot_size, 1) > COALESCE(p.onhand, 0)),
            'listing_value',   (SELECT COALESCE(sum(price * quantity), 0) FROM ebay_listing_shop WHERE channel_id = p_channel_id AND status = 'active'),
            'offered',         (SELECT count(*) FROM parts_channel_shop WHERE channel_id = p_channel_id AND active),
            'sync_errors',     (SELECT count(*) FROM parts_channel_shop WHERE channel_id = p_channel_id AND active AND sync_status = 'error')),
        'review', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
                        'id', r.id, 'ebay_order_id', r.ebay_order_id, 'ordered_at', r.ordered_at, 'total', r.total,
                        'buyer', r.kaeufer, 'match', r.match, 'error', r.error,
                        'customer_id', r.customer_id, 'decided_at', r.decided_at,
                        'items', ebay_order_items(p_channel_id, r.raw))
                    ORDER BY r.ordered_at), '[]'::jsonb)
                     FROM karte r WHERE r.state = 'review'),
        'orders', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
                        'id', o.id, 'ebay_order_id', o.ebay_order_id, 'ordered_at', o.ordered_at, 'total', o.total,
                        'state', o.state, 'payment_status', o.payment_status, 'cancel_state', o.cancel_state,
                        'fulfillment', o.order_status,
                        'buyer_username', o.buyer_username, 'buyer_name', o.kaeufer ->> 'name',
                        'customer_id', o.customer_id, 'customer_name', o.customer_name, 'customernumber', o.customernumber,
                        'ar_id', o.ar_id, 'invnumber', o.invnumber, 'posting_reason', o.posting_reason,
                        'decided_by', o.decided_by, 'error', o.error,
                        'tracking_number', COALESCE(o.tracking_number, o.dhl_nummer), 'carrier', COALESCE(o.carrier, CASE WHEN o.dhl_nummer IS NOT NULL THEN 'DHL' END),
                        'shipped_at', o.shipped_at, 'ship_error', o.ship_error,
                        'to_ship', o.zu_versenden, 'credit_needed', o.gutschrift,
                        'items', (SELECT COALESCE(jsonb_agg(jsonb_build_object('title', li ->> 'title', 'quantity', li ->> 'quantity')), '[]'::jsonb)
                                    FROM jsonb_array_elements(COALESCE(o.raw -> 'lineItems', '[]'::jsonb)) li))
                    ORDER BY o.ordered_at DESC), '[]'::jsonb)
                     FROM (SELECT * FROM karte WHERE state <> 'history' ORDER BY ordered_at DESC NULLS LAST LIMIT 300) o),
        -- Von OSERP eingestellte und übernommene Artikel mit ihrem Abgleich
        'offered', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
                        'parts_id', p.id, 'partnumber', p.partnumber, 'description', COALESCE(NULLIF(pc.title, ''), p.description),
                        'onhand', p.onhand, 'unit', p.unit, 'price', ebay_gross_price(p.id, p_channel_id),
                        'status', pc.sync_status, 'error', pc.sync_error, 'mtime', pc.sync_mtime,
                        'listing_id', pc.external_id,
                        'legacy', COALESCE(pc.sync_data ->> 'legacy_item_id', '') <> '',
                        'has_page', COALESCE(pc.settings -> 'page', 'null'::jsonb) <> 'null'::jsonb,
                        'images', (SELECT count(*) FROM parts_channel_image_shop i WHERE i.parts_id = p.id AND i.channel_id = p_channel_id))
                    ORDER BY (pc.sync_status = 'error') DESC, p.description), '[]'::jsonb)
                     FROM parts_channel_shop pc JOIN parts p ON p.id = pc.parts_id
                    WHERE pc.channel_id = p_channel_id AND pc.active)
    )
$$;
