<?php
require __DIR__ . '/_init.php';

if (is_logged_in()) {
    redirect('admin/index.php');
}

$next = auth_safe_next($_GET['next'] ?? ($_POST['next'] ?? ''));

if (is_post()) {
    csrf_require();
    $username = clean_str($_POST['username'] ?? '');
    $result   = auth_attempt_login($username, (string)($_POST['password'] ?? ''));

    if ($result['ok']) {
        redirect($next ?: 'admin/index.php');   // $next already passed the internal-path whitelist
    }

    remember_input(['username' => $username]);  // never the password
    flash_set('error', $result['message']);
    redirect('admin/login.php', $next ? ['next' => $next] : []);   // POST-redirect-GET
}

$staff_layout = 'bare';
$page_title   = 'Staff sign in';
require AUTH_ROOT . '/includes/staff_header.php';
?>
    <section class="sp-card sp-login">
      <h1 class="sp-login-title">WMSU Garment Shop</h1>
      <p class="sp-muted">Staff sign in</p>

      <form method="post" action="<?= e(url('admin/login.php')) ?>" autocomplete="on" novalidate>
        <?= csrf_field() ?>
        <?php if ($next): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>

        <div class="sp-field">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" value="<?= e(old('username')) ?>"
                 maxlength="50" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </div>
        <div class="sp-field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="sp-btn sp-btn-primary sp-btn-block">Sign in</button>
      </form>

      <p class="sp-muted sp-small"><a class="sp-link" href="<?= e(url('index.php')) ?>">Back to the garment shop</a></p>
    </section>
<?php require AUTH_ROOT . '/includes/staff_footer.php';
