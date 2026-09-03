# Belegablage — Verfahrensdokumentation

Die GoBD verlangen eine Beschreibung des Verfahrens: wie ein Beleg ins System
kommt, wo er liegt, wer ihn anfassen kann und wie lange er bleibt. Diese Seite
ist diese Beschreibung. Sie beschreibt den **tatsächlichen** Stand der Technik in
OS-ERP — nicht den gewünschten.

> **Kein Rechtsrat.** Diese Seite beschreibt, was die Software tut. Ob das im
> konkreten Fall genügt, entscheidet der Steuerberater oder der Betriebsprüfer.
> Die offenen Punkte am Ende sind bewusst offen benannt.

---

## 1. Wie ein Beleg ins System kommt

Vier Wege, alle enden in derselben Ablage:

| Weg | Auslöser | Status nach der Ablage |
|---|---|---|
| Beleg-Upload | Datei aufs Cockpit ziehen | `pending` → Erkennung → `extracted` → `booked` |
| Kassenbeleg | Büroklammer an einer Kassenbuchung | `booked` |
| Bankabstimmung | Beleg beim Buchen einer Eingangsrechnung anhängen | `booked` |
| Kartenabrechnung | Abrechnungsdatei eines Zahlungsdienstleisters | `extracted` |
| **Ausgangsrechnung** | Rechnung wird gedruckt, gemailt oder gesammelt gedruckt | `booked` |

Jeder dieser Wege ruft denselben Baustein auf
(`backend/api/lib/belegablage.php`). Es gibt keinen fünften Weg, an dem andere
Regeln gälten.

---

## 2. Wo der Beleg liegt

