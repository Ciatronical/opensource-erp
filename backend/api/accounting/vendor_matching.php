<?php
// backend/api/accounting/vendor_matching.php

/**
 * Alle Stellen, an denen ein Lieferant haengen kann
 *
 * Eine Liste fuer beides: die Loeschbarkeitspruefung
 * (vendorDeletableCondition) und das Umhaengen beim Zusammenfuehren
 * (mergeVendors). Sonst laufen die zwei Listen auseinander und ein Lieferant
 * gilt als loeschbar, obwohl noch etwas auf ihn zeigt.
 *
 * Reine Anhänge zählen bewusst nicht mit: Aliase, erweiterte Kontaktdaten,
 * Ansprechpartner, Lieferadressen, Merkmale und Wiedervorlagen werden beim
 * Löschen ohnehin mit abgeräumt (kivitendo-Trigger auf vendor bzw. der
 * Aufräumschritt davor).
 *
 * Tabelle => Spalten, die auf vendor.id zeigen.
 *
 * @return array
 */
function vendorReferences() {
    return [
        'ap'                       => ['vendor_id'],
        'oe'                       => ['vendor_id', 'delivery_vendor_id'],
        'ar'                       => ['delivery_vendor_id'],
        'delivery_orders'          => ['vendor_id'],
        'reclamations'             => ['vendor_id'],
        'letter'                   => ['vendor_id'],
        'letter_draft'             => ['vendor_id'],
        'record_templates'         => ['vendor_id'],
        'makemodel'                => ['make'],
        'accounting_bookings'      => ['vendor_id'],
        'accounting_documents'     => ['vendor_id'],
        'accounting_account_rules' => ['vendor_id'],
        'payment_settlements'      => ['vendor_id'],
        'weroni_documents'         => ['vendor_id'],
        'bank_matching_rules'      => ['action_vendor_id']
    ];
}

/**
 * SQL-Bedingung: auf diesen Lieferanten zeigt kein Beleg und keine Regel mehr
 *
 * Wird an drei Stellen gebraucht — Dublettenliste, Vorprüfung und das
 * Löschen selbst — und deshalb einmal gebaut statt dreimal abgetippt.
 * Übergeben wird ein Spaltenausdruck (z. B. 'v1.id'), kein Wert: die
 * Bedingung korreliert mit der äusseren Abfrage und braucht damit keinen
 * einzigen zusätzlichen Platzhalter.
 *
 * Nur Tabellen, die es in dieser Firmen-DB gibt — Zusatzmodule sind nicht
 * ueberall ausgerollt, und ein fehlender Tabellenname laesst die ganze
 * Abfrage schon beim Parsen scheitern (existingTables).
 *
 * EXISTS statt COUNT: bricht beim ersten Treffer ab.
 *
 * @param ApiDatabase $db        Offene Company-Verbindung
 * @param string      $vendorRef Spaltenausdruck mit der Lieferanten-ID
 * @return string SQL-Bedingung, die TRUE ist wenn der Lieferant loeschbar ist
 */
