# Eigener Fahrzeugscheinscanner (lokal, ohne externe KI)

Ersetzt die API von fahrzeugschein-scanner.de durch einen Dienst auf dem eigenen
Server. Es verlässt kein Bild den Rechner. Ergebnisformat und Bedienung in OSERP
sind identisch zum bisherigen Scanner: Textfelder der Zulassungsbescheinigung
Teil I plus kleine Bildausschnitte je Feld (zum Prüfen per Mausklick) plus das
entzerrte Gesamtdokument.

## Funktionsweise

1. **Registrierung** – Das Foto (beliebig gedreht, schräg, PDF) wird per
   SIFT-Merkmalsabgleich + RANSAC-Homographie auf eine Referenzvorlage des
   Formulars entzerrt (`reference/zb1_reference.jpg`). Die Vorlage ist der
   Median vieler registrierter Scans: nur der gedruckte Vordruck bleibt übrig,
   alle Eintragungen sind herausgemittelt. Weil der Schein zweimal gefaltet ist,
   wird pro Panel eine eigene Homographie verfeinert.
2. **Layout** – `reference/layout.json` enthält die Feldboxen in Vorlagen-
   koordinaten. Es wurde mit `tools/learn_layout.py` aus den vorhandenen Scans
   gelernt (Original + Ausschnitte des alten Scanners per Template-Matching).
3. **Raster** (`fsscanner/grid.py`, `gridmode.py`) – Hintergrund per hellstem
   Farbkanal entfernen (Guilloche verschwindet, Schrift und Linien bleiben),
   Zeilenlinien neigungstolerant verfolgen (Polylinien, folgen der Wölbung) und
   den Referenzzeilen (`reference/grid.json`) nach Reihenfolge und Kästchen-
   Fingerabdruck zuordnen. Daraus entsteht eine stückweise Abbildung der
   y-Koordinaten je Panel — exakt auch bei geknicktem Papier.
4. **Textdetektion + Zuordnung** (`fsscanner/assign.py`) – Der RapidOCR-Detektor
   findet alle Textzeilen im entzerrten Dokument (~3 s). Seitlich wird je Panel
   eine Streckung/Verschiebung aus den Spaltenanfängen geschätzt (die globale
   Homographie skaliert das rechte, geknickte Panel um bis zu 40 % falsch).
   Kästen über mehrere Felder („02.26 Strausberg“) werden an den Feldgrenzen
   geteilt, Felder ohne Kasten fallen auf den Layoutausschnitt zurück.
5. **OCR** – Eigenes CRNN (`reference/crnn.onnx`, trainiert auf 15 774 echten
   OCR-B-Ausschnitten des alten Scanners, siehe `ocr_train/`, 13 Epochen CPU):
   89,6 % exakt auf zurückgehaltenen Dokumenten gegenüber 70 % beim
   PP-OCRv5-Modell, 7 ms je Feld. Es bekommt eng auf den Tintenbereich
   zugeschnittene Rohausschnitte (wie die Trainingsdaten). Abstimmung mit
   PP-OCRv5: bei Codes entscheidet das feste Feldformat (HSN vierstellig,
   Datum, FIN …), bei Text (Latin-Modell, Umlaute) die höhere Konfidenz,
   Kennzeichen (andere Schrift) liest PP-OCR allein. Bemerkungen entstehen
   zeilenweise aus beiden Panels.
6. **Nachbearbeitung** – Zeichenklassen je Feld (FIN ohne I/O/Q, HSN
   vierstellig, Datumsfelder), mitgelesene Feldbezeichner abziehen, FIN-Hersteller-
   kürzel (WMI) gegen typische Verwechslungen korrigieren, abgeleitete Felder wie
   beim alten Scanner (Maker, Model, PowerKw, Ccm, Fuel, FuelCode).
   `FSSCANNER_MODE=layout` schaltet auf den alten reinen Layoutmodus zurück.

Laufzeit auf dem Server (14 Kerne, CPU): ca. 8–12 s je Fahrzeugschein (unter
Volllast durch andere Dienste bis 20 s). OCR-Threads über `FSSCANNER_THREADS`
(Standard 6) begrenzen, damit der Webserver nicht verhungert.

## Gemessene Qualität (Stand 2026-09-11)

`tools/evaluate.py` vergleicht gegen die Ergebnisse des bisherigen Scanners
(`fs_scans_lxcars`, exakter Vergleich nach Normalisierung, 20 Fotos aus dem
Werkstattalltag inkl. geknickter und schräger Aufnahmen):

| Feld | exakt | Feld | exakt |
|---|---|---|---|
| Hubraum (P.1) | 97 % | Fahrzeugklasse (J) | 94 % |
| Kraftstoff (P.3) / Code (10) | 94 % | Hersteller (D.1) | 93 % |
| FIN (E) | 91 % | Erstzulassung (B) | 91 % |
| Kennzeichen | 88 % | HSN | 88 % |
| Emissionsklasse (14.1) | 88 % | Name / Vorname | 85–88 % |
| Anschrift (C.1.3) | 79–85 % | HU | 77 % |
| TSN (2.2) | 74 % | K, G, F.1/F.2, Reifen | 40–62 % |
| P.2/P.4 | 28 % | Feld 22 (Bemerkungen) | zeilenweise, selten exakt |

