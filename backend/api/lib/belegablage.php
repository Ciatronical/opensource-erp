<?php
// backend/api/lib/belegablage.php
//
// Ablage von Buchhaltungsbelegen: schreiben, schützen, protokollieren.
//
// Vier Stellen legen Belege ab (Beleg-Upload, Kassenbeleg, Bankabstimmung,
// Kartenabrechnung). Ohne einen gemeinsamen Baustein hätte jede ihre eigene
// Vorstellung davon, ob eine Datei schreibgeschützt wird und wer den Zugriff
// protokolliert — genau so entstehen Lücken, die bei einer Prüfung auffallen.

/**
 * Belegdatei schreiben und gegen Überschreiben sperren.
 *
 * Der Schreibschutz (0444) ist kein Ersatz für ein Archivsystem: wer Root-Rechte
 * auf dem Server hat, kommt daran vorbei. Er verhindert aber, dass die Anwendung
 * selbst oder ein unbedachtes Skript einen abgelegten Beleg überschreibt — und
 * genau das ist der Fall, der im Alltag vorkommt.
 *
 * @param string $absoluterPfad Zielpfad
 * @param string $inhalt        Dateiinhalt
 * @return bool true bei Erfolg
 */
function belegSchreiben($absoluterPfad, $inhalt) {
    // Eine bereits abgelegte Datei wird nie überschrieben — Belege sind
    // unveränderlich. Gleicher Inhalt landet ohnehin nie zweimal hier, das
    // fängt die Duplikaterkennung über den SHA-256-Hash vorher ab.
    if (file_exists($absoluterPfad)) return false;

    if (file_put_contents($absoluterPfad, $inhalt) === false) return false;
    @chmod($absoluterPfad, 0444);
    return true;
}

/**
 * Ende der Aufbewahrungsfrist für einen heute abgelegten Beleg.
 *
 * Die Dauer steht in defaults_oserp unter `beleg_aufbewahrung_jahre`. Ohne
 * Eintrag gelten 10 Jahre — bewusst der längere der beiden gängigen Werte.
 * Für Buchungsbelege wurde die Frist zwar verkürzt, für andere Unterlagen nicht;
 * welche Frist im Einzelfall greift, gehört zum Steuerberater und nicht in eine
 * fest verdrahtete Zahl.
 *
 * @param object $db Datenbankverbindung
 * @return string Datum als YYYY-MM-DD
 */
function belegAufbewahrungBis($db) {
    $row   = $db->getOne("SELECT value FROM defaults_oserp WHERE key = 'beleg_aufbewahrung_jahre'");
    $jahre = intval($row['value'] ?? 0);
    if ($jahre < 1 || $jahre > 30) $jahre = 10;
    return date('Y-m-d', strtotime("+{$jahre} years"));
}

/**
 * Ablage abschliessen: Pfad eintragen und die Aufbewahrungsfrist setzen.
 *
 * Die Spalte retention_until kommt erst mit dem Schema-Update in die Datenbank.
 * Zwischen dem Ausrollen des Codes und dem Update-Lauf gäbe es sonst ein
 * Zeitfenster, in dem jeder Beleg-Upload an einer fehlenden Spalte scheitert —
 * für den Anwender ein kaputtes Programm, obwohl nur eine Migration fehlt.
 * Deshalb wird die Spalte geprüft, genau wie es das Kassenmodul bei seinen
 * optionalen Tabellen hält.
 *
 * @param object $db         Datenbankverbindung
 * @param int    $docId      Beleg
 * @param string $storedPath Ablagepfad, relativ zum Mandantenverzeichnis
 */
function belegAblageEintragen($db, $docId, $storedPath) {
    static $hatFrist = null;
    if ($hatFrist === null) {
        $r = $db->getOne(
            "SELECT 1 AS ok FROM information_schema.columns
             WHERE table_name = 'accounting_documents' AND column_name = 'retention_until' LIMIT 1"
        );
        $hatFrist = (bool)$r;
    }

    if ($hatFrist) {
        $db->execute(
            "UPDATE accounting_documents SET stored_path = :path, retention_until = :bis WHERE id = :id",
            ['path' => $storedPath, 'bis' => belegAufbewahrungBis($db), 'id' => intval($docId)]
        );
        return;
    }

    $db->execute(
        "UPDATE accounting_documents SET stored_path = :path WHERE id = :id",
        ['path' => $storedPath, 'id' => intval($docId)]
    );
}

/**
 * Zugriff auf einen Beleg protokollieren.
 *
 * Schlägt bewusst nie fehl: ein Protokollfehler darf weder eine Buchung noch
 * eine Belegansicht verhindern. Fehlt die Tabelle (ältere Datenbank), passiert
 * schlicht nichts.
 *
 * @param object      $db         Datenbankverbindung
 * @param int         $documentId Beleg
 * @param int|null    $employeeId Wer
 * @param string      $aktion     ablage|ansicht|pruefung|verknuepfung
 * @param string|null $ergebnis   bei Prüfungen: ok|geaendert|fehlt
 * @param string|null $hinweis    Freitext
 */
