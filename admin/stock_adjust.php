<?php
/** Receive stock, correct a count, or record damage/loss for one size. */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/inventory.php';

$user = require_can('inventory.count');
$variant_id = (int) ($_GET['variant'] ?? $_POST['variant_id'] ?? 0);
$v = $variant_id > 0 ? inv_variant_find($variant_id) : null;
if (!$v) {
    staff_not_found();
}
$problem = inv_migration_problem();
$error = null;
$form = ['type' => 'receive', 'qty' => '', 'reason' => ''];

if ($problem === null && is_post()) {
    csrf_require();
    $form = ['type' => (string) ($_POST['type'] ?? ''), 'qty' => trim((string) ($_POST['qty'] ?? '')), 'reason' => (string) ($_POST['reason'] ?? '')];
    $qty = inv_int($form['qty'], 0, INV_MAX_QTY);
    if ($qty === null) {
        $error = 'Enter a whole number for the quantity (0 to ' . INV_MAX_QTY . ').';
    } else {
        $res = inv_adjust_stock($variant_id, $form['type'], $qty, $form['reason'], (int) $user['user_id']);
        if ($res['ok']) {
            flash_set('success', $res['message']);
            redirect('admin/inventory.php', ['q' => $v['sku']]);
        }
        $error = $res['message'];
    }
    $v = inv_variant_find($variant_id);   // numbers may have changed
}

$page_title = 'Adjust stock';
$nav_active = 'inventory';
require AUTH_ROOT . '/includes/staff_header.php';
echo inv_subnav('overview');
if (inv_migration_block()) {
    require AUTH_ROOT . '/includes/staff_footer.php';
    return;
}
$tracks = (int) $v['tracks_stock'] === 1;
?>
<h1>Adjust stock</h1>
<p><strong><?= e($v['item_name']) ?></strong>, size <?= e($v['size_label']) ?> <span class="inv-muted">(<?= e($v['sku']) ?>)</span></p>

<div class="inv-summary">
  <div class="inv-card"><b><?= (int) $v['quantity_on_hand'] ?></b><span>On hand</span></div>
  <div class="inv-card"><b><?= (int) $v['quantity_reserved'] ?></b><span>Reserved</span></div>
  <div class="inv-card"><b><?= (int) $v['available'] ?></b><span>Available</span></div>
</div>

<?php if (!$tracks): ?>
  <div class="inv-alert inv-alert-info">This item is made to order or a service, so it has no stock count.</div>
<?php else: ?>
  <?php if ($error): ?><div class="inv-alert inv-alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form class="inv-form" method="post" action="<?= e(url('admin/stock_adjust.php', ['variant' => $variant_id])) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="variant_id" value="<?= $variant_id ?>">
    <div class="inv-field">
      <label>What happened?</label>
      <?php foreach (inv_adjust_types() as $k => $l): ?>
        <label class="inv-radio"><input type="radio" name="type" value="<?= e($k) ?>"<?= $form['type'] === $k ? ' checked' : '' ?>><?= e($l) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="inv-field">
      <label for="qty">Quantity</label>
      <input id="qty" type="number" name="qty" min="0" max="<?= INV_MAX_QTY ?>" step="1" required value="<?= e($form['qty']) ?>">
      <p class="inv-hint">For a count correction, enter the total number you physically counted (not the difference).</p>
    </div>
    <div class="inv-field">
      <label for="reason">Reason (required)</label>
      <input id="reason" type="text" name="reason" maxlength="<?= INV_REASON_MAX ?>" required value="<?= e($form['reason']) ?>" placeholder="e.g. Supplier delivery DR-1043, torn in storage, shelf count">
      <p class="inv-hint">Saved in the movement history with your name. On hand can never go below reserved (<?= (int) $v['quantity_reserved'] ?>).</p>
    </div>
    <button class="inv-btn" type="submit">Save adjustment</button>
    <a class="inv-btn inv-btn-light" href="<?= e(url('admin/inventory.php')) ?>">Cancel</a>
  </form>
<?php endif; ?>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
