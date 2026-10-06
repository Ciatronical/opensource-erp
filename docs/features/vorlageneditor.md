---
title: Vorlageneditor
summary: Druckvorlagen für Rechnung, Angebot, Auftrag und Lieferschein per Drag & Drop gestalten — mit Firmendaten, echten Belegwerten und PDF-Vorschau
group: core
category: System
order: 92
status: stable
since: 2026-10-03
---

# Vorlageneditor — Druckvorlagen per Drag & Drop

Druckvorlagen mussten bisher als LaTeX-Dateien auf dem Server bearbeitet werden. Der Vorlageneditor unter **Systemmenü → Vorlageneditor** (auch über **Firmenkonfiguration → Druckvorlagen**) gestaltet dieselben Vorlagen im Browser: Bausteine werden mit der Maus auf eine maßstabsgetreue A4-Seite gezogen, der Fließbereich mit Betreff, Positionstabelle und Summen wird sortiert, und eine PDF-Vorschau zeigt das Ergebnis mit einem echten Beleg. Beim Speichern entsteht daraus die gewohnte `.tex`-Datei im Vorlagensatz — der Druck läuft unverändert.

Der Editor steht nur **Systemadministratoren** offen.

## Aufbau

| Bereich | Inhalt |
|---------|--------|
| Links | Bausteine (Logo, Absenderzeile, Anschrift, Lieferadresse, Infobox, Freitext, Kopf Folgeseiten, Fußzeile, Seitenzahl, Linie, Fläche, GiroCode), Abschnitte des Fließbereichs, Felder des Belegs mit Beispielwerten, Bilder des Vorlagensatzes |
| Mitte | Die Seite im Maßstab mit Linealen, Raster und Hilfslinien. Bausteine verschieben, an acht Griffen vergrößern, der Fließbereich an seinen Kanten. Umschalten zwischen erster Seite und Folgeseiten |
| Rechts | Eigenschaften der Auswahl: Seite (Ränder, Schrift, Farben, Falz- und Lochmarken, Briefpapier), Baustein oder Abschnitt |

Beim Ziehen rasten Kanten an Seitenrändern, Nachbarbausteinen und den DIN-5008-Marken ein; mit gedrückter **Alt**-Taste wird frei platziert. Pfeiltasten verschieben die Auswahl um einen Millimeter (mit Umschalt um fünf), **Entf** löscht, **Strg+D** dupliziert, **Strg+Z/Y** macht rückgängig und wiederholt, **Strg+S** speichert.

## Startvorlagen

Für eine Belegart ohne Design bietet der Editor Startvorlagen an: **DIN 5008 Form B** (klassischer Geschäftsbrief), **Modern** (farbiges Kopfband), **Kompakt** (mehr Platz für Positionen) und **Leer**. Alternativ wird das Design einer anderen Belegart übernommen. Betreff, Infobox-Zeilen, Einleitung und Spalten passen sich der Belegart an — ein Lieferschein bekommt keine Preisspalten und keinen Summenblock.

## Firmendaten und Felder

