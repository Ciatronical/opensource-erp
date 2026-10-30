<?php
// backend/api/shop/lib/sitecheck.php
//
// Konfigurationsprüfung der Webseite aus Sicht des Shops (2026-10-30,
// dev/shop-veroeffentlichung.md „Konfigurationsprüfung“). Haken site_checks
// des Kundenportals (backend/api/portal/lib/sitecheck.php).
//
// Die Kategorienübersicht und die Kategorieseiten sind in Hugo Taxonomie-
// Seiten. Sie entstehen nur, wenn die Webseite dazu passt, was die Kanalkarte
// in die Produktseiten schreibt (shopChannelCategoryPaths): die Taxonomie muss
// angemeldet sein, ihre Seitenarten dürfen nicht abgeschaltet sein, und die
// Adressen aus permalinks müssen die der Kanalkarte sein — sonst zeigen
// Produktseiten und Übersicht ins Leere. Das Theme inter-data erkennt die
// Shop-Taxonomie an params.portalui.taxonomy; ohne den Parameter bleibt es bei
// der schlichten Liste, und die Widgets kennen die Übersicht nicht.

/**
 * Adressen, die Hugo einer Taxonomie gibt: Übersicht und Begriffsseite
 *
 * Aus permalinks der aufgelösten Konfiguration, in allen Formen, die Hugo
 * ausgibt: als Liste aus pattern und target (kind, path — neuere Hugo), als
 * Zuordnung nach Seitenart (taxonomy, term) oder flach (gilt für page und
 * term). Ohne Eintrag gelten Hugos Vorgaben /<taxonomie>/ und
 * /<taxonomie>/:slug/.
 *
 * @param mixed $permalinks permalinks aus shopsiteconfig (config.permalinks)
 * @param string $taxonomie Plural der Taxonomie, klein
 * @return array index (Adresse der Übersicht), term (Muster der Begriffsseite mit :slug)
 */
function shopSitePermalinks($permalinks, string $taxonomie): array {
    $index = '/'.$taxonomie.'/';
    $begriff = '/'.$taxonomie.'/:slug/';
    if (!is_array($permalinks)) {
        return ['index' => $index, 'term' => $begriff];
    }

    if (array_is_list($permalinks)) {
        foreach ($permalinks as $eintrag) {
            if (!is_array($eintrag)) {
                continue;
            }
            $ziel = (array)($eintrag['target'] ?? []);
            $pfad = (string)($ziel['path'] ?? '');
            // path ist ein Muster wie /{shopkategorien,shopkategorien/**}
            if (!preg_match('~^/\{?([^,/}]+)~', $pfad, $m) || strtolower($m[1]) !== $taxonomie) {
                continue;
            }
            $art = strtolower((string)($ziel['kind'] ?? ''));
            if ('taxonomy' === $art) {
                $index = (string)($eintrag['pattern'] ?? $index);
            } elseif ('term' === $art) {
                $begriff = (string)($eintrag['pattern'] ?? $begriff);
            }
        }
        return ['index' => $index, 'term' => $begriff];
    }

    $nachArt = array_change_key_case($permalinks);
    $uebersicht = is_array($nachArt['taxonomy'] ?? null) ? array_change_key_case($nachArt['taxonomy']) : [];
    $begriffe = is_array($nachArt['term'] ?? null) ? array_change_key_case($nachArt['term']) : [];
    if (is_string($uebersicht[$taxonomie] ?? null)) {
        $index = $uebersicht[$taxonomie];
    }
    if (is_string($begriffe[$taxonomie] ?? null)) {
        $begriff = $begriffe[$taxonomie];
    } elseif (is_string($nachArt[$taxonomie] ?? null)) {
        $begriff = $nachArt[$taxonomie];
    }
    return ['index' => $index, 'term' => $begriff];
}

/**
 * Adresse ohne Anker und Abfrage — ein Permalink hat beides nicht
 *
 * @param string $adresse Muster oder Adresse, etwa /kategorien/:slug/#focus
 * @return string
 */
function shopSitePathOnly(string $adresse): string {
    return (string)preg_replace('~[#?].*$~', '', $adresse);
}

/**
 * Adresse zum Vergleich: klein, ohne Schrägstriche an den Enden, :title wie :slug
 *
 * Hugo bildet :slug und :title eines Begriffs gleich (aus dem Namen), und
 * OpensourceERP setzt dort %s ein (shopHugoPath).
 *
 * @param string $adresse Muster oder Adresse
 * @return string
 */
function shopSitePathKey(string $adresse): string {
    return strtolower(trim(str_replace([':title', '%s'], ':slug', shopSitePathOnly($adresse)), '/'));
}

/**
 * Prüfungen des Shops an der Hugo-Konfiguration einer Webseite
 *
 * Haken site_checks: ohne HugoShop an dieser Webseite kommt nichts hinzu.
 *
 * @param array $befunde Befunde des Kerns
 * @param object $db Company-Datenbankverbindung
 * @param int $website Webseite
 * @param array $site Antwort von shopsiteconfig (data)
 * @param array $stand Antwort von shopbuildstatus (portalHugoCmsCall), falls vorhanden
 * @return array Befunde
 */
