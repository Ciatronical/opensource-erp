# Shop: Freigaben für den HugoShop in HugoCMS festlegen

Stand 2026-10-09. Status: **umgesetzt** in OpensourceERP und HugoCMS
(`/home/worker/Projekte/hugocms-2026`, eigene Richtlinien: `CLAUDE.md` dort —
„Mount-Sicherheit ist die Grenze“), Entscheidungen E1–E5 wie vorgeschlagen.
Getestet mit Wegwerf-Skripten und lesend gegen die lokale Testumgebung, noch
nicht mit einem vollständigen Lauf gegen eine echte Webseite.

## Ziel

Wohin die Shop-Anbindung schreibt und woraus HugoCMS liest, legt **HugoCMS**
fest — der Ort, dem die Webseite gehört —, nicht OpensourceERP:

- HugoCMS bekommt eine **Shop-Erweiterung**, je Webseite einschaltbar, kein
  Pro-Merkmal. Erst mit ihr gibt es den Abschnitt „Shop-Anbindung“ in der
  Projektkonfiguration und die `shop*`-Befehle der API.
- In der Projektkonfiguration legt ein Administrator die **Freigaben** für den
  HugoShop fest: Verzeichnis der Produktseiten, Kategorieübersicht,
  Produktbilder, Vorschaubilder.
- OpensourceERP fragt sie in der Kanalkarte auf Knopfdruck ab und zeigt sie
  **nur lesend**; der Lauf nimmt sie bei jeder Übertragung aus HugoCMS.
- Vorher tragen Benutzer die Schlüssel in beiden Systemen ein (Shop-Anbindung
  von HugoCMS in OSERP, Signaturschlüssel von OSERP in HugoCMS).

## Warum dieser Weg besser ist als ein Browser in OSERP

- **Die Grenze bleibt bei HugoCMS.** Ein Administrator von HugoCMS entscheidet,
  wohin die Anbindung schreiben darf; der Schlüssel der Anbindung kann das nicht
  ausweiten. Die Mounts mit „Alle Dateien“ oder Pfaden außerhalb der Webseite
  sind damit kein Thema — sie werden gar nicht angeboten.
- **Eine Quelle, kein Widerspruch.** Heute kann `content_dir` in OSERP von
  `[shop] areas` in HugoCMS abweichen (Fall Autoprofis). Gibt es den Wert nur
  noch in HugoCMS, kann nichts mehr auseinanderlaufen.
- **Weniger API.** Ein Verzeichnisbrowser für OSERP bräuchte neue Befehle, die
  die Ordnerstruktur der Webseite an den Schlüssel herausgeben. Hier genügt der
  vorhandene Status (`shopbuildstatus`), der die Freigaben ohnehin meldet.
- **Kleinere Angriffsfläche.** Ohne eingeschaltete Shop-Erweiterung antworten
  die `shop*`-Befehle gar nicht.
- **Pflege an einer Stelle** statt einer INI-Pfadliste (`[shop] areas`): ein
  Formular mit Verzeichnisauswahl in der Projektkonfiguration.

## Entwurf

### HugoCMS

- **Schalter** „Shop-Erweiterung“ je Webseite, in der Projektkonfiguration, nur
  für Administratoren (`config.manage`). Gespeichert in der Mount-Datei,
  `[shop] enabled = true`. Ausgeschaltet:
  - alle `shop*`-Befehle antworten mit `SHOP-DISABLED`, ohne den Schlüssel zu
    prüfen;
  - in der Projektkonfiguration steht nur der Schalter;
  - in der Liste der Fähigkeiten (wie `git`, `audit`) steht `shop: false`.
- **Freigaben** im Abschnitt Shop-Anbindung, jeweils mit Verzeichnisauswahl
  innerhalb der Hugo-Quelle (vorhandener `DirectoryBrowser`, auf die Quelle
  begrenzt):

  | Freigabe | Vorgabe | HugoCMS | Anbindung darf |
  | --- | --- | --- | --- |
  | Produktseiten | `content/de/produkt/` | — | `.md` schreiben, löschen |
  | Kategorieübersicht | `data/category_groups.json` | — | diese eine Datei schreiben |
  | Produktbilder | `static/images/products/` | liest sie für die Vorschaubilder | — |
  | Vorschaubilder | `static/images/thumbnails/` | schreibt sie | — |
  | Paket | `oserp-shop/` (fest, nicht wählbar) | — | `md, json, html, js, css`, PHP nur signiert |

  Gespeichert unter `[shop]` (`content_dir`, `category_groups`, `images`,
  `thumbnails`); `[shop] areas` entfällt.
- **`ShopSync::allowedPath()`** prüft gegen genau diese Freigaben (je mit den
  passenden Endungen) statt gegen `[shop] areas`.
- **`shopbuildstatus`** meldet die Freigaben (ersetzt `areas`).
- **Bestehende Webseiten:** Ist ein Schlüssel hinterlegt, gilt die Erweiterung
  als eingeschaltet (E2), damit laufende Shops nicht stehen bleiben.

