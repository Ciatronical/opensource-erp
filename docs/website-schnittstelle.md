# Website-Schnittstelle: Feature-Katalog nach opensourceerp.dev

Stand 03.10.2026. Die Dokumentation im System (`docs/features/*.md`) ist die
einzige Quelle für die Feature-Liste. Jede Seite trägt Front Matter; daraus
baut OpensourceERP einen maschinenlesbaren **Feature-Katalog** und schickt
ihn auf Knopfdruck (Dokumentation → „Auf der Website veröffentlichen", nur
Systemadministratoren) an die Website. Die Website rendert daraus ihre
Feature-Seiten.

## Front Matter einer Doku-Seite

```yaml
---
title: Spezialwerkzeug                       # Pflicht
summary: Werkzeuge einlagern, per KI …       # Pflicht, ein Satz
group: extension                             # core | extension
extension: lxcars                            # nur bei group: extension — Name aus backend/upstall/<name>/extension.json
category: Werkstatt                          # Rubrik auf der Website (Stammdaten, Verkauf, Finanzen, Lager, Kommunikation, Organisation, KI, Kamera, System, Werkstatt …)
order: 101                                   # Sortierung innerhalb der Gruppe
status: stable                               # stable | beta | planned
since: 2026-10-03                            # optional
external: true                               # optional: braucht Drittanbieter-Zugang
flag: feature_anpr                           # optional: eigener Schalter in der Firmenkonfiguration
kind: feature                                # feature | overview
keywords: [Werkzeug, Verleih, KBA]           # optional
---
```

Regeln: Jede neue Funktion wird in der passenden Seite beschrieben **und** in
`alle-features.md` als Punkt aufgenommen. Funktionen, die nur mit einer
Erweiterung erscheinen, gehören zu einer Seite mit `group: extension` und
dem Namen der Erweiterung — die Website zeigt sie unter *Erweiterungen /
LxCars* bzw. *Erweiterungen / Shop*.

## Übertragung

```
POST <website_publish_url>
Authorization: Bearer <website_publish_token>
Content-Type: application/json
X-OSERP-Version: <git describe>
```

Adresse und Schlüssel stehen in `defaults_oserp` (`website_publish_url`,
`website_publish_token`) und werden im Dialog gepflegt. Die Website antwortet
mit HTTP 2xx und JSON:

```json
{ "success": true, "message": "24 Seiten übernommen" }
```

Jede andere Antwort gilt als Fehler und wird im Dialog angezeigt. Die
Übertragung ist vollständig und idempotent: Der Katalog enthält immer alle
Seiten; Seiten, die nicht mehr im Katalog stehen, darf die Website entfernen.
Schlüssel je Seite ist `slug`.

Vorschau und Export derselben Daten ohne Übertragung: Aktion `getDocsCatalog`
(API) bzw. „JSON herunterladen" im Dialog.

## Nutzlast

```json
{
  "schema": "opensource-erp.feature-catalog/1",
  "generated_at": "2026-10-03T16:20:00+02:00",
  "version": "fd5b29b",
  "source": "docs/features",
  "extensions": [
    { "name": "lxcars", "title": "LxCars", "icon": "mdi-car", "description": "Werkstattverwaltung für Kfz-Betriebe …" },
    { "name": "shop",   "title": "Shop",   "icon": "mdi-storefront", "description": "Webshop-Anbindung …" }
  ],
  "pages": [
    {
      "slug": "spezialwerkzeug",
      "title": "Spezialwerkzeug",
      "summary": "Spezialwerkzeuge einlagern, per KI den passenden Fahrzeugen zuordnen, im Shop verleihen und verkaufen",
      "group": "extension",
      "extension": "lxcars",
      "category": "Werkstatt",
      "order": 101,
      "status": "stable",
      "since": "2026-10-03",
      "external": false,
      "flag": null,
      "kind": "feature",
      "keywords": [],
      "updated": "2026-10-03 16:10:00",
      "sections": [
        {
          "level": 2,
          "title": "Zuordnung durch die KI",
          "anchor": "zuordnung-durch-die-ki",
          "text": "Die KI bekommt die Werkzeugdaten und ein Profil des eigenen Fahrzeugbestands …",
          "items": ["Ein Zahnriemenwerkzeug für VW 1.9 TDI Pumpe-Düse …", "…"],
          "tags": []
        }
      ],
      "markdown": "# Spezialwerkzeug — einlagern, zuordnen, verleihen\n\n…"
    }
  ]
}
```

