# Videoüberwachung

Das Kamera-Modul verbindet IP-Kameras mit dem ERP. Erkannte Objekte (Personen, Fahrzeuge, Tiere) erscheinen als Ereignisse und können Regeln auslösen (Browser-Benachrichtigung, WhatsApp, E-Mail).

---

## Architektur

```
IP-Kameras (RTSP)
       │
       ├──▶  ERP stream.php ──▶ Snapshot alle 5 s (ohne Frigate)
       │
       └──▶  Frigate NVR ──▶ KI-Erkennung in Echtzeit
                  │
                  └──▶  Webhook ──▶ ERP speichert Ereignis + Bild/Clip
```

Ohne Frigate läuft das Modul im **Basis-Modus**: Snapshots werden alle 5 Sekunden über PHP/FFmpeg abgeholt — keine automatische Erkennung, keine Ereignisse.

Mit Frigate läuft das Modul im **Voll-Modus**: Echtzeit-Objekterkennung, Ereignis-Timeline mit Snapshots/Clips, auslösbare Regeln.

---

## Kameras einrichten

### Automatische Erkennung (empfohlen)

Unter **Videoüberwachung → Einstellungen** auf **"Kameras suchen"** klicken.

Das ERP sendet einen ONVIF WS-Discovery Multicast-Probe ins lokale Netzwerk. Alle ONVIF-kompatiblen IP-Kameras antworten und werden automatisch erkannt. Zugangsdaten werden anhand des Herstellers automatisch ermittelt (Hikvision: `admin/12345`, Dahua: `admin/admin`, Axis: `root/pass` usw.).

Erkannte Kameras werden sofort in der Datenbank gespeichert und erscheinen in der Live-Ansicht.

### Voraussetzungen für automatische Erkennung

- Kamera muss **ONVIF** unterstützen (nahezu alle modernen IP-Kameras)
- ERP-Server und Kameras im **gleichen Subnetz** (Multicast wird nicht geroutet)
- Port 3702/UDP nicht durch Firewall geblockt

### Manuelle Einrichtung

Unter **Einstellungen → Kameras verwalten** auf das Plus-Symbol klicken:

| Feld | Beschreibung | Beispiel |
|------|-------------|---------|
| **Anzeigename** | Beliebiger Name | "Lager Eingang" |
| **Frigate-Kameraname** | Muss mit dem Namen in der Frigate-Konfiguration übereinstimmen | `lager_eingang` |
| **Stream-URL** | RTSP-URL oder Frigate/go2rtc WebRTC-URL | `rtsp://admin:admin@192.168.1.100/av0_0` |
| **Standort** | Optionale Beschreibung | "Halle 1 Nord" |

### Stream-URL Formate

```
# RTSP direkt (Snapshot-Proxy, keine Echtzeit)
rtsp://user:pass@ip:554/stream1

# Frigate MJPEG (flüssig, erfordert Frigate)
http://frigate-host:5000/api/camera/kameraname/mjpeg

# go2rtc WebRTC (niedrige Latenz, erfordert go2rtc)
http://go2rtc-host:1984/stream?src=kameraname
```

---

## Frigate installieren

Frigate ist die NVR-Komponente die für Objekterkennung zuständig ist. Sie läuft als eigener Dienst — idealerweise auf dem gleichen Server wie das ERP.

> **Frigate ohne Docker:** Frigate selbst unterstützt offiziell nur Docker. Für eine Docker-freie Installation steht die **Nativ-Option** zur Verfügung: **go2rtc** (Streams/WebRTC) + der eingebaute **camera-monitor** (YOLO-Objekterkennung). Das deckt denselben Funktionsumfang ab.

### Option A — Nativ (go2rtc, kein Docker, empfohlen)

Unter **Einstellungen → Frigate-Verbindung** auf **"Nativ (go2rtc)"** umschalten, dann **"Installationsbefehle anzeigen"**.

Das ERP generiert fertige Copy-Paste-Befehle:

```bash
# go2rtc herunterladen (einzelne Binary, keine Abhängigkeiten)
wget https://github.com/AlexxIT/go2rtc/releases/latest/download/go2rtc_linux_amd64 \
  -O /usr/local/bin/go2rtc
chmod +x /usr/local/bin/go2rtc

# Als systemd-Dienst einrichten
systemctl enable --now go2rtc
```

