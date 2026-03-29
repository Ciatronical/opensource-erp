<?php
// backend/api/wiki/wiki.php
// Wiki / Wissensdatenbank API

/**
 * Hilfsfunktion: Slug aus Titel erzeugen
 */
function generateSlug($title) {
    $slug = mb_strtolower($title);
    // Umlaute ersetzen
    $slug = str_replace(
        ['ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü'],
        ['ae', 'oe', 'ue', 'ss', 'ae', 'oe', 'ue'],
        $slug
    );
    // Nur alphanumerische Zeichen und Bindestriche
    $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

// ============================================================================
// WIKI-SEITEN
// ============================================================================

/**
 * Laedt alle Wiki-Seiten (Uebersicht)
 *
 * @param array $data Optionale Filter: category_id, search
 * @testdata {"action": "getWikiPages"}
 * @testdata {"action": "getWikiPages", "category_id": 1}
 * @testdata {"action": "getWikiPages", "search": "test"}
 */
function getWikiPages($data) {
    $company = DbhCompany::begin();
    $pdo = $company->getPDO();

    $where = '';
    if (!empty($data['category_id'])) {
        $catId = intval($data['category_id']);
        $where .= " AND wp.category_id = $catId";
    }
    if (!empty($data['search'])) {
        $search = $pdo->quote('%' . $data['search'] . '%');
        $where .= " AND (wp.title ILIKE $search OR wp.content ILIKE $search)";
    }

    $query = <<<SQL
        SELECT json_build_object(
            'pages', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', wp.id,
                    'title', wp.title,
                    'slug', wp.slug,
                    'category_id', wp.category_id,
                    'category_name', wc.name,
                    'kba_hsn', wp.kba_hsn,
                    'kba_tsn', wp.kba_tsn,
                    'created_by', wp.created_by,
                    'created_by_name', e_creator.name,
                    'updated_by', wp.updated_by,
                    'updated_by_name', e_updater.name,
                    'itime', wp.itime,
                    'mtime', wp.mtime,
                    'content_preview', LEFT(wp.content, 200)
                ) ORDER BY wp.mtime DESC NULLS LAST, wp.itime DESC)
                FROM wiki_pages wp
                LEFT JOIN wiki_categories wc ON wc.id = wp.category_id
                LEFT JOIN employee e_creator ON e_creator.id = wp.created_by
                LEFT JOIN employee e_updater ON e_updater.id = wp.updated_by
                WHERE TRUE $where
            ), '[]'::json)
        ) AS result
    SQL;

    echo $company->get($query);
}

/**
 * Laedt eine einzelne Wiki-Seite
 *
 * @param array $data Mit 'id'
 * @testdata {"action": "getWikiPage", "id": 1}
 */
function getWikiPage($data) {
    $company = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungueltige ID');
        return;
    }

    $query = <<<SQL
        SELECT json_build_object(
            'page', (
                SELECT json_build_object(
                    'id', wp.id,
                    'title', wp.title,
                    'slug', wp.slug,
                    'content', wp.content,
                    'category_id', wp.category_id,
                    'category_name', wc.name,
                    'kba_hsn', wp.kba_hsn,
                    'kba_tsn', wp.kba_tsn,
                    'created_by', wp.created_by,
                    'created_by_name', e_creator.name,
                    'updated_by', wp.updated_by,
                    'updated_by_name', e_updater.name,
                    'itime', wp.itime,
                    'mtime', wp.mtime,
                    'revision_count', (SELECT COUNT(*) FROM wiki_revisions wr WHERE wr.page_id = wp.id)
                )
                FROM wiki_pages wp
                LEFT JOIN wiki_categories wc ON wc.id = wp.category_id
                LEFT JOIN employee e_creator ON e_creator.id = wp.created_by
                LEFT JOIN employee e_updater ON e_updater.id = wp.updated_by
                WHERE wp.id = $id
            )
        ) AS result
    SQL;

    echo $company->get($query);
}

