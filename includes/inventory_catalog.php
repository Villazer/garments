<?php
/**
 * Session 7: catalog maintenance (items, sizes/variants, images).
 * Loaded by inventory.php. Business-rule failures throw DomainException
 * with a message that is safe to show.
 */

/* ------------------------------------------------------------------ */
/* Reference lists                                                     */
/* ------------------------------------------------------------------ */

function inv_categories(): array
{
    return inv_all('SELECT category_id, name FROM categories WHERE is_active = 1 ORDER BY name');
}

function inv_levels(): array
{
    return inv_all('SELECT education_level_id, name FROM education_levels ORDER BY sort_order, education_level_id');
}

function inv_courses(): array
{
    return inv_all('SELECT course_id, code, name FROM courses WHERE is_active = 1 ORDER BY code');
}

/* ------------------------------------------------------------------ */
/* Items                                                               */
/* ------------------------------------------------------------------ */

function inv_item_find(int $id): ?array
{
    return inv_one(
        'SELECT i.*, c.name AS category_name, el.name AS level_name
           FROM items i
           JOIN categories c ON c.category_id = i.category_id
           LEFT JOIN education_levels el ON el.education_level_id = i.education_level_id
          WHERE i.item_id = ?',
        [$id]
    );
}

function inv_items_filters(array $get): array
{
    $status = (string) ($get['status'] ?? 'active');
    return [
        'q'        => inv_substr(inv_clean($get['q'] ?? ''), 60),
        'category' => (int) ($get['category'] ?? 0),
        'status'   => in_array($status, ['active', 'archived', 'all'], true) ? $status : 'active',
        'page'     => max(1, (int) ($get['page'] ?? 1)),
    ];
}

