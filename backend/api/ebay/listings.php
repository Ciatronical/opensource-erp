<?php
// backend/api/ebay/listings.php
//
// eBay-Outbound: Artikel als Listing einstellen/beenden und Artikelbilder
// verwalten. Genutzt von der Artikel-Bearbeitung (Checkbox "eBay-Artikel").
//
// Listing-Ablauf (eBay Inventory API):
//   1. PUT  /sell/inventory/v1/inventory_item/{sku}   (Produkt + Bilder + Zustand)
//   2. POST /sell/inventory/v1/offer                  (Preis, Kategorie, Policies)  -> offerId
//   3. POST /sell/inventory/v1/offer/{offerId}/publish -> listingId (live)
// Beenden: POST /sell/inventory/v1/offer/{offerId}/withdraw

require_once __DIR__.'/ebay.php';
require_once __DIR__.'/../customer_vendor/filemanager.php';

const EBAY_IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

/**
 * Bildordner eines Artikels (data/<db>/parts/<parts_id>/), legt ihn bei Bedarf an.
 */
function ebayPartImageDir($partsId) {
    $dir = fmDataDir() . '/parts/' . intval($partsId);
    if (!is_dir($dir)) {
        fmMkdir($dir);
    }
    return $dir;
}

/**
 * Aktueller Datenbankname (fuer die oeffentliche Bild-URL).
 */
function ebayCurrentDb($db) {
    $row = $db->getOne("SELECT current_database() AS d");
    return $row['d'] ?? '';
}

/**
 * Basis-URL fuer oeffentlich erreichbare Bilder. eBay verlangt https.
 * Optional ueberschreibbar via defaults_oserp.ebay_public_host.
 */
function ebayPublicBaseUrl($cfg) {
    $host = trim($cfg['ebay_public_host'] ?? '');
    if ($host === '') {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }
    return 'https://' . $host;
}

/**
 * Oeffentliche URLs aller Bilder eines Artikels (Reihenfolge sort, dann id).
 */
function ebayPartImageUrls($db, $partsId, $cfg) {
    $dbname  = ebayCurrentDb($db);
    $base    = ebayPublicBaseUrl($cfg);
    $rows = $db->getAll(
        "SELECT filename FROM ebay_part_images WHERE parts_id = :p ORDER BY sort, id",
        [':p' => intval($partsId)]
    ) ?: [];
    $urls = [];
    foreach ($rows as $r) {
        $urls[] = $base . '/webhook/part-image.php?db=' . urlencode($dbname)
                . '&id=' . intval($partsId) . '&f=' . urlencode($r['filename']);
    }
    return $urls;
}

/**
 * Laedt ein eBay-Listing als Artikelbild hoch.
 *
 * @param array $data['parts_id'] Artikel-ID
 * @param array $data['filename'] Originaldateiname (fuer die Endung)
 * @param array $data['data']     Base64 (optional als data:-URL)
 * @testdata {"action": "ebayUploadPartImage", "parts_id": 1, "filename": "foto.jpg", "data": "..."}
 */
function ebayUploadPartImage($data) {
    permit(['invoice_edit', 'sales_order_edit'], false);
    $db = DbhCompany::begin();

    $partsId  = intval($data['parts_id'] ?? 0);
    $filename = trim($data['filename'] ?? '');
    $raw      = $data['data'] ?? '';
    if ($partsId <= 0 || $raw === '') {
        throw new ApiError('EBAY_IMG_INVALID', 'Artikel-ID oder Bilddaten fehlen');
    }

    // data:-URL-Praefix entfernen
    if (strpos($raw, 'base64,') !== false) {
        $raw = substr($raw, strpos($raw, 'base64,') + 7);
    }
    $bin = base64_decode($raw, true);
    if ($bin === false || strlen($bin) === 0) {
        throw new ApiError('EBAY_IMG_INVALID', 'Bild konnte nicht dekodiert werden');
    }
    if (strlen($bin) > FM_MAX_FILESIZE) {
        throw new ApiError('EBAY_IMG_TOO_LARGE', 'Bild ist zu gross (max. 20 MB)');
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, EBAY_IMG_EXT, true)) {
        // Endung aus dem Bildinhalt ableiten
        $info = @getimagesizefromstring($bin);
        $mimeMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $ext = $mimeMap[$info['mime'] ?? ''] ?? '';
    }
    if (!in_array($ext, EBAY_IMG_EXT, true)) {
        throw new ApiError('EBAY_IMG_TYPE', 'Nur JPG, PNG, GIF oder WEBP erlaubt');
    }

    $dir   = ebayPartImageDir($partsId);
    $name  = sha1($bin) . '.' . $ext;
    $path  = $dir . '/' . $name;
    if (file_put_contents($path, $bin) === false) {
        throw new ApiError('EBAY_IMG_WRITE', 'Bild konnte nicht gespeichert werden');
    }
    @chmod($path, 0664);

    // Reihenfolge ans Ende, Duplikate (gleicher Hash) vermeiden
    $exists = $db->getOne(
        "SELECT id FROM ebay_part_images WHERE parts_id = :p AND filename = :f",
        [':p' => $partsId, ':f' => $name]
    );
    if (!$exists) {
        $next = $db->getOne("SELECT COALESCE(MAX(sort), -1) + 1 AS s FROM ebay_part_images WHERE parts_id = :p", [':p' => $partsId]);
        $db->execute(
            "INSERT INTO ebay_part_images (parts_id, filename, sort) VALUES (:p, :f, :s)",
            [':p' => $partsId, ':f' => $name, ':s' => intval($next['s'] ?? 0)]
        );
    }

    ebayListPartImages(['parts_id' => $partsId]);
}

