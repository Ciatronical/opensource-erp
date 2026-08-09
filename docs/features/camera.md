# Videoüberwachung

Das Kamera-Modul verbindet IP-Kameras mit dem ERP. Erkannte Objekte (Personen, Fahrzeuge, Tiere) erscheinen als Ereignisse mit Snapshot und Videoclip und können Regeln auslösen (Browser-Benachrichtigung, WhatsApp, E-Mail).

Das Modul kommt ohne Docker und ohne Fremdsoftware wie Frigate aus: es besteht aus zwei schlanken systemd-Diensten, die beide über die ERP-Oberfläche installiert und gesteuert werden.

---

## Architektur

```
IP-Kameras (RTSP)
       │
       ├──▶  go2rtc ──────────▶ Live-Bild im Browser (WebRTC/MJPEG)
       │     (Binary, 1984)
       │
       └──▶  camera-monitor ──▶ Bewegungserkennung + KI (YOLO/Coral/OpenVINO)
             (Python-Dienst)         │
                                     ├──▶ camera_event in der DB + Snapshot/Clip
                                     └──▶ pg_notify → SSE → Browser-Meldung
```

| Komponente | Aufgabe | Ort |
|---|---|---|
| **go2rtc** | Wandelt RTSP in browsertaugliche Streams um. Einzelne Binary (~8 MB), keine Abhängigkeiten. | `backend/services/go2rtc/` |
| **camera-monitor** | Liest die RTSP-Streams direkt, erkennt Bewegung und Objekte, schreibt Ereignisse, nimmt Clips auf. | `backend/services/camera-monitor/` |
| **stream.php / mjpeg.php** | Liefern Standbild bzw. MJPEG-Stream authentifiziert über den Webserver aus (kein Mixed-Content bei HTTPS). | `backend/api/camera/` |
| **media.php** | Liefert Snapshots und Clips nur mit gültiger Session und nur aus der eigenen Mandanten-DB aus. | `backend/api/camera/` |

Ohne go2rtc läuft die Live-Ansicht im **Basis-Modus**: `stream.php` holt das Standbild per ffmpeg direkt von der Kamera. Ohne camera-monitor gibt es keine Ereignisse.

**Medien liegen ausserhalb des DocumentRoots** (`backend/data/camera-snapshots/` und `backend/data/camera-clips/`) und sind ausschliesslich über `media.php` erreichbar.

---

## Kameras einrichten

### Automatische Erkennung (empfohlen)

Unter **Videoüberwachung → Einstellungen** auf **"Netzwerk scannen"** klicken.

Das ERP sendet einen ONVIF WS-Discovery Multicast-Probe ins lokale Netzwerk. Alle ONVIF-kompatiblen IP-Kameras antworten und werden automatisch angelegt. Zugangsdaten werden anhand des Herstellers durchprobiert (Hikvision `admin/12345`, Dahua `admin/admin`, Axis `root/pass`, Reolink, Uniview, Tapo, Bosch usw.).

Gefundene Kameras werden sofort gespeichert, in go2rtc registriert und erscheinen in der Live-Ansicht. Ein erneuter Scan legt keine Duplikate an — bereits bekannte Kameras werden nur aktualisiert (deaktivierte wieder aktiviert, fehlende Stream-URL nachgetragen).

**Voraussetzungen:**

- Kamera unterstützt **ONVIF** (nahezu alle modernen IP-Kameras)
- ERP-Server und Kameras im **gleichen Subnetz** (Multicast wird nicht geroutet)
- Port 3702/UDP nicht durch Firewall geblockt

### Manuelle Einrichtung

Unter **Einstellungen → Kameras** auf **"Kamera hinzufügen"** klicken.

**Reiter "Kamera":**

| Feld | Beschreibung | Beispiel |
|------|-------------|---------|
| **Anzeigename** | Beliebiger Name | Lager Eingang |
| **Kamera-Schlüssel** | Eindeutiger Bezeichner ohne Leerzeichen — wird als Stream-Name in go2rtc verwendet. Bleibt das Feld leer, wird er aus dem Anzeigenamen erzeugt. | `lager_eingang` |
| **Stream-URL** | RTSP-URL der Kamera | `rtsp://admin:admin@192.168.1.100:554/stream1` |
| **Standort** | Optionale Beschreibung | Halle 1 Nord |
| **Reihenfolge** | Sortierung in der Übersicht | 0 |

