# OpensourceERP - Installationsanleitung

## Übersicht

OpensourceERP ist eine Vue.js-basierte ERP-Anwendung mit PHP/PostgreSQL-Backend.

---

## Schnellstart: automatischer Installer

Für eine **frische Maschine** richtet `install/install.sh` den kompletten Stack
idempotent ein (Pakete, PHP-FPM, Build, Apache, SSE, Whisper, Ollama, ANPR,
Kameras, Cron, Asterisk-Gerüst, Borg-Gerüst, kivitendo).

```bash
git clone <repo> opensource-erp && cd opensource-erp
./install/install.sh --list          # Schritte anzeigen
./install/install.sh                 # alles einrichten (als Betriebs-User, nutzt sudo)
./install/install.sh --only apache,sse   # nur einzelne Schritte erneut
```

**Wichtig:** Es werden **keine Versionen hardcodiert**. PHP-Version und FPM-Socket
werden zur Laufzeit erkannt und als Apache-`Define` (`OSERP_ROOT`, `OSERP_PHP_FPM`)
nach `/etc/apache2/conf-available/oserp-defines.conf` geschrieben; der vHost
`apacheOpensourceErp.conf` referenziert nur diese Variablen. Beim PHP-Update
(8.3 → 9.0 …) muss nichts angepasst werden.

Secrets/umgebungsspezifische Teile (DB-Passwort, Asterisk-Config, Borg-Repo)
markiert der Installer mit `[TODO]` — diese werden vom alten Server migriert.

### Vorher in einer VM testen

Nie ungetestet auf der Zielmaschine — `install/test-vm.sh` zieht eine
wegwerfbare Ubuntu-VM hoch (multipass), kopiert einen sauberen Repo-Stand und
führt den Installer darin aus. Snapshots erlauben beliebige Wiederholung:

```bash
sudo snap install multipass
./install/test-vm.sh up
./install/test-vm.sh snapshot base
./install/test-vm.sh all             # up + deploy + run
# bei Fehler fixen, committen, dann:
./install/test-vm.sh restore base && ./install/test-vm.sh all
```

Die manuellen Einzelschritte unten dienen als Referenz / für Sonderfälle.

---

## 0. Software installieren

```bash
# System aktualisieren
sudo apt update && sudo apt upgrade -y

# Node.js und npm
curl -fsSL https://deb.nodesource.com/setup_25.x | sudo -E bash -
sudo apt install -y nodejs

# PostgreSQL
sudo apt install -y postgresql postgresql-contrib

# PHP und Extensions
sudo apt install -y php php-fpm php-pgsql php-mbstring php-xml php-curl php-intl php-zip

# Composer (PHP-Abhängigkeiten)
sudo apt install -y composer

# Apache2 (nur für Production)
sudo apt install -y apache2

# Git und Perl
sudo apt install -y git perl

# Python (für ANPR-Kennzeichenerkennung)
sudo apt install -y python3 python3-venv python3-full
```

---

## 1. Repository klonen

```bash
# Development
OpensourceErp wird als normler Benutzer betrieben

git clone git@gitlab.com:inter-data.de/opensource-erp.git
cd opensource-erp
```

---

## 1b. PHP-Abhängigkeiten installieren

Erzeugt `backend/vendor/` inklusive `autoload.php`. Notwendig für Development und Production.

```bash
cd backend
composer install
cd ..
```

Hinweis: Schlägt der Aufruf mit `Class "Normalizer" not found` fehl, fehlt die `intl`-Extension für die aktive PHP-Version. Bei mehreren parallel installierten PHP-Versionen (z. B. via `ondrej/sury`) zeigt das Metapaket `php-intl` nur auf die Default-Version — dann gezielt `php<version>-intl` installieren (z. B. `sudo apt install php8.3-intl`).

---

## 2a. Development-Modus

```bash
# Backend-Konfiguration erstellen
nano backend/config/api.config.php  # Datenbank-Zugangsdaten eintragen

cp backend/config/api.passwd.php.example backend/config/api.passwd.php
nano backend/config/api.passwd.php

# Development-Server starten
./scripts/run-dev.sh
```

**Fertig!** Die Anwendung läuft auf: **http://localhost:5173**

---

## 2b. Production-Modus

```bash
# Backend-Konfiguration (wie oben)
cp backend/config/api.config.php.example backend/config/api.config.php
nano backend/config/api.config.php


**Apache konfigurieren:**

Kurz:
```bash
sudo cp install/apacheOpensourceErp.conf  /etc/apache2/sites-available/

