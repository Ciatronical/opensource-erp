# Kundenportal im CRM-Kern: Identität, Konto und Webseite aus der Shop-Erweiterung herauslösen

Stand 2026-10-21. Status: **in Umsetzung** — Entscheidungen P1 bis P7 wie
vorgeschlagen bestätigt, Stufen 1 bis 6 in einem Zug, Schemaänderungen
freigegeben; keine Datenübernahme nötig (der HugoShop bei Autoprofis ist noch
nicht produktiv), sonic24 läuft auf der alten Bridge.

Frage: Kunden sollen über die Webseite auf eigene Daten zugreifen — Stammdaten
und Rechnungen aus dem CRM, bei aktiver LxCars-Erweiterung auch Fahrzeuge.
Gehören Anmeldung, Registrierung und Kundenkonto deshalb in den CRM-Kern, mit
dem Warenkorb als Zusatz, der nur mit Shop-Erweiterung und HugoShop-Kanal
erscheint? Die Veröffentlichung der Widgets bleibt bei HugoCMS.

## Ergebnis

Ja. Die Trennung ist fachlich richtig und technisch machbar, weil der heutige
Code schon in zwei Hälften zerfällt: eine Konto-Hälfte, die nur den Kunden
aus `customer` kennt, und eine Handels-Hälfte, die Artikel, Warenkorb und
Zahlung kennt. Was heute alles zusammenhält, ist nicht die Fachlogik, sondern
drei Klammern: der **Schlüssel des HugoShop-Kanals** als Ausweis der
Webseite, die **Sitzung je Kanal** (`context_hugoshop`) und das **eine
Widget-Bündel**. Diese drei wandern in den Kern; der Rest folgt.

Der Umbau ist groß, aber in Stufen möglich, ohne den laufenden Shop (sonic24)
zu unterbrechen: Pfade, Aktionen und Cookie bleiben, nur die Zuständigkeit
wechselt.

## Heutiger Zustand

### Was zur Konto-Hälfte gehört (kennt nur den Kunden)

| Teil | Ort | Bemerkung |
| --- | --- | --- |
| Sitzung, Anmeldung, Abmeldung | `lib/context.php`, Tabelle `context_hugoshop`, Cookie `PORTALCLIENTID` | Anmeldung über `customer.email` + `user_password` |
| Registrierung, Gast, Profil, Adressen, Zahlungsbedingung, Kennwort, E-Mail | `lib/account.php`, `customer_ext.hugoshop_guest`, `hugoshop_shipto_id` | `customer_ext` ist eine Tabelle des CRM-Kerns, die Spalten hängt die Shop-Erweiterung an |
| Rechnungen des Kunden, PDF | `lib/invoice.php` (`customerInvoices`, `customerInvoice`, `shopInvoicePdf`) | listet **alle** Rechnungen des Kunden aus `ar`, nicht nur Shop-Rechnungen; `ar_link_hugoshop` liefert Rechnungslink und Zahlstatus dazu |
| Kontaktformular, Mails an den Kunden | `lib/mail.php`, Vorlagen unter `backend/api/shop/templates/` | |
| Konto-Freischaltung per WhatsApp | geplant, `dev/shop-konto-freischaltung.md` | gehört von Anfang an in den Kern |
| Widgets | `portal-login`, `portal-register`, `portal-account-*` (7), `portal-contact`, `shop-invoice` (Rechnungsseite) — rund 2 900 Zeilen | plus der Widget-Kern (`core/`, 2 364 Zeilen: API-Aufruf, Pfade, Presets, i18n, Bus) |

### Was zur Handels-Hälfte gehört (kennt Artikel, Kanal, Zahlung)

| Teil | Ort |
| --- | --- |
| Warenkorb, Kasse, Versand, PayPal, Widerruf | `lib/cart.php`, `lib/payment.php`, `lib/withdrawal.php`, Tabellen `carts_hugoshop`, `cart_parts_hugoshop`, `withdrawals_hugoshop`, PayPal-Spalten in `ar_link_hugoshop` |
| Produktseiten, Kategorien, Suche, Werkzeugsuche, Veröffentlichung | `lib/publish.php`, `lib/categories.php`, `lib/search.php`, `lib/special_tools.php`, `batchjob_*_hugoshop`, `redirect_pages_hugoshop` |
| Verkaufskanäle (HugoShop, eBay), Aufschläge, Verfügbarkeit | `sales_channel_shop`, `parts_channel_shop` |
| Widgets | `shop-cart`, `shop-checkout`, `shop-add-to-cart`, `shop-search`, `shop-search-results`, `shop-tool-finder` — rund 2 340 Zeilen; Zahlteil von `shop-invoice` |

### Die drei Klammern

1. **Ausweis der Webseite.** `backend/api/shop/public/bootstrap.php` sucht den
   Kopf `X-Portal-Key` in `sales_channel_secret_shop` aller Mandanten; der
   Treffer bestimmt Mandant **und Kanal**. Ohne Shop-Erweiterung gibt es die
   Tabelle nicht, also keinen Zugang.
2. **Sitzung je Kanal.** `context_hugoshop` trägt `channel_id`; Warenkorb,
   Kunde und Kanal hängen an derselben Zeile.
