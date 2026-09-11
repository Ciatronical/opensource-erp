# Shop: Ablösung der Bridge

Stand 2026-09-11. Die Bridge (`dev.hugoshop.dev/kivitendo_bridge`) wird nicht
mehr gepflegt, auch die Shop-UI nicht. Die Erweiterung Shop übernimmt alle
Aufgaben, die die Bridge bisher erledigt hat.

Das Backend ist bereits übernommen (`dev/shop-migration.md`, Stufen 1–7), die
Erzeugung der Produktseiten entsteht gerade (`dev/shop-veroeffentlichung.md`).
Dieses Dokument erfasst, was darüber hinaus in der Bridge steckt.

## Bestandsaufnahme

| Teil der Bridge | Aufgabe | Stand in OSERP | Ziel |
| --- | --- | --- | --- |
| `framework/` | Backend, 55 Funktionen | übernommen (Stufen 1–7) | — |
| `web/shop-api/index.php` | API-Einstieg für die Webseite | ersetzt durch `backend/shop/index.php` | — |
| `web/oserp/` | Proxy, Einstellungsübernahme — in Stufe 7 entstanden | Proxy im Paket (Stufe A), Übernahme `tools/shop-bridge-settings.php` (Stufe D) | — |
| `web/not_found.php` | 404-Seite mit Umleitungen aus `redirect_pages_hugoshop` (301, 302, 404, 410) | `resolveRedirect` und `not_found.php` im Paket (Stufe A) | — |
| `web/search/index.php` | serverseitige Suchseite, eigene `ILIKE`-Suche, festes Bootstrap-Markup | nicht übernommen | entfällt: sonic24 verlinkt sie nicht mehr, gesucht wird mit `shop-search-results` (Stufe D) |
| `batchjob/run.php` | Warteschlange | ersetzt durch `tools/shop-publish.php` | — |
| `batchjob/inc.php` | `createThumbnail`, `slugify`, `getTaxoneAndTaxrate` | übernommen (`shopThumbnail`, Linkvorschlag, Steuer in `shopPageData`) | — |
| `batchjob/sitemap.php` | Sitemaps: Produkte, PDFs, Markdown-Seiten, feste Seiten, Kategoriegruppen; gzip, in Blöcken | entfällt — konnte nie laufen, Hugo erzeugt die Sitemaps | `lastmod` im Front Matter (Stufe C) |
| `payment/reconcile.php` | Abgleich schwebender PayPal-Zahlungen | Aktion `reconcileShopPayments` im Admin-Panel, Auftrag `reconcile_payments` (Stufe C) | — |
| `hugo/layouts/` | 16 Shortcodes, 4 Partials: Einbindung der Widgets in Hugo | im Paket (Stufe A) | — |
| `shop-ui/` | Käuferoberfläche, 17 Web Components (Lit), Build mit esbuild | in OpensourceERP, Bundle im Paket (Stufe B) | — |
| `sql/install.sql`, `sql/migrations/` | Schema | übernommen (`backend/upstall/shop/`) | — |
| `sql/dev.sql`, `zufallspreise.sql`, `database_source.sql`, `db_query.sql`, `search.sql` | Entwicklungsdaten und Abfragen | — | entfällt |
| `test/payment-pending.php` | Prüfung schwebender Zahlungen | — | als Prüfwerkzeug nach `tools/` (optional) |
| `test/test.pl`, `test/data.json` | Test des Perl-Drucks | — | entfällt, der Druck läuft in OSERP ohne Perl |
| `dev/widerruf.md` | Rechtsgrundlage und Stand des Widerrufs | Funktion übernommen (`lib/withdrawal.php`) | Doku nach `dev/` |
| `config.php.template`, `passwd.php.template` | Konfiguration je Instanz | ersetzt durch die Shop-Einstellungen | — |

### Batchjob-Läufer

