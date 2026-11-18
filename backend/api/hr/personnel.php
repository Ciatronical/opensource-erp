<?php
// backend/api/hr/personnel.php
//
// Personalverwaltung: Personalfragebogen, Personalakte, Personalschreiben.
//
// * Personalfragebogen — Stammdaten je Mitarbeiter in hr_employee_data, als
//   PDF druckbar (ausgefüllt oder leer zum Mitgeben an neue Mitarbeiter).
// * Personalakte — Dokumente je Mitarbeiter (Einkommensteuernachweise,
//   Verträge, Krankmeldungen …). Die Datei liegt im Datenverzeichnis der
//   Firma unter personal/{employee_id}/, die Zeile in hr_documents trägt
//   Ablagefach, Titel, Datum und wer sie abgelegt hat.
// * Personalschreiben — Abmahnung, Kündigung, Aufhebungsvertrag aus Vorlagen
//   (mitgeliefert in hr_document_template_defaults, von der Firma angepasst in
//   hr_document_templates). Das erzeugte PDF wandert in die Personalakte.
//
// Alle Funktionen verlangen das Recht „hr_management" (Personalverwaltung):
// eine Personalakte mit Abmahnungen und Steuerdaten darf nicht jeder
// angemeldete Benutzer lesen. Das Recht „admin" taugt dafür nicht — es ist in
// gewachsenen Installationen an fast jede Gruppe vergeben.

require_once __DIR__.'/../customer_vendor/filemanager.php';
require_once __DIR__.'/../print/print.php';
require_once __DIR__.'/../print/template_engine.php';
require_once __DIR__.'/personnel.pdf.php';

define('HR_DOC_MAX_FILESIZE', 25 * 1024 * 1024); // 25 MB

/** Erlaubte Dateiformate der Personalakte: MIME → Dateiendung */
const HR_DOC_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.oasis.opendocument.text' => 'odt',
];

/**
 * Spalten des Personalfragebogens mit ihrem Typ. Einzige Stelle, an der die
 * Feldliste steht — Speichern baut daraus INSERT und UPDATE.
 */
const HR_DATA_FIELDS = [
    'personnel_number' => 'text', 'salutation' => 'text', 'first_name' => 'text', 'last_name' => 'text',
    'birth_name' => 'text', 'birth_date' => 'date', 'birth_place' => 'text', 'birth_country' => 'text',
    'nationality' => 'text', 'gender' => 'text', 'marital_status' => 'text',
    'street' => 'text', 'zipcode' => 'text', 'city' => 'text', 'country' => 'text',
    'phone' => 'text', 'mobile' => 'text', 'email' => 'text', 'severely_disabled' => 'boolean',
    'iban' => 'text', 'bic' => 'text', 'bank_name' => 'text', 'account_holder' => 'text',
    'entry_date' => 'date', 'exit_date' => 'date', 'job_title' => 'text', 'employment_type' => 'text',
    'weekly_hours' => 'numeric', 'probation_end' => 'date', 'limited_until' => 'date', 'notice_period' => 'text',
    'main_employment' => 'boolean', 'other_employments' => 'text',
    'school_degree' => 'text', 'vocational_training' => 'text',
    'tax_id' => 'text', 'child_allowance' => 'numeric', 'denomination' => 'text',
    'sv_number' => 'text', 'health_insurance' => 'text', 'health_insurance_type' => 'text',
    'has_children' => 'boolean', 'children_count' => 'numeric',
    'emergency_name' => 'text', 'emergency_phone' => 'text', 'driving_license' => 'text', 'notes' => 'text',
];

/**
 * Recht für die Personalverwaltung (hr_management, siehe hr.php).
 */
function hrPersonnelPermit(): void {
    hrManagementPermit();
}

/**
 * Anmeldename des Aufrufers. Bewusst aus der Sitzung und nicht aus
 * employee_id der Anfrage: dort steht in diesem Modul der Mitarbeiter, dessen
 * Akte bearbeitet wird.
 */
function hrActorLogin(): string {
    $auth = DbhAuth::begin();
    $auth->fetchSessionData();
    return (string)$auth->getLogin();
}

/**
 * Ordner der Personalakte eines Mitarbeiters; legt ihn bei Bedarf an und
 * sperrt das Oberverzeichnis gegen direkten Abruf über den Webserver.
 *
 * @param int  $employeeId Mitarbeiter
 * @param bool $create     Ordner anlegen
 * @return string Absoluter Pfad
 */
function hrPersonnelDir(int $employeeId, bool $create = false): string {
    $base = fmDataDir() . '/personal';
    $dir  = $base . '/' . $employeeId;
    if ($create && !is_dir($dir)) {
        fmMkdir($dir);
        if (!is_file($base . '/.htaccess')) {
            @file_put_contents($base . '/.htaccess', "Require all denied\n");
        }
    }
    return $dir;
}

