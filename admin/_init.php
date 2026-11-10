<?php
/**
 * Common start-up for every staff-side page: require this first.
 *
 * Session 8 fix: the old version required includes/bootstrap.php, which does not exist in
 * this project (the Session 1 entry file is includes/init.php), so every staff page stopped
 * with a fatal error. It now uses includes/bootstrap.php only if somebody has created one,
 * and includes/init.php otherwise.
 */
if (!defined('AUTH_ROOT')) {
    define('AUTH_ROOT', dirname(__DIR__));
}

require_once AUTH_ROOT . '/includes/helpers.php';      // config() is needed to name the session
require_once AUTH_ROOT . '/includes/session_boot.php';
auth_session_start();                                  // cookie options must be set before the session starts

require_once AUTH_ROOT . (is_file(AUTH_ROOT . '/includes/bootstrap.php') ? '/includes/bootstrap.php' : '/includes/init.php');
require_once AUTH_ROOT . '/includes/auth.php';
require_once AUTH_ROOT . '/includes/staff_nav.php';
