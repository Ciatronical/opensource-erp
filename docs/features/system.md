---
title: System und Werkzeuge
summary: Firmen- und Systemeinstellungen, Einstellungssuche, Update, Developer-Tools, Log-Viewer, KI-Modellwahl
group: core
category: System
order: 91
status: stable
---

# System und Werkzeuge

## Firmenkonfiguration

Alle Einstellungen einer Firma liegen in `defaults_oserp` (Schlüssel-Wert) und werden in der **Firmenkonfiguration** gepflegt — in Reitern je Thema (Allgemein, CRM-Vorgaben, Faktura, Buchhaltung, Banking, E-Mail, Shop, LxCars, KI und Gesundheit, Erweiterungen …).

- **Einstellungssuche**: ein Suchfeld über alle Reiter; der Treffer springt zum Abschnitt (findet auch E-Mail-Client, IMAP und SMTP)
- **Verzeichnis- und Dateibrowser** für Pfadeinstellungen: auf dem Server suchen statt Pfade abzutippen
- **Erweiterungen** (LxCars, Shop …) werden hier ein- und ausgeschaltet; dabei läuft das Datenbank-Update der Erweiterung
- **KI-Modellwahl** je Assistent (Fahrzeug-Chat, Weroni, Verkaufstext, Positionsvorschläge, Dokument-Chat, Spezialwerkzeug, Belegerkennung …); am Prompt kann der Benutzer das Modell für seine Sitzung umschalten
- Gesundheitsprüfung der KI-Dienste (API-Schlüssel, lokales LLM)

## Systemeinstellungen

Nur für Systemadministratoren: die `settings.ini` über die Oberfläche bearbeiten (Datenbankzugang, Sitzung, Protokollierung, Zeitzone). Ein geänderter Datenbankzugang wird vor dem Speichern ausprobiert, Werte mit Steuerzeichen werden abgewiesen, vor jedem Schreiben entsteht eine Sicherungskopie.

## Update (Upstall)

Das Datenbankschema aktualisiert sich selbst: bei Anmeldung und Firmenwechsel wird die Prüfsumme der Schema-Dateien je Firma verglichen und bei Abweichung das Update ausgeführt — nur für diese Firma. Die Update-Ansicht aktualisiert alle Firmen; `tools/oserp-upstall.php` tut dasselbe auf der Kommandozeile (auch als Notfallskript ohne Anmeldung). Vor jedem echten Lauf entsteht eine Sicherung per `pg_dump`. Siehe `backend/upstall/README.md`.

## Developer-Tools

- **API-Tester**: alle API-Funktionen mit ihren Testdaten (`@testdata`) aufrufen
- **Auto-Test** und **Test-Parser**
- **SQL-Tool**
- **Datenbank-Backup**
- **Log-Viewer**: das API-Debug-Log im Browser lesen und filtern
- `php tools/check-api-health.php` (Docblocks und Testdaten), `npm run check:routes` (Routen in allen Sprachen)

## Dokumentation im System

Dieses Handbuch liegt als Markdown in `docs/features/` und ist im System unter **Dokumentation** lesbar — gegliedert nach Kernsystem und Erweiterungen. Jede Datei trägt Metadaten (Titel, Kurzbeschreibung, Gruppe, Erweiterung, Rubrik), aus denen die Navigation entsteht. Der Knopf **Auf der Website veröffentlichen** überträgt den Feature-Katalog an opensource-erp.dev (siehe `docs/website-schnittstelle.md`).

## Weitere Grundlagen

- **Mandanten**: mehrere Firmen auf einer Installation, Wechsel ohne Neuanmeldung
- **Benutzer und Rechte**: Berechtigungsgruppen wie in kivitendo, Setup-Assistent ohne kivitendo (siehe [Benutzer und Firmen](benutzerverwaltung.md))
- **Nummernkreise** für alle Belegarten, Artikel und Anweisungen
- **21 Oberflächensprachen** inklusive übersetzter URLs
- **Echtzeit** (SSE): Benachrichtigungen, Kalender, Chat, Infoleiste
- **Demo-Modus** mit automatischem Zurücksetzen