function inv_items_query(array $f): array
{
    $w = [];
    $p = [];
    if ($f['status'] === 'active') {
        $w[] = 'i.is_active = 1';
    } elseif ($f['status'] === 'archived') {
        $w[] = 'i.is_active = 0';
    }
    if ($f['category'] > 0) {
        $w[] = 'i.category_id = ?';
        $p[] = $f['category'];
    }
    if ($f['q'] !== '') {
        $like = '%' . inv_like($f['q']) . '%';
        $w[] = '(i.name LIKE ? OR i.item_code LIKE ?)';
        array_push($p, $like, $like);
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

function inv_items_count(array $f): int
{
    [$where, $p] = inv_items_query($f);
    return (int) inv_value("SELECT COUNT(*) FROM items i $where", $p);
}

function inv_items_rows(array $f, int $offset, int $limit): array
{
    [$where, $p] = inv_items_query($f);
    return inv_all(
        "SELECT i.item_id, i.item_code, i.name, i.item_type, i.reservation_mode, i.max_qty_per_request,
                i.tracks_stock, i.is_active, c.name AS category_name, el.name AS level_name,
                (SELECT COUNT(*) FROM item_variants v WHERE v.item_id = i.item_id AND v.is_active = 1) AS sizes,
                (SELECT MIN(v.unit_price) FROM item_variants v WHERE v.item_id = i.item_id AND v.is_active = 1) AS min_price,
                (SELECT MAX(v.unit_price) FROM item_variants v WHERE v.item_id = i.item_id AND v.is_active = 1) AS max_price,
                (SELECT COALESCE(SUM(v.quantity_on_hand - v.quantity_reserved), 0) FROM item_variants v
                  WHERE v.item_id = i.item_id AND v.is_active = 1) AS available
           FROM items i
           JOIN categories c ON c.category_id = i.category_id
           LEFT JOIN education_levels el ON el.education_level_id = i.education_level_id
           $where
          ORDER BY i.is_active DESC, i.name
          LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
        $p
    );
}

/** Validate the item form. $existing locks item_code and item_type once created. */
function inv_item_validate(array $post, ?array $existing): array
{
    $errors = [];
    $c = [];

    $c['name'] = inv_clean($post['name'] ?? '');
    if (inv_strlen($c['name']) < 2 || inv_strlen($c['name']) > 150) {
        $errors['name'] = 'Name must be 2 to 150 characters.';
    }

    $desc = inv_clean($post['description'] ?? '');
    if (inv_strlen($desc) > 500) {
        $errors['description'] = 'Description can be at most 500 characters.';
    }
    $c['description'] = $desc === '' ? null : $desc;

    if ($existing) {
        $c['item_code'] = $existing['item_code'];
        $c['item_type'] = $existing['item_type'];
    } else {
        $code = strtoupper(inv_clean($post['item_code'] ?? ''));
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{2,29}$/', $code)) {
            $errors['item_code'] = 'Item code: 3 to 30 characters, letters, digits, dash or underscore (e.g. PRI-BOY-POLO).';
        } elseif (inv_value('SELECT COUNT(*) FROM items WHERE item_code = ?', [$code]) > 0) {
            $errors['item_code'] = 'That item code is already used.';
        }
        $c['item_code'] = $code;

        $type = (string) ($post['item_type'] ?? '');
        if (!isset(inv_item_types()[$type])) {
            $errors['item_type'] = 'Choose an item type.';
        }
        $c['item_type'] = $type;
    }

    $c['category_id'] = (int) ($post['category_id'] ?? 0);
    if (inv_value('SELECT COUNT(*) FROM categories WHERE category_id = ?', [$c['category_id']]) < 1) {
        $errors['category_id'] = 'Choose a category.';
    }

    $level = (int) ($post['education_level_id'] ?? 0);
    $c['education_level_id'] = $level > 0 ? $level : null;
    if ($level > 0 && inv_value('SELECT COUNT(*) FROM education_levels WHERE education_level_id = ?', [$level]) < 1) {
        $errors['education_level_id'] = 'Unknown education level.';
    }

    $course = (int) ($post['course_id'] ?? 0);
    $c['course_id'] = $course > 0 ? $course : null;
    if ($course > 0 && inv_value('SELECT COUNT(*) FROM courses WHERE course_id = ?', [$course]) < 1) {
        $errors['course_id'] = 'Unknown course.';
    }

    $mode = (string) ($post['reservation_mode'] ?? '');
    if (!isset(inv_reservation_modes()[$mode])) {
        $errors['reservation_mode'] = 'Choose a reservation mode.';
    }
    $c['reservation_mode'] = $mode;

    $max = inv_int($post['max_qty_per_request'] ?? '', 1, 50);
    if ($max === null) {
        $errors['max_qty_per_request'] = 'Maximum quantity per online request must be 1 to 50.';
    }
    $c['max_qty_per_request'] = $max ?? 3;

    $type = $c['item_type'];
    if ($type === 'rental') {
        $c['max_qty_per_request'] = 1; // one toga per order line
    }
    // Auto-approval would hold stock with no staff check; measurements, student
    // checks and one-rental-per-period all need a person.
    if ($mode === 'auto' && in_array($type, ['made_to_order', 'alteration', 'rental'], true)) {
        $errors['reservation_mode'] = 'Made-to-order, alteration and rental items need staff approval or counter-only.';
    }
    $c['tracks_stock'] = in_array($type, ['ready_made', 'rental'], true) ? 1 : 0;
    $c['is_active'] = inv_post_checkbox($post, 'is_active');

    return [$c, $errors];
}

function inv_item_create(array $c, int $user_id): int
{
    try {
        $id = inv_insert(
            'INSERT INTO items (category_id, education_level_id, course_id, item_code, name, description,
                                item_type, reservation_mode, max_qty_per_request, tracks_stock, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$c['category_id'], $c['education_level_id'], $c['course_id'], $c['item_code'], $c['name'],
             $c['description'], $c['item_type'], $c['reservation_mode'], $c['max_qty_per_request'],
             $c['tracks_stock'], $c['is_active']]
        );
    } catch (PDOException $ex) {
        if (inv_is_duplicate($ex)) {
            throw new DomainException('That item code is already used.');
        }
        throw $ex;
    }
    inv_audit($user_id, 'item_created', 'items', $id, null, $c);
    return $id;
}