Über alle 32 Vergleichsfelder: 74 % exakt (35 Dokumente; reiner Layoutmodus:
61 %, Detektionsmodus ohne eigenes Modell: 70 %). Ein Sichtvergleich von 43
Streitfällen ergab, dass in etwa einem Drittel der Fälle unser Scanner recht hat
und der alte falsch liegt (Anschriften, Umlaute) — die wahre Trefferquote liegt
daher über dem Messwert. Das eigene Modell liest auf zurückgehaltenen
Ausschnitten 89,6 % exakt (PP-OCRv5: 70 %). Restfehler sind überwiegend einzelne
Zeichen (Z↔2, F↔E bei der FIN, abgeschnittene erste Ziffer bei knapper
Registrierung) — im Scan-Formular an den Ausschnitten sofort erkennbar und
korrigierbar. Der bisherige Scanner ist damit noch genauer; der lokale liefert
dafür alle Felder ohne Cloud. Wo die Qualität hängt und was als Nächstes hilft,
steht unter „Grenzen“.

## Installation

```bash
cd backend/fahrzeugschein-scanner && ./install.sh
# als Dienst (Voll-Installer: install/install.sh --only fsscanner)
sudo cp ../../install/oserp-fahrzeugschein-scanner.service /etc/systemd/system/
sudo systemctl enable --now oserp-fahrzeugschein-scanner
curl -s http://127.0.0.1:3003/health
```

Auf dem Entwicklungsrechner läuft der Dienst als systemd-User-Unit
(`systemctl --user status oserp-fahrzeugschein-scanner`).

Aktivieren in OSERP: **Firmenkonfiguration → LxCars → „Eigenen
Fahrzeugscheinscanner benutzen“**. Die URL bleibt normalerweise
`http://127.0.0.1:3003`. Der externe API-Key wird dann nicht mehr benötigt.

## Schnittstelle

```
POST /scan   JSON {"image": "<base64>", "is_pdf": false, "with_images": true}
             oder rohe Bild-/PDF-Bytes (Content-Type image/* bzw. application/pdf)
GET  /health
```

Antwort wie bei der externen API: `data` mit allen Feldern (`vin`, `hsn`,
`field_2_2`, `registrationNumber`, `name1`, `address1`, ... ), je Feld
`<feld>_img` (JPEG Base64) und `document_img`. Zusätzlich `meta` mit
Inlier-Zahl, Laufzeiten, OCR-Konfidenzen je Feld und Plausibilitätshinweisen.

## Werkzeuge (nur für Pflege)

| Skript | Zweck |
|---|---|
| `tools/bootstrap_reference.py` | Erste Vorlage aus EINEM flachen Foto (Linien-Entzerrung) |
| `tools/learn_layout.py` | Feldboxen aus Original + Crops lernen |
| `tools/build_reference.py` | Saubere Median-Vorlage aus registrierten Scans |
| `tools/evaluate.py` | Trefferquote je Feld gegen `fs_scans_lxcars` messen (323 Dokumente) |
| `tools/build_grid.py` | Referenzraster (Zeilenlinien, Trennlinien) aus dem Bootstrap-Bild |
| `ocr_train/glyph_bank.py` | Glyphenbank aus Ausschnitten (Monospace-Zerlegung), `clean_bank.py` bereinigt |
| `ocr_train/train_crnn.py` | Eigenes CRNN trainieren (CPU, ~12 min/Epoche) und als ONNX exportieren |

Neues Formularlayout (z. B. andere Druckversion): ein flaches Foto per
`bootstrap_reference.py` entzerren, dann `learn_layout.py` und
`build_reference.py` laufen lassen, `evaluate.py` prüfen.

## Grenzen und nächste Schritte

- Nur Zulassungsbescheinigung Teil I (ab 2005). Alte graue Fahrzeugscheine
  werden nicht erkannt (HTTP 422 „kein Fahrzeugschein erkannt“).
- Stark gewölbtes oder geknicktes Papier: eine Homographie je Panel fängt Falten
  nur teilweise ab; Restfehler von einer halben Zeile lassen Nachbarzeilen in den
  Ausschnitt bluten. Versuche mit Ankerpatches, Lucas-Kanade-Verfolgung,
  Raster-Kreuzkorrelation und lokalem Einrasten an Zeilenlinien haben die
  Messung jeweils verschlechtert (Rasterperiodik) — sie sind im Code als
  abschaltbare Varianten belassen (`FSSCANNER_REFINE_CROP=1`).
- Aussichtsreich: (1) Registrierung stückweise (Thin-Plate-Spline über die
  SIFT-Inlier statt Homographie), (2) FIN-Prüfziffer/WMI-Liste erweitern,
  (3) Nachtraining des Latin-Erkennungsmodells auf OCR-B mit den ~19 000
  vorhandenen Ausschnitten (PaddleOCR-Feintuning, CPU-tauglich).
