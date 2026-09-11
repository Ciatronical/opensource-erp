# shop-ui

Buyer-UI des Shops als Web Components mit **Shadow-DOM** (Lit). Ersetzt
schrittweise `themes/hugoshop/static/js/shopwindow*` und die zugehörigen
Shortcodes, damit die Kaufstrecke nicht mehr am Theme hängt.

## Bauen

Die Shop-UI hat einen eigenen Build mit esbuild, getrennt vom Vite-Build von
OpensourceERP: Sie läuft auf den Webseiten der Shops, nicht in der
OSERP-Oberfläche.

```bash
cd shop-ui
npm install
npm run build      # -> ../backend/templates-default/shop/standard/kit/assets/shop-ui/shop-widgets.js
npm run build:dev  # -> dist/shop-widgets.js (+ .map), für die Testseite
npm run watch      # wie build:dev, baut bei jeder Änderung neu
npm run size       # Bundle-Analyse
```

Aus dem Wurzelverzeichnis von OpensourceERP genügt `npm run build:shop-ui`.

`npm run build` schreibt das Bundle in das Site-Kit des Vorlagensatzes
`standard`, und dort gehört es ins Repo: Der Läufer (`tools/shop-publish.php`)
spiegelt das Kit bei jedem Lauf nach `<webseite>/oserp-shop/`, auch auf
Servern ohne Node. Hugo versieht das Bundle beim Site-Build mit einem
Fingerprint. Nach jeder Quelländerung also **bauen und mit committen**.
`dist/` dient nur der Entwicklung und wird nicht versioniert.

Eigene Vorlagensätze unter `<templates_dir>/shop/<name>/` bekommen das Bundle
ohne eigene Kopie: Der Läufer legt ihr `kit/` dateiweise über das Kit von
`standard`.

## Einbindung

Unter `module.mounts` in der `config.json` der Shop-Instanz (z.B.
`sonic24.de/config.json`):

```json
"module": { "mounts": [
  { "source": "static",                    "target": "static" },
  { "source": "oserp-shop/static",         "target": "static" },
  { "source": "layouts",                   "target": "layouts" },
  { "source": "oserp-shop/layouts",        "target": "layouts" },
  { "source": "oserp-shop/assets/shop-ui", "target": "assets/shop-ui" }
]}
```

`oserp-shop/` legt der Läufer an; die Webseite braucht keinen Pfad zu
OpensourceERP. Die Reihenfolge ist nicht beliebig: bei gleichem Ziel gewinnt
der zuerst genannte Mount. Die eigenen `layouts` der Instanz stehen deshalb
**vor** denen des Kits. Zurzeit kollidiert nichts, aber sobald eine Instanz
ein Shortcode oder Partial des Kits übersteuern will, entscheidet genau diese
Reihenfolge darüber, ob die eigene Datei überhaupt zum Zug kommt.
`oserp-shop/static` bringt die Web-Einstiegspunkte mit, ohne die es kein
`/shop-api/` gibt. Die Mounts nur für die Entwicklung stehen unten unter
„Testen".

Der Fingerprint ist nicht optional: nginx liefert `.js` mit
`Cache-Control: public, max-age=31536000, immutable` aus.

Im Inhalt dann z.B.:

```
{{< shop-login register-url="/registrieren/" autofocus="true" >}}
{{< shop-register >}}
{{< shop-cart billing-page="/rechnung/" canceled-page="/bezahlung-abgebrochen/" >}}
{{< shop-add-to-cart product="1386" button-text="In den Warenkorb" >}}
```

Die rund 3700 erzeugten Produktseiten rufen `{{< shop-add-to-cart >}}` direkt
auf — `publish/template.php:111` erzeugt es seit dem 28.08.2026 so, und
`publish/run.php` ist seither durchgelaufen.

Vorher stand dort `{{< in-cart >}}`, ein Shortcode des Themes. Inhalt
umzuschreiben war sinnlos, solange `run.php` ihn neu erzeugt — also
uebersteuerte die Instanz das Shortcode
(`sonic24.de/layouts/shortcodes/in-cart.html`) und liess es denselben Partial
aufrufen wie `shop-add-to-cart`. Diese Bruecke ist am 03.09.2026 entfernt
worden, nachdem `grep -rl '{{< in-cart' content/` nichts mehr fand.

Der Partial `hugo/layouts/partials/shop-add-to-cart.html` bleibt getrennt vom
Shortcode: eine Instanz, die aus historischen Gruenden ein eigenes Shortcode
darauf legen muss, kann das jederzeit wieder tun.

## Aufbau