function inv_item_update(int $id, array $c, int $user_id): void
{
    $old = inv_item_find($id);
    if (!$old) {
        throw new DomainException('Item not found.');
    }
    inv_q(
        'UPDATE items SET category_id = ?, education_level_id = ?, course_id = ?, name = ?, description = ?,
                reservation_mode = ?, max_qty_per_request = ?, is_active = ? WHERE item_id = ?',
        [$c['category_id'], $c['education_level_id'], $c['course_id'], $c['name'], $c['description'],
         $c['reservation_mode'], $c['max_qty_per_request'], $c['is_active'], $id]
    );
    $before = [];
    $after  = [];
    foreach (['category_id', 'education_level_id', 'course_id', 'name', 'description', 'reservation_mode', 'max_qty_per_request', 'is_active'] as $k) {
        if ((string) $old[$k] !== (string) $c[$k]) {
            $before[$k] = $old[$k];
            $after[$k]  = $c[$k];
        }
    }
    if ($after) {
        inv_audit($user_id, 'item_updated', 'items', $id, $before, $after);
    }
}

/** Open orders (not finished) that contain this item. */
function inv_item_open_orders(int $item_id): int
{
    return (int) inv_value(
        "SELECT COUNT(DISTINCT o.order_id)
           FROM orders o
           JOIN order_items oi ON oi.order_id = o.order_id
           JOIN item_variants v ON v.variant_id = oi.variant_id
          WHERE v.item_id = ? AND o.deleted_at IS NULL
            AND o.status IN ('requested','approved','slip_issued','paid','unclaimed')",
        [$item_id]
    );
}

/** True when anything refers to this item's sizes (orders, stock log, waitlist, restock, rentals). */
function inv_item_has_history(int $item_id): bool
{
    $n = (int) inv_value(
        'SELECT (SELECT COUNT(*) FROM order_items oi JOIN item_variants v ON v.variant_id = oi.variant_id WHERE v.item_id = ?)
              + (SELECT COUNT(*) FROM inventory_movements m JOIN item_variants v ON v.variant_id = m.variant_id WHERE v.item_id = ?)
              + (SELECT COUNT(*) FROM waitlist w JOIN item_variants v ON v.variant_id = w.variant_id WHERE v.item_id = ?)
              + (SELECT COUNT(*) FROM restock_schedule r WHERE r.item_id = ? OR r.variant_id IN (SELECT variant_id FROM item_variants WHERE item_id = ?))
              + (SELECT COUNT(*) FROM rentals rt JOIN item_variants v ON v.variant_id = rt.variant_id WHERE v.item_id = ?)
              + (SELECT COUNT(*) FROM toga_units tu JOIN item_variants v ON v.variant_id = tu.variant_id WHERE v.item_id = ?)',
        [$item_id, $item_id, $item_id, $item_id, $item_id, $item_id, $item_id]
    );
    return $n > 0;
}

/** Archive (is_active = 0) or restore an item. Returns the number of open orders for a warning. */
function inv_item_set_active(int $id, bool $active, int $user_id): int
{
    $item = inv_item_find($id);
    if (!$item) {
        throw new DomainException('Item not found.');
    }
    inv_q('UPDATE items SET is_active = ? WHERE item_id = ?', [$active ? 1 : 0, $id]);
    inv_audit($user_id, $active ? 'item_restored' : 'item_archived', 'items', $id,
        ['is_active' => (int) $item['is_active']], ['is_active' => $active ? 1 : 0]);
    return $active ? 0 : inv_item_open_orders($id);
}

/** Permanent delete: only for items nothing has ever referred to. */
function inv_item_delete(int $id, int $user_id): void
{
    $item = inv_item_find($id);
    if (!$item) {
        throw new DomainException('Item not found.');
    }
    $files = [];
    inv_tx(function () use ($id, $item, $user_id, &$files) {
        inv_q('SELECT item_id FROM items WHERE item_id = ? FOR UPDATE', [$id]);
        if (inv_item_has_history($id)) {
            throw new DomainException('This item has order, stock, waitlist or restock history, so it cannot be deleted. Archive it instead.');
        }
        foreach (inv_images($id) as $img) {
            $files[] = $img['file'];
        }
        if (inv_image_cfg() !== null && empty(inv_image_cfg()['problem'])) {
            inv_q('DELETE FROM item_images WHERE item_id = ?', [$id]);
        }
        inv_q('DELETE FROM item_variants WHERE item_id = ?', [$id]);
        inv_q('DELETE FROM items WHERE item_id = ?', [$id]);
        inv_audit($user_id, 'item_deleted', 'items', $id, ['item_code' => $item['item_code'], 'name' => $item['name']], null);
    });
    foreach ($files as $f) {
        inv_image_unlink($f);
    }
}

