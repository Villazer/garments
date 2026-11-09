<?php
/**
 * Server-side validators. Each returns null when the value is fine, or an
 * error message (written for the person filling in the form) when it is not.
 * Optional fields: pass $required = false and an empty value passes.
 */

declare(strict_types=1);

/** Trimmed string from any scalar; arrays and null become ''. */
function clean_str($value): string
{
    return is_scalar($value) ? trim((string)$value) : '';
}

function validate_required($value, string $label): ?string
{
    return clean_str($value) === '' ? "$label is required." : null;
}

function validate_length($value, string $label, int $min, int $max): ?string
{
    $len = mb_strlen(clean_str($value));
    if ($len === 0) {
        return null; // use validate_required() to forbid empty
    }
    if ($len < $min) {
        return "$label must be at least $min characters.";
    }
    return $len > $max ? "$label must be at most $max characters." : null;
}

function validate_email($value, string $label = 'Email address', bool $required = false): ?string
{
    $v = clean_str($value);
    if ($v === '') {
        return $required ? "$label is required." : null;
    }
    if (mb_strlen($v) > 120 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
        return "Enter a valid $label (for example name@example.com).";
    }
    return null;
}

/**
 * Normalize a Philippine mobile number to 09XXXXXXXXX, or null if invalid.
 * Accepts 09171234567, +63 917 123 4567, 639171234567, 0917-123-4567.
 * Store the normalized form so tracking lookups match.
 */
function normalize_ph_mobile($value): ?string
{
    $v = preg_replace('/[\s\-().]/', '', clean_str($value));
    if (!preg_match('/^(?:\+?63|0)(9[0-9]{9})$/', (string)$v, $m)) {
        return null;
    }
    return '0' . $m[1];
}

function validate_ph_mobile($value, string $label = 'Mobile number', bool $required = true): ?string
{
    if (clean_str($value) === '') {
        return $required ? "$label is required." : null;
    }
    return normalize_ph_mobile($value) === null
        ? "Enter a valid Philippine mobile number, like 0917 123 4567."
        : null;
}

/**
 * Student ID / LRN. The format is a regular expression kept in the
 * settings table ('student_no_pattern'), falling back to config.php, so the
 * shop can change it without code changes.
 */
function validate_student_no($value, string $label = 'Student number', bool $required = true): ?string
{
    $v = clean_str($value);
    if ($v === '') {
        return $required ? "$label is required." : null;
    }
    $pattern = (string)get_setting('student_no_pattern', config('student_no_pattern', ''));
    if ($pattern === '' || @preg_match($pattern, '') === false) {
        $pattern = (string)config('student_no_pattern', '/^[A-Za-z0-9-]{4,30}$/'); // bad setting: use config
    }
    if (mb_strlen($v) > 30 || !preg_match($pattern, $v)) {
        return "Enter a valid $label (a student ID like 2023-00123 or a 12-digit LRN).";
    }
    return null;
}

function validate_int_range($value, string $label, int $min, int $max): ?string
{
    $v = clean_str($value);
    if ($v === '' || filter_var($v, FILTER_VALIDATE_INT) === false) {
        return "$label must be a whole number.";
    }
    $n = (int)$v;
    return ($n < $min || $n > $max) ? "$label must be between $min and $max." : null;
}

/**
 * Run several rules over a form. Returns [field => first error message].
 *
 * $rules = [
 *   'full_name'  => ['Full name',     ['required', ['length', 2, 120]]],
 *   'contact_no' => ['Mobile number', ['required', 'ph_mobile']],
 *   'email'      => ['Email',         ['email']],
 *   'qty'        => ['Quantity',      [['int_range', 1, 5]]],
 * ];
 * $errors = validate_run($_POST, $rules);   // [] means everything passed
 *
 * Rules: required, email, ph_mobile, student_no, [length,min,max], [int_range,min,max].
 * Without 'required', empty values pass (except int_range).
 */
function validate_run(array $input, array $rules): array
{
    $errors = [];
    foreach ($rules as $field => [$label, $checks]) {
        $value = $input[$field] ?? '';
        $required = in_array('required', $checks, true);
        foreach ($checks as $check) {
            $name = is_array($check) ? $check[0] : $check;
            $error = null;
            switch ($name) {
                case 'required':   $error = validate_required($value, $label); break;
                case 'length':     $error = validate_length($value, $label, (int)$check[1], (int)$check[2]); break;
                case 'email':      $error = validate_email($value, $label, $required); break;
                case 'ph_mobile':  $error = validate_ph_mobile($value, $label, $required); break;
                case 'student_no': $error = validate_student_no($value, $label, $required); break;
                case 'int_range':
                    if ($required || clean_str($value) !== '') {
                        $error = validate_int_range($value, $label, (int)$check[1], (int)$check[2]);
                    }
                    break;
            }
            if ($error !== null) {
                $errors[$field] = $error;
                break; // one message per field
            }
        }
    }
    return $errors;
}

/** Error text under a form field, or ''. Returns HTML. */
function field_error(array $errors, string $field): string
{
    return isset($errors[$field])
        ? '<p class="form-error" id="err-' . e($field) . '">' . e($errors[$field]) . '</p>'
        : '';
}