function vendorDeletableCondition($db, $vendorRef) {
    $references = vendorReferences();
    $used = [];

    foreach (existingTables($db, array_keys($references)) as $table) {
        $match = [];
        foreach ($references[$table] as $column) {
            $match[] = "{$column} = {$vendorRef}";
        }
        $used[] = "SELECT 1 FROM {$table} WHERE " . implode(' OR ', $match);
    }

    if (!$used) return 'TRUE';

    return 'NOT EXISTS (' . implode(')
           AND NOT EXISTS (', $used) . ')';
}

/**
 * Lieferanten mit Dublettenpruefer laden
 *
 * @param string $data['query']   Suchbegriff (optional)
 * @param int    $data['limit']   Anzahl (Standard: 50)
 * @param bool   $data['include_obsolete'] Auch inaktive anzeigen
 * @testdata {"limit": 50}
 */
function getAccountingVendors($data) {
    $db = DbhCompany::begin();

    $query = trim($data['query'] ?? '');
    $limit = intval($data['limit'] ?? 50);
    $includeObsolete = !empty($data['include_obsolete']);

    $where = $includeObsolete ? '1=1' : 'v.obsolete IS NOT TRUE';
    $params = [':limit' => $limit];

    if (!empty($query)) {
        $where .= " AND (v.name ILIKE :q OR v.vendornumber ILIKE :q2 OR v.iban ILIKE :q3 OR v.taxnumber ILIKE :q4)";
        $params[':q'] = '%' . $query . '%';
        $params[':q2'] = $query . '%';
        $params[':q3'] = '%' . $query . '%';
        $params[':q4'] = '%' . $query . '%';
    }

    // Buchungen/Gesamtbetrag kommen aus den echten Lieferantenrechnungen (ap),
    // nicht aus accounting_bookings — dort stehen nur die noch nicht
    // freigegebenen KI-Vorschlaege, sodass die Spalten sonst immer 0 zeigen.
    // Standardkonto = zuletzt bebuchtes Aufwandskonto des Lieferanten.
    $vendors = $db->getAll(<<<SQL
        SELECT v.id, v.name, v.vendornumber, v.street, v.zipcode, v.city,
               v.phone, v.email, v.iban, v.bic, v.taxnumber, v.ustid,
               v.obsolete, v.itime,
               TO_CHAR(v.itime, 'DD.MM.YYYY') AS created_fmt,
               COALESCE(inv.booking_count, 0) AS booking_count,
               inv.total_amount,
               acc.accno AS default_account,
               (SELECT STRING_AGG(va.alias_name, ', ') FROM vendor_aliases va WHERE va.vendor_id = v.id) AS aliases
        FROM vendor v
        LEFT JOIN (
            SELECT a.vendor_id, COUNT(*) AS booking_count, SUM(a.amount) AS total_amount
            FROM ap a
            GROUP BY a.vendor_id
        ) inv ON inv.vendor_id = v.id
        LEFT JOIN LATERAL (
            SELECT c.accno
            FROM ap a
            JOIN acc_trans t ON t.trans_id = a.id
            JOIN chart c ON c.id = t.chart_id
            WHERE a.vendor_id = v.id AND c.link LIKE '%AP_amount%'
            ORDER BY a.transdate DESC, t.acc_trans_id
            LIMIT 1
        ) acc ON TRUE
        WHERE {$where}
        ORDER BY v.name
        LIMIT :limit
    SQL, $params);

    resultInfo(true, '', ['vendors' => $vendors ?: []]);
}

/**
 * Lieferant anlegen (mit Dublettenprüfung)
 *
 * @param string $data['name']      Firmenname
 * @param string $data['street']    Strasse
 * @param string $data['zipcode']   PLZ
 * @param string $data['city']      Ort
 * @param string $data['iban']      IBAN
 * @param string $data['taxnumber'] Steuernummer
 * @param string $data['ustid']     USt-IdNr
 * @param string $data['email']     E-Mail
 * @param string $data['phone']     Telefon
 * @param bool   $data['force']     Anlage trotz Duplikat erzwingen
 * @testdata {"name": "Test GmbH", "city": "Berlin"}
 */
function createAccountingVendor($data) {
    $db = DbhCompany::begin();

    $name = trim($data['name'] ?? '');
    if (empty($name)) throw new ApiError('VALIDATION_ERROR', 'name erforderlich');

    $iban = trim($data['iban'] ?? '');
    $taxnumber = trim($data['taxnumber'] ?? $data['ustid'] ?? '');
    $force = !empty($data['force']);

    // Dublettenprüfung
    if (!$force) {
        $matches = $db->getAll(
            "SELECT * FROM find_vendor_fuzzy(:name, :iban, :tax, 0.4)",
            [':name' => $name, ':iban' => $iban ?: null, ':tax' => $taxnumber ?: null]
        );

        if (!empty($matches)) {
            $duplicates = [];
            foreach ($matches as $m) {
                $vendor = $db->getOne("SELECT id, name, city, iban FROM vendor WHERE id = :id", [':id' => $m['vendor_id']]);
                $duplicates[] = [
                    'vendor_id'   => intval($m['vendor_id']),
                    'vendor_name' => $m['vendor_name'],
                    'city'        => $vendor['city'] ?? '',
                    'iban'        => $vendor['iban'] ?? '',
                    'match_score' => floatval($m['match_score']),
                    'match_type'  => $m['match_type']
                ];
            }

            resultInfo(false, 'POSSIBLE_DUPLICATES', [
                'message'    => 'Moegliche Dubletten gefunden. Mit force=true trotzdem anlegen.',
                'duplicates' => $duplicates
            ]);
            return;
        }
    }

    // Naechste Lieferantennummer
    $vendornumber = $db->getOne(
        "SELECT COALESCE(MAX(CAST(vendornumber AS INTEGER)), 70000) + 1 AS next_nr
         FROM vendor WHERE vendornumber ~ '^\d+$'",
        []
    );

    $db->execute(
        "INSERT INTO vendor (name, street, zipcode, city, country, taxnumber, ustid, iban, bic, phone, email, vendornumber, taxzone_id, currency_id)
         VALUES (:name, :street, :zip, :city, :country, :tax, :ustid, :iban, :bic, :phone, :email, :vnr, 1, 1)",
        [
            ':name'    => $name,
            ':street'  => $data['street'] ?? null,
            ':zip'     => $data['zipcode'] ?? null,
            ':city'    => $data['city'] ?? null,
            ':country' => $data['country'] ?? 'DE',
            ':tax'     => $data['taxnumber'] ?? null,
            ':ustid'   => $data['ustid'] ?? null,
            ':iban'    => $iban ?: null,
            ':bic'     => $data['bic'] ?? null,
            ':phone'   => $data['phone'] ?? null,
            ':email'   => $data['email'] ?? null,
            ':vnr'     => $vendornumber['next_nr']
        ]
    );

    $newVendor = $db->getOne(
        "SELECT id, name, vendornumber FROM vendor WHERE vendornumber = :vnr",
        [':vnr' => $vendornumber['next_nr']]
    );

    resultInfo(true, 'Lieferant angelegt', [
        'vendor_id'      => intval($newVendor['id']),
        'vendor_name'    => $newVendor['name'],
        'vendornumber'   => $newVendor['vendornumber']
    ]);
}

/**
 * Lieferant bearbeiten
 *
 * @param int    $data['vendor_id'] Lieferanten-ID
 * @param string $data['name']      Firmenname (optional)
 * @param string $data['iban']      IBAN (optional)
 * @testdata {"vendor_id": 1, "name": "Geaenderter Name"}
 */
function updateAccountingVendor($data) {
    $db = DbhCompany::begin();
    $vendorId = intval($data['vendor_id'] ?? 0);
    if (!$vendorId) throw new ApiError('VALIDATION_ERROR', 'vendor_id erforderlich');

    $allowedFields = ['name', 'street', 'zipcode', 'city', 'country', 'phone', 'email',
                      'iban', 'bic', 'taxnumber', 'ustid'];
    $fields = [];
    $params = [':id' => $vendorId];

    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $data)) {
            $fields[] = "{$field} = :{$field}";
            $params[":{$field}"] = $data[$field];
        }
    }

    if (empty($fields)) throw new ApiError('VALIDATION_ERROR', 'Keine Felder zum Aktualisieren');

    $fields[] = "mtime = NOW()";
    $db->execute(
        "UPDATE vendor SET " . implode(', ', $fields) . " WHERE id = :id",
        $params
    );

    resultInfo(true, 'Lieferant aktualisiert', ['vendor_id' => $vendorId]);
}