**Reiter "Erkennung":** pro Kamera einstellbar

| Feld | Bedeutung | Standard |
|---|---|---|
| Bewegungsschwelle | Anteil geänderter Pixel, ab dem Bewegung gilt. Kleiner = empfindlicher. | 1,5 % |
| Mindest-Erkennungsgenauigkeit | Unterhalb dieses Wertes wird ein Objekt verworfen. | 0,45 |
| Analyse-Framerate | Analysierte Bilder pro Sekunde. Mehr = mehr CPU-Last. | 2 |
| Videoaufnahmen erstellen | Clip zu jedem Ereignis aufzeichnen | an |
| Vor-Aufnahme | Sekunden vor dem Ereignis (Ringpuffer) | 3 |
| Nach-Aufnahme | Sekunden nach dem Ereignis | 5 |

Beim Speichern trägt das ERP den Stream automatisch in go2rtc ein — sowohl in `go2rtc.yaml` als auch per REST-API im laufenden Dienst. Beim Löschen wird er wieder entfernt. **Manuelles Bearbeiten der go2rtc-Konfiguration ist nicht nötig.**

---

## Dienste installieren

Beides läuft über **Videoüberwachung → Einstellungen** — jeweils ein Klick, kein Terminal nötig, solange die sudo-Regeln installiert sind (siehe unten).

### 1. Live-Stream-Dienst (go2rtc)

**"go2rtc installieren"** klicken. Das ERP

1. erkennt die Architektur (amd64 / arm64 / arm),
2. lädt die passende Binary nach `backend/services/go2rtc/go2rtc`,
3. legt `go2rtc.yaml` an, falls noch keine vorhanden ist (API auf Port 1984, leere Stream-Liste),
4. installiert und startet `go2rtc.service`,
5. speichert `http://localhost:1984` als go2rtc-URL in `defaults_oserp`.

Scheitert Schritt 4 mangels sudo-Rechten, zeigt die Oberfläche unter **"systemd-Befehle anzeigen"** die fertigen Befehle zum Kopieren:

```bash
sudo cp backend/services/go2rtc/go2rtc.service /etc/systemd/system/go2rtc.service
sudo systemctl daemon-reload
sudo systemctl enable --now go2rtc
```

Prüfen lässt sich das mit **"Verbindung testen"** — die Anzeige nennt Version und Anzahl der synchronisierten Streams. Die go2rtc-Weboberfläche ist unter `http://SERVER:1984` erreichbar, ein einzelner Stream unter `http://SERVER:1984/stream.html?src=KAMERA_SCHLUESSEL`.

### 2. Erkennungs-Dienst (camera-monitor)

Der Dienst braucht eine Python-Umgebung. Die wird einmalig im Terminal angelegt — die Installationsknöpfe im Abschnitt **Objekterkennung (KI-Hardware)** erzeugen zwar ein venv, installieren aber nur die optionalen Beschleuniger-Pakete, nicht die Grundausstattung:

```bash
cd backend/services/camera-monitor
python3 -m venv venv
./venv/bin/pip install -r requirements.txt
```

Enthalten sind OpenCV, NumPy, psycopg2, ultralytics (YOLO) sowie `wsdiscovery` und `onvif-zeep` — letztere beide werden auch für **"Netzwerk scannen"** gebraucht.

Danach im Abschnitt **Erkennungs-Dienst** auf **"Dienst einrichten & starten"** klicken. Das ERP generiert die Service-Datei (Benutzer = Eigentümer des venv), installiert und startet sie. Fallback ohne sudo:

```bash
sudo cp backend/services/camera-monitor/camera-monitor.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now camera-monitor
```

Der Dienst holt seine Zugangsdaten aus `backend/config/settings.ini`, ermittelt darüber alle Mandanten-Datenbanken und arbeitet für jeden Mandanten mit aktiviertem NVR-Feature.

Alle 60 Sekunden gleicht der Dienst den Ist-Zustand mit der Datenbank ab: neue Kameras bekommen einen Worker, gelöschte oder deaktivierte verlieren ihn, geänderte Einstellungen, Zonen und Regeln lassen den betroffenen Worker neu starten. Änderungen in der Oberfläche greifen also von selbst — ein Neustart des Dienstes ist nur nach einem Update des Programmcodes nötig.

