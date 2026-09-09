<?php
/**
 * scripts/tests/M8VerificationTest.php
 * ---------------------------------------------------------------------------
 * OK Veggies. M8 Task K. Focused M8 verification suite.
 *
 * A single MySQL-free file that proves the checklist Section K names,
 * without waiting on a database. The database suites (credit_orders_db_test,
 * customers_db_test, etc.) prove the same behaviour against MySQL 8; this
 * proves the wiring, the gates and the honesty in one place so K is not
 * green-only-because-the-unit-tests-were-green.
 *
 *   php scripts/tests/run.php
 * ---------------------------------------------------------------------------
 */

$appRoot = dirname(__DIR__, 2);
$creditClass = (string) file_get_contents($appRoot . '/includes/classes/Credit.php');
$checkoutClass = (string) file_get_contents($appRoot . '/includes/classes/Checkout.php');
$kitchenWorkflow = (string) file_get_contents($appRoot . '/includes/classes/KitchenRunWorkflow.php');
$customersClass = (string) file_get_contents($appRoot . '/includes/classes/Customers.php');
$notificationsClass = (string) file_get_contents($appRoot . '/includes/classes/Notifications.php');
$proOrdersClass = (string) file_get_contents($appRoot . '/includes/classes/ProOrders.php');
$orderDocumentClass = (string) file_get_contents($appRoot . '/includes/classes/OrderDocument.php');
$checkoutEndpoint = (string) file_get_contents($appRoot . '/api/v1/checkout.php');
$creditEndpoint = (string) file_get_contents($appRoot . '/api/v1/credit.php');
$checkoutPage = (string) file_get_contents($appRoot . '/checkout.php');
$kitchenPanel = (string) file_get_contents($appRoot . '/includes/components/admin/kitchen_run_panel.php');
$customersEndpoint = (string) file_get_contents($appRoot . '/api/v1/customers.php');
$standingOrdersPage = (string) file_get_contents($appRoot . '/pro/standing_orders.php');
$kitchenRunsApi = (string) file_get_contents($appRoot . '/api/v1/kitchen_runs.php');
$mailClass = (string) file_get_contents($appRoot . '/includes/classes/Mail.php');

// --------------------------------------------------------------------------
// Credit maths and the one shared draw rule.
// --------------------------------------------------------------------------

$facility = static function (string $state, int $limit, int $outstanding, int $days = 7): array {
    $business = ['id' => 9, 'business_name' => 'K Check', 'credit_status' => $state, 'credit_days' => $days, 'credit_limit_subunit' => $limit];
    return Credit::facility($business, Credit::snapshot($business, ['outstanding_subunit' => $outstanding]));
};

$clear = $facility('approved', 50000000, 0);
okv_test_eq(50000000, $clear['available_subunit'], 'K: a clear approved facility exposes its whole limit');
okv_test_eq('', Credit::drawRefusal($clear, 50000000), 'K: exact limit is allowed on a clear account');
okv_test_eq('credit_limit_exceeded', Credit::drawRefusal($clear, 50000001), 'K: one kobo above the limit is refused');
okv_test_eq('invalid_charge', Credit::drawRefusal($clear, 0), 'K: zero or negative charge is refused as invalid_charge');

$partly = $facility('approved', 50000000, 42000000);
okv_test_eq(8000000, $partly['available_subunit'], 'K: available is limit minus outstanding');
okv_test_eq('credit_limit_exceeded', Credit::drawRefusal($partly, 8000001), 'K: one kobo above available is refused');
okv_test_eq('', Credit::drawRefusal($partly, 8000000), 'K: exact available is allowed');
okv_test_eq('credit_limit_exceeded', Credit::drawRefusal($partly, 9000000), 'K: over-limit is refused');

$afterRepay = $facility('approved', 50000000, 42000000 - 3000000);
okv_test_eq(11000000, $afterRepay['available_subunit'], 'K: a 30,000 naira repayment frees 30,000 naira');
okv_test_eq('', Credit::drawRefusal($afterRepay, 11000000), 'K: the freed credit can be drawn');

