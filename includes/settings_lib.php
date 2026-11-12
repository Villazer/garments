<?php
/**
 * Session 9: admin settings (prefix cfg_).
 * Validates every field on the server, saves only what changed, and writes one
 * audit_logs row per changed setting (action setting_changed, entity settings).
 */

if (defined('CFG_LIB_LOADED')) {
    return;
}
define('CFG_LIB_LOADED', true);

require_once __DIR__ . '/shop_settings.php';
require_once __DIR__ . '/orders.php';   // ord_format_slip_no(), ORD_DEFAULT_SLIP_FORMAT

function cfg_bool_keys(): array
{
    return ['reservations_open', 'auto_expiry_enabled', 'announcement_enabled'];
}

/** key => [value_type, description] used when a settings row has to be created. */
function cfg_meta(): array
{
    return [
        'reservations_open'            => ['bool',   '1 = accept online reservations and toga requests, 0 = closed'],
        'hold_unpaid_hours'            => ['int',    'Hours an approved, unpaid reservation is held before it expires'],
        'default_max_qty_per_request'  => ['int',    'Default online quantity cap suggested for new items'],
        'max_qty_per_request_limit'    => ['int',    'Highest online quantity cap any item may use'],
        'low_stock_default'            => ['int',    'Low-stock threshold used when a size has none'],
        'toga_rental_days'             => ['int',    'Default toga rental length in days (0 = until the end of the academic period)'],
        'recycle_purge_days'           => ['int',    'Days an expired order stays in the recycle bin before it is purged'],
        'expiring_soon_hours'          => ['int',    'Hours before a hold ends that an order is flagged as expiring soon'],
        'auto_expiry_enabled'          => ['bool',   'Run the expiry job when staff pages load (throttled)'],
        'auto_expiry_interval_minutes' => ['int',    'Minimum minutes between two lazy expiry runs'],
        'shop_name'                    => ['string', 'Shop name shown in the public footer'],
        'shop_address'                 => ['string', 'Shop address shown in the public footer'],
        'shop_hours'                   => ['string', 'Opening hours shown in the public footer'],
        'shop_phone'                   => ['string', 'Shop phone shown in the public footer'],
        'shop_email'                   => ['string', 'Shop email shown in the public footer'],
        'announcement_enabled'         => ['bool',   'Show the announcement banner on the home page'],
        'announcement_text'            => ['string', 'Announcement banner text'],
        'announcement_style'           => ['string', 'Announcement banner style: info, warning or success'],
        'order_slip_format'            => ['string', 'Order Slip No. format'],
        'order_no_prefix'              => ['string', 'Prefix of the Order Slip No.'],
    ];
}

function cfg_defaults(): array
{
    return [
        'reservations_open'            => '1',
        'hold_unpaid_hours'            => '168',
        'default_max_qty_per_request'  => '3',
        'max_qty_per_request_limit'    => '50',
        'low_stock_default'            => '5',
        'toga_rental_days'             => '0',
        'recycle_purge_days'           => '30',
        'expiring_soon_hours'          => '24',
        'auto_expiry_enabled'          => '1',
        'auto_expiry_interval_minutes' => '5',
        'shop_name'                    => (string) config('shop.name', 'WMSU Garment Shop'),
        'shop_address'                 => (string) config('shop.address', ''),
        'shop_hours'                   => (string) config('shop.hours', ''),
        'shop_phone'                   => (string) config('shop.phone', ''),
        'shop_email'                   => (string) config('shop.email', ''),
        'announcement_enabled'         => '0',
        'announcement_text'            => '',
        'announcement_style'           => 'info',
        'order_slip_format'            => ORD_DEFAULT_SLIP_FORMAT,
        'order_no_prefix'              => 'OS',
    ];
}

