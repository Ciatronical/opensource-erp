---
title: Briefe und Postversand
summary: Briefe an Kunden und Lieferanten schreiben und mit Belegen elektronisch per Post versenden – Hybridbrief über PIN AG oder LetterXpress
group: core
category: Kommunikation
order: 45
status: stable
since: 2026-10-11
external: true
keywords: [Brief, Briefe, Hybridbrief, Postversand, PIN AG, LetterXpress, Einschreiben, Textbausteine]
---

# Briefe und Postversand

Unter **Kontakt → Briefe** entstehen Briefe an Kunden und Lieferanten wie in kivitendo – mit Briefnummer aus dem Nummernkreis, Ansprechperson, Betreff, Anrede und formatiertem Text. Jeder Brief lässt sich drucken, per E-Mail senden oder **elektronisch per Post** einliefern: Das ERP übergibt das PDF an einen Briefdienstleister, der druckt, kuvertiert, frankiert und zustellt (Hybridbrief). Derselbe Postweg steht für **Rechnungen, Angebote, Aufträge und Lieferscheine** in der Faktura bereit.

Die Briefe liegen in kivitendos Tabelle `letter` und bleiben dort kompatibel; Textbausteine und Einlieferungen liegen in `letter_templates` und `postal_dispatches`.

## Brief schreiben

