# storage_backup — Borg-Backup zur Hetzner Storage Box

Verschlüsseltes, dedupliziertes System-Backup pro Server mit [BorgBackup](https://www.borgbackup.org/)
gegen eine Hetzner Storage Box. Sichert das komplette Dateisystem (`/`) plus vorher erstellte
PostgreSQL-/MySQL-Dumps, mit automatischer Retention und robustem Umgang mit der nächtlichen
DSL-Zwangstrennung. Dieselben Skripte laufen auf allen OSERP-Rechnern.

## Überblick

- **Ziel:** `ssh://<BACKUP_USER>@<BACKUP_USER>.your-storagebox.de:23/./backups/<REPOSITORY_DIR>`
- **Verschlüsselung:** `repokey` (Schlüssel im Repo, per Passphrase geschützt)
- **Läuft als:** `root` (der SSH-Key aus `/root/.ssh/` muss auf der Storage Box autorisiert sein)
- **Ablauf je Lauf:** PostgreSQL- + MySQL-Dumps → `borg create` von `/` (mit Excludes) → `borg prune` + `borg compact`

## Dateien

| Datei | Zweck |
|-------|-------|
| `backup.sh` | Hauptskript: DB-Dumps, `borg create`, Retention. Optionen siehe unten. |
| `backup.env` | **Zugangsdaten** (Passphrase, Konto, Excludes). **Nicht im Git** — aus Vorlage erstellen. |
| `backup.env.default` | Vorlage ohne echte Passphrase (versioniert). |
| `borg-uebersicht` | Statusübersicht über alle Server (Tabelle, optional Mail-Report). |
| `borg-status-local.sh` | Liefert den Status des lokalen Repos als JSON (von `borg-uebersicht` aufgerufen). |
| `progress.sh` | Live-Fortschritt eines laufenden Backups (gelesene Bytes / Gesamtgröße). |
| `first-backup-supervisor.sh` | Auto-Resume-Wächter für den ersten (großen) Vollupload: fängt Abbruch/Zwangstrennung ab und setzt fort. |

## Einrichtung auf einem neuen Server

Der OSERP-Installer erledigt das im Schritt `borg` (`./install/install.sh --only borg`):

1. Skripte aus `install/storage_backup/` nach `/usr/local/sbin/storage_backup/` kopieren
   (eine vorhandene `backup.env` wird **nie** überschrieben), Verzeichnisse
   `data/postgresql-dbs`, `data/mysql-dbs` und `/var/log/storage_backup` anlegen.
2. Fehlt `backup.env`, fragt der Installer im Terminal Storage-Box-Konto, Repository-Verzeichnis,
   Passphrase und Alarm-Mail ab und schreibt sie aus der Vorlage (0600, root). Ohne Terminal
   bleibt die Vorlage mit Platzhaltern liegen — oder eine fertige `backup.env` wird von einem
   anderen Rechner hinkopiert.
3. root-SSH-Key anlegen (falls keiner da ist) und per `install-ssh-key` auf der Storage Box
   eintragen (einmalige Passwortabfrage des Kontos).
4. `/etc/cron.d/oserp-backup` (täglich als root, Uhrzeit per `OSERP_BACKUP_TIME`, Vorgabe 02:30),
   sudoers-Regel für `borg-status-local.sh`, `borg-uebersicht` nach `/usr/local/bin`.
5. Erste Sicherung: `sudo /usr/local/sbin/storage_backup/backup.sh INIT` (oder dem Installer
   zustimmen, dann läuft sie im Hintergrund — Verlauf in `/var/log/storage_backup/backup.log`).

Von Hand ohne Installer:

```bash
sudo install -d /usr/local/sbin/storage_backup /var/log/storage_backup
sudo cp install/storage_backup/* /usr/local/sbin/storage_backup/
cd /usr/local/sbin/storage_backup
sudo cp backup.env.default backup.env && sudo chmod 600 backup.env && sudo $EDITOR backup.env
sudo ssh-keygen -t ed25519 -N "" -f /root/.ssh/id_ed25519
sudo sh -c 'ssh -p23 <BACKUP_USER>@<BACKUP_USER>.your-storagebox.de install-ssh-key < /root/.ssh/id_ed25519.pub'
sudo ./backup.sh INIT
```

## Benutzung `backup.sh`

```
./backup.sh            Normales Backup (Repo wird bei Bedarf automatisch angelegt)
./backup.sh -v         Verbose (borg mit --list --filter=AME)
./backup.sh INIT       Repo neu anlegen (bricht ab, falls schon vorhanden)
./backup.sh NEW_INIT   GEFÄHRLICH: Repo komplett löschen und neu anlegen
./backup.sh <suffix>   Beliebiger Suffix an den Archivnamen anhängen
./backup.sh -h         Hilfe
```

Das Skript muss **als root** laufen (`sudo`), sonst bricht es früh ab (Paketliste nach
`/var/software.list`, `su - postgres` für die Dumps, SSH-Key von root).

> **Warnung:** `NEW_INIT` gehört **niemals** in einen Cron — es löscht die gesamte
> Backup-Historie. (Genau das hat auf einem Altsystem alle Archive vernichtet.)

**Borg-Exit-Codes:** `0` = OK, `1` = Warnung (Archiv gültig, z. B. „file changed while we
backed it up" bei laufenden Programmen), `≥2` = echter Fehler. Nur `≥2` alarmiert per Mail.

## Statusübersicht

```bash
borg-uebersicht              # Tabelle im Terminal (letztes Backup, Alter, Archive, Größe, Status)
borg-uebersicht --mail       # Report zusätzlich per E-Mail (REPORT_MAIL, sonst ALERT_EMAIL aus backup.env)
borg-uebersicht --overdue 12 # Schwelle "überfällig" auf 12 h setzen (Default 26)
```

Der lokale Rechner steht immer in der Liste; weitere Server kommen in
`/etc/borg-uebersicht.servers` (eine Zeile `Name|user@host` je Server). Jeder Server wird
**passwortlos** über `borg-status-local.sh` abgefragt (NOPASSWD-sudoers-Eintrag
`/etc/sudoers.d/borg-status`, nur genau dieses Skript). So braucht kein zentraler Ort die
Passphrasen der anderen Server.

**Täglicher Mail-Report** (abschaltbar per Schalterdatei):

```bash
sudo touch /etc/borg-report.enabled   # AN
sudo rm    /etc/borg-report.enabled   # AUS
```

Der Cron dazu liegt in `/etc/cron.d/borg-report` (läuft als Betriebs-User, damit dessen
SSH-Keys/-Config für die Remote-Server greifen).

## Fortschritt eines laufenden Backups

```bash
sudo /usr/local/sbin/storage_backup/progress.sh
```

## Rücksicherung (Restore)

```bash
REPO="ssh://<BACKUP_USER>@<BACKUP_USER>.your-storagebox.de:23/./backups/<REPOSITORY_DIR>"

borg list "$REPO"                       # Archive auflisten
cd /mytmp && borg extract "$REPO::<ARCHIV>" etc var usr ...   # einzelne Pfade
cd /      && borg extract "$REPO::<ARCHIV>"                   # komplett
borg mount "$REPO::<ARCHIV>" /mnt/borg-restore               # als Dateisystem (braucht python3-pyfuse3)
```

Die PostgreSQL-Dumps liegen im Backup unter `usr/local/sbin/storage_backup/data/postgresql-dbs/`
(das eigentliche `/var/lib/postgresql` sollte in `backup.env` exkludiert sein — Restore läuft
über die `.sql`-Dumps).

## Sicherheit

- `backup.env` und **alle Varianten/Kopien** (`backup.env.*`, `*~`, `*.bak*`) sind per
  `.gitignore` vom Repo ausgeschlossen — die Passphrase darf **niemals** ins Repository.
- Nur `backup.env.default` (ohne echte Passphrase) ist versioniert.
- Bei `repokey` liegt der Schlüssel verschlüsselt im Repo; **Passphrase sicher aufbewahren** —
  ohne sie ist kein Restore möglich.
