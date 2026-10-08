# Upstall: offene Punkte

Stand: 08.10.2026. Ergebnis einer Prüfung des Datenbank-Updates über
`backend/upstall/` (Prüfsumme bei Anmeldung und Firmenwechsel, Einzellauf,
Lauf über alle Firmen, `tools/oserp-upstall.php`).

Bereits behoben:

- Ein fehlgeschlagenes Update bei der Anmeldung hielt den Benutzer auf der
  Anmeldeseite fest. Jetzt erscheint ein Fehlerdialog, danach geht es mit dem
  alten Stand weiter (`src/core/views/login/login.view.vue`).
- Ob eine SQL-Datei in die Auth-Datenbank gehört, wurde am vollständigen Pfad
  entschieden. Jetzt nur noch am Dateinamen (`updateDatabaseSchema()` in
  `backend/api/update/update.php`).

Die folgenden Punkte sind offen.

---

## 1. Auth-Schemas von Erweiterungen werden unbemerkt übersprungen

**Derzeit ohne Auswirkung:** keine Erweiterung hat ein `auth_schema.sql`.
Sobald eine eines bekommt, greifen beide Fehler.

### a) Lauf über alle Firmen ignoriert Fehler

`_updateAllDatabasesLocked()` in `backend/api/update/update.php`: scheitert das
`auth_schema.sql` einer Erweiterung, wird das nur ins Log geschrieben.
`$allResults['success']` bleibt `true`, das Ergebnis taucht im Bericht nicht
auf, und `upstallStoreChecksums()` speichert die Prüfsumme trotzdem. Danach
meldet keine Anmeldung mehr Update-Bedarf.

Außerdem läuft das Auth-Schema einer Erweiterung einmal je Mandant, der sie
aktiv hat — der Kommentar „einmalig“ stimmt nicht. Unschädlich, solange die
Datei idempotent ist, aber unnötig.

**Vorschlag:** Fehler in `$allResults['success']` und in den Bericht aufnehmen;
Prüfsumme dieses Mandanten dann nicht speichern. Auth-Schemas der
Erweiterungen vor der Mandantenschleife sammeln und je Erweiterung genau einmal
anwenden.

### b) Aktivieren einer Erweiterung lässt die Auth-Datenbank aus

`client-defaults.view.vue` ruft nach `saveExtensions` das Update mit
`auth_db: false` auf. `_updateOneDatabase()` speichert danach die Prüfsumme
des Erweiterungsverzeichnisses — und `upstallChecksum()` bezieht das
`auth_schema.sql` mit ein. Ergebnis: Die Auth-Datenbank wurde nicht
aktualisiert, die Prüfsumme sagt aber „aktuell“.

Zusätzlich sieht der Benutzer ein fehlgeschlagenes Update nicht: es landet nur
in der Browser-Konsole, danach lädt die Seite neu.

**Vorschlag:** `auth_db: false` entfernen (die Auth-Datenbank läuft ohnehin
mit, die Sperre verhindert Überschneidungen) oder in `_updateOneDatabase()`
keine Prüfsumme speichern, wenn `auth_db` ausgeschaltet war. Fehlermeldung im
Frontend anzeigen, bevor neu geladen wird.

---

## 2. Keine Rechteprüfung im Backend

`updateSchema()` und `updateAllDatabases()` prüfen keine Berechtigung,
`permit('admin')` ist auskommentiert. Folgen:

- Jeder angemeldete Benutzer kann `updateAllDatabases` aufrufen und damit alle
  Mandanten aktualisieren — auch solche, denen er nicht zugeordnet ist.
- Jeder Lauf erzeugt je Datenbank eine Sicherung per `pg_dump`. Wiederholte
  Aufrufe füllen den Datenträger.

`updateSchema` mit `client` prüft immerhin die Zuordnung zum Mandanten.