go2rtc läuft danach unter `http://SERVER:1984`. Stream-URLs für Kameras:
`http://SERVER:1984/api/ws?src=KAMERANAME`

Die YOLO-Objekterkennung und Webhook-Benachrichtigungen übernimmt der eingebaute **camera-monitor**-Service des ERP (unter Einstellungen → KI-Hardware konfigurieren).

### Option B — Docker (Frigate)

```bash
# Verzeichnis anlegen
mkdir -p /opt/frigate && cd /opt/frigate

# docker-compose.yml erstellen
cat > docker-compose.yml << 'EOF'
services:
  frigate:
    container_name: frigate
    image: ghcr.io/blakeblackshear/frigate:stable
    privileged: true
    restart: unless-stopped
    shm_size: "128mb"
    volumes:
      - /etc/localtime:/etc/localtime:ro
      - /opt/frigate/config:/config
      - /opt/frigate/storage:/media/frigate
      # Intel iGPU (OpenVINO):
      # - /dev/dri:/dev/dri
      # Google Coral USB:
      # - /dev/bus/usb:/dev/bus/usb
    ports:
      - "5000:5000"   # Web-UI und API
      - "8554:8554"   # RTSP re-streams
      - "8555:8555"   # WebRTC
    environment:
      FRIGATE_RTSP_PASSWORD: "geheim"
EOF

# Konfiguration anlegen
mkdir -p /opt/frigate/config
```

### Frigate Konfiguration (`/opt/frigate/config/config.yml`)

```yaml
mqtt:
  enabled: false   # optional: für Home Assistant

# Objekterkennung — Standard CPU
detectors:
  default:
    type: cpu

# Kameras definieren
cameras:
  lager_eingang:
    ffmpeg:
      inputs:
        - path: rtsp://admin:admin@192.168.1.100/av0_0
          roles:
            - detect
            - record
    detect:
      width: 1280
      height: 720
      fps: 5
    objects:
      track:
        - person
        - car
        - dog
    record:
      enabled: true
      retain:
        days: 7

# Webhook ans ERP bei jedem Ereignis
notifications:
  - type: webhook
    url: "http://ERP-HOST/api/camera/webhook.php?token=DEIN_TOKEN&client=MANDANT"
    events:
      - new
      - end
```

Den **Token** und die **Webhook-URL** findest du unter **Einstellungen → Frigate-Verbindung** im ERP.

```bash
# Starten
docker compose up -d

# Logs prüfen
docker compose logs -f frigate
```

Frigate-UI ist dann unter `http://SERVER-IP:5000` erreichbar.

### ERP konfigurieren

Unter **Videoüberwachung → Einstellungen → Frigate-Verbindung**:

- **Frigate-URL**: `http://192.168.1.x:5000`
- **Webhook-Token**: Neuen Token generieren und in Frigate-Konfiguration eintragen

---

## Hardware-Beschleunigung

### Übersicht

| Hardware | Geschwindigkeit | Kosten | Empfehlung |
|----------|----------------|--------|-----------|
| CPU (kein Extra) | ~10 Bilder/s | — | Bis 2 Kameras |
| Intel iGPU (OpenVINO) | ~40 Bilder/s | kostenlos | Ab 3 Kameras mit Intel-CPU |
| Google Coral USB | ~100 Bilder/s | ~35 € | Ab 6 Kameras oder für minimale CPU-Last |

### Intel iGPU (OpenVINO)

**Voraussetzung:** Intel-CPU der 6. Generation oder neuer (Skylake+). Prüfen: `lspci | grep VGA`.

#### In Frigate aktivieren

`/opt/frigate/config/config.yml` anpassen:

```yaml
detectors:
  default:
    type: openvino
    device: GPU

model:
  width: 300
  height: 300
  input_tensor: nhwc
  input_pixel_format: bgr
  path: /openvino-model/ssdlite_mobilenet_v2.xml
  labelmap_path: /openvino-model/coco_91cl_bkgr.txt
```

