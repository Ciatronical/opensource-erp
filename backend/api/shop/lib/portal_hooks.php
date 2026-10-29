<?php
// backend/api/shop/lib/portal_hooks.php
//
// Hier hängt sich der Shop an das Kundenportal (backend/api/portal/lib/
// hooks.php). Der Kern lädt diese Datei für jede aktive Erweiterung
// (portalExtensionsLoad) — im öffentlichen Zugang wie in der Admin-API der
// Webseiten; sie bringt ihre Abhängigkeiten deshalb selbst mit.

require_once __DIR__.'/shop.php';

// Anmeldung, Registrierung, Freischaltung: der mitgebrachte Warenkorb geht
// mit dem des Kunden zusammen
portalHookAdd('login', function ($db, string $uuid, int $customerId): void {
    cartMergeIntoCustomerCart($db, $uuid, $customerId);
});

// Status der Sitzung: Zahl der Warenkorbpositionen, und der Warenkorb wird
// nachgestempelt, damit er am Leben bleibt
portalHookAdd('context_status', function (array $status, $db, string $uuid, int $website): array {
    $zeile = $db->getOne(
        "WITH korb AS (
             UPDATE carts_hugoshop c SET active = NOW()
               FROM portal_context_oserp k
              WHERE k.uuid = :uuid AND c.uuid = k.cart_uuid
             RETURNING c.uuid
         )
         SELECT (SELECT COUNT(*) FROM cart_parts_hugoshop p WHERE p.cart_uuid = (SELECT uuid FROM korb)) AS cart_pos_count",
        [':uuid' => $uuid]
    );
    $status['cart_pos_count'] = (int)($zeile['cart_pos_count'] ?? 0);
    return $status;
});

// Steuerzone und Währung neuer Kunden aus den Shop-Einstellungen
portalHookAdd('customer_defaults', function (array $werte, $db): array {
    $werte['taxzone']  = shopConfigValue($db, 'shop_standard_taxzone', (string)$werte['taxzone']);
    $werte['currency'] = shopConfigValue($db, 'shop_standard_currency', (string)$werte['currency']);
    return $werte;
});

// Rechnungen: Zahlstand und Lieferstatus, Produktlinks der Positionen
portalHookAdd('invoice_list',      'shopInvoiceListDecorate');
portalHookAdd('invoice',           'shopInvoiceDecorate');
portalHookAdd('invoice_positions', 'shopInvoicePositionsDecorate');

// Mailvorlagen des Shops (Rechnung, Lieferstatus, Widerruf)
portalMailTemplateDirAdd(__DIR__.'/../templates');

// ── Veröffentlichung (dev/kundenportal.md, Stufe 4) ──

/**
 * Der HugoShop einer Webseite, 0 wenn keiner — für die Haken unten
 */
function shopChannelOfWebsiteOrZero($db, int $website): int {
    try {
        return shopChannelOfWebsite($db, $website);
    } catch (ApiError $e) {
        return 0;
    }
}

// Merkmale der Webseite: der Shop ist da, wenn ihr ein HugoShop zugeordnet ist
portalHookAdd('features', function (array $features, $db, int $website): array {
    if (shopChannelOfWebsiteOrZero($db, $website) > 0) {
        $features['shop'] = true;
    }
    return $features;
});

// Vorlagensätze des Shops (Produktseiten) — unter demselben Namen wie das Paket
portalHookAdd('template_sets', function (array $saetze, $db): array {
    foreach (shopTemplateSets() as $satz) {
        $saetze[] = ['name' => $satz['name'], 'title' => $satz['title'], 'source' => $satz['source']];
    }
    return $saetze;
});

// Paket: Shortcodes und Partials der Shop-Widgets, über das Paket des Kerns
portalHookAdd('publish_files', function (array $dateien, $db, int $website, string $satz): array {
    if (shopChannelOfWebsiteOrZero($db, $website) <= 0) {
        return $dateien;
    }
    portalKitLayer(shopTemplateMasterDir().'/standard/kit', $dateien);
    try {
        $eigener = shopTemplateDir($satz).'/kit';
        if ($eigener !== shopTemplateMasterDir().'/standard/kit') {
            portalKitLayer($eigener, $dateien);
        }
    } catch (ApiError $e) {
        // Kein Shop-Satz dieses Namens: das Paket des Kerns genügt
    }
    return $dateien;
});

