# Shop: Mehrere Verkaufskanäle je Art

Stand 2026-10-01. Status: **geplant** — Entscheidungen M1–M7 getroffen,
Schemaänderungen freigegeben (2026-10-01), nichts umgesetzt.

Ein Verkaufskanal ist künftig eine **Instanz** einer Kanalart. Kanalarten sind
HugoShop und eBay, später weitere (etwa Amazon). Je Mandant gibt es beliebig
viele HugoShops und beliebig viele eBay-Anbindungen.

Damit ist Entscheidung **V3** aus `dev/shop-verkaufskanaele.md` („je Mandant
höchstens ein Kanal je Art", 2026-09-25) aufgehoben.

## Gilt für

- Erweiterung `shop`: `backend/upstall/shop/company_schema.sql`,
  `backend/api/shop/`, `src/features/shop/`, Reiter „Shop" der
  Firmenkonfiguration
- `ebay_orders` (Erweiterung `crm`)
- Webseiten: unverändert — jede Webseite hat schon ihren eigenen Schlüssel

## Ausgangslage

Schon je Kanal-Kennung gespeichert und damit ohne Umbau tragfähig:

- Artikelzuordnung `parts_channel_shop`, Bilder `parts_channel_image_shop`
- Aufträge `batchjob_hugoshop.channel_id`
- Versandpreise `shipping_rate_shop.channel_id`, Lieferländer
  `sales_channel_country_shop`, Freigrenze `sales_channel_shop.free_shipping_from`
- Kanalarten als Module `channels/<art>.php` (`SHOP_CHANNEL_TYPES`,
  `shopChannel<Art>JobFunctions/RunJob/Switched`) — Amazon wäre ein weiteres
  Modul

## Was dagegen steht

1. **Schema:** `sales_channel_shop` hat `UNIQUE (type)`.
2. **Kanal über die Art gefunden statt über die Kennung:** rund 20 Stellen im
   SQL und 60 im PHP — `shop_channel_id(art)`, `shop_active_channel_id(art)`,
   `shop_channel_price(…, art)`, `shop_part_available(…, art)`,
   `shop_queue_job(…, art)`, `shop_cart_shipping` (fest `'hugoshop'`),
   `type = 'hugoshop'` in Triggern und Abfragen. Schwerpunkte: `cart.php`,
   `publish.php`, `ebay.php`, `admin.php`, `hugoshop.php`, `search.php`,
   `categories.php`, `hugocms.php`, `analytics.php`, `invoice.php`,
   `channel_images.php`. In Aufträgen steht `channel_id NULL` für „HugoShop"
   — mit mehreren HugoShops nicht mehr eindeutig.
3. **Einstellungen je Mandant:** alle `shop_*`- und `ebay_*`-Schlüssel stehen
   einmal in `defaults_oserp`. Ein Teil gilt nur für eine Instanz:

   | Bereich | Instanz-Einstellungen |
   |---|---|
   | HugoShop | `shop_public_key`, `shop_base_url`, `shop_backend_url`, `shop_allowed_origins`, `shop_site_dir`, `shop_content_dir`, `shop_images_dir`/`_link`, `shop_thumbnails_dir`/`_link`, `shop_downloads_link`, `shop_products_link`, `shop_category_link`, `shop_template_set`, `shop_publish_mode`, `shop_publish_clean_destination`, `shop_hugocms_url`, `shop_hugocms_key`, `shop_auto_publish`, `shop_invoice_mail_subject`, `shop_withdrawal_mail_to`, PayPal (`shop_paypal_*`) |
   | eBay | alle `ebay_*`: Zugang, Token, Umgebung, Marktplatz, Sprache, Währung, Richtlinien, Lagerort, Kategorie- und Zustandsvorgabe, Sammelartikel, Mitarbeiter, letzter Abruf |

   Je Mandant bleiben: `shop_standard_taxzone`, `shop_standard_currency`,
   `shop_tax_included`, `shop_stock_bin_id`, `shop_incoming_account`,
   `shop_target_account`, Bankverbindung (`shop_payment_*`),
   `shop_cart_lifetime_hours`, `shop_context_lifetime_hours`,
   `shop_job_retention_days`, `shop_search_weighting`,
   `shop_active_price_source`, `shop_thumbnail_size`, `shop_sites_dir`
   (Wurzel und Riegel aller Webseiten), `shop_shipping_partnumber`.
4. **Tabellen ohne Kanalbezug:** `carts_hugoshop`, `context_hugoshop`,
   `ar_link_hugoshop` (welcher Shop die Rechnung erzeugt hat: Mail-Signatur,
   Rechnungsseite, PayPal-Rücksprung), `withdrawals_hugoshop`,
   `redirect_pages_hugoshop`, `ebay_orders`.
5. **Öffentlicher Zugang:** Der Schlüssel `X-Shop-Key` bestimmt heute den
   Mandanten, künftig Mandant **und** Kanal. Passt ohne Umbau der Webseiten;
   auch HugoCMS kennt je Schlüssel genau ein Webprojekt.

## Weitere Stolpersteine

- **Derselbe eBay-Zugang in zwei Kanälen:** Der Inventareintrag (Menge, Titel,
  Los) gehört bei eBay dem Konto, nicht dem Angebot — zwei Kanäle mit einem
  Konto überschrieben sich gegenseitig (→ M4).
- **Artikeltexte in `parts_ext`** (Bilder, Downloads, Navigationspfad,
  Kategorie, Zielseite) gelten für „den" HugoShop (→ M6).
- **Bilder:** Medien bleiben auf der Webseite (Entscheidung 24.09.). Zwei
  Webseiten brauchen jede ihre eigenen Bilddateien.
- **Gemeinsamer Bestand:** Jeder weitere Kanal erhöht die Gefahr, mehr zu
  verkaufen als vorhanden — besteht heute schon, wird deutlicher.
- **Neue Artikel:** Der Trigger auf `parts_ext` nimmt neue Artikel in „den"
  HugoShop auf (→ M3).

## Entscheidungen (2026-10-01)

| Nr. | Frage | Entscheidung |
|---|---|---|
| M1 | Kundenkonten je Shop oder gemeinsam? | **Gemeinsam** für alle HugoShops des Mandanten — ein Kunde ist ein Datensatz, wie in kivitendo. |
| M2 | PayPal-Zugang je Shop oder je Mandant? | **Je Shop**, ohne Rückfall auf den Mandanten. |
| M3 | Wohin kommen neue Artikel automatisch? | Je Kanal ein Schalter „neue Artikel automatisch aufnehmen". |
| M4 | Derselbe eBay-Zugang zweimal? | **Nicht zulässig** — ein Kanal je eBay-Konto, beim Verbinden geprüft. |
| M5 | Kanal löschen? | Nur abschalten; löschen nur, solange keine Belege oder Bestellungen am Kanal hängen. |
| M6 | Artikeltexte (`parts_ext`) je HugoShop? | **Vorerst gemeinsam**; Abweichungen je Kanal (Zielseite, Navigationspfad) später nach Bedarf. |
| M7 | Wo werden Instanz-Einstellungen gepflegt? | In der Kanalkarte, eine aufklappbare Karte je Instanz; der Reiter „Shop" behält die Einstellungen je Mandant. |

## Schemaänderungen (freigegeben 2026-10-01)

Nur eigene Tabellen, keine kivitendo-Originaltabellen.

- `sales_channel_shop`: `UNIQUE (type)` entfällt; neu `name text NOT NULL`
  und `auto_add_parts boolean` (M3).
- Neue Tabelle `sales_channel_secret_shop (channel_id, key, value)` für
  Geheimnisse je Kanal — `settings` geht an die Oberfläche, Geheimnisse nie.
- `channel_id` (Verweis auf `sales_channel_shop`) in `carts_hugoshop`,
  `context_hugoshop`, `ar_link_hugoshop`, `withdrawals_hugoshop`,
  `redirect_pages_hugoshop`, `ebay_orders`.
- `batchjob_hugoshop.channel_id`: `NULL` wird auf den bisherigen HugoShop
  nachgetragen, danach Pflicht.
- Übernahme beim Schema-Update: die Instanz-Werte aus `defaults_oserp` in die
  bestehenden Kanalzeilen (`settings` bzw. `sales_channel_secret_shop`), Namen
  „HugoShop" und „eBay". Die Kennungen der Kanäle bleiben — Artikelzuordnungen,
  Bilder, Versandpreise und Aufträge gelten unverändert weiter.
- SQL-Funktionen nehmen die Kanal-Kennung statt der Art.

## Schritte

1. Schema, Übernahme der Einstellungen, SQL-Funktionen auf Kanal-Kennung.
2. Einstellungen je Kanal (`shopChannelConfig($db, $kanal)`); öffentlicher
   Zugang ermittelt den Kanal aus dem Schlüssel; Warenkorb, Kasse, Rechnung,
   Mail und Widerruf arbeiten mit diesem Kanal.
3. Veröffentlichung je HugoShop: eigene Webseite, eigenes Paket, eigene
   Aufträge; die Sperre des Laufs bleibt gemeinsam.
4. eBay je Kanal: Zugang, Token, Bestellabruf (Cron über alle eBay-Kanäle),
   Prüfung M4.
5. Oberfläche: Kanal anlegen (Art wählen, Name), Kanalkarte mit
   Instanz-Einstellungen (M7), Kanalname statt Art in Artikelmaske,
   Artikelsuche, Versandpreisen, Shop-Übersicht, Auftragsliste.
6. Tests, Dokumentation; V3 in `dev/shop-verkaufskanaele.md` als aufgehoben
   vermerken.

Umfang: mindestens wie der Versand (`dev/shop-versand.md`).
