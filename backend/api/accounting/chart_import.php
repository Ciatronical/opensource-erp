<?php
// backend/api/accounting/chart_import.php

/**
 * Kontenrahmen-Stammdaten (SKR03/SKR04) idempotent in eine Firmen-DB importieren.
 *
 * Quelle ist eine versionierte CSV (backend/upstall/crm/chart_master/skrNN.csv) mit den
 * Spalten: accno, description, charttype, category, link, taxkey, datevautomatik.
 *
 * Funktionsweise (bewusst additiv & DB-geerdet — es wird NICHTS geraten):
 *  - Fehlt eine Kontonummer in der Ziel-DB, wird sie als chart-Zeile eingefügt und ihre
 *    zeitabhängigen taxkeys werden NICHT erfunden, sondern von einem bestehenden Analog-Konto
 *    derselben Steuersignatur (taxkey + link) dieser DB geklont (gleiche tax_id, pos_ustva,
 *    startdate-Perioden). Nicht-Steuer-Konten (taxkey 0) bekommen die Standardzeile
 *    (tax_id 0, taxkey_id 0, startdate 1970-01-01).
 *  - Existiert die Nummer bereits, wird im Modus 'add' übersprungen. Im Modus 'fix' wird die
 *    Abweichung gegen die CSV nur PROTOKOLLIERT; eine tatsächliche Änderung erfolgt ausschließlich
 *    mit explizitem apply_corrections=true UND nur für Nummern in der Freigabeliste correction_allow.
 *
 * Wiederverwendbar aus zwei Wegen:
 *  - Firmen-Neuanlage (company.php) mit dem frischen ApiDatabase-Handle der neuen DB.
 *  - Bestands-Rollout-CLI (scripts/import-chart-master.php) pro Firmen-DB.
 *
 * @param ApiDatabase $db   Handle der ZIEL-DB (nicht zwingend die Session-Company-DB).
 * @param string      $skr  'skr03' oder 'skr04'.
 * @param array       $opts {
 *   @type string $mode               'add' (nur ergänzen, Default) oder 'fix' (zusätzl. Korrektur-Diff).
 *   @type bool   $dry_run            true = nichts schreiben, nur Report (Default false).
 *   @type bool   $apply_corrections  true = freigegebene Korrekturen wirklich anwenden (Default false).
 *   @type array  $correction_allow   Liste von accno, die korrigiert werden dürfen (Whitelist).
 * }
 * @return array Report ['added'=>[], 'skipped'=>[], 'corrections'=>[], 'corrected'=>[], 'conflicts'=>[], 'summary'=>[]]
 */
