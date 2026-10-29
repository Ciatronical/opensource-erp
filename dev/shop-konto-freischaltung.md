# Shop: Konto-Freischaltung für Bestandskunden per WhatsApp

Stand 2026-10-21. Status: **umgesetzt** mit den Vorschlägen F1 bis F6, als
Teil des Kundenportals (`dev/kundenportal.md`): Fachlogik in
`backend/api/portal/lib/activation.php`, Tabelle `portal_activation_oserp`
(statt `account_activation_hugoshop`), Aktionen `requestActivation`,
`activationInfo`, `completeActivation` im Kern, Widget `<portal-activate>`
(`portal-ui/src/components/portal-activate.js`), Shortcode `portal-activate`,
Seite der Webseite über die Einstellung `activate_page`. Aus der Kundenakte:
Karte „Zugang zum Kundenkonto“ im Reiter WhatsApp (`sendPortalActivation`,
auch per Mail). WhatsApp-Vorlage: Typ „Kundenkonto freischalten“
(`portal_activation`) mit Schaltfläche, Vorlage `konto_freischalten` kommt
mit „Standard-Templates laden“ — Adresse der Schaltfläche auf die eigene
Webseite setzen und bei Meta einreichen. Die Drossel je Sitzung zählt in
`portal_context_oserp.activation_count`. „Kennwort vergessen“ (F5) ist noch
nicht gebaut; die Bausteine (Kennung, Seite, `customerSetPassword`) stehen.
Der Rest dieser Datei ist die Analyse von vor der Umsetzung.

Ausgangslage bei Autoprofis: Die Kunden der Werkstatt stehen in `customer`,
meist mit Telefonnummer, selten mit E-Mail-Adresse, nie mit Shop-Kennwort.
Sie sollen sich im Online-Shop anmelden können, ohne ein zweites Konto
anzulegen. Weg: Der Kunde nennt im Shop seine Telefonnummer, bekommt per
WhatsApp einen Link, und auf der verlinkten Seite vergibt er E-Mail-Adresse
und Kennwort. Danach meldet er sich wie jeder Shop-Kunde an.

## Ergebnis

Realisierbar mit vorhandenen Bausteinen; neu sind eine Tabelle, drei
öffentliche Aktionen, ein Widget und eine WhatsApp-Vorlage. Der größte Teil
außerhalb des Codes ist die Genehmigung der Vorlage durch Meta.

| Teil | Stand | Aufwand |
| --- | --- | --- |
| Kundenkonto im Shop (`customer.email` + `user_password`, `customer_ext.hugoshop_guest`) | vorhanden; Anmeldung über E-Mail-Adresse (`shopLoginCustomer`) | — |
| Kunde zur Telefonnummer finden | vorhanden: `_findCustomerByPhone()` und `_normalizePhone()` in `backend/api/whatsapp/whatsapp.php` (sucht `customer.phone` und `customer_ext.phone_numbers`) | klein: aus dem Shop nutzbar machen, mehrdeutige Treffer behandeln |
| WhatsApp senden | vorhanden: `sendWhatsAppTemplateMessage()` (Meta Business API, Vorlagen mit Textplatzhaltern) | mittel: Schaltfläche mit Link in der Vorlage |
| Link-Kennung mit Ablauf | Muster vorhanden: `ar_link_hugoshop.uuid` (Rechnungslink ersetzt die Anmeldung) | klein: eigene Tabelle |
| Seite im Shop mit Formular | Muster vorhanden: `<portal-register>`, `<portal-login>`, Shortcodes im Paket, Pfade in `params.portalui.paths` | mittel: neues Widget `<shop-activate>` |
| Kennwort vergessen | **fehlt** heute ganz | fällt mit demselben Mechanismus ab |

## Ablauf

1. **Anfrage** auf der Shop-Seite „Konto freischalten“ (Widget
   `<shop-activate>`): Der Kunde gibt seine Telefonnummer ein, dazu die
   Postleitzahl seiner Rechnungsadresse (siehe Mehrdeutigkeit). Aktion
   `requestActivation`.
