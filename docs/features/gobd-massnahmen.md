# GoBD-Maßnahmenplan

Konzept für die offenen Punkte aus der
[Verfahrensdokumentation der Belegablage](gobd-belege.md). Jede Maßnahme steht
mit Problem, Ziel, Weg, Aufwand — und mit dem, was sie **nicht** löst. Letzteres
ist der wichtigere Teil: eine Maßnahme, von der man mehr erwartet als sie kann,
ist schlimmer als keine.

> Stand 28.08.2026. Kein Rechtsrat — die Bewertung, ob eine Maßnahme im
> konkreten Fall genügt, gehört zum Steuerberater.

---

## Überblick

| | Maßnahme | Risiko heute | Aufwand | Art |
|---|---|---|---|---|
| **M1** | Nächtliche Belegprüfung | mittel | klein | Code |
| **M2** | Ausgangsrechnungen archivieren | ~~hoch~~ **erledigt 28.08.2026** | — | Code |
| **M3** | Hash-Kette gegen stilles Verändern | mittel | mittel | Code |
| **M4** | Altbestand ohne Bearbeiter | niedrig | klein | Code + Doku |
| **M5** | Scan-Richtlinie (ersetzendes Scannen) | **hoch**, falls Papier vernichtet wird | klein | Doku |
| **M6** | Rücksicherung nachweisen | mittel | klein | Betrieb |
| **M7** | Verfahrensdokumentation abzeichnen | mittel | klein | Prozess |
| **M8** | kivitendo-Altlasten in `_ext`-Tabellen | niedrig fachlich, hoch technisch | groß | Code |
| **M9** | DATEV-Export deckt nur einen Bruchteil ab | **hoch** | groß | Code |
| **M10** | Kein Z3-Paket für die Betriebsprüfung | **hoch** | groß | Code |

**Empfohlene Reihenfolge:** M5 und M7 zuerst (kosten fast nichts und schließen
die formalen Lücken), dann M1, dann M2. M3, M4, M6 laufen nebenher. M8 ist
Technikschuld ohne GoBD-Bezug — separat planen.

---

## M1 — Nächtliche Belegprüfung

**Problem.** Die Belegprüfung läuft nur, wenn jemand sie öffnet. Eine
ausgetauschte oder verschwundene Datei fällt erst dann auf — möglicherweise
Monate später, wenn niemand mehr weiß, was passiert ist.

**Ziel.** Eine Veränderung ist spätestens am nächsten Morgen bekannt.

**Weg.** Ein CLI-Skript nach dem Muster der vorhandenen
(`backend/cli/tafel-watchdog.php`, `weroni-monitor.php`), täglich per Cron:

1. `checkAccountingDocuments` über alle Mandanten laufen lassen
2. Ergebnis in `accounting_document_log` schreiben (passiert bereits)
3. **Nur bei Auffälligkeiten** melden — über die vorhandene Telegram-Anbindung,
   die für den Tafel-Watchdog schon eingerichtet ist
4. Einmal wöchentlich eine Bestätigung „alles unverändert", damit ein
   ausgefallener Lauf auffällt (schweigender Wächter = kaputter Wächter)

**Aufwand.** Klein. Ein Skript, ein Cron-Eintrag; die Prüflogik steht schon.

**Löst nicht.** Verhindert keine Veränderung, meldet sie nur. Und wer die
Datenbankzeile *mit* verändert, bleibt unsichtbar — dafür ist M3 da.

---

## M2 — Ausgangsrechnungen archivieren ✅ erledigt 28.08.2026

Umgesetzt: Beim Drucken, Mailen und Sammeldruck wird das Versandexemplar über
`ausgangsrechnungArchivieren()` abgelegt — mit Hash, Schreibschutz,
Aufbewahrungsfrist und Protokoll, verknüpft über die neue Spalte
`accounting_documents.ar_id`. Ein zweiter Druck derselben, unveränderten Rechnung
legt nichts Neues an; wird die Rechnung geändert und erneut gedruckt, entsteht
eine zweite Fassung. Die Kasse zeigt das Versandexemplar jetzt auch bei
Barzahlungen auf Ausgangsrechnungen.

Offen bleibt nur der **Altbestand**: alles, was vor dem 28.08.2026 verschickt
wurde, existiert als Versandexemplar nicht.

**Problem (Ausgangslage).** Beim Prüfen ist aufgefallen: **es gibt kein Archiv der versendeten
Rechnungen.** Nachgesehen wurde in drei Mandanten-Datenbanken — kivitendos
Dateiverwaltung (`files`) ist überall leer, unter `backend/data/<mandant>/` liegt
kein Rechnungsverzeichnis, und keine Stelle im Code schreibt eine Rechnungs-PDF
auf die Platte. Rechnungen werden bei jedem Aufruf neu aus Daten und Vorlage
erzeugt.

