# OpensourceERP Docker Setup

## Ueberblick

Das Setup besteht aus zwei **unabhaengigen** Containern:

| Container | Image | Aufgabe |
|-----------|-------|---------|
| **{STACK_NAME}-web** | PHP 8.3 FPM + Apache 2.4 | Vue-Frontend (statisch) + PHP-API + SSL via Certbot |
| **{STACK_NAME}-db** | PostgreSQL 16 Alpine | Leere PostgreSQL-Instanz |

Container-, Volume- und Netzwerk-Namen werden durch `STACK_NAME` in `.env` gesteuert (Standard: `oserp`).

**Wichtig:** Der DB-Container startet als leeres PostgreSQL. Datenbanken, Schemas und Daten muessen manuell eingerichtet werden. Der Web-Container ist unabhaengig vom DB-Container und kann separat gestartet/gestoppt werden.

## Schnellstart

```bash
# 1. Konfiguration erstellen
cp docker/.env.example docker/.env

# 2. .env anpassen (mindestens POSTGRES_PASSWORD setzen)
nano docker/.env

# 3. DB-Container starten
./scripts/docker.sh up-db

# 4. Datenbanken anlegen
./scripts/docker.sh psql postgres
```

```sql
CREATE DATABASE oserp_auth;
CREATE DATABASE oserp_company;
\q
```

```bash
# 5. Basis-Schemas laden (eigene kivitendo-Dumps, nicht im Repository)
./scripts/docker.sh dbdump dumps/auth.sql oserp_auth
./scripts/docker.sh dbdump dumps/company.sql oserp_company

# 6. Erweiterungen installieren (CRM, lxcars etc.)
./scripts/docker.sh upstall

# 7. Web-Container starten
./scripts/docker.sh up-web

# 8. Status pruefen
./scripts/docker.sh status
```

Nach dem Start ist die Anwendung unter `http://localhost:<WEB_HTTP_PORT>` erreichbar (Standard: 8080).

## Konfiguration (.env)

Alle Einstellungen werden ueber die `.env` Datei gesteuert.

