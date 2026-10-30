<?php
// backend/api/faktura/gutachten_import.php

/**
 * Gutachten-Import (LxCars)
 *
 * Fremde Kfz-Schadengutachten und Kostenvoranschläge — als PDF oder Foto, egal
 * von welchem Sachverständigen, welcher Versicherung oder welchem Kalkulations-
 * system — werden per KI ausgelesen und in einen Werkstattauftrag übernommen:
 * Fahrzeug (Kennzeichen, FIN, Modell, Erstzulassung), km-Stand, Gutachten-
 * und Schadennummer sowie alle Kalkulationspositionen (Teile, Lohn, Lack).
 *
 * Gegenstück zum SilverDAT-VXS-Import für Dateien ohne Schnittstelle.
 * Ablauf: analyzeGutachtenPdf (Vorschau) → applyGutachtenToOrder (Übernahme).
 */

/** Grösse, bis zu der die Anthropic-API ein Dokument annimmt */
const GUTACHTEN_MAX_BYTES = 30 * 1024 * 1024;

/**
 * Liest ein Gutachten per KI aus und liefert die Vorschau (Fahrzeug,
 * Gutachtendaten, Positionen). Es wird noch nichts gespeichert.
 *
 * @param int    $data['oe_id']       Auftrags-ID (für Fahrzeug-Kontext)
 * @param string $data['file_base64'] Dateiinhalt Base64 (PDF oder Bild)
 * @param string $data['filename']    Originalname
 * @param string $data['mime_type']   application/pdf, image/jpeg, image/png, image/webp
 * @param string $data['ai_model']    Optional: Modellwahl des Benutzers, nur für diesen Aufruf
 * @testdata {"oe_id": 1, "filename": "gutachten.pdf", "mime_type": "application/pdf", "file_base64": ""}
 */
function analyzeGutachtenPdf($data) {
    set_time_limit(240);

    $oeId       = intval($data['oe_id'] ?? 0);
    $fileBase64 = $data['file_base64'] ?? '';
    $filename   = trim($data['filename'] ?? 'gutachten.pdf');
    $mimeType   = strtolower(trim($data['mime_type'] ?? 'application/pdf'));

    if ($oeId <= 0) {
        throw new ApiError('VALIDATION_ERROR', 'oe_id erforderlich');
    }
    if ($fileBase64 === '') {
        throw new ApiError('VALIDATION_ERROR', 'file_base64 erforderlich');
    }
    $fileContent = base64_decode($fileBase64, true);
    if ($fileContent === false) {
        throw new ApiError('VALIDATION_ERROR', 'Ungültige Base64-Daten');
    }
    if (strlen($fileContent) > GUTACHTEN_MAX_BYTES) {
        throw new ApiError('FILE_TOO_LARGE', 'Datei zu gross (max. 30 MB)');
    }
    $isPdf   = str_starts_with($mimeType, 'application/pdf');
    $isImage = in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    if (!$isPdf && !$isImage) {
        throw new ApiError('UNSUPPORTED_FILE', 'Nur PDF oder Bilddateien (JPEG, PNG, WebP) werden unterstützt');
    }

    $db = DbhCompany::begin();
    permit(getPermissionForFakturaType('order'));

    // Eine Abfrage: API-Schlüssel + Modellwahl und der Fahrzeug-Kontext des Auftrags
    $row = $db->getOne(<<<SQL
        SELECT (SELECT json_object_agg(key, value) FROM defaults_oserp
                 WHERE key IN ('anthropic_api_key', 'ai_external_enabled', :model_key)) AS config,
               (SELECT json_build_object(
                           'customer_id', o.customer_id,
                           'c_id',        e.c_id,
                           'c_ln',        c.c_ln,
                           'c_fin',       c.c_fin,
                           'c_mt',        c.c_mt,
                           'km_stand',    e.km_stand)
                  FROM oe o
                  LEFT JOIN oe_ext e ON e.oe_id = o.id
                  LEFT JOIN cars_lxcars c ON c.c_id = e.c_id
                 WHERE o.id = :oe_id) AS context
SQL, [':model_key' => aiModelConfigKey('gutachten_import'), ':oe_id' => $oeId]);

    $config  = json_decode($row['config'] ?? '{}', true) ?: [];
    $context = json_decode($row['context'] ?? 'null', true);
    if (!$context) {
        throw new ApiError('DATA_NOT_FOUND', 'Auftrag nicht gefunden');
    }

    $anthropicKey = trim($config['anthropic_api_key'] ?? '');
    if ($anthropicKey === '') {
        throw new ApiError('MISSING_API_KEYS', 'Anthropic API-Key ist nicht konfiguriert (Firmeneinstellungen → KI)');
    }
    $aiModel = resolveAiModel($config, 'gutachten_import', $data['ai_model'] ?? null);

    $extracted = _gi_askClaude($anthropicKey, $aiModel, $fileBase64, $isPdf ? 'application/pdf' : $mimeType, $context);
    $result    = _gi_normalize($extracted);

    $result['context']  = [
        'c_id'     => $context['c_id'] ? intval($context['c_id']) : null,
        'c_ln'     => $context['c_ln'] ?? null,
        'c_fin'    => $context['c_fin'] ?? null,
        'km_stand' => $context['km_stand'] !== null ? intval($context['km_stand']) : null,
    ];
    $result['filename'] = $filename;
    $result['ai_model'] = $aiModel;

    resultInfo(true, 'OK', $result);
}

