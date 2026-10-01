# Shop: Versandarten und Versandkosten

Stand 2026-10-01. Status: **in Umsetzung** — alle Entscheidungen getroffen
(1–8, W1–W13, Punkt 7 Weg a, P1), Tabellenentwurf freigegeben. Schritte
1 und 3 bis 6 erledigt, Schritt 9 zum Teil.

Bisher gibt es einen Pauschalbetrag (Versandartikel `shop_shipping_partnumber`,
bei Werkzeug24 Artikel 8 zu 7,90 € netto), der ab einem Warenwert
(`shop_free_shipping_from`, 150 €) entfällt. Kivitendo selbst kennt keine
Versandkosten: nur Lieferbedingungen als Text (`delivery_terms`), Versandart
und Versandort als Freitext am Beleg und das Gewicht für den Druck.

## Gilt für

Nur die Verkaufskanäle (HugoShop und eBay), nicht Belege, die in der Faktura
von Hand entstehen (Entscheidung 7).

## Entscheidungen (2026-10-01)

| Nr. | Frage | Entscheidung |
| --- | --- | --- |
| 1 | Wie werden die Kriterien kombiniert? | Preis aus Versandart × Länderzone × Gewichtsstufe, wahlweise mit Stückzahlstufe; Abmessungen schließen eine Versandart aus, statt den Preis zu bestimmen |
| 2 | Wer wählt die Versandart? | Dem Artikel kann eine Versandart zugeordnet werden; ohne Zuordnung gilt die günstigste passende. Der Kunde wählt nicht |
| 3 | Bestellung über der Grenze einer Versandart | Versandart ausschließen, keine Aufteilung in mehrere Pakete |
| 4 | Freigrenze | je Verkaufskanal |
| 5 | Mindestabnahme | je Artikel |
| 6 | Lieferbedingung | Lieferbedingungen von Kivitendo (`delivery_terms`) als Auswahlkatalog, nur je Artikel |
| 7 | Wo gelten Versandkosten? | nur in den Verkaufskanälen (Shop und eBay) |
| 8 | Länder | Länderliste mit ISO-Code einführen, die vorhandenen Freitexte darauf abbilden |

## Vorab behoben: Versandzeile im Warenkorb (2026-10-01)

Die Versandkosten wurden beim Start der PayPal-Zahlung als Zeile in den
Warenkorb geschrieben und nur über die Seite `/bezahlung-abgebrochen/` wieder
entfernt. Bei jedem anderen Abbruch (Zurück-Taste, PayPal geschlossen, Sitzung
abgelaufen) blieb sie liegen:

- „Versand" stand als Artikel im Warenkorb,
- die angezeigte Summe enthielt den Versand zweimal,
- nach nachgelegter Ware war der Versand trotz Freigrenze fällig,
- mit gelöschter Ware entstand eine PayPal-Bestellung über reine
  Versandkosten.

Jetzt (`lib/cart.php`, `lib/payment.php`):

- Summen, Positionsliste und Positionszahl zählen nur Ware; der Versand wird
  gerechnet (`cartTotals`).
- PayPal bekommt Warenwert plus Versand, ohne dass eine Zeile entsteht.
- Die Versandzeile entsteht erst mit der Rechnung (`cartApplyShipping`) und
  wird dort auf den richtigen Stand gebracht: genau einmal mit Menge 1, oder
  entfernt, wenn kein Versand anfällt.

Getestet in einer zurückgerollten Transaktion: liegengebliebene Zeile,
Freigrenze überschritten, nur Versand im Korb, beschädigte Menge; der
PayPal-Betrag stimmt mit der Rechnung überein.

## Prüfung auf Widersprüche und Realisierbarkeit

Erste Runde (W1–W8), entschieden 2026-10-01:

