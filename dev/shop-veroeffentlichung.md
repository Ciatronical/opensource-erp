# Shop: Inhaltsdateien erzeugen (Veröffentlichung)

Plan für den Erzeuger der Produktseiten. Grundlage ist die Analyse vom
2026-09-11; der Bestand ist in `dev/shop-migration.md` beschrieben.

## Ausgangslage

Die Produktseiten des Shops entstehen bisher ausschließlich im Bridge-Projekt,
in `sonic24.de/publish/run.php`:

- **Quelle** ist die Lieferantentabelle `parts_sonic24_source`, befüllt von
  einem eigenen Auslese-Projekt und nachbearbeitet per SQL und ChatGPT.
- Das Skript legt Artikel in `parts` an, schreibt `parts_ext`, erzeugt je
  Artikel eine Markdown-Datei in `content/de/produkt/` über die PHP-Vorlage
  `publish/template.php`, rechnet ein Vorschaubild und baut die Seite mit Hugo.
- Alles ist fest verdrahtet: absolute Serverpfade in `publish/config.php`,
  Marke, Buchungsgruppe, Versandkosten und Steuersatz im Quelltext.

**Die Lücke:** Ein Artikel, der in OSERP mit „Im Shop anbieten" angelegt wird,
bekommt keine Produktseite. Der Suchtreffer führt ins Leere, und die
Warenkorb-Schaltfläche fehlt — sie steht im Seiteninhalt, nicht im Theme.

## Ziel

OSERP erzeugt die Inhaltsdateien aus `parts` und `parts_ext`, mit
austauschbaren Vorlagensätzen und einstellbaren Pfaden. Das Aussehen der
Webseite bleibt beim Hugo-Projekt.

## Vorbild im Bestand

| Woher | Was übernommen wird |
| --- | --- |
| Druckvorlagen (`backend/api/print/print.php`) | Vorlagensatz als Name, Auflösung über Kundenkopie und mitgelieferten Satz, Liste für die Auswahl, Verzeichnis aus `settings.ini` |
| Mailvorlagen des Shops (`shopMailTemplate`) | Vorlage ist PHP, gefüllt über Ausgabepuffer und `include` |
| Demo-Reset (Host-Cron-Watcher) | Die Anwendung löst aus, ausgeführt wird außerhalb |
| `batchjob_hugoshop` (schon im Schema) | Auftragstabelle für den Läufer |

## Vorlagensätze

Ein Satz ist ein Verzeichnis. Gesucht wird nach demselben Muster wie beim Druck:

```
backend/templates-default/shop/<name>/     mitgeliefert
<templates_dir>/shop/<name>/               Kundenkopie, eigenes Repository
```

Die Kundenkopie hat Vorrang. Gewählt wird über die Einstellung
`shop_template_set` (nur ein Name, kein Pfad).

Bringt ein Satz eine Datei nicht mit — eine Vorlage, die Regeln der
Kategorieübersicht, eine Datei des Webseiten-Pakets —, gilt die des
mitgelieferten Satzes `standard`. Ein eigener Satz enthält so nur, was er
ändert; `theme.json` gehört immer dazu. Beispiel: `sonic24` besteht aus
`theme.json` und `category_groups.php`.

| Datei | Zweck |
| --- | --- |
| `theme.json` | Name, Beschreibung, passendes Hugo-Theme, Ausgaben mit Zielpfad-Muster |
| `product.md.php` | Produktseite |
| `category_groups.php` | Regeln der Kategorieübersicht (Bridge-Ablösung, Stufe C) |
| `_helpers.php` | Maskierung für YAML, Preis- und Zahlenformat |

**Schnittstelle zur Vorlage** ist ein einziges Array, kein Schwarm von
Einzelvariablen wie in der Bridge:

```php
$seite = [
    'artikel' => [...],   // Nummer, Bezeichnung, Preise, Einheit, EAN, Bestand
    'shop'    => [...],   // Kategorie, Navigationspfad, Bilder, Downloads, technische Daten
    'betrieb' => [...],   // Firma, Währung, Steuersatz, Versandkosten aus den Einstellungen
];
```

So bleibt ein Satz austauschbar, ohne den Erzeuger anzufassen.

## Pfade und ihre Grenze

Neue Einstellungen je Mandant: Zielverzeichnis der Inhalte, Verzeichnisse für
Bilder, Vorschaubilder und Downloads, Wurzel des Hugo-Projekts.

