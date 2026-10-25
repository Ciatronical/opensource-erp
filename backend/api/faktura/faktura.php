<?php
require_once __DIR__.'/../lib/kontenrahmen.php';  // kassenkontoBedingung()
require_once __DIR__.'/../banking/banking_utils.php';  // nextBelegnummer()
// backend/api/faktura/faktura.php

/**
 * Lädt Faktura-Daten (Rechnung, Auftrag, Angebot, Lieferschein)
 *
 * @param array $data Request-Daten mit fakturaID und fakturaType
 * @return void Gibt JSON zurück
 * @testdata {"fakturaID": 1, "fakturaType": "invoice"}
 */
function getFakturaData($data) {
    $fakturaID = intval($data['fakturaID']);
    $hintType = $data['fakturaType'] ?? 'invoice';

    $company = DbhCompany::begin();

    // Die Route kennt nur Verkaufsarten (Bestellung öffnet über die Auftragsroute,
    // Eingangsrechnung über die Rechnungsroute) — die echte Belegart steht im Datensatz
    $resolved = resolveFakturaType($company, $fakturaID, $hintType);
    if (!$resolved) {
        resultInfo(false, 'FAKTURA_NOT_FOUND', "Dokument {$fakturaID} ({$hintType}) nicht gefunden");
        return;
    }
    $fakturaType = $resolved['type'];
    permit(getPermissionForFakturaType($fakturaType));
    // Eingangsrechnung ohne Positionen (Belegerfassung, reine Buchung): gehört ins Journal
    if ($resolved['ledger_only']) {
        resultInfo(false, 'FAKTURA_LEDGER_ONLY', ['src' => $resolved['table'], 'id' => $fakturaID]);
        return;
    }

    // Tabellennamen abhängig vom Dokumenttyp
    $tableConfig = getFakturaTableConfig($fakturaType);
    $mainTable = $tableConfig['main_table'];
    $itemsTable = $tableConfig['items_table'];
    $itemsFk = $tableConfig['items_fk'];
    $cvTable = $tableConfig['cv_table'];
    $cvColumn = $tableConfig['cv_column'];

    // Payment chart_link: AR_paid für Verkauf, AP_paid für Einkauf
    $paymentLink = ($mainTable === 'ap') ? '%AP_paid%' : '%AR_paid%';

    // Eigene Lieferadresse des Belegs (dev/shop-adressen.md) — nicht bei
    // Einkaufsrechnungen: Lieferadressen gehören zum Verkauf
    $eigeneLieferadresseSql = ($mainTable === 'ap') ? '' : "OR shipto.shipto_id = {$mainTable}.shipto_id";
    // Rechnungsstatus nur bei Auftraegen/Angeboten (oe): welche Rechnung ist daraus entstanden?
    $invoiceStatusSql = ($mainTable === 'oe') ? 'oe_invoice_status(:fakturaID)' : 'NULL::json';
    // Wiedervorlagen hängen über follow_up_links (trans_id + kivitendo-trans_type) am Beleg.
    // trans_id allein ist nicht eindeutig — oe/delivery_orders teilen sich die Sequenz mit
    // customer/vendor, ar/ap mit gl —, deshalb nur die Typen der eigenen Haupttabelle.
    $followUpTypes = [
        'oe'              => ['sales_quotation', 'sales_order', 'sales_order_intake', 'request_quotation', 'purchase_order', 'purchase_quotation_intake', 'purchase_order_confirmation'],
        'ar'              => ['sales_invoice', 'credit_note', 'ar_transaction'],
        'ap'              => ['purchase_invoice', 'vendor_invoice', 'ap_transaction'],
        'delivery_orders' => ['sales_delivery_order', 'purchase_delivery_order', 'supplier_delivery_order', 'rma_delivery_order'],
    ][$mainTable] ?? [];
    // Bestätigungsstand nur bei Verkaufsaufträgen (oe_confirmation_status liefert sonst NULL)
    $confirmationSql = ($mainTable === 'oe') ? 'oe_confirmation_status(:fakturaID)' : 'NULL::json';
    // Letzte Änderung aus der Belegchronik (Tooltip der Verlauf-Taste); die Tabelle legt
    // erst der Upstall an — bis dahin bleibt der Wert leer statt die Abfrage zu sprengen
    $lastChangeSql = existingTables($company, ['record_history_oserp'])
        ? "(SELECT json_build_object('itime', h.itime, 'employee_id', h.employee_id, 'employee_name', e.name, 'source', h.source)
              FROM record_history_oserp h LEFT JOIN employee e ON e.id = h.employee_id
             WHERE h.record_table = '{$mainTable}' AND h.record_id = :fakturaID
             ORDER BY h.itime DESC, h.id DESC LIMIT 1)"
        : 'NULL::json';

    // Ext-Tabelle für phone_numbers (customer_ext/vendor_ext)
    $extTable = ($cvTable === 'vendor') ? 'vendor_ext' : 'customer_ext';
    $extFk    = ($cvTable === 'vendor') ? 'vendor_id'  : 'customer_id';

    // Billing addresses nur für Kunden verfügbar
    $billingAddressesSql = ($cvTable === 'customer')
        ? "(SELECT json_agg(billing_addr) FROM (SELECT addr.* FROM {$mainTable} JOIN additional_billing_addresses addr ON {$mainTable}.customer_id = addr.customer_id WHERE {$mainTable}.id = :fakturaID ORDER BY addr.id ASC) AS billing_addr)"
        : "NULL";

    // Forderungskonto („Buchen auf“) aus der Datenbank, nicht aus der company_config
    // des Browsers — die kann nach einem Firmenwechsel in einem anderen Tab zur alten
    // Firma gehören. Vorrang hat das Konto der bestehenden Forderungsbuchung, dann
    // defaults.ar_chart_id, dann das niedrigste AR-Konto (wie postArInvoiceToLedger).
    $arTargetSql = ($mainTable === 'ar')
        ? "COALESCE(
               (SELECT at.chart_id FROM acc_trans at
                 WHERE at.trans_id = ar.id
                   AND POSITION(':AR:' IN ':' || COALESCE(at.chart_link, '') || ':') > 0
                 ORDER BY at.acc_trans_id ASC LIMIT 1),
               (SELECT d.ar_chart_id FROM defaults d JOIN chart c ON c.id = d.ar_chart_id AND c.link = 'AR' LIMIT 1),
               (SELECT id FROM chart WHERE link = 'AR' ORDER BY accno ASC LIMIT 1)
           )"
        : "NULL";

    $query = <<<SQL
        SELECT json_build_object(
            'success', true,
            'payload', json_build_object(
            -- Belegart aus dem Datensatz (resolveFakturaType) — das Frontend übernimmt sie
            'fakturaType', CAST(:fakturaType AS text),
            'common',
                (
                    SELECT row_to_json(common)
                    FROM (
                        SELECT {$mainTable}.*, {$arTargetSql} AS "AR_target"
                        FROM {$mainTable}
                        WHERE {$mainTable}.id = :fakturaID
                    ) AS common
                ),
            'customer',
                (
                    SELECT row_to_json(common)
                    FROM (
                        SELECT
                            {$cvTable}.*,
                            (SELECT phone_numbers FROM {$extTable} WHERE {$extFk} = {$cvTable}.id) AS phone_numbers,
                            (SELECT emails FROM {$extTable} WHERE {$extFk} = {$cvTable}.id) AS emails
                        FROM {$mainTable}
                        INNER JOIN {$cvTable} ON {$cvTable}.id = {$mainTable}.{$cvColumn}
                        WHERE {$mainTable}.id = :fakturaID
                    ) AS common
                ),
            'payment',
                (
                    SELECT json_agg(payment ORDER BY acc_trans_id DESC)
                    FROM (
                        SELECT
                            at.acc_trans_id,
                            at.chart_id,
                            at.amount,
                            at.source,
                            at.memo,
                            at.transdate,
                            -- Zugehörige, noch GEBUCHTE Bankzuordnung ermitteln
                            -- (stabil über das Mapping, für Alt-Buchungen per Memo).
                            -- bank_transaction_id != null => Zahlung ist bank-gebucht:
                            -- im Faktura-Editor gesperrt (Storno nur über das Bankmodul);
                            -- bank_account_id dient dem Sprung ins Bankmodul.
                            (btx.id IS NOT NULL) AS bank_booked,
                            btx.id AS bank_transaction_id,
                            btx.local_bank_account_id AS bank_account_id
                        FROM acc_trans at
                        LEFT JOIN LATERAL (
                            SELECT bt.id, bt.local_bank_account_id
                            FROM bank_transactions bt
                            WHERE EXISTS (SELECT 1 FROM bank_transactions_ext bte WHERE bte.bank_transaction_id = bt.id AND bte.match_status = 'booked')
                                AND (
                                    EXISTS (
                                        SELECT 1 FROM bank_transaction_acc_trans bat
                                        WHERE bat.bank_transaction_id = bt.id
                                            AND bat.acc_trans_id = at.acc_trans_id
                                    )
                                    OR at.memo LIKE '%Umsatz #' || bt.id
                                )
                            LIMIT 1
                        ) btx ON true
                        WHERE at.trans_id = :fakturaID
                            AND at.chart_link LIKE '{$paymentLink}'
                            -- Zahlungen gibt es nur an Rechnungen; oe/delivery_orders-IDs
                            -- stammen aus einer anderen Sequenz und können kollidieren
                            AND '{$mainTable}' IN ('ar', 'ap')
                    ) AS payment
                ),
            'positions',
                (
                    SELECT json_agg({$itemsTable} ORDER BY position ASC)
                    FROM (
                        SELECT
                            {$itemsTable}.*,
                            parts.partnumber,
                            parts.part_type,
                            parts.classification_id,
                            {$itemsTable}.longdescription,
                            (
                                SELECT row_to_json(buchungsziel)
                                FROM (
                                    SELECT
                                        c2.id AS income_chart_id,
                                        tk.tax_id,
                                        tx.chart_id AS tax_chart_id,
                                        tx.rate
                                    FROM parts p
                                    LEFT JOIN buchungsgruppen bg ON p.buchungsgruppen_id = bg.id
                                    LEFT JOIN taxzone_charts tc ON bg.id = tc.buchungsgruppen_id
                                    LEFT JOIN chart c1 ON bg.inventory_accno_id = c1.id
                                    LEFT JOIN chart c2 ON tc.income_accno_id = c2.id
                                    LEFT JOIN chart c3 ON tc.expense_accno_id = c3.id
                                    -- nur Schlüssel, die am Belegdatum gelten — ein
                                    -- im Voraus eingetragener Satzwechsel greift erst ab
                                    -- seinem Datum (dev/shop-verkaufskanaele.md, V24)
                                    LEFT JOIN taxkeys tk ON tk.chart_id = c2.id
                                        AND tk.startdate <= COALESCE((SELECT transdate FROM {$mainTable} WHERE id = :fakturaID), current_date)
                                    LEFT JOIN tax tx ON tx.id = tk.tax_id
                                    WHERE tc.taxzone_id = (SELECT taxzone_id FROM {$mainTable} WHERE id = :fakturaID)
                                        AND p.id = parts.id
                                    ORDER BY tk.startdate DESC
                                    LIMIT 1
                                ) AS buchungsziel
                            ) AS buchungsziel
                        FROM {$itemsTable}
                        LEFT JOIN parts ON {$itemsTable}.parts_id = parts.id
                        WHERE {$itemsTable}.{$itemsFk} = :fakturaID
                    ) AS {$itemsTable}
                ),
            'shiptos',
                (
                    SELECT json_agg(shiptos)
                    FROM (
                        -- Lieferadressen des Kundenstamms (module 'CT') und die
                        -- eigene Lieferadresse des Belegs, auf die er zeigt (wie
                        -- in kivitendo: module des Belegs, trans_id = Beleg;
                        -- Shop-Rechnungen haben immer eine, dev/shop-adressen.md)
                        SELECT shipto.*, (shipto.module IS DISTINCT FROM 'CT') AS document_own
                          FROM {$mainTable}
                          JOIN shipto ON ({$mainTable}.{$cvColumn} = shipto.trans_id AND shipto.module = 'CT')
                                      {$eigeneLieferadresseSql}
                         WHERE {$mainTable}.id = :fakturaID
                         ORDER BY (shipto.module IS DISTINCT FROM 'CT') DESC, shipto.shipto_id ASC
                    ) AS shiptos
                ),
            'billing_addresses',
                {$billingAddressesSql},
            'customers',
                (
                    SELECT json_agg(json_build_object('id', cv.id, 'name', cv.name))
                    FROM {$cvTable} cv
                    JOIN {$mainTable} mt ON mt.{$cvColumn} = cv.id AND mt.id = :fakturaID
                ),
            -- Versandprotokoll: E-Mails (email_journal) und WhatsApp-Nachrichten über
            -- record_links (from = Beleg), DHL-Sendungen über dhl_shipments, Postversand
            -- über postal_dispatches. Neueste zuerst.
            'sent_log',
                (
                    SELECT COALESCE(json_agg(s ORDER BY s.sent_at DESC), '[]'::json)
                    FROM (
                        SELECT 'email' AS channel, ej.sent_on AS sent_at, ej.recipients AS recipient,
                               ej.subject AS subject, ej.status::text AS status, e.name AS employee_name
                        FROM record_links rl
                        JOIN email_journal ej ON ej.id = rl.to_id
                        LEFT JOIN employee e ON e.id = ej.sender_id
                        WHERE rl.from_table = '{$mainTable}' AND rl.from_id = :fakturaID AND rl.to_table = 'email_journal'
                        UNION ALL
                        SELECT 'whatsapp', wm.itime, wm.phone_number, wm.message_text, wm.status, NULL
                        FROM record_links rl
                        JOIN whatsapp_messages wm ON wm.id = rl.to_id
                        WHERE rl.from_table = '{$mainTable}' AND rl.from_id = :fakturaID AND rl.to_table = 'whatsapp_messages'
                        UNION ALL
                        SELECT 'dhl', ds.created_at, ds.shipment_no, ds.product, 'sent', e.name
                        FROM dhl_shipments ds
                        LEFT JOIN employee e ON e.id = ds.created_by
                        WHERE ds.record_type = ANY(CAST(:dhlTypes AS text[])) AND ds.record_id = :fakturaID
                        UNION ALL
                        -- Postversand (Hybridbrief): Einlieferungen beim Briefdienstleister
                        SELECT 'post', COALESCE(pd.sent_at, pd.itime), pd.recipient_name,
                               concat_ws(' · ', pd.filename, pd.tracking_code), pd.status, e.name
                        FROM postal_dispatches pd
                        LEFT JOIN employee e ON e.id = pd.employee_id
                        WHERE pd.record_table = '{$mainTable}' AND pd.record_id = :fakturaID AND pd.status <> 'error'
                    ) AS s
                ),
            -- Rechnungsstatus des Auftrags (oe_invoice_status, Upstall crm): state
            -- invoiced | storno | none, dazu die Rechnungen selbst — die Maske
            -- zeigt ihn neben dem Versandstatus und bietet den Sprung zur Rechnung.
            'invoice_status',
                {$invoiceStatusSql},
            -- Letzte Änderung (Belegchronik): wer zuletzt und wann
            'last_change',
                {$lastChangeSql},
            -- Offene Wiedervorlagen zum Beleg (kivitendo follow_ups): Zähler an der
            -- Glocke der Aktionsleiste, Liste mit Erledigt/Bearbeiten. Die Links
            -- gehen mit, weil updateFollowUp sie neu schreibt.
            'follow_ups',
                (
                    SELECT COALESCE(json_agg(f ORDER BY f.follow_up_date, f.id), '[]'::json)
                    FROM (
                        SELECT fu.id, fu.follow_up_date, n.subject, n.body,
                               ec.name AS created_by_name,
                               (SELECT COALESCE(json_agg(json_build_object(
                                           'employee_id', fe.employee_id,
                                           'employee_name', e.name) ORDER BY e.name), '[]'::json)
                                  FROM follow_up_created_for_employees fe
                                  LEFT JOIN employee e ON e.id = fe.employee_id
                                 WHERE fe.follow_up_id = fu.id) AS assigned_employees,
                               (SELECT json_agg(json_build_object(
                                           'trans_id', l.trans_id,
                                           'trans_type', l.trans_type,
                                           'trans_info', l.trans_info) ORDER BY l.id)
                                  FROM follow_up_links l
                                 WHERE l.follow_up_id = fu.id) AS links
                        FROM follow_ups fu
                        LEFT JOIN notes n ON n.id = fu.note_id
                        LEFT JOIN employee ec ON ec.id = fu.created_by
                        WHERE EXISTS (SELECT 1 FROM follow_up_links ful
                                       WHERE ful.follow_up_id = fu.id
                                         AND ful.trans_id = :fakturaID
                                         AND ful.trans_type = ANY(CAST(:followUpTypes AS text[])))
                          AND NOT EXISTS (SELECT 1 FROM follow_up_done fud WHERE fud.follow_up_id = fu.id)
                    ) AS f
                ),
            -- Bestätigung des Verkaufsauftrags (oe_confirmation_status, Upstall crm):
            -- bestätigt ja/nein, wie/wann/von wem, Betrag damals, offene KI-Vorschläge
            'confirmation_status',
                {$confirmationSql},
            -- Erfolgreiche Kartenzahlungen am SumUp-Terminal (nur Rechnungen). Sie
            -- werden NICHT sofort als Zahlung gebucht — die Buchung kommt mit der
            -- SumUp-Auszahlung im Banking. Hier nur zur Anzeige und damit der
            -- offene Rest nicht erneut ans Terminal geht.
            'card_payments',
                (
                    SELECT COALESCE(json_agg(sc ORDER BY sc.id), '[]'::json)
                    FROM (
                        SELECT sc.id, sc.amount, sc.currency, sc.transaction_code, sc.card_type, sc.itime
                        FROM sumup_checkouts sc
                        WHERE sc.trans_id = :fakturaID AND sc.status = 'successful' AND '{$mainTable}' = 'ar'
                    ) AS sc
                ),
            -- Checkouts ohne geklärtes Ergebnis (Abfrage im Browser abgebrochen oder
            -- lokal storniert, ohne dass SumUp befragt wurde): das Frontend gleicht
            -- sie beim Öffnen per sumupSyncCheckouts mit SumUp ab.
            'card_unresolved',
                (
                    SELECT count(*) FROM sumup_checkouts sc
                    WHERE sc.trans_id = :fakturaID AND '{$mainTable}' = 'ar'
                      AND (sc.status = 'pending' OR (sc.status = 'cancelled' AND sc.transaction_code IS NULL))
                )
            )
        ) AS result,
        EXISTS (SELECT 1 FROM {$mainTable} WHERE id = :fakturaID) AS found
SQL;

    //debugQuery($query, ['fakturaID' => $fakturaID], 'Faktura Daten laden');

    $row = $company->getOne($query, [
        'fakturaID'     => $fakturaID,
        'fakturaType'   => $fakturaType,
        'followUpTypes' => '{' . implode(',', $followUpTypes) . '}',
        // DHL-Etiketten tragen die Belegart, unter der sie erstellt wurden (Bestellungen
        // früher als 'order') — deshalb alle Arten der Haupttabelle
        'dhlTypes'      => '{' . implode(',', [
            'oe'              => ['order', 'quotation', 'purchase_order', 'request_quotation'],
            'ar'              => ['invoice', 'credit_note', 'invoice_storno'],
            'ap'              => ['purchase_invoice'],
            'delivery_orders' => ['delivery_order', 'purchase_delivery_order'],
        ][$mainTable] ?? []) . '}',
    ]);

    // Unbekannte oder gelöschte ID: die Abfrage liefert sonst success=true mit
    // common=NULL — die View würde darauf abstürzen. Deshalb als Fehler melden,
    // damit das Frontend zur Liste zurückführen kann.
    if (empty($row['found'])) {
        resultInfo(false, 'FAKTURA_NOT_FOUND', "Dokument {$fakturaID} ({$fakturaType}) nicht gefunden");
        return;
    }

    echo $row['result'];
}

/**
 * Sucht Kunden oder Lieferanten per Name fuer das Faktura-Autocomplete (max 10 Treffer)
 *
 * @param string $data['search'] Suchbegriff (min 3 Zeichen)
 * @param string $data['type'] 'customer' oder 'vendor'
 * @testdata {"search": "Test", "type": "customer"}
 */
