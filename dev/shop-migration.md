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

1. **`DbhCompany::begin($pdo)` ignoriert seinen Parameter**
   (`backend/api/database.php:735`). Der Kundenzugang löst die Company-Datenbank
   über einen Shop-Schlüssel auf und muss die Verbindung setzen können.
2. **Der Aktionsmechanismus kennt keine Grenzen.** `api.call.php:16` prüft nur
   `function_exists()`, und `inc.php` lädt `auth.php` immer mit — `login`,
   `logout`, `getClients`, `restoreSession`, `switchClient` wären damit in jedem
   Modul erreichbar. Der öffentliche Einstiegspunkt bindet `inc.php` deshalb
   nicht ein und führt eine Allowlist.
3. **Namenskollision.** Von 55 Bridge-Funktionen kollidieren genau zwei mit
   OSERP: `login` und `logout` (`shop.session.php:219`/`:227` gegen
   `auth.php:84`/`:369`). Ohne Umbenennung auf `shopLogin`/`shopLogout` bricht
   PHP beim Laden ab.
4. **Same-Origin.** `shop-ui` ruft `/shop-api/` same-origin auf, das
   Kontext-Cookie steht auf `SameSite=Strict`. Nach der Migration liegt die API
   auf der OSERP-Domain. Empfohlen: Reverse-Proxy im Shop-Docroot, dann bleibt
   alles same-origin und das Shop-Frontend unverändert. Liegen beide Zugänge
   unter derselben Domain, schickt der Browser den OSERP-Sitzungscookie auch an
   den Kundenendpunkt — der muss ihn ausdrücklich ignorieren.
5. **Antwortformat.** Der Kundenzugang liefert heute keinen einheitlichen
   Rahmen (siehe Kopfkommentar in `shop-ui/src/core/api.js`). Entschieden:
   OSERP-Format mit `payload` auch für den Kundenzugang; im `shop-ui` ändert
   sich dafür eine Zeile (`return data.payload ?? data`). Beim Portieren
   beachten: die Bridge-Signatur `resultInfo($success, $text, $debug)` führt an
   dritter Stelle etwas anderes als OSERP.

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
| 3 | Fachschicht: Kontext, Warenkorb, Konto, Suche | offen |
| 4 | Beide Einstiegspunkte, Allowlist, `shopLogin`/`shopLogout` | offen |
| 5 | Rechnung und Zahlung auf Faktura, Print und E-Mail | offen |
| 6 | `src/features/shop/` — Admin-Panel, Routen in 21 Sprachen | offen |
| 7 | `shop-ui` auf das Antwortformat umstellen, Proxy einrichten | offen |

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
- **Versandartikel** wird nur noch angelegt, wenn er fehlt.
  `install.sql:165` schreibt `ON CONFLICT DO UPDATE SET … sellprice = 7.90` und
  hätte damit bei jedem Schema-Update den vom Betreiber gepflegten Versandpreis
  zurückgesetzt.
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
