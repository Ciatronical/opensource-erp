# Erweiterung Shop — Überblick und Migrationsstand

Detailplanung der Fachlogik: `shop-migration-zuschnitt.md`.

## Ziel

Das Backend der Kivitendo-Bridge
(`/home/worker/Projekte/dev.hugoshop.dev/kivitendo_bridge/framework/`, 4.055
Zeilen, 55 Funktionen) wird zur OSERP-Erweiterung `shop`. Auf dieses eine
Backend greifen zwei Zugänge zu:

- **Admin-Panel** im OSERP-Frontend — für die Mitarbeiter des Shop-Betreibers.
  Normale OSERP-Sitzung, `permit()`.
- **Webshop-Webseite** (`shop-ui`, externer Hugo-Client) — für die Kunden des
  Betreibers. Anonym, Sitzungslogik unverändert aus der Bridge.

Kunden erreichen das Admin-Panel nicht. Mitarbeiter sind keine Kunden.

Der Beispielshop `sonic24.de` (Hugo) bleibt außerhalb; er ruft künftig die
OSERP-Erweiterung statt der Bridge auf.

## Warum das trägt: zwei Sitzungsmodelle ohne Berührungspunkt

| | Kunde (Webshop) | Mitarbeiter (Admin-Panel) |
| --- | --- | --- |
| Cookie | `HUGOSHOPCLIENTID` | `SESSION_COOKIE` (`backend/api/config.php:116`) |
| Sitzungstabelle | `context_hugoshop` | `auth.session_oserp` |
| Identität | `customer.id` | `auth.user.id` + `client_id` |
| Passwort | `customer.user_password`, bcrypt | `auth.user`, PBKDF2 (`backend/api/password.php`) |
| Rechte | keine | `auth.group_rights` über `permit()` |
| Aufräumen | Trigger nach 24 Stunden | Sitzungsverwaltung |

Getrennte Tabellen, Cookies und Passwortverfahren. Die Trennung ergibt sich aus
der Datenhaltung und muss nicht nachträglich abgesichert werden: ein Shop-Kunde
hat keine Zeile in `auth.user` und kann deshalb keine `permit()`-geschützte
Funktion bestehen.

Ein Shop-Kunde ist ein gewöhnlicher kivitendo-`customer`. Die Kundenverwaltung
des Shops ist damit die bestehende OSERP-Kundenverwaltung
(`backend/api/customer_vendor/`) — kein zweiter Datenbestand.

## Was OSERP bereits mitbringt

Diese Teile der Bridge werden nicht portiert, sondern ersetzt:

| Bridge | OSERP | Wirkung |
| --- | --- | --- |
| `shop.invoice.pl` + `shell_exec` auf `pdflatex`, `KIVI_ERP_PATH` | `backend/api/print/template_engine.php`, `renderDocumentPdfFile()` (`print.php:454`) | Perl, Shell-Aufruf und die Abhängigkeit an eine installierte kivitendo-Instanz entfallen |
| `framework/phpmailer/` (6 Dateien) | `backend/api/email/smtp.class.php`, `sendEmail()` | fremde Bibliothek entfällt |
| `acc_trans`-Aufbau in `invoicing()` | `postArInvoiceToLedger()` (`faktura.php:1227`) | Buchungslogik nur noch an einer Stelle |
| `ar` + `invoice` in `invoicing()` | `createFakturaCore()` / `createFakturaItemCore()` | inklusive Nummernvergabe über `getNumberConfig()` |
| `config.php` + `passwd.php` (rund 60 Konstanten) | `defaults_oserp` samt Einstellungen-Tab | Muster: `backend/api/payment/sumup.php` |

## Offene Konstruktionspunkte

1. ~~**`DbhCompany::begin($pdo)` ignoriert seinen Parameter.**~~ Erledigt in
   Stufe 2: die Verbindung lässt sich jetzt übernehmen, ein Austausch bei
   stehender Verbindung wirft `DB_ALREADY_CONNECTED`.
2. ~~**Der Aktionsmechanismus kennt keine Grenzen.**~~ Erledigt in Stufe 4:
   der öffentliche Einstiegspunkt bindet `inc.php` nicht ein und lässt nur die
   Aktionen aus `shopPublicActions()` zu.
3. ~~**Namenskollision `login`/`logout`.**~~ Erledigt in Stufe 4: die Aktionen
   heißen `shopLogin`/`shopLogout`, die Fachfunktionen `shopLoginCustomer`/
   `shopLogoutCustomer`.
4. ~~**Same-Origin.**~~ Erledigt in Stufe 7: Proxy im Shop-Docroot, damit
   bleibt der Aufruf same-origin und das Kontext-Cookie geht nicht verloren.
5. ~~**Antwortformat.**~~ Erledigt in Stufe 7: das `shop-ui` nimmt Antworten
   mit und ohne Hülle an.

## Tabellennamen

Die Namen `*_hugoshop` und die Spalten `hugoshop_*` bleiben, obwohl die
Erweiterung `shop` heißt und lxcars mit `_lxcars` die Gegenkonvention setzt.

Grund: Der Beispielshop und die Entwicklungsversion von OSERP arbeiten auf
derselben Datenbank. Ein Umbenennen bricht den laufenden Beispielshop, ohne
fachlich etwas zu gewinnen. Neue Tabellen der Erweiterung folgen demselben
Suffix, damit der Bestand einheitlich bleibt.

## `customer_ext`

Die Tabelle gehört der CRM-Basis (`upstall/crm/company_schema.sql:169`) und
trägt dort `phone_numbers`, `phone_labels`, `emails`, `keywords`. Die Bridge
definierte sie ein zweites Mal mit eigenem Primärschlüssel und
`hugoshop_guest boolean NOT NULL` ohne Vorgabewert.

Auf der Entwicklungsdatenbank ist das mit
`kivitendo_bridge/sql/migrations/custom_ext.problem.sql` aufgelöst: eine
Tabelle mit beiden Spaltensätzen, `hugoshop_guest` mit `DEFAULT FALSE`.

Für die Erweiterung reichen zwei `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` —
das Muster steht bereits in `upstall/lxcars/company_schema.sql:476`. Damit läuft
das Update auf leeren wie auf gefüllten Datenbanken und beliebig oft.

