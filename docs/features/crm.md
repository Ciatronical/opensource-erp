# CRM — Kunden- und Lieferantenverwaltung

Das CRM-System verwaltet Kunden, Lieferanten, Kontakte und die gesamte Kommunikationshistorie an einem zentralen Ort.

## Kunden / Lieferanten

### Anlegen und Bearbeiten

Jeder Kontakt hat:
- **Stammdaten**: Name, Adresse, Telefon, E-Mail, Steuernummer, USt-IdNr.
- **Bankverbindung**: IBAN, BIC für SEPA-Zahlungen
- **Lieferadressen**: Mehrere abweichende Adressen möglich
- **Ansprechpartner**: Mehrere Kontaktpersonen mit eigenen Telefonnummern/E-Mails
- **Preisregeln**: Kundenspezifische Rabatte, Preisgruppen
- **Notizen**: Freitext-Bemerkungen

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
- Infoleiste: Max. Anzahl Anrufe/E-Mails/WhatsApp-Nachrichten
- Telefonie-Einstellungen
- E-Mail-Konten
- WhatsApp Business API-Zugangsdaten