/**
 * Sucht Kunden und Lieferanten mit E-Mail für den E-Mail-Dialog.
 * Gibt primäre E-Mail (customer.email) + weitere Adressen aus customer_ext.emails zurück.
 * Mehrere Adressen können im Frontend automatisch als CC gesetzt werden.
 *
 * @param type $data['search'] Suchbegriff (mind. 3 Zeichen)
 * @testdata {"search": "Muster"}
 */
function searchCvEmails($data) {
    $search = trim($data['search'] ?? '');
    if (strlen($search) < 3) {
        echo resultInfo(true, '', ['results' => []]);
        return;
    }
    $db = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_build_object('results', COALESCE(
            (
                SELECT json_agg(t ORDER BY t.name)
                FROM (
                    (
                        SELECT c.id, c.name, 'customer' AS cv_type,
                               NULLIF(TRIM(c.email), '') AS email,
                               COALESCE(
                                   (SELECT json_agg(e)
                                    FROM jsonb_array_elements_text(ce.emails) AS e
                                    WHERE NULLIF(TRIM(e), '') IS NOT NULL
                                      AND e IS DISTINCT FROM NULLIF(TRIM(c.email), '')),
                                   '[]'::json
                               ) AS extra_emails
                        FROM customer c
                        LEFT JOIN customer_ext ce ON ce.customer_id = c.id
                        WHERE (c.name ILIKE '%' || :s1 || '%' OR c.email ILIKE '%' || :s2 || '%')
                          AND (NULLIF(TRIM(c.email), '') IS NOT NULL
                               OR (ce.emails IS NOT NULL AND jsonb_array_length(ce.emails) > 0))
                        ORDER BY c.name LIMIT 10
                    )
                    UNION ALL
                    (
                        SELECT v.id, v.name, 'vendor' AS cv_type,
                               NULLIF(TRIM(v.email), '') AS email,
                               COALESCE(
                                   (SELECT json_agg(e)
                                    FROM jsonb_array_elements_text(ve.emails) AS e
                                    WHERE NULLIF(TRIM(e), '') IS NOT NULL
                                      AND e IS DISTINCT FROM NULLIF(TRIM(v.email), '')),
                                   '[]'::json
                               ) AS extra_emails
                        FROM vendor v
                        LEFT JOIN vendor_ext ve ON ve.vendor_id = v.id
                        WHERE (v.name ILIKE '%' || :s3 || '%' OR v.email ILIKE '%' || :s4 || '%')
                          AND (NULLIF(TRIM(v.email), '') IS NOT NULL
                               OR (ve.emails IS NOT NULL AND jsonb_array_length(ve.emails) > 0))
                        ORDER BY v.name LIMIT 10
                    )
                ) t
                LIMIT 15
            ),
            '[]'::json
        )) AS result
    SQL;

    echo $db->getOne($query, [
        's1' => $search, 's2' => $search,
        's3' => $search, 's4' => $search,
    ])['result'];
}

function searchFakturaCustomers($data) {
    $search = trim($data['search'] ?? '');
    $type = $data['type'] ?? 'customer';

    if (strlen($search) < 3) {
        echo resultInfo(true, '', ['results' => []]);
        return;
    }

    $table = $type === 'vendor' ? 'vendor' : 'customer';
    $company = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_build_object(
            'results', COALESCE(
                (
                    SELECT json_agg(row_to_json(t))
                    FROM (
                        SELECT c.id, c.name,
                               COALESCE(NULLIF(c.phone, ''), ext.phone_numbers->>0) AS phone,
                               ext.emails->>0 AS email
                        FROM {$table} c
                        LEFT JOIN {$table}_ext ext ON ext.{$table}_id = c.id
                        WHERE c.name ILIKE '%' || :search || '%'
                        ORDER BY c.name
                        LIMIT 10
                    ) t
                ),
                '[]'::json
            )
        ) AS result
SQL;

    echo $company->getOne($query, ['search' => $search])['result'];
}

/**
 * Spalte der Lieferschein-Belegart: neues kivitendo-Schema record_type (Enum),
 * altes order_type (Text) — kivitendo benennt sie per Pg-upgrade2 um
 *
 * @param object $db Firmen-DB
 * @return string 'record_type' oder 'order_type'
 */
function deliveryOrderTypeColumn($db) {
    static $column = null;
    if ($column === null) {
        $row = $db->getOne(
            "SELECT column_name FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = 'delivery_orders' AND column_name IN ('record_type', 'order_type')
              ORDER BY column_name = 'record_type' DESC LIMIT 1"
        );
        $column = $row['column_name'] ?? 'record_type';
    }
    return $column;
}

/**
 * Belegart eines Faktura-Datensatzes aus dem Datensatz selbst ermitteln
 *
 * Der Typ des Aufrufers ist nur ein Hinweis auf die ID-Familie: ar/ap ziehen ihre
 * IDs aus der Sequenz glid, oe/delivery_orders aus id. Innerhalb einer Familie ist
 * eine ID eindeutig, zwischen den Familien nicht. Das alte kivitendo-Schema
 * (oe.quotation statt record_type, delivery_orders ohne record_type) lesen die
 * unqualifizierten Spalten über die Rückfall-Zeile schema_fallback.
 *
 * @param object $company   Firmen-DB
 * @param int    $fakturaID Beleg-ID
 * @param string $hintType  Belegart laut Route/Aufrufer
 * @return array|null ['type' => Belegart, 'table' => Haupttabelle, 'ledger_only' => bool]
 */
function resolveFakturaType($company, $fakturaID, $hintType) {
    $glFamily = in_array($hintType, ['invoice', 'purchase_invoice', 'credit_note', 'invoice_storno'], true);
    $sql = $glFamily ? <<<SQL
        SELECT COALESCE(
            (SELECT json_build_object('type', 'invoice', 'table', 'ar', 'ledger_only', false)
               FROM ar a WHERE a.id = :id),
            -- Eingangsrechnungen bucht und bearbeitet das Journal (Belegerfassung, Bank);
            -- die Faktura kann sie weder rechnen noch drucken
            (SELECT json_build_object('type', 'purchase_invoice', 'table', 'ap', 'ledger_only', true)
               FROM ap p WHERE p.id = :id)
        ) AS resolved
SQL
    : <<<SQL
        SELECT COALESCE(
            (SELECT json_build_object('type', CASE
                        WHEN rt = 'sales_quotation' THEN 'quotation'
                        WHEN rt IN ('request_quotation', 'purchase_quotation_intake') THEN 'request_quotation'
                        WHEN rt IN ('purchase_order', 'purchase_order_confirmation') THEN 'purchase_order'
                        ELSE 'order' END, 'table', 'oe', 'ledger_only', false)
               FROM (SELECT NULL::text AS record_type, NULL::boolean AS quotation) AS schema_fallback
               CROSS JOIN LATERAL (
                   SELECT COALESCE(record_type::text,
                            CASE WHEN o.vendor_id IS NOT NULL
                                 THEN CASE WHEN quotation THEN 'request_quotation' ELSE 'purchase_order' END
                                 ELSE CASE WHEN quotation THEN 'sales_quotation' ELSE 'sales_order' END END) AS rt
                     FROM oe o WHERE o.id = :id) AS oe_art),
            (SELECT json_build_object('type', CASE WHEN d.vendor_id IS NOT NULL THEN 'purchase_delivery_order' ELSE 'delivery_order' END,
                                      'table', 'delivery_orders', 'ledger_only', false)
               FROM delivery_orders d WHERE d.id = :id)
        ) AS resolved
SQL;
    $row = $company->getOne($sql, ['id' => intval($fakturaID)]);
    $resolved = !empty($row['resolved']) ? json_decode($row['resolved'], true) : null;
    // Ausgangsrechnungen: die Art (Rechnung, Gutschrift, Storno) bestimmt weiter die Route —
    // die Maske behandelt sie verschieden (Buchung, Zahlungen, E-Rechnung)
    if ($resolved && $resolved['table'] === 'ar' && in_array($hintType, ['invoice', 'credit_note', 'invoice_storno'], true)) {
        $resolved['type'] = $hintType;
    }
    return $resolved;
}

/**
 * Belegart für Schreibzugriffe: aus dem Datensatz, nicht aus dem Request
 *
 * Rechte und Tabellen hängen an der Belegart. Kommt sie ungeprüft aus dem
 * Request, ließe sich z. B. mit 'quotation' eine Bestellung löschen.
 *
 * @param object $company   Firmen-DB
 * @param int    $fakturaID Beleg-ID
 * @param string $hintType  Belegart laut Request
 * @return string Belegart
 * @throws ApiError FAKTURA_NOT_FOUND
 */
function resolveFakturaTypeForWrite($company, $fakturaID, $hintType) {
    $resolved = resolveFakturaType($company, $fakturaID, $hintType);
    if (!$resolved) {
        throw new ApiError('FAKTURA_NOT_FOUND', "Dokument {$fakturaID} nicht gefunden");
    }
    return $resolved['type'];
}

/**
 * Gibt die Tabellenkonfiguration für den jeweiligen Dokumenttyp zurück
 *
 * @param string $fakturaType Dokumenttyp (invoice, order, quotation, delivery_order …)
 * @return array Konfiguration mit main_table, items_table, items_fk (Fremdschlüssel der
 *               Positionen auf den Beleg: trans_id, bei Lieferscheinen delivery_order_id)
 */
function getFakturaTableConfig($fakturaType) {
    $configs = [
        'invoice' => [
            'main_table' => 'ar',
            'items_table' => 'invoice',
            'items_fk' => 'trans_id',
            'cv_table' => 'customer',
            'cv_column' => 'customer_id'
        ],
        'purchase_invoice' => [
            'main_table' => 'ap',
            'items_table' => 'invoice',
            'items_fk' => 'trans_id',
            'cv_table' => 'vendor',
            'cv_column' => 'vendor_id'
        ],
        'order' => [
            'main_table' => 'oe',
            'items_table' => 'orderitems',
            'items_fk' => 'trans_id',
            'cv_table' => 'customer',
            'cv_column' => 'customer_id'
        ],
        'purchase_order' => [
            'main_table' => 'oe',
            'items_table' => 'orderitems',
            'items_fk' => 'trans_id',
            'cv_table' => 'vendor',
            'cv_column' => 'vendor_id'
        ],
        'quotation' => [
            'main_table' => 'oe',
            'items_table' => 'orderitems',
            'items_fk' => 'trans_id',
            'cv_table' => 'customer',
            'cv_column' => 'customer_id'
        ],
        'request_quotation' => [
            'main_table' => 'oe',
            'items_table' => 'orderitems',
            'items_fk' => 'trans_id',
            'cv_table' => 'vendor',
            'cv_column' => 'vendor_id'
        ],
        'delivery_order' => [
            'main_table' => 'delivery_orders',
            'items_table' => 'delivery_order_items',
            'items_fk' => 'delivery_order_id',
            'cv_table' => 'customer',
            'cv_column' => 'customer_id'
        ],
        'purchase_delivery_order' => [
            'main_table' => 'delivery_orders',
            'items_table' => 'delivery_order_items',
            'items_fk' => 'delivery_order_id',
            'cv_table' => 'vendor',
            'cv_column' => 'vendor_id'
        ],
        'credit_note' => [
            'main_table' => 'ar',
            'items_table' => 'invoice',
            'items_fk' => 'trans_id',
            'cv_table' => 'customer',
            'cv_column' => 'customer_id'
        ],
        'invoice_storno' => [
            'main_table' => 'ar',
            'items_table' => 'invoice',
            'items_fk' => 'trans_id',
            'cv_table' => 'customer',
            'cv_column' => 'customer_id'
        ]
    ];

    return $configs[$fakturaType] ?? $configs['invoice'];
}

/**
 * Belegarten, deren Positionsmengen negativ geführt werden (wie kivitendo):
 * Gutschrift und Storno kehren die Ursprungsrechnung um.
 *
 * @param string $fakturaType Dokumenttyp
 * @return bool
 */
function hasNegativeQty($fakturaType) {
    return in_array($fakturaType, ['credit_note', 'invoice_storno']);
}

/**
 * Gibt die benötigte Berechtigung für einen Dokumenttyp zurück
 *
 * @param string $fakturaType Dokumenttyp
 * @return string Permission-Name
 */
function getPermissionForFakturaType($fakturaType) {
    $map = [
        'invoice' => 'invoice_edit',
        'purchase_invoice' => 'vendor_invoice_edit',
        'order' => 'sales_order_edit',
        'purchase_order' => 'purchase_order_edit',
        'quotation' => 'sales_quotation_edit',
        'request_quotation' => 'request_quotation_edit',
        'delivery_order' => 'sales_delivery_order_edit',
        'purchase_delivery_order' => 'purchase_delivery_order_edit',
        'credit_note' => 'invoice_edit',
        'invoice_storno' => 'invoice_edit',
    ];
    return $map[$fakturaType] ?? 'invoice_edit';
}

/**
 * Speichert Faktura-Daten
 *
 * @param array $data Request-Daten mit fakturaData und fakturaType
 * @return void Gibt JSON zurück
 */
function saveFakturaData($data) {
    // TODO: Implementierung für das Speichern von Faktura-Daten
    resultInfo(false, 'NOT_IMPLEMENTED', ['message' => 'Save faktura not yet implemented']);
}

/**
 * Erstellt ein neues leeres Faktura-Dokument (Auftrag, Angebot oder Rechnung)
 *
 * @param array $data Request-Daten mit fakturaType ('order', 'quotation', 'invoice')
 * @return void Gibt JSON mit der neuen Dokument-ID zurück
 */
function createFaktura($data) {
    $fakturaType = $data['fakturaType'] ?? 'order';
    $cvId = !empty($data['cvId']) ? intval($data['cvId']) : null;
    $cvSrc = $data['cvSrc'] ?? null; // 'C' = Kunde, 'V' = Lieferant
    $isVendor = ($cvSrc === 'V');
    $company = DbhCompany::begin();

    $effectiveType = resolveEffectiveFakturaType($fakturaType, $isVendor);
    permit(getPermissionForFakturaType($effectiveType));

    // Employee-ID aus Session-Login ermitteln
    $auth = DbhAuth::begin();
    $login = $auth->getLogin();
    $employee = $company->getOne(
        "SELECT id FROM employee WHERE login = :login",
        ['login' => $login]
    );
    $employeeId = $employee ? intval($employee['id']) : null;

    $res = createFakturaCore($company, $fakturaType, $cvId, $cvSrc, $employeeId);
    resultInfo(true, 'CREATED', $res);
}

/**
 * Verkaufs- → Einkaufs-Dokumenttyp (Kivitendo-Logik). Für Lieferanten ($isVendor)
 * wird der Einkaufstyp gemappt, sonst bleibt der Typ unverändert.
 */
function resolveEffectiveFakturaType($fakturaType, $isVendor) {
    if (!$isVendor) {
        return $fakturaType;
    }
    $typeMap = [
        'order' => 'purchase_order',
        'quotation' => 'request_quotation',
        'invoice' => 'purchase_invoice',
        'delivery_order' => 'purchase_delivery_order'
    ];
    return $typeMap[$fakturaType] ?? $fakturaType;
}

/**
 * Kern der Faktura-Erstellung – session-unabhängig (Mitarbeiter als Parameter).
 * Wird von createFaktura (HTTP) und vom eBay-Import (CLI) gemeinsam genutzt, damit
 * Nummernkreis- und Buchungslogik nur an einer Stelle existieren.
 *
 * @param object      $company     DbhCompany-Handle
 * @param string      $fakturaType  'invoice'|'order'|'quotation' (Verkaufssicht)
 * @param int|null    $cvId         Kunden-/Lieferanten-ID
 * @param string|null $cvSrc        'C' = Kunde, 'V' = Lieferant
 * @param int|null    $employeeId   Mitarbeiter-ID
 * @param array       $opts         Optionale Overrides: ['taxincluded' => bool]
 * @return array ['id'=>int, 'fakturaType'=>string, 'docNumber'=>mixed]
 */
function createFakturaCore($company, $fakturaType, $cvId, $cvSrc, $employeeId, $opts = []) {
    $isVendor = ($cvSrc === 'V');
    $effectiveType = resolveEffectiveFakturaType($fakturaType, $isVendor);

    // Defaults laden (currency_id)
    $defaults = $company->getOne("SELECT currency_id FROM defaults LIMIT 1");
    $currencyId = intval($defaults['currency_id']);

    // Steuerzone + taxincluded: aus Kunden-/Lieferantenstamm oder erste verfügbare
    $taxzoneId = null;
    $taxincluded = false;
    if ($cvId) {
        $cvTable = $isVendor ? 'vendor' : 'customer';
        // taxincluded_checked gibt es nur beim Kunden
        $taxCol = $isVendor ? 'false AS taxincluded_checked' : 'taxincluded_checked';
        $cvData = $company->getOne(
            "SELECT taxzone_id, {$taxCol} FROM {$cvTable} WHERE id = :id",
            ['id' => $cvId]
        );
        $taxzoneId = $cvData ? intval($cvData['taxzone_id']) : null;
        $taxincluded = !empty($cvData['taxincluded_checked']);
    }
    if (!$taxzoneId) {
        $taxzone = $company->getOne("SELECT id FROM tax_zones ORDER BY id LIMIT 1");
        $taxzoneId = $taxzone ? intval($taxzone['id']) : 4;
    }

    // Optionaler Override (z. B. eBay-Import: Preise sind brutto → taxincluded = true)
    if (array_key_exists('taxincluded', $opts)) {
        $taxincluded = (bool)$opts['taxincluded'];
    }

    // customer_id / vendor_id zuweisen
    $customerId = !$isVendor ? $cvId : null;
    $vendorId = $isVendor ? $cvId : null;

    // Dokumentnummer-Zähler und INSERT in einem atomaren CTE-Statement:
    //   WITH tmp AS (UPDATE defaults SET <col> = <col>::INT + 1 RETURNING <col>)
    //   INSERT INTO <table> (..., <numberfield>, ...) SELECT ..., (SELECT <col> FROM tmp), ...
    //   RETURNING id
    //
    // Mapping effectiveType → defaults-Spalte / Nummernfeld:
    //   order            → sonumber  / oe.ordnumber
    //   purchase_order   → ponumber  / oe.ordnumber
    //   quotation        → sqnumber  / oe.quonumber  (ordnumber bleibt '' – NOT NULL in oe)
    //   request_quotation→ rfqnumber / oe.quonumber
    //   invoice          → invnumber / ar.invnumber
    //   purchase_invoice → invnumber / ap.invnumber
    //   delivery_order   → sdonumber / delivery_orders.donumber
    //   purchase_delivery_order → pdonumber / delivery_orders.donumber

    if ($effectiveType === 'purchase_invoice') {
        // Eingangsrechnung → ap-Tabelle
        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1 RETURNING invnumber)
            INSERT INTO ap (invnumber, transdate, gldate, employee_id, vendor_id, taxzone_id, currency_id, invoice, taxincluded)
            SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :vendor_id, :taxzone_id, :currency_id, true, :taxincluded
            RETURNING id, invnumber
SQL;
        $result = $company->getOne($query, [
            ':employee_id'  => $employeeId,
            ':vendor_id'    => $vendorId,
            ':taxzone_id'   => $taxzoneId,
            ':currency_id'  => $currencyId,
            ':taxincluded'  => $taxincluded ? 'true' : 'false'
        ]);

    } elseif ($effectiveType === 'invoice') {
        // Ausgangsrechnung → ar-Tabelle
        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1 RETURNING invnumber)
            INSERT INTO ar (invnumber, transdate, gldate, employee_id, customer_id, taxzone_id, currency_id, invoice, taxincluded)
            SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :customer_id, :taxzone_id, :currency_id, true, :taxincluded
            RETURNING id, invnumber
