<?php
/**
 * Session 8 - toga rentals: list, approve/decline, issue, return, overdue.
 *
 * Depends on includes/orders.php (Session 6). Everything here is prefixed rent_.
 *
 * Why "issue" is the claim step: the shop is paid at the cashier BEFORE a toga
 * leaves the counter, and Session 6's claim already moves stock out and turns
 * the rentals row to 'released'. So rent_issue_locked() runs that same claim
 * (through ord_transition_locked) and then records the rental details on top:
 * issue date, due date, deposit and who handed it out. One code path, one history.
 *
 * Rental statuses shown to staff (rentals.status is the schema's own enum):
 *   requested  toga order not approved yet (no rentals row exists)
 *   reserved   approved, toga not handed out yet   (rentals.status = reserved)
 *   issued     handed to the student               (rentals.status = released)
 *   overdue    issued and past the due date        (computed, so it is right
 *              even when the MySQL event scheduler is off)
 *   returned, cancelled   as in the schema
 *   declined   the request was declined
 */

if (defined('RENT_LIB_LOADED')) {
    return;
}
define('RENT_LIB_LOADED', true);

const RENT_PAGE_SIZE = 20;

/* ------------------------------------------------------------------ */
/* Labels and guards                                                   */
/* ------------------------------------------------------------------ */

function rent_statuses(): array
{
    return [
        'requested' => 'Requested',
        'reserved'  => 'Approved, not issued',
        'issued'    => 'Issued',
        'overdue'   => 'Overdue',
        'returned'  => 'Returned',
        'cancelled' => 'Cancelled',
        'declined'  => 'Declined',
    ];
}

function rent_condition_labels(): array
{
    return ['good' => 'Good', 'needs_cleaning' => 'Needs cleaning', 'damaged' => 'Damaged', 'lost' => 'Lost'];
}

function rent_status_badge(string $eff): string
{
    $label = rent_statuses()[$eff] ?? ucfirst($eff);
    return '<span class="s8-badge s8-badge-' . e($eff) . '">' . e($label) . '</span>';
}

/** Returns a message when migration 006 is missing, else null. */
function rent_migration_problem(): ?string
{
    static $problem = false;
    if ($problem !== false) {
        return $problem;
    }
    $problem = null;
    try {
        $n = (int) ord_value(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' AND COLUMN_NAME IN ('issued_by', 'received_by')"
        );
        if ($n < 2) {
            $problem = 'Database migration 006 has not been applied yet. Import '
                . 'database/migrations/006_toga_rentals.sql (phpMyAdmin > Import), then reload this page.';
        }
    } catch (PDOException $ex) {
        $problem = 'The database could not be checked: ' . $ex->getMessage();
    }
    return $problem;
}

/** Print the migration warning; true means the page must stop. */
function rent_migration_block(): bool
{
    $problem = rent_migration_problem();
    if ($problem === null) {
        return false;
    }
    echo '<div class="s8-alert s8-alert-error" role="alert">' . e($problem) . '</div>';
    return true;
}

/** Acting staff member, checked against a permission. Throws a message that is safe to show. */
function rent_actor(?int $user_id, string $permission): array
{
    if ($user_id === null) {
        throw new DomainException('Only a signed-in staff member can do this.');
    }
    $u = ord_one('SELECT user_id, role, is_active, full_name FROM users WHERE user_id = ?', [$user_id]);
    if (!$u || !$u['is_active']) {
        throw new DomainException('Your account is not active.');
    }
    if (!can($permission, $u['role'])) {
        throw new DomainException('Your role is not allowed to make this change.');
    }
    return $u;
}

/** Transaction with a deadlock retry (joins an outer transaction when one is open). */
function rent_tx(callable $fn)
{
    for ($try = 1; ; $try++) {
        try {
            return db_transaction($fn);
        } catch (PDOException $ex) {
            $deadlock = ($ex->errorInfo[0] ?? '') === '40001' || (int) ($ex->errorInfo[1] ?? 0) === 1213;
            if (!$deadlock || $try >= 3 || db()->inTransaction()) {
                throw $ex;
            }
            usleep(100000 * $try);
        }
    }
}

