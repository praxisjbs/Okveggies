<?php
/**
 * api/v1/delivery.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Delivery reads for the storefront (which days a customer may pick,
 * which zones are active) and delivery writes for the admin (allowed days, zone
 * activity, dated exceptions). Every write is POST, RBAC gated and CSRF checked.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';

$action = okv_action();

/** True when the caller wants JSON rather than a redirect. */
function delivery_is_fetch(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

/** The gate every admin delivery write passes: POST, permission, CSRF. */
function delivery_guard(string $permission): void
{
    if (!okv_is_post()) {
        okv_error('Use POST for this action.', 405, 'method_not_allowed');
    }
    Rbac::requirePermission($permission);
    if (!Csrf::validate()) {
        okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
    }
}

/** Answer an admin write: JSON for a fetch, a 303 back to the screen otherwise. */
function delivery_success(string $message): void
{
    if (delivery_is_fetch()) {
        okv_json(['status' => 'ok', 'message' => $message]);
    }
    okv_redirect('/admin/delivery.php?delivery=updated', 303);
}

// Public reads. No side effects, so no CSRF.
if ($action === 'eligible_dates') {
    $type = (string) okv_input('customer_type', 'household');
    if (!in_array($type, ['household', 'business'], true)) {
        okv_error('Choose an account type.', 422, 'bad_type');
    }
    okv_json(['status' => 'ok', 'dates' => Delivery::nextEligibleDates($type)]);
}
if ($action === 'zones') {
    okv_json(['status' => 'ok', 'zones' => Delivery::zonesActive()]);
}

try {
    switch ($action) {
        case 'set_day':
            delivery_guard('delivery.days.edit');
            $type = (string) okv_input('customer_type', '');
            $day  = (int) okv_input('day_of_week', 0);
            if (!in_array($type, ['household', 'business'], true) || $day < 1 || $day > 7) {
                okv_error('Choose a valid day and account type.', 422, 'bad_day');
            }
            Database::run(
                'UPDATE allowed_delivery_days
                    SET is_active = :active, cutoff_time = :cutoff, minimum_lead_days = :lead
                  WHERE customer_type = :type AND day_of_week = :day',
                [
                    ':active' => (int) okv_input('is_active', 0),
                    ':cutoff' => (string) okv_input('cutoff_time', '16:00'),
                    ':lead'   => max(0, (int) okv_input('minimum_lead_days', 1)),
                    ':type'   => $type,
                    ':day'    => $day,
                ]
            );
            delivery_success('Delivery day updated.');
            break;

        case 'set_zone_active':
            delivery_guard('delivery.zones.edit');
            Database::run(
                'UPDATE delivery_zones SET is_active = :active WHERE id = :id',
                [':active' => (int) okv_input('is_active', 0), ':id' => (int) okv_input('zone_id', 0)]
            );
            delivery_success('Delivery zone updated.');
            break;

        case 'save_exception':
            delivery_guard('delivery.exceptions.edit');
            $date = (string) okv_input('exception_date', '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                okv_error('Choose a valid date.', 422, 'bad_date');
            }
            Database::run(
                'INSERT INTO delivery_date_exceptions (exception_date, is_available, reason, replacement_date, created_by)
                 VALUES (:date, :available, :reason, :replacement, :user)
                 ON DUPLICATE KEY UPDATE
                    is_available = VALUES(is_available),
                    reason = VALUES(reason),
                    replacement_date = VALUES(replacement_date),
                    created_by = VALUES(created_by)',
                [
                    ':date'        => $date,
                    ':available'   => (int) okv_input('is_available', 0),
                    ':reason'      => trim((string) okv_input('reason', '')) ?: null,
                    ':replacement' => trim((string) okv_input('replacement_date', '')) ?: null,
                    ':user'        => Rbac::userId(),
                ]
            );
            delivery_success('Delivery date saved.');
            break;

        default:
            okv_error('This action is not available.', 400, 'unknown_action');
    }
} catch (Throwable $e) {
    error_log('delivery action failed: ' . $e->getMessage());
    okv_error('We could not update delivery settings. Please try again.', 500, 'failed');
}
