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

    $notes = $db->getAll(
        "SELECT id, sender_name, transcript, duration, status, audio_file, itime
         FROM voice_notes
         WHERE hidden = FALSE
         ORDER BY itime DESC
         LIMIT :limit",
        [':limit' => $limit]
    );

    resultInfo(true, '', ['notes' => $notes]);
}

/**
 * Sprachnotiz ausblenden (Soft-Delete) — verschwindet von der Anschlagtafel.
 *
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

    resultInfo(true, '');
}
