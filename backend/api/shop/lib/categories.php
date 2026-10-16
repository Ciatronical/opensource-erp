<?php
// backend/api/shop/lib/categories.php
//
// Kategorieübersicht der Webseite. Die Seite je Kategorie erzeugt Hugo selbst
// aus der Taxonomie im Front Matter der Produktseiten. Was Hugo nicht kann, ist
// die Übersicht nach Obergruppen; dafür schreibt der Läufer eine Datendatei,
// die das Theme liest — beim Satz standard data/category_groups.json.
//
// Zwei Wege zu den Gruppen (dev/shop-sortiment.md):
//
//   Sortiment   Gruppen, Zuordnung und Topseller aus category_group_shop und
//               category_shop, gepflegt in der Ansicht „Sortiment“. Gilt für
//               den Satz standard und jeden Satz ohne eigene
//               category_groups.php.
//   Regeln      Ein Satz mit eigener category_groups.php (sonic24) ermittelt
//               die Gruppen aus Wortteilen der Kategorienamen — portiert aus
//               sonic24.de/publish/category_groups.php. Die Kategorien selbst
//               kommen auch dann aus dem Sortiment.

/**
 * Regeln der Gruppierung eines Vorlagensatzes
 *
 * Nur ein Satz mit eigener category_groups.php gruppiert nach Regeln; seine
 * Angaben liegen über den Vorgaben unten. Ohne Datei gilt das Sortiment.
 *
 * @param string $satz Name des gewählten Satzes
 * @return array|null null = Gruppen aus dem Sortiment
 * @throws ApiError SHOP_TEMPLATE_SET_INVALID, SHOP_TEMPLATE_SET_MISSING
 */
function shopCategoryRules(string $satz): ?array {
    $datei = shopTemplateDir($satz).'/category_groups.php';
    if (!is_file($datei)) {
        return null;
    }
    $eigene = (static fn(string $datei) => require $datei)($datei);

    return array_merge([
        'taxonomy'               => 'kategorien',
        'labels'                 => [],
        'topseller'              => [],
        // Wörter ohne Aussage, die nie eine Gruppe bilden. Standen bis zum
        // Sortiment (16.10.2026) in standard/category_groups.php.
        'stop_words'             => [
            'und', 'oder', 'mit', 'fuer', 'von', 'zur', 'zum',
            'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einer', 'eines',
            'auf', 'aus', 'bei', 'bis', 'nach', 'vor', 'ueber', 'unter',
            'als', 'wie', 'nicht', 'nur', 'auch', 'noch', 'schon',
            'typ', 'art', 'set', 'pro',
        ],
        'min_cluster_size'       => 3,
        'fallback_min_frequency' => 5,
        'fallback_max_clusters'  => 15,
        'rest_name'              => SHOP_CATEGORY_REST_NAME,
    ], is_array($eigene) ? $eigene : []);
}

/** Restgruppe für Kategorien ohne Gruppe — Text der Webseite */
const SHOP_CATEGORY_REST_NAME = 'Sonstiges';

/**
 * Eintrag einer Kategorie in der Übersicht
 *
 * @param array $kategorie name, productCount
 * @param string $muster Adressmuster mit %s (shopChannelCategoryPaths)
 * @return array name, url, productCount
 */
function shopCategoryEntry(array $kategorie, string $muster): array {
    return [
        'name'         => shopUcfirst($kategorie['name']),
        'url'          => str_replace('%s', shopHugoPath($kategorie['name']), $muster),
        'productCount' => (int)$kategorie['productCount'],
    ];
}

/**
 * Eine Gruppe der Übersicht, Kategorien nach Namen sortiert
 *
 * @param string $name Name der Gruppe
 * @param array $liste Kategorien (name, productCount)
 * @param string $muster Adressmuster mit %s
 * @return array name, slug (Anker), count, categories
 */
function shopCategoryGroupEntry(string $name, array $liste, string $muster): array {
    usort($liste, fn($a, $b) => strcmp(shopCategoryFold($a['name']), shopCategoryFold($b['name'])));
    $anker = trim(preg_replace('/[^a-z0-9]+/', '-', str_replace('&', 'und', shopCategoryFold($name))), '-');
    return [
        'name'       => $name,
        'slug'       => $anker,
        'count'      => count($liste),
        'categories' => array_map(fn($kategorie) => shopCategoryEntry($kategorie, $muster), $liste),
    ];
}

