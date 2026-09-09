<?php
/**
 * api/v1/customers.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Staff customer reads over JSON, and the one write the back office
 * needs before it can start a piece of work.
 *
 *   search  The type-ahead for /admin/customers.php, and the picker on the
 *           phone-order and typed-in-list screens. One search, not two: both
 *           call Customers::listing(), so they cannot disagree about who a
 *           number belongs to.
 *   get     One customer with the address we last delivered to, the days we can
 *           offer them and whether credit is open, so the order form can fill
 *           itself in rather than making a colleague read it all back.
 *   create  The light account a first-time caller needs. Somebody is on the
 *           phone, they have never bought from us, and their order has to
 *           belong to somebody.
 *
 * Orders, payments, Kitchen Runs and credit are still written by the modules
 * that own them, each with its own permission and audit trail. The rest of the
 * Customers module (the profile, the addresses, the credit terms) is the
 * screen at /admin/customers.php, not this endpoint.
 *
 * Every action is POST, CSRF checked and gated on its own permission.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

if (!okv_is_post()) { okv_error('Use POST for this action.', 405, 'method_not_allowed'); }
if (!Csrf::validate()) { okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired'); }

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

// -----------------------------------------------------------------------------
// Search.
// -----------------------------------------------------------------------------
if ($action === 'search') {
    Rbac::requirePermission('customers.view');

    try {
        $listing = Customers::listing([
            'search' => okv_input('search', okv_input('q', '')),
            'type'   => okv_input('type', ''),
        ], (int) okv_input('page', 1));

        // One row shape for both callers. The Customers screen reads name,
        // email, type and orders; the picker also needs the phone as a person
        // reads it, the raw account type, and whether the address on file is
        // one of ours rather than one the customer gave.
        $customers = array_map(static function (array $row): array {
            $email = (string) $row['email'];
            return [
                'id'            => (int) $row['id'],
                'name'          => Customers::displayName($row),
                'email'         => StaffCustomers::isPlaceholderEmail($email) ? '' : $email,
                'type'          => Customers::typeLabel((string) $row['user_type']),
                'type_key'      => (string) $row['user_type'],
                'phone'         => (string) $row['phone'],
                'phone_display' => Phone::display((string) $row['phone']),
                'status'        => (string) $row['status'],
                'orders'        => (int) $row['order_count'],
                'url'           => '/admin/customers.php?customer=' . (int) $row['id'],
            ];
        }, $listing['customers']);

        okv_json([
            'status'    => 'ok',
            'customers' => $customers,
            'count'     => (int) $listing['count'],
            'page'      => (int) $listing['page'],
            'last_page' => (int) $listing['lastPage'],
        ]);
    } catch (Throwable $e) {
        error_log('customers search failed: ' . $e->getMessage());
        okv_error('We could not search customers just now. Please try again.', 500, 'failed');
    }
}

// -----------------------------------------------------------------------------
// One customer, with everything the order form needs to fill itself in.
// -----------------------------------------------------------------------------
if ($action === 'get') {
    Rbac::requirePermission('customers.view');

    $customer = StaffCustomers::find((int) okv_input('user_id', 0));
    if (!$customer) {
        okv_error('That customer could not be found.', 404, 'not_found');
    }

    $credit = null;
    if ((string) $customer['user_type'] === 'business') {
        $row = Database::one(
            'SELECT credit_status FROM business_customers WHERE user_id = :id',
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
            'id'        => (int) $customer['id'],
            'name'      => trim($customer['first_name'] . ' ' . $customer['last_name']),
            'phone'     => (string) $customer['phone'],
            'type'      => (string) $customer['user_type'],
            'activated' => $customer['email_verified_at'] !== null,
        ],
        'address' => StaffCustomers::lastAddress((int) $customer['id']) ?: null,
        'credit'  => $credit,
        'dates'   => Delivery::nextEligibleDates((string) $customer['user_type'], 21),
    ]);
}

// -----------------------------------------------------------------------------
// Create the light account a first-time caller needs.
// -----------------------------------------------------------------------------
if ($action === 'create') {
    Rbac::requirePermission('customers.create');

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
            'id'            => $userId,
            'name'          => trim($clean['first_name'] . ' ' . $clean['last_name']),
            'phone'         => $clean['phone'],
            'phone_display' => Phone::display($clean['phone']),
            'email'         => StaffCustomers::isPlaceholderEmail($clean['email']) ? '' : $clean['email'],
            'type'          => $clean['customer_type'],
            'type_key'      => $clean['customer_type'],
            'orders'        => 0,
        ],
    ], 201);
}

okv_error('That action is not available.', 400, 'unknown_action');
