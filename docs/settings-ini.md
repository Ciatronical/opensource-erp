# settings.ini — Konfiguration der Installation

Alles, was zur einzelnen Installation gehört und nicht ins Repository darf:
Datenbankzugang, Zeitzone, Protokolldateien, Betriebsarten. Alles, was zur
einzelnen **Firma** gehört, steht dagegen in der Datenbank und ist in der
Firmenkonfiguration änderbar — siehe unten.

## Wo sie liegt

| | |
| --- | --- |
| Pfad | `backend/config/settings.ini` |
| Im Wurzelverzeichnis | `settings.ini` ist ein Symlink darauf — dieselbe Datei |
| Versioniert | nein, sie steht in `.gitignore` |
| Format | INI mit Abschnitten, Kommentare mit `;`, gelesen mit `parse_ini_file(..., INI_SCANNER_TYPED)` |

Wegen `INI_SCANNER_TYPED` sind `true`/`false` echte Wahrheitswerte und Zahlen
echte Zahlen; Zeichenketten gehören in Anführungszeichen.

## Wer sie anlegt

| Weg | Was er schreibt |
| --- | --- |
| Setup-Assistent im Browser (`backend/api/setup/setup.php`) | `[database]`, `[session]`, `[logging]`, `[system]` mit den eingegebenen Zugangsdaten |
| `install/install.sh` | dieselbe Vorlage mit `auth_pass = "BITTE_EINTRAGEN"`, falls die Datei fehlt |
| Docker (`docker/web/entrypoint.sh`) | erzeugt sie bei jedem Start aus den Umgebungsvariablen, im Demo-Betrieb zusätzlich `[demo]` |

Danach wird sie von Hand oder in der Ansicht **Systemeinstellungen** geändert
(siehe unten). Ändert das Backend die Datei selbst
(`OserpConfig::saveSettings()`), legt es vorher eine Kopie
`settings.ini.backup.<Zeitstempel>` daneben — diese Kopien enthalten dieselben
Zugangsdaten und gehören genauso geschützt.

## Bearbeiten in der Oberfläche

Systemadministratoren finden im Systemmenü (Klick auf Firmenlogo oder
Firmennamen) den Eintrag **Systemeinstellungen**. Anderen Benutzern wird er
nicht angezeigt; die Route verlangt `requiresAdmin`, und das Backend
(`backend/api/admin/system_settings.php`) prüft mit `requireSystemAdmin()`.

- Das Formular entsteht aus `systemSettingsSchema()`: nur die dort bekannten
  Schlüssel sind bearbeitbar. Andere Einträge der Datei werden nur mit Namen
  aufgeführt und beim Speichern unverändert übernommen.
- Fehlt ein Eintrag in der Datei, zeigt das Feld die Vorgabe aus `config.php`
  als Platzhalter. Ein geleertes Feld entfernt den Eintrag, danach gilt wieder
  die Vorgabe. Pflichtfelder (`host`, `port`, `auth_db`, `auth_user`) lassen
  sich nicht leeren.
- `auth_pass` wird nie ausgeliefert; ein leeres Feld behält das hinterlegte
  Passwort.
- Vor dem Speichern zeigt eine Rückfrage alle Änderungen. Das Backend prüft
  jeden Wert erneut (Zahlen, absolute Pfade, Auswahllisten, Zeitzonen; keine
  Steuerzeichen, Anführungszeichen, Backslashes und `${`).
- Geänderter Datenbankzugang wird vorher mit einer Testverbindung
  (`SELECT 1 FROM auth.clients`) ausprobiert; ohne Verbindung wird nichts
  gespeichert. Danach PHP-FPM neu laden.
- Eine neue `admin_users`-Liste, mit der der angemeldete Benutzer kein
  Systemadministrator mehr wäre, wird abgelehnt.
- Beim Speichern wird die Datei vollständig neu geschrieben: **Kommentare gehen
  verloren**, die Sicherungskopie bleibt daneben liegen.
- Die Datei muss für den Webserver beschreibbar sein, sonst ist Speichern
  gesperrt. Unter Docker erzeugt der Container sie bei jedem Start neu —
  dort gehören Änderungen in die Umgebungsvariablen.

## Wer sie liest

- **Das Backend** über `OserpConfig::init()` in `backend/api/config.php`. Jede
  Zeile wird dort zu einer Konstante; der Rest der Anwendung liest nur noch
  diese.
- **Die Kommandozeilenwerkzeuge** `tools/shop-publish.php` und
  `backend/cli/tafel-watchdog.php` über dieselbe Klasse.
- **Die Python-Dienste** `backend/services/camera-monitor/camera_monitor.py`
  und `backend/services/plate-recognition/anpr_service.py`. Sie lesen
  `[database]` selbst und entschlüsseln das Passwort mit demselben Verfahren.

