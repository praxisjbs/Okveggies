<?php
/**
 * The wallet's pure rules and the wiring around it, checked without a database.
 * The locked, transactional paths are in wallet_db_test.php.
 */
require_once dirname(__DIR__, 2) . '/includes/classes/Csrf.php';
require_once dirname(__DIR__, 2) . '/includes/components/shop/pay_sheet.php';
require_once dirname(__DIR__, 2) . '/includes/components/shop/wallet_panel.php';

$root = dirname(__DIR__, 2);

// --- who may be credited, and for what ---------------------------------------------------------
okv_test_ok(Wallet::creditIsValid(7, 1, 'goodwill', 'k'), 'a well formed credit is valid');
okv_test_ok(!Wallet::creditIsValid(0, 100, 'goodwill', 'k'), 'a credit needs a customer');
okv_test_ok(!Wallet::creditIsValid(7, 0, 'goodwill', 'k'), 'a credit of nothing is refused');
okv_test_ok(!Wallet::creditIsValid(7, -5, 'goodwill', 'k'), 'a negative credit is refused: money only enters by credit');
okv_test_ok(!Wallet::creditIsValid(7, 100, 'top_up', 'k'), 'there is no top up reason');
okv_test_ok(!Wallet::creditIsValid(7, 100, 'goodwill', ''), 'a credit needs an idempotency key');
okv_test_ok(!Wallet::creditIsValid(7, 100, 'goodwill', str_repeat('k', 151)), 'and the key has to fit the column');
foreach (['complaint', 'cancellation', 'shortage', 'goodwill'] as $reason) {
    okv_test_ok(Wallet::reasonLabel($reason) !== 'Credit from OK Veggies', "$reason has its own label");
}
okv_test_eq('Credit from OK Veggies', Wallet::reasonLabel('anything_else'), 'an unknown reason still reads plainly');

// --- how much of an order the wallet pays --------------------------------------------------------
okv_test_eq(0, Wallet::planSpend(0, 500), 'an empty wallet spends nothing');
okv_test_eq(0, Wallet::planSpend(500, 0), 'nothing owed, nothing spent');
okv_test_eq(0, Wallet::planSpend(-1, 500), 'a negative balance spends nothing');
okv_test_eq(300, Wallet::planSpend(300, 500), 'a small wallet is spent in full');
okv_test_eq(500, Wallet::planSpend(900, 500), 'a big wallet pays only what is owed');
okv_test_eq(500, Wallet::planSpend(500, 500), 'an exact wallet pays exactly');

// --- what the row that was owed becomes ---------------------------------------------------------
okv_test_eq(['expected' => 0, 'status' => 'void'], Wallet::reducedRow(500, 0, 500), 'a row nothing is left on is voided, never deleted');
okv_test_eq(['expected' => 200, 'status' => null], Wallet::reducedRow(500, 0, 300), 'a row still owed keeps its status and shrinks');
okv_test_eq(['expected' => 100, 'status' => 'paid'], Wallet::reducedRow(300, 100, 200), 'a row that holds money is never reduced below it');
okv_test_eq(500, 200 + Wallet::reducedRow(500, 0, 200)['expected'], 'the wallet part and the row that is left add up to what was owed');

// --- numbering -----------------------------------------------------------------------------------
okv_test_eq('CN26001', OrderNumber::format('CN', 26, 1), 'a credit note number is CN, the year and the sequence');
okv_test_eq('CN261000', OrderNumber::format('CN', 26, 1000), 'and grows past three digits');

// --- every wallet refusal has plain words ---------------------------------------------------------
foreach (['not_found', 'order_cancelled', 'payment_in_progress', 'nothing_due', 'wallet_empty', 'no_wallet'] as $code) {
    okv_test_ok(Wallet::message($code) !== Wallet::message('never_a_code'), "$code has its own message");
}
okv_test_ok(!str_contains(Wallet::message('never_a_code'), "\u{2014}"), 'the fallback message has no em dash');

