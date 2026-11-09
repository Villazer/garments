<?php
declare(strict_types=1);

/* Item detail (Session 3): images, description, one row per size with live price and stock. Read-only. */
require __DIR__ . '/includes/catalog_bootstrap.php';

$id = (is_string($_GET['id'] ?? null) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
$item = $id > 0 ? catalog_find_item($id) : null;
$variants = $item ? catalog_item_variants($id) : [];
if (!$item || !$variants) {
    // Unknown, inactive, or no active sizes: treat all the same.
    abort(404, 'We could not find that item. It may have been removed from the catalog.');
}

$is_rental  = $item['item_type'] === 'rental';
$is_walkin  = $item['reservation_mode'] === 'none';
$tracks     = (int) $item['tracks_stock'] === 1;
$cap        = max(1, (int) $item['max_qty_per_request']);
$images     = catalog_images_for([$item])[$id];
$restocks   = $tracks ? catalog_restock_dates($id) : [];
$period     = $is_rental ? catalog_current_period() : null;

// Item-level badge from the same rules the catalog cards use.
$summary = $item + [
    'sizes_available' => 0, 'sizes_ok' => 0,
    'min_price' => min(array_column($variants, 'unit_price')),
    'max_price' => max(array_column($variants, 'unit_price')),
];
foreach ($variants as $v) {
    $state = catalog_variant_status($v, $item);
    if ($state === 'in' || $state === 'low') {
        $summary['sizes_available']++;
    }
    if ($state === 'in') {
        $summary['sizes_ok']++;
    }
}
$item_status = catalog_item_status($summary);

function item_term_text(?array $period): string
{
    if (!$period) {
        return 'the end of the academic period';
    }
    $term = $period['semester'] === 'summer' ? 'summer term' : $period['semester'] . ' semester';
    return 'the end of the academic period (' . format_date($period['end_date']) . ', ' . $period['school_year'] . ' ' . $term . ')';
}

$page_title = (string) $item['name'];
$nav_active = 'catalog';
require CATALOG_HEADER;
echo catalog_asset_tags();
?>
<div class="cat-page">
  <p class="cat-back"><a class="cat-link" href="<?= e(url('catalog.php', ['category' => (int) $item['category_id']])) ?>">Back to <?= e($item['category_name']) ?></a></p>

  <div class="cat-detail">
    <div class="cat-gallery">
      <div class="cat-gallery__main" data-gallery-main>
<?php if ($images): ?>
        <img src="<?= e($images[0]['url']) ?>" alt="<?= e($images[0]['alt']) ?>">
<?php else: ?>
        <?= catalog_placeholder() ?>
<?php endif; ?>
      </div>
<?php if (count($images) > 1): ?>
      <ul class="cat-gallery__thumbs">
<?php foreach ($images as $n => $img): ?>
        <li><a href="<?= e($img['url']) ?>" data-gallery-thumb data-alt="<?= e($img['alt']) ?>" aria-label="Show photo <?= e((string) ($n + 1)) ?>">
          <img src="<?= e($img['url']) ?>" alt="" loading="lazy"></a></li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
    </div>

    <div class="cat-detail__info">
      <p class="cat-card__meta"><?= e(implode(', ', array_filter([(string) $item['category_name'], (string) ($item['level_name'] ?? '')]))) ?></p>
      <h1 class="cat-detail__title"><?= e($item['name']) ?></h1>
      <p class="cat-detail__price"><?= e(($is_rental ? 'Rental fee ' : '') . catalog_price_text($summary['min_price'], $summary['max_price'])) ?></p>
      <p><?= catalog_status_badge($item_status) ?></p>
<?php if (trim((string) $item['description']) !== ''): ?>
      <p class="cat-detail__desc"><?= nl2br(e((string) $item['description'])) ?></p>
<?php endif; ?>

<?php if ($is_rental): ?>
      <div class="cat-note">
        <h2>Toga rental information</h2>
        <ul>
          <li>Togas are rented, not bought. You return the set after graduation.</li>
          <li>Each student can rent one toga set per academic period.</li>
          <li>Rentals are due back at <?= e(item_term_text($period)) ?>.</li>
          <li>Ask the garment shop about the deposit and fees for damage or late return.</li>
        </ul>
      </div>
<?php endif; ?>
<?php if ($is_walkin): ?>
      <div class="cat-note">
        <h2>Available at the shop only</h2>
        <p>This item cannot be reserved online. Please visit the garment shop to buy it.</p>
      </div>
<?php endif; ?>
    </div>
  </div>

  <section aria-labelledby="sizes-title">
    <h2 id="sizes-title">Sizes, prices and stock</h2>
    <div class="cat-tablewrap">
      <table class="cat-sizes">
        <thead>
          <tr><th scope="col">Size</th><th scope="col">Price</th><th scope="col">Available</th><th scope="col"><span class="cat-sr">Action</span></th></tr>
        </thead>
        <tbody>
<?php foreach ($variants as $v):
        $vid     = (int) $v['variant_id'];
        $state   = catalog_variant_status($v, $item);
        $avail   = catalog_variant_available($v);
        $max_qty = $tracks ? min($cap, max(1, $avail)) : $cap;
        $date    = $restocks[$vid] ?? ($restocks[0] ?? null);
?>
          <tr>
            <th scope="row" data-label="Size"><?= e($v['size_label']) ?></th>
            <td data-label="Price"><?= e(money($v['unit_price'])) ?></td>
            <td data-label="Available">
<?php if ($tracks): ?>
              <?= e((string) $avail) ?> <?= catalog_status_badge($state) ?>
<?php else: ?>
              <?= catalog_status_badge($state) ?>
<?php endif; ?>
            </td>
            <td data-label="">
<?php if ($state === 'out' && !$is_walkin): ?>
              <p class="cat-soldout">This size is out of stock.<?= $date ? ' Expected restock: ' . e(format_date($date)) . '.' : '' ?></p>
              <a class="cat-btn" href="<?= e(url('waitlist.php', ['variant' => $vid])) ?>">Join the waitlist</a>
<?php elseif ($is_walkin): ?>
              <span class="cat-muted">Buy at the shop</span>
<?php elseif ($is_rental): ?>
              <a class="cat-btn cat-btn--primary" href="<?= e(url('toga_request.php', ['variant' => $vid])) ?>">Request rental</a>
<?php else: ?>
              <form class="cat-add" method="post" action="<?= e(url('reserve_add.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="variant_id" value="<?= e((string) $vid) ?>">
                <label class="cat-sr" for="qty-<?= e((string) $vid) ?>">Quantity for size <?= e($v['size_label']) ?></label>
                <input type="number" id="qty-<?= e((string) $vid) ?>" name="quantity" value="1" min="1" max="<?= e((string) $max_qty) ?>" inputmode="numeric">
                <button type="submit" class="cat-btn cat-btn--primary">Add to reservation list</button>
              </form>
<?php endif; ?>
            </td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php if (!$is_walkin && !$is_rental): ?>
    <p class="cat-muted">You can reserve up to <?= e((string) $cap) ?> of this item in one online request. Need more? Ask at the garment shop.</p>
<?php endif; ?>
<?php if (!$tracks): ?>
    <p class="cat-muted">This item is prepared for you, so there is no stock count. The shop will confirm details when it reviews your request.</p>
<?php endif; ?>
  </section>
</div>
<?php require CATALOG_FOOTER; ?>