/** Current stored values as strings (fresh from the database, not the per-request cache). */
function cfg_current(): array
{
    $raw = [];
    foreach (db_all('SELECT setting_key, setting_value FROM settings') as $r) {
        $raw[$r['setting_key']] = (string) $r['setting_value'];
    }
    $out = [];
    foreach (cfg_defaults() as $key => $default) {
        $v = array_key_exists($key, $raw) ? $raw[$key] : $default;
        if (in_array($key, cfg_bool_keys(), true)) {
            $v = shop_truthy($v) ? '1' : '0';
        } elseif (strpos($key, 'shop_') === 0 && trim($v) === '') {
            $v = $default;   // blank means "use config/config.php", so show that value
        }
        $out[$key] = $v;
    }
    return $out;
}

/** Values for the form: the stored hold is in hours, the form shows days. */
function cfg_form_values(): array
{
    $v = cfg_current();
    $days = number_format(((int) $v['hold_unpaid_hours']) / 24, 2, '.', '');
    $v['hold_days'] = rtrim(rtrim($days, '0'), '.');
    return $v;
}

/* ------------------------------------------------------------ validation */

function cfg_clean($v): string
{
    return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $v));
}

/** Single line: control characters removed and runs of whitespace collapsed. */
function cfg_line($v): string
{
    return trim((string) preg_replace('/\s+/u', ' ', cfg_clean($v)));
}

function cfg_int($raw, int $min, int $max): ?int
{
    $s = trim((string) $raw);
    if (!preg_match('/^\d{1,6}$/', $s)) {
        return null;
    }
    $n = (int) $s;
    return ($n >= $min && $n <= $max) ? $n : null;
}

function cfg_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
}

/** Returns an error message or null. */
function cfg_check_slip_format(string $format, string $prefix): ?string
{
    if ($format === '' || strlen($format) > 60) {
        return 'Enter a slip number format of up to 60 characters.';
    }
    if (!preg_match('/\{seq(?::\d{1,2})?\}/', $format)) {
        return 'The format must contain {seq} or {seq:4} (the running number).';
    }
    $rest = preg_replace('/\{(?:prefix|year|yy)\}|\{seq(?::\d{1,2})?\}/', '', $format);
    if (!preg_match('~^[A-Za-z0-9\-/._ ]*$~', (string) $rest)) {
        return 'Use only {prefix}, {year}, {yy}, {seq:N}, letters, numbers and - / . _ in the format.';
    }
    // Longest realistic number must still fit orders.order_no (VARCHAR(20)).
    $worst = ord_format_slip_no($format, $prefix, '2099', 999999);
    if (strlen($worst) > 20) {
        return 'This format can produce numbers longer than 20 characters (for example ' . $worst . '). Shorten it.';
    }
    return null;
}

