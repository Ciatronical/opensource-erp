# Telefonie (CRMTI) — CTI-Anbindung über Asterisk

CRMTI verbindet die Telefonanlage (Asterisk) mit OpensourceERP: Bei ein- und
ausgehenden Anrufen wird die Rufnummer in der Firmendatenbank nachgeschlagen und
der Vorgang in die Tabelle `crmti` geschrieben. OSERP zeigt den Anruf dann in
**Echtzeit** an (Anrufliste + Info-Bar), inkl. erkanntem Kundennamen, und
Gespräche werden als Audiodatei mitgeschnitten.

## Funktionsweise

```
FritzBox (IP-Telefone)  ──SIP──▶  Asterisk
                                     │  (Wählplan extensions.ael:
                                     │   ODBC_CALLIN / ODBC_CALLOUT)
                                     ▼
                                  ODBC-DSN "crmti"  ──▶  PostgreSQL-Funktion
                                     │                    CallIn() / CallOut()
                                     ▼
                            Firmendatenbank: Tabelle  crmti
                                     │  (Trigger trg_crmti_notify → pg_notify 'crmti_change')
                                     ▼
                            SSE-Server (Port 3001)  ──▶  OSERP-Frontend
                                                          (Anrufliste, Info-Bar)
```

Im Router (z. B. FritzBox) ist je Nebenstelle ein IP-Telefon eingerichtet.
Asterisk meldet sich an diesen Konten an und führt bei jedem Gespräch über die
ODBC-Funktionen `CallIn()`/`CallOut()` eine Abfrage in der Firmendatenbank aus,
die den Anruf in die Tabelle `crmti` einträgt. Ein Trigger sendet `pg_notify`,
der [SSE-Server](../../install/README.md) verteilt das Ereignis live ans
Frontend.

## OSERP-Integration (im Repo enthalten)

Der ERP-seitige Teil ist Bestandteil von OpensourceERP:

- Tabelle `crmti`, Funktionen `CallIn()`/`CallOut()`/`CallStatus()`, Trigger
  `trg_crmti_notify` → `backend/upstall/crm/company_schema.sql`
- Live-Ereignis `crmti_change` → `backend/sse/sse-server.js`
- Anzeige → `src/core/views/call-history/call-history.view.vue`,
  `src/core/components/navbar/info-bar.component.vue`,
  `src/core/composables/useInfoBar.js`

> Hinweis: Voraussetzung für die Live-Anzeige ist ein laufender SSE-Server
> (Port 3001). Siehe Abschnitt „SSE-Server" in [install/README.md](../../install/README.md).

Die **Asterisk-/ODBC-Seite** (Telefonanlage) ist Server-Infrastruktur und liegt
außerhalb des Repos. Die folgenden Abschnitte beschreiben deren Einrichtung.

### Nicht angenommene Anrufe (rot)

`crmti_status` hält den Asterisk-`DIALSTATUS` des Anrufs. Da dieser erst **nach**
dem `Dial()` feststeht (`CallIn`/`CallOut` laufen davor), trägt die Funktion
`CallStatus(unique_id, status)` ihn nachträglich in die crmti-Zeile ein und löst
erneut `pg_notify('crmti_change')` aus. Im Dialplan (`extensions.ael`) steht dazu
nach jedem `Dial()`:

```
Dial(...);
Set(cstatus=${ODBC_CALLSTATUS(${UNIQUEID},${DIALSTATUS})});
```

Bei **ausgehenden** Anrufen läuft der Dialplan nach `Dial()` allerdings nicht
zuverlässig weiter (legt die Gegenseite auf/drückt weg oder der Anrufer selbst
auf, wird der Kanal beendet — die Zeile nach `Dial()` wird übersprungen). Deshalb
trägt dort zusätzlich ein **Hangup-Handler** (`h`-Extension) je Ausgangs-Kontext
den Status nach — der läuft beim Auflegen garantiert, `${DIALSTATUS}` ist dort
noch gesetzt:

```
context autoprofis1 {
    _0. => { ...; Dial(...); }
    h   => { Set(cstatus=${ODBC_CALLSTATUS(${UNIQUEID},${DIALSTATUS})}); }
}
```

`ODBC_CALLSTATUS` ist in `func_odbc.conf` definiert (Section `[CALLSTATUS]` →
`SELECT CallStatus(...)`). Ist `crmti_status` gesetzt und ungleich `ANSWERED`,
gilt der Anruf als **nicht angenommen** (eingehend verpasst bzw. ausgehend nicht
erreicht) und wird in Anrufliste und Info-Bar **rot** dargestellt
(`src/core/utils/callStatus.js`). Altdaten ohne Status bleiben unauffällig.

---

## ⚠️ Beim Datenbank-Wechsel anpassen

Asterisk schreibt über den ODBC-DSN `crmti` in **eine fest konfigurierte
Datenbank**. Welche das ist, steht **ausschließlich** in `/etc/odbc.ini`,
Section `[crmti]`:

