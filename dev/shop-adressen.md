# Shop: Rechnungs- und Lieferadressen

Stand 2026-10-08. Status: **umgesetzt**, nicht gegen die Datenbank
getestet. Entscheidungen E1–E6 am 2026-10-08 wie vorgeschlagen getroffen.

## Ausgangslage

Die Shop-Erweiterung nutzt dieselben Tabellen wie kivitendo:

- **Rechnungsadresse** = Anschrift im Kundenstamm (`customer.street`,
  `zipcode`, `city`, `country`). `ar` speichert keine Kopie.
- **Lieferadressen** = `shipto` mit `module = 'CT'`, `trans_id` = Kunde
  (Lieferadressen des Kundenstamms). Die Rechnung verweist über
  `ar.shipto_id` darauf.
- Ohne Lieferadresse gilt die Rechnungsadresse (`shopInvoiceShippingAddress`,
  Druck in `print.php`, DHL in `_getDhlRecipientAddress`).

kivitendo kennt daneben die **belegeigene Lieferadresse**: eine
`shipto`-Zeile, die einem Beleg gehört (`module` des Belegs, bei Rechnungen
`'AR'`, `trans_id` = Beleg). Damit bleibt die Anschrift eines Belegs stehen,
auch wenn sich der Kundenstamm ändert. Zusätzliche Rechnungsadressen
(`additional_billing_addresses`, `ar.billing_address_id`) nutzt die Faktura
von OpensourceERP; der Shop nicht.

### Wie der Shop heute Adressen schreibt

| Weg | Was entsteht |
| --- | --- |
| Konto anlegen (`customerRegister`) | `customer` mit Rechnungsadresse; optional eine Lieferadresse `'CT'` als Standard (`customer_ext.hugoshop_shipto_id`) |
| Kundenkonto: Lieferadressen (`shiptoCreate/Update/Delete`) | ändert und löscht `'CT'`-Zeilen direkt |
| Kundenkonto: Rechnungsadresse (`customerUpdateBillingAddress`) | ändert `customer` direkt |
| Kasse mit neuer Adresse (`shopInvoiceShiptoId`) | neue `'CT'`-Zeile, `ar.shipto_id` zeigt darauf |
| Kasse mit gespeicherter Adresse | `ar.shipto_id` zeigt auf die vorhandene `'CT'`-Zeile |
| Kasse ohne Lieferadresse | `ar.shipto_id` leer, es gilt der Kundenstamm |
| Gastbestellung | je Bestellung ein eigener Kunde (`customer_ext.hugoshop_guest`) |
| eBay, neuer Käufer (`shopEbayResolveCustomer`) | `customer` mit der eBay-Lieferadresse als Rechnungsadresse, kein `shipto` |
| eBay, bekannter Käufer | nichts; die Adresse der neuen Bestellung steht nur in `ebay_orders.raw` |

## Probleme

1. **Alte Rechnungen ändern ihre Lieferadresse.** Ändert der Kunde im Konto
   eine Lieferadresse, gilt das auch für frühere Rechnungen, die darauf
   zeigen (Rechnungsseite, Kundenkonto, neu erzeugtes PDF, DHL-Etikett).
   Löscht er sie, scheitert das Löschen oder die Rechnung verliert ihre
   Lieferadresse — je nach Fremdschlüssel auf `ar.shipto_id` (noch zu
   prüfen).
2. **Alte Rechnungen ändern ihre Rechnungsadresse.** Gleiches Verhalten wie
   in kivitendo (kein Abbild in `ar`), hier aber vom Kunden ausgelöst.
3. **eBay-Stammkunden:** Die Lieferadresse einer späteren Bestellung geht
   verloren; Rechnung und DHL-Etikett tragen die alte Anschrift.
4. **Gäste:** Mehrere Bestellungen desselben Gastes ergeben mehrere
   gleichlautende Kunden.

## Vorschlag

### S1 — Belegeigene Lieferadresse für jede Shop-Rechnung

`createShopInvoice` kopiert die Lieferadresse der Bestellung in eine
`shipto`-Zeile mit `module = 'AR'` und `trans_id` = Rechnung und setzt
`ar.shipto_id` darauf. Quelle ist die gewählte `'CT'`-Zeile, die neue Adresse
aus der Kasse oder — ohne Lieferadresse — der Kundenstamm (E1).

Umsetzung in der Datenbank: Funktion
`shop_invoice_shipto(ar_id, quelle_shipto_id)`, die kopiert und `ar.shipto_id`
setzt — ein Aufruf in derselben Transaktion wie die Rechnung.

Folgen:
- Ändern und Löschen im Kundenkonto wirken nur noch auf künftige
  Bestellungen.
- Rechnungsseite, Kundenkonto, Druck und DHL lesen weiter `ar.shipto_id`
  und brauchen keine Änderung.
- Eine neue Adresse aus der Kasse wird zusätzlich als `'CT'` gespeichert,
  damit der Kunde sie wieder wählen kann (wie heute) — bei Gästen nicht (E2).

### S2 — eBay: Lieferadresse je Bestellung

`shopEbayImportOrder` legt aus `fulfillmentStartInstructions[0].shippingStep.shipTo`
die belegeigene Lieferadresse (`'AR'`) an — für neue und bekannte Käufer.
Der Kundenstamm eines bekannten Käufers bleibt unverändert.

### S3 — Faktura: belegeigene Lieferadresse anzeigen

Die Auswahl „Lieferadresse“ in der Rechnungsansicht lädt nur `'CT'`-Zeilen
(`faktura.php`, `shiptos`). Eine `'AR'`-Zeile stünde dort nicht in der Liste.
Ergänzung: die Zeile, auf die der Beleg zeigt, kommt mit in die Liste,
gekennzeichnet als „Lieferadresse dieses Belegs“. Das ist eine Änderung im
Kern von OpensourceERP (keine Schemaänderung) und nützt auch Belegen, die
kivitendo selbst mit eigener Lieferadresse angelegt hat.

