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

### Artikelsuche in den Positionen *(Schalter)*

Beim Tippen in eine Positionszeile erscheint die Vorschlagsliste klug sortiert — die Datenbank liefert sie in einer einzigen Abfrage fertig gereiht:

1. **Beschreibung beginnt mit dem Begriff** („Batte" → „Batterie 12V 74 Ah")
2. **Artikelnummer beginnt mit dem Begriff**
3. **Ein Wort der Beschreibung beginnt mit dem Begriff** („Montage Batterie")
4. **Begriff irgendwo enthalten** („Starterbatterie")

Innerhalb jeder Stufe stehen die **häufig verwendeten Artikel oben**; Verwendungen der letzten zwölf Monate zählen zusätzlich. Je Artikelart (Ware, Dienstleistung) sind immer mindestens drei Treffer dabei. Die Liste zeigt wie gewohnt Beschreibung und Artikelnummer, dezent rechts den Verwendungszähler, eine gestrichelte Linie trennt die Stufen.

Dieselbe Rangfolge gilt für die **Arbeitsanweisungen** im Werkstattauftrag *(LxCars)*: Anweisungsnummer exakt, Beschreibung beginnt mit, Nummer beginnt mit, Wortanfang, enthalten — darin nach Verwendungshäufigkeit mit demselben Gewicht; diktierte Sätze finden weiterhin über Einzelwörter.

Die Häufigkeit kommt aus `parts_usage_oserp` (Verwendungen je Artikel und Belegmonat), die Trigger auf `orderitems` und `invoice` in Echtzeit pflegen — die Suche bleibt dadurch auch bei großen Artikelstämmen im Millisekundenbereich.

**Firmenkonfiguration → Grunddaten → Artikelsuche in Belegen:** Anzahl Vorschläge, Gewicht Verwendungshäufigkeit, Gewicht „zuletzt verwendet", Zeitraum „zuletzt". Eine **Probesuche** zeigt die Wirkung der eingetragenen Gewichte sofort, noch vor dem Speichern; „Neu aufbauen" zählt die Verwendungsstatistik aus den Bestandsdaten neu.

## Drucken

Belege können als PDF gedruckt werden. Die Druckvorlagen sind konfigurierbar unter **Einstellungen > Druckvorlagen**.

### Kartenterminal (SumUp Solo) *(extern, Schalter)*

Ist unter *Firmenkonfiguration → CRM → SumUp* ein SumUp Solo über die Cloud API gekoppelt und **SumUp aktivieren** gesetzt, erscheint in der Zahlungskarte jeder Rechnung eine Terminal-Leiste. Für Firmen ohne SumUp ändert sich nichts.

- **Offener Betrag automatisch**: Beim Öffnen einer noch nicht bezahlten Rechnung geht der offene Betrag sofort an das Terminal.
- **Abweichender Betrag**: Das Eingabefeld ist mit dem offenen Betrag vorbelegt und kann überschrieben werden, z. B. für eine Anzahlung oder die Selbstbeteiligung bei einer Frontscheibe. **An Terminal senden** bricht einen noch laufenden Checkout am Reader ab und startet den neuen Betrag.
- **Status live**: Die Leiste zeigt, was am Terminal passiert (Karte vorlegen, PIN-Eingabe, Trinkgeld, Unterschrift, offline); **Abbrechen** beendet den Vorgang am Reader. Das Ergebnis wird über die SumUp-Transaktions-API abgefragt, eine öffentlich erreichbare Webhook-URL ist nicht nötig.
- **Keine Sofortbuchung**: Die Kartenzahlung wird bewusst **nicht** über ein Verrechnungskonto gebucht. Sie erscheint in der Zahlungskarte als grüne Infozeile („Kartenzahlung am Terminal – wird mit der SumUp-Auszahlung im Banking gebucht“) und mindert den angezeigten offenen Betrag, damit der Rest nicht erneut ans Terminal geht. Gebucht wird erst, wenn die SumUp-Sammelauszahlung auf dem Bankkonto eintrifft: Der Abgleich im Banking findet die Rechnung dann über den Transaktionscode des Checkouts exakt (vor Rechnungsnummer- und Betragsvergleich) und bucht direkt gegen die Bank.
- **Vermerk auf der Rechnung**: Die Druckvorlagen zeigen unter der Summe „109,00 € mit Karte bezahlt (14.10.2026)“, bei Teilzahlung zusätzlich den Restbetrag (Vorlagenvariablen `card_paid`, `card_paid_date`, `card_open`).
- **Automatischer Druck**: Nach erfolgreicher Zahlung wird die Rechnung auf dem gewählten Drucker in der konfigurierten Anzahl Exemplare gedruckt (Standard 2, 0 schaltet ab). Der konfigurierte Auto-Versand per E-Mail/WhatsApp läuft dabei wie beim manuellen Druck.
- **Kundenbeleg als Belegseite**: Der Solo hat keinen Bondrucker. Der Kundenbeleg der Kartenzahlung (Händler, Terminal-ID, Datum, Beleg-Nr., Transaktionscode, Genehmigungs-Nr., Karte mit letzten vier Ziffern, AID, Eingabeart, Betrag) kommt aus der SumUp-Receipts-API, wird mit dem Checkout gespeichert und als eigene Seite hinter die Rechnung gedruckt. Die Belegseite kommt ohne Briefkopf, Logo und Fußzeile und wird auf eine gerade Seitenzahl geschoben, damit sie bei Duplexdruck (Druckbefehl z. B. `lpr -o sides=two-sided-long-edge`) auf der Rückseite liegt. Mehrere Kartenzahlungen einer Rechnung ergeben mehrere Belege auf der Seite (Vorlagenschleife `card_receipt_amount`).

Jeder gesendete Betrag wird mit Ergebnis, Transaktionscode und Kundenbeleg in `sumup_checkouts` protokolliert; der Code führt beim Abgleich der SumUp-Sammelauszahlung direkt zur Rechnung.

## E-Mail-Versand

Der Button **E-Mail** öffnet einen Dialog mit Empfänger (CC/BCC aus dem Kundenstamm), Betreff und Nachrichtentext; das Beleg-PDF hängt immer an. Über **Weitere Anhänge hinzufügen** lassen sich beliebige zusätzliche Dateien (z. B. AGB, Gutachten, Fotos) mitschicken — mehrere auf einmal, jede als Chip mit Größe entfernbar, gleichnamige Dateien ersetzen sich. Überschreiten die Zusatzdateien die in der Firmenkonfiguration hinterlegte **Maximale E-Mail-Größe** (*CRM → E-Mail-Client*, vom Mailserver ermittelbar, siehe [E-Mail](email.md)), zeigt der Dialog eine Warnung mit der tatsächlichen Größe und sperrt **Senden**; ohne Konfiguration gelten 10 MB, immer gekappt durch die PHP-Grenze `post_max_size`. Die Anhänge landen wie das PDF im `email_journal` und damit im Versandverlauf des Belegs.

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