/**
 * Übersicht aus dem Sortiment
 *
 * Gruppen in der Reihenfolge der Ansicht Sortiment (sortkey, dann Name),
 * Kategorien ohne Gruppe zuletzt in der Restgruppe. Keine Mindestgröße: eine
 * Gruppe hat jemand bewusst angelegt. Topseller nach Namen.
 *
 * @param array $kategorien aus shopCategoryCounts(): name, productCount,
 *                          group, group_sort, topseller
 * @param string $muster Adressmuster mit %s
 * @return array topseller, groups — im Aufbau, den die Themes lesen
 */
function shopCategoryGroupsFromAssortment(array $kategorien, string $muster): array {
    $gruppen = [];
    $rang = [];
    $rest = [];
    foreach ($kategorien as $kategorie) {
        $gruppe = trim((string)($kategorie['group'] ?? ''));
        if ('' === $gruppe) {
            $rest[] = $kategorie;
            continue;
        }
        $gruppen[$gruppe][] = $kategorie;
        $rang[$gruppe] = $kategorie['group_sort'] ?? null;
    }

    $namen = array_keys($gruppen);
    usort($namen, function ($a, $b) use ($rang) {
        $ra = $rang[$a];
        $rb = $rang[$b];
        if (null !== $ra && null !== $rb && $ra !== $rb) {
            return $ra <=> $rb;
        }
        if ((null === $ra) !== (null === $rb)) {
            return null === $ra ? 1 : -1;   // ohne Reihenfolge nach hinten
        }
        return strcmp(shopCategoryFold((string)$a), shopCategoryFold((string)$b));
    });

    $ergebnis = [];
    foreach ($namen as $name) {
        $ergebnis[] = shopCategoryGroupEntry((string)$name, $gruppen[$name], $muster);
    }
    if ($rest) {
        $ergebnis[] = shopCategoryGroupEntry(SHOP_CATEGORY_REST_NAME, $rest, $muster);
    }

    $topseller = array_values(array_filter($kategorien, fn($kategorie) => !empty($kategorie['topseller'])));
    usort($topseller, fn($a, $b) => strcmp(shopCategoryFold($a['name']), shopCategoryFold($b['name'])));

    return [
        'topseller' => array_map(fn($kategorie) => shopCategoryEntry($kategorie, $muster), $topseller),
        'groups'    => $ergebnis,
    ];
}

/**
 * Adresse, unter der Hugo einen Begriff der Taxonomie ablegt
 *
 * Nachbau von PathSpec.UnicodeSanitize samt Kleinschreibung, wie Hugo 0.125
 * ihn ausführt: Buchstaben, Ziffern und einige Zeichen bleiben, jedes
 * Leerzeichen wird zu einem Bindestrich, alles andere fällt weg.
 * "Handlampen, Leuchten" → "handlampen-leuchten",
 * "Stecknuss- und Biteinsatz" → "stecknuss--und-biteinsatz".
 *
 * Die Regel hängt an der Hugo-Fassung: 0.121 fasste Leerraum und Bindestriche
 * noch zu einem Bindestrich zusammen. Geprüft mit 0.121.1 und 0.125.1 und
 * gegen die 92 Kategorien von sonic24 (0.125.1) — beim Wechsel der Fassung
 * erneut prüfen.
 *
 * @param string $text Begriff
 * @return string
 */
function shopHugoPath(string $text): string {
    $zeichen = mb_str_split($text);
    $pfad = '';

    foreach ($zeichen as $i => $z) {
        if (1 === preg_match('/^[\p{L}\p{Nd}\p{M}._~#+@\/\\\\-]$/u', $z)
            || ('%' === $z && isset($zeichen[$i + 2]) && ctype_xdigit($zeichen[$i + 1].$zeichen[$i + 2]))) {
            $pfad .= $z;
        } elseif (1 === preg_match('/^\s$/u', $z)) {
            $pfad .= '-';
        }
    }

    return mb_strtolower($pfad, 'UTF-8');
}

/** Klein geschrieben, Umlaute aufgelöst — für Wortvergleich und Sortierung */
function shopCategoryFold(string $text): string {
    return str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], mb_strtolower($text, 'UTF-8'));
}

/** Erster Buchstabe groß, auch bei Umlauten */
function shopUcfirst(string $text): string {
    return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8').mb_substr($text, 1, null, 'UTF-8');
}

/**
 * Wörter eines Kategorienamens: aufgelöst, ohne Stoppwörter, jedes einmal
 *
 * @param string $name Kategorie
 * @param array $stoppwoerter aus den Regeln
 * @return array
 */
