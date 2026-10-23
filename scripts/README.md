# Scripts — Entwickler-Hilfsskripte

Dieses Verzeichnis enthält alle Shell-Skripte für die lokale Entwicklung und
den Docker-Betrieb von OpensourceERP.

> **Alle Skripte werden vom Projektroot aus aufgerufen:**
> ```bash
> ./scripts/<skript>.sh
> ```

---

## Übersicht

| Skript | Zweck | Voraussetzungen |
|--------|-------|-----------------|
| `docker.sh` | Docker-Stack verwalten (Start, Stop, Backup, DB-Zugang, ...) | Docker Engine >= 20.10, `docker/.env` |
| `run-dev.sh` | Lokale Entwicklungsumgebung starten (ohne Docker) | PHP >= 8.0, Node.js, lokale PostgreSQL auf Port 5432 |
| `built.sh` | Repository aktualisieren, Frontend bauen, Abhängigkeiten prüfen und für Apache-Deployment vorbereiten | Node.js |

---

## docker.sh — Docker-Stack-Helfer

Zentrales Skript für alle Docker-Operationen. Abstrahiert den Pfad zur
`docker/docker-compose.yml` und die `--env-file`-Angabe, sodass man sich
keine langen `docker compose`-Befehle merken muss.

### Ersteinrichtung

```bash
# 1. Env-Datei erstellen
cp docker/.env.example docker/.env

# 2. Passwort und Ports in docker/.env anpassen
nano docker/.env

# 3. DB-Container starten und Datenbanken manuell einrichten
./scripts/docker.sh up-db

# 4. Web-Container starten
./scripts/docker.sh up-web
```

Details zur DB-Einrichtung (Datenbanken anlegen, Schemas laden etc.):
siehe `docker/SETUP_DEMO.md`.

### Alle Befehle

#### Container starten

| Befehl | Beschreibung |
|--------|-------------|
| `up-db` | DB-Container starten. |
| `up-web` | Web-Container starten. Baut das Image automatisch wenn nötig. |
| `up-all` | Alle Container starten. |

```bash
./scripts/docker.sh up-db
./scripts/docker.sh up-web
./scripts/docker.sh up-all
```

#### Container stoppen

| Befehl | Beschreibung |
|--------|-------------|
| `down-db` | DB-Container stoppen und entfernen. Volumes bleiben erhalten. |
| `down-web` | Web-Container stoppen und entfernen. |
| `down-all` | Alle Container stoppen. |

```bash
./scripts/docker.sh down-db
./scripts/docker.sh down-web
./scripts/docker.sh down-all
```

#### Container entfernen

| Befehl | Beschreibung |
|--------|-------------|
| `destroy-db` | **Destruktiv:** DB-Container, Volume (alle DB-Daten) und Image löschen. Fragt nach Bestätigung. |
| `destroy-web` | **Destruktiv:** Web-Container und Image löschen. Fragt nach Bestätigung. |
| `destroy-all` | **Destruktiv:** Alles löschen — Container, Volumes, Images. Fragt nach Bestätigung. |

```bash
./scripts/docker.sh destroy-db        # Bestätigung mit "ja"
./scripts/docker.sh destroy-web       # Bestätigung mit "ja"
./scripts/docker.sh destroy-all       # Bestätigung mit "ja"
```

#### Datenbank

| Befehl | Beschreibung |
|--------|-------------|
| `dbdump <datei> <db>` | SQL-Datei in eine Datenbank laden. Beide Parameter sind Pflicht. Relative Pfade werden gegen das Projektroot aufgelöst. Erkennt automatisch Auth-DBs und passt `auth.clients` an die Docker-Umgebung an (Host, DB-Name, Credentials). |
| `upstall` | Alle Erweiterungen aus `backend/upstall/` installieren. Erkennt automatisch Auth- und Company-Schemas anhand des Dateinamens (`auth_schema.sql` → Auth-DB, `company_schema.sql` → Company-DB). |
| `psql <db>` | PostgreSQL-Shell öffnen. Datenbankname ist Pflicht. |
| `backup` | Erstellt gzip-komprimierte Backups beider Datenbanken im Ordner `backups/`. |

Die Basis-Schemas (kivitendo) liegen nicht im Repository — eigene pg_dump-Dateien
verwenden. `upstall` ergänzt darauf nur die OpensourceERP-Tabellen.

```bash
./scripts/docker.sh dbdump dumps/auth.sql oserp_auth
./scripts/docker.sh dbdump dumps/company.sql oserp_company
./scripts/docker.sh upstall
./scripts/docker.sh psql oserp_company
./scripts/docker.sh backup
```

#### Sonstiges

| Befehl | Beschreibung |
|--------|-------------|
| `status` | Zeigt den Status aller Container sowie erreichbare URLs und Ports an. |
| `logs [service]` | Zeigt Container-Logs im Follow-Modus (letzte 100 Zeilen). Beenden mit `Ctrl+C`. |
| `shell [service]` | Öffnet eine Shell im Container. Standard: `web`. |
| `help` | Zeigt die eingebaute Hilfe an. |