/* ------------------------------------------------------------------ */
/* Due date                                                            */
/* ------------------------------------------------------------------ */

/**
 * Default due date. The real rental length is unconfirmed, so it comes from the
 * toga_rental_days setting; 0 (the default) means the end of the academic period.
 */
function rent_default_due(string $issue_date, ?string $period_end): ?string
{
    $days = (int) get_setting('toga_rental_days', 0);
    if ($days > 0) {
        return (new DateTime($issue_date))->modify('+' . $days . ' days')->format('Y-m-d');
    }
    return $period_end !== null && $period_end !== '' ? $period_end : null;
}

/* ------------------------------------------------------------------ */
/* List and counts                                                     */
/* ------------------------------------------------------------------ */

/**
 * One row per toga: every rentals row, plus toga orders that have no rentals row
 * yet (requested, declined or cancelled before approval). eff_status is computed
 * here so the list, the counts and the filter all agree.
 */
function rent_union_sql(): string
{
    return "SELECT r.rental_id, o.order_id, o.order_ref, o.order_no, o.status AS order_status,
                   o.created_at AS placed_at, COALESCE(r.academic_period_id, o.academic_period_id) AS period_id,
                   s.student_id, s.student_no, s.full_name AS student_name, oi.item_name, oi.size_label,
                   r.status AS rental_status, r.released_at, r.due_date, r.returned_at,
                   r.deposit_amount, r.penalty_amount, r.deposit_returned, r.condition_on_return,
                   CASE WHEN o.status = 'declined' AND r.status = 'cancelled' THEN 'declined'
                        WHEN r.status = 'released' AND r.due_date IS NOT NULL AND r.due_date < CURDATE() THEN 'overdue'
                        WHEN r.status = 'released' THEN 'issued'
                        ELSE r.status END AS eff_status
              FROM rentals r
              JOIN order_items oi ON oi.order_item_id = r.order_item_id
              JOIN orders o ON o.order_id = oi.order_id
              JOIN students s ON s.student_id = r.student_id
            UNION ALL
            SELECT NULL, o.order_id, o.order_ref, o.order_no, o.status,
                   o.created_at, o.academic_period_id,
                   s.student_id, s.student_no, s.full_name, oi.item_name, oi.size_label,
                   NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
                   o.status
              FROM orders o
              JOIN order_items oi ON oi.order_id = o.order_id
              JOIN item_variants v ON v.variant_id = oi.variant_id
              JOIN items i ON i.item_id = v.item_id
              LEFT JOIN students s ON s.student_id = o.student_id
             WHERE i.item_type = 'rental' AND o.deleted_at IS NULL
               AND o.status IN ('requested', 'declined', 'cancelled')
               AND NOT EXISTS (SELECT 1 FROM rentals r2 WHERE r2.order_item_id = oi.order_item_id)";
}

function rent_read_filters(array $get): array
{
    $status = clean_str($get['status'] ?? '');
    if (!isset(rent_statuses()[$status])) {
        $status = '';
    }
    $q = clean_str($get['q'] ?? '');
    return [
        'status' => $status,
        'period' => max(0, (int) ($get['period'] ?? 0)),
        'q'      => function_exists('mb_substr') ? mb_substr($q, 0, 60) : substr($q, 0, 60),
        'page'   => max(1, (int) ($get['page'] ?? 1)),
    ];
}

/** Query-string parts for links (page excluded, blanks dropped). */
function rent_filter_query(array $f): array
{
    $out = [];
    foreach (['status', 'period', 'q'] as $k) {
        if ($f[$k] !== '' && $f[$k] !== 0) {
            $out[$k] = $f[$k];
        }
    }
    return $out;
}

