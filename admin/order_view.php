<?php
/** Staff order detail: customer, items, payments, history, notes and the actions for the current status. */
require __DIR__ . '/_init.php';
$user = require_can('orders.view');
require_once AUTH_ROOT . '/includes/orders.php';

/* ---------------------------------------------------------------- POST */
if (is_post()) {
    $user = require_can('orders.process');
    csrf_require();
    $id = (int) ($_POST['id'] ?? 0);
    $do = clean_str($_POST['do'] ?? '');

    if ($do === 'note') {
        $res = ord_add_note($id, (int) $user['user_id'], (string) ($_POST['note'] ?? ''));
        flash_set($res['ok'] ? 'success' : 'error', $res['message']);
        redirect('admin/order_view.php', ['id' => $id]);
    }

    $to = clean_str($_POST['to'] ?? '');
    $data = [];
    foreach (['reason', 'measured_by', 'pickup_date', 'oic_manager_id', 'deposit', 'note', 'or_no', 'amount_paid',
              'deposit_amount', 'paid_date', 'received_by', 'checked_by', 'claim_date'] as $k) {
        if (isset($_POST[$k]) && is_string($_POST[$k])) {
            $data[$k] = $_POST[$k];
        }
    }
    $res = transition_order($id, $to, (int) $user['user_id'], $data);
    if ($res['ok']) {
        flash_set('success', $res['message']);
        if ($to === 'slip_issued' && $res['kind'] === 'forward') {
            redirect('admin/order_slip.php', ['id' => $id]);   // open the printable slip right after issuing
        }
        redirect('admin/order_view.php', ['id' => $id]);
    }
    flash_set('error', $res['message']);
    remember_input($_POST);
    redirect('admin/order_view.php', ['id' => $id, 'open' => $to]);
}

/* ----------------------------------------------------------------- GET */
$id = (int) ($_GET['id'] ?? 0);
$order = $id > 0 ? ord_fetch($id) : null;
if (!$order) {
    staff_not_found();
}
$items = ord_lines($id);
$payments = ord_payments($id);
$rentals = ord_rentals($id);
$history = ord_history($order);
$in_bin = $order['deleted_at'] !== null;
$moves = $in_bin ? ['forward' => [], 'revert' => []] : ord_available_moves($order['status'], $user['role']);
$can_note = can('orders.process', $user['role']);
$open = clean_str($_GET['open'] ?? '');
$status = $order['status'];
$urg = ord_urgency($order);
$slip_ready = in_array($status, ['slip_issued', 'paid', 'claimed', 'unclaimed'], true);
$staff = ord_staff_options();
$managers = ord_staff_options(['manager', 'admin']);
$has_rental = (bool) $rentals;
$reason_text = order_public_reason($order);

/** Reason-only action (decline, cancel, revert) as a collapsible form. */
function s6_reason_action(int $id, string $to, string $title, string $button, string $hint, string $open, string $btn_class = 'btn-danger'): void
{
    ?>
    <details class="s6-action" <?= $open === $to ? 'open' : '' ?>>
      <summary><?= e($title) ?></summary>
      <form method="post" action="<?= e(url('admin/order_view.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="to" value="<?= e($to) ?>">
        <label>Reason <small class="s6-muted"><?= e($hint) ?></small>
          <textarea name="reason" rows="2" maxlength="255" required><?= $open === $to ? e(old('reason')) : '' ?></textarea>
        </label>
        <button type="submit" class="btn <?= e($btn_class) ?>"><?= e($button) ?></button>
      </form>
    </details>
    <?php
}

$page_title = 'Order ' . $order['order_ref'];
$nav_active = 'orders';
require AUTH_ROOT . '/includes/staff_header.php';
?>
<link rel="stylesheet" href="<?= e(asset('css/orders.css')) ?>">

<p><a href="<?= e(url('admin/orders.php')) ?>">&larr; All orders</a></p>

<div class="s6-head">
  <h1><?= e($order['order_ref']) ?> <?= status_badge($status) ?></h1>
  <p class="s6-muted">
    Slip no.: <?= $slip_ready ? '<strong>' . e($order['order_no']) . '</strong>' : 'not issued yet' ?>
    · <?= e(ucfirst($order['order_type'])) ?> · <?= $order['source'] === 'walk_in' ? 'Walk-in' : 'Online' ?>
    · placed <?= e(format_datetime($order['created_at'])) ?>
  </p>
  <?php if ($in_bin): ?><p class="s6-flag s6-flag-overdue">This order is in the recycle bin. Restore it before making changes.</p><?php endif; ?>
  <?php if ($urg): ?><p class="s6-flag s6-flag-<?= e($urg['level']) ?>"><?= e($urg['text']) ?></p><?php endif; ?>
  <?php if ($reason_text !== ''): ?><p class="s6-reason"><strong>Reason shown to the customer:</strong> <?= e($reason_text) ?></p><?php endif; ?>
