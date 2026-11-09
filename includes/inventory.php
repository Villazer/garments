<?php
/**
 * Session 7: Inventory management library (core).
 *
 * Everything here is prefixed inv_. Depends on Session 1 (db, e, money, ...)
 * and Session 2 (current_user, can). The catalog (items/variants/images) lives
 * in inventory_catalog.php and restock/waitlist in inventory_restock.php;
 * both are loaded at the bottom of this file.
 *
 * Rule that everything below protects: quantity_on_hand is NEVER changed
 * without an inventory_movements row written in the same transaction, and it
 * never drops below quantity_reserved.
 */

if (defined('INV_LIB_LOADED')) {
    return;
}
define('INV_LIB_LOADED', true);

const INV_REASON_MAX = 180;     // leaves room for the prefix inside VARCHAR(255)
const INV_MAX_QTY    = 100000;
const INV_PAGE_SIZE  = 50;

/* ------------------------------------------------------------------ */
/* Small database helpers (positional ? placeholders only)             */
/* ------------------------------------------------------------------ */

function inv_q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st;
}

function inv_all(string $sql, array $params = []): array
{
    return inv_q($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}

function inv_one(string $sql, array $params = []): ?array
{
    $row = inv_q($sql, $params)->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function inv_value(string $sql, array $params = [])
{
    $row = inv_q($sql, $params)->fetch(PDO::FETCH_NUM);
    return $row === false ? null : $row[0];
}

function inv_exec(string $sql, array $params = []): int
{
    return inv_q($sql, $params)->rowCount();
}

function inv_insert(string $sql, array $params = []): int
{
    inv_q($sql, $params);
    return (int) db()->lastInsertId();
}

/**
 * Run $fn in a transaction (joins an outer one if open) and retry on deadlock.
 * The result is captured by reference so we do not depend on what
 * db_transaction() returns.
 */
function inv_tx(callable $fn)
{
    $outer  = db()->inTransaction();
    $result = null;
    $tries  = 0;
    while (true) {
        $tries++;
        try {
            db_transaction(function () use ($fn, &$result) {
                $result = $fn();
            });
            return $result;
        } catch (PDOException $ex) {
            $deadlock = $ex->getCode() === '40001'
                || (isset($ex->errorInfo[1]) && (int) $ex->errorInfo[1] === 1213);
            if ($deadlock && !$outer && $tries < 3) {
                usleep(50000 * $tries);
                continue;
            }
            throw $ex;
        }
    }
}

function inv_is_duplicate(PDOException $ex): bool
{
    return isset($ex->errorInfo[1]) && (int) $ex->errorInfo[1] === 1062;
}

function inv_audit(?int $user_id, string $action, string $entity_type, $entity_id, $old = null, $new = null): void
{
    try {
        inv_q(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $user_id,
                $action,
                $entity_type,
                $entity_id === null ? null : (string) $entity_id,
                $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
                $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
                isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null,
            ]
        );
    } catch (PDOException $ex) {
        // An audit failure must not undo the real work, but leave a trace.
        error_log('inv_audit failed: ' . $ex->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* Input helpers                                                       */
/* ------------------------------------------------------------------ */

function inv_clean($v): string
{
    return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $v));
}

function inv_strlen(string $s): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s, 'UTF-8');
    }
    return (int) preg_match_all('/./su', $s);
}

function inv_substr(string $s, int $len): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $len, 'UTF-8');
    }
    return substr($s, 0, $len);
}

/** Non-negative integer from user input, or null when invalid / out of range. */
function inv_int($raw, int $min, int $max): ?int
{
    $s = trim((string) $raw);
    if (!preg_match('/^\d{1,9}$/', $s)) {
        return null;
    }
    $n = (int) $s;
    return ($n >= $min && $n <= $max) ? $n : null;
}

/** "1,250.5" or "₱1250" -> "1250.50"; null when not a valid money amount. */
function inv_parse_money($raw): ?string
{
    $s = str_replace(['₱', ',', ' '], '', trim((string) $raw));
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $s)) {
        return null;
    }
    return number_format((float) $s, 2, '.', '');
}

/** Valid Y-m-d date string or null. */
function inv_parse_date($raw): ?string
{
    $s = trim((string) $raw);
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}

