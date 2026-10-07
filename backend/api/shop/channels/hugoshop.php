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
//
// Mehrere HugoShops (dev/shop-mehrere-kanaele.md): jeder Auftrag trägt seinen
// Kanal (channel_id) und wirkt nur auf dessen Webseite.

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
 * @param int $kanal HugoShop
 * @return bool
 */
function shopChannelHugoshopActive($db, int $kanal): bool {
    $zeile = $db->getOne(
        "SELECT shop_active_channel_id(CAST(:kanal AS integer)) IS NOT NULL AS an",
        [':kanal' => $kanal]
    );
    return in_array($zeile['an'] ?? false, [true, 't', 1, '1'], true);
}

/**
 * Dateinamen der Seiten aller Artikel, die im HugoShop angeboten werden
 *
 * Unabhängig davon, ob der Kanal eingeschaltet ist: remove_all läuft, wenn er
 * gerade abgeschaltet wurde.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return array Dateinamen ohne Pfad
 */
function shopChannelHugoshopPages($db, int $kanal): array {
    $zeilen = $db->getAll(
        "SELECT p.partnumber, COALESCE(pe.hugoshop_hyperlink, '') AS hyperlink
           FROM parts_channel_shop pc
           JOIN parts p ON p.id = pc.parts_id
           LEFT JOIN parts_ext pe ON pe.parts_id = p.id
          WHERE pc.channel_id = CAST(:kanal AS integer)
            AND pc.active
          ORDER BY p.id",
        [':kanal' => $kanal]
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
 * Aus: je nach channel_off_pages des Kanals die Seiten als Entwurf stehen lassen
 * (draft_all, Vorgabe) oder entfernen (remove_all). An: alle Seiten neu
 * schreiben. Ein noch offener Auftrag der Gegenrichtung wird vorher gelöscht —
 * sonst hinterließe aus-an-aus die Seiten veröffentlicht, weil der zweite
 * Auftrag als Doppel des ersten nicht angelegt würde.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @param bool $an eingeschaltet
 * @return void
 */
function shopChannelHugoshopSwitched($db, int $kanal, bool $an): void {
    $db->execute(
        "DELETE FROM batchjob_hugoshop
          WHERE result IS NULL
            AND channel_id = CAST(:kanal AS integer)
            AND function = ANY(string_to_array(:gegenrichtung, ','))",
        [':kanal' => $kanal,
         ':gegenrichtung' => $an ? 'remove_all,draft_all' : 'publish_all,publish_part,remove_all,draft_all']
    );

    if ($an) {
        shopQueueJob($db, 'publish_all', '', null, $kanal);
        return;
    }
    shopQueueJob($db, 'remove' === shopChannelValue($db, $kanal, 'channel_off_pages', 'draft') ? 'remove_all' : 'draft_all',
                 '', null, $kanal);
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
 * @param array $bilanz Zähler des Laufs (seiten, entfernt, kit — je Webseite über shopSiteTally)
 * @return void
 */
function shopChannelHugoshopRunJob($db, array $auftrag, callable $sagen, callable $fehler, array &$bilanz): void {
    $id = (int)$auftrag['id'];
    $kanal = (int)$auftrag['channel_id'];

    switch ($auftrag['function']) {
        case 'publish_part':
            // Abgeschaltet schreibt der HugoShop keine Seiten (V16): ein
            // Auftrag von vor dem Abschalten brächte sonst eine Seite zurück,
            // die remove_all gerade entfernt hat.
            if (!shopChannelHugoshopActive($db, $kanal)) {
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
            try {
                $ergebnis = shopWriteProductPage($db, $kanal, (int)$artikel['id']);
            } catch (ApiError $e) {
                // Ohne passende Versandart ist die Seite zum Entwurf geworden —
                // gebaut werden muss trotzdem, sonst bliebe sie online
                if ('SHIPPING_UNFIT_DRAFT' === $e->getId()) {
                    shopSiteTally($bilanz, $kanal, 'seiten');
                }
                // Die Artikelnummer gehört in die Meldung: im Lauf steht sonst
                // nur die Nummer des Auftrags
                throw new ApiError($e->getId(), 'Artikel '.$auftrag['partnumber'].': '.$e->getMessage());
            }
            shopSiteTally($bilanz, $kanal, 'seiten');
            $sagen('Seite geschrieben: '.basename($ergebnis['file']).' (Vorschaubild: '.$ergebnis['thumbnail'].')');
            shopJobResult($db, $id, 'ok: '.basename($ergebnis['file']));
            break;

        case 'publish_all':
            $anzahl = 0;
            $gescheitert = 0;
            $entwuerfe = 0;
            $ersterFehler = '';
            foreach (shopListedParts($db, $kanal) as $artikel) {
                try {
                    shopWriteProductPage($db, $kanal, (int)$artikel['id']);
                    $anzahl++;
                } catch (ApiError $e) {
                    $gescheitert++;
                    // Zum Entwurf gewordene Seite: der Bau muss sie entfernen
                    if ('SHIPPING_UNFIT_DRAFT' === $e->getId()) {
                        $entwuerfe++;
                    }
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
            shopSiteTally($bilanz, $kanal, 'seiten', $anzahl + $entwuerfe);
            // „Alle Produkte" gleicht jede Seite mit HugoCMS ab und baut die
            // Webseite immer neu — auch wenn sich seit dem letzten Lauf nichts
            // geändert hat. Dort gelöschte oder veränderte Seiten kommen so
            // zurück, und der Bau samt Ausgabe steht in jedem Fall im Lauf.
            shopSiteTally($bilanz, $kanal, 'bauen');
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
            $weg = shopRemovePage($db, $kanal, (string)($auftrag['param'] ?? ''));
            if ($weg) {
                shopSiteTally($bilanz, $kanal, 'entfernt');
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
            foreach (shopChannelHugoshopPages($db, $kanal) as $datei) {
                if (shopRemovePage($db, $kanal, $datei)) {
                    $entfernt++;
                }
            }
            shopSiteTally($bilanz, $kanal, 'entfernt', $entfernt);
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
                  WHERE pc.channel_id = CAST(:kanal AS integer) AND pc.active
                  ORDER BY p.id",
                [':kanal' => $kanal]
            ) as $artikel) {
                try {
                    shopWriteProductPage($db, $kanal, (int)$artikel['id'], true);
                    $anzahl++;
                } catch (ApiError $e) {
                    $gescheitert++;
                    if ($gescheitert <= SHOP_MELDUNGEN_JE_AUFTRAG) {
                        $fehler('Artikel '.$artikel['partnumber'].': '.$e->getMessage());
                    }
                }
            }
            shopSiteTally($bilanz, $kanal, 'seiten', $anzahl);
            $stand = sprintf('%d Seiten als Entwurf, %d fehlgeschlagen', $anzahl, $gescheitert);
            $sagen($stand);
            shopJobResult($db, $id, ($gescheitert > 0 ? 'Fehler: ' : 'ok: ').$stand);
            break;

        case 'sync_kit':
            $kit = shopSyncKit($db, $kanal);
            shopSiteTally($bilanz, $kanal, 'kit', shopKitChanges($kit));
            $sagen(sprintf('Paket abgeglichen: %d kopiert, %d entfernt%s',
                $kit['kopiert'], $kit['entfernt'], $kit['config'] ? ', Konfiguration neu' : ''));

            // „Shop-Benutzerschnittstelle installieren“ (installShopUi): die
            // Webseite wird auch gebaut, wenn das Paket schon aktuell war —
            // etwa weil ein früherer Bau fehlschlug oder die Mounts erst
            // danach eingetragen wurden.
            if (SHOP_KIT_INSTALL === ($auftrag['param'] ?? null)) {
                shopSiteTally($bilanz, $kanal, 'bauen');
                foreach (shopKitSetupHints($db, $kanal) as $hinweis) {
                    $sagen('Hinweis: '.$hinweis);
                }
            }
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
