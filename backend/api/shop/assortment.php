<?php
// backend/api/shop/assortment.php
//
// Ansicht „Sortiment“ der Shop-Erweiterung (dev/shop-sortiment.md): Gruppen
// und Kategorien des Shops. Ein Artikel wählt seine Kategorie in der
// Artikelkarte aus dieser Liste, die Gruppe gehört der Kategorie.
//
// Lesen dürfen alle, die Shop-Artikel pflegen; ändern nur mit dem Recht für
// die Shop-Einrichtung — eine umbenannte Kategorie ändert Adressen auf der
// Webseite.
//
// Was sich auf der Webseite niederschlägt, stoßen Trigger an
// (company_schema.sql): umbenannte Kategorie → Produktseiten ihrer Artikel neu,
// jede andere Änderung → Auftrag write_categories für die Kategorieübersicht.

/** Name aus der Eingabe: ohne Ränder, höchstens 120 Zeichen */
function shopAssortmentName($wert): string {
    return mb_substr(trim((string)$wert), 0, 120);
}

/**
 * Gruppen und Kategorien des Sortiments
 *
 * Je Kategorie die Zahl der Artikel, die sie tragen, und davon die in einem
 * eingeschalteten Kanal angebotenen — nur diese erscheinen in der
 * Kategorieübersicht der Webseite.
 *
 * @return void groups (id, name, sortkey), categories (id, name, group_id,
 *              topseller, parts, offered)
 * @testdata {}
 */
function getShopAssortment($data) {
    permit(['shop_part_edit', 'edit_shop_config'], false);
    $db = DbhCompany::begin();

    $zeile = $db->getOne(
        "SELECT
            (SELECT COALESCE(json_agg(json_build_object('id', g.id, 'name', g.name, 'sortkey', g.sortkey)
                                      ORDER BY g.sortkey NULLS LAST, lower(g.name)), '[]')
               FROM category_group_shop g) AS groups,
            (SELECT COALESCE(json_agg(json_build_object(
                        'id', c.id, 'name', c.name, 'group_id', c.group_id, 'topseller', c.topseller,
                        'parts', (SELECT count(*) FROM parts_ext pe WHERE pe.hugoshop_category_id = c.id),
                        'offered', (SELECT count(DISTINCT pe.parts_id)
                                      FROM parts_ext pe
                                      JOIN parts_channel_shop pc ON pc.parts_id = pe.parts_id AND pc.active
                                      JOIN sales_channel_shop k ON k.id = pc.channel_id AND k.active AND k.type = 'hugoshop'
                                     WHERE pe.hugoshop_category_id = c.id))
                        ORDER BY lower(c.name)), '[]')
               FROM category_shop c) AS categories"
    );

    resultInfo(true, '', [
        'groups'     => json_decode((string)($zeile['groups'] ?? '[]'), true) ?: [],
        'categories' => json_decode((string)($zeile['categories'] ?? '[]'), true) ?: [],
    ]);
}

/**
 * Legt eine Gruppe an oder benennt sie um
 *
 * Neue Gruppen kommen ans Ende der Reihenfolge.
 *
 * @param int $data['id'] Gruppe; leer oder 0 = neu
 * @param string $data['name'] Name, wie er auf der Webseite steht
 * @return void group (id, name, sortkey)
 * @testdata {"id": 0, "name": "Werkstattbedarf"}
 */
