# OpensourceERP

**Das moderne Web-Frontend für kivitendo** — gebaut mit dem Ziel, ERP endlich so bedienbar zu machen, wie man es von modernen Webanwendungen erwartet. Keine Kompromisse bei der Benutzerfreundlichkeit — aber 100% kompatibel mit bestehenden kivitendo-Datenbanken.

**Konzept: UX First**

[Live-Demo](https://demo.opensourceerp.dev/) · [GitHub](https://github.com/Ciatronical/opensource-erp)

### Tech-Stack

| Bereich | Technologie |
|---|---|
| Frontend | Vue 3, Vuetify 3, Vite 7, Pinia, Vue Router 4 |
| Backend | PHP 8, PostgreSQL 16 |
| Echtzeit | Node.js 25 SSE-Server |
| Kalender | FullCalendar v6 |
| Editor | Tiptap (Rich Text) |
| Charts | Chart.js |
| Dateimanager | VueFinder |
| Drag & Drop | Vuedraggable |
| Telefon | libphonenumber-js |
| Deployment | Lokal (Apache) oder Docker · Let's Encrypt SSL |

---

## Features

### Kunden- & Lieferantenverwaltung (CRM)

- Vollständige Stammdatenverwaltung mit beliebig vielen Kontakten, Rollen und Abteilungen
- Mehrere Rechnungs- und Lieferadressen pro Kunde/Lieferant
- Bankdaten, Zahlungsbedingungen, Kreditlimits
- Umsatzstatistiken mit Diagrammen (Jahresvergleich, Trendanalyse)
- Dublettenprüfung beim Anlegen neuer Kunden
- USt-IdNr.-Validierung über VIES
- PLZ-Lookup für automatische Ortsermittlung
- Integrierter Dateimanager für Kundendokumente
- Kundenspezifische Preisregeln und Preisgruppen

### CRM-Dashboard

Eine kompakte Übersichtsseite pro Kunde: Kontaktdaten, letzte Vorgänge, Umsatzhistorie, verknüpfte Dokumente — alles auf einen Blick.

### Faktura (Belege)

Alle Belegarten in einer einheitlichen, modernen Oberfläche:

- Angebote → Aufträge → Rechnungen → Lieferscheine — mit Konvertierung zwischen den Belegarten
- Gutschriften, Einkaufsanfragen, Bestellungen, Reklamationen
- Live-Suche nach Kunden und Artikeln während der Eingabe
- Automatische Positionsnummerierung und Steuerberechnung
- Steuerzonen-Unterstützung
- Entwürfe speichern und später weiterbearbeiten
- PDF-Vorschau und -Export
- Direkter Versand per E-Mail oder WhatsApp
- Druckerauswahl mit Template-System
- Beleghistorie und Statusverwaltung (offen/geschlossen)
- Kompakt- und Vollansicht umschaltbar

### Integrierter E-Mail-Client

- IMAP-Posteingang direkt im ERP
- E-Mail-Versand über SMTP
- Ordnernavigation (Posteingang, Gesendet, Entwürfe, ...)
- E-Mail-Suche und -Filterung
- Automatische Zuordnung zu Kunden
- E-Mail-Journal mit vollständiger Historie
- Anhänge und Mehrfach-Konten-Unterstützung
- Brevo (Sendinblue)-Integration für E-Mail-Templates

### WhatsApp Business API

- Vollständige Integration der Meta WhatsApp Business API
- Nachrichten senden und empfangen — direkt aus der Kundenansicht
- Chat-Verlauf und Konversationsübersicht
- Dokumente, Standorte und Medien teilen
- Eigene Nachrichtenvorlagen erstellen und zur Meta-Freigabe einreichen
- Automatische Erinnerungen (z.B. Terminbenachrichtigungen)
- Echtzeit-Updates über SSE (gesendet, zugestellt, gelesen)
- Webhook-Integration für eingehende Nachrichten

### Wiedervorlage-System

Drei Ansichten — eine Datenbasis:

- Kanban-Board mit Drag & Drop (Überfällig, Heute, Kommend)
- Listenansicht mit Sortierung und Filterung
- Kalenderansicht mit Monatsübersicht
- Verknüpfung mit Kunden, Aufträgen, Rechnungen
- Zuweisung an Mitarbeiter
- Status-Tracking (erledigt/offen) mit Undo-Funktion
- Dashboard-Widget für die Startseite

### Kalender

- Monats-, Wochen- und Tagesansicht (FullCalendar v6)
- Termine erstellen, bearbeiten und per Drag & Drop verschieben
- Farbkodierte Kategorien
- Suchfunktion
- Integration mit dem Wiedervorlage-System

### Anrufliste & Telefonie

- Anrufhistorie mit Suchfiltern, Richtung und Datumsbereich
- Automatische Zuordnung zu Kunden
- Click-to-Call-Integration mit Telefonanlagen
- KI-gestützte Anruftranskription (Whisper API)
- Automatische Zusammenfassung von Gesprächen (Claude API)
- Ein-Klick-Auftragserstellung aus transkribierten Anrufen

### Wiki / Wissensdatenbank

- Internes Dokumentationssystem mit Rich-Text-Editor (Tiptap)
- Kategoriebasierte Organisation
- Versionierung mit Revisionshistorie und Wiederherstellung
- Suchfunktion
- SEO-freundliche Slug-URLs

### Aufgabenverwaltung

- Aufgaben erstellen und Mitarbeitern zuweisen
- Status-Tracking auf dem Dashboard
- Integration mit Pflichtenheften und Projekten

### Globale Suche

- Modulübergreifende Suche: Kunden, Lieferanten, Kontakte, Artikel, Belege
- Erweiterte Filterung pro Belegart
- SQL-Query-Builder für Power-User
- Gespeicherte Suchabfragen

### Artikelverwaltung

- Artikel-Stammdaten mit Kategorien
- Preishistorie und kundenspezifische Preise
- Benutzerdefinierte Variablen und Attribute

### Wall-Display / Digital Signage

- Großbildschirm-Modus für Werkstatt oder Empfang
- Zwei Modi: Kalenderanzeige oder Belegansicht für Kundenpräsentationen
- Echtzeit-Aktualisierung über SSE
- Vollbild-Darstellung

### Nummernkreise

Zwei Typen, sauber getrennt:

- **Geschützt (rechtssicher):** Rechnungen, Aufträge, Angebote, Lieferscheine — atomar, lückenlos, nur aufsteigend
- **Frei:** Kunden-, Lieferanten-, Artikelnummern — mit Kollisionserkennung, manuell setzbar

### Firmenfähigkeit (Multi-Tenant)

- Mehrere Firmen mit getrennten Datenbanken
- Firmenwechsel im laufenden Betrieb
- Firmenspezifische Konfiguration

### Konfiguration

- Umfangreiche Firmenkonfiguration (238+ Einstellungsfelder)
- Bankkonten, Buchungsgruppen, Steuerzonen, Steuereinstellungen
- DATEV-Integration
- Lagerverwaltung und Inventur
- Feature-Toggles zum Aktivieren/Deaktivieren von Modulen
- Feldsuche über alle Konfigurationstabs

### Benutzer & Rechte

- Rollenbasierte Zugriffskontrolle
- Berechtigungen pro Belegart (Rechnungen, Aufträge, Angebote, ...)
- Benutzerspezifische Einstellungen
- Gruppenverwaltung

### Mehrsprachigkeit

21 Sprachen: Deutsch, Englisch, Polnisch, Ukrainisch, Russisch, Französisch, Niederländisch, Dänisch, Norwegisch, Schwedisch, Estnisch, Lettisch, Litauisch, Spanisch, Italienisch, Portugiesisch, Tschechisch, Rumänisch, Türkisch, Finnisch, Chinesisch

- Vollständige Übersetzung aller Module über vue-i18n
- Datums- und Zahlenformatierung je Sprache

### Echtzeit-Benachrichtigungen

- Live-Updates über Server-Sent Events (SSE)
- Neue E-Mails, WhatsApp-Nachrichten, Wiedervorlagen und Kalendereinträge erscheinen sofort
- InfoBar mit Echtzeit-Zählern
- Automatische Reconnection bei Verbindungsabbruch

### Developer-Tools (für Administratoren)

- Integrierter API-Tester mit Testdaten
- SQL-Abfrage-Tool
- Datenbank-Backup & -Restore
- Schema-Verwaltung und Migrations-System
- Ticket-System mit Kanban-Board

### Datenschutz

- DSGVO-konforme Datenschutzerklärung
- Datenlöschungsanträge direkt im System
- Öffentlich zugängliche Rechtstexte ohne Login

---

## Architektur-Philosophie

| Prinzip | Umsetzung |
|---|---|
| UX First | Jede Designentscheidung priorisiert Bedienbarkeit |
| Logik in der Datenbank | SQL statt PHP — PHP ist nur Transport-Layer |
| Ein Request = Eine Query | Keine Daten-Assemblierung im Backend |
| Single Source of Truth | Pinia-Store verhindert doppelte API-Calls |
| Kein Hardcoding | Alles kommt aus der Datenbank — keine statischen Listen im Code |
| Prepared Statements | Konsequent parametrisierte Queries, keine String-Interpolation |
| Responsive Design | Desktop, Tablet, Mobil — eine Codebasis |
| 100% kivitendo-kompatibel | Funktioniert mit bestehenden kivitendo-Datenbanken |

---

## Integrationen

- **Meta WhatsApp Business API** — Messaging direkt im ERP
- **Brevo (Sendinblue)** — E-Mail-Marketing und Templates
- **OpenAI Whisper** — Anruftranskription (Speech-to-Text)
- **Anthropic Claude** — KI-Zusammenfassungen und Vorschläge
- **VIES** — EU-weite USt-IdNr.-Validierung
- **IMAP/SMTP** — Vollintegrierter E-Mail-Client
- **Telefonanlagen** — Click-to-Call
- **DATEV** — Buchhaltungsexport

---

## Installation ohne Docker

### 1. Voraussetzungen installieren

```bash
sudo apt update && sudo apt upgrade -y

sudo apt install -y \
  git curl \
  php php-cli php-pgsql php-mbstring php-xml php-curl php-ssh2 \
  postgresql postgresql-contrib qrencode \
  gnome-terminal

# Node.js 25 via nvm (Vite 7 braucht mindestens Node 20.19+)
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash
source ~/.bashrc
nvm install 25
nvm alias default 25
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
