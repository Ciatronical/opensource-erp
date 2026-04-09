# WhatsApp — Business-Messaging

OpensourceERP kann über die WhatsApp Business API Nachrichten an Kunden senden und empfangen.

## Voraussetzungen

- **Meta Business Account** mit verifizierter WhatsApp Business API
- **Telefonnummer** die für WhatsApp Business registriert ist
- **Zugangsdaten**: Phone Number ID, Access Token, Business Account ID

Die komplette Einrichtung ist in [WhatsApp-Setup](../whatsapp-setup.md) beschrieben.

## Funktionen

### Nachrichten senden

- **Textnachrichten** an beliebige Telefonnummern
- **Vorlagen** (Templates): Von Meta genehmigte Nachrichtenvorlagen für den Erstkontakt
- **Medien**: Bilder, Dokumente, Videos versenden
- **Standort**: GPS-Position teilen

### Nachrichten empfangen

Eingehende Nachrichten werden automatisch per Webhook empfangen und erscheinen:
- Im **Kunden-Tab "Nachrichten"** (wenn der Absender einem Kunden zugeordnet ist) — gemeinsam mit Telegram-Nachrichten, jeweils mit Kanal-Icon gekennzeichnet
- Als **grüner Chip** in der Infoleiste

### Vorlagen verwalten

WhatsApp erfordert für den Erstkontakt genehmigte Vorlagen (Templates). Diese können im System:
- Erstellt und an Meta zur Genehmigung eingereicht werden
- Nach Genehmigung für den Versand verwendet werden
- Platzhalter enthalten (z.B. Kundenname, Termin)

## Konfiguration

Unter **Einstellungen > CRM**:
- WhatsApp Business API Token
- Phone Number ID
- Business Account ID
- Webhook-Verifizierungstoken

## LxCars-Integration

Wenn LxCars aktiv ist, können HU-Erinnerungen auch per WhatsApp statt per Brief versendet werden (siehe [LxCars > HU-Serienbrief](lxcars.md#hu-serienbrief)).

## Siehe auch

- [Telegram-Integration](telegram.md) — kostenlose Alternative über Telegram-Bot
