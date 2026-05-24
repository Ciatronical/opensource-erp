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

Dies funktioniert auch mit seitlich montierten Kameras (siehe Kalibrierung weiter unten).

### 4. Kamera mit Aktor verknüpfen

In den Kamera-Einstellungen:
- **Aktion** auf "Aktor ansteuern" oder "Infoleiste + Aktor" setzen
- **Verknüpfter Aktor** aus der Dropdown-Liste wählen
- **Toröffnung** wählen (komplett oder fahrzeughöhe-basiert)

### 5. Erkennungsdienst installieren

```bash
cd backend/services/plate-recognition
python3 -m venv venv
./venv/bin/pip install -r requirements.txt
```

Beim ersten Start werden die OCR-Modelle heruntergeladen (~15 MB). Einmal manuell starten damit die Modelle gecacht werden:

```bash
./venv/bin/python -c "from paddleocr import PaddleOCR; PaddleOCR(use_angle_cls=True, lang='en', show_log=False, use_gpu=False); print('OK')"
```

### 6. Erkennungsdienst starten

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

### 7. Dauerbetrieb als Systemd-Service

Eine fertige Unit-Datei liegt unter `install/anpr.service`. Pfade und User vor dem Kopieren anpassen!

```bash
sudo cp install/anpr.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable anpr
sudo systemctl start anpr
```

**Wichtig**: Die Unit-Datei braucht `Environment=HOME=/home/<user>` damit PaddleOCR seine Modelle findet. Ohne diese Zeile versucht Systemd nach `/var/www/.paddleocr` zu schreiben und scheitert.

Status prüfen:

```bash
sudo systemctl status anpr
sudo journalctl -u anpr -f    # Live-Log
```

### 8. Neustart über die Web-Oberfläche ermöglichen

Der ANPR-Service kann unter **Einstellungen > ANPR** per Klick gestartet, gestoppt und neu gestartet werden. Dafür muss der Webserver-User `systemctl start/stop/restart anpr` ohne Passwort ausführen dürfen:

```bash
sudo tee /etc/sudoers.d/anpr << 'EOF'
# PHP-Backend (www-data) darf den ANPR-Service via systemctl steuern
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl start anpr.service
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl stop anpr.service
www-data ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart anpr.service
EOF
sudo chmod 440 /etc/sudoers.d/anpr
```

- `www-data` = Apache/PHP-FPM (Produktivsystem, läuft ohne Terminal → kein `requiretty` nötig)
- Für den PHP-Dev-Server (`User=work`) die Zeilen entsprechend ergänzen

### 9. Service-File aktuell halten

Das Service-File unter `install/anpr.service` wird mit dem Repo weiterentwickelt (z.B. neue Environment-Variablen, CPU-Limits). Nach einem `git pull` prüfen ob es Änderungen gab, und ggf. neu einlesen:

```bash
sudo cp install/anpr.service /etc/systemd/system/anpr.service
sudo systemctl daemon-reload
sudo systemctl restart anpr
```

**Wichtig**: Die installierte Unit-Datei unter `/etc/systemd/system/anpr.service` wird durch `git pull` nicht automatisch aktualisiert — sie muss manuell kopiert werden.

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

#### Variante A: Frontal (z.B. an der Gebäudewand gegenüber der Einfahrt)

- **Schräg von oben** (~30 Grad) montieren — vermeidet Blendung durch Scheinwerfer
- Kennzeichen muss im Bild **mindestens 100px breit** sein
- Fester Bildausschnitt auf die Einfahrtsspur
- Infrarot/Nachtsicht für Betrieb bei Dunkelheit
- In der Config: **Position = "Frontal"**

#### Variante B: Seitlich neben dem Tor (empfohlen bei Segmenttoren)

Bei Segmenttoren darf die Kamera **nicht am Tor** montiert werden — sie würde sich beim Öffnen mitbewegen. Stattdessen seitlich daneben:

```
    ┌────────────────────┐
    │                    │
    │       Tor          │📷 ← Kamera seitlich, ~1.0-1.2m Höhe
    │                    │     schräg auf die Einfahrt gerichtet
    └────────────────────┘

         🚗 → Fahrzeug fährt ein
```

