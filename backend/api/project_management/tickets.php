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
            (SELECT COUNT(*) FROM ticket_attachments ta WHERE ta.ticket_id = t.id) AS attachment_count,
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
 * Lädt ein einzelnes Ticket mit Kommentaren, Labels und Anhängen
 *
 * @param array $data['id'] Ticket-ID
 * @testdata {"id": 1}
 */
function getTicket($data) {
    $db = DbhCompany::begin();

    $row = $db->getOne(<<<SQL
        SELECT json_build_object(
            'ticket', to_jsonb(t) || jsonb_build_object(
                'assigned_to_name', e_assigned.name,
                'reported_by_name', e_reported.name,
                'project_name', p.description,
                'requirement_spec_title', rs.title
            ),
            'comments', COALESCE((
                SELECT json_agg(to_jsonb(tc) || jsonb_build_object('employee_name', e.name) ORDER BY tc.itime)
                FROM ticket_comments tc
                LEFT JOIN employee e ON e.id = tc.employee_id
                WHERE tc.ticket_id = t.id
            ), '[]'),
            'labels', COALESCE((
                SELECT json_agg(tl ORDER BY tl.position)
                FROM ticket_label_assignments tla
                JOIN ticket_labels tl ON tl.id = tla.label_id
                WHERE tla.ticket_id = t.id
            ), '[]'),
            'attachments', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', ta.id, 'comment_id', ta.comment_id, 'filename', ta.filename,
                    'mime_type', ta.mime_type, 'file_size', ta.file_size,
                    'employee_name', e.name, 'itime', ta.itime
                ) ORDER BY ta.itime)
                FROM ticket_attachments ta
                LEFT JOIN employee e ON e.id = ta.employee_id
                WHERE ta.ticket_id = t.id
            ), '[]')
        ) AS result
        FROM tickets t
        LEFT JOIN employee e_assigned ON e_assigned.id = t.assigned_to
        LEFT JOIN employee e_reported ON e_reported.id = t.reported_by
        LEFT JOIN project p ON p.id = t.project_id
        LEFT JOIN requirement_specs rs ON rs.id = t.requirement_spec_id
        WHERE t.id = :id
    SQL, ['id' => intval($data['id'] ?? 0)]);

    if (!$row) {
        resultInfo(false, 'NOT_FOUND', 'Ticket nicht gefunden');
        return;
    }

    resultInfo(true, '', json_decode($row['result'], true));
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
 * Löscht ein oder mehrere Tickets mit allen Kommentaren, Labels und Anhängen
 *
 * @param array $data['ids'] Ticket-IDs
 * @testdata {"ids": [999]}
 */
