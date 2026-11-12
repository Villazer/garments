<?php
/** Dashboard: shows only what the signed-in role can act on. */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/dashboard.php';
$user = require_can('dashboard.view');
$role = $user['role'];

$show_orders = can('orders.process', $role);
$show_stock  = can('inventory.view', $role);
$show_restock = can('restock.view', $role);
$show_togas  = can('rentals.view', $role);
$show_admin  = can('settings.manage', $role);

$awaiting = $show_orders ? dash_awaiting_approval() : null;
$expiring = $show_orders ? dash_expiring() : null;
$slips    = $show_orders ? dash_slips_waiting() : null;
$stock    = $show_stock ? dash_stock_attention() : null;
$restocks = $show_restock ? dash_restocks() : null;
$togas    = $show_togas ? dash_overdue_togas() : null;
$bin      = can('recycle.view', $role) ? dash_recycle_count() : null;
if ($show_admin) {
    require_once AUTH_ROOT . '/includes/expiry.php';
    $exp = expiry_status();
}

$page_title = 'Dashboard';
$nav_active = 'dashboard';
require AUTH_ROOT . '/includes/staff_header.php';

/** One order row: link, customer and a right-hand note. */
$order_li = static function (array $o, string $note): string {
    return '<li><a href="' . e(url('admin/order_view.php', ['id' => (int) $o['order_id']])) . '"><strong>' . e($o['order_ref']) . '</strong></a> '
        . e($o['customer_name']) . ($o['student_no'] ? ' <small class="s9-muted">(' . e($o['student_no']) . ')</small>' : '')
        . '<span class="s9-li-note">' . e($note) . '</span></li>';
};
?>
<link rel="stylesheet" href="<?= e(asset('css/admin9.css')) ?>">

<section class="s9-card">
  <p>Signed in as <strong><?= e($user['full_name']) ?></strong> (<?= e($user['username']) ?>) &middot; <?= role_badge($role) ?></p>
  <p class="s9-muted"><?= e(role_description($role)) ?></p>
</section>

<div class="s9-tiles">
  <?php if ($awaiting): ?><a class="s9-tile<?= $awaiting['count'] ? ' is-alert' : '' ?>" href="<?= e(url('admin/orders.php', ['status' => 'requested'])) ?>"><b><?= (int) $awaiting['count'] ?></b><span>Awaiting approval</span></a><?php endif; ?>
  <?php if ($expiring): ?><a class="s9-tile<?= $expiring['count'] ? ' is-alert' : '' ?>" href="<?= e(url('admin/orders.php', ['expiring' => 1])) ?>"><b><?= (int) $expiring['count'] ?></b><span>Expiring soon</span></a><?php endif; ?>
  <?php if ($slips): ?><a class="s9-tile" href="<?= e(url('admin/orders.php', ['status' => 'slip_issued'])) ?>"><b><?= (int) $slips['count'] ?></b><span>Slips waiting for payment</span></a><?php endif; ?>
  <?php if ($stock): ?><a class="s9-tile<?= $stock['out'] ? ' is-alert' : '' ?>" href="<?= e(url('admin/inventory.php', ['status' => 'attention'])) ?>"><b><?= (int) ($stock['out'] + $stock['low']) ?></b><span>Sizes low or out of stock</span></a><?php endif; ?>
  <?php if ($togas): ?><a class="s9-tile<?= $togas['count'] ? ' is-alert' : '' ?>" href="<?= e(url('admin/toga_rentals.php')) ?>"><b><?= (int) $togas['count'] ?></b><span>Overdue togas</span></a><?php endif; ?>
  <?php if ($bin !== null): ?><a class="s9-tile" href="<?= e(url('admin/recycle_bin.php')) ?>"><b><?= (int) $bin ?></b><span>In the recycle bin</span></a><?php endif; ?>
</div>

<div class="s9-cols">
<?php if ($awaiting): ?>
  <section class="s9-card"><h2>Orders awaiting approval <span class="s9-count"><?= (int) $awaiting['count'] ?></span></h2>
    <?php if (!$awaiting['rows']): ?><p class="s9-muted">Nothing waiting.</p><?php else: ?>
    <ul class="s9-list"><?php foreach ($awaiting['rows'] as $o) { echo $order_li($o, 'placed ' . format_datetime($o['created_at'])); } ?></ul>
    <p><a href="<?= e(url('admin/orders.php', ['status' => 'requested'])) ?>">See all requested orders</a></p><?php endif; ?></section>
<?php endif; ?>

<?php if ($expiring): ?>
  <section class="s9-card"><h2>Orders expiring soon <span class="s9-count"><?= (int) $expiring['count'] ?></span></h2>
    <?php if (!$expiring['rows']): ?><p class="s9-muted">No holds are about to end.</p><?php else: ?>
    <ul class="s9-list"><?php foreach ($expiring['rows'] as $o) { echo $order_li($o, dash_relative($o['expires_at'])); } ?></ul>
    <p><a href="<?= e(url('admin/orders.php', ['expiring' => 1])) ?>">See all expiring orders</a></p><?php endif; ?></section>
<?php endif; ?>

