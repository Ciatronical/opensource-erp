## Konzept: UX First

OpensourceERP ist ein komplett neues Web-Frontend für Kivitendo, gebaut mit dem Ziel, ERP endlich so bedienbar zu machen, wie man es von modernen Webanwendungen erwartet. Keine Kompromisse bei der Benutzerfreundlichkeit — aber 100% kompatibel mit bestehenden Kivitendo-Datenbanken.

**Live-Demo:** [https://demo.lxcars.de/](https://demo.lxcars.de/)
**GitHub:** [https://github.com/Ciatronical/opensource-erp](https://github.com/Ciatronical/opensource-erp)

**Technologie-Stack:**
- Frontend: Vue 3 + Vuetify 3 (Material Design) + Vite
- Backend: PHP 8 + PostgreSQL 16
- Echtzeit: Node.js SSE-Server für Live-Updates
- Deployment: Docker-ready mit Let's Encrypt SSL

---

## Die Features im Überblick

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

- **Angebote** → **Aufträge** → **Rechnungen** → **Lieferscheine** — mit Konvertierung zwischen den Belegarten
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
- Ordnernavigation (Posteingang, Gesendet, Entwürfe, …)
- E-Mail-Suche und -Filterung
- Automatische Zuordnung zu Kunden
- E-Mail-Journal mit vollständiger Historie
- Anhänge und Mehrfach-Konten-Unterstützung
- Brevo (Sendinblue)-Integration für E-Mail-Templates

### Messaging (WhatsApp + Telegram)

Beide Messenger in einer gemeinsamen Oberfläche — der Mitarbeiter sieht alle Nachrichten eines Kunden an einem Ort, unabhängig vom Kanal.

**WhatsApp Business API:**
- Vollständige Integration der Meta WhatsApp Business API
- Nachrichten senden und empfangen — direkt aus der Kundenansicht
- Chat-Verlauf und Konversationsübersicht
- Dokumente, Standorte und Medien teilen
- Eigene Nachrichtenvorlagen erstellen und zur Meta-Freigabe einreichen
- Automatische Erinnerungen (z.B. Terminbenachrichtigungen)
- Echtzeit-Updates über SSE (gesendet, zugestellt, gelesen)
- Webhook-Integration für eingehende Nachrichten

**Telegram Bot:**
- Komplett kostenlos — keine API-Gebühren, kein Drittanbieter
- Bot in 2 Minuten über @BotFather erstellt
- Kein 24-Stunden-Fenster — Kunden jederzeit erreichbar
- Keine Template-Genehmigung nötig
- Medien, Dokumente und Standorte teilen
- Echtzeit-Updates über SSE

### Wiedervorlage-System

Drei Ansichten — eine Datenbasis:

- **Kanban-Board** mit Drag & Drop (Überfällig, Heute, Kommend)
- **Listenansicht** mit Sortierung und Filterung
- **Kalenderansicht** mit Monatsübersicht
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

### Mandantenfähigkeit

- Mehrere Mandanten mit getrennten Datenbanken
- Mandantenwechsel im laufenden Betrieb
- Mandantenspezifische Konfiguration

### Konfiguration

- Umfangreiche Mandantenkonfiguration (238+ Einstellungsfelder)
- Bankkonten, Buchungsgruppen, Steuerzonen, Steuereinstellungen
- DATEV-Integration
- Lagerverwaltung und Inventur
- Feature-Toggles zum Aktivieren/Deaktivieren von Modulen
- Feldsuche über alle Konfigurationstabs

### Benutzer & Rechte

- Rollenbasierte Zugriffskontrolle
- Berechtigungen pro Belegart (Rechnungen, Aufträge, Angebote, …)
- Benutzerspezifische Einstellungen
- Gruppenverwaltung

### Mehrsprachigkeit

- 21 Sprachen: Deutsch, Englisch, Polnisch, Ukrainisch, Russisch, Französisch, Niederländisch, Dänisch, Norwegisch, Schwedisch, Estnisch, Lettisch, Litauisch, Spanisch, Italienisch, Portugiesisch, Tschechisch, Rumänisch, Türkisch, Finnisch, Chinesisch
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

### Setup & Updates

- Setup-Assistent für die Ersteinrichtung
- Datenimport aus bestehenden Kivitendo-Installationen
- Automatisches Datenbank-Migrations-System (Upstall)
- Demo-Modus mit automatischem Reset nach Inaktivität

---

## Architektur-Philosophie

| Prinzip | Umsetzung |
|---|---|
| **UX First** | Jede Designentscheidung priorisiert Bedienbarkeit |
| **Logik in der Datenbank** | SQL statt PHP — PHP ist nur Transport-Layer |
| **Ein Request = Eine Query** | Keine Daten-Assemblierung im Backend |
| **Single Source of Truth** | Pinia-Store verhindert doppelte API-Calls |
| **Kein Hardcoding** | Alles kommt aus der Datenbank — keine statischen Listen im Code |
| **Prepared Statements** | Konsequent parametrisierte Queries, keine String-Interpolation |
| **Responsive Design** | Desktop, Tablet, Mobil — eine Codebasis |
| **100% Kivitendo-kompatibel** | Funktioniert mit bestehenden Kivitendo-Datenbanken |

---

## Technologie auf einen Blick

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
| Deployment | Docker + Apache + Let's Encrypt |

---

## Integrationen

- **Meta WhatsApp Business API** — Messaging direkt im ERP
- **Telegram Bot API** — kostenloser Messenger-Kanal, gemeinsame Oberfläche mit WhatsApp
- **Brevo (Sendinblue)** — E-Mail-Marketing und Templates
- **OpenAI Whisper** — Anruftranskription (Speech-to-Text)
- **Anthropic Claude** — KI-Zusammenfassungen und Vorschläge
- **VIES** — EU-weite USt-IdNr.-Validierung
- **IMAP/SMTP** — Vollintegrierter E-Mail-Client
- **Telefonanlagen** — Click-to-Call
- **DATEV** — Buchhaltungsexport

---

*Im nächsten Beitrag: LxCars — das Autohaus- und Werkstattmodul für OpensourceERP.*
