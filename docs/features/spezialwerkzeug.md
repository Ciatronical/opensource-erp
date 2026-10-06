---
title: Spezialwerkzeug
summary: Spezialwerkzeuge einlagern, per KI den passenden Fahrzeugen zuordnen, im Shop verleihen und verkaufen
group: extension
extension: lxcars
category: Werkstatt
order: 101
status: stable
since: 2026-10-03
---

# Spezialwerkzeug — einlagern, zuordnen, verleihen

Spezialwerkzeuge (Zahnriemen-Absteckwerkzeug, Injektor-Auszieher, Steuerketten-Arretierung …) werden unter **Lager → Spezialwerkzeug** eingelagert. Das Besondere: Eine KI erkennt, zu welchen Motoren und Fahrzeugen das Werkzeug passt — auch über Marken hinweg — und ordnet es den Fahrzeugen des Betriebs zu. In der Fahrzeugansicht und im Werkstattauftrag sieht der Mechaniker sofort, welches Werkzeug passt und wo es liegt.

Der Menüpunkt erscheint nur, wenn die Erweiterung **LxCars** aktiv ist, denn die Zuordnung braucht die Fahrzeuge und die KBA-Daten.

## Werkzeug einlagern

Ein Werkzeug braucht nur eine Bezeichnung und einen Lagerort. Je genauer die Bezeichnung, desto besser erkennt die KI die Motorfamilie — „Zahnriemen-Arretierwerkzeug VW 1.9/2.0 TDI PD" ist besser als „Arretierwerkzeug".

| Feld | Bedeutung |
|------|-----------|
| Bezeichnung, Werkzeugnummer, Hersteller | Stammdaten |
| Kategorie | z. B. Zahnriemen, Injektoren, Steuerkette — die KI schlägt sie vor |
| Lagerort | Freitext (Schrank 2, Schublade 3) und optional ein Lagerplatz aus dem Lagermodul |
| Status | Verfügbar, Verliehen (an wen), Defekt |
| Hinweis an die KI | Was die KI wissen soll: bekannte Motorcodes, OE-Nummern, wofür es *nicht* passt |

Nach dem Speichern bewertet die KI das Werkzeug sofort (abschaltbar per Haken).

## Zuordnung durch die KI

Die KI bekommt die Werkzeugdaten und ein Profil des eigenen Fahrzeugbestands: Hersteller mit HSN, vorkommende Motorcodes mit Hubraum, Leistung, Kraftstoff und Baujahren sowie die Modellfamilien. Daraus erzeugt sie **Zuordnungsregeln** und eine Einschätzung in Klartext:

- Ein Zahnriemenwerkzeug für VW 1.9 TDI Pumpe-Düse bekommt die Motorcodes der ganzen Familie (AXR, BKC, BXE, …) für VW, Audi, Seat und Skoda, dazu baugleiche Fremdfabrikate (Ford Galaxy I).
- Ein Injektor-Auszieher für Common Rail bekommt eine Regel „Diesel ab 2003" plus Regeln je Herstellerfamilie (Mercedes OM, BMW N47, PSA DV/DW, Renault K9K …).
- Weil bei vielen Fahrzeugen der Motorkennbuchstabe fehlt, legt die KI zusätzlich Regeln über Hersteller, Kraftstoff, Hubraum und Baujahr an, die dieselbe Motorfamilie ohne Motorcode treffen.
- Die KI nennt **Warnungen**, etwa dass Pumpe-Düse kein Common Rail ist, und kann **Ausschlussregeln** anlegen, die eine breite Regel präzise machen.

Das Modell ist je Firma einstellbar (Vorgabe: Claude Opus 5) und am Prompt umschaltbar.

### Regeln

Jede Regel beschreibt ein Fahrzeugprofil. Innerhalb einer Regel gelten alle Angaben zugleich, mehrere Regeln sind Alternativen:

| Kriterium | Wirkung |
|-----------|---------|
| Hersteller / HSN | Hersteller gilt, wenn Name oder HSN passt |
| Modelle | Wortgrenzen-Suche im Fahrzeugtext (Golf, A3, Octavia) |
| Motorcodes | Teilstring-Suche ohne Leer- und Sonderzeichen, `*` als Platzhalter (CAY*) |
| Kraftstoff | Gruppe aus dem KBA-Schlüssel (Hybride zählen zum Verbrennungsmotor) |
| Fahrzeugart | Pkw, Lkw/Transporter, Motorrad, Anhänger |
| Hubraum, Leistung, Erstzulassung | Bereiche von–bis |
| Einschluss / Ausschluss | Ausschlussregeln ziehen Treffer wieder ab |

Der Mensch behält das letzte Wort: Regeln lassen sich ein- und ausschalten, bearbeiten oder löschen. Der Regel-Editor zeigt **live**, wie viele und welche Fahrzeuge die Regel trifft. Einzelne Fahrzeuge können fest zugeordnet oder ausgeschlossen werden; eine neue KI-Bewertung ersetzt nur die Regeln der KI, von Hand bearbeitete Regeln und feste Zuordnungen bleiben.

### Technik

Die Prüfung läuft vollständig in der Datenbank: Kriterien werden zu Arrays und regulären Ausdrücken kompiliert und gegen ein normalisiertes Fahrzeugprofil geprüft. Die Treffer liegen in einem Cache, der bei jeder Regel- oder Zuordnungsänderung in derselben Abfrage aufgefrischt wird; Fahrzeugänderungen frischt ein Trigger je Fahrzeug auf. Nach einem Datenimport bietet die Übersicht „Neu berechnen" an.

## In der Werkstatt

- **Fahrzeugansicht** und **Werkstattauftrag (Mechaniker-Modus)** zeigen die Karte „Spezialwerkzeug": passende Werkzeuge mit Lagerort, Status und dem Grund der Zuordnung. Ein Klick öffnet das Werkzeug.
- Die Übersicht zeigt je Werkzeug die Zahl der passenden Fahrzeuge; Werkzeuge ohne Zuordnung fallen auf und lassen sich mit einem Klick bewerten.

## Verleih und Verkauf im Shop

Mit aktiver **Shop-Erweiterung** lässt sich jedes Werkzeug im HugoShop anbieten — zum Verleih, zum Kauf oder beides:

- **Preise**: Einkaufspreis eintragen; Verkaufspreis (Vorgabe: Einkaufspreis) und Miete (Vorgabe: ein Drittel des Einkaufspreises) sind änderbar, dazu die Mietdauer in Tagen.
- Je Angebot entsteht ein Artikel (Verkauf als Ware, Miete als Dienstleistung) mit Produktseite; die Fahrzeugliste aus den Regeln steht als technische Daten auf der Seite und wird nach jeder Regeländerung nachgeführt.
- **Werkzeugsuche im Shop**: Der Besucher gibt HSN/TSN aus dem Fahrzeugschein oder seine Fahrgestellnummer ein und bekommt die passenden Werkzeuge mit „Jetzt mieten" und „In den Warenkorb". Die Fahrgestellnummer wird gegen die Fahrzeuge der Werkstatt aufgelöst; für fremde Fahrzeuge bittet der Shop um HSN/TSN.
- Verliehene oder defekte Werkzeuge zeigt der Shop als „zurzeit verliehen" bzw. nicht verfügbar.

Offene Punkte der Mietabwicklung (Mietzeitraum, Kaution, Rückgabe, Versandart) stehen in `dev/spezialwerkzeug-shop-todo.md`.

## Konfiguration

| Schlüssel (`defaults_oserp`) | Bedeutung |
|------|-----------|
| `special_tools_ai_model` | KI-Modell der Zuordnung (Firmenkonfiguration → KI) |
| `special_tools_buchungsgruppen_id` | Buchungsgruppe der Shop-Artikel; ohne Angabe die am häufigsten verwendete |