function shopCategoryWords(string $name, array $stoppwoerter): array {
    $text = preg_replace('/[^a-z0-9\s]/', ' ', shopCategoryFold($name));
    $woerter = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    $woerter = array_filter($woerter, fn($wort) => strlen($wort) > 1 && !in_array($wort, $stoppwoerter, true));
    return array_values(array_unique($woerter));
}

/**
 * Bester Wortteil in einem Wort: der früheste, bei gleicher Stelle der längste
 *
 * Bei zusammengesetzten Wörtern trägt das erste Glied die Bedeutung:
 * "heizkoerperventile" → "heiz" (Stelle 0) vor "ventil" (Stelle 12).
 *
 * @param string $wort Wort aus shopCategoryWords()
 * @param array $wortteile Schlüssel der labels
 * @return array|null Wortteil, Stelle, Länge
 */
function shopCategoryMatch(string $wort, array $wortteile): ?array {
    $bester = null;
    foreach ($wortteile as $teil) {
        $stelle = '' === $teil ? false : strpos($wort, $teil);
        if (false === $stelle) {
            continue;
        }
        if (null === $bester || $stelle < $bester[1] || ($stelle === $bester[1] && strlen($teil) > $bester[2])) {
            $bester = [$teil, $stelle, strlen($teil)];
        }
    }
    return $bester;
}

/**
 * Gruppiert Kategorien nach den Regeln
 *
 * 1. Wortteile aus labels. Über mehrere Wörter gewinnt der längste Treffer,
 *    bei gleicher Länge der frühere: "Elektro Heizstab" → "elektr" vor "heiz".
 * 2. Der Rest nach Häufigkeit: ein Wort, das in genug Kategorien vorkommt,
 *    wird zur Gruppe; je Kategorie zählt das seltenste davon.
 * 3. Zu kleine Gruppen wandern in die Restgruppe. Die Gruppen stehen nach Zahl
 *    der Kategorien absteigend, die Restgruppe zuletzt.
 *
 * @param array $kategorien Liste aus name und productCount
 * @param array $regeln aus shopCategoryRules()
 * @return array topseller, groups — im Aufbau, den das Theme hugoshop liest
 */
function shopCategoryGroups(array $kategorien, array $regeln): array {
    $labels = $regeln['labels'];
    $stopp = $regeln['stop_words'];
    $wortteile = array_map('strval', array_keys($labels));

    $gruppen = [];
    $rest = [];
    foreach ($kategorien as $kategorie) {
        $treffer = null;
        foreach (shopCategoryWords($kategorie['name'], $stopp) as $wort) {
            $kandidat = shopCategoryMatch((string)$wort, $wortteile);
            if (null !== $kandidat && (null === $treffer || $kandidat[2] > $treffer[2]
                    || ($kandidat[2] === $treffer[2] && $kandidat[1] < $treffer[1]))) {
                $treffer = $kandidat;
            }
        }
        if (null === $treffer) {
            $rest[] = $kategorie;
        } else {
            $gruppen[(string)$labels[$treffer[0]]][] = $kategorie;
        }
    }

    // Häufige Wörter im Rest. Ein Wort mit einem der Wortteile kann hier nicht
    // mehr vorkommen — seine Kategorie hätte sonst schon eine Gruppe.
    $haeufigkeit = [];
    foreach ($rest as $kategorie) {
        foreach (shopCategoryWords($kategorie['name'], $stopp) as $wort) {
            $haeufigkeit[$wort] = ($haeufigkeit[$wort] ?? 0) + 1;
        }
    }
    arsort($haeufigkeit);

    $kerne = [];
    foreach ($haeufigkeit as $wort => $anzahl) {
        if ($anzahl < $regeln['fallback_min_frequency'] || count($kerne) >= $regeln['fallback_max_clusters']) {
            break;
        }
        $kerne[(string)$wort] = $anzahl;
    }

    $uebrig = [];
    foreach ($rest as $kategorie) {
        $seltenstes = null;
        foreach (shopCategoryWords($kategorie['name'], $stopp) as $wort) {
            $wort = (string)$wort;
            if (isset($kerne[$wort]) && (null === $seltenstes || $kerne[$wort] < $kerne[$seltenstes])) {
                $seltenstes = $wort;
            }
        }
        if (null === $seltenstes) {
            $uebrig[] = $kategorie;
        } else {
            $gruppen[shopUcfirst($seltenstes)][] = $kategorie;
        }
    }

    // Adresse der Kategorieseite: das Muster der Kanalkarte (category_link,
    // von shopWriteCategoryGroups eingesetzt); ohne Kanal der Taxonomiename
    // der Regeln an der Wurzel
    $muster = (string)($regeln['category_link'] ?? '');
    if (!str_contains($muster, '%s')) {
        $muster = '/'.trim((string)$regeln['taxonomy'], '/').'/%s/';
    }

    $ergebnis = [];
    foreach ($gruppen as $name => $liste) {
        if (count($liste) < $regeln['min_cluster_size']) {
            $uebrig = array_merge($uebrig, $liste);
            continue;
        }
        $ergebnis[] = shopCategoryGroupEntry((string)$name, $liste, $muster);
    }
    usort($ergebnis, fn($a, $b) => $b['count'] <=> $a['count']);
    if ($uebrig) {
        $ergebnis[] = shopCategoryGroupEntry((string)$regeln['rest_name'], $uebrig, $muster);
    }

    $topseller = [];
    foreach ($regeln['topseller'] as $gesucht) {
        foreach ($kategorien as $kategorie) {
            if (mb_strtolower($kategorie['name'], 'UTF-8') === mb_strtolower((string)$gesucht, 'UTF-8')) {
                $topseller[] = shopCategoryEntry($kategorie, $muster);
                break;
            }
        }
    }

    return ['topseller' => $topseller, 'groups' => $ergebnis];
}