/**
 * Listet die Bilder eines Artikels mit oeffentlicher URL.
 *
 * @param array $data['parts_id']
 * @testdata {"action": "ebayListPartImages", "parts_id": 1}
 */
function ebayListPartImages($data) {
    $db = DbhCompany::begin();
    $partsId = intval($data['parts_id'] ?? 0);
    $cfg = ebayLoadConfig($db);
    $dbname = ebayCurrentDb($db);
    $base = ebayPublicBaseUrl($cfg);

    $rows = $db->getAll(
        "SELECT id, filename, sort FROM ebay_part_images WHERE parts_id = :p ORDER BY sort, id",
        [':p' => $partsId]
    ) ?: [];
    foreach ($rows as &$r) {
        $r['url'] = $base . '/webhook/part-image.php?db=' . urlencode($dbname)
                  . '&id=' . $partsId . '&f=' . urlencode($r['filename']);
    }
    resultInfo(true, '', ['images' => $rows]);
}

/**
 * Loescht ein Artikelbild (Datei + Datensatz).
 *
 * @param array $data['image_id']
 * @testdata {"action": "ebayDeletePartImage", "image_id": 1}
 */
function ebayDeletePartImage($data) {
    permit(['invoice_edit', 'sales_order_edit'], false);
    $db = DbhCompany::begin();
    $imageId = intval($data['image_id'] ?? 0);

    $row = $db->getOne("SELECT parts_id, filename FROM ebay_part_images WHERE id = :id", [':id' => $imageId]);
    if ($row) {
        $path = fmDataDir() . '/parts/' . intval($row['parts_id']) . '/' . basename($row['filename']);
        if (is_file($path)) @unlink($path);
        $db->execute("DELETE FROM ebay_part_images WHERE id = :id", [':id' => $imageId]);
    }
    resultInfo(true, '', ['deleted' => true]);
}

// =========================================================================
// Listing (Inventory API)
// =========================================================================

/**
 * Schreibt/aktualisiert die ebay_listings-Zeile eines Artikels.
 */
function ebayUpsertListing($db, $partsId, $fields) {
    $cols = array_keys($fields);
    $set  = [];
    $params = [':p' => intval($partsId)];
    foreach ($fields as $k => $v) {
        $set[] = "$k = :$k";
        $params[":$k"] = $v;
    }
    $insertCols = array_merge(['parts_id'], $cols);
    $insertVals = array_merge([':p'], array_map(fn($c) => ":$c", $cols));
    $db->execute(
        "INSERT INTO ebay_listings (" . implode(', ', $insertCols) . ")
         VALUES (" . implode(', ', $insertVals) . ")
         ON CONFLICT (parts_id) DO UPDATE SET " . implode(', ', $set) . ", mtime = now()",
        $params
    );
}

/**
 * Stellt einen Artikel bei eBay ein (Inventory Item -> Offer -> Publish).
 * Bei Validierungsfehlern wird status='error' + Meldung gespeichert und
 * zurueckgegeben (kein stilles Scheitern).
 *
 * @return array ['ok'=>bool, 'status'=>string, 'message'=>string, 'listing_id'=>?string]
 */
