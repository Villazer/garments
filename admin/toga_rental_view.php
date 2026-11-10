<?php
/**
 * One toga: approve or decline the request, issue the toga, record its return.
 * The page is keyed by the ORDER id, because a requested toga has no rentals row yet.
 * Every change goes through includes/rentals.php, which reuses Session 6's order rules.
 */
require __DIR__ . '/_init.php';
$user = require_can('rentals.view');
require_once AUTH_ROOT . '/includes/orders.php';
require_once AUTH_ROOT . '/includes/rentals.php';

/* ---------------------------------------------------------------- POST */
if (is_post()) {
    csrf_require();
    $id  = (int) ($_POST['id'] ?? 0);
    $do  = clean_str($_POST['do'] ?? '');
    $uid = (int) $user['user_id'];
    if ($do === 'approve' || $do === 'decline') {
        require_can('orders.process');
        $res = rent_decide($id, $do === 'approve' ? 'approved' : 'declined', $uid, (string) ($_POST['reason'] ?? ''));
    } elseif ($do === 'issue') {
        require_can('orders.process');
        $res = rent_issue($id, $uid, $_POST);
    } elseif ($do === 'return') {
        require_can('rentals.process');
        $res = rent_return((int) ($_POST['rental_id'] ?? 0), $uid, $_POST);
    } else {
        $res = ['ok' => false, 'message' => 'Unknown action.'];
    }
    flash_set($res['ok'] ? 'success' : 'error', $res['message']);
    if (!$res['ok']) {
        remember_input($_POST);
    }
    redirect('admin/toga_rental_view.php', ['id' => $id] + ($res['ok'] ? [] : ['open' => $do]));
}

/* ----------------------------------------------------------------- GET */
$id = (int) ($_GET['id'] ?? 0);
$order = $id > 0 ? ord_fetch($id) : null;
if (!$order || !rent_is_toga_order($id)) {
    staff_not_found();
}
$open = clean_str($_GET['open'] ?? '');

$page_title = 'Toga ' . $order['order_ref'];
$nav_active = 'rentals';
require AUTH_ROOT . '/includes/staff_header.php';
?>
<link rel="stylesheet" href="<?= e(asset('css/rentals.css')) ?>">
<p><a href="<?= e(url('admin/toga_rentals.php')) ?>">&larr; All toga rentals</a></p>
<?php
if (rent_migration_block()) {
    require AUTH_ROOT . '/includes/staff_footer.php';
    return;
}

$rental = rent_for_order($id);
$eff = rent_effective_status($order, $rental);
$lines = ord_lines($id);
$payments = ord_payments($id);
$status = $order['status'];
$staff = ord_staff_options();
$managers = ord_staff_options(['manager', 'admin']);
$can_process = can('orders.process', $user['role']);
$can_return = can('rentals.process', $user['role']);
$today = date('Y-m-d');
$pay_deposit = 0.0;
foreach ($payments as $pm) {
    $pay_deposit += (float) $pm['deposit_amount'];
}
$default_due = rent_default_due($today, $rental['period_end'] ?? null);
$late_days = ($eff === 'overdue' && $rental && $rental['due_date']) ? (int) floor((strtotime($today) - strtotime($rental['due_date'])) / 86400) : 0;
$staff_select = static function (string $name, array $people, string $selected, bool $blank = false): string {
    $h = '<select name="' . e($name) . '" id="' . e($name) . '">' . ($blank ? '<option value="">— none —</option>' : '');
    foreach ($people as $s) {
        $h .= '<option value="' . (int) $s['user_id'] . '"' . ((string) $s['user_id'] === $selected ? ' selected' : '') . '>' . e($s['full_name']) . '</option>';
    }
    return $h . '</select>';
};
$in_bin = $order['deleted_at'] !== null;
?>
<div class="s8-head">
  <h1><?= e($order['student_name'] ?? 'Toga') ?> <?= rent_status_badge($eff) ?></h1>
  <p class="s8-muted">Reference <strong><?= e($order['order_ref']) ?></strong> · order is <?= status_badge($status) ?>
    · <a href="<?= e(url('admin/order_view.php', ['id' => $id])) ?>">open the order</a>
    · <a href="<?= e(url('admin/customer_history.php', ['q' => (string) $order['student_no']])) ?>">student history</a></p>
  <?php if ($in_bin): ?><p class="s8-alert s8-alert-warn">This order is in the recycle bin. Restore it before making changes.</p><?php endif; ?>
  <?php if ($late_days > 0): ?><p class="s8-alert s8-alert-error" role="alert"><strong>Overdue by <?= $late_days ?> day<?= $late_days === 1 ? '' : 's' ?>.</strong> It was due on <?= e(format_date($rental['due_date'])) ?>.</p><?php endif; ?>
  <p class="s8-alert s8-alert-info"><?= e(rent_next_hint($eff, $order)) ?></p>
</div>

