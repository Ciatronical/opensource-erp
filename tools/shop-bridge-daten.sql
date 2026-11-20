-- tools/shop-bridge-daten.sql
--
-- Übernimmt die Shop-Daten einer Kivitendo-Bridge-Datenbank in die Tabellen der
-- Erweiterung shop. Gegenstück zu tools/shop-bridge-settings.php, das die
-- Einstellungen übernimmt.
--
--   psql -d <mandant> -f tools/shop-bridge-daten.sql
--
-- Voraussetzungen, in dieser Reihenfolge:
--   1. Kivitendo-Schema auf dem Stand, den OpensourceERP verlangt (4.0.0)
--   2. Mandant in auth.clients eingetragen
--   3. Schema-Update gelaufen, Erweiterung shop aktiv
--      (extensions_oserp, php tools/oserp-upstall.php --client <id>)
--
-- Was übernommen wird:
--   parts_hugoshop     -> parts_ext (Artikelangaben), Kategorien ins Sortiment,
--                         Kanalzeile im ersten HugoShop
--   customer_hugoshop  -> customer_ext.portal_guest / portal_shipto_id
--   ar_link_hugoshop   -> invoice_link_oserp (macht das Schema-Update selbst,
--                         hier nur nachgezogen, falls Zeilen fehlen)
--   carts_hugoshop     -> alte Warenkörbe werden verworfen
--
-- Was umgeformt wird:
--   downloads       Die Bridge führt eine Liste von Dateinamen, die Erweiterung
--                   ein Objekt Anzeigename -> Dateiname. Der Anzeigename entsteht
--                   aus dem Dateinamen: ohne Endung, ohne führende Nummer,
--                   Unterstriche als Leerzeichen.
--   technical_data  Eine leere Liste wird zum leeren Objekt.
--   Kfz-Daten       Führt die Bridge-Tabelle kompatible Fahrzeuge, OE-Nummern,
--                   TecDoc-Eigenschaften und Ersatzteile (auto-spar.de), werden
--                   sie zu den Zusatzdaten des Artikels (hugoshop_extra, gleiche
--                   Schlüssel wie die Spalten der Bridge) und die OE-Nummern zu
--                   Suchwörtern (hugoshop_search_terms): jede Nummer, wie sie
--                   geschrieben ist, und ohne Leerzeichen.
--   EAN             Aus der Quelltabelle des Lieferanten nach parts.ean, wo sie
--                   fehlt (Schritt 10); ebenso die Reihenfolge der
--                   TecDoc-Eigenschaften in die Zusatzdaten.
--
-- Angeboten (Kanalzeile aktiv) werden alle Artikel der Bridge-Tabelle, wie im
-- Bridge-Shop. Dort setzt die Veröffentlichung ausverkaufte Artikel auf
-- ungültig (parts.obsolete) und lässt die Seite als „Momentan nicht verfügbar“
-- stehen; die Erweiterung zeigt sie ebenso (shop_part_available).
--
-- Ungültig-Kennzeichen, die ein abgebrochener Bridge-Lauf hinterlassen hat,
-- werden anhand der Quelltabellen der Lieferanten zurückgesetzt (Schritt 7).
--
-- Der Mandant wird auf „Nur Dropshipping“ gestellt, mit einer Lieferbedingung
-- samt Lieferzeit in Tagen als Vorgabe (Schritt 8).
--
-- Die Marketingtexte der Lieferanten-Quelltabellen (Zusammenfassung und
-- Beschreibung) werden zur Beschreibung des Artikels im HugoShop (Schritt 9).
--
-- Es entstehen keine Veröffentlichungsaufträge (shop.ohne_auftraege): die
-- Seiten werden nach der Einrichtung der Webseite bewusst in einem Lauf
-- veröffentlicht.
--
-- Wiederholbar: bereits übernommene Artikel und Kunden bleiben, wie sie sind.
-- Die Bridge-Tabellen parts_hugoshop und customer_hugoshop bleiben stehen; sie
-- werden nach der Prüfung von Hand entfernt.

\set ON_ERROR_STOP 1

BEGIN;

