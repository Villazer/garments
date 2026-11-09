<?php
/**
 * User-account management (admin only). Pages call these; they hold the rules.
 * Business-rule failures throw DomainException with a message safe to show.
 * Passwords are never written to the audit log.
 */

function users_all(string $search = '', string $status = 'all'): array
{
    $where = [];
    $args  = [];
    if ($search !== '') {
        $where[] = '(username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        array_push($args, $like, $like, $like);
    }
    if ($status === 'active')   { $where[] = 'is_active = 1'; }
    if ($status === 'inactive') { $where[] = 'is_active = 0'; }

    return db_all(
        'SELECT user_id, username, full_name, email, role, is_active, last_login_at
           FROM users' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
          ORDER BY is_active DESC, FIELD(role, \'admin\', \'manager\', \'staff\'), full_name',
        $args
    );
}

function users_find(int $id): ?array
{
    $row = db_one('SELECT user_id, username, full_name, email, role, is_active, last_login_at, created_at
                     FROM users WHERE user_id = ?', [$id]);
    return $row ?: null;
}

/**
 * Validate the create/edit form. Returns [errors, clean].
 * $edit_id = null when creating (username and password are then required).
 */
function users_validate(array $in, ?int $edit_id): array
{
    $errors = [];
    $clean = [
        'username'  => clean_str($in['username'] ?? ''),
        'full_name' => clean_str($in['full_name'] ?? ''),
        'email'     => clean_str($in['email'] ?? ''),
        'role'      => clean_str($in['role'] ?? ''),
    ];

    if ($edit_id === null) {
        if ($clean['username'] === '') {
            $errors['username'] = 'Username is required.';
        } elseif (strlen($clean['username']) < 3 || strlen($clean['username']) > 50) {
            $errors['username'] = 'Username must be 3 to 50 characters.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]+$/', $clean['username'])) {
            $errors['username'] = 'Use letters, numbers, dot, dash or underscore only.';
        } elseif (db_value('SELECT 1 FROM users WHERE username = ?', [$clean['username']])) {
            $errors['username'] = 'That username is already taken.';
        }
    }

    if ($clean['full_name'] === '') {
        $errors['full_name'] = 'Full name is required.';
    } elseif (auth_strlen($clean['full_name']) > 120) {
        $errors['full_name'] = 'Full name must be 120 characters or fewer.';
    }

    if ($clean['email'] !== '') {
        if (auth_strlen($clean['email']) > 120 || !filter_var($clean['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address or leave it blank.';
        } elseif (db_value('SELECT 1 FROM users WHERE email = ? AND user_id <> ?', [$clean['email'], (int)$edit_id])) {
            $errors['email'] = 'Another account already uses that email.';
        }
    }

    if (!in_array($clean['role'], AUTH_ROLES, true)) {
        $errors['role'] = 'Choose a role.';
    }

    if ($edit_id === null) {
        $pw = (string)($in['password'] ?? '');
        if ($err = auth_validate_new_password($pw, $clean['username'])) {
            $errors['password'] = $err;
        } elseif ($pw !== (string)($in['password_confirm'] ?? '')) {
            $errors['password_confirm'] = 'The two passwords do not match.';
        }
    }
    return [$errors, $clean];
}

/** Lock the active admins so two concurrent requests cannot both remove the last one. */
function users_lock_active_admins(): int
{
    return count(db_all("SELECT user_id FROM users WHERE role = 'admin' AND is_active = 1 FOR UPDATE"));
}

function users_create(int $actor_id, array $clean, string $password): int
{
    try {
        $id = db_transaction(function () use ($clean, $password) {
            db_run(
                'INSERT INTO users (username, password_hash, full_name, email, role, is_active)
                 VALUES (?, ?, ?, ?, ?, 1)',
                [
                    $clean['username'],
                    password_hash($password, PASSWORD_DEFAULT),
                    $clean['full_name'],
                    $clean['email'] === '' ? null : $clean['email'],   // NULL, not '', so the UNIQUE email key allows many blanks
                    $clean['role'],
                ]
            );
            return (int)db()->lastInsertId();
        });
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {   // lost a race with another admin
            throw new DomainException('That username or email is already in use.');
        }
        throw $e;
    }
    auth_log('user_created', $actor_id, 'users', (string)$id, null,
        ['username' => $clean['username'], 'role' => $clean['role']]);
    return $id;
}

function users_update(int $actor_id, int $id, array $clean): void
{
    try {
        db_transaction(function () use ($actor_id, $id, $clean) {
            $admins = users_lock_active_admins();
            $old = db_one('SELECT user_id, full_name, email, role, is_active FROM users WHERE user_id = ? FOR UPDATE', [$id]);
            if (!$old) {
                throw new DomainException('User not found.');
            }
            if ($old['role'] !== $clean['role']) {
                if ($id === $actor_id) {
                    throw new DomainException('You cannot change your own role. Ask another administrator.');
                }
                if ($old['role'] === 'admin' && (int)$old['is_active'] === 1 && $admins <= 1) {
                    throw new DomainException('This is the last active administrator, so its role cannot be changed.');
                }
            }
            $email = $clean['email'] === '' ? null : $clean['email'];
            db_run('UPDATE users SET full_name = ?, email = ?, role = ? WHERE user_id = ?',
                [$clean['full_name'], $email, $clean['role'], $id]);
            auth_log('user_updated', $actor_id, 'users', (string)$id,
                ['full_name' => $old['full_name'], 'email' => $old['email'], 'role' => $old['role']],
                ['full_name' => $clean['full_name'], 'email' => $email, 'role' => $clean['role']]);
        });
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('Another account already uses that email.');
        }
        throw $e;
    }
}

/** Deactivate or reactivate. There is no hard delete: orders and logs reference users. */
function users_set_active(int $actor_id, int $id, bool $active): void
{
    db_transaction(function () use ($actor_id, $id, $active) {
        $admins = users_lock_active_admins();
        $target = db_one('SELECT user_id, username, role, is_active FROM users WHERE user_id = ? FOR UPDATE', [$id]);
        if (!$target) {
            throw new DomainException('User not found.');
        }
        if (!$active) {
            if ($id === $actor_id) {
                throw new DomainException('You cannot deactivate your own account.');
            }
            if ($target['role'] === 'admin' && (int)$target['is_active'] === 1 && $admins <= 1) {
                throw new DomainException('This is the last active administrator and cannot be deactivated.');
            }
        }
        if ((int)$target['is_active'] === ($active ? 1 : 0)) {
            return;   // already in that state
        }
        db_run('UPDATE users SET is_active = ? WHERE user_id = ?', [$active ? 1 : 0, $id]);
        auth_log($active ? 'user_reactivated' : 'user_deactivated', $actor_id, 'users', (string)$id,
            ['is_active' => (int)$target['is_active']], ['is_active' => $active ? 1 : 0]);
    });
}

/** Admin sets a new password for someone else. Their open sessions stop working (fingerprint check). */
function users_reset_password(int $actor_id, int $id, string $new_password): void
{
    $target = users_find($id);
    if (!$target) {
        throw new DomainException('User not found.');
    }
    db_run('UPDATE users SET password_hash = ? WHERE user_id = ?', [password_hash($new_password, PASSWORD_DEFAULT), $id]);
    auth_log('password_reset', $actor_id, 'users', (string)$id);
}