function rent_where(array $f, bool $with_status = true): array
{
    $w = ['1 = 1'];
    $p = [];
    if ($with_status && $f['status'] !== '') {
        $w[] = 't.eff_status = ?';
        $p[] = $f['status'];
    }
    if ($f['period'] > 0) {
        $w[] = 't.period_id = ?';
        $p[] = $f['period'];
    }
    if ($f['q'] !== '') {
        $like = '%' . addcslashes($f['q'], '\\%_') . '%';
        $w[] = '(t.student_no LIKE ? OR t.student_name LIKE ? OR t.order_ref LIKE ?)';
        array_push($p, $like, $like, $like);
    }
    return [implode(' AND ', $w), $p];
}

function rent_count(array $f): int
{
    [$where, $p] = rent_where($f);
    return (int) ord_value('SELECT COUNT(*) FROM (' . rent_union_sql() . ") t WHERE $where", $p);
}

function rent_rows(array $f, int $offset, int $per_page): array
{
    [$where, $p] = rent_where($f);
    return ord_all(
        'SELECT t.*, DATEDIFF(CURDATE(), t.due_date) AS days_late FROM (' . rent_union_sql() . ") t
          WHERE $where
          ORDER BY (t.eff_status = 'overdue') DESC, t.placed_at DESC, t.order_id DESC
          LIMIT " . max(1, $per_page) . ' OFFSET ' . max(0, $offset),
        $p
    );
}

/** [status => count] for the tab bar; honours the period and search but not the status filter. */
function rent_status_counts(array $f): array
{
    [$where, $p] = rent_where($f, false);
    $out = array_fill_keys(array_keys(rent_statuses()), 0);
    foreach (ord_all('SELECT t.eff_status AS s, COUNT(*) AS n FROM (' . rent_union_sql() . ") t WHERE $where GROUP BY t.eff_status", $p) as $r) {
        if (isset($out[$r['s']])) {
            $out[$r['s']] = (int) $r['n'];
        }
    }
    return $out;
}

function rent_overdue_count(): int
{
    return (int) ord_value('SELECT COUNT(*) FROM (' . rent_union_sql() . ") t WHERE t.eff_status = 'overdue'");
}

/** Overdue togas, longest overdue first. */
function rent_overdue_rows(int $limit = 50): array
{
    return ord_all(
        'SELECT t.*, DATEDIFF(CURDATE(), t.due_date) AS days_late FROM (' . rent_union_sql() . ")
           t WHERE t.eff_status = 'overdue' ORDER BY t.due_date ASC, t.order_id LIMIT " . max(1, $limit)
    );
}

function rent_periods(): array
{
    return ord_all('SELECT academic_period_id, school_year, semester, is_current FROM academic_periods ORDER BY start_date DESC, academic_period_id DESC');
}

/** Every toga of one student (for the customer history page). */
function rent_for_student(int $student_id): array
{
    return ord_all(
        'SELECT t.*, DATEDIFF(CURDATE(), t.due_date) AS days_late, p.school_year, p.semester
           FROM (' . rent_union_sql() . ') t
           LEFT JOIN academic_periods p ON p.academic_period_id = t.period_id
          WHERE t.student_id = ? ORDER BY t.placed_at DESC, t.order_id DESC',
        [$student_id]
    );
}

/* ------------------------------------------------------------------ */
/* One toga order                                                      */
/* ------------------------------------------------------------------ */

function rent_is_toga_order(int $order_id): bool
{
    return (int) ord_value(
        "SELECT COUNT(*) FROM order_items oi
           JOIN item_variants v ON v.variant_id = oi.variant_id
           JOIN items i ON i.item_id = v.item_id
          WHERE oi.order_id = ? AND i.item_type = 'rental'",
        [$order_id]
    ) > 0;
}

