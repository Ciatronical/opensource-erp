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
| `sync_kit` | von Hand | Paket abgleichen |
| `reconcile_payments` | Läufer mit `--reconcile-payments` | Schwebende PayPal-Zahlungen nachfragen |

Ein Ergebnis beginnt mit `ok:` oder `Fehler:`; Fehler stehen rot in der Liste.

### Wo Fehler landen

| Art | Wo sie steht |
| --- | --- |
| Fehler in einem Auftrag | in dessen Ergebnis, rot in der Auftragsliste |
| Gescheiterte Artikel in `publish_all` | im Ergebnis des Auftrags: `Fehler: <Zahl> Seiten geschrieben, <Zahl> fehlgeschlagen — <erster Grund>` |
| Fehler am Lauf selbst (Paket, Kategorien, Bau) | unter der Auftragsliste als „Meldungen des letzten Laufs", Fehlerzeilen rot |
| Fehlendes Wurzelverzeichnis, ungültiger Hugo-Pfad | als Hinweis über den Kennzahlen, mit dem Grund im Klartext |
| Läufer im Cron | Standardausgabe, Bau-Fehler zusätzlich auf der Fehlerausgabe, Rückgabewert 1 |

Ein `publish_all` gilt nur dann als erledigt, wenn kein Artikel gescheitert
ist — sonst stünde ein grüner Haken an einem Lauf, der nichts geschrieben hat.
Je Auftrag stehen höchstens 20 gescheiterte Artikel im Wortlaut in den
Meldungen, danach folgt eine Zeile mit der Zahl der übrigen; gezählt werden
alle.

Der Läufer meldet dasselbe, nur auf der Kommandozeile. In der Übersicht kommen
die Meldungen aus der Antwort von `runShopPublishJobs`; die Fehlerzeilen liefert
das Backend getrennt mit, statt sie am Wortlaut zu erraten.

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
| Verkauf | `shop_contact_login`, `shop_target_account`, `shop_incoming_account`, `shop_standard_taxzone`, `shop_standard_currency`, `shop_tax_included`, `shop_active_price_source`, `shop_shipping_partnumber`, `shop_free_shipping_from` |
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

In dieser Betriebsart **zwei Dateien einmal von Hand** auf den Webserver legen,
weil HugoCMS kein PHP annimmt: aus `backend/templates-default/shop/standard/kit/static/`
die `shop-api/index.php` nach `<webseite>/oserp-shop/static/shop-api/index.php`
und die `not_found.php` nach `<webseite>/oserp-shop/static/not_found.php`. Ihre
Konfiguration (`oserp-shop/config.json`) kommt über die Übertragung. Nach einem
OSERP-Update, das eine der beiden ändert, gehören sie erneut kopiert; der Lauf
erinnert daran, sobald er etwas überträgt.

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

- **Artikel veröffentlichen:** In der Artikelkarte „Im Shop anbieten" und
  „Veröffentlichen"; die Seite entsteht beim nächsten Lauf.
- **Alles neu schreiben:** „Alle veröffentlichen" in der Shop-Übersicht.
- **Artikel aus dem Shop nehmen:** Die Shop-Angaben löschen; der Auftrag
  `remove_part` entfernt die Seite. Für die alte Adresse gehört eine Zeile in
  `redirect_pages_hugoshop` — die 404-Seite fragt sie über `resolveRedirect`
  ab.
- **Zahlungen abgleichen:** Knopf in der Bestellansicht oder der Auftrag aus
  dem Cron. Gebucht wird nie automatisch.
- **Widerrufe:** Ansicht „Widerrufe" im Admin-Panel, siehe `shop-widerruf.md`.
- **Shop-UI ändern:** `npm run build:shop-ui`, das Bundle mit committen; der
  Läufer verteilt es beim nächsten Lauf.

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
