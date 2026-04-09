<?php
// backend/api/docs/docs.php
// Dokumentation API — liest Markdown-Dateien aus docs/features/

/**
 * Gibt die Liste aller verfuegbaren Doku-Dateien zurueck
 *
 * @testdata {}
 */
function getDocsList() {
    $docsDir = __DIR__ . '/../../../docs/features';

    if (!is_dir($docsDir)) {
        resultInfo(false, 'DOCS_DIR_NOT_FOUND', 'Dokumentationsverzeichnis nicht gefunden');
        return;
    }

    $files = glob($docsDir . '/*.md');
    $docs = [];

    foreach ($files as $file) {
        $filename = basename($file, '.md');
        if ($filename === 'index') continue;

        // Titel aus erster Zeile lesen (# Titel)
        $firstLine = fgets(fopen($file, 'r'));
        $title = preg_replace('/^#\s+/', '', trim($firstLine)) ?: $filename;

        $docs[] = [
            'slug' => $filename,
            'title' => $title,
            'size' => filesize($file),
            'mtime' => date('Y-m-d H:i:s', filemtime($file)),
        ];
    }

    // Alphabetisch nach Titel sortieren
    usort($docs, fn($a, $b) => strcasecmp($a['title'], $b['title']));

    resultInfo(true, '', ['docs' => $docs]);
}

/**
 * Gibt den Inhalt einer Doku-Datei zurueck (Markdown)
 *
 * @param string $data['slug'] Dateiname ohne .md
 * @testdata {"slug": "anpr"}
 */
function getDoc($data) {
    $slug = $data['slug'] ?? null;

    if (!$slug || !preg_match('/^[a-z0-9_-]+$/i', $slug)) {
        resultInfo(false, 'INVALID_SLUG', 'Ungueltiger Dokumentname');
        return;
    }

    $file = __DIR__ . '/../../../docs/features/' . $slug . '.md';

    if (!file_exists($file)) {
        resultInfo(false, 'NOT_FOUND', 'Dokument nicht gefunden');
        return;
    }

    $content = file_get_contents($file);

    resultInfo(true, '', [
        'slug' => $slug,
        'content' => $content,
    ]);
}