3. **Ein Bündel, ein Paket.** `portal-ui/` baut `shop-widgets.js`; das Paket
   `oserp-shop/` (Vorlagen, Shortcodes, Weiterleiter `shop-api/index.php`,
   404-Seite) geht je HugoShop-Kanal an HugoCMS. HugoCMS legt den Paketnamen
   und die signierten PHP-Pfade fest (`ShopSync::PACKAGE_DIR`, `SIGNED_PHP`).

Die Webseite selbst — Adresse, HugoCMS-Zugang, Freigaben, Pfade der Seiten
(`params.portalui.paths`), Adresse des Shop-Zugangs, Signatur — ist heute eine
Eigenschaft des HugoShop-Kanals (`shop_channel_setting_keys()`), obwohl sie
mit dem Handel nichts zu tun hat.

## Zielbild

### Kern: „Webseite“ und „Kundenportal“

- Neue Einheit **Webseite** im Kern (Tabelle `website_oserp` mit
  Geheimnis-Tabelle, oder Erweiterung der Kanaltabelle — siehe
  Entscheidungen): Name, Basisadresse, HugoCMS-Adresse und -Schlüssel,
  Freigaben-Abfrage, Pfade der Seiten, Adresse des Portal-Zugangs,
  Webseiten-Schlüssel (der heutige Shop-Schlüssel), Signatur, Vorlagensatz.
- **Öffentlicher Zugang** des Kerns: `backend/api/portal/` mit dem heutigen
  Unterbau (Schlüssel → Mandant und Webseite, Kontext-Cookie, CORS,
  Aktionsliste). Die Aktionsliste wird **zusammengesetzt**: Kern-Aktionen
  (Kontext, Anmeldung, Registrierung, Konto, Adressen, Rechnungen, Kontakt,
  Freischaltung) plus die Aktionen der aktiven Erweiterungen, die sich mit
  einer Datei `public/actions.php` je Erweiterung registrieren (Shop:
  Warenkorb, Kasse, Zahlung, Suche, Widerruf; LxCars: Fahrzeuge). Der Pfad
  `/shop-api/` auf der Webseite und das Cookie bleiben, damit laufende
  Shops weiterlaufen.
- **Sitzung** im Kern (`context_hugoshop` → Zuständigkeit Kern, später
  umbenannt): `customer_id`, `website_id`, Gast. Der Warenkorb hängt dann
  über `cart_uuid` an der Sitzung wie heute, die Spalte gehört der
  Shop-Erweiterung.
- **Kundenkonto** im Kern: `lib/context.php`, `lib/account.php`, der
  Rechnungsteil von `lib/invoice.php` (Liste, Einzelrechnung, PDF,
  Rechnungslink ohne PayPal), `lib/mail.php` mit Vorlagen,
  `account_activation` (Freischaltung). `customer_ext.hugoshop_guest` und
  `hugoshop_shipto_id` werden Kern-Spalten (`portal_guest`, `portal_shipto_id`).
- **Veröffentlichung** im Kern: Paket (Shortcodes, Partials, Weiterleiter,
  404-Seite, Widget-Bündel), Abgleich mit HugoCMS (`lib/hugocms.php`),
  Signatur (`lib/signing.php`), Läufer. Produktseiten, Kategorien und Medien
  bleiben in der Shop-Erweiterung und hängen sich an den Lauf der Webseite.

### Shop-Erweiterung: HugoShop-Kanal = Webseite + Handel

- Der HugoShop-Kanal verweist auf eine Webseite (`website_id`) und behält
  nur, was Handel ist: Aufschläge, Verfügbarkeit, PayPal, Kategorie-Pfade,
  Adressmuster der Produktseiten, Lieferstatus, Versand. Eine Webseite ohne
  HugoShop-Kanal ist ein reines Kundenportal (Autoprofis heute); mit Kanal
  kommt der Handel dazu.
- Registriert beim Kern: öffentliche Aktionen, Widgets, Paketteile
  (Produktvorlagen, Kategorien), Zusatzspalten an der Sitzung (`cart_uuid`)
  und an der Rechnung (`ar_link_hugoshop`: PayPal-Felder).

### LxCars-Erweiterung

- Registriert öffentliche Aktionen für den angemeldeten Kunden: seine
  Fahrzeuge (`cars_lxcars.customer_id`), HU-Termine, Dokumente, Termine —
  nur lesend, nur eigene Datensätze (Prüfung wie bei `customerInvoice`:
  `customer_id` steht in jeder Bedingung).
- Widget `portal-vehicles` im eigenen Bündel.

### Widgets

- `portal-ui/` wird `portal-ui/` mit drei Bündeln aus einem Quellbaum:
  `portal-widgets.js` (Kern: Anmeldung, Registrierung, Konto, Rechnungen,
  Kontakt, Freischaltung), `shop-widgets.js` (Warenkorb, Kasse, Artikel,
  Suche, Werkzeugsuche, Zahlteil der Rechnungsseite), `lxcars-widgets.js`
  (Fahrzeuge). Der gemeinsame Kern (`core/`) wird einmal gebündelt und von
  den Erweiterungsbündeln als `window.oserpPortal` genutzt — sonst läge Lit
  dreimal auf der Seite.
- Das Partial `portal-ui-assets.html` lädt Kern und die Bündel der aktiven
  Erweiterungen; welche das sind, schreibt der Lauf in die Konfiguration des
  Pakets (`config.json`), nicht die Webseite.