function belegProtokoll($db, $documentId, $employeeId, $aktion, $ergebnis = null, $hinweis = null) {
    if (intval($documentId) <= 0) return;
    try {
        $vorhanden = $db->getOne("SELECT to_regclass('public.accounting_document_log') AS t");
        if (empty($vorhanden['t'])) return;

        $db->execute(
            "INSERT INTO accounting_document_log (document_id, employee_id, aktion, ergebnis, hinweis)
             VALUES (:doc, :eid, :aktion, :ergebnis, :hinweis)",
            [
                'doc'      => intval($documentId),
                'eid'      => $employeeId ? intval($employeeId) : null,
                'aktion'   => $aktion,
                'ergebnis' => $ergebnis,
                'hinweis'  => $hinweis,
            ]
        );
    } catch (\Throwable $e) {
        // Absichtlich still: siehe Funktionskommentar.
    }
}

/**
 * Ausgangsrechnung archivieren.
 *
 * Bis hierher wurde keine einzige versendete Rechnung aufbewahrt: sie entstand
 * bei jedem Aufruf neu aus Daten und Vorlage. Das trägt nur, solange beides
 * unverändert ist — ändert jemand die Rechnungsvorlage, sieht ein Nachdruck von
 * 2024 anders aus als das, was der Kunde damals bekommen hat, und niemand merkt
 * es. Deshalb wird beim Drucken eine unveränderliche Kopie abgelegt.
 *
 * Wann eine neue Fassung entsteht: Es wird archiviert, wenn es noch keine Kopie
 * gibt, die **nach** der letzten Änderung der Rechnung entstanden ist. Ein
 * zweiter Druck derselben Rechnung legt also nichts Neues an; wird die Rechnung
 * geändert und erneut gedruckt, kommt eine zweite Fassung dazu. Genau das ist
 * die Historie, die eine Prüfung sehen will.
 *
 * Bewusst NICHT über den Hash abgegrenzt: LaTeX schreibt einen Zeitstempel ins
 * PDF, zwei Drucke derselben Rechnung sind daher nie bytegleich. Eine
 * Hash-Prüfung würde bei jedem Druck eine neue Fassung anlegen.
 *
 * @param object   $db          Datenbankverbindung
 * @param int      $arId        ar.id der Ausgangsrechnung
 * @param string   $fakturaType Belegart; archiviert wird nur 'invoice'
 * @param string   $pdfInhalt   PDF-Bytes
 * @param string   $dateiname   Vorgeschlagener Dateiname
 * @param int|null $employeeId  Wer gedruckt hat
 * @return int|null Beleg-ID der neuen Fassung, null wenn nichts abgelegt wurde
 */
function ausgangsrechnungArchivieren($db, $arId, $fakturaType, $pdfInhalt, $dateiname, $employeeId = null) {
    if ($fakturaType !== 'invoice' || intval($arId) <= 0 || $pdfInhalt === '') return null;

    // Nur gebuchte Rechnungen. Ein Entwurf, der noch nicht in ar steht, ist
    // kein Beleg — und ein Fremdschluessel auf eine fehlende Zeile schlaegt fehl.
    $rechnung = $db->getOne(
        "SELECT id, invnumber, COALESCE(mtime, itime) AS stand FROM ar WHERE id = :id",
        ['id' => intval($arId)]
    );
    if (!$rechnung) return null;

    // Die Spalte kommt erst mit dem Schema-Update. Ohne sie wird nicht
    // archiviert, statt bei jedem Druck in einen Fehler zu laufen.
    $spalte = $db->getOne(
        "SELECT 1 AS ok FROM information_schema.columns
         WHERE table_name = 'accounting_documents' AND column_name = 'ar_id' LIMIT 1"
    );
    if (!$spalte) return null;

    $aktuell = $db->getOne(
        "SELECT id FROM accounting_documents
         WHERE ar_id = :ar AND stored_path IS NOT NULL AND itime >= :stand
         LIMIT 1",
        ['ar' => intval($arId), 'stand' => $rechnung['stand']]
    );
    if ($aktuell) return null;   // seit der letzten Änderung schon archiviert

    $name = trim((string)$dateiname) ?: ('rechnung_' . $rechnung['invnumber'] . '.pdf');
    $hash = hash('sha256', $pdfInhalt);

    $doc = $db->getOne(<<<SQL
        INSERT INTO accounting_documents
            (original_name, mime_type, file_size, file_hash, status, employee_id, ar_id, notes)
        VALUES (:name, 'application/pdf', :size, :hash, 'booked', :eid, :ar, :notiz)
        RETURNING id
    SQL, [
        'name'  => $name,
        'size'  => strlen($pdfInhalt),
        'hash'  => $hash,
        'eid'   => $employeeId ? intval($employeeId) : null,
        'ar'    => intval($arId),
        'notiz' => 'Ausgangsrechnung ' . $rechnung['invnumber'] . ', Versandexemplar',
    ]);
    $docId = intval($doc['id']);

    $verzeichnis = fmDataDir() . '/accounting';
    if (!is_dir($verzeichnis)) mkdir($verzeichnis, 0755, true);

    $sicher = preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
    $pfad   = "accounting/{$docId}_{$sicher}";
    if (!belegSchreiben(fmDataDir() . '/' . $pfad, $pdfInhalt)) {
        $db->execute("DELETE FROM accounting_documents WHERE id = :id", ['id' => $docId]);
        return null;
    }

    belegAblageEintragen($db, $docId, $pfad);
    belegProtokoll($db, $docId, $employeeId, 'ablage', null, 'Ausgangsrechnung ' . $rechnung['invnumber']);
    return $docId;
}
