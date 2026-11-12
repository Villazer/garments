<?php
/**
 * Session 9: recycle bin (prefix rb_).
 * Orders with deleted_at set are "in the bin". Expiry puts orders there; nothing
 * is hard-deleted except by an admin's confirmed purge (or the existing daily
 * MySQL event sp_purge_recycle_bin once purge_after has passed).
 *
 * Restore differs from the schema's sp_restore_order on purpose: it re-reserves the
 * stock inside one transaction and returns the order to Approved with a fresh hold,
 * and it refuses when the stock is no longer there. Nothing is changed on refusal.
 */

require_once __DIR__ . '/orders.php';

function rb_filters(array $get): array
{
    $status = (string) ($get['status'] ?? '');
    return [
        'status' => in_array($status, ['expired', 'other'], true) ? $status : '',
        'q'      => function_exists('mb_substr') ? mb_substr(clean_str($get['q'] ?? ''), 0, 60) : substr(clean_str($get['q'] ?? ''), 0, 60),
        'page'   => max(1, (int) ($get['page'] ?? 1)),
    ];
}

function rb_where(array $f): array
{
    $w = ['o.deleted_at IS NOT NULL'];
    $p = [];
    if ($f['status'] === 'expired') {
        $w[] = "o.status = 'expired'";
    } elseif ($f['status'] === 'other') {
        $w[] = "o.status <> 'expired'";
    }
    if ($f['q'] !== '') {
        $like = '%' . addcslashes($f['q'], '\\%_') . '%';
        $w[] = '(o.order_ref LIKE ? OR o.order_no LIKE ? OR c.full_name LIKE ? OR s.student_no LIKE ?)';
        array_push($p, $like, $like, $like, $like);
    }
    return [implode(' AND ', $w), $p];
}

function rb_count(array $f): int
{
    [$where, $p] = rb_where($f);
    return (int) ord_value(
        "SELECT COUNT(*) FROM orders o JOIN customers c ON c.customer_id = o.customer_id
           LEFT JOIN students s ON s.student_id = o.student_id WHERE $where",
        $p
    );
}

