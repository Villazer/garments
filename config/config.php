<?php
/**
 * Local configuration (XAMPP defaults).
 * Copy of config.sample.php. Keep this file out of version control and
 * never commit real passwords. All paths are relative; nothing here is
 * tied to C:\xampp so the site can move to another host unchanged.
 */
return [
    // --- Application --------------------------------------------------
    'app_name'    => 'WMSU Garment Shop',
    'app_tagline' => 'Online Uniform and Toga Ordering',

    // URL path where the site lives, no trailing slash.
    // XAMPP: '/garments'. If the site sits at the web root, use ''.
    'base_url'    => '/garments',

    'timezone'    => 'Asia/Manila',

    // true = show errors and allow setup_check.php / styleguide.php.
    // MUST be false on a live server.
    'debug'       => true,

    'session_name' => 'garments_sid',

    // --- Database (XAMPP defaults) ------------------------------------
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'wmsu_garment_shop',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // --- Validation defaults ------------------------------------------
    // Used when the settings table has no 'student_no_pattern' row.
    // Default accepts a student ID like 2023-00123 or a 12-digit LRN.
    'student_no_pattern' => '/^([0-9]{4}-[0-9]{5}|[0-9]{12})$/',

    'items_per_page' => 12,

    // --- Shop information (public footer). SAMPLE values: confirm with the shop.
    'shop' => [
        'name'     => 'WMSU Garment Shop',
        'office'   => 'Resource Generation Office',
        'school'   => 'Western Mindanao State University',
        'address'  => 'Normal Road, Baliwasan, Zamboanga City',
        'hours'    => 'Monday to Friday, 8:00 AM to 5:00 PM',
        'phone'    => '(062) 000-0000',
        'email'    => 'garments@example.com',
    ],
];
