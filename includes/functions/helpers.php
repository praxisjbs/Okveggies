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

if (!function_exists('okv_role_label')) {
    /** A staff role name shown to people: "owner" becomes "Owner". */
    function okv_role_label(string $roleName): string
    {
        return $roleName === '' ? 'No role' : ucfirst($roleName);
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

if (!function_exists('okv_image_url')) {
    /** Encode each segment while retaining URL path separators. */
    function okv_image_url(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}

if (!function_exists('okv_quantity')) {
    /** Display a stored decimal quantity without trailing zeroes. */
    function okv_quantity($quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.');
    }
}

if (!function_exists('okv_availability')) {
    /** Customer-facing availability text and presentation key. */
    function okv_availability(string $status, ?string $restockDate = null): array
    {
        if ($status === 'restocking') {
            $label = 'Restocking';
            if ($restockDate) {
                $timestamp = strtotime($restockDate);
                if ($timestamp !== false) {
                    $label .= ' for ' . date('D j M', $timestamp);
                }
            }
            return ['key' => 'restocking', 'label' => $label, 'can_add' => false];
        }
        if ($status === 'out_of_stock') {
            return ['key' => 'out', 'label' => 'Out of stock', 'can_add' => false];
        }
        return ['key' => 'available', 'label' => 'Available', 'can_add' => true];
    }
}

if (!function_exists('okv_send_account_code')) {
    /**
     * Issue a one-time code and email it from a notification template, in one
     * place so registration, the resend button and password reset stay in step.
     * Returns true only when the message was handed to SMTP. A missing template
     * or a mail failure returns false, so the caller can show the "we could not
     * send the code" state instead of a silent success.
     *
     * $urlVar is the template token for the link, for example activate_url or
     * reset_url; $urlPath is the path it points at under APP_URL.
     */
    function okv_send_account_code(
        string $email,
        string $name,
        string $purpose,
        string $templateKey,
        string $urlVar,
        string $urlPath,
        ?int $userId = null
    ): bool {
        $code = Otp::issue($email, 'email', $purpose, $userId);
        $vars = [
            'customer_name' => $name !== '' ? $name : 'there',
            'code'          => $code,
            'minutes'       => (string) (int) round(Otp::TTL_SECONDS / 60),
            $urlVar         => rtrim((string) APP_URL, '/') . $urlPath,
        ];
        $tpl = Mail::renderTemplate($templateKey, $vars);
        if ($tpl === null) {
            error_log('okv_send_account_code: template missing: ' . $templateKey);
            return false;
        }
        [$subject, $body] = $tpl;
        return Mail::send($email, $subject, $body);
    }
}
