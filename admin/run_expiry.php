<?php
/** "Run now" button target (admin only, POST). Runs the same job as cron/expire_orders.php. */
require __DIR__ . '/_init.php';
$user = require_can('settings.manage');
if (!is_post()) {
    redirect('admin/index.php');
}
csrf_require();
require_once AUTH_ROOT . '/includes/expiry.php';

$res = expiry_run('manual', (int) $user['user_id']);
flash_set($res['failed'] > 0 ? 'warning' : ($res['expired'] > 0 ? 'success' : 'info'), $res['message']);
$back = auth_safe_next($_POST['back'] ?? '');
redirect($back ?: 'admin/index.php');
