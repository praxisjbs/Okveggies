<?php
/**
 * scripts/tests/PaymentsTest.php
 * The money path. Every assertion here is guarding real naira.
 *
 * The figures in the amount tests are Paystack's own documented sample, where
 * amount (40333) is requested_amount (30050) plus fees (10283). Crediting the
 * wrong field over-credits every order by the fee, so that case is pinned first.
 */

// -----------------------------------------------------------------------------
// The credit rule. This is the one that matters most.
// -----------------------------------------------------------------------------
$paystackSample = [
    'id'               => 4099260516,
    'domain'           => 'test',
    'status'           => 'success',
    'reference'        => 're4lyvq3s3',
    'amount'           => 40333,
    'fees'             => 10283,
    'requested_amount' => 30050,
    'currency'         => 'NGN',
    'channel'          => 'card',
];

okv_test_eq(30050, Payments::creditableAmount($paystackSample), 'credits requested_amount, the goods price, not the gross charge');
okv_test_eq(10283, Payments::providerFee($paystackSample),      'records the Paystack fee separately');
okv_test_ok(Payments::creditableAmount($paystackSample) !== (int) $paystackSample['amount'], 'the credited amount is not the gross amount when a fee is borne');

// Older or partial payloads may carry no requested_amount at all.
okv_test_eq(5000, Payments::creditableAmount(['amount' => 5000]), 'falls back to amount when requested_amount is absent');
okv_test_eq(5000, Payments::creditableAmount(['amount' => 5000, 'requested_amount' => 0]), 'falls back when requested_amount is zero');
okv_test_eq(0,    Payments::creditableAmount([]), 'an empty payload credits nothing');
okv_test_eq(0,    Payments::creditableAmount(['amount' => -900]), 'a negative amount never credits');
okv_test_eq(0,    Payments::providerFee(['fees' => -5]), 'a negative fee is clamped to zero');

// -----------------------------------------------------------------------------
// Mismatch classification
// -----------------------------------------------------------------------------
okv_test_eq('exact', Payments::mismatchKind(30050, 30050), 'equal amounts are exact');
okv_test_eq('under', Payments::mismatchKind(30000, 30050), 'short payment is under');
okv_test_eq('over',  Payments::mismatchKind(40000, 30050), 'excess payment is over');

// -----------------------------------------------------------------------------
// Payment and order status
// -----------------------------------------------------------------------------
okv_test_eq('unpaid',    Payments::paymentStatus(0, 1690000),       'nothing paid is unpaid');
okv_test_eq('part_paid', Payments::paymentStatus(507000, 1690000),  'a deposit leaves the payment part paid');
okv_test_eq('paid',      Payments::paymentStatus(1690000, 1690000), 'the full amount settles a payment');
okv_test_eq('paid',      Payments::paymentStatus(1700000, 1690000), 'an overpayment still counts as settled');

okv_test_eq('unpaid',    Payments::orderPaymentStatus(0, 1690000),       'an unpaid order');
okv_test_eq('part_paid', Payments::orderPaymentStatus(507000, 1690000),  'a deposit order is part paid');
okv_test_eq('paid',      Payments::orderPaymentStatus(1690000, 1690000), 'a fully paid order');

// -----------------------------------------------------------------------------
// Deposit and balance split, the two rows checkout writes
// -----------------------------------------------------------------------------
$orderTotal = 1690000;                                   // 16,900 naira
$deposit    = Money::deposit($orderTotal, 30);           // 30 percent
$balance    = Money::balance($orderTotal, $deposit);

okv_test_eq(507000,  $deposit, 'the deposit row expects 30 percent of the order');
okv_test_eq(1183000, $balance, 'the balance row expects the rest');
okv_test_eq($orderTotal, $deposit + $balance, 'deposit plus balance is exactly the order total, no kobo lost');

// An odd total must not lose or invent a kobo in the split.
$odd        = 1000001;
$oddDeposit = Money::deposit($odd, 30);
okv_test_eq($odd, $oddDeposit + Money::balance($odd, $oddDeposit), 'an odd total still splits exactly');

