<?php
/**
 * Shared helpers. Loaded by includes/init.php on every request.
 * Conventions: snake_case names; every function that prints HTML says so
 * in its comment; functions that return HTML return already-escaped markup.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------

/**
 * Read a value from config/config.php. Dot notation reaches nested keys:
 * config('db.host'). Returns $default when the key does not exist.
 */
function config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Missing config/config.php. Copy config/config.sample.php to config/config.php and edit it.');
        }
        $cfg = require $file;
    }
    if ($key === null) {
        return $cfg;
    }
    $value = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

// ---------------------------------------------------------------------
// Output and URLs
// ---------------------------------------------------------------------

/** Escape any value for safe output inside HTML text or attributes. */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Build an app URL from a path relative to the project root.
 * url('catalog.php', ['page' => 2]) => /garments/catalog.php?page=2
 * The result is NOT escaped: wrap it in e() when printing.
 */
function url(string $path = '', array $query = []): string
{
    $base = rtrim((string)config('base_url', ''), '/');
    $url = $base . '/' . ltrim($path, '/');
    if ($query) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }
    return $url;
}

/** URL of a file in assets/, with a version suffix so browsers reload it after edits. */
function asset(string $path): string
{
    $file = __DIR__ . '/../assets/' . ltrim($path, '/');
    $version = is_file($file) ? (string)filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $version;
}

/**
 * Redirect to an app path (same format as url()) and stop.
 * External addresses are ignored on purpose, so a user-supplied value
 * can never cause an open redirect.
 */
function redirect(string $path = '', array $query = [], int $status = 302): void
{
    $target = preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $path) ? '' : $path;
    header('Location: ' . url($target, $query), true, $status);
    exit;
}

/** True when the current request is HTTPS (also behind a proxy that sets the header). */
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Stop with an HTTP error and a plain message page. */
function abort(int $status, string $message = ''): void
{
    http_response_code($status);
    $titles = [403 => 'Access denied', 404 => 'Page not found', 419 => 'Session expired', 500 => 'Something went wrong'];
    $title = $titles[$status] ?? 'Error';
    $page_title = $title;
    $active_nav = '';
    require __DIR__ . '/public_header.php';
    echo '<section class="container section"><div class="alert alert--error"><strong>' . e($title) . '.</strong> '
        . e($message !== '' ? $message : 'Please go back and try again.') . '</div>'
        . '<p><a class="btn btn--secondary" href="' . e(url('')) . '">Back to home</a></p></section>';
    require __DIR__ . '/public_footer.php';
    exit;
}

// ---------------------------------------------------------------------
// Flash messages and form memory
// ---------------------------------------------------------------------

/** Queue a message for the next page. $type: success, info, warning or error. */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Print all queued messages as alerts, then clear them. Prints HTML. */
function flash_show(): void
{
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $type = in_array($f['type'], ['success', 'info', 'warning', 'error'], true) ? $f['type'] : 'info';
        echo '<div class="alert alert--' . $type . '" role="' . ($type === 'error' ? 'alert' : 'status') . '">'
            . '<span class="alert__text">' . e($f['message']) . '</span>'
            . '<button type="button" class="alert__close" data-dismiss="alert" aria-label="Dismiss">&times;</button></div>';
    }
    unset($_SESSION['flash']);
}

/**
 * Keep submitted values for the next request (use before redirecting after
 * a failed POST). Password and token fields are never stored.
 */
function remember_input(array $input): void
{
    foreach ($input as $key => $value) {
        if (stripos((string)$key, 'password') !== false || $key === 'csrf_token') {
            unset($input[$key]);
        }
    }
    $_SESSION['old_input'] = $input;
}

/**
 * Previously submitted value for a form field, or $default.
 * Looks at remembered input first (after a redirect), then at $_POST
 * (when the same request re-renders the form). Returns RAW text:
 * always print it as  value="<?= e(old('name')) ?>".
 */
function old(string $key, string $default = ''): string
{
    static $remembered = null;
    if ($remembered === null) {
        $remembered = $_SESSION['old_input'] ?? [];
        unset($_SESSION['old_input']);
    }
    $value = $remembered[$key] ?? $_POST[$key] ?? $default;
    return is_scalar($value) ? (string)$value : $default;
}

// ---------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------

