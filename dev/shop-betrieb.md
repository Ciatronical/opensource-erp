# Shop: Betrieb

Was im Alltag mit der Erweiterung `shop` zu tun ist und wo es steht. Wie die
Teile entstanden sind, beschreiben `shop-migration.md` (Fachlogik),
`shop-veroeffentlichung.md` (Produktseiten) und `shop-bridge-abloesung.md`
(Ablösung der Kivitendo-Bridge); den Widerruf `shop-widerruf.md`.

## Die Teile

| Teil | Ort | Aufgabe |
| --- | --- | --- |
| Admin-Panel | `src/features/shop/` | Mitarbeiter: Übersicht, Bestellungen, Zahlungen, Widerrufe, Veröffentlichung |
| Mitarbeiter-API | `backend/api/shop/` | normale OpensourceERP-Sitzung, Rechte über `permit()` |
| Kundenportal (Kern) | `backend/api/portal/`, Ansicht System → Webseiten | Webseiten (`website_oserp`) mit Zugang, Adresse, HugoCMS und Vorlagensatz; Sitzung, Konto, Rechnungen, Kontakt, Freischaltung; Paket und HugoCMS-Transport (`dev/kundenportal.md`) |
| Öffentlicher Zugang | `backend/portal/index.php` | Kunden des Betreibers; Ausweis über den Kopf `X-Portal-Key` der Webseite, Aktionen des Kerns plus `public/actions.php` jeder aktiven Erweiterung |
| Fachschicht | `backend/api/shop/lib/` | Warenkorb, Kasse, Suche, Rechnung, Zahlung, Mail, Widerruf, Umleitungen, Veröffentlichung, Kategorien; hängt über `lib/portal_hooks.php` am Portal |
| Webseiten-Paket | `backend/templates-default/portal/standard/kit/` (Kern: Konto-Shortcodes, Partials, Proxy, Bündel) + `backend/templates-default/shop/standard/kit/` (Shop: Warenkorb, Kasse, Suche, 404-Seite) | geht zusammen als `oserp-portal/` an HugoCMS |
| Widgets | `portal-ui/` | Web Components (Lit): `portal-widgets.js`, `shop-widgets.js`, `lxcars-widgets.js`; eigener Build ins Paket |
| Läufer | `tools/shop-publish.php` | Aufträge abarbeiten, Paket abgleichen, Kategorien, an HugoCMS übertragen und dort bauen lassen |
| Übernahme | `tools/shop-bridge-settings.php` | Einstellungen einer Bridge-Instanz als SQL ausgeben |
| Medienbibliothek | `backend/api/shop/media.php`, `lib/media.php`, Medien in der Artikelkarte | Produktbilder und Downloads zentral für alle Verkaufskanäle (`dev/shop-medienbibliothek.md`); Übernahme vorhandener Medien mit `tools/shop-media-import.php` |

Die Webseite selbst — Hugo-Projekt, Theme, Inhalte — gehört dem Betreiber und
liegt bei HugoCMS. Veröffentlicht wird ausschließlich über HugoCMS: OpensourceERP
erzeugt alles in seiner Bereitstellung unter `backend/tmp/`, überträgt es über
die Shop-Erweiterung von HugoCMS und lässt die Webseite dort bauen
(`dev/shop-hugocms-trennung.md`). Wohin es schreiben darf, legt ein
Administrator in HugoCMS fest — die **Freigaben** in den Projekteinstellungen
(Produktseiten, Kategorieübersicht, Produktbilder, Vorschaubilder, Downloads;
das Paket liegt fest in `oserp-portal/`). OpensourceERP zeigt sie in der
Kanalkarte nur an und übernimmt sie bei jedem Lauf
(`dev/shop-hugocms-verzeichnisse.md`). Produktbilder, Vorschaubilder und
Downloads kommen aus der Medienbibliothek und gehen mit jeder Lieferung an die
Webseite (`dev/shop-medienbibliothek.md`).

## Eine Webseite einrichten

1. **Erweiterung** `shop` beim Mandanten aktivieren, Schema-Update laufen lassen.
2. **Firmenkonfiguration → Shop:** die Angaben für den ganzen Mandanten
   (Rechnungsstellung, Bankverbindung, Lagerplatz, Bestellungen, siehe
   „Einstellungen“).
3. **System → Webseiten** (Recht „Webshops anlegen und bearbeiten“): die
   Webseite des Kundenportals anlegen. In ihrer Karte:
   - **Shop-Schlüssel** — der Knopf neben dem Feld erzeugt ihn (32 zufällige
     Byte, hexadezimal). Er unterscheidet Mandanten und Webseiten; zwei
     Webseiten dürfen nicht denselben haben. Die Webseite bekommt ihn über
     `oserp-portal/config.json`, der Browser sieht ihn nie.
   - **Adresse des Portal-Zugangs von OpensourceERP** — `backend/portal/`
     (etwa `https://erp.example.de/portal/`; `…/shop/` gilt weiter), mit
     „Verbindung prüfen“. Ein leeres Feld belegt die Karte mit der Adresse
     vor, unter der OpensourceERP gerade aufgerufen wird (lokal
     `http://localhost:8000/portal/`). Der Webserver von OpensourceERP muss
     `/portal` dorthin geben (Apache: `Alias /portal …/backend/portal`).
   - Adresse der Webseite, Seiten (Rechnung, Konto freischalten),
     Vorlagensatz, Empfänger des Kontaktformulars.
   - **HugoCMS:** Adresse (`…/cms-api/`) und Schlüssel aus HugoCMS (siehe 4),
     „Verbindung prüfen und Freigaben abrufen“; „Paket installieren“ überträgt
     Vorlagen, Widgets und Weiterleiter und lässt bauen.
   Dann **Shop-Menü → Verkaufskanäle:** einen HugoShop anlegen und in seiner
   Karte die **Webseite** wählen; dort bleiben Adressmuster, PayPal, Mails.
   Passen die Adressmuster nicht zu den Freigaben, bietet die Karte nach
   „Verbindung prüfen“ einen Vorschlag an.
