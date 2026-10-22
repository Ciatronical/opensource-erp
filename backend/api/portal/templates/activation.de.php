<?php
// backend/api/portal/templates/activation.de.php — Link zur Einrichtung des Kundenkontos
//
// Verfügbar: $name, $link, $stunden, $webseite, $signatur
$titel = 'Ihr Zugang zum Kundenkonto';
ob_start(); ?>
<p class="kopf">Ihr Zugang zum Kundenkonto</p>
<p class="text">Hallo <?= htmlspecialchars($name) ?>,</p>
<p class="text">mit dem folgenden Knopf richten Sie Ihren Zugang zum Kundenkonto<?= !empty($webseite) ? ' auf '.htmlspecialchars($webseite) : '' ?> ein.
  Sie vergeben dabei Ihre E-Mail-Adresse und ein Kennwort — danach können Sie sich jederzeit anmelden.</p>
<p style="margin: 24px 0;"><a class="knopf" href="<?= htmlspecialchars($link) ?>">Zugang jetzt einrichten</a></p>
<p class="text">Der Link gilt <?= (int)$stunden ?> Stunden. Falls der Knopf nicht funktioniert, kopieren Sie diese Adresse in Ihren Browser:<br>
  <a href="<?= htmlspecialchars($link) ?>"><?= htmlspecialchars($link) ?></a></p>
<p class="text">Sie haben diese Nachricht nicht erwartet? Dann können Sie sie einfach löschen — es wird nichts geändert.</p>
<?php $inhalt = ob_get_clean(); include __DIR__.'/_layout.php';
