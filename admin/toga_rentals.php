<?php
/** Toga rentals: filter by status and academic period, search by student, overdue list. */
require __DIR__ . '/_init.php';
$user = require_can('rentals.view');
require_once AUTH_ROOT . '/includes/orders.php';
require_once AUTH_ROOT . '/includes/rentals.php';

$page_title = 'Toga rentals';
$nav_active = 'rentals';
require AUTH_ROOT . '/includes/staff_header.php';
?>
<link rel="stylesheet" href="<?= e(asset('css/rentals.css')) ?>">
<?php
if (rent_migration_block()) {
    require AUTH_ROOT . '/includes/staff_footer.php';
    return;
}

$f = rent_read_filters($_GET);
$total = rent_count($f);
$p = paginate($total, RENT_PAGE_SIZE, $f['page']);
$rows = rent_rows($f, (int) $p['offset'], (int) $p['per_page']);
$counts = rent_status_counts($f);
$overdue = rent_overdue_count();
$base = rent_filter_query($f);
$tab = static fn(string $status) => array_merge(array_diff_key($base, ['status' => 1]), $status === '' ? [] : ['status' => $status]);
$all_count = array_sum($counts);
?>
<div class="s8-head">
  <h1>Toga rentals</h1>
  <p class="s8-muted"><?= (int) $total ?> toga<?= $total === 1 ? '' : 's' ?> found. Issuing a toga is the claim step of a paid order; open a toga to approve, issue or return it.</p>
</div>

<?php if ($overdue > 0 && $f['status'] !== 'overdue'): ?>
  <div class="s8-alert s8-alert-error s8-banner" role="alert">
    <span><strong><?= (int) $overdue ?> toga<?= $overdue === 1 ? ' is' : 's are' ?> overdue.</strong> The due date has passed and the toga has not been returned.</span>
    <a class="s8-btn s8-btn-sm" href="<?= e(url('admin/toga_rentals.php', array_merge(array_diff_key($base, ['status' => 1]), ['status' => 'overdue']))) ?>">Show overdue</a>
  </div>
<?php endif; ?>

<nav class="s8-tabs" aria-label="Filter by status">
  <a class="<?= $f['status'] === '' ? 'is-active' : '' ?>" href="<?= e(url('admin/toga_rentals.php', $tab(''))) ?>">All <span><?= (int) $all_count ?></span></a>
  <?php foreach (rent_statuses() as $key => $label): ?>
    <a class="<?= $f['status'] === $key ? 'is-active' : '' ?>" href="<?= e(url('admin/toga_rentals.php', $tab($key))) ?>"><?= e($label) ?> <span><?= (int) $counts[$key] ?></span></a>
  <?php endforeach; ?>
</nav>

<form method="get" action="<?= e(url('admin/toga_rentals.php')) ?>" class="s8-filters">
  <label>Search
    <input type="search" name="q" value="<?= e($f['q']) ?>" maxlength="60" placeholder="Student no., name or reference">
  </label>
  <label>Academic period
    <select name="period">
      <option value="0">All periods</option>
      <?php foreach (rent_periods() as $pr): ?>
        <option value="<?= (int) $pr['academic_period_id'] ?>"<?= $f['period'] === (int) $pr['academic_period_id'] ? ' selected' : '' ?>><?= e($pr['school_year'] . ', ' . $pr['semester'] . ($pr['is_current'] ? ' (current)' : '')) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Status
    <select name="status">
      <option value="">All statuses</option>
      <?php foreach (rent_statuses() as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $f['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="s8-actions">
    <button type="submit" class="s8-btn">Apply</button>
    <a class="s8-btn s8-btn-light" href="<?= e(url('admin/toga_rentals.php')) ?>">Reset</a>
  </div>
</form>

<?php if (!$rows): ?>
  <p class="s8-alert s8-alert-info">No togas match these filters.</p>
<?php else: ?>
  <div class="s8-table-wrap">
    <table class="s8-table">
      <thead><tr><th>Student</th><th>Toga</th><th>Status</th><th>Issued</th><th>Due</th><th>Returned</th><th class="s8-num">Deposit</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $late = $r['eff_status'] === 'overdue' && $r['days_late'] !== null && (int) $r['days_late'] > 0; ?>
        <tr class="<?= $late ? 's8-row-overdue' : '' ?>">
          <td><strong><?= e($r['student_name'] ?? '—') ?></strong><br>
            <span class="s8-muted"><?= e($r['student_no'] ?? '') ?> · <a href="<?= e(url('admin/customer_history.php', ['q' => $r['student_no']])) ?>">history</a></span></td>
          <td><?= e($r['item_name']) ?>, size <?= e($r['size_label']) ?><br>
            <span class="s8-muted"><?= e($r['order_ref']) ?></span></td>
          <td><?= rent_status_badge($r['eff_status']) ?></td>
          <td><?= $r['released_at'] ? e(format_date($r['released_at'])) : '—' ?></td>
          <td><?= $r['due_date'] ? e(format_date($r['due_date'])) : '—' ?>
            <?php if ($late): ?><br><span class="s8-late"><?= (int) $r['days_late'] ?> day<?= (int) $r['days_late'] === 1 ? '' : 's' ?> late</span><?php endif; ?></td>
          <td><?= $r['returned_at'] ? e(format_date($r['returned_at'])) : '—' ?>
            <?php if ($r['condition_on_return']): ?><br><span class="s8-muted"><?= e(rent_condition_labels()[$r['condition_on_return']] ?? $r['condition_on_return']) ?></span><?php endif; ?></td>
          <td class="s8-num"><?= $r['deposit_amount'] !== null ? e(money($r['deposit_amount'])) : '—' ?></td>
          <td><a class="s8-btn s8-btn-sm" href="<?= e(url('admin/toga_rental_view.php', ['id' => (int) $r['order_id']])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= pagination_links($p, 'admin/toga_rentals.php', $base) ?>
<?php endif; ?>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