4. **HugoCMS → Projekteinstellungen → OpensourceERP-Anbindung** (nur
   Administratoren): die Anbindung einschalten, einen Schlüssel erzeugen und
   die **Freigaben** prüfen — Verzeichnis der Produktseiten (Vorgabe
   `content/de/produkt`), Kategorieübersicht (`data/category_groups.json`, so
   erwartet sie der Vorlagensatz `standard`), Produkt- und Vorschaubilder.
5. **Signaturschlüssel:** in den Systemeinstellungen von OpensourceERP erzeugen,
   den öffentlichen Teil in HugoCMS eintragen (Shop-Erweiterung →
   Signaturschlüssel). Dann überträgt der Lauf Weiterleiter und 404-Seite
   selbst; ohne ihn legt man sie von Hand ab (siehe „Einstellungen“).
6. **Hugo-Konfiguration der Webseite:** die Mounts `oserp-portal/static` →
   `static`, `oserp-portal/layouts` → `layouts` und `oserp-portal/assets/portal-ui`
   → `assets/portal-ui`, jeweils **nach** den eigenen Einträgen. Optional
   `params.portalui` für das Aussehen der Widgets und `params.portalui.paths` für
   die Pfade der Seiten (`kit/layouts/partials/portal-ui-assets.html`,
   `portal-ui/README.md` „Pfade der Seiten“). Der Partial lädt die Bündel nach
   `features.json` im Paket: den Kern immer, den Shop mit HugoShop, die
   Fahrzeuge mit LxCars.
   - **Shop unterhalb einer bestehenden Webseite** (etwa eine Werkstatt mit
     ein paar Artikeln unter `https://werkstatt.example/shop/`): Seiten unter
     `content/shop/`, `"paths": { "base": "/shop/" }`; in HugoCMS die
     Freigabe „Produktseiten“ auf `content/shop/produkt`; in der Kanalkarte
     Adressmuster `/shop/produkt/%s/` und Rechnungsseite `/shop/rechnung/`.
     Der Weiterleiter bleibt `/portal-api/`.
   - **Eigene Hugo-Seite unter einem Pfad** (`baseURL` mit `/shop/`): keine
     Angabe nötig, der Partial leitet die Pfade aus der baseURL ab; in der
     Kanalkarte die Adresse der Shop-Webseite mit Pfad eintragen
     (`https://werkstatt.example/shop/`), der Weiterleiter liegt dann unter
     `/shop/portal-api/`.
7. **Webserver der Webseite:** PHP für `portal-api/index.php` und `not_found.php`,
   die 404 an `/not_found.php` (nginx: `error_page 404 /not_found.php;`, Apache: `ErrorDocument 404 /not_found.php`).
8. **„Shop-Benutzerschnittstelle installieren“** in der Shop-Übersicht, danach
   die Cron-Einträge für Läufer und Zahlungsabgleich.

## Der Läufer

```
php tools/shop-publish.php [--client=<id>] [--db=<name>] [--limit=500]
                           [--no-build] [--quiet] [--reconcile-payments]
php tools/shop-publish.php --list-clients
```

Ein Lauf tut der Reihe nach:

1. **Freigaben** je HugoShop bei HugoCMS abfragen (`shopbuildstatus`). Ist
   HugoCMS nicht erreichbar, die Shop-Erweiterung dort ausgeschaltet oder eine
   Freigabe unbrauchbar, bleiben die Aufträge des Kanals ausgesetzt, und der
   Lauf meldet einen Fehler. Hat ein Administrator eine Freigabe verlegt, zieht
   die Bereitstellung nach (`shopStagingFollowGrants`); HugoCMS löscht die
   Seiten am alten Ort mit der Übertragung, weil sie aus einer früheren
   Lieferung stammen.
2. **Aufträge** aus `batchjob_hugoshop` abarbeiten, älteste zuerst. Die Seiten
   entstehen in der Bereitstellung des HugoShops
   (`backend/tmp/shop-publish-<db>-<kanal>-staging/`), im Verzeichnis der
   Freigabe „Produktseiten“.
3. **Paket abgleichen** — bei jedem Lauf; er vergleicht Prüfsummen und kopiert
   nur Geändertes nach `oserp-portal/` der Bereitstellung.
4. **Kategorieübersicht** erneuern, wenn Seiten geschrieben oder entfernt
   wurden — an den Ort der Freigabe; erwartet der Vorlagensatz sie anderswo,
   warnt der Lauf.
5. **An HugoCMS übertragen** — Abgleich, Übertragung, Übernahme
   (`shopHugoCmsSync`) — und **dort bauen lassen**, wenn sich etwas geändert
   hat. Mit der Lieferung gehen die Medien aus der Medienbibliothek
   (Produktbilder, Vorschaubilder, Downloads) in ihre Freigaben: als
   Binärdaten in Stücken, nur was in HugoCMS fehlt oder abweicht; vorher
   erneuert der Lauf Vorschaubilder, die fehlen oder nicht die eingestellte
   Größe haben. Danach nennt er, welche in den Artikeln genannten Dateien in
   der Bibliothek fehlen. Gegen ein HugoCMS ohne Medienübernahme (vor
   2026-10-15) bleibt es beim alten Verfahren: Vorschaubilder in HugoCMS und
   die Prüfung der Download-Dateien auf der Webseite. Gebaut wird mit dem
   Hugo-Programm, das HugoCMS eingerichtet hat; OpensourceERP startet kein
   Programm zum Bauen.
