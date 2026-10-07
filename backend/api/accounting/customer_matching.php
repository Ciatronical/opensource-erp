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
 * Erlöskonto des Kunden. Die Löschbarkeit kommt mit, damit auch von Hand
 * ausgewählte Kunden ohne weitere Abfrage zusammengeführt werden können.
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

    $deletable = customerDeletableCondition($db, 'c.id');

    $customers = $db->getAll(<<<SQL
        SELECT c.id, c.name, c.customernumber, c.street, c.zipcode, c.city,
               c.phone, c.email, c.iban, c.bic, c.taxnumber, c.ustid,
               c.obsolete, c.itime,
               TO_CHAR(c.itime, 'DD.MM.YYYY') AS created_fmt,
               COALESCE(inv.booking_count, 0) AS booking_count,
               inv.total_amount,
               acc.accno AS default_account,
               ({$deletable}) AS deletable
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

    // PostgreSQL-Booleans kommen über PDO als 't'/'f' an — beides ist in
    // JavaScript wahr. Vor der Ausgabe in echte Booleans wandeln.
    foreach ($customers ?: [] as &$c) {
        $c['deletable'] = $c['deletable'] === true || $c['deletable'] === 't';
        $c['obsolete']  = $c['obsolete'] === true || $c['obsolete'] === 't';
    }
    unset($c);

    resultInfo(true, '', ['customers' => $customers ?: []]);
}

/**
 * Potenzielle Kunden-Dubletten finden — als Gruppen, nicht als Paare
 *
 * Die Datenbank liefert Paare. Ist ein Kunde dreimal angelegt, sind das drei
 * Paare (A-B, A-C, B-C), in denen derselbe Kunde immer wieder auftaucht; der
 * Anwender müsste dreimal nacheinander zusammenführen. Deshalb werden die
 * Paare hier zu Gruppen verbunden: alles, was direkt oder über Zwischenglieder
 * zusammenhängt, ist eine Gruppe und wird in einem Schritt zusammengeführt.
 *
 * Jedes Mitglied bringt Kundennummer, Strasse, Ort, Anlegedatum, Rechnungszahl
 * und Löschbarkeit mit, damit gleichnamige Einträge im Dialog unterscheidbar
 * sind und entschieden werden kann, welcher bleibt und ob der Rest gelöscht
 * statt nur stillgelegt werden darf.
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

        // Gleiche IBAN: mit und ohne Leerzeichen vergleichen — so wie die Paare
        // entstehen. Sonst zeigt die Anzeige "69 % Ähnlichkeit", obwohl das
        // Paar gerade wegen der IBAN gefunden wurde.
        $pairs = $db->getAll(<<<SQL
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
                   c1.customernumber AS customer1_number, c1.street AS customer1_street,
                   TO_CHAR(c1.itime, 'DD.MM.YYYY') AS customer1_created,
                   COALESCE(i1.booking_count, 0) AS customer1_bookings,
                   ({$c1Deletable}) AS customer1_deletable,
                   c2.id AS customer2_id, c2.name AS customer2_name, c2.city AS customer2_city,
                   c2.customernumber AS customer2_number, c2.street AS customer2_street,
                   TO_CHAR(c2.itime, 'DD.MM.YYYY') AS customer2_created,
                   COALESCE(i2.booking_count, 0) AS customer2_bookings,
                   ({$c2Deletable}) AS customer2_deletable,
                   similarity(c1.name, c2.name) AS name_similarity,
                   (c1.iban IS NOT NULL AND c1.iban <> ''
                    AND REPLACE(c1.iban, ' ', '') = REPLACE(c2.iban, ' ', '')) AS same_iban
            FROM paare p
            JOIN customer c1 ON c1.id = p.id1
            JOIN customer c2 ON c2.id = p.id2
            LEFT JOIN inv i1 ON i1.customer_id = c1.id
            LEFT JOIN inv i2 ON i2.customer_id = c2.id
            ORDER BY name_similarity DESC
            LIMIT 200
        SQL);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    resultInfo(true, '', ['groups' => groupDuplicatePairs($pairs ?: [], 'customer')]);
}

/**
 * Dubletten-Paare zu Gruppen verbinden
 *
 * Union-Find über die Paare: jedes Paar verbindet zwei Einträge, alles was
 * zusammenhängt landet in einer Gruppe. Die Gruppe trägt die höchste
 * Namensähnlichkeit ihrer Paare und ob irgendein Paar über die IBAN gefunden
 * wurde. Sortiert wird nach IBAN-Treffer, dann Ähnlichkeit — die sichersten
 * Treffer zuerst.
 *
 * Die Mitglieder einer Gruppe stehen nach Rechnungszahl absteigend, bei
 * Gleichstand nach ID aufsteigend: der erste ist damit immer der Vorschlag,
 * welcher Eintrag bleiben soll (am wenigsten umzuhängen, sonst der älteste).
 *
 * @param array  $pairs  Zeilen aus findCustomerDuplicates()
 * @param string $prefix Spaltenpräfix ('customer')
 * @return array Gruppen mit members, same_iban, similarity
 */