/**
 * Lieferanten zusammenführen (Deduplizierung)
 *
 * Zwei Wege, je nachdem ob der aufzulösende Lieferant je benutzt wurde:
 *
 * 1. Nie benutzt (vendorDeletableCondition) und `delete_merged` gesetzt: der
 *    Doppeleintrag wird wirklich gelöscht. Es gibt keinen Beleg, der ihn
 *    braucht, also muss auch nichts umgehängt werden — es bleibt keine
 *    Karteileiche in der Auswahl zurück. Ansprechpartner, Lieferadressen,
 *    Merkmale und Wiedervorlagen räumen die kivitendo-Trigger mit ab.
 *
 * 2. Sonst: der aufgelöste Lieferant wird auf obsolete gesetzt. kivitendo
 *    verbietet das Löschen bebuchter Stammdaten, und die Historie bleibt
 *    nachvollziehbar. Sämtliche Belege beider Lieferanten hängen danach am
 *    beibehaltenen Lieferanten — es geht keine Buchung verloren, auch wenn
 *    beide bebucht waren. Die Buchungssätze selbst (acc_trans) bleiben
 *    unberührt, sie hängen am Beleg.
 *
 * In beiden Fällen werden Name und IBAN des aufgelösten Lieferanten als
 * Alias am verbleibenden hinterlegt, damit die Belegerkennung die alte
 * Schreibweise weiterhin zuordnet.
 *
 * @param int  $data['keep_vendor_id']   Lieferant der beibehalten wird
 * @param int  $data['merge_vendor_id']  Lieferant der aufgelöst wird
 * @param bool $data['delete_merged']    Löschen statt stilllegen, sofern unbenutzt
 * @testdata {"keep_vendor_id": 1, "merge_vendor_id": 2, "delete_merged": true}
 */