function importChartMaster($db, string $skr, array $opts = []) {
    $skr = strtolower(trim($skr));
    if (!in_array($skr, ['skr03', 'skr04'], true)) {
        throw new ApiError('INVALID_PARAMETER', "Unbekannter Kontenrahmen: {$skr}");
    }

    $mode             = ($opts['mode'] ?? 'add') === 'fix' ? 'fix' : 'add';
    $dryRun           = !empty($opts['dry_run']);
    $applyCorrections = !empty($opts['apply_corrections']);
    $correctionAllow  = array_flip(array_map('strval', $opts['correction_allow'] ?? []));

    $csvFile = __DIR__ . '/../../upstall/crm/chart_master/' . $skr . '.csv';
    if (!is_file($csvFile)) {
        throw new ApiError('DATA_NOT_FOUND', "Kontenrahmen-CSV nicht gefunden: {$csvFile}");
    }

    $master = _chartReadMasterCsv($csvFile);
    if (empty($master)) {
        throw new ApiError('DATA_NOT_FOUND', "Kontenrahmen-CSV ist leer: {$csvFile}");
    }

    // Bestehende Konten der Ziel-DB indizieren (accno => Konfig).
    $existingRows = $db->getAll(
        'SELECT id, accno, description, category, link, taxkey_id, datevautomatik FROM chart'
    );
    $existing = [];
    foreach ($existingRows as $row) {
        $existing[$row['accno']] = $row;
    }

    $report = [
        'added'           => [],
        'skipped'         => [],
        'corrections'     => [],   // erkannte Konfig-Abweichungen (immer nur Vorschlag)
        'corrected'       => [],   // tatsächlich angewandte Konfig-Korrekturen
        'repaired_taxkeys'=> [],   // Bestandskonten mit fehlenden taxkeys (sicher additiv repariert)
        'conflicts'       => [],   // z.B. fehlende Steuervorlage
        'summary'         => [],
    ];

    $templateCache = [];

    $db->beginTransaction();
    try {
        foreach ($master as $m) {
            $accno = $m['accno'];

            // ── Bereits vorhanden ─────────────────────────────────────────────
            if (isset($existing[$accno])) {
                $cur  = $existing[$accno];
                $diff = _chartConfigDiff($cur, $m);

                if ($mode === 'fix' && !empty($diff)) {
                    $report['corrections'][] = ['accno' => $accno, 'diff' => $diff];

                    if ($applyCorrections && isset($correctionAllow[$accno]) && !$dryRun) {
                        $db->execute(
                            'UPDATE chart SET category = :category, link = :link,
                                    datevautomatik = :datevautomatik, mtime = now()
                             WHERE id = :id',
                            [
                                ':category'       => $m['category'] !== '' ? $m['category'] : null,
                                ':link'           => $m['link'],
                                ':datevautomatik' => $m['datevautomatik'] ? 't' : 'f',
                                ':id'             => $cur['id'],
                            ]
                        );
                        $report['corrected'][] = ['accno' => $accno, 'diff' => $diff];
                    }
                } else {
                    $report['skipped'][] = $accno;
                }
                continue;
            }

            // ── Neu: chart-Zeile + taxkeys ────────────────────────────────────
            $taxkeyNum = (int) $m['taxkey'];
            $taxRows   = _chartTaxTemplate($db, $taxkeyNum, $m['link'], $templateCache);
            if ($taxRows === null) {
                // Keine Steuervorlage in dieser DB gefunden -> nicht raten, melden, Konto auslassen.
                $report['conflicts'][] = [
                    'accno'  => $accno,
                    'reason' => "keine taxkeys-Vorlage fuer taxkey={$taxkeyNum} link='{$m['link']}'",
                ];
                continue;
            }

            if ($dryRun) {
                $report['added'][] = ['accno' => $accno, 'taxkeys' => count($taxRows)];
                continue;
            }

            $newIdRow = $db->getOne(
                'INSERT INTO chart (accno, description, charttype, category, link, taxkey_id,
                                    datevautomatik, itime, mtime)
                 VALUES (:accno, :description, :charttype, :category, :link, :taxkey_id,
                                    :datevautomatik, now(), now())
                 RETURNING id',
                [
                    ':accno'          => $accno,
                    ':description'    => $m['description'],
                    ':charttype'      => $m['charttype'] !== '' ? $m['charttype'] : 'A',
                    ':category'       => $m['category'] !== '' ? $m['category'] : null,
                    ':link'           => $m['link'],
                    ':taxkey_id'      => $taxkeyNum,
                    ':datevautomatik' => $m['datevautomatik'] ? 't' : 'f',
                ]
            );
            $newId = (int) $newIdRow['id'];

            foreach ($taxRows as $tr) {
                $db->execute(
                    'INSERT INTO taxkeys (chart_id, tax_id, taxkey_id, pos_ustva, startdate)
                     VALUES (:chart_id, :tax_id, :taxkey_id, :pos_ustva, :startdate)',
                    [
                        ':chart_id'  => $newId,
                        ':tax_id'    => (int) $tr['tax_id'],
                        ':taxkey_id' => (int) $tr['taxkey_id'],
                        ':pos_ustva' => $tr['pos_ustva'] !== null ? (int) $tr['pos_ustva'] : null,
                        ':startdate' => $tr['startdate'],
                    ]
                );
            }

            $report['added'][] = ['accno' => $accno, 'id' => $newId, 'taxkeys' => count($taxRows)];
        }

        // ── Sichere Reparatur: Bestandskonten mit taxkey_id<>0 aber OHNE taxkeys-Zeilen.
        // Rein additiv (es werden nur fehlende taxkeys ergänzt, nichts überschrieben). Genau der
        // dokumentierte "fehlerhaft konfiguriert"-Fall (in Buchungsmasken nicht nutzbar).
        if ($mode === 'fix') {
            $broken = $db->getAll(
                'SELECT c.id, c.accno, c.taxkey_id, c.link
                 FROM chart c
                 WHERE c.taxkey_id IS NOT NULL AND c.taxkey_id <> 0
                   AND NOT EXISTS (SELECT 1 FROM taxkeys tk WHERE tk.chart_id = c.id)'
            );
            foreach ($broken as $b) {
                $tk = (int) $b['taxkey_id'];
                $tpl = _chartTaxTemplate($db, $tk, (string) ($b['link'] ?? ''), $templateCache);
                if ($tpl === null) {
                    $report['conflicts'][] = [
                        'accno'  => $b['accno'],
                        'reason' => "Reparatur: keine taxkeys-Vorlage fuer taxkey={$tk}",
                    ];
                    continue;
                }
                if (!$dryRun) {
                    foreach ($tpl as $tr) {
                        $db->execute(
                            'INSERT INTO taxkeys (chart_id, tax_id, taxkey_id, pos_ustva, startdate)
                             VALUES (:chart_id, :tax_id, :taxkey_id, :pos_ustva, :startdate)',
                            [
                                ':chart_id'  => (int) $b['id'],
                                ':tax_id'    => (int) $tr['tax_id'],
                                ':taxkey_id' => (int) $tr['taxkey_id'],
                                ':pos_ustva' => $tr['pos_ustva'] !== null ? (int) $tr['pos_ustva'] : null,
                                ':startdate' => $tr['startdate'],
                            ]
                        );
                    }
                }
                $report['repaired_taxkeys'][] = ['accno' => $b['accno'], 'taxkey' => $tk, 'taxkeys' => count($tpl)];
            }
        }

        if ($dryRun) {
            $db->rollBack();
        } else {
            $db->commit();
        }
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $report['summary'] = [
        'skr'          => $skr,
        'mode'         => $mode,
        'dry_run'      => $dryRun,
        'master_count' => count($master),
        'added'        => count($report['added']),
        'skipped'      => count($report['skipped']),
        'corrections'  => count($report['corrections']),
        'corrected'    => count($report['corrected']),
        'repaired_taxkeys' => count($report['repaired_taxkeys']),
        'conflicts'    => count($report['conflicts']),
    ];

    return $report;
}

