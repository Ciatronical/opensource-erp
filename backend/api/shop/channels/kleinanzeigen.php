<?php
// backend/api/shop/channels/kleinanzeigen.php
//
// Verkaufskanal Kleinanzeigen (dev/shop-kleinanzeigen.md). Kleinanzeigen.de
// hat keine Schnittstelle; die Anbindung läuft über den Kleinanzeigen-Dienst
// (backend/services/kleinanzeigen): ein Headless-Chrome, der sich mit dem
// Konto des Kanals anmeldet und Anzeigen einstellt, ändert, löscht, die
// Anzeigenliste liest und die Nachrichten holt (lib/kleinanzeigen_worker.php).
//
// Zwei Betriebsarten je Mandant:
//
//   automatisch  Dienst eingeschaltet (kleinanzeigen_worker_enabled) und
//                Zugang im Kanal (login_email): die Aufträge der Warteschlange
//                stellen ein, aktualisieren und löschen bei Kleinanzeigen;
//                sync_ads gleicht mit „Meine Anzeigen“ ab, import_leads holt
//                die Unterhaltungen. Der Benutzer bestätigt höchstens einmal
//                die Anmeldung (Code aus dem Postfach kommt von selbst).
//   von Hand     ohne Dienst: OSERP bereitet die Anzeige vor, der Benutzer
//                stellt sie per Kopieren ein und meldet zurück; Anfragen
//                kommen aus den Benachrichtigungs-Mails (IMAP).
//
// Stand je Artikel in parts_channel_shop (beide Betriebsarten gleich):
//   sync_status  draft, active, reserved, ended; external_id Anzeigennummer;
//   sync_data    url, online_since, refreshed_at, posted_hash, sold_out …
// Was zu tun ist, leitet kleinanzeigen_board() live ab.

require_once __DIR__.'/../lib/kleinanzeigen_worker.php';
require_once __DIR__.'/../../email/mailer.php';

/**
 * Auftragsarten des Kleinanzeigen-Kanals
 *
 * publish_part/remove_part/publish_all/remove_all legen die Trigger an;
 * sync_ads und import_leads der Cron (tools/shop-publish.php
 * --reconcile-payments), wie den PayPal-Abgleich.
 *
 * @return array
 */
function shopChannelKleinanzeigenJobFunctions(): array {
    return ['publish_part', 'remove_part', 'publish_all', 'remove_all', 'sync_ads', 'import_leads'];
}

/**
 * Läuft dieser Kanal automatisch über den Dienst?
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal Kleinanzeigen-Kanal
 * @return bool
 */
function shopKleinanzeigenAutomatic($db, int $kanal): bool {
    return shopKleinanzeigenWorkerEnabled($db)
        && '' !== trim((string)(shopChannelConfig($db, $kanal)['login_email'] ?? ''));
}

/**
 * Reagiert auf Ein- und Ausschalten des Kanals
 *
 * Automatisch: aus = alle Anzeigen löschen (remove_all), an = alle
 * angebotenen einstellen (publish_all). Von Hand: nichts zu übertragen —
 * das Board zeigt bei abgeschaltetem Kanal jede Online-Anzeige als „zu
 * löschen“. Offene Aufträge der Gegenrichtung werden verworfen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal Kleinanzeigen-Kanal
 * @param bool $an eingeschaltet
 * @return void
 */
function shopChannelKleinanzeigenSwitched($db, int $kanal, bool $an): void {
    $db->execute(
        "DELETE FROM batchjob_hugoshop
          WHERE result IS NULL
            AND channel_id = CAST(:kanal AS integer)
            AND function = ANY(string_to_array(:gegenrichtung, ','))",
        [':kanal' => $kanal, ':gegenrichtung' => $an ? 'remove_all,remove_part' : 'publish_all,publish_part']
    );
    if (shopKleinanzeigenAutomatic($db, $kanal)) {
        shopQueueJob($db, $an ? 'publish_all' : 'remove_all', '', null, $kanal);
    }
}

