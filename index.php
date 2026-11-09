<?php
declare(strict_types=1);

/* Home page (Session 3). Replaces the Session 1 placeholder index.php. */
require __DIR__ . '/includes/catalog_bootstrap.php';

$announcement = catalog_announcement();
$restocks     = catalog_next_restocks(3);
$hold_text    = catalog_hold_text();

// Quick links. P.E. and toga links find their category by name so they survive id changes.
$pe_id   = catalog_category_id_like('P.E.');
$toga_id = catalog_category_id_like('toga');
$quick_links = [
    ['Primary',        'Primary school uniforms',      url('catalog.php', ['level' => 'primary'])],
    ['Secondary',      'Junior and Senior High', url('catalog.php', ['level' => 'secondary'])],
    ['Tertiary',       'College uniforms',   url('catalog.php', ['level' => 'tertiary'])],
    ['P.E. uniforms',  'All levels',         url('catalog.php', $pe_id ? ['category' => $pe_id] : [])],
    ['Toga rental',    'Graduation sets',    url('catalog.php', $toga_id ? ['category' => $toga_id] : [])],
];

$page_title = 'Home';
$nav_active = 'home';
require CATALOG_HEADER;
echo catalog_asset_tags();
?>
<div class="cat-page">

<?php if ($announcement): ?>
  <div class="cat-announce cat-announce--<?= e($announcement['style']) ?>" role="status">
    <?= e($announcement['text']) ?>
  </div>
<?php endif; ?>

  <section class="cat-hero">
    <h1>Uniforms and togas, reserved before you visit</h1>
    <p>Check sizes, prices and what is in stock at the WMSU Garment Shop, then reserve online.
       No account is needed. You pay at the cashier and claim your items at the shop.</p>
    <p class="cat-hero__actions">
      <a class="cat-btn cat-btn--primary" href="<?= e(url('catalog.php')) ?>">Browse the catalog</a>
      <a class="cat-btn" href="<?= e($quick_links[4][2]) ?>">See toga rental</a>
    </p>
  </section>

  <section aria-labelledby="quick-title">
    <h2 id="quick-title">Find your uniform</h2>
    <ul class="cat-tiles">
<?php foreach ($quick_links as [$label, $sub, $href]): ?>
      <li><a class="cat-tile" href="<?= e($href) ?>">
        <span class="cat-tile__label"><?= e($label) ?></span>
        <span class="cat-tile__sub"><?= e($sub) ?></span>
      </a></li>
<?php endforeach; ?>
    </ul>
  </section>

  <section aria-labelledby="how-title">
    <h2 id="how-title">How reserving works</h2>
    <ol class="cat-steps">
      <li><strong>Reserve online.</strong> Pick the items and sizes you need and send your request.</li>
      <li><strong>Get your order slip.</strong> The garment shop confirms your request and prepares your slip.</li>
      <li><strong>Pay at the cashier.</strong> The cashier is beside the garment shop. Reservations that are not paid are released after <?= e($hold_text) ?>.</li>
      <li><strong>Claim at the garment shop.</strong> Show your proof of payment to receive your uniform or toga.</li>
    </ol>
  </section>

  <section aria-labelledby="restock-title">
    <h2 id="restock-title">Next restock dates</h2>
<?php if ($restocks): ?>
    <ul class="cat-restock">
<?php foreach ($restocks as $r):
        $label = (string) $r['item_name'] . ($r['size_label'] !== null ? ' (size ' . $r['size_label'] . ')' : ''); ?>
      <li><span class="cat-restock__date"><?= e(format_date($r['expected_date'])) ?></span>
          <span class="cat-restock__item"><?= e($label) ?></span></li>
<?php endforeach; ?>
    </ul>
    <p class="cat-muted">Dates are estimates and can change if a delivery is delayed.</p>
<?php else: ?>
    <p class="cat-muted">No restock dates are posted right now. Check back soon.</p>
<?php endif; ?>
  </section>

</div>
<?php require CATALOG_FOOTER; ?>
