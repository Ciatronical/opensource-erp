---
title: LxCars
summary: Werkstattverwaltung: Fahrzeuge, Werkstattaufträge, Mechaniker-Modus, KI-Chat, Etiketten
group: extension
extension: lxcars
category: Werkstatt
order: 100
status: stable
---

# LxCars — Werkstattverwaltung

LxCars ist das Werkstattmodul für Kfz-Betriebe. Es verwaltet Fahrzeuge, Werkstattaufträge, Arbeitsanweisungen, Ersatzteilanforderungen, Mängelerfassung und bietet einen Mechaniker-Modus für die direkte Arbeit am Fahrzeug.

## Erweiterung aktivieren

Unter **Einstellungen > Features** im Abschnitt "Erweiterungen" den Schalter bei "LxCars" einschalten und bestätigen. Dabei läuft ein Datenbank-Update; nach dem Neuladen der Seite erscheinen die LxCars-Menüpunkte.

Mehrere Erweiterungen können gleichzeitig aktiv sein. Wird LxCars wieder abgeschaltet, bleiben alle Daten erhalten — beim erneuten Einschalten ist der alte Stand wieder da.

## Fahrzeugverwaltung

### Fahrzeug anlegen

Ein Fahrzeug kann auf mehreren Wegen angelegt werden:
- **Manuell**: Fahrzeug-Formular öffnen, Kennzeichen und Besitzer eingeben
- **Fahrzeugschein scannen**: Foto des Fahrzeugscheins hochladen — die Felder werden automatisch ausgelesen
- **Aus KBA-Datenbank**: HSN/TSN eingeben — Hersteller, Modell, Leistung etc. werden automatisch befüllt

### Wichtige Fahrzeugfelder

| Feld | Beschreibung |
|------|-------------|
| Kennzeichen | Pflichtfeld, eindeutig (z.B. "B-AB 1234") |
| Besitzer | Verknüpfung zum Kunden |
| HSN / TSN | Hersteller-/Typ-Schlüssel-Nummer (für KBA-Abfrage) |
| FIN | Fahrzeug-Identifizierungsnummer (VIN) |
| Erstzulassung | Datum der Erstzulassung |
| HU-Datum | Nächste Hauptuntersuchung |
| Kraftstoff | Benzin, Diesel, Elektro etc. |
| Motorkennbuchstabe | z.B. "CJSA" |
| Bereifung | Sommer-/Winterreifen-Größen |

### KBA-Datenbank

Die KBA-Datenbank enthält Fahrzeugstammdaten (Hersteller, Modell, technische Daten). Bei Eingabe von HSN und TSN werden alle bekannten Felder automatisch befüllt. Für Fahrzeuge die nicht in der KBA-Datenbank sind, können eigene Stammdaten hinterlegt werden.

## Werkstattaufträge

Werkstattaufträge sind normale Aufträge (Faktura) mit LxCars-Erweiterungen:

- **Fahrzeug verknüpfen**: Im Auftrag ein Fahrzeug aus der Datenbank zuordnen
- **Kilometerstand**: Wird bei Auftragsanlage erfasst
- **Auftragsstatus**: Konfigurierbare Status (Standard: Angenommen, In Arbeit, Warte auf Teile, Fertig, Abgeholt)
- **Arbeitsanweisungen**: Einzelne Arbeitsschritte die dem Auftrag zugeordnet werden

### Arbeitsanweisungen

Jede Anweisung hat:
- Beschreibung (Freitext, z.B. "Ölwechsel durchführen")
- Anweisungsnummer (automatisch, konfigurierbarer Präfix + Zähler; Nummern, die es schon als Artikel gibt, werden übersprungen — Anweisungs- und Artikelnummern fallen nie zusammen)
- Geplante Minuten — beim Hinzufügen oder Ersetzen automatisch vorbelegt mit der **zuletzt eingetragenen geplanten Zeit** derselben Anweisung: wer bei „Batterie prüfen" 0:20 einträgt, bekommt beim nächsten „Batterie prüfen" wieder 0:20, im selben oder im nächsten Auftrag
- Zugewiesener Mitarbeiter
- **Schnellausfüllen** (Blitz-Symbol je Zeile): übernimmt die geplante Zeit als benötigte Zeit und trägt den angemeldeten Mitarbeiter als „Erledigt von" ein — ein Klick, ein Speichern; das Abhaken bleibt beim Benutzer
- Status: offen / erledigt
- Timer: Echtzeit-Zeitmessung der tatsächlichen Arbeitszeit