<?php if ($slips): ?>
  <section class="s9-card"><h2>Slips waiting for payment <span class="s9-count"><?= (int) $slips['count'] ?></span></h2>
    <?php if (!$slips['rows']): ?><p class="s9-muted">No issued slips are waiting.</p><?php else: ?>
    <ul class="s9-list"><?php foreach ($slips['rows'] as $o) { echo $order_li($o, ($o['order_no'] ? $o['order_no'] . ' · ' : '') . money($o['total_amount'])); } ?></ul>
    <p><a href="<?= e(url('admin/orders.php', ['status' => 'slip_issued'])) ?>">See all</a></p><?php endif; ?></section>
<?php endif; ?>

<?php if ($stock): ?>
  <section class="s9-card"><h2>Items low or out of stock <span class="s9-count"><?= (int) ($stock['out'] + $stock['low']) ?></span></h2>
    <?php if (!$stock['rows']): ?><p class="s9-muted">All stocked sizes are above their thresholds.</p><?php else: ?>
    <p class="s9-muted"><?= (int) $stock['out'] ?> out of stock, <?= (int) $stock['low'] ?> low.</p>
    <ul class="s9-list">
      <?php foreach ($stock['rows'] as $r): ?>
        <li><?= e($r['item_name']) ?>, size <?= e($r['size_label']) ?>
          <span class="s9-li-note"><?= (int) $r['available'] <= 0 ? '<span class="s9-badge s9-badge-bad">Out</span>' : '<span class="s9-badge s9-badge-warn">' . (int) $r['available'] . ' left</span>' ?>
            <?php if (can('inventory.count', $role)): ?> <a href="<?= e(url('admin/stock_adjust.php', ['variant' => (int) $r['variant_id']])) ?>">Adjust</a><?php endif; ?></span></li>
      <?php endforeach; ?>
    </ul>
    <p><a href="<?= e(url('admin/inventory.php', ['status' => 'attention'])) ?>">Open inventory</a></p><?php endif; ?></section>
<?php endif; ?>

<?php if ($restocks !== null): ?>
  <section class="s9-card"><h2>Upcoming restocks</h2>
    <?php if (!$restocks): ?><p class="s9-muted">No restocks are scheduled.</p><?php else: ?>
    <ul class="s9-list">
      <?php foreach ($restocks as $r): $late = $r['expected_date'] < date('Y-m-d'); ?>
        <li><?= e(format_date($r['expected_date'])) ?> &ndash; <?= e($r['item_name']) ?><?= $r['size_label'] !== null ? ', size ' . e($r['size_label']) : ', all sizes' ?>
          <span class="s9-li-note"><?= $r['status'] === 'delayed' ? '<span class="s9-badge s9-badge-warn">Delayed</span>' : ($late ? '<span class="s9-badge s9-badge-bad">Overdue</span>' : '') ?><?= $r['expected_qty'] !== null ? ' ' . (int) $r['expected_qty'] . ' pcs' : '' ?></span></li>
      <?php endforeach; ?>
    </ul><?php endif; ?>
    <p><a href="<?= e(url('admin/restock.php')) ?>">Restock schedule</a></p></section>
<?php endif; ?>

<?php if ($togas): ?>
  <section class="s9-card"><h2>Overdue togas <span class="s9-count"><?= (int) $togas['count'] ?></span></h2>
    <?php if (!$togas['rows']): ?><p class="s9-muted">No toga is past its due date.</p><?php else: ?>
    <ul class="s9-list">
      <?php foreach ($togas['rows'] as $r): ?>
        <li><a href="<?= e(url('admin/toga_rental_view.php', ['id' => (int) $r['order_id']])) ?>"><strong><?= e($r['student_name']) ?></strong></a>
          <small class="s9-muted">(<?= e($r['student_no']) ?>)</small> <?= e($r['item_name']) ?>, <?= e($r['size_label']) ?>
          <span class="s9-li-note"><?= (int) $r['days_late'] ?> day<?= (int) $r['days_late'] === 1 ? '' : 's' ?> late</span></li>
      <?php endforeach; ?>
    </ul>
    <p><a href="<?= e(url('admin/toga_rentals.php')) ?>">Open toga rentals</a></p><?php endif; ?></section>
<?php endif; ?>

<?php if ($show_admin): ?>
  <section class="s9-card"><h2>Automatic expiry</h2>
    <p><?= (int) $exp['due'] ?> unpaid hold(s) past their time right now.<br>
      <span class="s9-muted">Last run: <?= $exp['last_run'] !== '' ? e(format_datetime($exp['last_run'])) : 'never' ?><?= $exp['result'] !== '' ? ' &middot; ' . e($exp['result']) : '' ?></span></p>
    <form method="post" action="<?= e(url('admin/run_expiry.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="back" value="admin/index.php">
      <button type="submit" class="btn btn-small">Run expiry now</button>
    </form></section>
<?php endif; ?>
</div>

<?php $shortcuts = array_filter(staff_nav_visible(), fn($i) => $i['key'] !== 'dashboard'); ?>
<h2 class="s9-h2">Your sections</h2>
<div class="sp-grid">
  <?php foreach ($shortcuts as $item): ?>
    <a class="sp-tile" href="<?= e($item['href']) ?>">
      <span class="sp-tile-name"><?= e($item['label']) ?></span>
      <span class="sp-muted sp-small"><?= $item['ready'] ? 'Open' : 'Coming soon' ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php require AUTH_ROOT . '/includes/staff_footer.php';