/**
 * CSV der Kontenrahmen-Stammdaten einlesen.
 * Erwartete Kopfzeile: accno,description,charttype,category,link,taxkey,datevautomatik
 *
 * @return array Liste normalisierter Konten-Datensätze.
 */
function _chartReadMasterCsv(string $csvFile) {
    $handle = fopen($csvFile, 'r');
    if ($handle === false) {
        throw new ApiError('DATA_NOT_FOUND', "Kontenrahmen-CSV nicht lesbar: {$csvFile}");
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        return [];
    }
    $idx = array_flip(array_map('trim', $header));
    foreach (['accno', 'description', 'category', 'link', 'taxkey', 'datevautomatik'] as $col) {
        if (!isset($idx[$col])) {
            fclose($handle);
            throw new ApiError('INVALID_PARAMETER', "Spalte '{$col}' fehlt in {$csvFile}");
        }
    }

    $rows = [];
    while (($r = fgetcsv($handle)) !== false) {
        if (count($r) === 1 && trim((string) $r[0]) === '') continue; // Leerzeile
        $accno = trim((string) ($r[$idx['accno']] ?? ''));
        if ($accno === '') continue;
        $rows[] = [
            'accno'          => $accno,
            'description'    => trim((string) ($r[$idx['description']] ?? '')),
            'charttype'      => isset($idx['charttype']) ? trim((string) ($r[$idx['charttype']] ?? 'A')) : 'A',
            'category'       => trim((string) ($r[$idx['category']] ?? '')),
            'link'           => trim((string) ($r[$idx['link']] ?? '')),
            'taxkey'         => trim((string) ($r[$idx['taxkey']] ?? '0')),
            'datevautomatik' => _chartBool($r[$idx['datevautomatik']] ?? false),
        ];
    }
    fclose($handle);
    return $rows;
}