/**
 * Übernimmt ein ausgelesenes Gutachten in den Auftrag: Fahrzeug anlegen bzw.
 * ergänzen und verknüpfen, km-Stand, Gutachtendaten in die internen Notizen,
 * Positionen anlegen, PDF im Fahrzeugordner ablegen. Alles in einer Transaktion.
 *
 * Gehört das Kennzeichen bereits einem anderen Kunden, wird ohne take_over
 * nichts gespeichert und EXISTS_OTHER_OWNER gemeldet — das Frontend fragt nach.
 *
 * @param int    $data['oe_id']        Auftrags-ID
 * @param array  $data['items']        Positionen {description, partnumber, qty, sellprice, unit, part_type, longdescription}
 * @param int    $data['buchungsgruppeTeile']  Buchungsgruppe Ersatzteile
 * @param int    $data['buchungsgruppeArbeit'] Buchungsgruppe Arbeit/Lack
 * @param array  $data['vehicle']      {license_plate, vin, manufacturer, model, first_registration, color, mileage}
 * @param array  $data['appraisal']    {number, date, appraiser, insurer, claim_number, damage_date, damage_description, repair_costs_net}
 * @param array  $data['options']      {apply_vehicle, apply_km, apply_notes, store_pdf, take_over}
 * @param string $data['file_base64']  Datei für die Ablage (nur bei store_pdf)
 * @param string $data['filename']     Dateiname für die Ablage
 * @param array  $data['extracted']    Optional: komplettes Analyse-Ergebnis, wird für die Weroni-Prüfung gespeichert
 * @testdata {"oe_id": 1, "buchungsgruppeTeile": 1, "buchungsgruppeArbeit": 1, "items": [{"description": "Stoßfänger vorn", "partnumber": "", "qty": 1, "sellprice": 250, "unit": "Stck", "part_type": "part", "longdescription": ""}], "vehicle": {"license_plate": "B-GU 1234", "vin": "", "manufacturer": "VW", "model": "Golf VIII", "first_registration": "2021-03-05", "color": "", "mileage": 45120}, "appraisal": {"number": "G-2026-001", "date": "2026-10-01", "appraiser": "SV Muster", "insurer": "Muster Versicherung", "claim_number": "SN-4711", "damage_date": "2026-09-28", "damage_description": "Frontschaden", "repair_costs_net": 250}, "options": {"apply_vehicle": true, "apply_km": true, "apply_notes": true, "store_pdf": false}}
 */
