<?php
/**
 * An item that could not be sourced: the pure rules and the wiring around them,
 * checked without a database. The locked, transactional paths (marking a line
 * short, the customer's choice, the refund queue, the wallet cash out) are in
 * shortages_db_test.php.
 */
require_once dirname(__DIR__, 2) . '/includes/classes/Csrf.php';
require_once dirname(__DIR__, 2) . '/includes/components/shop/wallet_panel.php';

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

// --- what quantity can be marked short ---------------------------------------------------------
okv_test_eq('', Shortages::quantityError('2', '5'), 'less than the line holds is fine');
okv_test_eq('', Shortages::quantityError('5', '5'), 'all of the line is fine');
okv_test_eq('', Shortages::quantityError('0.5', '2.000'), 'a half is fine on a weighed line');
okv_test_eq('', Shortages::quantityError('0.001', '2'), 'three decimals are the finest cut');
okv_test_eq('bad_quantity', Shortages::quantityError('0', '5'), 'nothing is not a shortage');
okv_test_eq('bad_quantity', Shortages::quantityError('-1', '5'), 'a negative quantity is refused');
okv_test_eq('bad_quantity', Shortages::quantityError('5.001', '5'), 'more than the line holds is refused');
okv_test_eq('bad_quantity', Shortages::quantityError('1.2345', '5'), 'four decimals are refused');
okv_test_eq('bad_quantity', Shortages::quantityError('abc', '5'), 'words are refused');
okv_test_eq('bad_quantity', Shortages::quantityError('', '5'), 'an empty box is refused');
okv_test_eq('bad_quantity', Shortages::quantityError('1e3', '5000'), 'scientific notation is refused');

// --- what a missing quantity is worth ---------------------------------------------------------
okv_test_eq(300000, Shortages::shortAmount('2', '5', 150000, 750000), 'two of five at 1500 each is 3000');
okv_test_eq(750000, Shortages::shortAmount('5', '5', 150000, 750000), 'the whole line is exactly what the line was worth');
okv_test_eq(333, Shortages::shortAmount('1', '3', 333, 999), 'a part is quantity times the unit price');
okv_test_eq(1000, Shortages::shortAmount('3', '3', 333, 1000), 'the last of a line takes the rounding remainder, so no kobo is stranded');
okv_test_eq(100, Shortages::shortAmount('1', '2', 5000, 100), 'a shortage is never worth more than the line');
okv_test_eq(75000, Shortages::shortAmount('0.5', '2.000', 150000, 300000), 'half a kilo is half the price');

// --- how the value comes off what is still unpaid ----------------------------------------------
$deposit = ['id' => 10, 'status' => 'paid', 'expected_amount_subunit' => 500, 'paid_amount_subunit' => 500];
$balance = ['id' => 11, 'status' => 'pending', 'expected_amount_subunit' => 300, 'paid_amount_subunit' => 0];

$plan = Shortages::plan([$deposit, $balance], 200);
okv_test_eq(200, $plan['reduced'], 'a shortage inside the unpaid part comes entirely off it');
okv_test_eq(0, $plan['excess'], 'and nobody is owed anything back');
okv_test_eq(100, $plan['reductions'][0]['expected_after'], 'the balance shrinks');
okv_test_eq('pending', $plan['reductions'][0]['status_after'], 'and is still unpaid');

$plan = Shortages::plan([$deposit, $balance], 300);
okv_test_eq('void', $plan['reductions'][0]['status_after'], 'a row with nothing left and nothing paid is voided, never deleted');
okv_test_eq(0, $plan['reductions'][0]['expected_after'], 'its expected amount is zero');
okv_test_eq(0, $plan['excess'], 'an exact fit leaves no excess');

$plan = Shortages::plan([$deposit, $balance], 450);
okv_test_eq(300, $plan['reduced'], 'the unpaid part absorbs only what it holds');
okv_test_eq(150, $plan['excess'], 'the rest is what the customer has overpaid');
okv_test_eq(300 + 150, $plan['reduced'] + $plan['excess'], 'the two always add up to the shortage');

$plan = Shortages::plan([$deposit], 100);
okv_test_eq(0, $plan['reduced'], 'a paid row is never reduced');
okv_test_eq(100, $plan['excess'], 'so all of it is owed back');
okv_test_eq([], $plan['reductions'], 'and nothing is written to the paid row');

$partPaid = ['id' => 12, 'status' => 'part_paid', 'expected_amount_subunit' => 400, 'paid_amount_subunit' => 150];
$plan = Shortages::plan([$partPaid], 400);
okv_test_eq(250, $plan['reduced'], 'a row that holds money never drops below it');
okv_test_eq(150, $plan['excess'], 'what it holds is owed back');
okv_test_eq('paid', $plan['reductions'][0]['status_after'], 'and what it holds is now all it owed, so it is paid');

