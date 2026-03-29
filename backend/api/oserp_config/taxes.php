<?php
// opensource-erp/backend/api/oserp_config/taxes.php

function saveTax($data) {
    $db = DbhCompany::begin();
    $tax = $data['data'];
    
    // Validierung
    if (empty($tax['taxkey']) && $tax['taxkey'] !== 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Steuerschlüssel fehlt');
        return;
    }
    
    if (empty($tax['taxdescription'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Beschreibung fehlt');
        return;
    }
    
    if (!isset($tax['rate'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Steuersatz fehlt');
        return;
    }
    
    // Spezialregel: taxkey 0 nur für rate 0
    if ($tax['taxkey'] == 0 && $tax['rate'] > 0) {
        resultInfo(false, 'VALIDATION_ERROR', 'Steuerschlüssel 0 ist für Steuersatz 0 reserviert');
        return;
    }
    
    // Rate muss zwischen 0 und 100 sein
    if ($tax['rate'] < 0 || $tax['rate'] >= 100) {
        resultInfo(false, 'VALIDATION_ERROR', 'Steuersatz muss zwischen 0 und 100 sein');
        return;
    }
    
    // Rate zwischen 0 und 0.99 nicht erlaubt
    if ($tax['rate'] > 0 && $tax['rate'] <= 0.99) {
        resultInfo(false, 'VALIDATION_ERROR', 'Steuersatz muss zwischen 0 und 100 sein');
        return;
    }
    
    // UPDATE existierende Steuer
    if (!empty($tax['id'])) {
        $result = $db->getOne(<<<SQL
            UPDATE tax 
            SET taxkey = :taxkey,
                taxdescription = :taxdescription,
                rate = :rate,
                chart_id = :chart_id,
                chart_categories = :chart_categories,
                skonto_sales_chart_id = :skonto_sales_chart_id,
                skonto_purchase_chart_id = :skonto_purchase_chart_id,
                reverse_charge_chart_id = :reverse_charge_chart_id
            WHERE id = :id
            RETURNING id
        SQL, [
            'id' => $tax['id'],
            'taxkey' => $tax['taxkey'],
            'taxdescription' => $tax['taxdescription'],
            'rate' => $tax['rate'] / 100, // Convert from percentage to decimal
            'chart_id' => $tax['chart_id'] ?? null,
            'chart_categories' => $tax['chart_categories'] ?? '',
            'skonto_sales_chart_id' => $tax['skonto_sales_chart_id'] ?? null,
            'skonto_purchase_chart_id' => $tax['skonto_purchase_chart_id'] ?? null,
            'reverse_charge_chart_id' => $tax['reverse_charge_chart_id'] ?? null
        ]);
        
        $id = $result['id'];
    } else {
        // INSERT neue Steuer
        $result = $db->getOne(<<<SQL
            INSERT INTO tax (taxkey, taxdescription, rate, chart_id, chart_categories, skonto_sales_chart_id, skonto_purchase_chart_id, reverse_charge_chart_id)
            VALUES (:taxkey, :taxdescription, :rate, :chart_id, :chart_categories, :skonto_sales_chart_id, :skonto_purchase_chart_id, :reverse_charge_chart_id)
            RETURNING id
        SQL, [
            'taxkey' => $tax['taxkey'],
            'taxdescription' => $tax['taxdescription'],
            'rate' => $tax['rate'] / 100, // Convert from percentage to decimal
            'chart_id' => $tax['chart_id'] ?? null,
            'chart_categories' => $tax['chart_categories'] ?? '',
            'skonto_sales_chart_id' => $tax['skonto_sales_chart_id'] ?? null,
            'skonto_purchase_chart_id' => $tax['skonto_purchase_chart_id'] ?? null,
            'reverse_charge_chart_id' => $tax['reverse_charge_chart_id'] ?? null
        ]);
        
        $id = $result['id'];
    }
    
    // Speichere Übersetzungen in generic_translations
    if (!empty($tax['translations'])) {
        // Lösche alte Übersetzungen
        $db->execute(<<<SQL
            DELETE FROM generic_translations 
            WHERE translation_type = 'SL::DB::Tax/taxdescription' 
            AND translation_id = :id
        SQL, ['id' => $id]);
        
        // Füge neue Übersetzungen ein
        foreach ($tax['translations'] as $language_id => $translation) {
            if (!empty($translation)) {
                $db->execute(<<<SQL
                    INSERT INTO generic_translations (translation_type, translation_id, language_id, translation)
                    VALUES ('SL::DB::Tax/taxdescription', :translation_id, :language_id, :translation)
                SQL, [
                    'translation_id' => $id,
                    'language_id' => $language_id,
                    'translation' => $translation
                ]);
            }
        }
    }
    
    resultInfo(true, 'Gespeichert', ['id' => $id]);
}

function deleteTax($data) {
    $db = DbhCompany::begin();
    
    // Prüfe ob verwendet (in acc_trans, invoice, taxkeys)
    $used = $db->getOne(<<<SQL
        SELECT EXISTS(
            SELECT 1 FROM acc_trans WHERE tax_id = :id
            UNION
            SELECT 1 FROM invoice WHERE tax_id = :id
            UNION
            SELECT 1 FROM taxkeys WHERE tax_id = :id
            LIMIT 1
        ) as used
    SQL, ['id' => $data['id']]);
    
    if ($used['used'] === 't' || $used['used'] === true) {
        resultInfo(false, 'IN_USE', 'Wird verwendet');
        return;
    }
    
    // Lösche Übersetzungen
    $db->execute(<<<SQL
        DELETE FROM generic_translations 
        WHERE translation_type = 'SL::DB::Tax/taxdescription' 
        AND translation_id = :id
    SQL, ['id' => $data['id']]);
    
    // Lösche Steuer
    $db->execute("DELETE FROM tax WHERE id = :id", ['id' => $data['id']]);
    
    resultInfo(true, 'Gelöscht');
}
