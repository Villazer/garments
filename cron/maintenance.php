<?php
/**
 * Daily maintenance (Session 10). Command line only:
 *     C:\xampp\php\php.exe C:\xampp\htdocs\garments\cron\maintenance.php
 * Schedule once a day (README section 8). Safe to run any time and more than once.
 *
 *  1. Deletes old throttle rows from audit_logs (they grow with every tracking lookup and failed login).
 *  2. Runs the three daily jobs that used to exist only as MySQL events (flag unclaimed orders, purge the
 *     recycle bin, mark overdue rentals), so they also run on hosts where events are off or not allowed.
 *  3. Checks that reserved stock matches the order lines holding it, and prints a warning if not.
 * Exit code: 0 all good, 1 a job failed, 2 a stock mismatch was found.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/init.php';

$exit = 0;
$say = static function (string $s): void { echo '[' . date('Y-m-d H:i:s') . '] ' . $s . "\n"; };

// 1. prune throttle rows
$n1 = db_run("DELETE FROM audit_logs WHERE entity_type = 'rate_limit' AND created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)")->rowCount();
$n2 = db_run("DELETE FROM audit_logs WHERE entity_type = 'auth' AND action IN ('login_failed', 'password_check_failed')
               AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)")->rowCount();
$say("Pruned $n1 rate-limit rows and $n2 old failed-login rows.");

// 2. daily jobs (the MySQL events do the same work; running both is harmless)
foreach (['sp_flag_unclaimed_orders' => 'orders flagged unclaimed',
          'sp_purge_recycle_bin'    => 'recycle-bin orders purged',
          'sp_mark_overdue_rentals' => 'rentals marked overdue'] as $proc => $label) {
    try {
        $st = db()->query('CALL ' . $proc . '()');
        $row = $st ? $st->fetch(PDO::FETCH_NUM) : false;
        if ($st) { $st->closeCursor(); }
        $say(($row ? (int)$row[0] : 0) . ' ' . $label . '.');
    } catch (Throwable $ex) {
        $say("FAILED $proc: " . $ex->getMessage());
        $exit = max($exit, 1);
    }
}

// 3. stock consistency: reserved must equal the quantity on lines flagged stock_held, and never exceed on hand
$bad = db_all(
    'SELECT v.variant_id, v.sku, v.quantity_on_hand, v.quantity_reserved,
            COALESCE((SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.variant_id = v.variant_id AND oi.stock_held = 1), 0) AS held
       FROM item_variants v
      WHERE v.quantity_reserved <> COALESCE((SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.variant_id = v.variant_id AND oi.stock_held = 1), 0)
         OR v.quantity_reserved > v.quantity_on_hand'
);
if ($bad) {
    $exit = 2;
    $say('WARNING: ' . count($bad) . ' size(s) have reserved stock that does not match the orders holding it:');
    foreach ($bad as $b) {
        $say("  {$b['sku']}: on hand {$b['quantity_on_hand']}, reserved {$b['quantity_reserved']}, held by orders {$b['held']}");
    }
    $say('Check Inventory > Movements for these sizes before correcting anything.');
} else {
    $say('Stock check passed: reserved stock matches the held order lines.');
}
exit($exit);
