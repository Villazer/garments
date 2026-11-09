<?php
/**
 * Receive a restock: apply the delivered quantities to stock and mark the
 * entry delivered in ONE transaction, or just mark it delivered.
 */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/inventory.php';

$user = require_can('restock.manage');
$id = (int) ($_GET['id'] ?? $_POST['restock_id'] ?? 0);
$entry = $id > 0 ? inv_restock_find($id) : null;
if (!$entry) {
    staff_not_found();
}
if (!inv_restock_open($entry)) {
    flash_set('error', 'This entry was already received or cancelled.');
    redirect('admin/restock.php');
}
$item_id = $entry['variant_id'] !== null
    ? (int) inv_value('SELECT item_id FROM item_variants WHERE variant_id = ?', [(int) $entry['variant_id']])
    : (int) $entry['item_id'];
$sizes = inv_restock_sizes($item_id);
if ($entry['variant_id'] !== null) {
    $sizes = array_values(array_filter($sizes, static fn($s) => (int) $s['variant_id'] === (int) $entry['variant_id']));
}
$error = null;
$posted_qty = [];

if (inv_migration_problem() === null && is_post()) {
    csrf_require();
    $mode = (string) ($_POST['do'] ?? '');
    $posted_qty = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
    $lines = [];
    foreach ($sizes as $s) {
        $raw = trim((string) ($posted_qty[$s['variant_id']] ?? ''));
        if ($raw === '') {
            continue;
        }
        $n = inv_int($raw, 0, INV_MAX_QTY);
        if ($n === null) {
            $error = 'Quantities must be whole numbers from 0 to ' . INV_MAX_QTY . '.';
            break;
        }
        $lines[(int) $s['variant_id']] = $n;
    }
    if ($error === null && in_array($mode, ['apply', 'mark'], true)) {
        $res = inv_restock_receive($id, $lines, $mode === 'apply', (int) $user['user_id']);
        if ($res['ok']) {
            flash_set('success', $res['message']);
            redirect('admin/restock.php');
        }
        $error = $res['message'];
    }
}

$page_title = 'Receive restock';
$nav_active = 'restock';
require AUTH_ROOT . '/includes/staff_header.php';
echo inv_subnav('restock');
if (inv_migration_block()) {
    require AUTH_ROOT . '/includes/staff_footer.php';
    return;
}
?>
<h1>Receive restock</h1>
<p><strong><?= e(inv_restock_label($entry)) ?></strong><br>
  <span class="inv-muted">Expected <?= e(format_date($entry['expected_date'])) ?><?= $entry['expected_qty'] !== null ? ', about ' . (int) $entry['expected_qty'] . ' unit(s)' : '' ?><?= $entry['note'] ? ' · ' . e($entry['note']) : '' ?></span></p>
<?php if ($error): ?><div class="inv-alert inv-alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>

<form class="inv-form" method="post" action="<?= e(url('admin/restock_receive.php', ['id' => $id])) ?>">
  <?= csrf_field() ?><input type="hidden" name="restock_id" value="<?= $id ?>">
  <h2>Quantity actually received</h2>
  <div class="inv-table-wrap"><table class="inv-table">
    <thead><tr><th>Size</th><th class="num">On hand now</th><th>Received</th></tr></thead>
    <tbody>
    <?php foreach ($sizes as $s):
        $default = $entry['variant_id'] !== null && $entry['expected_qty'] !== null ? (string) $entry['expected_qty'] : ''; ?>
      <tr><td><?= e($s['size_label']) ?></td><td class="num"><?= (int) $s['quantity_on_hand'] ?></td>
        <td><input type="number" name="qty[<?= (int) $s['variant_id'] ?>]" min="0" max="<?= INV_MAX_QTY ?>"
             value="<?= e((string) ($posted_qty[$s['variant_id']] ?? $default)) ?>"></td></tr>
    <?php endforeach; ?>
    <?php if (!$sizes): ?><tr><td colspan="3" class="inv-muted">No active sizes to receive into.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="inv-hint">Each size gets a stock movement ("Restock #<?= $id ?>") with your name, and the entry moves to Received, all in one step. Leave a size blank if none arrived.</p>
  <div class="inv-actions">
    <button class="inv-btn" type="submit" name="do" value="apply">Receive and add to stock</button>
    <button class="inv-btn inv-btn-light" type="submit" name="do" value="mark" onclick="return confirm('Mark as delivered WITHOUT changing stock?')">Mark delivered only (no stock change)</button>
    <a class="inv-btn inv-btn-light" href="<?= e(url('admin/restock.php')) ?>">Back</a>
  </div>
</form>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
