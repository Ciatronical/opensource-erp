---

# Entwicklungsrichtlinien

## 1. Anwendungslogik & Architektur

### 1.1 Logik in der Datenbank
Die **Anwendungslogik liegt primär in der Datenbank**, *nicht in PHP*.

### 1.2 Einheitliches JSON
Die Datenbank liefert **immer genau ein JSON-Objekt** zurück.

### 1.3 Umwandlungen in Vue
Datenformatierungen (z. B. Datum) werden **in Vue** durchgeführt.

### 1.4 Validierung in Vue
Validierungen (E-Mail, Kennzeichen etc.) erfolgen **in Vue**, nicht auf Back-End-Ebene.

### 1.5 Performance
Datenverarbeitung findet **dort statt, wo die Daten liegen** → in der Datenbank.

### 1.6 Minimaler Datenverkehr
**Nur ein Ajax-Call und eine DB-Abfrage pro Vorgang!**
In PHP wird **nichts zusammengebaut**.
Alles wird in SQL zusammengebaut!

### 1.7 Kein Hardcoding von Daten
**NIEMALS Daten im Code hardcoden!**
- Keine Listen, Keywords, Mappings im PHP- oder Vue-Code
- Alle Daten kommen aus der Datenbank
- Konfigurationen gehören in DB-Tabellen oder `defaults`
- Statistische Analysen werden dynamisch aus vorhandenen Daten berechnet

```php
// ❌ FALSCH - Hardgecodete Keywords
$serviceKeywords = ['arbeit', 'montage', 'reparatur'];

// ✅ RICHTIG - Dynamisch aus DB lernen
SELECT word, part_type, COUNT(*)
FROM parts, unnest(string_to_array(description, ' ')) as word
GROUP BY word, part_type
```

### 1.8 Store als Single Source of Truth
**Keine Daten doppelt abrufen!**
- Wenn Daten im Store vorhanden sind → **kein DB-Aufruf**
- Backend-Kommunikation **nur via Store**, nicht direkt aus Komponenten
- **Nur einen einzigen Store verwenden** (`oserpStore`)

### 1.9 Variablenbenennung nach Tabellenstruktur
**Variablen die Tabellen abbilden heißen genau so wie die Tabellen.**

Beispiele:
```javascript
// ✅ RICHTIG - Namen entsprechen den DB-Tabellen
additional_billing_addresses  // Tabelle: additional_billing_addresses
shiptos                        // Tabelle: shipto (Plural)
contacts                       // Tabelle: contacts

// ❌ FALSCH
billing_addresses             // Tabelle heißt aber additional_billing_addresses
deliveryAddresses             // camelCase statt underscore
```

---

## 2. Datenbankzugriffe

### 2.1 NIEMALS direkt pg_connect() verwenden!

**IMMER** die Datenbankhelfer-Klassen verwenden:

```php
// ✅ RICHTIG - Company-Datenbank
$db = DbhCompany::begin();

// ✅ RICHTIG - Auth-Datenbank
$auth = DbhAuth::begin();

// ❌ FALSCH - Niemals direkt pg_connect()
$conn = pg_connect("host=... dbname=...");  // NIEMALS!
```

### 2.2 Wann welche Datenbank?

**DbhCompany::begin()** - für alle Company/Mandanten-spezifischen Daten:
- Kunden, Lieferanten, Artikel
- Belege (Angebote, Aufträge, Rechnungen)
- Alle Business-Daten
- 99% aller Queries

**DbhAuth::begin()** - nur für Authentication und User-Management:
- Sessions
- User-Daten
- Client-Zuordnungen
- Login-Verwaltung

### 2.3 Query-Methoden

```php
$db = DbhCompany::begin();

// Eine Zeile holen
$row = $db->getOne($query, $params);

// Mehrere Zeilen holen
$rows = $db->getAll($query, $params);

// Query ohne Rückgabe ausführen (INSERT/UPDATE/DELETE)
$db->execute($query, $params);

// PDOStatement für spezielle Fälle
$stmt = $db->query($query);
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### 2.4 Prepared Statements IMMER verwenden

```php
// ✅ RICHTIG - Prepared Statement
$db->getOne("SELECT * FROM customer WHERE id = :id", [':id' => $customerId]);

