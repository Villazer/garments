<?php
declare(strict_types=1);

/*
 * Session 3 data layer for the public catalog.
 * Read-only: nothing here changes stock or orders.
 * Only active items, active categories and active variants are ever returned.
 */

// ---------------------------------------------------------------- settings

/** Low-stock threshold used when a variant's own low_stock_threshold is 0. */
function catalog_low_default(): int
{
    return max(0, (int) get_setting('low_stock_default', 5));
}

function catalog_page_size(): int
{
    return min(48, max(4, (int) get_setting('catalog_page_size', 12)));
}

/** Announcement shown on the home page, or null when switched off or empty. */
function catalog_announcement(): ?array
{
    $on = get_setting('announcement_enabled', false);
    $enabled = $on === true || $on === 1 || $on === '1' || $on === 'true';
    $text = trim((string) get_setting('announcement_text', ''));
    if (!$enabled || $text === '') {
        return null;
    }
    $style = (string) get_setting('announcement_style', 'info');
    if (!in_array($style, ['info', 'warning', 'success'], true)) {
        $style = 'info';
    }
    return ['text' => $text, 'style' => $style];
}

/** Human text for the unpaid hold, e.g. "7 days" or "36 hours". */
function catalog_hold_text(): string
{
    $hours = max(1, (int) get_setting('hold_unpaid_hours', 48));
    if ($hours % 24 === 0) {
        $days = intdiv($hours, 24);
        return $days . ($days === 1 ? ' day' : ' days');
    }
    return $hours . ' hours';
}

// ---------------------------------------------------------------- filters

/** Level filter values. "secondary" groups junior and senior high. */
function catalog_level_groups(): array
{
    return [
        'primary'     => ['label' => 'Primary',                         'codes' => ['primary']],
        'secondary'   => ['label' => 'Secondary (Junior and Senior High)', 'codes' => ['junior_high', 'senior_high']],
        'junior_high' => ['label' => 'Junior High',                     'codes' => ['junior_high']],
        'senior_high' => ['label' => 'Senior High',                     'codes' => ['senior_high']],
        'tertiary'    => ['label' => 'Tertiary',                        'codes' => ['tertiary']],
    ];
}

function catalog_sort_options(): array
{
    return [
        'name_asc'   => 'Name, A to Z',
        'name_desc'  => 'Name, Z to A',
        'price_asc'  => 'Lowest price first',
        'price_desc' => 'Highest price first',
    ];
}

function catalog_availability_options(): array
{
    return [
        ''       => 'All items',
        'in'     => 'Available to reserve',
        'out'    => 'Out of stock',
        'walkin' => 'Walk-in only',
    ];
}

function catalog_categories(): array
{
    return db_all('SELECT category_id, name FROM categories WHERE is_active = 1 ORDER BY name');
}

/** First active category whose name contains $needle (case-insensitive), or 0. */
function catalog_category_id_like(string $needle): int
{
    foreach (catalog_categories() as $row) {
        if (stripos((string) $row['name'], $needle) !== false) {
            return (int) $row['category_id'];
        }
    }
    return 0;
}

/** Turn raw $_GET into safe, whitelisted filter values. Unknown values fall back to "all". */
function catalog_read_filters(array $get): array
{
    $level = is_string($get['level'] ?? null) ? $get['level'] : '';
    if (!isset(catalog_level_groups()[$level])) {
        $level = '';
    }

    $category = 0;
    if (is_string($get['category'] ?? null) && ctype_digit($get['category'])) {
        $wanted = (int) $get['category'];
        foreach (catalog_categories() as $row) {
            if ((int) $row['category_id'] === $wanted) {
                $category = $wanted;
                break;
            }
        }
    }

    $q = is_string($get['q'] ?? null) ? trim($get['q']) : '';
    $q = function_exists('mb_substr') ? mb_substr($q, 0, 60) : substr($q, 0, 60);

    $avail = is_string($get['avail'] ?? null) ? $get['avail'] : '';
    if (!isset(catalog_availability_options()[$avail])) {
        $avail = '';
    }

    $sort = is_string($get['sort'] ?? null) ? $get['sort'] : 'name_asc';
    if (!isset(catalog_sort_options()[$sort])) {
        $sort = 'name_asc';
    }

    $page = 1;
    if (is_string($get['page'] ?? null) && ctype_digit($get['page'])) {
        $page = max(1, min(100000, (int) $get['page']));
    }

    return compact('level', 'category', 'q', 'avail', 'sort', 'page');
}