DO $$
BEGIN
    IF to_regclass('parts_hugoshop') IS NULL OR to_regclass('customer_hugoshop') IS NULL THEN
        RAISE EXCEPTION 'Keine Bridge-Datenbank: parts_hugoshop oder customer_hugoshop fehlt';
    END IF;
    IF to_regclass('parts_ext') IS NULL OR to_regclass('parts_channel_shop') IS NULL
       OR to_regclass('invoice_link_oserp') IS NULL THEN
        RAISE EXCEPTION 'Schema der Erweiterung shop fehlt — zuerst tools/oserp-upstall.php ausführen';
    END IF;
    IF shop_first_channel_id('hugoshop') IS NULL THEN
        RAISE EXCEPTION 'Kein HugoShop-Kanal vorhanden';
    END IF;
END $$;

-- Keine Aufträge aus den Triggern, gilt bis zum Ende der Transaktion
SELECT set_config('shop.ohne_auftraege', 'on', true);

-- ----------------------------------------------------------------------------
-- 1. Artikelangaben
-- ----------------------------------------------------------------------------
-- Die Bridge-Tabelle hat keinen eindeutigen Index: je Artikel gilt die jüngste
-- Zeile. Der Trigger auf parts_ext legt die Kanalzeilen der Kanäle mit
-- auto_add_parts an; Schritt 3 stellt sie richtig.

CREATE TEMPORARY TABLE uebernommen (parts_id integer PRIMARY KEY) ON COMMIT DROP;

WITH quelle AS (
    SELECT DISTINCT ON (ph.parts_id) ph.*
      FROM parts_hugoshop ph
     ORDER BY ph.parts_id, ph.id DESC
), neu AS (
    INSERT INTO parts_ext (parts_id, hugoshop_breadcrumbs, hugoshop_technical_data, hugoshop_properties,
                           hugoshop_downloads, hugoshop_images, hugoshop_hyperlink, hugoshop_category,
                           hugoshop_extra, hugoshop_search_terms)
    SELECT q.parts_id,
           q.breadcrumbs,
           CASE WHEN jsonb_typeof(q.technical_data) = 'array' AND jsonb_array_length(q.technical_data) = 0
                THEN '{}'::jsonb
                ELSE q.technical_data END,
           q.properties,
           CASE WHEN jsonb_typeof(q.downloads) = 'array' THEN (
                    SELECT COALESCE(jsonb_object_agg(
                               n.name || CASE WHEN n.nr > 1 THEN ' (' || n.nr || ')' ELSE '' END, n.datei), '{}'::jsonb)
                      FROM (SELECT m.datei, m.name, row_number() OVER (PARTITION BY m.name ORDER BY m.pos) AS nr
                              FROM (SELECT d.datei, d.pos,
                                           COALESCE(NULLIF(btrim(
                                               -- doppeltes erstes Wort („Datenblatt Datenblatt …“) einmal
                                               regexp_replace(
                                                   -- Unterstriche zu Leerzeichen
                                                   regexp_replace(
                                                       -- führende Nummer samt Unterstrich weg
                                                       regexp_replace(
                                                           -- Endung weg
                                                           regexp_replace(d.datei, '\.[A-Za-z0-9]+$', ''),
                                                           '^[0-9][0-9.\-]*_', ''),
                                                       '[_\s]+', ' ', 'g'),
                                                   '^(\S+) \1 ', '\1 ')), ''), d.datei) AS name
                                      FROM jsonb_array_elements_text(q.downloads) WITH ORDINALITY AS d(datei, pos)
                                     WHERE btrim(d.datei) <> '') m) n)
                ELSE q.downloads END,
           q.images,
           NULLIF(btrim(q.hyperlink), ''),
           NULLIF(btrim(q.category), ''),
           -- Kfz-Daten: über die Zeile als JSON gelesen, weil die Spalten nur
           -- in der Bridge-Tabelle eines Kfz-Shops stehen. Leere Angaben
           -- ([] und {}) fallen weg, ohne Angaben bleibt die Spalte leer.
           (SELECT NULLIF(COALESCE(jsonb_object_agg(k.schluessel, z.zeile -> k.schluessel), '{}'::jsonb), '{}'::jsonb)
              FROM unnest(ARRAY['applicable_cars', 'oe_codes', 'tecdoc_params',
                                'oe_replacement', 'eq_replacement']) AS k(schluessel)
             WHERE jsonb_typeof(z.zeile -> k.schluessel) IN ('array', 'object')
               AND z.zeile -> k.schluessel NOT IN ('[]'::jsonb, '{}'::jsonb)),
           CASE WHEN jsonb_typeof(z.zeile -> 'oe_codes') = 'array' THEN (
                    SELECT string_agg(DISTINCT w.wort, ' ')
                      FROM jsonb_array_elements(z.zeile -> 'oe_codes') AS h(eintrag)
                     CROSS JOIN LATERAL jsonb_array_elements_text(
                               CASE WHEN jsonb_typeof(h.eintrag -> 'codes') = 'array'
                                    THEN h.eintrag -> 'codes' ELSE '[]'::jsonb END) AS c(nummer)
                     CROSS JOIN LATERAL (VALUES (btrim(c.nummer)), (replace(c.nummer, ' ', ''))) AS w(wort)
                     WHERE w.wort <> '')
           END
      FROM quelle q
     CROSS JOIN LATERAL (SELECT to_jsonb(q) AS zeile) z
      ON CONFLICT (parts_id) DO NOTHING
    RETURNING parts_id
)
INSERT INTO uebernommen SELECT parts_id FROM neu;