function applyGutachtenToOrder($data) {
    $oeId      = intval($data['oe_id'] ?? 0);
    $items     = is_array($data['items'] ?? null) ? $data['items'] : [];
    $bgTeile   = intval($data['buchungsgruppeTeile'] ?? 0);
    $bgArbeit  = intval($data['buchungsgruppeArbeit'] ?? 0);
    $vehicle   = is_array($data['vehicle'] ?? null) ? $data['vehicle'] : [];
    $appraisal = is_array($data['appraisal'] ?? null) ? $data['appraisal'] : [];
    $options   = is_array($data['options'] ?? null) ? $data['options'] : [];

    if ($oeId <= 0) {
        throw new ApiError('VALIDATION_ERROR', 'oe_id erforderlich');
    }
    if (!empty($items) && ($bgTeile <= 0 || $bgArbeit <= 0)) {
        throw new ApiError('MISSING_BUCHUNGSGRUPPE', 'Buchungsgruppen müssen angegeben werden');
    }

    $applyVehicle = !empty($options['apply_vehicle']);
    $applyKm      = !empty($options['apply_km']);
    $applyNotes   = !empty($options['apply_notes']);
    $storePdf     = !empty($options['store_pdf']);
    $takeOver     = !empty($options['take_over']);

    $plate   = _gi_normalizePlate($vehicle['license_plate'] ?? '');
    $vin     = _gi_normalizeVin($vehicle['vin'] ?? '');
    $model   = trim(implode(' ', array_filter([trim($vehicle['manufacturer'] ?? ''), trim($vehicle['model'] ?? '')])));
    $model   = $model !== '' ? mb_substr($model, 0, 30) : null;
    $color   = trim($vehicle['color'] ?? '');
    $color   = $color !== '' ? mb_substr($color, 0, 30) : null;
    $firstReg = _gi_normalizeDate($vehicle['first_registration'] ?? '');
    $mileage = intval($vehicle['mileage'] ?? 0);
    $mileage = $mileage > 0 ? $mileage : null;

    $db = DbhCompany::begin();
    permit(getPermissionForFakturaType('order'));

    $order = $db->getOne(
        "SELECT o.customer_id, e.c_id, (SELECT name FROM customer WHERE id = o.customer_id) AS customer_name
           FROM oe o LEFT JOIN oe_ext e ON e.oe_id = o.id WHERE o.id = :oe_id",
        [':oe_id' => $oeId]
    );
    if (!$order) {
        throw new ApiError('DATA_NOT_FOUND', 'Auftrag nicht gefunden');
    }
    $customerId = intval($order['customer_id']);
    $cId        = $order['c_id'] ? intval($order['c_id']) : 0;

    $carCreated = false;
    $carLinked  = false;
    $takenOver  = false;
    $oldOwnerId = 0;
    $newCar     = null;

    $db->beginTransaction();
    try {
        // ── Fahrzeug ──
        if ($applyVehicle) {
            if ($cId > 0) {
                // Verknüpftes Fahrzeug: nur leere Felder ergänzen, FIN nur wenn noch frei —
                // und nur, wenn das Kennzeichen im Gutachten zum Fahrzeug passt (sonst
                // landen Modell/FIN eines fremden Fahrzeugs im falschen Datensatz)
                $db->execute(<<<SQL
                    UPDATE cars_lxcars SET
                        c_fin   = COALESCE(NULLIF(c_fin, ''), CASE WHEN CAST(:vin AS text) IS NULL OR EXISTS (
                                      SELECT 1 FROM cars_lxcars x WHERE x.c_fin = CAST(:vin AS text) AND x.c_id <> cars_lxcars.c_id)
                                  THEN NULL ELSE CAST(:vin AS text) END),
                        c_mt    = COALESCE(NULLIF(c_mt, ''), CAST(:c_mt AS text)),
                        c_color = COALESCE(NULLIF(c_color, ''), CAST(:c_color AS text)),
                        c_d     = COALESCE(c_d, CAST(:c_d AS date)),
                        c_km    = GREATEST(COALESCE(c_km, 0), COALESCE(CAST(:c_km AS integer), 0))
                    WHERE c_id = :c_id
                      AND (CAST(:plate AS text) = '' OR UPPER(c_ln) = CAST(:plate AS text))
SQL, [':vin' => $vin, ':c_mt' => $model, ':c_color' => $color, ':c_d' => $firstReg, ':c_km' => $mileage,
      ':c_id' => $cId, ':plate' => $plate]);
            } elseif ($plate !== '' && $customerId > 0) {
                $existing = $db->getOne(
                    "SELECT c.c_id, c.c_ow, COALESCE(cu.name, '') AS owner_name
                       FROM cars_lxcars c LEFT JOIN customer cu ON cu.id = c.c_ow
                      WHERE UPPER(c.c_ln) = CAST(:c_ln AS text) OR (CAST(:vin AS text) IS NOT NULL AND c.c_fin = CAST(:vin AS text))
                      ORDER BY (UPPER(c.c_ln) = CAST(:c_ln AS text)) DESC LIMIT 1",
                    [':c_ln' => $plate, ':vin' => $vin]
                );
                if ($existing && intval($existing['c_ow']) !== $customerId && !$takeOver) {
                    $db->rollBack();
                    resultInfo(true, 'EXISTS_OTHER_OWNER', [
                        'exists_other_owner' => true,
                        'owner_name'         => $existing['owner_name'],
                        'plate'              => $plate,
                    ]);
                    return;
                }
                if ($existing) {
                    $cId = intval($existing['c_id']);
                    if (intval($existing['c_ow']) !== $customerId) {
                        $oldOwnerId = intval($existing['c_ow']);
                        $takenOver  = true;
                        $db->execute("UPDATE cars_lxcars SET c_ow = CAST(:c_ow AS integer) WHERE c_id = :c_id",
                            [':c_ow' => $customerId, ':c_id' => $cId]);
                    }
                    $db->execute(<<<SQL
                        UPDATE cars_lxcars SET
                            c_fin   = COALESCE(NULLIF(c_fin, ''), CASE WHEN CAST(:vin AS text) IS NULL OR EXISTS (
                                          SELECT 1 FROM cars_lxcars x WHERE x.c_fin = CAST(:vin AS text) AND x.c_id <> cars_lxcars.c_id)
                                      THEN NULL ELSE CAST(:vin AS text) END),
                            c_mt    = COALESCE(NULLIF(c_mt, ''), CAST(:c_mt AS text)),
                            c_color = COALESCE(NULLIF(c_color, ''), CAST(:c_color AS text)),
                            c_d     = COALESCE(c_d, CAST(:c_d AS date)),
                            c_km    = GREATEST(COALESCE(c_km, 0), COALESCE(CAST(:c_km AS integer), 0))
                        WHERE c_id = :c_id
SQL, [':vin' => $vin, ':c_mt' => $model, ':c_color' => $color, ':c_d' => $firstReg, ':c_km' => $mileage, ':c_id' => $cId]);
                } else {
                    $newRow = $db->getOne(<<<SQL
                        INSERT INTO cars_lxcars (c_ow, c_ln, c_fin, c_mt, c_color, c_d, c_km)
                        SELECT CAST(:c_ow AS integer), CAST(:c_ln AS text),
                               CASE WHEN CAST(:vin AS text) IS NULL OR EXISTS (SELECT 1 FROM cars_lxcars x WHERE x.c_fin = CAST(:vin AS text))
                                    THEN NULL ELSE CAST(:vin AS text) END,
                               CAST(:c_mt AS text), CAST(:c_color AS text), CAST(:c_d AS date), CAST(:c_km AS integer)
                        RETURNING c_id, c_ln, c_mt, c_fin
SQL, [':c_ow' => $customerId, ':c_ln' => $plate, ':vin' => $vin, ':c_mt' => $model,
      ':c_color' => $color, ':c_d' => $firstReg, ':c_km' => $mileage]);
                    $cId        = intval($newRow['c_id']);
                    $carCreated = true;
                    $newCar     = $newRow;
                }
                $db->execute(
                    "INSERT INTO oe_ext (oe_id, c_id) VALUES (:oe_id, :c_id)
                     ON CONFLICT (oe_id) DO UPDATE SET c_id = EXCLUDED.c_id",
                    [':oe_id' => $oeId, ':c_id' => $cId]
                );
                $carLinked = true;
            }
        }

        // ── km-Stand des Auftrags ──
        if ($applyKm && $mileage) {
            $db->execute(
                "INSERT INTO oe_ext (oe_id, km_stand) VALUES (:oe_id, :km)
                 ON CONFLICT (oe_id) DO UPDATE SET km_stand = EXCLUDED.km_stand",
                [':oe_id' => $oeId, ':km' => $mileage]
            );
        }

        // ── Gutachtendaten: interne Notizen + Schadennummer als Kundenauftragsnummer ──
        if ($applyNotes) {
            $noteText = _gi_buildNote($appraisal);
            $claim    = trim($appraisal['claim_number'] ?? '');
            if ($noteText !== '' || $claim !== '') {
                $db->execute(<<<SQL
                    UPDATE oe SET
                        intnotes     = CASE WHEN CAST(:note AS text) = '' THEN intnotes
                                            WHEN COALESCE(intnotes, '') = '' THEN CAST(:note AS text)
                                            ELSE intnotes || E'\n\n' || CAST(:note AS text) END,
                        cusordnumber = COALESCE(NULLIF(cusordnumber, ''), NULLIF(:claim, ''))
                    WHERE id = :oe_id
SQL, [':note' => $noteText, ':claim' => mb_substr($claim, 0, 30), ':oe_id' => $oeId]);
            }
        }

        // ── Positionen ──
        $createdIds = [];
        if (!empty($items)) {
            $createdIds = _fakturaImportItems($db, $oeId, 'order', $items, $bgTeile, $bgArbeit);
        }

        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw new ApiError('IMPORT_FAILED', 'Übernahme fehlgeschlagen: ' . $e->getMessage());
    }

    // ── Ordner und Symlinks wie bei quickCreateCar (ausserhalb der Transaktion) ──
    if ($carCreated || $takenOver) {
        if ($takenOver && $oldOwnerId > 0) {
            $safePlate = preg_replace('/[^a-zA-Z0-9\-]/', '_', $plate);
            $oldLink   = fmDataDir() . '/customers/' . $oldOwnerId . '/fahrzeuge/' . $safePlate;
            if (is_link($oldLink)) @unlink($oldLink);
        }
        ensureVehiclePaths(['c_id' => $cId, 'c_ln' => $plate, 'c_ow' => $customerId]);
        ensureCustomerFolder($customerId, 'C', trim($order['customer_name'] ?? ''));
    }

    // ── Gutachten-Datei ablegen: im Fahrzeugordner, sonst beim Kunden ──
    $storedPath = null;
    if ($storePdf && !empty($data['file_base64'])) {
        $content = base64_decode($data['file_base64'], true);
        if ($content !== false && strlen($content) <= FM_MAX_FILESIZE) {
            $storedPath = _gi_storeFile($content, $data['filename'] ?? 'gutachten.pdf', $cId, $customerId);
        }
    }

    // ── Analyse für Weroni bereitlegen: geprüft wird erst auf Knopfdruck ──
    $extracted = is_array($data['extracted'] ?? null) ? $data['extracted'] : null;
    if ($extracted) {
        $db->execute(<<<SQL
            WITH del AS (
                DELETE FROM gutachten_pruefung_lxcars WHERE oe_id = :oe_id AND review IS NULL
            )
            INSERT INTO gutachten_pruefung_lxcars (oe_id, c_id, stored_path, extracted)
            VALUES (:oe_id2, :c_id, :stored_path, CAST(:extracted AS jsonb))
SQL, [
            ':oe_id'       => $oeId,
            ':oe_id2'      => $oeId,
            ':c_id'        => $cId ?: null,
            ':stored_path' => $storedPath,
            ':extracted'   => json_encode($extracted, JSON_UNESCAPED_UNICODE),
        ]);
    }

    resultInfo(true, 'IMPORTED', [
        'count'         => count($createdIds),
        'c_id'          => $cId ?: null,
        'plate'         => $plate ?: null,
        'car_created'   => $carCreated,
        'car_linked'    => $carLinked,
        'taken_over'    => $takenOver,
        'km_applied'    => $applyKm && $mileage ? $mileage : null,
        'notes_applied' => $applyNotes,
        'stored_path'   => $storedPath,
    ]);
}

