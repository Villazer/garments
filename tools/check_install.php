<?php
/**
 * Production readiness check (Session 10). Command line only, so it can stay on the live server
 * (setup_check.php is a browser page and must not).   php tools/check_install.php
 * Exit code 1 when any FAIL is found.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/init.php';

$fail = 0; $warn = 0;
$out = function (string $level, string $what, string $detail = '') use (&$fail, &$warn) {
    if ($level === 'FAIL') { $fail++; } elseif ($level === 'WARN') { $warn++; }
    printf("%-5s %s%s\n", $level, $what, $detail !== '' ? ' - ' . $detail : '');
};
$root = APP_ROOT;

$out(version_compare(PHP_VERSION, '8.0.0', '>=') ? 'PASS' : 'FAIL', 'PHP 8.0 or newer', PHP_VERSION);
foreach (['pdo_mysql', 'mbstring', 'json', 'fileinfo', 'gd'] as $ext) {
    $req = $ext !== 'gd';
    $out(extension_loaded($ext) ? 'PASS' : ($req ? 'FAIL' : 'WARN'), "PHP extension $ext", extension_loaded($ext) ? '' : ($req ? 'enable it in php.ini' : 'optional'));
}
$out(!config('debug') ? 'PASS' : 'FAIL', "'debug' is off in config.php", config('debug') ? 'set it to false on the real server' : '');
$out(config('timezone') === 'Asia/Manila' ? 'PASS' : 'WARN', 'Timezone', (string)config('timezone'));
$out(!ini_get('display_errors') || in_array(strtolower((string)ini_get('display_errors')), ['0', 'off', ''], true) ? 'PASS' : 'WARN', 'display_errors', 'php.ini value for the web server may differ from this CLI check');

foreach (['includes', 'config', 'database', 'tests', 'tools', 'docs', 'cron', 'uploads', 'uploads/items'] as $d) {
    $f = "$root/$d/.htaccess";
    $out(is_file($f) ? 'PASS' : 'FAIL', "$d/.htaccess present", is_file($f) ? '' : 'folder is open to the web');
}
$out(is_file("$root/.htaccess") ? 'PASS' : 'FAIL', 'root .htaccess present');
foreach (['setup_check.php', 'styleguide.php'] as $dev) {
    $out(is_file("$root/$dev") ? 'WARN' : 'PASS', "$dev removed from the server", is_file("$root/$dev") ? 'delete it (the .htaccess blocks it, but it should not be there)' : '');
}
$out(is_dir("$root/uploads/items") && is_writable("$root/uploads/items") ? 'PASS' : 'FAIL', 'uploads/items is writable');
$out(is_dir("$root/tests") ? 'WARN' : 'PASS', 'tests/ folder not on the server', 'tests use a throwaway database; remove from production');

try {
    db_value('SELECT 1');
    $out('PASS', 'Database connection');
} catch (Throwable $e) {
    $out('FAIL', 'Database connection', 'check config.php');
    exit(1);
}
$u = (string)config('db.user'); $p = (string)config('db.pass');
$out($u === 'root' || $p === '' ? 'FAIL' : 'PASS', 'Database user is not root / has a password', $u === 'root' || $p === '' ? 'create garments_app (README section 4)' : '');
$out($p === 'CHANGE-THIS-PASSWORD' ? 'FAIL' : 'PASS', 'Database password changed from the sample');

$col = fn(string $t, string $c) => (int)db_value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]) > 0;
foreach ([['waitlist', 'quantity_wanted', '003'], ['waitlist', 'status', '005'], ['rentals', 'issued_by', '006']] as [$t, $c, $m]) {
    $out($col($t, $c) ? 'PASS' : 'FAIL', "Migration $m applied ($t.$c)");
}
$out((int)db_value("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_images'") ? 'PASS' : 'WARN', 'Migration 001 (item_images)');
$out((int)db_value("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = 'idx_orders_list'") ? 'PASS' : 'WARN', 'Migration 008 (indexes)');
foreach (['hold_unpaid_hours', 'order_slip_format', 'reservations_open', 'toga_rental_days'] as $k) {
    $out(db_value('SELECT 1 FROM settings WHERE setting_key = ?', [$k]) ? 'PASS' : 'WARN', "Setting $k exists", 'migrations 004/006/007');
}

// time zones: PHP and MySQL must agree or holds expire at the wrong time
$diff = abs(strtotime((string)db_value('SELECT NOW()')) - time());
$out($diff < 120 ? 'PASS' : 'FAIL', 'PHP and MySQL clocks agree', "difference {$diff}s");
$sched = db_one("SHOW VARIABLES LIKE 'event_scheduler'");
$out($sched && strtoupper((string)$sched['Value']) === 'ON' ? 'PASS' : 'WARN', 'MySQL event scheduler', 'off is fine only if cron/maintenance.php is scheduled');

// sample data and default passwords
$sample = (int)db_value("SELECT COUNT(*) FROM orders WHERE order_ref LIKE 'GS-%'");
$out($sample === 0 ? 'PASS' : 'FAIL', 'No sample orders (GS-... references)', $sample ? "$sample found: run database/clear_sample_data.sql BEFORE go-live" : '');
$ph = (int)db_value("SELECT COUNT(*) FROM users WHERE password_hash LIKE 'REPLACE_WITH%'");
$out($ph === 0 ? 'PASS' : 'WARN', 'No placeholder user accounts', $ph ? "$ph account(s): delete them in Users" : '');
foreach (db_all("SELECT username, password_hash FROM users WHERE is_active = 1") as $r) {
    foreach (['ChangeMe#2026', 'admin', 'password', '12345678'] as $guess) {
        if (password_verify($guess, $r['password_hash'])) {
            $out('FAIL', "Account {$r['username']} uses a well-known password", 'tools/set_password.php ' . $r['username']);
        }
    }
}
$admins = (int)db_value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
$out($admins >= 1 ? 'PASS' : 'FAIL', 'At least one active administrator');
$out($admins >= 2 ? 'PASS' : 'WARN', 'A second administrator exists', 'so one forgotten password does not lock everyone out');

echo "\n$fail failed, $warn warnings.\n";
exit($fail ? 1 : 0);
