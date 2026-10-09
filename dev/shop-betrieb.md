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
| Öffentlicher Zugang | `backend/shop/index.php` | Kunden des Betreibers; Ausweis über den Kopf `X-Shop-Key`, zugelassene Aktionen in `public/actions.php` |
| Fachschicht | `backend/api/shop/lib/` | Warenkorb, Konto, Suche, Rechnung, Zahlung, Mail, Widerruf, Umleitungen, Veröffentlichung, Kategorien |
| Webseiten-Paket | `backend/templates-default/shop/standard/kit/` | Shortcodes, Partials, Proxy, 404-Seite, Widget-Bundle |
| Shop-UI | `shop-ui/` | Widgets der Käuferoberfläche (Lit), eigener Build ins Paket |
| Läufer | `tools/shop-publish.php` | Aufträge abarbeiten, Paket abgleichen, Kategorien, Webseite bauen |
| Übernahme | `tools/shop-bridge-settings.php` | Einstellungen einer Bridge-Instanz als SQL ausgeben |

Die Webseite selbst — Hugo-Projekt, Theme, Inhalte — gehört dem Betreiber.
OpensourceERP schreibt dort nur in das Verzeichnis der Produktseiten und nach
`oserp-shop/`.

## Eine Webseite einrichten

Der Ablauf steht ausführlich in `shop-bridge-abloesung.md` unter Stufe D. Kurz:

1. Erweiterung `shop` beim Mandanten aktivieren, Schema-Update laufen lassen.
2. Einstellungen in der Firmenkonfiguration unter Shop: Shop-Schlüssel,
   `shop_backend_url`, Wurzelverzeichnis der Webseiten, Verzeichnis dieser
   Webseite, Inhaltsordner, Vorlagensatz. Die Shop-Übersicht zeigt, was noch
   fehlt.

   Den **Shop-Schlüssel** erzeugt der Knopf neben dem Feld: 32 zufällige Byte,
   hexadezimal. Er wird dabei angezeigt, weil ein Reverse-Proxy im Webserver
   denselben Wert braucht; der mitgelieferte Proxy bekommt ihn vom Läufer über
   `oserp-shop/config.json`. Der Schlüssel unterscheidet die Mandanten — zwei
   Firmen dürfen nicht denselben haben.
3. Programm zum Bauen: Verzeichnis, in dem `hugo` liegt, in der
   Shop-Einstellung `shop_publish_command_path` — nur das Verzeichnis, der
   Dateiname steht fest. Dazu das Kontrollkästchen für
   `--cleanDestinationDir`. Ohne Programm werden nur Dateien geschrieben.
4. `php tools/shop-publish.php --client=<id>` einmal von Hand — legt
   `<webseite>/oserp-shop/` samt `config.php` an.
5. In der `config.json` der Instanz die Mounts auf `oserp-shop/` setzen.
6. Cron-Einträge für Läufer und Zahlungsabgleich.

## Der Läufer

```
php tools/shop-publish.php [--client=<id>] [--db=<name>] [--limit=500]
                           [--no-build] [--quiet] [--reconcile-payments]
php tools/shop-publish.php --list-clients
```

Ein Lauf tut der Reihe nach:

1. **Aufträge** aus `batchjob_hugoshop` abarbeiten, älteste zuerst.
2. **Paket abgleichen** — bei jedem Lauf; er vergleicht Prüfsummen und kopiert
   nur Geändertes nach `<webseite>/oserp-shop/`.
3. **Kategorieübersicht** erneuern, wenn Seiten geschrieben oder entfernt
   wurden.
4. **Bauen**, wenn sich etwas geändert hat und ein gültiges Programm
   eingestellt ist. Die Befehlszeile setzt die Erweiterung selbst zusammen,
   siehe unten.

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
| `sync_kit` | von Hand | Paket abgleichen |
| `reconcile_payments` | Läufer mit `--reconcile-payments` | Schwebende PayPal-Zahlungen nachfragen |

Ein Ergebnis beginnt mit `ok:` oder `Fehler:`; Fehler stehen rot in der Liste.

Jeder Auftrag gehört einem Verkaufskanal (`batchjob_hugoshop.channel_id`,
`NULL` = HugoShop) und wird vom Modul dieses Kanals unter
`backend/api/shop/channels/` abgearbeitet (dev/shop-verkaufskanaele.md,
Schritt 4). Ist der HugoShop abgeschaltet und steht kein HugoShop-Auftrag an,
lässt der Lauf Paket, Kategorieübersicht und Bau aus.

