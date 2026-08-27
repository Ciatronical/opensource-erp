#!/usr/bin/env php
<?php
/**
 * Tafel-Watchdog — meldet per Telegram, wenn Sprachnotizen nicht mehr ankommen
 *
 * Hintergrund: Der Webhook (backend/webhook/telegram.php) antwortet Telegram sofort
 * mit 200 OK und verarbeitet erst danach. Schlaegt die Verarbeitung fehl, bleibt das
 * nach aussen unsichtbar — getWebhookInfo meldet weiterhin 0 Fehler, waehrend jede
 * Notiz verlorengeht. Genau dieser Fall trat am 13.08.2026 auf.
 *
 * Zwei Indikatoren:
 *   A) Verwaiste Audiodateien: _saveAudio() laeuft unmittelbar vor dem INSERT. Eine
 *      .ogg-Datei ohne zugehoerige voice_notes-Zeile bedeutet also, dass der INSERT
 *      fehlgeschlagen ist. Nur Dateien aelter als GRACE_MIN zaehlen, damit die
 *      Millisekunden zwischen Speichern und INSERT keinen Fehlalarm ausloesen.
 *   B) Neue "[TELEGRAM WEBHOOK]"-Zeilen im Apache-Error-Log seit dem letzten Lauf.
 *
 * Schutz vor Fehlalarmen — mehrfach abgesichert:
 *   - Ein Problem muss in SCHWELLE aufeinanderfolgenden Laeufen bestehen
 *   - Nach einem Alarm mindestens COOLDOWN_STD Stunden Ruhe
 *   - Karenz, wenn der Webhook-Code kuerzlich geaendert wurde (Deploy laeuft)
 *   - Karenz per Pause-Datei (siehe unten), fuer geplante Arbeiten
 *   - Beim allerersten Lauf wird nur die Ausgangslage gemerkt, nie alarmiert
 *   - Fehler im Watchdog selbst loesen niemals einen Alarm aus
 *
 * Es wird ausschliesslich bei Stoerungen benachrichtigt, nicht bei Entwarnung.
 *
 * Wartungsmodus (verhindert Alarme, z.B. vor groesseren Umbauten):
 *   touch /home/work/.local/state/tafel-watchdog.pause
 *   rm    /home/work/.local/state/tafel-watchdog.pause
 *
 * Empfaenger: defaults_oserp.telegram_alert_chat_id; fehlt der Wert, wird der Chat
 * mit den meisten bisherigen Sprachnotizen verwendet.
 *
 * Cron (alle 15 Minuten):
 *   *\/15 * * * * work cd /home/work/opensource-erp && php backend/cli/tafel-watchdog.php >> log/tafel-watchdog.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    die("Nur über die Kommandozeile ausführbar.\n");
}

// Trockenlauf: pruefen und den Alarmtext anzeigen, aber nichts senden und den
// Zustand nicht veraendern. Zum gefahrlosen Testen der Alarmkette.
$TROCKEN = in_array('--trocken', $argv, true);

const GRACE_MIN     = 10;   // Mindestalter verwaister Audiodateien (Minuten)
const SCHWELLE      = 2;    // so viele Laeufe in Folge muss das Problem bestehen
const COOLDOWN_STD  = 6;    // Mindestabstand zwischen zwei Alarmen (Stunden)
const CODE_KARENZ   = 30;   // keine Alarme, wenn telegram.php juenger ist (Minuten)

$ROOT       = dirname(__DIR__, 2);
$STATE_DIR  = getenv('HOME').'/.local/state';
$STATE_FILE = $STATE_DIR.'/tafel-watchdog.json';
$PAUSE_FILE = $STATE_DIR.'/tafel-watchdog.pause';
$ERRORLOG   = '/var/log/apache2/opensourceerp_error.log';

function protokoll(string $text): void {
    echo '['.date('Y-m-d H:i:s').'] '.$text."\n";
}

/** Zustand fortschreiben — im Trockenlauf bewusst folgenlos. */
function zustandSpeichern(string $datei, array $state, bool $trocken): void {
    if ($trocken) return;
    @file_put_contents($datei, json_encode($state));
    @chmod($datei, 0600);
}