**Vorschlag:** `updateAllDatabases` auf den Systemadministrator beschränken
(`isSystemAdmin()`). Für `updateSchema` die heutige Freigabe beibehalten, weil
die Anmeldung jedes Benutzers das Update auslösen können muss — aber nur, wenn
`upstallUpdateNeeded()` tatsächlich Bedarf meldet. Dann gibt es keine
beliebigen Läufe mit Sicherung auf Knopfdruck.

---

## 3. Geänderte CSV-Daten erreichen bestehende Firmen nie

`updateDatabaseSchema()` lädt CSV-Dateien nur in **leere** Tabellen. Für
`kba_lxcars` ist das gewollt (IDs werden von `cars_lxcars` referenziert, und
der Stammdatenschutz gilt absolut). Für reine Nachschlagetabellen dagegen
nicht:

- `hr_lohnsteuer_table.csv`, `hr_lohnsteuer_meta.csv` — jährlich neue Werte
- `blz_de.csv` — Bundesbank veröffentlicht vierteljährlich
- `zipcode_*_oserp.csv`, `firstnametogender.csv`, `country_alias_shop.csv`

Eine geänderte CSV ändert die Prüfsumme und löst damit ein Update samt
Sicherungen aus, das in bestehenden Firmen nichts importiert.

**Vorschlag:** Je Tabelle festlegen, ob sie bei Änderung neu geladen werden
darf (etwa eine Liste in der Erweiterung oder eine Namenskonvention). Für
solche Tabellen in einer Transaktion leeren und neu füllen, wenn sich die
Prüfsumme der einzelnen CSV geändert hat. Referenzierte Stammdaten wie
`kba_lxcars` bleiben ausgenommen.

---

## 4. Geänderte View-Definitionen werden nie angewendet

`identifyStatement()` erkennt `CREATE OR REPLACE VIEW` als `CREATE_VIEW`;
`updateDatabaseSchema()` überspringt es, sobald die View existiert. Eine
geänderte Definition kommt so nie in bestehenden Datenbanken an.

**Derzeit ohne Auswirkung:** die Schema-Dateien enthalten keine Views.

**Vorschlag:** `CREATE OR REPLACE VIEW` immer ausführen, nur das einfache
`CREATE VIEW` bei vorhandener View überspringen.

---

## 5. Neue Firma bekommt keine Prüfsumme

`tenant.php` (Anlage einer Firma) ruft `updateDatabaseSchema()` direkt auf,
speichert aber keine Prüfsummen. Die erste Anmeldung in der neuen Firma löst
deshalb sofort ein vollständiges Update samt Sicherung von Auth- und
Firmen-Datenbank aus.

Außerdem läuft die Anlage ohne `schemaUpdateLock()` und kann sich mit einem
gleichzeitigen Update überschneiden.

**Vorschlag:** Nach erfolgreichem Lauf `upstallStoreChecksums($newDb, ['crm'])`
aufrufen; die Einrichtung des Schemas in `schemaUpdateLock()` /
`schemaUpdateUnlock()` einschließen.

---

## 6. README veraltet

`backend/upstall/README.md`, Abschnitt „CSV-Import“ beschreibt:

- Import nur bei abweichender Zeilenanzahl
- `TRUNCATE` vor dem Import
- Import per `COPY`, Lesezugriff des PostgreSQL-Servers auf die Datei nötig

Tatsächlich: Import nur in leere Tabellen, zeilenweise per vorbereitetem
`INSERT`, keine Superuser-Rechte oder Dateizugriff des Servers nötig,
anschließend Abgleich der Sequenzen (`resyncTableSequences()`).

Auch der Abschnitt „CREATE VIEW“ sollte nach Punkt 4 angepasst werden.

---

## 7. Editor-Sicherungsdatei im Repository

`backend/upstall/crm/#company_schema.sql#` (Emacs-Autosave) ist eingecheckt.
Das Update liest sie nicht und die Prüfsumme bezieht sie nicht ein, sie gehört
aber nicht ins Repository.

**Vorschlag:** `git rm --cached` und `#*#` in `.gitignore` aufnehmen.