function inv_like(string $s): string
{
    return addcslashes($s, '\\%_');
}

function inv_post_checkbox(array $post, string $key): int
{
    return !empty($post[$key]) ? 1 : 0;
}

/* ------------------------------------------------------------------ */
/* Permissions                                                         */
/* ------------------------------------------------------------------ */

/**
 * Who may edit the catalog (items, sizes, prices, images).
 * Stock counts and restock/waitlist use the Session 2 permissions
 * (inventory.count, restock.manage). Override in config.php:
 *   'inventory' => ['catalog_roles' => ['staff','manager','admin']]
 */
function inv_catalog_roles(): array
{
    $roles = config('inventory.catalog_roles', ['manager', 'admin']);
    return is_array($roles) && $roles ? array_values($roles) : ['manager', 'admin'];
}

function inv_can_manage_catalog(): bool
{
    $u = current_user();
    return $u !== null && in_array($u['role'], inv_catalog_roles(), true);
}

/* ------------------------------------------------------------------ */
/* Migration guard (005 must be applied)                               */
/* ------------------------------------------------------------------ */

function inv_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        $cache[$key] = (int) inv_value(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        ) > 0;
    }
    return $cache[$key];
}

/** Returns an error message when migration 005 is missing, else null. */
function inv_migration_problem(): ?string
{
    static $problem = false;
    if ($problem !== false) {
        return $problem;
    }
    $problem = null;
    try {
        $type = (string) inv_value(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'restock_schedule' AND COLUMN_NAME = 'status'"
        );
        if (!inv_column_exists('waitlist', 'status') || strpos($type, 'cancelled') === false) {
            $problem = 'Database migration 005 has not been applied yet. Import '
                . 'database/migrations/005_inventory_management.sql (phpMyAdmin > Import), then reload this page.';
        }
    } catch (PDOException $ex) {
        $problem = 'The database could not be checked: ' . $ex->getMessage();
    }
    return $problem;
}

/** Echo the migration warning; returns true when the page must stop. */
function inv_migration_block(): bool
{
    $problem = inv_migration_problem();
    if ($problem === null) {
        return false;
    }
    echo '<div class="inv-alert inv-alert-error" role="alert">' . e($problem) . '</div>';
    return true;
}

/* ------------------------------------------------------------------ */
/* Display helpers                                                     */
/* ------------------------------------------------------------------ */

function inv_item_types(): array
{
    return [
        'ready_made'    => 'Ready-made (stocked)',
        'made_to_order' => 'Made to order',
        'rental'        => 'Rental (toga)',
        'alteration'    => 'Alteration service',
    ];
}

function inv_reservation_modes(): array
{
    return [
        'auto'     => 'Auto-approve (hold stock immediately)',
        'approval' => 'Staff approval required',
        'none'     => 'Counter only (no online reservation)',
    ];
}

function inv_stock_state(int $available, int $low, bool $tracks = true): string
{
    if (!$tracks) {
        return 'na';
    }
    if ($available <= 0) {
        return 'out';
    }
    return $available <= $low ? 'low' : 'in';
}

function inv_state_badge(string $state): string
{
    $labels = ['out' => 'Out of stock', 'low' => 'Low stock', 'in' => 'In stock', 'na' => 'Not tracked'];
    $label  = $labels[$state] ?? $state;
    return '<span class="inv-badge inv-badge-' . e($state) . '">' . e($label) . '</span>';
}

function inv_movement_types(): array
{
    return [
        'restock'    => 'Restock',
        'reserved'   => 'Reserved',
        'released'   => 'Released',
        'sold'       => 'Sold',
        'rented_out' => 'Rented out',
        'returned'   => 'Returned',
        'adjustment' => 'Adjustment',
    ];
}

function inv_signed(int $n): string
{
    return $n > 0 ? '+' . $n : (string) $n;
}