Der Läufer der Bridge war **nie in Betrieb**: `batchjob/config.php` fehlt, die
Datei mit den Job-Funktionen (`BATCHJOB_FUNCTIONS_SCRIPT`) gibt es nicht, die
Tabelle ist leer, und im Code legt niemand Aufträge an (so auch die README der
Bridge). Es gibt nichts zu übernehmen außer dem, was `tools/shop-publish.php`
schon kann — und dem Sitemap-Generator, der daneben liegt.

Der Konflikt zweier Läufer auf einer Tabelle (`dev/shop-veroeffentlichung.md`)
bestand damit nur auf dem Papier.

### Auf Seiten der Instanz

Nicht Teil der Bridge, aber von ihr abhängig — `sonic24.de/publish/`:

| Skript | Aufgabe | Stand in OSERP |
| --- | --- | --- |
| `run.php` | Lieferantenimport aus `parts_sonic24_source` nach `parts`/`parts_ext`, Produktseiten, Bau | Produktseiten und Bau: Veröffentlichung Stufen 1–3; **Import: offen** |
| `category_groups.php` | Kategoriegruppen für die Navigation | übernommen (Stufe C): Algorithmus in `lib/categories.php`, Regeln im Vorlagensatz |
| `chatgpt.*.php` | Kategorien und Beschreibungen per KI | entfällt (Entscheidung 1) |

Der `hugocms`-Editor, auf den `run.php` einen Verweis anlegt, liegt in keinem
der beiden Repositories.

## Site-Kit

Eine Hugo-Instanz bindet heute drei Verzeichnisse der Bridge per Mount ein:
`web/` (Einstiegspunkte im Docroot), `hugo/layouts/` (Shortcodes und Partials)
und `shop-ui/dist/` (Widget-Bundle). Nach der Ablösung liefert OSERP diese
Teile — der Vorlagensatz wird zum vollständigen Paket für eine Webseite:

```
backend/templates-default/shop/standard/
├── theme.json
├── product.md.php          Produktseite (vorhanden)
├── layouts/                Shortcodes und Partials (aus bridge/hugo/layouts)
└── static/                 Einstiegspunkte im Docroot: Proxy, 404-Seite

shop-ui/                    Quelltext der Widgets (Lit), Build mit esbuild → kit/assets/shop-ui/
```

**Die Shop-UI bekommt keinen Platz im Vite-Build von OSERP.** Sie läuft auf
fremden Webseiten, nicht in der OSERP-Oberfläche, und bleibt ein eigener,
kleiner Build. Das Frontend von OSERP muss dafür nicht gebaut werden.

**Wie das Kit in die Webseite kommt** — zwei Wege:

| Weg | Bewertung |
| --- | --- |
| Hugo-Mounts auf das OSERP-Verzeichnis, wie heute auf die Bridge | Webseite und OSERP müssen auf demselben Rechner liegen; die Webseite kennt einen Pfad in OSERP |
| **Der Läufer kopiert das Kit beim Veröffentlichen** in das Verzeichnis der Webseite | Vorschlag: die Webseite braucht keinen Pfad zu OSERP, Kit und Backend haben immer denselben Stand, und der Läufer schreibt ohnehin dorthin |

## Stufen

| Stufe | Inhalt |
| --- | --- |
| A | Site-Kit: `hugo/layouts` und die Einstiegspunkte (Proxy, 404-Seite mit Umleitungen) in den Vorlagensatz; der Läufer kopiert das Kit |
| B | Shop-UI: Quelltext nach OSERP, Build, Auslieferung über das Kit; den Rückfall auf die Bridge in `api.js` entfernen |
| C | Läufer: Auftrag `reconcile_payments`, Kategorieübersicht; die Sitemap bleibt bei Hugo |
| D | Umstieg von sonic24: Mounts durch das Kit ersetzen, Proxy, Einstellungen übernehmen, eigener Vorlagensatz |
| E | Aufräumen: Hinweis in der Bridge-README, `dev/widerruf.md` übernehmen, Doku |
| F | Lieferantenimport, neu gestaltet |