<div class="s8-grid">
  <section class="s8-card">
    <h2>Student and toga</h2>
    <dl class="s8-dl">
      <dt>Student</dt><dd><?= e($order['student_name'] ?? '—') ?></dd>
      <dt>Student no.</dt><dd><?= e($order['student_no'] ?? '—') ?></dd>
      <dt>Course &amp; year</dt><dd><?= e(trim(($order['course_code'] ?? '') . ' ' . ($order['year_level'] ?? '')) ?: '—') ?></dd>
      <dt>Requested by</dt><dd><?= e($order['customer_name']) ?> (<?= e($order['relationship']) ?>), <?= e($order['contact_no']) ?></dd>
      <?php foreach ($lines as $l): ?>
        <dt><?= $l['item_type'] === 'rental' ? 'Toga' : 'Also on order' ?></dt><dd><?= e($l['item_name']) ?>, size <?= e($l['size_label']) ?> × <?= (int) $l['quantity'] ?> · <?= e(money($l['line_total'])) ?></dd>
      <?php endforeach; ?>
      <dt>Order total</dt><dd><?= e(money($order['total_amount'])) ?></dd>
      <dt>Placed</dt><dd><?= e(format_datetime($order['created_at'])) ?> (<?= $order['source'] === 'walk_in' ? 'walk-in' : 'online' ?>)</dd>
    </dl>
  </section>

  <section class="s8-card">
    <h2>Rental</h2>
    <?php if (!$rental): ?>
      <p class="s8-muted">No rental record yet. It is created when the request is approved.</p>
    <?php else: ?>
    <dl class="s8-dl">
      <dt>Academic period</dt><dd><?= e(($rental['school_year'] ?? '—') . ($rental['semester'] ? ', ' . $rental['semester'] : '')) ?></dd>
      <dt>Issued</dt><dd><?= $rental['released_at'] ? e(format_datetime($rental['released_at'])) . ' · by ' . e($rental['issued_by_name'] ?? '—') : '—' ?></dd>
      <dt>Due</dt><dd><?= $rental['due_date'] ? e(format_date($rental['due_date'])) : 'Not set' ?></dd>
      <dt>Deposit</dt><dd><?= $rental['deposit_amount'] !== null ? e(money($rental['deposit_amount'])) : ($pay_deposit > 0 ? e(money($pay_deposit)) . ' (on the receipt)' : 'None recorded') ?></dd>
      <dt>Returned</dt><dd><?= $rental['returned_at'] ? e(format_datetime($rental['returned_at'])) . ' · received by ' . e($rental['received_by_name'] ?? '—') : '—' ?></dd>
      <dt>Condition</dt><dd><?= $rental['condition_on_return'] ? e(rent_condition_labels()[$rental['condition_on_return']] ?? $rental['condition_on_return']) : '—' ?></dd>
      <dt>Penalty</dt><dd><?= $rental['penalty_amount'] !== null ? e(money($rental['penalty_amount'])) : 'None recorded' ?></dd>
      <dt>Deposit returned</dt><dd><?= $rental['deposit_returned'] === null ? 'Not recorded' : ((int) $rental['deposit_returned'] === 1 ? 'Yes' : 'No') ?></dd>
      <?php if ($rental['remarks']): ?><dt>Remarks</dt><dd><?= e($rental['remarks']) ?></dd><?php endif; ?>
    </dl>
    <?php endif; ?>
    <p class="s8-hint" style="margin-top:.6rem">Deposit, penalty and rental length are not confirmed by the shop yet, so they are all optional.</p>
  </section>
</div>

