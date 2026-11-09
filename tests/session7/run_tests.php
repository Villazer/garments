<?php
/** php tests/session7/run_tests.php  (scratch DB wmsu_s7_test with schema + seed + migration 005) */
require __DIR__ . '/stub.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name $detail\n"; }
}
function variant(string $sku): array { return inv_one('SELECT * FROM item_variants WHERE sku = ?', [$sku]); }
function movements(int $vid): int { return (int) inv_value('SELECT COUNT(*) FROM inventory_movements WHERE variant_id = ?', [$vid]); }

check('migration 005 applied', inv_migration_problem() === null, (string) inv_migration_problem());

echo "Stock adjustments\n";
$v = variant('PRI-BOY-POLO-10'); $vid = (int) $v['variant_id']; $m0 = movements($vid);
$r = inv_adjust_stock($vid, 'receive', 5, 'Test delivery', 2);
check('receive +5', $r['ok'] && variant('PRI-BOY-POLO-10')['quantity_on_hand'] == $v['quantity_on_hand'] + 5);
check('receive wrote a movement', movements($vid) === $m0 + 1);
$mv = inv_one('SELECT * FROM inventory_movements WHERE variant_id = ? ORDER BY movement_id DESC LIMIT 1', [$vid]);
check('movement has user, type restock, delta', (int) $mv['user_id'] === 2 && $mv['movement_type'] === 'restock' && (int) $mv['on_hand_delta'] === 5);
$r = inv_adjust_stock($vid, 'damage', 1, 'torn', 2);
check('damage -1 is an adjustment', $r['ok'] && inv_one('SELECT movement_type, on_hand_delta FROM inventory_movements WHERE variant_id=? ORDER BY movement_id DESC LIMIT 1', [$vid])['movement_type'] === 'adjustment');
$cur = (int) variant('PRI-BOY-POLO-10')['quantity_on_hand'];
$r = inv_adjust_stock($vid, 'count', $cur, 'shelf count', 2);
check('count equal to system is refused, no movement', !$r['ok'] && movements($vid) === $m0 + 2);
$r = inv_adjust_stock($vid, 'count', $cur - 2, 'shelf count', 2);
check('count correction down by 2', $r['ok'] && (int) variant('PRI-BOY-POLO-10')['quantity_on_hand'] === $cur - 2);
check('reason required', !inv_adjust_stock($vid, 'receive', 1, '  ', 2)['ok']);
check('zero qty refused for receive', !inv_adjust_stock($vid, 'receive', 0, 'x y z', 2)['ok']);
check('removing more than on hand refused', !inv_adjust_stock($vid, 'loss', 9999, 'lost', 2)['ok']);

// reserved protection: O3 holds 2 x JHS-PE-SHIRT-S (10 on hand, 3 reserved with O6 expired -> check actual)
$pe = variant('JHS-PE-SHIRT-S'); $peid = (int) $pe['variant_id'];
$res = (int) $pe['quantity_reserved']; $oh = (int) $pe['quantity_on_hand'];
check('seed has reserved stock to test with', $res > 0, "reserved=$res");
$before = movements($peid);
$r = inv_adjust_stock($peid, 'loss', $oh - $res + 1, 'would go below reserved', 2);
check('adjustment below reserved is blocked with a clear message', !$r['ok'] && stripos($r['message'], 'reserved') !== false, $r['message']);
check('blocked adjustment changed nothing', (int) variant('JHS-PE-SHIRT-S')['quantity_on_hand'] === $oh && movements($peid) === $before);
$r = inv_adjust_stock($peid, 'count', $res - 1, 'recount below reserved', 2);
check('count below reserved is blocked', !$r['ok']);
$r = inv_adjust_stock($peid, 'loss', $oh - $res, 'down to exactly reserved', 2);
check('removing down to exactly reserved is allowed', $r['ok'] && (int) variant('JHS-PE-SHIRT-S')['quantity_on_hand'] === $res);
inv_adjust_stock($peid, 'receive', $oh - $res, 'put back', 2);

$slacks = variant('SHS-SLACKS-CUSTOM');
check('untracked (made to order) item refuses stock changes', !inv_adjust_stock((int) $slacks['variant_id'], 'receive', 1, 'nope nope', 2)['ok']);

echo "Waitlist notice\n";
$blouse12 = variant('PRI-GIRL-BLSE-12'); $bid = (int) $blouse12['variant_id'];
$_SESSION = [];
inv_adjust_stock($bid, 'receive', 3, 'delivery', 2);
check('receiving stock for a waitlisted size sets a notice', !empty($_SESSION['inv_waitlist_notices'][$bid]));
check('notice renders prominently and is cleared', strpos(inv_render_notices(), 'inv-alert-waitlist') !== false && empty($_SESSION['inv_waitlist_notices']));
$_SESSION = [];
inv_adjust_stock((int) variant('PRI-BOY-POLO-6')['variant_id'], 'receive', 1, 'delivery', 2);
check('no notice when nobody is waiting', empty($_SESSION['inv_waitlist_notices']));

