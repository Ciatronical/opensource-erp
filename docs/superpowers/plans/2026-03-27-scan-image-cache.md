# Scan-Bilder-Cache Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scan-Bilder temporär auf dem Server in `backend/tmp/{scan_id}/` zwischenspeichern, statt sie als Base64 im JSON mitzuschicken. Bilder werden beim Hover einzeln vom Server geladen. Beim Speichern des Fahrzeugs werden sie in den endgültigen Ordner verschoben.

**Architecture:**
- `getScanDetail()` speichert Bilder in `backend/tmp/{scan_id}/` und liefert nur Textdaten zurück
- Neuer Endpunkt `getScanTempCrop(scan_id, field)` liefert ein einzelnes Crop-Bild aus dem tmp-Ordner
- Frontend: `scanCrops` computed liefert URLs statt data-URIs, Bilder werden beim Hover lazy geladen
- `saveScanImages()` kopiert aus `backend/tmp/{scan_id}/` in den endgültigen Ordner `fahrzeugschein/{c_id}/`

**Tech Stack:** PHP 8, Vue 3 / Pinia

---

## File Structure

| Datei | Änderung | Verantwortung |
|-------|----------|---------------|
| `backend/api/lxcars/scan_images.php` | Modify | Neue Funktionen `cacheScanToTmp()`, `getScanTempCrop()`, erweiterte `saveScanImages()` |
| `backend/api/lxcars/cars.php` | Modify | `getScanDetail()` cacht Bilder in tmp, liefert keine Bilder mehr |
| `src/features/lxcars/stores/lxcars.store.js` | Modify | Neue Action `getScanTempCropUrl()`, erweiterte `saveScanImages()` |
| `src/features/lxcars/views/car/car.scan.view.vue` | Modify | `scanCrops` und `selectScan()` anpassen |

---

### Task 1: Cache-Funktionen + Endpunkt in scan_images.php

**Files:**
- Modify: `backend/api/lxcars/scan_images.php` (am Dateiende anfügen)

- [ ] **Step 1: Funktionen am Ende von `scan_images.php` hinzufügen (nach Zeile 321)**

