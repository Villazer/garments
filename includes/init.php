<?php
/**
 * Bootstrap. Every page starts with:
 *     require __DIR__ . '/includes/init.php';      (admin pages: '/../includes/init.php')
 * It loads config and helpers, sets the timezone, error handling, session,
 * and baseline security headers.
 *
 * Session 10 changes: client_ip() with trusted proxies, session hardening
 * (strict mode, cookies only, longer garbage-collection time), extra security
 * headers (optional HSTS and Content-Security-Policy), and no session or headers
 * when run from the command line (cron and tools).
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validation.php';

date_default_timezone_set((string)config('timezone', 'Asia/Manila'));
mb_internal_encoding('UTF-8');

// Show errors only while debugging; always log them.
error_reporting(E_ALL);
ini_set('display_errors', config('debug') ? '1' : '0');
ini_set('display_startup_errors', config('debug') ? '1' : '0');
ini_set('log_errors', '1');

if (!config('debug')) {
    // On a live server show a generic page instead of a stack trace.
    set_exception_handler(static function (Throwable $ex): void {
        error_log((string)$ex);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Error: see the PHP error log.\n");
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
            . '<p style="font:16px system-ui;margin:2rem">Something went wrong. Please try again later.</p>';
    });
}

/**
 * The visitor's IP address. REMOTE_ADDR is used unless the request comes from a
 * proxy listed in config 'trusted_proxies' (for example a hosting load balancer
 * or Cloudflare); only then is the first X-Forwarded-For address believed.
 * Never read X-Forwarded-For directly: a visitor can forge it to dodge lockouts.
 */
function client_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $trusted = config('trusted_proxies', []);
    if (is_array($trusted) && $trusted && in_array($remote, $trusted, true)) {
        $fwd = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        if ($fwd !== '' && filter_var($fwd, FILTER_VALIDATE_IP) !== false) {
            return $fwd;
        }
    }
    return $remote;
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // Session settings can only be changed before the session starts.
    ini_set('session.use_strict_mode', '1');      // refuse session ids the server did not create
    ini_set('session.use_only_cookies', '1');     // never accept an id from the URL
    ini_set('session.gc_maxlifetime', '7200');    // a 30 minute idle timeout must not be cut short by 24 minute cleanup
    session_name((string)config('session_name', 'garments_sid'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header_remove('X-Powered-By');
    if (is_https() && config('hsts', false)) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    if (config('csp', false)) {
        // The site uses no external files. Inline scripts are still needed by a few pages.
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
            . "script-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
    }
}

/** Gate for developer-only pages (setup_check.php, styleguide.php). */
function require_debug_mode(): void
{
    if (!config('debug')) {
        abort(404);
    }
}
