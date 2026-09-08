<?php
/**
 * pro/index.php
 * OK Veggies. Your business at a glance.
 * The domain summaries arrive in their own M8 tasks. This screen provides the
 * protected portal home and working routes without presenting made-up data.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_business_customer();
require_once __DIR__ . '/../includes/components/pro/placeholder.php';

$okv_pro_title  = 'Dashboard';
$okv_pro_note   = 'Your business ordering home.';
$okv_pro_active = '/pro/';
require __DIR__ . '/../includes/components/pro/header.php';

okv_pro_screen_shell(
    'Start with what your kitchen needs',
    'Use Kitchen Lists to send produce requirements. Your current order history remains in Your account.',
    'Order, delivery and credit summaries are not shown on the Dashboard yet.',
    [
        ['label' => 'Open My Kitchen Lists', 'href' => '/pro/kitchen_lists.php', 'primary' => true],
        ['label' => 'Open Your account', 'href' => '/account.php'],
    ]
);

require __DIR__ . '/../includes/components/pro/footer.php';
