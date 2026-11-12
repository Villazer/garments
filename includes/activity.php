<?php
/**
 * Session 9: activity log viewer (prefix act_). Read-only.
 * The feed is audit_logs plus manual stock changes from inventory_movements
 * (receives and adjustments have no audit row of their own). Rate-limit rows
 * (entity_type rate_limit) are noise and never shown.
 */

require_once __DIR__ . '/orders.php';

const ACT_PAGE_SIZE = 50;

/** Category key => [label, SQL condition on the feed alias x]. */
function act_categories(): array
{
    $logins = "x.action IN ('login_success','login_failed','logout','password_check_failed','password_changed','password_reset','password_reset_cli')";
    $settings = "x.action IN ('setting_changed','academic_period_changed','academic_period_added')";
    $reverts = "(x.action = 'order_status_changed' AND x.new_values LIKE '%\"kind\":\"revert\"%')";
    $stock = "(x.src = 'stock' OR x.action = 'restock_received')";
    $purges = "x.action IN ('order_purged','restore_order')";
    $users = "x.action IN ('user_created','user_updated','user_deactivated','user_reactivated')";
    $system = "x.action = 'auto_expiry_run'";
    return [
        'sensitive' => ['Sensitive actions (all of the below)', "($logins OR $settings OR $reverts OR $stock OR $purges OR $users OR $system)"],
        'logins'    => ['Logins and passwords', $logins],
        'settings'  => ['Setting changes', $settings],
        'reverts'   => ['Order status reverts', $reverts],
        'stock'     => ['Stock adjustments and receipts', $stock],
        'purges'    => ['Purges and restores', $purges],
        'users'     => ['User accounts', $users],
        'system'    => ['Auto-expiry runs', $system],
        'orders'    => ['All order activity', "x.action IN ('order_status_changed','order_note_added','order_cancelled_by_customer','order_created_online','toga_issued','toga_returned','restore_order','order_purged')"],
        'all'       => ['Everything', '1=1'],
    ];
}

function act_filters(array $get): array
{
    $cat = (string) ($get['cat'] ?? 'sensitive');
    return [
        'cat'  => isset(act_categories()[$cat]) ? $cat : 'sensitive',
        'user' => max(0, (int) ($get['user'] ?? 0)),
        'from' => ord_parse_date($get['from'] ?? '') ?? '',
        'to'   => ord_parse_date($get['to'] ?? '') ?? '',
        'q'    => function_exists('mb_substr') ? mb_substr(clean_str($get['q'] ?? ''), 0, 60) : substr(clean_str($get['q'] ?? ''), 0, 60),
        'page' => max(1, (int) ($get['page'] ?? 1)),
    ];
}

function act_query_params(array $f): array
{
    $q = [];
    foreach (['user', 'from', 'to', 'q'] as $k) {
        if ($f[$k] !== '' && $f[$k] !== 0) {
            $q[$k] = $f[$k];
        }
    }
    if ($f['cat'] !== 'sensitive') {
        $q['cat'] = $f['cat'];
    }
    return $q;
}

/** UNION of the two sources. Collations are forced so MariaDB does not refuse the UNION. */
function act_feed_sql(): string
{
    $c = 'COLLATE utf8mb4_unicode_ci';
    return "SELECT 'audit' AS src, a.audit_id AS id, a.created_at, a.user_id, a.action, a.entity_type, a.entity_id,
                   a.old_values, a.new_values, a.ip_address
              FROM audit_logs a WHERE a.entity_type <> 'rate_limit'
            UNION ALL
            SELECT 'stock', m.movement_id, m.created_at, m.user_id,
                   CONCAT('stock_', m.movement_type) $c, 'item_variants',
                   CAST(m.variant_id AS CHAR CHARACTER SET utf8mb4) $c, NULL,
                   CONCAT('{\"on_hand_delta\":', m.on_hand_delta, ',\"reserved_delta\":', m.reserved_delta,
                          ',\"reason\":', JSON_QUOTE(COALESCE(m.reason, '')), '}') $c, NULL
              FROM inventory_movements m
             WHERE m.user_id IS NOT NULL AND m.order_id IS NULL AND m.movement_type IN ('restock','adjustment')";
}

