<?php
/**
 * Session 8 - walk-in orders (staff enter an order for a customer at the counter).
 * Everything is prefixed walk_. Depends on includes/orders.php, includes/rentals.php
 * and includes/customer_lookup.php.
 *
 * Design: the order is created as 'requested' with source 'walk_in', then moved
 * forward with the SAME central rules as every other order (ord_transition_locked):
 *   requested -> approved (stock reserved) -> slip_issued [-> paid [-> claimed]]
 * all in ONE transaction, so a failure anywhere (for example not enough stock for
 * one line) leaves nothing behind. Staff are not held to the online per-request
 * cap: only real stock limits them. A toga line is claimed through
 * rent_issue_locked() so the due date, deposit and issuing staff are recorded.
 */

const WALK_MAX_LINES = 12;     // keeps the printed slip on one page (same as the online list)
const WALK_MAX_QTY   = 99;

function walk_after_options(): array
{
    return [
        'slip'    => 'Issue the order slip only (customer pays at the cashier, then returns)',
        'paid'    => 'Issue the slip and record the payment now',
        'claimed' => 'Issue the slip, record the payment and hand over the items now',
    ];
}

function walk_relationships(): array
{
    return ['self' => 'Myself (the student)', 'parent' => 'Parent', 'guardian' => 'Guardian', 'other' => 'Other'];
}

/* ------------------------------------------------------------------ */
/* Catalog with live availability                                      */
/* ------------------------------------------------------------------ */

/** Every active size of every active item (counter-only items included), grouped by item. */
function walk_catalog(): array
{
    $rows = ord_all(
        'SELECT i.item_id, i.name AS item_name, i.item_type, i.reservation_mode, i.max_qty_per_request, i.tracks_stock,
                c.name AS category_name, v.variant_id, v.size_label, v.unit_price,
                (v.quantity_on_hand - v.quantity_reserved) AS available
           FROM items i
           JOIN categories c ON c.category_id = i.category_id
           JOIN item_variants v ON v.item_id = i.item_id
          WHERE i.is_active = 1 AND c.is_active = 1 AND v.is_active = 1
          ORDER BY c.name, i.name, v.variant_id'
    );
    $out = [];
    foreach ($rows as $r) {
        $id = (int) $r['item_id'];
        if (!isset($out[$id])) {
            $out[$id] = ['item_id' => $id, 'name' => $r['item_name'], 'category' => $r['category_name'], 'item_type' => $r['item_type'],
                         'mode' => $r['reservation_mode'], 'cap' => (int) $r['max_qty_per_request'],
                         'tracks' => (int) $r['tracks_stock'], 'sizes' => []];
        }
        $out[$id]['sizes'][] = $r;
    }
    return array_values($out);
}