| Nr. | Befund | Entscheidung |
| --- | --- | --- |
| W1 | Mehrere Artikel, verschiedene zugeordnete Versandarten; der Kunde wählt nicht, aufgeteilt wird nicht | Versandarten haben einen Rang (Spedition vor Paket); es gilt die ranghöchste der zugeordneten. Artikel ohne Zuordnung schließen sich an |
| W2 | Die zugeordnete (bzw. ranghöchste) Versandart passt nicht: Land, Gewicht, Abmessungen | Bestellung sperren, mit Hinweis im Warenkorb |
| W3 | Keine Versandart passt | Hinweis im Warenkorb, Bezahlen nicht möglich |
| W4 | eBay rechnet den Versand über die Versandrichtlinie des Angebots | eBay rechnet den Versand selbst; die Rechnung übernimmt den Betrag von eBay. Staffeln, Freigrenze und Lieferländer der Versandarten wirken nur im HugoShop |
| W5 | eBay kennt keine Mindestmenge je Käufer | Artikel mit Mindestabnahme bei eBay als Los anbieten |
| W6 | Freigrenze auch für teure Versandarten? | Freigrenze je Kanal, dazu je Versandart ein Schalter „Freigrenze gilt" |
| W7 | Lieferbedingung je Artikel, Kivitendo führt sie aber je Beleg | Lieferbedingung je Artikel, auch in der Bestätigung an den Kunden (wie bei Amazon) — siehe „Punkt 7: Machbarkeit" |
| W8 | Bei keinem Artikel ist ein Gewicht gepflegt | Regel „Versand auf Anfrage" für Artikel ohne Gewicht, dazu eine Liste der Artikel zum Nachpflegen |

Zweite Runde (W9–W13), entschieden 2026-10-01 — Spalte „Vorschlag" gilt,
wo nicht anders vermerkt:

| Nr. | Befund | Vorschlag / Entscheidung |
| --- | --- | --- |
| W9 | **„Versand auf Anfrage" sperrt heute den ganzen Shop.** Kein Artikel hat ein Gewicht (W8); mit W2/W3 wäre ab dem ersten Tag keine Bestellung mehr möglich | Die Regel gilt nur, wenn die maßgebliche Versandart das Gewicht braucht: mehr als eine Gewichtsstufe oder ein Höchstgewicht. Die heutige Pauschale wird zur Versandart „Standard" mit einer Stufe — sie braucht kein Gewicht, der Shop läuft weiter, und die Staffeln greifen mit dem Nachpflegen |
| W10 | **Lose bei eBay** (W5) ändern Menge und Preis: eBay verkauft ein Los, OSERP führt Stück | Angebot: Preis je Los = Stückpreis × Losgröße, verfügbare Menge = Bestand ÷ Losgröße (abgerundet). Rechnung aus der eBay-Bestellung: Menge = Lose × Losgröße, Stückpreis = Lospreis ÷ Losgröße. **Bestätigt** |
| W11 | **Lange Lieferzeiten bei eBay.** eBay rechnet selbst (W4); auch die Bearbeitungszeit kommt aus der Versandrichtlinie und ist dort nach meiner Kenntnis auf höchstens 30 Tage begrenzt. „Versandfertig in 4–8 Wochen" lässt sich bei eBay nicht abbilden | **Entschieden:** Artikel mit einer Lieferbedingung über dieser Grenze werden bei eBay nicht angeboten |
| W12 | **Lieferländer je Kanal bei eBay.** Die Tabelle `sales_channel_country_shop` wirkt bei eBay nicht; dort bestimmt die Versandrichtlinie die Länder (W4) | Für eBay in der Oberfläche ausblenden. Ohne Widerspruch, nur zur Kenntnis |
| W13 | **Grenze der Freigrenze.** Heute ist der Versand bis einschließlich des Grenzwerts fällig (`<=`): bei genau 150 € kostet er noch | **Entschieden:** künftig frei ab dem Grenzwert (`>=`) |

### Punkt 7: Machbarkeit

**Was der Kunde heute bekommt.** Eine eigene Bestellbestätigung gibt es nicht.
Nach der Zahlung entsteht sofort die Rechnung (`createShopInvoice`), und der
Kunde bekommt:

1. die Rechnungsmail (`templates/invoice.de.php`): Text mit Rechnungsnummer
   und Betrag, ohne Positionen,
2. das Rechnungs-PDF im Anhang, gedruckt über die Kivitendo-kompatiblen
   LaTeX-Vorlagen (`backend/api/print/print.php`, Vorlagensatz aus
   `defaults.templates`),