- Die Elementnamen bleiben zunächst (`portal-login`, `shop-cart`), damit die
  Seiten der Webseiten nicht angefasst werden müssen; neue Elemente heißen
  `portal-*`. Umbenennen mit Alias ist ein späterer, eigener Schritt.

### HugoCMS

- Mechanisch unverändert: Abgleich, Übertragung, Übernahme, Medien, Bau,
  signiertes PHP. Nur die Benennung passt nicht mehr („Shop-Erweiterung“,
  `[shop]`, Paket `oserp-shop/`, Freigabe „Produktseiten“). Für die ersten
  Stufen bleibt sie; eine Umbenennung in „OpensourceERP-Anbindung“ mit
  Paket `oserp-portal/` braucht eine HugoCMS-Änderung mit Übergangsfrist
  (beide Paketnamen annehmen). Die Freigabe „Produktseiten“ ist dann eine
  Freigabe der Shop-Erweiterung, die der Kern nur durchreicht.

## Datenmodell und Umzug

| Heute (Shop) | Ziel (Kern) | Umzug |
| --- | --- | --- |
| `sales_channel_secret_shop.public_key` | `website_secret_oserp` | Schlüssel übernehmen, Kanal verweist auf Webseite; laufende Webseiten behalten ihren Schlüssel |
| `context_hugoshop` (Sitzung) | `portal_context_oserp` | Sitzungen sind flüchtig: Umbenennen genügt, offene Sitzungen überleben |
| `customer_ext.hugoshop_guest`, `hugoshop_shipto_id` | `portal_guest`, `portal_shipto_id` | Spalten umbenennen |
| `ar_link_hugoshop` | Link-Teil in `invoice_link_oserp`, PayPal-Teil bleibt im Shop | Tabelle teilen oder behalten mit Zuständigkeit Kern; teilen ist sauberer, behalten ist billiger |
| Einstellungen der Webseite im Kanal (`hugocms_url`, `hugocms_key`, `base_url`, `backend_url`, `paths`, Freigaben, Signatur, Vorlagensatz) | Webseite | Schema-Update überführt sie je HugoShop-Kanal in eine Webseite und setzt `website_id` |
| `shop_template_set`, Paket `backend/templates-default/shop/<satz>/kit/` | Vorlagensatz der Webseite; Shop legt Produktvorlagen dazu | Verzeichnisse umhängen |

Alle betroffenen Tabellen gehören OpensourceERP; Kivitendo-Tabellen bleiben
unberührt. Jede Umbenennung ist eine Schemaänderung mit Freigabe.

## Abhängigkeiten und Reihenfolge der Erweiterungen

Heute: Kern (crm) → shop → (lxcars unabhängig). Ziel: Kern mit Portal →
shop und lxcars hängen daran. Das Schema-Update muss die Kernteile zuerst
anlegen; `upstall/crm` bekommt die Portaltabellen, `upstall/shop` setzt sie
voraus. Die Aktivierungsliste (`extensions_oserp`) entscheidet, welche
öffentlichen Aktionen und Bündel es gibt.

## Grenzen und Risiken

- **Laufender Betrieb (sonic24):** Pfad `/shop-api/`, Cookie, Aktionen und
  Elementnamen bleiben in jeder Stufe gleich; die Umstellung besteht aus
  Umzügen hinter derselben Schnittstelle. Jede Stufe endet mit einem Lauf
  gegen die Testwebseite.
- **Kanal und Webseite:** Die Kanalkarte zerfällt in zwei Karten (Webseite,
  Handel). Viele Stellen lesen `shopChannelValue($db, $kanal, …)` für
  Webseiten-Einstellungen; sie müssen auf die Webseite des Kanals umgestellt
  werden. Das ist der größte Posten.
- **Mehrere Webseiten je Mandant** bleiben möglich (eine je HugoShop-Kanal
  heute); ein Portal ohne Handel ist dann nur eine Webseite ohne Kanal.
- **Datenschutz bei Portaldaten:** Was der Kunde sieht, ist je Aktion
  festzulegen (Rechnungen: alle seine; Fahrzeuge: nur eigene; keine internen
  Notizen). Die Prüfungen nach dem Muster von `customerInvoice` (Kundennummer
  in jeder Bedingung) sind Pflicht.
- **Rechte im Backend:** Für die Pflege von Webseiten braucht es ein eigenes
  Recht (`website_edit`) statt `edit_shop_config`.
- **HugoCMS-Benennung:** nicht zwingend, aber die Oberfläche von HugoCMS
  spricht sonst dauerhaft von „Shop“ bei einem Werkstattportal.
- **Aufwand:** grob zwei bis drei Wochen Arbeit über alle Stufen, davon der
  größte Teil in Stufe 2 (Webseite als Einheit) und in den Tests.

## Entscheidungen (offen)

