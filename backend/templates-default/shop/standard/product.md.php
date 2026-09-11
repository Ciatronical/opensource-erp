<?php
/**
 * Produktseite für das Hugo-Theme hugoshop
 *
 * Bekommt $seite aus shopPageData(): artikel, shop, betrieb.
 * Hilfen aus lib/publish.php: shopYaml(), shopYamlList(), shopNumber(), shopMoney().
 *
 * Der Warenkorb-Shortcode trägt die parts.id — eine Seite gehört damit zu der
 * Datenbank, aus der sie erzeugt wurde.
 */
$artikel = $seite['artikel'];
$shop    = $seite['shop'];
$betrieb = $seite['betrieb'];

$titel = $artikel['description'];
$pfad  = $shop['breadcrumbs'];
$kurz  = trim($artikel['partnumber'].($pfad ? ' | '.implode(' | ', $pfad) : ''));
?>
---
title: <?= shopYaml($titel) ?>

description: <?= shopYaml($kurz) ?>

metaDescription: <?= shopYaml($kurz) ?>

productName: <?= shopYaml($titel) ?>

productID: <?= shopYaml($artikel['partnumber']) ?>

<?php if ($artikel['ean'] !== ''): ?>
productModel: <?= shopYaml($artikel['ean']) ?>

<?php endif; ?>
mpn: <?= shopYaml($artikel['partnumber']) ?>

price: <?= shopYaml(shopNumber($artikel['sellprice'])) ?>

priceCurrency: <?= shopYaml($betrieb['currency']) ?>

availability: "https://schema.org/<?= $artikel['onhand'] > 0 ? 'InStock' : 'OutOfStock' ?>"
schemaBusinessType: "Product"
type: "produkt"
draft: false
<?php if ($artikel['mtime'] !== ''): ?>
lastmod: <?= shopYaml($artikel['mtime']) ?>

<?php endif; ?>
category: "Produkt"
<?php if ($shop['category'] !== ''): ?>
kategorien: <?= shopYamlList([$shop['category']]) ?>

catalogname: <?= shopYaml($shop['category']) ?>

<?php endif; ?>
<?php if ($pfad): ?>
breadcrumbs: <?= shopYamlList($pfad) ?>

<?php endif; ?>
<?php if ($shop['images']): ?>
product-image: <?= shopYaml($shop['image_urls'][0]) ?>

card-image: <?= shopYaml($shop['thumbnail_url']) ?>

card-image-alt: <?= shopYaml($titel) ?>

<?php endif; ?>
---

<?php
/**
 * Seitenkörper aus Absatzblöcken.
 *
 * Nicht als Vorlagentext: PHP verschluckt den Zeilenumbruch nach ?>, und die
 * Leerzeile zwischen zwei Absätzen entscheidet in Markdown über den Umbruch.
 */
$tabelle = function (array $paare, string $links, string $rechts): string {
    $zeilen = ['| '.$links.' | '.$rechts.' |', '| --- | --- |'];
    foreach ($paare as $bezeichnung => $wert) {
        $zeilen[] = '| '.$bezeichnung.' | '.$wert.' |';
    }
    return implode("\n", $zeilen);
};

$bloecke = [];

if ($artikel['notes'] !== '') {
    $bloecke[] = $artikel['notes'];
}

// Preise wie im bisherigen Shop: netto und brutto, deutsches Zahlenformat.
// Ohne Steuerschlüssel gibt es nur einen Preis.
$einheit = $artikel['unit'] !== '' ? ' / '.$artikel['unit'] : '';
if ($artikel['taxrate'] > 0) {
    $bloecke[] = '**'.shopMoney($artikel['price_net']).' '.$betrieb['currency'].'**'.$einheit.' ohne MwSt.';
    $bloecke[] = '**'.shopMoney($artikel['price_gross']).' '.$betrieb['currency'].'**'.$einheit.' inkl. MwSt.';
} else {
    $bloecke[] = '**'.shopMoney($artikel['price_net']).' '.$betrieb['currency'].'**'.$einheit;
}

$bloecke[] = '{{< shop-add-to-cart product="'.$artikel['id'].'" button-text="In den Warenkorb" >}}';

if ($shop['technical_data']) {
    $bloecke[] = "## Technische Daten";
    $bloecke[] = $tabelle($shop['technical_data'], 'Bezeichnung', 'Wert');
}

if ($shop['properties']) {
    $bloecke[] = "## Eigenschaften";
    $bloecke[] = $tabelle($shop['properties'], 'Bezeichnung', 'Wert');
}

if ($shop['downloads']) {
    $bloecke[] = "## Downloads";
    $liste = [];
    foreach ($shop['download_urls'] as $anzeigename => $adresse) {
        $liste[] = '- ['.$anzeigename.']('.$adresse.')';
    }
    $bloecke[] = implode("\n", $liste);
}

echo implode("\n\n", $bloecke)."\n";
