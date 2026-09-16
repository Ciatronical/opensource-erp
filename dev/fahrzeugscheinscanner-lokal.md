# Eigener Fahrzeugscheinscanner (lokal)

Ersetzt die externe API fahrzeugschein-scanner.de durch einen Dienst auf dem eigenen
Server. Vollständige Beschreibung, Installation und Werkzeuge:
`backend/fahrzeugschein-scanner/README.md`.

## Kurzfassung

- Dienst: `oserp-fahrzeugschein-scanner` (Python-venv, RapidOCR/ONNX, CPU), Port 3003,
  nur 127.0.0.1. Unit-Vorlage `install/oserp-fahrzeugschein-scanner.service`,
  Installer-Schritt `install/install.sh --only fsscanner`.
- Umschalter: Firmenkonfiguration → LxCars → „Eigenen Fahrzeugscheinscanner benutzen“
  (`defaults_oserp.lxcars_local_scanner = 't'`, optional `lxcars_local_scanner_url`).
- Backend: `scanFahrzeugschein()` in `backend/api/lxcars/cars.php` wählt anhand der
  Konfiguration `_scanFahrzeugscheinLocal()` oder `_scanFahrzeugscheinExternal()`.
  Antwortformat ist identisch, Frontend und Speicherung (`saveScanImages`,
  `fs_scans_lxcars`) bleiben unverändert. Im lokalen Modus wird die Scan-Liste nicht
  mehr mit dem externen Portal synchronisiert. `checkLocalScanner` prüft den Dienst.
- Verfahren: SIFT-Registrierung auf eine Median-Vorlage des Vordrucks, gelerntes
  Feldlayout (`reference/layout.json`), Erkennung je Ausschnitt mit dem
  PP-OCRv5-Latin-Modell (kein Training, keine externe KI, kein LLM nötig).
