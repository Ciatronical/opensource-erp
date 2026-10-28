<?php
// backend/api/faktura/gutachten_review.php

/**
 * Weroni Rechtsprüfung (LxCars)
 *
 * Nach dem Gutachten-Import prüft Weroni als digitale Rechtsassistentin der
 * Werkstatt das komplette Gutachten — auch die Teile, die nicht als Positionen
 * in den Auftrag wandern: Wiederbeschaffungswert, Restwert, 130-%-Regel,
 * Wertminderung, Nutzungsausfall, Sachverständigenkosten, Abrechnungsart,
 * Haftpflicht/Kasko, Werkstattrisiko und Abtretung. Sie trifft eine
 * Entscheidung (Reparatur freigeben / 130-%-Fall / wirtschaftlicher
 * Totalschaden / Rückfrage), begründet sie, stellt die Ansprüche des Kunden
 * zusammen und formuliert die Kommunikation an Kunde und Versicherung.
 *
 * Jeder Lauf wird in gutachten_pruefung_lxcars gespeichert; die Entscheidung
 * landet zusätzlich in den internen Notizen des Auftrags. Versand (E-Mail,
 * WhatsApp) läuft über die vorhandenen Kanäle, das Protokoll führt
 * markGutachtenReviewCommunicated.
 *
 * Hinweis Rechtsdienstleistungsgesetz: Die Ausgabe ist eine fachliche
 * Einschätzung für die Werkstatt und allgemeine Information an den Kunden,
 * keine Rechtsberatung — Weroni sagt das im Kundentext selbst und empfiehlt
 * bei Bedarf einen Anwalt (dessen Kosten im Haftpflichtfall der Gegner trägt).
 */

/**
 * Weroni prüft ein ausgelesenes Gutachten und trifft eine Entscheidung
 *
 * @param int    $data['oe_id']       Auftrags-ID
 * @param array  $data['extracted']   Ergebnis von analyzeGutachtenPdf (vehicle, appraisal, items, items_net …)
 * @param int    $data['c_id']        Fahrzeug (nach Übernahme), optional
 * @param string $data['stored_path'] Ablagepfad der Datei, optional
 * @param string $data['ai_model']    Optional: Modellwahl des Benutzers, nur für diesen Aufruf
 * @testdata {"oe_id": 1, "extracted": {"vehicle": {"license_plate": "B-GU 1234", "manufacturer": "VW", "model": "Golf VIII", "first_registration": "2021-03-05", "mileage": 45120}, "appraisal": {"number": "G-1", "date": "2026-10-01", "appraiser": "SV Muster", "insurer": "Muster Versicherung", "claim_number": "SN-4711", "repair_costs_net": 3475.08, "repair_costs_gross": 4135.35, "legal": {"wiederbeschaffungswert_brutto": 24500, "restwert": 17900, "wertminderung": 250, "nutzungsausfall_tage": 5, "nutzungsausfall_gruppe": "F", "sachverstaendigenkosten": 612, "schadenart": "haftpflicht"}}, "items": [], "items_net": 3475.08}}
 */