/** SQL für die Dokumentzeilen der Personalakte (Alias x = hr_documents) */
function hrDocumentSelectSql(string $where, string $source = 'hr_documents'): string {
    return "SELECT x.id, x.employee_id, x.category, x.title, x.filename, x.original_name, x.filesize,
                   x.doc_date, x.doc_year, x.notes, x.subject, (x.body IS NOT NULL) AS generated,
                   x.itime, c.name AS created_by_name
              FROM $source x
              LEFT JOIN employee c ON c.id = x.created_by
             WHERE $where";
}

// ============================================================================
// ÜBERSICHT UND PERSONALAKTE
// ============================================================================

/**
 * Personalübersicht: Mitarbeiter mit Kennzahlen ihrer Akte, Ablagefächer und
 * Vorlagen für Personalschreiben — alles in einer Abfrage.
 *
 * @param bool $data['include_inactive'] Auch ausgeschiedene (gelöschte) Mitarbeiter
 * @testdata {"include_inactive": false}
 */
function getPersonnelOverview($data) {
    hrPersonnelPermit();
    $db = DbhCompany::begin();
    $row = $db->getOne(<<<SQL
        WITH p AS (SELECT :inactive::boolean AS inactive)
        SELECT json_build_object(
            'employees', COALESCE((
                SELECT json_agg(x ORDER BY x.deleted, x.name)
                FROM (
                    SELECT e.id AS employee_id, COALESCE(NULLIF(e.name, ''), e.login) AS name, e.login,
                           COALESCE(e.deleted, FALSE) AS deleted,
                           d.id IS NOT NULL AS has_data,
                           d.personnel_number, d.job_title, d.entry_date, d.exit_date,
                           d.probation_end, d.limited_until,
                           (SELECT COUNT(*) FROM hr_documents h WHERE h.employee_id = e.id) AS document_count
                    FROM employee e
                    LEFT JOIN hr_employee_data d ON d.employee_id = e.id, p
                    WHERE e.login IS NOT NULL AND e.login <> ''
                      AND (p.inactive OR e.deleted IS NOT TRUE)
                ) x
            ), '[]'::json),
            'categories', COALESCE((
                SELECT json_agg(c ORDER BY c.sortkey, c.name) FROM hr_document_categories c
            ), '[]'::json),
            'templates', COALESCE((
                SELECT json_agg(t ORDER BY t.sortkey, t.name)
                FROM (
                    SELECT t.id, t.default_key, t.category, t.name, t.subject, t.body,
                           t.signature_mode, t.receipt, COALESCE(d.sortkey, 1000) AS sortkey
                    FROM hr_document_templates t
                    LEFT JOIN hr_document_template_defaults d ON d.key = t.default_key
                    UNION ALL
                    SELECT NULL, d.key, d.category, d.name, d.subject, d.body,
                           d.signature_mode, d.receipt, d.sortkey
                    FROM hr_document_template_defaults d
                    WHERE NOT EXISTS (SELECT 1 FROM hr_document_templates t WHERE t.default_key = d.key)
                ) t
            ), '[]'::json)
        ) AS result
    SQL, [':inactive' => !empty($data['include_inactive']) ? 'true' : 'false']);
    resultInfo(true, '', ['results' => json_decode($row['result'], true)]);
}

/**
 * Personalakte eines Mitarbeiters: Stammdaten (Personalfragebogen) und
 * Dokumente. Ohne gespeicherten Fragebogen kommen Vor- und Nachname als
 * Vorschlag aus dem Benutzernamen.
 *
 * @param int $data['employee_id'] Mitarbeiter
 * @testdata {"employee_id": 861}
 */
function getPersonnelFile($data) {
    hrPersonnelPermit();
    $db = DbhCompany::begin();
    $docs = hrDocumentSelectSql('x.employee_id = (SELECT employee_id FROM p)');
    $row = $db->getOne(<<<SQL
        WITH p AS (SELECT :employee_id::int AS employee_id)
        SELECT json_build_object(
            'employee', (
                SELECT json_build_object('employee_id', e.id, 'name', COALESCE(NULLIF(e.name, ''), e.login),
                                         'login', e.login, 'deleted', COALESCE(e.deleted, FALSE))
                FROM employee e, p WHERE e.id = p.employee_id
            ),
            'data', COALESCE(
                (SELECT row_to_json(d) FROM hr_employee_data d, p WHERE d.employee_id = p.employee_id),
                (SELECT json_build_object(
                    'employee_id', e.id,
                    'first_name', NULLIF(regexp_replace(trim(COALESCE(e.name, '')), '\s+\S+$', ''), trim(COALESCE(e.name, ''))),
                    'last_name', NULLIF(regexp_replace(trim(COALESCE(e.name, '')), '^.*\s', ''), ''),
                    'main_employment', TRUE, 'severely_disabled', FALSE, 'has_children', FALSE)
                 FROM employee e, p WHERE e.id = p.employee_id)
            ),
            'documents', COALESCE((
                SELECT json_agg(x ORDER BY COALESCE(x.doc_date, x.itime::date) DESC, x.id DESC) FROM ($docs) x
            ), '[]'::json)
        ) AS result
    SQL, [':employee_id' => intval($data['employee_id'] ?? 0)]);
    $result = json_decode($row['result'], true);
    if (empty($result['employee'])) throw new ApiError('EMPLOYEE_NOT_FOUND', 'Mitarbeiter nicht gefunden');
    resultInfo(true, '', ['results' => $result]);
}