Das Migrationsskript selbst gehört nicht in die Erweiterung: es überträgt beim
Umkopieren nur `customer_id`, `hugoshop_guest` und `hugoshop_shipto_id`, danach
folgt `DROP TABLE` — auf einem System mit gepflegten CRM-Kontaktdaten wäre das
Datenverlust. Als einmalige Entwicklungsmaßnahme war es folgenlos, weil dort
die reine Shop-Variante stand.

## Stufenplan

| Stufe | Inhalt | Stand |
| --- | --- | --- |
| 1 | `backend/upstall/shop/` — Schema und `extension.json` | **erledigt** |
| 2 | Einstellungen aus `defaults_oserp` lesen, Einstellungen-Tab, `DbhCompany::begin($pdo)` | **erledigt** |
| 3 | Fachschicht: Kontext, Warenkorb, Konto, Suche | **erledigt** |
| 4 | Beide Einstiegspunkte, Allowlist, `shopLogin`/`shopLogout` | **erledigt** |
| 5 | Rechnung und Zahlung auf Faktura, Print und E-Mail | **erledigt**, PDF/Mail/PayPal ungeprüft |
| 6 | `src/features/shop/` — Admin-Panel, Routen in 21 Sprachen | **erledigt** |
| 7 | `shop-ui` auf das Antwortformat umstellen, Proxy einrichten | **erledigt** |

Nach Stufe 5 ist der Shop lauffähig, nach Stufe 6 verwaltbar.

## Stufe 1 — was angelegt wurde

`backend/upstall/shop/extension.json` und
`backend/upstall/shop/company_schema.sql`.

Gegenüber `kivitendo_bridge/sql/install.sql` geändert:

- **`DROP TABLE` entfernt.** `install.sql:179` und `:189` löschen
  `batchjob_hugoshop` und `redirect_pages_hugoshop` vor dem Anlegen. Im
  Upstall läuft die Datei bei jedem Update — das hätte jedes Mal die
  Weiterleitungen und die Warteschlange gekostet.
- **`customer_ext`** nur noch als zwei `ADD COLUMN IF NOT EXISTS`.
- **`parts_ext`** bekommt einen Primärschlüssel und einen eindeutigen Index auf
  `parts_id`. Beides fehlte; ohne den Index kann ein Artikel mehrere Zeilen
  haben, und jeder `JOIN parts_ext` vervielfacht dann Warenkorb- und
  Rechnungspositionen. Der Index wird in einem `DO`-Block angelegt, der bei
  vorhandenen Doppeleinträgen eine `NOTICE` ausgibt statt das Update
  abzubrechen.
- **Versandartikel** wird gar nicht mehr angelegt (in Stufe 5 nachgezogen,
  Begründung dort). `install.sql:165` schrieb `ON CONFLICT DO UPDATE SET …
  sellprice = 7.90` und hätte bei jedem Schema-Update den gepflegten
  Versandpreis zurückgesetzt.
- **Aufräumfristen** der beiden Trigger kommen aus `defaults_oserp`
  (`shop_cart_lifetime_hours`, `shop_context_lifetime_hours`) statt fest aus dem
  Funktionsrumpf.
- **Trigger** über `DO`-Blöcke mit Existenzprüfung statt
  `CREATE OR REPLACE TRIGGER` (PostgreSQL 14 aufwärts), wie in lxcars.
- **`withdrawals_hugoshop`** neu: der Widerruf schreibt heute eine JSON-Zeile
  in eine Logdatei (`shop.widerruf.php:16`). Ein Vorgang, der aus gesetzlichen
  Gründen nachweisbar sein muss, gehört in die Datenbank.
- **Einstellungen** als `shop_*`-Schlüssel in `defaults_oserp` angelegt, Muster
  wie `lxcars_*`. Zugangsdaten (PayPal) und der Shop-Schlüssel bleiben leer und
  werden im Admin-Panel gesetzt.

### Nachgemessen

Gegen PostgreSQL 14.24 auf vier Wegwerf-Datenbanken mit Stubs der
Basistabellen, danach wieder entfernt:

| Fall | Ergebnis |
| --- | --- |
| Upstall auf frischer Datenbank | 8 Tabellen angelegt, 65 Statements, fehlerfrei |
| Upstall ein zweites Mal | 8 Tabellen aktuell, 3 Indizes übersprungen, keine Änderung |
| `customer_ext` mit Bestandszeilen | beide Spalten ergänzt, vorhandene Daten unverändert |
| `parts_ext` in der Bridge-Fassung | Primärschlüssel und eindeutiger Index nachgezogen |
| dieselbe, mit Doppeleinträgen | Primärschlüssel gesetzt, Index ausgelassen, `NOTICE`, Update läuft durch |

Zusätzlich mit dem Parser aus `backend/api/update/update.php` geprüft, dass er
die Datei erwartungsgemäß zerlegt: acht `CREATE TABLE`-Blöcke mit erkannten
Spalten (Constraint-Zeilen korrekt ausgelassen), 65 übrige Statements in
Dateireihenfolge, `DO`-Blöcke und Dollar-Quoting unbeschädigt. `::`-Casts
kommen durch `execute()` — das CRM-Schema verwendet sie bereits.

Nicht übernommen: die auskommentierten Blöcke am Dateiende von `install.sql`
(`search_tsv`, `parts_ext_search_gin`, die `jsonb`-Umstellungen). Sie gehören zu
einer Suchvariante, die nicht in Betrieb ist — `shop.search.php` baut den
`tsvector` zur Laufzeit.

## Stufe 2 — was angelegt wurde

### `DbhCompany::begin($pdo)`

`backend/api/database.php` löst die Zusage aus Signatur und Kommentar jetzt ein:
mit `$pdo` übernimmt das Singleton eine bereits aufgebaute Verbindung. Das
braucht jeder Einstiegspunkt ohne Mitarbeiter-Sitzung — der öffentliche Zugang
des Shops über den Shop-Schlüssel, die Webhooks über ihr Secret. Die
Fachfunktionen rufen anschliessend wie überall `begin()` ohne Argument.