/**
 * Arbeitet einen Auftrag des Kleinanzeigen-Kanals ab
 *
 * Von Hand: publish setzt Zeilen ohne Stand (oder beendete, die wieder
 * angeboten werden) auf Entwurf, remove beendet Entwürfe; Online-Anzeigen
 * bleiben, bis der Benutzer sie gelöscht meldet.
 *
 * Automatisch: publish_part stellt die Anzeige über den Dienst ein oder
 * aktualisiert sie; remove_part löscht sie. Scheitert der Dienst, steht der
 * Fehler an der Kanalzeile (sync_error) und der Auftrag gilt als
 * fehlgeschlagen (Wiederholung im Panel).
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $auftrag Zeile aus shopOpenJobs()
 * @param callable $sagen Fortschritt
 * @param callable $fehler Fehlermeldung, wird gezählt
 * @param array $bilanz Zähler des Laufs
 * @return void
 * @throws ApiError wenn ein einzelner Artikel scheitert — der Läufer vermerkt den Auftrag
 */
function shopChannelKleinanzeigenRunJob($db, array $auftrag, callable $sagen, callable $fehler, array &$bilanz): void {
    $id = (int)$auftrag['id'];
    $kanal = (int)$auftrag['channel_id'];
    $funktion = (string)$auftrag['function'];
    $alle = in_array($funktion, ['publish_all', 'remove_all'], true);
    $einstellen = in_array($funktion, ['publish_part', 'publish_all'], true);

    if (!in_array($funktion, shopChannelKleinanzeigenJobFunctions(), true)) {
        $fehler('Auftrag '.$id.': unbekannte Funktion '.$funktion);
        shopJobResult($db, $id, 'Fehler: unbekannte Funktion '.$funktion);
        return;
    }
    $automatisch = shopKleinanzeigenAutomatic($db, $kanal);

    if ('import_leads' === $funktion) {
        $anfragen = $automatisch ? shopKleinanzeigenLeadsViaWorker($db, $kanal) : shopKleinanzeigenImportLeads($db, $kanal);
        $stand = sprintf('Kleinanzeigen: %d %s gesehen, %d Anfragen neu, %d zugeordnet',
                         $anfragen['fetched'], $automatisch ? 'Unterhaltungen' : 'Mails', $anfragen['imported'], $anfragen['matched']);
        $sagen($stand);
        shopJobResult($db, $id, 'ok: '.$stand);
        return;
    }
    if ('sync_ads' === $funktion) {
        if (!$automatisch) {
            shopJobResult($db, $id, 'ok: Kleinanzeigen ohne Dienst — kein Abgleich');
            return;
        }
        $abgleich = shopKleinanzeigenSyncViaWorker($db, $kanal);
        $stand = sprintf('Kleinanzeigen: %d Anzeigen bei Kleinanzeigen, %d beendet, %d reserviert, %d online',
                         $abgleich['fetched'], $abgleich['ended'], $abgleich['reserved'], $abgleich['active']);
        $sagen($stand);
        shopJobResult($db, $id, 'ok: '.$stand);
        return;
    }

    if (!$automatisch) {
        $zeile = $db->getOne(
            "WITH geaendert AS (
                 UPDATE parts_channel_shop pc
                    SET sync_status = CASE WHEN :einstellen = 1 THEN 'draft' ELSE 'ended' END,
                        sync_error  = NULL,
                        sync_mtime  = now(),
                        sync_data   = CASE WHEN :einstellen_daten = 1
                                           THEN COALESCE(pc.sync_data, '{}'::jsonb) - 'posted_hash' - 'url' - 'online_since' - 'refreshed_at'
                                           ELSE pc.sync_data END,
                        external_id = CASE WHEN :einstellen_kennung = 1 THEN NULL ELSE pc.external_id END
                   FROM parts p
                  WHERE p.id = pc.parts_id
                    AND pc.channel_id = CAST(:kanal AS integer)
                    AND (:alle = 1 OR p.partnumber = :partnumber)
                    AND CASE WHEN :einstellen_filter = 1
                             THEN pc.active AND COALESCE(pc.sync_status, '') IN ('', 'ended')
                             ELSE NOT pc.active AND COALESCE(pc.sync_status, 'draft') = 'draft' END
                 RETURNING pc.parts_id
             )
             SELECT count(*) AS anzahl FROM geaendert",
            [
                ':einstellen'         => $einstellen ? 1 : 0,
                ':einstellen_daten'   => $einstellen ? 1 : 0,
                ':einstellen_kennung' => $einstellen ? 1 : 0,
                ':einstellen_filter'  => $einstellen ? 1 : 0,
                ':kanal'              => $kanal,
                ':alle'               => $alle ? 1 : 0,
                ':partnumber'         => (string)($auftrag['partnumber'] ?? ''),
            ]
        );
        $stand = sprintf('Kleinanzeigen: %d %s', (int)($zeile['anzahl'] ?? 0),
                         $einstellen ? 'als Entwurf bereitgestellt' : 'beendet');
        $sagen($stand);
        shopJobResult($db, $id, 'ok: '.$stand);
        return;
    }

    // Automatisch über den Dienst. Einstellen nur, was angeboten und
    // vollständig ist; löschen alles mit Anzeigennummer.
    $artikelListe = $db->getAll(
        "SELECT p.id, p.partnumber
           FROM parts_channel_shop pc
           JOIN parts p ON p.id = pc.parts_id
          WHERE pc.channel_id = CAST(:kanal AS integer)
            AND (:alle = 1 OR p.partnumber = :partnumber)
            AND CASE WHEN :einstellen = 1 THEN pc.active
                     ELSE COALESCE(pc.external_id, '') <> '' OR COALESCE(pc.sync_status, 'draft') = 'draft' END
          ORDER BY p.id",
        [':kanal' => $kanal, ':alle' => $alle ? 1 : 0, ':partnumber' => (string)($auftrag['partnumber'] ?? ''),
         ':einstellen' => $einstellen ? 1 : 0]
    ) ?: [];

    $gut = 0;
    $gescheitert = 0;
    $letzterFehler = '';
    foreach ($artikelListe as $artikel) {
        try {
            if ($einstellen) {
                $ergebnis = shopKleinanzeigenPublishViaWorker($db, $kanal, (int)$artikel['id']);
                $sagen('Kleinanzeigen '.$artikel['partnumber'].': '.($ergebnis['updated'] ? 'aktualisiert' : 'eingestellt').' ('.$ergebnis['id'].')');
            } else {
                $ergebnis = shopKleinanzeigenDeleteViaWorker($db, $kanal, (int)$artikel['id']);
                $sagen('Kleinanzeigen '.$artikel['partnumber'].': '.(!empty($ergebnis['deleted']) ? 'gelöscht' : 'keine Anzeige'));
            }
            $gut++;
        } catch (ApiError $e) {
            $gescheitert++;
            $letzterFehler = $e->getMessage();
            $db->execute(
                "UPDATE parts_channel_shop SET sync_error = :meldung, sync_mtime = now()
                  WHERE parts_id = :parts_id AND channel_id = CAST(:kanal AS integer)",
                [':meldung' => mb_substr($e->getMessage(), 0, 500), ':parts_id' => (int)$artikel['id'], ':kanal' => $kanal]
            );
            if ($gescheitert <= SHOP_MELDUNGEN_JE_AUFTRAG) {
                $fehler('Kleinanzeigen '.$artikel['partnumber'].': '.$e->getMessage());
            }
            // Ohne Anmeldung oder ohne Dienst scheitert jeder weitere genauso
            if (in_array($e->getId(), ['KLEINANZEIGEN_NOT_LOGGED_IN', 'KLEINANZEIGEN_WORKER_DOWN', 'KLEINANZEIGEN_WORKER_OFF'], true)) {
                break;
            }
        }
    }
    $stand = sprintf('Kleinanzeigen: %d %s, %d fehlgeschlagen', $gut, $einstellen ? 'eingestellt' : 'gelöscht', $gescheitert);
    $sagen($stand);
    shopJobResult($db, $id, ($gescheitert > 0 ? 'Fehler: ' : 'ok: ').$stand.('' !== $letzterFehler ? ' — '.$letzterFehler : ''));
    if ($gescheitert > 0 && !$alle) {
        throw new ApiError('KLEINANZEIGEN_JOB_FAILED', $letzterFehler);
    }
}

