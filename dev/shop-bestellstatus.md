# Shop: Bestellstatus und Lieferstatus

Stand 2026-10-08. Status: **in Umsetzung** — alle Entscheidungen getroffen,
Tabelle `ar_status_shop` freigegeben.

Bestellungen aus den Verkaufskanälen (HugoShop und eBay) bekommen zwei
Status, die in der Liste der Bestellungen der Shop-Erweiterung stehen:

| Bestellstatus | Schlüssel |
| --- | --- |
| Offen | `open` |
| In Bearbeitung | `processing` |
| Abgeschlossen | `completed` |
| Abgebrochen | `cancelled` |

| Lieferstatus | Schlüssel |
| --- | --- |
| Offen | `open` |
| Teilweise versandt | `partially_shipped` |
| Versandt | `shipped` |
| Teilretour | `partially_returned` |
| Retour | `returned` |
| Abgebrochen | `cancelled` |

## Ausgangslage

- Eine Shop-Bestellung ist eine Rechnung (`ar`). Beim HugoShop kommt
  `ar_link_hugoshop` dazu (Link, Kanal, PayPal-Stand), bei eBay
  `ebay_orders`. Auftrag (`oe`) oder Lieferschein legt der Shop nicht an.
- Die Liste der Bestellungen zeigte bisher nur HugoShop-Bestellungen.
- Einen Liefer- oder Bestellstatus gab es nirgends. Ableitbar sind nur
  einzelne Ereignisse: DHL-Etikett zur Rechnung (`dhl_shipments`,
  `record_type = 'invoice'`), stornierte Rechnung (`ar.storno`), Zahlung
  (`ar.paid`, `ar_link_hugoshop.payment_status`).
- `ebay_orders.order_status` wird nur beim Import geschrieben und danach
  nicht mehr abgeglichen — als Quelle für den Lieferstand taugt er nicht.

## Entscheidungen (2026-10-08)

| Nr. | Frage | Entscheidung |
| --- | --- | --- |
| 1 | Von Hand oder automatisch? | Beides: von Hand in der Liste, dazu Automatik bei eindeutigen Ereignissen. Von Hand gesetzt hat immer Vorrang |
| 2 | „Teilweise“ mit Mengen je Position? | Nein, der Status allein reicht |
| 3 | eBay-Bestellungen | Kommen in die Liste der Bestellungen |
| 4 | Für den Kunden sichtbar? | Lieferstatus auf der Rechnungsseite im Shop und Mail bei Änderung — beides in der Firmenkonfiguration (Shop) einstellbar, Vorgabe aus. Nur HugoShop; eBay benachrichtigt seine Käufer selbst |
| 5 | Folgen von Abgebrochen/Retour | Keine — nur Kennzeichnung, keine Rückerstattung, keine Lagerbuchung |
| 6 | Speicherort | Eigene Tabelle `ar_status_shop` (Schlüssel `ar_id`) für beide Kanäle; `ar_link_hugoshop` und `ebay_orders` bleiben unverändert |
| 7 | Bestellstatus automatisch? | Teilweise: Storno → Abgebrochen; bezahlt und versandt → Abgeschlossen; Lieferstatus nicht mehr offen → In Bearbeitung |
| 8 | Bestellstatus für den Kunden? | Nein, nur intern |

## Umsetzung

### Tabelle `ar_status_shop`

| Spalte | Bedeutung |
| --- | --- |
| `ar_id` | Rechnung, Primärschlüssel, `ON DELETE CASCADE` |
| `order_status` | von Hand gesetzter Bestellstatus, `NULL` = automatisch |
| `order_mtime` | letzte Änderung von Hand |
| `delivery_status` | von Hand gesetzter Lieferstatus, `NULL` = automatisch |
| `delivery_mtime` | letzte Änderung von Hand |
| `delivery_notified` | zuletzt per Mail gemeldeter Lieferstatus |
| `delivery_notified_mtime` | Zeitpunkt dieser Mail |

Eine Zeile entsteht erst, wenn jemand einen Status setzt oder eine Mail
verschickt wird. Ohne Zeile gilt alles automatisch.

### Automatik (`shop_order_state(ar_id)`)

Die Datenbankfunktion liefert je Rechnung den wirksamen und den abgeleiteten
Status beider Arten. Abgeleitet wird so:

- Lieferstatus: Rechnung storniert → Abgebrochen; DHL-Etikett zur Rechnung
  → Versandt; sonst Offen.
- Bestellstatus (aus dem *wirksamen* Lieferstatus): storniert oder Lieferung
  abgebrochen → Abgebrochen; bezahlt und versandt → Abgeschlossen;
  Lieferung nicht mehr offen → In Bearbeitung; sonst Offen.
- Bezahlt: `ar.paid` deckt den Betrag, PayPal meldet `COMPLETED` oder die
  Bestellung kam über eBay (dort wird vor dem Import bezahlt).
  Dieselbe Regel gilt für die Spalte „Zahlung“ der Liste (`is_paid`), den
  Filter „Nur unbezahlte“ und „Abgeschlossen“ — eine gebuchte Überweisung
  zählt überall als bezahlt. Schwebend/gescheitert zeigt die Spalte nur,
  solange nicht bezahlt.

### Mail zum Lieferstatus

- Schalter `shop_delivery_status_mail` (Vorgabe aus), nur HugoShop, nur an
  Kunden mit E-Mail-Adresse, nie für „Offen“.
- Von Hand gesetzt: Mail sofort beim Speichern.
- Automatisch (DHL-Etikett): Der Läufer (`tools/shop-publish.php`) prüft nach
  jedem Lauf die Bestellungen, deren Etikett oder Statusänderung höchstens
  zwei Tage alt ist, und meldet jeden Status nur einmal
  (`delivery_notified`). Das Zeitfenster verhindert, dass beim Einschalten
  des Schalters alle alten Bestellungen eine Mail bekommen.
- Vorlage `backend/api/shop/templates/delivery-status.de.php`.

### Rechnungsansicht

Bei aktiver Shop-Erweiterung zeigt die Rechnungsansicht (`/rechnung/<id>`,
`faktura.view.vue`) über den Positionen die Karte „Shop-Bestellung“
(`invoice-shop-status.card.vue`): Verkaufskanal, Bestell- und Lieferstatus
mit derselben Auswahl wie in der Liste (`shop-order-status.chip.vue`) und
den zuletzt per Mail gemeldeten Lieferstatus. Die Karte erscheint nur bei
Rechnungen aus einem Verkaufskanal (`getShopOrderStatus` liefert sonst
`null`) und nur mit dem Recht `shop_order` oder `edit_shop_config`.

### Anzeige im Shop

Schalter `shop_delivery_status_show` (Vorgabe aus), nur HugoShop:

- Rechnungsseite `<shop-invoice>` (Kaufabschluss, `/rechnung/?link=…`):
  `getInvoiceSummary` liefert `delivery_status`. Bleibt auch im
  Kaufabschluss stehen (Entscheidung 2026-10-08) — dieselbe Seite öffnet der
  Link in der Mail zum Lieferstatus später wieder.
- Kundenkonto `<shop-account-orders>`: Liste und Detailansicht der
  Bestellungen (`personalOrders`, `personalOrder`).

### Link in der Mail

Die Rechnungsseite ist sonst nur über die Weiterleitung nach dem Kauf
erreichbar; Gäste haben kein Kundenkonto. Die Mail zum Lieferstatus enthält
deshalb den Link auf die Rechnungsseite mit dem Rechnungslink
(`shopInvoicePageUrl`). Der Pfad steht je HugoShop in der neuen Einstellung
„Pfad der Rechnungsseite“ (`invoice_page`, Vorgabe `/rechnung/` wie
`billing-page` in `<shop-checkout>`). Ohne Basisadresse des Kanals entfällt
der Link.

### Liste der Bestellungen

HugoShop- und eBay-Bestellungen gemeinsam, mit Kanal, Bestellstatus und
Lieferstatus als Chip. Ein Klick auf den Chip öffnet die Auswahl:
„Automatisch“ (mit dem abgeleiteten Wert) oder einer der Werte von Hand.

## Offen

- Prüfung gegen die Datenbank und den Mailversand (aus der Sandbox nicht
  möglich): Schema-Update, `getShopOrders`, `setShopOrderStatus`, Mail.
- Das neu gebaute Shop-UI-Bündel (`shop-widgets.js`) erreicht die Webseiten
  erst mit „Shop-Benutzerschnittstelle installieren“ bzw. dem nächsten
  Auftrag „Shop-Benutzerschnittstelle aktualisieren“.
