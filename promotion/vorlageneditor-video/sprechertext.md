# Sprechertext: Vorlageneditor

Jede Szene ist ein Audio-Abschnitt. Die Aufnahme zeigt währenddessen die
beschriebene Aktion. Pausen zwischen den Szenen entstehen automatisch.

## 01 Intro

So Freunde der grünen Frucht, heute zeige ich euch den neuen Vorlageneditor
in OpensourceERP. Bisher musste man Druckvorlagen als LaTeX-Dateien auf dem
Server bearbeiten. Ab jetzt gestaltet ihr Rechnung, Angebot, Auftrag und
Lieferschein direkt im System, mit der Maus, per Drag and Drop.

## 02 Öffnen

Der Editor ist nur für Administratoren da. Ihr findet ihn im Systemmenü
hinter dem Firmenlogo unter „Vorlageneditor", oder in der Firmenkonfiguration
bei den Druckvorlagen über den Knopf „Vorlageneditor öffnen".

## 03 Aufbau

Oben wählt ihr den Vorlagensatz und die Belegart. Die Symbole an den
Belegarten zeigen den Zustand: Haken heißt gestaltet, Stift heißt
handgeschriebene Vorlage, Plus heißt noch nichts da. Links liegen die
Bausteine, die Abschnitte des Fließbereichs, alle Felder des Belegs und die
Bilder des Vorlagensatzes. In der Mitte seht ihr die Seite im Maßstab mit
Linealen in Millimetern. Rechts stehen die Eigenschaften dessen, was gerade
ausgewählt ist.

## 04 Startvorlage

Für eine Belegart ohne Design bietet der Editor Startvorlagen an: DIN 5008
Form B als klassischer Geschäftsbrief, eine moderne Variante mit farbigem
Kopfband, eine kompakte Variante und ein leeres Blatt. Ich nehme DIN 5008.
Sofort liegt alles da: Logo, Absenderzeile, Anschrift im Fenster, Infobox,
Fußzeile mit Firmen- und Bankdaten, und im Fließbereich Betreff,
Anschreiben, Positionstabelle und Summen.

## 05 Firmendaten

Das Schöne daran: Die Firmendaten kommen aus der Firmenkonfiguration.
Firma, Straße, Ort, Steuernummer, Umsatzsteuer-ID, E-Mail und
Bankverbindung stehen schon in der Fußzeile. Und wenn ihr einen Text
bearbeitet, bietet der Editor genau diese Werte als Vorschläge an. Ein Klick,
und der Wert steht im Text.

## 06 Verschieben

Jeder Baustein lässt sich mit der Maus verschieben. Beim Ziehen rasten die
Kanten an den Seitenrändern, an Nachbarbausteinen und an den DIN-Marken
ein, rote Hilfslinien zeigen das an. Mit den acht Griffen ändert ihr die
Größe. Die Pfeiltasten verschieben millimetergenau, mit Umschalt in
Fünferschritten. Und wenn mal etwas daneben geht: Strg Z macht es rückgängig.

## 07 Felder

Jedes Feld des Belegs zeigt den Wert des neuesten Belegs als Beispiel, so
seht ihr sofort, was später gedruckt wird. Ein Klick auf ein Feld fügt es in
den gewählten Text ein, oder ihr zieht es direkt auf die Seite. Felder, die
beim Beleg leer sind, werden gelb markiert, damit nichts untergeht.

## 08 Infobox

Die Infobox rechts neben der Anschrift besteht aus Zeilen mit Beschriftung
und Wert. Zeilen lassen sich sortieren, ergänzen oder löschen. Und das
Auge sagt: Diese Zeile nur drucken, wenn der Wert gefüllt ist. Die
Auftragsnummer erscheint also nur, wenn es wirklich eine gibt.

## 09 Fließbereich

Der gestrichelte Rahmen ist der Fließbereich. Hier läuft der eigentliche Text:
Betreff, Anschreiben, die Positionstabelle, die Summen und der Schlusstext.
Die Abschnitte könnt ihr mit der Maus in eine andere Reihenfolge ziehen.
Und wenn ein Text lieber frei auf der Seite stehen soll, etwa die
Bemerkungen zum Beleg, macht ihr ihn mit einem Klick zum freien Baustein
und legt ihn ab, wo ihr wollt. Die Kanten des Fließbereichs zieht ihr direkt
auf der Seite, oben, unten, links und rechts.

## 10 Tabelle

Die Positionstabelle hat Spalten für Position, Artikelnummer, Bezeichnung,
Menge, Einzelpreis, Rabatt und Gesamtpreis. Ihr sortiert sie per Griff,
blendet sie mit dem Häkchen aus, ändert Beschriftung und Breite. Dazu
Kopfhintergrund, Zebrastreifen, Linien, Langtext und Seriennummer unter der
Bezeichnung. Lange Tabellen brechen sauber über mehrere Seiten um, der Kopf
wiederholt sich automatisch.

## 11 Folgeseiten

Jeder Baustein gilt für alle Seiten, nur für die erste oder nur für die
Folgeseiten. Mit dem Schalter „2 plus" schaut ihr euch die Folgeseiten an:
Dort steht die laufende Kopfzeile mit Belegnummer und Seitenzahl, und der
Fließbereich beginnt weiter oben.

## 12 Vorschau

Die PDF-Vorschau rendert das Design mit einem echten Beleg, über genau
denselben LaTeX-Weg wie der spätere Druck. Ihr könnt den Beleg wechseln,
und mit „automatisch" aktualisiert sich die Vorschau nach jeder Änderung von
selbst.

## 13 Speichern

Beim Speichern entsteht aus dem Design die fertige Vorlagendatei im
Vorlagensatz, bei der Rechnung ist das invoice.tex. Eine handgeschriebene
Vorlage wird vorher gesichert, man kann sie jederzeit zurückholen. Jede
Speicherung ist eine Version, die letzten zwanzig bleiben erhalten.

## 14 Übertragen

Und zum Schluss das Beste: Mit „Grundlayout auf andere Belegarten
übertragen" bekommen Angebot, Auftrag und Lieferschein mit einem Klick
dasselbe Logo, dieselbe Anschrift, dieselbe Fußzeile. Nur der Fließbereich
bleibt je Belegart eigen. So ist der ganze Satz in einer Viertelstunde
fertig.

## 15 Schluss

Das war der Vorlageneditor. Probiert es aus, spielt mit den Startvorlagen,
und schreibt mir in die Kommentare, was ihr noch braucht. Bis zum nächsten
Mal!
