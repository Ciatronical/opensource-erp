# Spezialwerkzeug im Shop: Stand und offene Aufgaben

Stand 03.10.2026. Spezialwerkzeuge (Lager → Spezialwerkzeug) lassen sich im
HugoShop zum Verleih und zum Kauf anbieten; Besucher finden sie über die
Werkzeugsuche nach HSN/TSN oder Fahrgestellnummer. Dieses Dokument beschreibt,
was fertig ist, wie es zusammenhängt, und was noch zu tun ist.

Verwandt: `dev/shop-betrieb.md` (Läufer, Aufträge), `dev/shop-verkaufskanaele.md`
(Kanäle, Preise), `dev/shop-veroeffentlichung.md` (Produktseiten).

## Was fertig ist

| Teil | Ort | Was es tut |
| --- | --- | --- |
| Shop-Felder am Werkzeug | `backend/upstall/lxcars/company_schema.sql`, Tabelle `special_tools_lxcars` | `purchase_price`, `sale_price`, `rental_price`, `rental_days`, `shop_sell`, `shop_rent`, `parts_id` (Verkaufsartikel), `rental_parts_id` (Mietartikel) |
| Angebot speichern | `backend/api/lxcars/special_tools.php`, `saveSpecialToolShopOffer` | Vorgaben: Verkaufspreis = Einkaufspreis, Miete = ein Drittel des Einkaufspreises (änderbar). Legt je Angebot einmalig einen Artikel an (Verkauf: Ware, Miete: Dienstleistung, Nummern aus dem Nummernkreis), schreibt `parts_ext` mit Kategorie `Spezialwerkzeug` bzw. `Werkzeugverleih` und der Fahrzeugliste aus den Regeln als technische Daten, nimmt die Artikel in alle HugoShop-Kanäle auf und stellt `publish_part` ein. Abwählen schaltet die Kanalzeile ab und stellt `remove_part` ein |
| Nachführen | `_stSyncShopArticles()` | Läuft nach jeder KI-Analyse und jeder Regeländerung, damit die Fahrzeugliste auf der Produktseite stimmt |
| Werkzeugsuche im Shop | `backend/api/shop/lib/special_tools.php`, öffentliche Aktion `findSpecialTools` | HSN/TSN → KBA-Profil (`special_tool_profile_from_kba_lxcars`) → Regeln live prüfen (`special_tool_compute_matches_lxcars(..., p_profile)`). FIN → Fahrzeug im eigenen Bestand → Treffer-Cache. Liefert je Werkzeug Miet- und Kaufangebot mit Kanalpreis (netto/brutto), Verfügbarkeit, Produktseite |
| Widget | `shop-ui/src/components/shop-tool-finder.js`, Shortcode `shop-tool-finder.html` | Formular (HSN/TSN oder FIN, optional Motorcode und Erstzulassung), Fahrzeuganzeige, Werkzeugkarten mit „Jetzt mieten" / „In den Warenkorb" (`shop-add-to-cart`). Texte de/en |
| Oberfläche im ERP | Werkzeugdialog, Reiter „Werkzeug", Abschnitt „Im HugoShop anbieten" | Nur bei aktiver Shop-Erweiterung. Übersicht zeigt Chips „Verleih"/„Verkauf" |

Einstellung: `defaults_oserp.special_tools_buchungsgruppen_id` legt die
Buchungsgruppe der angelegten Artikel fest; fehlt sie, gilt die am häufigsten
verwendete Buchungsgruppe. Die Einheit kommt vom häufigsten Artikel der Art.

## Einrichten (Betreiber)

1. Shop-Erweiterung und HugoShop-Kanal wie in `dev/shop-betrieb.md`.
2. Seite für die Werkzeugsuche im Hugo-Projekt anlegen, z. B.
   `content/de/werkzeugverleih.md` mit `{{< shop-tool-finder heading="Werkzeugverleih" >}}`.
   Der Shortcode kommt mit dem Paket (`oserp-shop/layouts/shortcodes/`).
3. Werkzeuge im ERP mit Einkaufspreis versehen und „Zum Verleih / Zum Kauf
   anbieten" einschalten; der Läufer schreibt die Produktseiten.
4. Versandart: Der Läufer schreibt eine Seite nur als Entwurf, wenn keine
   Versandart passt (`shop_part_shipping_check`). Für die Werkzeugartikel also
   Gewicht/Maße oder eine Versandart „Abholung" pflegen (siehe unten).

## Offene Aufgaben

Nach Wichtigkeit. Die ersten drei braucht es, bevor der Verleih produktiv geht.

### 1. Mietabwicklung (fachlich das Wichtigste)

Heute ist die Miete ein Dienstleistungsartikel im Warenkorb — der Kauf läuft
wie jeder andere, mehr nicht. Es fehlt:

- **Mietzeitraum**: Beginn/Ende beim Bestellen wählen, Verfügbarkeit gegen
  bestehende Vermietungen prüfen (ein Werkzeug gibt es nur einmal). Vorschlag:
  Tabelle `special_tool_rentals_lxcars` (tool_id, ar_id/oe_id, customer_id,
  from_date, to_date, deposit, returned_at, status) und eine Sperre je Werkzeug
  und Zeitraum (Exclusion Constraint auf `daterange`).
