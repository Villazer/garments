<?php
/**
 * Toga rental request (Session 5).
 * Depends on includes/public5_common.php.
 *
 * Exception codes used by tog_create_locked() (all messages are safe to show):
 *   1 = out of stock   2 = student already has an active rental   3 = too many open requests
 *   4 = requests closed (no current academic period)   0 = anything else
 */

const TOG_STUDENT_NO_RE = '/^[A-Za-z0-9][A-Za-z0-9-]{3,29}$/';
const TOG_IP_LIMIT      = 10;   // successful requests per IP per hour

/** Toga items that can be requested online, with sizes, price and availability. */
function tog_catalog(): array
{
    $rows = db_all(
        "SELECT i.item_id, i.name AS item_name, i.description, i.reservation_mode,
                v.variant_id, v.size_label, v.unit_price, v.low_stock_threshold,
                (v.quantity_on_hand - v.quantity_reserved) AS available
           FROM items i
           JOIN item_variants v ON v.item_id = i.item_id
          WHERE i.item_type = 'rental' AND i.is_active = 1 AND v.is_active = 1
            AND i.reservation_mode <> 'none'
          ORDER BY i.name, v.variant_id"
    );
    $grouped = [];
    foreach ($rows as $r) {
        $id = (int) $r['item_id'];
        if (!isset($grouped[$id])) {
            $grouped[$id] = ['item_id' => $id, 'name' => $r['item_name'], 'description' => $r['description'], 'sizes' => []];
        }
        $grouped[$id]['sizes'][] = $r;
    }
    return array_values($grouped);
}

function tog_courses(): array
{
    return db_all('SELECT course_id, code, name FROM courses WHERE is_active = 1 ORDER BY code');
}

function tog_requester_options(): array
{
    return ['self' => 'The student (myself)', 'parent' => 'Parent', 'guardian' => 'Guardian', 'other' => 'Someone else'];
}

/** Student number check: our own pattern first, then the Session 1 validator if it accepts a single argument. */
function tog_check_student_no(string $v): ?string
{
    if (!preg_match(TOG_STUDENT_NO_RE, $v)) {
        return 'Enter a valid student number (letters, numbers and dashes, 4 to 30 characters).';
    }
    if (function_exists('validate_student_no')) {
        try {
            $msg = validate_student_no($v);
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
        } catch (Throwable $ex) {
            // A different signature must not break the page; our own pattern already passed.
        }
    }
    return null;
}

/** Read and validate the form. Returns [clean values, errors by field]. */
function tog_validate(array $post, array $course_ids, array $allowed_variant_ids): array
{
    $c = [
        'variant_id'     => (int) ($post['variant_id'] ?? 0),
        'student_no'     => clean_str($post['student_no'] ?? ''),
        'student_name'   => clean_str($post['student_name'] ?? ''),
        'requester'      => clean_str($post['requester'] ?? 'self'),
        'requester_name' => clean_str($post['requester_name'] ?? ''),
        'contact_no'     => clean_str($post['contact_no'] ?? ''),
        'email'          => clean_str($post['email'] ?? ''),
        'course_id'      => (int) ($post['course_id'] ?? 0),
        'year_level'     => clean_str($post['year_level'] ?? ''),
        'agree'          => !empty($post['agree']),
    ];
    $err = [];

    if (!in_array($c['variant_id'], $allowed_variant_ids, true)) {
        $err['variant_id'] = 'Choose a toga size.';
    }
    if (($m = tog_check_student_no($c['student_no'])) !== null) {
        $err['student_no'] = $m;
    }
    $len = function_exists('mb_strlen') ? mb_strlen($c['student_name']) : strlen($c['student_name']);
    if ($len < 2 || $len > 120) {
        $err['student_name'] = 'Enter the student\'s full name (2 to 120 characters).';
    }
    if (!array_key_exists($c['requester'], tog_requester_options())) {
        $c['requester'] = 'self';
    }
    if ($c['requester'] !== 'self') {
        $rl = function_exists('mb_strlen') ? mb_strlen($c['requester_name']) : strlen($c['requester_name']);
        if ($rl < 2 || $rl > 120) {
            $err['requester_name'] = 'Enter your full name (2 to 120 characters).';
        }
    } else {
        $c['requester_name'] = $c['student_name'];
    }
    $mobile = normalize_ph_mobile($c['contact_no']);
    if ($mobile === null) {
        $err['contact_no'] = 'Enter a valid mobile number, for example 09171234567.';
    } else {
        $c['contact_no'] = $mobile; // stored normalised so tracking lookups match
    }
    if ($c['email'] !== '' && (strlen($c['email']) > 120 || !filter_var($c['email'], FILTER_VALIDATE_EMAIL))) {
        $err['email'] = 'Enter a valid email address or leave it blank.';
    }
    if (!in_array($c['course_id'], $course_ids, true)) {
        $err['course_id'] = 'Choose the student\'s course.';
    }
    $yl = function_exists('mb_strlen') ? mb_strlen($c['year_level']) : strlen($c['year_level']);
    if ($yl < 1 || $yl > 20) {
        $err['year_level'] = 'Enter the year level, for example 4th Year (up to 20 characters).';
    }
    if (!$c['agree']) {
        $err['agree'] = 'Please confirm that you understand the rental terms are set by the shop.';
    }
    return [$c, $err];
}

