<?php
/**
 * Shared access rules for every Pro Portal page.
 *
 * This gate uses the customer account type. Staff permissions are a separate
 * concern and never grant access to a customer portal.
 */

if (!function_exists('okv_pro_safe_return_path')) {
    /** Keep only a known Pro route path. Query strings are always discarded. */
    function okv_pro_safe_return_path(string $candidate): ?string
    {
        $parts = parse_url(trim($candidate));
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '/pro') {
            $path = '/pro/';
        }

        $OKV_PRO_NAV = [];
        require __DIR__ . '/../config/nav.php';
        $allowed = array_column($OKV_PRO_NAV, 'href');
        $allowed[] = '/pro/index.php';

        return in_array($path, $allowed, true) ? $path : null;
    }
}

if (!function_exists('okv_pro_household_destination')) {
    /** Send a household to the closest useful customer screen. */
    function okv_pro_household_destination(string $proPath): string
    {
        if ($proPath === '/pro/kitchen_lists.php') {
            return '/kitchen-runs.php?notice=pro_business';
        }
        return '/account.php?notice=pro_business';
    }
}

if (!function_exists('require_business_customer')) {
    /** Stop unless the current session belongs to a signed-in business customer. */
    function require_business_customer(?string $requestedPath = null): void
    {
        if (Customer::isBusiness()) {
            return;
        }

        $candidate = $requestedPath ?? (string) ($_SERVER['REQUEST_URI'] ?? '/pro/');
        $proPath = okv_pro_safe_return_path($candidate) ?? '/pro/';

        if (!Customer::isLoggedIn()) {
            okv_redirect('/account.php?mode=signin&return=' . rawurlencode($proPath));
        }

        okv_redirect(okv_pro_household_destination($proPath));
    }
}
