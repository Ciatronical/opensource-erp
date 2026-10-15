<?php
/**
 * Produktseite des mitgelieferten Satzes „standard“
 *
 * Dasselbe Aussehen wie der Satz sonic24 (Nachbau von
 * sonic24.de/publish/template.php): Bootstrap-Markup mit Bildkarussell,
 * Preisblock netto und brutto, Verfügbarkeitsabzeichen, Warenkorb
 * (shop-add-to-cart), Anfrage-Knopf und den Reitern Beschreibung, Technische
 * Daten, Eigenschaften und Downloads. Das Theme hugoshop lädt Bootstrap; das
 * Theme inter-data bildet diese Klassen unter .produkt-seite nach (theme.css,
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

// Seiten der Webseite, auf die der Seitenkörper verweist. Die Kategorien sind
// die Taxonomie der Webseite und liegen an deren Wurzel — auch bei einem Shop
// unterhalb einer Webseite (params.shopui.paths.base).
$kontaktpfad   = '/kontakt/';
$kategoriePfad = '/kategorien/';

$titel = $artikel['description'];

// description: Artikelnummer, die Stationen der Navigation ohne die letzte,
// und die letzte nur, wenn sie vom Titel abweicht
$stationen = $shop['breadcrumbs'];
$letzte    = $stationen ? array_pop($stationen) : '';
$kurz      = implode(' | ', array_merge([$artikel['partnumber']], $stationen));
if ('' !== $letzte && $letzte !== $titel) {
    $kurz .= ' | '.$letzte;
}

// Die Kategorie als Adressteil, wie Hugo den Begriff der Taxonomie ablegt:
// klein, Leerzeichen zu Bindestrichen
$katalog = mb_strtolower(str_replace(' ', '-', $shop['category']));

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

description: <?= shopYaml($kurz) ?>

metaDescription: <?= shopYaml($kurz) ?>

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
home: "Kategorien"
homelink: <?= shopYaml($kategoriePfad) ?>

<?php if ($shop['category'] !== ''): ?>
kategorien: <?= shopYamlList([$shop['category']]) ?>

catalogname: <?= shopYaml(ucfirst($shop['category'])) ?>

cataloglink: <?= shopYaml($kategoriePfad.$katalog.'/') ?>

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
<?php if (count($bilder) > 1): ?>
<div id="product-image-carousel" class="carousel carousel-dark slide">
<div class="carousel-inner border rounded">
<?php foreach ($bilder as $nummer => $bild): ?>
<div class="carousel-item<?= 0 === $nummer ? ' active' : '' ?>"><a href="<?= $schutz($bild) ?>" target="_blank"><img src="<?= $schutz($bild) ?>" class="img-fluid d-block w-100" style="max-height: 30rem; object-fit: contain;" alt="<?= $schutz($titel.' - Ansicht '.($nummer + 1)) ?>"<?= $nummer > 0 ? ' loading="lazy"' : '' ?>></a></div>
<?php endforeach; ?>
</div>
<div class="py-2">Zum Vergrößern auf das Bild klicken.</div>
<button class="btn btn-outline-secondary me-1" type="button" data-bs-target="#product-image-carousel" data-bs-slide="prev">
<span class="carousel-control-prev-icon mt-1" aria-hidden="true"></span>
<span class="visually-hidden">Zurück</span>
</button>
<button class="btn btn-outline-secondary" type="button" data-bs-target="#product-image-carousel" data-bs-slide="next">
<span class="carousel-control-next-icon mt-1" aria-hidden="true"></span>
<span class="visually-hidden">Weiter</span>
</button>
</div>
<?php elseif (count($bilder) === 1): ?>
<a href="<?= $schutz($bilder[0]) ?>" target="_blank"><img src="<?= $schutz($bilder[0]) ?>" class="img-fluid" style="max-height: 30rem; object-fit: contain;" alt="<?= $schutz($titel) ?>"></a>
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
<?php if (!$bestellbar): ?>
<div class="badge rounded-pill bg-danger bg-opacity-25 text-danger mb-2">
  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" class="bi bi-x-circle-fill me-2">
  <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293z"></path>
</svg>
    <span style="vertical-align: middle;">Momentan nicht verfügbar</span>
</div>
<p class="mb-0">Lieferzeit auf Anfrage</p>
<?php elseif ($aufLager): ?>
<div class="badge rounded-pill bg-success bg-opacity-25 text-success mb-2">
  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-check-circle-fill me-2" viewBox="0 0 16 16">
  <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
</svg>
    <span style="vertical-align: middle;">Verfügbar / Auf Lager</span>
</div>
<?php else: ?>
<div class="badge rounded-pill bg-warning bg-opacity-25 text-warning mb-2">
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
<p><?= $beschreibung ?></p>
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
