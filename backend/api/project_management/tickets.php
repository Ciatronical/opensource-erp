<?php
// backend/api/project_management/tickets.php

/**
 * Lädt alle Tickets mit Filtern
 *
 * @param array $data['status'] (optional) Status-Filter
 * @param array $data['priority'] (optional) Prioritäts-Filter
 * @param array $data['project_id'] (optional) Projekt-Filter
 * @testdata {}
 */
function getTickets($data) {
    $db = DbhCompany::begin();

    $where = "WHERE 1=1";
    $params = [];

    if (!empty($data['status'])) {
        $where .= " AND t.status = :status";
        $params['status'] = $data['status'];
    }
    if (!empty($data['priority'])) {
        $where .= " AND t.priority = :priority";
        $params['priority'] = $data['priority'];
    }
    if (!empty($data['project_id'])) {
        $where .= " AND t.project_id = :project_id";
        $params['project_id'] = $data['project_id'];
    }

    $tickets = $db->getAll(<<<SQL
        SELECT
            t.*,
            e_assigned.name AS assigned_to_name,
            e_reported.name AS reported_by_name,
            p.description AS project_name,
            p.projectnumber,
            rs.title AS requirement_spec_title,
            (SELECT COUNT(*) FROM ticket_comments tc WHERE tc.ticket_id = t.id) AS comment_count,
            COALESCE(
                (SELECT json_agg(json_build_object('id', tl.id, 'name', tl.name, 'color', tl.color))
                 FROM ticket_label_assignments tla
                 JOIN ticket_labels tl ON tl.id = tla.label_id
                 WHERE tla.ticket_id = t.id), '[]'
            ) AS labels
        FROM tickets t
        LEFT JOIN employee e_assigned ON e_assigned.id = t.assigned_to
        LEFT JOIN employee e_reported ON e_reported.id = t.reported_by
        LEFT JOIN project p ON p.id = t.project_id
        LEFT JOIN requirement_specs rs ON rs.id = t.requirement_spec_id
        $where
        ORDER BY
            CASE t.priority
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'low' THEN 4
            END,
            t.itime DESC
    SQL, $params);

    resultInfo(true, '', ['tickets' => $tickets ?: []]);
}

/**
 * Lädt ein einzelnes Ticket mit Kommentaren
 *
 * @param array $data['id'] Ticket-ID
 * @testdata {"id": 1}
 */
function getTicket($data) {
    $db = DbhCompany::begin();

    $ticket = $db->getOne(<<<SQL
        SELECT
            t.*,
            e_assigned.name AS assigned_to_name,
            e_reported.name AS reported_by_name,
            p.description AS project_name,
            rs.title AS requirement_spec_title
        FROM tickets t
        LEFT JOIN employee e_assigned ON e_assigned.id = t.assigned_to
        LEFT JOIN employee e_reported ON e_reported.id = t.reported_by
        LEFT JOIN project p ON p.id = t.project_id
        LEFT JOIN requirement_specs rs ON rs.id = t.requirement_spec_id
        WHERE t.id = :id
    SQL, ['id' => $data['id']]);

    if (!$ticket) {
        resultInfo(false, 'NOT_FOUND', 'Ticket nicht gefunden');
        return;
    }

    $comments = $db->getAll(<<<SQL
        SELECT tc.*, e.name AS employee_name
        FROM ticket_comments tc
        LEFT JOIN employee e ON e.id = tc.employee_id
        WHERE tc.ticket_id = :id
        ORDER BY tc.itime ASC
    SQL, ['id' => $data['id']]);

    $labels = $db->getAll(<<<SQL
        SELECT tl.*
        FROM ticket_label_assignments tla
        JOIN ticket_labels tl ON tl.id = tla.label_id
        WHERE tla.ticket_id = :id
        ORDER BY tl.position
    SQL, ['id' => $data['id']]);

    resultInfo(true, '', [
        'ticket' => $ticket,
        'comments' => $comments ?: [],
        'labels' => $labels ?: []
    ]);
}

/**
 * Speichert ein Ticket (neu oder bestehend)
 *
 * @param array $data['ticket'] Ticket-Daten
 * @testdata {"ticket": {"title": "Test-Ticket", "ticket_type": "task", "priority": "medium"}}
 */
