# OpensourceERP — Gesamtübersicht aller Features

Vollständige Aufstellung dessen, was OpensourceERP kann — Kernsystem und die Zusatzfunktionen, die erst mit eingeschaltetem **LxCars** erscheinen.

**Legende**

| Kennzeichnung | Bedeutung |
|---|---|
| *(Kern)* | immer verfügbar |
| *(LxCars)* | nur bei aktiviertem Feature `lxcars` |
| *(Schalter)* | eigener Feature-Schalter in der Firmenkonfiguration |
| *(extern)* | benötigt Zugangsdaten/Vertrag eines Drittanbieters |

---

## Inhalt

**Teil A — Kernsystem**
1. [Banking](#1-banking)
2. [Buchhaltung](#2-buchhaltung)
3. [Kasse / Kassenbuch](#3-kasse--kassenbuch)
4. [Lager & Inventur](#4-lager--inventur)
5. [Faktura / Belege](#5-faktura--belege)
6. [CRM — Kunden & Lieferanten](#6-crm--kunden--lieferanten)
7. [Artikel & Preise](#7-artikel--preise)
8. [Kommunikation](#8-kommunikation)
9. [Organisation & Wissen](#9-organisation--wissen)
10. [Personal (HR)](#10-personal-hr)
11. [KI-Funktionen](#11-ki-funktionen)
12. [Kamera & Überwachung](#12-kamera--überwachung)
13. [Versand, Bezahlung, Marktplätze](#13-versand-bezahlung-marktplätze)
14. [System, Rechte, Mandanten](#14-system-rechte-mandanten)
15. [Developer- & Admin-Werkzeuge](#15-developer--admin-werkzeuge)

**Teil B — LxCars**

16. [Was LxCars zusätzlich freischaltet](#16-lxcars--was-zusätzlich-dazukommt)

**Anhang**

- [Feature-Schalter im Überblick](#anhang-a--feature-schalter)
- [Konfigurations-Tabs](#anhang-b--konfigurations-tabs)
- [Hintergrunddienste & Cronjobs](#anhang-c--hintergrunddienste--cronjobs)

---

# Teil A — Kernsystem

## 1. Banking

Alle Bankfunktionen liegen in einem Hub mit neun Reitern: **Umsätze · Überweisungen · Liquidität · Lastschriften · Vorlagen · Daueraufträge · Mandate · Abgleich · Konten**.

### 1.1 Bankanbindung per FinTS *(extern)*

- Direktanbindung an deutsche Banken über **FinTS/HBCI** (phpFinTS 3.x)
- **Automatische FinTS-URL-Ermittlung** aus der Bankleitzahl (mitgelieferte BLZ-Datenbank)
- **BIC- und Banknamen-Auflösung aus der IBAN**
- **PIN-Speicherung verschlüsselt** in der Firmenkonfiguration (optional) — inkl. Laden und Löschen
- **Trust-Anker / Gerätekennung**: der Kundensystem-Zustand wird gespeichert, damit nicht bei jedem Abruf eine neue TAN fällig wird
  - **Keep-Alive im Hintergrund** — frischt den Trust-Anker automatisch auf (alle 45 Minuten, für alle Konten mit gespeicherter PIN)
- **TAN-Verfahren**: Auswahl des Verfahrens inkl. TAN-Medium, Unterstützung für **decoupled-TAN** (Push-Freigabe in der Banking-App)
- **FinTS-Produkt-ID** pro Betreiber hinterlegbar (Pflicht nach FinTS-Spezifikation)
- **Debug-Logging** für die FinTS-Kommunikation zuschaltbar

### 1.2 Kontoumsätze

- **Umsatzabruf** von der Bank, Import ohne Dubletten
- **Kontostandsabfrage** direkt bei der Bank
- Umsatzliste mit **Zuordnungsstatus** (nicht zugeordnet / zugeordnet / verbucht / ignoriert)
- Einzelumsatz-Detailansicht
- Umsätze als **ignoriert** markieren und zurücksetzen
- **Kontostatistik** je Konto (Zusammenfassung, Ein-/Ausgänge)
- **Export als CSV** und **als PDF-Kontoauszug**

### 1.3 Überweisungen (SEPA)

- Überweisungsauftrag als **Entwurf** anlegen, ändern, löschen
- **Einzel-, Sammel-, Sofort- und Termin-Überweisung** (PAIN.001.001.03, Segmente HKCCS / HKCSE / HKCCM / HKCME)
- **Sammelüberweisung**: mehrere Aufträge in einem PAIN-Batch mit einer TAN
- **VoP-Prüfung (Verification of Payee)** — Namensabgleich vor Absendung
  - positiver Abgleich → direkt TAN
  - Abweichung (RVMC/RVNM/RVNA) → Bestätigungsdialog, danach Absendung
  - Opt-out möglich für Banken ohne VoP
- **IBAN-Validierung** nach Format, Länderlänge und MOD-97-Prüfsumme
- **SEPA-Zeichensatz-Bereinigung** (verhindert Bankfehler 9010 bei Umlauten/Sonderzeichen)
- **Empfänger-Autocomplete** über Kunden und Lieferanten
  - neuen Empfänger direkt aus dem Dialog anlegen
  - IBAN/BIC dauerhaft am Kunden bzw. Lieferanten speichern
- **Überweisung aus Eingangsrechnung** erzeugen (ein Klick aus der offenen Verbindlichkeit)
- **Überweisungsbestätigung als PDF**
- **Statusnachverfolgung**: Entwurf → wartet auf TAN → eingereicht → ausgeführt
  - **automatischer Abgleich** eingereichter Aufträge gegen tatsächliche Buchungen
  - **Verfall** alter „eingereicht"-Aufträge nach konfigurierbarer Frist
  - manueller Anstoß von Abgleich und Verfall

### 1.4 Überweisungsvorlagen

- Vorlagen mit **Gruppen** (z. B. „Monatliche Fixkosten")
- Gruppen anlegen, umbenennen, löschen, farblich kennzeichnen
- Vorlagen anlegen, ändern, aktivieren/deaktivieren, löschen
- **Sammelanlage**: aus mehreren Vorlagen auf einmal Überweisungen erzeugen
- **Platzhalter** in Verwendungszweck und Betrag (client-seitig aufgelöst, z. B. Monat/Jahr)

### 1.5 Daueraufträge

- Dauerauftrag anlegen, ändern, löschen
- Frequenzen: **wöchentlich, monatlich, quartalsweise, jährlich**
- **Pausieren / reaktivieren / beenden**
- **Nächstes Ausführungsdatum** wird automatisch berechnet
- **Sofort ausführen** — erzeugt einen Überweisungsentwurf

### 1.6 SEPA-Lastschrift

- Lastschriftaufträge laden, anlegen, löschen (Entwürfe)
- **Einzug an die Bank senden** (PAIN.008.003.02, SEPA Core) inkl. TAN-Schritt
- **SEPA-Mandate**: anlegen, aktualisieren, widerrufen, löschen
- Mandatsarten **RCUR / FRST / FNAL / OOFF**, Gläubiger-ID, Mandatsreferenz, Höchstbetrag, Erteilungs- und Widerrufsdatum

### 1.7 Zuordnung & Verbuchung (Abgleich)

- **Offene Posten laden** für die Zuordnung — Debitoren (AR) **und** Kreditoren (AP)
- **Automatisches Matching** über Regeln
  - Regelkriterien: IBAN, Kundenname, Verwendungszweck, Betragsbereich, Buchungsschlüssel
  - Regeln anlegen, ändern, löschen
- **Zuordnungskandidaten je Umsatz** mit Konfidenzbewertung, sortiert
- **Manuelle Zuordnung** und **Zuordnung aufheben**
- **Verbuchen** in das Hauptbuch (`acc_trans`, kivitendo-kompatibel)
- **Storno** — Buchung eines Bankumsatzes rückgängig machen
- **Sammelzahlung**: ein Bankumsatz gegen mehrere Rechnungen, jede Rechnung erhält ihren Anteil als eigene Buchungszeile

### 1.8 Kartenabrechnungen / Settlements

Für Sammelauszahlungen von Kartendienstleistern (z. B. Flatpay, Rapyd, SumUp):

- **Upload der Abrechnung** als PDF, Excel oder CSV
- **Serverseitiges PDF-Parsing** — erkennt Auszahlungsdatum, Brutto, Gebühr, Netto
- Datei wird beim Kreditor abgelegt, Zeilen strukturiert gespeichert
- **Automatischer Vorschlag**: passende Abrechnungszeile zu einem nicht zuordbaren Bankumsatz (Netto-Betrag + Datumsnähe)
- **Teilmengen-Suche (Subset-Sum)**: findet die Kombination offener Ausgangsrechnungen, die in Summe dem Bruttobetrag der Abrechnungszeile entspricht
- **Split-Buchung**: Erlöse, Gebühren und Auszahlung in einem Vorgang; Konten je Kreditor merkbar
- **Storno** der Settlement-Buchung

### 1.9 Liquidität & Warnungen

- **30-Tage-Liquiditätsvorschau**: Kontostand + geplante Überweisungen + Daueraufträge
- **KI-Liquiditätsprognose** *(Schalter)* — tagesweise Prognose über die Claude API, basierend auf Monatsumsätzen und erkannten Zahlungsrhythmen
- **Banking-Alerts**: niedrige Salden, überfällige Daueraufträge, auffällige Vorgänge

### 1.10 Belegnummern

- Fortlaufende, lückenlose **Belegnummern je Geldkonto und Jahr** (Bankkonto und Kasse getrennt)
- Höchste Nummer wird über alle Speicherorte ermittelt, damit keine Nummer doppelt vergeben wird
- Vorschlagsnummer für den Zahlungsbeleg im Rechnungseditor und im Bankmodul

---

## 2. Buchhaltung

Eigener Menübereich mit acht Ansichten.

### 2.1 Übersicht / Dashboard

- Offene KI-Belege, gebuchte Belege, Buchungen des Jahres
- Einnahmen und Ausgaben des Jahres
- **Trend-Diagramm**: Einnahmen/Ausgaben der letzten 12 Monate aus dem echten Hauptbuch (netto, ohne Steuer)
- Eingangs- und Ausgangsrechnungen des laufenden Monats
- **Vorsteuer / Umsatzsteuer des Monats**, Zahllast bzw. Vorsteuerüberhang (UStVA-Kennzahlen)
- Nicht zugeordnete Bankbewegungen
- Offene Forderungen und Verbindlichkeiten mit Anzahl
- Letzte Buchungen, Schnellaktionen (z. B. Rechnung hochladen)

### 2.2 Eingangsrechnungen (Belegerfassung)

- **Upload** von Rechnungen (PDF/Bild)
- **KI-Analyse** des Belegs (Weroni): Lieferant, Rechnungsnummer, Datum, Beträge, Steuersätze, Kontierungsvorschlag
- **E-Rechnungs-Auslesung**: strukturierte ZUGFeRD-/XRechnung-Daten aus PDF oder XML
- Dokument jederzeit als PDF abrufbar (Vorschau am Buchungssatz)
- Liste bereits gebuchter Eingangsrechnungen
- **Buchen der Eingangsrechnung** inkl. Lieferanten-Auflösung in einem Schritt

### 2.3 Buchungen

Zwei Modi in einer Ansicht:

**KI-Vorschläge**
- Buchungsvorschläge prüfen, bearbeiten, **freigeben** oder **ablehnen** (mit Grund)
- **Stapelfreigabe** mehrerer Buchungen
- Anzeige der **KI-Sicherheit** je Vorschlag
- **Lieferanten-Zuordnung** über Kandidaten-Picker (Suche + Vorschläge), „Zuordnen & buchen"
- Konten-Typeahead für Soll- und Habenkonto
- Felder: Buchungsdatum, Belegdatum, Fälligkeit, Netto/Steuer/Brutto, Steuersatz, BU-Schlüssel, Belegnummer, Buchungstext, Kostenstelle

**Hauptbuch-Journal**
- Echtes Buchungsjournal aus `ar` / `ap` / `gl`
- Einzeltransaktion mit allen Soll-/Haben-Zeilen aus `acc_trans`

### 2.4 Zahlungseingänge

- **Automatischer Abgleich** von Ausgangsrechnungen mit Bankbewegungen
- Vorschau aller ungematchten Eingänge mit möglichen Treffern
- Match bestätigen und als Buchung erfassen

### 2.5 Offene Posten

- **Offene Forderungen** (Debitoren) und **offene Verbindlichkeiten** (Kreditoren)
- Sortiert nach ältester Fälligkeit, Kennzeichnung **überfällig** mit Tagesanzahl
- Summen: Anzahl offener Posten, Gesamtbetrag, davon überfällig
- Filter: nur laufendes Jahr, Kleinbeträge unter 1 € ausblenden
- Direktsprung zum Beleg

### 2.6 Lieferanten (Kreditoren)

- Lieferantenliste mit **Dublettenprüfer**
- Lieferant anlegen (mit Dublettenprüfung beim Speichern) und bearbeiten
- **Potenzielle Dubletten finden**
- **Lieferanten zusammenführen** (Deduplizierung inkl. Belegumhängung)

### 2.7 Kontenrahmen

- Vollständige Verwaltung des Kontenrahmens (**SKR03 / SKR04**, beim Setup importierbar, idempotenter Import in bestehende Firmen-DBs)
- Konto anlegen/bearbeiten: Kontonummer, Beschreibung, **Kontentyp** (Konto/Überschrift), **Kontoart** (Kosten, Aktiva, Passiva, Aufwand, Erlös), gültig/ungültig
- **Steuerautomatik**: mehrere **zeitabhängige Steuerschlüssel** je Konto („gültig ab"), **UStVA-Kennzahl** je Schlüssel
- **DATEV-Automatikkonto**-Kennzeichen
- **Verwendung in Buchungsmasken** steuerbar: Sammelkonto (Debitoren/Kreditoren/Warenbestand), Erlös, Aufwand, Aufwand/Anlage, Steuer, Zahlungseingang, Zahlungsausgang, Auswahllisten
- **Folgekonto mit Stichtag** — ab dem Datum wird automatisch auf das Nachfolgekonto gebucht
- **EÜR-Position** und **BWA-Position** je Konto
- Sperren, sobald auf ein Konto bereits gebucht wurde (Typ und Verknüpfung unveränderlich)
- Volltextsuche über Kontonummer und Beschreibung

### 2.8 DATEV-Export

- **Buchungsstapel im DATEV-Format EXTF 700** als CSV, direkt importierbar
- **Export-Paket als ZIP**: Buchungsstapel + alle gescannten Belege + Belegliste + Hinweisdatei für den Steuerberater
- Verknüpfung Buchung ↔ Belegdatei über „Beleginfo — Inhalt 1"
- DATEV-Konfiguration (Berater-/Mandantennummer, Wirtschaftsjahr etc.) speicherbar
- Eigener Konfigurations-Tab für DATEV-Prüfungen

> Hinweis: Eine ELSTER-Übermittlung der UStVA ist nicht enthalten — die UStVA-Kennzahlen werden ermittelt und angezeigt, die Übermittlung erfolgt über DATEV bzw. den Steuerberater.

### 2.9 Buchen aus der Faktura

- Ausgangsrechnungen werden **serverseitig ins Hauptbuch gebucht** (`acc_trans`)
- Eingangsrechnungen ebenso
- Cronjob für nachträgliches Buchen noch nicht verbuchter Rechnungen

---

## 3. Kasse / Kassenbuch

- **Kassakonten werden kontenrahmen-unabhängig erkannt** (Aktivkonto mit Zahlungsverknüpfung) — funktioniert mit SKR03 und SKR04 ohne feste Kontonummern
- **Kassenbuch** je Kassenkonto: Bewegungen aus `gl`, `acc_trans`, `ar` und `ap` in einer chronologischen Liste mit laufendem Saldo
- **Manuelle Kassenbuchung** anlegen und löschen (kivitendo-kompatibel über `gl` + `acc_trans`)
- **Gegenkonten**: Aufwand, Ertrag, Geldtransit sowie Bankkonten (Geld zur/von der Bank bringen)
- **Kassenbestandsprüfung**: eine Ausgabe darf den verfügbaren Bestand am Buchungstag nicht ins Minus bringen (niedrigster laufender Tagesend-Saldo)
- **Fortlaufende Belegnummer** je Kassenbuch, Vorschlag im Dialog
- **Buchungsvorschlag aus der Historie** (ohne KI): erkennt wiederkehrende Muster, z. B. „gestern 900 € zur Bank (Geldtransit) → heute 1200 € getippt"
- **Barzahlung von Ausgangsrechnungen** buchen (Einzahlung)
- **Barzahlung von Eingangsrechnungen** buchen (Auszahlung an Lieferanten)
- **Belege**: Upload, Zuordnung zu einer Kassenbuchung, Vorschau im Browser
- Monatsnavigation

---

## 4. Lager & Inventur

OpensourceERP arbeitet auf einer kivitendo-Datenbank und übernimmt deren Lagerlogik. Im Frontend ist der Lagerbereich derzeit **auf die Konfiguration beschränkt** — es gibt keine eigene Lagerbewegungs- oder Inventurmaske in der Vue-Oberfläche. Die Einstellungen wirken auf die zugrundeliegende kivitendo-Lagerverwaltung.

**Lager-Konfiguration**

- Standardlager und Standard-Lagerplatz
- Getrennte Vorgabe für Dienstleistungen
- Lagerplatz aus dem Artikelstamm übernehmen
- **Auslagern ohne Bestandsprüfung**: eigenes Lager und eigener Lagerplatz dafür
- Transfer- und Fertigungsverhalten (Auslagern beim Transfer, Seriennummer = Chargennummer)
- **Rückgängig-Frist** für Lagerbewegungen (Intervall in Tagen)
- **Mindesthaltbarkeitsdatum** anzeigen/verwenden
- **Erzeugnisfertigung**: nur aus demselben Lager, Dienstleistungen mit umlagern
- **Dienstleistungen in Lieferscheinen** prüfen (Verkauf und Einkauf getrennt)
- **Berechnung der Liefermenge**: erst nach tatsächlicher Auslagerung

**Inventur-Konfiguration**

- Inventur-Lager und Inventur-Lagerplatz
- **Stichtag** der Inventur
- **Mengenschwelle** für Abweichungen

**Verwandte Konten**

- Warenbestandskonto und Dienstleistungskonten je Buchungsgruppe (Tab „Standardkonten" / „Hinzufügen")

---

## 5. Faktura / Belege

### 5.1 Belegarten

Eine einheitliche Oberfläche für alle Belege:

- **Angebot**
- **Auftrag**
- **Rechnung**
- **Gutschrift**
- **Lieferschein**
- **Einkaufsanfrage** (Lieferant)
- **Bestellung** (Lieferant)
- **Reklamation**

### 5.2 Arbeiten am Beleg

- **Live-Suche** nach Kunden/Lieferanten und Artikeln während der Eingabe
- Positionen anlegen, ändern, löschen, **Bulk-Update mehrerer Positionen in einem Query**
- **Artikel einer bestehenden Position austauschen**
- Automatische Positionsnummerierung
- **Steuerberechnung** inkl. Steuerzonen
- Belegdatum, Lieferdatum, Fälligkeitsdatum einzeln änderbar
- **Kompakt- und Vollansicht** umschaltbar
- **Entwürfe** speichern und später weiterbearbeiten
- **Status offen/geschlossen**, Auftragsbestätigung setzen und zurücknehmen
- **Beleghistorie** und Belegverknüpfungen (Record-Links)
- **Wiedervorlage direkt am Beleg** anlegen
- Löschen (konfigurierbar, welche Belegarten gelöscht werden dürfen)

### 5.3 Workflow / Konvertierung

Aus einem Beleg heraus erzeugen:

- Beleg wiederverwenden
- Angebot → Auftrag → Lieferschein → Rechnung
- Gutschrift, Stornierung
- Reklamation
- Einkaufsanfrage, Lieferantenbestellung
- Verkaufs- ↔ Einkaufsbelegart wird automatisch aufgelöst, wenn der Partner ein Lieferant ist

### 5.4 Ausgabe

- **PDF-Vorschau** und -Erzeugung aus Templates
- **Template-Sets**: Liste verfügbarer Sets, neues Set als Kopie eines Master-Sets anlegen, aktives Set speichern
- **Automatische Template-Erkennung** je Belegart
- **Druckerauswahl** und Direktdruck
- **Sammel-PDF**: mehrere Belege zu einem PDF zusammenführen
- **Versand per E-Mail**
- **Versand per WhatsApp** (Dokument als Template-Nachricht oder im Chat)
- **DHL-Versandetikett** aus dem Beleg *(extern)*
- **Auf dem Wall-Display anzeigen** (Kundenpräsentation)

### 5.5 E-Rechnung

- Erzeugung von **ZUGFeRD / Factur-X** (PDF mit eingebettetem XML) und **XRechnung**
- **XML-Vorschau** ohne PDF-Erzeugung (Debug/Prüfung)
- Alle Daten in einer Query geladen
- **Auslesen** eingehender E-Rechnungen aus PDF oder XML
- Eigener Konfigurations-Tab (Leitweg-ID, Profil etc.)

### 5.6 Belegsuche

- **Auftragssuche** mit Filtern
- **Gutschriftenliste**
- Globale Dokumentensuche über alle Belegarten (siehe [Globale Suche](#94-globale-suche))

---

## 6. CRM — Kunden & Lieferanten

### 6.1 Stammdaten

- Kunden und Lieferanten in einer Maske, umschaltbar
- Karten: Name & Adresse, Kommunikation, Nummern & IDs, Zugangsdaten, Info & Status, Währung/Preise/Steuer, Konditionen
- Beliebig viele **Ansprechpartner** mit Rollen und Abteilungen
- Mehrere **Rechnungsadressen** und **Lieferadressen**
- **Bankdaten**, Zahlungsbedingungen, Kreditlimits, Skonto
- **Benutzerdefinierte Variablen** (Custom Vars)
- **Notizen**
- **Preisregeln** und Preisgruppen je Kunde

### 6.2 Eingabehilfen & Prüfungen

- **Dublettenprüfung** beim Anlegen über pg_trgm-Ähnlichkeit
- **USt-IdNr.-Validierung** über die EU-Schnittstelle **VIES**
- **PLZ-Lookup** — Ort automatisch aus der Postleitzahl
- **E-Mail-Validierung** mit Format- **und** DNS-Prüfung (MX/A-Record)
- **Anrede automatisch** aus dem Vornamen (Vornamen-Geschlechts-Tabelle)
- **Visitenkarte scannen** — KI liest Stammdaten aus, prüft die Domain auf Erreichbarkeit, korrigiert typische OCR-Fehler und validiert die Adresse über Nominatim/OpenStreetMap
- **Rückwärtssuche zur Telefonnummer** — KI-Websuche nach dem Inhaber

### 6.3 Auswertung & Historie

- **Umsatzstatistik** mit Diagrammen (Jahresvergleich, Trend)
- **Vorgangsübersicht** je Kunde (Angebote, Aufträge, Rechnungen, Lieferscheine)
- **CRM-Dashboard** je Kunde: Kontaktdaten, letzte Vorgänge, Umsatzhistorie, Dokumente auf einen Blick
- **E-Mail-Tab** und **WhatsApp-Tab** je Kunde
- **Anrufhistorie** je Kunde

### 6.4 Dateimanager

- Vollwertiger Dateimanager je Kunde/Lieferant (**VueFinder**): auflisten, hochladen, herunterladen, umbenennen, verschieben, kopieren, duplizieren, löschen, Ordner anlegen, suchen, Vorschau, Texteditor
- **Mandantenspezifische Datenverzeichnisse**, konfigurierbare Verzeichnisrechte und Gruppenbesitzer
- **Path-Traversal-Schutz**, Symlink-Unterstützung
- **Dokumenten-Chat**: KI-Frage zu einem Dokument aus dem Dateimanager stellen

### 6.5 Datenschutz

- DSGVO-konforme Datenschutzerklärung, öffentlich ohne Login erreichbar
- **Datenlöschungsantrag** direkt im System, ebenfalls öffentlich erreichbar

---

## 7. Artikel & Preise

- Artikelstammdaten anlegen, laden, bearbeiten
- **Artikelsuche** mit Pagination, Sortierung und Filtern
- **Intelligenter Artikeltyp-Vorschlag** — lernt dynamisch aus der Datenbank statt fester Listen
- Buchungsgruppen, Erlös-/Aufwands-/Bestandskonten
- Einheiten aus der Datenbank (nicht hartkodiert)
- Kundenspezifische Preise und Preisgruppen
- Benutzerdefinierte Variablen
- **Artikelbilder** (u. a. für eBay-Listings): hochladen, listen, löschen

---

## 8. Kommunikation

### 8.1 E-Mail-Client

- **IMAP-Posteingang** direkt im ERP, Ordnernavigation (Posteingang, Gesendet, Entwürfe …)
- **SMTP-Versand**, Mehrfach-Konten
- Einzelmail mit Body und **Anhängen** lesen
- **Automatische Kundenzuordnung** über die Absenderadresse
- **E-Mail-Journal** mit vollständiger Historie
- Suche und Filterung, Empfänger-Autocomplete aus dem Kundenstamm
- **Verbindungstest** für IMAP und SMTP
- **Brevo (Sendinblue)** für Templates und Marketing *(extern)*

### 8.2 WhatsApp Business *(extern)*

- Vollständige Anbindung der **Meta WhatsApp Cloud API**
- Nachrichten senden und empfangen, **Chatverlauf** und **Konversationsübersicht**
- **Medien**: Bilder, Audio, Video, Dokumente abrufen und **im Kunden-/Lieferantenordner speichern** (Ordnerauswahl-Dialog)
- **Dokumente versenden** — als Template-Nachricht mit Dokument-Header oder regulär im 24-Stunden-Fenster
- **Standort/Adresse senden** (Google-Maps-Link per Template)
- **Nachrichtenvorlagen**: anlegen, bei Meta einreichen, Status synchronisieren, löschen, Standardvorlagen laden
- **Automatische Erinnerungen** (Cronjob), z. B. Terminbestätigungen
- **Profilbild** des WhatsApp-Kontos abrufen und aktualisieren
- Echtzeit-Statusupdates (gesendet/zugestellt/gelesen) über SSE
- **Webhook** für eingehende Nachrichten
- Nachrichten als gelesen markieren, ausblenden (Soft-Delete)

### 8.3 Telegram

- **Telegram-Bot-Anbindung** (kostenlos, kein Drittanbieter nötig)
- Nachrichten erscheinen im selben Nachrichten-Tab wie WhatsApp, mit Kanal-Icon
- Kein 24-Stunden-Fenster, keine Vorlagen-Genehmigung
- Zuordnung über die **Telegram-Chat-ID**, automatisch beim Erstkontakt gespeichert
- **Webhook** mit Secret

### 8.4 Telefonie / CTI *(extern)*

- **Anrufliste** über alle Kunden und Lieferanten, mit Filtern für Suche, Richtung und Zeitraum
- **Automatische Zuordnung** von Anrufen zu Kunden, manuelle Zuordnung/Aufhebung
- **Click-to-Call** über das **Asterisk Manager Interface**
- Verfügbare Telefone und Kontexte auslesen, benutzerspezifische Einstellungen speichern
- **Gesprächsmitschnitt** abspielen (CRMTI-Verknüpfung)
- **KI-Transkription** von Anrufen
- **Werkstattauftrag aus einer Telefonaufnahme generieren**

---

## 9. Organisation & Wissen

### 9.1 Kalender

- Monats-, Wochen- und Tagesansicht (FullCalendar v6)
- Termine anlegen, ändern, löschen, per **Drag & Drop** verschieben und in der Größe ändern
- **Wiederkehrende Termine** mit Expansion über den angezeigten Zeitraum, Wiederholungsende aus Anzahl × Intervall
- **Farbkodierte Kategorien** — anlegen, ändern, löschen
- **Suche** über alle Zeiträume
- **Feiertage** für den Firmenstandort
- **Import** aus **iCal**, **CSV** und **TXT** mit automatischer Trennzeichen-, Header- und Datumsformat-Erkennung
- Echtzeit-Aktualisierung über SSE
- Steuerbefehle an die Wandanzeige senden

### 9.2 Wiedervorlage

Drei Ansichten auf einer Datenbasis:

- **Kanban-Board** mit Drag & Drop (Überfällig / Heute / Kommend)
- **Listenansicht** mit Sortierung und Filterung
- **Kalenderansicht**
- Verknüpfung mit Kunden, Aufträgen, Rechnungen und weiteren Entitäten (Suche über alle Typen)
- Zuweisung an Mitarbeiter
- Erledigt/offen mit **Undo**
- Dashboard-Widget, gruppiert nach Priorität

### 9.3 Aufgaben

- Aufgaben anlegen, zuweisen, bearbeiten, erledigen, wieder öffnen, löschen
- Dashboard nach Priorität
- Verknüpfung mit Projekten und Pflichtenheften

### 9.4 Globale Suche

- **Modulübergreifende Schnellsuche**: Kunden, Lieferanten, Kontakte, Artikel, Belege *(und Fahrzeuge bei LxCars)*
- **Erweiterte Suche** mit WHERE-Bedingungen je Feldtyp und Tabelle
- Spaltenliste je Tabelle abrufbar
- **SQL-Query-Builder** für Power-User mit Validierung gefährlicher Verben
- **Gespeicherte Suchabfragen** je Benutzer, anlegen und löschen
- Dokumentensuche über Rechnungen, Angebote, Aufträge, Bestellungen, Lieferscheine

### 9.5 Wiki

- Internes Dokumentationssystem mit **Rich-Text-Editor (Tiptap)**
- **Kategorien** mit eigener Sortierung (Batch-Update der Reihenfolge)
- **Versionierung**: Revisionshistorie und Wiederherstellung früherer Stände
- Volltextsuche
- **SEO-freundliche Slug-URLs**

### 9.6 Anschlagtafel & Sprachnotizen

- **Anschlagtafel** als Vollbildanzeige für Werkstatt/Büro
- **Sprachnotizen** — per Telegram eingesprochen, lokal durch **Whisper** transkribiert, erscheinen auf der Tafel
- **PC-Tafel** zur Verwaltung: Notizen direkt am Rechner hinzufügen, per **Drag & Drop** sortieren, ausblenden
- Mitarbeiterauswahl für das „Von"-Feld

### 9.7 Wall-Display / Digital Signage

- Großbildschirm-Modus, zwei Betriebsarten: **Kalenderanzeige** oder **Belegansicht** für Kundenpräsentationen
- Vollbild, Echtzeit-Aktualisierung über SSE
- Fernsteuerung der Ansicht aus dem ERP heraus (`pg_notify`)
- Als Startansicht konfigurierbar

### 9.8 Projektmanagement

- **Pflichtenhefte**: anlegen, bearbeiten, löschen, Positionen als Baum (Abschnitte und Funktionsblöcke), Stammdaten für Typen, Status, Komplexitäten, Risiken
- **Ticketsystem** mit **Kanban-Board**: Tickets anlegen, Status per Drag verschieben, Kommentare, **Labels**, Zuweisung an Mitarbeiter, Verknüpfung mit Projekten und Pflichtenheften

### 9.9 Dokumentation im System

- Eingebauter **Doku-Viewer** für die Markdown-Dokumentation (`/doku`)

---

## 10. Personal (HR)

- **HR-Dashboard**: aktive Mitarbeiter, laufende Abrechnungen, offene Urlaubsanträge

**Lohnabrechnung**

- **Gehaltseinstellungen** je Mitarbeiter
- **Abrechnungsläufe** anlegen (automatisch mit Mitarbeiterdaten befüllt), einzelne Positionen bearbeiten
- **Finalisieren** (Entwurf → final), Löschen nur im Entwurfsstatus
- Übersicht aller Läufe mit Zusammenfassung

**Lohnsteuer**

- **Lohnsteuertabelle wird lokal berechnet** — PAP-Algorithmus, kein Internetzugriff nötig
- Aufbau je Jahr, Status- und Vorschauanzeige
- **Automatische Berechnung von Lohnsteuer und Solidaritätszuschlag** für alle Positionen eines Lohnlaufs
- **Schnellvorschau** für frei eingegebene Parameter

**Urlaub**

- Jahresübersicht über alle Mitarbeiter
- Urlaubsanträge anlegen und aktualisieren
- **Genehmigen / ablehnen / löschen**
- **Jahresurlaubsanspruch** je Mitarbeiter pflegen

---

## 11. KI-Funktionen

### 11.1 Weroni — KI-Bürokauffrau

- Chat-Assistent, erreichbar über die Navigationsleiste
- **Werkzeugzugriff** auf das System (definierte Tools, kontrolliert ausgeführt)
- **Dokumentenanalyse** mit Claude Vision — Belege werden erkannt und weitergeleitet
- **Aufgabenverwaltung**: Weroni legt Aufgaben an; im Assistenten-Modus müssen sie **bestätigt** oder **abgelehnt** werden
- **Rückfragen** bei kritischen Aktionen, Beantwortung direkt in der Oberfläche
- **Aktionsprotokoll** — alles, was Weroni getan hat
- Badge in der Navigationsleiste mit der Zahl offener Rückfragen
- Konversationsverlauf speicherbar und löschbar
- Hintergrund-Monitor als Cronjob

### 11.2 Spracheingabe

- **Lokaler Whisper-Dienst** (eigener Python-Server, keine Cloud nötig)
- Aufnahme direkt im Browser, Transkription im Backend
- **Fachbegriffe-Glossar**: wird aus den Stammdaten (Artikelbeschreibungen u. a.) automatisch gelernt und an Whisper übergeben — dadurch werden branchenspezifische Begriffe korrekt erkannt
- Glossar einsehbar und neu aufbaubar
- Gedacht als **ergonomische Entlastung** für alle, die ungern oder langsam tippen

### 11.3 Lokales LLM

- Wiederverwendbarer Client für ein **lokal gehostetes LLM (Ollama, OpenAI-kompatibel)**
- Wird u. a. für die Umwandlung gesprochener Daten in strukturierte Felder genutzt

### 11.4 Cloud-KI *(extern)*

- **Anthropic Claude** — Zusammenfassungen, Vorschläge, Liquiditätsprognose, Dokumentenanalyse, Websuche
- **OpenAI Whisper** — Anruftranskription
- API-Keys und Gewichtungen im Tab „KI und Gesundheit"

---

## 12. Kamera & Überwachung

### 12.1 NVR / Videoüberwachung *(Schalter `feature_nvr`)*

- **Kameras** anlegen, bearbeiten, deaktivieren (Soft-Delete)
- **Zonen** je Kamera definieren
- **Regeln** anlegen und verwalten
- **Ereignisse** mit Filter, einzeln oder alle einer Kamera als gelesen markieren
- **Dashboard-Statistiken**
- **Automatische Kamerasuche im Netzwerk** — gefundene Kameras werden direkt eingetragen
- **go2rtc-Integration**: Installationsbefehle generieren (Copy-Paste), Binary automatisch installieren, Config anlegen, systemd-Dienst einrichten, Status abfragen, Verbindung erkennen und speichern
- **Hardware-Erkennung** für KI-Beschleunigung, Installation von **OpenVINO, pycoral, tflite-runtime, ultralytics** aus der Oberfläche
- **CPU-Kernauslastung in Echtzeit**
- **camera-monitor-Dienst** einrichten, Status prüfen, neu starten
- Feature-Dienste gezielt starten und stoppen

### 12.2 ANPR — Kennzeichenerkennung

Siehe [Abschnitt 16.13](#1613-anpr--kennzeichenerkennung).

---

## 13. Versand, Bezahlung, Marktplätze

### 13.1 DHL *(extern)*

- **Versandetikett erstellen** aus dem Beleg
- **Label-PDF** einer bestehenden Sendung abrufen
- **Sendung stornieren**
- Sendungsliste je Beleg
- Konfiguration inkl. Abrechnungsnummer (EKP + Verfahren + Teilnahme) und Absenderdaten

### 13.2 SumUp *(extern)*

- **Kartenleser koppeln** und entkoppeln
- Kopplungsstatus in der Konfiguration
- **Karten-Checkout**: Betrag direkt an den gekoppelten Reader senden

### 13.3 eBay *(extern)*

- **Verbindungstest** (Token + Probeabruf), Sandbox und Produktion
- **Bestellimport**: neue Bestellungen seit dem letzten Lauf, idempotent — Kunde wird ohne Dubletten ermittelt oder angelegt, Positionen werden per SKU auf Artikel gemappt, Rechnung wird erzeugt
- Manueller Sync und automatischer Cronjob
- **Status**: letzter Lauf, zuletzt importierte Bestellungen
- **Listings**: Artikel bei eBay einstellen (Inventory Item → Offer → Publish), Listing beenden (withdraw), Status je Artikel
- **Artikelbilder** hochladen, listen, löschen; öffentliche Bild-URLs (https) für eBay
- Kennzeichen „eBay-Artikel" je Artikel

### 13.4 eLetter / Briefversand *(extern)*

- Versand generierter PDFs per **SFTP an einen Briefdienstleister** (genutzt für den HU-Serienbrief)

---

## 14. System, Rechte, Mandanten

### 14.1 Mandanten (Multi-Tenant)

- Mehrere Firmen mit **getrennten Datenbanken**
- **Firmenwechsel im laufenden Betrieb** ohne Neuanmeldung
- **Neue Firmendatenbank aus der Oberfläche anlegen** (Berechtigung erforderlich)
- Firmenspezifische Konfiguration und Datenverzeichnisse

### 14.2 Benutzer & Rechte

- Anmeldung gegen die kivitendo-Auth-Datenbank, Passwort-Hashing kompatibel (PBKDF2)
- **Session-Wiederherstellung**
- **Rollenbasierte Rechte**, Berechtigungen pro Belegart (Rechnungen, Aufträge, Angebote, Lieferscheine …)
- Rechteprüfung im Router — geschützte Ansichten leiten mit Hinweis zurück
- **Gruppenverwaltung** je Mandant
- **Benutzerspezifische Einstellungen** (eigene Konfigurationsseite)
- Mitarbeiterverwaltung inkl. „obsolet" setzen und reaktivieren

### 14.3 Nummernkreise

Zwei sauber getrennte Typen:

- **Geschützt (rechtssicher)** — Rechnungen, Aufträge, Angebote, Lieferscheine: atomar vergeben, lückenlos, nur aufsteigend
- **Frei** — Kunden-, Lieferanten-, Artikel-, Dienstleistungs-, Erzeugnisnummern: mit Kollisionserkennung, manuell setzbar

### 14.4 Konfiguration

- Umfangreiche Firmenkonfiguration mit **Feldsuche über alle Tabs** (Suche nach Stichworten wie „iban", „inventur", „zugferd")
- Werte liegen in `defaults` (kivitendo) und `defaults_oserp` (Erweiterungen, Key/Value)
- Einzelwerte werden **automatisch gespeichert** (Deep-Watcher mit Verzögerung)
- Mitarbeiterbezogene Einstellungen separat speicherbar
- Stammdaten anlegen: **Buchungsgruppen, Steuerzonen, Steuersätze, Bankkonten**
- **Drucker** verwalten (Bezeichnung + Druckbefehl)
- **Startansicht** wählbar (Menü, CRM, Wall-Display, Anschlagtafel, Mechaniker-Modus)

### 14.5 Mehrsprachigkeit

- **21 Sprachen**: Deutsch, Englisch, Polnisch, Ukrainisch, Russisch, Französisch, Niederländisch, Dänisch, Norwegisch, Schwedisch, Estnisch, Lettisch, Litauisch, Spanisch, Italienisch, Portugiesisch, Tschechisch, Rumänisch, Türkisch, Finnisch, Chinesisch
- **Übersetzte URLs**: `/kunde` ↔ `/customer` ↔ `/client` — beim Sprachwechsel bleibt die Ansicht erhalten, alte Lesezeichen funktionieren weiter (alle Sprachvarianten sind als Alias registriert)
- Datums- und Zahlenformatierung je Sprache
- Locale-Dateien liegen neben ihren Views, automatische Erfassung per `import.meta.glob`

### 14.6 Echtzeit (SSE)

- Eigener **Node.js-SSE-Server**
- Live-Updates für neue E-Mails, WhatsApp- und Telegram-Nachrichten, Wiedervorlagen, Kalendereinträge, Anrufe, ANPR-Erkennungen, Ersatzteilanforderungen
- **Infoleiste** mit Echtzeit-Zählern und farbigen Chips je Ereignistyp
- Automatische Reconnection bei Verbindungsabbruch

### 14.7 Setup & Update

- **Setup-Wizard** beim ersten Aufruf: Datenbankdaten eingeben, Verbindung testen, `settings.ini` anlegen
- **Automatische Übernahme** vorhandener kivitendo-Konfiguration (`kivitendo.conf`)
- **Schema-Update**: fehlende Tabellen und Spalten werden aus den SQL-Dateien erzeugt, Indizes und Views geprüft, CSV-Stammdaten importiert
- **Alle Mandantendatenbanken auf einmal aktualisieren**
- Anzeige der Git-Commit-Hashes (lokal und Remote) zur Versionskontrolle

---

## 15. Developer- & Admin-Werkzeuge

- **API-Tester**: alle API-Ordner und Funktionen auflisten, Parameter samt Typinformationen anzeigen, Beispielwerte aus den `@testdata`-Angaben generieren und Aufrufe direkt absetzen
- **Automatische Tests**: alle API-Funktionen durchtesten, Routen ermitteln, **Workflow-Tests** über mehrstufige Geschäftsprozesse
- **SQL-Werkzeug**: mehrere Statements, Ergebnisse **direkt editierbar** (Primärschlüssel wird automatisch ermittelt), Zeilen ändern und löschen, Autovervollständigung für Tabellen, Spalten und Keywords, Tabellenstruktur mit Spaltenkommentaren
- **Query-History** je Benutzer: speichern, laden, einzeln oder komplett löschen
- **Datenbank-Backup**: manuell und automatisch erstellen (`pg_dump`), auflisten, wiederherstellen, herunterladen, einzeln oder komplett löschen — je Datenbank
- **Schema-Verwaltung / Migrationssystem**
- **Store-Viewer**: Live-Einblick in den Pinia-Store
- **Ticketsystem** und **Pflichtenhefte** (siehe 9.8)
- **Demo-Reset**: Demo-Datenbank auf den Ausgangszustand zurücksetzen
- **Logging** mit Levels, Query-Interpolation und Parameter-Dumps

---

# Teil B — LxCars

## 16. LxCars — was zusätzlich dazukommt

**Aktivierung:** Einstellungen → Features → „LxCars" auswählen und speichern. Nach dem Neuladen erscheinen die LxCars-Menüpunkte, ein eigener Konfigurations-Tab und alle unten beschriebenen Funktionen.

Was sich sofort ändert:

- Neues Hauptmenü **Fahrzeuge** (Auftragsliste, Scanliste, Neu anlegen, Verwalten — und bei aktiviertem Mechaniker-Modus zusätzlich „Meine Aufträge")
- Im Menü **Kontakt** kommt **HU-Benachrichtigungen** dazu
- In der **globalen Suche** werden Fahrzeuge mitgesucht
- In der **Faktura** erscheinen zusätzliche Schaltflächen (Fahrzeug, SilverDAT, AAG-Online, ESI[tronic], mega macs, HGS-Data, AU-Beleg)
- Neuer Konfigurations-Tab **LxCars**, zusätzlich **ANPR**
- Im **Kalender** wird die Werkstattauslastung je Bringetermin-Tag angezeigt
- Im **Wiki** lassen sich Artikel einer HSN/TSN-Kombination zuordnen

---

### 16.1 Fahrzeugstammdaten

- Fahrzeug anlegen, bearbeiten, löschen (inkl. rekursivem Aufräumen des Fahrzeugverzeichnisses)
- **Identifikation**: Kennzeichen, HSN (2.1), TSN (2.2), Typ (D.2), Emissionsklasse (14.1), Erstzulassung, HU-Datum, FIN, FIN-Prüfziffer
- **Prüf-Häkchen** je Feld (Kennzeichen, HSN, TSN, Emission, FIN, HU geprüft)
- **Fahrzeugdaten**: Marke, Modelltyp, Fahrzeugtyp, Farbe, Getriebeart, Motorkennbuchstabe (Auswahl aus tatsächlich verbauten Motoren), Besitzer, externe ID
- **Personenbeförderung** (Taxi/Mietwagen) als Kennzeichen
- **Reifen**: Sommer- und Winterreifentyp, Lagerort und Zustand je Satz
- **Wartung & Service**: Steuerkette, Zahnriemenwechsel (Datum und km), nächster Bremsflüssigkeitswechsel, nächster Wartungsdienst, letzter km-Stand
- **Notizen**
- **Automatisches Speichern** während der Eingabe
- **Kennzeichen-Prüfung** auf Doppelvergabe — einzeln und im Stapel
- **FIN-Prüfung** auf Doppelvergabe
- **Kennzeichen-Formatierung und -Validierung** (eigene Utility)
- **Felder per Klick in die Zwischenablage** (Kennzeichen, HSN, TSN, FIN; per Doppelklick der komplette KBA-Block)
- **Fahrzeugdaten exportieren**
- **Fahrzeugdaten per E-Mail versenden** — Betreff, Text und „Vollständige Daten anhängen" konfigurierbar

### 16.2 KBA-Datenbank

- **Lookup über HSN + TSN**, optional zusätzlich D.2
- **Nur-HSN-Suche** (alle TSN-Varianten eines Herstellers)
- **Fuzzy-Lookup** — prüft, ob die Kombination exakt existiert
- **Fahrzeugspezifische KBA-Daten** (`special_kba`) für Fahrzeuge, die nicht im Standardbestand stehen
- Über 50 KBA-Felder werden übernommen und angezeigt: Hersteller, Handelsbezeichnung, Fahrzeug- und Aufbauart, Hubraum (P.1), Leistung/Drehzahl (P.2/P.4), Kraftstoff (P.3), Achsen (L), Antriebsachsen, Massen (F.1/F.2/G), Achslasten (7.x/8.x), Anhängelasten (O.1/O.2), Sitz- und Stehplätze (S.1/S.2), Bereifung (15.1/15.2), Geräuschwerte (U.1–U.3), CO₂ (V.7), Schadstoffklasse (V.9), Emissionsklasse, Leistungsgewicht (Q), Vmax

### 16.3 Fahrzeugschein-Scan

- **Scan über fahrzeugschein-scanner.de** *(extern)* — Foto hochladen, Felder werden ausgelesen
- **Scanliste**: die letzten Scans abrufen, neue Scans von der API nachladen und speichern
- **Detaildaten** eines Scans
- **Bilder**: Originalbild und **Feld-Ausschnitte (Crops)** werden gespeichert; einzelne Crops oder die komplette Liste als Base64 abrufbar; Zwischenspeicher für noch nicht zugeordnete Scans
- **Automatisches Mapping** der Rohdaten auf Fahrzeug- und KBA-Felder, inkl. Datumsnormalisierung und Erkennung von Personenbeförderung
- **Automatische Kundenzuordnung** über Namensähnlichkeit + exakte Adresse
- **Manuelle Suche** nach Kunde oder Fahrzeug zur Zuordnung
- **Dublettenprüfung** vor dem Anlegen eines neuen Kunden aus dem Scan

### 16.4 Fahrzeug-Dateien

- Eigenes Verzeichnis je Fahrzeug, **automatisch angelegte Unterordner** (konfigurierbare Liste)
- Dateidialog direkt aus der Fahrzeugmaske
- Pfade werden idempotent sichergestellt, Symlinks zum Kundenordner aktuell gehalten

### 16.5 Rotes Heft

- Eigener Dialog zur Erfassung/Anzeige der Daten des roten Fahrzeugbriefs bzw. der Zulassungsbescheinigung Teil II

### 16.6 Fahrzeugzulassung

- **Zulassungsformular** aus Fahrzeug- und Kundendaten befüllt
- **PDF-Erzeugung mit FPDI** auf Basis des amtlichen Vordrucks

### 16.7 Fahrzeugverkauf

- **Verkaufsdialog** je Fahrzeug
- **KI-Verkaufstext-Generator** — erzeugt einen Inseratstext aus den Fahrzeugdaten, System-Prompt konfigurierbar
- Text bearbeitbar und als `verkaufstext.txt` beim Fahrzeug speicherbar

### 16.8 Etikettendruck (ZPL)

- **Grüne Plakette / gelbes Etikett** — Kennzeichen groß plus Firmen-URL, druckt direkt auf den konfigurierten ZPL-Etikettendrucker
- **Reifenetiketten** — 4 Stück pro Satz (vorne rechts/links, hinten rechts/links) mit Kennzeichen, Reifengröße und Lagerposition; getrennt für Sommer- und Winterreifen
- Beide Drucker werden in der LxCars-Konfiguration aus der Druckerliste zugeordnet

### 16.9 Spracheingabe für Wartungsdaten

- Wartungsdaten **einsprechen** statt tippen: „Kilometerstand 120369, Zahnriemen fällig bei 20000, Bremsflüssigkeit 02/2029"
- Lokales Whisper transkribiert, ein **lokales LLM wandelt den Text in strukturierte Felder**
- Übernommene Felder werden angezeigt

### 16.10 KI-Assistenz

- **KI-Chat je Fahrzeug** — Werkstattmeister-Assistent, der Fahrzeugdaten, KBA-Spezifikationen und Auftragshistorie kennt; Verlauf speicherbar und löschbar; System-Prompt konfigurierbar
- **KI-Positionsvorschläge** für Werkstattaufträge — aus den Arbeitsanweisungen, der nach Alter gewichteten Auftrags-/Rechnungshistorie des Kunden und den Fahrzeugdaten; bestätigte Vorschläge werden als Positionen eingefügt
- **KI-Zeitvorschläge** für Arbeitsanweisungen (geplante Minuten)

### 16.11 Werkstattaufträge

Werkstattaufträge sind normale Belege mit LxCars-Erweiterungen (`oe_ext` / `ar_ext`):

- **Fahrzeug mit Auftrag bzw. Rechnung verknüpfen** und Verknüpfung wieder lösen
- **Kilometerstand** am Auftrag und an der Rechnung
- **Plausibilitätsprüfung des km-Stands** gegen alle früheren Aufträge und Rechnungen desselben Fahrzeugs
- **KFZ-Ort** (wo steht das Fahrzeug) — Auswahlliste konfigurierbar
- **Auftragsstatus** — Liste frei konfigurierbar (z. B. Angenommen, In Arbeit, Warte auf Teile, Fertig, Abgeholt), inkl. Status zum Ausblenden aus der Liste
- **Bringetermin und Fertigstellung** mit Vorgabezeiten und Zeitraster
- **Kalender-Synchronisation** der Auftragstermine
- **WhatsApp-Terminbestätigung** zum Bringetermin (entprellt ausgelöst)
- Kennzeichen „gedruckt" und „intern"
- **Fahrzeugauswahl im Auftrag** aus allen Fahrzeugen des Kunden
- Alle LxCars-Initialdaten einer Faktura werden in **einem einzigen Aufruf** geladen
- **Auslastungsanzeige im Kalender** (geplante Minuten je Bringetermin-Tag)

### 16.12 Arbeitsanweisungen

- Anweisungen je Auftrag anlegen, ändern, löschen, alle löschen
- **Reihenfolge per Drag & Drop**
- **Master-Anweisungsliste** als Stammdaten: Autocomplete-Suche, Verwaltungsdialog zum Anlegen/Ändern/Löschen, Prüfung auf doppelte Anweisungsnummern
- Bestehende Auftragsanweisung durch eine Master-Anweisung **ersetzen**
- **Nummernkreis** mit konfigurierbarem Präfix und Zähler
- **Geplante Minuten** je Anweisung
- **Mitarbeiterzuweisung** einzeln oder für alle Anweisungen eines Auftrags
- Status offen/erledigt
- **Timer**: Start/Stopp je Anweisung, Nettoarbeitszeit wird addiert; der aktuell laufende Timer eines Mitarbeiters ist auftragsübergreifend abrufbar

### 16.13 Mängelerfassung

- Mängel je Auftrag **oder** Rechnung erfassen, ändern, löschen, alle löschen
- **Mängelliste als Stammdaten** mit Autocomplete
- **Mängelklassen** und Freitext-Notiz je Mangel
- **Eigene Mängel** anlegen, die nicht in der Stammliste stehen

### 16.14 Mechaniker-Modus

Vereinfachte, tablet-taugliche Ansicht für die Werkstatt. Aktivierung und Mitarbeitergruppe in der LxCars-Konfiguration; optional als **Startansicht**.

- **Meine Aufträge** / **Alle Aufträge** umschaltbar, Filter über Kennzeichen, Kunde, Auftrag, Fahrzeug
- Auftragsdetail mit **Anweisungen, Positionen, Mängeln und Ersatzteil-Warenkorb**
- Anweisungen abhaken, **Timer starten/stoppen**, Soll- gegen Ist-Minuten
- Fahrzeugansicht schreibgeschützt aufrufbar
- **Aufträge, bei denen alle Anweisungen erledigt sind** (letzte 24 h) als eigene Liste

**Ersatzteil-Anforderungen**

- Teil per Artikelsuche oder als **Freitext** anfordern — legt gleichzeitig eine Auftragsposition an
- Bestehende Position als „muss bestellt werden" markieren, Markierung wieder entfernen
- **Fotos** zur Anforderung aufnehmen (mehrere je Anfrage), abrufen und einzeln löschen
- **Lieferant** wählen — Liste sortiert nach Bestellhäufigkeit, zusätzlich Namenssuche
- Status-Workflow: **Angefordert → Bestellt → Eingetroffen**, Rücksetzen auf „Angefordert" möglich
- Anfrage bearbeiten und löschen (nur solange offen)
- **Bestellstatus aller Positionen** eines Auftrags auf einen Blick
- Offene Anforderungen erscheinen gruppiert nach Auftrag als **Chips in der Infoleiste**

**„Auftrag fehlt"-Meldung**

- Fahrzeug per Name, Kennzeichen oder FIN suchen und melden, dass kein Auftrag existiert
- Alternativ als **Freitext** melden
- Offene Meldungen erscheinen in der Infoleiste und können dort erledigt werden

### 16.15 Auswertungen

- **Meine Auswertung** je Mechaniker — Tag/Woche/Monat:
  - produktive Stunden, Auslastung, Effizienz (Soll vs. Ist)
  - bearbeitete Aufträge und Fahrzeuge (mit Kennzeichen und Typ)
  - erledigte Anweisungen, bester Tag, persönlicher Rekord
  - Arbeitsstunden pro Tag als Diagramm
  - häufigste Tätigkeiten mit Anzahl und Durchschnittszeit
- **Team-Übersicht** für die Werkstattleitung — Team-Stunden, geplante Zeit, Effizienz, Auslastung gegen die Kapazität
- Arbeitszeitberechnung mit **Arbeitsbeginn, Arbeitsende und Pausenabzug** aus der Konfiguration
- Zugriff auf die Team-Auswertung über eine konfigurierbare Benutzergruppe

### 16.16 HU-Serienbrief / HU-Benachrichtigungen

- Liste aller Kunden mit **demnächst fälliger Hauptuntersuchung** (Vorlauf in Monaten konfigurierbar)
- **Ausschlüsse**: je Kunde komplett („kein Serienbrief") und je Fahrzeug einzeln abschaltbar
- **PDF-Serienbrief** generieren — Brieftext als Vorlage mit Platzhaltern (`{anrede}`, `{name}`, `{fahrzeugliste}`, `{mitarbeiter}`)
- **Versand per SFTP an einen eLetter-Dienst** *(extern)*
- **Massenversand per WhatsApp** an ausgewählte Kunden
- **Automatischer WhatsApp-Versand als Cronjob**
- Auslösende Leistungsbezeichnungen konfigurierbar (welche Auftragspositionen als HU zählen)

### 16.17 Teilekataloge und technische Daten *(extern)*

**AAG-Online (DVSE TM.Next)**

- Token-basierte Anmeldung mit Zwischenspeicherung und automatischer Erneuerung
- **Beleg/Fahrzeug ans Portal übertragen** (ImportVoucher) und dort direkt öffnen — aus dem Auftrag **und** aus dem Fahrzeug
- **Fahrzeug per FIN öffnen** (ohne Auftragskontext)
- **TecDoc-Ktype-Auflösung**: Ktype-Nummer über einen Import-/Export-Roundtrip ermitteln und am Fahrzeug speichern
- **Motorkennbuchstaben per VIN-Decodierung** ermitteln, gespeicherte Motoren ohne Dubletten ergänzen
- Leichtgewichtiger Motor-Sync direkt aus dem aktuellen Beleg

**HGS-Data (Hella Gutmann)**

- Login mit gecachter Session (HGS begrenzt gleichzeitige Sitzungen)
- **HSN/TSN-Suche** löst die interne Fahrzeug-ID auf, die Fahrzeugdatenseite wird im Browser geöffnet

**Gutmann mega macs**

- Direktaufruf des Diagnosegeräts über die konfigurierte URL mit HSN/TSN/FIN

**Bosch ESI[tronic]**

- Aufruf bei gültiger HSN/TSN aus Auftrag und Fahrzeug

**SilverDAT**

- **VXS-Import**: Kalkulationspositionen aus SilverDAT als Auftrags- bzw. Rechnungspositionen übernehmen (Bulk-Import über einen Dialog)

### 16.18 AU / Abgasuntersuchung („Special")

- Erzeugt **AU-Prüfbelege als PDF** auf Basis der amtlichen Vorlagen: Benzin G-Kat, G-Kat OBD, Diesel, Diesel OBD
- Messwerte werden in die Vorlagen-PDFs einpositioniert
- Optionaler **Direktdruck** auf den Prüfstandsdrucker
- Aufruf über eine eigene Schaltfläche in der Faktura

> Das Special-Modul liegt in einem eigenen, nicht-öffentlichen Repository und ist im Haupt-Repo per `.gitignore` ausgeschlossen. Der Backend-Einstieg läuft über einen Symlink, der beim Setup angelegt wird.

### 16.19 ANPR — Kennzeichenerkennung *(Schalter `feature_anpr`)*

Automatische Kennzeichenerkennung an der Werkstattzufahrt.

- **Kameras** für die Erkennung anlegen, bearbeiten, löschen
- **Aktoren** (z. B. Torsteuerung, Schranke) anlegen, bearbeiten, löschen
- **Erkennungen** werden vom Python-Dienst gemeldet und erscheinen als Chips in der **Infoleiste** — mit Zuordnung zum Fahrzeug bzw. Kunden
- Erkennung als erledigt markieren
- **Erkennungshistorie** in der Konfigurationsansicht, komplett löschbar
- **Gesundheitsprotokoll** des Dienstes
- **Dienststeuerung**: Status prüfen, neu starten, **journalctl-Log** einsehen
- **Testlauf** mit einer Bild- oder Videodatei gegen das Erkennungsskript
- **Near-miss-Debug-Snapshots** auflisten, ansehen und löschen — zeigt, was knapp nicht erkannt wurde
- Umfangreicher eigener Konfigurations-Tab mit Vorgabewerten

### 16.20 Weitere LxCars-Integrationen

- **Wiki-Artikel je KBA** (HSN/TSN) — fahrzeugspezifisches Werkstattwissen
- **Werkstattauftrag aus einer Telefonaufnahme** generieren (KI)
- **Fahrzeugsuche** in der globalen Suche und in der erweiterten Suche
- Fahrzeug-Karte in der CRM-Ansicht des Kunden

### 16.21 LxCars-Konfiguration (eigener Tab)

| Bereich | Einstellungen |
|---|---|
| Allgemein | API-Key, Chat-System-Prompt, Verkaufstext-System-Prompt |
| Arbeitsanweisungen | Präfix, Startnummer |
| Dateien | Automatisch anzulegende Fahrzeug-Unterordner |
| Aufträge | Statusliste, auszublendender Status, KFZ-Ort-Optionen, Vorschautage |
| HU | Vorlaufmonate, auslösende Leistungsbezeichnungen, Brieftext, WhatsApp-Versand |
| AAG-Online | Benutzer, Passwort, zweites Passwort |
| Gutmann | mega-macs-URL |
| HGS-Data | Benutzer, Passwort |
| Termine | Standard-Abgabezeit, Standard-Fertigstellungszeit, Zeitraster |
| Etikettendrucker | Drucker für grüne Plakette, Drucker für Reifenetiketten |
| Zeiterfassung | Werkstattleitungs-Gruppe, Arbeitsbeginn, Arbeitsende, Pausen |
| E-Mail | Betreff, Textvorlage, vollständige Daten anhängen |
| Mechaniker-Modus | aktivieren, Mitarbeitergruppe |
| Wartung | Wartungsprüfung aktivieren |

---

# Anhang

## Anhang A — Feature-Schalter

| Schalter | Wirkung |
|---|---|
| `lxcars` | Kompletter Werkstattbereich (siehe Teil B) |
| `feature_anpr` | ANPR-Kennzeichenerkennung inkl. Konfigurations-Tab (Standard: an) |
| `feature_nvr` | Videoüberwachung / NVR (Standard: an) |
| `feature_datev` | DATEV-Funktionen |
| `feature_ustva` | Umsatzsteuer-Voranmeldung |
| `lxcars_mechanic_mode` | Mechaniker-Modus im Menü und als Startansicht |
| `lxcars_hu_whatsapp_enabled` | HU-Benachrichtigungen per WhatsApp |
| `lxcars_wartung_enabled` | Prüfung der Wartungsdaten vor Auftragsabschluss |
| `telegram_enabled` | Telegram-Messaging |
| eBay, DHL, SumUp, Brevo, WhatsApp | jeweils über hinterlegte Zugangsdaten aktiv |
| `webdav`, `doc_storage` | Dokumentenablage und WebDAV-Synchronisation |
| `debug` | Debug-Modus in der Oberfläche |

## Anhang B — Konfigurations-Tabs

**Stammdaten** — Firma · Mitarbeiter · Lager · CRM
**Buchhaltung** — Standardkonten · Buchungskonfiguration · DATEV-Prüfung · SEPA/Bank
**Belege** — Nummernkreise · Belegverknüpfungen · Löschbare Belege · E-Rechnung · Inventur
**Features** — Features · LxCars *(LxCars)* · ANPR *(Schalter)* · KI und Gesundheit
**Werkzeuge** — Hinzufügen (Buchungsgruppen, Steuerzonen, Steuersätze, Bankkonten) und weitere

Über allen Tabs liegt eine **Feldsuche** — Eingabe von „iban", „zugferd", „inventur" oder „plakette" springt direkt zum passenden Feld.

## Anhang C — Hintergrunddienste & Cronjobs

| Dienst | Aufgabe |
|---|---|
| **SSE-Server** (Node.js, pm2) | Echtzeit-Benachrichtigungen an alle Browser |
| **Whisper-Server** (Python) | Lokale Spracherkennung ohne Cloud |
| **go2rtc** | Kamera-Streaming |
| **camera-monitor** | Auswertung der Kamerastreams, Ereigniserzeugung |
| **plate-recognition** | ANPR-Erkennungsdienst *(LxCars)* |
| `whatsapp-reminders.php` | Terminerinnerungen und HU-Benachrichtigungen versenden |
| `ebay-orders.php` | eBay-Bestellungen importieren |
| `post-unbooked-invoices.php` | Noch nicht verbuchte Rechnungen nachbuchen |
| `weroni-monitor.php` | Weroni-Aufgaben im Hintergrund abarbeiten |
| **Webhooks** | `whatsapp.php`, `telegram.php`, `part-image.php` |

---

## Grundsätze hinter allem

| Prinzip | Umsetzung |
|---|---|
| UX First | Jede Entscheidung priorisiert Bedienbarkeit |
| Logik in der Datenbank | SQL statt PHP — PHP ist nur Transportschicht |
| Ein Request = eine Query | Keine Datenassemblierung im Backend |
| Single Source of Truth | Der Pinia-Store verhindert doppelte Abrufe |
| Kein Hardcoding | Alles kommt aus der Datenbank |
| Prepared Statements | Durchgängig parametrisierte Queries |
| Responsive | Desktop, Tablet, Mobil aus einer Codebasis |
| kivitendo-kompatibel | Läuft auf bestehenden kivitendo-Datenbanken; Erweiterungen liegen in eigenen Tabellen und in `defaults_oserp` |
