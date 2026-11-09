<?php
require __DIR__ . '/_init.php';

// POST only: a plain link would let any web page sign staff out.
if (!is_post()) {
    redirect(is_logged_in() ? 'admin/index.php' : 'admin/login.php');
}
csrf_require();

$user = current_user();
if ($user) {
    auth_log('logout', $user['user_id'], 'auth', auth_username_key($user['username']));
}
auth_clear_session();
flash_set('success', 'You have been signed out.');
redirect('admin/login.php');
