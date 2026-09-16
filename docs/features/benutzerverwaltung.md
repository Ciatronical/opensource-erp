# Benutzer und Firmen — Systemadministration

OSERP verwaltet Benutzer, Berechtigungsgruppen und Firmen (Mandanten) selbst. Ein k9o-`admin.pl` ist nicht mehr nötig — die Datenstruktur bleibt aber vollständig k9o-kompatibel, sodass eine bestehende Installation weiter parallel genutzt werden kann.

**Wo:** Firmenmenü oben rechts (Klick auf den Firmennamen) → **Benutzer und Firmen**. Sichtbar nur für Systemadministratoren.

## Wie alles zusammenhängt

| Begriff | Bedeutung |
|---------|-----------|
| **Benutzer** | Meldet sich mit Anmeldename und Passwort an. Stammdaten (Name, E-Mail, Sprache, Formate) liegen k9o-kompatibel in `auth.user_config`. |
| **Gruppe** | Bündelt Rechte (z. B. „Aufträge anlegen und bearbeiten“). Mitglieder erhalten diese Rechte. |
| **Firma** | Eigene Datenbank mit Kunden, Belegen und Buchhaltung. Benutzer und Gruppen werden je Firma zugeordnet. |
| **Systemadministrator** | Darf Benutzer, Gruppen und Firmen verwalten und Firmen-Datenbanken anlegen. Kennzeichen pro Benutzer (Schalter im Benutzerdialog). |

Ein Benutzer sieht eine Firma nur, wenn er ihr zugeordnet ist. Rechte bekommt er über Gruppen — und eine Gruppe wirkt in einer Firma nur, wenn sie dort ebenfalls zugeordnet ist. Die **Übersicht** prüft genau das und zeigt Befunde mit direktem Sprung zur Stelle, an der sie behoben werden (Benutzer ohne Firma, Gruppe ohne Firma, Firma ohne Benutzer, fehlende Standardfirma …).

## Benutzer

* **Anlegen:** „Neuer Benutzer“ — Anmeldename, Name, E-Mail, Passwort (Generator mit Stärkeanzeige), Rolle, Firmen und Gruppen in **einem** Dialog. Nach dem Speichern kann der Kollege sofort arbeiten.
* **Mitarbeiter:** In jeder zugeordneten Firma wird automatisch ein Mitarbeiter-Datensatz (`employee`) angelegt bzw. aktualisiert — wie in k9o. Belege zeigen den Namen des Bearbeiters.
* **Passwort setzen:** Zeilenmenü → „Passwort setzen“. Hashes sind k9o-kompatibel (PBKDF2).
* **Löschen:** Der Benutzer kann sich nicht mehr anmelden; seine Belege bleiben erhalten und zeigen weiterhin seinen Namen (Mitarbeiter wird als gelöscht markiert, k9o-Semantik).
* **Schutz:** Der eigene Benutzer kann weder gelöscht noch die eigene Administratorrolle entzogen werden.

## Gruppen

* Rechte nach Kategorien (Stammdaten, Verkauf, Einkauf, Lager, Finanzbuchhaltung, …) mit Kategorie-Schalter „alle/keine“, Suchfeld und Filter „nur erteilte“.
* Beschreibungen der Rechte sind übersetzt; der technische Name (z. B. `sales_order_edit`) steht darunter.
* „Duplizieren“ legt eine Kopie mit allen Rechten an — praktisch für abgestufte Rollen.
* Bei einer frischen Installation existiert die Gruppe **Vollzugriff** mit allen Rechten.

## Firmen