/**
 * Personalfragebogen speichern (anlegen oder ändern). Die Feldliste steht in
 * HR_DATA_FIELDS; leere Angaben werden als NULL gespeichert.
 *
 * @param int   $data['employee_id'] Mitarbeiter
 * @param array $data['data']        Felder des Fragebogens
 * @testdata {"employee_id": 861, "data": {"first_name": "Max", "last_name": "Mustermann", "birth_date": "1985-04-12", "tax_id": "12345678901"}}
 */
function savePersonnelData($data) {
    hrPersonnelPermit();
    $db = DbhCompany::begin();
    $employeeId = intval($data['employee_id'] ?? 0);
    $input = is_array($data['data'] ?? null) ? $data['data'] : [];
    if ($employeeId <= 0) throw new ApiError('VALIDATION_ERROR', 'employee_id ist erforderlich');

    $columns = ['employee_id'];
    $values  = [':employee_id::int'];
    $updates = [];
    $params  = [':employee_id' => $employeeId];
    foreach (HR_DATA_FIELDS as $column => $type) {
        $value = $input[$column] ?? null;
        if ($type === 'boolean') {
            $params[':' . $column] = !empty($value) && $value !== 'false' ? 'true' : 'false';
            $values[] = ':' . $column . '::boolean';
        } else {
            $value = is_scalar($value) ? trim((string)$value) : '';
            $params[':' . $column] = $value === '' ? null : $value;
            $values[] = 'NULLIF(:' . $column . ", '')::" . $type;
        }
        $columns[] = $column;
        $updates[] = $column . ' = EXCLUDED.' . $column;
    }
    $columnList = implode(', ', $columns);
    $valueList  = implode(', ', $values);
    $updateList = implode(', ', $updates);
    $row = $db->getOne(
        "INSERT INTO hr_employee_data ($columnList) VALUES ($valueList)
         ON CONFLICT (employee_id) DO UPDATE SET $updateList, mtime = NOW()
         RETURNING row_to_json(hr_employee_data) AS result",
        $params
    );
    resultInfo(true, 'SAVED', ['results' => json_decode($row['result'], true)]);
}

/**
 * Personalfragebogen als PDF — ausgefüllt mit den gespeicherten Angaben oder
 * leer zum Ausfüllen von Hand (neuer Mitarbeiter).
 *
 * @param int  $data['employee_id'] Mitarbeiter (bei blank optional)
 * @param bool $data['blank']       Leeres Formular
 * @testdata {"employee_id": 861, "blank": false}
 */
function getPersonnelQuestionnairePdf($data) {
    hrPersonnelPermit();
    $db = DbhCompany::begin();
    $blank = !empty($data['blank']);
    $row = $db->getOne(hrQuestionnaireSql(), [':employee_id' => $blank ? 0 : intval($data['employee_id'] ?? 0)]);
    $values = json_decode($row['result'] ?? '{}', true) ?: [];
    if (!$blank && empty($values['employee_id'])) throw new ApiError('EMPLOYEE_NOT_FOUND', 'Mitarbeiter nicht gefunden');

    $pdf  = hrQuestionnairePdf($values, $blank);
    $name = 'Personalfragebogen' . ($blank ? '' : '_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', ReportPdf::de($values['full_name'] ?? ''))) . '.pdf';
    resultInfo(true, '', ['results' => ['pdf' => base64_encode($pdf), 'filename' => $name]]);
}

// ============================================================================
// DOKUMENTE DER PERSONALAKTE
// ============================================================================

/**
 * Lädt ein Dokument in die Personalakte (multipart/form-data, Feld „file").
 * Erlaubt sind PDF, Bilder (JPEG, PNG, WebP) und Textdokumente bis 25 MB.
 *
 * @param int    $data['employee_id'] Mitarbeiter
 * @param string $data['category']    Ablagefach (hr_document_categories.key)
 * @param string $data['title']       Bezeichnung (optional, sonst Dateiname)
 * @param string $data['doc_date']    Datum des Dokuments (YYYY-MM-DD, optional)
 * @param int    $data['doc_year']    Kalenderjahr (z. B. Lohnsteuerbescheinigung, optional)
 * @param string $data['notes']       Bemerkung (optional)
 * @testdata {"employee_id": 861, "category": "income_tax", "title": "Lohnsteuerbescheinigung", "doc_year": 2025}
 */
