<?php
// Minimal stand-ins for the Session 1/2 helpers so includes/orders.php can run from the CLI.
// Scratch database only (needs MySQL user t/t on 127.0.0.1, database wmsu_s6_test). Never use real data.
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('mysql:host=127.0.0.1;dbname=wmsu_s6_test;charset=utf8mb4', 't', 't', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
function db_transaction(callable $fn) {
    $pdo = db();
    if ($pdo->inTransaction()) { return $fn(); }
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
}
function clean_str($v): string { return trim((string) $v); }
function get_setting(string $k, $d = null) {
    $v = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $v->execute([$k]);
    $r = $v->fetchColumn();
    return $r === false ? $d : $r;
}
function can(string $p, ?string $role = null): bool {
    $map = [
        'orders.view' => ['staff','manager','admin'], 'orders.process' => ['staff','manager','admin'],
        'orders.override' => ['manager','admin'],
    ];
    return in_array($role, $map[$p] ?? [], true);
}
function order_statuses(): array {
    return ['requested'=>'Requested','approved'=>'Approved','slip_issued'=>'Slip issued','paid'=>'Paid','claimed'=>'Claimed',
            'declined'=>'Declined','expired'=>'Expired','cancelled'=>'Cancelled','unclaimed'=>'Unclaimed'];
}
function format_datetime($v): string { return (string) $v; }