/** Query-string parts for links (page excluded, defaults omitted). */
function catalog_filter_query(array $f): array
{
    $query = [];
    foreach (['level', 'category', 'q', 'avail'] as $key) {
        if ($f[$key] !== '' && $f[$key] !== 0) {
            $query[$key] = $f[$key];
        }
    }
    if ($f['sort'] !== 'name_asc') {
        $query['sort'] = $f['sort'];
    }
    return $query;
}

// ---------------------------------------------------------------- listing

/** Build the grouped item query. Returns [sql, params]. Sizes with no active variant are excluded. */
function catalog_items_sql(array $f): array
{
    $params = [':default_low' => catalog_low_default()];
    $where  = ['i.is_active = 1', 'c.is_active = 1'];
    $having = [];

    if ($f['level'] !== '') {
        $marks = [];
        foreach (catalog_level_groups()[$f['level']]['codes'] as $n => $code) {
            $marks[] = ':lv' . $n;
            $params[':lv' . $n] = $code;
        }
        $where[] = 'el.code IN (' . implode(',', $marks) . ')';
    }
    if ($f['category'] > 0) {
        $where[] = 'i.category_id = :category';
        $params[':category'] = $f['category'];
    }
    if ($f['q'] !== '') {
        // Escape LIKE wildcards so a search for "50%" matches literally.
        $like = '%' . addcslashes($f['q'], '\\%_') . '%';
        $where[] = '(i.name LIKE :q1 OR i.item_code LIKE :q2)';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
    }

    if ($f['avail'] === 'in') {
        $having[] = "i.reservation_mode <> 'none' AND (i.tracks_stock = 0 OR sizes_available > 0)";
    } elseif ($f['avail'] === 'out') {
        $having[] = "i.reservation_mode <> 'none' AND i.tracks_stock = 1 AND sizes_available = 0";
    } elseif ($f['avail'] === 'walkin') {
        $having[] = "i.reservation_mode = 'none'";
    }

    $sql = "SELECT i.item_id, i.item_code, i.name, i.item_type, i.reservation_mode,
                   i.max_qty_per_request, i.tracks_stock,
                   c.name AS category_name, el.name AS level_name,
                   MIN(v.unit_price) AS min_price, MAX(v.unit_price) AS max_price,
                   COUNT(v.variant_id) AS size_count,
                   SUM(CASE WHEN (v.quantity_on_hand - v.quantity_reserved) > 0 THEN 1 ELSE 0 END) AS sizes_available,
                   SUM(CASE WHEN (v.quantity_on_hand - v.quantity_reserved)
                                 > COALESCE(NULLIF(v.low_stock_threshold, 0), :default_low)
                            THEN 1 ELSE 0 END) AS sizes_ok
              FROM items i
              JOIN categories c ON c.category_id = i.category_id
              JOIN item_variants v ON v.item_id = i.item_id AND v.is_active = 1
              LEFT JOIN education_levels el ON el.education_level_id = i.education_level_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY i.item_id, i.item_code, i.name, i.item_type, i.reservation_mode,
                      i.max_qty_per_request, i.tracks_stock, c.name, el.name";
    if ($having) {
        $sql .= ' HAVING ' . implode(' AND ', $having);
    }
    return [$sql, $params];
}

function catalog_count_items(array $f): int
{
    [$sql, $params] = catalog_items_sql($f);
    return (int) db_value('SELECT COUNT(*) FROM (' . $sql . ') AS t', $params);
}

function catalog_fetch_items(array $f, int $offset, int $per_page): array
{
    $orders = [
        'name_asc'   => 'i.name ASC, i.item_id ASC',
        'name_desc'  => 'i.name DESC, i.item_id ASC',
        'price_asc'  => 'min_price ASC, i.name ASC',
        'price_desc' => 'min_price DESC, i.name ASC',   // "highest price" = highest starting price
    ];
    [$sql, $params] = catalog_items_sql($f);
    // LIMIT/OFFSET are cast to int, so they are safe to inline.
    $sql .= ' ORDER BY ' . $orders[$f['sort']] . ' LIMIT ' . (int) $per_page . ' OFFSET ' . (int) $offset;
    return db_all($sql, $params);
}

