<?php
/** Admin settings: reservations, quantities, stock, toga, shop details, announcement, slip format, academic periods. */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/settings_lib.php';
require_once AUTH_ROOT . '/includes/expiry.php';

$user = require_can('settings.manage');
$uid = (int) $user['user_id'];
$errors = [];
$period_errors = [];
$posted = null;
$posted_period = null;

if (is_post()) {
    csrf_require();
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'save') {
        [$clean, $errors] = cfg_validate($_POST);
        if (!$errors) {
            $changed = cfg_save($clean, $uid);
            flash_set('success', $changed
                ? 'Settings saved (' . count($changed) . ' changed). Each change was recorded in the activity log.'
                : 'Nothing changed.');
            redirect('admin/settings.php');
        }
        $posted = $_POST;
    } elseif ($do === 'period_current') {
        try {
            $moved = cfg_period_make_current((int) ($_POST['period_id'] ?? 0), $uid);
            flash_set('success', $moved ? 'Current academic period changed. Existing orders keep the period they were placed in.' : 'That period is already the current one.');
        } catch (DomainException $ex) {
            flash_set('error', $ex->getMessage());
        }
        redirect('admin/settings.php');
    } elseif ($do === 'period_add') {
        [$pc, $period_errors] = cfg_period_validate($_POST);
        if (!$period_errors) {
            try {
                cfg_period_add($pc, $uid);
                flash_set('success', 'Academic period added.');
                redirect('admin/settings.php');
            } catch (DomainException $ex) {
                $period_errors['school_year'] = $ex->getMessage();
            }
        }
        $posted_period = $_POST;
    }
}

$vals = cfg_form_values();
$v = static fn(string $k): string => e((string) ($posted[$k] ?? $vals[$k] ?? ''));
$checked = static function (string $k) use ($posted, $vals): string {
    $on = $posted !== null ? !empty($posted[$k]) : ($vals[$k] ?? '0') === '1';
    return $on ? ' checked' : '';
};
$err = static fn(array $errs, string $k): string => isset($errs[$k]) ? '<p class="s9-error" role="alert">' . e($errs[$k]) . '</p>' : '';
$style = (string) ($posted['announcement_style'] ?? $vals['announcement_style']);
$periods = cfg_periods();
$exp = expiry_status();
$prefix_prev = preg_match('/^[A-Za-z0-9]{1,6}$/', (string) ($posted['order_no_prefix'] ?? $vals['order_no_prefix'])) ? strtoupper((string) ($posted['order_no_prefix'] ?? $vals['order_no_prefix'])) : 'OS';
$fmt_prev = (string) ($posted['order_slip_format'] ?? $vals['order_slip_format']);
$sample = cfg_check_slip_format($fmt_prev, $prefix_prev) === null ? ord_format_slip_no($fmt_prev, $prefix_prev, date('Y'), 1) : '';

$page_title = 'Settings';
$nav_active = 'settings';
require AUTH_ROOT . '/includes/staff_header.php';
?>
<link rel="stylesheet" href="<?= e(asset('css/admin9.css')) ?>">

<?php if ($errors): ?><div class="s9-alert s9-alert-error" role="alert">Some settings were not saved. Please fix the highlighted fields.</div><?php endif; ?>

