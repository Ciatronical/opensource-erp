# Lager — Bestandsführung, Scanner und Inventur

Das Lagermodul führt Bestände auf Lager-, Lagerplatz- und Chargenebene. Es arbeitet direkt auf den kivitendo-Tabellen `warehouse`, `bin` und `inventory` — es gibt keine Parallelhaltung und keine Synchronisation: was hier gebucht wird, sieht kivitendo sofort, und umgekehrt.

Aufruf: **Lager** im Hauptmenü oder direkt `/lager`.

---

## Einrichtung

Ist noch kein Lager vorhanden, zeigt die Ansicht einen Einstieg statt einer leeren Tabelle. Zwei Felder — Lagername und Lagerplatz — und ein Klick genügen. Angelegt werden dabei:

- ein Lager (`warehouse`)
- ein Lagerplatz darin (`bin`)
- die Vorgaben in der Firmenkonfiguration (Standardlager, Standard-Lagerplatz, Inventurlager, Inventur-Lagerplatz)

Damit funktionieren Lieferscheine und Inventur ohne weitere Konfiguration. Weitere Lager und Plätze lassen sich später im Reiter **Lager & Plätze** anlegen.

---

## Lager-Cockpit

Die Startseite zeigt vier Kennzahlen, die zugleich Filter sind — ein Klick zeigt genau die Artikel dahinter:

| Kennzahl | Bedeutung |
|---|---|
| Artikel mit Bestand | Artikel mit einem Bestand ungleich 0 |
| Lagerwert | Bestand × Einkaufspreis (`parts.lastcost`) |
| Unter Meldebestand | Artikel, deren Bestand den Meldebestand erreicht hat |
| Ladenhüter | Artikel ohne Bewegung seit 180 Tagen |

### Bestandsabgleich

Kivitendo führt in `parts.onhand` ein Schnellfeld mit, das ein Trigger fortschreibt. Nach Datenimporten oder direkten Eingriffen in die Datenbank kann es von der Summe der Lagerbewegungen abweichen — dann zeigen kivitendo-Masken einen anderen Bestand als das Lagermodul.

Das Lagermodul rechnet den Bestand **immer aus den Bewegungen** und meldet eine Abweichung als Hinweis mit einem Knopf **Jetzt abgleichen**, der das Schnellfeld neu berechnet.

---

## Bestand

Statt eines Filterformulars gibt es eine Sofortsuche über Artikelnummer, Bezeichnung und EAN. Jede Zeile lässt sich aufklappen und zeigt dann die Verteilung auf Lager, Lagerplatz und Charge — ohne Nachladen, die Verteilung kommt mit der Trefferliste mit.

Zeilenaktionen:

- **Buchen** — öffnet den Buchungsdialog mit dem Artikel vorbelegt
- **Meldebestand setzen** — Schwelle, ab der der Artikel als nachzubestellen gilt (`parts.rop`)

---

## Buchen

Es gibt **einen** Dialog für alle drei Bewegungen. Oben wird umgeschaltet, das Formular passt sich an — Zielfelder erscheinen nur beim Umlagern.

| Richtung | Wirkung |
|---|---|
| **Einlagern** | Eine Zugangszeile am gewählten Platz |
| **Auslagern** | Eine Abgangszeile am gewählten Platz |
| **Umlagern** | Zwei Zeilen mit derselben Vorgangsnummer: Abgang an der Quelle, Zugang am Ziel |

Weitere Angaben (Bewegungsart, Charge, Mindesthaltbarkeit, Bemerkung) stehen aufklappbar darunter — sie werden selten gebraucht und sollen den Normalfall nicht verstellen.

**Bestandsschutz:** Aus- und Umlagern prüfen den verfügbaren Bestand am Quellplatz inklusive Charge. Reicht er nicht, nennt die Meldung die verfügbare und die angeforderte Menge, statt nur „Fehler" zu sagen.

**Lagerfähigkeit:** Ein bebuchter Artikel wird automatisch als lagerfähig markiert (`parts.stockable`). Ohne dieses Kennzeichen blendet kivitendo ihn in seinen Lagermasken aus und der Bestand wäre dort unsichtbar.

### Zurücknehmen

Eine manuelle Buchung lässt sich im Bewegungsjournal zurücknehmen, solange die in der Firmenkonfiguration eingestellte Frist (**Lager → Rückgängig-Intervall**) nicht abgelaufen ist. Buchungen, die zu einem Beleg gehören (Lieferschein, Rechnung), sind gesperrt — sie werden am Beleg geändert.

---

## Scanner-Modus

Aufruf: **Scanner** im Lager-Cockpit oder `/lager/scanner`.

Vollbildansicht für Tablet oder Handscanner: große Ziele, dunkler Hintergrund, das Eingabefeld hat immer den Fokus. Ein Handscanner tippt den Code und sendet Enter — der ganze Vorgang läuft damit ohne Berührung des Bildschirms.

