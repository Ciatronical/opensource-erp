# LxCars Labeldrucker – Reifenetiketten / Grüne Plaketten

Stand: 2026-07-07. Nach dem Serverumzug melissa (.26) → mmelissa (.25) drucken die
**Reifenaufkleber falsch** (oberer + linker Abstand zu groß). Ergebnis der Analyse:

> **Es ist KEIN Treiber-, Server-, CUPS- oder Code-Problem.**
> Die Ursache liegt in der **im Drucker gespeicherten Konfiguration**, die beim
> Umzug (Stromausfall/Reset) verloren gegangen ist.

## Welcher Drucker / welcher „Treiber"?

Konfiguration pro Firma über `defaults_oserp` → `printerId` aus Tabelle `printers`.
Live-DB **`ap_rebuild`** (auf .26 lief es über `autoprofis_gmbh` mit identischer Config):

| Zweck            | defaults_oserp-Key            | printerId | printer_command                 |
|------------------|-------------------------------|-----------|---------------------------------|
| Reifenetiketten  | `lxcars_tyre_label_printer`   | 32283     | `lpr -P BP730-Raw`              |
| Grüne Plaketten  | `lxcars_yellow_label_printer` | 32282     | `lpr -P gruenePlakettenDrucker` |

Code: [backend/api/lxcars/labels.php](../../backend/api/lxcars/labels.php) erzeugt
**ZPL** (`^XA … ^XZ`) und schickt es per `lpr` an die Queue.

### Reifen-Queue `BP730-Raw` = **kein Treiber**

- Gerät: **GoDEX BP730**, Firmware V1.140q, 300 dpi (12 dots/mm, Kopf 1248 dots),
  ZPL-II-Emulation, `socket://192.168.178.252:9100`.
- CUPS-Queue `BP730-Raw`: **reine Raw-Queue ohne PPD** (make-and-model
  `Local Raw Printer`) – auf **.25 und .26 identisch**. Es gibt hier **keinen
  Treiber**, den man neu installieren könnte; die ZPL-Bytes gehen unverändert an den
  Drucker.

### Grüne-Plaketten-Queue `gruenePlakettenDrucker` (geprüft)

- Gerät: **Zebra ZPL Label**, `socket://192.168.178.242:9100`.
- CUPS-Queue mit PPD + Filter `rastertolabel`, **aber** der Code druckt mit `-o raw`
  → der Filter wird umgangen. Funktioniert nach dem Umzug weiter, **kein Treiberbedarf**.

## Beweis: der Server ist NICHT die Ursache

1. **ZPL korrekt** – über Labelary gerendert: sauberes Etikett, normaler Rand.
2. **Byte-Capture auf .25** (Netcat-Capture-Queue): die Raw-Queue sendet die ZPL
   **byte-identisch** (310 B) an den Drucker – **mit und ohne `-o raw`**.
   CUPS/cups-filters verändert also nichts.
3. **Byte-Capture auf .26** (der Server, auf dem es lief): sendet **exakt dieselben
   310 Bytes**.
4. **Gleicher Ziel-Drucker** (BP730-Raw, 192.168.178.252) und gleiche CUPS-Behandlung
   (beide typen die Datei als `text/plain`, beide ohne text→raw-Filter, beide Raw-Queue).

→ Identische Bytes an denselben Drucker. Wenn das Ergebnis trotzdem anders ist, kann
die Ursache nur im **Drucker** liegen.

> Hinweis: Eine zwischenzeitliche Theorie (cups-filters 1.28 → 2.0 verändert Raw-Jobs)
> hat sich als **falsch** erwiesen – beide Versionen reichen die ZPL unverändert durch.
> Das testweise gesetzte `-o raw` im `printer_command` war wirkungslos und wurde
> wieder entfernt.

## Eigentliche Ursache: Drucker-Konfiguration verloren

`^XA^HH^XZ` an den Drucker liefert seine gespeicherte Konfiguration. Auffällig:

```
  648                 PRINT WIDTH      <-- Kopf ist 1248 dots breit! Inhalt bis
                                           x≈1100 wird rechts abgeschnitten
  1200                LABEL LENGTH
  +000                LABEL TOP
  +0000               LEFT POSITION
  ZPL II              ZPL MODE
  CUSTOMIZED          CONFIGURATION
  06/01/24            RTC DATE         <-- Uhr auf 2024 zurückgesetzt (echt: 2026)
                                           => Drucker hatte Strom-/Reset-Ereignis
```

Die falsche RTC-Uhr belegt: der BP730 hat beim Umzug Strom verloren und dabei
Kalibrierung/Einstellungen verloren bzw. neu (falsch) auto-kalibriert. `PRINT WIDTH`
648 statt voller 1248 ist objektiv falsch für das Etikettenlayout.

## Fix (GELÖST – ohne Server/Treiber)

Etikett = **100 × 150 mm = 1200 × 1800 dots** (300 dpi). Zwei verstellte Werte:

**1. Horizontal (PRINT WIDTH 648 → Inhalt zentriert + rechts abgeschnitten).**
Direkt im ZPL erzwungen (stromausfallsicher), in `printTyreLabel()` nach `^XA`:
```
^PW1200      // Etikettenbreite erzwingen (statt gespeicherter 648)
^LH110,0     // ~9 mm linker Rand (110 dots), y-Ursprung 0
```

**2. Vertikal (Sensor erkannte 1236 statt 1800 dots → oberer Versatz).**
Drucker einmalig neu einmessen – druckt nichts ausser 1–2 Vorschub-Etiketten:
```
printf '~JC' | nc 192.168.178.252 9100
```
Prüfen: `~HS` liefert in Feld 4 die erkannte Länge. Vorher 1236 → nach `~JC`
**1820** (LABEL LENGTH 1786) ≈ 150 mm ✓.

Bei erneutem Stromausfall genügt wieder `~JC` (oder FEED-Taste ~3 s halten). Die
Breite/­der Rand sitzen dauerhaft im ZPL.

Status/Config ohne Druck: `~HI` (Modell/FW), `~HS` (Status/Länge),
`^XA^HH^XZ` (volle Config) – jeweils per `nc 192.168.178.252 9100`.
ZPL vorab testen: https://labelary.com/viewer.html (300 dpi = 12dpmm, 100×150 mm).

## Randnotiz GODEX-RT730

Der lokal installierte GoDEX-**RT730**-Treiber (Filter `rastertoezpl`, V1.1.7,
PPD 121072 B) ist auf .25/.26 **byte-identisch** – aber irrelevant, weil die
Reifenetiketten über den BP730 (Raw) laufen, nicht über den RT730.