| Nr. | Frage | Vorschlag |
| --- | --- | --- |
| P1 | Webseite als eigene Tabelle oder als Kanalart „website“ in `sales_channel_shop`? | eigene Tabelle im Kern — die Kanaltabelle gehört der Shop-Erweiterung und darf ohne sie nicht nötig sein |
| P2 | Tabellen umbenennen oder nur die Zuständigkeit wechseln? | Stufe 1 nur Zuständigkeit (keine Datenbewegung), Umbenennung in Stufe 3 mit Freigabe |
| P3 | Bündel | ein Kernbündel plus je Erweiterung eines; den gemeinsamen Teil (Lit, Widget-Kern) legt esbuild als geteilte Datei daneben (`splitting`), statt ihn über ein globales Objekt zu reichen — kein eigener Lader, Lit nur einmal |
| P4 | Elementnamen | bleiben `shop-*` bis zum Ende, dann Alias `portal-*` |
| P5 | HugoCMS umbenennen | umgesetzt 2026-10-22 ohne Übergangsfrist — alle angebundenen Webseiten sind Testseiten |
| P6 | Rechnungslink | `ar_link_hugoshop` teilen: Link und Zahlstatus-Anzeige in den Kern, PayPal-Felder bleiben |
| P7 | Portalzugriff auf LxCars | Fahrzeuge, HU-Termine, Dokumente des Kunden, lesend; Umfang je Datensatz vor der Umsetzung festlegen |

## Reihenfolge der Umsetzung

1. **Widgets trennen** (ohne Datenbankänderung): Quellbaum `portal-ui/`,
   drei Bündel, Partial lädt nach aktiven Erweiterungen. Ergebnis: Eine
   Webseite kann Konto ohne Warenkorb zeigen. Freischaltung per WhatsApp
   (`dev/shop-konto-freischaltung.md`) gleich im Kernbündel.
2. **Webseite als Einheit** im Kern: Tabelle, Karte „Webseiten“, Umzug der
   Einstellungen aus dem HugoShop-Kanal, Kanal verweist auf Webseite; der
   öffentliche Zugang sucht den Schlüssel in der Webseitentabelle. Schema-
   Freigabe nötig.
3. **Öffentlicher Zugang und Konto** in den Kern (`backend/api/portal/`,
   `lib/context.php`, `lib/account.php`, Rechnungen, Mails) mit
   Aktionsregister für Erweiterungen; Sitzung und Kundenspalten umbenennen.
4. **Veröffentlichung** in den Kern (Paket, HugoCMS-Abgleich, Signatur,
   Läufer); Shop hängt Produktseiten, Kategorien und Medien an.
5. **LxCars-Portal**: Aktionen und Widget.
6. **Benennung**: HugoCMS „OpensourceERP-Anbindung“, Paket `oserp-portal/`,
   Elementnamen `portal-*` mit Alias.

Stufe 1 ist für sich sinnvoll und klein; sie beantwortet den Bedarf von
Autoprofis (Konto und Rechnungen ohne Warenkorb) am schnellsten, auch wenn
die Shop-Erweiterung dafür vorerst aktiv bleiben muss. Ab Stufe 3 läuft das
Portal ohne sie.

## Umsetzung

### Festlegungen (2026-10-21)

- **Webseite** = Tabelle `website_oserp` (Name, aktiv, `settings` jsonb,
  Sortierung) mit `website_secret_oserp` (Schlüssel `public_key`,
  `hugocms_key`). Einstellungen: `base_url`, `backend_url`, `allowed_origins`,
  `invoice_page`, `template_set` (Paket), `hugocms_url`; Signatur wie bisher
  je Installation. `website_setting_keys()` wie bei den Kanälen.
- **Sitzung** = `portal_context_oserp` (uuid, website_id, customer_id,
  `cart_uuid` für den Shop, active). Cookie und Name bleiben
  (`PORTALCLIENTID`), Pfad `/shop-api/` bleibt; `backend/shop/index.php`
  bleibt als Einstieg und zeigt auf den Portal-Unterbau, neu daneben
  `backend/portal/index.php`.
- **Kunde**: `customer_ext.portal_guest`, `portal_shipto_id` (umbenannt aus
  `hugoshop_*`). **Rechnungslink**: `invoice_link_oserp` (uuid, ar_id,
  website_id); `ar_link_hugoshop` behält die PayPal-Felder und dieselbe uuid.
- **Freischaltung**: `portal_activation_oserp` (siehe
  `dev/shop-konto-freischaltung.md`).
- **HugoShop-Kanal** bekommt `website_id`; die Webseiten-Schlüssel fallen aus
  `shop_channel_setting_keys()`; das Schema-Update legt je vorhandenem
  HugoShop eine Webseite aus seinen Einstellungen an (billig, deshalb
  trotzdem).
- **Öffentlicher Zugang**: `backend/api/portal/public/` mit Aktionsregister.
  Eine Erweiterung liefert `backend/api/<name>/public/actions.php` mit
  `<name>PublicActions()` und optional `<name>ClosedActions()`; der Unterbau
  lädt sie für die aktiven Erweiterungen des Mandanten, sobald die
  Datenbank bekannt ist. Vierter Parameter jeder Aktion ist die Webseite;
  Shop-Aktionen holen sich ihren Kanal mit `shopChannelOfWebsite()`.
- **Haken** (`backend/api/portal/lib/hooks.php`): der Kern ruft an wenigen
  Stellen `portalHookRun('name', …)`; Erweiterungen hängen sich mit
  `portalHookAdd()` an — Anmeldung (Warenkorb zusammenführen), Status der
  Sitzung (Warenkorbzähler), Rechnungsliste (Zahlstand, Lieferstatus),
  Rechnungspositionen (Produktlinks), Veröffentlichung (Medien, Paketteile).