</div>

<div class="s6-grid">
  <section class="s6-card">
    <h2>Customer</h2>
    <dl class="s6-dl">
      <dt>Name</dt><dd><?= e($order['customer_name']) ?> <small class="s6-muted">(<?= e($order['relationship']) ?>)</small></dd>
      <dt>Contact no.</dt><dd><?= e($order['contact_no']) ?></dd>
      <dt>Email</dt><dd><?= $order['customer_email'] ? e($order['customer_email']) : '—' ?></dd>
      <dt>Address</dt><dd><?= $order['customer_address'] ? e($order['customer_address']) : '—' ?></dd>
    </dl>
    <h2>Student</h2>
    <?php if ($order['student_id']): ?>
    <dl class="s6-dl">
      <dt>Name</dt><dd><?= e($order['student_name']) ?></dd>
      <dt>Student no.</dt><dd><?= e($order['student_no']) ?></dd>
      <dt>Level</dt><dd><?= e($order['level_name'] ?? '—') ?></dd>
      <dt>Course &amp; year</dt><dd><?= e(trim(($order['course_code'] ?? '') . ' ' . ($order['year_level'] ?? '')) ?: '—') ?></dd>
    </dl>
    <?php else: ?><p class="s6-muted">No student record on this order.</p><?php endif; ?>
  </section>

  <section class="s6-card">
    <h2>Progress</h2>
    <dl class="s6-dl">
      <dt>Approved</dt><dd><?= $order['approved_at'] ? e(format_datetime($order['approved_at'])) . ' · ' . e($order['approved_by_name'] ?? 'automatic') : '—' ?></dd>
      <dt>Hold ends</dt><dd><?= e(format_datetime($order['expires_at'])) ?></dd>
      <dt>Slip issued</dt><dd><?= e(format_datetime($order['slip_issued_at'])) ?></dd>
      <dt>Pickup date</dt><dd><?= e(format_date($order['pickup_date'])) ?></dd>
      <dt>Measure/Cutter</dt><dd><?= e($order['measured_by_name'] ?? '—') ?></dd>
      <dt>OIC-Manager</dt><dd><?= e($order['oic_name'] ?? '—') ?></dd>
      <dt>Paid</dt><dd><?= e(format_datetime($order['paid_at'])) ?></dd>
      <dt>Claim by</dt><dd><?= e(format_datetime($order['claim_deadline'])) ?></dd>
      <dt>Claimed</dt><dd><?= $order['claimed_at'] ? e(format_datetime($order['claimed_at'])) : '—' ?></dd>
      <dt>Received by</dt><dd><?= e($order['received_by'] ?? '—') ?></dd>
      <dt>Checked by</dt><dd><?= e($order['checked_by_name'] ?? '—') ?></dd>
    </dl>
  </section>
</div>

<section class="s6-card">
  <h2>Items</h2>
  <div class="s6-table-wrap">
    <table class="s6-table">
      <thead><tr><th>Item</th><th>Size</th><th class="s6-num">Qty</th><th class="s6-num">Unit price</th><th class="s6-num">Line total</th><th>Stock</th></tr></thead>
      <tbody>
      <?php foreach ($items as $l): ?>
        <tr>
          <td data-label="Item"><?= e($l['item_name']) ?></td>
          <td data-label="Size"><?= e($l['size_label']) ?></td>
          <td data-label="Qty" class="s6-num"><?= (int) $l['quantity'] ?></td>
          <td data-label="Unit price" class="s6-num"><?= e(money($l['unit_price'])) ?></td>
          <td data-label="Line total" class="s6-num"><?= e(money($l['line_total'])) ?></td>
          <td data-label="Stock"><?= (int) $l['tracks_stock'] === 0 ? '<span class="s6-muted">Not tracked</span>' : ((int) $l['stock_held'] === 1 ? 'Reserved' : '<span class="s6-muted">Not held</span>') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th colspan="4" class="s6-num">Total</th><th class="s6-num"><?= e(money($order['total_amount'])) ?></th><th></th></tr></tfoot>
    </table>
  </div>
  <?php foreach ($rentals as $r): ?>
    <p class="s6-muted">Toga rental: <?= e($r['status']) ?>, due <?= e(format_date($r['due_date'])) ?><?= $r['deposit_amount'] !== null ? ', deposit ' . e(money($r['deposit_amount'])) : '' ?>.</p>
  <?php endforeach; ?>
  <?php if ($order['remarks']): ?><p><strong>Customer remarks:</strong> <?= e($order['remarks']) ?></p><?php endif; ?>
</section>

