<?php
// backend/api/shop/channels/hugoshop.php
//
// Verkaufskanal HugoShop: die eigene Shop-Webseite, gebaut mit Hugo
// (dev/shop-veroeffentlichung.md, dev/shop-hugocms-trennung.md). Erstes Modul
// nach channels/channels.php.
//
// Die Aufträge schreiben und entfernen Produktseiten, gleichen das
// Webseiten-Paket ab und fragen PayPal nach schwebenden Zahlungen. Paket,
// Kategorieübersicht und Bau nach den Aufträgen erledigt shopPublishRun() in
// lib/publish.php.

/**
 * Auftragsarten des HugoShops
 *
 * @return array
 */
function shopChannelHugoshopJobFunctions(): array {
    return shopJobFunctions();
}

/**
 * Ist der HugoShop eingeschaltet?
 *
 * @param object $db Company-Datenbankverbindung
 * @return bool
 */
function shopChannelHugoshopActive($db): bool {
    $zeile = $db->getOne("SELECT shop_active_channel_id('hugoshop') IS NOT NULL AS an");
    return in_array($zeile['an'] ?? false, [true, 't', 1, '1'], true);
}

/**
 * Dateinamen der Seiten aller Artikel, die im HugoShop angeboten werden
 *
 * Unabhängig davon, ob der Kanal eingeschaltet ist: remove_all läuft, wenn er
 * gerade abgeschaltet wurde.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array Dateinamen ohne Pfad
 */
function shopChannelHugoshopPages($db): array {
    $zeilen = $db->getAll(
        "SELECT p.partnumber, COALESCE(pe.hugoshop_hyperlink, '') AS hyperlink
           FROM parts_channel_shop pc
           JOIN parts p ON p.id = pc.parts_id
           LEFT JOIN parts_ext pe ON pe.parts_id = p.id
          WHERE pc.channel_id = shop_channel_id('hugoshop')
            AND pc.active
          ORDER BY p.id"
    );

    $namen = [];
    foreach ($zeilen as $zeile) {
        try {
            $namen[] = shopPageFileName(['shop'    => ['hyperlink'  => (string)$zeile['hyperlink']],
                                         'artikel' => ['partnumber' => (string)$zeile['partnumber']]]);
        } catch (ApiError $e) {
            // Ohne Namen gibt es auch keine Seite
        }
    }
    return array_values(array_unique($namen));
}

/**
 * Reagiert auf Ein- und Ausschalten des HugoShops (V16)
 *
 * Aus: je nach shop_channel_off_pages die Seiten als Entwurf stehen lassen
 * (draft_all, Vorgabe) oder entfernen (remove_all). An: alle Seiten neu
 * schreiben. Ein noch offener Auftrag der Gegenrichtung wird vorher gelöscht —
 * sonst hinterließe aus-an-aus die Seiten veröffentlicht, weil der zweite
 * Auftrag als Doppel des ersten nicht angelegt würde.
 *
 * @param object $db Company-Datenbankverbindung
 * @param bool $an eingeschaltet
 * @return void
 */
function shopChannelHugoshopSwitched($db, bool $an): void {
    $db->execute(
        "DELETE FROM batchjob_hugoshop
          WHERE result IS NULL
            AND COALESCE(channel_id, shop_channel_id('hugoshop')) = shop_channel_id('hugoshop')
            AND function = ANY(string_to_array(:gegenrichtung, ','))",
        [':gegenrichtung' => $an ? 'remove_all,draft_all' : 'publish_all,publish_part,remove_all,draft_all']
    );

    if ($an) {
        shopQueueJob($db, 'publish_all');
        return;
    }
    shopQueueJob($db, 'remove' === shopConfigValue($db, 'shop_channel_off_pages', 'draft') ? 'remove_all' : 'draft_all');
}

/**
 * Arbeitet einen Auftrag des HugoShops ab
 *
 * Vermerkt das Ergebnis selbst (shopJobResult). Wirft bei Fehlern; der Läufer
 * vermerkt dann den Auftrag als fehlgeschlagen.
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $auftrag Zeile aus shopOpenJobs()
 * @param callable $sagen Fortschritt
 * @param callable $fehler Fehlermeldung, wird gezählt
 * @param array $bilanz Zähler des Laufs (seiten, entfernt, kit)
 * @return void
 */