/* ------------------------------------------------------------------ */
/* Variants (sizes)                                                    */
/* ------------------------------------------------------------------ */

function inv_variants(int $item_id): array
{
    return inv_all(
        'SELECT variant_id, item_id, size_label, sku, unit_price, quantity_on_hand, quantity_reserved,
                (quantity_on_hand - quantity_reserved) AS available, low_stock_threshold, is_active
           FROM item_variants WHERE item_id = ? ORDER BY is_active DESC, variant_id',
        [$item_id]
    );
}

function inv_variant_find(int $id): ?array
{
    return inv_one(
        'SELECT v.*, (v.quantity_on_hand - v.quantity_reserved) AS available,
                i.name AS item_name, i.item_code, i.tracks_stock, i.is_active AS item_active
           FROM item_variants v JOIN items i ON i.item_id = v.item_id WHERE v.variant_id = ?',
        [$id]
    );
}

function inv_variant_has_history(int $variant_id): bool
{
    $n = (int) inv_value(
        'SELECT (SELECT COUNT(*) FROM order_items WHERE variant_id = ?)
              + (SELECT COUNT(*) FROM inventory_movements WHERE variant_id = ?)
              + (SELECT COUNT(*) FROM waitlist WHERE variant_id = ?)
              + (SELECT COUNT(*) FROM restock_schedule WHERE variant_id = ?)
              + (SELECT COUNT(*) FROM rentals WHERE variant_id = ?)
              + (SELECT COUNT(*) FROM toga_units WHERE variant_id = ?)',
        [$variant_id, $variant_id, $variant_id, $variant_id, $variant_id, $variant_id]
    );
    return $n > 0;
}

/** Validate a size form. $variant_id = 0 when adding. */
function inv_variant_validate(array $post, array $item, int $variant_id): array
{
    $errors = [];
    $c = [];

    $label = inv_clean($post['size_label'] ?? '');
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 .\/-]{0,19}$/', $label)) {
        $errors[] = 'Size label: 1 to 20 characters (letters, digits, space, . / -).';
    } elseif (inv_value('SELECT COUNT(*) FROM item_variants WHERE item_id = ? AND size_label = ? AND variant_id <> ?',
        [(int) $item['item_id'], $label, $variant_id]) > 0) {
        $errors[] = 'This item already has a size "' . $label . '".';
    }
    $c['size_label'] = $label;

    $sku = strtoupper(inv_clean($post['sku'] ?? ''));
    if ($sku === '' && $errors === []) {
        $sku = strtoupper($item['item_code'] . '-' . preg_replace('/[^A-Za-z0-9]+/', '', $label));
    }
    if (!preg_match('/^[A-Z0-9][A-Z0-9_.-]{1,39}$/', $sku)) {
        $errors[] = 'SKU: 2 to 40 characters, letters, digits, dash, dot or underscore (leave blank to generate one).';
    } elseif (inv_value('SELECT COUNT(*) FROM item_variants WHERE sku = ? AND variant_id <> ?', [$sku, $variant_id]) > 0) {
        $errors[] = 'The SKU "' . $sku . '" is already used by another size.';
    }
    $c['sku'] = $sku;

    $price = inv_parse_money($post['unit_price'] ?? '');
    if ($price === null) {
        $errors[] = 'Enter a valid price, for example 250 or 250.50.';
    }
    $c['unit_price'] = $price;

    if ((int) $item['tracks_stock'] === 1) {
        $low = inv_int($post['low_stock_threshold'] ?? '', 0, 100000);
        if ($low === null) {
            $errors[] = 'Low-stock threshold must be a whole number, 0 or more.';
        }
        $c['low_stock_threshold'] = $low ?? 0;
    } else {
        $c['low_stock_threshold'] = 0;
    }

    return [$c, $errors];
}