SQL;
        $result = $company->getOne($query, [
            ':employee_id'  => $employeeId,
            ':customer_id'  => $customerId,
            ':taxzone_id'   => $taxzoneId,
            ':currency_id'  => $currencyId,
            ':taxincluded'  => $taxincluded ? 'true' : 'false'
        ]);

    } elseif ($effectiveType === 'quotation' || $effectiveType === 'request_quotation') {
        // Angebot / Anfrage → oe-Tabelle, Nummer in quonumber; ordnumber = '' (NOT NULL)
        $defaultsCol = ($effectiveType === 'quotation') ? 'sqnumber' : 'rfqnumber';
        $recordType  = ($effectiveType === 'quotation') ? 'sales_quotation' : 'request_quotation';

        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET {$defaultsCol} = COALESCE({$defaultsCol}::INT, 0) + 1 RETURNING {$defaultsCol})
            INSERT INTO oe (ordnumber, quonumber, transdate, employee_id, customer_id, vendor_id, taxzone_id, currency_id, record_type, taxincluded)
            SELECT '', (SELECT {$defaultsCol} FROM tmp), CURRENT_DATE, :employee_id, :customer_id, :vendor_id, :taxzone_id, :currency_id, :record_type, :taxincluded
            RETURNING id, quonumber
SQL;
        $result = $company->getOne($query, [
            ':employee_id'  => $employeeId,
            ':customer_id'  => $customerId,
            ':vendor_id'    => $vendorId,
            ':taxzone_id'   => $taxzoneId,
            ':currency_id'  => $currencyId,
            ':record_type'  => $recordType,
            ':taxincluded'  => $taxincluded ? 'true' : 'false'
        ]);

    } elseif ($effectiveType === 'delivery_order' || $effectiveType === 'purchase_delivery_order') {
        // Lieferschein (Verkauf/Einkauf) → delivery_orders, Nummernkreis wie kivitendo
        $defaultsCol = ($effectiveType === 'delivery_order') ? 'sdonumber' : 'pdonumber';
        $recordType  = ($effectiveType === 'delivery_order') ? 'sales_delivery_order' : 'purchase_delivery_order';
        $typeCol     = deliveryOrderTypeColumn($company);

        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET {$defaultsCol} = COALESCE({$defaultsCol}::INT, 0) + 1 RETURNING {$defaultsCol})
            INSERT INTO delivery_orders (donumber, transdate, employee_id, customer_id, vendor_id, taxzone_id, currency_id, {$typeCol}, taxincluded)
            SELECT (SELECT {$defaultsCol} FROM tmp), CURRENT_DATE, :employee_id, :customer_id, :vendor_id, :taxzone_id, :currency_id, :record_type, :taxincluded
            RETURNING id, donumber
SQL;
        $result = $company->getOne($query, [
            ':employee_id'  => $employeeId,
            ':customer_id'  => $customerId,
            ':vendor_id'    => $vendorId,
            ':taxzone_id'   => $taxzoneId,
            ':currency_id'  => $currencyId,
            ':record_type'  => $recordType,
            ':taxincluded'  => $taxincluded ? 'true' : 'false'
        ]);

    } else {
        // Auftrag (Verkauf oder Einkauf) → oe-Tabelle, Nummer in ordnumber
        // Neue Verkaufsaufträge starten als Auftragseingang (unbestätigt) und werden
        // über den "Bestätigt"-Schalter (setOrderConfirmed) zu sales_order.
        $defaultsCol = ($effectiveType === 'purchase_order') ? 'ponumber' : 'sonumber';
        $recordType  = ($effectiveType === 'purchase_order') ? 'purchase_order' : 'sales_order_intake';

        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET {$defaultsCol} = COALESCE({$defaultsCol}::INT, 0) + 1 RETURNING {$defaultsCol})
            INSERT INTO oe (ordnumber, transdate, employee_id, customer_id, vendor_id, taxzone_id, currency_id, record_type, taxincluded)
            SELECT (SELECT {$defaultsCol} FROM tmp), CURRENT_DATE, :employee_id, :customer_id, :vendor_id, :taxzone_id, :currency_id, :record_type, :taxincluded
            RETURNING id, ordnumber
SQL;
        $result = $company->getOne($query, [
            ':employee_id'  => $employeeId,
            ':customer_id'  => $customerId,
            ':vendor_id'    => $vendorId,
            ':taxzone_id'   => $taxzoneId,
            ':currency_id'  => $currencyId,
            ':record_type'  => $recordType,
            ':taxincluded'  => $taxincluded ? 'true' : 'false'
        ]);
    }

    // Dokumentnummer aus dem RETURNING-Ergebnis extrahieren
    $docNumber = $result['invnumber'] ?? $result['ordnumber'] ?? $result['quonumber'] ?? $result['donumber'] ?? null;

    return [
        'id' => intval($result['id']),
        'fakturaType' => $effectiveType,
        'docNumber' => $docNumber
    ];
}

/**
 * Fügt eine neue Faktura-Position ein
 *
 * @param array $data Request-Daten mit item-Daten und fakturaType
 * @return void Gibt JSON mit der neuen Position zurück
 */
function createFakturaItem($data) {
    $fakturaID = intval($data['fakturaID']);
    $fakturaType = $data['fakturaType'] ?? 'invoice';
    $item = $data['item'];

    $company = DbhCompany::begin();

    $fakturaType = resolveFakturaTypeForWrite($company, $fakturaID, $fakturaType);
    permit(getPermissionForFakturaType($fakturaType));

    $newId = createFakturaItemCore($company, $fakturaType, $fakturaID, $item);

    $tableConfig = getFakturaTableConfig($fakturaType);
    $itemsTable = $tableConfig['items_table'];
    $itemsFk = $tableConfig['items_fk'];
    $mainTable = $tableConfig['main_table'];

    // Komplette Position mit allen Daten zurückgeben (wie in getFakturaData)
    $selectQuery = <<<SQL
        SELECT json_build_object(
            'success', true,
            'payload', (
                SELECT row_to_json(item)
                FROM (
                    SELECT
                        {$itemsTable}.*,
                        parts.partnumber,
                        parts.part_type,
                        parts.classification_id,
                        (
                            SELECT row_to_json(buchungsziel)
                            FROM (
                                SELECT
                                    c2.id AS income_chart_id,
                                    tk.tax_id,
                                    tx.chart_id AS tax_chart_id,
                                    tx.rate
                                FROM parts p
                                LEFT JOIN buchungsgruppen bg ON p.buchungsgruppen_id = bg.id
                                LEFT JOIN taxzone_charts tc ON bg.id = tc.buchungsgruppen_id
                                LEFT JOIN chart c2 ON tc.income_accno_id = c2.id
                                -- nur am Belegdatum gültige Schlüssel (V24)
                                LEFT JOIN taxkeys tk ON tk.chart_id = c2.id
                                    AND tk.startdate <= COALESCE((SELECT transdate FROM {$mainTable} WHERE id = {$itemsTable}.{$itemsFk}), current_date)
                                LEFT JOIN tax tx ON tx.id = tk.tax_id
                                WHERE tc.taxzone_id = (SELECT taxzone_id FROM {$mainTable} WHERE id = {$itemsTable}.{$itemsFk})
                                    AND p.id = parts.id
                                ORDER BY tk.startdate DESC
                                LIMIT 1
                            ) AS buchungsziel
                        ) AS buchungsziel
                    FROM {$itemsTable}
                    LEFT JOIN parts ON {$itemsTable}.parts_id = parts.id
                    WHERE {$itemsTable}.id = :itemId
                ) AS item
            )
        ) AS result
SQL;

    echo $company->getOne($selectQuery, ['itemId' => $newId])['result'];
}

/**
 * Kern: fügt eine Faktura-Position ein und liefert die neue Positions-ID zurück.
 * Session-unabhängig; von createFakturaItem (HTTP) und vom eBay-Import (CLI) genutzt.
 *
 * @param object $company     DbhCompany-Handle
 * @param string $fakturaType  Dokumenttyp (z. B. 'invoice')
 * @param int    $fakturaID    trans_id des Belegs
 * @param array  $item         ['parts_id','description','longdescription','qty','sellprice','discount','unit']
 * @return int   Neue Positions-ID
 */
function createFakturaItemCore($company, $fakturaType, $fakturaID, $item) {
    $tableConfig = getFakturaTableConfig($fakturaType);
    $itemsTable = $tableConfig['items_table'];
    $itemsFk = $tableConfig['items_fk'];
    // Gutschrift/Storno: Menge immer negativ (wie bei der Umwandlung aus der Rechnung)
    $qtyExpr = hasNegativeQty($fakturaType) ? '-abs(:qty)' : ':qty';

    // Nächste Position ermitteln
    $positionQuery = "SELECT COALESCE(MAX(position), 0) + 1 AS next_position FROM {$itemsTable} WHERE {$itemsFk} = :fakturaID";
    $nextPosition = $company->getOne($positionQuery, ['fakturaID' => $fakturaID])['next_position'];

    // Basis-Spalten für alle Tabellentypen
    $columns = [
        $itemsFk,
        'parts_id',
        'position',
        'description',
        'longdescription',
        'qty',
        'sellprice',
        'discount',
        'unit'
    ];

    $values = [
        ':trans_id',
        ':parts_id',
        ':position',
        ':description',
        // Leerer Langtext -> Langtext des Artikels (parts.notes), wie in kivitendo
        "COALESCE(NULLIF(:longdescription, ''), (SELECT notes FROM parts WHERE id = :ld_parts_id), '')",
        $qtyExpr,
        ':sellprice',
        ':discount',
        // Leere Einheit -> Einheit des Artikels (parts.unit), sonst NULL.
        // Verhindert FK-Verletzung (orderitems_unit_fkey/invoice_unit_fkey),
        // wenn das Frontend keine Einheit mitsendet (z. B. bei Dienstleistungen).
        "COALESCE(NULLIF(:unit, ''), (SELECT unit FROM parts WHERE id = :unit_parts_id))"
    ];

    $params = [
        'trans_id' => $fakturaID,
        'parts_id' => intval($item['parts_id']),
        'position' => $nextPosition,
        'description' => $item['description'] ?? '',
        'longdescription' => $item['longdescription'] ?? '',
        'qty' => floatval($item['qty'] ?? 1),
        'sellprice' => floatval($item['sellprice'] ?? 0),
        'discount' => floatval($item['discount'] ?? 0),
        'unit' => $item['unit'] ?? '',
        'unit_parts_id' => intval($item['parts_id']),
        'ld_parts_id' => intval($item['parts_id'])
    ];

    // Zusätzliche Spalten nur für Belege in der invoice-Tabelle (Rechnung, Gutschrift, Storno)
    if ($itemsTable === 'invoice') {
        $columns[] = 'fxsellprice';
        $values[] = ':fxsellprice';
        $params['fxsellprice'] = floatval($item['sellprice'] ?? 0);
    }

    $columnList = implode(', ', $columns);
    $valueList = implode(', ', $values);

    // Insert mit RETURNING für die generierte ID
    $query = "INSERT INTO {$itemsTable} ({$columnList}) VALUES ({$valueList}) RETURNING id";

    $result = $company->getOne($query, $params);
    return $result['id'];
}

/**
 * Löscht eine Faktura-Position
 *
 * @param array $data Request-Daten mit itemID und fakturaType
 * @return void Gibt JSON zurück
 */
function deleteFakturaItem($data) {
    $itemID = intval($data['itemID']);
    $fakturaType = $data['fakturaType'] ?? 'invoice';

    $company = DbhCompany::begin();

    $tableConfig = getFakturaTableConfig($fakturaType);
    $itemsTable = $tableConfig['items_table'];
    $itemsFk = $tableConfig['items_fk'];

    // trans_id ermitteln für Positionsneuberechnung
    $transIdQuery = "SELECT {$itemsFk} AS trans_id FROM {$itemsTable} WHERE id = :itemID";
    $transId = $company->getOne($transIdQuery, ['itemID' => $itemID])['trans_id'] ?? null;

    if (!$transId) {
        resultInfo(false, 'ITEM_NOT_FOUND', ['message' => 'Position nicht gefunden']);
        return;
    }

    // Recht nach dem Beleg, zu dem die Position gehört
    $fakturaType = resolveFakturaTypeForWrite($company, $transId, $fakturaType);
    permit(getPermissionForFakturaType($fakturaType));

    // Position löschen
    $deleteQuery = "DELETE FROM {$itemsTable} WHERE id = :itemID";
    $company->execute($deleteQuery, ['itemID' => $itemID]);

    // Positionen neu nummerieren
    $renumberQuery = <<<SQL
        UPDATE {$itemsTable}
        SET position = subquery.new_position
        FROM (
            SELECT id, ROW_NUMBER() OVER (ORDER BY position) AS new_position
            FROM {$itemsTable}
            WHERE {$itemsFk} = :transId
        ) AS subquery
        WHERE {$itemsTable}.id = subquery.id
SQL;

    $company->execute($renumberQuery, ['transId' => $transId]);

    // Kopfbeträge neu berechnen — auch wenn die letzte Position gelöscht wurde
    // und der Editor danach nichts mehr zu speichern hat
    recalcFakturaTotals($company, $fakturaType, $transId);

    resultInfo(true, 'DELETED', ['message' => 'Position gelöscht']);
}

/**
 * Ersetzt den Artikel EINER bestehenden Position (parts_id + zugehörige Felder).
 * Bewusst isoliertes Einzel-Update per id – kann konstruktionsbedingt KEINE
 * andere Position beeinflussen (anders als das Bulk-Update, das parts_id
 * absichtlich nicht anfasst).
 *
 * @param int    $data['item_id']     ID der Position
 * @param int    $data['parts_id']    Neue Artikel-ID
 * @param string $data['description'] Beschreibung
 * @param float  $data['qty']         Menge
 * @param float  $data['sellprice']   Verkaufspreis
 * @param string $data['unit']        Einheit
 * @param string $data['fakturaType'] order|invoice|quotation...
 * @testdata {"item_id": 1, "parts_id": 1, "description": "Test", "qty": 1, "sellprice": 0, "unit": "Stk", "fakturaType": "order"}
 */
function replaceFakturaItemArticle($data) {
    $fakturaType = $data['fakturaType'] ?? 'order';
    $itemId  = intval($data['item_id'] ?? 0);
    $partsId = intval($data['parts_id'] ?? 0);
    if ($itemId <= 0 || $partsId <= 0) {
        resultInfo(false, 'INVALID_ARGS', 'item_id und parts_id erforderlich');
        return;
    }

    $company = DbhCompany::begin();
    permit(getPermissionForFakturaType($fakturaType));

    $tableConfig = getFakturaTableConfig($fakturaType);
    $itemsTable = $tableConfig['items_table']; // kontrollierter Tabellenname (kein User-Input)
    // Gutschrift/Storno: Menge immer negativ
    $qtyExpr = hasNegativeQty($fakturaType) ? '-abs(:qty)' : ':qty';

    // Langtext folgt dem neuen Artikel (parts.notes), wie in kivitendo
    $row = $company->getOne(
        "UPDATE {$itemsTable}
            SET parts_id = :parts_id,
                description = :description,
                longdescription = COALESCE((SELECT notes FROM parts WHERE id = :ld_parts_id), ''),
                qty = {$qtyExpr},
                sellprice = :sellprice,
                unit = COALESCE(NULLIF(:unit, ''), unit)
          WHERE id = :item_id
          RETURNING id, longdescription",
        [
            ':parts_id'    => $partsId,
            ':ld_parts_id' => $partsId,
            ':description' => $data['description'] ?? '',
            ':qty'         => $data['qty'] ?? 1,
            ':sellprice'   => $data['sellprice'] ?? 0,
            ':unit'        => $data['unit'] ?? '',
            ':item_id'     => $itemId,
        ]
    );

    resultInfo(true, 'REPLACED', $row ?: []);
}

/**
 * Speichert eine bearbeitete Position als neuen Artikel in den Stammdaten und
 * ordnet die Position dem neuen Artikel zu.
 *
 * Der Artikeltyp kommt aus dem Store (part_type der Position). Buchungsgruppe und
 * Einheit erbt der neue Artikel vom bisherigen Artikel der Position, die
 * Artikelnummer kommt aus dem Nummernkreis (articlenumber/servicenumber).
 *
 * @param string $data['fakturaType']     Belegart
 * @param int    $data['item_id']         Positions-ID
 * @param string $data['description']     Beschreibung (Pflicht)
 * @param string $data['longdescription'] Langtext -> parts.notes
 * @param float  $data['sellprice']       Verkaufspreis
 * @param string $data['unit']            Einheit (leer -> Einheit des bisherigen Artikels)
 * @param float  $data['qty']             Menge der Position
 * @param float  $data['discount']        Rabatt der Position (Faktor 0-1)
 * @param string $data['part_type']       'part' oder 'service'
 * @param string $data['partnumber']      Gewünschte Artikelnummer (leer -> Nummernkreis)
 * @return void JSON {parts_id, partnumber, unit, buchungsziel} — buchungsziel (Erlöskonto,
 *              Steuerkonto, tax_id, Satz) für Steuerzone und Datum des Belegs, damit die
 *              Faktura die Umsatzsteuer der Position sofort richtig rechnet
 * @testdata {"fakturaType": "quotation", "item_id": 1, "description": "Test Artikel", "part_type": "part"}
 */
function saveFakturaItemAsNewPart($data) {
    $fakturaType = $data['fakturaType'] ?? 'order';
    $itemId      = intval($data['item_id'] ?? 0);
    $description = trim($data['description'] ?? '');
    $partType    = in_array($data['part_type'] ?? '', ['part', 'service'], true) ? $data['part_type'] : 'part';

    if ($itemId <= 0 || $description === '') {
        resultInfo(false, 'INVALID_ARGS', 'item_id und description erforderlich');
        return;
    }

    $company = DbhCompany::begin();
    permit(getPermissionForFakturaType($fakturaType));

    $tableConfig = getFakturaTableConfig($fakturaType);
    $itemsTable  = $tableConfig['items_table']; // kontrollierte Tabellennamen (kein User-Input)
    $itemsFk     = $tableConfig['items_fk'];
    $mainTable   = $tableConfig['main_table'];

    $partnumber = trim($data['partnumber'] ?? '');
    if ($partnumber !== '') {
        // Vom Benutzer gewählte Nummer: muss frei sein
        if ($company->getOne("SELECT 1 FROM parts WHERE partnumber = :pn", [':pn' => $partnumber])) {
            resultInfo(false, 'PARTNUMBER_EXISTS', ['partnumber' => $partnumber]);
            return;
        }
    } else {
        $numberField = ($partType === 'service') ? 'servicenumber' : 'articlenumber';
        $partnumber  = nextFreeNumber($company, $numberField, 'parts', 'partnumber');
    }

    $sellprice = floatval($data['sellprice'] ?? 0);
    $notes     = trim($data['longdescription'] ?? '');

    $row = $company->getOne(<<<SQL
        WITH src AS (
            SELECT p.buchungsgruppen_id, p.unit AS part_unit, m.taxzone_id, m.transdate
            FROM {$itemsTable} i
            LEFT JOIN parts p ON p.id = i.parts_id
            LEFT JOIN {$mainTable} m ON m.id = i.{$itemsFk}
            WHERE i.id = :item_id
        ),
        np AS (
            INSERT INTO parts (partnumber, description, part_type, buchungsgruppen_id, sellprice, unit, notes, obsolete)
            SELECT :partnumber, :description, :part_type,
                   COALESCE(src.buchungsgruppen_id,
                            (SELECT id FROM buchungsgruppen WHERE NOT obsolete ORDER BY sortkey LIMIT 1)),
                   :sellprice,
                   COALESCE(NULLIF(:unit, ''), src.part_unit, 'Stck'),
                   :notes, FALSE
            FROM src
            RETURNING id, partnumber, unit, buchungsgruppen_id
        ),
        upd AS (
            UPDATE {$itemsTable} i
               SET parts_id        = np.id,
                   description     = :description2,
                   longdescription = :notes2,
                   sellprice       = :sellprice2,
                   unit            = np.unit,
                   qty             = :qty,
                   discount        = :discount
              FROM np
             WHERE i.id = :item_id2
            RETURNING i.id
        )
        SELECT np.id AS parts_id, np.partnumber, np.unit,
               -- Buchungsziel wie beim Laden der Positionen: Buchungsgruppe → taxzone_charts
               -- → Erlöskonto → am Belegdatum gültiger Steuerschlüssel
               (
                   SELECT row_to_json(bz)
                   FROM (
                       SELECT c2.id AS income_chart_id, tk.tax_id, tx.chart_id AS tax_chart_id, tx.rate
                       FROM taxzone_charts tc
                       LEFT JOIN chart c2 ON c2.id = tc.income_accno_id
                       LEFT JOIN taxkeys tk ON tk.chart_id = c2.id
                           AND tk.startdate <= COALESCE(src.transdate, current_date)
                       LEFT JOIN tax tx ON tx.id = tk.tax_id
                       WHERE tc.buchungsgruppen_id = np.buchungsgruppen_id
                         AND tc.taxzone_id = COALESCE(src.taxzone_id, 0)
                       ORDER BY tk.startdate DESC
                       LIMIT 1
                   ) AS bz
               ) AS buchungsziel
        FROM np, src
    SQL, [
        ':item_id'      => $itemId,
        ':item_id2'     => $itemId,
        ':partnumber'   => $partnumber,
        ':description'  => $description,
        ':description2' => $description,
        ':part_type'    => $partType,
        ':sellprice'    => $sellprice,
        ':sellprice2'   => $sellprice,
        ':unit'         => trim($data['unit'] ?? ''),
        ':notes'        => $notes,
        ':notes2'       => $notes,
        ':qty'          => floatval($data['qty'] ?? 1),
        ':discount'     => floatval($data['discount'] ?? 0),
    ]);

    if (!$row) {
        resultInfo(false, 'ITEM_NOT_FOUND', 'Position nicht gefunden');
        return;
    }

    $row['buchungsziel'] = !empty($row['buchungsziel']) ? json_decode($row['buchungsziel'], true) : null;
    resultInfo(true, 'CREATED', $row);
}