/** Sub-navigation shown on every inventory page, plus any waitlist notices. */
function inv_subnav(string $active): string
{
    $links = [
        'overview'  => ['Overview',  'admin/inventory.php', 'inventory.view'],
        'items'     => ['Items',     'admin/items.php',     'inventory.view'],
        'movements' => ['Movements', 'admin/movements.php', 'inventory.view'],
        'restock'   => ['Restock schedule', 'admin/restock.php', 'restock.view'],
        'waitlist'  => ['Waitlist',  'admin/waitlist.php',  'restock.view'],
    ];
    $html = '<link rel="stylesheet" href="' . e(asset('css/inventory.css')) . '">';
    $html .= '<nav class="inv-subnav" aria-label="Inventory sections">';
    foreach ($links as $key => [$label, $path, $perm]) {
        if (!can($perm)) {
            continue;
        }
        $cls = $key === $active ? ' class="is-active" aria-current="page"' : '';
        $html .= '<a href="' . e(url($path)) . '"' . $cls . '>' . e($label) . '</a>';
    }
    $html .= '</nav>';
    return $html . inv_render_notices();
}

/* Waitlist notices: set when stock arrives, shown once on the next page. */

function inv_notice_waitlist(int $variant_id): void
{
    $row = inv_one(
        "SELECT v.size_label, i.name,
                (SELECT COUNT(*) FROM waitlist w WHERE w.variant_id = v.variant_id AND w.status = 'waiting') AS waiting
           FROM item_variants v JOIN items i ON i.item_id = v.item_id
          WHERE v.variant_id = ?",
        [$variant_id]
    );
    if ($row && (int) $row['waiting'] > 0) {
        $_SESSION['inv_waitlist_notices'][$variant_id] = [
            'item'    => $row['name'],
            'size'    => $row['size_label'],
            'waiting' => (int) $row['waiting'],
        ];
    }
}

function inv_render_notices(): string
{
    $notices = $_SESSION['inv_waitlist_notices'] ?? [];
    unset($_SESSION['inv_waitlist_notices']);
    $html = '';
    foreach ($notices as $variant_id => $n) {
        $people = $n['waiting'] === 1 ? '1 customer is' : $n['waiting'] . ' customers are';
        $html .= '<div class="inv-alert inv-alert-waitlist" role="alert"><strong>Customers are waiting.</strong> '
            . e($people) . ' on the waitlist for ' . e($n['item']) . ', size ' . e($n['size'])
            . '. The system does not send messages: please call or text them. '
            . '<a href="' . e(url('admin/waitlist.php', ['variant' => $variant_id])) . '">Open the waitlist</a></div>';
    }
    return $html;
}

/* ------------------------------------------------------------------ */
/* Stock adjustments                                                   */
/* ------------------------------------------------------------------ */

function inv_adjust_types(): array
{
    return [
        'receive' => 'Receive stock (add units)',
        'count'   => 'Correct a count (enter the total counted)',
        'damage'  => 'Record damage (remove units)',
        'loss'    => 'Record loss or missing (remove units)',
    ];
}

/**
 * The adjustment itself. Must run inside a transaction; throws DomainException
 * with a message that is safe to show. Returns [before, after, delta].
 */
