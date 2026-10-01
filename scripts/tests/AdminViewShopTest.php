<?php
/**
 * Pure contracts for the admin's way out to the storefront.
 *
 * The shared header's "View shop" control and the command palette's storefront
 * command must open the shop page. They must never open "/": index.php sends a
 * signed-in staff member straight back to /admin/, so a link to the home URL
 * loops the button into the dashboard it was meant to leave.
 */

$root    = dirname(__DIR__, 2);
$header  = (string) file_get_contents($root . '/includes/components/admin/header.php');
$palette = (string) file_get_contents($root . '/includes/components/admin/command_palette.php');
$home    = (string) file_get_contents($root . '/index.php');
require_once $root . '/includes/config/nav.php';

okv_test_ok(
    (bool) preg_match('#<a\b[^>]*\bhref="([^"]+)"[^>]*>(?:(?!</a>).)*?View shop#s', $header, $match),
    'the shared header renders a View shop control'
);
okv_test_eq('/shop.php', $match[1] ?? null, 'View shop opens the storefront shop page');
okv_test_ok(
    str_contains($header, 'href="/shop.php" target="_blank" rel="noopener"'),
    'View shop opens in a new tab without handing the referrer over'
);
okv_test_ok(
    !str_contains($header, 'href="/" class="okv-btn-text'),
    'no header control points View shop at the staff-bouncing home URL'
);

// The contract this fix leans on: the home route still bounces signed-in
// staff to the admin dashboard, so the admin's storefront link stays off it.
$rbac = (string) file_get_contents($root . '/includes/classes/Rbac.php');
okv_test_ok(
    str_contains($home, 'Rbac::isStaff()') && str_contains($home, 'Rbac::redirectToLanding()'),
    'the home route still sends signed-in staff to their admin landing'
);
okv_test_ok(
    str_contains($rbac, "header('Location: ' . (self::isStaff() ? '/admin/' : '/'))"),
    'that staff landing is the admin dashboard, not the storefront'
);

// The palette carries the same destination, flagged for a new tab.
okv_test_ok(str_contains($palette, "'label'    => 'View shop'"), 'the palette names the storefront command View shop');
okv_test_ok(str_contains($palette, "'href'     => '/shop.php'"), 'the palette storefront command opens the shop page');
okv_test_ok(str_contains($palette, "'new_tab'  => true"), 'the palette storefront command asks for a new tab');
okv_test_ok(
    str_contains($palette, 'target="_blank" rel="noopener"') && str_contains($palette, "\$okv_command['new_tab']"),
    'the palette renders a new-tab target for the flagged command only'
);

// One source of truth: the storefront's own nav calls this route the shop.
$shopNav = array_column($OKV_SHOP_NAV, 'href', 'label');
okv_test_eq('/shop.php', $shopNav['Shop'] ?? null, 'the storefront nav agrees the shop route is /shop.php');
