<?php

function sendMail($data) {
    if (empty($data['template'])) {
        throw new ApiError('API_BREVO_MISSING_TEMPLATE', 'Missing email template data');
    }

    $table = match($data['type']) {
        'customer' => 'customer',
        'vendor' => 'vendor',
        'contacts' => 'contacts',
        default => throw new ApiError('API_BREVO_INVALID_TYPE_FILTER', 'Invalid type specified'),
    };

    if (empty($data['ids'])) {
        throw new ApiError('API_BREVO_EMPTY_ID_LIST', 'Empty recipient id list submitted');
    }

    $api = new BrevoApi();
    $mandant = DbhCompany::begin();

    $idList = implode(",", $data['ids']);
    $recipients = match ($table) {
        // Stamm-E-Mail aus customer + zusätzliche E-Mails aus customer_ext.emails (JSONB-Array).
        // UNION dedupliziert, falls die Stamm-E-Mail auch im Array steht.
        // Kunden ohne customer_ext-Eintrag werden trotzdem zurückgegeben (erster SELECT).
        'customer' => $mandant->fetchAll("
            SELECT DISTINCT c.name, c.email
            FROM customer c
            WHERE c.id IN(".$idList.")
            UNION
            SELECT DISTINCT c.name, e.email
            FROM customer c
            JOIN customer_ext ce ON ce.customer_id = c.id
            CROSS JOIN LATERAL jsonb_array_elements_text(ce.emails) AS e(email)
            WHERE c.id IN(".$idList.") AND ce.emails IS NOT NULL AND jsonb_array_length(ce.emails) > 0
        "),
        'vendor' => $mandant->fetchAll("SELECT DISTINCT name, email FROM vendor WHERE id IN(".$idList.")"),
        'contacts' => $mandant->fetchAll("SELECT DISTINCT CONCAT(cp_givenname, ' ', cp_name) AS name, cp_email AS email FROM contacts WHERE cp_id IN(".$idList.")"),
        default => [],
    };

    // E-Mail-Felder normalisieren: Semikolon-separierte Adressen in einzelne Einträge aufsplitten
    $recipients = array_merge(...array_map(function ($r) {
        $emails = array_map('trim', explode(';', $r['email']));
        return array_map(fn($e) => ['name' => $r['name'], 'email' => $e], $emails);
    }, $recipients));

    // Leere E-Mails entfernen, dann Duplikate anhand der E-Mail-Adresse entfernen (erster Treffer gewinnt)
    $recipients = array_filter($recipients, fn($r) => !empty($r['email']));
    $recipients = array_values(array_reduce($recipients, function ($carry, $r) {
        $carry[$r['email']] ??= $r;
        return $carry;
    }, []));

    $recipientsCount = count($recipients);
    if ($recipientsCount === 0) {
        throw new ApiError('API_BREVO_NO_RECIPIENTS_FOUND', 'No recipients found for the provided ids');
    }

    try {
        $remainingCredits = $api->checkAccountPlanQuota();
    } catch (Exception $e) {
        throw new ApiError('API_BREVO_QUOTA_CHECK_FAILED', 'Failed to check Brevo account quota: '.$e->getMessage());
    }

    if ($remainingCredits < $recipientsCount) {
        throw new ApiError('API_BREVO_INSUFFICIENT_CREDITS', 'Insufficient Brevo account credits to send all emails. Remaining: '.$remainingCredits.', required: '.$recipientsCount);
    }

    $failures = 0;
    foreach ($recipients as $recipient) {
        try {
            if (empty($recipient['email'])) {
                throw new Exception('Recipient email is empty');
            }

            $email = new BrevoTransactionalEmail();

            $recipientName = $recipient['name'] ?? '';

            $email->recipientName = $recipientName;
            $email->recipientEmail = $recipient['email'];
            $email->senderName = $data['sender_name'] ?? $data['template']['sender']['name'] ?? null;
            $email->senderEmail = $data['sender_email'] ?? $data['template']['sender']['email'] ?? null;
            $email->replyToEmail = $data['reply_to_email'] ?? $data['template']['reply_to'] ?? null;
            $email->templateId = $data['template']['id'];

            // Weitere Template Parameter setzen
            if (!empty($recipientName)) {
                $email->params = [
                    'recipientName' => $recipientName
                ];
            }

            $result = $api->sendTransactional($email);
            // writeLog('BREVO: Sent email with template id '.$data['template']['id'].' to '.$recipient['email'].' successfully (message ID: '.$result['messageId'].')');
        } catch (Exception $e) {
            $failures++;
            // writeLog('BREVO: Failed to send email with template id '.$data['template']['id'].' to '.$recipient['email'].'. Error: '.$e->getMessage());
            continue;
        }
    }

    $response = [
    'success' => $failures === 0,
        'payload' => [
            'sent_count' => $recipientsCount - $failures,
            'failed_count' => $failures,
        ],
    ];

    echo json_encode($response);
}