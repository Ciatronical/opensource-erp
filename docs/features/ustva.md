# Umsatzsteuer-Voranmeldung (UStVA)

Ermittelt die Kennzahlen der Umsatzsteuer-Voranmeldung aus den tatsächlich gebuchten Geschäftsvorfällen — mit Nachweis bis zur einzelnen Buchung.

Aufruf: **Buchhaltung → Umsatzsteuer** oder direkt `/buchhaltung/umsatzsteuer-voranmeldung`.

> **Keine ELSTER-Übermittlung.** OpensourceERP meldet nicht selbst an das Finanzamt. Die Kennzahlen lassen sich einzeln kopieren (für die Eingabe in ELSTER-Online) oder als CSV an die Steuerkanzlei geben. Der vollständige Buchungsstapel geht über den [DATEV-Export](../features/index.md).

---

## Woher die Zahlen kommen

Die Kennzahlen werden **nicht aus den Konten-Stammdaten geschätzt**, sondern aus `acc_trans` ermittelt — also aus dem, was wirklich gebucht wurde. Jede steuerlich relevante Buchungszeile wird eingeordnet nach:

| Merkmal | Quelle |
|---|---|
| Rolle | Konto-Verknüpfung: `AR_amount`/`AP_amount` = Bemessungsgrundlage, `AR_tax`/`AP_tax` = Steuerkonto |
| Seite | Belegart (Ausgangs- oder Eingangsrechnung); bei Hauptbuchbuchungen die Konto-Verknüpfung |
| Steuerschlüssel und -satz | Der gebuchte Steuerschlüssel bzw. das Steuerkonto |

Aus dieser Kombination ergibt sich über die **Zuordnungstabelle** die Kennzahl.

### Nichts verschwindet

Buchungen, die sich keiner Kennzahl zuordnen lassen, werden im Block **Nicht zugeordnet** ausgewiesen — mit Konto, Steuerschlüssel und Betrag. Eine Voranmeldung, bei der stillschweigend Beträge fehlen, wäre gefährlicher als gar keine.

Ein Klick auf **Zuordnen** wählt die Kennzahl und legt die Regel dauerhaft an.

---

## Soll- und Ist-Versteuerung

Beide Verfahren werden unterstützt. Die Vorgabe kommt aus der Firmenkonfiguration (`defaults.accounting_method`), lässt sich in der Ansicht aber umschalten — nützlich, um beide Sichten zu vergleichen.

| Verfahren | Maßgeblich |
|---|---|
| **Soll-Versteuerung** | Das Buchungsdatum der Rechnung |
| **Ist-Versteuerung** | Der Zahlungszeitpunkt |

Bei der Ist-Versteuerung wird jede Zahlung im Zeitraum **anteilig** auf die Steuerstruktur der zugehörigen Rechnung verteilt: Bei einer Rechnung über 1.190 € (1.000 € netto + 190 € Steuer) und einer Teilzahlung von 595 € gehen 500 € in die Bemessungsgrundlage und 95 € in die Steuer.

Der Drilldown zeigt bei Ist-Versteuerung zusätzlich die Spalte **Gezahlt / Brutto**, sodass die anteilige Zuordnung nachvollziehbar ist.

Weicht die gewählte Besteuerungsart von der Mandantenvorgabe ab, weist die Ansicht darauf hin — solche Zahlen sind zum Vergleichen gedacht, nicht zum Abgeben.

---

## Zeitstrahl

Über der Ansicht liegt das ganze Jahr als Zeitstrahl, umschaltbar zwischen Monaten und Quartalen. Jeder Zeitraum zeigt seine Zahllast und seinen Status:

| Status | Bedeutung |
|---|---|
| **läuft** (gestrichelt) | Der Zeitraum ist noch nicht zu Ende, die Zahlen ändern sich noch |
| **offen** | Abgeschlossen, Frist läuft noch |
| **überfällig** (rot) | Die Abgabefrist ist verstrichen |
| **abgegeben** (grün) | Als abgegeben markiert |
| ausgegraut | Liegt in der Zukunft |

### Abgabefrist

Der 10. des Folgemonats, bei aktiver **Dauerfristverlängerung** einen Monat später. Fällt der Tag auf ein Wochenende, verschiebt sich die Frist auf den nächsten Werktag.

Die Dauerfristverlängerung wird über den Konfigurationswert `ustva_permanent_extension` in `defaults_oserp` gesetzt.

---

## Kennzahlen