```php
/**
 * Speichert Scan-Bilder in backend/tmp/{scan_id}/
 * Wird von getScanDetail() aufgerufen.
 *
 * @param string $scanId Scan-ID
 * @param array $imageFields { 'hsnImg' => '<base64>', 'document_img' => '<base64>', ... }
 */
function cacheScanToTmp($scanId, $imageFields) {
    if (empty($scanId) || empty($imageFields)) return;

    $safeScanId = preg_replace('/[^a-zA-Z0-9\-]/', '', $scanId);
    if (empty($safeScanId)) return;

    $scanDir = __DIR__ . '/../../tmp/' . $safeScanId;
    $cropDir = $scanDir . '/.crops';

    if (!is_dir($scanDir)) mkdir($scanDir, 0775, true);
    if (!is_dir($cropDir)) mkdir($cropDir, 0775, true);

    foreach ($imageFields as $key => $base64) {
        if (empty($base64)) continue;

        $decoded = base64_decode($base64);
        if ($decoded === false) continue;

        // document_img → original.jpg
        if ($key === 'document_img' || $key === 'documentImg') {
            file_put_contents($scanDir . '/original.jpg', $decoded);
            continue;
        }

        // Feldname: 'hsn_img' → 'hsn', 'hsnImg' → 'hsn'
        $fieldName = preg_replace('/[_]?[iI]mg$/', '', $key);
        if (empty($fieldName)) continue;
        $fieldName = preg_replace('/[^a-zA-Z0-9_]/', '', $fieldName);
        $fieldName = rtrim($fieldName, '_');
        if (empty($fieldName)) continue;

        file_put_contents($cropDir . '/crop_' . $fieldName . '.jpg', $decoded);
    }
}

/**
 * Gibt ein einzelnes Crop-Bild aus backend/tmp/{scan_id}/ als Base64 zurück.
 * Wird beim Hover im Scan-Formular aufgerufen (lazy loading).
 *
 * @param string $data['scan_id'] Scan-ID
 * @param string $data['field'] Feldname (z.B. 'hsn', 'vin', 'registrationNumber')
 * @testdata {"scan_id": "12345", "field": "hsn"}
 */
function getScanTempCrop($data) {
    $scanId = trim($data['scan_id'] ?? '');
    $field = trim($data['field'] ?? '');

    if (empty($scanId) || empty($field)) {
        resultInfo(false, 'VALIDATION_ERROR', 'scan_id und field sind erforderlich');
        return;
    }

    $safeScanId = preg_replace('/[^a-zA-Z0-9\-]/', '', $scanId);
    $safeField = preg_replace('/[^a-zA-Z0-9_]/', '', $field);

    if (empty($safeScanId) || empty($safeField)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Ungültige Parameter');
        return;
    }

    // original-Bild
    if ($safeField === 'original') {
        $filePath = __DIR__ . '/../../tmp/' . $safeScanId . '/original.jpg';
    } else {
        $filePath = __DIR__ . '/../../tmp/' . $safeScanId . '/.crops/crop_' . $safeField . '.jpg';
    }

    if (!is_file($filePath)) {
        resultInfo(false, 'FILE_NOT_FOUND', 'Bild nicht gefunden');
        return;
    }

    resultInfo(true, 'OK', [
        'image' => base64_encode(file_get_contents($filePath)),
        'mime'  => 'image/jpeg'
    ]);
}

/**
 * Gibt die Liste der verfügbaren Crop-Felder aus backend/tmp/{scan_id}/ zurück.
 * Wird nach getScanDetail aufgerufen um zu wissen, welche Crops vorhanden sind.
 *
 * @param string $data['scan_id'] Scan-ID
 * @testdata {"scan_id": "12345"}
 */
function getScanTempCropList($data) {
    $scanId = trim($data['scan_id'] ?? '');
    if (empty($scanId)) {
        resultInfo(true, 'OK', ['fields' => []]);
        return;
    }

    $safeScanId = preg_replace('/[^a-zA-Z0-9\-]/', '', $scanId);
    $cropDir = __DIR__ . '/../../tmp/' . $safeScanId . '/.crops';

    $fields = [];
    if (is_dir($cropDir)) {
        $entries = scandir($cropDir);
        foreach ($entries as $entry) {
            if (strpos($entry, 'crop_') !== 0) continue;
            $field = preg_replace('/^crop_(.+)\.\w+$/', '$1', $entry);
            $fields[] = $field;
        }
    }

    $hasOriginal = is_file(__DIR__ . '/../../tmp/' . $safeScanId . '/original.jpg');

    resultInfo(true, 'OK', [
        'fields'       => $fields,
        'has_original' => $hasOriginal
    ]);
}
```

- [ ] **Step 2: Commit**

```bash
tools/fix-ws.sh
git add backend/api/lxcars/scan_images.php
git commit -m "feat(lxcars): add tmp scan image cache + getScanTempCrop endpoint"
```

---

### Task 2: getScanDetail() — Bilder in tmp cachen, nicht mehr zurückgeben

**Files:**
- Modify: `backend/api/lxcars/cars.php:1777-1842`

- [ ] **Step 1: `getScanDetail()` ersetzen (Zeilen 1777-1842)**