/**
 * Belegnummern für Barzahlungen eines Belegs vergeben (Faktura-Editor).
 *
 * Der Editor schickt je Zahlung zwei Beine (Forderungs-/Verbindlichkeitskonto
 * und Geldkonto) mit identischem source. Liegt das Geldkonto auf einem
 * Kassenkonto, ist das Belegfeld eine Kassenbuch-Belegnummer und muss
 * fortlaufend sein — der Wert aus dem Browser zählt deshalb nicht:
 *  - Bestehende Zahlungen (acc_trans_id > 0) behalten die im Hauptbuch
 *    gespeicherte Nummer, auch wenn der Editor etwas anderes schickt.
 *  - Neue Zahlungen bekommen nextBelegnummer(); mehrere neue in einem
 *    Speichervorgang zählen lokal hoch, weil noch nichts geschrieben ist.
 *  - Das Gegenbein wird über acc_trans_id bzw. (bei neuen) über Datum, Betrag,
 *    Memo und bisherigem source gefunden und bekommt dieselbe Nummer.
 * Bankzahlungen bleiben unberührt. Muss VOR dem Löschen der alten Zahlungen
 * laufen, damit deren Nummern beim Ermitteln der nächsten noch mitzählen.
 *
 * Hintergrund: Die vom Editor beim Öffnen vorgemerkte Nummer war beim Speichern
 * oft veraltet (Kassenbuch: 393 doppelt, 391 nach 392/393), und ins Belegfeld
 * getippte Rechnungsnummern zogen die Folge in den Rechnungsnummernkreis (252580).
 *
 * @param object $db          DbhCompany
 * @param int    $fakturaID   ar.id / ap.id
 * @param string $paymentLink '%AR_paid%' | '%AP_paid%'
 * @param array  $entries     paymentEntries aus dem Request
 * @return array paymentEntries mit gesetztem source für Kassenzahlungen
 */
function _faktura_kassenBelegnummern($db, $fakturaID, $paymentLink, array $entries) {
    if (!$entries) return $entries;

    // Kassenkonten und die bisherigen Kassen-Belegnummern dieses Belegs — eine Abfrage
    $rows = $db->getAll("
        SELECT ch.id AS chart_id, at.acc_trans_id, at.source
        FROM chart ch
        LEFT JOIN acc_trans at ON at.chart_id = ch.id AND at.trans_id = :tid AND at.chart_link LIKE '{$paymentLink}'
        WHERE " . kassenkontoBedingung('ch'),
        ['tid' => $fakturaID]) ?: [];
    $cashCharts = []; $stored = [];
    foreach ($rows as $r) {
        $cashCharts[intval($r['chart_id'])] = true;
        if ($r['acc_trans_id']) $stored[intval($r['acc_trans_id'])] = trim((string)$r['source']);
    }
    if (!$cashCharts) return $entries;

    $assigned = [];   // "chart:jahr" => zuletzt in diesem Speichervorgang vergebene Nummer
    $done     = [];   // Index => schon nummeriert
    foreach ($entries as $i => $e) {
        if (isset($done[$i]) || !isset($cashCharts[intval($e['chart_id'] ?? 0)])) continue;
        $accTransId = intval($e['acc_trans_id'] ?? 0);
        if ($accTransId > 0 && !empty($stored[$accTransId])) {
            $beleg = $stored[$accTransId];
        } else {
            $transdate = $e['transdate'] ?? date('Y-m-d');
            $key  = intval($e['chart_id']) . ':' . substr((string)$transdate, 0, 4);
            $next = intval(nextBelegnummer($db, intval($e['chart_id']), $transdate));
            if (isset($assigned[$key]) && $assigned[$key] >= $next) $next = $assigned[$key] + 1;
            $assigned[$key] = $next;
            $beleg = strval($next);
        }
        $entries[$i]['source'] = $beleg;
        $done[$i] = true;

        // Gegenbein: gleiche acc_trans_id, sonst gleiches Datum, umgekehrter Betrag, gleiches Memo/source
        foreach ($entries as $j => $p) {
            if (isset($done[$j]) || isset($cashCharts[intval($p['chart_id'] ?? 0)])) continue;
            $same = $accTransId > 0
                ? intval($p['acc_trans_id'] ?? 0) === $accTransId
                : (($p['transdate'] ?? null) === ($e['transdate'] ?? null)
                   && abs(floatval($p['amount'] ?? 0) + floatval($e['amount'] ?? 0)) < 0.005
                   && ($p['memo'] ?? '') === ($e['memo'] ?? '')
                   && ($p['source'] ?? '') === ($e['source'] ?? ''));
            if ($same) { $entries[$j]['source'] = $beleg; $done[$j] = true; break; }
        }
    }
    return $entries;
}

/**
 * Aktualisiert mehrere Faktura-Positionen in einem Query (Bulk-Update)
 * und verarbeitet die Buchungen für acc_trans
 *
 * @param array $data Request-Daten mit items Array, fakturaType, accTransEntries, paymentEntries
 * @return void Gibt JSON zurück
 * @testdata {"fakturaID": 4, "fakturaType": "invoice", "items": [], "accTransEntries": [], "paymentEntries": [], "netAmount": 100, "grossAmount": 119}
 */
function updateFakturaItems($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);
    $fakturaType = $data['fakturaType'] ?? 'invoice';
    $items = $data['items'] ?? [];
    $accTransEntries = $data['accTransEntries'] ?? [];
    $updatePayments = array_key_exists('paymentEntries', $data);
    $paymentEntries = $updatePayments ? ($data['paymentEntries'] ?? []) : [];
    $netAmount = floatval($data['netAmount'] ?? 0);
    $grossAmount = floatval($data['grossAmount'] ?? 0);

    if ($fakturaID <= 0) {
        resultInfo(false, 'INVALID_FAKTURA_ID', ['message' => 'Ungültige Faktura-ID']);
        return;
    }

    $company = DbhCompany::begin();

    $fakturaType = resolveFakturaTypeForWrite($company, $fakturaID, $fakturaType);
    permit(getPermissionForFakturaType($fakturaType));

    $tableConfig = getFakturaTableConfig($fakturaType);
    $itemsTable = $tableConfig['items_table'];
    $itemsFk = $tableConfig['items_fk'];
    $mainTable = $tableConfig['main_table'];
    $isInvoiceType = in_array($fakturaType, ['invoice', 'purchase_invoice']);
    $isOeType = in_array($fakturaType, ['order', 'purchase_order', 'quotation', 'request_quotation']);
    $paymentLink = ($mainTable === 'ap') ? '%AP_paid%' : '%AR_paid%';

    // 1. Items aktualisieren (Bulk-Update) falls vorhanden
    if (!empty($items)) {
        $columns = [
            'id' => 'integer',
            'position' => 'integer',
            'description' => 'text',
            'longdescription' => 'text',
            'qty' => 'numeric',
            'sellprice' => 'numeric',
            'discount' => 'numeric',
            'unit' => 'text'
        ];

        if ($isInvoiceType) {
            $columns['fxsellprice'] = 'numeric';
        }

        $company->updateAll($itemsTable, $columns, $items, 'id');
    }

    // 2a. Bei Aufträgen/Angeboten: Beträge in oe-Tabelle aktualisieren
    if ($isOeType) {
        $updateOeQuery = <<<SQL
            UPDATE oe SET
                netamount = :netAmount,
                amount = :grossAmount
            WHERE id = :fakturaID
SQL;
        $company->execute($updateOeQuery, [
            'fakturaID' => $fakturaID,
            'netAmount' => $netAmount,
            'grossAmount' => $grossAmount
        ]);
    }

    // 2b. Gutschrift/Storno: Mengen immer negativ halten (auch wenn ein Client
    // eine positive Menge schickt) und Kopfbeträge in SQL aus den Positionen
    // neu berechnen. Der Editor bucht diese Typen nicht ins Hauptbuch (keine
    // accTransEntries), deshalb greift der ar-Update unter 3. nicht — ohne
    // diesen Schritt bliebe der bei der Umwandlung kopierte Betrag der
    // Ursprungsrechnung stehen.
    if (hasNegativeQty($fakturaType)) {
        $company->execute(
            "UPDATE {$itemsTable} SET qty = -abs(qty) WHERE {$itemsFk} = :fakturaID AND qty > 0",
            ['fakturaID' => $fakturaID]
        );
        recalcFakturaTotals($company, $fakturaType, $fakturaID);
    }

    // Geschützte Bank-Buchungen ermitteln: acc_trans-Einträge, die zu einer noch
    // GEBUCHTEN Bankzuordnung gehören. Erkennung über das stabile Mapping
    // bank_transaction_acc_trans ODER (für Alt-Buchungen) über das Memo
    // '...Umsatz #<id>' — unabhängig von source (source trägt jetzt die Belegnummer).
    // Solche Einträge dürfen vom Faktura-Editor nicht gelöscht/verändert werden
    // (FK bank_transaction_acc_trans_acc_trans_id_fkey, Bankabstimmung).
    // Bereits stornierte/verwaiste Einträge sind hier NICHT enthalten und werden
    // dadurch im Editor wieder bereinigbar (löschen/editieren).
    $protectedBankIds = [];
    $protectExclusion = '';
    $protectParams = [];
    if ($isInvoiceType) {
        $protectedRows = $company->getAll(<<<SQL
            SELECT pa.acc_trans_id
            FROM acc_trans pa
            WHERE pa.trans_id = :fakturaID
                AND EXISTS (
                    SELECT 1 FROM bank_transactions bt
                    WHERE EXISTS (SELECT 1 FROM bank_transactions_ext bte WHERE bte.bank_transaction_id = bt.id AND bte.match_status = 'booked')
                        AND (
                            EXISTS (
                                SELECT 1 FROM bank_transaction_acc_trans bat
                                WHERE bat.bank_transaction_id = bt.id
                                    AND bat.acc_trans_id = pa.acc_trans_id
                            )
                            OR pa.memo LIKE '%Umsatz #' || bt.id
                        )
                )
SQL, ['fakturaID' => $fakturaID]);
        $protectedBankIds = array_map(static fn($r) => intval($r['acc_trans_id']), $protectedRows);
        if (!empty($protectedBankIds)) {
            $ph = [];
            foreach ($protectedBankIds as $i => $pid) {
                $ph[] = ":pb{$i}";
                $protectParams["pb{$i}"] = $pid;
            }
            $protectExclusion = ' AND acc_trans_id NOT IN (' . implode(',', $ph) . ')';
        }
    }

    // Gegenbein einer Zahlung erkennen: zu jedem Zahlungs-Bein (Geldkonto,
    // chart_link '…_paid') gehört ein Bein auf dem Forderungs-/Verbindlichkeits-
    // konto mit gleichem Datum und umgekehrtem Vorzeichen. Es trägt chart_link
    // 'AR' bzw. 'AP' — genau wie die Erstbuchung der Rechnung — und war deshalb
    // von der Positions-Löschung (2b) nicht zu unterscheiden. Folge: Wer nach
    // einer Bar- oder Editor-Zahlung nur noch eine Position änderte, verlor die
    // Gegenbuchung; das Geldkonto-Bein blieb stehen und der Buchungssatz war
    // unausgeglichen (Rechnungen 251955 und 252267). Die Bedingung wird in 2b
    // zum Schutz und in 2c zum gezielten Löschen vor dem Neuschreiben genutzt.
    $counterLegCond = <<<SQL
        EXISTS (
            SELECT 1 FROM acc_trans p
            WHERE p.trans_id = acc_trans.trans_id
              AND p.acc_trans_id <> acc_trans.acc_trans_id
              AND p.chart_link LIKE '{$paymentLink}'
              AND p.transdate = acc_trans.transdate
              AND p.amount = -acc_trans.amount
        )
SQL;

    // 2b. Bei Rechnungen: Positions-Buchungen verarbeiten
    if ($isInvoiceType && !empty($accTransEntries)) {
        // Alte Buchungen löschen (NICHT die existierenden Zahlungen und NICHT
        // deren Gegenbeine!). Geschützte Bank-Buchungen (gebuchte Bankzuordnung)
        // ebenfalls nicht anfassen — deren Forderungs-Gegenbuchung würde sonst
        // mitgelöscht und die FK bank_transaction_acc_trans_acc_trans_id_fkey verletzt.
        $deleteQuery = "DELETE FROM acc_trans WHERE trans_id = :fakturaID AND chart_link NOT LIKE '{$paymentLink}' AND NOT {$counterLegCond}{$protectExclusion}";
        $company->execute($deleteQuery, array_merge(['fakturaID' => $fakturaID], $protectParams));

        // Neue Positions-Buchungen einfügen
        foreach ($accTransEntries as $entry) {
            $insertQuery = <<<SQL
                INSERT INTO acc_trans (
                    trans_id,
                    chart_id,
                    amount,
                    transdate,
                    gldate,
                    tax_id,
                    taxkey,
                    chart_link
                )
                SELECT
                    :trans_id,
                    :chart_id,
                    :amount,
                    :transdate,
                    :transdate,
                    :tax_id,
                    :taxkey,
                    chart.link
                FROM chart
                WHERE chart.id = :chart_id
SQL;
            $company->execute($insertQuery, [
                'trans_id' => $fakturaID,
                'chart_id' => intval($entry['chart_id']),
                'amount' => floatval($entry['amount']),
                'transdate' => $entry['transdate'],
                'tax_id' => intval($entry['tax_id'] ?? 0),
                'taxkey' => intval($entry['taxkey'] ?? 0)
            ]);
        }
    }

    // Zahlungen und Beträge in einer Transaktion: Die Kassen-Belegnummer ist nur
    // innerhalb der Transaktion gegen gleichzeitige Buchungen gesperrt, und
    // gelöschte Zahlungen ohne neu geschriebene wären eine halbe Buchung.
    $company->beginTransaction();
    try {
        // 2c. Zahlungsbuchungen nur aktualisieren wenn explizit vom Frontend angefordert
        if ($isInvoiceType && $updatePayments) {
            // Geschützte, noch gebuchte Bank-Zahlungen behandeln. Pro Zahlung kommen zwei
            // Beine an (Forderung +Betrag, Bank −Betrag), beide tragen die acc_trans_id des
            // Bank-Beins → dedupliziert über diese ID.
            //   - Standard (bank_edit aus): nur das editierbare Memo pflegen, Betrag/Konto/
            //     Quelle bleiben unangetastet → Bankabstimmung intakt.
            //   - bank_edit (Schloss im Editor geöffnet): den gebuchten Satz direkt
            //     bearbeiten. Beide acc_trans-Beine werden in-place aktualisiert (stabile
            //     acc_trans_id → FK bank_transaction_acc_trans bleibt gültig). Der Bankumsatz
            //     selbst wird NICHT verändert; Betrag/Konto können dadurch bewusst von der
            //     Bankabstimmung abweichen (vom Anwender so gewollt, Warnung im Frontend).
            // Barzahlungen: Belegnummer vergibt die Kasse fortlaufend erst jetzt beim
        // Speichern (siehe _faktura_kassenBelegnummern) — vor dem Löschen unten.
        $paymentEntries = _faktura_kassenBelegnummern($company, $fakturaID, $paymentLink, $paymentEntries);

        $bankMemoUpdates = [];   // acc_trans_id (Bank-Bein) => memo
            $bankFullEdits   = [];   // acc_trans_id (Bank-Bein) => [amount, transdate, source, memo, bank_chart_id]
            foreach ($paymentEntries as $entry) {
                $accTransId = intval($entry['acc_trans_id'] ?? 0);
                if ($accTransId <= 0 || !in_array($accTransId, $protectedBankIds, true)) {
                    continue;
                }
                $amount = floatval($entry['amount']);
                if (!empty($entry['bank_edit'])) {
                    if (!isset($bankFullEdits[$accTransId])) {
                        $bankFullEdits[$accTransId] = [
                            'amount'        => abs($amount),
                            'transdate'     => $entry['transdate'],
                            'source'        => $entry['source'] ?? '',
                            'memo'          => $entry['memo'] ?? '',
                            'bank_chart_id' => null,
                        ];
                    }
                    // Das negative Bein liefert das Geldkonto (Bank) und den Betrag.
                    if ($amount < 0) {
                        $bankFullEdits[$accTransId]['bank_chart_id'] = intval($entry['chart_id']);
                        $bankFullEdits[$accTransId]['amount']        = abs($amount);
                    }
                } else {
                    $bankMemoUpdates[$accTransId] = $entry['memo'] ?? '';
                }
            }

            foreach ($bankMemoUpdates as $accTransId => $memo) {
                $company->execute(<<<SQL
                    UPDATE acc_trans
                    SET memo = :memo
                    WHERE acc_trans_id = :acc_trans_id
    SQL, ['memo' => $memo, 'acc_trans_id' => $accTransId]);
            }

            foreach ($bankFullEdits as $bankLegId => $edit) {
                // Gegenbein (Forderung) über das stabile Mapping finden — selber Bankumsatz,
                // andere acc_trans_id.
                $counterRow = $company->getOne(<<<SQL
                    SELECT acc_trans_id
                    FROM bank_transaction_acc_trans
                    WHERE bank_transaction_id = (
                            SELECT bank_transaction_id FROM bank_transaction_acc_trans
                            WHERE acc_trans_id = :bankLeg LIMIT 1
                        )
                      AND acc_trans_id <> :bankLeg
                    LIMIT 1
    SQL, ['bankLeg' => $bankLegId]);
                $counterId = $counterRow ? intval($counterRow['acc_trans_id']) : null;

                // Fallback für Alt-Buchungen ohne Mapping-Zeile: Gegenbein über das
                // (noch unveränderte) identische Memo derselben Rechnung finden.
                if (!$counterId) {
                    $counterRow = $company->getOne(<<<SQL
                        SELECT acc_trans_id
                        FROM acc_trans
                        WHERE trans_id = :tid
                          AND acc_trans_id <> :bankLeg
                          AND chart_link NOT LIKE '{$paymentLink}'
                          AND memo = (SELECT memo FROM acc_trans WHERE acc_trans_id = :bankLeg2)
                        LIMIT 1
    SQL, ['tid' => $fakturaID, 'bankLeg' => $bankLegId, 'bankLeg2' => $bankLegId]);
                    $counterId = $counterRow ? intval($counterRow['acc_trans_id']) : null;
                }

                $amt = round((float)$edit['amount'], 2);

                // Bank-Bein (negativ). chart_link separat laden (vermeidet doppelte
                // Named-Parameter) und mit aktualisieren, falls das Geldkonto gewechselt wurde.
                if (!empty($edit['bank_chart_id'])) {
                    $bankChartLink = $company->getOne(
                        "SELECT link FROM chart WHERE id = :id",
                        ['id' => $edit['bank_chart_id']]
                    );
                    $company->execute(<<<SQL
                        UPDATE acc_trans SET
                            amount     = :amount,
                            transdate  = :transdate,
                            gldate     = :transdate,
                            source     = :source,
                            memo       = :memo,
                            chart_id   = :chart_id,
                            chart_link = :chart_link
                        WHERE acc_trans_id = :acc_trans_id
    SQL, [
                        'amount'       => -$amt,
                        'transdate'    => $edit['transdate'],
                        'source'       => (string)$edit['source'],
                        'memo'         => $edit['memo'],
                        'chart_id'     => $edit['bank_chart_id'],
                        'chart_link'   => $bankChartLink['link'] ?? 'AR_paid:AP_paid',
                        'acc_trans_id' => $bankLegId,
                    ]);
                }

                // Gegenbein (Forderung, positiv) — Konto/chart_link bleiben unverändert.
                if ($counterId) {
                    $company->execute(<<<SQL
                        UPDATE acc_trans SET
                            amount    = :amount,
                            transdate = :transdate,
                            gldate    = :transdate,
                            source    = :source,
                            memo      = :memo
                        WHERE acc_trans_id = :acc_trans_id
    SQL, [
                        'amount'       => $amt,
                        'transdate'    => $edit['transdate'],
                        'source'       => (string)$edit['source'],
                        'memo'         => $edit['memo'],
                        'acc_trans_id' => $counterId,
                    ]);
                }
            }

            // Alte Zahlungsbuchungen samt Gegenbeinen löschen — aber NICHT die
            // geschützten Bank-Zahlungen (noch gebuchte Bankzuordnung). Verwaiste/
            // stornierte BANK-Einträge sind nicht geschützt und werden hier mit
            // aufgeräumt. Die Gegenbeine müssen mit weg, weil sie unten mit jeder
            // Zahlung neu geschrieben werden — sonst stünden sie doppelt da.
            $deletePaymentsQuery = "DELETE FROM acc_trans WHERE trans_id = :fakturaID AND (chart_link LIKE '{$paymentLink}' OR {$counterLegCond}){$protectExclusion}";
            $company->execute($deletePaymentsQuery, array_merge(['fakturaID' => $fakturaID], $protectParams));

            // Neue Zahlungsbuchungen einfügen
            foreach ($paymentEntries as $entry) {
                // Geschützte Bank-Zahlungen überspringen — sie bleiben in der DB erhalten
                // (oben nicht gelöscht, oben per Memo-UPDATE gepflegt). Ein erneutes
                // Einfügen würde sie verdoppeln. Erkennung über die acc_trans_id (robust
                // gegen ein vom Anwender geändertes Quellfeld). paid (Schritt 3) zählt sie
                // weiterhin korrekt aus $paymentEntries mit.
                $accTransId = intval($entry['acc_trans_id'] ?? 0);
                if ($accTransId > 0 && in_array($accTransId, $protectedBankIds, true)) {
                    continue;
                }
                $insertQuery = <<<SQL
                    INSERT INTO acc_trans (
                        trans_id,
                        chart_id,
                        amount,
                        transdate,
                        gldate,
                        source,
                        memo,
                        tax_id,
                        taxkey,
                        chart_link
                    )
                    SELECT
                        :trans_id,
                        :chart_id,
                        :amount,
                        :transdate,
                        :transdate,
                        :source,
                        :memo,
                        :tax_id,
                        :taxkey,
                        chart.link
                    FROM chart
                    WHERE chart.id = :chart_id
    SQL;
                $company->execute($insertQuery, [
                    'trans_id' => $fakturaID,
                    'chart_id' => intval($entry['chart_id']),
                    'amount' => floatval($entry['amount']),
                    'transdate' => $entry['transdate'],
                    'source' => $entry['source'] ?? '',
                    'memo' => $entry['memo'] ?? '',
                    'tax_id' => intval($entry['tax_id'] ?? 0),
                    'taxkey' => intval($entry['taxkey'] ?? 0)
                ]);
            }
        }

        // 3. Beträge in Rechnungstabelle aktualisieren (ar oder ap)
        if ($isInvoiceType && (!empty($accTransEntries) || $updatePayments)) {
            if ($updatePayments) {
                // paid NICHT aus der gesendeten Liste summieren, sondern direkt aus dem
                // Hauptbuch ableiten — die Zahlungs-Beine (chart_link '…_paid', negativer
                // Betrag) sind die Wahrheit. So bleibt paid auch dann konsistent, wenn
                // geschützte Bank-Zahlungen in acc_trans stehen, aber nicht (mehr) in der
                // übermittelten Liste enthalten sind.
                $updateInvoiceQuery = <<<SQL
                    UPDATE {$mainTable} SET
                        netamount = :netAmount,
                        amount = :grossAmount,
                        paid = COALESCE((
                            SELECT -sum(amount)
                            FROM acc_trans
                            WHERE trans_id = :fakturaID
                                AND chart_link LIKE '{$paymentLink}'
                        ), 0)
                    WHERE id = :fakturaID
    SQL;
                $company->execute($updateInvoiceQuery, [
                    'fakturaID' => $fakturaID,
                    'netAmount' => $netAmount,
                    'grossAmount' => $grossAmount
                ]);
            } else {
                $updateInvoiceQuery = <<<SQL
                    UPDATE {$mainTable} SET
                        netamount = :netAmount,
                        amount = :grossAmount
                    WHERE id = :fakturaID
    SQL;
                $company->execute($updateInvoiceQuery, [
                    'fakturaID' => $fakturaID,
                    'netAmount' => $netAmount,
                    'grossAmount' => $grossAmount
                ]);
            }
        }

        $company->commit();
    } catch (\Throwable $e) {
        $company->rollBack();
        throw $e;
    }

    resultInfo(true, 'UPDATED', [
        'message' => 'Positionen und Buchungen aktualisiert',
        'itemCount' => count($items),
        'accTransCount' => count($accTransEntries),
        'paymentCount' => count($paymentEntries)
    ]);
}

