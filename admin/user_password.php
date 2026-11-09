<?php
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/user_admin.php';
$me = require_can('users.manage');

$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$target = $id > 0 ? users_find($id) : null;
if (!$target) {
    staff_not_found();
}
if ($id === $me['user_id']) {
    flash_set('info', 'To change your own password, use Change password.');
    redirect('admin/change_password.php');
}

$errors = [];
if (is_post()) {
    csrf_require();
    $new = (string)($_POST['password'] ?? '');
    if ($err = auth_validate_new_password($new, $target['username'])) {
        $errors['password'] = $err;
    } elseif ($new !== (string)($_POST['password_confirm'] ?? '')) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }
    if (!$errors) {
        users_reset_password($me['user_id'], $id, $new);
        flash_set('success', 'Password reset for ' . $target['username'] . '. Any session they had open is now signed out.');
        redirect('admin/users.php');
    }
}

$page_title = 'Reset password';
$nav_active = 'users';
require AUTH_ROOT . '/includes/staff_header.php';
?>
    <section class="sp-card sp-form-card">
      <p>Set a new password for <strong><?= e($target['full_name']) ?></strong> (<?= e($target['username']) ?>).
         Tell them in person and ask them to change it after signing in.</p>
      <form method="post" action="<?= e(url('admin/user_password.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <div class="sp-field">
          <label for="password">New password</label>
          <input type="password" id="password" name="password" autocomplete="new-password" required autofocus>
          <p class="sp-help">At least <?= (int)max(6, (int)config('auth.min_password_length', 8)) ?> characters.</p>
          <?= sp_field_error($errors, 'password') ?>
        </div>
        <div class="sp-field">
          <label for="password_confirm">Confirm new password</label>
          <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
          <?= sp_field_error($errors, 'password_confirm') ?>
        </div>
        <div class="sp-form-actions">
          <button type="submit" class="sp-btn sp-btn-primary">Reset password</button>
          <a class="sp-btn sp-btn-secondary" href="<?= e(url('admin/users.php')) ?>">Cancel</a>
        </div>
      </form>
    </section>
<?php require AUTH_ROOT . '/includes/staff_footer.php';
