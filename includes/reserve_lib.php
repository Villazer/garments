<?php
/**
 * includes/reserve_lib.php  (Session 4)
 * Reservation list (PHP session), rule checks, atomic order creation, view access.
 * Depends on: db(), get_setting() (Session 1). Everything else is self-contained.
 */

if (!defined('RES_MAX_LINES')) { define('RES_MAX_LINES', 12); }   // keeps the printed slip on one page
if (!defined('RES_MAX_QTY_INPUT')) { define('RES_MAX_QTY_INPUT', 99); }

/* ------------------------------------------------------------------ */
/* small DB helpers (local, so this file does not guess Session 1 signatures) */
/* ------------------------------------------------------------------ */
function res_q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function res_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/* ------------------------------------------------------------------ */
/* the reservation list: [variant_id => quantity] in $_SESSION          */
/* ------------------------------------------------------------------ */
function res_list(): array
{
    res_session_start();
    $raw = $_SESSION['reserve_list'] ?? [];
    $out = [];
    if (is_array($raw)) {
        foreach ($raw as $vid => $qty) {
            $vid = (int)$vid; $qty = (int)$qty;
            if ($vid > 0 && $qty > 0) { $out[$vid] = $qty; }
        }
    }
    return $out;
}

function res_save(array $list): void
{
    res_session_start();
    $_SESSION['reserve_list'] = $list;
}

function res_count(): int
{
    return array_sum(res_list());
}

function res_remove(int $variant_id): void
{
    $l = res_list();
    unset($l[$variant_id]);
    res_save($l);
}

function res_empty(): void
{
    res_save([]);
}

/* ------------------------------------------------------------------ */
/* data + rules                                                         */
/* ------------------------------------------------------------------ */

/** Variant + item rows keyed by variant_id. $lock = SELECT ... FOR UPDATE (call inside a transaction). */
function res_fetch_variants(array $ids, bool $lock = false): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) { return []; }
    sort($ids);   // always lock in the same order -> no deadlocks between two buyers
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT v.variant_id, v.size_label, v.unit_price, v.quantity_on_hand, v.quantity_reserved,
                   v.is_active AS v_active,
                   i.item_id, i.name AS item_name, i.item_code, i.item_type, i.reservation_mode,
                   i.max_qty_per_request, i.tracks_stock, i.is_active AS i_active
              FROM item_variants v
              JOIN items i ON i.item_id = v.item_id
             WHERE v.variant_id IN ($ph)
             ORDER BY v.variant_id" . ($lock ? ' FOR UPDATE' : '');
    $out = [];
    foreach (res_q($sql, $ids)->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['variant_id']] = $r;
    }
    return $out;
}

function res_cents($price): int
{
    return (int)round(((float)$price) * 100);
}

function res_money_from_cents(int $c): string
{
    return number_format($c / 100, 2, '.', '');
}

/**
 * Check every line of a list against the rules.
 * Returns ['lines' => [variant_id => line], 'ok' => bool, 'approval_needed' => bool, 'total_cents' => int]
 * line = variant_id, qty, item_id, item_name, size_label, unit_price, line_cents, mode, available,
 *        tracks_stock, error (null when fine), kind (null|toga|walkin|stock|limit|gone)
 */
