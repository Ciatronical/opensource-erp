# Shop: Widerruf (§ 356a BGB)

Übernommen aus `kivitendo_bridge/dev/widerruf.md` (Stufe E der Ablösung). Der
erste Teil hält fest, was in OpensourceERP daraus geworden ist; der zweite ist
die rechtliche Vorlage, eingeholt am 18.06.2026, einen Tag vor Fristablauf.

## Stand am 16.09.2026

Gebaut und betriebsbereit. In der Bridge fehlten dafür zwei Dinge außerhalb des
Codes: vier Konstanten je Instanz und eine Inhaltsseite mit dem Formular. Die
Konstanten sind entfallen, das Formular bleibt Sache der Webseite.

| Teil | Ort |
| --- | --- |
| Fachlogik | `backend/api/shop/lib/withdrawal.php` |
| Öffentliche Aktion | `submitWiderruf` in `backend/api/shop/public/actions.php` |
| Nachweis | Tabelle `withdrawals_hugoshop` |
| Mails | `shopSendWithdrawalMails()` in `lib/mail.php`, Vorlagen unter `backend/api/shop/templates/` |
| Admin-Panel | Ansicht „Widerrufe", Aktionen `getShopWithdrawals` und `setShopWithdrawalProcessed` |
| Einstellung | `shop_withdrawal_mail_to` — ohne sie geht die Betreibermail nicht hinaus |
| Formular | Sache der Webseite; bei sonic24 das Shortcode `widerruf-form.html` im Theme, bedient von `js/shopwindow/widerruf.js` |

Der Ablauf in `submitWiderruf()`:

1. **Honeypot** — ist das für Menschen unsichtbare Feld `website` gefüllt,
   meldet die Aktion Erfolg und hält nichts fest.
2. **Serverseitige Prüfung** von Name und E-Mail-Adresse. Die Bestellnummer
   wird **nicht** geprüft: der Widerruf ist auch mit falscher oder fehlender
   Nummer wirksam.
3. **Eine Abfrage** schreibt die Zeile und sucht dabei die Rechnung zur
   Bestellnummer — bei angemeldeten Kunden nur unter deren eigenen. Das erspart
   dem Betreiber das Suchen.
4. **Zeitstempel** ist `itime` der Zeile, also die Zeit des Datenbankservers.
   Derselbe Wert steht in beiden Mails.
5. **Erst schreiben, dann versenden.** Scheitert eine Mail, ist der Vorgang
   trotzdem festgehalten und der Fehler steht im Protokoll. Die
   Eingangsbestätigung geht nur hinaus, wenn der Kunde eine Adresse angegeben
   hat; die Betreffzeilen stehen in `lib/mail.php`.

### Gegenüber der Bridge geändert

- **Tabelle statt Logdatei.** Die Bridge hängte eine JSON-Zeile an eine Datei;
  der Nachweis hing daran, dass niemand sie anfasst. Die rechtliche Vorlage
  nennt beide Wege und schlägt eine Tabelle vor. Auswertungen wie „alle
  Widerrufe im Quartal" sind jetzt eine Abfrage.
- **Sichtbar im Admin-Panel**, mit Kennzeichen „bearbeitet". Dafür hatte die
  Bridge nichts.
- **Zuordnung zur Rechnung**, wo die Bestellnummer passt.
- **Keine Konstanten je Instanz**, nur die Einstellung
  `shop_withdrawal_mail_to`.
- **Serverzeit** wie bisher. Die Vorlage empfiehlt, in UTC zu speichern und für
  die Anzeige umzurechnen. Protokoll und Mails bleiben so konsistent — darauf
  kam es an —, aber nach einem Zeitzonenwechsel sind ältere Einträge nur mit
  Kenntnis der damaligen Zone einzuordnen.

### Offen

- **Das Formular bringt das Webseiten-Paket nicht mit.** Ein Widget
  `shop-withdrawal` in der Shop-UI würde es wie Warenkorb und Anmeldung
  mitliefern; heute pflegt jede Instanz ihr eigenes. Bei sonic24 läuft es über
  das alte `js/shopwindow/widerruf.js`, das mit der Antwortform von
  OpensourceERP zurechtkommt, weil `fetchRequest` `success` auswertet.
- **Append-only nur der Absicht nach:** wer in der Datenbank schreiben darf,
  kann die Zeilen ändern. Für Revisionssicherheit im engeren Sinn fehlt eine
  Verkettung oder ein Ziel außer Haus.

---

# Rechtliche Vorlage (Stand 18.06.2026)

Die Frist ist morgen (19.06.2026), insofern kommt die Frage zur richtigen Zeit. Rechtsgrundlage ist der neue § 356a BGB.

Die Eingangsbestätigung ist rechtlich klar geregelt – sie ist eine reine **Eingangs**bestätigung, keine Wirksamkeits- oder Anerkennungsbestätigung. Das ist der wichtigste Punkt für die Formulierung.

**Pflichtinhalte der E-Mail:**