## Entscheidungen (2026-09-11)

1. **KI-Skripte** (`chatgpt.*.php`) sind obsolet und werden nicht übernommen.
2. **Lieferantenimport:** wird neu gestaltet und als letztes umgesetzt — nicht
   in der Form von `publish/run.php`, das vor jedem Lauf alle Artikel auf
   veraltet setzt.
3. **Auslieferung des Kits:** Kopie durch den Läufer.

## Nebenbefunde

- `not_found.php` beantwortet 301 und 302, indem sie das Ziel per `fopen()`
  selbst abruft und ausgibt, statt einen `Location`-Kopf zu senden. Eine
  Umleitung findet nicht statt; Suchmaschinen sehen den Inhalt unter der alten
  Adresse. Beim Übernehmen richtig machen.

## Stufe A — was angelegt wurde

### Das Paket

```
backend/templates-default/shop/standard/kit/
├── layouts/partials/          4 Partials   (aus bridge/hugo/layouts)
├── layouts/shortcodes/       17 Shortcodes (aus bridge/hugo/layouts)
└── static/
    ├── shop-api/index.php     Proxy zu OpensourceERP
    └── not_found.php          404-Seite mit Umleitungen
```

Das Widget-Bundle (`assets/shop-ui/`) ist mit Stufe B dazugekommen.

Die Layouts sind unverändert übernommen; nur die Warnung in
`shop-ui-assets.html` nennt jetzt den neuen Mount statt des Bridge-Pfads.

### Der Läufer überträgt es

`shopSyncKit()` in `backend/api/shop/lib/publish.php` spiegelt `kit/` nach
`<webseite>/oserp-shop/`: kopiert nur Geändertes, entfernt, was das Paket nicht
mehr enthält — beides nur innerhalb von `oserp-shop/`. Verknüpfungen werden als
solche gelöscht, nie ihr Ziel. Der Läufer gleicht das Paket vor jedem Bau ab;
zusätzlich gibt es den Auftrag `sync_kit`. Gebaut wird, sobald Seiten oder das
Paket sich geändert haben.

**Nicht direkt nach `layouts/`:** Die Webseite hängt die Unterordner von
`oserp-shop/` *nach* ihren eigenen ein. So behalten Übersteuerungen der Instanz
Vorrang — direkt in `layouts/` kopiert, überschriebe das Paket sie.

Der Name `oserp-shop` ist fest: Proxy und 404-Seite finden ihre Konfiguration
darüber.

### Konfiguration statt `bridge-config`

Der Läufer schreibt `<webseite>/oserp-shop/config.php` mit der Adresse von
OpensourceERP (neue Einstellung `shop_backend_url`) und dem Shop-Schlüssel. Die
Datei liegt außerhalb des Docroots und wird von Hugo nicht eingehängt; sie wird
nur neu geschrieben, wenn sich ihr Inhalt ändert. Webserver und Läufer müssen
sie lesen können.

### 404-Seite und Umleitungen

`not_found.php` fragt OpensourceERP über die neue öffentliche Aktion
`resolveRedirect` (`lib/redirect.php`) nach einer Umleitung für Host und Pfad.
Gegenüber der Bridge:

- **301/302/307/308 leiten wirklich um** (`Location`), statt das Ziel selbst
  abzurufen und unter der alten Adresse auszugeben
- **gebunden statt eingesetzt:** die Bridge baute die angefragte Adresse
  ungeprüft in den SQL-Text; jetzt ein Parameter, LIKE-Platzhalter in der
  Adresse werden wörtlich genommen
- **ohne Schema verglichen,** auf beiden Seiten — hinter einem Proxy weiß die
  Webseite oft nicht, ob sie per https angesprochen wurde