/** @return array [clean values keyed by setting key, errors keyed by form field] */
function cfg_validate(array $post): array
{
    $e = [];
    $c = [];

    $c['reservations_open'] = !empty($post['reservations_open']) ? '1' : '0';
    $c['auto_expiry_enabled'] = !empty($post['auto_expiry_enabled']) ? '1' : '0';
    $c['announcement_enabled'] = !empty($post['announcement_enabled']) ? '1' : '0';

    // Hold time: entered in days, stored in hours (the unit the schema and jobs use).
    $days = str_replace(',', '.', trim((string) ($post['hold_days'] ?? '')));
    if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $days) || (float) $days < 0.5 || (float) $days > 90) {
        $e['hold_days'] = 'Enter a hold time from 0.5 to 90 days, for example 7.';
    } else {
        $c['hold_unpaid_hours'] = (string) max(1, (int) round((float) $days * 24));
    }

    $limit = cfg_int($post['max_qty_per_request_limit'] ?? '', 1, 50);
    if ($limit === null) {
        $e['max_qty_per_request_limit'] = 'Enter a whole number from 1 to 50.';
    } else {
        $c['max_qty_per_request_limit'] = (string) $limit;
    }
    $def = cfg_int($post['default_max_qty_per_request'] ?? '', 1, 50);
    if ($def === null) {
        $e['default_max_qty_per_request'] = 'Enter a whole number from 1 to 50.';
    } elseif ($limit !== null && $def > $limit) {
        $e['default_max_qty_per_request'] = 'The default cannot be higher than the maximum (' . $limit . ').';
    } else {
        $c['default_max_qty_per_request'] = (string) $def;
    }

    $ints = [
        'low_stock_default'            => [0, 1000],
        'toga_rental_days'             => [0, 730],
        'recycle_purge_days'           => [1, 365],
        'expiring_soon_hours'          => [1, 720],
        'auto_expiry_interval_minutes' => [1, 60],
    ];
    foreach ($ints as $key => [$min, $max]) {
        $n = cfg_int($post[$key] ?? '', $min, $max);
        if ($n === null) {
            $e[$key] = "Enter a whole number from $min to $max.";
        } else {
            $c[$key] = (string) $n;
        }
    }

    $name = cfg_line($post['shop_name'] ?? '');
    if (cfg_len($name) < 2 || cfg_len($name) > 100) {
        $e['shop_name'] = 'Enter the shop name (2 to 100 characters).';
    }
    $c['shop_name'] = $name;
    foreach (['shop_address' => 150, 'shop_hours' => 100, 'shop_phone' => 40] as $key => $max) {
        $v = cfg_line($post[$key] ?? '');
        if (cfg_len($v) > $max) {
            $e[$key] = "Use at most $max characters.";
        }
        $c[$key] = $v;
    }
    $email = cfg_line($post['shop_email'] ?? '');
    if ($email !== '' && (cfg_len($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $e['shop_email'] = 'Enter a valid email address or leave it blank.';
    }
    $c['shop_email'] = $email;

    $text = cfg_line($post['announcement_text'] ?? '');
    if (cfg_len($text) > 255) {
        $e['announcement_text'] = 'The announcement can be at most 255 characters.';
    } elseif ($c['announcement_enabled'] === '1' && $text === '') {
        $e['announcement_text'] = 'Enter the announcement text, or switch the banner off.';
    }
    $c['announcement_text'] = $text;
    $style = (string) ($post['announcement_style'] ?? 'info');
    if (!in_array($style, ['info', 'warning', 'success'], true)) {
        $e['announcement_style'] = 'Choose info, warning or success.';
    }
    $c['announcement_style'] = $style;

    $prefix = strtoupper(cfg_line($post['order_no_prefix'] ?? ''));
    if (!preg_match('/^[A-Z0-9]{1,6}$/', $prefix)) {
        $e['order_no_prefix'] = 'Use 1 to 6 letters or numbers.';
        $prefix = 'OS';
    }
    $c['order_no_prefix'] = $prefix;
    $format = cfg_clean($post['order_slip_format'] ?? '');
    if (($m = cfg_check_slip_format($format, $prefix)) !== null) {
        $e['order_slip_format'] = $m;
    }
    $c['order_slip_format'] = $format;

    return [$c, $e];
}

/* ------------------------------------------------------------------ save */

function cfg_audit(?int $user_id, string $action, string $type, string $id, ?array $old, ?array $new): void
{
    db_run(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $user_id, $action, $type, substr($id, 0, 40),
            $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null,
        ]
    );
}

/** Save the validated values. Only changed keys are written, each with its own audit row. Returns the changed keys. */
function cfg_save(array $clean, int $user_id): array
{
    $current = cfg_current();
    $meta = cfg_meta();
    $changed = [];
    db_transaction(function () use ($clean, $current, $meta, $user_id, &$changed) {
        foreach ($clean as $key => $value) {
            if (!isset($meta[$key])) {
                continue;
            }
            $old = (string) ($current[$key] ?? '');
            if ($old === (string) $value) {
                continue;
            }
            // A blank shop field means "use config/config.php"; the form shows that fallback,
            // so an untouched blank field must not count as a change every time.
            if (strpos($key, 'shop_') === 0 && (string) $value === '' && $old === (string) cfg_defaults()[$key]) {
                continue;
            }
            db_run(
                'INSERT INTO settings (setting_key, setting_value, value_type, description, updated_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
                [$key, (string) $value, $meta[$key][0], $meta[$key][1], $user_id]
            );
            cfg_audit($user_id, 'setting_changed', 'settings', $key, ['value' => $old], ['value' => (string) $value]);
            $changed[] = $key;
        }
    });
    if ($changed) {
        settings_all(true);   // refresh the per-request cache
    }
    return $changed;
}