Gelesen wird bei jeder Anfrage neu. Geänderte **Datenbankzugangsdaten** greifen
trotzdem erst, wenn PHP-FPM neu geladen wurde (`systemctl reload php*-fpm`):
Die Verbindungen sind persistent, und bestehende Arbeiter behalten ihre alte.

## Die Abschnitte

### [database]

| Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- |
| `host` | `localhost` | Datenbankserver |
| `port` | `5432` | Port |
| `auth_db` | `oserp_auth` | Auth-Datenbank mit Benutzern, Rechten und der Mandantenliste |
| `auth_user` | `postgres` | Datenbankbenutzer |
| `auth_pass` | leer | Passwort, verschleiert (siehe unten) |

Die Datenbanken der Mandanten stehen **nicht** hier, sondern in `auth.clients`.

### [session]

| Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- |
| `cookie_name` | `opensource_erp` | Name des Sitzungs-Cookies |
| `cookie_same_site` | `Strict` | SameSite-Regel des Cookies |

### [logging]

| Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- |
| `debug_log_file` | `backend/log/opensource_erp.api.debug.log` | Ziel von `writeLog()` |
| `api_log_file` | `backend/log/opensource_erp.api.log` | Protokoll der API-Aufrufe |
| `max_log_size` | `10485760` | Ab dieser Größe in Bytes wird die Datei umbenannt |

### [system]

| Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- |
| `timezone` | `Europe/Berlin` | Zeitzone für alle Zeitstempel |
| `debug` | `false` | Ausführliches Protokoll; im Betrieb aus |
| `templates_dir` | `templates` | Druck- und Shop-Vorlagen. Relative Pfade gelten ab `backend/`, also `backend/templates` |
| `backup_dir` | `backups/` im Wurzelverzeichnis | Ziel der Datenbanksicherungen |
| `shop_sites_dir` | leer | Grenze für die Shop-Webseiten, freiwillig — siehe unten |
| `shop_publish_command_path` | leer | Verzeichnis, in dem `hugo` liegt — siehe unten |
| `browse_roots` | leer | Einstiegspunkte der Verzeichnisauswahl — siehe unten |

### [telephony]

| Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- |
| `monitor_dir` | `/var/spool/asterisk/monitor` | Verzeichnis der Gesprächsaufzeichnungen |

### [demo]

| Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- |
| `enabled` | `false` | Demo-Betrieb: Daten werden regelmäßig zurückgesetzt |
| `company_db` | leer | Datenbank, die zurückgesetzt wird |
| `inactivity_minutes` | `20` | Nach so vielen Minuten ohne Betrieb wird zurückgesetzt |

Einzelheiten in `docker/SETUP_DEMO.md`.

### [company]

| Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- |
| `admin_users` | leer | Logins, die Systemadministrator sind — Benutzer, Gruppen und Firmen verwalten —, durch Komma getrennt |

Das ist die zweite von drei Regeln in `tenantAdminStatus()`
(`backend/api/lib/tenant.php`):

1. Kennzeichen `oserp_admin` in `auth.user_config`, gesetzt in der
   Benutzerverwaltung — der vorgesehene Weg.
2. `admin_users` aus dieser Datei — Altbestand, weiterhin gültig.
3. Übergang für kivitendo-Installationen: Solange **niemand** über 1 oder 2
   Administrator ist, zählt das kivitendo-Recht „admin“ in einer Gruppe.

Ein einziger Eintrag in `admin_users` beendet damit den Übergang aus Regel 3:
Wer bis dahin nur über das kivitendo-Recht Administrator war, ist es danach
nicht mehr. Die Übersicht in der Benutzerverwaltung zeigt, über welche Regel
der angemeldete Benutzer Administrator ist, und listet die Logins aus dieser
Datei.

## Die beiden Shop-Schlüssel

Sie fallen aus dem Rahmen, weil das Übrige der Shop-Erweiterung je Mandant in
der Firmenkonfiguration steht.

**`shop_publish_command_path`** ist das **Verzeichnis**, in dem das Programm
liegt, das die Webseite baut — weder Dateiname noch Befehlszeile. Der Dateiname
steht fest: `hugo`. Die Befehlszeile setzt die Erweiterung selbst zusammen:
maskierter Pfad aus Verzeichnis und Name, bei Bedarf `--cleanDestinationDir`,
ausgeführt im Verzeichnis der Webseite, Hugo schreibt dann nach `public/`.
Vor jedem Bau prüft sie beides: absolut, ohne Leerraum, vorhandenes
Verzeichnis, darin eine vorhandene und ausführbare Datei `hugo`.

Derselbe Schlüssel steht auch in den Shop-Einstellungen des Mandanten, und
**die gelten zuerst**. Der Eintrag hier ist Rückfall: Er greift nur, wenn die
Shop-Einstellung leer ist, und erscheint dort als Vorgabe im leeren Feld —
gespeichert wird er dabei nicht. Ein ungültiger Wert in der Shop-Einstellung
ist ein Fehler, kein Anlass zum Rückfall. Ohne Eintrag hier und dort schreibt
die Erweiterung nur Dateien und baut nicht. `--cleanDestinationDir` ist nur in
den Shop-Einstellungen schaltbar.