// -----------------------------------------------------------------------------
// Reference minting and Paystack's charset rule
// -----------------------------------------------------------------------------
$reference = Payments::reference('OKV26000123', 1);
okv_test_ok(Paystack::isValidReference($reference), 'a minted reference is legal for Paystack');
okv_test_ok(str_starts_with($reference, 'OKV26000123-01-'), 'the reference carries the order number and attempt');
okv_test_ok(Payments::reference('OKV26000123', 1) !== Payments::reference('OKV26000123', 1), 'two mints of the same attempt still differ');
okv_test_ok(Paystack::isValidReference(Payments::reference('OKV-26/000 123', 2)), 'an order number with stray characters still mints a legal reference');

okv_test_ok(Paystack::isValidReference('abc-123.XY=z'), 'the allowed characters are accepted');
okv_test_ok(!Paystack::isValidReference('has space'),    'a space is rejected');
okv_test_ok(!Paystack::isValidReference('slash/here'),   'a slash is rejected');
okv_test_ok(!Paystack::isValidReference('under_score'),  'an underscore is rejected, it is not in Paystack allowed set');
okv_test_ok(!Paystack::isValidReference(''),             'an empty reference is rejected');
okv_test_ok(!Paystack::isValidReference(str_repeat('a', 101)), 'an over-long reference is rejected');

// -----------------------------------------------------------------------------
// The guards that run before money moves
// -----------------------------------------------------------------------------
$good = $paystackSample;
okv_test_eq('ok', Payments::chargeIsAcceptable($good, 'test', 're4lyvq3s3')['reason'], 'a clean payload passes every guard');
okv_test_ok(Payments::chargeIsAcceptable($good, 'test', 're4lyvq3s3')['ok'], 'a clean payload is acceptable');

$notSuccess = $good; $notSuccess['status'] = 'abandoned';
okv_test_eq('not_successful', Payments::chargeIsAcceptable($notSuccess, 'test', 're4lyvq3s3')['reason'], 'an abandoned charge is refused');

$wrongRef = $good; $wrongRef['reference'] = 'someone-elses';
okv_test_eq('reference_mismatch', Payments::chargeIsAcceptable($wrongRef, 'test', 're4lyvq3s3')['reason'], 'a payload for another reference is refused');

$wrongCurrency = $good; $wrongCurrency['currency'] = 'USD';
okv_test_eq('currency_mismatch', Payments::chargeIsAcceptable($wrongCurrency, 'test', 're4lyvq3s3')['reason'], 'a foreign currency is refused');

// The guard that stops a test event crediting a live order.
okv_test_eq('domain_mismatch', Payments::chargeIsAcceptable($good, 'live', 're4lyvq3s3')['reason'], 'a test mode event cannot credit a live order');
$liveSample = $good; $liveSample['domain'] = 'live';
okv_test_eq('domain_mismatch', Payments::chargeIsAcceptable($liveSample, 'test', 're4lyvq3s3')['reason'], 'a live event cannot credit a test order');

$zero = $good; $zero['requested_amount'] = 0; $zero['amount'] = 0;
okv_test_eq('zero_amount', Payments::chargeIsAcceptable($zero, 'test', 're4lyvq3s3')['reason'], 'a zero value charge is refused');

// -----------------------------------------------------------------------------
// Webhook idempotency: the dedupe key
// -----------------------------------------------------------------------------
okv_test_eq('charge.success:4099260516', Payments::deduplicationKey('charge.success', $paystackSample), 'the dedupe key is the event plus the resource id');
okv_test_eq(
    Payments::deduplicationKey('charge.success', $paystackSample),
    Payments::deduplicationKey('charge.success', $paystackSample),
    'the same event yields the same key, which is what makes a retry a duplicate'
);
okv_test_ok(
    Payments::deduplicationKey('charge.success', $paystackSample) !== Payments::deduplicationKey('refund.processed', $paystackSample),
    'two different events on one resource are not duplicates of each other'
);
okv_test_eq('charge.success:re4lyvq3s3', Payments::deduplicationKey('charge.success', ['reference' => 're4lyvq3s3']), 'the key falls back to the reference when there is no id');

// -----------------------------------------------------------------------------
// Metadata, which Paystack returns as an object, a JSON string, or ""
// -----------------------------------------------------------------------------
okv_test_eq(['a' => 1], Payments::decodeMetadata(['a' => 1]),   'an object metadata is passed through');
okv_test_eq(['a' => 1], Payments::decodeMetadata('{"a":1}'),    'a stringified metadata is decoded');
okv_test_eq([],         Payments::decodeMetadata(''),           'the empty string metadata in Paystack own sample does not fatal');
okv_test_eq([],         Payments::decodeMetadata(null),         'a null metadata is empty');
okv_test_eq([],         Payments::decodeMetadata('not json'),   'unparseable metadata is empty, never an error');

