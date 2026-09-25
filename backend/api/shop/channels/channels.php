<?php
// backend/api/shop/channels/channels.php
//
// Verkaufskanäle als Module (dev/shop-verkaufskanaele.md, Schritt 4).
//
// Ein Kanal ist eine Datei channels/<art>.php. <Art> ist die Art mit großem
// Anfangsbuchstaben; das Modul stellt bereit:
//
//   shopChannel<Art>JobFunctions(): array
//       Auftragsarten, die der Kanal in batchjob_hugoshop abarbeitet
//   shopChannel<Art>RunJob($db, array $auftrag, callable $sagen, callable $fehler, array &$bilanz): void
//       arbeitet einen Auftrag ab und vermerkt das Ergebnis (shopJobResult);
//       wirft bei Fehlern — der Läufer vermerkt den Auftrag dann als
//       fehlgeschlagen und macht mit dem nächsten weiter (V13)
//   shopChannel<Art>Switched($db, bool $an): void
//       wird gerufen, wenn der Kanal ein- oder ausgeschaltet wurde
//
// Aufträge tragen ihren Kanal in batchjob_hugoshop.channel_id; NULL heißt
// HugoShop. Welche Kanäle es gibt, sagt SHOP_CHANNEL_TYPES: eine Art ohne
// Modul gibt es für OSERP nicht, auch wenn sie in sales_channel_shop steht.

/** Kanäle mit Modul, in der Reihenfolge der Umsetzung */
const SHOP_CHANNEL_TYPES = ['hugoshop', 'ebay'];

foreach (SHOP_CHANNEL_TYPES as $shopKanalArt) {
    require_once __DIR__.'/'.$shopKanalArt.'.php';
}
unset($shopKanalArt);

/**
 * Name einer Modulfunktion
 *
 * @param string $art Art des Kanals, etwa hugoshop
 * @param string $teil JobFunctions, RunJob oder Switched
 * @return string
 */
function shopChannelFunction(string $art, string $teil): string {
    return 'shopChannel'.ucfirst($art).$teil;
}

/**
 * Alle Paare aus Kanal und Auftragsart, als Liste für die Abfragen
 *
 * Die Auftragsabfragen filtern damit auf das, was ein Modul abarbeitet —
 * dieselbe Auftragsart kann es in zwei Kanälen geben, und fremde Aufträge
 * (die Tabelle stammt aus der Bridge) bleiben unberührt.
 *
 * @return string kommagetrennt, etwa "hugoshop:publish_part,hugoshop:publish_all"
 */
function shopChannelJobPairs(): string {
    $paare = [];
    foreach (SHOP_CHANNEL_TYPES as $art) {
        $funktionen = shopChannelFunction($art, 'JobFunctions');
        foreach ($funktionen() as $funktion) {
            $paare[] = $art.':'.$funktion;
        }
    }
    return implode(',', $paare);
}

/**
 * Übergibt einen Auftrag dem Modul seines Kanals
 *
 * @param object $db Company-Datenbankverbindung
 * @param array $auftrag Zeile aus shopOpenJobs(), mit channel
 * @param callable $sagen Fortschritt
 * @param callable $fehler Fehlermeldung, wird gezählt
 * @param array $bilanz Zähler des Laufs
 * @return void
 * @throws ApiError SHOP_CHANNEL_UNKNOWN
 */
function shopChannelRunJob($db, array $auftrag, callable $sagen, callable $fehler, array &$bilanz): void {
    $art = (string)($auftrag['channel'] ?? '');
    if (!in_array($art, SHOP_CHANNEL_TYPES, true)) {
        throw new ApiError('SHOP_CHANNEL_UNKNOWN', 'Unbekannter Verkaufskanal: '.$art);
    }
    $ausfuehren = shopChannelFunction($art, 'RunJob');
    $ausfuehren($db, $auftrag, $sagen, $fehler, $bilanz);
}

/**
 * Meldet dem Modul, dass sein Kanal ein- oder ausgeschaltet wurde
 *
 * @param object $db Company-Datenbankverbindung
 * @param string $art Art des Kanals
 * @param bool $an eingeschaltet
 * @return void
 */
function shopChannelSwitched($db, string $art, bool $an): void {
    $melden = shopChannelFunction($art, 'Switched');
    if (in_array($art, SHOP_CHANNEL_TYPES, true) && function_exists($melden)) {
        $melden($db, $an);
    }
}