```ini
shop_publish_command_path = "/var/www/hugoshops/dev.hugoshop.dev/hugo"
```

**`shop_sites_dir`** ist freiwillig und wirkt nur als Grenze. Wo die Webseite
eines Mandanten liegt, steht in dessen Shop-Einstellungen — jede Firma hat ihre
eigene. Ist hier ein Verzeichnis eingetragen, muss das eingestellte darunter
liegen, sonst meldet die Erweiterung `SHOP_SITES_DIR_OUTSIDE_LIMIT`.

Alles Weitere zum Shop: `dev/shop-betrieb.md`.

## Die Verzeichnisauswahl

Pfadfelder in den Systemeinstellungen haben rechts ein Ordner-Symbol. Es
öffnet einen Auswahldialog, der die Verzeichnisse **des Servers** zeigt — der
Browser selbst kann keinen Serverpfad auswählen, deshalb listet die Anwendung
sie auf. Sichtbar ist dabei nur, was unterhalb eines freigegebenen
Einstiegspunktes liegt; alles andere weist der Server ab, auch wenn jemand den
Pfad von Hand in die Anfrage schreibt.

Ohne Eintrag leitet die Anwendung die Einstiegspunkte ab: das Verzeichnis der
Installation und dessen übergeordnetes Verzeichnis, die Verzeichnisse der
bereits eingetragenen Pfade sowie `/srv`, `/var/www`, `/mnt` und `/media`,
soweit vorhanden. Das reicht in den meisten Fällen und erreicht auch ein
Programm, das neben der Installation liegt.

Steht `browse_roots` in der Datei, gilt **allein** diese Liste — mehrere
Verzeichnisse durch Komma getrennt:

```ini
[system]
browse_roots = "/srv/oserp,/var/www"
```

Das ist der Weg, die Sicht auf einem gemeinsam genutzten Server zu
beschneiden. Der Dialog erreicht dann nichts außerhalb dieser Verzeichnisse.
Die Felder bleiben unabhängig davon frei beschreibbar: Wer einen Pfad tippt,
ist nicht auf den Dialog angewiesen.

Die Auswahl steht nur Systemadministratoren offen, weil eine Auflistung die
Struktur des Servers preisgibt. In der Firmenkonfiguration gibt es sie
ausschließlich für die relativen Shop-Verzeichnisse, und dort reicht sie nicht
über das Webseiten-Verzeichnis des jeweiligen Mandanten hinaus.

## Was hier nicht hingehört

| Angabe | Wo sie steht |
| --- | --- |
| Datenbanken und Zugangsdaten der Mandanten | `auth.clients` in der Auth-Datenbank |
| Alles Firmenspezifische: Nummernkreise, Konten, Steuerzone, Shop, PayPal, DHL | Tabellen `defaults` und `defaults_oserp`, änderbar unter Einstellungen → Firmenkonfiguration |
| Rechte der Mitarbeiter | Auth-Datenbank, Einstellungen → Benutzer |

## Passwort verschleiern

```
php tools/encrypt_password.php "geheim"
```

Der ausgegebene Wert gehört nach `auth_pass`. Das Verfahren ist XOR mit einem
festen Schlüssel plus Base64 — **Verschleierung, kein Schutz.** Wer die Datei
lesen kann, kann das Passwort zurückrechnen; dieselbe Funktion steckt in den
Python-Diensten. Der Schutz liegt allein in den Dateirechten.

## Rechte und Erreichbarkeit

- Die Datei gehört dem Benutzer, unter dem die Anwendung läuft, und muss für
  den Webserver lesbar sein — mehr nicht.
- Über den Webserver erreichbar ist sie in der mitgelieferten
  Apache-Konfiguration nicht: Der Docroot ist `dist/`, und nur `/api` und
  `/webhook` zeigen ins Backend. `backend/config/` hat keinen Alias. Bei
  eigener Serverkonfiguration gehört das geprüft.
- Die Sicherungskopien `settings.ini.backup.*` nicht vergessen.

## Beispiel

```ini
; config/settings.ini
; OpensourceERP Konfigurationsdatei
;
; WICHTIG: Diese Datei enthält sensible Zugangsdaten!
; Niemals in Git einchecken oder öffentlich zugänglich machen.

[database]
host = "localhost"
port = "5432"
auth_db = "kivitendo_auth"
auth_user = "postgres"
auth_pass = "<Ausgabe von tools/encrypt_password.php>"

[session]
cookie_name = "opensource_erp"
cookie_same_site = "Strict"

[logging]
max_log_size = 10485760

[system]
timezone = "Europe/Berlin"
debug = false
templates_dir = "templates"
shop_publish_command_path = ""

[company]
admin_users = "admin"
```
