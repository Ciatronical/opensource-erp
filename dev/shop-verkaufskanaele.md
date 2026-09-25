# Shop: Verkaufskanäle

Stand 2026-09-25. Status: **Schritte 1 bis 5 umgesetzt**, dazu die
Lagerbuchung bei Verkäufen (V28). Entschieden sind V1 bis V28. Was noch fehlt
— offene Entscheidungen O15 bis O25, Aufgaben, Prüfungen vor der
Inbetriebnahme und die Einrichtung — steht gesammelt unter „Offener Stand
nach Schritt 5“.

Ein Artikel, für den „Im Shop anbieten" aktiviert ist, wird über einen oder
mehrere Verkaufskanäle angeboten. Der erste Kanal ist der HugoShop
(`dev/shop-hugocms-trennung.md`), später folgen eBay und Amazon über deren
Schnittstellen. Für jeden Kanal sind je Artikel eigene Einstellungen möglich:
Preisaufschlag, Titel, Beschreibung und kanaleigene Angaben.

Verwandte Dokumente: `dev/shop-veroeffentlichung.md` (Seitenerzeugung),
`dev/shop-hugocms-trennung.md` (Aufteilung OSERP/HugoCMS),
`dev/shop-betrieb.md` (Betrieb), `dev/rundungsfehler.txt`.

## Entscheidungen

