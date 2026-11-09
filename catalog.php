<?php
declare(strict_types=1);

/* Public catalog (Session 3): browse, search, filter, sort, paginate. Read-only. */
require __DIR__ . '/includes/catalog_bootstrap.php';

$f          = catalog_read_filters($_GET);
$categories = catalog_categories();
$total      = catalog_count_items($f);
$per_page   = catalog_page_size();
$total_pages = max(1, (int) ceil($total / $per_page));
$page       = min($f['page'], $total_pages);          // a page past the end shows the last page
$pager      = paginate($total, $per_page, $page);
$items      = $total > 0 ? catalog_fetch_items($f, (int) $pager['offset'], (int) $pager['per_page']) : [];
$images     = catalog_images_for($items);
$link_query = catalog_filter_query($f);
$filtered   = $link_query !== [];

$first = $total > 0 ? ($page - 1) * $per_page + 1 : 0;
$last  = $total > 0 ? $first + count($items) - 1 : 0;

$page_title = 'Catalog';
$nav_active = 'catalog';
require CATALOG_HEADER;
echo catalog_asset_tags();
?>
<div class="cat-page">
  <h1>Uniform and toga catalog</h1>
  <p class="cat-muted">Prices and stock are live. Choose an item to see every size.</p>

  <form class="cat-filters" method="get" action="<?= e(url('catalog.php')) ?>">
    <div class="cat-field cat-field--wide">
      <label for="f-q">Search by name</label>
      <input type="search" id="f-q" name="q" maxlength="60" value="<?= e($f['q']) ?>" placeholder="For example: polo, toga, slacks">
    </div>
    <div class="cat-field">
      <label for="f-level">Education level</label>
      <select id="f-level" name="level">
        <option value="">All levels</option>
<?php foreach (catalog_level_groups() as $key => $group): ?>
        <option value="<?= e($key) ?>"<?= catalog_selected($f['level'], $key) ?>><?= e($group['label']) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="cat-field">
      <label for="f-category">Category</label>
      <select id="f-category" name="category">
        <option value="">All categories</option>
<?php foreach ($categories as $c): ?>
        <option value="<?= e((string) $c['category_id']) ?>"<?= catalog_selected($f['category'], $c['category_id']) ?>><?= e($c['name']) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="cat-field">
      <label for="f-avail">Availability</label>
      <select id="f-avail" name="avail">
<?php foreach (catalog_availability_options() as $key => $label): ?>
        <option value="<?= e((string) $key) ?>"<?= catalog_selected($f['avail'], $key) ?>><?= e($label) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="cat-field">
      <label for="f-sort">Sort by</label>
      <select id="f-sort" name="sort">
<?php foreach (catalog_sort_options() as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= catalog_selected($f['sort'], $key) ?>><?= e($label) ?></option>
<?php endforeach; ?>
      </select>
    </div>
    <div class="cat-field cat-field--actions">
      <button type="submit" class="cat-btn cat-btn--primary">Apply</button>
<?php if ($filtered): ?>
      <a class="cat-btn" href="<?= e(url('catalog.php')) ?>">Clear filters</a>
<?php endif; ?>
    </div>
  </form>

<?php if ($total === 0): ?>
  <div class="cat-empty">
    <h2>No items match</h2>
    <p><?= $filtered
        ? 'Try a different spelling, or remove a filter to see more items.'
        : 'There are no items in the catalog yet. Please check back soon.' ?></p>
<?php if ($filtered): ?>
    <p><a class="cat-btn cat-btn--primary" href="<?= e(url('catalog.php')) ?>">Show all items</a></p>
<?php endif; ?>
  </div>
<?php else: ?>
  <p class="cat-count" aria-live="polite">Showing <?= e((string) $first) ?> to <?= e((string) $last) ?> of <?= e((string) $total) ?> <?= $total === 1 ? 'item' : 'items' ?></p>
  <div class="cat-grid">
<?php foreach ($items as $item):
        $image = $images[(int) $item['item_id']][0] ?? null;
        echo catalog_render_card($item, $image);   // built with e() inside
      endforeach; ?>
  </div>
<?php if ($total_pages > 1): ?>
  <nav class="cat-pager" aria-label="Catalog pages">
    <?= pagination_links($pager, 'catalog.php', $link_query) ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
</div>
<?php require CATALOG_FOOTER; ?>
