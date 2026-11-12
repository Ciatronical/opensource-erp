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
--
-- Angeboten (Kanalzeile aktiv) werden nur Artikel, die nicht als ungültig
-- markiert sind (parts.obsolete). Die Angaben der übrigen bleiben erhalten.
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
                           hugoshop_downloads, hugoshop_images, hugoshop_hyperlink, hugoshop_category)
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
           NULLIF(btrim(q.category), '')
      FROM quelle q
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
SELECT u.parts_id, shop_first_channel_id('hugoshop'), NOT COALESCE(p.obsolete, false)
  FROM uebernommen u
  JOIN parts p ON p.id = u.parts_id
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
-- Bericht
-- ----------------------------------------------------------------------------

SELECT (SELECT count(*) FROM uebernommen)                                         AS artikel_neu,
       (SELECT count(*) FROM parts_ext)                                           AS artikel_gesamt,
       (SELECT count(*) FROM parts_channel_shop pc
         WHERE pc.channel_id = shop_first_channel_id('hugoshop') AND pc.active)   AS angeboten,
       (SELECT count(*) FROM parts_channel_shop pc
         WHERE pc.channel_id = shop_first_channel_id('hugoshop') AND NOT pc.active) AS nicht_angeboten,
       (SELECT count(*) FROM category_shop)                                       AS kategorien,
       (SELECT count(*) FROM parts_ext WHERE hugoshop_category_id IS NULL)        AS ohne_kategorie,
       (SELECT count(*) FROM parts_ext
         WHERE hugoshop_downloads IS NOT NULL AND hugoshop_downloads <> '{}'::jsonb) AS mit_downloads,
       (SELECT count(*) FROM parts_ext
         WHERE jsonb_typeof(hugoshop_technical_data) NOT IN ('object', 'null'))   AS technische_daten_pruefen,
       (SELECT count(*) FROM customer_ext WHERE portal_guest)                     AS gastkunden,
       (SELECT count(*) FROM customer_ext WHERE NOT portal_guest)                 AS kundenkonten,
       (SELECT count(*) FROM invoice_link_oserp)                                  AS rechnungslinks,
       (SELECT count(*) FROM batchjob_hugoshop WHERE result IS NULL)              AS offene_auftraege;

COMMIT;
