<?php
// backend/api/letters/letters.php
//
// Brieffunktion — Nachbau der kivitendo-Briefe mit OS-ERP-Oberfläche.
//
// Die Briefe liegen unverändert in kivitendos Tabelle `letter` (Kunde oder
// Lieferant, Ansprechperson, Betreff, Anrede, Text als HTML, Nummer aus
// defaults.letternumber). Dazu kommen Textbausteine (letter_templates) und
// der Versand: PDF zum Drucken, per E-Mail (email_journal + record_links wie
// bei Belegen) oder elektronisch per Post (postal.php). Alles, was versendet
// wurde, erscheint in der Kontakthistorie des Kunden.

require_once __DIR__.'/../lib/mitarbeiter.php';
require_once __DIR__.'/../print/print.php';
require_once __DIR__.'/../print/template_engine.php';
require_once __DIR__.'/../email/mailer.php';

/**
 * Briefrecht (Verkaufs- oder Einkaufsbriefe, kivitendo-Rechte).
 */
function letterPermit(): void {
    permit(['sales_letter_edit', 'purchase_letter_edit'], false);
}

/**
 * Briefliste mit Volltextsuche (UND-verknüpfte Begriffe), Filtern und
 * Versandstatus je Brief.
 *
 * @param string $data['search']    Suchbegriffe (Nummer, Betreff, Empfänger, Text)
 * @param string $data['src']       C = Kunden, V = Lieferanten, leer = alle
 * @param int    $data['cv_id']     Nur Briefe dieses Kunden/Lieferanten
 * @param string $data['status']    draft (nicht versendet) | sent | leer
 * @param string $data['date_from'] Datum ab (YYYY-MM-DD)
 * @param string $data['date_to']   Datum bis
 * @param int    $data['limit']     Seitengröße
 * @param int    $data['offset']    Seitenanfang
 * @testdata {"search": "", "limit": 50, "offset": 0}
 */
function getLetters($data) {
    letterPermit();
    $db = DbhCompany::begin();
    $row = $db->getOne(<<<SQL
        WITH p AS (
            SELECT NULLIF(btrim(:search), '')::text AS search,
                   NULLIF(:src, '')::text AS src,
                   NULLIF(:cv_id::int, 0) AS cv_id,
                   NULLIF(:status, '')::text AS status,
                   NULLIF(:date_from, '')::date AS date_from,
                   NULLIF(:date_to, '')::date AS date_to
        ),
        base AS (
            SELECT l.id, l.letternumber, l.date, l.subject, l.reference, l.cp_id, l.itime, l.mtime, l.body,
                   CASE WHEN l.vendor_id IS NOT NULL THEN 'V' ELSE 'C' END AS src,
                   COALESCE(l.customer_id, l.vendor_id) AS cv_id,
                   COALESCE(c.name, v.name, '') AS cv_name,
                   NULLIF(btrim(concat_ws(' ', cp.cp_givenname, cp.cp_name)), '') AS contact_name,
                   e.name AS employee_name,
                   (SELECT COALESCE(json_agg(json_build_object('channel', s.channel, 'sent_at', s.sent_at, 'status', s.status)
                                             ORDER BY s.sent_at DESC), '[]'::json)
                    FROM (
                        SELECT 'email' AS channel, ej.sent_on AS sent_at, ej.status::text AS status
                        FROM record_links rl
                        JOIN email_journal ej ON ej.id = rl.to_id
                        WHERE rl.from_table = 'letter' AND rl.from_id = l.id AND rl.to_table = 'email_journal'
                        UNION ALL
                        SELECT 'post', COALESCE(pd.sent_at, pd.itime), pd.status
                        FROM postal_dispatches pd
                        WHERE pd.record_table = 'letter' AND pd.record_id = l.id AND pd.status <> 'error'
                    ) s) AS sent_log
            FROM letter l
            LEFT JOIN customer c ON c.id = l.customer_id
            LEFT JOIN vendor v ON v.id = l.vendor_id
            LEFT JOIN contacts cp ON cp.cp_id = l.cp_id
            LEFT JOIN employee e ON e.id = l.employee_id
        ),
        f AS (
            SELECT b.* FROM base b, p
            WHERE (p.src IS NULL OR b.src = p.src)
              AND (p.cv_id IS NULL OR b.cv_id = p.cv_id)
              AND (p.date_from IS NULL OR b.date >= p.date_from)
              AND (p.date_to IS NULL OR b.date <= p.date_to)
              AND (p.status IS NULL
                   OR (p.status = 'sent'  AND json_array_length(b.sent_log) > 0)
                   OR (p.status = 'draft' AND json_array_length(b.sent_log) = 0))
              AND (p.search IS NULL OR (
                    SELECT bool_and(concat_ws(' ', b.letternumber, b.subject, b.reference, b.cv_name,
                                              b.contact_name, b.employee_name, b.body) ILIKE '%' || w || '%')
                    FROM unnest(regexp_split_to_array(p.search, '\s+')) AS w
                    WHERE w <> ''))
        )
        SELECT json_build_object(
            'total', (SELECT count(*) FROM f),
            'letters', (
                SELECT COALESCE(json_agg(json_build_object(
                    'id', x.id, 'letternumber', x.letternumber, 'date', x.date, 'subject', x.subject,
                    'reference', x.reference, 'src', x.src, 'cv_id', x.cv_id, 'cv_name', x.cv_name,
                    'contact_name', x.contact_name, 'employee_name', x.employee_name,
                    'sent_log', x.sent_log, 'itime', x.itime
                ) ORDER BY x.date DESC, x.id DESC), '[]'::json)
                FROM (SELECT * FROM f ORDER BY f.date DESC, f.id DESC LIMIT :limit OFFSET :offset) x
            ),
            'counts', json_build_object(
                'all',   (SELECT count(*) FROM base),
                'draft', (SELECT count(*) FROM base WHERE json_array_length(sent_log) = 0),
                'sent',  (SELECT count(*) FROM base WHERE json_array_length(sent_log) > 0)
            )
        ) AS result
    SQL, [
        ':search'    => (string)($data['search'] ?? ''),
        ':src'       => (string)($data['src'] ?? ''),
        ':cv_id'     => intval($data['cv_id'] ?? 0),
        ':status'    => (string)($data['status'] ?? ''),
        ':date_from' => (string)($data['date_from'] ?? ''),
        ':date_to'   => (string)($data['date_to'] ?? ''),
        ':limit'     => max(1, min(500, intval($data['limit'] ?? 50))),
        ':offset'    => max(0, intval($data['offset'] ?? 0)),
    ]);
    resultInfo(true, '', ['results' => json_decode($row['result'], true)]);
}