- Die Aktion braucht keine Besuchersitzung; `shopPublicContextUuid()` setzt nur
  ein Cookie, angelegt wird eine Sitzung erst von `getContext`

Die Platzhalter der `404.html` (`[!code]`, `[!display]`, `[!link_text]`,
`[!hyperlink]`) bleiben; Texte werden jetzt maskiert eingesetzt.

### Einbindung in die Webseite (für Stufe D)

In der `config.json` der Instanz ersetzen diese Mounts die der Bridge:

```json
"module": { "mounts": [
  { "source": "static",                    "target": "static" },
  { "source": "oserp-shop/static",         "target": "static" },
  { "source": "layouts",                   "target": "layouts" },
  { "source": "oserp-shop/layouts",        "target": "layouts" },
  { "source": "oserp-shop/assets/shop-ui", "target": "assets/shop-ui" }
]}
```

Die eigenen Verzeichnisse stehen jeweils vorn: bei gleichem Ziel gewinnt der
zuerst genannte Mount.

### Geprüft

**Paketabgleich** mit Wegwerf-Verzeichnissen:

- erster Lauf 23 Dateien und Konfiguration, zweiter Lauf nichts
- von Hand veränderte Datei wird wiederhergestellt
- überzählige Datei und leere Verzeichnisse verschwinden; eine eingeschleuste
  Verknüpfung wird entfernt, ihr Ziel bleibt unberührt
- geänderte Adresse schreibt nur die Konfiguration neu; `config.php` übersteht
  jeden Lauf und liest sich samt Anführungszeichen und Backslash im Schlüssel
  zurück

**Umleitungssuche** mit Ersatz-Datenbank: Schema und Schrägstrich am Ende
entfallen, `100%_rabatt` wird zu `100\%\_rabatt%`, leere Eingaben fragen nicht.

**Über HTTP** mit zwei PHP-Servern, einer als Ersatz für OpensourceERP:

| Anfrage | Ergebnis |
| --- | --- |
| `/alt` | 301, `Location: https://site.test/neu` |
| `/weg` | 410, Hinweis auf die Ersatzseite, `<` im Linktext maskiert |
| `/gibtsnicht` | 404, Hinweis ausgeblendet |
| Proxy, POST mit Cookie und Abfrage | Status 201 und `Set-Cookie` durchgereicht; beim Backend kommen Schlüssel, Cookie, Abfrage und Rumpf an |
| Proxy ohne `config.php` | 503 `SHOP_PROXY_NOT_CONFIGURED` |
| `/alt` ohne `config.php` | 404 |

Dazu `php -l` auf allen Dateien, `check:api` (189 Dateien), die neue
Einstellung in allen 21 Sprachen.

Beim ersten Testlauf schien der Abgleich fehlerhaft — der Fehler lag im Test:
`$cfg = require …` auf oberster Ebene überschrieb `$GLOBALS['cfg']`, aus dem die
Ersatzfunktionen die Einstellungen lasen.

Nicht geprüft: gegen eine echte Datenbank, mit Hugo und hinter nginx.

## Stufe B — was angelegt wurde

### Quelltext und Build

```
shop-ui/                          Quelltext (Lit), eigener Build mit esbuild
├── src/  demo/  demo-src/        aus der Bridge übernommen
├── package.json                  build → Site-Kit, build:dev und watch → dist/
└── .gitignore                    node_modules/, dist/

backend/templates-default/shop/standard/kit/assets/shop-ui/
└── shop-widgets.js               gebautes Bundle, versioniert
```

`npm run build` in `shop-ui/` (oder `npm run build:shop-ui` im
Wurzelverzeichnis) schreibt das minifizierte Bundle direkt ins Kit. Es wird
versioniert, weil der Läufer es auch auf Server ohne Node überträgt. Der
Vite-Build von OpensourceERP bleibt davon unberührt. `build:dev` und `watch`
schreiben mit Sourcemap nach `shop-ui/dist/` — nur für die Testseite
`/shop-ui-demo/`.