3. die Rechnungsseite im Shop (`/rechnung/`).

**Was Kivitendo hergibt.** Die Lieferbedingung sitzt am Beleg
(`ar.delivery_term_id`), eine je Rechnung; die Vorlagen drucken sie unten als
„Lieferung: …". Je Position gibt es keine. Gedruckt wird je Position aber der
**Langtext** (`invoice.longdescription`), in allen drei mitgelieferten
Vorlagensätzen (RB, marei, mersiha).

**Machbar, auf zwei Wegen:**

| Weg | Wie | Bewertung |
| --- | --- | --- |
| a) Langtext | Beim Anlegen der Rechnung wird der Langtext der Lieferbedingung an den Langtext der Position angehängt („Versandfertig in 4–8 Wochen") | **Gewählt (2026-10-01).** Kein weiteres Schema; wirkt sofort in jedem Vorlagensatz, auch in eigenen des Mandanten. Der Text ist ein Schnappschuss: Ändert sich später die Lieferbedingung des Artikels, bleibt die Rechnung, wie sie verschickt wurde — so soll es sein |
| b) Eigene Druckvariable | Je Position eine Variable `delivery_term` aus einer eigenen Tabelle (Schnappschuss je Rechnungsposition) | Sauberer getrennt, aber eine neunte Tabelle (nicht im freigegebenen Entwurf) und Änderungen an jeder Druckvorlage — eigene Vorlagensätze des Mandanten zeigen sonst nichts |

Dazu, unabhängig vom Weg:

- **Rechnungsmail:** Liste der Positionen mit Lieferbedingung im Text, wie in
  der Bestätigung bei Amazon.
- **Rechnungsseite im Shop:** Lieferbedingung je Position.
- **Vor dem Kauf:** Produktseite, Warenkorb und Kasse zeigen die Lieferbedingung
  je Artikel. Rechtlich ist das nach meinem Verständnis der wichtigere Ort:
  Im Fernabsatz muss die Lieferzeit vor der Bestellung genannt werden
  (Art. 246a § 1 EGBGB). Das ist keine Rechtsberatung.
- **Übersetzungen:** Die Lieferbedingungen von Kivitendo sind übersetzbar
  (`generic_translations`); der Shop kann die Übersetzung seiner Sprache
  zeigen.
- **Kopf der Rechnung:** `ar.delivery_term_id` bleibt leer, die Angabe steht je
  Position.

Weitere Befunde, ohne Widerspruch:

- **Abmessungen einer Bestellung.** Wie mehrere Artikel in ein Paket passen,
  lässt sich nicht verlässlich rechnen. Geprüft wird je Artikel: der größte
  Artikel muss in die Grenzen der Versandart passen (längste Kante,
  Gurtmaß). Dazu die Summe der Gewichte.