- **Status automatisch setzen**: Bei Mietbeginn `status = 'lent'` und
  `lent_to` = Kunde, bei Rückgabe wieder `available`. Einstieg: der
  Shop-Rechnungslauf (`backend/api/shop/lib/invoice.php`) kennt die Positionen;
  Mietartikel sind an `special_tools_lxcars.rental_parts_id` erkennbar.
- **Kaution**: Höhe (Vorschlag: Einkaufspreis), Einzug per PayPal/Rechnung,
  Rückzahlung bei Rückgabe, Einbehalt bei Schaden. Eigene Position oder
  eigener Artikel je Werkzeug.
- **Rückgabe und Mahnung**: Rückgabetermin in der Wiedervorlage, Erinnerung
  per E-Mail/WhatsApp (Vorlagen gibt es für Rechnungen und Widerruf), Mahngebühr
  bei Überschreitung, Verlängerung durch den Kunden im Kundenkonto.
- **Mietvertrag/AGB**: Rechtstext für Verleih (Haftung, Verschleiß,
  Rückgabezustand) als Download auf der Produktseite (`parts_ext.hugoshop_downloads`)
  und als Pflichthaken im Checkout.
- **Mietdauer-Staffel**: Heute ein Preis für `rental_days` Tage. Offen:
  Verlängerungstage, Wochenend-/Wochenpreis, Abholung vs. Versand.

### 2. Versand und Abholung

- Werkzeuge haben weder Gewicht noch Maße; ohne passende Versandart bleibt die
  Seite ein Entwurf. Entweder je Werkzeug Maße pflegen (Artikelkarte →
  Shop-Angaben → Versand) oder eine Versandart „Abholung in der Werkstatt"
  anlegen und den Werkzeugartikeln zuordnen. Sinnvoll: beim Anlegen der
  Artikel (`_stSyncShopArticles`) eine konfigurierbare Versandart setzen
  (`defaults_oserp.special_tools_shipping_method_id`).
- Rücksendung bei Versandverleih (Retourenlabel, DHL-Anbindung vorhanden).

### 3. Lagerbestand beim Verkauf

- Ein Werkzeug ist ein Einzelstück. Beim Verkauf muss der Artikel danach aus
  dem Shop verschwinden und das Werkzeug im Lager als verkauft gelten
  (`status`, Lagerbuchung über `shop_stock_bin_id`, V28). Heute hat der
  Verkaufsartikel keinen Bestand (`onhand` 0) — die Seite zeigt „nicht auf
  Lager", der Warenkorb nimmt ihn trotzdem. Vorschlag: beim Anbieten eine
  Einlagerung buchen (`bookStock`, Menge 1), beim Verkauf die Ausbuchung des
  Shops greifen lassen und den Werkzeugdatensatz auf `defective`/verkauft
  setzen oder löschen.

### 4. Fahrgestellnummer vollständig auflösen

- Heute wird die FIN nur gegen die eigenen Fahrzeuge geprüft. Für fremde
  Besucher braucht es einen VIN-Decoder (z. B. DAT, TecDoc/TecAlliance, oder
  der bereits angebundene Fahrzeugschein-Scanner, falls er FIN-Abfragen
  kann). Schnittstelle vorbereitet: `shopFindSpecialTools()` nimmt `fin` und
  liefert `fin_unknown`; ein Decoder müsste HSN/TSN oder ein Profil
  (Hersteller, Modell, Motorcode, ccm, kW, Kraftstoff, Baujahr) liefern, das
  an `special_tool_compute_matches_lxcars(NULL, NULL, NULL, profil)` geht.
- Ohne Decoder: aus dem WMI (erste drei Zeichen) wenigstens den Hersteller
  ableiten und die Hersteller-Vorauswahl im Widget setzen.

### 5. Produktseite und Bilder

- Bilder: Werkzeuge haben noch keine Fotos. Bildverwaltung je Kanal gibt es
  (`parts_channel_image_shop`); Werkzeugfotos ließen sich beim Anlegen
  übernehmen, sobald das Werkzeug ein Bildfeld hat.
- Eigene Vorlage für Werkzeugseiten (Vorlagensatz, `theme.json`): Fahrzeugliste
  hübscher als Tabelle, Hinweis „Passt es zu Ihrem Fahrzeug? → Werkzeugsuche".
- Kategorieübersicht: „Spezialwerkzeug" und „Werkzeugverleih" in
  `category_groups.php` einordnen.

### 6. Kleineres

- Preisregel „ein Drittel" als Einstellung (`defaults_oserp`) statt fest im
  Code (`saveSpecialToolShopOffer`), ebenso Mietdauer-Vorgabe.
- Bruttopreise: Die Vorschau im ERP zeigt Nettopreise; die Seite rechnet den
  Kanalpreis mit Aufschlag und Steuer (`shop_channel_price`). Im Dialog den
  Kanalpreis anzeigen wie in der Artikelkarte.
- Übersetzungen des Widgets in weitere Sprachen (`shop-ui/src/core/i18n.js`
  hat de/en); ERP-Texte unter `SpecialToolsView.shop` nur de/en.
- Nach Bau des Widget-Bundles (`cd shop-ui && npm run build`) gehört das
  Bundle in den Commit; der Läufer verteilt es ins Paket der Webseite.
- Test gegen eine echte Shop-Instanz: Seite anlegen, Läufer laufen lassen,
  Werkzeugsuche im Browser mit HSN 0603 / TSN AFA prüfen.