`publish_part` und `publish_all` entstehen auch von selbst, wenn sich Preis
oder Texte ändern (Trigger, dev/shop-verkaufskanaele.md V22) — abschaltbar
mit `shop_auto_publish`.

### Wo Fehler landen

| Art | Wo sie steht |
| --- | --- |
| Fehler in einem Auftrag | in dessen Ergebnis, rot in der Auftragsliste |
| Gescheiterte Artikel in `publish_all` | im Ergebnis des Auftrags: `Fehler: <Zahl> Seiten geschrieben, <Zahl> fehlgeschlagen — <erster Grund>` |
| Fehler am Lauf selbst (Paket, Kategorien, Bau) und seine übrigen Meldungen | Klick auf den Status eines erledigten Auftrags: die vollständige Ausgabe des Laufs, in dem er erledigt wurde, Fehlerzeilen rot |
| Abgebrochener Lauf | als Hinweis unter der Auftragsliste, mit der Ausgabe des Prozesses |
| Fehlendes Wurzelverzeichnis, ungültiger Hugo-Pfad | als Hinweis über den Kennzahlen, mit dem Grund im Klartext |
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
Reihenfolge wie der Cron: Aufträge, Paket, Kategorieübersicht, Bau. „Alle
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

- Der Webserver-Benutzer muss im Verzeichnis der Webseite schreiben und den
  Bau-Befehl ausführen dürfen, und `shell_exec()` darf nicht abgeschaltet
  sein.
- Ein Kommandozeilen-PHP muss auffindbar sein. Unter PHP-FPM zeigt
  `PHP_BINARY` auf `php-fpm`; gesucht wird deshalb `php<Version>` und `php`
  neben `PHP_BINDIR` und unter `/usr/bin` (`shopPhpCli()`).
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

Was ein Satz nicht mitbringt, kommt aus `standard`: Vorlagen (`product.md.php`),
die Regeln der Kategorieübersicht (`category_groups.php`) und jede Datei des
Pakets (`kit/`). Ein eigener Satz besteht deshalb nur aus dem, was er ändert;
`theme.json` gehört immer dazu und nennt unter `data.category_groups` die
Datendatei der Kategorieübersicht.

## Einstellungen

40 Zeilen in `defaults_oserp`, zu ändern unter Einstellungen → Erweiterungen →
Shop. Fehlt eine nötige, nennt die Fehlermeldung den Feldnamen aus der
Oberfläche, nicht den Schlüssel.

| Gruppe | Einstellungen |
| --- | --- |
| Zugang | `shop_public_key`, `shop_allowed_origins`, `shop_backend_url` |
| Sitzung | `shop_cart_lifetime_hours`, `shop_context_lifetime_hours` |
| Verkauf | `shop_contact_login`, `shop_target_account`, `shop_incoming_account`, `shop_standard_taxzone`, `shop_standard_currency`, `shop_tax_included`, `shop_active_price_source`, `shop_free_shipping_from` (`shop_shipping_partnumber` entfiel 2026-10-02, siehe dev/shop-versand.md) |
| Zahlung | Bankverbindung (`shop_payment_*`), PayPal (`shop_paypal_*`) |
| Adressen der Webseite | `shop_base_url`, `shop_products_link`, `shop_category_link`, `shop_images_link`, `shop_thumbnails_link`, `shop_downloads_link` |
| Veröffentlichung | `shop_template_set`, `shop_sites_dir`, `shop_site_dir`, `shop_content_dir`, `shop_publish_command_path`, `shop_publish_clean_destination`, `shop_images_dir`, `shop_thumbnails_dir`, `shop_thumbnail_size` |
| HugoCMS (`dev/shop-hugocms-trennung.md`) | `shop_publish_mode` (`local`/`hugocms`), `shop_hugocms_url`, `shop_hugocms_key` (Geheimnis) |
| Sonstiges | `shop_search_weighting`, `shop_invoice_mail_subject`, `shop_withdrawal_mail_to` |

**Betriebsart.** `local` (Vorgabe): Die Webseite liegt auf diesem Server,
OSERP schreibt hinein und baut selbst — alles in diesem Abschnitt gilt so.
`hugocms`: Die Webseite liegt bei HugoCMS auf einem eigenen Webserver. OSERP
schreibt dann in die Bereitstellung `backend/tmp/shop-publish-<db>-staging/`,
überträgt an HugoCMS und lässt dort bauen; Verzeichnis- und Programmfelder
gelten nicht. Die Vorschaubilder erzeugt HugoCMS aus den Produktbildern auf
dem Webserver, nach jeder Übertragung; Größe weiter über
`shop_thumbnail_size`, die Verzeichnisse stehen in der Mount-Datei von HugoCMS
(`[shop] images`, `[shop] thumbnails`). Fehlende Produktbilder nennt der Lauf.
Einzelheiten in `dev/shop-hugocms-trennung.md`.