function reviewGutachten($data) {
    set_time_limit(240);

    $oeId      = intval($data['oe_id'] ?? 0);
    $extracted = is_array($data['extracted'] ?? null) ? $data['extracted'] : [];
    if ($oeId <= 0) {
        throw new ApiError('VALIDATION_ERROR', 'oe_id erforderlich');
    }
    if (empty($extracted['appraisal']) && empty($extracted['vehicle'])) {
        throw new ApiError('VALIDATION_ERROR', 'extracted (Ergebnis der Gutachten-Analyse) erforderlich');
    }

    $db = DbhCompany::begin();
    permit(getPermissionForFakturaType('order'));

    // Eine Abfrage: Schlüssel + Modell, Werkstatt, Auftrag, Kunde, Fahrzeug
    $row = $db->getOne(<<<SQL
        SELECT (SELECT json_object_agg(key, value) FROM defaults_oserp
                 WHERE key IN ('anthropic_api_key', 'weroni_system_prompt', :model_key)) AS config,
               (SELECT json_build_object(
                           'werkstatt',     d.company,
                           'ordnumber',     o.ordnumber,
                           'transdate',     to_char(o.transdate, 'DD.MM.YYYY'),
                           'customer_id',   o.customer_id,
                           'customer_name', cu.name,
                           'customer_greeting', cu.greeting,
                           'customer_ustid', cu.ustid,
                           'order_net',     o.netamount,
                           'c_id',          e.c_id,
                           'c_ln',          c.c_ln,
                           'c_fin',         c.c_fin,
                           'c_mt',          c.c_mt,
                           'c_d',           to_char(c.c_d, 'DD.MM.YYYY'),
                           'c_km',          c.c_km,
                           'km_stand',      e.km_stand)
                  FROM oe o
                  CROSS JOIN defaults d
                  LEFT JOIN customer cu ON cu.id = o.customer_id
                  LEFT JOIN oe_ext e ON e.oe_id = o.id
                  LEFT JOIN cars_lxcars c ON c.c_id = e.c_id
                 WHERE o.id = :oe_id) AS context
SQL, [':model_key' => aiModelConfigKey('gutachten_review'), ':oe_id' => $oeId]);

    $config  = json_decode($row['config'] ?? '{}', true) ?: [];
    $context = json_decode($row['context'] ?? 'null', true);
    if (!$context) {
        throw new ApiError('DATA_NOT_FOUND', 'Auftrag nicht gefunden');
    }
    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if ($anthropicKey === '') {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key ist nicht konfiguriert (Firmeneinstellungen → KI)');
    }
    $aiModel = resolveAiModel($config, 'gutachten_review', $data['ai_model'] ?? null);

    $review = _gr_askWeroni($anthropicKey, $aiModel, $extracted, $context);
    $review = _gr_normalize($review);

    $employeeId = null;
    try { $employeeId = mitarbeiterId($data); } catch (\Throwable $e) { /* CLI/Test ohne Mitarbeiter */ }

    // Speichern + Entscheidung in die internen Notizen — eine Abfrage;
    // die beim Import bereitgelegte, noch ungeprüfte Analyse wird dabei ersetzt
    $saved = $db->getOne(<<<SQL
        WITH del AS (
            DELETE FROM gutachten_pruefung_lxcars WHERE oe_id = :oe_id3 AND review IS NULL
        ), ins AS (
            INSERT INTO gutachten_pruefung_lxcars (oe_id, c_id, stored_path, extracted, review, verdict, ampel, ai_model, employee_id)
            VALUES (:oe_id, :c_id, :stored_path, CAST(:extracted AS jsonb), CAST(:review AS jsonb), :verdict, :ampel, :ai_model, :employee_id)
            RETURNING id, to_char(created_at, 'DD.MM.YYYY HH24:MI') AS created_at
        ), upd AS (
            UPDATE oe SET intnotes = CASE WHEN COALESCE(intnotes, '') = '' THEN CAST(:note AS text)
                                          ELSE intnotes || E'\n\n' || CAST(:note AS text) END
            WHERE id = :oe_id2
        )
        SELECT id, created_at FROM ins
SQL, [
        ':oe_id'       => $oeId,
        ':c_id'        => intval($data['c_id'] ?? 0) ?: ($context['c_id'] ?? null),
        ':stored_path' => trim((string)($data['stored_path'] ?? '')) ?: null,
        ':extracted'   => json_encode($extracted, JSON_UNESCAPED_UNICODE),
        ':review'      => json_encode($review, JSON_UNESCAPED_UNICODE),
        ':verdict'     => $review['verdict'],
        ':ampel'       => $review['ampel'],
        ':ai_model'    => $aiModel,
        ':employee_id' => $employeeId,
        ':note'        => 'Weroni Rechtsprüfung (' . date('d.m.Y') . '): ' . $review['headline']
                          . ' [' . strtoupper($review['ampel']) . "]\n" . $review['decision'],
        ':oe_id2'      => $oeId,
        ':oe_id3'      => $oeId,
    ]);

    resultInfo(true, 'OK', [
        'id'           => intval($saved['id']),
        'created_at'   => $saved['created_at'],
        'ai_model'     => $aiModel,
        'review'       => $review,
        'communicated' => [],
        'customer'     => [
            'id'    => $context['customer_id'] ? intval($context['customer_id']) : null,
            'name'  => $context['customer_name'],
        ],
    ]);
}

