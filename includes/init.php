<?php
/**
 * Bootstrap. Every page starts with:
 *     require __DIR__ . '/includes/init.php';      (admin pages: '/../includes/init.php')
 * It loads config and helpers, sets the timezone, error handling, session,
 * and baseline security headers.
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
ini_set('log_errors', '1');

if (!config('debug')) {
    // On a live server show a generic page instead of a stack trace.
    set_exception_handler(static function (Throwable $ex): void {
        error_log((string)$ex);
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
            . '<p style="font:16px system-ui;margin:2rem">Something went wrong. Please try again later.</p>';
    });
}

if (session_status() === PHP_SESSION_NONE) {
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

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

/** Gate for developer-only pages (setup_check.php, styleguide.php). */
function require_debug_mode(): void
{
    if (!config('debug')) {
        abort(404);
    }
}