// --- the Pay sheet draws the wallet as the first, primary button -----------------------------------
$wallet = [
    'key' => 'wallet', 'label' => 'Use ₦3,000 from wallet', 'icon' => 'wallet', 'primary' => true,
    'action' => 'use_wallet', 'endpoint' => '/api/v1/payments.php', 'order_id' => 9, 'fields' => ['then' => 'card'],
    'amount_subunit' => 300000, 'note' => '₦5,000 more by card', 'info' => 'Spent first.',
];
$card = [
    'key' => 'paystack', 'label' => 'Pay ₦8,000 by card', 'icon' => 'card', 'primary' => false,
    'action' => 'initialise', 'endpoint' => '/api/v1/payments.php', 'payment_id' => 5,
    'amount_subunit' => 800000, 'info' => 'Card, bank transfer or USSD on Paystack.',
];
$render = static function (callable $draw): string {
    ob_start();
    $draw();
    return (string) ob_get_clean();
};
$sheet = $render(static fn() => okv_pay_action(['id' => 9, 'order_number' => 'OKV26000009'], [$wallet, $card]));
okv_test_ok(str_contains($sheet, 'name="action" value="use_wallet"') && str_contains($sheet, 'name="order_id" value="9"'), 'the wallet button posts its own action for this order');
okv_test_ok(str_contains($sheet, 'name="then" value="card"'), 'a part payment carries the instruction to finish by card');
okv_test_ok(strpos($sheet, 'use_wallet') < strpos($sheet, 'name="action" value="initialise"'), 'the wallet comes before the card button');
okv_test_ok(strpos($sheet, 'okv-btn ') < strpos($sheet, 'okv-btn-outline'), 'the wallet is the primary button and the card the outlined alternative');
okv_test_ok(str_contains($sheet, 'Pay ₦8,000 by card'), 'the card button says it is by card');
okv_test_ok(str_contains($sheet, '₦5,000 more by card'), 'and the wallet says what is left for the card');
$whole = $render(static fn() => okv_pay_action(['id' => 9, 'order_number' => 'X'], [['fields' => []] + $wallet]));
okv_test_ok(!str_contains($whole, 'name="then"'), 'a wallet that covers everything asks for no card step');
$hostile = $render(static fn() => okv_pay_action(['id' => 9, 'order_number' => 'X'], [['fields' => ['"><script>x</script>' => '"><b>']] + $wallet]));
okv_test_ok(!str_contains($hostile, '<script>x</script>') && !str_contains($hostile, '"><b>'), 'extra form fields are escaped');

// --- the wallet screen -------------------------------------------------------------------------------
$empty = $render(static fn() => okv_wallet_panel(['balance_subunit' => 0, 'entries' => []]));
okv_test_ok(str_contains($empty, '₦0'), 'an empty wallet shows a zero balance');
okv_test_ok(str_contains($empty, 'Nothing here yet'), 'and an honest empty state');
okv_test_ok(!str_contains($empty, 'Spend it in the shop'), 'no spend button when there is nothing to spend');
$full = $render(static fn() => okv_wallet_panel(['balance_subunit' => 250000, 'entries' => [
    ['label' => 'Make It Right credit', 'amount_subunit' => 250000, 'balance_after_subunit' => 250000, 'created_at' => '2026-09-29 10:00:00',
     'order_id' => 3, 'order_number' => 'OKV26003', 'credit_note_id' => 12, 'credit_note_number' => 'CN26012'],
    ['label' => '<b>Paid toward order</b>', 'amount_subunit' => -100000, 'balance_after_subunit' => 150000, 'created_at' => '2026-09-30 10:00:00',
     'order_id' => 4, 'order_number' => 'OKV26004', 'credit_note_id' => null, 'credit_note_number' => null],
]]));
okv_test_ok(str_contains($full, '₦2,500') && str_contains($full, '+₦2,500') && str_contains($full, '-₦1,000'), 'credits read as plus and spends as minus');
okv_test_ok(str_contains($full, '/public/documents/credit_note.php?id=12') && str_contains($full, 'CN26012'), 'a credit carries a button to its credit note');
okv_test_ok(substr_count($full, 'credit_note.php') === 1, 'and a spend does not');
okv_test_ok(!str_contains($full, '<b>Paid toward order</b>'), 'labels are escaped');
okv_test_ok(str_contains($full, 'Spend it in the shop'), 'a wallet with money offers to spend it');
okv_test_ok(str_contains($full, 'aria-label="More about your wallet"'), 'the long explanation sits behind an info control');

