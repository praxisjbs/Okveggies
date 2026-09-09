<?php
/**
 * api/v1/customers.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Staff customer reads over JSON, for the type-ahead on
 * /admin/customers.php. The screen itself is server rendered and works with
 * JavaScript switched off, so this endpoint only ever speeds a search up.
 *
 * It reads. Orders, payments, Kitchen Runs and credit are written by the
 * modules that own them, each with its own permission and audit trail.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

if (!okv_is_post()) { okv_error('Use POST for this action.', 405, 'method_not_allowed'); }
if (!Csrf::validate()) { okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired'); }
Rbac::requirePermission('customers.view');

if (okv_action() !== 'search') { okv_error('That action is not available.', 400, 'unknown_action'); }

try {
    $listing = Customers::listing([
        'search' => okv_input('search', ''),
        'type'   => okv_input('type', ''),
    ], (int) okv_input('page', 1));

    $customers = array_map(static function (array $row): array {
        return [
            'id'      => (int) $row['id'],
            'name'    => Customers::displayName($row),
            'email'   => (string) $row['email'],
            'type'    => Customers::typeLabel((string) $row['user_type']),
            'orders'  => (int) $row['order_count'],
            'url'     => '/admin/customers.php?customer=' . (int) $row['id'],
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