function res_evaluate(array $list, array $rows): array
{
    $item_qty = [];
    foreach ($list as $vid => $qty) {
        if (isset($rows[$vid])) {
            $iid = (int)$rows[$vid]['item_id'];
            $item_qty[$iid] = ($item_qty[$iid] ?? 0) + (int)$qty;
        }
    }

    $lines = [];
    $ok = true; $approval = false; $total = 0;

    foreach ($list as $vid => $qty) {
        $r = $rows[$vid] ?? null;
        $line = [
            'variant_id' => $vid, 'qty' => (int)$qty, 'item_id' => 0, 'item_name' => 'Unavailable item',
            'size_label' => '', 'unit_price' => '0.00', 'line_cents' => 0, 'mode' => 'approval',
            'available' => 0, 'tracks_stock' => 1, 'error' => null, 'kind' => null,
        ];

        if (!$r || !(int)$r['v_active'] || !(int)$r['i_active']) {
            $line['error'] = 'This item or size is no longer available. Please remove it.';
            $line['kind']  = 'gone';
        } else {
            $avail = max(0, (int)$r['quantity_on_hand'] - (int)$r['quantity_reserved']);
            $line = array_merge($line, [
                'item_id' => (int)$r['item_id'], 'item_name' => $r['item_name'], 'size_label' => $r['size_label'],
                'unit_price' => $r['unit_price'], 'mode' => $r['reservation_mode'],
                'available' => $avail, 'tracks_stock' => (int)$r['tracks_stock'],
                'line_cents' => res_cents($r['unit_price']) * (int)$qty,
            ]);
            $cap = max(1, (int)$r['max_qty_per_request']);

            if ($r['item_type'] === 'rental') {
                $line['error'] = 'Togas are not reserved here. Please request them on the Toga Rental page.';
                $line['kind']  = 'toga';
            } elseif ($r['reservation_mode'] === 'none') {
                $line['error'] = 'Walk-in only: this item cannot be reserved online.';
                $line['kind']  = 'walkin';
            } elseif ((int)$qty < 1) {
                $line['error'] = 'Quantity must be at least 1.';
                $line['kind']  = 'limit';
            } elseif ($item_qty[(int)$r['item_id']] > $cap) {
                $line['error'] = "You can reserve at most {$cap} of this item per request (all sizes together).";
                $line['kind']  = 'limit';
            } elseif ((int)$r['tracks_stock'] === 1 && $avail < 1) {
                $line['error'] = 'Out of stock in this size.';
                $line['kind']  = 'stock';
            } elseif ((int)$r['tracks_stock'] === 1 && (int)$qty > $avail) {
                $line['error'] = "Only {$avail} left in this size.";
                $line['kind']  = 'stock';
            }
        }

        if ($line['error'] !== null) { $ok = false; }
        if ($line['mode'] !== 'auto') { $approval = true; }
        $total += $line['line_cents'];
        $lines[$vid] = $line;
    }

    return ['lines' => $lines, 'ok' => $ok && $lines !== [], 'approval_needed' => $approval, 'total_cents' => $total];
}

/** Evaluate the current session list against fresh DB data. */
function res_evaluate_current(): array
{
    $list = res_list();
    return res_evaluate($list, res_fetch_variants(array_keys($list)));
}

/* ------------------------------------------------------------------ */
/* list changes (each one is checked before it is saved)                */
/* ------------------------------------------------------------------ */

/** @return array ['ok'=>bool, 'message'=>string, 'kind'=>?string] */
function res_add(int $variant_id, int $qty): array
{
    if ($variant_id < 1 || $qty < 1 || $qty > RES_MAX_QTY_INPUT) {
        return ['ok' => false, 'message' => 'Please choose a size and a valid quantity.', 'kind' => 'input'];
    }
    $list = res_list();
    if (!isset($list[$variant_id]) && count($list) >= RES_MAX_LINES) {
        return ['ok' => false, 'message' => 'Your list is full (' . RES_MAX_LINES . ' lines). Please submit it first, then start another request.', 'kind' => 'full'];
    }
    $new = $list;
    $new[$variant_id] = ($new[$variant_id] ?? 0) + $qty;

    $ev = res_evaluate($new, res_fetch_variants(array_keys($new)));
    $line = $ev['lines'][$variant_id];
    if ($line['error'] !== null) {
        return ['ok' => false, 'message' => $line['item_name'] . ($line['size_label'] !== '' ? ' (' . $line['size_label'] . ')' : '') . ': ' . $line['error'], 'kind' => $line['kind']];
    }
    foreach ($ev['lines'] as $l) {          // another size of the same item may now break the per-item limit
        if ($l['item_id'] === $line['item_id'] && $l['error'] !== null) {
            return ['ok' => false, 'message' => $l['item_name'] . ': ' . $l['error'], 'kind' => $l['kind']];
        }
    }
    res_save($new);
    return ['ok' => true, 'message' => $line['item_name'] . ' (' . $line['size_label'] . ') added to your reservation list.', 'kind' => null];
}

/**
 * Apply posted quantities. 0 or empty removes the line. A quantity that breaks a rule is rejected and the
 * old quantity is kept. @return string[] error messages (empty when all changes were accepted)
 */
function res_update_quantities(array $posted): array
{
    $list = res_list();
    $new  = $list;
    foreach ($posted as $vid => $q) {
        $vid = (int)$vid;
        if (!isset($list[$vid])) { continue; }
        $q = trim((string)$q);
        if ($q === '' || !ctype_digit($q)) { $q = '-1'; }
        $q = (int)$q;
        if ($q === 0) { unset($new[$vid]); continue; }
        if ($q < 0 || $q > RES_MAX_QTY_INPUT) { continue; }   // invalid input: keep old value
        $new[$vid] = $q;
    }
    $ev = res_evaluate($new, res_fetch_variants(array_keys($new)));
    $errors = [];
    foreach ($new as $vid => $q) {
        if ($q !== ($list[$vid] ?? null) && $ev['lines'][$vid]['error'] !== null && $ev['lines'][$vid]['kind'] !== 'gone') {
            $l = $ev['lines'][$vid];
            $errors[] = $l['item_name'] . ' (' . $l['size_label'] . '): ' . $l['error'];
            $new[$vid] = $list[$vid];   // revert this line only
        }
    }
    res_save($new);
    return $errors;
}