$older = ['id' => 5, 'status' => 'pending', 'expected_amount_subunit' => 100, 'paid_amount_subunit' => 0];
$newer = ['id' => 6, 'status' => 'pending', 'expected_amount_subunit' => 100, 'paid_amount_subunit' => 0];
$plan = Shortages::plan([$older, $newer], 60);
okv_test_eq(6, $plan['reductions'][0]['payment_id'], 'the newest unpaid row is reduced first');
okv_test_eq(1, count($plan['reductions']), 'and only as many rows as it takes');
okv_test_eq(['reductions' => [], 'reduced' => 0, 'excess' => 0], Shortages::plan([$older], 0), 'nothing off is nothing done');

$void = ['id' => 7, 'status' => 'void', 'expected_amount_subunit' => 0, 'paid_amount_subunit' => 0];
okv_test_eq(80, Shortages::plan([$void], 80)['excess'], 'a voided row absorbs nothing');

// --- the words ----------------------------------------------------------------------------------
okv_test_eq('2 kg Tomatoes', Shortages::itemLine('2.000', 'kg', 'Tomatoes'), 'a line reads as quantity, unit and name');
okv_test_eq('0.5 kg Tomatoes', Shortages::itemLine('0.500', 'kg', 'Tomatoes'), 'a half keeps its decimal');
okv_test_eq('3 bunch Ugu', Shortages::itemLine('3', 'bunch', 'Ugu'), 'a whole number drops its zeros');
foreach (['not_found', 'not_sourcing', 'already_short', 'bad_quantity', 'payment_in_progress', 'receipt_waiting',
          'already_decided', 'not_awaiting', 'no_account', 'bad_bank', 'cannot_undo', 'bad_choice'] as $code) {
    okv_test_ok(Shortages::message($code) !== Shortages::message('never_a_code'), "$code has its own message");
}
okv_test_ok(!str_contains(Shortages::message('never_a_code'), "\u{2014}"), 'the fallback message has no em dash');
$labels = [
    Shortages::statusLabel(Shortages::STATUS_AWAITING),
    Shortages::statusLabel(Shortages::STATUS_REFUND_PENDING),
    Shortages::statusLabel(Shortages::STATUS_WITHDRAWN),
    Shortages::statusLabel(Shortages::STATUS_SETTLED, Shortages::RESOLUTION_WALLET),
    Shortages::statusLabel(Shortages::STATUS_SETTLED, Shortages::RESOLUTION_BANK),
    Shortages::statusLabel(Shortages::STATUS_SETTLED, Shortages::RESOLUTION_REDUCED),
];
okv_test_eq(count($labels), count(array_unique($labels)), 'every state of a shortage reads differently to staff');
okv_test_eq(['pending', 'confirmed'], Shortages::STAGES, 'an item can be marked short only before the order is packed');

// --- the bank details a manual refund needs -----------------------------------------------------
$good = ManualRefunds::validateBank(['bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Ada Obi']);
okv_test_ok($good['ok'], 'well formed bank details pass');
okv_test_eq('0123456789', $good['clean']['account_number'], 'and the number is kept as ten digits');

$spaced = ManualRefunds::validateBank(['bank_name' => '  First   Bank ', 'account_number' => '0123 456-789', 'account_name' => "  Ada   Obi  "]);
okv_test_ok($spaced['ok'], 'spaces and dashes in the number are forgiven');
okv_test_eq('0123456789', $spaced['clean']['account_number'], 'and removed');
okv_test_eq('First Bank', $spaced['clean']['bank_name'], 'a bank name is tidied');
okv_test_eq('Ada Obi', $spaced['clean']['account_name'], 'and so is an account name');

