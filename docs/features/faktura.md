# Faktura — Angebote, Aufträge, Rechnungen

Das Faktura-Modul deckt den gesamten Belegfluss ab: Von der Anfrage über das Angebot bis zur Rechnung.

## Belegarten

| Beleg | Beschreibung |
|-------|-------------|
| **Angebot** | Preisvorschlag an den Kunden |
| **Auftrag** | Bestätigter Kundenauftrag |
| **Rechnung** | Abrechnung an den Kunden |
| **Einkaufsrechnung** | Eingangsrechnung vom Lieferanten |

## Belegfluss

```
Angebot → Auftrag → Rechnung
```

Jeder Beleg kann in den nächsten Typ umgewandelt werden. Positionen, Preise und Kundendaten werden übernommen.

## Positionen

Jeder Beleg enthält Positionen mit:
- Artikelnummer (aus Artikelstamm oder Freitext)
- Beschreibung
- Menge und Einheit
- Einzel- und Gesamtpreis
- Rabatt
- Steuersatz

## Drucken

Belege können als PDF gedruckt werden. Die Druckvorlagen sind konfigurierbar unter **Einstellungen > Druckvorlagen**.

## Suche

Die Auftragssuche ermöglicht das Finden von Belegen nach:
- Belegnummer
- Kundenname
- Datum / Zeitraum
- Status (offen/geschlossen)
- Betrag
