<?php
/**
 * Session cookie hardening for staff-side pages.
 *
 * Why a separate file: the cookie options (HttpOnly, SameSite=Lax) can only be
 * set BEFORE the session starts, and admin/_init.php calls this before the
 * Session 1 bootstrap so these options win.
 *
 * Session 8 fix: the session now uses the configured name ('session_name' in
 * config/config.php, default garments_sid), the same one includes/init.php uses.
 * Before, staff pages started a session under PHP's default name, so staff and
 * public pages never shared one and "signed-in staff may view" on the public
 * confirmation/slip pages never worked. config() is available because
 * admin/_init.php loads includes/helpers.php first.
 */
function auth_session_start(): void
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');   // refuse session ids the server did not create
    ini_set('session.use_only_cookies', '1');  // never accept ids from the URL

    if (function_exists('config')) {
        session_name((string)config('session_name', 'garments_sid'));
    }
    session_set_cookie_params([
        'lifetime' => 0,          // browser-session cookie; idle expiry is enforced server-side
        'path'     => '/',
        'secure'   => $https,     // Secure flag only when actually on HTTPS (XAMPP localhost is HTTP)
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
