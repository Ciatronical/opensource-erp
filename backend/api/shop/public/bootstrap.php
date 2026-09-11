<?php
// backend/api/shop/public/bootstrap.php
//
// Unterbau des oeffentlichen Shop-Zugangs. Hier landet jeder Aufruf der
// Shop-Webseite — von Besuchern, die keine Mitarbeiter sind, keine
// OpensourceERP-Sitzung haben und keine Rechte besitzen.
//
// WARUM DIESER ZUGANG NICHT api/inc.php EINBINDET
// api.call.php prueft nur function_exists($action). Ueber inc.php ist
// auth.php immer geladen, damit waeren login, logout, getClients,
// restoreSession und switchClient von hier aus aufrufbar. Der Zugang laedt
// deshalb nur, was er braucht, und laesst ausschliesslich die Aktionen aus
// seiner Liste zu — die Liste ist die Grenze, nicht function_exists.
//
// MANDANT
// Ohne Mitarbeiter-Sitzung gibt es keine auth.session_oserp, ueber die sich
// die Firmen-Datenbank ermitteln liesse. Stattdessen weist sich die
// Shop-Webseite mit dem Shop-Schluessel aus (defaults_oserp.shop_public_key).
// Das Muster stammt von backend/webhook/telegram.php.
//
// Der Schluessel gehoert in den Kopf X-Shop-Key und wird sinnvollerweise vom
// Reverse-Proxy im Shop-Docroot gesetzt: dann kennt ihn der Browser nie.

define('SHOP_CONTEXT_COOKIE', 'HUGOSHOPCLIENTID');

require_once __DIR__.'/../../error.php';
require_once __DIR__.'/../../config.php';
require_once __DIR__.'/../../logging.php';
require_once __DIR__.'/../../database.php';
// Bausteine des ERP, auf die die Erweiterung aufsetzt. Sie bringen eigene
// Aktionen mit — erreichbar sind sie trotzdem nicht: darueber entscheidet
// allein die Liste in shopPublicActions().
require_once __DIR__.'/../../faktura/faktura.php';   // postArInvoiceToLedger
require_once __DIR__.'/../../print/print.php';       // renderDocumentPdfFile
require_once __DIR__.'/../../print/template_engine.php';
require_once __DIR__.'/../../email/smtp.class.php';

require_once __DIR__.'/../lib/config.php';
require_once __DIR__.'/../lib/context.php';
require_once __DIR__.'/../lib/cart.php';
require_once __DIR__.'/../lib/account.php';
require_once __DIR__.'/../lib/search.php';
require_once __DIR__.'/../lib/invoice.php';
require_once __DIR__.'/../lib/mail.php';
require_once __DIR__.'/../lib/payment.php';
require_once __DIR__.'/../lib/analytics.php';
require_once __DIR__.'/../lib/withdrawal.php';
require_once __DIR__.'/../lib/redirect.php';

/**
 * Verbindung zur Auth-Datenbank
 *
 * Eigene Verbindung statt DbhAuth: dessen ApiSession haengt am
 * Sitzungs-Cookie eines Mitarbeiters, den es hier nicht gibt.
 *
 * @return PDO
 */
function shopPublicAuthPdo(): PDO {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_AUTH_NAME),
        DB_AUTH_USER, DB_AUTH_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/**
 * Sucht die Firmen-Datenbank zum Shop-Schluessel
 *
 * Geht die Mandanten durch und vergleicht deren shop_public_key. Der
 * Vergleich laeuft ueber hash_equals, damit die Laufzeit nichts verraet; ein
 * leerer Schluessel wird nie angenommen, sonst passte ein nicht
 * eingerichteter Mandant auf jede Anfrage.
 *
 * @param string $schluessel Wert aus dem Kopf X-Shop-Key
 * @return PDO|null Verbindung zur Firmen-Datenbank, oder null
 */
function shopPublicFindCompany(string $schluessel): ?PDO {
    if ('' === $schluessel) {
        return null;
    }

    try {
        $auth = shopPublicAuthPdo();
        $mandanten = $auth->query("SELECT dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients")
                          ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        writeLog('[SHOP] Auth-Datenbank nicht erreichbar: '.$e->getMessage(), true, DLOG_ERR);
        return null;
    }

    foreach ($mandanten as $mandant) {
        try {
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s',
                        $mandant['dbhost'], $mandant['dbport'], $mandant['dbname']),
                $mandant['dbuser'], $mandant['dbpasswd']
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT value FROM defaults_oserp WHERE key = 'shop_public_key'");
            $stmt->execute();
            $zeile = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($zeile && '' !== (string)$zeile['value']
                && hash_equals((string)$zeile['value'], $schluessel)) {
                return $pdo;
            }
        } catch (Exception $e) {
            // Ein Mandant ohne defaults_oserp ist noch nicht eingerichtet —
            // kein Grund, die Suche abzubrechen.
            continue;
        }
    }

    return null;
}

/**
 * Setzt die Kopfzeilen fuer den Zugriff von der Shop-Webseite
 *
 * Ohne Eintrag in shop_allowed_origins wird nichts gesetzt: dann laeuft der
 * Shop ueber einen Reverse-Proxy unter derselben Adresse, und
 * Herkunftspruefung erübrigt sich.
 *
 * @param object $db Firmen-Datenbankverbindung
 * @return bool true wenn die Anfrage von einer fremden, erlaubten Adresse kommt
 */
