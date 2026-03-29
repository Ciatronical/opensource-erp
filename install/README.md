# OpensourceERP - Installationsanleitung

## Übersicht

OpensourceERP ist eine Vue.js-basierte ERP-Anwendung mit PHP/PostgreSQL-Backend.

---

## 0. Software installieren

```bash
# System aktualisieren
sudo apt update && sudo apt upgrade -y

# Node.js und npm
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt install -y nodejs

# PostgreSQL
sudo apt install -y postgresql postgresql-contrib

# PHP und Extensions
sudo apt install -y php php-fpm php-pgsql php-mbstring php-xml php-curl

# Apache2 (nur für Production)
sudo apt install -y apache2

# Git und Perl
sudo apt install -y git perl
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

## 4. Programmier-Stilrichtlinien

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