<form method="post" action="<?= e(url('admin/settings.php')) ?>" class="s9-form" novalidate>
  <?= csrf_field() ?><input type="hidden" name="do" value="save">

  <section class="s9-card">
    <h2>Reservations</h2>
    <label class="s9-check"><input type="checkbox" name="reservations_open" value="1"<?= $checked('reservations_open') ?>> Accept online reservations and toga requests</label>
    <p class="s9-hint">Switch off to close the shop online (for example during inventory). Customers can still browse and track orders; staff can still enter walk-in orders.</p>
    <div class="s9-grid2">
      <div class="s9-field"><label for="hold_days">Hold time for unpaid reservations (days)</label>
        <input id="hold_days" name="hold_days" inputmode="decimal" required value="<?= $v('hold_days') ?>">
        <p class="s9-hint">Default 7. Approved orders not paid within this time are expired and their stock released.</p><?= $err($errors, 'hold_days') ?></div>
      <div class="s9-field"><label for="low_stock_default">Low-stock threshold</label>
        <input id="low_stock_default" name="low_stock_default" type="number" min="0" max="1000" required value="<?= $v('low_stock_default') ?>">
        <p class="s9-hint">Used for sizes that have no threshold of their own.</p><?= $err($errors, 'low_stock_default') ?></div>
      <div class="s9-field"><label for="default_max_qty_per_request">Default quantity limit per online request</label>
        <input id="default_max_qty_per_request" name="default_max_qty_per_request" type="number" min="1" max="50" required value="<?= $v('default_max_qty_per_request') ?>">
        <p class="s9-hint">Suggested for new items. Each item keeps its own limit.</p><?= $err($errors, 'default_max_qty_per_request') ?></div>
      <div class="s9-field"><label for="max_qty_per_request_limit">Maximum quantity limit per online request</label>
        <input id="max_qty_per_request_limit" name="max_qty_per_request_limit" type="number" min="1" max="50" required value="<?= $v('max_qty_per_request_limit') ?>">
        <p class="s9-hint">No item can allow more than this online, even if its own limit is higher. Staff at the counter can still go above it.</p><?= $err($errors, 'max_qty_per_request_limit') ?></div>
      <div class="s9-field"><label for="toga_rental_days">Default toga rental length (days)</label>
        <input id="toga_rental_days" name="toga_rental_days" type="number" min="0" max="730" required value="<?= $v('toga_rental_days') ?>">
        <p class="s9-hint">0 = due at the end of the academic period. To be confirmed with the shop.</p><?= $err($errors, 'toga_rental_days') ?></div>
    </div>
  </section>

  <section class="s9-card">
    <h2>Automatic expiry and alerts</h2>
    <label class="s9-check"><input type="checkbox" name="auto_expiry_enabled" value="1"<?= $checked('auto_expiry_enabled') ?>> Also run the expiry job when staff pages are opened</label>
    <p class="s9-hint">Useful when there is no Windows Task Scheduler or cron. The job runs at most once per interval below.</p>
    <div class="s9-grid2">
      <div class="s9-field"><label for="auto_expiry_interval_minutes">Minimum minutes between runs</label>
        <input id="auto_expiry_interval_minutes" name="auto_expiry_interval_minutes" type="number" min="1" max="60" required value="<?= $v('auto_expiry_interval_minutes') ?>"><?= $err($errors, 'auto_expiry_interval_minutes') ?></div>
      <div class="s9-field"><label for="expiring_soon_hours">"Expiring soon" warning (hours before the hold ends)</label>
        <input id="expiring_soon_hours" name="expiring_soon_hours" type="number" min="1" max="720" required value="<?= $v('expiring_soon_hours') ?>"><?= $err($errors, 'expiring_soon_hours') ?></div>
      <div class="s9-field"><label for="recycle_purge_days">Days in the recycle bin before purge</label>
        <input id="recycle_purge_days" name="recycle_purge_days" type="number" min="1" max="365" required value="<?= $v('recycle_purge_days') ?>"><?= $err($errors, 'recycle_purge_days') ?></div>
    </div>
  </section>

  <section class="s9-card">
    <h2>Shop details (public footer)</h2>
    <div class="s9-grid2">
      <div class="s9-field"><label for="shop_name">Shop name</label>
        <input id="shop_name" name="shop_name" maxlength="100" required value="<?= $v('shop_name') ?>"><?= $err($errors, 'shop_name') ?></div>
      <div class="s9-field"><label for="shop_hours">Opening hours</label>
        <input id="shop_hours" name="shop_hours" maxlength="100" value="<?= $v('shop_hours') ?>"><?= $err($errors, 'shop_hours') ?></div>
      <div class="s9-field"><label for="shop_address">Address</label>
        <input id="shop_address" name="shop_address" maxlength="150" value="<?= $v('shop_address') ?>"><?= $err($errors, 'shop_address') ?></div>
      <div class="s9-field"><label for="shop_phone">Phone</label>
        <input id="shop_phone" name="shop_phone" maxlength="40" value="<?= $v('shop_phone') ?>"><?= $err($errors, 'shop_phone') ?></div>
      <div class="s9-field"><label for="shop_email">Email</label>
        <input id="shop_email" name="shop_email" type="email" maxlength="120" value="<?= $v('shop_email') ?>"><?= $err($errors, 'shop_email') ?></div>
    </div>
    <p class="s9-hint">A blank field falls back to the value in config/config.php.</p>
  </section>

  <section class="s9-card">
    <h2>Announcement banner (home page)</h2>
    <label class="s9-check"><input type="checkbox" name="announcement_enabled" value="1"<?= $checked('announcement_enabled') ?>> Show the banner</label>
    <div class="s9-field"><label for="announcement_text">Text (up to 255 characters)</label>
      <input id="announcement_text" name="announcement_text" maxlength="255" value="<?= $v('announcement_text') ?>"><?= $err($errors, 'announcement_text') ?></div>
    <div class="s9-field"><label for="announcement_style">Style</label>
      <select id="announcement_style" name="announcement_style">
        <?php foreach (['info' => 'Information (blue)', 'warning' => 'Warning (yellow)', 'success' => 'Good news (green)'] as $k => $l): ?>
          <option value="<?= e($k) ?>"<?= $style === $k ? ' selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select><?= $err($errors, 'announcement_style') ?></div>
  </section>

  <section class="s9-card">
    <h2>Order slip number</h2>
    <div class="s9-grid2">
      <div class="s9-field"><label for="order_no_prefix">Prefix</label>
        <input id="order_no_prefix" name="order_no_prefix" maxlength="6" required value="<?= $v('order_no_prefix') ?>"><?= $err($errors, 'order_no_prefix') ?></div>
      <div class="s9-field"><label for="order_slip_format">Format</label>
        <input id="order_slip_format" name="order_slip_format" maxlength="60" required value="<?= $v('order_slip_format') ?>"><?= $err($errors, 'order_slip_format') ?></div>
    </div>
    <p class="s9-hint">Placeholders: <code>{prefix}</code> <code>{year}</code> <code>{yy}</code> <code>{seq}</code> or <code>{seq:4}</code> (running number, padded to 4 digits).
      The number is at most 20 characters. <?= $sample !== '' ? 'Next slip would look like: <strong>' . e($sample) . '</strong>.' : '' ?>
      Slips already issued keep their numbers; a number already in use is skipped automatically.</p>
  </section>

  <p><button type="submit" class="btn btn-primary">Save settings</button></p>