-- ----------------------------------------------------------------------------
-- 2. Kategorien ins Sortiment
-- ----------------------------------------------------------------------------
-- Dieselbe Übernahme wie im Schema der Erweiterung (dev/shop-sortiment.md):
-- jede Freitext-Kategorie wird eine Kategorie ohne Gruppe.

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

-- ----------------------------------------------------------------------------
-- 3. Kanalzeilen im ersten HugoShop
-- ----------------------------------------------------------------------------
-- Nur für die in diesem Lauf übernommenen Artikel — was danach in der
-- Artikelkarte ein- oder abgewählt wurde, bleibt bei einer Wiederholung.

INSERT INTO parts_channel_shop (parts_id, channel_id, active)
SELECT u.parts_id, shop_first_channel_id('hugoshop'), true
  FROM uebernommen u
    ON CONFLICT (parts_id, channel_id) DO UPDATE SET active = EXCLUDED.active;

-- ----------------------------------------------------------------------------
-- 4. Kunden
-- ----------------------------------------------------------------------------
-- Die Kennwörter stehen unverändert in customer.user_password (bcrypt), das
-- Kundenportal prüft sie mit password_verify.

INSERT INTO customer_ext (customer_id, portal_guest, portal_shipto_id)
SELECT ch.customer_id, ch.guest, ch.shipto_id
  FROM customer_hugoshop ch
    ON CONFLICT (customer_id) DO UPDATE
       SET portal_guest     = EXCLUDED.portal_guest,
           portal_shipto_id = EXCLUDED.portal_shipto_id
     WHERE NOT customer_ext.portal_guest
       AND customer_ext.portal_shipto_id IS NULL;

-- ----------------------------------------------------------------------------
-- 5. Rechnungslinks
-- ----------------------------------------------------------------------------
-- Der Link (uuid) liegt im Kern, ar_link_hugoshop behält die Zahlungsangaben.
-- Die alte Spalte paypal (Payer-Id) bleibt gültig: ohne payment_status zählt
-- eine Zeile mit Payer-Id als bezahlt.

INSERT INTO invoice_link_oserp (uuid, ar_id, active)
SELECT l.uuid, l.ar_id, l.active
  FROM ar_link_hugoshop l
 WHERE l.ar_id IS NOT NULL
    ON CONFLICT DO NOTHING;

-- ----------------------------------------------------------------------------
-- 6. Alte Warenkörbe
-- ----------------------------------------------------------------------------
-- Warenkörbe der Bridge hängen an keiner Portalsitzung; solche des neuen
-- Shops bleiben. Die Positionen gehen über den Fremdschlüssel mit.

DELETE FROM carts_hugoshop c
 WHERE NOT EXISTS (SELECT 1 FROM portal_context_oserp pc WHERE pc.cart_uuid = c.uuid);