/**
 * Bucht eine Ausgangsrechnung (ar) serverseitig ins Hauptbuch (acc_trans).
 *
 * Spiegelt exakt die Frontend-Logik (useAccounting.calculateAccTransEntries) und die
 * buchungsziel-Ableitung (Buchungsgruppe → taxzone_charts → Erlöskonto/Steuer) aus
 * createFakturaItem. Pro Erlöskonto+Steuer eine Sammelbuchung: Erlös (+netto),
 * Steuer (+steuer), Forderungskonto (−brutto). Vorzeichen wie kivitendo.
 *
 * Sicherheitsnetz:
 *  - Idempotent: existieren bereits Sach-Buchungen (chart_link NICHT '%_paid%'),
 *    wird nichts gebucht (reason ALREADY_POSTED).
 *  - Wird das ermittelte Brutto != ar.amount (> 1 Cent), wird NICHT gebucht
 *    (reason AMOUNT_MISMATCH) — schützt vor falschen Steuer-/Kontenzuordnungen.
 *  - $dryRun = true liefert nur die geplanten Buchungssätze zurück, ohne zu schreiben.
 *
 * @param object $db     DbhCompany-Handle
 * @param int    $arId   ar.id
 * @param bool   $dryRun Nur planen, nicht schreiben
 * @return array ['posted'=>bool, 'reason'=>string, 'gross'=>float, 'ar_amount'=>float, 'entries'=>array, 'count'=>int]
 */
function postArInvoiceToLedger($db, $arId, $dryRun = false) {
    $arId = intval($arId);
    $ar = $db->getOne(
        "SELECT id, taxzone_id, taxincluded, amount, transdate FROM ar WHERE id = :id",
        ['id' => $arId]
    );
    if (!$ar) {
        return ['posted' => false, 'reason' => 'AR_NOT_FOUND'];
    }

    // Bereits gebucht? (Sach-Buchungen außerhalb der Zahlungs-Beine '%_paid%')
    $already = $db->getOne(
        "SELECT 1 AS x FROM acc_trans WHERE trans_id = :id AND COALESCE(chart_link,'') NOT LIKE '%_paid%' LIMIT 1",
        ['id' => $arId]
    );
    if ($already) {
        return ['posted' => false, 'reason' => 'ALREADY_POSTED'];
    }

    $taxIncluded = in_array($ar['taxincluded'], [true, 't', '1', 1], true);
    $transdate   = $ar['transdate'];

    // Positionen inkl. buchungsziel (Erlöskonto, Steuerkonto, tax_id, rate) — exakt
    // wie createFakturaItem: der für die Steuerzone gültige taxzone_charts-Eintrag,
    // die Steuer kommt aus dem taxkeys-Eintrag des Erlöskontos.
    $items = $db->getAll(<<<SQL
        SELECT i.qty, i.sellprice, i.discount,
               bz.income_chart_id, bz.tax_id, bz.tax_chart_id, bz.rate
        FROM invoice i
        JOIN parts p ON p.id = i.parts_id
        LEFT JOIN LATERAL (
            SELECT c2.id AS income_chart_id, tk.tax_id, tx.chart_id AS tax_chart_id, tx.rate
            FROM buchungsgruppen bg
            JOIN taxzone_charts tc ON tc.buchungsgruppen_id = bg.id AND tc.taxzone_id = :tz
            JOIN chart c2 ON c2.id = tc.income_accno_id
            -- nur am Belegdatum gültige Schlüssel (V24)
            LEFT JOIN taxkeys tk ON tk.chart_id = c2.id AND tk.startdate <= :td
            LEFT JOIN tax tx ON tx.id = tk.tax_id
            WHERE bg.id = p.buchungsgruppen_id
            ORDER BY tk.startdate DESC NULLS LAST
            LIMIT 1
        ) bz ON true
        WHERE i.trans_id = :id
        ORDER BY i.position
    SQL, ['id' => $arId, 'tz' => $ar['taxzone_id'], 'td' => $transdate ?: date('Y-m-d')]);

    if (empty($items)) {
        return ['posted' => false, 'reason' => 'NO_ITEMS'];
    }

    // Nach Erlöskonto + tax_id gruppieren und Netto summieren.
    $groups = [];
    foreach ($items as $it) {
        if ($it['income_chart_id'] === null) {
            // Ohne Erlöskonto (Buchungsgruppe/Steuerzone nicht zugeordnet) NICHT raten.
            return ['posted' => false, 'reason' => 'NO_INCOME_ACCOUNT'];
        }
        $rate      = (float)($it['rate'] ?? 0);
        $itemTotal = round((float)$it['qty'] * (float)$it['sellprice'] * (1 - (float)$it['discount']), 2);
        $net       = ($taxIncluded && $rate) ? round($itemTotal / (1 + $rate), 2) : $itemTotal;
        $key       = $it['income_chart_id'] . '_' . ($it['tax_id'] ?? '0');
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'income'    => (int)$it['income_chart_id'],
                'tax_chart' => $it['tax_chart_id'] !== null ? (int)$it['tax_chart_id'] : null,
                'tax_id'    => $it['tax_id'] !== null ? (int)$it['tax_id'] : 0,
                'rate'      => $rate,
                'net'       => 0.0,
            ];
        }
        $groups[$key]['net'] += $net;
    }

    $entries = [];
    $gross   = 0.0;
    foreach ($groups as $g) {
        $net    = round($g['net'], 2);
        $tax    = $g['rate'] ? round($net * $g['rate'], 2) : 0.0;
        $taxkey = $g['rate'] > 0.1 ? 3 : ($g['rate'] > 0 ? 2 : 0);

        $entries[] = ['chart_id' => $g['income'], 'amount' => $net, 'tax_id' => $g['tax_id'], 'taxkey' => $taxkey];
        if ($tax != 0 && $g['tax_chart']) {
            $entries[] = ['chart_id' => $g['tax_chart'], 'amount' => $tax, 'tax_id' => 0, 'taxkey' => 0];
        }
        $gross += $net + $tax;
    }
    $gross = round($gross, 2);

    // Forderungskonto (Standard: chart.link='AR', niedrigste Kontonummer).
    $arChart = $db->getOne("SELECT id FROM chart WHERE link = 'AR' ORDER BY accno ASC LIMIT 1");
    if (!$arChart) {
        return ['posted' => false, 'reason' => 'NO_AR_ACCOUNT'];
    }
    $entries[] = ['chart_id' => (int)$arChart['id'], 'amount' => round(-$gross, 2), 'tax_id' => 0, 'taxkey' => 0];

    // Sicherheitsnetz: ermitteltes Brutto muss zum gespeicherten Rechnungsbetrag passen.
    if (abs($gross - (float)$ar['amount']) > 0.01) {
        return ['posted' => false, 'reason' => 'AMOUNT_MISMATCH',
                'gross' => $gross, 'ar_amount' => (float)$ar['amount'], 'entries' => $entries];
    }

    if ($dryRun) {
        return ['posted' => false, 'reason' => 'DRY_RUN',
                'gross' => $gross, 'ar_amount' => (float)$ar['amount'], 'entries' => $entries];
    }

    $db->beginTransaction();
    try {
        foreach ($entries as $e) {
            $linkRow = $db->getOne("SELECT link FROM chart WHERE id = :id", ['id' => $e['chart_id']]);
            $db->execute(<<<SQL
                INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
                VALUES (:t, :c, :a, :td, :td, '', '', :taxid, :tk, :link)
            SQL, [
                't'     => $arId,
                'c'     => $e['chart_id'],
                'a'     => $e['amount'],
                'td'    => $transdate,
                'taxid' => $e['tax_id'],
                'tk'    => $e['taxkey'],
                'link'  => $linkRow['link'] ?? '',
            ]);
        }
        $db->commit();
    } catch (\Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }

    return ['posted' => true, 'gross' => $gross, 'ar_amount' => (float)$ar['amount'], 'count' => count($entries)];
}

/**
 * Bucht eine Eingangsrechnung (ap) serverseitig ins Hauptbuch (acc_trans).
 *
 * Spiegelbild zu postArInvoiceToLedger: pro Aufwandskonto+Steuer eine Sammelbuchung
 * Aufwand (−netto) + Vorsteuer (−steuer) gegen Kreditor (+brutto). Aufwandskonto und
 * Vorsteuer kommen aus der Buchungsgruppe (taxzone_charts.expense_accno → dessen
 * taxkeys → tax_id/rate/Steuerkonto). Vorzeichen wie kivitendo (siehe AP-Referenz).
 *
 * Sicherheitsnetz wie bei AR: idempotent (ALREADY_POSTED) und Brutto==ap.amount-Schutz
 * (AMOUNT_MISMATCH). $dryRun liefert nur die geplanten Buchungssätze.
 *
 * @param object $db     DbhCompany-Handle
 * @param int    $apId   ap.id
 * @param bool   $dryRun Nur planen, nicht schreiben
 * @return array ['posted'=>bool,'reason'=>string,'gross'=>float,'ap_amount'=>float,'entries'=>array,'count'=>int]
 */
function postApInvoiceToLedger($db, $apId, $dryRun = false) {
    $apId = intval($apId);
    $ap = $db->getOne(
        "SELECT id, taxzone_id, taxincluded, amount, transdate FROM ap WHERE id = :id",
        ['id' => $apId]
    );
    if (!$ap) {
        return ['posted' => false, 'reason' => 'AP_NOT_FOUND'];
    }

    $already = $db->getOne(
        "SELECT 1 AS x FROM acc_trans WHERE trans_id = :id AND COALESCE(chart_link,'') NOT LIKE '%_paid%' LIMIT 1",
        ['id' => $apId]
    );
    if ($already) {
        return ['posted' => false, 'reason' => 'ALREADY_POSTED'];
    }

    $taxIncluded = in_array($ap['taxincluded'], [true, 't', '1', 1], true);
    $transdate   = $ap['transdate'];

    // Positionen inkl. buchungsziel: Aufwandskonto (expense_accno) + Vorsteuer aus
    // dem taxkeys-Eintrag des Aufwandskontos.
    $items = $db->getAll(<<<SQL
        SELECT i.qty, i.sellprice, i.discount,
               bz.expense_chart_id, bz.tax_id, bz.tax_chart_id, bz.rate
        FROM invoice i
        JOIN parts p ON p.id = i.parts_id
        LEFT JOIN LATERAL (
            SELECT c2.id AS expense_chart_id, tk.tax_id, tx.chart_id AS tax_chart_id, tx.rate
            FROM buchungsgruppen bg
            JOIN taxzone_charts tc ON tc.buchungsgruppen_id = bg.id AND tc.taxzone_id = :tz
            JOIN chart c2 ON c2.id = tc.expense_accno_id
            -- nur am Belegdatum gültige Schlüssel (V24)
            LEFT JOIN taxkeys tk ON tk.chart_id = c2.id AND tk.startdate <= :td
            LEFT JOIN tax tx ON tx.id = tk.tax_id
            WHERE bg.id = p.buchungsgruppen_id
            ORDER BY tk.startdate DESC NULLS LAST
            LIMIT 1
        ) bz ON true
        WHERE i.trans_id = :id
        ORDER BY i.position
    SQL, ['id' => $apId, 'tz' => $ap['taxzone_id'], 'td' => $transdate ?: date('Y-m-d')]);

    if (empty($items)) {
        return ['posted' => false, 'reason' => 'NO_ITEMS'];
    }

    $groups = [];
    foreach ($items as $it) {
        if ($it['expense_chart_id'] === null) {
            return ['posted' => false, 'reason' => 'NO_EXPENSE_ACCOUNT'];
        }
        $rate      = (float)($it['rate'] ?? 0);
        $itemTotal = round((float)$it['qty'] * (float)$it['sellprice'] * (1 - (float)$it['discount']), 2);
        $net       = ($taxIncluded && $rate) ? round($itemTotal / (1 + $rate), 2) : $itemTotal;
        $key       = $it['expense_chart_id'] . '_' . ($it['tax_id'] ?? '0');
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'expense'   => (int)$it['expense_chart_id'],
                'tax_chart' => $it['tax_chart_id'] !== null ? (int)$it['tax_chart_id'] : null,
                'tax_id'    => $it['tax_id'] !== null ? (int)$it['tax_id'] : 0,
                'rate'      => $rate,
                'net'       => 0.0,
            ];
        }
        $groups[$key]['net'] += $net;
    }

    $entries = [];
    $gross   = 0.0;
    foreach ($groups as $g) {
        $net    = round($g['net'], 2);
        $tax    = $g['rate'] ? round($net * $g['rate'], 2) : 0.0;
        // Vorsteuer-Schlüssel: 19% = 9, 7% = 8, sonst 0 (Einkaufsseite)
        $taxkey = $g['rate'] > 0.1 ? 9 : ($g['rate'] > 0 ? 8 : 0);

        $entries[] = ['chart_id' => $g['expense'], 'amount' => -$net, 'tax_id' => $g['tax_id'], 'taxkey' => $taxkey];
        if ($tax != 0 && $g['tax_chart']) {
            $entries[] = ['chart_id' => $g['tax_chart'], 'amount' => -$tax, 'tax_id' => 0, 'taxkey' => 0];
        }
        $gross += $net + $tax;
    }
    $gross = round($gross, 2);

    // Kreditorenkonto (Standard: chart.link='AP', niedrigste Kontonummer).
    $apChart = $db->getOne("SELECT id FROM chart WHERE link = 'AP' ORDER BY accno ASC LIMIT 1");
    if (!$apChart) {
        return ['posted' => false, 'reason' => 'NO_AP_ACCOUNT'];
    }
    $entries[] = ['chart_id' => (int)$apChart['id'], 'amount' => round($gross, 2), 'tax_id' => 0, 'taxkey' => 0];

    if (abs($gross - (float)$ap['amount']) > 0.01) {
        return ['posted' => false, 'reason' => 'AMOUNT_MISMATCH',
                'gross' => $gross, 'ap_amount' => (float)$ap['amount'], 'entries' => $entries];
    }

    if ($dryRun) {
        return ['posted' => false, 'reason' => 'DRY_RUN',
                'gross' => $gross, 'ap_amount' => (float)$ap['amount'], 'entries' => $entries];
    }

    $db->beginTransaction();
    try {
        foreach ($entries as $e) {
            $linkRow = $db->getOne("SELECT link FROM chart WHERE id = :id", ['id' => $e['chart_id']]);
            $db->execute(<<<SQL
                INSERT INTO acc_trans (trans_id, chart_id, amount, transdate, gldate, source, memo, tax_id, taxkey, chart_link)
                VALUES (:t, :c, :a, :td, :td, '', '', :taxid, :tk, :link)
            SQL, [
                't'     => $apId,
                'c'     => $e['chart_id'],
                'a'     => $e['amount'],
                'td'    => $transdate,
                'taxid' => $e['tax_id'],
                'tk'    => $e['taxkey'],
                'link'  => $linkRow['link'] ?? '',
            ]);
        }
        $db->commit();
    } catch (\Throwable $ex) {
        $db->rollBack();
        throw $ex;
    }

    return ['posted' => true, 'gross' => $gross, 'ap_amount' => (float)$ap['amount'], 'count' => count($entries)];
}

