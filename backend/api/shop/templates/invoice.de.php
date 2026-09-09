<?php
// backend/api/shop/templates/invoice.de.php — Rechnung an den Kunden
//
// Verfügbar: $name, $greeting, $invnumber, $amount, $currency, $signatur
$titel = 'Ihre Rechnung '.$invnumber;
ob_start(); ?>
<p class="kopf">Guten Tag<?= $name ? ' '.htmlspecialchars($name) : '' ?>,</p>
<p class="text">
  vielen Dank für Ihre Bestellung. Ihre Rechnung <strong><?= htmlspecialchars($invnumber) ?></strong>
  über <strong><?= htmlspecialchars(number_format((float)$amount, 2, ',', '.')) ?>&nbsp;<?= htmlspecialchars($currency) ?></strong>
  finden Sie im Anhang dieser Nachricht.
</p>
<p class="text">
  Sofern Sie noch nicht bezahlt haben, entnehmen Sie die Bankverbindung bitte der Rechnung.
</p>
<p class="text">Mit freundlichen Grüßen</p>
<?php $inhalt = ob_get_clean(); include __DIR__.'/_layout.php';
