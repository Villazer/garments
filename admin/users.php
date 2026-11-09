<?php
require __DIR__ . '/_init.php';
require_once AUTH_ROOT . '/includes/user_admin.php';
$me = require_can('users.manage');

// Deactivate / reactivate (POST, then redirect so a refresh cannot repeat it)
if (is_post()) {
    csrf_require();
    $target = (int)($_POST['user_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'deactivate' || $action === 'reactivate') {
            users_set_active($me['user_id'], $target, $action === 'reactivate');
            flash_set('success', $action === 'reactivate' ? 'Account reactivated.' : 'Account deactivated. They can no longer sign in.');
        } else {
            flash_set('error', 'Unknown action.');
        }
    } catch (DomainException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('admin/users.php');
}

$search = clean_str($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : 'all';
$users  = users_all($search, $status);

$page_title = 'Users';
$nav_active = 'users';
require AUTH_ROOT . '/includes/staff_header.php';
?>
    <div class="sp-toolbar">
      <form method="get" class="sp-filter" role="search">
        <label class="sp-sr" for="q">Search users</label>
        <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Search name, username or email" maxlength="60">
        <label class="sp-sr" for="status">Status</label>
        <select id="status" name="status">
          <option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All</option>
          <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
          <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Inactive</option>
        </select>
        <button type="submit" class="sp-btn sp-btn-secondary">Filter</button>
      </form>
      <a class="sp-btn sp-btn-primary" href="<?= e(url('admin/user_form.php')) ?>">Add user</a>
    </div>

    <?php if (!$users): ?>
      <p class="sp-card sp-muted">No users match.</p>
    <?php else: ?>
    <div class="sp-table-wrap">
      <table class="sp-table">
        <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $is_me = (int)$u['user_id'] === $me['user_id']; ?>
          <tr>
            <td data-label="Name"><strong><?= e($u['full_name']) ?></strong><?php if ($is_me): ?> <span class="sp-muted">(you)</span><?php endif; ?>
              <?php if ($u['email']): ?><br><span class="sp-muted sp-small"><?= e($u['email']) ?></span><?php endif; ?></td>
            <td data-label="Username"><?= e($u['username']) ?></td>
            <td data-label="Role"><?= role_badge($u['role']) ?></td>
            <td data-label="Status"><?= (int)$u['is_active'] ? '<span class="sp-badge sp-ok">Active</span>' : '<span class="sp-badge sp-off">Inactive</span>' ?></td>
            <td data-label="Last sign-in"><?= e(format_datetime($u['last_login_at'])) ?></td>
            <td data-label="Actions" class="sp-actions">
              <a class="sp-btn sp-btn-small sp-btn-secondary" href="<?= e(url('admin/user_form.php', ['id' => $u['user_id']])) ?>">Edit</a>
              <?php if (!$is_me): ?>
                <a class="sp-btn sp-btn-small sp-btn-secondary" href="<?= e(url('admin/user_password.php', ['id' => $u['user_id']])) ?>">Reset password</a>
                <form method="post" class="sp-inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                  <?php if ((int)$u['is_active']): ?>
                    <input type="hidden" name="action" value="deactivate">
                    <button type="submit" class="sp-btn sp-btn-small sp-btn-danger"
                            data-confirm="Deactivate <?= e($u['username']) ?>? They will be signed out and cannot sign in.">Deactivate</button>
                  <?php else: ?>
                    <input type="hidden" name="action" value="reactivate">
                    <button type="submit" class="sp-btn sp-btn-small sp-btn-secondary">Reactivate</button>
                  <?php endif; ?>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <p class="sp-muted sp-small">Accounts are never deleted, because orders and the activity log refer to them. Deactivate instead.</p>
<?php require AUTH_ROOT . '/includes/staff_footer.php';