// ── Anfragen aus dem Postfach (Betrieb von Hand) ──
//
// Kleinanzeigen schickt zu jeder Nachricht eines Interessenten eine
// Benachrichtigung an die Konto-Adresse. Liegt dieses Postfach im
// E-Mail-Modul (Firmenkonfiguration → E-Mail), liest der Kanal die
// Benachrichtigungen und legt je Mail eine Anfrage an — mit Absender,
// Nachricht und, wenn sich die Anzeigennummer oder der Titel zuordnen lässt,
// dem Artikel. Mit Dienst kommen die Anfragen stattdessen direkt aus den
// Unterhaltungen (shopKleinanzeigenLeadsViaWorker).

/**
 * Liest die Benachrichtigungen von Kleinanzeigen aus dem Postfach und legt Anfragen an
 *
 * Mails seit dem letzten Abruf (lead_last_check des Kanals, mit einem Tag
 * Überlappung; beim ersten Mal 30 Tage), Absender kleinanzeigen. Jede Mail
 * nur einmal (mail_uid = Ordner:UID). Gelesen wird mit PEEK — der
 * Lesestatus im Postfach bleibt, wie er ist.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal Kleinanzeigen-Kanal
 * @return array fetched, imported, matched
 * @throws ApiError EMAIL_NOT_CONFIGURED, IMAP-Fehler
 */
