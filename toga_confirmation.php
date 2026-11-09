<?php
/** Confirmation page for a toga rental request. */
require __DIR__ . '/includes/catalog_bootstrap.php';
require_once __DIR__ . '/includes/public5_common.php';
require_once __DIR__ . '/includes/track_lib.php';
require_once __DIR__ . '/includes/toga_lib.php';

$ref = s5_clean_ref((string) ($_GET['ref'] ?? ''));
$o = $ref !== '' ? tog_fetch_confirmation($ref) : null;
if ($o === null) {
    redirect('track.php');
}
// Not the browser that placed it? Send them to the tracking page, which asks for the contact details.
if (!s5_can_view($o['order_ref'])) {
    redirect('track.php', ['ref' => $o['order_ref']]);
}

$status = $o['status'];
$period = ['school_year' => $o['school_year'] ?? '', 'semester' => $o['semester'] ?? ''];

$page_title = 'Toga request received';
$nav_active = 'toga';
require CATALOG_HEADER;
echo s5_asset_tags(true);
flash_show();
?>
<main class="s5-page" id="main">
  <h1>Your toga request was received</h1>

  <div class="s5-card">
    <p class="s5-hint" style="margin:0">Your reference number. Keep it to track your request.</p>
    <p class="s5-ref"><?= e($o['order_ref']) ?></p>
    <button class="s5-btn s5-btn--ghost" type="button" data-copy="<?= e($o['order_ref']) ?>">Copy reference number</button>
  </div>

  <div class="s5-alert s5-alert--<?= $status === 'approved' ? 'success' : 'info' ?>">
    <h3><?= $status === 'approved' ? 'Your toga is reserved' : 'Waiting for the shop to review your request' ?></h3>
    <p><?php if ($status === 'approved'): ?>
      Stock is being held for you<?= !empty($o['expires_at']) ? ' until ' . e(format_datetime($o['expires_at'])) : '' ?>. The shop will prepare your Order Slip. Pay at the cashier, then return to the Garment Shop with your proof of payment.
    <?php else: ?>
      No toga is held yet. Once staff approve the request, your toga is reserved and the shop prepares your Order Slip. Then you pay at the cashier and return with your proof of payment.
    <?php endif; ?></p>
  </div>

  <div class="s5-alert s5-alert--warning">
    <h3>Deposit and rental terms</h3>
    <p>The deposit amount, final rental fee, how long you may keep the toga and any penalty rules are confirmed by the Garment Shop. Nothing about them is final on this page.</p>
  </div>

  <div class="s5-card">
    <dl class="s5-meta">
      <div><dt>Toga</dt><dd><?= e($o['item_name']) ?></dd></div>
      <div><dt>Size</dt><dd><?= e($o['size_label']) ?></dd></div>
      <div><dt>Listed fee</dt><dd><?= e(money($o['unit_price'])) ?></dd></div>
      <div><dt>Student</dt><dd><?= e($o['student_name']) ?></dd></div>
      <div><dt>Student no.</dt><dd><?= e($o['student_no']) ?></dd></div>
      <div><dt>Academic period</dt><dd><?= e($period['school_year'] !== '' ? s5_period_label($period) : '—') ?></dd></div>
    </dl>
  </div>

  <p>
    <a class="s5-btn" href="<?= e(url('track.php', ['ref' => $o['order_ref']])) ?>">Track this request</a>
    <a class="s5-btn s5-btn--ghost" href="<?= e(url('catalog.php')) ?>">Back to catalog</a>
  </p>
  <?= s5_quicklinks_html() ?>
</main>
<?php require CATALOG_FOOTER;