/** Philippine peso, e.g. 1250 => "₱1,250.00". Accepts numbers or numeric strings. */
function money($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

/** Date like "Nov 6, 2026"; returns an em dash for empty or invalid values. */
function format_date($value, string $format = 'M j, Y'): string
{
    if ($value === null || $value === '' || $value === '0000-00-00') {
        return '—';
    }
    try {
        return (new DateTimeImmutable((string)$value))->format($format);
    } catch (Exception $ex) {
        return '—';
    }
}

/** Date and time like "Nov 6, 2026 3:45 PM". */
function format_datetime($value): string
{
    return format_date($value, 'M j, Y g:i A');
}

// ---------------------------------------------------------------------
// Status badges
// ---------------------------------------------------------------------

/** Order statuses in workflow order => label shown to people. */
function order_statuses(): array
{
    return [
        'requested'   => 'Requested',
        'approved'    => 'Approved',
        'slip_issued' => 'Slip issued',
        'paid'        => 'Paid',
        'claimed'     => 'Claimed',
        'declined'    => 'Declined',
        'expired'     => 'Expired',
        'cancelled'   => 'Cancelled',
        'unclaimed'   => 'Unclaimed',
    ];
}

/** Coloured badge for an order status. Returns HTML. */
function status_badge(string $status): string
{
    $labels = order_statuses();
    $label = $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    $class = isset($labels[$status]) ? str_replace('_', '-', $status) : 'neutral';
    return '<span class="badge badge--' . e($class) . '">' . e($label) . '</span>';
}

/** Stock badge: out of stock, low stock, or in stock. Returns HTML. */
function stock_badge(int $available, int $low_threshold = 5): string
{
    if ($available <= 0) {
        return '<span class="badge badge--out">Out of stock</span>';
    }
    if ($available <= $low_threshold) {
        return '<span class="badge badge--low">Low stock: ' . $available . ' left</span>';
    }
    return '<span class="badge badge--in">In stock</span>';
}

// ---------------------------------------------------------------------
// Settings table
// ---------------------------------------------------------------------

/** Load every row of `settings` once per request, converted by value_type. */
function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        try {
            foreach (db()->query('SELECT setting_key, setting_value, value_type FROM settings') as $row) {
                $v = $row['setting_value'];
                switch ($row['value_type']) {
                    case 'int':     $v = (int)$v; break;
                    case 'decimal': $v = (float)$v; break;
                    case 'bool':    $v = in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true); break;
                }
                $cache[$row['setting_key']] = $v;
            }
        } catch (Throwable $ex) {
            // Database or table missing: fall back to defaults so public pages still render.
            $cache = [];
        }
    }
    return $cache;
}

/** One setting by key, or $default when missing. Call settings_all(true) after an admin edit. */
function get_setting(string $key, $default = null)
{
    $all = settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

// ---------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------

/**
 * Work out paging numbers for a list. $page defaults to ?page= in the URL.
 * Use 'offset' and 'per_page' in the SQL LIMIT clause (cast to int).
 */
function paginate(int $total, int $per_page = 0, ?int $page = null): array
{
    $per_page = $per_page > 0 ? $per_page : max(1, (int)config('items_per_page', 12));
    $total_pages = max(1, (int)ceil($total / $per_page));
    $page = $page ?? (int)($_GET['page'] ?? 1);
    $page = min(max(1, $page), $total_pages);
    $offset = ($page - 1) * $per_page;
    return [
        'page'        => $page,
        'per_page'    => $per_page,
        'total'       => $total,
        'total_pages' => $total_pages,
        'offset'      => $offset,
        'from'        => $total === 0 ? 0 : $offset + 1,
        'to'          => min($offset + $per_page, $total),
        'has_prev'    => $page > 1,
        'has_next'    => $page < $total_pages,
    ];
}

/**
 * Page links for a paginate() result. $path is an app path; $query keeps
 * the other filters in the URL. Returns HTML (empty when only one page).
 */
function pagination_links(array $p, string $path, array $query = []): string
{
    if ($p['total_pages'] <= 1) {
        return '';
    }
    $link = static function (int $n, string $text, string $class = '', string $aria = '') use ($path, $query): string {
        $href = e(url($path, array_merge($query, ['page' => $n])));
        return '<li class="' . $class . '"><a href="' . $href . '"' . $aria . '>' . $text . '</a></li>';
    };
    $html = '<nav class="pagination" aria-label="Pages"><ul class="pagination__list">';
    $html .= $p['has_prev']
        ? $link($p['page'] - 1, 'Previous', 'pagination__step')
        : '<li class="pagination__step is-disabled"><span>Previous</span></li>';

    // Show first, last, and two pages around the current one; "…" fills gaps.
    $shown = array_unique(array_filter(
        array_merge([1, $p['total_pages']], range($p['page'] - 2, $p['page'] + 2)),
        static fn($n) => $n >= 1 && $n <= $p['total_pages']
    ));
    sort($shown);
    $prev = 0;
    foreach ($shown as $n) {
        if ($n - $prev > 1) {
            $html .= '<li class="pagination__gap" aria-hidden="true">…</li>';
        }
        $html .= $n === $p['page']
            ? '<li class="is-current"><span aria-current="page">' . $n . '</span></li>'
            : $link($n, (string)$n);
        $prev = $n;
    }
    $html .= $p['has_next']
        ? $link($p['page'] + 1, 'Next', 'pagination__step')
        : '<li class="pagination__step is-disabled"><span>Next</span></li>';
    return $html . '</ul></nav>';
}

// ---------------------------------------------------------------------
// Navigation (one place to change menu items)
// ---------------------------------------------------------------------

/** Public menu: key => [label, path]. Later sessions create these pages. */
function public_nav_items(): array
{
    return [
        'home'    => ['Home', 'index.php'],
        'catalog' => ['Catalog', 'catalog.php'],
        'track'   => ['Track Order', 'track.php'],
        'restock' => ['Restock Schedule', 'restock.php'],
        // Session 8 fix: the toga page is toga_request.php (toga.php never existed).
        'toga'    => ['Toga Rental', 'toga_request.php'],
    ];
}
