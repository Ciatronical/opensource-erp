<?php
// backend/api/accounting/customer_matching.php

/**
 * Alle Stellen, an denen ein Kunde haengen kann
 *
 * Eine Liste fuer beides: die Loeschbarkeitspruefung
 * (customerDeletableCondition) und das Umhaengen beim Zusammenfuehren
 * (mergeCustomers). Sonst laufen die zwei Listen auseinander und ein Kunde
 * gilt als loeschbar, obwohl noch etwas auf ihn zeigt.
 *
 * Tabelle => Spalten, die auf customer.id zeigen.
 *
 * @return array
 */
function customerReferences() {
    return [
        'ar'                           => ['customer_id', 'delivery_customer_id'],
        'oe'                           => ['customer_id', 'delivery_customer_id'],
        'delivery_orders'              => ['customer_id'],
        'reclamations'                 => ['customer_id'],
        'letter'                       => ['customer_id'],
        'letter_draft'                 => ['customer_id'],
        'record_templates'             => ['customer_id'],
        'project'                      => ['customer_id', 'billable_customer_id'],
        'requirement_specs'            => ['customer_id'],
        'part_customer_prices'         => ['customer_id'],
        'time_recordings'              => ['customer_id'],
        'additional_billing_addresses' => ['customer_id'],
        'shop_orders'                  => ['kivi_customer_id'],
        'accounting_bookings'          => ['customer_id'],
        'ebay_orders'                  => ['customer_id'],
        'weroni_documents'             => ['customer_id'],
        'whatsapp_messages'            => ['customer_id'],
        'whatsapp_reminder_log'        => ['customer_id'],
        'bank_matching_rules'          => ['action_customer_id']
    ];
}

/**
 * SQL-Bedingung: auf diesen Kunden zeigt kein Beleg und keine Regel mehr
 *
 * Gegenstück zu vendorDeletableCondition() in vendor_matching.php — gleiche
 * Bauart, gleiche Begründung: übergeben wird ein Spaltenausdruck (z. B.
 * 'c1.id'), kein Wert, damit die Bedingung mit der äusseren Abfrage
 * korreliert und keinen zusätzlichen Platzhalter braucht.
 *
 * Nur Tabellen, die es in dieser Firmen-DB gibt: Zusatzmodule wie eBay oder
 * WhatsApp sind nicht überall ausgerollt, und ein fehlender Tabellenname
 * lässt die ganze Abfrage schon beim Parsen scheitern (existingTables).
 *
 * Reine Anhänge zählen bewusst nicht mit: erweiterte Kontaktdaten,
 * Ansprechpartner, Lieferadressen, Merkmale, Wiedervorlagen und die
 * Anrufhistorie werden beim Zusammenführen mitgenommen bzw. von den
 * kivitendo-Triggern abgeräumt.
 *
 * EXISTS statt COUNT: bricht beim ersten Treffer ab.
 *
 * @param ApiDatabase $db          Offene Company-Verbindung
 * @param string      $customerRef Spaltenausdruck mit der Kunden-ID
 * @return string SQL-Bedingung, die TRUE ist wenn der Kunde löschbar ist
 */
