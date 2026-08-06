<?php
// backend/api/voicenotes/voicenotes.php
// API fuer Sprachnotizen (Telegram -> Whisper -> Anschlagtafel)

/**
 * Letzte Sprachnotizen fuer die Anschlagtafel laden (neueste zuerst).
 *
 * @param int $data['limit'] Maximale Anzahl (Standard 50, max 200)
 * @testdata {"action": "getRecentVoiceNotes", "limit": 50}
 */
function getRecentVoiceNotes($data) {
    $db = DbhCompany::begin();
    $limit = (int)($data['limit'] ?? 50);
    if ($limit < 1)   $limit = 1;
    if ($limit > 200) $limit = 200;

    // Reihenfolge: manuelle Sortierung (sort_order) hat Vorrang, sonst neueste zuerst.
    // Neue Notizen ohne sort_order fallen auf ihren itime-Zeitstempel zurueck und
    // erscheinen dadurch automatisch oben.
    $notes = $db->getAll(
        "SELECT id, sender_name, transcript, duration, status, audio_file, itime
         FROM voice_notes
         WHERE hidden = FALSE
         ORDER BY COALESCE(sort_order, EXTRACT(EPOCH FROM itime)) DESC, id DESC
         LIMIT :limit",
        [':limit' => $limit]
    );

    resultInfo(true, '', ['notes' => $notes]);
}

/**
 * Mitarbeiterliste fuer das "Von"-Dropdown der Tafel-Verwaltung.
 *
 * @testdata {"action": "getVoiceNoteEmployees"}
 */
function getVoiceNoteEmployees($data) {
    $db = DbhCompany::begin();

    // Nur aktive Mitarbeiter: nicht geloescht (kivitendo-„obsolete") und nicht
    // bereits ausgeschieden/in Rente (enddate in der Vergangenheit).
    $employees = $db->getAll(
        "SELECT id, name, login
         FROM employee
         WHERE deleted IS NOT TRUE
           AND (enddate IS NULL OR enddate >= CURRENT_DATE)
         ORDER BY name"
    );

    resultInfo(true, '', ['employees' => $employees]);
}

/**
 * Reihenfolge der Anschlagtafel neu setzen (Drag & Drop von der PC-Tafel).
 *
 * Erhaelt die sichtbaren Notiz-IDs von oben nach unten. Die oberste bekommt den
 * hoechsten sort_order-Wert. Ein pg_notify('voicenote_change', 'reordered') sorgt
 * dafuer, dass die Anschlagtafel (TV) die Reihenfolge live uebernimmt.
 *
 * @param int[] $data['ids'] Notiz-IDs in gewuenschter Reihenfolge (oben zuerst)
 * @testdata {"action": "reorderVoiceNotes", "ids": [3, 1, 2]}
 */
function reorderVoiceNotes($data) {
    $db = DbhCompany::begin();

    $ids = $data['ids'] ?? [];
    if (!is_array($ids) || count($ids) === 0) {
        resultInfo(false, 'INVALID_INPUT', 'Reihenfolge (ids) ist erforderlich');
        return;
    }

    // Nur ganze Zahlen zulassen und als PostgreSQL-Array-Literal aufbauen ({1,2,3}).
    $intIds = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (count($intIds) === 0) {
        resultInfo(false, 'INVALID_INPUT', 'Keine gueltigen IDs');
        return;
    }
    $pgArray = '{' . implode(',', $intIds) . '}';

    // Ein Ajax-Call, eine DB-Abfrage: sort_order fuer alle IDs in einem Rutsch setzen.
    // Die oberste Notiz (ord=1) erhaelt den groessten Wert (EPOCH now), jede weitere
    // eins weniger. Spaeter eintreffende Notizen ohne sort_order liegen zeitlich hoeher
    // und erscheinen dadurch oben.
    $db->execute(
        "UPDATE voice_notes AS v
         SET sort_order = EXTRACT(EPOCH FROM NOW()) - (o.ord - 1),
             mtime = NOW()
         FROM unnest(:ids::int[]) WITH ORDINALITY AS o(id, ord)
         WHERE v.id = o.id",
        [':ids' => $pgArray]
    );

    // Anschlagtafel (TV) live umsortieren.
    $db->execute(
        "SELECT pg_notify('voicenote_change', :p)",
        [':p' => json_encode(['action' => 'reordered', 'order' => $intIds], JSON_UNESCAPED_UNICODE)]
    );

    resultInfo(true, '');
}

/**
 * Neue Notiz direkt vom PC (Tafel-Verwaltung) hinzufuegen — ohne Telegram/Whisper.
 *
 * Der INSERT-Trigger voice_notes_notify feuert pg_notify('voicenote_change') und
 * die Notiz erscheint dadurch automatisch live auf der Anschlagtafel (TV).
 *
 * @param string $data['transcript']  Notiztext (Pflicht)
 * @param string $data['sender_name'] Anzeigename des Absenders (Pflicht)
 * @testdata {"action": "addVoiceNote", "transcript": "Kaffeemaschine ist defekt", "sender_name": "Ronny"}
 */
function addVoiceNote($data) {
    $db = DbhCompany::begin();
    $transcript = trim((string)($data['transcript'] ?? ''));
    $sender     = trim((string)($data['sender_name'] ?? ''));

    if ($transcript === '') {
        resultInfo(false, 'INVALID_INPUT', 'Notiztext ist erforderlich');
        return;
    }
    if ($sender === '') {
        $sender = 'Unbekannt';
    }

    $note = $db->getOne(
        "INSERT INTO voice_notes (sender_name, transcript, status)
         VALUES (:sender, :transcript, 'transcribed')
         RETURNING id, sender_name, transcript, duration, status, audio_file, itime",
        [':sender' => $sender, ':transcript' => $transcript]
    );

    resultInfo(true, '', ['note' => $note]);
}

/**
 * Sprachnotiz ausblenden (Soft-Delete) — verschwindet von der Anschlagtafel.
 *
 * Meldet die Loeschung zusaetzlich per pg_notify('voicenote_change') live, damit
 * ein von hier (PC) geloeschter Eintrag auch auf der Anschlagtafel (TV) verschwindet.
 * Wiederherstellung nur per DB: UPDATE voice_notes SET hidden=false WHERE id=...
 *
 * @param int $data['id'] Notiz-ID
 * @testdata {"action": "hideVoiceNote", "id": 1}
 */
function hideVoiceNote($data) {
    $db = DbhCompany::begin();
    $id = (int)($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_INPUT', 'Notiz-ID ist erforderlich');
        return;
    }

    $db->execute(
        "UPDATE voice_notes SET hidden = TRUE, mtime = NOW() WHERE id = :id",
        [':id' => $id]
    );

    // Live an alle Anschlagtafeln (TV) melden, damit der Eintrag dort verschwindet.
    $db->execute(
        "SELECT pg_notify('voicenote_change', :p)",
        [':p' => json_encode(['action' => 'removed', 'id' => $id], JSON_UNESCAPED_UNICODE)]
    );

    resultInfo(true, '');
}