echo "Waitlist management\n";
$wid = (int) inv_value('SELECT waitlist_id FROM waitlist LIMIT 1');
check('waiting -> contacted', inv_waitlist_change($wid, 'contacted', 'called, no answer', 2)['ok']);
$w = inv_one('SELECT * FROM waitlist WHERE waitlist_id = ?', [$wid]);
check('contacted sets time, user and note', $w['status'] === 'contacted' && $w['notified_at'] && (int) $w['contacted_by'] === 2 && $w['staff_note'] === 'called, no answer');
check('contacted -> fulfilled', inv_waitlist_change($wid, 'fulfilled', '', 2)['ok']);
$w = inv_one('SELECT * FROM waitlist WHERE waitlist_id = ?', [$wid]);
check('fulfilled sets closed_at and keeps note', $w['closed_at'] && $w['staff_note'] === 'called, no answer');
check('fulfilled -> contacted refused', !inv_waitlist_change($wid, 'contacted', '', 2)['ok']);
check('reopen works', inv_waitlist_change($wid, 'waiting', '', 2)['ok'] && inv_one('SELECT status, notified_at FROM waitlist WHERE waitlist_id=?', [$wid])['notified_at'] === null);
check('waitlist rows query runs', count(inv_waitlist_rows(inv_waitlist_filters([]))) >= 1);

echo "Catalog\n";
$cat = (int) inv_value('SELECT category_id FROM categories LIMIT 1');
[$c, $err] = inv_item_validate(['name' => 'Test Cap', 'item_code' => 'tst-cap', 'item_type' => 'ready_made', 'category_id' => $cat,
    'reservation_mode' => 'auto', 'max_qty_per_request' => '4', 'is_active' => '1'], null);
check('item validates', !$err, json_encode($err));
$iid = inv_item_create($c, 2);
check('item created with uppercase code and tracks_stock', inv_item_find($iid)['item_code'] === 'TST-CAP' && (int) inv_item_find($iid)['tracks_stock'] === 1);
[, $err] = inv_item_validate(['name' => 'Dup', 'item_code' => 'TST-CAP', 'item_type' => 'ready_made', 'category_id' => $cat, 'reservation_mode' => 'approval', 'max_qty_per_request' => '1'], null);
check('duplicate item code rejected', isset($err['item_code']));
[, $err] = inv_item_validate(['name' => 'Toga X', 'item_code' => 'TOGA-X', 'item_type' => 'rental', 'category_id' => $cat, 'reservation_mode' => 'auto', 'max_qty_per_request' => '3'], null);
check('auto-approve rejected for rentals', isset($err['reservation_mode']));
[$c2] = inv_item_validate(['name' => 'Toga Y', 'item_code' => 'TOGA-Y', 'item_type' => 'rental', 'category_id' => $cat, 'reservation_mode' => 'approval', 'max_qty_per_request' => '5'], null);
check('rental max qty forced to 1', $c2['max_qty_per_request'] === 1);

$item = inv_item_find($iid);
[$vc, $verr] = inv_variant_validate(['size_label' => 'M', 'sku' => '', 'unit_price' => '₱1,250.5', 'low_stock_threshold' => '2'], $item, 0);
check('variant validates, SKU generated, price parsed', !$verr && $vc['sku'] === 'TST-CAP-M' && $vc['unit_price'] === '1250.50', json_encode([$vc, $verr]));
$nv = inv_variant_create($item, $vc, 7, 2);
check('opening stock recorded with movement', (int) variant('TST-CAP-M')['quantity_on_hand'] === 7 && movements($nv) === 1);
[, $verr] = inv_variant_validate(['size_label' => 'M', 'sku' => 'X-1', 'unit_price' => '1', 'low_stock_threshold' => '1'], $item, 0);
check('duplicate size label rejected', count($verr) === 1);
[, $verr] = inv_variant_validate(['size_label' => 'L', 'sku' => 'tst-cap-m', 'unit_price' => '1', 'low_stock_threshold' => '1'], $item, 0);
check('duplicate SKU rejected', count($verr) === 1);
[, $verr] = inv_variant_validate(['size_label' => 'L', 'sku' => 'TST-CAP-L', 'unit_price' => 'abc', 'low_stock_threshold' => '1'], $item, 0);
check('bad price rejected', count($verr) === 1);

