<?php
// backend/api/faktura/document_search.php
// Listenansicht fuer Belege (Rechnungen, Gutschriften, Auftraege, Angebote,
// Lieferscheine) und Artikel.
//
// Die Pfade /rechnung, /angebot, /lieferschein und /artikel standen zwar in der
// Routen-Tabelle, hatten aber keine Ansicht — der Aufruf landete auf "Seite
// nicht gefunden". Diese Funktion liefert die Daten dafuer.
//
// Ein Ajax-Call = eine DB-Abfrage: der Belegtyp waehlt eine feste Konfiguration
// (Tabelle, Nummernspalte, Abgrenzung). Die Konfiguration ist eine Whitelist im
// Code — es fliesst nie Benutzereingabe in den SQL-Text, Filterwerte gehen
// ausschliesslich als Prepared-Statement-Parameter hinein.

/**
 * Konfiguration je Belegtyp — Tabelle, Spalten und Abgrenzung untereinander.
 *
 * Besonderheiten des kivitendo-Schemas:
 * - Gutschriften stehen in `ar` und tragen type = 'credit_note'
 * - Angebote und Auftraege teilen sich `oe`; das Angebot hat eine quonumber,
 *   der Auftrag eine ordnumber
 */
function documentListConfig($documentType) {
    $configs = [
        'invoice' => [
            'table'      => 'ar',
            'number'     => 'invnumber',
            'filter'     => "COALESCE(d.type, '') <> 'credit_note'",
            'permission' => 'invoice_edit',
        ],
        'credit_note' => [
            'table'      => 'ar',
            'number'     => 'invnumber',
            'filter'     => "d.type = 'credit_note'",
            'permission' => 'invoice_edit',
        ],
        'order' => [
            'table'      => 'oe',
            'number'     => 'ordnumber',
            'filter'     => "COALESCE(d.ordnumber, '') <> ''",
            'permission' => 'sales_order_edit',
        ],
        'quotation' => [
            'table'      => 'oe',
            'number'     => 'quonumber',
            'filter'     => "COALESCE(d.quonumber, '') <> ''",
            'permission' => 'sales_quotation_edit',
        ],
        'delivery_order' => [
            'table'      => 'delivery_orders',
            'number'     => 'donumber',
            'filter'     => '1=1',
            'permission' => 'sales_delivery_order_edit',
        ],
    ];

    return $configs[$documentType] ?? null;
}

/**
 * Belegliste laden (neueste zuerst), optional gefiltert.
 *
 * @param string $data['documentType'] invoice | credit_note | order | quotation | delivery_order
 * @param string $data['q']            Volltext ueber Belegnummer, Kunde und Beschreibung
 * @param string $data['from']         Belegdatum ab (YYYY-MM-DD)
 * @param string $data['to']           Belegdatum bis (YYYY-MM-DD)
 * @param int    $data['limit']        Maximale Trefferzahl (Standard 200, max 1000)
 * @testdata {"action": "searchDocuments", "documentType": "invoice", "limit": 50}
 * @testdata {"action": "searchDocuments", "documentType": "quotation", "q": "Müller"}
 */
function searchDocuments($data) {
    $documentType = (string)($data['documentType'] ?? '');
    $config = documentListConfig($documentType);
    if ($config === null) {
        resultInfo(false, 'INVALID_INPUT', 'Unbekannter Belegtyp');
        return;
    }

    permit($config['permission']);

    $db    = DbhCompany::begin();
    $limit = (int)($data['limit'] ?? 200);
    if ($limit < 1)    $limit = 1;
    if ($limit > 1000) $limit = 1000;

    $q    = trim((string)($data['q'] ?? ''));
    $from = trim((string)($data['from'] ?? ''));
    $to   = trim((string)($data['to'] ?? ''));

    $table   = $config['table'];
    $number  = $config['number'];
    $filter  = $config['filter'];

    // Lieferscheine fuehren keine Betraege — dann bleibt die Spalte leer.
    $amount  = $table === 'delivery_orders' ? 'NULL::numeric' : 'd.amount';
    // "Erledigt": Rechnung vollstaendig bezahlt bzw. Beleg geschlossen
    $closed  = $table === 'ar'
        ? 'ABS(d.amount) > 0 AND ABS(d.paid) >= ABS(d.amount)'
        : 'COALESCE(d.closed, FALSE)';

    $params = [':limit' => $limit];
    $where  = [$filter];

    if ($q !== '') {
        $params[':q'] = '%' . $q . '%';
        $where[] = "(d.$number ILIKE :q
                     OR c.name ILIKE :q
                     OR COALESCE(d.transaction_description, '') ILIKE :q)";
    }
    if ($from !== '') {
        $params[':from'] = $from;
        $where[] = 'd.transdate >= :from::date';
    }
    if ($to !== '') {
        $params[':to'] = $to;
        $where[] = 'd.transdate <= :to::date';
    }

    $whereSql = implode(' AND ', $where);

    $documents = $db->getAll(
        "SELECT d.id,
                d.$number                        AS number,
                d.transdate,
                c.name                           AS customer_name,
                c.customernumber,
                $amount                          AS amount,
                ($closed)                        AS closed,
                COALESCE(d.transaction_description, '') AS description
           FROM $table d
           LEFT JOIN customer c ON c.id = d.customer_id
          WHERE $whereSql
          ORDER BY d.transdate DESC NULLS LAST, d.id DESC
          LIMIT :limit",
        $params
    );

    resultInfo(true, '', ['documents' => $documents]);
}

/**
 * Artikelliste fuer /artikel (Klick fuehrt in die Artikelbearbeitung).
 *
 * @param string $data['q']     Volltext ueber Artikelnummer und Bezeichnung
 * @param bool   $data['all']   true = auch ausgemusterte Artikel zeigen
 * @param int    $data['limit'] Maximale Trefferzahl (Standard 200, max 1000)
 * @testdata {"action": "searchParts", "q": "Bremse", "limit": 50}
 */
function searchParts($data) {
    $db    = DbhCompany::begin();
    $limit = (int)($data['limit'] ?? 200);
    if ($limit < 1)    $limit = 1;
    if ($limit > 1000) $limit = 1000;

    $q   = trim((string)($data['q'] ?? ''));
    $all = !empty($data['all']);

    $params = [':limit' => $limit];
    $where  = [$all ? '1=1' : 'NOT COALESCE(p.obsolete, FALSE)'];

    if ($q !== '') {
        $params[':q'] = '%' . $q . '%';
        $where[] = '(p.partnumber ILIKE :q OR p.description ILIKE :q)';
    }

    $whereSql = implode(' AND ', $where);

    $parts = $db->getAll(
        "SELECT p.id,
                p.partnumber,
                p.description,
                p.unit,
                p.sellprice,
                p.onhand,
                COALESCE(p.obsolete, FALSE) AS obsolete
           FROM parts p
          WHERE $whereSql
          ORDER BY p.partnumber
          LIMIT :limit",
        $params
    );

    resultInfo(true, '', ['parts' => $parts]);
}