Die letzten Protokolleinträge stehen direkt in der Oberfläche, ausserdem per `journalctl -u camera-monitor -f`.

### 3. sudo-Regeln (einmalig als root)

Damit die Dienste über die Oberfläche gestartet, gestoppt und neu gestartet werden können:

```bash
cp backend/services/oserp-camera-sudoers /etc/sudoers.d/oserp-camera
chmod 440 /etc/sudoers.d/oserp-camera
visudo -c -f /etc/sudoers.d/oserp-camera
```

Die Datei erlaubt `www-data` ausschliesslich `systemctl start|stop|restart` für `go2rtc.service`, `camera-monitor.service` und `anpr.service`.

### Modul ein-/ausschalten

Unter **Einstellungen → Funktionen** schaltet **Videoüberwachung** das Modul für den angemeldeten Mandanten ein oder aus.

`go2rtc` und `camera-monitor` laufen je einmal für **alle** Mandanten. Beim Ausschalten werden sie deshalb nur dann gestoppt, wenn kein anderer Mandant die Videoüberwachung noch nutzt — andernfalls laufen sie weiter, und nur die Kameras des abgeschalteten Mandanten fallen binnen einer Minute aus der Verarbeitung. Zu prüfen ist das auch bei Test- und Altmandanten: steht dort `feature_nvr` noch auf ein, bleiben die Dienste aktiv.

---

## Hardware-Beschleunigung

### Übersicht

| Hardware | Geschwindigkeit | Kosten | Empfehlung |
|----------|----------------|--------|-----------|
| CPU (YOLOv8n) | ~10 Bilder/s | — | Bis 2 Kameras |
| Intel iGPU (OpenVINO) | ~40 Bilder/s | kostenlos | Ab 3 Kameras mit Intel-CPU |
| Google Coral USB | ~100 Bilder/s | ~35 € | Ab 6 Kameras oder für minimale CPU-Last |

Unter **Einstellungen → Objekterkennung (KI-Hardware)** erkennt das ERP CPU, RAM, GPU, angestecktes Coral und installierte Python-Pakete und zeigt an, welches Backend gerade aktiv ist. Der camera-monitor wählt beim Start automatisch das schnellste verfügbare: Coral → OpenVINO → CPU.

Installierbar per Klick sind nur die vier freigegebenen Pakete `openvino`, `pycoral`, `tflite-runtime` und `ultralytics`. Nach jeder Installation den Erkennungs-Dienst neu starten.

### Intel iGPU (OpenVINO)

**Voraussetzung:** Intel-CPU ab 6. Generation (Skylake+). Prüfen: `lspci | grep VGA`.

In der Oberfläche neben OpenVINO auf **"Installieren"** klicken, oder manuell:

```bash
cd backend/services/camera-monitor
./venv/bin/pip install openvino
```

Das YOLO-Modell wird beim ersten Start automatisch ins OpenVINO-Format exportiert (`yolov8n_openvino_model/`) — der erste Start dauert dadurch etwas länger.

### Google Coral USB

**Hardware:** Google Coral USB Accelerator (~35 €).

Systemtreiber einmalig installieren:

```bash
echo "deb https://packages.cloud.google.com/apt coral-edgetpu-stable main" \
  | sudo tee /etc/apt/sources.list.d/coral-edgetpu.list
curl https://packages.cloud.google.com/apt/doc/apt-key.gpg | sudo apt-key add -
sudo apt update
sudo apt install libedgetpu1-std

sudo usermod -aG plugdev $USER   # danach neu einloggen oder: newgrp plugdev
```

Anschliessend im venv:

```bash
cd backend/services/camera-monitor
./venv/bin/pip install pycoral tflite-runtime
```

Das ERP erkennt den Stick per USB-ID auch ohne installiertes `pycoral` und weist in der Oberfläche darauf hin, was noch fehlt (`libedgetpu` oder `pycoral`). Das Coral-Modell wird beim ersten Start automatisch heruntergeladen.

---

## Zonen

Zonen begrenzen Regeln auf Bildbereiche — etwa nur der Hofeinfahrt statt der ganzen Strasse dahinter.