Ein zweiter Aufruf mit `$pdo` bei bereits stehender Verbindung wirft
`DB_ALREADY_CONNECTED`. Die Verbindung nachträglich austauschen zu wollen ist
immer ein Programmierfehler: alles bisher Gelesene stammte dann aus einer
anderen Datenbank. Für die 595 vorhandenen Aufrufe von `begin()` ohne Argument
ändert sich nichts.

### `backend/api/shop/lib/config.php`

`shopConfig($db)` liest alle `shop_*`-Schlüssel in einer Abfrage und hält sie
für die Dauer des Requests — pro Verbindung getrennt, damit ein Durchlauf über
mehrere Mandanten nicht die Werte des ersten weiterträgt. Dazu die Zugriffe
`shopConfigValue`, `shopConfigRequire` (wirft `SHOP_CONFIG_MISSING` bei leerem
Pflichtwert), `shopConfigBool`, `shopConfigInt` und `shopConfigFloat`.

Damit ist der Ersatz für `config.php` und `passwd.php` der Bridge vollständig:
rund 60 Konstanten aus zwei Dateien je Shop-Instanz sind jetzt Einstellungen je
Mandant.

### Einstellungen-Tab

`src/core/views/config/tabs/shop-defaults.tab.vue` samt Felddefinition in
`shopDefaultsConfig.js`, eingehängt in `client-defaults.view.vue` unter
„Erweiterungen" — sichtbar nur bei aktiver Erweiterung
(`store.isExtensionEnabled('shop')`), analog zu LxCars. 28 Felder in acht
Abschnitten. Gespeichert wird über den vorhandenen Weg `saveCrmDefaults`; ein
eigenes Backend braucht der Tab nicht.

Übersetzt in alle 21 Sprachen (66 Schlüssel unter `crm_fields` je Sprache).

### Geheimnisse

`getCompanyConfig` liefert `defaults_oserp` vollständig an den Browser jedes
angemeldeten Benutzers. Für `shop_paypal_secret` und `shop_public_key` wäre das
zu weitgehend: mit ihnen liessen sich Zahlungen abwickeln bzw. der öffentliche
Shop-Zugang übernehmen. Beide stehen deshalb jetzt in der Ausschlussliste neben
`aag_online_token*`.

Bearbeiten lassen sie sich trotzdem, ohne Sonderweg im Backend: `cleanData()`
in `client-defaults.view.vue` übergeht leere Zeichenketten beim Speichern. Der
Tab zeigt die beiden Felder also leer mit dem Hinweis „hinterlegt – leer lassen
zum Behalten"; wer nichts einträgt, ändert nichts, wer etwas einträgt,
überschreibt.

Anmerkung für später: `aag_online_passwd`, `aag_online_passwd2` und
`hgs_data_passwd` gehen weiterhin an den Browser. Das ist Bestand und war nicht
Teil dieser Stufe — dasselbe Vorgehen liesse sich dort anwenden.

### Nachgemessen

- `npm run build` fehlerfrei, `npm run check:api` (167 Dateien) und
  `npm run check:routes` ohne Beanstandung
- `shopConfig()` gegen eine Wegwerf-Datenbank mit eingespieltem Shop-Schema:
  28 Schlüssel, Typumwandlungen, Rückfallwerte, `SHOP_CONFIG_MISSING` bei
  leerem Pflichtwert; ein zusätzlich angelegter Schlüssel `shopping_fremd` wird
  nicht mitgelesen (die `LIKE`-Maskierung greift)
- `DbhCompany::begin($pdo)` übernimmt die Verbindung, `begin()` liefert
  danach dieselbe Instanz, ein zweites `begin($pdo)` wirft
- Gegenprobe der Übersetzungen: alle 66 im Tab verwendeten Schlüssel sind in
  allen 21 Sprachen vorhanden, keiner davon unbenutzt

## Stufe 3 — was angelegt wurde

Die Fachschicht unter `backend/api/shop/lib/`. Jede Funktion folgt der
Signaturregel aus `shop-migration-zuschnitt.md`: `$db` zuerst, `customer_id`
als Parameter, Rückgabe ein Array, kein `echo`, kein Zugriff auf `$_POST` oder
`$_COOKIE`. Damit ist jede Funktion von beiden Zugängen aufrufbar.

| Datei | Inhalt |
| --- | --- |
| `context.php` | Sitzung des Shop-Besuchers, Anmeldung, Abmeldung, Artikellink |
| `cart.php` | Warenkorb: lesen, ergänzen, ändern, Summen, Versand, Zusammenführen |
| `account.php` | Kundenkonto: Anlegen, Anschriften, Profil, Kennwort, Zahlungsart, Kasse |
| `search.php` | Artikelsuche mit Präfixtreffern und Preisgewichtung |

Die Bestell- und Rechnungsansichten (`personalOrders`, `personalOrder`,
`getInvoiceSummary`, die PDF-Ausgabe) fehlen bewusst: sie hängen an der
Rechnungserstellung und kommen mit Stufe 5.

### Abweichungen von der Bridge

Beim Portieren korrigiert, jeweils im Code begründet:

- **Kennwort bei der Anmeldung.** Die Bridge setzte
  `('false' == $guest) ? null : password_hash(...)` — also andersherum. Ein
  angelegtes Konto bekam kein Kennwort und kam durch die Anmeldung nie
  hindurch, eine Gastbestellung einen Hash über ein Feld, das dort nicht
  gesetzt wird. Jetzt bekommt das Konto das Kennwort und der Gast keines.
- **Rundung.** `ROUND(x / precision, 2) * precision` rundet eine bereits ganze
  Zahl auf zwei Stellen und lässt den Rundungsschritt des Mandanten wirkungslos
  (sichtbar erst bei `precision <> 0.01`). Jetzt `ROUND(x / precision) * precision`,
  und gerundet wird je Position, danach summiert — sonst weicht die Summe von
  der Addition der angezeigten Positionsbeträge ab.
- **Steuersatz des Versands.** Die Bridge rechnete ihn mit `MAX(tax.rate)` des
  Warenkorbs. Jetzt mit dem eigenen Satz des Versandartikels.
