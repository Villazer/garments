<?php
/**
 * Bridge file. admin/_init.php (Session 2) loads includes/bootstrap.php;
 * the Session 1 bootstrap is includes/init.php. This file just loads it.
 *
 * init.php loads config, helpers (e, url, redirect, flash_*, money, paginate...),
 * db, csrf, validation, sets the timezone and error handling, and starts the
 * session only if none is active. admin/_init.php starts the session first
 * (auth_session_start), so the HttpOnly/SameSite cookie settings are kept.
 */

declare(strict_types=1);

if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);

require_once __DIR__ . '/init.php';