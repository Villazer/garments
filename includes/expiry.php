<?php
/**
 * Session 9: auto-expiry of unpaid reservations (prefix expiry_).
 *
 * One function, expiry_run(), does the work and is used three ways:
 *   1. cron/expire_orders.php           (Windows Task Scheduler or Linux cron)
 *   2. admin/run_expiry.php             (the "Run now" button, admin only)
 *   3. expiry_lazy_run()                (called from staff_header.php, throttled)
 *
 * Each order is moved with transition_order($id, 'expired', null), the ONLY place
 * that changes orders.status: it locks the order, releases the reserved stock,
 * writes the inventory_movements rows and the history row, and soft-deletes the
 * order into the recycle bin (deleted_at / purge_after).
 *
 * Overlap safety, two layers:
 *   - a named database lock (GET_LOCK) so two runs never process at the same time;
 *     a run that cannot get it returns at once. The lock dies with the connection,
 *     so a crashed script cannot leave it stuck.
 *   - transition_order() re-checks the status under SELECT ... FOR UPDATE, so even
 *     the old MySQL event or a staff click at the same moment cannot expire an
 *     order twice or release its stock twice.
 */

require_once __DIR__ . '/shop_settings.php';
require_once __DIR__ . '/orders.php';

const EXPIRY_LOCK = 'garments_expire_orders';

/** The two bookkeeping rows exist even if migration 007 was not run yet. */
function expiry_ensure_settings(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db_run(
        "INSERT IGNORE INTO settings (setting_key, setting_value, value_type, description) VALUES
           ('auto_expiry_last_run', '', 'string', 'When the auto-expiry job last started (maintained by the system)'),
           ('auto_expiry_last_result', '', 'string', 'Result of the last auto-expiry run (maintained by the system)')"
    );
}

/** Open orders whose unpaid hold has ended. Orders with any payment record are never auto-expired. */
function expiry_due_sql(): string
{
    return "FROM orders o
             WHERE o.status IN ('approved','slip_issued')
               AND o.expires_at IS NOT NULL AND o.expires_at <= NOW()
               AND o.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.order_id)";
}

function expiry_due_ids(int $limit = 200): array
{
    $rows = db_all('SELECT o.order_id ' . expiry_due_sql() . ' ORDER BY o.expires_at, o.order_id LIMIT ' . max(1, $limit));
    return array_map('intval', array_column($rows, 'order_id'));
}

function expiry_due_count(): int
{
    return (int) db_value('SELECT COUNT(*) ' . expiry_due_sql());
}

function expiry_audit(?int $user_id, string $source, array $result): void
{
    db_run(
        "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values, ip_address)
         VALUES (?, 'auto_expiry_run', 'system', ?, ?, ?)",
        [
            $user_id, substr($source, 0, 40), json_encode($result, JSON_UNESCAPED_UNICODE),
            isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null,
        ]
    );
}

/**
 * Expire every due order.
 * @param string   $source  'cli', 'manual' or 'lazy' (for the log only)
 * @param int|null $user_id admin who pressed Run now, otherwise null
 * @return array ['ok','skipped','expired','failed','already','ids','message']
 */
function expiry_run(string $source, ?int $user_id = null): array
{
    expiry_ensure_settings();
    $got = (int) db_value('SELECT GET_LOCK(?, 0)', [EXPIRY_LOCK]);
    if ($got !== 1) {
        return ['ok' => true, 'skipped' => true, 'expired' => 0, 'failed' => 0, 'already' => 0, 'ids' => [],
                'message' => 'Another expiry run is already in progress, so this run did nothing.'];
    }

    $expired = 0;
    $failed = 0;
    $already = 0;
    $ids = [];
    $errors = [];
    try {
        foreach (expiry_due_ids() as $id) {
            $r = transition_order($id, 'expired', null, []);
            if ($r['ok']) {
                $expired++;
                $ids[] = $id;
                continue;
            }
            // Someone (the old MySQL event, a staff member) may have expired it a moment ago.
            $row = db_one('SELECT status FROM orders WHERE order_id = ?', [$id]);
            if ($row && $row['status'] === 'expired') {
                $already++;
                continue;
            }
            $failed++;
            $errors[] = '#' . $id . ': ' . $r['message'];
        }

        $summary = 'expired ' . $expired . ', failed ' . $failed . ($already ? ', already done ' . $already : '');
        db_run("UPDATE settings SET setting_value = DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s') WHERE setting_key = 'auto_expiry_last_run'");
        db_run("UPDATE settings SET setting_value = ? WHERE setting_key = 'auto_expiry_last_result'",
            [substr(date('Y-m-d H:i') . ' - ' . $source . ' - ' . $summary, 0, 250)]);
        if ($expired > 0 || $failed > 0 || $source === 'manual') {
            expiry_audit($user_id, $source, ['expired' => $expired, 'failed' => $failed, 'already' => $already,
                                              'order_ids' => array_slice($ids, 0, 50), 'errors' => array_slice($errors, 0, 5)]);
        }
    } finally {
        db_value('SELECT RELEASE_LOCK(?)', [EXPIRY_LOCK]);
    }

    if ($expired === 0 && $failed === 0 && $already === 0) {
        $msg = 'No unpaid reservations are past their hold time.';
    } else {
        $msg = $expired . ' order(s) expired, their stock released and moved to the recycle bin'
            . ($already ? '; ' . $already . ' were already handled' : '')
            . ($failed ? '; ' . $failed . ' could not be expired (' . implode(' ', $errors) . ')' : '') . '.';
    }
    return ['ok' => $failed === 0, 'skipped' => false, 'expired' => $expired, 'failed' => $failed,
            'already' => $already, 'ids' => $ids, 'message' => $msg];
}

/**
 * Claim the right to run, atomically. One UPDATE succeeds for exactly one request
 * per interval, so ten staff pages loading together start only one run.
 */
function expiry_claim_slot(int $minutes): bool
{
    expiry_ensure_settings();
    $minutes = max(1, min(60, $minutes));
    $n = db_run(
        "UPDATE settings SET setting_value = DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')
          WHERE setting_key = 'auto_expiry_last_run'
            AND (setting_value = '' OR setting_value < DATE_FORMAT(DATE_SUB(NOW(), INTERVAL $minutes MINUTE), '%Y-%m-%d %H:%i:%s'))"
    )->rowCount();
    return $n === 1;
}

/** Called when a staff page loads. Throttled, never throws, never blocks the page on failure. */
function expiry_lazy_run(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        if (!shop_truthy(get_setting('auto_expiry_enabled', true))) {
            return;
        }
        $minutes = (int) get_setting('auto_expiry_interval_minutes', 5);
        if (!expiry_claim_slot($minutes)) {
            return;
        }
        expiry_run('lazy', null);
    } catch (Throwable $ex) {
        error_log('expiry_lazy_run: ' . $ex->getMessage());
    }
}

/** For the settings page and the dashboard. */
function expiry_status(): array
{
    $rows = [];
    foreach (db_all("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('auto_expiry_last_run','auto_expiry_last_result')") as $r) {
        $rows[$r['setting_key']] = (string) $r['setting_value'];
    }
    return [
        'enabled'  => shop_truthy(get_setting('auto_expiry_enabled', true)),
        'interval' => max(1, min(60, (int) get_setting('auto_expiry_interval_minutes', 5))),
        'last_run' => $rows['auto_expiry_last_run'] ?? '',
        'result'   => $rows['auto_expiry_last_result'] ?? '',
        'due'      => expiry_due_count(),
    ];
}