function mergeVendors($data) {
    $db = DbhCompany::begin();

    $keepId = intval($data['keep_vendor_id'] ?? 0);
    $mergeId = intval($data['merge_vendor_id'] ?? 0);
    $deleteMerged = filter_var($data['delete_merged'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!$keepId || !$mergeId || $keepId === $mergeId) {
        throw new ApiError('VALIDATION_ERROR', 'Zwei unterschiedliche vendor_ids erforderlich');
    }

    // Beide Lieferanten und die Löschbarkeit des aufzulösenden in einem Zug
    $mergeDeletable = vendorDeletableCondition($db, 'm.id');
    $info = $db->getOne(
        "SELECT k.name AS keep_name, m.name AS merge_name, m.iban AS merge_iban,
                ({$mergeDeletable}) AS deletable
         FROM vendor k
         JOIN vendor m ON m.id = :merge
         WHERE k.id = :keep",
        [':keep' => $keepId, ':merge' => $mergeId]
    );

    if (!$info) {
        throw new ApiError('DATA_NOT_FOUND', 'Lieferant nicht gefunden');
    }

    $deletable = $info['deletable'] === true || $info['deletable'] === 't';

    if ($deleteMerged && $deletable) {
        deleteDuplicateVendor($db, $keepId, $mergeId, $info);
        return;
    }

    // Alles in einer Anweisung: jede Referenz auf den aufzulösenden
    // Lieferanten wird umgehängt, sein Name als Alias gesichert und er
    // selbst auf obsolete gesetzt. Ein einziges Statement heißt eine
    // Transaktion und einen konsistenten Snapshot für alle Teilschritte.
    //
    // Die Umhaenge-Zweige entstehen aus vendorReferences(), gefiltert auf die
    // Tabellen dieser Firmen-DB — dieselbe Liste, die auch ueber die
    // Loeschbarkeit entscheidet. Jeder Zweig bekommt eigene Platzhalter: PDO
    // spricht mit PostgreSQL echt vorbereitet, da darf kein Name doppelt vorkommen.
    $references = vendorReferences();
    $params = [];
    $ctes = [];
    $counts = [];

    $bind = function ($value) use (&$params) {
        $name = ':p' . count($params);
        $params[$name] = $value;
        return $name;
    };

    $ctes[] = 'keep AS (SELECT id, name FROM vendor WHERE id = ' . $bind($keepId) . ')';
    $ctes[] = 'merged AS (SELECT id, name, iban FROM vendor WHERE id = ' . $bind($mergeId) . ')';

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

    // vendor_ext ist pro Lieferant eindeutig: nur übernehmen, wenn der
    // verbleibende Lieferant noch keine erweiterten Kontaktdaten hat.
    if (existingTables($db, ['vendor_ext'])) {
        $ctes[] = "upd_ext AS (
            UPDATE vendor_ext SET vendor_id = " . $bind($keepId) . "
            WHERE vendor_id = " . $bind($mergeId) . "
              AND NOT EXISTS (SELECT 1 FROM vendor_ext e WHERE e.vendor_id = " . $bind($keepId) . ")
            RETURNING 1
        )";
    }

    // Name und IBAN des aufgeloesten Lieferanten als Alias sichern, damit die
    // Belegerkennung die alte Schreibweise weiterhin zuordnet.
    if (existingTables($db, ['vendor_aliases'])) {
        $ctes[] = "ins_alias AS (
            INSERT INTO vendor_aliases (vendor_id, alias_name, alias_iban)
            SELECT " . $bind($keepId) . ", m.name, m.iban
            FROM merged m
            WHERE NOT EXISTS (
                SELECT 1 FROM vendor_aliases a
                WHERE a.vendor_id = " . $bind($keepId) . " AND LOWER(a.alias_name) = LOWER(m.name)
            )
            RETURNING 1
        )";
        $ctes[] = "upd_alias AS (
            UPDATE vendor_aliases SET vendor_id = " . $bind($keepId) . "
            WHERE vendor_id = " . $bind($mergeId) . " AND EXISTS (SELECT 1 FROM keep) RETURNING 1
        )";
    }

    // Anrufhistorie: crmti kennt Kunden und Lieferanten in einer Spalte,
    // deshalb muss der Typ mitgeprueft werden — kein Fall fuer die Liste oben.
    $callsCte = null;
    if (existingTables($db, ['crmti'])) {
        $callsCte = 'upd_calls';
        $ctes[] = "{$callsCte} AS (
            UPDATE crmti SET crmti_caller_id = " . $bind($keepId) . "
            WHERE crmti_caller_id = " . $bind($mergeId) . " AND crmti_caller_typ = 'V'
              AND EXISTS (SELECT 1 FROM keep) RETURNING 1
        )";
    }

    $ctes[] = "set_obsolete AS (
        UPDATE vendor SET obsolete = TRUE, mtime = NOW()
        WHERE id = " . $bind($mergeId) . " AND EXISTS (SELECT 1 FROM keep) RETURNING 1
    )";

    // Zaehler nur fuer Zweige, die es in dieser DB wirklich gibt
    $tally = function ($key) use ($counts) {
        return isset($counts[$key]) ? "(SELECT COUNT(*) FROM {$counts[$key]})" : '0';
    };

    $movedCalls = $callsCte ? "(SELECT COUNT(*) FROM {$callsCte})" : '0';

    $result = $db->getOne(
        'WITH ' . implode(",\n", $ctes) . "
        SELECT (SELECT name FROM keep)   AS kept_vendor,
               (SELECT name FROM merged) AS merged_vendor,
               " . $tally('ap')                    . " AS moved_invoices,
               " . $tally('oe')                    . " AS moved_orders,
               " . $tally('delivery_orders')       . " AS moved_delivery_orders,
               " . $tally('accounting_bookings')   . " AS moved_bookings,
               " . $tally('accounting_documents')  . " AS moved_documents,
               {$movedCalls} AS moved_calls,
               (SELECT COUNT(*) FROM set_obsolete) AS merged_closed",
        $params
    );

    if (empty($result['kept_vendor']) || empty($result['merged_vendor'])) {
        throw new ApiError('DATA_NOT_FOUND', 'Lieferant nicht gefunden');
    }

    resultInfo(true, 'Lieferanten zusammengeführt', [
        'kept_vendor'           => $result['kept_vendor'],
        'merged_vendor'         => $result['merged_vendor'],
        'merged_deleted'        => false,
        'moved_invoices'        => intval($result['moved_invoices']),
        'moved_orders'          => intval($result['moved_orders']),
        'moved_delivery_orders' => intval($result['moved_delivery_orders']),
        'moved_bookings'        => intval($result['moved_bookings']),
        'moved_documents'       => intval($result['moved_documents']),
        'moved_calls'           => intval($result['moved_calls'])
    ]);
}

