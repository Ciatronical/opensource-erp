<?php
// backend/api/shop/lib/categories.php
//
// Kategorieübersicht der Webseite. Die Seite je Kategorie erzeugt Hugo selbst
// aus der Taxonomie im Front Matter der Produktseiten. Was Hugo nicht kann, ist
// die Übersicht nach Obergruppen; dafür schreibt der Läufer eine Datendatei,
// die das Theme liest — beim Satz standard data/category_groups.json für das
// Theme hugoshop.
//
// Portiert aus sonic24.de/publish/category_groups.php. Der Algorithmus steht
// hier; die Regeln (Wortteile, Topseller, Stoppwörter) sind Inhalt des Shops
// und stehen im Vorlagensatz, in category_groups.php.

/**
 * Regeln der Gruppierung
 *
 * In Schichten wie das Webseiten-Paket: Vorgaben, darüber category_groups.php
 * des mitgelieferten Satzes standard, darüber die des gewählten Satzes. Ein
 * eigener Satz nennt so nur, was er ändert.
 *
 * @param string $satz Name des gewählten Satzes
 * @return array
 * @throws ApiError SHOP_TEMPLATE_SET_INVALID, SHOP_TEMPLATE_SET_MISSING
 */
function shopCategoryRules(string $satz): array {
    $regeln = [
        'taxonomy'               => 'kategorien',
        'labels'                 => [],
        'topseller'              => [],
        'stop_words'             => [],
        'min_cluster_size'       => 3,
        'fallback_min_frequency' => 5,
        'fallback_max_clusters'  => 15,
        'rest_name'              => 'Sonstiges',
    ];

    foreach (array_unique([shopTemplateMasterDir().'/standard', shopTemplateDir($satz)]) as $verzeichnis) {
        $datei = $verzeichnis.'/category_groups.php';
        if (is_file($datei)) {
            $eigene = (static fn(string $datei) => require $datei)($datei);
            if (is_array($eigene)) {
                $regeln = array_merge($regeln, $eigene);
            }
        }
    }

    return $regeln;
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

    $taxonomie = trim((string)$regeln['taxonomy'], '/');
    $eintrag = fn(array $kategorie) => [
        'name'         => shopUcfirst($kategorie['name']),
        'url'          => '/'.$taxonomie.'/'.shopHugoPath($kategorie['name']).'/',
        'productCount' => (int)$kategorie['productCount'],
    ];
    $gruppe = function (string $name, array $liste) use ($eintrag) {
        usort($liste, fn($a, $b) => strcmp(shopCategoryFold($a['name']), shopCategoryFold($b['name'])));
        $anker = trim(preg_replace('/[^a-z0-9]+/', '-', str_replace('&', 'und', shopCategoryFold($name))), '-');
        return ['name' => $name, 'slug' => $anker, 'count' => count($liste), 'categories' => array_map($eintrag, $liste)];
    };

    $ergebnis = [];
    foreach ($gruppen as $name => $liste) {
        if (count($liste) < $regeln['min_cluster_size']) {
            $uebrig = array_merge($uebrig, $liste);
            continue;
        }
        $ergebnis[] = $gruppe((string)$name, $liste);
    }
    usort($ergebnis, fn($a, $b) => $b['count'] <=> $a['count']);
    if ($uebrig) {
        $ergebnis[] = $gruppe((string)$regeln['rest_name'], $uebrig);
    }

    $topseller = [];
    foreach ($regeln['topseller'] as $gesucht) {
        foreach ($kategorien as $kategorie) {
            if (mb_strtolower($kategorie['name'], 'UTF-8') === mb_strtolower((string)$gesucht, 'UTF-8')) {
                $topseller[] = $eintrag($kategorie);
                break;
            }
        }
    }

    return ['topseller' => $topseller, 'groups' => $ergebnis];
}

/**
 * Kategorien der Artikel im Shop mit Zahl der Artikel
 *
 * Dieselbe Auswahl wie shopListedParts(): was eine Produktseite hat, zählt.
 * Schreibweisen, die Hugo auf dieselbe Adresse legt, werden zusammengezählt —
 * sonst stünde dieselbe Kategorie zweimal in der Übersicht.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array Liste aus name und productCount
 */
function shopCategoryCounts($db): array {
    $zeilen = $db->getAll(
        "SELECT btrim(hugoshop_category) AS name, count(*) AS product_count
           FROM parts_ext
          WHERE btrim(COALESCE(hugoshop_category, '')) <> ''
          GROUP BY btrim(hugoshop_category)
          ORDER BY btrim(hugoshop_category)"
    );

    $kategorien = [];
    foreach ($zeilen as $zeile) {
        $pfad = shopHugoPath((string)$zeile['name']);
        if ('' === $pfad) {
            continue;
        }
        if (isset($kategorien[$pfad])) {
            $kategorien[$pfad]['productCount'] += (int)$zeile['product_count'];
        } else {
            $kategorien[$pfad] = ['name' => (string)$zeile['name'], 'productCount' => (int)$zeile['product_count']];
        }
    }

    return array_values($kategorien);
}

/**
 * Schreibt die Kategorieübersicht in die Webseite
 *
 * Nur wenn der Vorlagensatz in theme.json unter data.category_groups eine
 * Datei nennt, und nur wenn sich ihr Inhalt ändert — jede Änderung löst einen
 * Bau aus. Ohne Kategorien wird die Datei entfernt: das Theme zeigt dann die
 * alphabetische Liste aus der Taxonomie statt einer leeren Übersicht.
 *
 * @param object $db Company-Datenbankverbindung
 * @return array file (leer, wenn der Satz keine vorsieht), categories, groups, changed
 * @throws ApiError SHOP_PATH_INVALID, SHOP_WRITE_FAILED
 */
function shopWriteCategoryGroups($db): array {
    $bilanz = ['file' => '', 'categories' => 0, 'groups' => 0, 'changed' => false];

    $satz = shopConfigValue($db, 'shop_template_set', 'standard');
    $relativ = trim((string)(shopTemplateInfo(shopTemplateDir($satz))['data']['category_groups'] ?? ''), '/');
    if ('' === $relativ) {
        return $bilanz;
    }
    if (str_starts_with(basename($relativ), '.')) {
        throw new ApiError('SHOP_PATH_INVALID', 'Unbrauchbarer Dateiname für die Kategorieübersicht: '.$relativ);
    }

    $unterordner = dirname($relativ);
    $datei = shopPathUnder(shopSiteDir($db, true), '.' === $unterordner ? '' : $unterordner, true).'/'.basename($relativ);
    $bilanz['file'] = $datei;

    $kategorien = shopCategoryCounts($db);
    if (!$kategorien) {
        if (is_file($datei)) {
            unlink($datei);
            $bilanz['changed'] = true;
        }
        return $bilanz;
    }

    $uebersicht = shopCategoryGroups($kategorien, shopCategoryRules($satz));
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