Die README der Shop-UI nennt jetzt die Mounts auf `oserp-shop/`, den Build ins
Kit und für Tailwind das Bundle als Scan-Pfad
(`./oserp-shop/assets/shop-ui/*.js`): Die Klassenketten stehen im minifizierten
Bundle unverändert, die Webseite braucht also keinen Pfad zum Quelltext.

### Rückfall auf die Bridge entfernt

| Datei | Änderung |
| --- | --- |
| `src/core/api.js` | `send()` liefert `payload`; `mitRueckfall` entfällt; Anmelden und Abmelden rufen `shopLogin` und `shopLogout` direkt |
| `src/core/dates.js` | nur noch ISO-Datumsangaben; ein reines Datum wird als Ortszeit gelesen |
| `src/core/cart-data.js`, `shop-account-orders.js` | nur noch `thumbnail`, nicht mehr die Bridge-Schreibweise `tumbnail` |

### Eigene Vorlagensätze

Seit Stufe C entsteht das Paket in Schichten (`shopKitFiles()`): Grundlage ist
das Kit des mitgelieferten Satzes `standard`, das Kit des gewählten Satzes legt
sich dateiweise darüber. Ein eigener Satz bekommt das Bundle damit ohne eigene
Kopie.

### Geprüft

- Bundle 155 443 Bytes (Bridge: 155 721), als ES-Modul syntaktisch fehlerfrei,
  16 Aufrufe von `customElements.define`; keine Reste von `mitRueckfall` oder
  `tumbnail`; die Aktionen heißen `shopLogin` und `shopLogout`
- Tailwind-Klassenketten des Presets `tailwind` stehen unverändert im Bundle
- Paketabgleich: erster Lauf jetzt 24 Dateien statt 23, die übrigen Fälle wie
  in Stufe A
- Git erfasst das Bundle im Kit; `node_modules/` und `dist/` sind ausgenommen

Nicht geprüft: im Browser und mit Hugo — das folgt mit dem Umstieg von sonic24
in Stufe D.

## Stufe C — was angelegt wurde

### Paket in Schichten

`shopKitFiles($satz)` setzt das Paket aus dem Kit von
`backend/templates-default/shop/standard/kit/` und darüber dem `kit/` des
gewählten Satzes zusammen — auch einer Kundenkopie von `standard`. Ein eigener
Satz bringt nur mit, was er ändert. Eine Datei der Grundlage entfernen kann er
nicht, nur ersetzen.

### Sitemap: bleibt bei Hugo

Abweichend vom Plan gibt es keinen Auftrag `sitemap`:

- Der Generator der Bridge konnte nie laufen. Er liest seine Einstellungen aus
  `batchjob/config.php`, die es nicht gibt, und `bridge-config` kennt keine
  `SITEMAP_*`-Konstante.
- sonic24 erzeugt die Sitemaps mit Hugo: `sitemap.xml` mit allen Seiten
  einschließlich der Kategorieseiten, `sitemap-pdf.xml` aus `downloads/` und
  `sitemap_index.xml` darüber (Theme `hugoshop`, Ausgabeformate in der
  `config.json`). `robots.txt` verweist auf `sitemap.xml`.
- Ein zweiter Generator schriebe neben Hugo her; die Bridge löschte dafür
  sogar Hugos `sitemap.xml`.

Gefehlt hat das Änderungsdatum. Die Produktseite trägt jetzt `lastmod` aus
`parts.mtime`, ersatzweise `parts.itime`, und Hugo übernimmt es in die Sitemap.
Die Grenze von 50.000 Adressen je Datei ist bei 3.711 Seiten fern; `gzip` und
Blöcke entfallen.

### Zahlungsabgleich als Auftrag

Der Auftrag `reconcile_payments` ruft `paymentsReconcile()` — dieselbe Funktion
wie der Knopf im Admin-Panel. Gebucht wird nichts.