```
src/
├── index.js                  Einstiegspunkt, importiert alle Komponenten
├── core/
│   ├── base.js               ShopElement — Basisklasse aller Widgets
│   ├── theme.js              Preset auflösen, Stylesheet laden und adoptieren
│   ├── presets.js            Rolle → Klassenname je CSS-Framework
│   ├── defaults.js           frameworkfreies Grundaussehen der shop-*-Klassen
│   ├── api.js                /shop-api/, ApiError, Sitzungskontext
│   ├── bus.js                shop:auth-changed / shop:cart-changed / shop:error
│   ├── money.js              Betraege des Backends lesen und formatieren
│   ├── cart-data.js          Warenkorb-Antworten vereinheitlichen
│   ├── account-data.js       Konto- und Adress-Antworten vereinheitlichen
│   ├── account-base.js       ShopAccountElement — Laden/Fehler/nicht angemeldet
│   ├── checkout-data.js      billingAndShipping lesen, adresses-Nutzlast bauen
│   ├── links.js              fremde Herkunft auf den eigenen Ursprung kuerzen
│   ├── gtm.js                dataLayer-Ereignisse, Einwilligung wie bisher
│   └── i18n.js               Texte, Sprache aus <html lang>
├── components/
│   ├── shop-login.js
│   ├── shop-register.js      mode="account" | "guest"
│   ├── shop-cart.js
│   ├── shop-add-to-cart.js
│   ├── shop-account-nav.js         Seitenleiste der Konto-Seiten
│   ├── shop-account-overview.js
│   ├── shop-account-profile.js
│   ├── shop-account-payment.js
│   ├── shop-account-orders.js
│   ├── shop-account-addresses.js
│   ├── shop-checkout.js            Kasse
│   ├── shop-invoice.js             Seite nach der Bestellung
│   ├── shop-account-buttons.js     Knoepfe im Seitenkopf
│   ├── shop-search.js              Suchfeld im Seitenkopf
│   ├── shop-search-results.js      Trefferliste auf /suchergebnisse/
│   └── shop-contact.js             Kontaktformular
└── legacy/
    └── header-cart.js        Uebergangsloesung: Zaehler im Theme-Header
```

## Der Seitenkopf

`<shop-account-buttons>` und `<shop-search>` ersetzen den Inhalt von
`partials/header/account-buttons.html`, `partials/header/search.html` und den
gesamten aktiven Teil von `js/shopwindow.js`.

Die drei Partials liegen **im Theme**, nicht in einer Projekt-Übersteuerung.
Jedes führt beide Fassungen und schaltet über dieselbe Bedingung:

```go-html-template
{{ if site.Params.shopui }} … Web-Komponente … {{ else }} … bisheriges Markup … {{ end }}
```

| Datei (im Theme) | mit `params.shopui` | ohne |
| --- | --- | --- |
| `partials/header.html` | `shop-ui-assets.html` (Bundle) | `<script src="/js/shopwindow.js">` |
| `partials/header/account-buttons.html` | `<shop-account-buttons …>` | die alte `<ul class="navbar-nav">` |
| `partials/header/search.html` | `<shop-search …>` | das alte `#search`-Markup |

Das Theme wird von mehreren Shops benutzt, liegt aber als Kopie in jeder
Instanz. Der Schalter ist deshalb kein Schmuck: er hält die Fassung mit
Widgets und die ohne in **einer** Datei, sodass das Theme ausgerollt werden
kann, ohne dass ein Shop ohne `params.shopui` etwas davon merkt. Eine
Instanz, die die shop-ui einbindet, muss dafür keine Partials kopieren — der
Eintrag `params.shopui` in ihrer `config.json` genügt.

Eine echte Projekt-Übersteuerung gibt es dagegen bei der alten Skriptdatei:

```
sonic24.de/static/js/shopwindow.js    nur noch showMessage/getProperty
```

Projekt-`static` sticht Theme-`static`; das Theme behält seine vollständige
Fassung für Shops ohne Widgets. Löschen der Datei stellt das alte Verhalten
wieder her.

Der Kopf steht auf jeder Seite — deshalb bindet **er** das Bundle ein, nicht
nur die Widget-Shortcodes. Auf Seiten mit Widgets stehen dadurch zwei
`<script type="module">`-Tags mit derselben Adresse; ein Modul wird pro URL
genau einmal geladen und ausgeführt, das ist also folgenlos.

### Damit endet die doppelte Kontextanfrage

Bisher fragten zwei Stellen denselben Sitzungskontext ab: `shopwindow.js` im
`window`-load-Handler für die Knöpfe und das Bundle für die Widgets. Beide
schrieben zudem in `#cart-count`. `<shop-account-buttons>` liest jetzt den
bereits gehaltenen Kontext aus `core/api.js` und folgt danach nur noch
`shop:auth-changed` und `shop:cart-changed`.

### Suche und Kontakt

`<shop-search-results>` ersetzt `moreSearchResults()`, `fullSearch()` und
`initSearchSlot()`, `<shop-contact>` das Shortcode `contact-form.html` samt
`contact.js`. Beide Seiten sind damit einzeilig:

```
{{< shop-search-results >}}
{{< shop-contact >}}
```

Zwei Fehler sind dabei nicht mitgewandert. `sendContactMail` antwortet mit dem
**String** `'true'` bzw. `'false'` (`sendContactMail()` in `shop.mail.php`,
Zeilen 96 und 104) — das alte
`if(data.success)` war deshalb auch bei `'false'` wahr und leitete nach einer
fehlgeschlagenen Mail auf die Dankesseite. Und die E-Mail-Prüfung lief über
`email.validity.typeMismatch`, während das Feld `type="text"` war; sie konnte
nie zutreffen.

### Warum die Suchvorschläge gekürzt werden

`fastSearch`, `moreSearchResults` und `fullSearch` liefern `hyperlink` als
vollständige Adresse mit Domain (`https://sonic24.de/produkt/…`,
`shop.search.php:66`). Auf einer anderen Instanz führte ein Vorschlag damit
aus dem Shop heraus. `sameOriginHref()` in `core/links.js` lässt gleiche
Herkunft unverändert und kürzt fremde auf Pfad, Query und Fragment — benutzt
von `<shop-search>` und `<shop-search-results>`.

## Der Zaehler im Header

`legacy/header-cart.js` ist **der einzige Ort im Bundle, der fremdes Markup
anfasst**. Es hoert auf `shop:cart-changed` und schreibt in `#cart-count`,
`#cart-button` und `#empty-cart-button` — die Ids aus dem alten Zweig von
`partials/header/account-buttons.html`.

