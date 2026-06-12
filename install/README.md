# OpensourceERP - Installationsanleitung

## Übersicht

OpensourceERP ist eine Vue.js-basierte ERP-Anwendung mit PHP/PostgreSQL-Backend.

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
