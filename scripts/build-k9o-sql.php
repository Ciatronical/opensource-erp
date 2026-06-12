<?php
/**
 * build-k9o-sql.php — erzeugt eigenständige, reine SQL-Dateien, mit denen ein
 * k9o-Anwender seinen Kontenrahmen auf den vollen DATEV-Standard bringt
 * (ohne OpensourceERP / ohne PHP-Importer).
 *
 * Quelle: backend/upstall/crm/chart_master/skrNN.csv
 * Ziel:   backend/upstall/crm/chart_master/k9o/k9o-skrNN-full-chart.sql
 *
 * Die SQL-Datei ist:
 *   - additiv & idempotent (fügt nur fehlende Konten/taxkeys hinzu),
 *   - DB-geerdet: Steuer-taxkeys werden NICHT hartkodiert, sondern in der Ziel-DB aus
 *     einem bestehenden Analog-Konto derselben taxkey_id geklont (tax_id, pos_ustva,
 *     startdate-Perioden stammen aus der Ziel-DB selbst),
 *   - abgesichert: prüft defaults.coa und bricht ab, wenn der falsche Kontenrahmen läuft.
 *
 * Aufruf:  php scripts/build-k9o-sql.php [skr03|skr04|all]
 */

$OUT  = __DIR__ . '/../backend/upstall/crm/chart_master';
$DEST = $OUT . '/k9o';
@mkdir($DEST, 0775, true);

$which = $argv[1] ?? 'all';
$skrs  = $which === 'all' ? ['skr03', 'skr04'] : [$which];

foreach ($skrs as $skr) {
    buildSql($skr, $OUT, $DEST);
}

function sq(string $s): string { return "'" . str_replace("'", "''", $s) . "'"; }