/**
 * Neueste Weroni-Prüfung eines Auftrags
 *
 * @param int $data['oe_id'] Auftrags-ID
 * @testdata {"oe_id": 1}
 */
function getGutachtenReview($data) {
    $oeId = intval($data['oe_id'] ?? 0);
    if ($oeId <= 0) {
        throw new ApiError('VALIDATION_ERROR', 'oe_id erforderlich');
    }
    $db = DbhCompany::begin();
    permit(getPermissionForFakturaType('order'));

    $row = $db->getOne(<<<SQL
        SELECT p.id, to_char(p.created_at, 'DD.MM.YYYY HH24:MI') AS created_at, p.ai_model,
               p.review, p.communicated, p.stored_path, p.extracted, p.c_id,
               json_build_object('id', o.customer_id, 'name', cu.name) AS customer
        FROM gutachten_pruefung_lxcars p
        JOIN oe o ON o.id = p.oe_id
        LEFT JOIN customer cu ON cu.id = o.customer_id
        WHERE p.oe_id = :oe_id
        ORDER BY p.id DESC
        LIMIT 1
SQL, [':oe_id' => $oeId]);

    if (!$row) {
        resultInfo(true, 'NONE', ['id' => null]);
        return;
    }
    resultInfo(true, 'OK', [
        'id'           => intval($row['id']),
        'created_at'   => $row['created_at'],
        'ai_model'     => $row['ai_model'],
        'review'       => json_decode($row['review'] ?? 'null', true),
        'communicated' => json_decode($row['communicated'] ?? '[]', true) ?: [],
        'stored_path'  => $row['stored_path'],
        'c_id'         => $row['c_id'] ? intval($row['c_id']) : null,
        'extracted'    => json_decode($row['extracted'] ?? 'null', true),
        'customer'     => json_decode($row['customer'] ?? 'null', true),
    ]);
}

/**
 * Protokolliert, dass die Entscheidung über einen Kanal kommuniziert wurde
 *
 * @param int    $data['id']      Prüfung
 * @param string $data['channel'] email_customer | whatsapp_customer | email_insurer | copied
 * @param string $data['to']      Empfänger (Adresse/Nummer), optional
 * @testdata {"id": 1, "channel": "email_customer", "to": "kunde@example.de"}
 */
function markGutachtenReviewCommunicated($data) {
    $id      = intval($data['id'] ?? 0);
    $channel = trim((string)($data['channel'] ?? ''));
    if ($id <= 0 || !in_array($channel, ['email_customer', 'whatsapp_customer', 'email_insurer', 'copied'], true)) {
        throw new ApiError('VALIDATION_ERROR', 'id und gültiger channel erforderlich');
    }
    $db = DbhCompany::begin();
    permit(getPermissionForFakturaType('order'));

    $row = $db->getOne(<<<SQL
        UPDATE gutachten_pruefung_lxcars
           SET communicated = communicated || jsonb_build_array(jsonb_build_object(
                   'channel', CAST(:channel AS text),
                   'to',      CAST(:to AS text),
                   'at',      to_char(now(), 'DD.MM.YYYY HH24:MI')))
         WHERE id = :id
        RETURNING communicated
SQL, [':channel' => $channel, ':to' => trim((string)($data['to'] ?? '')), ':id' => $id]);
    if (!$row) {
        throw new ApiError('DATA_NOT_FOUND', 'Prüfung nicht gefunden');
    }
    resultInfo(true, 'OK', ['communicated' => json_decode($row['communicated'], true) ?: []]);
}

