<?php
require __DIR__ . '/includes/catalog_bootstrap.php';
require_once __DIR__ . '/includes/reserve_lib.php';
require_once __DIR__ . '/includes/order_slip.php';

$order = res_gate((string)($_GET['ref'] ?? ''), 'slip.php');
$autoprint = !empty($_GET['print']);

$page_title = 'Order slip'; $nav_active = 'catalog';
require CATALOG_HEADER;
echo '<link rel="stylesheet" href="' . e(asset('css/reserve.css')) . '">';
?>
<main class="rv-wrap rv-narrow" <?= $autoprint ? 'data-autoprint="1"' : '' ?>>
  <div class="rv-actions no-print">
    <button class="rv-btn rv-btn-primary" type="button" data-print>Print</button>
    <a class="rv-btn" href="<?= e(url('confirmation.php', ['ref' => $order['order_ref']])) ?>">Back</a>
  </div>
  <div class="slip-print"><?= render_order_slip((int)$order['order_id']) ?></div>
  <p class="rv-muted no-print">The official order slip number is written by the staff when the slip is issued.</p>
</main>
<script src="<?= e(asset('js/reserve.js')) ?>"></script>
<?php require CATALOG_FOOTER;