- **Kundennummer.** Statt `LOCK TABLE customer IN EXCLUSIVE MODE` plus
  Zählschleife jetzt `nextFreeNumber()` aus `backend/api/database.php`.
- **Zugehörigkeit von Lieferadressen.** Jede Funktion, die eine `shipto_id`
  entgegennimmt, führt `trans_id` in der Bedingung mit und meldet
  `ADDRESS_NOT_FOUND`, wenn nichts getroffen wurde. Die Bridge meldete bei
  `updateDeliveryAddress` auch dann Erfolg, wenn keine Zeile geändert wurde.
- **Preise als Zahlen.** Die Bridge lieferte formatierte Zeichenketten
  (`formatPrice`). Formatiert wird jetzt in der Oberfläche — für das `shop-ui`
  ein Punkt in Stufe 7.
- **Eindeutiger Index auf `(cart_uuid, parts_id)`** im Schema ergänzt. Damit
  wird aus Suchen-und-Entscheiden ein `INSERT … ON CONFLICT DO UPDATE`; zwei
  gleichzeitige Anfragen können keine zwei Zeilen mehr anlegen. Vorhandene
  Doppeleinträge fasst der Upstall vorher zusammen (Mengen addiert) statt sie
  nur zu melden — anders als bei `parts_ext` gibt es hier eine eindeutig
  richtige Auflösung.

### Nachgemessen

62 Prüfungen gegen PostgreSQL 14.24 auf einer Wegwerf-Datenbank mit Stubs der
kivitendo-Tabellen, danach wieder entfernt: Kontext anlegen und wiederfinden,
Warenkorb füllen und ändern (auch mit zwei Steuersätzen), Versandkosten-Regel
in allen drei Fällen, Registrierung samt Kundennummer und Kennwort,
Warenkörbe-Zusammenführen beim Anmelden, Fremdzugriff auf Lieferadressen in
allen vier Varianten, Profil, Kennwortwechsel, Zahlungsart, Kasse,
Kontaktformular, Suche (Präfix, Kategorie, Sonderzeichen, mehrere Wörter) und
Abmelden.

Zwei echte Fehler hat der Durchlauf aufgedeckt und sie sind behoben:

1. `cartAdd` zählte die Positionen im selben CTE wie das `INSERT` — alle Zweige
   einer Anweisung lesen denselben Stand, die Zählung sah den Warenkorb also
   vor dem Einfügen. Jetzt eine getrennte Abfrage.
2. Die Rundung (siehe oben) fiel beim Vergleich der erwarteten Summe auf.

Der Duplikat-Fall des neuen Index wurde eigens geprüft: drei überzählige
Zeilen in zwei Warenkörben wurden zu den richtigen Mengen zusammengefasst
(2+3+1 und 7+1), danach der Index angelegt.

## Stufe 4 — was angelegt wurde

### Die beiden Einstiegspunkte

| Datei | Zugang |
| --- | --- |
| `backend/shop/index.php` | Kunden des Betreibers, anonym, Shop-Schlüssel |
| `backend/api/shop/index.php` | Mitarbeiter, normale Sitzung, `permit()` |

Der öffentliche Zugang liegt bewusst **neben** `backend/api/` und nicht darin:
alles unter `api/` lädt über `inc.php` auch `auth.php`, und über den
Aktionsmechanismus wären dann `login`, `logout`, `getClients`,
`restoreSession` und `switchClient` von der Shop-Webseite aus erreichbar. Er
liegt damit neben `backend/webhook/`, das aus demselben Grund dort steht.

`backend/api/shop/public/bootstrap.php` trägt den Unterbau (Mandant, Cookie,
Herkunftsprüfung, Verteiler), `public/actions.php` die 31 Aktionen.

### `resultInfo` und `ApiError` herausgelöst

Beide standen in `inc.php` — der Datei, die der öffentliche Zugang nicht laden
darf. Sie stehen jetzt in `backend/api/error.php`, das `inc.php` als erstes
einbindet. Für die übrigen Module ändert sich nichts.

**Auch der Admin-Zugang braucht `error.php` ausdrücklich**, und zwar vor
`lib/payment.php`: dort erbt `ShopPaymentError` von `ApiError`, und eine
Basisklasse muss beim *Laden* der Datei bekannt sein — anders als Funktionen,
die erst beim Aufruf aufgelöst werden. `inc.php` gehört ans Ende
(Projektkonvention) und käme dafür zu spät. Ohne diese Zeile endet jeder
Aufruf von `/api/shop/` mit `Class "ApiError" not found`.

### Mandant ohne Sitzung

Die Shop-Webseite weist sich mit dem Kopf `X-Shop-Key` aus; der Wert steht in
`defaults_oserp.shop_public_key`. Gesucht wird wie in
`backend/webhook/telegram.php`: über die Mandanten der Auth-Datenbank, Vergleich
mit `hash_equals`, ein leerer Schlüssel wird nie angenommen.

Den Kopf setzt sinnvollerweise der Reverse-Proxy im Shop-Docroot — dann sieht
der Browser den Schlüssel nie. Das Beispiel steht im Kopf von
`backend/shop/index.php`.

Bei vielen Mandanten wird die Suche linear teuer, weil sie je Mandant eine
Verbindung aufbaut. Bei der heutigen Größenordnung unkritisch; eine Zuordnung
in der Auth-Datenbank wäre die Antwort, wenn es einmal viele werden.

### Die Allowlist ist die Grenze

`shopPublicActions()` nennt die zugelassenen Aktionen. Was dort nicht steht,
ist nicht erreichbar — auch nicht, wenn die Funktion geladen ist. Damit wird
eine neue Fachfunktion nicht dadurch öffentlich, dass jemand sie einbindet.

### Cookie und Herkunft

Ohne Eintrag in `shop_allowed_origins` läuft der Shop über einen Proxy unter
derselben Adresse: keine Freigabe-Kopfzeilen, Cookie `SameSite=Strict`. Steht
dort eine Adresse und die Anfrage kommt von ihr, werden die Freigaben gesetzt
und das Cookie auf `SameSite=None; Secure` — sonst schickt der Browser es bei
fremder Herkunft nicht mit. Eine nicht eingetragene Herkunft bekommt keine
Kopfzeilen; der Browser bricht dann selbst ab, und die Antwort verrät nicht,
welche Adressen zugelassen sind.

