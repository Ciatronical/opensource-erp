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

### Servereinstellungen automatisch ermitteln

Der Knopf **Servereinstellungen automatisch ermitteln** (unter den Zugangsdaten) füllt IMAP- und SMTP-Server, Port und Verschlüsselung zur eingetragenen E-Mail-Adresse aus — wie Thunderbird beim Anlegen eines Kontos. Abgefragt werden der Reihe nach die Autoconfig-Datei des Anbieters (`autoconfig.<domain>`, `.well-known/autoconfig`), die Mozilla-ISPDB, der MX-Record (Anbieter-Domain), SRV-Records nach RFC 6186 und zuletzt die üblichen Hostnamen (`imap.`, `mail.`, `smtp.`). Ein leerer Benutzername wird nach Vorgabe des Anbieters gesetzt. Gespeichert wird erst mit **Speichern**; das Passwort muss weiterhin von Hand eingetragen werden.

### Maximale E-Mail-Größe

Das Feld **Maximale E-Mail-Größe (MB)** (unter den SMTP-Einstellungen) begrenzt die Anhänge je E-Mail; der E-Mail-Dialog der Faktura warnt und sperrt den Versand, wenn zusätzliche Anhänge darüber liegen. Der Knopf **Grenze vom Mailserver ermitteln** verbindet sich mit dem eingetragenen SMTP-Server, liest dessen Höchstgröße aus der EHLO-Antwort (`SIZE`, RFC 1870), vergleicht sie mit der PHP-Grenze `post_max_size` (Anhänge reisen als Base64 im JSON-Request, Zuschlag 37 %) und trägt den nutzbaren Wert ein. Ist PHP die engere Grenze, weist die Meldung darauf hin; der Installer setzt `post_max_size` und `upload_max_filesize` auf 128M und `memory_limit` auf 512M (`/etc/php/<version>/fpm/conf.d/90-oserp.ini`), weil der Request beim Versand mehrfach im Speicher liegt (Rohtext, dekodiertes JSON, MIME-Nachricht). Die praktische Grenze setzt dann der Mailserver, z. B. 100 MB bei Hetzner, 25 MB bei Gmail oder Microsoft 365. Gespeichert wird erst mit **Speichern**.

### E-Mail-Links aus dem Kundenstamm

Unter *CRM → E-Mail-Client → E-Mail-Links öffnen mit* wird festgelegt, was ein Klick auf eine E-Mail-Adresse im Kundenstamm tut: **Externes E-Mail-Programm (mailto:)** (Standard) übergibt die Adresse an das Mailprogramm des Arbeitsplatzes, **Interner E-Mail-Client** öffnet den Compose-Dialog des ERP-Postfachs mit vorbelegtem Empfänger. Letzteres setzt eingerichtete SMTP-Zugangsdaten voraus.

## Funktionen

- **Abruf**: Neue E-Mails werden regelmäßig abgerufen (Polling alle 60 Sekunden)
- **Kundenzuordnung**: E-Mails werden automatisch dem passenden Kunden zugeordnet (anhand der E-Mail-Adresse)
- **Infoleiste**: Neue E-Mails erscheinen als lila Chips in der Infoleiste
- **Kundenansicht**: Alle E-Mails eines Kunden im Tab "E-Mails" einsehbar
- **Journaling**: Gesendete E-Mails werden protokolliert
