# E-Mail — Integration

OpensourceERP kann E-Mails über IMAP abrufen und über SMTP versenden. E-Mails werden automatisch Kunden zugeordnet.

## Einrichtung

Unter **Einstellungen > CRM** das E-Mail-Konto konfigurieren:
- **IMAP-Server**: z.B. `imap.gmail.com:993`
- **SMTP-Server**: z.B. `smtp.gmail.com:587`
- **Benutzername / Passwort**: E-Mail-Zugangsdaten
- **Ordner**: Welche IMAP-Ordner abgerufen werden

## Funktionen

- **Abruf**: Neue E-Mails werden regelmäßig abgerufen (Polling alle 60 Sekunden)
- **Kundenzuordnung**: E-Mails werden automatisch dem passenden Kunden zugeordnet (anhand der E-Mail-Adresse)
- **Infoleiste**: Neue E-Mails erscheinen als lila Chips in der Infoleiste
- **Kundenansicht**: Alle E-Mails eines Kunden im Tab "E-Mails" einsehbar
- **Journaling**: Gesendete E-Mails werden protokolliert
