<?php
/**
 * CLI tests for Session 8 (toga rentals and walk-in orders).
 *   php tests/session8/run_tests.php
 * Needs a SCRATCH database wmsu_s8_test loaded with schema + seed + migration 006
 * and a MySQL user t/t on 127.0.0.1 (see stub.php). Reload it before each run: the
 * tests leave data behind. Never point this at real data.
 */
require __DIR__ . '/stub.php';

if (($argv[1] ?? '') === '--child') {   // race worker: walk-in for the last unit of a size
    $n = (int) $argv[2];
    [$c, $err] = walk_validate([
        'full_name' => "Racer $n", 'relationship' => 'self', 'student_no' => sprintf('2025-9%04d', $n), 'contact_no' => sprintf('09175550%03d', $n),
        'year_level' => '1st Year', 'variant_id' => [(int) $argv[3]], 'qty' => ['1'], 'after' => 'slip',
    ]);
    $r = $err ? ['ok' => false, 'error' => json_encode($err)] : walk_create($c, 2);
    echo $r['ok'] ? "WIN\n" : 'LOSE:' . ($r['error'] ?? '') . "\n";
    exit;
}

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name  -> $extra\n"; }
}
function q(string $sql, array $p = []) { return ord_value($sql, $p); }
function row(string $sql, array $p = []): ?array { return ord_one($sql, $p); }
function variant(string $sku): array { return row('SELECT * FROM item_variants WHERE sku = ?', [$sku]); }
function vid(string $sku): int { return (int) variant($sku)['variant_id']; }
function st(int $id): string { return (string) q('SELECT status FROM orders WHERE order_id = ?', [$id]); }
function sid(string $no): int { return (int) q('SELECT student_id FROM students WHERE student_no = ?', [$no]); }
function rental(int $order_id): ?array { return rent_for_order($order_id); }
function toga_request(string $student_no, string $sku, string $ref): int {
    $s = row('SELECT * FROM students WHERE student_no = ?', [$student_no]);
    ord_q("INSERT INTO customers (full_name, contact_no) VALUES (?, '09175550000')", [$s['full_name']]);
    $cid = (int) db()->lastInsertId();
    ord_q("INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id, academic_period_id)
           VALUES (?, ?, 'rental', 'requested', 'online', ?, ?, 1)", ['TMP-' . $ref, $ref, $cid, $s['student_id']]);
    $oid = (int) db()->lastInsertId();
    $v = variant($sku);
    ord_q("INSERT INTO order_items (order_id, variant_id, item_name, size_label, quantity, unit_price, line_total) VALUES (?, ?, 'Bachelor Toga Set', ?, 1, ?, ?)",
        [$oid, $v['variant_id'], $v['size_label'], $v['unit_price'], $v['unit_price']]);
    ord_q('UPDATE orders SET total_amount = ? WHERE order_id = ?', [$v['unit_price'], $oid]);
    return $oid;
}
function form(array $o = []): array {   // a valid walk-in form, overridable
    return array_merge([
        'full_name' => 'Walk In Buyer', 'relationship' => 'self', 'student_no' => '2025-00001', 'contact_no' => '0917 555 0101',
        'year_level' => '1st Year', 'variant_id' => [], 'qty' => [], 'after' => 'slip',
    ], $o);
}
function walk(array $post, int $uid = 2): array {
    [$c, $err] = walk_validate($post);
    return $err ? ['ok' => false, 'error' => 'VALIDATION ' . json_encode($err)] : walk_create($c, $uid);
}
function consistent(): bool {
    return (int) q('SELECT COUNT(*) FROM item_variants WHERE quantity_reserved <> (
        SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi WHERE oi.variant_id = item_variants.variant_id AND oi.stock_held = 1)') === 0
        && (int) q('SELECT COUNT(*) FROM item_variants WHERE quantity_reserved > quantity_on_hand') === 0;
}
function movement_sum_ok(): bool {
    return (int) q('SELECT COUNT(*) FROM (SELECT v.variant_id, v.quantity_on_hand - COALESCE(SUM(m.on_hand_delta),0) AS diff
        FROM item_variants v LEFT JOIN inventory_movements m ON m.variant_id = v.variant_id GROUP BY v.variant_id, v.quantity_on_hand HAVING diff <> 0) x') === 0;
}

echo "Migration\n";
check('migration 006 applied', rent_migration_problem() === null, (string) rent_migration_problem());
check('toga_rental_days setting exists (0 = period end)', get_setting('toga_rental_days', null) === 0);

echo "List and counts (seed: one issued toga)\n";
$f = rent_read_filters([]);
check('seed rental shows as issued', rent_status_counts($f)['issued'] === 1 && rent_count($f) >= 1);
check('status filter works', rent_count(rent_read_filters(['status' => 'issued'])) === 1 && rent_count(rent_read_filters(['status' => 'returned'])) === 0);
check('bad status filter ignored', rent_read_filters(['status' => "x'; DROP"])['status'] === '');
check('search by student no.', rent_count(rent_read_filters(['q' => '2021-00555'])) === 1);
check('search by name', rent_count(rent_read_filters(['q' => 'grace'])) === 1);
check('period filter', rent_count(rent_read_filters(['period' => 1])) >= 1 && rent_count(rent_read_filters(['period' => 999])) === 0);
check('wildcard in search is literal', rent_count(rent_read_filters(['q' => '%'])) === 0);

echo "Approve, pay, issue\n";
$o = toga_request('2023-00123', 'TOGA-BACH-L', 'T8-ANA');
check('request appears as requested', rent_count(rent_read_filters(['status' => 'requested'])) === 1);
check('issue before approval refused', !rent_issue($o, 2, ['received_by' => 'Ana Reyes'])['ok']);
check('decline needs a reason', !rent_decide($o, 'declined', 2, '')['ok'] && st($o) === 'requested');
$before = variant('TOGA-BACH-L');
$r = rent_decide($o, 'approved', 2);
check('approve ok', $r['ok'] === true, $r['message'] ?? '');
check('approve reserves one toga', (int) variant('TOGA-BACH-L')['quantity_reserved'] === (int) $before['quantity_reserved'] + 1);
check('rentals row created as reserved', rental($o)['status'] === 'reserved');
check('shows as reserved (approved, not issued)', rent_effective_status(row('SELECT * FROM orders WHERE order_id=?', [$o]), rental($o)) === 'reserved');
$r = rent_issue($o, 2, ['received_by' => 'Ana Reyes']);
check('issue before payment refused with a clear message', !$r['ok'] && stripos($r['message'], 'payment') !== false, $r['message'] ?? '');
transition_order($o, 'slip_issued', 2, []);
check('issue after slip but before payment refused', !rent_issue($o, 2, ['received_by' => 'Ana Reyes'])['ok'] && st($o) === 'slip_issued');
$r = transition_order($o, 'paid', 2, ['or_no' => 'OR-8001', 'amount_paid' => '850', 'deposit_amount' => '500', 'paid_date' => date('Y-m-d')]);
check('payment with deposit recorded', $r['ok'] === true, $r['message'] ?? '');
check('issue needs received_by', !rent_issue($o, 2, [])['ok'] && st($o) === 'paid');
check('future issue date refused', !rent_issue($o, 2, ['received_by' => 'Ana Reyes', 'issue_date' => date('Y-m-d', strtotime('+2 days'))])['ok']);
check('due date before issue date refused', !rent_issue($o, 2, ['received_by' => 'Ana Reyes', 'due_date' => '2020-01-01'])['ok']);
check('bad deposit refused', !rent_issue($o, 2, ['received_by' => 'Ana Reyes', 'deposit_amount' => 'abc'])['ok']);
check('failed attempts changed nothing', st($o) === 'paid' && rental($o)['status'] === 'reserved' && consistent());
$b = variant('TOGA-BACH-L');
$r = rent_issue($o, 2, ['received_by' => 'Ana Reyes', 'due_date' => '2026-12-01', 'issued_by' => '3']);
$a = variant('TOGA-BACH-L'); $rt = rental($o);
check('issue ok', $r['ok'] === true, $r['message'] ?? '');
check('order claimed, rental released', st($o) === 'claimed' && $rt['status'] === 'released');
check('due date, issuing staff recorded', $rt['due_date'] === '2026-12-01' && (int) $rt['issued_by'] === 3 && $rt['issued_by_name'] !== null);
check('deposit taken from the payment record', (float) $rt['deposit_amount'] === 500.0);
check('stock: on hand -1 and reserved -1', (int) $a['quantity_on_hand'] === (int) $b['quantity_on_hand'] - 1 && (int) $a['quantity_reserved'] === (int) $b['quantity_reserved'] - 1);
check('rented_out movement written', (int) q("SELECT COUNT(*) FROM inventory_movements WHERE order_id=? AND movement_type='rented_out'", [$o]) === 1);
check('toga_issued history row', (int) q("SELECT COUNT(*) FROM audit_logs WHERE entity_type='orders' AND entity_id=? AND action='toga_issued'", [(string) $o]) === 1);
check('issue twice refused', !rent_issue($o, 2, ['received_by' => 'Ana Reyes'])['ok']);
check('shows as issued', rent_status_counts(rent_read_filters([]))['issued'] === 2);

echo "Return\n";
$rid = (int) $rt['rental_id'];
check('return needs a condition', !rent_return($rid, 2, [])['ok'] && rental($o)['status'] === 'released');
check('return date in the future refused', !rent_return($rid, 2, ['condition' => 'good', 'return_date' => date('Y-m-d', strtotime('+1 day'))])['ok']);
check('bad deposit_returned value refused', !rent_return($rid, 2, ['condition' => 'good', 'deposit_returned' => 'maybe'])['ok']);
check('bad penalty refused', !rent_return($rid, 2, ['condition' => 'good', 'penalty_amount' => '-5'])['ok']);
$b = variant('TOGA-BACH-L');
$r = rent_return($rid, 2, ['condition' => 'good']);   // penalty and deposit_returned left empty on purpose
$a = variant('TOGA-BACH-L'); $rt = rental($o);
check('return works with deposit and penalty empty', $r['ok'] === true, $r['message'] ?? '');
check('rental returned, nulls kept', $rt['status'] === 'returned' && $rt['penalty_amount'] === null && $rt['deposit_returned'] === null && $rt['condition_on_return'] === 'good');
check('received_by recorded (defaults to you)', (int) $rt['received_by'] === 2);
check('good toga goes back into stock', (int) $a['quantity_on_hand'] === (int) $b['quantity_on_hand'] + 1);
check('returned movement written', (int) q("SELECT COUNT(*) FROM inventory_movements WHERE order_id=? AND movement_type='returned' AND on_hand_delta=1", [$o]) === 1);
check('return twice refused, stock unchanged', !rent_return($rid, 2, ['condition' => 'good'])['ok'] && (int) variant('TOGA-BACH-L')['quantity_on_hand'] === (int) $a['quantity_on_hand']);
check('shows as returned', rent_count(rent_read_filters(['status' => 'returned'])) === 1);

echo "One active rental per student per period\n";
$o_dup = toga_request('2021-00555', 'TOGA-BACH-S', 'T8-GRACE');   // Grace Lim already holds an issued toga
$sb = variant('TOGA-BACH-S');
$r = rent_decide($o_dup, 'approved', 2);
check('second active rental refused', !$r['ok'] && st($o_dup) === 'requested', $r['message'] ?? '');
check('nothing was reserved', (int) variant('TOGA-BACH-S')['quantity_reserved'] === (int) $sb['quantity_reserved'] && rental($o_dup) === null);
$r = rent_decide($o_dup, 'declined', 2, 'Student already has a toga');
check('decline with a reason', $r['ok'] === true && st($o_dup) === 'declined', $r['message'] ?? '');
check('declined shows in the list', rent_count(rent_read_filters(['status' => 'declined'])) === 1);
check('declined order cannot be decided again', !rent_decide($o_dup, 'approved', 2)['ok']);
$o_ana2 = toga_request('2023-00123', 'TOGA-BACH-M', 'T8-ANA2');   // Ana returned hers, so a new request is allowed again
check('after return the student may rent again', rent_decide($o_ana2, 'approved', 2)['ok'] === true);
check('walk-in refuses a student with an active toga', rent_student_has_active(sid('2023-00123'), 1) === true && rent_student_has_active(sid('2023-00123'), 1, $o_ana2) === false);

echo "Overdue\n";
$o_v = rental((int) q("SELECT order_id FROM orders WHERE order_no='OS-2026-0007'"));
ord_q('UPDATE rentals SET due_date = ? WHERE rental_id = ?', [date('Y-m-d', strtotime('-3 days')), $o_v['rental_id']]);
check('past due date shows as overdue without the scheduled job', rent_overdue_count() === 1 && rent_count(rent_read_filters(['status' => 'overdue'])) === 1);
$od = rent_overdue_rows();
check('overdue list has days late', count($od) === 1 && (int) $od[0]['days_late'] === 3 && $od[0]['student_no'] === '2021-00555');
check('overdue badge text', strpos(rent_status_badge('overdue'), 's8-badge-overdue') !== false);
ord_q('UPDATE rentals SET due_date = ? WHERE rental_id = ?', [date('Y-m-d', strtotime('+30 days')), $o_v['rental_id']]);
check('not overdue when due date is ahead', rent_overdue_count() === 0);

echo "Default due date comes from settings\n";
check('0 days = end of the academic period', rent_default_due('2026-11-09', '2026-12-18') === '2026-12-18');
ord_q("UPDATE settings SET setting_value = '10' WHERE setting_key = 'toga_rental_days'");
check('10 days = issue date + 10', rent_default_due('2026-11-09', '2026-12-18') === '2026-11-19');
ord_q("UPDATE settings SET setting_value = '0' WHERE setting_key = 'toga_rental_days'");
check('no period and no days = no due date', rent_default_due('2026-11-09', null) === null);

echo "Walk-in orders\n";
$polo_m = vid('TER-CS-POLO-M');
$before = variant('TER-CS-POLO-M');
$r = walk(form(['variant_id' => [$polo_m], 'qty' => ['5'], 'student_no' => '2025-00001', 'student_name' => '']));
check('walk-in above the online cap (3) is allowed', $r['ok'] === true, $r['error'] ?? '');
$w1 = (int) ($r['order_id'] ?? 0);
$o1 = row('SELECT * FROM orders WHERE order_id = ?', [$w1]);
check('created at slip_issued, source walk_in, created_by set', $o1['status'] === 'slip_issued' && $o1['source'] === 'walk_in' && (int) $o1['created_by'] === 2);
check('official slip number assigned', preg_match('/^OS-\d{4}-\d{4}$/', $o1['order_no']) === 1, $o1['order_no']);
check('stock reserved for 5', (int) variant('TER-CS-POLO-M')['quantity_reserved'] === (int) $before['quantity_reserved'] + 5);
check('total is price x qty', $o1['total_amount'] === '1600.00', $o1['total_amount']);
check('over-cap note in the history', (int) q("SELECT COUNT(*) FROM audit_logs WHERE entity_type='orders' AND entity_id=? AND action='order_note_added'", [(string) $w1]) === 1);
check('history shows approved and slip steps', (int) q("SELECT COUNT(*) FROM audit_logs WHERE entity_type='orders' AND entity_id=? AND action='order_status_changed'", [(string) $w1]) === 2);
check('slip can be printed (status allows it)', in_array($o1['status'], ['slip_issued', 'paid', 'claimed', 'unclaimed'], true));
check('new student was created with the level data', cust_find_student('2025-00001')['year_level'] === '1st Year');

$r = walk(form(['student_no' => '2025-00002', 'contact_no' => '09175550102', 'variant_id' => [vid('PRI-BOY-POLO-6')], 'qty' => ['2'], 'after' => 'paid', 'or_no' => 'OR-8100']));
$w2 = (int) ($r['order_id'] ?? 0);
check('paid immediately (amount left blank = total)', $r['ok'] === true && st($w2) === 'paid', $r['error'] ?? '');
check('payment row matches the total', q('SELECT amount_paid FROM payments WHERE order_id = ?', [$w2]) === '490.00');

$pb = variant('PRI-BOY-POLO-8');
$r = walk(form(['student_no' => '2025-00003', 'contact_no' => '09175550103', 'variant_id' => [vid('PRI-BOY-POLO-8')], 'qty' => ['1'], 'after' => 'claimed', 'or_no' => 'OR-8101']));
$w3 = (int) ($r['order_id'] ?? 0);
check('paid and claimed right away', $r['ok'] === true && st($w3) === 'claimed', $r['error'] ?? '');
check('claim moved stock out', (int) variant('PRI-BOY-POLO-8')['quantity_on_hand'] === (int) $pb['quantity_on_hand'] - 1);
check('received_by defaults to the customer', q('SELECT received_by FROM orders WHERE order_id = ?', [$w3]) === 'Walk In Buyer');

$orders_before = (int) q('SELECT COUNT(*) FROM orders'); $res_before = (int) variant('PRI-GIRL-BLSE-12')['quantity_reserved'];
$r = walk(form(['student_no' => '2025-00099', 'contact_no' => '09175550199', 'variant_id' => [vid('PRI-GIRL-BLSE-12')], 'qty' => ['1']]));
check('out of stock refused with a clear message', !$r['ok'] && stripos($r['error'], 'not enough stock') !== false, $r['error'] ?? '');
check('refused order left nothing behind', (int) q('SELECT COUNT(*) FROM orders') === $orders_before && cust_find_student('2025-00099') === null);
$r = walk(form(['student_no' => '2025-00098', 'contact_no' => '09175550198', 'variant_id' => [vid('JHS-PE-SHIRT-M'), vid('PRI-GIRL-BLSE-12')], 'qty' => ['1', '1']]));
check('one short line stops the whole order', !$r['ok'] && (int) q('SELECT COUNT(*) FROM orders') === $orders_before && consistent());
$ps = variant('JHS-PE-SHIRT-L');
$r = walk(form(['student_no' => '2025-00097', 'contact_no' => '09175550197', 'variant_id' => [vid('JHS-PE-SHIRT-L')], 'qty' => ['1'], 'after' => 'paid', 'or_no' => 'OR-8102', 'amount_paid' => '10']));
check('bad payment rolls back the whole walk-in', !$r['ok'] && (int) q('SELECT COUNT(*) FROM orders') === $orders_before && (int) variant('JHS-PE-SHIRT-L')['quantity_reserved'] === (int) $ps['quantity_reserved'] && cust_find_student('2025-00097') === null, $r['error'] ?? '');
$r = walk(form(['student_no' => '2025-00096', 'contact_no' => '09175550196', 'variant_id' => [vid('SHS-SLACKS-CUSTOM')], 'qty' => ['2'], 'year_level' => 'Grade 11']));
check('made-to-order item (no stock) is accepted', $r['ok'] === true && (int) q('SELECT stock_held FROM order_items WHERE order_id = ?', [$r['order_id'] ?? 0]) === 0, $r['error'] ?? '');

echo "Walk-in validation\n";
[, $e] = walk_validate(form(['contact_no' => '123', 'student_no' => '', 'full_name' => 'A', 'variant_id' => [], 'qty' => []]));
check('bad phone, missing student no., short name and no items are all reported', isset($e['contact_no'], $e['student_no'], $e['full_name'], $e['lines']), json_encode($e));
[, $e] = walk_validate(form(['variant_id' => [5], 'qty' => ['0']]));
check('zero quantity refused', isset($e['lines']));
[, $e] = walk_validate(form(['student_no' => 'ABC', 'variant_id' => [5], 'qty' => ['1']]));
check('new student numbers must match the pattern', isset($e['student_no']));
[, $e] = walk_validate(form(['student_no' => '2022-01001', 'variant_id' => [5], 'qty' => ['1']]));
check('known student numbers are accepted as they are', !isset($e['student_no']));
[$c2] = walk_validate(form(['variant_id' => [5, 5], 'qty' => ['1', '2']]));
check('duplicate rows are added together', $c2['lines'] === [5 => 3]);
[, $e] = walk_validate(form(['relationship' => 'parent', 'student_no' => '2025-77777', 'variant_id' => [5], 'qty' => ['1']]));
check('a parent ordering for a new student must give the student name', isset($e['student_name']));

echo "Walk-in togas\n";
$sb = variant('TOGA-BACH-S');
$r = walk(form(['student_no' => '2023-00777', 'full_name' => 'Pedro Ramos', 'contact_no' => '09171110007',
    'variant_id' => [vid('TOGA-BACH-S'), vid('PRI-BOY-POLO-10')], 'qty' => ['1', '1'], 'after' => 'claimed', 'or_no' => 'OR-8200', 'deposit_amount' => '500']));
$wt = (int) ($r['order_id'] ?? 0);
check('toga walk-in claimed right away', $r['ok'] === true && st($wt) === 'claimed', $r['error'] ?? '');
$rt = $wt ? rental($wt) : null;
check('order type is rental', q('SELECT order_type FROM orders WHERE order_id = ?', [$wt]) === 'rental');
check('rental issued with the default due date (period end)', $rt && $rt['status'] === 'released' && $rt['due_date'] === '2026-12-18', json_encode($rt));
check('deposit and issuing staff recorded', $rt && (float) $rt['deposit_amount'] === 500.0 && (int) $rt['issued_by'] === 2);
check('payment = fee 350 + polo 265 + deposit 500', q('SELECT amount_paid FROM payments WHERE order_id = ?', [$wt]) === '1115.00' && q('SELECT deposit_amount FROM payments WHERE order_id = ?', [$wt]) === '500.00', (string) q('SELECT amount_paid FROM payments WHERE order_id = ?', [$wt]));
check('both lines moved out of stock', (int) variant('TOGA-BACH-S')['quantity_on_hand'] === (int) $sb['quantity_on_hand'] - 1);
check('walk-in toga is in the rentals list as issued', rent_count(rent_read_filters(['q' => '2023-00777', 'status' => 'issued'])) === 1);

$r = walk(form(['student_no' => '2021-00555', 'full_name' => 'Grace Lim', 'contact_no' => '09171110006', 'variant_id' => [vid('TOGA-BACH-M')], 'qty' => ['1']]));
check('walk-in refuses a second toga for the same student and period', !$r['ok'] && stripos($r['error'], 'already has a toga') !== false, $r['error'] ?? '');
$r = walk(form(['student_no' => '2025-00050', 'contact_no' => '09175550150', 'variant_id' => [vid('TOGA-BACH-M'), vid('TOGA-BACH-L')], 'qty' => ['1', '1']]));
check('two togas in one order refused', !$r['ok'], $r['error'] ?? '');
$r = walk(form(['student_no' => '2025-00051', 'contact_no' => '09175550151', 'variant_id' => [vid('TOGA-BACH-M')], 'qty' => ['2']]));
check('toga quantity above 1 refused', !$r['ok'], $r['error'] ?? '');
$r = walk(form(['student_no' => '2025-00052', 'contact_no' => '09175550152', 'variant_id' => [vid('PRI-BOY-POLO-6')], 'qty' => ['1'], 'deposit_amount' => '100']));
check('deposit without a toga refused', !$r['ok'], $r['error'] ?? '');
$r = walk(form(['student_no' => '2025-00053', 'contact_no' => '09175550153', 'variant_id' => [vid('TOGA-BACH-M')], 'qty' => ['1']]));
$wq = (int) ($r['order_id'] ?? 0);
check('toga walk-in (slip only) reserves the toga and creates the rental', $r['ok'] === true && rental($wq)['status'] === 'reserved' && st($wq) === 'slip_issued', $r['error'] ?? '');
check('that student cannot start a second request', rent_student_has_active(sid('2025-00053'), 1) === true);
$r = walk(form(['student_no' => '2025-00060', 'contact_no' => '09175550160', 'variant_id' => [vid('TOGA-BACH-M')], 'qty' => ['1'], 'after' => 'claimed', 'or_no' => 'OR-8300', 'due_date' => '2026-11-30']));
$wd = (int) ($r['order_id'] ?? 0);
check('walk-in toga with a chosen due date', $r['ok'] === true && rental($wd)['due_date'] === '2026-11-30', $r['error'] ?? '');

echo "Return a damaged toga with penalty\n";
$rd = rental($wd); $ob = variant('TOGA-BACH-M');
$r = rent_return((int) $rd['rental_id'], 3, ['condition' => 'damaged', 'penalty_amount' => '100', 'deposit_returned' => '0', 'remarks' => 'Torn sleeve', 'received_by' => '2']);
$rd2 = rental($wd);
check('damaged return recorded', $r['ok'] === true && $rd2['status'] === 'returned' && $rd2['condition_on_return'] === 'damaged' && (float) $rd2['penalty_amount'] === 100.0 && (int) $rd2['deposit_returned'] === 0, $r['message'] ?? '');
check('damaged toga is NOT restocked', (int) variant('TOGA-BACH-M')['quantity_on_hand'] === (int) $ob['quantity_on_hand']);
check('adjustment movement explains it', (int) q("SELECT COUNT(*) FROM inventory_movements WHERE order_id=? AND movement_type='adjustment' AND reason LIKE '%not restocked%'", [$wd]) === 1);
check('remarks and receiving staff kept', $rd2['remarks'] === 'Torn sleeve' && (int) $rd2['received_by'] === 2);

echo "Customer history\n";
$h = cust_history(sid('2021-00555'));
check('history has orders, rentals and contacts', count($h['orders']) >= 2 && count($h['contacts']) >= 1 && in_array('issued', array_column($h['rentals'], 'eff_status'), true) && in_array('declined', array_column($h['rentals'], 'eff_status'), true));
check('history order has an item summary', strpos((string) $h['orders'][0]['items_text'], 'Toga') !== false);
check('student search by name and number', count(cust_search_students('Mendoza')) === 1 && cust_search_students('2022-01001')[0]['full_name'] === 'Carlo Mendoza');
check('exact student lookup + last customer prefill', cust_find_student('2022-01001')['full_name'] === 'Carlo Mendoza' && cust_last_customer(sid('2022-01001'))['contact_no'] === '09171110003');
check('unknown student gives null', cust_find_student('nope') === null && cust_search_students('') === []);

echo "Permissions\n";
check('a signed-out caller cannot issue or return', !rent_issue($wq, null, ['received_by' => 'x'])['ok'] && !rent_return(1, null, ['condition' => 'good'])['ok']);
check('inactive account refused', (function () { ord_q('UPDATE users SET is_active = 0 WHERE user_id = 2'); $r = walk(form(['variant_id' => [5], 'qty' => ['1']]), 2); ord_q('UPDATE users SET is_active = 1 WHERE user_id = 2'); return !$r['ok']; })());

check('on hand equals the sum of its movement rows (before the race setup edits stock directly)', movement_sum_ok());

echo "Race for the last unit (5 processes)\n";
$last = vid('JHS-PE-SHIRT-XL');
ord_q('UPDATE item_variants SET quantity_on_hand = quantity_reserved + 1 WHERE variant_id = ?', [$last]);
$procs = []; $pipes = [];
for ($i = 1; $i <= 5; $i++) {
    $procs[$i] = proc_open([PHP_BINARY, __FILE__, '--child', (string) $i, (string) $last], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$wins = 0;
foreach ($procs as $i => $p) { $out = stream_get_contents($pipes[$i][1]); proc_close($p); if (strpos($out, 'WIN') === 0) { $wins++; } }
check("exactly one winner ($wins won)", $wins === 1);

echo "Invariants\n";
check('reserved always equals held lines and never exceeds on hand', consistent());

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
