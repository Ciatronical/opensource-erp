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

## Stammdaten-Listen

Reiter **Werkzeuge → Stammdaten-Listen** in der Firmenkonfiguration: die einfachen Listen, die kivitendo unter „System“ pflegt, an einer Stelle.

| Liste | Felder |
|---|---|
| Einheiten | Bezeichnung, Art (Ware/Dienstleistung), Basiseinheit, Umrechnungsfaktor |
| Kundentypen | Bezeichnung, Rabatt in %, eigener Kundennummernkreis, Verkäufer |
| Warengruppen, Preisgruppen | Bezeichnung, ungültig |
| Preisfaktoren | Bezeichnung, Faktor („pro 100“) |
| Abteilungen, Anreden | Bezeichnung |
| Zahlungsbedingungen | Zahlungsziel, Skontofrist, Skonto %, automatische Fälligkeit, Texte für Angebote/Aufträge und Rechnungen, ungültig |
| Lieferbedingungen | Bezeichnung, Belegtext, ungültig |
| Belegsprachen | Bezeichnung, Vorlagen- und Artikelcode, Zahlen- und Datumsformat, ungültig |
| Projekttypen, Projektstatus | Bezeichnung, intern bzw. technischer Schlüssel |

- Jede Liste ist ein aufklappbarer Abschnitt mit Suche über alle Listen und Einträge; die Einstellungssuche springt direkt in die Liste
- Reihenfolge per Drag&Drop (Listen mit Sortierspalte), Bearbeiten im Dialog, Pflichtfeld- und Zahlprüfung im Frontend
- Einträge, die in Belegen, Artikeln oder Kunden verwendet werden, tragen ein Verknüpfungssymbol: nicht löschbar, bei Einheiten ist die Bezeichnung gesperrt — stattdessen auf ungültig setzen
- Technik: `backend/api/oserp_config/master_lists.php` mit Whitelist der Tabellen und Spalten (`getMasterLists`, `saveMasterListItem`, `deleteMasterListItem`, `reorderMasterList`), je Vorgang eine Abfrage; Darstellung in `src/core/views/config/tabs/masterListsConfig.js`
- Noch nicht abgedeckt aus kivitendos System-Menü: benutzerdefinierte Variablen, CSV-Import, Dialogbuchungen korrigieren, Hintergrundjobs

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
- **Tickets**: Kanban- und Listenansicht, Kommentare, Labels; Screenshots per Strg+V oder Dateien per Drag&Drop an Ticket oder Kommentar hängen (abgelegt unter `data/<mandant>/tickets/<id>/`); Filter nach Status (mehrfach, auch über die Status-Chips), Mehrfachauswahl zum Löschen in der Listenansicht — z. B. alle erledigten auf einmal
- `php tools/check-api-health.php` (Docblocks und Testdaten), `npm run check:routes` (Routen in allen Sprachen)

## Offsite-Backup (Borg)

Der Installer richtet auf Wunsch das nächtliche, verschlüsselte und deduplizierte Backup des ganzen Servers auf eine Hetzner Storage Box ein — dieselben `storage_backup`-Skripte wie auf allen OSERP-Rechnern: Dumps aller PostgreSQL-Datenbanken, `borg create` von `/` mit Ausschlüssen, gestaffelte Aufbewahrung (7 täglich, 4 wöchentlich, 6 monatlich), Auto-Resume-Wächter für die Erstsicherung nach DSL-Zwangstrennung, Fehlermeldung per E-Mail. Die Zugangsdaten werden bei der Einrichtung abgefragt und liegen nur auf dem Server in `backup.env`; `borg-uebersicht` zeigt den Backup-Stand aller Installationen in einer Tabelle. Optional baut der Lauf jede Nacht den Test-Mandanten aus der Produktivdatenbank neu (Bot-Zugänge werden entschärft). Einrichtung: `install/README.md`, Abschnitt „Offsite-Backup“.

## Dokumentation im System

Dieses Handbuch liegt als Markdown in `docs/features/` und ist im System unter **Dokumentation** lesbar — gegliedert nach Kernsystem und Erweiterungen. Jede Datei trägt Metadaten (Titel, Kurzbeschreibung, Gruppe, Erweiterung, Rubrik), aus denen die Navigation entsteht. Der Knopf **Auf der Website veröffentlichen** überträgt den Feature-Katalog an opensourceerp.dev (siehe `docs/website-schnittstelle.md`).

## Über OSERP

**Firma → Über** öffnet eine eigene Seite: wie OSERP entstanden ist, welche Grundsätze das Projekt leiten, Dank an die Wegbegleiter, das Entwicklerteam und die Einladung zum Mitmachen mit Links zu GitHub, Website, Demo und Dokumentation. Sie ersetzt den früheren kleinen Über-Dialog. Die Seite ist öffentlich und auf der Loginseite verlinkt (angemeldet mit Menü, sonst mit Rückweg zur Anmeldung). Ihr Inhalt geht mit dem Feature-Katalog an die Website (siehe `docs/website-schnittstelle.md`). OSERP selbst ist für Suchmaschinen gesperrt (`noindex`), damit nur die Website gefunden wird.

## Weitere Grundlagen

- **Mandanten**: mehrere Firmen auf einer Installation, Wechsel ohne Neuanmeldung
- **Benutzer und Rechte**: Berechtigungsgruppen wie in kivitendo, Setup-Assistent ohne kivitendo (siehe [Benutzer und Firmen](benutzerverwaltung.md))
- **Nummernkreise** für alle Belegarten, Artikel und Anweisungen
- **21 Oberflächensprachen** inklusive übersetzter URLs
- **Echtzeit** (SSE): Benachrichtigungen, Kalender, Chat, Infoleiste
- **Demo-Modus** mit automatischem Zurücksetzen
