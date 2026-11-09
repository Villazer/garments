<?php
/**
 * Public order tracking. A guest proves ownership with the reference number plus
 * the contact number or email used on the order. After a successful lookup the
 * browser is remembered (session) so the page can be reloaded via ?ref= without
 * putting the contact data in the URL.
 */
require __DIR__ . '/includes/catalog_bootstrap.php';
require_once __DIR__ . '/includes/public5_common.php';
require_once __DIR__ . '/includes/track_lib.php';

$error = null;
$ref_input = clean_str($_GET['ref'] ?? '');
$order = null;

if (is_post()) {
    csrf_require();
    $action = clean_str($_POST['action'] ?? 'lookup');

    if ($action === 'cancel') {
        $row = track_fetch_order(s5_clean_ref($_POST['ref'] ?? ''));
        if ($row && s5_can_view($row['order_ref']) && track_can_cancel($row)) {
            $res = track_cancel_order((int) $row['order_id']);
            flash_set($res['ok'] ? 'success' : 'error', $res['message']);
            redirect('track.php', ['ref' => $row['order_ref']]);
        }
        flash_set('error', 'This order can no longer be cancelled online. Please visit the Garment Shop if you need help.');
        redirect('track.php', ['ref' => s5_clean_ref($_POST['ref'] ?? '')]);
    }

    // Lookup: same generic error for every kind of mismatch.
    $ref_input = clean_str($_POST['ref'] ?? '');
    $result = track_attempt($ref_input, (string) ($_POST['contact'] ?? ''));
    if ($result['ok']) {
        s5_grant_view($result['order']['order_ref']);
        redirect('track.php', ['ref' => $result['order']['order_ref']]);
    }
    $error = $result['error'];
} elseif ($ref_input !== '') {
    $row = track_fetch_order(s5_clean_ref($ref_input));
    if ($row && s5_can_view($row['order_ref'])) {
        $order = $row;
    }
}

$page_title = 'Track your order';
$nav_active = 'track';
require CATALOG_HEADER;
echo s5_asset_tags(true);
flash_show();
?>
<main class="s5-page" id="main">
  <h1>Track your order</h1>

<?php if ($order === null): ?>
  <p class="s5-lead">Enter the reference number from your confirmation and the contact number or email you used when ordering.</p>
  <?php if ($error): ?>
    <div class="s5-alert s5-alert--error" role="alert"><p><?= e($error) ?></p></div>
  <?php endif; ?>
  <form method="post" action="<?= e(url('track.php')) ?>" class="s5-card" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="lookup">
    <div class="s5-field">
      <label for="ref">Reference number</label>
      <input class="s5-input" id="ref" name="ref" type="text" maxlength="24" required autocomplete="off"
             autocapitalize="characters" spellcheck="false" placeholder="GRM-2026-ABC234" value="<?= e($ref_input) ?>">
    </div>
    <div class="s5-field">
      <label for="contact">Contact number or email</label>
      <input class="s5-input" id="contact" name="contact" type="text" maxlength="120" required autocomplete="off"
             placeholder="09171234567 or name@example.com">
      <p class="s5-hint">Use the same one you entered when you placed the order.</p>
    </div>
    <button class="s5-btn s5-btn--block" type="submit">Track order</button>
  </form>

<?php else:
    $o = $order;
    $items = track_items((int) $o['order_id']);
    $timeline = track_timeline($o);
    $next = track_next_step($o);
    $count = track_countdown($o);
    $slip_ready = in_array($o['status'], ['slip_issued', 'paid', 'claimed', 'unclaimed'], true);
    $confirm_page = ($o['order_type'] === 'rental') ? 'toga_confirmation.php' : 'confirmation.php';
