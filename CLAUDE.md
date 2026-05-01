# Claude Code Richtlinien

Vollstaendige Projektrichtlinien: `dev/programmierstil-richtlinien.md` — IMMER beachten!

## Wichtigste Regeln (Kurzfassung)

### Architektur
- Logik gehoert in die **Datenbank** (SQL), nicht in PHP
- **Ein Ajax-Call, eine DB-Abfrage** pro Vorgang — in PHP wird nichts zusammengebaut
- **Kein Hardcoding** von Daten — alles kommt aus der DB
- Store (`oserpStore`) ist Single Source of Truth — keine Daten doppelt abrufen
- Variablen heissen wie die DB-Tabellen (underscore, nicht camelCase)

### Datenbankzugriff (PHP)

```php
$db = DbhCompany::begin();  // Company-Daten (99% der Fälle)
$auth = DbhAuth::begin();   // Nur für Auth/Sessions
```

**Query-Methoden mit Prepared Statements:**
```php
$db->getOne($query, $params);   // Eine Zeile
$db->getAll($query, $params);   // Mehrere Zeilen
$db->execute($query, $params);  // INSERT/UPDATE/DELETE ohne Rueckgabe
```

**NIEMALS `get()` fuer parametrisierte Queries verwenden!**
`get()` wrapped die Query intern in ein CTE mit eigenem `:data` Parameter. Named Parameters in der inneren Query kollidieren mit der CTE-Struktur. `get()` ist nur fuer Queries OHNE Platzhalter.

**IMMER Prepared Statements verwenden — kein String-Interpolation in SQL!**

### API-Antworten
```php
resultInfo(true, '', ['results' => $data]);   // Erfolg
resultInfo(false, 'ERROR_CODE', 'Nachricht'); // Fehler
```

### Funktionskommentare (PFLICHT)
```php
/**
 * Kurzbeschreibung
 *
 * @param type $data['paramName'] Beschreibung
 * @testdata {"paramName": "beispielwert"}
 */
```
`@testdata` ist Pflicht fuer alle API-Funktionen (wird im API-Tester verwendet).

### Frontend (Vue)
- vue-i18n: Alles sofort uebersetzen, keine Hardcoded-Strings
- Validierungen und Datenformatierungen in Vue, nicht im Backend
- Kein Gendern — generisches Maskulinum verwenden (Kunde, Benutzer, Mitarbeiter)

### PHP-Schreibweisen
```php
require_once __DIR__.'/inc.php';  // ohne Klammern
```

### Umgebung
- **Entwicklung ist LOKAL** (lokale PostgreSQL auf Port 5432, lokaler PHP/Node)
- **Docker ist NUR fuer Test/Demo** und laeuft auf einem **separaten Server**, nicht auf dem Entwicklungsrechner
- Vom Entwicklungsrechner gibt es KEINE direkte Verbindung zum Docker-Stack — keine `docker`-Kommandos moeglich

### Docker (auf dem Server)
- DB-Container ist leeres PostgreSQL — Schemas und Daten werden manuell per `dbdump` geladen
- Lokale Node-Version: 25 — Dockerfile muss `node:25-alpine` nutzen (Vite 7 braucht crypto.hash, ab Node 20.19+)
- `php:fpm-bookworm` ist KEIN Apache-Image — Runtime-Dirs muessen im Entrypoint erstellt werden
- DB-Schema-Dumps (`backend/db/*.sql`) enthalten bereits CRM-Tabellen — kein `ON_ERROR_STOP=1` bei CRM-Erweiterungsskripten
- Lokale PostgreSQL laeuft auf Port 5432 — Docker nutzt 5433 (siehe `docker/.env.example`)
- Docker-Kommandos: `./scripts/docker.sh help` — granulare Steuerung (up-db, up-web, down-db, destroy-all, dbdump etc.)
- Docker Clean-State: `./scripts/docker.sh destroy-all` dann manuell neu einrichten

### Git
- Kleine, saubere Commits mit aussagekraeftigen Messages
- `fix-ws` vor Commits ausfuehren (tools/fix-ws.sh)

### Gendern und Umlaute
- keine z.B. oe, ae, etc verwenden, sondern die echten deutschen Umlaute ä ü ö usw. beim schreiben von Dateien wie i18n-Übesetzungen und bei Antworten im Prompt
- Niemals gendern!