Die Vorschlagsliste beim Eintippen ist klug sortiert wie die Artikelsuche in der Faktura: Anweisungsnummer exakt, Beschreibung beginnt mit dem Begriff, Nummer beginnt mit, Wortanfang, enthalten — darin nach Verwendungshäufigkeit (Gewicht aus **Firmenkonfiguration → Grunddaten → Artikelsuche in Belegen**). Diktierte Sätze finden den Eintrag weiterhin über Einzelwörter.

## Mechaniker-Modus

Der Mechaniker-Modus ist eine vereinfachte Ansicht für Werkstattmitarbeiter.

### Aktivierung

Unter **Einstellungen > LxCars > Mechaniker-Modus**:
- "Mechaniker-Modus aktiviert" ankreuzen
- Mitarbeiter-Gruppe wählen (welche Benutzergruppe den Modus sieht)

### Funktionen

- **Meine Aufträge**: Zeigt nur Aufträge an denen der Mechaniker zugewiesen ist
- **Anweisungen abhaken**: Erledigte Arbeiten als "fertig" markieren
- **Timer**: Start/Stopp-Zeitmessung pro Anweisung
- **Ersatzteil anfordern**: Teile direkt aus dem Auftrag beim Lager/Einkauf anfordern (mit Foto-Upload)
- **Mängel erfassen**: TÜV-Mängel mit Code und Klasse dokumentieren
- **AAG-Online / HGS-Data**: Fahrzeug direkt aus dem Auftrag (Kopfzeile) und aus der Fahrzeugansicht an die Portale übergeben
- **Reifenetiketten drucken**: Sommer-/Winterreifen-Etiketten auch aus der schreibgeschützten Fahrzeugansicht

### Ersatzteil-Anforderungen

Ein Mechaniker kann Teile anfordern:
1. Beschreibung eingeben oder aus Artikelstamm wählen
2. Optional: Foto aufnehmen
3. Optional: Lieferant wählen
4. Status-Workflow: **Angefordert** → **Bestellt** (durch Büro) → **Eingetroffen**

Offene Anforderungen erscheinen als rote Chips in der **Infoleiste** — so sieht das Büro sofort wenn ein Mechaniker Teile braucht.

## KI-Funktionen

### KI-Chat pro Fahrzeug

Jedes Fahrzeug hat einen eigenen KI-Chat. Der Assistent kennt:
- Alle Fahrzeugdaten (Hersteller, Modell, Motor, Baujahr)
- Auftragshistorie (was wurde schon gemacht)
- Technische Spezifikationen aus der KBA-Datenbank

Typische Fragen: "Was könnte bei diesem Fehlerbild die Ursache sein?", "Welches Öl braucht dieser Motor?", "Wann war der letzte Zahnriemenwechsel?"

Der System-Prompt ist konfigurierbar unter **Einstellungen > LxCars > KI-Chat System-Prompt**.

### KI-Positionsvorschläge

Beim Erstellen eines Auftrags kann die KI basierend auf den Arbeitsanweisungen passende Artikelpositionen vorschlagen. Die Vorschläge basieren auf:
- Den Arbeitsanweisungen des aktuellen Auftrags
- Der Auftrags-/Rechnungshistorie des Kunden (gewichtet nach Alter)
- Den Fahrzeugdaten

### Gutachten-Import (PDF/Foto)

Fremde Schadengutachten und Kostenvoranschläge ohne Schnittstelle – PDF oder Foto eines beliebigen Sachverständigen, einer Versicherung, aus DAT/Audatex oder einer anderen Werkstatt – liest die KI aus und füllt damit den Auftrag. Nichts muss mehr abgetippt werden.