### S4 — Bestehende Rechnungen nachziehen (einmalig, E3)

Upstall: für Shop-Rechnungen (`ar_link_hugoshop`, `ebay_orders`), deren
`ar.shipto_id` auf eine `'CT'`-Zeile zeigt, eine `'AR'`-Kopie anlegen und
umhängen. Was bereits geändert wurde, lässt sich nicht zurückholen — die
Kopie friert den heutigen Stand ein. Für eBay-Rechnungen ohne `shipto_id`
kann die Adresse aus `ebay_orders.raw` kommen.

## Entscheidungen (2026-10-08, wie vorgeschlagen)

| Nr. | Frage | Vorschlag |
| --- | --- | --- |
| E1 | Auch ohne abweichende Lieferadresse eine `'AR'`-Kopie (aus dem Kundenstamm)? | Ja — dann bleibt die Lieferanschrift jeder Shop-Rechnung stehen, auch wenn der Kunde später seine Rechnungsadresse ändert |
| E2 | Neue Adresse aus der Kasse bei Gästen auch als `'CT'` speichern? | Nein — Gäste können sie nicht wiederverwenden |
| E3 | Bestehende Rechnungen nachziehen (S4)? Schreibt in die kivitendo-Tabellen `shipto` und `ar` (Daten, kein Schema) | Ja, einmalig im Upstall |
| E4 | Rechnungsadresse einfrieren (Problem 2)? Möglich über `additional_billing_addresses` + `ar.billing_address_id`; die Zeile gehört aber dem Kunden und erscheint in seiner Liste | Nein — wie in kivitendo lassen; das archivierte PDF hält den Stand fest |
| E5 | Faktura-Anzeige (S3) im Kern ändern? | Ja |
| E6 | Gäste zusammenführen (Problem 4), etwa über die E-Mail-Adresse? | Eigenes Thema, nicht Teil dieses Umbaus |

## Befunde vor der Umsetzung

- Eine Lieferadresse, auf die eine Rechnung zeigt, lässt sich im Kundenkonto
  nicht löschen (Rückmeldung 2026-10-08) — der Fremdschlüssel verhindert es.
  Nach S1/S4 zeigen Shop-Rechnungen nicht mehr auf Adressen des Kontos; das
  Löschen gelingt dann.
- `shipto.module` ist in den vorhandenen Zeilen NULL (Adressen aus der Zeit
  der Bridge; `shiptoCreate` schreibt seit Langem `'CT'`). Solche Zeilen
  gelten im Shop weiter als Adressen des Kundenstamms
  (`COALESCE(module, 'CT') = 'CT'`). Die Faktura und die Kundenverwaltung
  zeigen nur `'CT'` — dort fehlten sie. Das Schema-Update trägt deshalb
  `'CT'` nach, wo `trans_id` ein Kunde oder Lieferant ist (2026-10-08).
- Alle Shop-Abfragen auf `shipto` filterten nur über `trans_id` = Kunde. Mit
  belegeigenen Zeilen (`trans_id` = Rechnung) hätte ein Kunde die
  Lieferadresse der Rechnung sehen und ändern können, deren Nummer seiner
  Kundennummer entspricht. Alle Abfragen in `account.php` und `cart.php`
  filtern jetzt zusätzlich auf das Modul.

## Umsetzung

| Teil | Wo |
| --- | --- |
| Kopie anlegen und `ar.shipto_id` umhängen, Gast-Quelle entfernen | `shop_invoice_shipto(ar_id, quelle, adresse, gast_aufraeumen)` in `backend/upstall/shop/company_schema.sql` |
| eBay-Adresse aus dem Rohdatensatz | `shop_ebay_ship_to(raw)` |
| Bestand nachziehen (S4) | im selben Abschnitt, läuft bei jedem Schema-Update und findet nach dem ersten Mal nichts mehr |
| Kasse (S1) | `createShopInvoice` (`lib/invoice.php`), in der Transaktion der Rechnung |
| eBay (S2) | `shopEbayImportOrder` (`channels/ebay_orders.php`) |
| Modul-Filter | `lib/account.php`, `lib/cart.php` |
| Faktura (S3) | `getFakturaData` (`faktura/faktura.php`): Beleg-Lieferadresse in der Liste, `document_own`; Hinweis „Lieferadresse dieses Belegs“ in `customer.info.card.vue`. Nicht bei Einkaufsrechnungen (`ap`) |

Gäste: Die Lieferadresse muss als Zeile bestehen, solange die Bestellung
durch Kasse und PayPal läuft — nur ihre Kennung reist über PayPal
(`custom_id`). Deshalb entsteht sie weiter als `'CT'` und wird nach dem
Kopieren entfernt (E2).

Grenzfall: Wandelt die Faktura einen Beleg um (`shipto_id` wird kopiert),
zeigt der neue Beleg auf die Lieferadresse des alten. Sie erscheint dort als
„Lieferadresse dieses Belegs“, gehört aber dem Ausgangsbeleg — für die
Anzeige und den Druck ohne Folgen.

## Offen

- Prüfung gegen die Datenbank: Schema-Update mit Bestandsübernahme,
  Kauf als Kunde und als Gast (mit und ohne PayPal), eBay-Import, Faktura.
- Ob `ap` eine Spalte `shipto_id` hat, ist nicht geprüft; deshalb bleibt die
  Faktura-Ergänzung dort aus.
- Kundenstamm löschen (`customer_vendor.php`) entfernt nur `'CT'`-Zeilen;
  `'AR'`-Zeilen gehören zur Rechnung und bleiben — richtig so.