- **Rechte**: Webseiten verwalten verlangt `edit_shop_config` (Kernrecht
  „Create and edit webshops“ aus `master_rights.csv`); ein eigenes Recht
  wäre ein Eingriff in die Rechte-Migration und lohnt nicht.
- **Paket**: `oserp-portal/` aus `backend/templates-default/portal/<satz>/kit/`
  (Kern: Partials, Shortcodes der Kern-Widgets, Weiterleiter, 404-Seite,
  `config.json`, `features.json`), darüber die Pakete der aktiven
  Erweiterungen (`backend/templates-default/shop/<satz>/kit/`: Shortcodes und
  Partials des Shops). HugoCMS nimmt `oserp-portal/` und übergangsweise
  `oserp-portal/` an. `features.json` sagt dem Partial, welche Bündel geladen
  werden.
- **Widgets**: `portal-ui/` (aus `portal-ui/`), drei Einstiege, esbuild mit
  `splitting`; Ausgabe nach `backend/templates-default/portal/standard/kit/assets/portal-ui/`
  (`portal-widgets.js`, `shop-widgets.js`, `lxcars-widgets.js`, geteilte
  Datei). Elementnamen bleiben, dazu Aliasse `portal-*` für die Kern-Widgets
  und neue Elemente `portal-activate`, `portal-vehicles`.

### Stand

| Stufe | Teil | Stand |
| --- | --- | --- |
| 2 | Schema Kern (`website_oserp`, `portal_context_oserp`, `invoice_link_oserp`, `portal_activation_oserp`, Kundenspalten) und Shop (`website_id`, Übernahme) | umgesetzt 2026-10-21 |
| 3 | Unterbau, Sitzung, Konto, Rechnungen, Kontakt, Freischaltung in `backend/api/portal/` | umgesetzt 2026-10-21 |
| 2 | Admin-API und Ansicht „Webseiten“, Kanalkarte ohne Webseitenfelder | umgesetzt 2026-10-21 |
| 4 | Paket und HugoCMS-Abgleich je Webseite im Kern, Lauf des Shops hängt sich an | umgesetzt 2026-10-21 |
| 1 | `portal-ui/` mit drei Bündeln, Freischaltungs-Widget, Aliasse | umgesetzt 2026-10-21 |
| 5 | LxCars: öffentliche Aktionen und Widget Fahrzeuge | umgesetzt 2026-10-21 |
| 6 | HugoCMS: Paketname `oserp-portal/`, Benennung | umgesetzt 2026-10-22 (siehe unten) |
| F | WhatsApp-Freischaltung: Vorlage mit Schaltfläche, Knopf in der Kundenakte | umgesetzt 2026-10-21 (Vorlage muss bei Meta genehmigt werden) |
| F | Reiter „Kundenkonto“ in der Kundenakte statt Karte im WhatsApp-Reiter, sichtbar nur mit eingeschalteter Webseite (`company_config.portal_websites`) | umgesetzt 2026-10-29 |
| F | Zugang sperren / Sperre aufheben und Zugang zurücksetzen im Reiter „Kundenkonto“ (`customer_ext.portal_locked_at`; gesperrt: keine Anmeldung, Freischaltung oder Registrierung; zurückgesetzt: Kennwort weg, Freischaltung erneut möglich) | umgesetzt 2026-10-29 |

### Umgesetzt (2026-10-21) — wo was liegt

**Kern `backend/api/portal/`**

| Datei | Inhalt |
| --- | --- |
| `lib/portal.php` | lädt die Fachschicht; `portalExtensionsLoad()` holt `backend/api/<ext>/lib/portal_hooks.php` der aktiven Erweiterungen |
| `lib/hooks.php` | `portalHookAdd/Run/Filter` — Haken `login`, `context_status`, `customer_defaults`, `invoice_list`, `invoice`, `invoice_positions`, `features`, `template_sets`, `publish_files`, `publish_entries` (Beitrag zur Lieferung von „Paket installieren“), `site_checks` (Konfigurationsprüfung der Webseite) |
| `lib/website.php` | `websiteConfig/Value/Bool/Require`, Seitenadressen (`websiteInvoicePageUrl`, `websiteActivatePageUrl`) |
| `lib/context.php` | Sitzung `portal_context_oserp`: `portalContext*`, `portalLoginCustomer`, `portalLogoutCustomer`, Cookie `PORTALCLIENTID` |
| `lib/account.php` | Konto: `customerRegister`, Adressen (`shipto*`), Profil, Zahlungsart, E-Mail, Kennwort, `customerInvoicingData`, `contactFormDefaults` |
| `lib/invoices.php` | Rechnungsliste und -ansicht mit Haken, Rechnungslink `invoice_link_oserp`, PDF |
| `lib/mail.php` | `portalMailer`, Vorlagen (`portalMailTemplate`, Verzeichnisse der Erweiterungen), Kontaktmail |
| `lib/activation.php` | Freischaltung (F1–F6): Zuordnung über `portal_phone_digits()` + PLZ/Kundennummer, Drossel je Sitzung und Nummer, Versand per WhatsApp (`whatsappSendTemplate`, Vorlagentyp `portal_activation`) oder Mail |
| `lib/signing.php`, `lib/hugocms.php`, `lib/kit.php` | Signaturschlüssel, HugoCMS-Transport je Webseite (`portalHugoCmsCall/Transfer/Build`, `portalBackendUrlCheck`), Paket (`portalKitFiles`, `portalSyncKit`, `portalPublishWebsite`, `features.json`) |
| `lib/sitecheck.php` | Konfigurationsprüfung der Webseite (2026-10-30): `portalSiteConfigFetch` (HugoCMS `shopsiteconfig`), `portalSiteChecks` (Mounts des Pakets, Hugos Warnungen, Haken `site_checks`) — dev/shop-veroeffentlichung.md „Konfigurationsprüfung“ |
| `public/bootstrap.php`, `public/actions.php` | öffentlicher Zugang mit Aktionsregister; `backend/portal/index.php` führt hierher (`backend/shop/index.php` seit 2026-10-22 entfernt) |
| `admin.php`, `index.php` | Admin-API `/api/portal/`: Webseiten, Tests, Paket installieren, Freischaltung aus der Kundenakte |

