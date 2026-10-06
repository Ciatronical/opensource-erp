# Belegsuche und „Magisch Buchen"

Eingangsbelege kommen auf vielen Wegen ins Haus: als Mail-Anhang, als Foto per
WhatsApp, als Datei auf dem Server oder zum Herunterladen im Portal eines
Lieferanten. Die Belegsuche holt sie von dort automatisch, legt sie
revisionssicher ab, liest sie aus und stellt sie als Buchungsvorschlag bereit.
Gebucht wird erst, wenn in der Buchhaltung auf **Magisch Buchen** geklickt wird.

## Ablauf

1. **Suche** (täglich per Cron oder per Klick auf „Jetzt suchen"): Jede aktive
   Quelle wird abgefragt. Neue Dateien (PDF, XML, Bilder) werden durch die
   vorhandene Belegpipeline geschickt: Ablage als unveränderbare Datei mit
   Aufbewahrungsfrist und Protokoll (`accounting_documents`), Erkennung per
   E-Rechnung oder KI (Lieferant, Beträge, Steuer, Kontovorschlag),
   Buchungsvorschlag in `accounting_bookings` mit Status `pending`.
2. **Vorschläge** (Buchhaltung › Kachel „Magisch Buchen"): Alle offenen
   Vorschläge mit Quelle, Lieferant, Rechnungsnummer, Betrag, Konto und
   Sicherheit. Was ohne Nacharbeit buchbar ist, ist vorbelegt; alles andere ist
   abgewählt und nennt den Grund (Lieferant unklar, Konto fehlt, Rechnungsnummer
   fehlt, bereits gebucht).
3. **Buchen**: „N Belege buchen" bucht die markierten Vorschläge als echte
   Eingangsrechnungen (`ap` + Hauptbuch). Was scheitert, bleibt liegen und wird
   mit Fehlertext angezeigt.

Wer bucht, sieht zu jedem Vorschlag die Herkunft: Quelle mit Name (z. B.
„Portal Intercars", „Firmenpostfach", „WhatsApp-Eingang"), Fundzeitpunkt und bei
Mails Absender und Betreff. Der Beleg selbst öffnet sich über das PDF-Symbol.

Was keine Rechnung ist (Logos, Signaturbilder, Werbung), wird erkannt und ohne
Vorschlag abgelegt. Duplikate (gleicher Dateiinhalt) werden übersprungen. Beides
ist unter „übersprungen" einsehbar.

## Quellen einrichten

Einstellungen › CRM-Vorgaben › Abschnitt **Belegsuche**.

- **Belegsuche täglich ausführen** und **Uhrzeit**: ab dieser Uhrzeit läuft die
  Suche einmal pro Tag (siehe Cron-Job).
- **Quellen**: Beim ersten Öffnen werden angelegt: das eingerichtete
  Firmenpostfach (IMAP), der WhatsApp-Eingang und der Ordner `belege/` im
  Projektverzeichnis. Jede Quelle hat „Verbindung testen", „Jetzt suchen",
  Bearbeiten und Löschen.

| Typ | Was passiert | Hinweise |
|---|---|---|
| E-Mail-Postfach (IMAP) | Alle Mails seit dem letzten Lauf (3 Tage Puffer, erster Lauf 30 Tage) werden gelesen, Anhänge PDF/XML immer, Bilder ab 150 KB. Mails bleiben ungelesen. | Beliebig viele Postfächer; eigene Zugangsdaten oder das Firmenpostfach. Eingebettete Bilder (Signaturen) werden übersprungen. |
| WhatsApp-Eingang | Empfangene Dokumente und Fotos aus `whatsapp_messages` (letzte 60 Tage). | Bilder ab 40 KB, Belegfotos vom Handy liegen weit darüber. |
| Ordner auf dem Server | Alle Belegdateien im Ordner; erledigte Dateien wandern nach `verarbeitet/JJJJ-MM/`, fehlgeschlagene nach `fehler/`. | Pfad absolut oder mit `~` (Home des Projektbesitzers), z. B. `~/opensource-erp/belege`. Optional mit Unterordnern. |
| Lieferanten-Portal (Login) | Ein einmal aufgezeichneter Ablauf (Login › Rechnungsliste › Download) wird unsichtbar abgespielt, alle Rechnungen der Liste werden geladen. | Siehe unten „Portal einmal vormachen". |

Passwörter werden verschlüsselt gespeichert (AES-256-GCM, `backend/api/lib/secrets.php`)
und nie an den Browser zurückgegeben.

## Portal einmal vormachen

Für Portale gibt es keinen allgemeinen Abruf, jedes sieht anders aus. Deshalb
zeichnen Sie den Weg einmal auf, das System wiederholt ihn.

Der Einstieg ist der Lieferant selbst: In der Lieferantenkarte (CRM) gibt es
den Reiter **Belegabruf**. Dort steht, ob für diesen Lieferanten ein Portal
eingerichtet ist, wann es zuletzt lief und wie viele Belege es geliefert hat.
„Portal einrichten" legt die Quelle gleich mit dem richtigen Lieferanten an.
Dieselben Portale erscheinen auch gesammelt unter Einstellungen › CRM-Vorgaben ›
Belegsuche.

1. Am Lieferanten (Reiter „Belegabruf") auf **Portal einrichten**: Portal-
   Adresse, Benutzername, Passwort. Speichern.
2. In **Google Chrome** das Portal öffnen, **F12** drücken, Reiter **Recorder**
   wählen (falls versteckt: unter dem Menü »).
3. **Neue Aufnahme** starten. Dann wie gewohnt einloggen, zur Rechnungsliste
   gehen und **eine** Rechnung als PDF herunterladen.
4. Aufnahme beenden, über das Export-Symbol als **JSON** speichern.
5. Die JSON-Datei in der Quelle hochladen. Eingetippte Zugangsdaten werden
   durch Platzhalter ersetzt, das Passwort landet nicht in der Aufnahme.
6. „Verbindung testen" spielt den Ablauf einmal unsichtbar ab und meldet, wie
   viele Dateien geladen wurden.

Beim täglichen Lauf wird der Klick, der in der Aufnahme den Download ausgelöst
hat, auf alle gleichartigen Elemente der Seite ausgeweitet (jede Zeile der
Rechnungsliste). Bereits bekannte Dateien werden übersprungen, es kommen also
nur neue Rechnungen. Die Option „Alle Rechnungen der Liste laden" lässt sich
je Quelle abschalten.

**Grenzen:** Zwei-Faktor-Codes und Captchas kann die Wiedergabe nicht lösen.
Ändert der Anbieter seine Seite, scheitert ein Schritt und der Lauf meldet
„Wiedergabe gescheitert bei Schritt N, Aufnahme bitte erneuern". Dann neu
aufnehmen und hochladen.

**Technik:** `backend/portal-runner/replay.mjs` (Puppeteer + `@puppeteer/replay`,
eigener Chrome unter `backend/portal-runner/.cache`). Einrichtung:

```
cd backend/portal-runner && npm install && npx puppeteer browsers install chrome
```

Der Runner läuft als Kindprozess von PHP (Webserver oder Cron), Zugangsdaten
gehen per Umgebungsvariable hinein, nicht über die Kommandozeile.

## Cron-Job

Die Uhrzeit steht in den Einstellungen, der Cron prüft alle zehn Minuten, ob
sie erreicht ist, und läuft je Mandant einmal pro Tag:

```
0,10,20,30,40,50 * * * * cd /home/work/opensource-erp && php backend/cli/belegsuche.php >> log/belegsuche.log 2>&1
```

Manuell: `php backend/cli/belegsuche.php --force` (alle Mandanten sofort),
`--client <id>` (nur ein Mandant).

## Tabellen

- `beleg_quellen`: Quellen (Typ, Name, Config als JSON, verschlüsseltes Passwort,
  Lieferant, letzter Lauf/Status).
- `beleg_quellen_log`: je verarbeitetem Element ein Eintrag (Mail-Anhang, Datei,
  WhatsApp-Medium, Portal-Download) mit Ergebnis `imported`, `duplicate`,
  `not_invoice`, `skipped`, `error`. Eindeutig je Quelle und Kennung, darum wird
  nichts zweimal verarbeitet.
- `accounting_documents.source_id / origin / origin_info`: Herkunft eines
  Belegs (Quelle, Kennung, Absender/Betreff).

## API (backend/api/accounting/beleg_quellen.php)

`getBelegQuellen`, `saveBelegQuelle`, `deleteBelegQuelle`, `testBelegQuelle`,
`saveBelegsucheSettings`, `savePortalRecording`, `runBelegSuche`,
`getMagicProposals`, `magicBook`. Der Import selbst läuft über
`_iv_importDocument()` in `invoice_upload.php`, demselben Kern wie der manuelle
Upload.
