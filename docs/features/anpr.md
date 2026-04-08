# ANPR — Automatische Kennzeichenerkennung

ANPR (Automatic Number Plate Recognition) erkennt Fahrzeuge an der Werkstattzufahrt automatisch per Kamera. Erkannte Kennzeichen erscheinen in der Infoleiste — aber nur wenn kein offener Auftrag für das Fahrzeug existiert. Optional können Tore, Schranken oder andere Aktoren angesteuert werden.

## Voraussetzungen

- **IP-Kamera** mit RTSP-Stream (z.B. Hikvision, Dahua), mindestens 2 MP, Infrarot/Nachtsicht
- **Python 3.12+** auf dem Server (für den Erkennungsdienst)
- **LxCars** muss als Feature aktiviert sein

## Einrichtung

### 1. ANPR aktivieren

Unter **Einstellungen > ANPR**:
- "ANPR aktiviert" ankreuzen
- Service-Host und -Port sind normalerweise auf den Standardwerten (127.0.0.1 / 8765)

### 2. Kamera hinzufügen

Im Abschnitt "Kameras" auf "Kamera hinzufügen" klicken:

| Feld | Beschreibung | Beispiel |
|------|-------------|---------|
| **Name** | Beliebiger Name | "Werkstattzufahrt" |
| **RTSP-URL** | Stream-URL der IP-Kamera | `rtsp://admin:pass@192.168.1.100:554/stream` |
| **Position** | Wo die Kamera montiert ist | Frontal / Seitlich links / Seitlich rechts |
| **Richtungserkennung** | Wie Einfahrt/Ausfahrt unterschieden wird | Größe (Standard) oder Position |
| **Frame-Intervall** | Sekunden zwischen Bildanalysen | 0.5 (Standard) |
| **Min. Confidence** | Mindest-Erkennungssicherheit (0-1) | 0.60 (Standard) |
| **Min. Erkennungen** | Wie oft muss das Kennzeichen erkannt werden bevor gemeldet wird | 3 (Standard) |
| **Cooldown** | Minuten bis dasselbe Kennzeichen erneut gemeldet wird | 5 (Standard) |
| **Aktion** | Was passiert bei Erkennung | Infoleiste / Aktor / Beides |

### 3. Aktor einrichten (optional)

Für Tore, Schranken oder Lichter im Abschnitt "Aktoren":

| Feld | Beschreibung |
|------|-------------|
| **Name** | z.B. "Werkstatttor" |
| **Typ** | Tor, Schranke oder Ampel/Licht |
| **Protokoll** | TCP, HTTP oder Modbus TCP |
| **IP-Adresse / Port** | Netzwerkadresse des Aktors |
| **Befehl: Öffnen** | Hex-Code oder Text-Befehl zum Öffnen |
| **Befehl: Schließen** | Befehl zum Schließen |
| **Befehl: Teilöffnung** | Befehl mit `{height}` als Platzhalter für cm |
| **Max. Höhe** | Maximale Öffnungshöhe in cm |
| **Puffer** | Sicherheitspuffer über Fahrzeughöhe in cm |
| **Auto-Schließen** | Sekunden bis automatisches Schließen |

#### Energie sparende Toröffnung

Wenn die Kamera-Aktion auf "Aktor" oder "Beides" steht und ein Aktor verknüpft ist, kann das Tor **nur so weit öffnen wie nötig**:

- **Toröffnung = "Komplett öffnen"**: Tor geht immer ganz auf
- **Toröffnung = "Fahrzeughöhe + Puffer"**: Tor öffnet nur auf die geschätzte Fahrzeughöhe plus Sicherheitspuffer (z.B. PKW ~150cm + 30cm = 180cm statt volle 300cm)

Dies funktioniert auch mit seitlich montierten Kameras.

### 4. Kamera mit Aktor verknüpfen

In den Kamera-Einstellungen:
- **Aktion** auf "Aktor ansteuern" oder "Infoleiste + Aktor" setzen
- **Verknüpfter Aktor** aus der Dropdown-Liste wählen
- **Toröffnung** wählen (komplett oder fahrzeughöhe-basiert)

