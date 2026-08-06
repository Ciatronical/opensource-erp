# OpensourceERP — Server-Setup Schritt fuer Schritt

Diese Anleitung beschreibt die **komplette Einrichtung** von OpensourceERP auf einem
frischen Debian- oder Ubuntu-Server (z.B. Hetzner Cloud, Netcup, DigitalOcean).

Am Ende laeuft die Anwendung unter `https://demo.example.com` mit automatischem
SSL-Zertifikat von Let's Encrypt.

> **Beispiel-Domain in dieser Anleitung:** `demo.example.com`
> Ersetze diese ueberall durch deine eigene Domain.

---

## Inhaltsverzeichnis

1. [Voraussetzungen](#1-voraussetzungen)
2. [DNS einrichten (am Beispiel Hetzner)](#2-dns-einrichten-am-beispiel-hetzner)
3. [Server vorbereiten](#3-server-vorbereiten)
4. [Docker installieren](#4-docker-installieren)
5. [Repository klonen](#5-repository-klonen)
6. [Konfiguration (.env) erstellen](#6-konfiguration-env-erstellen)
7. [Firewall einrichten](#7-firewall-einrichten)
8. [DB-Container starten und Datenbank einrichten](#8-db-container-starten-und-datenbank-einrichten)
9. [Admin-Benutzer anlegen](#9-admin-benutzer-anlegen)
10. [Stammdaten und Demo-Daten laden](#10-stammdaten-und-demo-daten-laden)
11. [Web-Container starten](#11-web-container-starten)
12. [Demo-Modus](#12-demo-modus)
13. [Pruefen ob alles laeuft](#13-pruefen-ob-alles-laeuft)
14. [Erster Login](#14-erster-login)
15. [Updates einspielen](#15-updates-einspielen)
16. [Backups](#16-backups)
17. [Troubleshooting](#17-troubleshooting)
18. [Mehrere Instanzen auf einem Server (optional)](#18-mehrere-instanzen-auf-einem-server-optional)

---

## 1. Voraussetzungen

Was du brauchst, bevor du anfaengst:

| Was | Minimum | Empfohlen |
|-----|---------|-----------|
| Server | 1 vCPU, 2 GB RAM, 20 GB SSD | 2 vCPU, 4 GB RAM, 40 GB SSD |
| Betriebssystem | Debian 12 oder Ubuntu 22.04/24.04 | Debian 12 (Bookworm) |
| Domain | Eine eigene Domain (z.B. bei Hetzner, Netcup, INWX) | — |
| SSH-Zugang | Root-Zugang oder Benutzer mit sudo-Rechten | SSH-Key statt Passwort |

**Kein Docker-Wissen noetig** — diese Anleitung erklaert jeden Schritt.

---

## 2. DNS einrichten (am Beispiel Hetzner)

Bevor der Server etwas tun kann, muss die Domain auf den Server zeigen.
Das passiert ueber einen **DNS A-Record**.

### Was ist ein A-Record?

Ein A-Record verknuepft einen Domainnamen (z.B. `demo.example.com`) mit einer
IP-Adresse (z.B. `123.45.67.89`). Wenn jemand `demo.example.com` im Browser
eingibt, fragt der Browser den DNS-Server "Welche IP gehoert zu dieser Domain?"
und bekommt `123.45.67.89` als Antwort.

### Schritt fuer Schritt (Hetzner Console)

> **Hinweis:** Hetzner hat das DNS-Management in die neue **Hetzner Console**
> (`console.hetzner.com`) umgezogen. Die alte DNS Console (`dns.hetzner.com`)
> wird im Mai 2026 abgeschaltet. Falls deine Zonen noch dort liegen, musst du
> sie zuerst migrieren (Button "Migrate" bei der jeweiligen Zone).

1. Logge dich ein unter: https://console.hetzner.com
2. Klicke im linken Menue auf **"DNS"**
3. Klicke auf deine Domain (z.B. `example.com`)
4. Klicke auf **"Add Record"** (oder "Eintrag hinzufuegen")
5. Fuelle die Felder aus:

   | Feld | Wert | Erklaerung |
   |------|------|------------|
   | **Typ** | `A` | IPv4-Adresse |
   | **Name** | `demo` | Die Subdomain (ergibt zusammen `demo.example.com`) |
   | **Wert** | `123.45.67.89` | Die **IP-Adresse deines Servers** |
   | **TTL** | `300` | Aktualisierungszeit in Sekunden (5 Minuten) |

6. Klicke auf **"Add Record"**

### Bei anderen DNS-Anbietern

Die Felder heissen ueberall leicht anders, aber das Prinzip ist identisch:

| Anbieter | Wo | Name-Feld | Wert-Feld |
|----------|----|-----------|-----------|
| **Netcup** | CCP → Domains → DNS | Host | Destination |
| **INWX** | Nameserver → Records | Name | Value |
| **Cloudflare** | DNS → Records | Name | IPv4 address |
| **Strato** | Domains → DNS-Verwaltung | Subdomain | Wert |

### Pruefen ob der DNS-Eintrag funktioniert

Warte 2-5 Minuten nach dem Erstellen, dann pruefe auf deinem lokalen Rechner:

```bash
# Auf deinem lokalen Rechner (nicht auf dem Server) ausfuehren:
ping demo.example.com
```

**Erwartete Ausgabe:**
```
PING demo.example.com (123.45.67.89): 56 data bytes
64 bytes from 123.45.67.89: icmp_seq=0 ttl=55 time=12.3 ms
```

Wenn die richtige IP-Adresse erscheint, funktioniert der DNS-Eintrag.

> **Achtung:** DNS-Aenderungen koennen bis zu 24 Stunden dauern, bei neuen
> Eintraegen mit TTL 300 sind es aber meistens nur 2-10 Minuten.

> **Wichtig:** Das SSL-Zertifikat kann nur geholt werden, wenn der DNS-Eintrag
> bereits funktioniert. Starte den Web-Container erst, wenn `ping` die richtige
> IP anzeigt.

---

## 3. Server vorbereiten

Verbinde dich per SSH mit deinem Server:

```bash
ssh root@123.45.67.89
# oder wenn du einen Benutzer mit sudo-Rechten hast:
ssh benutzer@123.45.67.89
```

Zuerst das System aktualisieren:

```bash
sudo apt update && sudo apt upgrade -y
```

Git installieren (wird fuer das Repository benoetigt):

```bash
sudo apt install -y git
```

---

## 4. Docker installieren

Docker wird mit dem offiziellen Installationsskript installiert.
Das funktioniert auf Debian und Ubuntu identisch:

```bash
# Docker installieren
curl -fsSL https://get.docker.com | sh
```

Warte bis die Installation abgeschlossen ist (1-2 Minuten).

### Docker ohne sudo nutzbar machen

Falls du **nicht als root** angemeldet bist, musst du deinen Benutzer zur
Docker-Gruppe hinzufuegen:

```bash
# Benutzer zur Docker-Gruppe hinzufuegen
sudo usermod -aG docker $USER

# WICHTIG: Ab- und wieder anmelden, damit die Gruppe aktiv wird!
exit
```

Dann erneut per SSH verbinden und pruefen:

```bash
ssh benutzer@123.45.67.89

# Pruefen ob Docker funktioniert:
docker --version
docker compose version
```

**Erwartete Ausgabe (Versionen koennen abweichen):**
```
Docker version 27.x.x, build ...
Docker Compose version v2.x.x
```

> **Haeufiger Fehler:** `permission denied while trying to connect to the Docker daemon socket`
> → Du hast dich nach `usermod` nicht ab- und wieder angemeldet.
> Loesung: `exit` und erneut per SSH verbinden.

---

## 5. Repository klonen

```bash
# In das Home-Verzeichnis wechseln
cd ~

# Repository klonen
git clone https://github.com/DEIN-GITHUB-USER/opensource-erp.git

# In das Projektverzeichnis wechseln
cd opensource-erp
```

> **Hinweis:** Ersetze die URL durch die tatsaechliche Repository-URL.

Helfer-Skript ausfuehrbar machen:

```bash
chmod +x scripts/docker.sh
```

Pruefe ob alles da ist:

```bash
ls docker/
# Erwartete Ausgabe: docker-compose.yml  .env.example  web/  db/  certbot/  README.md  ...
```

---

## 6. Konfiguration (.env) erstellen

Die `.env`-Datei enthaelt alle Einstellungen fuer den Docker-Stack.
Du erstellst sie aus der Vorlage und passt sie an.

### 6.1 Datei erstellen

```bash
cp docker/.env.example docker/.env
```

### 6.2 Datei bearbeiten

```bash
nano docker/.env
```

### 6.3 Was muss geaendert werden?

Hier ist die komplette `.env` mit Erklaerungen. Die mit **AENDERN** markierten
Zeilen **muessen** angepasst werden:

```ini
# ── Stack-Name (nur aendern bei mehreren Instanzen auf einem Server) ──
STACK_NAME=oserp

# ── AENDERN: Deine Domain (ohne https://, ohne Schraegstrich am Ende) ──
DOMAIN=demo.example.com

# ── AENDERN: Deine E-Mail fuer Let's Encrypt SSL-Zertifikat ──
CERTBOT_EMAIL=admin@example.com

# ── PostgreSQL ──
POSTGRES_USER=postgres
# ── AENDERN: Sicheres Passwort setzen! ──
POSTGRES_PASSWORD=MeinSuperGeheimesPasswort2026!
DB_AUTH_NAME=oserp_auth
DB_COMPANY_NAME=oserp_company

# ── AENDERN: Externer DB-Port (5433 ist gut, NICHT 5432) ──
DB_EXTERNAL_PORT=5433

# ── App-Einstellungen (koennen so bleiben) ──
APP_TIMEZONE=Europe/Berlin
APP_DEBUG=false

# ── AENDERN: Ports auf 80/443 setzen fuer Produktivbetrieb mit SSL! ──
WEB_HTTP_PORT=80
WEB_HTTPS_PORT=443

# ── Session (kann so bleiben) ──
SESSION_COOKIE_NAME=opensource_erp
SESSION_COOKIE_SAMESITE=Strict

# ── Demo-Modus (optional) ──
DEMO_MODE=true
DEMO_INACTIVITY_MINUTES=20
```

Speichern: `Ctrl+O` → `Enter` → `Ctrl+X`

### Wichtige Hinweise zur Konfiguration

> **DOMAIN** — Nur der Domainname, KEIN `https://` davor, KEIN `/` dahinter.
> Richtig: `demo.example.com` | Falsch: `https://demo.example.com/`

> **WEB_HTTP_PORT und WEB_HTTPS_PORT** — Fuer SSL muessen diese auf `80` und
> `443` stehen! Die Standardwerte `8080`/`8443` aus der Vorlage sind fuer
> lokale Entwicklung gedacht.

> **POSTGRES_USER** — Muss `postgres` bleiben! Das Datenbankschema enthaelt
> `OWNER TO postgres`-Statements.

> **POSTGRES_PASSWORD** — Verwende ein sicheres Passwort (mindestens 16 Zeichen,
> Buchstaben + Zahlen + Sonderzeichen). Dieses Passwort wird intern verwendet,
> du musst es dir nicht merken.

> **DEMO_MODE** — Wenn `true`, erstellt der Web-Container beim Start einen
> Datenbank-Snapshot. Nach Inaktivitaet (konfigurierbar ueber
> `DEMO_INACTIVITY_MINUTES`) wird die Datenbank automatisch auf diesen
> Snapshot zurueckgesetzt. Ideal fuer oeffentliche Demo-Instanzen.

---

## 7. Firewall einrichten

Eine Firewall schuetzt den Server vor ungewollten Zugriffen. Wir oeffnen nur
die Ports die wirklich gebraucht werden.

```bash
# UFW installieren (falls nicht vorhanden)
sudo apt install -y ufw

# Standardregeln setzen: Alles blockieren, ausgehend erlauben
sudo ufw default deny incoming
sudo ufw default allow outgoing

# SSH erlauben (WICHTIG: Sonst sperrst du dich aus!)
sudo ufw allow 22/tcp comment 'SSH'

# HTTP und HTTPS erlauben (fuer die Webanwendung und SSL-Zertifikat)
sudo ufw allow 80/tcp comment 'HTTP'
sudo ufw allow 443/tcp comment 'HTTPS'

# Firewall aktivieren
sudo ufw enable
# Bestaetigen mit: y
```

Pruefe die Regeln:

```bash
sudo ufw status verbose
```

> **Wichtig:** Port `5433` (Datenbank) ist absichtlich NICHT geoeffnet.
> Falls du von aussen mit pgAdmin zugreifen willst, oeffne den Port
> temporaer: `sudo ufw allow 5433/tcp` — aber schliesse ihn danach wieder!

> **Achtung:** Wenn dein SSH auf einem anderen Port laeuft (z.B. 2222),
> passe die Regel an: `sudo ufw allow 2222/tcp comment 'SSH'`
> **BEVOR** du die Firewall aktivierst! Sonst sperrst du dich aus.

---

## 8. DB-Container starten und Datenbank einrichten

Der DB-Container startet als **leeres PostgreSQL** — Datenbanken, Schemas und
Daten muessen manuell eingerichtet werden. Das macht den Stack flexibel bei
Schema-Aenderungen.

### 8.1 DB-Container starten

```bash
cd ~/opensource-erp
./scripts/docker.sh up-db
```

Warte ein paar Sekunden, dann pruefen:

```bash
./scripts/docker.sh status
# DB-Container muss laufen
```

### 8.2 Datenbanken anlegen

```bash
./scripts/docker.sh psql postgres
```

In der PostgreSQL-Shell:

```sql
CREATE DATABASE oserp_auth;
CREATE DATABASE oserp_company;
\q
```

### 8.3 Basis-Schemas laden (kivitendo)

Die Basisstruktur stammt aus kivitendo (`auth.clients`, `customer`, `parts`,
`ar` …) und liegt **nicht im Repository** — sie muss als eigener pg_dump
mitgebracht werden, entweder aus einer kivitendo-Installation oder vom
Produktivserver:

```bash
pg_dump --no-owner --no-privileges kivitendo_auth > dumps/auth.sql
pg_dump --no-owner --no-privileges <firmen_db>    > dumps/company.sql
```

Anschließend einspielen (Pfade an die eigenen Dateien anpassen):

```bash
./scripts/docker.sh dbdump dumps/auth.sql oserp_auth
./scripts/docker.sh dbdump dumps/company.sql oserp_company
```

> **Hinweis:** `dbdump` erkennt automatisch Auth-Datenbanken und passt die
> Verbindungsdaten in `auth.clients` an die Docker-Umgebung an (Host, DB-Name,
> Credentials aus `.env`). Ein manuelles Anpassen ist nicht noetig.

### 8.4 Erweiterungen installieren

```bash
./scripts/docker.sh upstall
```

Installiert alle SQL-Erweiterungen aus `backend/upstall/` (CRM, lxcars, skr03,
skr04) automatisch in die richtigen Datenbanken. Dieser Schritt ergänzt nur die
OpensourceERP-Tabellen und setzt die Basis aus 8.3 voraus — insbesondere das
`auth`-Schema.

### 8.5 CSV-Referenzdaten laden

Die CSV-Dateien (Bankleitzahlen, Geschlecht aus Vornamen) liegen im Repository
und muessen in den DB-Container kopiert werden:

```bash
# Dateien in den Container kopieren
docker cp backend/upstall/crm/company_data/blz_de.csv oserp-db:/tmp/
docker cp backend/upstall/crm/company_data/firstnametogender.csv oserp-db:/tmp/

# In die Datenbank importieren
./scripts/docker.sh psql oserp_company
```

In der PostgreSQL-Shell:

```sql
\copy blz_de FROM '/tmp/blz_de.csv' WITH (FORMAT csv, HEADER true);
\copy firstnametogender FROM '/tmp/firstnametogender.csv' WITH (FORMAT csv, HEADER true);
\q
```

> **Hinweis:** Falls `STACK_NAME` in der `.env` geaendert wurde, den
> Container-Namen entsprechend anpassen (z.B. `demo1-db` statt `oserp-db`).

---

## 9. Admin-Benutzer anlegen

Es wird kein Admin-Benutzer automatisch erstellt. Der erste Benutzer muss
manuell angelegt werden.

### 9.1 Passwort-Hash erzeugen

Der Web-Container muss dafuer laufen:

```bash
./scripts/docker.sh up-web
```

Dann den Hash generieren (ersetze `MeinPasswort` durch dein gewuenschtes Passwort):

```bash
docker exec oserp-web php -r '
    require "/var/www/html/backend/api/password.php";
    echo generate_password_hash("admin", "MeinPasswort") . "\n";
'
```

Kopiere den ausgegebenen Hash (langer String).

### 9.2 Benutzer in der Datenbank anlegen

```bash
./scripts/docker.sh psql oserp_auth
```

In der PostgreSQL-Shell (ersetze `<HASH>` durch den kopierten Hash):

```sql
-- Benutzer erstellen
INSERT INTO auth.user (login, password)
VALUES ('admin', '<HASH>');

-- Dem Mandanten zuordnen (id=1)
INSERT INTO auth.clients_users (client_id, user_id)
VALUES (1, (SELECT id FROM auth.user WHERE login = 'admin'));

-- Der Admin-Gruppe zuordnen (id=1 = Vollzugriff)
INSERT INTO auth.user_group (user_id, group_id)
VALUES ((SELECT id FROM auth.user WHERE login = 'admin'), 1);

\q
```

---

## 10. Stammdaten und Demo-Daten laden

Falls Stammdaten (Kontenrahmen, Steuerkonfiguration etc.) oder Demo-Daten
(Kunden, Artikel etc.) als SQL-Dateien vorliegen, koennen diese per `dbdump`
geladen werden:

```bash
./scripts/docker.sh dbdump pfad/zu/stammdaten.sql oserp_company
./scripts/docker.sh dbdump pfad/zu/demo_daten.sql oserp_company
```

---

## 11. Web-Container starten

Falls der Web-Container nicht schon laeuft (Schritt 9.1):

```bash
./scripts/docker.sh up-web
```

### Was passiert beim Start?

1. **settings.ini** wird aus den Umgebungsvariablen generiert (DB-Verbindung,
   Session-Einstellungen, Timezone)
2. **Demo-Modus** (wenn `DEMO_MODE=true`): Ein `pg_dump`-Snapshot der
   Company-Datenbank wird erstellt
3. **PHP-FPM** startet als Daemon
4. **SSL-Zertifikat** wird automatisch geholt (wenn `DOMAIN` und
   `CERTBOT_EMAIL` konfiguriert sind)
5. **Apache** startet im Vordergrund

> **Wichtig bei Demo-Modus:** Die Datenbank muss **vollstaendig eingerichtet**
> sein, bevor der Web-Container gestartet wird! Der Snapshot wird beim Start
> erstellt und dient als Grundlage fuer den automatischen Reset.

---

## 12. Demo-Modus

Der Demo-Modus ist ideal fuer oeffentliche Demo-Instanzen. Er stellt die
Datenbank nach Inaktivitaet automatisch auf den Ausgangszustand zurueck.

### Wie funktioniert es?

1. **Beim Start** des Web-Containers wird ein `pg_dump`-Snapshot der
   Company-Datenbank erstellt und unter
   `/var/www/html/backend/data/demo_snapshot.sql` gespeichert
2. **Bei Inaktivitaet** (konfigurierbar, Standard: 20 Minuten) wird die
   Company-Datenbank automatisch auf diesen Snapshot zurueckgesetzt
3. Die Auth-Datenbank wird **nicht** zurueckgesetzt — Benutzer bleiben erhalten

### Konfiguration

In `docker/.env`:

```ini
DEMO_MODE=true
DEMO_INACTIVITY_MINUTES=20
```

### Snapshot aktualisieren

Wenn du Aenderungen an der Datenbank vorgenommen hast und den Snapshot
aktualisieren willst:

```bash
# Web-Container neu starten (erstellt neuen Snapshot)
./scripts/docker.sh down-web
./scripts/docker.sh up-web
```

---

## 13. Pruefen ob alles laeuft

### Container-Status

```bash
./scripts/docker.sh status
```

**Erwartete Ausgabe:**
```
Container-Status:

NAME        IMAGE              STATUS                    PORTS
oserp-web   ...                Up X minutes (healthy)    0.0.0.0:80->80/tcp, 0.0.0.0:443->443/tcp
oserp-db    postgres:16-alpine Up X minutes (healthy)    0.0.0.0:5433->5432/tcp

Web erreichbar unter: http://localhost:80
DB erreichbar unter:  localhost:5433
```

Beide Container muessen den Status `healthy` zeigen.

### Im Browser testen

Oeffne im Browser:

```
https://demo.example.com
```

Du solltest die Login-Seite von OpensourceERP sehen.

### SSL-Zertifikat pruefen

```bash
curl -sI https://demo.example.com | head -5
```

**Erwartete Ausgabe:**
```
HTTP/2 200
...
```

---

## 14. Erster Login

1. Oeffne `https://demo.example.com` im Browser
2. Logge dich ein mit den Zugangsdaten die du in Schritt 9 angelegt hast
3. Aendere das Passwort in der Anwendung (oben rechts auf Benutzernamen klicken)

---

## 15. Updates einspielen

Wenn es neue Versionen im Git-Repository gibt:

```bash
cd ~/opensource-erp

# 1. Aktuelle Aenderungen holen
git pull

# 2. Web-Container neu bauen und starten
./scripts/docker.sh destroy-web
./scripts/docker.sh up-web
```

Das baut das Frontend neu, kopiert den aktuellen Backend-Code und startet
den Container. **Die Datenbank und alle Daten bleiben erhalten.**

> **Bei Demo-Modus:** Der Snapshot wird nach dem Neustart automatisch
> aktualisiert.

---

## 16. Backups

### Backup erstellen

```bash
./scripts/docker.sh backup
```

Erstellt gzip-komprimierte Backups beider Datenbanken im Ordner `backups/`:

```
backups/oserp_auth_20260309_141500.sql.gz
backups/oserp_company_20260309_141500.sql.gz
```

### Backup wiederherstellen

```bash
# Entpacken
gunzip backups/oserp_company_20260309_141500.sql.gz

# In DB laden
./scripts/docker.sh dbdump backups/oserp_company_20260309_141500.sql oserp_company
```

### Automatische Backups einrichten (optional)

```bash
# Crontab oeffnen
crontab -e

# Diese Zeile am Ende einfuegen (Backup taeglich um 3:00 Uhr):
0 3 * * * /root/opensource-erp/scripts/docker.sh backup >> /root/opensource-erp/backups/cron.log 2>&1
```

> **Tipp:** Kopiere die Backup-Dateien regelmaessig auf einen anderen Server
> oder in einen Cloud-Speicher.

---

## 17. Troubleshooting

### Container starten nicht

```bash
# Detaillierte Logs anzeigen:
./scripts/docker.sh logs
./scripts/docker.sh logs web
./scripts/docker.sh logs db
```

### "Permission denied" bei Docker-Befehlen

```
permission denied while trying to connect to the Docker daemon socket
```

Dein Benutzer ist nicht in der Docker-Gruppe:

```bash
sudo usermod -aG docker $USER
exit
# Erneut per SSH verbinden
```

### SSL-Zertifikat wird nicht geholt

Pruefe der Reihe nach:

1. **DNS:** `ping demo.example.com` — muss die Server-IP zeigen
2. **Firewall:** `sudo ufw status` — Port 80 und 443 muessen offen sein
3. **Ports:** `WEB_HTTP_PORT` muss `80` sein (nicht `8080`)
4. **Domain:** `DOMAIN` darf nicht `erp.example.com` sein
5. **Anderer Webserver:** `sudo lsof -i :80` — kein Apache/Nginx laufen lassen

Nach der Korrektur Web-Container neu starten:

```bash
./scripts/docker.sh down-web
./scripts/docker.sh up-web
```

### Datenbank-Fehler

```bash
# DB-Logs pruefen
./scripts/docker.sh logs db

# Komplett-Reset (LOESCHT ALLE DATEN):
./scripts/docker.sh destroy-db
./scripts/docker.sh up-db
# Danach Datenbanken und Schemas neu einrichten (siehe Abschnitt 8)
```

### Port-Konflikt

```
Bind for 0.0.0.0:80 failed: port is already allocated
```

```bash
# Herausfinden wer den Port belegt:
sudo lsof -i :80

# Haeufig: vorinstalliertes Apache oder Nginx
sudo systemctl stop apache2 nginx
sudo systemctl disable apache2 nginx
```

### Alles zuruecksetzen (Neuanfang)

```bash
# ACHTUNG: Loescht ALLE Daten (Datenbank, Uploads, Logs, SSL-Zertifikate)!
./scripts/docker.sh destroy-all

# Danach komplett neu einrichten ab Abschnitt 8
```

---

## 18. Mehrere Instanzen auf einem Server (optional)

Falls du mehrere OpensourceERP-Instanzen parallel auf dem gleichen Server
betreiben willst (z.B. `demo1.example.com` und `demo2.example.com`), ist
das mit der `STACK_NAME`-Variable moeglich.

### Voraussetzungen

- Ein bestehender Webserver (Apache oder Nginx) auf dem Host als **Reverse Proxy**
- SSL-Zertifikate werden vom **Host-Webserver** via Certbot geholt — nicht
  vom Docker-Container

### Schritt fuer Schritt

**1. Repository fuer jede Instanz separat klonen**

```bash
sudo mkdir -p /var/www/docker
cd /var/www/docker
sudo git clone <REPO_URL> demo1.example.com
sudo git clone <REPO_URL> demo2.example.com
chmod +x /var/www/docker/demo1.example.com/scripts/docker.sh
chmod +x /var/www/docker/demo2.example.com/scripts/docker.sh
```

**2. Jede Instanz separat konfigurieren**

Instanz 1 (`demo1.example.com/docker/.env`):
```ini
STACK_NAME=demo1
DOMAIN=erp.example.com
WEB_HTTP_PORT=8081
WEB_HTTPS_PORT=8441
DB_EXTERNAL_PORT=15433
POSTGRES_PASSWORD=Passwort1...
```

Instanz 2 (`demo2.example.com/docker/.env`):
```ini
STACK_NAME=demo2
DOMAIN=erp.example.com
WEB_HTTP_PORT=8082
WEB_HTTPS_PORT=8442
DB_EXTERNAL_PORT=15434
POSTGRES_PASSWORD=Passwort2...
```

> **Wichtig — DOMAIN:** `DOMAIN` bleibt auf dem Standardwert `erp.example.com`,
> damit der Container **kein eigenes SSL-Zertifikat** holt. SSL wird vom
> Host-Webserver terminiert.

> **Wichtig — Ports:** Jede Instanz braucht eigene Ports!

**3. Stacks starten und Datenbanken einrichten**

Fuer jede Instanz separat:

```bash
cd /var/www/docker/demo1.example.com
./scripts/docker.sh up-db
# Datenbanken anlegen und Schemas laden (siehe Abschnitt 8)
# Admin-Benutzer anlegen (siehe Abschnitt 9)
./scripts/docker.sh up-web
```

**4. DNS-Eintraege und Reverse Proxy einrichten**

Erstelle fuer jede Subdomain einen A-Record (siehe Abschnitt 2).

**Nginx-Variante** (`/etc/nginx/sites-available/demo1.example.com`):

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name demo1.example.com;

    location ^~ /.well-known/acme-challenge/ {
        default_type "text/plain";
        try_files $uri =404;
    }

    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/demo1.example.com /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

**5. SSL-Zertifikate holen**

```bash
sudo certbot --nginx -d demo1.example.com
sudo certbot --nginx -d demo2.example.com
```

### Updates einspielen (Multi-Instanz)

```bash
cd /var/www/docker/demo1.example.com && git pull && ./scripts/docker.sh destroy-web && ./scripts/docker.sh up-web
cd /var/www/docker/demo2.example.com && git pull && ./scripts/docker.sh destroy-web && ./scripts/docker.sh up-web
```

---

## Checkliste

- [ ] Server bestellt und per SSH erreichbar
- [ ] IP-Adresse des Servers notiert
- [ ] DNS A-Record erstellt und funktioniert (`ping demo.example.com`)
- [ ] Docker installiert (`docker --version` funktioniert)
- [ ] Repository geklont und Skript ausfuehrbar (`chmod +x scripts/docker.sh`)
- [ ] `.env` erstellt und angepasst (DOMAIN, CERTBOT_EMAIL, POSTGRES_PASSWORD, Ports 80/443)
- [ ] Firewall eingerichtet (Port 22, 80, 443 offen)
- [ ] DB-Container gestartet (`./scripts/docker.sh up-db`)
- [ ] Datenbanken angelegt (oserp_auth, oserp_company)
- [ ] Schemas geladen (auth + company + CRM-Extensions)
- [ ] CSV-Referenzdaten importiert
- [ ] Admin-Benutzer angelegt
- [ ] Stammdaten/Demo-Daten geladen
- [ ] Web-Container gestartet (`./scripts/docker.sh up-web`)
- [ ] Beide Container "healthy" (`./scripts/docker.sh status`)
- [ ] `https://demo.example.com` im Browser erreichbar
- [ ] Login mit Admin-Zugangsdaten funktioniert
