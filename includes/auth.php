<?php
/**
 * Authentication, roles and permissions for the staff side.
 * Customers never log in; only rows in `users` can sign in.
 *
 * Session keys (all prefixed auth_):
 *   auth_uid, auth_role   identity (the role is re-checked against the DB on every request)
 *   auth_seen, auth_start housekeeping times for idle / absolute expiry
 *   auth_fp               fingerprint of the password hash, so a password change or
 *                         reset signs out the user's other sessions
 *
 * Optional config/config.php keys (defaults shown):
 *   'auth' => [
 *       'idle_timeout_minutes'    => 30,
 *       'absolute_timeout_hours'  => 12,
 *       'max_attempts_per_user'   => 5,
 *       'max_attempts_per_ip'     => 15,
 *       'lockout_minutes'         => 10,
 *       'min_password_length'     => 8,
 *   ]
 */

const AUTH_ROLES = ['staff', 'manager', 'admin'];

// Used so an unknown username costs the same time as a wrong password.
const AUTH_DUMMY_HASH = '$2y$10$9eTYHDk3ekD7aPPMW6Gru.lgRm9XyrqO2NykStJLXsmQNCZUakEem';

/* ------------------------------------------------------------------
 * Permission map. To change who can do what, edit ONLY this function.
 * Unknown permissions are denied (fail closed).
 * ------------------------------------------------------------------ */
function auth_permissions(): array
{
    $all     = ['staff', 'manager', 'admin'];
    $manager = ['manager', 'admin'];
    $admin   = ['admin'];

    return [
        'dashboard.view'   => $all,
        'password.change'  => $all,

        // Staff: process orders, handle the counter, count stock
        'orders.view'      => $all,
        'orders.process'   => $all,      // approve, issue slip, record OR no., claim
        'walkin.create'    => $all,
        'inventory.view'   => $all,
        'inventory.count'  => $all,
        'rentals.view'     => $all,
        'rentals.process'  => $all,
        'restock.view'     => $all,
        'restock.manage'   => $all,      // restock schedule and waitlist

        // Manager and up: overrides and reports
        'orders.override'  => $manager,  // exceed the online cap, restore expired orders
        'reports.view'     => $manager,
        'recycle.view'     => $manager,
        'recycle.restore'  => $manager,

        // Admin only
        'settings.manage'  => $admin,
        'users.manage'     => $admin,
    ];
}

function role_label(string $role): string
{
    return ['staff' => 'Staff', 'manager' => 'Manager', 'admin' => 'Administrator'][$role] ?? ucfirst($role);
}

function role_description(string $role): string
{
    return [
        'staff'   => 'Process orders, handle walk-ins and toga rentals, and update inventory counts.',
        'manager' => 'Everything staff can do, plus overrides, the recycle bin and reports.',
        'admin'   => 'Everything a manager can do, plus user accounts and system settings.',
    ][$role] ?? '';
}

/** Can the signed-in user (or the given role) do this? */
function can(string $permission, ?string $role = null): bool
{
    if ($role === null) {
        $user = current_user();
        if (!$user) {
            return false;
        }
        $role = $user['role'];
    }
    $map = auth_permissions();
    return isset($map[$permission]) && in_array($role, $map[$permission], true);
}

/* ------------------------------------------------------------------
 * Session / current user
 * ------------------------------------------------------------------ */

function auth_fingerprint(string $password_hash): string
{
    return hash('sha256', $password_hash);
}