**Shop:** `lib/shop.php` (Lader), `lib/portal_hooks.php` (Haken), `shopChannelOfWebsite()`, `shopSiteValue()` (liest Webseitenwerte über den Kanal); `lib/context.php` und der alte `public/bootstrap.php` sind weg, `lib/account.php` enthält nur noch die Kasse. **LxCars:** `public/actions.php` (`portalVehicles`), `lib/portal_hooks.php`. **Widgets:** `portal-ui/` (vorher `portal-ui/`) baut `portal-widgets.js`, `shop-widgets.js`, `lxcars-widgets.js` plus geteilte `chunk-*.js` nach `backend/templates-default/portal/standard/kit/assets/portal-ui/`; neue Elemente `portal-activate`, `portal-vehicles`, Aliasse `portal-*`. **Oberfläche:** `src/core/views/config/tabs/portal/`, Firmenkonfiguration → Funktionen → Kundenportal, Kanalkarte mit Webseitenauswahl, Kundenakte (CRM-Ansicht) → Reiter „Kundenkonto“ (`customer-vendor/tabs/portal.tab.vue`, nur bei Kunden und nur, wenn eine Webseite eingeschaltet ist: `company_config.portal_websites` aus `portalWebsitesSelectSql()`, Store `hasPortalWebsite()`), Vorlageneditor mit Schaltfläche.

**Abweichungen vom Plan**