function uploadPersonnelDocument($data) {
    hrPersonnelPermit();
    $db = DbhCompany::begin();
    $employeeId = intval($data['employee_id'] ?? 0);
    $category   = trim((string)($data['category'] ?? ''));
    if ($employeeId <= 0 || $category === '') throw new ApiError('VALIDATION_ERROR', 'Mitarbeiter und Ablagefach sind erforderlich');

    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $tooLarge = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        throw new ApiError($tooLarge ? 'FILE_TOO_LARGE' : 'NO_FILE', $tooLarge ? 'Datei überschreitet die maximale Servergröße' : 'Keine Datei empfangen');
    }
    if (intval($file['size']) > HR_DOC_MAX_FILESIZE) throw new ApiError('FILE_TOO_LARGE', 'Datei ist größer als 25 MB');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext  = HR_DOC_TYPES[$mime] ?? null;
    if ($ext === null) throw new ApiError('FILE_TYPE_NOT_ALLOWED', 'Erlaubt sind PDF, JPEG, PNG, WebP, DOC, DOCX und ODT');

    $original = basename((string)$file['name']);
    $stem = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo(ReportPdf::de($original), PATHINFO_FILENAME));
    $name = hrStoreFile($employeeId, trim($stem, '_') ?: 'dokument', $ext, null, $file['tmp_name']);

    $title = trim((string)($data['title'] ?? ''));
    try {
        $row = hrInsertDocument($db, [
            ':employee_id' => $employeeId, ':category' => $category,
            ':title' => $title !== '' ? $title : pathinfo($original, PATHINFO_FILENAME),
            ':filename' => $name, ':original_name' => $original, ':filesize' => intval($file['size']),
            ':doc_date' => (string)($data['doc_date'] ?? ''), ':doc_year' => (string)($data['doc_year'] ?? ''),
            ':notes' => (string)($data['notes'] ?? ''), ':subject' => null, ':body' => null,
        ]);
    } catch (Throwable $e) {
        // Keine Zeile, keine Datei: sonst läge ein Dokument ohne Zuordnung in der Akte
        @unlink(hrPersonnelDir($employeeId) . '/' . $name);
        throw $e;
    }
    resultInfo(true, 'SAVED', ['results' => $row]);
}

/**
 * Legt eine Datei im Aktenordner ab — entweder ein hochgeladenes Original
 * ($uploadTmp) oder erzeugten Inhalt ($content). Der Name bekommt einen
 * Zeitstempel, damit nichts überschrieben wird.
 *
 * @return string Dateiname im Aktenordner
 */
function hrStoreFile(int $employeeId, string $stem, string $ext, ?string $content, ?string $uploadTmp = null): string {
    $dir = hrPersonnelDir($employeeId, true);
    if (!is_writable($dir)) throw new ApiError('DIR_NOT_WRITABLE', 'Keine Schreibrechte im Ordner der Personalakte – bitte Dateirechte prüfen');
    $base = date('Ymd-His') . '_' . substr($stem, 0, 80);
    $name = $base . '.' . $ext;
    for ($i = 2; file_exists($dir . '/' . $name) && $i < 1000; $i++) {
        $name = $base . '_' . $i . '.' . $ext;
    }
    $ok = $uploadTmp !== null
        ? move_uploaded_file($uploadTmp, $dir . '/' . $name)
        : file_put_contents($dir . '/' . $name, $content) !== false;
    if (!$ok) throw new ApiError('SAVE_FAILED', 'Datei konnte nicht gespeichert werden');
    $cfg = fmGetDirConfig();
    if (!empty($cfg['group'])) @chgrp($dir . '/' . $name, $cfg['group']);
    @chmod($dir . '/' . $name, 0660);
    return $name;
}

/**
 * Dokumentzeile anlegen; liefert sie so, wie die Akte sie anzeigt.
 */
function hrInsertDocument($db, array $params): array {
    $params[':login'] = hrActorLogin();
    return $db->getOne(
        "WITH ins AS (
            INSERT INTO hr_documents (employee_id, category, title, filename, original_name, filesize,
                                      doc_date, doc_year, notes, subject, body, created_by)
            VALUES (:employee_id::int, :category, :title, :filename, :original_name, :filesize::int,
                    NULLIF(:doc_date, '')::date, NULLIF(:doc_year, '')::smallint, NULLIF(:notes, ''),
                    :subject, :body, (SELECT id FROM employee WHERE login = :login))
            RETURNING *
         ) " . hrDocumentSelectSql('TRUE', 'ins'),
        $params
    );
}

/**
 * Ändert Ablagefach, Bezeichnung, Datum, Jahr und Bemerkung eines Dokuments.
 *
 * @param int    $data['id']       Dokument
 * @param string $data['category'] Ablagefach
 * @param string $data['title']    Bezeichnung
 * @param string $data['doc_date'] Datum (YYYY-MM-DD, optional)
 * @param int    $data['doc_year'] Kalenderjahr (optional)
 * @param string $data['notes']    Bemerkung (optional)
 * @testdata {"id": 1, "category": "income_tax", "title": "Lohnsteuerbescheinigung 2025", "doc_year": 2025}
 */