Die Einstellungen stehen in `defaults_oserp` und sind für jeden Mitarbeiter mit
Shop-Recht änderbar. Ein frei wählbarer Schreibpfad wäre damit ein
Schreibzugriff auf jedes Verzeichnis, das PHP erreicht. Deshalb:

- eine Wurzel in der `settings.ini` (`shop_sites_dir`), wie `templates_dir`,
- jeder eingestellte Pfad muss nach `realpath()` unterhalb dieser Wurzel liegen,
- der Vorlagensatz ist ein Name; Pfadtrenner und `..` sind nicht erlaubt.

Ohne gesetzte Wurzel schreibt der Erzeuger nichts und meldet das.

## Wer schreibt und wer baut

| Weg | Bewertung |
| --- | --- |
| Direkt aus dem Web-Backend, wie die Bridge | PHP-FPM bräuchte Schreibrechte im Webseitenverzeichnis und `exec()` für Hugo; 3.711 Seiten laufen in jedes Zeitlimit |
| **Auftragstabelle und CLI-Läufer** | gewählt: OSERP schreibt Aufträge nach `batchjob_hugoshop`, ein Skript rendert und baut |
| Gemischt | eine einzelne Seite sofort, der Vollbau über einen Auftrag |

Gegen den ersten Weg spricht nicht nur die Laufzeit: `--cleanDestinationDir`
löscht das ausgelieferte Verzeichnis. Das gehört nicht an einen Klick im
Browser.

## Datenlücken

| Angabe | Stand |
| --- | --- |
| EAN | `parts.ean` vorhanden, von OSERP bisher nicht gelesen |
| Bestand | `parts.onhand` vorhanden, ebenfalls nicht gelesen |
| Marketingtexte | fehlen — neues Feld in `parts_ext` oder `parts.notes` |
| Bilder, Downloads | liegen auf der Webseite, nicht in OSERP |
| Marke, Versandkosten, Rückgabefrist | in der Bridge fest verdrahtet, gehören in die Einstellungen |

Nebenbefund: kivitendo führt in `parts` ein eigenes Feld `shop`, das weder die
Bridge noch unsere Erweiterung nutzt. Wenn „Im Shop anbieten" es mitführt, zeigt
kivitendos eigene Oberfläche denselben Stand.

## Stufen

| Stufe | Inhalt | Stand |
| --- | --- | --- |
| 1 | Vorlagenauflösung, Satzauswahl, Rendern einer Produktseite in eine Datei, Pfadeinstellungen mit Wurzelgrenze, mitgelieferter Satz `standard` | **erledigt** |
| 2 | Auftragstabelle, CLI-Läufer, „Veröffentlichen" im Admin-Panel und in der Artikelkarte | **erledigt** |
| 3 | Bilder, Vorschaubilder, Downloads, Kategorieseiten, Sprachen, Preise inkl. Steuer, Bildadressen aus den Einstellungen | **begonnen:** Preise, Adressen, Vorschaubilder, Downloads, Kategorieübersicht erledigt; Sprachen offen |
| 4 | Voller Seiteninhalt aus `template.php` (Karussell, Reiter), Umstieg von sonic24, Doku | offen |

## Stufe 1 — was angelegt wurde

| Datei | Inhalt |
| --- | --- |
| `backend/api/shop/lib/publish.php` | Vorlagenauflösung, Pfadprüfung, Werte für die Vorlage, Rendern, Schreiben |
| `backend/templates-default/shop/standard/` | mitgelieferter Satz: `theme.json`, `product.md.php` |
| `backend/api/shop/admin.php` | `getShopTemplateSets`, `previewShopPage`, `writeShopPage` |
| `backend/api/config.php` | `OSERP_SHOP_SITES_DIR` aus `settings.ini` |
| `backend/upstall/shop/company_schema.sql` | `shop_template_set`, `shop_site_dir`, `shop_content_dir` |
| Einstellungen-Tab | Abschnitt „Veröffentlichung"; der Vorlagensatz ist eine Auswahl, gefüllt aus der Erweiterung |