function shopPublicCors($db): bool {
    $erlaubt = array_filter(array_map('trim',
        explode(',', shopConfigValue($db, 'shop_allowed_origins'))));
    $herkunft = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (empty($erlaubt) || '' === $herkunft) {
        return false;
    }

    if (!in_array($herkunft, $erlaubt, true)) {
        // Bewusst ohne Kopfzeilen: der Browser bricht die Anfrage dann selbst
        // ab, und die Antwort verraet nicht, welche Adressen zugelassen sind.
        return false;
    }

    header('Access-Control-Allow-Origin: '.$herkunft);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Shop-Key');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Vary: Origin');
    return true;
}

/**
 * Liefert die Kennung der Besuchersitzung und setzt notfalls das Cookie
 *
 * Die Kennung ist frei waehlbar — sie kommt aus dem Cookie und damit vom
 * Aufrufer. Sie wird ausschliesslich gebunden verwendet, nie in einen
 * SQL-Text gesetzt.
 *
 * Angelegt wird die Sitzung selbst erst von der Aktion getContext. Wer eine
 * andere Aktion zuerst ruft, bekommt SHOP_CONTEXT_ERROR — der Shop-Client
 * holt daraufhin den Kontext und wiederholt.
 *
 * @param bool $fremdeHerkunft true wenn die Anfrage von einer anderen Adresse kommt
 * @return string Sitzungskennung
 */
function shopPublicContextUuid(bool $fremdeHerkunft): string {
    $vorhanden = $_COOKIE[SHOP_CONTEXT_COOKIE] ?? '';

    // Nur die selbst vergebene Form annehmen. Ein Cookie aus fremder Hand
    // landet sonst als Kennung in der Tabelle und bläht sie auf.
    if (is_string($vorhanden) && preg_match('/^[0-9a-f]{32}$/', $vorhanden)) {
        return $vorhanden;
    }

    $uuid = shopNewContextUuid();
    setcookie(SHOP_CONTEXT_COOKIE, $uuid, [
        'expires'  => 0,
        'path'     => '/',
        'secure'   => $fremdeHerkunft || !empty($_SERVER['HTTPS']),
        'httponly' => true,
        // Bei Zugriff von einer anderen Adresse schickt der Browser das
        // Cookie nur mit None; im Proxy-Betrieb bleibt Strict.
        'samesite' => $fremdeHerkunft ? 'None' : 'Strict',
    ]);
    $_COOKIE[SHOP_CONTEXT_COOKIE] = $uuid;
    return $uuid;
}

/**
 * Nimmt die Eingabedaten der Anfrage entgegen
 *
 * JSON-Rumpf wie im uebrigen Backend, sonst POST und GET.
 *
 * @return array
 */
function shopPublicInput(): array {
    $rumpf = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($rumpf)) {
        return array_merge($_GET, $_POST, $rumpf);
    }
    return array_merge($_GET, $_POST);
}

/**
 * Fuehrt die angeforderte Aktion aus
 *
 * @param array $erlaubteAktionen Namen der zugelassenen Aktionen
 * @return void
 */
function shopPublicDispatch(array $erlaubteAktionen): void {
    header('Content-Type: application/json');

    if (defined('OSERP_SETUP_MODE') && OSERP_SETUP_MODE) {
        http_response_code(503);
        resultInfo(false, 'SETUP_INCOMPLETE', null, 'Die Einrichtung ist nicht abgeschlossen');
        return;
    }

    OserpConfig::init();

    $pdo = shopPublicFindCompany((string)($_SERVER['HTTP_X_SHOP_KEY'] ?? ''));
    if (null === $pdo) {
        // Aus der Antwort darf nicht hervorgehen, ob es den Mandanten gibt.
        http_response_code(403);
        resultInfo(false, 'SHOP_NOT_AUTHORIZED', null, 'Kein gueltiger Shop-Schluessel');
        return;
    }

    $db = DbhCompany::begin($pdo);
    $fremdeHerkunft = shopPublicCors($db);

    // Vorabanfrage des Browsers: die Kopfzeilen stehen, mehr ist nicht noetig.
    if ('OPTIONS' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
        http_response_code(204);
        return;
    }

    $daten = shopPublicInput();
    $aktion = (string)($daten['action'] ?? '');

    if ('' === $aktion) {
        resultInfo(false, 'API_ACTION_NOT_SPECIFIED', null, 'Keine Aktion angegeben');
        return;
    }

    // Die Liste entscheidet, nicht function_exists: eine neue Fachfunktion
    // wird nicht dadurch oeffentlich, dass jemand sie einbindet.
    if (!in_array($aktion, $erlaubteAktionen, true) || !function_exists($aktion)) {
        http_response_code(404);
        resultInfo(false, 'API_ACTION_NOT_ALLOWED', null, 'Diese Aktion gibt es hier nicht');
        return;
    }

    $uuid = shopPublicContextUuid($fremdeHerkunft);

    try {
        $aktion($db, $uuid, $daten);
    } catch (ApiError $e) {
        resultInfo(false, $e->getId(), null, $e->getMessage());
        if (null !== $e->getQuery()) {
            writeLog('[SHOP] '.$e->getQuery(), true, DLOG_ERR);
        }
    } catch (PDOException $e) {
        writeLog('[SHOP] Datenbankfehler: '.$e->getMessage(), true, DLOG_ERR);
        resultInfo(false, 'API_DATABASE_ERROR', null, dbFriendlyError($e));
    } catch (\Throwable $e) {
        writeLog('[SHOP] '.$e->getMessage().' in '.basename($e->getFile()).':'.$e->getLine(), true, DLOG_ERR);
        resultInfo(false, 'API_INTERNAL_ERROR', null, 'Unerwarteter Fehler');
    }
}