```ini
[crmti]
...
Database    = ap_rebuild      # ← muss auf die AKTUELLE Firmendatenbank zeigen
Servername    = localhost
Username    = postgres
Port        = 5432
```

Wird die Firmendatenbank gewechselt (z. B. `autoprofis_gmbh` → `ap_rebuild`),
**muss diese Zeile geändert werden**, sonst landen eingehende Anrufe weiter in
der alten DB und erscheinen in OSERP nicht mehr.

```bash
# DB-Namen in der [crmti]-Section umstellen:
sudo sed -i '/^\[crmti\]/,/^\[/ s/^Database\s*=.*/Database\t= ap_rebuild/' /etc/odbc.ini
sed -n '/^\[crmti\]/,/^\[/p' /etc/odbc.ini    # prüfen

# Asterisk ODBC neu laden + Verbindung testen:
sudo asterisk -rx "odbc reload"
sudo asterisk -rx "odbc show"                 # crmti -> Connected: Yes
```

Die Tabelle `crmti`, der Trigger `trg_crmti_notify` und die Funktionen
`CallIn`/`CallOut` sind in jeder aus dem CRM-Schema erzeugten DB bereits
vorhanden — es ist also nur der DB-Name in `odbc.ini` umzustellen.

> Der zweite DSN `[crmtihandel]` (Mandant „Handel") und `res_pgsql.conf`
> (`dbname=asterisk`, Asterisks eigene Realtime-DB) sind hiervon **nicht**
> betroffen.

---

## Einrichtung Asterisk

Vorlagen aus `crmti/asterisk/` nach `/etc/asterisk/` kopieren, dann anpassen:

| Datei | Inhalt |
|-------|--------|
| `extensions.conf` | umbenennen oder löschen (Wählplan kommt aus der `.ael`) |
| `sip.conf` | Zugangsdaten zum VoIP-Provider und Rufnummern |
| `extensions.ael` | Wählplan: was bei ein-/ausgehenden Gesprächen passiert (an `sip.conf` anpassen). Ruft `ODBC_CALLIN`/`ODBC_CALLOUT` auf und schneidet via `MixMonitor` mit |
| `res_odbc.conf` | ODBC-Zugang (DSN `crmti`, Benutzer/Passwort) |
| `res_pgsql.conf` | Asterisks eigene Realtime-DB (**nicht** die Firmen-DB) |
| `func_odbc.conf` | Definiert `CALLIN`/`CALLOUT` → `dsn=crmti`, ruft `CallIn()`/`CallOut()`. **Nicht modifizieren** |

Damit OSERP/Webserver die Anrufaufzeichnungen abspielen kann:

```bash
usermod -a -G asterisk www-data
```

## Einrichtung ODBC

```bash
apt install unixodbc unixodbc-dev odbc-postgresql
cpan Mozilla::CA

# Treiber registrieren (füllt /etc/odbcinst.ini):
odbcinst -i -d -f /usr/share/psqlodbc/odbcinst.ini.template
```

`/etc/odbc.ini` mit der Vorlage aus `crmti/etc/odbc.ini` füllen und
**Datenbankname, Passwort und Treiberpfad** anpassen (siehe Warnhinweis oben).
Den Treiberpfad ggf. ermitteln mit:

```bash
find / -name 'lib*odbc*.so'
```

## Testen

```bash
sudo systemctl restart asterisk        # oder: /etc/init.d/asterisk restart
sudo asterisk -vvvvvvr                  # Asterisk-Konsole

# in der Konsole:
odbc show          # crmti -> Connected: Yes
sip show peers     # SIP-Verbindungen
sip reload         # SIP neu einlesen
ael reload         # Wählplan neu einlesen
```

Anschließend testweise anrufen → der Eintrag muss in `<firmen-db>.crmti` landen
und sofort in der OSERP-Anrufliste/Info-Bar erscheinen.

## Hardware

In der FritzBox je Nebenstelle ein IP-Telefon einrichten (Beispiel):

```
Internetnummer 2000
Benutzername   2000
Kennwort       <geheim>
Registrar      <IP-des-Servers>:5070
Proxyserver    <IP-des-Servers>:5070
```

> Hinweis: Die FritzBox Fon kann die aus der Datenbank ermittelten Kundennamen
> nicht auf dem Display darstellen.

Ist keine Telefon-Hardware zur Hand, hilft ein Softphone:

```bash
apt install twinkle
```

## Weiterführend

- Das Asterisk-Buch: <http://www.das-asterisk-buch.de>
- ODBC für Asterisk:
  <http://asteriskdocs.org/en/3rd_Edition/asterisk-book-html-chunk/installing_configuring_odbc.html>

---

*Diese Doku basiert auf der ursprünglichen `INSTALL.TXT` der CRMTI-Komponente
(R. Zimmermann) und wurde für OpensourceERP modernisiert.*