function inv_adjust_stock_tx(int $variant_id, string $type, int $qty, string $reason, int $user_id, ?int $order_id = null): array
{
    if (!isset(inv_adjust_types()[$type])) {
        throw new DomainException('Unknown adjustment type.');
    }
    $reason = inv_clean($reason);
    if (inv_strlen($reason) < 3) {
        throw new DomainException('A reason is required (at least 3 characters).');
    }
    if (inv_strlen($reason) > INV_REASON_MAX) {
        throw new DomainException('The reason is too long (maximum ' . INV_REASON_MAX . ' characters).');
    }
    $min = $type === 'count' ? 0 : 1;
    if ($qty < $min || $qty > INV_MAX_QTY) {
        throw new DomainException($type === 'count'
            ? 'Enter the counted quantity (0 to ' . INV_MAX_QTY . ').'
            : 'Quantity must be between 1 and ' . INV_MAX_QTY . '.');
    }

    // Row lock first: every later read and write sees a stable quantity.
    $v = inv_one('SELECT variant_id, item_id, quantity_on_hand, quantity_reserved FROM item_variants WHERE variant_id = ? FOR UPDATE', [$variant_id]);
    if (!$v) {
        throw new DomainException('That size does not exist.');
    }
    $tracks = inv_value('SELECT tracks_stock FROM items WHERE item_id = ?', [(int) $v['item_id']]);
    if ((int) $tracks !== 1) {
        throw new DomainException('This item is made to order or a service, so it has no stock count.');
    }

    $on_hand  = (int) $v['quantity_on_hand'];
    $reserved = (int) $v['quantity_reserved'];

    switch ($type) {
        case 'receive':
            $new = $on_hand + $qty;
            $label = 'Stock received';
            break;
        case 'count':
            $new = $qty;
            $label = 'Count correction (system ' . $on_hand . ' to counted ' . $qty . ')';
            if ($new === $on_hand) {
                throw new DomainException('The counted quantity matches the system (' . $on_hand . '). Nothing was changed.');
            }
            break;
        case 'damage':
            $new = $on_hand - $qty;
            $label = 'Damaged';
            break;
        default:
            $new = $on_hand - $qty;
            $label = 'Lost or missing';
    }

    if ($new < 0) {
        throw new DomainException('Cannot remove ' . $qty . ': only ' . $on_hand . ' on hand.');
    }
    if ($new < $reserved) {
        $room = max(0, $on_hand - $reserved);
        throw new DomainException(
            'Blocked: that would leave ' . $new . ' on hand but ' . $reserved . ' unit(s) are reserved by open orders. '
            . 'On hand can never go below reserved. You can remove at most ' . $room
            . ' unit(s) now; to go lower, cancel or release the reserved orders first.'
        );
    }

    $delta = $new - $on_hand;
    inv_q('UPDATE item_variants SET quantity_on_hand = ? WHERE variant_id = ?', [$new, $variant_id]);
    inv_q(
        'INSERT INTO inventory_movements (variant_id, movement_type, on_hand_delta, reserved_delta, order_id, user_id, reason)
         VALUES (?, ?, ?, 0, ?, ?, ?)',
        [$variant_id, $type === 'receive' ? 'restock' : 'adjustment', $delta, $order_id, $user_id, $label . ': ' . $reason]
    );
    return ['before' => $on_hand, 'after' => $new, 'delta' => $delta];
}

/** Public entry point: runs the adjustment atomically and reports the result. */
function inv_adjust_stock(int $variant_id, string $type, int $qty, string $reason, int $user_id): array
{
    try {
        $r = inv_tx(function () use ($variant_id, $type, $qty, $reason, $user_id) {
            return inv_adjust_stock_tx($variant_id, $type, $qty, $reason, $user_id);
        });
    } catch (DomainException $ex) {
        return ['ok' => false, 'message' => $ex->getMessage()];
    } catch (PDOException $ex) {
        error_log('inv_adjust_stock: ' . $ex->getMessage());
        return ['ok' => false, 'message' => 'The stock change could not be saved. Please try again.'];
    }
    if ($r['delta'] > 0) {
        inv_notice_waitlist($variant_id);
    }
    return ['ok' => true, 'message' => 'Stock updated: ' . $r['before'] . ' to ' . $r['after'] . ' on hand (' . inv_signed($r['delta']) . ').'] + $r;
}

/* ------------------------------------------------------------------ */
/* Inventory overview                                                  */
/* ------------------------------------------------------------------ */

function inv_overview_filters(array $get): array
{
    $scope  = (string) ($get['scope'] ?? 'active');
    $status = (string) ($get['status'] ?? 'all');
    return [
        'category' => (int) ($get['category'] ?? 0),
        'status'   => in_array($status, ['all', 'attention', 'in', 'low', 'out', 'untracked'], true) ? $status : 'all',
        'scope'    => in_array($scope, ['active', 'inactive', 'all'], true) ? $scope : 'active',
        'q'        => inv_substr(inv_clean($get['q'] ?? ''), 60),
        'page'     => max(1, (int) ($get['page'] ?? 1)),
    ];
}

