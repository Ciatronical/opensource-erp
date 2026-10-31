<?php
// backend/api/mailings/mailings.php
//
// Rundschreiben — eine Nachricht an bis zu 300 Kunden/Lieferanten.
//
// Grundsätze:
//   * Kosten vermeiden: WhatsApp-Freitext ist nur im 24-Stunden-Fenster nach
//     einer Kundennachricht kostenlos. Außerhalb geht WhatsApp nur, wenn der
//     Benutzer ausdrücklich eine (kostenpflichtige) Vorlage wählt — sonst
//     weicht das Rundschreiben auf Telegram oder E-Mail aus.
//   * Nicht gesperrt werden: Versand in Etappen (Anzahl je Etappe, Pause
//     dazwischen, Zeitfenster am Tag) mit zufälligem Abstand zwischen zwei
//     Nachrichten. Der Hintergrunddienst backend/cli/mailings.php holt die
//     fälligen Etappen; solange die Seite offen ist, stößt sie die Etappen
//     auch selbst an.
//   * Ein Empfänger, ein Kanal: der erste verfügbare in der gewählten
//     Reihenfolge. Gleiche Ziele (eine Nummer bei zwei Kunden) nur einmal.
//
// Tabellen: mailings, mailing_recipients, mailing_lists, mailing_list_members
// (backend/upstall/crm/company_schema.sql). Die Telegram-Chat-ID eines Kunden
// steht in customer_ext.telegram_chat_id und entsteht im Webhook
// (backend/webhook/telegram.php), wenn der Kunde dem Bot seine Rufnummer teilt.

require_once __DIR__.'/../lib/mitarbeiter.php';
require_once __DIR__.'/../email/mailer.php';

/** Höchstzahl Empfänger je Rundschreiben und je Verteiler. */
const MAILING_MAX_RECIPIENTS = 300;

/**
 * Recht wie bei den Briefen: Verkaufs- oder Einkaufsbriefe.
 */
function mailingPermit(): void {
    permit(['sales_letter_edit', 'purchase_letter_edit'], false);
}

/**
 * CTE-Kette, die zu einer Menge src(cv_src, cv_id) alles liefert, was ein
 * Rundschreiben über einen Empfänger wissen muss: Name, Nummer, Anrede,
 * Telegram-Chat-ID, WhatsApp-taugliche Rufnummer (offenes Fenster? bekannt?)
 * und E-Mail-Adresse. Wird an eine WITH-Kette angehängt, die `src` definiert.
 *
 * WhatsApp-Nummer: bevorzugt eine Nummer, über die schon WhatsApp lief
 * (offenes Fenster zuerst), dann als mobil bezeichnete Nummern, dann Nummern,
 * die nach Mobilfunk aussehen. Festnetz wird nie als WhatsApp-Ziel gewählt.
 */
function mailingInfoCtes(): string {
    // Bewusst ein Durchlauf über cvbase mit LATERAL-Unterabfragen je Empfänger
    // (Index-Zugriffe auf contacts und whatsapp_messages): das skaliert linear.
    // Getrennte CTEs, die anschließend verbunden werden, geraten bei den
    // Schätzungen des Planers in verschachtelte Schleifen über alle Zeilen
    // (6.000 Kunden → Minuten statt Sekundenbruchteile).
    return <<<SQL
        cfg AS (
            SELECT COALESCE((SELECT value FROM defaults_oserp WHERE key = 'whatsapp_country_code'), '49') AS cc
        ),
        cvbase AS (
            SELECT s.cv_src, s.cv_id, btrim(c.name) AS name, COALESCE(c.customernumber, '') AS number,
                   COALESCE(c.greeting, '') AS greeting, COALESCE(c.natural_person, false) AS natural_person,
                   COALESCE(lg.template_code, 'DE') AS lang, c.email AS cv_email, c.phone AS cv_phone,
                   COALESCE(c.obsolete, false) AS obsolete, c.city, c.street, c.zipcode, c.country,
                   x.telegram_chat_id, x.phone_numbers, x.emails
            FROM src s
            JOIN customer c ON s.cv_src = 'C' AND c.id = s.cv_id
            LEFT JOIN customer_ext x ON x.customer_id = c.id
            LEFT JOIN language lg ON lg.id = c.language_id
            UNION ALL
            SELECT s.cv_src, s.cv_id, btrim(v.name), COALESCE(v.vendornumber, ''),
                   COALESCE(v.greeting, ''), COALESCE(v.natural_person, false),
                   COALESCE(lg.template_code, 'DE'), v.email, v.phone,
                   COALESCE(v.obsolete, false), v.city, v.street, v.zipcode, v.country,
                   x.telegram_chat_id, x.phone_numbers, x.emails
            FROM src s
            JOIN vendor v ON s.cv_src = 'V' AND v.id = s.cv_id
            LEFT JOIN vendor_ext x ON x.vendor_id = v.id
            LEFT JOIN language lg ON lg.id = v.language_id
        ),
        info AS (
            SELECT b.cv_src, b.cv_id, b.name, b.number, b.obsolete, b.city, b.street, b.zipcode,
                   -- Postanschrift für den E-Brief: Straße, PLZ und Ort müssen da sein
                   (btrim(COALESCE(b.street, '')) <> '' AND btrim(COALESCE(b.zipcode, '')) <> '' AND btrim(COALESCE(b.city, '')) <> '') AS address_ok,
                   concat_ws(', ', b.name, NULLIF(btrim(COALESCE(b.street, '')), ''), NULLIF(btrim(concat_ws(' ', b.zipcode, b.city)), ''), NULLIF(btrim(COALESCE(b.country, '')), '')) AS address,
                   NULLIF(btrim(b.telegram_chat_id), '') AS telegram_chat_id,
                   ph.phone AS whatsapp_phone,
                   COALESCE(ph.wa_window, false) AS whatsapp_window,
                   COALESCE(ph.wa_known, false) AS whatsapp_known,
                   em.email,
                   CASE
                       WHEN cp.cp_id IS NOT NULL AND cp.cp_gender = 'f' THEN
                           CASE WHEN b.lang = 'EN' THEN 'Dear Ms. ' ELSE 'Sehr geehrte Frau ' END || btrim(concat_ws(' ', cp.cp_title, cp.cp_name))
                       WHEN cp.cp_id IS NOT NULL AND cp.cp_gender = 'm' THEN
                           CASE WHEN b.lang = 'EN' THEN 'Dear Mr. ' ELSE 'Sehr geehrter Herr ' END || btrim(concat_ws(' ', cp.cp_title, cp.cp_name))
                       WHEN cp.cp_id IS NOT NULL THEN
                           CASE WHEN b.lang = 'EN' THEN 'Dear ' ELSE 'Guten Tag ' END || btrim(concat_ws(' ', cp.cp_givenname, cp.cp_name))
                       WHEN b.name <> '' AND (b.greeting ILIKE 'frau%' OR b.greeting ILIKE 'ms%' OR b.greeting ILIKE 'mrs%') THEN
                           CASE WHEN b.lang = 'EN' THEN 'Dear Ms. ' ELSE 'Sehr geehrte Frau ' END || b.name
                       WHEN b.name <> '' AND (b.greeting ILIKE 'herr%' OR b.greeting ILIKE 'mr%') THEN
                           CASE WHEN b.lang = 'EN' THEN 'Dear Mr. ' ELSE 'Sehr geehrter Herr ' END || b.name
                       WHEN b.natural_person AND b.name <> '' THEN
                           CASE WHEN b.lang = 'EN' THEN 'Dear ' ELSE 'Guten Tag ' END || b.name
                       ELSE CASE WHEN b.lang = 'EN' THEN 'Dear Sir or Madam' ELSE 'Sehr geehrte Damen und Herren' END
                   END AS salutation
            FROM cvbase b
            CROSS JOIN cfg
            -- WhatsApp-taugliche Nummer: zuerst Nummern mit WhatsApp-Verlauf (offenes
            -- Fenster zuerst), dann als mobil bezeichnete, dann nach Mobilfunk aussehende.
            -- Festnetz wird nie gewählt.
            LEFT JOIN LATERAL (
                SELECT '+' || x.digits AS phone, x.wa_window, x.wa_known
                FROM (
                    SELECT n.digits, p.prio,
                           EXISTS (SELECT 1 FROM whatsapp_messages m
                                   WHERE m.phone_number = '+' || n.digits AND m.direction = 'I'
                                     AND m.itime > now() - interval '24 hours') AS wa_window,
                           EXISTS (SELECT 1 FROM whatsapp_messages m WHERE m.phone_number = '+' || n.digits) AS wa_known,
                           (p.label ~* '(mobil|handy|whatsapp|cell)') AS labelled_mobile,
                           (n.digits ~ ('^' || cfg.cc || '1[5-7]')) AS looks_mobile
                    FROM (
                        SELECT 'Telefon'::text AS label, b.cv_phone AS raw, 2 AS prio
                        UNION ALL
                        SELECT e->>'label', e->>'number', 1 FROM jsonb_array_elements(COALESCE(b.phone_numbers, '[]'::jsonb)) e
                        UNION ALL
                        SELECT 'Mobil', c.cp_mobile1, 3 FROM contacts c WHERE c.cp_cv_id = b.cv_id
                        UNION ALL
                        SELECT 'Mobil', c.cp_mobile2, 4 FROM contacts c WHERE c.cp_cv_id = b.cv_id
                    ) p,
                    LATERAL (
                        SELECT CASE WHEN r.d LIKE '00%' THEN substr(r.d, 3)
                                    WHEN r.d LIKE '0%'  THEN cfg.cc || substr(r.d, 2)
                                    ELSE r.d END AS digits
                        FROM (SELECT regexp_replace(COALESCE(p.raw, ''), '[^0-9]', '', 'g') AS d) r
                    ) n
                    WHERE length(n.digits) >= 7
                ) x
                WHERE x.wa_known OR x.labelled_mobile OR x.looks_mobile
                ORDER BY x.wa_window DESC, x.wa_known DESC, x.labelled_mobile DESC, x.looks_mobile DESC, x.prio
                LIMIT 1
            ) ph ON true
            -- E-Mail: Stammsatz, Zusatzadressen, Haupt-Ansprechperson, weitere Ansprechpersonen
            LEFT JOIN LATERAL (
                SELECT lower(btrim(y.email)) AS email
                FROM (
                    SELECT b.cv_email AS email, 1 AS prio
                    UNION ALL SELECT el->>'email', 2 FROM jsonb_array_elements(COALESCE(b.emails, '[]'::jsonb)) el
                    UNION ALL SELECT c.cp_email, CASE WHEN c.cp_main THEN 3 ELSE 4 END FROM contacts c WHERE c.cp_cv_id = b.cv_id
                ) y
                WHERE btrim(COALESCE(y.email, '')) ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'
                ORDER BY y.prio
                LIMIT 1
            ) em ON true
            -- Ansprechperson für die Anrede (Hauptansprechperson zuerst)
            LEFT JOIN LATERAL (
                SELECT c.cp_id, c.cp_gender, c.cp_title, c.cp_givenname, c.cp_name
                FROM contacts c
                WHERE c.cp_cv_id = b.cv_id AND btrim(COALESCE(c.cp_name, '')) <> ''
                ORDER BY c.cp_main DESC, c.cp_id
                LIMIT 1
            ) cp ON true
        )
    SQL;
}