- Ergebnis in der Auftragsliste etwa „ok: 2 geprüft, 1 bezahlt, 1 offen". Zu
  bezahlten Rechnungen meldet der Läufer „Zahlungseingang von Hand buchen".
- Eine gescheiterte oder nicht abfragbare Zahlung macht daraus „Fehler: … —
  Rechnung 1003 gescheitert": rot in der Auftragsliste, Rückgabewert 1 (der
  Cron meldet sich), gescheiterte Zahlungen zusätzlich im Protokoll.
- Regelmäßig über einen eigenen Cron-Eintrag mit `--reconcile-payments`. Der
  Läufer nimmt den Auftrag vor der Sperre an: läuft gerade ein anderer Lauf,
  erledigt ihn dieser oder der nächste.

```
*/5 * * * *  php tools/shop-publish.php --client=1 --quiet
17 * * * *   php tools/shop-publish.php --client=1 --quiet --reconcile-payments
```

### Kategorieübersicht

Die Seite je Kategorie erzeugt Hugo aus `kategorien:` im Front Matter; das
schrieb `product.md.php` schon. Neu ist die Übersicht nach Obergruppen, die das
Theme aus `data/category_groups.json` liest — ohne die Datei zeigt es eine
alphabetische Liste.

| Teil | Ort |
| --- | --- |
| Algorithmus, aus `sonic24.de/publish/category_groups.php` | `backend/api/shop/lib/categories.php` |
| Regeln: Wortteile, Topseller, Stoppwörter, Schwellen | `category_groups.php` im Vorlagensatz, in Schichten wie das Kit |
| Zieldatei | `theme.json` des Satzes, Eintrag `data.category_groups` |

- Gezählt wird mit einer Abfrage über `parts_ext`, dieselbe Auswahl wie die
  Seiten.
- Der Läufer erneuert die Datei, wenn Seiten geschrieben oder entfernt wurden,
  und schreibt nur bei geändertem Inhalt. Ohne Kategorien entfernt er sie.
  Scheitert die Übersicht, wird trotzdem gebaut.
- Die Wortliste von sonic24 ist Inhalt des Shops und gehört in dessen
  Vorlagensatz (Stufe D). `standard` bringt nur die Stoppwörter mit; ohne
  Wortteile gruppiert die Häufigkeit der Wörter.
- Die Adressen bildet `shopHugoPath()` nach Hugos Regel. Die hängt an der
  Fassung: 0.125 macht aus jedem Leerzeichen einen Bindestrich
  (`Stecknuss- und Biteinsatz` → `stecknuss--und-biteinsatz`), 0.121 fasste
  zusammen. sonic24 baut mit 0.125.1 — beim Wechsel der Fassung prüfen.

### Nebenbei behoben

`remove_part` löste keinen Bau aus: Der Läufer baute nur nach geschriebenen
Seiten, und die gelöschte Inhaltsdatei blieb bis zum nächsten Bau im Netz.
Entfernte Seiten zählen jetzt mit.

### Geprüft

- Paket in Schichten: Ein eigener Satz ersetzt eine Datei und fügt eine hinzu
  (25 statt 24 Dateien, Bundle aus `standard`); ein Satz ohne `kit/` stellt
  `standard` wieder her; eine Kundenkopie von `standard` wirkt; `../eigen` wird
  abgewiesen
- Kategorien mit den Regeln aus dem Skript von sonic24 und dessen 92
  Kategorien: Topseller, 14 Gruppen, Anker, Mitglieder und Reihenfolge gleich
  der vorhandenen `category_groups.json`
- Adressen: 14 Grenzfälle gleich einem echten Bau mit Hugo 0.125.1; alle 92
  Adressen liegen im gebauten `public/kategorien/` von sonic24
- Schreiben: Der zweite Lauf ändert nichts; ohne Kategorien verschwindet die
  Datei; ein Satz ohne Eintrag in `theme.json` schreibt nichts