function buildSql(string $skr, string $OUT, string $DEST): void {
    $csv = "$OUT/$skr.csv";
    if (!is_file($csv)) { fwrite(STDERR, "FEHLT: $csv\n"); exit(1); }

    $rows = array_map('str_getcsv', file($csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    $head = array_flip(array_map('trim', array_shift($rows)));

    $coaLabel  = $skr === 'skr03' ? 'SKR03' : 'SKR04';
    $values = [];
    foreach ($rows as $r) {
        $accno = trim($r[$head['accno']] ?? '');
        if ($accno === '') continue;
        $desc  = trim($r[$head['description']] ?? '');
        $cat   = trim($r[$head['category']] ?? '');
        $link  = trim($r[$head['link']] ?? '');
        $tk    = (int) trim($r[$head['taxkey']] ?? '0');
        $auto  = in_array(strtolower(trim($r[$head['datevautomatik']] ?? 'f')), ['t','true','1'], true) ? 'true' : 'false';
        $catSql = $cat !== '' ? sq($cat) : 'NULL';
        // accno MUSS quotiert sein, sonst frisst PostgreSQL führende Nullen (0010 -> 10).
        $values[] = "  (" . sq($accno) . ", " . sq($desc) . ", $catSql, " . sq($link) . ", $tk, $auto)";
    }

    $valuesSql = implode(",\n", $values);
    $n = count($values);

    $sql = <<<SQL
-- ============================================================================
-- k9o: Vollständiger DATEV-Kontenrahmen $coaLabel ($n Konten)
-- ============================================================================
-- Ergänzt fehlende Sachkonten samt zeitabhängigen Steuerschlüsseln in einer
-- k9o-FIRMEN-Datenbank. Additiv & idempotent — bestehende Konten und
-- Buchungen werden NICHT verändert; mehrfaches Ausführen ist gefahrlos.
--
-- Die Steuerschlüssel werden nicht geraten: jedes neue Steuer-Konto erhält
-- seine taxkeys, indem ein bereits vorhandenes Konto desselben Steuerschlüssels
-- als Vorlage geklont wird (tax_id/Perioden stammen aus DEINER Datenbank).
--
-- ANWENDUNG (immer zuerst ein Backup!):
--   pg_dump -U <user> <firmendb> > backup_vor_kontenrahmen.sql
--   psql   -U <user> -d <firmendb> -f k9o-$skr-full-chart.sql
--
-- Voraussetzung: Firmen-DB mit Kontenrahmen $coaLabel (wird geprüft).
-- Encoding: UTF-8. Bei alten LATIN9-Datenbanken vorher nach UTF-8 konvertieren
-- oder client_encoding entsprechend anpassen.
-- ============================================================================

SET client_encoding = 'UTF8';

BEGIN;

-- 0. Sicherheitscheck: richtiger Kontenrahmen?
DO \$\$
DECLARE v_coa text;
BEGIN
    SELECT coa INTO v_coa FROM defaults LIMIT 1;
    IF v_coa IS NULL OR v_coa NOT ILIKE '%$coaLabel%' THEN
        RAISE EXCEPTION 'Abbruch: defaults.coa = % — diese Datei ist für $coaLabel.', COALESCE(v_coa, '(leer)');
    END IF;
END
\$\$;

-- 1. DATEV-Stammdaten in eine temporäre Tabelle laden
CREATE TEMP TABLE _cm (
    accno          text PRIMARY KEY,
    description    text,
    category       char(1),
    link           text,
    taxkey         integer,
    datevautomatik boolean
) ON COMMIT DROP;

INSERT INTO _cm (accno, description, category, link, taxkey, datevautomatik) VALUES
$valuesSql;

-- 2. Fehlende Konten anlegen (chart.id über die gemeinsame id-Sequenz)
INSERT INTO chart (accno, description, charttype, category, link, taxkey_id, datevautomatik, itime, mtime)
SELECT m.accno, m.description, 'A', m.category, m.link, m.taxkey, m.datevautomatik, now(), now()
FROM _cm m
WHERE NOT EXISTS (SELECT 1 FROM chart c WHERE c.accno = m.accno);

-- 3a. Steuer-Konten (taxkey<>0) ohne taxkeys: Perioden aus einem Analog-Konto klonen
INSERT INTO taxkeys (chart_id, tax_id, taxkey_id, pos_ustva, startdate)
SELECT c.id, tk.tax_id, tk.taxkey_id, tk.pos_ustva, tk.startdate
FROM chart c
JOIN _cm m ON m.accno = c.accno
JOIN LATERAL (
    SELECT tpl.id
    FROM chart tpl
    JOIN taxkeys t2 ON t2.chart_id = tpl.id
    WHERE tpl.taxkey_id = c.taxkey_id
    GROUP BY tpl.id
    ORDER BY (tpl.link = c.link) DESC, count(t2.id) DESC
    LIMIT 1
) sel ON true
JOIN taxkeys tk ON tk.chart_id = sel.id
WHERE c.taxkey_id IS NOT NULL AND c.taxkey_id <> 0
  AND NOT EXISTS (SELECT 1 FROM taxkeys x WHERE x.chart_id = c.id);

-- 3b. Nicht-Steuer-Konten (taxkey 0) ohne taxkeys: Standardzeile
INSERT INTO taxkeys (chart_id, tax_id, taxkey_id, pos_ustva, startdate)
SELECT c.id, 0, 0, NULL, DATE '1970-01-01'
FROM chart c
JOIN _cm m ON m.accno = c.accno
WHERE (c.taxkey_id IS NULL OR c.taxkey_id = 0)
  AND NOT EXISTS (SELECT 1 FROM taxkeys x WHERE x.chart_id = c.id);

-- 4. Kurzbericht
DO \$\$
DECLARE v_total int; v_missing_tk int;
BEGIN
    SELECT count(*) INTO v_total FROM chart;
    SELECT count(*) INTO v_missing_tk FROM chart c
      WHERE c.taxkey_id IS NOT NULL AND c.taxkey_id <> 0
        AND NOT EXISTS (SELECT 1 FROM taxkeys x WHERE x.chart_id = c.id);
    RAISE NOTICE 'Kontenrahmen $coaLabel: % Konten gesamt. Konten mit Steuerschlüssel aber ohne taxkeys (Rest): %', v_total, v_missing_tk;
END
\$\$;

COMMIT;

-- Fertig. Tipp: anschließend unter "System -> Kontenrahmen anzeigen" prüfen.

SQL;

    $file = "$DEST/k9o-$skr-full-chart.sql";
    file_put_contents($file, $sql);
    fprintf(STDOUT, "%s -> %s  (%d Konten)\n", $skr, $file, $n);
}