- **Höhe**: ~1,0-1,2m (Kennzeichenhöhe)
- **Direkt neben dem Tor** an der Wand oder auf einem Pfosten
- **Schräg auf die Einfahrt** gerichtet, damit das Kennzeichen rechtzeitig erkannt wird
- In der Config: **Position = "Seitlich links"** oder **"Seitlich rechts"**

### Kalibrierung für Fahrzeughöhen-Erkennung

Wenn das Tor nur so weit öffnen soll wie das Fahrzeug hoch ist, muss die Kamera einmalig kalibriert werden. Das Tor dient dabei als Referenz — seine Höhe ist bekannt, und aus dem Verhältnis der Pixel im Bild zur realen Höhe kann die Fahrzeughöhe berechnet werden.

#### Einrichtung (einmalig)

1. In den Kamera-Einstellungen **Toröffnung = "Fahrzeughöhe + Puffer"** wählen
2. Es erscheinen drei Kalibrierungsfelder:

| Feld | Beschreibung | Beispiel |
|------|-------------|---------|
| **Torhöhe real** | Die echte Höhe des Tors in cm | 300 |
| **Oberkante Tor (Y)** | Y-Pixel der Toroberkante im Kamerabild | 50 |
| **Unterkante Tor (Y)** | Y-Pixel der Torunterkante im Kamerabild | 450 |

3. Die Y-Pixel ermitteln: Screenshot vom Kamerabild machen und in einem Bildbearbeitungsprogramm die Pixelposition der Toroberkante und -unterkante ablesen

#### Rechenbeispiel

```
Tor im Bild:  Oberkante bei Y=50, Unterkante bei Y=450 → 400px
Tor real:     300cm
→ 1 Pixel =   0,75cm

Fahrzeug:     Oberkante geschätzt bei Y=110, Unterkante bei Y=350
→ Höhe =      (350 - 110) × 0,75 = 180cm
→ Toröffnung: 180cm + 30cm Puffer = 210cm (statt volle 300cm)
```

Ergebnis: Ein PKW (~150cm) bekommt 180cm Öffnung, ein Transporter (~200cm) bekommt 230cm — statt jedes Mal die vollen 300cm. Das spart Energie und Verschleiß am Torantrieb.

**Tipp**: Die Fahrzeughöhe wird automatisch aus der Kennzeichenposition geschätzt (ein deutsches Kennzeichen sitzt auf ~50cm Höhe und ist genormt 11cm hoch). Wenn die Kalibrierungswerte stimmen, ist die Berechnung auf ±10cm genau.

## Fehlerbehebung

### Near-miss Debug-Snapshots

Wenn Kennzeichen nicht erkannt werden, hilft der Debug-Modus: Unter **Einstellungen > ANPR > Allgemein** die Option **"Near-miss Debug-Snapshots aktivieren"** einschalten.

Der Dienst speichert dann Frames in denen ein Kennzeichen-Kandidat erkannt aber verworfen wurde — z.B. weil das Format nicht stimmt oder das Kennzeichen zu klein im Bild ist. Die Bilder erscheinen direkt im Config-Tab mit der jeweiligen Ablehnungsursache:

| Grund | Bedeutung |
|-------|-----------|
| **Format ungültig** | OCR hat etwas erkannt (Confidence ok), aber kein deutsches Kennzeichen-Format |
| **Kennzeichen zu klein** | Gültiges Kennzeichen, aber unter dem konfigurierten Mindest-Pixelwert |

Die Bilder werden automatisch gelöscht wenn mehr als 500 vorliegen (älteste zuerst). Alle manuell löschen: Schaltfläche "Alle löschen" im Debug-Snapshots-Bereich.

**Typische Ursachen:**
- `Format ungültig`: OCR liest z.B. `8AB1234` statt `B-AB 1234` (Schlecht beleuchtetes oder schräg stehendes Kennzeichen)
- `Kennzeichen zu klein`: Kamera zu weit weg oder Winkel zu flach → `Min. Kennzeichengröße` in den Kameraeinstellungen reduzieren oder Kamera näher montieren

### Service-Log

Der aktuelle `journalctl`-Output ist direkt unter **Einstellungen > ANPR > Service-Log** sichtbar — mit wählbarer Zeilenanzahl und Auto-Refresh alle 5 Sekunden (Play-Button).

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
