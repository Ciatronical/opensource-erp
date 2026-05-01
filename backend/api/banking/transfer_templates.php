<?php
// backend/api/banking/transfer_templates.php
//
// Ueberweisungsvorlagen mit Gruppen.
// Platzhalter im Verwendungszweck werden client-seitig aufgeloest.

// ─── Gruppen ──────────────────────────────────────────────

/**
 * Alle Gruppen inkl. Anzahl der Vorlagen laden
 * @testdata {}
 */
function getTransferTemplateGroups($data) {
    $db = DbhCompany::begin();
    $result = $db->getOne(<<<SQL
        SELECT json_agg(row_to_json(t) ORDER BY t.sort_order, t.name) AS groups
        FROM (
            SELECT g.*,
                   COUNT(tt.id)::integer AS template_count
            FROM transfer_template_groups g
            LEFT JOIN transfer_templates tt ON tt.group_id = g.id AND tt.active
            GROUP BY g.id
            ORDER BY g.sort_order, g.name
        ) t
    SQL);
    resultInfo(true, '', ['groups' => json_decode($result['groups'] ?? '[]', true) ?: []]);
}

/**
 * Gruppe anlegen
 * @param string $data['name']
 * @param string $data['color']  optional (Vuetify-Farbname)
 * @testdata {"name":"Lohn April","color":"primary"}
 */
function createTransferTemplateGroup($data) {
    $db   = DbhCompany::begin();
    $name = trim($data['name'] ?? '');
    if (!$name) { resultInfo(false, 'VALIDATION_ERROR', 'Name ist Pflicht'); return; }
    $row  = $db->getOne(
        "INSERT INTO transfer_template_groups (name, color, description) VALUES (:n, :c, :d) RETURNING id",
        ['n' => $name, 'c' => $data['color'] ?? 'primary', 'd' => trim($data['description'] ?? '') ?: null]
    );
    resultInfo(true, '', ['id' => $row['id']]);
}

/**
 * Gruppe ändern
 * @param int    $data['id']
 * @param string $data['name']
 * @testdata {"id":1,"name":"Lohn Mai","color":"success"}
 */
function updateTransferTemplateGroup($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }
    $db->execute(
        "UPDATE transfer_template_groups SET name=:n, color=:c, description=:d WHERE id=:id",
        ['id' => $id, 'n' => trim($data['name'] ?? ''), 'c' => $data['color'] ?? 'primary', 'd' => trim($data['description'] ?? '') ?: null]
    );
    resultInfo(true, '');
}

/**
 * Gruppe löschen (nur wenn keine aktiven Vorlagen)
 * @param int $data['id']
 * @testdata {"id":1}
 */
function deleteTransferTemplateGroup($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }
    $count = $db->getOne("SELECT COUNT(*)::int AS c FROM transfer_templates WHERE group_id = :id AND active", ['id' => $id]);
    if ($count['c'] > 0) {
        resultInfo(false, 'HAS_TEMPLATES', 'Gruppe enthält noch aktive Vorlagen — bitte zuerst entfernen oder verschieben');
        return;
    }
    $db->execute("DELETE FROM transfer_template_groups WHERE id = :id", ['id' => $id]);
    resultInfo(true, '');
}

// ─── Vorlagen ─────────────────────────────────────────────

/**
 * Vorlagen laden (alle oder nach Gruppe)
 * @param int $data['group_id']         optional
 * @param int $data['bank_account_id']  optional
 * @testdata {}
 */
function getTransferTemplates($data) {
    $db      = DbhCompany::begin();
    $where   = [];
    $params  = [];
    if (!empty($data['group_id']))        { $where[] = 'tt.group_id = :gid';  $params['gid']  = intval($data['group_id']); }
    if (!empty($data['bank_account_id'])) { $where[] = 'tt.bank_account_id = :aid'; $params['aid'] = intval($data['bank_account_id']); }
    if (!isset($data['include_inactive'])) { $where[] = 'tt.active'; }
    $wClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $result = $db->getOne(<<<SQL
        SELECT json_agg(row_to_json(t) ORDER BY t.sort_order, t.name, t.remote_name) AS templates
        FROM (
            SELECT tt.*,
                   g.name  AS group_name,
                   g.color AS group_color,
                   ba.name AS account_name
            FROM transfer_templates tt
            LEFT JOIN transfer_template_groups g  ON g.id  = tt.group_id
            LEFT JOIN bank_accounts           ba ON ba.id = tt.bank_account_id
            {$wClause}
            ORDER BY tt.sort_order, tt.name, tt.remote_name
        ) t
    SQL, $params);
    resultInfo(true, '', ['templates' => json_decode($result['templates'] ?? '[]', true) ?: []]);
}

/**
 * Vorlage anlegen
 * @param string $data['remote_name']
 * @param string $data['remote_iban']
 * @param float  $data['amount']
 * @param string $data['purpose_template']  Mit Platzhaltern, z.B. "Lohn {MM/JJJJ}"
 * @testdata {"remote_name":"Max Mustermann","remote_iban":"DE89370400440532013000","amount":2500,"purpose_template":"Lohn {MM/JJJJ}","group_id":1}
 */