**Ablauf**

1. Richtung wählen (Einlagern / Auslagern / Umlagern) und Lagerplatz einstellen — bleibt für alle folgenden Scans
2. Artikel scannen oder Artikelnummer tippen
   - eindeutiger Treffer (EAN oder Artikelnummer) → direkt weiter zur Menge
   - mehrere Treffer → große, antippbare Auswahlliste
3. Menge eingeben oder eine der Schnellmengen (1/2/5/10/20) antippen
4. Enter oder **Buchen** → Bestätigung mit neuem Bestand, Feld leer und fokussiert für den nächsten Scan

Die letzten acht Buchungen bleiben mit **Rückgängig** stehen — ein Fehlscan ist damit sofort korrigiert.

---

## Inventur

Aufruf: Reiter **Inventur** im Lager-Cockpit.

Eine Inventur ist durch Lager und Stichtag bestimmt. Gezählt wird der Bestand **zum Stichtag** — Bewegungen danach verfälschen das Ergebnis nicht.

### Blindzählung

Standardmäßig ist die Blindzählung aktiv: der Buchbestand wird **nicht mitgeschickt**, solange keine Menge eingetragen ist. Wer die Sollmenge sieht, schreibt sie erfahrungsgemäß ab — genau das macht eine Inventur wertlos.

Sobald eine Menge eingetragen und das Feld verlassen wird, kommen Buchbestand und Differenz zurück und erscheinen in der Zeile. Eine Abweichung meldet zusätzlich ein Hinweis, sodass sofort nachgezählt werden kann.

Die Blindzählung lässt sich abschalten, etwa um eine bereits abgeschlossene Inventur nachzuvollziehen.

### Ablauf

1. **Neue Inventur** anlegen: Bezeichnung, Lager, Stichtag
2. Zählen — Suche oder Scan springt zur passenden Zeile, der Fortschritt oben zeigt gezählte von erwarteten Plätzen
3. **Abschließen** öffnet die Zusammenfassung: Anzahl Abweichungen, Mehr- und Fehlbestand, Wertdifferenz und die Liste aller abweichenden Positionen
4. **Differenzen buchen** erzeugt für jede Abweichung eine Lagerbuchung mit der Bewegungsart „Inventur"; Positionen ohne Abweichung bleiben unberührt

Gebuchte Zählzeilen sind gesperrt. Eine Korrektur danach ist eine normale Lagerbuchung, keine Inventurzeile — die Inventur bleibt als Beleg unverändert.

**Verwerfen** löscht alle noch nicht gebuchten Zählungen. Bereits gebuchte Differenzen bleiben bestehen; sie sind echte Lagerbewegungen und werden nicht stillschweigend entfernt.

---

## Bewegungsjournal

Alle Lagerbewegungen mit Filtern für Zeitraum, Lager, Richtung und Freitext. Umlagerungen tragen dieselbe Vorgangsnummer und werden als ein Vorgang zurückgenommen.

Bewegungen aus Belegen sind als solche gekennzeichnet und lassen sich hier nicht zurücknehmen.

---

## Konfiguration

Die Lagereinstellungen liegen unter **Einstellungen → Lager** und **Einstellungen → Inventur**. Sie wirken auf die kivitendo-Lagerlogik und gelten damit auch für Lieferscheine und Erzeugnisfertigung:

| Einstellung | Wirkung |
|---|---|
| Standardlager / Standard-Lagerplatz | Vorbelegung in Buchungen und Belegen |
| Auslagern ohne Bestandsprüfung | Eigenes Lager/Platz für Buchungen ohne Deckung |
| Rückgängig-Intervall | Frist, innerhalb derer Buchungen zurückgenommen werden dürfen |
| Mindesthaltbarkeitsdatum anzeigen | Blendet das MHD-Feld im Buchungsdialog ein |
| Inventurlager / Inventur-Lagerplatz | Vorgaben für neue Inventuren |
| Inventur-Stichtag | Vorgabe des Stichtags |

---

## Datenmodell

| Tabelle | Herkunft | Zweck |
|---|---|---|
| `warehouse`, `bin` | kivitendo | Lager und Lagerplätze |
| `inventory` | kivitendo | Jede einzelne Lagerbewegung; Bestand = Summe der Zeilen |
| `transfer_type` | kivitendo | Bewegungsarten (Wareneingang, Verbrauch, Inventur, …) |
| `stocktakings` | kivitendo | Zählergebnisse; `inventory_id` gesetzt = gebucht |
| `stocktaking_sessions` | OpensourceERP | Klammer um eine Zählung (Name, Status), zugeordnet über Lager + Stichtag |

`stocktakings` bleibt dadurch unverändert kivitendo-kompatibel — die Inventur-Klammer hängt außen dran statt in der Kerntabelle.
