<?php
/**
 * Sidebar definition and small UI helpers for the staff panel.
 *
 * A link goes to its real page as soon as that file exists. Until then it goes
 * to admin/coming_soon.php?p=<key>, so a later session only has to create the
 * file at the path listed here; this list does not need editing.
 * The 'perm' value decides who sees the link (see auth_permissions()).
 * Every real page must still call require_can()/require_role() itself.
 *
 * Session 8: Toga Rentals now points at admin/toga_rentals.php (it was admin/rentals.php,
 * a file that was never built) and Customer History was added.
 */
function staff_nav_items(): array
{
    return [
        ['key' => 'dashboard', 'label' => 'Dashboard',            'path' => 'admin/index.php',          'perm' => 'dashboard.view', 'group' => 'Overview'],
        ['key' => 'orders',    'label' => 'Orders',               'path' => 'admin/orders.php',         'perm' => 'orders.view',    'group' => 'Operations'],
        ['key' => 'walkin',    'label' => 'Walk-in',              'path' => 'admin/walkin.php',         'perm' => 'walkin.create',  'group' => 'Operations'],
        ['key' => 'rentals',   'label' => 'Toga Rentals',         'path' => 'admin/toga_rentals.php',   'perm' => 'rentals.view',   'group' => 'Operations'],
        ['key' => 'history',   'label' => 'Customer History',     'path' => 'admin/customer_history.php', 'perm' => 'orders.view',  'group' => 'Operations'],
        ['key' => 'inventory', 'label' => 'Inventory',            'path' => 'admin/inventory.php',      'perm' => 'inventory.view', 'group' => 'Operations'],
        ['key' => 'restock',   'label' => 'Restock and Waitlist', 'path' => 'admin/restock.php',        'perm' => 'restock.view',   'group' => 'Operations'],
        ['key' => 'reports',   'label' => 'Reports',              'path' => 'admin/reports.php',        'perm' => 'reports.view',   'group' => 'Management'],
        ['key' => 'recycle',   'label' => 'Recycle Bin',          'path' => 'admin/recycle_bin.php',    'perm' => 'recycle.view',   'group' => 'Management'],
        ['key' => 'settings',  'label' => 'Settings',             'path' => 'admin/settings.php',       'perm' => 'settings.manage','group' => 'Administration'],
        ['key' => 'users',     'label' => 'Users',                'path' => 'admin/users.php',          'perm' => 'users.manage',   'group' => 'Administration'],
    ];
}

/** Only the links the signed-in role may use, each with a resolved 'href' and 'ready' flag. */
function staff_nav_visible(): array
{
    $out = [];
    foreach (staff_nav_items() as $item) {
        if (!can($item['perm'])) {
            continue;
        }
        $ready = is_file(AUTH_ROOT . '/' . $item['path']);
        $item['ready'] = $ready;
        $item['href']  = $ready
            ? url($item['path'])
            : url('admin/coming_soon.php', ['p' => $item['key']]);
        $out[] = $item;
    }
    return $out;
}

function role_badge(string $role): string
{
    return '<span class="sp-badge sp-role-' . e($role) . '">' . e(role_label($role)) . '</span>';
}

function sp_field_error(array $errors, string $field): string
{
    return isset($errors[$field])
        ? '<p class="sp-field-error" role="alert">' . e($errors[$field]) . '</p>'
        : '';
}