function createTransferTemplate($data) {
    $db   = DbhCompany::begin();
    $name = trim($data['remote_name']      ?? '');
    $iban = strtoupper(str_replace(' ', '', trim($data['remote_iban'] ?? '')));
    $amt  = floatval($data['amount'] ?? 0);
    $pur  = trim($data['purpose_template'] ?? '');
    if (!$name || !$iban || $amt <= 0 || !$pur) {
        resultInfo(false, 'VALIDATION_ERROR', 'Empfänger, IBAN, Betrag und Verwendungszweck sind Pflicht');
        return;
    }
    $row = $db->getOne(<<<SQL
        INSERT INTO transfer_templates
            (group_id, bank_account_id, recipient_type, recipient_id,
             remote_name, remote_iban, remote_bic, amount, purpose_template, name, sort_order)
        VALUES
            (:gid, :aid, :rtype, :rid,
             :rname, :iban, :bic, :amt, :pur, :tname, :sort)
        RETURNING id
    SQL, [
        'gid'   => $data['group_id']       ? intval($data['group_id'])        : null,
        'aid'   => $data['bank_account_id'] ? intval($data['bank_account_id']) : null,
        'rtype' => $data['recipient_type']  ?? null,
        'rid'   => $data['recipient_id']    ? intval($data['recipient_id'])    : null,
        'rname' => $name,
        'iban'  => $iban,
        'bic'   => trim($data['remote_bic'] ?? '') ?: null,
        'amt'   => $amt,
        'pur'   => $pur,
        'tname' => trim($data['name'] ?? '') ?: null,
        'sort'  => intval($data['sort_order'] ?? 0),
    ]);
    resultInfo(true, '', ['id' => $row['id']]);
}

/**
 * Vorlage ändern
 * @param int $data['id']
 * @testdata {"id":1,"remote_name":"Max","remote_iban":"DE89370400440532013000","amount":2600,"purpose_template":"Lohn {MM/JJJJ}"}
 */
function updateTransferTemplate($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }
    $db->execute(<<<SQL
        UPDATE transfer_templates SET
            group_id         = :gid,
            bank_account_id  = :aid,
            remote_name      = :rname,
            remote_iban      = :iban,
            remote_bic       = :bic,
            amount           = :amt,
            purpose_template = :pur,
            name             = :tname,
            sort_order       = :sort,
            mtime            = now()
        WHERE id = :id
    SQL, [
        'id'    => $id,
        'gid'   => $data['group_id']       ? intval($data['group_id'])        : null,
        'aid'   => $data['bank_account_id'] ? intval($data['bank_account_id']) : null,
        'rname' => trim($data['remote_name']      ?? ''),
        'iban'  => strtoupper(str_replace(' ', '', trim($data['remote_iban'] ?? ''))),
        'bic'   => trim($data['remote_bic']       ?? '') ?: null,
        'amt'   => floatval($data['amount']       ?? 0),
        'pur'   => trim($data['purpose_template'] ?? ''),
        'tname' => trim($data['name']             ?? '') ?: null,
        'sort'  => intval($data['sort_order']     ?? 0),
    ]);
    resultInfo(true, '');
}

/**
 * Vorlage (de)aktivieren
 * @param int  $data['id']
 * @param bool $data['active']
 * @testdata {"id":1,"active":false}
 */
function setTransferTemplateActive($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }
    $db->execute(
        "UPDATE transfer_templates SET active = :a, mtime = now() WHERE id = :id",
        ['id' => $id, 'a' => !empty($data['active'])]
    );
    resultInfo(true, '');
}

/**
 * Vorlage löschen
 * @param int $data['id']
 * @testdata {"id":1}
 */
function deleteTransferTemplate($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    if (!$id) { resultInfo(false, 'VALIDATION_ERROR', 'ID fehlt'); return; }
    $db->execute("DELETE FROM transfer_templates WHERE id = :id", ['id' => $id]);
    resultInfo(true, '');
}

/**
 * Überweisung aus Vorlagen erzeugen (Platzhalter bereits client-seitig aufgelöst).
 * Erzeugt bank_transfer_orders im Status 'draft'.
 *
 * @param array  $data['transfers']  Liste: [{template_id, remote_name, remote_iban, remote_bic, amount, purpose, bank_account_id}]
 * @testdata {"transfers":[{"template_id":1,"remote_name":"Max","remote_iban":"DE89370400440532013000","amount":2500,"purpose":"Lohn 04/2026","bank_account_id":1}]}
 */
function createTransfersFromTemplates($data) {
    $db        = DbhCompany::begin();
    $transfers = $data['transfers'] ?? [];
    if (empty($transfers)) {
        resultInfo(false, 'VALIDATION_ERROR', 'Keine Überweisungen übergeben');
        return;
    }

    $ids = [];
    foreach ($transfers as $tx) {
        $iban = strtoupper(str_replace(' ', '', trim($tx['remote_iban'] ?? '')));
        $amt  = floatval($tx['amount'] ?? 0);
        $pur  = trim($tx['purpose'] ?? '');
        $name = trim($tx['remote_name'] ?? '');
        $aid  = intval($tx['bank_account_id'] ?? 0);
        if (!$name || !$iban || $amt <= 0 || !$pur || !$aid) continue;

        $row = $db->getOne(<<<SQL
            INSERT INTO bank_transfer_orders
                (bank_account_id, recipient_type, recipient_id,
                 remote_iban, remote_bic, remote_name,
                 amount, purpose, engine, status,
                 source_type, source_id)
            VALUES
                (:aid, :rtype, :rid,
                 :iban, :bic, :name,
                 :amt, :pur, 'vop_check', 'draft',
                 'template', :tid)
            RETURNING id
        SQL, [
            'aid'   => $aid,
            'rtype' => $tx['recipient_type'] ?? null,
            'rid'   => $tx['recipient_id']   ? intval($tx['recipient_id']) : null,
            'iban'  => $iban,
            'bic'   => trim($tx['remote_bic'] ?? '') ?: null,
            'name'  => $name,
            'amt'   => $amt,
            'pur'   => $pur,
            'tid'   => $tx['template_id'] ? intval($tx['template_id']) : null,
        ]);
        if ($row) $ids[] = $row['id'];
    }

    resultInfo(true, '', ['created' => count($ids), 'transfer_ids' => $ids]);
}
