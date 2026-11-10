<?php
/** Customer history: look a student up by number or name and see past orders, toga rentals and contacts. */
require __DIR__ . '/_init.php';
$user = require_can('orders.view');
require_once AUTH_ROOT . '/includes/orders.php';
require_once AUTH_ROOT . '/includes/rentals.php';
require_once AUTH_ROOT . '/includes/customer_lookup.php';

$q = clean_str($_GET['q'] ?? '');
$matches = [];
$student = null;
if ($q !== '') {
    $student = cust_find_student($q);
    if (!$student) {
        $matches = cust_search_students($q, 20);
        if (count($matches) === 1) {
            $student = cust_find_student($matches[0]['student_no']);
            $matches = [];
        }
    }
}

$page_title = 'Customer history';
$nav_active = 'history';
require AUTH_ROOT . '/includes/staff_header.php';
?>
<link rel="stylesheet" href="<?= e(asset('css/rentals.css')) ?>">
<div class="s8-head">
  <h1>Customer history</h1>
  <p class="s8-muted">Past orders and toga rentals for a student, found by student no. (or LRN) or name.</p>
</div>

<form method="get" action="<?= e(url('admin/customer_history.php')) ?>" class="s8-filters" role="search">
  <label>Student no. or name
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="60" required autofocus placeholder="2023-00123 or a name"></label>
  <div class="s8-actions"><button type="submit" class="s8-btn">Search</button></div>
</form>

<?php if ($q !== '' && !$student && !$matches): ?>
  <p class="s8-alert s8-alert-info">No student found for "<?= e($q) ?>".</p>
<?php endif; ?>

<?php if ($matches): ?>
  <div class="s8-card"><strong><?= count($matches) ?> students match:</strong>
    <ul class="s8-results">
      <?php foreach ($matches as $m): ?>
        <li><a href="<?= e(url('admin/customer_history.php', ['q' => $m['student_no']])) ?>"><?= e($m['full_name']) ?></a>
          <span class="s8-muted">· <?= e($m['student_no']) ?> · <?= e(trim(($m['course_code'] ?: $m['level_name'] ?: '') . ' ' . $m['year_level'])) ?> · <?= (int) $m['order_count'] ?> order<?= (int) $m['order_count'] === 1 ? '' : 's' ?></span></li>
      <?php endforeach; ?>
    </ul></div>
<?php endif; ?>

<?php if ($student):
    $h = cust_history((int) $student['student_id']);
    $can_walkin = can('walkin.create');
    $can_rent = can('rentals.view'); ?>
  <section class="s8-card">
    <h2><?= e($student['full_name']) ?></h2>
    <dl class="s8-dl">
      <dt>Student no.</dt><dd><?= e($student['student_no']) ?></dd>
      <dt>Level</dt><dd><?= e($student['level_name'] ?? '—') ?></dd>
      <dt>Course &amp; year</dt><dd><?= e(trim(($student['course_code'] ?? '') . ' ' . ($student['year_level'] ?? '')) ?: '—') ?></dd>
      <dt>Known since</dt><dd><?= e(format_date($student['created_at'])) ?></dd>
    </dl>
    <?php if ($can_walkin): ?><p style="margin-top:.7rem"><a class="s8-btn s8-btn-light" href="<?= e(url('admin/walkin.php', ['q' => $student['student_no']])) ?>">Start a walk-in order for this student</a></p><?php endif; ?>
  </section>

  <section class="s8-card">
    <h2>Toga rentals (<?= count($h['rentals']) ?>)</h2>
    <?php if (!$h['rentals']): ?><p class="s8-muted">No toga requests or rentals.</p><?php else: ?>
    <div class="s8-table-wrap"><table class="s8-table">
      <thead><tr><th>Period</th><th>Toga</th><th>Status</th><th>Issued</th><th>Due</th><th>Returned</th><th class="s8-num">Deposit</th><th class="s8-num">Penalty</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($h['rentals'] as $r): ?>
        <tr class="<?= $r['eff_status'] === 'overdue' ? 's8-row-overdue' : '' ?>">
          <td><?= e(($r['school_year'] ?? '—') . ($r['semester'] ? ', ' . $r['semester'] : '')) ?></td>
          <td><?= e($r['item_name']) ?>, size <?= e($r['size_label']) ?><br><span class="s8-muted"><?= e($r['order_ref']) ?></span></td>
          <td><?= rent_status_badge($r['eff_status']) ?></td>
          <td><?= $r['released_at'] ? e(format_date($r['released_at'])) : '—' ?></td>
          <td><?= $r['due_date'] ? e(format_date($r['due_date'])) : '—' ?></td>
          <td><?= $r['returned_at'] ? e(format_date($r['returned_at'])) : '—' ?></td>
          <td class="s8-num"><?= $r['deposit_amount'] !== null ? e(money($r['deposit_amount'])) : '—' ?></td>
          <td class="s8-num"><?= $r['penalty_amount'] !== null ? e(money($r['penalty_amount'])) : '—' ?></td>
          <td><?php if ($can_rent): ?><a class="s8-btn s8-btn-sm" href="<?= e(url('admin/toga_rental_view.php', ['id' => (int) $r['order_id']])) ?>">Open</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>

  <section class="s8-card">
    <h2>Orders (<?= count($h['orders']) ?>)</h2>
    <?php if (!$h['orders']): ?><p class="s8-muted">No orders yet.</p><?php else: ?>
    <div class="s8-table-wrap"><table class="s8-table">
      <thead><tr><th>Reference</th><th>Slip no.</th><th>Items</th><th class="s8-num">Total</th><th>Status</th><th>Placed</th><th>Ordered by</th></tr></thead>
      <tbody>
      <?php foreach ($h['orders'] as $o):
          $slip = in_array($o['status'], ['slip_issued', 'paid', 'claimed', 'unclaimed'], true); ?>
        <tr>
          <td><a href="<?= e(url('admin/order_view.php', ['id' => (int) $o['order_id']])) ?>"><strong><?= e($o['order_ref']) ?></strong></a><br>
            <span class="s8-muted"><?= e(ucfirst($o['order_type'])) ?> · <?= $o['source'] === 'walk_in' ? 'walk-in' : 'online' ?></span></td>
          <td><?= $slip ? e($o['order_no']) : '<span class="s8-muted">Not issued</span>' ?></td>
          <td><?= e((string) $o['items_text']) ?></td>
          <td class="s8-num"><?= e(money($o['total_amount'])) ?></td>
          <td><?= status_badge($o['status']) ?><?= $o['deleted_at'] ? '<br><span class="s8-muted">in recycle bin</span>' : '' ?></td>
          <td><?= e(format_datetime($o['created_at'])) ?></td>
          <td><?= e($o['customer_name']) ?><br><span class="s8-muted"><?= e($o['contact_no']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>

  <section class="s8-card">
    <h2>People who ordered for this student</h2>
    <?php if (!$h['contacts']): ?><p class="s8-muted">None yet.</p><?php else: ?>
    <ul class="s8-results">
      <?php foreach ($h['contacts'] as $c): ?>
        <li><strong><?= e($c['full_name']) ?></strong> (<?= e($c['relationship']) ?>) · <?= e($c['contact_no']) ?><?= $c['email'] ? ' · ' . e($c['email']) : '' ?>
          <span class="s8-muted">· <?= (int) $c['orders'] ?> order<?= (int) $c['orders'] === 1 ? '' : 's' ?>, last <?= e(format_date($c['last_order'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