-- ----------------------------------------------------------------------------
-- 7. Ungültig-Kennzeichen aus einem abgebrochenen Bridge-Lauf
-- ----------------------------------------------------------------------------
-- Der Vorlauf der Bridge setzt alle Artikel auf ungültig (parts.obsolete), die
-- Veröffentlichung setzt die lagernden wieder auf gültig. Lief sie für einen
-- Lieferanten danach nicht mehr, stehen dessen Artikel als ungültig in der
-- Datenbank, obwohl der Shop sie als verfügbar zeigt. Hier wird nachgeholt, was
-- die Veröffentlichung getan hätte — mit derselben Bedingung (publish/main.php).
--
-- Quelltabellen und Nummernpräfixe stehen in publish/config.php und
-- publish/run.*.php der Webseite; je Shop anpassen. Fehlende Tabellen werden
-- übergangen. Nur für die in diesem Lauf übernommenen Artikel.

-- roh: Die Veröffentlichung dieses Lieferanten setzte die Beschreibung
-- unverändert in die Seite (run.hart.php von auto-spar.de), statt sie in
-- Absätze zu gliedern (main.php von solar-spar.de) — für Schritt 9.
CREATE TEMPORARY TABLE bridge_quelle (tabelle text PRIMARY KEY, praefix text NOT NULL,
                                      roh boolean NOT NULL DEFAULT false) ON COMMIT DROP;
INSERT INTO bridge_quelle VALUES
    ('parts_attack_source',            'AT/', false),
    ('parts_mainmetall_source',        'MM/', false),
    ('parts_schliessel_kaelte_source', 'SK/', false),
    ('parts_hart_source',              'HP/', true);

CREATE TEMPORARY TABLE wieder_gueltig (parts_id integer PRIMARY KEY) ON COMMIT DROP;

DO $$
DECLARE
    q record;
BEGIN
    FOR q IN SELECT tabelle, praefix FROM bridge_quelle WHERE to_regclass(tabelle) IS NOT NULL LOOP
        EXECUTE format($sql$
            WITH gesetzt AS (
                UPDATE parts p
                   SET obsolete = false
                  FROM %I s, uebernommen u
                 WHERE u.parts_id = p.id
                   AND p.partnumber = %L || s.number
                   AND p.obsolete
                   AND s.in_stock > 0
                   AND s.markdown_filename IS NOT NULL
                   AND COALESCE(s.category, '') <> ''
                   AND s.get_status = '200'
                   AND s.discounted_price <> '0'
                RETURNING p.id
            )
            INSERT INTO wieder_gueltig SELECT DISTINCT id FROM gesetzt ON CONFLICT DO NOTHING
        $sql$, q.tabelle, q.praefix);
    END LOOP;
END $$;

-- ----------------------------------------------------------------------------
-- 8. Dropshipping und Lieferzeit
-- ----------------------------------------------------------------------------
-- Ein Bridge-Shop führt keinen eigenen Bestand: der Lieferant versendet. Mit
-- der Vorgabe „Nur Dropshipping“ (shop_dropshipping_default) gilt jeder
-- Artikel als Dropshipping-Artikel und ist ohne Bestand „Verfügbar“.
--
-- Die Reihenfolge ist zwingend: Ein Dropshipping-Artikel ohne Lieferzeit wird
-- nicht veröffentlicht, eine vorhandene Seite wird zum Entwurf
-- (shop_part_delivery, Spalte missing). Deshalb zuerst die Lieferbedingung mit
-- ihren Tagen, dann ihre Vorgabe, zuletzt der Schalter.
--
-- Die Lieferzeit unten je Shop anpassen. Ist schon eine Lieferbedingung für
-- Dropshipping-Artikel vorgegeben, bleibt sie. Eine Wiederholung ohne neue
-- Artikel fasst den Schalter nicht an.

\set lieferzeit_text 'Lieferzeit 1–6 Tage'
\set lieferzeit_min 1
\set lieferzeit_max 6