/**
 * Create the toga rental request. Returns
 *   ['ok'=>true,'order_id','ref','status','expires_at']  or  ['ok'=>false,'error','code'].
 * $c must come from tog_validate() with no errors.
 */
function tog_create_request(array $c): array
{
    $ip_key = s5_rate_key('ip', s5_client_ip());
    if (s5_rate_count('toga_request', $ip_key, 60) >= TOG_IP_LIMIT) {
        return ['ok' => false, 'code' => 0, 'error' => 'Too many requests were sent from this device. Please try again later or visit the Garment Shop.'];
    }
    try {
        $res = s5_transaction(function () use ($c) {
            return tog_create_locked($c);
        });
    } catch (DomainException $ex) {
        return ['ok' => false, 'code' => (int) $ex->getCode(), 'error' => $ex->getMessage()];
    }
    s5_rate_hit('toga_request', $ip_key);
    return $res;
}

/** The transactional part. Runs inside db_transaction (see tog_create_request). */
function tog_create_locked(array $c): array
{
    $period = s5_current_period();
    if ($period === null) {
        throw new DomainException('Toga rental requests are closed right now because no academic period is open. Please ask the Garment Shop.', 4);
    }
    $period_id = (int) $period['academic_period_id'];

    $course = db_one('SELECT course_id FROM courses WHERE course_id = ? AND is_active = 1', [$c['course_id']]);
    if (!$course) {
        throw new DomainException('Please choose the student\'s course again.', 0);
    }

    // 1) Student row, locked. The lock also serialises two requests for the same
    //    student, which is what makes the one-active-rental check below race-free.
    $student = tog_lock_student($c);

    // 2) Variant row, locked, then its item.
    $v = db_one(
        'SELECT variant_id, item_id, size_label, unit_price, quantity_on_hand, quantity_reserved, is_active
           FROM item_variants WHERE variant_id = ? FOR UPDATE',
        [$c['variant_id']]
    );
    $item = $v ? db_one(
        'SELECT item_id, name, item_type, reservation_mode, education_level_id, tracks_stock, is_active
           FROM items WHERE item_id = ?',
        [$v['item_id']]
    ) : null;
    if (!$v || !$item || !(int) $v['is_active'] || !(int) $item['is_active'] || $item['item_type'] !== 'rental') {
        throw new DomainException('That toga size is not available for online requests.', 0);
    }
    if ($item['reservation_mode'] === 'none') {
        throw new DomainException('This toga can only be arranged at the Garment Shop counter.', 0);
    }

    // 3) One active rental per student per academic period.
    if (tog_student_has_active($student['student_id'], $period_id)) {
        throw new DomainException('A toga rental already exists for this student in the current academic period, so another one cannot be requested. If you think this is a mistake, please visit the Garment Shop.', 2);
    }

    // 4) Optional cap on open requests per contact number (0 = no limit).
    $cap = (int) get_setting('max_active_requests_per_contact', 0);
    if ($cap > 0) {
        $open = (int) db_value(
            "SELECT COUNT(*) FROM orders o JOIN customers cu ON cu.customer_id = o.customer_id
              WHERE cu.contact_no = ? AND o.deleted_at IS NULL
                AND o.status IN ('requested','approved','slip_issued')",
            [$c['contact_no']]
        );
        if ($open >= $cap) {
            throw new DomainException('This contact number already has the maximum number of open requests. Please wait for the shop to process them first.', 3);
        }
    }

    // 5) Stock check on the locked row.
    $tracks = (int) $item['tracks_stock'] === 1;
    if ($tracks && ((int) $v['quantity_on_hand'] - (int) $v['quantity_reserved']) < 1) {
        throw new DomainException('Sorry, that size has just run out of stock. You can join the waitlist or choose another size.', 1);
    }

    // 6) Customer, order, line.
    $customer_id = s5_insert(
        'INSERT INTO customers (full_name, contact_no, email, address, relationship) VALUES (?, ?, ?, NULL, ?)',
        [$c['requester_name'], $c['contact_no'], $c['email'] !== '' ? $c['email'] : null, $c['requester']]
    );

    $auto  = ($item['reservation_mode'] === 'auto');
    $hours = max(1, (int) get_setting('hold_unpaid_hours', 168));
    $price = (string) $v['unit_price'];
    $tmp_no = 'TMP' . bin2hex(random_bytes(8)); // replaced right after we know the id

    $order_id = 0;
    $ref = '';
    for ($try = 0; $try < 5 && $order_id === 0; $try++) {
        $ref = s5_new_ref();
        try {
            if ($auto) {
                $order_id = s5_insert(
                    "INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id,
                                         academic_period_id, approved_at, expires_at, total_amount)
                     VALUES (?, ?, 'rental', 'approved', 'online', ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL $hours HOUR), ?)",
                    [$tmp_no, $ref, $customer_id, $student['student_id'], $period_id, $price]
                );
            } else {
                $order_id = s5_insert(
                    "INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id,
                                         academic_period_id, total_amount)
                     VALUES (?, ?, 'rental', 'requested', 'online', ?, ?, ?, ?)",
                    [$tmp_no, $ref, $customer_id, $student['student_id'], $period_id, $price]
                );
            }
        } catch (PDOException $ex) {
            if (!s5_is_duplicate($ex)) {
                throw $ex;
            }
            $order_id = 0; // reference collided: draw another
        }
    }
    if ($order_id === 0) {
        throw new RuntimeException('Could not generate a unique reference number');
    }
    s5_exec('UPDATE orders SET order_no = ? WHERE order_id = ?', [s5_order_no($order_id), $order_id]);

    $order_item_id = s5_insert(
        'INSERT INTO order_items (order_id, variant_id, item_name, size_label, quantity, unit_price, line_total, stock_held)
         VALUES (?, ?, ?, ?, 1, ?, ?, ?)',
        [$order_id, $v['variant_id'], $item['name'], $v['size_label'], $price, $price, ($auto && $tracks) ? 1 : 0]
    );

    // 7) Auto items hold stock and the rental slot right away (same effect as sp_approve_order).
    if ($auto) {
        if ($tracks) {
            s5_exec('UPDATE item_variants SET quantity_reserved = quantity_reserved + 1 WHERE variant_id = ?', [$v['variant_id']]);
            s5_exec(
                "INSERT INTO inventory_movements (variant_id, movement_type, on_hand_delta, reserved_delta, order_id, user_id, reason)
                 VALUES (?, 'reserved', 0, 1, ?, NULL, 'Reservation approved (auto)')",
                [$v['variant_id'], $order_id]
            );
        }
        s5_exec(
            "INSERT INTO rentals (order_item_id, student_id, academic_period_id, variant_id, status, due_date)
             VALUES (?, ?, ?, ?, 'reserved', ?)",
            [$order_item_id, $student['student_id'], $period_id, $v['variant_id'], $period['end_date']]
        );
    }

    // 8) Fill blank student fields only; an existing student row is never overwritten.
    s5_exec(
        "UPDATE students
            SET course_id = COALESCE(course_id, ?),
                education_level_id = COALESCE(education_level_id, ?),
                year_level = IF(year_level IS NULL OR year_level = '', ?, year_level)
          WHERE student_id = ?",
        [$c['course_id'], $item['education_level_id'], $c['year_level'], $student['student_id']]
    );

    s5_exec(
        "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values, ip_address)
         VALUES (NULL, 'order_created_online', 'orders', ?, ?, ?)",
        [(string) $order_id, json_encode(['order_type' => 'rental', 'status' => $auto ? 'approved' : 'requested']), substr(s5_client_ip(), 0, 45)]
    );

    $expires = $auto ? db_value('SELECT expires_at FROM orders WHERE order_id = ?', [$order_id]) : null;
    return ['ok' => true, 'order_id' => $order_id, 'ref' => $ref, 'status' => $auto ? 'approved' : 'requested', 'expires_at' => $expires ?: null];
}

