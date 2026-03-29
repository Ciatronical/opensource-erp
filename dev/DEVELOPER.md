# OpensourceERP - Entwickler-Dokumentation

Willkommen im OpensourceERP-Entwicklerteam!

---

## Erste Schritte

### 1. Repository klonen

```bash
git clone https://github.com/Ciatronical/opensource-erp.git
cd opensource-erp
```

### 2. Installation (siehe README.md)

Führe die Schritte aus der `README.md` durch, bis das System läuft.

### 3. Programmierstil-Richtlinien lesen (Pflicht!)

```bash
docu/programmierstil-richtlinien.md
```

**Diese Datei MUSS vor der ersten Code-Änderung gelesen werden!**

---

## Entwicklungs-Workflow

### Development-Server starten

```bash
./scripts/run-dev.sh
```

Startet automatisch:
- Frontend (Vite): http://localhost:5173
- Backend (PHP): http://localhost:8000

### Code-Änderungen

1. Branch erstellen: `git checkout -b feature/mein-feature`
2. Code ändern (siehe Programmierstil-Richtlinien!)
3. Testen im Development-Modus
4. Commit mit aussagekräftiger Message
5. Pull Request erstellen

### Production-Build testen

```bash
./scripts/run-build.sh
```

---

## Projekt-Struktur

```
opensource-erp/
├── backend/
│   ├── api/               # API-Endpunkte
│   │   ├── customer_vendor/
│   │   ├── inc.php        # Zentrale Funktionen (resultInfo, etc.)
│   │   └── ...
│   ├── config/            # Konfigurationsdateien
│   └── db/                # SQL-Scripts
├── src/
│   ├── core/
│   │   ├── components/    # Wiederverwendbare Komponenten
│   │   ├── views/         # Seiten
│   │   ├── stores/        # Pinia Stores
│   │   └── utils/         # Hilfsfunktionen
│   └── features/          # Feature-spezifischer Code
├── scripts/               # Build & Dev Scripts
└── docu/                  # Dokumentation
```

---

## Wichtige Konventionen

### 1. Datei-Header

**JEDE Datei** (außer JSON) muss in der ersten Zeile den Pfad enthalten:

```php
<?php
// backend/api/customer_vendor/example.php
```

```vue
<!-- src/core/views/example.vue -->
```

```javascript
// src/core/utils/example.js
```

### 2. API-Responses

**Standard ist `payload` (nicht `data`):**

```php
// Backend
resultInfo(true, 'SUCCESS', ['key' => 'value']);

// Gibt zurück:
{
  "success": true,
  "text": "SUCCESS",
  "payload": {"key": "value"}
}
```

```javascript
// Frontend
response.data.payload.key  // ✅ Richtig
response.data.data.key     // ❌ Falsch
```

### 3. Einrückung

**Immer 4 Leerzeichen** (kein TAB)

### 4. Store als Single Source of Truth

Keine doppelten Datenabfragen - immer über den Store!

---

## Git-Workflow

### Commit Messages

```
Kurze Zusammenfassung (max 50 Zeichen)

- Änderung 1
- Änderung 2
- Änderung 3

Dateien geändert:
- pfad/zur/datei1.php
- pfad/zur/datei2.vue
```

### Branches

- `main` - Production
- `develop` - Development
- `feature/xyz` - Neue Features
- `bugfix/xyz` - Bugfixes

---

## Debugging

### Frontend

```javascript
console.log('🔍 Debug:', variable)
```

Vue DevTools installieren!

### Backend

```php
writeLog('Debug: ' . print_r($data, true));
```

Log-Dateien prüfen.

### Datenbank

```sql
-- Queries testen
SELECT * FROM customer WHERE ...
```

---

## Testing

### Manuelle Tests

Jede Änderung muss getestet werden:
- Development-Modus funktioniert?
- Production-Build funktioniert?
- Keine Console-Errors?
- Keine PHP-Errors?

---

## Hilfreiche Befehle

### Git

```bash
git grep "suchbegriff"                    # Code durchsuchen
git log --oneline --graph                 # Commit-History
git diff                                   # Änderungen anzeigen
```

### Datenbank

```bash
psql -U postgres -d kivitendo_auth        # Datenbank öffnen
\dt                                        # Tabellen auflisten
\d customer                                # Tabellen-Schema
```

### Node/npm

```bash
npm run dev                                # Development-Server
npm run build                              # Production-Build
npm run preview                            # Build testen
```

---

## Ressourcen

- **Programmierstil:** `docu/programmierstil-richtlinien.md`
- **Vue 3 Docs:** https://vuejs.org/
- **Vuetify Docs:** https://vuetifyjs.com/
- **Pinia Docs:** https://pinia.vuejs.org/

---

## Fragen?

Bei Unklarheiten:
1. Programmierstil-Richtlinien prüfen
2. Code-Kommentare lesen
3. GitHub Issues erstellen
