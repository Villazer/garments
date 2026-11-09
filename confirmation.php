<?php
require __DIR__ . '/includes/catalog_bootstrap.php';
require_once __DIR__ . '/includes/reserve_lib.php';
require_once __DIR__ . '/includes/order_slip.php';

$order = res_gate((string)($_GET['ref'] ?? ''), 'confirmation.php');   // exits with a verify form if not allowed

$items = res_q('SELECT item_name, size_label, quantity, unit_price, line_total FROM order_items WHERE order_id = ? ORDER BY order_item_id', [$order['order_id']])->fetchAll(PDO::FETCH_ASSOC);
$approved = $order['status'] === 'approved';

$page_title = 'Reservation received'; $nav_active = 'catalog';
require CATALOG_HEADER;
echo '<link rel="stylesheet" href="' . e(asset('css/reserve.css')) . '">';
?>
<main class="rv-wrap rv-narrow">
  <h1>Reservation received</h1>

  <div class="rv-card rv-ref">
    <span>Your reference number</span>
    <strong id="rv-ref-value"><?= e($order['order_ref']) ?></strong>
    <small>Keep this number. You need it, with your contact number, to check your request.</small>
  </div>

  <div class="rv-alert <?= $approved ? 'rv-alert-ok' : 'rv-alert-info' ?>">
    <?php if ($approved): ?>
      Your items are <strong>reserved</strong> until <strong><?= e(format_datetime($order['expires_at'])) ?></strong>.
      Unpaid reservations are released after that.
    <?php else: ?>
      Your request is <strong>waiting for staff approval</strong>. Stock is held once it is approved.
    <?php endif; ?>
  </div>

  <div class="rv-card">
    <h2>Order summary</h2>
    <div class="rv-table-wrap"><table class="rv-table">
      <thead><tr><th>Item</th><th>Size</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Total</th></tr></thead>
      <tbody><?php foreach ($items as $it): ?>
        <tr><td><?= e($it['item_name']) ?></td><td><?= e($it['size_label']) ?></td><td class="num"><?= (int)$it['quantity'] ?></td>
            <td class="num"><?= e(money($it['unit_price'])) ?></td><td class="num"><?= e(money($it['line_total'])) ?></td></tr>
      <?php endforeach; ?></tbody>
      <tfoot><tr><td colspan="4" class="num"><strong>Total</strong></td><td class="num"><strong><?= e(money($order['total_amount'])) ?></strong></td></tr></tfoot>
    </table></div>
    <p class="rv-muted">Order slip no.: <em>To be issued</em> by the Garment Shop.</p>
  </div>

  <div class="rv-card">
    <h2>What to do next</h2>
    <ol class="rv-steps">
      <li>Go to the Garment Shop and show your reference number.</li>
      <li>Receive your printed <strong>Order Slip</strong> from the staff.</li>
      <li>Pay the total at the <strong>Cashier</strong> (the shop does not take payment).</li>
      <li>Return to the Garment Shop with your proof of payment (official receipt) to claim your items.</li>
    </ol>
  </div>

  <div class="rv-actions">
    <a class="rv-btn rv-btn-primary" href="<?= e(url('slip.php', ['ref' => $order['order_ref'], 'print' => 1])) ?>" target="_blank" rel="noopener">Print order slip</a>
    <a class="rv-btn" href="<?= e(url('slip.php', ['ref' => $order['order_ref']])) ?>">View order slip</a>
    <a class="rv-btn" href="<?= e(url('catalog.php')) ?>">Back to catalog</a>
  </div>
</main>
<?php require CATALOG_FOOTER;