- **Empfänger**: Kunde oder Lieferant – aus der Kontaktkarte („Brief"-Knopf in der Kontakthistorie), aus dem Menü mit dem aktuell gewählten Kunden oder per Suche. Die Adresse erscheint so, wie sie gedruckt wird; eine unvollständige Adresse wird sofort angezeigt.
- **Ansprechperson**: wählbar, erscheint im Adressfeld. Die **Anrede** wird daraus vorgeschlagen (Geschlecht der Ansprechperson, bei natürlichen Personen aus der Kundenanrede, in der Sprache des Kunden) und bleibt änderbar; ein Klick auf den Zauberstab holt den Vorschlag zurück.
- **Datum, Ihr Zeichen, Unterschrift** (ein oder zwei Unterzeichner), interne Bemerkung (wird nicht gedruckt).
- **Text** im Editor mit Absätzen, Fett, Kursiv, Listen. **Bausteine** (Name, Kundennummer, Vor-/Nachname der Ansprechperson, Datum, Briefnummer, Bearbeiter, Firma) werden per Klick an der Cursorstelle eingefügt und erscheinen als Pille; beim Drucken steht der Wert. Im Betreff und in Textbausteinen stehen sie als `<%name%>`, `<%customernumber%>`, `<%cp_name%>`, `<%date%>`, `<%letternumber%>`, `<%employee_name%>`, `<%company%>`.
- **Automatisches Speichern**: Der Brief entsteht, sobald Betreff oder Text eingegeben sind, und wird danach 1,5 Sekunden nach jeder Änderung gesichert – die Kopfzeile zeigt den Stand (Empfänger wählen / entsteht mit dem ersten Text / gespeichert um …). Ein leerer Brief wird nie angelegt.
- **Textbausteine**: Betreff und Text als Baustein speichern, auswählen, aktualisieren, löschen – direkt im Editor.
- **Kopie**: jeder Brief dient als Vorlage für einen neuen Brief (auch aus der Liste).
- Beim Verlassen und vor E-Mail- oder Postversand wird eine ausstehende Speicherung still ausgeführt.
- **Unterschrift**: gedruckt wird der Name des gewählten Mitarbeiters (optional ein zweiter); ein Unterschriftsbild gibt es nicht. Die E-Mail-Signatur aus der Benutzerverwaltung wird nicht verwendet.
- **Rechte**: Der Menüpunkt und die Funktionen setzen das kivitendo-Recht `sales_letter_edit` (Verkaufsbriefe) oder `purchase_letter_edit` (Einkaufsbriefe) der Benutzergruppe voraus.

## Versand

| Weg | Was passiert |
|-----|--------------|
| **PDF** | Vorschau (auch ungespeichert, mit Aufdruck VORSCHAU) oder fertiger Brief zum Drucken |
| **E-Mail** | PDF als Anhang, Empfänger aus Ansprechperson/Kunde vorbelegt, Eintrag im E-Mail-Journal mit Verknüpfung zum Brief |
| **Post** | Einlieferung beim Briefdienstleister oder „Selbst drucken" (Versand wird vermerkt, PDF öffnet sich) |

Der **Versanddialog** zeigt vor dem Einliefern Seitenzahl, Empfängeradresse, Inland/Ausland, den Anbieter und – bei LetterXpress – **Porto und Guthaben**. Farbdruck, beidseitiger Druck und **Einschreiben** (Einwurf oder Übergabe, nur Inland) sind je Sendung wählbar. Das eingelieferte PDF bleibt als **Versandexemplar** gespeichert; bei LetterXpress lässt sich ein Auftrag in den ersten 15 Minuten stornieren, Status und Sendungsverfolgung werden auf Knopfdruck oder per Cron aktualisiert.

Das **Versandprotokoll** im Brief zeigt jede E-Mail und jede Einlieferung mit Zeitpunkt, Bearbeiter, Status, Porto und Sendungsnummer.

## Belege per Post

In der Faktura hat die Aktionsleiste neben E-Mail und WhatsApp den Knopf **Post** (Briefkasten-Symbol, mit Zähler bisheriger Einlieferungen). Der Dialog ist derselbe wie beim Brief; der Beleg wird mit der normalen Druckvorlage gerendert. Einlieferungen erscheinen im **Versandstatus** des Belegs (Chip „Post" mit Status eingeliefert / gedruckt und versendet / selbst versendet / storniert) und in der Belegliste.

## Kontakthistorie

Briefe erscheinen in der Kontakthistorie des Kunden bzw. Lieferanten chronologisch neben Anrufen, WhatsApp und E-Mails – mit Nummer, Betreff und den genutzten Versandwegen; nicht versendete Briefe sind als solche markiert. Ein eigener Filter zeigt nur Briefe, ein Klick öffnet den Brief.

## Einrichtung

Firmenkonfiguration → CRM → **Briefversand (Hybridbrief)**:

| Einstellung | Bedeutung |
|-------------|-----------|
| Briefdienstleister | *Kein Anbieter* (nur selbst drucken), *PIN AG / eBrief* oder *LetterXpress* |
| PIN AG: Übertragungsweg, Server, Port, Benutzername, Passwort, Einlieferungsordner | Zugangsdaten aus dem Vertrag (eCollect/eBrief). PDFs werden per SFTP oder FTPS abgelegt; Druckoptionen sind beim Anbieter je Ordner hinterlegt. Ein zweiter Ordner für Einwurf-Einschreiben ist optional |
| LetterXpress: Benutzername, API-Schlüssel, Betriebsmodus | API-Schlüssel aus dem Kundenbereich (Funktionen → Zugangsdaten → LXP API). Im **Testmodus** landen Aufträge in der Postbox und werden nicht gedruckt |
| Farbdruck / beidseitig als Vorgabe | Vorbelegung des Versanddialogs |
| Verbindung testen | Prüft Anmeldung und – je nach Anbieter – Guthaben oder Einlieferungsordner |

Die **Briefnummer** zählt in der Firmenkonfiguration → Nummernkreise unter „Brief“ (kivitendos `defaults.letternumber`); der nächste Brief erhält Zähler + 1, der Wert ist frei setzbar. Die Briefvorlage ist `letter.tex` im Vorlagensatz der Firma; fehlt sie oder liegt sie noch in kivitendos Template-Toolkit-Syntax, greift die Master-Vorlage (`templates-default/RB/letter.tex`). Das Adressfeld steht an der DIN-5008-Fensterposition, damit der Dienstleister die Anschrift liest. Rechte: `sales_letter_edit` / `purchase_letter_edit` (kivitendo), Belege per Post mit dem jeweiligen Belegrecht.

## Automatik

Status und Sendungsverfolgung der LetterXpress-Aufträge aktualisiert ein Cronjob:

```
0 * * * *  cd /home/work/opensource-erp && php backend/cli/postal-status.php >> log/postal-status.log 2>&1
```