<figure>
<svg viewBox="0 0 860 260" xmlns="http://www.w3.org/2000/svg" role="img"
     aria-label="Weg eines Belegs von der Datei bis zur Buchung">
  <style>
    .b { fill: none; stroke-width: 2; }
    .t { fill: currentColor; font: 600 13px sans-serif; }
    .s { fill: currentColor; font: 11px sans-serif; opacity: .7; }
    .l { stroke: currentColor; stroke-width: 1.5; fill: none; marker-end: url(#gp); }
  </style>
  <defs>
    <marker id="gp" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto">
      <path d="M0,0 L10,5 L0,10 z" fill="currentColor"/>
    </marker>
  </defs>
  <rect class="b" x="15" y="20" width="215" height="70" rx="6" stroke="#1976d2"/>
  <text class="t" x="33" y="45">Datei</text>
  <text class="s" x="33" y="64">backend/data/&lt;mandant&gt;/</text>
  <text class="s" x="33" y="80">accounting/&lt;id&gt;_&lt;name&gt;</text>
  <rect class="b" x="325" y="20" width="215" height="70" rx="6" stroke="#6a1b9a"/>
  <text class="t" x="343" y="45">accounting_documents</text>
  <text class="s" x="343" y="64">Name, Typ, Größe, SHA-256,</text>
  <text class="s" x="343" y="80">Bearbeiter, Frist, Zeitstempel</text>
  <rect class="b" x="635" y="20" width="210" height="70" rx="6" stroke="#2e7d32"/>
  <text class="t" x="653" y="45">Buchung</text>
  <text class="s" x="653" y="64">cash_gl_documents (Kasse)</text>
  <text class="s" x="653" y="80">ap_id (Eingangsrechnung)</text>
  <rect class="b" x="325" y="150" width="215" height="70" rx="6" stroke="#ef6c00"/>
  <text class="t" x="343" y="175">accounting_document_log</text>
  <text class="s" x="343" y="194">wer, wann, was —</text>
  <text class="s" x="343" y="210">nur angehängt, nie geändert</text>
  <path class="l" d="M230,55 H320"/>
  <path class="l" d="M540,55 H630"/>
  <path class="l" d="M432,90 V145"/>
  <path class="l" d="M120,90 C120,185 200,185 320,185"/>
  <path class="l" d="M740,90 C740,185 660,185 545,185"/>
</svg>
<figcaption>Datei und Datensatz gehören zusammen: der Datensatz ohne Datei ist ein Loch, die Datei ohne Datensatz ist unauffindbar. Die Belegprüfung sucht genau nach diesen beiden Fällen.</figcaption>
</figure>

**Verzeichnis:** `backend/data/<datenbankname>/accounting/`. Jeder Mandant hat
sein eigenes, benannt nach seiner Datenbank. Der Dateiname beginnt mit der
Beleg-ID, damit Datei und Datensatz auch von Hand zusammenzubringen sind.

**Dateirechte:** Nach dem Schreiben wird die Datei auf `0444` gesetzt — nur
lesbar. Die Anwendung selbst kann einen abgelegten Beleg damit nicht mehr
überschreiben; `belegSchreiben()` weigert sich zusätzlich, eine vorhandene Datei
anzufassen.

**Doppelte Belege:** Vor der Ablage wird der SHA-256-Hash gebildet. Ist er schon
vorhanden, wird auf den bestehenden Beleg verwiesen statt eine zweite Kopie
anzulegen.

---

## 3. Wer war das

Jeder Beleg trägt die Mitarbeiter-ID desjenigen, der ihn abgelegt hat. Sie kommt
aus dem angemeldeten Benutzer im Frontend und wird bei jeder Anfrage
mitgeschickt (Axios-Interceptor in `src/main.js`, ausgewertet von
`mitarbeiterId()`).

Zusätzlich schreibt `accounting_document_log` jeden Zugriff mit:

| Vorgang | Wann |
|---|---|
| `ablage` | Beleg wird gespeichert |
| `verknuepfung` | Beleg wird an eine Kassenbuchung gehängt |
| `ansicht` | Beleg wird geöffnet |
| `pruefung` | Belegprüfung findet eine Auffälligkeit |

Das Protokoll wird nur angehängt. Es gibt keine Funktion, die daraus löscht oder
darin ändert.

---

## 4. Unveränderbarkeit und ihre Kontrolle

Die Prüfsumme allein beweist nichts — sie muss nachgerechnet werden. Dafür gibt
es **Buchhaltung → Belegprüfung**: die Seite liest jede Datei erneut ein, bildet
den SHA-256-Hash und vergleicht ihn mit dem gespeicherten.

| Ergebnis | Bedeutung |
|---|---|
| unverändert | Datei vorhanden, Hash stimmt |
| **verändert** | Datei wurde nach der Ablage ausgetauscht — Fall für den Steuerberater |
| **Datei fehlt** | Datensatz vorhanden, Datei verschwunden |
| nicht abgelegt | Ablage wurde abgebrochen |
| nicht prüfbar | Altbestand ohne gespeicherten Hash |

Zusätzlich zeigt die Seite Belege **ohne Bearbeiter** (Altbestand aus der Zeit
vor dieser Änderung) und Belege **ohne Buchung**.

**Empfehlung:** einmal im Monat laufen lassen, spätestens beim Monatsabschluss.

---

## 5. Aufbewahrung

Beim Ablegen wird `retention_until` gesetzt: Ablagedatum plus die Anzahl Jahre
aus `defaults_oserp.beleg_aufbewahrung_jahre`. Ohne Eintrag gelten **10 Jahre** —
bewusst der längere der beiden gängigen Werte. Für Buchungsbelege wurde die
Frist verkürzt, für andere Unterlagen nicht; welche Frist im Einzelfall greift,
gehört zum Steuerberater und nicht in eine fest verdrahtete Zahl.

Die Software löscht **nichts** automatisch. Es gibt in der gesamten API keine
Funktion, die einen Beleg entfernt. Ein Löschen nach Fristablauf wäre eine
bewusste, von Hand ausgelöste Aufräumaktion.

---

## 6. Datensicherung

Belege liegen **außerhalb der Datenbank**. Ein Datenbank-Backup allein rettet sie
nicht — das Verzeichnis `backend/data/` muss mitgesichert werden.

**Geprüft am 28.08.2026:** Die Borg-Sicherung auf diesem Server sichert `/` mit
einer kurzen Ausschlussliste, in der das Belegverzeichnis nicht steht. Die Belege
sind also mit gesichert. Was fehlt, ist der **Nachweis der Rücksicherung** —
siehe [Maßnahmenplan](gobd-massnahmen.md), M6.

Das Ablageverzeichnis des angemeldeten Mandanten steht im Klartext auf der Seite
**Belegprüfung** — damit es beim Prüfen der Sicherung nicht gesucht werden muss.

---

## 7. Was offen ist

Ehrlich benannt, damit es niemanden im Prüfungsfall überrascht:

1. **Altbestand ohne Bearbeiter.** Belege, die vor dieser Änderung abgelegt
   wurden, haben keine Mitarbeiter-ID. Das lässt sich nicht rückwirkend
   herstellen. Die Belegprüfung zählt sie, damit die Größenordnung bekannt ist.
2. **Root kommt an alles.** Der Schreibschutz hält die Anwendung ab, nicht einen
   Administrator. Ein echtes revisionssicheres Archiv (WORM) ist das nicht.
3. **Keine automatische Prüfung.** Die Belegprüfung läuft nur, wenn jemand sie
   startet. Ein nächtlicher Lauf mit Benachrichtigung wäre der nächste Schritt.
4. **Ausgangsrechnungen vor dem 28.08.2026.** Seit dem 28.08.2026 wird beim
   Drucken ein Versandexemplar abgelegt (`accounting_documents.ar_id`). Alles,
   was davor verschickt wurde, existiert nur als Neuberechnung aus Daten und
   Vorlage — siehe [Maßnahmenplan](gobd-massnahmen.md), M2.
5. **Ersetzendes Scannen.** Wenn Papierbelege nach dem Einscannen vernichtet
   werden sollen, braucht es eine eigene, unterschriebene Scan-Richtlinie
   (wer scannt, mit welchem Gerät, welche Kontrolle). Die gibt es hier nicht.
6. **Diese Seite ist nicht abgezeichnet.** Eine Verfahrensdokumentation gehört
   datiert und vom Verantwortlichen freigegeben. Bitte mit dem Steuerberater
   durchgehen und unterschreiben.

---

Wie und in welcher Reihenfolge diese Punkte geschlossen werden sollen, steht im
[Maßnahmenplan](gobd-massnahmen.md).

---

## Verwandte Seiten

- [GoBD-Maßnahmenplan](gobd-massnahmen.md) — Konzept für die offenen Punkte
- [Buchhaltung — das Konzept](buchhaltung.md)
- [Banking](banking.md)