**Warum PHP und nicht die Engine der Druckvorlagen.** `print/template_engine.php`
kann `<%variable%>`, `<%if%>` und `<%foreach%>`, maskiert aber fest für LaTeX
und ist mit Bau und PDF verwoben. Für Markdown müsste die Maskierung umgebaut
werden — an der Stelle, an der das Drucken hängt. Dazu ist die vorhandene
Vorlage des Beispielshops PHP und lässt sich als Satz fast unverändert
übernehmen. Eine Vorlage ist damit Code; sie stammt wie die Druckvorlagen vom
Betreiber, nicht von Benutzern.

**Einrichtung:** In der `settings.ini` unter `[system]` die Wurzel setzen, etwa
`shop_sites_dir = "/var/www/hugoshops"`. Ohne sie meldet der Shop
`SHOP_SITES_DIR_MISSING` und schreibt nichts.

**Geprüft** mit Ersatz-Datenbank und Wegwerf-Verzeichnissen:

- Satz gefunden und aufgelöst; ein Pfad als Satzname wird abgewiesen
  (`SHOP_TEMPLATE_SET_INVALID`), ein unbekannter Satz gemeldet
- Seite gerendert und geschrieben; das Front Matter liest sich mit einem
  YAML-Parser fehlerfrei zurück, samt Anführungszeichen, Backslash, Doppelpunkt
  und Zeilenumbruch in der Bezeichnung
- Dateiname kann nicht ausbrechen: aus `../../../etc/passwd` wird `passwd.md`
- Pfadgrenze hält in allen drei Fällen: `..` im eingestellten Pfad, Verknüpfung
  aus dem Webseiten-Verzeichnis heraus, fehlendes Verzeichnis

Nicht geprüft: der Lauf gegen eine echte Datenbank und im Browser.

## Risiken

- **Löschen:** Wird ein Artikel aus dem Shop genommen, muss die Seite
  verschwinden. Sonst bleibt eine Seite mit Warenkorb-Schaltfläche stehen.
- **Gleichzeitige Läufe** zerlegen das Zielverzeichnis; eine Sperrdatei ist
  Pflicht.
- **Artikel-Kennung:** Die Warenkorb-Schaltfläche trägt die `parts.id`. Seiten
  aus einer anderen Datenbank passen nicht.
- **Zeichensatz und YAML:** Anführungszeichen, Doppelpunkte und Zeilenumbrüche
  in Bezeichnungen brauchen echte Maskierung. Die Bridge behilft sich mit
  `str_replace('"', '\"')`.

## Stufe 2 — was angelegt wurde

Ohne Schemaänderung: `batchjob_hugoshop` wird so genutzt, wie die Bridge sie
angelegt hat (`id`, `function`, `partnumber`, `param`, `result`). Offen ist ein
Auftrag, solange `result` leer ist.

| Datei | Inhalt |
| --- | --- |
| `backend/api/shop/lib/publish.php` | `shopQueueJob`, `shopOpenJobs`, `shopJobResult`, `shopListedParts`, `shopRemovePage`, `shopRunJobs` |
| `tools/shop-publish.php` | Läufer für die Kommandozeile |
| `backend/api/shop/admin.php` | `publishShopPart`, `publishShopAll`, `getShopPublishJobs`; `deletePartShopData` legt jetzt einen Auftrag zum Entfernen der Seite an |
| `backend/api/config.php` | `OSERP_SHOP_PUBLISH_COMMAND` aus `settings.ini` |
| Artikelkarte, Shop-Übersicht | „Veröffentlichen" je Artikel, „Alle veröffentlichen" samt Auftragsliste |

**Aufträge:** `publish_part` (ein Artikel), `publish_all` (alle Artikel mit
Shop-Angaben), `remove_part` (Seite eines Artikels löschen, der aus dem Shop
genommen wurde). Ein gleicher, noch offener Auftrag wird nicht doppelt
angenommen.

**Der Läufer** arbeitet die Schlange ab und baut danach die Webseite:

```
php tools/shop-publish.php --client=<id> [--limit=500] [--no-build] [--quiet]
php tools/shop-publish.php --list-clients
```

Als Cron-Eintrag gedacht, etwa alle fünf Minuten. Eine Sperrdatei je Mandant
unter `backend/tmp/` verhindert zwei gleichzeitige Läufe — der Bau löscht das
ausgelieferte Verzeichnis. Der Baubefehl steht in der `settings.ini`
(`shop_publish_command`, ausgeführt im Verzeichnis der Shop-Webseite), nicht in
den Mandanteneinstellungen: einen Befehl soll niemand über die Oberfläche
setzen können. Ohne Befehl werden nur Dateien geschrieben.

