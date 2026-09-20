<?php
/** M10 Task F storefront and checkout trust-signal contract. */
$root = dirname(__DIR__, 2);
$checkout = (string) file_get_contents($root . '/checkout.php');
$page = (string) file_get_contents($root . '/page.php');
$guidance = (string) file_get_contents($root . '/includes/components/shop/make_it_right_guidance.php');
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
okv_test_ok(str_contains($checkout, 'okv_make_it_right_policy_url()'), 'F: checkout resolves the Make It Right link from published content state');

// Fix 13 (plan PR3): the trust panel sits below the whole payment radio group,
// never between two options, so it cannot read as a payment choice itself.
// The order in the file is the order on the screen: every payment radio first,
// then the trust panel, then the policy note and the pay button.
$lastRadioPos  = strripos($checkout, 'name="payment_option"');
$trustPanelPos = stripos($checkout, 'data-trust-panel');
$policyPos     = stripos($checkout, 'Cancellation::policyLine');
okv_test_ok($lastRadioPos !== false, 'F: checkout still renders the payment radio group');
okv_test_ok($trustPanelPos !== false && $trustPanelPos > $lastRadioPos, 'F: the trust panel sits below the whole payment radio group');
okv_test_ok($policyPos !== false && $policyPos > $trustPanelPos, 'F: the policy line follows the trust panel');

// The choices are large cards with a 44px-plus radio hit area, not a text list.
foreach (['pay_in_full', 'deposit', 'pay_on_delivery'] as $option) {
    okv_test_ok(preg_match('/class="[^"]*okv-choice[^"]*"[^>]*>\s*<span[^>]*>\s*<input[^>]*name="payment_option"[^>]*value="' . $option . '"/s', $checkout) === 1,
        'F: the ' . $option . ' choice is a large tappable card');
}
okv_test_ok(str_contains($checkout, 'okv-radio'), 'F: payment radios carry the drawn radio component');
okv_test_ok(str_contains($checkout, 'form="checkout-payment-form"'), 'F: the sticky pay bar submits the payment form');
okv_test_ok(str_contains($checkout, 'data-sheet-open="fee-sheet"'), 'F: the fee explanation is a sheet, not a paragraph wall');
okv_test_ok(str_contains($checkout, 'Cancellation::policyLine('), 'F: checkout still speaks the one cancellation sentence from Cancellation::policyLine');
okv_test_ok(str_contains($page, "make_it_right_guidance.php"), 'F: How It Works and Delivery Policy can expose operational guidance');
okv_test_ok(str_contains($guidance, 'IssueReports::reportingWindowDays()'), 'F: policy uses the managed reporting window');
foreach ([$product, $combo, $productCard] as $source) {
    okv_test_ok(str_contains($source, 'okv_sourced_note('), 'F: every product and combo buying page renders the shared sourcing line');
}
foreach ([$comboCard, $comboSpread] as $source) {
    okv_test_ok(str_contains($source, 'sourced from'), 'F: combo storefront photography includes sourcing when available');
}