// =========================================================================
// Hilfsfunktionen (keine API-Endpoints)
// =========================================================================

/**
 * Schickt das Dokument an Claude und liefert das rohe JSON der Extraktion.
 *
 * Der System-Prompt ist bei jedem Gutachten identisch und wird zwischen-
 * gespeichert (cache_control); nur das Dokument und der Auftragskontext
 * reisen jedes Mal mit.
 */
function _gi_askClaude($apiKey, $model, $fileBase64, $mediaType, array $context) {
    $systemPrompt = <<<'PROMPT'
Du bist ein erfahrener Kfz-Werkstattmeister und liest Schadengutachten, Kostenvoranschläge und Reparaturkalkulationen beliebiger Herkunft (freie Sachverständige, DEKRA, TÜV, SSH, DAT, Audatex, Versicherer, Kostenvoranschläge anderer Werkstätten). Das Ziel: Der Auftrag in der Werkstatt soll ohne Abtippen entstehen.

Antworte AUSSCHLIESSLICH mit einem validen JSON-Objekt (kein Markdown, kein Text drumherum) in genau dieser Struktur:

{
  "vehicle": {
    "license_plate": "amtliches Kennzeichen wie gedruckt, z.B. B-AB 1234, sonst null",
    "vin": "17-stellige Fahrzeug-Identifizierungsnummer oder null",
    "manufacturer": "Hersteller, z.B. Volkswagen",
    "model": "Modell/Typ/Handelsbezeichnung, z.B. Golf VIII 2.0 TDI",
    "first_registration": "YYYY-MM-DD oder null",
    "mileage": 45120,
    "color": "Farbe oder null",
    "hsn": "Herstellerschlüsselnummer (4-stellig) oder null",
    "tsn": "Typschlüsselnummer oder null"
  },
  "appraisal": {
    "number": "Gutachten-/Vorgangsnummer oder null",
    "date": "YYYY-MM-DD oder null",
    "appraiser": "Sachverständigenbüro / Ersteller oder null",
    "insurer": "Versicherung oder null",
    "claim_number": "Schadennummer der Versicherung oder null",
    "damage_date": "YYYY-MM-DD oder null",
    "damage_description": "Schaden in einem Satz (betroffene Bereiche)",
    "repair_costs_net": 1234.56,
    "repair_costs_gross": 1469.13,
    "labour_rate": 110.00,
    "labour_rate_unit": "Std oder AW",
    "lacquer_rate": 120.00,
    "legal": {
      "wiederbeschaffungswert_brutto": 24500.00,
      "wiederbeschaffungswert_netto": null,
      "restwert": 17900.00,
      "wertminderung": 250.00,
      "nutzungsausfall_tage": 5,
      "nutzungsausfall_gruppe": "F",
      "nutzungsausfall_tagessatz": 59.00,
      "nutzungsausfall_betrag": 295.00,
      "reparaturdauer_tage": 5,
      "sachverstaendigenkosten": 612.00,
      "verbringungskosten": null,
      "upe_aufschlag_prozent": null,
      "abrechnungsart": "konkret | fiktiv | unbekannt",
      "schadenart": "haftpflicht | kasko | unbekannt",
      "haftungsquote_prozent": null,
      "vorschaeden": "keine | Beschreibung | null",
      "fahrzeug_vorsteuerabzug": null,
      "reparaturwuerdig_laut_gutachter": true,
      "hinweise": "Hinweise des Gutachters zu Restwertangeboten, Reparaturweg, Prüfberichten"
    }
  },
  "items": [
    {
      "category": "part | labour | lacquer | additional",
      "description": "Bezeichnung der Position",
      "partnumber": "Teilenummer (OE-Nummer) oder leer",
      "qty": 1,
      "unit": "Stck | AW | Std",
      "sellprice": 100.00,
      "total": 100.00
    }
  ],
  "confidence": 0.9,
  "notes": "Unklarheiten, Annahmen, abweichende Summen"
}

REGELN FÜR DIE POSITIONEN:
- Übernimm NUR die Reparaturkalkulation: Ersatzteile, Arbeitslohn (Mechanik/Karosserie/Elektrik), Lackierung (Lohn und Material), Zuschläge und Nebenkosten der Reparatur (Kleinersatzteile, Verbrauchsmaterial, Entsorgung, Achsvermessung, Fahrzeug reinigen, Probefahrt, Diagnose, Kalibrierung).
- NICHT übernehmen: Wertminderung, Nutzungsausfall, Mietwagen, Sachverständigenhonorar/Gutachterkosten, Restwert, Wiederbeschaffungswert, Reparaturdauer, Zwischensummen, Gesamtsummen, Mehrwertsteuer-Zeilen, Überschriften, Hinweiszeilen.
- Alle Preise NETTO (ohne Mehrwertsteuer). Steht nur ein Bruttobetrag, rechne mit dem im Dokument genannten Steuersatz auf netto zurück.
- category "part": Ersatzteile. qty = Stückzahl, unit "Stck", sellprice = Einzelpreis netto. Teilenummer nur, wenn sie im Dokument steht — nie erfinden.
- category "labour": Arbeitspositionen (Aus-/Einbau, Instandsetzen, Erneuern, Prüfen). qty = Zeiteinheiten laut Dokument (AW oder Stunden), unit entsprechend "AW" oder "Std", sellprice = Verrechnungssatz je Einheit. Steht nur ein Gesamtbetrag ohne Zeit, dann qty 1, unit "Stck", sellprice = Betrag.
- category "lacquer": Lackierlohn (qty Zeiteinheiten, unit AW/Std, sellprice Lacksatz je Einheit) und Lackmaterial (qty 1, unit "Stck", sellprice = Betrag). Pauschale Lackierkosten je Teil ebenfalls als lacquer.
- category "additional": alles Übrige aus der Reparaturkalkulation (Kleinersatzteile-Zuschlag, Verbrauchsmaterial, Entsorgung, Nebenkosten) — qty 1, unit "Stck", sellprice = Betrag.
- total = qty × sellprice (netto). Runde Preise auf 2 Nachkommastellen.
- Beschreibungen in der Sprache des Dokuments, Abkürzungen ausschreiben, wo eindeutig (z.B. "E+A" → "aus- und einbauen", "ern." → "erneuern", "inst." → "instandsetzen", "lack." → "lackieren"). Teilenamen behalten (z.B. "Stoßfänger vorn").
- Reihenfolge wie im Dokument. Jede Position genau einmal, auch wenn sie in Zusammenfassung und Detailseite doppelt erscheint.
- Prüfe am Ende: Summe aller total soll den Reparaturkosten netto entsprechen. Weicht sie ab, erkläre das in "notes".

REGELN FÜR FAHRZEUG UND GUTACHTEN:
- "legal" sammelt die weiteren Werte des Gutachtens, die NICHT als Positionen übernommen werden, aber für die rechtliche Prüfung zählen: Wiederbeschaffungswert, Restwert, merkantile Wertminderung, Nutzungsausfall (Tage, Gruppe, Tagessatz, Betrag), Reparaturdauer, Sachverständigenhonorar, Verbringungskosten, UPE-Aufschlag, Abrechnungsart, Schadenart (Haftpflicht/Kasko), Haftungsquote, Vorschäden, Vorsteuerabzugsberechtigung, Reparaturwürdigkeit laut Gutachter. Was nicht im Dokument steht, bleibt null.
- Datumsformat immer YYYY-MM-DD, Zahlen als Zahlen (nicht als Strings), unbekannte Felder null.
- mileage ist der abgelesene Kilometerstand als ganze Zahl.
- confidence 0.0–1.0: wie sicher du bei Positionen und Beträgen bist. Unter 0.7, wenn Teile des Dokuments unleserlich sind.
PROMPT;

    $contextLines = [];
    if (!empty($context['c_ln']))  $contextLines[] = 'Am Auftrag verknüpftes Fahrzeug: Kennzeichen ' . $context['c_ln']
        . (!empty($context['c_fin']) ? ', FIN ' . $context['c_fin'] : '')
        . (!empty($context['c_mt']) ? ', ' . $context['c_mt'] : '');
    $userText = (empty($contextLines) ? 'Der Auftrag hat noch kein verknüpftes Fahrzeug.' : implode("\n", $contextLines))
        . "\n\nLies das beigefügte Gutachten vollständig aus — alle Seiten, alle Kalkulationspositionen.";

    $contentBlock = $mediaType === 'application/pdf'
        ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $fileBase64]]
        : ['type' => 'image',    'source' => ['type' => 'base64', 'media_type' => $mediaType,        'data' => $fileBase64]];

    // max_tokens deckt Denk- UND Antwort-Tokens: ein Gutachten hat leicht
    // 100+ Positionen, knapp bemessen bricht die Antwort mitten im JSON ab.
    $requestBody = json_encode([
        'model'         => $model,
        'max_tokens'    => 24000,
        'output_config' => ['effort' => 'medium'],
        'system'        => [[
            'type'          => 'text',
            'text'          => $systemPrompt,
            'cache_control' => ['type' => 'ephemeral'],
        ]],
        'messages'      => [[
            'role'    => 'user',
            'content' => [$contentBlock, ['type' => 'text', 'text' => $userText]],
        ]],
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
            'KI-Analyse fehlgeschlagen (HTTP ' . $httpCode . ')' . ($apiError !== '' ? ': ' . $apiError : ''));
    }

    $responseData = json_decode($response, true);
    $aiText = '';
    foreach ($responseData['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') { $aiText = $block['text'] ?? ''; break; }
    }
    if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/', $aiText, $m)) {
        $aiText = $m[1];
    }
    $extracted = json_decode(trim($aiText), true);
    if (!is_array($extracted) || !isset($extracted['items'])) {
        // Letzter Versuch: erstes { … letztes }
        $start = strpos($aiText, '{');
        $end   = strrpos($aiText, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $extracted = json_decode(substr($aiText, $start, $end - $start + 1), true);
        }
    }
    if (!is_array($extracted) || !isset($extracted['items'])) {
        throw new ApiError('EXTRACTION_ERROR', 'KI konnte das Gutachten nicht auswerten'
            . (($responseData['stop_reason'] ?? '') === 'max_tokens' ? ' (Antwort abgeschnitten)' : ''));
    }
    return $extracted;
}

