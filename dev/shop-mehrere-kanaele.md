# Shop: Mehrere Verkaufskanäle je Art

Stand 2026-10-01. Status: **umgesetzt** — Entscheidungen M1–M7 getroffen,
Schemaänderungen freigegeben (2026-10-01), Schritte 1 bis 6 erledigt. Offen
sind nur Prüfungen gegen die echten Dienste (eBay, PayPal, HugoCMS mit
mehreren Webseiten) und die Oberfläche im Browser.

> **Hinweis (2026-10-07):** Die Betriebsart „lokal“ — OpensourceERP schreibt in
> ein Verzeichnis auf seinem eigenen Server und baut die Webseite selbst — gibt
> es nicht mehr; veröffentlicht wird nur noch über HugoCMS. Was dieses Dokument
> darüber sagt (`shop_sites_dir`, `shop_site_dir`, `shop_publish_command_path`,
> `shop_publish_mode`, `shop_images_dir`, `shop_thumbnails_dir`,
> `publish_clean_destination`, Bau mit eigenem Hugo-Programm), beschreibt einen
> früheren Stand. Der laufende Betrieb steht in `dev/shop-betrieb.md`.
>
> **Hinweis (2026-10-09):** Das Verzeichnis der Produktseiten stellt
> OpensourceERP nicht mehr selbst ein (`shop_content_dir` bzw. `content_dir`
> entfallen), und HugoCMS wertet `[shop] areas` nicht mehr aus: Wohin die
> Anbindung schreiben darf, legt ein Administrator in HugoCMS als Freigaben
> fest (`dev/shop-hugocms-verzeichnisse.md`).

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
   (Wurzel und Riegel aller Webseiten). (`shop_shipping_partnumber` entfiel
   2026-10-02: jede Versandart hat ihren Versandartikel, dev/shop-versand.md.)
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

1. Schema, Übernahme der Einstellungen, SQL-Funktionen auf Kanal-Kennung —
   **erledigt 2026-10-01**, siehe unten.
2. Einstellungen je Kanal (`shopChannelConfig($db, $kanal)`); öffentlicher
   Zugang ermittelt den Kanal aus dem Schlüssel; Warenkorb, Kasse, Rechnung,
   Mail und Widerruf arbeiten mit diesem Kanal — **erledigt 2026-10-01**,
   siehe unten.
3. Veröffentlichung je HugoShop: eigene Webseite, eigenes Paket, eigene
   Aufträge; die Sperre des Laufs bleibt gemeinsam — **erledigt 2026-10-01**,
   siehe unten.
4. eBay je Kanal: Zugang, Token, Bestellabruf (Cron über alle eBay-Kanäle),
   Prüfung M4 — **erledigt 2026-10-01**, siehe unten.
5. Oberfläche: Kanal anlegen (Art wählen, Name), Kanalkarte mit
   Instanz-Einstellungen (M7), Kanalname statt Art in Artikelmaske,
   Artikelsuche, Versandpreisen, Shop-Übersicht, Auftragsliste; danach die
   Instanz-Schlüssel aus `defaults_oserp` und dem Reiter „Shop" entfernen,
   ebenso den Übergangs-Trigger — **erledigt 2026-10-01**, siehe unten.
6. Übergang entfernen, Tests, Dokumentation; V3 in
   `dev/shop-verkaufskanaele.md` als aufgehoben vermerken — **erledigt
   2026-10-01**, siehe unten.

Umfang: mindestens wie der Versand (`dev/shop-versand.md`).

## Stand der Umsetzung

### Schritt 1: Schema und SQL-Funktionen (2026-10-01)

Alles in `backend/upstall/shop/company_schema.sql`. PHP ist unverändert und
läuft wie bisher — dafür gibt es Übergangsregeln, die in den Schritten 2 bis
4 entfallen.

**Kanaltabelle** `sales_channel_shop`:

- `UNIQUE (type)` entfernt.
- `name`: Pflicht, nicht leer, eindeutig ohne Rücksicht auf Groß- und
  Kleinschreibung und Leerraum (`sales_channel_shop_name_key` auf
  `lower(btrim(name))`). Bestehende Kanäle heißen „HugoShop" und „eBay".
  Angelegt ohne NOT NULL, gefüllt, dann NOT NULL — der Upstall trägt Spalten
  mit ihrer ganzen Definition nach, ein NOT NULL ohne Vorgabe scheiterte an
  vorhandenen Zeilen.