- **Inhalt der Widerrufserklärung** – also die Daten, die der Kunde im Formular eingegeben hat: Name, Vertrags-/Bestellnummer und die Widerrufserklärung selbst. Zulässig als Pflichtangaben sind nur Name, Vertragsidentifikation und ein elektronisches Kommunikationsmittel für die Eingangsbestätigung.
- **Datum und genaue Uhrzeit des Eingangs.** Die E-Mail-Bestätigung muss revisionssicher sein und Datum sowie Uhrzeit des Widerrufs enthalten – einfache Bestätigungs-E-Mails ohne Zeitstempel genügen nicht.
- **Versand auf dauerhaftem Datenträger** – E-Mail erfüllt das. Sobald der Verbraucher die Widerrufserklärung über den Bestätigungsbutton abgesendet hat, muss der Händler ihm unverzüglich und auf einem dauerhaften Datenträger eine Eingangsbestätigung übermitteln, in der Regel als automatisierte E-Mail.
- **Versand unverzüglich und automatisiert** nach dem Klick auf „Widerruf bestätigen".

**Was die E-Mail NICHT tun darf:**

Die Formulierung muss neutral bleiben und darf nicht den Eindruck erwecken, der Widerruf sei bereits geprüft oder anerkannt. Sie bestätigt nur den Eingang, nicht die Wirksamkeit des Widerrufs. Also keine Formulierungen wie „Ihr Widerruf wurde akzeptiert/bearbeitet/genehmigt".

**Konkreter Vorschlag für den Mailtext:**

> Betreff: Eingangsbestätigung Ihres Widerrufs – Bestellung [Bestellnr.]
>
> Sehr geehrte/r [Name],
>
> wir bestätigen den Eingang Ihrer Widerrufserklärung. Diese ist bei uns eingegangen am **[TT.MM.JJJJ] um [HH:MM:SS] Uhr**.
>
> Inhalt Ihrer Erklärung:
> – Name: [Name]
> – Vertrag/Bestellung: [Bestell-/Vertragsnummer]
> – Widerrufene Leistung: [ggf. einzelne Position]
> – Erklärung: Hiermit widerrufe ich den oben genannten Vertrag.
>
> Diese E-Mail bestätigt ausschließlich den Eingang Ihrer Erklärung. Über den weiteren Ablauf der Rückabwicklung informieren wir Sie gesondert.
>
> Mit freundlichen Grüßen
> [Firma / Impressum]

Ein technischer Hinweis zur Umsetzung in HugoShop: Der Zeitstempel muss serverseitig zum Zeitpunkt des Form-Submits gesetzt und protokolliert werden (nicht clientseitig), damit er revisionssicher ist – idealerweise wird derselbe Wert geloggt und in die Mail eingesetzt. Und: Der Widerruf gilt als fristgerecht, wenn der Kunde ihn vor Fristablauf abgesendet hat (§ 356a Abs. 5 BGB) – auf den Zugang bei dir kommt es nicht an. Maßgeblich für den Kunden ist also sein Absendezeitpunkt, deine Eingangsbestätigung dokumentiert nur deinen Empfang.

Der Hintergrund: Die E-Mail an den Kunden enthält ja Datum und Uhrzeit. Aber eine E-Mail ist kein Beweismittel, das *du* in der Hand hast – sie liegt beim Kunden. Wenn es später Streit gibt („ich habe rechtzeitig widerrufen" / „die Erklärung kam nie an"), brauchst du auf deiner Seite einen eigenen, verlässlichen Nachweis, wann die Erklärung eingegangen ist. Genau das ist mit „revisionssicher" gemeint: ein Protokoll, das im Nachhinein nicht still verändert werden kann.

Konkret heißt das in der Umsetzung dreierlei:

**Zeitstempel serverseitig erzeugen.** Beim Verarbeiten des Form-Submits einmal die Serverzeit nehmen (z. B. `date('Y-m-d H:i:s')` bzw. der entsprechende Aufruf in deiner Backend-Sprache), nicht aus dem Browser des Kunden übernehmen. Clientseitige Zeit ist manipulierbar und unzuverlässig (falsche Systemuhr, Zeitzone). Am besten in UTC speichern und für die Anzeige in lokale Zeit umrechnen.

**Diesen einen Wert überall verwenden.** Derselbe Zeitstempel geht in (a) das Protokoll/die Datenbank, (b) die Eingangsbestätigung an den Kunden und (c) die interne Mail an dich. So sind alle drei konsistent – sonst hast du im Streitfall drei leicht abweichende Zeiten.

**Persistent und unveränderbar ablegen.** Also in eine Datenbanktabelle oder ein Log schreiben, zusammen mit den Formulardaten (Name, Bestellnummer, Erklärung). „Revisionssicher" bedeutet idealerweise append-only: Einträge werden nur ergänzt, nie überschrieben oder gelöscht. Eine simple, saubere Variante ist ein Logfile, in das pro Widerruf eine Zeile geschrieben wird, plus optional die generierte Bestätigungsmail als Kopie (z. B. als gespeicherte `.eml` oder PDF).

Für deinen Lean-Server-Ansatz reicht das gut aus: eine Tabelle `widerrufe` (oder ein dediziertes Logfile) mit Zeitstempel, Bestelldaten und dem Erklärungstext. Kein zusätzliches System nötig. Wichtig ist nur, dass der Eintrag beim Submit *atomar* geschrieben wird, bevor die Mails rausgehen – damit nie der Fall eintritt, dass der Kunde eine Bestätigung bekommt, du aber keinen Protokolleintrag hast.