?>
  <div class="s5-card">
    <p class="s5-hint" style="margin:0">Reference number</p>
    <p class="s5-ref"><?= e($o['order_ref']) ?></p>
    <p><?= status_badge($o['status']) ?></p>
  </div>

  <h2>Progress</h2>
  <ol class="s5-timeline" aria-label="Order progress">
    <?php foreach ($timeline as $i => $step): ?>
      <li class="s5-step s5-step--<?= e($step['state']) ?>"<?= $step['state'] === 'current' ? ' aria-current="step"' : '' ?>>
        <span class="s5-dot" aria-hidden="true"><?= $step['state'] === 'done' ? '&#10003;' : ($step['state'] === 'exit' ? '&#10005;' : (int) ($i + 1)) ?></span>
        <b><?= e($step['label']) ?></b>
        <small><?= $step['when'] ? e(format_datetime($step['when'])) : ($step['state'] === 'todo' ? 'Pending' : '') ?></small>
      </li>
    <?php endforeach; ?>
  </ol>

  <div class="s5-alert s5-alert--<?= e($next['tone']) ?>">
    <h3>Next step: <?= e($next['title']) ?></h3>
    <p><?= e($next['text']) ?></p>
  </div>

  <?php if ($count !== null): ?>
    <div class="s5-card">
      <span class="s5-hint"><?= e($count['label']) ?></span><br>
      <?php if ($count['seconds'] > 0): ?>
        <span class="s5-countdown" data-countdown="<?= (int) $count['seconds'] ?>" aria-live="off"><?= e(format_datetime($count['at'])) ?></span>
        <p class="s5-hint">Deadline: <?= e(format_datetime($count['at'])) ?></p>
      <?php else: ?>
        <span class="s5-countdown">Time is up</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <h2>Order details</h2>
  <div class="s5-card">
    <dl class="s5-meta">
      <div><dt>Type</dt><dd><?= e(s5_order_type_label($o['order_type'])) ?></dd></div>
      <div><dt>Placed</dt><dd><?= e(format_datetime($o['created_at'])) ?></dd></div>
      <div><dt>Order Slip No.</dt><dd><?= $slip_ready ? e($o['order_no']) : 'To be issued' ?></dd></div>
      <?php if (!empty($o['student_name'])): ?>
        <div><dt>For student</dt><dd><?= e($o['student_name']) ?></dd></div>
      <?php endif; ?>
    </dl>
    <div class="s5-tablewrap">
      <table class="s5-table">
        <thead><tr><th>Item</th><th>Size</th><th class="s5-num">Qty</th><th class="s5-num">Price</th><th class="s5-num">Total</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= e($it['item_name']) ?></td><td><?= e($it['size_label']) ?></td>
            <td class="s5-num"><?= (int) $it['quantity'] ?></td>
            <td class="s5-num"><?= e(money($it['unit_price'])) ?></td>
            <td class="s5-num"><?= e(money($it['line_total'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="4" class="s5-num">Total</th><th class="s5-num"><?= e(money($o['total_amount'])) ?></th></tr></tfoot>
      </table>
    </div>
    <?php if ($o['order_type'] === 'rental'): ?>
      <p class="s5-hint">Toga deposit, rental fee details and return rules are confirmed by the Garment Shop.</p>
    <?php endif; ?>
  </div>

  <p>
    <?php if ($slip_ready): ?>
      <a class="s5-btn" href="<?= e(url('slip.php', ['ref' => $o['order_ref']])) ?>">View printable Order Slip</a>
    <?php endif; ?>
    <a class="s5-btn s5-btn--ghost" href="<?= e(url($confirm_page, ['ref' => $o['order_ref']])) ?>">View confirmation</a>
  </p>

  <?php if (track_can_cancel($o)): ?>
    <details class="s5-cancel s5-card">
      <summary>Cancel this order</summary>
      <p>Cancelling releases any reserved stock so other students can use it. This cannot be undone. You would need to place a new request.</p>
      <form method="post" action="<?= e(url('track.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="ref" value="<?= e($o['order_ref']) ?>">
        <button class="s5-btn s5-btn--danger" type="submit">Yes, cancel my order</button>
      </form>
    </details>
  <?php endif; ?>

  <p><a href="<?= e(url('track.php')) ?>">Track a different order</a></p>
<?php endif; ?>

  <?= s5_quicklinks_html() ?>
</main>
<?php require CATALOG_FOOTER;