- `auto_add_parts` (M3): der bestehende HugoShop einmalig `true`
  (Merker `shop_auto_add_migrated`). Der Trigger auf `parts_ext` nimmt neue
  Artikel in jeden Kanal mit dem Schalter auf; das Löschen einer
  `parts_ext`-Zeile schaltet den Artikel in allen HugoShops ab.
- Grundausstattung (ein HugoShop, ein eBay-Kanal) nur einmal je Mandant
  (Merker `shop_channels_seeded`), damit ein gelöschter Kanal nicht
  wiederkommt.

**Geheimnisse:** neue Tabelle `sales_channel_secret_shop (channel_id, key,
value)`.

**Kanal in den Instanz-Tabellen** (Abschnitt INSTANZEN am Ende der Datei):
`carts_hugoshop`, `context_hugoshop`, `redirect_pages_hugoshop`,
`batchjob_hugoshop`, `ar_link_hugoshop`, `withdrawals_hugoshop`,
`ebay_orders` — `channel_id` nachgetragen, NOT NULL, Fremdschlüssel, Index.
Löschen eines Kanals (M5): Warenkörbe, Sitzungen, Weiterleitungen und
Aufträge gehen mit; Rechnungslinks, Widerrufe und eBay-Bestellungen
verhindern es.

**Einstellungen:** `shop_channel_setting_keys()` ordnet jedem Instanz-Schlüssel
aus `defaults_oserp` seinen Namen ohne Präfix zu und sagt, ob er geheim ist.
Nicht geheime Werte gehen nach `settings`, geheime nach
`sales_channel_secret_shop`.

**SQL-Funktionen mit Kanal-Kennung:** `shop_channel_price(parts, kanal)`,
`shop_part_available(parts, kanal)`, `shop_active_channel_id(kanal)`,
`shop_queue_job(funktion, nummer, param, kanal)` (ohne Vorgaben),
`shop_auto_publish_enabled(kanal)`. Alle Trigger arbeiten mit der Kennung:
Aufträge entstehen je Kanal, die automatische Veröffentlichung gilt je
HugoShop. `shop_cart_shipping` nimmt den Kanal des Warenkorbs
(Preisstufen, Lieferländer, Freigrenze). `shop_book_stock` übergeht den
Sammelartikel jedes eBay-Kanals.

**Übergang bis Schritt 4** — entfällt danach:

- Die Fassungen mit der Art (`shop_channel_id('hugoshop')`,
  `shop_channel_price(id, 'ebay')` …) bleiben und nehmen den
  **Standardkanal der Art** (kleinster `sortkey`, dann kleinste Kennung).
- `channel_id` hat die Vorgabe `shop_channel_id('<art>')`, damit Schreiber
  ohne Kanal (PHP, Lieferantenimport der Bridge) gültig bleiben. Vor dem
  Entfernen der text-Fassungen muss die Vorgabe weg.
- Die Werte in `defaults_oserp` bleiben maßgeblich; jedes Schema-Update
  schreibt sie erneut in den Standardkanal (ein dort geleertes Geheimnis wird
  entfernt). Seit Schritt 2 überträgt zusätzlich ein Trigger jede Änderung
  sofort (siehe dort); gelöscht werden die Schlüssel erst in Schritt 5.