Als Sitzungskennung wird nur die selbst vergebene Form angenommen (32
Hexzeichen); alles andere wird ersetzt, damit fremde Werte nicht in der Tabelle
landen.

### Rechte

Eigene Rechte braucht die Erweiterung nicht: kivitendo bringt `shop_order`,
`shop_part_edit` und `edit_shop_config` bereits mit, und die Gruppe
„Vollzugriff" hat sie. Nachgesehen in der Entwicklungsdatenbank.

`getShopStatus` in `backend/api/shop/admin.php` prüft die Einrichtung und
trennt dabei, was den Betrieb verhindert (fehlender Shop-Schlüssel,
Versandartikel, Forderungskonto) von dem, was ihn nur einschränkt (kein
Ansprechpartner, keine PayPal-Zugangsdaten, keine Artikel mit Shopdaten,
Sandbox noch aktiv). Damit hält das Admin-Panel die Zusage ein, die der
Kommentar im Schema beim Versandartikel gibt.

### Nachgemessen

42 Prüfungen über echtes HTTP gegen den eingebauten PHP-Webserver, mit eigenen
Test-Datenbanken und eigener `settings.ini` — die Entwicklungskonfiguration
blieb unangetastet, alles danach entfernt.

- Ohne und mit falschem Shop-Schlüssel: HTTP 403, keine Nutzdaten, kein Cookie
- `login`, `logout`, `getClients`, `restoreSession`, `switchClient` sind über
  den öffentlichen Zugang **nicht** erreichbar, ebenso wenig die
  Fachfunktionen (`cartAdd`, `customerRegister`, `shopConfigValue`, …), der
  Mandantensucher selbst und die Admin-Aktion `getShopStatus`
- Cookie wird gesetzt, ist `HttpOnly` und `SameSite=Strict`, bleibt über
  Anfragen hinweg bestehen; ein unbrauchbarer Wert wird ersetzt
- Warenkorb füllen und lesen, Konto anlegen, anmelden, abmelden — danach kein
  Kontozugriff mehr
- Erlaubte Herkunft bekommt die Freigaben und `SameSite=None; Secure`, eine
  nicht eingetragene bekommt keine Kopfzeilen, `OPTIONS` antwortet mit 204
- Einschleusversuch in der Suche bleibt folgenlos, die Artikel stehen danach
  unverändert da

Der Durchlauf hat außerdem bestätigt, dass `DbhCompany::begin($pdo)` beim
zweiten Aufruf im selben Prozess wirft — im Betrieb ist ein Request ein
Prozess, im ersten Testanlauf (mehrere Anfragen in einem Prozess) fiel es auf.
Deshalb läuft der Test jetzt über echtes HTTP.

## Stufe 5 — was angelegt wurde

| Datei | Inhalt |
| --- | --- |
| `lib/invoice.php` | Warenkorb → Rechnung, Rechnungsansichten, PDF-Abruf |
| `lib/mail.php` | Rechnungsmail, Kontaktmail, Widerrufsmails |
| `lib/payment.php` | PayPal: Bestellung, Einzug, Zahlungsstand, Abgleich |
| `lib/withdrawal.php` | Widerruf entgegennehmen und verwalten |
| `lib/analytics.php` | Angaben für die Reichweitenmessung |
| `templates/` | fünf Mailvorlagen (Rahmen, Rechnung, Kontakt, zwei Widerrufsmails) |

Dazu 16 weitere öffentliche Aktionen (Rechnung, Zahlung, Auswertung, Kontakt,
Widerruf) und acht Admin-Aktionen (Bestellungen, schwebende Zahlungen,
Abgleich, Widerrufe, Artikel-Shopdaten).

### Was von der Bridge übrig bleibt

`invoicing()` hatte rund 200 Zeilen. Geblieben ist ein Ablauf, der vorhandene
Bausteine aneinanderreiht:

| Schritt | jetzt |
| --- | --- |
| Versandkosten | `cartApplyShipping()` |
| Lieferadresse | `shiptoCreate()` — dieselbe Funktion wie im Konto |
| `ar` + `invoice` | ein `INSERT … SELECT` aus den Warenkorbzeilen |
| `acc_trans` | `postArInvoiceToLedger()` aus `faktura.php` |
| Beträge | aus dem Trockenlauf derselben Funktion |
| PDF | `renderDocumentPdfFile()` — Perl und `shell_exec` entfallen |
| Mail | `SmtpClient` — PHPMailer entfällt |

Der feste Teiler `/1.19` ist damit weg: die Steuer kommt je Position aus der
Buchungsgruppe. Nachgemessen an einem Warenkorb mit 19 % und 7 %: fünf
Buchungszeilen, Summe null.

**Betrag und Buchung können nicht auseinanderlaufen.**
`postArInvoiceToLedger()` prüft den gerechneten Bruttobetrag gegen `ar.amount`
und verweigert bei Abweichung. Deshalb wird die Funktion zuerst im Trockenlauf
gefragt, ihr Ergebnis als Betrag gesetzt und dann gebucht — sonst könnte die
Prüfung an einem Rundungscent scheitern.

### Versandartikel: Anlage zurückgenommen

Stufe 1 legte ihn an, wenn er fehlt. Der Lauf gegen ein echtes
kivitendo-Schema scheiterte daran mit
`part_classification_id_fkey` — `parts` trägt je nach Stand unterschiedliche
Pflichtfelder. Ein Schema-Update darf daran nicht scheitern, und sachlich
gehört der Artikel ohnehin dem Betreiber (Preis, Buchungsgruppe, Steuersatz).
`getShopStatus()` meldet ihn jetzt als blockierenden Punkt, wenn er fehlt.

### Nachgemessen

**Rechnungskern** (40 Prüfungen, Stub-Schema): Rechnung mit zwei Steuersätzen,
Positionen, Beträge, Buchungssatz (fünf Zeilen, Summe null, Forderung negativ),
kein Zahlungseingang gebucht, Warenkorb geleert, Nummernkreis zählt hoch,
Ansichten, Fremdzugriff verwehrt, leerer Warenkorb abgewiesen.