WITH neu AS (
    INSERT INTO delivery_terms (description, description_long, sortkey)
    SELECT :'lieferzeit_text', :'lieferzeit_text', COALESCE((SELECT max(sortkey) FROM delivery_terms), 0) + 1
     WHERE NOT EXISTS (SELECT 1 FROM defaults_oserp d
                         JOIN delivery_terms t ON t.id::text = btrim(d.value)
                        WHERE d.key = 'shop_dropshipping_delivery_term_id')
    RETURNING id
), tage AS (
    INSERT INTO delivery_term_shop (delivery_term_id, days_min, days_max)
    SELECT id, :lieferzeit_min, :lieferzeit_max FROM neu
    RETURNING delivery_term_id
)
INSERT INTO defaults_oserp (key, value)
SELECT 'shop_dropshipping_delivery_term_id', delivery_term_id::text FROM tage
    ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now();

-- Der Schalter: nur im Lauf der Übernahme (neue Artikel), und nur, wenn die
-- Lieferbedingung steht. Das Schema-Update legt ihn mit 0 an.
INSERT INTO defaults_oserp (key, value)
SELECT 'shop_dropshipping_default', '1'
 WHERE EXISTS (SELECT 1 FROM uebernommen)
   AND EXISTS (SELECT 1 FROM defaults_oserp d
                 JOIN delivery_terms t ON t.id::text = btrim(d.value)
                WHERE d.key = 'shop_dropshipping_delivery_term_id')
    ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, mtime = now();

-- ----------------------------------------------------------------------------
-- 9. Marketingtexte
-- ----------------------------------------------------------------------------
-- Die Bridge führt Zusammenfassung und Beschreibung nicht am Artikel, sondern
-- in den Quelltabellen der Lieferanten (Spalte marketing, JSON mit summery
-- oder summary und description); ihre Veröffentlichung setzt sie in die
-- Produktseite. In der Erweiterung ist das die Beschreibung des Artikels im
-- HugoShop (parts_channel_shop.description) — ein Text: die Zusammenfassung,
-- eine Trennlinie (<hr>), die Beschreibung. So liest ihn der Vorlagensatz des
-- Shops (solarSparTexte in backend/templates/shop/solar-spar). Fehlt die
-- Zusammenfassung, steht die Beschreibung ohne Trennlinie — die Bridge zeigte
-- sie dann ganz an der Stelle der Zusammenfassung, der Reiter blieb leer.
--
-- Umgeformt wie in publish/main.php: Absätze der Beschreibung an Leerzeilen,
-- einfache Zeilenwechsel als <br />. Unlesbares JSON wird übergangen.
--
-- Nur, wo der Artikel im HugoShop noch keine Beschreibung hat — eine
-- Wiederholung überschreibt nichts, was inzwischen bearbeitet wurde.
-- Ausnahme: Texte in einer der beiden früheren Formen vom 18. und 19.11.2026
-- werden ersetzt, solange sie unverändert sind (p_form bildet sie zum
-- Vergleich nach: 1 = erster Absatz als Zusammenfassung, ohne Trennlinie;
-- 2 = mit Trennlinie, aber Zeilenwechsel am Absatzende als <br />). Anders
-- als die übrigen Schritte nicht auf die in diesem Lauf übernommenen Artikel
-- begrenzt: der Schritt kam am 18.11.2026 dazu und holt auch für früher
-- übernommene Artikel nach. Form 3 lässt die Beschreibung unverändert (Spalte
-- roh in bridge_quelle).

CREATE FUNCTION pg_temp.bridge_marketing(p_text text, p_form integer DEFAULT 0) RETURNS text LANGUAGE plpgsql AS $fn$
DECLARE
    m    jsonb;
    kurz text := '';
    lang text := '';
    roh  text;
