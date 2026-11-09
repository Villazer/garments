<?php
/** Restock schedule: upcoming / received / cancelled. Feeds the public restock page. */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/inventory.php';

$user = require_can('restock.view');
$can_manage = can('restock.manage');
$tab = (string) ($_GET['tab'] ?? 'upcoming');
$tab = isset(inv_restock_tabs()[$tab]) ? $tab : 'upcoming';

if (is_post() && inv_migration_problem() === null) {
    csrf_require();
    require_can('restock.manage');
    if (($_POST['do'] ?? '') === 'cancel') {
        $res = inv_restock_cancel((int) ($_POST['restock_id'] ?? 0), (int) $user['user_id']);
        flash_set($res['ok'] ? 'success' : 'error', $res['message']);
    }
    redirect('admin/restock.php', ['tab' => $tab]);
}

$page_title = 'Restock schedule';
$nav_active = 'restock';
require AUTH_ROOT . '/includes/staff_header.php';
echo inv_subnav('restock');
if (inv_migration_block()) {
    require AUTH_ROOT . '/includes/staff_footer.php';
    return;
}
$rows = inv_restock_rows($tab);
$today = date('Y-m-d');
?>
<h1>Restock schedule</h1>
<p class="inv-muted">Scheduled and delayed entries appear on the public restock page (staff notes and quantities stay private).</p>
<div class="inv-filters">
  <?php foreach (inv_restock_tabs() as $k => $l): ?>
    <a class="inv-btn inv-btn-sm<?= $k === $tab ? '' : ' inv-btn-light' ?>" href="<?= e(url('admin/restock.php', ['tab' => $k])) ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
  <?php if ($can_manage): ?><a class="inv-btn" href="<?= e(url('admin/restock_form.php')) ?>">+ Schedule a restock</a><?php endif; ?>
</div>

<div class="inv-table-wrap">
<table class="inv-table">
  <thead><tr><th>Expected</th><th>Item / size</th><th class="num">Qty</th><th>Status</th><th>Note (staff only)</th><th class="num">Waiting</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="inv-muted">Nothing here.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r):
      $open = in_array($r['status'], ['scheduled', 'delayed'], true);
      $overdue = $open && $r['expected_date'] < $today; ?>
    <tr>
      <td><?= e(format_date($r['expected_date'])) ?><?= $overdue ? ' <span class="inv-badge inv-badge-out">overdue</span>' : '' ?></td>
      <td><?= e(inv_restock_label($r)) ?></td>
      <td class="num"><?= $r['expected_qty'] !== null ? (int) $r['expected_qty'] : '—' ?></td>
      <td><span class="inv-badge inv-badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'] === 'delivered' ? 'received' : $r['status'])) ?></span></td>
      <td><?= e((string) $r['note']) ?></td>
      <td class="num"><?php if ((int) $r['waiting'] > 0): ?><a href="<?= e(url('admin/waitlist.php', ['variant' => (int) $r['variant_id']])) ?>"><?= (int) $r['waiting'] ?></a><?php else: ?>—<?php endif; ?></td>
      <td><?php if ($open && $can_manage): ?><div class="inv-actions">
        <a class="inv-btn inv-btn-sm" href="<?= e(url('admin/restock_receive.php', ['id' => (int) $r['restock_id']])) ?>">Receive</a>
        <a class="inv-btn inv-btn-sm inv-btn-light" href="<?= e(url('admin/restock_form.php', ['id' => (int) $r['restock_id']])) ?>">Edit</a>
        <form method="post" action="<?= e(url('admin/restock.php', ['tab' => $tab])) ?>">
          <?= csrf_field() ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="restock_id" value="<?= (int) $r['restock_id'] ?>">
          <button class="inv-btn inv-btn-sm inv-btn-danger" type="submit" onclick="return confirm('Cancel this restock entry?')">Cancel</button>
        </form>
      </div><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