function shopKleinanzeigenImportLeads($db, int $kanal): array {
    $cfg = shopChannelConfig($db, $kanal);
    $ordner = trim((string)($cfg['lead_mail_folder'] ?? '')) ?: 'INBOX';
    $letzter = trim((string)($cfg['lead_last_check'] ?? ''));
    $seit = '' !== $letzter && false !== strtotime($letzter)
        ? strtotime($letzter) - 86400
        : time() - 30 * 86400;

    $imap = _createImapClient();
    $bilanz = ['fetched' => 0, 'imported' => 0, 'matched' => 0];
    try {
        $imap->selectFolder($ordner);
        $uids = $imap->search('SINCE '.date('d-M-Y', $seit).' FROM "kleinanzeigen"');
        $bilanz['fetched'] = count($uids);
        if ($uids) {
            // Schon bekannte Mails in einem Rutsch ausfiltern
            $bekannt = $db->getAll(
                "SELECT mail_uid FROM kleinanzeigen_lead_shop
                  WHERE channel_id = CAST(:kanal AS integer)
                    AND mail_uid = ANY(string_to_array(:uids, ','))",
                [':kanal' => $kanal, ':uids' => implode(',', array_map(fn($u) => $ordner.':'.$u, $uids))]
            );
            $bekannt = array_flip(array_column($bekannt ?: [], 'mail_uid'));

            foreach ($uids as $uid) {
                $kennung = $ordner.':'.$uid;
                if (isset($bekannt[$kennung])) {
                    continue;
                }
                $mail = $imap->fetchMessage((int)$uid, true);
                $anfrage = shopKleinanzeigenParseNotification($mail);
                if (null === $anfrage) {
                    continue;
                }
                $zeile = $db->getOne(
                    "INSERT INTO kleinanzeigen_lead_shop
                         (channel_id, parts_id, ad_id, ad_title, sender_name, message, mail_uid, received_at)
                     SELECT CAST(:kanal AS integer),
                            (SELECT pc.parts_id
                               FROM parts_channel_shop pc
                               JOIN parts p ON p.id = pc.parts_id
                              WHERE pc.channel_id = CAST(:kanal_artikel AS integer)
                                AND ((:ad_id <> '' AND pc.external_id = :ad_id_vergleich)
                                     OR (:titel <> '' AND lower(btrim(COALESCE(NULLIF(pc.title, ''), p.description)))
                                                          = lower(btrim(:titel_vergleich))))
                              ORDER BY (pc.external_id = :ad_id_rang) DESC NULLS LAST, pc.sync_mtime DESC NULLS LAST
                              LIMIT 1),
                            NULLIF(:ad_id_wert, ''), NULLIF(:titel_wert, ''), NULLIF(:absender, ''), CAST(:nachricht AS text),
                            CAST(:mail_uid AS text), COALESCE(CAST(NULLIF(:empfangen, '') AS timestamp), now())
                     ON CONFLICT (channel_id, mail_uid) DO NOTHING
                     RETURNING id, parts_id",
                    [
                        ':kanal'           => $kanal,
                        ':kanal_artikel'   => $kanal,
                        ':ad_id'           => $anfrage['ad_id'],
                        ':ad_id_vergleich' => $anfrage['ad_id'],
                        ':ad_id_rang'      => $anfrage['ad_id'],
                        ':ad_id_wert'      => $anfrage['ad_id'],
                        ':titel'           => $anfrage['title'],
                        ':titel_vergleich' => $anfrage['title'],
                        ':titel_wert'      => $anfrage['title'],
                        ':absender'        => $anfrage['sender'],
                        ':nachricht'       => $anfrage['message'],
                        ':mail_uid'        => $kennung,
                        ':empfangen'       => $anfrage['received_at'],
                    ]
                );
                if ($zeile) {
                    $bilanz['imported']++;
                    if (!empty($zeile['parts_id'])) {
                        $bilanz['matched']++;
                    }
                }
            }
        }
    } finally {
        $imap->disconnect();
    }

    $db->execute(
        "UPDATE sales_channel_shop
            SET settings = COALESCE(settings, '{}'::jsonb) || jsonb_build_object('lead_last_check', to_char(now(), 'YYYY-MM-DD HH24:MI:SS'))
          WHERE id = CAST(:kanal AS integer)",
        [':kanal' => $kanal]
    );
    shopChannelConfig($db, $kanal, true);
    return $bilanz;
}

