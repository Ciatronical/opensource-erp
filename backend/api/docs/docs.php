<?php
// backend/api/docs/docs.php
//
// Dokumentation: liest die Markdown-Dateien aus docs/features/. Jede Datei
// trägt YAML-Front-Matter (title, summary, group, extension, category, order,
// status, since, external, flag, kind) — daraus entstehen die Navigation nach
// Kernsystem und Erweiterungen sowie der maschinenlesbare Feature-Katalog,
// den die Website (opensource-erp.dev) übernimmt (docs/website-schnittstelle.md).
//
// Das Front Matter ist bewusst einfach gehalten (Schlüssel: Wert, Listen als
// [a, b]); ein vollständiger YAML-Parser ist dafür nicht nötig.

/**
 * Verzeichnis der Feature-Dokumentation
 */
function docsDir(): string {
    return realpath(__DIR__ . '/../../../docs/features') ?: (__DIR__ . '/../../../docs/features');
}

/**
 * Trennt Front Matter und Inhalt einer Markdown-Datei
 *
 * @param string $raw Dateiinhalt
 * @return array ['meta' => array, 'body' => string]
 */
function docsParseFrontMatter(string $raw): array {
    $meta = [];
    $body = $raw;
    if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?/s', $raw, $m)) {
        $body = substr($raw, strlen($m[0]));
        foreach (preg_split('/\r?\n/', $m[1]) as $line) {
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*):\s*(.*)$/', $line, $kv)) continue;
            $value = trim($kv[2]);
            if ($value === 'true')  $value = true;
            elseif ($value === 'false') $value = false;
            elseif (preg_match('/^\[(.*)\]$/', $value, $l)) {
                $value = array_values(array_filter(array_map('trim', explode(',', $l[1])), 'strlen'));
            } elseif (is_numeric($value)) {
                $value = $value + 0;
            } else {
                $value = trim($value, '"\'');
            }
            $meta[$kv[1]] = $value;
        }
    }
    return ['meta' => $meta, 'body' => ltrim($body, "\r\n")];
}

/**
 * Metadaten einer Datei: Front Matter, ergänzt um Titel aus der ersten
 * Überschrift und Vorgaben für Dateien ohne Front Matter
 *
 * @param string $file Pfad
 * @return array
 */
function docsReadMeta(string $file): array {
    $parsed = docsParseFrontMatter(file_get_contents($file) ?: '');
    $meta = $parsed['meta'];
    $slug = basename($file, '.md');

    if (empty($meta['title']) && preg_match('/^#\s+(.+)$/m', $parsed['body'], $h)) {
        $meta['title'] = trim($h[1]);
    }
    $meta += [
        'title'     => $slug,
        'summary'   => '',
        'group'     => 'core',
        'extension' => null,
        'category'  => '',
        'order'     => 999,
        'status'    => 'stable',
        'kind'      => 'feature',
    ];
    $meta['slug']  = $slug;
    $meta['size']  = filesize($file);
    $meta['mtime'] = date('Y-m-d H:i:s', filemtime($file));
    return ['meta' => $meta, 'body' => $parsed['body']];
}

/**
 * Alle Dokumente mit Metadaten, sortiert nach Gruppe, Rubrik, Reihenfolge, Titel
 *
 * @return array [['meta' => ..., 'body' => ...], ...]
 */
function docsAll(): array {
    $dir = docsDir();
    if (!is_dir($dir)) {
        throw new ApiError('DOCS_DIR_NOT_FOUND', 'Dokumentationsverzeichnis nicht gefunden');
    }
    $docs = [];
    foreach (glob($dir . '/*.md') ?: [] as $file) {
        if (basename($file) === 'index.md') continue;
        $docs[] = docsReadMeta($file);
    }
    usort($docs, function ($a, $b) {
        $ma = $a['meta']; $mb = $b['meta'];
        return [$ma['group'] === 'core' ? 0 : 1, (string)$ma['extension'], (int)$ma['order'], $ma['title']]
           <=> [$mb['group'] === 'core' ? 0 : 1, (string)$mb['extension'], (int)$mb['order'], $mb['title']];
    });
    return $docs;
}

