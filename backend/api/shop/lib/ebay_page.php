<?php
// backend/api/shop/lib/ebay_page.php
//
// Produktseiten für eBay (dev/shop-ebay.md, Teil Seiten). Der Aufbau einer
// Seite steht in parts_channel_shop.settings.page, gerendert wird hier — eine
// Stelle für Vorschau, Speichern und KI-Entwurf:
//
//   template       klar, modern oder kompakt
//   color          Akzentfarbe (#rrggbb), Vorgabe aus dem Kanal
//   headline       Überschrift auf der Seite (sonst der eBay-Titel)
//   subtitle       eine Zeile darunter
//   highlights     3–6 Stichpunkte: das Wichtigste auf einen Blick
//   description    Fließtext; Leerzeile = neuer Absatz, „- “ = Aufzählung
//   specs          [{name, value}] technische Daten als Tabelle
//   aspects        [{name, value}] Artikelmerkmale bei eBay (Marke, Herstellernummer …)
//   compatibility  Passend für (Fahrzeuge, Geräte)
//   scope          Lieferumfang
//   notes          Hinweise
//   show_images    Bilder des Kanals zusätzlich in der Beschreibung
//
// eBay erlaubt keine Skripte und keine aktiven Inhalte; die eBay-App nimmt
// <style>-Blöcke heraus. Deshalb nur Inline-Stile, eine Spalte, Tabellen mit
// 100 % Breite — so bleibt die Seite auf dem Telefon lesbar. Alle Texte
// werden maskiert; HTML aus der KI kommt nie ungefiltert auf die Seite.

const SHOP_EBAY_PAGE_TEMPLATES = ['klar', 'modern', 'kompakt'];

/** Überschriften der Abschnitte in der Sprache des Marktplatzes (content_language des Kanals) */
const SHOP_EBAY_PAGE_LABELS = [
    'de' => ['description' => 'Beschreibung', 'specs' => 'Technische Daten', 'compatibility' => 'Passend für',
             'scope' => 'Lieferumfang', 'notes' => 'Hinweise', 'shipping' => 'Versand', 'returns' => 'Rückgabe', 'about' => 'Über uns'],
    'en' => ['description' => 'Description', 'specs' => 'Specifications', 'compatibility' => 'Compatibility',
             'scope' => 'In the box', 'notes' => 'Notes', 'shipping' => 'Shipping', 'returns' => 'Returns', 'about' => 'About us'],
    'fr' => ['description' => 'Description', 'specs' => 'Caractéristiques', 'compatibility' => 'Compatible avec',
             'scope' => 'Contenu', 'notes' => 'Remarques', 'shipping' => 'Livraison', 'returns' => 'Retours', 'about' => 'À propos'],
    'it' => ['description' => 'Descrizione', 'specs' => 'Dati tecnici', 'compatibility' => 'Compatibile con',
             'scope' => 'Contenuto', 'notes' => 'Note', 'shipping' => 'Spedizione', 'returns' => 'Resi', 'about' => 'Chi siamo'],
    'es' => ['description' => 'Descripción', 'specs' => 'Datos técnicos', 'compatibility' => 'Compatible con',
             'scope' => 'Contenido', 'notes' => 'Notas', 'shipping' => 'Envío', 'returns' => 'Devoluciones', 'about' => 'Sobre nosotros'],
    'nl' => ['description' => 'Beschrijving', 'specs' => 'Technische gegevens', 'compatibility' => 'Geschikt voor',
             'scope' => 'Inhoud', 'notes' => 'Opmerkingen', 'shipping' => 'Verzending', 'returns' => 'Retourneren', 'about' => 'Over ons'],
    'pl' => ['description' => 'Opis', 'specs' => 'Dane techniczne', 'compatibility' => 'Pasuje do',
             'scope' => 'Zawartość', 'notes' => 'Uwagi', 'shipping' => 'Wysyłka', 'returns' => 'Zwroty', 'about' => 'O nas'],
];

/**
 * Maskierter Text
 *
 * @param mixed $text Text
 * @return string
 */