**Über HTTP** (22 Prüfungen): Kauf auf Rechnung, Bestellliste, Einzelansicht,
Zusammenfassung über den Rechnungslink, Auswertung, Widerruf samt Honigtopf,
Fremdzugriff auf Bestellungen und PDF verwehrt. Ein fehlgeschlagener
Mailversand kippt den Kauf nicht — die Antwort meldet `email_status: error`,
die Bestellung steht.

**Zwei echte Fehler gefunden und behoben:**

1. `ROUND(qty * sellprice * (1 - discount), 2)` — `invoice.discount` ist `real`,
   der Ausdruck damit `double precision`, und `ROUND(double precision, integer)`
   gibt es in PostgreSQL nicht. Jetzt mit `::numeric`.
2. Division durch Null, wenn `defaults.precision` 0 oder nicht gesetzt ist —
   der Rundungsschritt steht im Nenner. Der Shop meldete nur „division by
   zero". Jetzt `COALESCE(NULLIF(precision, 0), 0.01)`.

### Was noch nicht geprüft ist

- **PDF-Erzeugung.** Der Aufruf ist verdrahtet und erreicht `loadPrintData()`;
  ein vollständiger Lauf braucht ein echtes kivitendo-Schema mit gefüllter
  `defaults`-Zeile. Der Versuch über eine schemagleiche Kopie scheiterte an
  Fremdschlüsseln der `defaults`-Zeile (`bin_id`) — mit einer echten
  Mandanten-Datenbank ist das in Minuten nachzuholen.
- **Mailversand.** Braucht eingerichtetes SMTP. Geprüft ist, dass ein
  Fehlschlag den Kauf nicht kippt.
- **PayPal.** Braucht Zugangsdaten. Geprüft ist, dass ohne sie sauber
  `SHOP_CONFIG_MISSING` unter Nennung des fehlenden Schlüssels kommt.

### Zwei PayPal-Zugangsdatenpaare

Nachgetragen, nachdem der erste Entwurf nur eines vorsah: PayPal vergibt für
Test- und Echtbetrieb getrennte Kennungen, und die `passwd.php` der Bridge
hielt beide vor (`if(!$PAYPAL_SANDBOX)`). Mit nur einem Paar müsste man sie
beim Umschalten jedes Mal austauschen — und beim Zurückschalten wieder.

Vier Einstellungen statt zwei: `shop_paypal_live_client_id`,
`shop_paypal_live_secret`, `shop_paypal_sandbox_client_id`,
`shop_paypal_sandbox_secret`. Welches Paar gilt, entscheidet derselbe Schalter,
der auch die Adresse bestimmt (`shop_paypal_sandbox`) — so können die beiden
nicht auseinanderlaufen. Beide Geheimnisse stehen in der Ausschlussliste von
`getCompanyConfig`, `getShopStatus()` prüft das jeweils aktive Paar.

Das Schema zieht einen Altbestand aus der ersten Fassung in die
Echtbetrieb-Schlüssel und löscht die alten Zeilen — auch auf Datenbanken, die
die erste Fassung nie hatten.

`web/oserp/einstellungen-uebernehmen.php` im Bridge-Repo liest beide Paare aus
der `passwd.php`. Welcher Zweig welcher ist, entscheidet dort nicht die
Reihenfolge, sondern die Bedingung im Quelltext: vertauschte Zugangsdaten wären
ein teurer Fehler. Findet sich die Weiche nicht, kommt eine Warnung und gar
nichts — die Zugangsdaten gehören dann von Hand eingetragen.

**Nachgemessen:** Schema-Übergang übernimmt den Altbestand und räumt die alten
Schlüssel weg; die Adresse folgt dem Schalter; und über einen Ersatzdienst an
PayPals Stelle, dass bei aktiver Testumgebung tatsächlich die
Testumgebung-Zugangsdaten hinausgehen.

### Test- und Echtbetrieb im Einstellungen-Tab

Vier fast gleich benannte Felder untereinander machen den Tab unlesbar, und
welches Paar gerade gilt, stünde nirgends. Der Tab kennt deshalb einen
Feldtyp `group`: eine Karte mit Überschrift, Erklärung und den enthaltenen
Feldern. `activeWhen` nennt Feld und Wert, bei dem die Gruppe gilt — hier
jeweils `shop_paypal_sandbox`. Die geltende Karte steht hervorgehoben
(`variant="tonal"`, Merkmal "gilt"), die andere zurückgenommen
(`variant="outlined"`, "gilt nicht"); beide bleiben bearbeitbar, damit sich
das ruhende Paar vor dem Umschalten eintragen lässt.

`shop_paypal_mock_response` steht in der Testumgebung-Gruppe, weil der
Fehlertest nur dort wirkt. Der Schalter selbst und die Zahlungsarten stehen
über den Karten — sie entscheiden, welche gilt.

Die Felddarstellung liegt in
`src/core/views/config/tabs/shop-config-field.component.vue`, damit dieselben
Feldtypen auf oberster Ebene und innerhalb einer Gruppe gleich aussehen.
`normalizeShopDefaults()` läuft über die Felder in Gruppen mit, sonst blieben
die vier Zugangsdatenfelder beim Laden unbehandelt.

### PayPal-Fehlertest

Nachgerüstet als Einstellung `shop_paypal_mock_response` (in der Bridge die
Konstante `PAYPAL_MOCK_RESPONSE`). PayPal beantwortet einen Aufruf damit mit
einem bestimmten Fehler, statt ihn auszuführen — die Grundlage für Fehlertests
im Kaufablauf.

Aufbau: `create:CODE`, `capture:CODE` oder `read:CODE`; ohne Vorsilbe gilt der
Code für alle drei Aufrufe. Ein vollständiges JSON
(`{"mock_application_codes":"…"}`) geht auch und wird nie als Vorsilbe
missverstanden. Beispiel: `capture:TRANSACTION_REFUSED`.

