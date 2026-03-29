<?php
// backend/api/project_management/requirement_specs.php

/**
 * Lädt alle Pflichtenhefte mit Kunden- und Projektinfo
 *
 * @testdata {}
 */
function getRequirementSpecs($data) {
    $db = DbhCompany::begin();

    $specs = $db->getAll(<<<SQL
        SELECT
            rs.id,
            rs.title,
            rs.hourly_rate,
            rs.time_estimation,
            rs.is_template,
            rs.itime,
            rs.mtime,
            rst.description AS type_name,
            rss.name AS status_name,
            rss.description AS status_description,
            c.name AS customer_name,
            c.id AS customer_id,
            p.projectnumber,
            p.description AS project_name,
            p.id AS project_id,
            (SELECT COUNT(*) FROM requirement_spec_items rsi WHERE rsi.requirement_spec_id = rs.id) AS item_count,
            (SELECT COALESCE(SUM(rsi.time_estimation), 0) FROM requirement_spec_items rsi WHERE rsi.requirement_spec_id = rs.id) AS total_hours
        FROM requirement_specs rs
        LEFT JOIN requirement_spec_types rst ON rst.id = rs.type_id
        LEFT JOIN requirement_spec_statuses rss ON rss.id = rs.status_id
        LEFT JOIN customer c ON c.id = rs.customer_id
        LEFT JOIN project p ON p.id = rs.project_id
        WHERE rs.working_copy_id IS NULL
        ORDER BY rs.itime DESC
    SQL);

    resultInfo(true, '', ['specs' => $specs ?: []]);
}

/**
 * Lädt ein einzelnes Pflichtenheft mit allen Positionen als Baum
 *
 * @param array $data['id'] Pflichtenheft-ID
 * @testdata {"id": 1}
 */
function getRequirementSpec($data) {
    $db = DbhCompany::begin();

    $spec = $db->getOne(<<<SQL
        SELECT
            rs.*,
            rst.description AS type_name,
            rss.name AS status_name,
            rss.description AS status_description,
            c.name AS customer_name,
            p.projectnumber,
            p.description AS project_name
        FROM requirement_specs rs
        LEFT JOIN requirement_spec_types rst ON rst.id = rs.type_id
        LEFT JOIN requirement_spec_statuses rss ON rss.id = rs.status_id
        LEFT JOIN customer c ON c.id = rs.customer_id
        LEFT JOIN project p ON p.id = rs.project_id
        WHERE rs.id = :id
    SQL, ['id' => $data['id']]);

    if (!$spec) {
        resultInfo(false, 'NOT_FOUND', 'Pflichtenheft nicht gefunden');
        return;
    }

    // Positionen hierarchisch laden
    $items = $db->getAll(<<<SQL
        SELECT
            rsi.*,
            rsc.description AS complexity_name,
            rsr.description AS risk_name,
            rsas.name AS acceptance_status_name
        FROM requirement_spec_items rsi
        LEFT JOIN requirement_spec_complexities rsc ON rsc.id = rsi.complexity_id
        LEFT JOIN requirement_spec_risks rsr ON rsr.id = rsi.risk_id
        LEFT JOIN requirement_spec_acceptance_statuses rsas ON rsas.id = rsi.acceptance_status_id
        WHERE rsi.requirement_spec_id = :id
        ORDER BY rsi.position
    SQL, ['id' => $data['id']]);

    // Textblöcke laden
    $textBlocks = $db->getAll(<<<SQL
        SELECT * FROM requirement_spec_text_blocks
        WHERE requirement_spec_id = :id
        ORDER BY position
    SQL, ['id' => $data['id']]);

    resultInfo(true, '', [
        'spec' => $spec,
        'items' => $items ?: [],
        'textBlocks' => $textBlocks ?: []
    ]);
}

/**
 * Speichert ein Pflichtenheft (neu oder bestehend)
 *
 * @param array $data['spec'] Pflichtenheft-Daten
 * @testdata {"spec": {"title": "Test-Pflichtenheft", "type_id": 1, "status_id": 1}}
 */
