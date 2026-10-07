<?php
// backend/api/banking/bank_documents.php
//
// Belege direkt an einem Bankumsatz — beliebig viele, auch nachträglich.
//
// Belege zu Eingangs- und Ausgangsrechnungen hängen an der Rechnung
// (accounting_documents.ap_id / ar_id) und erscheinen hier nur mit, damit der
// Anwender an einer Stelle alles sieht, was zu einem Umsatz gehört. Alles
// andere — Gebührenabrechnung der Bank, Kontoauszugsseite, Vertrag, Mahnung,
// Lieferschein — liegt in bank_transaction_documents direkt am Umsatz.
//
// Belege werden nie gelöscht, nur die Verknüpfung wird gelöst: Was einmal in
// der Ablage liegt, bleibt dort bis zum Ende der Aufbewahrungsfrist (GoBD).

/**
 * Alle Belege zu einem Bankumsatz in einer Abfrage.
 *
 * Zwei Quellen in einer UNION: direkt verknüpfte Belege (source = 'direct',
 * lösbar) und Belege der zugeordneten Rechnungen (source = 'ap' | 'ar', nur
 * Anzeige). Zugeordnet heißt: gebucht (bank_transaction_acc_trans) oder
 * vorgemerkt (bank_transaction_matches).
 *
 * @param object $db   Datenbankverbindung
 * @param int    $btId Bankumsatz
 * @return array Liste der Belege
 */
function _btDocumentsList($db, $btId) {
    $row = $db->getOne(<<<SQL
        SELECT COALESCE(json_agg(d ORDER BY d.source = 'direct' DESC, d.itime DESC), '[]'::json) AS docs
        FROM (
            SELECT ad.id, ad.original_name, ad.mime_type, ad.file_size, ad.itime,
                   e.name AS employee_name, 'direct' AS source, NULL::text AS invnumber
            FROM bank_transaction_documents btd
            JOIN accounting_documents ad ON ad.id = btd.document_id
            LEFT JOIN employee e ON e.id = ad.employee_id
            WHERE btd.bank_transaction_id = :id
              AND ad.stored_path IS NOT NULL
            UNION ALL
            SELECT ad.id, ad.original_name, ad.mime_type, ad.file_size, ad.itime,
                   e.name AS employee_name,
                   CASE WHEN ad.ap_id IS NOT NULL THEN 'ap' ELSE 'ar' END AS source,
                   COALESCE(ap.invnumber, ar.invnumber) AS invnumber
            FROM (
                SELECT ap_id, ar_id FROM bank_transaction_acc_trans WHERE bank_transaction_id = :id
                UNION
                SELECT CASE WHEN target_type = 'ap' THEN target_id END,
                       CASE WHEN target_type = 'ar' THEN target_id END
                FROM bank_transaction_matches WHERE bank_transaction_id = :id
            ) a
            JOIN accounting_documents ad
              ON (a.ap_id IS NOT NULL AND ad.ap_id = a.ap_id)
              OR (a.ar_id IS NOT NULL AND ad.ar_id = a.ar_id)
            LEFT JOIN ap ON ap.id = ad.ap_id
            LEFT JOIN ar ON ar.id = ad.ar_id
            LEFT JOIN employee e ON e.id = ad.employee_id
            WHERE ad.stored_path IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM bank_transaction_documents x
                  WHERE x.bank_transaction_id = :id AND x.document_id = ad.id
              )
        ) d
    SQL, ['id' => intval($btId)]);

    $docs = json_decode($row['docs'] ?? '[]', true) ?: [];
    // Ein Beleg kann an AP *und* direkt hängen — die UNION fängt das über
    // NOT EXISTS ab; derselbe AP-Beleg zweimal (gebucht + vorgemerkt) nicht.
    $seen = [];
    $out  = [];
    foreach ($docs as $d) {
        if (isset($seen[$d['id']])) continue;
        $seen[$d['id']] = true;
        $out[] = $d;
    }
    return $out;
}

/**
 * Belege zu einem Bankumsatz auflisten
 *
 * @param int $data['bank_transaction_id'] Bankumsatz
 * @testdata {"bank_transaction_id": 1}
 */