/**
 * Bringt die KI-Antwort in die Form, die der Dialog und der Positions-Import
 * erwarten: saubere Zahlen, gültige Kategorien/Einheiten, part_type je Position,
 * Abgleich der Positionssumme mit den Reparaturkosten.
 */
function _gi_normalize(array $x): array {
    $v = is_array($x['vehicle'] ?? null) ? $x['vehicle'] : [];
    $a = is_array($x['appraisal'] ?? null) ? $x['appraisal'] : [];

    $str = fn($val, $max = 200) => is_scalar($val) && trim((string)$val) !== '' && strtolower(trim((string)$val)) !== 'null'
        ? mb_substr(trim((string)$val), 0, $max) : null;
    $num = fn($val) => is_numeric($val) ? round(floatval($val), 2)
        : (is_string($val) && is_numeric(str_replace(['.', ','], ['', '.'], $val)) ? round(floatval(str_replace(['.', ','], ['', '.'], $val)), 2) : null);

    $vehicle = [
        'license_plate'      => $str($v['license_plate'] ?? null, 20),
        'plate_normalized'   => _gi_normalizePlate($v['license_plate'] ?? ''),
        'vin'                => _gi_normalizeVin($v['vin'] ?? ''),
        'manufacturer'       => $str($v['manufacturer'] ?? null, 50),
        'model'              => $str($v['model'] ?? null, 80),
        'first_registration' => _gi_normalizeDate($v['first_registration'] ?? ''),
        'mileage'            => intval($v['mileage'] ?? 0) > 0 ? intval($v['mileage']) : null,
        'color'              => $str($v['color'] ?? null, 30),
        'hsn'                => $str($v['hsn'] ?? null, 4),
        'tsn'                => $str($v['tsn'] ?? null, 10),
    ];
    $appraisal = [
        'number'             => $str($a['number'] ?? null, 60),
        'date'               => _gi_normalizeDate($a['date'] ?? ''),
        'appraiser'          => $str($a['appraiser'] ?? null, 120),
        'insurer'            => $str($a['insurer'] ?? null, 120),
        'claim_number'       => $str($a['claim_number'] ?? null, 60),
        'damage_date'        => _gi_normalizeDate($a['damage_date'] ?? ''),
        'damage_description' => $str($a['damage_description'] ?? null, 500),
        'repair_costs_net'   => $num($a['repair_costs_net'] ?? null),
        'repair_costs_gross' => $num($a['repair_costs_gross'] ?? null),
        'labour_rate'        => $num($a['labour_rate'] ?? null),
        'labour_rate_unit'   => in_array(strtoupper((string)($a['labour_rate_unit'] ?? '')), ['AW', 'STD']) ? (strtoupper($a['labour_rate_unit']) === 'AW' ? 'AW' : 'Std') : null,
        'lacquer_rate'       => $num($a['lacquer_rate'] ?? null),
        'legal'              => _gi_normalizeLegal(is_array($a['legal'] ?? null) ? $a['legal'] : [], $str, $num),
    ];

    $items = [];
    foreach ((array)($x['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $description = $str($it['description'] ?? null, 255);
        if ($description === null) continue;

        $category = strtolower(trim((string)($it['category'] ?? 'additional')));
        if (!in_array($category, ['part', 'labour', 'lacquer', 'additional'], true)) $category = 'additional';

        $qty   = $num($it['qty'] ?? null);
        $price = $num($it['sellprice'] ?? null);
        $total = $num($it['total'] ?? null);
        if ($qty === null || $qty <= 0) $qty = 1.0;
        if ($price === null && $total !== null) $price = round($total / $qty, 2);
        if ($price === null) $price = 0.0;
        if ($price === 0.0 && ($total === null || $total == 0)) continue; // Überschriften ohne Betrag

        $unitRaw = strtoupper(trim((string)($it['unit'] ?? '')));
        if ($category === 'part') {
            $unit = 'Stck';
        } elseif (in_array($category, ['labour', 'lacquer'], true)) {
            $unit = $unitRaw === 'AW' ? 'AW' : (in_array($unitRaw, ['STD', 'H', 'STUNDE', 'STUNDEN', 'ZE'], true) ? 'Std' : 'Stck');
        } else {
            $unit = 'Stck';
        }

        $partnumber = $str($it['partnumber'] ?? null, 40) ?? '';
        // Teile ohne Teilenummer, die eine Pauschale sind, laufen wie beim
        // SilverDAT-Import als Dienstleistung (Nummer aus dem servicenumber-Kreis)
        $isFlat = $partnumber === '' && preg_match('/kleinteil|kleinersatz|verbrauchsmaterial|zuschlag|pauschal/i', $description);
        $partType = ($category === 'part' && !$isFlat) ? 'part' : 'service';

        $items[] = [
            'category'        => $category,
            'part_type'       => $partType,
            'description'     => $description,
            'partnumber'      => $partnumber,
            'qty'             => $qty,
            'unit'            => $unit,
            'sellprice'       => $price,
            'longdescription' => '',
            'selected'        => true,
        ];
    }

    $itemsNet = round(array_sum(array_map(fn($i) => $i['qty'] * $i['sellprice'], $items)), 2);
    $delta    = $appraisal['repair_costs_net'] !== null ? round($itemsNet - $appraisal['repair_costs_net'], 2) : null;

    return [
        'vehicle'    => $vehicle,
        'appraisal'  => $appraisal,
        'items'      => $items,
        'items_net'  => $itemsNet,
        'delta_net'  => $delta,
        'confidence' => is_numeric($x['confidence'] ?? null) ? max(0, min(1, floatval($x['confidence']))) : 0.5,
        'notes'      => $str($x['notes'] ?? null, 1000),
    ];
}

/**
 * Weitere Werte des Gutachtens für die Rechtsprüfung (Weroni) — nur saubere
 * Zahlen und bekannte Schlüsselwörter, alles andere null.
 */
function _gi_normalizeLegal(array $l, callable $str, callable $num): array {
    $enum = function ($v, array $allowed) {
        $s = strtolower(trim((string)$v));
        return in_array($s, $allowed, true) ? $s : null;
    };
    $bool = fn($v) => is_bool($v) ? $v : (in_array(strtolower((string)$v), ['true', 'ja', '1'], true) ? true
        : (in_array(strtolower((string)$v), ['false', 'nein', '0'], true) ? false : null));
    $int = fn($v) => is_numeric($v) && intval($v) > 0 ? intval($v) : null;
    return [
        'wiederbeschaffungswert_brutto'   => $num($l['wiederbeschaffungswert_brutto'] ?? null),
        'wiederbeschaffungswert_netto'    => $num($l['wiederbeschaffungswert_netto'] ?? null),
        'restwert'                        => $num($l['restwert'] ?? null),
        'wertminderung'                   => $num($l['wertminderung'] ?? null),
        'nutzungsausfall_tage'            => $int($l['nutzungsausfall_tage'] ?? null),
        'nutzungsausfall_gruppe'          => $str($l['nutzungsausfall_gruppe'] ?? null, 5),
        'nutzungsausfall_tagessatz'       => $num($l['nutzungsausfall_tagessatz'] ?? null),
        'nutzungsausfall_betrag'          => $num($l['nutzungsausfall_betrag'] ?? null),
        'reparaturdauer_tage'             => $int($l['reparaturdauer_tage'] ?? null),
        'sachverstaendigenkosten'         => $num($l['sachverstaendigenkosten'] ?? null),
        'verbringungskosten'              => $num($l['verbringungskosten'] ?? null),
        'upe_aufschlag_prozent'           => $num($l['upe_aufschlag_prozent'] ?? null),
        'abrechnungsart'                  => $enum($l['abrechnungsart'] ?? '', ['konkret', 'fiktiv']),
        'schadenart'                      => $enum($l['schadenart'] ?? '', ['haftpflicht', 'kasko']),
        'haftungsquote_prozent'           => $num($l['haftungsquote_prozent'] ?? null),
        'vorschaeden'                     => $str($l['vorschaeden'] ?? null, 300),
        'fahrzeug_vorsteuerabzug'         => $bool($l['fahrzeug_vorsteuerabzug'] ?? null),
        'reparaturwuerdig_laut_gutachter' => $bool($l['reparaturwuerdig_laut_gutachter'] ?? null),
        'hinweise'                        => $str($l['hinweise'] ?? null, 600),
    ];
}

/**
 * Kennzeichen wie im Fahrzeugstamm: Grossbuchstaben, erstes Leerzeichen wird
 * zum Bindestrich, keine weiteren Leerzeichen. Länger als 10 Zeichen → leer
 * (passt nicht in cars_lxcars.c_ln).
 */
function _gi_normalizePlate($raw): string {
    $v = mb_strtoupper(trim((string)$raw));
    if ($v === '' || $v === 'NULL') return '';
    $v = preg_replace('/\s+/', ' ', $v);
    if (!str_contains($v, '-')) $v = preg_replace('/ /', '-', $v, 1);
    $v = str_replace(' ', '', $v);
    $v = preg_replace('/[^A-ZÄÖÜ0-9\-]/u', '', $v);
    return mb_strlen($v) <= 10 ? $v : '';
}

/** FIN: 17 Zeichen, ohne I/O/Q — sonst null */
function _gi_normalizeVin($raw): ?string {
    $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$raw));
    return preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $v) ? $v : null;
}