/**
 * JSON-Ausdruck mit der Kanal-Verfügbarkeit der Firma (für Oberfläche und
 * Kanalplanung): WhatsApp (Zugang + Nummer), Telegram (eingeschaltet + Token),
 * E-Mail (SMTP), dazu die Kosten je WhatsApp-Vorlage, falls hinterlegt.
 */
function mailingConfigJson(): string {
    return <<<SQL
        (SELECT json_build_object(
            'whatsapp', COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'whatsapp_access_token'), false)
                        AND COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'whatsapp_phone_number_id'), false),
            'telegram', COALESCE((SELECT value = '1' FROM defaults_oserp WHERE key = 'telegram_enabled'), false)
                        AND COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'telegram_bot_token'), false),
            'telegram_bot', COALESCE((SELECT btrim(value) FROM defaults_oserp WHERE key = 'telegram_bot_username'), ''),
            'email', COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'email_smtp_host'), false)
                     AND COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'email_username'), false),
            'whatsapp_template_cost', COALESCE((SELECT NULLIF(btrim(value), '')::numeric FROM defaults_oserp WHERE key = 'whatsapp_template_cost'), 0),
            -- E-Brief: nur wenn der Postdienst (Firmenkonfiguration → CRM → Briefversand) vollständig eingerichtet ist
            'letter', COALESCE((SELECT CASE btrim(value)
                        WHEN 'pinag' THEN COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'eletter_hostname'), false)
                                      AND COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'eletter_username'), false)
                                      AND COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'eletter_folder'), false)
                        WHEN 'letterxpress' THEN COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'eletter_lxp_username'), false)
                                      AND COALESCE((SELECT btrim(value) <> '' FROM defaults_oserp WHERE key = 'eletter_lxp_apikey'), false)
                        ELSE false END FROM defaults_oserp WHERE key = 'eletter_provider'), false),
            'letter_provider', COALESCE((SELECT CASE btrim(value) WHEN 'pinag' THEN 'PIN AG' WHEN 'letterxpress' THEN 'LetterXpress' ELSE btrim(value) END FROM defaults_oserp WHERE key = 'eletter_provider'), ''),
            'letter_cost', COALESCE((SELECT NULLIF(btrim(value), '')::numeric FROM defaults_oserp WHERE key = 'mailing_letter_cost'), 0),
            'company', COALESCE((SELECT company FROM defaults LIMIT 1), ''),
            'max_recipients', :max_recipients::int
        ))
    SQL;
}

/**
 * Bot-Name für den Hinweis „t.me/<Bot>“ nachtragen, falls in der Konfiguration
 * nur das Token steht: einmalig bei Telegram erfragen und in defaults_oserp
 * (telegram_bot_username) ablegen, damit kein weiterer Aufruf nötig ist.
 */
function mailingEnsureTelegramBotName($db): void {
    $row = $db->getOne(<<<SQL
        SELECT (SELECT btrim(value) FROM defaults_oserp WHERE key = 'telegram_bot_token') AS token,
               (SELECT btrim(value) FROM defaults_oserp WHERE key = 'telegram_bot_username') AS username,
               (SELECT value = '1' FROM defaults_oserp WHERE key = 'telegram_enabled') AS enabled
    SQL);
    if (empty($row['enabled']) || empty($row['token']) || !empty($row['username'])) return;
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $res = json_decode((string)@file_get_contents('https://api.telegram.org/bot'.$row['token'].'/getMe', false, $ctx), true);
    $name = trim((string)($res['result']['username'] ?? ''));
    if ($name === '') return;
    $db->execute(<<<SQL
        INSERT INTO defaults_oserp (key, value) VALUES ('telegram_bot_username', :name)
        ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value
    SQL, [':name' => $name]);
}

/**
 * Übersicht: alle Rundschreiben mit Zählern je Status, alle Verteiler mit
 * Mitgliederzahl und die Kanal-Verfügbarkeit.
 *
 * @testdata {}
 */
function getMailings($data) {
    mailingPermit();
    $db = DbhCompany::begin();
    mailingEnsureTelegramBotName($db);
    $cfg = mailingConfigJson();
    $row = $db->getOne(<<<SQL
        SELECT json_build_object(
            'mailings', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', m.id, 'title', m.title, 'subject', m.subject, 'status', m.status, 'channels', m.channels,
                    'email_mode', m.email_mode, 'letter_mode', m.letter_mode, 'itime', m.itime, 'mtime', m.mtime, 'started_at', m.started_at, 'finished_at', m.finished_at,
                    'next_batch_at', m.next_batch_at, 'last_error', m.last_error,
                    'send_from', m.send_from, 'send_until', m.send_until,
                    'batch_size', m.batch_size, 'batch_pause_minutes', m.batch_pause_minutes,
                    'employee_name', e.name,
                    'total', cnt.total, 'sent', cnt.sent, 'failed', cnt.failed, 'pending', cnt.pending, 'skipped', cnt.skipped,
                    'by_channel', cnt.by_channel
                ) ORDER BY m.itime DESC)
                FROM mailings m
                LEFT JOIN employee e ON e.id = m.employee_id
                LEFT JOIN LATERAL (
                    SELECT count(*) AS total,
                           count(*) FILTER (WHERE r.status = 'sent') AS sent,
                           count(*) FILTER (WHERE r.status = 'failed') AS failed,
                           count(*) FILTER (WHERE r.status = 'pending') AS pending,
                           count(*) FILTER (WHERE r.status = 'skipped') AS skipped,
                           COALESCE((SELECT json_object_agg(ch, n) FROM (
                                SELECT COALESCE(r2.channel, 'none') AS ch, count(*) AS n
                                FROM mailing_recipients r2 WHERE r2.mailing_id = m.id AND r2.status = 'sent' GROUP BY 1
                                UNION ALL
                                SELECT 'email_extra', count(*) FROM mailing_recipients r3
                                WHERE r3.mailing_id = m.id AND r3.email_status = 'sent' HAVING count(*) > 0
                                UNION ALL
                                SELECT 'letter_extra', count(*) FROM mailing_recipients r4
                                WHERE r4.mailing_id = m.id AND r4.letter_status = 'sent' HAVING count(*) > 0
                           ) x), '{}'::json) AS by_channel
                    FROM mailing_recipients r WHERE r.mailing_id = m.id
                ) cnt ON true
            ), '[]'::json),
            'lists', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', l.id, 'name', l.name, 'description', l.description, 'itime', l.itime, 'mtime', l.mtime,
                    'members', (SELECT count(*) FROM mailing_list_members mm WHERE mm.mailing_list_id = l.id)
                ) ORDER BY l.name)
                FROM mailing_lists l
            ), '[]'::json),
            'config', {$cfg}
        ) AS result
    SQL, [':max_recipients' => MAILING_MAX_RECIPIENTS]);
    resultInfo(true, '', json_decode($row['result'], true));
}

/**
 * Ein Rundschreiben mit seinen Empfängern (inklusive Kanal-Verfügbarkeit je
 * Empfänger), die genehmigten WhatsApp-Vorlagen, Verteiler, Kundentypen und
 * die Kanal-Verfügbarkeit der Firma. id = 0 liefert die Vorgaben für ein
 * neues Rundschreiben; copy_of übernimmt Text und Empfänger eines anderen.
 *
 * @param int $data['id']      Rundschreiben (0 = neu)
 * @param int $data['copy_of'] Vorlage: Text und Empfänger dieses Rundschreibens übernehmen
 * @testdata {"id": 0}
 */
