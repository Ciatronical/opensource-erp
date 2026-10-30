<?php
// backend/api/portal/public/bootstrap.php
//
// Unterbau des öffentlichen Zugangs: hier landet jeder Aufruf der Webseite —
// von Kunden und Besuchern, die keine Mitarbeiter sind, keine
// OpensourceERP-Sitzung haben und keine Rechte besitzen.
//
// WARUM DIESER ZUGANG NICHT api/inc.php EINBINDET
// api.call.php prüft nur function_exists($action). Über inc.php ist auth.php
// immer geladen, damit wären login, logout, getClients, restoreSession und
// switchClient von hier aus aufrufbar. Der Zugang lädt deshalb nur, was er
// braucht, und lässt ausschließlich die Aktionen aus seiner Liste zu — die
// Liste ist die Grenze, nicht function_exists.
//
// MANDANT UND WEBSEITE
// Ohne Mitarbeiter-Sitzung gibt es keine auth.session_oserp, über die sich
// die Firmen-Datenbank ermitteln ließe. Stattdessen weist sich die Webseite
// mit ihrem Schlüssel aus (website_secret_oserp, public_key). Er bestimmt
// Mandant und Webseite. Das Muster stammt von backend/webhook/telegram.php.
//
// Der Schlüssel gehört in den Kopf X-Portal-Key und wird sinnvollerweise vom
// Reverse-Proxy im Docroot der Webseite gesetzt: dann kennt ihn der Browser
// nie. Der Name des Kopfes stammt aus der Zeit des reinen HugoShops und
// bleibt, damit laufende Weiterleiter weiterarbeiten.
//
// ERWEITERUNGEN
// Der Kern kennt Sitzung, Konto, Rechnungen, Kontakt und Freischaltung. Was
// eine Erweiterung auf der Webseite anbietet, steht in
// backend/api/<name>/public/actions.php mit <name>PublicActions(); der
// Unterbau lädt diese Datei für jede aktive Erweiterung des Mandanten, sobald
// die Datenbank bekannt ist. Jede Aktion bekommt vier Parameter:
//   ($db, $uuid, $daten, $website)

require_once __DIR__.'/../../error.php';
require_once __DIR__.'/../../config.php';
require_once __DIR__.'/../../logging.php';
require_once __DIR__.'/../../database.php';
require_once __DIR__.'/../../lib/extensions.php';
require_once __DIR__.'/../../lib/upstall.php';
// Bausteine des ERP, auf die der Kern aufsetzt. Sie bringen eigene Aktionen
// mit — erreichbar sind sie trotzdem nicht: darüber entscheidet allein die
// Aktionsliste.
require_once __DIR__.'/../../print/print.php';       // renderDocumentPdfFile
require_once __DIR__.'/../../print/template_engine.php';
require_once __DIR__.'/../../email/smtp.class.php';

require_once __DIR__.'/../lib/portal.php';
require_once __DIR__.'/actions.php';

/**
 * Verbindung zur Auth-Datenbank
 *
 * Eigene Verbindung statt DbhAuth: dessen ApiSession hängt am
 * Sitzungs-Cookie eines Mitarbeiters, den es hier nicht gibt.
 *
 * @return PDO
 */
function portalPublicAuthPdo(): PDO {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_AUTH_NAME),
        DB_AUTH_USER, DB_AUTH_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/**
 * Sucht Firmen-Datenbank und Webseite zum Schlüssel
 *
 * Geht die Mandanten durch und vergleicht die Schlüssel ihrer Webseiten
 * (website_secret_oserp, public_key) — jede Webseite hat ihren eigenen, der
 * Schlüssel bestimmt damit Mandant und Webseite. Der Vergleich läuft über
 * hash_equals, damit die Laufzeit nichts verrät; ein leerer Schlüssel wird
 * nie angenommen, sonst passte eine nicht eingerichtete Webseite auf jede
 * Anfrage.
 *
 * Auch eine abgeschaltete Webseite wird gefunden; abgelehnt wird dann in
 * portalPublicDispatch — mit einer Antwort, die den Grund nennt.
 *
 * @param string $schluessel Wert aus dem Kopf X-Portal-Key
 * @return array{pdo: PDO, website: int}|null Verbindung und Webseite, oder null
 */