function updatePersonnelDocument($data) {
    hrPersonnelPermit();
    $db = DbhCompany::begin();
    $title = trim((string)($data['title'] ?? ''));
    $category = trim((string)($data['category'] ?? ''));
    if ($title === '' || $category === '') throw new ApiError('VALIDATION_ERROR', 'Bezeichnung und Ablagefach sind erforderlich');
    $row = $db->getOne(
        "WITH upd AS (
            UPDATE hr_documents
               SET category = :category, title = :title, doc_date = NULLIF(:doc_date, '')::date,
                   doc_year = NULLIF(:doc_year, '')::smallint, notes = NULLIF(:notes, ''), mtime = NOW()
             WHERE id = :id::int
            RETURNING *
         ) " . hrDocumentSelectSql('TRUE', 'upd'),
        [
            ':id' => intval($data['id'] ?? 0), ':category' => $category, ':title' => $title,
            ':doc_date' => (string)($data['doc_date'] ?? ''), ':doc_year' => (string)($data['doc_year'] ?? ''),
            ':notes' => (string)($data['notes'] ?? ''),
        ]
    );
    if (!$row) throw new ApiError('DOCUMENT_NOT_FOUND', 'Dokument nicht gefunden');
    resultInfo(true, 'SAVED', ['results' => $row]);
}

/**
 * Löscht ein Dokument aus der Personalakte (Zeile und Datei).
 *
 * @param int $data['id'] Dokument
 * @testdata {"id": 1}
 */
function deletePersonnelDocument($data) {
    hrPersonnelPermit();
    $db = DbhCompany::begin();
    $row = $db->getOne(
        "DELETE FROM hr_documents WHERE id = :id::int RETURNING employee_id, filename",
        [':id' => intval($data['id'] ?? 0)]
    );
    if (!$row) throw new ApiError('DOCUMENT_NOT_FOUND', 'Dokument nicht gefunden');
    @unlink(hrPersonnelDir(intval($row['employee_id'])) . '/' . basename($row['filename']));
    resultInfo(true, 'DELETED');
}

/**
 * Streamt ein Dokument der Personalakte (binär), per GET im neuen Tab nutzbar.
 *
 * @param int $data['id']       Dokument
 * @param int $data['download'] 1 = als Download statt Anzeige
 * @testdata {"id": 1}
 */
function getPersonnelDocument($data) {
    hrPersonnelPermit();
    $db = DbhCompany::begin();
    $row = $db->getOne(
        "SELECT employee_id, filename, title FROM hr_documents WHERE id = :id::int",
        [':id' => intval($data['id'] ?? 0)]
    );
    $abs = $row ? hrPersonnelDir(intval($row['employee_id'])) . '/' . basename($row['filename']) : '';
    if (!$row || !is_file($abs)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Dokument nicht gefunden';
        exit;
    }
    $ext  = pathinfo($abs, PATHINFO_EXTENSION);
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', ReportPdf::de($row['title'])) . '.' . $ext;
    header('Content-Type: ' . fmGetMimeType(basename($abs)));
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: ' . (!empty($data['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    header('Cache-Control: private, no-store');
    readfile($abs);
    exit;
}

// ============================================================================
// VORLAGEN FÜR PERSONALSCHREIBEN
// ============================================================================

/**
 * Vorlage anlegen oder ändern. Wird eine mitgelieferte Vorlage geändert
 * (default_key ohne id), entsteht eine Firmenfassung, die sie überschreibt.
 *
 * @param int    $data['id']             Vorhandene Firmenvorlage (optional)
 * @param string $data['default_key']    Schlüssel der mitgelieferten Vorlage (optional)
 * @param string $data['category']       Ablagefach, in dem das Schreiben landet
 * @param string $data['name']           Anzeigename
 * @param string $data['subject']        Betreff
 * @param string $data['body']           Text (HTML mit Platzhaltern)
 * @param string $data['signature_mode'] letter | contract
 * @param bool   $data['receipt']        Empfangsbestätigung anfügen
 * @testdata {"category": "warning", "name": "Abmahnung Unpünktlichkeit", "subject": "Abmahnung", "body": "<p>Sehr geehrte …</p>", "signature_mode": "letter", "receipt": true}
 */