function ebayPublishPart($db, $partsId) {
    $cfg = ebayLoadConfig($db);

    $part = $db->getOne(
        "SELECT id, partnumber, description, notes, sellprice FROM parts WHERE id = :id",
        [':id' => intval($partsId)]
    );
    if (!$part) {
        throw new ApiError('EBAY_PART_NOT_FOUND', 'Artikel nicht gefunden');
    }

    $sku = trim($part['partnumber'] ?? '');
    if ($sku === '') {
        return ebayListingFail($db, $partsId, null, 'Artikel hat keine Artikelnummer (SKU erforderlich).');
    }

    $imageUrls = ebayPartImageUrls($db, $partsId, $cfg);
    if (empty($imageUrls)) {
        return ebayListingFail($db, $partsId, $sku, 'Mindestens ein Artikelbild ist erforderlich.');
    }

    $category = trim($cfg['ebay_default_category_id'] ?? '');
    $location = trim($cfg['ebay_merchant_location_key'] ?? '');
    $payPol   = trim($cfg['ebay_payment_policy_id'] ?? '');
    $retPol   = trim($cfg['ebay_return_policy_id'] ?? '');
    $fulPol   = trim($cfg['ebay_fulfillment_policy_id'] ?? '');
    $mkt      = trim($cfg['ebay_marketplace_id'] ?? 'EBAY_DE');
    $cond     = trim($cfg['ebay_default_condition'] ?? 'NEW');
    $qty      = max(1, intval($cfg['ebay_listing_quantity'] ?? 1));
    $currency = trim($cfg['ebay_currency'] ?? 'EUR');

    $missing = [];
    if ($category === '') $missing[] = 'Kategorie';
    if ($location === '') $missing[] = 'Lagerort';
    if ($payPol === '')   $missing[] = 'Zahlungs-Policy';
    if ($retPol === '')   $missing[] = 'Ruecknahme-Policy';
    if ($fulPol === '')   $missing[] = 'Versand-Policy';
    if (!empty($missing)) {
        return ebayListingFail($db, $partsId, $sku, 'eBay-Konfiguration unvollstaendig: ' . implode(', ', $missing) . ' (Einstellungen → eBay).');
    }

    $title = trim($part['description'] ?? '');
    if ($title === '') $title = $sku;
    $title = mb_substr($title, 0, 80);
    $longDesc = trim($part['notes'] ?? '');
    if ($longDesc === '') $longDesc = $title;
    $price = number_format((float)$part['sellprice'], 2, '.', '');

    $skuPath = rawurlencode($sku);

    // Schritt 1: Inventory Item
    $invBody = [
        'availability' => ['shipToLocationAvailability' => ['quantity' => $qty]],
        'condition'    => $cond,
        'product'      => [
            'title'       => $title,
            'description' => $longDesc,
            'imageUrls'   => $imageUrls,
        ],
    ];
    $r1 = ebayApiSend($db, 'PUT', '/sell/inventory/v1/inventory_item/' . $skuPath, $invBody);
    if ($r1['status'] >= 400) {
        return ebayListingFail($db, $partsId, $sku, 'Produktdaten abgelehnt: ' . ebayErrorMessage($r1));
    }

    // Schritt 2: Offer (bestehende SKU-Offer wiederverwenden, sonst neu)
    $offerId = null;
    $existing = ebayApiSend($db, 'GET', '/sell/inventory/v1/offer?sku=' . $skuPath . '&marketplace_id=' . rawurlencode($mkt) . '&limit=1');
    if ($existing['status'] < 400 && !empty($existing['body']['offers'][0]['offerId'])) {
        $offerId = $existing['body']['offers'][0]['offerId'];
    }

    $offerBody = [
        'sku'                 => $sku,
        'marketplaceId'       => $mkt,
        'format'              => 'FIXED_PRICE',
        'availableQuantity'   => $qty,
        'categoryId'          => $category,
        'listingDescription'  => $longDesc,
        'listingPolicies'     => [
            'paymentPolicyId'     => $payPol,
            'returnPolicyId'      => $retPol,
            'fulfillmentPolicyId' => $fulPol,
        ],
        'pricingSummary'      => ['price' => ['currency' => $currency, 'value' => $price]],
        'merchantLocationKey' => $location,
    ];

    if ($offerId) {
        $r2 = ebayApiSend($db, 'PUT', '/sell/inventory/v1/offer/' . rawurlencode($offerId), $offerBody);
        if ($r2['status'] >= 400) {
            return ebayListingFail($db, $partsId, $sku, 'Angebot (Update) abgelehnt: ' . ebayErrorMessage($r2));
        }
    } else {
        $r2 = ebayApiSend($db, 'POST', '/sell/inventory/v1/offer', $offerBody);
        if ($r2['status'] >= 400 || empty($r2['body']['offerId'])) {
            return ebayListingFail($db, $partsId, $sku, 'Angebot abgelehnt: ' . ebayErrorMessage($r2));
        }
        $offerId = $r2['body']['offerId'];
    }

    // Schritt 3: Publish
    $r3 = ebayApiSend($db, 'POST', '/sell/inventory/v1/offer/' . rawurlencode($offerId) . '/publish');
    if ($r3['status'] >= 400) {
        ebayUpsertListing($db, $partsId, ['sku' => $sku, 'offer_id' => $offerId, 'status' => 'error', 'message' => ebayErrorMessage($r3)]);
        return ['ok' => false, 'status' => 'error', 'message' => 'Veroeffentlichen abgelehnt: ' . ebayErrorMessage($r3), 'listing_id' => null];
    }
    $listingId = $r3['body']['listingId'] ?? null;

    ebayUpsertListing($db, $partsId, [
        'sku'        => $sku,
        'offer_id'   => $offerId,
        'listing_id' => $listingId,
        'status'     => 'active',
        'message'    => '',
    ]);
    return ['ok' => true, 'status' => 'active', 'message' => '', 'listing_id' => $listingId];
}