function getMailing($data) {
    mailingPermit();
    $db = DbhCompany::begin();
    mailingEnsureTelegramBotName($db);
    $info = mailingInfoCtes();
    $cfg = mailingConfigJson();
    $row = $db->getOne(<<<SQL
        WITH p AS (
            SELECT NULLIF(:id::int, 0) AS id, NULLIF(:copy_of::int, 0) AS copy_of
        ),
        m AS (
            SELECT ml.* FROM mailings ml, p WHERE ml.id = COALESCE(p.id, p.copy_of)
        ),
        src AS (
            SELECT r.cv_src, r.cv_id FROM mailing_recipients r, p WHERE r.mailing_id = COALESCE(p.id, p.copy_of)
        ),
        {$info}
        SELECT json_build_object(
            'found', (SELECT id FROM p) IS NULL OR EXISTS (SELECT 1 FROM m),
            'is_copy', (SELECT id FROM p) IS NULL AND (SELECT copy_of FROM p) IS NOT NULL,
            'mailing', COALESCE(
                (SELECT row_to_json(m) FROM m),
                (SELECT row_to_json(d) FROM (
                    SELECT 0 AS id, '' AS title, '' AS subject, '' AS body_text, '' AS body_html,
                           '["whatsapp","telegram"]'::jsonb AS channels,
                           -- Vorgabe: WhatsApp auch außerhalb des Fensters, per Vorlage (Chat-Vorlage der Firma, sonst die erste passende)
                           COALESCE(
                               (SELECT t.id FROM whatsapp_templates t
                                WHERE t.status = 'approved' AND t.body_text LIKE '%{{2}}%' AND t.body_text NOT LIKE '%{{3}}%'
                                  AND t.id = (SELECT NULLIF(btrim(value), '')::int FROM defaults_oserp WHERE key = 'whatsapp_tpl_chat')),
                               (SELECT t.id FROM whatsapp_templates t
                                WHERE t.status = 'approved' AND t.body_text LIKE '%{{2}}%' AND t.body_text NOT LIKE '%{{3}}%'
                                ORDER BY t.display_name LIMIT 1)
                           ) AS whatsapp_template_id,
                           'always' AS email_mode, 'off' AS letter_mode,
                           20 AS batch_size, 10 AS batch_pause_minutes, '09:00'::time AS send_from, '20:00'::time AS send_until,
                           'draft' AS status, NULL::int AS mailing_list_id, NULL::timestamptz AS next_batch_at,
                           NULL::timestamptz AS started_at, NULL::timestamptz AS finished_at, NULL::text AS last_error
                ) d)
            ),
            'recipients', COALESCE((
                SELECT json_agg(json_build_object(
                    'cv_src', r.cv_src, 'cv_id', r.cv_id, 'name', i.name, 'number', i.number, 'city', i.city,
                    'obsolete', COALESCE(i.obsolete, i.cv_id IS NULL), 'salutation', i.salutation,
                    'telegram_chat_id', i.telegram_chat_id,
                    'whatsapp_phone', i.whatsapp_phone, 'whatsapp_window', i.whatsapp_window, 'whatsapp_known', i.whatsapp_known,
                    'email', i.email, 'street', i.street, 'zipcode', i.zipcode, 'address_ok', COALESCE(i.address_ok, false), 'address', i.address,
                    'channel', r.channel, 'target', r.target, 'status', r.status, 'error', r.error,
                    'sent_at', r.sent_at, 'batch_no', r.batch_no,
                    'email_target', r.email_target, 'email_status', r.email_status, 'email_error', r.email_error, 'email_sent_at', r.email_sent_at,
                    'letter_target', r.letter_target, 'letter_status', r.letter_status, 'letter_error', r.letter_error, 'letter_sent_at', r.letter_sent_at
                ) ORDER BY i.name, i.cv_id)
                FROM mailing_recipients r
                JOIN p ON r.mailing_id = COALESCE(p.id, p.copy_of)
                LEFT JOIN info i ON i.cv_src = r.cv_src AND i.cv_id = r.cv_id
            ), '[]'::json),
            'templates', COALESCE((
                SELECT json_agg(json_build_object('id', t.id, 'name', t.name, 'display_name', t.display_name,
                                                  'category', t.category, 'body_text', t.body_text) ORDER BY t.display_name)
                FROM whatsapp_templates t
                WHERE t.status = 'approved'
                  -- nur Vorlagen mit genau zwei Platzhaltern: {{1}} = Name, {{2}} = Text
                  AND t.body_text LIKE '%{{2}}%' AND t.body_text NOT LIKE '%{{3}}%'
            ), '[]'::json),
            'lists', COALESCE((
                SELECT json_agg(json_build_object('id', l.id, 'name', l.name,
                        'members', (SELECT count(*) FROM mailing_list_members mm WHERE mm.mailing_list_id = l.id)) ORDER BY l.name)
                FROM mailing_lists l
            ), '[]'::json),
            'businesses', COALESCE((
                SELECT json_agg(json_build_object('id', b.id, 'description', b.description) ORDER BY b.description) FROM business b
            ), '[]'::json),
            'config', {$cfg}
        ) AS result
    SQL, [':id' => intval($data['id'] ?? 0), ':copy_of' => intval($data['copy_of'] ?? 0), ':max_recipients' => MAILING_MAX_RECIPIENTS]);
    resultInfo(true, '', json_decode($row['result'], true));
}

/**
 * Rundschreiben speichern (Text, Kanäle, Etappen) und Empfängerliste
 * abgleichen. Empfänger und Einstellungen sind nur änderbar, solange das
 * Rundschreiben nicht läuft; der Text bleibt auch pausiert änderbar.
 *
 * @param array $data['mailing']    id, title, subject, body_text, body_html, channels[] (Messenger-Reihenfolge), email_mode (always|fallback|off), letter_mode (off|fallback|always), whatsapp_template_id, batch_size, batch_pause_minutes, send_from, send_until, mailing_list_id
 * @param array $data['recipients'] [{cv_src, cv_id}] — vollständige Liste (höchstens 300)
 * @testdata {"mailing": {"id": 0, "title": "Neujahrsgrüße", "subject": "Frohes neues Jahr", "body_text": "{{anrede}}, wir wünschen ein gutes neues Jahr!", "channels": ["whatsapp","telegram"], "email_mode": "always", "batch_size": 20, "batch_pause_minutes": 10, "send_from": "09:00", "send_until": "20:00"}, "recipients": [{"cv_src": "C", "cv_id": 1}]}
 */
function saveMailing($data) {
    mailingPermit();
    $db = DbhCompany::begin();
    $m = is_array($data['mailing'] ?? null) ? $data['mailing'] : [];
    $recipients = is_array($data['recipients'] ?? null) ? $data['recipients'] : [];

    $id = intval($m['id'] ?? 0);
    $title = trim((string)($m['title'] ?? ''));
    if ($title === '') {
        resultInfo(false, 'MAILING_TITLE_REQUIRED', 'Bitte einen Titel angeben');
        return;
    }
    // channels = Messenger in Reihenfolge; E-Mail wird über email_mode geregelt
    $channels = array_values(array_unique(array_filter(array_map('strval', is_array($m['channels'] ?? null) ? $m['channels'] : []),
                                          fn($c) => in_array($c, ['whatsapp', 'telegram'], true))));
    $emailMode = in_array($m['email_mode'] ?? '', ['always', 'fallback', 'off'], true) ? $m['email_mode'] : 'always';
    $letterMode = in_array($m['letter_mode'] ?? '', ['always', 'fallback', 'off'], true) ? $m['letter_mode'] : 'off';
    if (!$channels && $emailMode === 'off' && $letterMode === 'off') {
        resultInfo(false, 'MAILING_CHANNEL_REQUIRED', 'Mindestens ein Messenger oder E-Mail muss gewählt sein');
        return;
    }
    $clean = [];
    foreach ($recipients as $r) {
        $src = strtoupper(substr((string)($r['cv_src'] ?? ''), 0, 1));
        $cid = intval($r['cv_id'] ?? 0);
        if (($src === 'C' || $src === 'V') && $cid > 0) $clean[$src.$cid] = ['cv_src' => $src, 'cv_id' => $cid];
    }
    if (count($clean) > MAILING_MAX_RECIPIENTS) {
        resultInfo(false, 'MAILING_TOO_MANY_RECIPIENTS', 'Höchstens '.MAILING_MAX_RECIPIENTS.' Empfänger je Rundschreiben');
        return;
    }

    $params = [
        ':title' => $title,
        ':subject' => trim((string)($m['subject'] ?? '')),
        ':body_text' => (string)($m['body_text'] ?? ''),
        ':body_html' => (string)($m['body_html'] ?? ''),
        ':channels' => json_encode($channels),
        ':email_mode' => $emailMode,
        ':letter_mode' => $letterMode,
        ':whatsapp_template_id' => intval($m['whatsapp_template_id'] ?? 0) ?: null,
        ':batch_size' => max(1, min(100, intval($m['batch_size'] ?? 20))),
        ':batch_pause_minutes' => max(0, min(1440, intval($m['batch_pause_minutes'] ?? 10))),
        ':send_from' => preg_match('/^\d{2}:\d{2}/', (string)($m['send_from'] ?? '')) ? substr($m['send_from'], 0, 5) : '09:00',
        ':send_until' => preg_match('/^\d{2}:\d{2}/', (string)($m['send_until'] ?? '')) ? substr($m['send_until'], 0, 5) : '20:00',
        ':mailing_list_id' => intval($m['mailing_list_id'] ?? 0) ?: null,
        ':employee_id' => mitarbeiterId($data),
    ];

    if ($id > 0) {
        $params[':id'] = $id;
        // Einstellungen nur außerhalb des laufenden Versands; Text immer (gilt für die restlichen Etappen)
        $row = $db->getOne(<<<SQL
            UPDATE mailings SET
                title = :title, subject = :subject, body_text = :body_text, body_html = :body_html,
                channels = CASE WHEN status = 'running' THEN channels ELSE :channels::jsonb END,
                email_mode = CASE WHEN status = 'running' THEN email_mode ELSE :email_mode END,
                letter_mode = CASE WHEN status = 'running' THEN letter_mode ELSE :letter_mode END,
                whatsapp_template_id = CASE WHEN status = 'running' THEN whatsapp_template_id ELSE :whatsapp_template_id END,
                batch_size = :batch_size, batch_pause_minutes = :batch_pause_minutes,
                send_from = :send_from::time, send_until = :send_until::time,
                mailing_list_id = :mailing_list_id, employee_id = COALESCE(employee_id, :employee_id), mtime = now()
            WHERE id = :id
            RETURNING id, status
        SQL, $params);
        if (!$row) {
            resultInfo(false, 'MAILING_NOT_FOUND', 'Rundschreiben nicht gefunden');
            return;
        }
    } else {
        $row = $db->getOne(<<<SQL
            INSERT INTO mailings (title, subject, body_text, body_html, channels, email_mode, letter_mode, whatsapp_template_id, batch_size,
                                  batch_pause_minutes, send_from, send_until, mailing_list_id, employee_id)
            VALUES (:title, :subject, :body_text, :body_html, :channels::jsonb, :email_mode, :letter_mode, :whatsapp_template_id, :batch_size,
                    :batch_pause_minutes, :send_from::time, :send_until::time, :mailing_list_id, :employee_id)
            RETURNING id, status
        SQL, $params);
        $id = intval($row['id']);
    }

    // Empfänger abgleichen: Entfernte löschen (nicht die schon versendeten), neue anlegen
    $sync = $db->getOne(<<<SQL
        WITH want AS (
            SELECT DISTINCT (e->>'cv_src')::char(1) AS cv_src, (e->>'cv_id')::int AS cv_id
            FROM jsonb_array_elements(:recipients::jsonb) e
        ),
        del AS (
            DELETE FROM mailing_recipients r
            WHERE r.mailing_id = :id AND r.status IN ('pending', 'skipped')
              AND NOT EXISTS (SELECT 1 FROM want w WHERE w.cv_src = r.cv_src AND w.cv_id = r.cv_id)
            RETURNING 1
        ),
        ins AS (
            INSERT INTO mailing_recipients (mailing_id, cv_src, cv_id)
            SELECT :id, w.cv_src, w.cv_id FROM want w
            ON CONFLICT (mailing_id, cv_src, cv_id) DO NOTHING
            RETURNING 1
        )
        SELECT (SELECT count(*) FROM del) AS deleted, (SELECT count(*) FROM ins) AS inserted,
               -- innerhalb des Statements sieht der Zähler nur den alten Stand: Bestand − gelöscht + neu
               (SELECT count(*) FROM mailing_recipients WHERE mailing_id = :id) - (SELECT count(*) FROM del) + (SELECT count(*) FROM ins) AS total
    SQL, [':id' => $id, ':recipients' => json_encode(array_values($clean))]);

    resultInfo(true, '', ['id' => $id, 'status' => $row['status'], 'recipients' => intval($sync['total'] ?? 0)]);
}