sudo a2enmod rewrite proxy_fcgi setenvif
sudo a2ensite apacheOpensourceErp
sudo systemctl restart apache2
```

**Build und Deploy:**

```bash
./scripts/run-build.sh
```

**Fertig!** Die Anwendung läuft auf: **http://localhost**

---

## 3. Cronjobs einrichten

### WhatsApp-Erinnerungen (Termine + HU)

Automatischer Versand von WhatsApp-Erinnerungen an Kunden. Das Script durchlauft alle Mandanten und versendet:
- **Terminerinnerungen**: Kalendertermine im konfigurierten Vorlaufzeitraum
- **HU-Erinnerungen**: Fahrzeuge mit faelliger Hauptuntersuchung

**Voraussetzungen:**
- WhatsApp Business API konfiguriert (Firmenkonfiguration > CRM)
- Templates mit Status "approved" bei Meta (Typ "reminder" fuer Termine, Typ "hu" fuer HU)
- Terminerinnerungen: aktiviert unter CRM > WhatsApp Erinnerungen
- HU-Erinnerungen: aktiviert unter LxCars > "HU-Erinnerung per WhatsApp"

**Cron einrichten (alle 15 Minuten):**

```bash
crontab -e
```

Folgende Zeile einfuegen (Pfad anpassen!):

```
*/15 * * * * cd /home/work/opensource-erp && php backend/cli/whatsapp-reminders.php >> log/whatsapp-reminders.log 2>&1
```

**Log-Verzeichnis erstellen (einmalig):**

```bash
mkdir -p log
```

**Manuell testen:**

```bash
cd /home/work/opensource-erp
php backend/cli/whatsapp-reminders.php
```

---

## 4. ANPR-Kennzeichenerkennung (optional)

Nur nötig wenn die automatische Kennzeichenerkennung an der Werkstattzufahrt genutzt werden soll.

```bash
cd backend/services/plate-recognition
python3 -m venv venv
./venv/bin/pip install -r requirements.txt

# OCR-Modelle einmalig herunterladen
./venv/bin/python -c "from paddleocr import PaddleOCR; PaddleOCR(use_angle_cls=True, lang='en', show_log=False, use_gpu=False); print('OK')"
```

Konfiguration und Kameras werden im Browser unter **Einstellungen > ANPR** eingerichtet.

Für Dauerbetrieb als Systemd-Service (Pfade und User in der Datei anpassen!):

```bash
sudo cp install/anpr.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable anpr
sudo systemctl start anpr
```

**Wichtig**: Die Unit-Datei braucht `Environment=HOME=/home/<user>` damit PaddleOCR seine Modelle findet.

Ausführliche Dokumentation: `docs/features/anpr.md`

---

## 4b. SSE-Server (Echtzeit-Benachrichtigungen)

Der SSE-Server liefert Live-Updates (Anrufliste, Kalender, Faktura, WhatsApp …)
und wird von Apache unter `/sse/` auf `127.0.0.1:3001` weitergeleitet. Läuft er
nicht, antwortet `/sse/events` mit **503 Service Unavailable**.

Für Dauerbetrieb als Systemd-Service (Pfade, User und ggf. Node-Pfad anpassen!):

```bash
sudo cp install/oserp-sse.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable oserp-sse
sudo systemctl start oserp-sse
```

`Restart=always` sorgt dafür, dass der Dienst nach einem Absturz automatisch
wieder startet. Im Docker-Betrieb übernimmt der Container-Entrypoint dieselbe
Aufgabe (Auto-Restart-Schleife), ein Service ist dort nicht nötig.

**Wichtig — `Restart=always`, nicht `on-failure`:** Der Server kann sich auch
sauber (Exit 0) beenden, z. B. wenn die DB-Verbindung wegbricht. Mit
`Restart=on-failure` wird er dann **nicht** neu gestartet und bleibt still
liegen (Port 3001 leer, Frontend ohne Live-Updates). Deshalb zwingend
`Restart=always` verwenden.

### Variante ohne root (User-Service)

Wird OpensourceERP als normaler Benutzer betrieben (Standardfall, siehe 1.),
kann der SSE-Server auch als **systemd-User-Service** laufen — ohne `sudo` und
ohne System-Unit. Dafür die Unit nach `~/.config/systemd/user/` legen und
`linger` aktivieren, damit der Dienst Logout überlebt und beim Boot startet:

```bash
mkdir -p ~/.config/systemd/user
cp install/oserp-sse.service ~/.config/systemd/user/
# Findet systemd "node" nicht (nvm/Custom-Pfad): absoluten Node-Pfad in ExecStart eintragen
systemctl --user daemon-reload
systemctl --user enable --now oserp-sse
sudo loginctl enable-linger "$USER"   # einmalig: Dienst läuft ohne aktive Session
```

Status/Logs dann **immer mit `--user`** prüfen (im System-Scope taucht er nicht
auf, sonst wirkt er fälschlich „nicht installiert"):

```bash
systemctl --user status oserp-sse
journalctl --user -u oserp-sse -f
```

### Healthcheck

```bash
curl -m3 -D- http://127.0.0.1:3001/events
```

**HTTP 401 Unauthorized ist gesund** — der Server verlangt eine Login-Session
und weist Anfragen ohne Cookie ab. „Connection refused" bedeutet dagegen, dass
der Server tot ist.

---

## 5. Programmier-Stilrichtlinien

Vor der Entwicklung bitte lesen:
```
docu/programmierstil-richtlinien.md
```
---

## Workflow

**Development:**
```bash
./scripts/run-dev.sh  # Startet beide Server
```

**Production (nach Code-Änderungen):**
```bash
./scripts/run-build.sh  # Build + Deploy
```

---
