<?php
// opensource-erp/backend/api/oserp_config/master_lists.php
//
// Stammdaten-Listen: die einfachen Tabellen, die kivitendo unter System pflegt
// (Einheiten, Kundentypen, Warengruppen, Preisgruppen, Preisfaktoren,
// Abteilungen, Zahlungs- und Lieferbedingungen, Sprachen, Projekttypen,
// Projektstatus, Anreden). Ein generischer Satz Funktionen, gesteuert durch
// die Whitelist in masterListDefinitions(). Nur dort eingetragene Tabellen
// und Spalten sind erreichbar — Listenname und Spaltennamen aus dem Request
// landen nie ungeprüft im SQL.

/**
 * Whitelist aller pflegbaren Listen.
 *
 * Je Liste:
 *   table    Tabellenname
 *   label    Spalte, die den Eintrag benennt (Pflichtfeld, eindeutig)
 *   columns  pflegbare Spalten mit Typ: text | int | numeric | bool
 *   sort     Sortierspalte (null = keine Reihenfolge, Liste alphabetisch)
 *   order    ORDER BY für die Ausgabe
 *   used     Verwendungsprüfung: [Tabelle, Fremdspalte, eigene Spalte]
 *   locked   Spalten, die bei verwendeten Einträgen nicht mehr änderbar sind
 *   mtime    Tabelle hat eine mtime-Spalte (wird bei Änderung gesetzt)
 */