function saveRequirementSpec($data) {
    $db = DbhCompany::begin();
    $spec = $data['spec'];

    if (empty($spec['title'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Titel ist erforderlich');
        return;
    }

    $isTemplate = !empty($spec['is_template']);

    // Pflichtfelder prüfen (CHECK constraint: is_template OR (type_id AND status_id AND customer_id))
    if (!$isTemplate) {
        if (empty($spec['type_id'])) {
            resultInfo(false, 'VALIDATION_ERROR', 'Typ ist erforderlich');
            return;
        }
        if (empty($spec['status_id'])) {
            resultInfo(false, 'VALIDATION_ERROR', 'Status ist erforderlich');
            return;
        }
        if (empty($spec['customer_id'])) {
            resultInfo(false, 'VALIDATION_ERROR', 'Kunde ist erforderlich');
            return;
        }
    }

    if (!empty($spec['id'])) {
        $result = $db->getOne(<<<SQL
            UPDATE requirement_specs SET
                title = :title,
                type_id = :type_id,
                status_id = :status_id,
                customer_id = :customer_id,
                project_id = :project_id,
                hourly_rate = :hourly_rate,
                time_estimation = :time_estimation,
                mtime = now()
            WHERE id = :id
            RETURNING id
        SQL, [
            'id' => $spec['id'],
            'title' => $spec['title'],
            'type_id' => $spec['type_id'] ?? null,
            'status_id' => $spec['status_id'] ?? null,
            'customer_id' => $spec['customer_id'] ?? null,
            'project_id' => $spec['project_id'] ?? null,
            'hourly_rate' => $spec['hourly_rate'] ?? 0,
            'time_estimation' => $spec['time_estimation'] ?? 0,
        ]);
    } else {
        // Nächste Sektionsnummer ermitteln
        $result = $db->getOne(<<<SQL
            INSERT INTO requirement_specs (title, type_id, status_id, customer_id, project_id, hourly_rate, time_estimation, previous_section_number, previous_fb_number)
            VALUES (:title, :type_id, :status_id, :customer_id, :project_id, :hourly_rate, :time_estimation, 0, 0)
            RETURNING id
        SQL, [
            'title' => $spec['title'],
            'type_id' => $spec['type_id'] ?? null,
            'status_id' => $spec['status_id'] ?? null,
            'customer_id' => $spec['customer_id'] ?? null,
            'project_id' => $spec['project_id'] ?? null,
            'hourly_rate' => $spec['hourly_rate'] ?? 0,
            'time_estimation' => $spec['time_estimation'] ?? 0,
        ]);
    }

    resultInfo(true, 'Gespeichert', ['id' => $result['id']]);
}

/**
 * Löscht ein Pflichtenheft und alle zugehörigen Daten
 *
 * @param array $data['id'] Pflichtenheft-ID
 * @testdata {"id": 999}
 */
function deleteRequirementSpec($data) {
    $db = DbhCompany::begin();

    // Abhängige Daten löschen
    $db->execute("DELETE FROM requirement_spec_item_dependencies WHERE depending_item_id IN (SELECT id FROM requirement_spec_items WHERE requirement_spec_id = :id) OR depended_item_id IN (SELECT id FROM requirement_spec_items WHERE requirement_spec_id = :id)", ['id' => $data['id']]);
    $db->execute("DELETE FROM requirement_spec_items WHERE requirement_spec_id = :id", ['id' => $data['id']]);
    $db->execute("DELETE FROM requirement_spec_text_blocks WHERE requirement_spec_id = :id", ['id' => $data['id']]);
    $db->execute("DELETE FROM requirement_spec_orders WHERE requirement_spec_id = :id", ['id' => $data['id']]);
    $db->execute("DELETE FROM requirement_spec_parts WHERE requirement_spec_id = :id", ['id' => $data['id']]);
    $db->execute("DELETE FROM requirement_spec_versions WHERE requirement_spec_id = :id", ['id' => $data['id']]);
    $db->execute("DELETE FROM requirement_specs WHERE id = :id", ['id' => $data['id']]);

    resultInfo(true, 'Gelöscht');
}

/**
 * Speichert eine Pflichtenheft-Position (Abschnitt oder Funktionsblock)
 *
 * @param array $data['item'] Position-Daten
 * @testdata {"item": {"requirement_spec_id": 1, "item_type": "section", "title": "Test-Abschnitt", "position": 1, "fb_number": "A01"}}
 */
function saveRequirementSpecItem($data) {
    $db = DbhCompany::begin();
    $item = $data['item'];

    if (empty($item['title'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Titel ist erforderlich');
        return;
    }

    if (!empty($item['id'])) {
        $result = $db->getOne(<<<SQL
            UPDATE requirement_spec_items SET
                title = :title,
                description = :description,
                item_type = :item_type,
                parent_id = :parent_id,
                position = :position,
                complexity_id = :complexity_id,
                risk_id = :risk_id,
                time_estimation = :time_estimation,
                is_flagged = :is_flagged,
                mtime = now()
            WHERE id = :id
            RETURNING id
        SQL, [
            'id' => $item['id'],
            'title' => $item['title'],
            'description' => $item['description'] ?? null,
            'item_type' => $item['item_type'] ?? 'section',
            'parent_id' => $item['parent_id'] ?? null,
            'position' => $item['position'] ?? 0,
            'complexity_id' => $item['complexity_id'] ?? null,
            'risk_id' => $item['risk_id'] ?? null,
            'time_estimation' => $item['time_estimation'] ?? 0,
            'is_flagged' => $item['is_flagged'] ?? false,
        ]);
    } else {
        // Nächste FB-Nummer
        $nextPos = $db->getOne("SELECT COALESCE(MAX(position), 0) + 1 AS next_pos FROM requirement_spec_items WHERE requirement_spec_id = :spec_id AND COALESCE(parent_id, 0) = COALESCE(:parent_id, 0)", [
            'spec_id' => $item['requirement_spec_id'],
            'parent_id' => $item['parent_id'] ?? null,
        ]);

        $result = $db->getOne(<<<SQL
            INSERT INTO requirement_spec_items (requirement_spec_id, item_type, parent_id, position, fb_number, title, description, complexity_id, risk_id, time_estimation, is_flagged)
            VALUES (:requirement_spec_id, :item_type, :parent_id, :position, :fb_number, :title, :description, :complexity_id, :risk_id, :time_estimation, :is_flagged)
            RETURNING id
        SQL, [
            'requirement_spec_id' => $item['requirement_spec_id'],
            'item_type' => $item['item_type'] ?? 'section',
            'parent_id' => $item['parent_id'] ?? null,
            'position' => $nextPos['next_pos'],
            'fb_number' => $item['fb_number'] ?? ('FB' . str_pad($nextPos['next_pos'], 3, '0', STR_PAD_LEFT)),
            'title' => $item['title'],
            'description' => $item['description'] ?? null,
            'complexity_id' => $item['complexity_id'] ?? null,
            'risk_id' => $item['risk_id'] ?? null,
            'time_estimation' => $item['time_estimation'] ?? 0,
            'is_flagged' => $item['is_flagged'] ?? false,
        ]);
    }

    resultInfo(true, 'Gespeichert', ['id' => $result['id']]);
}

/**
 * Löscht eine Pflichtenheft-Position
 *
 * @param array $data['id'] Position-ID
 * @testdata {"id": 999}
 */
function deleteRequirementSpecItem($data) {
    $db = DbhCompany::begin();

    // Abhängigkeiten löschen
    $db->execute("DELETE FROM requirement_spec_item_dependencies WHERE depending_item_id = :id OR depended_item_id = :id", ['id' => $data['id']]);
    // Kinder löschen
    $db->execute("DELETE FROM requirement_spec_item_dependencies WHERE depending_item_id IN (SELECT id FROM requirement_spec_items WHERE parent_id = :id) OR depended_item_id IN (SELECT id FROM requirement_spec_items WHERE parent_id = :id)", ['id' => $data['id']]);
    $db->execute("DELETE FROM requirement_spec_items WHERE parent_id = :id", ['id' => $data['id']]);
    $db->execute("DELETE FROM requirement_spec_items WHERE id = :id", ['id' => $data['id']]);

    resultInfo(true, 'Gelöscht');
}

/**
 * Lädt Pflichtenheft-Stammdaten (Typen, Status, Komplexitäten, Risiken)
 *
 * @testdata {}
 */
function getRequirementSpecMasterData($data) {
    $db = DbhCompany::begin();

    $types = $db->getAll("SELECT * FROM requirement_spec_types ORDER BY position");
    $statuses = $db->getAll("SELECT * FROM requirement_spec_statuses ORDER BY position");
    $complexities = $db->getAll("SELECT * FROM requirement_spec_complexities ORDER BY position");
    $risks = $db->getAll("SELECT * FROM requirement_spec_risks ORDER BY position");
    $acceptanceStatuses = $db->getAll("SELECT * FROM requirement_spec_acceptance_statuses ORDER BY position");
    $customers = $db->getAll("SELECT id, name FROM customer ORDER BY name");
    $projects = $db->getAll("SELECT id, projectnumber, description FROM project WHERE active = true ORDER BY projectnumber");

    resultInfo(true, '', [
        'types' => $types ?: [],
        'statuses' => $statuses ?: [],
        'complexities' => $complexities ?: [],
        'risks' => $risks ?: [],
        'acceptanceStatuses' => $acceptanceStatuses ?: [],
        'customers' => $customers ?: [],
        'projects' => $projects ?: [],
    ]);
}
