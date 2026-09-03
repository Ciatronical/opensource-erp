<?php
// backend/api/accounting/document_check.php
//
// Belegprüfung: liest jede abgelegte Datei erneut ein, bildet den SHA-256-Hash
// und vergleicht ihn mit dem, der bei der Ablage gespeichert wurde.
//
// Der Hash lag bisher ungenutzt in der Datenbank. Er beweist nur dann etwas,
// wenn ihn jemand nachrechnet — sonst fiele eine ausgetauschte Rechnung
// niemandem auf.

/**
 * Alle abgelegten Belege gegen ihren gespeicherten Hash prüfen.
 *
 * Ergebnis je Beleg:
 *   ok        — Datei vorhanden, Hash stimmt
 *   geaendert — Datei vorhanden, Hash weicht ab (der ernste Fall)
 *   fehlt     — Eintrag in der Datenbank, Datei nicht auffindbar
 *   ohne_datei— Beleg ohne Ablagepfad (Abbruch beim Hochladen)
 *   ohne_hash — Altbestand ohne gespeicherten Hash, nicht prüfbar
 *
 * Das Einlesen kostet Zeit, deshalb ein Deckel: geprüft werden die ältesten
 * noch nicht geprüften Belege zuerst. Wie viele offen bleiben, steht im
 * Ergebnis — eine Prüfung, die stillschweigend abschneidet, wäre wertlos.
 *
 * @param int  $data['limit']       Höchstzahl geprüfter Belege (Standard 500)
 * @param bool $data['nur_fehler']  true = nur Auffälligkeiten zurückgeben
 * @testdata {"limit": 50}
 */
function checkAccountingDocuments($data) {
    $db    = DbhCompany::begin();
    $limit = max(1, min(intval($data['limit'] ?? 500), 5000));
    $nurFehler  = !empty($data['nur_fehler']);
    $mitarbeiter = mitarbeiterId($data);

    $gesamt = $db->getOne("SELECT COUNT(*)::int AS n FROM accounting_documents");
    $docs   = $db->getAll(
        "SELECT d.id, d.original_name, d.stored_path, d.file_hash, d.file_size,
                d.status, d.retention_until, d.ap_id, d.employee_id,
                TO_CHAR(d.itime, 'DD.MM.YYYY HH24:MI') AS abgelegt_am,
                e.name AS abgelegt_von,
                (SELECT COUNT(*)::int FROM cash_gl_documents cg WHERE cg.document_id = d.id) AS kassenbuchungen
         FROM accounting_documents d
         LEFT JOIN employee e ON e.id = d.employee_id
         ORDER BY d.id ASC
         LIMIT :limit",
        ['limit' => $limit]
    );

    $basis   = fmDataDir();
    $zeilen  = [];
    $zaehler = ['ok' => 0, 'geaendert' => 0, 'fehlt' => 0, 'ohne_datei' => 0, 'ohne_hash' => 0];

    foreach ($docs as $doc) {
        $pfad     = $doc['stored_path'] ? $basis . '/' . $doc['stored_path'] : null;
        $ergebnis = 'ok';
        $istHash  = null;
        $groesse  = null;

        if (!$doc['stored_path'])            $ergebnis = 'ohne_datei';
        elseif (!is_file($pfad))             $ergebnis = 'fehlt';
        elseif (empty($doc['file_hash']))    $ergebnis = 'ohne_hash';
        else {
            $istHash = hash_file('sha256', $pfad);
            $groesse = filesize($pfad);
            $ergebnis = hash_equals($doc['file_hash'], $istHash) ? 'ok' : 'geaendert';
        }

        $zaehler[$ergebnis]++;
        // Nur Auffälligkeiten protokollieren — sonst wüchse das Protokoll bei
        // jeder Prüfung um den gesamten Bestand.
        if ($ergebnis !== 'ok') {
            belegProtokoll($db, $doc['id'], $mitarbeiter, 'pruefung', $ergebnis, $doc['stored_path']);
        }

        if ($nurFehler && $ergebnis === 'ok') continue;

        $zeilen[] = [
            'id'              => intval($doc['id']),
            'name'            => $doc['original_name'],
            'ergebnis'        => $ergebnis,
            'pfad'            => $doc['stored_path'],
            'status'          => $doc['status'],
            'abgelegt_am'     => $doc['abgelegt_am'],
            'abgelegt_von'    => $doc['abgelegt_von'],
            'ohne_bearbeiter' => empty($doc['employee_id']),
            'ap_id'           => $doc['ap_id'] ? intval($doc['ap_id']) : null,
            'kassenbuchungen' => intval($doc['kassenbuchungen']),
            'verwaist'        => empty($doc['ap_id']) && intval($doc['kassenbuchungen']) === 0,
            'aufbewahrung_bis'=> $doc['retention_until'],
            'groesse_db'      => $doc['file_size'] ? intval($doc['file_size']) : null,
            'groesse_datei'   => $groesse,
            'hash_soll'       => $doc['file_hash'],
            'hash_ist'        => $istHash,
        ];
    }

    resultInfo(true, '', [
        'geprueft'      => count($docs),
        'gesamt'        => intval($gesamt['n']),
        'nicht_geprueft'=> max(intval($gesamt['n']) - count($docs), 0),
        'zaehler'       => $zaehler,
        'verzeichnis'   => $basis . '/accounting',
        'dokumente'     => $zeilen,
    ]);
}

/**
 * Protokoll eines einzelnen Belegs — wer hat ihn abgelegt, angesehen, geprüft.
 *
 * @param int $data['document_id']
 * @testdata {"document_id": 1}
 */
function getAccountingDocumentLog($data) {
    $db    = DbhCompany::begin();
    $docId = intval($data['document_id'] ?? 0);
    if ($docId <= 0) { resultInfo(false, 'VALIDATION_ERROR', 'document_id erforderlich'); return; }

    $vorhanden = $db->getOne("SELECT to_regclass('public.accounting_document_log') AS t");
    if (empty($vorhanden['t'])) { resultInfo(true, '', ['eintraege' => []]); return; }

    $rows = $db->getAll(
        "SELECT l.aktion, l.ergebnis, l.hinweis,
                TO_CHAR(l.itime, 'DD.MM.YYYY HH24:MI:SS') AS zeitpunkt,
                COALESCE(e.name, '—') AS mitarbeiter
         FROM accounting_document_log l
         LEFT JOIN employee e ON e.id = l.employee_id
         WHERE l.document_id = :id
         ORDER BY l.id DESC
         LIMIT 500",
        ['id' => $docId]
    );

    resultInfo(true, '', ['eintraege' => $rows ?: []]);
}