`docker-compose.yml`: `/dev/dri` einkommentieren und Container neu starten:

```bash
docker compose down && docker compose up -d
```

#### Im ERP-eigenen Kamera-Monitor aktivieren

Unter **Einstellungen → KI-Hardware** auf **"Installieren"** neben OpenVINO klicken.

Oder manuell im venv:

```bash
cd /pfad/zum/erp/backend/services/camera-monitor
source venv/bin/activate
pip install openvino
```

### Google Coral USB

**Hardware:** Google Coral USB Accelerator — erhältlich bei Amazon/Mouser (~35 €).

#### Treiber installieren

```bash
# Coral-Treiber (einmalig)
echo "deb https://packages.cloud.google.com/apt coral-edgetpu-stable main" \
  | sudo tee /etc/apt/sources.list.d/coral-edgetpu.list
curl https://packages.cloud.google.com/apt/doc/apt-key.gpg | sudo apt-key add -
sudo apt update
sudo apt install libedgetpu1-std

# Benutzer zur plugdev-Gruppe hinzufügen
sudo usermod -aG plugdev $USER
# Neu einloggen oder: newgrp plugdev
```

#### In Frigate aktivieren

`/opt/frigate/config/config.yml`:

```yaml
detectors:
  coral:
    type: edgetpu
    device: usb
```

`docker-compose.yml`: `/dev/bus/usb` einkommentieren.

#### Im ERP-eigenen Kamera-Monitor

Unter **Einstellungen → KI-Hardware** wird das Coral automatisch erkannt sobald es eingesteckt ist. Auf **"Installieren"** klicken um den Python-Treiber (`pycoral`) zu installieren.

---

## Zonen

Zonen begrenzen Erkennungsregeln auf Bildbereiche (z.B. nur Erkennung im Eingangsbereich, nicht im Hintergrund).

Zonen werden in Frigate als Polygone definiert und im ERP unter der jeweiligen Kamera eingetragen. Der Zonenname muss in beiden Systemen identisch sein.

Frigate-Dokumentation zu Zonen: `https://docs.frigate.video/configuration/zones`

---

## Regeln und Benachrichtigungen

Unter **Videoüberwachung → Regeln** können Aktionen konfiguriert werden die bei Ereignissen ausgelöst werden:

| Aktion | Beschreibung |
|--------|-------------|
| Browser-Benachrichtigung | Echtzeit-Push im Browser (erfordert offenes Tab) |
| WhatsApp | Nachricht an eine Mobilnummer (erfordert WhatsApp-Business-API) |
| E-Mail | Platzhalter, in Kürze verfügbar |
| Nur Protokoll | Ereignis wird gespeichert, keine aktive Benachrichtigung |

**Einstellbare Filter pro Regel:**

- Kamera (alle oder eine bestimmte)
- Zone (nur Ereignisse in einer bestimmten Zone)
- Objekttypen (Person, Auto, Hund …)
- Zeitfenster (z.B. nur nachts 22:00–06:00)
- Wochentage
- Mindest-Erkennungsgenauigkeit (0–100 %)
- Cooldown (Mindestabstand zwischen zwei Auslösungen in Sekunden)

---

## Fehlerbehebung

### Kameras werden nicht erkannt (WS-Discovery)

```bash
# ONVIF Multicast testen
python3 backend/services/camera-monitor/discover_cameras.py

# Firewall prüfen
sudo iptables -L | grep 3702
```

### Stream bleibt schwarz

```bash
# RTSP-URL direkt testen
ffmpeg -rtsp_transport tcp -i "rtsp://user:pass@ip:port/pfad" \
  -frames:v 1 /tmp/test.jpg && echo OK

# Snapshot-Verzeichnis prüfen
ls -la public/camera-snapshots/
```

### Frigate-Webhook kommt nicht an

```bash
# Webhook manuell testen
curl -X POST "http://localhost/api/camera/webhook.php?token=TOKEN&client=MANDANT" \
  -H "Content-Type: application/json" \
  -d '{"type":"new","after":{"id":"test1","camera":"test","label":"person","zones":[],"score":0.9,"start_time":0}}'
```