// price change leaves existing order lines alone
$pv = variant('PRI-BOY-POLO-8'); $oi_before = inv_all('SELECT unit_price FROM order_items WHERE variant_id = ?', [(int) $pv['variant_id']]);
$vc = ['size_label' => $pv['size_label'], 'sku' => $pv['sku'], 'unit_price' => '999.00', 'low_stock_threshold' => $pv['low_stock_threshold']];
check('price change reported', inv_variant_update((int) $pv['variant_id'], $vc, 2) === true);
check('order snapshot untouched by price change', inv_all('SELECT unit_price FROM order_items WHERE variant_id = ?', [(int) $pv['variant_id']]) === $oi_before);
check('price change audited', (int) inv_value("SELECT COUNT(*) FROM audit_logs WHERE action='variant_price_changed'") === 1);

check('variant with reserved stock cannot be deactivated', (function () use ($peid) {
    try { inv_variant_set_active($peid, false, 2); return false; } catch (DomainException $e) { return true; }
})());
inv_variant_set_active($nv, false, 2);
check('unreserved variant can be deactivated', (int) variant('TST-CAP-M')['is_active'] === 0);

check('item with order history has history flag', inv_item_has_history(1));
check('item with history cannot be deleted', (function () {
    try { inv_item_delete(1, 2); return false; } catch (DomainException $e) { return true; }
})());
check('archive keeps the item', inv_item_set_active(1, false, 2) >= 0 && (int) inv_item_find(1)['is_active'] === 0);
inv_item_set_active(1, true, 2);
check('item with stock movement from opening stock is not deletable', inv_item_has_history($iid));
$iid2 = inv_item_create(inv_item_validate(['name' => 'Scratch', 'item_code' => 'SCRATCH-1', 'item_type' => 'ready_made', 'category_id' => $cat, 'reservation_mode' => 'approval', 'max_qty_per_request' => '1'], null)[0], 2);
check('untouched item can be deleted', (function () use ($iid2) { inv_item_delete($iid2, 2); return inv_item_find($iid2) === null; })());

echo "Image upload validation\n";
$tmp = tempnam(sys_get_temp_dir(), 'img'); file_put_contents($tmp, '<?php echo 1;');
$r = (function () use ($tmp) { try { inv_image_validate_upload(['error' => 0, 'tmp_name' => $tmp, 'size' => 13, 'name' => 'x.jpg']); return 'accepted'; } catch (DomainException $e) { return $e->getMessage(); } })();
check('non-image rejected (is_uploaded_file also fails in CLI)', $r !== 'accepted', $r);
check('no file selected message', (function () { try { inv_image_validate_upload(['error' => UPLOAD_ERR_NO_FILE]); } catch (DomainException $e) { return strpos($e->getMessage(), 'Choose') === 0; } return false; })());
check('image config detected', inv_image_cfg() === null || is_array(inv_image_cfg()));
unlink($tmp);

echo "Restock\n";
$cap = inv_item_find($iid);
inv_variant_set_active($nv, true, 2);
[$vcl] = inv_variant_validate(['size_label' => 'L', 'sku' => '', 'unit_price' => '1300', 'low_stock_threshold' => '2'], $cap, 0);
$lv = inv_variant_create($cap, $vcl, 0, 2);
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$r = inv_restock_create($iid, ['expected_date' => $tomorrow, 'note' => 'PO-77', 'size_qty' => [$nv => '10', $lv => '4']], 2);
check('restock creates one row per size', $r['ok'] && $r['count'] === 2, json_encode($r));
check('past date refused', !inv_restock_create($iid, ['expected_date' => '2020-01-01', 'size_qty' => [$nv => '1']], 2)['ok']);
check('nothing entered refused', !inv_restock_create($iid, ['expected_date' => $tomorrow], 2)['ok']);
$rid = (int) inv_value('SELECT restock_id FROM restock_schedule WHERE variant_id = ? ORDER BY restock_id DESC LIMIT 1', [$nv]);
check('edit marks delayed', inv_restock_update($rid, ['expected_date' => date('Y-m-d', strtotime('+5 day')), 'expected_qty' => '12', 'status' => 'delayed', 'note' => 'late'], 2)['ok']);
check('open rows list', count(inv_restock_rows('upcoming')) >= 3);
$before = (int) variant('TST-CAP-M')['quantity_on_hand'];
$r = inv_restock_receive($rid, [$nv => 12], true, 2);
check('receive applies stock and marks delivered', $r['ok'] && (int) variant('TST-CAP-M')['quantity_on_hand'] === $before + 12 && inv_restock_find($rid)['status'] === 'delivered', json_encode($r));
check('restock movement written with reference', (int) inv_value("SELECT COUNT(*) FROM inventory_movements WHERE variant_id=? AND reason LIKE ?", [$nv, '%Restock #' . $rid . '%']) === 1);
$r = inv_restock_receive($rid, [$nv => 12], true, 2);
check('double receive blocked, stock unchanged', !$r['ok'] && (int) variant('TST-CAP-M')['quantity_on_hand'] === $before + 12);
check('delivered entry cannot be cancelled', !inv_restock_cancel($rid, 2)['ok']);
$rid2 = (int) inv_value('SELECT restock_id FROM restock_schedule WHERE variant_id = ? ORDER BY restock_id DESC LIMIT 1', [$lv]);
check('cancel open entry', inv_restock_cancel($rid2, 2)['ok'] && inv_restock_find($rid2)['status'] === 'cancelled');
check('cancelled entry cannot be received', !inv_restock_receive($rid2, [$lv => 1], true, 2)['ok']);
// item-level entry received across sizes in one step
inv_restock_create($iid, ['expected_date' => $tomorrow, 'whole_item' => '1', 'whole_qty' => '9'], 2);
$rid3 = (int) inv_value('SELECT restock_id FROM restock_schedule WHERE item_id = ? ORDER BY restock_id DESC LIMIT 1', [$iid]);
$bm = (int) variant('TST-CAP-M')['quantity_on_hand']; $bl = (int) variant('TST-CAP-L')['quantity_on_hand'];
$r = inv_restock_receive($rid3, [$nv => 4, $lv => 5], true, 2);
check('whole-item entry received across sizes', $r['ok'] && (int) variant('TST-CAP-M')['quantity_on_hand'] === $bm + 4 && (int) variant('TST-CAP-L')['quantity_on_hand'] === $bl + 5);
inv_restock_create($iid, ['expected_date' => $tomorrow, 'size_qty' => [$nv => '3']], 2);
$rid4 = (int) inv_value('SELECT restock_id FROM restock_schedule WHERE variant_id = ? ORDER BY restock_id DESC LIMIT 1', [$nv]);
$other = (int) variant('PRI-BOY-POLO-6')['variant_id'];
check('quantity for a foreign size refused and nothing changes', !inv_restock_receive($rid4, [$other => 3], true, 2)['ok'] && inv_restock_find($rid4)['status'] === 'scheduled');
check('apply with no quantities refused', !inv_restock_receive($rid4, [], true, 2)['ok']);
check('mark delivered without stock change', inv_restock_receive($rid4, [], false, 2)['ok'] && inv_restock_find($rid4)['status'] === 'delivered');

