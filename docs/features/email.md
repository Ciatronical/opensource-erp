---
title: E-Mail
summary: IMAP/SMTP-Integration mit automatischer Kundenzuordnung
group: core
category: Kommunikation
order: 50
status: stable
---

# E-Mail — Integration

OpensourceERP kann E-Mails über IMAP abrufen und über SMTP versenden. E-Mails werden automatisch Kunden zugeordnet.

## Einrichtung

Unter **Einstellungen > CRM** das E-Mail-Konto konfigurieren:
- **IMAP-Server**: z.B. `imap.gmail.com:993`
- **SMTP-Server**: z.B. `smtp.gmail.com:587`
- **Benutzername / Passwort**: E-Mail-Zugangsdaten
- **Ordner**: Welche IMAP-Ordner abgerufen werden

### E-Mail-Links aus dem Kundenstamm

Unter *CRM → E-Mail-Client → E-Mail-Links öffnen mit* wird festgelegt, was ein Klick auf eine E-Mail-Adresse im Kundenstamm tut: **Externes E-Mail-Programm (mailto:)** (Standard) übergibt die Adresse an das Mailprogramm des Arbeitsplatzes, **Interner E-Mail-Client** öffnet den Compose-Dialog des ERP-Postfachs mit vorbelegtem Empfänger. Letzteres setzt eingerichtete SMTP-Zugangsdaten voraus.

## Funktionen

- **Abruf**: Neue E-Mails werden regelmäßig abgerufen (Polling alle 60 Sekunden)
- **Kundenzuordnung**: E-Mails werden automatisch dem passenden Kunden zugeordnet (anhand der E-Mail-Adresse)
- **Infoleiste**: Neue E-Mails erscheinen als lila Chips in der Infoleiste
- **Kundenansicht**: Alle E-Mails eines Kunden im Tab "E-Mails" einsehbar
- **Journaling**: Gesendete E-Mails werden protokolliert
