<?php
/**
 * pro/orders.php
 * OK Veggies. Your orders and their invoices.
 * The business order and invoice domain work remains in its own M8 task.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_business_customer();
require_once __DIR__ . '/../includes/components/pro/placeholder.php';

$okv_pro_title  = 'Orders and Invoices';
$okv_pro_note   = 'Business order history and invoice records belong on this screen.';
$okv_pro_active = '/pro/orders.php';
require __DIR__ . '/../includes/components/pro/header.php';

okv_pro_screen_shell(
    'Business orders and invoices belong here',
    'This screen will bring together this account\'s orders, delivery progress and invoice records.',
    'The Pro order list and invoice downloads are not available on this screen yet. Your current orders remain in Your account.',
    [
        ['label' => 'Open Your account', 'href' => '/account.php', 'primary' => true],
    ]
);

require __DIR__ . '/../includes/components/pro/footer.php';