**Fehler halten die Schlange nicht auf.** Jeder Auftrag bekommt sein Ergebnis
(`ok: …` oder `Fehler: …`), auch `publish_all` zählt fehlgeschlagene Artikel
einzeln und läuft weiter. Der Läufer endet mit Rückgabewert 1, wenn es Fehler
gab — für den Cron sichtbar.

**Geprüft** mit Ersatz-Datenbank: sechs Aufträge in einem Lauf — ein Artikel
geschrieben, ein unbekannter Artikel als Fehler vermerkt, eine Seite entfernt,
eine nicht vorhandene Seite sauber gemeldet, `publish_all` mit zwei Seiten, eine
unbekannte Funktion abgewiesen. Bilanz: 6 Aufträge, 3 Seiten, 2 Fehler, jeder
Auftrag mit eigenem Ergebnis. Dazu `php -l`, Vue-Compiler, `check:api`, Texte in
allen 21 Sprachen. Der Läufer meldet eine nicht erreichbare Auth-Datenbank im
Klartext und endet mit 1.

Nicht geprüft: ein Lauf gegen eine echte Datenbank, der Bau mit Hugo und die
Oberfläche im Browser.

## Zeitstempel für die Aufträge

Am 2026-09-11 freigegeben: `batchjob_hugoshop` bekommt die Spalte
`itime timestamp without time zone DEFAULT now()` (Name nach kivitendo-Brauch).
Sie steht im `CREATE TABLE` des Shop-Schemas; auf bestehenden Datenbanken trägt
der Upstall sie nach. Geprüft über `parseColumns()` aus
`backend/api/update/update.php`: die erzeugte Anweisung lautet
`ALTER TABLE … ADD COLUMN itime timestamp without time zone DEFAULT now()`.
Die Bridge schreibt nicht in diese Tabelle, nur ihr Läufer liest und
aktualisiert — die neue Spalte stört sie nicht.

Die Shop-Übersicht zeigt den Zeitpunkt in der Auftragsliste.

## Gemeinsame Auftragstabelle mit der Bridge

Der Läufer der Bridge (`kivitendo_bridge/batchjob/run.php`) liest *alle*
offenen Aufträge und vermerkt jeden, dessen Funktion er nicht kennt, als
Fehler. Unser Läufer tat bis Stufe 3 dasselbe umgekehrt. Auf einer gemeinsamen
Datenbank hätten sich beide gegenseitig die Aufträge verdorben.

Behoben auf unserer Seite: `shopOpenJobs()` und die Auftragsliste nehmen nur
`publish_part`, `publish_all` und `remove_part`. **Offen auf Seiten der
Bridge:** Solange ihr Läufer auf derselben Datenbank läuft, markiert er unsere
Aufträge als Fehler, bevor unser Läufer sie sieht. Entweder läuft nur einer der
beiden, oder `run.php` überspringt fremde Funktionen, statt sie als Fehler zu
vermerken.

## Stufe 3 — erster Teil

| Thema | Umsetzung |
| --- | --- |
| Preise | Netto und brutto in derselben Abfrage wie die Seite. Steuersatz wie in der Faktura (Buchungsgruppe → Steuerzone → Erlöskonto → Steuerschlüssel), aber nur Schlüssel mit `startdate <= current_date`. `shop_tax_included` wird beachtet. Ohne Schlüssel steht nur ein Preis auf der Seite. |
| Adressen | `shop_images_link`, `shop_thumbnails_link`, `shop_downloads_link` als Muster mit `%s`; Dateinamen werden kodiert |
| Vorschaubilder | `shop_images_dir` (Quelle) und `shop_thumbnails_dir` (Ziel) relativ zum Verzeichnis der Webseite, `shop_thumbnail_size` als längste Seite. Mit GD; Format, Seitenverhältnis und Transparenz bleiben, kleine Bilder werden nicht vergrößert. Ein Vorschaubild, das neuer ist als seine Quelle, bleibt stehen. |
| Downloads | Liste auf der Seite über `shop_downloads_link` |

Die fünf neuen Einstellungen sind Zeilen in `defaults_oserp`, keine
Schemaänderung.

Scheitert das Vorschaubild, bleibt die Seite trotzdem geschrieben; das Ergebnis
(`erzeugt`, `aktuell`, `Quelle fehlt`, `nicht eingerichtet`, `keine Bilder`,
`Fehler: …`) steht in der Meldung des Läufers.