/**
 * Nie benutzten Doppeleintrag löschen statt stilllegen
 *
 * Interner Helfer von mergeVendors(). Bewusst in mehreren Schritten statt
 * in einer Anweisung: vendor_aliases hängt per ON DELETE CASCADE am
 * Lieferanten, die Aliase müssen also nachweislich VOR dem Löschen am
 * verbleibenden Lieferanten hängen — innerhalb einer Anweisung ist die
 * Reihenfolge datenverändernder CTEs nicht garantiert. Alles in einer
 * Transaktion, das abschliessende DELETE prüft die Löschbarkeit erneut,
 * falls zwischenzeitlich doch gebucht wurde.
 *
 * @param ApiDatabase $db      Offene Company-Verbindung
 * @param int         $keepId  Lieferant der bleibt
 * @param int         $mergeId Doppeleintrag der verschwindet
 * @param array       $info    Namen und IBAN aus mergeVendors()
 */
function deleteDuplicateVendor($db, $keepId, $mergeId, $info) {
    $db->beginTransaction();

    try {
        // Schreibweise und IBAN des Doppels am bleibenden Lieferanten sichern
        $db->execute(
            "INSERT INTO vendor_aliases (vendor_id, alias_name, alias_iban)
             SELECT :keep, :name, :iban
             WHERE NOT EXISTS (
                 SELECT 1 FROM vendor_aliases a
                 WHERE a.vendor_id = :keep2 AND LOWER(a.alias_name) = LOWER(:name2)
             )",
            [':keep'  => $keepId, ':name'  => $info['merge_name'], ':iban' => $info['merge_iban'],
             ':keep2' => $keepId, ':name2' => $info['merge_name']]
        );

        // Aliase aus früheren Zusammenführungen mitnehmen, sonst nimmt sie
        // der Fremdschlüssel beim Löschen mit ins Grab
        $db->execute(
            "UPDATE vendor_aliases SET vendor_id = :keep WHERE vendor_id = :merge",
            [':keep' => $keepId, ':merge' => $mergeId]
        );

        // Anrufhistorie hängt ohne Fremdschlüssel am Lieferanten und würde
        // sonst ins Leere zeigen — sie zieht zum bleibenden Lieferanten um
        $db->execute(
            "UPDATE crmti SET crmti_caller_id = :keep
             WHERE crmti_caller_id = :merge AND crmti_caller_typ = 'V'",
            [':keep' => $keepId, ':merge' => $mergeId]
        );

        // Erweiterte Kontaktdaten hängen ohne Fremdschlüssel am Lieferanten
        $db->execute("DELETE FROM vendor_ext WHERE vendor_id = :merge", [':merge' => $mergeId]);

        $stillDeletable = vendorDeletableCondition($db, 'v.id');
        $deleted = $db->getOne(
            "DELETE FROM vendor v WHERE v.id = :merge AND ({$stillDeletable}) RETURNING v.id",
            [':merge' => $mergeId]
        );

        if (!$deleted) {
            $db->rollBack();
            throw new ApiError('VENDOR_IN_USE', 'Lieferant wird inzwischen verwendet und kann nicht gelöscht werden');
        }

        $db->commit();
    } catch (ApiError $e) {
        throw $e;
    } catch (\Exception $e) {
        $db->rollBack();
        throw new ApiError('MERGE_ERROR', 'Löschen fehlgeschlagen: ' . $e->getMessage());
    }

    resultInfo(true, 'Doppelter Lieferant gelöscht', [
        'kept_vendor'    => $info['keep_name'],
        'merged_vendor'  => $info['merge_name'],
        'merged_deleted' => true
    ]);
}