function auth_ip(): string
{
    // REMOTE_ADDR only. X-Forwarded-For is client-controlled and would let an
    // attacker dodge the lockout by changing a header.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Wipe the login and rotate the session id (flash can be set afterwards). */
function auth_clear_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/** Start an authenticated session. Call only after the password was verified. */
function auth_login(int $user_id, string $role, string $password_hash): void
{
    session_regenerate_id(true);   // defeats session fixation
    $_SESSION['auth_uid']   = $user_id;
    $_SESSION['auth_role']  = $role;
    $_SESSION['auth_fp']    = auth_fingerprint($password_hash);
    $_SESSION['auth_seen']  = time();
    $_SESSION['auth_start'] = time();
    current_user(true);            // drop any cached "nobody" from earlier in this request
}

/**
 * The signed-in user, or null. Re-reads the row on every request so that a
 * deactivated account or a changed role takes effect immediately.
 * The password hash is never returned.
 */
function current_user(bool $refresh = false): ?array
{
    static $loaded = false;
    static $user = null;

    if ($refresh) {
        $loaded = false;
        $user = null;
    }
    if ($loaded) {
        return $user;
    }
    $loaded = true;

    $uid = $_SESSION['auth_uid'] ?? null;
    if (!is_int($uid) || $uid <= 0) {
        return null;
    }

    // Idle and absolute expiry
    $now      = time();
    $idle     = max(1, (int)config('auth.idle_timeout_minutes', 30)) * 60;
    $absolute = max(1, (int)config('auth.absolute_timeout_hours', 12)) * 3600;
    $seen     = (int)($_SESSION['auth_seen'] ?? 0);
    $start    = (int)($_SESSION['auth_start'] ?? 0);
    if ($now - $seen > $idle || $now - $start > $absolute) {
        auth_clear_session();
        flash_set('warning', 'You were signed out because the session expired. Please sign in again.');
        return null;
    }

    $row = db_one(
        'SELECT user_id, username, full_name, email, role, is_active, last_login_at, password_hash
           FROM users WHERE user_id = ?',
        [$uid]
    );
    $valid = $row
        && (int)$row['is_active'] === 1
        && in_array($row['role'], AUTH_ROLES, true)
        && hash_equals(auth_fingerprint((string)$row['password_hash']), (string)($_SESSION['auth_fp'] ?? ''));
    if (!$valid) {
        auth_clear_session();
        return null;
    }

    $_SESSION['auth_seen'] = $now;
    $_SESSION['auth_role'] = $row['role'];   // keep in step with the DB

    unset($row['password_hash']);
    $row['user_id'] = (int)$row['user_id'];
    $row['is_active'] = 1;
    $user = $row;
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/** Only internal staff pages are valid "return to" targets (no open redirect). */
function auth_safe_next($next): ?string
{
    if (!is_string($next)) {
        return null;
    }
    $ok = preg_match('#^admin/(?!login\.php|logout\.php)[A-Za-z0-9_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $next);
    return $ok ? $next : null;
}

function auth_current_target(): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return '';
    }
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    $path  = 'admin/' . basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) . ($query !== '' ? '?' . $query : '');
    return auth_safe_next($path) ?? '';
}

/** Must be signed in; otherwise go to the login page. Returns the user. */
function require_login(): array
{
    $user = current_user();
    if ($user) {
        return $user;
    }
    $next = auth_current_target();
    redirect('admin/login.php', $next !== '' ? ['next' => $next] : []);
    exit;
}

/** Must be signed in AND have one of these roles; otherwise the access-denied page. */
function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        deny_access();
    }
    return $user;
}

/** Same idea, but by permission name from the map above. */
function require_can(string $permission): array
{
    $user = require_login();
    if (!can($permission, $user['role'])) {
        deny_access();
    }
    return $user;
}

function deny_access(): void
{
    http_response_code(403);
    require AUTH_ROOT . '/admin/403.php';
    exit;
}

function staff_not_found(): void
{
    http_response_code(404);
    require AUTH_ROOT . '/admin/404.php';
    exit;
}

/* ------------------------------------------------------------------
 * Audit log + brute-force throttle (uses the existing audit_logs table,
 * so no migration is needed)
 * ------------------------------------------------------------------ */

function auth_log(string $action, ?int $user_id, string $entity_type, ?string $entity_id, ?array $old = null, ?array $new = null): void
{
    db_run(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $user_id, $action, $entity_type, $entity_id,
            $old === null ? null : json_encode($old),
            $new === null ? null : json_encode($new),
            auth_ip(),
        ]
    );
}

/** Character count that works with or without the mbstring extension. */
function auth_strlen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

/** audit_logs.entity_id is VARCHAR(40), so the username is lower-cased and cut to fit. */
function auth_username_key(string $username): string
{
    return substr(strtolower($username), 0, 40);   // byte-based on purpose: no mbstring dependency
}

/**
 * Seconds the caller must still wait, or 0 if not locked.
 * Locked when failures inside the window reach the per-key limit OR the per-IP limit.
 * The wait ends when the oldest counted failure leaves the window.
 */
function auth_lock_seconds(string $action, string $key): int
{
    $window = max(1, (int)config('auth.lockout_minutes', 10));
    $limits = [
        ['entity_id', $key,       max(1, (int)config('auth.max_attempts_per_user', 5))],
        ['ip_address', auth_ip(), max(1, (int)config('auth.max_attempts_per_ip', 15))],
    ];

    $wait = 0;
    foreach ($limits as [$column, $value, $max]) {
        $offset = $max - 1;   // inlined as an int: bound LIMIT/OFFSET values break under emulated prepares
        $secs = db_value(
            "SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(created_at, INTERVAL $window MINUTE))
               FROM audit_logs
              WHERE action = ? AND entity_type = 'auth' AND $column = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL $window MINUTE)
              ORDER BY created_at DESC, audit_id DESC
              LIMIT 1 OFFSET $offset",
            [$action, $value]
        );
        if ($secs !== null && $secs !== false) {
            $wait = max($wait, (int)$secs);
        }
    }
    return $wait;
}

