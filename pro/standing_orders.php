<?php
/**
 * pro/standing_orders.php
 * OK Veggies. Repeat orders on a schedule.
 * The standing-order domain work remains in its own M8 task.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_business_customer();
require_once __DIR__ . '/../includes/components/pro/placeholder.php';

$okv_pro_title  = 'Standing Orders';
$okv_pro_note   = 'Repeat order schedules belong on this screen.';
$okv_pro_active = '/pro/standing_orders.php';
require __DIR__ . '/../includes/components/pro/header.php';

okv_pro_screen_shell(
    'Repeat orders belong here',
    'Standing Orders will hold the lists a business asks us to prepare on a regular schedule.',
    'Scheduling, pausing and changing a standing order are not available on this screen yet.',
    [
        ['label' => 'Open My Kitchen Lists', 'href' => '/pro/kitchen_lists.php', 'primary' => true],
    ]
);

require __DIR__ . '/../includes/components/pro/footer.php';