echo "Overview, CSV, movements\n";
$f = inv_overview_filters([]);
check('overview rows', inv_overview_count($f) > 10 && count(inv_overview_rows($f, 0, 50)) > 10);
foreach (['attention', 'in', 'low', 'out', 'untracked'] as $s) {
    $fs = inv_overview_filters(['status' => $s]);
    check("status filter $s runs", is_int(inv_overview_count($fs)));
}
inv_adjust_stock((int) variant('PRI-GIRL-BLSE-12')['variant_id'], 'count', 0, 'zero it for the out filter', 2);
check('out filter finds a sold-out size', count(array_filter(inv_overview_rows(inv_overview_filters(['status' => 'out']), 0), fn($r) => $r['sku'] === 'PRI-GIRL-BLSE-12')) === 1);
$out = inv_overview_rows(inv_overview_filters(['status' => 'out', 'q' => 'SHS']), 0);
check('untracked items are never flagged out', count($out) === 0);
check('summary runs', isset(inv_overview_summary()['sizes']));
check('csv cell neutralises formulas', inv_csv_cell('=1+1') === "'=1+1" && inv_csv_cell('Polo') === 'Polo' && inv_csv_cell('-5') === '-5');
ob_start(); @inv_export_csv(inv_overview_filters([])); $csv = ob_get_clean();
check('csv export has header and rows', substr_count($csv, "\n") > 10 && strpos($csv, 'Item code') !== false);
$mf = inv_movement_filters(['item' => '1', 'type' => 'restock', 'from' => '2020-01-01', 'to' => date('Y-m-d')]);
check('movement filters run', inv_movement_count($mf) >= 1 && count(inv_movement_rows($mf, 0, 10)) >= 1);
check('movement rows link orders', (function () {
    foreach (inv_movement_rows(inv_movement_filters([]), 0, 200) as $r) { if ($r['order_id']) { return $r['order_no'] !== null; } }
    return false; })());
check('invalid movement type ignored', inv_movement_filters(['type' => "x'; DROP"])['type'] === '');

echo "Invariant\n";
check('no variant has reserved > on hand', (int) inv_value('SELECT COUNT(*) FROM item_variants WHERE quantity_reserved > quantity_on_hand') === 0);
$bad = (int) inv_value("SELECT COUNT(*) FROM (SELECT v.variant_id, v.quantity_on_hand - COALESCE(SUM(m.on_hand_delta),0) AS diff FROM item_variants v LEFT JOIN inventory_movements m ON m.variant_id = v.variant_id GROUP BY v.variant_id, v.quantity_on_hand HAVING diff <> 0) x");
check('on hand always equals the sum of its movement rows', $bad === 0, "mismatches=$bad");

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
