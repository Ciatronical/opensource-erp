# LxCars ANPR — Automatische Kennzeichenerkennung an der Werkstattzufahrt

## Ziel

Fahrzeuge, die in den Werkstattbereich einfahren, werden per Kamera automatisch erkannt. Das Kennzeichen wird ausgelesen und in der Infoleiste angezeigt — aber nur, wenn fuer das Fahrzeug kein offener Auftrag existiert (Probefahrten, Teileabholungen etc. werden ignoriert).


## Architektur

```
┌─────────────┐    RTSP     ┌──────────────────────┐
│  IP-Kamera  │───────────►│  Python-Dienst        │
│  (Zufahrt)  │            │  - PaddleOCR          │
└─────────────┘            │  - Richtungserkennung │
                           │  - Deduplizierung     │
                           └──────────┬────────────┘
                                      │ HTTP POST
                                      ▼
                           ┌──────────────────────┐
                           │  PHP API (oserp)      │
                           │  lxcars/anpr/         │
                           │  - DB-Abgleich        │
                           │  - Auftragspruefung   │
                           └──────────┬────────────┘
                                      │
                                      ▼
                           ┌──────────────────────┐
                           │  Infoleiste (Vue)     │
                           │  - Fahrzeug-Chips     │
                           └──────────────────────┘
```


## Komponenten

### 1. Hardware

- **IP-Kamera** mit RTSP-Stream (z.B. Hikvision/Dahua, ab ~150 EUR)
  - Mindestens 2 MP, besser 4 MP
  - Infrarot/Nachtsicht
  - Varifocal-Objektiv empfohlen
  - Wetterfest (IP67) fuer Aussenmontage
- **Montage:** Schraeg von oben (~30°), Kennzeichen muss im Bild mindestens 100px breit sein
- **Server:** Bestehender Rechner oder kleiner NUC (8 GB RAM, keine GPU noetig)

### 2. Kennzeichenerkennung (Python-Dienst)

Laeuft als Systemd-Service auf dem lokalen Server.

**Technologie:** PaddleOCR (Open Source, von Baidu)
- Erkennt Textposition UND liest Text in einem Durchlauf
- Gute Erkennung bei schlechter Beleuchtung, Schmutz, schraegen Winkeln
- Laeuft auf CPU, keine GPU erforderlich
- ~0,3–0,5s pro Frame

**Ablauf pro Frame:**
1. Frame aus RTSP-Stream lesen (OpenCV, alle 0,5–1s)
2. PaddleOCR: Text im Bild finden und lesen
3. Ergebnis gegen deutsches Kennzeichen-Format pruefen (Regex)
4. Bounding-Box-Groesse mit vorherigen Frames vergleichen → Richtungserkennung
5. Bei bestaetigter Einfahrt: HTTP POST an PHP-API

**Richtungserkennung:**
- Bounding Box des Kennzeichens wird ueber mehrere Frames getrackt
- Wird groesser → Fahrzeug faehrt auf Kamera zu → Einfahrt
- Wird kleiner → Fahrzeug entfernt sich → Ausfahrt (ignorieren)
- Schwellenwert: 15% Groessenaenderung

**Dateien:**
- `backend/services/plate-recognition/detect_plate.py` — Prototyp (Bild/Video/Stream)
- `backend/services/plate-recognition/requirements.txt` — Python-Abhaengigkeiten

### 3. Datenbank

Neue Tabelle `car_detections_lxcars`:

```sql
CREATE TABLE car_detections_lxcars (
    id           SERIAL PRIMARY KEY,
    c_ln         VARCHAR(10),                              -- erkanntes Kennzeichen
    c_id         INTEGER REFERENCES cars_lxcars(c_id),     -- NULL wenn unbekannt
    detected_at  TIMESTAMP DEFAULT now(),
    direction    VARCHAR(3) CHECK (direction IN ('in', 'out')),
    dismissed    BOOLEAN DEFAULT FALSE,
    dismissed_by INTEGER,
    confidence   NUMERIC(4,2)                              -- Erkennungssicherheit 0-1
);

CREATE INDEX idx_car_detections_pending
    ON car_detections_lxcars(dismissed, detected_at)
    WHERE dismissed IS NOT TRUE;
```

### 4. PHP API (`backend/api/lxcars/anpr/`)

Drei Endpunkte:

**`reportDetection`** — Wird vom Python-Dienst aufgerufen
- Empfaengt: `{kennzeichen, confidence, direction}`
- Sucht Fahrzeug in `cars_lxcars` per `c_ln`
- Prueft ob offener Auftrag existiert (`oe.closed IS NOT TRUE`)
- Speichert in `car_detections_lxcars` wenn relevant

**`getPendingDetections`** — Fuer die Infoleiste
- Liefert alle nicht-dismissed Einfahrten ohne offenen Auftrag
- Nur Erkennungen der letzten X Stunden

**`dismissDetection`** — Wenn Mitarbeiter das Fahrzeug in der Infoleiste wegklickt

### 5. Frontend (Infoleiste)

Erweiterung der bestehenden `info-bar.component.vue`:
- Neuer Chip-Typ mit Auto-Icon fuer erkannte Fahrzeuge
- Anzeige: Kennzeichen + Kundenname (falls bekannt)
- Klick → zum Fahrzeug navigieren oder neuen Auftrag anlegen
- Dismiss → Erkennung als erledigt markieren


## Logik: Wann wird angezeigt?

```
Kennzeichen erkannt + Einfahrt bestaetigt
        │
        ▼
  Kennzeichen in cars_lxcars suchen (c_ln)
        │
        ├── Nicht gefunden → Anzeigen als "Neues Fahrzeug"
        │
        └── Gefunden → Offene Auftraege pruefen:
                │
                │   SELECT 1 FROM oe
                │   JOIN oe_ext ON oe.id = oe_ext.oe_id
                │   WHERE oe_ext.c_id = :c_id
                │     AND oe.closed IS NOT TRUE
                │
                ├── Offener Auftrag existiert → NICHT anzeigen
                │
                └── Kein offener Auftrag → In Infoleiste anzeigen
```


## Deduplizierung

Der Python-Dienst erkennt dasselbe Kennzeichen auf vielen aufeinanderfolgenden Frames. Um Spam zu vermeiden:
- Cooldown pro Kennzeichen: 5 Minuten nach letzter Meldung
- Erst melden wenn Richtung "in" bestaetigt (mindestens 3 Frames)
- PHP-API prueft zusaetzlich ob das Kennzeichen in den letzten 10 Minuten schon gemeldet wurde


## Umsetzungsreihenfolge

1. **Prototyp testen** — `detect_plate.py` mit Testbild/Video ausfuehren
2. **Kamera beschaffen und aufstellen** — RTSP-Stream verifizieren
3. **DB-Tabelle anlegen** — `car_detections_lxcars`
4. **PHP-API bauen** — `reportDetection`, `getPendingDetections`, `dismissDetection`
5. **Python-Dienst zum Service ausbauen** — Dauerlauf, API-Anbindung, Systemd
6. **Infoleiste erweitern** — Neue Chips fuer Fahrzeug-Erkennungen
7. **Feintuning** — Schwellenwerte, Cooldowns, Beleuchtungsbedingungen


## Kosten

| Posten | Einmalig | Laufend |
|--------|----------|---------|
| IP-Kamera | 150–300 EUR | — |
| NUC/Mini-PC (falls noetig) | 200–400 EUR | ~5 EUR Strom/Monat |
| PaddleOCR | kostenlos | kostenlos |
| Gesamt | **150–700 EUR** | **~5 EUR/Monat** |
