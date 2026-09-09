<?php
/**
 * api/v1/customers.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Customer reads and edits (PRD Section 17).
 *
 * Only the two actions the back office needs to start work are built here:
 * finding the customer who is on the phone, and creating them when they have
 * never bought from us before. Everything else the Customers module will hold,
 * the profile, the addresses, the order history and the credit terms, is M8, and
 * admin/customers.php is still its placeholder.
 *
 * They live here rather than inside the order endpoint because two screens use
 * them, the phone order and the typed-in Kitchen Run, and a customer search that
 * belongs to orders would have to be written twice.
 *
 * Every action gates on its own permission and re-checks it on the server. The
 * gates on the screens are UX only.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

/** True when the caller wants JSON rather than a redirect. */
function customers_is_fetch(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

/**
 * Where a non-fetch caller goes next. Only ever a path on this site, checked by
 * okv_safe_path, so a crafted return_to cannot bounce a colleague off to
 * another host.
 */
function customers_return_to(string $fallback): string
{
    return okv_safe_path((string) okv_input('return_to', ''), $fallback);
}

// A fetch gets JSON. A plain form post gets a redirect, so every one of these
// screens still works with JavaScript switched off.
if (customers_is_fetch() || !okv_is_post()) {
    header('Content-Type: application/json; charset=utf-8');
}

// -----------------------------------------------------------------------------
// Search. A read, so GET is allowed, and it answers with the same shape whether
// it found nobody or twenty people.
// -----------------------------------------------------------------------------
if ($action === 'search') {
    Rbac::requirePermission('customers.view');

    $term    = StaffCustomers::cleanSearch((string) okv_input('q', ''));
    $matches = $term === '' ? [] : StaffCustomers::search($term, 20);

    $customers = [];
    foreach ($matches as $row) {
        $email = (string) $row['email'];
        $customers[] = [
            'id'           => (int) $row['id'],
            'name'         => trim($row['first_name'] . ' ' . $row['last_name']),
            'email'        => StaffCustomers::isPlaceholderEmail($email) ? '' : $email,
            'phone'        => (string) $row['phone'],
            'phone_display' => Phone::display((string) $row['phone']),
            'type'         => (string) $row['user_type'],
            'order_count'  => (int) $row['order_count'],
        ];
    }

    okv_json(['status' => 'ok', 'customers' => $customers, 'term' => $term]);
}

// -----------------------------------------------------------------------------
// One customer, with the address we last delivered to, so the order form can
// fill itself in rather than making a colleague read it back over the phone.
// -----------------------------------------------------------------------------
if ($action === 'get') {
    Rbac::requirePermission('customers.view');

    $customer = StaffCustomers::find((int) okv_input('user_id', 0));
    if (!$customer) {
        okv_error('That customer could not be found.', 404, 'not_found');
    }
    $address = StaffCustomers::lastAddress((int) $customer['id']);

    $credit = null;
    if ((string) $customer['user_type'] === 'business') {
        $row = Database::one(
            'SELECT credit_status, credit_limit_subunit FROM business_customers WHERE user_id = :id',
            [':id' => (int) $customer['id']]
        );
        $credit = [
            'status'   => (string) ($row['credit_status'] ?? 'not_requested'),
            'approved' => ($row['credit_status'] ?? '') === 'approved',
        ];
    }

    okv_json([
        'status'   => 'ok',
        'customer' => [
            'id'    => (int) $customer['id'],
            'name'  => trim($customer['first_name'] . ' ' . $customer['last_name']),
            'phone' => (string) $customer['phone'],
            'type'  => (string) $customer['user_type'],
            'activated' => $customer['email_verified_at'] !== null,
        ],
        'address' => $address ?: null,
        'credit'  => $credit,
        'dates'   => Delivery::nextEligibleDates((string) $customer['user_type'], 21),
    ]);
}

// -----------------------------------------------------------------------------
// Create. A state change, so POST, permission and CSRF, in that order.
// -----------------------------------------------------------------------------
if ($action === 'create') {
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission('customers.create');
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }

    $staffId = (int) Rbac::userId();
    try {
        $clean  = StaffCustomers::validateNew([
            'first_name'    => okv_input('first_name', ''),
            'last_name'     => okv_input('last_name', ''),
            'email'         => okv_input('email', ''),
            'phone'         => okv_input('phone', ''),
            'customer_type' => okv_input('customer_type', 'household'),
            'business_name' => okv_input('business_name', ''),
        ]);
        $userId = StaffCustomers::create($clean, $staffId);
    } catch (DomainException $e) {
        if (!customers_is_fetch()) {
            $back = customers_return_to('/admin/order_new.php');
            okv_redirect($back . (str_contains($back, '?') ? '&' : '?') . 'error=' . rawurlencode($e->getMessage()), 303);
        }
        okv_error(StaffCustomers::message($e->getMessage()), 422, $e->getMessage());
    } catch (Throwable $e) {
        error_log('customers.create failed: ' . $e->getMessage());
        if (!customers_is_fetch()) {
            $back = customers_return_to('/admin/order_new.php');
            okv_redirect($back . (str_contains($back, '?') ? '&' : '?') . 'error=customer_failed', 303);
        }
        okv_error('We could not save that customer. Please try again.', 500, 'failed');
    }

    // With JavaScript off the colleague lands back on the screen they came from,
    // with the customer they just made already chosen.
    if (!customers_is_fetch()) {
        $back = customers_return_to('/admin/order_new.php');
        okv_redirect($back . (str_contains($back, '?') ? '&' : '?') . 'user_id=' . $userId, 303);
    }

    okv_json([
        'status'   => 'ok',
        'message'  => 'Customer added.',
        'customer' => [
            'id'    => $userId,
            'name'  => trim($clean['first_name'] . ' ' . $clean['last_name']),
            'phone' => $clean['phone'],
            'phone_display' => Phone::display($clean['phone']),
            'email' => StaffCustomers::isPlaceholderEmail($clean['email']) ? '' : $clean['email'],
            'type'  => $clean['customer_type'],
            'order_count' => 0,
        ],
    ], 201);
}

okv_error('That action is not available.', 400, 'unknown_action');
