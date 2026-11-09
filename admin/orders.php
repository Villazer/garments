<?php
/** Staff orders list: filter, search, paginate, highlight orders about to expire. */
require __DIR__ . '/_init.php';
$user = require_can('orders.view');
require_once AUTH_ROOT . '/includes/orders.php';

$f = ord_read_filters($_GET);
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = ord_count($f);
$p = paginate($total, ord_page_size(), $page);
$rows = ord_list($f, (int) $p['offset'], (int) $p['per_page']);
$counts = ord_status_counts();
$open_count = 0;
foreach (ord_open_statuses() as $s) {
    $open_count += $counts[$s];
}
$tab_query = fn(string $status) => ord_filter_query(array_merge($f, ['status' => $status]));

$page_title = 'Orders';
$nav_active = 'orders';
require AUTH_ROOT . '/includes/staff_header.php';
?>
<link rel="stylesheet" href="<?= e(asset('css/orders.css')) ?>">

<div class="s6-head">
  <h1>Orders</h1>
  <p class="s6-muted"><?= (int) $total ?> order<?= $total === 1 ? '' : 's' ?> found</p>
</div>

<nav class="s6-tabs" aria-label="Filter by status">
  <a class="<?= $f['status'] === '' ? 'is-active' : '' ?>" href="<?= e(url('admin/orders.php', array_diff_key(ord_filter_query($f), ['status' => 1]))) ?>">All</a>
  <a class="<?= $f['status'] === 'open' ? 'is-active' : '' ?>" href="<?= e(url('admin/orders.php', $tab_query('open'))) ?>">Open <span><?= $open_count ?></span></a>
  <?php foreach (['requested', 'approved', 'slip_issued', 'paid', 'claimed', 'unclaimed', 'declined', 'cancelled'] as $s): ?>
    <a class="<?= $f['status'] === $s ? 'is-active' : '' ?>" href="<?= e(url('admin/orders.php', $tab_query($s))) ?>"><?= e(ord_label($s)) ?> <span><?= $counts[$s] ?></span></a>
  <?php endforeach; ?>
</nav>

<form method="get" action="<?= e(url('admin/orders.php')) ?>" class="s6-filters">
  <label>Search
    <input type="search" name="q" value="<?= e($f['q']) ?>" maxlength="60" placeholder="Reference, slip no., customer, student no.">
  </label>
  <label>Status
    <select name="status">
      <option value="">All statuses</option>
      <option value="open" <?= $f['status'] === 'open' ? 'selected' : '' ?>>Open (needs attention)</option>
      <?php foreach (ord_statuses_all() as $s): ?>
        <option value="<?= e($s) ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= e(ord_label($s)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>From <input type="date" name="date_from" value="<?= e($f['date_from']) ?>"></label>
  <label>To <input type="date" name="date_to" value="<?= e($f['date_to']) ?>"></label>
  <label class="s6-check"><input type="checkbox" name="expiring" value="1" <?= $f['expiring'] ? 'checked' : '' ?>> Expiring soon only</label>
  <div class="s6-filter-actions">
    <button type="submit" class="btn btn-primary">Apply</button>
    <a class="btn" href="<?= e(url('admin/orders.php')) ?>">Reset</a>
  </div>
</form>

<?php if (!$rows): ?>
  <p class="s6-empty">No orders match these filters.</p>
<?php else: ?>
  <div class="s6-table-wrap">
    <table class="s6-table">
      <thead>
        <tr><th>Reference</th><th>Slip no.</th><th>Customer / student</th><th>Items</th><th class="s6-num">Total</th><th>Status</th><th>Placed</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $o):
          $urg = ord_urgency($o);
          $slip_known = in_array($o['status'], ['slip_issued', 'paid', 'claimed', 'unclaimed'], true);
      ?>
        <tr class="<?= $urg ? 's6-row-' . e($urg['level']) : '' ?>">
          <td data-label="Reference"><a href="<?= e(url('admin/order_view.php', ['id' => $o['order_id']])) ?>"><strong><?= e($o['order_ref']) ?></strong></a></td>
          <td data-label="Slip no."><?= $slip_known ? e($o['order_no']) : '<span class="s6-muted">Not issued</span>' ?></td>
          <td data-label="Customer"><?= e($o['customer_name']) ?>
            <?php if ($o['student_no']): ?><br><small class="s6-muted"><?= e($o['student_name']) ?> · <?= e($o['student_no']) ?></small><?php endif; ?></td>
          <td data-label="Items"><?= (int) $o['item_count'] ?></td>
          <td data-label="Total" class="s6-num"><?= e(money($o['total_amount'])) ?></td>
          <td data-label="Status"><?= status_badge($o['status']) ?>
            <?php if ($urg): ?><br><small class="s6-flag s6-flag-<?= e($urg['level']) ?>"><?= e($urg['text']) ?></small><?php endif; ?></td>
          <td data-label="Placed"><?= e(format_datetime($o['created_at'])) ?><br><small class="s6-muted"><?= $o['source'] === 'walk_in' ? 'Walk-in' : 'Online' ?></small></td>
          <td><a class="btn btn-small" href="<?= e(url('admin/order_view.php', ['id' => $o['order_id']])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p, 'admin/orders.php', ord_filter_query($f)) ?>
<?php endif; ?>

<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