/**
 * Erweiterungen mit Titel und Beschreibung aus backend/upstall/<name>/extension.json
 *
 * @return array name => ['name', 'title', 'icon', 'description']
 */
function docsExtensions(): array {
    $out = [];
    foreach (glob(upstallBaseDir() . '/*/extension.json') ?: [] as $file) {
        $json = json_decode(file_get_contents($file) ?: '', true);
        if (!is_array($json) || empty($json['name'])) continue;
        $out[$json['name']] = [
            'name'        => $json['name'],
            'title'       => $json['title'] ?? $json['name'],
            'icon'        => $json['icon'] ?? '',
            'description' => $json['description'] ?? '',
        ];
    }
    return $out;
}

/**
 * Gibt die Liste aller Doku-Seiten mit Metadaten zurück, dazu die bekannten
 * Erweiterungen — die Oberfläche gliedert daraus Kernsystem, Erweiterungen
 * und Rubriken.
 *
 * @testdata {}
 */
function getDocsList() {
    $docs = array_map(fn($d) => $d['meta'], docsAll());
    resultInfo(true, '', ['docs' => $docs, 'extensions' => array_values(docsExtensions())]);
}

/**
 * Gibt den Inhalt einer Doku-Seite zurück (Markdown ohne Front Matter) samt Metadaten
 *
 * @param string $data['slug'] Dateiname ohne .md
 * @testdata {"slug": "lxcars"}
 */
function getDoc($data) {
    $slug = $data['slug'] ?? null;
    if (!$slug || !preg_match('/^[a-z0-9_-]+$/i', $slug)) {
        resultInfo(false, 'INVALID_SLUG', 'Ungültiger Dokumentname');
        return;
    }
    $file = docsDir() . '/' . $slug . '.md';
    if (!file_exists($file)) {
        resultInfo(false, 'NOT_FOUND', 'Dokument nicht gefunden');
        return;
    }
    $doc = docsReadMeta($file);
    resultInfo(true, '', ['slug' => $slug, 'content' => $doc['body'], 'meta' => $doc['meta']]);
}

/**
 * Zerlegt einen Markdown-Text in Abschnitte (Überschriften mit erstem Absatz)
 *
 * Grundlage der maschinenlesbaren Feature-Liste: jede Überschrift ist ein
 * Feature bzw. eine Funktionsgruppe, der erste Absatz die Kurzbeschreibung,
 * die Aufzählungspunkte die Einzelfunktionen.
 *
 * @param string $body Markdown ohne Front Matter
 * @return array [['level', 'title', 'anchor', 'text', 'items', 'tags']]
 */
function docsSections(string $body): array {
    $sections = [];
    $current = null;
    $inCode = false;
    $partTag = null;   // "# Teil B — LxCars": alles darunter gehört zur Erweiterung
    foreach (preg_split('/\r?\n/', $body) as $line) {
        if (preg_match('/^```/', $line)) { $inCode = !$inCode; continue; }
        if ($inCode) continue;
        if (preg_match('/^(#{1,4})\s+(.+?)\s*$/', $line, $h)) {
            if ($current) $sections[] = $current;
            $title = trim($h[2]);
            if (strlen($h[1]) === 1) {
                $partTag = preg_match('/^Teil\s+[A-Z]\s+[—–-]+\s+(.+)$/u', $title, $pt) && stripos($pt[1], 'kern') === false
                    ? trim($pt[1]) : null;
            }
            // Kennzeichnungen wie *(LxCars)*, *(extern)*, *(Schalter `x`)* herauslösen
            $tags = [];
            if (preg_match_all('/\*\(([^)]+)\)\*/', $title, $t)) {
                foreach ($t[1] as $tag) $tags[] = trim(strip_tags($tag), '` ');
                $title = trim(preg_replace('/\s*\*\([^)]+\)\*/', '', $title));
            }
            if ($partTag !== null && !in_array($partTag, $tags, true) && strlen($h[1]) > 1) $tags[] = $partTag;
            // Nummerierung "1.2 " entfernen
            $title = preg_replace('/^\d+(?:\.\d+)?[a-z]?\.?\s+/', '', $title);
            $anchor = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', $title), '-'));
            $current = ['level' => strlen($h[1]), 'title' => $title, 'anchor' => $anchor, 'text' => '', 'items' => [], 'tags' => $tags];
            continue;
        }
        if (!$current) continue;
        $trim = trim($line);
        if ($trim === '' || $trim === '---') continue;
        if (preg_match('/^[-*]\s+(.+)$/', $trim, $li)) {
            $current['items'][] = docsPlainText($li[1]);
        } elseif ($current['text'] === '' && !preg_match('/^(\||>|\d+\.\s)/', $trim)) {
            $current['text'] = docsPlainText($trim);
        }
    }
    if ($current) $sections[] = $current;
    return $sections;
}

