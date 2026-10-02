<?php
// backend/api/shop/templates/invoice.de.php — Rechnung an den Kunden
//
// Verfügbar: $name, $greeting, $invnumber, $amount, $currency, $signatur,
//            $positionen (description, qty, unit, delivery_term)
$titel = 'Ihre Rechnung '.$invnumber;
ob_start(); ?>
<p class="kopf">Guten Tag<?= $name ? ' '.htmlspecialchars($name) : '' ?>,</p>
<p class="text">
  vielen Dank für Ihre Bestellung. Ihre Rechnung <strong><?= htmlspecialchars($invnumber) ?></strong>
  über <strong><?= htmlspecialchars(number_format((float)$amount, 2, ',', '.')) ?>&nbsp;<?= htmlspecialchars($currency) ?></strong>
  finden Sie im Anhang dieser Nachricht.
</p>
<?php if (!empty($positionen)): ?>
<!-- Bestellte Artikel mit Lieferbedingung (dev/shop-versand.md, Punkt 7) -->
<p class="text"><strong>Ihre Bestellung</strong></p>
<table class="angaben">
  <?php foreach ($positionen as $position): ?>
  <tr>
    <td style="padding-right: 16px;"><?= htmlspecialchars(rtrim(rtrim(number_format((float)$position['qty'], 3, ',', '.'), '0'), ',')) ?>&nbsp;<?= htmlspecialchars((string)$position['unit']) ?></td>
    <td>
      <?= htmlspecialchars((string)$position['description']) ?>
      <?php if ('' !== (string)$position['delivery_term']): ?>
      <br><span style="color: #777777;"><?= htmlspecialchars((string)$position['delivery_term']) ?></span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>
<p class="text">
  Sofern Sie noch nicht bezahlt haben, entnehmen Sie die Bankverbindung bitte der Rechnung.
</p>
<p class="text">Mit freundlichen Grüßen</p>
<?php $inhalt = ob_get_clean(); include __DIR__.'/_layout.php';