/**
 * Gibt das Mapping von Dokumenttyp auf Nummernfeld und defaults-Spalte zurück
 *
 * @param string $fakturaType Dokumenttyp
 * @return array ['doc_number_column' => ..., 'defaults_column' => ...]
 */
function getNumberConfig($fakturaType) {
    $map = [
        'invoice'            => ['doc_number_column' => 'invnumber', 'defaults_column' => 'invnumber'],
        'purchase_invoice'   => ['doc_number_column' => 'invnumber', 'defaults_column' => 'invnumber'],
        'order'              => ['doc_number_column' => 'ordnumber', 'defaults_column' => 'sonumber'],
        'purchase_order'     => ['doc_number_column' => 'ordnumber', 'defaults_column' => 'ponumber'],
        'quotation'          => ['doc_number_column' => 'quonumber', 'defaults_column' => 'sqnumber'],
        'request_quotation'  => ['doc_number_column' => 'quonumber', 'defaults_column' => 'rfqnumber'],
        'delivery_order'     => ['doc_number_column' => 'donumber', 'defaults_column' => 'sdonumber'],
        'purchase_delivery_order' => ['doc_number_column' => 'donumber', 'defaults_column' => 'pdonumber'],
        'credit_note'        => ['doc_number_column' => 'invnumber', 'defaults_column' => 'invnumber'],
        'invoice_storno'     => ['doc_number_column' => 'invnumber', 'defaults_column' => 'invnumber'],
    ];
    return $map[$fakturaType] ?? null;
}

/**
 * Löscht eine komplette Faktura (Rechnung, Auftrag, Angebot, Lieferschein).
 * Bei Rechnungen ist Löschen nur erlaubt wenn es die letzte Nummer des Nummernkreises ist.
 * Aufträge und Angebote sind immer löschbar. Bei Löschung der letzten Nummer wird
 * der Nummernkreis um 1 zurückgesetzt.
 *
 * @param array $data Request-Daten mit fakturaID und fakturaType
 * @testdata {"fakturaID": 1, "fakturaType": "invoice"}
 */
function deleteFaktura($data) {
    $fakturaID = intval($data['fakturaID']);
    $fakturaType = $data['fakturaType'] ?? 'invoice';

    if ($fakturaID <= 0) {
        echo resultInfo(false, 'INVALID_FAKTURA_ID', 'Ungültige Faktura-ID');
        return;
    }

    $company = DbhCompany::begin();

    $fakturaType = resolveFakturaTypeForWrite($company, $fakturaID, $fakturaType);
    permit(getPermissionForFakturaType($fakturaType));

    $tableConfig = getFakturaTableConfig($fakturaType);
    $mainTable = $tableConfig['main_table'];
    $itemsTable = $tableConfig['items_table'];
    $itemsFk = $tableConfig['items_fk'];
    $numberConfig = getNumberConfig($fakturaType);

    // 1. Dokument laden
    $docNumberCol = $numberConfig ? $numberConfig['doc_number_column'] : null;
    $selectCols = $docNumberCol ? "id, {$docNumberCol}" : 'id';
    $doc = $company->getOne(
        "SELECT {$selectCols} FROM {$mainTable} WHERE id = :fakturaID",
        ['fakturaID' => $fakturaID]
    );

    if (!$doc) {
        echo resultInfo(false, 'NOT_FOUND', 'Dokument nicht gefunden');
        return;
    }

    // Ausgelagerter Lieferschein: Lagerbewegungen zeigen auf ihn (inventory.oe_id ohne
    // CASCADE) — wie kivitendo nicht mehr löschbar
    if ($mainTable === 'delivery_orders') {
        $delivered = $company->getOne("SELECT delivered FROM delivery_orders WHERE id = :id", [':id' => $fakturaID]);
        if (!empty($delivered['delivered'])) {
            resultInfo(false, 'DELIVERED', 'Der Lieferschein ist ausgelagert und kann nicht gelöscht werden');
            return;
        }
    }

    // 2. Transaktion starten und Nummernkreis mit FOR UPDATE sperren,
    //    damit keine parallele Nummernvergabe dazwischenfunkt.
    $company->beginTransaction();

    $isLastNumber = false;
    if ($numberConfig) {
        $defaultsCol = $numberConfig['defaults_column'];
        $docNumber = $doc[$docNumberCol] ?? '';

        // FOR UPDATE sperrt die defaults-Zeile — konkurrierende CTEs
        // (UPDATE defaults SET ... +1) blockieren bis zum COMMIT.
        $defaults = $company->getOne(
            "SELECT {$defaultsCol} FROM defaults LIMIT 1 FOR UPDATE",
            []
        );
        $currentNumber = $defaults[$defaultsCol] ?? '';

        $isLastNumber = (string)$docNumber === (string)$currentNumber;

        // Nur bei Rechnungen die Nummernkreis-Prüfung erzwingen
        if (in_array($fakturaType, ['invoice', 'purchase_invoice', 'credit_note', 'invoice_storno']) && !$isLastNumber) {
            $company->rollBack();
            echo resultInfo(false, 'NOT_LAST_NUMBER', [
                'currentNumber' => $currentNumber,
                'docNumber' => $docNumber
            ]);
            return;
        }
    }

    // 3. Nummernkreis, Positionen, Buchungen und Dokument atomar löschen
    if ($numberConfig && $isLastNumber) {
        $defaultsCol = $numberConfig['defaults_column'];
        $company->execute(
            "UPDATE defaults SET {$defaultsCol} = GREATEST(({$defaultsCol}::INT) - 1, 0)",
            []
        );
    }

    if (in_array($fakturaType, ['invoice', 'purchase_invoice', 'credit_note', 'invoice_storno'])) {
        $company->execute(
            "DELETE FROM acc_trans WHERE trans_id = :fakturaID",
            ['fakturaID' => $fakturaID]
        );
    }

    if ($mainTable === 'oe') {
        $company->execute(
            "DELETE FROM calendar_events WHERE order_id = :fakturaID",
            ['fakturaID' => $fakturaID]
        );
    }

    // Erst der Kopf, dann die Positionen: oe/delivery_orders nehmen ihre Positionen per
    // Kaskade mit, und die Belegchronik vermerkt nur das Löschen des Belegs statt jeder Position
    $company->execute(
        "DELETE FROM {$mainTable} WHERE id = :fakturaID",
        ['fakturaID' => $fakturaID]
    );
    $company->execute(
        "DELETE FROM {$itemsTable} WHERE {$itemsFk} = :fakturaID",
        ['fakturaID' => $fakturaID]
    );

    $company->commit();

    echo resultInfo(true, 'DELETED', ['message' => 'Dokument erfolgreich gelöscht']);
}

/**
 * Aktualisiert ein einzelnes Feld einer Faktura (Belegdatum, Lieferdatum, Fälligkeitsdatum)
 *
 * @param array $data Request-Daten mit fakturaID, fakturaType, field und value
 * @return void Gibt JSON zurück
 * @testdata {"fakturaID": 1, "fakturaType": "invoice", "field": "transdate", "value": "2025-01-30"}
 */
function updateFakturaField($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);
    $fakturaType = $data['fakturaType'] ?? 'invoice';
    $field = $data['field'] ?? '';
    $value = $data['value'] ?? null;

    if ($fakturaID <= 0) {
        resultInfo(false, 'INVALID_FAKTURA_ID', ['message' => 'Ungültige Faktura-ID']);
        return;
    }

    // Erlaubte Felder für diesen Endpunkt (Sicherheit!)
    $allowedFields = [
        // Belegnummern
        'invnumber',        // Rechnungsnummer
        'ordnumber',        // Auftragsnummer
        'quonumber',        // Angebotsnummer
        'donumber',         // Lieferscheinnummer
        'cusordnumber',     // Kundenauftragsnummer
        // Datumsfelder
        'transdate',        // Belegdatum
        'reqdate',          // Lieferdatum (oe, delivery_orders)
        'deliverydate',     // Lieferdatum (ar, ap)
        'duedate',          // Fälligkeitsdatum (nur bei invoice)
        // Kundenfelder
        'customer_id',      // Kunde
        'vendor_id',        // Lieferant
        'shipto_id',        // Lieferadresse
        'billing_address_id', // Rechnungsadresse
        // Notizen
        'notes',            // Öffentliche Notizen
        'intnotes',         // Interne Notizen
        // Zusätzliche Informationen
        'currency_id',      // Währung
        'taxzone_id',       // Steuerzone
        'language_id',      // Sprache
        'department_id',    // Abteilung
        'employee_id',      // Mitarbeiter
        'payment_id',       // Zahlungsbedingungen
        'delivery_term_id', // Lieferbedingungen
        'taxincluded',      // Steuer im Preis inbegriffen
        'closed'            // Status offen/erledigt
    ];

    if (!in_array($field, $allowedFields)) {
        resultInfo(false, 'INVALID_FIELD', ['message' => 'Feld nicht erlaubt: ' . $field]);
        return;
    }

    $company = DbhCompany::begin();

    $fakturaType = resolveFakturaTypeForWrite($company, $fakturaID, $fakturaType);
    permit(getPermissionForFakturaType($fakturaType));

    $tableConfig = getFakturaTableConfig($fakturaType);
    $mainTable = $tableConfig['main_table'];

    // ar/ap führen das Lieferdatum als 'deliverydate' (auch Gutschrift/Storno), oe und
    // delivery_orders als 'reqdate'
    $dbField = (in_array($mainTable, ['ar', 'ap'], true) && $field === 'reqdate') ? 'deliverydate' : $field;

    // Spalten, die es in der Haupttabelle nicht gibt (Fälligkeit nur an Rechnungen,
    // keine Angebotsnummer am Lieferschein, keine Lieferadresse an Eingangsrechnungen …)
    $notInTable = [
        'oe'              => ['invnumber', 'donumber', 'deliverydate', 'duedate'],
        'delivery_orders' => ['invnumber', 'quonumber', 'deliverydate', 'duedate'],
        'ap'              => ['donumber', 'cusordnumber', 'customer_id', 'shipto_id', 'billing_address_id', 'closed'],
        'ar'              => ['vendor_id', 'closed'],
    ][$mainTable] ?? [];
    if (in_array($dbField, $notInTable, true)) {
        resultInfo(false, 'INVALID_FIELD', ['message' => "Feld {$field} gibt es bei diesem Beleg nicht"]);
        return;
    }

    // Wert vorbereiten
    if ($field === 'closed') {
        $cleanValue = $value ? 't' : 'f';
    } else {
        $cleanValue = empty($value) ? null : $value;
    }

    // Update durchführen (mit gemapptem Feldnamen)
    $company->update(
        $mainTable,
        [$dbField],
        [$cleanValue],
        'id = ' . $fakturaID
    );

    resultInfo(true, 'UPDATED', [
        'message' => 'Feld aktualisiert',
        'field' => $field,
        'dbField' => $dbField,
        'value' => $cleanValue
    ]);
}

/**
 * Bestätigt einen Verkaufsauftrag oder nimmt die Bestätigung zurück.
 *
 * Schaltet record_type zwischen 'sales_order' (bestätigt) und
 * 'sales_order_intake' (Auftragseingang) und hält fest, wie der Kunde zugesagt
 * hat — mit dem Betrag zum Zeitpunkt der Bestätigung (SQL-Funktion oe_confirm,
 * Upstall crm). Mit suggestion_id wird ein Vorschlag der KI übernommen.
 * Nur für Verkaufsaufträge.
 *
 * @param int    $data['fakturaID']     Auftrags-ID (oe.id)
 * @param bool   $data['confirmed']     true = bestätigen, false = zurücknehmen
 * @param string $data['channel']       phone | email | whatsapp | visit | signature | portal | other
 * @param int    $data['agreed_at']     Zeitpunkt der Zusage (ms seit 1970), leer = jetzt
 * @param string $data['note']          Notiz, z. B. was der Kunde gesagt hat
 * @param int    $data['suggestion_id'] Vorschlag der KI, der übernommen wird
 * @param int    $data['employee_id']   bestätigender Mitarbeiter (aus dem Store)
 * @testdata {"fakturaID": 1, "confirmed": true, "channel": "phone", "note": "Kunde hat telefonisch zugesagt", "employee_id": 1}
 */
function setOrderConfirmed($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);
    if (!$fakturaID) {
        resultInfo(false, 'VALIDATION_ERROR', 'fakturaID required');
        return;
    }

    $company = DbhCompany::begin();
    permit(getPermissionForFakturaType('order'));

    $agreedAt = intval($data['agreed_at'] ?? 0);
    $row = $company->getOne(
        "SELECT oe_confirm(:id, :confirmed, :employee_id, :channel,
                           CASE WHEN :agreed_at::bigint > 0 THEN to_timestamp(:agreed_at2::bigint / 1000.0) END,
                           :note, :suggestion_id) AS status",
        [
            ':id'            => $fakturaID,
            ':confirmed'     => !empty($data['confirmed']) ? 'true' : 'false',
            ':employee_id'   => intval($data['employee_id'] ?? 0) ?: null,
            ':channel'       => (string)($data['channel'] ?? '') ?: null,
            ':agreed_at'     => $agreedAt,
            ':agreed_at2'    => $agreedAt,
            ':note'          => (string)($data['note'] ?? '') ?: null,
            ':suggestion_id' => intval($data['suggestion_id'] ?? 0) ?: null,
        ]
    );
    $status = json_decode($row['status'] ?? 'null', true);
    if (!$status || isset($status['error'])) {
        resultInfo(false, $status['error'] ?? 'NOT_FOUND', 'Auftrag konnte nicht bestätigt werden');
        return;
    }

    resultInfo(true, 'UPDATED', [
        'record_type'         => $status['confirmed'] ? 'sales_order' : 'sales_order_intake',
        'confirmation_status' => $status,
    ]);
}

/**
 * Verwirft einen Vorschlag der KI (keine Zusage) bzw. nimmt eine erkannte
 * Absage zur Kenntnis (SQL-Funktion oe_confirmation_dismiss).
 *
 * @param int $data['id']          Vorschlag (oe_confirmation_oserp.id)
 * @param int $data['employee_id'] Mitarbeiter (aus dem Store)
 * @testdata {"id": 1, "employee_id": 1}
 */
function dismissOrderConfirmation($data) {
    $id = intval($data['id'] ?? 0);
    if (!$id) {
        resultInfo(false, 'VALIDATION_ERROR', 'id required');
        return;
    }

    $company = DbhCompany::begin();
    permit(getPermissionForFakturaType('order'));

    $row = $company->getOne(
        "SELECT oe_confirmation_dismiss(:id, :employee_id) AS status",
        [':id' => $id, ':employee_id' => intval($data['employee_id'] ?? 0) ?: null]
    );
    $status = json_decode($row['status'] ?? 'null', true);
    if (!$status || isset($status['error'])) {
        resultInfo(false, $status['error'] ?? 'NOT_FOUND', 'Vorschlag nicht gefunden');
        return;
    }

    resultInfo(true, 'UPDATED', ['confirmation_status' => $status]);
}

/**
 * Konvertiert ein Dokument in einen anderen Dokumenttyp
 * (z.B. Angebot → Auftrag, Auftrag → Rechnung, etc.)
 *
 * Ablauf:
 * 1. Quelldokument-Header laden
 * 2. Neues Zieldokument anlegen (neue Nummer, transdate = heute)
 * 3. Header-Felder vom Quell- auf das Zieldokument kopieren
 * 4. Positionen kopieren (INSERT...SELECT)
 * 5. record_links-Eintrag erstellen
 * 6. LxCars: Fahrzeug-Zuordnung kopieren (falls vorhanden)
 *
 * @param array $data Request-Daten mit sourceId, sourceType, targetType
 * @testdata {"sourceId": 1, "sourceType": "order", "targetType": "invoice"}
 */
function convertFaktura($data) {
    $sourceId   = intval($data['sourceId'] ?? 0);
    $sourceType = $data['sourceType'] ?? '';
    $targetType = $data['targetType'] ?? '';

    if ($sourceId <= 0 || !$sourceType || !$targetType) {
        resultInfo(false, 'INVALID_PARAMS', 'sourceId, sourceType und targetType sind erforderlich');
        return;
    }

    $company = DbhCompany::begin();

    $sourceType = resolveFakturaTypeForWrite($company, $sourceId, $sourceType);
    permit(getPermissionForFakturaType($sourceType));
    permit(getPermissionForFakturaType($targetType));

    $sourceConfig = getFakturaTableConfig($sourceType);
    $targetConfig = getFakturaTableConfig($targetType);
    $sourceTable  = $sourceConfig['main_table'];
    $targetTable  = $targetConfig['main_table'];

    // ── 1. Quelldokument-Header laden ──

    $source = $company->getOne(
        "SELECT * FROM {$sourceTable} WHERE id = :id",
        [':id' => $sourceId]
    );

    if (!$source) {
        resultInfo(false, 'SOURCE_NOT_FOUND', 'Quelldokument nicht gefunden');
        return;
    }

    // Einkaufsbeleg als Quelle (Lieferant, kein Kunde): Ziel auf den Einkauf umlenken
    // (Einkaufslieferschein → Eingangsrechnung statt Ausgangsrechnung ohne Kunden)
    $sourceIsVendor = empty($source['customer_id']) && !empty($source['vendor_id']);
    $effectiveTarget = $sourceIsVendor ? resolveEffectiveFakturaType($targetType, true) : $targetType;
    if ($effectiveTarget !== $targetType) {
        permit(getPermissionForFakturaType($effectiveTarget));
        $targetConfig = getFakturaTableConfig($effectiveTarget);
        $targetTable  = $targetConfig['main_table'];
    }

    // ── 1b. Duplikat-Check: Nur eine Rechnung pro Auftrag/Angebot/Lieferschein ──

    $isReuse = ($sourceType === $targetType);

    if (!$isReuse && $targetType === 'invoice' && in_array($sourceTable, ['oe', 'delivery_orders'], true)) {
        $existingInvoice = $company->getOne(
            "SELECT rl.to_id, ar.invnumber
             FROM record_links rl
             JOIN ar ON ar.id = rl.to_id
             WHERE rl.from_table = :sourceTable AND rl.from_id = :sourceId AND rl.to_table = 'ar'
             LIMIT 1",
            [':sourceTable' => $sourceTable, ':sourceId' => $sourceId]
        );

        if ($existingInvoice) {
            resultInfo(false,
                'Für dieses Dokument existiert bereits eine Rechnung: ' . ($existingInvoice['invnumber'] ?? ''));
            return;
        }
    }

    // Transaktion starten — invnumber-Inkrement und alle Folgeoperationen atomar
    $company->beginTransaction();

    try {

    // Kunden-/Lieferanten-ID bestimmen
    $customerId = $source['customer_id'] ?? null;
    $vendorId   = $source['vendor_id'] ?? null;

    // Falls customer_id NULL ist und das Quelldokument ein Fahrzeug hat → Halter übernehmen
    if (!$customerId && !$vendorId && $sourceTable === 'oe') {
        $owner = $company->getOne(
            "SELECT cl.c_ow FROM oe_ext e JOIN cars_lxcars cl ON cl.c_id = e.c_id WHERE e.oe_id = :id AND cl.c_ow IS NOT NULL",
            [':id' => $sourceId]
        );
        if ($owner) {
            $customerId = intval($owner['c_ow']);
        }
    }

    // Für Einkaufs-Workflows (Lieferantenanfrage/-auftrag) aus Verkaufsdokumenten:
    // customer_id nicht übernehmen, vendor_id bleibt leer (manuell zuordnen)
    $isTargetPurchase = in_array($targetType, ['purchase_order', 'request_quotation']);
    if ($isTargetPurchase && !$sourceIsVendor) {
        $customerId = null;
        $vendorId   = null;
    }

    // ── 2. Neues Dokument anlegen (CTE mit Nummern-Inkrement) ──

    $auth = DbhAuth::begin();
    $login = $auth->getLogin();
    $employee = $company->getOne("SELECT id FROM employee WHERE login = :login", ['login' => $login]);
    $employeeId = $employee ? intval($employee['id']) : null;

    $currencyId = intval($source['currency_id'] ?? 1);
    $taxzoneId  = intval($source['taxzone_id'] ?? 4);

    $newId = null;

    if ($effectiveTarget === 'invoice') {
        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1 RETURNING invnumber)
            INSERT INTO ar (invnumber, transdate, gldate, employee_id, customer_id, taxzone_id, currency_id, invoice)
            SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :customer_id, :taxzone_id, :currency_id, true
            RETURNING id
SQL;
        // Lösungsvorschlag: GREATEST() heilt defaults.invnumber falls out-of-sync mit MAX(ar.invnumber) → verhindert UNIQUE VIOLATION
        // $query = <<<SQL
        //     WITH tmp AS (
        //         UPDATE defaults SET invnumber = GREATEST(COALESCE(invnumber::INT, 0) + 1, COALESCE((SELECT MAX(invnumber::INT) FROM ar), 0) + 1)
        //         RETURNING invnumber
        //     )
        //     INSERT INTO ar (invnumber, transdate, gldate, employee_id, customer_id, taxzone_id, currency_id, invoice)
        //     SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :customer_id, :taxzone_id, :currency_id, true
        //     RETURNING id
        // SQL;
        $result = $company->getOne($query, [
            ':employee_id'  => $employeeId,
            ':customer_id'  => $customerId,
            ':taxzone_id'   => $taxzoneId,
            ':currency_id'  => $currencyId
        ]);
        $newId = intval($result['id']);

    } elseif ($effectiveTarget === 'purchase_invoice') {
        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1 RETURNING invnumber)
            INSERT INTO ap (invnumber, transdate, gldate, employee_id, vendor_id, taxzone_id, currency_id, invoice)
            SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :vendor_id, :taxzone_id, :currency_id, true
            RETURNING id
SQL;
        // Lösungsvorschlag: GREATEST() heilt defaults.invnumber falls out-of-sync mit MAX(ap.invnumber) → verhindert UNIQUE VIOLATION
        // $query = <<<SQL
        //     WITH tmp AS (
        //         UPDATE defaults SET invnumber = GREATEST(COALESCE(invnumber::INT, 0) + 1, COALESCE((SELECT MAX(invnumber::INT) FROM ap), 0) + 1)
        //         RETURNING invnumber
        //     )
        //     INSERT INTO ap (invnumber, transdate, gldate, employee_id, vendor_id, taxzone_id, currency_id, invoice)
        //     SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :vendor_id, :taxzone_id, :currency_id, true
        //     RETURNING id
        // SQL;
        $result = $company->getOne($query, [
            ':employee_id' => $employeeId,
            ':vendor_id'   => $vendorId,
            ':taxzone_id'  => $taxzoneId,
            ':currency_id' => $currencyId
        ]);
        $newId = intval($result['id']);

    } elseif (in_array($effectiveTarget, ['quotation', 'request_quotation'])) {
        $defaultsCol = ($effectiveTarget === 'quotation') ? 'sqnumber' : 'rfqnumber';
        $recordType  = ($effectiveTarget === 'quotation') ? 'sales_quotation' : 'request_quotation';

        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET {$defaultsCol} = COALESCE({$defaultsCol}::INT, 0) + 1 RETURNING {$defaultsCol})
            INSERT INTO oe (ordnumber, quonumber, transdate, employee_id, customer_id, vendor_id, taxzone_id, currency_id, record_type)
            SELECT '', (SELECT {$defaultsCol} FROM tmp), CURRENT_DATE, :employee_id, :customer_id, :vendor_id, :taxzone_id, :currency_id, :record_type
            RETURNING id