function saveShopCategoryGroup($data) {
    permit(['edit_shop_config']);
    $db = DbhCompany::begin();

    $name = shopAssortmentName($data['name'] ?? '');
    if ('' === $name) {
        resultInfo(false, 'ASSORTMENT_NAME_REQUIRED', null, 'Die Gruppe braucht einen Namen');
        return;
    }

    try {
        $zeile = $db->getOne(
            "WITH neu AS (
                 INSERT INTO category_group_shop (name, sortkey)
                 SELECT :name_neu, COALESCE((SELECT max(sortkey) FROM category_group_shop), 0) + 1
                  WHERE CAST(:id_neu AS integer) = 0
                 RETURNING id, name, sortkey
             ), alt AS (
                 UPDATE category_group_shop
                    SET name = :name_alt, mtime = now()
                  WHERE id = CAST(:id_alt AS integer) AND CAST(:id_pruefung AS integer) <> 0
                 RETURNING id, name, sortkey
             )
             SELECT row_to_json(x) AS gruppe FROM (SELECT * FROM neu UNION ALL SELECT * FROM alt) x",
            [
                ':name_neu'    => $name,
                ':id_neu'      => (int)($data['id'] ?? 0),
                ':name_alt'    => $name,
                ':id_alt'      => (int)($data['id'] ?? 0),
                ':id_pruefung' => (int)($data['id'] ?? 0),
            ]
        );
    } catch (PDOException $e) {
        if ('23505' === $e->getCode()) {
            resultInfo(false, 'ASSORTMENT_NAME_TAKEN', null, 'Eine Gruppe dieses Namens gibt es schon: '.$name);
            return;
        }
        throw $e;
    }

    if (empty($zeile['gruppe'])) {
        resultInfo(false, 'ASSORTMENT_NOT_FOUND', null, 'Diese Gruppe gibt es nicht');
        return;
    }
    resultInfo(true, '', ['group' => json_decode((string)$zeile['gruppe'], true)]);
}

/**
 * Löscht eine Gruppe
 *
 * Ihre Kategorien bleiben und stehen danach in der Restgruppe „Sonstiges“
 * (category_shop.group_id: ON DELETE SET NULL).
 *
 * @param int $data['id'] Gruppe
 * @return void
 * @testdata {"id": 1}
 */
function deleteShopCategoryGroup($data) {
    permit(['edit_shop_config']);
    $db = DbhCompany::begin();

    $db->execute("DELETE FROM category_group_shop WHERE id = :id", [':id' => (int)($data['id'] ?? 0)]);
    resultInfo(true, '', null);
}

/**
 * Speichert die Reihenfolge der Gruppen
 *
 * Die Gruppen stehen in der Kategorieübersicht der Webseite in dieser
 * Reihenfolge; nicht genannte behalten ihren Platz.
 *
 * @param array $data['ids'] Gruppen in der gewünschten Reihenfolge
 * @return void
 * @testdata {"ids": [2, 1, 3]}
 */
function saveShopCategoryGroupOrder($data) {
    permit(['edit_shop_config']);
    $db = DbhCompany::begin();

    $ids = array_values(array_filter(array_map('intval', is_array($data['ids'] ?? null) ? $data['ids'] : [])));
    $db->execute(
        "UPDATE category_group_shop g
            SET sortkey = r.rang, mtime = now()
           FROM unnest(CAST(:ids AS integer[])) WITH ORDINALITY AS r(id, rang)
          WHERE g.id = r.id AND g.sortkey IS DISTINCT FROM r.rang",
        [':ids' => '{'.implode(',', $ids).'}']
    );
    resultInfo(true, '', null);
}

/**
 * Legt eine Kategorie an oder ändert sie
 *
 * Ein neuer Name ändert die Adresse der Kategorieseite: die Produktseiten
 * ihrer Artikel werden neu geschrieben (Trigger category_shop_rename_publish).
 *
 * @param int $data['id'] Kategorie; leer oder 0 = neu
 * @param string $data['name'] Name — Begriff der Taxonomie auf der Webseite
 * @param int $data['group_id'] Gruppe; leer = Restgruppe
 * @param bool $data['topseller'] hervorgehoben oben in der Übersicht
 * @return void category (id, name, group_id, topseller)
 * @testdata {"id": 0, "name": "Verbandskästen", "group_id": 1, "topseller": false}
 */