2. **Zuordnung** im Backend: Nummer normalisieren (`_normalizePhone`), Kunden
   suchen, die nicht `obsolete` sind und noch kein Shop-Konto haben
   (`user_password IS NULL` oder `hugoshop_guest`). Die Postleitzahl muss
   passen. Genau ein Treffer: weiter. Kein oder mehrere Treffer: nichts
   senden, Antwort trotzdem neutral.
3. **Kennung**: Zeile in `account_activation_hugoshop` (uuid zufällig,
   `customer_id`, `channel_id`, `phone`, `created`, `expires` nach 24 Stunden,
   `used_at`). Je Kunde höchstens eine offene Kennung; eine neue Anfrage
   ersetzt die alte.
4. **Nachricht** per WhatsApp an die Nummer: genehmigte Vorlage mit
   Schaltfläche, deren Adresse die Kennung trägt
   (`<base_url><Pfad activate>?token=<uuid>`). Text etwa: „Hallo {{1}}, hier
   richten Sie Ihren Zugang zum Online-Shop von Autoprofis ein. Der Link gilt
   24 Stunden.“
5. **Freischalten**: Der Link öffnet dieselbe Seite mit Kennung. Das Widget
   holt `activationInfo` (Name und Ort des Kunden, maskiert, damit er sieht,
   dass es sein Konto ist) und zeigt das Formular: E-Mail-Adresse, Kennwort,
   Wiederholung. Aktion `completeActivation`: E-Mail-Adresse prüfen (kein
   anderes Shop-Konto mit dieser Adresse, wie bei `customerRegister`),
   `customer.email` und `user_password` setzen, `customer_ext` anlegen oder
   `hugoshop_guest = false`, Kennung als benutzt markieren, Kunden in die
   Sitzung setzen (`context_hugoshop.customer_id`), Warenkorb zusammenführen.
   Danach ist er angemeldet.
6. **Antwort** an den Kunden auf Schritt 1 immer gleich: „Wenn wir Sie unter
   dieser Nummer kennen, haben wir Ihnen einen Link per WhatsApp geschickt.“
   Sonst ließe sich über die Antwort ablesen, welche Nummern Kunden sind.

Zweiter Einstieg, von innen: In der Kundenakte von OpensourceERP (Reiter
WhatsApp oder Kopf der Akte) ein Knopf **„Shop-Zugang per WhatsApp senden“**
mit derselben Kennung und Nachricht. Am Tresen ist das der kürzere Weg: Der
Mitarbeiter schickt den Link, der Kunde richtet sich den Zugang zu Hause ein.
Schritt 1 und 2 entfallen dabei, die Zuordnung ist eindeutig.

## Entwurf

### Datenmodell

Neue Tabelle `account_activation_hugoshop` (Schemaänderung, Freigabe nötig):

```sql
CREATE TABLE IF NOT EXISTS account_activation_hugoshop (
    uuid        text PRIMARY KEY,                    -- 32 Hexzeichen aus random_bytes(16)
    customer_id integer NOT NULL REFERENCES customer (id) ON DELETE CASCADE,
    channel_id  integer REFERENCES sales_channel_shop (id) ON DELETE SET NULL,
    phone       text NOT NULL,                       -- normalisiert, wie gesendet
    created     timestamp NOT NULL DEFAULT now(),
    expires     timestamp NOT NULL,
    used_at     timestamp,
    source      text NOT NULL DEFAULT 'shop'         -- shop (Kunde) oder staff (Mitarbeiter)
);
CREATE UNIQUE INDEX … ON account_activation_hugoshop (customer_id) WHERE used_at IS NULL;
```

Keine Änderung an `customer` (Kivitendo-Tabelle): E-Mail und Kennwort stehen
dort schon, der Shop nutzt sie heute.

### Öffentliche Aktionen (`backend/api/shop/public/actions.php`)

| Aktion | Eingabe | Ergebnis |
| --- | --- | --- |
| `requestActivation` | `phone`, `zipcode`, Honeypot `website` | immer `{sent: true}`; sendet nur bei eindeutigem Treffer |
| `activationInfo` | `token` | `{name, city}` maskiert, oder `ACTIVATION_INVALID` / `ACTIVATION_EXPIRED` |
| `completeActivation` | `token`, `email`, `password` | Konto eingerichtet und angemeldet; `ACCOUNT_EXISTS`, wenn die Adresse ein anderes Shop-Konto hat |