```php
/**
 * Holt Detail-Daten eines Scans (nur Textdaten).
 * Bilder werden in backend/tmp/{scan_id}/ gecacht und über getScanTempCrop geladen.
 *
 * @param string $data['scan_id'] Scan-ID
 * @testdata {"scan_id": "12345"}
 */
function getScanDetail($data) {
    $db = DbhCompany::begin();
    $scanId = trim($data['scan_id'] ?? '');

    if (empty($scanId)) {
        resultInfo(false, 'VALIDATION_ERROR', 'scan_id ist erforderlich');
        return;
    }

    // Textdaten aus DB (nach Sync immer vorhanden)
    $row = $db->getOne(
        "SELECT * FROM fs_scans_lxcars WHERE scan_id = :scan_id",
        [':scan_id' => $scanId]
    );

    // Prüfen ob Bilder schon im tmp-Cache liegen
    $safeScanId = preg_replace('/[^a-zA-Z0-9\-]/', '', $scanId);
    $tmpCropDir = __DIR__ . '/../../tmp/' . $safeScanId . '/.crops';
    $hasCachedImages = is_dir($tmpCropDir) && count(glob($tmpCropDir . '/crop_*.jpg')) > 0;

    // DB-Zeile vorhanden + Bilder gecacht → schneller Pfad
    if ($row && $hasCachedImages) {
        $mapped = mapScanToCarFields($row);
        resultInfo(true, 'OK', [
            'car'   => $mapped['car'],
            'kba'   => $mapped['kba'],
            'owner' => $mapped['owner'],
            'raw'   => $row
        ]);
        return;
    }

    // API aufrufen (mit Bildern zum Cachen)
    $apiRow = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = 'lxcarsapi'",
        []
    );

    if (!$apiRow || empty($apiRow['value'])) {
        if ($row) {
            $mapped = mapScanToCarFields($row);
            resultInfo(true, 'OK', [
                'car'   => $mapped['car'],
                'kba'   => $mapped['kba'],
                'owner' => $mapped['owner'],
                'raw'   => $row
            ]);
            return;
        }
        resultInfo(false, 'NO_API_KEY', 'Kein API-Key konfiguriert (lxcarsapi)');
        return;
    }

    $apiKey = $apiRow['value'];
    $detailUrl = 'https://fahrzeugschein-scanner.de/api/Scans/ScanDetails/' . $apiKey . '/' . $scanId . '/true';
    $detailJson = file_get_contents($detailUrl);

    if ($detailJson === false) {
        resultInfo(false, 'SCAN_API_ERROR', 'Fehler beim Abrufen der Scan-Details');
        return;
    }

    $detail = json_decode($detailJson, true);
    if (!$detail) {
        resultInfo(false, 'SCAN_PARSE_ERROR', 'Ungültige API-Antwort');
        return;
    }

    // Bild-Felder extrahieren und in tmp cachen
    $imageFields = [];
    foreach ($detail as $key => $val) {
        if ((strpos($key, 'img') !== false || strpos($key, 'Img') !== false) && !empty($val)) {
            $imageFields[$key] = $val;
            unset($detail[$key]);
        }
    }

    // Original-Dokument holen
    if (empty($imageFields['document_img'])) {
        $documentUrl = 'https://fahrzeugschein-scanner.de/api/Scans/Document/' . $apiKey . '/' . $scanId;
        $imgData = @file_get_contents($documentUrl);
        if ($imgData !== false && strlen($imgData) > 100) {
            $imageFields['document_img'] = base64_encode($imgData);
        }
    }

    // In tmp cachen
    cacheScanToTmp($scanId, $imageFields);

    $source = $row ?: $detail;
    $mapped = mapScanToCarFields($source);

    resultInfo(true, 'OK', [
        'car'   => $mapped['car'],
        'kba'   => $mapped['kba'],
        'owner' => $mapped['owner'],
        'raw'   => $source
    ]);
}
```

- [ ] **Step 2: Commit**

```bash
tools/fix-ws.sh
git add backend/api/lxcars/cars.php
git commit -m "feat(lxcars): getScanDetail caches images to tmp, returns text only"
```

---

### Task 3: saveScanImages() — aus tmp kopieren

**Files:**
- Modify: `backend/api/lxcars/scan_images.php:19-138`

- [ ] **Step 1: scan_id-Parameter hinzufügen (nach Zeile 21)**

```php
$scanId = trim($data['scan_id'] ?? '');
```

- [ ] **Step 2: Cache-Fallback nach dem `original_image`-Block einfügen (nach Zeile 70)**

Nach der schließenden `}` von `elseif (!empty($data['original_image']))`:

```php
} elseif (!empty($scanId)) {
    // Aus tmp-Cache kopieren
    $safeScanId = preg_replace('/[^a-zA-Z0-9\-]/', '', $scanId);
    $cacheDir = __DIR__ . '/../../tmp/' . $safeScanId;

    if (is_file($cacheDir . '/original.jpg')) {
        copy($cacheDir . '/original.jpg', $carDir . '/original.jpg');
        $savedFiles[] = 'original.jpg';
    }

    if (empty($data['field_images']) && is_dir($cacheDir . '/.crops')) {
        $cropEntries = scandir($cacheDir . '/.crops');
        foreach ($cropEntries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (!is_file($cacheDir . '/.crops/' . $entry)) continue;
            copy($cacheDir . '/.crops/' . $entry, $cropDir . '/' . $entry);
            $savedFiles[] = '.crops/' . $entry;
        }
    }

    // tmp aufräumen
    _removeTmpScanDir($safeScanId);
}
```

- [ ] **Step 3: Aufräum-Hilfsfunktion am Dateiende hinzufügen**

