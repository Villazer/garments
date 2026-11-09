<?php
/**
 * Session cookie hardening for staff-side pages.
 *
 * Why a separate file: the cookie options (HttpOnly, SameSite=Lax) can only be
 * set BEFORE the session starts, and admin/_init.php calls this before the
 * Session 1 bootstrap so these options win. It uses only native PHP so it works
 * before any helper is loaded.
 */
function auth_session_start(): void
{
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    ini_set('session.use_strict_mode', '1');   // refuse session ids the server did not create
    ini_set('session.use_only_cookies', '1');  // never accept ids from the URL

    session_set_cookie_params([
        'lifetime' => 0,          // browser-session cookie; idle expiry is enforced server-side
        'path'     => '/',
        'secure'   => $https,     // Secure flag only when actually on HTTPS (XAMPP localhost is HTTP)
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