/**
 * Rundschreiben löschen (mit Empfängern). Laufende müssen zuerst abgebrochen werden.
 *
 * @param int $data['id']
 * @testdata {"id": 1}
 */
function deleteMailing($data) {
    mailingPermit();
    $db = DbhCompany::begin();
    $row = $db->getOne("DELETE FROM mailings WHERE id = :id AND status <> 'running' RETURNING id", [':id' => intval($data['id'] ?? 0)]);
    if (!$row) {
        resultInfo(false, 'MAILING_NOT_DELETABLE', 'Rundschreiben nicht gefunden oder noch im Versand');
        return;
    }
    resultInfo(true, '', ['id' => intval($row['id'])]);
}

/**
 * Kunden/Lieferanten für die Empfängerliste suchen (Name, Nummer, Ort, E-Mail,
 * Rufnummer, Stichworte) — mit Kanal-Verfügbarkeit je Treffer.
 *
 * @param string $data['search'] Suchbegriff (ab 2 Zeichen)
 * @param string $data['src']    C = Kunden, V = Lieferanten, leer = beide
 * @testdata {"search": "Müller", "src": ""}
 */
function searchMailingRecipients($data) {
    mailingPermit();
    $search = trim((string)($data['search'] ?? ''));
    if (mb_strlen($search) < 2) {
        resultInfo(true, '', ['results' => []]);
        return;
    }
    $db = DbhCompany::begin();
    $info = mailingInfoCtes();
    $row = $db->getOne(<<<SQL
        WITH p AS (SELECT :search::text AS q, NULLIF(:src, '')::text AS src),
        hits AS (
            -- Rang: Nummer exakt/beginnend vor Namensanfang vor „enthält“, dann erst die
            -- Nebenfelder (Ort, E-Mail, Stichworte, Rufnummer) – begrenzt wird NACH dem Sortieren.
            SELECT 'C'::char(1) AS cv_src, c.id AS cv_id, c.name,
                   CASE WHEN c.customernumber = p.q THEN 0
                        WHEN c.customernumber ILIKE p.q || '%' THEN 1
                        WHEN c.name ILIKE p.q || '%' THEN 2
                        WHEN c.name ILIKE '%' || p.q || '%' THEN 3
                        ELSE 4 END AS rank
            FROM customer c LEFT JOIN customer_ext x ON x.customer_id = c.id, p
            WHERE (p.src IS NULL OR p.src = 'C') AND NOT COALESCE(c.obsolete, false)
              AND (c.name ILIKE '%' || p.q || '%' OR c.customernumber ILIKE p.q || '%' OR c.city ILIKE p.q || '%'
                   OR c.email ILIKE '%' || p.q || '%' OR x.keywords ILIKE '%' || p.q || '%'
                   OR regexp_replace(COALESCE(c.phone, ''), '[^0-9]', '', 'g') LIKE '%' || regexp_replace(p.q, '[^0-9]', '', 'g') || '%'
                      AND length(regexp_replace(p.q, '[^0-9]', '', 'g')) >= 4)
            UNION ALL
            SELECT 'V', v.id, v.name,
                   CASE WHEN v.vendornumber = p.q THEN 0
                        WHEN v.vendornumber ILIKE p.q || '%' THEN 1
                        WHEN v.name ILIKE p.q || '%' THEN 2
                        WHEN v.name ILIKE '%' || p.q || '%' THEN 3
                        ELSE 4 END
            FROM vendor v LEFT JOIN vendor_ext x ON x.vendor_id = v.id, p
            WHERE (p.src IS NULL OR p.src = 'V') AND NOT COALESCE(v.obsolete, false)
              AND (v.name ILIKE '%' || p.q || '%' OR v.vendornumber ILIKE p.q || '%' OR v.city ILIKE p.q || '%'
                   OR v.email ILIKE '%' || p.q || '%' OR x.keywords ILIKE '%' || p.q || '%')
        ),
        src AS (
            -- knapp über der Anzeigegrenze der Oberfläche (8): schon in der Liste stehende fallen dort noch weg
            SELECT cv_src, cv_id, rank FROM hits ORDER BY rank, name LIMIT 12
        ),
        {$info}
        SELECT COALESCE(json_agg(json_build_object(
            'cv_src', i.cv_src, 'cv_id', i.cv_id, 'name', i.name, 'number', i.number, 'city', i.city,
            'salutation', i.salutation, 'telegram_chat_id', i.telegram_chat_id,
            'whatsapp_phone', i.whatsapp_phone, 'whatsapp_window', i.whatsapp_window, 'whatsapp_known', i.whatsapp_known,
            'email', i.email, 'address_ok', i.address_ok, 'address', i.address
        ) ORDER BY s.rank, i.name), '[]'::json) AS result
        FROM info i JOIN src s ON s.cv_src = i.cv_src AND s.cv_id = i.cv_id
    SQL, [':search' => $search, ':src' => strtoupper(substr((string)($data['src'] ?? ''), 0, 1))]);
    resultInfo(true, '', ['results' => json_decode($row['result'], true)]);
}

/**
 * Empfänger vorschlagen: nach Kunde/Lieferant, Kundentyp, letztem Umsatz
 * (Rechnung in den letzten N Monaten), Ort und erreichbarem Kanal. Liefert
 * die Gesamtzahl der Treffer und höchstens 300 Empfänger mit Kanal-Verfügbarkeit.
 *
 * @param string $data['src']         C, V oder leer
 * @param int    $data['business_id'] Kundentyp (nur Kunden)
 * @param int    $data['months']      Umsatz in den letzten N Monaten (0 = egal)
 * @param string $data['city']        Ort beginnt mit
 * @param string $data['channel']     any | telegram | whatsapp_window | whatsapp | email | none
 * @param int    $data['limit']       höchstens 300
 * @testdata {"src": "C", "business_id": 0, "months": 12, "city": "", "channel": "any", "limit": 300}
 */
function suggestMailingRecipients($data) {
    mailingPermit();
    $db = DbhCompany::begin();
    $info = mailingInfoCtes();
    $channel = (string)($data['channel'] ?? 'any');
    if (!in_array($channel, ['any', 'telegram', 'whatsapp_window', 'whatsapp', 'email', 'none'], true)) $channel = 'any';
    $row = $db->getOne(<<<SQL
        WITH p AS (
            SELECT NULLIF(:src, '')::text AS src, NULLIF(:business_id::int, 0) AS business_id,
                   NULLIF(:months::int, 0) AS months, NULLIF(btrim(:city), '')::text AS city,
                   :channel::text AS channel, LEAST(:limit::int, :max_recipients::int) AS lim
        ),
        src AS (
            SELECT 'C'::char(1) AS cv_src, c.id AS cv_id
            FROM customer c, p
            WHERE (p.src IS NULL OR p.src = 'C') AND NOT COALESCE(c.obsolete, false)
              AND (p.business_id IS NULL OR c.business_id = p.business_id)
              AND (p.city IS NULL OR c.city ILIKE p.city || '%')
              AND (p.months IS NULL OR EXISTS (
                    SELECT 1 FROM ar WHERE ar.customer_id = c.id AND ar.transdate >= CURRENT_DATE - (p.months || ' months')::interval))
            UNION ALL
            SELECT 'V', v.id
            FROM vendor v, p
            WHERE (p.src IS NULL OR p.src = 'V') AND NOT COALESCE(v.obsolete, false)
              AND p.business_id IS NULL
              AND (p.city IS NULL OR v.city ILIKE p.city || '%')
              AND (p.months IS NULL OR EXISTS (
                    SELECT 1 FROM ap WHERE ap.vendor_id = v.id AND ap.transdate >= CURRENT_DATE - (p.months || ' months')::interval))
        ),
        {$info},
        hits AS (
            SELECT i.* FROM info i, p
            WHERE CASE p.channel
                    WHEN 'telegram' THEN i.telegram_chat_id IS NOT NULL
                    WHEN 'whatsapp_window' THEN i.whatsapp_phone IS NOT NULL AND i.whatsapp_window
                    WHEN 'whatsapp' THEN i.whatsapp_phone IS NOT NULL
                    WHEN 'email' THEN i.email IS NOT NULL
                    WHEN 'none' THEN i.telegram_chat_id IS NULL AND i.whatsapp_phone IS NULL AND i.email IS NULL
                    ELSE true
                  END
        )
        SELECT json_build_object(
            'total', (SELECT count(*) FROM hits),
            'results', COALESCE((
                SELECT json_agg(json_build_object(
                    'cv_src', h.cv_src, 'cv_id', h.cv_id, 'name', h.name, 'number', h.number, 'city', h.city,
                    'salutation', h.salutation, 'telegram_chat_id', h.telegram_chat_id,
                    'whatsapp_phone', h.whatsapp_phone, 'whatsapp_window', h.whatsapp_window, 'whatsapp_known', h.whatsapp_known,
                    'email', h.email, 'address_ok', h.address_ok, 'address', h.address
                ) ORDER BY h.name, h.cv_id)
                -- Erreichbare zuerst (Telegram/WhatsApp-Fenster vor E-Mail vor Mobilnummer vor „kein Kanal“),
                -- damit die Obergrenze nicht mit unerreichbaren Kunden gefüllt wird
                FROM (SELECT * FROM hits
                      ORDER BY CASE WHEN telegram_chat_id IS NOT NULL OR (whatsapp_phone IS NOT NULL AND whatsapp_window) THEN 0
                                    WHEN email IS NOT NULL THEN 1
                                    WHEN whatsapp_phone IS NOT NULL THEN 2
                                    ELSE 3 END, name, cv_id
                      LIMIT (SELECT lim FROM p)) h
            ), '[]'::json)
        ) AS result
    SQL, [
        ':src' => strtoupper(substr((string)($data['src'] ?? ''), 0, 1)),
        ':business_id' => intval($data['business_id'] ?? 0),
        ':months' => intval($data['months'] ?? 0),
        ':city' => (string)($data['city'] ?? ''),
        ':channel' => $channel,
        ':limit' => max(1, intval($data['limit'] ?? MAILING_MAX_RECIPIENTS)),
        ':max_recipients' => MAILING_MAX_RECIPIENTS,
    ]);
    resultInfo(true, '', json_decode($row['result'], true));
}