Das ist nicht automatisch falsch — eine Wiedergabe aus den Daten ist ein
anerkanntes Verfahren. Es trägt aber nur, solange **Daten und Vorlage
unverändert** sind. Ändert jemand die Rechnungsvorlage, sieht ein Nachdruck von
2024 anders aus als das, was der Kunde damals bekommen hat. Niemand merkt das,
und niemand kann es hinterher belegen.

**Ziel.** Zu jeder versendeten Ausgangsrechnung existiert eine unveränderliche
Kopie dessen, was der Kunde erhalten hat.

**Weg.** Die Belegablage ist bereits gebaut und passt:

1. Beim Versand (Druck, E-Mail, E-Rechnung) die erzeugte PDF durch
   `belegSchreiben()` ablegen — Schreibschutz, Hash, Aufbewahrungsfrist und
   Protokoll gelten damit automatisch
2. Verknüpfung zur Rechnung: neue Spalte `ar_id` in `accounting_documents`
   (eigene Tabelle, kein kivitendo-Eingriff)
3. Die Kasse zeigt den Beleg dann auch bei Barzahlungen auf Ausgangsrechnungen —
   die heute einzige verbliebene Lücke in der Beleganzeige
4. Für Altbestand: einmaliger Lauf, der die vorhandenen Rechnungen mit der
   **heutigen** Vorlage rendert und ablegt — ausdrücklich markiert als
   „nachträglich erzeugt, nicht das Versandexemplar". Ehrlicher als so zu tun,
   als sei es das Original.

**Aufwand.** Groß. Der Versandweg muss an drei Stellen angefasst werden, und der
Altbestand braucht eine eigene Entscheidung.

**Löst nicht.** Rechnungen, die vor der Umstellung verschickt wurden, sind als
Versandexemplar verloren. Punkt 4 ist ein Ersatz, kein Original.

---

## M3 — Hash-Kette

**Problem.** Der Hash liegt in derselben Datenbank wie der Beleg. Wer die Datei
austauscht **und** den Hash in der Zeile anpasst, hinterlässt keine Spur. Die
Belegprüfung würde „unverändert" melden.

**Ziel.** Auch das gemeinsame Verändern von Datei und Datenbankzeile wird
erkennbar.

**Weg.** Jeder neue Beleg bindet den Hash des vorhergehenden ein:

```
kette(n) = SHA256( kette(n-1) + hash(n) )
```

Wer einen alten Beleg ändert, müsste die gesamte Kette danach neu rechnen. Damit
das etwas nützt, muss der jeweils letzte Kettenwert **außerhalb** der Datenbank
liegen:

- monatlich in die Sicherung schreiben (eine Zeile Text), oder
- an den Steuerberater mailen, oder
- von einem Zeitstempeldienst signieren lassen

Die Belegprüfung rechnet die Kette mit und meldet den ersten Bruch.

**Aufwand.** Mittel. Eine Spalte, eine Berechnung beim Ablegen, eine Prüfschleife.

**Löst nicht.** Kein WORM-Speicher. Wer Root hat und die ausgelagerten
Kettenwerte ebenfalls erwischt, kommt weiterhin durch. Ein revisionssicheres
Archiv (WORM-Volume, S3 Object Lock) wäre die nächste Stufe — deutlich teurer und
erst sinnvoll, wenn M1 bis M3 stehen.

---

## M4 — Altbestand ohne Bearbeiter

**Problem.** Belege aus der Zeit vor dem 27.08.2026 tragen keine Mitarbeiter-ID.
Der Code las sie aus `$_SESSION`, was nie gefüllt war.

**Ziel.** Der Umfang ist bekannt und dokumentiert; wo sich der Bearbeiter
belastbar herleiten lässt, steht er drin — als Herleitung gekennzeichnet.

**Weg.**

1. **Stichtag dokumentieren.** Ab 27.08.2026 wird der Bearbeiter erfasst. Das
   gehört in die Verfahrensdokumentation, nicht in eine stillschweigende Lücke.
2. **Wo eindeutig, herleiten.** Hängt ein Beleg an einer Eingangsrechnung, kennt
   `ap.employee_id` denjenigen, der die Rechnung gebucht hat. Diese ID
   übernehmen — aber **im Protokoll als abgeleitet vermerken**. Wer die Rechnung
   gebucht hat, ist nicht zwingend, wer den Beleg eingescannt hat.
3. **Sonst NULL lassen.** Die Belegprüfung zählt diese Fälle als „ohne
   Bearbeiter". Eine erfundene Zuordnung wäre schlimmer als eine offene.

**Aufwand.** Klein.

**Löst nicht.** Rückwirkende Wahrheit gibt es nicht.

---

## M5 — Scan-Richtlinie