### OpensourceERP

- **Kanalkarte**, Kasten HugoCMS: Knopf „Verbindung prüfen und Freigaben
  abrufen“. Er zeigt die Freigaben nur lesend an. Das Feld „Verzeichnis der
  Inhaltsdateien“ entfällt (E3).
- **Lauf:** holt die Freigaben zu Beginn jedes HugoShops aus `shopbuildstatus`
  und legt Seiten und Kategorieübersicht in der Bereitstellung genau dort ab.
  Ist HugoCMS nicht erreichbar oder die Erweiterung aus, gilt der Kanal als
  nicht eingerichtet — die Aufträge bleiben ausgesetzt, statt in ein falsches
  Verzeichnis zu schreiben.
- **Adressmuster:** Aus den Freigaben lässt sich ein Vorschlag für
  `products_link`, `images_link` und `thumbnails_link` machen (`content/de/produkt`
  → `/produkt/%s/`). Weil Hugo die Adressen auch über Sprachen und Permalinks
  bilden kann, bleibt es ein Vorschlag mit Warnung bei Abweichung (E5).
- Die bisherigen Meldungen zu `[shop] areas` (Lauf, Verbindungstest)
  entfallen bzw. nennen die Freigaben.

### Wechsel eines Verzeichnisses

Ändert ein Administrator in HugoCMS das Verzeichnis der Produktseiten, schreibt
der nächste Lauf alle Seiten am neuen Ort. Die alten liegen außerhalb der neuen
Freigabe; HugoCMS löscht sie trotzdem, weil sie in der vorigen Lieferung standen
und OSERP sie selbst geliefert hat (`last-manifest.json`, E4). Sonst blieben
verwaiste Seiten mit alten Preisen veröffentlicht.

## Entscheidungen (2026-10-09, wie vorgeschlagen)

| Nr. | Frage | Entscheidung |
| --- | --- | --- |
| E1 | Die vier Freigaben oben, Paket `oserp-shop/` fest | ja |
| E2 | Bestehende Webseiten mit Schlüssel | gelten als eingeschaltet; neue Webseiten erst nach dem Einschalten |
| E3 | `content_dir` in OSERP | entfällt ganz (Kanalschlüssel und Feld, Upstall räumt auf — **Schemaänderung, Freigabe nötig**); OSERP hält keine eigene Kopie der Freigaben |
| E4 | Alte Seiten nach einem Verzeichniswechsel | löschen, sofern OSERP sie selbst geliefert hat |
| E5 | Adressmuster aus den Freigaben | Vorschlag auf Knopfdruck, Warnung bei Abweichung; bleiben in OSERP einstellbar |

## Umsetzung

| Teil | Wo |
| --- | --- |
| HugoCMS: Schalter, Freigaben lesen und prüfen | `backend/core/MountConfig.php` (`[shop] enabled`, `SHOP_GRANTS`, `shopSection()`, `shopGrantPath()`) |
| HugoCMS: Schreibgrenze | `backend/core/Shop/ShopSync.php` (`allowedPath()` je Freigabe, `deletablePath()` für E4, `PACKAGE_DIR`) |
| HugoCMS: Befehle | `backend/core/Connector.php` (`SHOP-DISABLED` in `requireShopKey()`, `grants` in `shopbuildstatus`, `shopsettingsset`, `shopbrowsedirs`, `writeShopSection()`, `shop` in `whoami` und `projectconfig`) |
| HugoCMS: Oberfläche | `ProjectSettingsDialog.vue` (Schalter, Freigaben mit Verzeichnisauswahl), `DirectoryPickerDialog.vue` (`command`), `StatusView.vue`, `stores/auth.js`, `i18n/de.js`, `i18n/en.js`; README, `mounts.ini.beispiel` |
| OSERP: Freigaben im Lauf | `lib/hugocms.php` (`shopHugoCmsGrantsFrom`, `shopHugoCmsGrants`, `shopHugoCmsGrantsRequired`, Abgleich nach Freigaben), `lib/publish.php` (`shopContentDir`, `shopStagingFollowGrants`, Prüfung in `shopRunJobs` und `shopPublishRun`), `lib/categories.php` (`shopCategoryGroupsTemplatePath`, `shopCategoryGroupsFile`) |
| OSERP: Kanalkarte | `testShopHugoCms` (`grants_error`, `template_category_groups`), `shop-channel-settings.vue` (Freigaben, Adressmuster-Vorschlag), `shopChannelSettingsConfig.js`; Texte in 21 Sprachen |
| OSERP: Schema | `company_schema.sql`: `content_dir` aus `shop_channel_default_settings()` und `shop_channel_setting_keys()`, Aufräumen der Kanäle und von `shop_content_dir` |
| OSERP: Werkzeug | `tools/shop-bridge-settings.php` nennt den Inhaltsordner der Bridge nur noch als Hinweis für HugoCMS |