</form>

<section class="s9-card">
  <h2>Academic period</h2>
  <p class="s9-hint">The current period decides the toga rule "one active rental per student per period" and the due date of a rental.</p>
  <div class="s9-table-wrap"><table class="s9-table">
    <thead><tr><th>School year</th><th>Semester</th><th>Starts</th><th>Ends</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$periods): ?><tr><td colspan="6" class="s9-muted">No academic periods yet. Add one below.</td></tr><?php endif; ?>
    <?php foreach ($periods as $p): ?>
      <tr>
        <td><?= e($p['school_year']) ?></td><td><?= e($p['semester']) ?></td>
        <td><?= e(format_date($p['start_date'])) ?></td><td><?= e(format_date($p['end_date'])) ?></td>
        <td><?= (int) $p['is_current'] === 1 ? '<span class="s9-badge s9-badge-ok">Current</span>' : '' ?></td>
        <td><?php if ((int) $p['is_current'] !== 1): ?>
          <form method="post" action="<?= e(url('admin/settings.php')) ?>" class="s9-inline">
            <?= csrf_field() ?><input type="hidden" name="do" value="period_current"><input type="hidden" name="period_id" value="<?= (int) $p['academic_period_id'] ?>">
            <button type="submit" class="btn btn-small" data-confirm="Make <?= e($p['school_year'] . ' ' . $p['semester']) ?> the current academic period?">Make current</button>
          </form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <form method="post" action="<?= e(url('admin/settings.php')) ?>" class="s9-form s9-subform" novalidate>
    <?= csrf_field() ?><input type="hidden" name="do" value="period_add">
    <h3>Add a period</h3>
    <?php $pv = static fn(string $k): string => e((string) ($posted_period[$k] ?? '')); ?>
    <div class="s9-grid2">
      <div class="s9-field"><label for="school_year">School year</label>
        <input id="school_year" name="school_year" placeholder="2026-2027" maxlength="9" required value="<?= $pv('school_year') ?>"><?= $err($period_errors, 'school_year') ?></div>
      <div class="s9-field"><label for="semester">Semester</label>
        <select id="semester" name="semester" required>
          <?php foreach (['1st' => '1st semester', '2nd' => '2nd semester', 'summer' => 'Summer'] as $k => $l): ?>
            <option value="<?= e($k) ?>"<?= (($posted_period['semester'] ?? '1st') === $k) ? ' selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select><?= $err($period_errors, 'semester') ?></div>
      <div class="s9-field"><label for="start_date">Starts</label>
        <input id="start_date" name="start_date" type="date" required value="<?= $pv('start_date') ?>"><?= $err($period_errors, 'start_date') ?></div>
      <div class="s9-field"><label for="end_date">Ends</label>
        <input id="end_date" name="end_date" type="date" required value="<?= $pv('end_date') ?>"><?= $err($period_errors, 'end_date') ?></div>
    </div>
    <label class="s9-check"><input type="checkbox" name="make_current" value="1"<?= !empty($posted_period['make_current']) ? ' checked' : '' ?>> Make it the current period</label>
    <p><button type="submit" class="btn">Add period</button></p>
  </form>
</section>

<section class="s9-card">
  <h2>Automatic expiry status</h2>
  <dl class="s9-dl">
    <dt>Unpaid holds past their time right now</dt><dd><?= (int) $exp['due'] ?></dd>
    <dt>Last run started</dt><dd><?= $exp['last_run'] !== '' ? e(format_datetime($exp['last_run'])) : 'never' ?></dd>
    <dt>Last result</dt><dd><?= $exp['result'] !== '' ? e($exp['result']) : '—' ?></dd>
    <dt>Run when staff pages open</dt><dd><?= $exp['enabled'] ? 'On (every ' . (int) $exp['interval'] . ' min at most)' : 'Off' ?></dd>
  </dl>
  <form method="post" action="<?= e(url('admin/run_expiry.php')) ?>">
    <?= csrf_field() ?><input type="hidden" name="back" value="admin/settings.php">
    <button type="submit" class="btn">Run expiry now</button>
    <span class="s9-hint">Same job as cron/expire_orders.php. Safe to press at any time.</span>
  </form>
</section>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
