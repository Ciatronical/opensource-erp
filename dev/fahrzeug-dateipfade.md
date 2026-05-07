# Fahrzeug-Dateipfade

Übersicht aller Pfade, die zu einem Fahrzeug gehören oder im Lebenszyklus berührt werden. Basis ist immer `fmDataDir()` = `backend/data/{mandanten-db-name}/` (z. B. `backend/data/autoprofis_gmbh/`).

`fmDataDir()` ist in [backend/api/customer_vendor/filemanager.php:44](../backend/api/customer_vendor/filemanager.php#L44) definiert und liefert das mandantenspezifische Datenverzeichnis (`current_database()` der Company-DB).

## Dauerhafte Speicherorte (Bestand)

### Pro Fahrzeug

```
fmDataDir/fahrzeuge/{c_id}/
├── fahrzeugschein/
│   ├── original.jpg            ← gesamter Fahrzeugschein
│   └── .crops/
│       ├── crop_address1.jpg
│       ├── crop_address2.jpg
│       ├── crop_firstname.jpg
│       ├── crop_name1.jpg
│       ├── crop_registrationNumber.jpg
│       ├── crop_hsn.jpg
│       ├── crop_field_2_2.jpg     (TSN)
│       ├── crop_vin.jpg            (FIN)
│       ├── crop_field_3.jpg        (FIN-Check)
│       ├── crop_ez.jpg             (Erstzulassung)
│       ├── crop_hu.jpg             (HU)
│       ├── crop_field_14_1.jpg     (Emissionsklasse)
│       └── … (weitere 40+ Crops vom OCR)
├── chiptuningfiles/             ← Auto-Folder aus defaults_oserp.lxcars_auto_folders
├── fehlerprotokolle/            ← Auto-Folder
├── versicherungsfälle/          ← Auto-Folder
└── {beliebige weitere Ordner}/  ← vom Benutzer im Dateimanager angelegt
```

Erzeugt von:

- **Ordner**: `ensureVehicleFolders($carId)` → bei `saveCar` und beim ersten Öffnen des Dateimanagers
- **fahrzeugschein/**: `saveScanImages` (`backend/api/lxcars/scan_images.php`)
- **Auto-Folder**: konfigurierbar via Schlüssel `lxcars_auto_folders` in `defaults_oserp`

### Symlinks für Auffindbarkeit per Kennzeichen

```
fmDataDir/fahrzeuge/0_by-plate/{plate}   →  ../{c_id}/fahrzeugschein
```

Erzeugt von `saveScanImages` (`backend/api/lxcars/scan_images.php`). `{plate}` ist sanitized (Sonderzeichen → `_`).

### Reverse-Symlinks im Kunden-Ordner

```
fmDataDir/customers/{c_ow}/fahrzeuge/{plate}   →  ../../../fahrzeuge/{c_id}
```

Erzeugt von `ensureCustomerFolder($cvId, 'C', $name)` (`backend/api/customer_vendor/filemanager.php`) — wird nach jedem Customer-Save und nach `saveCar` aufgerufen.

### Anbauteile-/Ersatzteilfotos zum Fahrzeug

```
fmDataDir/fahrzeuge/{c_id}/{Instruction-Bezeichnung}/
└── {requestId}_1.jpg, {requestId}_2.jpg, …
```

Erzeugt von `partsRequestPhotoDir()` in `backend/api/lxcars/mechanic.php`. Liegt bewusst auf gleicher Ebene wie `fahrzeugschein/`, damit alle fahrzeugbezogenen Dokumente zusammen sind.

## Temporäre Speicherorte

### Upload-Stage (Original-Bild eines neuen Scans, vor Anlage des Fahrzeugs)

```
fmDataDir/fahrzeuge/0_temp/{tempId}.jpg|.pdf
```

Erzeugt von `scanFahrzeugschein` / `_scanFahrzeugscheinDemo` (`backend/api/lxcars/cars.php`). Wird durch `saveScanImages` per `rename` nach `fahrzeugschein/original.jpg` verschoben.

### Scan-Cache (Crops aus dem OCR-Service, vor Verknüpfung mit einem Fahrzeug)

```
backend/tmp/{scan_id}/
├── original.jpg
└── .crops/
    └── crop_*.jpg
```

**Liegt _nicht_ in `fmDataDir`** — ist ein globaler tmp-Cache. Erzeugt von `cacheScanToTmp` in `backend/api/lxcars/scan_images.php` (aus `getScanDetail` / `scanFahrzeugschein`).

Wird beim Speichern des Fahrzeugs nach `fahrzeuge/{c_id}/fahrzeugschein/` umkopiert (durch `saveScanImages` mit `scan_id`-Parameter) und anschließend von `_removeTmpScanDir` weggeräumt.

## DB-Verknüpfung

**Spalte** `cars_lxcars.filename` enthält den **relativen** Pfad zum Fahrzeugschein-Ordner:

```
'fahrzeuge/{c_id}/fahrzeugschein'
```

Gesetzt von `saveScanImages` (`backend/api/lxcars/scan_images.php`) nach erfolgreicher Bildablage.

## Lifecycle (was passiert wann)

| Aktion | Erzeugte/Berührte Pfade |
|---|---|
| Fahrzeugschein-Scan starten (Upload) | `0_temp/{tempId}.jpg`, `backend/tmp/{scan_id}/{original.jpg, .crops/*}` |
| `saveCar` (Fahrzeug anlegen) | `fahrzeuge/{c_id}/`, Auto-Folder, `customers/{c_ow}/fahrzeuge/{plate}` Symlink |
| `saveScanImages` (Bilder anhängen) | `fahrzeuge/{c_id}/fahrzeugschein/{original.jpg, .crops/*}`, `0_by-plate/{plate}` Symlink, DB `filename` gesetzt; `0_temp` und `backend/tmp/{scan_id}` aufgeräumt |
| Anbauteile-Foto speichern | `fahrzeuge/{c_id}/{Instruction}/{requestId}_N.jpg` |
| Datei via Dateimanager-Dialog hochladen | beliebiger Pfad unter `fahrzeuge/{c_id}/` |
| `deleteCar` | `fahrzeuge/{c_id}/` rekursiv weg, `0_by-plate/{plate}` Symlink weg, `customers/{c_ow}/fahrzeuge/{plate}` Symlink weg |

## Zusammengefasst pro Sicht

- **Aus Sicht eines Fahrzeugs**: `fahrzeuge/{c_id}/` ist die Wurzel, mit `fahrzeugschein/` als Unterordner für den Scan und freien Unterordnern für sonstige Dokumente.
- **Aus Sicht „suche per Kennzeichen"**: `fahrzeuge/0_by-plate/{plate}` zeigt aktuell auf den Fahrzeugschein-Unterordner.
- **Aus Sicht eines Kunden**: `customers/{c_ow}/fahrzeuge/{plate}` zeigt aufs gesamte Fahrzeug-Verzeichnis.

## Konventionen

- **Numerische Ordner** (`{c_id}`) werden vom Code automatisch verwaltet, **`0_`-prefixierte Ordner** sind Index-/Hilfsstrukturen (`0_by-plate`, `0_temp`) und sortieren in einer Verzeichnisliste vor den numerischen Fahrzeug-Ordnern.
- **Verstecktes `.crops/`-Unterverzeichnis** kapselt die OCR-Feldausschnitte vom „normalen" Inhalt eines Fahrzeug-Ordners ab — Vuefinder zeigt es im Dateimanager-Dialog nicht an (Default: hidden files = aus).
- **Symlinks werden bewusst eingesetzt** statt Hardlinks, damit die Auflösung über `realpath()` und das Aufräumen mit `unlink()` funktionieren und sie sich klar von echten Verzeichnissen unterscheiden.