/** The rental row of an order with the staff names and the period (needs migration 006). */
function rent_for_order(int $order_id): ?array
{
    return ord_one(
        'SELECT r.*, oi.order_id, oi.item_name, oi.size_label,
                ui.full_name AS issued_by_name, ur.full_name AS received_by_name,
                p.school_year, p.semester, p.end_date AS period_end
           FROM rentals r
           JOIN order_items oi ON oi.order_item_id = r.order_item_id
           LEFT JOIN users ui ON ui.user_id = r.issued_by
           LEFT JOIN users ur ON ur.user_id = r.received_by
           LEFT JOIN academic_periods p ON p.academic_period_id = r.academic_period_id
          WHERE oi.order_id = ? ORDER BY r.rental_id LIMIT 1',
        [$order_id]
    );
}

/** Same rule as the SQL CASE in rent_union_sql(), for a single order. */
function rent_effective_status(array $order, ?array $rental): string
{
    if ($rental === null) {
        return $order['status'];
    }
    if ($order['status'] === 'declined' && $rental['status'] === 'cancelled') {
        return 'declined';
    }
    if ($rental['status'] === 'released') {
        return ($rental['due_date'] !== null && $rental['due_date'] < date('Y-m-d')) ? 'overdue' : 'issued';
    }
    return $rental['status'];
}

/** One-student-one-active-rental check used by walk-in; counts rentals rows and still-requested rental orders. */
function rent_student_has_active(int $student_id, int $period_id, int $exclude_order_id = 0): bool
{
    $a = (int) ord_value(
        "SELECT COUNT(*) FROM rentals r JOIN order_items oi ON oi.order_item_id = r.order_item_id
          WHERE r.student_id = ? AND r.academic_period_id = ? AND r.status IN ('reserved','released','overdue')
            AND oi.order_id <> ?",
        [$student_id, $period_id, $exclude_order_id]
    );
    if ($a > 0) {
        return true;
    }
    return (int) ord_value(
        "SELECT COUNT(*) FROM orders
          WHERE student_id = ? AND order_type = 'rental' AND status = 'requested' AND deleted_at IS NULL
            AND (academic_period_id = ? OR academic_period_id IS NULL) AND order_id <> ?",
        [$student_id, $period_id, $exclude_order_id]
    ) > 0;
}

/** Short "what happens next" text for the detail page. */
function rent_next_hint(string $eff, array $order): string
{
    switch ($eff) {
        case 'requested':
            return 'Approve the request to reserve a toga for this student, or decline it with a reason.';
        case 'reserved':
            if ($order['status'] === 'approved') {
                return 'Approved. Next: issue the order slip on the order page, so the student can pay at the cashier.';
            }
            if ($order['status'] === 'slip_issued') {
                return 'Waiting for payment. When the student brings the official receipt, record the payment on the order page.';
            }
            return 'Paid. The toga can be issued now.';
        case 'issued':
            return 'The toga is with the student. Record the return when it comes back.';
        case 'overdue':
            return 'The due date has passed. Record the return when the toga comes back; any penalty is entered there.';
        case 'returned':
            return 'The toga was returned.';
        case 'declined':
            return 'This request was declined.';
        default:
            return 'This toga request was cancelled.';
    }
}

/* ------------------------------------------------------------------ */
/* Approve / decline (same central function as every other order)      */
/* ------------------------------------------------------------------ */

/** $to is 'approved' or 'declined'. Returns transition_order()'s result. */
function rent_decide(int $order_id, string $to, ?int $user_id, string $reason = ''): array
{
    if (!in_array($to, ['approved', 'declined'], true)) {
        return ['ok' => false, 'message' => 'Unknown action.'];
    }
    $o = ord_one('SELECT status FROM orders WHERE order_id = ?', [$order_id]);
    if (!$o || !rent_is_toga_order($order_id)) {
        return ['ok' => false, 'message' => 'Toga request not found.'];
    }
    if ($o['status'] !== 'requested') {
        return ['ok' => false, 'message' => 'Only a request that is still waiting for review can be approved or declined here.'];
    }
    return transition_order($order_id, $to, $user_id, ['reason' => $reason]);
}