function masterListDefinitions(): array {
    return [
        'units' => [
            'table'   => 'units',
            'mtime'   => false,
            'label'   => 'name',
            'columns' => ['name' => 'text', 'type' => 'text', 'base_unit' => 'text', 'factor' => 'numeric'],
            'sort'    => 'sortkey',
            'order'   => 'sortkey, id',
            // parts.unit & Co. verweisen per Name, nicht per ID
            'used'    => [
                ['parts', 'unit', 'name'], ['orderitems', 'unit', 'name'], ['invoice', 'unit', 'name'],
                ['delivery_order_items', 'unit', 'name'], ['assortment_items', 'unit', 'name'],
                ['instructions', 'unit', 'name'], ['units', 'base_unit', 'name'],
            ],
            'locked'  => ['name'],
        ],
        'business' => [
            'table'   => 'business',
            'mtime'   => true,
            'label'   => 'description',
            'columns' => ['description' => 'text', 'discount' => 'numeric', 'customernumberinit' => 'text', 'salesman' => 'bool'],
            'sort'    => null,
            'order'   => 'description',
            'used'    => [['customer', 'business_id', 'id'], ['vendor', 'business_id', 'id']],
            'locked'  => [],
        ],
        'partsgroup' => [
            'table'   => 'partsgroup',
            'mtime'   => true,
            'label'   => 'partsgroup',
            'columns' => ['partsgroup' => 'text', 'obsolete' => 'bool'],
            'sort'    => 'sortkey',
            'order'   => 'sortkey, id',
            'used'    => [['parts', 'partsgroup_id', 'id']],
            'locked'  => [],
        ],
        'pricegroup' => [
            'table'   => 'pricegroup',
            'mtime'   => false,
            'label'   => 'pricegroup',
            'columns' => ['pricegroup' => 'text', 'obsolete' => 'bool'],
            'sort'    => 'sortkey',
            'order'   => 'sortkey, id',
            'used'    => [['customer', 'pricegroup_id', 'id'], ['prices', 'pricegroup_id', 'id'], ['orderitems', 'pricegroup_id', 'id'], ['invoice', 'pricegroup_id', 'id']],
            'locked'  => [],
        ],
        'price_factors' => [
            'table'   => 'price_factors',
            'mtime'   => false,
            'label'   => 'description',
            'columns' => ['description' => 'text', 'factor' => 'numeric'],
            'sort'    => 'sortkey',
            'order'   => 'sortkey, id',
            'used'    => [['parts', 'price_factor_id', 'id'], ['orderitems', 'price_factor_id', 'id'], ['invoice', 'price_factor_id', 'id'], ['delivery_order_items', 'price_factor_id', 'id']],
            'locked'  => [],
        ],
        'department' => [
            'table'   => 'department',
            'mtime'   => true,
            'label'   => 'description',
            'columns' => ['description' => 'text'],
            'sort'    => null,
            'order'   => 'description',
            'used'    => [['oe', 'department_id', 'id'], ['ar', 'department_id', 'id'], ['ap', 'department_id', 'id'], ['gl', 'department_id', 'id'], ['delivery_orders', 'department_id', 'id']],
            'locked'  => [],
        ],
        'payment_terms' => [
            'table'   => 'payment_terms',
            'mtime'   => true,
            'label'   => 'description',
            'columns' => [
                'description' => 'text', 'description_long' => 'text', 'description_long_invoice' => 'text',
                'terms_netto' => 'int', 'terms_skonto' => 'int', 'percent_skonto' => 'numeric',
                'auto_calculation' => 'bool', 'obsolete' => 'bool',
            ],
            'sort'    => 'sortkey',
            'order'   => 'sortkey, id',
            'used'    => [['customer', 'payment_id', 'id'], ['vendor', 'payment_id', 'id'], ['parts', 'payment_id', 'id'], ['oe', 'payment_id', 'id'], ['ar', 'payment_id', 'id'], ['ap', 'payment_id', 'id'], ['delivery_orders', 'payment_id', 'id']],
            'locked'  => [],
        ],
        'delivery_terms' => [
            'table'   => 'delivery_terms',
            'mtime'   => true,
            'label'   => 'description',
            'columns' => ['description' => 'text', 'description_long' => 'text', 'obsolete' => 'bool'],
            'sort'    => 'sortkey',
            'order'   => 'sortkey, id',
            'used'    => [['customer', 'delivery_term_id', 'id'], ['vendor', 'delivery_term_id', 'id'], ['oe', 'delivery_term_id', 'id'], ['ar', 'delivery_term_id', 'id'], ['ap', 'delivery_term_id', 'id'], ['delivery_orders', 'delivery_term_id', 'id']],
            'locked'  => [],
        ],
        'language' => [
            'table'   => 'language',
            'mtime'   => true,
            'label'   => 'description',
            'columns' => [
                'description' => 'text', 'template_code' => 'text', 'article_code' => 'text',
                'output_numberformat' => 'text', 'output_dateformat' => 'text', 'output_longdates' => 'bool',
                'obsolete' => 'bool',
            ],
            'sort'    => null,
            'order'   => 'id',
            'used'    => [['customer', 'language_id', 'id'], ['vendor', 'language_id', 'id'], ['oe', 'language_id', 'id'], ['ar', 'language_id', 'id'], ['ap', 'language_id', 'id'], ['translation', 'language_id', 'id'], ['generic_translations', 'language_id', 'id']],
            'locked'  => [],
        ],
        'project_types' => [
            'table'   => 'project_types',
            'mtime'   => false,
            'label'   => 'description',
            'columns' => ['description' => 'text', 'internal' => 'bool'],
            'sort'    => 'position',
            'order'   => 'position, id',
            'used'    => [['project', 'project_type_id', 'id']],
            'locked'  => [],
        ],
        'project_statuses' => [
            'table'   => 'project_statuses',
            'mtime'   => true,
            'label'   => 'description',
            'columns' => ['description' => 'text', 'name' => 'text'],
            'sort'    => 'position',
            'order'   => 'position, id',
            'used'    => [['project', 'project_status_id', 'id']],
            'locked'  => [],
        ],
        'greetings' => [
            'table'   => 'greetings',
            'mtime'   => false,
            'label'   => 'description',
            'columns' => ['description' => 'text'],
            'sort'    => null,
            'order'   => 'id',
            // customer.greeting ist Freitext — keine harte Verwendung
            'used'    => [],
            'locked'  => [],
        ],
    ];
}

