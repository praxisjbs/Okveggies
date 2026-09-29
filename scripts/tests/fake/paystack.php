<?php
/**
 * scripts/tests/fake/paystack.php
 * -----------------------------------------------------------------------------
 * OK Veggies. A stand-in Paystack, for tests only.
 *
 * The refund path is the one piece of the money flow that cannot be proved
 * against the real gateway without moving real money out of a real account. So
 * the tests point PAYSTACK_BASE_URL at this file and drive the whole path:
 * Refunds::request calls POST /refund and gets a Paystack-shaped answer back.
 *
 * It answers only what the client actually asks for (starting a payment, a
 * refund, and verification), in the shape the client
 * actually parses, and it can be told to refuse so the failure branch is
 * exercised as well as the happy one. It is never reachable in production:
 * Paystack::base() ignores the override unless the secret key is a test key,
 * and this file lives under scripts/, which is not served.
 *
 * Run it with:  php -S 127.0.0.1:8124 scripts/tests/fake/paystack.php
 * -----------------------------------------------------------------------------
 */

header('Content-Type: application/json');

$path   = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$raw    = file_get_contents('php://input') ?: '';
$body   = json_decode($raw, true) ?: [];

/** A refusal is requested with a header, so a test can ask for one without a second server. */
$refuse = strtolower((string) ($_SERVER['HTTP_X_FAKE_REFUSE'] ?? '')) === 'yes';

/** Everything this fake has been asked, so a test can assert on the call itself. */
$logPath = (string) (getenv('FAKE_PAYSTACK_LOG') ?: sys_get_temp_dir() . '/okv-fake-paystack.log');
file_put_contents(
    $logPath,
    json_encode(['method' => $method, 'path' => $path, 'body' => $body]) . "\n",
    FILE_APPEND
);

if ($method === 'POST' && $path === '/transaction/initialize') {
    // Starting a payment: the shape Payments::beginCharge parses. The customer
    // is sent to /pay/<reference> below, a page that stands in for Paystack's own.
    if ($refuse) {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'Invalid Amount Sent']);
        exit;
    }
    $reference = (string) ($body['reference'] ?? bin2hex(random_bytes(6)));
    echo json_encode([
        'status'  => true,
        'message' => 'Authorization URL created',
        'data'    => [
            'authorization_url' => 'http://127.0.0.1:8124/pay/' . rawurlencode($reference),
            'access_code'       => 'ac_' . substr(md5($reference), 0, 12),
            'reference'         => $reference,
        ],
    ]);
    exit;
}

if ($method === 'GET' && str_starts_with($path, '/pay/')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><title>Paystack stand-in</title><h1>Paystack test checkout</h1><p>Reference '
        . htmlspecialchars(rawurldecode(substr($path, 5)), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

if ($method === 'POST' && $path === '/refund') {
    if ($refuse) {
        http_response_code(400);
        echo json_encode(['status' => false, 'message' => 'Transaction has been fully refunded']);
        exit;
    }
    $amount = (int) ($body['amount'] ?? 0);
    echo json_encode([
        'status'  => true,
        'message' => 'Refund has been queued for processing',
        'data'    => [
            'id'          => 900000 + (int) (microtime(true) * 100) % 99999,
            'amount'      => $amount,
            'currency'    => (string) ($body['currency'] ?? 'NGN'),
            'status'      => 'pending',
            'transaction' => ['reference' => (string) ($body['transaction'] ?? '')],
        ],
    ]);
    exit;
}

if ($method === 'GET' && str_starts_with($path, '/refund/')) {
    echo json_encode([
        'status' => true,
        'data'   => ['id' => (int) substr($path, 8), 'status' => 'processed'],
    ]);
    exit;
}

if ($method === 'GET' && str_starts_with($path, '/transaction/verify/')) {
    echo json_encode([
        'status' => true,
        'data'   => [
            'status'    => 'success',
            'reference' => rawurldecode(substr($path, 20)),
            'amount'    => 1000000,
            'currency'  => 'NGN',
            'domain'    => 'test',
        ],
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['status' => false, 'message' => 'This stand-in does not answer ' . $method . ' ' . $path]);
