<?php
/** Recycle bin: expired and removed orders. Restore (manager+), purge (admin only, with confirmation). */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/recycle.php';

$user = require_can('recycle.view');
$uid = (int) $user['user_id'];
$can_restore = can('recycle.restore');
$can_purge = can('settings.manage');   // admin only
$f = rb_filters($_GET);
$keep = array_filter(['status' => $f['status'] ?: null, 'q' => $f['q'] ?: null, 'page' => $f['page'] > 1 ? $f['page'] : null], static fn($x) => $x !== null);

if (is_post()) {
    csrf_require();
    $do = (string) ($_POST['do'] ?? '');
    $id = (int) ($_POST['order_id'] ?? 0);
    if ($do === 'restore') {
        require_can('recycle.restore');
        $res = rb_restore($id, $uid);
        flash_set($res['ok'] ? 'success' : 'error', $res['message']);
        redirect('admin/recycle_bin.php', $keep);
    }
    if ($do === 'purge') {
        require_can('settings.manage');
        $res = rb_purge($id, $uid, (string) ($_POST['confirm_ref'] ?? ''));
        flash_set($res['ok'] ? 'success' : 'error', $res['message']);
        redirect('admin/recycle_bin.php', $res['ok'] ? $keep : ['purge' => $id]);
    }
    redirect('admin/recycle_bin.php', $keep);
}

// Step 1 of a purge: the confirmation page.
$purge_order = null;
if (isset($_GET['purge'])) {
    if (!$can_purge) {
        deny_access();
    }
    $purge_order = rb_find((int) $_GET['purge']);
    if (!$purge_order) {
        flash_set('error', 'That order is not in the recycle bin.');
        redirect('admin/recycle_bin.php');
    }
}

$total = rb_count($f);
$p = paginate($total, 20, $f['page']);
$rows = $purge_order ? [] : rb_rows($f, (int) $p['offset'], (int) $p['per_page']);
$purge_days = max(1, (int) get_setting('recycle_purge_days', 30));

$page_title = 'Recycle bin';
$nav_active = 'recycle';
require AUTH_ROOT . '/includes/staff_header.php';
?>
<link rel="stylesheet" href="<?= e(asset('css/admin9.css')) ?>">

<?php if ($purge_order): $o = $purge_order; ?>
  <section class="s9-card s9-danger">
    <h2>Permanently delete this order?</h2>
    <p>This cannot be undone. The order, its lines and any rental record are removed. A record of the purge stays in the activity log.</p>
    <dl class="s9-dl">
      <dt>Reference</dt><dd><strong><?= e($o['order_ref']) ?></strong></dd>
      <dt>Customer</dt><dd><?= e($o['customer_name']) ?></dd>
      <dt>Status</dt><dd><?= status_badge($o['status']) ?></dd>
      <dt>Total</dt><dd><?= e(money($o['total_amount'])) ?></dd>
    </dl>
    <form method="post" action="<?= e(url('admin/recycle_bin.php')) ?>" class="s9-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="purge"><input type="hidden" name="order_id" value="<?= (int) $o['order_id'] ?>">
      <div class="s9-field"><label for="confirm_ref">Type the reference number <strong><?= e($o['order_ref']) ?></strong> to confirm</label>
        <input id="confirm_ref" name="confirm_ref" autocomplete="off" required></div>
      <button type="submit" class="btn btn-danger">Delete permanently</button>
      <a class="btn" href="<?= e(url('admin/recycle_bin.php')) ?>">Cancel</a>
    </form>
  </section>
<?php else: ?>
  <p class="s9-muted">Expired orders stay here for <?= $purge_days ?> days (Settings) and are then purged automatically. Restoring puts the stock back on hold, so it only works while enough is still available.</p>

  <form method="get" action="<?= e(url('admin/recycle_bin.php')) ?>" class="s9-filters">
    <label>Search<input type="search" name="q" value="<?= e($f['q']) ?>" maxlength="60" placeholder="Reference, slip no., customer, student no."></label>
    <label>Show
      <select name="status">
        <option value="">All</option>
        <option value="expired"<?= $f['status'] === 'expired' ? ' selected' : '' ?>>Expired</option>
        <option value="other"<?= $f['status'] === 'other' ? ' selected' : '' ?>>Other removed orders</option>
      </select></label>
    <button type="submit" class="btn btn-primary">Filter</button>
    <a class="btn" href="<?= e(url('admin/recycle_bin.php')) ?>">Reset</a>
  </form>

  <?php if ($can_purge): ?>
  <form method="post" action="<?= e(url('admin/run_expiry.php')) ?>" class="s9-inline-block">
    <?= csrf_field() ?><input type="hidden" name="back" value="admin/recycle_bin.php">
    <button type="submit" class="btn btn-small">Run expiry now</button>
    <span class="s9-hint">Moves any unpaid reservation past its hold time into this bin.</span>
  </form>
  <?php endif; ?>

  <p class="s9-muted"><?= (int) $total ?> order<?= $total === 1 ? '' : 's' ?> in the bin.</p>
  <div class="s9-table-wrap"><table class="s9-table">
    <thead><tr><th>Reference</th><th>Customer / student</th><th class="s9-num">Items</th><th class="s9-num">Total</th><th>Status</th><th>Removed</th><th>Purge after</th><th>Restore check</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="9" class="s9-muted">The recycle bin is empty.</td></tr><?php endif; ?>
    <?php foreach ($rows as $o):
        $problems = rb_restore_problems($o); ?>
      <tr>
        <td><a href="<?= e(url('admin/order_view.php', ['id' => (int) $o['order_id']])) ?>"><strong><?= e($o['order_ref']) ?></strong></a></td>
        <td><?= e($o['customer_name']) ?><?php if ($o['student_no']): ?><br><small class="s9-muted"><?= e($o['student_name']) ?> · <?= e($o['student_no']) ?></small><?php endif; ?></td>
        <td class="s9-num"><?= (int) $o['item_count'] ?></td>
        <td class="s9-num"><?= e(money($o['total_amount'])) ?></td>
        <td><?= status_badge($o['status']) ?></td>
        <td><?= e(format_datetime($o['deleted_at'])) ?></td>
        <td><?= e(format_datetime($o['purge_after'])) ?></td>
        <td><?php if ($o['status'] !== 'expired'): ?><span class="s9-muted">—</span>
            <?php elseif ($problems): ?><span class="s9-badge s9-badge-bad">Cannot restore</span><br><small><?= e(implode('; ', $problems)) ?></small>
            <?php else: ?><span class="s9-badge s9-badge-ok">Stock available</span><?php endif; ?></td>
        <td><div class="s9-actions">
          <?php if ($can_restore): ?>
          <form method="post" action="<?= e(url('admin/recycle_bin.php', $keep)) ?>">
            <?= csrf_field() ?><input type="hidden" name="do" value="restore"><input type="hidden" name="order_id" value="<?= (int) $o['order_id'] ?>">
            <button type="submit" class="btn btn-small btn-primary"<?= $problems ? ' disabled' : '' ?>
              data-confirm="Restore this order and re-reserve its stock?">Restore</button>
          </form>
          <?php endif; ?>
          <?php if ($can_purge && (int) $o['pay_count'] === 0): ?>
            <a class="btn btn-small btn-danger" href="<?= e(url('admin/recycle_bin.php', ['purge' => (int) $o['order_id']])) ?>">Purge…</a>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= pagination_links($p, 'admin/recycle_bin.php', array_diff_key($keep, ['page' => 1])) ?>
<?php endif; ?>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
