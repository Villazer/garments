<?php
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/user_admin.php';
$me = require_can('users.manage');

$id      = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$editing = $id > 0;
$target  = $editing ? users_find($id) : null;
if ($editing && !$target) {
    staff_not_found();
}
$is_self = $editing && $id === $me['user_id'];

$errors = [];
$form = $editing
    ? ['username' => $target['username'], 'full_name' => $target['full_name'], 'email' => (string)$target['email'], 'role' => $target['role']]
    : ['username' => '', 'full_name' => '', 'email' => '', 'role' => 'staff'];

if (is_post()) {
    csrf_require();
    $input = $_POST;
    if ($is_self) {
        $input['role'] = $target['role'];   // own role is locked, whatever was posted
    }
    [$errors, $clean] = users_validate($input, $editing ? $id : null);
    $form = $clean;
    if ($editing) {
        $form['username'] = $target['username'];
    }

    if (!$errors) {
        try {
            if ($editing) {
                users_update($me['user_id'], $id, $clean);
                flash_set('success', 'Changes saved.');
            } else {
                users_create($me['user_id'], $clean, (string)$_POST['password']);
                flash_set('success', 'User "' . $clean['username'] . '" created. Give them the password in person and ask them to change it.');
            }
            redirect('admin/users.php');
        } catch (DomainException $e) {
            $errors['form'] = $e->getMessage();
        }
    }
}

$page_title = $editing ? 'Edit user' : 'Add user';
$nav_active = 'users';
require AUTH_ROOT . '/includes/staff_header.php';
?>
    <section class="sp-card sp-form-card">
      <?php if (isset($errors['form'])): ?><p class="sp-field-error" role="alert"><?= e($errors['form']) ?></p><?php endif; ?>
      <form method="post" action="<?= e(url('admin/user_form.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$id ?>"><?php endif; ?>

        <div class="sp-field">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" value="<?= e($form['username']) ?>" maxlength="50"
                 autocapitalize="none" spellcheck="false" autocomplete="off" <?= $editing ? 'readonly' : 'required' ?>>
          <?php if ($editing): ?><p class="sp-help">The username cannot be changed.</p><?php endif; ?>
          <?= sp_field_error($errors, 'username') ?>
        </div>

        <div class="sp-field">
          <label for="full_name">Full name</label>
          <input type="text" id="full_name" name="full_name" value="<?= e($form['full_name']) ?>" maxlength="120" required>
          <?= sp_field_error($errors, 'full_name') ?>
        </div>

        <div class="sp-field">
          <label for="email">Email <span class="sp-muted">(optional)</span></label>
          <input type="email" id="email" name="email" value="<?= e($form['email']) ?>" maxlength="120" autocomplete="off">
          <?= sp_field_error($errors, 'email') ?>
        </div>

        <div class="sp-field">
          <label for="role">Role</label>
          <select id="role" name="role" <?= $is_self ? 'disabled' : 'required' ?>>
            <?php foreach (AUTH_ROLES as $r): ?>
              <option value="<?= e($r) ?>"<?= $form['role'] === $r ? ' selected' : '' ?>><?= e(role_label($r)) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($is_self): ?><p class="sp-help">You cannot change your own role.</p>
          <?php else: ?><p class="sp-help"><?= e(role_description($form['role'] ?: 'staff')) ?> Staff, Manager and Administrator differ as listed on the dashboard.</p><?php endif; ?>
          <?= sp_field_error($errors, 'role') ?>
        </div>

        <?php if (!$editing): ?>
        <div class="sp-field">
          <label for="password">Initial password</label>
          <input type="password" id="password" name="password" autocomplete="new-password" required>
          <p class="sp-help">At least <?= (int)max(6, (int)config('auth.min_password_length', 8)) ?> characters.</p>
          <?= sp_field_error($errors, 'password') ?>
        </div>
        <div class="sp-field">
          <label for="password_confirm">Confirm password</label>
          <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
          <?= sp_field_error($errors, 'password_confirm') ?>
        </div>
        <?php endif; ?>

        <div class="sp-form-actions">
          <button type="submit" class="sp-btn sp-btn-primary"><?= $editing ? 'Save changes' : 'Create user' ?></button>
          <a class="sp-btn sp-btn-secondary" href="<?= e(url('admin/users.php')) ?>">Cancel</a>
        </div>
      </form>
    </section>
<?php require AUTH_ROOT . '/includes/staff_footer.php';
