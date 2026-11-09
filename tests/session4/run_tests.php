<?php
// Run: php tests/session4/run_tests.php   (needs the wmsu_garment_shop database from schema + sample seed)
require __DIR__ . '/bootstrap_stub.php';
$pass = 0; $fail = 0;
function check(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail; if ($cond) { $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name $extra\n"; }
}
function vid(string $sku): int { return (int)res_q('SELECT variant_id FROM item_variants WHERE sku = ?', [$sku])->fetchColumn(); }
function stock(int $v): array { return res_q('SELECT quantity_on_hand oh, quantity_reserved rs FROM item_variants WHERE variant_id = ?', [$v])->fetch(); }
$buyer = ['full_name' => 'Test Buyer', 'relationship' => 'parent', 'student_no' => 'T-0001', 'student_name' => 'Test Student',
  'contact_no' => '09179990000', 'email' => 'b@example.com', 'education_level_id' => 1, 'course_id' => 0,
  'year_level' => 'Grade 2', 'address' => 'Somewhere', 'notes' => 'test'];

$polo6 = vid('PRI-BOY-POLO-6');   // auto, cap 5
$polo8 = vid('PRI-BOY-POLO-8');
$pe_s  = vid('JHS-PE-SHIRT-S');   // approval, cap 3
$toga  = vid('TOGA-BACH-M');      // rental
$slx   = vid('SHS-SLACKS-CUSTOM');// made to order, approval, no stock tracking
$blse12= vid('PRI-GIRL-BLSE-12'); // out of stock

echo "Rules\n";
$rows = res_fetch_variants([$polo6, $pe_s, $toga, $blse12, $slx]);
$ev = res_evaluate([$toga => 1], $rows);            check('toga line rejected', $ev['lines'][$toga]['kind'] === 'toga');
$ev = res_evaluate([$blse12 => 1], $rows);          check('out of stock rejected', $ev['lines'][$blse12]['kind'] === 'stock');
$ev = res_evaluate([$pe_s => 4], $rows);            check('per-request cap (3) enforced', $ev['lines'][$pe_s]['kind'] === 'limit');
$ev = res_evaluate([$polo6 => 21], $rows);          check('quantity above cap/stock rejected', in_array($ev['lines'][$polo6]['kind'], ['limit', 'stock'], true));
db()->exec("UPDATE items SET max_qty_per_request = 50 WHERE item_code='PRI-BOY-POLO'");
$ev = res_evaluate([$polo6 => 21], res_fetch_variants([$polo6])); check('quantity above stock rejected (cap lifted)', $ev['lines'][$polo6]['kind'] === 'stock');
db()->exec("UPDATE items SET max_qty_per_request = 5 WHERE item_code='PRI-BOY-POLO'");
$ev = res_evaluate([$polo6 => 2], $rows);           check('auto line ok, no approval needed', $ev['ok'] && !$ev['approval_needed']);
$ev = res_evaluate([$polo6 => 2, $pe_s => 1], $rows); check('mixed list needs approval', $ev['ok'] && $ev['approval_needed']);
$ev = res_evaluate([$slx => 1], $rows);             check('made-to-order skips stock check', $ev['ok'] && $ev['approval_needed']);
db()->exec("UPDATE items SET reservation_mode='none' WHERE item_code='JHS-PE-SHIRT'");
$ev = res_evaluate([$pe_s => 1], res_fetch_variants([$pe_s])); check('mode none = walk-in only', $ev['lines'][$pe_s]['kind'] === 'walkin');
db()->exec("UPDATE items SET reservation_mode='approval' WHERE item_code='JHS-PE-SHIRT'");

echo "Auto order\n";
$b = stock($polo6);
$r = res_submit([$polo6 => 2], $buyer);
check('auto order created', $r['ok'] && $r['status'] === 'approved', json_encode($r));
$a = stock($polo6);
check('stock reserved +2', $a['rs'] === $b['rs'] + 2 && $a['oh'] === $b['oh']);
$o = res_q('SELECT * FROM orders WHERE order_id = ?', [$r['order_id']])->fetch();
check('ref format GRM-YYYY-XXXXXX', (bool)preg_match('/^GRM-\d{4}-[A-HJ-NP-Z2-9]{6}$/', $o['order_ref']));
$hold = (int)get_setting('hold_unpaid_hours', 168) * 3600;
check('expires_at = now + hold_unpaid_hours', abs((strtotime($o['expires_at']) - time()) - $hold) < 120);
check('order_no final format', (bool)preg_match('/^OS-\d{4}-\d{6}$/', $o['order_no']), $o['order_no']);
check('total 2 x 245.00 = 490.00', $o['total_amount'] === '490.00');
check('movement logged', (int)res_q("SELECT COUNT(*) FROM inventory_movements WHERE order_id=? AND movement_type='reserved' AND reserved_delta=2", [$r['order_id']])->fetchColumn() === 1);
check('stock_held = 1', (int)res_q('SELECT stock_held FROM order_items WHERE order_id=?', [$r['order_id']])->fetchColumn() === 1);

echo "Approval order (no stock held until staff approve)\n";
$b = stock($pe_s);
$r2 = res_submit([$pe_s => 2], array_merge($buyer, ['student_no' => 'T-0002', 'contact_no' => '09179990001']));
check('requested, no expiry', $r2['ok'] && $r2['status'] === 'requested' && $r2['expires_at'] === null);
check('no stock held', stock($pe_s) === $b);
try { res_q('CALL sp_approve_order(?, 2)', [$r2['order_id']]); $approved = true; } catch (Throwable $e) { $approved = false; echo $e->getMessage(), "\n"; }
check('staff sp_approve_order works on our order', $approved && stock($pe_s)['rs'] === $b['rs'] + 2);

echo "Failure keeps everything consistent\n";
$b6 = stock($polo6); $before = (int)res_q('SELECT COUNT(*) FROM orders')->fetchColumn();
$r3 = res_submit([$polo6 => 1, $blse12 => 1], array_merge($buyer, ['student_no' => 'T-0003']));
check('submit refused, problem names the failed line', !$r3['ok'] && isset($r3['problems'][$blse12]) && !isset($r3['problems'][$polo6]));
check('no order, no stock change', (int)res_q('SELECT COUNT(*) FROM orders')->fetchColumn() === $before && stock($polo6) === $b6);

echo "Existing student record is kept\n";
$orig = res_q("SELECT full_name FROM students WHERE student_no='2022-01001'")->fetchColumn();
res_submit([$polo8 => 1], array_merge($buyer, ['student_no' => '2022-01001', 'student_name' => 'Someone Else', 'contact_no' => '09179990002']));
check('student name unchanged', res_q("SELECT full_name FROM students WHERE student_no='2022-01001'")->fetchColumn() === $orig);

echo "Race: 6 buyers, 1 item left\n";
db()->exec("UPDATE item_variants SET quantity_on_hand = quantity_reserved + 1 WHERE variant_id = $polo8");
$procs = [];
for ($i = 1; $i <= 6; $i++) {
    $procs[$i] = proc_open([PHP_BINARY, __DIR__ . '/race_worker.php', '1', (string)$polo8, (string)$i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$ok = 0; foreach ($procs as $i => $p) { $out = stream_get_contents($pipes[$i][1]); if (str_starts_with($out, 'OK')) { $ok++; } proc_close($p); }
$s = stock($polo8);
check("exactly one winner (got $ok)", $ok === 1);
check('reserved never exceeds on-hand', $s['rs'] <= $s['oh'], json_encode($s));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
