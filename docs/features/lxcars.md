# LxCars — Werkstattverwaltung

LxCars ist das Werkstattmodul für Kfz-Betriebe. Es verwaltet Fahrzeuge, Werkstattaufträge, Arbeitsanweisungen, Ersatzteilanforderungen, Mängelerfassung und bietet einen Mechaniker-Modus für die direkte Arbeit am Fahrzeug.

## Feature aktivieren

Unter **Einstellungen > Features** den Eintrag "LxCars" auswählen und bestätigen. Nach dem Neuladen der Seite erscheinen die LxCars-Menüpunkte.

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
- Anweisungsnummer (automatisch, konfigurierbarer Präfix + Zähler)
- Geplante Minuten (Zeitschätzung)
- Zugewiesener Mitarbeiter
- Status: offen / erledigt
- Timer: Echtzeit-Zeitmessung der tatsächlichen Arbeitszeit

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
- Geplante vs. tatsächliche Arbeitsminuten
- Bearbeitete Aufträge
- Arbeitszeitberechnung (mit Pausenabzug)

Die Arbeitszeiten sind konfigurierbar unter **Einstellungen > LxCars > Zeiterfassung** (Arbeitsbeginn, -ende, Pausen).

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