**Signiert übertragen (empfohlen, seit 2026-10-08):** In den
Systemeinstellungen (Shop-Erweiterung: Signaturschlüssel) oder mit
`php tools/shop-signing-key.php --create` ein Schlüsselpaar erzeugen und den
angezeigten öffentlichen Schlüssel in HugoCMS eintragen (Projekteinstellungen →
Shop-Anbindung). Dann überträgt der Lauf Weiterleiter und 404-Seite selbst,
auch nach einem OSERP-Update (`dev/shop-php-signatur.md`).

**Adresse von OpensourceERP für die Webseite** (`backend_url`, seit
2026-10-08 mit „Verbindung prüfen“ direkt unter dem Feld): Ziel des
Weiterleiters, also der Shop-Zugang `backend/shop/` — nicht die Adresse von
HugoCMS (`…/cms-api/`), die in der HugoCMS-Gruppe steht. Der Test prüft in drei
Schritten: Eingabe (nicht die HugoCMS-Adresse), direkt von OpensourceERP mit dem
Shop-Schlüssel (öffentliche Aktion `shopPing`, legt keine Sitzung an) und über
`<Basisadresse>/shop-api/`, wie der Browser. Lokal mit `scripts/dev.sh` ist das
`http://localhost:8000/shop/` — der Vite-Server auf 5173 leitet `/shop` nicht
weiter.

Ohne Signaturschlüssel **zwei Dateien einmal von Hand** auf den Webserver legen,
weil HugoCMS sonst kein PHP annimmt: aus `backend/templates-default/shop/standard/kit/static/`
die `shop-api/index.php` nach `<webseite>/oserp-shop/static/shop-api/index.php`
und die `not_found.php` nach `<webseite>/oserp-shop/static/not_found.php`. Ihre
Konfiguration (`oserp-shop/config.json`) kommt über die Übertragung. Für die
404-Seite muss der Webserver auf sie zeigen (nginx: `error_page 404 /not_found.php;`).

Ob beide liegen und aktuell sind, prüft der Lauf nach jeder Übertragung über
die Webseite (`shopWebsiteManualFilesCheck`, seit 2026-10-08): Beide Dateien
senden ihre Prüfsumme im Kopf `X-Oserp-Shop-File`, der Lauf vergleicht sie
mit dem Vorlagensatz. Aufgerufen werden `<Basisadresse>/shop-api/` und eine
Adresse, die es nicht gibt (für die 404-Seite). Gemeldet wird nur, was nicht
stimmt: fehlt, veraltet (nach einem OSERP-Update neu kopieren), PHP läuft
nicht, Weiterleiter ohne `config.json`. Ohne Basisadresse im Kanal oder bei
nicht erreichbarer Webseite bleibt es beim Hinweis, die Dateien von Hand
abzulegen.

Wurzelverzeichnis und Programm stehen hier, weil jede Firma ihre eigene
Webseite hat. Beide sind **absolut**:

| Einstellung | Art | Bezug |
| --- | --- | --- |
| `shop_sites_dir` | absolut | Wurzelverzeichnis der Webseiten |
| `shop_publish_command_path` | absolut | Verzeichnis, in dem `hugo` liegt |
| `shop_site_dir` | relativ | zum Wurzelverzeichnis |
| `shop_content_dir`, `shop_images_dir`, `shop_thumbnails_dir` | relativ | zum Verzeichnis der Webseite |

Die relativen Pfade laufen durch `shopPathUnder()`: ein führender Schrägstrich
wird abgewiesen (früher fiel er weg, und der Wert wurde stillschweigend als
Unterverzeichnis gelesen), `..` ebenso, und das aufgelöste Verzeichnis muss
unterhalb des übergeordneten liegen — auch über Symlinks hinweg. Liegt die
Webseite anderswo, gehört das in `shop_sites_dir`, nicht in ein relatives
Feld. Das Formular weist einen führenden Schrägstrich schon beim Eintippen
zurück.

**Wie gebaut wird.** Eingestellt wird nur das Verzeichnis des Programms, nie
eine Befehlszeile; der Dateiname `hugo` steht fest im Quelltext. Die setzt die Erweiterung vor jedem Bau selbst zusammen — für den
Läufer wie für „Jetzt ausführen“, beide über `shopPublishCommand()`:

