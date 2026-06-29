<?php
// backend/api/ebay/orders.php
//
// HTTP-Actions der eBay-Anbindung (Verbindung testen, manueller Abruf, Status).
// Der eigentliche Import liegt in import.php (auch vom CLI-Sweep genutzt).

require_once __DIR__.'/import.php';

/**
 * Testet die eBay-Verbindung: Token holen + 1 Probe-Abruf.
 *
 * @testdata {"action": "ebayTestConnection"}
 */
function ebayTestConnection($data) {
    $db  = DbhCompany::begin();
    $cfg = ebayLoadConfig($db);
    if (!ebayIsEnabled($cfg)) {
        resultInfo(false, 'EBAY_DISABLED', 'eBay-Anbindung ist nicht aktiviert (Einstellungen → eBay).');
        return;
    }
    // Token erzwingen (frischer Refresh) und einen Datensatz probeweise abrufen
    ebayGetToken($db, true);
    $resp = ebayApiGet($db, '/sell/fulfillment/v1/order', ['limit' => 1]);
    resultInfo(true, '', [
        'connected'   => true,
        'environment' => $cfg['ebay_environment'] ?? 'production',
        'orderTotal'  => intval($resp['total'] ?? 0),
    ]);
}

/**
 * Stoesst den eBay-Bestellimport manuell an (gleiche Kernlogik wie der Cron-Sweep).
 *
 * @testdata {"action": "ebaySyncOrders"}
 */
function ebaySyncOrders($data) {
    permit('invoice_edit');
    $db  = DbhCompany::begin();
    $cfg = ebayLoadConfig($db);
    if (!ebayIsEnabled($cfg)) {
        resultInfo(false, 'EBAY_DISABLED', 'eBay-Anbindung ist nicht aktiviert (Einstellungen → eBay).');
        return;
    }
    $summary = ebayImportOrders($db, $cfg);
    resultInfo(true, '', $summary);
}

/**
 * Liefert den eBay-Status: letzter Lauf + zuletzt importierte Bestellungen.
 *
 * @testdata {"action": "ebayGetStatus"}
 */
function ebayGetStatus($data) {
    $db  = DbhCompany::begin();
    $cfg = ebayLoadConfig($db);

    $counts = $db->getOne(
        "SELECT
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE posting_reason = 'posted') AS posted,
            COUNT(*) FILTER (WHERE posting_reason <> 'posted') AS unposted
         FROM ebay_orders"
    );

    $recent = $db->getAll(
        "SELECT e.ebay_order_id, e.buyer_username, e.total, e.posting_reason, e.itime,
                a.invnumber, c.name AS customer_name
         FROM ebay_orders e
         LEFT JOIN ar a ON a.id = e.ar_id
         LEFT JOIN customer c ON c.id = e.customer_id
         ORDER BY e.id DESC LIMIT 20"
    ) ?: [];

    resultInfo(true, '', [
        'enabled'    => ebayIsEnabled($cfg),
        'lastCheck'  => $cfg['ebay_order_last_check'] ?? null,
        'counts'     => $counts,
        'recent'     => $recent,
    ]);
}