/**
 * Liest Anzeige, Absender und Nachricht aus einer Benachrichtigung von Kleinanzeigen
 *
 * Die Mails sind HTML mit wechselndem Aufbau; gesucht wird robust: die
 * Anzeigennummer aus dem Link zur Anzeige, der Absender aus dem Betreff
 * („Neue Nachricht von … zu …“), der Titel aus Betreff oder Text, die
 * Nachricht als Textteil bis zu den Schaltflächen und Fußzeilen.
 *
 * @param array $mail Ergebnis von ImapClient::fetchMessage()
 * @return array|null ad_id, title, sender, message, received_at — null, wenn es keine Nachricht ist
 */
function shopKleinanzeigenParseNotification(array $mail): ?array {
    $betreff = trim((string)($mail['subject'] ?? ''));
    $html = (string)($mail['body_html'] ?? '');
    $text = trim((string)($mail['body_text'] ?? ''));
    if ('' === $text && '' !== $html) {
        $text = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/tr)[^>]*>#i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    $alles = $betreff."\n".$text."\n".$html;

    // Nur Nachrichten von Interessenten — keine Werbung, keine Hinweise zum Konto
    if (!preg_match('/nachricht|anfrage|antwort/iu', $betreff.' '.mb_substr($text, 0, 400))) {
        return null;
    }

    $adId = '';
    if (preg_match('#/s-anzeige/[^\s"\'<>]*?/(\d{6,})(?:-\d+-\d+)?#u', $alles, $t)
        || preg_match('/(?:adId|anzeigen?(?:-?nummer|-?nr\.?|-?id))\s*[=:#]?\s*(\d{6,})/iu', $alles, $t)) {
        $adId = $t[1];
    }

    $absender = '';
    if (preg_match('/von\s+(.+?)\s+(?:zu|für|hat|an|:|$)/iu', $betreff, $t)
        || preg_match('/^(.+?)\s+hat\s+(?:dir|Ihnen)/iu', $betreff, $t)
        || preg_match('/^(?:Neue\s+)?Nachricht\s+von\s+(.+?)$/iu', $betreff, $t)) {
        $absender = trim($t[1], " \t\"„“”:");
    }

    $titel = '';
    if (preg_match('/zu\s+(?:deiner|Ihrer|der)\s+Anzeige\s*[:„"“]?\s*([^„"”\n]{3,120})/iu', $betreff."\n".$text, $t)
        || preg_match('/[„"“]([^„"”\n]{3,120})[“"”]/u', $betreff, $t)) {
        $titel = trim($t[1], " \t\"„“”:.");
    }

    // Nachricht: Textteil bis zu Schaltflächen oder Fußzeilen
    $nachricht = $text;
    if (preg_match('/^(.*?)(?:\n\s*(?:Jetzt antworten|Antworten|Zur Nachricht|Nachricht ansehen|Diese E-Mail|Kleinanzeigen GmbH|Impressum|Du erhältst diese|Sie erhalten diese).*)$/isu', $text, $t)) {
        $nachricht = $t[1];
    }
    $nachricht = trim(preg_replace("/\n{3,}/", "\n\n", $nachricht));
    $nachricht = mb_substr($nachricht, 0, 1500);

    $empfangen = '';
    if (!empty($mail['date']) && false !== ($zeit = strtotime((string)$mail['date']))) {
        $empfangen = date('Y-m-d H:i:s', $zeit);
    }

    return ['ad_id' => $adId, 'title' => $titel, 'sender' => $absender,
            'message' => $nachricht, 'received_at' => $empfangen];
}

/**
 * Eingeschaltete Kleinanzeigen-Kanäle — für den Abruf im Cron
 *
 * @param object $db Company-Datenbankverbindung
 * @return array Liste aus id und name
 */
function shopKleinanzeigenActiveChannels($db): array {
    return $db->getAll(
        "SELECT id, name FROM sales_channel_shop WHERE type = 'kleinanzeigen' AND active ORDER BY sortkey NULLS LAST, id"
    ) ?: [];
}