// -----------------------------------------------------------------------------
// Webhook signature. The security boundary.
// -----------------------------------------------------------------------------
$_ENV['PAYSTACK_SECRET_KEY'] = 'sk_test_okveggies_unit_test_key';
$secret  = 'sk_test_okveggies_unit_test_key';
$rawBody = '{"event":"charge.success","data":{"id":4099260516,"reference":"re4lyvq3s3","amount":40333}}';
$valid   = hash_hmac('sha512', $rawBody, $secret);

okv_test_ok(Paystack::verifyWebhookSignature($rawBody, $valid), 'a correctly signed body is accepted');
okv_test_ok(!Paystack::verifyWebhookSignature($rawBody, ''), 'a missing signature is rejected');
okv_test_ok(!Paystack::verifyWebhookSignature($rawBody, 'deadbeef'), 'a wrong signature is rejected');
okv_test_ok(!Paystack::verifyWebhookSignature($rawBody . ' ', $valid), 'a tampered body is rejected');
okv_test_ok(!Paystack::verifyWebhookSignature($rawBody, hash_hmac('sha512', $rawBody, 'another_key')), 'a body signed with a different key is rejected');
okv_test_ok(!Paystack::verifyWebhookSignature('', $valid), 'an empty body is rejected');

// SHA512 and not SHA256. Paystack is unusual here and getting it wrong would
// reject every real event.
okv_test_ok(!Paystack::verifyWebhookSignature($rawBody, hash_hmac('sha256', $rawBody, $secret)), 'a sha256 signature is rejected, Paystack signs sha512');
okv_test_eq(128, strlen($valid), 'a Paystack signature is 128 hex characters');

// The raw body is what is signed. Re-serialising the decoded body, which is
// what Paystack own Node sample does, changes the bytes and breaks the check.
$reserialised = json_encode(json_decode($rawBody, true), JSON_PRETTY_PRINT);
okv_test_ok(!Paystack::verifyWebhookSignature($reserialised, $valid), 'a re-serialised body fails, which is why we hash php://input');

// -----------------------------------------------------------------------------
// Environment guards
// -----------------------------------------------------------------------------
okv_test_ok(Paystack::isTestMode(), 'a sk_test key reports test mode');
okv_test_eq('test', Paystack::domain(), 'test mode stamps the test domain');
$_ENV['PAYSTACK_SECRET_KEY'] = 'sk_live_okveggies_unit_test_key';
okv_test_ok(!Paystack::isTestMode(), 'a sk_live key reports live mode');
okv_test_eq('live', Paystack::domain(), 'live mode stamps the live domain');
unset($_ENV['PAYSTACK_SECRET_KEY']);

// -----------------------------------------------------------------------------
// Webhook source IPs, advisory only
// -----------------------------------------------------------------------------
okv_test_ok(Paystack::isKnownWebhookIp('52.31.139.75'),  'a documented Paystack ip is recognised');
okv_test_ok(Paystack::isKnownWebhookIp('52.49.173.169'), 'the second documented ip is recognised');
okv_test_ok(Paystack::isKnownWebhookIp('52.214.14.220'), 'the third documented ip is recognised');
okv_test_ok(!Paystack::isKnownWebhookIp('8.8.8.8'),      'an unlisted ip is flagged');
okv_test_eq(3, count(Paystack::WEBHOOK_IPS),             'there are three documented Paystack source ips');

// -----------------------------------------------------------------------------
// Channels, from the Paystack OpenAPI enum
// -----------------------------------------------------------------------------
okv_test_ok(in_array('bank_transfer', Paystack::SUPPORTED_CHANNELS, true), 'bank transfer is a supported channel');
okv_test_ok(in_array('ussd', Paystack::SUPPORTED_CHANNELS, true),          'ussd is a supported channel');
okv_test_ok(in_array('capitec_pay', Paystack::SUPPORTED_CHANNELS, true),   'the enum carries channels newer than the PRD');
okv_test_eq(10, count(Paystack::SUPPORTED_CHANNELS),                        'ten channels, matching the specification enum');
