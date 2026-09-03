# Umbau: Features → Erweiterungen (extensions), mehrfach aktivierbar

## Ziel

Zwei Begriffe sauber trennen:

- **Erweiterung** (`extension`): eigenständiges Modul mit eigenem Schema unter
  `backend/upstall/<name>/`, eigenem Backend unter `backend/api/<name>/` und
  eigenem Frontend unter `src/features/<name>/`. Beispiel: LxCars.
  Mehrere Erweiterungen sind gleichzeitig aktivierbar.
- **Feature**: einfacher Ein-/Aus-Schalter innerhalb bestehender Funktionen.
  Beispiele: `feature_anpr`, `feature_nvr`, `feature_datev`, `feature_ustva`.
  Bleiben unverändert.

Code-Bezeichner englisch (`extension`), Oberfläche deutsch („Erweiterung“).

## Ausgangslage

- Speicherort war `defaults_oserp.key = 'features'` — ein einzelner Textwert.
- Die Tabelle `features_oserp` existierte im Schema, wurde aber nie benutzt.
- Drei verschiedene Prüfmuster im Backend: `str_contains`, client-geliefertes
  `in_array` (wirkungslos, toter Code) und `preg_match` auf einen Einzelnamen.
- `backend/api/features.php` war tot: die gesetzte Variable `$lxCars` wurde in
  keinem der vier einbindenden Aufrufer verwendet.

## Schritt 1 — Datenbankschema

`backend/upstall/crm/company_schema.sql`: `features_oserp` durch
`extensions_oserp` ersetzen (Spalten `extension`, `active`, `itime`, `mtime`),
Unique-Index auf `extension`, danach Migration des Altbestands:

    INSERT INTO extensions_oserp (extension, active)
    SELECT trim(value), true FROM defaults_oserp
    WHERE key = 'features' AND trim(COALESCE(value, '')) <> ''
    ON CONFLICT (extension) DO NOTHING;

    DELETE FROM defaults_oserp WHERE key = 'features';
    DROP TABLE IF EXISTS features_oserp;

Reihenfolge ist zwingend: `CREATE TABLE` verarbeitet der Parser vorab separat,
alle übrigen Statements laufen sequenziell — der Unique-Index muss vor dem
`INSERT ... ON CONFLICT` stehen. Alle Migrationsstatements sind idempotent.
Ein Fehler löst Rollback der gesamten Datei aus, daher `IF EXISTS`/`ON CONFLICT`.

## Schritt 2 — Zentraler Helper

Neu `backend/api/lib/extensions.php` mit `getActiveExtensions($db): array` und
`isExtensionActive($db, $name): bool`. Eingebunden in `backend/api/inc.php`
nach `database.php`, damit überall verfügbar. Exakter Vergleich statt
`str_contains` — beseitigt die Präfix-Falle (`cars` in `lxcars`).

## Schritt 3 — Backend-Aufrufstellen

| Datei | Änderung |
|---|---|
| `customer_vendor.php` | `isExtensionActive($mandant, 'lxcars')` |
| `filemanager.php` (2x) | `isExtensionActive($db, 'lxcars')` |
| `print.php` | Rumpf von `isLxCarsEnabled()` umstellen, Name bleibt |
| `features.php` | gelöscht |
| `faktura.php`, `parts.php`, `crm_defaults.php`, `customer_vendor.php` | die vier `include_once .../features.php` entfernt |
| `global_search.php` | liest Erweiterungen serverseitig statt aus dem Request |
| `global-search.component.vue` | schickt kein `features`-Array mehr |

Danach entscheidet ausschließlich der Server über Sichtbarkeit.

## Schritt 4 — Session- und Config-Queries

Drei `json_agg`-Blöcke (`oserp_config/defaults.php`, `customer_vendor.php` 2x)
liefern jetzt ein flaches Array aus `extensions_oserp`:

    'extensions', (
        SELECT COALESCE(json_agg(extension ORDER BY extension), '[]'::json)
        FROM extensions_oserp WHERE active
    )

## Schritt 5 — update.php

`$featureDirs` → `$extensionDirs`, gefüllt per Schleife über
`getActiveExtensions()`. Die `preg_match`-Prüfung bleibt und wandert auf den
Einzelnamen — dort gehört sie hin, da der Name Pfadbestandteil ist.
Antwortfelder: `processed_features` → `processed_extensions`,
`client.features` → `client.extensions` (Frontend in
`schema-update.component.vue` und `update.view.vue` nachgezogen).

Reihenfolge deterministisch: `crm` zuerst, danach alphabetisch. Für
Abhängigkeiten zwischen Erweiterungen bräuchte es später eine explizite Angabe.

## Schritt 6 — Store

`features` → `extensions`, `isFeatureEnabled()` → `isExtensionEnabled()`.
`isLxCars()` bleibt bestehen und wird nur intern umgestellt — damit bleiben
31 der 33 Aufrufstellen unangetastet. Der Ajax-Parameter `features:` entfällt.

## Schritt 7 — Konfigurationsoberfläche

- Auswahl aus `crmDefaultsConfig.js` entfernt (schrieb nach `defaults_oserp`)
- Neuer Abschnitt „Erweiterungen“ oben in `features.tab.vue`, darunter
  unverändert die Feature-Schalter — die Trennung wird optisch sichtbar
- Katalog der verfügbaren Erweiterungen aus dem Dateisystem: jede Erweiterung
  bekommt `backend/upstall/<name>/extension.json` mit Name, Titel, Icon und
  Beschreibung; Action `getAvailableExtensions` liest sie ein. Damit entfällt
  beim Anlegen einer neuen Erweiterung der Eintrag in einer Auswahlliste.