Einzelheiten, die beim Umsetzen dazukamen:

- **Unbrauchbare Freigabe** (von Hand in der Mount-Datei, etwa absolut oder mit
  `..`): HugoCMS gibt dort nichts frei und meldet sie nach der Anmeldung
  (`SHOP-GRANT-UNUSABLE`), statt still die Vorgabe zu nehmen. OSERP setzt die
  Aufträge aus und meldet einen Fehler.
- **Zeichen** einer Freigabe: Buchstaben, Ziffern, `- _ .` und Leerzeichen —
  die Mount-Datei setzt Werte in Anführungszeichen.
- **Produkt- und Vorschaubilder** dürfen nicht im selben Verzeichnis liegen:
  Ein Vorschaubild trägt den Namen seines Produktbilds und überschriebe es.
- **Schalter beim Schreiben**: Jede Änderung der `[shop]`-Sektion schreibt
  `enabled` ausdrücklich. Sonst hinge der Schalter weiter am Schlüssel (E2),
  und wer den Schlüssel entfernt, schaltete die Erweiterung ungewollt mit aus.
- **Bereitstellung folgt den Freigaben** (`shopStagingFollowGrants`): Die
  Seiten aller Artikel ziehen in der Bereitstellung an den neuen Ort, nicht
  nur die der geänderten — sonst stünden am neuen Ort nur wenige Seiten.
- **Kategorieübersicht und Vorlagensatz**: Ob es eine Übersicht gibt, sagt
  weiter der Vorlagensatz (`theme.json`, `data.category_groups`); wohin sie
  kommt, die Freigabe. Weichen beide ab, finden die Vorlagen sie nicht — Lauf
  und Verbindungstest warnen.
- **Abgeschalteter Kanal**: Scheitern die Freigaben, bleiben auch die
  Aufträge eines abgeschalteten HugoShops ausgesetzt. Sonst gälte ein
  `remove_all` als erledigt, ohne dass HugoCMS die Seiten entfernt hätte.

## Übergang

- HugoCMS: Webseiten mit Schlüssel gelten als eingeschaltet (E2). Steht in der
  Mount-Datei noch `[shop] areas`, wird es nicht mehr ausgewertet; nach der
  Anmeldung erscheint ein Hinweis (`SHOP-AREAS-OBSOLETE`), das Speichern der
  Freigaben entfernt den Eintrag. **Wer `areas` von Hand angepasst hatte, muss
  die Freigaben einmal in den Projekteinstellungen setzen** — bis dahin gelten
  die Vorgaben (`content/de/produkt`, `data/category_groups.json`).
- OSERP: Das Schema-Update entfernt `content_dir` aus den Kanälen. Hatte ein
  Kanal ein anderes Verzeichnis als die Vorgabe, gehört es jetzt als Freigabe
  „Produktseiten“ in HugoCMS; die Bereitstellung zieht beim nächsten Lauf
  nach.
- Reihenfolge der Aktualisierung: beliebig. Ein neues OSERP gegen ein älteres
  HugoCMS setzt die Aufträge aus („HugoCMS meldet keine Freigaben — bitte
  aktualisieren“). Ein älteres OSERP gegen ein neues HugoCMS liest weiter
  `areas`; HugoCMS meldet sie übergangsweise, abgeleitet aus den Freigaben.
  Ohne sie ließe das ältere OSERP jede Datei aus, und die Übernahme löschte die
  vorige Lieferung. Das gilt, solange das Verzeichnis der Produktseiten in OSERP
  und die Freigabe in HugoCMS übereinstimmen — wer beides abweichend von der
  Vorgabe eingestellt hatte, setzt die Freigabe gleich nach dem Update von
  HugoCMS.

## Geprüft

- HugoCMS, Wegwerf-Skript gegen den Autoloader: Schalter mit und ohne
  Schlüssel (E2), unbrauchbare und normalisierte Freigaben, gleiche
  Bildverzeichnisse, Hinweis zu `areas`; Abgleich je Freigabe (Markdown im
  Seitenverzeichnis, genau die Kategoriedatei, Paket, kein PHP ohne Signatur,
  `..`, Präfix ohne Schrägstrich); Verlegung der Produktseiten mit Löschen am
  alten Ort (E4), von Hand angelegte Seite bleibt; signiertes PHP weiter
  angenommen und nie gelöscht. `npm run --prefix frontend build`.
- OSERP, Wegwerf-Skript: Freigaben lesen (gültig, Fehler von HugoCMS, ältere
  Version, unbrauchbar), Abgleich nach Freigaben, Nachziehen der
  Bereitstellung (Seiten, Kategorieübersicht, leere Verzeichnisse, neuere
  Datei am Ziel bleibt). Schema-Anweisungen in einer zurückgerollten
  Transaktion. Lesend gegen die lokale Testumgebung: HugoCMS meldet die
  Freigaben, OSERP legt Seiten und Übersicht danach ab. `vite build`.