| Feld | Bedeutung |
| --- | --- |
| `pages[].group` | `core` = Kernsystem, `extension` = nur mit Erweiterung `pages[].extension` |
| `pages[].kind` | `overview` ist die Gesamtübersicht `alle-features.md`: ihre `sections` sind die vollständige, feingranulare Feature-Liste (Überschrift je Feature, `items` je Einzelfunktion, `tags` wie `LxCars`, `Shop`, `extern`, `Schalter …`) |
| `pages[].sections` | Überschriften mit erstem Absatz und Aufzählungspunkten — für Teaser, Suche und Anker |
| `pages[].markdown` | der vollständige Text ohne Front Matter, Links relativ (`lxcars.md`, `../dhl-setup.md`) |

Empfehlung für die Website: Feature-Übersicht aus `kind: overview` (Teil A
Kernsystem, Teil B/C Erweiterungen), je Seite eine Unterseite aus `markdown`,
Navigation aus `group`/`extension`/`category`/`order`.

## Über OSERP (`about`)

Seit 25.10.2026 trägt der Katalog zusätzlich die Seite **Über OSERP** —
Entstehung, Grundsätze, Dank, Team, Mitmachen. Quelle sind dieselben Dateien,
aus denen OSERP die Seite baut: Aufbau, Namen und Links in
`src/core/views/about/about.data.json`, die Texte je Sprache in
`src/core/views/about/locales/<sprache>.json` (Schlüssel `AboutView`). Fehlt
die Datei, ist `about` `null`.

```json
"about": {
  "history": ["roots", "motivation", "ai", "compatible", "today"],
  "values": [{ "key": "free", "icon": "mdi-handshake-outline" }, "…"],
  "thanks": [
    { "text": "accountants", "icon": "mdi-calculator-variant-outline", "people": ["Yvonne A.", "Gerda", "Olaf Stechow"] },
    { "text": "accountantsDetail" },
    { "text": "vehicleScanner", "extension": "lxcars", "link": "https://www.fahrzeugschein-scanner.de", "icon": "…", "people": ["…"] },
    { "text": "switzerland", "link": "https://revampit.ch" }
  ],
  "team": ["Ronny Zimmermann", "Dirk Schwartzer", "Martin Willig"],
  "contribution_ways": [{ "key": "code", "icon": "mdi-code-tags" }, "…"],
  "links": { "github": "…", "website": "https://opensourceerp.dev", "demo": "https://demo.opensourceerp.dev/" },
  "texts": {
    "de": { "title": "Über OSERP", "subtitle": "…", "history": { "title": "…", "roots": "…" }, "thanks": { "…": "…" }, "…": "…" },
    "en": { "…": "…" }
  }
}
```

So setzt die Website die Seite zusammen (wie `about.view.vue`):

| Abschnitt | Überschrift | Inhalt |
| --- | --- | --- |
| Entstehung | `texts.<l>.history.title` | je Schlüssel aus `history` ein Absatz `texts.<l>.history.<schlüssel>` |
| Grundsätze | `values.title` | je Eintrag aus `values`: `values.<key>.title` + `values.<key>.text`, Symbol `icon` (Material Design Icons) |
| Dank | `thanks.title` | je Block aus `thanks`: Absatz `thanks.<text>`, danach Link `link` (Linktext = Rechnername ohne `www.`), danach die Namen `people` |
| Entwicklung | `team.title` | Absatz `team.text`, danach die Namen `team` |
| Mitmachen | `contribute.title` | `contribute.intro`, je Eintrag aus `contribution_ways` Titel + Text unter `contribute.ways.<key>`, dann `contribute.howto`, Knöpfe aus `links` (Beschriftung `contribute.github` / `website` / `demo`), Schlusssatz `contribute.closing` |

- Ein Block mit `extension` gehört zu einer Erweiterung. In OSERP erscheint
  er nur, wenn sie aktiv ist; die Website zeigt ihn immer und darf ihn als
  Teil der Erweiterung kennzeichnen.
- Nicht jede Sprache hat jeden Schlüssel sofort: fehlt einer, Englisch
  nehmen, dann Deutsch (wie OSERP).
- `contribute.docs` (Knopf zur Dokumentation in OSERP) ist für die Website
  nicht gedacht.

**Suchmaschinen:** Die Website ist die einzige Fassung, die in Suchmaschinen
gehört. OSERP trägt `noindex, nofollow` in `index.html` — für alle
Installationen und die Demo —, damit die öffentliche Seite `/ueber` nicht mit
der Website um denselben Inhalt konkurriert.