function groupDuplicatePairs(array $pairs, string $prefix) {
    $parent = [];
    $find = function ($id) use (&$parent, &$find) {
        if (!isset($parent[$id])) $parent[$id] = $id;
        return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
    };

    $members = [];
    $pairInfo = [];
    foreach ($pairs as $row) {
        foreach ([1, 2] as $n) {
            $id = intval($row["{$prefix}{$n}_id"]);
            $members[$id] = [
                'id'             => $id,
                'name'           => $row["{$prefix}{$n}_name"],
                'customernumber' => $row["{$prefix}{$n}_number"],
                'street'         => $row["{$prefix}{$n}_street"],
                'city'           => $row["{$prefix}{$n}_city"],
                'created'        => $row["{$prefix}{$n}_created"],
                'bookings'       => intval($row["{$prefix}{$n}_bookings"]),
                'deletable'      => $row["{$prefix}{$n}_deletable"] === true || $row["{$prefix}{$n}_deletable"] === 't'
            ];
        }
        $a = $find(intval($row["{$prefix}1_id"]));
        $b = $find(intval($row["{$prefix}2_id"]));
        if ($a !== $b) $parent[$a] = $b;
        $pairInfo[] = [
            'ids'        => [intval($row["{$prefix}1_id"]), intval($row["{$prefix}2_id"])],
            'similarity' => floatval($row['name_similarity']),
            'same_iban'  => $row['same_iban'] === true || $row['same_iban'] === 't'
        ];
    }

    $groups = [];
    foreach ($members as $id => $member) {
        $groups[$find($id)]['members'][] = $member;
    }
    foreach ($pairInfo as $pair) {
        $root = $find($pair['ids'][0]);
        $groups[$root]['similarity'] = max($groups[$root]['similarity'] ?? 0, $pair['similarity']);
        $groups[$root]['same_iban']  = ($groups[$root]['same_iban'] ?? false) || $pair['same_iban'];
    }

    $groups = array_values($groups);
    foreach ($groups as &$group) {
        usort($group['members'], fn($x, $y) => [$y['bookings'], $x['id']] <=> [$x['bookings'], $y['id']]);
        $group['same_iban']  = $group['same_iban'] ?? false;
        $group['similarity'] = $group['similarity'] ?? 0;
    }
    unset($group);
    usort($groups, fn($x, $y) => [$y['same_iban'], $y['similarity']] <=> [$x['same_iban'], $x['similarity']]);

    return $groups;
}

/**
 * Kunden zusammenführen (Deduplizierung)
 *
 * Ein Kunde bleibt, beliebig viele andere gehen in ihm auf — alles in einer
 * Transaktion, damit eine Dreiergruppe nicht halb zusammengeführt stehen
 * bleibt, wenn ein Mitglied scheitert. Für jeden aufzulösenden Kunden gibt es
 * zwei Wege, je nachdem ob er je benutzt wurde:
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
 * Ansprechpartner, Lieferadressen, Anrufhistorie und erweiterte Kontaktdaten
 * ziehen in beiden Fällen zum verbleibenden Kunden um.
 *
 * @param int   $data['keep_customer_id']   Kunde der beibehalten wird
 * @param int[] $data['merge_customer_ids'] Kunden die aufgelöst werden
 * @param int   $data['merge_customer_id']  Einzelner Kunde (Altform)
 * @param bool  $data['delete_merged']      Löschen statt stilllegen, sofern unbenutzt
 * @testdata {"keep_customer_id": 1, "merge_customer_ids": [2, 3], "delete_merged": true}
 */