Die Kennzahlen sind nach Abschnitten gruppiert: steuerpflichtige Umsätze, steuerfreie und nicht steuerbare Umsätze, innergemeinschaftliche Erwerbe, § 13b, weitere Steuerbeträge, Vorsteuer, Ergebnis. Leere Kennzahlen sind standardmäßig ausgeblendet und lassen sich einblenden.

Je Kennzahl werden angezeigt:

- der **gemeldete Wert** — Bemessungsgrundlagen ohne Cent (zur Null hin gekürzt), Steuerbeträge mit Cent
- die **gerechnete Steuer** bei Kennzahlen mit festem Steuersatz (81 → 19 %, 86 → 7 %)
- ein **Kopier-Knopf** für die Übertragung nach ELSTER-Online

**Ein Klick auf eine Kennzahl öffnet den Nachweis**: alle Buchungen dahinter mit Datum, Beleg, Konto, Steuerschlüssel und Betrag. Bemessungsgrundlage und Steuer werden getrennt summiert, jede Zeile ist als das eine oder andere gekennzeichnet.

---

## Prüfungen

Neben dem Ergebnis stehen laufende Plausibilitätsprüfungen:

| Prüfung | Warum sie wichtig ist |
|---|---|
| Beträge ohne Kennzahl | Diese Umsätze fehlen sonst in der Meldung |
| Gerechnete vs. gebuchte Steuer | Weicht die aus der Bemessungsgrundlage errechnete Steuer von der tatsächlich gebuchten ab, stimmt etwas an den Belegen nicht (Rundung, falscher Steuerschlüssel, manuelle Korrektur) |
| Erlöse ohne Steuerschlüssel | Typisch für Konten, die nie steuerlich eingerichtet wurden |
| Buchungen nach der Abgabe | Wird nach der Abgabe noch in den Zeitraum gebucht, ist eine Berichtigung fällig |

---

## Abgeben und festschreiben

**Als abgegeben markieren** sichert die Kennzahlen als Momentaufnahme (`ustva_filings`). Spätere Buchungen im selben Zeitraum ändern die abgegebenen Zahlen dadurch nicht mehr, werden aber als Hinweis auf eine notwendige Berichtigung angezeigt.

Die Abgabe lässt sich zurücknehmen; der Zeitraum gilt danach wieder als offen.

---

## Zuordnung bearbeiten

**Zuordnung bearbeiten** zeigt die vollständige Regeltabelle. Eine Regel besteht aus:

| Feld | Bedeutung |
|---|---|
| Seite | Ausgang (Umsatzsteuer) oder Eingang (Vorsteuer) |
| Art | Bemessungsgrundlage oder Steuerkonto |
| Steuerschlüssel / Satz | Leer = beliebig |
| Konto | Leer = alle Konten; ein Eintrag mit Konto **gewinnt** gegenüber dem allgemeinen |
| Kennzahl | Ziel im Formular |

Die kontospezifische Regel wird zum Beispiel bei § 13b gebraucht: Vorsteuer und geschuldete Umsatzsteuer tragen dort denselben Steuerschlüssel und lassen sich nur über das Konto unterscheiden.

### Standardzuordnung

Bei der Einrichtung wird die Zuordnung für die kivitendo-Standardsteuerschlüssel angelegt (19 %, 7 %, 16 %/5 % als „andere Steuersätze", innergemeinschaftliche Lieferungen und Erwerbe, Vorsteuer, § 13b). Bei einem Update kommen neue Vorgaben dazu, ohne eigene Anpassungen zu überschreiben.

Die Steuerkonten zu den Kennzahlen 81, 86, 89 und 93 sind bewusst mit zugeordnet: dort rechnet das Finanzamt die Steuer selbst aus der Bemessungsgrundlage, die gebuchte Steuer dient als **Kontrollwert** für die Plausibilitätsprüfung.

---

## Export

**Als CSV exportieren** liefert alle Kennzahlen mit Bezeichnung, Art und Betrag — geeignet für die Steuerkanzlei oder zur Ablage.

---

## Datenmodell

| Tabelle | Zweck |
|---|---|
| `ustva_kennzahlen` | Die Kennzahlen des amtlichen Formulars mit Abschnitt, Art und Steuersatz. Liegt in der Datenbank statt im Code, damit Formularänderungen ohne Deployment nachziehbar sind. |
| `ustva_mapping` | Zuordnung Steuerschlüssel/Steuersatz/Konto → Kennzahl. Benutzerdaten, in der Oberfläche editierbar. |
| `ustva_filings` | Abgegebene Voranmeldungen mit Momentaufnahme der Kennzahlen. |
