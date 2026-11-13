<?php
/**
 * Session 10 patcher. Applies small, exact find-and-replace fixes to files written in Sessions 1-9.
 * Why a tool and not whole files: Session 8 and 9 overwrote some files whose current text this
 * session could not see, so replacing them wholesale could undo that work.
 *
 *   php tools/apply_session10_patches.php --dry-run    show what would change
 *   php tools/apply_session10_patches.php              apply (a copy of each changed file goes to backup/s10/)
 *
 * Safe to run twice: a patch already applied is reported as "already". A patch whose text is not
 * found is reported as "NOT FOUND": do that one by hand from docs/REVIEW_REPORT.md.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$dry  = in_array('--dry-run', $argv, true);
$patches = [];
/** Register a literal patch: file, text to find, replacement, label. */
$add = function (string $file, string $find, string $replace, string $label) use (&$patches) {
    $patches[] = compact('file', 'find', 'replace', 'label');
};

// 1-4. includes/auth.php: password rules, safe redirect target, client IP
$add('includes/auth.php', <<<'X'
const AUTH_ROLES = ['staff', 'manager', 'admin'];
X, <<<'X'
const AUTH_ROLES = ['staff', 'manager', 'admin'];

// Session 10: passwords that are refused because they are the first thing anyone tries
// (includes the sample password printed in the old README).
const AUTH_COMMON_PASSWORDS = [
    'password', 'password1', 'password123', 'passw0rd', '12345678', '123456789', '1234567890', 'qwertyuiop',
    'qwerty123', 'abc12345', 'iloveyou', 'admin123', 'admin1234', 'administrator', 'letmein123', 'welcome123',
    'changeme', 'changeme#2026', 'garments', 'garments123', 'garmentshop', 'wmsu1234', 'wmsu2026', 'wmsugarments',
];
X, 'auth: common-password list');