/** Add a size. Optional opening stock goes through the normal adjustment (with a movement row). */
function inv_variant_create(array $item, array $c, int $opening_qty, int $user_id): int
{
    $id = 0;
    try {
        inv_tx(function () use ($item, $c, $opening_qty, $user_id, &$id) {
            $id = inv_insert(
                'INSERT INTO item_variants (item_id, size_label, sku, unit_price, quantity_on_hand, low_stock_threshold)
                 VALUES (?, ?, ?, ?, 0, ?)',
                [(int) $item['item_id'], $c['size_label'], $c['sku'], $c['unit_price'], $c['low_stock_threshold']]
            );
            if ($opening_qty > 0 && (int) $item['tracks_stock'] === 1) {
                inv_adjust_stock_tx($id, 'receive', $opening_qty, 'Opening stock', $user_id);
            }
            inv_audit($user_id, 'variant_created', 'item_variants', $id, null, $c + ['opening_qty' => $opening_qty]);
        });
    } catch (PDOException $ex) {
        if (inv_is_duplicate($ex)) {
            throw new DomainException('That size label or SKU already exists.');
        }
        throw $ex;
    }
    if ($opening_qty > 0) {
        inv_notice_waitlist($id);
    }
    return $id;
}

/** Update label, SKU, price and threshold. A price change only affects NEW orders (snapshots). */
function inv_variant_update(int $id, array $c, int $user_id): bool
{
    $old = inv_variant_find($id);
    if (!$old) {
        throw new DomainException('Size not found.');
    }
    try {
        inv_q(
            'UPDATE item_variants SET size_label = ?, sku = ?, unit_price = ?, low_stock_threshold = ? WHERE variant_id = ?',
            [$c['size_label'], $c['sku'], $c['unit_price'], $c['low_stock_threshold'], $id]
        );
    } catch (PDOException $ex) {
        if (inv_is_duplicate($ex)) {
            throw new DomainException('That size label or SKU already exists.');
        }
        throw $ex;
    }
    $before = [];
    $after  = [];
    foreach (['size_label', 'sku', 'unit_price', 'low_stock_threshold'] as $k) {
        if ((string) $old[$k] !== (string) $c[$k]) {
            $before[$k] = $old[$k];
            $after[$k]  = $c[$k];
        }
    }
    if ($after) {
        inv_audit($user_id, isset($after['unit_price']) ? 'variant_price_changed' : 'variant_updated', 'item_variants', $id, $before, $after);
    }
    return isset($after['unit_price']);
}

function inv_variant_set_active(int $id, bool $active, int $user_id): void
{
    inv_tx(function () use ($id, $active, $user_id) {
        $v = inv_one('SELECT variant_id, quantity_reserved, is_active FROM item_variants WHERE variant_id = ? FOR UPDATE', [$id]);
        if (!$v) {
            throw new DomainException('Size not found.');
        }
        if (!$active && (int) $v['quantity_reserved'] > 0) {
            throw new DomainException('Cannot deactivate: ' . (int) $v['quantity_reserved']
                . ' unit(s) are reserved by open orders. Deactivate after they are claimed, cancelled or released.');
        }
        inv_q('UPDATE item_variants SET is_active = ? WHERE variant_id = ?', [$active ? 1 : 0, $id]);
        inv_audit($user_id, $active ? 'variant_activated' : 'variant_deactivated', 'item_variants', $id,
            ['is_active' => (int) $v['is_active']], ['is_active' => $active ? 1 : 0]);
    });
}

/** Permanent delete of a size nothing has ever referred to (typo clean-up). */
function inv_variant_delete(int $id, int $user_id): void
{
    inv_tx(function () use ($id, $user_id) {
        $v = inv_one('SELECT variant_id, size_label, sku FROM item_variants WHERE variant_id = ? FOR UPDATE', [$id]);
        if (!$v) {
            throw new DomainException('Size not found.');
        }
        if (inv_variant_has_history($id)) {
            throw new DomainException('This size has order, stock, waitlist or restock history. Deactivate it instead.');
        }
        inv_q('DELETE FROM item_variants WHERE variant_id = ?', [$id]);
        inv_audit($user_id, 'variant_deleted', 'item_variants', $id, $v, null);
    });
}

/* ------------------------------------------------------------------ */
/* Images                                                              */
/* ------------------------------------------------------------------ */

const INV_IMAGE_MAX_COUNT = 6;

