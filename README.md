# OpensourceERP

**Das moderne Web-Frontend für kivitendo** — Rechnungen, Kunden, E-Mails, Dokumente und mehr in einer Oberfläche.

### Was steckt drin?

- Moderne Rechnungs- und Auftragsmaske mit Live-Suche
- Integrierter E-Mail-Client mit Kundenzuordnung
- Dateimanager für Dokumente und Anhänge
- Wiedervorlagen als Kanban-Board, Liste oder Kalender
- Wiki / Wissensdatenbank für interne Dokumentation
- Kunden- & Lieferantenverwaltung mit Kontakten, Adressen und Umsatzstatistik
- Globale Suche über alle Bereiche
- 100% kompatibel mit bestehenden kivitendo-Datenbanken

### Tech-Stack

Vue 3 + Vite + Vuetify · PHP 8 · PostgreSQL 16

---

## Installation ohne Docker

### 1. Voraussetzungen installieren

```bash
sudo apt update && sudo apt upgrade -y

sudo apt install -y \
  git \
  nodejs npm \
  php php-cli php-pgsql php-mbstring php-xml php-curl php-ssh2 \
  postgresql postgresql-contrib qrencode \
  gnome-terminal
```

### 2. Repository klonen

```bash
git clone https://github.com/Ciatronical/opensource-erp.git
cd opensource-erp
```

### 3. Starten

**Development:**

```bash
./scripts/run-dev.sh
```

Läuft auf **http://localhost:5173**

**Production:**

```bash
# Apache konfigurieren
sudo cp install/apacheOpensourceErp.conf /etc/apache2/sites-available/
sudo a2enmod rewrite proxy_fcgi setenvif
sudo a2ensite apacheOpensourceErp
sudo systemctl restart apache2

# Build & Deploy
./scripts/run-build.sh
```

Läuft auf **http://localhost**

### 4. Setup

Beim ersten Aufruf im Browser wird automatisch der Setup-Wizard gestartet.
Dort werden die Datenbank-Zugangsdaten eingegeben und eine `settings.ini` angelegt.

---

## Installation mit Docker

### 1. Voraussetzungen

- [Docker](https://docs.docker.com/engine/install/) und [Docker Compose](https://docs.docker.com/compose/install/)

### 2. Repository klonen

```bash
git clone https://github.com/Ciatronical/opensource-erp.git
cd opensource-erp/docker
```

### 3. Konfiguration

```bash
cp .env.example .env
nano .env
```

Wichtige Werte in `.env`:

| Variable | Beschreibung | Standard |
|---|---|---|
| `DOMAIN` | Domain für SSL-Zertifikat | `erp.example.com` |
| `POSTGRES_PASSWORD` | Datenbank-Passwort | *muss gesetzt werden* |
| `WEB_HTTP_PORT` | HTTP-Port | `8080` |
| `WEB_HTTPS_PORT` | HTTPS-Port | `8443` |

### 4. Starten

```bash
# DB-Container starten und Datenbanken manuell einrichten
./scripts/docker.sh up-db

# Web-Container starten
./scripts/docker.sh up-web
```

Details zur DB-Einrichtung: siehe `docker/SETUP_DEMO.md`

Die Anwendung läuft auf **http://localhost:8080**

### SSL aktivieren

Für Let's Encrypt SSL-Zertifikate `DOMAIN` und `CERTBOT_EMAIL` in `.env` setzen. Der Web-Container holt die Zertifikate automatisch.

---

## Troubleshooting

**Port bereits belegt?**
```bash
sudo lsof -i :5173
sudo lsof -i :8000
```

**Datenbank-Verbindung fehlgeschlagen?**
- `backend/config/api.config.php` prüfen
- PostgreSQL läuft? `sudo systemctl status postgresql`

**Docker-Logs prüfen:**
```bash
docker compose logs -f web
docker compose logs -f db
```
