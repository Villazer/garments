<?php
require __DIR__ . '/includes/catalog_bootstrap.php';
require_once __DIR__ . '/includes/reserve_lib.php';
res_session_start();

if (is_post()) {
    csrf_require();
    if (isset($_POST['remove'])) {
        res_remove((int)$_POST['remove']);
        flash_set('info', 'Item removed from your list.');
    } elseif (($_POST['action'] ?? '') === 'empty') {
        res_empty();
        flash_set('info', 'Your reservation list is now empty.');
    } elseif (($_POST['action'] ?? '') === 'update') {
        $errors = res_update_quantities((array)($_POST['qty'] ?? []));
        if ($errors) { flash_set('error', implode(' ', $errors)); }
        else { flash_set('success', 'Quantities updated.'); }
    }
    redirect('reserve.php');
}

$failed = $_SESSION['res_problems'] ?? [];      // set by checkout.php when a submit failed
unset($_SESSION['res_problems']);

$ev    = res_evaluate_current();
$lines = $ev['lines'];

$page_title = 'My reservation list'; $nav_active = 'catalog';
require CATALOG_HEADER;
echo catalog_asset_tags();
echo '<link rel="stylesheet" href="' . e(asset('css/reserve.css')) . '">';
?>
<main class="rv-wrap">
  <h1>My reservation list</h1>

  <?php if ($failed): ?>
    <div class="rv-alert rv-alert-error" role="alert">
      <strong>Your request was not submitted.</strong> These lines could not be reserved:
      <ul><?php foreach ($failed as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?></ul>
      The rest of your list was kept. Fix or remove the lines above, then try again.
    </div>
  <?php endif; ?>

  <?php if (!$lines): ?>
    <div class="rv-card rv-empty">
      <p>Your list is empty.</p>
      <a class="rv-btn rv-btn-primary" href="<?= e(url('catalog.php')) ?>">Browse the catalog</a>
    </div>
  <?php else: ?>
    <form method="post" action="<?= e(url('reserve.php')) ?>" data-once>
      <?= csrf_field() ?>
      <div class="rv-table-wrap">
        <table class="rv-table">
          <thead><tr><th>Item</th><th>Size</th><th class="num">Unit price</th><th>Qty</th><th class="num">Total</th><th><span class="rv-sr">Remove</span></th></tr></thead>
          <tbody>
          <?php foreach ($lines as $vid => $l): ?>
            <tr class="<?= $l['error'] ? 'rv-row-bad' : '' ?>">
              <td data-label="Item">
                <?php if ($l['item_id']): ?><a href="<?= e(catalog_item_url($l['item_id'])) ?>"><?= e($l['item_name']) ?></a><?php else: ?><?= e($l['item_name']) ?><?php endif; ?>
                <?php if (!$l['error']): ?>
                  <small class="rv-tag"><?= $l['mode'] === 'auto' ? 'Reserved right away' : 'Needs staff approval' ?></small>
                <?php else: ?>
                  <small class="rv-err" role="alert"><?= e($l['error']) ?>
                    <?php if ($l['kind'] === 'toga'): ?> <a href="<?= e(url('toga_request.php', ['variant' => $vid])) ?>">Go to Toga Rental</a><?php endif; ?></small>
                <?php endif; ?>
              </td>
              <td data-label="Size"><?= e($l['size_label']) ?></td>
              <td data-label="Unit price" class="num"><?= e(money($l['unit_price'])) ?></td>
              <td data-label="Qty">
                <input class="rv-qty" type="number" inputmode="numeric" min="0" max="<?= (int)RES_MAX_QTY_INPUT ?>"
                       name="qty[<?= (int)$vid ?>]" value="<?= (int)$l['qty'] ?>" aria-label="Quantity for <?= e($l['item_name']) ?>">
              </td>
              <td data-label="Total" class="num"><?= e(money($l['line_cents'] / 100)) ?></td>
              <td><button class="rv-link-btn" type="submit" form="rm-<?= (int)$vid ?>" name="remove" value="<?= (int)$vid ?>">Remove</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td colspan="4" class="num"><strong>Estimated total</strong></td><td class="num"><strong><?= e(money($ev['total_cents'] / 100)) ?></strong></td><td></td></tr></tfoot>
        </table>
      </div>
      <p class="rv-muted">Set a quantity to 0 to remove a line. Prices are confirmed on your order slip. You pay at the Cashier, not on this website.</p>
      <div class="rv-actions">
        <button class="rv-btn" type="submit" name="action" value="update">Update quantities</button>
        <?php if ($ev['ok']): ?>
          <a class="rv-btn rv-btn-primary" href="<?= e(url('checkout.php')) ?>">Continue to checkout</a>
        <?php else: ?>
          <span class="rv-btn rv-btn-primary rv-disabled" aria-disabled="true">Fix the marked lines to continue</span>
        <?php endif; ?>
      </div>
    </form>

    <?php foreach (array_keys($lines) as $vid): ?>
      <form id="rm-<?= (int)$vid ?>" method="post" action="<?= e(url('reserve.php')) ?>"><?= csrf_field() ?></form>
    <?php endforeach; ?>

    <form method="post" action="<?= e(url('reserve.php')) ?>" class="rv-inline">
      <?= csrf_field() ?><input type="hidden" name="action" value="empty">
      <button class="rv-link-btn" type="submit" onclick="return confirm('Empty your reservation list?');">Empty list</button>
    </form>
  <?php endif; ?>
</main>
<script src="<?= e(asset('js/reserve.js')) ?>"></script>
<?php require CATALOG_FOOTER;