/**
 * Erstellt oder aktualisiert eine Wiki-Seite
 * Beim Update wird der alte Stand automatisch als Revision gespeichert
 *
 * @param array $data Mit title, content, category_id, kba_hsn, kba_tsn; optional: id (fuer Update)
 * @testdata {"action": "saveWikiPage", "title": "Testseite", "content": "<p>Inhalt</p>"}
 * @testdata {"action": "saveWikiPage", "id": 1, "title": "Aktualisiert", "content": "<p>Neuer Inhalt</p>"}
 */
function saveWikiPage($data) {
    $company = DbhCompany::begin();
    $pdo = $company->getPDO();

    $employeeId = intval($data['employee_id'] ?? 0);

    $id = intval($data['id'] ?? 0);
    $title = trim($data['title'] ?? '');
    $content = $data['content'] ?? '';
    $categoryId = !empty($data['category_id']) ? intval($data['category_id']) : null;
    $kbaHsn = !empty($data['kba_hsn']) ? trim($data['kba_hsn']) : null;
    $kbaTsn = !empty($data['kba_tsn']) ? mb_substr(trim($data['kba_tsn']), 0, 3) : null;

    if (empty($title)) {
        resultInfo(false, 'MISSING_TITLE', 'Titel erforderlich');
        return;
    }

    $slug = generateSlug($title);

    $company->beginTransaction();

    try {
        if ($id > 0) {
            // UPDATE: Alten Stand als Revision speichern
            $oldPage = $company->fetch("SELECT title, content, updated_by FROM wiki_pages WHERE id = $id");
            if (!$oldPage) {
                $company->rollBack();
                resultInfo(false, 'NOT_FOUND', 'Seite nicht gefunden');
                return;
            }

            $oldTitle = $pdo->quote($oldPage['title']);
            $oldContent = $pdo->quote($oldPage['content'] ?? '');
            $oldEditor = intval($oldPage['updated_by'] ?? $employeeId);
            $company->query(
                "INSERT INTO wiki_revisions (page_id, title, content, edited_by) VALUES ($id, $oldTitle, $oldContent, $oldEditor)"
            );

            // Seite aktualisieren
            $qTitle = $pdo->quote($title);
            $qContent = $pdo->quote($content);
            $qSlug = $pdo->quote($slug);
            $qCatId = $categoryId !== null ? $categoryId : 'NULL';
            $qHsn = $kbaHsn !== null ? $pdo->quote($kbaHsn) : 'NULL';
            $qTsn = $kbaTsn !== null ? $pdo->quote($kbaTsn) : 'NULL';

            $company->query(
                "UPDATE wiki_pages SET title = $qTitle, slug = $qSlug, content = $qContent, " .
                "category_id = $qCatId, kba_hsn = $qHsn, kba_tsn = $qTsn, " .
                "updated_by = $employeeId, mtime = NOW() WHERE id = $id"
            );
        } else {
            // INSERT
            $qTitle = $pdo->quote($title);
            $qContent = $pdo->quote($content);
            $qSlug = $pdo->quote($slug);
            $qCatId = $categoryId !== null ? $categoryId : 'NULL';
            $qHsn = $kbaHsn !== null ? $pdo->quote($kbaHsn) : 'NULL';
            $qTsn = $kbaTsn !== null ? $pdo->quote($kbaTsn) : 'NULL';

            $result = $company->fetch(
                "INSERT INTO wiki_pages (title, slug, content, category_id, kba_hsn, kba_tsn, created_by, updated_by) " .
                "VALUES ($qTitle, $qSlug, $qContent, $qCatId, $qHsn, $qTsn, $employeeId, $employeeId) RETURNING id"
            );
            $id = $result['id'];
        }

        $company->commit();
        resultInfo(true, '', ['id' => $id]);

    } catch (Exception $e) {
        $company->rollBack();
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
    }
}

/**
 * Loescht eine Wiki-Seite (inkl. Revisionen via CASCADE)
 *
 * @param array $data Mit 'id'
 * @testdata {"action": "deleteWikiPage", "id": 1}
 */
function deleteWikiPage($data) {
    $company = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungueltige ID');
        return;
    }

    $company->query("DELETE FROM wiki_pages WHERE id = $id");
    resultInfo(true, 'DELETED');
}

// ============================================================================
// WIKI-KATEGORIEN
// ============================================================================

