<?php
/** Public restock schedule: upcoming deliveries. Read-only. */
require __DIR__ . '/includes/catalog_bootstrap.php';
require_once __DIR__ . '/includes/public5_common.php';
require_once __DIR__ . '/includes/restock_lib.php';

$data = rs_upcoming();
$focus = (int) ($_GET['item'] ?? 0); // from an out-of-stock item page: highlight that item

$page_title = 'Restock schedule';
$nav_active = 'restock';
require CATALOG_HEADER;
echo s5_asset_tags(true);
flash_show();

/** One schedule row. */
function rs_render_row(array $r, int $focus, bool $show_status): void
{ ?>
  <li<?= $focus > 0 && (int) $r['item_id'] === $focus ? ' style="background:#fff8e1"' : '' ?>>
    <span>
      <a href="<?= e(url('item.php', ['id' => (int) $r['item_id']])) ?>"><?= e($r['item_name']) ?></a>
      &ndash; <?= e(rs_size_text($r)) ?>
      <?php if ($show_status): ?><span class="s5-pill s5-pill--warn"><?= $r['status'] === 'delayed' ? 'Delayed' : 'Date to be confirmed' ?></span><?php endif; ?>
    </span>
    <span>
      <?php if ($r['variant_id'] !== null): ?><?= stock_badge((int) $r['available'], (int) $r['low_stock_threshold']) ?><?php endif; ?>
      <?php if (rs_can_waitlist($r)): ?> <a href="<?= e(url('waitlist.php', ['variant' => (int) $r['variant_id']])) ?>">Join waitlist</a><?php endif; ?>
    </span>
  </li>
<?php } ?>
<main class="s5-page" id="main">
  <h1>Restock schedule</h1>
  <p class="s5-lead">Items the Garment Shop expects to receive soon.</p>
  <div class="s5-alert s5-alert--warning">
    <h3>Dates can change</h3>
    <p>Delivery dates depend on suppliers and may move because of delivery issues. Items are only available to reserve once they are back in stock and shown as available in the catalog.</p>
  </div>

<?php if (!$data['dated'] && !$data['unconfirmed']): ?>
  <div class="s5-card"><p>No deliveries are scheduled at the moment. Check back later or <a href="<?= e(url('waitlist.php')) ?>">join the waitlist</a> for an out-of-stock size.</p></div>
<?php endif; ?>

<?php foreach ($data['dated'] as $date => $rows): ?>
  <section class="s5-day s5-card">
    <h3>Expected <?= e(format_date($date, 'l, F j, Y')) ?></h3>
    <ul class="s5-list"><?php foreach ($rows as $r) { rs_render_row($r, $focus, false); } ?></ul>
  </section>
<?php endforeach; ?>

<?php if ($data['unconfirmed']): ?>
  <section class="s5-day s5-card">
    <h3>Delayed or waiting for a new date</h3>
    <p class="s5-hint">The shop has not confirmed a new delivery date for these yet.</p>
    <ul class="s5-list"><?php foreach ($data['unconfirmed'] as $r) { rs_render_row($r, $focus, true); } ?></ul>
  </section>
<?php endif; ?>

  <?= s5_quicklinks_html() ?>
</main>
<?php require CATALOG_FOOTER;