// ❌ FALSCH - SQL Injection möglich!
$db->query("SELECT * FROM customer WHERE id = $customerId");
```

---

## 3. Datenbank-Design

### 3.1 Namenskonventionen

#### Tabellen
* plural
* lowercase
* underscore

Beispiele:
```
customers
invoice_items
shipping_addresses
```

#### Spalten
* lowercase
* underscore

Beispiele:
```
customer_id
created_at
total_amount
```

#### Primary Keys
```
id  (INTEGER, SERIAL)
```

#### Foreign Keys
```
[table]_id
```

#### Indexes
```
idx_[table]_[column]
```

Beispiel:
```sql
CREATE INDEX idx_customers_email ON customers(email);
```

#### Constraints
```
[table]_[column]_[type]
```

Beispiel:
```sql
ALTER TABLE customers
ADD CONSTRAINT customers_email_unique UNIQUE (email);
```

---

## 4. Code-Kommentare

### 4.1 Funktionskommentare
Über jeder Funktion steht ein **DocBlock-Kommentar** mit `/**`:

```php
/**
 * Kurzbeschreibung was die Funktion macht
 *
 * @param type $data['paramName'] Beschreibung des Parameters
 * @testdata {"paramName": "beispielwert"}
 */
function functionName($data) {
    // Implementation
}
```

**WICHTIG:** Der `@testdata` Tag ist **PFLICHT** für alle API-Funktionen!
- Enthält JSON mit Testdaten für den API-Tester
- Wird automatisch im Developer-Tools API-Tester verwendet
- Ohne `@testdata` wird eine Warnung angezeigt

Beispiel:
```php
/**
 * Lädt Kundendaten anhand der ID
 *
 * @param int $data['customerId'] ID des Kunden
 * @testdata {"customerId": 1}
 */
function getCustomer($data) {
    // ...
}

/**
 * Sucht nach Kunden mit Suchbegriff
 *
 * @param string $data['term'] Suchbegriff
 * @param int $data['limit'] Maximale Anzahl (optional)
 * @testdata {"term": "test", "limit": 10}
 */
function searchCustomers($data) {
    // ...
}
```

### 4.2 Inline-Kommentare
Wichtige Stellen im Code werden mit `//` kommentiert:
```php
// Hier stehen die kompletten Daten des Benutzers aus der auth-Datenbank drin
return $result;
```

---

## 5. Internationalisierung (i18n)

### 5.1 Übersetzungen
* Nutzung von **vue-i18n**
* **Alles sofort übersetzen**, keine Hardcoded-Strings im Template oder Script

### 5.2 Kein Gendern!
**Im gesamten Projekt wird NICHT gegendert. Keine Ausnahmen.**

Es heißt **Kunde**, nicht „Kund:innen", nicht „Kundinnen und Kunden", nicht „Kundschaft".
Es heißt **Benutzer**, nicht „Benutzer:innen". Es heißt **Mitarbeiter**, nicht „Mitarbeitende".

Warum?
- Das generische Maskulinum ist grammatikalisch korrekt und schließt alle Menschen ein
- Gendersprache macht Code, UI-Texte und Übersetzungen unnötig kompliziert und hässlich
- Wer „Kundinnen und Kunden" schreibt, schließt nonbinäre Personen aus — also genau das Gegenteil von dem, was beabsichtigt wird
- Dass alle Menschen gleich behandelt werden, steht im Grundgesetz. Wir brauchen keine sprachlichen Belehrungen in einer ERP-Software
- **Ideologische oder politische Statements haben in diesem Projekt nichts verloren**

```
// ❌ FALSCH
"Kund:innen"
"Kundinnen und Kunden"
"Mitarbeitende"
"Benutzer*innen"

// ✅ RICHTIG
"Kunde"
"Kunden"
"Mitarbeiter"
"Benutzer"
```

---

## 6. Versionierung

### 6.1 Git nutzen
fix-ws ist Pflicht! Es liegt unter tools, beseitigt Whitespace-Errors und nimmt einem viel Arbeit ab.
Einmalig einen Symlink unter /usr/local/sbin/ anlegen, damit Änderungen an tools/fix-ws.sh automatisch übernommen werden:
```
sudo ln -sf "$(pwd)/tools/fix-ws.sh" /usr/local/sbin/fix-ws.sh
```
Danach ist `fix-ws.sh` systemweit aufrufbar.

-!- Achtung: fix-ws.sh fügt standardmäßig keine neuen Dateien hinzu (erst mit `git add` einzeln hinzufügen, dann fix-ws.sh aufrufen).
Mit `fix-ws.sh -a` wird für jede unversionierte Datei/jedes Verzeichnis einzeln per `[Y/n]` nachgefragt (Default: `y` → wird geaddet).

### 6.2 Viele kleine Commits
Kleine, saubere, nachvollziehbare Schritte.

### 6.3 Aussagekräftige Commit-Messages

**Nicht gut:**
> "customer-vendor-search bearbeitet"
> (Sieht man ohnehin mit `git status` oder `gitk`)

**Gut:**
> "Kunden- und Lieferantensuche findet nun auch Personen"


## 7. PHP-Schreibweisen

```php
require_once __DIR__.'/inc.php'; statt require_once(__DIR__.'/inc.php');
```