/**
 * Potenzielle Dubletten finden
 *
 * Die Paare entstehen ueber den Trigramm-Operator `%` statt ueber
 * `similarity(...) > schwellwert` — inhaltlich dasselbe, aber nur der Operator
 * kann den GIN-Index vendor_name_gin_trgm_idx nutzen; sonst rechnet
 * PostgreSQL jedes Lieferantenpaar einzeln aus. LOWER() faellt weg, weil
 * pg_trgm ohnehin in Kleinbuchstaben zerlegt. Der IBAN-Zweig steht als
 * eigener UNION-Arm daneben, als OR wuerde er die Indexnutzung verhindern.
 * Gleiche Begruendung wie bei findCustomerDuplicates(), dort ausfuehrlich.
 *
 * @param float $data['threshold'] Schwellwert (Standard: 0.4)
 * @testdata {"threshold": 0.4}
 */
function findVendorDuplicates($data) {
    $db = DbhCompany::begin();
    $threshold = floatval($data['threshold'] ?? 0.4);

    // Anlegedatum, Buchungszahl und Löschbarkeit beider Seiten kommen mit,
    // damit im Zusammenführen-Dialog entschieden werden kann, welcher
    // Lieferant bleibt (in der Regel der ältere bzw. der stärker bebuchte)
    // und ob der andere gelöscht statt nur stillgelegt werden kann.
    $v1Deletable = vendorDeletableCondition($db, 'v1.id');
    $v2Deletable = vendorDeletableCondition($db, 'v2.id');

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
                SELECT vendor_id, COUNT(*) AS booking_count
                FROM ap
                GROUP BY vendor_id
            ),
            paare AS (
                SELECT v1.id AS id1, v2.id AS id2
                FROM vendor v1
                JOIN vendor v2 ON v2.id > v1.id
                WHERE v1.obsolete IS NOT TRUE AND v2.obsolete IS NOT TRUE
                  AND v1.name % v2.name
                UNION
                SELECT v1.id, v2.id
                FROM vendor v1
                JOIN vendor v2 ON v2.id > v1.id
                WHERE v1.obsolete IS NOT TRUE AND v2.obsolete IS NOT TRUE
                  AND v1.iban IS NOT NULL AND v1.iban <> ''
                  AND REPLACE(v1.iban, ' ', '') = REPLACE(v2.iban, ' ', '')
            )
            SELECT v1.id AS vendor1_id, v1.name AS vendor1_name, v1.city AS vendor1_city,
                   TO_CHAR(v1.itime, 'DD.MM.YYYY') AS vendor1_created,
                   COALESCE(i1.booking_count, 0) AS vendor1_bookings,
                   ({$v1Deletable}) AS vendor1_deletable,
                   v2.id AS vendor2_id, v2.name AS vendor2_name, v2.city AS vendor2_city,
                   TO_CHAR(v2.itime, 'DD.MM.YYYY') AS vendor2_created,
                   COALESCE(i2.booking_count, 0) AS vendor2_bookings,
                   ({$v2Deletable}) AS vendor2_deletable,
                   similarity(v1.name, v2.name) AS name_similarity,
                   CASE WHEN v1.iban IS NOT NULL AND v1.iban != '' AND v1.iban = v2.iban THEN TRUE ELSE FALSE END AS same_iban
            FROM paare p
            JOIN vendor v1 ON v1.id = p.id1
            JOIN vendor v2 ON v2.id = p.id2
            LEFT JOIN inv i1 ON i1.vendor_id = v1.id
            LEFT JOIN inv i2 ON i2.vendor_id = v2.id
            ORDER BY name_similarity DESC
            LIMIT 50
        SQL);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // PostgreSQL-Booleans kommen über PDO als 't'/'f' an — beides ist in
    // JavaScript wahr. Vor der Ausgabe in echte Booleans wandeln, sonst
    // meldet die Oberfläche bei jeder Namensdublette "gleiche IBAN".
    foreach ($duplicates as &$dup) {
        foreach (['same_iban', 'vendor1_deletable', 'vendor2_deletable'] as $flag) {
            $dup[$flag] = $dup[$flag] === true || $dup[$flag] === 't';
        }
    }
    unset($dup);

    resultInfo(true, '', ['duplicates' => $duplicates ?: []]);
}
