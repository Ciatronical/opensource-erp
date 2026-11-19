# Übergabe: Umstellung solar-spar.de auf die Shop-Erweiterung

Stand 19.11.2026. Geschrieben für eine neue Sitzung, die auf dem Server
weiterarbeitet und den Verlauf der lokalen Probeumstellung nicht kennt.

## Ziel und Grenzen

- Der Bridge-Shop solar-spar.de (Kivitendo-Bridge, Theme hugoshop) wird auf die
  Shop-Erweiterung von OpensourceERP mit HugoCMS umgestellt.
- **Oberstes Ziel:** Der Käufer merkt nichts. Alle Adressen und Seiteninhalte
  bleiben, wie sie sind. Ausnahme ist nur die Portal-/Shop-UI (Warenkorb,
  Kasse, Konto).
- **Der Vorlagensatz regelt, was die Webseite gleich hält.** Abweichungen vom
  alten Shop werden im Satz gelöst, nicht durch Umbenennen von Daten.
- **Nie auf die Datenbank des laufenden Shops (`solar_spar`) schreiben.**
  Gearbeitet wird auf einer Kopie. Auf dem Server heißt das: eigene Datenbank,
  eigenes Verzeichnis der Webseite, eigene Adresse (Subdomain mit Zugangsschutz
  und `noindex`), PayPal im Sandbox-Betrieb.
- Der Lieferantenimport der Bridge (`prepublish/`, `publish/run.*.php`) bleibt
  vorerst, wie er ist, und schreibt weiter in `solar_spar`. Der laufende Shop
  kann erst umgestellt werden, wenn der Import geklärt ist. Am Tag der echten
  Umstellung läuft die Datenübernahme noch einmal mit frischer Kopie.

Arbeitsregeln des Projekts stehen in `CLAUDE.md` und
`dev/programmierstil-richtlinien.md`. Zusätzlich gilt: kein `git add`/`commit`
und kein `tools/fix-ws.sh` ohne Auftrag; Kivitendo-Originaltabellen nie ändern;
Schemaänderungen nachfragen; Antworten in klarem Hochdeutsch.

## Was lokal erreicht ist

Verglichen wurden die 22.431 neu erzeugten Produktseiten mit dem alten Stand:

| Merkmal | gleich |
| --- | --- |
| Dateinamen und Adressen | alle |
| Zusammenfassung, Beschreibung | alle |
| Front Matter | 22.338 |
| Kategorieübersicht (`data/category_groups.json`, 1.301 Kategorien, 40 Gruppen) | vollständig |
| Rumpf ohne gewollte Abweichungen und ohne „Ähnliche Produkte“ | 19.832 |

Gewollte Abweichungen: Zeile „Lieferzeit 1–6 Tage“ unter „Verfügbar“,
`shop-add-to-cart` statt `in-cart`, `#focus` am Link „Mehr anzeigen“ auf allen
Seiten, `lastmod` im Front Matter. Der Rest (ähnliche Produkte, Reihenfolge
der Downloads, einzelne Preise) hängt am Datenstand, nicht an der Vorlage.

**Nicht erprobt:** der gesamte Kaufweg (Warenkorb, Anmeldung, Kasse, PayPal,
Rechnung, E-Mail), die Suche, die 404-Seite mit Umleitungen. Lokal fehlen
Erreichbarkeit von außen und ein Mailserver.

## Bausteine

| Baustein | Ort | Stand |
| --- | --- | --- |
| Vorlagensatz `solar-spar` | `backend/templates/shop/solar-spar/` (eigenes Repository `backend/templates`, im Hauptprojekt ignoriert) | am 19.11. dort **nicht committet** — vor der Arbeit auf dem Server prüfen |
| Datenübernahme | `tools/shop-bridge-daten.sql` | committet; Tabellen und Präfixe (`AT/`, `MM/`, `SK/`) je Shop |
| Einstellungen der Bridge | `tools/shop-bridge-settings.php <webseite>/themes/hugoshop/kivitendo_bridge` | gibt SQL aus, enthält PayPal-Zugangsdaten; lokal noch nicht eingespielt |
| Medienabgleich | `php tools/shop-media-import.php --client=<id>` | lokal gelaufen |
| Adressregel der Kategorien | Schlüssel `path` in `category_groups.php` des Satzes, `shopCategoryPath()` in `backend/api/shop/lib/categories.php` | committet |
| Vergleichsskripte | dieses Verzeichnis | nur lesend |

Der Satz besteht aus `theme.json`, `product.md.php` (Nachbau von
`publish/template.php`), `_helpers.php` (Kurztext, FAQ-Schema, Marke, ähnliche
Produkte, Adressteil der Kategorie), `category_groups.php` (153 Wortteile,
4 Topseller, Adressregel) und `README.md`. Das Webseiten-Paket kommt aus dem
Satz `standard`.

