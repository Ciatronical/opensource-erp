# Buchhaltung — das Konzept

Diese Seite erklärt, wie die Buchhaltung in OS-ERP gedacht ist: was wohin läuft,
warum es nur einen Menüpunkt gibt, und wo Sie welche Zahl finden. Sie ist als
Handbuch geschrieben — von oben nach unten lesbar, aber auch zum Nachschlagen.

---

## 1. Die Idee in einem Satz

> Jeder Geldvorgang im Betrieb hinterlässt zwei Spuren: einen **Beleg** (was war
> es?) und eine **Zahlung** (wann floss das Geld?). Die Buchhaltung bringt beide
> zusammen und schreibt das Ergebnis auf **Konten** fort.

Alles Weitere auf dieser Seite ist nur die Ausführung dieses einen Satzes.

---

## 2. Der Weg eines Belegs

<figure>
<svg viewBox="0 0 860 250" xmlns="http://www.w3.org/2000/svg" role="img"
     aria-label="Ablauf von Beleg und Zahlung bis zum Konto">
  <style>
    .box  { fill: none; stroke: currentColor; stroke-width: 1.5; }
    .accent { stroke: #1976d2; }
    .money  { stroke: #2e7d32; }
    .lbl  { fill: currentColor; font: 13px sans-serif; }
    .sub  { fill: currentColor; font: 11px sans-serif; opacity: .65; }
    .arr  { stroke: currentColor; stroke-width: 1.5; fill: none; marker-end: url(#tip); }
  </style>
  <defs>
    <marker id="tip" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto">
      <path d="M0,0 L10,5 L0,10 z" fill="currentColor"/>
    </marker>
  </defs>
  <rect class="box accent" x="10"  y="20"  width="150" height="60" rx="6"/>
  <text class="lbl" x="26" y="45">Beleg kommt an</text>
  <text class="sub" x="26" y="63">PDF, Foto, E-Mail</text>
  <rect class="box accent" x="210" y="20"  width="150" height="60" rx="6"/>
  <text class="lbl" x="226" y="45">Erkennung</text>
  <text class="sub" x="226" y="63">Lieferant, Betrag, USt</text>
  <rect class="box accent" x="410" y="20"  width="150" height="60" rx="6"/>
  <text class="lbl" x="426" y="45">Eingangsrechnung</text>
  <text class="sub" x="426" y="63">offener Posten</text>
  <rect class="box money" x="10"  y="160" width="150" height="60" rx="6"/>
  <text class="lbl" x="26" y="185">Bankumsatz</text>
  <text class="sub" x="26" y="203">FinTS-Abruf</text>
  <rect class="box money" x="210" y="160" width="150" height="60" rx="6"/>
  <text class="lbl" x="226" y="185">Zuordnung</text>
  <text class="sub" x="226" y="203">Umsatz trifft Rechnung</text>
  <rect class="box" x="620" y="90" width="220" height="70" rx="6"/>
  <text class="lbl" x="640" y="118">Buchung auf Konten</text>
  <text class="sub" x="640" y="138">Soll und Haben, unveränderlich</text>
  <path class="arr" d="M160,50 H205"/>
  <path class="arr" d="M360,50 H405"/>
  <path class="arr" d="M160,190 H205"/>
  <path class="arr" d="M560,50 C600,50 600,110 615,118"/>
  <path class="arr" d="M360,190 C560,190 590,145 615,133"/>
  <path class="arr" d="M420,80 V160 H365" stroke-dasharray="4 3"/>
  <text class="sub" x="430" y="130">Rechnung wird bezahlt</text>
</svg>
<figcaption>Oben der fachliche Weg (was war es), unten der Geldweg (wann floss es). Erst wenn beide zusammentreffen, entsteht eine vollständige Buchung.</figcaption>
</figure>

Praktisch heißt das:

1. **Beleg abgeben.** Ziehen Sie die Datei irgendwo auf das Cockpit — es gibt
   keinen eigenen Menüpunkt „hochladen". Die Erkennung liest Lieferant, Datum,
   Betrag und Umsatzsteuer heraus.
2. **Prüfen.** Sichere Vorschläge dürfen durchlaufen, unklare kommen in den
   Durchlauf und werden einzeln bestätigt.
3. **Zahlung zuordnen.** Die Bankumsätze kommen per FinTS herein und werden mit
   den offenen Rechnungen abgeglichen.
4. **Fertig.** Ab hier ist der Vorgang eine Buchung und wird nicht mehr
   angefasst — Korrekturen laufen über Stornobuchungen, nicht über Ändern.

---

## 3. Die Konten — wo die Zahlen landen

Ein **Konto** ist ein Behälter, in dem gleichartige Vorgänge gesammelt werden.
Der **Kontenrahmen** (SKR03 oder SKR04) legt fest, welche es gibt.
Vier Arten reichen zum Verständnis:

<figure>
<svg viewBox="0 0 860 200" xmlns="http://www.w3.org/2000/svg" role="img"
     aria-label="Die vier Kontenarten">
  <style>
    .k    { fill: none; stroke-width: 2; }
    .kt   { fill: currentColor; font: 600 14px sans-serif; }
    .ks   { fill: currentColor; font: 11.5px sans-serif; opacity: .7; }
    .kn   { fill: currentColor; font: 11px sans-serif; opacity: .5; }
  </style>
  <rect class="k" x="10"  y="20" width="195" height="150" rx="8" stroke="#1976d2"/>
  <text class="kt" x="28" y="50">Aktiva</text>
  <text class="ks" x="28" y="74">Was der Betrieb hat</text>
  <text class="ks" x="28" y="94">Bank, Kasse, Forderungen,</text>
  <text class="ks" x="28" y="112">Maschinen, Warenlager</text>
  <text class="kn" x="28" y="150">SKR03: 0000–1999</text>
  <rect class="k" x="225" y="20" width="195" height="150" rx="8" stroke="#6a1b9a"/>
  <text class="kt" x="243" y="50">Passiva</text>
  <text class="ks" x="243" y="74">Wem es gehört</text>
  <text class="ks" x="243" y="94">Eigenkapital, Darlehen,</text>
  <text class="ks" x="243" y="112">Verbindlichkeiten, Steuern</text>
  <text class="kn" x="243" y="150">SKR03: 0800–3999</text>
  <rect class="k" x="440" y="20" width="195" height="150" rx="8" stroke="#c62828"/>
  <text class="kt" x="458" y="50">Aufwand</text>
  <text class="ks" x="458" y="74">Was Geld kostet</text>
  <text class="ks" x="458" y="94">Wareneinkauf, Löhne,</text>
  <text class="ks" x="458" y="112">Miete, Kfz, Werbung</text>
  <text class="kn" x="458" y="150">SKR03: 4000–7999</text>
  <rect class="k" x="655" y="20" width="195" height="150" rx="8" stroke="#2e7d32"/>
  <text class="kt" x="673" y="50">Erlöse</text>
  <text class="ks" x="673" y="74">Was Geld bringt</text>
  <text class="ks" x="673" y="94">Umsatz aus Werkstatt,</text>
  <text class="ks" x="673" y="112">Handel, Vermietung</text>
  <text class="kn" x="673" y="150">SKR03: 8000–8999</text>
</svg>
<figcaption>Aktiva und Passiva bilden die Bilanz (Stichtagsbetrachtung), Aufwand und Erlöse die Gewinnermittlung (Zeitraumbetrachtung).</figcaption>
</figure>

Den vollständigen Kontenrahmen sehen und bearbeiten Sie unter **Konten**
(im Cockpit im Fachbereich, oder über die Befehlspalette mit `Strg+K`).

---

## 4. Soll und Haben — warum jede Buchung zwei Seiten hat

Keine Buchung steht allein. Geld, das irgendwo hinfließt, kommt woanders her.
Das ist die **doppelte Buchführung**: jeder Vorgang berührt mindestens zwei
Konten, und die Summen beider Seiten sind immer gleich.

<figure>
<svg viewBox="0 0 860 250" xmlns="http://www.w3.org/2000/svg" role="img"
     aria-label="T-Konten am Beispiel einer bezahlten Tankrechnung">
  <style>
    .t    { stroke: currentColor; stroke-width: 1.5; fill: none; }
    .tt   { fill: currentColor; font: 600 13px sans-serif; }
    .tv   { fill: currentColor; font: 12px sans-serif; }
    .th   { fill: currentColor; font: 11px sans-serif; opacity: .6; }
    .note { fill: currentColor; font: 12px sans-serif; opacity: .75; }
  </style>
  <defs>
    <marker id="tip2" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto">
      <path d="M0,0 L10,5 L0,10 z" fill="currentColor"/>
    </marker>
  </defs>
  <text class="tt" x="40" y="30">6530 Kfz-Betriebskosten</text>
  <path class="t" d="M40,42 H300 M170,42 V150"/>
  <text class="th" x="70"  y="60">Soll</text>
  <text class="th" x="215" y="60">Haben</text>
  <text class="tv" x="60"  y="86">84,03</text>
  <text class="tt" x="440" y="30">1200 Bank</text>
  <path class="t" d="M440,42 H700 M570,42 V150"/>
  <text class="th" x="470" y="60">Soll</text>
  <text class="th" x="615" y="60">Haben</text>
  <text class="tv" x="600" y="86">100,00</text>
  <text class="tt" x="40" y="190">1576 Vorsteuer 19 %</text>
  <path class="t" d="M40,202 H300 M170,202 V240"/>
  <text class="tv" x="60" y="228">15,97</text>
  <path class="t" d="M310,86 H430" marker-end="url(#tip2)"/>
  <path class="t" d="M560,120 C480,120 400,180 305,215" marker-end="url(#tip2)"/>
  <text class="note" x="545" y="215">Summe Soll 100,00 = Summe Haben 100,00</text>
</svg>
<figcaption>Eine Tankrechnung über 100,00 € brutto: der Aufwand und die Vorsteuer stehen im Soll, das Bankkonto im Haben. Beide Seiten ergeben denselben Betrag.</figcaption>
</figure>

Im **einfachen Modus** sehen Sie davon nichts — dort heißt es schlicht
„Tankrechnung, 100,00 €, bezahlt". Im **fachlichen Modus** stehen Soll, Haben
und Steuerschlüssel offen da. Beide Modi arbeiten auf denselben Daten; der
Umschalter oben rechts ändert nur die Darstellung, niemals die Buchung.

---

## 5. Drei Wege, wie Geld ins System kommt

<figure>
<svg viewBox="0 0 860 230" xmlns="http://www.w3.org/2000/svg" role="img"
     aria-label="Bank, Kasse und offene Posten münden in dieselben Konten">
  <style>
    .w  { fill: none; stroke-width: 2; }
    .wt { fill: currentColor; font: 600 13px sans-serif; }
    .ws { fill: currentColor; font: 11.5px sans-serif; opacity: .7; }
    .wl { stroke: currentColor; stroke-width: 1.5; fill: none; marker-end: url(#tip3); }
  </style>
  <defs>
    <marker id="tip3" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto">
      <path d="M0,0 L10,5 L0,10 z" fill="currentColor"/>
    </marker>
  </defs>
  <rect class="w" x="20" y="15"  width="230" height="55" rx="6" stroke="#1976d2"/>
  <text class="wt" x="38" y="38">Bank</text>
  <text class="ws" x="38" y="57">Umsätze per FinTS, kein Import</text>
  <rect class="w" x="20" y="85"  width="230" height="55" rx="6" stroke="#ef6c00"/>
  <text class="wt" x="38" y="108">Kasse</text>
  <text class="ws" x="38" y="127">Bareinnahmen und -ausgaben</text>
  <rect class="w" x="20" y="155" width="230" height="55" rx="6" stroke="#6a1b9a"/>
  <text class="wt" x="38" y="178">Offene Posten</text>
  <text class="ws" x="38" y="197">noch nicht bezahlte Rechnungen</text>
  <rect class="w" x="360" y="70" width="200" height="85" rx="6" stroke="currentColor"/>
  <text class="wt" x="378" y="100">Hauptbuch</text>
  <text class="ws" x="378" y="120">alle Buchungen,</text>
  <text class="ws" x="378" y="138">nach Konten sortiert</text>
  <rect class="w" x="655" y="70" width="185" height="85" rx="6" stroke="#2e7d32"/>
  <text class="wt" x="673" y="100">Berichte</text>
  <text class="ws" x="673" y="120">Saldenliste, Kontoblatt,</text>
  <text class="ws" x="673" y="138">Umsatzsteuer, DATEV</text>
  <path class="wl" d="M250,42  C310,42  320,95  355,105"/>
  <path class="wl" d="M250,112 H355"/>
  <path class="wl" d="M250,182 C310,182 320,130 355,120"/>
  <path class="wl" d="M560,112 H650"/>
</svg>
<figcaption>Bank, Kasse und offene Posten sind nur Eingänge. Gebucht wird immer ins selbe Hauptbuch — deshalb passen die Berichte zusammen.</figcaption>
</figure>

**Bank** und **Kasse** haben im Cockpit je eine eigene Kachel und je eine eigene
Seite. Das ist kein Schönheitsfehler, sondern Absicht: der Bankbestand wird von
der Bank bestätigt, der Kassenbestand wird gezählt und abgezeichnet. Zwei
Zahlen, zwei Verantwortlichkeiten — deshalb nicht in einem Feld addiert.

Das Kassenbuch öffnet immer im **laufenden Monat**. Ein ganzes Jahr sind schnell
mehrere hundert Zeilen und die Seite baut sich spürbar langsamer auf; Jahr und
Gesamtzeitraum sind einen Klick daneben. Anfangsbestand und laufender Saldo
stimmen in jedem Zeitraum, weil der Bestand davor als Übertrag mitgeliefert wird.

---

## 6. Offene Posten — wer schuldet wem

| Richtung | Fachbegriff | Bedeutung |
|---|---|---|
| Kunde schuldet uns | Forderungen (Debitoren) | Ausgangsrechnung ist raus, Geld fehlt noch |
| Wir schulden dem Lieferanten | Verbindlichkeiten (Kreditoren) | Eingangsrechnung liegt vor, Zahlung steht aus |

Ein Posten gilt als erledigt, sobald ihm eine Zahlung zugeordnet ist. Die
Zuordnung geschieht meist automatisch — über Rechnungsnummer im
Verwendungszweck, IBAN, Betrag oder eine gelernte Regel. Was nicht sicher
zuzuordnen ist, landet im Durchlauf und wird per Hand entschieden.

---

## 7. Umsatzsteuer

<figure>
<svg viewBox="0 0 860 190" xmlns="http://www.w3.org/2000/svg" role="img"
     aria-label="Umsatzsteuer aus Erlösen minus Vorsteuer aus Aufwand ergibt die Zahllast">
  <style>
    .u  { fill: none; stroke-width: 2; }
    .ut { fill: currentColor; font: 600 13px sans-serif; }
    .us { fill: currentColor; font: 11.5px sans-serif; opacity: .7; }
    .op { fill: currentColor; font: 600 22px sans-serif; }
    .ul { stroke: currentColor; stroke-width: 1.5; fill: none; marker-end: url(#tip4); }
  </style>
  <defs>
    <marker id="tip4" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto">
      <path d="M0,0 L10,5 L0,10 z" fill="currentColor"/>
    </marker>
  </defs>
  <rect class="u" x="20" y="20" width="230" height="60" rx="6" stroke="#2e7d32"/>
  <text class="ut" x="38" y="45">Umsatzsteuer</text>
  <text class="us" x="38" y="65">auf das, was Sie berechnen</text>
  <text class="op" x="278" y="62">−</text>
  <rect class="u" x="320" y="20" width="230" height="60" rx="6" stroke="#c62828"/>
  <text class="ut" x="338" y="45">Vorsteuer</text>
  <text class="us" x="338" y="65">auf das, was Sie einkaufen</text>
  <text class="op" x="578" y="62">=</text>
  <rect class="u" x="620" y="20" width="220" height="60" rx="6" stroke="#1976d2"/>
  <text class="ut" x="638" y="45">Zahllast</text>
  <text class="us" x="638" y="65">ans Finanzamt, monatlich</text>
  <rect class="u" x="20" y="115" width="820" height="55" rx="6" stroke="currentColor" stroke-dasharray="5 4"/>
  <text class="us" x="40" y="139">Voranmeldung: bis zum 10. des Folgemonats — mit Dauerfristverlängerung bis zum 10. des übernächsten.</text>
  <text class="us" x="40" y="158">Der Zeitstrahl im Cockpit zeigt jeden Monat des Jahres: abgegeben, offen oder überfällig.</text>
</svg>
<figcaption>Die Umsatzsteuer-Voranmeldung ist eine reine Rechenaufgabe aus den gebuchten Beträgen — sie wird nicht getippt, sondern aus dem Hauptbuch abgeleitet.</figcaption>
</figure>

Voraussetzung ist, dass die Steuerschlüssel an den Konten stimmen. Fehlt einem
Konto der gültige Schlüssel, taucht es in der Voranmeldung nicht auf — das ist
der häufigste Grund für eine Abweichung.

---

## 8. Berichte — alle Konten auf einen Blick

Der Einstieg in jede Prüfung ist die **Summen- und Saldenliste**: eine Zeile je
Konto mit Anfangssaldo, Soll, Haben und Endsaldo. Ein Klick auf eine Zeile führt
ins **Kontoblatt** mit den einzelnen Buchungen dieses Kontos.

<figure>
<svg viewBox="0 0 860 240" xmlns="http://www.w3.org/2000/svg" role="img"
     aria-label="Von der Saldenliste ins Kontoblatt bis zum einzelnen Beleg">
  <style>
    .r   { fill: none; stroke: currentColor; stroke-width: 1.5; }
    .rt  { fill: currentColor; font: 600 13px sans-serif; }
    .rs  { fill: currentColor; font: 11px sans-serif; opacity: .7; }
    .rl  { stroke: #1976d2; stroke-width: 2; fill: none; marker-end: url(#tip5); }
    .rlb { fill: #1976d2; font: 11px sans-serif; }
  </style>
  <defs>
    <marker id="tip5" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto">
      <path d="M0,0 L10,5 L0,10 z" fill="#1976d2"/>
    </marker>
  </defs>
  <rect class="r" x="15" y="30" width="250" height="160" rx="6"/>
  <text class="rt" x="33" y="55">Summen- und Saldenliste</text>
  <path class="r" d="M33,66 H247"/>
  <text class="rs" x="33" y="86">1200  Forderungen … 60.186,16</text>
  <text class="rs" x="33" y="106">1600  Kasse …………… 1.998,52</text>
  <text class="rs" x="33" y="126">1800  Bank ………………36.846,75</text>
  <text class="rs" x="33" y="146">4400  Erlöse 19 % … 363.906,47</text>
  <text class="rs" x="33" y="172" opacity=".45">alle Konten des Zeitraums</text>
  <rect class="r" x="305" y="30" width="250" height="160" rx="6"/>
  <text class="rt" x="323" y="55">Kontoblatt 1600</text>
  <path class="r" d="M323,66 H537"/>
  <text class="rs" x="323" y="86">05.01.  Beleg 1 …… +59,96</text>
  <text class="rs" x="323" y="106">05.01.  Beleg 2 … +1.098,69</text>
  <text class="rs" x="323" y="126">06.01.  Beleg 4 … −2.000,00</text>
  <text class="rs" x="323" y="146">06.01.  Beleg 5 …… +20,00</text>
  <text class="rs" x="323" y="172" opacity=".45">jede Buchung mit laufendem Saldo</text>
  <rect class="r" x="595" y="30" width="250" height="160" rx="6"/>
  <text class="rt" x="613" y="55">Buchungssatz</text>
  <path class="r" d="M613,66 H827"/>
  <text class="rs" x="613" y="86">1600 Kasse ……… Soll  59,96</text>
  <text class="rs" x="613" y="106">4400 Erlöse …… Haben 50,39</text>
  <text class="rs" x="613" y="126">3806 USt 19 % … Haben  9,57</text>
  <text class="rs" x="613" y="172" opacity=".45">plus Beleg als Datei</text>
  <path class="rl" d="M265,110 H300"/>
  <path class="rl" d="M555,110 H590"/>
  <text class="rlb" x="262" y="215">Klick auf die Zeile</text>
  <text class="rlb" x="552" y="215">Klick auf die Buchung</text>
</svg>
<figcaption>Drei Ebenen, jeweils einen Klick auseinander: Konto → Buchungen des Kontos → einzelner Buchungssatz mit Beleg.</figcaption>
</figure>

Sie finden die Berichte im Cockpit unter **Nachschlagen und drucken** — in
beiden Modi, nicht nur im fachlichen. Über `Strg+K` genügt auch die Eingabe
„Berichte".

---

## 9. Was sich drucken lässt

| Ausdruck | Wo | Inhalt |
|---|---|---|
| **Kontoauszug** | Bank → Umsätze → *Kontoauszug drucken* | Bankumsätze eines Zeitraums, chronologisch, mit Anfangssaldo, laufendem Saldo und Endsaldo |
| **Kassenbuch** | Kasse → *Kassenbuch drucken* | Aufbau wie bei Lexware: laufende Nummer, Beleg-Nr., Buchungstext, Gegenkonto, USt-Satz, Einnahme, Ausgabe, Bestand — mit Übertrag, Summenzeile und Unterschriftsfeld |
| **Summen- und Saldenliste** | Berichte → *Drucken* | alle Konten mit Anfangssaldo, Soll, Haben, Endsaldo |
| **Kontoblatt** | Kontoblatt → *Kontoblatt drucken* | alle Buchungen eines Kontos mit Eröffnungssaldo und laufendem Saldo |
| **DATEV-Export** | Fachbereich → DATEV Export | Buchungsstapel und Belege für den Steuerberater |

Alle Ausdrucke öffnen sich als PDF in einem neuen Tab — von dort geht es zum
Drucker oder in den Ordner.

---

## 10. Warum nur ein Menüpunkt?

Die Buchhaltung hatte einmal dreizehn Menüeinträge. Das führte dazu, dass man
erst überlegen musste, wo man hin will, bevor man arbeiten konnte. Heute gilt:

- **Das Cockpit zeigt die Arbeit**, nicht die Funktionen. Eine Kachel erscheint
  nur, wenn es dort etwas zu tun gibt; ist alles erledigt, ist die Seite leer —
  und genau das ist die Rückmeldung.
- **Der Durchlauf** arbeitet einen Stapel am Stück ab, statt für jeden Beleg
  eine Maske zu öffnen.
- **Die Befehlspalette** (`Strg+K`) findet alles Seltene: Konten, Kunden,
  Lieferanten, Belegnummern und jede Seite der Buchhaltung. Verborgen ist
  nichts — nur nicht dauernd im Weg.

---

## 11. Der Monatsabschluss als Checkliste

1. **Belege vollständig?** Cockpit-Kachel „Belege" muss leer sein.
2. **Bank vollständig?** Alle Umsätze des Monats abgerufen und zugeordnet.
3. **Kasse gezählt?** Kassenbuch drucken, Bestand mit der Kassenlade abgleichen,
   abzeichnen.
4. **Offene Posten geprüft?** Überfällige Forderungen anmahnen.
5. **Saldenliste angesehen?** Soll und Haben müssen sich decken. Weichen sie ab,
   zeigt die Kopfzeile der Berichte die Differenz — dann stimmt eine Buchung nicht.
6. **Umsatzsteuer abgegeben?** Zeitstrahl im Cockpit auf Grün prüfen.

---

## Verwandte Seiten

- [Belegablage — Verfahrensdokumentation](gobd-belege.md) — wo Belege liegen, wer sie anfasst, wie lange sie bleiben
- [Banking](banking.md) — FinTS, Überweisungen, Lastschriften, Bankabstimmung
- [Umsatzsteuer-Voranmeldung](ustva.md) — Kennzahlen, Fristen, ELSTER
- [Faktura](faktura.md) — Angebot, Auftrag, Rechnung
