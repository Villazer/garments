<?php
/**
 * Minimal stand-ins for the Session 1/2 helpers so the Session 7 library can be
 * tested from the command line against a SCRATCH database (wmsu_s7_test).
 * Never point this at real data. Needs a MySQL user t/t on 127.0.0.1.
 */
$GLOBALS['__pdo'] = null;
function db(): PDO {
    if (!$GLOBALS['__pdo']) {
        $GLOBALS['__pdo'] = new PDO('mysql:host=127.0.0.1;dbname=wmsu_s7_test;charset=utf8mb4', 't', 't',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    }
    return $GLOBALS['__pdo'];
}
function db_transaction(callable $fn) {
    $pdo = db();
    if ($pdo->inTransaction()) { return $fn(); }
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (Throwable $t) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $t; }
}
function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function config($k = null, $d = null) { return $d; }
function can(string $p, $role = null): bool { return true; }
function current_user() { return ['user_id' => 2, 'role' => 'admin']; }
function asset(string $p): string { return '/assets/' . $p; }
function url(string $p, array $q = []): string { return '/' . $p . ($q ? '?' . http_build_query($q) : ''); }
$_SESSION = [];
require __DIR__ . '/../../includes/inventory.php';
