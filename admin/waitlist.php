<?php
/**
 * Waitlist management. Staff call or text customers themselves; the system
 * sends nothing. Entries are grouped by size so a restock is easy to act on.
 */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/inventory.php';

$user = require_can('restock.view');
$can_manage = can('restock.manage');
$f = inv_waitlist_filters($_GET);
$keep = array_filter(['status' => $f['status'] !== 'open' ? $f['status'] : null, 'variant' => $f['variant'] ?: null,
    'item' => $f['item'] ?: null, 'q' => $f['q'] !== '' ? $f['q'] : null], static fn($v) => $v !== null);

if (is_post() && inv_migration_problem() === null) {
    csrf_require();
    require_can('restock.manage');
    $res = inv_waitlist_change((int) ($_POST['waitlist_id'] ?? 0), (string) ($_POST['to'] ?? ''), (string) ($_POST['staff_note'] ?? ''), (int) $user['user_id']);
    flash_set($res['ok'] ? 'success' : 'error', $res['message']);
    redirect('admin/waitlist.php', $keep);
}

$page_title = 'Waitlist';
$nav_active = 'restock';
require AUTH_ROOT . '/includes/staff_header.php';
echo inv_subnav('waitlist');
if (inv_migration_block()) {
    require AUTH_ROOT . '/includes/staff_footer.php';
    return;
}
$rows = inv_waitlist_rows($f);
$counts = inv_waitlist_counts();
$groups = [];
foreach ($rows as $r) {
    $groups[(int) $r['variant_id']][] = $r;
}
$moves = inv_waitlist_moves();
$move_labels = ['contacted' => 'Contacted', 'fulfilled' => 'Fulfilled', 'cancelled' => 'Cancel', 'waiting' => 'Reopen'];
?>
<h1>Waitlist</h1>
<p class="inv-muted">Customers who asked to be told when a size is back. The system does not send emails or texts: call or text them, then mark them as contacted.</p>

<form class="inv-filters" method="get" action="<?= e(url('admin/waitlist.php')) ?>">
  <label>Show
    <select name="status">
      <option value="open"<?= $f['status'] === 'open' ? ' selected' : '' ?>>Open (waiting + contacted)</option>
      <?php foreach (inv_waitlist_statuses() as $k => $l): ?>
        <option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($l) ?> (<?= (int) $counts[$k] ?>)</option>
      <?php endforeach; ?>
      <option value="all"<?= $f['status'] === 'all' ? ' selected' : '' ?>>All</option>
    </select></label>
  <label>Size
    <select name="variant"><option value="0">All sizes</option>
      <?php foreach (inv_waitlist_variant_options() as $o): ?>
        <option value="<?= (int) $o['variant_id'] ?>"<?= $f['variant'] === (int) $o['variant_id'] ? ' selected' : '' ?>><?= e($o['item_name']) ?> - <?= e($o['size_label']) ?></option>
      <?php endforeach; ?>
    </select></label>
  <label>Search<input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, number or email"></label>
  <button class="inv-btn" type="submit">Filter</button>
</form>

<?php if (!$groups): ?><p class="inv-muted">No waitlist entries match.</p><?php endif; ?>
<?php if (count($rows) >= INV_WAITLIST_LIMIT): ?><p class="inv-muted">Showing the first <?= INV_WAITLIST_LIMIT ?> entries; narrow the filter to see others.</p><?php endif; ?>

<?php foreach ($groups as $vid => $entries):
    $g = $entries[0];
    $avail = (int) $g['available'];
    $tracks = (int) $g['tracks_stock'] === 1;
    $waiting_here = count(array_filter($entries, static fn($x) => $x['status'] === 'waiting'));
    $ready = $tracks && $avail > 0 && $waiting_here > 0; ?>
  <div class="inv-group<?= $ready ? ' is-ready' : '' ?>">
    <h3><?= e($g['item_name']) ?>, size <?= e($g['size_label']) ?>
      <?= $tracks ? inv_state_badge(inv_stock_state($avail, 0, true)) . ' <span class="inv-muted">' . $avail . ' available</span>' : '' ?></h3>
    <?php if ($ready): ?><div class="inv-alert inv-alert-waitlist"><strong>In stock now.</strong> <?= (int) $waiting_here ?> customer(s) are still waiting to be told.</div><?php endif; ?>
    <div class="inv-table-wrap"><table class="inv-table">
      <thead><tr><th>Customer</th><th>Contact</th><th class="num">Qty</th><th>Joined</th><th>Status</th><th>Note</th><?php if ($can_manage): ?><th>Update</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($entries as $r):
          $tel = preg_replace('/[^0-9+]/', '', (string) $r['contact_no']); ?>
        <tr>
          <td><?= e($r['contact_name']) ?></td>
          <td>
            <a href="tel:<?= e($tel) ?>"><?= e($r['contact_no']) ?></a>
            · <a href="sms:<?= e($tel) ?>">Text</a>
            <?php if ($r['email']): ?><br><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?php endif; ?>
          </td>
          <td class="num"><?= isset($r['quantity_wanted']) ? (int) $r['quantity_wanted'] : '—' ?></td>
          <td><?= e(format_datetime($r['created_at'])) ?></td>
          <td><span class="inv-badge inv-badge-<?= e($r['status']) ?>"><?= e(inv_waitlist_statuses()[$r['status']] ?? $r['status']) ?></span>
            <?php if ($r['status'] === 'contacted' && $r['notified_at']): ?><br><span class="inv-muted"><?= e(format_datetime($r['notified_at'])) ?><?= $r['contacted_by_name'] ? ' by ' . e($r['contacted_by_name']) : '' ?></span><?php endif; ?></td>
          <td><?= e((string) $r['staff_note']) ?></td>
          <?php if ($can_manage): ?>
          <td>
            <form method="post" action="<?= e(url('admin/waitlist.php', $keep)) ?>" class="inv-actions">
              <?= csrf_field() ?><input type="hidden" name="waitlist_id" value="<?= (int) $r['waitlist_id'] ?>">
              <input type="text" name="staff_note" maxlength="255" placeholder="Note (optional)" aria-label="Note" style="max-width:11rem">
              <?php foreach ($moves[$r['status']] ?? [] as $to): ?>
                <button class="inv-btn inv-btn-sm<?= $to === 'contacted' ? '' : ' inv-btn-light' ?>" type="submit" name="to" value="<?= e($to) ?>"
                  <?= $to === 'cancelled' ? 'onclick="return confirm(\'Cancel this waitlist entry?\')"' : '' ?>><?= e($move_labels[$to]) ?></button>
              <?php endforeach; ?>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endforeach; ?>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