**Geprüft** mit Ersatz-Datenbank und echten Bildern aus GD:

- 800 × 400 WebP wird 200 × 100 WebP; 300 × 600 PNG mit Transparenz wird
  100 × 200 und behält den Alphakanal
- zweiter Lauf: `aktuell`, das Vorschaubild wird nicht neu gerechnet
- fehlende Quelle, fehlendes Verzeichnis und Artikel ohne Bilder werden
  gemeldet, ohne die Seite zu verhindern
- Seite mit 51,93 EUR netto und 61,80 EUR brutto; ohne Steuerschlüssel nur ein
  Preis; `shop_tax_included` erreicht die Abfrage
- `anleitung de.pdf` wird zu `/downloads/anleitung%20de.pdf`

Nicht geprüft: die Steuerabfrage gegen eine echte Datenbank.

**Noch offen in Stufe 3:** mehrere Sprachen. Die Kategorieübersicht (in der
Bridge `generateCategoryGroups()`) ist mit Stufe C der Bridge-Ablösung
entstanden, siehe `dev/shop-bridge-abloesung.md`; dort steht auch, warum die
Sitemap bei Hugo bleibt und die Produktseite dafür `lastmod` trägt.

## Update beim Login

Aufgefallen mit dem Zeitstempel: Der Login stieß das Schema-Update nur an, wenn
eine seiner eigenen Abfragen an einer fehlenden Spalte oder Tabelle scheiterte
(`SQLSTATE 42703/42P01`). Eine neue Spalte in einer Erweiterungstabelle oder
neue Einstellungszeilen berührt der Login nicht — das Update blieb aus, und
der Fehler zeigte sich erst später, etwa in der Auftragsliste der
Shop-Übersicht.

Jetzt gibt es eine Prüfsumme je Upstall-Verzeichnis (`backend/api/lib/upstall.php`):

- **Berechnet** über genau die Dateien, die das Update anwendet:
  `auth_schema.sql`, `company_schema.sql`, `auth_data/*.csv`,
  `company_data/*.csv`. Andere Dateien (Sicherungskopien des Editors,
  `anpr_schema.sql`, `extension.json`) lösen nichts aus.
- **Gespeichert** als Zeile `upstall_checksum_<verzeichnis>` in
  `defaults_oserp` — keine Schemaänderung. Geschrieben von
  `updateAllDatabases()` je Mandant und von `updateSchema()`, jeweils nur nach
  einem erfolgreichen, echten Lauf.
- **Verglichen** beim Login für die CRM-Basis und alle aktiven Erweiterungen.
  Weicht eine Summe ab oder fehlt sie, meldet der Login
  `schema_update_needed`; der Store gibt `AuthStatus.UPDATE_REQUIRED` zurück,
  und die Login-Ansicht führt wie im Fehlerfall `updateAllDatabases` aus und
  meldet sich einmal neu an. Beim zweiten Mal wird nicht erneut aktualisiert,
  damit ein dauerhaft scheiterndes Update keine Schleife bildet.
- **Scheitert die Prüfung selbst** (etwa weil `defaults_oserp` noch fehlt),
  gilt das als „Update nötig" — genau das behebt es.

Beim ersten Login nach dieser Änderung läuft das Update einmal auf jeder
Installation: Es gibt noch keine gespeicherten Summen.

`AuthStatus.UPDATE_REQUIRED` stand schon in der Login-Ansicht, war aber nirgends
definiert — der Zweig verglich mit `undefined` und griff nie.

**Geprüft** mit Ersatz-Datenbank: Die Summen stimmen mit einer unabhängigen
Rechnung in Python überein; ausgelassen werden genau `#company_schema.sql#`,
`extension.json` und `anpr_schema.sql`. Ohne gespeicherte Summen → Update,
nach dem Speichern → aktuell, veraltete Shop-Summe → Update, fehlende
LxCars-Summe → Update, fehlende Tabelle → Update mit Protokolleintrag.
Unzulässige Erweiterungsnamen und solche ohne Upstall-Verzeichnis werden
übergangen.

Nebenbei: `shop_content_dir` hat jetzt auch im Code die Vorgabe
`content/de/produkt`; ohne die Zeile landeten die Seiten sonst direkt im
Verzeichnis der Webseite.
