<?php
require_once __DIR__ . '/_init.php';
http_response_code(404);

// Works signed in (full panel) or signed out (bare card, nothing about the panel is revealed).
$staff_layout = is_logged_in() ? 'panel' : 'bare';
$page_title   = 'Page not found';
$nav_active   = '';
require AUTH_ROOT . '/includes/staff_header.php';
?>
    <section class="sp-card sp-center<?= $staff_layout === 'bare' ? ' sp-login' : '' ?>">
      <h2 class="sp-h2">Page not found</h2>
      <p>The page you are looking for does not exist or has moved.</p>
      <p><a class="sp-btn sp-btn-primary" href="<?= e(url($staff_layout === 'panel' ? 'admin/index.php' : 'admin/login.php')) ?>">
        <?= $staff_layout === 'panel' ? 'Back to the dashboard' : 'Go to sign in' ?></a></p>
    </section>
<?php require AUTH_ROOT . '/includes/staff_footer.php';
