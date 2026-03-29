<?php
function saveCrmDefaults($data) {
    include_once __DIR__ . '/../features.php';

    $mandant = DbhCompany::begin();

    //Alternative mit with:
    $keys = array_keys($data['config']);
    $values = array_values($data['config']);
    $params = array_merge($keys, $values);

    $placeholders = implode(',', array_fill(0, count($data['config']), '?'));
    $sql = "
        WITH new_data AS (
            SELECT 
                unnest(ARRAY[{$placeholders}]) as key,
                unnest(ARRAY[{$placeholders}]) as value
        )
        INSERT INTO defaults_oserp (key, value)
        SELECT key, value FROM new_data
        ON CONFLICT (key) 
        DO UPDATE SET 
            value = EXCLUDED.value,
            mtime = now()
    ";

    $mandant->execute($sql, $params);
    resultInfo(true);
}