- Aufträge mit Ersatz-Datenbank: entfernte Seite gezählt; Abgleich mit
  bezahlt, offen, gescheitert und nicht abfragbar ergibt „Fehler: …", nur
  unauffällige ergeben „ok: …"; `lastmod` nur, wo `mtime` gesetzt ist
- `check:api` (190 Dateien); der Läufer lädt alle Dateien und scheitert erst an
  der Datenbank, die in der Sandbox nicht erreichbar ist

Nicht geprüft: gegen eine echte Datenbank, gegen PayPal und der Bau mit Hugo
samt erzeugter Übersicht.

## Stufe D — was angelegt wurde

### sonic24 bindet das Paket ein

In `sonic24.de/config.json` ersetzen die Mounts auf `oserp-shop/` die der
Bridge (Aufstellung in Stufe A). Die Datei ist in sonic24 nicht versioniert —
sie ist Einstellung der Instanz; der alte Stand liegt in
`tmp/config.json.vor-oserp`. Die Testseite `/shop-ui-demo/` kommt aus dem
Arbeitsstand von OpensourceERP (`../../opensource-erp/shop-ui/`, nur in der
Entwicklung). `/oserp-shop/` steht in der `.gitignore` von sonic24: der Läufer
legt es an, und `config.php` enthält den Shop-Schlüssel.

Nicht mehr eingehängt werden `web/search/` — sonic24 verlinkt die Seite nicht
mehr, gesucht wird mit `shop-search-results` — und `web/oserp/`: der Proxy
steckt im Paket, die Übernahme der Einstellungen in `tools/`.

Die Theme-Shortcodes `checkout`, `invoice` und `personal-order` rufen
`/shop-api/` noch mit Formularen und Links auf. Der Inhalt von sonic24
verwendet sie nicht mehr; der Einstieg nähme GET und POST ohnehin an, und die
Aktionen sind freigegeben.

### Das Paket muss vor dem ersten Bau da sein

Ohne `oserp-shop/` bricht Hugo ab: `template for shortcode "shop-cart" not
found`. Der Läufer gleicht das Paket deshalb jetzt bei jedem Lauf ab, nicht nur
nach neuen Seiten, und baut danach. Nebenbei kommt so ein neues Bundle nach
einem Update von OpensourceERP an, ohne dass jemand eine Seite veröffentlicht;
stimmt alles, vergleicht der Abgleich nur Prüfsummen. Ein Bau von Hand
(`publish.sh`, `hugo.sh`) mit den neuen Mounts vor dem ersten Läuferlauf
scheitert.

### Vorlagensatz sonic24

`backend/templates/shop/sonic24/` im Repository der Kundensätze: `theme.json`
und `category_groups.php` mit den 68 Wortteilen und 4 Topsellern aus
`publish/category_groups.php`, samt Kommentaren. Stoppwörter, Schwellen,
Produktseite und Paket kommen aus `standard` — `shopRenderPage()` greift jetzt
wie Paket und Regeln auf `standard` zurück, wenn der Satz eine Vorlage nicht
mitbringt.

### Einstellungen übernehmen

`tools/shop-bridge-settings.php`, hervorgegangen aus
`web/oserp/einstellungen-uebernehmen.php`, gibt die Einstellungen einer Instanz
als SQL aus. Neu dabei: `shop_site_dir` (relativ zu `shop_sites_dir` aus der
settings.ini) und `shop_content_dir` (aus `KIVI_CONTENT_PATH`). Die
`config.php` der Instanz definiert `DB_HOST` und weitere Konstanten, die auch
OpensourceERP verwendet; das Skript lädt deshalb nicht die Konfiguration von
OpensourceERP, sondern liest aus der settings.ini nur `shop_sites_dir`.

### Umstellen auf dem Server