function mergeCustomers($data) {
    $db = DbhCompany::begin();

    $keepId = intval($data['keep_customer_id'] ?? 0);
    $mergeIds = $data['merge_customer_ids'] ?? [];
    if (!is_array($mergeIds)) $mergeIds = [$mergeIds];
    if (!empty($data['merge_customer_id'])) $mergeIds[] = $data['merge_customer_id'];
    $mergeIds = array_values(array_unique(array_filter(array_map('intval', $mergeIds), fn($id) => $id > 0 && $id !== $keepId)));
    $deleteMerged = filter_var($data['delete_merged'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!$keepId || !$mergeIds) {
        throw new ApiError('VALIDATION_ERROR', 'Ein verbleibender und mindestens ein aufzulösender Kunde erforderlich');
    }

    $keep = $db->getOne("SELECT id, name FROM customer WHERE id = :keep", [':keep' => $keepId]);
    if (!$keep) {
        throw new ApiError('DATA_NOT_FOUND', 'Kunde nicht gefunden');
    }

    $mergeDeletable = customerDeletableCondition($db, 'm.id');
    $totals = ['moved_invoices' => 0, 'moved_orders' => 0, 'moved_delivery_orders' => 0, 'moved_bookings' => 0, 'moved_calls' => 0];
    $merged = [];

    $db->beginTransaction();
    try {
        foreach ($mergeIds as $mergeId) {
            $info = $db->getOne(
                "SELECT m.name, ({$mergeDeletable}) AS deletable FROM customer m WHERE m.id = :merge",
                [':merge' => $mergeId]
            );
            if (!$info) {
                throw new ApiError('DATA_NOT_FOUND', "Kunde $mergeId nicht gefunden");
            }
            $deletable = $info['deletable'] === true || $info['deletable'] === 't';

            if ($deleteMerged && $deletable) {
                deleteDuplicateCustomer($db, $keepId, $mergeId);
                $merged[] = ['id' => $mergeId, 'name' => $info['name'], 'deleted' => true];
                continue;
            }

            $counts = obsoleteDuplicateCustomer($db, $keepId, $mergeId);
            foreach ($totals as $key => $sum) {
                $totals[$key] = $sum + $counts[$key];
            }
            $merged[] = ['id' => $mergeId, 'name' => $info['name'], 'deleted' => false];
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        if ($e instanceof ApiError) throw $e;
        throw new ApiError('MERGE_ERROR', 'Zusammenführen fehlgeschlagen: ' . $e->getMessage());
    }

    $allDeleted = !in_array(false, array_column($merged, 'deleted'), true);
    resultInfo(true, $allDeleted ? 'Doppelte Kunden gelöscht' : 'Kunden zusammengeführt', [
        'kept_customer'   => $keep['name'],
        'merged'          => $merged,
        'merged_customer' => implode(', ', array_column($merged, 'name')),
        'merged_deleted'  => $allDeleted
    ] + $totals);
}

/**
 * Einen Kunden stilllegen und alles, was an ihm hängt, umhängen
 *
 * Interner Helfer von mergeCustomers(), läuft in dessen Transaktion. Alles in
 * einer Anweisung: jede Referenz auf den aufzulösenden Kunden wird umgehängt
 * und er selbst auf obsolete gesetzt — ein konsistenter Snapshot.
 *
 * Die Umhaenge-Zweige entstehen aus customerReferences(), gefiltert auf die
 * Tabellen dieser Firmen-DB — dieselbe Liste, die auch ueber die
 * Loeschbarkeit entscheidet. Jeder Zweig bekommt eigene Platzhalter: PDO
 * spricht mit PostgreSQL echt vorbereitet, da darf kein Name doppelt vorkommen.
 *
 * @param ApiDatabase $db      Offene Company-Verbindung (in Transaktion)
 * @param int         $keepId  Kunde der bleibt
 * @param int         $mergeId Kunde der stillgelegt wird
 * @return array Zähler der umgehängten Belege
 */
function obsoleteDuplicateCustomer($db, $keepId, $mergeId) {
    $references = customerReferences();
    $params = [];
    $ctes = [];
    $counts = [];

    $bind = function ($value) use (&$params) {
        $name = ':p' . count($params);
        $params[$name] = $value;
        return $name;
    };

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
            WHERE " . implode(' OR ', $where) . " RETURNING 1
        )";
    }

    // Ansprechpartner und Lieferadressen hängen ohne Fremdschlüssel am Kunden
    // und wären am stillgelegten Eintrag nicht mehr auffindbar.
    $ctes[] = "upd_contacts AS (
        UPDATE contacts SET cp_cv_id = " . $bind($keepId) . "
        WHERE cp_cv_id = " . $bind($mergeId) . " RETURNING 1
    )";
    $ctes[] = "upd_shipto AS (
        UPDATE shipto SET trans_id = " . $bind($keepId) . "
        WHERE trans_id = " . $bind($mergeId) . " AND module = 'CT' RETURNING 1
    )";

    // Anrufhistorie: crmti kennt Kunden und Lieferanten in einer Spalte,
    // deshalb muss der Typ mitgeprueft werden — kein Fall fuer die Liste oben.
    $callsCte = null;
    if (existingTables($db, ['crmti'])) {
        $callsCte = 'upd_calls';
        $ctes[] = "{$callsCte} AS (
            UPDATE crmti SET crmti_caller_id = " . $bind($keepId) . "
            WHERE crmti_caller_id = " . $bind($mergeId) . " AND crmti_caller_typ = 'C' RETURNING 1
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
        WHERE id = " . $bind($mergeId) . " RETURNING 1
    )";

    // Zaehler nur fuer Zweige, die es in dieser DB wirklich gibt
    $tally = function ($key) use ($counts) {
        return isset($counts[$key]) ? "(SELECT COUNT(*) FROM {$counts[$key]})" : '0';
    };
    $movedCalls = $callsCte ? "(SELECT COUNT(*) FROM {$callsCte})" : '0';

    $result = $db->getOne(
        'WITH ' . implode(",\n", $ctes) . "
        SELECT " . $tally('ar')                  . " AS moved_invoices,
               " . $tally('oe')                  . " AS moved_orders,
               " . $tally('delivery_orders')     . " AS moved_delivery_orders,
               " . $tally('accounting_bookings') . " AS moved_bookings,
               {$movedCalls} AS moved_calls,
               (SELECT COUNT(*) FROM set_obsolete) AS obsoleted",
        $params
    );

    if (empty($result['obsoleted'])) {
        throw new ApiError('DATA_NOT_FOUND', "Kunde $mergeId nicht gefunden");
    }

    return [
        'moved_invoices'        => intval($result['moved_invoices']),
        'moved_orders'          => intval($result['moved_orders']),
        'moved_delivery_orders' => intval($result['moved_delivery_orders']),
        'moved_bookings'        => intval($result['moved_bookings']),
        'moved_calls'           => intval($result['moved_calls'])
    ];
}