function inv_overview_query(array $f): array
{
    $avail = '(v.quantity_on_hand - v.quantity_reserved)';
    $w = [];
    $p = [];
    if ($f['scope'] === 'active') {
        $w[] = 'i.is_active = 1 AND v.is_active = 1';
    } elseif ($f['scope'] === 'inactive') {
        $w[] = '(i.is_active = 0 OR v.is_active = 0)';
    }
    if ($f['category'] > 0) {
        $w[] = 'i.category_id = ?';
        $p[] = $f['category'];
    }
    if ($f['q'] !== '') {
        $like = '%' . inv_like($f['q']) . '%';
        $w[] = '(i.name LIKE ? OR i.item_code LIKE ? OR v.sku LIKE ? OR v.size_label LIKE ?)';
        array_push($p, $like, $like, $like, $like);
    }
    switch ($f['status']) {
        case 'out':
            $w[] = "i.tracks_stock = 1 AND $avail <= 0";
            break;
        case 'low':
            $w[] = "i.tracks_stock = 1 AND $avail > 0 AND $avail <= v.low_stock_threshold";
            break;
        case 'in':
            $w[] = "i.tracks_stock = 1 AND $avail > v.low_stock_threshold";
            break;
        case 'attention':
            $w[] = "i.tracks_stock = 1 AND $avail <= v.low_stock_threshold";
            break;
        case 'untracked':
            $w[] = 'i.tracks_stock = 0';
            break;
    }
    $from = 'FROM item_variants v
             JOIN items i ON i.item_id = v.item_id
             JOIN categories c ON c.category_id = i.category_id
             LEFT JOIN education_levels el ON el.education_level_id = i.education_level_id';
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    return [$from, $where, $p];
}

function inv_overview_count(array $f): int
{
    [$from, $where, $p] = inv_overview_query($f);
    return (int) inv_value("SELECT COUNT(*) $from $where", $p);
}

function inv_overview_rows(array $f, int $offset = 0, ?int $limit = null): array
{
    [$from, $where, $p] = inv_overview_query($f);
    $sql = "SELECT v.variant_id, v.size_label, v.sku, v.unit_price, v.quantity_on_hand, v.quantity_reserved,
                   (v.quantity_on_hand - v.quantity_reserved) AS available,
                   v.low_stock_threshold, v.is_active AS variant_active,
                   i.item_id, i.item_code, i.name AS item_name, i.tracks_stock, i.is_active AS item_active,
                   c.name AS category_name, el.name AS level_name,
                   (SELECT COUNT(*) FROM waitlist w WHERE w.variant_id = v.variant_id AND w.status = 'waiting') AS waiting
              $from $where
             ORDER BY i.name, v.variant_id";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
    }
    return inv_all($sql, $p);
}

/** Headline numbers for active items and sizes. */
function inv_overview_summary(): array
{
    $avail = '(v.quantity_on_hand - v.quantity_reserved)';
    $row = inv_one(
        "SELECT COUNT(*) AS sizes,
                COALESCE(SUM(CASE WHEN i.tracks_stock = 1 AND $avail <= 0 THEN 1 ELSE 0 END), 0) AS out_count,
                COALESCE(SUM(CASE WHEN i.tracks_stock = 1 AND $avail > 0 AND $avail <= v.low_stock_threshold THEN 1 ELSE 0 END), 0) AS low_count,
                COALESCE(SUM(CASE WHEN i.tracks_stock = 1 THEN v.quantity_on_hand ELSE 0 END), 0) AS units_on_hand,
                COALESCE(SUM(CASE WHEN i.tracks_stock = 1 THEN v.quantity_reserved ELSE 0 END), 0) AS units_reserved
           FROM item_variants v JOIN items i ON i.item_id = v.item_id
          WHERE i.is_active = 1 AND v.is_active = 1"
    );
    return $row ?: ['sizes' => 0, 'out_count' => 0, 'low_count' => 0, 'units_on_hand' => 0, 'units_reserved' => 0];
}

/** CSV cells starting with = + - @ are treated as formulas by Excel; neutralise them. */
function inv_csv_cell($v): string
{
    $s = (string) $v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && !is_numeric($s)) {
        $s = "'" . $s;
    }
    return $s;
}