**Problem.** Werden Papierbelege nach dem Einscannen vernichtet, verlangen die
GoBD eine dokumentierte Verfahrensweise für das ersetzende Scannen. Die gibt es
nicht.

**Ziel.** Eine unterschriebene Richtlinie, die zum tatsächlichen Ablauf passt.

**Weg.** Ein Dokument, das folgende Fragen beantwortet:

- **Wer** darf scannen (namentlich oder nach Rolle)?
- **Womit** wird gescannt (Gerät, Auflösung, Farbe/Graustufen, Format)?
- **Wann** wird gescannt (sofort bei Eingang, wöchentlich)?
- **Wie** wird die Lesbarkeit kontrolliert und von wem?
- **Wann** darf das Papier vernichtet werden — und was bleibt im Original
  (Urkunden, Zollbelege, Verträge mit Beweisfunktion bleiben immer)?
- **Was** passiert bei einem Fehlscan?

**Aufwand.** Klein, aber es muss jemand entscheiden — Vorlage kann ich schreiben,
die Antworten nicht.

**Löst nicht.** Solange Papier aufbewahrt wird, ist die Richtlinie
Kür. Vernichtet ihr Papier, ist sie Pflicht.

---

## M6 — Rücksicherung nachweisen

**Problem.** Die Borg-Sicherung erfasst das Belegverzeichnis (geprüft: `/` wird
gesichert, `backend/data` steht in keiner Ausschlussliste). Ungeprüft ist, ob
sich daraus **wiederherstellen** lässt. Eine nie getestete Sicherung ist eine
Vermutung.

**Ziel.** Ein dokumentierter, datierter Rücksicherungstest.

**Weg.** Einmal jährlich, protokolliert:

1. Belegverzeichnis eines Mandanten in ein Testverzeichnis zurückholen
2. Datenbank desselben Standes in eine Testdatenbank einspielen
3. Belegprüfung darauf laufen lassen — sie prüft genau das Zusammenspiel von
   Datei und Datensatz und ist damit der passende Test
4. Ergebnis mit Datum in die Verfahrensdokumentation

**Aufwand.** Klein, aber wiederkehrend.

**Löst nicht.** Nichts an der Sicherung selbst — es macht sie nur nachweisbar.

---

## M7 — Verfahrensdokumentation abzeichnen

**Problem.** Die Verfahrensdokumentation existiert, ist aber nicht datiert,
versioniert oder freigegeben. Unabgezeichnet ist sie eine Notiz.

**Ziel.** Datierte, freigegebene Fassung; Änderungen nachvollziehbar.

**Weg.** Kopfblock in [gobd-belege.md](gobd-belege.md): Version, Stand,
Verantwortlicher, Freigabedatum, Änderungsverzeichnis. Bei jeder Änderung am
Verfahren fortschreiben — die alten Fassungen bleiben (die Datei liegt in Git,
die Historie ist damit vorhanden).

**Aufwand.** Klein.

**Löst nicht.** Ein abgezeichnetes Dokument, das nicht zum gelebten Ablauf passt,
ist schlimmer als keins. Vor der Unterschrift mit dem Steuerberater durchgehen.

---

## M8 — kivitendo-Altlasten

**Kein GoBD-Thema**, aber beim Prüfen der Schema-Änderungen aufgefallen und
inhaltlich verwandt: ein Mandantendump muss in kivitendo re-importierbar bleiben.

**Meine Änderungen sind sauber.** Geändert wurden nur
`accounting_documents` (neue Spalte `retention_until`) und die neue Tabelle
`accounting_document_log`. Beide sind OS-ERP-eigen — sie kommen weder im
kivitendo-Basisschema noch im kivitendo-Quelltext vor. Keine kivitendo-Tabelle
wurde angefasst.

**Vorhandene Altlasten** im CRM-Schema, älter als diese Arbeit:

| Zeile | Anweisung |
|---|---|
| 1512 | `ALTER TABLE bank_transactions ADD COLUMN match_status text` |
| 1513 | `ALTER TABLE bank_transactions ADD COLUMN remote_iban varchar(40)` |
| 1836 | `ALTER TABLE printers ADD COLUMN hide_factura boolean` |

**Weg.** Für jede eine Begleittabelle mit Fremdschlüssel auf den
kivitendo-Primärschlüssel:

```sql
CREATE TABLE bank_transactions_ext (
    bank_transaction_id INTEGER PRIMARY KEY REFERENCES bank_transactions(id) ON DELETE CASCADE,
    match_status        TEXT DEFAULT 'unmatched',
    remote_iban         VARCHAR(40)
);
CREATE TABLE printers_ext (
    printer_id   INTEGER PRIMARY KEY REFERENCES printers(id) ON DELETE CASCADE,
    hide_factura BOOLEAN DEFAULT false
);
```

