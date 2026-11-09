<?php
/** Inventory overview: stock per size, flags, filters, CSV export. */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/inventory.php';

$user = require_can('inventory.view');
$problem = inv_migration_problem();
$f = inv_overview_filters($_GET);

if ($problem === null && ($_GET['export'] ?? '') === 'csv') {
    inv_export_csv($f);   // sends headers + file; nothing else may be printed
    exit;
}

$page_title = 'Inventory';
$nav_active = 'inventory';
require AUTH_ROOT . '/includes/staff_header.php';
echo inv_subnav('overview');
if (inv_migration_block()) {
    require AUTH_ROOT . '/includes/staff_footer.php';
    return;
}

$summary = inv_overview_summary();
$total   = inv_overview_count($f);
$p       = paginate($total, INV_PAGE_SIZE, $f['page']);
$rows    = inv_overview_rows($f, $p['offset'], $p['per_page']);
$query   = ['category' => $f['category'] ?: null, 'status' => $f['status'] !== 'all' ? $f['status'] : null,
            'scope' => $f['scope'] !== 'active' ? $f['scope'] : null, 'q' => $f['q'] !== '' ? $f['q'] : null];
$query   = array_filter($query, static fn($v) => $v !== null);
$can_adjust = can('inventory.count');
$can_catalog = inv_can_manage_catalog();
?>
<h1>Inventory overview</h1>

<div class="inv-summary">
  <div class="inv-card"><b><?= (int) $summary['sizes'] ?></b><span>Active sizes</span></div>
  <div class="inv-card"><b><?= (int) $summary['units_on_hand'] ?></b><span>Units on hand</span></div>
  <div class="inv-card"><b><?= (int) $summary['units_reserved'] ?></b><span>Units reserved</span></div>
  <div class="inv-card"><b><?= (int) $summary['low_count'] ?></b><span>Low stock</span></div>
  <div class="inv-card"><b><?= (int) $summary['out_count'] ?></b><span>Out of stock</span></div>
</div>

<form class="inv-filters" method="get" action="<?= e(url('admin/inventory.php')) ?>">
  <label>Search<input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Item, size or SKU"></label>
  <label>Category
    <select name="category"><option value="0">All</option>
      <?php foreach (inv_categories() as $c): ?>
        <option value="<?= (int) $c['category_id'] ?>"<?= $f['category'] === (int) $c['category_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select></label>
  <label>Status
    <select name="status">
      <?php foreach (['all' => 'All', 'attention' => 'Low or out', 'in' => 'In stock', 'low' => 'Low stock', 'out' => 'Out of stock', 'untracked' => 'Not tracked'] as $k => $l): ?>
        <option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select></label>
  <label>Show
    <select name="scope">
      <?php foreach (['active' => 'Active only', 'inactive' => 'Archived / inactive', 'all' => 'Everything'] as $k => $l): ?>
        <option value="<?= e($k) ?>"<?= $f['scope'] === $k ? ' selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select></label>
  <button class="inv-btn" type="submit">Filter</button>
  <a class="inv-btn inv-btn-light" href="<?= e(url('admin/inventory.php', $query + ['export' => 'csv'])) ?>">Export CSV</a>
</form>

<div class="inv-table-wrap">
<table class="inv-table">
  <thead><tr>
    <th>Item</th><th>Size</th><th class="num">Price</th><th class="num">On hand</th><th class="num">Reserved</th>
    <th class="num">Available</th><th>Status</th><th class="num">Waiting</th><th>Actions</th>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="9" class="inv-muted">No sizes match these filters.</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r):
      $tracks = (int) $r['tracks_stock'] === 1;
      $muted  = !(int) $r['item_active'] || !(int) $r['variant_active'];
      $state  = inv_stock_state((int) $r['available'], (int) $r['low_stock_threshold'], $tracks); ?>
    <tr<?= $muted ? ' class="is-muted"' : '' ?>>
      <td><strong><?= e($r['item_name']) ?></strong><br><span class="inv-muted"><?= e($r['category_name']) ?><?= $r['level_name'] ? ' · ' . e($r['level_name']) : '' ?><?= !(int) $r['item_active'] ? ' · archived' : '' ?></span></td>
      <td><?= e($r['size_label']) ?><?= !(int) $r['variant_active'] ? ' <span class="inv-badge inv-badge-muted">inactive</span>' : '' ?><br><span class="inv-muted"><?= e($r['sku']) ?></span></td>
      <td class="num"><?= e(money($r['unit_price'])) ?></td>
      <td class="num"><?= $tracks ? (int) $r['quantity_on_hand'] : '—' ?></td>
      <td class="num"><?= $tracks ? (int) $r['quantity_reserved'] : '—' ?></td>
      <td class="num"><strong><?= $tracks ? (int) $r['available'] : '—' ?></strong></td>
      <td><?= inv_state_badge($state) ?></td>
      <td class="num"><?php if ((int) $r['waiting'] > 0): ?><a href="<?= e(url('admin/waitlist.php', ['variant' => (int) $r['variant_id']])) ?>"><?= (int) $r['waiting'] ?></a><?php else: ?>0<?php endif; ?></td>
      <td><div class="inv-actions">
        <?php if ($tracks && $can_adjust): ?><a class="inv-btn inv-btn-sm" href="<?= e(url('admin/stock_adjust.php', ['variant' => (int) $r['variant_id']])) ?>">Adjust</a><?php endif; ?>
        <a class="inv-btn inv-btn-sm inv-btn-light" href="<?= e(url('admin/movements.php', ['variant' => (int) $r['variant_id']])) ?>">History</a>
        <?php if ($can_catalog): ?><a class="inv-btn inv-btn-sm inv-btn-light" href="<?= e(url('admin/item_edit.php', ['id' => (int) $r['item_id']])) ?>">Edit</a><?php endif; ?>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<div class="inv-pager"><?= pagination_links($p, 'admin/inventory.php', $query) ?></div>
<p class="inv-muted">Available = on hand − reserved. "Low" means available is at or below the size's low-stock threshold. Made-to-order items and services have no stock count.</p>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