- **Länder.** In `customer.country` stehen bei Werkzeug24 „Deutschland",
  „DE", „Deutschland " (mit Leerzeichen), „Österreich", „Brandenburg",
  leere Werte und NULL. „Brandenburg" ist kein Land, leere Werte haben
  keines: Die Zuordnung braucht eine Durchsicht von Hand. Leer gilt als
  Mandantenland (`defaults.address_country` = „Germany"). Die Freitextspalten
  der Kivitendo-Tabellen bleiben, wie sie sind; die Zuordnung steht in einer
  eigenen Tabelle. Die Ländernamen in der Oberfläche kommen aus dem ISO-Code
  (`Intl.DisplayNames`), nicht aus der Datenbank.
- **Freigrenze.** Heute gilt der Versand bis einschließlich 150 €
  (`<=`); „frei ab 150 €" hieße `<`. Beim Umbau auf die Freigrenze je Kanal
  anzugleichen.
- **DHL.** Die vorhandene DHL-Anbindung (`backend/api/dhl/shipping.php`,
  `dhl_shipments`, Produkte V01PAK usw.) erstellt Etiketten. Eine Versandart
  mit Anbieter DHL kann ihr DHL-Produkt mitführen; das ist eine spätere
  Stufe.
- **Lieferanten.** Bei Werkzeug24 gibt es keinen einzigen Lieferanten.
  Versandarten mit Anbieter setzen voraus, dass DHL, Spedition usw. als
  Lieferanten angelegt werden; eine allgemeine Versandart kommt ohne aus.
- **Mindestmenge.** `parts.order_qty` ist in Kivitendo die Bestellmenge im
  Einkauf (Disposition), nicht die Mindestmenge im Verkauf — ein eigenes Feld
  ist nötig.

## Tabellenentwurf (freigegeben 2026-10-01)

Keine Kivitendo-Tabelle wird geändert. Namen mit der Endung `_shop` wie
`sales_channel_shop`.

| Tabelle | Inhalt |
| --- | --- |
| `country_shop` | Länderliste: `iso_code` (ISO 3166-1 alpha-2, Schlüssel), `sortkey`, `eu` |
| `country_alias_shop` | Freitext → Land: `alias` (bereinigt: getrimmt, klein), `iso_code` |
| `shipping_method_shop` | Versandart: Name, `vendor_id` (Anbieter, leer = allgemein), `parts_id` (Versandartikel für Buchung und Steuer), `rank` (W1), Grenzen (`max_weight`, `max_length`, `max_girth`), `free_shipping_applies` (W6), `ebay_fulfillment_policy_id` (W4: die Versandrichtlinie für Angebote mit dieser Versandart; leer = die allgemeine aus der Kanal-Einstellung), `active` |
| `shipping_zone_shop` / `shipping_zone_country_shop` | Länderzonen und ihre Länder |
| `shipping_rate_shop` | Preis je Versandart, Kanal (leer = alle), Zone, `weight_from`, `qty_from` — es gilt die Zeile mit den höchsten erreichten Schwellen |
| `sales_channel_country_shop` | Lieferländer je Kanal; keine Zeile = keine Begrenzung |
| `parts_shipping_shop` | je Artikel: `shipping_method_id` (Entscheidung 2), `length`, `width`, `height`, `min_qty` (5; bei eBay die Losgröße, W5/W10), `delivery_term_id` (6, Verweis auf `delivery_terms`) |

Dazu die Freigrenze je Kanal: als Spalte `free_shipping_from` in
`sales_channel_shop` (eigene Tabelle der Erweiterung) — sie ersetzt
`shop_free_shipping_from`. Das Gewicht bleibt in `parts.weight`.

Gerechnet wird in der Datenbank (eine Funktion: Warenkorb, Kanal, Land →
Versandart und Preis), damit Warenkorb, PayPal und Rechnung denselben Betrag
sehen.

## Schritte

1. Warenkorb-Fehler — **erledigt**, siehe oben.
2. W1–W13 entscheiden, Tabellenentwurf freigeben — **erledigt 2026-10-01**.
3. Länderliste und Zuordnung der Freitexte, mit Durchsicht von Hand —
   **erledigt 2026-10-01**, siehe unten.
4. Versandarten, Zonen, Preise: Tabellen, Pflege in der Firmenkonfiguration —
   **erledigt 2026-10-01**, mit der Freigrenze je Kanal (aus Schritt 7
   vorgezogen), siehe unten.
5. Artikel: Versandart, Abmessungen, Mindestabnahme, Lieferbedingung in der
   Artikelmaske (Shop-Karte); Lieferbedingung auf der Produktseite —
   **erledigt 2026-10-01**, mit Gewichtsfeld und Liste „ohne Gewicht"
   (W8), siehe unten.
6. Berechnung im HugoShop: Warenkorb, PayPal, Rechnung; Hinweise bei
   W2/W3/W8; Mindestabnahme im Warenkorb — **erledigt 2026-10-01**, siehe
   unten. Dazu P1 (Lieferadresse bei PayPal) und die Lieferländer-Prüfung aus
   Schritt 7.
7. Lieferländer je Kanal (Freigrenze: erledigt in Schritt 4).
8. eBay: Versandrichtlinie je Versandart, Lose, lange Lieferzeiten (W4, W5,
   W10, W11).
9. Lieferbedingung in Rechnung (Weg a oder b), Rechnungsmail und
   Rechnungsseite (Punkt 7). **Rechnung (Weg a), Warenkorb und Kasse
   erledigt** mit Schritt 6; offen: Rechnungsmail und Rechnungsseite.

## Stand der Umsetzung

### Schritt 3: Länder (2026-10-01)

**Schema** (`backend/upstall/shop/company_schema.sql`, Abschnitt LÄNDER):

- `country_shop`: 249 Länder nach ISO 3166-1 alpha-2, davon 27 EU. Per
  `INSERT … ON CONFLICT DO NOTHING` in der Schema-Datei, nicht als CSV: der
  CSV-Import des Upstalls leert die Tabelle vorher mit `TRUNCATE`, und das
  verweigert PostgreSQL bei einer Tabelle, auf die ein Fremdschlüssel zeigt.
- `country_alias_shop`: 3467 Zuordnungen aus
  `company_data/country_alias_shop.csv` — ISO-Codes und Ländernamen in den
  21 Sprachen der Anwendung, dazu Kurzformen wie „BRD", „USA", „UK".
  Doppeldeutiges („Kongo") fehlt bewusst. Erzeugt mit
  `tools/shop-country-aliases.php` aus den ICU-Daten. Wie jede CSV des
  Upstalls nur in eine leere Tabelle; von Hand Zugeordnetes bleibt bei
  späteren Updates erhalten.
- `shop_country_key(text)`: bereinigt einen Freitext (getrimmt, Leerräume
  zusammengefasst, klein).
- `shop_country_code(text)`: Freitext → ISO-Code, NULL wenn nicht
  zugeordnet. Leer meint das Mandantenland (`defaults.address_country`).
- Die Ländernamen der Oberfläche kommen aus dem Code
  (`Intl.DisplayNames`), nicht aus der Datenbank.

**Durchsicht von Hand:** Firmenkonfiguration → Shop → „Länder"
(`shop-countries.config.vue`, API `getShopCountryMapping`,
`saveShopCountryAlias`). Jeder Freitext aus Kunden-, Liefer- und weiteren
Rechnungsadressen mit der Zahl der Adressen; nicht Zugeordnetes oben,
Zuordnung per Auswahl, sofort gespeichert und als „von Hand" vermerkt.

**Getestet** in einer zurückgerollten Transaktion an Werkzeug24 (Schema,
CSV-Import wie im Upstall, beide Aufrufe): „Deutschland " mit Leerzeichen,
„DE", leere Felder (Mandantenland „Germany") → DE; „Österreich" → AT,
„België" → BE, „USA" → US. Nicht zugeordnet: „Brandenburg" (3 Adressen),
„test" (2), „auch Hier", „Land", „AUT" — dreibuchstabige Codes liefern die
ICU-Daten nicht. Zuordnen, Entfernen, unbekannter Code und leerer Text
verhalten sich wie beschrieben.

### Schritt 4: Versandarten, Zonen, Preise (2026-10-01)

**Schema** (Abschnitt VERSAND): alle Tabellen des freigegebenen Entwurfs —
`shipping_method_shop`, `shipping_zone_shop`, `shipping_zone_country_shop`,
`shipping_rate_shop`, `sales_channel_country_shop`, `parts_shipping_shop` —
und `sales_channel_shop.free_shipping_from`. `parts_shipping_shop` und
`sales_channel_country_shop` werden erst in Schritt 5 bzw. 7 benutzt.

- Ein Land gehört höchstens zu einer Zone (Schlüssel `iso_code`).
- Preisstufe: Kanal und Zone leer = alle; es gilt die genaueste Stufe (eigener
  Kanal vor allen, eigene Zone vor „alle übrigen Länder", dann die höchste
  erreichte Gewichts- und Stückzahlstufe). Ohne passende Stufe liefert die
  Versandart nicht dorthin.
- Gewichte in der Einheit von `parts.weight` (`defaults.weightunit`),
  Abmessungen in cm.

**Übernahme** beim Upstall, je einmal:

- Die Pauschale wird zur Versandart „Standard" (W9): Versandartikel aus
  `shop_shipping_partnumber`, Rang 0, „Freigrenze gilt", eine Preisstufe für
  alle Kanäle und Länder zum Preis des Versandartikels.
- `shop_free_shipping_from` wird zur Freigrenze des HugoShop-Kanals; der
  Schlüssel entfällt.

**Freigrenze je Kanal** (Entscheidung 4, W13): in der Karte
„Verkaufskanäle", nicht bei eBay (W4). Der Warenkorb rechnet ab sofort mit ihr,
frei **ab** dem Grenzwert, verglichen mit dem Bruttowarenwert wie bisher. Leer
= keine Freigrenze. Die Produktseiten bekommen sie wie bisher als
`free_shipping_from`.

**Pflege:** Firmenkonfiguration → Shop → Versand → „Versandarten"
(`shop-shipping.config.vue`; API `getShopShipping`, `saveShopShippingMethod`,
`deleteShopShippingMethod`, `saveShopShippingZone`, `deleteShopShippingZone`,
`searchShopShippingParts`). Speichern nach jeder Änderung; eine Versandart samt
ihren Preisstufen ist eine Anweisung. Stufen werden per `ON CONFLICT`
aktualisiert und nur die entfallenen gelöscht — Löschen und Neuanlegen
derselben Stufe in einer Anweisung lehnt PostgreSQL ab (eindeutiger
Schlüssel).

**Übergangsstand bis Schritt 6:** Der Warenkorb rechnet den Versand noch mit
dem Versandartikel aus `shop_shipping_partnumber` und dessen Verkaufspreis,
nicht mit den Versandarten. Preisänderungen in der neuen Karte wirken dort
erst mit Schritt 6. Die Freigrenze wirkt schon.

**Befunde bei Werkzeug24:**

- Der Versandartikel 8 „Versand" (und 8008) ist **ausgemustert**. Die Suche
  nach Versandartikeln zeigt deshalb auch ausgemusterte, gekennzeichnet und
  hinten.
- `defaults.weightunit` ist „t" (Tonnen); Kivitendo-Vorgabe ist „kg".
  Gewichtsgrenzen und -stufen gelten in dieser Einheit — vor dem Pflegen der
  Gewichte zu klären.

**Getestet** in einer zurückgerollten Transaktion an Werkzeug24: Schema
zweimal angewandt; Übernahme (Standard 7,90 €, Freigrenze 150 im HugoShop,
Schlüssel entfernt); Zone anlegen, Land wechselt die Zone, unbekannter Code
fällt weg; Versandart anlegen, ändern (Preis aktualisiert, Stufen entfallen
und kommen hinzu), doppelte Stufe, Grenze 0, unbekannter Artikel, unbekannte
Versandart; Zone löschen nimmt ihre Stufen mit; Kanal: Freigrenze ohne Feld
unverändert, leer = keine, negativ abgelehnt; Warenkorb: genau am Grenzwert
frei, darunter fällig, ohne Grenzwert fällig.

### Schritt 5: Versandangaben je Artikel (2026-10-01)

**Gewicht in der Artikelmaske.** Die Artikelmaske von OSERP hatte kein
Gewichtsfeld — ohne es ließe sich die Liste „ohne Gewicht" nicht abarbeiten.
`parts.weight` ist ein Kivitendo-Stammdatum und steht deshalb in der
allgemeinen Artikelmaske (Stammdaten), nicht in der Shop-Karte: `getPart`
liefert `weight` und `weightunit`, `updatePart` nimmt `weight` (leer = nicht
gepflegt, sonst eine Zahl ab 0; fehlt das Feld, bleibt es). Nur am angelegten
Artikel: `createPart` übernimmt kein Gewicht.

**Shop-Karte, Bereich „Versand"** (`part-shop.card.vue`): Versandart (leer =
„günstigste passende"), Lieferbedingung aus den Kivitendo-Lieferbedingungen
(ungültige nur, wenn gewählt; der Langtext erscheint als Hinweis), Länge,
Breite, Höhe in cm, Mindestabnahme. Dazu das Gewicht aus den Stammdaten zur
Ansicht, mit Hinweis, wenn es fehlt. Gespeichert mit den übrigen
Shop-Angaben (`savePartShopData`, Feld `shipping`, eine Anweisung; fehlt das
Feld, bleiben die Angaben). Wie alle Shop-Angaben nur, solange der Artikel
angeboten wird.

**Produktseite:** `shopPageData` liefert `delivery_term` (Langtext, sonst
Kurztext) und `min_qty`. Beide Vorlagensätze zeigen sie über dem
Warenkorb-Knopf; die Standardvorlage schreibt sie zusätzlich ins Front Matter
(`deliveryTerm`, `minQuantity`). Neue Hilfe `shopQuantity()` für Mengen ohne
überflüssige Nullen.

**Neu veröffentlichen:** Trigger `trigger_parts_shipping_shop_auto_publish` —
ändern sich Lieferbedingung oder Mindestabnahme, entsteht `publish_part` für
jeden aktiven Kanal des Artikels (HugoShop nur bei `shop_auto_publish`).
Versandart und Abmessungen stehen auf keiner Seite und lösen nichts aus.
Ändert jemand den **Text** einer Lieferbedingung in Kivitendo, wird nicht
neu veröffentlicht (kein Trigger auf der Kivitendo-Tabelle) — dann „Alle
veröffentlichen".

**Liste „ohne Gewicht"** (W8): Filter „Nur ohne Gewicht" in der
Artikelliste (`searchParts`, `weight_missing`; Dienstleistungen zählen nicht,
sie werden nicht verschickt), sichtbar mit Shop-Erweiterung, vorbelegbar mit
`?weight=missing`. Die Shop-Übersicht hat dafür den Weg „Artikel ohne
Gewicht" (angeboten und ohne Gewicht). Bei Werkzeug24: 3711 angebotene Waren
ohne Gewicht.

**Noch nicht:** Lieferbedingung in Warenkorb, Kasse und Rechnung (Schritte 6
und 9), Mindestabnahme im Warenkorb (Schritt 6), Lose bei eBay (Schritt 8).

**Getestet** in einer zurückgerollten Transaktion an Werkzeug24: Schema
zweimal angewandt; Laden mit Auswahllisten; Speichern mit Versandangaben (ein
Auftrag `publish_part`), Änderung nur von Länge und Versandart (kein Auftrag),
Speichern ohne Feld `shipping` (unverändert), negative Länge und unbekannte
Versandart abgelehnt; Gewicht setzen, ungültig abgelehnt, ohne Feld
unverändert; Filter „ohne Gewicht" mit und ohne Gewicht; Produktseite in
beiden Vorlagensätzen.

### Schritt 6: Berechnung im HugoShop (2026-10-01)

**Entscheidung P1 (2026-10-01): Lieferadresse bei PayPal.** Bisher legte der
Kunde die Lieferadresse bei PayPal fest; OSERP erfuhr sie erst nach der
Zahlung, den Betrag schickte es aber vorher. Mit Preisen je Land hätte der
Versand falsch bezahlt sein können. Jetzt: Der Kunde wählt die Lieferadresse
in der Kasse wie beim Kauf auf Rechnung; OSERP rechnet damit und gibt sie fest
an PayPal (`shipping_preference: SET_PROVIDED_ADDRESS`, bei PayPal nicht
mehr änderbar). Die Kennung der Adresse reist als `custom_id` mit und kommt
mit der Zahlung zurück; die Rechnung nimmt dieselbe Adresse.

**Datenbank** (Abschnitt VERSAND):

- `shop_cart_shipping(Warenkorb, Land, Bruttowarenwert)`: Versandart und Preis
  nach den Regeln W1–W13 — ranghöchste zugeordnete Versandart, sonst die
  günstigste passende; Gewicht, längste Kante, Gurtmaß; Preisstufe nach Kanal,
  Zone, Gewicht, Stückzahl; Freigrenze; Lieferländer des Kanals (Schritt 7,
  Prüfung schon hier). Status: `ok`, `no_goods`, `country_unknown`,
  `country_not_delivered`, `weight_missing` (Versand auf Anfrage),
  `assigned_unfit`, `no_method`. Fehlende Abmessungen gelten als passend.
- `shop_is_shipping_part(parts_id)`: Versandartikel (einer Versandart oder
  der bisherige) sind nie Ware.

**Kein Versand mehr im Warenkorb.** Der Versand ist keine Zeile in
`cart_parts_hugoshop` mehr, auch nicht während der Rechnungsstellung:
`cartTotals` rechnet ihn, die Rechnung fügt die Versandposition selbst ein
(Versandartikel der Versandart, ihre Bezeichnung, der berechnete Preis).
`cartApplyShipping` und `cartRemoveShipping` entfallen. Der Fehler aus dem
Vorab-Schritt kann damit grundsätzlich nicht wiederkehren.

**Lieferland:** gespeicherte Lieferadresse (nur eine des Kunden), neue
Adresse oder Rechnungsadresse (`shopShippingCountry`). Der Warenkorb rechnet
mit der Standard-Lieferadresse des Kontos, die Kasse mit der gewählten — sie
rechnet bei jeder Auswahl neu (`checkout` mit `adresses`). PayPal vom
Warenkorb aus nimmt die Standard-Lieferadresse, aus der Kasse die gewählte.

**Behoben nebenbei:** Eine in der Kasse gewählte gespeicherte Lieferadresse
kam nie auf der Rechnung an — `invoicing` verwarf die Angabe bei `default`,
und die Rechnung las `shipto_id` statt `id`. `shopDeliveryAddress`
vereinheitlicht die Formen der Kasse.

**Sperren** (`cartRequireShipping`): beim Kauf auf Rechnung und vor PayPal
`SHIPPING_COUNTRY_UNKNOWN`, `SHIPPING_COUNTRY_NOT_DELIVERED`,
`SHIPPING_ON_REQUEST`, `SHIPPING_ASSIGNED_UNFIT`, `SHIPPING_NO_METHOD`,
`CART_MIN_QUANTITY`. Nach einer PayPal-Zahlung wird nicht gesperrt — das Geld
ist da, die Rechnung muss entstehen.

**Mindestabnahme:** Hineinlegen und Mengenänderung heben auf die
Mindestabnahme an; „−" an der Mindestabnahme entfernt die Position.

**Lieferbedingung** (Punkt 7, Weg a): je Position in Warenkorb und Kasse,
und in der Rechnung im Langtext der Position.

**Shop-Widget** (`shop-ui`, Bündel neu gebaut, beide auf dem Stand des
Quelltexts): Versandart oder „versandkostenfrei", Sperrgrund statt Betrag,
Kaufknöpfe gesperrt; Lieferbedingung und Mindestabnahme je Position; die
Kasse gibt ihre Adresswahl an den Warenkorb und an PayPal (neue Adresse wird
vorher angelegt). Texte Deutsch und Englisch.

**Grenzen:**

- Gäste: Der Warenkorb rechnet mit dem Land aus dem Gastformular. PayPal setzt
  wie bisher ein Kundenkonto voraus.
- Ob PayPal `custom_id` auf Ebene der Bestellung oder nur in den Buchungen
  zurückgibt, ist nicht gegen PayPal geprüft — gelesen werden beide; fehlt
  sie, gilt die Rechnungsadresse.
- `shop_shipping_partnumber` bleibt nur noch, um den bisherigen
  Versandartikel als Nicht-Ware zu erkennen.

**Getestet** in einer zurückgerollten Transaktion an Werkzeug24 (mit
Sicherungspunkten statt innerer Transaktionen): Regeln der Funktion an 13
Fällen; Mindestabnahme beim Hineinlegen und Ändern; Warenkorb DE (DHL 4,99) und
Kasse mit Lieferadresse AT (Standard 7,90, weil DHL dort 14,99 kostet);
PayPal-Adresse; Rechnung an die AT-Adresse mit Betrag gleich Kasse (43,24),
Versandposition, Lieferbedingung im Langtext, gebucht, Warenkorb geleert;
Sperren bei unbekanntem Land und unter Mindestabnahme; ab Freigrenze keine
Versandposition. Nicht getestet: ein echter Durchlauf mit PayPal, das Widget im
Browser.
