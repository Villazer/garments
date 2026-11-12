<?php
/** Reports (manager and admin): filters, printable view and CSV export. */
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/reports.php';

$user = require_can('reports.view');
$f = rep_filters($_GET);
$report = rep_run($f);

// CSV and the print view send their own output, so they run before the staff layout.
if (($_GET['export'] ?? '') === 'csv') {
    rep_export_csv($report, $f);
    exit;
}
if (!empty($_GET['print'])) {
    header('Cache-Control: no-store');
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($report['title']) ?> · Report</title>
  <link rel="stylesheet" href="<?= e(asset('css/admin9.css')) ?>">
</head>
<body class="s9-print-page">
  <div class="s9-noprint s9-toolbar">
    <a class="btn" href="<?= e(url('admin/reports.php', rep_filter_query($f))) ?>">&larr; Back to report</a>
    <button type="button" class="btn btn-primary" id="s9-print">Print</button>
  </div>
  <header class="s9-print-head">
    <h1><?= e(shop_info('name') ?: config('app_name', 'Garment Shop')) ?> &ndash; <?= e($report['title']) ?></h1>
    <p><?= e($report['subtitle']) ?><br>Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> by <?= e($user['full_name']) ?></p>
  </header>
  <?= rep_render_html($report) ?>
  <script>
    document.getElementById('s9-print').addEventListener('click', function () { window.print(); });
    window.addEventListener('load', function () { window.print(); });
  </script>
</body>
</html>
<?php
    exit;
}

$periods = rep_periods();
$base = rep_filter_query($f);
$page_title = 'Reports';
$nav_active = 'reports';
require AUTH_ROOT . '/includes/staff_header.php';
?>
<link rel="stylesheet" href="<?= e(asset('css/admin9.css')) ?>">

<nav class="s9-tabs" aria-label="Report type">
  <?php foreach (rep_types() as $k => $label): ?>
    <a class="<?= $f['type'] === $k ? 'is-active' : '' ?>" href="<?= e(url('admin/reports.php', array_merge($base, ['type' => $k]))) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<form method="get" action="<?= e(url('admin/reports.php')) ?>" class="s9-filters">
  <input type="hidden" name="type" value="<?= e($f['type']) ?>">
  <label>From <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label>To <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <?php if (rep_uses_period($f['type'])): ?>
  <label>Academic period
    <select name="period"><option value="0">All periods</option>
      <?php foreach ($periods as $p): ?><option value="<?= (int) $p['academic_period_id'] ?>"<?= $f['period'] === (int) $p['academic_period_id'] ? ' selected' : '' ?>><?= e(rep_period_label($p)) ?></option><?php endforeach; ?>
    </select></label>
  <?php endif; ?>
  <button type="submit" class="btn btn-primary">Apply</button>
  <a class="btn" href="<?= e(url('admin/reports.php', ['type' => $f['type']])) ?>">Reset</a>
</form>

<p class="s9-actions-row">
  <a class="btn" href="<?= e(url('admin/reports.php', $base + ['print' => 1])) ?>" target="_blank" rel="noopener">Printable view</a>
  <a class="btn" href="<?= e(url('admin/reports.php', $base + ['export' => 'csv'])) ?>">Export CSV</a>
</p>

<h2 class="s9-h2"><?= e($report['title']) ?></h2>
<p class="s9-muted"><?= e($report['subtitle']) ?></p>
<?= rep_render_html($report) ?>
<?php require AUTH_ROOT . '/includes/staff_footer.php'; ?>
