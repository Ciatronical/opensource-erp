# eBay-Kanal: Angebote übernehmen, Verkäufe prüfen, Produktseiten

Stand 2026-10-26. Baut auf dem eBay-Kanal aus `dev/shop-verkaufskanaele.md`
(Schritt 5) und `dev/shop-mehrere-kanaele.md` auf. Anwenderdoku:
`docs/features/ebay.md`.

## Ziel

Betriebe mit vielen bestehenden eBay-Angeboten: übernehmen statt neu
einstellen, jeden Verkauf ohne Kundendubletten abrechnen, nur unklare Käufer
von Hand prüfen, Produktseiten mit KI gestalten.

## Dateien

| Datei | Inhalt |
|-------|--------|
| `backend/upstall/shop/company_schema.sql`, Abschnitt EBAY | `ebay_listing_shop`, Normalformen, `ebay_buyer`, `ebay_customer_match`, `ebay_order_items`, `ebay_order_upsert`, `ebay_listing_link/unlink/autolink`, `ebay_listings`, `ebay_board`, `ebay_gross_price`; neue Kanalschlüssel in `shop_channel_setting_keys` |
| `backend/upstall/crm/company_schema.sql` | `ebay_orders`: state, match, payment_status, cancel_state, ordered_at, modified_at, error, decided_by/at, tracking_number, carrier, shipped_at, ship_error |
| `backend/api/shop/channels/ebay.php` | Scopes (gemerkt in `settings.scopes`), Abzweig zu übernommenen Angeboten in `shopEbayPublishPartNow`/`shopEbayEndPartNow`, Artikelmerkmale aus der Seite |
| `backend/api/shop/channels/ebay_listings.php` | Trading-API (`shopEbayTrading`, OAuth-Token im Kopf `X-EBAY-API-IAF-TOKEN`), OAuth-Zustimmung und Codetausch, GetSellerList → `ebay_listing_shop`, `shopEbayReviseLegacy` |
| `backend/api/shop/channels/ebay_orders.php` | Abruf nach `lastmodifieddate`, `shopEbayImportOrder` → `ebay_order_upsert`, `shopEbayInvoiceOrder`, `shopEbayPushTracking` |
| `backend/api/shop/ebay.php` | API des Boards (Board, Angebote, Abgleich, Verbinden, Entscheiden, Versand, Zuordnen, Artikel anlegen, Seiten, KI, Suchen) |
| `backend/api/shop/lib/ebay_page.php` | Renderer der Produktseiten (Vorlagen klar/modern/kompakt) |
| `backend/webhook/ebay-oauth.php` | Rücksprung der Zustimmung, gibt Code/State per postMessage an die Oberfläche |
| `backend/cli/ebay-orders.php` | Cron: Bestellungen, Versand, alle 6 h Angebote |
| `src/features/shop/views/shop.ebay.vue` | Board (Route `shop-ebay`) |
| `src/features/shop/components/ebay-review.card.vue`, `ebay-listings.section.vue`, `ebay-page.dialog.vue` | Prüfkarte, Angebote, Seiten-Editor |

## Entscheidungen

- **E1 Übernommene Angebote sind Kanalzeilen.** Zuordnen legt `parts_channel_shop`
  mit `sync_data.legacy_item_id` an. Damit greifen die vorhandenen Trigger
  (Bestand, Preis, Texte → Auftrag `publish_part`) und die Artikelkarte zeigt den
  Kanal. `shopEbayPublishPartNow` verzweigt auf `shopEbayReviseLegacy` — nie ein
  zweites Angebot über die Inventory-API.
- **E2 Nichts ändern ist die Vorgabe** (`legacy_sync = off`). Viele Betriebe
  pflegen den Bestand in OSERP nicht; Menge = Lager setzte sonst Hunderte
  Angebote auf 0. Das Board warnt bei Überverkauf und führt zum Schalter.
- **E3 OSERP beendet übernommene Angebote nie.** Abwählen und Kanal aus heißen
  nur „nicht mehr abgleichen“; `remove_all` erfasst sie nicht (kein `offer_id`).
- **E4 Bestellungen erst festhalten, dann abrechnen.** `ebay_order_upsert` ist
  die eine Regel (bezahlt? storniert? vor `import_since`? Käufer klar?); PHP legt
  nur die Rechnung an. Abruf nach Änderungsdatum mit 10 Minuten Überlappung —
  Wiederholungen sind gefahrlos.
- **E5 Käuferabgleich in SQL mit Punkten und Gründen** (`ebay_customer_match`).
  Sicher ≥ 85 ohne zweiten in Reichweite, neu < 40. „Straße ähnlich“ nur bei
  gleicher Hausnummer — sonst wurden Nachbarn Kandidaten. Lernen über
  `ebay_orders.buyer_username → customer_id` (auch nach einer Entscheidung).
- **E6 Registrierungsadresse = Rechnungsanschrift.** Nur sie löst „umgezogen?“
  aus; abweichende Lieferanschriften (Werkstatt, Nachbar) sind normal.
- **E7 Anmeldung im Popup, Rücksprung ohne Datenbank.** Die Webhook-Seite gibt
  Code und State per postMessage (Ziel `*`, weil Oberfläche und öffentliche
  Adresse verschieden sein können); der Code taugt nur mit dem Client-Secret.
  State = Kanal + Zeit, HMAC mit dem Client-Secret, eine Stunde gültig.
- **E8 Seiten im Backend gerendert**, eine Stelle für Vorschau, Speichern und
  KI. Nur Inline-Stile (die eBay-App entfernt `<style>`), alles maskiert.
  Aufbau in `parts_channel_shop.settings.page`, HTML in `description`, Titel in
  `title`; Merkmale gehen als `aspects` (Inventory) bzw. `ItemSpecifics` (Trading).

## Offen

- Gegen ein echtes eBay-Konto bzw. die Sandbox noch nicht gelaufen (keine
  Zugangsdaten auf dem Entwicklungsrechner). Zuerst in der Sandbox prüfen:
  Verbinden, GetSellerList mit `OutputSelector`, ReviseInventoryStatus,
  shipping_fulfillment, `buyerRegistrationAddress` in den Bestellungen.
- eBay-Auszahlungen (Sammelzahlungen) im Banking zuordnen — wie SumUp/Flatpay.
- Richtlinien und Lagerorte über die Account-API als Auswahl statt Kennung.
- 19 Sprachen der neuen Texte.