6. **Prüfung über die Webseite** nach einer Übertragung: Weiterleiter und
   404-Seite (siehe „Einstellungen“).

Je Mandant läuft nur einer. Zwei Sperren sichern das:

- eine **Sperrdatei** in `backend/tmp/` — sie fängt zwei Läufer auf demselben
  Rechner ab, bevor überhaupt eine Verbindung aufgebaut wird;
- eine **Beratungssperre in der Datenbank** des Mandanten
  (`pg_try_advisory_lock`) — sie gilt über Prozesse, Benutzer und Rechner
  hinweg und hält damit auch den Läufer und den Knopf „Jetzt ausführen"
  auseinander. Gesperrt wird dabei weder Tabelle noch Zeile; der übrige
  Betrieb merkt nichts davon. Wer sie nicht bekommt, tut nichts und meldet
  das.

Ein zweiter Lauf endet damit ohne Fehler, nachdem er das gemeldet hat. Der
Rückgabewert ist 1, sobald ein Auftrag
fehlgeschlagen ist — so meldet sich der Cron. Beispiel:

```
*/5 * * * *  php /pfad/tools/shop-publish.php --client=1 --quiet
17 * * * *   php /pfad/tools/shop-publish.php --client=1 --quiet --reconcile-payments
```

## Aufträge

Die Anwendung legt Aufträge an; abgearbeitet werden sie vom Läufer oder auf
Knopfdruck in der Shop-Übersicht. Sie stehen in `batchjob_hugoshop`, offen ist,
was kein Ergebnis hat. Die Übersicht zeigt die offenen und die zuletzt
erledigten.

| Auftrag | Angelegt von | Wirkung |
| --- | --- | --- |
| `publish_part` | Artikelkarte „Veröffentlichen" | Eine Produktseite schreiben |
| `publish_all` | Shop-Übersicht | Alle Seiten der Artikel im Shop |
| `remove_part` | Artikel aus dem Shop nehmen | Inhaltsdatei entfernen |
| `remove_all` | Abschalten des HugoShop-Kanals bei `shop_channel_off_pages` = `remove` | Seiten aller im HugoShop angebotenen Artikel entfernen; beim Einschalten folgt `publish_all` |
| `draft_all` | Abschalten des HugoShop-Kanals bei `shop_channel_off_pages` = `draft` (Vorgabe) | Seiten aller im HugoShop angebotenen Artikel als Entwurf (`draft: true`) neu schreiben; Hugo veröffentlicht sie nicht mehr |
| `sync_kit` („Shop-Benutzerschnittstelle aktualisieren“) | Knopf „Shop-Benutzerschnittstelle installieren“, sofort oder als Aufgabe | Paket abgleichen, an HugoCMS übertragen, Webseite in jedem Fall bauen |
| `reconcile_payments` | Läufer mit `--reconcile-payments` | Schwebende PayPal-Zahlungen nachfragen |

Ein Ergebnis beginnt mit `ok:` oder `Fehler:`; Fehler stehen rot in der Liste.

Jeder Auftrag gehört einem Verkaufskanal (`batchjob_hugoshop.channel_id`,
`NULL` = HugoShop) und wird vom Modul dieses Kanals unter
`backend/api/shop/channels/` abgearbeitet (dev/shop-verkaufskanaele.md,
Schritt 4). Ist der HugoShop abgeschaltet und steht kein HugoShop-Auftrag an,
lässt der Lauf Paket, Kategorieübersicht und Bau aus.

`publish_part` und `publish_all` entstehen auch von selbst, wenn sich am
Artikel etwas ändert, das auf seiner Seite steht (Trigger,
dev/shop-verkaufskanaele.md V22) — abschaltbar je HugoShop mit dem
Kanalschalter `auto_publish`. Bekommt die Seite dabei einen neuen Dateinamen
(neue Produktseiten-Kennung oder Artikelnummer), trägt der Auftrag den alten
in `param`, und der Lauf entfernt die Seite unter dem alten Namen.

### Wo Fehler landen

| Art | Wo sie steht |
| --- | --- |
| Fehler in einem Auftrag | in dessen Ergebnis, rot in der Auftragsliste |
| Gescheiterte Artikel in `publish_all` | im Ergebnis des Auftrags: `Fehler: <Zahl> Seiten geschrieben, <Zahl> fehlgeschlagen — <erster Grund>` |
| Fehler am Lauf selbst (Paket, Kategorien, Bau) und seine übrigen Meldungen | Klick auf den Status eines erledigten Auftrags: die vollständige Ausgabe des Laufs, in dem er erledigt wurde, Fehlerzeilen rot |
| Abgebrochener Lauf | als Hinweis unter der Auftragsliste, mit der Ausgabe des Prozesses |
| Fehlende Einrichtung (Shop-Schlüssel, Adresse oder Schlüssel von HugoCMS) | Aufträge bleiben „ausgesetzt“ (gelbes Zeichen), Hinweis über den Kennzahlen mit dem Grund |
| HugoCMS weist Produktseiten ab, Weiterleiter nicht erreichbar | Fehler des Laufs: der Auftrag endet mit „Fehler bei der Webseite: …“ |
| Läufer im Cron | Standardausgabe, Bau-Fehler zusätzlich auf der Fehlerausgabe, Rückgabewert 1 |

