<?php
// backend/api/chat/chat.php
// Interner Mitarbeiter-Chat (1:1). Echtzeit-Zustellung laeuft ueber pg_notify
// ('chat_message') -> SSE-Server -> Chat-Panel im Browser des Empfaengers.
//
// Grundsatz: ein Ajax-Call = eine DB-Abfrage. Die Mitarbeiter-ID kommt aus dem
// Frontend-Store (session.logged_in_employee.id) und wird nicht erneut geholt.

/**
 * Uebersicht: alle Unterhaltungen des Mitarbeiters (mit letzter Nachricht und
 * Ungelesen-Zaehler) plus die Kollegenliste fuer neue Chats — in einer Abfrage.
 *
 * @param int $data['employee_id'] Angemeldeter Mitarbeiter (aus dem Store)
 * @testdata {"action": "getChatOverview", "employee_id": 1}
 */
function getChatOverview($data) {
    $employeeId = (int)($data['employee_id'] ?? 0);
    if ($employeeId <= 0) {
        resultInfo(false, 'NO_EMPLOYEE', 'Kein Mitarbeiter angemeldet');
        return;
    }

    $db = DbhCompany::begin();

    $query = <<<SQL
        WITH conv AS (
            SELECT c.id,
                   c.is_group,
                   c.title,
                   p.last_read_id,
                   p.muted,
                   (SELECT string_agg(COALESCE(NULLIF(TRIM(e.name), ''), e.login), ', ' ORDER BY e.name)
                      FROM chat_participants pp
                      JOIN employee e ON e.id = pp.employee_id
                     WHERE pp.conversation_id = c.id
                       AND pp.employee_id <> :employee_id) AS partner_names,
                   (SELECT COALESCE(json_agg(pp.employee_id), '[]'::json)
                      FROM chat_participants pp
                     WHERE pp.conversation_id = c.id
                       AND pp.employee_id <> :employee_id) AS partner_ids,
                   -- Niedrigster Lesestand der Gegenseite: daraus wird in der
                   -- Liste der Haken an der letzten eigenen Nachricht abgeleitet
                   (SELECT COALESCE(MIN(p2.last_read_id), 0)
                      FROM chat_participants p2
                     WHERE p2.conversation_id = c.id
                       AND p2.employee_id <> :employee_id) AS partner_read_id,
                   lm.id          AS last_message_id,
                   lm.message     AS last_message,
                   lm.employee_id AS last_employee_id,
                   lm.itime       AS last_itime,
                   (SELECT COUNT(*)
                      FROM chat_messages m
                     WHERE m.conversation_id = c.id
                       AND m.id > p.last_read_id
                       AND m.employee_id <> :employee_id
                       AND m.deleted = FALSE) AS unread
              FROM chat_participants p
              JOIN chat_conversations c ON c.id = p.conversation_id
              -- LATERAL statt Fensterfunktion: liefert genau die letzte Nachricht
              -- je Unterhaltung und nutzt dabei den Index (conversation_id, id DESC)
              LEFT JOIN LATERAL (
                  SELECT m.id, m.message, m.employee_id, m.itime
                    FROM chat_messages m
                   WHERE m.conversation_id = c.id
                     AND m.deleted = FALSE
                   ORDER BY m.id DESC
                   LIMIT 1
              ) lm ON TRUE
             WHERE p.employee_id = :employee_id
        )
        SELECT json_build_object(
            'conversations', COALESCE((
                SELECT json_agg(conv ORDER BY conv.last_itime DESC NULLS LAST) FROM conv
            ), '[]'::json),
            'employees', COALESCE((
                SELECT json_agg(emp ORDER BY emp.name)
                  FROM (
                      SELECT e.id, COALESCE(NULLIF(TRIM(e.name), ''), e.login) AS name, e.login
                        FROM employee e
                       WHERE e.deleted IS NOT TRUE
                         AND (e.enddate IS NULL OR e.enddate >= CURRENT_DATE)
                         AND e.id <> :employee_id
                  ) emp
            ), '[]'::json),
            'unread_total', COALESCE((SELECT SUM(conv.unread) FROM conv), 0)
        ) AS payload
    SQL;

    $row = $db->getOne($query, [':employee_id' => $employeeId]);
    resultInfo(true, '', json_decode($row['payload'], true));
}

/**
 * Nachrichten einer Unterhaltung laden und dabei als gelesen markieren.
 *
 * Das UPDATE steckt als CTE in derselben Abfrage: es dient gleichzeitig als
 * Berechtigungspruefung (wer nicht Teilnehmer ist, bekommt keine Zeile zurueck).
 * Der Trigger auf chat_participants meldet den neuen Lesestand per SSE an den
 * Absender (Gelesen-Haken) — ohne zusaetzlichen Call.
 *
 * @param int $data['conversation_id'] Unterhaltung
 * @param int $data['employee_id']     Angemeldeter Mitarbeiter (aus dem Store)
 * @param int $data['limit']           Maximale Anzahl Nachrichten (Standard 200, max 500)
 * @testdata {"action": "getChatMessages", "conversation_id": 1, "employee_id": 1, "limit": 200}
 */
