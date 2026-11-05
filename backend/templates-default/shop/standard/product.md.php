<?php
/**
 * Produktseite des mitgelieferten Satzes „standard“
 *
 * Dasselbe Aussehen wie der Satz sonic24 (Nachbau von
 * sonic24.de/publish/template.php): Bootstrap-Markup mit Preisblock netto
 * und brutto, Verfügbarkeitsabzeichen, Warenkorb (shop-add-to-cart),
 * Anfrage-Knopf und den Reitern Beschreibung, Technische Daten, Eigenschaften
 * und Downloads. Die Produktbilder zeigt seit 11/2026 das Widget
 * shop-product-images mit Vergrößerung (Lightbox) statt des
 * Bootstrap-Karussells. Das Theme hugoshop lädt Bootstrap; das Theme
 * inter-data bildet die übrigen Klassen unter .produkt-seite nach (theme.css,
 * ui.js) — die Seiten sehen in beiden gleich aus.
 *
 * Was hier gegenüber sonic24 fehlt, gehört zu diesem einen Shop: Marke,
 * Firmenlogo, Rückgabe- und Versandangaben im Front Matter. Ein eigener Satz
 * kopiert diese Datei und ergänzt sie.
 *
 * Bekommt $seite aus shopPageData(): artikel, shop, betrieb.
 * Hilfen aus lib/publish.php: shopYaml(), shopYamlList(), shopNumber(),
 * shopMoney(), shopQuantity().
 *
 * Der Warenkorb-Shortcode trägt die parts.id — eine Seite gehört damit zu der
 * Datenbank, aus der sie erzeugt wurde.
 */
$artikel = $seite['artikel'];
$shop    = $seite['shop'];
$betrieb = $seite['betrieb'];

// Seite der Webseite, auf die der Anfrage-Knopf verweist. Taxonomie,
// Bezeichnung und Adressen der Kategorien kommen dagegen aus der Kanalkarte
// (shop.taxonomy, shop.categories_title, shop.categories_url,
// shop.category_url) — eine Webseite mit eigener Themen-Taxonomie legt den
// Shop so unter shopkategorien und etwa /shop/sortiment/ mit „Sortiment“.
$kontaktpfad = '/kontakt/';

$titel = $artikel['description'];

// Kurzbeschreibung aus der „Einordnung im Sortiment“ (parts_ext.hugoshop_breadcrumbs):
// die Stufen ohne die letzte, und die letzte nur, wenn sie vom Titel
// abweicht. Sie steht als description unter dem Titel der Produktseite und in
// den Kategorielisten — ohne Artikelnummer. Ohne Stufen entfällt sie.
//
// metaDescription behält die Artikelnummer vorn: sie ist nicht sichtbar, und
// wer bei einer Suchmaschine nach der Nummer sucht, findet die Seite so.
$stufen = $shop['breadcrumbs'];
$letzte = $stufen ? array_pop($stufen) : '';
if ('' !== $letzte && $letzte !== $titel) {
    $stufen[] = $letzte;
}
$kurz = implode(' | ', $stufen);
$meta = implode(' | ', array_merge([$artikel['partnumber']], $stufen));

// Bestellbar: nicht veraltet und im HugoShop nicht als nicht verfügbar
// markiert (shop_part_available). Der Bestand entscheidet nur über das
// Abzeichen: „Auf Lager“ oder „bestellbar, zurzeit nicht auf Lager“.
$bestellbar = $artikel['available'];
$aufLager   = $bestellbar && $artikel['onhand'] > 0;
$bilder     = $shop['image_urls'];
$bildnamen  = array_map(fn($name) => basename((string)$name), $shop['images']);

$beschreibung = trim($artikel['notes']);
$waehrung     = $betrieb['currency'];