/** Datum YYYY-MM-DD (auch DD.MM.YYYY wird erkannt) — sonst null */
function _gi_normalizeDate($raw): ?string {
    $v = trim((string)$raw);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $v;
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return null;
}

/** Textblock für die internen Notizen des Auftrags */
function _gi_buildNote(array $a): string {
    $de = fn($iso) => $iso && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? "$m[3].$m[2].$m[1]" : $iso;
    $lines = [];
    $head = 'Gutachten' . (!empty($a['number']) ? ' Nr. ' . trim($a['number']) : '') . (!empty($a['date']) ? ' vom ' . $de($a['date']) : '');
    if (!empty($a['appraiser'])) $head .= ' (' . trim($a['appraiser']) . ')';
    $lines[] = $head;
    if (!empty($a['insurer']))            $lines[] = 'Versicherung: ' . trim($a['insurer']);
    if (!empty($a['claim_number']))       $lines[] = 'Schadennummer: ' . trim($a['claim_number']);
    if (!empty($a['damage_date']))        $lines[] = 'Schadentag: ' . $de($a['damage_date']);
    if (!empty($a['damage_description'])) $lines[] = 'Schaden: ' . trim($a['damage_description']);
    if (isset($a['repair_costs_net']) && is_numeric($a['repair_costs_net'])) {
        $lines[] = 'Reparaturkosten lt. Gutachten: ' . number_format(floatval($a['repair_costs_net']), 2, ',', '.') . ' € netto';
    }
    return count($lines) > 1 || !empty($a['number']) || !empty($a['date']) ? implode("\n", $lines) : '';
}