SQL;
        $result = $company->getOne($query, [
            ':employee_id' => $employeeId,
            ':customer_id' => $customerId,
            ':vendor_id'   => $vendorId,
            ':taxzone_id'  => $taxzoneId,
            ':currency_id' => $currencyId,
            ':record_type' => $recordType
        ]);
        $newId = intval($result['id']);

    } elseif (in_array($effectiveTarget, ['order', 'purchase_order'])) {
        $defaultsCol = ($effectiveTarget === 'purchase_order') ? 'ponumber' : 'sonumber';
        // Auch ein umgewandelter oder kopierter Verkaufsauftrag startet als Auftragseingang
        // (wie createFakturaCore) — bestätigt wird mit Weg und Zeitpunkt (setOrderConfirmed)
        $recordType  = ($effectiveTarget === 'purchase_order') ? 'purchase_order' : 'sales_order_intake';

        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET {$defaultsCol} = COALESCE({$defaultsCol}::INT, 0) + 1 RETURNING {$defaultsCol})
            INSERT INTO oe (ordnumber, quonumber, transdate, employee_id, customer_id, vendor_id, taxzone_id, currency_id, record_type)
            SELECT (SELECT {$defaultsCol} FROM tmp), '', CURRENT_DATE, :employee_id, :customer_id, :vendor_id, :taxzone_id, :currency_id, :record_type
            RETURNING id
SQL;
        $result = $company->getOne($query, [
            ':employee_id' => $employeeId,
            ':customer_id' => $customerId,
            ':vendor_id'   => $vendorId,
            ':taxzone_id'  => $taxzoneId,
            ':currency_id' => $currencyId,
            ':record_type' => $recordType
        ]);
        $newId = intval($result['id']);

    } elseif (in_array($effectiveTarget, ['delivery_order', 'purchase_delivery_order'], true)) {
        // Nummernkreis wie kivitendo: sdonumber (Verkauf) / pdonumber (Einkauf); defaults.donumber gibt es nicht
        $defaultsCol = ($effectiveTarget === 'delivery_order') ? 'sdonumber' : 'pdonumber';
        $recordType  = ($effectiveTarget === 'delivery_order') ? 'sales_delivery_order' : 'purchase_delivery_order';
        $typeCol     = deliveryOrderTypeColumn($company);
        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET {$defaultsCol} = COALESCE({$defaultsCol}::INT, 0) + 1 RETURNING {$defaultsCol})
            INSERT INTO delivery_orders (donumber, transdate, employee_id, customer_id, vendor_id, taxzone_id, currency_id, {$typeCol})
            SELECT (SELECT {$defaultsCol} FROM tmp), CURRENT_DATE, :employee_id, :customer_id, :vendor_id, :taxzone_id, :currency_id, :record_type
            RETURNING id
SQL;
        $result = $company->getOne($query, [
            ':employee_id' => $employeeId,
            ':customer_id' => $customerId,
            ':vendor_id'   => $vendorId,
            ':taxzone_id'  => $taxzoneId,
            ':currency_id' => $currencyId,
            ':record_type' => $recordType
        ]);
        $newId = intval($result['id']);

    } elseif ($effectiveTarget === 'credit_note') {
        // Gutschrift: nur aus Rechnung (ar) erlaubt
        if ($sourceTable !== 'ar') {
            resultInfo(false, 'INVALID_SOURCE', 'Gutschrift kann nur aus einer Rechnung erstellt werden');
            return;
        }

        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1 RETURNING invnumber)
            INSERT INTO ar (invnumber, transdate, gldate, employee_id, customer_id, taxzone_id, currency_id, invoice, type, invnumber_for_credit_note)
            SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :customer_id, :taxzone_id, :currency_id, true, 'credit_note', :source_invnumber
            RETURNING id
SQL;
        $result = $company->getOne($query, [
            ':employee_id'      => $employeeId,
            ':customer_id'      => $customerId,
            ':taxzone_id'       => $taxzoneId,
            ':currency_id'      => $currencyId,
            ':source_invnumber' => $source['invnumber'] ?? ''
        ]);
        $newId = intval($result['id']);

    } elseif ($effectiveTarget === 'invoice_storno') {
        // Storno: nur aus Rechnung (ar) erlaubt
        if ($sourceTable !== 'ar') {
            resultInfo(false, 'INVALID_SOURCE', 'Storno kann nur aus einer Rechnung erstellt werden');
            return;
        }

        // Prüfen: Ist die Quellrechnung selbst ein Storno?
        if (!empty($source['storno']) && ($source['storno'] === true || $source['storno'] === 't')) {
            resultInfo(false, 'ALREADY_STORNO', 'Eine Storno-Rechnung kann nicht erneut storniert werden');
            return;
        }

        // Prüfen: Existiert bereits ein Storno für diese Rechnung?
        $existingStorno = $company->getOne(
            "SELECT id, invnumber FROM ar WHERE storno_id = :sourceId AND storno = true LIMIT 1",
            [':sourceId' => $sourceId]
        );
        if ($existingStorno) {
            resultInfo(false, 'STORNO_EXISTS', 'Für diese Rechnung existiert bereits ein Storno: ' . ($existingStorno['invnumber'] ?? ''));
            return;
        }

        // Neuen Storno-Record anlegen
        $query = <<<SQL
            WITH tmp AS (UPDATE defaults SET invnumber = COALESCE(invnumber::INT, 0) + 1 RETURNING invnumber)
            INSERT INTO ar (invnumber, transdate, gldate, employee_id, customer_id, taxzone_id, currency_id, invoice, type, storno, storno_id)
            SELECT (SELECT invnumber FROM tmp), CURRENT_DATE, CURRENT_DATE, :employee_id, :customer_id, :taxzone_id, :currency_id, true, 'invoice_storno', true, :storno_id
            RETURNING id
SQL;
        $result = $company->getOne($query, [
            ':employee_id' => $employeeId,
            ':customer_id' => $customerId,
            ':taxzone_id'  => $taxzoneId,
            ':currency_id' => $currencyId,
            ':storno_id'   => $sourceId
        ]);
        $newId = intval($result['id']);

        // Original-Rechnung als storniert markieren: paid = amount, storno = true
        $company->execute(
            "UPDATE ar SET paid = amount, storno = true,
             intnotes = CONCAT('Storniert am ' || CURRENT_DATE || E'\\n', COALESCE(intnotes, ''))
             WHERE id = :sourceId",
            [':sourceId' => $sourceId]
        );

    } else {
        resultInfo(false, 'UNSUPPORTED_TARGET', 'Zieltyp nicht unterstützt: ' . $effectiveTarget);
        return;
    }

    // ── 3. Header-Felder vom Quell-Dokument kopieren ──

    // Felder, die auf dem Zieldokument aktualisiert werden (sofern sie in der Zieltabelle existieren)
    $headerFields = [];
    $headerParams = [':newId' => $newId];

    // Gemeinsame Felder die kopiert werden sollen
    $copyFields = ['notes', 'intnotes', 'payment_id', 'delivery_term_id', 'language_id',
                   'department_id', 'cusordnumber', 'globalproject_id', 'salesman_id',
                   'shippingpoint', 'shipvia', 'transaction_description', 'shipto_id',
                   'amount', 'netamount', 'taxincluded', 'cp_id',
                   'marge_total', 'marge_percent', 'tax_point', 'exchangerate',
                   'billing_address_id'];

    // reqdate → reqdate (oe/delivery_orders) oder deliverydate (ar/ap)
    if (isset($source['reqdate']) && $source['reqdate']) {
        $targetDateField = in_array($targetTable, ['ar', 'ap']) ? 'deliverydate' : 'reqdate';
        $headerFields[] = "{$targetDateField} = :reqdate_val";
        $headerParams[':reqdate_val'] = $source['reqdate'];
    } elseif (isset($source['deliverydate']) && $source['deliverydate']) {
        $targetDateField = in_array($targetTable, ['ar', 'ap']) ? 'deliverydate' : 'reqdate';
        $headerFields[] = "{$targetDateField} = :reqdate_val";
        $headerParams[':reqdate_val'] = $source['deliverydate'];
    }

    // Nur Spalten, die die Zieltabelle hat (delivery_orders kennt keine Beträge, ap keine
    // Lieferadresse/Kundenauftragsnummer) — sonst scheitert das UPDATE
    $targetColumns = array_column($company->getAll(
        "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t",
        [':t' => $targetTable]
    ), 'column_name');

    foreach ($copyFields as $field) {
        if (!in_array($field, $targetColumns, true)) {
            continue;
        }
        if (isset($source[$field]) && $source[$field] !== null && $source[$field] !== '') {
            $headerFields[] = "{$field} = :{$field}_val";
            $headerParams[":{$field}_val"] = $source[$field];
        }
    }

    // Gutschrift/Storno: Beträge negieren
    if (in_array($effectiveTarget, ['credit_note', 'invoice_storno'])) {
        if (isset($headerParams[':amount_val'])) {
            $headerParams[':amount_val'] = -1 * abs(floatval($headerParams[':amount_val']));
        }
        if (isset($headerParams[':netamount_val'])) {
            $headerParams[':netamount_val'] = -1 * abs(floatval($headerParams[':netamount_val']));
        }
        if (isset($headerParams[':marge_total_val'])) {
            $headerParams[':marge_total_val'] = -1 * abs(floatval($headerParams[':marge_total_val']));
        }
    }

    // ── 3b. Referenznummern kopieren ──
    // Angebotsnr → neuen Auftrag/Rechnung, Auftragsnr → neue Rechnung/Lieferschein

    if (!$isReuse && $sourceTable === 'oe') {
        // ordnumber → ar, ap, delivery_orders, oe (z.B. Auftrag → Rechnung, Auftrag → Angebot)
        if (!empty($source['ordnumber']) && in_array($targetTable, ['ar', 'ap', 'delivery_orders', 'oe'])) {
            $headerFields[] = "ordnumber = :ref_ordnumber";
            $headerParams[':ref_ordnumber'] = $source['ordnumber'];
        }

        // quonumber → oe, ar, ap (z.B. Angebot → Auftrag, Angebot → Rechnung)
        if (!empty($source['quonumber'])) {
            if (in_array($targetTable, ['oe', 'ar', 'ap'])) {
                $headerFields[] = "quonumber = :ref_quonumber";
                $headerParams[':ref_quonumber'] = $source['quonumber'];
            }
        }
    }

    // Lieferschein als Quelle: Liefer- und Auftragsnummer mitnehmen (ar kennt beide)
    if (!$isReuse && $sourceTable === 'delivery_orders' && in_array($targetTable, ['ar', 'ap', 'oe'], true)) {
        if (!empty($source['donumber']) && in_array('donumber', $targetColumns, true)) {
            $headerFields[] = "donumber = :ref_donumber";
            $headerParams[':ref_donumber'] = $source['donumber'];
        }
        if (!empty($source['ordnumber']) && in_array('ordnumber', $targetColumns, true)) {
            $headerFields[] = "ordnumber = :ref_ordnumber";
            $headerParams[':ref_ordnumber'] = $source['ordnumber'];
        }
    }

    if (!empty($headerFields)) {
        $setClause = implode(', ', $headerFields);
        $company->execute(
            "UPDATE {$targetTable} SET {$setClause} WHERE id = :newId",
            $headerParams
        );
    }

    // ── 4. Positionen kopieren ──

    $sourceItemsTable = $sourceConfig['items_table'];
    $targetItemsTable = $targetConfig['items_table'];

    // ID-Spalte in der Ziel-Items-Tabelle
    $targetIdCol = ($targetItemsTable === 'delivery_order_items') ? 'delivery_order_id' : 'trans_id';
    $sourceIdCol = ($sourceItemsTable === 'delivery_order_items') ? 'delivery_order_id' : 'trans_id';

    // Gemeinsame Spalten
    $commonCols = 'parts_id, description, longdescription, qty, sellprice, discount, unit, position';

    // Gutschrift: Mengen negieren (wie kivitendo)
    $qtyExpr = in_array($effectiveTarget, ['credit_note', 'invoice_storno']) ? '(qty * -1)' : 'qty';

    // Für Rechnungen/Gutschriften: fxsellprice zusätzlich setzen
    if ($targetItemsTable === 'invoice') {
        $company->execute(
            "INSERT INTO invoice ({$targetIdCol}, parts_id, description, longdescription, qty, sellprice, discount, unit, position, fxsellprice)
             SELECT :newId, parts_id, description, longdescription, {$qtyExpr}, sellprice, discount, unit, position, sellprice
             FROM {$sourceItemsTable}
             WHERE {$sourceIdCol} = :sourceId
             ORDER BY position",
            [':newId' => $newId, ':sourceId' => $sourceId]
        );
    } else {
        $company->execute(
            "INSERT INTO {$targetItemsTable} ({$targetIdCol}, {$commonCols})
             SELECT :newId, {$commonCols}
             FROM {$sourceItemsTable}
             WHERE {$sourceIdCol} = :sourceId
             ORDER BY position",
            [':newId' => $newId, ':sourceId' => $sourceId]
        );
    }

    // ── 5. record_links erstellen ──

    $company->execute(
        "INSERT INTO record_links (from_table, from_id, to_table, to_id)
         VALUES (:from_table, :from_id, :to_table, :to_id)",
        [
            ':from_table' => $sourceTable,
            ':from_id'    => $sourceId,
            ':to_table'   => $targetTable,
            ':to_id'      => $newId
        ]
    );

    // ── 5b. Lieferschein als Quelle ──
    // Er hat keine Beträge: Summe des Ziels aus den Positionen rechnen (sonst steht
    // die Rechnung auf 0 und die Buchung scheitert). Wird daraus eine Rechnung,
    // gilt er wie in kivitendo (close_orders) als erledigt.
    if (!$isReuse && $sourceTable === 'delivery_orders') {
        if (in_array($targetTable, ['oe', 'ar'], true)) {
            recalcFakturaTotals($company, $effectiveTarget, $newId, true);
        }
        if (in_array($targetTable, ['ar', 'ap'], true)) {
            $company->execute("UPDATE delivery_orders SET closed = TRUE WHERE id = :id", [':id' => $sourceId]);
        }
    }

    // ── 6. LxCars: Fahrzeug-Zuordnung + Fertigstellung + Mängel kopieren ──
    // Nur bei aktiver Erweiterung: ohne LxCars gibt es die Tabellen oe_ext/ar_ext
    // und oe_defects/ar_defects nicht. Ein SQL-Fehler innerhalb der laufenden
    // Transaktion bricht diese in PostgreSQL ab — das Commit weiter unten rollt
    // dann still alles zurück und die Antwort meldet eine ID, die es nicht gibt.
    // Deshalb zusätzlich ein SAVEPOINT, auf den im Fehlerfall zurückgesetzt wird,
    // damit die Konvertierung selbst durchkommt.

    $lxcarsActive = isExtensionActive($company, 'lxcars');
    if ($lxcarsActive) {
        $company->execute('SAVEPOINT lxcars_copy');
    }

    try {
        $sourceExtTable = ($sourceTable === 'ar') ? 'ar_ext' : (($sourceTable === 'oe') ? 'oe_ext' : null);
        $targetExtTable = ($targetTable === 'ar') ? 'ar_ext' : (($targetTable === 'oe') ? 'oe_ext' : null);

        if ($lxcarsActive && $sourceExtTable && $targetExtTable) {
            $sourceExtIdCol = ($sourceExtTable === 'ar_ext') ? 'ar_id' : 'oe_id';

            // Fahrzeug + km_stand + fertigstellung laden
            $extCols = 'c_id, km_stand';
            if ($sourceExtTable === 'oe_ext') $extCols .= ', fertigstellung';
            if ($sourceExtTable === 'ar_ext') $extCols .= ', fertigstellung';

            $sourceExt = $company->getOne(
                "SELECT {$extCols} FROM {$sourceExtTable} WHERE {$sourceExtIdCol} = :sourceId",
                [':sourceId' => $sourceId]
            );

            if ($sourceExt && $sourceExt['c_id']) {
                $cId = intval($sourceExt['c_id']);
                $kmStand = $sourceExt['km_stand'] !== null ? intval($sourceExt['km_stand']) : null;
                $fertigstellung = $sourceExt['fertigstellung'] ?? null;

                if ($targetExtTable === 'ar_ext') {
                    $company->execute(
                        "INSERT INTO ar_ext (ar_id, c_id, km_stand, fertigstellung)
                         VALUES (:newId, :c_id, :km_stand, :fertigstellung)
                         ON CONFLICT (ar_id) DO UPDATE SET c_id = EXCLUDED.c_id, km_stand = EXCLUDED.km_stand, fertigstellung = EXCLUDED.fertigstellung",
                        [':newId' => $newId, ':c_id' => $cId, ':km_stand' => $kmStand, ':fertigstellung' => $fertigstellung]
                    );
                } else {
                    $company->execute(
                        "INSERT INTO oe_ext (oe_id, c_id, km_stand) VALUES (:newId, :c_id, :km_stand)
                         ON CONFLICT (oe_id) DO UPDATE SET c_id = EXCLUDED.c_id, km_stand = EXCLUDED.km_stand",
                        [':newId' => $newId, ':c_id' => $cId, ':km_stand' => $kmStand]
                    );
                }

                // Nur bei Auftrag -> Rechnung: HU-Termin im Fahrzeug automatisch setzen,
                // wenn eine Auftragsposition einen der konfigurierten Trigger-Begriffe enthaelt.
                // Neuer HU-Termin = heute + 2 Jahre, jeweils 1. des Monats.
                if ($sourceTable === 'oe' && $targetTable === 'ar') {
                    $company->execute(
                        "UPDATE cars_lxcars
                         SET c_hu = (date_trunc('month', CURRENT_DATE + INTERVAL '2 years'))::date
                         WHERE c_id = :c_id
                           AND EXISTS (
                               SELECT 1
                               FROM orderitems oi,
                                    defaults_oserp d,
                                    unnest(string_to_array(d.value, ',')) AS trigger_raw
                               WHERE oi.trans_id = :source_id
                                 AND d.key = 'lxcars_hu_trigger_descriptions'
                                 AND trim(trigger_raw) <> ''
                                 AND oi.description ILIKE '%' || trim(trigger_raw) || '%'
                           )",
                        [':c_id' => $cId, ':source_id' => $sourceId]
                    );
                }
            }
        }

        // Defects kopieren (oe_defects → ar_defects oder umgekehrt)
        $sourceDefectsTable = ($sourceTable === 'ar') ? 'ar_defects' : (($sourceTable === 'oe') ? 'oe_defects' : null);
        $targetDefectsTable = ($targetTable === 'ar') ? 'ar_defects' : (($targetTable === 'oe') ? 'oe_defects' : null);

        if ($lxcarsActive && $sourceDefectsTable && $targetDefectsTable) {
            $srcDefectsIdCol = ($sourceDefectsTable === 'ar_defects') ? 'ar_id' : 'oe_id';
            $tgtDefectsIdCol = ($targetDefectsTable === 'ar_defects') ? 'ar_id' : 'oe_id';

            $company->execute(
                "INSERT INTO {$targetDefectsTable} ({$tgtDefectsIdCol}, defect_code, defect_description, defect_class, note, sort_order)
                 SELECT :newId, defect_code, defect_description, defect_class, note, sort_order
                 FROM {$sourceDefectsTable}
                 WHERE {$srcDefectsIdCol} = :sourceId
                 ORDER BY sort_order",
                [':newId' => $newId, ':sourceId' => $sourceId]
            );
        }
    } catch (Exception $e) {
        // LxCars-Kopie fehlgeschlagen — Konvertierung trotzdem abschließen
        error_log('LxCars data copy failed: ' . $e->getMessage());
        if ($lxcarsActive) {
            $company->execute('ROLLBACK TO SAVEPOINT lxcars_copy');
        }
    }

    // Storno: Neuen Storno-Record auch als bezahlt markieren (paid = amount)
    if ($effectiveTarget === 'invoice_storno') {
        $company->execute("UPDATE ar SET paid = amount WHERE id = :newId", [':newId' => $newId]);
    }

    $company->commit();

    // Auftrag → Rechnung: sofort ins Hauptbuch buchen (acc_trans), damit die Rechnung
    // in GuV/UStVA/Bilanz erscheint und Zahlungen/Forderungskonto sauber greifen.
    // Best-effort NACH dem Commit: ein Buchungsfehler darf die bereits erstellte
    // Rechnung nicht zurückrollen (postArInvoiceToLedger ist idempotent + hat den
    // Mismatch-Schutz). Nur fuer echte Rechnungen, nicht Gutschrift/Storno.
    if ($effectiveTarget === 'invoice') {
        try {
            $postRes = postArInvoiceToLedger($company, $newId);
            if (empty($postRes['posted'])) {
                writeLog("convertFaktura: AR #{$newId} nicht auto-gebucht (" . ($postRes['reason'] ?? '?') . ")", true, DLOG_INF);
            }
        } catch (\Throwable $e) {
            writeLog("convertFaktura: Auto-Buchung AR #{$newId} fehlgeschlagen: " . $e->getMessage(), true, DLOG_ERR);
        }
    } elseif ($effectiveTarget === 'purchase_invoice') {
        try {
            $postRes = postApInvoiceToLedger($company, $newId);
            if (empty($postRes['posted'])) {
                writeLog("convertFaktura: AP #{$newId} nicht auto-gebucht (" . ($postRes['reason'] ?? '?') . ")", true, DLOG_INF);
            }
        } catch (\Throwable $e) {
            writeLog("convertFaktura: Auto-Buchung AP #{$newId} fehlgeschlagen: " . $e->getMessage(), true, DLOG_ERR);
        }
    }

    resultInfo(true, 'CONVERTED', ['id' => $newId, 'fakturaType' => $effectiveTarget]);

    } catch (Exception $e) {
        $company->rollBack();
        resultInfo(false, 'CONVERT_ERROR', $e->getMessage());
    }
}