| Nr. | Entscheidung | Datum |
| --- | --- | --- |
| V1 | Eigene Tabellen der Erweiterung, angelegt über `backend/upstall/shop/company_schema.sql`. Die kivitendo-Tabellen `shops`/`shop_parts` werden weder benutzt noch verändert | 2026-09-25 |
| V2 | Grundpreis ist `parts.sellprice`. Je Kanal ein Aufschlag in Prozent oder als fester Betrag, wahlweise mit Rundung auf ,99. Der Aufschlag gilt auf den Netto- oder Bruttopreis, je nachdem, was `sellprice` laut `shop_tax_included` enthält | 2026-09-25 |
| V3 | Je Mandant höchstens ein Kanal je Art: ein HugoShop, ein eBay-Zugang, ein Amazon-Zugang | 2026-09-25 |
| V4 | Ein gemeinsamer Lagerbestand (`parts.onhand`) für alle Kanäle, keine Kontingente | 2026-09-25 |
| V8 | Mindestens ein Kanal bleibt eingeschaltet. Der HugoShop lässt sich abschalten, sobald ein weiterer Kanal (etwa eBay) eingeschaltet ist; solange es nur den HugoShop gibt, ist er gesperrt. Ein abgeschalteter Kanal bietet nichts an, seine Artikelzeilen bleiben für das Wiedereinschalten stehen. (Erste Fassung „HugoShop nie abschaltbar" am selben Tag ersetzt) | 2026-09-25 |
| V10 | Ist „Im Shop anbieten" eingeschaltet und hat der Benutzer noch keinen Kanal gewählt, ist der HugoShop vorgewählt — ist er abgeschaltet, der erste eingeschaltete Kanal | 2026-09-25 |
| V11 | Ein Artikel kann in mehreren Kanälen zugleich angeboten werden | 2026-09-25 |
| V12 | Jeder Kanal hat seine eigene Bilderverwaltung. Bilder aus einem anderen Kanal lassen sich übernehmen (O3b) | 2026-09-25 |
| V13 | Die Kanäle arbeiten unabhängig voneinander: Ausfall, Fehler oder Abschalten eines Kanals berührt die anderen nicht | 2026-09-25 |
| V14 | Alle Verkaufskanäle setzen die Shop-Erweiterung voraus (O3a) | 2026-09-25 |
| V15 | Die bisherige eBay-Anbindung im Kern wird durch den eBay-Kanal ersetzt, mit allen ihren Funktionen — Angebote, Bilder, Bestellimport samt Kundenzuordnung, Rechnung und Buchung, Cron-Abruf, Verbindungstest und Statusanzeige (O3c, O3d) | 2026-09-25 |
| V16 | (O8, geändert 2026-09-25) Beim Abschalten des HugoShops bleiben die Produktseiten wahlweise als Entwurf stehen (`draft: true`, Vorgabe) oder werden entfernt — Einstellung `shop_channel_off_pages`. Der öffentliche Zugang antwortet auf Warenkorb und Bestellung mit „Shop geschlossen". Beim Einschalten entsteht `publish_all` | 2026-09-25 |
| V17 | (O9) Bildübernahme HugoShop → eBay über `shop_images_link`; eBay → HugoShop in der Betriebsart „lokal" über `shop_images_dir`. In der Betriebsart „HugoCMS" wird diese Richtung nicht angeboten, bis HugoCMS eine Upload-Schnittstelle hat | 2026-09-25 |
| V18 | (O10) Die alte eBay-Anbindung wird im selben Schritt entfernt. Die Firmenkonfiguration weist darauf hin, solange `ebay_enabled` gesetzt, die Shop-Erweiterung aber nicht aktiv ist. `getShopStatus` prüft die HugoShop-Punkte nur bei eingeschaltetem HugoShop | 2026-09-25 |
| V19 | (O11) eBay-Menge = `parts.onhand`, abgerundet, nicht negativ. Bei 0 bleibt das Angebot als „ausverkauft" stehen (Out-of-Stock Control) statt beendet zu werden. Dienstleistungen werden nicht über eBay angeboten | 2026-09-25 |
| V20 | (O12) Beim Bau des eBay-Kanals wird geprüft, wie die Faktura den Lagerbestand bucht; der Bestellimport bucht ihn genauso | 2026-09-25 |
| V21 | (O13) `ebay_listings` und `ebay_part_images` werden in `parts_channel_shop` und die Bildverwaltung je Kanal übernommen; die alten Tabellen bleiben stehen, bis alle Mandanten übernommen sind, und werden dann in einem eigenen Schritt entfernt. `ebay_orders` bleibt unverändert | 2026-09-25 |
| V22 | (O1) Ändert sich, was den Preis oder den Text einer Produktseite bestimmt, entsteht automatisch der Auftrag zur Veröffentlichung. Abschaltbar über `shop_auto_publish` (Vorgabe: an) | 2026-09-25 |
| V23 | (O2) Der Warenkorb nimmt nur Artikel an, die der eingeschaltete HugoShop anbietet. Nicht mehr angebotene Artikel in bestehenden Warenkörben verhindern Kauf auf Rechnung und PayPal-Zahlung, bis sie entfernt sind | 2026-09-25 |
| V24 | (O4) Warenkorb und Faktura (Belegansicht, neue Position, Buchung von Ausgangs- und Eingangsrechnungen) nehmen nur Steuerschlüssel, die am Belegdatum gelten — wie Produktseite und `shop_tax_rate()`. Änderung am Kern, freigegeben | 2026-09-25 |
| V25 | (O5) Der Grenzfall der Bridge (gelöschte und neu angelegte `parts_ext`-Zeile) wird nicht eigens behandelt; er erledigt sich mit dem Lieferantenimport in OSERP (Stufe F), der Kanalzeilen ausdrücklich schreibt. Danach werden die Trigger aus V7 entfernt | 2026-09-25 |
| V26 | (O6) Abschalten eines Marktplatz-Kanals (eBay, Amazon) beendet dessen laufende Angebote über die Warteschlange; Wiedereinschalten stellt sie neu ein. Umsetzung mit dem jeweiligen Kanal | 2026-09-25 |
| V27 | (O7) Amazon folgt nach eBay. Vorher wird geklärt, ob ein Amazon-Verkäuferkonto mit API-Zugang (SP-API, Registrierung als Entwickler) besteht | 2026-09-25 |
| V28 | (O14) Rechnungen aus HugoShop und eBay buchen je Warenposition eine Ausbuchung vom Lagerplatz aus `shop_stock_bin_id`. Ohne Lagerplatz keine Buchung, mit Hinweis in der Einrichtungsprüfung. Die Faktura im Kern bleibt unberührt | 2026-09-25 |

Daraus abgeleitete Festlegungen (unten begründet):

| Nr. | Festlegung |
| --- | --- |
| V2a | Der Aufschlag des Kanals ist die Vorgabe; je Artikel und Kanal kann er überschrieben werden (eigener Aufschlag oder „ohne Aufschlag") — so war die Anforderung „für jeden Artikel" gestellt |
| V2b | Gerundet wird immer der **Bruttopreis**, den der Kunde sieht: aufrunden auf die nächste ,99. Ein Preis, der schon auf ,99 endet, bleibt; nie unter dem berechneten Preis (12,00 → 12,99; 12,30 → 12,99; 12,99 → 12,99) |
| V2c | Eine Rechnungsposition mit Aufschlag trägt eine leere Preisquelle (`active_price_source = ''`, in kivitendo „manuell"), sonst weiter `shop_active_price_source` |
| V2d | Bei `shop_tax_included = 0` wird der Nettopreis aus dem gerundeten Bruttopreis zurückgerechnet und mit fünf Nachkommastellen gespeichert. Für Kanäle mit Rundung auf ,99 wird Brutto in den Stammdaten empfohlen; `getShopStatus()` weist darauf hin |
| V3a | Die HugoShop-Einstellungen bleiben als `shop_*`-Schlüssel in `defaults_oserp`; die Kanaltabelle hält nur Kanal-Angaben (aktiv, Aufschlag, Rundung) |
| V5 | Abwählen eines Kanals für einen Artikel setzt `active = false` statt zu löschen; Titel, Beschreibung und Aufschlag bleiben erhalten |
| V6 | Jede vorhandene `parts_ext`-Zeile erhält einmalig eine aktive HugoShop-Kanalzeile ohne eigenen Aufschlag; ein Merker in `defaults_oserp` (`shop_channels_migrated`) verhindert eine Wiederholung bei späteren Schema-Updates |
| V7 | Übergang für Schreiber, die nur `parts_ext` kennen (Lieferantenimport der Bridge bis Stufe F): Trigger auf `parts_ext` legen beim Anlegen eine aktive HugoShop-Zeile an, sofern es keine gibt, und schalten sie beim Löschen ab. Eine vorhandene Kanalzeile — auch eine abgeschaltete — hat Vorrang |
| V9 | Ändert sich am HugoShop-Kanal, was den Preis bestimmt (Aufschlagsart, Wert, Rundung), entsteht der Auftrag, alle Produktseiten neu zu schreiben (`publish_all`). Die Seiten tragen den Preis fest im Inhalt |

## Ausgangslage

**Angebotsstatus.** Ob ein Artikel im Shop steht, entscheidet heute allein
die Existenz seiner Zeile in `parts_ext`. Der Schalter „Im Shop anbieten" in
`src/features/shop/components/part-shop.card.vue` legt sie an oder löscht sie.
Suche, Kategorien und Veröffentlichung verknüpfen per `JOIN parts_ext`:

- `backend/api/shop/lib/search.php`
- `backend/api/shop/lib/publish.php`
- `backend/api/shop/lib/hugocms.php`
- `backend/api/shop/lib/categories.php`

**Preis.** Überall fest `p.sellprice`:

| Stelle | Datei |
| --- | --- |
| Warenkorb, Summen, Versand | `lib/cart.php` |
| Rechnungspositionen | `lib/invoice.php` (Preisquelle aus `shop_active_price_source`) |
| Produktseiten | `lib/publish.php` (netto/brutto nach `shop_tax_included`) |
| Suche und Ranking | `lib/search.php` |
| Auswertung | `lib/analytics.php` |

**Befund Warenkorb.** `lib/cart.php` rechnet den Bruttobetrag immer als
`sellprice × (1 + Steuersatz)`, auch bei `shop_tax_included = 1`. Seitenerzeugung
und Rechnung (`ar.taxincluded`) beachten die Einstellung. Bei Bruttopreisen in
den Stammdaten würde der Warenkorb die Steuer doppelt aufschlagen. Das
behebt Schritt 1 nebenbei: Warenkorb, Seite und Rechnung lesen dann denselben
Preis aus derselben Funktion.

**Beschreibung.** Unverändert aus den Stammdaten (`p.description`, `p.notes`).

**Warteschlange.** `batchjob_hugoshop` kennt nur Aufträge für Hugo.

## Datenmodell

Neue Tabellen in `backend/upstall/shop/company_schema.sql`. Der Upstall-Parser
verarbeitet alle `CREATE TABLE` vorab — `sales_channel_shop` muss deshalb vor
`parts_channel_shop` stehen.

```sql
-- Kanal: höchstens einer je Art (V3)
CREATE TABLE IF NOT EXISTS sales_channel_shop
(
    id           integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    type         text NOT NULL,                 -- 'hugoshop' | 'ebay' | 'amazon'
    active       boolean NOT NULL DEFAULT true,
    sortkey      integer,
    markup_type  text NOT NULL DEFAULT 'none',  -- 'none' | 'percent' | 'amount'
    markup_value numeric(15,5) NOT NULL DEFAULT 0,
    round_99     boolean NOT NULL DEFAULT false,
    settings     jsonb,                         -- kanaleigene Einstellungen, ohne Geheimnisse
    itime        timestamp without time zone DEFAULT now(),
    mtime        timestamp without time zone,
    CONSTRAINT sales_channel_shop_type_key UNIQUE (type),
    CONSTRAINT sales_channel_shop_type_check CHECK (type IN ('hugoshop', 'ebay', 'amazon')),
    CONSTRAINT sales_channel_shop_markup_check CHECK (markup_type IN ('none', 'percent', 'amount'))
);

-- Artikel je Kanal
CREATE TABLE IF NOT EXISTS parts_channel_shop
(
    id           integer NOT NULL GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    parts_id     integer NOT NULL,
    channel_id   integer NOT NULL,
    active       boolean NOT NULL DEFAULT true,
    markup_type  text,                          -- NULL = Vorgabe des Kanals (V2a)
    markup_value numeric(15,5),
    title        text,                          -- NULL = parts.description
    description  text,                          -- NULL = parts.notes
    settings     jsonb,                         -- eBay-Kategorie, Zustand, ASIN ...
    external_id  text,                          -- eBay-Angebots-ID, Amazon-SKU/ASIN
    sync_status  text,
    sync_mtime   timestamp without time zone,
    sync_error   text,
    itime        timestamp without time zone DEFAULT now(),
    mtime        timestamp without time zone,
    CONSTRAINT parts_channel_shop_key UNIQUE (parts_id, channel_id),
    CONSTRAINT parts_channel_shop_parts_id_fk FOREIGN KEY (parts_id)
        REFERENCES parts (id) ON DELETE CASCADE,
    CONSTRAINT parts_channel_shop_channel_id_fk FOREIGN KEY (channel_id)
        REFERENCES sales_channel_shop (id) ON DELETE CASCADE
);

-- Der HugoShop existiert immer
INSERT INTO sales_channel_shop (type, sortkey) VALUES ('hugoshop', 1)
    ON CONFLICT (type) DO NOTHING;
```

Die Namen folgen dem Muster der übrigen Erweiterungstabellen. Geheimnisse der
Kanäle (OAuth-Tokens, Schlüssel) gehören nicht in `settings`, das an die
Oberfläche geht, sondern — wie heute `shop_hugocms_key` — in
`defaults_oserp`-Schlüssel, die `oserp_config/defaults.php` nie ausliefert.

**Bedeutung von „Im Shop anbieten".** Der Schalter ist an, wenn der Artikel
mindestens eine aktive Kanalzeile hat. Ob er im HugoShop steht, entscheidet
künftig die aktive Kanalzeile des HugoShops, nicht mehr die `parts_ext`-Zeile.
Abwählen eines Kanals setzt `active = false` statt zu löschen — Titel,
Beschreibung und Aufschlag bleiben für ein späteres Wiedereinschalten erhalten.

`parts_ext` bleibt der HugoShop-Inhalt (Breadcrumbs, Bilder, technische Daten,
Downloads). Bilder und technische Daten brauchen eBay und Amazon ebenfalls;
sie werden über die öffentlichen Bildadressen (`shop_images_link`)
mitbenutzt — die Medien bleiben nach E6 der HugoCMS-Trennung auf der
Webseite.

**Übernahme des Bestands.** Jede vorhandene `parts_ext`-Zeile erhält eine
aktive HugoShop-Kanalzeile ohne eigenen Aufschlag — Preise und Sortiment
bleiben unverändert. Die Datei läuft bei jedem Schema-Update; die Übernahme
darf deshalb nur einmal wirken, sonst kämen abgewählte Artikel zurück.
Absicherung über einen Merker in `defaults_oserp`
(`shop_channels_migrated`), der nach der Übernahme gesetzt wird.

## Preis

Die Berechnung liegt in der Datenbank, als SQL-Funktion
`shop_channel_price(parts_id, channel_id, taxzone_id)`. Sie liefert
Nettopreis, Bruttopreis, Steuersatz und Preisquelle; alle Stellen aus der
Tabelle „Preis" oben lesen nur noch daraus.

**Ablauf:**

1. Grundpreis ist `parts.sellprice` — netto oder brutto laut
   `shop_tax_included`.
2. Aufschlag: der Artikelwert des Kanals, sonst die Vorgabe des Kanals (V2a).
   - Prozent: `sellprice × (1 + Wert / 100)`
   - Betrag: `sellprice + Wert` — der Betrag gilt in derselben Art wie
     `sellprice`, also netto oder brutto.
3. Brutto und netto ableiten mit dem Steuersatz (Buchungsgruppe → Steuerzone →
   Erlöskonto → Steuerschlüssel, wie in `publish.php`).
4. Bei `round_99`: Bruttopreis auf die nächste ,99 aufrunden (V2b),
   `CEIL(brutto + 0.01) - 0.01`, danach den Nettopreis daraus zurückrechnen.

**Warum immer brutto gerundet wird (V2b).** Der Kunde sieht im HugoShop den
Bruttopreis, eBay und Amazon arbeiten ausschließlich mit Bruttopreisen. Ein
auf ,99 gerundeter Nettopreis ergäbe krumme Bruttopreise (10,99 netto =
13,08 brutto) und verfehlte den Zweck.

**Folge bei `shop_tax_included = 0`.** Der zurückgerechnete Nettopreis hat
mehr als zwei Nachkommastellen (12,99 / 1,19 = 10,91597). Er wird mit fünf
Stellen gespeichert (`invoice.sellprice` ist `numeric(15,5)`). Die Zeile ergibt
damit wieder 12,99 brutto; bei großen Mengen oder mehreren Positionen kann die
Rechnungssumme um einen Cent vom Warenkorb abweichen
(`dev/rundungsfehler.txt`). Mit `shop_tax_included = 1` tritt das nicht auf:
Rechnung (`ar.taxincluded`) und Warenkorb rechnen dann mit dem gerundeten
Bruttopreis selbst. Für Kanäle mit Rundung auf ,99 ist Brutto in den
Stammdaten deshalb die empfohlene Einstellung; das Admin-Panel weist in
`getShopStatus()` darauf hin.

**Steuerzone.** Gerundet wird mit dem Steuersatz der Standard-Steuerzone
(`shop_standard_taxzone`). Kunden aus anderen Steuerzonen (z.B. EU mit
USt-IdNr.) erhalten den daraus abgeleiteten Nettopreis — ,99 gilt für den
Regelfall, nicht für jede Zone.

**Preisquelle (V2c).** Weicht der Kanalpreis vom Grundpreis ab, trägt die
Rechnungsposition eine leere Preisquelle. `master_data/sellprice` wäre in
kivitendo irreführend, weil Stammdatenpreis und Positionspreis dann nicht
übereinstimmen.

## Bestand (V4)

Alle Kanäle lesen `parts.onhand`. Nach jeder Bestandsänderung, die einen
Artikel mit aktiver eBay- oder Amazon-Kanalzeile betrifft, entsteht ein
Abgleichauftrag in der Warteschlange. Der HugoShop braucht keinen Auftrag, er
fragt den Bestand beim Bestellen ab. Eine Bestellung aus einem Marktplatz
bucht auf denselben Bestand und löst so den Abgleich der übrigen Kanäle aus.

## Oberfläche

### Artikelkarte (`part-shop.card.vue`)

- Unter „Im Shop anbieten" die aktiven Kanäle als Chips. Die Kanäle kommen
  einmal in den `oserpStore`; ist nur der HugoShop vorhanden, ist er
  vorgewählt und die Auswahl entfällt.
- Je gewähltem Kanal ein aufklappbarer Bereich:
  - Aufschlag: „Vorgabe des Kanals", „kein Aufschlag", Prozent oder Betrag;
    daneben der Endpreis netto und brutto (in Vue berechnet, als Vorschau —
    maßgeblich ist die SQL-Funktion)
  - Titel und Beschreibung; leer = Stammdaten, als Platzhalter angezeigt
  - kanaleigene Felder, als Feldliste je Kanaltyp beschrieben wie in
    `src/core/views/config/tabs/shopDefaultsConfig.js`
  - beim HugoShop die bisherigen Felder aus `parts_ext`
- Speichern bleibt ein Aufruf: `savePartShopData` erhält ein Feld `channels`
  und schreibt alles mit einer SQL-Anweisung (Upsert in einem CTE).
  `saveFor()` für die Neuanlage bleibt erhalten.

### Firmenkonfiguration

Abschnitt „Verkaufskanäle" im Reiter Shop (umgesetzt in Schritt 3): je
Kanalart eine Karte mit Aktiv-Schalter, Aufschlagsart, Aufschlagswert und
Rundung auf ,99. eBay und Amazon erscheinen erst, wenn ihr Kanal umgesetzt
ist; wo ihre Zugangsdaten stehen, hängt an O3.

## Backend-Aufbau

```
backend/api/shop/channels/
    hugoshop.php   -- bestehende Veröffentlichung
    ebay.php       -- später
    amazon.php     -- später
```

Jeder Kanal bietet dieselben Funktionen: Artikel veröffentlichen,
zurückziehen, Bestand abgleichen, Bestellungen abholen.
`batchjob_hugoshop` gehört der Erweiterung und erhält eine Spalte
`channel_id`; der Läufer (`tools/shop-publish.php`) verteilt die Aufträge an
den jeweiligen Kanal.

### Unterschiede der Marktplätze zum HugoShop

| Thema | HugoShop | eBay / Amazon |
| --- | --- | --- |
| Bestand | Abfrage beim Bestellen | Abgleich per Auftrag, sonst Überverkauf |
| Bestellungen | entstehen im eigenen Warenkorb | werden abgeholt (eBay Fulfillment API, Amazon SP-API Orders) und als Rechnung angelegt; `shop_orders`/`shop_order_items` von kivitendo eignen sich als Zwischenablage (nur lesen und schreiben, Schema unverändert) |
| Zahlung | PayPal, Überweisung | wickelt die Plattform ab; eigene Buchung von Zahlungseingang und Gebühren |
| Zugang | Shop-Schlüssel | OAuth mit erneuerbaren Tokens, Aufrufgrenzen |
| Angebot | Produktseite aus Hugo | eBay Sell Inventory API (Inventory Item, Offer, Publish); Amazon Listings Items API mit produkttypabhängigen Pflichtfeldern |
| Artikelkennung | Artikelnummer, Produktseite | SKU = Artikelnummer; `external_id` für Angebots-ID bzw. ASIN |

## Umsetzung Schritt 1

**Datenbank** (`backend/upstall/shop/company_schema.sql`, Abschnitt
„Verkaufskanäle"):

| Objekt | Zweck |
| --- | --- |
| `sales_channel_shop`, `parts_channel_shop` | Tabellen wie oben; HugoShop-Zeile wird immer angelegt |
| `shop_channel_id(type)` | Kennung eines Kanals über seine Art, für die Verknüpfungen in den Abfragen |
| `shop_tax_rate(buchungsgruppen_id, taxzone)` | Steuersatz wie auf der Produktseite, nur bereits gültige Schlüssel |
| `shop_channel_price(parts_id, type)` | Kanalpreis in der Art von `sellprice` (V2, V2a, V2b, V2d). Ohne Kanalzeile kein Aufschlag — der Versandartikel behält seinen Preis |
| Übernahme mit Merker | V6 |
| `trigger_parts_ext_channel_insert`, `trigger_parts_ext_channel_delete` | V7 |

Keine Sicht: der Upstall überspringt eine vorhandene Sicht, spätere
Änderungen kämen nie an. Funktionen werden bei jedem Lauf ersetzt.

**Backend:**

| Datei | Änderung |
| --- | --- |
| `lib/cart.php` | Preis aus `shop_channel_price()`; Steuer nur bei Nettopreisen aufschlagen (Befund Warenkorb behoben, auch beim Versand); Bezeichnung aus dem Kanal |
| `lib/invoice.php` | Positionspreis, Bezeichnung und Langtext aus dem Kanal; leere Preisquelle bei abweichendem Preis (V2c) |
| `lib/publish.php` | Seitenpreis, Bezeichnung und Beschreibung aus dem Kanal; `listed` und `shopListedParts()` über die aktive Kanalzeile |
| `lib/search.php` | Nur Artikel mit aktiver Kanalzeile; Kanaltitel durchsucht; Gewichtung mit dem Kanalpreis |
| `lib/analytics.php` | Kanalpreis und -titel |
| `lib/categories.php`, `lib/hugocms.php` | Kategorien und Vorschaubilder nur für angebotene Artikel |
| `admin.php` | Kennzahl über aktive Kanalzeilen; `savePartShopData` schaltet die Kanalzeile mit ein; `deletePartShopData` schaltet sie ab und lässt `parts_ext` stehen (V5) |

Die Schnittstelle zur Artikelkarte ist unverändert; `listed` hat dieselbe
Bedeutung wie bisher.

**Geprüft** in einer eigenen PostgreSQL-Testinstanz mit nachgebildeten
kivitendo-Tabellen: Preisfunktion (netto/brutto, Prozent, Betrag, Artikelwert,
„ohne Aufschlag", ,99, künftiger Steuersatz), zweifacher Upstall-Lauf,
Warenkorb, Mengenänderung, Rechnungspositionen, Produktseite, Suche,
Auswertung, Abwählen und Wiedereinschalten, Trigger samt Löschen eines
Artikels. Nicht geprüft: gegen einen echten Mandanten, Buchung und PayPal.

**Beobachtungen:**

- Warenkorb und Buchung (`postArInvoiceToLedger` in `faktura.php`) nehmen
  den neuesten Steuerschlüssel eines Kontos, auch einen künftigen;
  Produktseite und `shop_tax_rate()` nur bereits gültige. Solange keine
  künftigen Schlüssel eingetragen sind, ist das gleich. Mit einem
  vorab eingetragenen Satzwechsel ergäbe ein auf ,99 gerundeter Nettopreis im
  Warenkorb einen anderen Bruttobetrag als auf der Seite. Die Buchung gehört
  zum Kern und bleibt unverändert; bei Bedarf gemeinsam angleichen.
- V7, Grenzfall: Löscht die Bridge eine `parts_ext`-Zeile und legt sie
  später neu an, bleibt die HugoShop-Zeile abgeschaltet. Ob `run.php` so
  vorgeht, ist nicht geprüft — das Skript liegt nicht in diesem Repository.
- Der Hinweis aus V2d in `getShopStatus()` ist mit Schritt 3 umgesetzt
  (Empfehlung `channel_round_99_net`).
- Zu den beiden ersten Punkten siehe O4 und O5 unter „Probleme und anstehende
  Entscheidungen".

## Umsetzung Schritt 2

**Artikelkarte** (`src/features/shop/components/part-shop.card.vue`):

- „Im Shop anbieten" heißt jetzt: in mindestens einem Kanal aktiv. Beim
  Einschalten wird der HugoShop gewählt; die Chip-Auswahl der Kanäle erscheint
  erst ab zwei eingeschalteten Kanälen und lässt sich nicht leeren.
- Je gewähltem Kanal ein aufklappbarer Bereich: Aufschlag („Vorgabe des
  Kanals" mit ihrem Wert, „Kein Aufschlag", Prozent, fester Betrag), Vorschau
  von Grund- und Kanalpreis netto/brutto, Beschreibung und Langbeschreibung
  im Kanal mit den Stammdaten als Platzhalter.
- Die Vorschau rechnet wie `shop_channel_price()`, in Cent, mit Verkaufspreis
  und Buchungsgruppe aus der Maske (neue Props `sellprice`,
  `buchungsgruppen-id`, `description`, `notes` in
  `article.edit.view.vue`). Maßgeblich bleibt der Preis aus der Datenbank.
- „Veröffentlichen" erscheint nur, wenn der HugoShop gewählt ist.
- Die bisherigen Shop-Angaben (Kategorie, Produktseite, Bilder …) stehen
  unverändert darunter.

**Admin-API** (`backend/api/shop/admin.php`):

| Aktion | Änderung |
| --- | --- |
| `getPartShopData` | Neue Antwortform `{part, channels, tax_rates}` in einer Abfrage; auch ohne Artikel (Neuanlage) mit Kanälen und Steuersätzen. `listed` = in mindestens einem Kanal aktiv |
| `savePartShopData` | Nimmt `channels` (channel_id, active, markup_type, markup_value, title, description) und schreibt sie zusammen mit `parts_ext` in einer Anweisung. Ohne `channels` wie bisher: nur HugoShop einschalten, gepflegte Werte bleiben. Unbekannte Aufschlagsarten werden verworfen, doppelte Kanäle zusammengefasst. Wird der HugoShop abgewählt, entsteht der Auftrag zum Entfernen der Seite |
| `deletePartShopData` | Schaltet alle Kanäle ab; Auftrag zum Entfernen der Seite nur, wenn der HugoShop aktiv war |
| `shopQueueRemovePage()` | Neue Hilfsfunktion für diesen Auftrag, von beiden genutzt |

**Übersetzungen:** neue Schlüssel unter `ShopView.partCard` und
`ShopView.channels` in allen 21 Sprachen.

**Geprüft:** Laden, Speichern, Abwählen und Herausnehmen gegen die
Testinstanz (mit zusätzlichem eBay-Kanal); Build; Preisvorschau mit Node
gegen die Werte der SQL-Funktion. Nicht geprüft: Bedienung im Browser.

### Befund: vorhandene eBay-Anbindung im Kern

Unabhängig von der Shop-Erweiterung gibt es bereits eine eBay-Anbindung:
`backend/api/ebay/` (Angebote über die Inventory API, Bestellimport, OAuth),
Tabelle `ebay_listings` im CRM-Schema, Schalter `ebay_listing_enabled` und
eine eigene Karte „eBay-Artikel" in der Artikelmaske mit Bildern unter
`data/<db>/parts/<id>/`. Sie weicht von den Entscheidungen ab:

- Preis ist `parts.sellprice` ohne Aufschlag (statt `shop_channel_price()`).
- Menge ist der feste Wert `ebay_listing_quantity` (statt des gemeinsamen
  Bestands, V4).
- Titel und Beschreibung sind die Stammdaten (statt der Kanaltexte).

Für Schritt 5 heißt das: die vorhandene Anbindung in das Kanalmodell
überführen, nicht neu bauen. Zu klären ist dann, ob die Bilder aus
`data/<db>/parts/` oder die der Webseite (E6) gelten und ob die Karte
„eBay-Artikel" im Kanalbereich der Shop-Karte aufgeht.

## Umsetzung Schritt 3

Statt eines eigenen Reiters ein Abschnitt „Verkaufskanäle" im Reiter Shop
der Firmenkonfiguration, direkt nach den Angaben zur Rechnungsstellung — dort
steht `shop_tax_included`, von dem abhängt, ob ein fester Aufschlag netto
oder brutto gilt.

| Datei | Inhalt |
| --- | --- |
| `src/features/shop/components/shop-channels.config.vue` | Neu. Je Kanal eine Karte: Zahl der angebotenen Artikel, Schalter (beim letzten eingeschalteten Kanal gesperrt, V8), Aufschlagsart und Wert, Rundung auf ,99 mit Warnung bei Nettopreisen (V2d). Lädt und speichert selbst über die Shop-API, verzögert wie die übrigen Felder; Meldung, wenn die Seiten neu geschrieben werden |
| `src/core/views/config/tabs/shopDefaultsConfig.js`, `shop-defaults.tab.vue` | Feld vom Typ `component` (`sales-channels`), Einbindung der Komponente mit dem Formularwert von `shop_tax_included` |
| `backend/api/shop/admin.php` | Neu: `getShopChannels`, `saveShopChannel` (prüft Aufschlagsart und Abschlag unter 100 %, lässt den letzten eingeschalteten Kanal eingeschaltet und meldet den geltenden Stand zurück, legt bei Preisänderung am HugoShop `publish_all` an, V9). `getShopStatus` liefert `recommendations`, darin `channel_round_99_net` (V2d) |
| `src/features/shop/views/shop.hub.vue` | Abschnitt „Empfehlungen" in der Übersicht, getrennt von den fehlenden Angaben |
| `backend/upstall/shop/company_schema.sql` | Neu: `shop_active_channel_id(type)` — Kennung nur bei eingeschaltetem Kanal. Suche, `shopListedParts()`, Kategorien, Vorschaubilder, Kennzahl und `listed` der Produktseite und der Artikelkarte fragen damit (V8). Warenkorb, Rechnung und Speichern nutzen weiter `shop_channel_id()`, damit Texte und Preise unabhängig vom Schalter gelten |
| `src/features/shop/locales/*.json` | `ShopView.channelConfig`, `ShopView.status.recommendations`/`recommendation` in 21 Sprachen |

**Geprüft** gegen die Testinstanz: Laden, Speichern, Sperre beim HugoShop,
genau ein Auftrag bei Preisänderung, keiner bei gleichem Stand oder anderem
Kanal, Fehlerfälle; V8: letzter Kanal bleibt an, HugoShop abschaltbar bei
eingeschaltetem eBay-Kanal, abgeschalteter HugoShop bietet nichts an (Suche,
Liste, Kategorien, Vorschaubilder, Produktseite), Wiedereinschalten stellt
alles her; Build. Nicht geprüft: Bedienung im Browser,
`getShopStatus` als Ganzes (braucht die vollständige kivitendo-Datenbank).

## Umsetzung Schritt 4

**Kanalmodule** unter `backend/api/shop/channels/`:

| Datei | Inhalt |
| --- | --- |
| `channels.php` | Rahmen: `SHOP_CHANNEL_TYPES` (bisher nur `hugoshop`), lädt die Module; `shopChannelJobPairs()` (Paare Kanal:Auftragsart für die Abfragen), `shopChannelRunJob()` (übergibt einen Auftrag dem Modul seines Kanals), `shopChannelSwitched()` (meldet Ein- und Ausschalten). Beschreibt, was ein Modul bereitstellen muss |
| `hugoshop.php` | Erstes Modul. Die Abarbeitung der Aufträge aus `shopRunJobs()` unverändert hierher verschoben; neu `remove_all`, Sperre für `publish_part` bei abgeschaltetem Kanal, `shopChannelHugoshopSwitched()` (V16), `shopChannelHugoshopActive()`, `shopChannelHugoshopPages()` |

Eingebunden werden die Module am Ende von `lib/publish.php` — überall, wo
der Läufer oder die Warteschlange gebraucht wird.

**Warteschlange:**

- `batchjob_hugoshop.channel_id` (Upstall trägt die Spalte nach). `NULL`
  heißt HugoShop: Aufträge von vorher und Schreiber ohne die Spalte bleiben
  gültig. Kein Fremdschlüssel — auf einer bestehenden Datenbank legt der
  Upstall nur die Spalte an.
- `shopQueueJob()` nimmt den Kanal als letzten Parameter (Vorgabe
  `hugoshop`); gleiche Aufträge verschiedener Kanäle sind keine Doppel.
- `shopOpenJobs()`, `shopDeleteJobs()`, `shopCleanupJobs()` und die
  Auftragsliste (`getShopPublishJobs`, jetzt mit `channel`) filtern nach
  Kanal und Auftragsart. Aufträge eines Kanals ohne Modul bleiben unberührt.
- `shopRunJobs()` verteilt nur noch an die Module; ein Fehler trifft nur den
  einen Auftrag (V13). Die Bilanz zählt zusätzlich `jobs_hugoshop`.
- `shopPublishRun()` lässt Paket, Kategorieübersicht und Bau aus, wenn der
  HugoShop abgeschaltet ist und in diesem Lauf keine HugoShop-Aufträge liefen.

**V16:**

- Abschalten des HugoShops (`saveShopChannel`): offene `publish_all` und
  `publish_part` löschen, `remove_all` anlegen. Einschalten: offenes
  `remove_all` löschen, `publish_all` anlegen. Das Löschen der Gegenrichtung
  verhindert, dass aus-an-aus die Seiten stehen lässt.
- Öffentlicher Zugang: `shopClosedActions()` (`inCart`, `changeQuantity`,
  `checkout`, `invoicing`, `beginPayment`) antwortet bei abgeschaltetem
  HugoShop mit `SHOP_CLOSED`. `endPayment`, Konto, Rechnungen und Widerruf
  bleiben erreichbar. Prüfung über `shopIsOpen()` in `lib/config.php`.
- Übersetzung der neuen Auftragsart `remove_all` in 21 Sprachen;
  `dev/shop-betrieb.md` ergänzt.

**Geprüft** gegen die Testinstanz mit echten Seitendateien: Anlegen und
Zusammenfassen von Aufträgen je Kanal, Altauftrag ohne Kanal, Auftrag eines
Kanals ohne Modul bleibt liegen, Abschalten entfernt genau die Seiten der
angebotenen Artikel (fremde Datei bleibt), aus-an-aus, Gesamtlauf bei
abgeschaltetem HugoShop, Einschalten, `shopIsOpen`, Auftragsliste, Löschen;
dazu die Prüfläufe der Schritte 1 bis 3 erneut; Build.
Nicht geprüft: `tools/shop-publish.php` auf der Kommandozeile gegen einen
echten Mandanten, die Betriebsart HugoCMS, die Anzeige von `SHOP_CLOSED` in
der Shop-UI.

**Hinweise:**

- Die Shop-UI (Web Components) kennt `SHOP_CLOSED` noch nicht und zeigt den
  Text der Fehlermeldung. Eine eigene Anzeige „Shop geschlossen" gehört zum
  Webseiten-Paket und folgt, wenn der HugoShop tatsächlich abgeschaltet
  werden kann (mit dem eBay-Kanal).
- Die Auftragsliste der Übersicht liefert den Kanal mit, zeigt ihn aber noch
  nicht an; mit nur einem Kanal wäre die Spalte leer an Aussage. Folgt mit dem
  eBay-Kanal.

## Nachtrag zu Schritt 4: V16 geändert, V22, V23

**V16, Entwurf statt Entfernen:**

- Neue Auftragsart `draft_all`: schreibt die Seiten aller im HugoShop
  angebotenen Artikel als Entwurf neu. `shopPageAsDraft()` setzt im Front
  Matter `draft: true` — in der Seite statt in der Vorlage, damit es für
  jeden Vorlagensatz gilt, auch für Kundenkopien. Hugo veröffentlicht
  Entwürfe nicht: der lokale Bau ruft `hugo` ohne `--buildDrafts` auf,
  HugoCMS baut ausdrücklich ohne (`Connector.php`).
- `shopChannelHugoshopSwitched()` wählt nach `shop_channel_off_pages`
  zwischen `draft_all` (Vorgabe) und `remove_all` und löscht offene Aufträge
  der Gegenrichtung.
- Die Kategorieübersicht zählt nur angebotene Artikel; bei abgeschaltetem
  HugoShop entfällt sie, auch wenn die Seiten als Entwurf stehen bleiben.

**V22, automatisch neu veröffentlichen** (Upstall, Abschnitt
„Verkaufskanäle"):

| Objekt | Auslöser | Auftrag |
| --- | --- | --- |
| `trigger_parts_shop_auto_publish` auf `parts` | Verkaufspreis oder Buchungsgruppe eines im HugoShop angebotenen Artikels | `publish_part` |
| `trigger_parts_channel_shop_auto_publish` | Aufschlag, Bezeichnung oder Langbeschreibung im HugoShop (nur echte Änderungen — das Speichern der Artikelkarte schreibt jedes Mal alle Zeilen) | `publish_part` |
| `trigger_defaults_oserp_shop_auto_publish` | `shop_tax_included` umgeschaltet | `publish_all` |
| `saveShopChannel` (V9) | Kanalvorgaben des HugoShops | `publish_all` |

Alle nur, wenn `shop_auto_publish` gesetzt, die Erweiterung aktiv und der
HugoShop eingeschaltet ist (`shop_auto_publish_enabled()`). Die Regel für das
Anlegen eines Auftrags steht jetzt in der Datenbank (`shop_queue_job()`);
`shopQueueJob()` ruft sie auf.

Der Trigger auf `parts` ist die einzige Berührung einer kivitendo-Tabelle:
ein Trigger, keine Spalte, kein Constraint — freigegeben mit O1.

Nicht erfasst: ein neuer Steuersatz (`taxkeys`, kivitendo) und ein Wechsel
des Vorlagensatzes. Dafür bleibt „Alle veröffentlichen" in der Übersicht.

**V23, Warenkorb:**

- `cartAdd()` nimmt nur Artikel mit aktiver HugoShop-Zeile bei
  eingeschaltetem Kanal an; sonst `PART_NOT_FOUND` wie bisher.
- Jede Warenkorbposition trägt `offered` (der Versandartikel gilt als
  angeboten).
- `cartRequireOffered()` lehnt mit `CART_NOT_OFFERED` und den Bezeichnungen
  ab: vor der PayPal-Zahlung (`paymentBegin`) und beim Kauf auf Rechnung —
  nicht nach einer PayPal-Zahlung, dann muss die Rechnung entstehen.

**Einstellungen** im Reiter Shop, Abschnitt Veröffentlichung:
`shop_auto_publish` (Schalter) und `shop_channel_off_pages` (Auswahl),
übersetzt in 21 Sprachen (`src/core/views/config/locales`).

**Geprüft** gegen die Testinstanz: alle drei Trigger samt Gegenproben (nicht
angebotener Artikel, gleiche Werte, reine Textänderung an `parts`,
Einstellung aus, Erweiterung inaktiv), `shopPageAsDraft` mit und ohne
`draft`-Zeile, Abschalten mit Entwurf (echte Seiten aus dem Vorlagensatz
`standard`, danach `draft: true`), Wiedereinschalten (`draft: false`),
Einstellung „entfernen", Warenkorb (angeboten, abgewählt, Versandartikel,
unbekannt, HugoShop abgeschaltet), `offered` und `cartRequireOffered`; die
Prüfläufe der Schritte 1 bis 4 erneut; Build. Nicht geprüft: PayPal gegen
die Sandbox, die Shop-UI mit `CART_NOT_OFFERED`.

**Hinweis Shop-UI:** `CART_NOT_OFFERED` und `SHOP_CLOSED` zeigt die Shop-UI
bisher als Fehlertext. Eine Markierung der betroffenen Position im Warenkorb
(über `offered`) gehört zum Webseiten-Paket und folgt mit dem eBay-Kanal.

## Nachtrag: V24, Steuerschlüssel am Belegdatum

| Stelle | Vorher | Jetzt |
| --- | --- | --- |
| `lib/cart.php` (Summen, Positionen mit Buchungsangaben) | neuester Schlüssel je Konto | neuester Schlüssel mit `startdate <= current_date` |
| `faktura.php`, Belegansicht (`getFakturaData`) | neuester Schlüssel | neuester mit `startdate <=` Belegdatum (`transdate` von `ar`, `ap`, `oe` bzw. `delivery_orders`, ersatzweise heute) |
| `faktura.php`, neue Position (`createFakturaItem`) | neuester Schlüssel | wie Belegansicht |
| `faktura.php`, `postArInvoiceToLedger` / `postApInvoiceToLedger` | neuester Schlüssel | neuester mit `startdate <=` Belegdatum |

Alle vier Stellen der Faktura gleich — sonst zeigte der Beleg einen anderen
Satz, als gebucht wird. Die Bedingung steht in der `LEFT JOIN`-Klausel: gibt
es noch keinen gültigen Schlüssel, bleibt der Satz leer wie bisher, statt die
Position zu verlieren.

**Geprüft** gegen die Testinstanz (Schlüssel 19 % ab 2020 und 21 % ab 2099):
Buchungsabfrage aus `faktura.php` mit heutigem Datum 19 %, mit 2099 21 %, vor
allen Schlüsseln leer; Warenkorb rechnet 19 % (vorher 21 %). Die Abfragen der
Belegansicht und der neuen Position nur auf Syntax (`php -l`); sie brauchen
die vollständige Faktura und sind im Browser zu prüfen.

## Umsetzung Schritt 5, Teil 1: eBay-Kanal ausgehend

**Sicherheitslücke behoben:** `ebay_client_secret`, `ebay_refresh_token` und
der Token-Cache `ebay_access_token*` gingen bisher mit der
Firmenkonfiguration an den Browser (`oserp_config/defaults.php`, beide
Ausschlusslisten). Wer die Firmenkonfiguration öffnen konnte, sah das
Refresh-Token des eBay-Verkäuferkontos. Die Schlüssel sind jetzt
ausgenommen; die Oberfläche zeigt „hinterlegt" wie bei den Shop-Geheimnissen.

**Modul** `backend/api/shop/channels/ebay.php` (in `SHOP_CHANNEL_TYPES`):

- Zugang und API aus `backend/api/ebay/ebay.php` übernommen, Verhalten
  gleich, Namen `shopEbay…` (die alte Anbindung liegt bis zum Entfernen noch
  daneben).
- Aufträge `publish_part`, `remove_part`, `publish_all`, `remove_all` mit
  Kanal `ebay`. Einstellen: Inventory Item → Offer → Publish wie bisher,
  aber Preis `shop_channel_price(…, 'ebay')` brutto, Titel und
  Beschreibung aus dem Kanal, Menge = Bestand (V19), Bilder des eBay-Kanals,
  Kategorie und Zustand je Artikel oder Vorgabe. Stand in `parts_channel_shop`
  (`sync_status`, `sync_error`, `sync_mtime`, `external_id` = Listing,
  `sync_data.offer_id`). Jeder Fehler steht am Artikel.
- V19: Dienstleistungen werden abgelehnt; ein neues Angebot ohne Bestand
  ebenso. Ein bestehendes bleibt bei Bestand 0 als „ausverkauft" stehen —
  **Voraussetzung ist die Einstellung „Out-of-Stock Control" im
  eBay-Verkäuferkonto**, sonst beendet eBay das Angebot selbst.
- V26: Ein- und Ausschalten des Kanals legt `publish_all` bzw. `remove_all`
  an und schreibt `ebay_enabled` mit, solange der Bestellimport noch dort
  nachsieht.
- Der Läufer arbeitet ohne Webanfrage und kennt die eigene Adresse nicht:
  **`ebay_public_host` ist Pflicht** (neue Einstellung), sonst kann eBay die
  Bilder nicht abholen.

**Schema** (Upstall, Abschnitt „Verkaufskanäle"):

| Objekt | Zweck |
| --- | --- |
| `parts_channel_shop.sync_data` | vom Kanal geschriebene Angaben (offer_id), getrennt von `settings` (Benutzer) |
| `parts_channel_image_shop` | Bilder je Marktplatz-Kanal (V12); Dateien weiter unter `data/<db>/parts/<id>/`, öffentlich über `backend/webhook/part-image.php` |
| Kanalzeile `ebay` | eingeschaltet, wenn `ebay_enabled` es war |
| `shop_queue_job()` | löscht jetzt offene Aufträge der Gegenrichtung (veröffentlichen/entfernen) für denselben Artikel und Kanal |
| `shop_extension_active()` | Trigger legen nur Aufträge an, wenn die Erweiterung aktiv ist; `shop.ohne_auftraege` schaltet sie während der Übernahme ab |
| Trigger auf `parts` | Marktplätze bei Preis, Buchungsgruppe, Bestand, Beschreibung, Langbeschreibung — immer; HugoShop bei Preis und Buchungsgruppe — nur mit `shop_auto_publish` |
| Trigger auf `parts_channel_shop` | Marktplatz: neu gewählt oder geändert (auch `settings`) → einstellen, abgewählt → beenden; HugoShop wie bisher |
| Trigger auf `parts_channel_image_shop` | Bilder geändert → Angebot neu einstellen |
| Trigger auf `defaults_oserp` | Brutto/Netto umgeschaltet → `publish_all` je eingeschaltetem Kanal |
| Übernahme (V21, Merker `shop_ebay_migrated`) | `ebay_listings` → eBay-Kanalzeilen (aktiv = eingestellt, Listing- und Angebotskennung bleiben), `ebay_part_images` → eBay-Bilder. Keine Aufträge dabei. Alte Tabellen bleiben stehen |

Die Trigger werden jetzt bei jedem Upstall neu angelegt (`DROP TRIGGER IF
EXISTS` + `CREATE`), damit geänderte Bedingungen ankommen.

**Bilder** (`lib/channel_images.php`, Aktionen in `admin.php`):
`uploadShopChannelImage`, `deleteShopChannelImage`, `sortShopChannelImages`,
`copyShopChannelImages`. Übernahme nach V17: HugoShop → eBay aus dem
Bildverzeichnis der Webseite (lokal) oder über `shop_images_link`;
eBay → HugoShop nur in der Betriebsart lokal. Dateinamen sind die Prüfsumme
des Inhalts; eine Datei wird erst gelöscht, wenn kein Kanal sie mehr nutzt.

**Artikelkarte:** je Marktplatz Kategorie und Zustand (Feldliste
`KANAL_FELDER`, leer = Vorgabe), Bilder mit Hochladen, Entfernen,
Umsortieren und „Bilder aus … übernehmen", Stand beim Kanal mit Fehlertext.
Beim HugoShop „Bilder aus eBay übernehmen", nur in der Betriebsart lokal.
Marktplätze sind bei Dienstleistungen gesperrt. Die alte Karte
„eBay-Artikel" in `article.edit.view.vue` ist entfernt.

**Einstellungen:** Gruppe „eBay" im Reiter Shop unter „Verkaufskanäle" mit
allen bisherigen `ebay_*`-Feldern, der neuen `ebay_public_host` und der
Statusanzeige (Verbindungstest, Bestellabruf). Die Einstellungen bleiben
unter ihren Schlüsseln in `defaults_oserp` — **Abweichung vom Plan**
(`sales_channel_shop.settings`): so braucht es keine Datenübernahme, und die
Geheimnisbehandlung der Firmenkonfiguration greift unverändert.
`ebay_listing_enabled` und `ebay_listing_quantity` entfallen (Kanalschalter,
Bestand). Im CRM-Reiter steht statt des eBay-Abschnitts ein Hinweis, mit
Warnung, wenn eBay eingeschaltet ist, die Shop-Erweiterung aber nicht (V18).

**Einrichtungsprüfung (V18):** HugoShop-Punkte nur bei eingeschaltetem
HugoShop; bei eingeschaltetem eBay fehlende Zugangsdaten, öffentliche
Adresse, Kategorie, Lagerort und Policies als Hinweise.

**Übersetzungen:** neue Schlüssel unter `ShopView.partCard`,
`ShopView.ebayCondition` und `crm_fields` in 21 Sprachen.

**Geprüft** gegen die Testinstanz: Übernahme aus nachgebildeten
`ebay_listings`/`ebay_part_images` (auch verwaiste Zeilen, keine Aufträge),
alle Trigger mit Gegenproben, Gegenrichtung in der Warteschlange,
Bildablage, Typprüfung, Übernahme in beide Richtungen, Sperre in der
Betriebsart HugoCMS, Löschen mit Aufräumen der Datei, Einstellen bis zu den
Prüfungen vor dem ersten eBay-Aufruf (fehlende Adresse, Einstellungen,
Zugangsdaten, Dienstleistung) samt Stand am Artikel, Ein- und Ausschalten,
Laden und Speichern der kanaleigenen Angaben; die Prüfläufe der Schritte 1
bis 4; Build. **Nicht geprüft:** Aufrufe gegen eBay selbst (Sandbox), die
Bedienung im Browser (Hochladen, Umsortieren).

**Offen für Teil 2:**

Umgesetzt in Teil 2, siehe unten.

## Umsetzung Schritt 5, Teil 2: Bestellimport, alte Anbindung entfernt

**Bestellimport** in `backend/api/shop/channels/ebay_orders.php`, aus
`backend/api/ebay/import.php` übernommen, Verhalten gleich: Abruf seit
`ebay_order_last_check`, Kunde ohne Dubletten (bekannter Käufer,
Adressvergleich, sonst neu), eine Rechnung je Bestellung (brutto), Buchung,
Sperre gegen Doppelimport und Nachweis in `ebay_orders`, Sammelartikel
`ebay_default_parts_id` für unbekannte SKU und Versandkosten. Geändert:
eingeschaltet über den Kanalschalter (`shopEbayActive`) statt `ebay_enabled`;
der Kanalschalter schreibt `ebay_enabled` nicht mehr.

**Aktionen** (`/api/shop/`): `testShopEbay` (Recht `edit_shop_config`),
`syncShopEbayOrders` und `getShopEbayStatus` (`shop_order` oder
`edit_shop_config`). Vorher: Abruf mit `invoice_edit`, Test und Stand ohne
Rechteprüfung.

**Cron** `backend/cli/ebay-orders.php`: Name und Aufruf bleiben
(`install/install.sh`); berücksichtigt nur Mandanten mit aktiver
Shop-Erweiterung und eingeschaltetem eBay-Kanal und ruft
`shopEbayImportOrders()`.

**Statusanzeige:** `src/core/views/config/tabs/ebay-status.config.vue` →
`src/features/shop/components/shop-ebay-status.vue`, gegen die Shop-API;
eingebunden in der Gruppe „eBay" des Reiters Shop.

**Entfernt:** `backend/api/ebay/` (`ebay.php`, `import.php`, `listings.php`,
`orders.php`, `index.php`). Die Tabellen `ebay_orders` (weiter genutzt),
`ebay_listings` und `ebay_part_images` (übernommen, V21) bleiben;
`backend/webhook/part-image.php` bleibt für die Bilder.

**V20, Ergebnis der Prüfung:** Weder die Faktura noch die Rechnungen des
HugoShops buchen Lager. Bestand ändert sich in kivitendo nur über
Lagerbewegungen (Tabelle `inventory`, Trigger `trig_update_onhand`). Der
eBay-Import bucht deshalb — wie beschlossen „genauso wie die Faktura" —
ebenfalls nicht. Folge für V4: Der gemeinsame Bestand sinkt erst, wenn die
Ware im Lager ausgebucht wird. Siehe O14.

**Shop-UI:** Fehlertexte `SHOP_CLOSED`, `CART_NOT_OFFERED`, `PART_NOT_FOUND`
(Deutsch, Englisch — die Sprachen der Shop-UI) und im Warenkorb die
Kennzeichnung „Nicht mehr erhältlich" für Positionen mit `offered: false`.
Bündel neu gebaut
(`backend/templates-default/shop/standard/kit/assets/shop-ui/shop-widgets.js`);
es geht mit dem nächsten Abgleich des Webseiten-Pakets an die Webseite.

**Übersicht:** Die Auftragsliste zeigt den Kanal je Auftrag.

**Geprüft** gegen die Testinstanz: Mitarbeiter, Artikelzuordnung (SKU,
Sammelartikel, Fehler ohne), Kunde über bekannten Käufer, doppelte Bestellung
wird übersprungen, Stand für die Anzeige, Sperre bei abgeschaltetem Kanal;
Signaturen der genutzten Faktura-Funktionen; alle Prüfläufe der Schritte 1
bis 5; Build von OSERP und Shop-UI. **Nicht geprüft:** Abruf und Import
gegen eBay (Sandbox) mit Rechnung und Buchung, der Cron-Lauf, die Anzeige in
Browser und Shop-UI.

## Umsetzung O14: Lagerbuchung bei Verkäufen (V28)

**Datenbank:** `shop_book_stock(ar_id)` im Upstall. Bucht je Warenposition
der Rechnung eine Zeile in `inventory` — wie `bookStock` der
Lagerverwaltung, `parts.onhand` schreibt der kivitendo-Trigger
`trig_update_onhand` fort:

| Angabe | Wert |
| --- | --- |
| Lager, Lagerplatz | `shop_stock_bin_id`, Lager über `bin.warehouse_id` |
| Menge | minus Rechnungsmenge |
| Buchungsart | Ausgang „shipped“, ersatzweise „used“ |
| Mitarbeiter | der Rechnung, sonst der erste aktive |
| Beleg | `invoice_id` = Rechnungsposition — die Lagerverwaltung nimmt solche Buchungen nicht einzeln zurück |
| Bemerkung | „Verkauf, Rechnung <Nummer>“ |

Nur Waren (`part_type = 'part'`) mit positiver Menge; nicht der
Versandartikel und nicht der eBay-Sammelartikel. Höchstens einmal je
Rechnung. Der Bestand darf negativ werden (verkauft ist verkauft). Gebuchte
Artikel werden lagerfähig (`stockable`), wie bei `bookStock`.

**Aufruf:** `shopBookStock()` in `lib/config.php`, nach dem Buchen der
Rechnung — in `createShopInvoice()` (HugoShop, Rechnung und PayPal) und in
`shopEbayImportOrder()` (auch bei ungebuchter Rechnung). Ein Fehler lässt die
Bestellung nicht scheitern, er wird protokolliert.

**Folge:** Die Ausbuchung senkt `parts.onhand`; der Trigger aus V22 legt
daraufhin den Bestandsabgleich für eBay an.

**Einstellung:** „Lagerplatz für Verkäufe“ im Reiter Shop (Abschnitt
Rechnungsstellung), Auswahl „Lager – Platz“ aus `getWarehouseOptions`;
übersetzt in 21 Sprachen. **Einrichtungsprüfung:** Hinweis
`shop_stock_bin_id`, solange HugoShop oder eBay eingeschaltet und kein
gültiger Lagerplatz eingestellt ist.

**Geprüft** gegen die Testinstanz mit nachgebildetem Lager und
kivitendo-Trigger: ohne Lagerplatz keine Buchung; mit Lagerplatz nur die
Ware, richtige Menge, Buchungsart, Mitarbeiter, Beleg; Bestand sinkt,
`stockable` gesetzt, eBay-Auftrag entsteht; kein zweites Mal; ungültiger
Platz bucht nicht; Prüfläufe der Schritte 1 bis 5; Build. **Nicht geprüft:**
gegen die echte kivitendo-Datenbank (weitere Trigger auf `inventory`, etwa
`check_bin_wh_inventory`), die Auswahl im Browser.

## Probleme und anstehende Entscheidungen

| Nr. | Thema | Stand | Vorschlag |
| --- | --- | --- | --- |
| O1 | **Preis auf der Seite veraltet.** Die Produktseiten tragen den Preis fest im Inhalt. Warenkorb und Rechnung rechnen sofort mit dem neuen Preis, die Seite erst nach der Veröffentlichung. Automatisch neu geschrieben wird nur bei einer Änderung am Kanal (V9). Nicht bei: eigenem Aufschlag in der Artikelkarte, Verkaufspreis oder Buchungsgruppe in der Artikelmaske (Kern), Umschalten von `shop_tax_included`, neuem Steuersatz. Das galt für den Verkaufspreis schon vor den Verkaufskanälen | **entschieden 2026-09-25**: Vorschlag gilt, einstellbar (V22) | Trigger auf `parts` (Spalten `sellprice`, `buchungsgruppen_id`) und `parts_channel_shop` (Aufschlag, Texte), der für im HugoShop angebotene Artikel `publish_part` anlegt; `shop_tax_included` wie V9 mit `publish_all`. Die Warteschlange fasst doppelte offene Aufträge bereits zusammen |
| O2 | **Artikel außerhalb des Shops im Warenkorb.** `cartAdd()` nimmt jeden vorhandenen Artikel an, auch abgewählte, veraltete oder nie angebotene — wer die Kennung kennt, kann sie über die öffentliche Schnittstelle bestellen. Bestand schon vor den Verkaufskanälen | **entschieden 2026-09-25**: Vorschlag gilt (V23) | Nur Artikel mit aktiver HugoShop-Zeile annehmen (der Versandartikel wird intern ergänzt und ist nicht betroffen); abgewählte Artikel in bestehenden Warenkörben beim Bezahlen melden |
| O3 | **Vorhandene eBay-Anbindung im Kern** (Befund bei Schritt 2) | **entschieden 2026-09-25**: (a) alle Kanäle setzen die Shop-Erweiterung voraus (V14); (b) jeder Kanal hat eigene Bilder, Übernahme aus anderen Kanälen (V12); (c, d) die bisherige Anbindung wird durch den eBay-Kanal ersetzt, samt aller Funktionen (V15) | Umsetzung nach „Plan Schritt 5" |
| O4 | **Künftige Steuerschlüssel.** Warenkorb und Buchung (`postArInvoiceToLedger`, Kern) nehmen den neuesten Schlüssel eines Kontos, auch einen künftigen; Produktseite und `shop_tax_rate()` nur gültige. Relevant nur bei im Voraus eingetragenem Satzwechsel | **entschieden 2026-09-25**: Vorschlag gilt (V24), umgesetzt | Warenkorb und Buchung auf `startdate <= Belegdatum` angleichen — Änderung am Kern, deshalb nicht ohne Freigabe |
| O5 | **Grenzfall V7.** Löscht die Bridge eine `parts_ext`-Zeile und legt sie später neu an, bleibt der HugoShop abgeschaltet. Ob `run.php` so vorgeht, ist nicht geprüft | **entschieden 2026-09-25**: Vorschlag gilt (V25) | Beim Lieferantenimport in OSERP (Stufe F) Kanalzeilen ausdrücklich schreiben; danach Trigger entfernen |
| O6 | **Abgeschaltete Kanäle.** Artikelzeilen eines abgeschalteten Kanals bleiben stehen und erscheinen nicht mehr in der Artikelkarte. Für den HugoShop ohne Folgen (V8); für eBay/Amazon ist offen, ob Abschalten die laufenden Angebote beendet | **entschieden 2026-09-25**: Vorschlag gilt (V26) | Abschalten eines Marktplatz-Kanals beendet die Angebote über die Warteschlange; Wiedereinschalten stellt sie neu ein |
| O8 | **Abgeschalteter HugoShop** (seit V8 möglich, sobald eBay umgesetzt ist). Suche, Liste und Kategorien bieten dann nichts mehr an. Die Produktseiten bleiben aber auf der Webseite — `publish_all` schreibt nur, es entfernt nichts —, und der öffentliche Zugang nimmt weiter Warenkörbe und Bestellungen an (siehe O2) | **entschieden 2026-09-25**: Vorschlag gilt (V16) | Beim Abschalten alle Produktseiten entfernen (neuer Auftrag `remove_all`, nicht Tausende einzelne); der öffentliche Zugang antwortet „Shop geschlossen" auf Warenkorb und Bestellung. Beim Einschalten `publish_all` |
| O9 | **Bildübernahme zwischen Kanälen** (V12). HugoShop → eBay geht: OSERP lädt die Bilder über `shop_images_link` von der Webseite und legt sie beim eBay-Kanal ab. eBay → HugoShop braucht einen Weg, Dateien auf die Webseite zu bringen: in der Betriebsart „lokal" in `shop_images_dir`, in der Betriebsart „HugoCMS" gibt es keinen — die Medien bleiben dort nach E6 auf der Webseite, und HugoCMS nimmt keine Bilder entgegen | **entschieden 2026-09-25**: Vorschlag gilt (V17) | Richtung HugoShop → eBay und eBay → HugoShop (nur „lokal") mit Schritt 5; für HugoCMS entweder eine Upload-Schnittstelle in HugoCMS (Änderung an HugoCMS) oder die Richtung dort nicht anbieten |
| O10 | **Mandanten, die eBay heute ohne Shop-Erweiterung nutzen.** Nach V14/V15 brauchen sie die Shop-Erweiterung. Die Übernahme der eBay-Daten läuft im Upstall der Shop-Erweiterung, also erst, wenn sie aktiviert ist. Die Einrichtungsprüfung meldet dann HugoShop-Punkte (Shop-Schlüssel, Versandartikel …) als blockierend, obwohl der Mandant nur eBay nutzen will | **entschieden 2026-09-25**: Vorschlag gilt (V18) | Alte eBay-Anbindung im selben Schritt entfernen; Hinweis in der Firmenkonfiguration, solange `ebay_enabled` gesetzt, aber die Shop-Erweiterung nicht aktiv ist. `getShopStatus` prüft HugoShop-Punkte nur bei eingeschaltetem HugoShop |
| O11 | **Menge bei eBay** (V4). Bisher fest `ebay_listing_quantity`. Mit gemeinsamem Bestand: `parts.onhand`, abgerundet, nicht negativ. Bei 0 bleibt das Angebot als „ausverkauft" stehen (eBay-Einstellung „Out-of-Stock Control") oder wird beendet. Dienstleistungen haben keinen Bestand | **entschieden 2026-09-25**: Vorschlag gilt (V19) | Menge = Bestand; bei 0 „ausverkauft" statt beenden; Dienstleistungen nicht über eBay anbieten |
| O12 | **Bestand nach eBay-Verkauf.** Der Bestellimport legt eine Rechnung an. Ob er den Lagerbestand mindert (Lagerbuchung), ist nicht geprüft — ohne das stimmt der gemeinsame Bestand (V4) nicht | **entschieden 2026-09-25**: Vorschlag gilt (V20) | Beim Bau des eBay-Kanals prüfen, wie die Faktura den Bestand bucht, und den Import gleich behandeln |
| O13 | **Übergang der Daten.** `ebay_listings` (Angebots- und Listing-Kennung, Stand) und `ebay_part_images` gehören dem CRM-Schema. Werden sie in `parts_channel_shop` und die neue Bildverwaltung übernommen, bleiben die alten Tabellen stehen oder werden gelöscht? `ebay_orders` bleibt als Sperre gegen doppelte Rechnungen und wird von der Kundenzusammenführung (`accounting/customer_matching.php`) gelesen | **entschieden 2026-09-25**: Vorschlag gilt (V21) | Übernehmen, alte Tabellen `ebay_listings` und `ebay_part_images` stehen lassen, bis alle Mandanten übernommen sind, dann in einem eigenen Schritt entfernen; `ebay_orders` bleibt unverändert |
| O7 | **Amazon.** SP-API verlangt eine Registrierung als Entwickler, Listings nach produkttypabhängigem Schema und Bestandsmeldungen | **entschieden 2026-09-25**: Vorschlag gilt (V27) | Erst nach eBay angehen; vorher klären, ob ein Amazon-Verkäuferkonto mit API-Zugang besteht |
| O14 | **Lagerbuchung bei Verkäufen** (Ergebnis zu V20). Rechnungen aus HugoShop und eBay buchen kein Lager; der gemeinsame Bestand (V4) sinkt erst, wenn jemand die Ware ausbucht. Bis dahin meldet eBay den alten Bestand, und die Produktseite zeigt „auf Lager" — Überverkauf ist möglich. Eine automatische Ausbuchung braucht Lager, Lagerplatz und Buchungsart (`inventory`: `warehouse_id`, `bin_id`, `trans_type_id`), wie `bookStock` in `backend/api/warehouse/transfer.php` | **entschieden 2026-09-25**: Vorschlag gilt (V28), umgesetzt | Einstellungen „Lager und Lagerplatz für Verkäufe" im Reiter Shop; beim Anlegen einer Rechnung aus HugoShop oder eBay je Warenposition (nicht Dienstleistung, nicht Versand) eine Ausbuchung. Ohne Einstellung wie bisher keine Buchung, mit Hinweis in der Einrichtungsprüfung. Die Faktura selbst bleibt unberührt |

## Offener Stand nach Schritt 5

Zusammenfassung dessen, was nach Schritt 5 noch fehlt. Die Punkte O1 bis O14
sind entschieden (siehe „Probleme und anstehende Entscheidungen“); hier
beginnen die neuen bei O15.

### Anstehende Entscheidungen

| Nr. | Thema | Stand heute | Vorschlag |
| --- | --- | --- | --- |
| O15 | **Amazon** (V27) | Nicht begonnen. Voraussetzung ist ein Amazon-Verkäuferkonto mit Zugang zur Selling Partner API (Registrierung als Entwickler, Anmeldung über Login with Amazon). Angebote verlangen je Produkttyp eigene Pflichtangaben (Product Type Definitions) | Vor Beginn klären: (a) Konto und API-Zugang vorhanden? (b) Versand durch den Händler oder durch Amazon (FBA — dann führt Amazon den Bestand, und V4 gilt dort nicht)? (c) welche Marktplätze? (d) Zuordnung über EAN oder vorhandene ASIN? Danach wie eBay: Modul `channels/amazon.php`, Kanalzeile, Bestellimport |
| O16 | **Zahlungen und Gebühren bei eBay** | Der Import legt die Rechnung an und bucht sie, aber keinen Zahlungseingang. eBay zahlt gesammelt aus und behält Gebühren ein; beides wird nicht gebucht | Auszahlungen über die eBay Finances API abrufen und wie die Kartenabrechnungen (`payment_settlement_lines`) den Rechnungen zuordnen; Gebühren auf ein einstellbares Aufwandskonto. Alternative: bleibt von Hand über den Kontoauszug |
| O17 | **Stornos, Rücksendungen, Abbrüche** | Wird eine Rechnung storniert oder ein Widerruf bearbeitet, bucht niemand das Lager zurück (V28 bucht nur aus). Von eBay abgebrochene oder erstattete Bestellungen werden nicht abgeholt; die Rechnung bleibt stehen | Rückbuchung des Lagers beim Storno einer Shop- oder eBay-Rechnung (Gegenstück zu `shop_book_stock`); abgebrochene eBay-Bestellungen beim Abruf erkennen und in der Übersicht als „zu stornieren“ melden, statt selbst zu stornieren |
| O18 | **Verfügbarkeit auf der Produktseite** | Die Seite trägt „auf Lager“ oder „nicht auf Lager“ fest im Inhalt. Bestandsänderungen schreiben HugoShop-Seiten nicht neu (nur Preis, V22) | Neu schreiben nur beim Wechsel zwischen 0 und mehr als 0, nicht bei jeder Änderung — sonst baute jeder Verkauf die Webseite neu |
| O19 | **Lagerbuchung für Verkäufe außerhalb der Kanäle** | Rechnungen aus der Faktura des Kerns buchen kein Lager (V28 gilt nur für HugoShop und eBay). Wer auch im Laden oder auf Rechnung verkauft, hat einen gemeinsamen Bestand, der nur die Kanalverkäufe kennt | Entweder die Faktura bucht beim Buchen der Rechnung ebenfalls aus (Änderung am Kern), oder es bleibt beim Ausbuchen über Lieferscheine bzw. die Lagerverwaltung — dann in der Betriebsdokumentation festhalten |
| O20 | **Aufräumen der alten eBay-Anbindung** | Stehen geblieben: Tabellen `ebay_listings`, `ebay_part_images` (V21: bis alle Mandanten übernommen sind), Einstellungen `ebay_enabled`, `ebay_listing_enabled`, `ebay_listing_quantity` in `defaults_oserp`, ungenutzte Übersetzungen `ArticleEditView.ebay.*` und `crm_fields.ebayEnabled*`, `ebayListing*`, `ebayPanel`, `ebayArticle`. `ebay_enabled` liest noch der Hinweis im CRM-Reiter (Mandanten ohne Shop-Erweiterung) | Nach der Übernahme aller Mandanten: alte Tabellen und die beiden Listing-Einstellungen im CRM-Upstall entfernen, ungenutzte Übersetzungen löschen. `ebay_enabled` erst entfernen, wenn kein Mandant ohne Shop-Erweiterung es mehr gesetzt hat |
| O21 | **Sprachen der Shop-UI** | Die Shop-UI kennt Deutsch und Englisch; OSERP 21 Sprachen. Die neuen Meldungen (`SHOP_CLOSED`, `CART_NOT_OFFERED`, „Nicht mehr erhältlich“) gibt es nur in diesen beiden | Bleibt so, bis die Shop-UI grundsätzlich mehrsprachig wird — eigene Entscheidung außerhalb der Verkaufskanäle |
| O22 | **Aufrufgrenzen bei eBay** | Jede Änderung an Preis, Bestand oder Text erzeugt einen Auftrag; einer kostet drei bis vier API-Aufrufe (Inventory Item, Offer, Publish). Bei großem Sortiment und vielen Bestandsänderungen kann das Tageskontingent der Inventory API knapp werden | Beobachten. Bei Bedarf Bestandsänderungen über `bulkUpdatePriceQuantity` bündeln (bis 25 Artikel je Aufruf) statt über den vollen Abgleich |
| O23 | **Überverkauf zwischen zwei Abgleichen** | Der Bestand geht erst beim nächsten Lauf des Läufers (Cron, etwa alle 5 Minuten) an eBay. Wird das letzte Stück in dieser Zeit im HugoShop und bei eBay verkauft, ist es zweimal verkauft | Einstellbarer Sicherheitsbestand je Kanal (an eBay geht Bestand minus Sicherheitsbestand) oder kürzeres Cron-Intervall |
| O24 | **Öffentliche Bildadressen** | `backend/webhook/part-image.php?db=<Datenbank>&id=<Artikel>&f=<Prüfsumme>` — ohne Anmeldung abrufbar (eBay braucht das) und mit dem Namen der Mandantendatenbank in der Adresse. Übernommen aus der bisherigen Anbindung | Die Datenbank über ein Kürzel statt ihres Namens adressieren, oder als bekannt hinnehmen. Die Dateinamen sind Prüfsummen und nicht zu erraten |
| O25 | **Rechte für eBay** | Bestellabruf und Stand verlangen jetzt `shop_order` oder `edit_shop_config`, der Verbindungstest `edit_shop_config`. Vorher genügte für den Abruf `invoice_edit`, Test und Stand waren ohne Prüfung | Bestätigen oder zusätzlich `invoice_edit` für den Abruf zulassen, falls Mitarbeiter ohne Shop-Rechte eBay-Bestellungen abrufen sollen |

### Aufgaben ohne Entscheidung

| Nr. | Aufgabe |
| --- | --- |
| A1 | Text `crm_fields.ebayPanelUi.disabled` (21 Sprachen) sagt noch „Oben aktivieren und Zugangsdaten speichern“ — eingeschaltet wird eBay jetzt unter „Verkaufskanäle“ |
| A2 | `dev/lokale-ki-ollama.md` erwähnt `api/ebay/*`, das es nicht mehr gibt |
| A3 | Kommentare, die auf die alte Anbindung zeigen (Herkunftsangaben in `channels/ebay.php`, `channels/ebay_orders.php`, `shop-ebay-status.vue`) sind als Herkunft gewollt; bei O20 mit aufräumen |
| A4 | Die Prüfprogramme dieser Umsetzung liegen nur im Scratchpad der Sitzung. Für dauerhafte Tests bräuchte es eine Testumgebung mit kivitendo-Schema; die Nachbildung reichte für Logik und SQL, nicht für Faktura und Buchung |

### Prüfungen vor der Inbetriebnahme

Nicht geprüft, weil ohne echten Mandanten, eBay-Zugang oder Browser nicht
möglich:

| Nr. | Prüfung | Betrifft |
| --- | --- | --- |
| P1 | Upstall der Shop-Erweiterung auf einer Kopie eines echten Mandanten, zweimal hintereinander | Schema, Übernahmen V6 und V21, Trigger |
| P2 | eBay-Sandbox: Artikel einstellen, Preis und Bestand ändern, abwählen, Kanal aus- und einschalten, Bilder ändern | eBay-Modul, Trigger, Warteschlange |
| P3 | eBay-Sandbox: Bestellung abrufen — Kunde, Rechnung, Buchung, Lagerbuchung, Bestandsabgleich | Import, V28 |
| P4 | Cron `backend/cli/ebay-orders.php` über mehrere Mandanten | Cron |
| P5 | Faktura: Beleg öffnen, Position hinzufügen, buchen — mit und ohne im Voraus eingetragenen Steuersatz | V24 |
| P6 | Lagerbuchung gegen die echte Datenbank (weitere Trigger auf `inventory`, etwa `check_bin_wh_inventory`) | V28 |
| P7 | Artikelkarte im Browser: Kanäle, Aufschlag, Vorschau, Texte, Bilder hochladen, umsortieren, übernehmen | Schritt 2, Schritt 5 |
| P8 | Firmenkonfiguration im Browser: Verkaufskanäle, eBay-Gruppe, Geheimnisse bleiben leer und erhalten, Lagerplatz | Schritt 3, Schritt 5 |
| P9 | HugoShop abschalten und einschalten, je einmal mit „Entwurf“ und „Entfernen“, in beiden Betriebsarten (lokal, HugoCMS) | V16 |
| P10 | Shop-UI nach dem Abgleich des Webseiten-Pakets: „Shop geschlossen“, „Nicht mehr erhältlich“, Kauf eines abgewählten Artikels | V16, V23 |
| P11 | PayPal-Sandbox: Kauf mit Kanalaufschlag und Rundung auf ,99, Bruttopreise und Nettopreise in den Stammdaten | V2, Befund Warenkorb |
| P12 | Bridge (`run.php`), falls noch in Betrieb: neue Artikel erscheinen im HugoShop | V7, V25 |

### Einrichtung für den Betrieb

1. Upstall der Shop-Erweiterung ausführen (legt Tabellen, Funktionen,
   Trigger an und übernimmt HugoShop-Artikel und alte eBay-Daten).
2. Reiter Shop → Rechnungsstellung: „Lagerplatz für Verkäufe“ wählen (V28).
3. Reiter Shop → Verkaufskanäle: Aufschlag und Rundung je Kanal; eBay
   einschalten.
4. Reiter Shop → eBay: Zugangsdaten, **öffentliche Adresse für Bilder**
   (Pflicht, https, von eBay erreichbar), Kategorie, Zustand, Lagerort und
   die drei Policies; „Verbindung testen“.
5. Im eBay-Verkäuferkonto „Out-of-Stock Control“ einschalten — sonst beendet
   eBay Angebote bei Bestand 0 (V19).
6. Cron für den Läufer (`tools/shop-publish.php`) und den Bestellabruf
   (`backend/cli/ebay-orders.php`) prüfen; `install/install.sh` legt den
   zweiten an.
7. Bei Nettopreisen in den Stammdaten und Rundung auf ,99 die Empfehlung der
   Shop-Übersicht beachten (V2d).
8. Refresh-Token bei eBay erneuern, falls die Firmenkonfiguration vor der
   Behebung der Lücke (Schritt 5, Teil 1) von Personen geöffnet wurde, die
   keinen Zugriff auf das eBay-Konto haben sollten.

## Plan Schritt 5: eBay-Kanal

Grundlage: V12 bis V15. Der eBay-Kanal ersetzt `backend/api/ebay/` und
übernimmt alle Funktionen. Bestand der bisherigen Anbindung:

| Funktion heute | Ort heute | Im eBay-Kanal |
| --- | --- | --- |
| OAuth (Refresh-Token, Zugriffstoken mit Ablauf), `ebayApiGet`/`ebayApiSend`, Fehlertexte | `backend/api/ebay/ebay.php` | übernommen nach `backend/api/shop/channels/ebay/`, Verhalten unverändert |
| Zugang: `ebay_enabled`, Umgebung (Produktion/Sandbox), Marktplatz, Client-ID, Client-Secret, Refresh-Token | CRM-Reiter der Firmenkonfiguration, `defaults_oserp` | Karte „eBay" unter „Verkaufskanäle"; eingeschaltet = `sales_channel_shop.active`; Geheimnisse bleiben in `defaults_oserp` und werden nie ausgeliefert |
| Angebotsvorgaben: Kategorie, Zustand, Zahlungs-, Rücknahme-, Versand-Policy, Lagerort, Währung, Menge | CRM-Reiter | Kanalvorgaben in `sales_channel_shop.settings`; Kategorie und Zustand je Artikel überschreibbar (`parts_channel_shop.settings`); feste Menge entfällt (O11) |
| Angebot einstellen (Inventory Item, Offer, Publish) und beenden (Withdraw), Stand je Artikel | `listings.php`, `ebay_listings` | über die Warteschlange mit Kanalbezug (Schritt 4), Stand in `parts_channel_shop` (`external_id`, `sync_status`, `sync_error`). Preis aus `shop_channel_price(…, 'ebay')` brutto, Titel und Beschreibung aus dem Kanal, Menge aus dem Bestand |
| Bilder: hochladen, auflisten, löschen, Reihenfolge; Dateien unter `data/<db>/parts/<id>/`, öffentlich über `backend/webhook/part-image.php` | `listings.php`, `ebay_part_images` | Bildverwaltung je Kanal (V12), mit Übernahme aus anderen Kanälen (O9); öffentliche Auslieferung bleibt |
| Karte „eBay-Artikel" in der Artikelmaske (Bilder, Schalter, Stand) | `article.edit.view.vue` | entfällt; Bereich „eBay" in der Shop-Karte mit Aufschlag, Texten, Bildern, Kategorie, Zustand und Stand |
| Bestellimport: Zeitfenster seit letztem Abruf, Kunde ohne Dubletten (eBay-Käufer, dann Adressvergleich, sonst neu), Rechnung brutto, Buchung, Sperre gegen Doppelimport, Ersatzartikel für unbekannte SKU, Mitarbeiter | `import.php`, `ebay_orders`, Einstellungen `ebay_default_parts_id`, `ebay_employee_login`, `ebay_order_last_check` | übernommen; Tabelle `ebay_orders` bleibt (O13); Lagerbuchung prüfen (O12) |
| Abruf per Cron über alle Mandanten | `backend/cli/ebay-orders.php` | bleibt als Aufruf erhalten, ruft den Kanal auf; nur für Mandanten mit Shop-Erweiterung und eingeschaltetem eBay-Kanal |
| Verbindungstest, manueller Abruf, Statusanzeige | `orders.php`, `ebay-status.config.vue` | in der Karte „eBay" unter „Verkaufskanäle" |
| Kernfunktionen der Faktura für den Import, Kundenzusammenführung | `faktura.php`, `accounting/customer_matching.php` | bleiben im Kern und werden weiter genutzt |

Reihenfolge:

1. Schritt 4: Kanalmodule unter `backend/api/shop/channels/` und
   Warteschlange mit `channel_id`; HugoShop als erstes Modul. Umgesetzt.
2. eBay-Modul: Zugang und API übernehmen, Karte „eBay" unter
   „Verkaufskanäle", Einstellungen übernehmen.
3. Angebote über die Warteschlange, Preis, Texte, Menge (O11).
4. Bildverwaltung je Kanal mit Übernahme (V12, O9).
5. Bestellimport und Cron übernehmen, Lagerbuchung (O12).
6. Bereich „eBay" in der Shop-Karte; alte Karte, alter CRM-Abschnitt und
   `backend/api/ebay/` entfernen; Datenübernahme im Upstall (O10, O13).

## Schritte

1. **Datenmodell und Preisfunktion.** Umgesetzt, siehe oben.
2. **Artikelkarte** mit Kanalauswahl, Aufschlag und Texten je Kanal. Umgesetzt, siehe oben.
3. **Kanalvorgaben** mit Aufschlag und Rundung in der Firmenkonfiguration. Umgesetzt, siehe oben.
4. **Gemeinsamer Aufbau der Kanäle** und Warteschlange mit Kanalbezug. Umgesetzt, siehe oben.
5. **eBay** mit Bestandsabgleich und Bestellimport. Umgesetzt, siehe oben.
6. **Amazon** (V27), nach Klärung von O15.
7. **Prüfungen P1 bis P12** vor der Inbetriebnahme, Einrichtung nach
   „Einrichtung für den Betrieb“.
8. **Folgepunkte** nach Entscheidung: O16 bis O25, Aufgaben A1 bis A4.