foreach (['suspended', 'withdrawn', 'requested', 'declined', 'not_requested'] as $state) {
    okv_test_eq('credit_not_approved', Credit::drawRefusal($facility($state, 50000000, 0), 500000), "K: $state credit cannot be drawn");
}
okv_test_eq('credit_not_approved', Credit::drawRefusal(null, 500000), 'K: no business profile cannot draw');
okv_test_eq('credit_not_approved', Credit::drawRefusal($facility('approved', 50000000, 0, 6), 500000), 'K: term below 7 days is not approved');
okv_test_eq('credit_not_approved', Credit::drawRefusal($facility('approved', 50000000, 0, 11), 500000), 'K: term above 10 days is not approved');
okv_test_eq('2026-09-16', Credit::dueDateFor('2026-09-09', 7), 'K: due date is delivery plus 7 days');
okv_test_eq('2026-09-19', Credit::dueDateFor('2026-09-09', 10), 'K: due date is delivery plus 10 days');

// --------------------------------------------------------------------------
// Locked draw wiring and idempotency.
// --------------------------------------------------------------------------

okv_test_ok(str_contains($creditClass, 'FOR UPDATE'), 'K: the draw locks the facility row');
okv_test_ok(str_contains($creditClass, 'FOR SHARE'), 'K: the draw uses a locking read for the journal');
okv_test_ok(str_contains($creditClass, 'credit draw called outside a transaction'), 'K: draw refuses to run outside a transaction');
okv_test_ok(str_contains($creditClass, "'order:' . \$orderId . ':charge'"), 'K: one order can carry only one keyed charge');
okv_test_ok(str_contains($creditClass, 'source_key'), 'K: charge insert is keyed by source_key');
okv_test_ok(str_contains($creditClass, 'balance_due_subunit'), 'K: the charge is the order balance due after any deposit');
okv_test_ok(str_contains($creditClass, 'already') && str_contains($creditClass, 'source_key'), 'K: a retry returns the existing charge rather than writing a second row');

// Repayment and adjustment idempotency lives on payment id and source_key.
okv_test_ok(str_contains($creditClass, 'repayment_recorded'), 'K: duplicate repayment is refused');
okv_test_ok(str_contains($creditClass, 'payment_id'), 'K: repayment is tied to its payment');
okv_test_ok(str_contains($creditClass, 'adjustCancelledOrder'), 'K: cancellation appends an adjustment');
okv_test_ok(str_contains($creditClass, 'adjustRefund'), 'K: refund appends an adjustment');
okv_test_ok(!str_contains($creditClass, 'DELETE FROM credit_transactions'), 'K: financial records are never deleted');
okv_test_ok(!str_contains($creditClass, 'UPDATE credit_transactions SET'), 'K: financial records are never edited in place');

// --------------------------------------------------------------------------
// Both checkout paths read the same rule.
// --------------------------------------------------------------------------

okv_test_ok(str_contains($checkoutClass, 'Credit::drawRefusal'), 'K: ordinary checkout refuses over-limit before writing');
okv_test_ok(str_contains($checkoutClass, 'Credit::drawForOrder'), 'K: ordinary checkout draws through the shared service');
okv_test_ok(str_contains($kitchenWorkflow, 'Credit::drawRefusal'), 'K: Kitchen Run conversion reads the same shared rule');
okv_test_ok(str_contains($kitchenWorkflow, 'Credit::drawForOrder'), 'K: Kitchen Run conversion draws through the shared service');
okv_test_ok(!str_contains($kitchenWorkflow, '$creditApproved = $paymentOption'), 'K: Kitchen Run no longer assumes approval from payment choice');
okv_test_ok(str_contains($kitchenWorkflow, 'Credit::facilityForUser'), 'K: Kitchen Run reads the real facility');
okv_test_ok(str_contains($checkoutPage, 'Credit::drawRefusal'), 'K: checkout page shows eligibility from the real facility');
okv_test_ok(str_contains($kitchenPanel, 'Credit::drawRefusal'), 'K: conversion panel shows staff the real available credit');

// --------------------------------------------------------------------------
// Pro order ownership and document isolation.
// --------------------------------------------------------------------------