function inv_image_dir(): string
{
    return rtrim(defined('AUTH_ROOT') ? AUTH_ROOT : dirname(__DIR__), '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'items';
}

function inv_image_max_bytes(): int
{
    return max(100 * 1024, (int) config('uploads.max_bytes', 2 * 1024 * 1024));
}

/**
 * Session 3's migration 001 created item_images but its exact columns were not
 * visible to this session, so the table is read at run time and mapped by
 * common column names. Returns null when the table is missing, or an array
 * with 'problem' set when it cannot be mapped.
 */
function inv_image_cfg(): ?array
{
    static $cfg = false;
    if ($cfg !== false) {
        return $cfg;
    }
    $cols = inv_all(
        "SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS def, EXTRA AS extra
           FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_images'"
    );
    if (!$cols) {
        return $cfg = null;
    }
    $names = array_column($cols, 'name');
    $pick = function (array $candidates) use ($names) {
        foreach ($candidates as $c) {
            if (in_array($c, $names, true)) {
                return $c;
            }
        }
        return null;
    };
    $cfg = [
        'id'      => $pick(['image_id', 'item_image_id', 'id']),
        'item'    => $pick(['item_id']),
        'file'    => $pick(['file_path', 'image_path', 'path', 'file_name', 'filename', 'image_file', 'image_url', 'url', 'file']),
        'alt'     => $pick(['alt_text', 'alt', 'caption', 'title']),
        'sort'    => $pick(['sort_order', 'position', 'display_order']),
        'problem' => null,
    ];
    if (!$cfg['id'] || !$cfg['item'] || !$cfg['file']) {
        $cfg['problem'] = 'The item_images table has columns this page does not recognise (' . implode(', ', $names)
            . '). Image upload is switched off; see the handoff note.';
        return $cfg;
    }
    $known = array_filter([$cfg['id'], $cfg['item'], $cfg['file'], $cfg['alt'], $cfg['sort']]);
    foreach ($cols as $col) {
        $needs = $col['nullable'] === 'NO' && $col['def'] === null && stripos((string) $col['extra'], 'auto_increment') === false;
        if ($needs && !in_array($col['name'], $known, true)) {
            $cfg['problem'] = 'The item_images column "' . $col['name'] . '" is required but unknown to this page. Image upload is switched off; see the handoff note.';
            break;
        }
    }
    return $cfg;
}

/** Stored value: a bare file name when the column is called *name, else uploads/items/<file>. */
function inv_image_stored_value(string $basename): string
{
    $cfg = inv_image_cfg();
    return ($cfg && stripos($cfg['file'], 'name') !== false) ? $basename : 'uploads/items/' . $basename;
}

function inv_image_url(string $stored): string
{
    $path = strpos($stored, '/') !== false ? ltrim($stored, '/') : 'uploads/items/' . $stored;
    return url($path);
}

/** Images of an item, in display order: [['image_id','file','url','alt'], ...]. */
function inv_images(int $item_id): array
{
    $cfg = inv_image_cfg();
    if ($cfg === null || $cfg['problem']) {
        return [];
    }
    $sql = 'SELECT `' . $cfg['id'] . '` AS image_id, `' . $cfg['file'] . '` AS file'
        . ($cfg['alt'] ? ', `' . $cfg['alt'] . '` AS alt' : ", '' AS alt")
        . ' FROM item_images WHERE `' . $cfg['item'] . '` = ? ORDER BY '
        . ($cfg['sort'] ? '`' . $cfg['sort'] . '`, ' : '') . '`' . $cfg['id'] . '`';
    $rows = inv_all($sql, [$item_id]);
    foreach ($rows as &$r) {
        $r['url'] = inv_image_url((string) $r['file']);
    }
    return $rows;
}

/** Validate an uploaded image; returns [extension, tmp_path]. Never trusts the client file name or type. */
function inv_image_validate_upload(array $f): array
{
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        throw new DomainException('Choose an image file to upload.');
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new DomainException('The image is too large (limit ' . round(inv_image_max_bytes() / 1048576, 1) . ' MB, and the PHP upload_max_filesize must allow it).');
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new DomainException('The upload failed (code ' . (int) $err . '). Please try again.');
    }
    $tmp = (string) ($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new DomainException('The upload could not be verified.');
    }
    $size = (int) ($f['size'] ?? 0);
    if ($size < 1 || $size > inv_image_max_bytes()) {
        throw new DomainException('The image must be smaller than ' . round(inv_image_max_bytes() / 1048576, 1) . ' MB.');
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $info = @getimagesize($tmp);
    if ($info === false || !isset($allowed[$info['mime'] ?? ''])) {
        throw new DomainException('Only JPG, PNG or WebP images are accepted.');
    }
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? finfo_file($fi, $tmp) : false;
        if ($fi) {
            finfo_close($fi);
        }
        if ($mime !== $info['mime']) {
            throw new DomainException('The file content does not match an allowed image type.');
        }
    }
    if ($info[0] < 50 || $info[1] < 50 || $info[0] > 6000 || $info[1] > 6000) {
        throw new DomainException('Image dimensions must be between 50 and 6000 pixels.');
    }
    return [$allowed[$info['mime']], $tmp];
}

