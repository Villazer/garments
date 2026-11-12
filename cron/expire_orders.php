<?php
/**
 * Expire unpaid reservations whose hold has ended (Session 9).
 *
 *   Windows Task Scheduler (every 5 minutes):
 *     Program:   C:\xampp\php\php.exe
 *     Arguments: C:\xampp\htdocs\garments\cron\expire_orders.php
 *   Linux cron:
 *     crontab line: every 5 minutes, command  php /var/www/garments/cron/expire_orders.php
 *
 * Safe to run while the site is in use and safe if two runs overlap.
 * Exit code 0 = fine (including "nothing to do" and "another run is active"), 1 = at least one order failed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// A command-line run needs no session cookie.
ini_set('session.use_cookies', '0');
require __DIR__ . '/../includes/init.php';
if (function_exists('session_abort') && session_status() === PHP_SESSION_ACTIVE) {
    session_abort();
}
require_once APP_ROOT . '/includes/expiry.php';

$res = expiry_run('cli', null);
fwrite(STDOUT, date('Y-m-d H:i:s') . ' ' . $res['message'] . "\n");
exit($res['failed'] > 0 ? 1 : 0);