function savePersonnelTemplate($data) {
    hrPersonnelPermit();
    $db   = DbhCompany::begin();
    $name = trim((string)($data['name'] ?? ''));
    $category = trim((string)($data['category'] ?? ''));
    if ($name === '' || $category === '') throw new ApiError('VALIDATION_ERROR', 'Name und Ablagefach sind erforderlich');
    $row = $db->getOne(<<<SQL
        INSERT INTO hr_document_templates AS t (id, default_key, category, name, subject, body, signature_mode, receipt, employee_id)
        OVERRIDING SYSTEM VALUE
        VALUES (COALESCE(NULLIF(:id::int, 0), nextval(pg_get_serial_sequence('hr_document_templates', 'id'))),
                NULLIF(:default_key, ''), :category, :name, :subject, :body, :signature_mode, :receipt::boolean,
                (SELECT id FROM employee WHERE login = :login))
        ON CONFLICT (id) DO UPDATE
            SET category = EXCLUDED.category, name = EXCLUDED.name, subject = EXCLUDED.subject, body = EXCLUDED.body,
                signature_mode = EXCLUDED.signature_mode, receipt = EXCLUDED.receipt, mtime = NOW()
        RETURNING id
    SQL, [
        ':id' => intval($data['id'] ?? 0), ':default_key' => (string)($data['default_key'] ?? ''),
        ':category' => $category, ':name' => $name,
        ':subject' => (string)($data['subject'] ?? ''), ':body' => (string)($data['body'] ?? ''),
        ':signature_mode' => ($data['signature_mode'] ?? '') === 'contract' ? 'contract' : 'letter',
        ':receipt' => !empty($data['receipt']) ? 'true' : 'false',
        ':login' => hrActorLogin(),
    ]);
    resultInfo(true, 'SAVED', ['results' => ['id' => intval($row['id'])]]);
}

/**
 * Firmenvorlage löschen. War sie die angepasste Fassung einer mitgelieferten
 * Vorlage, gilt danach wieder das Original.
 *
 * @param int $data['id'] Vorlage
 * @testdata {"id": 1}
 */
function deletePersonnelTemplate($data) {
    hrPersonnelPermit();
    DbhCompany::begin()->execute("DELETE FROM hr_document_templates WHERE id = :id::int", [':id' => intval($data['id'] ?? 0)]);
    resultInfo(true, 'DELETED');
}

// ============================================================================
// PERSONALSCHREIBEN
// ============================================================================

/**
 * Werte für Platzhalter und Anschrift eines Personalschreibens: Mitarbeiter
 * (Fragebogen, sonst Benutzername), Firma, Unterzeichner. Anrede, Namen und
 * Datumsformat entstehen in SQL.
 *
 * @return array Platzhalter → Wert
 */
function hrLetterValues($db, int $employeeId): array {
    $row = $db->getOne(<<<SQL
        WITH p AS (SELECT :employee_id::int AS employee_id, :login::text AS login),
        n AS (
            SELECT e.id AS employee_id,
                   COALESCE(NULLIF(trim(COALESCE(d.first_name, '') || ' ' || COALESCE(d.last_name, '')), ''), e.name, '') AS full_name,
                   COALESCE(d.first_name, '') AS first_name,
                   COALESCE(NULLIF(d.last_name, ''), regexp_replace(trim(COALESCE(e.name, '')), '^.*\s', '')) AS last_name,
                   CASE d.salutation WHEN 'mr' THEN 'Herr' WHEN 'mrs' THEN 'Frau' ELSE '' END AS salutation,
                   d.street, d.zipcode, d.city, d.country, d.birth_date, d.personnel_number, d.job_title, d.entry_date
            FROM employee e
            LEFT JOIN hr_employee_data d ON d.employee_id = e.id, p
            WHERE e.id = p.employee_id
        )
        SELECT json_build_object(
            'employee_id', n.employee_id,
            'greeting', CASE n.salutation
                            WHEN 'Herr' THEN 'Sehr geehrter Herr ' || n.last_name || ','
                            WHEN 'Frau' THEN 'Sehr geehrte Frau ' || n.last_name || ','
                            ELSE 'Guten Tag ' || n.full_name || ',' END,
            'salutation', n.salutation,
            'full_name', n.full_name,
            'first_name', n.first_name,
            'last_name', n.last_name,
            'street', COALESCE(n.street, ''),
            'zipcode', COALESCE(n.zipcode, ''),
            'city', COALESCE(n.city, ''),
            'country', COALESCE(n.country, ''),
            'birth_date', COALESCE(to_char(n.birth_date, 'DD.MM.YYYY'), ''),
            'personnel_number', COALESCE(n.personnel_number, ''),
            'job_title', COALESCE(n.job_title, ''),
            'entry_date', COALESCE(to_char(n.entry_date, 'DD.MM.YYYY'), '[Eintrittsdatum]'),
            'date', to_char(CURRENT_DATE, 'DD.MM.YYYY'),
            'company', COALESCE((SELECT company FROM defaults LIMIT 1), ''),
            'company_street', COALESCE((SELECT address_street1 FROM defaults LIMIT 1), ''),
            'company_zipcode', COALESCE((SELECT address_zipcode FROM defaults LIMIT 1), ''),
            'company_city', COALESCE((SELECT address_city FROM defaults LIMIT 1), ''),
            'signer_name', COALESCE((SELECT s.name FROM employee s, p WHERE s.login = p.login), ''),
            'template_set', (SELECT templates FROM defaults LIMIT 1)
        ) AS result
        FROM n
    SQL, [':employee_id' => $employeeId, ':login' => hrActorLogin()]);
    $values = $row ? json_decode($row['result'], true) : null;
    if (!$values) throw new ApiError('EMPLOYEE_NOT_FOUND', 'Mitarbeiter nicht gefunden');
    return $values;
}