Ein `publish_all` gilt nur dann als erledigt, wenn kein Artikel gescheitert
ist — sonst stünde ein grüner Haken an einem Lauf, der nichts geschrieben hat.
Je Auftrag stehen höchstens 20 gescheiterte Artikel im Wortlaut in den
Meldungen, danach folgt eine Zeile mit der Zahl der übrigen; gezählt werden
alle.

Der Läufer meldet dasselbe, nur auf der Kommandozeile. In der Übersicht stehen
die Meldungen in der gespeicherten Ausgabe des Laufs; Fehlerzeilen erkennt der
Dialog am Wortlaut („Fehler", „fehlgeschlagen").

**Ausgabe je Lauf.** Am Ende jedes Laufs, der Aufträge erledigt hat, landen
seine Meldungen in `batchjob_run_hugoshop`; die Aufträge verweisen über
`batchjob_hugoshop.run_id` darauf. Das gilt für Läufe aus dem Cron wie aus dem
Admin-Panel. Gespeichert werden höchstens 20 000 Zeilen, bei mehr Anfang und
Ende. Die Ausgabe bleibt, solange einer ihrer Aufträge besteht: Löschen,
Aufräumen und die Aufbewahrungsfrist nehmen sie mit (Trigger
`trigger_cleanup_batchjob_run_hugoshop`). Ein Lauf, der abbricht, bevor er
endet, hinterlässt keine gespeicherte Ausgabe — dann gilt die Meldung
„abgebrochen" mit der Ausgabe des Prozesses.

### Sofort ausführen, ohne Cron

In der Auftragsliste lässt sich jede Zeile ankreuzen, „Alle auswählen" nimmt
alle, und „Jetzt ausführen" arbeitet die offenen davon ab — in derselben
Reihenfolge wie der Cron: Aufträge, Paket, Kategorieübersicht, Übertragung und
Bau. „Alle
sofort veröffentlichen" legt den Auftrag „Alle Produkte" an und führt ihn im
selben Zug aus. Ein Cron-Eintrag ist damit nicht zwingend nötig.

**Der Lauf arbeitet im Hintergrund.** Beide Knöpfe starten den Läufer
`tools/shop-publish.php` als eigenen Prozess (`shopPublishStartBackground()`)
und kehren sofort zurück. Die Karte fragt danach den Stand ab
(`getShopPublishStatus`) — alle 2 Sekunden, nach einer Minute alle 5, nach 15
Minuten nicht mehr; das Muster stammt aus der Live-Analyse von HugoCMS. Die
Auftragsliste färbt sich dabei Zeile für Zeile um, und „Meldungen des letzten
Laufs" füllt sich, während der Lauf arbeitet. Das übrige ERP bleibt bedienbar,
und kein Proxy bricht eine minutenlange Anfrage ab.

| Datei unter `backend/tmp/` | Inhalt |
| --- | --- |
| `shop-publish-<db>.json` | angefordert, begonnen, beendet, Bilanz |
| `shop-publish-<db>.log` | Meldungen des laufenden oder letzten Laufs |
| `shop-publish-<db>.out` | Fehlerausgabe des zuletzt vom Panel gestarteten Prozesses |

Diese Dateien schreibt jeder Lauf, auch der aus dem Cron — die Karte zeigt
also auch dessen Meldungen, und ein Lauf, der beim Öffnen der Seite gerade
arbeitet, wird gleich verfolgt. Ob ein Lauf arbeitet, entscheidet allein die
Beratungssperre in der Datenbank (abgelesen aus `pg_locks`); die Dateien
erzählen nur, was war. Kommt ein gestarteter Prozess nicht binnen 30 Sekunden
an der Sperre an, oder bricht ein Lauf mittendrin ab, zeigt die Karte das mit
der Fehlerausgabe des Prozesses an.

Beim Start schließt die Befehlszeile alle geerbten Deskriptoren oberhalb von 2
und löst den Prozess mit `setsid` ab. Ohne das erbte er die Sockets des
Webservers und hielte etwa den Port des Entwicklungsservers fest, solange der
Lauf dauert.

Voraussetzungen, sonst bleibt es beim Cron:

- Der Webserver-Benutzer muss in `backend/tmp/` schreiben (Bereitstellung,
  Stand, Meldungen) und HugoCMS erreichen dürfen, und `shell_exec()` darf
  nicht abgeschaltet sein — damit startet „Jetzt ausführen“ den Läufer.
- Ein Kommandozeilen-PHP muss auffindbar sein. Unter PHP-FPM zeigt
  `PHP_BINARY` auf `php-fpm`; gesucht wird deshalb `php<Version>` und `php`
  neben `PHP_BINDIR` und unter `/usr/bin` (`shopPhpCli()`).
- Die PHP-Erweiterung GD mit WebP-Unterstützung, im Kommandozeilen-PHP des
  Laufs wie im PHP des Webservers: Die Medienbibliothek erzeugt daraus die
  Vorschaubilder und wandelt Bilder um. Fehlt sie, meldet der Lauf
  „Vorschaubilder nicht erzeugt — GD …“ und das Hochladen warnt. Prüfen mit
  `php -r 'print_r(gd_info());'` („WebP Support“); Debian/Ubuntu: `php-gd`.
- Läuft der Cron unter einem anderen Benutzer als der Webserver, müssen beide
  die Dateien in `backend/tmp/` schreiben dürfen — am einfachsten läuft der
  Cron als Webserver-Benutzer.

Läuft gerade ein Lauf, startet ein Klick keinen zweiten: Der laufende nimmt die
offenen Aufträge ohnehin mit, und die Karte verfolgt ihn.

