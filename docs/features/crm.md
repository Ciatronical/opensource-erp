---
title: CRM
summary: Kunden- und Lieferantenverwaltung, Kontakte, Kontakthistorie, Kommunikation
group: core
category: Stammdaten
order: 10
status: stable
---

# CRM — Kunden- und Lieferantenverwaltung

Das CRM-System verwaltet Kunden, Lieferanten, Kontakte und die gesamte Kommunikationshistorie an einem zentralen Ort.

## Kunden / Lieferanten

### Anlegen und Bearbeiten

Jeder Kontakt hat:
- **Stammdaten**: Name, Adresse, Telefon, E-Mail, Steuernummer, USt-IdNr.
- **USt-IdNr. bestätigen** (Knopf am Feld): qualifizierte Bestätigungsabfrage beim Bundeszentralamt für Steuern (**eVatR**, REST) — Name und Ort, optional PLZ und Straße werden gegen die Registerdaten des Mitgliedstaats geprüft und je Feld als Übereinstimmung angezeigt (A stimmt, B stimmt nicht, C nicht angefragt, D nicht mitgeteilt). Voraussetzung: eigene deutsche USt-IdNr. in der Firmenkonfiguration. Ohne sie, für deutsche Nummern oder ohne Name/Ort läuft die Prüfung über **VIES** (mit eigener EU-Nummer inklusive Konsultationsnummer). **Jede Abfrage wird als Nachweis gespeichert** (§ 18e UStG): vollständig in `ustid_check_log`, die Kurzfassung der letzten Prüfung am Kunden bzw. Lieferanten (`customer_ext`/`vendor_ext.ustid_check`) mit Datum, Dienst und Ergebnis — die Maske warnt, wenn der Nachweis zu einer anderen Nummer gehört
- **Mehrere Telefonnummern, E-Mail-Adressen und URLs**: zusätzlich zu Haupt-Telefon, Haupt-E-Mail und Homepage beliebig viele weitere Einträge, jeweils mit Bezeichnung (z.B. „Buchhaltung", „Bestellungen", „Kundenportal")
- **Bankverbindung**: IBAN, BIC für SEPA-Zahlungen
- **Lieferadressen**: Mehrere abweichende Adressen möglich
- **Ansprechpartner**: Mehrere Kontaktpersonen mit eigenen Telefonnummern/E-Mails
- **Preisregeln**: Kundenspezifische Rabatte, Preisgruppen
- **Notizen**: Freitext-Bemerkungen

### Einfacher Modus der Kundenmaske

Die Kundenmaske hat zwei Ansichten, umschaltbar oben rechts im Kopf (wie die Kompaktansicht der Faktura):

- **Einfach** — eine ruhige, einspaltige Maske in Lesebreite. Sie zeigt nur die Felder und Reiter, die in der Firmenkonfiguration freigegeben sind. Karten ohne freigegebenes Feld entfallen ganz. Trägt ein ausgeblendetes Feld bei einem bestehenden Kunden einen Wert (z.B. Rabatt oder Kreditlimit), steht das unten als Hinweis mit Sprung zu „Alle Felder“ — nichts geht unbemerkt verloren.
- **Alle Felder** — die vollständige zweispaltige Ansicht mit allen Karten und Reitern.

Der Standard kommt aus der Firmenkonfiguration; ohne Eintrag ist der einfache Modus an. Jeder Benutzer kann in der Maske umschalten, seine Wahl wird pro Benutzer gemerkt (`company_employee_config.cv_easymode`). Pflichtfelder fürs Speichern (Name, PLZ, Ort, Straße, Kundentyp) bleiben im einfachen Modus immer sichtbar.

Die Anschrift steht als Block: PLZ und Ort, direkt darunter die Straße, dann das Land. Die PLZ füllt den Ort automatisch.

### Zeitreise (Änderungsverlauf)

Der Reiter **Zeitreise** zeigt, wer wann was am Kunden oder Lieferanten geändert hat. Ein Trigger (`cv_history_log`) schreibt bei jedem Speichern die geänderten Spalten als Diff (alt → neu) mit Mitarbeiter und Zeit in `cv_history_oserp`; erfasst werden Stammdaten (`customer`/`vendor`), die Erweiterungen (`customer_ext`/`vendor_ext`), Ansprechpartner, Lieferadressen und zusätzliche Rechnungsadressen. Die kivitendo-Tabellen bleiben unverändert; die alten kivitendo-Ereignisse aus `history_erp` (gespeichert, gedruckt, gemailt) erscheinen als Vorgeschichte mit.

- Ereignisse sind nach Mitarbeiter filterbar und durchsuchbar; IDs (Zahlungsbedingung, Kundentyp, Verkäufer …) werden als Klartext angezeigt.
- **Zeitreise-Regler**: aus den Diffs wird der Stand der Stammdaten vor einer gewählten Änderung zurückgerechnet und gegen heute gestellt. Es werden keine Schnappschüsse gespeichert.
- Der Mitarbeiter kommt aus dem Store (`employee_id` im Speichern-/Löschen-Aufruf) und wird per `set_config('oserp.employee_id')` an den Trigger gereicht; ohne Angabe wird er aus der Anmeldung ermittelt.
- Der alte kivitendo-„Historie“-Knopf ist entfallen.

### Tabs im Kontakt

| Tab | Inhalt |
|-----|--------|
| Stammdaten | Adresse, Kontaktdaten, Zahlungsbedingungen |
| Ansprechpartner | Kontaktpersonen mit Rollen |
| Kommunikation | Anrufe, E-Mails, WhatsApp-Nachrichten |
| Dokumente | Angebote, Aufträge, Rechnungen |
| Dateien | Hochgeladene Dokumente, Bilder |
| Lieferadressen | Alternative Versandadressen |
| Preise | Kundenspezifische Preisregeln |

### E-Mail-Adressen und URLs

Alle E-Mail-Adressen und Web-Adressen eines Kunden sind in der Kundenkarte (Erreichbarkeit) und im Bearbeitungsformular direkt klickbar:

- **URLs** öffnen in einem neuen Browser-Tab; fehlt das Schema, wird `https://` ergänzt
- **E-Mail-Adressen** öffnen je nach Firmenkonfiguration (*CRM → E-Mail-Client → E-Mail-Links öffnen mit*):
  - **Externes E-Mail-Programm (mailto:)** — Standard; der Browser übergibt die Adresse an das eingerichtete Mailprogramm
  - **Interner E-Mail-Client** — in der Kundenkarte öffnet sich der E-Mail-Tab mit vorbelegtem Empfänger, im Bearbeitungsformular das Postfach des ERP

Die weiteren Adressen werden in der globalen Suche, in der Kundensuche des Postfachs und beim Abruf der Kunden-E-Mails (E-Mail-Tab) berücksichtigt. Gespeichert werden sie in `customer_ext.emails` / `customer_ext.urls` bzw. `vendor_ext.*` (JSON-Arrays mit `label` und `email` bzw. `url`); die kivitendo-Tabellen bleiben unverändert.

## Anrufhistorie

Eingehende und ausgehende Anrufe werden automatisch protokolliert (bei konfigurierter Telefonanlage). Die Anrufhistorie zeigt:
- Datum und Uhrzeit
- Richtung (eingehend/ausgehend)
- Anrufer-Name (automatisch aus Kontakten aufgelöst)
- Dauer

Neue Anrufe erscheinen als grüne (eingehend) oder blaue (ausgehend) Chips in der **Infoleiste**.

## E-Mail-Integration

E-Mails werden per IMAP abgerufen und können Kunden zugeordnet werden. Siehe [E-Mail-Dokumentation](email.md).

## WhatsApp-Integration

Nachrichten über die WhatsApp Business API senden und empfangen. Siehe [WhatsApp-Dokumentation](whatsapp.md).

## Suche

Die globale Suche durchsucht gleichzeitig:
- Kundennamen und -nummern
- Lieferantennamen
- Telefonnummern
- E-Mail-Adressen
- Ansprechpartner

## Infoleiste

Die Infoleiste am oberen Bildschirmrand zeigt aktuelle Ereignisse als farbige Chips:

| Farbe | Typ | Bedeutung |
|-------|-----|----------|
| Rot | Ersatzteile | Offene Ersatzteil-Anforderungen (LxCars) |
| Blau | ANPR | Erkannte Fahrzeuge an der Zufahrt (LxCars) |
| Grün | Anruf (eingehend) | Neuer eingehender Anruf |
| Blau | Anruf (ausgehend) | Neuer ausgehender Anruf |
| Lila | E-Mail | Neue E-Mail |
| Grün | WhatsApp | Neue WhatsApp-Nachricht |

Chips können angeklickt (zum Kontakt navigieren) oder geschlossen (dismiss) werden.

## Konfiguration

Unter **Einstellungen > CRM**:
- **Einfacher Modus → Kunde anlegen / bearbeiten**: Schalter „Einfacher Modus ist Standard“ sowie die im einfachen Modus sichtbaren Felder je Karte und Reiter. Schnellauswahl „Empfehlung“, „Nur Pflichtfelder“, „Alle Felder“; je Karte lassen sich alle Felder auf einmal setzen. Pflichtfelder sind gesperrt und immer sichtbar. Die Auswahl gilt für Kunden und Lieferanten (Felder nur für eine Seite sind markiert). Gespeichert in `defaults_oserp` (`cv_easymode_default`, `cv_easymode_fields`, `cv_easymode_tabs` als JSON-Listen); der Feldkatalog liegt in `src/core/views/customer-vendor/cvFieldCatalog.js`.
- Infoleiste: Max. Anzahl Anrufe/E-Mails/WhatsApp-Nachrichten
- Telefonie-Einstellungen
- E-Mail-Konten
- WhatsApp Business API-Zugangsdaten

## Kontakthistorie

Die Karte **Kontakthistorie** im Kunden zeigt alle Berührungspunkte chronologisch in einer Liste: Anrufe (ein- und ausgehend, verpasst, mit Mitschnitt und Transkript), WhatsApp-Nachrichten, [Briefe](briefe.md) (mit Versandwegen E-Mail/Post) und E-Mails — jeweils mit Datum, Richtung, Mitarbeiter und Kurztext. Der Knopf „Brief“ in der Karte beginnt einen neuen Brief an diesen Kunden. Ein Klick öffnet den Vorgang. So ist auf einen Blick klar, wann zuletzt Kontakt bestand und worum es ging.

Im Kopf der Karte, links vom Brief-Knopf, steht der Knopf **„Kunde vor Ort“**: Kommt der Kunde persönlich vorbei, wird im Dialog festgehalten, was er gesagt hat (Wunsch, Beschwerde, Terminabsprache …). Der Zeitpunkt ist mit jetzt vorbelegt und lässt sich nachträglich ändern, Strg+Enter speichert. Der Eintrag erscheint sofort als „Vor Ort“ mit erfassendem Mitarbeiter in der Historie, hat einen eigenen Filter und kann über den Stift geändert oder gelöscht werden. Gespeichert wird kivitendo-kompatibel als Notiz am Kunden (`notes`, Modul CT) — dieselben Notizen sind damit auch in kivitendo sichtbar.

**Auftragsbestätigungen** erscheinen ebenfalls in der Historie (Filter mit Handschlag-Symbol, nur sichtbar, wenn es welche gibt): „Auftrag … bestätigt“ zum Zeitpunkt der Zusage mit Weg, Zitat bzw. Notiz und Mitarbeiter (oder „automatisch bestätigt (KI)“), eine spätere Rücknahme als eigener Eintrag mit Zeitpunkt und Mitarbeiter, die offenen Vorschläge der KI — „Zusage zu Auftrag … erkannt — bitte prüfen“ (blau) und „Absage … erkannt“ (rot), jeweils mit dem Zitat des Kunden — sowie zur Kenntnis genommene Absagen. Bei einer Zusage am Telefon spielt der Knopf ▶ die Aufnahme ab, der zweite Knopf öffnet den Auftrag, wo der Vorschlag bestätigt oder verworfen wird (siehe [Faktura → Auftragsbestätigung](faktura.md#auftragsbestätigung-ki-erkennung-schalter)).

Die Kontakthistorie aktualisiert sich **von selbst**, einen Aktualisieren-Knopf gibt es nicht: Anrufe und WhatsApp-Nachrichten, neue oder versandte Briefe und „Kunde vor Ort“-Einträge anderer Mitarbeiter erscheinen sofort auf allen offenen Arbeitsplätzen (SSE). Neue E-Mails meldet der Dienst `oserp-email-eingang`: Er hält zu jedem eingerichteten Postfach (Firmenkonto und eigene Mitarbeiterkonten) eine Verbindung auf Posteingang und Gesendet, der Mailserver meldet neue Nachrichten per IMAP IDLE sofort (Server ohne IDLE alle 15 Sekunden), und die Absender- und Empfängeradressen werden den Kunden, Lieferanten und Ansprechpersonen zugeordnet. Damit taucht auch eine Mail, die in Thunderbird oder am Handy geschrieben wurde, direkt beim Kunden auf. Einrichtung: `install/install.sh --only emaileingang` (Unit-Vorlage `install/oserp-email-eingang.service`).
