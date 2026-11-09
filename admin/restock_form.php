<?php
/** Schedule restocks (pick an item, then quantities per size) or edit one open entry. */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/inventory.php';

$user = require_can('restock.manage');
$uid = (int) $user['user_id'];
$id = (int) ($_GET['id'] ?? $_POST['restock_id'] ?? 0);
$entry = $id > 0 ? inv_restock_find($id) : null;
if ($id > 0 && (!$entry || !inv_restock_open($entry))) {
    flash_set('error', 'Only upcoming restock entries can be edited.');
    redirect('admin/restock.php');
}
$item_id = (int) ($_GET['item'] ?? $_POST['item_id'] ?? 0);
$errors = [];
$posted = [];
if (!$entry && $item_id > 0 && !inv_one('SELECT item_id FROM items WHERE item_id = ? AND tracks_stock = 1 AND is_active = 1', [$item_id])) {
    staff_not_found();   // before any output
}

if (inv_migration_problem() === null && is_post()) {
    csrf_require();
    $posted = $_POST;
    if ($entry) {
        $res = inv_restock_update($id, $_POST, $uid);
        if ($res['ok']) {
            flash_set('success', 'Restock entry updated.');
            redirect('admin/restock.php');
        }
        $errors = $res['errors'];
    } else {
        $res = inv_restock_create($item_id, $_POST, $uid);
        if ($res['ok']) {
            flash_set('success', $res['count'] . ' restock ' . ($res['count'] === 1 ? 'entry' : 'entries') . ' scheduled.');
            redirect('admin/restock.php');
        }
        $errors = $res['errors'];
    }
}

$page_title = $entry ? 'Edit restock' : 'Schedule a restock';
$nav_active = 'restock';
require AUTH_ROOT . '/includes/staff_header.php';
echo inv_subnav('restock');
if (inv_migration_block()) {
    require AUTH_ROOT . '/includes/staff_footer.php';
    return;
}
$fe = static fn(array $errs, string $k): string => isset($errs[$k]) ? '<p class="inv-error">' . e($errs[$k]) . '</p>' : '';
$val = static fn(string $k, $default = '') => e((string) ($posted[$k] ?? $default));
?>
<h1><?= e($page_title) ?></h1>
<?php if (!empty($errors['general'])): ?><div class="inv-alert inv-alert-error" role="alert"><?= e($errors['general']) ?></div><?php endif; ?>

<?php if ($entry): ?>
  <form class="inv-form" method="post" action="<?= e(url('admin/restock_form.php', ['id' => $id])) ?>">
    <?= csrf_field() ?><input type="hidden" name="restock_id" value="<?= $id ?>">
    <p><strong><?= e(inv_restock_label($entry)) ?></strong></p>
    <div class="inv-grid-2">
      <div class="inv-field"><label for="expected_date">Expected date</label>
        <input id="expected_date" type="date" name="expected_date" required value="<?= $val('expected_date', $entry['expected_date']) ?>"><?= $fe($errors, 'expected_date') ?></div>
      <div class="inv-field"><label for="expected_qty">Expected quantity (optional)</label>
        <input id="expected_qty" type="number" name="expected_qty" min="1" max="<?= INV_MAX_QTY ?>" value="<?= $val('expected_qty', (string) $entry['expected_qty']) ?>"><?= $fe($errors, 'expected_qty') ?></div>
      <div class="inv-field"><label for="status">Status</label>
        <select id="status" name="status">
          <?php $cur = (string) ($posted['status'] ?? $entry['status']); foreach (['scheduled' => 'Scheduled', 'delayed' => 'Delayed'] as $k => $l): ?>
            <option value="<?= e($k) ?>"<?= $cur === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select><?= $fe($errors, 'status') ?></div>
    </div>
    <div class="inv-field"><label for="note">Note (staff only)</label>
      <input id="note" type="text" name="note" maxlength="255" value="<?= $val('note', (string) $entry['note']) ?>"><?= $fe($errors, 'note') ?></div>
    <button class="inv-btn" type="submit">Save</button>
    <a class="inv-btn inv-btn-light" href="<?= e(url('admin/restock.php')) ?>">Cancel</a>
  </form>

