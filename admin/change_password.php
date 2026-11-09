<?php
require __DIR__ . '/_init.php';
$me = require_can('password.change');

$error = null;
if (is_post()) {
    csrf_require();
    $error = auth_change_own_password(
        $me,
        (string)($_POST['current_password'] ?? ''),
        (string)($_POST['new_password'] ?? ''),
        (string)($_POST['confirm_password'] ?? '')
    );
    if ($error === null) {
        flash_set('success', 'Your password was changed. Other devices have been signed out.');
        redirect('admin/index.php');
    }
}

$page_title = 'Change my password';
$nav_active = '';
require AUTH_ROOT . '/includes/staff_header.php';
?>
    <section class="sp-card sp-form-card">
      <?php if ($error): ?><p class="sp-field-error" role="alert"><?= e($error) ?></p><?php endif; ?>
      <form method="post" action="<?= e(url('admin/change_password.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="sp-field">
          <label for="current_password">Current password</label>
          <input type="password" id="current_password" name="current_password" autocomplete="current-password" required autofocus>
        </div>
        <div class="sp-field">
          <label for="new_password">New password</label>
          <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
          <p class="sp-help">At least <?= (int)max(6, (int)config('auth.min_password_length', 8)) ?> characters.</p>
        </div>
        <div class="sp-field">
          <label for="confirm_password">Confirm new password</label>
          <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
        </div>
        <div class="sp-form-actions">
          <button type="submit" class="sp-btn sp-btn-primary">Change password</button>
          <a class="sp-btn sp-btn-secondary" href="<?= e(url('admin/index.php')) ?>">Cancel</a>
        </div>
      </form>
    </section>
<?php require AUTH_ROOT . '/includes/staff_footer.php';