/** Streams the filtered overview as CSV. Call before any output, then exit. */
function inv_export_csv(array $f): void
{
    $name = 'inventory-' . date('Ymd-Hi') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
    fputcsv($out, ['Item code', 'Item', 'Category', 'Education level', 'Size', 'SKU', 'Unit price',
        'On hand', 'Reserved', 'Available', 'Low-stock threshold', 'Status', 'Waiting customers', 'Item active', 'Size active']);
    foreach (inv_overview_rows($f) as $r) {
        $tracks = (int) $r['tracks_stock'] === 1;
        $state  = inv_stock_state((int) $r['available'], (int) $r['low_stock_threshold'], $tracks);
        $labels = ['out' => 'Out of stock', 'low' => 'Low stock', 'in' => 'In stock', 'na' => 'Not tracked'];
        fputcsv($out, [
            inv_csv_cell($r['item_code']), inv_csv_cell($r['item_name']), inv_csv_cell($r['category_name']),
            inv_csv_cell($r['level_name'] ?? ''), inv_csv_cell($r['size_label']), inv_csv_cell($r['sku']),
            $r['unit_price'],
            $tracks ? $r['quantity_on_hand'] : '', $tracks ? $r['quantity_reserved'] : '', $tracks ? $r['available'] : '',
            $tracks ? $r['low_stock_threshold'] : '', $labels[$state], $r['waiting'],
            (int) $r['item_active'] ? 'yes' : 'no', (int) $r['variant_active'] ? 'yes' : 'no',
        ]);
    }
    fclose($out);
}

/* ------------------------------------------------------------------ */
/* Movement history                                                    */
/* ------------------------------------------------------------------ */

function inv_movement_filters(array $get): array
{
    $type = (string) ($get['type'] ?? '');
    return [
        'item'    => (int) ($get['item'] ?? 0),
        'variant' => (int) ($get['variant'] ?? 0),
        'type'    => isset(inv_movement_types()[$type]) ? $type : '',
        'user'    => (int) ($get['user'] ?? 0),
        'from'    => inv_parse_date($get['from'] ?? '') ?? '',
        'to'      => inv_parse_date($get['to'] ?? '') ?? '',
        'page'    => max(1, (int) ($get['page'] ?? 1)),
    ];
}

function inv_movement_query(array $f): array
{
    $w = [];
    $p = [];
    if ($f['item'] > 0) {
        $w[] = 'v.item_id = ?';
        $p[] = $f['item'];
    }
    if ($f['variant'] > 0) {
        $w[] = 'm.variant_id = ?';
        $p[] = $f['variant'];
    }
    if ($f['type'] !== '') {
        $w[] = 'm.movement_type = ?';
        $p[] = $f['type'];
    }
    if ($f['user'] > 0) {
        $w[] = 'm.user_id = ?';
        $p[] = $f['user'];
    }
    if ($f['from'] !== '') {
        $w[] = 'm.created_at >= ?';
        $p[] = $f['from'] . ' 00:00:00';
    }
    if ($f['to'] !== '') {
        // inclusive end date: strictly before the next day
        $w[] = 'm.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $p[] = $f['to'] . ' 00:00:00';
    }
    $from = 'FROM inventory_movements m
             JOIN item_variants v ON v.variant_id = m.variant_id
             JOIN items i ON i.item_id = v.item_id
             LEFT JOIN users u ON u.user_id = m.user_id
             LEFT JOIN orders o ON o.order_id = m.order_id';
    return [$from, $w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

function inv_movement_count(array $f): int
{
    [$from, $where, $p] = inv_movement_query($f);
    return (int) inv_value("SELECT COUNT(*) $from $where", $p);
}

function inv_movement_rows(array $f, int $offset, int $limit): array
{
    [$from, $where, $p] = inv_movement_query($f);
    return inv_all(
        "SELECT m.movement_id, m.variant_id, m.movement_type, m.on_hand_delta, m.reserved_delta, m.order_id,
                m.reason, m.created_at, v.size_label, i.item_id, i.name AS item_name,
                u.username, u.full_name, o.order_no
           $from $where
          ORDER BY m.created_at DESC, m.movement_id DESC
          LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
        $p
    );
}

function inv_user_options(): array
{
    return inv_all('SELECT user_id, username, full_name FROM users ORDER BY full_name');
}

require_once __DIR__ . '/inventory_catalog.php';
require_once __DIR__ . '/inventory_restock.php';