- Der Paketname in HugoCMS heißt seit 2026-10-22 `oserp-portal/` (`ShopSync::PACKAGE_DIR`, vorher `oserp-shop/`), die Bündel liegen unter `assets/portal-ui/` (vorher `assets/portal-ui/`). Es gibt keine Übergangsfrist für den alten Namen, weil alle angebundenen Webseiten Testseiten sind. Eine Webseite aus der Zeit davor braucht die Mounts `oserp-portal/static` → `static`, `oserp-portal/layouts` → `layouts`, `oserp-portal/assets/portal-ui` → `assets/portal-ui` und ein neues „Paket installieren“; das alte Verzeichnis `oserp-shop/` ist von Hand zu löschen. Der Partial `portal-ui-assets.html` und die Attribute `data-portal-ui-*` behalten ihren Namen. HugoCMS nennt die Anbindung „OpensourceERP-Anbindung“.
- Der Weiterleiter heißt seit 2026-10-22 `/portal-api/` (vorher `/shop-api/`; Datei `oserp-portal/static/portal-api/index.php`, `ShopSync::SIGNED_PHP`), ebenfalls ohne Übergangsfrist: Der Shop ist eine Zusatzfunktion des Kundenportals, der Weiterleiter gehört dem Portal. HugoCMS löscht signiertes PHP nie — auf den Testseiten wurde `static/shop-api/` von Hand entfernt. Cookie, Kopf und Elementnamen wurden im selben Zug umbenannt (nächster Punkt).
- Vollständige Umbenennung der Portal-Teile (2026-10-22): Elemente und Shortcodes `portal-login`, `portal-register`, `portal-contact`, `portal-account` und `portal-account-nav/overview/profile/payment/orders/addresses/buttons` (vorher `shop-*`, keine Aliasse mehr), Partials `portal-ui-assets.html`, `portal-account-nav.html`, `portal-account-panel.html`, Attribute `data-portal-ui-*`, Hugo-Parameter `params.portalui`, globales Objekt `window.PortalUI`/`PortalUIConfig`, Basisklasse `PortalElement`, Cookie `PORTALCLIENTID`, Kopf `X-Portal-Key`, Fehlercodes `PORTAL_CONTEXT_ERROR`, `PORTAL_NOT_AUTHORIZED`, `PORTAL_PROXY_NOT_CONFIGURED`, `PORTAL_BACKEND_UNREACHABLE`, `PORTAL_API_ERROR`, `PORTAL_NETWORK_ERROR`, `PORTAL_DATABASE_ERROR`, Ereignisse `PORTAL_AUTH_CHANGED`, `PORTAL_ACCOUNT_LOADED`, `PORTAL_ERROR`. Beim Shop bleiben `shop-cart`, `shop-checkout`, `shop-add-to-cart`, `shop-search`, `shop-search-results`, `shop-tool-finder`, `shop-invoice`, `SHOP_CART_CHANGED`, `SHOP_CLOSED`, `SHOP_NOT_CONFIGURED` und das Bündel `shop-widgets.js`. Inhalte und Themes der Testseiten wurden mit umgestellt.
- Der frühere öffentliche Einstieg `backend/shop/index.php` ist entfernt (2026-10-22); die Adresse des Portal-Zugangs lautet `…/portal/`. Die beiden Testwebseiten wurden in der Datenbank umgestellt und brauchen ein neues „Paket installieren“, damit `oserp-portal/config.json` die neue Adresse trägt.
- HugoCMS trennt die Freigaben (2026-10-22): Das Kundenportal braucht nur das „Kundenportal-Paket“; Produktseiten, Kategorieübersicht, Produktbilder, Vorschaubilder und Downloads gibt es erst mit dem Schalter „Online-Shop auf dieser Webseite“ (`[shop] online_shop`, Projekteinstellungen → OpensourceERP-Anbindung). `shopbuildstatus` meldet `onlineShop`; der Shop-Lauf sagt bei ausgeschaltetem Schalter „Online-Shop nicht freigegeben“ statt „Freigabe unbrauchbar“, die Webseiten-Ansicht warnt schon beim Verbindungstest. Anbindungen ohne den Schlüssel gelten als Shop (Stand vor dem Schalter), neu eingeschaltete Anbindungen beginnen ohne Online-Shop.
- Portalseiten aus dem Paket (2026-10-23): Der Vorlagensatz liefert die Seiten fertig mit — Kern `kit/content/` (Anmelden, Registrieren, Konto mit Unterseiten Profil/Adressen/Rechnungen/Abgemeldet, Freischalten, Kontakt, Danke), Shop (Zahlungsart, Warenkorb, Kasse, Rechnung, Zahlung abgebrochen), LxCars (Fahrzeuge). Platzhalter `[[login]]`, `[[account]]`, `[[feature:shop]]` … füllt `portalKitPageRender()` beim Abgleich aus `portalKitPages()`; die Pfade kommen aus den neuen Einstellungen `login_page`, `register_page`, `account_page`, `contact_page` (plus `invoice_page`, `activate_page`), Vorgaben unter `/kunden/` in `website_default_settings()`, Unterseiten hängen an `account_page`. `assets/portal-ui/pages.json` gibt dieselben Pfade an die Widgets (Partial `portal-ui-assets.html`, Site-Parameter gehen vor). Vierter Mount in der config.json der Webseite: `oserp-portal/content` → `content/kunden`; eine eigene Seite der Webseite unter derselben Adresse gewinnt. Erweiterungen passen die Pfade über den Haken `pages` an.
- „Paket installieren“ unabhängig vom Shop (2026-10-29): `installWebsitePackage` liefert immer selbst (`portalPublishWebsite`), mit oder ohne Shop-Erweiterung; der Haken `install_package` (Auftrag an den Shop-Lauf, Meldung „Auftrag angelegt — die Shop-Veröffentlichung überträgt das Paket“) ist weg. Weil HugoCMS bei der Übernahme löscht, was die vorige Lieferung enthielt und diese nicht mehr, nennt die Lieferung alles, was die Webseite von OpensourceERP haben soll: das Paket, dazu über den Haken `publish_entries` die Produktseiten, die Kategorieübersicht und die Medien des Shops (mit denselben Prüfsummen wie `shopHugoCmsSync`; Medien überträgt der Shop in Stücken selbst). Kern und Shop-Lauf teilen sich die Sperre `portalPublishLock()` (advisory lock `shop_publish`); ein zweiter Aufruf meldet `PORTAL_PUBLISH_BUSY`.
- Übernahme eingegrenzt (2026-10-29): Der Übernahmeblock in `upstall/shop/company_schema.sql` legt nur noch für HugoShop-Kanäle eine Webseite an, die vor dem Kundenportal eingerichtet waren (Adresse, Portal-Zugang, HugoCMS oder Schlüssel in den Kanaleinstellungen); die Webseite übernimmt den Schaltzustand des Kanals statt immer eingeschaltet zu starten. Der leere HugoShop-Kanal einer frischen Aktivierung erzeugt keine Webseite „HugoShop“ mehr — die Webseite wird unter Kundenportal angelegt und dem Kanal zugeordnet.
- Hugo-Version (2026-10-28, ergänzt 2026-10-29): Die Abschnittsseite `kit/content/_index.md` unterdrückt die Seite `/kunden/` über Bauoptionen im Vorspann. Hugo hat den Schlüssel mit 0.145 von `_build` in `build` umbenannt: ab da bricht `_build` den Bau ab („deprecated … removed“, so geschehen bei autoprofis24.de mit Hugo 0.164), davor bleibt `build` wirkungslos. Die Vorlage trägt deshalb den Platzhalter `[[hugo:build]]`; `portalKitPageRender()` setzt ihn über `portalHugoBuildKey(portalHugoVersion())` nach der Hugo-Version, die HugoCMS seit 2026-10-29 im Baustand nennt (`shopbuildstatus.hugo`). Ohne Angabe (älteres HugoCMS) gilt `build` — ein überflüssiger Abschnitt ist das kleinere Übel als eine Webseite, die nicht baut. Das lokale HugoCMS baut mit dem mitgelieferten Hugo 0.125 und bekommt `_build`.
- Konfigurationsprüfung der Webseite (2026-10-30): „Verbindung prüfen und Freigaben abrufen“ holt nach dem Baustand mit `shopsiteconfig` die Hugo-Konfiguration, wie Hugo sie auflöst (HugoCMS `Shop\SiteConfig`, `hugo config --format json`), und bewertet sie in OpensourceERP: der Kern die vier Mounts des Pakets und Hugos Warnungen beim Laden (`lib/sitecheck.php`, `portalSiteChecks`), der Shop über den Haken `site_checks` Taxonomie, `disableKinds`, `permalinks`, `params.portalui.taxonomy`, `_index.md` und die Lage der Freigaben in den Mounts (`backend/api/shop/lib/sitecheck.php`). Beide Karten zeigen die Befunde mit dem Eintrag für die `config.json`; geschrieben wird dort nichts. Anlass war das Beispielprojekt `inter-data_ab_29_10_26` ohne Kategorieseiten (`disableKinds`, fehlende Taxonomie, andere Adressen, fehlender Parameter). Ein HugoCMS ohne den Befehl meldet `site_checks_outdated`. Einzelheiten: dev/shop-veroeffentlichung.md „Konfigurationsprüfung der Webseite“.
- Belege im Kundenkonto je Webseite wählbar (2026-10-28): Die Kontoseite „Bestellungen“ (`konto/rechnungen/`, nur Rechnungen, Wortwahl aus dem Shop) ist durch die Seite „Belege“ (`konto/belege/`, Schlüssel `documents`, Vorlage `kit/content/konto-belege.md`, Widget `<portal-account-documents>`, Shortcode `portal-account-documents`, Panel-Seite `documents`, `order` als alter Name) ersetzt. Schalter je Webseite `documents_invoices` (Vorgabe `1`), `documents_orders`, `documents_quotations`, `documents_delivery_orders`, `documents_reclamations` (Vorgabe `0`) im Reiter Kundenportal, Abschnitt „Belege im Kundenkonto“; `websiteDocumentTypes()` in `lib/documents.php`. Öffentliche Aktionen `personalDocuments` (liefert `types`, `type`, `documents`), `personalDocument`, `downloadDocument` (type, id); die alten `personalOrders`/`personalOrder`/`downloadInvoice` bleiben, gebunden an den Schalter Rechnungen. Rechnungen weiter über `invoices.php` (Haken des Shops), Aufträge/Angebote aus `oe` (record_type, alt `quotation` über Rückfallzeile), Lieferscheine aus `delivery_orders` (ohne Preise), Reklamationen aus `reclamations` (ohne PDF — `print.php` kennt keine Vorlage). Die Schalter wirken ohne „Paket installieren“, das Widget fragt die Belegarten beim Aufruf ab; nur die Seite selbst kommt mit dem nächsten Paket. Erweiterungen hängen eigene Belegarten über die Haken `document_types`, `document_rows`, `document_detail` an: der Shop die Art `shop_orders` (Schalter `documents_shop_orders`, Vorgabe `1`, Feld nur mit aktiver Shop-Erweiterung — `extension: "shop"` in `websiteSettingsConfig.js`), Rechnungen mit Zeile in `ar_link_hugoshop`; ist der Reiter eingeschaltet, fehlen diese Rechnungen unter „Rechnungen“. `personalDocuments` meldet `pdf`, ob es zur Art ein PDF gibt.
- Konto-Freischaltung je Webseite schaltbar (2026-10-28): Einstellung `activation_enabled` (Vorgabe `0`, Abschnitt „Konto-Freischaltung für Bestandskunden“ im Reiter Kundenportal; das Feld `activate_page` erscheint nur eingeschaltet). `websiteActivationEnabled()` steuert: `portalKitPages()` liefert `activate` leer, damit `[[activate]]` auf Anmelde- und Registrierseite kein Attribut ergibt und der Link entfällt; `portalSyncKit()` lässt Seitenvorlagen mit `oserp_requires: activation` im Vorspann (`kit/content/freischalten.md`) aus dem Paket, die Seite verschwindet beim nächsten „Paket installieren“ (`oserp_requires: <merkmal>` prüft ein Merkmal der Webseite, `portalKitPageWanted()`); `activationRequireEnabled()` weist `requestActivation`, `activationInfo`, `completeActivation` mit `ACTIVATION_DISABLED` ab (auch für schon ausgegebene Kennungen über `activationRow()`); `getPortalActivationStatus` listet nur Webseiten mit eingeschalteter Freischaltung, `sendPortalActivation` lehnt andere ab.
- Die Webseiten des Kundenportals sind seit 2026-10-23 ein Reiter der Firmenkonfiguration (Gruppe Funktionen → „Kundenportal“, `src/core/views/config/tabs/portal/portal.tab.vue`, Aufruf `?tab=portal&website=<id>`); die eigene Route `websites` und der Menüpunkt System → Webseiten sind entfernt.
- Der HugoCMS-Transport liegt im Kern (`lib/hugocms.php`), die Lieferung des Shops (Produktseiten, Medien, Vorschaubilder) bleibt in `backend/api/shop/lib/hugocms.php` und ruft den Kern über `portalHugoCmsTransfer()`.
- Eine Webseite trägt höchstens einen HugoShop (eindeutiger Index `sales_channel_shop_website_hugoshop`); die Bereitstellung gehört der Webseite, die bisherige je Kanal zieht beim ersten Zugriff um.