// Ab hier darf nichts mehr hart abbrechen: ein Fehler im Watchdog ist kein
// Tafel-Problem und darf keinen Alarm erzeugen.
try {
    // config.php setzt die Zeitzone aus settings.ini — deshalb zuerst laden,
    // damit alle Protokollzeilen dieselbe (lokale) Uhrzeit tragen.
    require_once $ROOT.'/backend/api/config.php';
    require_once $ROOT.'/backend/api/logging.php';
    require_once $ROOT.'/backend/api/database.php';

    // ── Wartungsmodus ────────────────────────────────────────────────────────
    if (file_exists($PAUSE_FILE)) {
        protokoll('Pausiert (Wartungsmodus) — keine Pruefung.');
        exit(0);
    }

    if (!is_dir($STATE_DIR)) @mkdir($STATE_DIR, 0700, true);
    $state = [];
    if (is_readable($STATE_FILE)) {
        $state = json_decode((string)file_get_contents($STATE_FILE), true) ?: [];
    }
    $ersterLauf = empty($state);

    // ── Karenz: laeuft gerade ein Deploy? ────────────────────────────────────
    $webhook = $ROOT.'/backend/webhook/telegram.php';
    $codeFrisch = file_exists($webhook) && (time() - filemtime($webhook)) < CODE_KARENZ * 60;

    $auth = connectPDO(DB_HOST, DB_PORT, DB_AUTH_NAME, DB_AUTH_USER, DB_AUTH_PASS);
    $clients = $auth->query(
        "SELECT dbname, dbhost, dbport, dbuser, dbpasswd FROM auth.clients
          ORDER BY COALESCE(is_default, FALSE) DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $probleme = [];   // menschenlesbare Befunde
    $alarmChat = null;
    $alarmToken = null;

    foreach ($clients as $c) {
        try {
            $db = connectPDO($c['dbhost'], $c['dbport'], $c['dbname'], $c['dbuser'], $c['dbpasswd']);
        } catch (Throwable $e) {
            continue; // nicht erreichbarer Mandant ist kein Tafel-Problem
        }
        if (!$db->query("SELECT to_regclass('public.voice_notes') IS NOT NULL")->fetchColumn()) continue;

        // Nur Mandanten pruefen, die Telegram ueberhaupt nutzen. Die entschaerfte
        // Test-DB (telegram_enabled = 0) faellt dadurch automatisch heraus.
        $aktiv = $db->query("SELECT value FROM defaults_oserp WHERE key = 'telegram_enabled'")->fetchColumn();
        if ($aktiv !== '1' && $aktiv !== 't' && $aktiv !== 'true') continue;

        // Empfaenger und Token fuer den Alarm (erster aktiver Mandant gewinnt)
        if ($alarmToken === null) {
            $tok = $db->query("SELECT value FROM defaults_oserp WHERE key = 'telegram_bot_token'")->fetchColumn();
            if ($tok) {
                $alarmToken = $tok;
                $chat = $db->query("SELECT value FROM defaults_oserp WHERE key = 'telegram_alert_chat_id'")->fetchColumn();
                // Strikt pruefen: ein konfigurierter Wert gilt, auch wenn er wie "0"
                // aussieht. Ein lockeres !$chat wuerde solche Werte verwerfen und
                // faelschlich den Fallback ziehen.
                if ($chat === false || $chat === null || trim((string)$chat) === '') {
                    $chat = $db->query(
                        "SELECT telegram_chat_id FROM voice_notes WHERE telegram_chat_id IS NOT NULL
                          GROUP BY 1 ORDER BY COUNT(*) DESC LIMIT 1"
                    )->fetchColumn();
                }
                $alarmChat = ($chat === false || trim((string)$chat) === '') ? null : (string)$chat;
            }
        }

        // ── A) Verwaiste Audiodateien ────────────────────────────────────────
        $dir = $ROOT.'/backend/data/'.$c['dbname'].'/voicenotes/';
        if (is_dir($dir)) {
            $referenziert = [];
            foreach ($db->query("SELECT audio_file FROM voice_notes WHERE audio_file IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $referenziert[basename($p)] = true;
            }
            $grenze = time() - GRACE_MIN * 60;
            $verwaist = [];
            foreach (glob($dir.'*.ogg') ?: [] as $f) {
                if (!isset($referenziert[basename($f)]) && filemtime($f) < $grenze) {
                    $verwaist[] = basename($f);
                }
            }
            if ($verwaist) {
                $probleme[] = sprintf('%s: %d Sprachnachricht(en) empfangen, aber nicht gespeichert (%s%s)',
                    $c['dbname'], count($verwaist), $verwaist[0],
                    count($verwaist) > 1 ? ', …' : '');
            }
        }
    }

    // ── B) Neue Webhook-Fehler im Apache-Log ────────────────────────────────
    $logPos = (int)($state['logpos'] ?? 0);
    if (is_readable($ERRORLOG)) {
        clearstatcache(true, $ERRORLOG);
        $groesse = filesize($ERRORLOG);
        if ($groesse < $logPos) $logPos = 0;   // Datei wurde rotiert
        if ($groesse > $logPos) {
            $fh = @fopen($ERRORLOG, 'r');
            if ($fh) {
                fseek($fh, $logPos);
                $neu = 0; $beispiel = '';
                while (($zeile = fgets($fh)) !== false) {
                    if (stripos($zeile, '[TELEGRAM WEBHOOK]') !== false) {
                        $neu++;
                        if ($beispiel === '' && preg_match('/\[TELEGRAM WEBHOOK\][^\']{0,150}/', $zeile, $m)) {
                            $beispiel = trim($m[0]);
                        }
                    }
                }
                fclose($fh);
                if ($neu > 0) {
                    $probleme[] = sprintf('%d Webhook-Fehler im Log%s', $neu, $beispiel !== '' ? ': '.$beispiel : '');
                }
            }
        }
        $state['logpos'] = $groesse;
    }

    // ── Bewertung ───────────────────────────────────────────────────────────
    if ($ersterLauf) {
        $state['fehlerlaeufe'] = 0;
        $state['letzter_alarm'] = 0;
        zustandSpeichern($STATE_FILE, $state, $TROCKEN);
        protokoll('Erster Lauf — Ausgangslage gemerkt, kein Alarm.');
        exit(0);
    }

    if (!$probleme) {
        if (($state['fehlerlaeufe'] ?? 0) > 0) protokoll('Wieder unauffaellig — Zaehler zurueckgesetzt.');
        $state['fehlerlaeufe'] = 0;
        zustandSpeichern($STATE_FILE, $state, $TROCKEN);
        exit(0);
    }

    $state['fehlerlaeufe'] = (int)($state['fehlerlaeufe'] ?? 0) + 1;
    protokoll('Auffaellig ('.$state['fehlerlaeufe'].'/'.SCHWELLE.'): '.implode(' | ', $probleme));

    if ($codeFrisch) {
        protokoll('Kein Alarm: telegram.php wurde vor weniger als '.CODE_KARENZ.' Minuten geaendert (Deploy).');
        zustandSpeichern($STATE_FILE, $state, $TROCKEN);
        exit(0);
    }
    if (!$TROCKEN && $state["fehlerlaeufe"] < SCHWELLE) {
        protokoll('Kein Alarm: Schwelle noch nicht erreicht.');
        zustandSpeichern($STATE_FILE, $state, $TROCKEN);
        exit(0);
    }
    $seitAlarm = time() - (int)($state['letzter_alarm'] ?? 0);
    if (!$TROCKEN && $seitAlarm < COOLDOWN_STD * 3600) {
        protokoll('Kein Alarm: Ruhezeit laeuft noch ('.round($seitAlarm / 60).' von '.(COOLDOWN_STD * 60).' Minuten).');
        zustandSpeichern($STATE_FILE, $state, $TROCKEN);
        exit(0);
    }

    // ── Alarm senden ────────────────────────────────────────────────────────
    if (!$alarmToken || !$alarmChat) {
        protokoll('Alarm faellig, aber kein Bot-Token oder Empfaenger konfiguriert.');
        zustandSpeichern($STATE_FILE, $state, $TROCKEN);
        exit(0);
    }

    $text = "⚠️ Tafel: Sprachnotizen kommen nicht an\n\n"
          . implode("\n", array_map(fn($p) => '• '.$p, $probleme))
          . "\n\nDie Nachrichten sind nicht verloren — die Aufnahmen liegen auf dem Server "
          . "und können nachgetragen werden.\n"
          . 'Geprüft: '.date('d.m.Y H:i');

    if ($TROCKEN) {
        protokoll('TROCKENLAUF — es wird nichts gesendet. Empfaenger waere Chat '.$alarmChat.':');
        echo "----- Nachricht -----\n".$text."\n---------------------\n";
        exit(0);   // Zustand bewusst nicht speichern
    }

    $ch = curl_init("https://api.telegram.org/bot{$alarmToken}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['chat_id' => $alarmChat, 'text' => $text]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $antwort = curl_exec($ch);
    $rc = json_decode((string)$antwort, true);
    curl_close($ch);

    if ($rc['ok'] ?? false) {
        $state['letzter_alarm'] = time();
        protokoll('ALARM an Chat '.$alarmChat.' gesendet.');
    } else {
        // Nicht als gesendet markieren — beim naechsten Lauf erneut versuchen.
        protokoll('Alarm konnte nicht gesendet werden: '.substr((string)$antwort, 0, 200));
    }

    zustandSpeichern($STATE_FILE, $state, $TROCKEN);

} catch (Throwable $e) {
    // Watchdog-interner Fehler: protokollieren, aber niemals alarmieren.
    protokoll('Watchdog-Fehler (kein Alarm): '.$e->getMessage());
    exit(0);
}
