<?php
declare(strict_types=1);

/*
 * Session 3 bootstrap, shared by every public catalog page.
 *
 * The Session 1 file names were not available when this was written, so the
 * shared bootstrap and the public layout partials are auto-detected. If your
 * names differ, add them to the three lists below (the first file that exists
 * wins). Nothing else in Session 3 depends on those names.
 */
if (!defined('CATALOG_ROOT')) {
    define('CATALOG_ROOT', dirname(__DIR__));
}

function catalog_first_existing(array $relative_paths): ?string
{
    foreach ($relative_paths as $relative) {
        $full = CATALOG_ROOT . '/' . $relative;
        if (is_file($full)) {
            return $full;
        }
    }
    return null;
}

// --- ADJUST HERE if your Session 1 files are named differently -------------
$catalog_boot_files = [
    'includes/bootstrap.php', 'includes/init.php', 'includes/app.php',
    'includes/start.php', 'bootstrap.php', 'init.php', 'config/bootstrap.php',
    'includes/functions.php',
];
$catalog_header_files = [
    'includes/header.php', 'includes/public_header.php', 'includes/layout_header.php',
    'includes/partials/header.php', 'includes/partials/public_header.php',
];
$catalog_footer_files = [
    'includes/footer.php', 'includes/public_footer.php', 'includes/layout_footer.php',
    'includes/partials/footer.php', 'includes/partials/public_footer.php',
];
// ---------------------------------------------------------------------------

$catalog_boot = catalog_first_existing($catalog_boot_files);
if ($catalog_boot === null) {
    http_response_code(500);
    exit('Catalog setup: the Session 1 bootstrap file was not found. Edit $catalog_boot_files in includes/catalog_bootstrap.php.');
}
require_once $catalog_boot;

if (!function_exists('e') || !function_exists('db_all')) {
    http_response_code(500);
    exit('Catalog setup: the file "' . basename($catalog_boot) . '" did not load the Session 1 helpers. Edit $catalog_boot_files in includes/catalog_bootstrap.php.');
}

// Layout partials. If none are found, a minimal built-in layout is used so the
// pages still render while you point the lists above at the real partials.
define('CATALOG_HEADER', catalog_first_existing($catalog_header_files) ?? CATALOG_ROOT . '/includes/catalog_fallback_header.php');
define('CATALOG_FOOTER', catalog_first_existing($catalog_footer_files) ?? CATALOG_ROOT . '/includes/catalog_fallback_footer.php');

require_once __DIR__ . '/catalog_data.php';
require_once __DIR__ . '/catalog_view.php';

/** Tags for the catalog stylesheet and script; echo them right after the header partial. */
function catalog_asset_tags(): string
{
    return '<link rel="stylesheet" href="' . e(asset('css/catalog.css')) . '">'
         . '<script src="' . e(asset('js/catalog.js')) . '" defer></script>';
}