/** Find or create the student by student_no and lock the row. */
function tog_lock_student(array $c): array
{
    $sql = 'SELECT student_id FROM students WHERE student_no = ? FOR UPDATE';
    $row = db_one($sql, [$c['student_no']]);
    if (!$row) {
        try {
            s5_exec('INSERT INTO students (student_no, full_name) VALUES (?, ?)', [$c['student_no'], $c['student_name']]);
        } catch (PDOException $ex) {
            if (!s5_is_duplicate($ex)) {
                throw $ex;
            }
            // another request created it a moment ago: fall through and read it
        }
        $row = db_one($sql, [$c['student_no']]);
    }
    return ['student_id' => (int) $row['student_id']];
}

/**
 * Does the student already have a toga rental in this period?
 * Two sources: rentals rows (created on approval) and still-'requested' rental
 * orders, which have no rentals row yet because stock is held only on approval.
 */
function tog_student_has_active(int $student_id, int $period_id): bool
{
    $a = db_value(
        "SELECT COUNT(*) FROM rentals
          WHERE student_id = ? AND academic_period_id = ? AND status IN ('reserved','released','overdue')",
        [$student_id, $period_id]
    );
    if ((int) $a > 0) {
        return true;
    }
    $b = db_value(
        "SELECT COUNT(*) FROM orders
          WHERE student_id = ? AND order_type = 'rental' AND status = 'requested' AND deleted_at IS NULL
            AND (academic_period_id = ? OR academic_period_id IS NULL)",
        [$student_id, $period_id]
    );
    return (int) $b > 0;
}

/** Data for the confirmation page (one rental order by reference). */
function tog_fetch_confirmation(string $ref): ?array
{
    $row = db_one(
        "SELECT o.order_id, o.order_ref, o.order_no, o.status, o.total_amount, o.created_at, o.expires_at,
                TIMESTAMPDIFF(SECOND, NOW(), o.expires_at) AS expires_in,
                s.full_name AS student_name, s.student_no,
                p.school_year, p.semester, p.end_date,
                oi.item_name, oi.size_label, oi.unit_price
           FROM orders o
           JOIN order_items oi ON oi.order_id = o.order_id
           LEFT JOIN students s ON s.student_id = o.student_id
           LEFT JOIN academic_periods p ON p.academic_period_id = o.academic_period_id
          WHERE o.order_ref = ? AND o.order_type = 'rental' AND (o.deleted_at IS NULL OR o.status = 'expired')
          ORDER BY oi.order_item_id LIMIT 1",
        [$ref]
    );
    return $row ?: null;
}
