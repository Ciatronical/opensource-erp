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

Alles darüber hinaus wird von Hand eingetragen. Ändert das Backend die Datei
selbst (`OserpConfig::saveSettings()`), legt es vorher eine Kopie
`settings.ini.backup.<Zeitstempel>` daneben — diese Kopien enthalten dieselben
Zugangsdaten und gehören genauso geschützt.

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
| `shop_publish_command` | leer | Befehl, der die Shop-Webseite baut — siehe unten |

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
| `admin_users` | leer | Logins, die eine neue Firma anlegen dürfen, durch Komma getrennt |

## Die beiden Shop-Schlüssel

Sie fallen aus dem Rahmen, weil das Übrige der Shop-Erweiterung je Mandant in
der Firmenkonfiguration steht.

**`shop_publish_command`** ist der Befehl, der die Webseite baut, etwa der
Hugo-Aufruf. Er steht hier und nirgendwo sonst: Ausgeführt wird er auf dem
Server, und das soll niemand über die Oberfläche setzen können. Ohne ihn
schreibt die Erweiterung nur Dateien und baut nicht. Ausgeführt wird er im
Verzeichnis der Webseite.

```ini
shop_publish_command = "/var/www/hugoshops/dev.hugoshop.dev/hugo/hugo --cleanDestinationDir -d public"
```

**`shop_sites_dir`** ist freiwillig und wirkt nur als Grenze. Wo die Webseite
eines Mandanten liegt, steht in dessen Shop-Einstellungen — jede Firma hat ihre
eigene. Ist hier ein Verzeichnis eingetragen, muss das eingestellte darunter
liegen, sonst meldet die Erweiterung `SHOP_SITES_DIR_OUTSIDE_LIMIT`.

Alles Weitere zum Shop: `dev/shop-betrieb.md`.

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
shop_publish_command = ""

[company]
admin_users = "admin"
```