function rb_rows(array $f, int $offset, int $per_page): array
{
    [$where, $p] = rb_where($f);
    return ord_all(
        "SELECT o.*, c.full_name AS customer_name, c.contact_no, s.student_no, s.full_name AS student_name,
                (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS item_count,
                (SELECT COUNT(*) FROM payments pm WHERE pm.order_id = o.order_id) AS pay_count
           FROM orders o JOIN customers c ON c.customer_id = o.customer_id
           LEFT JOIN students s ON s.student_id = o.student_id
          WHERE $where ORDER BY o.deleted_at DESC, o.order_id DESC
          LIMIT " . max(1, $per_page) . ' OFFSET ' . max(0, $offset),
        $p
    );
}

function rb_find(int $id): ?array
{
    return ord_one(
        'SELECT o.*, c.full_name AS customer_name, s.student_no, s.full_name AS student_name
           FROM orders o JOIN customers c ON c.customer_id = o.customer_id
           LEFT JOIN students s ON s.student_id = o.student_id
          WHERE o.order_id = ? AND o.deleted_at IS NOT NULL',
        [$id]
    );
}

/**
 * Can this expired order be restored right now? Returns a list of problems
 * (empty = yes). Only a preview: the real check is repeated under row locks.
 */
function rb_restore_problems(array $o): array
{
    if ($o['status'] !== 'expired') {
        return [];
    }
    $oid = (int) $o['order_id'];
    $lines = ord_lines($oid);
    $problems = [];
    $need = ord_variant_totals($lines, fn($l) => (int) $l['tracks_stock'] === 1 && (int) $l['stock_held'] === 0);
    foreach ($need as $vid => $qty) {
        $v = ord_one('SELECT quantity_on_hand, quantity_reserved, is_active FROM item_variants WHERE variant_id = ?', [$vid]);
        $avail = $v ? (int) $v['quantity_on_hand'] - (int) $v['quantity_reserved'] : 0;
        if (!$v || !$v['is_active'] || $avail < $qty) {
            $problems[] = ord_line_name($lines, $vid) . ': ' . max(0, $avail) . ' available, ' . $qty . ' needed';
        }
    }
    $period = $o['academic_period_id'] !== null
        ? (int) $o['academic_period_id']
        : (int) ord_value('SELECT academic_period_id FROM academic_periods WHERE is_current = 1 LIMIT 1');
    foreach ($lines as $l) {
        if ($l['item_type'] === 'rental' && $o['student_id'] !== null && $period > 0) {
            $n = (int) ord_value(
                "SELECT COUNT(*) FROM rentals WHERE student_id = ? AND academic_period_id = ?
                    AND status IN ('reserved','released','overdue') AND order_item_id <> ?",
                [$o['student_id'], $period, $l['order_item_id']]
            );
            if ($n > 0) {
                $problems[] = 'the student already has an active toga rental in this period';
            }
        }
    }
    return array_values(array_unique($problems));
}

/**
 * Restore an order from the bin. Expired orders go back to Approved with their
 * stock re-reserved and a fresh hold; any other removed order just leaves the bin.
 * @return array ['ok'=>bool,'message'=>string]
 */
function rb_restore(int $order_id, int $user_id): array
{
    $tries = 0;
    while (true) {
        $tries++;
        try {
            return db_transaction(function () use ($order_id, $user_id) {
                $o = ord_one('SELECT * FROM orders WHERE order_id = ? FOR UPDATE', [$order_id]);
                if (!$o || $o['deleted_at'] === null) {
                    throw new DomainException('That order is not in the recycle bin.');
                }
                $from = $o['status'];
                if ($from !== 'expired') {
                    ord_q('UPDATE orders SET deleted_at = NULL, deleted_by = NULL, purge_after = NULL WHERE order_id = ?', [$order_id]);
                    ord_audit($user_id, 'restore_order', $order_id, ['status' => $from], ['status' => $from]);
                    return ['ok' => true, 'message' => 'Order restored from the recycle bin (status unchanged: ' . ord_label($from) . ').'];
                }

                $last_move = (int) ord_value('SELECT COALESCE(MAX(movement_id), 0) FROM inventory_movements');
                ord_reserve_stock($o, $user_id);   // locks variants in order, throws DomainException when short
                ord_q(
                    "UPDATE inventory_movements SET reason = 'Restored from recycle bin'
                      WHERE order_id = ? AND movement_type = 'reserved' AND movement_id > ? AND user_id = ?",
                    [$order_id, $last_move, $user_id]
                );

                $now = (string) ord_value('SELECT NOW()');
                $hours = max(1, (int) get_setting('hold_unpaid_hours', 48));
                $hold_until = (new DateTime($now))->modify("+{$hours} hours")->format('Y-m-d H:i:s');
                $st = ord_q(
                    "UPDATE orders SET status = 'approved', approved_by = ?, approved_at = ?, expires_at = ?,
                            deleted_at = NULL, deleted_by = NULL, purge_after = NULL
                      WHERE order_id = ? AND status = 'expired'",
                    [$user_id, $now, $hold_until, $order_id]
                );
                if ($st->rowCount() !== 1) {
                    throw new DomainException('The order was changed by someone else. Reload the page and try again.');
                }
                ord_audit($user_id, 'restore_order', $order_id, ['status' => 'expired'],
                    ['status' => 'approved', 'expires_at' => $hold_until, 'stock_reserved' => true]);
                return ['ok' => true, 'message' => 'Order restored: stock re-reserved and the order is Approved again. The hold ends ' . format_datetime($hold_until) . '.'];
            });
        } catch (DomainException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (PDOException $e) {
            $deadlock = ($e->errorInfo[0] ?? '') === '40001' || (int) ($e->errorInfo[1] ?? 0) === 1213;
            if ($deadlock && $tries < 3) {
                usleep(100000 * $tries);
                continue;
            }
            error_log('rb_restore: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The order could not be restored because of a database error. Nothing was changed.'];
        }
    }
}

/**
 * Permanently delete an order that is in the bin. The reference number must be
 * typed to confirm. Orders with payment records are never purged. The audit row
 * is written first, in the same transaction, so a purge always leaves a trace.
 */
function rb_purge(int $order_id, int $user_id, string $typed_ref): array
{
    try {
        db_transaction(function () use ($order_id, $user_id, $typed_ref) {
            $o = ord_one('SELECT * FROM orders WHERE order_id = ? FOR UPDATE', [$order_id]);
            if (!$o || $o['deleted_at'] === null) {
                throw new DomainException('Only orders in the recycle bin can be purged.');
            }
            if (strtoupper(trim($typed_ref)) !== strtoupper((string) $o['order_ref'])) {
                throw new DomainException('The reference number you typed does not match. Nothing was deleted.');
            }
            if ((int) ord_value('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$order_id]) > 0) {
                throw new DomainException('This order has payment records, so it cannot be purged.');
            }
            if ((int) ord_value('SELECT COUNT(*) FROM order_items WHERE order_id = ? AND stock_held = 1', [$order_id]) > 0) {
                throw new DomainException('This order still holds reserved stock, so it cannot be purged.');
            }
            ord_audit($user_id, 'order_purged', $order_id, [
                'order_ref' => $o['order_ref'], 'order_no' => $o['order_no'], 'status' => $o['status'],
                'total_amount' => $o['total_amount'], 'customer_id' => $o['customer_id'], 'student_id' => $o['student_id'],
                'created_at' => $o['created_at'],
            ], null);
            ord_q('DELETE FROM orders WHERE order_id = ?', [$order_id]);   // lines, measurements and rentals cascade
        });
    } catch (DomainException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    } catch (PDOException $e) {
        error_log('rb_purge: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'The order could not be purged because of a database error. Nothing was deleted.'];
    }
    return ['ok' => true, 'message' => 'Order permanently deleted.'];
}