1. Erweiterung `shop` beim Mandanten aktiv, Schema-Update gelaufen.
2. `php tools/shop-bridge-settings.php <webseite>/bridge-config` ausgeben,
   lesen, einspielen. Die Ausgabe enthält die PayPal-Zugangsdaten.
3. Im Admin-Panel setzen: Shop-Schlüssel, Adresse von OpensourceERP
   (`shop_backend_url`, die Adresse von `backend/shop/`), Vorlagensatz
   `sonic24`. Die Shop-Übersicht zeigt, was noch fehlt.
4. settings.ini: `shop_sites_dir` (Verzeichnis über den Webseiten) und
   `shop_publish_command` (der Hugo-Aufruf, wie in `publish/run.php`).
5. `php tools/shop-publish.php --client=<id>` einmal von Hand: legt
   `oserp-shop/` samt `config.php` an. Gebaut wird dabei noch mit den alten
   Mounts.
6. `config.json` der Instanz auf die neuen Mounts umstellen und bauen.
7. Cron-Einträge für den Läufer und den Zahlungsabgleich (Stufe C).
8. Prüfen: Warenkorb, Anmeldung, Bestellung mit der PayPal-Sandbox,
   Rechnungsdownload, 404-Seite und eine Umleitung.

Am Webserver ändert sich nichts: `shop-api/index.php` und `not_found.php`
liegen an denselben Adressen wie bisher. Wer die Serverkonfiguration ändern
kann, ersetzt den PHP-Proxy durch einen Reverse-Proxy und spart einen
PHP-Prozess je Anfrage:

```nginx
location /shop-api/ {
    proxy_pass         https://erp.example/shop/;
    proxy_set_header   X-Shop-Key        "<Shop-Schlüssel>";
    proxy_set_header   Host              $host;
    proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header   X-Forwarded-Proto $scheme;
    # Der Rückweg von PayPal ist eine Weiterleitung an den Besucher —
    # sie gehört in seinen Browser, nicht in den Proxy.
    proxy_redirect     off;
}
```

### Bis Stufe F an der Bridge

`publish/run.php` und `publish/category_groups.php` von sonic24 laden
`kivitendo_bridge/framework/inc.php` — für den Lieferantenimport, die
Produktseiten und die Kategorieübersicht. Die Bridge bleibt deshalb auf dem
Server liegen, bis der Import neu gestaltet ist. Bis dahin schreiben `run.php`
und der Läufer beide nach `content/de/produkt/` und
`data/category_groups.json`, der Läufer nur, wenn in OpensourceERP
veröffentlicht wird. Gegeneinander gesperrt sind die beiden Bauten nicht.

### Geprüft

- Probebau mit Hugo 0.125.1 in einer Kopie von sonic24 ohne Bilder: mit den
  alten Mounts 3.988 Dateien, mit den neuen und dem Paket 3.984 — es fehlen
  `search/index.php` und die drei Dateien unter `oserp/`. Von 3.983
  gemeinsamen Dateien unterscheiden sich 3.834 nur in Fingerabdruck und
  `integrity` des Bundles (der Warenkorb steht im Seitenkopf); zwei sind
  gewollt neu: `shop-api/index.php` und `not_found.php` aus dem Paket
- ohne `oserp-shop/` bricht der Bau ab, siehe oben
- Testseite `/shop-ui-demo/` mit dem Bundle aus `shop-ui/dist`
- Satz sonic24: Produktseite aus `standard`, Paket gleich `standard` (24
  Dateien), Kategorieübersicht mit seinen Regeln gleich der vorhandenen von
  sonic24
- Übernahme mit einer erfundenen `bridge-config`: Sandbox- und Live-Paar
  getrennt, Anführungszeichen maskiert, `shop_site_dir` und `shop_content_dir`
  abgeleitet, ohne `shop_sites_dir` ein Hinweis statt eines Werts

Nicht geprüft: auf dem Server, über den Proxy gegen OpensourceERP und mit
PayPal.