/* ------------------------------------------------------------------ */
/* Issue                                                               */
/* ------------------------------------------------------------------ */

/**
 * Hand the toga out. Must run inside a transaction; throws DomainException with a
 * message that is safe to show. Used by the rental page and by walk-in orders.
 *
 * $data: issue_date (blank = today), due_date (blank = default), deposit_amount
 * (blank = keep what is recorded), issued_by (blank = you), received_by (required),
 * checked_by (blank = you), oic_manager_id (optional).
 */
function rent_issue_locked(int $order_id, ?int $user_id, array $data): array
{
    $problem = rent_migration_problem();
    if ($problem !== null) {
        throw new DomainException($problem);
    }
    rent_actor($user_id, 'orders.process');

    $order = ord_one('SELECT * FROM orders WHERE order_id = ? FOR UPDATE', [$order_id]);
    if (!$order) {
        throw new DomainException('Order not found.');
    }
    $rental = ord_one(
        'SELECT r.* FROM rentals r JOIN order_items oi ON oi.order_item_id = r.order_item_id
          WHERE oi.order_id = ? ORDER BY r.rental_id LIMIT 1 FOR UPDATE',
        [$order_id]
    );
    if (!$rental) {
        if ($order['status'] === 'requested') {
            throw new DomainException('Approve the request first. A toga is reserved only after approval.');
        }
        throw new DomainException('This order has no toga rental record. Revert the order to Approved and approve it again.');
    }

    $status = $order['status'];
    if (!in_array($status, ['paid', 'unclaimed'], true)) {
        if (in_array($status, ['approved', 'slip_issued'], true)) {
            throw new DomainException('The toga cannot be issued before payment. Issue the order slip, let the student pay at the cashier, then record the payment on the order page.');
        }
        if ($status === 'claimed') {
            throw new DomainException('This toga was already issued.');
        }
        throw new DomainException('A toga cannot be issued while the order is ' . ord_label($status) . '.');
    }
    if ($rental['status'] !== 'reserved') {
        throw new DomainException('This rental is already ' . $rental['status'] . ', so it cannot be issued.');
    }

    $now = (string) ord_value('SELECT NOW()');
    $today = substr($now, 0, 10);

    $issue_date = clean_str($data['issue_date'] ?? '') === '' ? $today : ord_parse_date($data['issue_date']);
    if ($issue_date === null || $issue_date > $today) {
        throw new DomainException('The issue date cannot be in the future.');
    }
    if ($order['paid_at'] !== null && $issue_date < substr($order['paid_at'], 0, 10)) {
        throw new DomainException('The issue date cannot be before the payment date.');
    }

    $due_raw = clean_str($data['due_date'] ?? '');
    if ($due_raw === '') {
        $period_end = $rental['academic_period_id'] !== null
            ? ord_value('SELECT end_date FROM academic_periods WHERE academic_period_id = ?', [$rental['academic_period_id']])
            : null;
        $due = rent_default_due($issue_date, $period_end);
    } else {
        $due = ord_parse_date($due_raw);
        if ($due === null) {
            throw new DomainException('Enter the due date as a valid date.');
        }
    }
    if ($due !== null && $due < $issue_date) {
        throw new DomainException('The due date cannot be before the issue date.');
    }

    // Deposit is optional because the shop has not confirmed the rule.
    $dep_raw = clean_str($data['deposit_amount'] ?? '');
    if ($dep_raw !== '') {
        $cents = ord_parse_money($dep_raw);
        if ($cents === null) {
            throw new DomainException('Enter the deposit as an amount such as 500 or 500.00, or leave it blank.');
        }
        $deposit = ord_fmt_cents($cents);
    } elseif ($rental['deposit_amount'] !== null) {
        $deposit = (string) $rental['deposit_amount'];
    } else {
        $paid_dep = ord_cents(ord_value('SELECT COALESCE(SUM(deposit_amount), 0) FROM payments WHERE order_id = ?', [$order_id]));
        $deposit = $paid_dep > 0 ? ord_fmt_cents($paid_dep) : null;
    }

    $issued_by = ord_staff_id($data['issued_by'] ?? null, 'Issued by') ?? $user_id;

    // The claim does the stock move, the status change and the order history.
    $claim = [
        'received_by'    => $data['received_by'] ?? '',
        'checked_by'     => $data['checked_by'] ?? '',
        'oic_manager_id' => $data['oic_manager_id'] ?? '',
    ];
    if ($issue_date !== $today) {
        $claim['claim_date'] = $issue_date;
    }
    ord_transition_locked($order_id, 'claimed', $user_id, $claim);

    $released_at = $issue_date === $today ? $now : $issue_date . ' 12:00:00';
    ord_q(
        'UPDATE rentals SET released_at = ?, due_date = ?, deposit_amount = ?, issued_by = ? WHERE rental_id = ?',
        [$released_at, $due, $deposit, $issued_by, $rental['rental_id']]
    );
    ord_audit($user_id, 'toga_issued', $order_id, null, [
        'issue_date' => $issue_date, 'due_date' => $due, 'deposit' => $deposit,
        'issued_by' => $issued_by, 'received_by' => clean_str($data['received_by'] ?? ''),
    ]);

    return [
        'message'  => 'Toga issued.' . ($due !== null ? ' It is due back on ' . format_date($due) . '.' : ' No due date was set.'),
        'order_id' => $order_id, 'rental_id' => (int) $rental['rental_id'], 'due_date' => $due,
    ];
}

