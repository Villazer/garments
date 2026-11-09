<?php
/**
 * Common start-up for every staff-side page: require this first.
 *
 * ASSUMPTION (Session 1 files were not visible when this was written):
 * includes/bootstrap.php loads config, helpers, db and csrf. If your Session 1
 * entry file has a different name, change the ONE line marked below.
 */
if (!defined('AUTH_ROOT')) {
    define('AUTH_ROOT', dirname(__DIR__));
}

require_once AUTH_ROOT . '/includes/session_boot.php';
auth_session_start();                                  // cookie options must be set before the session starts

require_once AUTH_ROOT . '/includes/bootstrap.php';    // <-- the only Session 1 file name assumed
require_once AUTH_ROOT . '/includes/auth.php';
require_once AUTH_ROOT . '/includes/staff_nav.php';
