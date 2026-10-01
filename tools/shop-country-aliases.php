<?php
// tools/shop-country-aliases.php
//
// Erzeugt backend/upstall/shop/company_data/country_alias_shop.csv aus den
// ICU-Daten (PHP-Erweiterung intl): ISO-Codes und die Ländernamen in den 21
// Sprachen der Anwendung, bereinigt wie shop_country_key(). Namen, die in
// zwei Sprachen verschiedene Länder meinen, fehlen bewusst — sie ordnet der
// Betreiber in der Firmenkonfiguration zu (dev/shop-versand.md, Schritt 3).
//
// Die Länderliste selbst steht als INSERT in backend/upstall/shop/company_schema.sql;
// das Skript schreibt sie zusätzlich als country_shop.csv zum Abgleich.
//
//   php tools/shop-country-aliases.php backend/upstall/shop/company_data
// Erzeugt country_shop.csv und country_alias_shop.csv aus den ICU-Daten
$rb = ResourceBundle::create('de', 'ICUDATA-region');
$codes = [];
foreach ($rb['Countries'] as $code => $name) {
    if (preg_match('/^[A-Z]{2}$/', $code) && !in_array($code, ['ZZ','EU','EZ','UN','QO','XA','XB','XK','AC','CP','CQ','DG','EA','IC','TA'], true)) $codes[] = $code;
}
$codes = array_values(array_unique($codes)); sort($codes);
$eu = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];
$sprachen = ['de','en','cs','da','es','et','fi','fr','it','lt','lv','nb','nl','pl','pt','ro','ru','sv','tr','uk','zh'];
$schluessel = fn(string $t) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($t)));
$alias = []; $kollision = [];
$setze = function (string $a, string $code) use (&$alias, &$kollision, $schluessel) {
    $k = $schluessel($a);
    if ($k === '') return;
    if (isset($alias[$k]) && $alias[$k] !== $code) { $kollision[$k][] = $alias[$k]; $kollision[$k][] = $code; return; }
    $alias[$k] = $alias[$k] ?? $code;
};
// Reihenfolge = Vorrang bei Doppeldeutigkeit: Code, dann Deutsch, Englisch, übrige
foreach ($codes as $c) $setze($c, $c);
foreach ($sprachen as $l) foreach ($codes as $c) { $n = Locale::getDisplayRegion('-'.$c, $l); if ($n && $n !== $c) $setze($n, $c); }
// Gebräuchliche Kurzformen, die ICU nicht als Namen führt
foreach (['deutschland'=>'DE','brd'=>'DE','d'=>'DE','germany'=>'DE','österreich'=>'AT','oesterreich'=>'AT','a'=>'AT','schweiz'=>'CH','ch'=>'CH','usa'=>'US','u.s.a.'=>'US','uk'=>'GB','england'=>'GB','holland'=>'NL','niederlande'=>'NL','tschechien'=>'CZ','tschechische republik'=>'CZ'] as $a => $c) $setze($a, $c);
$f = fopen($argv[1].'/country_shop.csv', 'w'); fputcsv($f, ['iso_code','eu']);
foreach ($codes as $c) fputcsv($f, [$c, in_array($c, $eu, true) ? 't' : 'f']); fclose($f);
foreach (array_keys($kollision) as $k) unset($alias[$k]); // doppeldeutig: Durchsicht von Hand
ksort($alias);
$f = fopen($argv[1].'/country_alias_shop.csv', 'w'); fputcsv($f, ['alias','iso_code','manual']);
foreach ($alias as $a => $c) fputcsv($f, [$a, $c, 'f']); fclose($f);
fprintf(STDERR, "%d Länder, %d Zuordnungen, %d doppeldeutige Namen entfernt (Auszug: %s)\n", count($codes), count($alias), count($kollision), implode(', ', array_slice(array_map(fn($k) => $k.'→'.implode('/', array_unique($kollision[$k])), array_keys($kollision)), 0, 6)));