```bash
./scripts/docker.sh status
./scripts/docker.sh logs web
./scripts/docker.sh shell db
./scripts/docker.sh help
```

### Vergleich: down vs. destroy

| | `down-*` | `destroy-*` |
|--|----------|-------------|
| Container gestoppt | ja | ja |
| Volumes gelöscht | nein | ja |
| Images gelöscht | nein | ja |
| Einsatz | Kurze Pause, Daten bleiben erhalten | Alles entfernen, danach manuell neu einrichten |

---

## run-dev.sh — Lokale Entwicklungsumgebung

Startet die vollständige Entwicklungsumgebung **ohne Docker** in einem
`gnome-terminal`-Fenster. Gedacht für die tägliche Frontend- und
Backend-Entwicklung mit Hot-Reload.

### Was das Skript macht

1. `git pull` — holt aktuelle Änderungen
2. Prüft ob PHP >= 8.0 installiert ist
3. `npm install` — installiert/aktualisiert Abhängigkeiten
4. Stoppt eventuell laufende alte Server-Prozesse
5. Öffnet ein neues Terminal mit drei parallelen Prozessen:
   - **PHP Built-in Server** auf `localhost:8000` (Backend-API)
   - **Vite Dev Server** mit Hot-Reload (Frontend, proxied `/api` nach `localhost:8000`)
   - **Log-Monitor** für PHP-Fehler und API-Debug-Logs

### Voraussetzungen

- PHP >= 8.0 mit Extensions: `pgsql`, `mbstring`, `xml`, `curl`
- Node.js 25
- Lokale PostgreSQL auf Port 5432
- `backend/config/settings.ini` muss konfiguriert sein
- `gnome-terminal` (GNOME-Desktop)

### Aufruf

```bash
./scripts/run-dev.sh
```

Beenden: `Ctrl+C` im geöffneten Terminal-Fenster.

---

## built.sh — Frontend bauen & bereitstellen

Holt den aktuellen Stand per `git pull`, installiert die Abhängigkeiten, baut
Vue-Frontend (Vite) und Shop-UI (esbuild), setzt die Dateiberechtigungen für
Apache und prüft zum Schluss alle Abhängigkeiten auf Sicherheitslücken und
Aktualität. Gedacht für den Entwicklungsrechner und für Nicht-Docker-Server.

### Was das Skript macht

0. `git pull`
1. `npm install`
1b. *(optional)* veraltete npm-Pakete auf die neueste Version heben, siehe unten
2. `npm run build` und `scripts/portal-ui-build.sh`
2c./2d. Abhängigkeiten von SSE-Server und Composer
3./4. Build-ID anzeigen, Rechte auf `775`/`664` setzen
5. `npm audit`, `composer audit`, `npm outdated` — Bericht ganz am Ende
6. Ergebnis des automatischen Paket-Updates

### Automatisches Paket-Update (Schritt 1b)

Standardmäßig meldet das Skript veraltete npm-Pakete nur. Auf dem
Entwicklungsrechner kann es sie selbst auf die neueste Version heben:

```bash
git config oserp.autoUpdate true     # einmalig, pro Clone, wird nicht eingecheckt
```

Danach läuft bei jedem Build `npm i <paket>@latest …` für alle echten
Rückstände (Pakete, deren `latest`-Tag rückwärts zeigt, bleiben außen vor).
Der anschließende Build ist der Test:

- **Build ok** → grüner Bericht am Ende mit der Liste alt → neu;
  `package.json` und `package-lock.json` sind geändert und müssen committet
  werden. Major-Sprünge werden eigens genannt, dort lohnt ein Blick in die
  Oberfläche.
- **Build scheitert** → `package.json`/`package-lock.json` werden
  zurückgesetzt, `npm install` stellt die alten Versionen wieder her, es wird
  erneut gebaut. Das System läuft weiter, am Ende steht ein roter Bericht mit
  den betroffenen Paketen; die Fehlerausgabe liegt in
  `build-fehler-update.log` (gitignored).

Auf Kundenrechnern bleibt der Schalter aus: dort würde die lokale Änderung an
`package.json` den nächsten `git pull` blockieren. Die neuen Versionen kommen
dort mit dem Pull.

### Voraussetzungen

- Node.js >= 20.19 (lokal 25)

### Aufruf

```bash
./scripts/built.sh
```

---

## Verwandte Skripte im `tools/`-Ordner

Diese Skripte liegen im `tools/`-Verzeichnis und sind nicht direkt Teil der
Entwicklungsumgebung, aber nützliche Helfer:

| Skript | Zweck |
|--------|-------|
| `tools/fix-ws.sh` | Bereinigt Whitespace in geänderten Dateien (Trailing Whitespace entfernen, Tabs zu Spaces, leere Zeilen am Ende entfernen). Sollte vor jedem Commit ausgeführt werden. Führt danach `git add -p`, `git commit` und `git push` aus. |
| `tools/encrypt_password.php` | Verschlüsselt ein Passwort per XOR für die Verwendung in `backend/config/settings.ini`. Aufruf: `php tools/encrypt_password.php "meinpasswort"` |