/**
 * Schreibt einen Fehlerstatus und liefert das Fehlerergebnis.
 */
function ebayListingFail($db, $partsId, $sku, $message) {
    ebayUpsertListing($db, $partsId, ['sku' => $sku, 'status' => 'error', 'message' => $message]);
    return ['ok' => false, 'status' => 'error', 'message' => $message, 'listing_id' => null];
}

/**
 * Beendet das eBay-Listing eines Artikels (withdraw).
 *
 * @return array ['ok'=>bool, 'status'=>string, 'message'=>string]
 */
function ebayEndPart($db, $partsId) {
    $row = $db->getOne("SELECT offer_id FROM ebay_listings WHERE parts_id = :p", [':p' => intval($partsId)]);
    if ($row && !empty($row['offer_id'])) {
        $r = ebayApiSend($db, 'POST', '/sell/inventory/v1/offer/' . rawurlencode($row['offer_id']) . '/withdraw');
        if ($r['status'] >= 400) {
            // 404 = bereits offline; als beendet behandeln, sonst Fehler melden
            if ($r['status'] !== 404) {
                ebayUpsertListing($db, $partsId, ['status' => 'error', 'message' => 'Beenden abgelehnt: ' . ebayErrorMessage($r)]);
                return ['ok' => false, 'status' => 'error', 'message' => ebayErrorMessage($r)];
            }
        }
    }
    ebayUpsertListing($db, $partsId, ['status' => 'ended', 'message' => '']);
    return ['ok' => true, 'status' => 'ended', 'message' => ''];
}

/**
 * Setzt den eBay-Listing-Status eines Artikels (Checkbox "eBay-Artikel").
 *
 * @param array $data['parts_id']
 * @param array $data['listed']   true = einstellen, false = beenden
 * @testdata {"action": "ebaySetPartListed", "parts_id": 1, "listed": true}
 */
function ebaySetPartListed($data) {
    permit(['invoice_edit', 'sales_order_edit'], false);
    $db = DbhCompany::begin();

    $partsId = intval($data['parts_id'] ?? 0);
    $listed  = filter_var($data['listed'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($partsId <= 0) {
        throw new ApiError('EBAY_PART_NOT_FOUND', 'Artikel-ID fehlt');
    }

    $result = $listed ? ebayPublishPart($db, $partsId) : ebayEndPart($db, $partsId);
    // Erfolg auch bei Validierungsfehler "true" zurueckgeben, damit die UI die
    // Meldung anzeigt; ok-Flag transportiert das eigentliche Ergebnis.
    resultInfo(true, '', $result);
}

/**
 * Liefert den eBay-Listing-Status eines Artikels fuer die UI.
 *
 * @param array $data['parts_id']
 * @testdata {"action": "ebayGetPartListing", "parts_id": 1}
 */
function ebayGetPartListing($data) {
    $db = DbhCompany::begin();
    $partsId = intval($data['parts_id'] ?? 0);
    $row = $db->getOne(
        "SELECT status, offer_id, listing_id, message, mtime FROM ebay_listings WHERE parts_id = :p",
        [':p' => $partsId]
    );
    resultInfo(true, '', [
        'listed'     => $row ? ($row['status'] === 'active') : false,
        'status'     => $row['status'] ?? null,
        'listing_id' => $row['listing_id'] ?? null,
        'message'    => $row['message'] ?? '',
    ]);
}