/**
 * Platzhalter ersetzen — als Baustein des Editors
 * (<span data-placeholder="key">Label</span>) und als <%key%>.
 */
function hrFillPlaceholders(string $text, array $values): string {
    foreach ($values as $key => $value) {
        if (!is_scalar($value) && $value !== null) continue;
        $safe = htmlspecialchars((string)$value, ENT_NOQUOTES);
        $text = preg_replace('/<span[^>]*data-placeholder="' . preg_quote($key, '/') . '"[^>]*>.*?<\/span>/su', $safe, $text);
        $text = str_replace(['<%' . $key . '%>', '&lt;%' . $key . '%&gt;'], $safe, $text);
    }
    // Unbekannte Bausteine nicht als Label drucken
    return preg_replace('/<span[^>]*data-placeholder="[^"]*"[^>]*>.*?<\/span>/su', '', $text);
}

/**
 * Entwurf eines Personalschreibens: Vorlage mit eingesetzten Angaben des
 * Mitarbeiters. Der Benutzer ergänzt im Editor Sachverhalt, Termine und
 * Beträge (Stellen in eckigen Klammern) und legt das Schreiben dann ab.
 *
 * @param int    $data['employee_id'] Mitarbeiter
 * @param int    $data['template_id'] Firmenvorlage (optional)
 * @param string $data['default_key'] Mitgelieferte Vorlage (optional)
 * @testdata {"employee_id": 861, "default_key": "warning"}
 */
function getPersonnelLetterDraft($data) {
    hrPersonnelPermit();
    $db = DbhCompany::begin();
    $values = hrLetterValues($db, intval($data['employee_id'] ?? 0));
    $tpl = $db->getOne(<<<SQL
        WITH p AS (SELECT NULLIF(:template_id::int, 0) AS template_id, NULLIF(:default_key, '') AS default_key)
        SELECT t.category, t.name, t.subject, t.body, t.signature_mode, t.receipt
        FROM hr_document_templates t, p WHERE t.id = p.template_id
        UNION ALL
        SELECT d.category, d.name, d.subject, d.body, d.signature_mode, d.receipt
        FROM hr_document_template_defaults d, p WHERE p.template_id IS NULL AND d.key = p.default_key
        LIMIT 1
    SQL, [':template_id' => intval($data['template_id'] ?? 0), ':default_key' => (string)($data['default_key'] ?? '')]);
    if (!$tpl) throw new ApiError('TEMPLATE_NOT_FOUND', 'Vorlage nicht gefunden');

    resultInfo(true, '', ['results' => [
        'category'       => $tpl['category'],
        'title'          => $tpl['name'],
        'subject'        => strip_tags(hrFillPlaceholders((string)$tpl['subject'], $values)),
        'body'           => hrFillPlaceholders((string)$tpl['body'], $values),
        'signature_mode' => $tpl['signature_mode'],
        'receipt'        => !empty($tpl['receipt']),
        'date'           => date('Y-m-d'),
        'address_missing' => $values['street'] === '' || $values['city'] === '',
    ]]);
}

/**
 * Personalschreiben als PDF rendern (Vorlage hr_document.tex im Vorlagensatz
 * der Firma, sonst die Master-Vorlage).
 *
 * @param array $letter  subject, body, date, signature_mode, receipt
 * @param bool  $preview Aufdruck VORSCHAU
 * @return string PDF-Inhalt
 */