Fachlogik in `backend/api/shop/lib/activation.php`. `requestActivation`
gehört in `shopClosedActions()` nicht hinein (Konto einrichten geht auch bei
geschlossenem Shop).

Schutz gegen Missbrauch, wie beim Widerruf: Honeypot-Feld; dazu eine Drossel
je Nummer (höchstens eine Nachricht je Nummer in 10 Minuten, drei am Tag —
jede Nachricht kostet Geld bei Meta) und je Sitzung. Die Drossel braucht
keine eigene Tabelle: `created` der Kennungen reicht für die Nummer, die
Sitzung zählt in `context_hugoshop` nicht mit; einfacher ist ein Zähler je
Sitzung in einer kleinen Tabelle `activation_attempt_hugoshop` oder im
Kontext selbst (Schemaänderung in der Shop-Erweiterung).

### WhatsApp

- `sendWhatsAppTemplateMessage()` baut heute nur Body-Parameter. Für den
  Link braucht die Vorlage eine **Schaltfläche vom Typ URL mit dynamischem
  Suffix** (Meta: Button `url` mit `{{1}}`); die Funktion bekommt dafür ein
  Feld `button_parameters` oder einen eigenen kleinen Aufruf
  `sendWhatsAppTemplateWithUrl()`. Ein Link als Body-Platzhalter wird von
  Meta meist abgelehnt.
- Vorlage `shop_konto_freischalten`, Kategorie UTILITY, Sprache de, wird
  über `saveWhatsAppTemplate` und `submitTemplateToMeta` eingereicht. Die
  Genehmigung dauert Stunden bis Tage; der feste Teil der Adresse
  (`https://autoprofis24.de/shop/freischalten/?token=`) steht in der Vorlage,
  variabel ist nur das Suffix.
- Die Nachricht geht vom Firmen-Konto (`whatsapp_phone_number_id`) aus;
  der Kunde muss WhatsApp unter der gespeicherten Nummer nutzen. Festnetz-
  nummern scheiden aus — die Zuordnung sollte Mobilnummern bevorzugen
  (`customer_ext.phone_numbers` mit Typ, sonst `customer.phone`).
- Zustellung und Fehler landen in `whatsapp_messages` wie bei den übrigen
  Nachrichten; der Kunde sieht im Shop davon nichts (neutrale Antwort).

### Widget und Webseite

- `<shop-activate>` in `portal-ui/src/components/`: ohne `token` das
  Anfrageformular (Telefon, PLZ), mit `token` das Einrichtungsformular
  (E-Mail, Kennwort, Wiederholung, Kennwortfeld aus `core/password-field.js`).
  Shortcode `shop-activate.html` im Paket, Pfad `activate` in
  `params.portalui.paths` (Vorgabe `/freischalten/`), Übersetzungen in
  `core/i18n.js`.
- `<portal-login>` bekommt unter dem Formular einen Verweis „Bestandskunde?
  Konto freischalten“ (Attribut `activate-url`, Vorgabe aus den Pfaden).
- Seite `content/shop/freischalten.md` der Webseite mit dem Shortcode, wie
  die Seiten für Anmeldung und Registrierung.

### Kundenakte in OpensourceERP

- Aktion `sendShopActivation` (Admin-API, Recht `shop_part_edit` oder
  ein eigenes `shop_customer_edit`): legt die Kennung an (`source = staff`)
  und sendet die Vorlage an eine gewählte Nummer des Kunden.
- Reiter „Kundenkonto“ in der Kundenakte (CRM-Ansicht, `tabs/portal.tab.vue`),
  sichtbar, sobald eine Webseite des Kundenportals eingeschaltet ist; bei
  fehlendem Konto der Versand per WhatsApp oder Mail, danach „gesendet am …“.
  Bis 2026-10-29 war das eine Karte im WhatsApp-Reiter.

## Grenzen und Risiken

- **Mehrdeutige Nummern**: `_findCustomerByPhone()` vergleicht die letzten
  acht Ziffern und nimmt den ersten Treffer — für eine Freischaltung zu
  unscharf (Familien, Firmen mit mehreren Kunden). Darum die vollständige
  normalisierte Nummer vergleichen und die Postleitzahl als zweites Merkmal
  verlangen. Bleibt es mehrdeutig, geht nichts hinaus; der Kunde wird auf
  den Weg über den Mitarbeiter verwiesen (Hinweistext auf der Seite).
