<?php
// backend/api/shop/lib/withdrawal.php
//
// Widerruf nach § 356a BGB. Die Bridge schrieb eine JSON-Zeile in eine
// Logdatei und verschickte zwei Mails; der Nachweis hing daran, dass niemand
// die Datei anfasst. Hier steht der Vorgang in withdrawals_hugoshop und ist
// im Admin-Panel sichtbar.
//
// Die Bestellnummer wird nicht geprüft: der Widerruf ist auch mit falscher
// oder fehlender Nummer wirksam. Passt sie zu einer Rechnung des Kunden, wird
// sie zugeordnet — das erspart dem Betreiber das Suchen.

/**
 * Nimmt einen Widerruf entgegen
 *
 * Erst schreiben, dann versenden: wenn der Mailversand scheitert, ist der
 * Vorgang trotzdem festgehalten. Andersherum ginge er verloren.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int|null $customerId Kunde, falls angemeldet
 * @param array $daten name, ordernumber, email, reason, remote_addr, user_agent
 * @return array{id: int, mails: array}
 * @throws ApiError MISSING_NAME, INVALID_EMAIL
 */
function submitWithdrawal($db, ?int $customerId, array $daten): array {
    $name  = trim((string)($daten['name'] ?? ''));
    $email = trim((string)($daten['email'] ?? ''));

    if ('' === $name) {
        throw new ApiError('MISSING_NAME', 'Der Name wird gebraucht');
    }
    if ('' !== $email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiError('INVALID_EMAIL', 'Die E-Mail-Adresse ist unbrauchbar');
    }

    $bestellnummer = trim((string)($daten['ordernumber'] ?? ''));

    $zeile = $db->getOne(
        "INSERT INTO withdrawals_hugoshop
                (name, ordernumber, email, reason, customer_id, ar_id, remote_addr, user_agent)
         SELECT :name, :ordernumber, :email, :reason, :customer_id,
                (SELECT id FROM ar
                  WHERE invnumber = :ordernumber
                    AND (:customer_id::int IS NULL OR customer_id = :customer_id)
                  ORDER BY id DESC LIMIT 1),
                :remote_addr, :user_agent
         RETURNING id, name, ordernumber, email, reason, itime, remote_addr, user_agent",
        [
            ':name'        => $name,
            ':ordernumber' => $bestellnummer,
            ':email'       => $email,
            ':reason'      => trim((string)($daten['reason'] ?? '')),
            ':customer_id' => $customerId,
            ':remote_addr' => (string)($daten['remote_addr'] ?? ''),
            ':user_agent'  => mb_substr((string)($daten['user_agent'] ?? ''), 0, 500),
        ]
    );

    return [
        'id'    => (int)$zeile['id'],
        'mails' => shopSendWithdrawalMails($db, $zeile),
    ];
}

/**
 * Eingegangene Widerrufe für das Admin-Panel
 *
 * @param object $db Company-Datenbankverbindung
 * @param bool $nurOffene true = nur unbearbeitete
 * @param int $limit Höchstzahl
 * @return array
 */
function withdrawalsList($db, bool $nurOffene = false, int $limit = 100): array {
    return $db->getAll(
        "SELECT w.id, w.itime, w.name, w.ordernumber, w.email, w.reason,
                w.customer_id, w.ar_id, w.processed, ar.invnumber
           FROM withdrawals_hugoshop w
           LEFT JOIN ar ON ar.id = w.ar_id
          WHERE NOT :nur_offene OR w.processed IS NULL
          ORDER BY w.itime DESC
          LIMIT :limit",
        [':nur_offene' => $nurOffene, ':limit' => $limit]
    );
}

/**
 * Merkt einen Widerruf als bearbeitet vor oder nimmt das zurück
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $id Widerruf
 * @param bool $bearbeitet true = bearbeitet
 * @return void
 * @throws ApiError WITHDRAWAL_NOT_FOUND
 */
function withdrawalSetProcessed($db, int $id, bool $bearbeitet): void {
    $zeile = $db->getOne(
        "UPDATE withdrawals_hugoshop
            SET processed = CASE WHEN :bearbeitet THEN NOW() ELSE NULL END
          WHERE id = :id
         RETURNING id",
        [':bearbeitet' => $bearbeitet, ':id' => $id]
    );

    if (!$zeile) {
        throw new ApiError('WITHDRAWAL_NOT_FOUND', 'Diesen Widerruf gibt es nicht');
    }
}