// ---------------------------------------------------------------- status

/**
 * One status key per item card:
 * walkin | made | service | out | low | in
 * "in" means at least one size is above its low-stock threshold;
 * "low" means sizes exist but all are at or below it.
 */
function catalog_item_status(array $item): string
{
    if ($item['reservation_mode'] === 'none') {
        return 'walkin';
    }
    if ((int) $item['tracks_stock'] === 0) {
        return $item['item_type'] === 'alteration' ? 'service' : 'made';
    }
    if ((int) $item['sizes_available'] === 0) {
        return 'out';
    }
    return (int) $item['sizes_ok'] > 0 ? 'in' : 'low';
}

function catalog_variant_available(array $variant): int
{
    return max(0, (int) $variant['quantity_on_hand'] - (int) $variant['quantity_reserved']);
}

/** Per-size status: made | service | out | low | in. (Walk-in is an item-level status.) */
function catalog_variant_status(array $variant, array $item): string
{
    if ((int) $item['tracks_stock'] === 0) {
        return $item['item_type'] === 'alteration' ? 'service' : 'made';
    }
    $available = catalog_variant_available($variant);
    if ($available <= 0) {
        return 'out';
    }
    $threshold = (int) $variant['low_stock_threshold'] > 0
        ? (int) $variant['low_stock_threshold']
        : catalog_low_default();
    return $available <= $threshold ? 'low' : 'in';
}

// ---------------------------------------------------------------- item detail

/** Active item in an active category, or null. */
function catalog_find_item(int $item_id): ?array
{
    $row = db_one(
        'SELECT i.item_id, i.item_code, i.name, i.description, i.item_type, i.reservation_mode, i.category_id,
                i.max_qty_per_request, i.tracks_stock,
                c.name AS category_name, el.name AS level_name
           FROM items i
           JOIN categories c ON c.category_id = i.category_id
           LEFT JOIN education_levels el ON el.education_level_id = i.education_level_id
          WHERE i.item_id = :id AND i.is_active = 1 AND c.is_active = 1',
        [':id' => $item_id]
    );
    return $row ?: null;
}

/** Active variants of an item, in natural size order (6, 8, 10 / S, M, L, XL). */
function catalog_item_variants(int $item_id): array
{
    $rows = db_all(
        'SELECT variant_id, size_label, unit_price, quantity_on_hand, quantity_reserved, low_stock_threshold
           FROM item_variants WHERE item_id = :id AND is_active = 1',
        [':id' => $item_id]
    );
    usort($rows, fn(array $a, array $b) => catalog_size_rank((string) $a['size_label']) <=> catalog_size_rank((string) $b['size_label']));
    return $rows;
}

function catalog_size_rank(string $label): array
{
    $named = ['XXS' => 1, 'XS' => 2, 'S' => 3, 'M' => 4, 'L' => 5, 'XL' => 6,
              'XXL' => 7, '2XL' => 7, 'XXXL' => 8, '3XL' => 8];
    $u = strtoupper(trim($label));
    if (is_numeric($u)) {
        return [0, (float) $u, ''];
    }
    if (isset($named[$u])) {
        return [1, (float) $named[$u], ''];
    }
    return [2, 0.0, $u];
}

/** A variant with its item, for the waitlist and toga request placeholders. */
function catalog_find_variant(int $variant_id): ?array
{
    $row = db_one(
        'SELECT v.variant_id, v.size_label, v.unit_price, i.item_id, i.name AS item_name, i.item_type
           FROM item_variants v
           JOIN items i ON i.item_id = v.item_id
           JOIN categories c ON c.category_id = i.category_id
          WHERE v.variant_id = :id AND v.is_active = 1 AND i.is_active = 1 AND c.is_active = 1',
        [':id' => $variant_id]
    );
    return $row ?: null;
}

// ---------------------------------------------------------------- images

/**
 * Images for several items at once: item_id => list of ['url' => ..., 'alt' => ...].
 * Source 1: the item_images table (migration 001). If the table does not exist yet,
 * this quietly falls through. Source 2: a file named uploads/items/<item_code>.jpg|png|webp.
 * Only files that really exist under uploads/ are returned, so a missing file shows the placeholder.
 */