function act_where(array $f): array
{
    $w = [act_categories()[$f['cat']][1]];
    $p = [];
    if ($f['user'] > 0) {
        $w[] = 'x.user_id = ?';
        $p[] = $f['user'];
    }
    if ($f['from'] !== '') {
        $w[] = 'x.created_at >= ?';
        $p[] = $f['from'] . ' 00:00:00';
    }
    if ($f['to'] !== '') {
        $w[] = 'x.created_at < ?';
        $p[] = (new DateTime($f['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }
    if ($f['q'] !== '') {
        $like = '%' . addcslashes($f['q'], '\\%_') . '%';
        $w[] = '(x.action LIKE ? OR x.entity_id LIKE ? OR x.entity_type LIKE ? OR x.new_values LIKE ?)';
        array_push($p, $like, $like, $like, $like);
    }
    return [implode(' AND ', $w), $p];
}

function act_count(array $f): int
{
    [$where, $p] = act_where($f);
    return (int) ord_value('SELECT COUNT(*) FROM (' . act_feed_sql() . ") x WHERE $where", $p);
}

function act_rows(array $f, int $offset, int $limit): array
{
    [$where, $p] = act_where($f);
    return ord_all(
        'SELECT x.*, u.username, u.full_name FROM (' . act_feed_sql() . ") x
           LEFT JOIN users u ON u.user_id = x.user_id
          WHERE $where ORDER BY x.created_at DESC, x.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
        $p
    );
}

function act_users(): array
{
    return ord_all('SELECT user_id, full_name, username FROM users ORDER BY full_name');
}

function act_label(string $action): string
{
    $known = [
        'login_success' => 'Signed in', 'login_failed' => 'Failed sign-in', 'logout' => 'Signed out',
        'password_check_failed' => 'Wrong current password', 'password_changed' => 'Password changed',
        'password_reset' => 'Password reset by admin', 'password_reset_cli' => 'Password set (command line)',
        'setting_changed' => 'Setting changed', 'academic_period_changed' => 'Current academic period changed',
        'academic_period_added' => 'Academic period added', 'order_status_changed' => 'Order status changed',
        'order_note_added' => 'Order note', 'order_purged' => 'Order purged', 'restore_order' => 'Order restored',
        'auto_expiry_run' => 'Auto-expiry run', 'stock_restock' => 'Stock received', 'stock_adjustment' => 'Stock adjusted',
        'restock_received' => 'Restock received', 'user_created' => 'User created', 'user_updated' => 'User updated',
        'user_deactivated' => 'User deactivated', 'user_reactivated' => 'User reactivated',
        'toga_issued' => 'Toga issued', 'toga_returned' => 'Toga returned',
        'order_cancelled_by_customer' => 'Cancelled by customer', 'order_created_online' => 'Order placed online',
    ];
    return $known[$action] ?? ucfirst(str_replace('_', ' ', $action));
}

/** One readable line describing the row. Plain text; the page escapes it. */
function act_detail(array $r): string
{
    $old = $r['old_values'] ? (json_decode((string) $r['old_values'], true) ?: []) : [];
    $new = $r['new_values'] ? (json_decode((string) $r['new_values'], true) ?: []) : [];
    switch ($r['action']) {
        case 'order_status_changed':
            $t = ord_label((string) ($new['from'] ?? '?')) . ' → ' . ord_label((string) ($new['to'] ?? '?')) . (($new['kind'] ?? '') === 'revert' ? ' (revert)' : '');
            return $t . (!empty($new['reason']) ? ' · reason: ' . $new['reason'] : '');
        case 'setting_changed':
            return $r['entity_id'] . ': ' . act_short((string) ($old['value'] ?? '')) . ' → ' . act_short((string) ($new['value'] ?? ''));
        case 'login_failed':
            return 'reason: ' . ($new['reason'] ?? 'unknown') . ' · IP ' . $r['ip_address'];
        case 'login_success':
        case 'logout':
            return 'IP ' . $r['ip_address'];
        case 'order_purged':
            return 'ref ' . ($old['order_ref'] ?? '?') . ' · slip ' . ($old['order_no'] ?? '?') . ' · was ' . ($old['status'] ?? '?') . ' · ' . money($old['total_amount'] ?? 0);
        case 'auto_expiry_run':
            return 'expired ' . (int) ($new['expired'] ?? 0) . ', failed ' . (int) ($new['failed'] ?? 0) . ' · source ' . $r['entity_id'];
        case 'academic_period_changed':
            return (string) ($new['label'] ?? '');
    }
    if ($r['src'] === 'stock') {
        $d = (int) ($new['on_hand_delta'] ?? 0);
        return 'on hand ' . ($d > 0 ? '+' : '') . $d . ' · ' . ($new['reason'] ?? '');
    }
    $parts = [];
    foreach ($new as $k => $v) {
        $parts[] = $k . ': ' . act_short(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
    }
    return act_short(implode(' · ', $parts), 160);
}

function act_short(string $s, int $max = 70): string
{
    if (function_exists('mb_strlen') && mb_strlen($s) > $max) {
        return mb_substr($s, 0, $max - 1) . '…';
    }
    return $s;
}

/** Link target for the row's subject, or null. */
function act_entity_link(array $r): ?string
{
    if ($r['entity_type'] === 'orders' && ctype_digit((string) $r['entity_id'])) {
        return url('admin/order_view.php', ['id' => (int) $r['entity_id']]);
    }
    if ($r['entity_type'] === 'item_variants' && ctype_digit((string) $r['entity_id'])) {
        return url('admin/movements.php', ['variant' => (int) $r['entity_id']]);
    }
    if ($r['entity_type'] === 'users' && ctype_digit((string) $r['entity_id'])) {
        return url('admin/user_form.php', ['id' => (int) $r['entity_id']]);
    }
    return null;
}