function inv_image_upload(int $item_id, array $file, string $alt, int $user_id): int
{
    $cfg = inv_image_cfg();
    if ($cfg === null) {
        throw new DomainException('Image upload is not available: the item_images table (migration 001) is missing.');
    }
    if ($cfg['problem']) {
        throw new DomainException($cfg['problem']);
    }
    if (!inv_item_find($item_id)) {
        throw new DomainException('Item not found.');
    }
    if (count(inv_images($item_id)) >= INV_IMAGE_MAX_COUNT) {
        throw new DomainException('An item can have at most ' . INV_IMAGE_MAX_COUNT . ' images. Delete one first.');
    }
    [$ext, $tmp] = inv_image_validate_upload($file);

    $dir = inv_image_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new DomainException('The uploads/items folder does not exist and could not be created.');
    }
    if (!is_writable($dir)) {
        throw new DomainException('The uploads/items folder is not writable.');
    }
    $basename = bin2hex(random_bytes(16)) . '.' . $ext;   // the original name is never used
    $target = $dir . DIRECTORY_SEPARATOR . $basename;
    if (!move_uploaded_file($tmp, $target)) {
        throw new DomainException('The image could not be saved.');
    }
    @chmod($target, 0644);

    $alt = inv_substr(inv_clean($alt), 150);
    try {
        $cols = [$cfg['item'], $cfg['file']];
        $vals = [$item_id, inv_image_stored_value($basename)];
        if ($cfg['alt']) {
            $cols[] = $cfg['alt'];
            $vals[] = $alt;
        }
        if ($cfg['sort']) {
            $next = (int) inv_value('SELECT COALESCE(MAX(`' . $cfg['sort'] . '`), 0) + 1 FROM item_images WHERE `' . $cfg['item'] . '` = ?', [$item_id]);
            $cols[] = $cfg['sort'];
            $vals[] = $next;
        }
        $sql = 'INSERT INTO item_images (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $id = inv_insert($sql, $vals);
    } catch (PDOException $ex) {
        @unlink($target);
        throw $ex;
    }
    inv_audit($user_id, 'item_image_added', 'items', $item_id, null, ['image_id' => $id, 'file' => $basename]);
    return $id;
}

function inv_image_delete(int $item_id, int $image_id, int $user_id): void
{
    $cfg = inv_image_cfg();
    if ($cfg === null || $cfg['problem']) {
        throw new DomainException('Image management is not available.');
    }
    $row = inv_one('SELECT `' . $cfg['file'] . '` AS file FROM item_images WHERE `' . $cfg['id'] . '` = ? AND `' . $cfg['item'] . '` = ?', [$image_id, $item_id]);
    if (!$row) {
        throw new DomainException('Image not found.');
    }
    inv_q('DELETE FROM item_images WHERE `' . $cfg['id'] . '` = ? AND `' . $cfg['item'] . '` = ?', [$image_id, $item_id]);
    inv_image_unlink((string) $row['file']);
    inv_audit($user_id, 'item_image_deleted', 'items', $item_id, ['image_id' => $image_id, 'file' => $row['file']], null);
}

/** Delete a stored file, but only if it really lives inside uploads/items. */
function inv_image_unlink(string $stored): void
{
    $base = basename($stored);
    if ($base === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $base)) {
        return;
    }
    $path = inv_image_dir() . DIRECTORY_SEPARATOR . $base;
    $dir  = realpath(inv_image_dir());
    $real = realpath($path);
    if ($dir !== false && $real !== false && strpos($real, $dir . DIRECTORY_SEPARATOR) === 0) {
        @unlink($real);
    }
}
