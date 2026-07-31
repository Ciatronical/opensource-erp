<?php
// backend/api/lxcars/instructions.php

/**
 * Generiert die nächste Anweisungsnummer (atomar)
 * Liest optionalen Prefix aus defaults_oserp key 'instructionprefix'
 *
 * @param object $db Database handle
 * @return string Formatierte Anweisungsnummer (z.B. "AW-5" oder "5")
 */
function _getNextInstructionNumber($db) {
    // Atomar den Zähler erhöhen
    $result = $db->getOne(
        "INSERT INTO defaults_oserp (key, value)
         VALUES ('instructionnumber', '101')
         ON CONFLICT (key) DO UPDATE
         SET value = (COALESCE(regexp_replace(defaults_oserp.value, '[^0-9]', '', 'g')::bigint, 0) + 1)::text,
             mtime = now()
         RETURNING value",
        []
    );
    $number = $result['value'];

    // Optionalen Prefix lesen
    $prefixRow = $db->getOne(
        "SELECT value FROM defaults_oserp WHERE key = 'instructionprefix'",
        []
    );
    $prefix = $prefixRow ? trim($prefixRow['value'] ?? '') : '';

    return $prefix . $number;
}

/**
 * Lädt alle Arbeitsanweisungen eines Auftrags
 *
 * @param int $data['oe_id'] Auftrags-ID
 * @testdata {"oe_id": 1}
 */