* **Neue Datenbank anlegen:** Firmenname, Datenbankname (wird vorgeschlagen), Kontenrahmen **SKR03** oder **SKR04**, Benutzer und Gruppen. Dauert etwa eine Minute: Datenbank anlegen, Kontenrahmen einspielen, OSERP-Tabellen ergänzen, vollständigen DATEV-Kontenrahmen laden. Danach direkt in die neue Firma wechseln.
* **Vorhandene Datenbank verbinden:** Der Server wird untersucht; alle Datenbanken, die wie eine Firmen-Datenbank aussehen und noch keiner Firma zugeordnet sind, werden zur Auswahl angeboten (mit Firmenname aus `defaults`, Kontenrahmen und Hinweis, ob OSERP-Tabellen vorhanden sind). Alternativ Zugangsdaten von Hand (anderer Server). Fehlende OSERP-Tabellen werden beim Verbinden ergänzt.
* **Bearbeiten:** Name, Zugangsdaten (leeres Passwort = unverändert), Standardfirma, Benutzer, Gruppen. „Verbindung prüfen“ zeigt Firmenname und Kontenrahmen.
* **Entfernen:** Standard ist nur das Entfernen aus der Anmeldemaske — die Datenbank bleibt. Die Datenbank wird nur gelöscht, wenn der Schalter gesetzt **und** der Datenbankname abgetippt wird; vorher wird ein Backup (`pg_dump`) im Backup-Verzeichnis abgelegt. Die Firma, in der man gerade angemeldet ist, kann nicht entfernt werden.
* **Datenbank auf aktuellen Stand bringen:** spielt das OSERP-Schema (crm + aktive Erweiterungen) in die Firmen-DB ein.

## Installation ohne k9o

Beim ersten Aufruf ohne `settings.ini` startet der **Setup-Assistent** (`/setup`):

1. **Datenbankserver** — Host, Port, Rolle, Passwort. „Verbindung prüfen“ zeigt Version, Rechte (CREATEDB) und alle vorhandenen Auth- und Firmen-Datenbanken.
2. **Auth-Datenbank** — neu anlegen (Standard) oder eine vorhandene k9o-Auth-DB übernehmen (Benutzer, Gruppen, Firmen bleiben; OSERP-Tabellen werden ergänzt).
3. **Administrator** — erster Benutzer (bei vorhandener Auth-DB auch ein bestehender Anmeldename).
4. **Erste Firma** — Name, Datenbankname, Kontenrahmen.
5. **Installation** — Zusammenfassung, Ausführung, Weiter zur Anmeldung.

### Wenn die Auth-Datenbank noch keine Tabellen hat

Der Assistent springt auch dann an, wenn eine `settings.ini` bereits existiert, die
Installation aber unvollständig ist. Er erkennt vier Fälle und sagt sie im Klartext:

| Zustand | Was der Assistent tut |
|---------|-----------------------|
| Datenbank aus der Konfiguration fehlt auf dem Server | legt sie an |
| Datenbank vorhanden, aber ohne Tabellen | spielt Schema und Rechtekatalog ein |
| Tabellen vorhanden, aber kein Benutzer | legt den Administrator an |
| Benutzer vorhanden, aber keine Firma | fragt nach einem vorhandenen Administrator und legt die Firma an |

Zugangsdaten müssen dabei nicht erneut eingegeben werden: Host, Port, Benutzer und
Datenbankname kommen aus der vorhandenen Konfiguration, das Passwort bleibt auf dem
Server. Auch eine leere, vom Hoster vorangelegte Datenbank lässt sich so übernehmen —
sie erscheint in Schritt zwei mit dem Hinweis „leer, wird eingerichtet".

### Standardzugang admin/admin

Im Schritt „Administrator" stehen zwei Wege zur Wahl. Voreingestellt ist der
**Standardzugang**: Benutzer `admin`, Passwort `admin`, Gruppe „Vollzugriff", sofort
anmeldebereit. Wer lieber gleich eigene Zugangsdaten vergibt, wählt „Eigenen
Administrator anlegen".