/* ------------------------------------------------------------------ */
/* order creation                                                        */
/* ------------------------------------------------------------------ */

function res_generate_ref(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O/1/I
    for ($try = 0; $try < 10; $try++) {
        $s = '';
        for ($i = 0; $i < 6; $i++) { $s .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        $ref = 'GRM-' . date('Y') . '-' . $s;
        if (!res_q('SELECT 1 FROM orders WHERE order_ref = ?', [$ref])->fetchColumn()) { return $ref; }
    }
    throw new RuntimeException('Could not generate a reference number.');
}

/**
 * Create the order atomically.
 * $c = full_name, relationship, student_no, student_name, contact_no (normalized), email,
 *      education_level_id, course_id, year_level, address, notes
 * @return array ['ok'=>true,'order_id','ref','status','expires_at'] or ['ok'=>false,'problems'=>[...],'error'=>?string]
 */
function res_submit(array $list, array $c): array
{
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $attempt = 0;
    while (true) {
        try {
            return res_submit_once($pdo, $list, $c);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $code = (int)($e->errorInfo[1] ?? 0);
            if (in_array($code, [1213, 1205], true) && ++$attempt < 3) {   // deadlock / lock wait: retry
                usleep(random_int(50000, 200000));
                continue;
            }
            throw $e;
        }
    }
}

function res_submit_once(PDO $pdo, array $list, array $c): array
{
    if (!$list) { return ['ok' => false, 'problems' => [], 'error' => 'Your reservation list is empty.']; }
    if (count($list) > RES_MAX_LINES) { return ['ok' => false, 'problems' => [], 'error' => 'Too many lines in one request.']; }

    $pdo->beginTransaction();

    // 1. lock the variant rows (FOR UPDATE, ascending id) and re-check every rule on the locked data
    $rows = res_fetch_variants(array_keys($list), true);
    $ev   = res_evaluate($list, $rows);
    if (!$ev['ok']) {
        $pdo->rollBack();
        $problems = [];
        foreach ($ev['lines'] as $vid => $l) {
            if ($l['error'] !== null) {
                $problems[$vid] = $l['item_name'] . ($l['size_label'] !== '' ? ' (' . $l['size_label'] . ')' : '') . ': ' . $l['error'];
            }
        }
        return ['ok' => false, 'problems' => $problems, 'error' => null];
    }

    // 2. optional cap on pending online requests per contact number (0 = no limit)
    $max_active = (int)get_setting('max_active_requests_per_contact', 0);
    if ($max_active > 0) {
        $n = (int)res_q("SELECT COUNT(*) FROM orders o JOIN customers cu ON cu.customer_id = o.customer_id
                          WHERE cu.contact_no = ? AND o.status IN ('requested','approved','slip_issued') AND o.deleted_at IS NULL",
                        [$c['contact_no']])->fetchColumn();
        if ($n >= $max_active) {
            $pdo->rollBack();
            return ['ok' => false, 'problems' => [], 'error' => 'You already have the maximum number of pending requests for this contact number. Please finish or cancel one first.'];
        }
    }

    // 3. customer (guest) and student
    $cust = res_q('SELECT customer_id FROM customers WHERE contact_no = ? AND full_name = ? ORDER BY customer_id DESC LIMIT 1',
                  [$c['contact_no'], $c['full_name']])->fetchColumn();
    if ($cust) {
        $customer_id = (int)$cust;
        res_q("UPDATE customers SET email = COALESCE(NULLIF(?, ''), email), address = COALESCE(NULLIF(?, ''), address), relationship = ? WHERE customer_id = ?",
              [$c['email'], $c['address'], $c['relationship'], $customer_id]);
    } else {
        res_q('INSERT INTO customers (full_name, contact_no, email, address, relationship) VALUES (?,?,?,?,?)',
              [$c['full_name'], $c['contact_no'], $c['email'] !== '' ? $c['email'] : null,
               $c['address'] !== '' ? $c['address'] : null, $c['relationship']]);
        $customer_id = (int)$pdo->lastInsertId();
    }

    $lvl = $c['education_level_id'] ?: null;
    $crs = $c['course_id'] ?: null;
    $stu = res_q('SELECT student_id FROM students WHERE student_no = ?', [$c['student_no']])->fetchColumn();
    if ($stu) {
        // never overwrite an existing student record from a guest form; only fill blanks
        $student_id = (int)$stu;
        res_q('UPDATE students SET education_level_id = COALESCE(education_level_id, ?), course_id = COALESCE(course_id, ?),
                                   year_level = COALESCE(year_level, ?) WHERE student_id = ?',
              [$lvl, $crs, $c['year_level'], $student_id]);
    } else {
        res_q('INSERT INTO students (student_no, full_name, education_level_id, course_id, year_level) VALUES (?,?,?,?,?)',
              [$c['student_no'], $c['student_name'] !== '' ? $c['student_name'] : $c['full_name'], $lvl, $crs, $c['year_level']]);
        $student_id = (int)$pdo->lastInsertId();
    }

    $period = res_q('SELECT academic_period_id FROM academic_periods WHERE is_current = 1 LIMIT 1')->fetchColumn();
    $period = $period ? (int)$period : null;

    // 4. the order itself. All lines 'auto' -> approved now and stock held. Any 'approval' line -> requested;
    //    staff approval (sp_approve_order) re-checks and holds the stock atomically.
    $approved = !$ev['approval_needed'];
    $ref      = res_generate_ref();
    $remarks  = $c['notes'] !== '' ? $c['notes'] : null;

    res_q("INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id, academic_period_id, remarks, total_amount)
           VALUES (?, ?, 'reservation', 'requested', 'online', ?, ?, ?, ?, 0)",
          ['T-' . $ref, $ref, $customer_id, $student_id, $period, $remarks]);
    $order_id = (int)$pdo->lastInsertId();

    $prefix  = preg_replace('/[^A-Za-z0-9]/', '', (string)get_setting('order_no_prefix', 'OS')) ?: 'OS';
    $order_no = substr($prefix, 0, 6) . '-' . date('Y') . '-' . str_pad((string)$order_id, 6, '0', STR_PAD_LEFT);
    res_q('UPDATE orders SET order_no = ? WHERE order_id = ?', [$order_no, $order_id]);

    // 5. lines (price snapshot), stock hold + movements when auto-approved
    $total = 0;
    foreach ($ev['lines'] as $vid => $l) {
        $hold = ($approved && (int)$l['tracks_stock'] === 1) ? 1 : 0;
        $unit = res_money_from_cents(res_cents($l['unit_price']));
        $line_total = res_money_from_cents($l['line_cents']);
        res_q('INSERT INTO order_items (order_id, variant_id, item_name, size_label, quantity, unit_price, line_total, stock_held)
               VALUES (?,?,?,?,?,?,?,?)',
              [$order_id, $vid, $l['item_name'], $l['size_label'], $l['qty'], $unit, $line_total, $hold]);
        $total += $l['line_cents'];

        if ($hold) {
            $st = res_q('UPDATE item_variants SET quantity_reserved = quantity_reserved + ?
                          WHERE variant_id = ? AND is_active = 1 AND (quantity_on_hand - quantity_reserved) >= ?',
                        [$l['qty'], $vid, $l['qty']]);
            if ($st->rowCount() !== 1) {   // cannot normally happen: rows are locked and checked above
                $pdo->rollBack();
                return ['ok' => false, 'problems' => [$vid => $l['item_name'] . ' (' . $l['size_label'] . '): not enough stock left.'], 'error' => null];
            }
            res_q("INSERT INTO inventory_movements (variant_id, movement_type, on_hand_delta, reserved_delta, order_id, user_id, reason)
                   VALUES (?, 'reserved', 0, ?, ?, NULL, 'Auto-approved online reservation')",
                  [$vid, $l['qty'], $order_id]);
        }
    }

    if ($approved) {
        $hours = max(1, (int)get_setting('hold_unpaid_hours', 168));
        res_q("UPDATE orders SET status = 'approved', approved_by = NULL, approved_at = NOW(),
                                 expires_at = DATE_ADD(NOW(), INTERVAL ? HOUR), total_amount = ? WHERE order_id = ?",
              [$hours, res_money_from_cents($total), $order_id]);
    } else {
        res_q('UPDATE orders SET total_amount = ? WHERE order_id = ?', [res_money_from_cents($total), $order_id]);
    }

    res_q("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values) VALUES (NULL, 'order_created_online', 'orders', ?, ?)",
          [(string)$order_id, json_encode(['ref' => $ref, 'status' => $approved ? 'approved' : 'requested', 'lines' => count($list)])]);

    $pdo->commit();

    $exp = res_q('SELECT expires_at FROM orders WHERE order_id = ?', [$order_id])->fetchColumn();
    return ['ok' => true, 'order_id' => $order_id, 'ref' => $ref, 'status' => $approved ? 'approved' : 'requested',
            'expires_at' => $exp ?: null];
}

/* ------------------------------------------------------------------ */
/* who may see a confirmation / slip                                    */
/* ------------------------------------------------------------------ */

function res_clean_ref(string $ref): string
{
    $ref = strtoupper(trim($ref));
    return preg_match('/^GRM-\d{4}-[A-Z0-9]{6}$/', $ref) ? $ref : '';
}

function res_find_order_by_ref(string $ref): ?array
{
    if ($ref === '') { return null; }
    $r = res_q('SELECT * FROM orders WHERE order_ref = ? AND deleted_at IS NULL', [$ref])->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function res_grant_view(string $ref): void
{
    res_session_start();
    $_SESSION['res_orders'][$ref] = time();
}

function res_can_view(string $ref): bool
{
    res_session_start();
    if (isset($_SESSION['res_orders'][$ref])) { return true; }
    if (function_exists('is_logged_in') && is_logged_in() && function_exists('can') && can('orders.view')) { return true; }
    return false;
}

function res_verify_contact(string $ref, string $contact): bool
{
    $contact = trim($contact);
    if ($ref === '' || $contact === '') { return false; }
    $norm = function_exists('normalize_ph_mobile') ? normalize_ph_mobile($contact) : null;
    $r = res_q('SELECT cu.contact_no, cu.email FROM orders o JOIN customers cu ON cu.customer_id = o.customer_id
                 WHERE o.order_ref = ? AND o.deleted_at IS NULL', [$ref])->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return false; }
    if ($norm !== null && $norm === $r['contact_no']) { return true; }
    return $r['email'] !== null && $r['email'] !== '' && strcasecmp($contact, $r['email']) === 0;
}

/**
 * Gate for confirmation.php and slip.php. Call BEFORE any output.
 * Returns the order row when the visitor may see it; otherwise shows a "verify" form and exits.
 */
function res_gate(string $raw_ref, string $self_path): array
{
    res_session_start();
    $ref   = res_clean_ref($raw_ref);
    $order = res_find_order_by_ref($ref);
    if ($order && res_can_view($ref)) { return $order; }

    $error = null;
    $v = $_SESSION['res_verify'] ?? ['n' => 0, 'until' => 0];
    $locked = ((int)$v['until'] > time());

    if (is_post() && !$locked) {
        csrf_require();
        $contact = (string)($_POST['contact'] ?? '');
        if ($ref !== '' && res_verify_contact($ref, $contact)) {
            res_grant_view($ref);
            unset($_SESSION['res_verify']);
            redirect($self_path, ['ref' => $ref]);
        }
        $v['n'] = (int)$v['n'] + 1;
        if ($v['n'] >= 5) { $v = ['n' => 0, 'until' => time() + 600]; $locked = true; }
        $_SESSION['res_verify'] = $v;
        $error = 'The reference number and contact details did not match.';
    }
    if ($locked) { $error = 'Too many attempts. Please wait a few minutes and try again.'; }

    $page_title = 'Verify your request'; $nav_active = 'catalog';
    require CATALOG_HEADER;
    echo '<link rel="stylesheet" href="' . e(asset('css/reserve.css')) . '">';
    ?>
    <main class="rv-wrap rv-narrow">
      <h1>Open your reservation</h1>
      <p class="rv-muted">Enter the contact number or email you used when you reserved, so we know it is you.</p>
      <?php if ($error): ?><div class="rv-alert rv-alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="<?= e(url($self_path, ['ref' => $ref])) ?>" class="rv-card rv-form" data-once>
        <?= csrf_field() ?>
        <label for="rv-ref">Reference number</label>
        <input id="rv-ref" type="text" value="<?= e($ref !== '' ? $ref : strtoupper(trim($raw_ref))) ?>" readonly>
        <label for="rv-contact">Contact number or email</label>
        <input id="rv-contact" name="contact" type="text" autocomplete="off" required <?= $locked ? 'disabled' : '' ?>>
        <button class="rv-btn rv-btn-primary" type="submit" <?= $locked ? 'disabled' : '' ?>>Continue</button>
      </form>
    </main>
    <script src="<?= e(asset('js/reserve.js')) ?>"></script>
    <?php
    require CATALOG_FOOTER;
    exit;
}