function portalPublicFindCompany(string $schluessel): ?array {
    if ('' === $schluessel) {
        return null;
    }

    try {
        $auth = portalPublicAuthPdo();
        $mandanten = $auth->query("SELECT dbhost, dbport, dbname, dbuser, dbpasswd FROM auth.clients")
                          ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        writeLog('[PORTAL] Auth-Datenbank nicht erreichbar: '.$e->getMessage(), true, DLOG_ERR);
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

            $stmt = $pdo->prepare(
                "SELECT s.website_id, s.value
                   FROM website_secret_oserp s
                  WHERE s.key = 'public_key' AND s.value <> ''"
            );
            $stmt->execute();

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $zeile) {
                if (hash_equals((string)$zeile['value'], $schluessel)) {
                    return ['pdo' => $pdo, 'website' => (int)$zeile['website_id']];
                }
            }
        } catch (Exception $e) {
            // Ein Mandant mit altem Schema (website_secret_oserp fehlt) — kein
            // Grund, die Suche abzubrechen. Ein altes Schema muss aber
            // auffallen: seine Webseite antwortet bis zum Schema-Update mit
            // PORTAL_NOT_AUTHORIZED.
            if (str_contains($e->getMessage(), 'website_secret_oserp')) {
                writeLog('[PORTAL] Mandant '.$mandant['dbname'].': Schema ohne website_secret_oserp — '
                         .'Schema-Update nötig (Anmeldung eines Mitarbeiters)', true, DLOG_WRN);
            }
            continue;
        }
    }

    return null;
}

/**
 * Lädt die öffentlichen Aktionen der aktiven Erweiterungen
 *
 * Eine Erweiterung liefert backend/api/<name>/public/actions.php mit
 *   <name>PublicActions()      Namen der zugelassenen Aktionen (Pflicht)
 *   <name>SessionlessActions() Aktionen ohne Besuchersitzung (Verbindungstest)
 *   <name>PublicClosed($db, $website, $aktion)  true, wenn die Aktion gerade
 *                              abgelehnt wird (abgeschalteter Shop)
 *
 * @param object $db Firmen-Datenbankverbindung
 * @return array{actions: string[], sessionless: string[], closed: callable[]}
 */
function portalPublicRegistry($db): array {
    $register = [
        'actions'     => portalPublicActions(),
        'sessionless' => portalSessionlessActions(),
        'external'    => portalExternalActions(),
        'closed'      => [],
    ];

    // Erst die Haken (Warenkorb an der Sitzung, Rechnungen …), dann die Aktionen
    portalExtensionsLoad($db);
    foreach (getActiveExtensions($db) as $erweiterung) {
        if (!preg_match('/^[a-z0-9_]+$/', $erweiterung)) {
            continue;
        }
        $datei = __DIR__.'/../../'.$erweiterung.'/public/actions.php';
        if (!file_exists($datei)) {
            continue;
        }
        require_once $datei;

        $liste = $erweiterung.'PublicActions';
        if (function_exists($liste)) {
            $register['actions'] = array_merge($register['actions'], $liste());
        }
        $ohne = $erweiterung.'SessionlessActions';
        if (function_exists($ohne)) {
            $register['sessionless'] = array_merge($register['sessionless'], $ohne());
        }
        $aussen = $erweiterung.'ExternalActions';
        if (function_exists($aussen)) {
            $register['external'] = array_merge($register['external'], $aussen());
        }
        $zu = $erweiterung.'PublicClosed';
        if (function_exists($zu)) {
            $register['closed'][] = $zu;
        }
    }

    return $register;
}

/**
 * Setzt die Kopfzeilen für den Zugriff von der Webseite
 *
 * Ohne Eintrag in allowed_origins der Webseite wird nichts gesetzt: dann
 * läuft die Webseite über einen Reverse-Proxy unter derselben Adresse, und
 * Herkunftsprüfung erübrigt sich.
 *
 * @param object $db Firmen-Datenbankverbindung
 * @param int $website Webseite der Anfrage
 * @return bool true wenn die Anfrage von einer fremden, erlaubten Adresse kommt
 */