// „Paket installieren“ einer Webseite mit HugoShop: der Kern liefert selbst
// (portalPublishWebsite). HugoCMS löscht bei der Übernahme, was die vorige
// Lieferung enthielt und diese nicht mehr — deshalb führt die Lieferung
// Produktseiten, Kategorieübersicht und Medien des Shops mit, mit denselben
// Prüfsummen wie der Lauf der Shop-Erweiterung (shopHugoCmsSync). HugoCMS
// fordert nur an, was ihm fehlt; Medien überträgt der Shop selbst in Stücken.
// Vorher zieht eine ältere Bereitstellung je Kanal auf die Webseite um
// (shopStagingDir) — der Haken läuft vor dem Paketabgleich des Kerns.
portalHookAdd('publish_entries', function (array $beitrag, $db, int $website, array $stand): array {
    $kanal = shopChannelOfWebsiteOrZero($db, $website);
    if ($kanal <= 0) {
        return $beitrag;
    }
    $freigaben = shopHugoCmsGrantsFrom($stand);
    if ('' !== $freigaben['fehler']) {
        // Ohne die Freigaben des Shops ginge die Lieferung ohne Produktseiten
        // hinaus und HugoCMS räumte sie ab — lieber gar nicht liefern
        throw new ApiError('SHOP_HUGOCMS_GRANTS', $freigaben['fehler']);
    }
    $freigaben = $freigaben['freigaben'];
    $wurzel = shopStagingDir($db, $kanal);

    $seiten = 0;
    $liste = shopHugoCmsManifest($wurzel, $freigaben, (array)($stand['data']['accept'] ?? []));
    foreach ($liste['dateien'] as $pfad => $pruefsumme) {
        if (str_starts_with($pfad, $freigaben['package'].'/')) {
            continue;   // das Paket liefert der Kern nach seinem Abgleich
        }
        $beitrag['eintraege'][$pfad] = ['sha256' => $pruefsumme];
        if ('md' === strtolower(pathinfo($pfad, PATHINFO_EXTENSION))) {
            $seiten++;
        }
    }

    $medien = shopHugoCmsMediaList($db, $freigaben);
    foreach ($medien['dateien'] as $pfad => $datei) {
        // Medien tragen ihre Größe: HugoCMS misst die Stücke daran
        $beitrag['eintraege'][$pfad] = ['sha256' => $datei['sha256'], 'size' => $datei['size']];
    }
    $stueckGroesse = (int)$freigaben['medien_stueck'] > 0
        ? max(65536, min(SHOP_HUGOCMS_MEDIA_CHUNK_BYTES, (int)$freigaben['medien_stueck']))
        : SHOP_HUGOCMS_MEDIA_CHUNK_BYTES;
    $beitrag['extern'] = function (string $syncId, string $pfad) use ($db, $kanal, $medien, $stueckGroesse): ?string {
        if (!isset($medien['dateien'][$pfad])) {
            return null;
        }
        return shopHugoCmsMediaUpload($db, $kanal, $syncId, $pfad, $medien['dateien'][$pfad], $stueckGroesse);
    };

    $beitrag['meldungen'][] = sprintf('Shop: %d Produktseiten und %d Medien in der Lieferung', $seiten, count($medien['dateien']));
    if ($medien['fehlend']) {
        $beitrag['meldungen'][] = 'Shop: Medien ohne Datei, nicht geliefert: '.implode(', ', array_slice($medien['fehlend'], 0, 10))
            .(count($medien['fehlend']) > 10 ? ' …' : '');
    }
    return $beitrag;
});

// ── Belege im Kundenkonto (portal/lib/documents.php) ──
//
// Bestellungen des Shops als eigene Belegart shop_orders: Rechnungen mit
// Zeile in ar_link_hugoshop. Schalter documents_shop_orders der Webseite,
// Vorgabe an. Hat die Art ihren Reiter, fallen diese Rechnungen aus dem
// Reiter „Rechnungen“ heraus — jeder Beleg steht nur einmal.

/**
 * Rechnungen des Kunden, die Shop-Bestellungen sind
 *
 * @return int[] ar.id
 */
function shopOrderInvoiceIds($db, int $customerId): array {
    return array_map('intval', array_column($db->getAll(
        "SELECT al.ar_id FROM ar_link_hugoshop al
           JOIN ar ON ar.id = al.ar_id
          WHERE ar.customer_id = :customer_id",
        [':customer_id' => $customerId]
    ) ?: [], 'ar_id'));
}

// Belegart „Bestellungen“ hinter den Rechnungen
portalHookAdd('document_types', function (array $typen, $db, int $website): array {
    $neu = [];
    foreach ($typen as $typ => $info) {
        $neu[$typ] = $info;
        if ('invoices' === $typ) {
            $neu['shop_orders'] = ['setting' => 'documents_shop_orders', 'print' => 'invoice', 'default' => true];
        }
    }
    return $neu;
});

// Zeilen: Bestellungen sind Rechnungen mit Shop-Verknüpfung; Rechnungen ohne
// sie, sobald die Bestellungen ihren eigenen Reiter haben
portalHookAdd('document_rows', function ($zeilen, $db, int $customerId, string $typ, int $website) {
    if ('shop_orders' === $typ) {
        $ids = shopOrderInvoiceIds($db, $customerId);
        return array_values(array_filter(
            documentInvoiceRows(customerInvoices($db, $customerId)['orders']),
            fn(array $r) => in_array((int)$r['id'], $ids, true)
        ));
    }
    if ('invoices' === $typ && is_array($zeilen) && in_array('shop_orders', websiteDocumentTypes($db, $website), true)) {
        $ids = shopOrderInvoiceIds($db, $customerId);
        return array_values(array_filter($zeilen, fn(array $r) => !in_array((int)$r['id'], $ids, true)));
    }
    return $zeilen;
});

// Ansicht einer Bestellung: die Rechnungsansicht, nur für Shop-Rechnungen
portalHookAdd('document_detail', function ($detail, $db, int $customerId, string $typ, int $id, int $website) {
    if ('shop_orders' !== $typ) {
        return $detail;
    }
    if (!in_array($id, shopOrderInvoiceIds($db, $customerId), true)) {
        return null;
    }
    return documentInvoiceDetail($db, $customerId, $typ, $id, $website);
});
