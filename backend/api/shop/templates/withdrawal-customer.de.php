<?php
// backend/api/shop/templates/withdrawal-customer.de.php
//
// Eingangsbestätigung an den Kunden. Bewusst neutral: bestätigt wird der
// Eingang, nicht die Wirksamkeit des Widerrufs (§ 356a BGB).
//
// Verfügbar: $name, $ordernumber, $email, $reason, $eingegangen
$titel = 'Eingangsbestätigung Ihres Widerrufs';
ob_start(); ?>
<p class="kopf">Eingangsbestätigung Ihres Widerrufs</p>
<p class="text">Sehr geehrte/r <?= htmlspecialchars($name) ?>,</p>
<p class="text">
  wir bestätigen den Eingang Ihrer Widerrufserklärung zur Bestellung
  <strong><?= htmlspecialchars($ordernumber) ?></strong> am
  <?= htmlspecialchars(date('d.m.Y \u\m H:i \U\h\r', strtotime((string)$eingegangen))) ?>.
</p>
<?php if (!empty($reason)): ?>
  <div class="hervorgehoben text"><?= nl2br(htmlspecialchars($reason)) ?></div>
<?php endif; ?>
<p class="text">
  Diese Nachricht bestätigt den Eingang Ihrer Erklärung. Wir melden uns zum
  weiteren Ablauf, insbesondere zur Rücksendung und zur Erstattung.
</p>
<p class="text">Mit freundlichen Grüßen</p>
<?php $inhalt = ob_get_clean(); include __DIR__.'/_layout.php';