/**
 * Liefert die Definition einer Liste oder beendet die Anfrage mit Fehler.
 */
function masterListDefinition($name): ?array {
    $defs = masterListDefinitions();
    if (!is_string($name) || !isset($defs[$name])) {
        resultInfo(false, 'UNKNOWN_LIST', 'Unbekannte Liste');
        return null;
    }
    return $defs[$name];
}

/**
 * SQL-Ausdruck "wird verwendet" für einen Alias der Haupttabelle.
 */
function masterListUsedSql(array $def, string $alias): string {
    if (empty($def['used'])) return 'false';
    $parts = [];
    foreach ($def['used'] as [$table, $column, $own]) {
        $parts[] = "EXISTS (SELECT 1 FROM {$table} WHERE {$column} = {$alias}.{$own})";
    }
    return '(' . implode(' OR ', $parts) . ')';
}

/**
 * Alle Stammdaten-Listen in einer Abfrage. Jeder Eintrag trägt ein Feld
 * `used` (true = irgendwo referenziert, dann nicht löschbar).
 *
 * @param array $data (keine Parameter benötigt)
 * @testdata {"action": "getMasterLists"}
 */
function getMasterLists($data) {
    $db = DbhCompany::begin();
    $defs = masterListDefinitions();

    $selects = [];
    foreach ($defs as $name => $def) {
        $sortCol = $def['sort'] ? "t.{$def['sort']} AS sortkey," : '';
        $used = masterListUsedSql($def, 't');
        $selects[] = "'{$name}', (
            SELECT COALESCE(json_agg(row_to_json(x)), '[]'::json)
            FROM (
                SELECT t.id, {$sortCol} " . implode(', ', array_map(fn($c) => "t.{$c}", array_keys($def['columns']))) . ",
                       {$used} AS used
                FROM {$def['table']} t
                ORDER BY {$def['order']}
            ) x
        )";
    }

    $row = $db->getOne("SELECT json_build_object(" . implode(",\n", $selects) . ") AS lists");
    resultInfo(true, '', ['lists' => json_decode($row['lists'], true)]);
}

/**
 * Legt einen Eintrag an oder ändert ihn — eine Abfrage je Vorgang.
 * Nur Spalten der Whitelist werden übernommen. Die Eindeutigkeit der
 * Bezeichnung prüft das SQL selbst (kein Treffer = Duplikat); gesperrte
 * Spalten bleiben bei verwendeten Einträgen per CASE unverändert.
 *
 * @param string $data['list'] Listenname (Schlüssel aus masterListDefinitions)
 * @param array  $data['item'] Felder, bei Änderung mit id
 * @testdata {"action": "saveMasterListItem", "list": "department", "item": {"description": "Werkstatt"}}
 */
