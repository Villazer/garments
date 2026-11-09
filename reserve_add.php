<?php
// reserve_add.php - replaces the Session 3 stub. POST only; adds one variant to the reservation list.
require __DIR__ . '/includes/catalog_bootstrap.php';
require_once __DIR__ . '/includes/reserve_lib.php';

if (!is_post()) { redirect('catalog.php'); }
csrf_require();

// Accepts the field names the Session 3 forms may use.
$variant = (int)($_POST['variant_id'] ?? $_POST['variant'] ?? 0);
$qty     = (int)($_POST['qty'] ?? $_POST['quantity'] ?? 1);

$res = res_add($variant, $qty);
if ($res['ok']) {
    flash_set('success', $res['message']);
    redirect('reserve.php');
}
if ($res['kind'] === 'toga') {
    flash_set('info', 'Togas are requested on the Toga Rental page, not the reservation list.');
    redirect('toga_request.php', ['variant' => $variant]);
}
flash_set('error', $res['message']);
// go back to the item page when we can, otherwise to the list
$row = $variant > 0 ? res_q('SELECT item_id FROM item_variants WHERE variant_id = ?', [$variant])->fetchColumn() : false;
if ($row) { redirect('item.php', ['id' => (int)$row]); }
redirect('reserve.php');
