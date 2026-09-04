<?php
/**
 * api/v1/checkout.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Checkout step persistence and the final order placement. Each
 * step saves into the session bag and moves the customer to the next step. The
 * last step places the order in one transaction. A fetch gets JSON; a plain
 * form post gets a 303 redirect, so checkout works without JavaScript.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

if (!okv_is_post()) {
    okv_error('Use POST for this action.', 405, 'method_not_allowed');
}
if (!Csrf::validate()) {
    okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
}

/** True when the caller wants JSON rather than a redirect. */
function checkout_is_fetch(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

/** Fields kept for each checkout step. Anything else in the post is ignored. */
const CHECKOUT_STEP_FIELDS = [
    'customer' => ['recipient_name', 'recipient_phone', 'email', 'address_line_1', 'address_line_2', 'city', 'state', 'landmark', 'customer_type', 'create_account'],
    'delivery' => ['delivery_date', 'delivery_zone_id'],
    'payment'  => ['payment_option'],
];

const CHECKOUT_NEXT_STEP = ['customer' => 3, 'delivery' => 4, 'payment' => 4];

/**
 * Create a light account for a guest checkout and sign them in, so the order
 * can be tracked and a delivery day chosen. Refuses when they did not consent,
 * when the details are not valid, or when the email or phone is already in use.
 */
function checkout_create_guest_account(array $customer): void
{
    if (empty($customer['create_account'])) {
        throw new DomainException('consent_required');
    }
    $email = strtolower(trim((string) ($customer['email'] ?? '')));
    $phone = Phone::normalize((string) ($customer['recipient_phone'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === null) {
        throw new DomainException('bad_customer');
    }
    if (Database::one('SELECT id FROM users WHERE email = :email OR phone = :phone', [':email' => $email, ':phone' => $phone])) {
        throw new DomainException('account_exists');
    }

    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status)
         VALUES (:first, \'\', :email, :phone, :password, \'household\', \'active\')',
        [
            ':first'    => trim((string) ($customer['recipient_name'] ?? '')),
            ':email'    => $email,
            ':phone'    => $phone,
            ':password' => Password::hash(bin2hex(random_bytes(24))),
        ]
    );
    $user = Database::one(
        'SELECT id, user_type, email_verified_at, first_name FROM users WHERE id = :id',
        [':id' => (int) Database::getInstance()->getConnection()->lastInsertId()]
    );
    Auth::startSession($user);
}

try {
    if ($action === 'save_step') {
        $step = (string) okv_input('step', '');
        if (!isset(CHECKOUT_STEP_FIELDS[$step])) {
            throw new DomainException('bad_step');
        }
        $data = [];
        foreach (CHECKOUT_STEP_FIELDS[$step] as $field) {
            $data[$field] = okv_input($field, '');
        }
        Checkout::saveStep($step, $data);

        $next = CHECKOUT_NEXT_STEP[$step];
        if (checkout_is_fetch()) {
            okv_json(['status' => 'ok', 'next_step' => $next]);
        }
        okv_redirect('/checkout.php?step=' . $next, 303);
    }

    if ($action !== 'place_order') {
        okv_error('This action is not available.', 400, 'unknown_action');
    }

    // A payment choice posted with the final submit is saved before placing.
    $postedPayment = (string) okv_input('payment_option', '');
    if ($postedPayment !== '') {
        Checkout::saveStep('payment', ['payment_option' => $postedPayment]);
    }

    $bag      = Checkout::bag();
    $customer = $bag['customer'] ?? [];
    $delivery = $bag['delivery'] ?? [];
    $payment  = $bag['payment'] ?? [];

    if (!Customer::isLoggedIn()) {
        checkout_create_guest_account($customer);
    }

    $input = array_merge($customer, [
        'user_id'          => Customer::id(),
        'customer_type'    => Customer::type(),
        'activated'        => Customer::isActivated(),
        'delivery_date'    => $delivery['delivery_date'] ?? '',
        'delivery_zone_id' => (int) ($delivery['delivery_zone_id'] ?? 0),
        'payment_option'   => $payment['payment_option'] ?? '',
    ]);

    $result = Checkout::place($input);
    // The order is committed. Send the customer their copy with the trail link
    // in it, and raise the staff alert. PRD 14.2 makes that link the way a
    // customer follows their order, so it has to leave the building here.
    Notifications::announceOrderPlaced((int) $result['order_id'], (string) ($result['trail_token'] ?? ''));
    $base = rtrim((string) APP_URL, '/');
    $result['confirmation_url'] = $base . $result['confirmation_url'];
    $result['trail_url'] = $result['trail_url'] === '' ? '' : $base . $result['trail_url'];

    // The order exists now. If money is due online, send the customer straight
    // to Paystack rather than leaving them on a placed order with no way to pay.
    // Anything that goes wrong from here lands them on the order page, which
    // offers the same payment again: the order is never lost to a failed charge.
    $payNow  = null;
    $pending = null;
    $orderId = (int) $result['order_id'];
    try {
        $pending = Payments::pendingOnlinePayment($orderId);
        if ($pending) {
            $charge = Payments::beginCharge(
                (int) $pending['id'],
                rtrim((string) APP_URL, '/') . '/public/payment/callback.php'
            );
            if ($charge['ok'] && $charge['authorization_url'] !== '') {
                $payNow = $charge['authorization_url'];
            } else {
                error_log('checkout.place_order: could not open a charge for order ' . $orderId . ': ' . (string) ($charge['code'] ?? 'unknown'));
            }
        }
    } catch (Throwable $e) {
        // Never let a payment problem lose a placed order.
        error_log('checkout.place_order: charge failed for order ' . $orderId . ': ' . $e->getMessage());
    }

    if (checkout_is_fetch()) {
        okv_json(['status' => 'ok', 'pay_url' => $payNow] + $result);
    }
    if ($payNow !== null) {
        okv_redirect($payNow, 303);
    }
    okv_redirect('/public/order.php?order=' . $orderId . ($pending !== null ? '&payment=unavailable' : ''), 303);
} catch (DomainException $e) {
    $known = [
        'consent_required'    => ['Tick the account consent box to continue.', 'consent_required'],
        'payment_not_allowed' => ['That payment choice is not available for this account.', 'payment_not_allowed'],
        'credit_not_approved' => ['On-account payment is only available after credit approval.', 'credit_not_approved'],
        'delivery_unavailable' => ['That delivery date is no longer available. Pick another date.', 'delivery_unavailable'],
        'zone_unavailable'    => ['That delivery area is no longer available. Pick another area.', 'zone_unavailable'],
        'empty_cart'          => ['Your basket is empty.', 'empty_cart'],
        'cart_converted'      => ['This basket has already been placed as an order.', 'cart_converted'],
        'bad_step'            => ['That checkout step is not available.', 'bad_step'],
        'bad_customer'        => ['Check your contact and delivery details.', 'bad_customer'],
        'account_exists'      => ['An account already uses that email address or phone number. Sign in to continue.', 'account_exists'],
    ];
    $reason = $e->getMessage();
    [$defaultMessage, $clientCode] = $known[$reason] ?? ['Check your checkout details and try again.', 'invalid_checkout'];
    $message = $e instanceof CheckoutException ? $e->customerMessage() : $defaultMessage;
    okv_error($message, 422, $clientCode);
} catch (Throwable $e) {
    error_log('checkout.place_order failed: ' . $e->getMessage());
    okv_error('We could not place your order. Please try again.', 500, 'failed');
}