function shopEbayPageEsc($text): string {
    return htmlspecialchars(trim((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Farbe aus der Eingabe, sonst die Vorgabe
 *
 * @param string $farbe #rrggbb
 * @return string
 */
function shopEbayPageColor(string $farbe): string {
    return preg_match('/^#[0-9a-fA-F]{6}$/', trim($farbe)) ? strtolower(trim($farbe)) : '#1f6feb';
}

/**
 * Fließtext in Absätze und Aufzählungen
 *
 * @param string $text Text mit Leerzeilen und „- “-Zeilen
 * @param string $absatzStil Stil der Absätze
 * @param string $farbe Akzentfarbe für die Aufzählungspunkte
 * @return string HTML
 */
function shopEbayPageText(string $text, string $absatzStil, string $farbe): string {
    $html = '';
    foreach (preg_split('/\R{2,}/', trim($text)) as $block) {
        $zeilen = array_values(array_filter(array_map('trim', preg_split('/\R/', $block)), fn($z) => '' !== $z));
        if (!$zeilen) {
            continue;
        }
        $liste = count(array_filter($zeilen, fn($z) => preg_match('/^[-•*]\s+/', $z))) === count($zeilen);
        if ($liste) {
            $html .= shopEbayPageList(array_map(fn($z) => preg_replace('/^[-•*]\s+/', '', $z), $zeilen), $farbe);
        } else {
            $html .= '<p style="'.$absatzStil.'">'.implode('<br>', array_map('shopEbayPageEsc', $zeilen)).'</p>';
        }
    }
    return $html;
}

/**
 * Aufzählung mit farbigen Haken
 *
 * @param array $punkte Texte
 * @param string $farbe Akzentfarbe
 * @return string HTML
 */
function shopEbayPageList(array $punkte, string $farbe): string {
    $html = '<table role="presentation" style="width:100%;border-collapse:collapse;margin:0 0 12px 0">';
    foreach ($punkte as $punkt) {
        if ('' === trim((string)$punkt)) {
            continue;
        }
        $html .= '<tr><td style="width:26px;vertical-align:top;padding:4px 0;color:'.$farbe.';font-weight:bold;font-size:16px">&#10003;</td>'
            .'<td style="padding:4px 0;font-size:15px;line-height:1.5">'.shopEbayPageEsc($punkt).'</td></tr>';
    }
    return $html.'</table>';
}

/**
 * Tabelle aus Name/Wert-Paaren
 *
 * @param array $paare [{name, value}]
 * @param string $kopfFarbe Hintergrund der Namensspalte
 * @return string HTML
 */
function shopEbayPageTable(array $paare, string $kopfFarbe): string {
    $html = '<table style="width:100%;border-collapse:collapse;margin:0 0 12px 0;font-size:14px">';
    $nr = 0;
    foreach ($paare as $paar) {
        $name = trim((string)($paar['name'] ?? ''));
        $wert = trim((string)($paar['value'] ?? ''));
        if ('' === $name || '' === $wert) {
            continue;
        }
        $grund = 0 === $nr++ % 2 ? '#ffffff' : '#f6f8fa';
        $html .= '<tr style="background:'.$grund.'">'
            .'<td style="width:40%;padding:8px 10px;border:1px solid #e1e4e8;background:'.$kopfFarbe.';font-weight:bold;vertical-align:top">'.shopEbayPageEsc($name).'</td>'
            .'<td style="padding:8px 10px;border:1px solid #e1e4e8;vertical-align:top">'.shopEbayPageEsc($wert).'</td></tr>';
    }
    return $nr ? $html.'</table>' : '';
}

/**
 * Rendert die Produktseite
 *
 * @param array $seite Aufbau (siehe oben)
 * @param array $kontext title (eBay-Titel), images (Adressen), company,
 *                       shipping, returns, about (Texte des Kanals), color (Vorgabe),
 *                       language (content_language des Kanals, etwa de-DE)
 * @return string HTML für die Beschreibung bei eBay
 */
function shopEbayRenderPage(array $seite, array $kontext): string {
    $vorlage = in_array($seite['template'] ?? '', SHOP_EBAY_PAGE_TEMPLATES, true) ? $seite['template'] : 'klar';
    $farbe = shopEbayPageColor((string)($seite['color'] ?? '') ?: (string)($kontext['color'] ?? ''));
    $hell = $farbe.'14'; // Akzentfarbe mit 8 % Deckung für Flächen
    $schrift = 'font-family:Arial,Helvetica,sans-serif;color:#1f2328';
    $absatz = 'margin:0 0 12px 0;font-size:15px;line-height:1.6';
    $kompakt = 'kompakt' === $vorlage;
    $wort = SHOP_EBAY_PAGE_LABELS[strtolower(substr((string)($kontext['language'] ?? 'de'), 0, 2))] ?? SHOP_EBAY_PAGE_LABELS['de'];

    $abschnitt = function (string $titel, string $inhalt) use ($vorlage, $farbe, $hell, $kompakt): string {
        if ('' === trim(strip_tags($inhalt, '<img>'))) {
            return '';
        }
        $kopf = 'modern' === $vorlage
            ? '<h2 style="margin:0 0 10px 0;padding:8px 12px;font-size:17px;background:'.$hell.';border-radius:6px;color:'.$farbe.'">'.shopEbayPageEsc($titel).'</h2>'
            : '<h2 style="margin:0 0 10px 0;padding:0 0 6px 0;font-size:'.($kompakt ? '15' : '18').'px;border-bottom:2px solid '.$farbe.';color:#1f2328">'.shopEbayPageEsc($titel).'</h2>';
        return '<div style="margin:0 0 '.($kompakt ? '14' : '22').'px 0">'.$kopf.$inhalt.'</div>';
    };

    $titel = trim((string)($seite['headline'] ?? '')) ?: trim((string)($kontext['title'] ?? ''));
    $html = '<div style="max-width:900px;margin:0 auto;'.$schrift.'">';

    // Kopf
    if ('modern' === $vorlage) {
        $html .= '<div style="background:'.$farbe.';color:#ffffff;padding:22px 20px;border-radius:10px;margin:0 0 20px 0">'
            .'<h1 style="margin:0;font-size:24px;line-height:1.3;color:#ffffff">'.shopEbayPageEsc($titel).'</h1>'
            .(trim((string)($seite['subtitle'] ?? '')) !== '' ? '<div style="margin-top:6px;font-size:16px;opacity:.9">'.shopEbayPageEsc($seite['subtitle']).'</div>' : '')
            .'</div>';
    } else {
        $html .= '<h1 style="margin:0 0 6px 0;font-size:'.($kompakt ? '20' : '24').'px;line-height:1.3">'.shopEbayPageEsc($titel).'</h1>'
            .(trim((string)($seite['subtitle'] ?? '')) !== '' ? '<div style="margin:0 0 16px 0;font-size:16px;color:#57606a">'.shopEbayPageEsc($seite['subtitle']).'</div>' : '')
            .'<div style="height:3px;background:'.$farbe.';margin:0 0 18px 0;width:80px"></div>';
    }

    // Bilder: Hauptbild groß, weitere klein darunter
    $bilder = !empty($seite['show_images']) ? array_slice($kontext['images'] ?? [], 0, 6) : [];
    if ($bilder) {
        $html .= '<div style="text-align:center;margin:0 0 18px 0"><img src="'.shopEbayPageEsc($bilder[0]).'" alt="" style="max-width:100%;max-height:520px;border-radius:8px"></div>';
        if (count($bilder) > 1) {
            $html .= '<div style="text-align:center;margin:0 0 18px 0">';
            foreach (array_slice($bilder, 1) as $bild) {
                $html .= '<img src="'.shopEbayPageEsc($bild).'" alt="" style="width:23%;max-width:180px;margin:0 1%;border-radius:6px;border:1px solid #e1e4e8">';
            }
            $html .= '</div>';
        }
    }

    // Das Wichtigste auf einen Blick
    $punkte = array_values(array_filter(array_map('strval', (array)($seite['highlights'] ?? [])), fn($p) => '' !== trim($p)));
    if ($punkte) {
        $html .= '<div style="background:'.$hell.';border-radius:8px;padding:12px 16px;margin:0 0 20px 0">'
            .shopEbayPageList($punkte, $farbe).'</div>';
    }

    $daten = shopEbayPageTable((array)($seite['specs'] ?? []), $kompakt ? '#f6f8fa' : $hell);
    $text = shopEbayPageText((string)($seite['description'] ?? ''), $absatz, $farbe);
    // Kompakt: die Daten zuerst — Technik-Käufer suchen Maße und Nummern
    if ($kompakt) {
        $html .= $abschnitt($wort['specs'], $daten).$abschnitt($wort['description'], $text);
    } else {
        $html .= $abschnitt($wort['description'], $text).$abschnitt($wort['specs'], $daten);
    }
    $html .= $abschnitt($wort['compatibility'], shopEbayPageText((string)($seite['compatibility'] ?? ''), $absatz, $farbe));
    $html .= $abschnitt($wort['scope'], shopEbayPageText((string)($seite['scope'] ?? ''), $absatz, $farbe));
    $html .= $abschnitt($wort['notes'], shopEbayPageText((string)($seite['notes'] ?? ''), $absatz, $farbe));

    // Fuß aus dem Kanal: Versand, Rückgabe, über uns
    $fuss = '';
    foreach (['shipping', 'returns', 'about'] as $schluessel) {
        $ueberschrift = $wort[$schluessel];
        $inhalt = trim((string)($kontext[$schluessel] ?? ''));
        if ('' !== $inhalt) {
            $fuss .= '<div style="margin:0 0 12px 0"><div style="font-weight:bold;margin:0 0 4px 0;color:'.$farbe.'">'.shopEbayPageEsc($ueberschrift).'</div>'
                .shopEbayPageText($inhalt, 'margin:0 0 6px 0;font-size:13px;line-height:1.5;color:#57606a', $farbe).'</div>';
        }
    }
    if ('' !== $fuss) {
        $html .= '<div style="border-top:1px solid #e1e4e8;padding-top:16px;margin-top:8px">'.$fuss.'</div>';
    }
    if ('' !== trim((string)($kontext['company'] ?? ''))) {
        $html .= '<div style="text-align:center;font-size:12px;color:#8c959f;margin-top:16px">'.shopEbayPageEsc($kontext['company']).'</div>';
    }
    return $html.'</div>';
}

/**
 * Aufbau einer Seite bereinigen: nur bekannte Felder, Texte gekürzt
 *
 * @param array $seite Eingabe
 * @return array
 */
function shopEbayPageClean(array $seite): array {
    $paare = fn($liste) => array_values(array_filter(array_map(fn($p) => [
        'name'  => mb_substr(trim((string)($p['name'] ?? '')), 0, 65),
        'value' => mb_substr(trim((string)($p['value'] ?? '')), 0, 200),
    ], is_array($liste) ? $liste : []), fn($p) => '' !== $p['name'] && '' !== $p['value']));

    return [
        'template'      => in_array($seite['template'] ?? '', SHOP_EBAY_PAGE_TEMPLATES, true) ? $seite['template'] : 'klar',
        'color'         => shopEbayPageColor((string)($seite['color'] ?? '')),
        'title'         => mb_substr(trim((string)($seite['title'] ?? '')), 0, 80),
        'headline'      => mb_substr(trim((string)($seite['headline'] ?? '')), 0, 150),
        'subtitle'      => mb_substr(trim((string)($seite['subtitle'] ?? '')), 0, 200),
        'highlights'    => array_slice(array_values(array_filter(array_map(fn($p) => mb_substr(trim((string)$p), 0, 200),
                                                                            (array)($seite['highlights'] ?? [])))), 0, 8),
        'description'   => mb_substr(trim((string)($seite['description'] ?? '')), 0, 8000),
        'specs'         => array_slice($paare($seite['specs'] ?? []), 0, 40),
        'aspects'       => array_slice($paare($seite['aspects'] ?? []), 0, 30),
        'compatibility' => mb_substr(trim((string)($seite['compatibility'] ?? '')), 0, 4000),
        'scope'         => mb_substr(trim((string)($seite['scope'] ?? '')), 0, 2000),
        'notes'         => mb_substr(trim((string)($seite['notes'] ?? '')), 0, 2000),
        'show_images'   => !empty($seite['show_images']),
    ];
}
