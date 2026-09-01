<?php
$homePage = file_get_contents(dirname(__DIR__, 2) . '/index.php');
okv_test_ok(
    str_contains($homePage, 'class="okv-btn-outline border-white bg-transparent text-white hover:border-gold hover:bg-transparent hover:text-gold"'),
    'home hero combos button is transparent and uses gold only on hover'
);