/**
 * Markdown-Auszeichnung aus einer Zeile entfernen (für Kurztexte im Katalog)
 */
function docsPlainText(string $text): string {
    $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text);
    $text = preg_replace('/[*_`]+/', '', $text);
    return trim($text);
}

/**
 * Der Feature-Katalog: maschinenlesbare Fassung der gesamten Dokumentation
 *
 * Für die Website und für den Export. Enthält je Seite die Metadaten, die
 * Abschnitte (Überschrift, Kurztext, Einzelpunkte, Kennzeichnungen) und den
 * vollständigen Markdown-Text, dazu die Erweiterungen und die Version.
 *
 * @return array
 */
function docsCatalog(): array {
    $version = trim((string)@shell_exec('cd ' . escapeshellarg(dirname(docsDir(), 2)) . ' && git describe --tags --always 2>/dev/null'));
    $pages = [];
    foreach (docsAll() as $doc) {
        $meta = $doc['meta'];
        $pages[] = [
            'slug'      => $meta['slug'],
            'title'     => $meta['title'],
            'summary'   => $meta['summary'],
            'group'     => $meta['group'],
            'extension' => $meta['extension'],
            'category'  => $meta['category'],
            'order'     => (int)$meta['order'],
            'status'    => $meta['status'],
            'since'     => $meta['since'] ?? null,
            'external'  => !empty($meta['external']),
            'flag'      => $meta['flag'] ?? null,
            'kind'      => $meta['kind'],
            'keywords'  => is_array($meta['keywords'] ?? null) ? $meta['keywords'] : [],
            'updated'   => $meta['mtime'],
            'sections'  => docsSections($doc['body']),
            'markdown'  => $doc['body'],
        ];
    }
    return [
        'schema'       => 'opensource-erp.feature-catalog/1',
        'generated_at' => date(DATE_ATOM),
        'version'      => $version,
        'source'       => 'docs/features',
        'extensions'   => array_values(docsExtensions()),
        'pages'        => $pages,
    ];
}

/**
 * Liefert den Feature-Katalog als JSON (Vorschau und Export)
 *
 * @testdata {}
 */
function getDocsCatalog($data) {
    resultInfo(true, '', ['catalog' => docsCatalog()]);
}

/**
 * Einstellungen der Website-Veröffentlichung lesen (Adresse, ob ein Schlüssel
 * hinterlegt ist, letzte Veröffentlichung) — nur Systemadministratoren
 *
 * @testdata {}
 */
function getDocsPublishSettings($data) {
    requireSystemAdmin();
    $db = DbhCompany::begin();
    $row = $db->getOne(
        "SELECT json_build_object(
            'url',       COALESCE((SELECT value FROM defaults_oserp WHERE key = 'website_publish_url'), ''),
            'has_token', COALESCE((SELECT value FROM defaults_oserp WHERE key = 'website_publish_token'), '') <> '',
            'last',      (SELECT value FROM defaults_oserp WHERE key = 'website_publish_last')
         ) AS result"
    );
    $result = json_decode($row['result'] ?? '{}', true);
    $result['last'] = json_decode((string)($result['last'] ?? ''), true);
    resultInfo(true, '', ['results' => $result]);
}