<?php elseif ($item_id <= 0): ?>
  <form class="inv-form" method="get" action="<?= e(url('admin/restock_form.php')) ?>">
    <div class="inv-field"><label for="item">Which item is being restocked?</label>
      <select id="item" name="item" required><option value="">Choose…</option>
        <?php foreach (inv_restock_items() as $it): ?><option value="<?= (int) $it['item_id'] ?>"><?= e($it['name']) ?> (<?= e($it['item_code']) ?>)</option><?php endforeach; ?>
      </select>
      <p class="inv-hint">Next you enter the expected quantity for each size.</p></div>
    <button class="inv-btn" type="submit">Continue</button>
    <a class="inv-btn inv-btn-light" href="<?= e(url('admin/restock.php')) ?>">Cancel</a>
  </form>

<?php else:
    $item = inv_one('SELECT item_id, name FROM items WHERE item_id = ?', [$item_id]);
    $sizes = inv_restock_sizes($item_id); ?>
  <form class="inv-form" method="post" action="<?= e(url('admin/restock_form.php', ['item' => $item_id])) ?>">
    <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $item_id ?>">
    <p><strong><?= e($item['name']) ?></strong> <a href="<?= e(url('admin/restock_form.php')) ?>">(change item)</a></p>
    <?= $fe($errors, 'item') ?>
    <div class="inv-grid-2">
      <div class="inv-field"><label for="expected_date">Expected date</label>
        <input id="expected_date" type="date" name="expected_date" required min="<?= e(date('Y-m-d')) ?>" value="<?= $val('expected_date') ?>"><?= $fe($errors, 'expected_date') ?></div>
      <div class="inv-field"><label for="note">Note (staff only)</label>
        <input id="note" type="text" name="note" maxlength="255" value="<?= $val('note') ?>" placeholder="e.g. Supplier, PO number"><?= $fe($errors, 'note') ?></div>
    </div>
    <h2>Expected quantity per size</h2>
    <p class="inv-hint">Leave a size blank if it is not part of this delivery. One entry is created per size you fill in.</p>
    <?= $fe($errors, 'entries') ?>
    <div class="inv-table-wrap"><table class="inv-table">
      <thead><tr><th>Size</th><th class="num">On hand now</th><th>Expected quantity</th></tr></thead>
      <tbody>
      <?php foreach ($sizes as $s): ?>
        <tr><td><?= e($s['size_label']) ?></td><td class="num"><?= (int) $s['quantity_on_hand'] ?></td>
          <td><input type="number" name="size_qty[<?= (int) $s['variant_id'] ?>]" min="1" max="<?= INV_MAX_QTY ?>"
               value="<?= e((string) ($posted['size_qty'][$s['variant_id']] ?? '')) ?>"><?= $fe($errors, 'size_' . $s['variant_id']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$sizes): ?><tr><td colspan="3" class="inv-muted">This item has no active sizes. Add sizes first, or schedule the whole item below.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <div class="inv-field">
      <label><input type="checkbox" name="whole_item" value="1"<?= !empty($posted['whole_item']) ? ' checked' : '' ?>> Whole item only (sizes not known yet)</label>
      <input type="number" name="whole_qty" min="1" max="<?= INV_MAX_QTY ?>" placeholder="Total quantity (optional)" value="<?= $val('whole_qty') ?>">
      <?= $fe($errors, 'whole_qty') ?>
      <p class="inv-hint">Use this when you know an item is coming but not the sizes; you enter the sizes when you receive it.</p>
    </div>
    <button class="inv-btn" type="submit">Schedule</button>
    <a class="inv-btn inv-btn-light" href="<?= e(url('admin/restock.php')) ?>">Cancel</a>
  </form>
<?php endif; ?>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