Zwei Absicherungen aus der Bridge übernommen: die Kopfzeile entsteht im
Echtbetrieb gar nicht erst (nicht erst „später wieder herausnehmen"), und jeder
erzwungene Aufruf schreibt eine Warnung ins Protokoll — eine erzwungene
Ablehnung sähe dort sonst genauso aus wie eine echte. Dazu neu: Antwortet
PayPal mit HTTP 403 und leerem Rumpf, steht der Hinweis im Protokoll, dass der
Code nicht zum Aufruf gehört; ohne ihn trifft eine leere Antwort ebenso einen
Proxy, eine Zeitüberschreitung oder eine Drosselung.

Eine schwebende Buchung (`PENDING`) lässt sich damit nicht erzeugen: der
Katalog besteht aus Fehlern, `PENDING` ist eine erfolgreiche Antwort mit
zurückgehaltener Buchung.

**Nachgemessen** (22 Prüfungen): wann die Kopfzeile entsteht und wann nicht
(leere Einstellung, Echtbetrieb, passende und unpassende Vorsilbe), dass die
Vorsilbe abgeschnitten wird, dass ein JSON unverändert durchgeht und sein
Doppelpunkt nicht als Vorsilbe gelesen wird, dass eine unbekannte Vorsilbe Teil
des Codes bleibt, dass der Protokolleintrag entsteht — und über einen lokalen
Ersatzdienst, dass die Kopfzeile beim Empfänger ankommt, bei `capture` aber
nicht bei `create`.

**Dabei ein echter Fehler gefunden:** `shopConfig()` schlüsselte seinen
Zwischenspeicher über `spl_object_id`. Diese Kennung wird nach dem Freigeben
eines Objekts neu vergeben — eine frisch aufgebaute Verbindung erbte die
Kennung einer zerstörten und bekam deren Einstellungen. Genau der Fall, gegen
den die Trennung gedacht war. Jetzt eine `WeakMap`, die über das Objekt selbst
schlüsselt.

## Stufe 6 — was angelegt wurde

`src/features/shop/` nach dem Muster von `banking`:

| Datei | Inhalt |
| --- | --- |
| `composables/useShop.js` | Zugriff auf `/api/shop/`, Fehler landen in `error` statt zu werfen |
| `views/shop.hub.vue` | Einrichtungsstand, drei Kennzahlen, Wege zu den Ansichten |
| `views/shop.orders.vue` | Bestellungen und schwebende Zahlungen in zwei Reitern |
| `views/shop.withdrawals.vue` | Widerrufe, ausklappbar, als bearbeitet vormerkbar |
| `locales/*.json` | 21 Sprachen, 53 Schlüssel je Sprache |

Drei Routen (`shop-overview`, `shop-orders`, `shop-withdrawals`) über
`routePath('ShopView.routes.*')`, also mit Pfad in der aktiven Sprache und den
übrigen 20 als Alias. Der Menüeintrag in `navigation.cards.js` erscheint nur
bei aktiver Erweiterung, ebenso liefern die Routen sonst die
Nicht-gefunden-Seite — ohne das stünde die Adresse jedem offen, der sie kennt.

### Wo der Einrichtungsstand hingehört

Die Prüfung aus `getShopStatus()` steht oben im Hub und nicht in den
Einstellungen: dort sieht man die einzelnen Felder, aber nicht, ob das
Zusammenspiel stimmt — ob es den Versandartikel wirklich gibt zum Beispiel.
Getrennt wird, was den Betrieb verhindert (rote Meldung) von dem, was ihn nur
einschränkt (blaue). Die Feldnamen kommen aus `crm_fields`, die der
Einstellungen-Tab ohnehin trägt; ein gemeldeter Punkt ohne solchen Schlüssel
(`parts_ext`) bleibt als Schlüsselname stehen, statt eine leere Zeile zu zeigen.

### Schwebende Zahlungen

Der zweite Reiter der Bestellansicht ist der Ort, an dem jemand hinsieht —
solange das niemand tut, bleibt eine schwebende Zahlung offen stehen. Der
Abgleich fragt bei PayPal nach und trägt das Ergebnis ein; der Hinweis darunter
sagt, dass bestätigte Zahlungen damit vermerkt, aber nicht gebucht sind.

### Nachgemessen

- `npm run check:routes`: Routennamen in allen 21 Sprachen identisch, keine
  Pfad-Kollision (1538 URLs), 41.454 Kreuzproben bestanden, keine der 52 alten
  URLs kaputt
- `npm run build` fehlerfrei; die drei Ansichten werden als eigene Bündel
  ausgeliefert und nur bei Bedarf geladen
- `npm run check:api` ohne Beanstandung
- Vollständigkeitsprüfung der Übersetzungen: 53 Schlüssel in jeder der 21
  Sprachen, keine Lücke

### Noch offen

**Artikel-Shopdaten** — inzwischen erledigt, siehe den folgenden Abschnitt.

### Artikel-Shopdaten in der Artikelmaske

Voraussetzung war, dass der Kern Artikel überhaupt anlegen kann: die
Artikelmaske (`src/core/views/article/article.edit.view.vue`) hat dafür einen
Neu-Modus unter der Route `article-new` bekommen, erreichbar über
Stammdaten → „Neuen Artikel anlegen" und die Artikelliste. `createPart` prüft
eine vorgegebene Artikelnummer jetzt selbst (`PARTNUMBER_EXISTS`).

Die Shop-Angaben stehen in einer Karte der Erweiterung
(`src/features/shop/components/part-shop.card.vue`). Die Artikelmaske lädt sie
per `defineAsyncComponent` nur bei aktiver Shop-Erweiterung — wie der Router die
Shop-Ansichten.

- **„Im Shop anbieten"** entscheidet über die `parts_ext`-Zeile. Nur Artikel
  mit Zeile findet die Shop-Suche (`JOIN`). Ohne diesen Schalter hätte jede
  Bearbeitung bei aktivem Shop eine Zeile angelegt. Ausschalten löscht die
  Zeile über die neue Aktion `deletePartShopData`; Warenkorb und Rechnungen
  verknüpfen per `LEFT JOIN` und behalten ihre Positionen.
- **Bearbeiten:** die Karte lädt selbst (`getPartShopData` meldet jetzt
  `listed`) und speichert Änderungen nach 800 ms; gleiche Daten werden nicht
  erneut gesendet.
- **Neuanlage:** „Im Shop anbieten" ist eingeschaltet — wer bei aktivem Shop
  einen Artikel anlegt, meint ihn in der Regel für den Shop. Die Karte sammelt
  nur; die Maske ruft nach `createPart` `saveFor(neueId)` auf, und erst dann
  wechselt die Route auf `article-edit`. Vorhandene Artikel zeigen den
  gespeicherten Stand.
- **Produktseite:** leer bedeutet den Vorschlag der Maske — Nummer und
  Beschreibung als Pfad aus Kleinbuchstaben, Ziffern und Bindestrichen, wie die
  Produktseiten der bisherigen Shops heißen. Mit `shop_products_link` zeigt die
  Karte einen Link auf die Seite, mit `shop_thumbnails_link` Bildvorschauen.
- **Technische Daten, Eigenschaften, Downloads** sind Objekte aus Bezeichnung
  und Wert (Downloads: Anzeigename → Dateiname, so auch in den Daten der
  Bridge). `savePartShopData` speichert sie mit `JSON_FORCE_OBJECT`, damit ein
  leeres nicht als `[]` ankommt. Der Spaltenkommentar sprach bei den Downloads
  von einem Array und ist korrigiert.
- **Rechte:** bearbeitbar mit `shop_part_edit` oder `edit_shop_config`, sonst
  nur lesbar.

Nicht im Browser gesehen, und die Abfragen nicht gegen eine Datenbank
ausgeführt. Geprüft sind Syntax, Übersetzbarkeit der Vue-Dateien, `check:api`
und die Vollständigkeit der Texte in allen 21 Sprachen.

**Die Ansichten sind nicht im laufenden System gesehen worden.** Geprüft sind
Übersetzung, Routen und Build; wie sie sich mit echten Daten anfühlen, zeigt
erst der erste Aufruf im Browser.

## Stufe 7 — was angelegt wurde

Diese Stufe berührt **das Bridge-Repository**, nicht OpensourceERP:
`/home/worker/Projekte/dev.hugoshop.dev/kivitendo_bridge/`.

### Das `shop-ui` verträgt jetzt beide Backends

Damit lässt sich der Shop neu bauen, bevor umgeschaltet wird — und im Zweifel
zurückschalten, ohne ihn erneut zu bauen.

| Datei | Änderung |
| --- | --- |
| `src/core/api.js` | Antwort mit Hülle (`payload`) und ohne; `login`/`logout` versuchen erst `shopLogin`/`shopLogout` und fallen auf die alten Namen zurück |
| `src/core/dates.js` | neu: nimmt `15.03.2026` und `2026-03-15` an |
| `src/core/cart-data.js` | `thumbnail` mit Rückfall auf den Schreibfehler `tumbnail` |
| `src/components/shop-account-orders.js` | dasselbe für die Rechnungspositionen, Datum über `formatDate()` |
| `shop-login.js`, `shop-register.js`, `shop-account-buttons.js` | rufen die neuen Helfer statt `apiRequest('login')` |

Preise brauchten keine Änderung: `src/core/money.js` wandelt jeden Betrag beim
Eintreffen in eine Zahl um und verkraftet beide Schreibweisen. Dass das
Backend jetzt Zahlen statt formatierter Zeichenketten liefert, merkt die
Oberfläche nicht.

### Der Proxy

`web/oserp/shop-api-proxy.php` tritt an die Stelle von `web/shop-api/index.php`.
Er hält den Aufruf same-origin — sonst schickt der Browser das Cookie
`HUGOSHOPCLIENTID` (`SameSite=Strict`) nicht mit und der Warenkorb wäre bei
jeder Anfrage leer — und trägt den Shop-Schlüssel nach, sodass der Browser ihn
nie sieht.

Weiterleitungen folgt er bewusst nicht: der Rückweg von PayPal gehört in den
Browser des Kunden, nicht in den Proxy. Durchgereicht werden nur die
Kopfzeilen, die der Browser braucht (Inhaltstyp, Cookie, Weiterleitung,
Dateiname beim PDF).

`web/oserp/README.md` beschreibt beide Wege — Reverse-Proxy im Webserver
(nginx und Apache, ohne PHP-Prozess je Anfrage) und den PHP-Proxy für
Umgebungen ohne Zugriff auf die Serverkonfiguration — samt Reihenfolge beim
Umschalten.

### Nachgemessen

16 Prüfungen über den vollständigen Weg: Shop-Oberfläche → Proxy → Erweiterung,
mit zwei Webservern und einer Testdatenbank.

- Der Aufruf kommt **ohne Schlüssel** durch — der Proxy trägt ihn nach
- Die Sitzung hält über mehrere Anfragen (Cookie wird durchgereicht)
- Warenkorb, Konto, Anmeldung mit `shopLogin`, Bestellung, Rechnungslink,
  Bestellliste
- Der alte Aktionsname `login` bleibt am öffentlichen Zugang verwehrt
  (`API_ACTION_NOT_ALLOWED`) — genau der Fehlercode, auf den der Rückfall im
  `shop-ui` reagiert
- Eine Weiterleitung (302 mit `Location`) wird nicht verschluckt
- Ohne konfigurierten Schlüssel meldet der Proxy HTTP 503 und
  `SHOP_PROXY_NOT_CONFIGURED`
- `npm run build` des `shop-ui` fehlerfrei (152 kB)

### Was der Umstellung noch fehlt

Das Ganze ist gegen Testdatenbanken geprüft, nicht gegen den echten Shop. Vor
dem Umschalten von `sonic24.de`:

1. Erweiterung beim Mandanten aktivieren, Schema-Update laufen lassen
2. Shop-Einstellungen füllen, bis die Übersicht grün ist
3. `shop-ui` neu bauen und veröffentlichen (läuft weiter gegen die Bridge)
4. Proxy einrichten — ab hier läuft der Shop gegen OpensourceERP
5. PDF und Mailversand prüfen: beides ist bisher nirgends vollständig gelaufen
