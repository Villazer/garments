<?php
/**
 * Session 5 shared helpers (tracking, toga request, waitlist, restock).
 * Built only on the documented Session 1-4 helpers. All functions here are
 * prefixed s5_ so they cannot clash with res_, catalog_ or auth functions.
 */

if (!function_exists('s5_session')) {

/** Make sure a session exists (the bootstrap normally starts it). */
function s5_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/** Execute a write statement and return the affected row count. */
function s5_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** Insert and return the new auto-increment id. */
function s5_insert(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return (int) db()->lastInsertId();
}

/** True when a PDOException is a duplicate-key error (MySQL 1062). */
function s5_is_duplicate(PDOException $ex): bool
{
    return isset($ex->errorInfo[1]) && (int) $ex->errorInfo[1] === 1062;
}

/**
 * Run a callable in a transaction and retry it on a deadlock (SQLSTATE 40001).
 * Why: two guests can hit the same stock row at once; InnoDB may pick one as
 * the deadlock victim, and a quick retry is the documented remedy.
 */
function s5_transaction(callable $fn, int $tries = 3)
{
    for ($attempt = 1; ; $attempt++) {
        try {
            return db_transaction($fn);
        } catch (PDOException $ex) {
            $deadlock = ((string) $ex->getCode() === '40001');
            if (!$deadlock || $attempt >= $tries) {
                throw $ex;
            }
            usleep(120000 * $attempt);
        }
    }
}

/* ------------------------------------------------------------------ */
/* Rate limiting (uses the existing audit_logs table, no schema change) */
/* ------------------------------------------------------------------ */

function s5_client_ip(): string
{
    // REMOTE_ADDR only: forwarded headers can be forged by the guest.
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Short, fixed-length key (fits audit_logs.entity_id VARCHAR(40)); hides raw values. */
function s5_rate_key(string $prefix, string $value): string
{
    return $prefix . ':' . substr(hash('sha256', $value), 0, 24);
}

/** How many hits of $action exist for $key in the last $minutes. */
function s5_rate_count(string $action, string $key, int $minutes): int
{
    try {
        return (int) db_value(
            "SELECT COUNT(*) FROM audit_logs
              WHERE entity_type = 'rate_limit' AND entity_id = ? AND action = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL " . (int) $minutes . " MINUTE)",
            [$key, $action]
        );
    } catch (Throwable $ex) {
        error_log('s5_rate_count: ' . $ex->getMessage());
        return 0; // never block guests because the log table failed
    }
}

function s5_rate_hit(string $action, string $key): void
{
    try {
        s5_exec(
            "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, ip_address)
             VALUES (NULL, ?, 'rate_limit', ?, ?)",
            [$action, $key, substr(s5_client_ip(), 0, 45)]
        );
    } catch (Throwable $ex) {
        error_log('s5_rate_hit: ' . $ex->getMessage());
    }
}

/** True when any [key => limit] pair has reached its limit inside the window. */
function s5_rate_blocked(string $action, array $limits, int $minutes): bool
{
    foreach ($limits as $key => $limit) {
        if (s5_rate_count($action, (string) $key, $minutes) >= $limit) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------ */
/* Reference numbers and view access                                   */
/* ------------------------------------------------------------------ */

/**
 * Normalise what a guest typed into the stored reference format.
 * Accepts the Session 4 format (GRM-2026-ABC234) and the older sample-seed
 * format (GS-7K4Q9M2X), with or without dashes or spaces, any letter case.
 */
function s5_clean_ref(string $raw): string
{
    $r = strtoupper((string) preg_replace('/[^A-Za-z0-9-]/', '', trim($raw)));
    if (preg_match('/^GRM(\d{4})([A-Z0-9]{6})$/', $r, $m)) {
        return 'GRM-' . $m[1] . '-' . $m[2];
    }
    if (preg_match('/^GS([A-Z0-9]{8})$/', $r, $m)) {
        return 'GS-' . $m[1];
    }
    return substr($r, 0, 16);
}

/** Generate a public reference with the Session 4 helper (fallback: same format). */
function s5_new_ref(): string
{
    if (function_exists('res_generate_ref')) {
        return (string) res_generate_ref();
    }
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 6; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'GRM-' . date('Y') . '-' . $out;
}

/** Order Slip No. is NOT NULL UNIQUE, so it is filled at creation: OS-YYYY-000123. */
function s5_order_no(int $order_id): string
{
    $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string) get_setting('order_no_prefix', 'OS'));
    $prefix = $prefix === '' ? 'OS' : substr($prefix, 0, 6);
    return sprintf('%s-%s-%06d', $prefix, date('Y'), $order_id);
}

/** Remember in this browser's session that the guest may view an order. */
function s5_grant_view(string $ref): void
{
    s5_session();
    $_SESSION['s5_view_ok'][$ref] = time();
    if (function_exists('res_grant_view')) {
        res_grant_view($ref); // lets slip.php and confirmation.php open without asking again
    }
}

function s5_can_view(string $ref): bool
{
    s5_session();
    if (isset($_SESSION['s5_view_ok'][$ref])) {
        return true;
    }
    return function_exists('res_can_view') && res_can_view($ref);
}

/* ------------------------------------------------------------------ */
/* Academic period                                                     */
/* ------------------------------------------------------------------ */

/** The period flagged is_current = 1 (this is where "the academic period" comes from). */
function s5_current_period(): ?array
{
    $row = db_one(
        'SELECT academic_period_id, school_year, semester, start_date, end_date
           FROM academic_periods WHERE is_current = 1 ORDER BY academic_period_id DESC LIMIT 1'
    );
    return $row ?: null;
}

function s5_period_label(array $p): string
{
    $sem = ['1st' => '1st semester', '2nd' => '2nd semester', 'summer' => 'Summer'];
    return $p['school_year'] . ', ' . ($sem[$p['semester']] ?? $p['semester']);
}

/* ------------------------------------------------------------------ */
/* Links and assets shown on public pages                              */
/* ------------------------------------------------------------------ */

/** Single list of public pages. Use it for the header/footer so nothing is missed. */
function s5_public_links(): array
{
    return [
        ['href' => 'index.php',        'label' => 'Home'],
        ['href' => 'catalog.php',      'label' => 'Catalog'],
        ['href' => 'reserve.php',      'label' => 'My reservation list'],
        ['href' => 'toga_request.php', 'label' => 'Toga rental'],
        ['href' => 'track.php',        'label' => 'Track an order'],
        ['href' => 'restock.php',      'label' => 'Restock schedule'],
        ['href' => 'waitlist.php',     'label' => 'Waitlist'],
    ];
}

/** HTML for the quick-links strip (also the footer snippet for Sessions 1-4 pages). */
function s5_quicklinks_html(): string
{
    $html = '<nav class="s5-quicklinks" aria-label="Public pages"><ul>';
    foreach (s5_public_links() as $l) {
        $html .= '<li><a href="' . e(url($l['href'])) . '">' . e($l['label']) . '</a></li>';
    }
    return $html . '</ul></nav>';
}

/** CSS (and optional JS) tags. Echo once, right after the public header. */
function s5_asset_tags(bool $with_js = false): string
{
    $out = '<link rel="stylesheet" href="' . e(asset('css/session5.css')) . '">';
    if ($with_js) {
        $out .= '<script src="' . e(asset('js/session5.js')) . '" defer></script>';
    }
    return $out;
}

/** Plain-text label for an order_type. */
function s5_order_type_label(string $type): string
{
    return ['purchase' => 'Purchase', 'reservation' => 'Reservation', 'rental' => 'Toga rental'][$type] ?? ucfirst($type);
}

/** Does the optional migration 003 column exist? Cached per request. */
function s5_waitlist_has_quantity(): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $has = (int) db_value(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'waitlist'
                    AND COLUMN_NAME = 'quantity_wanted'"
            ) > 0;
        } catch (Throwable $ex) {
            $has = false;
        }
    }
    return $has;
}

} // function_exists guard
