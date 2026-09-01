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

if (!function_exists('okv_safe_path')) {
    /**
     * Validate a redirect target that came from the request. Only a path on
     * this site is allowed, so a crafted return_to cannot bounce a customer off
     * to another host. Anything else falls back.
     *
     * A browser normalises a backslash to a forward slash before it resolves a
     * URL, so "/\evil.example" is protocol-relative and has to be refused
     * alongside "//evil.example". Percent-encoded control characters are
     * refused for the same reason: they are only ever there to slip past a
     * check like this one. An encoded space is left alone, because a search
     * term legitimately carries one.
     */
    function okv_safe_path(string $path, string $fallback = '/'): string
    {
        if ($path === '' || $path[0] !== '/') {
            return $fallback;
        }
        // Control characters, space, DEL and the backslash, raw or encoded.
        if (preg_match('#[\x00-\x20\x7f\x5c]#', $path)) {
            return $fallback;
        }
        if (preg_match('#%(?:0[0-9a-f]|1[0-9a-f]|5c|7f)#i', $path)) {
            return $fallback;
        }
        // A second leading slash makes the value protocol-relative: another host.
        if (isset($path[1]) && $path[1] === '/') {
            return $fallback;
        }
        // A fragment has no business in a server-side redirect target.
        if (str_contains($path, '#')) {
            return $fallback;
        }
        return $path;
    }
}

if (!function_exists('okv_is_post')) {
    function okv_is_post(): bool
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }
}

if (!function_exists('okv_input')) {
    /**
     * Read a request value (POST then GET) with a default. Not escaped.
     * An array or object value (for example ?search[]=a) is refused and the
     * default is returned, so a caller casting to string never trips a warning.
     */
    function okv_input(string $key, $default = null)
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_scalar($value) || $value === null ? $value : $default;
    }
}

if (!function_exists('okv_action')) {
    /** The action a controller dispatches on. */
    function okv_action(): string
    {
        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        return is_scalar($action) ? (string) $action : '';
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
    /**
     * Customer-facing availability text and presentation key.
     *
     * Two labels, not one. "label" is the full sentence for the product page.
     * "short_label" is the badge on a product card, where a two-up mobile grid
     * has no room for a date and a long badge pushes the whole page sideways.
     * The date still reaches the customer, as a line under the card.
     */
    function okv_availability(string $status, ?string $restockDate = null): array
    {
        if ($status === 'restocking') {
            $label = 'Restocking';
            $note = '';
            if ($restockDate) {
                $timestamp = strtotime($restockDate);
                if ($timestamp !== false) {
                    $note = 'Back on ' . date('l jS F', $timestamp);
                    $label .= ', back on ' . date('l jS F', $timestamp);
                }
            }
            return ['key' => 'restocking', 'label' => $label, 'short_label' => 'Restocking', 'note' => $note, 'can_add' => false];
        }
        if ($status === 'out_of_stock') {
            return ['key' => 'out', 'label' => 'Out of stock', 'short_label' => 'Out of stock', 'note' => '', 'can_add' => false];
        }
        return ['key' => 'available', 'label' => 'Available', 'short_label' => 'Available', 'note' => '', 'can_add' => true];
    }
}

if (!function_exists('okv_sourced_line')) {
    /**
     * The sourcing trust line, in one place. Bible 6.3 fixes the pattern:
     * "Sourced Tuesday from Ogun State." The same sentence has to read the
     * same on a product card, a product page, a combo and, from M6, the Order
     * Trail and the confirmation email, so the promise is never worded two
     * ways. Both halves are admin settings: source_day and source_regions.
     *
     * A blank day falls back to "this week", which is what the storefront said
     * before the day setting existed, so a site that has not set one still
     * reads as a whole sentence. Blank regions return an empty string and the
     * caller drops the line rather than promising a farm we cannot name.
     */
    function okv_sourced_line(string $regions, string $day = ''): string
    {
        $regions = trim($regions);
        if ($regions === '') {
            return '';
        }
        $day = ucfirst(trim($day));
        return 'Sourced ' . ($day !== '' ? $day : 'this week') . ' from ' . $regions;
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

if (!function_exists('okv_limit_clause')) {
    /**
     * " LIMIT 25 OFFSET 50" for page three, or '' when the caller asked for
     * every row. Only ever clamped ints, so it is the one safe thing to put
     * into SQL by hand. Under native prepared statements a bound string cannot
     * sit inside a LIMIT clause, which is exactly why a page is never a
     * placeholder.
     */
    function okv_limit_clause(int $page, ?int $perPage): string
    {
        if ($perPage === null) {
            return '';
        }
        $perPage = max(1, $perPage);
        return ' LIMIT ' . $perPage . ' OFFSET ' . ((max(1, $page) - 1) * $perPage);
    }
}

if (!function_exists('okv_page_window')) {
    /**
     * The run of page numbers shown under a listing: the first and last pages
     * always, the current page with $radius neighbours on each side, and an
     * "…" where a run breaks. Page 1 of 10 gives [1, 2, 3, '…', 10]; page 5
     * gives [1, '…', 3, 4, 5, 6, 7, '…', 10].
     */
    function okv_page_window(int $page, int $lastPage, int $radius = 2): array
    {
        if ($lastPage < 1) {
            return [];
        }
        if ($lastPage === 1) {
            return [1];
        }
        $page = min(max(1, $page), $lastPage);
        $middle = range(max(1, $page - $radius), min($lastPage, $page + $radius));

        $window = [];
        if ($middle[0] > 1) {
            $window[] = 1;
            if ($middle[0] > 2) {
                $window[] = '…';
            }
        }
        foreach ($middle as $n) {
            $window[] = $n;
        }
        $lastMiddle = end($middle);
        if ($lastMiddle < $lastPage) {
            if ($lastMiddle < $lastPage - 1) {
                $window[] = '…';
            }
            $window[] = $lastPage;
        }
        return $window;
    }
}

if (!function_exists('okv_page_summary')) {
    /**
     * The line over a listing. "5 items" when everything fits on one page,
     * "Showing 26 to 50 of 87 items" once there is more than one page.
     */
    function okv_page_summary(int $page, int $total, int $perPage, string $noun): string
    {
        $label = $total === 1 ? $noun : $noun . 's';
        $perPage = max(1, $perPage);
        if ($total <= $perPage) {
            return $total . ' ' . $label;
        }
        $page = max(1, $page);
        $first = ($page - 1) * $perPage + 1;
        $last = min($total, $page * $perPage);
        return 'Showing ' . $first . ' to ' . $last . ' of ' . $total . ' ' . $label;
    }
}

if (!function_exists('okv_page_of_position')) {
    /**
     * The page a row sits on, given how many rows sort before it. Row 0 opens
     * page 1, row 25 opens page 2 at 25 a page. Kept apart from the query that
     * counts those rows so the maths can be pinned by a test on its own.
     */
    function okv_page_of_position(int $before, int $perPage): int
    {
        return intdiv(max(0, $before), max(1, $perPage)) + 1;
    }
}
