<?php
/** CLI tests: php tests/session5/run_tests.php  (needs a scratch MariaDB with the project schema; see stub.php) */
require __DIR__ . '/stub.php';
$root = dirname(__DIR__, 2);
require $root . '/includes/public5_common.php';
require $root . '/includes/track_lib.php';
require $root . '/includes/toga_lib.php';
require $root . '/includes/waitlist_lib.php';
require $root . '/includes/restock_lib.php';

if (($argv[1] ?? '') === '--child') {          // race worker: try to rent the last toga
    $_SERVER['REMOTE_ADDR'] = '10.9.' . random_int(1, 200) . '.' . random_int(1, 200);
    $c = ['variant_id' => (int) $argv[3], 'student_no' => 'RACE-' . $argv[2], 'student_name' => 'Racer ' . $argv[2],
          'requester' => 'self', 'requester_name' => 'Racer ' . $argv[2], 'contact_no' => '0918000' . str_pad($argv[2], 4, '0', STR_PAD_LEFT),
          'email' => '', 'course_id' => 1, 'year_level' => '4th Year', 'agree' => true];
    $r = tog_create_request($c);
    echo $r['ok'] ? "WIN\n" : 'LOSE:' . $r['code'] . "\n";
    exit;
}

$pass = 0; $fail = 0;
function check(string $name, $cond): void { global $pass, $fail; if ($cond) { $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name\n"; } }
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

echo "refs and contact matching\n";
check('dashless GRM ref normalised', s5_clean_ref(' grm 2026 abc234 ') === 'GRM-2026-ABC234');
check('seed GS ref kept', s5_clean_ref('gs-7k4q9m2x') === 'GS-7K4Q9M2X');

echo "tracking\n";
$r = track_attempt('GS-7K4Q9M2X', '0917 111 0001');
check('correct ref + phone (spaces) ok', $r['ok'] && $r['order']['status'] === 'requested');
check('email match case-insensitive', track_attempt('gs-7k4q9m2x', 'MARIA.SANTOS@example.com')['ok']);
$bad1 = track_attempt('GS-7K4Q9M2X', '09170000000');
$bad2 = track_attempt('GS-NOSUCH99', '09171110001');
check('wrong contact and unknown ref give the same error', !$bad1['ok'] && $bad1['error'] === $bad2['error']);
check('expired (recycle bin) order is still trackable', track_attempt('GS-6P1X9JD3', '09171110007')['ok']);
$o = track_attempt('GS-6P1X9JD3', '09171110007')['order'];
$tl = track_timeline($o);
check('expired timeline ends in an exit step', end($tl)['state'] === 'exit' && end($tl)['key'] === 'expired');
check('paid order timeline: 3 done, paid current', (function () {
    $o = track_attempt('GS-5B7N1VC8', '09171110003')['order']; $t = track_timeline($o);
    return $t[3]['state'] === 'current' && $t[2]['state'] === 'done' && $t[4]['state'] === 'todo' && track_countdown($o)['seconds'] > 0;
})());
for ($i = 0; $i < 10; $i++) { track_attempt('GS-7K4Q9M2X', '09179999999'); }
$locked = track_attempt('GS-7K4Q9M2X', '09171110001');
check('after repeated failures even the right contact is locked', !$locked['ok'] && !empty($locked['locked']));
s5_exec("DELETE FROM audit_logs WHERE entity_type='rate_limit'");

echo "cancel\n";
$var = (int) db_value("SELECT variant_id FROM item_variants WHERE sku='PRI-GIRL-BLSE-8'");
$before = db_one('SELECT quantity_reserved FROM item_variants WHERE variant_id=?', [$var]);
$o2 = (int) db_value("SELECT order_id FROM orders WHERE order_ref='GS-3F8D2LQ5'");
$res = track_cancel_order($o2);
$after = db_one('SELECT quantity_reserved FROM item_variants WHERE variant_id=?', [$var]);
check('approved order cancelled', $res['ok'] && db_value('SELECT status FROM orders WHERE order_id=?', [$o2]) === 'cancelled');
check('reserved stock released by the held quantity (2)', (int) $before['quantity_reserved'] - (int) $after['quantity_reserved'] === 2);
check('inventory_movements row written', (int) db_value("SELECT COUNT(*) FROM inventory_movements WHERE order_id=? AND movement_type='released' AND reserved_delta=-2 AND reason='Cancelled by customer'", [$o2]) === 1);
check('cancel twice is refused', !track_cancel_order($o2)['ok'] && (int) db_value('SELECT quantity_reserved FROM item_variants WHERE variant_id=?', [$var]) === (int) $after['quantity_reserved']);
$o1 = (int) db_value("SELECT order_id FROM orders WHERE order_ref='GS-7K4Q9M2X'");
check('requested order cancels with no stock change', track_cancel_order($o1)['ok']);
$o3 = (int) db_value("SELECT order_id FROM orders WHERE order_ref='GS-9H2R6TW1'");
check('slip_issued cannot be cancelled online', !track_cancel_order($o3)['ok'] && db_value('SELECT status FROM orders WHERE order_id=?', [$o3]) === 'slip_issued');
check('no negative or inconsistent stock', (int) db_value('SELECT COUNT(*) FROM item_variants WHERE quantity_reserved < 0 OR quantity_reserved > quantity_on_hand') === 0);

echo "toga request\n";
$tm = (int) db_value("SELECT variant_id FROM item_variants WHERE sku='TOGA-BACH-M'");
$tl_ = (int) db_value("SELECT variant_id FROM item_variants WHERE sku='TOGA-BACH-L'");
function toga_in($no, $variant, $contact = '09180000001') {
    return ['variant_id' => $variant, 'student_no' => $no, 'student_name' => 'Test Student ' . $no, 'requester' => 'self', 'requester_name' => '',
            'contact_no' => $contact, 'email' => '', 'course_id' => '1', 'year_level' => '4th Year', 'agree' => '1'];
}
[$c, $err] = tog_validate(toga_in('2024-0001', $tm), [1, 2, 3], [$tm, $tl_]);
check('validation passes and normalises phone', !$err && $c['contact_no'] === '09180000001');
[, $err2] = tog_validate(array_merge(toga_in('x', $tm, '123'), ['agree' => '']), [2], [$tm]);
check('bad student no, phone, course and missing consent are all reported', isset($err2['student_no'], $err2['contact_no'], $err2['course_id'], $err2['agree']));

$res = tog_create_request($c);
check('approval-mode request created as requested', $res['ok'] && $res['status'] === 'requested' && preg_match('/^GRM-\d{4}-[A-Z0-9]{6}$/', $res['ref']) === 1);
$ord = db_one('SELECT * FROM orders WHERE order_id=?', [$res['order_id']]);
check('order_no replaced by OS-YYYY-000nnn, type rental, source online', preg_match('/^OS-\d{4}-\d{6}$/', $ord['order_no']) === 1 && $ord['order_type'] === 'rental' && $ord['source'] === 'online');
check('no stock held and no rentals row yet', (int) db_value('SELECT stock_held FROM order_items WHERE order_id=?', [$res['order_id']]) === 0
    && (int) db_value('SELECT COUNT(*) FROM rentals WHERE student_id=?', [$ord['student_id']]) === 0);
$again = tog_create_request($c);
check('same student, same period: second request blocked (code 2)', !$again['ok'] && $again['code'] === 2);
$seed2 = tog_create_request(array_merge(toga_in('2021-00555', $tm), ['contact_no' => '09180000003']));
check('seed student Grace Lim (released rental) is blocked', !$seed2['ok'] && $seed2['code'] === 2);
check('cancelling the pending request frees the slot', track_cancel_order($res['order_id'])['ok'] && tog_create_request($c)['ok']);

s5_exec("UPDATE items SET reservation_mode='auto' WHERE item_id=6");
$mb = db_one('SELECT quantity_reserved FROM item_variants WHERE variant_id=?', [$tm]);
$auto = tog_create_request(array_merge(toga_in('2024-0002', $tm), ['contact_no' => '09180000004']));
$mb2 = db_one('SELECT quantity_reserved FROM item_variants WHERE variant_id=?', [$tm]);
check('auto mode: approved, stock held, expires_at set', $auto['ok'] && $auto['status'] === 'approved' && $auto['expires_at'] !== null && (int) $mb2['quantity_reserved'] === (int) $mb['quantity_reserved'] + 1);
check('auto mode: rentals row reserved with due date = period end', db_value("SELECT COUNT(*) FROM rentals r JOIN order_items oi ON oi.order_item_id=r.order_item_id WHERE oi.order_id=? AND r.status='reserved' AND r.due_date='2026-12-18'", [$auto['order_id']]) == 1);
check('auto mode: reserved movement logged', (int) db_value("SELECT COUNT(*) FROM inventory_movements WHERE order_id=? AND movement_type='reserved'", [$auto['order_id']]) === 1);
$dup_auto = tog_create_request(array_merge(toga_in('2024-0002', $tl_), ['contact_no' => '09180000004']));
check('auto mode: second rental for same student blocked', !$dup_auto['ok'] && $dup_auto['code'] === 2);
check('cancel releases stock and cancels the rentals row', track_cancel_order($auto['order_id'])['ok']
    && (int) db_value('SELECT quantity_reserved FROM item_variants WHERE variant_id=?', [$tm]) === (int) $mb['quantity_reserved']
    && (int) db_value("SELECT COUNT(*) FROM rentals r JOIN order_items oi ON oi.order_item_id=r.order_item_id WHERE oi.order_id=? AND r.status='cancelled'", [$auto['order_id']]) === 1);
s5_exec("UPDATE items SET reservation_mode='approval' WHERE item_id=6");

s5_exec('UPDATE item_variants SET quantity_on_hand = quantity_reserved WHERE variant_id = ?', [$tl_]);
$oos = tog_create_request(array_merge(toga_in('2024-0003', $tl_), ['contact_no' => '09180000005']));
check('out of stock size refused (code 1)', !$oos['ok'] && $oos['code'] === 1);

echo "race for the last toga (6 processes)\n";
s5_exec('UPDATE item_variants SET quantity_on_hand = quantity_reserved + 1 WHERE variant_id = ?', [$tl_]);
s5_exec("UPDATE items SET reservation_mode='auto' WHERE item_id=6");
$procs = [];
for ($i = 1; $i <= 6; $i++) {
    $procs[] = proc_open([PHP_BINARY, __FILE__, '--child', (string) $i, (string) $tl_], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$wins = 0; $losses = 0;
foreach ($procs as $i => $p) { $out = stream_get_contents($pipes[$i + 1][1]); proc_close($p); if (strpos($out, 'WIN') !== false) $wins++; else $losses++; }
$vr = db_one('SELECT quantity_on_hand, quantity_reserved FROM item_variants WHERE variant_id=?', [$tl_]);
check("exactly one winner ($wins won, $losses lost)", $wins === 1 && $losses === 5);
check('reserved never above on-hand', (int) $vr['quantity_reserved'] <= (int) $vr['quantity_on_hand'] && (int) $vr['quantity_reserved'] === (int) $vr['quantity_on_hand']);
s5_exec("UPDATE items SET reservation_mode='approval' WHERE item_id=6");

echo "waitlist\n";
$blouse12 = (int) db_value("SELECT variant_id FROM item_variants WHERE sku='PRI-GIRL-BLSE-12'");
$v = wl_find_variant($blouse12);
check('out-of-stock variant is joinable', $v !== null && wl_is_joinable($v));
[$wc, $werr] = wl_validate(['contact_name' => 'Ben Cruz', 'contact_no' => '0917 222 3333', 'email' => 'ben@example.com', 'quantity' => '2'], $v);
check('waitlist validation ok (migration 003 applied)', !$werr && $wc['quantity'] === 2 && s5_waitlist_has_quantity());
check('first join creates a row', wl_join($blouse12, $wc) === ['ok' => true, 'duplicate' => false]);
$wc2 = $wc; $wc2['quantity'] = 3; $wc2['contact_name'] = 'Benjamin Cruz';
check('same phone again = duplicate, quantity updated, still one row', wl_join($blouse12, $wc2)['duplicate'] === true
    && (int) db_value('SELECT COUNT(*) FROM waitlist WHERE variant_id=? AND contact_no=?', [$blouse12, '09172223333']) === 1
    && (int) db_value('SELECT quantity_wanted FROM waitlist WHERE variant_id=? AND contact_no=?', [$blouse12, '09172223333']) === 3);
$wc3 = $wc; $wc3['contact_no'] = '09175556666';
check('same email with a different phone is also a duplicate', wl_join($blouse12, $wc3)['duplicate'] === true);
$wc4 = $wc; $wc4['contact_no'] = '09177778888'; $wc4['email'] = '';
check('a different person is added', wl_join($blouse12, $wc4)['duplicate'] === false);
$instock = (int) db_value("SELECT variant_id FROM item_variants WHERE sku='PRI-BOY-POLO-6'");
check('in-stock size refused', (wl_join($instock, $wc)['kind'] ?? '') === 'in_stock');
check('made-to-order variant is not joinable', !wl_is_joinable(wl_find_variant((int) db_value("SELECT variant_id FROM item_variants WHERE sku='SHS-SLACKS-CUSTOM'"))));
check('picker lists out-of-stock sizes', count(wl_out_of_stock_list()) >= 1);

echo "restock\n";
$rs = rs_upcoming();
$all = array_merge(...array_values($rs['dated'] ?: [[]]), ...[$rs['unconfirmed']]);
check('seed schedule rows appear (variant row and whole-item row)', count($all) >= 2 && in_array('All sizes', array_map('rs_size_text', $all), true));
check('internal note and quantity are not selected', !array_key_exists('note', $all[0]) && !array_key_exists('expected_qty', $all[0]));
check('out-of-stock row offers the waitlist', (function () use ($all) { foreach ($all as $r) { if ($r['size_label'] === '12') return rs_can_waitlist($r); } return false; })());

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
