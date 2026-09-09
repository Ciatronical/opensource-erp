<?php
// backend/api/shop/templates/_layout.php
//
// Gemeinsamer Rahmen der Shop-Mails. Die Vorlagen setzen $titel und $inhalt
// und binden diese Datei ein.
//
// Bewusst schlichtes HTML mit Inline-Auszeichnung: Mailprogramme unterstützen
// weder externe Stilvorlagen noch moderne Layouts verlässlich.
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($titel ?? '') ?></title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
  .rahmen { width: 100%; padding: 20px; background-color: #f4f4f4; }
  .inhalt { max-width: 640px; margin: auto; background-color: #ffffff; border: 1px solid #dddddd; padding: 24px; }
  .kopf { font-size: 22px; color: #333333; margin: 0 0 16px 0; }
  .text { font-size: 15px; color: #555555; line-height: 1.5; }
  .hervorgehoben { background-color: #f9f9f9; border-left: 3px solid #cccccc; padding: 12px 16px; margin: 16px 0; }
  .angaben { font-size: 14px; color: #555555; border-collapse: collapse; }
  .angaben th { text-align: left; padding: 4px 16px 4px 0; vertical-align: top; font-weight: bold; }
  .angaben td { padding: 4px 0; vertical-align: top; }
  .fuss { font-size: 12px; color: #aaaaaa; text-align: center; padding: 12px; }
</style>
</head>
<body>
  <div class="rahmen">
    <div class="inhalt">
      <?= $inhalt ?? '' ?>
    </div>
    <?php if (!empty($signatur)): ?>
      <div class="fuss"><?= htmlspecialchars($signatur) ?></div>
    <?php endif; ?>
  </div>
</body>
</html>