/**
 * Wo der Vorlagensatz eines HugoShops die Kategorieübersicht erwartet
 *
 * Steht in seiner theme.json unter data.category_groups; seine Vorlagen lesen
 * sie dort (Hugo: site.Data). Nennt er nichts, sieht er keine Übersicht vor.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @param string|null $satz Vorlagensatz statt des eingestellten (Verbindungstest
 *                          mit noch nicht gespeicherter Auswahl)
 * @return string Pfad relativ zur Webseite, leer = keine Übersicht
 * @throws ApiError SHOP_TEMPLATE_SET_INVALID, SHOP_TEMPLATE_SET_MISSING
 */
function shopCategoryGroupsTemplatePath($db, int $kanal, ?string $satz = null): string {
    $satz = $satz ?? shopChannelValue($db, $kanal, 'template_set', 'standard');
    return trim((string)(shopTemplateInfo(shopTemplateDir($satz))['data']['category_groups'] ?? ''), '/');
}

/**
 * Zieldatei der Kategorieübersicht
 *
 * Ob es eine gibt, sagt der Vorlagensatz (shopCategoryGroupsTemplatePath);
 * sieht er keine vor, liefert die Funktion einen leeren Pfad zurück. Wohin sie
 * gehört, legt HugoCMS fest (Freigabe „Kategorieübersicht“,
 * dev/shop-hugocms-verzeichnisse.md). Erwartet der Vorlagensatz sie woanders,
 * meldet das der Lauf (shopPublishSite) und der Verbindungstest der Kanalkarte.
 *
 * Herausgelöst, damit ein Lauf nachsehen kann, ob die Datei fehlt, ohne sie
 * gleich zu schreiben.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop — sein Vorlagensatz, seine Webseite
 * @param bool $anlegen Fehlende Verzeichnisse anlegen
 * @return string Pfad, leer wenn der Vorlagensatz keine Übersicht vorsieht
 * @throws ApiError SHOP_HUGOCMS_GRANTS, SHOP_PATH_INVALID, SHOP_PATH_MISSING
 */
function shopCategoryGroupsFile($db, int $kanal, bool $anlegen = false): string {
    if ('' === shopCategoryGroupsTemplatePath($db, $kanal)) {
        return '';
    }
    $relativ = shopHugoCmsGrantsRequired($db, $kanal)['categoryGroups'];

    $unterordner = dirname($relativ);

    return shopPathUnder(shopStagingDir($db, $kanal), '.' === $unterordner ? '' : $unterordner, $anlegen)
         .'/'.basename($relativ);
}

/**
 * Kategorien der Artikel im Shop mit Zahl der Artikel
 *
 * Dieselbe Auswahl wie shopListedParts(): was eine Produktseite hat, zählt.
 * Eine Kategorie ohne angebotenen Artikel fehlt — Hugo legt für sie keine
 * Seite an, ein Eintrag in der Übersicht führte ins Leere. Namen, die Hugo
 * auf dieselbe Adresse legt, werden zusammengezählt.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return array Liste aus name, productCount und aus dem Sortiment group,
 *               group_sort, topseller
 */
