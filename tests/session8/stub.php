<?php
/**
 * Minimal stand-ins for the Session 1/2 helpers so the Session 8 libraries run from the CLI.
 * SCRATCH database only (MySQL user t/t on 127.0.0.1, database wmsu_s8_test). Never use real data.
 */
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('mysql:host=127.0.0.1;dbname=wmsu_s8_test;charset=utf8mb4', 't', 't', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
function db_transaction(callable $fn) {
    $pdo = db();
    if ($pdo->inTransaction()) { return $fn($pdo); }
    $pdo->beginTransaction();
    try { $r = $fn($pdo); $pdo->commit(); return $r; }
    catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
}
function clean_str($v): string { return is_scalar($v) ? trim((string) $v) : ''; }
function get_setting(string $k, $d = null) {
    $st = db()->prepare('SELECT setting_value, value_type FROM settings WHERE setting_key = ?');
    $st->execute([$k]);
    $r = $st->fetch();
    if (!$r) { return $d; }
    return $r['value_type'] === 'int' ? (int) $r['setting_value'] : $r['setting_value'];
}
function can(string $p, ?string $role = null): bool {
    $map = ['orders.view' => ['staff','manager','admin'], 'orders.process' => ['staff','manager','admin'],
            'orders.override' => ['manager','admin'], 'rentals.view' => ['staff','manager','admin'],
            'rentals.process' => ['staff','manager','admin'], 'walkin.create' => ['staff','manager','admin']];
    return in_array($role, $map[$p] ?? [], true);
}
function order_statuses(): array {
    return ['requested'=>'Requested','approved'=>'Approved','slip_issued'=>'Slip issued','paid'=>'Paid','claimed'=>'Claimed',
            'declined'=>'Declined','expired'=>'Expired','cancelled'=>'Cancelled','unclaimed'=>'Unclaimed'];
}
function format_date($v, string $f = 'M j, Y'): string { return $v ? date($f, strtotime((string) $v)) : '—'; }
function format_datetime($v): string { return (string) $v; }
function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function normalize_ph_mobile($value): ?string {
    $v = preg_replace('/[\s\-().]/', '', clean_str($value));
    return preg_match('/^(?:\+?63|0)(9[0-9]{9})$/', (string) $v, $m) ? '0' . $m[1] : null;
}
function validate_student_no($value) {
    return preg_match('/^([0-9]{4}-[0-9]{5}|[0-9]{12})$/', clean_str($value)) ? null : 'Enter a valid Student number.';
}
require __DIR__ . '/../../includes/orders.php';
require __DIR__ . '/../../includes/rentals.php';
require __DIR__ . '/../../includes/customer_lookup.php';
require __DIR__ . '/../../includes/walkin.php';
