# Fahrzeugverwaltung (LxCars)

## Übersicht

Fahrzeuge werden über die CRM-Ansicht oder den Fahrzeugschein-Scan angelegt und
bearbeitet. Jedes Fahrzeug gehört zu einem Kunden (`c_ow`) und kann über
HSN + TSN + D2 mit einem KBA-Stammdatensatz (`kba_lxcars`) verknüpft werden.

**Wichtige Dateien:**

| Datei | Zweck |
|-------|-------|
| `src/features/lxcars/views/car/car.edit.view.vue` | Formular (Anlegen + Bearbeiten) |
| `src/features/lxcars/views/car/composables/useCarAutoSave.js` | Auto-Save, Laden, sendBeacon |
| `src/features/lxcars/views/car/car.scan.view.vue` | Fahrzeugschein-Scan |
| `src/features/lxcars/stores/lxcars.store.js` | Store (API-Aufrufe) |
| `backend/api/lxcars/cars.php` | Backend (saveCar, updateCar, KBA-Logik) |
| `src/core/components/crmview/cars.view.vue` | Fahrzeugliste in der CRM-Ansicht |

---

## 1. Neues Fahrzeug anlegen (manuell)

### Einstieg

Der Benutzer wählt in der CRM-Ansicht einen Kunden aus und klickt in der
Fahrzeuge-Card auf **"Neues Fahrzeug"**. Das öffnet `car.edit.view.vue` im
Anlagemodus (ohne ID).

### Pflichtfelder

Bevor das Fahrzeug erstmalig gespeichert wird, müssen mindestens ausgefüllt
sein:

- **Kennzeichen** (`c_ln`)
- **HSN** (`c_2`, 4-stellig)
- **TSN** (`c_3`, mind. 3 Zeichen)

### Ablauf

1. Benutzer füllt die Felder aus.
2. Alle Textfelder werden automatisch in Großbuchstaben umgewandelt
   (`c_ln`, `c_2`, `c_3`, `c_d2`, `c_em`, `c_fin`, `c_finchk`, `c_mkb`).
3. HSN → Cursor springt automatisch ins TSN-Feld wenn 4 Zeichen erreicht.
4. Bei Eingabe von HSN + TSN wird automatisch ein KBA-Lookup ausgelöst
   (siehe Abschnitt "KBA-Verknüpfung").
5. Beim Verlassen eines Eingabefelds (focus-out) wird automatisch gespeichert.
6. Backend gibt `c_id` zurück → URL wird per `replaceState` auf den
   Bearbeitungsmodus umgeschrieben (`/car/123`).

---

## 2. Neues Fahrzeug anlegen (Fahrzeugschein-Scan)

### Einstieg

Der Benutzer öffnet die Scan-Ansicht (`car.scan.view.vue`), lädt ein Foto oder
PDF des Fahrzeugscheins hoch.

### Scan-Ablauf

1. **Upload**: Bild/PDF wird an die fahrzeugschein-scanner.de API gesendet.
2. **OCR**: API extrahiert alle Felder per Texterkennung.
3. **KBA-Zuordnung**: Wenn die TSN unvollständig ist (z.B. "000"), wird ein
   Auswahldialog mit allen Varianten dieser HSN angezeigt.
4. **Duplikatprüfung**: Existiert bereits ein Fahrzeug mit diesem Kennzeichen?
   - Gleicher Besitzer → Info mit Link zum vorhandenen Fahrzeug.
   - Anderer Besitzer → Warnung.
5. **Besitzerzuordnung**: Scan-Daten (Name, Adresse) werden mit bestehenden
   Kunden abgeglichen. Der Benutzer wählt den Kunden aus oder legt einen neuen
   an.
6. **Weiter zur Bearbeitung**: Die Scan-Daten werden im Store
   (`carsStore.pendingScanData`) zwischengespeichert und die Bearbeitungsansicht
   geöffnet.

### Datenübernahme in die Bearbeitungsansicht

Beim Initialisieren der Bearbeitungsansicht werden die Scan-Daten aus dem Store
übernommen:

- **Fahrzeugfelder**: `c_ln`, `c_2`, `c_3`, `c_fin`, `c_finchk`, `c_d`,
  `c_hu`, `c_em`
- **KBA-Daten**: Vollständiger Datensatz → `pendingKbaData` (wird beim ersten
  Save via `prepareKba` in `kba_lxcars` geschrieben)
