<?php
/** Minimal stand-ins for the Session 1 helpers so the Session 5 libraries can run in the CLI. */
$GLOBALS['S5_DB'] = null;
function db(): PDO {
    if (!$GLOBALS['S5_DB']) {
        $GLOBALS['S5_DB'] = new PDO('mysql:host=127.0.0.1;dbname=wmsu_garment_shop;charset=utf8mb4', 't', 't', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $GLOBALS['S5_DB'];
}
function db_run($s, $p = []) { $st = db()->prepare($s); $st->execute($p); return $st; }
function db_all($s, $p = []) { return db_run($s, $p)->fetchAll(); }
function db_one($s, $p = []) { $r = db_run($s, $p)->fetch(); return $r === false ? null : $r; }
function db_value($s, $p = []) { $r = db_run($s, $p)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
function db_transaction(callable $fn) {
    db()->beginTransaction();
    try { $r = $fn(); db()->commit(); return $r; } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}
function get_setting($k, $d = null) { $v = db_value('SELECT setting_value FROM settings WHERE setting_key = ?', [$k]); return $v === null ? $d : $v; }
function clean_str($v) { return trim((string) $v); }
function normalize_ph_mobile($v) { $d = preg_replace('/\D/', '', (string) $v); if (strpos($d, '63') === 0) $d = '0' . substr($d, 2); return preg_match('/^09\d{9}$/', $d) ? $d : null; }
function e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function url($p, $q = []) { return $p . ($q ? '?' . http_build_query($q) : ''); }
function asset($p) { return 'assets/' . $p; }
function format_datetime($v) { return $v ?: '—'; }
function format_date($v, $f = 'Y-m-d') { return $v ? date($f, strtotime($v)) : '—'; }
function money($x) { return '₱' . number_format((float) $x, 2); }