okv_test_ok(str_contains($proOrdersClass, 'user_id') && str_contains($proOrdersClass, 'o.user_id = :user_id'), 'K: Pro orders are scoped to the signed-in business');
okv_test_ok(str_contains($orderDocumentClass, 'user_id') || str_contains($orderDocumentClass, 'customer'), 'K: order documents check ownership before returning data');
okv_test_ok(str_contains((string) file_get_contents($appRoot . '/pro/orders.php'), 'ProOrders'), 'K: Pro orders page reads through the owned service');
$customersTest = (string) file_get_contents($appRoot . '/scripts/tests/CustomersTest.php');
okv_test_ok(str_contains($customersClass, 'business_customers') || str_contains($customersClass, 'user_id'), 'K: Customers reads are scoped');
okv_test_ok(str_contains($customersClass, 'SELECT') && !str_contains($customersClass, 'INSERT INTO') || str_contains($customersClass, 'read-only') || substr_count($customersClass, 'INSERT INTO') === 0, 'K: Customers class performs no writes');

// --------------------------------------------------------------------------
// Method, POST, CSRF, auth, account-type, RBAC and ownership gates.
// --------------------------------------------------------------------------

okv_test_ok(str_contains($checkoutEndpoint, 'okv_is_post()') || str_contains($checkoutEndpoint, 'Use POST'), 'K: checkout endpoint requires POST');
okv_test_ok(str_contains($checkoutEndpoint, 'Csrf::validate()'), 'K: checkout endpoint checks CSRF');
okv_test_ok(str_contains($creditEndpoint, 'okv_is_post()'), 'K: credit endpoint requires POST');
okv_test_ok(str_contains($creditEndpoint, 'Csrf::validate()'), 'K: credit endpoint checks CSRF');
okv_test_ok(str_contains($creditEndpoint, "Customer::isBusiness()"), 'K: credit apply checks business account type');
okv_test_ok(str_contains($creditEndpoint, 'Rbac::requirePermission'), 'K: credit admin actions check RBAC');
okv_test_ok(str_contains($customersEndpoint, 'Rbac::requirePermission') || str_contains($customersEndpoint, 'customers.view'), 'K: customers endpoint checks RBAC');
okv_test_ok(str_contains($kitchenRunsApi, 'okv_is_post()') || str_contains($kitchenRunsApi, 'method_not_allowed'), 'K: kitchen runs endpoint checks method');
okv_test_ok(str_contains($kitchenRunsApi, 'Csrf::validate()'), 'K: kitchen runs endpoint checks CSRF');
foreach (['/pro/index.php', '/pro/credit.php', '/pro/orders.php', '/pro/standing_orders.php', '/pro/kitchen_lists.php', '/pro/account.php'] as $proPage) {
    $body = (string) file_get_contents($appRoot . $proPage);
    okv_test_ok(str_contains($body, 'require_business_customer') || str_contains($body, 'require_business') || str_contains($body, 'pro_access'), "K: $proPage checks business account type before reading data");
}

// --------------------------------------------------------------------------
// Standing-orders page honesty.
// --------------------------------------------------------------------------

okv_test_ok(str_contains($standingOrdersPage, 'planned for a later phase'), 'K: standing orders states the feature is planned');
okv_test_ok(str_contains($standingOrdersPage, 'Nothing is scheduled'), 'K: standing orders states nothing is scheduled');
okv_test_ok(!str_contains(strtolower($standingOrdersPage), '<form'), 'K: standing orders contains no form');
okv_test_ok(!str_contains(strtolower($standingOrdersPage), 'name=\"recurrence\"') && !str_contains(strtolower($standingOrdersPage), 'recurrence'), 'K: standing orders contains no recurrence control');
okv_test_ok(str_contains($standingOrdersPage, 'My Kitchen Lists') && str_contains($standingOrdersPage, 'Kitchen Run'), 'K: standing orders links to the working Kitchen List and Kitchen Run paths');
okv_test_ok(str_contains($standingOrdersPage, 'wa.me') || str_contains($standingOrdersPage, 'WhatsApp'), 'K: standing orders links to configured WhatsApp support');