function catalog_images_for(array $items): array
{
    $result = [];
    $ids = [];
    foreach ($items as $item) {
        $ids[] = (int) $item['item_id'];
        $result[(int) $item['item_id']] = [];
    }
    if (!$ids) {
        return $result;
    }

    try {
        $marks = [];
        $params = [];
        foreach ($ids as $n => $id) {
            $marks[] = ':id' . $n;
            $params[':id' . $n] = $id;
        }
        $rows = db_all(
            'SELECT item_id, file_path, alt_text FROM item_images
              WHERE item_id IN (' . implode(',', $marks) . ') ORDER BY sort_order, image_id',
            $params
        );
    } catch (Throwable $ex) {
        $rows = [];   // table not created yet
    }
    $names = [];
    foreach ($items as $item) {
        $names[(int) $item['item_id']] = (string) $item['name'];
    }
    foreach ($rows as $row) {
        $path = (string) $row['file_path'];
        if (catalog_image_file_ok($path)) {
            $alt = trim((string) ($row['alt_text'] ?? ''));
            $result[(int) $row['item_id']][] = [
                'url' => url($path),
                'alt' => $alt !== '' ? $alt : $names[(int) $row['item_id']],
            ];
        }
    }

    foreach ($items as $item) {
        $id = (int) $item['item_id'];
        if ($result[$id] || !preg_match('/^[A-Za-z0-9_-]+$/', (string) $item['item_code'])) {
            continue;
        }
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $path = 'uploads/items/' . $item['item_code'] . '.' . $ext;
            if (catalog_image_file_ok($path)) {
                $result[$id][] = ['url' => url($path), 'alt' => (string) $item['name']];
                break;
            }
        }
    }
    return $result;
}

/** Only plain files under uploads/ are allowed; blocks "..", absolute paths and odd characters. */
function catalog_image_file_ok(string $path): bool
{
    if (!preg_match('#^uploads/[A-Za-z0-9._/-]+$#', $path) || strpos($path, '..') !== false) {
        return false;
    }
    return is_file(CATALOG_ROOT . '/' . $path);
}

// ---------------------------------------------------------------- restock and rental info

/** Next scheduled deliveries for the home page teaser. Never throws; an empty list hides nothing important. */
function catalog_next_restocks(int $limit = 3): array
{
    try {
        return db_all(
            'SELECT rs.expected_date, COALESCE(i1.name, i2.name) AS item_name, v.size_label
               FROM restock_schedule rs
               LEFT JOIN item_variants v ON v.variant_id = rs.variant_id
               LEFT JOIN items i1 ON i1.item_id = v.item_id
               LEFT JOIN items i2 ON i2.item_id = rs.item_id
              WHERE rs.status = \'scheduled\' AND rs.expected_date >= CURDATE()
                AND COALESCE(i1.is_active, i2.is_active) = 1
              ORDER BY rs.expected_date ASC, rs.restock_id ASC
              LIMIT ' . max(1, min(10, $limit))
        );
    } catch (Throwable $ex) {
        return [];
    }
}

/** Earliest scheduled delivery per variant for one item: variant_id => date; key 0 = whole item. */
function catalog_restock_dates(int $item_id): array
{
    $rows = db_all(
        'SELECT COALESCE(rs.variant_id, 0) AS variant_key, MIN(rs.expected_date) AS expected_date
           FROM restock_schedule rs
           LEFT JOIN item_variants v ON v.variant_id = rs.variant_id
          WHERE rs.status = \'scheduled\' AND rs.expected_date >= CURDATE()
            AND (v.item_id = :item1 OR rs.item_id = :item2)
          GROUP BY COALESCE(rs.variant_id, 0)',
        [':item1' => $item_id, ':item2' => $item_id]
    );
    $dates = [];
    foreach ($rows as $row) {
        $dates[(int) $row['variant_key']] = (string) $row['expected_date'];
    }
    return $dates;
}

/** The current academic period (for the toga return date), or null. */
function catalog_current_period(): ?array
{
    $row = db_one('SELECT school_year, semester, end_date FROM academic_periods WHERE is_current = 1 LIMIT 1');
    return $row ?: null;
}
