<?php
// opensource-erp/backend/api/oserp_config/taxzones.php

function saveTaxzone($data) {
    $db = DbhCompany::begin();
    $tz = $data['data'];

    // UPDATE existierende Steuerzone
    if (!empty($tz['id'])) {
        $result = $db->getOne(<<<SQL
            UPDATE tax_zones
            SET description = :description,
                obsolete = :obsolete
            WHERE id = :id
            RETURNING id
        SQL, [
            'id' => $tz['id'],
            'description' => $tz['description'],
            'obsolete' => $tz['obsolete'] ?? false
        ]);

        $id = $result['id'];
    } else {
        // INSERT neue Steuerzone
        $result = $db->getOne(<<<SQL
            INSERT INTO tax_zones (description, obsolete, sortkey)
            VALUES (:description, :obsolete, :sortkey)
            RETURNING id
        SQL, [
            'description' => $tz['description'],
            'obsolete' => false,
            'sortkey' => $tz['sortkey'] ?? 0
        ]);

        $id = $result['id'];
    }

    // Prüfe ob orphaned (nicht verwendet)
    $orphaned = $db->getOne("SELECT NOT EXISTS(SELECT 1 FROM customer WHERE taxzone_id = :id) as orphaned", ['id' => $id]);

    // Nur wenn neu ODER orphaned: Taxzone Charts neu schreiben
    if (!$tz['id'] || $orphaned['orphaned'] === 't' || $orphaned['orphaned'] === true) {
        // Taxzone Charts neu schreiben
        $db->execute("DELETE FROM taxzone_charts WHERE taxzone_id = :id", ['id' => $id]);

        foreach ($tz['taxzone_charts'] ?? [] as $tc) {
            // Beide Konten sind Pflicht!
            if (!$tc['income_accno_id'] || !$tc['expense_accno_id']) {
                resultInfo(false, 'VALIDATION_ERROR', 'Erlös- und Aufwandskonto sind Pflicht');
                return;
            }

            $db->execute(<<<SQL
                INSERT INTO taxzone_charts (buchungsgruppen_id, taxzone_id, income_accno_id, expense_accno_id)
                VALUES (:buchungsgruppen_id, :taxzone_id, :income_accno_id, :expense_accno_id)
            SQL, [
                'buchungsgruppen_id' => $tc['buchungsgruppen_id'],
                'taxzone_id' => $id,
                'income_accno_id' => $tc['income_accno_id'],
                'expense_accno_id' => $tc['expense_accno_id']
            ]);
        }
    }

    resultInfo(true, 'Gespeichert', ['id' => $id]);
}

function deleteTaxzone($data) {
    $db = DbhCompany::begin();

    // Prüfe ob verwendet (in customer, vendor, oder parts)
    $used = $db->getOne(<<<SQL
        SELECT EXISTS(
            SELECT 1 FROM customer WHERE taxzone_id = :id
            UNION
            SELECT 1 FROM vendor WHERE taxzone_id = :id
        ) as used
    SQL, ['id' => $data['id']]);

    if ($used['used'] === 't' || $used['used'] === true) {
        resultInfo(false, 'IN_USE', 'Wird verwendet');
        return;
    }

    $db->execute("DELETE FROM taxzone_charts WHERE taxzone_id = :id", ['id' => $data['id']]);
    $db->execute("DELETE FROM tax_zones WHERE id = :id", ['id' => $data['id']]);

    resultInfo(true, 'Gelöscht');
}

function reorderTaxzones($data) {
    $db = DbhCompany::begin();

    $taxzoneIds = $data['tzone_id'] ?? [];

    // Setze sortkey basierend auf Array-Position
    foreach ($taxzoneIds as $index => $id) {
        $db->execute(
            "UPDATE tax_zones SET sortkey = :sortkey WHERE id = :id",
            ['sortkey' => $index, 'id' => $id]
        );
    }

    resultInfo(true, 'Reihenfolge gespeichert');
}