function portalPublicCors($db, int $website): bool {
    $erlaubt = array_filter(array_map('trim',
        explode(',', websiteValue($db, $website, 'allowed_origins'))));
    $herkunft = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (empty($erlaubt) || '' === $herkunft) {
        return false;
    }

    if (!in_array($herkunft, $erlaubt, true)) {
        // Bewusst ohne Kopfzeilen: der Browser bricht die Anfrage dann selbst
        // ab, und die Antwort verrät nicht, welche Adressen zugelassen sind.
        return false;
    }

    header('Access-Control-Allow-Origin: '.$herkunft);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Portal-Key');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Vary: Origin');
    return true;
}

/**
 * Die Sitzungskennung aus dem Cookie, sofern sie zu dieser Webseite gehört
 *
 * Nur die selbst vergebene Form wird angenommen. Ein Cookie aus fremder Hand
 * landete sonst als Kennung in der Tabelle und blähte sie auf. Eine Kennung
 * einer anderen Webseite unter demselben Host zählt nicht.
 *
 * @param object $db Firmen-Datenbankverbindung
 * @param int $website Webseite der Anfrage
 * @return string Kennung, '' ohne brauchbares Cookie
 */
function portalPublicContextUuidVorhanden($db, int $website): string {
    $vorhanden = $_COOKIE[PORTAL_CONTEXT_COOKIE] ?? '';
    if (!is_string($vorhanden) || !preg_match('/^[0-9a-f]{32}$/', $vorhanden)) {
        return '';
    }
    $fremd = $db->getOne(
        "SELECT 1 AS fremd FROM portal_context_oserp
          WHERE uuid = :uuid AND website_id <> CAST(:website AS integer)",
        [':uuid' => $vorhanden, ':website' => $website]
    );
    return $fremd ? '' : $vorhanden;
}

/**
 * Liefert die Kennung der Besuchersitzung und setzt notfalls das Cookie
 *
 * Die Kennung ist frei wählbar — sie kommt aus dem Cookie und damit vom
 * Aufrufer. Sie wird ausschließlich gebunden verwendet, nie in einen
 * SQL-Text gesetzt.
 *
 * Angelegt wird die Sitzung selbst erst von der Aktion getContext. Wer eine
 * andere Aktion zuerst ruft, bekommt PORTAL_CONTEXT_ERROR — die Widgets holen
 * daraufhin den Kontext und wiederholen.
 *
 * Eine Sitzung gehört genau einer Webseite. Kommt das Cookie mit der Sitzung
 * einer anderen — zwei Webseiten unter demselben Host teilen sich das Cookie
 * —, gibt es eine neue Kennung. Danach gilt: jede Kennung, mit der eine
 * Aktion gerufen wird, gehört zur Webseite der Anfrage, und die
 * Fachfunktionen müssen das nicht mehr prüfen.
 *
 * @param object $db Firmen-Datenbankverbindung
 * @param int $website Webseite der Anfrage
 * @param bool $fremdeHerkunft true wenn die Anfrage von einer anderen Adresse kommt
 * @return string Sitzungskennung
 */
function portalPublicContextUuid($db, int $website, bool $fremdeHerkunft): string {
    $vorhanden = portalPublicContextUuidVorhanden($db, $website);
    if ('' !== $vorhanden) {
        return $vorhanden;
    }

    $uuid = portalNewUuid();
    portalContextCookie($uuid, $fremdeHerkunft);
    return $uuid;
}

/**
 * Nimmt die Eingabedaten der Anfrage entgegen
 *
 * JSON-Rumpf wie im übrigen Backend, sonst POST und GET.
 *
 * @return array
 */
function portalPublicInput(): array {
    $rumpf = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($rumpf)) {
        return array_merge($_GET, $_POST, $rumpf);
    }
    return array_merge($_GET, $_POST);
}

/**
 * Führt die angeforderte Aktion aus
 *
 * @return void
 */
