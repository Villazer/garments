<?php
/**
 * Session 9: small settings helpers that are safe to load on every page,
 * public and staff. The admin settings logic lives in settings_lib.php.
 */

if (!function_exists('shop_truthy')) {

/** '1', 'true', 'yes', 'on' (any case) and true are "on". */
function shop_truthy($v): bool
{
    if (is_string($v)) {
        return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true);
    }
    return (bool) $v;
}

/** Shop details: the settings table wins, config/config.php is the fallback. $key: name, address, hours, phone, email. */
function shop_info(string $key): string
{
    $v = trim((string) get_setting('shop_' . $key, ''));
    return $v !== '' ? $v : (string) config('shop.' . $key, '');
}

function shop_reservations_open(): bool
{
    return shop_truthy(get_setting('reservations_open', true));
}

function shop_closed_message(): string
{
    $hours = shop_info('hours');
    return 'Online reservations and toga requests are closed right now. Please visit the Garment Shop'
        . ($hours !== '' ? ' (' . $hours . ').' : '.');
}

/** Global ceiling for items.max_qty_per_request on online requests (1 to 50). */
function shop_qty_limit(): int
{
    return max(1, min(50, (int) get_setting('max_qty_per_request_limit', 50)));
}

/**
 * Called from catalog_bootstrap.php on every public page. While reservations are
 * closed it stops the three pages that create orders. Staff walk-in orders and
 * the tracking, waitlist and restock pages are not affected.
 */
function shop_guard_public_posts(): void
{
    if (shop_reservations_open()) {
        return;
    }
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === 'checkout.php' || ($script === 'reserve_add.php' && is_post())) {
        flash_set('warning', shop_closed_message());
        redirect('catalog.php');
    }
    if ($script === 'toga_request.php') {
        flash_set('warning', shop_closed_message());
        if (is_post()) {
            redirect('toga_request.php');
        }
        // On a plain page view the message is printed by this same request.
    }
}

}