Unter **Einstellungen → Kameras → Zonen konfigurieren** öffnet sich der Zoneneditor mit dem Live-Bild der Kamera:

- Zone anlegen und in der Liste auswählen
- Klick ins Bild setzt einen Polygonpunkt
- Doppelklick schliesst das Polygon ab
- Rechtsklick entfernt den letzten Punkt

Die Polygone werden als Koordinaten in `camera_zone.coordinates` gespeichert; der camera-monitor prüft für jedes erkannte Objekt, in welchen Zonen sein Mittelpunkt liegt, und schreibt sie ins Ereignis. Eine Konfiguration ausserhalb des ERP gibt es nicht.

---

## Regeln und Benachrichtigungen

Unter **Videoüberwachung → Regeln** werden Aktionen konfiguriert, die bei Ereignissen ausgelöst werden:

| Aktion | Beschreibung |
|--------|-------------|
| Browser-Benachrichtigung | Echtzeit-Push über SSE (erfordert offenes Tab) |
| WhatsApp | Nachricht an eine Mobilnummer (erfordert WhatsApp-Business-API) |
| E-Mail | Platzhalter, in Kürze verfügbar |
| Nur Protokoll | Ereignis wird gespeichert, keine aktive Benachrichtigung |

**Einstellbare Filter pro Regel:**

- Kamera (alle oder eine bestimmte)
- Zone (nur Ereignisse in einer bestimmten Zone)
- Objekttypen (Person, Auto, Motorrad, Fahrrad, Bus, LKW, Hund, Katze, Vogel, Pferd)
- Zeitfenster (z.B. nur nachts 22:00–06:00)
- Wochentage
- Mindest-Erkennungsgenauigkeit (0–100 %)
- Cooldown (Mindestabstand zwischen zwei Auslösungen in Sekunden)

---

## Fehlerbehebung

### Kameras werden nicht gefunden (WS-Discovery)

```bash
# ONVIF Multicast direkt testen
backend/services/camera-monitor/venv/bin/python \
  backend/services/camera-monitor/discover_cameras.py

# Firewall prüfen
sudo iptables -L | grep 3702
```

### Stream bleibt schwarz

```bash
# RTSP-URL direkt testen
ffmpeg -rtsp_transport tcp -i "rtsp://user:pass@ip:554/stream1" \
  -frames:v 1 /tmp/test.jpg && echo OK

# Läuft go2rtc und kennt es die Kamera?
systemctl status go2rtc
curl -s http://localhost:1984/api/streams | head
```

Kennt go2rtc den Stream nicht, genügt einmal Speichern der Kamera im ERP — dabei wird er neu registriert.

### Keine Ereignisse

```bash
systemctl status camera-monitor
journalctl -u camera-monitor -n 50 --no-pager
```

Häufige Ursachen: Videoüberwachung unter **Einstellungen → Funktionen** nicht aktiviert, Kamera auf inaktiv gesetzt, Bewegungsschwelle zu hoch oder Mindest-Genauigkeit zu streng eingestellt.

### Snapshots und Clips fehlen

```bash
ls -la backend/data/camera-snapshots/
ls -la backend/data/camera-clips/
```

Beide Verzeichnisse legt der camera-monitor selbst an — sie müssen dem Service-Benutzer gehören. Ausgeliefert werden die Dateien nur über `media.php` mit gültiger Session.

---

## Umstieg von Frigate

Frühere Versionen banden Frigate als NVR über einen Webhook ein. Das ist entfallen: `webhook.php` gibt es nicht mehr, Erkennung und Aufzeichnung übernimmt der camera-monitor.

Die Datenbankspalten wurden dabei umbenannt — `frigate_name` → `cam_key`, `frigate_zone` → `zone_key`, `frigate_event_id` → `event_id`. Bestehende Installationen ziehen die Werte beim nächsten Schema-Update (**Entwickler-Tools → Schema-Update**) automatisch um; die alten Spalten werden entfernt.

Ein laufender Frigate-Container kann danach abgeschaltet werden. Die Kameras selbst müssen nicht neu angelegt werden — nur die Stream-URLs sollten auf die RTSP-Adresse der Kamera zeigen, nicht mehr auf Frigate.