/**
 * Laedt alle Wiki-Kategorien
 *
 * @param array $data Keine Parameter
 * @testdata {"action": "getWikiCategories"}
 */
function getWikiCategories($data) {
    $company = DbhCompany::begin();

    $query = <<<SQL
        SELECT json_build_object(
            'categories', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', wc.id,
                    'name', wc.name,
                    'sortkey', wc.sortkey,
                    'page_count', (SELECT COUNT(*) FROM wiki_pages wp WHERE wp.category_id = wc.id)
                ) ORDER BY wc.sortkey, wc.name)
                FROM wiki_categories wc
            ), '[]'::json)
        ) AS result
    SQL;

    echo $company->get($query);
}

/**
 * Erstellt oder aktualisiert eine Wiki-Kategorie
 *
 * @param array $data Mit name, sortkey; optional: id (fuer Update)
 * @testdata {"action": "saveWikiCategory", "name": "Allgemein"}
 * @testdata {"action": "saveWikiCategory", "id": 1, "name": "Allgemein", "sortkey": 10}
 */
function saveWikiCategory($data) {
    $company = DbhCompany::begin();
    $pdo = $company->getPDO();

    $id = intval($data['id'] ?? 0);
    $name = trim($data['name'] ?? '');
    $sortkey = intval($data['sortkey'] ?? 0);

    if (empty($name)) {
        resultInfo(false, 'MISSING_NAME', 'Name erforderlich');
        return;
    }

    $qName = $pdo->quote($name);

    if ($id > 0) {
        $company->query("UPDATE wiki_categories SET name = $qName, sortkey = $sortkey, mtime = NOW() WHERE id = $id");
    } else {
        $result = $company->fetch("INSERT INTO wiki_categories (name, sortkey) VALUES ($qName, $sortkey) RETURNING id");
        $id = $result['id'];
    }

    resultInfo(true, '', ['id' => $id]);
}

/**
 * Aktualisiert die Sortierung mehrerer Kategorien (Batch)
 *
 * @param array $data Mit 'categories' als Array von {id, sortkey}
 * @testdata {"action": "updateCategorySortOrder", "categories": [{"id": 1, "sortkey": 10}, {"id": 2, "sortkey": 20}]}
 */
function updateCategorySortOrder($data) {
    $company = DbhCompany::begin();

    $categories = $data['categories'] ?? [];
    if (empty($categories) || !is_array($categories)) {
        resultInfo(false, 'INVALID_DATA', 'Keine Kategorien angegeben');
        return;
    }

    $company->beginTransaction();
    try {
        foreach ($categories as $cat) {
            $id = intval($cat['id'] ?? 0);
            $sortkey = intval($cat['sortkey'] ?? 0);
            if ($id > 0) {
                $company->query("UPDATE wiki_categories SET sortkey = $sortkey WHERE id = $id");
            }
        }
        $company->commit();
        resultInfo(true, 'SORT_UPDATED');
    } catch (Exception $e) {
        $company->rollBack();
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
    }
}

/**
 * Loescht eine Wiki-Kategorie
 *
 * @param array $data Mit 'id'
 * @testdata {"action": "deleteWikiCategory", "id": 1}
 */
function deleteWikiCategory($data) {
    $company = DbhCompany::begin();
    $id = intval($data['id'] ?? 0);

    if ($id <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungueltige ID');
        return;
    }

    $company->query("DELETE FROM wiki_categories WHERE id = $id");
    resultInfo(true, 'DELETED');
}

// ============================================================================
// WIKI-REVISIONEN
// ============================================================================

/**
 * Laedt Revisionen einer Wiki-Seite
 *
 * @param array $data Mit 'page_id'
 * @testdata {"action": "getWikiRevisions", "page_id": 1}
 */
function getWikiRevisions($data) {
    $company = DbhCompany::begin();
    $pageId = intval($data['page_id'] ?? 0);

    if ($pageId <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungueltige ID');
        return;
    }

    $query = <<<SQL
        SELECT json_build_object(
            'revisions', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', wr.id,
                    'title', wr.title,
                    'edited_by', wr.edited_by,
                    'edited_by_name', e.name,
                    'itime', wr.itime
                ) ORDER BY wr.itime DESC)
                FROM wiki_revisions wr
                LEFT JOIN employee e ON e.id = wr.edited_by
                WHERE wr.page_id = $pageId
            ), '[]'::json)
        ) AS result
    SQL;

    echo $company->get($query);
}