function getChatMessages($data) {
    $employeeId     = (int)($data['employee_id'] ?? 0);
    $conversationId = (int)($data['conversation_id'] ?? 0);
    $limit          = (int)($data['limit'] ?? 200);
    if ($limit < 1)   $limit = 1;
    if ($limit > 500) $limit = 500;

    if ($employeeId <= 0 || $conversationId <= 0) {
        resultInfo(false, 'INVALID_INPUT', 'Mitarbeiter und Unterhaltung sind erforderlich');
        return;
    }

    $db = DbhCompany::begin();

    $query = <<<SQL
        WITH upd AS (
            UPDATE chat_participants p
               SET last_read_id = GREATEST(
                       p.last_read_id,
                       COALESCE((SELECT MAX(m.id) FROM chat_messages m
                                  WHERE m.conversation_id = p.conversation_id), 0))
             WHERE p.conversation_id = :conversation_id
               AND p.employee_id     = :employee_id
            RETURNING p.conversation_id
        )
        SELECT json_build_object(
            'conversation_id', :conversation_id::int,
            'messages', COALESCE((
                SELECT json_agg(msg ORDER BY msg.id)
                  FROM (
                      SELECT m.id,
                             m.employee_id,
                             COALESCE(NULLIF(TRIM(e.name), ''), e.login) AS employee_name,
                             m.message,
                             m.itime
                        FROM chat_messages m
                        LEFT JOIN employee e ON e.id = m.employee_id
                       WHERE m.conversation_id = :conversation_id
                         AND m.deleted = FALSE
                       ORDER BY m.id DESC
                       LIMIT :limit
                  ) msg
            ), '[]'::json),
            -- Niedrigster Lesestand der Gegenseite: ab dieser ID gilt "gelesen"
            'partner_read_id', COALESCE((
                SELECT MIN(p2.last_read_id)
                  FROM chat_participants p2
                 WHERE p2.conversation_id = :conversation_id
                   AND p2.employee_id <> :employee_id), 0),
            'partner_names', (
                SELECT string_agg(COALESCE(NULLIF(TRIM(e.name), ''), e.login), ', ' ORDER BY e.name)
                  FROM chat_participants p3
                  JOIN employee e ON e.id = p3.employee_id
                 WHERE p3.conversation_id = :conversation_id
                   AND p3.employee_id <> :employee_id)
        ) AS payload
         WHERE EXISTS (SELECT 1 FROM upd)
    SQL;

    $row = $db->getOne($query, [
        ':conversation_id' => $conversationId,
        ':employee_id'     => $employeeId,
        ':limit'           => $limit,
    ]);

    if ($row === false) {
        resultInfo(false, 'NOT_A_PARTICIPANT', 'Kein Zugriff auf diese Unterhaltung');
        return;
    }

    resultInfo(true, '', json_decode($row['payload'], true));
}

/**
 * Nachricht senden. Ohne conversation_id wird der 1:1-Chat mit to_employee_id
 * gesucht und bei Bedarf neu angelegt — alles in einer Abfrage.
 *
 * Der INSERT-Trigger auf chat_messages feuert pg_notify('chat_message'); beim
 * Empfaenger oeffnet sich dadurch sofort das Chatfenster.
 *
 * @param int    $data['employee_id']     Absender (aus dem Store)
 * @param int    $data['to_employee_id']  Empfaenger — nur beim Start eines neuen Chats
 * @param int    $data['conversation_id'] Bestehende Unterhaltung (Antwort)
 * @param string $data['message']         Nachrichtentext
 * @testdata {"action": "sendChatMessage", "employee_id": 1, "to_employee_id": 2, "message": "Hallo!"}
 */
