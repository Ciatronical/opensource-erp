# Mitwirken an OSERP

Beiträge sind willkommen: Fehlerberichte, Verbesserungsvorschläge, Übersetzungen,
Dokumentation und Code. Diese Seite beschreibt, was dabei zu beachten ist.

## Lizenz und Contributor License Agreement

OSERP steht unter der GNU Affero General Public License v3 oder später
(`LICENSE`). Inter-Data bietet das Projekt zusätzlich unter einer kommerziellen
Lizenz an. Damit das möglich bleibt, nehmen wir Beiträge nur von Personen und
Organisationen an, die das [Contributor License Agreement](CLA.md) abgeschlossen
haben.

Der Ablauf ist einfach: Beim ersten Pull Request bittet der CLA-Assistent per
Kommentar um die Unterschrift. Sie antworten mit dem dort genannten Satz, die
Unterschrift wird in `.github/cla-signatures.json` festgehalten und gilt für
alle weiteren Beiträge. Pull Requests ohne Unterschrift werden nicht gemergt.

Sie behalten alle Rechte an Ihrem Beitrag. Das CLA räumt Inter-Data nur das
Recht ein, den Beitrag auch unter anderen Lizenzen weiterzugeben.

## Material Dritter

Code, Bibliotheken, Schriftarten oder Grafiken, die nicht von Ihnen stammen,
kennzeichnen Sie im Pull Request mit Herkunft und Lizenz. Zulässig sind nur
Lizenzen, die mit der AGPL-3.0 verträglich sind und einer späteren
kommerziellen Lizenzierung nicht entgegenstehen, also vorrangig MIT, BSD,
Apache-2.0 und ISC. Neue Copyleft-Abhängigkeiten (GPL, AGPL, LGPL als
statische Einbindung) sprechen Sie vorher in einem Issue ab.

Bei jeder neuen oder geänderten Abhängigkeit:

1. `docs/drittkomponenten.md` ergänzen (Paket, Version, Lizenz, Zweck).
2. `npm run licenses` ausführen und die aktualisierte `THIRD_PARTY_LICENSES.md`
   mit einchecken. Voraussetzung sind installierte Abhängigkeiten im
   Hauptprojekt, in `portal-ui`, `backend/sse` und `backend/` (Composer).

## Entwicklungsumgebung

Einrichtung, Start und Werkzeuge beschreibt `dev/DEVELOPER.md`. Die Entwicklung
läuft lokal mit PostgreSQL, PHP und Node; Docker dient nur für Test und Demo.

## Richtlinien

Verbindlich für alle Beiträge ist `dev/programmierstil-richtlinien.md`, die
Kurzfassung steht in `CLAUDE.md`. Die wichtigsten Punkte:

- Logik gehört in die Datenbank (SQL), ein API-Aufruf entspricht einer Abfrage.
- Prepared Statements, keine String-Interpolation in SQL.
- Kivitendo-Originaltabellen werden nicht verändert; Erweiterungen bekommen
  eigene Tabellen unter `backend/upstall/`.
- Frontend mit vue-i18n: alle Texte in den Locale-Dateien, keine festen Strings.
- Navigation ausschließlich über Routen-Namen, geprüft mit `npm run check:routes`.
- Layout-Bausteine aus `src/core/components/layout/` und Tokens aus
  `src/core/styles/tokens.css`.
- Echte Umlaute (ä, ö, ü, ß) in Texten und Kommentaren, kein Gendern.
- Jede API-Funktion trägt einen Funktionskommentar mit `@param` und `@testdata`.

## Commits und Pull Requests

- Kleine, in sich abgeschlossene Commits mit aussagekräftiger Nachricht auf
  Deutsch.
- Vor dem Commit `tools/fix-ws.sh` ausführen (Leerzeichen am Zeilenende und
  Zeilenenden normalisieren).
- Ein Pull Request behandelt ein Thema. Beschreiben Sie, was sich ändert und
  warum, und wie es geprüft wurde.
- Prüfungen vor dem Einreichen: `npm run check:routes`, `npm run check:api`,
  `npm run build`.

## Fehler melden

Nutzen Sie die Issues auf GitHub. Hilfreich sind: Version oder Commit,
Browser, Schritte zum Nachstellen, erwartetes und tatsächliches Verhalten sowie
Auszüge aus `backend/log/`. Sicherheitsrelevante Fehler melden Sie bitte nicht
öffentlich, sondern an die in der README genannte Kontaktadresse.
