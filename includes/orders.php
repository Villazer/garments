<?php
/**
 * Session 6 - staff order processing core.
 *
 * transition_order() is the ONLY function that changes orders.status.
 * Everything else here is read helpers, validators and the stock steps it uses.
 *
 * Why stock is reserved on approval: Session 4 holds stock at creation ONLY for
 * all-auto orders; orders that need approval hold nothing until staff approve
 * (same as the schema's sp_approve_order). order_items.stock_held tells us which
 * lines already hold stock, so approval reserves exactly the lines that do not.
 *
 * Status history lives in audit_logs (entity_type 'orders'); no new table.
 */

const ORD_DEFAULT_SLIP_FORMAT = '{prefix}-{year}-{seq:4}';

/* ---------------------------------------------------------------- DB helpers */

function ord_q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function ord_all(string $sql, array $params = []): array
{
    return ord_q($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}

function ord_one(string $sql, array $params = []): ?array
{
    $row = ord_q($sql, $params)->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function ord_value(string $sql, array $params = [])
{
    $v = ord_q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/* ------------------------------------------------------- status move rules */

/** Statuses that still count as "open" (can be cancelled). */
function ord_open_statuses(): array
{
    return ['requested', 'approved', 'slip_issued', 'paid', 'unclaimed'];
}

/** Normal forward moves. Any open status may also go to cancelled (with a reason). */
function ord_forward_moves(): array
{
    return [
        'requested'   => ['approved', 'declined', 'cancelled'],
        'approved'    => ['slip_issued', 'declined', 'cancelled', 'expired'],
        'slip_issued' => ['paid', 'cancelled', 'expired'],
        'paid'        => ['claimed', 'cancelled'],
        'unclaimed'   => ['claimed', 'cancelled'],   // 'unclaimed' is set by the scheduled job
    ];
}

/** Reverts: one step back, manager/admin only, reason required. */
function ord_revert_moves(): array
{
    return [
        'approved'    => 'requested',
        'slip_issued' => 'approved',
        'claimed'     => 'paid',
        'declined'    => 'requested',
        'cancelled'   => 'requested',
    ];
}

/** 'forward', 'revert' or null (not allowed). */
function ord_move_kind(string $from, string $to): ?string
{
    if (in_array($to, ord_forward_moves()[$from] ?? [], true)) {
        return 'forward';
    }
    if ((ord_revert_moves()[$from] ?? null) === $to) {
        return 'revert';
    }
    return null;
}

/** Permission needed for a move. Cancelling after payment also needs override. */
function ord_move_permission(string $from, string $to): string
{
    if (ord_move_kind($from, $to) === 'revert') {
        return 'orders.override';
    }
    if ($to === 'cancelled' && in_array($from, ['paid', 'unclaimed'], true)) {
        return 'orders.override';
    }
    return 'orders.process';
}

/** Moves a given role may start from a status: ['forward' => [...], 'revert' => [...]]. */
function ord_available_moves(string $from, string $role): array
{
    $out = ['forward' => [], 'revert' => []];
    foreach (ord_forward_moves()[$from] ?? [] as $to) {
        if (can(ord_move_permission($from, $to), $role)) {
            $out['forward'][] = $to;
        }
    }
    $rev = ord_revert_moves()[$from] ?? null;
    if ($rev !== null && can('orders.override', $role)) {
        $out['revert'][] = $rev;
    }
    return $out;
}

function ord_label(string $status): string
{
    $all = function_exists('order_statuses') ? order_statuses() : [];
    return $all[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/* ------------------------------------------------------------- validation */

function ord_parse_money($v): ?int
{
    $s = str_replace([',', '₱', ' '], '', (string) $v);
    if ($s === '' || !preg_match('/^\d{1,8}(\.\d{1,2})?$/', $s)) {
        return null;
    }
    return (int) round(((float) $s) * 100);
}

function ord_cents($decimal): int
{
    return (int) round(((float) $decimal) * 100);
}

function ord_fmt_cents(int $c): string
{
    return number_format($c / 100, 2, '.', '');
}

function ord_parse_date($v): ?string
{
    $s = trim((string) $v);
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}

function ord_strlen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

function ord_reason(array $data): string
{
    $r = clean_str($data['reason'] ?? '');
    $len = ord_strlen($r);
    if ($len < 3) {
        throw new DomainException('A reason of at least 3 characters is required.');
    }
    if ($len > 255) {
        throw new DomainException('The reason must be 255 characters or fewer.');
    }
    return $r;
}

/** Active staff user id or null (blank). Throws when the id is not an active user. */
function ord_staff_id($v, string $what, array $roles = []): ?int
{
    if ($v === null || $v === '' || $v === '0' || $v === 0) {
        return null;
    }
    $id = (int) $v;
    $u = ord_one('SELECT user_id, role, is_active FROM users WHERE user_id = ?', [$id]);
    if (!$u || !$u['is_active'] || ($roles && !in_array($u['role'], $roles, true))) {
        throw new DomainException("Choose a valid active staff member for \"$what\".");
    }
    return $id;
}

/* ------------------------------------------------------------------ audit */

function ord_audit(?int $user_id, string $action, int $order_id, ?array $old, ?array $new): void
{
    ord_q(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $user_id, $action, 'orders', (string) $order_id,
            $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]
    );
}

/* ------------------------------------------------------------ order lines */

function ord_lines(int $order_id): array
{
    return ord_all(
        'SELECT oi.*, i.tracks_stock, i.item_type
           FROM order_items oi
           JOIN item_variants v ON v.variant_id = oi.variant_id
           JOIN items i ON i.item_id = v.item_id
          WHERE oi.order_id = ?
          ORDER BY oi.variant_id, oi.order_item_id',
        [$order_id]
    );
}

function ord_lock_variant(int $variant_id): array
{
    $v = ord_one(
        'SELECT variant_id, size_label, is_active, quantity_on_hand, quantity_reserved
           FROM item_variants WHERE variant_id = ? FOR UPDATE',
        [$variant_id]
    );
    if (!$v) {
        throw new DomainException('An item in this order no longer exists.');
    }
    return $v;
}

function ord_movement(int $variant_id, string $type, int $on_hand, int $reserved, int $order_id, ?int $user_id, string $reason): void
{
    ord_q(
        'INSERT INTO inventory_movements (variant_id, movement_type, on_hand_delta, reserved_delta, order_id, user_id, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$variant_id, $type, $on_hand, $reserved, $order_id, $user_id, $reason]
    );
}

/** Sum quantities per variant for lines passing $keep, keyed by variant_id ascending (the lock order). */
function ord_variant_totals(array $lines, callable $keep): array
{
    $t = [];
    foreach ($lines as $l) {
        if ($keep($l)) {
            $t[(int) $l['variant_id']] = ($t[(int) $l['variant_id']] ?? 0) + (int) $l['quantity'];
        }
    }
    ksort($t);
    return $t;
}

function ord_line_name(array $lines, int $variant_id): string
{
    foreach ($lines as $l) {
        if ((int) $l['variant_id'] === $variant_id) {
            return $l['item_name'] . ' (size ' . $l['size_label'] . ')';
        }
    }
    return 'an item';
}

/** Hold stock for lines that do not hold it yet; create toga rental rows. */
function ord_reserve_stock(array $order, ?int $user_id): void
{
    $oid = (int) $order['order_id'];
    $lines = ord_lines($oid);
    if (!$lines) {
        throw new DomainException('This order has no items.');
    }

    $period = $order['academic_period_id'] !== null ? (int) $order['academic_period_id'] : null;
    if ($period === null) {
        $cur = ord_value('SELECT academic_period_id FROM academic_periods WHERE is_current = 1 LIMIT 1');
        if ($cur !== null) {
            $period = (int) $cur;
            ord_q('UPDATE orders SET academic_period_id = ? WHERE order_id = ?', [$period, $oid]);
        }
    }

    $need = ord_variant_totals($lines, fn($l) => (int) $l['tracks_stock'] === 1 && (int) $l['stock_held'] === 0);
    foreach ($need as $vid => $qty) {
        $v = ord_lock_variant($vid);
        $avail = (int) $v['quantity_on_hand'] - (int) $v['quantity_reserved'];
        if (!$v['is_active'] || $avail < $qty) {
            throw new DomainException(
                'Not enough stock for ' . ord_line_name($lines, $vid) . ': ' . max(0, $avail) . ' available, ' . $qty . ' needed. Nothing was reserved.'
            );
        }
        ord_q('UPDATE item_variants SET quantity_reserved = quantity_reserved + ? WHERE variant_id = ?', [$qty, $vid]);
        ord_movement($vid, 'reserved', 0, $qty, $oid, $user_id, 'Reservation approved');
    }
    ord_q(
        'UPDATE order_items oi JOIN item_variants v ON v.variant_id = oi.variant_id JOIN items i ON i.item_id = v.item_id
            SET oi.stock_held = 1 WHERE oi.order_id = ? AND i.tracks_stock = 1',
        [$oid]
    );

    foreach ($lines as $l) {
        if ($l['item_type'] !== 'rental') {
            continue;
        }
        if ($order['student_id'] === null || $period === null) {
            throw new DomainException('A toga rental needs a student record and a current academic period.');
        }
        $due = ord_value('SELECT end_date FROM academic_periods WHERE academic_period_id = ?', [$period]);
        $existing = ord_one('SELECT rental_id, status FROM rentals WHERE order_item_id = ?', [$l['order_item_id']]);
        try {
            if (!$existing) {
                ord_q(
                    "INSERT INTO rentals (order_item_id, student_id, academic_period_id, variant_id, status, due_date)
                     VALUES (?, ?, ?, ?, 'reserved', ?)",
                    [$l['order_item_id'], $order['student_id'], $period, $l['variant_id'], $due]
                );
            } elseif ($existing['status'] === 'cancelled') {
                ord_q(
                    "UPDATE rentals SET status = 'reserved', academic_period_id = ?, due_date = ? WHERE rental_id = ?",
                    [$period, $due, $existing['rental_id']]
                );
            }
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('This student already has an active toga rental for this academic period.');
            }
            throw $e;
        }
    }
}

/** Give held stock back (decline, cancel, expire, revert to requested). */
function ord_release_stock(int $oid, ?int $user_id, string $reason): void
{
    $lines = ord_lines($oid);
    foreach (ord_variant_totals($lines, fn($l) => (int) $l['stock_held'] === 1) as $vid => $qty) {
        $v = ord_lock_variant($vid);
        if ((int) $v['quantity_reserved'] < $qty) {
            throw new DomainException('Stock records are inconsistent for ' . ord_line_name($lines, $vid) . '. Ask an admin to check the inventory log.');
        }
        ord_q('UPDATE item_variants SET quantity_reserved = quantity_reserved - ? WHERE variant_id = ?', [$qty, $vid]);
        ord_movement($vid, 'released', 0, -$qty, $oid, $user_id, $reason);
    }
    ord_q('UPDATE order_items SET stock_held = 0 WHERE order_id = ?', [$oid]);
    ord_q(
        "UPDATE rentals r JOIN order_items oi ON oi.order_item_id = r.order_item_id
            SET r.status = 'cancelled' WHERE oi.order_id = ? AND r.status = 'reserved'",
        [$oid]
    );
}

/** Claim: held stock leaves on_hand and reserved together. */
function ord_claim_stock(int $oid, ?int $user_id, string $now): void
{
    $lines = ord_lines($oid);
    foreach ($lines as $l) {
        if ((int) $l['tracks_stock'] === 1 && (int) $l['stock_held'] === 0) {
            throw new DomainException('Stock was not reserved for ' . $l['item_name'] . ' (size ' . $l['size_label'] . '). Revert the order to Approved and approve it again.');
        }
    }
    foreach (ord_variant_totals($lines, fn($l) => (int) $l['stock_held'] === 1) as $vid => $qty) {
        $v = ord_lock_variant($vid);
        if ((int) $v['quantity_reserved'] < $qty || (int) $v['quantity_on_hand'] < $qty) {
            throw new DomainException('Stock records are inconsistent for ' . ord_line_name($lines, $vid) . '. Ask an admin to check the inventory log.');
        }
        ord_q(
            'UPDATE item_variants SET quantity_on_hand = quantity_on_hand - ?, quantity_reserved = quantity_reserved - ? WHERE variant_id = ?',
            [$qty, $qty, $vid]
        );
    }
    foreach ($lines as $l) {
        if ((int) $l['stock_held'] === 1) {
            ord_movement(
                (int) $l['variant_id'], $l['item_type'] === 'rental' ? 'rented_out' : 'sold',
                -(int) $l['quantity'], -(int) $l['quantity'], $oid, $user_id, 'Claimed at counter'
            );
        }
    }
    ord_q('UPDATE order_items SET stock_held = 0 WHERE order_id = ?', [$oid]);
    ord_q(
        "UPDATE rentals r JOIN order_items oi ON oi.order_item_id = r.order_item_id
            SET r.status = 'released', r.released_at = ? WHERE oi.order_id = ? AND r.status = 'reserved'",
        [$now, $oid]
    );
}

/** Revert of a claim: put the items back into stock as reserved. */
function ord_unclaim_stock(int $oid, ?int $user_id, string $reason): void
{
    $bad = ord_value(
        "SELECT COUNT(*) FROM rentals r JOIN order_items oi ON oi.order_item_id = r.order_item_id
          WHERE oi.order_id = ? AND r.status IN ('returned','overdue')",
        [$oid]
    );
    if ((int) $bad > 0) {
        throw new DomainException('The toga on this order was already returned or is overdue, so the claim cannot be reverted.');
    }
    $lines = ord_lines($oid);
    foreach (ord_variant_totals($lines, fn($l) => (int) $l['tracks_stock'] === 1 && (int) $l['stock_held'] === 0) as $vid => $qty) {
        ord_lock_variant($vid);
        ord_q(
            'UPDATE item_variants SET quantity_on_hand = quantity_on_hand + ?, quantity_reserved = quantity_reserved + ? WHERE variant_id = ?',
            [$qty, $qty, $vid]
        );
        ord_movement($vid, 'adjustment', $qty, $qty, $oid, $user_id, $reason);
    }
    ord_q(
        'UPDATE order_items oi JOIN item_variants v ON v.variant_id = oi.variant_id JOIN items i ON i.item_id = v.item_id
            SET oi.stock_held = 1 WHERE oi.order_id = ? AND i.tracks_stock = 1',
        [$oid]
    );
    ord_q(
        "UPDATE rentals r JOIN order_items oi ON oi.order_item_id = r.order_item_id
            SET r.status = 'reserved', r.released_at = NULL WHERE oi.order_id = ? AND r.status = 'released'",
        [$oid]
    );
}

/* ------------------------------------------------------- slip numbering */

function ord_format_slip_no(string $format, string $prefix, string $year, int $seq): string
{
    $out = str_replace(['{prefix}', '{year}', '{yy}'], [$prefix, $year, substr($year, 2)], $format);
    return preg_replace_callback(
        '/\{seq(?::(\d{1,2}))?\}/',
        fn($m) => str_pad((string) $seq, (int) ($m[1] ?? 0), '0', STR_PAD_LEFT),
        $out
    );
}

/**
 * Next official Order Slip No. Sequential per year (counter row in settings,
 * locked FOR UPDATE), skipping any number already used. Call inside a transaction.
 */
function ord_next_slip_no(string $now, int $order_id): string
{
    $year = substr($now, 0, 4);
    $key = 'order_slip_seq_' . $year;
    ord_q(
        "INSERT IGNORE INTO settings (setting_key, setting_value, value_type, description) VALUES (?, '0', 'int', ?)",
        [$key, 'Last order slip sequence used in ' . $year . ' (maintained by the system)']
    );
    $last = (int) ord_value('SELECT setting_value FROM settings WHERE setting_key = ? FOR UPDATE', [$key]);

    $format = (string) get_setting('order_slip_format', ORD_DEFAULT_SLIP_FORMAT);
    if (strpos($format, '{seq') === false) {
        $format = ORD_DEFAULT_SLIP_FORMAT;
    }
    $prefix = preg_replace('/[^A-Za-z0-9\-]/', '', (string) get_setting('order_no_prefix', 'OS'));

    for ($i = 0; $i < 1000; $i++) {
        $last++;
        $no = ord_format_slip_no($format, $prefix, $year, $last);
        if (strlen($no) > 20) {
            throw new DomainException('The order slip number format makes numbers longer than 20 characters. Ask an admin to shorten it.');
        }
        if (ord_value('SELECT 1 FROM orders WHERE order_no = ? AND order_id <> ?', [$no, $order_id]) === null) {
            ord_q('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [(string) $last, $key]);
            return $no;
        }
    }
    throw new DomainException('Could not find a free order slip number. Ask an admin to check the number format.');
}

/* ------------------------------------------------- the central transition */

/**
 * Move an order to a new status. The ONLY place orders.status changes.
 *
 * @param int      $order_id
 * @param string   $to_status
 * @param int|null $user_id   staff user id; null = system/customer (only forward cancel/expire)
 * @param array    $data      move-specific input: reason | measured_by, pickup_date, oic_manager_id,
 *                            deposit, note | or_no, amount_paid, deposit_amount, paid_date |
 *                            received_by, checked_by, oic_manager_id, claim_date
 * @return array   ['ok'=>true,'message','order_id','from','to','kind']  or  ['ok'=>false,'message','code']
 */
function transition_order(int $order_id, string $to_status, ?int $user_id, array $data = []): array
{
    $tries = 0;
    while (true) {
        $tries++;
        try {
            $res = db_transaction(fn() => ord_transition_locked($order_id, $to_status, $user_id, $data));
            return ['ok' => true] + $res;
        } catch (DomainException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'code' => 'rule'];
        } catch (PDOException $e) {
            $deadlock = ($e->errorInfo[0] ?? '') === '40001' || (int) ($e->errorInfo[1] ?? 0) === 1213;
            if ($deadlock && $tries < 3) {
                usleep(100000 * $tries);
                continue;
            }
            error_log('transition_order DB error: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The order could not be updated because of a database error. Nothing was changed. Please try again.', 'code' => 'db'];
        } catch (Throwable $e) {
            error_log('transition_order error: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'The order could not be updated. Nothing was changed.', 'code' => 'error'];
        }
    }
}

function ord_transition_locked(int $order_id, string $to, ?int $user_id, array $data): array
{
    $order = ord_one('SELECT * FROM orders WHERE order_id = ? FOR UPDATE', [$order_id]);
    if (!$order) {
        throw new DomainException('Order not found.');
    }
    if ($order['deleted_at'] !== null) {
        throw new DomainException('This order is in the recycle bin. Restore it before changing it.');
    }
    $from = $order['status'];
    $kind = ord_move_kind($from, $to);
    if ($kind === null) {
        throw new DomainException('An order that is ' . ord_label($from) . ' cannot be moved to ' . ord_label($to) . '.');
    }

    // who is acting (role rules from Session 2, via can())
    $permission = ord_move_permission($from, $to);
    if ($user_id !== null) {
        $actor = ord_one('SELECT user_id, role, is_active FROM users WHERE user_id = ?', [$user_id]);
        if (!$actor || !$actor['is_active']) {
            throw new DomainException('Your account is not active.');
        }
        if (!can($permission, $actor['role'])) {
            throw new DomainException('Your role is not allowed to make this change.');
        }
    } elseif ($kind !== 'forward' || !in_array($to, ['cancelled', 'expired'], true) || $permission !== 'orders.process') {
        throw new DomainException('Only a signed-in staff member can make this change.');
    }

    $now = (string) ord_value('SELECT NOW()');
    $oid = (int) $order_id;
    $hold_hours = max(1, (int) get_setting('hold_unpaid_hours', 48));
    $hold_until = (new DateTime($now))->modify("+{$hold_hours} hours")->format('Y-m-d H:i:s');
    $sets = ['status' => $to];
    $audit = ['from' => $from, 'to' => $to, 'kind' => $kind];

    if ($kind === 'revert') {
        $reason = ord_reason($data);
        $audit['reason'] = $reason;
        switch ($from . '>' . $to) {
            case 'approved>requested':
                ord_release_stock($oid, $user_id, 'Reverted to requested: ' . $reason);
                $sets += ['approved_by' => null, 'approved_at' => null, 'expires_at' => null];
                break;
            case 'slip_issued>approved':
                $sets += ['expires_at' => $hold_until];   // fresh hold so the job does not expire it at once
                break;
            case 'claimed>paid':
                ord_unclaim_stock($oid, $user_id, 'Claim reverted: ' . $reason);
                $days = max(1, (int) get_setting('claim_period_days', 14));
                $sets += [
                    'claimed_at' => null, 'received_by' => null,
                    'claim_deadline' => (new DateTime($now))->modify("+{$days} days")->format('Y-m-d H:i:s'),
                ];
                break;
            case 'declined>requested':
                $sets += ['declined_by' => null, 'declined_at' => null, 'decline_reason' => null];
                break;
            case 'cancelled>requested':
                if ((int) ord_value('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$oid]) > 0) {
                    throw new DomainException('This order has payment records, so a cancellation cannot be reverted. Create a new order instead.');
                }
                $sets += ['decline_reason' => null, 'expires_at' => null, 'claim_deadline' => null];
                break;
        }
        $msg = 'Order reverted to ' . ord_label($to) . '.';
    } else {
        switch ($to) {
            case 'approved':
                ord_reserve_stock($order, $user_id);
                $sets += ['approved_by' => $user_id, 'approved_at' => $now, 'expires_at' => $hold_until];
                $msg = 'Order approved and stock reserved.';
                break;

            case 'declined':
                $reason = ord_reason($data);
                ord_release_stock($oid, $user_id, 'Order declined');
                $sets += ['declined_by' => $user_id, 'declined_at' => $now, 'decline_reason' => $reason, 'expires_at' => null];
                $audit['reason'] = $reason;
                $msg = 'Order declined. The customer will see the reason when tracking.';
                break;

            case 'cancelled':
                $reason = ord_reason($data);
                ord_release_stock($oid, $user_id, 'Order cancelled');
                // the schema has no cancel_reason column; decline_reason holds the customer-visible reason
                $sets += ['decline_reason' => $reason, 'expires_at' => null, 'claim_deadline' => null];
                $audit['reason'] = $reason;
                $msg = 'Order cancelled and stock released. The customer will see the reason when tracking.';
                break;

            case 'expired':
                if ((int) ord_value('SELECT COUNT(*) FROM payments WHERE order_id = ?', [$oid]) > 0) {
                    throw new DomainException('This order has a payment record and cannot be expired.');
                }
                ord_release_stock($oid, $user_id, 'Unpaid hold expired');
                $days = max(1, (int) get_setting('recycle_purge_days', 30));
                $sets += [
                    'deleted_at' => $now, 'deleted_by' => $user_id,
                    'purge_after' => (new DateTime($now))->modify("+{$days} days")->format('Y-m-d H:i:s'),
                ];
                $audit['reason'] = 'Unpaid hold expired';
                $msg = 'Order marked expired, stock released, and moved to the recycle bin.';
                break;

            case 'slip_issued':
                [$extra, $info] = ord_prepare_slip($order, $data, $now);
                $sets += $extra;
                $audit += $info;
                $msg = 'Order slip ' . $info['slip_no'] . ' issued.';
                break;

            case 'paid':
                [$extra, $info] = ord_record_payment($order, $user_id, $data, $now);
                $sets += $extra;
                $audit += $info;
                $msg = 'Payment recorded (OR ' . $info['or_no'] . '). The order is paid and ready to claim.';
                break;

            case 'claimed':
                [$extra, $info] = ord_prepare_claim($order, $user_id, $data, $now);
                ord_claim_stock($oid, $user_id, $now);
                $sets += $extra;
                $audit += $info;
                $msg = 'Order claimed. Stock updated.';
                break;

            default:
                throw new DomainException('Unsupported move.');
        }
    }

    $cols = [];
    $vals = [];
    foreach ($sets as $col => $val) {
        $cols[] = "`$col` = ?";
        $vals[] = $val;
    }
    $vals[] = $oid;
    $vals[] = $from;
    $st = ord_q('UPDATE orders SET ' . implode(', ', $cols) . ' WHERE order_id = ? AND status = ?', $vals);
    if ($st->rowCount() !== 1) {
        throw new DomainException('The order was changed by someone else. Reload the page and try again.');
    }
    ord_audit($user_id, 'order_status_changed', $oid, ['status' => $from], $audit);

    return ['message' => $msg, 'order_id' => $oid, 'from' => $from, 'to' => $to, 'kind' => $kind];
}

/** approved -> slip_issued: number, measure/cutter, pickup date, OIC, rental deposit. */
function ord_prepare_slip(array $order, array $data, string $now): array
{
    $oid = (int) $order['order_id'];
    $measured = ord_staff_id($data['measured_by'] ?? null, 'Measure/Cutter');
    $oic = ord_staff_id($data['oic_manager_id'] ?? null, 'OIC-Manager', ['manager', 'admin']);

    $pickup = null;
    if (clean_str($data['pickup_date'] ?? '') !== '') {
        $pickup = ord_parse_date($data['pickup_date']);
        if ($pickup === null || $pickup < substr($now, 0, 10)) {
            throw new DomainException('Pickup date must be today or a later date.');
        }
    }

    $note = clean_str($data['note'] ?? '');
    if (ord_strlen($note) > 500) {
        throw new DomainException('Measure/cutter notes must be 500 characters or fewer.');
    }

    $deposit = null;
    if (clean_str($data['deposit'] ?? '') !== '') {
        $deposit = ord_parse_money($data['deposit']);
        if ($deposit === null) {
            throw new DomainException('Enter the deposit as an amount such as 500 or 500.00.');
        }
        $rentals = ord_rentals($oid);
        if (!$rentals) {
            throw new DomainException('A deposit applies to toga rentals only.');
        }
        if (count($rentals) > 1) {
            throw new DomainException('This order has more than one rental. Record the deposit with the payment instead.');
        }
        ord_q('UPDATE rentals SET deposit_amount = ? WHERE rental_id = ?', [$deposit / 100, $rentals[0]['rental_id']]);
    }

    // First issue assigns the official number; a re-issue after a revert keeps it.
    $sets = ['slip_issued_at' => $now];
    $info = [];
    if ($order['slip_issued_at'] === null) {
        $no = ord_next_slip_no($now, $oid);
        $sets['order_no'] = $no;
        $info['slip_no'] = $no;
    } else {
        $info['slip_no'] = $order['order_no'];
    }
    $sets += ['pickup_date' => $pickup, 'measured_by' => $measured, 'oic_manager_id' => $oic];
    if ($note !== '') {
        $info['measure_notes'] = $note;
    }
    if ($deposit !== null) {
        $info['deposit'] = ord_fmt_cents($deposit);
    }
    return [$sets, $info];
}

/** slip_issued -> paid: record the cashier's OR. Payments are records only. */
function ord_record_payment(array $order, ?int $user_id, array $data, string $now): array
{
    $oid = (int) $order['order_id'];
    $or_no = clean_str($data['or_no'] ?? '');
    if ($or_no === '' || strlen($or_no) > 30 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\/ .]*$/', $or_no)) {
        throw new DomainException('Enter the OR number exactly as printed (letters, numbers, dashes; up to 30 characters).');
    }
    $amount = ord_parse_money($data['amount_paid'] ?? '');
    if ($amount === null || $amount <= 0) {
        throw new DomainException('Enter the total amount on the receipt (greater than zero).');
    }
    $deposit = clean_str($data['deposit_amount'] ?? '') === '' ? 0 : ord_parse_money($data['deposit_amount']);
    if ($deposit === null || $deposit > $amount) {
        throw new DomainException('The deposit must be an amount that is not more than the amount paid.');
    }
    $date = ord_parse_date($data['paid_date'] ?? '');
    $today = substr($now, 0, 10);
    if ($date === null || $date > $today) {
        throw new DomainException('Enter the payment date from the receipt (not in the future).');
    }
    $paid_at = $date === $today ? $now : $date . ' 12:00:00';

    $existing = ord_cents(ord_value('SELECT COALESCE(SUM(amount_paid - deposit_amount), 0) FROM payments WHERE order_id = ?', [$oid]));
    $net = $amount - $deposit;
    $total = ord_cents($order['total_amount']);
    if ($existing + $net < $total) {
        throw new DomainException(
            'The amount paid, not counting the deposit, is ₱' . number_format(($existing + $net) / 100, 2) .
            ' but the order total is ₱' . number_format($total / 100, 2) . '. Enter the full amount shown on the receipt.'
        );
    }

    try {
        ord_q(
            'INSERT INTO payments (order_id, or_no, amount_paid, deposit_amount, paid_at, verified_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$oid, $or_no, $amount / 100, $deposit / 100, $paid_at, $user_id]
        );
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('That OR number is already recorded on an order.');
        }
        throw $e;
    }

    $days = max(1, (int) get_setting('claim_period_days', 14));
    $sets = [
        'paid_at' => $paid_at, 'expires_at' => null,
        'claim_deadline' => (new DateTime($paid_at))->modify("+{$days} days")->format('Y-m-d H:i:s'),
    ];
    $info = ['or_no' => $or_no, 'amount_paid' => ord_fmt_cents($amount), 'deposit_amount' => ord_fmt_cents($deposit)];
    return [$sets, $info];
}

/** paid -> claimed: checked by, received by, optional OIC-Manager and claim date. */
function ord_prepare_claim(array $order, ?int $user_id, array $data, string $now): array
{
    $received = clean_str($data['received_by'] ?? '');
    $len = ord_strlen($received);
    if ($len < 2 || $len > 120) {
        throw new DomainException('Enter the name of the person who received the items (2 to 120 characters).');
    }
    $checked = ord_staff_id($data['checked_by'] ?? null, 'Checked by') ?? $user_id;
    $oic = ord_staff_id($data['oic_manager_id'] ?? null, 'OIC-Manager', ['manager', 'admin'])
        ?? ($order['oic_manager_id'] !== null ? (int) $order['oic_manager_id'] : null);

    $claimed_at = $now;
    if (clean_str($data['claim_date'] ?? '') !== '') {
        $d = ord_parse_date($data['claim_date']);
        $today = substr($now, 0, 10);
        if ($d === null || $d > $today) {
            throw new DomainException('The claim date cannot be in the future.');
        }
        if ($order['paid_at'] !== null && $d < substr($order['paid_at'], 0, 10)) {
            throw new DomainException('The claim date cannot be before the payment date.');
        }
        $claimed_at = $d === $today ? $now : $d . ' 12:00:00';
    }
    $sets = ['claimed_at' => $claimed_at, 'received_by' => $received, 'checked_by' => $checked, 'oic_manager_id' => $oic];
    return [$sets, ['received_by' => $received, 'checked_by' => $checked, 'claimed_at' => $claimed_at]];
}

/* ------------------------------------------------------------- read side */

function ord_page_size(): int
{
    return 20;
}

function ord_statuses_all(): array
{
    return ['requested', 'approved', 'slip_issued', 'paid', 'claimed', 'unclaimed', 'declined', 'cancelled', 'expired'];
}

function ord_read_filters(array $get): array
{
    $status = clean_str($get['status'] ?? '');
    if ($status !== 'open' && !in_array($status, ord_statuses_all(), true)) {
        $status = '';
    }
    $q = clean_str($get['q'] ?? '');
    return [
        'status'    => $status,
        'date_from' => ord_parse_date($get['date_from'] ?? '') ?? '',
        'date_to'   => ord_parse_date($get['date_to'] ?? '') ?? '',
        'q'         => function_exists('mb_substr') ? mb_substr($q, 0, 60) : substr($q, 0, 60),
        'expiring'  => !empty($get['expiring']) ? 1 : 0,
    ];
}

function ord_filter_query(array $f): array
{
    $q = [];
    foreach ($f as $k => $v) {
        if ($v !== '' && $v !== 0) {
            $q[$k] = $v;
        }
    }
    return $q;
}

function ord_expiring_hours(): int
{
    return max(1, (int) get_setting('expiring_soon_hours', 24));
}

function ord_claim_warning_days(): int
{
    return max(1, (int) get_setting('claim_warning_days', 3));
}

/** [where_sql, params] for the list. Recycle-bin orders are never listed here. */
function ord_where(array $f): array
{
    $w = ['o.deleted_at IS NULL'];
    $p = [];
    if ($f['status'] === 'open') {
        $w[] = "o.status IN ('requested','approved','slip_issued','paid','unclaimed')";
    } elseif ($f['status'] !== '') {
        $w[] = 'o.status = ?';
        $p[] = $f['status'];
    }
    if ($f['date_from'] !== '') {
        $w[] = 'o.created_at >= ?';
        $p[] = $f['date_from'] . ' 00:00:00';
    }
    if ($f['date_to'] !== '') {
        $w[] = 'o.created_at < ?';
        $p[] = (new DateTime($f['date_to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }
    if ($f['q'] !== '') {
        $like = '%' . addcslashes($f['q'], '\\%_') . '%';
        $w[] = '(o.order_ref LIKE ? OR o.order_no LIKE ? OR c.full_name LIKE ? OR s.student_no LIKE ?)';
        array_push($p, $like, $like, $like, $like);
    }
    if ($f['expiring']) {
        $h = ord_expiring_hours();
        $d = ord_claim_warning_days();
        $w[] = "((o.status IN ('approved','slip_issued') AND o.expires_at IS NOT NULL AND o.expires_at <= DATE_ADD(NOW(), INTERVAL $h HOUR))
              OR (o.status IN ('paid','unclaimed') AND o.claim_deadline IS NOT NULL AND o.claim_deadline <= DATE_ADD(NOW(), INTERVAL $d DAY)))";
    }
    return [implode(' AND ', $w), $p];
}

function ord_count(array $f): int
{
    [$where, $p] = ord_where($f);
    return (int) ord_value(
        "SELECT COUNT(*) FROM orders o JOIN customers c ON c.customer_id = o.customer_id
           LEFT JOIN students s ON s.student_id = o.student_id WHERE $where",
        $p
    );
}

function ord_list(array $f, int $offset, int $per_page): array
{
    [$where, $p] = ord_where($f);
    $offset = max(0, $offset);
    $per_page = max(1, $per_page);
    return ord_all(
        "SELECT o.*, c.full_name AS customer_name, c.contact_no, s.student_no, s.full_name AS student_name,
                (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.order_id) AS item_count
           FROM orders o JOIN customers c ON c.customer_id = o.customer_id
           LEFT JOIN students s ON s.student_id = o.student_id
          WHERE $where ORDER BY o.created_at DESC, o.order_id DESC LIMIT $per_page OFFSET $offset",
        $p
    );
}

function ord_status_counts(): array
{
    $out = array_fill_keys(ord_statuses_all(), 0);
    foreach (ord_all('SELECT status, COUNT(*) AS n FROM orders WHERE deleted_at IS NULL GROUP BY status') as $r) {
        $out[$r['status']] = (int) $r['n'];
    }
    return $out;
}

/** ['level' => 'overdue'|'soon', 'text' => ...] or null. Only for orders still waiting on something. */
function ord_urgency(array $o): ?array
{
    $now = time();
    if (in_array($o['status'], ['approved', 'slip_issued'], true) && !empty($o['expires_at'])) {
        $t = strtotime($o['expires_at']);
        if ($t <= $now) {
            return ['level' => 'overdue', 'text' => 'Hold ended ' . format_datetime($o['expires_at'])];
        }
        if ($t <= $now + ord_expiring_hours() * 3600) {
            return ['level' => 'soon', 'text' => 'Hold ends ' . format_datetime($o['expires_at'])];
        }
    }
    if (in_array($o['status'], ['paid', 'unclaimed'], true) && !empty($o['claim_deadline'])) {
        $t = strtotime($o['claim_deadline']);
        if ($t <= $now) {
            return ['level' => 'overdue', 'text' => 'Claim period ended ' . format_datetime($o['claim_deadline'])];
        }
        if ($t <= $now + ord_claim_warning_days() * 86400) {
            return ['level' => 'soon', 'text' => 'Claim by ' . format_datetime($o['claim_deadline'])];
        }
    }
    return null;
}

function ord_fetch(int $id): ?array
{
    return ord_one(
        'SELECT o.*, c.full_name AS customer_name, c.contact_no, c.email AS customer_email, c.address AS customer_address,
                c.relationship, s.student_no, s.full_name AS student_name, s.year_level,
                el.name AS level_name, co.code AS course_code, co.name AS course_name,
                ua.full_name AS approved_by_name, ud.full_name AS declined_by_name,
                um.full_name AS measured_by_name, uc.full_name AS checked_by_name, uo.full_name AS oic_name
           FROM orders o
           JOIN customers c ON c.customer_id = o.customer_id
           LEFT JOIN students s ON s.student_id = o.student_id
           LEFT JOIN education_levels el ON el.education_level_id = s.education_level_id
           LEFT JOIN courses co ON co.course_id = s.course_id
           LEFT JOIN users ua ON ua.user_id = o.approved_by
           LEFT JOIN users ud ON ud.user_id = o.declined_by
           LEFT JOIN users um ON um.user_id = o.measured_by
           LEFT JOIN users uc ON uc.user_id = o.checked_by
           LEFT JOIN users uo ON uo.user_id = o.oic_manager_id
          WHERE o.order_id = ?',
        [$id]
    );
}

function ord_payments(int $order_id): array
{
    return ord_all(
        'SELECT p.*, u.full_name AS verified_by_name FROM payments p LEFT JOIN users u ON u.user_id = p.verified_by
          WHERE p.order_id = ? ORDER BY p.paid_at, p.payment_id',
        [$order_id]
    );
}

function ord_rentals(int $order_id): array
{
    return ord_all(
        'SELECT r.* FROM rentals r JOIN order_items oi ON oi.order_item_id = r.order_item_id WHERE oi.order_id = ?',
        [$order_id]
    );
}

/** Active staff for the "measure/cutter", "checked by" and OIC selects. */
function ord_staff_options(array $roles = []): array
{
    $sql = 'SELECT user_id, full_name, role FROM users WHERE is_active = 1';
    $p = [];
    if ($roles) {
        $sql .= ' AND role IN (' . implode(',', array_fill(0, count($roles), '?')) . ')';
        $p = $roles;
    }
    return ord_all($sql . ' ORDER BY full_name', $p);
}

/** Timeline for the detail page: the creation row plus everything logged against the order. */
function ord_history(array $order): array
{
    $rows = ord_all(
        "SELECT a.*, u.full_name AS user_name FROM audit_logs a LEFT JOIN users u ON u.user_id = a.user_id
          WHERE a.entity_type = 'orders' AND a.entity_id = ? ORDER BY a.audit_id",
        [(string) $order['order_id']]
    );
    $out = [[
        'when' => $order['created_at'], 'who' => $order['source'] === 'walk_in' ? 'Staff (walk-in)' : 'Customer (online)',
        'label' => 'Order created', 'detail' => '', 'reason' => '', 'is_note' => false,
    ]];
    $detail_keys = [
        'slip_no' => 'Slip no.', 'or_no' => 'OR no.', 'amount_paid' => 'Amount ₱', 'deposit_amount' => 'Deposit ₱',
        'deposit' => 'Deposit ₱', 'received_by' => 'Received by', 'measure_notes' => 'Measure notes',
    ];
    foreach ($rows as $r) {
        if ($r['action'] === 'order_created_online') {
            continue;   // shown as the creation row
        }
        $n = $r['new_values'] ? (json_decode($r['new_values'], true) ?: []) : [];
        $who = $r['user_name'] ?? 'System';
        $detail = [];
        $label = ucfirst(str_replace('_', ' ', $r['action']));
        $reason = '';
        switch ($r['action']) {
            case 'order_status_changed':
                $label = ord_label($n['from'] ?? '?') . ' → ' . ord_label($n['to'] ?? '?') . (($n['kind'] ?? '') === 'revert' ? ' (revert)' : '');
                $reason = $n['reason'] ?? '';
                foreach ($detail_keys as $k => $lab) {
                    if (isset($n[$k]) && $n[$k] !== '') {
                        $detail[] = $lab . ': ' . $n[$k];
                    }
                }
                break;
            case 'order_note_added':
                $label = 'Note';
                $reason = $n['note'] ?? '';
                break;
            case 'order_cancelled_by_customer':
                $label = 'Cancelled by the customer';
                $who = 'Customer';
                break;
            case 'restore_order':
                $label = 'Restored from the recycle bin';
                break;
        }
        $out[] = [
            'when' => $r['created_at'], 'who' => $who, 'label' => $label,
            'detail' => implode(' · ', $detail), 'reason' => $reason, 'is_note' => $r['action'] === 'order_note_added',
        ];
    }
    return $out;
}

/** Add a free-text staff note (not a status change). */
function ord_add_note(int $order_id, int $user_id, string $note): array
{
    $note = clean_str($note);
    if ($note === '' || ord_strlen($note) > 500) {
        return ['ok' => false, 'message' => 'A note must be 1 to 500 characters.'];
    }
    if (!ord_one('SELECT order_id FROM orders WHERE order_id = ?', [$order_id])) {
        return ['ok' => false, 'message' => 'Order not found.'];
    }
    ord_audit($user_id, 'order_note_added', $order_id, null, ['note' => $note]);
    return ['ok' => true, 'message' => 'Note added.'];
}

/**
 * Customer-visible reason for a declined or cancelled order (for track.php).
 * Returns '' for other statuses or when none was recorded.
 */
function order_public_reason(array $order): string
{
    return in_array($order['status'] ?? '', ['declined', 'cancelled'], true) ? (string) ($order['decline_reason'] ?? '') : '';
}
