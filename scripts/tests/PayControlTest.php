<?php
/**
 * The Pay control: nothing when nothing is owed, one button for one way of
 * paying, and a "Pay now" button that opens a sheet when there are several.
 * Rendered here so the markup the customer touches is asserted, not assumed.
 */
require_once dirname(__DIR__, 2) . '/includes/classes/Csrf.php';
require_once dirname(__DIR__, 2) . '/includes/components/shop/pay_sheet.php';

$render = static function (callable $draw): string {
    ob_start();
    $draw();
    return (string) ob_get_clean();
};

$card = [
    'key' => 'paystack', 'label' => 'Pay ₦8,000', 'icon' => 'card', 'primary' => true,
    'action' => 'initialise', 'endpoint' => '/api/v1/payments.php', 'payment_id' => 5,
    'amount_subunit' => 800000, 'info' => 'Card, bank transfer or USSD on Paystack.',
];
$credit = [
    'key' => 'credit_line', 'label' => 'Use my credit line', 'icon' => 'handshake', 'primary' => false,
    'action' => 'use_credit_line', 'endpoint' => '/api/v1/orders.php', 'order_id' => 9,
    'note' => '₦20,000 available', 'info' => 'We put this order on your credit line.',
];
$order = ['id' => 9, 'order_number' => 'OKV26000009'];

// --- no methods: no button at all ---------------------------------------------------------
okv_test_eq('', trim($render(static fn() => okv_pay_action($order, []))), 'a settled order draws no Pay control');

// --- one method: one tap, no sheet -----------------------------------------------------------
$one = $render(static fn() => okv_pay_action($order, [$card]));
okv_test_eq(1, substr_count($one, '<form'), 'one method is one form');
okv_test_ok(!str_contains($one, 'okv-sheet-backdrop') && !str_contains($one, 'data-sheet-open'), 'and no sheet to open first');
okv_test_ok(str_contains($one, 'action="/api/v1/payments.php"'), 'the form posts to the payments endpoint');
okv_test_ok(str_contains($one, 'name="action" value="initialise"'), 'with the initialise action');
okv_test_ok(str_contains($one, 'name="payment_id" value="5"'), 'for the payment it names');
okv_test_ok(!str_contains($one, 'name="order_id"'), 'and carries no order id it does not need');
okv_test_ok(str_contains($one, 'data-once'), 'a double tap cannot post it twice');
okv_test_ok(str_contains($one, 'Pay ₦8,000'), 'the button says what it will charge');
okv_test_ok(str_contains($one, 'min-h-[44px]'), 'and meets the 44px touch target');
okv_test_ok(preg_match('/<input[^>]+type="hidden"[^>]+name="[^"]*csrf[^"]*"/i', $one) === 1 || str_contains(strtolower($one), 'csrf'), 'every form carries a CSRF token');
okv_test_ok(!str_contains($one, 'name="token"'), 'a signed-in customer sends no guest token');

$guest = $render(static fn() => okv_pay_action($order, [$card], 'abc123token'));
okv_test_ok(str_contains($guest, 'name="token" value="abc123token"'), 'a guest reaches Paystack with their trail token');
$guestEscaped = $render(static fn() => okv_pay_action($order, [$card], '"><script>alert(1)</script>'));
okv_test_ok(!str_contains($guestEscaped, '<script>'), 'a hostile token is escaped');

// --- several methods: one big button each, inside a sheet ---------------------------------------
$many = $render(static fn() => okv_pay_action($order, [$card, $credit]));
okv_test_ok(str_contains($many, 'data-sheet-open="pay-sheet-9"'), 'several methods open a sheet from a Pay now button');
okv_test_ok(str_contains($many, '<span>Pay now</span>'), 'the trigger says Pay now');
okv_test_ok(str_contains($many, 'role="dialog"') && str_contains($many, 'aria-modal="true"'), 'the sheet is a modal dialog');
okv_test_ok(str_contains($many, 'aria-labelledby="pay-sheet-9-title"') && str_contains($many, 'id="pay-sheet-9-title"'), 'and is labelled by its heading');
okv_test_eq(2, substr_count($many, '<form'), 'one form per method');
okv_test_ok(str_contains($many, 'name="action" value="use_credit_line"') && str_contains($many, 'name="order_id" value="9"'), 'the credit line posts its own action for this order');
okv_test_ok(str_contains($many, 'data-sheet-close'), 'the sheet can be closed');
okv_test_eq(4, substr_count($many, 'min-h-[56px]'), 'each method is a large button, beside a full height info target');
okv_test_ok(str_contains($many, '₦20,000 available'), 'the credit line shows what is available');
okv_test_eq(2, substr_count($many, 'aria-label="More about '), 'long text sits behind an info icon for each method');
okv_test_ok(str_contains($many, 'We put this order on your credit line.'), 'and is still in the page for those who open it');
okv_test_ok(str_contains($many, 'OKV26000009'), 'the sheet names the order');
okv_test_ok(strpos($many, 'okv-btn ') < strpos($many, 'okv-btn-outline'), 'the primary method comes first and the other is outlined');

$hostile = $render(static fn() => okv_pay_action($order, [['label' => '<script>x</script>', 'note' => '"><b>'] + $card, $credit]));
okv_test_ok(!str_contains($hostile, '<script>x</script>') && !str_contains($hostile, '"><b>'), 'labels and notes are escaped');

// --- the badge -------------------------------------------------------------------------------------
foreach (['good' => 'okv-badge-available', 'neutral' => 'okv-badge-neutral', 'warn' => 'okv-badge-warn'] as $tone => $class) {
    $badge = $render(static fn() => okv_money_badge(['tone' => $tone, 'badge' => 'On your credit line']));
    okv_test_ok(str_contains($badge, $class) && str_contains($badge, 'On your credit line'), "the $tone badge uses $class");
}
$badge = $render(static fn() => okv_money_badge(['tone' => 'good', 'badge' => '<i>x</i>']));
okv_test_ok(!str_contains($badge, '<i>'), 'the badge text is escaped');

// --- the endpoints the controls post to guard themselves -----------------------------------------------
$root = dirname(__DIR__, 2);
$orders = (string) file_get_contents($root . '/api/v1/orders.php');
$block = substr($orders, (int) strpos($orders, "if (\$action === 'use_credit_line')"), 2200);
okv_test_ok(str_contains($block, 'okv_is_post()'), 'use_credit_line accepts POST only');
okv_test_ok(str_contains($block, 'Customer::requireLoginApi()'), 'and needs a signed-in customer');
okv_test_ok(str_contains($block, 'Csrf::validate()'), 'and a CSRF token');
okv_test_ok(str_contains($block, 'RateLimiter::hit('), 'and is rate limited');
okv_test_ok(str_contains($block, 'Credit::useCreditLine($orderId, $userId, $userId'), 'and only ever acts on the caller\'s own order');
$payments = (string) file_get_contents($root . '/api/v1/payments.php');
$block = substr($payments, (int) strpos($payments, "if (\$action === 'start_deposit')"), 2400);
okv_test_ok(str_contains($block, 'okv_is_post()') && str_contains($block, 'Csrf::validate()'), 'start_deposit accepts POST with a CSRF token only');
okv_test_ok(str_contains($block, 'order_trail_token_hash = :token') && str_contains($block, 'user_id = :user'), 'and proves ownership by account or guest trail token');
okv_test_ok(str_contains($block, 'RateLimiter::hit('), 'and is rate limited');
