<?php
/**
 * Database access (PDO, MySQL/MariaDB). The connection opens on first use,
 * so pages that never touch the database do not pay for it.
 * Prepared statements only: never put user input into the SQL string.
 */

declare(strict_types=1);

/** Shared PDO connection: exceptions on, real prepared statements, utf8mb4. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = config('db', []);
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'] ?? '127.0.0.1',
        (int)($c['port'] ?? 3306),
        $c['name'] ?? '',
        $c['charset'] ?? 'utf8mb4'
    );
    try {
        $pdo = new PDO($dsn, $c['user'] ?? 'root', $c['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $ex) {
        // Do not chain $ex: its stack trace can contain the database password.
        throw new RuntimeException(
            'Could not connect to the database.' . (config('debug') ? ' ' . $ex->getMessage() : '')
        );
    }
    // Keep MySQL's NOW() in the same timezone as PHP (Asia/Manila).
    $pdo->exec('SET time_zone = ' . $pdo->quote(date('P')));
    return $pdo;
}

/** Run a prepared statement and return it. */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** All rows as an array of associative arrays. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** First row, or null when there is none. */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** First column of the first row (COUNT, SUM, ...), or null. */
function db_value(string $sql, array $params = [])
{
    $v = db_run($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/**
 * Run $callback(PDO $pdo) inside a transaction. Commits when it returns,
 * rolls back and rethrows when it throws. Returns the callback's result.
 * Use SELECT ... FOR UPDATE inside it for multi-step stock changes.
 * If a transaction is already open the callback just joins it.
 * NOTE: the schema's stored procedures (sp_approve_order, ...) manage their
 * own transaction, so CALL them OUTSIDE db_transaction().
 */
function db_transaction(callable $callback)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $callback($pdo);
    }
    $pdo->beginTransaction();
    try {
        $result = $callback($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}