Migration in vier Schritten: Tabelle anlegen → Daten übernehmen → Code umstellen
→ alte Spalten entfernen. Schritt drei ist der teure: **`match_status` kommt an
100 Stellen im Code vor** und steckt in fast jeder Banking-Abfrage. Das ist kein
Nebenbei-Umbau.

**Empfehlung.** Nicht mit den GoBD-Maßnahmen vermischen. Als eigenes Vorhaben
planen, mit `printers_ext` anfangen (eine Spalte, wenige Fundstellen) und
`bank_transactions_ext` erst danach angehen.

**Löst nicht.** Die Re-Importierbarkeit ist auch danach nicht bewiesen, nur
wahrscheinlicher. Ein echter Testimport in eine kivitendo-Instanz wäre der
Nachweis — und ein Nachmittag Arbeit, der sich lohnt.

---

## Verwandte Seiten

- [Belegablage — Verfahrensdokumentation](gobd-belege.md)
- [Buchhaltung — das Konzept](buchhaltung.md)

---

## M9 — DATEV-Export deckt nur einen Bruchteil ab

**Problem.** Der DATEV-Export liest ausschließlich `accounting_bookings` mit
Status `approved` — die Warteschlange der KI-Belegerkennung für
Eingangsrechnungen. **Ausgangsrechnungen, Kassenbuchungen, Bankbuchungen und
alles, was direkt ins Hauptbuch gebucht wurde, sind nicht enthalten.**

Gemessen am 28.08.2026:

| Mandant | im DATEV-Export | Vorgänge im Hauptbuch |
|---|---|---|
| handel | **0** | 6.896 |
| autoprofis | **0** | 15.127 |
| ap_dev | 42 | 2.581 |

In beiden Produktivmandanten ist der Export also **leer**. Wer ihn dem
Steuerberater schickt, schickt nichts.

**Ziel.** Ein Export, der das Hauptbuch abbildet — alle Buchungen des Zeitraums
mit ihren Belegen.

**Weg.** Die Quelle wechseln: statt `accounting_bookings` das Hauptbuch
(`acc_trans` mit `ar`/`ap`/`gl`) je Vorgang zu Buchungssätzen verdichten. Die
Belegzuordnung steht dann bereits: `ap_id`, `ar_id` und `cash_gl_documents`.
Format, Belegliste, Prüfsummen und LIESMICH bleiben, wie sie sind — die sind
gut. Es ist die Datenquelle, die falsch ist, nicht das Paket.

**Aufwand.** Groß. Die Verdichtung von `acc_trans` auf DATEV-Buchungssätze
(Konto/Gegenkonto, Steuerschlüssel, Buchungstext) ist die eigentliche Arbeit.

**Löst nicht.** Ersetzt kein Z3-Paket (siehe M10). DATEV ist das Format des
Steuerberaters, nicht das der Finanzverwaltung.

---

## M10 — Z3-Paket für die Betriebsprüfung

**Problem.** Bei einer Außenprüfung kann das Finanzamt nach § 147 Abs. 6 AO die
**Datenträgerüberlassung (Z3)** verlangen. Erwartet wird der
Beschreibungsstandard für die Datenträgerüberlassung — `index.xml` plus
CSV-Dateien plus DTD, einlesbar in die Prüfsoftware IDEA. OS-ERP kann das nicht.
Es kann DATEV-Buchungsstapel, und das ist etwas anderes.

**Ziel.** Ein Knopf, der ein IDEA-lesbares Paket für einen Zeitraum erzeugt.

**Weg.**

1. Tabellen festlegen, die überlassen werden (Buchungen, Konten, Debitoren,
   Kreditoren, Rechnungen, Kassenbuch) — der Umfang gehört mit dem
   Steuerberater abgestimmt, nicht von der Software entschieden
2. Je Tabelle eine CSV
3. `index.xml` nach dem Beschreibungsstandard erzeugen (Feldnamen, Typen,
   Längen, Trennzeichen)
4. `gdpdu-01-08-2002.dtd` beilegen
5. Belege wie im DATEV-Paket dazu, plus Belegliste mit Prüfsummen

**Aufwand.** Groß, aber gut abgrenzbar. Das Format ist öffentlich dokumentiert
und ändert sich seit Jahren nicht.

**Löst nicht.** Die Frage nach **DSFinV-K** (§ 146a AO, Kassensicherungs-
verordnung). Die gilt für *elektronische Aufzeichnungssysteme* mit technischer
Sicherheitseinrichtung. Ob das Kassenbuch in OS-ERP darunter fällt, hängt davon
ab, wie es benutzt wird — werden damit Barverkäufe unmittelbar erfasst, oder
werden Rechnungen geschrieben und deren Barzahlung nachgetragen? Das ist eine
Frage an den Steuerberater und **muss vor der nächsten Kassen-Nachschau
(§ 146b AO) geklärt sein**, denn die kommt unangekündigt.
