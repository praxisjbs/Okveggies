<?php
/**
 * pro/credit.php
 * OK Veggies. Your limit, balance and terms.
 * Credit application, approval, limits and balances remain in their own M8
 * task. This page does not imply that credit has been granted.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_business_customer();
require_once __DIR__ . '/../includes/components/pro/placeholder.php';

$okv_pro_title  = 'Credit';
$okv_pro_note   = 'Credit terms, balances and due dates belong on this screen.';
$okv_pro_active = '/pro/credit.php';
require __DIR__ . '/../includes/components/pro/header.php';

okv_pro_screen_shell(
    'Credit information belongs here',
    'An approved business account will use this screen to check its limit, balance, terms and amounts due.',
    'Applications, credit decisions and balances are not available on this screen yet. No credit status is implied here.',
    [
        ['label' => 'Open Your account', 'href' => '/account.php', 'primary' => true],
    ]
);

require __DIR__ . '/../includes/components/pro/footer.php';
