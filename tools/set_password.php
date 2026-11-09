<?php
/**
 * Command-line only: set a staff password (use it once to give the seeded admin a real password).
 *   C:\xampp\php\php.exe tools\set_password.php admin "NewPassword123"
 * If the password is left off, it is read from the keyboard (it will be visible).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../admin/_init.php';

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';
if ($username === '') {
    fwrite(STDERR, "Usage: php tools/set_password.php <username> [password]\n");
    exit(1);
}
if ($password === '') {
    fwrite(STDOUT, "New password for $username: ");
    $password = rtrim((string)fgets(STDIN), "\r\n");
}
if ($error = auth_validate_new_password($password, $username)) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}
$id = db_value('SELECT user_id FROM users WHERE username = ?', [$username]);
if (!$id) {
    fwrite(STDERR, "No such user: $username\n");
    exit(1);
}
db_run('UPDATE users SET password_hash = ? WHERE user_id = ?', [password_hash($password, PASSWORD_DEFAULT), (int)$id]);
auth_log('password_reset_cli', null, 'users', (string)$id);
fwrite(STDOUT, "Password updated for $username.\n");