- **D2**: Aus den KBA-Daten in `car.c_d2` übernommen
- **Scan-Bilder**: Crops und Original → `pendingScanImages` (werden nach dem
  ersten Save asynchron gespeichert)

### Speicherung der KBA-Daten (Scan)

Beim ersten Save ruft das Backend `prepareKba($kbaData)` auf:

1. **Exakter Treffer** (HSN + TSN + D2 stimmen überein) → UPDATE der variablen
   Felder (ab `d3`). Stammdaten (HSN, TSN, D2, Hersteller, Marke, Name etc.)
   werden nicht überschrieben. Neue Werte werden nur übernommen wenn sie länger
   sind als die vorhandenen.
2. **Treffer ohne D2** (HSN + TSN passen, `d2` ist NULL) → D2 wird gesetzt,
   variable Felder aktualisiert.
3. **Kein Treffer** → INSERT eines neuen KBA-Datensatzes.

---

## 3. Fahrzeug bearbeiten

### Auto-Save

Die Bearbeitungsansicht speichert automatisch — es gibt keinen "Speichern"-
Button.

**Ablauf:**

1. Benutzer tippt in ein Feld → Änderung wird als "pending" markiert.
2. Benutzer verlässt das Feld (focus-out) → Save wird ausgelöst (500 ms
   Debounce).
3. Klick auf Checkbox/Shield → Sofortiger Save.
4. Backend `updateCar` wird aufgerufen.

**sendBeacon-Fallback:**

Wenn der Benutzer die Seite verlässt (Tab schließen, Navigation) bevor der
Debounce-Timer abgelaufen ist, werden die Änderungen per
`navigator.sendBeacon()` gesendet. Das stellt sicher, dass keine Daten
verloren gehen.

### Felder die nicht in cars_lxcars gespeichert werden

- **`c_d2`** (Typschlüssel D.2): Wird im Backend aus den Car-Daten extrahiert
  und per `unset` entfernt. Der Wert wird ausschließlich in `kba_lxcars.d2`
  gespeichert (siehe "KBA-Verknüpfung").

### Laden eines Fahrzeugs

Backend `getCar` liefert den Car-Datensatz inklusive KBA-Daten:

1. `cars_lxcars` wird geladen.
2. KBA-Daten: Zuerst `special_kba_lxcars` (fahrzeugspezifisch), dann Fallback
   auf `kba_lxcars` (über `kba_id`).
3. Frontend setzt `car.c_d2` aus `kba.d2` (nicht aus `cars_lxcars`).

---

## 4. KBA-Verknüpfung

### Überblick

Ein Fahrzeug wird über `kba_id` (Spalte in `cars_lxcars`) mit einem
KBA-Stammdatensatz in `kba_lxcars` verknüpft. Die Verknüpfung basiert auf
HSN + TSN + D2.

Es gibt drei Wege zur KBA-Zuordnung:

### A. Automatischer Lookup (Frontend)

Wird ausgelöst wenn HSN, TSN oder D2 sich ändern:

1. Frontend ruft `lookupKba(hsn, tsn, d2)` auf.
2. Backend sucht in `kba_lxcars`:
   - Wenn D2 angegeben: Zuerst exakter Treffer (HSN+TSN+D2), bei keinem
     Treffer Fallback auf HSN+TSN.
   - Ohne D2: Suche nach HSN+TSN.
3. **Ein Treffer** → Automatische Zuordnung (`kba_id` + `kbaData` gesetzt).
4. **Mehrere Treffer** → Auswahldialog mit Hersteller, Name, Hubraum,
   Leistung, Kraftstoff, D2.
5. **TSN ist Platzhalter** (nur Nullen, z.B. "000") → Alle Varianten der HSN
   anzeigen.

### B. Manuelle Auflösung beim Speichern (resolveKbaWithD2)

Wird im Backend bei jedem Save aufgerufen (wenn keine Scan-KBA-Daten
vorhanden):

**Fall 1 — Genau ein Eintrag, d2 ist NULL:**
Es gibt nur einen Eintrag mit passender HSN+TSN und dessen `d2` ist NULL.
→ `d2` wird auf den eingegebenen Wert gesetzt, Fahrzeug wird verknüpft.

**Fall 2 — Exakter Treffer:**
Ein Eintrag mit passender HSN+TSN+D2 existiert bereits (auch wenn D2 leer ist
und `d2` NULL ist). → Fahrzeug wird verknüpft, kein neuer Eintrag.