function customerDeletableCondition($db, $customerRef) {
    $references = customerReferences();
    $used = [];

    foreach (existingTables($db, array_keys($references)) as $table) {
        $match = [];
        foreach ($references[$table] as $column) {
            $match[] = "{$column} = {$customerRef}";
        }
        $used[] = "SELECT 1 FROM {$table} WHERE " . implode(' OR ', $match);
    }

    if (!$used) return 'TRUE';

    return 'NOT EXISTS (' . implode(')
           AND NOT EXISTS (', $used) . ')';
}

/**
 * Kunden mit Dublettenprüfer laden
 *
 * Gegenstück zu getAccountingVendors(): Umsatz und Rechnungszahl kommen aus
 * den Ausgangsrechnungen (ar), das Standardkonto ist das zuletzt bebuchte
 * Erlöskonto des Kunden.
 *
 * @param string $data['query']            Suchbegriff (optional)
 * @param int    $data['limit']            Anzahl (Standard: 50)
 * @param bool   $data['include_obsolete'] Auch inaktive anzeigen
 * @testdata {"limit": 50}
 */
function getAccountingCustomers($data) {
    $db = DbhCompany::begin();

    $query = trim($data['query'] ?? '');
    $limit = intval($data['limit'] ?? 50);
    $includeObsolete = !empty($data['include_obsolete']);

    $where = $includeObsolete ? '1=1' : 'c.obsolete IS NOT TRUE';
    $params = [':limit' => $limit];

    if (!empty($query)) {
        $where .= " AND (c.name ILIKE :q OR c.customernumber ILIKE :q2 OR c.iban ILIKE :q3 OR c.taxnumber ILIKE :q4 OR c.city ILIKE :q5)";
        $params[':q']  = '%' . $query . '%';
        $params[':q2'] = $query . '%';
        $params[':q3'] = '%' . $query . '%';
        $params[':q4'] = '%' . $query . '%';
        $params[':q5'] = '%' . $query . '%';
    }

    $customers = $db->getAll(<<<SQL
        SELECT c.id, c.name, c.customernumber, c.street, c.zipcode, c.city,
               c.phone, c.email, c.iban, c.bic, c.taxnumber, c.ustid,
               c.obsolete, c.itime,
               TO_CHAR(c.itime, 'DD.MM.YYYY') AS created_fmt,
               COALESCE(inv.booking_count, 0) AS booking_count,
               inv.total_amount,
               acc.accno AS default_account
        FROM customer c
        LEFT JOIN (
            SELECT a.customer_id, COUNT(*) AS booking_count, SUM(a.amount) AS total_amount
            FROM ar a
            GROUP BY a.customer_id
        ) inv ON inv.customer_id = c.id
        LEFT JOIN LATERAL (
            SELECT ch.accno
            FROM ar a
            JOIN acc_trans t ON t.trans_id = a.id
            JOIN chart ch ON ch.id = t.chart_id
            WHERE a.customer_id = c.id AND ch.link LIKE '%AR_amount%'
            ORDER BY a.transdate DESC, t.acc_trans_id
            LIMIT 1
        ) acc ON TRUE
        WHERE {$where}
        ORDER BY c.name
        LIMIT :limit
    SQL, $params);

    resultInfo(true, '', ['customers' => $customers ?: []]);
}

/**
 * Potenzielle Kunden-Dubletten finden
 *
 * Anlegedatum, Rechnungszahl und Löschbarkeit beider Seiten kommen mit, damit
 * im Zusammenführen-Dialog entschieden werden kann, welcher Kunde bleibt und
 * ob der andere gelöscht statt nur stillgelegt werden kann.
 *
 * Die Paare entstehen ueber den Trigramm-Operator `%` statt ueber
 * `similarity(...) > schwellwert`. Inhaltlich ist das dasselbe — `%` ist genau
 * "Aehnlichkeit ueber dem Schwellwert" —, aber nur der Operator kann den
 * GIN-Index customer_name_gin_trgm_idx nutzen. Mit der Funktion muss
 * PostgreSQL jedes Kundenpaar einzeln ausrechnen: bei 4.200 aktiven Kunden
 * sind das rund 8,8 Mio. Paare und ueber 30 Sekunden — mehr als PHP an
 * Laufzeit erlaubt, die Suche lief also ins Zeitlimit. Ueber den Index sind
 * es unter einer Sekunde bei identischem Ergebnis.
 *
 * LOWER() faellt dabei weg: pg_trgm zerlegt ohnehin in Kleinbuchstaben,
 * similarity('ABC','abc') ist 1. Der Aufruf haette nur den Index blockiert.
 *
 * Der IBAN-Zweig steht als eigener UNION-Arm daneben. Als OR im selben WHERE
 * wuerde er die Indexnutzung des Namenszweigs wieder verhindern.
 *
 * @param float $data['threshold'] Schwellwert (Standard: 0.4)
 * @testdata {"threshold": 0.4}
 */
function findCustomerDuplicates($data) {
    $db = DbhCompany::begin();
    $threshold = floatval($data['threshold'] ?? 0.4);

    $c1Deletable = customerDeletableCondition($db, 'c1.id');
    $c2Deletable = customerDeletableCondition($db, 'c2.id');

    // `%` liest seinen Schwellwert aus pg_trgm.similarity_threshold. Der Wert
    // gilt nur fuer diese Transaktion (set_config mit is_local = true) — die
    // Verbindungen sind persistent, eine Sitzungseinstellung wuerde in den
    // naechsten Request durchschlagen.
    $db->beginTransaction();

    try {
        $db->execute(
            "SELECT set_config('pg_trgm.similarity_threshold', :threshold, true)",
            [':threshold' => (string)$threshold]
        );

        $duplicates = $db->getAll(<<<SQL
            WITH inv AS (
                SELECT customer_id, COUNT(*) AS booking_count
                FROM ar
                GROUP BY customer_id
            ),
            paare AS (
                SELECT c1.id AS id1, c2.id AS id2
                FROM customer c1
                JOIN customer c2 ON c2.id > c1.id
                WHERE c1.obsolete IS NOT TRUE AND c2.obsolete IS NOT TRUE
                  AND c1.name % c2.name
                UNION
                SELECT c1.id, c2.id
                FROM customer c1
                JOIN customer c2 ON c2.id > c1.id
                WHERE c1.obsolete IS NOT TRUE AND c2.obsolete IS NOT TRUE
                  AND c1.iban IS NOT NULL AND c1.iban <> ''
                  AND REPLACE(c1.iban, ' ', '') = REPLACE(c2.iban, ' ', '')
            )
            SELECT c1.id AS customer1_id, c1.name AS customer1_name, c1.city AS customer1_city,
                   TO_CHAR(c1.itime, 'DD.MM.YYYY') AS customer1_created,
                   COALESCE(i1.booking_count, 0) AS customer1_bookings,
                   ({$c1Deletable}) AS customer1_deletable,
                   c2.id AS customer2_id, c2.name AS customer2_name, c2.city AS customer2_city,
                   TO_CHAR(c2.itime, 'DD.MM.YYYY') AS customer2_created,
                   COALESCE(i2.booking_count, 0) AS customer2_bookings,
                   ({$c2Deletable}) AS customer2_deletable,
                   similarity(c1.name, c2.name) AS name_similarity,
                   CASE WHEN c1.iban IS NOT NULL AND c1.iban != '' AND c1.iban = c2.iban THEN TRUE ELSE FALSE END AS same_iban
            FROM paare p
            JOIN customer c1 ON c1.id = p.id1
            JOIN customer c2 ON c2.id = p.id2
            LEFT JOIN inv i1 ON i1.customer_id = c1.id
            LEFT JOIN inv i2 ON i2.customer_id = c2.id
            ORDER BY name_similarity DESC
            LIMIT 50
        SQL);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // PostgreSQL-Booleans kommen über PDO als 't'/'f' an — beides ist in
    // JavaScript wahr. Vor der Ausgabe in echte Booleans wandeln.
    foreach ($duplicates as &$dup) {
        foreach (['same_iban', 'customer1_deletable', 'customer2_deletable'] as $flag) {
            $dup[$flag] = $dup[$flag] === true || $dup[$flag] === 't';
        }
    }
    unset($dup);

    resultInfo(true, '', ['duplicates' => $duplicates ?: []]);
}

/**
 * Kunden zusammenführen (Deduplizierung)
 *
 * Zwei Wege, je nachdem ob der aufzulösende Kunde je benutzt wurde:
 *
 * 1. Nie benutzt (customerDeletableCondition) und `delete_merged` gesetzt:
 *    der Doppeleintrag wird gelöscht. Es gibt keinen Beleg, der ihn braucht,
 *    also bleibt keine Karteileiche in der Kundenauswahl zurück.
 *
 * 2. Sonst: der aufgelöste Kunde wird auf obsolete gesetzt. Sämtliche Belege
 *    beider Kunden hängen danach am beibehaltenen Kunden — es geht keine
 *    Rechnung verloren, auch wenn beide bebucht waren. Die Buchungssätze
 *    selbst (acc_trans) bleiben unberührt, sie hängen am Beleg.
 *
 * Anrufhistorie und erweiterte Kontaktdaten ziehen in beiden Fällen mit um.
 *
 * @param int  $data['keep_customer_id']  Kunde der beibehalten wird
 * @param int  $data['merge_customer_id'] Kunde der aufgelöst wird
 * @param bool $data['delete_merged']     Löschen statt stilllegen, sofern unbenutzt
 * @testdata {"keep_customer_id": 1, "merge_customer_id": 2, "delete_merged": true}
 */
function mergeCustomers($data) {
    $db = DbhCompany::begin();

    $keepId = intval($data['keep_customer_id'] ?? 0);
    $mergeId = intval($data['merge_customer_id'] ?? 0);
    $deleteMerged = filter_var($data['delete_merged'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!$keepId || !$mergeId || $keepId === $mergeId) {
        throw new ApiError('VALIDATION_ERROR', 'Zwei unterschiedliche customer_ids erforderlich');
    }

    $mergeDeletable = customerDeletableCondition($db, 'm.id');
    $info = $db->getOne(
        "SELECT k.name AS keep_name, m.name AS merge_name,
                ({$mergeDeletable}) AS deletable
         FROM customer k
         JOIN customer m ON m.id = :merge
         WHERE k.id = :keep",
        [':keep' => $keepId, ':merge' => $mergeId]
    );

    if (!$info) {
        throw new ApiError('DATA_NOT_FOUND', 'Kunde nicht gefunden');
    }

    $deletable = $info['deletable'] === true || $info['deletable'] === 't';

    if ($deleteMerged && $deletable) {
        deleteDuplicateCustomer($db, $keepId, $mergeId, $info);
        return;
    }

    // Alles in einer Anweisung: jede Referenz auf den aufzulösenden Kunden
    // wird umgehängt und er selbst auf obsolete gesetzt. Ein einziges
    // Statement heisst eine Transaktion und einen konsistenten Snapshot.
    //
    // Die Umhaenge-Zweige entstehen aus customerReferences(), gefiltert auf die
    // Tabellen dieser Firmen-DB — dieselbe Liste, die auch ueber die
    // Loeschbarkeit entscheidet. Jeder Zweig bekommt eigene Platzhalter: PDO
    // spricht mit PostgreSQL echt vorbereitet, da darf kein Name doppelt vorkommen.
    $references = customerReferences();
    $params = [];
    $ctes = [];
    $counts = [];

    $bind = function ($value) use (&$params) {
        $name = ':p' . count($params);
        $params[$name] = $value;
        return $name;
    };

    $ctes[] = 'keep AS (SELECT id, name FROM customer WHERE id = ' . $bind($keepId) . ')';
    $ctes[] = 'merged AS (SELECT id, name FROM customer WHERE id = ' . $bind($mergeId) . ')';

    // Ein UPDATE je Tabelle, nicht je Spalte: zwei CTEs auf dieselbe Tabelle
    // sehen denselben Snapshot. Steht derselbe Partner in einer Zeile in beiden
    // Spalten (Rechnungs- und Lieferadresse), setzt sonst nur eines der beiden
    // UPDATEs durch und die andere Spalte zeigt weiter auf den stillgelegten
    // Eintrag. CASE in einer Anweisung haengt beide Spalten sicher um.
    foreach (existingTables($db, array_keys($references)) as $table) {
        $sets = [];
        $where = [];
        foreach ($references[$table] as $column) {
            $sets[]  = "{$column} = CASE WHEN {$column} = " . $bind($mergeId) . " THEN " . $bind($keepId) . " ELSE {$column} END";
            $where[] = "{$column} = " . $bind($mergeId);
        }

        $cte = 'upd_' . count($ctes);
        $counts[$table] = $cte;
        $ctes[] = "{$cte} AS (
            UPDATE {$table} SET " . implode(', ', $sets) . "
            WHERE (" . implode(' OR ', $where) . ") AND EXISTS (SELECT 1 FROM keep) RETURNING 1
        )";
    }

    // Anrufhistorie: crmti kennt Kunden und Lieferanten in einer Spalte,
    // deshalb muss der Typ mitgeprueft werden — kein Fall fuer die Liste oben.
    $callsCte = null;
    if (existingTables($db, ['crmti'])) {
        $callsCte = 'upd_calls';
        $ctes[] = "{$callsCte} AS (
            UPDATE crmti SET crmti_caller_id = " . $bind($keepId) . "
            WHERE crmti_caller_id = " . $bind($mergeId) . " AND crmti_caller_typ = 'C'
              AND EXISTS (SELECT 1 FROM keep) RETURNING 1
        )";
    }

    // customer_ext ist pro Kunde eindeutig: nur übernehmen, wenn der
    // verbleibende Kunde noch keine erweiterten Kontaktdaten hat.
    if (existingTables($db, ['customer_ext'])) {
        $ctes[] = "upd_ext AS (
            UPDATE customer_ext SET customer_id = " . $bind($keepId) . "
            WHERE customer_id = " . $bind($mergeId) . "
              AND NOT EXISTS (SELECT 1 FROM customer_ext e WHERE e.customer_id = " . $bind($keepId) . ")
            RETURNING 1
        )";
    }

    $ctes[] = "set_obsolete AS (
        UPDATE customer SET obsolete = TRUE, mtime = NOW()
        WHERE id = " . $bind($mergeId) . " AND EXISTS (SELECT 1 FROM keep) RETURNING 1
    )";

    // Zaehler nur fuer Zweige, die es in dieser DB wirklich gibt
    $tally = function ($key) use ($counts) {
        return isset($counts[$key]) ? "(SELECT COUNT(*) FROM {$counts[$key]})" : '0';
    };

    $movedCalls = $callsCte ? "(SELECT COUNT(*) FROM {$callsCte})" : '0';

    $result = $db->getOne(
        'WITH ' . implode(",\n", $ctes) . "
        SELECT (SELECT name FROM keep)   AS kept_customer,
               (SELECT name FROM merged) AS merged_customer,
               " . $tally('ar')                  . " AS moved_invoices,
               " . $tally('oe')                  . " AS moved_orders,
               " . $tally('delivery_orders')     . " AS moved_delivery_orders,
               " . $tally('accounting_bookings') . " AS moved_bookings,
               {$movedCalls} AS moved_calls",
        $params
    );

    if (empty($result['kept_customer']) || empty($result['merged_customer'])) {
        throw new ApiError('DATA_NOT_FOUND', 'Kunde nicht gefunden');
    }

    resultInfo(true, 'Kunden zusammengeführt', [
        'kept_customer'         => $result['kept_customer'],
        'merged_customer'       => $result['merged_customer'],
        'merged_deleted'        => false,
        'moved_invoices'        => intval($result['moved_invoices']),
        'moved_orders'          => intval($result['moved_orders']),
        'moved_delivery_orders' => intval($result['moved_delivery_orders']),
        'moved_bookings'        => intval($result['moved_bookings']),
        'moved_calls'           => intval($result['moved_calls'])
    ]);
}