/**
 * Ein Brief für den Editor — vorhandener Brief, Kopie eines Briefs oder ein
 * neuer Brief an einen Kunden/Lieferanten. Liefert in einem Aufruf alles,
 * was der Editor braucht: Brief, Empfänger mit Adresse, Ansprechpersonen,
 * Mitarbeiter, Textbausteine, Versandprotokoll und einen Anredevorschlag
 * (aus Geschlecht der Ansprechperson bzw. Anrede des Kunden, in der Sprache
 * des Kunden).
 *
 * @param int $data['id']          Vorhandener Brief
 * @param int $data['copy_of']     Alternativ: Brief, der als Vorlage kopiert wird
 * @param int $data['customer_id'] Neuer Brief an diesen Kunden …
 * @param int $data['vendor_id']   … oder an diesen Lieferanten
 * @param int $data['cp_id']       Ansprechperson (optional)
 * @testdata {"customer_id": 1}
 */
function getLetter($data) {
    letterPermit();
    $db = DbhCompany::begin();
    $row = $db->getOne(<<<SQL
        WITH p AS (
            SELECT NULLIF(:id::int, 0) AS id, NULLIF(:copy_of::int, 0) AS copy_of,
                   NULLIF(:customer_id::int, 0) AS customer_id, NULLIF(:vendor_id::int, 0) AS vendor_id,
                   NULLIF(:cp_id::int, 0) AS cp_id, NULLIF(:employee_id::int, 0) AS employee_id
        ),
        src AS (SELECT l.* FROM letter l, p WHERE l.id = COALESCE(p.id, p.copy_of)),
        ids AS (
            SELECT COALESCE((SELECT customer_id FROM src), (SELECT customer_id FROM p)) AS customer_id,
                   COALESCE((SELECT vendor_id FROM src), (SELECT vendor_id FROM p)) AS vendor_id,
                   COALESCE((SELECT cp_id FROM src), (SELECT cp_id FROM p)) AS cp_id
        ),
        cv AS (
            SELECT 'C' AS src, c.id, c.name, COALESCE(c.customernumber, '') AS number,
                   COALESCE(c.greeting, '') AS greeting, COALESCE(c.natural_person, false) AS natural_person,
                   COALESCE(c.department_1, '') AS department_1, COALESCE(c.department_2, '') AS department_2,
                   COALESCE(c.street, '') AS street, COALESCE(c.zipcode, '') AS zipcode, COALESCE(c.city, '') AS city,
                   COALESCE(c.country, '') AS country, COALESCE(c.email, '') AS email, COALESCE(c.cc, '') AS cc,
                   COALESCE(lg.template_code, 'DE') AS language_code
            FROM customer c LEFT JOIN language lg ON lg.id = c.language_id, ids
            WHERE c.id = ids.customer_id
            UNION ALL
            SELECT 'V', v.id, v.name, COALESCE(v.vendornumber, ''),
                   COALESCE(v.greeting, ''), COALESCE(v.natural_person, false),
                   COALESCE(v.department_1, ''), COALESCE(v.department_2, ''),
                   COALESCE(v.street, ''), COALESCE(v.zipcode, ''), COALESCE(v.city, ''),
                   COALESCE(v.country, ''), COALESCE(v.email, ''), COALESCE(v.cc, ''),
                   COALESCE(lg.template_code, 'DE')
            FROM vendor v LEFT JOIN language lg ON lg.id = v.language_id, ids
            WHERE v.id = ids.vendor_id AND ids.customer_id IS NULL
        ),
        cp AS (
            SELECT c.cp_id, COALESCE(c.cp_gender, '') AS cp_gender, COALESCE(c.cp_title, '') AS cp_title,
                   COALESCE(c.cp_givenname, '') AS cp_givenname, COALESCE(c.cp_name, '') AS cp_name,
                   COALESCE(c.cp_email, '') AS cp_email, COALESCE(c.cp_main, false) AS cp_main
            FROM contacts c, cv WHERE c.cp_cv_id = cv.id
        ),
        chosen AS (SELECT cp.* FROM cp, ids WHERE cp.cp_id = ids.cp_id),
        g AS (
            SELECT (SELECT cp_gender FROM chosen) AS gender,
                   (SELECT btrim(concat_ws(' ', cp_title, cp_name)) FROM chosen) AS cp_lastname,
                   (SELECT btrim(concat_ws(' ', cp_givenname, cp_name)) FROM chosen) AS cp_fullname,
                   (SELECT count(*) FROM chosen) AS has_cp,
                   COALESCE((SELECT natural_person FROM cv), false) AS natural_person,
                   COALESCE((SELECT name FROM cv), '') AS name,
                   COALESCE((SELECT greeting FROM cv), '') AS greeting,
                   COALESCE((SELECT language_code FROM cv), 'DE') AS lang
        ),
        suggestion AS (
            SELECT CASE
                WHEN g.has_cp = 1 AND g.gender = 'f' THEN
                    CASE WHEN g.lang = 'EN' THEN 'Dear Ms. ' ELSE 'Sehr geehrte Frau ' END || g.cp_lastname || ','
                WHEN g.has_cp = 1 AND g.gender = 'm' THEN
                    CASE WHEN g.lang = 'EN' THEN 'Dear Mr. ' ELSE 'Sehr geehrter Herr ' END || g.cp_lastname || ','
                WHEN g.has_cp = 1 THEN
                    CASE WHEN g.lang = 'EN' THEN 'Dear ' ELSE 'Guten Tag ' END || g.cp_fullname || ','
                -- Kunde ist eine Person: Anrede „Herr"/„Frau" im Stammsatz genügt, der
                -- Haken „natürliche Person" ist in der Praxis selten gesetzt. Voller Name.
                WHEN g.name <> '' AND (g.greeting ILIKE 'frau%' OR g.greeting ILIKE 'ms%' OR g.greeting ILIKE 'mrs%') THEN
                    CASE WHEN g.lang = 'EN' THEN 'Dear Ms. ' ELSE 'Sehr geehrte Frau ' END || g.name || ','
                WHEN g.name <> '' AND (g.greeting ILIKE 'herr%' OR g.greeting ILIKE 'mr%') THEN
                    CASE WHEN g.lang = 'EN' THEN 'Dear Mr. ' ELSE 'Sehr geehrter Herr ' END || g.name || ','
                WHEN g.natural_person AND g.name <> '' THEN
                    CASE WHEN g.lang = 'EN' THEN 'Dear ' ELSE 'Guten Tag ' END || g.name || ','
                ELSE CASE WHEN g.lang = 'EN' THEN 'Dear Sir or Madam,' ELSE 'Sehr geehrte Damen und Herren,' END
            END AS text
            FROM g
        ),
        existing AS (SELECT src.* FROM src, p WHERE p.id IS NOT NULL)
        SELECT json_build_object(
            'found', (SELECT id FROM p) IS NULL OR EXISTS (SELECT 1 FROM src),
            'letter', json_build_object(
                'id',           (SELECT id FROM existing),
                'letternumber', (SELECT letternumber FROM existing),
                'date',         COALESCE((SELECT date FROM existing), CURRENT_DATE),
                'customer_id',  (SELECT id FROM cv WHERE src = 'C'),
                'vendor_id',    (SELECT id FROM cv WHERE src = 'V'),
                'cp_id',        (SELECT cp_id FROM chosen),
                'reference',    COALESCE((SELECT reference FROM src), ''),
                'subject',      COALESCE((SELECT subject FROM src), ''),
                'greeting',     COALESCE(NULLIF((SELECT greeting FROM src), ''), (SELECT text FROM suggestion)),
                'body',         COALESCE((SELECT body FROM src), ''),
                'intnotes',     COALESCE((SELECT intnotes FROM existing), ''),
                'employee_id',  COALESCE((SELECT employee_id FROM src), (SELECT employee_id FROM p)),
                'salesman_id',  (SELECT salesman_id FROM src),
                'itime',        (SELECT itime FROM existing),
                'mtime',        (SELECT mtime FROM existing)
            ),
            'greeting_suggestion', (SELECT text FROM suggestion),
            'customer_vendor', (SELECT row_to_json(cv) FROM cv),
            'contacts', (
                SELECT COALESCE(json_agg(json_build_object(
                    'cp_id', cp.cp_id, 'name', btrim(concat_ws(' ', cp.cp_title, cp.cp_givenname, cp.cp_name)),
                    'email', cp.cp_email, 'gender', cp.cp_gender, 'main', cp.cp_main
                ) ORDER BY cp.cp_main DESC, cp.cp_name), '[]'::json)
                FROM cp
            ),
            'employees', (
                SELECT COALESCE(json_agg(json_build_object('id', e.id, 'name', e.name) ORDER BY e.name), '[]'::json)
                FROM employee e WHERE COALESCE(e.deleted, false) = false
            ),
            'templates', (
                SELECT COALESCE(json_agg(json_build_object('id', t.id, 'name', t.name, 'subject', t.subject, 'body', t.body) ORDER BY t.name), '[]'::json)
                FROM letter_templates t
            ),
            'sent_log', (
                SELECT COALESCE(json_agg(s ORDER BY s.sent_at DESC), '[]'::json)
                FROM (
                    SELECT 'email' AS channel, ej.id, ej.sent_on AS sent_at, ej.recipients AS recipient,
                           ej.subject, ej.status::text AS status, e.name AS employee_name,
                           NULL::text AS provider, NULL::text AS tracking_code, NULL::text AS tracking_status,
                           NULL::numeric AS price, NULL::text AS error, NULL::int AS pages, NULL::text AS registered,
                           NULL::text AS recipient_address, NULL::text AS provider_status, false AS cancelable, false AS has_pdf
                    FROM record_links rl
                    JOIN email_journal ej ON ej.id = rl.to_id
                    LEFT JOIN employee e ON e.id = ej.sender_id
                    WHERE rl.from_table = 'letter' AND rl.from_id = (SELECT id FROM existing) AND rl.to_table = 'email_journal'
                    UNION ALL
                    SELECT 'post', pd.id, COALESCE(pd.sent_at, pd.itime), pd.recipient_name, pd.filename, pd.status, e.name,
                           pd.provider, pd.tracking_code, pd.tracking_status, pd.price, pd.error, pd.pages, pd.registered,
                           pd.recipient_address, pd.provider_status,
                           pd.provider = 'letterxpress' AND pd.status = 'sent' AND pd.sent_at > now() - INTERVAL '15 minutes',
                           pd.pdf IS NOT NULL
                    FROM postal_dispatches pd
                    LEFT JOIN employee e ON e.id = pd.employee_id
                    WHERE pd.record_table = 'letter' AND pd.record_id = (SELECT id FROM existing)
                ) s
            )
        ) AS result
    SQL, [
        ':id'          => intval($data['id'] ?? 0),
        ':copy_of'     => intval($data['copy_of'] ?? 0),
        ':customer_id' => intval($data['customer_id'] ?? 0),
        ':vendor_id'   => intval($data['vendor_id'] ?? 0),
        ':cp_id'       => intval($data['cp_id'] ?? 0),
        ':employee_id' => intval(mitarbeiterId($data) ?? 0),
    ]);
    $result = json_decode($row['result'], true);
    if (empty($result['found'])) {
        throw new ApiError('LETTER_NOT_FOUND', 'Brief nicht gefunden');
    }
    resultInfo(true, '', ['results' => $result]);
}