function sendChatMessage($data) {
    $employeeId     = (int)($data['employee_id'] ?? 0);
    $toEmployeeId   = (int)($data['to_employee_id'] ?? 0);
    $conversationId = (int)($data['conversation_id'] ?? 0);
    $message        = trim((string)($data['message'] ?? ''));

    if ($employeeId <= 0) {
        resultInfo(false, 'NO_EMPLOYEE', 'Kein Mitarbeiter angemeldet');
        return;
    }
    if ($message === '') {
        resultInfo(false, 'INVALID_INPUT', 'Nachrichtentext ist erforderlich');
        return;
    }
    if ($conversationId <= 0 && $toEmployeeId <= 0) {
        resultInfo(false, 'INVALID_INPUT', 'Empfaenger oder Unterhaltung ist erforderlich');
        return;
    }
    if ($toEmployeeId === $employeeId) {
        resultInfo(false, 'INVALID_INPUT', 'Chat mit sich selbst ist nicht moeglich');
        return;
    }

    $db = DbhCompany::begin();

    $query = <<<SQL
        WITH existing AS (
            SELECT c.id
              FROM chat_conversations c
             WHERE (
                       -- Antwort in bestehender Unterhaltung (nur als Teilnehmer)
                       :conversation_id::int > 0
                   AND c.id = :conversation_id
                   AND EXISTS (SELECT 1 FROM chat_participants p
                                WHERE p.conversation_id = c.id AND p.employee_id = :employee_id)
                   )
                OR (
                       -- Neuer Chat: existiert bereits ein 1:1 mit dem Empfaenger?
                       :conversation_id::int = 0
                   AND c.is_group = FALSE
                   AND (SELECT COUNT(*) FROM chat_participants p WHERE p.conversation_id = c.id) = 2
                   AND EXISTS (SELECT 1 FROM chat_participants p
                                WHERE p.conversation_id = c.id AND p.employee_id = :employee_id)
                   AND EXISTS (SELECT 1 FROM chat_participants p
                                WHERE p.conversation_id = c.id AND p.employee_id = :to_employee_id)
                   )
             ORDER BY c.id
             LIMIT 1
        ),
        created AS (
            INSERT INTO chat_conversations (created_by, is_group)
            SELECT :employee_id::int, FALSE
             WHERE NOT EXISTS (SELECT 1 FROM existing)
               AND :to_employee_id::int > 0
            RETURNING id
        ),
        target AS (
            SELECT id FROM existing
            UNION ALL
            SELECT id FROM created
        ),
        parts AS (
            INSERT INTO chat_participants (conversation_id, employee_id)
            SELECT t.id, x
              FROM created t, unnest(ARRAY[:employee_id::int, :to_employee_id::int]) AS x
            ON CONFLICT (conversation_id, employee_id) DO NOTHING
            RETURNING conversation_id
        ),
        ins AS (
            INSERT INTO chat_messages (conversation_id, employee_id, message)
            SELECT t.id, :employee_id::int, :message::text FROM target t
            RETURNING id, conversation_id, employee_id, message, itime
        )
        SELECT json_build_object(
            'id',                  ins.id,
            'conversation_id',     ins.conversation_id,
            'employee_id',         ins.employee_id,
            'message',             ins.message,
            'itime',               ins.itime,
            'is_new_conversation', EXISTS (SELECT 1 FROM created)
        ) AS payload
          FROM ins
    SQL;

    $row = $db->getOne($query, [
        ':employee_id'     => $employeeId,
        ':to_employee_id'  => $toEmployeeId,
        ':conversation_id' => $conversationId,
        ':message'         => $message,
    ]);

    if ($row === false) {
        resultInfo(false, 'NOT_A_PARTICIPANT', 'Unterhaltung nicht gefunden oder kein Zugriff');
        return;
    }

    resultInfo(true, '', ['message' => json_decode($row['payload'], true)]);
}

/**
 * Unterhaltung als gelesen markieren (z. B. beim Schliessen des Chatfensters).
 *
 * @param int $data['conversation_id'] Unterhaltung
 * @param int $data['employee_id']     Angemeldeter Mitarbeiter (aus dem Store)
 * @testdata {"action": "markChatRead", "conversation_id": 1, "employee_id": 1}
 */
function markChatRead($data) {
    $employeeId     = (int)($data['employee_id'] ?? 0);
    $conversationId = (int)($data['conversation_id'] ?? 0);

    if ($employeeId <= 0 || $conversationId <= 0) {
        resultInfo(false, 'INVALID_INPUT', 'Mitarbeiter und Unterhaltung sind erforderlich');
        return;
    }

    $db = DbhCompany::begin();

    // GREATEST verhindert, dass ein Lesestand zurueckfaellt; bleibt der Wert
    // gleich, feuert der Trigger kein ueberfluessiges SSE-Event.
    $db->execute(
        "UPDATE chat_participants p
            SET last_read_id = GREATEST(
                    p.last_read_id,
                    COALESCE((SELECT MAX(m.id) FROM chat_messages m
                               WHERE m.conversation_id = p.conversation_id), 0))
          WHERE p.conversation_id = :conversation_id
            AND p.employee_id     = :employee_id",
        [':conversation_id' => $conversationId, ':employee_id' => $employeeId]
    );

    resultInfo(true, '');
}

/**
 * Eigene Nachricht loeschen (Soft-Delete, bleibt fuer die Historie erhalten).
 *
 * @param int $data['id']          Nachricht
 * @param int $data['employee_id'] Angemeldeter Mitarbeiter (aus dem Store)
 * @testdata {"action": "deleteChatMessage", "id": 1, "employee_id": 1}
 */
function deleteChatMessage($data) {
    $employeeId = (int)($data['employee_id'] ?? 0);
    $id         = (int)($data['id'] ?? 0);

    if ($employeeId <= 0 || $id <= 0) {
        resultInfo(false, 'INVALID_INPUT', 'Mitarbeiter und Nachricht sind erforderlich');
        return;
    }

    $db = DbhCompany::begin();

    $db->execute(
        "UPDATE chat_messages
            SET deleted = TRUE
          WHERE id = :id
            AND employee_id = :employee_id",
        [':id' => $id, ':employee_id' => $employeeId]
    );

    resultInfo(true, '');
}