function shopSiteChecks(array $befunde, $db, int $website, array $site, array $stand = []): array {
    try {
        $kanal = shopChannelOfWebsite($db, $website);
    } catch (ApiError $e) {
        return $befunde;
    }
    if ($kanal <= 0) {
        return $befunde;
    }

    $konfiguration = (array)($site['config'] ?? []);
    $pfade = shopChannelCategoryPaths($db, $kanal);
    $taxonomie = strtolower($pfade['taxonomy']);

    // Abgeschaltete Seitenarten: ohne taxonomy und term baut Hugo weder
    // Übersicht noch Kategorieseiten — egal, was sonst eingestellt ist
    $arten = array_map('strtolower', array_map('strval', (array)($konfiguration['disableKinds'] ?? [])));
    $aus = array_values(array_intersect(['taxonomy', 'term'], $arten));
    if ($aus) {
        $befunde[] = portalSiteFinding('SHOP_KINDS_DISABLED', 'error', ['kinds' => implode(', ', $aus)],
            ['disableKinds' => array_values(array_diff($arten, ['taxonomy', 'term']))]);
    }

    // Taxonomie angemeldet? Ohne Eintrag kennt Hugo nur tags und categories;
    // der Schlüssel im Vorspann der Produktseiten bleibt dann ein Parameter
    $taxonomien = is_array($konfiguration['taxonomies'] ?? null) ? $konfiguration['taxonomies'] : [];
    $angemeldet = in_array($taxonomie, array_map('strtolower', array_map('strval', $taxonomien)), true);
    if (!$angemeldet) {
        // Ein taxonomies-Block ersetzt Hugos Vorgaben: die vorhandenen bleiben im Vorschlag
        $vorschlag = $taxonomien ?: ['category' => 'categories', 'tag' => 'tags'];
        $vorschlag[isset($vorschlag['shopcategory']) ? $taxonomie : 'shopcategory'] = $taxonomie;
        $befunde[] = portalSiteFinding('SHOP_TAXONOMY_MISSING', 'error', ['taxonomy' => $taxonomie], ['taxonomies' => $vorschlag]);
    }

    // Adressen: Kanalkarte (category_link) gegen Hugo (permalinks oder Vorgabe).
    // Das Muster der Kanalkarte darf einen Anker oder eine Abfrage tragen
    // (/kategorien/%s/#focus), ein Permalink nie — Vergleich und Vorschlag ohne
    $hugo = shopSitePermalinks($konfiguration['permalinks'] ?? null, $taxonomie);
    $sollIndex = shopSitePathOnly($pfade['index']);
    $sollBegriff = shopSitePathOnly(str_replace('%s', ':slug', $pfade['pattern']));
    if (shopSitePathKey($hugo['index']) !== shopSitePathKey($sollIndex)
        || shopSitePathKey($hugo['term']) !== shopSitePathKey($sollBegriff)) {
        $befunde[] = portalSiteFinding('SHOP_PERMALINKS', 'error', [
            'taxonomy' => $taxonomie,
            'expected' => $sollIndex.' und '.$sollBegriff,
            'actual'   => $hugo['index'].' und '.$hugo['term'],
        ], ['permalinks' => [
            'taxonomy' => [$taxonomie => $sollIndex],
            'term'     => [$taxonomie => $sollBegriff],
        ]]);
    }

    // Das Theme erkennt die Shop-Taxonomie an params.portalui.taxonomy; Hugo
    // schreibt die Schlüssel der Parameter klein
    $portalui = is_array($konfiguration['portalui'] ?? null) ? array_change_key_case($konfiguration['portalui']) : [];
    $ist = strtolower(trim((string)($portalui['taxonomy'] ?? '')));
    if ($ist !== $taxonomie) {
        $befunde[] = portalSiteFinding('SHOP_PORTALUI_TAXONOMY', 'warning', ['taxonomy' => $taxonomie, 'actual' => '' === $ist ? '—' : $ist],
            ['params' => ['portalui' => ['taxonomy' => $taxonomie]]]);
    }

    // Übersichtsseite content/<taxonomie>/_index.md: trägt den Titel der Übersicht
    $uebersichten = array_change_key_case((array)($site['files']['taxonomyIndex'] ?? []));
    if ($angemeldet && array_key_exists($taxonomie, $uebersichten) && !$uebersichten[$taxonomie]) {
        $befunde[] = portalSiteFinding('SHOP_TAXONOMY_INDEX', 'warning', ['file' => 'content/'.$taxonomie.'/_index.md'],
            "---\ntitle: \"".str_replace('"', '\\"', $pfade['title'])."\"\n---\n");
    }

    // Freigaben von HugoCMS müssen in Hugos Dateisystem eingehängt sein, sonst
    // sieht Hugo die Produktseiten nicht und die Vorlagen nicht die Übersicht
    $mounts = portalSiteMounts($site);
    $freigaben = (array)($site['grants'] ?? $stand['data']['grants'] ?? []);
    if ($mounts) {
        $produkte = is_string($freigaben['contentDir'] ?? null) ? trim($freigaben['contentDir'], '/') : '';
        if ('' !== $produkte && !portalSiteMounted($mounts, $produkte, 'content')) {
            $befunde[] = portalSiteFinding('SHOP_CONTENT_UNMOUNTED', 'error', ['dir' => $produkte]);
        }
        $uebersicht = is_string($freigaben['categoryGroups'] ?? null) ? trim($freigaben['categoryGroups'], '/') : '';
        if ('' !== $uebersicht && !portalSiteMounted($mounts, $uebersicht, 'data')) {
            $befunde[] = portalSiteFinding('SHOP_DATA_UNMOUNTED', 'error', ['file' => $uebersicht]);
        }
    }

    return $befunde;
}
