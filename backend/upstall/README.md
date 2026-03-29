# Database Update System

Das Update-System aktualisiert das Datenbankschema automatisch basierend auf SQL- und CSV-Dateien.

## Verzeichnisstruktur

```
backend/upstall/
├── crm/                          # Basis-CRM (wird immer geladen)
│   ├── auth_schema.sql           # Schema für Auth-Datenbank
│   ├── company_schema.sql        # Schema für Company-Datenbank
│   ├── auth_data/                # CSV-Dateien für Auth-Tabellen
│   │   └── {tabellenname}.csv
│   └── company_data/             # CSV-Dateien für Company-Tabellen
│       └── {tabellenname}.csv
│
├── lxcars/                       # Feature-Modul (optional)
│   ├── auth_schema.sql           # Optional
│   ├── company_schema.sql        # Optional
│   ├── auth_data/
│   │   └── {tabellenname}.csv
│   └── company_data/
│       └── {tabellenname}.csv
│
└── {weitere-features}/
    └── ...
```

## API-Verwendung

### Schema aktualisieren

```
POST /api/update/
Content-Type: application/json

{
    "action": "updateSchema",
    "features": ["lxcars"],     // Optional: zusätzliche Feature-Module
    "auth_db": true,            // Optional: Auth-DB aktualisieren (default: true)
    "company_db": true,         // Optional: Company-DB aktualisieren (default: true)
    "dry_run": false            // Optional: Nur Vorschau ohne Änderungen
}
```

### Beispiel-Response

```json
{
    "success": true,
    "message": "Schema-Update erfolgreich",
    "data": {
        "processed_features": ["crm", "lxcars"],
        "messages": [
            {"type": "table_created", "table": "public.customers"},
            {"type": "columns_added", "table": "public.orders", "count": 2},
            {"type": "csv_imported", "table": "public.countries", "rows_imported": 249}
        ]
    }
}
```

## Unterstützte SQL-Statements

### CREATE TABLE (mit Spaltendefinitionen)

```sql
CREATE TABLE customers (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255)
);
```

- Prüft ob Tabelle existiert
- Fügt fehlende Spalten automatisch hinzu
- Bestehende Spalten werden nicht verändert

### CREATE TABLE ... AS SELECT

```sql
CREATE TABLE blz_de AS
SELECT DISTINCT ON (blz) *
FROM blz_de_raw
WHERE is_main='1'
ORDER BY blz;
```

- Wird nur ausgeführt wenn Tabelle nicht existiert

### CREATE INDEX

```sql
CREATE INDEX idx_customers_email ON customers(email);
CREATE UNIQUE INDEX idx_users_username ON auth.users(username);
```

- Wird nur ausgeführt wenn Index nicht existiert

### CREATE VIEW

```sql
CREATE VIEW active_customers AS
SELECT * FROM customers WHERE active = true;
```

- Wird nur ausgeführt wenn View nicht existiert

### INSERT / UPDATE / DELETE

```sql
INSERT INTO settings (key, value) VALUES ('version', '1.0');
UPDATE settings SET value = '1.1' WHERE key = 'version';
DELETE FROM temp_data WHERE created_at < NOW() - INTERVAL '30 days';
```

- Werden immer ausgeführt

### COMMENT ON

```sql
COMMENT ON TABLE customers IS 'Kundenstammdaten';
COMMENT ON COLUMN customers.email IS 'E-Mail-Adresse des Kunden';
```

- Werden immer ausgeführt

## CSV-Import

### Dateiname = Tabellenname

Der Dateiname (ohne `.csv`) entspricht dem Tabellennamen:

```
company/countries.csv  →  public.countries
auth/roles.csv         →  auth.roles
```

### CSV-Format

- Erste Zeile: Header mit Spaltennamen
- Trennzeichen: Komma (`,`)
- Encoding: UTF-8

```csv
id,code,name,iso3
1,DE,Deutschland,DEU
2,AT,Österreich,AUT
3,CH,Schweiz,CHE
```

### Import-Verhalten

1. **Zeilenvergleich**: CSV wird nur importiert wenn Zeilenanzahl unterschiedlich ist
2. **TRUNCATE**: Bestehende Daten werden vor Import gelöscht
3. **COPY**: PostgreSQL COPY-Statement für schnellen Import
4. **Spalten-Mapping**: Nur Spalten die in CSV und Tabelle existieren werden importiert

### COPY-Statement (intern)

```sql
COPY public.countries (id, code, name, iso3)
FROM '/absolute/path/to/countries.csv'
WITH (FORMAT csv, HEADER true)
```

## Transaktionen & Fehlerbehandlung

- Jede SQL-Datei wird in einer eigenen Transaktion verarbeitet
- Bei Fehler: Automatischer Rollback aller Änderungen der Datei
- CSV-Imports werden separat in Transaktionen verarbeitet

## Versionierung

Nach erfolgreichem Update wird die Git-Version in die `version_oserp` Tabelle eingetragen:

```sql
-- In auth.version_oserp und public.version_oserp
INSERT INTO version_oserp (version) VALUES ('v1.2.3');
```

## Dry-Run Modus

Mit `"dry_run": true` werden keine Änderungen vorgenommen. Stattdessen werden alle geplanten SQL-Statements zurückgegeben:

```json
{
    "success": true,
    "message": "Schema-Update erfolgreich (Dry-Run)",
    "data": {
        "dry_run": true,
        "sql_statements": [
            {
                "type": "CREATE TABLE",
                "table": "public.customers",
                "sql": "CREATE TABLE customers (...)"
            },
            {
                "type": "CSV_IMPORT",
                "table": "public.countries",
                "file": "countries.csv",
                "csv_rows": 249,
                "db_rows": 0
            }
        ]
    }
}
```

## Hinweise

- **Reihenfolge**: SQL-Dateien werden vor CSV-Dateien verarbeitet
- **Features**: Das `crm`-Verzeichnis wird immer geladen, Features sind optional
- **Berechtigungen**: COPY benötigt Lesezugriff auf die CSV-Datei vom PostgreSQL-Server
- **Encoding**: Alle Dateien sollten UTF-8 kodiert sein