/**
 * Verteiler mit Mitgliedern (inklusive Kanal-Verfügbarkeit) laden.
 *
 * @param int $data['id']
 * @testdata {"id": 1}
 */
function getMailingList($data) {
    mailingPermit();
    $db = DbhCompany::begin();
    $info = mailingInfoCtes();
    $row = $db->getOne(<<<SQL
        WITH p AS (SELECT :id::int AS id),
        l AS (SELECT ml.* FROM mailing_lists ml, p WHERE ml.id = p.id),
        src AS (SELECT mm.cv_src, mm.cv_id FROM mailing_list_members mm, p WHERE mm.mailing_list_id = p.id),
        {$info}
        SELECT json_build_object(
            'found', EXISTS (SELECT 1 FROM l),
            'list', (SELECT json_build_object('id', l.id, 'name', l.name, 'description', l.description) FROM l),
            'members', COALESCE((
                SELECT json_agg(json_build_object(
                    'cv_src', i.cv_src, 'cv_id', i.cv_id, 'name', i.name, 'number', i.number, 'city', i.city,
                    'obsolete', i.obsolete, 'salutation', i.salutation, 'telegram_chat_id', i.telegram_chat_id,
                    'whatsapp_phone', i.whatsapp_phone, 'whatsapp_window', i.whatsapp_window, 'whatsapp_known', i.whatsapp_known,
                    'email', i.email, 'address_ok', i.address_ok, 'address', i.address
                ) ORDER BY i.name, i.cv_id)
                FROM info i
            ), '[]'::json)
        ) AS result
    SQL, [':id' => intval($data['id'] ?? 0)]);
    resultInfo(true, '', json_decode($row['result'], true));
}

/**
 * Verteiler anlegen oder ändern; die Mitgliederliste wird vollständig ersetzt.
 *
 * @param int    $data['id']          0 = neu
 * @param string $data['name']
 * @param string $data['description']
 * @param array  $data['members']     [{cv_src, cv_id}] (höchstens 300)
 * @testdata {"id": 0, "name": "Weihnachtsfeier", "description": "", "members": [{"cv_src": "C", "cv_id": 1}]}
 */
function saveMailingList($data) {
    mailingPermit();
    $db = DbhCompany::begin();
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        resultInfo(false, 'MAILING_LIST_NAME_REQUIRED', 'Bitte einen Namen für den Verteiler angeben');
        return;
    }
    $clean = [];
    foreach ((is_array($data['members'] ?? null) ? $data['members'] : []) as $r) {
        $src = strtoupper(substr((string)($r['cv_src'] ?? ''), 0, 1));
        $cid = intval($r['cv_id'] ?? 0);
        if (($src === 'C' || $src === 'V') && $cid > 0) $clean[$src.$cid] = ['cv_src' => $src, 'cv_id' => $cid];
    }
    if (count($clean) > MAILING_MAX_RECIPIENTS) {
        resultInfo(false, 'MAILING_TOO_MANY_RECIPIENTS', 'Höchstens '.MAILING_MAX_RECIPIENTS.' Mitglieder je Verteiler');
        return;
    }
    $row = $db->getOne(<<<SQL
        WITH up AS (
            UPDATE mailing_lists SET name = :name, description = :description, mtime = now()
            WHERE id = NULLIF(:id::int, 0) RETURNING id
        ),
        ins AS (
            INSERT INTO mailing_lists (name, description, employee_id)
            SELECT :name, :description, :employee_id WHERE NOT EXISTS (SELECT 1 FROM up)
            RETURNING id
        ),
        lid AS (SELECT id FROM up UNION ALL SELECT id FROM ins),
        want AS (
            SELECT DISTINCT (e->>'cv_src')::char(1) AS cv_src, (e->>'cv_id')::int AS cv_id
            FROM jsonb_array_elements(:members::jsonb) e
        ),
        del AS (
            DELETE FROM mailing_list_members mm
            WHERE mm.mailing_list_id = (SELECT id FROM lid)
              AND NOT EXISTS (SELECT 1 FROM want w WHERE w.cv_src = mm.cv_src AND w.cv_id = mm.cv_id)
            RETURNING 1
        ),
        add AS (
            INSERT INTO mailing_list_members (mailing_list_id, cv_src, cv_id)
            SELECT (SELECT id FROM lid), w.cv_src, w.cv_id FROM want w
            ON CONFLICT (mailing_list_id, cv_src, cv_id) DO NOTHING
            RETURNING 1
        )
        SELECT (SELECT id FROM lid) AS id, (SELECT count(*) FROM want) AS members
    SQL, [
        ':id' => intval($data['id'] ?? 0), ':name' => $name,
        ':description' => trim((string)($data['description'] ?? '')),
        ':employee_id' => mitarbeiterId($data),
        ':members' => json_encode(array_values($clean)),
    ]);
    if (!$row || !$row['id']) {
        resultInfo(false, 'MAILING_LIST_NOT_FOUND', 'Verteiler nicht gefunden');
        return;
    }
    resultInfo(true, '', ['id' => intval($row['id']), 'members' => intval($row['members'])]);
}

/**
 * Verteiler löschen. Rundschreiben, die daraus entstanden sind, bleiben erhalten.
 *
 * @param int $data['id']
 * @testdata {"id": 1}
 */
function deleteMailingList($data) {
    mailingPermit();
    $db = DbhCompany::begin();
    $row = $db->getOne("DELETE FROM mailing_lists WHERE id = :id RETURNING id", [':id' => intval($data['id'] ?? 0)]);
    if (!$row) {
        resultInfo(false, 'MAILING_LIST_NOT_FOUND', 'Verteiler nicht gefunden');
        return;
    }
    $db->execute("UPDATE mailings SET mailing_list_id = NULL WHERE mailing_list_id = :id", [':id' => intval($row['id'])]);
    resultInfo(true, '', ['id' => intval($row['id'])]);
}

/**
 * Kanal und Ziel je wartendem Empfänger festlegen: der erste verfügbare Kanal
 * in der Reihenfolge des Rundschreibens. WhatsApp zählt nur mit offenem
 * 24-Stunden-Fenster (kostenlos) oder — wenn eine Vorlage gewählt ist — als
 * Vorlagennachricht. Gleiche Ziele bekommen die Nachricht nur einmal; wer
 * keinen Kanal hat, wird übersprungen (error = no_channel).
 *
 * @return array{planned:int, no_channel:int, duplicate:int}
 */
