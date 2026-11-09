<?php
require __DIR__ . '/_init.php';
$user = require_can('dashboard.view');

$page_title = 'Dashboard';
$nav_active = 'dashboard';
require AUTH_ROOT . '/includes/staff_header.php';

$shortcuts = array_filter(staff_nav_visible(), fn($i) => $i['key'] !== 'dashboard');
?>
    <section class="sp-card">
      <p>Signed in as <strong><?= e($user['full_name']) ?></strong> (<?= e($user['username']) ?>) &middot; <?= role_badge($user['role']) ?></p>
      <p class="sp-muted"><?= e(role_description($user['role'])) ?></p>
    </section>

    <h2 class="sp-h2">Your sections</h2>
    <div class="sp-grid">
      <?php foreach ($shortcuts as $item): ?>
        <a class="sp-tile" href="<?= e($item['href']) ?>">
          <span class="sp-tile-name"><?= e($item['label']) ?></span>
          <span class="sp-muted sp-small"><?= $item['ready'] ? 'Open' : 'Coming soon' ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <p class="sp-muted sp-small">Order counts and low-stock alerts will appear here in a later update.</p>
<?php require AUTH_ROOT . '/includes/staff_footer.php';
