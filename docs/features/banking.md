---
title: Banking
summary: Bankanbindung per FinTS, Kontoumsätze, SEPA-Überweisungen, Zuordnung, Sammelbuchung, Belege je Umsatz, Kartenabrechnungen
group: core
category: Finanzen
order: 30
status: stable
---

# Banking — Bankanbindung und Zahlungsverkehr

Das Banking-Modul verbindet OpensourceERP direkt mit Bankkonten über den FinTS-Standard (früherer Name: HBCI). Kontoumsätze werden automatisch abgerufen und können Rechnungen und Eingangsrechnungen zugeordnet werden.

## Voraussetzungen

- Bankkonto mit **FinTS/HBCI-Zugang** (die meisten deutschen Banken unterstützen dies)
- FinTS-URL der Bank (z.B. `https://banking-dkb.s-fints-pt-dkb.de/fints30`)
- Online-Banking Zugangsdaten (Benutzerkennung + PIN)
- **Eigene FinTS-Produktregistrierung** (siehe nächster Abschnitt)

## FinTS-Produktregistrierung (pro Betreiber Pflicht)

Die FinTS-Spezifikation schreibt vor, dass jede Banking-Software eine 25-stellige Produkt-Registrierungs-ID bei der Deutschen Kreditwirtschaft führen muss. Diese ID wird bei jedem FinTS-Dialog im Segment `HKVVB` mitgeschickt und identifiziert die Software gegenüber den Banksystemen.

**Wichtig:** Die Registrierung ist **pro Betreiber** vorzunehmen — jeder Kunde, der OpensourceERP mit FinTS produktiv nutzen möchte, muss eine eigene ID beantragen. Eine zentrale, mit der Software ausgelieferte ID ist nach den Bedingungen der Deutschen Kreditwirtschaft nicht zulässig.

### Antrag stellen

1. Registrierungsformular ausfüllen:
   [docs/features/FinTS-Produktregistrierung_V1.0.4.pdf](FinTS-Produktregistrierung_V1.0.4.pdf)
2. Einreichen per Mail an `registrierung@hbci-zka.de`
3. Informationen und aktuelle Formulare:
   <https://www.fints.org/de/hersteller/produktregistrierung>

Die Zuteilung erfolgt per E-Mail. Nach Erhalt dauert es laut SIZ GmbH in der Regel mehrere Werktage, bis die ID in den produktiven Banksystemen aktiv ist.

### ID in OpensourceERP hinterlegen

Die zugeteilte 25-stellige ID wird in der **Firmenkonfiguration** eingetragen:

**Einstellungen → Firmenkonfiguration → SEPA/Bank → FinTS-Produktregistrierung**

Die ID wird in der Datenbank unter `defaults_oserp.fints_product_id` abgelegt und gilt für die gesamte Installation. Ohne eingetragene ID weist der FinTS-Abruf mit dem Fehler `FINTS_NOT_CONFIGURED` ab.

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

## Kontostand und Zeitraum

Die Kachel **Kontostand** zeigt den aktuellen Stand (Anfangssaldo plus alle Umsätze). Ist in der Umsatzliste ein Zeitraum gewählt, der in der Vergangenheit endet (z. B. Monat Juli), zeigt sie den **Kontostand per Zeitraumende** mit dem Datum des letzten Umsatzes — so lässt sich jeder Monat gegen den Kontoauszug prüfen.

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

Die Zuordnung prüft die Zahlungsrichtung sofort: Ein Geldeingang lässt sich nur einer Ausgangsrechnung zuordnen, ein Geldausgang nur einer Eingangsrechnung (Gutschriften entsprechend umgekehrt). Beim Verbuchen gelten drei weitere Sperren: keine Zahlung vor dem Rechnungsdatum, nie mehr als der offene Betrag, keine Doppelbuchung derselben Zahlung.

### Lieferant oder Kunde erkennen — auch ohne offenen Beleg

Zu den meisten Geldausgängen gibt es keine vorerfasste Eingangsrechnung, und nur wenige Lieferanten tragen eine IBAN im Stammsatz. Der Dialog „Zahlung buchen" erkennt den Zahlungsempfänger deshalb unabhängig von den Belegen und zeigt ihn als Chip unter dem Umsatz an, mit Angabe der Quelle:

| Quelle | Bedeutung |
|--------|-----------|
| IBAN im Stammsatz | Die IBAN des Umsatzes steht am Kunden/Lieferanten |
| Zuordnungsregel | Eine aktive Regel nennt diesen Kontakt (IBAN oder Name) |
| frühere Buchungen dieser IBAN | Umsätze derselben IBAN wurden schon auf Belege dieses Kontakts gebucht |
| frühere Buchungen dieses Namens | wie oben, über den Gegennamen (Kartenzahlungen ohne IBAN) |
| Namensübereinstimmung | Der Gegenname passt zu genau einem Kontakt |

