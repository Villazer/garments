<?php
// php tests/session6/run_tests.php   (scratch DB wmsu_s6_test loaded from the schema + sample seed)
require __DIR__ . '/stub.php';
require __DIR__ . '/../../includes/orders.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok ? '' : "  -> $extra") . "\n";
}
function st(int $id): string { return (string) ord_value('SELECT status FROM orders WHERE order_id = ?', [$id]); }
function var_row(string $sku): array { return ord_one('SELECT * FROM item_variants WHERE sku = ?', [$sku]); }
function oid(string $no): int { return (int) ord_value('SELECT order_id FROM orders WHERE order_no = ?', [$no]); }
function new_order(int $cust, int $stu, string $type, array $lines, string $ref): int {
    ord_q("INSERT INTO orders (order_no, order_ref, order_type, status, source, customer_id, student_id, academic_period_id)
           VALUES (?, ?, ?, 'requested', 'online', ?, ?, 1)", ['TMP-' . $ref, $ref, $type, $cust, $stu]);
    $id = (int) db()->lastInsertId();
    foreach ($lines as [$sku, $qty]) { ord_q('CALL sp_add_order_item(?, ?, ?, 1)', [$id, var_row($sku)['variant_id'], $qty]); }
    return $id;
}
function consistent(): bool {
    return (int) ord_value('SELECT COUNT(*) FROM item_variants WHERE quantity_reserved <> (
        SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi WHERE oi.variant_id = item_variants.variant_id AND oi.stock_held = 1)') === 0;
}

$STAFF = 2; $MANAGER = 3;

// 1. approve a requested order (O1: 2x Primary Boys Polo 8) reserves stock
$o1 = oid('OS-2026-0001');
$before = var_row('PRI-BOY-POLO-8');
$r = transition_order($o1, 'approved', $STAFF);
$after = var_row('PRI-BOY-POLO-8');
check('approve ok', $r['ok'] === true, $r['message'] ?? '');
check('approve reserves 2', (int)$after['quantity_reserved'] === (int)$before['quantity_reserved'] + 2);
check('approve sets expiry + approver', ord_value('SELECT expires_at FROM orders WHERE order_id=?', [$o1]) !== null && (int) ord_value('SELECT approved_by FROM orders WHERE order_id=?', [$o1]) === $STAFF);
check('approve logged', (int) ord_value("SELECT COUNT(*) FROM inventory_movements WHERE order_id=? AND movement_type='reserved'", [$o1]) === 1);
check('history row', (int) ord_value("SELECT COUNT(*) FROM audit_logs WHERE entity_type='orders' AND entity_id=? AND action='order_status_changed'", [(string)$o1]) === 1);

// 2. illegal and permission checks
$r = transition_order($o1, 'paid', $STAFF);
check('approved->paid refused', !$r['ok']);
$r = transition_order($o1, 'requested', $STAFF, ['reason' => 'mistake']);
check('staff cannot revert', !$r['ok'] && st($o1) === 'approved', $r['message'] ?? '');
$r = transition_order($o1, 'requested', null, ['reason' => 'mistake']);
check('system cannot revert', !$r['ok']);

// 3. not enough stock: nothing changes
$o_short = new_order(1, 1, 'reservation', [['TER-CS-POLO-S', 2]], 'T-SHORT');
ord_q("UPDATE item_variants SET quantity_on_hand = 1 WHERE sku = 'TER-CS-POLO-S'");
$res_before = (int) var_row('TER-CS-POLO-S')['quantity_reserved'];
$r = transition_order($o_short, 'approved', $STAFF);
check('short stock refused', !$r['ok'] && st($o_short) === 'requested', $r['message'] ?? '');
check('short stock changed nothing', (int) var_row('TER-CS-POLO-S')['quantity_reserved'] === $res_before);

// 4. slip issuance: number skips used numbers, sequential, keeps on re-issue
$r = transition_order($o1, 'slip_issued', $STAFF, ['measured_by' => 2, 'pickup_date' => date('Y-m-d', strtotime('+2 days')), 'oic_manager_id' => 3, 'note' => 'Hem 1 inch']);
$no1 = ord_value('SELECT order_no FROM orders WHERE order_id=?', [$o1]);
check('slip issued', $r['ok'] === true, $r['message'] ?? '');
check('slip number sequential + free', preg_match('/^OS-\d{4}-0001$/', $no1) === 1, (string) $no1);
$r = transition_order($o1, 'approved', $MANAGER, ['reason' => 'Wrong measurements']);
check('manager revert slip->approved', $r['ok'] === true, $r['message'] ?? '');
transition_order($o1, 'slip_issued', $STAFF);
check('revert without reason refused', !transition_order($o1, 'approved', $MANAGER, [])['ok'] && st($o1) === 'slip_issued');
check('re-issue keeps number', ord_value('SELECT order_no FROM orders WHERE order_id=?', [$o1]) === $no1);

// second order gets the next number
$o2 = oid('OS-2026-0002');   // auto-approved in seed (holds stock)
transition_order($o2, 'slip_issued', $STAFF);
check('next slip number is 0002', preg_match('/-0002$/', (string) ord_value('SELECT order_no FROM orders WHERE order_id=?', [$o2])) === 1);

// 5. payment validation, then success, duplicate OR
$r = transition_order($o1, 'paid', $STAFF, ['or_no' => 'OR-7001', 'amount_paid' => '100', 'paid_date' => date('Y-m-d')]);
check('short payment refused', !$r['ok'] && st($o1) === 'slip_issued', $r['message'] ?? '');
$r = transition_order($o1, 'paid', $STAFF, ['or_no' => 'OR-0001001', 'amount_paid' => '510', 'paid_date' => date('Y-m-d')]);
check('duplicate OR refused', !$r['ok'] && st($o1) === 'slip_issued', $r['message'] ?? '');
$r = transition_order($o1, 'paid', $STAFF, ['or_no' => 'OR-7001', 'amount_paid' => '510.00', 'paid_date' => date('Y-m-d')]);
check('payment ok', $r['ok'] === true, $r['message'] ?? '');
check('payment row + claim deadline', (int) ord_value('SELECT COUNT(*) FROM payments WHERE order_id=?', [$o1]) === 1 && ord_value('SELECT claim_deadline FROM orders WHERE order_id=?', [$o1]) !== null);
check('future payment date refused', !transition_order($o2, 'paid', $STAFF, ['or_no' => 'OR-7002', 'amount_paid' => '510', 'paid_date' => date('Y-m-d', strtotime('+3 days'))])['ok']);

// 6. claim reduces on_hand and reserved together
$b = var_row('PRI-BOY-POLO-8');
$r = transition_order($o1, 'claimed', $STAFF, ['received_by' => 'Maria Santos', 'checked_by' => 2]);
$a = var_row('PRI-BOY-POLO-8');
check('claim ok', $r['ok'] === true, $r['message'] ?? '');
check('claim: on_hand -2 and reserved -2', (int)$a['quantity_on_hand'] === (int)$b['quantity_on_hand'] - 2 && (int)$a['quantity_reserved'] === (int)$b['quantity_reserved'] - 2);
check('claim stores received/checked', ord_value('SELECT received_by FROM orders WHERE order_id=?', [$o1]) === 'Maria Santos');
check('claim needs received_by', !transition_order($o2, 'claimed', $STAFF, [])['ok']);

// 7. revert claim puts stock back
$r = transition_order($o1, 'paid', $MANAGER, ['reason' => 'Claimed by mistake']);
$a2 = var_row('PRI-BOY-POLO-8');
check('manager revert claimed->paid', $r['ok'] === true, $r['message'] ?? '');
check('revert restores stock', (int)$a2['quantity_on_hand'] === (int)$b['quantity_on_hand'] && (int)$a2['quantity_reserved'] === (int)$b['quantity_reserved']);
transition_order($o1, 'claimed', $STAFF, ['received_by' => 'Maria Santos']);

// 8. decline / cancel need reasons and release stock
$o3 = new_order(2, 2, 'reservation', [['JHS-PE-SHIRT-M', 2]], 'T-DECL');
transition_order($o3, 'approved', $STAFF);
$held = (int) var_row('JHS-PE-SHIRT-M')['quantity_reserved'];
check('decline without reason refused', !transition_order($o3, 'declined', $STAFF, [])['ok'] && st($o3) === 'approved');
$r = transition_order($o3, 'declined', $STAFF, ['reason' => 'Wrong size for grade']);
check('decline releases stock', $r['ok'] && (int) var_row('JHS-PE-SHIRT-M')['quantity_reserved'] === $held - 2, $r['message'] ?? '');
check('decline reason stored for tracking', order_public_reason(ord_one('SELECT * FROM orders WHERE order_id=?', [$o3])) === 'Wrong size for grade');
$o4 = new_order(2, 2, 'reservation', [['JHS-PE-SHIRT-L', 1]], 'T-CANC');
$r = transition_order($o4, 'cancelled', $STAFF, ['reason' => 'Customer asked']);
check('cancel a requested order (no stock held)', $r['ok'] === true, $r['message'] ?? '');
check('system (customer) cancel of approved order', (function () {
    $id = new_order(2, 2, 'reservation', [['JHS-PE-SHIRT-L', 1]], 'T-SYS');
    transition_order($id, 'approved', 2);
    $r = transition_order($id, 'cancelled', null, ['reason' => 'Cancelled by customer']);
    return $r['ok'] && (int) ord_value('SELECT COALESCE(SUM(quantity_reserved),0) FROM item_variants WHERE sku="JHS-PE-SHIRT-L"') === 0;
})());
check('staff cannot cancel a paid order', !transition_order(oid('OS-2026-0004'), 'cancelled', $STAFF, ['reason' => 'test only'])['ok']);
$r = transition_order(oid('OS-2026-0004'), 'cancelled', $MANAGER, ['reason' => 'Customer withdrew']);
check('manager cancels paid order', $r['ok'] === true, $r['message'] ?? '');

// 9. expire: needs approved/slip_issued, goes to recycle bin, releases stock
$o5 = new_order(2, 2, 'reservation', [['JHS-PE-SHIRT-S', 1]], 'T-EXP');
transition_order($o5, 'approved', $STAFF);
$r = transition_order($o5, 'expired', $STAFF);
check('expire -> recycle bin', $r['ok'] && ord_value('SELECT deleted_at FROM orders WHERE order_id=?', [$o5]) !== null && ord_value('SELECT purge_after FROM orders WHERE order_id=?', [$o5]) !== null, $r['message'] ?? '');
check('deleted order cannot be changed', !transition_order($o5, 'approved', $STAFF)['ok']);

// 10. toga: second active rental for the same student/period is refused, and nothing is reserved
$toga = new_order(6, 6, 'rental', [['TOGA-BACH-L', 1]], 'T-TOGA');
$tb = (int) var_row('TOGA-BACH-L')['quantity_reserved'];
$r = transition_order($toga, 'approved', $STAFF);
check('second active toga rental refused', !$r['ok'] && st($toga) === 'requested' && (int) var_row('TOGA-BACH-L')['quantity_reserved'] === $tb, $r['message'] ?? '');

// 11. toga lifecycle for a student without a rental
$toga2 = new_order(2, 2, 'rental', [['TOGA-BACH-S', 1]], 'T-TOGA2');
check('toga approve creates rental', transition_order($toga2, 'approved', $STAFF)['ok'] && count(ord_rentals($toga2)) === 1);
$r = transition_order($toga2, 'slip_issued', $STAFF, ['deposit' => '500']);
check('toga slip stores deposit', $r['ok'] && (float) ord_rentals($toga2)[0]['deposit_amount'] === 500.0, $r['message'] ?? '');
check('deposit refused on non-rental', !transition_order(oid('OS-2026-0009'), 'slip_issued', $STAFF, ['deposit' => '100'])['ok']);
$r = transition_order($toga2, 'paid', $STAFF, ['or_no' => 'OR-7100', 'amount_paid' => '850', 'deposit_amount' => '500', 'paid_date' => date('Y-m-d')]);
check('toga paid with deposit (fee covers total)', $r['ok'] === true, $r['message'] ?? '');
$r = transition_order($toga2, 'claimed', $STAFF, ['received_by' => 'Ana Reyes']);
check('toga claim releases rental + rented_out movement', $r['ok'] && ord_rentals($toga2)[0]['status'] === 'released' && (int) ord_value("SELECT COUNT(*) FROM inventory_movements WHERE order_id=? AND movement_type='rented_out'", [$toga2]) === 1, $r['message'] ?? '');

// 12. declined -> requested revert, then approve again
$r = transition_order($o3, 'requested', $MANAGER, ['reason' => 'Declined by mistake']);
check('revert declined->requested', $r['ok'] === true, $r['message'] ?? '');
check('re-approve after revert', transition_order($o3, 'approved', $STAFF)['ok'] === true);

// 13. stock invariant: reserved always equals held lines, never above on_hand
check('reserved matches held order lines everywhere', consistent());
check('no variant has reserved > on_hand', (int) ord_value('SELECT COUNT(*) FROM item_variants WHERE quantity_reserved > quantity_on_hand') === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
