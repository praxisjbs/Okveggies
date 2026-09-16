<?php
/** M10 Task F storefront and checkout trust-signal contract. */
$root = dirname(__DIR__, 2);
$checkout = (string) file_get_contents($root . '/checkout.php');
$page = (string) file_get_contents($root . '/page.php');
$product = (string) file_get_contents($root . '/product.php');
$combo = (string) file_get_contents($root . '/combo.php');
$productCard = (string) file_get_contents($root . '/includes/components/shop/product_card.php');
$comboCard = (string) file_get_contents($root . '/includes/components/shop/combo_card.php');
$comboSpread = (string) file_get_contents($root . '/includes/components/shop/combo_spread.php');

okv_test_eq('Tomatoes, kg, sourced from Ogun State', okv_produce_alt('Tomatoes', 'kg', 'Ogun State'), 'F: complete produce alt text follows the PRD pattern');
okv_test_eq('Tomatoes, kg', okv_produce_alt('Tomatoes', 'kg', ''), 'F: missing source data is omitted rather than invented');
okv_test_eq('Tomatoes, sourced from Jos', okv_produce_alt('Tomatoes', '', 'Jos'), 'F: missing unit is omitted cleanly');
okv_test_ok(str_contains($checkout, '/assets/img/payments/paystack.svg'), 'F: checkout carries the Paystack mark');
foreach (['Card', 'Bank transfer', 'USSD', 'Secure Paystack payment', 'Follow every order', 'We make it right'] as $copy) {
    okv_test_ok(str_contains($checkout, $copy), 'F: checkout carries trust copy: ' . $copy);
}
okv_test_ok(str_contains($checkout, '/page.php?slug=delivery-policy#make-it-right'), 'F: checkout links to the Make It Right policy section');
okv_test_ok(str_contains($page, 'id="make-it-right"'), 'F: the Delivery Policy exposes the linked section');
okv_test_ok(str_contains($page, 'IssueReports::reportingWindowDays()'), 'F: policy uses the managed reporting window');
foreach ([$product, $combo, $productCard] as $source) {
    okv_test_ok(str_contains($source, 'okv_sourced_note('), 'F: every product and combo buying page renders the shared sourcing line');
}
foreach ([$comboCard, $comboSpread] as $source) {
    okv_test_ok(str_contains($source, 'sourced from'), 'F: combo storefront photography includes sourcing when available');
}