- **Falle bei Platzhaltern:** PDO übergibt Platzhalter ohne Typ, PostgreSQL
  wählt dann die text-Fassung. Kennungen deshalb immer als
  `CAST(:kanal AS integer)` übergeben. Eine verwechselte Kennung bricht laut
  ab (`shop_channel_type_check`: „Unbekannte Kanalart"), statt still nichts
  zu finden.
- Kein `?`-Operator für jsonb in Funktionen, die PHP ausführt: PDO hält ihn
  für einen Platzhalter.

**Getestet** in einer zurückgerollten Transaktion an Werkzeug24 mit dem
echten Upstall (`updateDatabaseSchema`, zweimal): Index entfernt, Namen,
NOT NULL; 25 Einstellungen in `settings`, 4 Geheimnisse in der neuen Tabelle,
keins in `settings`, Werte gleich `defaults_oserp`; `channel_id` in allen
sieben Tabellen gefüllt, NOT NULL, Fremdschlüssel (78 Rechnungslinks).
Beide Fassungen der Funktionen liefern dasselbe. Mit einem zweiten
HugoShop: Name eindeutig und nicht leer; neuer Shop-Artikel in beiden
HugoShops, nicht bei eBay; Preisänderung erzeugt je HugoShop einen Auftrag,
eine geänderte Kanalzeile nur im eigenen; Aufschlag je Kanal (8,90 → 9,79);
Lieferländer und Freigrenze nach dem Kanal des Warenkorbs; Löschen mit
Rechnungslink verweigert, ohne Belege möglich. `shopQueueJob` und
`getShopChannels` laufen unverändert, ohne Geheimnisse in der Antwort.
Nicht getestet: Neuinstallation auf leerer Datenbank.

### Schritt 2: Einstellungen je Kanal, öffentlicher Zugang (2026-10-01)

**Lesen:** `shopChannelConfig($db, $kanal)` liefert `settings` und Geheimnisse
eines Kanals (Schlüssel ohne Präfix, dazu `id`, `type`, `name`, `active`), je
Request zwischengespeichert. Dazu `shopChannelValue`, `shopChannelBool`,
`shopChannelRequire` — die Meldung nennt Feld und Kanal („Die Einstellung
'PayPal Client-ID (Testumgebung)' des Verkaufskanals 'Zweitshop' ist nicht
gesetzt"). Einstellungen des ganzen Mandanten bleiben bei `shopConfig*()`.

**Brücke bis Schritt 5:** Gepflegt wird weiter im Reiter „Shop". Der Trigger
`defaults_oserp_shop_channel_sync` überträgt jede Änderung eines
Instanz-Schlüssels sofort in den Standardkanal der Art (geleertes Geheimnis:
entfernt). `shop_auto_publish_enabled(kanal)` liest nur noch `settings`.

**Öffentlicher Zugang** (`public/bootstrap.php`):

- Der Shop-Schlüssel (`X-Shop-Key`) wird mit `public_key` aller HugoShops
  aller Mandanten verglichen und bestimmt Mandant **und** Kanal; ein
  eindeutiger Index (`sales_channel_secret_shop_public_key`) verhindert
  denselben Schlüssel in zwei Kanälen. Auch ein abgeschalteter HugoShop wird
  gefunden — Konto, Rechnungen, Widerruf bleiben erreichbar, neue Käufe
  sperrt `shopIsOpen($db, $kanal)`.
- CORS nach `allowed_origins` des Kanals.
- Eine Sitzung gehört einem HugoShop. Bringt das Cookie die Sitzung eines
  anderen mit (zwei HugoShops unter demselben Host teilen sich das Cookie),
  gibt es eine neue Kennung — danach muss keine Fachfunktion den Kanal der
  Sitzung mehr prüfen. **Grenze:** zwei HugoShops unter einem Host verlieren
  beim Wechsel ihre Sitzung; jeder HugoShop braucht deshalb einen eigenen
  Host.
- Aktionen bekommen den Kanal als vierten Parameter
  (`$db, $uuid, $daten, $kanal`); wer ihn nicht braucht, lässt ihn weg.

**Fachfunktionen:**

| Bereich | Kanal aus | Je Kanal |
|---|---|---|
| Sitzung | Anfrage | `context_hugoshop.channel_id` |
| Warenkorb | Sitzung (`cartOfContext`) | Preis, Angebot, Verfügbarkeit; Zusammenführen beim Anmelden nur mit Körben desselben Kanals (Konten gemeinsam, M1) |
| Rechnung | Sitzung | Preis und Bezeichnung des Kanals, `ar_link_hugoshop.channel_id` |
| Rechnungsseite, PDF über Link, Kaufauswertung | Anfrage | Link gilt nur im eigenen Kanal |
| PayPal (M2) | Sitzung bzw. Anfrage (Rückweg), Abgleich: Rechnungslink | Zugang, Test-/Echtbetrieb, Fehlertest, Rücksprungadresse |
| Rechnungsmail | Rechnungslink | Signatur (`base_url`), Betreff |
| Kontaktmail, Widerruf | Anfrage | Empfänger (`withdrawal_mail_to`), `withdrawals_hugoshop.channel_id` |
| Suche, Artikelauswertung | Anfrage | Sortiment, Preis, Adressmuster (`products_link`, `thumbnails_link`) |
| Umleitungen | Anfrage | `redirect_pages_hugoshop.channel_id` |

Unverändert: die Bestellübersicht im Konto zeigt alle Rechnungen des Kunden
(wie bisher auch solche aus dem ERP); Bankverbindung, Steuerzone, Währung und
Kontakt-Mitarbeiter gelten für den ganzen Mandanten.

**Achtung beim Einspielen:** Der öffentliche Zugang sucht den Schlüssel in
`sales_channel_secret_shop`. Bis zum Schema-Update (Anmeldung eines
Mitarbeiters) antwortet ein HugoShop mit `SHOP_NOT_AUTHORIZED`; das Protokoll
nennt den Mandanten.

**Getestet** in einer zurückgerollten Transaktion an Werkzeug24 (Upstall
zweimal) mit einem zweiten HugoShop („Zweitshop", +10 %): Brücke (Wert,
Geheimnis, geleertes Geheimnis); doppelter Shop-Schlüssel abgelehnt;
Schlüssel findet den Kanal; CORS und „geöffnet" je Kanal; Sitzung im Kanal
der Anfrage, fremde Sitzung im Cookie ergibt neue Kennung; Warenkorb nur mit
Artikeln des Kanals, Preis 17,94 → 19,73, „nicht angeboten" nur im Kanal, in
dem abgewählt; Anmelden zieht keinen Korb aus dem anderen Kanal; Rechnung mit
Kanalpreis und Kanal im Link; Rechnungsseite und Kaufauswertung nur im
eigenen Kanal; PayPal und Kontaktmail melden fehlende Einstellungen des
Zweitshops; Widerruf mit Kanal; Suche mit Sortiment und Adressmuster des
Kanals; Umleitung je Webseite; Standard-Shop unverändert. Nicht getestet:
echter Aufruf über die Webseite, PayPal, Mailversand.

### Schritt 3: Veröffentlichung je HugoShop (2026-10-01)

**Webseite eines HugoShops.** Alle Funktionen, die eine Webseite anfassen,
bekommen die Kennung des Kanals und lesen dessen Einstellungen: Verzeichnis
(`shopSiteDir`, `shopContentDir`), Betriebsart (`shopPublishMode`),
Seitendaten und Rendern (`shopPageData`, `shopRenderPage`,
`shopWriteProductPage` — Preis, Texte, Angebot, Freigrenze, Adressmuster und
Vorlagensatz des Kanals), Vorschaubild, Paket (`shopSyncKit`, `config.json`
mit `backend_url` und `public_key` des Kanals), Kategorieübersicht, Bau
(`shopPublishCommand`, `publish_clean_destination`), HugoCMS (Adresse,
Schlüssel, Übertragung, Vorschaubilder, Bau). Für den ganzen Mandanten
bleiben Wurzelverzeichnis aller Webseiten (`shop_sites_dir`), Bau-Programm
und Größe der Vorschaubilder. Ein leeres Webseiten-Verzeichnis heißt wie
bisher die Wurzel selbst — bei mehreren HugoShops braucht jeder ein eigenes.

**Aufträge.** `shopOpenJobs` liefert den Kanal des Auftrags mit, das
HugoShop-Modul schreibt und entfernt Seiten nur in dessen Webseite.
`shopQueueJob` nimmt die Kennung (int) oder, als Übergang, die Art (Text).
Schalten (`shopChannel<Art>Switched($db, $kanal, $an)`): beim HugoShop
`draft_all`/`remove_all`/`publish_all` nur für diesen Kanal, nach dessen
`channel_off_pages`; beim eBay-Kanal ebenso je Kanal.

**Lauf.** Nach den Aufträgen bearbeitet `shopPublishSite()` jeden HugoShop,
der eingeschaltet ist oder in diesem Lauf Aufträge hatte: Paket,
Kategorieübersicht, Bau bzw. Übertragung an HugoCMS. Die Zahlen je Webseite
sammelt `shopSiteTally()`. Ein Fehler bei einer Webseite hält die übrigen
nicht auf. Bei mehreren HugoShops beginnt jede Meldung mit `[Name]`. Die
Sperre bleibt gemeinsam (ein Lauf je Mandant).

**Bereitstellung und HugoCMS-Stand je Webseite**
(`backend/tmp/shop-publish-<db>-<kanal>-staging`, `…-<kanal>-hugocms.json`,
`shopSiteStateFile`). Die Dateien von vor Schritt 3 übernimmt beim ersten
Zugriff der älteste HugoShop (kleinste Kennung) — die Übertragung gleicht die
Bereitstellung als Abbild ab und entfernt bei HugoCMS, was fehlt; eine neue,
leere Bereitstellung räumte sonst die Webseite leer. Bei Werkzeug24 hat das
der erste echte Lauf am 2026-10-01 erledigt; der nächste meldete „HugoCMS ist
auf dem aktuellen Stand".

**Verwaltung.** Bis zur Kanalauswahl (Schritt 5) nehmen die Funktionen ein
optionales `channel_id`, ohne Angabe gilt der Standard-HugoShop
(`shopHugoshopOfRequest`): `previewShopPage`, `writeShopPage`,
`publishShopPart`, `publishShopAll`, `installShopUi`, `testShopHugoCms`,
`browseDirectories` (Basis `site`). Wird ein Artikel abgewählt oder aus dem
Shop genommen, entsteht je betroffenem HugoShop ein `remove_part`.
`getShopPublishJobs` liefert `channel_id` und `channel_name`.
`saveShopChannel` schaltet und veröffentlicht je Kanal (`auto_publish` des
Kanals). Bildübernahme (`shopChannelImageCopy`, `copyShopChannelImages`)
und Bilder je Kanal nehmen Kennung oder Art (`shopChannelParam`); Bilder
eines HugoShops werden von seiner Webseite gelesen bzw. auf sie geschrieben,
zwischen zwei HugoShops wird nichts kopiert (Bildnamen gemeinsam, M6).
`getShopStatus` prüft bis Schritt 5 die Webseite des Standard-HugoShops.

**Getestet** in einer zurückgerollten Transaktion an Werkzeug24 mit zwei
Webseiten in einem Testverzeichnis (lokal, ohne Bau): `publish_all` in beiden
— Artikel A in beiden, B nur im Zweitshop, Preis 17,94 / 19,73 (+10 %);
Paket je Webseite mit Schlüssel und Adresse des Kanals; Meldungen mit
`[Name]`; Abwählen im Zweitshop entfernt nur dort; Herausnehmen aus dem Shop
gibt den Auftrag je HugoShop; Abschalten des Zweitshops: `draft_all` nur dort,
Seiten nur dort als Entwurf, danach bleibt die abgeschaltete Webseite
unberührt; Vorschau je HugoShop, eBay-Kennung abgelehnt; Auftragsliste mit
Kanalname; Übernahme der alten Dateien nur durch den ältesten HugoShop;
Bereitstellung je Kanal. Nicht getestet: Bau mit Hugo und HugoCMS mit
mehreren Webseiten (ein echter Lauf mit einer Webseite lief fehlerfrei).

### Schritt 4: eBay je Kanal (2026-10-01)

Jeder eBay-Kanal ist ein eBay-Konto mit eigenem Zugang, eigenen Richtlinien,
eigenem Marktplatz und eigenem Bestellabruf. Alle Funktionen in
`channels/ebay.php` und `channels/ebay_orders.php` bekommen den Kanal.

**Einstellungen:** `shopEbayConfig($db, $kanal)` — die Einstellungen des
Kanals (Schlüssel ohne Präfix: `client_id`, `marketplace_id`,
`payment_policy_id` …) und seine Geheimnisse, dazu `public_host` aus
`ebay_public_host` (Adresse von OSERP für die Bilder, für den ganzen
Mandanten). Laufzeitwerte schreibt `shopEbaySetConfig($db, $kanal, …)` in den
Kanal: `access_token`, `access_token_exp` als Geheimnis, `order_last_check`
und `account_id` in `settings`; der Zwischenspeicher liest danach neu
(`shopChannelConfig(…, true)`). Die Laufzeitwerte stehen nicht mehr in
`shop_channel_setting_keys()` — die Abschrift beim Schema-Update überschriebe
sie sonst mit alten Werten aus `defaults_oserp`.

**Angebote:** Token, API-Aufruf, Einstellen, Beenden, Bilder, Abgleichsstand
und Fehler je Kanal: Preis, Verfügbarkeit, Kanalzeile, Bilder und
ausgeschlossene Lieferbedingungen des Kanals; Aufträge mit ihrem Kanal.

**Bestellungen:** `shopEbayImportOrders($db, $kanal)` mit eigenem letzten
Abruf; `ebay_orders.channel_id`; Sammelartikel und Mitarbeiter des Kanals.
Der Kunde wird über den eBay-Benutzernamen aus allen Kanälen gefunden (der
Name gilt bei eBay weltweit). `backend/cli/ebay-orders.php` geht je Mandant
alle eingeschalteten eBay-Kanäle durch (`shopEbayActiveChannels`); ein Fehler
in einem Kanal hält die übrigen nicht auf. `shopEbayStatus($db, $kanal)`:
Stand, Konto und Bestellungen je Kanal.

**M4 — ein Kanal je eBay-Konto:**

- Derselbe Refresh-Token in zwei eBay-Kanälen ist dasselbe Konto: der
  Tokenabruf bricht ab (`EBAY_ACCOUNT_IN_USE`), ohne eBay zu fragen.
- Nach jedem neuen Token bestimmt `shopEbayAccountCheck` das Konto — über die
  Identity-API, sonst über die Verkäuferkennung der letzten Bestellung — und
  merkt es in `settings.account_id`. Ein eindeutiger Index
  (`sales_channel_shop_ebay_account_key`) lässt dasselbe Konto in keinem
  zweiten eBay-Kanal zu; das eben geholte Token wird dann verworfen.
- **Grenze:** Die Identity-API braucht den Scope
  `commerce.identity.readonly`. Die Anbindung fordert ihn nicht an — ein
  vorhandenes Refresh-Token ohne ihn ließe sich sonst nicht mehr erneuern.
  Ohne ihn hilft erst die erste Bestellung; bis dahin schützt nur der
  Vergleich der Refresh-Tokens. „Verbindung prüfen" zeigt das erkannte Konto
  (`account`, leer = nicht feststellbar).

**Verwaltung:** `testShopEbay`, `syncShopEbayOrders`, `getShopEbayStatus`
nehmen ein optionales `channel_id`, ohne Angabe gilt der Standard-eBay-Kanal
(`shopChannelOfRequest`). `getShopStatus` prüft bis Schritt 5 den
Standard-eBay-Kanal.

**Getestet** in einer zurückgerollten Transaktion an Werkzeug24 mit zwei
eBay-Kanälen (22 Prüfungen), **ohne Aufruf bei eBay**: Einstellungen,
Umgebung und Geheimnisse je Kanal; Token und letzter Abruf je Kanal, Token
aus dem Zwischenspeicher; gleicher Refresh-Token und gleiches Konto
abgelehnt, anderes erlaubt; ausgeschlossene Lieferbedingung nur im eigenen
Kanal, Fehler an der eigenen Kanalzeile, Auftrag mit seinem Kanal;
nachgebildete Bestellung mit Kanal und dessen Sammelartikel, ohne
Sammelartikel abgelehnt; Stand je Kanal; Verwaltung mit und ohne
`channel_id`; Cron-Auswahl; abgeschalteter Kanal ohne Abruf; Reiter „Shop"
erreicht den Standard-eBay-Kanal, Laufzeitwerte bleiben. Nicht getestet:
Aufrufe bei eBay (Token, Identity-API, Angebote, Bestellabruf) — in
Werkzeug24 ist kein eBay-Konto eingerichtet.

### Schritt 5: Oberfläche (2026-10-01)

**Kanalkarte** (`shop-channels.config.vue`, Reiter „Shop" → Verkaufskanäle):

- Je Kanal: Name (Pflicht, eindeutig), Art als Kennzeichen, eingeschaltet,
  „Neue Shop-Artikel automatisch aufnehmen" (M3), Preisvorgaben, Freigrenze
  und Lieferländer bzw. ausgeschlossene Lieferbedingungen wie bisher.
- Aufklappbar „Einstellungen von …" (`shop-channel-settings.vue`, Felder in
  `shopChannelSettingsConfig.js`): beim HugoShop Shop-Schlüssel (erzeugen),
  erlaubte Herkunft, Adressmuster, Adresse von OSERP, Vorlagensatz,
  Betriebsart, Verzeichnisse (Auswahl in der Webseite dieses Kanals),
  automatisch veröffentlichen, Verhalten beim Abschalten, HugoCMS mit
  „Verbindung prüfen", Mails, PayPal mit beiden Zugangspaaren; beim eBay-Kanal
  Zugang, Richtlinien, Lagerort, Sammelartikel, Mitarbeiter und darunter
  Verbindungstest, Bestellabruf und Stand dieses Kanals. Gespeichert wird
  verzögert nach jeder Änderung (`saveShopChannelSettings`); Geheimnisse
  kommen nie vom Server, der Platzhalter sagt, ob etwas hinterlegt ist.
- Unter den Karten: neuen Kanal anlegen (Art, Name) — abgeschaltet, ohne
  automatische Aufnahme, mit den Vorgaben der Art
  (`shop_channel_default_settings`).
- Löschen (M5): nur abgeschaltet, ohne offene Aufträge und ohne Belege; der
  Knopf erscheint nur dann, das Backend prüft dasselbe.

**Reiter „Shop"** behält nur die Einstellungen für den ganzen Mandanten
(Sitzung, Rechnungsstellung, Lager, Versand, Bankverbindung, Wurzel der
Webseiten, Bau-Programm, Aufbewahrung, Größe der Vorschaubilder,
`ebay_public_host`, Suche). Die Instanz-Schlüssel sind nach der letzten
Abschrift aus `defaults_oserp` gelöscht, ebenso die Laufzeitwerte des
eBay-Kanals; der Übergangs-Trigger `defaults_oserp_shop_channel_sync` und
`shop_auto_publish_enabled()` ohne Kanal sind entfernt. Das Schema legt die
Instanz-Schlüssel nicht mehr an.

**Kanalname statt Art:** Artikelmaske (Kanäle, Bilder, Abgleichsstand;
Bildübernahme über Kennungen, nicht zwischen zwei HugoShops; Betriebsart
und Adressmuster aus dem HugoShop), Versandpreise, Kanalfilter der
Artikelsuche, Auftragsliste der Übersicht.

**Übersicht:** „Alle veröffentlichen" und „Shop-Benutzerschnittstelle
installieren" ohne Kanal gelten für alle eingeschalteten HugoShops (ein
Auftrag je Shop). Die Einrichtungsprüfung prüft jeden eingeschalteten
HugoShop und eBay-Kanal; bei mehreren HugoShops nennen die Details den Kanal.

**Werkzeug** `tools/shop-bridge-settings.php`: Einstellungen der Instanz gehen
in den HugoShop (Standard oder per Name als zweites Argument), Geheimnisse in
`sales_channel_secret_shop`, die Freigrenze in die Kanalspalte.

**Neue Fehlercodes** mit Text in 21 Sprachen (`ShopView.errors`):
`CHANNEL_NAME_TAKEN`, `CHANNEL_KEY_TAKEN`, `CHANNEL_IN_USE`,
`CHANNEL_NOT_DELETABLE`.

**Getestet** in einer zurückgerollten Transaktion an Werkzeug24 (27 Prüfungen):
Umzug — Instanz-Schlüssel und Übergangs-Trigger weg, Adresse, Shop-Schlüssel
und Betriebsart im HugoShop erhalten, Mandanten-Schlüssel bleiben; Kanal
anlegen mit Vorgaben, doppelter Name und Art ohne Modul abgelehnt; Name und
automatische Aufnahme speichern, Umbenennen auf vergebenen Namen abgelehnt;
Einstellungen speichern — unbekannte und fremde Schlüssel verworfen, leeres
Geheimnis bleibt, fremder Shop-Schlüssel abgelehnt, `auto_publish` wirkt;
Kanalliste ohne Geheimnisse, mit „hinterlegt" und „löschbar"; Artikelkarte
und Artikelsuche mit Namen; „Alle veröffentlichen" je HugoShop; Prüfung mit
zwei HugoShops; Löschen nur abgeschaltet, ohne Aufträge und Belege.
Vue-Dateien mit dem Vue-Compiler übersetzt. Nicht getestet: die Oberfläche
im Browser.

**Blieb für Schritt 6:** die Fassungen der SQL-Funktionen mit der Art und
die Spaltenvorgaben — siehe dort.

### Schritt 6: Übergang entfernt (2026-10-01)

**Entscheidung (2026-10-01):** Die Bridge wird mit der Shop-Erweiterung nicht
verwendet; Rücksicht auf sie ist nicht nötig. Die noch laufenden produktiven
Aufgaben der Bridge arbeiten bis zu ihrer Umstellung losgelöst mit der alten
Datenbank weiter.

- **Fassungen mit der Art entfernt:** `shop_channel_id(text)`,
  `shop_active_channel_id(text)`, `shop_channel_price(integer, text)`,
  `shop_part_available(integer, text)`, `shop_queue_job(…, text)` und
  `shop_channel_type_check(text)` werden beim Schema-Update gelöscht. Damit
  ist auch die Falle mit untypisierten Platzhaltern (gleicher Name, falsche
  Fassung) weg.
- **Erster Kanal einer Art:** `shop_first_channel_id(art)` (PHP:
  `shopFirstChannelId`) — eigener Name, keine Überladung; nur für Stellen, die
  bewusst irgendeinen Kanal der Art meinen: Übernahmen im Schema,
  Verwaltungsaufrufe ohne `channel_id`, `savePartShopData` ohne Kanalliste,
  den Zahlungsabgleich (`--reconcile-payments` legt den Auftrag beim ersten
  HugoShop an; abgeglichen werden alle).
- **Keine Spaltenvorgaben mehr:** `channel_id` in Warenkorb, Sitzung,
  Weiterleitungen, Aufträgen, Rechnungslinks, Widerrufen und eBay-Bestellungen
  ist Pflicht ohne Vorgabe — wer schreibt, nennt den Kanal. Alte Zeilen ohne
  Kanal bekommen beim Schema-Update den ersten Kanal ihrer Art.
- `shopQueueJob($db, $funktion, $nummer, $param, int $kanal)`: der Kanal ist
  Pflicht.
- `shop_cart_shipping` nimmt den Kanal nur noch aus dem Warenkorb.
- Die Trigger auf `parts_ext` bleiben: sie sind die automatische Aufnahme
  neuer Shop-Artikel (M3), nicht mehr ein Übergang für die Bridge.
- Kommentare, die eine gemeinsame Nutzung der Warteschlange mit der Bridge
  voraussetzten, sind berichtigt; der Filter auf die Auftragsarten der
  Kanalmodule bleibt.
- `dev/shop-verkaufskanaele.md`: V3 und V3a als aufgehoben vermerkt.

**Getestet** in einer zurückgerollten Transaktion an Werkzeug24 (Upstall
zweimal): keine Fassung mit der Art und keine Spaltenvorgabe mehr; ein
Auftrag ohne Kanal wird abgelehnt; `shopFirstChannelId` für HugoShop, eBay
und eine Art ohne Kanal; Auftrag mit Kennung; Preisänderung legt den Auftrag
im HugoShop an; `publishShopAll`, `getPartShopData`, `savePartShopData` ohne
Kanalliste, `getShopStatus`, `getShopChannels`, `getShopPublishJobs`;
öffentlicher Zugang: Sitzung, Warenkorb mit Versand, Suche.

### Nachtrag: eigene Ansicht „Verkaufskanäle" (2026-10-01)

Die Kanalkarte steht nicht mehr in der Firmenkonfiguration, sondern in einer
eigenen Ansicht mit Menüpunkt: Hauptmenü → Shop → Verkaufskanäle (Route
`shop-channels`, `src/features/shop/views/shop.channels.vue`; Pfade
`ShopView.routes.shopChannels` in 21 Sprachen, z. B. `/shop/verkaufskanaele`,
`/shop/sales-channels`).

- Menüpunkt und Kachel „Verkaufskanäle" in der Shop-Übersicht nur mit dem
  Recht `edit_shop_config` — dasselbe verlangt die Kanal-API. Ohne das Recht
  zeigt die Ansicht einen Hinweis.
- Die Ansicht bettet `shop-channels.config.vue` ohne eigene Überschrift ein
  (`mitUeberschrift`); ob Verkaufspreise brutto oder netto stehen, liest die
  Karte aus `getShopChannels`.
- Im Reiter „Shop" steht an der alten Stelle ein Hinweis mit Knopf zur
  Ansicht. Der Einrichtungshinweis der Übersicht nennt beide Orte.
- `npm run check:routes`: die neue Route besteht alle Kreuzproben. Die Prüfung
  meldet weiter einen Fehlalarm, der schon vorher bestand — die Felddefinition
  `{ name: 'condition' }` in `part-shop.card.vue` hält sie für einen
  Routennamen.