- Schaltfläche **Gutachten (PDF)** in der Aktionsleiste des Auftrags (nur bei aktivem LxCars); Datei wählen, Claude liest alle Seiten, dann erscheint die Vorschau
- **Fahrzeug**: Kennzeichen, FIN, Hersteller/Modell, Erstzulassung, Farbe. Hat der Auftrag noch kein Fahrzeug, wird es angelegt und verknüpft; ein verknüpftes Fahrzeug wird nur um leere Felder ergänzt, nie überschrieben. Gehört das Kennzeichen einem anderen Kunden, fragt das System wie beim Schnellanlegen nach
- **Auftrag**: km-Stand, Gutachten-Nr., Datum, Sachverständiger, Versicherung, Schadennummer und Schadenbeschreibung in die internen Notizen; die Schadennummer zusätzlich als Kundenauftragsnummer (falls leer)
- **Positionen** wie beim SilverDAT-Import: Ersatzteile (Teilenummer nur, wenn gedruckt), Arbeitslohn in AW/Std mit Verrechnungssatz, Lackierlohn und -material, Nebenkosten (Kleinteile, Entsorgung, Achsvermessung …). Wertminderung, Nutzungsausfall, Gutachterkosten, Restwert und Summenzeilen werden bewusst nicht übernommen
- Jede Position ist in der Vorschau abwählbar; die Summe der gewählten Positionen wird mit den Reparaturkosten laut Gutachten abgeglichen, Abweichungen über 1 % werden markiert
- Die Datei wird im Fahrzeugordner unter `gutachten/` abgelegt (ohne Fahrzeug beim Kunden)
- Modell je Mandant unter **Firmeneinstellungen → KI** (Assistent „Gutachten-Import", Vorgabe Claude Opus 5); im Dialog lässt es sich für die Sitzung umschalten und das Dokument erneut auslesen

### Weroni Rechtsprüfung (Gutachten)

Auf Knopfdruck prüft Weroni als digitale Rechtsassistentin der Werkstatt das importierte Gutachten komplett – auch die Teile, die nicht als Positionen in den Auftrag wandern – trifft eine Entscheidung und formuliert die Kommunikation.

- **Prüfpunkte**: Reparaturkosten + Wertminderung gegen Wiederbeschaffungswert, 130-%-Regel, Wiederbeschaffungsaufwand (WBW − Restwert), Abrechnungsart und MwSt./Vorsteuerabzug, Stunden- und Lacksätze, Wertminderung, Nutzungsausfall (Tage, Gruppe, Tagessatz), Sachverständigenkosten, Unkostenpauschale, Restwertangebote, Haftpflicht/Kasko und Quote, Risiken für die Werkstatt (Abtretung, Werkstattrisiko, Kürzungen)
- **Entscheidung mit Ampel**: Reparatur freigeben (grün), 130-%-Fall mit Auflagen (gelb), wirtschaftlicher Totalschaden oder Rückfrage (rot) – begründet mit Zahlen; wird in die internen Notizen des Auftrags geschrieben
- **Ansprüche des Kunden** gegenüber der gegnerischen Haftpflicht als Aufstellung mit Beträgen und Rechtsgrundlage; Empfehlung für einen Anwalt, wenn sinnvoll (Kosten trägt im Haftpflichtfall der Gegner)
- **Kommunikation**: fertiger, editierbarer Text an den Kunden (E-Mail oder WhatsApp, ohne Beleg-PDF) und ein förmliches Schreiben an die Versicherung (Reparaturfreigabe / Kostenübernahme, als E-Mail oder zum Kopieren); jeder Versand wird mit Kanal, Empfänger und Zeit protokolliert
- Button **Waage** in der Aktionsleiste: nach dem Gutachten-Import startet „Jetzt prüfen" die Prüfung (die ausgelesenen Daten werden beim Import gespeichert, auch nach dem Neuladen verfügbar); danach öffnet er die letzte Prüfung jederzeit wieder, „Erneut prüfen" wiederholt sie mit dem gewählten Modell
- Modell je Mandant unter **Firmeneinstellungen → KI** (Assistent „Weroni Rechtsprüfung (Gutachten)", Vorgabe Claude Opus 5)
- Hinweis: fachliche Einschätzung, keine Rechtsberatung im Sinne des Rechtsdienstleistungsgesetzes – Weroni sagt das im Kundentext selbst

## HU-Serienbrief

Automatisierter Versand von Erinnerungsschreiben für fällige Hauptuntersuchungen (TÜV).

### Einrichtung

Unter **Einstellungen > LxCars**:
- **HU-Vorlauf Monate**: Wie viele Monate vor Fälligkeit erinnert wird (Standard: 2)
- **HU-Brief Text**: Vorlage für das Anschreiben mit Platzhaltern (`{anrede}`, `{name}`, `{fahrzeugliste}`, `{mitarbeiter}`)
- **WhatsApp-Versand**: Optional per WhatsApp statt Brief

### Ablauf

1. Menüpunkt "HU-Serienbrief" öffnen
2. Liste zeigt alle Kunden deren Fahrzeuge bald TÜV brauchen
3. Kunden mit "Kein Serienbrief" sind ausgeschlossen
4. Briefe generieren und versenden

## Auswertungen / Reports

### Mechaniker-Auswertung

Zeigt pro Mitarbeiter:
- Erledigte Anweisungen pro Tag/Woche/Monat
- Geplante vs. tatsächliche Arbeitsminuten — zugerechnet zum Tag des Arbeitsbeginns (automatisch protokollierte Timer-Sitzungen)
- Bearbeitete Aufträge
- Arbeitszeitberechnung (mit Pausenabzug)

Die Arbeitszeiten sind konfigurierbar unter **Einstellungen > LxCars > Zeiterfassung** (Arbeitsbeginn, -ende, Pausen).

### Leistung der Mechaniker (Personal → Leistung)

Mit aktiviertem LxCars bekommt das Personal-Modul den Reiter **Leistung** (auch im Personal-Menü). Er ist nur sichtbar mit dem kivitendo-Recht **Produktivität** oder als Mitglied der konfigurierten Werkstattleitungs-Gruppe; das Backend prüft dasselbe.

Links stehen alle Mechaniker (Mitarbeitergruppe des Mechaniker-Modus) mit Kurzverlauf, oben wird der Zeitraum gewählt (Woche, Monat, Quartal, Jahr, blättern, „Heute"). Jede Kennzahl trägt den Vergleich zur Vorperiode. Ein Aufruf liefert die Daten aller Mechaniker, der Wechsel zwischen ihnen lädt nichts nach.

**Alle** — Vergleich des Teams:
- Kennzahlen: Ist-Stunden, Leistungsgrad (Vorgabe ÷ Ist), Auslastung, erledigte Anweisungen, anteiliger Arbeitslohn
- **Leistungsmatrix**: Auslastung gegen Leistungsgrad je Mechaniker, Kreisgröße = Stunden, Quadranten mit Team-Durchschnitt; Klick öffnet den Mechaniker
- Stunden Ist gegen Vorgabe je Mechaniker, Team-Stunden je Tag/Woche/Monat gestapelt
- Rangliste mit Verlauf, Veränderung zur Vorperiode, Urlaubstagen, Umsatz
- **Größte Unterschiede im Team**: Tätigkeiten, bei denen schnellster und langsamster Mechaniker am weitesten auseinanderliegen — Hinweis auf Schulung oder falsche Vorgabezeit
- Erkenntnisse in Textform (bester Leistungsgrad, Umsatzanteil, Trend, laufende Timer)

**Einzelner Mechaniker**:
- Kennzahlen mit Vorperiode und Team-Wert, Platz in der Rangliste, laufender Timer mit Sprung zum Auftrag
- Stunden und Leistungsgrad je Tag/Woche/Monat (100-%-Linie)
- **Stärken und Schwächen**: Zeit je Tätigkeit gegenüber dem Team-Durchschnitt derselben Tätigkeit
- **Aktivitätsprofil**: Erledigungen nach Wochentag und Uhrzeit
- Tätigkeitstabelle mit Anzahl, Ø Ist, Ø Vorgabe, Ø Team und Abweichung

**Plausibilität** (bei „Alle" für das ganze Team, beim Mechaniker nur für ihn) — prüft jede erfasste Zeit des Zeitraums:
- **Über Arbeitszeit**: Tage, an denen ein Mechaniker mehr als die Netto-Arbeitszeit je Tag (Arbeitsbeginn/-ende/Pausen der Zeiterfassung, z. B. 8 h) erfasst hat, mit allen Aufträgen des Tages. Plausible mehrtägige Arbeiten, die zur Vorgabe passen, zählen nicht mit, weil sie nur am Erledigungstag landen.
- **Timer vergessen?**: eine einzelne Anweisung länger als ein Arbeitstag und mindestens doppelt so lang wie ihr Vergleichswert
- **Ungewöhnlich hoch**: mindestens das Dreifache des Vergleichswerts und 30 min darüber
- **Ungewöhnlich niedrig**: höchstens ein Drittel des Vergleichswerts (ab 20 min Vergleichswert), z. B. Timer nur kurz gestartet oder Vorgabe zu hoch
- Vergleichswert ist die Vorgabezeit, ohne Vorgabe der Median derselben Tätigkeit im Team über 365 Tage
- Filter-Chips je Art, Auftragsnummern öffnen direkt den Auftrag; Mechaniker-Liste und Rangliste zeigen die Anzahl der Auffälligkeiten, die Erkenntnisse nennen die Stunden aus vermutlich vergessenen Timern

**Aufträge im Zeitraum** (einzelner Mechaniker): alle bearbeiteten Aufträge mit Kennzeichen, Kunde, Arbeiten, Ist und Vorgabe; Klick öffnet den Auftrag. Ein Klick auf einen Balken im Stunden-Diagramm filtert die Liste auf diesen Tag, diese Woche oder diesen Monat. Im Tagesraster zeigt das Diagramm die Arbeitszeit als Linie und Tage darüber rot; extreme Ausreißer werden abgeschnitten, damit die übrigen Tage lesbar bleiben.

Berechnung: Auslastung = Ist-Minuten ÷ (Arbeitstage Mo–Fr abzüglich genehmigter Urlaubstage aus der Urlaubsplanung × Nettominuten je Tag aus der Zeiterfassungs-Konfiguration). Arbeitslohn = Netto-Dienstleistungspositionen des Auftrags, anteilig nach den Ist-Minuten des Mechanikers am Auftrag.

**Zeitpunkt der Arbeit:** Ist-Zeit zählt am Tag des **Arbeitsbeginns**, nicht der Fertigstellung. Dafür hält das System jede Timer-Sitzung automatisch fest (Start, Stopp, Nettominuten, Mechaniker) — der Mechaniker bedient nur wie bisher Start und Stopp. Läuft ein Timer über Nacht oder mehrere Tage, wird die Sitzung tageweise geteilt und jeder Tag mit seinen Nettominuten bewertet. Von Hand nachgetragene Ist-Minuten zählen ab dem Eintrag rückwärts. Eine Anweisung gehört zum Zeitraum ihres ersten Arbeitsbeginns; für Altdaten ohne Protokoll wird der Beginn aus Fertigstellung minus Nettominuten (Arbeitstage rückwärts) geschätzt. Dieselbe Regel gilt für die Mechaniker-Auswertung im Benutzermenü.

## Etikettendruck

### Gelbes Etikett (Auftragszettel)

Wird am Fahrzeugschlüssel befestigt. Druckt:
- Kennzeichen
- Kundenname
- Auftragsnummer

### Reifenetikett

4 Stück pro Satz (alle Radpositionen). Druckt:
- Kennzeichen
- Reifengröße
- Lagerposition

Die Drucker werden unter **Einstellungen > LxCars > Etikettendrucker** konfiguriert (Zuordnung zu ZPL-fähigen Etikettendruckern).

## ANPR — Kennzeichenerkennung

Siehe separate Dokumentation: [ANPR](anpr.md)

## Konfiguration

Alle LxCars-Einstellungen finden sich unter **Einstellungen > LxCars**:

| Einstellung | Beschreibung |
|------------|-------------|
| API-Key (Anthropic) | Für KI-Chat und Positionsvorschläge |
| KI-Chat System-Prompt | Persönlichkeit des KI-Assistenten |
| Anweisungs-Präfix/-Nummer | Nummernkreis für Arbeitsanweisungen |
| Auftragsstatus | Kommagetrennte Liste (z.B. "Angenommen, In Arbeit, ...") |
| KFZ-Ort Optionen | Wo steht das Fahrzeug (für Auftragsansicht) |
| HU-Einstellungen | Vorlauf, Brieftext, WhatsApp |
| Termin-Defaults | Abgabe-/Fertigstellungszeit, Zeitbereich |
| Zeiterfassung | Arbeitsbeginn, -ende, Pausen |
| Mechaniker-Modus | Aktivierung, Mitarbeiter-Gruppe |
| Etikettendrucker | Zuordnung gelbes Etikett / Reifenetikett |

## Spezialwerkzeug

Spezialwerkzeuge werden unter **Lager → Spezialwerkzeug** eingelagert und per KI den passenden Fahrzeugen zugeordnet; Fahrzeugansicht und Werkstattauftrag zeigen, welches Werkzeug passt und wo es liegt. Mit der Shop-Erweiterung lassen sich die Werkzeuge verleihen und verkaufen. Eigene Seite: [Spezialwerkzeug](spezialwerkzeug.md).

## Eigener Fahrzeugscheinscanner

Statt der externen API fahrzeugschein-scanner.de kann ein Dienst auf dem eigenen Server die Fahrzeugscheine auslesen (RapidOCR, nur CPU, Port 3003 lokal). Umschalter: Firmenkonfiguration → LxCars → „Eigenen Fahrzeugscheinscanner benutzen". Installation: `install/install.sh --only fsscanner`, Beschreibung in `backend/fahrzeugschein-scanner/README.md`.

## Eingangsverzeichnis für Fahrzeugscheine (Dokumentenscanner)

Statt den Fahrzeugschein mit dem Handy zu fotografieren und hochzuladen, legt ein Dokumentenscanner (z. B. ScanSnap iX1600, eine Taste) den Schein flach gescannt direkt auf dem Server ab — die Erkennungsrate ist dadurch deutlich höher als beim geknickten Foto.

- **Verzeichnis je Mandant** mit aktiver LxCars-Erweiterung, Standard `/home/<oserp-benutzer>/Fahrzeugscheine/<db>/` (z. B. `/home/work/Fahrzeugscheine/ap_rebuild`). Das überwachte Verzeichnis steht in Firmenkonfiguration → LxCars im Feld „Überwachtes Verzeichnis“ (vorbelegt, änderbar, kopierbar); der Dienst legt es an.
- **Sofortige Verarbeitung, kein Cron**: der Dienst `oserp-fahrzeugschein-eingang` überwacht alle Eingangsverzeichnisse per inotify. Eine neue Datei (jpg, jpeg, png, pdf) wird erkannt, sobald der Scanner sie fertig geschrieben hat, durch den konfigurierten Scanner geschickt (eigener Scanner oder fahrzeugschein-scanner.de) und erscheint sofort in der **Scanliste** — auf allen offenen Arbeitsplätzen (SSE), mit Dateiname. Von dort wird wie beim Upload Kunde und Fahrzeug angelegt; das Originalbild wandert mit ins Fahrzeug.
- **PDF** vom Scanner wird vorab zur Bildseite gerastert (pdftoppm), damit auch hier ein Originalbild entsteht.
- **Ablage**: erkannte Dateien wandern nach `verarbeitet/` (30 Tage aufbewahrt), nicht erkannte nach `fehler/` mit `<datei>.fehler.txt` als Begründung. Die Firmenkonfiguration zeigt Dienststatus, wartende Dateien, letzte Verarbeitung und die jüngsten Fehler.
- **Mandanten**: der Dienst liest die Mandantenliste jede Minute neu — wird LxCars für eine Firma aktiviert, legt er ihr Verzeichnis an und überwacht es ab dann; bei Deaktivierung endet die Überwachung, das Verzeichnis bleibt.

Einrichtung: `install/install.sh --only fseingang` (Unit-Vorlage `install/oserp-fahrzeugschein-eingang.service`, Dienst läuft als OSERP-Benutzer mit `php -d ffi.enable=true`, ohne FFI Verzeichnisabfrage im Sekundentakt). Die Scanner-Software speichert direkt in das Verzeichnis (etwa über eine Freigabe des Servers).
