<?php
/** Activity log viewer (admin only): logins, setting changes, reverts, stock adjustments, purges and more. */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/activity.php';

$user = require_can('settings.manage');   // admin only
$f = act_filters($_GET);
$total = act_count($f);
$p = paginate($total, ACT_PAGE_SIZE, $f['page']);
$rows = act_rows($f, (int) $p['offset'], (int) $p['per_page']);
$query = act_query_params($f);

$page_title = 'Activity log';
$nav_active = 'activity';
require AUTH_ROOT . '/includes/staff_header.php';
?>
<link rel="stylesheet" href="<?= e(asset('css/admin9.css')) ?>">

<p class="s9-muted">A read-only record of sensitive actions. Entries cannot be edited or deleted here.</p>
<form method="get" action="<?= e(url('admin/activity_log.php')) ?>" class="s9-filters">
  <label>Show
    <select name="cat">
      <?php foreach (act_categories() as $k => [$label]): ?><option value="<?= e($k) ?>"<?= $f['cat'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
  <label>User
    <select name="user"><option value="0">Everyone</option>
      <?php foreach (act_users() as $u): ?><option value="<?= (int) $u['user_id'] ?>"<?= $f['user'] === (int) $u['user_id'] ? ' selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?>
    </select></label>
  <label>From <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label>To <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <label>Search <input type="search" name="q" value="<?= e($f['q']) ?>" maxlength="60" placeholder="Action, record id, text"></label>
  <button type="submit" class="btn btn-primary">Filter</button>
  <a class="btn" href="<?= e(url('admin/activity_log.php')) ?>">Reset</a>
</form>

<p class="s9-muted"><?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?>.</p>
<div class="s9-table-wrap"><table class="s9-table">
  <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Details</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="5" class="s9-muted">No entries match.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r):
      $link = act_entity_link($r);
      $who = $r['user_id'] === null ? 'System / customer' : ($r['full_name'] ?: $r['username'] ?: ('user #' . $r['user_id'])); ?>
    <tr>
      <td><?= e(format_datetime($r['created_at'])) ?></td>
      <td><?= e($who) ?></td>
      <td><strong><?= e(act_label($r['action'])) ?></strong></td>
      <td><?php if ($link): ?><a href="<?= e($link) ?>"><?= e($r['entity_type']) ?> #<?= e($r['entity_id']) ?></a>
          <?php else: ?><?= e($r['entity_type']) ?><?= $r['entity_id'] !== null && $r['entity_id'] !== '' ? ' · ' . e($r['entity_id']) : '' ?><?php endif; ?></td>
      <td><?= e(act_detail($r)) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= pagination_links($p, 'admin/activity_log.php', $query) ?>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