// =========================================================================
// Hilfsfunktionen (keine API-Endpoints)
// =========================================================================

/**
 * Fragt Weroni (Claude) nach der Rechtsprüfung. Der System-Prompt mit den
 * Regeln ist bei jedem Gutachten gleich und wird zwischengespeichert.
 */
function _gr_askWeroni($apiKey, $model, array $extracted, array $context) {
    $systemPrompt = <<<'PROMPT'
Du bist Weroni, die digitale Rechtsassistentin einer Kfz-Werkstatt. Du bekommst die ausgelesenen Daten eines Schadengutachtens (Fahrzeug, Gutachtendaten, Reparaturkalkulation, weitere Werte) und den Werkstattauftrag. Deine Aufgabe: das Gutachten vollständig prüfen, eine Entscheidung treffen, sie begründen, die Ansprüche des Kunden zusammenstellen und die Kommunikation an Kunde und Versicherung formulieren. Du arbeitest für die Werkstatt, denkst aber im Interesse des Kunden (Geschädigter), damit die Reparatur bezahlt wird und der Kunde bekommt, was ihm zusteht.

RECHTLICHER RAHMEN (deutsches Kfz-Schadenrecht, Haftpflichtschaden, § 249 ff. BGB — Stand deines Wissens, Gerichte können abweichen):
1. Vergleichsbasis: Reparaturkosten INKLUSIVE merkantiler Wertminderung gegen den Wiederbeschaffungswert (WBW). Brutto mit brutto vergleichen; bei vorsteuerabzugsberechtigtem Geschädigten (Unternehmer, USt-IdNr.) netto mit netto.
2. Reparaturkosten ≤ WBW: Reparatur ist wirtschaftlich, Ersatz der Reparaturkosten. Konkrete Abrechnung (Rechnung) inkl. MwSt.; fiktive Abrechnung nur netto (§ 249 Abs. 2 S. 2 BGB). Liegen die Reparaturkosten über dem Wiederbeschaffungsaufwand (WBW − Restwert), setzt die fiktive Abrechnung der vollen Reparaturkosten eine Weiternutzung von mindestens 6 Monaten voraus (BGH VI ZR 192/05).
3. Reparaturkosten > WBW bis 130 % des WBW (Integritätsinteresse): Ersatz nur bei vollständiger und fachgerechter Reparatur gemäß Gutachten und Weiternutzung von mindestens 6 Monaten (BGH VI ZR 70/04, VI ZR 212/07). Teilreparatur oder fiktiv: nur Wiederbeschaffungsaufwand. Bei Reparatur in diesem Bereich keine zusätzliche Wertminderung, wenn sie bereits in die 130 % eingerechnet ist.
4. Reparaturkosten > 130 % WBW: wirtschaftlicher Totalschaden. Ersatz = WBW − Restwert (Wiederbeschaffungsaufwand) zuzüglich Nebenkosten (Ab-/Anmeldung, Nutzungsausfall für die Wiederbeschaffungsdauer, meist 10–14 Tage). Keine Wertminderung. Restwert: Geschädigter darf zum im Gutachten ausgewiesenen Restwert (regionaler Markt, drei Angebote) verkaufen, wenn er nicht vorher ein höheres Angebot des Versicherers erhalten hat (BGH VI ZR 232/04).
5. Merkantile Wertminderung: nur bei Reparatur (nicht bei Totalschaden), Höhe laut Gutachten; bei älteren Fahrzeugen mit hoher Laufleistung oft null — plausibel prüfen.
6. Nutzungsausfall: für die Reparaturdauer laut Gutachten (bei Totalschaden Wiederbeschaffungsdauer) zuzüglich Prüf- und Überlegungszeit; Tagessatz nach Tabelle (Sanden/Danner/Küppersbusch) gemäß Fahrzeuggruppe im Gutachten; alternativ Mietwagen (nicht beides). Voraussetzung: Nutzungswille und -möglichkeit.
7. Sachverständigenkosten: erstattungsfähig (BGH VI ZR 67/06), ausser bei Bagatellschäden unter etwa 750–1.000 €. Unkostenpauschale 20–30 €. Abschleppkosten, Standgeld, Ab-/Anmeldung bei Totalschaden, Anwaltskosten bei klarer Haftung ebenfalls erstattungsfähig.
8. UPE-Aufschläge und Verbringungskosten: bei konkreter Reparatur erstattungsfähig, wenn sie anfallen; bei fiktiver Abrechnung regional strittig.
9. Verweisung auf eine günstigere freie Referenzwerkstatt (BGH VI ZR 53/09 „Porsche", VI ZR 337/09 „VW"): nur bei fiktiver Abrechnung, Fahrzeug älter als 3 Jahre und nicht scheckheftgepflegt, Werkstatt mühelos erreichbar — und nicht mehr, wenn die Reparatur durchgeführt ist.
10. Werkstattrisiko (BGH VI ZR 51/23, VI ZR 38/22 u. a., 2024): Überhöhte oder unnötige Werkstattkosten gehen zu Lasten des Schädigers, nicht des Kunden, auch bei unbezahlter Rechnung (Freistellung). Tritt der Kunde den Anspruch an die Werkstatt ab, kann die Werkstatt sich darauf NICHT berufen — Empfehlung für die Werkstatt: Zahlung durch den Kunden, kein Vorgehen allein aus Abtretung bei strittigen Positionen.
11. Haftungsquote/Mitverschulden: Alle Ansprüche nur in Höhe der Quote. Kaskoschaden: eigene Versicherung, Selbstbeteiligung, keine Wertminderung/Nutzungsausfall/Gutachterkosten, ggf. Werkstattbindung — die Kaskobedingungen prüfen.
12. Rechtsdienstleistungsgesetz: Die Werkstatt erbringt keine Rechtsberatung. Der Text an den Kunden informiert allgemein und empfiehlt bei Totalschaden, 130-%-Fällen, Kürzungen, unklarer Haftung oder Personenschaden einen Anwalt (dessen Kosten im Haftpflichtfall der gegnerische Versicherer trägt). Die Werkstatt darf ihre eigene Reparaturrechnung gegenüber der Versicherung geltend machen.

PRÜFUNG — gehe diese Punkte durch und bewerte jeden mit ok / warn / fail / info:
- Vollständigkeit und Plausibilität des Gutachtens (Fahrzeugdaten, FIN, km, Erstzulassung, Vorschäden, Lichtbilder, Reparaturweg)
- Wirtschaftlichkeit: Reparaturkosten + Wertminderung vs. WBW, 130-%-Grenze, Wiederbeschaffungsaufwand
- Abrechnungsart (konkret/fiktiv) und MwSt./Vorsteuerabzug
- Stundenverrechnungssätze und Lacksatz (marktüblich? Verweisungsrisiko?)
- Wertminderung (angemessen? ausgewiesen?), Nutzungsausfall (Dauer, Gruppe, Tagessatz), Mietwagen
- Sachverständigenkosten, Unkostenpauschale, weitere Nebenkosten
- Restwert und Restwertangebote
- Haftung (Haftpflicht/Kasko, Quote) und Versicherungsdaten vollständig
- Risiken für die Werkstatt (Abtretung, Werkstattrisiko, Kürzungen, Prüfbericht, Bezahlung)
- Fehlende Angaben, die den Kunden oder Gutachter zu fragen sind

ENTSCHEIDUNG (verdict):
- "reparatur": Reparaturkosten + Wertminderung ≤ WBW → Reparatur freigeben.
- "reparatur_130": über WBW, aber ≤ 130 % → Reparatur nur vollständig nach Gutachten, Weiternutzung 6 Monate, Kunde aufklären.
- "totalschaden": > 130 % → wirtschaftlicher Totalschaden, Abrechnung auf Totalschadenbasis, Reparatur nur auf eigene Rechnung des Kunden.
- "rueckfrage": WBW fehlt oder Daten widersprüchlich → vor Reparaturfreigabe klären.
ampel: "gruen" (Freigabe ohne Vorbehalt), "gelb" (Freigabe mit Bedingungen/Hinweisen), "rot" (keine Freigabe / Totalschaden / Rückfrage).

Antworte AUSSCHLIESSLICH mit einem validen JSON-Objekt (kein Markdown, kein Text drumherum):
{
  "verdict": "reparatur | reparatur_130 | totalschaden | rueckfrage",
  "ampel": "gruen | gelb | rot",
  "headline": "Entscheidung in einem Satz",
  "decision": "Begründete Entscheidung in 3–6 Sätzen: Zahlen nennen (Reparaturkosten, WBW, Grenze), was die Werkstatt jetzt tut, was der Kunde wissen muss.",
  "checks": [
    {"topic": "Wirtschaftlichkeit", "status": "ok | warn | fail | info", "finding": "Befund in 1–2 Sätzen mit Zahlen", "basis": "Rechtsgrundlage/Urteil, kurz oder leer"}
  ],
  "claims": [
    {"title": "Reparaturkosten (brutto)", "amount": 4135.35, "basis": "§ 249 Abs. 2 BGB", "note": "Hinweis, z.B. nur bei konkreter Abrechnung"}
  ],
  "claims_total": 5000.00,
  "risks": ["Risiko für die Werkstatt in einem Satz"],
  "next_steps": ["Konkreter nächster Schritt für die Werkstatt"],
  "questions": ["Offene Frage an Kunde oder Gutachter"],
  "lawyer_recommended": true,
  "lawyer_reason": "Warum ein Anwalt sinnvoll ist, oder leer",
  "customer_message": "Nachricht an den Kunden (Anrede mit Namen, höflich per Sie, 8–14 Sätze, verständlich, keine Paragraphenflut): Ergebnis der Prüfung, was die Werkstatt tut, welche Ansprüche er zusätzlich zur Reparatur gegenüber der gegnerischen Versicherung hat (mit Beträgen), was er dafür tun muss, Hinweis auf Anwalt wenn empfohlen, Hinweis dass dies allgemeine Informationen und keine Rechtsberatung sind. Grußformel mit dem Werkstattnamen.",
  "insurer_letter": {
    "subject": "Betreff, z.B. Schadennummer, Kennzeichen, Reparaturfreigabe / Anmeldung der Reparaturkosten",
    "body": "Förmliches Schreiben der Werkstatt an die Versicherung (Sie-Form, sachlich, mit Schadennummer, Kennzeichen, Gutachten-Nr., Reparaturkosten lt. Gutachten, Bitte um Reparaturfreigabe bzw. Kostenübernahmeerklärung und Zahlung an die Werkstatt, Frist 10 Werktage, Hinweis auf Nutzungsausfall während der Reparatur). Ansprüche, die dem Kunden persönlich zustehen (Wertminderung, Nutzungsausfall, Gutachterkosten), nur erwähnen, nicht im Namen der Werkstatt fordern."
  }
}

REGELN:
- Beträge als Zahlen mit 2 Nachkommastellen, Datumsangaben als DD.MM.YYYY im Text.
- Fehlt der WBW, dann verdict "rueckfrage", ampel "rot", und der erste next_step ist, den WBW beim Gutachter zu erfragen — die Positionen können trotzdem vorbereitet werden.
- Erfinde keine Zahlen. Was nicht im Gutachten steht, als fehlend benennen.
- Nutzungsausfall-Betrag nur berechnen, wenn Tage und Tagessatz (oder Gruppe mit bekanntem Satz) vorliegen; sonst Tage und Gruppe nennen und den Betrag als Schätzung kennzeichnen.
- claims enthält alle Ansprüche des Kunden gegenüber der gegnerischen Haftpflicht (Reparaturkosten, Wertminderung, Nutzungsausfall, Sachverständigenkosten, Unkostenpauschale, ggf. weitere); bei Kasko nur, was die Kasko zahlt.
- Sprache: Deutsch.
PROMPT;

    $v = $extracted['vehicle'] ?? [];
    $a = $extracted['appraisal'] ?? [];
    $l = $a['legal'] ?? [];
    $items = $extracted['items'] ?? [];
    $sums = ['part' => 0, 'labour' => 0, 'lacquer' => 0, 'additional' => 0];
    foreach ($items as $it) {
        $cat = $it['category'] ?? 'additional';
        if (!isset($sums[$cat])) $cat = 'additional';
        $sums[$cat] += floatval($it['qty'] ?? 0) * floatval($it['sellprice'] ?? 0);
    }

    $payload = [
        'heute'      => date('d.m.Y'),
        'werkstatt'  => $context['werkstatt'] ?? '',
        'auftrag'    => [
            'nummer'         => $context['ordnumber'] ?? '',
            'datum'          => $context['transdate'] ?? '',
            'kunde'          => $context['customer_name'] ?? '',
            'kunde_anrede'   => $context['customer_greeting'] ?? '',
            'kunde_ustid'    => $context['customer_ustid'] ?? '',
            'kunde_vorsteuerabzug_vermutet' => !empty($context['customer_ustid']),
            'fahrzeug_im_auftrag' => trim(($context['c_ln'] ?? '') . ' ' . ($context['c_mt'] ?? '')),
            'km_stand_auftrag'    => $context['km_stand'] ?? null,
        ],
        'fahrzeug'   => $v,
        'gutachten'  => $a,
        'kalkulation' => [
            'positionen_anzahl' => count($items),
            'summe_ersatzteile_netto' => round($sums['part'], 2),
            'summe_lohn_netto'        => round($sums['labour'], 2),
            'summe_lack_netto'        => round($sums['lacquer'], 2),
            'summe_nebenkosten_netto' => round($sums['additional'], 2),
            'summe_positionen_netto'  => round(array_sum($sums), 2),
            'reparaturkosten_netto_lt_gutachten'  => $a['repair_costs_net'] ?? null,
            'reparaturkosten_brutto_lt_gutachten' => $a['repair_costs_gross'] ?? null,
        ],
        'weitere_werte' => $l,
        'ki_hinweise_extraktion' => $extracted['notes'] ?? '',
    ];
    $userText = "GUTACHTEN UND AUFTRAG (JSON):\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        . "\n\nPrüfe das Gutachten vollständig, triff die Entscheidung und formuliere die Kommunikation.";

    $requestBody = json_encode([
        'model'         => $model,
        'max_tokens'    => 12000,
        'output_config' => ['effort' => 'medium'],
        'system'        => [[
            'type'          => 'text',
            'text'          => $systemPrompt,
            'cache_control' => ['type' => 'ephemeral'],
        ]],
        'messages'      => [['role' => 'user', 'content' => $userText]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 220,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new ApiError('CLAUDE_API_ERROR', 'cURL-Fehler: ' . $curlError);
    }
    if ($httpCode !== 200) {
        $apiError = json_decode((string)$response, true)['error']['message'] ?? '';
        throw new ApiError('CLAUDE_API_ERROR',
            'Weroni konnte nicht prüfen (HTTP ' . $httpCode . ')' . ($apiError !== '' ? ': ' . $apiError : ''));
    }

    $responseData = json_decode($response, true);
    $aiText = '';
    foreach ($responseData['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') { $aiText = $block['text'] ?? ''; break; }
    }
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/', $aiText, $m)) {
        $aiText = $m[1];
    }
    $review = json_decode(trim($aiText), true);
    if (!is_array($review) || !isset($review['verdict'])) {
        $start = strpos($aiText, '{');
        $end   = strrpos($aiText, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $review = json_decode(substr($aiText, $start, $end - $start + 1), true);
        }
    }
    if (!is_array($review) || !isset($review['verdict'])) {
        throw new ApiError('EXTRACTION_ERROR', 'Weroni hat keine auswertbare Prüfung geliefert'
            . (($responseData['stop_reason'] ?? '') === 'max_tokens' ? ' (Antwort abgeschnitten)' : ''));
    }
    return $review;
}

/**
 * Bringt die Antwort in eine feste Form: gültige Werte, saubere Zahlen,
 * Listen statt null, damit Dialog und Notizen nichts prüfen müssen.
 */
function _gr_normalize(array $r): array {
    $str = fn($v, $max = 4000) => is_scalar($v) && trim((string)$v) !== '' ? mb_substr(trim((string)$v), 0, $max) : '';
    $num = fn($v) => is_numeric($v) ? round(floatval($v), 2) : null;
    $list = function ($v, $max = 20) use ($str) {
        $out = [];
        foreach ((array)$v as $e) { $s = $str($e, 500); if ($s !== '') $out[] = $s; if (count($out) >= $max) break; }
        return $out;
    };

    $verdict = strtolower($str($r['verdict'] ?? '', 30));
    if (!in_array($verdict, ['reparatur', 'reparatur_130', 'totalschaden', 'rueckfrage'], true)) $verdict = 'rueckfrage';
    $ampel = strtolower($str($r['ampel'] ?? '', 10));
    if (!in_array($ampel, ['gruen', 'gelb', 'rot'], true)) {
        $ampel = $verdict === 'reparatur' ? 'gruen' : ($verdict === 'reparatur_130' ? 'gelb' : 'rot');
    }

    $checks = [];
    foreach ((array)($r['checks'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $topic = $str($c['topic'] ?? '', 80);
        if ($topic === '') continue;
        $status = strtolower($str($c['status'] ?? 'info', 10));
        if (!in_array($status, ['ok', 'warn', 'fail', 'info'], true)) $status = 'info';
        $checks[] = ['topic' => $topic, 'status' => $status, 'finding' => $str($c['finding'] ?? '', 600), 'basis' => $str($c['basis'] ?? '', 120)];
    }
    $claims = [];
    $total = 0.0;
    foreach ((array)($r['claims'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $title = $str($c['title'] ?? '', 120);
        if ($title === '') continue;
        $amount = $num($c['amount'] ?? null);
        if ($amount !== null) $total += $amount;
        $claims[] = ['title' => $title, 'amount' => $amount, 'basis' => $str($c['basis'] ?? '', 120), 'note' => $str($c['note'] ?? '', 300)];
    }
    $claimsTotal = $num($r['claims_total'] ?? null);
    if ($claimsTotal === null || abs($claimsTotal - $total) > 0.05) $claimsTotal = round($total, 2);

    $letter = is_array($r['insurer_letter'] ?? null) ? $r['insurer_letter'] : [];

    return [
        'verdict'            => $verdict,
        'ampel'              => $ampel,
        'headline'           => $str($r['headline'] ?? '', 200) ?: 'Prüfung abgeschlossen',
        'decision'           => $str($r['decision'] ?? '', 3000),
        'checks'             => $checks,
        'claims'             => $claims,
        'claims_total'       => $claimsTotal,
        'risks'              => $list($r['risks'] ?? []),
        'next_steps'         => $list($r['next_steps'] ?? []),
        'questions'          => $list($r['questions'] ?? []),
        'lawyer_recommended' => !empty($r['lawyer_recommended']),
        'lawyer_reason'      => $str($r['lawyer_reason'] ?? '', 500),
        'customer_message'   => $str($r['customer_message'] ?? '', 6000),
        'insurer_letter'     => [
            'subject' => $str($letter['subject'] ?? '', 200),
            'body'    => $str($letter['body'] ?? '', 8000),
        ],
    ];
}