### 5. Erkennungsdienst starten

```bash
cd backend/services/plate-recognition
./venv/bin/python anpr_service.py
```

Der Dienst:
- Liest die DB-Verbindung automatisch aus `backend/config/settings.ini`
- Lädt die Kamera-Konfiguration aus der Datenbank
- Startet pro aktiver Kamera einen eigenen Worker-Thread
- Aktualisiert die Konfiguration alle 60 Sekunden (neue Kameras werden automatisch gestartet)
- Schreibt Erkennungen direkt in die Datenbank (SSE-Benachrichtigung feuert automatisch)

Für Dauerbetrieb als Systemd-Service einrichten:

```ini
[Unit]
Description=ANPR Kennzeichenerkennung fuer LxCars
After=postgresql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/pfad/zu/opensource-erp/backend/services/plate-recognition
ExecStart=/pfad/zu/opensource-erp/backend/services/plate-recognition/venv/bin/python anpr_service.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

## Funktionsweise

### Erkennungsablauf

```
Kamera (RTSP-Stream)
    |
    v
Frame lesen (alle 0.5s)
    |
    v
Bildverbesserung (Kontrast/CLAHE)
    |
    v
PaddleOCR: Text erkennen
    |
    v
Deutsches Kennzeichen-Format prüfen
    |
    v
Richtung erkennen (Kennzeichen wird größer = Einfahrt)
    |
    v
Mind. 3x erkannt + Einfahrt bestätigt?
    |
    v
DB: Fahrzeug bekannt? Offener Auftrag?
    |
    +-- Offener Auftrag → Nicht anzeigen (Probefahrt etc.)
    |
    +-- Kein offener Auftrag → Infoleiste + ggf. Tor öffnen
```

### Infoleiste

Erkannte Fahrzeuge erscheinen als **blaue Chips** mit Auto-Icon in der Infoleiste:
- Anzeige: Kennzeichen (z.B. "MOL-HA 856")
- Klick: Zum Fahrzeug navigieren
- Schließen: Erkennung als erledigt markieren
- Automatisches Verschwinden nach konfigurierbarer Zeit (Standard: 8 Stunden)

### Kamera-Montage

- **Schräg von oben** (~30 Grad) montieren — vermeidet Blendung durch Scheinwerfer
- Kennzeichen muss im Bild **mindestens 100px breit** sein
- Fester Bildausschnitt auf die Einfahrtsspur
- Infrarot/Nachtsicht für Betrieb bei Dunkelheit

## Testen

### Im Config-Tab

Unter **Einstellungen > ANPR > Test / Simulation**:
1. Bild oder Video hochladen
2. "Erkennung starten" klicken
3. Ergebnisse werden als Tabelle angezeigt (Kennzeichen, Confidence, Richtung)

### Mit dem Kommandozeilen-Tool

```bash
cd backend/services/plate-recognition

# Einzelbild testen
./venv/bin/python detect_plate.py --image /pfad/zum/foto.jpg

# Video testen
./venv/bin/python detect_plate.py --video /pfad/zum/video.mp4

# Live-Kamera testen
./venv/bin/python detect_plate.py --video rtsp://user:pass@192.168.1.100:554/stream
```

### Erkennungs-Historie

Unter **Einstellungen > ANPR > Erkennungs-Historie** können die letzten 50 Erkennungen eingesehen werden (Zeitpunkt, Kennzeichen, Kunde, Kamera, Confidence, Richtung, Aktion).

## Technische Details

- **OCR-Engine**: PaddleOCR 2.9.1 (Open Source, CPU-basiert, keine GPU nötig)
- **Erkennungsgenauigkeit**: ~93-97% bei deutschen Kennzeichen
- **Verarbeitungszeit**: ~0.3-0.5s pro Frame
- **RAM-Bedarf**: ~800 MB - 1 GB für den Python-Dienst
- **Unterstützte Formate**: Deutsche EU-Kennzeichen (1-3 Buchstaben, Bindestrich, 1-2 Buchstaben, 1-4 Ziffern, optional E/H)
