<?php
// backend/api/shop/templates/delivery-status.de.php
//
// Mail an den Kunden, wenn sich der Lieferstatus seiner Bestellung ändert
// (dev/shop-bestellstatus.md). Nur für HugoShop-Bestellungen und nur bei
// eingeschaltetem shop_delivery_status_mail.
//
// Verfügbar: $name, $invnumber, $status (Schlüssel), $text (Lieferstatus),
// $link (Rechnungsseite, leer ohne Basisadresse des Kanals), $signatur (Fußzeile, _layout.php)
$titel = 'Lieferstatus Ihrer Bestellung';
ob_start(); ?>
<p class="kopf">Lieferstatus Ihrer Bestellung</p>
<p class="text">Guten Tag <?= htmlspecialchars($name) ?>,</p>
<p class="text">
  der Lieferstatus Ihrer Bestellung <strong><?= htmlspecialchars($invnumber) ?></strong>
  hat sich geändert:
</p>
<div class="hervorgehoben text"><strong><?= htmlspecialchars($text) ?></strong></div>
<?php if ('shipped' === $status || 'partially_shipped' === $status): ?>
<p class="text">Die Sendung ist unterwegs zu Ihnen.</p>
<?php elseif ('returned' === $status || 'partially_returned' === $status): ?>
<p class="text">Die Rücksendung ist bei uns eingegangen.</p>
<?php elseif ('cancelled' === $status): ?>
<p class="text">Die Lieferung wurde abgebrochen. Bei Fragen antworten Sie bitte auf diese Mail.</p>
<?php endif; ?>
<?php if (!empty($link)): ?>
<p class="text">
  Den aktuellen Stand und Ihre Rechnung finden Sie jederzeit hier:<br>
  <a href="<?= htmlspecialchars($link) ?>"><?= htmlspecialchars($link) ?></a>
</p>
<?php endif; ?>
<p class="text">Mit freundlichen Grüßen</p>
<?php $inhalt = ob_get_clean(); include __DIR__.'/_layout.php';