function mailingResolveChannels($db, int $mailingId): array {
    $info = mailingInfoCtes();
    $cfg = mailingConfigJson();
    // Zuvor übersprungene (ohne Kanal / Dublette) noch einmal prüfen — die Lage kann sich geändert haben
    $db->execute("UPDATE mailing_recipients SET status = 'pending', error = NULL, channel = NULL, target = NULL,
                         email_target = NULL, email_status = NULL, email_error = NULL,
                         letter_target = NULL, letter_status = NULL, letter_error = NULL
                  WHERE mailing_id = :id AND status = 'skipped'", [':id' => $mailingId]);
    $row = $db->getOne(<<<SQL
        WITH m AS (SELECT * FROM mailings WHERE id = :id),
        f AS (SELECT {$cfg} AS c),
        src AS (SELECT r.cv_src, r.cv_id FROM mailing_recipients r WHERE r.mailing_id = :id AND r.status = 'pending'),
        {$info},
        choice AS (
            -- Messenger: der erste verfügbare in der Reihenfolge von m.channels
            SELECT i.cv_src, i.cv_id, ch.channel, ch.target,
                   CASE WHEN (f.c->>'email')::boolean AND i.email IS NOT NULL AND m.email_mode <> 'off' THEN i.email END AS email_ok,
                   CASE WHEN (f.c->>'letter')::boolean AND i.address_ok AND m.letter_mode <> 'off' THEN i.address END AS letter_ok
            FROM info i CROSS JOIN m CROSS JOIN f
            LEFT JOIN LATERAL (
                SELECT c.channel, c.target
                FROM (
                    SELECT ord.n,
                        CASE ord.ch
                            WHEN 'telegram' THEN CASE WHEN (f.c->>'telegram')::boolean AND i.telegram_chat_id IS NOT NULL THEN 'telegram' END
                            WHEN 'whatsapp' THEN CASE
                                WHEN (f.c->>'whatsapp')::boolean AND i.whatsapp_phone IS NOT NULL AND i.whatsapp_window THEN 'whatsapp'
                                WHEN (f.c->>'whatsapp')::boolean AND i.whatsapp_phone IS NOT NULL AND m.whatsapp_template_id IS NOT NULL THEN 'whatsapp_template'
                            END
                        END AS channel,
                        CASE ord.ch WHEN 'telegram' THEN i.telegram_chat_id ELSE i.whatsapp_phone END AS target
                    FROM jsonb_array_elements_text(m.channels) WITH ORDINALITY AS ord(ch, n)
                ) c
                WHERE c.channel IS NOT NULL
                ORDER BY c.n
                LIMIT 1
            ) ch ON true
        ),
        plan0 AS (
            -- Hauptkanal: Messenger, sonst E-Mail, sonst Brief (jeweils zusätzlich oder als Rückfall);
            -- Zusatz-E-Mail nur neben einem Messenger bei „always“, Zusatz-Brief neben jedem anderen Hauptkanal bei „always“
            SELECT c.cv_src, c.cv_id,
                   COALESCE(c.channel, CASE WHEN c.email_ok IS NOT NULL THEN 'email' WHEN c.letter_ok IS NOT NULL THEN 'letter' END) AS channel,
                   COALESCE(c.target, c.email_ok, c.letter_ok) AS target,
                   CASE WHEN c.channel IS NOT NULL AND m.email_mode = 'always' THEN c.email_ok END AS email_extra,
                   CASE WHEN (c.channel IS NOT NULL OR c.email_ok IS NOT NULL) AND m.letter_mode = 'always' THEN c.letter_ok END AS letter_extra
            FROM choice c CROSS JOIN m
        ),
        plan1 AS (
            SELECT p.*,
                   CASE WHEN p.channel IS NULL THEN 'no_channel'
                        WHEN row_number() OVER (PARTITION BY replace(p.channel, '_template', ''), lower(p.target) ORDER BY p.cv_src, p.cv_id) > 1 THEN 'duplicate_target'
                   END AS skip_reason
            FROM plan0 p
        ),
        plan AS (
            -- Jede Adresse bekommt die Zusatz-E-Mail nur einmal und nicht, wenn sie schon Hauptkanal eines anderen Empfängers ist
            SELECT p.*,
                   CASE WHEN p.skip_reason IS NULL AND p.email_extra IS NOT NULL
                         AND row_number() OVER (PARTITION BY lower(p.email_extra), (p.skip_reason IS NULL) ORDER BY p.cv_src, p.cv_id) = 1
                         AND NOT EXISTS (SELECT 1 FROM plan1 q WHERE q.skip_reason IS NULL AND q.channel = 'email' AND lower(q.target) = lower(p.email_extra))
                        THEN p.email_extra END AS email_target,
                   CASE WHEN p.skip_reason IS NULL AND p.letter_extra IS NOT NULL
                         AND row_number() OVER (PARTITION BY lower(p.letter_extra), (p.skip_reason IS NULL) ORDER BY p.cv_src, p.cv_id) = 1
                         AND NOT EXISTS (SELECT 1 FROM plan1 q WHERE q.skip_reason IS NULL AND q.channel = 'letter' AND lower(q.target) = lower(p.letter_extra))
                        THEN p.letter_extra END AS letter_target
            FROM plan1 p
        ),
        upd AS (
            UPDATE mailing_recipients r
            SET channel = p.channel, target = p.target,
                status = CASE WHEN p.skip_reason IS NULL THEN 'pending' ELSE 'skipped' END,
                error = p.skip_reason,
                email_target = p.email_target,
                email_status = CASE WHEN p.email_target IS NOT NULL THEN 'pending' END,
                email_error = NULL,
                letter_target = p.letter_target,
                letter_status = CASE WHEN p.letter_target IS NOT NULL THEN 'pending' END,
                letter_error = NULL
            FROM plan p
            WHERE r.mailing_id = :id AND r.status = 'pending' AND r.cv_src = p.cv_src AND r.cv_id = p.cv_id
            RETURNING p.skip_reason, p.email_target, p.letter_target
        )
        SELECT count(*) FILTER (WHERE skip_reason IS NULL) AS planned,
               count(*) FILTER (WHERE skip_reason = 'no_channel') AS no_channel,
               count(*) FILTER (WHERE skip_reason = 'duplicate_target') AS duplicate,
               count(*) FILTER (WHERE email_target IS NOT NULL) AS emails,
               count(*) FILTER (WHERE letter_target IS NOT NULL) AS letters
        FROM upd
    SQL, [':id' => $mailingId, ':max_recipients' => MAILING_MAX_RECIPIENTS]);
    // Empfänger ohne Stammsatz (inzwischen gelöscht) tauchen in info nicht auf
    $db->execute("UPDATE mailing_recipients SET status = 'skipped', error = 'not_found'
                  WHERE mailing_id = :id AND status = 'pending' AND channel IS NULL", [':id' => $mailingId]);
    return ['planned' => intval($row['planned'] ?? 0), 'no_channel' => intval($row['no_channel'] ?? 0),
            'duplicate' => intval($row['duplicate'] ?? 0), 'emails' => intval($row['emails'] ?? 0), 'letters' => intval($row['letters'] ?? 0)];
}

/**
 * Versand starten oder fortsetzen: Kanäle festlegen, Status auf „läuft"
 * setzen und die erste Etappe sofort anstoßen.
 *
 * @param int $data['id']
 * @testdata {"id": 1}
 */
function startMailing($data) {
    mailingPermit();
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    $m = $db->getOne("SELECT id, status, body_text FROM mailings WHERE id = :id", [':id' => $id]);
    if (!$m) {
        resultInfo(false, 'MAILING_NOT_FOUND', 'Rundschreiben nicht gefunden');
        return;
    }
    if (!in_array($m['status'], ['draft', 'paused'], true)) {
        resultInfo(false, 'MAILING_WRONG_STATUS', 'Nur Entwürfe und pausierte Rundschreiben können gestartet werden');
        return;
    }
    if (trim((string)$m['body_text']) === '') {
        resultInfo(false, 'MAILING_TEXT_REQUIRED', 'Die Nachricht ist leer');
        return;
    }
    $plan = mailingResolveChannels($db, $id);
    if ($plan['planned'] === 0) {
        resultInfo(false, 'MAILING_NO_RECIPIENTS', 'Kein Empfänger ist über die gewählten Kanäle erreichbar', $plan);
        return;
    }
    $db->execute("UPDATE mailings SET status = 'running', started_at = COALESCE(started_at, now()), finished_at = NULL,
                         next_batch_at = now(), last_error = NULL, mtime = now() WHERE id = :id", [':id' => $id]);

    // Erste Etappe gleich losschicken (sofern im Zeitfenster)
    $result = mailingProcessDue($db, $id);
    resultInfo(true, '', ['plan' => $plan, 'batch' => $result]);
}

/**
 * Versand pausieren, fortsetzen oder abbrechen.
 *
 * @param int    $data['id']
 * @param string $data['status'] paused | cancelled | draft (abgebrochenes zurück in den Entwurf)
 * @testdata {"id": 1, "status": "paused"}
 */
function setMailingStatus($data) {
    mailingPermit();
    $db = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);
    $status = (string)($data['status'] ?? '');
    $allowed = [
        'paused'    => ['running'],
        'cancelled' => ['running', 'paused'],
        'draft'     => ['cancelled', 'done', 'paused'],
    ];
    if (!isset($allowed[$status])) {
        resultInfo(false, 'MAILING_INVALID_STATUS', 'Ungültiger Status');
        return;
    }
    $from = "'".implode("','", $allowed[$status])."'";
    $row = $db->getOne(<<<SQL
        UPDATE mailings SET status = :status,
               finished_at = CASE WHEN :status = 'cancelled' THEN now() WHEN :status = 'draft' THEN NULL ELSE finished_at END,
               started_at = CASE WHEN :status = 'draft' THEN NULL ELSE started_at END,
               next_batch_at = CASE WHEN :status = 'paused' OR :status = 'cancelled' THEN NULL ELSE next_batch_at END,
               mtime = now()
        WHERE id = :id AND status IN ({$from})
        RETURNING id, status
    SQL, [':id' => $id, ':status' => $status]);
    if (!$row) {
        resultInfo(false, 'MAILING_WRONG_STATUS', 'Statuswechsel in dieser Lage nicht möglich');
        return;
    }
    if ($status === 'draft') {
        // Zurück in den Entwurf: noch nicht versendete Empfänger wieder auf „wartend"
        $db->execute("UPDATE mailing_recipients SET status = 'pending', channel = NULL, target = NULL, error = NULL, batch_no = NULL,
                             email_target = NULL, email_status = NULL, email_error = NULL, email_reference = NULL, email_sent_at = NULL,
                             letter_target = NULL, letter_status = NULL, letter_error = NULL, letter_reference = NULL, letter_sent_at = NULL
                      WHERE mailing_id = :id AND status <> 'sent'", [':id' => $id]);
    }
    resultInfo(true, '', ['id' => $id, 'status' => $row['status']]);
}

/**
 * Fällige Etappen verarbeiten — für alle laufenden Rundschreiben oder eines.
 * Wird vom Hintergrunddienst (backend/cli/mailings.php) und von der offenen
 * Rundschreiben-Seite aufgerufen.
 *
 * @param int $data['id'] Nur dieses Rundschreiben (0 = alle fälligen)
 * @testdata {"id": 0}
 */
function processMailings($data) {
    mailingPermit();
    $db = DbhCompany::begin();
    resultInfo(true, '', mailingProcessDue($db, intval($data['id'] ?? 0) ?: null));
}

/**
 * Fällige Etappen senden. Beansprucht die Rundschreiben atomar (next_batch_at
 * wird vor dem Senden auf die nächste Etappe gesetzt), damit Hintergrunddienst
 * und offene Seite sich nicht in die Quere kommen.
 *
 * @return array{mailings:int, sent:int, failed:int, emails:int, letters:int}
 */
function mailingProcessDue($db, ?int $onlyId = null): array {
    $tz = date_default_timezone_get() ?: 'Europe/Berlin';
    $claimed = $db->getAll(<<<SQL
        UPDATE mailings m
        SET next_batch_at = now() + (m.batch_pause_minutes || ' minutes')::interval, mtime = now()
        WHERE m.status = 'running'
          AND COALESCE(m.next_batch_at, now()) <= now()
          AND (now() AT TIME ZONE :tz)::time BETWEEN m.send_from AND m.send_until
          AND (:only::int IS NULL OR m.id = :only::int)
          AND EXISTS (SELECT 1 FROM mailing_recipients r WHERE r.mailing_id = m.id AND r.status = 'pending' AND r.channel IS NOT NULL)
        RETURNING m.*, (SELECT name FROM employee e WHERE e.id = m.employee_id) AS employee_name,
                  (SELECT company FROM defaults LIMIT 1) AS company,
                  (SELECT COALESCE(max(batch_no), 0) + 1 FROM mailing_recipients r WHERE r.mailing_id = m.id) AS batch_no
    SQL, [':tz' => $tz, ':only' => $onlyId]);

    $summary = ['mailings' => count($claimed), 'sent' => 0, 'failed' => 0, 'emails' => 0, 'letters' => 0];
    foreach ($claimed as $m) {
        $r = mailingSendBatch($db, $m);
        $summary['sent'] += $r['sent'];
        $summary['failed'] += $r['failed'];
        $summary['emails'] += $r['emails'];
        $summary['letters'] += $r['letters'];
    }

    // Fertig, wenn nichts mehr wartet (auch ohne beanspruchte Etappe, z. B. nach dem Abbruch der letzten)
    $db->execute(<<<SQL
        UPDATE mailings m SET status = 'done', finished_at = now(), next_batch_at = NULL, mtime = now()
        WHERE m.status = 'running' AND (:only::int IS NULL OR m.id = :only::int)
          AND NOT EXISTS (SELECT 1 FROM mailing_recipients r WHERE r.mailing_id = m.id AND r.status = 'pending' AND r.channel IS NOT NULL)
    SQL, [':only' => $onlyId]);
    return $summary;
}

/**
 * Eine Etappe eines Rundschreibens senden: die nächsten batch_size wartenden
 * Empfänger, mit zufälligem Abstand zwischen zwei Nachrichten. Nach fünf
 * Fehlern in Folge wird pausiert, damit ein kaputter Zugang nicht die ganze
 * Liste verbrennt.
 */
function mailingSendBatch($db, array $m): array {
    $info = mailingInfoCtes();
    $rows = $db->getAll(<<<SQL
        WITH src AS (
            SELECT r.cv_src, r.cv_id FROM mailing_recipients r
            WHERE r.mailing_id = :id AND r.status = 'pending' AND r.channel IS NOT NULL
            ORDER BY r.id LIMIT :lim
        ),
        {$info}
        SELECT r.id AS recipient_id, r.channel, r.target, r.email_target, r.email_status, r.letter_target, r.letter_status,
               i.cv_src, i.cv_id, i.name, i.number, i.salutation
        FROM mailing_recipients r
        JOIN info i ON i.cv_src = r.cv_src AND i.cv_id = r.cv_id
        WHERE r.mailing_id = :id AND r.status = 'pending' AND r.channel IS NOT NULL
        ORDER BY r.id
    SQL, [':id' => intval($m['id']), ':lim' => max(1, intval($m['batch_size']))]);

    $ctx = ['company' => (string)($m['company'] ?? ''), 'employee_name' => (string)($m['employee_name'] ?? '')];
    $sent = 0; $failed = 0; $emails = 0; $letters = 0; $streak = 0;
    $templateCache = null;
    $subjectOf = fn(string $subject) => $subject !== '' ? $subject : (string)$m['title'];

    foreach ($rows as $k => $r) {
        $text = mailingFill((string)$m['body_text'], $r, $ctx);
        $subject = mailingFill((string)($m['subject'] ?? ''), $r, $ctx);
        $html = trim((string)($m['body_html'] ?? '')) !== '' ? mailingFill((string)$m['body_html'], $r, $ctx) : '';

        try {
            switch ($r['channel']) {
                case 'telegram':
                    $res = mailingSendTelegram($db, (string)$r['target'], $text);
                    break;
                case 'whatsapp':
                    $res = mailingSendWhatsAppText((string)$r['target'], $text, $r['cv_src'] === 'C' ? intval($r['cv_id']) : 0);
                    break;
                case 'whatsapp_template':
                    if ($templateCache === null) {
                        $templateCache = $db->getOne("SELECT id, name, body_text FROM whatsapp_templates WHERE id = :id AND status = 'approved'",
                                                     [':id' => intval($m['whatsapp_template_id'])]) ?: [];
                    }
                    $res = mailingSendWhatsAppTemplate($templateCache, (string)$r['target'], (string)$r['name'], $text, $r['cv_src'] === 'C' ? intval($r['cv_id']) : 0, (string)($r['salutation'] ?? ''));
                    break;
                case 'email':
                    $res = mailingSendEmail((string)$r['target'], (string)$r['name'], $subjectOf($subject), $text, $html, intval($m['employee_id'] ?? 0) ?: null);
                    break;
                case 'letter':
                    $res = mailingSendLetter($db, $m, $r, $subjectOf($subject), $text, $html);
                    break;
                default:
                    $res = ['success' => false, 'error' => 'unknown_channel'];
            }
        } catch (\Throwable $e) {
            $res = ['success' => false, 'error' => $e->getMessage()];
        }

        $db->execute(<<<SQL
            UPDATE mailing_recipients SET status = :status, error = :error, reference = :reference, batch_no = :batch_no,
                   sent_at = CASE WHEN :status = 'sent' THEN now() ELSE NULL END
            WHERE id = :rid
        SQL, [
            ':status' => $res['success'] ? 'sent' : 'failed',
            ':error' => $res['success'] ? null : mb_substr((string)($res['error'] ?? 'Fehler'), 0, 500),
            ':reference' => $res['reference'] ?? null,
            ':batch_no' => intval($m['batch_no'] ?? 1),
            ':rid' => intval($r['recipient_id']),
        ]);

        if ($res['success']) { $sent++; $streak = 0; }
        else { $failed++; $streak++; }

        // Zusätzliche E-Mail neben dem Messenger (email_mode = always)
        if (!empty($r['email_target']) && ($r['email_status'] ?? '') === 'pending') {
            try {
                $er = mailingSendEmail((string)$r['email_target'], (string)$r['name'], $subjectOf($subject), $text, $html, intval($m['employee_id'] ?? 0) ?: null);
            } catch (\Throwable $e) {
                $er = ['success' => false, 'error' => $e->getMessage()];
            }
            $db->execute(<<<SQL
                UPDATE mailing_recipients SET email_status = :status, email_error = :error, email_reference = :reference,
                       email_sent_at = CASE WHEN :status = 'sent' THEN now() ELSE NULL END
                WHERE id = :rid
            SQL, [
                ':status' => $er['success'] ? 'sent' : 'failed',
                ':error' => $er['success'] ? null : mb_substr((string)($er['error'] ?? 'Fehler'), 0, 500),
                ':reference' => $er['reference'] ?? null,
                ':rid' => intval($r['recipient_id']),
            ]);
            if ($er['success']) $emails++;
        }

        // Zusätzlicher Brief über den Postdienst (letter_mode = always)
        if (!empty($r['letter_target']) && ($r['letter_status'] ?? '') === 'pending') {
            try {
                $lr = mailingSendLetter($db, $m, $r, $subjectOf($subject), $text, $html);
            } catch (\Throwable $e) {
                $lr = ['success' => false, 'error' => $e->getMessage()];
            }
            $db->execute(<<<SQL
                UPDATE mailing_recipients SET letter_status = :status, letter_error = :error, letter_reference = :reference,
                       letter_sent_at = CASE WHEN :status = 'sent' THEN now() ELSE NULL END
                WHERE id = :rid
            SQL, [
                ':status' => $lr['success'] ? 'sent' : 'failed',
                ':error' => $lr['success'] ? null : mb_substr((string)($lr['error'] ?? 'Fehler'), 0, 500),
                ':reference' => $lr['reference'] ?? null,
                ':rid' => intval($r['recipient_id']),
            ]);
            if ($lr['success']) $letters++;
        }

        // Zu viele Anfragen (Telegram 429): Etappe beenden und später weitermachen
        if (!empty($res['retry_after'])) {
            $db->execute("UPDATE mailings SET next_batch_at = now() + (:s || ' seconds')::interval WHERE id = :id",
                         [':s' => max(5, intval($res['retry_after'])), ':id' => intval($m['id'])]);
            break;
        }
        if ($streak >= 5) {
            $db->execute("UPDATE mailings SET status = 'paused', next_batch_at = NULL, last_error = :err, mtime = now() WHERE id = :id",
                         [':err' => mb_substr((string)($res['error'] ?? ''), 0, 500), ':id' => intval($m['id'])]);
            break;
        }
        // Menschlicher Rhythmus zwischen zwei Nachrichten
        if ($k < count($rows) - 1) usleep(random_int(1500, 4000) * 1000);
    }
    return ['sent' => $sent, 'failed' => $failed, 'emails' => $emails, 'letters' => $letters];
}

/**
 * Platzhalter füllen: {{anrede}}, {{name}}, {{kundennummer}}, {{firma}}, {{mitarbeiter}}.
 */
function mailingFill(string $text, array $r, array $ctx): string {
    return strtr($text, [
        '{{anrede}}'       => (string)($r['salutation'] ?? ''),
        '{{name}}'         => (string)($r['name'] ?? ''),
        '{{kundennummer}}' => (string)($r['number'] ?? ''),
        '{{firma}}'        => (string)($ctx['company'] ?? ''),
        '{{mitarbeiter}}'  => (string)($ctx['employee_name'] ?? ''),
    ]);
}

/**
 * Textnachricht über den Firmen-Bot an eine Telegram-Chat-ID.
 * Bei 429 liefert Telegram retry_after; ein gesperrter Bot („Forbidden: bot
 * was blocked by the user") ist ein dauerhafter Fehler für diesen Empfänger.
 */
function mailingSendTelegram($db, string $chatId, string $text): array {
    $token = trim((string)($db->getOne("SELECT value FROM defaults_oserp WHERE key = 'telegram_bot_token'")['value'] ?? ''));
    if ($token === '') return ['success' => false, 'error' => 'TELEGRAM_NOT_CONFIGURED'];
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => 'true']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['success' => false, 'error' => 'curl: '.$err];
    $json = json_decode((string)$resp, true);
    if (!empty($json['ok'])) {
        return ['success' => true, 'reference' => (string)($json['result']['message_id'] ?? '')];
    }
    $out = ['success' => false, 'error' => (string)($json['description'] ?? 'Telegram-Fehler')];
    if (intval($json['error_code'] ?? 0) === 429) $out['retry_after'] = intval($json['parameters']['retry_after'] ?? 30);
    return $out;
}

/**
 * WhatsApp-Freitext (nur im offenen 24-Stunden-Fenster kostenlos und zulässig).
 * Landet wie jede Nachricht in whatsapp_messages und damit in der Kontakthistorie.
 */
function mailingSendWhatsAppText(string $phone, string $text, int $customerId = 0): array {
    require_once __DIR__.'/../whatsapp/whatsapp.php';
    $config = _getWhatsAppConfig();
    $accessToken = $config['whatsapp_access_token'] ?? '';
    $phoneNumberId = $config['whatsapp_phone_number_id'] ?? '';
    if ($accessToken === '' || $phoneNumberId === '') return ['success' => false, 'error' => 'WHATSAPP_NOT_CONFIGURED'];

    $ch = curl_init("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'messaging_product' => 'whatsapp', 'to' => ltrim($phone, '+'),
            'type' => 'text', 'text' => ['body' => $text, 'preview_url' => false],
        ]),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$accessToken, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['success' => false, 'error' => 'curl: '.$err];
    $json = json_decode((string)$resp, true);
    if ($code !== 200 || empty($json['messages'][0]['id'])) {
        $msg = (string)($json['error']['message'] ?? 'Unbekannter Fehler');
        if (!empty($json['error']['error_data']['details'])) $msg .= ' — '.$json['error']['error_data']['details'];
        return ['success' => false, 'error' => "HTTP {$code}: {$msg}"];
    }
    $waId = (string)$json['messages'][0]['id'];
    DbhCompany::begin()->execute(
        "INSERT INTO whatsapp_messages (wa_message_id, direction, phone_number, customer_id, message_type, message_text, status, itime)
         VALUES (:wa_id, 'O', :phone, :cid, 'text', :text, 'sent', NOW())",
        [':wa_id' => $waId, ':phone' => $phone, ':cid' => $customerId > 0 ? $customerId : null, ':text' => $text]
    );
    return ['success' => true, 'reference' => $waId];
}

/**
 * WhatsApp-Vorlagennachricht (kostenpflichtig) für Empfänger ohne offenes
 * Fenster. Konvention wie im Chat: {{1}} = Name, {{2}} = Text; bei nur einem
 * Platzhalter steht dort der Text. Zeilenumbrüche werden von Meta nicht
 * erlaubt und zu Leerzeichen.
 */
function mailingSendWhatsAppTemplate(array $template, string $phone, string $name, string $text, int $customerId = 0, string $salutation = ''): array {
    require_once __DIR__.'/../whatsapp/whatsapp.php';
    if (!$template) return ['success' => false, 'error' => 'TEMPLATE_NOT_FOUND'];
    // Die Vorlage grüßt selbst („Hallo {{1}}“) – eine führende Anrede aus {{anrede}} würde doppelt stehen
    if ($salutation !== '') $text = preg_replace('/^\s*'.preg_quote($salutation, '/').'\s*[,!.]?\s*/u', '', $text, 1);
    preg_match_all('/\{\{(\d+)\}\}/', (string)($template['body_text'] ?? ''), $mm);
    $count = !empty($mm[1]) ? max(array_map('intval', $mm[1])) : 1;
    $params = [];
    for ($i = 0; $i < $count; $i++) $params[] = ($i === 0 && $count > 1) ? $name : $text;
    $res = _sendTemplateMessageInternal($phone, $template, $params, $customerId);
    return ['success' => !empty($res['success']), 'error' => $res['error'] ?? null, 'reference' => $res['wa_message_id'] ?? null];
}

/**
 * E-Brief über den Postdienst (PIN AG / LetterXpress): legt einen kivitendo-
 * Brief für den Kunden an (erscheint in dessen Briefliste) und liefert ihn über
 * das Postmodul ein. Die Anrede wandert ins Anrede-Feld des Briefs, damit sie
 * nicht doppelt steht.
 */
function mailingSendLetter($db, array $m, array $r, string $subject, string $text, string $html): array {
    require_once __DIR__.'/../letters/letters.php';
    require_once __DIR__.'/../letters/postal.php';
    $salutation = trim((string)($r['salutation'] ?? ''));
    $body = $text;
    if ($salutation !== '') $body = preg_replace('/^\s*'.preg_quote($salutation, '/').'\s*[,!.]?\s*/u', '', $body, 1);
    $bodyHtml = $html !== '' ? $html : '<p>'.nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')).'</p>';
    $letter = $db->getOne(<<<SQL
        WITH n AS (UPDATE defaults SET letternumber = COALESCE(letternumber, 0) + 1 RETURNING letternumber)
        INSERT INTO letter (customer_id, vendor_id, date, reference, subject, greeting, body, intnotes, employee_id, letternumber)
        SELECT :customer_id, :vendor_id, CURRENT_DATE, :reference, :subject, :greeting, :body, :intnotes, :employee_id, (SELECT letternumber::text FROM n)
        RETURNING id
    SQL, [
        ':customer_id' => $r['cv_src'] === 'C' ? intval($r['cv_id']) : null,
        ':vendor_id'   => $r['cv_src'] === 'V' ? intval($r['cv_id']) : null,
        ':reference'   => 'Rundschreiben: '.(string)$m['title'],
        ':subject'     => $subject,
        ':greeting'    => $salutation,
        ':body'        => $bodyHtml,
        ':intnotes'    => 'Automatisch aus Rundschreiben #'.intval($m['id']),
        ':employee_id' => intval($m['employee_id'] ?? 0) ?: null,
    ]);
    $cfg = postalConfig($db);
    $dispatch = postalDispatch($db, 'letter', intval($letter['id']), ['color' => $cfg['color'], 'duplex' => $cfg['duplex']], intval($m['employee_id'] ?? 0) ?: null);
    return ['success' => true, 'reference' => (string)$dispatch['id']];
}

/**
 * E-Mail über den Firmen-SMTP; Eintrag im E-Mail-Journal. Ohne HTML wird der
 * Text mit Zeilenumbrüchen als HTML aufbereitet.
 */
function mailingSendEmail(string $to, string $name, string $subject, string $text, string $html, ?int $employeeId): array {
    $config = _getEmailConfig();
    $smtp = _createSmtpClient($config);
    $from = $config['email_address'] ?? $config['email_username'] ?? '';
    $fromName = trim((string)(DbhCompany::begin()->getOne("SELECT company FROM defaults LIMIT 1")['company'] ?? ''));
    if ($html === '') $html = '<p>'.nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')).'</p>';
    $toList = [['email' => $to, 'name' => $name]];
    $smtp->send($from, $fromName, $toList, $subject, $html, $text, [], [], []);
    $jid = 0;
    try {
        $jid = _logToEmailJournal($from, $toList, [], $subject, $text, [], null, null, null, $employeeId);
    } catch (\Throwable $e) {
        error_log('[MAILING] Journal-Eintrag fehlgeschlagen: '.$e->getMessage());
    }
    return ['success' => true, 'reference' => $jid ? (string)$jid : null];
}

/**
 * Testnachricht an eine eigene Adresse oder Nummer — mit Beispielwerten für
 * die Platzhalter. WhatsApp nur im offenen Fenster oder mit Vorlage.
 *
 * @param string $data['channel']   email | whatsapp
 * @param string $data['target']    E-Mail-Adresse oder Rufnummer
 * @param string $data['subject']
 * @param string $data['body_text']
 * @param string $data['body_html']
 * @param int    $data['whatsapp_template_id']
 * @testdata {"channel": "email", "target": "test@example.com", "subject": "Test", "body_text": "{{anrede}}, Test."}
 */
function sendMailingTest($data) {
    mailingPermit();
    $db = DbhCompany::begin();
    $channel = (string)($data['channel'] ?? '');
    $target = trim((string)($data['target'] ?? ''));
    $emp = $db->getOne("SELECT name FROM employee WHERE id = :id", [':id' => mitarbeiterId($data) ?? 0]);
    $company = (string)($db->getOne("SELECT company FROM defaults LIMIT 1")['company'] ?? '');
    $sample = ['salutation' => 'Sehr geehrte Frau Mustermann', 'name' => 'Erika Mustermann', 'number' => '10001'];
    $ctx = ['company' => $company, 'employee_name' => (string)($emp['name'] ?? '')];
    $text = mailingFill((string)($data['body_text'] ?? ''), $sample, $ctx);
    $subject = mailingFill((string)($data['subject'] ?? 'Test'), $sample, $ctx);
    $html = trim((string)($data['body_html'] ?? '')) !== '' ? mailingFill((string)$data['body_html'], $sample, $ctx) : '';
    if ($target === '' || trim($text) === '') {
        resultInfo(false, 'MAILING_TEST_INPUT', 'Ziel und Text sind erforderlich');
        return;
    }
    if ($channel === 'email') {
        $res = mailingSendEmail($target, $sample['name'], '[Test] '.$subject, $text, $html, mitarbeiterId($data));
    } elseif ($channel === 'whatsapp') {
        require_once __DIR__.'/../whatsapp/whatsapp.php';
        $config = _getWhatsAppConfig();
        $phone = _normalizePhone($target, $config['whatsapp_country_code'] ?? '49');
        $win = $db->getOne("SELECT 1 AS x FROM whatsapp_messages WHERE phone_number = :p AND direction = 'I' AND itime > now() - interval '24 hours' LIMIT 1", [':p' => $phone]);
        if ($win) {
            $res = mailingSendWhatsAppText($phone, $text, 0);
        } elseif (intval($data['whatsapp_template_id'] ?? 0) > 0) {
            $tpl = $db->getOne("SELECT id, name, body_text FROM whatsapp_templates WHERE id = :id AND status = 'approved'", [':id' => intval($data['whatsapp_template_id'])]) ?: [];
            $res = mailingSendWhatsAppTemplate($tpl, $phone, $sample['name'], $text, 0, $sample['salutation']);
        } else {
            $res = ['success' => false, 'error' => 'WHATSAPP_WINDOW_CLOSED'];
        }
    } else {
        resultInfo(false, 'MAILING_TEST_CHANNEL', 'Testversand nur per E-Mail oder WhatsApp');
        return;
    }
    if (empty($res['success'])) {
        resultInfo(false, 'MAILING_TEST_FAILED', (string)($res['error'] ?? 'Fehler'));
        return;
    }
    resultInfo(true, '', ['reference' => $res['reference'] ?? null]);
}
