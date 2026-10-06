---
title: Wiederkehrende Rechnungen
summary: Abos, Mieten und Wartungsverträge automatisch abrechnen — mit Vorschau, Kündigungsfristen und Preisanpassung
group: core
category: Verkauf
order: 21
status: stable
since: 2026-10-02
---

# Wiederkehrende Rechnungen — Abos, Mieten, Wartungsverträge

Wiederkehrende Rechnungen erzeugen aus einem Auftrag in festem Rhythmus Rechnungen — monatliche Miete, jährliche Wartung, Abo-Gebühren. Aufruf: **Verkauf → Wiederkehrende Rechnungen**. Die Funktion baut auf den kivitendo-Tabellen `periodic_invoices_configs` und `periodic_invoices` auf und bleibt damit kompatibel; die Erweiterungen liegen in eigenen Zusatztabellen.

## Einrichten

Am Auftrag wird die Abrechnung angelegt (Karte „Wiederkehrende Abrechnung"):

| Einstellung | Bedeutung |
|-------------|-----------|
| Rhythmus | monatlich, vierteljährlich, halbjährlich, jährlich, wöchentlich, alle 2 Wochen, täglich, einmalig oder frei (alle *n* Tage/Wochen/Monate/Jahre) |
| Start und Ende | Laufzeit; ohne Ende läuft die Abrechnung unbegrenzt |
| Kalender-Ausrichtung | Perioden an Monats-, Quartals- oder Jahresgrenzen mit anteiliger erster Periode |
| Vor- oder nachschüssig | Rechnungsdatum vor oder nach der Periode, mit Datumsversatz |
| Preise | eingefrorene Auftragspreise oder aktuelle Listenpreise, jährliche Preisanpassung in Prozent |
| Positionen | je Position: immer, nur einmal (Einrichtungsgebühr) oder nie berechnen |
| Kündigungsfrist | Frist und nächstmöglicher Kündigungstermin werden berechnet |
| Zustellung | Rechnung per E-Mail (Text mit Platzhaltern), per WhatsApp (Dokument-Vorlage an die Mobilnummer des Kunden, Nummer und Vorlage je Abrechnung wählbar) oder Druck |
| Mahnsperre | Rechnungen aus dieser Abrechnung werden nicht gemahnt |

Eine **Vorschau** zeigt vor dem Speichern, was wann in welcher Höhe berechnet wird — Vorschau, Fälligkeit und Erzeugung rechnen mit denselben Datenbankfunktionen und kommen deshalb immer auf dieselben Perioden.

## Übersicht

Die Übersicht zeigt Kennzahlen (jetzt fällig, aktive Abrechnungen, monatlich wiederkehrender Umsatz, Kündigungsfristen in 30 Tagen, angehalten, pausiert) und drei Reiter:

- **Fällig**: alle fälligen Perioden, Rechnungen einzeln oder gesammelt erzeugen
- **Abrechnungen**: alle Konfigurationen mit Status (aktiv, pausiert, gekündigt, beendet)
- **Vorschau**: kommende Perioden über alle Abrechnungen

Einzelne Perioden lassen sich bewusst **überspringen**; eine Abrechnung lässt sich **pausieren** (mit Enddatum) und wieder aufnehmen. Hat der Kunde überfällige Rechnungen, stoppt die Erzeugung, bis sie bezahlt sind.

## Automatik

Ein Cron-Lauf erzeugt fällige Rechnungen von selbst, verschickt sie je nach Einstellung per E-Mail oder WhatsApp und legt die PDFs in der Belegablage ab. Schlägt ein Versand fehl, zeigt das Ergebnis den Grund; ein Klick auf den Fehler wiederholt den Versand. Jede erzeugte Rechnung merkt sich ihre Periode, damit nichts doppelt berechnet wird.

```
0 6 * * *  php backend/cli/recurring-invoices.php --client <id>
```
