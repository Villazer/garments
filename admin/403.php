<?php
require_once __DIR__ . '/_init.php';
$user = require_login();     // anonymous visitors go to the sign-in page instead
http_response_code(403);

$page_title = 'Access denied';
$nav_active = '';
require AUTH_ROOT . '/includes/staff_header.php';
?>
    <section class="sp-card sp-center">
      <h2 class="sp-h2">You do not have access to this page</h2>
      <p>Your role (<?= e(role_label($user['role'])) ?>) is not allowed to open it. If you think this is a mistake, ask an administrator.</p>
      <p><a class="sp-btn sp-btn-primary" href="<?= e(url('admin/index.php')) ?>">Back to the dashboard</a></p>
    </section>
<?php require AUTH_ROOT . '/includes/staff_footer.php';