/**
 * Brief speichern. Ein neuer Brief bekommt seine Nummer aus
 * defaults.letternumber — atomar im selben Statement wie das INSERT.
 *
 * @param array $data['letter'] id?, customer_id | vendor_id, cp_id, date, reference, subject, greeting, body, intnotes, employee_id, salesman_id
 * @testdata {"letter": {"customer_id": 1, "date": "2026-10-11", "subject": "Testbrief", "greeting": "Sehr geehrte Damen und Herren,", "body": "<p>Text</p>"}}
 */
function saveLetter($data) {
    letterPermit();
    $db = DbhCompany::begin();
    $l  = is_array($data['letter'] ?? null) ? $data['letter'] : [];
    $id = intval($l['id'] ?? 0);
    $customerId = intval($l['customer_id'] ?? 0) ?: null;
    $vendorId   = $customerId ? null : (intval($l['vendor_id'] ?? 0) ?: null);
    if (!$customerId && !$vendorId) {
        throw new ApiError('VALIDATION_ERROR', 'Empfänger fehlt');
    }
    $date = trim((string)($l['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

    $params = [
        ':customer_id' => $customerId,
        ':vendor_id'   => $vendorId,
        ':cp_id'       => intval($l['cp_id'] ?? 0) ?: null,
        ':date'        => $date,
        ':reference'   => trim((string)($l['reference'] ?? '')),
        ':subject'     => trim((string)($l['subject'] ?? '')),
        ':greeting'    => trim((string)($l['greeting'] ?? '')),
        ':body'        => (string)($l['body'] ?? ''),
        ':intnotes'    => (string)($l['intnotes'] ?? ''),
        ':employee_id' => intval($l['employee_id'] ?? 0) ?: mitarbeiterId($data),
        ':salesman_id' => intval($l['salesman_id'] ?? 0) ?: null,
    ];

    if ($id > 0) {
        $row = $db->getOne(<<<SQL
            UPDATE letter
            SET customer_id = :customer_id, vendor_id = :vendor_id, cp_id = :cp_id, date = :date,
                reference = :reference, subject = :subject, greeting = :greeting, body = :body,
                intnotes = :intnotes, employee_id = :employee_id, salesman_id = :salesman_id, mtime = now()
            WHERE id = :id
            RETURNING id, letternumber
        SQL, $params + [':id' => $id]);
        if (!$row) throw new ApiError('LETTER_NOT_FOUND', 'Brief nicht gefunden');
    } else {
        $row = $db->getOne(<<<SQL
            WITH n AS (UPDATE defaults SET letternumber = COALESCE(letternumber, 0) + 1 RETURNING letternumber)
            INSERT INTO letter (customer_id, vendor_id, cp_id, date, reference, subject, greeting, body,
                                intnotes, employee_id, salesman_id, letternumber)
            SELECT :customer_id, :vendor_id, :cp_id, :date, :reference, :subject, :greeting, :body,
                   :intnotes, :employee_id, :salesman_id, (SELECT letternumber::text FROM n)
            RETURNING id, letternumber
        SQL, $params);
    }
    resultInfo(true, 'SAVED', ['results' => ['id' => intval($row['id']), 'letternumber' => $row['letternumber']]]);
}

/**
 * Brief löschen (mit Versandprotokoll; E-Mail-Verknüpfungen räumt kivitendos
 * Trigger auf, das E-Mail-Journal bleibt).
 *
 * @param int $data['id'] Brief
 * @testdata {"id": 1}
 */
function deleteLetter($data) {
    letterPermit();
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    $db->execute("DELETE FROM postal_dispatches WHERE record_table = 'letter' AND record_id = :id", [':id' => $id]);
    $db->execute("DELETE FROM letter WHERE id = :id", [':id' => $id]);
    resultInfo(true, 'DELETED', ['results' => ['id' => $id]]);
}

/**
 * Textbaustein anlegen oder ändern.
 *
 * @param int    $data['id']      Vorhandener Baustein (optional)
 * @param string $data['name']    Anzeigename
 * @param string $data['subject'] Betreff
 * @param string $data['body']    Text (HTML)
 * @testdata {"name": "Terminbestätigung", "subject": "Ihr Termin", "body": "<p>Sehr geehrte …</p>"}
 */
function saveLetterTemplate($data) {
    letterPermit();
    $db   = DbhCompany::begin();
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') throw new ApiError('VALIDATION_ERROR', 'Name fehlt');
    $row = $db->getOne(<<<SQL
        INSERT INTO letter_templates AS t (id, name, subject, body, employee_id)
        OVERRIDING SYSTEM VALUE
        VALUES (COALESCE(NULLIF(:id::int, 0), nextval(pg_get_serial_sequence('letter_templates', 'id'))), :name, :subject, :body, :emp)
        ON CONFLICT (id) DO UPDATE
            SET name = EXCLUDED.name, subject = EXCLUDED.subject, body = EXCLUDED.body, mtime = now()
        RETURNING id
    SQL, [
        ':id' => intval($data['id'] ?? 0), ':name' => $name,
        ':subject' => (string)($data['subject'] ?? ''), ':body' => (string)($data['body'] ?? ''),
        ':emp' => mitarbeiterId($data),
    ]);
    resultInfo(true, 'SAVED', ['results' => ['id' => intval($row['id'])]]);
}

/**
 * Textbaustein löschen.
 *
 * @param int $data['id'] Baustein
 * @testdata {"id": 1}
 */
function deleteLetterTemplate($data) {
    letterPermit();
    DbhCompany::begin()->execute("DELETE FROM letter_templates WHERE id = :id", [':id' => intval($data['id'] ?? 0)]);
    resultInfo(true, 'DELETED');
}

/**
 * Druckdaten eines Briefs in einem Aufruf: Brief, Empfänger, Ansprechperson,
 * Unterzeichner, Firma, Vorlagensatz. Für die Vorschau eines noch nicht
 * gespeicherten Briefs kommen die Felder aus $override (id = 0).
 */
function letterPrintData($db, array $override): array {
    $row = $db->getOne(<<<SQL
        WITH p AS (
            SELECT NULLIF(:id::int, 0) AS id, NULLIF(:customer_id::int, 0) AS customer_id,
                   NULLIF(:vendor_id::int, 0) AS vendor_id, NULLIF(:cp_id::int, 0) AS cp_id,
                   NULLIF(:employee_id::int, 0) AS employee_id, NULLIF(:salesman_id::int, 0) AS salesman_id
        ),
        src AS (SELECT l.* FROM letter l, p WHERE l.id = p.id),
        ids AS (
            SELECT COALESCE((SELECT customer_id FROM src), (SELECT customer_id FROM p)) AS customer_id,
                   COALESCE((SELECT vendor_id FROM src), (SELECT vendor_id FROM p)) AS vendor_id,
                   COALESCE((SELECT cp_id FROM src), (SELECT cp_id FROM p)) AS cp_id,
                   COALESCE((SELECT employee_id FROM src), (SELECT employee_id FROM p)) AS employee_id,
                   COALESCE((SELECT salesman_id FROM src), (SELECT salesman_id FROM p)) AS salesman_id
        ),
        cv AS (
            SELECT 'C' AS src, c.id, c.name, COALESCE(c.customernumber, '') AS number,
                   COALESCE(c.department_1, '') AS department_1, COALESCE(c.department_2, '') AS department_2,
                   COALESCE(c.street, '') AS street, COALESCE(c.zipcode, '') AS zipcode, COALESCE(c.city, '') AS city,
                   COALESCE(c.country, '') AS country, COALESCE(c.email, '') AS email, COALESCE(c.cc, '') AS cc,
                   COALESCE(lg.template_code, 'DE') AS language_code
            FROM customer c LEFT JOIN language lg ON lg.id = c.language_id, ids
            WHERE c.id = ids.customer_id
            UNION ALL
            SELECT 'V', v.id, v.name, COALESCE(v.vendornumber, ''),
                   COALESCE(v.department_1, ''), COALESCE(v.department_2, ''),
                   COALESCE(v.street, ''), COALESCE(v.zipcode, ''), COALESCE(v.city, ''),
                   COALESCE(v.country, ''), COALESCE(v.email, ''), COALESCE(v.cc, ''),
                   COALESCE(lg.template_code, 'DE')
            FROM vendor v LEFT JOIN language lg ON lg.id = v.language_id, ids
            WHERE v.id = ids.vendor_id AND ids.customer_id IS NULL
        )
        SELECT json_build_object(
            'letter',   (SELECT row_to_json(src) FROM src),
            'cv',       (SELECT row_to_json(cv) FROM cv),
            'cp',       (SELECT json_build_object('cp_givenname', COALESCE(cp_givenname, ''), 'cp_name', COALESCE(cp_name, ''),
                                                  'cp_gender', COALESCE(cp_gender, ''), 'cp_title', COALESCE(cp_title, ''),
                                                  'cp_email', COALESCE(cp_email, ''))
                         FROM contacts c, ids WHERE c.cp_id = ids.cp_id),
            'employee', (SELECT json_build_object('id', e.id, 'name', COALESCE(e.name, ''),
                                                  'tel', COALESCE(e.deleted_tel, ''), 'email', COALESCE(e.deleted_email, ''))
                         FROM employee e, ids WHERE e.id = ids.employee_id),
            'salesman', (SELECT json_build_object('id', e.id, 'name', COALESCE(e.name, ''))
                         FROM employee e, ids WHERE e.id = ids.salesman_id),
            'company',      COALESCE((SELECT value FROM defaults_oserp WHERE key = 'company_name'), ''),
            'sender_name',  COALESCE((SELECT email_sender_letter FROM defaults LIMIT 1), ''),
            'template_set', (SELECT templates FROM defaults LIMIT 1)
        ) AS result
    SQL, [
        ':id'          => intval($override['id'] ?? 0),
        ':customer_id' => intval($override['customer_id'] ?? 0),
        ':vendor_id'   => intval($override['vendor_id'] ?? 0),
        ':cp_id'       => intval($override['cp_id'] ?? 0),
        ':employee_id' => intval($override['employee_id'] ?? 0),
        ':salesman_id' => intval($override['salesman_id'] ?? 0),
    ]);
    $print = json_decode($row['result'], true);
    // Felder aus dem Editor (Vorschau) überlagern den gespeicherten Stand
    $letter = $print['letter'] ?? [];
    foreach (['letternumber', 'date', 'reference', 'subject', 'greeting', 'body', 'cp_id', 'employee_id', 'salesman_id'] as $k) {
        if (array_key_exists($k, $override)) $letter[$k] = $override[$k];
    }
    $print['letter_merged'] = $letter;
    return $print;
}

/**
 * Platzhalter in Betreff und Text ersetzen: <%name%>, <%customernumber%>,
 * <%cp_givenname%>, <%cp_name%>, <%date%>, <%letternumber%>, <%employee_name%>,
 * <%company%>. Im Brieftext stehen sie als Bausteine des Editors
 * (<span data-placeholder="name">Name</span>), in Textbausteinen und im
 * Betreff als <%name%> bzw. HTML-kodiert (&lt;%name%&gt;) — alle drei
 * Schreibweisen werden ersetzt.
 */
function letterFillPlaceholders(string $text, array $print): string {
    $cv = $print['cv'] ?? [];
    $cp = $print['cp'] ?? [];
    $l  = $print['letter_merged'] ?? [];
    $map = [
        'name'           => $cv['name'] ?? '',
        'customernumber' => $cv['number'] ?? '',
        'street'         => $cv['street'] ?? '',
        'zipcode'        => $cv['zipcode'] ?? '',
        'city'           => $cv['city'] ?? '',
        'cp_givenname'   => $cp['cp_givenname'] ?? '',
        'cp_name'        => $cp['cp_name'] ?? '',
        'cp_title'       => $cp['cp_title'] ?? '',
        'date'           => !empty($l['date']) ? date('d.m.Y', strtotime($l['date'])) : date('d.m.Y'),
        'letternumber'   => (string)($l['letternumber'] ?? ''),
        'subject'        => (string)($l['subject'] ?? ''),
        'reference'      => (string)($l['reference'] ?? ''),
        'employee_name'  => $print['employee']['name'] ?? '',
        'company'        => $print['company'] ?? '',
    ];
    foreach ($map as $key => $value) {
        $safe = htmlspecialchars((string)$value, ENT_NOQUOTES);
        // Baustein aus dem Editor: <span data-placeholder="key">Label</span>
        $text = preg_replace('/<span[^>]*data-placeholder="' . preg_quote($key, '/') . '"[^>]*>.*?<\/span>/su', $safe, $text);
        $text = str_replace(['<%' . $key . '%>', '&lt;%' . $key . '%&gt;'], $safe, $text);
    }
    // Unbekannte Bausteine nicht als Label drucken
    $text = preg_replace('/<span[^>]*data-placeholder="[^"]*"[^>]*>.*?<\/span>/su', '', $text);
    return $text;
}

/**
 * Vorlage für den Brief: letter.tex bzw. brief.tex im Vorlagensatz der
 * Firma, sonst die Master-Vorlage. kivitendos mitgelieferte letter.tex in
 * Template-Toolkit-Syntax ($( … )$) kann die PHP-Engine nicht lesen und
 * wird übersprungen — sie bleibt unangetastet liegen.
 *
 * @return string Vorlageninhalt
 */
function letterTemplateContent(string $templateDir): string {
    $candidates = [$templateDir . '/letter.tex', $templateDir . '/brief.tex', PRINT_MASTER_BASE . '/RB/letter.tex'];
    foreach ($candidates as $file) {
        if (!is_file($file)) continue;
        $content = file_get_contents($file);
        if ($content !== false && strpos($content, 'use-template-toolkit') === false) {
            return $content;
        }
    }
    throw new ApiError('TEMPLATE_NOT_FOUND', 'Keine Briefvorlage (letter.tex) gefunden');
}

/**
 * Brief als PDF rendern.
 *
 * @param array $print Ergebnis von letterPrintData()
 * @return array ['pdf' => bytes, 'filename' => string] oder ['error' => string]
 */
function letterRenderPdf($db, array $print): array {
    $l    = $print['letter_merged'] ?? [];
    $cv   = $print['cv'] ?? [];
    $cp   = $print['cp'] ?? [];
    $emp  = $print['employee'] ?? [];
    $sm   = $print['salesman'] ?? [];
    $lang = strtoupper($cv['language_code'] ?? 'DE') ?: 'DE';
    $date = !empty($l['date']) ? date('d.m.Y', strtotime($l['date'])) : date('d.m.Y');
    $preview = empty($l['letternumber']);

    $variables = [
        'language_code'    => $lang,
        'currency'         => 'EUR',
        'media'            => 'printer',
        'employee_company' => $print['company'] ?? '',
        'template_meta'    => ['language' => ['template_code' => $lang], 'formname' => 'letter'],
        'formname'         => 'letter',

        'letternumber'     => (string)($l['letternumber'] ?? ''),
        'letter_date'      => $date,
        'reference'        => (string)($l['reference'] ?? ''),
        'subject'          => letterFillPlaceholders((string)($l['subject'] ?? ''), $print),
        'greeting'         => (string)($l['greeting'] ?? ''),
        'body'             => letterFillPlaceholders((string)($l['body'] ?? ''), $print),

        'name'             => $cv['name'] ?? '',
        'customernumber'   => $cv['number'] ?? '',
        'department_1'     => $cv['department_1'] ?? '',
        'department_2'     => $cv['department_2'] ?? '',
        'street'           => $cv['street'] ?? '',
        'zipcode'          => $cv['zipcode'] ?? '',
        'city'             => $cv['city'] ?? '',
        'country'          => $cv['country'] ?? '',
        'cp_givenname'     => $cp['cp_givenname'] ?? '',
        'cp_name'          => $cp['cp_name'] ?? '',
        'cp_title'         => $cp['cp_title'] ?? '',
        'cp_gender'        => $cp['cp_gender'] ?? '',

        'employee_name'    => $emp['name'] ?? '',
        'employee_tel'     => $emp['tel'] ?? '',
        'employee_email'   => $emp['email'] ?? '',
        'salesman_name'    => (!empty($sm['id']) && intval($sm['id']) !== intval($emp['id'] ?? 0)) ? ($sm['name'] ?? '') : '',
        'preview'          => $preview ? '1' : '',
    ];

    $templateSet = $print['template_set'] ?? null;
    if (!$templateSet || resolveTemplateDir($templateSet) === false) {
        $templateSet = getTemplateSet($db);
    }
    $templateDir = getTemplateDir($templateSet);

    $engine = new LaTeXTemplateEngine($templateDir);
    $engine->setVariables($variables);
    $latex = $engine->parseString(letterTemplateContent($templateDir));
    if ($latex === false) return ['error' => $engine->getError()];
    $pdfPath = $engine->compile($latex);
    if ($pdfPath === false) return ['error' => $engine->getError()];
    $pdf = file_get_contents($pdfPath);
    $engine->cleanup($pdfPath);

    $number = $l['letternumber'] ?? '';
    $name = 'Brief_' . ($number !== '' ? $number : 'Vorschau') . '_' . date('Ymd', strtotime($l['date'] ?? 'now'));
    return ['pdf' => $pdf, 'filename' => preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) . '.pdf'];
}

/**
 * Brief als PDF — gespeicherter Brief (id) oder Vorschau aus den
 * Editorfeldern (letter-Objekt ohne id, erscheint mit Aufdruck VORSCHAU).
 *
 * @param int    $data['id']           Gespeicherter Brief
 * @param array  $data['letter']       Alternativ: Felder aus dem Editor
 * @param string $data['content-type'] 'application/pdf' für Binärausgabe statt base64
 * @testdata {"id": 1}
 */
function getLetterPdf($data) {
    letterPermit();
    $db = DbhCompany::begin();
    $override = is_array($data['letter'] ?? null) ? $data['letter'] : [];
    if (!empty($data['id'])) $override['id'] = intval($data['id']);
    $print = letterPrintData($db, $override);
    if (empty($print['cv'])) {
        throw new ApiError('LETTER_NOT_FOUND', 'Brief oder Empfänger nicht gefunden');
    }
    $render = letterRenderPdf($db, $print);
    if (isset($render['error'])) {
        throw new ApiError('PDF_ERROR', 'PDF konnte nicht erzeugt werden: ' . $render['error']);
    }
    if (($data['content-type'] ?? '') === 'application/pdf') {
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($render['pdf']));
        header('Content-Disposition: inline; filename="' . $render['filename'] . '"');
        echo $render['pdf'];
        return;
    }
    resultInfo(true, '', ['results' => ['pdf' => base64_encode($render['pdf']), 'filename' => $render['filename']]]);
}

/**
 * Brief per E-Mail senden (PDF als Anhang) und im E-Mail-Journal mit
 * Verknüpfung zum Brief (record_links) vermerken — so erscheint der Versand
 * in der Kontakthistorie des Kunden.
 *
 * @param int    $data['id']        Brief (muss gespeichert sein)
 * @param string $data['to']        Empfänger (mehrere mit Komma)
 * @param string $data['cc']        Kopie (optional)
 * @param string $data['subject']   Betreff
 * @param string $data['body_html'] Nachrichtentext (HTML)
 * @testdata {"id": 1, "to": "kunde@example.com", "subject": "Unser Schreiben", "body_html": "<p>Anbei unser Schreiben.</p>"}
 */
function sendLetterEmail($data) {
    letterPermit();
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    $print = letterPrintData($db, ['id' => $id]);
    if (empty($print['letter'])) throw new ApiError('LETTER_NOT_FOUND', 'Brief nicht gefunden');

    $toList = array_values(array_filter(array_map(
        fn($e) => ['email' => trim($e), 'name' => ''], preg_split('/[,;]/', (string)($data['to'] ?? ''))
    ), fn($e) => $e['email'] !== ''));
    if (!$toList) throw new ApiError('EMAIL_NO_RECIPIENTS', 'Keine E-Mail-Adresse angegeben');
    $ccList = array_values(array_filter(array_map(
        fn($e) => ['email' => trim($e), 'name' => ''], preg_split('/[,;]/', (string)($data['cc'] ?? ''))
    ), fn($e) => $e['email'] !== ''));

    $render = letterRenderPdf($db, $print);
    if (isset($render['error'])) throw new ApiError('PDF_ERROR', 'PDF konnte nicht erzeugt werden: ' . $render['error']);

    $config  = _getEmailConfig();
    $smtp    = _createSmtpClient($config);
    $from    = $config['email_address'] ?? $config['email_username'] ?? '';
    $subject = trim((string)($data['subject'] ?? '')) ?: (string)($print['letter']['subject'] ?? 'Unser Schreiben');
    $body    = (string)($data['body_html'] ?? '');
    $text    = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $body));
    $attachments = [['filename' => $render['filename'], 'content_base64' => base64_encode($render['pdf']), 'content_type' => 'application/pdf']];

    $raw = $smtp->send($from, $print['sender_name'] ?: $print['company'], $toList, $subject, $body, $text, $ccList, [], $attachments);
    try {
        $imap = _createImapClient($config);
        $sent = $imap->findSentFolder();
        if ($sent) $imap->appendToFolder($sent, $raw);
        $imap->disconnect();
    } catch (\Throwable $e) {
        // Kopie im Gesendet-Ordner ist Komfort, kein Muss
    }
    _logToEmailJournal($from, $toList, $ccList, $subject, $text ?: $body, $attachments, null, 'letter', $id, mitarbeiterId($data));

    resultInfo(true, 'SENT', ['results' => ['entry' => [
        'channel'       => 'email',
        'sent_at'       => date('c'),
        'recipient'     => implode(', ', array_column($toList, 'email')),
        'subject'       => $subject,
        'status'        => 'sent',
        'employee_name' => $print['employee']['name'] ?? '',
    ]]]);
}