function getInstructions($data) {
    $db = DbhCompany::begin();
    $oeId = intval($data['oe_id']);

    $rows = $db->getAll(
        "SELECT i.id, i.oe_id, i.description, i.done, i.sort_order,
                i.instruction_number, i.planned_minutes, i.actual_minutes,
                i.employee_id, e.name AS employee_name,
                i.timer_started_at, i.timer_employee_id, i.done_at
         FROM oe_instructions_lxcars i
         LEFT JOIN employee e ON e.id = i.employee_id
         WHERE i.oe_id = :oe_id
         ORDER BY i.sort_order, i.id",
        [':oe_id' => $oeId]
    );

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Kern-Logik: Fügt eine Arbeitsanweisung zu einem Auftrag hinzu
 * Ergänzt automatisch die Master-Tabelle für zukünftiges Autocomplete.
 * WICHTIG: Muss innerhalb einer bestehenden Transaktion aufgerufen werden!
 *
 * @param object $db Database handle (bereits in Transaktion)
 * @param int $oeId Auftrags-ID
 * @param string $description Anweisungstext
 * @return array {id, sort_order, instruction_number, planned_minutes}
 */
function _addInstructionToOe($db, $oeId, $description) {
    // Nächste sort_order ermitteln
    $row = $db->getOne(
        "SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort
         FROM oe_instructions_lxcars WHERE oe_id = :oe_id",
        [':oe_id' => $oeId]
    );
    $nextSort = intval($row['next_sort']);

    // Prüfen ob Anweisung bereits in Master-Tabelle existiert
    $existing = $db->getOne(
        "SELECT id, instruction_number, avg_minutes FROM instructions_lxcars
         WHERE description = :description",
        [':description' => $description]
    );

    $instructionNumber = null;
    $plannedMinutes = 0;

    if ($existing) {
        // Existiert: usage_count erhöhen, bestehende Nummer + Durchschnitt verwenden
        $instructionNumber = $existing['instruction_number'];
        $plannedMinutes = intval($existing['avg_minutes'] ?? 0);

        // Fallback: Wenn kein Master-Durchschnitt, aus historischen actual_minutes berechnen
        if ($plannedMinutes <= 0) {
            $hist = $db->getOne(
                "SELECT ROUND(AVG(actual_minutes)) AS avg_min
                 FROM oe_instructions_lxcars
                 WHERE description = :desc AND actual_minutes > 0",
                [':desc' => $description]
            );
            $plannedMinutes = intval($hist['avg_min'] ?? 0);
            // Master-Tabelle nachziehen
            if ($plannedMinutes > 0) {
                $countRow = $db->getOne(
                    "SELECT COUNT(*) AS cnt FROM oe_instructions_lxcars WHERE description = :desc AND actual_minutes > 0",
                    [':desc' => $description]
                );
                $db->execute(
                    "UPDATE instructions_lxcars SET avg_minutes = :avg, completed_count = :cnt WHERE id = :id",
                    [':avg' => $plannedMinutes, ':cnt' => intval($countRow['cnt']), ':id' => $existing['id']]
                );
            }
        }

        $db->execute(
            "UPDATE instructions_lxcars SET usage_count = usage_count + 1 WHERE id = :id",
            [':id' => $existing['id']]
        );
    } else {
        // Neu: Nächste Nummer vergeben, in Master einfügen
        $instructionNumber = _getNextInstructionNumber($db);
        $db->execute(
            "INSERT INTO instructions_lxcars (description, usage_count, instruction_number)
             VALUES (:description, 1, :number)",
            [':description' => $description, ':number' => $instructionNumber]
        );
    }

    // Anweisung zum Auftrag hinzufügen MIT instruction_number + planned_minutes
    $db->execute(
        "INSERT INTO oe_instructions_lxcars (oe_id, description, done, sort_order, instruction_number, planned_minutes)
         VALUES (:oe_id, :description, false, :sort_order, :number, :planned)",
        [':oe_id' => $oeId, ':description' => $description, ':sort_order' => $nextSort,
         ':number' => $instructionNumber, ':planned' => $plannedMinutes]
    );

    $result = $db->getOne("SELECT currval('oe_instructions_lxcars_id_seq') AS id");

    return [
        'id' => intval($result['id']),
        'sort_order' => $nextSort,
        'instruction_number' => $instructionNumber,
        'planned_minutes' => $plannedMinutes
    ];
}

/**
 * Fügt eine neue Arbeitsanweisung zu einem Auftrag hinzu
 * Ergänzt automatisch die Master-Tabelle für zukünftiges Autocomplete
 *
 * @param int $data['oe_id'] Auftrags-ID
 * @param string $data['description'] Anweisungstext
 * @testdata {"oe_id": 1, "description": "Eingangskontrolle"}
 */
function addInstruction($data) {
    $db = DbhCompany::begin();
    $oeId = intval($data['oe_id']);
    $description = trim($data['description'] ?? '');

    if (!$oeId || empty($description)) {
        resultInfo(false, 'VALIDATION_ERROR: oe_id and description required');
        return;
    }

    $db->beginTransaction();
    try {
        $result = _addInstructionToOe($db, $oeId, $description);
        $db->commit();
        resultInfo(true, 'CREATED', $result);
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError('API_DATABASE_ERROR', $e->getMessage());
    }
}

/**
 * Aktualisiert eine Arbeitsanweisung (done-Status oder Beschreibung)
 *
 * @param int $data['id'] Anweisungs-ID
 * @param array $data['data'] Key-Value-Paare { done?, description? }
 * @testdata {"id": 1, "data": {"done": true}}
 */
function updateInstruction($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id']);
    $fields = $data['data'];

    if (!$id) {
        resultInfo(false, 'VALIDATION_ERROR: id required');
        return;
    }

    // done=true nur erlaubt wenn actual_minutes > 0
    if (isset($fields['done']) && $fields['done']) {
        $current = $db->getOne(
            "SELECT actual_minutes FROM oe_instructions_lxcars WHERE id = :id",
            [':id' => $id]
        );
        $currentActual = intval($current['actual_minutes'] ?? 0);
        $pendingActual = isset($fields['actual_minutes']) ? intval($fields['actual_minutes']) : $currentActual;
        if ($pendingActual <= 0) {
            resultInfo(false, 'VALIDATION_ERROR: actual_minutes required before marking done');
            return;
        }
    }

    // done_at automatisch setzen/loeschen
    if (isset($fields['done'])) {
        $fields['done_at'] = $fields['done'] ? date('Y-m-d H:i:s') : null;
    }

    $allowed = ['done', 'description', 'instruction_number', 'planned_minutes', 'actual_minutes', 'employee_id', 'timer_started_at', 'timer_employee_id', 'done_at'];
    $setClauses = [];
    $params = [':id' => $id];

    foreach ($fields as $key => $value) {
        if (!in_array($key, $allowed)) continue;

        $paramName = ':' . $key;
        if ($key === 'done') {
            $value = $value ? 't' : 'f';
        }
        if ($key === 'planned_minutes' || $key === 'actual_minutes') {
            $value = intval($value);
        }
        if ($key === 'employee_id') {
            $value = $value ? intval($value) : null;
        }
        $setClauses[] = "$key = $paramName";
        $params[$paramName] = $value;
    }

    if (empty($setClauses)) {
        resultInfo(true, 'NO_CHANGES');
        return;
    }

    $setString = implode(', ', $setClauses);
    $db->execute(
        "UPDATE oe_instructions_lxcars SET $setString WHERE id = :id",
        $params
    );

    // Master-Durchschnitt aktualisieren wenn actual_minutes gesetzt wird
    if (isset($fields['actual_minutes']) && intval($fields['actual_minutes']) > 0) {
        $row = $db->getOne(
            "SELECT description FROM oe_instructions_lxcars WHERE id = :id",
            [':id' => $id]
        );
        if ($row) {
            _updateMasterAverage($db, $row['description'], intval($fields['actual_minutes']));
        }
    }

    // Prüfen ob alle Instructions des Auftrags erledigt sind → Benachrichtigung
    if (isset($fields['done']) && $fields['done']) {
        $instr = $db->getOne(
            "SELECT oe_id FROM oe_instructions_lxcars WHERE id = :id",
            [':id' => $id]
        );
        if ($instr) {
            $pending = $db->getOne(
                "SELECT COUNT(*) AS cnt FROM oe_instructions_lxcars
                 WHERE oe_id = :oe_id AND done = false",
                [':oe_id' => $instr['oe_id']]
            );
            if (intval($pending['cnt']) === 0) {
                $payload = json_encode([
                    'action' => 'ALL_DONE',
                    'table' => 'oe_instructions_lxcars',
                    'oe_id' => intval($instr['oe_id'])
                ]);
                $db->execute("SELECT pg_notify('faktura_change', :payload)", [':payload' => $payload]);
            }
        }
    }

    resultInfo(true, 'UPDATED');
}

/**
 * Aktualisiert den Durchschnittswert in der Master-Tabelle (Running Average)
 */
function _updateMasterAverage($db, $description, $actualMinutes) {
    $db->execute(
        "UPDATE instructions_lxcars
         SET avg_minutes = CASE
                WHEN completed_count = 0 THEN :minutes
                ELSE ((avg_minutes * completed_count) + :minutes2) / (completed_count + 1)
             END,
             completed_count = completed_count + 1
         WHERE description = :description",
        [':minutes' => $actualMinutes, ':minutes2' => $actualMinutes, ':description' => $description]
    );
}

/**
 * Löscht eine Arbeitsanweisung
 *
 * @param int $data['id'] Anweisungs-ID
 * @testdata {"id": 1}
 */
function deleteInstruction($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id']);

    if (!$id) {
        resultInfo(false, 'VALIDATION_ERROR: id required');
        return;
    }

    $db->execute(
        "DELETE FROM oe_instructions_lxcars WHERE id = :id",
        [':id' => $id]
    );

    resultInfo(true, 'DELETED');
}

/**
 * Löscht alle Arbeitsanweisungen eines Auftrags
 *
 * @param int $data['oe_id'] Auftrags-ID
 * @testdata {"oe_id": 1}
 */
function deleteAllInstructions($data) {
    $db = DbhCompany::begin();
    $oeId = intval($data['oe_id']);

    if (!$oeId) {
        resultInfo(false, 'VALIDATION_ERROR: oe_id required');
        return;
    }

    $db->execute(
        "DELETE FROM oe_instructions_lxcars WHERE oe_id = :oe_id",
        [':oe_id' => $oeId]
    );

    resultInfo(true, 'DELETED');
}

/**
 * Aktualisiert die Reihenfolge der Arbeitsanweisungen nach Drag&Drop
 *
 * @param int $data['oe_id'] Auftrags-ID
 * @param array $data['order'] Array von Anweisungs-IDs in neuer Reihenfolge
 * @testdata {"oe_id": 1, "order": [3, 1, 2]}
 */
function reorderInstructions($data) {
    $db = DbhCompany::begin();
    $oeId = intval($data['oe_id']);
    $order = $data['order'];

    if (!$oeId || !is_array($order)) {
        resultInfo(false, 'VALIDATION_ERROR: oe_id and order array required');
        return;
    }

    // Einzelnes UPDATE mit VALUES statt N Einzelqueries
    $values = [];
    $params = [':oe_id' => $oeId];
    foreach ($order as $index => $id) {
        $pId = ':id_' . $index;
        $pSort = ':sort_' . $index;
        $values[] = "($pId::int, $pSort::int)";
        $params[$pId] = intval($id);
        $params[$pSort] = $index + 1;
    }

    if (empty($values)) {
        resultInfo(true, 'REORDERED');
        return;
    }

    $valueStr = implode(', ', $values);
    $db->execute(
        "UPDATE oe_instructions_lxcars oi
         SET sort_order = v.new_sort
         FROM (VALUES $valueStr) AS v(vid, new_sort)
         WHERE oi.id = v.vid AND oi.oe_id = :oe_id",
        $params
    );

    resultInfo(true, 'REORDERED');
}

/**
 * Durchsucht die Master-Tabelle für Autocomplete-Vorschläge
 *
 * @param string $data['query'] Suchbegriff (min. 2 Zeichen)
 * @testdata {"query": "Eingang"}
 */
function searchInstructions($data) {
    $db = DbhCompany::begin();
    $query = trim($data['query'] ?? '');

    if (strlen($query) < 1) {
        resultInfo(true, 'OK', []);
        return;
    }

    // Wortweise Suche: ein diktierter Satz ("professionellen Urlaubscheck ...")
    // soll den bestehenden Einzelwort-Eintrag ("Urlaubscheck") finden. Die volle
    // Phrase zählt am stärksten, danach die Anzahl getroffener Wörter.
    $stop = array_flip([
        'der','die','das','und','oder','ein','eine','einen','einem','einer','eines',
        'mit','ohne','für','von','vom','zum','zur','den','dem','des','auf','aus','bei',
        'durchführen','durchfuhren','prüfen','pruefen','machen','bitte','mal',
    ]);
    $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
    $words = array_values(array_filter($words, function ($w) use ($stop) {
        return mb_strlen($w) >= 3 && !isset($stop[mb_strtolower($w)]);
    }));

    $params = [':full' => '%' . $query . '%', ':numquery' => $query . '%', ':exact' => $query];
    $conds  = ['LOWER(description) LIKE LOWER(:full)', 'instruction_number LIKE :numquery'];
    $scores = ['CASE WHEN LOWER(description) LIKE LOWER(:full) THEN 100 ELSE 0 END'];
    foreach ($words as $i => $w) {
        $p = ':w' . $i;
        $params[$p] = '%' . $w . '%';
        $conds[]  = "LOWER(description) LIKE LOWER($p)";
        $scores[] = "CASE WHEN LOWER(description) LIKE LOWER($p) THEN 1 ELSE 0 END";
    }
    $where = implode(' OR ', $conds);
    $score = implode(' + ', $scores);

    $rows = $db->getAll(
        "SELECT id, description, instruction_number, ($score) AS match_score
         FROM instructions_lxcars
         WHERE $where
         ORDER BY
             CASE WHEN instruction_number = :exact THEN 0 ELSE 1 END,
             match_score DESC,
             usage_count DESC, description
         LIMIT 15",
        $params
    );

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Ersetzt eine bestehende Auftrags-Anweisung durch eine Master-Anweisung.
 *
 * Wird verwendet, wenn sich der Benutzer bei einer bereits erfassten Anweisung
 * vertippt hat und diese per Autocomplete durch die korrekte Master-Anweisung
 * ersetzen moechte. Uebernimmt Beschreibung, Anweisungsnummer und — falls im
 * Master hinterlegt — die geplante Zeit; analog zum Anlegen einer Anweisung.
 *
 * @param int    $data['id']          ID der Auftrags-Anweisung (oe_instructions_lxcars)
 * @param string $data['description'] Neue Beschreibung (Master-Anweisung)
 * @testdata {"id": 1, "description": "Oelwechsel durchfuehren"}
 */
function replaceInstruction($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    $description = trim($data['description'] ?? '');

    if (!$id || empty($description)) {
        resultInfo(false, 'VALIDATION_ERROR: id and description required');
        return;
    }

    $db->beginTransaction();
    try {
        // Master-Anweisung anhand der Beschreibung ermitteln
        $master = $db->getOne(
            "SELECT id, instruction_number, avg_minutes FROM instructions_lxcars
             WHERE description = :description",
            [':description' => $description]
        );

        if ($master) {
            $plannedMinutes = intval($master['avg_minutes'] ?? 0);
            // usage_count der Master-Anweisung erhoehen (wie beim Anlegen)
            $db->execute(
                "UPDATE instructions_lxcars SET usage_count = usage_count + 1 WHERE id = :id",
                [':id' => $master['id']]
            );
            // Geplante Zeit nur uebernehmen, wenn im Master hinterlegt — sonst
            // bereits erfasste Planzeit der Position nicht ueberschreiben.
            if ($plannedMinutes > 0) {
                $db->execute(
                    "UPDATE oe_instructions_lxcars
                     SET description = :description, instruction_number = :number, planned_minutes = :planned
                     WHERE id = :id",
                    [':description' => $description, ':number' => $master['instruction_number'],
                     ':planned' => $plannedMinutes, ':id' => $id]
                );
            } else {
                $db->execute(
                    "UPDATE oe_instructions_lxcars
                     SET description = :description, instruction_number = :number
                     WHERE id = :id",
                    [':description' => $description, ':number' => $master['instruction_number'], ':id' => $id]
                );
            }
        } else {
            // Kein Master-Treffer (freier Text) — nur die Beschreibung aktualisieren
            $db->execute(
                "UPDATE oe_instructions_lxcars SET description = :description WHERE id = :id",
                [':description' => $description, ':id' => $id]
            );
        }

        $row = $db->getOne(
            "SELECT instruction_number, planned_minutes FROM oe_instructions_lxcars WHERE id = :id",
            [':id' => $id]
        );
        $db->commit();
        resultInfo(true, 'REPLACED', [
            'instruction_number' => $row['instruction_number'] ?? null,
            'planned_minutes' => intval($row['planned_minutes'] ?? 0)
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError('API_DATABASE_ERROR', $e->getMessage());
    }
}

// ===== Master-Arbeitsanweisungen Verwaltung =====

/**
 * Lädt alle Master-Arbeitsanweisungen für den Verwaltungsdialog
 *
 * @testdata {}
 */
function getMasterInstructions($data) {
    $db = DbhCompany::begin();

    $rows = $db->getAll(
        "SELECT id, instruction_number, description, usage_count
         FROM instructions_lxcars
         ORDER BY COALESCE(regexp_replace(instruction_number, '[^0-9]', '', 'g')::bigint, 0) ASC, id ASC",
        []
    );

    resultInfo(true, 'OK', $rows ?: []);
}

/**
 * Fügt eine neue Master-Arbeitsanweisung hinzu (aus dem Verwaltungsdialog)
 *
 * @param string $data['description'] Anweisungstext
 * @testdata {"description": "Neue Standardanweisung"}
 */
function addMasterInstruction($data) {
    $db = DbhCompany::begin();
    $description = trim($data['description'] ?? '');

    if (empty($description)) {
        resultInfo(false, 'VALIDATION_ERROR: description required');
        return;
    }

    $db->beginTransaction();
    try {
        // Duplikat prüfen
        $existing = $db->getOne(
            "SELECT id FROM instructions_lxcars WHERE description = :description",
            [':description' => $description]
        );

        if ($existing) {
            $db->rollBack();
            resultInfo(false, 'DUPLICATE: instruction already exists');
            return;
        }

        $instructionNumber = _getNextInstructionNumber($db);

        $db->execute(
            "INSERT INTO instructions_lxcars (description, usage_count, instruction_number)
             VALUES (:description, 0, :number)",
            [':description' => $description, ':number' => $instructionNumber]
        );

        $result = $db->getOne("SELECT currval('instructions_lxcars_id_seq') AS id");

        $db->commit();
        resultInfo(true, 'CREATED', [
            'id' => intval($result['id']),
            'instruction_number' => $instructionNumber,
            'description' => $description,
            'usage_count' => 0
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError('API_DATABASE_ERROR', $e->getMessage());
    }
}

/**
 * Aktualisiert eine Master-Arbeitsanweisung (Beschreibung und/oder Nummer)
 * Ändert NICHT bestehende per-Order Kopien.
 *
 * @param int $data['id'] Master-Anweisungs-ID
 * @param string $data['description'] Neue Beschreibung
 * @param string $data['instruction_number'] Optionale neue Nummer
 * @testdata {"id": 1, "description": "Neue Beschreibung"}
 */
function updateMasterInstruction($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id']);
    $description = trim($data['description'] ?? '');
    $instructionNumber = isset($data['instruction_number']) ? trim($data['instruction_number']) : null;

    if (!$id || empty($description)) {
        resultInfo(false, 'VALIDATION_ERROR: id and description required');
        return;
    }

    $setClauses = ['description = :description'];
    $params = [':description' => $description, ':id' => $id];

    if ($instructionNumber !== null) {
        $setClauses[] = 'instruction_number = :number';
        $params[':number'] = $instructionNumber;
    }

    $setString = implode(', ', $setClauses);
    $db->execute(
        "UPDATE instructions_lxcars SET $setString WHERE id = :id",
        $params
    );

    resultInfo(true, 'UPDATED');
}

/**
 * Prüft ob eine Anweisungsnummer bereits vergeben ist
 *
 * @param string $data['number'] Zu prüfende Nummer
 * @param string $data['exclude_number'] Aktuelle Nummer ausschließen (eigene)
 * @testdata {"number": "101", "exclude_number": "101"}
 */
function checkInstructionNumber($data) {
    $db = DbhCompany::begin();
    $number = trim($data['number'] ?? '');
    $excludeNumber = trim($data['exclude_number'] ?? '');

    if (empty($number)) {
        resultInfo(true, 'OK', ['taken' => false]);
        return;
    }

    $row = $db->getOne(
        "SELECT id, description FROM instructions_lxcars
         WHERE instruction_number = :number AND instruction_number != :exclude",
        [':number' => $number, ':exclude' => $excludeNumber]
    );

    resultInfo(true, 'OK', [
        'taken' => !!$row,
        'description' => $row ? $row['description'] : null
    ]);
}

/**
 * Setzt employee_id für alle Anweisungen eines Auftrags
 *
 * @param int $data['oe_id'] Auftrags-ID
 * @param int|null $data['employee_id'] Mitarbeiter-ID (null zum Leeren)
 * @testdata {"oe_id": 1, "employee_id": 5}
 */
function setAllInstructionsEmployee($data) {
    $db = DbhCompany::begin();
    $oeId = intval($data['oe_id']);
    $employeeId = isset($data['employee_id']) && $data['employee_id'] ? intval($data['employee_id']) : null;

    if (!$oeId) {
        resultInfo(false, 'VALIDATION_ERROR: oe_id required');
        return;
    }

    $db->execute(
        "UPDATE oe_instructions_lxcars SET employee_id = :employee_id WHERE oe_id = :oe_id",
        [':employee_id' => $employeeId, ':oe_id' => $oeId]
    );

    resultInfo(true, 'UPDATED');
}

/**
 * Löscht eine Master-Arbeitsanweisung
 * Per-Order Kopien in oe_instructions_lxcars bleiben unberührt.
 *
 * @param int $data['id'] Master-Anweisungs-ID
 * @testdata {"id": 1}
 */
function deleteMasterInstruction($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id']);

    if (!$id) {
        resultInfo(false, 'VALIDATION_ERROR: id required');
        return;
    }

    $db->execute(
        "DELETE FROM instructions_lxcars WHERE id = :id",
        [':id' => $id]
    );

    resultInfo(true, 'DELETED');
}

// ===== Zeiterfassung: Timer-API =====

/**
 * Startet den Timer fuer eine Arbeitsanweisung.
 * Stoppt automatisch jeden anderen laufenden Timer des gleichen Mitarbeiters
 * und addiert dessen verstrichene Zeit zu actual_minutes.
 *
 * @param int $data['id'] Anweisungs-ID
 * @param int $data['employee_id'] Mitarbeiter-ID
 * @param string $data['pausen'] Pausenzeiten aus Config (z.B. "09:00-09:30, 12:00-12:30")
 * @param string $data['arbeitsbeginn'] Arbeitsbeginn (z.B. "08:00")
 * @param string $data['arbeitsende'] Arbeitsende (z.B. "17:00")
 * @testdata {"id": 1, "employee_id": 5, "pausen": "09:00-09:30, 12:00-12:30", "arbeitsbeginn": "08:00", "arbeitsende": "17:00"}
 */
function startInstructionTimer($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id']);
    $employeeId = intval($data['employee_id']);

    if (!$id || !$employeeId) {
        resultInfo(false, 'VALIDATION_ERROR: id and employee_id required');
        return;
    }

    $pausen = $data['pausen'] ?? '';
    $arbeitsbeginn = $data['arbeitsbeginn'] ?? '08:00';
    $arbeitsende = $data['arbeitsende'] ?? '17:00';

    $db->beginTransaction();
    try {
        // Anderen laufenden Timer dieses Mitarbeiters finden und stoppen
        $running = $db->getOne(
            "SELECT id, timer_started_at, actual_minutes
             FROM oe_instructions_lxcars
             WHERE timer_employee_id = :emp AND timer_started_at IS NOT NULL AND id != :id",
            [':emp' => $employeeId, ':id' => $id]
        );

        $stoppedId = null;
        $stoppedMinutes = 0;

        if ($running) {
            $stoppedId = intval($running['id']);
            $elapsed = _calcNetMinutes(
                $running['timer_started_at'],
                date('Y-m-d H:i:s'),
                $pausen, $arbeitsbeginn, $arbeitsende
            );
            $stoppedMinutes = max(1, $elapsed);
            $newActual = intval($running['actual_minutes']) + $stoppedMinutes;

            $db->execute(
                "UPDATE oe_instructions_lxcars
                 SET timer_started_at = NULL, timer_employee_id = NULL,
                     actual_minutes = :actual, done_at = NOW()
                 WHERE id = :id",
                [':actual' => $newActual, ':id' => $stoppedId]
            );
        }

        // Neuen Timer starten
        $db->execute(
            "UPDATE oe_instructions_lxcars
             SET timer_started_at = NOW(), timer_employee_id = :emp
             WHERE id = :id",
            [':emp' => $employeeId, ':id' => $id]
        );

        // Employee_id auch als "Erledigt von" setzen falls noch leer
        $db->execute(
            "UPDATE oe_instructions_lxcars
             SET employee_id = :emp
             WHERE id = :id AND employee_id IS NULL",
            [':emp' => $employeeId, ':id' => $id]
        );

        $db->commit();

        resultInfo(true, 'TIMER_STARTED', [
            'stopped_id' => $stoppedId,
            'stopped_minutes' => $stoppedMinutes
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        throw new ApiError('API_DATABASE_ERROR', $e->getMessage());
    }
}

/**
 * Stoppt den Timer einer Arbeitsanweisung und addiert die Netto-Arbeitszeit.
 *
 * @param int $data['id'] Anweisungs-ID
 * @param string $data['pausen'] Pausenzeiten aus Config
 * @param string $data['arbeitsbeginn'] Arbeitsbeginn
 * @param string $data['arbeitsende'] Arbeitsende
 * @testdata {"id": 1, "pausen": "09:00-09:30, 12:00-12:30", "arbeitsbeginn": "08:00", "arbeitsende": "17:00"}
 */
function stopInstructionTimer($data) {
    $db = DbhCompany::begin();
    $id = intval($data['id']);

    if (!$id) {
        resultInfo(false, 'VALIDATION_ERROR: id required');
        return;
    }

    $pausen = $data['pausen'] ?? '';
    $arbeitsbeginn = $data['arbeitsbeginn'] ?? '08:00';
    $arbeitsende = $data['arbeitsende'] ?? '17:00';

    $row = $db->getOne(
        "SELECT timer_started_at, actual_minutes FROM oe_instructions_lxcars WHERE id = :id",
        [':id' => $id]
    );

    if (!$row || !$row['timer_started_at']) {
        resultInfo(false, 'TIMER_NOT_RUNNING');
        return;
    }

    $elapsed = _calcNetMinutes(
        $row['timer_started_at'],
        date('Y-m-d H:i:s'),
        $pausen, $arbeitsbeginn, $arbeitsende
    );
    $minutes = max(1, $elapsed);
    $newActual = intval($row['actual_minutes']) + $minutes;

    $db->execute(
        "UPDATE oe_instructions_lxcars
         SET timer_started_at = NULL, timer_employee_id = NULL,
             actual_minutes = :actual, done_at = NOW()
         WHERE id = :id",
        [':actual' => $newActual, ':id' => $id]
    );

    resultInfo(true, 'TIMER_STOPPED', [
        'elapsed_minutes' => $minutes,
        'actual_minutes' => $newActual
    ]);
}

/**
 * Gibt den aktuell laufenden Timer eines Mitarbeiters zurueck (auftragsuebergreifend).
 *
 * @param int $data['employee_id'] Mitarbeiter-ID
 * @testdata {"employee_id": 5}
 */
function getActiveTimer($data) {
    $db = DbhCompany::begin();
    $employeeId = intval($data['employee_id']);

    if (!$employeeId) {
        resultInfo(false, 'VALIDATION_ERROR: employee_id required');
        return;
    }

    $row = $db->getOne(
        "SELECT i.id, i.oe_id, i.description, i.timer_started_at, i.timer_employee_id,
                o.ordnumber
         FROM oe_instructions_lxcars i
         LEFT JOIN oe o ON o.id = i.oe_id
         WHERE i.timer_employee_id = :emp AND i.timer_started_at IS NOT NULL",
        [':emp' => $employeeId]
    );

    resultInfo(true, 'OK', $row ?: null);
}

/**
 * Berechnet die Netto-Arbeitsminuten zwischen zwei Zeitpunkten,
 * abzueglich Pausen und Zeit ausserhalb der Arbeitszeit.
 *
 * @param string $startStr Startzeit (DB-Timestamp)
 * @param string $endStr Endzeit (DB-Timestamp)
 * @param string $pausenStr Kommagetrennte Pausen "HH:MM-HH:MM, ..."
 * @param string $arbeitsbeginn "HH:MM"
 * @param string $arbeitsende "HH:MM"
 * @return int Netto-Minuten (aufgerundet, mindestens 0)
 */
function _calcNetMinutes($startStr, $endStr, $pausenStr, $arbeitsbeginn, $arbeitsende) {
    $start = new DateTime($startStr);
    $end = new DateTime($endStr);

    if ($end <= $start) return 0;

    $workStartMin = _timeToMinutes($arbeitsbeginn);
    $workEndMin = _timeToMinutes($arbeitsende);

    // Pausen parsen
    $breaks = [];
    if (trim($pausenStr)) {
        foreach (explode(',', $pausenStr) as $b) {
            $parts = explode('-', trim($b));
            if (count($parts) === 2) {
                $breaks[] = [
                    'start' => _timeToMinutes(trim($parts[0])),
                    'end' => _timeToMinutes(trim($parts[1]))
                ];
            }
        }
    }

    // Minutenweise iterieren (Tag fuer Tag)
    $totalMinutes = 0;
    $current = clone $start;

    while ($current < $end) {
        $dayMinute = intval($current->format('H')) * 60 + intval($current->format('i'));

        // Nur zaehlen wenn innerhalb Arbeitszeit
        if ($dayMinute >= $workStartMin && $dayMinute < $workEndMin) {
            $inBreak = false;
            foreach ($breaks as $brk) {
                if ($dayMinute >= $brk['start'] && $dayMinute < $brk['end']) {
                    $inBreak = true;
                    break;
                }
            }
            if (!$inBreak) {
                $totalMinutes++;
            }
        }

        $current->modify('+1 minute');
    }

    return $totalMinutes;
}

/**
 * Hilfsfunktion: "HH:MM" -> Minuten seit Mitternacht
 */
function _timeToMinutes($timeStr) {
    $parts = explode(':', $timeStr);
    return intval($parts[0]) * 60 + intval($parts[1] ?? 0);
}
