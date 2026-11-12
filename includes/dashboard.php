<?php
/**
 * Session 9: dashboard queries (prefix dash_). Read-only. Each block is shown only
 * to roles that can act on it (see admin/index.php).
 */

require_once __DIR__ . '/orders.php';

/** ['count' => int, 'rows' => [...]] for orders matching a trusted SQL condition. */
function dash_orders(string $where, string $order, int $limit = 6): array
{
    $count = (int) db_value("SELECT COUNT(*) FROM orders o WHERE o.deleted_at IS NULL AND $where");
    $rows = db_all(
        "SELECT o.order_id, o.order_ref, o.order_no, o.status, o.total_amount, o.created_at, o.expires_at,
                o.slip_issued_at, o.pickup_date, c.full_name AS customer_name, s.student_no
           FROM orders o JOIN customers c ON c.customer_id = o.customer_id
           LEFT JOIN students s ON s.student_id = o.student_id
          WHERE o.deleted_at IS NULL AND $where ORDER BY $order LIMIT " . max(1, $limit)
    );
    return ['count' => $count, 'rows' => $rows];
}

function dash_awaiting_approval(int $limit = 6): array
{
    return dash_orders("o.status = 'requested'", 'o.created_at, o.order_id', $limit);
}

/** Holds that end within the "expiring soon" window (or already ended and not yet cleaned up). */
function dash_expiring(int $limit = 6): array
{
    $h = ord_expiring_hours();
    return dash_orders(
        "o.status IN ('approved','slip_issued') AND o.expires_at IS NOT NULL AND o.expires_at <= DATE_ADD(NOW(), INTERVAL $h HOUR)",
        'o.expires_at, o.order_id', $limit
    );
}

function dash_slips_waiting(int $limit = 6): array
{
    return dash_orders("o.status = 'slip_issued'", 'o.slip_issued_at, o.order_id', $limit);
}

/** Low or out-of-stock sizes of stocked, active items. */
function dash_stock_attention(int $limit = 8): array
{
    $cond = "i.is_active = 1 AND v.is_active = 1 AND i.tracks_stock = 1
             AND (v.quantity_on_hand - v.quantity_reserved) <= v.low_stock_threshold";
    $counts = db_one(
        "SELECT COALESCE(SUM(CASE WHEN (v.quantity_on_hand - v.quantity_reserved) <= 0 THEN 1 ELSE 0 END), 0) AS out_count,
                COALESCE(SUM(CASE WHEN (v.quantity_on_hand - v.quantity_reserved) > 0 THEN 1 ELSE 0 END), 0) AS low_count
           FROM item_variants v JOIN items i ON i.item_id = v.item_id WHERE $cond"
    ) ?: ['out_count' => 0, 'low_count' => 0];
    $rows = db_all(
        "SELECT i.name AS item_name, v.variant_id, v.size_label, v.quantity_on_hand, v.quantity_reserved,
                (v.quantity_on_hand - v.quantity_reserved) AS available, v.low_stock_threshold
           FROM item_variants v JOIN items i ON i.item_id = v.item_id
          WHERE $cond ORDER BY available ASC, i.name, v.variant_id LIMIT " . max(1, $limit)
    );
    return ['out' => (int) $counts['out_count'], 'low' => (int) $counts['low_count'], 'rows' => $rows];
}

function dash_restocks(int $limit = 5): array
{
    return db_all(
        "SELECT r.restock_id, r.expected_date, r.expected_qty, r.status, i.name AS item_name, v.size_label
           FROM restock_schedule r
           LEFT JOIN item_variants v ON v.variant_id = r.variant_id
           JOIN items i ON i.item_id = COALESCE(r.item_id, v.item_id)
          WHERE r.status IN ('scheduled','delayed')
          ORDER BY r.expected_date, r.restock_id LIMIT " . max(1, $limit)
    );
}

/** Issued togas past their due date (computed from the date, so right even without the scheduler). */
function dash_overdue_togas(int $limit = 6): array
{
    $cond = "r.status IN ('released','overdue') AND r.due_date IS NOT NULL AND r.due_date < CURDATE()";
    $count = (int) db_value("SELECT COUNT(*) FROM rentals r WHERE $cond");
    $rows = db_all(
        "SELECT r.rental_id, oi.order_id, s.full_name AS student_name, s.student_no, r.due_date,
                DATEDIFF(CURDATE(), r.due_date) AS days_late, i.name AS item_name, v.size_label
           FROM rentals r
           JOIN order_items oi ON oi.order_item_id = r.order_item_id
           JOIN students s ON s.student_id = r.student_id
           JOIN item_variants v ON v.variant_id = r.variant_id
           JOIN items i ON i.item_id = v.item_id
          WHERE $cond ORDER BY r.due_date, r.rental_id LIMIT " . max(1, $limit)
    );
    return ['count' => $count, 'rows' => $rows];
}

function dash_recycle_count(): int
{
    return (int) db_value('SELECT COUNT(*) FROM orders WHERE deleted_at IS NOT NULL');
}

/** "in 5 h", "in 2 days", "ended 3 h ago". Uses PHP time; keep PHP and MySQL timezones aligned. */
function dash_relative(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    $d = strtotime($dt) - time();
    $abs = abs($d);
    if ($abs < 3600) {
        $t = max(1, (int) round($abs / 60)) . ' min';
    } elseif ($abs < 172800) {
        $t = (int) round($abs / 3600) . ' h';
    } else {
        $t = (int) round($abs / 86400) . ' days';
    }
    return $d >= 0 ? 'in ' . $t : 'ended ' . $t . ' ago';
}