/**
 * Steuer-Vorlage für ein neues Konto aus der Ziel-DB ermitteln (Mirroring, kein Raten).
 *
 * taxkey 0 -> Standardzeile ohne Steuer. Sonst: bestes bestehendes Konto mit gleicher
 * (taxkey_id, link)-Signatur als Vorlage; dessen taxkeys-Zeilen werden geklont. Fällt der
 * link-genaue Treffer aus, wird auf taxkey-only zurückgegriffen.
 *
 * @return array|null Liste der zu klonenden taxkeys-Zeilen, oder null wenn keine Vorlage existiert.
 */
function _chartTaxTemplate($db, int $taxkey, string $link, array &$cache) {
    if ($taxkey === 0) {
        return [['tax_id' => 0, 'taxkey_id' => 0, 'pos_ustva' => null, 'startdate' => '1970-01-01']];
    }

    $cacheKey = $taxkey . '|' . $link;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    // Repräsentatives Vorlagen-Konto: gleiche taxkey_id (+ möglichst gleicher link),
    // mit den meisten taxkeys-Zeilen (vollständigste Periodenhistorie).
    $tpl = $db->getOne(
        'SELECT c.id, COUNT(tk.id) AS n,
                (c.link = :link) AS link_match
         FROM chart c
         JOIN taxkeys tk ON tk.chart_id = c.id
         WHERE c.taxkey_id = :tk
         GROUP BY c.id
         ORDER BY link_match DESC, n DESC
         LIMIT 1',
        [':tk' => $taxkey, ':link' => $link]
    );

    if (!$tpl) {
        $cache[$cacheKey] = null;
        return null;
    }

    $rows = $db->getAll(
        "SELECT tax_id, taxkey_id, pos_ustva, TO_CHAR(startdate, 'YYYY-MM-DD') AS startdate
         FROM taxkeys WHERE chart_id = :id ORDER BY startdate",
        [':id' => (int) $tpl['id']]
    );

    $cache[$cacheKey] = $rows;
    return $rows;
}

/**
 * Abweichung der bestehenden chart-Konfig gegenüber der CSV ermitteln (für 'fix'-Report).
 * Beschreibung wird bewusst NICHT verglichen (k9o nutzt abweichende Kurzformen).
 *
 * @return array Feldname => ['ist' => x, 'soll' => y]
 */
function _chartConfigDiff($cur, $m) {
    $diff = [];
    $curCat = (string) ($cur['category'] ?? '');
    if ($curCat !== $m['category'] && $m['category'] !== '') {
        $diff['category'] = ['ist' => $curCat, 'soll' => $m['category']];
    }
    $curLink = (string) ($cur['link'] ?? '');
    if ($curLink !== $m['link']) {
        $diff['link'] = ['ist' => $curLink, 'soll' => $m['link']];
    }
    $curTk = (int) ($cur['taxkey_id'] ?? 0);
    if ($curTk !== (int) $m['taxkey']) {
        $diff['taxkey'] = ['ist' => $curTk, 'soll' => (int) $m['taxkey']];
    }
    $curAuto = _chartBool($cur['datevautomatik'] ?? false);
    if ($curAuto !== $m['datevautomatik']) {
        $diff['datevautomatik'] = ['ist' => $curAuto, 'soll' => $m['datevautomatik']];
    }
    return $diff;
}

/**
 * Robustes Bool-Parsing für CSV/DB-Werte ('t'/'f'/'1'/'0'/'true'/'false').
 */
function _chartBool($v) {
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string) $v));
    return in_array($s, ['t', 'true', '1', 'yes', 'y'], true);
}