```php
/**
 * Löscht den tmp-Cache für eine Scan-ID
 *
 * @param string $safeScanId Bereits bereinigte Scan-ID
 */
function _removeTmpScanDir($safeScanId) {
    $dir = __DIR__ . '/../../tmp/' . $safeScanId;
    if (!is_dir($dir)) return;

    // .crops löschen
    $cropDir = $dir . '/.crops';
    if (is_dir($cropDir)) {
        $entries = scandir($cropDir);
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            @unlink($cropDir . '/' . $e);
        }
        @rmdir($cropDir);
    }

    // Dateien im Hauptverzeichnis löschen
    $entries = scandir($dir);
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        @unlink($dir . '/' . $e);
    }
    @rmdir($dir);
}
```

- [ ] **Step 4: Commit**

```bash
tools/fix-ws.sh
git add backend/api/lxcars/scan_images.php
git commit -m "feat(lxcars): saveScanImages copies from tmp cache, cleans up after"
```

---

### Task 4: Store — neue Actions

**Files:**
- Modify: `src/features/lxcars/stores/lxcars.store.js`

- [ ] **Step 1: `getScanTempCrop` und `getScanTempCropList` Actions hinzufügen (nach getScanCrops, Zeile 766)**

```javascript
/**
 * Lädt die Liste der verfügbaren Crop-Felder aus dem tmp-Cache
 *
 * @param {string} scanId - Scan-ID
 * @return {Promise<Object>} { fields: ['hsn', 'vin', ...], has_original: true }
 */
async function getScanTempCropList(scanId) {
    const response = await axios.post('/api/lxcars/', {
        action: 'getScanTempCropList',
        scan_id: scanId
    });
    if (!response.data.success) {
        throw new ApiError('ApiError', response.data.text, 'Error loading scan crop list: ' + response.data.text);
    }
    return response.data.payload;
}

/**
 * Lädt ein einzelnes Crop-Bild aus dem tmp-Cache
 *
 * @param {string} scanId - Scan-ID
 * @param {string} field - Feldname (z.B. 'hsn', 'vin')
 * @return {Promise<Object>} { image: '<base64>', mime: 'image/jpeg' }
 */
async function getScanTempCrop(scanId, field) {
    const response = await axios.post('/api/lxcars/', {
        action: 'getScanTempCrop',
        scan_id: scanId,
        field
    });
    if (!response.data.success) {
        throw new ApiError('ApiError', response.data.text, 'Error loading scan crop: ' + response.data.text);
    }
    return response.data.payload;
}
```

- [ ] **Step 2: `saveScanImages` um scanId-Parameter erweitern (Zeile 694)**

```javascript
// ALT:
async function saveScanImages(cId, cLn, originalImage, fieldImages, isPdf = false, tempImageId = null) {

// NEU:
async function saveScanImages(cId, cLn, originalImage, fieldImages, isPdf = false, tempImageId = null, scanId = null) {
```

Nach Zeile 703:

```javascript
if (tempImageId) payload.temp_image_id = tempImageId
if (scanId) payload.scan_id = scanId
```

- [ ] **Step 3: Neue Actions im return-Block exportieren**

`getScanTempCrop` und `getScanTempCropList` zum `return { ... }` hinzufügen.

- [ ] **Step 4: Commit**

```bash
tools/fix-ws.sh
git add src/features/lxcars/stores/lxcars.store.js
git commit -m "feat(lxcars): add getScanTempCrop/CropList store actions"
```

---

### Task 5: Frontend — Bilder lazy per Hover laden, scan_id durchreichen

**Files:**
- Modify: `src/features/lxcars/views/car/car.scan.view.vue`

- [ ] **Step 1: scanCrops computed ersetzen (Zeilen 1065-1078)**

Statt alle Bilder aus `scanResult.images` als data-URI zu bauen, werden Bilder einzeln beim Hover geladen und gecacht.