Im Entwicklungsserver (`scripts/dev.sh`) arbeitet PHP mit vier Prozessen
(`PHP_CLI_SERVER_WORKERS=4`). Mit nur einem hielte jede lange Anfrage das
ganze ERP an.

### Aufräumen

Damit `batchjob_hugoshop` nicht vollläuft, werden erledigte Aufträge gelöscht —
aber nur die erfolgreichen (Ergebnis beginnt mit `ok`). Fehlgeschlagene und
offene bleiben stehen, ebenso Auftragsarten, die nicht aus dieser Erweiterung
stammen.

| Weg | Wann | Was |
| --- | --- | --- |
| Knopf „Löschen" in der Auftragsliste | auf Zuruf, nach Rückfrage | die ausgewählten Zeilen: erledigte, fehlgeschlagene und noch offene |
| Knopf „Aufräumen" in der Auftragsliste | auf Zuruf, nach Rückfrage | alle erfolgreichen, ohne Frist |
| Läufer `tools/shop-publish.php` | nach jedem Lauf | die erfolgreichen, die älter sind als die Frist |

Auswählen lässt sich jede Zeile. „Jetzt ausführen" nimmt davon die offenen,
„Löschen" die ganze Auswahl — auch einen offenen Auftrag, der danach nicht mehr
vorgemerkt ist. Steht ein fehlgeschlagener Auftrag in der Auswahl, ist „Jetzt
ausführen" gesperrt: wer einen Fehlschlag ankreuzt, will aufräumen, und
ausführen ließe sich ein erledigter Auftrag ohnehin nicht.

Fehlgeschlagene Aufträge verschwinden nur auf diesem Weg: „Aufräumen" und der
Läufer rühren sie nicht an, damit der Grund lesbar bleibt, bis jemand ihn
gelesen hat.

Die Frist steht in der Firmenkonfiguration unter Shop: **Erledigte Aufträge
aufbewahren (Tage)**, gespeichert als `shop_job_retention_days` in
`defaults_oserp`, Vorgabe 30 Tage. `0` schaltet das Aufräumen im Läufer ab, dann
bleibt der Knopf. `--no-cleanup` lässt einen einzelnen Lauf nichts löschen.

## Verkaufskanal abschalten

Abgeschaltet wird in der Kanalkarte (Shop-Menü → Verkaufskanäle). Ein Teil
wirkt sofort beim Speichern, der Rest beim nächsten Lauf. Entscheidungen:
V16 (HugoShop) und V26 (eBay) in `dev/shop-verkaufskanaele.md`.

### HugoShop

**Sofort** (`shopChannelHugoshopSwitched`, `channels/hugoshop.php`):

- Offene Aufträge, die veröffentlichen würden (`publish_all`, `publish_part`)
  oder schon fürs Abschalten angelegt waren (`draft_all`, `remove_all`),
  werden gelöscht — sonst hinterließe aus-an-aus die Seiten veröffentlicht.
- Ein neuer Auftrag nach „Seiten beim Abschalten des HugoShops“
  (`channel_off_pages`, Abschnitt Veröffentlichung der Kanalkarte):

  | Einstellung | Auftrag (Name in der Liste) | Wirkung |
  | --- | --- | --- |
  | Als Entwurf behalten (Vorgabe) | `draft_all` („Alle Produktseiten als Entwurf“) | Seiten mit `draft: true` neu geschrieben; Hugo veröffentlicht sie nicht mehr, die Dateien bleiben auf der Webseite |
  | Entfernen | `remove_all` („Alle Produktseiten entfernen“) | Seiten aus der Bereitstellung gelöscht (`shopRemovePage`); bei der Übertragung fehlen sie in der Lieferung, und HugoCMS löscht sie aus der Webseite (es entfernt, was die vorige Lieferung enthielt und die aktuelle nicht) |

**Im Shop**, sobald der Kanal aus ist (`shopClosedActions`, `shopIsOpen`):
In den Warenkorb legen, Menge ändern, Kasse, Rechnung und Zahlungsbeginn
antworten mit `SHOP_CLOSED` („Der Shop nimmt derzeit keine Bestellungen an“).
Erreichbar bleiben Anmeldung, Kundenkonto, Bestellungen, Rechnungsseite und
PDF, der Rücksprung einer schon begonnenen PayPal-Zahlung, Widerruf, Kontakt
und Suche.

**Beim nächsten Lauf:** Der Auftrag wird abgearbeitet, an HugoCMS übertragen
und gebaut. Hat der Kanal keinen Shop-Schlüssel oder keinen HugoCMS-Zugang,
wird der Auftrag ohne Wirkung erledigt („ok: übersprungen — HugoShop
abgeschaltet und nicht eingerichtet“), statt dauerhaft ausgesetzt zu bleiben —
es gibt dann nichts zu veröffentlichen oder zurückzunehmen, und der Kanal
bleibt löschbar (`shopRunJobs`, `lib/publish.php`). Steht kein Auftrag mehr an,
lässt der Lauf Paket, Kategorieübersicht und Bau dieses Kanals aus.

**Einschalten:** offene `draft_all`/`remove_all` werden gelöscht,
`publish_all` schreibt alle Seiten neu.

### eBay

- **Sofort:** offene `publish_all`/`publish_part` werden gelöscht, `remove_all`
  angelegt — der nächste Lauf beendet damit alle Angebote bei eBay
  (`shopChannelEbaySwitched`, `channels/ebay.php`).
- Der Bestellabruf holt keine neuen Bestellungen mehr; er fragt den Schalter
  selbst ab (`shopEbayActive`).
- **Einschalten:** offene `remove_all`/`remove_part` werden gelöscht,
  `publish_all` stellt die angebotenen Artikel neu ein.