function hrLetterRenderPdf($db, array $values, array $letter, bool $preview): string {
    $date = !empty($letter['date']) && strtotime($letter['date']) ? date('d.m.Y', strtotime($letter['date'])) : date('d.m.Y');
    $contract = ($letter['signature_mode'] ?? '') === 'contract';
    $variables = [
        'language_code'    => 'DE',
        'currency'         => 'EUR',
        'media'            => 'printer',
        'employee_company' => $values['company'],
        'template_meta'    => ['language' => ['template_code' => 'DE'], 'formname' => 'hr_document'],
        'formname'         => 'hr_document',
        'letter_date'      => $date,
        'subject'          => strip_tags(hrFillPlaceholders((string)($letter['subject'] ?? ''), $values)),
        'body'             => hrFillPlaceholders((string)($letter['body'] ?? ''), $values),
        'name'             => trim($values['salutation'] . ' ' . $values['full_name']),
        'street'           => $values['street'],
        'zipcode'          => $values['zipcode'],
        'city'             => $values['city'],
        'country'          => $values['country'],
        'personnel_number' => $values['personnel_number'],
        'signer_name'      => $values['signer_name'],
        'company'          => $values['company'],
        'full_name'        => $values['full_name'],
        'contract'         => $contract ? '1' : '',
        'letter_mode'      => $contract ? '' : '1',
        'receipt'          => !empty($letter['receipt']) ? '1' : '',
        'preview'          => $preview ? '1' : '',
    ];

    $templateSet = $values['template_set'] ?? null;
    if (!$templateSet || resolveTemplateDir($templateSet) === false) {
        $templateSet = getTemplateSet($db);
    }
    $templateDir = getTemplateDir($templateSet);
    $source = null;
    foreach ([$templateDir . '/hr_document.tex', PRINT_MASTER_BASE . '/RB/hr_document.tex'] as $file) {
        if (is_file($file)) { $source = file_get_contents($file); break; }
    }
    if ($source === null) throw new ApiError('TEMPLATE_NOT_FOUND', 'Keine Vorlage für Personalschreiben (hr_document.tex) gefunden');

    $engine = new LaTeXTemplateEngine($templateDir);
    $engine->setVariables($variables);
    $latex = $engine->parseString($source);
    if ($latex === false) throw new ApiError('PDF_ERROR', 'PDF konnte nicht erzeugt werden: ' . $engine->getError());
    $pdfPath = $engine->compile($latex);
    if ($pdfPath === false) throw new ApiError('PDF_ERROR', 'PDF konnte nicht erzeugt werden: ' . $engine->getError());
    $pdf = file_get_contents($pdfPath);
    $engine->cleanup($pdfPath);
    return $pdf;
}

/**
 * Vorschau eines Personalschreibens als PDF (wird nicht gespeichert).
 *
 * @param int   $data['employee_id'] Mitarbeiter
 * @param array $data['letter']      subject, body, date, signature_mode, receipt
 * @testdata {"employee_id": 861, "letter": {"subject": "Abmahnung", "body": "<p>Sehr geehrter Herr Mustermann,</p><p>…</p>", "signature_mode": "letter", "receipt": true}}
 */
function getPersonnelLetterPdf($data) {
    hrPersonnelPermit();
    $db = DbhCompany::begin();
    $letter = is_array($data['letter'] ?? null) ? $data['letter'] : [];
    $pdf = hrLetterRenderPdf($db, hrLetterValues($db, intval($data['employee_id'] ?? 0)), $letter, true);
    resultInfo(true, '', ['results' => ['pdf' => base64_encode($pdf), 'filename' => 'Vorschau.pdf']]);
}

/**
 * Personalschreiben erzeugen und in der Personalakte ablegen: das PDF als
 * Datei, Betreff und Text in der Dokumentzeile (für spätere Nachvollziehbarkeit).
 *
 * @param int    $data['employee_id'] Mitarbeiter
 * @param string $data['category']    Ablagefach
 * @param string $data['title']       Bezeichnung in der Akte
 * @param array  $data['letter']      subject, body, date, signature_mode, receipt
 * @testdata {"employee_id": 861, "category": "warning", "title": "Abmahnung", "letter": {"subject": "Abmahnung", "body": "<p>…</p>", "date": "2026-11-17", "signature_mode": "letter", "receipt": true}}
 */
function savePersonnelLetter($data) {
    hrPersonnelPermit();
    $db = DbhCompany::begin();
    $employeeId = intval($data['employee_id'] ?? 0);
    $category   = trim((string)($data['category'] ?? ''));
    $title      = trim((string)($data['title'] ?? ''));
    $letter     = is_array($data['letter'] ?? null) ? $data['letter'] : [];
    if ($category === '' || $title === '') throw new ApiError('VALIDATION_ERROR', 'Ablagefach und Bezeichnung sind erforderlich');

    $values = hrLetterValues($db, $employeeId);
    $pdf    = hrLetterRenderPdf($db, $values, $letter, false);
    $stem   = trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', ReportPdf::de($title)), '_') ?: 'schreiben';
    $name   = hrStoreFile($employeeId, $stem, 'pdf', $pdf);
    $date   = !empty($letter['date']) && strtotime($letter['date']) ? date('Y-m-d', strtotime($letter['date'])) : date('Y-m-d');
    try {
        $row = hrInsertDocument($db, [
            ':employee_id' => $employeeId, ':category' => $category, ':title' => $title,
            ':filename' => $name, ':original_name' => $name, ':filesize' => strlen($pdf),
            ':doc_date' => $date, ':doc_year' => '', ':notes' => '',
            ':subject' => strip_tags(hrFillPlaceholders((string)($letter['subject'] ?? ''), $values)),
            ':body' => hrFillPlaceholders((string)($letter['body'] ?? ''), $values),
        ]);
    } catch (Throwable $e) {
        @unlink(hrPersonnelDir($employeeId) . '/' . $name);
        throw $e;
    }
    resultInfo(true, 'SAVED', ['results' => ['document' => $row, 'pdf' => base64_encode($pdf), 'filename' => $name]]);
}