| Variable | Beschreibung | Default |
|----------|-------------|---------|
| `STACK_NAME` | Praefix fuer Container-, Volume- und Netzwerk-Namen | oserp |
| `DOMAIN` | Domain fuer den Server (ohne https://) | erp.example.com |
| `CERTBOT_EMAIL` | E-Mail fuer Let's Encrypt | admin@example.com |
| `POSTGRES_USER` | PostgreSQL-Benutzer (**muss** `postgres` sein!) | postgres |
| `POSTGRES_PASSWORD` | PostgreSQL-Passwort | — (Pflicht) |
| `DB_AUTH_NAME` | Name der Auth-Datenbank | oserp_auth |
| `DB_COMPANY_NAME` | Name der Company-Datenbank | oserp_company |
| `DB_EXTERNAL_PORT` | Externer Port fuer PostgreSQL (pgAdmin etc.) | 5433 |
| `WEB_HTTP_PORT` | Externer HTTP-Port | 8080 |
| `WEB_HTTPS_PORT` | Externer HTTPS-Port | 8443 |
| `APP_TIMEZONE` | Zeitzone | Europe/Berlin |
| `APP_DEBUG` | Debug-Modus (true/false) | false |
| `SESSION_COOKIE_NAME` | Name des Session-Cookies | opensource_erp |
| `SESSION_COOKIE_SAMESITE` | SameSite-Policy des Cookies | Strict |
| `DEMO_MODE` | Demo-Modus aktivieren (true/false) | false |
| `DEMO_INACTIVITY_MINUTES` | Minuten Inaktivitaet bis DB-Reset (nur Demo-Modus) | 20 |

**Wichtig:** `POSTGRES_USER` muss `postgres` bleiben, da das Datenbankschema `ALTER ... OWNER TO postgres` Statements enthaelt.

### Mehrere Instanzen parallel betreiben

Um mehrere Stacks auf dem gleichen Server zu betreiben, klone das Repository
in separate Verzeichnisse und setze in jeder `.env` einen anderen `STACK_NAME`
und andere Ports:

```bash
# Instanz 1: STACK_NAME=demo1, WEB_HTTP_PORT=8081, DB_EXTERNAL_PORT=5433
# Instanz 2: STACK_NAME=demo2, WEB_HTTP_PORT=8082, DB_EXTERNAL_PORT=5434
```

## docker.sh Kommandos

Zentrales Skript fuer alle Docker-Operationen: `./scripts/docker.sh <command>`

### Container starten

| Kommando | Beschreibung |
|----------|-------------|
| `up-db` | DB-Container starten |
| `up-web` | Web-Container starten (baut Image wenn noetig) |
| `up-all` | Alle Container starten |

### Container stoppen

| Kommando | Beschreibung |
|----------|-------------|
| `down-db` | DB-Container stoppen |
| `down-web` | Web-Container stoppen |
| `down-all` | Alle Container stoppen |

### Container entfernen

| Kommando | Beschreibung |
|----------|-------------|
| `destroy-db` | DB-Container + Volume + Image loeschen |
| `destroy-web` | Web-Container + Image loeschen |
| `destroy-all` | Alles loeschen (Container, Volumes, Images) |

`destroy` loescht immer alles (Container, Volumes, Images). Danach muss alles
neu eingerichtet werden (Datenbanken anlegen, Schemas laden etc.).

### Datenbank

| Kommando | Beschreibung |
|----------|-------------|
| `dbdump <file> <db>` | SQL-Datei in Datenbank laden (passt auth.clients automatisch an) |
| `upstall` | Alle Erweiterungen aus backend/upstall/ installieren |
| `psql <db>` | PostgreSQL-Shell oeffnen |
| `backup` | Backup beider Datenbanken erstellen |

### Sonstiges

| Kommando | Beschreibung |
|----------|-------------|
| `status` | Status aller Container anzeigen |
| `logs [service]` | Logs anzeigen (Standard: alle) |
| `shell [service]` | Shell im Container oeffnen (Standard: web) |
| `help` | Hilfe anzeigen |

### Beispiele

```bash
./scripts/docker.sh up-db
./scripts/docker.sh dbdump dumps/auth.sql oserp_auth
./scripts/docker.sh dbdump dumps/company.sql oserp_company
./scripts/docker.sh upstall
./scripts/docker.sh psql oserp_company
./scripts/docker.sh logs web
./scripts/docker.sh destroy-all
```

## SSL einrichten (Let's Encrypt)

### Voraussetzungen

- Domain zeigt per DNS A-Record auf den Server
- Port 80 und 443 sind offen
- `DOMAIN` und `CERTBOT_EMAIL` in `.env` gesetzt (nicht die Standardwerte!)

### Automatische Einrichtung

SSL wird **automatisch** eingerichtet wenn `DOMAIN` und `CERTBOT_EMAIL` in `.env`
konfiguriert sind (nicht auf `erp.example.com` / `admin@example.com`). Beim Start
des Web-Containers passiert folgendes:

1. Pruefen ob bereits ein Zertifikat vorhanden ist
2. Falls nein: Zertifikat automatisch per Certbot holen
3. SSL-Site aktivieren und Secure Cookies einschalten
4. Cronjob fuer automatische Erneuerung einrichten (alle 12h)

## Updates deployen

```bash
cd opensource-erp

# 1. Code aktualisieren
git pull

# 2. Web-Container neu bauen und starten
./scripts/docker.sh destroy-web
./scripts/docker.sh up-web
```

Die Datenbank bleibt erhalten — nur der Web-Container wird neu gebaut.

## Datenbank

### Zugriff mit pgAdmin

| Feld | Wert |
|------|------|
| Host | IP-Adresse oder Domain des Servers |
| Port | Wert von `DB_EXTERNAL_PORT` (Standard: 5433) |
| User | Wert von `POSTGRES_USER` (Standard: postgres) |
| Passwort | Wert von `POSTGRES_PASSWORD` |

### Backup erstellen

```bash
./scripts/docker.sh backup
```

Erstellt gzip-komprimierte Backups beider Datenbanken im Ordner `backups/`.

### Backup wiederherstellen

```bash
# Entpacken
gunzip backups/oserp_company_20260309_120000.sql.gz

# In DB laden
./scripts/docker.sh dbdump backups/oserp_company_20260309_120000.sql oserp_company
```

## Verzeichnisstruktur

```
docker/
├── docker-compose.yml          # Docker Compose Definition
├── .env.example                # Konfigurations-Vorlage
├── .env                        # Aktive Konfiguration (nicht in Git)
├── README.md                   # Diese Datei
├── SETUP_DEMO.md               # Ausfuehrliche Schritt-fuer-Schritt-Anleitung
├── web/
│   ├── Dockerfile              # Multi-Stage Build (Node + PHP)
│   ├── apache-http.conf        # Apache HTTP-Config (Port 80)
│   ├── apache-ssl.conf         # Apache SSL-Config (Port 443)
│   ├── entrypoint.sh           # Container-Start (settings.ini, Demo-Snapshot, SSL)
│   └── php.ini                 # PHP-Konfiguration
└── certbot/
    └── init-ssl.sh             # SSL-Zertifikat manuell holen (Fallback)
```

## Troubleshooting

### Container-Logs anschauen

```bash
./scripts/docker.sh logs
./scripts/docker.sh logs web
./scripts/docker.sh logs db
```

### Datenbank komplett zuruecksetzen

```bash
# DB-Container und Volume loeschen
./scripts/docker.sh destroy-db

# DB-Container neu starten
./scripts/docker.sh up-db

# Datenbanken und Schemas neu einrichten (siehe Schnellstart)
```

### SSL-Probleme

```bash
# Zertifikat pruefen
./scripts/docker.sh shell web
ls -la /etc/ssl/oserp/
exit

# SSL-Logs anschauen
./scripts/docker.sh logs web | grep -i ssl
```

### Port-Konflikte

```bash
# Belegten Port finden
sudo lsof -i :8080

# Alternative Ports in .env setzen:
# WEB_HTTP_PORT=9080
# WEB_HTTPS_PORT=9443
# DB_EXTERNAL_PORT=5434
```

### Alles zuruecksetzen (Neuanfang)

```bash
# ACHTUNG: Loescht ALLE Daten!
./scripts/docker.sh destroy-all

# Danach komplett neu einrichten (siehe Schnellstart)
```
