<?php
/**
 * scripts/tests/CheckoutTest.php
 * OK Veggies. The checkout rules that hold before the database: the order
 * total, which payment choice is open to which customer, and what is due now.
 * The transactional placement is covered by scripts/tests/checkout_db_test.php.
 */

// --- The order total is the honest sum of the price snapshots. ----------------
$lines = [
    ['quantity' => '2.000', 'unit_price_subunit' => 270000],
    ['quantity' => '1.000', 'unit_price_subunit' => 300000],
];
okv_test_eq(840000, Checkout::total($lines), 'the order total sums each line at the price it was added');
okv_test_eq(0, Checkout::total([]), 'an empty basket totals nothing');

// --- Which payment choice is open, before the database is touched. ------------
okv_test_ok(Checkout::paymentAllowed('pay_in_full', 'household', false), 'anyone may pay in full');
okv_test_ok(Checkout::paymentAllowed('deposit', 'household', false), 'anyone may pay a deposit');
okv_test_ok(!Checkout::paymentAllowed('pay_on_delivery', 'household', false), 'pay on delivery needs a verified account');
okv_test_ok(Checkout::paymentAllowed('pay_on_delivery', 'household', true), 'a verified account may pay on delivery');
okv_test_ok(!Checkout::paymentAllowed('on_account', 'household', true), 'a household may not pay on account');
okv_test_ok(Checkout::paymentAllowed('on_account', 'business', false), 'a business may choose on account, pending credit approval');
okv_test_ok(!Checkout::paymentAllowed('made_up', 'business', true), 'an unknown payment choice is refused');

// --- What is due now. The deposit for a deposit order, otherwise the full total.
okv_test_eq(1000000, Checkout::amountDue('pay_in_full', 1000000, 30.0), 'pay in full owes the whole total now');
okv_test_eq(Money::deposit(1000000, 30.0), Checkout::amountDue('deposit', 1000000, 30.0), 'a deposit order owes the deposit now');
okv_test_eq(1000000, Checkout::amountDue('pay_on_delivery', 1000000, 30.0), 'pay on delivery records the full amount to settle');
okv_test_ok(Checkout::amountDue('deposit', 1000000, 30.0) < 1000000, 'a deposit is less than the full total');

// --- Idempotency guard: a placed bag matches its own basket. ------------------
$bag = ['placed' => ['order_id' => 7], 'placed_cart_id' => 42];
okv_test_ok(Checkout::placedMatchesBasket($bag, 42), 'a placed order is recognised for its own basket');
okv_test_ok(!Checkout::placedMatchesBasket($bag, 43), 'a placed order does not match a different basket');
okv_test_ok(!Checkout::placedMatchesBasket([], 42), 'an empty bag has placed nothing');

// --- Guest checkout (Milestone 6/7 bug: the account tick was mandatory). ------
//
// PRD 9.2 allows a guest checkout. The tick that offered an account carried
// `required`, and the controller made one whether or not it was wanted, so a
// visitor who only wanted to pay in full had to open an account first. These
// pin the shape of the fix: the page offers, and never demands.

$checkoutPage = (string) file_get_contents(dirname(__DIR__, 2) . '/checkout.php');
okv_test_ok(str_contains($checkoutPage, 'name="create_account"'), 'the account offer is still on the page');
okv_test_ok(
    !preg_match('/name="create_account"[^>]*\srequired/', $checkoutPage),
    'and it is no longer required, so a guest can place an order without one'
);
okv_test_ok(str_contains($checkoutPage, 'Order as a guest'), 'the page says plainly that an account is optional');

$checkoutApi = (string) file_get_contents(dirname(__DIR__, 2) . '/api/v1/checkout.php');
okv_test_ok(
    str_contains($checkoutApi, "!Customer::isLoggedIn() && !empty(\$customer['create_account'])"),
    'the controller only opens an account when it was asked for'
);
okv_test_ok(!str_contains($checkoutApi, 'consent_required'), 'refusing a checkout for want of a tick is gone');
okv_test_ok(str_contains($checkoutApi, 'OrderTrail::remember'), 'a guest order is remembered, so Paystack can send them back to it');

// A guest order has no account behind it, so the order carries the address its
// email goes to. Every write path has to admit a null user.
$checkoutClass = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/classes/Checkout.php');
foreach (['writeOrder(PDO $pdo, ?int $userId', 'writeAddress(int $orderId, ?int $userId', 'writePayments(int $orderId, ?int $userId'] as $signature) {
    okv_test_ok(str_contains($checkoutClass, $signature), 'a guest order can be written: ' . $signature);
}
okv_test_ok(str_contains($checkoutClass, 'contact_email'), 'the order keeps the email address it was placed with');