/** variant_id => ['available','tracks','price'] for the live-availability endpoint. */
function walk_stock(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($n) => $n > 0)));
    $ids = array_slice($ids, 0, 60);
    if (!$ids) {
        return [];
    }
    $out = [];
    $rows = ord_all(
        'SELECT v.variant_id, v.unit_price, i.tracks_stock, (v.quantity_on_hand - v.quantity_reserved) AS available
           FROM item_variants v JOIN items i ON i.item_id = v.item_id
          WHERE v.variant_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
        $ids
    );
    foreach ($rows as $r) {
        $out[(int) $r['variant_id']] = [
            'available' => max(0, (int) $r['available']), 'tracks' => (int) $r['tracks_stock'], 'price' => (string) $r['unit_price'],
        ];
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Validation                                                          */
/* ------------------------------------------------------------------ */

/** Parallel arrays variant_id[] and qty[] -> [variant_id => qty] (duplicates are added together). */
function walk_read_lines(array $post): array
{
    $vids = is_array($post['variant_id'] ?? null) ? array_values($post['variant_id']) : [];
    $qtys = is_array($post['qty'] ?? null) ? array_values($post['qty']) : [];
    $lines = [];
    foreach ($vids as $i => $vid) {
        $vid = (int) $vid;
        $q = trim((string) ($qtys[$i] ?? ''));
        if ($vid < 1 && $q === '') {
            continue;   // an empty spare row
        }
        $lines[$vid] = ($lines[$vid] ?? 0) + (ctype_digit($q) ? (int) $q : -1);
    }
    return $lines;
}

/** Read and check the form. Returns [clean, errors by field]. Stock is checked later, under lock. */
function walk_validate(array $post): array
{
    $err = [];
    $s = fn(string $k, int $max = 255) => function_exists('mb_substr') ? mb_substr(clean_str($post[$k] ?? ''), 0, $max) : substr(clean_str($post[$k] ?? ''), 0, $max);
    $c = [
        'full_name'          => $s('full_name', 120),
        'relationship'       => $s('relationship', 10),
        'student_no'         => $s('student_no', 30),
        'student_name'       => $s('student_name', 120),
        'contact_no'         => $s('contact_no', 20),
        'email'              => $s('email', 120),
        'address'            => $s('address', 255),
        'education_level_id' => (int) ($post['education_level_id'] ?? 0),
        'course_id'          => (int) ($post['course_id'] ?? 0),
        'year_level'         => $s('year_level', 20),
        'remarks'            => $s('remarks', 500),
        'after'              => $s('after', 10),
        'measured_by'        => $s('measured_by', 10),
        'oic_manager_id'     => $s('oic_manager_id', 10),
        'or_no'              => $s('or_no', 30),
        'amount_paid'        => $s('amount_paid', 15),
        'deposit_amount'     => $s('deposit_amount', 15),
        'paid_date'          => $s('paid_date', 10),
        'received_by'        => $s('received_by', 120),
        'due_date'           => $s('due_date', 10),
        'lines'              => walk_read_lines($post),
    ];

    if (mb_strlen($c['full_name']) < 2) {
        $err['full_name'] = 'Enter the customer\'s full name.';
    }
    if (!isset(walk_relationships()[$c['relationship']])) {
        $c['relationship'] = 'self';
    }

    if ($c['student_no'] === '') {
        $err['student_no'] = 'Enter the student ID or LRN.';
    } elseif (cust_find_student($c['student_no']) === null && function_exists('validate_student_no')
        && ($m = validate_student_no($c['student_no'])) !== null) {
        $err['student_no'] = $m;   // only brand-new numbers must match the pattern
    }
    $known = $c['student_no'] !== '' ? cust_find_student($c['student_no']) : null;
    if ($known === null && $c['relationship'] !== 'self' && $c['student_name'] === '') {
        $err['student_name'] = 'Enter the student\'s name (it is a new student).';
    }

    $mobile = normalize_ph_mobile($c['contact_no']);
    if ($mobile === null) {
        $err['contact_no'] = 'Enter a valid mobile number, for example 09171234567.';
    } else {
        $c['contact_no'] = $mobile;
    }
    if ($c['email'] !== '' && filter_var($c['email'], FILTER_VALIDATE_EMAIL) === false) {
        $err['email'] = 'Enter a valid email address or leave it blank.';
    }
    if ($c['year_level'] === '' && $known === null) {
        $err['year_level'] = 'Enter the year or grade level (for example "3rd Year" or "Grade 5").';
    }
    if ($c['education_level_id'] && !ord_value('SELECT 1 FROM education_levels WHERE education_level_id = ?', [$c['education_level_id']])) {
        $c['education_level_id'] = 0;
    }
    if ($c['course_id'] && !ord_value('SELECT 1 FROM courses WHERE course_id = ? AND is_active = 1', [$c['course_id']])) {
        $c['course_id'] = 0;
    }

    if (!$c['lines']) {
        $err['lines'] = 'Add at least one item.';
    } elseif (count($c['lines']) > WALK_MAX_LINES) {
        $err['lines'] = 'At most ' . WALK_MAX_LINES . ' lines fit on one order slip. Split the order in two.';
    } else {
        foreach ($c['lines'] as $vid => $q) {
            if ($vid < 1) {
                $err['lines'] = 'Choose an item and size on every row that has a quantity.';
            } elseif ($q < 1 || $q > WALK_MAX_QTY) {
                $err['lines'] = 'Quantities must be whole numbers from 1 to ' . WALK_MAX_QTY . '.';
            }
        }
    }

    if (!isset(walk_after_options()[$c['after']])) {
        $c['after'] = 'slip';
    }
    if ($c['after'] !== 'slip') {
        if ($c['paid_date'] === '') {
            $c['paid_date'] = date('Y-m-d');
        }
        if ($c['received_by'] === '' && $c['after'] === 'claimed') {
            $c['received_by'] = $c['relationship'] === 'self' && $c['student_name'] === '' ? $c['full_name']
                : ($c['student_name'] !== '' ? $c['student_name'] : $c['full_name']);
        }
    }
    return [$c, $err];
}

/* ------------------------------------------------------------------ */
/* Create                                                              */
/* ------------------------------------------------------------------ */

function walk_new_ref(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O/1/I, same as the online references
    $s = '';
    for ($i = 0; $i < 6; $i++) {
        $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'GRM-' . date('Y') . '-' . $s;
}

/** Find or create the student (row locked). An existing student is only topped up where blank. */
function walk_lock_student(array $c, ?int $period_id): int
{
    $level = $c['education_level_id'] ?: null;
    $course = $c['course_id'] ?: null;
    $sql = 'SELECT student_id FROM students WHERE student_no = ? FOR UPDATE';
    $row = ord_one($sql, [$c['student_no']]);
    if (!$row) {
        $name = $c['student_name'] !== '' ? $c['student_name'] : $c['full_name'];
        try {
            ord_q('INSERT INTO students (student_no, full_name, education_level_id, course_id, year_level) VALUES (?, ?, ?, ?, ?)',
                [$c['student_no'], $name, $level, $course, $c['year_level'] !== '' ? $c['year_level'] : null]);
        } catch (PDOException $ex) {
            if ((int) ($ex->errorInfo[1] ?? 0) !== 1062) {
                throw $ex;
            }
        }
        return (int) ord_one($sql, [$c['student_no']])['student_id'];
    }
    ord_q(
        "UPDATE students SET education_level_id = COALESCE(education_level_id, ?), course_id = COALESCE(course_id, ?),
                year_level = IF(year_level IS NULL OR year_level = '', ?, year_level) WHERE student_id = ?",
        [$level, $course, $c['year_level'] !== '' ? $c['year_level'] : null, $row['student_id']]
    );
    return (int) $row['student_id'];
}

function walk_find_customer(array $c): int
{
    $id = ord_value('SELECT customer_id FROM customers WHERE contact_no = ? AND full_name = ? ORDER BY customer_id DESC LIMIT 1', [$c['contact_no'], $c['full_name']]);
    if ($id) {
        ord_q("UPDATE customers SET email = COALESCE(NULLIF(?, ''), email), address = COALESCE(NULLIF(?, ''), address), relationship = ? WHERE customer_id = ?",
            [$c['email'], $c['address'], $c['relationship'], $id]);
        return (int) $id;
    }
    ord_q('INSERT INTO customers (full_name, contact_no, email, address, relationship) VALUES (?, ?, ?, ?, ?)',
        [$c['full_name'], $c['contact_no'], $c['email'] !== '' ? $c['email'] : null, $c['address'] !== '' ? $c['address'] : null, $c['relationship']]);
    return (int) db()->lastInsertId();
}

/** The whole walk-in order, inside the caller's transaction. Throws DomainException with a safe message. */
function walk_create_locked(array $c, ?int $user_id): array
{
    rent_actor($user_id, 'walkin.create');

    // 1. lock the sizes in ascending id order (the same order everything else uses)
    $vids = array_keys($c['lines']);
    sort($vids);
    $rows = [];
    foreach ($vids as $vid) {
        $v = ord_one(
            'SELECT v.variant_id, v.size_label, v.unit_price, v.quantity_on_hand, v.quantity_reserved, v.is_active AS v_active,
                    i.item_id, i.name AS item_name, i.item_type, i.max_qty_per_request, i.tracks_stock, i.is_active AS i_active
               FROM item_variants v JOIN items i ON i.item_id = v.item_id WHERE v.variant_id = ? FOR UPDATE',
            [$vid]
        );
        if (!$v || !(int) $v['v_active'] || !(int) $v['i_active']) {
            throw new DomainException('One of the items or sizes is no longer available. Reload the page and choose again.');
        }
        $rows[$vid] = $v;
    }

    // 2. rules per line: stock, one toga only
    $toga_lines = 0;
    $per_item = [];
    foreach ($c['lines'] as $vid => $qty) {
        $v = $rows[$vid];
        $label = $v['item_name'] . ' (size ' . $v['size_label'] . ')';
        if ($v['item_type'] === 'rental') {
            $toga_lines++;
            if ($qty !== 1) {
                throw new DomainException('Only one toga can be rented per order line (' . $label . ').');
            }
        }
        if ((int) $v['tracks_stock'] === 1) {
            $avail = (int) $v['quantity_on_hand'] - (int) $v['quantity_reserved'];
            if ($avail < $qty) {
                throw new DomainException('Not enough stock for ' . $label . ': ' . max(0, $avail) . ' available, ' . $qty . ' needed.');
            }
        }
        $per_item[(int) $v['item_id']] = ['name' => $v['item_name'], 'qty' => ($per_item[(int) $v['item_id']]['qty'] ?? 0) + $qty, 'cap' => (int) $v['max_qty_per_request']];
    }
    if ($toga_lines > 1) {
        throw new DomainException('A student can rent only one toga per academic period, so an order can contain only one toga.');
    }
    if ($toga_lines === 0 && clean_str($c['deposit_amount']) !== '') {
        throw new DomainException('A deposit applies to toga rentals only. Clear the deposit field.');
    }

    $period = ord_value('SELECT academic_period_id FROM academic_periods WHERE is_current = 1 ORDER BY academic_period_id DESC LIMIT 1');
    $period = $period !== null ? (int) $period : null;

    // 3. student, customer
    $student_id = walk_lock_student($c, $period);
    if ($toga_lines === 1) {
        if ($period === null) {
            throw new DomainException('Toga rentals need a current academic period. Ask an administrator to set one.');
        }
        if (rent_student_has_active($student_id, $period)) {
            throw new DomainException('This student already has a toga rental for the current academic period (one per student per period). Check the toga rentals list.');
        }
    }
    $customer_id = walk_find_customer($c);

    // 4. the order
    $type = $toga_lines === 1 ? 'rental' : 'purchase';
    $order_id = 0;
    $ref = '';
    for ($try = 0; $try < 5 && $order_id === 0; $try++) {
        $ref = walk_new_ref();
        try {
            ord_q(
                "INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id, academic_period_id, created_by, remarks, total_amount)
                 VALUES (?, ?, ?, 'requested', 'walk_in', ?, ?, ?, ?, ?, 0)",
                ['T-' . $ref, $ref, $type, $customer_id, $student_id, $period, $user_id, $c['remarks'] !== '' ? $c['remarks'] : null]
            );
            $order_id = (int) db()->lastInsertId();
        } catch (PDOException $ex) {
            if ((int) ($ex->errorInfo[1] ?? 0) !== 1062) {
                throw $ex;
            }
        }
    }
    if ($order_id === 0) {
        throw new RuntimeException('Could not generate a unique reference number');
    }
    $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string) get_setting('order_no_prefix', 'OS'));
    $prefix = $prefix === '' ? 'OS' : substr($prefix, 0, 6);
    ord_q('UPDATE orders SET order_no = ? WHERE order_id = ?', [sprintf('%s-%s-%06d', $prefix, date('Y'), $order_id), $order_id]);

    $total = 0;
    foreach ($c['lines'] as $vid => $qty) {
        $v = $rows[$vid];
        $unit = ord_cents($v['unit_price']);
        ord_q(
            'INSERT INTO order_items (order_id, variant_id, item_name, size_label, quantity, unit_price, line_total, stock_held) VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
            [$order_id, $vid, $v['item_name'], $v['size_label'], $qty, ord_fmt_cents($unit), ord_fmt_cents($unit * $qty)]
        );
        $total += $unit * $qty;
    }
    ord_q('UPDATE orders SET total_amount = ? WHERE order_id = ?', [ord_fmt_cents($total), $order_id]);

    // 5. quantities above the online cap are allowed at the counter; leave a trace in the history
    $over = [];
    foreach ($per_item as $it) {
        if ($it['qty'] > $it['cap']) {
            $over[] = $it['name'] . ' x' . $it['qty'] . ' (online limit ' . $it['cap'] . ')';
        }
    }
    if ($over) {
        ord_audit($user_id, 'order_note_added', $order_id, null, ['note' => 'Walk-in: above the online request limit, allowed at the counter: ' . implode('; ', $over)]);
    }

    // 6. move it forward with the normal rules
    $steps = ['requested'];
    ord_transition_locked($order_id, 'approved', $user_id, []);
    $steps[] = 'approved';
    $slip = ['measured_by' => $c['measured_by'], 'oic_manager_id' => $c['oic_manager_id']];
    if ($toga_lines === 1 && clean_str($c['deposit_amount']) !== '') {
        $slip['deposit'] = $c['deposit_amount'];
    }
    ord_transition_locked($order_id, 'slip_issued', $user_id, $slip);
    $steps[] = 'slip_issued';

    if ($c['after'] !== 'slip') {
        $amount = clean_str($c['amount_paid']);
        if ($amount === '') {
            $dep = clean_str($c['deposit_amount']) === '' ? 0 : (int) ord_parse_money($c['deposit_amount']);
            $amount = ord_fmt_cents($total + $dep);   // the receipt covers the total plus any deposit
        }
        ord_transition_locked($order_id, 'paid', $user_id, [
            'or_no' => $c['or_no'], 'amount_paid' => $amount, 'deposit_amount' => $c['deposit_amount'], 'paid_date' => $c['paid_date'],
        ]);
        $steps[] = 'paid';
    }
    if ($c['after'] === 'claimed') {
        if ($toga_lines === 1) {
            rent_issue_locked($order_id, $user_id, [
                'received_by' => $c['received_by'], 'checked_by' => (string) $user_id, 'issued_by' => (string) $user_id,
                'due_date' => $c['due_date'],
            ]);
        } else {
            ord_transition_locked($order_id, 'claimed', $user_id, ['received_by' => $c['received_by'], 'checked_by' => (string) $user_id]);
        }
        $steps[] = 'claimed';
    }

    $o = ord_one('SELECT order_no, order_ref, status, total_amount FROM orders WHERE order_id = ?', [$order_id]);
    return ['order_id' => $order_id, 'ref' => $o['order_ref'], 'order_no' => $o['order_no'], 'status' => $o['status'],
            'total' => $o['total_amount'], 'steps' => $steps];
}

/** Create the order. Returns ['ok'=>true, order_id, ref, order_no, status, ...] or ['ok'=>false, 'error']. */
function walk_create(array $c, ?int $user_id): array
{
    try {
        $res = rent_tx(fn() => walk_create_locked($c, $user_id));
        return ['ok' => true] + $res;
    } catch (DomainException $ex) {
        return ['ok' => false, 'error' => $ex->getMessage()];
    } catch (Throwable $ex) {
        error_log('walk_create: ' . $ex->getMessage());
        return ['ok' => false, 'error' => 'The walk-in order could not be saved. Nothing was changed. Please try again.'];
    }
}