function shopChannelHugoshopRunJob($db, array $auftrag, callable $sagen, callable $fehler, array &$bilanz): void {
    $id = (int)$auftrag['id'];

    switch ($auftrag['function']) {
        case 'publish_part':
            // Abgeschaltet schreibt der HugoShop keine Seiten (V16): ein
            // Auftrag von vor dem Abschalten brächte sonst eine Seite zurück,
            // die remove_all gerade entfernt hat.
            if (!shopChannelHugoshopActive($db)) {
                $sagen('HugoShop abgeschaltet — Seite nicht geschrieben: '.$auftrag['partnumber']);
                shopJobResult($db, $id, 'ok: HugoShop abgeschaltet, nicht geschrieben');
                break;
            }
            $artikel = $db->getOne(
                "SELECT id FROM parts WHERE partnumber = :partnumber",
                [':partnumber' => $auftrag['partnumber']]
            );
            if (!$artikel) {
                throw new ApiError('PART_NOT_FOUND', 'Artikel nicht gefunden: '.$auftrag['partnumber']);
            }
            $ergebnis = shopWriteProductPage($db, (int)$artikel['id']);
            $bilanz['seiten']++;
            $sagen('Seite geschrieben: '.basename($ergebnis['file']).' (Vorschaubild: '.$ergebnis['thumbnail'].')');
            shopJobResult($db, $id, 'ok: '.basename($ergebnis['file']));
            break;

        case 'publish_all':
            $anzahl = 0;
            $gescheitert = 0;
            $ersterFehler = '';
            foreach (shopListedParts($db) as $artikel) {
                try {
                    shopWriteProductPage($db, (int)$artikel['id']);
                    $anzahl++;
                } catch (ApiError $e) {
                    $gescheitert++;
                    if ('' === $ersterFehler) {
                        $ersterFehler = $e->getMessage();
                    }
                    // Nur die ersten Fehler im Wortlaut: trifft der
                    // Grund alle Artikel — ein fehlendes Verzeichnis
                    // etwa —, kämen sonst Tausende gleicher Zeilen.
                    if ($gescheitert <= SHOP_MELDUNGEN_JE_AUFTRAG) {
                        $fehler('Artikel '.$artikel['partnumber'].': '.$e->getMessage());
                    } else {
                        $bilanz['fehler']++;
                    }
                }
            }
            $bilanz['seiten'] += $anzahl;
            $stand = sprintf('%d Seiten geschrieben, %d fehlgeschlagen', $anzahl, $gescheitert);
            if ($gescheitert > SHOP_MELDUNGEN_JE_AUFTRAG) {
                $sagen(sprintf('… und %d weitere Artikel, nicht einzeln aufgeführt',
                    $gescheitert - SHOP_MELDUNGEN_JE_AUFTRAG));
            }
            $sagen($stand);
            // Ein Auftrag, bei dem kein Artikel durchkam, ist kein
            // erfolgreicher Auftrag — sonst stünde ein grüner Haken an
            // einem Lauf, der nichts zustande gebracht hat.
            shopJobResult($db, $id, $gescheitert > 0
                ? 'Fehler: '.$stand.' — '.$ersterFehler
                : 'ok: '.$anzahl.' Seiten');
            break;

        case 'remove_part':
            $weg = shopRemovePage($db, (string)($auftrag['param'] ?? ''));
            if ($weg) {
                $bilanz['entfernt']++;
            }
            $sagen('Seite entfernt: '.$auftrag['param'].($weg ? '' : ' (gab es nicht)'));
            shopJobResult($db, $id, $weg ? 'ok: entfernt' : 'ok: gab es nicht');
            break;

        case 'remove_all':
            // V16: beim Abschalten des HugoShops alle Seiten der dort
            // angebotenen Artikel entfernen — ein Auftrag statt Tausender
            // einzelner. Die Artikelzeilen bleiben stehen; beim Einschalten
            // schreibt publish_all die Seiten neu.
            $entfernt = 0;
            foreach (shopChannelHugoshopPages($db) as $datei) {
                if (shopRemovePage($db, $datei)) {
                    $entfernt++;
                }
            }
            $bilanz['entfernt'] += $entfernt;
            $sagen(sprintf('Alle Seiten entfernt: %d Dateien', $entfernt));
            shopJobResult($db, $id, 'ok: '.$entfernt.' entfernt');
            break;

        case 'draft_all':
            // V16, Vorgabe: beim Abschalten die Seiten der angebotenen Artikel
            // als Entwurf neu schreiben. Hugo veröffentlicht sie dann nicht
            // mehr; die Dateien bleiben, publish_all macht sie beim Einschalten
            // wieder zu gewöhnlichen Seiten.
            $anzahl = 0;
            $gescheitert = 0;
            foreach ($db->getAll(
                "SELECT p.id, p.partnumber
                   FROM parts_channel_shop pc
                   JOIN parts p ON p.id = pc.parts_id
                  WHERE pc.channel_id = shop_channel_id('hugoshop') AND pc.active
                  ORDER BY p.id"
            ) as $artikel) {
                try {
                    shopWriteProductPage($db, (int)$artikel['id'], true);
                    $anzahl++;
                } catch (ApiError $e) {
                    $gescheitert++;
                    if ($gescheitert <= SHOP_MELDUNGEN_JE_AUFTRAG) {
                        $fehler('Artikel '.$artikel['partnumber'].': '.$e->getMessage());
                    }
                }
            }
            $bilanz['seiten'] += $anzahl;
            $stand = sprintf('%d Seiten als Entwurf, %d fehlgeschlagen', $anzahl, $gescheitert);
            $sagen($stand);
            shopJobResult($db, $id, ($gescheitert > 0 ? 'Fehler: ' : 'ok: ').$stand);
            break;

        case 'sync_kit':
            $kit = shopSyncKit($db);
            $bilanz['kit'] += shopKitChanges($kit);
            $sagen(sprintf('Paket abgeglichen: %d kopiert, %d entfernt%s',
                $kit['kopiert'], $kit['entfernt'], $kit['config'] ? ', Konfiguration neu' : ''));
            shopJobResult($db, $id, sprintf('ok: %d kopiert, %d entfernt', $kit['kopiert'], $kit['entfernt']));
            break;

        case 'reconcile_payments':
            // Gebucht wird nichts (lib/payment.php). Eine gescheiterte
            // Zahlung zählt als Fehler: die Rechnung ist unbezahlt, die
            // Ware vielleicht schon unterwegs — das soll jemand sehen.
            $zahlungen = paymentsReconcile($db);
            $anzahl = array_count_values(array_column($zahlungen, 'result'));
            $auffaellig = [];
            foreach ($zahlungen as $zahlung) {
                $zeile = 'Rechnung '.$zahlung['invnumber'].': '.$zahlung['result'];
                if ('bezahlt' === $zahlung['result']) {
                    $zeile .= ' — Zahlungseingang von Hand buchen';
                } elseif ('offen' !== $zahlung['result']) {
                    $auffaellig[] = 'Rechnung '.$zahlung['invnumber'].' '.$zahlung['result'];
                    $zeile .= isset($zahlung['detail']) ? ' ('.$zahlung['detail'].')' : '';
                }
                if ('gescheitert' === $zahlung['result']) {
                    writeLog('[SHOP] PayPal-Zahlung gescheitert: Rechnung '.$zahlung['invnumber'], true, DLOG_ERR);
                }
                $sagen($zeile);
            }
            $stand = sprintf('%d geprüft, %d bezahlt, %d offen',
                count($zahlungen), $anzahl['bezahlt'] ?? 0, $anzahl['offen'] ?? 0);
            if ($auffaellig) {
                $fehler('Zahlungsabgleich: '.$stand.' — '.implode(', ', $auffaellig));
                shopJobResult($db, $id, 'Fehler: '.$stand.' — '.implode(', ', $auffaellig));
            } else {
                shopJobResult($db, $id, 'ok: '.$stand);
            }
            break;

        default:
            $fehler('Auftrag '.$id.': unbekannte Funktion '.$auftrag['function']);
            shopJobResult($db, $id, 'Fehler: unbekannte Funktion '.$auftrag['function']);
    }
}