Absenderzeile und Fußzeile werden aus der Firmenkonfiguration vorbelegt (Firma, Adresse, Steuernummer, USt-IdNr., E-Mail, Bankverbindung). Im Texteditor stehen dieselben Werte als Vorschläge bereit, dazu ein Feld-Menü mit allen Feldern des Belegs (`<%invnumber%>`, `<%name%>`, `<%employee_name%>` …) samt Beispielwert aus dem neuesten Beleg. Texte kennen **fett**, *kursiv* sowie `{page}` und `{pages}` für Seitenzahlen; eine Bedingung („nur anzeigen, wenn Feld gefüllt") blendet Bausteine und Abschnitte aus, wenn der Beleg das Feld nicht hat.

Das Firmenlogo aus der Firmenkonfiguration lässt sich mit einem Klick in den Vorlagensatz übernehmen; weitere Bilder (PNG, JPG, PDF) werden hochgeladen. Ein Hintergrundbild in A4 dient als gescanntes Briefpapier.

## Fließbereich und freie Bausteine

Der gestrichelte Rahmen ist der Fließbereich mit Betreff, Anschreiben, Positionstabelle, Summen und Schlusstext. Die Abschnitte lassen sich mit der Maus in eine andere Reihenfolge ziehen, die Kanten des Bereichs direkt auf der Seite. Ein Textabschnitt wird zum frei platzierbaren Baustein, indem man ihn aus dem Fließbereich heraus auf die Seite zieht oder im Eigenschaften-Panel „Als freien Baustein auf die Seite legen" wählt, etwa die Bemerkungen zum Beleg neben der Anschrift; umgekehrt holt „In den Fließbereich übernehmen" einen Textbaustein zurück in den laufenden Text.

## Positionstabelle und Summen

Die Spalten der Positionstabelle (Position, Artikelnummer, Bezeichnung, Menge, Einzelpreis, Rabatt, Gesamt) werden per Griff sortiert, ein- und ausgeblendet, beschriftet und in der Breite festgelegt; die Bezeichnung füllt den restlichen Platz. Dazu kommen Kopfhintergrund, Zebrastreifen, Linien, Langtext und Seriennummer unter der Bezeichnung. Lange Tabellen brechen über Seiten um, der Kopf wiederholt sich. Der Summenblock zeigt Netto, eine Zeile je Steuersatz und den Gesamtbetrag.

## Vorschau und Speichern

Die **PDF-Vorschau** rendert das aktuelle, auch ungespeicherte Design mit einem wählbaren echten Beleg über denselben LaTeX-Weg wie der Druck. Browser ohne eingebaute PDF-Anzeige (Tablets) bekommen die Seiten als Bilder; der Schalter in der Vorschauleiste wechselt jederzeit zwischen beiden Darstellungen. Schlägt die Übersetzung fehl, zeigt der Editor das LaTeX-Protokoll und die erzeugte Vorlage.

**Speichern** legt eine neue Version des Designs an (die letzten 20 bleiben zum Zurückholen erhalten) und schreibt die Vorlagendatei, zum Beispiel `invoice.tex`, in den Vorlagensatz. Liegt dort eine handgeschriebene Vorlage, wird sie vorher als `.tex.bak-<Zeitstempel>` gesichert; „Design entfernen" holt sie zurück. Mastervorlagen unter `templates-default/` sind schreibgeschützt — dafür legt die Firmenkonfiguration eine Kopie an.

Mit **„Grundlayout auf andere Belegarten übertragen"** werden Seite und Bausteine (Logo, Anschrift, Infobox, Fußzeile) auf weitere Belegarten kopiert; deren Fließbereich bleibt erhalten. Belegspezifisches wird dabei übersetzt: Aus „Rechnung <%invnumber%>" in der laufenden Kopfzeile wird beim Angebot „Angebot <%quonumber%>", und die Infobox bekommt die Zeilen der Zielbelegart. So entsteht aus einer gestalteten Rechnung in einem Schritt der passende Satz aus Angebot, Auftrag und Lieferschein.

## Vorhandene Vorlagen bearbeiten (Quelltext)

Der Schalter **Quelltext** in der Werkzeugleiste öffnet die vorhandenen Vorlagendateien des Satzes: Belegvorlagen (`invoice.tex`, `sales_quotation.tex` …), Firmendaten und Konten (`ident.tex`, `euro_account.tex`), Einstellungen (`insettings.tex`, `inheaders.tex`) und Sprachdateien. Der Editor hebt LaTeX-Befehle, Kommentare und die Platzhalter `<%feld%>` hervor; Strg+S speichert, der alte Stand wird als `.tex.bak-<Zeitstempel>` gesichert (die letzten zehn bleiben).

Rechts stehen die in der Datei definierten Werte (`\newcommand{\firma}{…}`) als Formular — Firma, Straße, Telefon, IBAN lassen sich so ändern, ohne LaTeX zu lesen; Sonderzeichen werden automatisch escaped. Weicht ein Wert von der Firmenkonfiguration ab, bietet ein Chip den hinterlegten Wert an. Die PDF-Vorschau rendert die bearbeitete Datei mit einem echten Beleg, auch bei Hilfsdateien wie `insettings.tex`: dafür wird der Satz in eine Arbeitskopie übernommen, in der nur die geänderte Datei ersetzt ist.

Bei einer Belegart ohne Design führt der Knopf **Quelltext bearbeiten** direkt zur handgeschriebenen Vorlage. Vom Editor erzeugte Dateien tragen eine Warnung: Änderungen dort gehen beim nächsten Speichern des Designs verloren.

## Hinweis bei LxCars

Ist die Erweiterung LxCars aktiv, druckt das System Aufträge über die Kfz-Vorlage `kfz_order.tex`. Der Editor weist darauf hin und bietet beim Speichern an, auch diese Datei durch das Design zu ersetzen.