function deleteTickets($data) {
    $db = DbhCompany::begin();
    $ids = array_values(array_filter(array_map('intval', (array)($data['ids'] ?? []))));
    if (!$ids) {
        resultInfo(false, 'VALIDATION_ERROR', 'Keine Tickets ausgewählt');
        return;
    }

    // Ein Statement: die Neben-CTEs laufen mit, auch ohne dass jemand sie liest
    $row = $db->getOne(<<<SQL
        WITH ids AS (SELECT unnest(:ids::int[]) AS id),
        anhaenge AS (DELETE FROM ticket_attachments WHERE ticket_id IN (SELECT id FROM ids)),
        kommentare AS (DELETE FROM ticket_comments WHERE ticket_id IN (SELECT id FROM ids)),
        labels AS (DELETE FROM ticket_label_assignments WHERE ticket_id IN (SELECT id FROM ids)),
        geloescht AS (DELETE FROM tickets WHERE id IN (SELECT id FROM ids) RETURNING id)
        SELECT COALESCE(json_agg(id), '[]') AS ids FROM geloescht
    SQL, ['ids' => '{'.implode(',', $ids).'}']);

    $geloescht = json_decode($row['ids'], true);
    foreach ($geloescht as $id) {
        ticketAttachmentDirRemove($id);
    }

    resultInfo(true, 'Gelöscht', ['ids' => $geloescht]);
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
 * Löscht einen Kommentar samt seiner Anhänge
 *
 * @param array $data['id'] Kommentar-ID
 * @testdata {"id": 999}
 */
function deleteTicketComment($data) {
    $db = DbhCompany::begin();
    $dateien = $db->getAll(<<<SQL
        WITH kommentar AS (DELETE FROM ticket_comments WHERE id = :id RETURNING id)
        DELETE FROM ticket_attachments WHERE comment_id IN (SELECT id FROM kommentar)
        RETURNING stored_path
    SQL, ['id' => intval($data['id'] ?? 0)]);

    foreach ($dateien as $datei) {
        ticketAttachmentFileRemove($datei['stored_path']);
    }
    resultInfo(true, 'Gelöscht');
}

// =========================================================================
// Anhänge (Screenshots, Dateien)
// =========================================================================

/** Verzeichnis der Anhänge eines Tickets, relativ zum Datenverzeichnis */
function ticketAttachmentDir(int $ticketId): string {
    return 'tickets/'.$ticketId;
}

/** Entfernt eine abgelegte Datei; Pfade außerhalb des Datenverzeichnisses bleiben unberührt */
function ticketAttachmentFileRemove(string $relativ): void {
    $basis = realpath(fmDataDir());
    $datei = realpath(fmDataDir().'/'.$relativ);
    if ($basis && $datei && str_starts_with($datei, $basis.'/') && is_file($datei)) {
        @unlink($datei);
    }
}

/** Entfernt das Anhang-Verzeichnis eines gelöschten Tickets */
function ticketAttachmentDirRemove(int $ticketId): void {
    $verzeichnis = fmDataDir().'/'.ticketAttachmentDir($ticketId);
    if (!is_dir($verzeichnis)) return;
    foreach (glob($verzeichnis.'/*') ?: [] as $datei) {
        if (is_file($datei)) @unlink($datei);
    }
    @rmdir($verzeichnis);
}

/**
 * Hängt eine Datei (Screenshot, PDF, …) an ein Ticket oder einen Kommentar
 *
 * @param int $data['ticket_id'] Ticket-ID
 * @param int $data['comment_id'] Kommentar-ID (optional, sonst hängt die Datei am Ticket)
 * @param string $data['filename'] Original-Dateiname
 * @param string $data['file_base64'] Dateiinhalt Base64-kodiert
 * @testdata {"ticket_id": 1, "filename": "test.txt", "file_base64": "SGFsbG8="}
 */
function uploadTicketAttachment($data) {
    $ticketId = intval($data['ticket_id'] ?? 0);
    $inhalt = base64_decode($data['file_base64'] ?? '', true);
    if (!$ticketId || $inhalt === false || $inhalt === '') {
        throw new ApiError('VALIDATION_ERROR', 'Ticket und Datei sind erforderlich');
    }
    if (strlen($inhalt) > FM_MAX_FILESIZE) {
        throw new ApiError('FILE_TOO_LARGE', 'Die Datei ist größer als '.fmFormatSize(FM_MAX_FILESIZE));
    }

    $name = trim(basename(str_replace('\\', '/', (string)($data['filename'] ?? '')))) ?: 'datei';
    $endung = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $relativ = ticketAttachmentDir($ticketId).'/'.bin2hex(random_bytes(12))
        .(preg_match('/^[a-z0-9]{1,10}$/', $endung) ? '.'.$endung : '');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($inhalt) ?: fmGetMimeType($name);

    // Erst die Zeile, dann die Datei: scheitert etwas, bleibt keine verwaiste
    // Datei liegen. Nur an ein vorhandenes Ticket und einen Kommentar dieses Tickets.
    $db = DbhCompany::begin();
    $row = $db->getOne(<<<SQL
        WITH neu AS (
            INSERT INTO ticket_attachments (ticket_id, comment_id, filename, mime_type, file_size, stored_path, employee_id)
            SELECT t.id, tc.id, :filename, :mime_type, :file_size, :stored_path, :employee_id
            FROM tickets t
            LEFT JOIN ticket_comments tc ON tc.id = :comment_id AND tc.ticket_id = t.id
            WHERE t.id = :ticket_id
            RETURNING *
        )
        SELECT json_build_object(
            'id', n.id, 'comment_id', n.comment_id, 'filename', n.filename,
            'mime_type', n.mime_type, 'file_size', n.file_size,
            'employee_name', e.name, 'itime', n.itime
        ) AS attachment
        FROM neu n
        LEFT JOIN employee e ON e.id = n.employee_id
    SQL, [
        'ticket_id'   => $ticketId,
        'comment_id'  => intval($data['comment_id'] ?? 0),
        'filename'    => $name,
        'mime_type'   => $mime,
        'file_size'   => strlen($inhalt),
        'stored_path' => $relativ,
        'employee_id' => mitarbeiterId($data),
    ]);

    if (!$row) {
        resultInfo(false, 'NOT_FOUND', 'Ticket nicht gefunden');
        return;
    }
    $anhang = json_decode($row['attachment'], true);

    fmMkdir(fmDataDir().'/'.ticketAttachmentDir($ticketId));
    if (file_put_contents(fmDataDir().'/'.$relativ, $inhalt) === false) {
        $db->execute("DELETE FROM ticket_attachments WHERE id = :id", ['id' => $anhang['id']]);
        throw new ApiError('FILE_WRITE_ERROR', 'Die Datei konnte nicht gespeichert werden');
    }
    resultInfo(true, '', ['attachment' => $anhang]);
}

/**
 * Liefert einen Anhang zum Anzeigen (Bilder, PDF) oder Herunterladen
 *
 * @param int $data['id'] Anhang-ID
 * @param int $data['download'] 1 = als Download statt zur Anzeige
 * @testdata {"id": 1}
 */
function getTicketAttachment($data) {
    $db = DbhCompany::begin();
    $anhang = $db->getOne(
        "SELECT filename, mime_type, stored_path FROM ticket_attachments WHERE id = :id",
        ['id' => intval($data['id'] ?? 0)]
    );
    $basis = realpath(fmDataDir());
    $datei = $anhang ? realpath(fmDataDir().'/'.$anhang['stored_path']) : false;
    if (!$datei || !str_starts_with($datei, $basis.'/') || !is_file($datei)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Datei nicht gefunden';
        exit;
    }

    // Im Browser nur, was dort kein Skript ausführen kann; SVG, HTML & Co. als Download
    $anzeigbar = preg_match('#^(image/(png|jpe?g|gif|webp|bmp)|application/pdf|text/plain|video/(mp4|webm))$#', $anhang['mime_type']);
    $art = (!empty($data['download']) || !$anzeigbar) ? 'attachment' : 'inline';

    header('Content-Type: '.$anhang['mime_type']);
    header('Content-Length: '.filesize($datei));
    header('Content-Disposition: '.$art.'; filename="'.str_replace(['"', "\r", "\n"], '', $anhang['filename']).'"; filename*=UTF-8\'\''.rawurlencode($anhang['filename']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($datei);
    exit;
}

/**
 * Löscht einen Anhang
 *
 * @param int $data['id'] Anhang-ID
 * @testdata {"id": 999}
 */
function deleteTicketAttachment($data) {
    $db = DbhCompany::begin();
    $row = $db->getOne(
        "DELETE FROM ticket_attachments WHERE id = :id RETURNING stored_path",
        ['id' => intval($data['id'] ?? 0)]
    );
    if ($row) {
        ticketAttachmentFileRemove($row['stored_path']);
    }
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