function saveShopCategory($data) {
    permit(['edit_shop_config']);
    $db = DbhCompany::begin();

    $name = shopAssortmentName($data['name'] ?? '');
    if ('' === $name) {
        resultInfo(false, 'ASSORTMENT_NAME_REQUIRED', null, 'Die Kategorie braucht einen Namen');
        return;
    }
    if ('' === shopHugoPath($name)) {
        resultInfo(false, 'ASSORTMENT_NAME_INVALID', null, 'Aus diesem Namen entsteht keine Adresse: '.$name);
        return;
    }

    $id = (int)($data['id'] ?? 0);
    $gruppe = (int)($data['group_id'] ?? 0);
    $topseller = filter_var($data['topseller'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

    try {
        $zeile = $db->getOne(
            "WITH neu AS (
                 INSERT INTO category_shop (name, group_id, topseller)
                 SELECT :name_neu, CAST(NULLIF(:gruppe_neu, 0) AS integer), CAST(:topseller_neu AS integer) = 1
                  WHERE CAST(:id_neu AS integer) = 0
                 RETURNING id, name, group_id, topseller
             ), alt AS (
                 UPDATE category_shop
                    SET name      = :name_alt,
                        group_id  = CAST(NULLIF(:gruppe_alt, 0) AS integer),
                        topseller = CAST(:topseller_alt AS integer) = 1,
                        mtime     = now()
                  WHERE id = CAST(:id_alt AS integer) AND CAST(:id_pruefung AS integer) <> 0
                 RETURNING id, name, group_id, topseller
             )
             SELECT row_to_json(x) AS kategorie FROM (SELECT * FROM neu UNION ALL SELECT * FROM alt) x",
            [
                ':name_neu'      => $name,
                ':gruppe_neu'    => $gruppe,
                ':topseller_neu' => $topseller,
                ':id_neu'        => $id,
                ':name_alt'      => $name,
                ':gruppe_alt'    => $gruppe,
                ':topseller_alt' => $topseller,
                ':id_alt'        => $id,
                ':id_pruefung'   => $id,
            ]
        );
    } catch (PDOException $e) {
        if ('23505' === $e->getCode()) {
            resultInfo(false, 'ASSORTMENT_NAME_TAKEN', null, 'Eine Kategorie dieses Namens gibt es schon: '.$name);
            return;
        }
        if ('23503' === $e->getCode()) {
            resultInfo(false, 'ASSORTMENT_NOT_FOUND', null, 'Diese Gruppe gibt es nicht (mehr)');
            return;
        }
        throw $e;
    }

    if (empty($zeile['kategorie'])) {
        resultInfo(false, 'ASSORTMENT_NOT_FOUND', null, 'Diese Kategorie gibt es nicht');
        return;
    }
    resultInfo(true, '', ['category' => json_decode((string)$zeile['kategorie'], true)]);
}

/**
 * Löscht eine Kategorie
 *
 * Nur ohne Artikel: hängen noch welche daran, bleibt sie, und die Meldung
 * nennt die Zahl (parts_ext.hugoshop_category_id: ON DELETE RESTRICT).
 *
 * @param int $data['id'] Kategorie
 * @return void
 * @testdata {"id": 1}
 */
function deleteShopCategory($data) {
    permit(['edit_shop_config']);
    $db = DbhCompany::begin();

    $id = (int)($data['id'] ?? 0);
    try {
        $db->execute("DELETE FROM category_shop WHERE id = :id", [':id' => $id]);
    } catch (PDOException $e) {
        if ('23503' === $e->getCode()) {
            $zahl = $db->getOne(
                "SELECT count(*) AS anzahl FROM parts_ext WHERE hugoshop_category_id = :id",
                [':id' => $id]
            );
            resultInfo(false, 'ASSORTMENT_CATEGORY_IN_USE', null, (string)(int)($zahl['anzahl'] ?? 0));
            return;
        }
        throw $e;
    }
    resultInfo(true, '', null);
}
