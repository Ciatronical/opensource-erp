<?php
// backend/api/search/global_search.php

/**
 * Globale Schnellsuche über alle Entitätstypen
 *
 * Durchsucht Kunden, Lieferanten, Rechnungen, Aufträge, Angebote, Artikel
 * und optional Fahrzeuge (wenn lxcars Feature aktiv).
 *
 * @param string $data['query'] Suchbegriff (min 2 Zeichen)
 * @param array $data['features'] Aktive Features (optional, für lxcars-Check)
 * @testdata {"query": "Test", "features": ["lxcars"]}
 */
function globalSearch($data) {
    $db = DbhCompany::begin();
    $query = trim($data['query'] ?? '');
    $features = $data['features'] ?? [];

    if (strlen($query) < 2) {
        resultInfo(true, 'OK', []);
        return;
    }

    $results = [];
    $prefixQ = $query . '%';
    $containsQ = '%' . $query . '%';

    // ===== Kunden =====
    $customers = $db->getAll(
        "SELECT customer.id, customer.name, customer.customernumber, customer.email, customer.city
         FROM customer
         LEFT JOIN customer_ext ON customer_ext.customer_id = customer.id
         WHERE NOT COALESCE(customer.obsolete, false)
           AND (LOWER(customer.name) LIKE LOWER(:contains)
                OR LOWER(customer.customernumber) LIKE LOWER(:prefix)
                OR LOWER(customer.email) LIKE LOWER(:contains2)
                OR LOWER(customer_ext.keywords) LIKE LOWER(:contains3))
         ORDER BY customer.name
         LIMIT 5",
        [':contains' => $containsQ, ':prefix' => $prefixQ, ':contains2' => $containsQ, ':contains3' => $containsQ]
    );

    foreach ($customers ?: [] as $row) {
        $results[] = [
            'type' => 'customer',
            'id' => intval($row['id']),
            'title' => $row['name'],
            'subtitle' => trim($row['customernumber'] . ($row['city'] ? ' · ' . $row['city'] : '')),
            'route' => '/kunde/' . $row['id']
        ];
    }

    // ===== Lieferanten =====
    $vendors = $db->getAll(
        "SELECT vendor.id, vendor.name, vendor.vendornumber, vendor.email, vendor.city
         FROM vendor
         LEFT JOIN vendor_ext ON vendor_ext.vendor_id = vendor.id
         WHERE NOT COALESCE(vendor.obsolete, false)
           AND (LOWER(vendor.name) LIKE LOWER(:contains)
                OR LOWER(vendor.vendornumber) LIKE LOWER(:prefix)
                OR LOWER(vendor.email) LIKE LOWER(:contains2)
                OR LOWER(vendor_ext.keywords) LIKE LOWER(:contains3))
         ORDER BY vendor.name
         LIMIT 5",
        [':contains' => $containsQ, ':prefix' => $prefixQ, ':contains2' => $containsQ, ':contains3' => $containsQ]
    );

    foreach ($vendors ?: [] as $row) {
        $results[] = [
            'type' => 'vendor',
            'id' => intval($row['id']),
            'title' => $row['name'],
            'subtitle' => trim($row['vendornumber'] . ($row['city'] ? ' · ' . $row['city'] : '')),
            'route' => '/lieferant/' . $row['id']
        ];
    }

    // ===== Rechnungen =====
    $invoices = $db->getAll(
        "SELECT ar.id, ar.invnumber, ar.transdate, ar.amount, c.name AS customer_name
         FROM ar
         LEFT JOIN customer c ON c.id = ar.customer_id
         WHERE LOWER(ar.invnumber) LIKE LOWER(:prefix)
            OR LOWER(c.name) LIKE LOWER(:contains)
         ORDER BY ar.transdate DESC
         LIMIT 5",
        [':prefix' => $prefixQ, ':contains' => $containsQ]
    );

    foreach ($invoices ?: [] as $row) {
        $results[] = [
            'type' => 'invoice',
            'id' => intval($row['id']),
            'title' => $row['invnumber'],
            'subtitle' => trim($row['customer_name'] . ' · ' . $row['transdate']),
            'route' => '/rechnung/' . $row['id']
        ];
    }

    // ===== Aufträge =====
    $orders = $db->getAll(
        "SELECT oe.id, oe.ordnumber, oe.transdate, oe.amount, c.name AS customer_name
         FROM oe
         LEFT JOIN customer c ON c.id = oe.customer_id
         WHERE oe.record_type IN ('sales_order', 'sales_order_intake')
           AND (LOWER(oe.ordnumber) LIKE LOWER(:prefix)
                OR LOWER(c.name) LIKE LOWER(:contains))
         ORDER BY oe.transdate DESC
         LIMIT 5",
        [':prefix' => $prefixQ, ':contains' => $containsQ]
    );

    foreach ($orders ?: [] as $row) {
        $results[] = [
            'type' => 'order',
            'id' => intval($row['id']),
            'title' => $row['ordnumber'],
            'subtitle' => trim($row['customer_name'] . ' · ' . $row['transdate']),
            'route' => '/auftrag/' . $row['id']
        ];
    }

    // ===== Angebote =====
    $quotations = $db->getAll(
        "SELECT oe.id, oe.quonumber, oe.transdate, oe.amount, c.name AS customer_name
         FROM oe
         LEFT JOIN customer c ON c.id = oe.customer_id
         WHERE oe.record_type = 'sales_quotation'
           AND (LOWER(oe.quonumber) LIKE LOWER(:prefix)
                OR LOWER(c.name) LIKE LOWER(:contains))
         ORDER BY oe.transdate DESC
         LIMIT 5",
        [':prefix' => $prefixQ, ':contains' => $containsQ]
    );

    foreach ($quotations ?: [] as $row) {
        $results[] = [
            'type' => 'quotation',
            'id' => intval($row['id']),
            'title' => $row['quonumber'],
            'subtitle' => trim($row['customer_name'] . ' · ' . $row['transdate']),
            'route' => '/angebot/' . $row['id']
        ];
    }

    // ===== Artikel =====
    $articles = $db->getAll(
        "SELECT id, partnumber, description, unit, sellprice
         FROM parts
         WHERE NOT COALESCE(obsolete, false)
           AND (LOWER(partnumber) LIKE LOWER(:prefix)
                OR LOWER(description) LIKE LOWER(:contains))
         ORDER BY partnumber
         LIMIT 5",
        [':prefix' => $prefixQ, ':contains' => $containsQ]
    );

    foreach ($articles ?: [] as $row) {
        $results[] = [
            'type' => 'article',
            'id' => intval($row['id']),
            'title' => $row['partnumber'] . ' — ' . $row['description'],
            'subtitle' => trim(($row['sellprice'] ? number_format(floatval($row['sellprice']), 2, ',', '.') . ' €' : '') . ($row['unit'] ? ' / ' . $row['unit'] : '')),
            'route' => '/artikel/' . $row['id']
        ];
    }

    // ===== Fahrzeuge (nur wenn lxcars aktiv) =====
    if (in_array('lxcars', $features)) {
        $vehicles = $db->getAll(
            "SELECT cl.c_id, cl.c_ln, cl.c_fin, cl.c_mkb, c.name AS owner_name
             FROM cars_lxcars cl
             LEFT JOIN customer c ON c.id = cl.c_ow
             WHERE LOWER(cl.c_ln) LIKE LOWER(:q1)
                OR LOWER(cl.c_fin) LIKE LOWER(:q2)
                OR LOWER(cl.c_mkb) LIKE LOWER(:q3)
             ORDER BY cl.c_ln
             LIMIT 5",
            [':q1' => $containsQ, ':q2' => $containsQ, ':q3' => $containsQ]
        );

        foreach ($vehicles ?: [] as $row) {
            // ToDo: Fahrzeugbezeichnung (Marke/Modell Klartext) im Subtitle anzeigen
            $results[] = [
                'type' => 'vehicle',
                'id' => intval($row['c_id']),
                'title' => $row['c_ln'],
                'subtitle' => trim(($row['c_mkb'] ?: '') . ($row['owner_name'] ? ' · ' . $row['owner_name'] : '')),
                'route' => '/fahrzeug/' . $row['c_id']
            ];
        }
    }

    // ===== Wiki-Artikel (ab 5 Zeichen) =====
    if (strlen($query) >= 5) {
        $wikiPages = $db->getAll(
            "SELECT wp.id, wp.title, wc.name AS category_name
             FROM wiki_pages wp
             LEFT JOIN wiki_categories wc ON wc.id = wp.category_id
             WHERE LOWER(wp.title) LIKE LOWER(:contains)
                OR LOWER(wp.content) LIKE LOWER(:contains2)
             ORDER BY wp.mtime DESC NULLS LAST, wp.itime DESC
             LIMIT 5",
            [':contains' => $containsQ, ':contains2' => $containsQ]
        );

        foreach ($wikiPages ?: [] as $row) {
            $results[] = [
                'type' => 'wiki',
                'id' => intval($row['id']),
                'title' => $row['title'],
                'subtitle' => $row['category_name'] ?: '',
                'route' => '/wiki/' . $row['id']
            ];
        }
    }

    resultInfo(true, 'OK', $results);
}