/* ------------------------------------------------------ academic periods */

function cfg_periods(): array
{
    return db_all(
        'SELECT academic_period_id, school_year, semester, start_date, end_date, is_current
           FROM academic_periods ORDER BY start_date DESC, academic_period_id DESC'
    );
}

function cfg_period_label(array $p): string
{
    $sem = ['1st' => '1st semester', '2nd' => '2nd semester', 'summer' => 'Summer'];
    return $p['school_year'] . ', ' . ($sem[$p['semester']] ?? $p['semester']);
}

/** Make one period the current one (the others are cleared). Throws DomainException. */
function cfg_period_make_current(int $id, int $user_id): bool
{
    return (bool) db_transaction(function () use ($id, $user_id) {
        $p = db_one('SELECT * FROM academic_periods WHERE academic_period_id = ? FOR UPDATE', [$id]);
        if (!$p) {
            throw new DomainException('That academic period does not exist.');
        }
        $old = db_value('SELECT academic_period_id FROM academic_periods WHERE is_current = 1 ORDER BY academic_period_id LIMIT 1');
        if ((int) $p['is_current'] === 1 && (int) $old === $id) {
            return false;
        }
        db_run('UPDATE academic_periods SET is_current = 0 WHERE is_current = 1');
        db_run('UPDATE academic_periods SET is_current = 1 WHERE academic_period_id = ?', [$id]);
        cfg_audit($user_id, 'academic_period_changed', 'academic_periods', (string) $id,
            ['current_period_id' => $old === null ? null : (int) $old],
            ['current_period_id' => $id, 'label' => cfg_period_label($p)]);
        return true;
    });
}

function cfg_parse_date($v): ?string
{
    $s = trim((string) $v);
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}

/** @return array [clean, errors] */
function cfg_period_validate(array $post): array
{
    $e = [];
    $year = trim((string) ($post['school_year'] ?? ''));
    if (!preg_match('/^(\d{4})-(\d{4})$/', $year, $m) || (int) $m[2] !== (int) $m[1] + 1) {
        $e['school_year'] = 'Enter the school year as 2026-2027 (the second year follows the first).';
    }
    $sem = (string) ($post['semester'] ?? '');
    if (!in_array($sem, ['1st', '2nd', 'summer'], true)) {
        $e['semester'] = 'Choose a semester.';
    }
    $start = cfg_parse_date($post['start_date'] ?? '');
    $end = cfg_parse_date($post['end_date'] ?? '');
    if ($start === null) {
        $e['start_date'] = 'Enter a valid start date.';
    }
    if ($end === null) {
        $e['end_date'] = 'Enter a valid end date.';
    } elseif ($start !== null && $end < $start) {
        $e['end_date'] = 'The end date cannot be before the start date.';
    }
    if (!$e && db_value('SELECT COUNT(*) FROM academic_periods WHERE school_year = ? AND semester = ?', [$year, $sem]) > 0) {
        $e['school_year'] = 'That school year and semester already exist.';
    }
    return [['school_year' => $year, 'semester' => $sem, 'start_date' => $start, 'end_date' => $end,
             'make_current' => !empty($post['make_current'])], $e];
}

function cfg_period_add(array $c, int $user_id): int
{
    try {
        $id = (int) db_transaction(function () use ($c, $user_id) {
            db_run('INSERT INTO academic_periods (school_year, semester, start_date, end_date, is_current) VALUES (?, ?, ?, ?, 0)',
                [$c['school_year'], $c['semester'], $c['start_date'], $c['end_date']]);
            $id = (int) db()->lastInsertId();
            cfg_audit($user_id, 'academic_period_added', 'academic_periods', (string) $id, null,
                ['school_year' => $c['school_year'], 'semester' => $c['semester'], 'start_date' => $c['start_date'], 'end_date' => $c['end_date']]);
            return $id;
        });
    } catch (PDOException $ex) {
        if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('That school year and semester already exist.');
        }
        throw $ex;
    }
    if (!empty($c['make_current'])) {
        cfg_period_make_current($id, $user_id);
    }
    return $id;
}