/**
 * Nie benutzten Doppeleintrag löschen statt stilllegen
 *
 * Interner Helfer von mergeCustomers(), läuft in dessen Transaktion.
 * Ansprechpartner, Lieferadressen, Anrufhistorie hängen ohne Fremdschlüssel
 * am Kunden: sie ziehen vor dem Löschen zum verbleibenden Kunden um, sonst
 * räumt der kivitendo-Trigger sie mit ab. Die erweiterten Kontaktdaten des
 * Doppels fallen weg. Das abschliessende DELETE prüft die Löschbarkeit
 * erneut, falls zwischenzeitlich doch ein Beleg entstanden ist.
 *
 * @param ApiDatabase $db      Offene Company-Verbindung (in Transaktion)
 * @param int         $keepId  Kunde der bleibt
 * @param int         $mergeId Doppeleintrag der verschwindet
 */
function deleteDuplicateCustomer($db, $keepId, $mergeId) {
    $move = [':keep' => $keepId, ':merge' => $mergeId];
    $db->execute("UPDATE contacts SET cp_cv_id = :keep WHERE cp_cv_id = :merge", $move);
    $db->execute("UPDATE shipto SET trans_id = :keep WHERE trans_id = :merge AND module = 'CT'", $move);
    if (existingTables($db, ['crmti'])) {
        $db->execute(
            "UPDATE crmti SET crmti_caller_id = :keep
             WHERE crmti_caller_id = :merge AND crmti_caller_typ = 'C'",
            $move
        );
    }
    if (existingTables($db, ['customer_ext'])) {
        $db->execute("DELETE FROM customer_ext WHERE customer_id = :merge", [':merge' => $mergeId]);
    }

    $stillDeletable = customerDeletableCondition($db, 'c.id');
    $deleted = $db->getOne(
        "DELETE FROM customer c WHERE c.id = :merge AND ({$stillDeletable}) RETURNING c.id",
        [':merge' => $mergeId]
    );

    if (!$deleted) {
        throw new ApiError('CUSTOMER_IN_USE', 'Kunde wird inzwischen verwendet und kann nicht gelöscht werden');
    }
}
