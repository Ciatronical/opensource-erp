<?php
// backend/api/shop/templates/contact.de.php — Anfrage aus dem Kontaktformular
//
// Verfügbar: $name, $email, $phone, $nachricht
$titel = 'Anfrage über das Kontaktformular';
ob_start(); ?>
<p class="kopf">Anfrage über das Kontaktformular</p>
<table class="angaben">
  <tr><th>Name</th><td><?= htmlspecialchars($name) ?></td></tr>
  <tr><th>E-Mail</th><td><?= htmlspecialchars($email) ?></td></tr>
  <?php if (!empty($phone)): ?><tr><th>Telefon</th><td><?= htmlspecialchars($phone) ?></td></tr><?php endif; ?>
</table>
<div class="hervorgehoben text"><?= nl2br(htmlspecialchars($nachricht)) ?></div>
<?php $inhalt = ob_get_clean(); include __DIR__.'/_layout.php';
