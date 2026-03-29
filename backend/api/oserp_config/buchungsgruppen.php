<?php
// opensource-erp/backend/api/oserp_config/buchungsgruppen.php

function saveBuchungsgruppe($data) {
    $db = DbhCompany::begin();
    $bg = $data['data'];

    // UPSERT Buchungsgruppe
    $result = $db->getOne(<<<SQL
        INSERT INTO buchungsgruppen (id, description, inventory_accno_id, obsolete, sortkey)
        VALUES (:id, :description, :inventory_accno_id, :obsolete, :sortkey)
        ON CONFLICT (id) DO UPDATE SET
            description = EXCLUDED.description,
            inventory_accno_id = EXCLUDED.inventory_accno_id,
            obsolete = EXCLUDED.obsolete
        RETURNING id
    SQL, [
        'id' => $bg['id'] ?? null,
        'description' => $bg['description'],
        'inventory_accno_id' => $bg['inventory_accno_id'] ?? null,
        'obsolete' => $bg['obsolete'] ?? false,
        'sortkey' => $bg['sortkey'] ?? 0
    ]);

    $id = $result['id'];

    // Taxzone Charts neu schreiben
    $db->execute("DELETE FROM taxzone_charts WHERE buchungsgruppen_id = :id", ['id' => $id]);

    foreach ($bg['taxzone_charts'] ?? [] as $tc) {
        if ($tc['income_accno_id'] || $tc['expense_accno_id']) {
            $db->execute(<<<SQL
                INSERT INTO taxzone_charts (taxzone_id, buchungsgruppen_id, income_accno_id, expense_accno_id)
                VALUES (:taxzone_id, :buchungsgruppen_id, :income_accno_id, :expense_accno_id)
            SQL, [
                'taxzone_id' => $tc['taxzone_id'],
                'buchungsgruppen_id' => $id,
                'income_accno_id' => $tc['income_accno_id'] ?? null,
                'expense_accno_id' => $tc['expense_accno_id'] ?? null
            ]);
        }
    }

    resultInfo(true, 'Gespeichert', ['id' => $id]);
}

function deleteBuchungsgruppe($data) {
    $db = DbhCompany::begin();

    // Prüfe ob verwendet
    $result = $db->getOne("SELECT EXISTS(SELECT 1 FROM parts WHERE buchungsgruppen_id = :id) as used", ['id' => $data['id']]);

    if ($result['used'] === 't' || $result['used'] === true) {
        resultInfo(false, 'IN_USE', 'Wird verwendet');
        return;
    }

    $db->execute("DELETE FROM taxzone_charts WHERE buchungsgruppen_id = :id", ['id' => $data['id']]);
    $db->execute("DELETE FROM buchungsgruppen WHERE id = :id", ['id' => $data['id']]);

    resultInfo(true, 'Gelöscht');
}

function reorderBuchungsgruppen($data) {
    $db = DbhCompany::begin();

    $buchungsgruppeIds = $data['bg_id'] ?? [];

    // Setze sortkey basierend auf Array-Position
    foreach ($buchungsgruppeIds as $index => $id) {
        $db->execute(
            "UPDATE buchungsgruppen SET sortkey = :sortkey WHERE id = :id",
            ['sortkey' => $index, 'id' => $id]
        );
    }

    resultInfo(true, 'Reihenfolge gespeichert');
}