function saveMasterListItem($data) {
    $def = masterListDefinition($data['list'] ?? null);
    if (!$def) return;

    $item = is_array($data['item'] ?? null) ? $data['item'] : [];
    $id = intval($item['id'] ?? 0);
    $label = trim((string)($item[$def['label']] ?? ''));
    if ($label === '') {
        resultInfo(false, 'VALIDATION_ERROR', 'Bezeichnung fehlt');
        return;
    }

    // Werte typisieren, nur Whitelist-Spalten
    $columns = [];
    foreach ($def['columns'] as $col => $type) {
        if (!array_key_exists($col, $item)) continue;
        $v = $item[$col];
        switch ($type) {
            case 'bool':    $v = ($v === true || $v === 'true' || $v === 1 || $v === '1'); break;
            case 'int':     $v = ($v === null || $v === '') ? null : intval($v); break;
            case 'numeric': $v = ($v === null || $v === '') ? null : floatval(str_replace(',', '.', (string)$v)); break;
            default:        $v = ($v === null) ? null : trim((string)$v);
        }
        $columns[$col] = $v;
    }
    $columns[$def['label']] = $label;

    $db = DbhCompany::begin();
    $table = $def['table'];
    $labelCol = $def['label'];
    $noDuplicate = "NOT EXISTS (SELECT 1 FROM {$table} d WHERE lower(d.{$labelCol}) = lower(:label) AND d.id <> :id)";
    $params = $columns;
    $params['label'] = $label;
    $params['id'] = $id;

    if ($id > 0) {
        $used = masterListUsedSql($def, 't');
        $set = [];
        foreach ($columns as $col => $v) {
            $set[] = in_array($col, $def['locked'], true)
                ? "{$col} = CASE WHEN {$used} THEN t.{$col} ELSE :{$col} END"
                : "{$col} = :{$col}";
        }
        if ($def['mtime']) $set[] = 'mtime = now()';
        $row = $db->getOne(
            "UPDATE {$table} t SET " . implode(', ', $set) . " WHERE t.id = :id AND {$noDuplicate} RETURNING t.id",
            $params
        );
    } else {
        // INSERT: Sortierspalte ans Ende der Liste
        $names = array_keys($columns);
        $values = array_map(fn($c) => ":{$c}", $names);
        if ($def['sort']) {
            $names[] = $def['sort'];
            $values[] = "(SELECT COALESCE(MAX({$def['sort']}), 0) + 1 FROM {$table})";
        }
        $row = $db->getOne(
            "INSERT INTO {$table} (" . implode(', ', $names) . ")
             SELECT " . implode(', ', $values) . " WHERE {$noDuplicate}
             RETURNING id",
            $params
        );
    }

    if (!$row) {
        resultInfo(false, 'DUPLICATE', 'Bezeichnung existiert bereits');
        return;
    }
    resultInfo(true, 'Gespeichert', ['id' => $row['id']]);
}

/**
 * Löscht einen Eintrag, sofern er nirgends verwendet wird.
 *
 * @param string $data['list'] Listenname
 * @param int    $data['id']   Eintrag
 * @testdata {"action": "deleteMasterListItem", "list": "department", "id": 0}
 */
function deleteMasterListItem($data) {
    $def = masterListDefinition($data['list'] ?? null);
    if (!$def) return;
    $id = intval($data['id'] ?? 0);
    if ($id <= 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt');
        return;
    }

    $db = DbhCompany::begin();
    $row = $db->getOne(
        "DELETE FROM {$def['table']} t
         WHERE t.id = :id AND NOT " . masterListUsedSql($def, 't') . "
         RETURNING id",
        ['id' => $id]
    );
    if (!$row) {
        resultInfo(false, 'IN_USE', 'Wird verwendet und kann nicht gelöscht werden');
        return;
    }
    resultInfo(true, 'Gelöscht');
}

/**
 * Speichert die Reihenfolge einer Liste (Position = Array-Index).
 *
 * @param string $data['list'] Listenname (nur Listen mit Sortierspalte)
 * @param int[]  $data['ids']  IDs in gewünschter Reihenfolge
 * @testdata {"action": "reorderMasterList", "list": "partsgroup", "ids": []}
 */
function reorderMasterList($data) {
    $def = masterListDefinition($data['list'] ?? null);
    if (!$def) return;
    if (!$def['sort']) {
        resultInfo(false, 'NOT_SORTABLE', 'Liste hat keine Reihenfolge');
        return;
    }
    $ids = array_values(array_filter(array_map('intval', (array)($data['ids'] ?? [])), fn($i) => $i > 0));
    if (empty($ids)) {
        resultInfo(true, 'Reihenfolge gespeichert');
        return;
    }

    $db = DbhCompany::begin();
    $db->execute(
        "UPDATE {$def['table']} t
         SET {$def['sort']} = o.pos
         FROM UNNEST(CAST(:ids AS int[])) WITH ORDINALITY AS o(id, pos)
         WHERE t.id = o.id",
        ['ids' => '{' . implode(',', $ids) . '}']
    );
    resultInfo(true, 'Reihenfolge gespeichert');
}