/**
 * Feature-Katalog auf der Website veröffentlichen
 *
 * Schickt den Katalog als JSON per POST an die eingestellte Adresse
 * (Kopf `Authorization: Bearer <Schlüssel>`); die Website antwortet mit
 * JSON `{"success": true, ...}`. Adresse und Schlüssel werden mitgegeben
 * und gespeichert (ein leerer Schlüssel behält den gespeicherten). Das
 * Ergebnis bleibt als letzte Veröffentlichung stehen. Schnittstelle:
 * docs/website-schnittstelle.md. Nur Systemadministratoren.
 *
 * @param string $data['url']   Adresse der Website-Schnittstelle (optional, sonst gespeichert)
 * @param string $data['token'] Zugangsschlüssel (optional, leer = gespeicherten behalten)
 * @testdata {"url": "https://opensource-erp.dev/api/features", "token": ""}
 */
function publishDocsToWebsite($data) {
    requireSystemAdmin();
    set_time_limit(120);
    $db = DbhCompany::begin();

    $url   = trim((string)($data['url'] ?? ''));
    $token = trim((string)($data['token'] ?? ''));

    // Einstellungen übernehmen (leerer Schlüssel = bestehenden behalten) und
    // zugleich die gültigen Werte lesen — eine Anweisung
    $row = $db->getOne(
        "WITH neu AS (
            INSERT INTO defaults_oserp (key, value)
            SELECT k, v FROM (VALUES ('website_publish_url', :url), ('website_publish_token', :token)) x(k, v)
            WHERE v <> ''
            ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value
            RETURNING key
         )
         SELECT COALESCE((SELECT value FROM defaults_oserp WHERE key = 'website_publish_url'), '')   AS url,
                COALESCE((SELECT value FROM defaults_oserp WHERE key = 'website_publish_token'), '') AS token
         FROM (SELECT COUNT(*) FROM neu) n",
        [':url' => $url, ':token' => $token]
    );
    $url   = (string)($row['url'] ?? '');
    $token = (string)($row['token'] ?? '');

    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        throw new ApiError('WEBSITE_URL_MISSING', 'Adresse der Website-Schnittstelle fehlt oder ist ungültig');
    }

    $catalog = docsCatalog();
    $body    = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => array_values(array_filter([
            'Content-Type: application/json',
            'Accept: application/json',
            'X-OSERP-Version: ' . $catalog['version'],
            $token !== '' ? 'Authorization: Bearer ' . $token : null,
        ])),
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $answer  = json_decode((string)$response, true);
    $ok      = $error === '' && $code >= 200 && $code < 300 && (!is_array($answer) || ($answer['success'] ?? true) !== false);
    $message = $error !== '' ? $error
             : (is_array($answer) ? (string)($answer['message'] ?? $answer['text'] ?? '') : mb_substr((string)$response, 0, 300));

    $last = [
        'at'       => date(DATE_ATOM),
        'ok'       => $ok,
        'http'     => $code,
        'message'  => $message,
        'pages'    => count($catalog['pages']),
        'sections' => array_sum(array_map(fn($p) => count($p['sections']), $catalog['pages'])),
        'version'  => $catalog['version'],
    ];
    $db->execute(
        "INSERT INTO defaults_oserp (key, value) VALUES ('website_publish_last', :v)
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value",
        [':v' => json_encode($last, JSON_UNESCAPED_UNICODE)]
    );

    if (!$ok) {
        throw new ApiError('WEBSITE_PUBLISH_FAILED', 'Website antwortet mit HTTP ' . $code . ($message !== '' ? ': ' . $message : ''));
    }
    resultInfo(true, '', ['results' => $last]);
}