/**
 * Importiert SilverDAT VXS-Positionen als Auftrags-/Rechnungspositionen (Bulk)
 *
 * Für jedes Item wird ein Artikel in der parts-Tabelle angelegt (oder ein
 * bestehender anhand der Teilenummer wiederverwendet) und eine Position
 * in der Faktura-Tabelle eingefügt.
 *
 * @param array $data Request-Daten
 * @param int    $data['fakturaID']            Faktura-ID
 * @param string $data['fakturaType']          z.B. 'order'
 * @param int    $data['buchungsgruppeTeile']  Buchungsgruppen-ID für Ersatzteile
 * @param int    $data['buchungsgruppeArbeit'] Buchungsgruppen-ID für Dienstleistungen
 * @param array  $data['items']                Array mit Items {description, partnumber, qty, sellprice, unit, part_type, longdescription}
 * @return void Gibt JSON mit der Anzahl der angelegten Positionen zurück
 * @testdata {"fakturaID": 1, "fakturaType": "order", "buchungsgruppeTeile": 1, "buchungsgruppeArbeit": 1, "items": [{"description": "Stoßfänger vorn", "partnumber": "SD-TEST-1", "qty": 1, "sellprice": 120, "unit": "Stck", "part_type": "part", "longdescription": ""}, {"description": "Stoßfänger aus- und einbauen", "partnumber": "", "qty": 4, "sellprice": 12.5, "unit": "AW", "part_type": "service", "longdescription": ""}]}
 */
function importSilverDATItems($data) {
    $fakturaID = intval($data['fakturaID'] ?? 0);
    $fakturaType = $data['fakturaType'] ?? 'order';
    $buchungsgruppeTeile = intval($data['buchungsgruppeTeile'] ?? 0);
    $buchungsgruppeArbeit = intval($data['buchungsgruppeArbeit'] ?? 0);
    $items = $data['items'] ?? [];

    if ($fakturaID <= 0) {
        resultInfo(false, 'INVALID_FAKTURA_ID', ['message' => 'Ungültige Faktura-ID']);
        return;
    }
    if (empty($items)) {
        resultInfo(false, 'NO_ITEMS', ['message' => 'Keine Positionen zum Importieren']);
        return;
    }
    if ($buchungsgruppeTeile <= 0 || $buchungsgruppeArbeit <= 0) {
        resultInfo(false, 'MISSING_BUCHUNGSGRUPPE', ['message' => 'Buchungsgruppen müssen angegeben werden']);
        return;
    }

    $company = DbhCompany::begin();

    permit(getPermissionForFakturaType($fakturaType));

    // Atomar importieren: schlägt eine einzige Position fehl, wird nichts
    // angelegt (kein Teil-Import, der den Beleg inkonsistent zuruecklaesst).
    $company->beginTransaction();
    try {
        $createdIds = _fakturaImportItems($company, $fakturaID, $fakturaType, $items, $buchungsgruppeTeile, $buchungsgruppeArbeit);
        $company->commit();
    } catch (\Throwable $e) {
        $company->rollBack();
        resultInfo(false, 'IMPORT_FAILED', ['message' => 'Import fehlgeschlagen: ' . $e->getMessage()]);
        return;
    }

    if (empty($createdIds)) {
        resultInfo(false, 'NO_ITEMS_CREATED', ['message' => 'Keine Positionen angelegt']);
        return;
    }

    resultInfo(true, 'IMPORTED', ['count' => count($createdIds)]);
}

/**
 * Berechnet die Kopfbeträge (netamount/amount) eines Belegs in SQL aus den Positionen neu.
 *
 * Gleiche Regeln wie useAccounting.calculateTotals: Zeilensumme auf 2 Stellen
 * runden, je Steuersatz Netto summieren, Steuer auf der Gruppensumme runden.
 * Steuersatz wie in postArInvoiceToLedger aus Buchungsgruppe → taxzone_charts
 * (Steuerzone des Belegs) → taxkeys (gültig am Belegdatum).
 *
 * Zuständig für Aufträge/Angebote (oe/orderitems) sowie Gutschriften und
 * Stornos (ar/invoice). Letztere werden vom Editor NICHT ins Hauptbuch gebucht,
 * deshalb muss der Kopfbetrag hier aus den Positionen kommen — sonst bleibt
 * nach dem Löschen von Positionen der (negierte) Betrag der Ursprungsrechnung
 * stehen und Belegliste wie Druckvorlage zeigen einen falschen Wert.
 * Rechnungen/Einkaufsrechnungen und Lieferscheine werden nicht angefasst.
 *
 * @param object $company   DbhCompany-Handle
 * @param string $fakturaType order|purchase_order|quotation|request_quotation|credit_note|invoice_storno
 * @param int    $fakturaID   ID in oe bzw. ar
 * @param bool   $force       auch eine Rechnung (ar) berechnen — nur für eine frisch aus einem
 *                            Lieferschein erstellte, noch ungebuchte Rechnung (der Lieferschein
 *                            hat keine Beträge, ohne Summe scheitert die Buchung)
 * @return bool true wenn neu berechnet wurde
 */
function recalcFakturaTotals($company, $fakturaType, $fakturaID, $force = false) {
    $fakturaID = intval($fakturaID);
    if ($fakturaID <= 0) {
        return false;
    }

    if (in_array($fakturaType, ['order', 'purchase_order', 'quotation', 'request_quotation'])) {
        $mainTable = 'oe';
        $itemsTable = 'orderitems';
    } elseif (hasNegativeQty($fakturaType) || ($force && $fakturaType === 'invoice')) {
        $mainTable = 'ar';
        $itemsTable = 'invoice';
    } else {
        return false;
    }

    $totalsQuery = <<<SQL
        WITH pos AS (
            SELECT COALESCE(m.taxincluded, FALSE) AS taxincluded, COALESCE(bz.rate, 0) AS rate,
                   ROUND((i.qty * i.sellprice * (1 - COALESCE(i.discount, 0)))::numeric, 2) AS total
            FROM {$itemsTable} i
            JOIN {$mainTable} m ON m.id = i.trans_id
            JOIN parts p ON p.id = i.parts_id
            LEFT JOIN LATERAL (
                SELECT tx.rate
                FROM buchungsgruppen bg
                JOIN taxzone_charts tc ON tc.buchungsgruppen_id = bg.id AND tc.taxzone_id = m.taxzone_id
                JOIN chart c2 ON c2.id = tc.income_accno_id
                LEFT JOIN taxkeys tk ON tk.chart_id = c2.id AND tk.startdate <= m.transdate
                LEFT JOIN tax tx ON tx.id = tk.tax_id
                WHERE bg.id = p.buchungsgruppen_id
                ORDER BY tk.startdate DESC NULLS LAST
                LIMIT 1
            ) bz ON TRUE
            WHERE i.trans_id = :fakturaID
        ), grp AS (
            SELECT taxincluded, rate,
                   SUM(total) AS gross_base,
                   SUM(CASE WHEN taxincluded AND rate <> 0 THEN ROUND(total / (1 + rate), 2) ELSE total END) AS net_base
            FROM pos
            GROUP BY taxincluded, rate
        ), sums AS (
            SELECT COALESCE(bool_or(taxincluded), FALSE) AS taxincluded,
                   COALESCE(SUM(gross_base), 0) AS gross_base,
                   COALESCE(SUM(net_base), 0) AS net_base,
                   COALESCE(SUM(ROUND(net_base * rate, 2)), 0) AS tax
            FROM grp
        )
        UPDATE {$mainTable} SET
            netamount = CASE WHEN sums.taxincluded THEN sums.gross_base - sums.tax ELSE sums.net_base END,
            amount    = CASE WHEN sums.taxincluded THEN sums.gross_base ELSE sums.net_base + sums.tax END
        FROM sums
        WHERE {$mainTable}.id = :mainID
SQL;
    $company->execute($totalsQuery, ['fakturaID' => $fakturaID, 'mainID' => $fakturaID]);
    return true;
}

/**
 * Legt Positionen (mit Artikeln) in einem Beleg an — gemeinsamer Kern von
 * importSilverDATItems (VXS) und applyGutachtenToOrder (Gutachten-PDF).
 *
 * Läuft innerhalb der Transaktion des Aufrufers; wirft bei Fehlern, damit
 * der Aufrufer zurückrollen kann.
 *
 * @param object $company   DbhCompany
 * @param int    $fakturaID Beleg-ID
 * @param string $fakturaType z.B. 'order'
 * @param array  $items     {description, partnumber, qty, sellprice, unit, part_type, longdescription}
 * @param int    $buchungsgruppeTeile  Buchungsgruppe für Ersatzteile
 * @param int    $buchungsgruppeArbeit Buchungsgruppe für Dienstleistungen
 * @return array IDs der angelegten Positionen
 */
function _fakturaImportItems($company, $fakturaID, $fakturaType, array $items, $buchungsgruppeTeile, $buchungsgruppeArbeit) {
    $tableConfig = getFakturaTableConfig($fakturaType);
    $itemsTable = $tableConfig['items_table'];
    $itemsFk = $tableConfig['items_fk'];
    $isInvoiceType = in_array($fakturaType, ['invoice', 'purchase_invoice']);

    // Nächste Position ermitteln
    $positionQuery = "SELECT COALESCE(MAX(position), 0) AS max_position FROM {$itemsTable} WHERE {$itemsFk} = :fakturaID";
    $nextPosition = intval($company->getOne($positionQuery, ['fakturaID' => $fakturaID])['max_position']);

    $createdIds = [];

    // Fehlende Einheiten anlegen: SilverDAT liefert Lohn und Lackierung in
    // 'AW' (Arbeitswert), das in einer frischen kivitendo-Datenbank fehlt.
    // parts.unit und orderitems.unit haben einen Fremdschlüssel auf units —
    // ohne die Zeile scheitert der gesamte Import. Eine Abfrage für alle
    // Einheiten des Imports, nur wirklich neue werden eingefügt.
    $unitNames = [];
    $unitTypes = [];
    foreach ($items as $item) {
        $unit = trim($item['unit'] ?? '');
        if ($unit === '' || isset($unitNames[$unit])) continue;
        $unitNames[$unit] = $unit;
        $unitTypes[$unit] = (($item['part_type'] ?? 'service') === 'part') ? 'dimension' : 'service';
    }
    if (!empty($unitNames)) {
        $unitQuery = <<<SQL
            INSERT INTO units (name, type, sortkey)
            SELECT u.name, u.type,
                   (SELECT COALESCE(MAX(sortkey), 0) FROM units) + ROW_NUMBER() OVER ()
            FROM UNNEST(CAST(:names AS text[]), CAST(:types AS text[])) AS u(name, type)
            WHERE NOT EXISTS (SELECT 1 FROM units WHERE units.name = u.name)
SQL;
        // execute() bindet Arrays nicht selbst → als PostgreSQL-Array-Literal übergeben
        $pgArray = fn(array $values) => '{' . implode(',', array_map(
            fn($v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"', $values
        )) . '}';
        $company->execute($unitQuery, [
            'names' => $pgArray(array_values($unitNames)),
            'types' => $pgArray(array_values($unitTypes))
        ]);
    }

    foreach ($items as $item) {
        $nextPosition++;
        $description = trim($item['description'] ?? '');
        $partnumber = trim($item['partnumber'] ?? '');
        $partType = $item['part_type'] ?? 'service';
        $buchungsgruppenId = ($partType === 'part') ? $buchungsgruppeTeile : $buchungsgruppeArbeit;

        if (empty($description)) continue;

        // 1. Artikel finden oder anlegen
        $partsId = null;

        if (!empty($partnumber)) {
            // Bestehenden Artikel suchen
            $existing = $company->getOne(
                "SELECT id FROM parts WHERE partnumber = :pn LIMIT 1",
                ['pn' => $partnumber]
            );
            if ($existing) {
                $partsId = intval($existing['id']);
            }
        }

        if (!$partsId) {
            // Neuen Artikel anlegen
            if (!empty($partnumber)) {
                $partQuery = <<<SQL
                    INSERT INTO parts (partnumber, description, part_type, buchungsgruppen_id, sellprice, unit, obsolete)
                    VALUES (:partnumber, :description, :part_type, :buchungsgruppen_id, :sellprice, :unit, FALSE)
                    RETURNING id
SQL;
                $partResult = $company->getOne($partQuery, [
                    'partnumber' => $partnumber,
                    'description' => $description,
                    'part_type' => $partType,
                    'buchungsgruppen_id' => $buchungsgruppenId,
                    'sellprice' => floatval($item['sellprice'] ?? 0),
                    'unit' => trim($item['unit'] ?? 'Stck')
                ]);
            } else {
                // Ohne Teilenummer → interne Nummer aus dem defaults-Nummernkreis.
                // Bewusst KEIN MAX(partnumber)-Scan: importierte Fremdnummern (z. B.
                // EANs) sollen den internen Zähler nicht hochreissen. nextFreeNumber
                // zählt defaults hoch und überspringt bereits vergebene Nummern.
                $numberField = ($partType === 'service') ? 'servicenumber' : 'articlenumber';
                $newNumber = nextFreeNumber($company, $numberField, 'parts', 'partnumber');

                $partQuery = <<<SQL
                    INSERT INTO parts (partnumber, description, part_type, buchungsgruppen_id, sellprice, unit, obsolete)
                    VALUES (:partnumber, :description, :part_type, :buchungsgruppen_id, :sellprice, :unit, FALSE)
                    RETURNING id
SQL;
                $partResult = $company->getOne($partQuery, [
                    'partnumber' => $newNumber,
                    'description' => $description,
                    'part_type' => $partType,
                    'buchungsgruppen_id' => $buchungsgruppenId,
                    'sellprice' => floatval($item['sellprice'] ?? 0),
                    'unit' => trim($item['unit'] ?? 'Stck')
                ]);
            }
            $partsId = intval($partResult['id']);
        }

        // 2. Position einfügen
        $columns = 'trans_id, parts_id, position, description, longdescription, qty, sellprice, discount, unit';
        // Leerer Langtext -> Langtext des Artikels (parts.notes), wie in kivitendo
        $values = ":trans_id, :parts_id, :position, :description, COALESCE(NULLIF(:longdescription, ''), (SELECT notes FROM parts WHERE id = :ld_parts_id), ''), :qty, :sellprice, :discount, :unit";
        $params = [
            'trans_id' => $fakturaID,
            'parts_id' => $partsId,
            'ld_parts_id' => $partsId,
            'position' => $nextPosition,
            'description' => $description,
            'longdescription' => trim($item['longdescription'] ?? ''),
            'qty' => floatval($item['qty'] ?? 1),
            'sellprice' => floatval($item['sellprice'] ?? 0),
            'discount' => 0,
            'unit' => trim($item['unit'] ?? '')
        ];

        if ($isInvoiceType) {
            $columns .= ', fxsellprice';
            $values .= ', :fxsellprice';
            $params['fxsellprice'] = floatval($item['sellprice'] ?? 0);
        }

        $insertResult = $company->getOne(
            "INSERT INTO {$itemsTable} ({$columns}) VALUES ({$values}) RETURNING id",
            $params
        );
        $createdIds[] = intval($insertResult['id']);
    }

    // Kopfbeträge aus den Positionen neu berechnen — sonst bleibt in der
    // Belegübersicht der alte Betrag stehen, bis der Beleg im Editor erneut
    // gespeichert wird. Rechnungen bleiben außen vor: dort gehören auch
    // acc_trans-Buchungen dazu, die der Editor beim Speichern erzeugt.
    recalcFakturaTotals($company, $fakturaType, $fakturaID);

    return $createdIds;
}
