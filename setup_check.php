<?php
/**
 * Installation check: PHP, extensions, database connection, expected tables.
 * Developer page: only opens when 'debug' is true. Set debug to false (and
 * delete this file) before the site goes live.
 */
require __DIR__ . '/includes/init.php';
require_debug_mode();

$page_title = 'Setup check';
$active_nav = '';

$checks = [];
/** Record one result. $status: ok, warn or fail. */
$add = static function (string $label, string $status, string $detail = '') use (&$checks): void {
    $checks[] = [$label, $status, $detail];
};

// --- PHP and extensions ------------------------------------------------
$add('PHP version 8.0 or newer', version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'fail', 'Running PHP ' . PHP_VERSION);
foreach (['pdo' => 'PDO', 'pdo_mysql' => 'PDO MySQL driver', 'mbstring' => 'mbstring', 'json' => 'JSON', 'session' => 'Sessions'] as $ext => $name) {
    $add("Extension: $name", extension_loaded($ext) ? 'ok' : 'fail',
        extension_loaded($ext) ? 'Loaded' : "Enable extension=$ext in php.ini (XAMPP: Apache > Config > php.ini), then restart Apache");
}
$add('Config file', 'ok', 'config/config.php loaded; base URL "' . config('base_url') . '"');
$add('Timezone', date_default_timezone_get() === 'Asia/Manila' ? 'ok' : 'warn', 'PHP timezone is ' . date_default_timezone_get() . '; local time ' . date('Y-m-d H:i'));
$add('Debug mode', config('debug') ? 'warn' : 'ok', config('debug') ? 'ON (fine on your own computer; must be OFF on a live server)' : 'Off');
$add('uploads/ folder writable', is_writable(APP_ROOT . '/uploads') ? 'ok' : 'warn', is_writable(APP_ROOT . '/uploads') ? 'Writable' : 'Not writable; needed only if uploads are added later');

// --- Database ------------------------------------------------------------
$connected = false;
if (extension_loaded('pdo_mysql')) {
    try {
        $version = db_value('SELECT VERSION()');
        $connected = true;
        $add('Database connection', 'ok', 'Connected to "' . config('db.name') . '" (server ' . $version . ')');
    } catch (Throwable $ex) {
        $add('Database connection', 'fail',
            $ex->getMessage() . ' Check the db settings in config/config.php, that MySQL is running, and that you created the database and imported database/schema.sql.');
    }
}

if ($connected) {
    $names = static fn(string $sql): array => array_column(db_all($sql), 'n');

    $expected_tables = ['users', 'education_levels', 'courses', 'categories', 'academic_periods', 'customers', 'students',
        'items', 'item_variants', 'toga_units', 'orders', 'order_items', 'measurements', 'payments', 'rentals',
        'inventory_movements', 'restock_schedule', 'waitlist', 'settings', 'audit_logs'];
    $have = $names("SELECT TABLE_NAME AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    $missing = array_diff($expected_tables, array_map('strtolower', $have));
    $add('Tables (' . count($expected_tables) . ' expected)', $missing ? 'fail' : 'ok',
        $missing ? 'Missing: ' . implode(', ', $missing) . '. Import database/schema.sql.' : 'All present');

    $views = $names("SELECT TABLE_NAME AS n FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()");
    $add('View v_catalog_stock', in_array('v_catalog_stock', $views, true) ? 'ok' : 'fail', in_array('v_catalog_stock', $views, true) ? 'Present' : 'Missing');

    $expected_routines = ['fn_setting_int', 'sp_add_order_item', 'sp_approve_order', 'sp_release_order', 'sp_expire_unpaid_orders',
        'sp_flag_unclaimed_orders', 'sp_purge_recycle_bin', 'sp_restore_order', 'sp_record_payment', 'sp_claim_order',
        'sp_return_rental', 'sp_mark_overdue_rentals'];
    $routines = $names('SELECT ROUTINE_NAME AS n FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()');
    $missing = array_diff($expected_routines, $routines);
    $add('Stored procedures and function (' . count($expected_routines) . ' expected)', $missing ? 'fail' : 'ok',
        $missing ? 'Missing: ' . implode(', ', $missing) . '. Re-import schema.sql in phpMyAdmin (not by pasting into the SQL tab).' : 'All present');

    $events = $names('SELECT EVENT_NAME AS n FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()');
    $add('Scheduled events (4 expected)', count($events) >= 4 ? 'ok' : 'warn', count($events) . ' found');
    $scheduler = db_one("SHOW VARIABLES LIKE 'event_scheduler'");
    $on = $scheduler && strtoupper((string)$scheduler['Value']) === 'ON';
    $add('MySQL event scheduler', $on ? 'ok' : 'warn',
        $on ? 'ON: expiry jobs run by themselves' : 'OFF (XAMPP default). Run SET GLOBAL event_scheduler = ON; or use the cron/ scripts (Session 9)');

    if (!count(array_filter($checks, static fn($c) => $c[1] === 'fail'))) {
        $users = (int)db_value('SELECT COUNT(*) FROM users');
        $items = (int)db_value('SELECT COUNT(*) FROM items');
        $add('Data loaded', ($users > 0 && $items > 0) ? 'ok' : 'warn', "$users user(s), $items item(s)" . ($users > 0 ? '' : '. Import database/seed.sql.'));
        $admin = db_one("SELECT password_hash FROM users WHERE username = 'admin'");
        if ($admin && password_verify('ChangeMe#2026', $admin['password_hash'])) {
            $add('Admin password', 'warn', 'The admin account still uses the default password from the README. Change it before real use.');
        }
    }
}

$fails = count(array_filter($checks, static fn($c) => $c[1] === 'fail'));
$warns = count(array_filter($checks, static fn($c) => $c[1] === 'warn'));
$badge = ['ok' => ['in', 'Pass'], 'warn' => ['low', 'Check'], 'fail' => ['out', 'Fail']];

require __DIR__ . '/includes/public_header.php';
?>
<div class="container section">
    <div class="page-header">
        <h1>Setup check</h1>
        <p>Confirms this computer is ready to run the garment shop site.</p>
    </div>

    <?php if ($fails > 0): ?>
        <div class="alert alert--error"><span class="alert__text"><strong><?= $fails ?> problem(s) to fix.</strong> Read the Details column, fix, then reload this page.</span></div>
    <?php elseif ($warns > 0): ?>
        <div class="alert alert--warning"><span class="alert__text"><strong>Ready, with <?= $warns ?> item(s) to review.</strong></span></div>
    <?php else: ?>
        <div class="alert alert--success"><span class="alert__text"><strong>Everything looks good.</strong></span></div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table--striped">
            <thead><tr><th>Check</th><th>Result</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($checks as [$label, $status, $detail]): ?>
                <tr>
                    <td><?= e($label) ?></td>
                    <td><span class="badge badge--<?= e($badge[$status][0]) ?>"><?= e($badge[$status][1]) ?></span></td>
                    <td><?= e($detail) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted text-sm">Delete or disable this page for a live site: set <code>'debug' =&gt; false</code> in config/config.php.</p>
</div>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