foreach (['012345678', '01234567890', 'abcdefghij', '', '0123 4567 8'] as $number) {
    $bad = ManualRefunds::validateBank(['bank_name' => 'GTBank', 'account_number' => $number, 'account_name' => 'Ada Obi']);
    okv_test_ok(!$bad['ok'] && isset($bad['errors']['account_number']), "'$number' is not an account number");
}
okv_test_ok(!ManualRefunds::validateBank(['bank_name' => '', 'account_number' => '0123456789', 'account_name' => 'Ada'])['ok'], 'a bank is needed');
okv_test_ok(!ManualRefunds::validateBank(['bank_name' => 'G', 'account_number' => '0123456789', 'account_name' => 'Ada'])['ok'], 'one letter is not a bank');
okv_test_ok(!ManualRefunds::validateBank(['bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => ''])['ok'], 'a name on the account is needed');
okv_test_ok(!ManualRefunds::validateBank(['bank_name' => '<script>', 'account_number' => '0123456789', 'account_name' => 'Ada'])['ok'], 'markup is not a bank name');
okv_test_ok(!ManualRefunds::validateBank(['bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => '<b>Ada</b>'])['ok'], 'markup is not an account name');
okv_test_ok(!ManualRefunds::validateBank(['bank_name' => str_repeat('a', 101), 'account_number' => '0123456789', 'account_name' => 'Ada'])['ok'], 'a bank name has to fit the column');
okv_test_ok(!ManualRefunds::validateBank(['bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => str_repeat('a', 151)])['ok'], 'so does an account name');
okv_test_ok(ManualRefunds::validateBank(['bank_name' => "Access Bank (Diamond)", 'account_number' => '0123456789', 'account_name' => "Ada O'Brien-Smith"])['ok'], 'brackets, apostrophes and hyphens are ordinary in names');
okv_test_ok(ManualRefunds::validateBank(['bank_name' => 'Zenith', 'account_number' => '0123456789', 'account_name' => 'Chidi Ọkafor'])['ok'], 'accented letters are ordinary in names');
okv_test_ok(!ManualRefunds::validateBank([])['ok'], 'nothing at all is refused');

okv_test_eq('******6789', ManualRefunds::maskAccount('0123456789'), 'all but the last four digits are hidden');
okv_test_eq('***', ManualRefunds::maskAccount('123'), 'a short number is hidden whole');
okv_test_eq('', ManualRefunds::maskAccount(''), 'nothing masks to nothing');
$line = ManualRefunds::bankLine(['bank_name' => 'GTBank', 'account_number' => '0123456789', 'account_name' => 'Ada Obi']);
okv_test_ok(str_contains($line, '6789') && !str_contains($line, '0123456789'), 'the email line never carries the whole account number');
okv_test_ok(str_contains($line, 'GTBank') && str_contains($line, 'Ada Obi'), 'but names the bank and the account holder');
okv_test_ok(ManualRefunds::kindLabel(ManualRefunds::KIND_CASHOUT) !== ManualRefunds::kindLabel(ManualRefunds::KIND_SHORTAGE), 'staff can tell the two kinds of refund apart');
foreach (['insufficient_balance', 'bad_amount', 'not_found', 'not_requested', 'reference_required', 'reason_required', 'bad_token'] as $code) {
    okv_test_ok(ManualRefunds::message($code) !== ManualRefunds::message('never_a_code'), "$code has its own refund message");
}
okv_test_eq('RF26001', OrderNumber::format('RF', 26, 1), 'a manual refund number is RF, the year and the sequence');

// --- the wiring: nothing here may be reachable without its guard ---------------------------------
$api = $read('api/v1/shortages.php');
okv_test_ok(str_contains($api, 'Csrf::validate()') && str_contains($api, 'okv_is_post()'), 'the controller is POST only and checks CSRF');
okv_test_eq(3, substr_count($api, "Rbac::requirePermission('orders.shortage.record')") - 0, 'record, choose for the customer and undo each check the staff permission');
okv_test_ok(str_contains($api, 'RateLimiter::hit'), 'a customer choice is rate limited');
okv_test_ok(str_contains($api, 'Shortages::byToken') && str_contains($api, 'Shortages::forOwner'), 'a customer proves it is theirs by token or by owning the order');
okv_test_ok(!str_contains($api, 'Rbac::requirePermission(\'payments.refund\')'), 'paying a refund is not in this controller');

$payments = $read('api/v1/payments.php');
okv_test_ok(preg_match("/'mark_refund_paid'.{0,200}payments_staff_guard\('payments\.refund'\)/s", $payments) === 1, 'marking a refund paid needs the refund permission');
okv_test_ok(preg_match("/'cancel_manual_refund'.{0,200}payments_staff_guard\('payments\.refund'\)/s", $payments) === 1, 'so does cancelling one');
okv_test_ok(str_contains($payments, "okv_input('confirmed'") && str_contains($payments, 'not_confirmed'), 'marking a refund paid needs the confirmation, server side');
okv_test_ok(preg_match("/'request_cashout'.{0,400}Customer::requireLoginApi\(\)/s", $payments) === 1, 'only a signed-in customer can ask for a cash out');
okv_test_ok(str_contains($payments, "RateLimiter::hit('wallet_cashout:'"), 'and only a few times an hour');

$perms = $read('includes/config/permissions.php');
okv_test_ok(str_contains($perms, "'orders.shortage.record'"), 'the shortage permission is in the catalogue');

$migration = $read('migrations/068_out_of_stock_and_manual_refunds.sql');
okv_test_ok(str_contains($migration, 'CREATE TABLE IF NOT EXISTS `order_shortages`') && str_contains($migration, 'CREATE TABLE IF NOT EXISTS `manual_refunds`'), 'the migration creates both tables idempotently');
okv_test_ok(!str_contains($migration, 'ADD COLUMN IF NOT EXISTS'), 'and uses no syntax MySQL 8 refuses');
okv_test_ok(str_contains($migration, 'uq_manual_refunds_shortage') && str_contains($migration, 'uq_manual_refunds_wallet_entry'), 'one shortage and one wallet entry can only ever raise one refund');
okv_test_ok(!str_contains($migration, "\u{2014}"), 'the migration has no em dash');

$cancel = $read('includes/classes/OrderCancellation.php');
okv_test_ok(substr_count($cancel, 'Shortages::returnedSubunit') >= 2, 'cancelling an order does not return a shortage refund a second time');

foreach (['DeliveryManifest', 'OrderDocument', 'OrderTrail'] as $class) {
    okv_test_ok(str_contains($read("includes/classes/$class.php"), 'quantity > 0'), "$class leaves out a line that is wholly out of stock");
}

$orders = $read('admin/orders.php');
okv_test_ok(str_contains($orders, "Rbac::can('orders.shortage.record')") && str_contains($orders, '$canShort'), 'the order screen offers "Mark out of stock" only to those allowed');
okv_test_ok(substr_count($orders, '/api/v1/shortages.php') >= 3, 'and its forms post to the shortage controller');

$paymentsPage = $read('admin/payments.php');
okv_test_ok(str_contains($paymentsPage, 'id="refunds-heading"'), 'the Payments screen has the Refunds to pay panel the email links to');
okv_test_ok(str_contains($paymentsPage, 'ManualRefunds::maskAccount') && str_contains($paymentsPage, '$canRefund'), 'and shows the whole account number only to those who can pay it');

// --- the customer page and the wallet's cash out ----------------------------------------------------
$page = $read('public/shortage.php');
okv_test_ok(str_contains($page, 'noindex'), 'the choice page is kept out of search');
okv_test_ok(str_contains($page, "name=\"choice\" value=\"wallet\"") && str_contains($page, "name=\"choice\" value=\"bank\""), 'the page offers the wallet and the bank, and nothing else');
okv_test_ok(substr_count($page, 'Csrf::field()') >= 2, 'both forms carry a CSRF token');
okv_test_ok(str_contains($page, "Nothing happens until you choose"), 'it says plainly that nothing is decided for them');
okv_test_ok(!str_contains($page, "\u{2014}"), 'and has no em dash');

$render = static function (callable $draw): string {
    ob_start();
    $draw();
    return (string) ob_get_clean();
};
$withMoney = $render(static fn() => okv_wallet_panel(['balance_subunit' => 500000, 'entries' => [], 'refunds' => [
    ['id' => 1, 'refund_number' => 'RF26001', 'kind' => 'wallet_cashout', 'amount_subunit' => 100000, 'bank_name' => 'GTBank', 'account_number' => '0123456789', 'status' => 'requested', 'created_at' => '2026-09-30 10:00:00', 'paid_at' => null],
    ['id' => 2, 'refund_number' => 'RF26002', 'kind' => 'shortage', 'amount_subunit' => 50000, 'bank_name' => 'Access', 'account_number' => '9876543210', 'status' => 'paid', 'created_at' => '2026-09-29 10:00:00', 'paid_at' => '2026-09-30 09:00:00'],
]]));
okv_test_ok(str_contains($withMoney, 'name="action" value="request_cashout"'), 'a wallet with money offers to ask for it back');
okv_test_ok(str_contains($withMoney, 'name="cashout_token"'), 'with a token, so a double tap asks once');
okv_test_ok(str_contains($withMoney, 'name="bank_name"') && str_contains($withMoney, 'name="account_number"') && str_contains($withMoney, 'name="account_name"'), 'and the three bank details');
okv_test_ok(!str_contains($withMoney, '0123456789') && !str_contains($withMoney, '9876543210'), 'the list of refunds never prints a whole account number');
okv_test_ok(str_contains($withMoney, '6789') && str_contains($withMoney, '3210'), 'it shows the last four so they can recognise the account');
okv_test_ok(str_contains($withMoney, 'Waiting to be sent') && str_contains($withMoney, 'Sent'), 'and says which refunds have gone and which are waiting');

$noMoney = $render(static fn() => okv_wallet_panel(['balance_subunit' => 0, 'entries' => [], 'refunds' => []]));
okv_test_ok(!str_contains($noMoney, 'name="action" value="request_cashout"'), 'an empty wallet has nothing to ask for');