```
cd '<Verzeichnis der Webseite>' && '<Programm>' [--cleanDestinationDir] 2>&1
```

Der Pfad wird vorher geprüft (absolutes, vorhandenes Verzeichnis ohne
Leerraum, darin eine vorhandene und ausführbare Datei `hugo`) und geht maskiert hinein; Argumente lassen sich so nicht
unterschieben. Ein ungültiger Pfad zählt als Fehler des Laufs und erscheint in
der Shop-Übersicht als Hinweis.

**In der `settings.ini`** stehen zwei Ergänzungen:

| Eintrag unter `[system]` | Wirkung |
| --- | --- |
| `shop_publish_command_path` | Verzeichnis, in dem `hugo` liegt. Rückfall: gilt, wenn die Shop-Einstellung leer ist, und erscheint dort als Vorgabe im leeren Feld. Ein ungültiger Wert in der Shop-Einstellung ist ein Fehler, kein Rückfall. |
| `shop_sites_dir` | Grenze: das eingestellte Wurzelverzeichnis muss darunter liegen, sonst `SHOP_SITES_DIR_OUTSIDE_LIMIT`. |

## Wiederkehrende Aufgaben

- **Artikel veröffentlichen:** In der Artikelkarte „Im Shop anbieten“, den
  HugoShop als Kanal wählen und „Veröffentlichen“; die Seite entsteht beim
  nächsten Lauf. Ändern sich danach Verkaufspreis, Buchungsgruppe, Aufschlag
  oder Texte, legt OSERP den Auftrag selbst an (`shop_auto_publish`, V22 in
  `dev/shop-verkaufskanaele.md`).
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
- **Shop-UI ändern:** `npm run build:shop-ui`, das Bundle mit committen; der
  Läufer verteilt es beim nächsten Lauf. Der Build prüft das Bündel selbst;
  nach dem Commit zeigt `npm --prefix shop-ui run check`, ob es unverändert
  geblieben ist.
- **Shop-UI sofort in die Webseite bringen:** Übersicht der Shop-Erweiterung,
  Karte „Veröffentlichung“, Knopf „Shop-Benutzerschnittstelle installieren“
  (Recht `edit_shop_config`, Aktion `installShopUi`). Er legt einen Auftrag
  „Paket abgleichen“ mit `param = install` an und startet den Läufer nur für
  diesen Auftrag: Paket nach `oserp-shop/` (bzw. an HugoCMS), danach wird die
  Webseite **in jedem Fall** gebaut, auch wenn das Paket schon aktuell war.
  Fehlen in der Site-Konfiguration die Mounts `oserp-shop/static`,
  `oserp-shop/layouts`, `oserp-shop/assets/shop-ui` oder `params.shopui`,
  steht das als Hinweis in den Meldungen des Laufs; geändert wird die
  Konfiguration der Webseite nicht.
- **Widgets zeigen `lit$…$`, Klassennamen oder Quelltext als Text:** Das
  Bündel ist beschädigt (früher durch `tools/fix-ws.sh`, siehe
  `shop-ui/README.md`, „Warum `build` ein eigenes Skript ist“).
  `npm --prefix shop-ui run check` meldet es; neu bauen und übertragen.

## Wenn etwas klemmt

| Zeichen | Ursache |
| --- | --- |
| Hugo: `template for shortcode "shop-…" not found` | `oserp-shop/` fehlt — Läufer laufen lassen |
| Proxy antwortet 503 `SHOP_PROXY_NOT_CONFIGURED` | `oserp-shop/config.json` fehlt oder ist für den Webserver nicht lesbar |
| 403 `SHOP_NOT_AUTHORIZED` | Der Schlüssel im Proxy passt nicht zu `shop_public_key` |
| Warenkorb bleibt leer | Der Aufruf kommt nicht von derselben Adresse — Proxy einrichten oder `shop_allowed_origins` setzen |
| Seiten entstehen nicht | `shop_sites_dir` und `shop_site_dir` prüfen, Schreibrechte, Ausgabe des Läufers lesen |
| `SHOP_SITES_DIR_OUTSIDE_LIMIT` | Die `settings.ini` grenzt das Wurzelverzeichnis ein, die Einstellung liegt außerhalb |
| „Ein Lauf ist noch unterwegs" | Sperrdatei in `backend/tmp/` — ein vorheriger Lauf hängt |
| Neue Einstellungen fehlen nach einem Update | Das Schema-Update beim Login prüft Prüfsummen, siehe `shop-veroeffentlichung.md` |