## Reihenfolge der Umstellung einer Kopie

1. **Kivitendo auf 4.0.0 heben.** Lokal waren 17 Skripte aus
   `sql/Pg-upgrade2` nötig. Ohne das scheitert das Schema der Erweiterung.
2. Datenbank kopieren. `createdb -T` scheitert, solange eine Verbindung die
   Quelle offen hält; dann `pg_dump | psql`.
3. Kopie als Mandant in der Auth-Datenbank eintragen (Name der Auth-Datenbank
   aus der Konfiguration lesen, nicht raten), CRM- und Shop-Schema einspielen.
4. `psql -d <kopie> -f tools/shop-bridge-daten.sql`. Der Bericht am Ende nennt
   unter anderem `marketingtexte_gesetzt` und `ohne_beschreibung`.
5. Firmenkonfiguration → Shop: „Nur Dropshipping“ an, Lieferbedingung
   „Lieferzeit 1–6 Tage“ als Vorgabe. **Erst die Lieferzeit, dann der
   Schalter** — ein Dropshipping-Artikel ohne Lieferzeit wird zum Entwurf und
   verliert seine Adresse. (Schritt 8 des Datenskripts setzt beides.)
6. Medien: Schalter „Ablage in OpensourceERP“ aus, die drei Verzeichnisse der
   Webseite eintragen (`products_images/`, `thumbnails/`, `downloads/`), der
   Webserver braucht dort Schreibrechte. Danach den Medienabgleich ausführen.
7. HugoCMS: Shop-Erweiterung an, Freigaben wie unten. Kanalkarte: Verbindung
   prüfen und Freigaben abrufen, Vorlagensatz `solar-spar` wählen, „Medien
   übertragen“ aus, Adressmuster wie unten.
8. **Einen Artikel veröffentlichen und prüfen**, bevor alle laufen: Die Seite
   muss `{{< product-gallery` enthalten und unter `/produkt/…` gebaut werden.
9. „Alle sofort veröffentlichen“, dann die Vergleichsskripte.

## Einstellungen für solar-spar

**Freigaben in HugoCMS:** Produktseiten `content/de/produkt/`,
Kategorieübersicht `data/category_groups.json`. Die Medienfreigaben bleiben
ungenutzt, solange „Medien übertragen“ aus ist.

**Adressmuster am HugoShop:**

| Feld | Wert |
| --- | --- |
| Artikelseite | `/produkt/%s/` |
| Kategorieseite | `/kategorien/%s/` (Vorgabe) |
| Taxonomie | `kategorien` (Vorgabe) |
| Vorschaubild | `/images/thumbnails/%s` |
| Produktbild | `/images/products/%s` |
| Download | `/downloads/%s` |

Die Medien liegen neben der Webseite und werden vom Webserver per Alias
eingebunden (`/images/products/`, `/images/thumbnails/`, `/downloads/`), nicht
von Hugo veröffentlicht.

## Fallen, die lokal aufgetreten sind

- **Einhängepunkt `content → content`.** solar-spar führt die Inhalte je
  Sprache unter `content/de`. Ein Mount `content → content` in der
  `config.json` der Webseite verschiebt alle Seiten nach `/de/…`. Richtig ist
  `content/de → content`. HugoCMS schreibt das seit dem 19.11. richtig und
  stellt einen alten Eintrag beim nächsten Mount-Fix richtig
  (`backend/core/Shop/SiteConfigWriter.php`); ein HugoCMS vor diesem Stand tut
  es nicht. **Nach dem ersten Bau prüfen, dass `public/produkt/` existiert und
  `public/de/` nicht.**
- **Hugo-Fassung.** Lokal baut HugoCMS mit 0.170.1. Das Paket verträgt Hugo vor
  und nach 0.156 (`hugo.Sites`); `lang` an Mounts ist ab 0.153 entfernt. Das
  Theme hugoshop meldet unter 0.170 Abkündigungen (`.Site.Data`,
  `.Site.AllPages`), noch keine Fehler. Die Fassung des Servers zuerst
  feststellen.
- **Kein Bau ohne Änderung.** OpensourceERP stößt einen Bau nur an, wenn sich
  die Lieferung ändert. Nach einer Änderung an der `config.json` der Webseite
  baut erst HugoCMS selbst oder ein Lauf mit geänderten Seiten.
- **Vorlagensatz nicht gewählt.** Dann entstehen alle Seiten aus `standard`
  (Kennzeichen: `{{< shop-product-images`). Deshalb Schritt 8.
- **Marketingtexte.** Zusammenfassung und Beschreibung stehen in der Bridge
  nicht am Artikel, sondern in den Quelltabellen `parts_*_source` (Spalte
  `marketing`, JSON mit `summery` oder `summary` und `description`). Schritt 9
  des Datenskripts schreibt sie in die Beschreibung des Artikels im HugoShop:
  Zusammenfassung, Trennlinie `<hr>`, Beschreibung. Ohne Trennlinie ist der
  ganze Text Zusammenfassung und der Reiter „Beschreibung“ bleibt leer — so
  zeigte es die Bridge, wenn die Zusammenfassung fehlte.