function shopCategoryCounts($db, int $kanal): array {
    $zeilen = $db->getAll(
        "SELECT btrim(c.name) AS name, count(*) AS product_count,
                g.name AS group_name, g.sortkey AS group_sort, c.topseller
           FROM parts_ext pe
           JOIN category_shop c ON c.id = pe.hugoshop_category_id
           LEFT JOIN category_group_shop g ON g.id = c.group_id
           JOIN parts_channel_shop pc ON pc.parts_id = pe.parts_id
                                     AND pc.channel_id = shop_active_channel_id(CAST(:kanal AS integer))
                                     AND pc.active
          GROUP BY c.id, g.id
          ORDER BY btrim(c.name)",
        [':kanal' => $kanal]
    );

    $wahr = fn($wert) => true === $wert || 't' === $wert || '1' === $wert || 1 === $wert;
    $kategorien = [];
    foreach ($zeilen as $zeile) {
        $pfad = shopHugoPath((string)$zeile['name']);
        if ('' === $pfad) {
            continue;
        }
        if (isset($kategorien[$pfad])) {
            $kategorien[$pfad]['productCount'] += (int)$zeile['product_count'];
        } else {
            $kategorien[$pfad] = [
                'name'         => (string)$zeile['name'],
                'productCount' => (int)$zeile['product_count'],
                'group'        => (string)($zeile['group_name'] ?? ''),
                'group_sort'   => null === $zeile['group_sort'] ? null : (int)$zeile['group_sort'],
                'topseller'    => $wahr($zeile['topseller']),
            ];
        }
    }

    return array_values($kategorien);
}

/**
 * Schreibt die Kategorieübersicht in die Webseite
 *
 * Nur wenn der Vorlagensatz in theme.json unter data.category_groups eine
 * Datei vorsieht — an den Ort, den HugoCMS freigibt (shopCategoryGroupsFile) —,
 * und nur wenn sich ihr Inhalt ändert: jede Änderung löst einen Bau aus. Ohne Kategorien wird die Datei entfernt: das Theme zeigt dann die
 * alphabetische Liste aus der Taxonomie statt einer leeren Übersicht.
 *
 * @param object $db Company-Datenbankverbindung
 * @param int $kanal HugoShop
 * @return array file (leer, wenn der Satz keine vorsieht), categories, groups, changed
 * @throws ApiError SHOP_PATH_INVALID, SHOP_WRITE_FAILED
 */
function shopWriteCategoryGroups($db, int $kanal): array {
    $bilanz = ['file' => '', 'categories' => 0, 'groups' => 0, 'changed' => false];

    $datei = shopCategoryGroupsFile($db, $kanal, true);
    if ('' === $datei) {
        return $bilanz;
    }
    $bilanz['file'] = $datei;

    // Derselbe Satz, aus dem shopCategoryGroupsFile() den Pfad nimmt: hat er
    // eigene Regeln, bestimmen sie die Gruppen, sonst das Sortiment.
    $satz = shopChannelValue($db, $kanal, 'template_set', 'standard');

    $kategorien = shopCategoryCounts($db, $kanal);
    if (!$kategorien) {
        if (is_file($datei)) {
            unlink($datei);
            $bilanz['changed'] = true;
        }
        return $bilanz;
    }

    // Die Adressen der Kategorieseiten folgen der Kanalkarte (category_link),
    // nicht dem Satz: sie gehören zur Webseite (shopChannelCategoryPaths)
    $muster = shopChannelCategoryPaths($db, $kanal)['pattern'];
    $regeln = shopCategoryRules($satz);
    if (null === $regeln) {
        $uebersicht = shopCategoryGroupsFromAssortment($kategorien, $muster);
    } else {
        $regeln['category_link'] = $muster;
        $uebersicht = shopCategoryGroups($kategorien, $regeln);
    }
    $bilanz['categories'] = count($kategorien);
    $bilanz['groups'] = count($uebersicht['groups']);

    $inhalt = json_encode($uebersicht, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    if (!is_file($datei) || file_get_contents($datei) !== $inhalt) {
        if (false === file_put_contents($datei, $inhalt, LOCK_EX)) {
            throw new ApiError('SHOP_WRITE_FAILED', 'Datei nicht schreibbar: '.$datei);
        }
        $bilanz['changed'] = true;
    }

    return $bilanz;
}