### Was bleibt

- Die Zuordnung der Artikel zum Kanal mit Preis, Titel, Beschreibung und
  Bildern (`parts_channel_shop`) — Grundlage für das Wiedereinschalten.
- Belege: Rechnungen samt Bestell- und Lieferstatus, Widerrufe,
  eBay-Bestellungen; sie behalten ihren Kanal.
- In der Shop-Übersicht erscheint der Kanal nicht mehr bei Kennzahlen und
  Hinweisen.

### Löschen

Ein Kanal lässt sich nur abgeschaltet löschen, und nur, wenn keine Aufträge
mehr offen sind und keine Belege an ihm hängen (Rechnungen, Widerrufe,
eBay-Bestellungen). Ist das nicht so, nennt die Kanalkarte den Grund mit
Anzahl („3 offene Aufträge, 12 Rechnungen“). Offene Aufträge erledigt der
nächste Lauf; mit Belegen bleibt der Kanal abgeschaltet stehen.

## Vorlagensätze

Gesucht wird wie beim Druck: erst `<templates_dir>/shop/<name>/`, dann
`backend/templates-default/shop/<name>/`. Gewählt wird über
`shop_template_set` — nur ein Name, kein Pfad.

Was ein Satz nicht mitbringt, kommt aus `standard`: Vorlagen (`product.md.php`)
und jede Datei des Pakets (`kit/`). Die Regeln der Kategorieübersicht
(`category_groups.php`) sind die Ausnahme: nur ein Satz, der sie selbst
mitbringt (sonic24), gruppiert nach Wortteilen; alle anderen nehmen die Gruppen
aus der Ansicht „Sortiment“ (dev/shop-sortiment.md). Ein eigener Satz besteht deshalb nur aus dem, was er ändert;
`theme.json` gehört immer dazu und nennt unter `data.category_groups` die
Datendatei der Kategorieübersicht.

## Einstellungen

**Für den ganzen Mandanten** in der Firmenkonfiguration (Einstellungen →
Erweiterungen → Shop), gespeichert in `defaults_oserp`. Fehlt eine nötige, nennt
die Fehlermeldung den Feldnamen aus der Oberfläche, nicht den Schlüssel.

| Gruppe | Einstellungen |
| --- | --- |
| Zugang | `shop_cart_lifetime_hours`, `shop_context_lifetime_hours` |
| Rechnungsstellung | `shop_contact_login`, `shop_target_account`, `shop_incoming_account`, `shop_standard_taxzone`, `shop_standard_currency`, `shop_tax_included`, `shop_active_price_source`, `shop_stock_bin_id` |
| Bankverbindung | `shop_payment_account_owner`, `shop_payment_bank`, `shop_payment_iban`, `shop_payment_bic` |
| Bestellungen | `shop_delivery_status_show`, `shop_delivery_status_mail` (dev/shop-bestellstatus.md) |
| Veröffentlichung | `shop_job_retention_days`, `shop_thumbnail_size`, `ebay_public_host` |
| Suche | `shop_search_weighting` |

**Je HugoShop** in seiner Karte (Shop-Menü → Verkaufskanäle), gespeichert in
`sales_channel_shop.settings`, Geheimnisse in `sales_channel_secret_shop`
(`shop_channel_setting_keys()`, dev/shop-mehrere-kanaele.md):

| Gruppe | Einstellungen |
| --- | --- |
| Zugang der Shop-Webseite | `public_key` (Geheimnis), `allowed_origins` |
| Adressen der Webseite | `base_url`, `invoice_page`, `products_link`, `category_link`, `category_taxonomy`, `category_title`, `images_link`, `thumbnails_link`, `downloads_link` |
| Veröffentlichung | `backend_url`, `template_set`, `auto_publish`, `channel_off_pages` |
| HugoCMS | `hugocms_url`, `hugocms_key` (Geheimnis) |
| Mail | `invoice_mail_subject`, `withdrawal_mail_to` |
| PayPal | `paypal_sandbox`, `paypal_live_client_id`, `paypal_live_secret`, `paypal_sandbox_client_id`, `paypal_sandbox_secret`, `paypal_payment_method_preference`, `paypal_mock_response` |

Preisvorgaben, Freigrenze und Lieferländer sind Spalten des Kanals
(`sales_channel_shop`, dev/shop-versand.md); der eBay-Kanal hat eigene Felder.

**Wohin geschrieben wird** (seit 2026-10-09, `dev/shop-hugocms-verzeichnisse.md`)
legt HugoCMS fest: die Freigaben in den Projekteinstellungen unter
„Shop-Erweiterung“, gespeichert in der `[shop]`-Sektion der Mount-Datei
(`content_dir`, `category_groups`, `images`, `thumbnails`, `downloads`). OpensourceERP hat
dafür keine eigene Einstellung mehr — der Kanalschlüssel `content_dir` ist
entfallen, das Schema-Update räumt ihn weg. Der Lauf holt die Freigaben zu
Beginn (`shopHugoCmsGrants`) und legt Seiten und Kategorieübersicht in der
Bereitstellung genau dort ab; die Pfade laufen dabei durch `shopPathUnder()`
(kein führender Schrägstrich, kein `..`, auch über Symlinks nicht aus der
Bereitstellung heraus). „Verbindung prüfen und Freigaben abrufen“ zeigt sie in
der Kanalkarte nur lesend an, dazu einen Vorschlag für `products_link`,
`images_link` und `thumbnails_link`, wenn diese nicht zu den Freigaben passen
(Hugo legt `content/<sprache>/<abschnitt>/` unter `/<abschnitt>/` ab und
liefert `static/` unter `/` aus — bei anderen Sprachen oder eigenen Permalinks
weicht die Adresse ab, deshalb nur ein Vorschlag).

