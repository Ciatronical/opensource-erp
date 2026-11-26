---
title: KI-Trading
summary: Eigenes automatisches Handelssystem je Mitarbeiter: Forschungslabor, Papierhandel, Echtgeld erst nach Freigabe
group: extension
extension: trading
category: Finanzen
order: 300
status: beta
---

# KI-Trading — erste Schritte

Jeder Mitarbeiter hat sein eigenes Handelssystem. Es sucht selbstständig nach Handelsstrategien, prüft sie an alten Kursen, lässt die bestandenen mit virtuellem Geld laufen und handelt erst dann mit echtem Geld, wenn Sie das ausdrücklich freigeben.

Aufruf: **Benutzermenü (oben rechts) → Auswertung → KI-Trading**.

> **Kein Gewinnversprechen.** Stand 24.11.2026 hat auf echten Kursdaten keine einzige Strategie alle Prüfungen bestanden. Das System hält dann Bargeld und forscht weiter. Mit echtem Geld können Sie den gesamten Einsatz verlieren.

---

## In fünf Schritten

### 1. Seite öffnen

Beim ersten Öffnen entsteht Ihr Handelssystem. Ab dann bearbeitet es die Engine alle zehn Minuten mit. Mehr ist für den Anfang nicht nötig: Kurse kommen ohne Zugang aus dem Netz, gehandelt wird mit virtuellem Geld (Vorgabe 10.000).

Voraussetzung, einmal je Firma: Erweiterung „KI-Trading" ist eingeschaltet und der Cronjob läuft (technische Seite: `dev/trading.md`).

### 2. Grenzen ansehen

Reiter **Sicherheit und Einstellungen**. Die Vorgaben sind vorsichtig gewählt; prüfen Sie, ob sie zu Ihnen passen:

| Grenze | Vorgabe |
|---|---|
| Höchstwert einer Order | 1.000 |
| Anteil eines Instruments am Depot | 35 % |
| Tagesverlust, ab dem gesperrt wird | 5 % |
| Verlust vom Höchststand, ab dem gesperrt wird | 20 % |
| Schutzstopp je Position | 25 % |

Ihre Werte gelten nur für Ihr System. Die KI kann sie nicht ändern.

### 3. Laufen lassen und zusehen

- **Labor**: welche Varianten geprüft wurden und woran sie gescheitert sind. „Zyklus jetzt starten" stösst einen Forschungslauf von Hand an.
- **Strategien**: was die Prüfungen bestanden hat. Am Anfang ist die Liste leer — das ist der Normalfall, kein Fehler.
- **Übersicht**: Stand des virtuellen Depots und der Tagesbericht.

Eine Strategie, die alle Prüfungen besteht, läuft zuerst mindestens 30 Tage im Vorwärtstest mit virtuellem Geld. Erst danach ist sie für Echtgeld zugelassen. Rechnen Sie also mit Wochen, nicht mit Tagen.

### 4. Broker anbinden (erst wenn eine Strategie zugelassen ist)

Reiter **Sicherheit und Einstellungen → Broker-Verbindungen → Verbindung anlegen**. Zuerst in der Umgebung **Testumgebung** anlegen, **Prüfen** drücken und einige Tage laufen lassen.

Beim Broker für den API-Schlüssel nur Abfrage und Handel freigeben, **niemals Auszahlung**. Tragen Sie einen **Höchstbetrag** ein: mehr setzt die KI über diese Verbindung nicht ein.

### 5. Echtgeld freigeben

Verbindung auf Umgebung **Echtgeld** anlegen, dann **Echtgeldhandel dauerhaft aktivieren**: drei Bestätigungen und das Wort `ECHTGELD` eintippen. Ab dann handelt die KI ohne Rückfrage, bis Sie den Handel deaktivieren.

Fangen Sie mit einem Betrag an, dessen Verlust Sie nicht schmerzt.

---

## Welcher Broker?

**Für den Anfang keiner.** Simulation und Vorwärtstest laufen ohne Broker.

**Für Echtgeld: Kraken.** Das ist derzeit die einzige sinnvolle Wahl, aus einem technischen Grund: Gehandelt werden acht Krypto-Paare in Euro (BTC, ETH, SOL, XRP, ADA, DOT, LINK, LTC), und nur für Kraken sind diese Paare hinterlegt.

| Broker | Eignung | Grund |
|---|---|---|
| Kraken | ja | alle acht Euro-Paare hinterlegt, alle Orderarten |
| Alpaca | derzeit nein | Adapter vorhanden, aber kein Instrument dafür hinterlegt; handelt in US-Dollar. Sinnvoll erst, wenn Aktien oder ETFs dazukommen |
| Interactive Brokers, Coinbase | nein | nicht umgesetzt |

Was Sie bei Kraken wissen müssen:

- **Keine echte Testumgebung.** In der „Testumgebung" schickt das System die Orders nur zur Prüfung an Kraken; Kraken führt sie nicht aus. Sie sehen also, ob Schlüssel und Orders angenommen werden, aber keine Ausführungen.
- **Schlüsselrechte:** Guthaben abfragen, offene und geschlossene Orders abfragen, Orders anlegen und stornieren. Keine Ein- oder Auszahlungsrechte.
- **Noch nie gegen ein echtes Konto gelaufen.** Der Adapter ist nach der Kraken-Dokumentation gebaut und mit nachgestellten Antworten getestet. Die ersten echten Orders deshalb mit kleinem Höchstbetrag und unter Beobachtung.

Ob Kraken für Sie steuerlich und rechtlich passt, klären Sie bitte selbst bzw. mit dem Steuerberater; das System führt keine Steuerauswertung.

---

## Wenn etwas schiefgeht

- **Not-Aus** (roter Knopf oben rechts): hält Ihren gesamten Handel sofort an und storniert alle offenen Orders, auch beim Broker. Bestände werden nicht verkauft.
- **Handelssperre**: setzt das System selbst, wenn eine Grenze reisst oder Fehler sich häufen. Aufheben können nur Sie, nachdem Sie die Ursache geprüft haben — die KI nie.
- **Protokoll**: jede Entscheidung, Order und Sperre mit Zeit und Auslöser.

## Grenzen des Systems

- Nur Tageskurse; kein Leerverkauf, kein Hebel. In fallenden Märkten ist Bargeld das beste erreichbare Ergebnis.
- Benachrichtigungen stehen nur in der Übersicht und im Protokoll, nicht per E-Mail oder Telegram.
- Oberfläche in Deutsch und Englisch.