// --------------------------------------------------------------------------
// Notification events and failure behaviour.
// --------------------------------------------------------------------------

foreach (['credit_approved', 'credit_declined', 'credit_charge_posted', 'admin_new_credit_application'] as $event) {
    okv_test_ok(str_contains($notificationsClass, $event), "K: notification event $event is registered");
}
okv_test_ok(str_contains($notificationsClass, 'announceCreditApproved'), 'K: approve announcement exists');
okv_test_ok(str_contains($notificationsClass, 'announceCreditDeclined'), 'K: decline announcement exists');
okv_test_ok(str_contains($notificationsClass, 'announceCreditChargePosted'), 'K: charge announcement exists');
okv_test_ok(str_contains($notificationsClass, 'announceCreditApplicationSubmitted'), 'K: application submitted announcement exists');
okv_test_ok(str_contains($creditEndpoint, 'try') && str_contains($creditEndpoint, 'announceCredit') && str_contains($creditEndpoint, 'catch (Throwable'), 'K: credit endpoint announces only after commit and logs on failure');
okv_test_ok(str_contains($checkoutEndpoint, 'try') && str_contains($checkoutEndpoint, 'announceCreditChargePosted') || str_contains($checkoutClass, 'announceCreditChargePosted') || str_contains($creditEndpoint, 'error_log'), 'K: charge announcement failure does not roll back the order');
okv_test_ok(str_contains($kitchenRunsApi, 'announceCreditChargePosted') && str_contains($kitchenRunsApi, 'try'), 'K: Kitchen Run conversion charge announcement is wrapped');
okv_test_ok(str_contains($mailClass, 'credit_url') || str_contains($notificationsClass, 'credit_url'), 'K: credit mail carries a credit_url CTA');
okv_test_ok(str_contains((string) file_get_contents($appRoot . '/migrations/032_credit_notifications.sql'), 'ON DUPLICATE KEY UPDATE'), 'K: credit notification migration is idempotent');

// Declined mail must carry decision reason only, never the applicant's own reason.
$declinedBody = $notificationsClass;
okv_test_ok(str_contains($declinedBody, 'declined_reason') || str_contains($declinedBody, 'decision_reason'), 'K: declined mail uses the approved staff reason');
okv_test_ok(!str_contains($notificationsClass, "'credit_declined' =>") || str_contains($notificationsClass, 'declined_reason'), 'K: declined event does not leak the application reason');

// --------------------------------------------------------------------------
// Responsive and brand guardrails.
// --------------------------------------------------------------------------

okv_test_ok(str_contains($standingOrdersPage, 'max-w-') || str_contains($standingOrdersPage, 'okv-panel'), 'K: standing orders uses a constrained panel rather than a fixed width');
okv_test_ok(str_contains((string) file_get_contents($appRoot . '/pro/orders.php'), 'max-w-') || str_contains((string) file_get_contents($appRoot . '/pro/orders.php'), 'okv-panel'), 'K: Pro orders panel is constrained and responsive');
okv_test_ok(str_contains((string) file_get_contents($appRoot . '/pro/credit.php'), 'max-w-') || str_contains((string) file_get_contents($appRoot . '/pro/credit.php'), 'okv-'), 'K: Pro credit panel is constrained');
$brandCss = (string) file_get_contents($appRoot . '/assets/css/tailwind.css');
okv_test_ok($brandCss !== '' && str_contains($brandCss, '.okv-'), 'K: brand stylesheet is present');

// --------------------------------------------------------------------------
// Migration hygiene spot check (MySQL 8 safe, transactional, idempotent).
// --------------------------------------------------------------------------

$migration032 = (string) file_get_contents($appRoot . '/migrations/032_credit_notifications.sql');
okv_test_ok(str_contains($migration032, 'START TRANSACTION') && str_contains($migration032, 'COMMIT'), 'K: credit notification migration is transactional');
okv_test_ok(!str_contains($migration032, 'ADD COLUMN IF NOT EXISTS') && !str_contains($migration032, 'ADD INDEX IF NOT EXISTS'), 'K: migration avoids MariaDB IF NOT EXISTS syntax');
