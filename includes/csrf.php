<?php
/**
 * CSRF protection. Put csrf_field() in every POST form and call
 * csrf_require() at the top of every POST handler.
 */

declare(strict_types=1);

/** The session's token (created on first use). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input for forms. Returns HTML. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** True when the submitted token matches the session's token. */
function csrf_verify(?string $token = null): bool
{
    $token = $token ?? (string)($_POST['csrf_token'] ?? '');
    return $token !== '' && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/** Stop with HTTP 419 unless the request carries a valid token. */
function csrf_require(): void
{
    if (!csrf_verify()) {
        abort(419, 'Your form expired or was not valid. Go back, reload the page, and try again.');
    }
}
