<?php
// backend/api/shop/templates/order-operator.de.php
//
// Meldung an den Betreiber: im Shop ist eine Bestellung eingegangen.
//
// Verfügbar: $invnumber, $amount, $currency, $name, $customernumber, $email,
//            $phone, $zahlung, $signatur,
//            $lieferadresse (name, street, zipcode, city, country),
//            $positionen (description, qty, unit, delivery_term)
$titel = 'Neue Bestellung '.$invnumber;
ob_start(); ?>
<p class="kopf">Neue Bestellung</p>
<table class="angaben">
  <tr><th>Rechnung</th><td><?= htmlspecialchars((string)$invnumber) ?></td></tr>
  <tr><th>Betrag</th><td><?= htmlspecialchars(number_format((float)$amount, 2, ',', '.')) ?>&nbsp;<?= htmlspecialchars((string)$currency) ?></td></tr>
  <tr><th>Zahlung</th><td><?= htmlspecialchars((string)$zahlung) ?></td></tr>
  <tr><th>Kunde</th><td><?= htmlspecialchars((string)$name) ?><?= '' !== (string)$customernumber ? ' ('.htmlspecialchars((string)$customernumber).')' : '' ?></td></tr>
  <?php if ('' !== (string)$email): ?>
  <tr><th>E-Mail</th><td><?= htmlspecialchars((string)$email) ?></td></tr>
  <?php endif; ?>
  <?php if ('' !== (string)$phone): ?>
  <tr><th>Telefon</th><td><?= htmlspecialchars((string)$phone) ?></td></tr>
  <?php endif; ?>
  <?php if (!empty($lieferadresse)): ?>
  <tr>
    <th>Lieferung an</th>
    <td>
      <?= htmlspecialchars((string)($lieferadresse['name'] ?? '')) ?><br>
      <?= htmlspecialchars((string)($lieferadresse['street'] ?? '')) ?><br>
      <?= htmlspecialchars(trim(($lieferadresse['zipcode'] ?? '').' '.($lieferadresse['city'] ?? ''))) ?>
      <?php if ('' !== (string)($lieferadresse['country'] ?? '')): ?>
      <br><?= htmlspecialchars((string)$lieferadresse['country']) ?>
      <?php endif; ?>
    </td>
  </tr>
  <?php endif; ?>
</table>
<?php if (!empty($positionen)): ?>
<p class="text"><strong>Positionen</strong></p>
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
<p class="text">Die Rechnung steht im ERP bei den Bestellungen des Shops.</p>
<?php $inhalt = ob_get_clean(); include __DIR__.'/_layout.php';