BEGIN
    BEGIN
        m := p_text::jsonb;
    EXCEPTION WHEN others THEN
        RETURN NULL;
    END;
    IF m IS NULL OR jsonb_typeof(m) <> 'object' THEN
        RETURN NULL;
    END IF;

    IF jsonb_typeof(m -> 'summery') = 'string' THEN
        kurz := btrim(m ->> 'summery');
    ELSIF NOT jsonb_exists(m, 'summery') AND jsonb_typeof(m -> 'summary') = 'string' THEN
        kurz := btrim(m ->> 'summary');
    END IF;

    IF p_form = 3 AND jsonb_typeof(m -> 'description') = 'string' THEN
        -- roh: unverändert, ohne Absätze
        lang := m ->> 'description';
    ELSIF jsonb_typeof(m -> 'description') = 'string' THEN
        IF p_form > 0 THEN
            roh := btrim(regexp_replace(m ->> 'description', E'\r\n|\r', E'\n', 'g'));
            SELECT string_agg('<p>' || replace(btrim(a.absatz), E'\n', E'<br />\n') || '</p>', '' ORDER BY a.nr)
              INTO lang
              FROM regexp_split_to_table(roh, E'\n\n') WITH ORDINALITY AS a(absatz, nr)
             WHERE btrim(a.absatz) <> '';
        ELSE
            -- wie trim() und nl2br() in PHP: Leerraum samt Zeilenwechsel am
            -- Rand jedes Absatzes weg; leere Absätze bleiben stehen
            roh := regexp_replace(btrim(m ->> 'description', E' \t\n\r\x0B'), E'\r\n|\r', E'\n', 'g');
            IF roh <> '' THEN
                SELECT string_agg('<p>' || replace(btrim(a.absatz, E' \t\n\r\x0B'), E'\n', E'<br />\n') || '</p>', '' ORDER BY a.nr)
                  INTO lang
                  FROM regexp_split_to_table(roh, E'\n\n') WITH ORDINALITY AS a(absatz, nr);
            END IF;
        END IF;
    ELSIF jsonb_typeof(m -> 'description') = 'array' THEN
        SELECT string_agg(e.wert #>> '{}', '' ORDER BY e.nr) INTO roh
          FROM jsonb_array_elements(m -> 'description') WITH ORDINALITY AS e(wert, nr)
         WHERE jsonb_typeof(e.wert) = 'string';
        lang := CASE WHEN btrim(COALESCE(roh, '')) <> '' THEN '<p>' || btrim(roh) || '</p>' END;
    ELSIF jsonb_typeof(m -> 'description') = 'object' THEN
        SELECT string_agg(e.value #>> '{}', '') INTO roh
          FROM jsonb_each(m -> 'description') e
         WHERE jsonb_typeof(e.value) = 'string';
        lang := CASE WHEN btrim(COALESCE(roh, '')) <> '' THEN '<p>' || btrim(roh) || '</p>' END;
    END IF;
    lang := COALESCE(lang, '');

    IF kurz = '' THEN
        RETURN NULLIF(lang, '');
    END IF;
    IF p_form = 1 THEN
        RETURN '<p>' || kurz || '</p>' || lang;
    END IF;
    RETURN kurz || CASE WHEN lang <> '' THEN '<hr>' || lang ELSE '' END;
END
$fn$;

CREATE TEMPORARY TABLE mit_marketing (parts_id integer PRIMARY KEY) ON COMMIT DROP;

DO $$
DECLARE
    q record;
BEGIN
    FOR q IN SELECT b.tabelle, b.praefix, CASE WHEN b.roh THEN 3 ELSE 0 END AS form
               FROM bridge_quelle b
              WHERE to_regclass(b.tabelle) IS NOT NULL
                AND EXISTS (SELECT 1 FROM information_schema.columns c
                             WHERE c.table_schema = current_schema() AND c.table_name = b.tabelle
                               AND c.column_name = 'marketing') LOOP
        EXECUTE format($sql$
            WITH texte AS (
                SELECT DISTINCT ON (p.id) p.id AS parts_id, pg_temp.bridge_marketing(s.marketing::text, %s) AS text,
                       pg_temp.bridge_marketing(s.marketing::text, 1) AS form1,
                       pg_temp.bridge_marketing(s.marketing::text, 2) AS form2
                  FROM %I s
                  JOIN parts p ON p.partnumber = %L || s.number
                 WHERE s.marketing IS NOT NULL
                 ORDER BY p.id
            ), gesetzt AS (
                UPDATE parts_channel_shop pc
                   SET description = t.text
                  FROM texte t
                 WHERE pc.parts_id = t.parts_id
                   AND pc.channel_id = shop_first_channel_id('hugoshop')
                   AND (btrim(COALESCE(pc.description, '')) = ''
                        OR (pc.description IN (t.form1, t.form2) AND pc.description <> t.text))
                   AND t.text IS NOT NULL
                RETURNING pc.parts_id
            )
            INSERT INTO mit_marketing SELECT DISTINCT parts_id FROM gesetzt ON CONFLICT DO NOTHING
        $sql$, q.form, q.tabelle, q.praefix);
    END LOOP;
END $$;

-- ----------------------------------------------------------------------------
-- 10. EAN und Reihenfolge der TecDoc-Eigenschaften
-- ----------------------------------------------------------------------------
-- Zwei Angaben standen in der Bridge nur in der Quelltabelle des Lieferanten:
--
-- Die EAN (eancode): Die Veröffentlichung schrieb sie als productModel in die
-- Produktseite, aber nie an den Artikel. Sie kommt nach parts.ean, wo der
-- Artikel noch keine hat — ohne Leerraum: einzelne Einträge des Lieferanten
-- tragen Tabulatoren und Zeilenwechsel, an denen Hugo ab 0.170 beim Lesen
-- des Front Matters scheitert.
--
-- Die Reihenfolge der TecDoc-Eigenschaften: Die Quelltabelle führt sie als
-- json in der Reihenfolge des Lieferanten, die Bridge-Tabelle und die
-- Zusatzdaten als jsonb — dort geht die Reihenfolge verloren. Die Schlüssel
-- kommen deshalb in ihrer Reihenfolge als tecdoc_order in die Zusatzdaten;
-- der Vorlagensatz ordnet die Tabelle der Produktseite danach.
--
-- Wiederholbar und nicht auf die in diesem Lauf übernommenen Artikel
-- begrenzt: gesetzt wird nur, was fehlt.

CREATE TEMPORARY TABLE mit_ean (parts_id integer PRIMARY KEY) ON COMMIT DROP;
CREATE TEMPORARY TABLE mit_reihenfolge (parts_id integer PRIMARY KEY) ON COMMIT DROP;

DO $$
DECLARE
    q record;
BEGIN
    FOR q IN SELECT b.tabelle, b.praefix,
                    EXISTS (SELECT 1 FROM information_schema.columns c
                             WHERE c.table_schema = current_schema() AND c.table_name = b.tabelle
                               AND c.column_name = 'eancode') AS ean,
                    EXISTS (SELECT 1 FROM information_schema.columns c
                             WHERE c.table_schema = current_schema() AND c.table_name = b.tabelle
                               AND c.column_name = 'tecdoc_params') AS tecdoc
               FROM bridge_quelle b
              WHERE to_regclass(b.tabelle) IS NOT NULL LOOP
        IF q.ean THEN
            EXECUTE format($sql$
                WITH gesetzt AS (
                    UPDATE parts p
                       SET ean = regexp_replace(s.eancode, '[[:space:]]+', '', 'g')
                      FROM %I s
                     WHERE p.partnumber = %L || s.number
                       AND btrim(COALESCE(p.ean, '')) = ''
                       AND regexp_replace(COALESCE(s.eancode, ''), '[[:space:]]+', '', 'g') <> ''
                       AND EXISTS (SELECT 1 FROM parts_ext pe WHERE pe.parts_id = p.id)
                    RETURNING p.id
                )
                INSERT INTO mit_ean SELECT DISTINCT id FROM gesetzt ON CONFLICT DO NOTHING
            $sql$, q.tabelle, q.praefix);
        END IF;
        IF q.tecdoc THEN
            EXECUTE format($sql$
                WITH folge AS (
                    SELECT DISTINCT ON (p.id) p.id AS parts_id,
                           (SELECT jsonb_agg(k.schluessel ORDER BY k.nr)
                              FROM json_object_keys(s.tecdoc_params::json) WITH ORDINALITY AS k(schluessel, nr)) AS schluessel
                      FROM %I s
                      JOIN parts p ON p.partnumber = %L || s.number
                     WHERE s.tecdoc_params IS NOT NULL
                       AND json_typeof(s.tecdoc_params::json) = 'object'
                     ORDER BY p.id
                ), gesetzt AS (
                    UPDATE parts_ext pe
                       SET hugoshop_extra = pe.hugoshop_extra || jsonb_build_object('tecdoc_order', f.schluessel)
                      FROM folge f
                     WHERE pe.parts_id = f.parts_id
                       AND f.schluessel IS NOT NULL
                       AND jsonb_typeof(pe.hugoshop_extra -> 'tecdoc_params') = 'object'
                       AND NOT jsonb_exists(pe.hugoshop_extra, 'tecdoc_order')
                    RETURNING pe.parts_id
                )
                INSERT INTO mit_reihenfolge SELECT DISTINCT parts_id FROM gesetzt ON CONFLICT DO NOTHING
            $sql$, q.tabelle, q.praefix);
        END IF;
    END LOOP;
END $$;

-- ----------------------------------------------------------------------------
-- Bericht
-- ----------------------------------------------------------------------------

SELECT (SELECT count(*) FROM uebernommen)                                         AS artikel_neu,
       (SELECT count(*) FROM parts_ext)                                           AS artikel_gesamt,
       (SELECT count(*) FROM parts_channel_shop pc
         WHERE pc.channel_id = shop_first_channel_id('hugoshop') AND pc.active)   AS angeboten,
       (SELECT count(*) FROM parts_channel_shop pc
         WHERE pc.channel_id = shop_first_channel_id('hugoshop') AND pc.active
           AND NOT shop_part_available(pc.parts_id, pc.channel_id))               AS davon_nicht_verfuegbar,
       (SELECT count(*) FROM wieder_gueltig)                                      AS wieder_gueltig,
       (SELECT count(*) FROM mit_marketing)                                       AS marketingtexte_gesetzt,
       (SELECT count(*) FROM parts_channel_shop pc
         WHERE pc.channel_id = shop_first_channel_id('hugoshop') AND pc.active
           AND btrim(COALESCE(pc.description, '')) = '')                          AS ohne_beschreibung,
       (SELECT count(*) FROM parts_channel_shop pc
         WHERE pc.channel_id = shop_first_channel_id('hugoshop') AND pc.active
           AND shop_part_dropshipping(pc.parts_id))                               AS dropshipping,
       (SELECT count(*) FROM parts_channel_shop pc
         CROSS JOIN LATERAL shop_part_delivery(pc.parts_id, pc.channel_id) lz
         WHERE pc.channel_id = shop_first_channel_id('hugoshop') AND pc.active
           AND lz.missing)                                                        AS ohne_lieferzeit,
       (SELECT count(*) FROM category_shop)                                       AS kategorien,
       (SELECT count(*) FROM parts_ext WHERE hugoshop_category_id IS NULL)        AS ohne_kategorie,
       (SELECT count(*) FROM parts_ext
         WHERE hugoshop_downloads IS NOT NULL AND hugoshop_downloads <> '{}'::jsonb) AS mit_downloads,
       (SELECT count(*) FROM parts_ext
         WHERE jsonb_typeof(hugoshop_technical_data) NOT IN ('object', 'null'))   AS technische_daten_pruefen,
       (SELECT count(*) FROM parts_ext WHERE hugoshop_extra IS NOT NULL)          AS mit_zusatzdaten,
       (SELECT count(*) FROM mit_ean)                                             AS ean_gesetzt,
       (SELECT count(*) FROM mit_reihenfolge)                                     AS tecdoc_reihenfolge_gesetzt,
       (SELECT count(*) FROM parts_ext
         WHERE btrim(COALESCE(hugoshop_search_terms, '')) <> '')                  AS mit_suchwoertern,
       (SELECT count(*) FROM customer_ext WHERE portal_guest)                     AS gastkunden,
       (SELECT count(*) FROM customer_ext WHERE NOT portal_guest)                 AS kundenkonten,
       (SELECT count(*) FROM invoice_link_oserp)                                  AS rechnungslinks,
       (SELECT count(*) FROM batchjob_hugoshop WHERE result IS NULL)              AS offene_auftraege;

COMMIT;
