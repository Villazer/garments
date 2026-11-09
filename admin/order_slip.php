<?php
/** Printable Order Slip for staff (uses render_order_slip() from Session 4). */
require __DIR__ . '/_init.php';
$user = require_can('orders.view');
require_once AUTH_ROOT . '/includes/orders.php';
foreach (['reserve_lib.php', 'order_slip.php'] as $f) {
    if (is_file(AUTH_ROOT . '/includes/' . $f)) {
        require_once AUTH_ROOT . '/includes/' . $f;
    }
}

$id = (int) ($_GET['id'] ?? 0);
$order = $id > 0 ? ord_fetch($id) : null;
if (!$order) {
    staff_not_found();
}
// the official slip exists once it has been issued; before that there is nothing to print
if (!in_array($order['status'], ['slip_issued', 'paid', 'claimed', 'unclaimed'], true)) {
    flash_set('warning', 'The order slip is available after the slip has been issued.');
    redirect('admin/order_view.php', ['id' => $id]);
}
$main_css = config('ui.main_css', 'css/app.css');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Order slip <?= e($order['order_no']) ?></title>
  <link rel="stylesheet" href="<?= e(asset($main_css)) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/reserve.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/orders.css')) ?>">
</head>
<body class="s6-slip-page">
  <div class="s6-slip-toolbar s6-noprint">
    <a class="btn" href="<?= e(url('admin/order_view.php', ['id' => $id])) ?>">&larr; Back to order</a>
    <button type="button" class="btn btn-primary" id="s6-print">Print slip</button>
  </div>
  <?php if (function_exists('flash_show')) { flash_show(); } ?>
  <div class="slip-print">
    <?= render_order_slip($id, ['staff' => true]) ?>
  </div>
  <script>
    document.getElementById('s6-print').addEventListener('click', function () { window.print(); });
    <?php if (!empty($_GET['print'])): ?>window.addEventListener('load', function () { window.print(); });<?php endif; ?>
  </script>
</body>
</html>
