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
            $cutoff = trim((string) okv_input('cutoff_time', ''));
            $leadRaw = (string) okv_input('minimum_lead_days', '');
            if (!in_array($type, ['household', 'business'], true) || $day < 1 || $day > 7) {
                okv_error('Choose a valid day and account type.', 422, 'bad_day');
            }
            if (!Delivery::validCutoff($cutoff)) {
                okv_error('Enter the cutoff in 24 hour HH:MM format.', 422, 'bad_cutoff');
            }
            if (!preg_match('/^\d{1,2}$/', $leadRaw) || (int) $leadRaw > 30) {
                okv_error('Lead days must be a whole number from 0 to 30.', 422, 'bad_lead');
            }
            Database::run(
                'INSERT INTO allowed_delivery_days (customer_type, day_of_week, is_active, cutoff_time, minimum_lead_days)
                 VALUES (:type, :day, :active, :cutoff, :lead)
                 ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), cutoff_time = VALUES(cutoff_time),
                    minimum_lead_days = VALUES(minimum_lead_days)',
                [
                    ':active' => (int) okv_input('is_active', 0),
                    ':cutoff' => $cutoff,
                    ':lead'   => (int) $leadRaw,
                    ':type'   => $type,
                    ':day'    => $day,
                ]
            );
            delivery_success('Delivery day updated.');
            break;

        case 'set_zone_active':
            delivery_guard('delivery.zones.edit');
            $zoneId = (int) okv_input('zone_id', 0);
            if ($zoneId < 1 || !Database::one('SELECT id FROM delivery_zones WHERE id = :id', [':id' => $zoneId])) {
                okv_error('Choose a valid delivery zone.', 422, 'bad_zone');
            }
            Database::run(
                'UPDATE delivery_zones SET is_active = :active WHERE id = :id',
                [':active' => (int) okv_input('is_active', 0), ':id' => $zoneId]
            );
            delivery_success('Delivery zone updated.');
            break;

        // Add a zone. The thirty Lagos zones that shipped with the seed were
        // always meant to be edited rather than lived with (PRD 13), and until
        // now the screen could only switch one on and off. A new estate, a new
        // corridor, or a name the team actually uses now has somewhere to go.
        case 'create_zone':
            delivery_guard('delivery.zones.edit');
            $name = Delivery::cleanZoneName((string) okv_input('name', ''));
            if ($name === null) {
                okv_error('Give the zone a name, up to 120 characters.', 422, 'bad_zone_name');
            }
            if (Database::one('SELECT id FROM delivery_zones WHERE name = :name LIMIT 1', [':name' => $name])) {
                okv_error('There is already a zone with that name.', 409, 'duplicate_zone');
            }
            $sortRaw = trim((string) okv_input('sort_order', ''));
            if ($sortRaw !== '' && preg_match('/^\d{1,4}$/', $sortRaw) !== 1) {
                okv_error('The order must be a whole number.', 422, 'bad_sort_order');
            }
            Database::run(
                'INSERT INTO delivery_zones (name, slug, area_note, is_active, sort_order)
                 VALUES (:name, :slug, :note, :active, :sort)',
                [
                    ':name'   => $name,
                    ':slug'   => Delivery::uniqueZoneSlug($name),
                    ':note'   => mb_substr(trim((string) okv_input('area_note', '')), 0, Delivery::ZONE_NOTE_MAX) ?: null,
                    ':active' => okv_input('is_active', '') !== '' ? 1 : 0,
                    ':sort'   => $sortRaw === '' ? Delivery::nextZoneSortOrder() : (int) $sortRaw,
                ]
            );
            delivery_success('Zone added.');
            break;

        // Edit a zone in place. No delete: an order, a Kitchen Run and a
        // manifest all point at a zone, and history is append-only (CLAUDE.md).
        // Switching a zone off already takes it out of the checkout picker,
        // which is what "remove it" actually means here.
        case 'update_zone':
            delivery_guard('delivery.zones.edit');
            $zoneId = (int) okv_input('zone_id', 0);
            $zone = $zoneId > 0
                ? Database::one('SELECT id, name FROM delivery_zones WHERE id = :id', [':id' => $zoneId])
                : null;
            if (!$zone) {
                okv_error('Choose a valid delivery zone.', 422, 'bad_zone');
            }
            $name = Delivery::cleanZoneName((string) okv_input('name', ''));
            if ($name === null) {
                okv_error('Give the zone a name, up to 120 characters.', 422, 'bad_zone_name');
            }
            if (Database::one(
                'SELECT id FROM delivery_zones WHERE name = :name AND id <> :id LIMIT 1',
                [':name' => $name, ':id' => $zoneId]
            )) {
                okv_error('There is already a zone with that name.', 409, 'duplicate_zone');
            }
            $sortRaw = trim((string) okv_input('sort_order', ''));
            if ($sortRaw !== '' && preg_match('/^\d{1,4}$/', $sortRaw) !== 1) {
                okv_error('The order must be a whole number.', 422, 'bad_sort_order');
            }

            // The slug is only rebuilt when the name really changed, so a link
            // or a saved filter that carries the old slug does not break every
            // time somebody fixes a comma.
            $params = [
                ':name'   => $name,
                ':note'   => mb_substr(trim((string) okv_input('area_note', '')), 0, Delivery::ZONE_NOTE_MAX) ?: null,
                ':active' => okv_input('is_active', '') !== '' ? 1 : 0,
                ':id'     => $zoneId,
            ];
            $setSlug = '';
            if ($name !== (string) $zone['name']) {
                $setSlug = ', slug = :slug';
                $params[':slug'] = Delivery::uniqueZoneSlug($name, $zoneId);
            }
            if ($sortRaw !== '') {
                $setSlug .= ', sort_order = :sort';
                $params[':sort'] = (int) $sortRaw;
            }
            Database::run(
                'UPDATE delivery_zones SET name = :name, area_note = :note, is_active = :active' . $setSlug . ' WHERE id = :id',
                $params
            );
            delivery_success('Zone updated.');
            break;

        case 'save_exception':
            delivery_guard('delivery.exceptions.edit');
            $date = (string) okv_input('exception_date', '');
            $replacement = trim((string) okv_input('replacement_date', ''));
            $reason = trim((string) okv_input('reason', ''));
            if (!Delivery::validDate($date)) {
                okv_error('Choose a valid date.', 422, 'bad_date');
            }
            if ($replacement !== '' && !Delivery::validDate($replacement)) {
                okv_error('Choose a valid replacement date.', 422, 'bad_replacement');
            }
            if (mb_strlen($reason) > 255) {
                okv_error('Keep the exception reason to 255 characters.', 422, 'reason_too_long');
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
                    ':reason'      => $reason ?: null,
                    ':replacement' => $replacement ?: null,
                    ':user'        => Rbac::userId(),
                ]
            );
            delivery_success('Delivery date saved.');
            break;

        case 'delete_exception':
            delivery_guard('delivery.exceptions.edit');
            $date = (string) okv_input('exception_date', '');
            if (!Delivery::validDate($date)) {
                okv_error('Choose a valid date.', 422, 'bad_date');
            }
            if (Database::run('DELETE FROM delivery_date_exceptions WHERE exception_date = :date', [':date' => $date]) !== 1) {
                okv_error('That delivery exception no longer exists.', 404, 'not_found');
            }
            delivery_success('Delivery exception removed.');
            break;

        default:
            okv_error('This action is not available.', 400, 'unknown_action');
    }
} catch (Throwable $e) {
    error_log('delivery action failed: ' . $e->getMessage());
    okv_error('We could not update delivery settings. Please try again.', 500, 'failed');
}