Solange das Standardpasswort gilt, trägt der Benutzer intern das Kennzeichen
`oserp_default_password`. Die Übersicht der Verwaltung zeigt dann den Befund
„Benutzer mit Standardpasswort" mit direktem Sprung zum Benutzer, und in der
Benutzerliste steht ein Warnsymbol. Sobald ein Passwort gesetzt wird, verschwindet
beides von selbst.

Auf der Kommandozeile entsteht derselbe Zugang, wenn `--admin-login` weggelassen wird.

### Wer darf einrichten?

Die Setup-Aktionen sind nur erreichbar, solange die Installation unvollständig ist:

- **Kein einziger Benutzer vorhanden**: Alle Schritte sind ohne Anmeldung möglich. Es
  gibt zu diesem Zeitpunkt nichts zu schützen, und ohne diese Öffnung käme niemand zur
  ersten Anmeldung.
- **Benutzer vorhanden, aber keine Firma**: Nur Zustandsabfrage und Installation sind
  offen, und die Installation verlangt Anmeldename und Passwort eines vorhandenen
  Administrators. Verbindungstests bleiben zu, damit der Server nicht zum Durchprobieren
  von Datenbank-Passwörtern taugt.
- **Installation vollständig**: Alle Setup-Aktionen liegen hinter dem normalen Auth-Gate.

Ohne Browser (Installer, Docker, Skripte):

```bash
php tools/oserp-setup.php --host localhost --port 5432 --user postgres --pass geheim \
    --auth-db oserp_auth --admin-login admin --admin-password 'Start123!' \
    --admin-name "Max Mustermann" --company "Muster GmbH" --company-db muster_gmbh --skr skr03
```

Alle Optionen auch als Umgebungsvariablen (`OSERP_DB_PASSWORD`, `OSERP_ADMIN_LOGIN`, …); `install/install.sh` nutzt sie für unbeaufsichtigte Installationen.

## Administrator werden — Regeln

1. Kennzeichen **Systemadministrator** am Benutzer (`auth.user_config`, Schlüssel `oserp_admin`).
2. Zusätzlich fest eingetragene Logins in `settings.ini` unter `[company] admin_users` (Altbestand).
3. **Übergang für bestehende k9o-Installationen:** Solange niemand ausdrücklich gekennzeichnet ist, gelten alle Benutzer mit dem k9o-Recht `admin` in einer Gruppe als Administrator. Die Übersicht zeigt das an und bietet „Mich als Administrator festlegen“ — danach zählt nur noch das Kennzeichen.

## Technik

* API: `backend/api/admin/` (Übersicht, Benutzer, Gruppen, Firmen, Datenbanken, Verbindungstest), `backend/api/company/` (Firma inkl. Datenbank anlegen), `backend/api/setup/` (Assistent: `status`, `probe`, `install`).
* Einrichtungsstand: `tenantInstallationState()` in `backend/api/lib/tenant.php` liefert die Stufe (fresh, no_database, no_schema, no_user, no_client, unreachable, ready). `restoreSession` und `getClients` schicken den Benutzer damit in den Assistenten statt auf eine unbrauchbare Anmeldemaske.
* Gemeinsame Logik: `backend/api/lib/tenant.php` — wird von Oberfläche, Assistent und CLI gleichermaßen genutzt.
* Schema: `backend/upstall/crm/auth_schema.sql` (k9o-kompatible Tabellen `auth.user`, `auth.group`, `auth.clients`, …) mit Seed-Daten in `auth_data/` (Rechtekatalog `master_rights`, `schema_info` auf Stand k9o 4.0). Der Update-Mechanismus legt fehlende Tabellen automatisch an.
* Frontend: `src/core/views/admin/`, Route `admin` (`/system/verwaltung`).
* Sprachen: Verwaltung und Setup-Assistent liegen in allen 21 Oberflächensprachen vor
  (`src/core/views/admin/locales/`, `src/core/views/setup/locales/`). Die Rechtebeschreibungen
  sind mit übersetzt; fehlt eine Übersetzung, greift die englische Beschreibung aus
  `auth.master_rights`.
