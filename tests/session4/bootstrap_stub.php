<?php
// CLI test stub: provides the few Session 1 helpers that reserve_lib.php needs.
function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=wmsu_garment_shop;charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $pdo;
}
function get_setting($k, $d = null) {
    $v = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?'); $v->execute([$k]);
    $x = $v->fetchColumn(); return $x === false ? $d : (is_numeric($x) ? (int)$x : $x);
}
require __DIR__ . '/../../includes/reserve_lib.php';
