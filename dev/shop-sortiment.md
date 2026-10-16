# Sortiment: Gruppen und Kategorien des Shops

Stand: 16.10.2026

## Ziel

Die Kategorieübersicht der Webseite (`category_groups.json`) entstand bisher
aus Regeln: Wortteile der Kategorienamen ordneten jede Kategorie einer Gruppe
zu (`category_groups.php` im Vorlagensatz, portiert aus der Bridge von
sonic24). Die Kategorie selbst war ein Freitext je Artikel.

Das hatte zwei Schwächen. Ein Tippfehler in der Artikelkarte ergab eine zweite
Kategorie. Und eine Gruppe je Artikel einzugeben, wäre widersprüchlich: zwei
Artikel derselben Kategorie könnten verschiedene Gruppen tragen.

Deshalb gibt es jetzt ein **Sortiment** in der Datenbank. Gruppen und
Kategorien werden in einer eigenen Ansicht gepflegt, die Gruppe gehört der
Kategorie, und der Artikel wählt seine Kategorie aus einer Liste.

## Daten

| Tabelle | Inhalt |
| --- | --- |
| `category_group_shop` | Gruppe: `name` (eindeutig ohne Groß- und Kleinschreibung), `sortkey` (Reihenfolge der Übersicht) |
| `category_shop` | Kategorie: `name` (eindeutig ohne Groß- und Kleinschreibung), `group_id` (leer = Restgruppe), `topseller` |
| `parts_ext.hugoshop_category_id` | Kategorie des Artikels, `ON DELETE RESTRICT` |

Gruppe löschen setzt `group_id` ihrer Kategorien auf leer (`ON DELETE SET NULL`).
Eine Kategorie mit Artikeln lässt sich nicht löschen.

Je Mandant ein Sortiment: die Artikeltexte in `parts_ext` gelten für alle
HugoShops (dev/shop-mehrere-kanaele.md, M6).

**Abgelöst:** `parts_ext.hugoshop_category` (Freitext). Das Upstall übernimmt
noch nicht übernommene Werte einmalig ins Sortiment, ohne Gruppe, und
verknüpft die Artikel. Artikelkarte und Werkzeugabgleich leeren den Freitext
beim Speichern, damit eine bewusst entfernte Kategorie nicht zurückkommt. Die
Spalte bleibt vorerst; entfernt werden kann sie in einem späteren Upstall.

## Zwei Wege zur Übersicht

`shopWriteCategoryGroups()` (lib/categories.php) entscheidet nach dem
Vorlagensatz des HugoShops:

| Satz | Gruppen | Topseller |
| --- | --- | --- |
| ohne eigene `category_groups.php` (`standard`) | aus dem Sortiment, in der Reihenfolge `sortkey`, dann Name; Kategorien ohne Gruppe unter „Sonstiges“; keine Mindestgröße | `category_shop.topseller`, nach Namen |
| mit eigener `category_groups.php` (`sonic24`) | aus den Wortteilen der Regeln wie bisher | aus den Regeln |

Die **Kategorien** kommen in beiden Fällen aus dem Sortiment; in der Übersicht
stehen nur Kategorien mit mindestens einem angebotenen Artikel. Die Adressen
folgen dem Muster der Kanalkarte (`category_link`, dev/shop-veroeffentlichung.md).

`standard/category_groups.php` gibt es nicht mehr. Die Vorgaben für einen Satz
mit Regeln (Stoppwörter, Schwellen, Restgruppe) stehen in `shopCategoryRules()`.

## Ansicht „Sortiment“

Route `shop-assortment` (`/shop/sortiment`), im Shop-Menü und als Karte im
Shop-Überblick. Lesen mit `shop_part_edit` oder `edit_shop_config`, ändern nur
mit `edit_shop_config`.

- Gruppen: anlegen, umbenennen, verschieben, löschen. Ein Klick filtert die
  Kategorien.
- Kategorien: anlegen, umbenennen, Gruppe und Topseller setzen, löschen. Die
  Spalte „Artikel“ zeigt angebotene und zugeordnete Artikel.
- Ein neuer Name für eine Kategorie mit Artikeln wird vor dem Speichern
  angekündigt: er ändert die Adresse der Kategorieseite.

API in `backend/api/shop/assortment.php`: `getShopAssortment`,
`saveShopCategoryGroup`, `deleteShopCategoryGroup`, `saveShopCategoryGroupOrder`,
`saveShopCategory`, `deleteShopCategory`. Jede Aktion eine Abfrage; die Antwort
trägt die geschriebene Zeile.

In der **Artikelkarte** ist die Kategorie eine Auswahlliste aus dem Sortiment,
mit der Gruppe als Unterzeile und einem Knopf, der das Sortiment in einem neuen
Reiter öffnet. `getPartShopData` liefert die Liste mit (`categories`),
`savePartShopData` nimmt `category_id`.

## Was auf der Webseite ankommt

Trigger in `company_schema.sql`, nur bei `shop_auto_publish` des HugoShops:

| Änderung | Folge |
| --- | --- |
| Kategorie umbenannt | `publish_part` für jeden ihrer Artikel (Name und Adresse stehen im Front Matter); der Lauf schreibt danach auch die Übersicht |
| jede andere Änderung an `category_shop` oder `category_group_shop` | ein Auftrag `write_categories` je HugoShop |
| andere Kategorie am Artikel | `publish_part` (Trigger auf `parts_ext`) |

`write_categories` schreibt nur `category_groups.json`; die Übertragung an
HugoCMS erkennt die geänderte Datei und baut. Die Trigger auf dem Sortiment
laufen je Zeile, nicht je Anweisung: die Übernahme bei jedem Upstall soll ohne
betroffene Zeile keinen Auftrag anlegen.

## Geprüft

Auf einem Wegwerf-Cluster (PostgreSQL 14) mit Platzhaltertabellen, der echte
Text aus `company_schema.sql`: Übernahme zweier Schreibweisen derselben
Kategorie als eine, wiederholtes Upstall ohne Auftrag, `write_categories` nach
Gruppenänderung, `publish_part` nach Umbenennen und nach Wechsel der Kategorie
am Artikel, Ablehnung doppelter Namen, Löschschutz, Gruppe löschen. Dazu die
Abfragen der neuen API und der Werkzeugabgleich (Kategorie anlegen, beim
zweiten Lauf wiederfinden). Die Gruppierung in PHP mit Beispieldaten für beide
Wege. **Nicht geprüft:** Upstall auf einer echten Mandantendatenbank und die
Ansicht im Browser.
