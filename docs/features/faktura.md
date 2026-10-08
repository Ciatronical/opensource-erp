---
title: Faktura
summary: Angebote, Aufträge, Rechnungen, Lieferscheine, Gutschriften, E-Rechnung
group: core
category: Verkauf
order: 20
status: stable
---

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

## Versandstatus

Direkt unter der Aktionsleiste zeigt jeder gespeicherte Beleg, ob er den Kunden schon erreicht hat:

- **Noch nicht versendet** — zurückhaltende, gestrichelte Zeile mit Hinweis
- **Versendet** — grün akzentuierte Zeile mit einem Chip je Kanal (E-Mail, WhatsApp, DHL-Paket): letzter Zeitpunkt, Empfänger, bei WhatsApp der Zustellstatus (gesendet / zugestellt / gelesen / fehlgeschlagen) und die Anzahl weiterer Sendungen
- **Verlauf** klappt eine Zeitleiste aller Versandereignisse auf: Kanal, Zeitpunkt, Empfänger, Betreff bzw. Dateiname, Mitarbeiter
- Die Versand-Buttons der Aktionsleiste tragen ein Zähler-Badge, die Belegliste zeigt kleine E-Mail-/WhatsApp-Symbole in der Statusspalte

Grundlage ist die kivitendo-Konvention `record_links` (Beleg → `email_journal` bzw. `whatsapp_messages`); DHL-Sendungen kommen aus `dhl_shipments`. Jeder Versand aus der Faktura, aus der wiederkehrenden Abrechnung und jede DHL-Etikett-Erstellung wird so am Beleg verankert. Das Versandprotokoll kommt mit dem Beleg in **einem** Aufruf (`getFakturaData` → `sent_log`).

### Automatischer Versand beim Drucken *(Schalter)*

*Firmenkonfiguration → CRM → Belegversand → Beim Drucken automatisch versenden*: Aus (Standard), per E-Mail, per WhatsApp oder beides. Nach erfolgreichem Druck geht der Beleg an die beim Kunden hinterlegten Kanäle — E-Mail an die Kunden-E-Mail (CC/BCC aus dem Kundenstamm), WhatsApp an die erste Mobilnummer mit dem zugeordneten Faktura-Template. Je Kanal wird nur **einmal** gesendet; ein zweiter Ausdruck schreibt den Kunden nicht erneut an. Fehlende Empfängerdaten oder Fehler werden je Kanal als Hinweis gemeldet, der Druck bleibt davon unberührt.

## Suche

Die Auftragssuche ermöglicht das Finden von Belegen nach:
- Belegnummer
- Kundenname
- Datum / Zeitraum
- Status (offen/geschlossen)
- Betrag
