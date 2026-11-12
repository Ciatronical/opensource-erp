# Shop: Name der Produktseite (Adresse im Shop)

Verbindliche Vorgabe vom 2026-11-12. Sie ersetzt die Regel „leer = Seite nach
Artikelnummer“ aus `dev/shop-betrieb.md` (Abschnitt „Produktseite in der
Artikelmaske“, Stand 2026-10-29).

## Anlass

Im Shop von Autoprofis stand ein Artikel unter

    https://autoprofis24.de/shop/artikel/959/

Der letzte Teil der Adresse ist die Artikelnummer. Eine solche Adresse sagt dem
Kunden nichts und ist für Suchmaschinen wertlos. Der Artikel heißt
„Angebot – Winterkomplettradsatz für Mercedes-Benz V-Klasse (ab 2014) RDKS“;
die Adresse muss das wiedergeben, etwa

    /shop/artikel/angebot-winterkomplettradsatz-fuer-mercedes-benz-v-klasse-ab-2014-rdks/

Es ist Aufgabe von OSERP, die Adresse so zu bilden, dass sie einen sprechenden
Namen trägt — für den Kunden und für die Suchmaschinen.

## Regeln

Alle Regeln gelten nur, wenn die Shop-Erweiterung eingeschaltet ist **und** der
Artikel im Shop angeboten wird (Schalter „Im Shop anbieten“).

| Nr. | Regel |
| --- | --- |
| N1 | Der Name der Produktseite (`parts_ext.hugoshop_hyperlink`, Feld „Produktseite“ in der Artikelmaske) ist ein sprechender Name. Grundlage ist die Beschreibung des Artikels (`parts.description`). |
| N2 | **Die Artikelnummer wird für den Namen nicht mehr verwendet** — weder als Name noch als Rückfall für ein leeres Feld noch als Anhang an einen vergebenen Vorschlag. Kein neuer Code darf aus der Artikelnummer einen Seitennamen bilden. |
| N3 | „Produktseite“ ist ein Pflichtfeld: Ein Artikel mit leerem Feld wird nicht gespeichert und nicht angelegt. Die Maske zeigt den Fehler am Feld, das Backend lehnt zusätzlich ab. |
| N4 | Ist das Feld leer, trägt die Maske aus der Beschreibung einen Vorschlag ein — sofort, wenn die Beschreibung vorhanden ist, sonst sobald sie eingetragen wird. Das gilt für neue und für bestehende Artikel. Ein eingetragener Name wird nie stillschweigend überschrieben. |
| N5 | Vor dem Speichern wird per Ajax geprüft, ob ein anderer Artikel die Produktseite schon belegt (`checkShopPageName`). Ist der Name vergeben, wird der Benutzer benachrichtigt und muss selbst einen anderen Namen finden — es gibt keine automatische Ausweichlösung. |
| N6 | **Bestand bleibt, wie er ist.** Artikel ohne eingetragene Produktseite behalten ihre Seite unter der Artikelnummer, bis ein Benutzer den Artikel bearbeitet. Dann greift N3: gespeichert wird erst mit befüllter Produktseite. Das bloße Öffnen eines Artikels ändert nichts. |

## Bildung des Vorschlags

`shopSlug()` in `src/features/shop/utils/slug.js`: Kleinbuchstaben, Umlaute
aufgelöst (ä → ae, ö → oe, ü → ue, ß → ss), Akzente entfernt, alles außer
Buchstaben und Ziffern wird zum Bindestrich, keine Bindestriche am Rand.

| Beschreibung | Vorschlag |
| --- | --- |
| Angebot – Winterkomplettradsatz für Mercedes-Benz V-Klasse (ab 2014) RDKS | `angebot-winterkomplettradsatz-fuer-mercedes-benz-v-klasse-ab-2014-rdks` |

## Umsetzung (2026-11-12)

| Stelle | Verhalten |
| --- | --- |
| `part-shop.card.vue` | `seiteFehler` zeigt am Feld: leer (`hyperlinkRequired`), gleich der Artikelnummer (`hyperlinkIsPartnumber`), vergeben (`hyperlinkTaken`). `vorschlagFallsLeer()` trägt den Vorschlag ein — nach dem Laden, wenn die Beschreibung sich ändert und beim Einschalten von „Im Shop anbieten“. `vorschlagAnwenden()` hängt keine Artikelnummer mehr an. `speichern()` sendet nicht, solange das Feld leer ist oder die Artikelnummer trägt. |
| `part-shop.card.vue`, Bestand | Der nach dem Laden eingetragene Vorschlag löst kein Speichern aus (`nurVorschlag`); er wird mit der nächsten Änderung an Karte oder Artikel gespeichert (`sichern()`). |
| `article.edit.view.vue` | `saveArticle()` ruft vor `updatePart` `seiteGueltig()` der Karte auf (Pflicht, Artikelnummer, Ajax-Prüfung auf Dopplung) und speichert sonst nicht; die Meldung `hyperlinkBlocksSave` steht oben in der Maske. Sobald die Produktseite stimmt, läuft das Speichern nach. `createArticle()` prüft ebenso, `canCreate` sperrt den Knopf. |
| `savePartShopData` (`backend/api/shop/admin.php`) | lehnt ab mit `PAGE_NAME_MISSING` (leer), `PAGE_NAME_IS_PARTNUMBER` (gleich der Artikelnummer), `PAGE_NAME_TAKEN` (vergeben). |
| Locale-Dateien der Shop-Erweiterung (21 Sprachen) | Hinweis am Feld ohne „Leer = Artikelnummer“, neue Meldungen und Fehlertexte. |

## Bewusst unverändert — nur wegen des Bestands

`shop_page_file_name()` (SQL) und `shopPageSlug()` (PHP) fallen bei leerer
Kennung weiterhin auf die Artikelnummer zurück. Nur so bleiben die bestehenden
Seiten des Bestands und die Links darauf (Warenkorb, Suche, Rechnung) gültig
(N6). Für neu gespeicherte Artikel kommt der Rückfall nicht mehr vor, weil kein
leerer Name mehr gespeichert wird. Der Rückfall kann entfallen, sobald kein im
Shop angebotener Artikel mehr ohne Produktseite ist.

## Offene Punkte

| Nr. | Punkt |
| --- | --- |
| O1 | Andere Wege ins Angebot, die nicht über `savePartShopData` laufen (Zuordnung im Sortiment, Importe), prüfen die Produktseite nicht. Solche Artikel zählen bis zur nächsten Bearbeitung in der Artikelmaske zum Bestand. |
| O2 | Ändert ein Bestandsartikel seinen Namen, entfernt der Lauf die Seite unter der alten Adresse (`/959/`). Eine Weiterleitung von der alten auf die neue Adresse gibt es nicht. |
| O3 | Artikel ohne Beschreibung bekommen keinen Vorschlag; der Benutzer trägt den Namen von Hand ein. |