$schutz = fn($wert) => htmlspecialchars((string)$wert, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
---
title: <?= shopYaml($titel) ?>

<?php if ($kurz !== ''): ?>
description: <?= shopYaml($kurz) ?>

<?php endif; ?>
metaDescription: <?= shopYaml($meta) ?>

productName: <?= shopYaml($titel) ?>

productID: <?= shopYaml($artikel['partnumber']) ?>

<?php if ($artikel['ean'] !== ''): ?>
productModel: <?= shopYaml($artikel['ean']) ?>

<?php endif; ?>
mpn: <?= shopYaml($artikel['partnumber']) ?>

price: <?= shopYaml(shopNumber($artikel['sellprice'])) ?>

priceCurrency: <?= shopYaml($waehrung) ?>

availability: "https://schema.org/<?= $aufLager ? 'InStock' : 'OutOfStock' ?>"
<?php if ($artikel['delivery_term'] !== ''): ?>
deliveryTerm: <?= shopYaml($artikel['delivery_term']) ?>

<?php endif; ?>
<?php if ($artikel['min_qty'] !== null): ?>
minQuantity: <?= shopYaml(shopNumber($artikel['min_qty'], 3)) ?>

<?php endif; ?>
schemaBusinessType: "Product"
type: "produkt"
draft: false
<?php if ($artikel['mtime'] !== ''): ?>
lastmod: <?= shopYaml($artikel['mtime']) ?>

<?php endif; ?>
category: "Produkt"
home: <?= shopYaml($shop['categories_title']) ?>

homelink: <?= shopYaml($shop['categories_url']) ?>

<?php if ($shop['category'] !== ''): ?>
<?= $shop['taxonomy'] ?>: <?= shopYamlList([$shop['category']]) ?>

catalogname: <?= shopYaml(ucfirst($shop['category'])) ?>

cataloglink: <?= shopYaml($shop['category_url']) ?>

<?php endif; ?>
<?php if ($shop['breadcrumbs']): ?>
breadcrumbs: <?= shopYamlList($shop['breadcrumbs']) ?>

<?php endif; ?>
<?php if ($bilder): ?>
product-image: <?= shopYaml($bilder[0]) ?>

card-image: <?= shopYaml($shop['thumbnail_url']) ?>

card-image-alt: <?= shopYaml($titel) ?>

<?php endif; ?>
---
<div class="row mb-5">
<div class="col-md-4 pe-4">
<?php // Produktbilder mit Vergrößerung: das Widget shop-product-images liest die
      // Links aus und zeigt sie mit Lightbox. Ohne Bündel bleiben die Links,
      // wie sie hier stehen — das Bild öffnet dann in einem neuen Tab. Keine
      // Leerzeile dazwischen: sie beendete den HTML-Block für Goldmark.
      //
      // width/height aus der Medienbibliothek (shop.image_sizes): der Browser
      // reserviert den Platz, bevor das Bild geladen ist — ohne die Maße
      // rutscht alles darunter nach, sobald es da ist. ?>
<?php if ($bilder): ?>
{{< shop-product-images >}}
<?php foreach ($bilder as $nummer => $bild): ?>
<?php $mass = $shop['image_sizes'][$nummer] ?? null; ?>
<a href="<?= $schutz($bild) ?>" target="_blank"><img src="<?= $schutz($bild) ?>"<?= $mass ? ' width="'.(int)$mass['width'].'" height="'.(int)$mass['height'].'"' : '' ?> style="max-width: 100%; height: auto;" alt="<?= $schutz(count($bilder) > 1 ? $titel.' - Ansicht '.($nummer + 1) : $titel) ?>"<?= $nummer > 0 ? ' loading="lazy"' : '' ?>></a>
<?php endforeach; ?>
{{< /shop-product-images >}}
<?php endif; ?>
</div>
<div class="col-md-6">
<h2 class="mt-0">Produkteigenschaften</h2>
<h3 class="h5 pb-1">Preis</h3>
<?php if ($artikel['taxrate'] > 0): ?>
<div class="lead fw-bold"><?= shopMoney($artikel['price_net']) ?> <?= $schutz($waehrung) ?> <span class="ps-2 fw-lighter fs-6">ohne MwSt.</span></div>
<div class="lead fw-bold fs-3"><?= shopMoney($artikel['price_gross']) ?> <?= $schutz($waehrung) ?> <span class="ps-2 fw-lighter fs-6">inkl. MwSt.</span></div>
<?php else: ?>
<?php // Ohne Steuerschlüssel ist brutto gleich netto: ein Preis ?>
<div class="lead fw-bold fs-3"><?= shopMoney($artikel['price_net']) ?> <?= $schutz($waehrung) ?></div>
<?php endif; ?>
<h3 class="h5 pt-4 pb-1">Verfügbarkeit</h3>
<?php // Abzeichen in Bootstraps abgeschwächten Farben (bg-*-subtle und
      // text-*-emphasis, Kontrast über 7:1). Die Farben stehen als Variablen
      // von Bootstrap 5.3 mit festem Rückfall im style-Attribut: Bootstrap 5.2
      // (hugoshop) kennt die Klassen nicht, und das Theme inter-data hat kein
      // Bootstrap. Die Utilities text-success/bg-success tragen !important
      // und bleiben deshalb weg — sie überschrieben jede Farbe hier. ?>
<?php if (!$bestellbar): ?>
<div class="badge rounded-pill mb-2" style="color: var(--bs-danger-text-emphasis, #58151c); background-color: var(--bs-danger-bg-subtle, #f8d7da);">
  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" class="bi bi-x-circle-fill me-2">
  <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293z"></path>
</svg>
    <span style="vertical-align: middle;">Momentan nicht verfügbar</span>
</div>
<p class="mb-0">Lieferzeit auf Anfrage</p>
<?php elseif ($aufLager): ?>
<div class="badge rounded-pill mb-2" style="color: var(--bs-success-text-emphasis, #0a3622); background-color: var(--bs-success-bg-subtle, #d1e7dd);">
  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-check-circle-fill me-2" viewBox="0 0 16 16">
  <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
</svg>
    <span style="vertical-align: middle;">Verfügbar / Auf Lager</span>
</div>
<?php else: ?>
<?php // Gelb: Bootstraps Textfarbe (#664d03, 7,2:1) wirkt auf dem hellen Gelb
      // noch schwach — fester dunkler Braunton (11,9:1), wie bei Grün und Rot ?>
<div class="badge rounded-pill mb-2" style="color: #3d2e02; background-color: var(--bs-warning-bg-subtle, #fff3cd);">
  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-clock-fill me-2" viewBox="0 0 16 16">
  <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM8 3.5a.5.5 0 0 0-1 0V9a.5.5 0 0 0 .252.434l3.5 2a.5.5 0 0 0 .496-.868L8 8.71V3.5z"/>
</svg>
    <span style="vertical-align: middle;">Bestellbar, zurzeit nicht auf Lager</span>
</div>
<?php endif; ?>
<?php if ($bestellbar): ?>
<?php // Lieferbedingung und Mindestabnahme (dev/shop-versand.md, Schritt 5) —
      // vor dem Kauf zu nennen, deshalb über dem Warenkorb-Knopf ?>
<?php if ($artikel['delivery_term'] !== ''): ?>
<p class="mb-0"><?= $schutz($artikel['delivery_term']) ?></p>
<?php endif; ?>
<?php if ($artikel['min_qty'] !== null): ?>
<p class="mb-0">Mindestabnahme: <?= $schutz(shopQuantity($artikel['min_qty']).($artikel['unit'] !== '' ? ' '.$artikel['unit'] : '')) ?></p>
<?php endif; ?>
<hr>
{{< shop-add-to-cart product="<?= (int)$artikel['id'] ?>" button-text="In den Warenkorb" >}}
<?php endif; ?>
<div><a id="send-request" class="btn btn-primary my-2">Anfragen</a></div>
</div>
</div>
<div class="row">
<div class="col-md-10">
<ul class="nav nav-tabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-description" type="button">
      Beschreibung
    </button>
  </li>
<?php if ($shop['technical_data']): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-technical-data" type="button">
      Technische Daten
    </button>
  </li>
<?php endif; ?>
<?php if ($shop['properties']): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-properties" type="button">
      Eigenschaften
    </button>
  </li>
<?php endif; ?>
<?php if ($shop['downloads']): ?>
  <li class="nav-item" role="presentation">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-downloads" type="button">
      Downloads
    </button>
  </li>
<?php endif; ?>
</ul>
<div class="tab-content mt-3">
<div class="tab-pane fade show active" id="tab-description">
<?php if ($beschreibung !== ''): ?>
<?php // Die Beschreibung aus dem Kanal bzw. die Langbeschreibung des Artikels
      // kann HTML aus dem Editor enthalten — sie bleibt, wie sie ist ?>
<p>

<?= $beschreibung ?>

</p>
<?php else: ?>
<p>Keine weitere Beschreibung vorhanden.</p>
<?php endif; ?>
<div class="alert alert-info mt-5">
  <strong class="me-1">Noch offene Fragen?</strong>Kontaktieren Sie uns gerne über unsere Kontaktmöglichkeiten. Wir beraten Sie und helfen Ihnen gerne weiter!
</div>
</div>
<?php foreach (['technical-data' => $shop['technical_data'], 'properties' => $shop['properties']] as $kennung => $paare): ?>
<?php if ($paare): ?>
<div class="tab-pane fade" id="tab-<?= $kennung ?>">
<table class="table table-striped" style="table-layout: fixed;">
<tbody>
<?php foreach ($paare as $bezeichnung => $wert): ?>
<tr><td><?= $schutz($bezeichnung) ?></td><td style="text-align: end;"><?= $schutz($wert) ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($shop['downloads']): ?>
<div class="tab-pane fade" id="tab-downloads">
<table class="table table-striped" style="table-layout: fixed;">
<tbody>
<?php foreach (array_values($shop['downloads']) as $nummer => $datei): ?>
<tr><td><a href="<?= $schutz(array_values($shop['download_urls'])[$nummer] ?? '') ?>" target="_blank"><?= $schutz($datei) ?></a></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
</div>
</div>
<script>
    document.getElementById('send-request').addEventListener("click", (event) => { window.location.href = <?= json_encode($kontaktpfad.'?pid='.rawurlencode($artikel['partnumber']), JSON_UNESCAPED_SLASHES) ?>; })
</script>