**Adressmuster in den Widgets** (seit 2026-10-09): Warenkorb, Bestellungen im
Kundenkonto, Suche und Werkzeugsuche bekommen die Adressen von Produktseite und
Vorschaubild fertig aus dem Backend (`shopChannelLink`, aus `products_link` und
`thumbnails_link` des HugoShops; Seitenname wie die Datei, `shopPageSlug`).
Fehlt ein Muster, zeigen sie keinen Link bzw. kein Bild. Die Aktion
`getProductLink` und die Attribute `product-url`/`thumbnail-url` sind
entfallen. Die Pfade der übrigen Seiten (Kasse, Anmeldung, Rechnungsseite …)
kommen aus `params.portalui.paths` der Webseite, ersatzweise aus den Vorgaben
der Widgets; ein Attribut am Shortcode geht vor (seit 2026-10-14,
`portal-ui/README.md` „Pfade der Seiten“).

**Gebaut wird in HugoCMS**, mit dem Hugo-Programm aus dessen `hugocms.ini`
(`[hugo] bin`); `--cleanDestinationDir` stellt dort `[hugo] clean` ein.
Entwürfe (`draft: true`) baut HugoCMS nicht mit.

**Signiert übertragen (empfohlen, seit 2026-10-08):** In den
Systemeinstellungen (Shop-Erweiterung: Signaturschlüssel) oder mit
`php tools/shop-signing-key.php --create` ein Schlüsselpaar erzeugen und den
angezeigten öffentlichen Schlüssel in HugoCMS eintragen (Projekteinstellungen →
Shop-Erweiterung). Dann überträgt der Lauf Weiterleiter und 404-Seite selbst,
auch nach einem OSERP-Update (`dev/shop-php-signatur.md`).

**Adresse des Portal-Zugangs von OpensourceERP** (`backend_url` der Webseite
unter System → Webseiten, mit „Verbindung prüfen“ direkt unter dem Feld): Ziel
des Weiterleiters, also der Portal-Zugang `backend/portal/` — nicht die Adresse
von HugoCMS (`…/cms-api/`), die in der HugoCMS-Gruppe steht. Der Test prüft in drei
Schritten: Eingabe (nicht die HugoCMS-Adresse), direkt von OpensourceERP mit dem
Schlüssel der Webseite (öffentliche Aktion `portalPing`, legt keine Sitzung an)
und über `<Basisadresse>/portal-api/`, wie der Browser. Lokal mit `scripts/dev.sh`
ist das `http://localhost:8000/portal/` — der Vite-Server auf 5173 leitet
`/portal` nicht weiter. Der frühere Einstieg `backend/shop/` ist seit 2026-10-22
entfernt.

Ohne Signaturschlüssel **zwei Dateien einmal von Hand** auf den Webserver legen,
weil HugoCMS sonst kein PHP annimmt: aus `backend/templates-default/shop/standard/kit/static/`
die `portal-api/index.php` nach `<webseite>/oserp-portal/static/portal-api/index.php`
und die `not_found.php` nach `<webseite>/oserp-portal/static/not_found.php`. Ihre
Konfiguration (`oserp-portal/config.json`) kommt über die Übertragung. Für die
404-Seite muss der Webserver auf sie zeigen (nginx: `error_page 404 /not_found.php;`, Apache: `ErrorDocument 404 /not_found.php`).

Ob beide liegen und aktuell sind, prüft der Lauf nach jeder Übertragung über
die Webseite (`shopWebsiteManualFilesCheck`, seit 2026-10-08): Beide Dateien
senden ihre Prüfsumme im Kopf `X-Oserp-Portal-File`, der Lauf vergleicht sie
mit dem Vorlagensatz. Aufgerufen werden `<Basisadresse>/portal-api/` und eine
Adresse, die es nicht gibt (für die 404-Seite). Gemeldet wird nur, was nicht
stimmt: fehlt, veraltet (nach einem OSERP-Update neu kopieren), PHP läuft
nicht, Weiterleiter ohne `config.json`. Ohne Basisadresse im Kanal oder bei
nicht erreichbarer Webseite bleibt es beim Hinweis, die Dateien von Hand
abzulegen.

## Wiederkehrende Aufgaben

- **Artikel veröffentlichen:** In der Artikelkarte „Im Shop anbieten“, den
  HugoShop als Kanal wählen und „Veröffentlichen“; die Seite entsteht beim
  nächsten Lauf. Ändert sich danach etwas, das auf der Seite steht (Preis,
  Texte, Bilder, Shop-Angaben, Gewicht, Vorrat …), legt OSERP den Auftrag
  selbst an (Kanalschalter `auto_publish`, V22 in `dev/shop-verkaufskanaele.md`).
- **Alles neu schreiben:** „Alle veröffentlichen“ in der Shop-Übersicht —
  nötig nach einem neuen Steuersatz oder einem anderen Vorlagensatz, die keinen
  Auftrag von selbst auslösen.
- **Artikel aus dem Shop nehmen:** „Im Shop anbieten“ ausschalten oder den
  HugoShop in der Karte abwählen; der Auftrag `remove_part` entfernt die Seite.
  Die Shop-Angaben bleiben für ein späteres Wiedereinschalten stehen. Für die alte Adresse gehört eine Zeile in
  `redirect_pages_hugoshop` — die 404-Seite fragt sie über `resolveRedirect`
  ab.
- **Zahlungen abgleichen:** Knopf in der Bestellansicht oder der Auftrag aus
  dem Cron. Gebucht wird nie automatisch.