function getBankTransactionDocuments($data) {
    $db   = DbhCompany::begin();
    $btId = intval($data['bank_transaction_id'] ?? 0);
    if ($btId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Kein Bankumsatz angegeben'); return; }

    resultInfo(true, '', ['documents' => _btDocumentsList($db, $btId)]);
}

/**
 * Einen oder mehrere Belege hochladen und direkt an den Bankumsatz hängen.
 *
 * Jede Datei wird einzeln abgelegt (Duplikaterkennung über SHA-256 in
 * storeAccountingDocument). Scheitert eine Datei, gehen die anderen trotzdem
 * durch; die Fehler kommen gesammelt zurück. Antwort enthält gleich die
 * aktualisierte Belegliste, damit die Oberfläche nicht noch einmal fragen muss.
 *
 * @param int   $data['bank_transaction_id'] Bankumsatz
 * @param array $data['documents']           [{filename, mime_type, file_base64}, ...]
 * @testdata {"bank_transaction_id": 1, "documents": [{"filename": "beleg.pdf", "mime_type": "application/pdf", "file_base64": ""}]}
 */
function uploadBankTransactionDocuments($data) {
    $db   = DbhCompany::begin();
    $btId = intval($data['bank_transaction_id'] ?? 0);
    $docs = is_array($data['documents'] ?? null) ? $data['documents'] : [];
    if ($btId <= 0)   { resultInfo(false, 'VALIDATION_ERROR', 'Kein Bankumsatz angegeben'); return; }
    if (!count($docs)) { resultInfo(false, 'VALIDATION_ERROR', 'Keine Dateien übergeben'); return; }

    $bt = $db->getOne("SELECT id FROM bank_transactions WHERE id = :id", ['id' => $btId]);
    if (!$bt) { resultInfo(false, 'DATA_NOT_FOUND', 'Bankumsatz nicht gefunden'); return; }

    $employeeId = mitarbeiterId($data);
    $stored     = 0;
    $errors     = [];

    foreach ($docs as $doc) {
        $name = trim((string)($doc['filename'] ?? ''));
        $res  = storeAccountingDocument(
            $db,
            $name ?: 'beleg.pdf',
            $doc['mime_type'] ?? 'application/octet-stream',
            $doc['file_base64'] ?? '',
            null,
            $employeeId
        );
        if (!$res['ok']) {
            $errors[] = ($name ?: '?') . ': ' . $res['error'];
            continue;
        }
        $db->execute(
            "INSERT INTO bank_transaction_documents (bank_transaction_id, document_id)
             VALUES (:bt, :doc)
             ON CONFLICT (bank_transaction_id, document_id) DO NOTHING",
            ['bt' => $btId, 'doc' => $res['document_id']]
        );
        belegProtokoll($db, $res['document_id'], $employeeId, 'verknuepfung', null, "Bankumsatz {$btId}");
        $stored++;
    }

    if ($stored === 0) {
        resultInfo(false, 'STORAGE_ERROR', implode('; ', $errors));
        return;
    }

    resultInfo(true, '', [
        'stored'    => $stored,
        'errors'    => $errors,
        'documents' => _btDocumentsList($db, $btId),
    ]);
}

/**
 * Verknüpfung Beleg ↔ Bankumsatz lösen. Der Beleg selbst bleibt in der Ablage.
 *
 * @param int $data['bank_transaction_id'] Bankumsatz
 * @param int $data['document_id']         Beleg
 * @testdata {"bank_transaction_id": 1, "document_id": 1}
 */
function unlinkBankTransactionDocument($data) {
    $db    = DbhCompany::begin();
    $btId  = intval($data['bank_transaction_id'] ?? 0);
    $docId = intval($data['document_id'] ?? 0);
    if ($btId <= 0 || $docId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'Bankumsatz und Beleg erforderlich'); return; }

    $db->execute(
        "DELETE FROM bank_transaction_documents WHERE bank_transaction_id = :bt AND document_id = :doc",
        ['bt' => $btId, 'doc' => $docId]
    );
    belegProtokoll($db, $docId, mitarbeiterId($data), 'verknuepfung', 'geloest', "Bankumsatz {$btId}");

    resultInfo(true, '', ['documents' => _btDocumentsList($db, $btId)]);
}

/**
 * Beleginhalt für die Vorschau (Base64). Gleiche Ablage wie die Kassenbelege,
 * deshalb derselbe Leseweg inklusive Zugriffsprotokoll.
 *
 * @param int $data['document_id'] Beleg
 * @testdata {"document_id": 1}
 */
function getBankDocumentContent($data) {
    getCashDocumentContent($data);
}
