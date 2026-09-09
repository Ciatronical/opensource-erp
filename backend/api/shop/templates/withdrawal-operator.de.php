<?php
// backend/api/shop/templates/withdrawal-operator.de.php
//
// Bearbeitungsmail an den Betreiber, mit allen Angaben des Formulars.
//
// Verfügbar: $name, $ordernumber, $email, $reason, $eingegangen,
//            $remote_addr, $user_agent
$titel = 'Widerruf eingegangen';
ob_start(); ?>
<p class="kopf">Widerruf eingegangen</p>
<table class="angaben">
  <tr><th>Bestellung</th><td><?= htmlspecialchars($ordernumber) ?></td></tr>
  <tr><th>Name</th><td><?= htmlspecialchars($name) ?></td></tr>
  <tr><th>E-Mail</th><td><?= htmlspecialchars($email) ?></td></tr>
  <tr><th>Eingegangen</th><td><?= htmlspecialchars(date('d.m.Y H:i:s', strtotime((string)$eingegangen))) ?></td></tr>
  <tr><th>IP-Adresse</th><td><?= htmlspecialchars($remote_addr) ?></td></tr>
  <tr><th>Browser</th><td><?= htmlspecialchars($user_agent) ?></td></tr>
</table>
<?php if (!empty($reason)): ?>
  <p class="text"><strong>Begründung des Kunden</strong></p>
  <div class="hervorgehoben text"><?= nl2br(htmlspecialchars($reason)) ?></div>
<?php endif; ?>
<p class="text">Der Vorgang steht im Admin-Panel unter den Widerrufen.</p>
<?php $inhalt = ob_get_clean(); include __DIR__.'/_layout.php';