$add('includes/auth.php', <<<'X'
    if ($username !== '' && strtolower($password) === strtolower($username)) {
X, <<<'X'
    if (in_array(strtolower($password), AUTH_COMMON_PASSWORDS, true) || preg_match('/^(.)\1+$/su', $password)) {
        return 'That password is too easy to guess. Choose something longer and less common.';
    }
    if (ctype_digit($password) && strlen($password) < 12) {
        return 'A password made only of digits must be at least 12 digits long. Mix in letters.';
    }
    if ($username !== '' && strtolower($password) === strtolower($username)) {
X, 'auth: password quality');

$add('includes/auth.php', <<<'X'
(\?[A-Za-z0-9_=&%.\-]*)?$#', $next);
X, <<<'X'
(\?[A-Za-z0-9_=&%.\-]*)?$#D', $next);
X, 'auth: D modifier so a trailing newline is not accepted');

$add('includes/auth.php', <<<'X'
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
X, <<<'X'
    return function_exists('client_ip') ? substr(client_ip(), 0, 45) : substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
X, 'auth: trusted-proxy aware IP');

// 5. public5_common.php client IP
$add('includes/public5_common.php', <<<'X'
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
X, <<<'X'
    return function_exists('client_ip') ? client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
X, 'public5: trusted-proxy aware IP');

// 6. reserve_lib.php: the confirmation/slip gate was limited per session only (a visitor could drop the cookie and retry)
$add('includes/reserve_lib.php', <<<'X'
$locked = ((int)$v['until'] > time());
X, <<<'X'
$locked = ((int)$v['until'] > time());
    require_once __DIR__ . '/public5_common.php';
    $gate_ip  = s5_rate_key('ip', s5_client_ip());
    $gate_ref = s5_rate_key('ref', $ref);
    // Session 10: also limit by IP and by reference, so clearing cookies does not reset the counter.
    if (!$locked && s5_rate_blocked('gate_failed', [$gate_ip => 10, $gate_ref => 20], 15)) { $locked = true; }
X, 'reserve_lib: server-side limit on the verify form');

$add('includes/reserve_lib.php', <<<'X'
$_SESSION['res_verify'] = $v;
X, <<<'X'
$_SESSION['res_verify'] = $v;
        s5_rate_hit('gate_failed', $gate_ip);
        s5_rate_hit('gate_failed', $gate_ref);
X, 'reserve_lib: record failed verifications');

// 7. reserve_lib.php: a guest could overwrite the e-mail of an existing customer row (same phone and name)
$add('includes/reserve_lib.php', <<<'X'
email = COALESCE(NULLIF(?, ''), email), address = COALESCE(NULLIF(?, ''), address)
X, <<<'X'
email = COALESCE(email, NULLIF(?, '')), address = COALESCE(address, NULLIF(?, ''))
X, 'reserve_lib: fill blank customer fields only');

// 8. checkout.php: limit orders per device (stops one person holding all auto-approved stock)
$add('checkout.php', <<<'X'
require_once __DIR__ . '/includes/reserve_lib.php';
X, <<<'X'
require_once __DIR__ . '/includes/reserve_lib.php';
require_once __DIR__ . '/includes/public5_common.php';
X, 'checkout: load rate-limit helpers');

$add('checkout.php', <<<'X'
$res = res_submit(res_list(), $in);
X, <<<'X'
$ip_key = s5_rate_key('ip', s5_client_ip());
        $ip_max = (int)get_setting('public_orders_per_ip_hour', 10);   // 0 = no limit
        if ($ip_max > 0 && s5_rate_count('reserve_submit', $ip_key, 60) >= $ip_max) {
            $res = ['ok' => false, 'problems' => [], 'error' => 'Too many requests were sent from this device. Please try again later or visit the Garment Shop.'];
        } else {
            $res = res_submit(res_list(), $in);
            if (!empty($res['ok'])) { s5_rate_hit('reserve_submit', $ip_key); }
        }
X, 'checkout: per-device order limit');

// 9. waitlist_lib.php: a closed (fulfilled/cancelled) entry was treated as "already on the list"
$add('includes/waitlist_lib.php', <<<'X'
WHERE variant_id = ? AND notified_at IS NULL
X, <<<'X'
WHERE variant_id = ? AND notified_at IS NULL AND status = \'waiting\'
X, 'waitlist: only a waiting entry counts as a duplicate');

// 10. orders.php: UI says an archived item cannot be approved, code did not enforce it
$add('includes/orders.php', <<<'X'
        if (!$v['is_active'] || $avail < $qty) {
X, <<<'X'
        if ((int) ord_value('SELECT i.is_active FROM items i JOIN item_variants v ON v.item_id = i.item_id WHERE v.variant_id = ?', [$vid]) !== 1) {
            throw new DomainException(ord_line_name($lines, $vid) . ' has been archived, so this order cannot be approved. Restore the item first or decline the order.');
        }
        if (!$v['is_active'] || $avail < $qty) {
X, 'orders: refuse approval for archived items');

// 11. session_boot.php: staff sessions get the same hardening as public ones
$add('includes/session_boot.php', <<<'X'
ini_set('session.use_only_cookies', '1');  // never accept ids from the URL
X, <<<'X'
ini_set('session.use_only_cookies', '1');  // never accept ids from the URL
    ini_set('session.gc_maxlifetime', '7200'); // Session 10: do not clean up sessions before the idle timeout
X, 'session_boot: longer garbage-collection time');

$mainfiles = ['track.php', 'toga_request.php', 'toga_confirmation.php', 'waitlist.php', 'restock.php',
              'reserve.php', 'checkout.php', 'confirmation.php', 'slip.php', 'includes/reserve_lib.php'];

$changed = 0; $notfound = 0; $already = 0;
$say = fn(string $s) => print($s . "\n");

foreach ($patches as $p) {
    $path = $root . '/' . $p['file'];
    if (!is_file($path)) { $say("MISSING   {$p['file']}  ({$p['label']})"); $notfound++; continue; }
    $src = file_get_contents($path);
    if (strpos($src, $p['replace']) !== false) { $say("already   {$p['file']}  ({$p['label']})"); $already++; continue; }
    if (strpos($src, $p['find']) === false)    { $say("NOT FOUND {$p['file']}  ({$p['label']}) -> apply by hand"); $notfound++; continue; }
    $new = preg_replace('/' . preg_quote($p['find'], '/') . '/', str_replace(['\\', '$'], ['\\\\', '\\$'], $p['replace']), $src, 1);
    if (!$dry) { backup_once($root, $p['file'], $src); file_put_contents($path, $new); }
    $say(($dry ? 'would fix ' : 'fixed     ') . "{$p['file']}  ({$p['label']})");
    $changed++;
}

// 12. Page markup: pages put a second <main id="main"> inside the layout's own <main>. Two main landmarks and a
//     duplicate id break the "Skip to content" link, so the inner one becomes a plain <div>.
foreach ($mainfiles as $f) {
    $path = $root . '/' . $f;
    if (!is_file($path)) { $say("MISSING   $f  (main -> div)"); $notfound++; continue; }
    $src = file_get_contents($path);
    if (!preg_match('/<main class="(?:s5-page|rv-wrap)/', $src)) { $say("already   $f  (main -> div)"); $already++; continue; }
    $new = preg_replace('/<main class="((?:s5-page|rv-wrap)[^"]*)"( id="main")?/', '<div class="$1"', $src);
    $new = str_replace('</main>', '</div>', $new);
    if (!$dry) { backup_once($root, $f, $src); file_put_contents($path, $new); }
    $say(($dry ? 'would fix ' : 'fixed     ') . "$f  (inner <main> -> <div>)");
    $changed++;
}

function backup_once(string $root, string $file, string $content): void
{
    $dest = $root . '/backup/s10/' . $file;
    if (is_file($dest)) { return; }
    if (!is_dir(dirname($dest))) { mkdir(dirname($dest), 0755, true); }
    file_put_contents($dest, $content);
}

$say("\n" . ($dry ? 'Dry run: ' : '') . "$changed changed, $already already applied, $notfound need attention.");
exit($notfound ? 1 : 0);