- **Abgebrochener Bridge-Lauf.** Der Vorlauf der Bridge setzt alle Artikel auf
  ungültig, erst die Veröffentlichung je Lieferant setzt lagernde zurück. Nach
  einem abgebrochenen Lauf stehen lieferbare Artikel als ungültig in der
  Datenbank; Schritt 7 des Datenskripts setzt sie anhand der Quelltabellen
  zurück. In der Bridge heißt `parts.obsolete` „ausverkauft“, nicht „aus dem
  Shop genommen“ — die Seite bleibt stehen.
- **Kategorien mit Schrägstrich oder Zollzeichen.** Der Satz bildet den
  Adressteil wie die Bridge (`solarSparKatalog`); im Sortiment wird nichts
  umbenannt.
- **Warnung „Übersichtsseite …/kategorien/_index.md fehlt“.** Der alte Shop hat
  die Datei nicht; die Warnung kann stehen bleiben.

## Was für die Webseite noch fehlt

1. **Theme.** Das Theme von solar-spar steht auf `master` und kennt die
   Shop-UI nicht: Warenkorb-Symbol, Suche und Kontoknöpfe der Kopfzeile
   sprechen die Bridge an. Die Unterstützung liegt im Zweig
   `feature/re-2026-08-07` des Themes; die drei Dateien `header.html`,
   `header/search.html`, `header/account-buttons.html` waren am 19.11. im
   Arbeitsstand von sonic24 geändert, aber nicht committet. Danach in der
   `config.json` unter `params.portalui` neben `taxonomy` auch
   `"theme": "bootstrap5"` setzen.
2. **Alte Shop-Seiten unter `content/de`.** `warenkorb.md`, `kasse.md` und
   `bezahlung-abgebrochen.md` beanspruchen dieselbe Adresse wie die Seiten des
   Pakets und müssen weg. Die Kontoseiten (`login`, `registrieren`,
   `persönliche-daten`, `bestellungen`, `adressen`, `rechnung`,
   `suchergebnisse`, …) behalten ihre Adresse und bekommen die neuen
   Shortcodes — Vorbild ist sonic24 (`dev/shop-bridge-abloesung.md`, Stufe D):
   `portal-login`, `portal-register`, `portal-account page="…"`,
   `shop-invoice`, `shop-search-results`.
3. **Einstellungen der Bridge** einspielen, Adresse von OpensourceERP für die
   Webseite setzen (`shop_backend_url`).
4. **Versandarten und Lagerplatz.** Kein Artikel hat ein Gewicht.
5. **Kundenkonten.** 74 Kunden sind übernommen; ob die Anmeldung mit dem
   bisherigen Kennwort gelingt, ist ungeprüft.
6. **Kaufweg prüfen:** Warenkorb, Anmeldung, Bestellung mit PayPal-Sandbox,
   Rechnungsabruf, E-Mail, Suche, 404-Seite und eine Umleitung.

## Vergleich alt gegen neu

Alle drei Skripte lesen nur. Erstes Argument ist das Verzeichnis der alten
Produktseiten, zweites das der neuen (`…/content/de/produkt`).

```
python3 dev/solar-spar-uebergabe/vergleich.py <alt> <neu>   # Front Matter und Rumpf, Abweichungen nach Art
python3 dev/solar-spar-uebergabe/texte.py     <alt> <neu>   # Aufteilung Zusammenfassung/Beschreibung
python3 dev/solar-spar-uebergabe/rest.py      <alt> <neu>   # Rumpf ohne gewollte Abweichungen, ähnliche Produkte
```

`vergleich.py` und `rest.py` ersetzen die lokale Adresse
`http://solar-spar.localhost/` durch `https://solar-spar.de/`; auf dem Server
die Adresse der Probeinstanz dort eintragen.

Die alten Seiten stammen aus Läufen der Bridge zu verschiedenen Zeiten: Die
Seiten von Schiessl (`SK/`) folgen der heutigen Vorlage, die von Mainmetall
(`MM/`) und Attack (`AT/`) einer älteren. Vollständige Gleichheit ist deshalb
nicht erreichbar; maßgeblich sind die Zahlen der Tabelle oben.

## Weiterführend

`dev/shop-bridge-abloesung.md` (Ablösung der Bridge, Stufe D für die
Webseite), `dev/shop-medienbibliothek.md` (Medien außerhalb),
`dev/shop-hugocms-verzeichnisse.md` (Freigaben), `dev/shop-sortiment.md`
(Kategorien und Adressregel), `dev/shop-veroeffentlichung.md`,
`dev/shop-betrieb.md`, `backend/templates/shop/solar-spar/README.md`.
