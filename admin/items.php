<?php
/** Item list. Everyone with inventory.view can look; manager/admin can archive, restore, delete. */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/inventory.php';

$user = require_can('inventory.view');
$can_edit = inv_can_manage_catalog();
$f = inv_items_filters($_GET);

if (is_post() && inv_migration_problem() === null) {
    csrf_require();
    if (!$can_edit) {
        deny_access();
    }
    $id = (int) ($_POST['item_id'] ?? 0);
    $do = (string) ($_POST['do'] ?? '');
    try {
        if ($do === 'archive' || $do === 'restore') {
            $open = inv_item_set_active($id, $do === 'restore', (int) $user['user_id']);
            if ($do === 'archive') {
                flash_set($open > 0 ? 'warning' : 'success', $open > 0
                    ? 'Item archived. Note: ' . $open . ' open order(s) still contain it; requested orders cannot be approved while it is archived.'
                    : 'Item archived. It is hidden from the public catalog but its history is kept.');
            } else {
                flash_set('success', 'Item restored to the catalog.');
            }
        } elseif ($do === 'delete') {
            inv_item_delete($id, (int) $user['user_id']);
            flash_set('success', 'Item deleted permanently.');
        }
    } catch (DomainException $ex) {
        flash_set('error', $ex->getMessage());
    }
    redirect('admin/items.php', array_filter(['status' => $f['status'] !== 'active' ? $f['status'] : null, 'q' => $f['q'] ?: null, 'category' => $f['category'] ?: null]));
}

$page_title = 'Items';
$nav_active = 'inventory';
require AUTH_ROOT . '/includes/staff_header.php';
echo inv_subnav('items');
if (inv_migration_block()) {
    require AUTH_ROOT . '/includes/staff_footer.php';
    return;
}
$total = inv_items_count($f);
$p = paginate($total, 25, $f['page']);
$rows = inv_items_rows($f, $p['offset'], $p['per_page']);
$query = array_filter(['q' => $f['q'] ?: null, 'category' => $f['category'] ?: null, 'status' => $f['status'] !== 'active' ? $f['status'] : null], static fn($v) => $v !== null);
$types = inv_item_types();
?>
<h1>Items</h1>
<form class="inv-filters" method="get" action="<?= e(url('admin/items.php')) ?>">
  <label>Search<input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name or code"></label>
  <label>Category
    <select name="category"><option value="0">All</option>
      <?php foreach (inv_categories() as $c): ?><option value="<?= (int) $c['category_id'] ?>"<?= $f['category'] === (int) $c['category_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
    </select></label>
  <label>Show
    <select name="status">
      <?php foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select></label>
  <button class="inv-btn" type="submit">Filter</button>
  <?php if ($can_edit): ?><a class="inv-btn inv-btn-light" href="<?= e(url('admin/item_edit.php')) ?>">+ New item</a><?php endif; ?>
</form>
<?php if (!$can_edit): ?><p class="inv-muted">Adding or editing items, sizes and prices is limited to managers and admins.</p><?php endif; ?>

<div class="inv-table-wrap">
<table class="inv-table">
  <thead><tr><th>Item</th><th>Category / level</th><th>Type</th><th>Reservation</th><th class="num">Sizes</th><th class="num">Price</th><th class="num">Available</th><th>Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="9" class="inv-muted">No items found.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r):
      $active = (int) $r['is_active'] === 1; ?>
    <tr<?= $active ? '' : ' class="is-muted"' ?>>
      <td><strong><?= e($r['name']) ?></strong><br><span class="inv-muted"><?= e($r['item_code']) ?></span></td>
      <td><?= e($r['category_name']) ?><?= $r['level_name'] ? '<br><span class="inv-muted">' . e($r['level_name']) . '</span>' : '' ?></td>
      <td><?= e($types[$r['item_type']] ?? $r['item_type']) ?></td>
      <td><?= e($r['reservation_mode']) ?> <span class="inv-muted">(max <?= (int) $r['max_qty_per_request'] ?>)</span></td>
      <td class="num"><?= (int) $r['sizes'] ?></td>
      <td class="num"><?php
        if ($r['min_price'] === null) { echo '—'; }
        elseif ($r['min_price'] === $r['max_price']) { echo e(money($r['min_price'])); }
        else { echo e(money($r['min_price'])) . ' – ' . e(money($r['max_price'])); } ?></td>
      <td class="num"><?= (int) $r['tracks_stock'] === 1 ? (int) $r['available'] : '—' ?></td>
      <td><?= $active ? '<span class="inv-badge inv-badge-in">Active</span>' : '<span class="inv-badge inv-badge-muted">Archived</span>' ?></td>
      <td><div class="inv-actions">
        <?php if ($can_edit): ?>
          <a class="inv-btn inv-btn-sm" href="<?= e(url('admin/item_edit.php', ['id' => (int) $r['item_id']])) ?>">Edit</a>
          <form method="post" action="<?= e(url('admin/items.php')) ?>">
            <?= csrf_field() ?><input type="hidden" name="item_id" value="<?= (int) $r['item_id'] ?>">
            <button class="inv-btn inv-btn-sm inv-btn-light" name="do" value="<?= $active ? 'archive' : 'restore' ?>" type="submit"
              <?= $active ? 'onclick="return confirm(\'Archive this item? It will be hidden from the public catalog.\')"' : '' ?>><?= $active ? 'Archive' : 'Restore' ?></button>
          </form>
        <?php else: ?>
          <a class="inv-btn inv-btn-sm inv-btn-light" href="<?= e(url('admin/inventory.php', ['q' => $r['item_code']])) ?>">Stock</a>
        <?php endif; ?>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<div class="inv-pager"><?= pagination_links($p, 'admin/items.php', $query) ?></div>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