/**
 * Nie benutzten Doppeleintrag löschen statt stilllegen
 *
 * Interner Helfer von mergeCustomers(). Anrufhistorie und erweiterte
 * Kontaktdaten hängen ohne Fremdschlüssel am Kunden und würden sonst ins
 * Leere zeigen: die Historie zieht um, die Kontaktdaten des Doppels fallen
 * weg. Das abschliessende DELETE prüft die Löschbarkeit erneut, falls
 * zwischenzeitlich doch ein Beleg entstanden ist.
 *
 * @param ApiDatabase $db      Offene Company-Verbindung
 * @param int         $keepId  Kunde der bleibt
 * @param int         $mergeId Doppeleintrag der verschwindet
 * @param array       $info    Namen aus mergeCustomers()
 */
function deleteDuplicateCustomer($db, $keepId, $mergeId, $info) {
    $db->beginTransaction();

    try {
        $db->execute(
            "UPDATE crmti SET crmti_caller_id = :keep
             WHERE crmti_caller_id = :merge AND crmti_caller_typ = 'C'",
            [':keep' => $keepId, ':merge' => $mergeId]
        );

        $db->execute("DELETE FROM customer_ext WHERE customer_id = :merge", [':merge' => $mergeId]);

        $stillDeletable = customerDeletableCondition($db, 'c.id');
        $deleted = $db->getOne(
            "DELETE FROM customer c WHERE c.id = :merge AND ({$stillDeletable}) RETURNING c.id",
            [':merge' => $mergeId]
        );

        if (!$deleted) {
            $db->rollBack();
            throw new ApiError('CUSTOMER_IN_USE', 'Kunde wird inzwischen verwendet und kann nicht gelöscht werden');
        }

        $db->commit();
    } catch (ApiError $e) {
        throw $e;
    } catch (\Exception $e) {
        $db->rollBack();
        throw new ApiError('MERGE_ERROR', 'Löschen fehlgeschlagen: ' . $e->getMessage());
    }

    resultInfo(true, 'Doppelter Kunde gelöscht', [
        'kept_customer'   => $info['keep_name'],
        'merged_customer' => $info['merge_name'],
        'merged_deleted'  => true
    ]);
}
