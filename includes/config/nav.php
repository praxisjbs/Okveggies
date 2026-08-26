<?php
/**
 * includes/config/nav.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Navigation structure for the three surfaces. The sidebar and any
 * command palette read from here, so they can never disagree about what a user
 * can reach. Each admin item names the permission it needs; the renderer hides
 * anything the user is not allowed, and hides a whole section when all of its
 * items are hidden.
 *
 * Icons are Heroicons v2 outline names; the renderer maps them to SVG.
 * -----------------------------------------------------------------------------
 */

// Admin panel (/admin) --------------------------------------------------------
$OKV_ADMIN_NAV = [
    [
        'heading' => 'Overview',
        'items' => [
            ['label' => 'Dashboard', 'href' => '/admin/',                 'icon' => 'home',           'permission' => 'dashboard.view'],
        ],
    ],
    [
        'heading' => 'Selling',
        'items' => [
            ['label' => 'Orders',        'href' => '/admin/orders.php',       'icon' => 'clipboard',   'permission' => 'orders.view'],
            ['label' => 'Kitchen Runs',  'href' => '/admin/kitchen_runs.php', 'icon' => 'list',        'permission' => 'kitchen_runs.view'],
            ['label' => 'Combos',        'href' => '/admin/combos.php',       'icon' => 'squares',     'permission' => 'combos.view'],
            ['label' => 'Products',      'href' => '/admin/products.php',     'icon' => 'leaf',        'permission' => 'products.view'],
            ['label' => 'Pricing',       'href' => '/admin/pricing.php',      'icon' => 'tag',         'permission' => 'pricing.view'],
        ],
    ],
    [
        'heading' => 'Customers and money',
        'items' => [
            ['label' => 'Customers',     'href' => '/admin/customers.php',    'icon' => 'users',       'permission' => 'customers.view'],
            ['label' => 'Payments',      'href' => '/admin/payments.php',     'icon' => 'card',        'permission' => 'payments.view'],
            ['label' => 'Credit',        'href' => '/admin/credit.php',       'icon' => 'scale',       'permission' => 'credit.view'],
        ],
    ],
    [
        'heading' => 'Delivery and care',
        'items' => [
            ['label' => 'Delivery',      'href' => '/admin/delivery.php',     'icon' => 'truck',       'permission' => 'delivery.view'],
            ['label' => 'Make It Right', 'href' => '/admin/make_it_right.php','icon' => 'heart',       'permission' => 'issues.view'],
            ['label' => 'Messages',      'href' => '/admin/content.php',      'icon' => 'chat',        'permission' => 'messages.view'],
        ],
    ],
    [
        'heading' => 'Setup',
        'items' => [
            ['label' => 'Settings',      'href' => '/admin/settings.php',     'icon' => 'cog',         'permission' => 'settings.view'],
            ['label' => 'Users',         'href' => '/admin/users.php',        'icon' => 'shield',      'permission' => 'users.view'],
        ],
    ],
];

// Pro Portal (/pro), for logged-in business customers -------------------------
$OKV_PRO_NAV = [
    ['label' => 'Dashboard',       'href' => '/pro/',                 'icon' => 'home'],
    ['label' => 'My Kitchen Lists','href' => '/pro/kitchen_lists.php','icon' => 'list'],
    ['label' => 'Standing Orders', 'href' => '/pro/standing_orders.php','icon' => 'repeat'],
    ['label' => 'Orders',          'href' => '/pro/orders.php',       'icon' => 'clipboard'],
    ['label' => 'Credit',          'href' => '/pro/credit.php',       'icon' => 'scale'],
    ['label' => 'Account',         'href' => '/pro/account.php',      'icon' => 'user'],
];

// Storefront (/), primary navigation ------------------------------------------
$OKV_SHOP_NAV = [
    ['label' => 'Shop',         'href' => '/shop.php',        'icon' => 'leaf'],
    ['label' => 'Combos',       'href' => '/combos.php',      'icon' => 'squares'],
    ['label' => 'Kitchen Runs', 'href' => '/kitchen-runs.php','icon' => 'list'],
];

// Footer links (reached from the footer, not the top nav) ---------------------
$OKV_FOOTER_NAV = [
    ['label' => 'Our Story',       'href' => '/page.php?slug=about'],
    ['label' => 'How It Works',    'href' => '/page.php?slug=how-it-works'],
    ['label' => 'Questions',       'href' => '/page.php?slug=faq'],
    ['label' => 'Terms',           'href' => '/page.php?slug=terms'],
    ['label' => 'Privacy',         'href' => '/page.php?slug=privacy'],
    ['label' => 'Delivery Policy', 'href' => '/page.php?slug=delivery-policy'],
];