Die Richtung ist Absicht: der Header gehoert dem Theme, das auch Shops ohne
shop-ui verwenden. Das Bundle spricht dessen Sprache, statt Markup zu
verlangen. Fehlt eines der Elemente, passiert nichts — kein Fehler, kein
Abbruch. Frueher tat das jedes Widget selbst, und ein fehlendes Element liess
`inCart()` mit einem TypeError abbrechen.

Im Theme dieser Instanz ist der Kopf inzwischen selbst gewandert (siehe „Der
Seitenkopf"), `#cart-count` gibt es dort nicht mehr — deshalb laeuft
`header-cart.js` hier ins Leere und darf es auch. Gebraucht wird es von einem
Shop, der die Widget-Shortcodes einbindet, den Kopf aber beim alten Markup
laesst. Dort greift auch noch die Vorrangregel: `shopwindow.js` fragt im
`window`-load-Handler denselben Sitzungskontext ab, und das Modul behaelt nach
`load` das letzte Wort, damit die spaetere Antwort keinen bereits
aktualisierten Zaehler zurueckdreht.

## Konto-Seiten

Fünf Panels und eine Seitenleiste ersetzen `account.js` (1203 Zeilen) und die
Shortcodes `personal-data-nav`, `personal-overview`, `personal-profile`,
`personal-payment`, `personal-order`, `personal-addresses`.

Auf einer Seite steht üblicherweise beides:

```
{{< shop-account page="overview" >}}
```

Das ist der bequeme Weg: Navigation, Panel und das Zweispalten-Raster in
einem Zug. Die Einzel-Shortcodes (`shop-account-nav`, `shop-account-profile`,
…) gibt es weiterhin, etwa für die Testseite.

`page` kennt genau fünf Werte — **Einzahl**, ein Tippfehler bricht den Build
mit `errorf` ab:

| `page` | Komponente |
| --- | --- |
| `overview` | `<shop-account-overview>` |
| `profile` | `<shop-account-profile>` |
| `address` | `<shop-account-addresses>` |
| `order` | `<shop-account-orders>` |
| `payment` | `<shop-account-payment>` |

**Warum ein Wrapper-Shortcode?** `personal-data-nav.html` öffnete
`<div class="row">`, schloss darin die eigene Spalte und ließ **den
`row`-Container offen** (Saldo +1). Jeder Inhalts-Shortcode brachte
spiegelbildlich ein `</div>` zu viel mit (Saldo −1) und schloss ihn. Die
beiden waren damit nur zusammen und nur in dieser Reihenfolge benutzbar.
Jetzt steht das Raster im Light DOM um zwei vollständige Elemente herum —
und damit auf der richtigen Seite der Shadow-Grenze.

### Nicht angemeldet ist kein Fehler

Alle fünf Konto-Abfragen in `account.js` — `personalOverview`,
`personalProfil`, `personalPayment`, `personalOrders`, `accountAddresses` —
endeten so:

```js
}, function() {
    // Fixup wenn der Benutzer nicht eingeloggt ist oder ein schwerer Fehler
    // aufgetreten ist
    location.href = '/login/';
})
```

Jeder Fehler landete also wortlos auf der Anmeldeseite — abgelaufene Sitzung,
Datenbank weg, SQL-Fehler, alles gleich.

Der Grund dafür ist echt: aus der Antwort des Backends ist „nicht angemeldet"
nicht ablesbar. Ein anonymer Besucher bekommt

| Aktion | Antwort ohne Anmeldung |
| --- | --- |
| `personalOverview`, `personalProfil`, `personalPayment` | `ACCOUNT_NOT_FOUND` |
| `accountAddresses` | `CUSTOMER_NOT_FOUND` (eigene Prüfung) bzw. `ACCOUNT_NOT_FOUND` |
| `personalOrders` | `CUSTOMER_NOT_FOUND` |

— dieselben Codes, die auch ein gelöschter oder kaputter Kundendatensatz
auslöst. Zwei verschiedene Ursachen, ein Code.

`ShopAccountElement` fragt deshalb **zuerst `getContext()`** — das meldet
`account` verlässlich und liegt ohnehin im Cache. Ist niemand angemeldet,
wird die Konto-Aktion gar nicht erst abgeschickt.

Bis August 2026 war der Fall schlimmer: die vier Funktionen hängten die
`customer_id` roh in den SQL-Text, aus `NULL` wurde `WHERE id = ` und daraus
ein PostgreSQL-Syntaxfehler samt `SHOP_DATABASE_ERROR` — für jeden anonymen
Besucher eine Zeile im Server-Log. Inzwischen binden alle vier über
`bindValue(..., PDO::PARAM_INT)`; das ist behoben, unabhängig von der
shop-ui.

Vier Zustände: `loading`, `ready`, `anonymous` (Hinweis + Link zur
Anmeldung), `error` (Meldung + „Erneut versuchen"). Schlägt eine Aktion fehl,
wird der Kontext einmal aufgefrischt: war die Sitzung zwischenzeitlich
abgelaufen, wird daraus `anonymous`; ist der Kontext gar nicht erreichbar,
bleibt es beim ursprünglichen Fehler — bei einem Netzproblem ist „bitte
anmelden" die falsche Auskunft.

### Die Begrüßung

`nav-box-greeting` wurde früher von **jeder** dieser fünf Abfragen selbst
beschrieben (`account.js`, fünf identische Zeilen). Jetzt meldet das Panel Name und Anrede über
`shop:account-loaded`, und `shop-account-nav` hört zu — eine Anfrage weniger
und kein Zugriff auf fremdes Markup.

### Zahlungsarten in der Navigation

Die alte Seitenleiste hatte vier Einträge; `/zahlungsarten/` war nur über
„Bearbeiten" auf der Übersicht erreichbar. Das ist so geblieben. Der Eintrag
lässt sich einschalten:

```
{{< shop-account page="payment" show-payment="true" >}}
```

## Kasse

`/kasse/` und `/rechnung/` sind je eine Zeile:

```
{{< shop-checkout billing-page="/rechnung/" canceled-page="/bezahlung-abgebrochen/" >}}
{{< shop-invoice >}}
```

`<shop-checkout>` bringt sein zweispaltiges Raster selbst mit — beide Spalten
entstehen im ShadowRoot, die Shadow-Grenze trennt also keinen Grid-Container
von seinen Kindern. Der alte Shortcode brachte dafür 359 Zeilen Bootstrap-
Markup mit, davon 182 Zeilen Registrierungsformular, das es in
`register-form.html` bereits gab.

### Zwei Zweige, ein Knopf

Angemeldet zeigt die linke Spalte die Rechnungsadresse und die Wahl der
Lieferadresse (Standard, eine gespeicherte, oder eine neue). Als Gast steht
dort `<shop-register mode="guest">` — dieselbe Komponente wie unter
`/registrieren/`, nur ohne Passwortfelder und ohne eigenen Absende-Knopf.
`<shop-checkout>` ruft deren `validate()`, `values()` und `register()` auf und
hängt `invoicing` daran.

Das Kundenkonto während der Bestellung ist geblieben: der Schalter
„Kundenkonto anlegen" stellt `<shop-register>` auf `mode="account"` um. Damit
gilt dessen eigener Weg — anlegen, anmelden, zurück auf `/kasse/` — und
„Jetzt kaufen" verschwindet so lange. Früher standen beide Knöpfe
gleichzeitig da, und `invoicing()` blendete die Passwortfelder per
`style.display` aus.

### Was das Backend aus `adresses` liest

Nur `adresses.shipping`, und zwar so (`invoicing()` in `shop.account.php`,
Zeilen 108–137):

| Nutzlast | Wirkung |
| --- | --- |
| `default: true` | `ar.shipto_id = shipping.id` (darf `null` sein) |
| `default: false`, `id` gesetzt | `ar.shipto_id = id` |
| `default: false`, `id: null` | neue Zeile in `shipto`, deren Id an die Rechnung |

`adresses.billing` wird nie gelesen, `ar.billing_address_id` ist immer `NULL`.
Es wird trotzdem mitgeschickt — unverändert zum alten Stand.

### Der Rechnungslink

`/rechnung/?link=<uuid>` ist der einzige Nachweis, dass jemand diese Rechnung
sehen darf; auch ein Gast ohne Konto kommt so an sein PDF. Fehlt der
Parameter, fragt `<shop-invoice>` gar nicht erst — das alte
`getInvoiceSummary()` schickte dann `ar_link=undefined`, bekam
`INVOICE_LINK_NOT_FOUND` und ließ die Seite leer, weil das ganze Markup auf
`display:none` stand.

Schlägt der Mailversand fehl, hängt `<shop-checkout>` `&mail=error` an und die
Rechnungsseite weist auf den Download hin. Vorher war das ein `alert()`, das
man wegklickt und danach nicht mehr sieht.

### Drei Zahlungszustände, nicht zwei

`getInvoiceSummary` liefert `paid` **und** `pending`:

| paid | pending | Anzeige |
| --- | --- | --- |
| `true` | `false` | nichts weiter — bezahlt |
| `false` | `true` | Hinweis „Ihre Zahlung wird noch bestätigt" |
| `false` | `false` | Bankverbindung zur Überweisung |

Der mittlere Fall entsteht, wenn PayPal die Zahlung angenommen hat, das Geld
aber noch unterwegs ist (Lastschrift, eCheck, Risikoprüfung). Ihm die
Bankverbindung zu zeigen wäre die Bitte, ein zweites Mal zu zahlen. Siehe
„Schwebende PayPal-Zahlungen" in `../README.md`.

## Sitzungskontext

Fast jede Aktion des Backends setzt das Cookie `HUGOSHOPCLIENTID` voraus;
angelegt wird es ausschliesslich von der Aktion `getContext`. Frueher stiess
`shopwindow.js` das erst im `window`-load-Handler an — wer direkt auf
`/warenkorb/` landete, lief in ein Wettrennen, weil das Modul-Script des
Shortcodes vorher ausgefuehrt wird.

`apiRequest()` faengt das ab: bei `SHOP_CONTEXT_ERROR` wird der Kontext
einmal geholt und die Aktion wiederholt. Das Ergebnis von `getContext` wird
im Modul gehalten (eine Anfrage pro Seite, nicht eine pro Widget) und nach
`shop:auth-changed` verworfen.

## Betraege

Das Backend mischt rohe und formatierte Zahlen — teils innerhalb *einer*
Antwort: `getCart` liefert `positions[].unitPrice` als `"9,80"`
(`formatPrice()`), `totalSum` dagegen als `"35.39"`; `changeQuantity` gibt
`totalSum` formatiert, `nettoTotalSum` roh zurueck. `core/money.js` liest
beide Schreibweisen (ein Komma ist der eindeutige Marker) und formatiert erst
beim Rendern.

## Theming: CSS-Framework austauschen

Die Widgets sind an **kein** Framework gebunden. Zwei Dinge sind konfigurierbar:
welche Klassennamen die Templates vergeben und welches Stylesheet in die
ShadowRoots adoptiert wird. Beides steckt im *Preset* (`core/presets.js`).

| Preset | Klassen für `input` | Stylesheet |
|---|---|---|
| `bootstrap5` (Default) | `shop-input form-control` | `<link …bootstrap…>` der Seite |
| `pure` | `shop-input pure-input-1` | `<link …pure…>` der Seite |
| `tailwind` | `shop-input block w-full rounded border …` | `<link …tailwind…>` oder konfiguriert |
| `custom` | nur `shop-input` | frei konfigurierbar |
| `none` | nur `shop-input` | keins |

Umgestellt wird unter `params.shopui` in der `config.json` der Instanz:

```json
"params": { "shopui": { "theme": "custom", "stylesheet": "/css/shop-ui.css" } }
```

Alternativ vor dem Laden des Bundles `window.ShopUIConfig = { theme, stylesheet, classes }`.

Einzelne Rollen lassen sich überschreiben, ohne ein Preset zu ändern — ebenfalls
in der `config.json` der Instanz:

```json
"params": { "shopui": { "theme": "tailwind", "classes": {
    "buttonSecondary": "inline-block rounded px-4 py-2 bg-teal-700 text-white"
}}}
```

Unbekannte Rollennamen werden verworfen und auf der Konsole gemeldet, damit ein
Tippfehler nicht still ins Markup durchschlägt. Die gültigen Rollen stehen in
`core/presets.js` (`ROLES`) und im Abschnitt unten.

**Jede Rolle vergibt immer zusätzlich eine framework-unabhängige Klasse**
(`shop-input`, `shop-button-primary`, `shop-alert-error`, …). Ein eigenes
Stylesheet dockt daran an und braucht kein gepflegtes Preset.

### Die Datei `core/presets.js`

Das ist die einzige Stelle, an der ein Framework-Klassenname steht. Keine
Komponente schreibt `class="form-control"` in ihr Template; sie fragt nach
einer Rolle:

```js
html`<input class="${this.cls('input')}">`
```

Was dabei herauskommt, entscheidet allein diese Datei. Ohne sie wäre der
Framework-Name über 16 Komponenten und mehr als 140 Stellen verteilt.

**Was drin steht**

| Export | Bedeutung |
|---|---|
| `PRESETS` | die fünf Presets `bootstrap5`, `pure`, `tailwind`, `custom`, `none` |
| `DEFAULT_PRESET` | `'bootstrap5'` — greift, wenn nichts konfiguriert ist |
| `ROLES` | die gültigen Rollennamen, abgeleitet aus `EMPTY` |
| `getPreset(name)` | Preset nachschlagen, unbekannter Name fällt auf den Default zurück |
| `withOverrides(preset, overrides)` | `params.shopui.classes` daraufsetzen, unbekannte Rollen verwerfen und melden |

Jedes Preset hat drei Felder: `name`, `detect` (CSS-Selektor, mit dem
`core/theme.js` das Stylesheet des Frameworks auf der Seite findet, wenn es
nicht ausdrücklich konfiguriert ist) und `classes` — die eigentliche Tabelle
Rolle → Klassennamen.

**Die vierzehn Rollen**

Sie sind bewusst wenige und beschreiben eine *Aufgabe im Formular*, kein
Aussehen. Neue Rollen erfindet man nur, wenn sie in jedem Framework eine
Entsprechung haben.

| Rolle | wofür | Stellen |
|---|---|---|
| `form` | Formular-Container | 8 |
| `heading` | Überschrift innerhalb einer Komponente | 10 |
| `label` | Beschriftung eines Feldes | 16 |
| `input` | Textfeld | 14 |
| `select` | Auswahlfeld | 7 |
| `check` | Kontrollkästchen | 6 |
| `button` | Knopf ohne besonderen Rang | 16 |
| `buttonPrimary` | die eine Haupthandlung einer Seite | 6 |
| `buttonSecondary` | Nebenhandlung | 13 |
| `alertError` | Fehlermeldung | 16 |
| `alertSuccess` | Erfolgsmeldung | 4 |
| `alertInfo` | Hinweis | 2 |
| `link` | Verweis | 4 |
| `muted` | zurückgenommener Text | 28 |

**Zwei Klassen, nicht eine**

`cls('button')` liefert nie nur den Framework-Namen, sondern immer auch eine
framework-unabhängige Klasse aus dem Rollennamen (`theme.js`, `classesFor`):

```
cls('buttonPrimary')  →  "shop-button-primary btn btn-primary"
cls('muted')          →  "shop-muted text-secondary"
```

Die `shop-*`-Klasse ist der Ankerpunkt für `core/defaults.js` und für ein
eigenes Stylesheet. Sie bleibt bestehen, auch wenn das Preset leer ist
(`custom`, `none`) — deshalb sehen die Widgets ohne jedes Framework brauchbar
aus.

**Wo die Reihenfolge zubeißt**

Die Schichten im ShadowRoot werden in dieser Folge adoptiert
(`core/base.js`): defaults, dann das Theme-Stylesheet, dann die `static
styles` der Komponente. Bei gleicher Spezifität gewinnt die spätere. Unter
`bootstrap5` überschreibt Bootstraps `.btn` also die `.shop-button`-Regel aus
`defaults.js` — die Custom Properties `--shop-button-bg` und Verwandte greifen
dort nicht. Sie wirken nur bei `theme: "custom"` und `"none"`. Wer das
Bootstrap-Aussehen ändern will, ändert die Klasse im Preset, nicht die
Variable.

**Ein Preset muss vollständig genug sein**

Gelernt am Knopf: `bootstrap5` trug lange `button: 'btn'`. Nacktes `btn` ist
in Bootstrap kein fertiger Knopf, sondern nur das Gerüst — transparenter
Hintergrund, transparenter Rahmen, und die Hover-Variablen sind gar nicht
definiert. Das Ergebnis war Text mit Rahmen beim Überfahren. Eine Rolle
braucht in einem semantischen Framework die Variante mit dazu
(`btn btn-outline-dark`), in Tailwind ohnehin die ganze Kette.

**Was man wo ändert**

Zwei Wege, und sie liegen in verschiedenen Dateien:

*Für alle Shops* — in **dieser** Datei, `core/presets.js`, den Wert der Rolle
im gewünschten Preset ändern, danach `npm run build`. Das Bündel liegt im
Site-Kit und wird vom Läufer in jede Instanz kopiert, die Änderung wirkt also
nach dem nächsten Lauf überall.

*Für einen einzelnen Shop, ohne Neubau* — in der `config.json` **der
Instanz** (z.B. `sonic24.de/config.json`) die Rolle unter `params.shopui.classes`
überschreiben. `withOverrides()` legt das beim Start auf das Preset:

```json
"params": { "shopui": { "theme": "bootstrap5", "classes": {
    "button": "btn btn-outline-secondary"
}}}
```

Der zweite Weg gewinnt gegen den ersten und braucht kein `npm run build`,
weil er zur Laufzeit ausgewertet wird.

### Tailwind: drei Dinge, die anders sind

Bootstrap und Pure sind semantisch — eine Klasse trägt ein Aussehen. Tailwind
ist ein Utility-Framework, das Aussehen entsteht erst aus der Kette. Daraus
folgt:

**1. Tailwind muss das Widget-Bundle scannen.** Tailwind erzeugt CSS nur für
Klassen, die es beim Bauen findet. Die Klassen der Widgets stehen nicht im
Shop-Projekt, sondern im Bundle, das der Läufer nach `oserp-shop/` kopiert —
der Tailwind-Build des Shops muss es einschließen, sonst fehlen die Utilities
im ausgelieferten CSS. Das minifizierte Bundle genügt: Die Klassenketten stehen
darin unverändert als Zeichenketten.

```js
// tailwind.config.js (v3)
content: ['./layouts/**/*.html', './oserp-shop/assets/shop-ui/*.js']
```
```css
/* v4 — der Pfad gilt relativ zur CSS-Datei, hier assets/css/ */
@source "../../oserp-shop/assets/shop-ui";
```

Achtung: Klassen aus `params.shopui.classes` stehen in der **Site-Config** und
werden dabei nicht gefunden. Entweder die Config-Datei mitscannen oder die
Klassen in die Safelist aufnehmen.

**2. Es muss das gebaute CSS sein.** Das Stylesheet wird per `replaceSync()`
in ein `CSSStyleSheet` übernommen, und dabei werden `@import`-Regeln
verworfen. Eine Quelldatei mit `@import "tailwindcss";` käme also leer an —
immer die kompilierte Ausgabe konfigurieren.

**3. Preflight neutralisiert die defaults-Schicht.** Tailwinds Preflight setzt
`input`, `button` usw. zurück und steht in der Kaskade nach `defaults.js`. Das
`tailwind`-Preset muss deshalb **vollständig** sein — es kann sich nicht wie
Bootstrap darauf verlassen, dass die Defaults Lücken füllen. Wer eine Rolle
ergänzt, muss sie dort mit ausformulieren.

Ungeprüft: ob Tailwind v4 mit `@property`- und `@layer`-Regeln innerhalb eines
adoptierten Stylesheets im ShadowRoot vollständig funktioniert. Das lässt sich
nur im Browser feststellen und sollte vor einem produktiven Tailwind-Shop
einmal nachgesehen werden.

Die Kaskade im ShadowRoot ist dreischichtig:

```
1. defaults.js         trägt die Komponente ohne Framework
2. Theme-Stylesheet    überschreibt, was es kennt (Bootstrap kennt .form-control)
3. static styles       Layout und Struktur des Widgets — gewinnt immer
```

Deshalb genügt es, ein Framework zu entfernen: die Komponenten fallen auf
Schicht 1 zurück und bleiben benutzbar. Alle Werte dort hängen an
CSS-Custom-Properties (`--shop-accent`, `--shop-radius`, `--shop-border-color`,
`--shop-field-gap`, `--shop-form-width`, …) — die erben durch die
Shadow-Grenze, ein Shop kann sie also von außen setzen, ohne irgendetwas zu
injizieren. Für punktuelle Eingriffe tragen die Elemente zusätzlich
`part`-Attribute (`shop-login::part(submit)`).

Eine Einschränkung, die man leicht übersieht: eine Custom Property wirkt nur,
solange die Regel aus Schicht 1 überhaupt noch gilt. Überschreibt Schicht 2
dieselbe Eigenschaft bei gleicher Spezifität, läuft das Setzen der Variablen
ins Leere — genau das passiert unter `bootstrap5` bei den Knöpfen (siehe „Die
Datei `core/presets.js`"). Bei `theme: "none"` und `"custom"` greifen sie
durchgehend, `part` in jedem Fall.

**Grenze der Abstraktion:** ausgetauscht wird das *Klassenvokabular*, nicht die
Markup-Struktur. Ein Framework, das anderes DOM verlangt (zusätzliche
Wrapper-Elemente), lässt sich damit nicht abbilden — für Label/Input/Button/
Alert, worum es hier durchgehend geht, reicht es.

## Regeln für neue Komponenten

1. **Von `ShopElement` erben**, nicht direkt von `LitElement`. Die Basisklasse
   setzt `:host{display:block}`, `box-sizing` und adoptiert die Theme-Schichten.
2. **Styles komponieren:** `static styles = [ShopElement.baseStyles, css\`…\`]`.
   Ein eigenes `static styles` ohne `baseStyles` überschreibt die Basis.
3. **Keine Framework-Klassen im Template.** Immer `this.cls('input')`,
   `this.cls('buttonPrimary')` usw. — sonst ist das Theming wieder
   festgenagelt. Fehlt eine Rolle, gehört sie in `core/presets.js` und
   `core/defaults.js` ergänzt, nicht ins Template.
4. **`this.$('id')` statt `document.getElementById('id')`.** IDs sind innerhalb
   des ShadowRoots lokal und dürfen sich zwischen Komponenten wiederholen.
5. **Die Shadow-Grenze nie zwischen Grid-Container und Grid-Kind legen.**
   Äußere Layout-Klassen (`col-md-6` o.ä.) gehören an den Host, der steht im
   Light DOM. Innen mit CSS Grid arbeiten (`.fields` in `baseStyles`).
6. **Kein Zugriff auf fremdes Markup.** Zustandswechsel über `bus.js` melden,
   nicht in den Header eines anderen Widgets schreiben.
7. **Formulare als echtes `<form>`** mit `autocomplete`-Tokens und `name`.
   Ohne das erkennen Browser-Autofill und Passwortmanager die Felder nicht —
   im Shadow-DOM erst recht nicht.

## Bootstrap-Ausstieg

Aktuell gilt Preset `bootstrap5`: das Bootstrap-Stylesheet der Seite wird
einmal geladen und als geteilte `CSSStyleSheet` in jeden ShadowRoot adoptiert,
damit die vorhandenen Klassen weiter wirken.

Der Ausstieg braucht dann **keine Änderung an den Komponenten** mehr — die
Templates enthalten ja keine Bootstrap-Klassen, nur Rollen. Vorgehen:

1. `defaults.js` ausbauen, bis die Widgets ohne Framework so aussehen, wie sie
   sollen (bzw. ein eigenes Stylesheet für Preset `custom` schreiben).
2. Preset in der Site-Config auf `none` bzw. `custom` stellen und vergleichen.
3. Erst danach lässt sich Bootstrap aus dem Theme selbst entfernen — das
   betrifft aber die Seitenstruktur außerhalb der Widgets und ist ein
   eigener Schritt.

Zwischenstände sind pro Shop-Instanz möglich: das Preset steht in der
Site-Config, nicht im Bundle.

## Testen

### 1. Framework-Prüfstand: `/shop-ui-demo/`

Statische Seite aus `demo/`, die **nicht** von Hugo gerendert wird — Pure und
Tailwind sind im Theme ja gar nicht vorhanden. Sie liegt im Docroot und läuft
damit same-origin: `/shop-api/`, Cookies und Browser-Autofill verhalten sich
wie im echten Shop.

```
/shop-ui-demo/                    Bootstrap 5 (wie im Shop)
/shop-ui-demo/?theme=pure         Pure.css 3
/shop-ui-demo/?theme=tailwind     Tailwind 3
/shop-ui-demo/?theme=custom       eigenes CSS (demo/vendor/custom-demo.css)
/shop-ui-demo/?theme=none         ganz ohne Framework (nur defaults.js)
```

Jede Variante zeigt das Widget einzeln, zweispaltig im Grid **des jeweiligen
Frameworks** (`row/col-md-6`, `pure-g/pure-u-1-2`, `grid grid-cols-2`) und eine
Diagnose-Ausgabe: Preset, Anzahl der adoptierten Stylesheets, tatsächliche
Klassen am Input, berechnete Werte für `border`/`padding`/`box-sizing` sowie
`display` des Hosts.

Mounts dafür, ebenfalls unter `module.mounts` in der `config.json` der Instanz
— **nur** in Entwicklungs-Instanzen setzen, sie legen die Testseite in den
Docroot. Sie zeigen auf den Arbeitsstand von OpensourceERP (Pfad anpassen) und
setzen `npm run build:dev` oder `npm run watch` voraus:

```json
{ "source": "../../opensource-erp/shop-ui/demo", "target": "static/shop-ui-demo" },
{ "source": "../../opensource-erp/shop-ui/dist", "target": "static/shop-ui-demo/dist" }
```

Das Tailwind-CSS der Demo wird mit `npm run demo:css` gebaut und scannt dabei
`src/**/*.js` — dieselbe Konfiguration, die ein Tailwind-Shop braucht.

### 2. Preset auf echten Seiten umschalten

Wenn die Site `params.shopui.allowUrlTheme = true` setzt (nur Entwicklung):

```
/login/?shop-ui-theme=none
/login/?shop-ui-theme=custom&shop-ui-stylesheet=/css/mein-shop-ui.css
```

Ohne das Flag wird der Parameter ignoriert — sonst könnte jeder Besucher das
Aussehen des Shops per Link verändern. Fremde Origins werden auch mit Flag
abgelehnt: ein untergeschobenes Stylesheet im ShadowRoot könnte über
Attributselektoren Formularinhalte nach außen tragen.

### 3. Was im Browser zu prüfen ist

* **Layout** — zweispaltige Variante schmal und breit. Die Komponente muss eine
  Box im äußeren Grid sein, nie Grid-Container und -Kind zugleich.
* **Autofill** — gespeicherte Zugangsdaten anbieten lassen, Passwortmanager
  öffnen. Das ist der Punkt, an dem die Shadow-DOM-Entscheidung hängt.
* **Fehlerfall** — falsches Passwort: Meldung erscheint, Passwortfeld wird
  geleert und fokussiert, Button ist wieder aktiv.
* **Leere Felder** — Absenden ohne Eingabe zeigt die Sammelmeldung.
* **Enter** im Passwortfeld sendet ab (Formular, kein keypress-Handler).
* **DevTools:** `document.querySelector('shop-login').shadowRoot.adoptedStyleSheets.length`

Für die Konto-Seiten zusätzlich:

* **Abgemeldet** `/persönliche-daten/`, `/persönliches-profil/`, `/adressen/`,
  `/bestellungen/`, `/zahlungsarten/` aufrufen: jede Seite zeigt den Hinweis
  mit Link zur Anmeldung — **kein** Sprung nach `/login/`. Im Netzwerk-Reiter
  darf dabei **keine** Konto-Aktion abgeschickt worden sein; der Kontext
  allein entscheidet.
* **Angemeldet** dieselben Seiten: Begrüßung in der Seitenleiste, Daten in den
  Kacheln.
* **Profil** — Kontotyp auf „Gewerblich" stellen: das Feld Firmenname
  erscheint, die Beschriftung von Name wechselt auf „Kontaktname". Die drei
  Formulare speichern getrennt und melden getrennt.
* **`/persönliche-daten/` → „Passwort ändern"** landet auf
  `/persönliches-profil/#Passwort` und muss zum Passwort-Abschnitt scrollen.
  Das Ziel liegt im ShadowRoot, der Browser findet es nicht von allein — die
  Komponente scrollt selbst.
* **Adressen** — anlegen, bearbeiten, Standard setzen, „Rechnungsadresse
  übernehmen", löschen. Eine Adresse, die zu einer Bestellung gehört, hat
  keinen Löschen-Knopf.
* **Zahlungsart** umschalten: die Auswahl darf bei einem Serverfehler nicht
  stehen bleiben, sondern springt zurück und meldet.
* **Bestellungen** — Karte öffnet die Detailansicht, „Zurück" schließt sie,
  der Download-Knopf löst **nur** den Download aus und öffnet nicht die
  Detailansicht. Mehrfach hin- und herwechseln: die Positionen dürfen sich
  nicht summieren (im alten `account.js` taten sie das).
* **`/kasse/` abgemeldet** — Gastformular links, Warenkorb rechts. Schalter
  „Kundenkonto anlegen" an: die Passwortfelder erscheinen, „Jetzt kaufen"
  verschwindet, bereits getippte Werte bleiben stehen. Wieder aus: alles
  zurück.
* **`/kasse/` angemeldet** — Lieferadresse umschalten, „Neue Adresse" öffnen,
  aus einer gespeicherten übernehmen, „Abbrechen". Die Standardadresse darf
  in der Auswahl **nicht** doppelt stehen.
* **Bestellung abschließen** — dabei entsteht eine echte Rechnung im ERP.
  Danach `/rechnung/?link=…`: E-Mail-Zeile, PDF-Download, bei unbezahlter
  Rechnung der Bankblock, darunter die Lieferanschrift.
* **`/rechnung/` ohne `?link=`** zeigt den Hinweis, nicht eine leere Seite.
* **PayPal** — der Knopf steht jetzt neben „Jetzt kaufen" statt über der
  Seite. Der Weg selbst (`/shop-api/?action=beginPayment`) ist unverändert.
* **Seitenkopf, abgemeldet und angemeldet** — die Knöpfe wechseln ohne
  Neuladen, wenn man sich auf `/login/` anmeldet oder oben abmeldet. Der
  Warenkorb-Zähler zählt beim Hinzufügen mit. Bei leerem Warenkorb sagt der
  Knopf das beim Klick (früher ein Bootstrap-Popover).
* **Suchfeld** — tippen, Pfeiltasten, Eingabetaste, Escape. Ein Treffer muss
  **innerhalb dieser Instanz** landen, nicht auf `sonic24.de`. Ohne Treffer
  erscheint „Keine Ergebnisse gefunden".
* **`/suchergebnisse/?terms=…`** — der Begriff steht im Kopf-Suchfeld (das
  erledigte früher `initSearchSlot()`), die Liste zeigt bis zu elf Treffer,
  „Weitere Ergebnisse anzeigen" hängt den Rest an.
* **`/kontakt/`** — angemeldet sind die Stammdaten vorbelegt, `?pid=123` füllt
  das Anliegen vor. Pflichtfelder und E-Mail-Prüfung greifen. **Absenden
  verschickt eine echte E-Mail.**

### 4. Logik ohne Browser

`core/theme.js` lässt sich mit einem kleinen DOM-Shim in Node prüfen
(Preset-Auflösung, Stylesheet-Ermittlung, Klassenausgabe, Reihenfolge der
Kaskade) — die Datei hängt an keinem Browser-API außer `document.querySelector`
und `CSSStyleSheet`.

Eingecheckte Tests gibt es dafür bislang **nicht**; wer welche schreibt, legt
sie neben `src/` an und nennt sie hier.

### 5. Hugo-Seite

`/shop-ui-test/` (noindex) im Projekt `sonic24.de` zeigt die Widgets im echten
Theme — einzeln und als Grid-Kind in einer `.row`, samt aller Konto-Panels.
