<?php
/**
 * Create a staff account from the command line (first administrator on a fresh install).
 *   php tools/create_admin.php <username> "<Full name>" [role=admin] [password]
 * Without a password you are asked for one (it is shown as you type, so do it in private).
 * The password rules of the site apply.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../admin/_init.php';

$username = $argv[1] ?? '';
$full     = $argv[2] ?? '';
$role     = $argv[3] ?? 'admin';
$password = $argv[4] ?? '';
if ($username === '' || $full === '' || !in_array($role, AUTH_ROLES, true)) {
    fwrite(STDERR, "Usage: php tools/create_admin.php <username> \"<Full name>\" [staff|manager|admin] [password]\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
    fwrite(STDERR, "Username: 3 to 50 letters, numbers, dot, dash or underscore.\n");
    exit(1);
}
if (db_value('SELECT 1 FROM users WHERE username = ?', [$username])) {
    fwrite(STDERR, "That username already exists. Use tools/set_password.php to change its password.\n");
    exit(1);
}
if ($password === '') {
    fwrite(STDOUT, "Password for $username: ");
    $password = rtrim((string)fgets(STDIN), "\r\n");
}
if ($error = auth_validate_new_password($password, $username)) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}
db_run('INSERT INTO users (username, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 1)',
    [$username, password_hash($password, PASSWORD_DEFAULT), $full, $role]);
auth_log('user_created_cli', null, 'users', (string)db()->lastInsertId(), null, ['username' => $username, 'role' => $role]);
fwrite(STDOUT, "Created $role account \"$username\". Sign in at admin/login.php and change the password if it was shared.\n");
