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

// „Paket installieren“ einer Webseite mit HugoShop: der Lauf der
// Shop-Erweiterung gleicht Paket und Produktseiten ab und baut
portalHookAdd('install_package', function ($ergebnis, $db, int $website) {
    $kanal = shopChannelOfWebsiteOrZero($db, $website);
    if ($kanal <= 0) {
        return null;
    }
    $id = shopQueueJob($db, 'sync_kit', '', SHOP_KIT_INSTALL, $kanal);
    if (0 === $id) {
        $offen = $db->getOne(
            "UPDATE batchjob_hugoshop b SET param = :param
              WHERE b.channel_id = CAST(:kanal AS integer) AND b.function = 'sync_kit'
                AND b.partnumber = '' AND b.result IS NULL
          RETURNING b.id",
            [':param' => SHOP_KIT_INSTALL, ':kanal' => $kanal]
        );
        $id = (int)($offen['id'] ?? 0);
    }
    if ($id <= 0) {
        throw new ApiError('SHOP_PUBLISH_START_FAILED', 'Der Auftrag ließ sich nicht anlegen.');
    }
    if (shopPublishStatus($db)['running']) {
        return ['queued' => true, 'started' => false, 'running' => true, 'job_id' => $id, 'channel_id' => $kanal];
    }
    $start = shopPublishStartBackground($db, [$id]);
    if ('' !== $start['fehler']) {
        throw new ApiError('SHOP_PUBLISH_START_FAILED', $start['fehler']);
    }
    return ['queued' => true, 'started' => true, 'running' => true, 'job_id' => $id, 'channel_id' => $kanal];
});