- **E-Mail-Adresse als Anmeldename**: Der Shop meldet über
  `customer.email` an. Der Kunde muss also eine Adresse eintragen, auch wenn
  er keine hat — ohne sie keine Rechnungsmail, keine Bestellbestätigung. Eine
  Anmeldung über die Telefonnummer statt E-Mail wäre ein eigenes Vorhaben
  (Anmeldemaske, `shopLoginCustomer`, Mails ohne Adresse).
- **Adresse schon vergeben**: Nennt der Kunde eine Adresse, die ein anderes
  Shop-Konto trägt (etwa das Konto des Partners), lehnt die Freischaltung ab,
  wie `customerRegister`. Die Adresse eines Gastkontos darf dagegen
  übernommen werden.
- **Kennung ist das Geheimnis**: 128 Bit Zufall, 24 Stunden gültig, einmal
  nutzbar, nur über HTTPS. Wer den Link hat, richtet das Konto ein — WhatsApp
  liefert ihn an die Nummer, mehr Prüfung gibt es nicht. Für ein
  Werkstattkonto (Bestellhistorie, Adressen) ist das angemessen; Zahlungen
  laufen über PayPal mit eigener Anmeldung.
- **Datenschutz**: Die Nachricht enthält nur die Anrede und den Link, keine
  Kundendaten. `activationInfo` zeigt Name und Ort maskiert
  („M… Mustermann, 12345 B…“), damit ein fremder Empfänger wenig sieht.
  Die Nutzung von WhatsApp Business für Kundenkommunikation setzt voraus,
  dass die Werkstatt das heute schon so handhabt (Erinnerungen laufen
  bereits darüber).
- **Kein WhatsApp beim Kunden**: Dann bleibt der Mitarbeiterweg mit einer
  anderen Nummer, oder — wenn eine E-Mail-Adresse da ist — derselbe Link per
  Mail. Die Mail-Variante kostet wenig extra (Vorlagen unter
  `backend/api/shop/templates/`) und deckt zugleich „Kennwort vergessen“ ab.
- **Obsolete und zusammengelegte Kunden**: `obsolete = true` wird nicht
  freigeschaltet. Doppelte Kundenstämme (gleiche Person zweimal) bleiben ein
  Problem der Stammdaten; die Postleitzahl-Prüfung mildert es nur.

## Entscheidungen (offen)

| Nr. | Frage | Vorschlag |
| --- | --- | --- |
| F1 | Zweites Merkmal zur Zuordnung | Postleitzahl der Rechnungsadresse; Kundennummer als Alternative im Formular („oder Kundennummer“) |
| F2 | Gültigkeit der Kennung | 24 Stunden, einmal nutzbar; neue Anfrage ersetzt die alte |
| F3 | Einstieg | beide: Seite im Shop und Knopf in der Kundenakte |
| F4 | E-Mail-Adresse Pflicht | ja — sie bleibt der Anmeldename und die Adresse für Rechnung und Bestätigung |
| F5 | Gleicher Mechanismus für „Kennwort vergessen“ | ja, in einem zweiten Schritt: Kennung per WhatsApp oder Mail, Seite setzt nur das Kennwort neu |
| F6 | Drossel | je Nummer 1 Nachricht in 10 Minuten und 3 am Tag, je Sitzung 5 Anfragen am Tag |

## Reihenfolge der Umsetzung

1. Tabelle und Fachlogik (`lib/activation.php`), Aktionen, Tests gegen die
   Testdatenbank. Freigabe der Schemaänderung vorher.
2. WhatsApp: Vorlage mit URL-Schaltfläche senden können, Vorlage einreichen
   (parallel, wegen der Wartezeit bei Meta).
3. Widget `<shop-activate>`, Shortcode, Pfad, Verweis in `<portal-login>`,
   Seite in der Webseite autoprofis24.
4. Knopf in der Kundenakte.
5. „Kennwort vergessen“ auf derselben Grundlage.