function rent_issue(int $order_id, ?int $user_id, array $data): array
{
    try {
        $res = rent_tx(fn() => rent_issue_locked($order_id, $user_id, $data));
        return ['ok' => true] + $res;
    } catch (DomainException $ex) {
        return ['ok' => false, 'message' => $ex->getMessage()];
    } catch (Throwable $ex) {
        error_log('rent_issue: ' . $ex->getMessage());
        return ['ok' => false, 'message' => 'The toga could not be issued. Nothing was changed. Please try again.'];
    }
}

/* ------------------------------------------------------------------ */
/* Return                                                              */
/* ------------------------------------------------------------------ */

/**
 * Take the toga back. Penalty, deposit_returned and remarks are all optional
 * (the shop has not confirmed those rules). A toga in good or needs-cleaning
 * condition goes back into stock; a damaged or lost one does not, because it was
 * never counted back in (same rule as the schema's sp_return_rental).
 */
function rent_return_locked(int $rental_id, ?int $user_id, array $data): array
{
    $problem = rent_migration_problem();
    if ($problem !== null) {
        throw new DomainException($problem);
    }
    rent_actor($user_id, 'rentals.process');

    $r = ord_one('SELECT * FROM rentals WHERE rental_id = ? FOR UPDATE', [$rental_id]);
    if (!$r) {
        throw new DomainException('Rental not found.');
    }
    if (!in_array($r['status'], ['released', 'overdue'], true)) {
        $why = ['reserved' => 'This toga has not been issued yet.', 'returned' => 'This toga was already returned.', 'cancelled' => 'This rental was cancelled.'];
        throw new DomainException($why[$r['status']] ?? 'This rental cannot be returned.');
    }
    $line = ord_one(
        'SELECT oi.order_id, oi.quantity, oi.item_name, i.tracks_stock
           FROM order_items oi
           JOIN item_variants v ON v.variant_id = ?
           JOIN items i ON i.item_id = v.item_id
          WHERE oi.order_item_id = ?',
        [$r['variant_id'], $r['order_item_id']]
    );
    $order_id = (int) $line['order_id'];

    $now = (string) ord_value('SELECT NOW()');
    $today = substr($now, 0, 10);
    $date = clean_str($data['return_date'] ?? '') === '' ? $today : ord_parse_date($data['return_date']);
    if ($date === null || $date > $today) {
        throw new DomainException('The return date cannot be in the future.');
    }
    if ($r['released_at'] !== null && $date < substr($r['released_at'], 0, 10)) {
        throw new DomainException('The return date cannot be before the issue date.');
    }

    $condition = clean_str($data['condition'] ?? '');
    if (!isset(rent_condition_labels()[$condition])) {
        throw new DomainException('Choose the condition of the toga.');
    }

    $penalty = null;
    if (clean_str($data['penalty_amount'] ?? '') !== '') {
        $cents = ord_parse_money($data['penalty_amount']);
        if ($cents === null) {
            throw new DomainException('Enter the penalty as an amount such as 100 or 100.00, or leave it blank.');
        }
        $penalty = ord_fmt_cents($cents);
    }

    $dr = clean_str($data['deposit_returned'] ?? '');
    if ($dr === '') {
        $deposit_returned = null;
    } elseif ($dr === '1' || $dr === '0') {
        $deposit_returned = (int) $dr;
    } else {
        throw new DomainException('Choose whether the deposit was returned, or leave it as not recorded.');
    }

    $received_by = ord_staff_id($data['received_by'] ?? null, 'Received by') ?? $user_id;
    $remarks = clean_str($data['remarks'] ?? '');
    if (ord_strlen($remarks) > 255) {
        throw new DomainException('Remarks must be 255 characters or fewer.');
    }

    $returned_at = $date === $today ? $now : $date . ' 12:00:00';
    ord_q(
        "UPDATE rentals SET status = 'returned', returned_at = ?, condition_on_return = ?, penalty_amount = ?,
                deposit_returned = ?, received_by = ?, remarks = COALESCE(NULLIF(?, ''), remarks)
          WHERE rental_id = ?",
        [$returned_at, $condition, $penalty, $deposit_returned, $received_by, $remarks, $rental_id]
    );

    $restock = in_array($condition, ['good', 'needs_cleaning'], true);
    if ((int) $line['tracks_stock'] === 1) {
        $qty = (int) $line['quantity'];
        ord_lock_variant((int) $r['variant_id']);
        if ($restock) {
            ord_q('UPDATE item_variants SET quantity_on_hand = quantity_on_hand + ? WHERE variant_id = ?', [$qty, $r['variant_id']]);
            ord_movement((int) $r['variant_id'], 'returned', $qty, 0, $order_id, $user_id, 'Toga returned (' . $condition . ')');
        } else {
            ord_movement((int) $r['variant_id'], 'adjustment', 0, 0, $order_id, $user_id, 'Toga returned ' . $condition . '; not restocked');
        }
    }

    $staff = ord_value('SELECT full_name FROM users WHERE user_id = ?', [$received_by]);
    ord_audit($user_id, 'toga_returned', $order_id, null, [
        'return_date' => $date, 'condition' => $condition, 'penalty' => $penalty,
        'deposit_returned' => $deposit_returned, 'received_by' => (string) $staff,
    ]);

    return [
        'message'  => 'Toga returned (' . strtolower(rent_condition_labels()[$condition]) . ').'
            . ($restock ? ' It is back in stock.' : ' It was not added back to stock.'),
        'order_id' => $order_id,
    ];
}

function rent_return(int $rental_id, ?int $user_id, array $data): array
{
    try {
        $res = rent_tx(fn() => rent_return_locked($rental_id, $user_id, $data));
        return ['ok' => true] + $res;
    } catch (DomainException $ex) {
        return ['ok' => false, 'message' => $ex->getMessage()];
    } catch (Throwable $ex) {
        error_log('rent_return: ' . $ex->getMessage());
        return ['ok' => false, 'message' => 'The return could not be saved. Nothing was changed. Please try again.'];
    }
}
