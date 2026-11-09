<?php
/** Stock movement history with filters; links to the related order. */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/inventory.php';

$user = require_can('inventory.view');
$page_title = 'Stock movements';
$nav_active = 'inventory';
require AUTH_ROOT . '/includes/staff_header.php';
echo inv_subnav('movements');
if (inv_migration_block()) {
    require AUTH_ROOT . '/includes/staff_footer.php';
    return;
}

$f = inv_movement_filters($_GET);
$total = inv_movement_count($f);
$p = paginate($total, INV_PAGE_SIZE, $f['page']);
$rows = inv_movement_rows($f, $p['offset'], $p['per_page']);
$query = array_filter([
    'item' => $f['item'] ?: null, 'variant' => $f['variant'] ?: null, 'type' => $f['type'] ?: null,
    'user' => $f['user'] ?: null, 'from' => $f['from'] ?: null, 'to' => $f['to'] ?: null,
], static fn($v) => $v !== null);
$items = inv_all('SELECT item_id, name FROM items ORDER BY name');
?>
<h1>Stock movements</h1>
<form class="inv-filters" method="get" action="<?= e(url('admin/movements.php')) ?>">
  <?php if ($f['variant']): ?><input type="hidden" name="variant" value="<?= (int) $f['variant'] ?>"><?php endif; ?>
  <label>Item
    <select name="item"><option value="0">All items</option>
      <?php foreach ($items as $it): ?><option value="<?= (int) $it['item_id'] ?>"<?= $f['item'] === (int) $it['item_id'] ? ' selected' : '' ?>><?= e($it['name']) ?></option><?php endforeach; ?>
    </select></label>
  <label>Type
    <select name="type"><option value="">All types</option>
      <?php foreach (inv_movement_types() as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select></label>
  <label>User
    <select name="user"><option value="0">Everyone</option>
      <?php foreach (inv_user_options() as $u): ?><option value="<?= (int) $u['user_id'] ?>"<?= $f['user'] === (int) $u['user_id'] ? ' selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?>
    </select></label>
  <label>From<input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label>To<input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <button class="inv-btn" type="submit">Filter</button>
  <a class="inv-btn inv-btn-light" href="<?= e(url('admin/movements.php')) ?>">Reset</a>
</form>
<?php if ($f['variant']): ?><p class="inv-muted">Showing one size only. Use Reset to see all movements.</p><?php endif; ?>
<p class="inv-muted"><?= (int) $total ?> movement(s). System actions (auto-approval, scheduled jobs, customer cancels) show as "System".</p>

<div class="inv-table-wrap">
<table class="inv-table">
  <thead><tr><th>When</th><th>Item / size</th><th>Type</th><th class="num">On hand</th><th class="num">Reserved</th><th>Order</th><th>By</th><th>Reason</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="8" class="inv-muted">No movements match.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e(format_datetime($r['created_at'])) ?></td>
      <td><?= e($r['item_name']) ?>, <?= e($r['size_label']) ?></td>
      <td><?= e(inv_movement_types()[$r['movement_type']] ?? $r['movement_type']) ?></td>
      <td class="num"><?= e(inv_signed((int) $r['on_hand_delta'])) ?></td>
      <td class="num"><?= e(inv_signed((int) $r['reserved_delta'])) ?></td>
      <td><?php if ($r['order_id']): ?><a href="<?= e(url('admin/order_view.php', ['id' => (int) $r['order_id']])) ?>"><?= e($r['order_no'] ?: ('#' . $r['order_id'])) ?></a><?php else: ?>—<?php endif; ?></td>
      <td><?= $r['username'] !== null ? e($r['full_name'] ?: $r['username']) : 'System' ?></td>
      <td><?= e((string) $r['reason']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<div class="inv-pager"><?= pagination_links($p, 'admin/movements.php', $query) ?></div>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