function saveTicket($data) {
    $db = DbhCompany::begin();
    $ticket = $data['ticket'];

    if (empty($ticket['title'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Titel ist erforderlich');
        return;
    }

    if (!empty($ticket['id'])) {
        // Update
        $wasClosed = $db->getOne("SELECT status FROM tickets WHERE id = :id", ['id' => $ticket['id']]);
        $closedAt = null;
        if (in_array($ticket['status'] ?? '', ['done', 'closed']) && !in_array($wasClosed['status'], ['done', 'closed'])) {
            $closedAt = date('Y-m-d H:i:s');
        }

        $result = $db->getOne(<<<SQL
            UPDATE tickets SET
                title = :title,
                description = :description,
                ticket_type = :ticket_type,
                priority = :priority,
                status = :status,
                project_id = :project_id,
                requirement_spec_id = :requirement_spec_id,
                requirement_spec_item_id = :requirement_spec_item_id,
                assigned_to = :assigned_to,
                reported_by = :reported_by,
                estimated_hours = :estimated_hours,
                actual_hours = :actual_hours,
                due_date = :due_date,
                closed_at = COALESCE(:closed_at, closed_at),
                mtime = now()
            WHERE id = :id
            RETURNING id
        SQL, [
            'id' => $ticket['id'],
            'title' => $ticket['title'],
            'description' => $ticket['description'] ?? null,
            'ticket_type' => $ticket['ticket_type'] ?? 'task',
            'priority' => $ticket['priority'] ?? 'medium',
            'status' => $ticket['status'] ?? 'open',
            'project_id' => $ticket['project_id'] ?? null,
            'requirement_spec_id' => $ticket['requirement_spec_id'] ?? null,
            'requirement_spec_item_id' => $ticket['requirement_spec_item_id'] ?? null,
            'assigned_to' => $ticket['assigned_to'] ?? null,
            'reported_by' => $ticket['reported_by'] ?? null,
            'estimated_hours' => $ticket['estimated_hours'] ?? null,
            'actual_hours' => $ticket['actual_hours'] ?? 0,
            'due_date' => $ticket['due_date'] ?? null,
            'closed_at' => $closedAt,
        ]);

        $id = $result['id'];
    } else {
        // Nächste Ticketnummer
        $nextNum = $db->getOne("SELECT COALESCE(MAX(CAST(SUBSTRING(ticket_number FROM '[0-9]+') AS INTEGER)), 0) + 1 AS next_num FROM tickets");
        $ticketNumber = 'T-' . str_pad($nextNum['next_num'], 5, '0', STR_PAD_LEFT);

        $result = $db->getOne(<<<SQL
            INSERT INTO tickets (ticket_number, title, description, ticket_type, priority, status, project_id, requirement_spec_id, requirement_spec_item_id, assigned_to, reported_by, estimated_hours, due_date)
            VALUES (:ticket_number, :title, :description, :ticket_type, :priority, :status, :project_id, :requirement_spec_id, :requirement_spec_item_id, :assigned_to, :reported_by, :estimated_hours, :due_date)
            RETURNING id
        SQL, [
            'ticket_number' => $ticketNumber,
            'title' => $ticket['title'],
            'description' => $ticket['description'] ?? null,
            'ticket_type' => $ticket['ticket_type'] ?? 'task',
            'priority' => $ticket['priority'] ?? 'medium',
            'status' => $ticket['status'] ?? 'open',
            'project_id' => $ticket['project_id'] ?? null,
            'requirement_spec_id' => $ticket['requirement_spec_id'] ?? null,
            'requirement_spec_item_id' => $ticket['requirement_spec_item_id'] ?? null,
            'assigned_to' => $ticket['assigned_to'] ?? null,
            'reported_by' => $ticket['reported_by'] ?? null,
            'estimated_hours' => $ticket['estimated_hours'] ?? null,
            'due_date' => $ticket['due_date'] ?? null,
        ]);

        $id = $result['id'];
    }

    // Labels aktualisieren
    if (isset($ticket['label_ids'])) {
        $db->execute("DELETE FROM ticket_label_assignments WHERE ticket_id = :id", ['id' => $id]);
        foreach ($ticket['label_ids'] as $labelId) {
            $db->execute("INSERT INTO ticket_label_assignments (ticket_id, label_id) VALUES (:ticket_id, :label_id)", [
                'ticket_id' => $id,
                'label_id' => $labelId,
            ]);
        }
    }

    resultInfo(true, 'Gespeichert', ['id' => $id]);
}

/**
 * Aktualisiert nur den Status eines Tickets (für Kanban-Drag)
 *
 * @param array $data['id'] Ticket-ID
 * @param array $data['status'] Neuer Status
 * @testdata {"id": 1, "status": "in_progress"}
 */
function updateTicketStatus($data) {
    $db = DbhCompany::begin();

    $closedAt = null;
    if (in_array($data['status'], ['done', 'closed'])) {
        $wasClosed = $db->getOne("SELECT status FROM tickets WHERE id = :id", ['id' => $data['id']]);
        if (!in_array($wasClosed['status'], ['done', 'closed'])) {
            $closedAt = date('Y-m-d H:i:s');
        }
    }

    $db->execute(<<<SQL
        UPDATE tickets SET
            status = :status,
            closed_at = COALESCE(:closed_at, closed_at),
            mtime = now()
        WHERE id = :id
    SQL, [
        'id' => $data['id'],
        'status' => $data['status'],
        'closed_at' => $closedAt,
    ]);

    resultInfo(true, 'Status aktualisiert');
}

/**
 * Löscht ein Ticket mit allen Kommentaren und Labels
 *
 * @param array $data['id'] Ticket-ID
 * @testdata {"id": 999}
 */
function deleteTicket($data) {
    $db = DbhCompany::begin();

    $db->execute("DELETE FROM ticket_label_assignments WHERE ticket_id = :id", ['id' => $data['id']]);
    $db->execute("DELETE FROM ticket_comments WHERE ticket_id = :id", ['id' => $data['id']]);
    $db->execute("DELETE FROM tickets WHERE id = :id", ['id' => $data['id']]);

    resultInfo(true, 'Gelöscht');
}

/**
 * Fügt einen Kommentar zu einem Ticket hinzu
 *
 * @param array $data['ticket_id'] Ticket-ID
 * @param array $data['comment'] Kommentar-Text
 * @testdata {"ticket_id": 1, "comment": "Test-Kommentar"}
 */
function addTicketComment($data) {
    $db = DbhCompany::begin();

    if (empty($data['comment'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Kommentar ist erforderlich');
        return;
    }

    $result = $db->getOne(<<<SQL
        INSERT INTO ticket_comments (ticket_id, employee_id, comment)
        VALUES (:ticket_id, :employee_id, :comment)
        RETURNING id
    SQL, [
        'ticket_id' => $data['ticket_id'],
        'employee_id' => $data['employee_id'] ?? null,
        'comment' => $data['comment'],
    ]);

    resultInfo(true, 'Kommentar hinzugefügt', ['id' => $result['id']]);
}

/**
 * Löscht einen Kommentar
 *
 * @param array $data['id'] Kommentar-ID
 * @testdata {"id": 999}
 */
function deleteTicketComment($data) {
    $db = DbhCompany::begin();
    $db->execute("DELETE FROM ticket_comments WHERE id = :id", ['id' => $data['id']]);
    resultInfo(true, 'Gelöscht');
}

/**
 * Lädt alle Labels
 *
 * @testdata {}
 */
function getTicketLabels($data) {
    $db = DbhCompany::begin();
    $labels = $db->getAll("SELECT * FROM ticket_labels ORDER BY position, name");
    resultInfo(true, '', ['labels' => $labels ?: []]);
}

/**
 * Speichert ein Label (neu oder bestehend)
 *
 * @param array $data['label'] Label-Daten
 * @testdata {"label": {"name": "Frontend", "color": "#4CAF50"}}
 */
function saveTicketLabel($data) {
    $db = DbhCompany::begin();
    $label = $data['label'];

    if (empty($label['name'])) {
        resultInfo(false, 'VALIDATION_ERROR', 'Name ist erforderlich');
        return;
    }

    if (!empty($label['id'])) {
        $db->execute("UPDATE ticket_labels SET name = :name, color = :color, position = :position WHERE id = :id", [
            'id' => $label['id'],
            'name' => $label['name'],
            'color' => $label['color'] ?? '#1976D2',
            'position' => $label['position'] ?? 0,
        ]);
        resultInfo(true, 'Gespeichert', ['id' => $label['id']]);
    } else {
        $nextPos = $db->getOne("SELECT COALESCE(MAX(position), 0) + 1 AS next_pos FROM ticket_labels");
        $result = $db->getOne(<<<SQL
            INSERT INTO ticket_labels (name, color, position)
            VALUES (:name, :color, :position)
            RETURNING id
        SQL, [
            'name' => $label['name'],
            'color' => $label['color'] ?? '#1976D2',
            'position' => $nextPos['next_pos'],
        ]);
        resultInfo(true, 'Gespeichert', ['id' => $result['id']]);
    }
}

/**
 * Löscht ein Label
 *
 * @param array $data['id'] Label-ID
 * @testdata {"id": 999}
 */
function deleteTicketLabel($data) {
    $db = DbhCompany::begin();
    $db->execute("DELETE FROM ticket_label_assignments WHERE label_id = :id", ['id' => $data['id']]);
    $db->execute("DELETE FROM ticket_labels WHERE id = :id", ['id' => $data['id']]);
    resultInfo(true, 'Gelöscht');
}

/**
 * Lädt Stammdaten für Ticket-Formulare (Mitarbeiter, Projekte, Pflichtenhefte)
 *
 * @testdata {}
 */
function getTicketMasterData($data) {
    $db = DbhCompany::begin();

    $projects = $db->getAll("SELECT id, projectnumber, description FROM project WHERE active = true ORDER BY projectnumber");
    $specs = $db->getAll("SELECT id, title FROM requirement_specs WHERE working_copy_id IS NULL ORDER BY title");
    $labels = $db->getAll("SELECT * FROM ticket_labels ORDER BY position, name");

    resultInfo(true, '', [
        'projects' => $projects ?: [],
        'specs' => $specs ?: [],
        'labels' => $labels ?: [],
    ]);
}