// --- what a customer is told when a cancelled order's money goes to the wallet -----------------------
$onlyWallet = Notifications::cancellationMoneyLine(['refund_subunit' => 1500000, 'wallet_subunit' => 1500000, 'refund_status' => 'processed', 'forfeit_subunit' => 0, 'manual_subunit' => 0]);
okv_test_ok(str_contains($onlyWallet, '₦15,000 has been added to your OK Veggies wallet'), 'a wallet only refund says it is in the wallet');
okv_test_ok(!str_contains($onlyWallet, 'sent') && !str_contains($onlyWallet, 'account you paid from'), 'and does not promise a bank transfer that is not coming');
$mixedLine = Notifications::cancellationMoneyLine(['refund_subunit' => 2000000, 'wallet_subunit' => 500000, 'refund_status' => 'processed', 'forfeit_subunit' => 0, 'manual_subunit' => 0]);
okv_test_ok(str_contains($mixedLine, '₦5,000 has been added to your OK Veggies wallet') && str_contains($mixedLine, 'We have sent ₦15,000 back to you.'), 'a mixed refund names each part once, with the card part reduced by the wallet part');

// --- the endpoints guard themselves ---------------------------------------------------------------------
$payments = (string) file_get_contents($root . '/api/v1/payments.php');
$block = substr($payments, (int) strpos($payments, "if (\$action === 'use_wallet')"), 3400);
okv_test_ok(str_contains($block, 'okv_is_post()'), 'use_wallet accepts POST only');
okv_test_ok(str_contains($block, 'Customer::requireLoginApi()'), 'and needs a signed-in customer: a guest has no wallet');
okv_test_ok(str_contains($block, 'Csrf::validate()'), 'and a CSRF token');
okv_test_ok(str_contains($block, 'RateLimiter::hit('), 'and is rate limited');
okv_test_ok(str_contains($block, 'user_id = :user'), 'and only acts on the caller\'s own order');
okv_test_ok(str_contains($block, 'Wallet::payOrder($orderId, $userId, $userId)'), 'and the caller is the only one who can spend their wallet');

$customers = (string) file_get_contents($root . '/api/v1/customers.php');
$block = substr($customers, (int) strpos($customers, "if (\$action === 'give_wallet_credit')"), 2600);
okv_test_ok(str_contains($block, "Rbac::requirePermission('wallet.credit')"), 'goodwill credit needs its own permission');
okv_test_ok(str_contains($block, "'goodwill:' . \$customerId . ':' . \$token"), 'and is idempotent on a per form token, so a double submit credits once');
okv_test_ok(str_contains($block, '$maximum'), 'and is capped');

$note = (string) file_get_contents($root . '/public/documents/credit_note.php');
okv_test_ok(str_contains($note, '(int) Customer::id() === (int) $note[\'user_id\']'), 'a credit note opens for the customer it was issued to');
okv_test_ok(str_contains($note, "Rbac::can('wallet.view')"), 'and for staff who may see wallets');
okv_test_ok(!str_contains($note, "okv_input('token'") && !str_contains($note, 'OrderTrail'), 'and never through a token link');

$checkout = (string) file_get_contents($root . '/api/v1/checkout.php');
okv_test_ok(str_contains($checkout, "!\$guest && (string) okv_input('use_wallet', '') === '1'"), 'checkout spends the wallet only for a signed-in customer who left the box ticked');
okv_test_ok(str_contains($checkout, "['pay_in_full', 'deposit']"), 'and only on a pay in full or deposit order');

$migration = (string) file_get_contents($root . '/migrations/067_wallet_and_credit_notes.sql');
okv_test_ok(str_contains($migration, 'balance_subunit` BIGINT UNSIGNED'), 'the balance is unsigned, so the database refuses a negative one');
okv_test_ok(str_contains($migration, 'UNIQUE KEY `uq_wallet_entries_source_key`'), 'the ledger is idempotent on its source key');
okv_test_ok(!preg_match('/ALTER TABLE[^;]*ADD COLUMN IF NOT EXISTS/i', $migration), 'the migration is safe on MySQL 8');
okv_test_ok(!str_contains($migration, "\u{2014}"), 'and carries no em dash');

$ledgerClass = (string) file_get_contents($root . '/includes/classes/Wallet.php');
okv_test_ok(!preg_match('/UPDATE\s+wallet_entries|DELETE\s+FROM\s+wallet_entries/i', $ledgerClass), 'no code path edits or deletes a ledger row');
okv_test_ok(!preg_match('/UPDATE\s+credit_notes|DELETE\s+FROM\s+credit_notes/i', $ledgerClass), 'nor a credit note');
okv_test_ok(substr_count($ledgerClass, 'FOR UPDATE') >= 3, 'spending locks the order, the payments and the wallet');