/**
 * Stellt eine Revision wieder her (wird zum aktuellen Stand)
 *
 * @param array $data Mit 'id' (revision_id)
 * @testdata {"action": "restoreWikiRevision", "id": 1}
 */
function restoreWikiRevision($data) {
    $company = DbhCompany::begin();
    $pdo = $company->getPDO();

    $employeeId = intval($data['employee_id'] ?? 0);
    $revisionId = intval($data['id'] ?? 0);

    if ($revisionId <= 0) {
        resultInfo(false, 'INVALID_ID', 'Ungueltige ID');
        return;
    }

    // Revision laden
    $revision = $company->fetch("SELECT page_id, title, content FROM wiki_revisions WHERE id = $revisionId");
    if (!$revision) {
        resultInfo(false, 'NOT_FOUND', 'Revision nicht gefunden');
        return;
    }

    $pageId = intval($revision['page_id']);

    $company->beginTransaction();

    try {
        // Aktuellen Stand als Revision speichern
        $oldPage = $company->fetch("SELECT title, content, updated_by FROM wiki_pages WHERE id = $pageId");
        $oldTitle = $pdo->quote($oldPage['title']);
        $oldContent = $pdo->quote($oldPage['content'] ?? '');
        $oldEditor = intval($oldPage['updated_by'] ?? $employeeId);
        $company->query(
            "INSERT INTO wiki_revisions (page_id, title, content, edited_by) VALUES ($pageId, $oldTitle, $oldContent, $oldEditor)"
        );

        // Revision als aktuellen Stand setzen
        $newTitle = $pdo->quote($revision['title']);
        $newContent = $pdo->quote($revision['content'] ?? '');
        $newSlug = $pdo->quote(generateSlug($revision['title']));
        $company->query(
            "UPDATE wiki_pages SET title = $newTitle, slug = $newSlug, content = $newContent, " .
            "updated_by = $employeeId, mtime = NOW() WHERE id = $pageId"
        );

        $company->commit();
        resultInfo(true, '', ['page_id' => $pageId]);

    } catch (Exception $e) {
        $company->rollBack();
        resultInfo(false, 'DATABASE_ERROR', $e->getMessage());
    }
}

// ============================================================================
// KBA-VERKNUEPFUNG (fuer LxCars)
// ============================================================================

/**
 * Laedt Wiki-Artikel fuer eine bestimmte KBA (HSN+TSN)
 *
 * @param array $data Mit 'hsn' und 'tsn'
 * @testdata {"action": "getWikiPagesByKba", "hsn": "0005", "tsn": "ABL"}
 */
function getWikiPagesByKba($data) {
    $company = DbhCompany::begin();
    $pdo = $company->getPDO();

    $hsn = trim($data['hsn'] ?? '');
    $tsn = mb_substr(trim($data['tsn'] ?? ''), 0, 3);

    if (empty($hsn) || strlen($tsn) < 3) {
        resultInfo(true, '', ['pages' => []]);
        return;
    }

    $qHsn = $pdo->quote($hsn);
    $qTsn = $pdo->quote($tsn);

    $query = <<<SQL
        SELECT json_build_object(
            'pages', COALESCE((
                SELECT json_agg(json_build_object(
                    'id', wp.id,
                    'title', wp.title,
                    'content', wp.content,
                    'category_name', wc.name,
                    'updated_by_name', e.name,
                    'mtime', wp.mtime,
                    'itime', wp.itime
                ) ORDER BY wp.mtime DESC NULLS LAST, wp.itime DESC)
                FROM wiki_pages wp
                LEFT JOIN wiki_categories wc ON wc.id = wp.category_id
                LEFT JOIN employee e ON e.id = wp.updated_by
                WHERE wp.kba_hsn = $qHsn AND wp.kba_tsn = $qTsn
            ), '[]'::json)
        ) AS result
    SQL;

    echo $company->get($query);
}