- **Widerrufe:** Ansicht „Widerrufe" im Admin-Panel, siehe `shop-widerruf.md`.
- **Lager:** Verkäufe aus HugoShop und eBay buchen die Waren vom „Lagerplatz
  für Verkäufe“ aus (V28). Stornos und Widerrufe buchen nicht zurück (O17) —
  dafür eine Einlagerung in der Lagerverwaltung.
- **eBay:** Angebote und Bestellabruf, siehe `dev/shop-verkaufskanaele.md`;
  der Abruf läuft über `backend/cli/ebay-orders.php`.
- **Widgets ändern:** `npm run build:portal-ui` (oder `build:portal-ui`), die
  Bündel unter `backend/templates-default/portal/standard/kit/assets/portal-ui/`
  mit committen; der Läufer verteilt sie beim nächsten Lauf. Der Build prüft
  die Bündel selbst; nach dem Commit zeigt `npm --prefix portal-ui run check`,
  ob sie unverändert geblieben sind.
- **Shop-UI sofort in die Webseite bringen:** Übersicht der Shop-Erweiterung,
  Karte „Veröffentlichung“, Knopf „Shop-Benutzerschnittstelle installieren“
  (Recht `edit_shop_config`, Aktion `installPortalUi`). Nach einer Rückfrage —
  sofort oder als Aufgabe für den nächsten Lauf — legt er den Auftrag
  „Shop-Benutzerschnittstelle aktualisieren“ (`sync_kit`, `param = install`)
  an; sofort heißt: der Läufer startet nur für diesen Auftrag. Das Paket geht
  an HugoCMS, danach wird die Webseite **in jedem Fall** gebaut, auch wenn das
  Paket schon aktuell war. Die Mounts der Hugo-Konfiguration (siehe „Eine
  Webseite einrichten“) prüft OpensourceERP nicht — fehlen sie, meldet Hugo
  beim Bau fehlende Shortcodes oder das fehlende Widget-Bündel.
- **Widgets zeigen `lit$…$`, Klassennamen oder Quelltext als Text:** Ein
  Bündel ist beschädigt (früher durch `tools/fix-ws.sh`, siehe
  `portal-ui/README.md`, „Warum `build` ein eigenes Skript ist“).
  `npm --prefix portal-ui run check` meldet es; neu bauen und übertragen.

## Wenn etwas klemmt

| Zeichen | Ursache |
| --- | --- |
| Hugo: `template for shortcode "shop-…" not found` | Paket nicht übertragen oder Mount `oserp-portal/layouts` fehlt — „Shop-Benutzerschnittstelle installieren“, Hugo-Konfiguration prüfen |
| Proxy antwortet 503 `PORTAL_PROXY_NOT_CONFIGURED` | `oserp-portal/config.json` fehlt oder ist für den Webserver nicht lesbar |
| 403 `PORTAL_NOT_AUTHORIZED` | Der Schlüssel im Proxy passt nicht zu `shop_public_key` |
| Warenkorb bleibt leer | Der Aufruf kommt nicht von derselben Adresse — Proxy einrichten oder `shop_allowed_origins` setzen |
| Seiten entstehen nicht | Ausgabe des Laufs lesen; „Inhaltsdateien von HugoCMS nicht angenommen“: eine Freigabe hat sich während des Laufs geändert — der nächste Lauf legt die Dateien an den neuen Ort |
| Aufträge „ausgesetzt“ (gelbes Zeichen) | Shop-Schlüssel, Adresse oder Schlüssel von HugoCMS fehlen — Kanalkarte; oder HugoCMS ist nicht erreichbar, die Shop-Erweiterung dort ausgeschaltet oder eine Freigabe unbrauchbar — Ausgabe des Laufs, „Verbindung prüfen und Freigaben abrufen“ |
| Kategorieseite zeigt nur die alphabetische Liste | Der Vorlagensatz erwartet die Kategorieübersicht an einem anderen Ort, als HugoCMS freigibt — Warnung im Lauf; in HugoCMS die Freigabe „Kategorieübersicht“ anpassen |
| Zweite Produktseite unter dem früheren Namen eines Artikels | Seit 16.10.2026 entfernt der Lauf solche Seiten selbst: Nach den Aufträgen löscht er je HugoShop jede Produktseite in der Bereitstellung (`backend/tmp/shop-publish-<datenbank>-<kanal>-staging/`), die zu keinem angebotenen Artikel gehört — Ausgabe „Verwaiste Produktseiten entfernt“; HugoCMS löscht sie danach, weil sie in seiner vorigen Lieferung stand (`shopChannelHugoshopRemoveOrphans`). Vorher blieb sie liegen, wenn beim Umbenennen „Automatisch veröffentlichen“ aus war. Nur von Hand in HugoCMS zu löschen ist eine Seite, die OSERP nie geliefert hat (Altbestand der Bridge) |
| Weiterleiter erreicht HugoCMS statt OpensourceERP | „Adresse des Shop-Zugangs von OpensourceERP“ steht auf `…/cms-api/` — Kanalkarte, „Verbindung prüfen“ |
| Weiterleiter 404, obwohl signiert übertragen | Der Webserver liefert unter der Basisadresse nicht das `public/` dieser Webseite aus, oder die Basisadresse stimmt nicht |
| „Ein Lauf ist noch unterwegs" | Sperrdatei in `backend/tmp/` — ein vorheriger Lauf hängt |
| Neue Einstellungen fehlen nach einem Update | Das Schema-Update beim Login prüft Prüfsummen, siehe `shop-veroeffentlichung.md` |