function auth_lock_message(int $seconds): string
{
    $minutes = max(1, (int)ceil($seconds / 60));
    return 'Too many failed attempts. For security, please wait about '
        . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' and try again.';
}

/**
 * Check a username and password.
 * Returns ['ok' => bool, 'message' => string]. The message never says which part was wrong.
 */
function auth_attempt_login(string $username, string $password): array
{
    $generic = 'Incorrect username or password, or the account is disabled.';
    $key = auth_username_key($username);

    $wait = auth_lock_seconds('login_failed', $key);
    if ($wait > 0) {
        return ['ok' => false, 'message' => auth_lock_message($wait)];
    }

    $user = null;
    if ($username !== '' && strlen($username) <= 50) {
        $user = db_one('SELECT user_id, password_hash, role, is_active FROM users WHERE username = ?', [$username]);
    }

    // Always run password_verify so response time does not reveal valid usernames.
    $verified = strlen($password) <= 1000
        && password_verify($password, $user ? (string)$user['password_hash'] : AUTH_DUMMY_HASH);

    $reason = null;
    if (!$user)                                         { $reason = 'unknown_user'; }
    elseif (!$verified)                                 { $reason = 'bad_password'; }
    elseif ((int)$user['is_active'] !== 1)              { $reason = 'inactive'; }
    elseif (!in_array($user['role'], AUTH_ROLES, true)) { $reason = 'bad_role'; }

    if ($reason !== null) {
        auth_log('login_failed', $user ? (int)$user['user_id'] : null, 'auth', $key, null, ['reason' => $reason]);
        $wait = auth_lock_seconds('login_failed', $key);
        return ['ok' => false, 'message' => $wait > 0 ? auth_lock_message($wait) : $generic];
    }

    $hash = (string)$user['password_hash'];
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        db_run('UPDATE users SET password_hash = ? WHERE user_id = ?', [$hash, (int)$user['user_id']]);
    }

    db_run('UPDATE users SET last_login_at = NOW() WHERE user_id = ?', [(int)$user['user_id']]);
    auth_login((int)$user['user_id'], $user['role'], $hash);
    auth_log('login_success', (int)$user['user_id'], 'auth', $key);
    return ['ok' => true, 'message' => ''];
}

/* ------------------------------------------------------------------
 * Passwords
 * ------------------------------------------------------------------ */

/** Returns an error message, or null when the password is acceptable. */
function auth_validate_new_password(string $password, string $username = ''): ?string
{
    $min = max(6, (int)config('auth.min_password_length', 8));
    if (strlen($password) < $min) {
        return 'Password must be at least ' . $min . ' characters.';
    }
    if (strlen($password) > 72) {
        // bcrypt silently ignores everything after 72 bytes, so refuse instead of truncating.
        return 'Password must be 72 characters or fewer.';
    }
    if (trim($password) === '') {
        return 'Password cannot be only spaces.';
    }
    if ($username !== '' && strtolower($password) === strtolower($username)) {
        return 'Password cannot be the same as the username.';
    }
    return null;
}

/**
 * Change the signed-in user's own password. Returns an error message or null.
 * Failed "current password" checks are throttled like failed logins.
 */
function auth_change_own_password(array $user, string $current, string $new, string $confirm): ?string
{
    $key  = 'u' . $user['user_id'];
    $wait = auth_lock_seconds('password_check_failed', $key);
    if ($wait > 0) {
        return auth_lock_message($wait);
    }

    $hash = (string)db_value('SELECT password_hash FROM users WHERE user_id = ?', [$user['user_id']]);
    if (!password_verify($current, $hash)) {
        auth_log('password_check_failed', $user['user_id'], 'auth', $key);
        return 'Your current password is incorrect.';
    }
    if ($new !== $confirm) {
        return 'The new password and its confirmation do not match.';
    }
    if ($new === $current) {
        return 'Choose a new password that is different from the current one.';
    }
    if ($error = auth_validate_new_password($new, (string)$user['username'])) {
        return $error;
    }

    $new_hash = password_hash($new, PASSWORD_DEFAULT);
    db_run('UPDATE users SET password_hash = ? WHERE user_id = ?', [$new_hash, $user['user_id']]);
    auth_log('password_changed', $user['user_id'], 'users', (string)$user['user_id']);

    session_regenerate_id(true);
    $_SESSION['auth_fp'] = auth_fingerprint($new_hash);   // keep THIS session; others are signed out
    return null;
}
