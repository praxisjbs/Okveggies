<?php
/**
 * includes/functions/helpers.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Small shared helpers used across the storefront, Pro Portal and
 * admin. Keep this file for genuinely cross-cutting glue only.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_e')) {
    /** Escape a value for safe output in HTML. Always null-guarded. */
    function okv_e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('okv_json')) {
    /** Send a JSON response and stop. Default shape: {status:'ok', ...}. */
    function okv_json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data + (isset($data['status']) ? [] : ['status' => 'ok']));
        exit;
    }
}

if (!function_exists('okv_error')) {
    /** Send a JSON error and stop. Never leak an exception message to the client. */
    function okv_error(string $message, int $code = 400, ?string $errorCode = null): void
    {
        okv_json(['status' => 'error', 'code' => $errorCode ?? 'error', 'message' => $message], $code);
    }
}

if (!function_exists('okv_redirect')) {
    function okv_redirect(string $to, int $code = 302): void
    {
        http_response_code($code);
        header('Location: ' . $to);
        exit;
    }
}

if (!function_exists('okv_is_post')) {
    function okv_is_post(): bool
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}

if (!function_exists('okv_input')) {
    /** Read a request value (POST then GET) with a default. Not escaped. */
    function okv_input(string $key, $default = null)
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }
}

if (!function_exists('okv_action')) {
    /** The action a controller dispatches on. */
    function okv_action(): string
    {
        return (string) ($_POST['action'] ?? $_GET['action'] ?? '');
    }
}

if (!function_exists('okv_slug')) {
    function okv_slug(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim((string) $text, '-');
    }
}

if (!function_exists('okv_money')) {
    /** Convenience wrapper so templates read naturally: okv_money(800000) -> "₦8,000". */
    function okv_money(int $subunit, ?bool $withKobo = null): string
    {
        return Money::format($subunit, $withKobo);
    }
}