<section class="s8-card">
  <h2>Actions</h2>
  <?php if (!$can_process && !$can_return): ?>
    <p class="s8-muted">Your role can view toga rentals but not change them.</p>
  <?php elseif ($in_bin): ?>
    <p class="s8-muted">No actions while the order is in the recycle bin.</p>
  <?php endif; ?>

  <?php if (!$in_bin && $can_process && $status === 'requested'): $o = $open === 'decline'; ?>
    <form method="post" action="<?= e(url('admin/toga_rental_view.php')) ?>" class="s8-actions" style="margin:.4rem 0">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="approve">
      <button type="submit" class="s8-btn">Approve and reserve a toga</button>
      <span class="s8-hint">Checks stock again and that the student has no other toga this period.</span>
    </form>
    <details class="s8-action" <?= $o ? 'open' : '' ?>>
      <summary>Decline this request</summary>
      <form method="post" action="<?= e(url('admin/toga_rental_view.php')) ?>" class="s8-form">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="decline">
        <label>Reason <span class="s8-hint">shown to the customer on the tracking page</span>
          <textarea name="reason" rows="2" maxlength="255" required><?= $o ? e(old('reason')) : '' ?></textarea></label>
        <button type="submit" class="s8-btn s8-btn-danger">Decline request</button>
      </form>
    </details>
  <?php endif; ?>

  <?php if (!$in_bin && in_array($status, ['approved', 'slip_issued'], true)): ?>
    <p><a class="s8-btn s8-btn-light" href="<?= e(url('admin/order_view.php', ['id' => $id])) ?>">Go to the order to <?= $status === 'approved' ? 'issue the slip' : 'record the payment' ?></a></p>
  <?php endif; ?>

  <?php if (!$in_bin && $can_process && $rental && $rental['status'] === 'reserved' && in_array($status, ['paid', 'unclaimed'], true)): $o = $open === 'issue'; ?>
    <details class="s8-action" open>
      <summary>Issue the toga</summary>
      <form method="post" action="<?= e(url('admin/toga_rental_view.php')) ?>" class="s8-form">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="issue">
        <div class="s8-two">
          <label>Issue date <input type="date" name="issue_date" max="<?= e($today) ?>" value="<?= e($o ? old('issue_date', $today) : $today) ?>" required></label>
          <label>Due date <span class="s8-hint"><?= (int) get_setting('toga_rental_days', 0) > 0 ? 'default: ' . (int) get_setting('toga_rental_days', 0) . ' days after issue' : 'default: end of the academic period' ?></span>
            <input type="date" name="due_date" value="<?= e($o ? old('due_date', (string) $default_due) : (string) $default_due) ?>"></label>
        </div>
        <div class="s8-two">
          <label>Deposit (₱) <span class="s8-hint">optional</span>
            <input type="text" name="deposit_amount" inputmode="decimal" value="<?= e($o ? old('deposit_amount') : ($rental['deposit_amount'] !== null ? (string) $rental['deposit_amount'] : ($pay_deposit > 0 ? number_format($pay_deposit, 2, '.', '') : ''))) ?>"></label>
          <label>Issued by <?= $staff_select('issued_by', $staff, $o ? (string) old('issued_by', (string) $user['user_id']) : (string) $user['user_id']) ?></label>
        </div>
        <div class="s8-two">
          <label>Received by <span class="s8-hint">who takes the toga</span>
            <input type="text" name="received_by" maxlength="120" required value="<?= e($o ? old('received_by') : (string) ($order['student_name'] ?? $order['customer_name'])) ?>"></label>
          <label>Checked by <?= $staff_select('checked_by', $staff, $o ? (string) old('checked_by', (string) $user['user_id']) : (string) $user['user_id']) ?></label>
        </div>
        <label>OIC-Manager <span class="s8-hint">only if the slip needs it</span>
          <?= $staff_select('oic_manager_id', $managers, $o ? (string) old('oic_manager_id') : '', true) ?></label>
        <button type="submit" class="s8-btn">Issue toga</button>
      </form>
    </details>
  <?php endif; ?>

  <?php if (!$in_bin && $can_return && $rental && in_array($rental['status'], ['released', 'overdue'], true)): $o = $open === 'return'; ?>
    <details class="s8-action" open>
      <summary>Record the return</summary>
      <form method="post" action="<?= e(url('admin/toga_rental_view.php')) ?>" class="s8-form">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="return">
        <input type="hidden" name="rental_id" value="<?= (int) $rental['rental_id'] ?>">
        <div class="s8-two">
          <label>Return date <input type="date" name="return_date" max="<?= e($today) ?>" value="<?= e($o ? old('return_date', $today) : $today) ?>" required></label>
          <label>Condition
            <select name="condition" required>
              <option value="">Choose…</option>
              <?php foreach (rent_condition_labels() as $k => $lab): ?><option value="<?= e($k) ?>"<?= $o && old('condition') === $k ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?>
            </select></label>
        </div>
        <p class="s8-hint">Good or needs-cleaning togas go back into stock. Damaged or lost ones do not.</p>
        <div class="s8-two">
          <label>Penalty (₱) <span class="s8-hint">optional</span>
            <input type="text" name="penalty_amount" inputmode="decimal" value="<?= $o ? e(old('penalty_amount')) : '' ?>"></label>
          <label>Deposit returned? <span class="s8-hint">optional</span>
            <select name="deposit_returned">
              <?php foreach (['' => 'Not recorded', '1' => 'Yes, returned', '0' => 'No, kept'] as $k => $lab): ?><option value="<?= e((string) $k) ?>"<?= $o && (string) old('deposit_returned') === (string) $k ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?>
            </select></label>
        </div>
        <label>Received by <?= $staff_select('received_by', $staff, $o ? (string) old('received_by', (string) $user['user_id']) : (string) $user['user_id']) ?></label>
        <label>Remarks <span class="s8-hint">optional</span>
          <input type="text" name="remarks" maxlength="255" value="<?= $o ? e(old('remarks')) : '' ?>"></label>
        <button type="submit" class="s8-btn">Record return</button>
      </form>
    </details>
  <?php endif; ?>

  <?php if (!$in_bin && $can_process && in_array($status, ['requested', 'approved', 'slip_issued', 'paid'], true)): ?>
    <p class="s8-hint">To cancel this toga or correct a payment, use the <a href="<?= e(url('admin/order_view.php', ['id' => $id])) ?>">order page</a>.</p>
  <?php endif; ?>
</section>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