**Fall 3 — Neue Variante:**
Einträge mit HSN+TSN existieren, aber kein `d2` passt (alle sind nicht NULL und
stimmen nicht überein). → Neuer KBA-Eintrag wird angelegt, alle Spalten vom
ersten vorhandenen Eintrag kopiert, nur `d2` erhält den neuen Wert.

### C. Scan-Zuordnung (prepareKba)

Wird aufgerufen wenn Scan-KBA-Daten vorhanden sind (vollständiger Datensatz
mit allen Feldern). Eigene Logik für INSERT/UPDATE (siehe Abschnitt
"Speicherung der KBA-Daten (Scan)").

### Special-KBA (Sonderfälle)

Wenn keine Standard-KBA-Zuordnung möglich ist (z.B. Fahrzeug nicht in der
KBA-Datenbank), kann der Benutzer manuell Hersteller und Marke eingeben.
Diese Daten werden in `special_kba_lxcars` gespeichert (verknüpft über `c_id`,
nicht über `kba_id`). Special-KBA hat Vorrang vor normaler KBA.

---

## 5. Watcher-Verhalten bei HSN/TSN/D2-Änderungen

### HSN ändert sich

- TSN und D2 werden geleert.
- `kba_id` und `kbaData` werden zurückgesetzt.
- Kein Lookup (TSN ist leer).

### TSN ändert sich

- `kba_id` und `kbaData` werden zurückgesetzt.
- Neuer KBA-Lookup wird ausgelöst.

### D2 ändert sich

- `kba_id` und `kbaData` werden zurückgesetzt.
- Neuer KBA-Lookup wird ausgelöst.
- Beim nächsten Save übernimmt `resolveKbaWithD2` die endgültige Zuordnung.

---

## 6. Besitzerzuordnung

- Beim Anlegen: Der aktuell ausgewählte Kunde aus dem Store
  (`oserpData.customer_vendor.profile.id`) wird als `c_ow` gesetzt.
- Im Bearbeitungsmodus: Besitzer kann über ein Autocomplete-Feld gewechselt
  werden.
- Backend prüft `c_ow` als Pflichtfeld.
- Nach dem Speichern werden Kundenordner und Symlinks aktualisiert
  (`ensureCustomerFolder`, `ensureVehicleFolders`).

---

## 7. Validierung

### Frontend (useCarValidation)

- Kennzeichen: Format, Duplikatprüfung (async)
- HSN: 4 Ziffern
- TSN: Mind. 3 Zeichen
- Emissionsklasse: Format
- Erstzulassung / HU: Datumsformat (TT.MM.JJJJ)
- FIN: Format, Duplikatprüfung (async)

Solange Validierungsfehler bestehen, wird nicht gespeichert.

### Backend

- `c_ow` und `c_ln` sind Pflichtfelder.
- Leere Datumsfelder werden vor dem INSERT/UPDATE entfernt (PostgreSQL
  akzeptiert keinen leeren String als Date).

---

## 8. Datenbank-Tabellen

### cars_lxcars (Fahrzeuge)

| Spalte | Bedeutung |
|--------|-----------|
| `c_id` | PK (auto-generated) |
| `c_ow` | Besitzer (FK → customer.id) |
| `c_ln` | Kennzeichen |
| `c_2` | HSN (4-stellig) |
| `c_3` | TSN (bis 10 Zeichen) |
| `kba_id` | FK → kba_lxcars.id |
| `c_fin` | Fahrgestellnummer |
| `c_d` | Erstzulassung |
| `c_hu` | HU-Datum |
| `c_em` | Emissionsklasse |
| ... | Reifen, Wartung, Notizen etc. |

### kba_lxcars (KBA-Stammdaten)

| Spalte | Bedeutung |
|--------|-----------|
| `id` | PK (auto-generated) |
| `hsn` | HSN (NOT NULL) |
| `tsn` | TSN, 3 Zeichen (NOT NULL) |
| `d2` | Typschlüssel D.2 |
| `hersteller` | Hersteller (NOT NULL) |
| `marke` | Marke (NOT NULL) |
| `name` | Typ / Modellname |
| ... | 50+ weitere Felder aus dem Fahrzeugschein |

### special_kba_lxcars (Fahrzeugspezifische KBA)

Gleiche Struktur wie `kba_lxcars`, zusätzlich `c_id` (FK → cars_lxcars).
Wird verwendet wenn keine Standard-KBA-Zuordnung möglich ist.