/**
 * Legt die Gutachten-Datei ab: fahrzeuge/{c_id}/gutachten/, ohne Fahrzeug
 * customers/{customer_id}/gutachten/. Vorhandene Namen werden nummeriert.
 *
 * @return string|null Pfad relativ zum Datenverzeichnis
 */
function _gi_storeFile(string $content, string $filename, int $cId, int $customerId): ?string {
    if ($cId > 0) {
        $relDir = 'fahrzeuge/' . $cId . '/gutachten';
    } elseif ($customerId > 0) {
        $relDir = fmGetBasePath('C') . '/' . $customerId . '/gutachten';
    } else {
        return null;
    }
    $absDir = fmDataDir() . '/' . $relDir;
    if (!is_dir($absDir)) fmMkdir($absDir);

    $safe = preg_replace('/[^\w.\-]/u', '_', basename($filename));
    if ($safe === '' || $safe === '.' || $safe === '..') $safe = 'gutachten.pdf';
    $base = pathinfo($safe, PATHINFO_FILENAME);
    $ext  = pathinfo($safe, PATHINFO_EXTENSION);
    $name = $safe;
    for ($i = 2; file_exists($absDir . '/' . $name) && $i < 100; $i++) {
        $name = $base . '_' . $i . ($ext !== '' ? '.' . $ext : '');
    }
    $abs = $absDir . '/' . $name;
    if (file_put_contents($abs, $content) === false) return null;

    $cfg = fmGetDirConfig();
    if (!empty($cfg['group'])) @chgrp($abs, $cfg['group']);
    @chmod($abs, 0664);

    return $relDir . '/' . $name;
}