- Action `saveExtensions` als eigener Speicherpfad
- `client-defaults.view.vue`: Mengenvergleich statt String-Vergleich,
  `applyFeatureChange()` ruft `saveExtensions` statt `saveConfig`

## Schritt 8 — i18n (21 Sprachen)

`crm_fields.features` → Namensraum `extensions`; `featureChange.*` →
`extensionChange.*` mit Mehrzahl-Texten; `update`-Locales `features` →
`extensions`. Unverändert bleiben: der Tab-Titel „Features“,
`configGroups.features`, `overviewDesc.features` sowie alle
`featureAnpr*`/`featureNvr*`/`featureDatev`/`featureUstva`-Keys.

## Schritt 9 — Prüfen

1. `npm run check:api` (neue Actions brauchen `@testdata`)
2. Schema-Update mit `"dry_run": true` gegen die lokale Datenbank
3. `SELECT * FROM extensions_oserp;` enthält `lxcars`; `defaults_oserp` hat den
   Schlüssel `features` nicht mehr
4. Zwei Erweiterungen gleichzeitig aktivieren, Log auf beide Verzeichnisse prüfen
5. Anmeldung gegen einen noch nicht migrierten Mandanten — muss dank Fallback
   ohne das Schema-Notfallupdate aus `login.view.vue` durchlaufen

## Risiken

- **Migrationsfenster**: entschärft. Ein Sicherheitsnetz gibt es bereits:
  `login.view.vue` erkennt über `isSchemaMismatchError` die SQLSTATEs 42703 und
  42P01, ruft `updateAllDatabases` auf und wiederholt die Anmeldung einmalig.
  Das hätte auch ohne Fallback geheilt — allerdings erst, nachdem alle
  laufenden Sitzungen auf der Anmeldeseite gelandet wären und der erste
  Benutzer ein Update sämtlicher Mandanten-Datenbanken samt Backups angestoßen
  hätte. Innerhalb einer laufenden Sitzung greift das Netz nicht: Druck, Suche
  und Kundenwechsel liefen bis dahin auf Datenbankfehler. Der Rückfall auf
  `defaults_oserp` vermeidet die Störung ganz.
- **Deaktivieren löscht nichts**: upstall entfernt keine Tabellen. Eine
  abgeschaltete Erweiterung lässt Daten stehen, Wiedereinschalten stellt den
  alten Zustand her. Gewollt, gehört in den Bestätigungsdialog.

## Rollout auf bestehende Installationen

`getCV()` bedient Anmeldung, Sitzungswiederherstellung und Mandantenwechsel
(`auth.php` Zeilen 189, 278, 359). Stünde dort ein fester Bezug auf
`extensions_oserp`, wäre jeder Client zwischen Code-Deploy und Schema-Update
komplett unbenutzbar — die Abfrage scheitert schon beim Parsen, nicht erst bei
der Ausführung. Deshalb ist der neue Code mit beiden Schemazuständen lauffähig:

- `extensionsSelectSql($db, $asJson)` liefert je nach Migrationsstand einen
  Ausdruck auf `extensions_oserp` oder auf den alten Schlüssel in
  `defaults_oserp`. Der Tabellenname taucht nur auf, wenn es die Tabelle gibt.
- `hasExtensionsTable()` prüft per `to_regclass` und **ohne Zwischenspeicher**
  gegen die jeweils übergebene Verbindung — `updateAllDatabases()` arbeitet in
  einem Durchlauf mit mehreren, unterschiedlich weit migrierten Mandanten-DBs.
  (`existingTables()` aus `database.php` wäre hier falsch: dessen statischer
  Cache gilt prozessweit, nicht pro Verbindung.)
- Die Session-Abfragen liefern neben `extensions` übergangsweise auch den alten
  Schlüssel `features`. Damit sehen bereits geladene Frontend-Bundles LxCars
  weiterhin, bis der Benutzer die Seite neu lädt.
- `saveExtensions` meldet `SCHEMA_UPDATE_REQUIRED` statt eines Datenbankfehlers,
  falls jemand vor dem ersten Schema-Update Erweiterungen umschalten will.

Damit ist die Reihenfolge des Rollouts gleichgültig. Was bleibt: nach dem
Deploy sind die gehashten Lazy-Chunks der alten Version weg, sodass Navigation
in noch nicht geladene Ansichten abbricht — das übliche Deploy-Verhalten, ein
Neuladen behebt es.

Die Übergangsteile (Fallback, Doppelschlüssel `features`) können entfernt
werden, sobald alle Installationen das Schema-Update hatten.

## Stand der Umsetzung

Schritte 1–8 sind umgesetzt. Geprüft:

- `npm run check:api` — 166 Dateien, keine Beanstandungen
- `npm run build` — läuft durch
- `php -l` auf allen geänderten PHP-Dateien
- alle 63 geänderten Locale-Dateien sind gültiges JSON, Formatierung unverändert

Offen (Schritt 9), weil kein Datenbankzugang bestand:

1. Schema-Update mit `"dry_run": true`, danach scharf
2. `SELECT * FROM extensions_oserp;` muss `lxcars` enthalten, `defaults_oserp`
   den Schlüssel `features` nicht mehr
3. Zwei Erweiterungen gleichzeitig aktivieren und das Log prüfen
4. Anmeldung gegen einen noch nicht migrierten Mandanten — muss dank Fallback
   ohne das Schema-Notfallupdate aus `login.view.vue` durchlaufen

Die SQL-Logik von `saveExtensions` lässt sich vorab ohne Datenänderung prüfen:
das Skript `test-saveextensions.sql` legt temporäre Tabellen an und rollt am
Ende zurück (`psql -d <company-db> -f test-saveextensions.sql`).
