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
| **Auftrag** | Kundenauftrag — zunächst Auftragseingang, nach der Zusage des Kunden bestätigt (siehe [Auftragsbestätigung](#auftragsbestätigung-ki-erkennung-schalter)) |
| **Rechnung** | Abrechnung an den Kunden |
| **Lieferschein** | Lieferung an den Kunden; daraus wird die Rechnung |
| **Einkaufsrechnung** | Eingangsrechnung vom Lieferanten |
| **Bestellung, Anfrage, Einkaufslieferschein** | Einkaufsbelege an den Lieferanten |

Welche Belegart ein Datensatz ist, erkennt die Faktura am Datensatz selbst — egal, über welchen Link er geöffnet wird (Liste, Schnellsuche, CRM-Karte, Wiedervorlage, Belegkette). Eine Bestellung öffnet also immer als Bestellung mit Lieferant, eine Anfrage als Anfrage, ein Einkaufslieferschein als Einkaufslieferschein. Bei Ausgangsrechnungen bestimmt weiterhin der Link die Rechnungsart (Rechnung, Gutschrift, Storno). Eingangsrechnungen öffnen im Journal, wo sie gebucht werden. Die Rechte prüft das Backend bei jedem Lesen und Schreiben mit der echten Belegart des Datensatzes, nicht mit der aus dem Aufruf (kivitendo-Rechte, z. B. `vendor_invoice_edit`, `request_quotation_edit`, `purchase_delivery_order_edit`).

### Lieferscheine

- Anlegen mit eigenem Nummernkreis wie kivitendo (`sdonumber` Verkauf, `pdonumber` Einkauf), auch auf dem alten kivitendo-Schema (`order_type` statt `record_type`)
- Positionen bearbeiten, drucken (Vorlage `sales_delivery_order` bzw. `purchase_delivery_order`), versenden
- **Lieferschein → Rechnung**: Betrag aus den Positionen, Buchung ins Hauptbuch, Liefer- und Auftragsnummer werden übernommen, der Lieferschein gilt danach als erledigt; eine zweite Rechnung aus demselben Lieferschein wird verhindert. Aus einem Einkaufslieferschein entsteht eine Eingangsrechnung.
- Ein ausgelagerter Lieferschein (Lagerbewegung) lässt sich wie in kivitendo nicht mehr löschen

## Steuerzonen und Reverse Charge (§ 13b UStG)

Die Steuerzone eines Belegs (Inland, EU mit/ohne USt-ID, Außerhalb EU) bestimmt über die Buchungsgruppe des Artikels das Erlöskonto und damit den Steuersatz jeder Position. Sie wird beim Anlegen aus dem Kunden übernommen und lässt sich am Beleg unter „Zusätzliche Informationen“ umstellen — alle Positionen werden dann neu bewertet und die Buchungssätze neu geschrieben.

### Reverse Charge einschalten

**Firmenkonfiguration → Features → Verkauf → Reverse Charge (§ 13b UStG)**. Das Einschalten legt an bzw. aktiviert:

| Was | Wert |
|---|---|
| Steuerzone | „Reverse Charge § 13b UStG“ (am Ende der Steuerzonen-Liste) |
| Erlöskonto | SKR04 **4337** / SKR03 **8337** „Erlöse aus Leistungen, für die der Leistungsempfänger die Umsatzsteuer nach § 13b UStG schuldet“, Steuerschlüssel 0 → 0 % |
| Kontenzuordnung | je Buchungsgruppe: steuerpflichtige Gruppen auf das § 13b-Konto, ohnehin steuerfreie (Auslagen, durchlaufende Posten, Gebühren) behalten ihr Konto; Aufwandskonten wie Inland |
| UStVA | Bemessungsgrundlage auf dem § 13b-Konto → **Kennzahl 60** |
| Hinweissatz | frei formulierbar, Vorgabe: „Leistungen unterliegen gemäß § 13b UStG dem Reverse-Charge-Verfahren (Steuerschuldnerschaft des Leistungsempfängers).“ |

Alles passiert in der SQL-Funktion `reverse_charge_set()` (Upstall `crm`), idempotent bei jedem Speichern der Firmenkonfiguration — eine später angelegte Buchungsgruppe bekommt ihre Zuordnung beim nächsten Speichern. Ausschalten blendet die Zone nur aus (`obsolete`); bestehende Belege behalten sie und drucken den Satz weiter.

> Das Erlöskonto bekommt dabei die kivitendo-Verknüpfung `AR_amount:IC_sale:IC_income`, falls sie fehlt — ohne sie ließe sich das Konto weder in Buchungsgruppen verwenden noch in der UStVA einordnen.

### Anwenden

- **Dauerhaft je Kunde**: Kundenmaske → Steuerzone „Reverse Charge § 13b UStG“. Jeder neue Beleg dieses Kunden steht dann in der Zone.
- **Einzelner Beleg**: Steuerzone unter „Zusätzliche Informationen“ umstellen. Ein Hinweis unter dem Feld zeigt, dass der Beleg mit 0 % und Hinweissatz läuft.
- **Ausdruck**: Angebot, Auftrag, Rechnung (auch Storno), Gutschrift und Proformarechnung drucken den Satz unterhalb der Zahlungs-/Lieferbedingungen — Vorlagenvariable `<%if reverse_charge%><%reverse_charge_text%><%end if%>` in allen Master-Sets (RB/Standard, marei, mersiha).
- **E-Rechnung** (ZUGFeRD/XRechnung): 0 %-Zeilen erhalten die Steuerkategorie **AE** (statt Z) mit dem Hinweissatz als Befreiungsgrund und Code `VATEX-EU-AE`.
- **Eingangsseite** (§ 13b beim Einkauf) läuft unverändert über den kivitendo-Steuerschlüssel 94 (Kennzahlen 46/47/67).

## Belegfluss

```
Angebot → Auftrag → Rechnung
```

Jeder Beleg kann in den nächsten Typ umgewandelt werden. Positionen, Preise und Kundendaten werden übernommen.

### Rechnungsstatus am Auftrag

Zu jedem Auftrag ist sichtbar, ob daraus schon eine Rechnung gemacht wurde — an drei Stellen:

- **Auftragsliste** (`/auftrag`): Spalte „Rechnung“ mit der Rechnungsnummer (Klick öffnet die Rechnung), Filter „Ohne Rechnung / Mit Rechnung“ und in der Summenleiste die Anzahl und Bruttosumme der Aufträge ohne Rechnung — ein Klick darauf filtert. Export (PDF, Excel, CSV) führt die Spalte mit.
- **CRM-Belegkarte** (Reiter „Aufträge“ beim Kunden): Spalte „Rechnung“, sortierbar; der Chip „n ohne Rechnung“ blendet alle anderen Aufträge aus.
- **Auftragsmaske**: rechts neben dem Versandstatus — Rechnungsnummer, Datum, bezahlt/offen (Klick öffnet die Rechnung) oder „Noch keine Rechnung“ mit dem Knopf „Rechnung erstellen“.

Die Zuordnung ermittelt die SQL-Funktion `oe_invoice_status` (Upstall `crm`): über `record_links` (Auftrag → Rechnung, auch über einen Lieferschein) und für Altbelege über die Auftragsnummer im Rechnungskopf. Stornierte Rechnungen zählen nicht; gibt es nur solche, steht am Auftrag „Rechnung storniert“.

### Auftragsbestätigung *(KI-Erkennung: Schalter)*

Ein neuer Verkaufsauftrag ist zunächst **Auftragseingang**; bestätigt ist er, wenn der Kunde zugesagt hat. Der Stand steht in der Auftragsmaske zwischen Versand- und Rechnungsstatus — in Kompakt- und Vollansicht:

- **Noch nicht bestätigt** (orange) mit dem Knopf „Bestätigen“. Der Dialog fragt, **wie** der Kunde zugesagt hat (Telefon, E-Mail, WhatsApp, vor Ort, Unterschrift, Kundenportal, Sonstiges), wann, und nimmt eine Notiz auf. Der zuletzt gewählte Weg ist beim nächsten Mal vorbelegt, Strg+Enter bestätigt.
- **Bestätigt** (grün) mit Weg, Zeitpunkt und Mitarbeiter, dazu Notiz bzw. Zitat. Festgehalten wird auch der **Bruttobetrag zum Zeitpunkt der Bestätigung**: Wird der Auftrag danach teurer, steht „Seit der Bestätigung um … gestiegen“ dabei, mit dem Knopf „Neu bestätigen“. Über das Menü lässt sich die Bestätigung zurücknehmen; der Verlauf bleibt erhalten. Bei früher bestätigten Aufträgen ohne Angaben bietet die Maske „Angaben nachtragen“ an.
- **Zusage erkannt** (blau) bzw. **Absage erkannt** (rot): ein Vorschlag der KI mit dem wörtlichen Zitat des Kunden, Weg und Zeitpunkt, einer erkannten Bedingung („nicht über 1200 €“) und — bei Anrufen bzw. WhatsApp — dem Knopf „Anruf anhören“ bzw. „Chat öffnen“. „Bestätigen“ übernimmt den Vorschlag, „Verwerfen“ bzw. „Zur Kenntnis“ erledigt ihn. Unsicher erkannte Vorschläge sind gekennzeichnet.

**Auftragsliste** (`/auftrag`): Spalte „Bestätigt“ (Datum und Weg der Zusage, „prüfen“ bei einem Vorschlag, „offen“), Filter „Auftragseingang / Vorschlag / Bestätigt“ (auch per `?confirmed=suggested`) und in der Summenleiste „Zu prüfen“ mit der Zahl der Vorschläge — ein Klick filtert darauf. Export (PDF, Excel, CSV) führt die Spalte mit. In der **CRM-Belegkarte** zeigt das Symbol in der Spalte „Bestätigt“ einen offenen Vorschlag an; in der **Kontakthistorie** des Kunden stehen Bestätigungen, Rücknahmen und Vorschläge als eigene Einträge (siehe [CRM](crm.md#kontakthistorie)).

**Erkennung per KI** (Firmenkonfiguration → CRM → Auftragsbestätigung, Schalter `order_confirm_ai`: *Aus*, *Vorschlag* oder *Automatisch*): Hat ein Kunde einen offenen Auftragseingang (nicht geschlossen, noch nicht berechnet, höchstens `order_confirm_ai_days` Tage alt, Vorgabe 60), landen seine Kontakte in einer Warteschlange — WhatsApp-Nachrichten (Text, Beschriftung gedrückter Antwortknöpfe, Sprachnachrichten) und Anrufe per Trigger, E-Mails aus dem Posteingang über den Dienst `oserp-email-eingang` (nur diese Nachrichten werden ganz geladen, ohne sie als gelesen zu markieren). Der Dienst `oserp-auftragsbestaetigung` arbeitet sie sofort ab: Anrufaufnahmen und Sprachnachrichten schreibt der lokale Whisper-Dienst mit, dann prüft Claude den Text gegen die Aufträge, die beim Eingang der Nachricht offen waren — Zusage, Absage, Rückfrage oder nichts, welcher Auftrag gemeint ist, mit Zitat und Bedingung. Zögern („ich überlege noch“) und Rückfragen werden nicht als Zusage gewertet; bei WhatsApp fließen die Nachrichten davor als Zusammenhang ein. Festgehalten wird der Betrag zum Zeitpunkt der Kundennachricht. Im Modus *Automatisch* bestätigt der Dienst nur eindeutige Fälle per WhatsApp oder Telefon selbst (genau ein offener Auftrag, keine Bedingung, Sicherheit ab 0,9) — sie tragen dann „automatisch (KI)“ —, alles andere bleibt Vorschlag; E-Mails bestätigen nie selbst, weil ihr Absender fälschbar ist und eine gemeinsame Adresse zu mehreren Kunden gehören kann. Auftragsmaske und Kontakthistorie aktualisieren sich live, die Auftragsliste beim nächsten Laden bzw. Filtern. Erledigte Einträge der Warteschlange (mit Mailtexten und Mitschriften) löscht der Dienst nach 30 Tagen.

Voraussetzungen: Anthropic-API-Schlüssel, Whisper-Dienst `oserp-whisper`, für E-Mails der Dienst `oserp-email-eingang` mit eingerichtetem Postfach (nur Mails, die eingehen, während er läuft), Dienst `oserp-auftragsbestaetigung` (`install/install.sh --only auftragsbestaetigung`, Unit-Vorlage `install/oserp-auftragsbestaetigung.service`). Für Anrufe muss der Dienst das Aufnahmeverzeichnis von Asterisk lesen dürfen (der Installer gibt ihm die Gruppe `asterisk`). Modell: KI-Assistent „Auftragsbestätigung“ (Vorgabe Claude Sonnet 5). Anrufe dürfen nur mit Einwilligung des Anrufers aufgezeichnet werden; die Auswertung durch die KI gehört in die Datenschutzerklärung.

Technik: Tabellen `oe_confirmation_oserp` (Bestätigungen und Vorschläge, kivitendos `oe` bleibt unverändert bis auf `record_type`) und `oe_confirmation_queue_oserp` (Warteschlange), SQL-Funktionen `oe_confirmation_status`, `oe_confirm`, `oe_confirmation_dismiss`, `oe_confirmation_suggest`, `oe_confirmation_candidates` im Upstall `crm`; API `setOrderConfirmed` / `dismissOrderConfirmation`; Dienst `backend/cli/auftragsbestaetigung.php` (`--once` arbeitet alle fälligen Einträge ab und beendet sich; Anrufe, deren Aufnahme noch fehlt, bleiben für den nächsten Lauf liegen). Neue Verkaufsaufträge starten auf allen Wegen als Auftragseingang — angelegt, aus Angebot oder anderem Beleg umgewandelt, kopiert, aus einem Telefonat oder von Weroni erstellt.

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

## Artikelstamm

Artikel (Waren und Dienstleistungen) werden unter **Artikel** gepflegt. Die Maske speichert Änderungen an einem bestehenden Artikel automatisch; ein neuer Artikel entsteht erst mit **Anlegen**. Die Artikelnummer kommt aus dem Nummernkreis (Waren und Dienstleistungen getrennt) oder wird von Hand vergeben, vergebene Nummern meldet die Maske am Feld.

### Als neu verwenden

Der Knopf **Als neu verwenden** im Kopf eines Artikels öffnet die Neuanlage, vorbelegt mit allen Angaben des Artikels (kivitendo: „use as new“). Bis zum Klick auf **Anlegen** wird nichts gespeichert; **Abbrechen** führt zum Ausgangsartikel zurück. Die Beschreibung ist markiert, weil sie bei einer Variante meist als Erstes geändert wird. Die Adresse ist teilbar (`/artikel/neu?vorlage=<id>`).

Übernommen wird mehr, als die Maske zeigt:

- Stammsatz: Beschreibung, Langbeschreibung, Typ, Einheit, Verkaufspreis, Buchungsgruppe, Gewicht, dazu Listenpreis, Einkaufspreis, Warengruppe, Preisfaktor, Lager und Lagerplatz, Zolltarifnummer und die übrigen kivitendo-Felder
- Übersetzungen, benutzerdefinierte Variablen, Herstellerangaben (Hersteller/Modell), Preisgruppenpreise
- Shop-Erweiterung: Shop-Angaben, Versandangaben, Verkaufskanäle mit Aufschlägen und Texten sowie die Kanalbilder (werden beim Anlegen kopiert, die Karte zeigt vorher ihre Anzahl)

Nicht übernommen werden die Artikelnummer (wird neu vergeben), der Bestand, „Veraltet“, die EAN (ein Strichcode gehört zu genau einem Artikel) und der Stand beim Marktplatz (Kennung, Abgleich).

---

## Drucken

Belege können als PDF gedruckt werden. Die Druckvorlagen sind konfigurierbar unter **Einstellungen > Druckvorlagen**.

### Kartenterminal (SumUp Solo) *(extern, Schalter)*

Ist unter *Firmenkonfiguration → CRM → SumUp* ein SumUp Solo über die Cloud API gekoppelt und **SumUp aktivieren** gesetzt, erscheint in der Zahlungskarte jeder Rechnung eine Terminal-Leiste. Für Firmen ohne SumUp ändert sich nichts.

- **Betrag senden**: Das Eingabefeld ist mit dem offenen Betrag vorbelegt und kann überschrieben werden, z. B. für eine Anzahlung oder die Selbstbeteiligung bei einer Frontscheibe. **An Terminal senden** gleicht vorher offene Checkouts derselben Rechnung mit SumUp ab: Ist einer davon inzwischen bezahlt (Seite neu geladen, zweiter Reiter), wird nichts erneut kassiert, sondern die Zahlung übernommen und gedruckt; läuft noch einer, wird er am Reader abgebrochen und der neue Betrag gestartet. Checkouts anderer Belege bleiben unberührt.
- **Automatisch senden** *(Schalter, Standard ein)*: Wird eine Rechnung aus einem Angebot oder Auftrag erzeugt, geht der offene Betrag sofort an das Terminal. Beim Öffnen bestehender Rechnungen wird nie automatisch gesendet, bei bezahlten Rechnungen ohnehin nicht. SumUp gibt dem Reader 60 Sekunden, die Zahlung zu starten; danach gilt der Checkout als fehlgeschlagen. Der Solo sollte deshalb dauerhaft am Strom hängen und nicht in den Standby gehen.
- **Vollständig bezahlt**: Deckt die Summe aus Zahlungen und Kartenzahlungen den Rechnungsbetrag, nimmt das Betragsfeld nichts mehr an und der Knopf heißt **Zahlungsbeleg erneut drucken** – er druckt die Rechnung mit Kartenvermerk und Kundenbeleg noch einmal und meldet „Rechnung vollständig bezahlt".
- **Abgleich beim Öffnen**: Checkouts ohne geklärtes Ergebnis (Abfrage im Browser abgebrochen, Seite verlassen, lokal storniert) werden beim Öffnen der Rechnung mit SumUp abgeglichen. Eine dabei erkannte Zahlung erscheint sofort in der Zahlungskarte und wird gemeldet; der Beleg kommt über den Knopf. Checkouts, zu denen SumUp nach zehn Minuten keine Transaktion kennt, werden geschlossen.
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

## Wiedervorlage am Beleg

Die Glocke in der Aktionsleiste legt eine Wiedervorlage zum geöffneten Beleg an — sobald der Beleg gespeichert ist. Der Dialog zeigt die feste Verknüpfung („Verknüpft mit: Auftrag 4711 (Kunde)“), dazu Datum mit Schnellwahl, Betreff, Notiz und zugewiesene Mitarbeiter (ohne Auswahl der Ersteller).

- Ein **Badge** an der Glocke zählt die offenen Wiedervorlagen des Belegs; rot, sobald eine heute fällig oder überfällig ist
- Gibt es offene Wiedervorlagen, öffnet die Glocke eine **Liste**: Fälligkeit (rot überfällig, orange heute), Betreff, zugewiesene Mitarbeiter; Klick öffnet die Wiedervorlage zum Bearbeiten, der Haken markiert sie als **erledigt**, darunter **Neue Wiedervorlage**
- Die Liste zeigt alle offenen Wiedervorlagen des Belegs, nicht nur die eigenen — wer den Beleg öffnet, sieht, was zu ihm noch ansteht
- In der Wiedervorlagen-Übersicht führt der Verknüpfungs-Chip zurück in den Beleg — für alle Belegarten inklusive Lieferschein und Einkauf; reine Buchungen (kivitendo `ar/ap/gl_transaction`) öffnen im Journal
- Verschieben im Board oder Kalender der Übersicht lässt die Verknüpfung zum Beleg stehen
- Wird ein Angebot oder Auftrag **geschlossen**, erledigt kivitendo dessen Wiedervorlagen per Trigger (`follow_up_close_when_oe_closed`); die Glocke zeigt das beim nächsten Öffnen des Belegs

Gespeichert wird kivitendo-kompatibel in `follow_ups` / `notes` / `follow_up_links` mit dem kivitendo-`trans_type` des Belegs (`sales_quotation`, `sales_order`, `sales_delivery_order`, `sales_invoice`, `credit_note`, bei Lieferanten `request_quotation`, `purchase_order`, `purchase_delivery_order`, `purchase_invoice`). Die offenen Wiedervorlagen kommen mit dem Beleg in **einem** Aufruf (`getFakturaData` → `follow_ups`).

## Verlauf (Belegchronik)

Die Taste **Verlauf** in der Aktionsleiste zeigt, wer wann was am Beleg geändert hat; ihr Tooltip nennt die letzte Änderung („zuletzt geändert vor 5 Minuten von …“). Ändert jemand anderes den offenen Beleg, meldet ein Hinweis, wer es war.

- **Eine Zeitleiste** für alles: Änderungen am Kopf, an den Positionen und an den Fahrzeugdaten (LxCars), Versand (E-Mail, WhatsApp, Post, DHL), Belegkette („Rechnung 4711 daraus erstellt“, anklickbar), Wiedervorlagen und die kivitendo-Vorgeschichte (gespeichert, gedruckt, gebucht, gemailt)
- **Zusammengefasst und netto**: Faktura speichert jedes Feld einzeln — Änderungen derselben Person kurz hintereinander erscheinen als eine Bearbeitung, eine Kette A → B → C als A → C, eine kurz angelegte und wieder gelöschte Position gar nicht. Je Bearbeitung eine Zusammenfassung („2 Positionen hinzugefügt · Betrag 975,41 € → 1.189,39 €“)
- **Klartext**: Feldnamen wie in der Maske, IDs als Namen (auch gelöschte Mitarbeiter, Kunden, Artikel), Beträge, Daten, Rabatte formatiert; Wendepunkte als Etikett: angelegt, Auftrag bestätigt/Bestätigung zurückgenommen, erledigt/wieder geöffnet, Zahlung +x €, storniert, gedruckt
- **Herkunft**: Änderungen über OSERP tragen den angemeldeten Mitarbeiter (aus der Anmeldung, nicht aus dem Request), automatische als „Automatisch · wiederkehrende Rechnung/eBay-Import“, alles außerhalb von OSERP (kivitendo, Reparatur-SQL) als „kivitendo / extern“
- **Filter**: Kopf, Positionen, Status & Versand, nach Person, Volltext
- **Zeitreise**: ein Regler rechnet Kopf und Positionen auf den Stand vor einer Bearbeitung zurück und zeigt, was sich seitdem geändert hat (Felder damals → heute, Positionen hinzugekommen/entfernt/geändert) — ohne gespeicherte Schnappschüsse

Technik: Trigger `record_history_log()` (Upstall `crm`, für `oe_ext`/`ar_ext` Upstall `lxcars`) auf den kivitendo-Tabellen `oe`, `ar`, `ap`, `delivery_orders`, `orderitems`, `invoice`, `delivery_order_items` schreibt je Änderung den Diff der Spalten nach `record_history_oserp` — technische und abgeleitete Spalten (Zeiten, Margen, Positionsnummern, Kopien der Belegnummer) bleiben draußen, unveränderte Speicherungen erzeugen keine Zeile. Mitarbeiter und Quelle setzt jede API-Anfrage zentral (`DbhCompany::begin`, `oserp.employee_id`/`oserp.source`), Cron-Skripte über `DbhCompany::setActor`. Die Chronik kommt in einer Abfrage (`getFakturaHistory`, rund 20 ms).

## Suche

Die Auftragssuche ermöglicht das Finden von Belegen nach:
- Belegnummer
- Kundenname
- Datum / Zeitraum
- Status (offen/geschlossen, bei Rechnungen und Gutschriften auch storniert)
- Betrag (bei Gutschriften ohne Vorzeichen)

### Gutschriften und Stornos finden

- **Gutschriften** haben eine eigene Liste (Verkauf → Gutschriften) und einen eigenen Reiter in der Suche. Die Rechnungsliste und der Rechnungsreiter zeigen keine Gutschriften. Offen/erledigt rechnet mit dem Betrag ohne Vorzeichen, weil Gutschriften negativ gebucht sind.
- **Stornos** sind keine eigene Belegart, sondern ein Zustand der Rechnung. Rechnungs- und Gutschriftenliste haben den Filter **„Storniert“** (auch per `?status=storno`), der Statusreiter der Suche den Wert „Storniert“. Der Stornobeleg zeigt „Storno zu <Nr.>“, die Originalrechnung „Storniert durch <Nr.>“; ein Klick öffnet den Gegenbeleg. Beide gelten als erledigt, auch im Export.
- Technik: kivitendo-Schema — der Stornobeleg trägt `storno_id` = Originalrechnung, das Original nur `storno = true`. Der Gegenbeleg wird über den Primärschlüssel verbunden, die Unterabfrage läuft nur für stornierte Zeilen (rund 5 ms für die Liste).