Bei Sammelauszahlern (SumUp, PayPal) wird die Historie nur gewertet, wenn ein Kontakt mindestens die Hälfte der bisherigen Buchungen trägt – sonst käme ein zufälliger Kunde heraus. In den Stammsatz wird nichts zurückgeschrieben: eine falsch gelernte IBAN würde spätere Überweisungen fehlleiten. Das System lernt aus den Buchungen selbst, ein Storno vergisst automatisch mit.

Der erkannte Lieferant ist im Dialog **Neue Eingangsrechnung** vorbelegt (Knopf „Eingangsrechnung für … anlegen"), zusammen mit dem zuletzt für ihn verwendeten Aufwandskonto und Steuersatz. Das gilt auch, wenn der Dialog direkt aus der Umsatzliste geöffnet wird.

### Belegnummern

Jede Bankbuchung erhält eine fortlaufende Belegnummer je Geldkonto und Jahr (`Beleg N` im Buchungstext, Nummer im Belegfeld). Die nächste Nummer wird beim Buchen aus dem Hauptbuch abgeleitet, in derselben Transaktion und gegen gleichzeitige Buchungen gesperrt. Rechnungsnummern, die als Zahlungsreferenz der eigenen Rechnung stehen, zählen nicht mit, ebenso wenig Werte, die größer sind als die Zahl der Buchungen des Jahres sein können (in kivitendo-Dialogbuchungen oder ins Belegfeld getippte Rechnungsnummern) — die Folge springt so nicht in den Rechnungsnummernkreis. Dieselbe Vergabe gilt für die Kasse (siehe Buchhaltung → Kasse).

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

## Belege zu einem Bankumsatz

Zu jedem Bankumsatz lassen sich **beliebig viele Belege** ablegen — Gebührenabrechnung der Bank, Kontoauszugsseite, Vertrag, Mahnung, Lieferschein — unabhängig davon, ob der Umsatz schon gebucht, nur zugeordnet oder ignoriert ist. Die Büroklammer in der Umsatzliste öffnet die Belege zum Umsatz; derselbe Bereich steht im Dialog „Zahlung buchen". Dateien werden per Drag-and-drop oder über „Hinzufügen" hochgeladen, mehrere auf einmal (PDF, JPG, PNG, WEBP, TIFF, bis 20 MB je Datei).

Angezeigt wird alles, was zum Umsatz gehört: direkt angehängte Belege sowie die Belege der zugeordneten Eingangs- und Ausgangsrechnungen (mit Rechnungsnummer gekennzeichnet). Letztere hängen an der Rechnung und werden hier nur mit angezeigt; direkt angehängte Belege lassen sich vom Umsatz **lösen**, bleiben dabei aber in der Belegablage — Belege werden nie gelöscht (GoBD). Jede Ablage, Verknüpfung und Ansicht wird im Belegprotokoll festgehalten.

Die Büroklammer in der Liste zeigt den Belegstand: grün = Beleg vorhanden, orange = Eingangsrechnung ohne Beleg (Lücke für die Betriebsprüfung), grau = nichts hinterlegt.

Beim Anlegen einer Eingangsrechnung aus dem Bankumsatz („Als Eingangsrechnung buchen") können ebenfalls mehrere Dateien gewählt werden; sie werden alle an die Eingangsrechnung gehängt und im DATEV-Export mitgeliefert.

Technik: Tabelle `bank_transaction_documents` (Umsatz ↔ `accounting_documents`), API `getBankTransactionDocuments`, `uploadBankTransactionDocuments`, `unlinkBankTransactionDocument`, `getBankDocumentContent` in `backend/api/banking/bank_documents.php`.

## Sammelbuchung — ein Umsatz, mehrere Belege

Ein Bankumsatz kann gegen **mehrere Belege** gebucht werden: eine Sammelüberweisung des Kunden über drei Rechnungen, eine Zahlung an den Lieferanten über zwei Eingangsrechnungen abzüglich einer Gutschrift. In der Zuordnung werden Belege „zur Sammelbuchung hinzugefügt"; die Summe wird gegen den Umsatz geprüft, ein Rest lässt sich als Skonto, Gebühr oder Teilzahlung behandeln. Der Dialog steht für Geldeingänge und Geldausgänge zur Verfügung.

## Auf Konto buchen — Umsätze ohne Beleg

Nicht jeder Umsatz gehört zu einer Rechnung: Umbuchungen zwischen eigenen Konten, Bareinzahlungen, Privatentnahmen, Lohn, Sozialversicherung, Finanzamt, Bankgebühren, Darlehen. Dafür gibt es in der Umsatzliste und im Buchungsdialog **„Auf Konto buchen"**. Der Dialog fragt zuerst **„Was ist das für ein Vorgang?"** und zeigt die Buchungsvorlagen aus der Tabelle `bank_booking_templates` als Auswahl; das passende Gegenkonto (SKR03 oder SKR04) ist je Vorlage hinterlegt und wird zur Laufzeit über den Kontenrahmen des Mandanten aufgelöst.

Das System erkennt den Vorgang vorab:

- **Gegen-IBAN gehört zu einem eigenen Bankkonto** → Umbuchung, Zielkonto vorbelegt. Es entsteht **eine** Buchung Bank A ↔ Bank B. Liegt der Gegenumsatz auf dem Zielkonto schon vor (gleicher Betrag, ±5 Tage), wird er mitgebucht; kommt er erst später, erkennt der Dialog das vorhandene Bankbein und bietet **„Mit dieser Buchung verknüpfen"** an — ohne zweite Buchung. Das Lösen einer Seite lässt die Buchung für die andere stehen.
- **Vorhandene Buchung auf dem Bankkonto** (z. B. eine Bareinzahlung, die in der Kasse schon gegen die Bank gebucht wurde) → nur verknüpfen.
- **Muster im Verwendungszweck oder Empfänger** (Lohn, Gehalt, Krankenkasse, Finanzamt, Entgelt …) → Vorlage vorbelegt. Benutzte Vorlagen zählen hoch und gewinnen bei mehreren Treffern.

Für Konten außerhalb des Systems (anderes Unternehmen, Privatkonto) steht **Geldtransit** bzw. **Privatentnahme/Privateinlage** bereit. Jede Vorlage außer der Umbuchung läuft über die Positionen, lässt sich also **aufteilen** (siehe unten) und bekommt bei Bedarf einen Steuerschlüssel (Bankgebühren mit 19 % Vorsteuer). Gebucht wird wie kivitendos Dialogbuchung (`gl` + `acc_trans`), die Verknüpfung zum Umsatz liegt in `bank_transaction_acc_trans.gl_id`; **Rückgängig** entfernt die Buchung samt Kopf.

## Buchung ansehen und ändern

Jeder gebuchte Umsatz hat in der Umsatzliste **„Buchung ansehen"** (Buchsymbol). Der Dialog zeigt je verknüpfter Buchung den Kopf (Dialogbuchung, Eingangs- oder Ausgangsrechnung, Belegnummer, Datum, Partner), die **Positionen so, wie sie eingegeben wurden** (Konto, Brutto, Netto, Steuersatz und -betrag) und aufklappbar **alle Buchungsbeine** mit Soll/Haben, Steuerschlüssel und Text; Beine mit Bankverknüpfung sind markiert.

- **Dialogbuchungen** („Auf Konto buchen") lassen sich mit **Bearbeiten** ändern: Konto(en), Steuer, Text, auch von und zu einer Umbuchung. Der Bruttobetrag ist der Umsatzbetrag. Geändert wird wie in kivitendo: alte Beine löschen, neue schreiben, Verknüpfungen zu den Bankumsätzen neu setzen; verliert die Gegenseite einer Umbuchung ihr Bein, ist ihr Umsatz wieder offen.
- **Eingangsrechnungen, die aus dem Umsatz entstanden sind**, lassen sich ebenfalls bearbeiten: Lieferant, Rechnungsnummer, Buchungstext und die Aufwandspositionen (Konto, Brutto, Steuersatz). Die Zahlungsbeine bleiben, die Positionssumme muss dem bezahlten Betrag entsprechen (kivitendo AP.pm: Erstbuchungsbeine neu schreiben).
- **Ausgangsrechnungen** werden angezeigt und per „Rechnung öffnen" in der Faktura bearbeitet.
- **Buchung rückgängig** bleibt im selben Dialog erreichbar.

Buchungen bis zum kivitendo-Abschlussdatum (`defaults.closedto`) und stornierte Buchungen sind gesperrt.

## Splitbuchung — ein Umsatz, mehrere Konten oder Steuersätze

Eine Lieferantenrechnung enthält oft Posten, die auf verschiedene Konten gehören: ein Ersatzteil als Wareneingang, ein anderes als Kfz-Kosten, oder 10 € Mahnkosten auf 6436 neben der eigentlichen Ware. Im Dialog **„Als Eingangsrechnung buchen"** gibt es dafür **Aufteilen**:

- Die erste Position ist der gewohnte Dialog (Aufwandskonto, Steuersatz). **Aufteilen** macht daraus eine Liste von Positionen mit je Konto, Bruttobetrag und Steuersatz.
- Jede neue Position übernimmt das Konto der vorherigen und wechselt den Steuersatz (19 % ↔ 7 %) — der häufigste Fall braucht so nur noch den Betrag.
- Unter der Liste steht, was vom Umsatz noch nicht verteilt ist; der Zauberstab an einer Position trägt den Rest dort ein. Gebucht werden kann erst, wenn die Aufteilung aufgeht.
- Beim Wählen eines Kontos wird sein Steuerschlüssel aus dem Kontenrahmen vorbelegt (3300 Wareneingang 7 % → 7 %).

Gebucht wird **wie in kivitendos Kreditorenbuchung mit mehreren Zeilen**: je Position ein Aufwandsbein mit Steuerschlüssel und direkt dahinter das Vorsteuerbein, zum Schluss ein Verbindlichkeitsbein über die Summe. `ap.amount`/`netamount` sind die Summen. UStVA, DATEV-Export und Storno arbeiten unverändert auf diesen Beinen.

Dasselbe gilt für die **Kasse** (Bon mit 7 % und 19 %) und für die Positionen eines per **Magisch Buchen** erkannten Belegs — siehe [Buchhaltung](buchhaltung.md).

## Kartenabrechnungen (Settlements)

Kartendienstleister (Flatpay, Rapyd, SumUp) zahlen gesammelt aus — ein Bankumsatz steht für viele Kartenzahlungen abzüglich Gebühren. Der Reiter **Abgleich** löst das auf:

- Abrechnungsbericht als PDF oder CSV hochladen; der Parser erkennt die Einzelzahlungen und Gebühren
- **SumUp ohne Datei**: Auszahlungen werden direkt über die SumUp-API geholt (API-Schlüssel und Händlercode in den CRM-Vorgaben) und wie ein Bericht gespeichert
- Je Auszahlung werden die passenden Rechnungen vorgeschlagen (Betrag, Datum, Teilsummen) und gebucht: Zahlungseingang auf die Rechnung, Gebühr auf das Gebührenkonto, Rest gegen das Bankkonto
- Buchungen lassen sich stornieren; der Vorschau-Modus zeigt den Buchungsplan vorher
- Wurde eine Kartenzahlung schon von Hand als bezahlt gegen das Bankkonto gebucht (ohne verknüpften Bankumsatz), erkennt der Dialog das, schlägt die Rechnung trotzdem vor und ersetzt die Handbuchung beim Buchen durch die Kartenabrechnung – der Betrag steht so nicht doppelt auf dem Bankkonto
- Weicht eine Kartenzahlung geringfügig vom Rechnungsbetrag ab (bis 2 €, z. B. Tippfehler am Terminal), wird die Rechnung trotzdem zugeordnet; die Differenz wird als eigene Zeile auf das Gebührenkonto gebucht und die Rechnung vollständig ausgeglichen
- Nach dem Buchen zeigt der Umsatz die ausgeglichenen Rechnungsnummern an; ein Klick öffnet die Rechnung, wie bei jeder anderen Rechnungszahlung

### Anzahlungen, Teilzahlungen und Handzahlungen

- **Anzahlungen und Teilzahlungen**: Eine Kartenzahlung, zu der keine Rechnung mit gleichem Betrag existiert, wird im Dialog einer beliebigen offenen Rechnung zugeordnet (Suche nach Nummer oder Kunde). Gebucht wird genau der Kartenbetrag als Zahlung auf diese Rechnung, der Rest bleibt offen — wie eine Teilzahlung in kivitendo, ohne Verrechnungs- oder Geldtransitkonto. Rechnungen nach dem Auszahlungstag werden nicht angeboten.
- **Überbuchen von Handzahlungen**: Wurde eine Rechnung schon von Hand als bezahlt gebucht, ersetzt die Kartenabrechnung diese Zahlung. Das gilt auch, wenn an der Handzahlung ein Bankumsatz hängt, der selbst eine Kartenauszahlung ist (z. B. SumUp-Auszahlung als „Zahlungseingang" auf die falsche Rechnung gebucht): Dieser Umsatz wird dabei wieder offen und kann richtig abgerechnet werden.
- **Keine Dubletten**: Eine Auszahlungskennung (SumUp-PID) wird nur einmal gespeichert, egal wie oft ein Bericht hochgeladen wird
