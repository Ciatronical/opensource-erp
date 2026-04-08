# Banking — Bankanbindung und Zahlungsverkehr

Das Banking-Modul verbindet OpensourceERP direkt mit Bankkonten über den FinTS-Standard (früherer Name: HBCI). Kontoumsätze werden automatisch abgerufen und können Rechnungen und Eingangsrechnungen zugeordnet werden.

## Voraussetzungen

- Bankkonto mit **FinTS/HBCI-Zugang** (die meisten deutschen Banken unterstützen dies)
- FinTS-URL der Bank (z.B. `https://banking-dkb.s-fints-pt-dkb.de/fints30`)
- Online-Banking Zugangsdaten (Benutzerkennung + PIN)

## Einrichtung

### Bankkonto anlegen

Unter **Einstellungen > SEPA/Bank**:

1. "Neues Bankkonto" klicken
2. Felder ausfüllen:
   - **Kontobezeichnung**: Freitext (z.B. "Geschäftskonto Sparkasse")
   - **IBAN / BIC**: Kontodaten
   - **Bankleitzahl**: Wird für FinTS benötigt
   - **FinTS-URL**: Die FinTS-Adresse der Bank
   - **FinTS-Benutzer**: Online-Banking Benutzerkennung
   - **TAN-Verfahren**: SMS-TAN, chipTAN, photoTAN etc.
3. Speichern

**Wichtig**: Die PIN wird **nicht gespeichert** — sie wird bei jeder Verbindung neu abgefragt.

## Kontoumsätze abrufen

1. Banking-Übersicht öffnen
2. Konto auswählen
3. "Umsätze abrufen" klicken
4. PIN eingeben
5. Ggf. TAN bestätigen

Umsätze der letzten 30 Tage werden abgerufen. Bereits vorhandene Buchungen werden automatisch erkannt und nicht doppelt importiert.

## Zuordnung (Matching)

### Automatisches Matching

Regeln definieren die automatisch Bankbuchungen zu Rechnungen zuordnen:

| Bedingung | Beschreibung |
|-----------|-------------|
| IBAN | Gegenkonto-IBAN |
| Kundenname | Name im Verwendungszweck |
| Verwendungszweck | Textsuche im Buchungstext |
| Betrag | Betragsbereich (von-bis) |
| Buchungsschlüssel | SEPA-Buchungscode |

### Manuelles Matching

1. Unbezuordnete Buchung anklicken
2. Rechnung suchen (nach Rechnungsnummer, Kunde, Betrag)
3. Zuordnen
4. Verbuchen (erzeugt Buchungssatz in der Finanzbuchhaltung)

### Status einer Buchung

| Status | Bedeutung |
|--------|----------|
| **Nicht zugeordnet** | Neue Buchung, noch keiner Rechnung zugewiesen |
| **Zugeordnet** | Einer Rechnung zugewiesen, noch nicht verbucht |
| **Verbucht** | In die Finanzbuchhaltung übernommen |
| **Ignoriert** | Manuell als irrelevant markiert |

## SEPA-Überweisungen

1. Überweisung erstellen (Empfänger-IBAN, Betrag, Verwendungszweck)
2. "Absenden" klicken
3. PIN eingeben
4. TAN bestätigen
5. Status wird aktualisiert: Entwurf → Warte auf TAN → Eingereicht → Ausgeführt

## Übersicht / Dashboard

Die Banking-Übersicht zeigt pro Konto:
- Aktueller Kontostand
- Anzahl nicht zugeordneter Buchungen
- Letzter Abruf-Zeitpunkt
- Monatliche Einnahmen/Ausgaben-Statistik