function portalPublicDispatch(): void {
    header('Content-Type: application/json');

    if (defined('OSERP_SETUP_MODE') && OSERP_SETUP_MODE) {
        http_response_code(503);
        resultInfo(false, 'SETUP_INCOMPLETE', null, 'Die Einrichtung ist nicht abgeschlossen');
        return;
    }

    OserpConfig::init();

    $treffer = portalPublicFindCompany((string)($_SERVER['HTTP_X_PORTAL_KEY'] ?? ''));
    if (null === $treffer) {
        // Aus der Antwort darf nicht hervorgehen, ob es den Mandanten gibt.
        http_response_code(403);
        resultInfo(false, 'PORTAL_NOT_AUTHORIZED', null, 'Kein gültiger Schlüssel');
        return;
    }

    $db = DbhCompany::begin($treffer['pdo']);
    // Die Webseite dieser Anfrage — jede Aktion bekommt sie als vierten
    // Parameter (actions.php)
    $website = $treffer['website'];
    $fremdeHerkunft = portalPublicCors($db, $website);

    // Vorabanfrage des Browsers: die Kopfzeilen stehen, mehr ist nicht nötig.
    if ('OPTIONS' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
        http_response_code(204);
        return;
    }

    // Das Portal läuft ohne Mitarbeiter-Sitzung: hier meldet sich niemand an,
    // der ein fälliges Schema-Update anstoßen würde. Ein anonymer Aufruf
    // darf keine Migration auslösen — also nur festhalten, dass diese Firma
    // eine braucht, sonst bliebe ein veraltetes Portal unbemerkt.
    if (upstallUpdateNeeded($db)) {
        writeLog('Portal-Zugang auf veraltetem Schema: Firmen-Datenbank braucht ein Upstall-Update', true, DLOG_WRN);
    }

    $daten = portalPublicInput();
    $aktion = (string)($daten['action'] ?? '');

    if ('' === $aktion) {
        resultInfo(false, 'API_ACTION_NOT_SPECIFIED', null, 'Keine Aktion angegeben');
        return;
    }

    try {
        $register = portalPublicRegistry($db);

        // Die Liste entscheidet, nicht function_exists: eine neue Fachfunktion
        // wird nicht dadurch öffentlich, dass jemand sie einbindet.
        if (!in_array($aktion, $register['actions'], true) || !function_exists($aktion)) {
            http_response_code(404);
            resultInfo(false, 'API_ACTION_NOT_ALLOWED', null, 'Diese Aktion gibt es hier nicht');
            return;
        }

        // Verbindungstest: keine Sitzung anlegen — sonst hinterließe jeder
        // Test eine Zeile in portal_context_oserp
        if (in_array($aktion, $register['sessionless'], true)) {
            $aktion($db, '', $daten, $website);
            return;
        }

        if (!websiteIsActive($db, $website)) {
            http_response_code(503);
            resultInfo(false, 'WEBSITE_INACTIVE', null, 'Diese Webseite ist abgeschaltet');
            return;
        }

        // Rücksprung von außen (PayPal): der Browser kommt von einer fremden
        // Seite, und bei dieser Navigation schickt er das Sitzungs-Cookie nicht
        // unbedingt mit. Hier keine neue Sitzung vergeben — das neue Cookie
        // ersetzte die angemeldete Sitzung, und der Kunde stünde abgemeldet
        // da (autoprofis24.de, 30.10.2026). Die Aktion holt ihre Sitzung
        // anderswoher (endPayment: reference_id der PayPal-Bestellung).
        $uuid = in_array($aktion, $register['external'], true)
            ? portalPublicContextUuidVorhanden($db, $website)
            : portalPublicContextUuid($db, $website, $fremdeHerkunft);

        // Abgeschalteter Shop: nichts Neues verkaufen. Was schon läuft — eine
        // begonnene Zahlung, Konto, Rechnungen — bleibt erreichbar.
        foreach ($register['closed'] as $geschlossen) {
            if ($geschlossen($db, $website, $aktion)) {
                resultInfo(false, 'SHOP_CLOSED', null, 'Der Shop nimmt derzeit keine Bestellungen an');
                return;
            }
        }

        $aktion($db, $uuid, $daten, $website);
    } catch (ApiError $e) {
        resultInfo(false, $e->getId(), null, $e->getMessage());
        if (null !== $e->getQuery()) {
            writeLog('[PORTAL] '.$e->getQuery(), true, DLOG_ERR);
        }
    } catch (PDOException $e) {
        writeLog('[PORTAL] Datenbankfehler: '.$e->getMessage(), true, DLOG_ERR);
        resultInfo(false, 'API_DATABASE_ERROR', null, dbFriendlyError($e));
    } catch (\Throwable $e) {
        writeLog('[PORTAL] '.$e->getMessage().' in '.basename($e->getFile()).':'.$e->getLine(), true, DLOG_ERR);
        resultInfo(false, 'API_INTERNAL_ERROR', null, 'Unerwarteter Fehler');
    }
}