<?php if ($payments): ?>
<section class="s6-card">
  <h2>Payment records</h2>
  <div class="s6-table-wrap">
    <table class="s6-table">
      <thead><tr><th>OR no.</th><th>Date</th><th class="s6-num">Amount paid</th><th class="s6-num">Deposit</th><th>Recorded by</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td data-label="OR no."><?= e($p['or_no']) ?></td>
          <td data-label="Date"><?= e(format_datetime($p['paid_at'])) ?></td>
          <td data-label="Amount paid" class="s6-num"><?= e(money($p['amount_paid'])) ?></td>
          <td data-label="Deposit" class="s6-num"><?= e(money($p['deposit_amount'])) ?></td>
          <td data-label="Recorded by"><?= e($p['verified_by_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="s6-card">
  <h2>Actions</h2>
  <?php if (!$can_note): ?>
    <p class="s6-muted">Your role can view orders but not change them.</p>
  <?php elseif ($in_bin): ?>
    <p class="s6-muted">No actions while the order is in the recycle bin.</p>
  <?php elseif (!$moves['forward'] && !$moves['revert'] && !$slip_ready): ?>
    <p class="s6-muted">No further actions are available for this status.</p>
  <?php endif; ?>

  <?php if ($slip_ready): ?>
    <p class="s6-actions-row">
      <a class="btn" href="<?= e(url('admin/order_slip.php', ['id' => $id])) ?>">View order slip</a>
      <a class="btn" href="<?= e(url('admin/order_slip.php', ['id' => $id, 'print' => 1])) ?>">Print order slip</a>
    </p>
  <?php endif; ?>

  <?php if (in_array('approved', $moves['forward'], true)): ?>
    <form method="post" action="<?= e(url('admin/order_view.php')) ?>" class="s6-inline">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="move"><input type="hidden" name="to" value="approved">
      <button type="submit" class="btn btn-primary">Approve and reserve stock</button>
      <small class="s6-muted">Checks availability again; nothing is reserved if any line is short.</small>
    </form>
  <?php endif; ?>

  <?php if (in_array('slip_issued', $moves['forward'], true)): $o = $open === 'slip_issued'; ?>
    <details class="s6-action" <?= $status === 'approved' || $o ? 'open' : '' ?>>
      <summary>Issue order slip</summary>
      <form method="post" action="<?= e(url('admin/order_view.php')) ?>" class="s6-form">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="move"><input type="hidden" name="to" value="slip_issued">
        <p class="s6-muted">The official slip number is assigned automatically (<?= e(ord_format_slip_no((string) get_setting('order_slip_format', ORD_DEFAULT_SLIP_FORMAT), (string) get_setting('order_no_prefix', 'OS'), date('Y'), 1)) ?> style).</p>
        <label>Measure/Cutter
          <select name="measured_by"><option value="">— none —</option>
            <?php foreach ($staff as $s): ?><option value="<?= (int) $s['user_id'] ?>" <?= $o && (string) old('measured_by') === (string) $s['user_id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option><?php endforeach; ?>
          </select></label>
        <label>OIC-Manager (if the slip needs it)
          <select name="oic_manager_id"><option value="">— none —</option>
            <?php foreach ($managers as $s): ?><option value="<?= (int) $s['user_id'] ?>" <?= $o && (string) old('oic_manager_id') === (string) $s['user_id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option><?php endforeach; ?>
          </select></label>
        <label>Pickup date <input type="date" name="pickup_date" min="<?= e(date('Y-m-d')) ?>" value="<?= $o ? e(old('pickup_date')) : '' ?>"></label>
        <?php if ($has_rental): ?>
          <label>Toga deposit (₱) <small class="s6-muted">optional, amount to be confirmed with the shop</small>
            <input type="text" name="deposit" inputmode="decimal" value="<?= $o ? e(old('deposit')) : '' ?>"></label>
        <?php endif; ?>
        <label>Measure/cutter notes <small class="s6-muted">kept in the order history</small>
          <textarea name="note" rows="2" maxlength="500"><?= $o ? e(old('note')) : '' ?></textarea></label>
        <button type="submit" class="btn btn-primary">Issue slip and open it</button>
      </form>
    </details>
  <?php endif; ?>

  <?php if (in_array('paid', $moves['forward'], true)): $o = $open === 'paid'; ?>
    <details class="s6-action" <?= $status === 'slip_issued' || $o ? 'open' : '' ?>>
      <summary>Record payment (from the cashier's proof of payment)</summary>
      <form method="post" action="<?= e(url('admin/order_view.php')) ?>" class="s6-form">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="move"><input type="hidden" name="to" value="paid">
        <p class="s6-muted">The shop does not take payment. Enter what the official receipt shows. Order total: <strong><?= e(money($order['total_amount'])) ?></strong></p>
        <label>OR number <input type="text" name="or_no" maxlength="30" required value="<?= $o ? e(old('or_no')) : '' ?>"></label>
        <label>Total amount on the receipt (₱) <input type="text" name="amount_paid" inputmode="decimal" required value="<?= e($o ? old('amount_paid') : number_format((float) $order['total_amount'], 2, '.', '')) ?>"></label>
        <label>Refundable deposit included (₱) <small class="s6-muted">leave blank if none</small>
          <input type="text" name="deposit_amount" inputmode="decimal" value="<?= $o ? e(old('deposit_amount')) : '' ?>"></label>
        <label>Payment date <input type="date" name="paid_date" max="<?= e(date('Y-m-d')) ?>" required value="<?= e($o ? old('paid_date', date('Y-m-d')) : date('Y-m-d')) ?>"></label>
        <button type="submit" class="btn btn-primary">Save payment record</button>
      </form>
    </details>
  <?php endif; ?>

  <?php if (in_array('claimed', $moves['forward'], true)): $o = $open === 'claimed'; ?>
    <details class="s6-action" <?= in_array($status, ['paid', 'unclaimed'], true) || $o ? 'open' : '' ?>>
      <summary>Record claim</summary>
      <form method="post" action="<?= e(url('admin/order_view.php')) ?>" class="s6-form">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="move"><input type="hidden" name="to" value="claimed">
        <label>Received by <input type="text" name="received_by" maxlength="120" required value="<?= e($o ? old('received_by') : $order['customer_name']) ?>"></label>
        <label>Checked by
          <select name="checked_by">
            <?php foreach ($staff as $s): $sel = $o ? (string) old('checked_by') : (string) $user['user_id']; ?>
              <option value="<?= (int) $s['user_id'] ?>" <?= $sel === (string) $s['user_id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label>OIC-Manager (if the slip needs it)
          <select name="oic_manager_id"><option value="">— <?= $order['oic_name'] ? e($order['oic_name']) . ' (as on slip)' : 'none' ?> —</option>
            <?php foreach ($managers as $s): ?><option value="<?= (int) $s['user_id'] ?>" <?= $o && (string) old('oic_manager_id') === (string) $s['user_id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option><?php endforeach; ?>
          </select></label>
        <label>Claim date <small class="s6-muted">leave blank for now</small>
          <input type="date" name="claim_date" max="<?= e(date('Y-m-d')) ?>" value="<?= $o ? e(old('claim_date')) : '' ?>"></label>
        <button type="submit" class="btn btn-primary">Mark as claimed</button>
      </form>
    </details>
  <?php endif; ?>

  <?php
  if (in_array('declined', $moves['forward'], true)) {
      s6_reason_action($id, 'declined', 'Decline order', 'Decline order', 'Shown to the customer on the tracking page.', $open);
  }
  if (in_array('expired', $moves['forward'], true)) {
      echo '<details class="s6-action"><summary>Mark as expired</summary><form method="post" action="' . e(url('admin/order_view.php')) . '">'
         . csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="do" value="move"><input type="hidden" name="to" value="expired">'
         . '<p class="s6-muted">Releases the reserved stock and moves the order to the recycle bin.</p>'
         . '<button type="submit" class="btn btn-danger">Mark as expired</button></form></details>';
  }
  if (in_array('cancelled', $moves['forward'], true)) {
      s6_reason_action($id, 'cancelled', 'Cancel order', 'Cancel order', 'Shown to the customer on the tracking page.', $open);
  }
  foreach ($moves['revert'] as $to) {
      s6_reason_action($id, $to, 'Revert to ' . ord_label($to) . ' (manager)', 'Revert to ' . ord_label($to), 'Required. Recorded in the history.', $open, 'btn');
  }
  ?>
</section>

<section class="s6-card">
  <h2>History and notes</h2>
  <ol class="s6-timeline">
    <?php foreach (array_reverse($history) as $h): ?>
      <li class="<?= $h['is_note'] ? 's6-note' : '' ?>">
        <strong><?= e($h['label']) ?></strong>
        <small class="s6-muted"><?= e(format_datetime($h['when'])) ?> · <?= e($h['who']) ?></small>
        <?php if ($h['detail'] !== ''): ?><div><?= e($h['detail']) ?></div><?php endif; ?>
        <?php if ($h['reason'] !== ''): ?><div class="s6-quote"><?= e($h['reason']) ?></div><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($can_note): ?>
    <form method="post" action="<?= e(url('admin/order_view.php')) ?>" class="s6-form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="note">
      <label>Add a note <small class="s6-muted">staff only, not shown to the customer</small>
        <textarea name="note" rows="2" maxlength="500" required></textarea></label>
      <button type="submit" class="btn">Add note</button>
    </form>
  <?php endif; ?>
</section>

<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