```javascript
// Geladene Crop-Bilder (lazy, pro Feld)
const loadedCrops = ref({})
const loadingCrops = ref({})

// Crop-Feld → API-Feldname Mapping (umgekehrt aus scanCropKeys)
const cropFieldMap = {}
for (const [field, keys] of Object.entries(scanCropKeys)) {
    for (const key of keys) {
        const cropName = key.replace(/[_]?[iI]mg$/, '')
        if (!cropFieldMap[field]) cropFieldMap[field] = []
        cropFieldMap[field].push(cropName)
    }
}

const scanCrops = computed(() => {
    // Upload-Flow: Bilder liegen direkt in scanResult.images (altes Format)
    const imgs = scanResult.value.images
    if (imgs && typeof imgs === 'object' && Object.keys(imgs).length > 0) {
        const result = {}
        for (const [field, keys] of Object.entries(scanCropKeys)) {
            for (const key of keys) {
                if (imgs[key]) {
                    result[field] = `data:image/jpeg;base64,${imgs[key]}`
                    break
                }
            }
        }
        return result
    }

    // Listen-Flow: Bilder aus tmp-Cache (lazy geladen)
    const result = {}
    for (const field of Object.keys(scanCropKeys)) {
        if (loadedCrops.value[field]) {
            result[field] = loadedCrops.value[field]
        } else if (availableCropFields.value.includes(field)) {
            result[field] = true  // Markiert: verfügbar, aber noch nicht geladen
        }
    }
    return result
})

// Verfügbare Crop-Felder aus dem tmp-Cache
const availableCropFields = ref([])

// Crop-Liste vom Server holen (nach getScanDetail)
async function loadCropFieldList(scanId) {
    if (!scanId) return
    try {
        const result = await carsStore.getScanTempCropList(scanId)
        // Server-Felder (z.B. 'hsn') auf Template-Felder (z.B. 'c_2') mappen
        const mapped = []
        for (const [field, cropNames] of Object.entries(cropFieldMap)) {
            if (cropNames.some(cn => result.fields.includes(cn))) {
                mapped.push(field)
            }
        }
        availableCropFields.value = mapped
    } catch {
        availableCropFields.value = []
    }
}

// Einzelnes Crop-Bild laden (wird beim Hover aufgerufen)
async function loadCropImage(field) {
    if (loadedCrops.value[field] || loadingCrops.value[field]) return
    const scanId = scanResult.value.raw?.scan_id
    if (!scanId) return

    const cropNames = cropFieldMap[field]
    if (!cropNames?.length) return

    loadingCrops.value[field] = true
    try {
        const data = await carsStore.getScanTempCrop(scanId, cropNames[0])
        if (data?.image) {
            loadedCrops.value[field] = `data:${data.mime || 'image/jpeg'};base64,${data.image}`
        }
    } catch {
        // Stille Fehlerbehandlung
    } finally {
        loadingCrops.value[field] = false
    }
}
```

- [ ] **Step 2: Template anpassen — Hover löst Laden aus**

Bei jedem Tooltip-Aktivator den `@mouseenter` Event hinzufügen, der das Bild lädt. Beispiel für `c_2` (HSN, Zeile 607-614):

```html
<!-- ALT: -->
<template v-if="scanCrops.c_2" #append-inner>
    <v-tooltip location="end" content-class="crop-tooltip">
        <template #activator="{ props: tipProps }">
            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1">mdi-image-outline</v-icon>
        </template>
        <img :src="scanCrops.c_2" class="crop-tooltip-img" />
    </v-tooltip>
</template>

<!-- NEU: -->
<template v-if="scanCrops.c_2" #append-inner>
    <v-tooltip location="end" content-class="crop-tooltip">
        <template #activator="{ props: tipProps }">
            <v-icon v-bind="tipProps" size="small" color="blue-lighten-2" class="cursor-pointer" tabindex="-1" @mouseenter="loadCropImage('c_2')">mdi-image-outline</v-icon>
        </template>
        <img v-if="typeof scanCrops.c_2 === 'string'" :src="scanCrops.c_2" class="crop-tooltip-img" />
        <v-progress-circular v-else indeterminate size="24" />
    </v-tooltip>
</template>
```

Das gleiche Muster für alle anderen Felder: `c_ln`, `c_3`, `d2`, `c_em`, `c_d`, `c_hu`, `c_fin`, `c_finchk`, `owner_firstname`, `owner_name`, `owner_address1`, `owner_address2`.

Jedes `<v-icon>` bekommt `@mouseenter="loadCropImage('FELDNAME')"` und das `<img>` wird mit `v-if="typeof scanCrops.FELDNAME === 'string'"` geschützt, mit einem `<v-progress-circular>` als Fallback.

- [ ] **Step 3: selectScan() — Crop-Liste nach Detail laden**

In `selectScan()` (Zeile 1393), nach dem `.then()` Block von `getScanDetail`, die Crop-Liste laden:

```javascript
// ALT (Zeile 1393-1415):
pendingDetailPromise = carsStore.getScanDetail(scan.scan_id)
    .then(async (detail) => {
        ...
    })
    .catch(err => console.error('Error loading scan detail:', err))

// NEU:
// Reset lazy-loaded crops
loadedCrops.value = {}
availableCropFields.value = []

pendingDetailPromise = carsStore.getScanDetail(scan.scan_id)
    .then(async (detail) => {
        normalizeScanResult(detail)
        mergeScanDetail(detail)
        applyHuExtrapolation()
        if (scanResult.value.owner?.name && !scanResult.value.owner.greeting) {
            await lookupGreeting(scanResult.value.owner.name)
        }
        if (!selectedCustomer.value) {
            const detailMatched = await tryAutoMatchCustomer()
            if (!detailMatched) {
                const name = formatOwnerName(scanResult.value.owner)
                if (name) searchCustomers(name)
            }
        }
        await checkDuplicates(scanResult.value.car || {})

        // Crop-Liste laden (welche Felder sind im tmp-Cache verfügbar?)
        loadCropFieldList(scan.scan_id)
    })
    .catch(err => console.error('Error loading scan detail:', err))
```

- [ ] **Step 4: mergeScanDetail() — images-Zeile entfernen (Zeile 1670)**

```javascript
// ENTFERNEN (Zeile 1670):
current.images = detail.images || current.images
```

`getScanDetail` liefert keine `images` mehr.

- [ ] **Step 5: onSaveClick() + saveNewCustomerDirect() — scan_id übergeben**

An allen `carsStore.saveScanImages()`-Aufrufen die `scan_id` als letzten Parameter anhängen und die Bedingung erweitern.

**Muster (an allen 3 Stellen: Zeilen ~1952, ~2090, ~2122):**

```javascript
// ALT:
const imgObj = scanResult.value.images
const hasFieldImages = imgObj && typeof imgObj === 'object' && Object.keys(imgObj).length > 0
if (hasFieldImages || scanResult.value.temp_image_id) {
    carsStore.saveScanImages(
        carId, cLn, null,
        hasFieldImages ? imgObj : {},
        false,
        scanResult.value.temp_image_id || null
    ).catch(...)
}

// NEU:
const imgObj = scanResult.value.images
const hasFieldImages = imgObj && typeof imgObj === 'object' && Object.keys(imgObj).length > 0
const scanIdForSave = scanResult.value.raw?.scan_id || null
if (hasFieldImages || scanResult.value.temp_image_id || scanIdForSave) {
    carsStore.saveScanImages(
        carId, cLn, null,
        hasFieldImages ? imgObj : {},
        false,
        scanResult.value.temp_image_id || null,
        scanIdForSave
    ).catch(...)
}
```

Stelle 4 — pendingScanData (Zeile ~1968): `scanId` hinzufügen:

```javascript
carsStore.pendingScanData = {
    ...bestehende Felder...,
    scanId: scanResult.value.raw?.scan_id || null
}
```

- [ ] **Step 6: Neue Variablen/Funktionen exportieren**

Im `return { ... }` Block am Ende des `setup()`: `loadCropImage` hinzufügen (wird im Template gebraucht).

- [ ] **Step 7: Commit**

```bash
tools/fix-ws.sh
git add src/features/lxcars/views/car/car.scan.view.vue
git commit -m "feat(lxcars): lazy load scan crops on hover from tmp cache"
```

---

### Task 6: Manueller Test

- [ ] **Step 1: Scan auswählen — prüfen dass Bilder in tmp landen**

Scan aus der Liste anklicken. Prüfen:
```bash
ls -la backend/tmp/<scan_id>/.crops/
```

- [ ] **Step 2: Network-Tab prüfen**

- `getScanDetail`: kleine JSON-Response, keine Base64-Bilder, schnell
- `getScanTempCropList`: gibt verfügbare Felder zurück
- `getScanTempCrop`: wird beim Hover einzeln aufgerufen

- [ ] **Step 3: Hover über Felder**

Über HSN-Icon hovern → Spinner kurz, dann Crop-Bild. Zweites Hovern: sofort da (gecacht in `loadedCrops`).

- [ ] **Step 4: Fahrzeug speichern**

Speichern → prüfen dass Bilder unter `fahrzeugschein/{c_id}/.crops/` landen und tmp aufgeräumt wird:
```bash
ls -la backend/data/autoprofis_gmbh/fahrzeugschein/<neue_c_id>/.crops/
ls -la backend/tmp/<scan_id>/  # sollte nicht mehr existieren
```

- [ ] **Step 5: Upload-Flow testen (Regression)**

Fahrzeugschein hochladen (nicht aus Liste). Bilder erscheinen sofort (altes Format über `scanResult.images`). Speichern funktioniert.
