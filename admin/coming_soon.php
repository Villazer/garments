<?php
require __DIR__ . '/_init.php';
$user = require_login();

// Resolve ?p=<key> against the nav list; unknown keys are 404, keys the role may not use are 403.
$found = null;
foreach (staff_nav_items() as $item) {
    if ($item['key'] === ($_GET['p'] ?? '')) {
        $found = $item;
    }
}
if (!$found) {
    staff_not_found();
}
if (!can($found['perm'], $user['role'])) {
    deny_access();
}

$page_title = $found['label'];
$nav_active = $found['key'];
require AUTH_ROOT . '/includes/staff_header.php';
?>
    <section class="sp-card sp-center">
      <p class="sp-big" aria-hidden="true">🚧</p>
      <h2 class="sp-h2">Coming soon</h2>
      <p>The <strong><?= e($found['label']) ?></strong> section is not built yet. It will appear here in a later update.</p>
      <p><a class="sp-btn sp-btn-secondary" href="<?= e(url('admin/index.php')) ?>">Back to the dashboard</a></p>
    </section>
<?php require AUTH_ROOT . '/includes/staff_footer.php';
